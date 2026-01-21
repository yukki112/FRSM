<?php
session_start();
require_once '../../config/db_connection.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: ../../login/login.php");
    exit();
}

$user_id = $_SESSION['user_id'];
$query = "SELECT first_name, middle_name, last_name, role, avatar FROM users WHERE id = ?";
$stmt = $pdo->prepare($query);
$stmt->execute([$user_id]);
$user = $stmt->fetch();

if (!$user || $user['role'] !== 'ADMIN') {
    header("Location: ../../login/unauthorized.php");
    exit();
}

$first_name = htmlspecialchars($user['first_name']);
$middle_name = htmlspecialchars($user['middle_name']);
$last_name = htmlspecialchars($user['last_name']);
$role = htmlspecialchars($user['role']);
$avatar = htmlspecialchars($user['avatar']);

$full_name = $first_name;
if (!empty($middle_name)) {
    $full_name .= " " . $middle_name;
}
$full_name .= " " . $last_name;

// Get filter parameters
$filter_status = isset($_GET['status']) ? $_GET['status'] : 'all';
$filter_date = isset($_GET['date']) ? $_GET['date'] : '';
$search_query = isset($_GET['search']) ? $_GET['search'] : '';
$filter_duty_type = isset($_GET['duty_type']) ? $_GET['duty_type'] : '';
$filter_unit = isset($_GET['unit']) ? $_GET['unit'] : '';

// Get all shifts with duty assignments (Admin can see all)
function getAllShifts($pdo, $filter_status = 'all', $filter_date = '', $search_query = '', $filter_duty_type = '', $filter_unit = '') {
    $sql = "SELECT 
                s.id,
                s.user_id,
                s.volunteer_id,
                s.shift_for,
                s.unit_id,
                s.shift_date,
                s.shift_type,
                s.start_time,
                s.end_time,
                s.status,
                s.location,
                s.notes,
                s.created_at,
                s.updated_at,
                s.duty_assignment_id,
                s.confirmation_status,
                s.check_in_time,
                s.check_out_time,
                s.attendance_status,
                s.attendance_notes,
                u.unit_name,
                u.unit_code,
                u.unit_type,
                CONCAT(creator.first_name, ' ', creator.last_name) as created_by_name,
                da.duty_type,
                da.duty_description,
                da.priority,
                da.required_equipment,
                da.required_training,
                CASE 
                    WHEN s.shift_for = 'user' THEN CONCAT(usr.first_name, ' ', usr.last_name)
                    WHEN s.shift_for = 'volunteer' THEN CONCAT(v.first_name, ' ', v.last_name)
                    ELSE 'Unassigned'
                END as assigned_to_name,
                CASE 
                    WHEN s.shift_for = 'user' THEN usr.email
                    WHEN s.shift_for = 'volunteer' THEN v.email
                    ELSE ''
                END as assigned_to_email
            FROM shifts s
            LEFT JOIN units u ON s.unit_id = u.id
            LEFT JOIN users creator ON s.created_by = creator.id
            LEFT JOIN users usr ON s.user_id = usr.id
            LEFT JOIN volunteers v ON s.volunteer_id = v.id
            LEFT JOIN duty_assignments da ON s.duty_assignment_id = da.id
            WHERE 1=1";
    
    $params = [];
    
    // Apply status filter
    if ($filter_status !== 'all') {
        $sql .= " AND s.status = ?";
        $params[] = $filter_status;
    }
    
    // Apply date filter
    if (!empty($filter_date)) {
        if ($filter_date === 'today') {
            $sql .= " AND s.shift_date = CURDATE()";
        } elseif ($filter_date === 'tomorrow') {
            $sql .= " AND s.shift_date = DATE_ADD(CURDATE(), INTERVAL 1 DAY)";
        } elseif ($filter_date === 'week') {
            $sql .= " AND s.shift_date >= CURDATE() AND s.shift_date <= DATE_ADD(CURDATE(), INTERVAL 7 DAY)";
        } elseif ($filter_date === 'month') {
            $sql .= " AND s.shift_date >= CURDATE() AND s.shift_date <= DATE_ADD(CURDATE(), INTERVAL 30 DAY)";
        } elseif ($filter_date === 'past') {
            $sql .= " AND s.shift_date < CURDATE()";
        } elseif ($filter_date === 'future') {
            $sql .= " AND s.shift_date > CURDATE()";
        }
    }
    
    // Apply unit filter
    if (!empty($filter_unit)) {
        $sql .= " AND s.unit_id = ?";
        $params[] = $filter_unit;
    }
    
    // Apply search query
    if (!empty($search_query)) {
        $sql .= " AND (
                    u.unit_name LIKE ? OR 
                    u.unit_code LIKE ? OR 
                    usr.first_name LIKE ? OR 
                    usr.last_name LIKE ? OR 
                    v.first_name LIKE ? OR 
                    v.last_name LIKE ? OR
                    s.location LIKE ? OR
                    da.duty_type LIKE ? OR
                    da.duty_description LIKE ? OR
                    usr.email LIKE ? OR
                    v.email LIKE ?
                )";
        $search_param = "%$search_query%";
        $params = array_merge($params, [
            $search_param, $search_param, $search_param, $search_param, 
            $search_param, $search_param, $search_param,
            $search_param, $search_param, $search_param, $search_param
        ]);
    }
    
    // Apply duty type filter
    if (!empty($filter_duty_type)) {
        $sql .= " AND da.duty_type LIKE ?";
        $params[] = "%$filter_duty_type%";
    }
    
    $sql .= " ORDER BY s.shift_date DESC, s.start_time ASC";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Get shift statistics for admin
function getAdminShiftStats($pdo) {
    $sql = "SELECT 
                COUNT(*) as total,
                SUM(CASE WHEN shift_date >= CURDATE() AND status != 'cancelled' THEN 1 ELSE 0 END) as upcoming,
                SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed,
                SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END) as cancelled,
                SUM(CASE WHEN shift_date = CURDATE() AND status != 'cancelled' THEN 1 ELSE 0 END) as today,
                SUM(CASE WHEN duty_assignment_id IS NOT NULL THEN 1 ELSE 0 END) as with_duty,
                SUM(CASE WHEN confirmation_status = 'pending' AND shift_for = 'volunteer' THEN 1 ELSE 0 END) as pending_confirmation,
                SUM(CASE WHEN confirmation_status = 'confirmed' THEN 1 ELSE 0 END) as confirmed,
                SUM(CASE WHEN attendance_status = 'checked_in' THEN 1 ELSE 0 END) as checked_in,
                SUM(CASE WHEN attendance_status = 'absent' THEN 1 ELSE 0 END) as absent
            FROM shifts";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute();
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    
    $stats = [
        'total' => 0,
        'upcoming' => 0,
        'completed' => 0,
        'cancelled' => 0,
        'today' => 0,
        'with_duty' => 0,
        'pending_confirmation' => 0,
        'confirmed' => 0,
        'checked_in' => 0,
        'absent' => 0
    ];
    
    if ($result) {
        $stats = array_merge($stats, $result);
    }
    
    return $stats;
}

// Get all duty types for filtering
function getDutyTypes($pdo) {
    $sql = "SELECT DISTINCT duty_type FROM duty_assignments ORDER BY duty_type";
    $stmt = $pdo->prepare($sql);
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_COLUMN, 0);
}

// Get all units for filtering
function getAllUnits($pdo) {
    $sql = "SELECT id, unit_name, unit_code FROM units ORDER BY unit_name";
    $stmt = $pdo->prepare($sql);
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Get data for filters
$duty_types = getDutyTypes($pdo);
$units = getAllUnits($pdo);

// Get shifts based on filters
$shifts = getAllShifts($pdo, $filter_status, $filter_date, $search_query, $filter_duty_type, $filter_unit);
$stats = getAdminShiftStats($pdo);

// Date filter options
$date_options = [
    '' => 'All Dates',
    'today' => 'Today',
    'tomorrow' => 'Tomorrow',
    'week' => 'Next 7 Days',
    'month' => 'Next 30 Days',
    'future' => 'Future Shifts',
    'past' => 'Past Shifts'
];

// Status options
$status_options = [
    'all' => 'All Status',
    'scheduled' => 'Scheduled',
    'confirmed' => 'Confirmed',
    'completed' => 'Completed',
    'cancelled' => 'Cancelled',
    'absent' => 'Absent'
];

// Shift type icons and colors
$shift_type_icons = [
    'morning' => 'bx-sun',
    'afternoon' => 'bx-cloud',
    'evening' => 'bx-moon',
    'night' => 'bx-bed',
    'full_day' => 'bx-calendar'
];

$shift_type_colors = [
    'morning' => '#f59e0b',
    'afternoon' => '#3b82f6',
    'evening' => '#8b5cf6',
    'night' => '#1e293b',
    'full_day' => '#10b981'
];

// Status colors
$status_colors = [
    'scheduled' => '#f59e0b',
    'confirmed' => '#3b82f6',
    'completed' => '#10b981',
    'cancelled' => '#dc2626',
    'absent' => '#6b7280'
];

// Priority colors
$priority_colors = [
    'primary' => '#dc2626',
    'secondary' => '#f59e0b',
    'support' => '#3b82f6'
];

// Confirmation status colors
$confirmation_colors = [
    'pending' => '#f59e0b',
    'confirmed' => '#10b981',
    'declined' => '#dc2626',
    'change_requested' => '#8b5cf6'
];

// Format time helper
function formatTime($time) {
    if (!$time) return 'N/A';
    return date('g:i A', strtotime($time));
}

// Format date helper
function formatDate($date) {
    if (!$date) return 'N/A';
    return date('M j, Y', strtotime($date));
}

// Get confirmation status badge HTML
function getConfirmationBadge($status) {
    global $confirmation_colors;
    $status = strtolower($status);
    $color = $confirmation_colors[$status] ?? '#6b7280';
    $text = ucfirst(str_replace('_', ' ', $status));
    
    return <<<HTML
        <span class="confirmation-badge" style="background: rgba(220, 38, 38, 0.1); color: {$color};">
            {$text}
        </span>
    HTML;
}

// Helper function to convert hex to RGB
function hexToRgb($hex) {
    $hex = str_replace('#', '', $hex);
    if(strlen($hex) == 3) {
        $r = hexdec(substr($hex,0,1).substr($hex,0,1));
        $g = hexdec(substr($hex,1,1).substr($hex,1,1));
        $b = hexdec(substr($hex,2,1).substr($hex,2,1));
    } else {
        $r = hexdec(substr($hex,0,2));
        $g = hexdec(substr($hex,2,2));
        $b = hexdec(substr($hex,4,2));
    }
    return "$r, $g, $b";
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>View Shifts - Admin - FRSM</title>
    <link href='https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css' rel='stylesheet'>
    <link rel="icon" type="image/png" sizes="32x32" href="../../img/frsm-logo.png">
    <link rel="stylesheet" href="../../css/dashboard.css">
    <style>
        :root {
            --primary-color: #dc2626;
            --primary-dark: #b91c1c;
            --secondary-color: #ef4444;
            --secondary-dark: #dc2626;
            --background-color: #ffffff;
            --text-color: #1f2937;
            --text-light: #6b7280;
            --border-color: #e5e7eb;
            --card-bg: #f9fafb;
            --sidebar-bg: #ffffff;
            
            --success: #10b981;
            --warning: #f59e0b;
            --danger: #dc2626;
            --info: #3b82f6;
            --purple: #8b5cf6;
            --indigo: #6366f1;
            --pink: #ec4899;
            --teal: #14b8a6;
            
            --gray-100: #f3f4f6;
            --gray-200: #e5e7eb;
            --gray-300: #d1d5db;
            --gray-400: #9ca3af;
            --gray-500: #6b7280;
            --gray-600: #4b5563;
            --gray-700: #374151;
            --gray-800: #1f2937;
            --gray-900: #111827;
        }
        
        .dark-mode {
            --background-color: #0f172a;
            --text-color: #f1f5f9;
            --text-light: #94a3b8;
            --border-color: #334155;
            --card-bg: #1e293b;
            --sidebar-bg: #0f172a;
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            font-size: 14px;
            line-height: 1.6;
            color: var(--text-color);
            background-color: var(--background-color);
            overflow-x: hidden;
        }

        .dashboard-content {
            padding: 0;
            min-height: 100vh;
        }

        .dashboard-header {
            color: white;
            padding: 60px 40px 40px;
            border-radius: 0 0 30px 30px;
            margin-bottom: 40px;
            position: relative;
            overflow: hidden;
            background: linear-gradient(135deg, #f8fafc 0%, #e2e8f0 100%);
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.08);
            border: 1px solid var(--border-color);
        }

        .dark-mode .dashboard-header {
            background: linear-gradient(135deg, #1e293b 0%, #334155 100%);
        }
        
        .dashboard-title {
            font-size: 40px;
            margin-bottom: 12px;
            color: var(--text-color);
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }

        .dashboard-subtitle {
            font-size: 16px;
            opacity: 0.9;
            color: var(--text-color);
        }

        .content-container {
            padding: 0 40px 40px;
        }

        /* Enhanced Stats Container */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        
        .stat-card {
            background: linear-gradient(135deg, var(--card-bg) 0%, #ffffff 100%);
            border: 1px solid var(--border-color);
            border-radius: 16px;
            padding: 24px;
            display: flex;
            flex-direction: column;
            gap: 12px;
            transition: all 0.3s ease;
            cursor: pointer;
            position: relative;
            overflow: hidden;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
        }
        
        .dark-mode .stat-card {
            background: linear-gradient(135deg, var(--card-bg) 0%, #2d3748 100%);
        }
        
        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.1);
            border-color: var(--primary-color);
        }
        
        .stat-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        
        .stat-icon-container {
            width: 48px;
            height: 48px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
        }
        
        .stat-card[data-type="total"] .stat-icon-container {
            background: rgba(59, 130, 246, 0.1);
            color: var(--info);
        }
        
        .stat-card[data-type="upcoming"] .stat-icon-container {
            background: rgba(220, 38, 38, 0.1);
            color: var(--primary-color);
        }
        
        .stat-card[data-type="completed"] .stat-icon-container {
            background: rgba(16, 185, 129, 0.1);
            color: var(--success);
        }
        
        .stat-card[data-type="cancelled"] .stat-icon-container {
            background: rgba(107, 114, 128, 0.1);
            color: var(--gray-500);
        }
        
        .stat-card[data-type="today"] .stat-icon-container {
            background: rgba(245, 158, 11, 0.1);
            color: var(--warning);
        }
        
        .stat-card[data-type="with_duty"] .stat-icon-container {
            background: rgba(139, 92, 246, 0.1);
            color: var(--purple);
        }
        
        .stat-card[data-type="pending_confirmation"] .stat-icon-container {
            background: rgba(245, 158, 11, 0.1);
            color: var(--warning);
        }
        
        .stat-card[data-type="confirmed"] .stat-icon-container {
            background: rgba(16, 185, 129, 0.1);
            color: var(--success);
        }
        
        .stat-card[data-type="checked_in"] .stat-icon-container {
            background: rgba(59, 130, 246, 0.1);
            color: var(--info);
        }
        
        .stat-card[data-type="absent"] .stat-icon-container {
            background: rgba(220, 38, 38, 0.1);
            color: var(--danger);
        }
        
        .stat-info {
            display: flex;
            flex-direction: column;
            gap: 4px;
        }
        
        .stat-value {
            font-size: 32px;
            font-weight: 800;
            line-height: 1;
        }
        
        .stat-label {
            font-size: 14px;
            color: var(--text-light);
        }
        
        .stat-trend {
            font-size: 12px;
            display: flex;
            align-items: center;
            gap: 4px;
            color: var(--success);
        }
        
        .stat-trend.down {
            color: var(--danger);
        }

        /* Enhanced Filter Tabs */
        .filter-tabs-container {
            background: var(--card-bg);
            border-radius: 16px;
            padding: 20px;
            margin-bottom: 20px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
        }

        .filter-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }

        .filter-title {
            font-size: 20px;
            font-weight: 700;
            color: var(--text-color);
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .filter-title i {
            color: var(--primary-color);
        }

        .filter-tabs {
            display: flex;
            gap: 8px;
            margin-bottom: 20px;
            flex-wrap: wrap;
        }

        .filter-tab {
            padding: 10px 20px;
            border-radius: 10px;
            background: var(--gray-100);
            border: 2px solid transparent;
            color: var(--text-color);
            cursor: pointer;
            transition: all 0.3s ease;
            font-weight: 500;
            display: flex;
            align-items: center;
            gap: 8px;
            text-decoration: none;
        }

        .dark-mode .filter-tab {
            background: var(--gray-800);
        }

        .filter-tab.active {
            background: var(--primary-color);
            color: white;
            border-color: var(--primary-color);
        }

        .filter-tab:hover:not(.active) {
            background: var(--gray-200);
            text-decoration: none;
        }

        .dark-mode .filter-tab:hover:not(.active) {
            background: var(--gray-700);
        }

        .filter-tab-count {
            background: rgba(255, 255, 255, 0.2);
            padding: 2px 8px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: 600;
        }

        .filter-tab.active .filter-tab-count {
            background: rgba(255, 255, 255, 0.3);
        }

        /* Advanced Filters */
        .filters-container {
            background: var(--card-bg);
            border-radius: 16px;
            padding: 24px;
            margin-bottom: 30px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
        }

        .filter-section {
            margin-bottom: 24px;
        }

        .filter-section-title {
            font-size: 16px;
            font-weight: 600;
            color: var(--text-color);
            margin-bottom: 16px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .filter-section-title i {
            color: var(--primary-color);
        }

        .filter-row {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
        }

        .filter-group {
            display: flex;
            flex-direction: column;
            gap: 8px;
        }
        
        .filter-label {
            font-size: 14px;
            font-weight: 600;
            color: var(--gray-700);
            display: flex;
            align-items: center;
            gap: 6px;
        }
        
        .filter-label i {
            color: var(--primary-color);
            font-size: 16px;
        }
        
        .dark-mode .filter-label {
            color: var(--gray-300);
        }
        
        .filter-select, .filter-input {
            padding: 12px 16px;
            border-radius: 10px;
            border: 1px solid var(--border-color);
            background: var(--card-bg);
            color: var(--text-color);
            font-size: 14px;
            width: 100%;
            transition: all 0.3s ease;
        }
        
        .filter-select:focus, .filter-input:focus {
            outline: none;
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(220, 38, 38, 0.1);
        }

        .filter-actions {
            display: flex;
            gap: 12px;
            justify-content: flex-end;
            margin-top: 20px;
            padding-top: 20px;
            border-top: 1px solid var(--border-color);
        }

        .filter-button {
            padding: 12px 24px;
            border-radius: 10px;
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            color: white;
            border: none;
            cursor: pointer;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 8px;
            transition: all 0.3s ease;
            text-decoration: none;
            justify-content: center;
        }

        .filter-button:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(220, 38, 38, 0.3);
            text-decoration: none;
        }

        .clear-filters {
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            color: var(--text-color);
        }

        .clear-filters:hover {
            background: var(--gray-100);
        }

        .dark-mode .clear-filters:hover {
            background: var(--gray-800);
        }

        /* Enhanced Table Styles */
        .shifts-table-container {
            background: var(--card-bg);
            border-radius: 16px;
            overflow: hidden;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
        }

        .table-header {
            display: grid;
            grid-template-columns: 80px 150px 140px 120px 200px 120px 120px 120px;
            gap: 15px;
            padding: 20px;
            background: rgba(220, 38, 38, 0.03);
            border-bottom: 1px solid var(--border-color);
            font-weight: 600;
            color: var(--text-color);
            font-size: 13px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .table-row {
            display: grid;
            grid-template-columns: 80px 150px 140px 120px 200px 120px 120px 120px;
            gap: 15px;
            padding: 20px;
            border-bottom: 1px solid var(--border-color);
            transition: all 0.3s ease;
            align-items: center;
            background: var(--card-bg);
        }
        
        .table-row:hover {
            background: rgba(220, 38, 38, 0.03);
        }
        
        .table-row:last-child {
            border-bottom: none;
        }
        
        .table-cell {
            display: flex;
            flex-direction: column;
            gap: 4px;
            color: var(--text-color);
            min-height: 40px;
            justify-content: center;
        }
        
        .shift-id {
            font-weight: 700;
            color: var(--primary-color);
            font-size: 15px;
        }
        
        .shift-date {
            font-weight: 600;
            color: var(--text-color);
        }
        
        .shift-time {
            font-size: 12px;
            color: var(--text-light);
        }
        
        /* Enhanced Shift Type Badge */
        .shift-type-badge {
            padding: 8px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            width: fit-content;
            white-space: nowrap;
            border: 2px solid transparent;
        }
        
        .shift-type-morning {
            background: linear-gradient(135deg, rgba(245, 158, 11, 0.1), rgba(245, 158, 11, 0.2));
            color: #f59e0b;
            border-color: rgba(245, 158, 11, 0.3);
        }
        
        .shift-type-afternoon {
            background: linear-gradient(135deg, rgba(59, 130, 246, 0.1), rgba(59, 130, 246, 0.2));
            color: #3b82f6;
            border-color: rgba(59, 130, 246, 0.3);
        }
        
        .shift-type-evening {
            background: linear-gradient(135deg, rgba(139, 92, 246, 0.1), rgba(139, 92, 246, 0.2));
            color: #8b5cf6;
            border-color: rgba(139, 92, 246, 0.3);
        }
        
        .shift-type-night {
            background: linear-gradient(135deg, rgba(30, 41, 59, 0.1), rgba(30, 41, 59, 0.2));
            color: #1e293b;
            border-color: rgba(30, 41, 59, 0.3);
        }
        
        .shift-type-full_day {
            background: linear-gradient(135deg, rgba(16, 185, 129, 0.1), rgba(16, 185, 129, 0.2));
            color: #10b981;
            border-color: rgba(16, 185, 129, 0.3);
        }
        
        /* Enhanced Status Badge */
        .status-badge {
            padding: 8px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            text-transform: uppercase;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            width: fit-content;
            white-space: nowrap;
            border: 2px solid transparent;
        }
        
        .status-scheduled {
            background: linear-gradient(135deg, rgba(245, 158, 11, 0.1), rgba(245, 158, 11, 0.2));
            color: var(--warning);
            border-color: rgba(245, 158, 11, 0.3);
        }
        
        .status-confirmed {
            background: linear-gradient(135deg, rgba(59, 130, 246, 0.1), rgba(59, 130, 246, 0.2));
            color: var(--info);
            border-color: rgba(59, 130, 246, 0.3);
        }
        
        .status-completed {
            background: linear-gradient(135deg, rgba(16, 185, 129, 0.1), rgba(16, 185, 129, 0.2));
            color: var(--success);
            border-color: rgba(16, 185, 129, 0.3);
        }
        
        .status-cancelled {
            background: linear-gradient(135deg, rgba(220, 38, 38, 0.1), rgba(220, 38, 38, 0.2));
            color: var(--danger);
            border-color: rgba(220, 38, 38, 0.3);
        }
        
        .status-absent {
            background: linear-gradient(135deg, rgba(107, 114, 128, 0.1), rgba(107, 114, 128, 0.2));
            color: var(--gray-500);
            border-color: rgba(107, 114, 128, 0.3);
        }
        
        /* Confirmation Status Badge */
        .confirmation-badge {
            padding: 6px 10px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            border: 1px solid;
            border-color: inherit;
        }
        
        .unit-info {
            display: flex;
            flex-direction: column;
            gap: 2px;
        }
        
        .unit-name {
            font-weight: 600;
            color: var(--text-color);
            font-size: 13px;
        }
        
        .unit-code {
            font-size: 11px;
            color: var(--text-light);
        }

        /* Enhanced Action Buttons */
        .action-buttons {
            display: flex;
            gap: 8px;
        }
        
        .action-button {
            padding: 8px 12px;
            border-radius: 8px;
            font-weight: 600;
            border: none;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            transition: all 0.3s ease;
            font-size: 13px;
            min-width: 80px;
            position: relative;
            overflow: hidden;
        }
        
        .action-button::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.2), transparent);
            transition: left 0.5s ease;
        }
        
        .action-button:hover::before {
            left: 100%;
        }
        
        .view-button {
            background: linear-gradient(135deg, rgba(59, 130, 246, 0.1), rgba(59, 130, 246, 0.2));
            color: var(--info);
            border: 1px solid rgba(59, 130, 246, 0.3);
        }
        
        .view-button:hover {
            background: var(--info);
            color: white;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(59, 130, 246, 0.3);
        }
        
        .edit-button {
            background: linear-gradient(135deg, rgba(16, 185, 129, 0.1), rgba(16, 185, 129, 0.2));
            color: var(--success);
            border: 1px solid rgba(16, 185, 129, 0.3);
        }
        
        .edit-button:hover {
            background: var(--success);
            color: white;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(16, 185, 129, 0.3);
        }
        
        .delete-button {
            background: linear-gradient(135deg, rgba(220, 38, 38, 0.1), rgba(220, 38, 38, 0.2));
            color: var(--danger);
            border: 1px solid rgba(220, 38, 38, 0.3);
        }
        
        .delete-button:hover {
            background: var(--danger);
            color: white;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(220, 38, 38, 0.3);
        }

        .attendance-button {
            background: linear-gradient(135deg, rgba(139, 92, 246, 0.1), rgba(139, 92, 246, 0.2));
            color: var(--purple);
            border: 1px solid rgba(139, 92, 246, 0.3);
        }
        
        .attendance-button:hover {
            background: var(--purple);
            color: white;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(139, 92, 246, 0.3);
        }

        .no-shifts {
            text-align: center;
            padding: 60px 20px;
            color: var(--text-light);
            grid-column: 1 / -1;
        }
        
        .no-shifts-icon {
            font-size: 64px;
            margin-bottom: 16px;
            color: var(--text-light);
            opacity: 0.5;
        }

        /* Quick Actions Panel */
        .quick-actions {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        
        .quick-action-card {
            background: linear-gradient(135deg, var(--card-bg), #ffffff);
            border: 1px solid var(--border-color);
            border-radius: 16px;
            padding: 24px;
            display: flex;
            align-items: center;
            gap: 16px;
            transition: all 0.3s ease;
            cursor: pointer;
            text-decoration: none;
            color: var(--text-color);
        }
        
        .dark-mode .quick-action-card {
            background: linear-gradient(135deg, var(--card-bg), #2d3748);
        }
        
        .quick-action-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.1);
            text-decoration: none;
            color: var(--text-color);
            border-color: var(--primary-color);
        }
        
        .action-icon {
            width: 56px;
            height: 56px;
            border-radius: 14px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
        }
        
        .quick-action-card:nth-child(1) .action-icon {
            background: linear-gradient(135deg, rgba(59, 130, 246, 0.1), rgba(59, 130, 246, 0.2));
            color: var(--info);
        }
        
        .quick-action-card:nth-child(2) .action-icon {
            background: linear-gradient(135deg, rgba(16, 185, 129, 0.1), rgba(16, 185, 129, 0.2));
            color: var(--success);
        }
        
        .quick-action-card:nth-child(3) .action-icon {
            background: linear-gradient(135deg, rgba(139, 92, 246, 0.1), rgba(139, 92, 246, 0.2));
            color: var(--purple);
        }
        
        .quick-action-card:nth-child(4) .action-icon {
            background: linear-gradient(135deg, rgba(245, 158, 11, 0.1), rgba(245, 158, 11, 0.2));
            color: var(--warning);
        }
        
        .action-content {
            flex: 1;
        }
        
        .action-title {
            font-size: 16px;
            font-weight: 600;
            margin-bottom: 4px;
        }
        
        .action-description {
            font-size: 13px;
            color: var(--text-light);
        }

        /* Modal Styles */
        .modal-overlay {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.5);
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 1000;
            opacity: 0;
            visibility: hidden;
            transition: all 0.3s ease;
            backdrop-filter: blur(5px);
        }
        
        .modal-overlay.active {
            opacity: 1;
            visibility: visible;
        }
        
        .modal {
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: 20px;
            width: 90%;
            max-width: 800px;
            max-height: 90vh;
            overflow-y: auto;
            transform: scale(0.9);
            transition: all 0.3s ease;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
        }
        
        .modal-overlay.active .modal {
            transform: scale(1);
        }
        
        .modal-header {
            padding: 24px;
            border-bottom: 1px solid var(--border-color);
            display: flex;
            align-items: center;
            justify-content: space-between;
            background: rgba(220, 38, 38, 0.02);
            position: sticky;
            top: 0;
            z-index: 10;
            backdrop-filter: blur(10px);
        }
        
        .modal-title {
            font-size: 20px;
            font-weight: 700;
            color: var(--text-color);
        }
        
        .modal-close {
            background: none;
            border: none;
            font-size: 24px;
            cursor: pointer;
            color: var(--text-light);
            transition: all 0.3s ease;
            width: 40px;
            height: 40px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 50%;
        }
        
        .modal-close:hover {
            background: var(--gray-100);
            color: var(--danger);
        }
        
        .dark-mode .modal-close:hover {
            background: var(--gray-800);
        }
        
        .modal-body {
            padding: 24px;
        }
        
        .form-group {
            margin-bottom: 20px;
        }
        
        .form-label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: var(--text-color);
        }
        
        .form-select, .form-textarea, .form-input {
            width: 100%;
            padding: 12px 16px;
            border-radius: 10px;
            border: 1px solid var(--border-color);
            background: var(--card-bg);
            color: var(--text-color);
            font-size: 14px;
            transition: all 0.3s ease;
        }
        
        .form-select:focus, .form-textarea:focus, .form-input:focus {
            outline: none;
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(220, 38, 38, 0.1);
        }
        
        .form-textarea {
            min-height: 100px;
            resize: vertical;
        }
        
        .modal-actions {
            display: flex;
            gap: 12px;
            justify-content: flex-end;
            margin-top: 24px;
            position: sticky;
            bottom: 0;
            background: var(--card-bg);
            padding: 16px 0;
            border-top: 1px solid var(--border-color);
            backdrop-filter: blur(10px);
        }
        
        .btn {
            padding: 12px 24px;
            border-radius: 10px;
            font-weight: 600;
            border: none;
            cursor: pointer;
            transition: all 0.3s ease;
        }
        
        .btn-primary {
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            color: white;
        }
        
        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(220, 38, 38, 0.3);
        }
        
        .btn-secondary {
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            color: var(--text-color);
        }
        
        .btn-secondary:hover {
            background: var(--gray-100);
        }
        
        .dark-mode .btn-secondary:hover {
            background: var(--gray-800);
        }

        /* Responsive Design */
        @media (max-width: 1400px) {
            .table-header, .table-row {
                grid-template-columns: 70px 140px 120px 100px 180px 110px 100px 100px;
                gap: 12px;
                padding: 16px;
            }
        }

        @media (max-width: 1200px) {
            .table-header, .table-row {
                grid-template-columns: 60px 130px 110px 90px 160px 100px 90px 90px;
                gap: 10px;
                padding: 14px;
            }
        }

        @media (max-width: 992px) {
            .table-header {
                display: none;
            }
            
            .table-row {
                grid-template-columns: 1fr;
                gap: 16px;
                padding: 20px;
                border: 1px solid var(--border-color);
                border-radius: 12px;
                margin-bottom: 12px;
            }
            
            .table-cell {
                display: grid;
                grid-template-columns: 140px 1fr;
                gap: 16px;
                align-items: start;
                border-bottom: 1px solid var(--border-color);
                padding-bottom: 12px;
            }
            
            .table-cell:last-child {
                border-bottom: none;
                padding-bottom: 0;
            }
            
            .table-cell::before {
                content: attr(data-label);
                font-weight: 600;
                color: var(--text-light);
                font-size: 13px;
            }
            
            .table-cell .action-buttons {
                grid-column: 1 / -1;
                display: flex;
                flex-wrap: wrap;
                gap: 8px;
                justify-content: center;
                margin-top: 10px;
            }
            
            .table-cell .action-button {
                flex: 1;
                min-width: 120px;
            }
            
            .filter-row {
                grid-template-columns: 1fr;
                gap: 16px;
            }
            
            .filter-actions {
                flex-direction: column;
            }
            
            .filter-button, .clear-filters {
                width: 100%;
                justify-content: center;
            }
            
            .dashboard-header {
                padding: 40px 25px 30px;
            }
            
            .dashboard-title {
                font-size: 32px;
            }
            
            .content-container {
                padding: 0 25px 30px;
            }
            
            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }

            .filter-tabs {
                flex-direction: column;
            }

            .modal {
                width: 95%;
                margin: 10px;
            }

            .quick-actions {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 768px) {
            .content-container {
                padding: 0 20px 30px;
            }
            
            .dashboard-header {
                padding: 30px 20px 25px;
            }
            
            .dashboard-title {
                font-size: 28px;
            }
            
            .stats-grid {
                grid-template-columns: 1fr;
                gap: 15px;
            }
            
            .stat-card {
                padding: 20px;
            }
            
            .filters-container {
                padding: 20px;
            }
            
            .action-buttons {
                flex-direction: column;
            }
            
            .action-button {
                width: 100%;
            }
        }

        .shifts-table-container {
            max-height: 600px;
            overflow-y: auto;
        }

        .shifts-table-container::-webkit-scrollbar {
            width: 6px;
        }
        
        .shifts-table-container::-webkit-scrollbar-track {
            background: var(--gray-100);
            border-radius: 3px;
        }
        
        .shifts-table-container::-webkit-scrollbar-thumb {
            background: var(--gray-400);
            border-radius: 3px;
        }
        
        .shifts-table-container::-webkit-scrollbar-thumb:hover {
            background: var(--gray-500);
        }
        
        .dark-mode .shifts-table-container::-webkit-scrollbar-track {
            background: var(--gray-800);
        }
        
        .dark-mode .shifts-table-container::-webkit-scrollbar-thumb {
            background: var(--gray-600);
        }
        
        .dark-mode .shifts-table-container::-webkit-scrollbar-thumb:hover {
            background: var(--gray-500);
        }

        .modal::-webkit-scrollbar {
            width: 6px;
        }
        
        .modal::-webkit-scrollbar-track {
            background: var(--card-bg);
            border-radius: 3px;
        }
        
        .modal::-webkit-scrollbar-thumb {
            background: var(--border-color);
            border-radius: 3px;
        }
        
        .modal::-webkit-scrollbar-thumb:hover {
            background: var(--gray-400);
        }

        /* Animation for table rows */
        @keyframes fadeIn {
            from {
                opacity: 0;
                transform: translateY(10px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .table-row {
            animation: fadeIn 0.3s ease forwards;
        }

        .table-row:nth-child(even) {
            background: rgba(220, 38, 38, 0.01);
        }
        
        .dark-mode .table-row:nth-child(even) {
            background: rgba(255, 255, 255, 0.01);
        }
    </style>
</head>
<body>
    <!-- Shift Details Modal -->
    <div class="modal-overlay" id="details-modal">
        <div class="modal">
            <div class="modal-header">
                <h2 class="modal-title">Shift Details</h2>
                <button class="modal-close" id="details-modal-close">&times;</button>
            </div>
            <div class="modal-body">
                <div id="details-content">
                    <!-- Details will be loaded here via JavaScript -->
                </div>
                <div class="modal-actions">
                    <button type="button" class="btn btn-secondary" id="close-details">Close</button>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Edit Shift Modal -->
    <div class="modal-overlay" id="edit-modal">
        <div class="modal">
            <div class="modal-header">
                <h2 class="modal-title">Edit Shift</h2>
                <button class="modal-close" id="edit-modal-close">&times;</button>
            </div>
            <div class="modal-body">
                <form id="edit-shift-form">
                    <input type="hidden" id="edit-shift-id" name="shift_id">
                    
                    <div class="form-group">
                        <label class="form-label" for="edit_status">Status</label>
                        <select class="form-select" id="edit_status" name="status" required>
                            <option value="scheduled">Scheduled</option>
                            <option value="confirmed">Confirmed</option>
                            <option value="completed">Completed</option>
                            <option value="cancelled">Cancelled</option>
                            <option value="absent">Absent</option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label" for="edit_confirmation_status">Confirmation Status</label>
                        <select class="form-select" id="edit_confirmation_status" name="confirmation_status">
                            <option value="pending">Pending</option>
                            <option value="confirmed">Confirmed</option>
                            <option value="declined">Declined</option>
                            <option value="change_requested">Change Requested</option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label" for="edit_notes">Admin Notes</label>
                        <textarea class="form-textarea" id="edit_notes" name="notes" placeholder="Enter any administrative notes..."></textarea>
                    </div>
                    
                    <div class="modal-actions">
                        <button type="button" class="btn btn-secondary" id="cancel-edit">Cancel</button>
                        <button type="submit" class="btn btn-primary">Update Shift</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Attendance Modal -->
    <div class="modal-overlay" id="attendance-modal">
        <div class="modal">
            <div class="modal-header">
                <h2 class="modal-title">Update Attendance</h2>
                <button class="modal-close" id="attendance-modal-close">&times;</button>
            </div>
            <div class="modal-body">
                <form id="update-attendance-form">
                    <input type="hidden" id="attendance-shift-id" name="shift_id">
                    
                    <div class="form-group">
                        <label class="form-label" for="attendance_status">Attendance Status</label>
                        <select class="form-select" id="attendance_status" name="attendance_status" required>
                            <option value="pending">Pending</option>
                            <option value="checked_in">Checked In</option>
                            <option value="checked_out">Checked Out</option>
                            <option value="absent">Absent</option>
                            <option value="excused">Excused</option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label" for="attendance_check_in">Check-in Time</label>
                        <input type="datetime-local" class="form-input" id="attendance_check_in" name="check_in">
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label" for="attendance_check_out">Check-out Time</label>
                        <input type="datetime-local" class="form-input" id="attendance_check_out" name="check_out">
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label" for="attendance_notes">Attendance Notes</label>
                        <textarea class="form-textarea" id="attendance_notes" name="attendance_notes" placeholder="Enter attendance notes..."></textarea>
                    </div>
                    
                    <div class="modal-actions">
                        <button type="button" class="btn btn-secondary" id="cancel-attendance">Cancel</button>
                        <button type="submit" class="btn btn-primary">Update Attendance</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <div class="container">
        <!-- Sidebar -->
        <div class="sidebar">
            <!-- Logo -->
            <div class="logo">
                <div class="logo-icon">
                    <img src="../../img/frsm-logo.png" alt="Fire & Rescue Logo" style="width: 40px; height: 45px;">
                </div>
                <span class="logo-text">Fire & Rescue</span>
            </div>
            
            <!-- Menu Section -->
            <div class="menu-section">
                <p class="menu-title">FIRE & RESCUE MANAGEMENT</p>
                
                <div class="menu-items">
                    <a href="../admin/dashboard.php" class="menu-item">
                        <div class="icon-box icon-bg-red">
                            <i class='bx bxs-dashboard icon-red'></i>
                        </div>
                        <span class="font-medium">Dashboard</span>
                    </a>
                    
                    <!-- User Management -->
                    <div class="menu-item" onclick="toggleSubmenu('user-management')">
                        <div class="icon-box icon-bg-orange">
                            <i class='bx bxs-user icon-orange'></i>
                        </div>
                        <span class="font-medium">User Management</span>
                        <svg class="dropdown-arrow menu-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                        </svg>
                    </div>
                    <div id="user-management" class="submenu">
                        <a href="manage_users.php" class="submenu-item">Manage Users</a>
                        <a href="role_control.php" class="submenu-item">Role Control</a>
                        <a href="audit_logs.php" class="submenu-item">Audit & Activity Logs</a>
                    </div>
                    
                    <!-- Fire & Incident Reporting Management -->
                    <div class="menu-item" onclick="toggleSubmenu('incident-management')">
                        <div class="icon-box icon-bg-yellow">
                            <i class='bx bxs-alarm-exclamation icon-yellow'></i>
                        </div>
                        <span class="font-medium">Incident Management</span>
                        <svg class="dropdown-arrow menu-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                        </svg>
                    </div>
                    <div id="incident-management" class="submenu">
                        <a href="#" class="submenu-item">View Reports</a>
                        <a href="#" class="submenu-item">Validate Data</a>
                        <a href="#" class="submenu-item">Assign Severity</a>
                        <a href="#" class="submenu-item">Track Progress</a>
                        <a href="#" class="submenu-item">Mark Resolved</a>
                    </div>
                    
                    <!-- Volunteer Management -->
                    <div class="menu-item" onclick="toggleSubmenu('volunteer-management')">
                        <div class="icon-box icon-bg-blue">
                            <i class='bx bxs-user-detail icon-blue'></i>
                        </div>
                        <span class="font-medium">Volunteer Management</span>
                        <svg class="dropdown-arrow menu-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                        </svg>
                    </div>
                    <div id="volunteer-management" class="submenu">
                        <a href="../volunteer/review_data.php" class="submenu-item">Review Data</a>
                        <a href="../volunteer/approve_applications.php" class="submenu-item">Approve Applications</a>
                        <a href="../volunteer/assign_volunteers.php" class="submenu-item">Assign Volunteers</a>
                        <a href="../volunteer/view_availability.php" class="submenu-item">View Availability</a>
                        <a href="../volunteer/remove_volunteers.php" class="submenu-item">Remove Volunteers</a>
                        <a href="../volunteer/toggle_volunteer_registration.php" class="submenu-item">Toggle Volunteer Registration Access</a>
                    </div>
                    
                    <!-- Resource Inventory Management -->
                    <div class="menu-item" onclick="toggleSubmenu('resource-management')">
                        <div class="icon-box icon-bg-green">
                            <i class='bx bxs-cube icon-green'></i>
                        </div>
                        <span class="font-medium">Resource Management</span>
                        <svg class="dropdown-arrow menu-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                        </svg>
                    </div>
                    <div id="resource-management" class="submenu">
                        <a href="#" class="submenu-item">View Equipment</a>
                        <a href="#" class="submenu-item">Approve Maintenance</a>
                        <a href="#" class="submenu-item">Approve Resources</a>
                        <a href="#" class="submenu-item">Review Deployment</a>
                    </div>
                    
                    <!-- Shift & Duty Scheduling -->
                    <div class="menu-item active" onclick="toggleSubmenu('schedule-management')">
                        <div class="icon-box icon-bg-purple">
                            <i class='bx bxs-calendar icon-purple'></i>
                        </div>
                        <span class="font-medium">Schedule Management</span>
                        <svg class="dropdown-arrow menu-icon rotated" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                        </svg>
                    </div>
                    <div id="schedule-management" class="submenu active">
                       <a href="view_shifts.php" class="submenu-item active">View Shifts</a>
                        <a href="create_schedule.php" class="submenu-item">Create Schedule</a>
                          <a href="confirm_availability.php" class="submenu-item">Confirm Availability</a>
                        <a href="request_change.php" class="submenu-item">Request Change</a>
                        <a href="monitor_attendance.php" class="submenu-item">Monitor Attendance</a>
                    </div>
                    
                   <!-- Training & Certification Monitoring -->
                    <div class="menu-item" onclick="toggleSubmenu('training-management')">
                        <div class="icon-box icon-bg-teal">
                            <i class='bx bxs-graduation icon-teal'></i>
                        </div>
                        <span class="font-medium">Training Management</span>
                        <svg class="dropdown-arrow menu-icon rotated" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                        </svg>
                    </div>
                    <div id="training-management" class="submenu">
                        <a href="../tc/approve_completions.php" class="submenu-item">Approve Completions</a>
                        <a href="../tc/view_training_records.php" class="submenu-item">View Records</a>
                        <a href="../tc/assign_training.php" class="submenu-item">Assign Training</a>
                        <a href="../tc/track_expiry.php" class="submenu-item">Track Expiry</a>
                    </div>
                    
                    <!-- Inspection Logs for Establishments -->
                    <div class="menu-item" onclick="toggleSubmenu('inspection-management')">
                        <div class="icon-box icon-bg-cyan">
                            <i class='bx bxs-check-shield icon-cyan'></i>
                        </div>
                        <span class="font-medium">Inspection Management</span>
                        <svg class="dropdown-arrow menu-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                        </svg>
                    </div>
                    <div id="inspection-management" class="submenu">
                        <a href="#" class="submenu-item">Approve Reports</a>
                        <a href="#" class="submenu-item">Review Violations</a>
                        <a href="#" class="submenu-item">Issue Certificates</a>
                        <a href="#" class="submenu-item">Track Follow-Up</a>
                    </div>
                    
                    <!-- Post-Incident Reporting & Analytics -->
                    <div class="menu-item" onclick="toggleSubmenu('analytics-management')">
                        <div class="icon-box icon-bg-pink">
                            <i class='bx bxs-file-doc icon-pink'></i>
                        </div>
                        <span class="font-medium">Analytics & Reports</span>
                        <svg class="dropdown-arrow menu-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                        </svg>
                    </div>
                    <div id="analytics-management" class="submenu">
                        <a href="#" class="submenu-item">Review Summaries</a>
                        <a href="#" class="submenu-item">Analyze Data</a>
                        <a href="#" class="submenu-item">Export Reports</a>
                        <a href="#" class="submenu-item">Generate Statistics</a>
                    </div>
                </div>
                
                <p class="menu-title" style="margin-top: 32px;">GENERAL</p>
                
                <div class="menu-items">
                    <a href="#" class="menu-item">
                        <div class="icon-box icon-bg-teal">
                            <i class='bx bxs-cog icon-teal'></i>
                        </div>
                        <span class="font-medium">Settings</span>
                    </a>
                    
                    <a href="../profile.php" class="menu-item">
                        <div class="icon-box icon-bg-orange">
                            <i class='bx bxs-user icon-orange'></i>
                        </div>
                        <span class="font-medium">Profile</span>
                    </a>
                    
                    <a href="../../includes/logout.php" class="menu-item">
                        <div class="icon-box icon-bg-red">
                            <i class='bx bx-log-out icon-red'></i>
                        </div>
                        <span class="font-medium">Logout</span>
                    </a>
                </div>
            </div>
        </div>
        <!-- Main Content -->
        <div class="main-content">
            <!-- Header -->
            <div class="header">
                <div class="header-content">
                    <div class="search-container">
                        <div class="search-box">
                            <svg class="search-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path>
                            </svg>
                            <input type="text" placeholder="Search shifts..." class="search-input" id="search-input">
                        </div>
                    </div>
                    
                    <div class="header-actions">
                        <button class="theme-toggle" id="theme-toggle">
                            <i class='bx bx-moon'></i>
                            <span>Dark Mode</span>
                        </button>
                        <div class="time-display" id="time-display">
                            <i class='bx bx-time time-icon'></i>
                            <span id="current-time">Loading...</span>
                        </div>
                        <div class="user-profile" id="user-profile">
                            <?php if ($avatar): ?>
                                <img src="../../img/<?php echo $avatar; ?>" alt="User" class="user-avatar">
                            <?php else: ?>
                                <div class="user-avatar" style="background: linear-gradient(135deg, var(--primary-color), var(--secondary-color)); color: white; display: flex; align-items: center; justify-content: center; font-weight: bold; border-radius: 50%; width: 40px; height: 40px;">
                                    <?php echo strtoupper(substr($full_name, 0, 1)); ?>
                                </div>
                            <?php endif; ?>
                            <div class="user-info">
                                <p class="user-name"><?php echo $full_name; ?></p>
                                <p class="user-email"><?php echo $role; ?></p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Dashboard Content -->
            <div class="dashboard-content">
                <div class="dashboard-header">
                    <div>
                        <h1 class="dashboard-title">Shift Management</h1>
                        <p class="dashboard-subtitle">Admin Panel - View and manage all shifts across the organization</p>
                    </div>
                </div>
                
                <!-- Content Container -->
                <div class="content-container">
                    <!-- Quick Actions -->
                    <div class="quick-actions">
                        <a href="../sm/create_schedule.php" class="quick-action-card">
                            <div class="action-icon">
                                <i class='bx bxs-calendar-plus'></i>
                            </div>
                            <div class="action-content">
                                <div class="action-title">Create New Schedule</div>
                                <div class="action-description">Create bulk schedules for volunteers or employees</div>
                            </div>
                        </a>
                        <a href="../sm/approve_shifts.php" class="quick-action-card">
                            <div class="action-icon">
                                <i class='bx bxs-check-shield'></i>
                            </div>
                            <div class="action-content">
                                <div class="action-title">Approve Shifts</div>
                                <div class="action-description">Review and approve pending shift confirmations</div>
                            </div>
                        </a>
                        <a href="../sm/override_assignments.php" class="quick-action-card">
                            <div class="action-icon">
                                <i class='bx bxs-calendar-exclamation'></i>
                            </div>
                            <div class="action-content">
                                <div class="action-title">Override Assignments</div>
                                <div class="action-description">Make manual adjustments to shift assignments</div>
                            </div>
                        </a>
                        <a href="../sm/monitor_attendance.php" class="quick-action-card">
                            <div class="action-icon">
                                <i class='bx bxs-user-check'></i>
                            </div>
                            <div class="action-content">
                                <div class="action-title">Monitor Attendance</div>
                                <div class="action-description">Track attendance and generate reports</div>
                            </div>
                        </a>
                    </div>
                    
                    <!-- Statistics Cards -->
                    <div class="stats-grid">
                        <div class="stat-card" data-type="total" onclick="filterByStatus('all')">
                            <div class="stat-header">
                                <div class="stat-icon-container">
                                    <i class='bx bxs-calendar'></i>
                                </div>
                                <div class="stat-trend">
                                    <i class='bx bx-up-arrow-alt'></i>
                                    +12%
                                </div>
                            </div>
                            <div class="stat-value"><?php echo $stats['total']; ?></div>
                            <div class="stat-label">Total Shifts</div>
                        </div>
                        <div class="stat-card" data-type="upcoming" onclick="filterByStatus('scheduled')">
                            <div class="stat-header">
                                <div class="stat-icon-container">
                                    <i class='bx bxs-up-arrow'></i>
                                </div>
                                <div class="stat-trend">
                                    <i class='bx bx-up-arrow-alt'></i>
                                    +5%
                                </div>
                            </div>
                            <div class="stat-value"><?php echo $stats['upcoming']; ?></div>
                            <div class="stat-label">Upcoming Shifts</div>
                        </div>
                        <div class="stat-card" data-type="completed" onclick="filterByStatus('completed')">
                            <div class="stat-header">
                                <div class="stat-icon-container">
                                    <i class='bx bxs-check-circle'></i>
                                </div>
                                <div class="stat-trend">
                                    <i class='bx bx-up-arrow-alt'></i>
                                    +8%
                                </div>
                            </div>
                            <div class="stat-value"><?php echo $stats['completed']; ?></div>
                            <div class="stat-label">Completed</div>
                        </div>
                        <div class="stat-card" data-type="cancelled" onclick="filterByStatus('cancelled')">
                            <div class="stat-header">
                                <div class="stat-icon-container">
                                    <i class='bx bxs-x-circle'></i>
                                </div>
                                <div class="stat-trend">
                                    <i class='bx bx-down-arrow-alt'></i>
                                    -2%
                                </div>
                            </div>
                            <div class="stat-value"><?php echo $stats['cancelled']; ?></div>
                            <div class="stat-label">Cancelled</div>
                        </div>
                        <div class="stat-card" data-type="today" onclick="filterByDate('today')">
                            <div class="stat-header">
                                <div class="stat-icon-container">
                                    <i class='bx bxs-sun'></i>
                                </div>
                                <div class="stat-trend">
                                    <i class='bx bx-up-arrow-alt'></i>
                                    +3%
                                </div>
                            </div>
                            <div class="stat-value"><?php echo $stats['today']; ?></div>
                            <div class="stat-label">Today's Shifts</div>
                        </div>
                        <div class="stat-card" data-type="with_duty" onclick="filterByDuty()">
                            <div class="stat-header">
                                <div class="stat-icon-container">
                                    <i class='bx bxs-briefcase'></i>
                                </div>
                                <div class="stat-trend">
                                    <i class='bx bx-up-arrow-alt'></i>
                                    +15%
                                </div>
                            </div>
                            <div class="stat-value"><?php echo $stats['with_duty']; ?></div>
                            <div class="stat-label">With Duty Assignment</div>
                        </div>
                        <div class="stat-card" data-type="pending_confirmation" onclick="showPendingConfirmations()">
                            <div class="stat-header">
                                <div class="stat-icon-container">
                                    <i class='bx bxs-time-five'></i>
                                </div>
                                <div class="stat-trend">
                                    <i class='bx bx-up-arrow-alt'></i>
                                    +2%
                                </div>
                            </div>
                            <div class="stat-value"><?php echo $stats['pending_confirmation']; ?></div>
                            <div class="stat-label">Pending Confirmation</div>
                        </div>
                        <div class="stat-card" data-type="checked_in" onclick="showCheckedIn()">
                            <div class="stat-header">
                                <div class="stat-icon-container">
                                    <i class='bx bxs-log-in'></i>
                                </div>
                                <div class="stat-trend">
                                    <i class='bx bx-up-arrow-alt'></i>
                                    +7%
                                </div>
                            </div>
                            <div class="stat-value"><?php echo $stats['checked_in']; ?></div>
                            <div class="stat-label">Checked In</div>
                        </div>
                    </div>
                    
                    <!-- Filter Tabs Container -->
                    <div class="filter-tabs-container">
                        <div class="filter-header">
                            <h3 class="filter-title">
                                <i class='bx bxs-calendar'></i>
                                Shift Overview - Admin View
                            </h3>
                        </div>
                        
                        <div class="filter-tabs">
                            <a href="?status=all&date=<?php echo $filter_date; ?>&search=<?php echo urlencode($search_query); ?>&duty_type=<?php echo $filter_duty_type; ?>&unit=<?php echo $filter_unit; ?>" class="filter-tab <?php echo $filter_status === 'all' ? 'active' : ''; ?>">
                                <i class='bx bxs-dashboard'></i>
                                All Shifts
                                <span class="filter-tab-count"><?php echo $stats['total']; ?></span>
                            </a>
                            <a href="?status=scheduled&date=<?php echo $filter_date; ?>&search=<?php echo urlencode($search_query); ?>&duty_type=<?php echo $filter_duty_type; ?>&unit=<?php echo $filter_unit; ?>" class="filter-tab <?php echo $filter_status === 'scheduled' ? 'active' : ''; ?>">
                                <i class='bx bxs-time'></i>
                                Scheduled
                            </a>
                            <a href="?status=confirmed&date=<?php echo $filter_date; ?>&search=<?php echo urlencode($search_query); ?>&duty_type=<?php echo $filter_duty_type; ?>&unit=<?php echo $filter_unit; ?>" class="filter-tab <?php echo $filter_status === 'confirmed' ? 'active' : ''; ?>">
                                <i class='bx bxs-check-circle'></i>
                                Confirmed
                            </a>
                            <a href="?status=completed&date=<?php echo $filter_date; ?>&search=<?php echo urlencode($search_query); ?>&duty_type=<?php echo $filter_duty_type; ?>&unit=<?php echo $filter_unit; ?>" class="filter-tab <?php echo $filter_status === 'completed' ? 'active' : ''; ?>">
                                <i class='bx bxs-check-double'></i>
                                Completed
                            </a>
                            <a href="?status=cancelled&date=<?php echo $filter_date; ?>&search=<?php echo urlencode($search_query); ?>&duty_type=<?php echo $filter_duty_type; ?>&unit=<?php echo $filter_unit; ?>" class="filter-tab <?php echo $filter_status === 'cancelled' ? 'active' : ''; ?>">
                                <i class='bx bxs-x-circle'></i>
                                Cancelled
                            </a>
                            <a href="?status=absent&date=<?php echo $filter_date; ?>&search=<?php echo urlencode($search_query); ?>&duty_type=<?php echo $filter_duty_type; ?>&unit=<?php echo $filter_unit; ?>" class="filter-tab <?php echo $filter_status === 'absent' ? 'active' : ''; ?>">
                                <i class='bx bxs-user-x'></i>
                                Absent
                            </a>
                        </div>
                    </div>
                    
                    <!-- Advanced Filters -->
                    <div class="filters-container">
                        <div class="filter-section">
                            <h4 class="filter-section-title">
                                <i class='bx bx-filter-alt'></i>
                                Advanced Filters
                            </h4>
                            
                            <form method="GET" id="filter-form">
                                <div class="filter-row">
                                    <div class="filter-group">
                                        <label class="filter-label">
                                            <i class='bx bx-calendar'></i>
                                            Date Range
                                        </label>
                                        <select class="filter-select" name="date">
                                            <?php foreach ($date_options as $value => $label): ?>
                                                <option value="<?php echo $value; ?>" <?php echo $filter_date === $value ? 'selected' : ''; ?>>
                                                    <?php echo $label; ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    
                                    <div class="filter-group">
                                        <label class="filter-label">
                                            <i class='bx bxs-briefcase'></i>
                                            Duty Type
                                        </label>
                                        <select class="filter-select" name="duty_type">
                                            <option value="">All Duty Types</option>
                                            <?php foreach ($duty_types as $duty_type): ?>
                                                <option value="<?php echo htmlspecialchars($duty_type); ?>" <?php echo $filter_duty_type === $duty_type ? 'selected' : ''; ?>>
                                                    <?php echo ucfirst(str_replace('_', ' ', $duty_type)); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    
                                    <div class="filter-group">
                                        <label class="filter-label">
                                            <i class='bx bxs-building'></i>
                                            Unit
                                        </label>
                                        <select class="filter-select" name="unit">
                                            <option value="">All Units</option>
                                            <?php foreach ($units as $unit): ?>
                                                <option value="<?php echo $unit['id']; ?>" <?php echo $filter_unit == $unit['id'] ? 'selected' : ''; ?>>
                                                    <?php echo htmlspecialchars($unit['unit_name'] . ' (' . $unit['unit_code'] . ')'); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>
                                
                                <div class="filter-row">
                                    <div class="filter-group">
                                        <label class="filter-label">
                                            <i class='bx bx-search'></i>
                                            Search
                                        </label>
                                        <input type="text" class="filter-input" name="search" placeholder="Search by name, email, unit, location..." value="<?php echo htmlspecialchars($search_query); ?>">
                                    </div>
                                </div>
                                
                                <div class="filter-actions">
                                    <a href="view_shifts.php" class="filter-button clear-filters">
                                        <i class='bx bx-x'></i>
                                        Clear All Filters
                                    </a>
                                    <button type="submit" class="filter-button">
                                        <i class='bx bx-filter-alt'></i>
                                        Apply Filters
                                    </button>
                                </div>
                                
                                <!-- Hidden field to preserve status filter -->
                                <input type="hidden" name="status" value="<?php echo $filter_status; ?>">
                            </form>
                        </div>
                    </div>
                    
                    <!-- Shifts Table -->
                    <div class="shifts-table-container">
                        <div class="table-header">
                            <div>ID</div>
                            <div>Date & Time</div>
                            <div>Assigned To</div>
                            <div>Unit</div>
                            <div>Shift Type</div>
                            <div>Status</div>
                            <div>Confirmation</div>
                            <div>Actions</div>
                        </div>
                        <div class="shifts-table-container" style="max-height: 500px;">
                            <?php if (count($shifts) > 0): ?>
                                <?php foreach ($shifts as $index => $shift): ?>
                                    <?php 
                                    $shiftDate = new DateTime($shift['shift_date']);
                                    $shiftTypeClass = 'shift-type-' . strtolower($shift['shift_type']);
                                    $statusClass = 'status-' . strtolower($shift['status']);
                                    ?>
                                    <div class="table-row" style="animation-delay: <?php echo $index * 0.05; ?>s;">
                                        <div class="table-cell" data-label="ID">
                                            <div class="shift-id">#<?php echo $shift['id']; ?></div>
                                        </div>
                                        <div class="table-cell" data-label="Date & Time">
                                            <div class="shift-date"><?php echo $shiftDate->format('M j, Y'); ?></div>
                                            <div class="shift-time">
                                                <?php echo formatTime($shift['start_time']); ?> - <?php echo formatTime($shift['end_time']); ?>
                                            </div>
                                        </div>
                                        <div class="table-cell" data-label="Assigned To">
                                            <div class="unit-info">
                                                <div class="unit-name"><?php echo htmlspecialchars($shift['assigned_to_name']); ?></div>
                                                <?php if ($shift['assigned_to_email']): ?>
                                                    <div class="unit-code"><?php echo htmlspecialchars($shift['assigned_to_email']); ?></div>
                                                <?php endif; ?>
                                                <div class="unit-code" style="font-size: 10px; color: var(--text-light);">
                                                    <?php echo ucfirst($shift['shift_for']); ?>
                                                    <?php if ($shift['user_id']): ?> (User ID: <?php echo $shift['user_id']; ?>)<?php endif; ?>
                                                    <?php if ($shift['volunteer_id']): ?> (Volunteer ID: <?php echo $shift['volunteer_id']; ?>)<?php endif; ?>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="table-cell" data-label="Unit">
                                            <?php if ($shift['unit_name']): ?>
                                                <div class="unit-info">
                                                    <div class="unit-name"><?php echo htmlspecialchars($shift['unit_name']); ?></div>
                                                    <div class="unit-code"><?php echo htmlspecialchars($shift['unit_code']); ?></div>
                                                </div>
                                            <?php else: ?>
                                                <div>Not assigned</div>
                                            <?php endif; ?>
                                        </div>
                                        <div class="table-cell" data-label="Shift Type">
                                            <div class="shift-type-badge <?php echo $shiftTypeClass; ?>">
                                                <i class='bx <?php echo $shift_type_icons[strtolower($shift['shift_type'])]; ?>'></i>
                                                <?php echo ucfirst(str_replace('_', ' ', $shift['shift_type'])); ?>
                                            </div>
                                        </div>
                                        <div class="table-cell" data-label="Status">
                                            <div class="status-badge <?php echo $statusClass; ?>">
                                                <?php echo ucfirst($shift['status']); ?>
                                            </div>
                                        </div>
                                        <div class="table-cell" data-label="Confirmation">
                                            <?php if ($shift['confirmation_status']): ?>
                                                <?php echo getConfirmationBadge($shift['confirmation_status']); ?>
                                            <?php else: ?>
                                                <span style="color: var(--text-light); font-size: 12px;">N/A</span>
                                            <?php endif; ?>
                                        </div>
                                        <div class="table-cell" data-label="Actions">
                                            <div class="action-buttons">
                                                <button class="action-button view-button" onclick="viewShiftDetails(<?php echo $shift['id']; ?>)">
                                                    <i class='bx bxs-info-circle'></i>
                                                    View
                                                </button>
                                                
                                                <button class="action-button edit-button" onclick="editShift(<?php echo $shift['id']; ?>)">
                                                    <i class='bx bxs-edit'></i>
                                                    Edit
                                                </button>
                                                
                                                <button class="action-button attendance-button" onclick="updateAttendance(<?php echo $shift['id']; ?>)">
                                                    <i class='bx bxs-log-in'></i>
                                                    Attendance
                                                </button>
                                                
                                                <?php if ($shift['status'] !== 'cancelled'): ?>
                                                    <button class="action-button delete-button" onclick="cancelShift(<?php echo $shift['id']; ?>)">
                                                        <i class='bx bxs-trash'></i>
                                                        Cancel
                                                    </button>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <div class="no-shifts">
                                    <div class="no-shifts-icon">
                                        <i class='bx bxs-calendar-x'></i>
                                    </div>
                                    <h3>No Shifts Found</h3>
                                    <p>No shifts match your current filters.</p>
                                    <?php if ($filter_status !== 'all' || $filter_date !== '' || $search_query !== '' || $filter_duty_type !== '' || $filter_unit !== ''): ?>
                                        <a href="view_shifts.php" class="filter-button" style="margin-top: 16px;">
                                            <i class='bx bx-x'></i>
                                            Clear Filters
                                        </a>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Initialize event listeners
            initEventListeners();
            
            // Update time display
            updateTime();
            setInterval(updateTime, 1000);
            
            // Initialize search functionality
            initSearch();
            
            // Add data labels for mobile view
            addDataLabels();
            
            // Initialize tooltips
            initTooltips();
            
            // Add animation to stat cards
            animateStatCards();
        });
        
        function initEventListeners() {
            // Theme toggle
            const themeToggle = document.getElementById('theme-toggle');
            const themeIcon = themeToggle.querySelector('i');
            const themeText = themeToggle.querySelector('span');
            
            themeToggle.addEventListener('click', function() {
                document.body.classList.toggle('dark-mode');
                
                if (document.body.classList.contains('dark-mode')) {
                    themeIcon.className = 'bx bx-sun';
                    themeText.textContent = 'Light Mode';
                } else {
                    themeIcon.className = 'bx bx-moon';
                    themeText.textContent = 'Dark Mode';
                }
                
                // Save theme preference
                localStorage.setItem('theme', document.body.classList.contains('dark-mode') ? 'dark' : 'light');
            });
            
            // Load saved theme preference
            const savedTheme = localStorage.getItem('theme');
            if (savedTheme === 'dark') {
                document.body.classList.add('dark-mode');
                themeIcon.className = 'bx bx-sun';
                themeText.textContent = 'Light Mode';
            }
            
            // Modal functionality
            const detailsModal = document.getElementById('details-modal');
            const detailsModalClose = document.getElementById('details-modal-close');
            const closeDetails = document.getElementById('close-details');
            
            detailsModalClose.addEventListener('click', closeDetailsModal);
            closeDetails.addEventListener('click', closeDetailsModal);
            
            detailsModal.addEventListener('click', function(e) {
                if (e.target === this) {
                    closeDetailsModal();
                }
            });
            
            // Edit modal functionality
            const editModal = document.getElementById('edit-modal');
            const editModalClose = document.getElementById('edit-modal-close');
            const cancelEdit = document.getElementById('cancel-edit');
            
            editModalClose.addEventListener('click', closeEditModal);
            cancelEdit.addEventListener('click', closeEditModal);
            
            editModal.addEventListener('click', function(e) {
                if (e.target === this) {
                    closeEditModal();
                }
            });
            
            // Attendance modal functionality
            const attendanceModal = document.getElementById('attendance-modal');
            const attendanceModalClose = document.getElementById('attendance-modal-close');
            const cancelAttendance = document.getElementById('cancel-attendance');
            
            attendanceModalClose.addEventListener('click', closeAttendanceModal);
            cancelAttendance.addEventListener('click', closeAttendanceModal);
            
            attendanceModal.addEventListener('click', function(e) {
                if (e.target === this) {
                    closeAttendanceModal();
                }
            });
            
            // Edit shift form submission
            const editForm = document.getElementById('edit-shift-form');
            editForm.addEventListener('submit', function(e) {
                e.preventDefault();
                submitEditShift();
            });
            
            // Update attendance form submission
            const attendanceForm = document.getElementById('update-attendance-form');
            attendanceForm.addEventListener('submit', function(e) {
                e.preventDefault();
                submitAttendanceUpdate();
            });
            
            // Filter form submission
            const filterForm = document.getElementById('filter-form');
            
            // Handle filter select changes
            filterForm.querySelectorAll('select').forEach(select => {
                select.addEventListener('change', function() {
                    filterForm.submit();
                });
            });
            
            // Add click handlers for stat cards
            document.querySelectorAll('.stat-card').forEach(card => {
                card.addEventListener('click', function() {
                    const type = this.dataset.type;
                    handleStatCardClick(type);
                });
            });
        }
        
        function initSearch() {
            const searchInput = document.getElementById('search-input');
            const filterForm = document.getElementById('filter-form');
            const searchParam = filterForm.querySelector('input[name="search"]');
            
            // Set search input value from URL parameter
            searchInput.value = '<?php echo htmlspecialchars($search_query); ?>';
            
            // Add event listener for search input
            searchInput.addEventListener('keypress', function(e) {
                if (e.key === 'Enter') {
                    searchParam.value = this.value;
                    filterForm.submit();
                }
            });
            
            // Add debounced search
            let searchTimeout;
            searchInput.addEventListener('input', function() {
                clearTimeout(searchTimeout);
                searchTimeout = setTimeout(() => {
                    if (this.value.length >= 2 || this.value.length === 0) {
                        searchParam.value = this.value;
                        filterForm.submit();
                    }
                }, 500);
            });
        }
        
        function initTooltips() {
            // Add tooltips to duty type badges
            document.querySelectorAll('.duty-type-badge').forEach(badge => {
                const title = badge.querySelector('.duty-type-title')?.textContent || '';
                const description = badge.querySelector('.duty-description')?.textContent || '';
                
                if (description) {
                    badge.title = `${title}: ${description}`;
                }
            });
            
            // Add tooltips to action buttons
            document.querySelectorAll('.action-button').forEach(button => {
                const text = button.textContent.trim();
                button.title = text;
            });
        }
        
        function animateStatCards() {
            document.querySelectorAll('.stat-card').forEach((card, index) => {
                card.style.animationDelay = `${index * 0.1}s`;
                card.style.opacity = '0';
                card.style.transform = 'translateY(20px)';
                
                setTimeout(() => {
                    card.style.transition = 'all 0.5s ease';
                    card.style.opacity = '1';
                    card.style.transform = 'translateY(0)';
                }, index * 100);
            });
        }
        
        function addDataLabels() {
            // This function adds data-label attributes for mobile responsive view
            const tableCells = document.querySelectorAll('.table-cell');
            const headers = document.querySelectorAll('.table-header > div');
            
            if (window.innerWidth <= 992) {
                const headerLabels = Array.from(headers).map(header => header.textContent);
                
                tableCells.forEach((cell, index) => {
                    const rowIndex = Math.floor(index / 8); // 8 columns
                    const colIndex = index % 8;
                    
                    if (colIndex < headerLabels.length) {
                        cell.setAttribute('data-label', headerLabels[colIndex]);
                    }
                });
            }
        }
        
        function viewShiftDetails(shiftId) {
            const detailsModal = document.getElementById('details-modal');
            const detailsContent = document.getElementById('details-content');
            
            // Show loading animation
            detailsContent.innerHTML = `
                <div style="text-align: center; padding: 40px;">
                    <div style="width: 60px; height: 60px; margin: 0 auto 20px; border: 4px solid rgba(220, 38, 38, 0.1); border-top-color: var(--primary-color); border-radius: 50%; animation: spin 1s linear infinite;"></div>
                    <p style="color: var(--text-light);">Loading shift details...</p>
                </div>
                <style>
                    @keyframes spin {
                        0% { transform: rotate(0deg); }
                        100% { transform: rotate(360deg); }
                    }
                </style>
            `;
            
            // Fetch shift details via AJAX
            fetch(`get_shift_details.php?id=${shiftId}&admin=true`)
                .then(response => {
                    // First check if response is JSON
                    const contentType = response.headers.get("content-type");
                    if (contentType && contentType.includes("application/json")) {
                        return response.json();
                    } else {
                        // If not JSON, get text and try to parse
                        return response.text().then(text => {
                            console.error("Non-JSON response:", text.substring(0, 200));
                            throw new Error("Server returned non-JSON response");
                        });
                    }
                })
                .then(data => {
                    if (data.success) {
                        const shift = data.shift;
                        renderShiftDetails(shift);
                    } else {
                        detailsContent.innerHTML = `
                            <div style="text-align: center; padding: 40px; color: var(--danger);">
                                <i class="bx bx-error" style="font-size: 48px; margin-bottom: 16px;"></i>
                                <h3 style="margin-bottom: 8px;">Error</h3>
                                <p>${data.message || 'Failed to load shift details'}</p>
                            </div>
                        `;
                    }
                })
                .catch(error => {
                    console.error('Fetch error:', error);
                    detailsContent.innerHTML = `
                        <div style="text-align: center; padding: 40px; color: var(--danger);">
                            <i class="bx bx-error" style="font-size: 48px; margin-bottom: 16px;"></i>
                            <h3 style="margin-bottom: 8px;">Network Error</h3>
                            <p>Failed to load shift details. Please check your connection and try again.</p>
                            <p style="font-size: 12px; margin-top: 10px;">Error: ${error.message}</p>
                        </div>
                    `;
                });
            
            // Open modal
            detailsModal.classList.add('active');
        }
        
        function renderShiftDetails(shift) {
            const detailsContent = document.getElementById('details-content');
            
            // Get shift type icon
            const shiftTypeIcons = {
                'morning': 'bx-sun',
                'afternoon': 'bx-cloud',
                'evening': 'bx-moon',
                'night': 'bx-bed',
                'full_day': 'bx-calendar'
            };
            
            // Format times
            function formatTime(timeString) {
                if (!timeString) return 'N/A';
                const time = new Date('1970-01-01T' + timeString);
                return time.toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit', hour12: true });
            }
            
            // Calculate duration
            function calculateDuration(startTime, endTime) {
                const start = new Date('1970-01-01T' + startTime);
                const end = new Date('1970-01-01T' + endTime);
                const diffMs = end - start;
                const hours = Math.floor(diffMs / (1000 * 60 * 60));
                const minutes = Math.floor((diffMs % (1000 * 60 * 60)) / (1000 * 60));
                return `${hours}h ${minutes}m`;
            }
            
            // Get confirmation badge HTML
            function getConfirmationBadgeHTML(status) {
                const statusColors = {
                    'pending': { bg: '#fef3c7', color: '#f59e0b' },
                    'confirmed': { bg: '#d1fae5', color: '#10b981' },
                    'declined': { bg: '#fee2e2', color: '#dc2626' },
                    'change_requested': { bg: '#f3e8ff', color: '#8b5cf6' }
                };
                
                const config = statusColors[status?.toLowerCase()] || { bg: '#f3f4f6', color: '#6b7280' };
                const text = status ? status.charAt(0).toUpperCase() + status.slice(1).replace('_', ' ') : 'N/A';
                
                return `<span style="padding: 4px 8px; border-radius: 12px; font-size: 12px; font-weight: 600; background: ${config.bg}; color: ${config.color}; border: 1px solid ${config.color}20;">${text}</span>`;
            }
            
            // Get attendance badge HTML
            function getAttendanceBadgeHTML(status) {
                const statusColors = {
                    'pending': { bg: '#fef3c7', color: '#f59e0b' },
                    'checked_in': { bg: '#dbeafe', color: '#3b82f6' },
                    'checked_out': { bg: '#d1fae5', color: '#10b981' },
                    'absent': { bg: '#fee2e2', color: '#dc2626' },
                    'excused': { bg: '#f3e8ff', color: '#8b5cf6' }
                };
                
                const config = statusColors[status?.toLowerCase()] || { bg: '#f3f4f6', color: '#6b7280' };
                const text = status ? status.charAt(0).toUpperCase() + status.slice(1).replace('_', ' ') : 'N/A';
                
                return `<span style="padding: 4px 8px; border-radius: 12px; font-size: 12px; font-weight: 600; background: ${config.bg}; color: ${config.color}; border: 1px solid ${config.color}20;">${text}</span>`;
            }
            
            let detailsHtml = `
                <div class="shift-details">
                    <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 24px;">
                        <div>
                            <h3 style="margin: 0; color: var(--primary-color);">Shift #${shift.id}</h3>
                            <p style="margin: 4px 0 0; color: var(--text-light);">${new Date(shift.shift_date).toLocaleDateString('en-US', { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' })}</p>
                        </div>
                        <div style="display: flex; gap: 8px;">
                            <span class="status-badge status-${shift.status?.toLowerCase() || 'scheduled'}">
                                ${shift.status?.toUpperCase() || 'SCHEDULED'}
                            </span>
                            <span class="shift-type-badge shift-type-${shift.shift_type?.toLowerCase() || 'full_day'}">
                                <i class='bx ${shiftTypeIcons[shift.shift_type?.toLowerCase()] || 'bx-calendar'}'></i>
                                ${(shift.shift_type || 'FULL_DAY').replace('_', ' ').toUpperCase()}
                            </span>
                        </div>
                    </div>
                    
                    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 20px; margin-bottom: 24px;">
                        <div style="background: var(--card-bg); border: 1px solid var(--border-color); border-radius: 12px; padding: 16px;">
                            <div style="font-size: 12px; color: var(--text-light); margin-bottom: 4px;">Start Time</div>
                            <div style="font-size: 16px; font-weight: 600;">${formatTime(shift.start_time)}</div>
                        </div>
                        <div style="background: var(--card-bg); border: 1px solid var(--border-color); border-radius: 12px; padding: 16px;">
                            <div style="font-size: 12px; color: var(--text-light); margin-bottom: 4px;">End Time</div>
                            <div style="font-size: 16px; font-weight: 600;">${formatTime(shift.end_time)}</div>
                        </div>
                        <div style="background: var(--card-bg); border: 1px solid var(--border-color); border-radius: 12px; padding: 16px;">
                            <div style="font-size: 12px; color: var(--text-light); margin-bottom: 4px;">Duration</div>
                            <div style="font-size: 16px; font-weight: 600;">${calculateDuration(shift.start_time, shift.end_time)}</div>
                        </div>
                    </div>`;
            
            // Assigned to information
            if (shift.assigned_to_name) {
                detailsHtml += `
                    <div style="background: rgba(59, 130, 246, 0.05); border-radius: 12px; padding: 16px; margin-bottom: 20px; border-left: 4px solid var(--info);">
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px;">
                            <div>
                                <div style="font-size: 12px; color: var(--text-light);">Assigned To</div>
                                <div style="font-size: 18px; font-weight: 600; color: var(--info);">${shift.assigned_to_name}</div>
                            </div>
                            <div style="background: var(--info); color: white; padding: 4px 12px; border-radius: 20px; font-size: 12px; font-weight: 600;">
                                ${shift.shift_for === 'volunteer' ? 'Volunteer' : 'Employee'}
                            </div>
                        </div>
                        ${shift.assigned_to_email ? `<div style="font-size: 14px; color: var(--text-light);">${shift.assigned_to_email}</div>` : ''}
                    </div>`;
            }
            
            if (shift.unit_name) {
                detailsHtml += `
                    <div style="background: rgba(16, 185, 129, 0.05); border-radius: 12px; padding: 16px; margin-bottom: 20px; border-left: 4px solid var(--success);">
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px;">
                            <div>
                                <div style="font-size: 12px; color: var(--text-light);">Assigned Unit</div>
                                <div style="font-size: 18px; font-weight: 600; color: var(--success);">${shift.unit_name}</div>
                            </div>
                            <div style="background: var(--success); color: white; padding: 4px 12px; border-radius: 20px; font-size: 12px; font-weight: 600;">
                                ${shift.unit_code || ''}
                            </div>
                        </div>
                        <div style="font-size: 14px; color: var(--text-light);">${shift.unit_type || ''} Unit</div>
                    </div>`;
            }
            
            detailsHtml += `
                    <div style="background: var(--card-bg); border: 1px solid var(--border-color); border-radius: 12px; padding: 16px; margin-bottom: 20px;">
                        <div style="font-size: 12px; color: var(--text-light); margin-bottom: 4px;">Location</div>
                        <div style="font-size: 16px; font-weight: 600;">${shift.location || 'Main Station'}</div>
                    </div>`;
            
            if (shift.notes) {
                detailsHtml += `
                    <div style="background: var(--card-bg); border: 1px solid var(--border-color); border-radius: 12px; padding: 16px; margin-bottom: 20px;">
                        <div style="font-size: 12px; color: var(--text-light); margin-bottom: 4px;">Shift Notes</div>
                        <div style="font-size: 14px; line-height: 1.6;">${shift.notes}</div>
                    </div>`;
            }
            
            // Confirmation Status
            detailsHtml += `
                    <div style="background: var(--card-bg); border: 1px solid var(--border-color); border-radius: 12px; padding: 16px; margin-bottom: 20px;">
                        <div style="font-size: 12px; color: var(--text-light); margin-bottom: 4px;">Confirmation Status</div>
                        <div style="font-size: 16px; font-weight: 600;">
                            ${getConfirmationBadgeHTML(shift.confirmation_status)}
                        </div>
                    </div>`;
            
            // Duty Assignment Section
            if (shift.duty_type) {
                detailsHtml += `
                    <div style="background: linear-gradient(135deg, rgba(139, 92, 246, 0.05), rgba(139, 92, 246, 0.1)); border-radius: 12px; padding: 20px; margin-bottom: 20px; border-left: 4px solid var(--purple);">
                        <h4 style="margin: 0 0 16px 0; color: var(--purple); display: flex; align-items: center; gap: 8px;">
                            <i class='bx bxs-briefcase'></i>
                            Duty Assignment Details
                        </h4>
                        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 16px; margin-bottom: 16px;">
                            <div style="background: var(--card-bg); border: 1px solid var(--border-color); border-radius: 8px; padding: 12px;">
                                <div style="font-size: 11px; color: var(--text-light); text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 4px;">Duty Type</div>
                                <div style="font-size: 14px; font-weight: 600;">${shift.duty_type.replace('_', ' ').toUpperCase()}</div>
                            </div>
                            <div style="background: var(--card-bg); border: 1px solid var(--border-color); border-radius: 8px; padding: 12px;">
                                <div style="font-size: 11px; color: var(--text-light); text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 4px;">Priority</div>
                                <div>
                                    <span style="padding: 4px 8px; border-radius: 6px; font-size: 11px; font-weight: 600; background: ${shift.priority === 'primary' ? '#dc2626' : shift.priority === 'secondary' ? '#f59e0b' : '#3b82f6'}; color: white;">
                                        ${(shift.priority || 'support').toUpperCase()}
                                    </span>
                                </div>
                            </div>
                        </div>
                        
                        <div style="background: var(--card-bg); border: 1px solid var(--border-color); border-radius: 8px; padding: 12px; margin-bottom: 16px;">
                            <div style="font-size: 11px; color: var(--text-light); text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 4px;">Duty Description</div>
                            <div style="font-size: 14px; line-height: 1.6;">${shift.duty_description || ''}</div>
                        </div>`;
                
                if (shift.required_equipment) {
                    detailsHtml += `
                        <div style="background: var(--card-bg); border: 1px solid var(--border-color); border-radius: 8px; padding: 12px; margin-bottom: 16px;">
                            <div style="font-size: 11px; color: var(--text-light); text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 8px;">Required Equipment</div>
                            <div style="display: flex; flex-wrap: wrap; gap: 6px;">`;
                    
                    const equipmentList = shift.required_equipment.split(',').map(item => item.trim());
                    equipmentList.forEach(equipment => {
                        if (equipment) {
                            detailsHtml += `<span style="padding: 4px 8px; border-radius: 6px; font-size: 11px; background: rgba(59, 130, 246, 0.1); color: var(--info);">${equipment}</span>`;
                        }
                    });
                    
                    detailsHtml += `</div></div>`;
                }
                
                if (shift.required_training) {
                    detailsHtml += `
                        <div style="background: var(--card-bg); border: 1px solid var(--border-color); border-radius: 8px; padding: 12px;">
                            <div style="font-size: 11px; color: var(--text-light); text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 8px;">Required Training</div>
                            <div style="display: flex; flex-wrap: wrap; gap: 6px;">`;
                    
                    const trainingList = shift.required_training.split(',').map(item => item.trim());
                    trainingList.forEach(training => {
                        if (training) {
                            detailsHtml += `<span style="padding: 4px 8px; border-radius: 6px; font-size: 11px; background: rgba(16, 185, 129, 0.1); color: var(--success);">${training}</span>`;
                        }
                    });
                    
                    detailsHtml += `</div></div>`;
                }
                
                detailsHtml += `</div>`;
            }
            
            // Attendance Information
            if (shift.check_in_time || shift.attendance_status !== 'pending') {
                detailsHtml += `
                    <div style="background: linear-gradient(135deg, rgba(16, 185, 129, 0.05), rgba(16, 185, 129, 0.1)); border-radius: 12px; padding: 20px; margin-bottom: 20px; border-left: 4px solid var(--success);">
                        <h4 style="margin: 0 0 16px 0; color: var(--success); display: flex; align-items: center; gap: 8px;">
                            <i class='bx bxs-log-in'></i>
                            Attendance Information
                        </h4>
                        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 16px;">
                            <div style="background: var(--card-bg); border: 1px solid var(--border-color); border-radius: 8px; padding: 12px;">
                                <div style="font-size: 11px; color: var(--text-light); text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 4px;">Attendance Status</div>
                                <div style="font-size: 14px; font-weight: 600;">
                                    ${getAttendanceBadgeHTML(shift.attendance_status)}
                                </div>
                            </div>`;
                
                if (shift.check_in_time) {
                    detailsHtml += `
                            <div style="background: var(--card-bg); border: 1px solid var(--border-color); border-radius: 8px; padding: 12px;">
                                <div style="font-size: 11px; color: var(--text-light); text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 4px;">Check-in Time</div>
                                <div style="font-size: 14px; font-weight: 600;">${new Date(shift.check_in_time).toLocaleString()}</div>
                            </div>`;
                }
                
                if (shift.check_out_time) {
                    detailsHtml += `
                            <div style="background: var(--card-bg); border: 1px solid var(--border-color); border-radius: 8px; padding: 12px;">
                                <div style="font-size: 11px; color: var(--text-light); text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 4px;">Check-out Time</div>
                                <div style="font-size: 14px; font-weight: 600;">${new Date(shift.check_out_time).toLocaleString()}</div>
                            </div>`;
                }
                
                if (shift.attendance_notes) {
                    detailsHtml += `
                            <div style="background: var(--card-bg); border: 1px solid var(--border-color); border-radius: 8px; padding: 12px; grid-column: 1 / -1;">
                                <div style="font-size: 11px; color: var(--text-light); text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 4px;">Attendance Notes</div>
                                <div style="font-size: 14px; line-height: 1.6;">${shift.attendance_notes}</div>
                            </div>`;
                }
                
                detailsHtml += `</div></div>`;
            }
            
            // System Information
            detailsHtml += `
                    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 20px;">
                        <div style="background: var(--card-bg); border: 1px solid var(--border-color); border-radius: 12px; padding: 16px;">
                            <div style="font-size: 12px; color: var(--text-light); margin-bottom: 4px;">Created By</div>
                            <div style="font-size: 14px; font-weight: 600;">${shift.created_by_name || 'System'}</div>
                        </div>
                        <div style="background: var(--card-bg); border: 1px solid var(--border-color); border-radius: 12px; padding: 16px;">
                            <div style="font-size: 12px; color: var(--text-light); margin-bottom: 4px;">Created At</div>
                            <div style="font-size: 14px; font-weight: 600;">${new Date(shift.created_at).toLocaleString()}</div>
                        </div>
                        <div style="background: var(--card-bg); border: 1px solid var(--border-color); border-radius: 12px; padding: 16px;">
                            <div style="font-size: 12px; color: var(--text-light); margin-bottom: 4px;">Last Updated</div>
                            <div style="font-size: 14px; font-weight: 600;">${new Date(shift.updated_at).toLocaleString()}</div>
                        </div>
                    </div>
                </div>`;
            
            detailsContent.innerHTML = detailsHtml;
        }
        
        function editShift(shiftId) {
            const editModal = document.getElementById('edit-modal');
            const editShiftId = document.getElementById('edit-shift-id');
            
            editShiftId.value = shiftId;
            
            // Fetch current shift data
            fetch(`get_shift_details.php?id=${shiftId}&admin=true`)
                .then(response => {
                    const contentType = response.headers.get("content-type");
                    if (contentType && contentType.includes("application/json")) {
                        return response.json();
                    } else {
                        return response.text().then(text => {
                            console.error("Non-JSON response:", text.substring(0, 200));
                            throw new Error("Server returned non-JSON response");
                        });
                    }
                })
                .then(data => {
                    if (data.success) {
                        const shift = data.shift;
                        document.getElementById('edit_status').value = shift.status || 'scheduled';
                        document.getElementById('edit_confirmation_status').value = shift.confirmation_status || 'pending';
                        document.getElementById('edit_notes').value = shift.notes || '';
                    }
                })
                .catch(error => {
                    showNotification('error', 'Failed to load shift data: ' + error.message);
                });
            
            // Open modal
            editModal.classList.add('active');
        }
        
        function updateAttendance(shiftId) {
            const attendanceModal = document.getElementById('attendance-modal');
            const attendanceShiftId = document.getElementById('attendance-shift-id');
            
            attendanceShiftId.value = shiftId;
            
            // Fetch current attendance data
            fetch(`get_shift_details.php?id=${shiftId}&admin=true`)
                .then(response => {
                    const contentType = response.headers.get("content-type");
                    if (contentType && contentType.includes("application/json")) {
                        return response.json();
                    } else {
                        return response.text().then(text => {
                            console.error("Non-JSON response:", text.substring(0, 200));
                            throw new Error("Server returned non-JSON response");
                        });
                    }
                })
                .then(data => {
                    if (data.success) {
                        const shift = data.shift;
                        document.getElementById('attendance_status').value = shift.attendance_status || 'pending';
                        
                        if (shift.check_in_time) {
                            const checkIn = new Date(shift.check_in_time);
                            document.getElementById('attendance_check_in').value = checkIn.toISOString().slice(0, 16);
                        } else {
                            document.getElementById('attendance_check_in').value = '';
                        }
                        
                        if (shift.check_out_time) {
                            const checkOut = new Date(shift.check_out_time);
                            document.getElementById('attendance_check_out').value = checkOut.toISOString().slice(0, 16);
                        } else {
                            document.getElementById('attendance_check_out').value = '';
                        }
                        
                        document.getElementById('attendance_notes').value = shift.attendance_notes || '';
                    }
                })
                .catch(error => {
                    showNotification('error', 'Failed to load attendance data: ' + error.message);
                });
            
            // Open modal
            attendanceModal.classList.add('active');
        }
        
        function submitEditShift() {
            const form = document.getElementById('edit-shift-form');
            const formData = new FormData(form);
            
            fetch('update_shift.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showNotification('success', data.message || 'Shift updated successfully!');
                    closeEditModal();
                    setTimeout(() => {
                        location.reload();
                    }, 1500);
                } else {
                    showNotification('error', data.message || 'Failed to update shift');
                }
            })
            .catch(error => {
                showNotification('error', 'Error: ' + error.message);
            });
        }
        
        function submitAttendanceUpdate() {
            const form = document.getElementById('update-attendance-form');
            const formData = new FormData(form);
            
            fetch('update_attendance.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showNotification('success', data.message || 'Attendance updated successfully!');
                    closeAttendanceModal();
                    setTimeout(() => {
                        location.reload();
                    }, 1500);
                } else {
                    showNotification('error', data.message || 'Failed to update attendance');
                }
            })
            .catch(error => {
                showNotification('error', 'Error: ' + error.message);
            });
        }
        
        function cancelShift(shiftId) {
            if (confirm('Are you sure you want to cancel this shift? This action cannot be undone.')) {
                fetch('cancel_shift.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({ shift_id: shiftId })
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        showNotification('success', data.message || 'Shift cancelled successfully!');
                        setTimeout(() => {
                            location.reload();
                        }, 1500);
                    } else {
                        showNotification('error', data.message || 'Failed to cancel shift');
                    }
                })
                .catch(error => {
                    showNotification('error', 'Error: ' + error.message);
                });
            }
        }
        
        function showNotification(type, message) {
            // Remove existing notifications
            const existingNotification = document.querySelector('.notification');
            if (existingNotification) {
                existingNotification.remove();
            }
            
            const notification = document.createElement('div');
            notification.className = `notification notification-${type}`;
            notification.innerHTML = `
                <div style="display: flex; align-items: center; gap: 10px;">
                    <i class='bx ${type === 'success' ? 'bx-check-circle' : 'bx-error-circle'}'></i>
                    <span>${message}</span>
                </div>
                <button onclick="this.parentElement.remove()" style="background: none; border: none; color: inherit; cursor: pointer;">
                    <i class='bx bx-x'></i>
                </button>
            `;
            
            // Add styles
            notification.style.cssText = `
                position: fixed;
                top: 20px;
                right: 20px;
                padding: 16px 20px;
                border-radius: 12px;
                display: flex;
                align-items: center;
                justify-content: space-between;
                gap: 15px;
                max-width: 400px;
                z-index: 9999;
                animation: slideIn 0.3s ease;
                box-shadow: 0 4px 20px rgba(0, 0, 0, 0.15);
                backdrop-filter: blur(10px);
            `;
            
            if (type === 'success') {
                notification.style.background = 'linear-gradient(135deg, rgba(16, 185, 129, 0.9), rgba(16, 185, 129, 0.8))';
                notification.style.color = 'white';
                notification.style.border = '1px solid rgba(16, 185, 129, 0.3)';
            } else {
                notification.style.background = 'linear-gradient(135deg, rgba(220, 38, 38, 0.9), rgba(220, 38, 38, 0.8))';
                notification.style.color = 'white';
                notification.style.border = '1px solid rgba(220, 38, 38, 0.3)';
            }
            
            document.body.appendChild(notification);
            
            // Auto-remove after 5 seconds
            setTimeout(() => {
                if (notification.parentNode) {
                    notification.style.animation = 'slideOut 0.3s ease';
                    setTimeout(() => {
                        if (notification.parentNode) {
                            notification.remove();
                        }
                    }, 300);
                }
            }, 5000);
            
            // Add keyframes
            if (!document.querySelector('#notification-styles')) {
                const style = document.createElement('style');
                style.id = 'notification-styles';
                style.textContent = `
                    @keyframes slideIn {
                        from { transform: translateX(100%); opacity: 0; }
                        to { transform: translateX(0); opacity: 1; }
                    }
                    @keyframes slideOut {
                        from { transform: translateX(0); opacity: 1; }
                        to { transform: translateX(100%); opacity: 0; }
                    }
                `;
                document.head.appendChild(style);
            }
        }
        
        function closeDetailsModal() {
            document.getElementById('details-modal').classList.remove('active');
        }
        
        function closeEditModal() {
            document.getElementById('edit-modal').classList.remove('active');
        }
        
        function closeAttendanceModal() {
            document.getElementById('attendance-modal').classList.remove('active');
        }
        
        function formatTime(timeString) {
            if (!timeString) return 'N/A';
            const time = new Date('1970-01-01T' + timeString);
            return time.toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit', hour12: true });
        }
        
        function toggleSubmenu(id) {
            const submenu = document.getElementById(id);
            const arrow = submenu.previousElementSibling.querySelector('.dropdown-arrow');
            
            submenu.classList.toggle('active');
            arrow.classList.toggle('rotated');
        }
        
        function updateTime() {
            const now = new Date();
            const utc = now.getTime() + (now.getTimezoneOffset() * 60000);
            const gmt8 = new Date(utc + (8 * 3600000));
            
            const hours = gmt8.getHours().toString().padStart(2, '0');
            const minutes = gmt8.getMinutes().toString().padStart(2, '0');
            const seconds = gmt8.getSeconds().toString().padStart(2, '0');
            
            const timeString = `${hours}:${minutes}:${seconds} UTC+8`;
            document.getElementById('current-time').textContent = timeString;
        }
        
        function handleStatCardClick(type) {
            switch(type) {
                case 'total':
                    filterByStatus('all');
                    break;
                case 'upcoming':
                    filterByStatus('scheduled');
                    break;
                case 'completed':
                    filterByStatus('completed');
                    break;
                case 'cancelled':
                    filterByStatus('cancelled');
                    break;
                case 'today':
                    filterByDate('today');
                    break;
                case 'with_duty':
                    filterByDuty();
                    break;
                case 'pending_confirmation':
                    showPendingConfirmations();
                    break;
                case 'checked_in':
                    showCheckedIn();
                    break;
            }
        }
        
        function filterByStatus(status) {
            const url = new URL(window.location.href);
            url.searchParams.set('status', status);
            window.location.href = url.toString();
        }
        
        function filterByDate(date) {
            const url = new URL(window.location.href);
            url.searchParams.set('date', date);
            window.location.href = url.toString();
        }
        
        function filterByDuty() {
            const url = new URL(window.location.href);
            url.searchParams.set('duty_type', 'logistics_support');
            window.location.href = url.toString();
        }
        
        function showPendingConfirmations() {
            const url = new URL(window.location.href);
            url.searchParams.set('status', 'scheduled');
            url.searchParams.delete('date');
            url.searchParams.delete('search');
            url.searchParams.delete('duty_type');
            url.searchParams.delete('unit');
            window.location.href = url.toString();
        }
        
        function showCheckedIn() {
            const url = new URL(window.location.href);
            url.searchParams.set('status', 'scheduled');
            url.searchParams.delete('date');
            url.searchParams.delete('search');
            url.searchParams.delete('duty_type');
            url.searchParams.delete('unit');
            window.location.href = url.toString();
        }
        
        // Handle window resize for responsive layout
        window.addEventListener('resize', addDataLabels);
    </script>
</body>
</html>