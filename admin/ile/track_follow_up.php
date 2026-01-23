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
$filter_type = isset($_GET['type']) ? $_GET['type'] : 'all';
$filter_date = isset($_GET['date']) ? $_GET['date'] : '';
$search_query = isset($_GET['search']) ? $_GET['search'] : '';
$filter_barangay = isset($_GET['barangay']) ? $_GET['barangay'] : '';
$filter_establishment = isset($_GET['establishment']) ? $_GET['establishment'] : '';
$filter_assigned_to = isset($_GET['assigned_to']) ? $_GET['assigned_to'] : '';

// Handle status update
if (isset($_POST['update_follow_up_status'])) {
    $follow_up_id = (int)$_POST['follow_up_id'];
    $new_status = $_POST['status'];
    $outcome = $_POST['outcome'];
    $compliance_verified = isset($_POST['compliance_verified']) ? 1 : 0;
    
    $query = "UPDATE inspection_follow_ups 
              SET status = ?, outcome = ?, compliance_verified = ?,
                  verified_by = ?, verified_at = NOW(),
                  actual_date = CASE WHEN ? IN ('completed', 'cancelled') THEN NOW() ELSE actual_date END
              WHERE id = ?";
    $stmt = $pdo->prepare($query);
    $stmt->execute([$new_status, $outcome, $compliance_verified, $user_id, $new_status, $follow_up_id]);
    
    // If compliance is verified, update the inspection report
    if ($compliance_verified) {
        // Get the inspection_id from follow_up
        $query = "SELECT inspection_id FROM inspection_follow_ups WHERE id = ?";
        $stmt = $pdo->prepare($query);
        $stmt->execute([$follow_up_id]);
        $follow_up = $stmt->fetch();
        
        if ($follow_up) {
            // Update the violation status to rectified
            $query = "UPDATE inspection_violations 
                      SET status = 'rectified', rectified_at = NOW()
                      WHERE inspection_id = ? AND status = 'pending'";
            $stmt = $pdo->prepare($query);
            $stmt->execute([$follow_up['inspection_id']]);
        }
    }
    
    header("Location: track_follow_up.php?success=Follow-up+updated+successfully");
    exit();
}

// Handle assign action
if (isset($_POST['assign_follow_up'])) {
    $follow_up_id = (int)$_POST['follow_up_id'];
    $assigned_to = (int)$_POST['assigned_to'];
    
    $query = "UPDATE inspection_follow_ups 
              SET assigned_to = ?, status = 'scheduled'
              WHERE id = ?";
    $stmt = $pdo->prepare($query);
    $stmt->execute([$assigned_to, $follow_up_id]);
    
    header("Location: track_follow_up.php?success=Follow-up+assigned+successfully");
    exit();
}

// Handle reschedule action
if (isset($_POST['reschedule_follow_up'])) {
    $follow_up_id = (int)$_POST['follow_up_id'];
    $scheduled_date = $_POST['scheduled_date'];
    
    $query = "UPDATE inspection_follow_ups 
              SET scheduled_date = ?, status = 'scheduled'
              WHERE id = ?";
    $stmt = $pdo->prepare($query);
    $stmt->execute([$scheduled_date, $follow_up_id]);
    
    header("Location: track_follow_up.php?success=Follow-up+rescheduled+successfully");
    exit();
}

// Get all follow-ups
function getFollowUps($pdo, $filter_status = 'all', $filter_type = 'all', $filter_date = '', 
                     $search_query = '', $filter_barangay = '', $filter_establishment = '', 
                     $filter_assigned_to = '') {
    $sql = "SELECT 
                fu.*,
                ie.establishment_name,
                ie.establishment_type,
                ie.barangay,
                ie.address,
                ie.owner_name,
                ir.report_number,
                ir.inspection_date,
                CONCAT(assigned.first_name, ' ', assigned.last_name) as assigned_to_name,
                CONCAT(assigned.first_name, ' ', assigned.last_name) as assigned_name,
                DATEDIFF(fu.scheduled_date, CURDATE()) as days_until_due,
                CASE 
                    WHEN fu.status = 'overdue' THEN 'overdue'
                    WHEN fu.status = 'pending' AND fu.scheduled_date < CURDATE() THEN 'overdue'
                    WHEN fu.status IN ('pending', 'scheduled') AND fu.scheduled_date <= DATE_ADD(CURDATE(), INTERVAL 3 DAY) THEN 'due_soon'
                    ELSE fu.status
                END as priority_status,
                (SELECT COUNT(*) FROM inspection_violations iv 
                 WHERE iv.inspection_id = fu.inspection_id AND iv.status = 'pending') as pending_violations
            FROM inspection_follow_ups fu
            LEFT JOIN inspection_reports ir ON fu.inspection_id = ir.id
            LEFT JOIN inspection_establishments ie ON fu.establishment_id = ie.id
            LEFT JOIN users assigned ON fu.assigned_to = assigned.id
            WHERE 1=1";
    
    $params = [];
    
    // Apply status filter
    if ($filter_status !== 'all') {
        if ($filter_status === 'overdue') {
            $sql .= " AND (fu.status = 'overdue' OR (fu.status IN ('pending', 'scheduled') AND fu.scheduled_date < CURDATE()))";
        } elseif ($filter_status === 'due_soon') {
            $sql .= " AND fu.status IN ('pending', 'scheduled') AND fu.scheduled_date <= DATE_ADD(CURDATE(), INTERVAL 3 DAY) AND fu.scheduled_date >= CURDATE()";
        } else {
            $sql .= " AND fu.status = ?";
            $params[] = $filter_status;
        }
    }
    
    // Apply type filter
    if ($filter_type !== 'all') {
        $sql .= " AND fu.follow_up_type = ?";
        $params[] = $filter_type;
    }
    
    // Apply date filter
    if (!empty($filter_date)) {
        if ($filter_date === 'today') {
            $sql .= " AND DATE(fu.scheduled_date) = CURDATE()";
        } elseif ($filter_date === 'tomorrow') {
            $sql .= " AND DATE(fu.scheduled_date) = DATE_ADD(CURDATE(), INTERVAL 1 DAY)";
        } elseif ($filter_date === 'week') {
            $sql .= " AND fu.scheduled_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)";
        } elseif ($filter_date === 'overdue') {
            $sql .= " AND fu.scheduled_date < CURDATE() AND fu.status IN ('pending', 'scheduled')";
        }
    }
    
    // Apply barangay filter
    if (!empty($filter_barangay)) {
        $sql .= " AND ie.barangay LIKE ?";
        $params[] = "%$filter_barangay%";
    }
    
    // Apply establishment filter
    if (!empty($filter_establishment)) {
        $sql .= " AND fu.establishment_id = ?";
        $params[] = $filter_establishment;
    }
    
    // Apply assigned to filter
    if (!empty($filter_assigned_to)) {
        if ($filter_assigned_to === 'unassigned') {
            $sql .= " AND fu.assigned_to IS NULL";
        } else {
            $sql .= " AND fu.assigned_to = ?";
            $params[] = $filter_assigned_to;
        }
    }
    
    // Apply search query
    if (!empty($search_query)) {
        $sql .= " AND (
                    ie.establishment_name LIKE ? OR 
                    ie.owner_name LIKE ? OR 
                    ir.report_number LIKE ? OR
                    fu.notes LIKE ?
                )";
        $search_param = "%$search_query%";
        $params = array_merge($params, [
            $search_param, $search_param, $search_param, $search_param
        ]);
    }
    
    $sql .= " ORDER BY 
                CASE priority_status
                    WHEN 'overdue' THEN 1
                    WHEN 'due_soon' THEN 2
                    WHEN 'pending' THEN 3
                    WHEN 'scheduled' THEN 4
                    ELSE 5
                END,
                fu.scheduled_date ASC,
                fu.status ASC";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Get follow-up statistics
function getFollowUpStats($pdo) {
    $sql = "SELECT 
                COUNT(*) as total,
                SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending,
                SUM(CASE WHEN status = 'scheduled' THEN 1 ELSE 0 END) as scheduled,
                SUM(CASE WHEN status = 'in_progress' THEN 1 ELSE 0 END) as in_progress,
                SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed,
                SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END) as cancelled,
                SUM(CASE WHEN status IN ('pending', 'scheduled') AND scheduled_date < CURDATE() THEN 1 ELSE 0 END) as overdue,
                SUM(CASE WHEN status IN ('pending', 'scheduled') AND scheduled_date <= DATE_ADD(CURDATE(), INTERVAL 3 DAY) AND scheduled_date >= CURDATE() THEN 1 ELSE 0 END) as due_soon,
                SUM(CASE WHEN assigned_to IS NULL THEN 1 ELSE 0 END) as unassigned,
                SUM(CASE WHEN follow_up_type = 'compliance_check' THEN 1 ELSE 0 END) as compliance_checks,
                SUM(CASE WHEN follow_up_type = 'violation_rectification' THEN 1 ELSE 0 END) as violation_rectifications,
                SUM(CASE WHEN follow_up_type = 're_inspection' THEN 1 ELSE 0 END) as reinspections,
                SUM(CASE WHEN follow_up_type = 'training' THEN 1 ELSE 0 END) as trainings
            FROM inspection_follow_ups";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute();
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    
    $stats = [
        'total' => 0,
        'pending' => 0,
        'scheduled' => 0,
        'in_progress' => 0,
        'completed' => 0,
        'cancelled' => 0,
        'overdue' => 0,
        'due_soon' => 0,
        'unassigned' => 0,
        'compliance_checks' => 0,
        'violation_rectifications' => 0,
        'reinspections' => 0,
        'trainings' => 0
    ];
    
    if ($result) {
        $stats = array_merge($stats, $result);
    }
    
    return $stats;
}

// Get all barangays for filtering
function getBarangays($pdo) {
    $sql = "SELECT DISTINCT barangay FROM inspection_establishments WHERE barangay IS NOT NULL AND barangay != '' ORDER BY barangay";
    $stmt = $pdo->prepare($sql);
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_COLUMN, 0);
}

// Get all establishments for filtering
function getEstablishments($pdo) {
    $sql = "SELECT id, establishment_name FROM inspection_establishments ORDER BY establishment_name";
    $stmt = $pdo->prepare($sql);
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Get all inspectors for assignment
function getInspectors($pdo) {
    $sql = "SELECT id, CONCAT(first_name, ' ', last_name) as full_name 
            FROM users 
            WHERE role IN ('ADMIN', 'EMPLOYEE') 
            ORDER BY first_name, last_name";
    $stmt = $pdo->prepare($sql);
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Get follow-up types
$follow_up_types = [
    'compliance_check' => 'Compliance Check',
    'violation_rectification' => 'Violation Rectification',
    'training' => 'Training Session',
    're_inspection' => 'Re-inspection',
    'other' => 'Other'
];

// Get status options
$status_options = [
    'pending' => 'Pending',
    'scheduled' => 'Scheduled',
    'in_progress' => 'In Progress',
    'completed' => 'Completed',
    'cancelled' => 'Cancelled',
    'overdue' => 'Overdue'
];

// Get filter status options
$filter_status_options = [
    'all' => 'All Follow-ups',
    'pending' => 'Pending',
    'scheduled' => 'Scheduled',
    'in_progress' => 'In Progress',
    'completed' => 'Completed',
    'overdue' => 'Overdue',
    'due_soon' => 'Due Soon (≤3 days)'
];

// Get type filter options
$filter_type_options = [
    'all' => 'All Types',
    'compliance_check' => 'Compliance Check',
    'violation_rectification' => 'Violation Rectification',
    'training' => 'Training',
    're_inspection' => 'Re-inspection',
    'other' => 'Other'
];

// Get date filter options
$date_options = [
    '' => 'All Dates',
    'today' => 'Today',
    'tomorrow' => 'Tomorrow',
    'week' => 'Next 7 Days',
    'overdue' => 'Overdue'
];

// Get data for filters
$barangays = getBarangays($pdo);
$establishments = getEstablishments($pdo);
$inspectors = getInspectors($pdo);

// Get follow-ups based on filters
$follow_ups = getFollowUps($pdo, $filter_status, $filter_type, $filter_date, $search_query, 
                          $filter_barangay, $filter_establishment, $filter_assigned_to);
$stats = getFollowUpStats($pdo);

// Status colors
$status_colors = [
    'pending' => '#f59e0b',
    'scheduled' => '#3b82f6',
    'in_progress' => '#8b5cf6',
    'completed' => '#10b981',
    'cancelled' => '#6b7280',
    'overdue' => '#dc2626',
    'due_soon' => '#f59e0b'
];

// Priority colors
$priority_colors = [
    'overdue' => '#dc2626',
    'due_soon' => '#f59e0b',
    'normal' => '#3b82f6'
];

// Format date helper
function formatDate($date) {
    if (!$date) return 'N/A';
    return date('M j, Y', strtotime($date));
}

// Get status badge HTML
function getStatusBadge($status) {
    global $status_colors;
    $color = $status_colors[$status] ?? '#6b7280';
    $text = ucfirst(str_replace('_', ' ', $status));
    
    return <<<HTML
        <span class="status-badge" style="background: rgba(${hexToRgb($color)}, 0.1); color: {$color}; border-color: rgba(${hexToRgb($color)}, 0.3);">
            {$text}
        </span>
    HTML;
}

// Get priority badge HTML
function getPriorityBadge($priority, $days_until_due = 0) {
    global $priority_colors;
    $color = $priority_colors[$priority] ?? '#6b7280';
    $text = ucfirst(str_replace('_', ' ', $priority));
    
    if ($priority === 'due_soon' && $days_until_due > 0) {
        $text .= " ({$days_until_due} days)";
    } elseif ($priority === 'overdue' && $days_until_due < 0) {
        $text .= " (" . abs($days_until_due) . " days late)";
    }
    
    return <<<HTML
        <span class="priority-badge" style="background: rgba(${hexToRgb($color)}, 0.1); color: {$color}; border-color: rgba(${hexToRgb($color)}, 0.3);">
            <i class='bx bx-alarm'></i>
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

// Check for success message
$success_message = isset($_GET['success']) ? urldecode($_GET['success']) : '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Track Follow-Up - Inspection Management - Admin - FRSM</title>
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
            --orange: #f97316;
            --amber: #f59e0b;
            --lime: #84cc16;
            --emerald: #10b981;
            --cyan: #06b6d4;
            --light-blue: #0ea5e9;
            --violet: #8b5cf6;
            --fuchsia: #d946ef;
            --rose: #f43f5e;
            --warm-gray: #78716c;
            
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

        /* Stats Container */
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
        
        .stat-card[data-type="overdue"] .stat-icon-container {
            background: rgba(220, 38, 38, 0.1);
            color: var(--danger);
        }
        
        .stat-card[data-type="due_soon"] .stat-icon-container {
            background: rgba(245, 158, 11, 0.1);
            color: var(--warning);
        }
        
        .stat-card[data-type="pending"] .stat-icon-container {
            background: rgba(245, 158, 11, 0.1);
            color: var(--warning);
        }
        
        .stat-card[data-type="scheduled"] .stat-icon-container {
            background: rgba(59, 130, 246, 0.1);
            color: var(--info);
        }
        
        .stat-card[data-type="in_progress"] .stat-icon-container {
            background: rgba(139, 92, 246, 0.1);
            color: var(--purple);
        }
        
        .stat-card[data-type="completed"] .stat-icon-container {
            background: rgba(16, 185, 129, 0.1);
            color: var(--success);
        }
        
        .stat-card[data-type="unassigned"] .stat-icon-container {
            background: rgba(107, 114, 128, 0.1);
            color: var(--gray-500);
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

        /* Filter Tabs Container */
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
        .follow-ups-table-container {
            background: var(--card-bg);
            border-radius: 16px;
            overflow: hidden;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
        }

        .table-header {
            display: grid;
            grid-template-columns: 100px 200px 150px 120px 120px 120px 150px 200px;
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
            grid-template-columns: 100px 200px 150px 120px 120px 120px 150px 200px;
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
        
        .follow-up-type {
            font-weight: 600;
            color: var(--text-color);
            font-size: 14px;
        }
        
        .establishment-name {
            font-weight: 600;
            color: var(--text-color);
            font-size: 15px;
        }
        
        .establishment-info {
            font-size: 12px;
            color: var(--text-light);
        }
        
        /* Status Badge */
        .status-badge, .priority-badge {
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
        
        .priority-badge {
            font-size: 11px;
        }
        
        /* Enhanced Action Buttons */
        .action-buttons {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
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
        
        .update-button {
            background: linear-gradient(135deg, rgba(59, 130, 246, 0.1), rgba(59, 130, 246, 0.2));
            color: var(--info);
            border: 1px solid rgba(59, 130, 246, 0.3);
        }
        
        .update-button:hover {
            background: var(--info);
            color: white;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(59, 130, 246, 0.3);
        }
        
        .assign-button {
            background: linear-gradient(135deg, rgba(16, 185, 129, 0.1), rgba(16, 185, 129, 0.2));
            color: var(--success);
            border: 1px solid rgba(16, 185, 129, 0.3);
        }
        
        .assign-button:hover {
            background: var(--success);
            color: white;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(16, 185, 129, 0.3);
        }
        
        .view-button {
            background: linear-gradient(135deg, rgba(139, 92, 246, 0.1), rgba(139, 92, 246, 0.2));
            color: var(--purple);
            border: 1px solid rgba(139, 92, 246, 0.3);
        }
        
        .view-button:hover {
            background: var(--purple);
            color: white;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(139, 92, 246, 0.3);
        }
        
        .reschedule-button {
            background: linear-gradient(135deg, rgba(245, 158, 11, 0.1), rgba(245, 158, 11, 0.2));
            color: var(--warning);
            border: 1px solid rgba(245, 158, 11, 0.3);
        }
        
        .reschedule-button:hover {
            background: var(--warning);
            color: white;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(245, 158, 11, 0.3);
        }
        
        .complete-button {
            background: linear-gradient(135deg, rgba(16, 185, 129, 0.1), rgba(16, 185, 129, 0.2));
            color: var(--success);
            border: 1px solid rgba(16, 185, 129, 0.3);
        }
        
        .complete-button:hover {
            background: var(--success);
            color: white;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(16, 185, 129, 0.3);
        }
        
        .cancel-button {
            background: linear-gradient(135deg, rgba(107, 114, 128, 0.1), rgba(107, 114, 128, 0.2));
            color: var(--gray-500);
            border: 1px solid rgba(107, 114, 128, 0.3);
        }
        
        .cancel-button:hover {
            background: var(--gray-500);
            color: white;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(107, 114, 128, 0.3);
        }

        .no-follow-ups {
            text-align: center;
            padding: 60px 20px;
            color: var(--text-light);
            grid-column: 1 / -1;
        }
        
        .no-follow-ups-icon {
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
            max-width: 600px;
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

        .btn-success {
            background: linear-gradient(135deg, var(--success), #059669);
            color: white;
        }
        
        .btn-success:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(16, 185, 129, 0.3);
        }

        /* Responsive Design */
        @media (max-width: 1400px) {
            .table-header, .table-row {
                grid-template-columns: 90px 180px 140px 110px 110px 110px 140px 180px;
                gap: 12px;
                padding: 16px;
            }
        }

        @media (max-width: 1200px) {
            .table-header, .table-row {
                grid-template-columns: 80px 160px 130px 100px 100px 100px 130px 160px;
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

        .follow-ups-table-container {
            max-height: 600px;
            overflow-y: auto;
        }

        .follow-ups-table-container::-webkit-scrollbar {
            width: 6px;
        }
        
        .follow-ups-table-container::-webkit-scrollbar-track {
            background: var(--gray-100);
            border-radius: 3px;
        }
        
        .follow-ups-table-container::-webkit-scrollbar-thumb {
            background: var(--gray-400);
            border-radius: 3px;
        }
        
        .follow-ups-table-container::-webkit-scrollbar-thumb:hover {
            background: var(--gray-500);
        }
        
        .dark-mode .follow-ups-table-container::-webkit-scrollbar-track {
            background: var(--gray-800);
        }
        
        .dark-mode .follow-ups-table-container::-webkit-scrollbar-thumb {
            background: var(--gray-600);
        }
        
        .dark-mode .follow-ups-table-container::-webkit-scrollbar-thumb:hover {
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

        /* Success message */
        .success-message {
            background: linear-gradient(135deg, rgba(16, 185, 129, 0.1), rgba(16, 185, 129, 0.2));
            border: 1px solid rgba(16, 185, 129, 0.3);
            border-radius: 12px;
            padding: 16px 20px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            animation: slideIn 0.3s ease;
        }
        
        .success-message-content {
            display: flex;
            align-items: center;
            gap: 12px;
            color: var(--success);
        }
        
        .close-message {
            background: none;
            border: none;
            color: var(--success);
            cursor: pointer;
            font-size: 18px;
            width: 30px;
            height: 30px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 50%;
            transition: all 0.3s ease;
        }
        
        .close-message:hover {
            background: rgba(16, 185, 129, 0.1);
        }
        
        @keyframes slideIn {
            from {
                transform: translateY(-20px);
                opacity: 0;
            }
            to {
                transform: translateY(0);
                opacity: 1;
            }
        }

        /* Follow-up type badge */
        .follow-up-type-badge {
            padding: 6px 10px;
            border-radius: 6px;
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            border: 1px solid;
            width: fit-content;
        }
        
        .follow-up-type-compliance_check {
            background: rgba(16, 185, 129, 0.1);
            color: var(--success);
            border-color: rgba(16, 185, 129, 0.3);
        }
        
        .follow-up-type-violation_rectification {
            background: rgba(220, 38, 38, 0.1);
            color: var(--danger);
            border-color: rgba(220, 38, 38, 0.3);
        }
        
        .follow-up-type-training {
            background: rgba(139, 92, 246, 0.1);
            color: var(--purple);
            border-color: rgba(139, 92, 246, 0.3);
        }
        
        .follow-up-type-re_inspection {
            background: rgba(59, 130, 246, 0.1);
            color: var(--info);
            border-color: rgba(59, 130, 246, 0.3);
        }
        
        .follow-up-type-other {
            background: rgba(107, 114, 128, 0.1);
            color: var(--gray-500);
            border-color: rgba(107, 114, 128, 0.3);
        }

        /* Calendar icon */
        .calendar-info {
            display: flex;
            align-items: center;
            gap: 6px;
            font-size: 12px;
            color: var(--text-light);
        }
        
        .calendar-info i {
            color: var(--primary-color);
        }

        /* Violation count */
        .violation-count {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 4px 8px;
            background: rgba(220, 38, 38, 0.1);
            color: var(--danger);
            border-radius: 12px;
            font-size: 11px;
            font-weight: 600;
        }
        
        .violation-count i {
            font-size: 10px;
        }

        /* Notes preview */
        .notes-preview {
            font-size: 12px;
            color: var(--text-light);
            overflow: hidden;
            text-overflow: ellipsis;
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
            line-height: 1.4;
        }
    </style>
</head>
<body>
    <!-- Update Status Modal -->
    <div class="modal-overlay" id="update-modal">
        <div class="modal">
            <div class="modal-header">
                <h2 class="modal-title">Update Follow-up Status</h2>
                <button class="modal-close" id="update-modal-close">&times;</button>
            </div>
            <div class="modal-body">
                <form id="update-follow-up-form">
                    <input type="hidden" id="update-follow-up-id" name="follow_up_id">
                    
                    <div class="form-group">
                        <label class="form-label" for="status">Status</label>
                        <select class="form-select" id="status" name="status" required>
                            <option value="">Select Status</option>
                            <?php foreach ($status_options as $value => $label): ?>
                                <option value="<?php echo $value; ?>"><?php echo $label; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label" for="outcome">Outcome / Notes</label>
                        <textarea class="form-textarea" id="outcome" name="outcome" placeholder="Enter outcome or notes..."></textarea>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label" style="display: flex; align-items: center; gap: 8px;">
                            <input type="checkbox" id="compliance_verified" name="compliance_verified" value="1">
                            Compliance Verified
                        </label>
                        <small style="color: var(--text-light); font-size: 12px;">Check if all violations have been rectified</small>
                    </div>
                    
                    <div class="modal-actions">
                        <button type="button" class="btn btn-secondary" id="cancel-update">Cancel</button>
                        <button type="submit" class="btn btn-primary" name="update_follow_up_status">Update Status</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Assign Modal -->
    <div class="modal-overlay" id="assign-modal">
        <div class="modal">
            <div class="modal-header">
                <h2 class="modal-title">Assign Follow-up</h2>
                <button class="modal-close" id="assign-modal-close">&times;</button>
            </div>
            <div class="modal-body">
                <form id="assign-follow-up-form">
                    <input type="hidden" id="assign-follow-up-id" name="follow_up_id">
                    
                    <div class="form-group">
                        <label class="form-label" for="assigned_to">Assign To Inspector</label>
                        <select class="form-select" id="assigned_to" name="assigned_to" required>
                            <option value="">Select Inspector</option>
                            <?php foreach ($inspectors as $inspector): ?>
                                <option value="<?php echo $inspector['id']; ?>"><?php echo htmlspecialchars($inspector['full_name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="modal-actions">
                        <button type="button" class="btn btn-secondary" id="cancel-assign">Cancel</button>
                        <button type="submit" class="btn btn-success" name="assign_follow_up">Assign Follow-up</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Reschedule Modal -->
    <div class="modal-overlay" id="reschedule-modal">
        <div class="modal">
            <div class="modal-header">
                <h2 class="modal-title">Reschedule Follow-up</h2>
                <button class="modal-close" id="reschedule-modal-close">&times;</button>
            </div>
            <div class="modal-body">
                <form id="reschedule-follow-up-form">
                    <input type="hidden" id="reschedule-follow-up-id" name="follow_up_id">
                    
                    <div class="form-group">
                        <label class="form-label" for="scheduled_date">New Scheduled Date</label>
                        <input type="date" class="form-input" id="scheduled_date" name="scheduled_date" required min="<?php echo date('Y-m-d'); ?>">
                    </div>
                    
                    <div class="modal-actions">
                        <button type="button" class="btn btn-secondary" id="cancel-reschedule">Cancel</button>
                        <button type="submit" class="btn btn-primary" name="reschedule_follow_up">Reschedule</button>
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
                    <div class="menu-item" onclick="toggleSubmenu('schedule-management')">
                        <div class="icon-box icon-bg-purple">
                            <i class='bx bxs-calendar icon-purple'></i>
                        </div>
                        <span class="font-medium">Schedule Management</span>
                        <svg class="dropdown-arrow menu-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                        </svg>
                    </div>
                    <div id="schedule-management" class="submenu">
                       <a href="view_shifts.php" class="submenu-item">View Shifts</a>
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
                        <svg class="dropdown-arrow menu-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
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
                    <div class="menu-item active" onclick="toggleSubmenu('inspection-management')">
                        <div class="icon-box icon-bg-cyan">
                            <i class='bx bxs-check-shield icon-cyan'></i>
                        </div>
                        <span class="font-medium">Inspection Management</span>
                        <svg class="dropdown-arrow menu-icon rotated" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                        </svg>
                    </div>
                    <div id="inspection-management" class="submenu active">
                        <a href="approve_reports.php" class="submenu-item">Approve Reports</a>
                        <a href="review_violations.php" class="submenu-item">Review Violations</a>
                        <a href="issue_certificates.php" class="submenu-item">Issue Certificates</a>
                        <a href="track_follow_up.php" class="submenu-item active">Track Follow-Up</a>
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
                        <a href="../pir/review_summaries.php" class="submenu-item">Review Summaries</a>
                       
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
                            <input type="text" placeholder="Search follow-ups..." class="search-input" id="search-input">
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
                        <h1 class="dashboard-title">Track Follow-up Actions</h1>
                        <p class="dashboard-subtitle">Monitor and manage inspection follow-up activities</p>
                    </div>
                </div>
                
                <!-- Content Container -->
                <div class="content-container">
                    <?php if ($success_message): ?>
                        <div class="success-message" id="success-message">
                            <div class="success-message-content">
                                <i class='bx bx-check-circle' style="font-size: 24px;"></i>
                                <span><?php echo $success_message; ?></span>
                            </div>
                            <button class="close-message" onclick="document.getElementById('success-message').style.display='none'">
                                <i class='bx bx-x'></i>
                            </button>
                        </div>
                    <?php endif; ?>
                    
                    <!-- Quick Actions -->
                    <div class="quick-actions">
                        <a href="review_violations.php" class="quick-action-card">
                            <div class="action-icon">
                                <i class='bx bxs-error-circle'></i>
                            </div>
                            <div class="action-content">
                                <div class="action-title">Review Violations</div>
                                <div class="action-description">Review pending violations</div>
                            </div>
                        </a>
                        <a href="#" class="quick-action-card" onclick="createNewFollowUp()">
                            <div class="action-icon">
                                <i class='bx bx-plus-circle'></i>
                            </div>
                            <div class="action-content">
                                <div class="action-title">New Follow-up</div>
                                <div class="action-description">Create new follow-up task</div>
                            </div>
                        </a>
                        <a href="#" class="quick-action-card" onclick="generateFollowUpReport()">
                            <div class="action-icon">
                                <i class='bx bxs-report'></i>
                            </div>
                            <div class="action-content">
                                <div class="action-title">Generate Report</div>
                                <div class="action-description">Generate follow-up report</div>
                            </div>
                        </a>
                        <a href="#" class="quick-action-card" onclick="showOverdueTasks()">
                            <div class="action-icon">
                                <i class='bx bxs-alarm-exclamation'></i>
                            </div>
                            <div class="action-content">
                                <div class="action-title">Overdue Tasks</div>
                                <div class="action-description">View overdue follow-ups</div>
                            </div>
                        </a>
                    </div>
                    
                    <!-- Statistics Cards -->
                    <div class="stats-grid">
                        <div class="stat-card" data-type="total" onclick="filterByStatus('all')">
                            <div class="stat-header">
                                <div class="stat-icon-container">
                                    <i class='bx bxs-tasks'></i>
                                </div>
                                <div class="stat-trend">
                                    <i class='bx bx-up-arrow-alt'></i>
                                    +15%
                                </div>
                            </div>
                            <div class="stat-value"><?php echo $stats['total']; ?></div>
                            <div class="stat-label">Total Follow-ups</div>
                        </div>
                        <div class="stat-card" data-type="overdue" onclick="filterByStatus('overdue')">
                            <div class="stat-header">
                                <div class="stat-icon-container">
                                    <i class='bx bxs-alarm-exclamation'></i>
                                </div>
                                <div class="stat-trend">
                                    <i class='bx bx-up-arrow-alt'></i>
                                    +3%
                                </div>
                            </div>
                            <div class="stat-value"><?php echo $stats['overdue']; ?></div>
                            <div class="stat-label">Overdue</div>
                        </div>
                        <div class="stat-card" data-type="due_soon" onclick="filterByStatus('due_soon')">
                            <div class="stat-header">
                                <div class="stat-icon-container">
                                    <i class='bx bxs-time-five'></i>
                                </div>
                                <div class="stat-trend">
                                    <i class='bx bx-up-arrow-alt'></i>
                                    +8%
                                </div>
                            </div>
                            <div class="stat-value"><?php echo $stats['due_soon']; ?></div>
                            <div class="stat-label">Due Soon (≤3 days)</div>
                        </div>
                        <div class="stat-card" data-type="pending" onclick="filterByStatus('pending')">
                            <div class="stat-header">
                                <div class="stat-icon-container">
                                    <i class='bx bxs-hourglass'></i>
                                </div>
                                <div class="stat-trend">
                                    <i class='bx bx-down-arrow-alt'></i>
                                    -5%
                                </div>
                            </div>
                            <div class="stat-value"><?php echo $stats['pending']; ?></div>
                            <div class="stat-label">Pending</div>
                        </div>
                        <div class="stat-card" data-type="scheduled" onclick="filterByStatus('scheduled')">
                            <div class="stat-header">
                                <div class="stat-icon-container">
                                    <i class='bx bxs-calendar'></i>
                                </div>
                                <div class="stat-trend">
                                    <i class='bx bx-up-arrow-alt'></i>
                                    +12%
                                </div>
                            </div>
                            <div class="stat-value"><?php echo $stats['scheduled']; ?></div>
                            <div class="stat-label">Scheduled</div>
                        </div>
                        <div class="stat-card" data-type="in_progress" onclick="filterByStatus('in_progress')">
                            <div class="stat-header">
                                <div class="stat-icon-container">
                                    <i class='bx bxs-cog'></i>
                                </div>
                                <div class="stat-trend">
                                    <i class='bx bx-up-arrow-alt'></i>
                                    +6%
                                </div>
                            </div>
                            <div class="stat-value"><?php echo $stats['in_progress']; ?></div>
                            <div class="stat-label">In Progress</div>
                        </div>
                        <div class="stat-card" data-type="completed" onclick="filterByStatus('completed')">
                            <div class="stat-header">
                                <div class="stat-icon-container">
                                    <i class='bx bxs-check-circle'></i>
                                </div>
                                <div class="stat-trend">
                                    <i class='bx bx-up-arrow-alt'></i>
                                    +20%
                                </div>
                            </div>
                            <div class="stat-value"><?php echo $stats['completed']; ?></div>
                            <div class="stat-label">Completed</div>
                        </div>
                        <div class="stat-card" data-type="unassigned" onclick="filterByAssigned('unassigned')">
                            <div class="stat-header">
                                <div class="stat-icon-container">
                                    <i class='bx bxs-user-x'></i>
                                </div>
                                <div class="stat-trend">
                                    <i class='bx bx-down-arrow-alt'></i>
                                    -7%
                                </div>
                            </div>
                            <div class="stat-value"><?php echo $stats['unassigned']; ?></div>
                            <div class="stat-label">Unassigned</div>
                        </div>
                    </div>
                    
                    <!-- Filter Tabs Container -->
                    <div class="filter-tabs-container">
                        <div class="filter-header">
                            <h3 class="filter-title">
                                <i class='bx bxs-trending-up'></i>
                                Follow-up Actions
                            </h3>
                        </div>
                        
                        <div class="filter-tabs">
                            <a href="?status=all&type=<?php echo $filter_type; ?>&date=<?php echo $filter_date; ?>&search=<?php echo urlencode($search_query); ?>&barangay=<?php echo $filter_barangay; ?>&establishment=<?php echo $filter_establishment; ?>&assigned_to=<?php echo $filter_assigned_to; ?>" class="filter-tab <?php echo $filter_status === 'all' ? 'active' : ''; ?>">
                                <i class='bx bxs-dashboard'></i>
                                All Follow-ups
                                <span class="filter-tab-count"><?php echo $stats['total']; ?></span>
                            </a>
                            <a href="?status=overdue&type=<?php echo $filter_type; ?>&date=<?php echo $filter_date; ?>&search=<?php echo urlencode($search_query); ?>&barangay=<?php echo $filter_barangay; ?>&establishment=<?php echo $filter_establishment; ?>&assigned_to=<?php echo $filter_assigned_to; ?>" class="filter-tab <?php echo $filter_status === 'overdue' ? 'active' : ''; ?>">
                                <i class='bx bxs-alarm-exclamation'></i>
                                Overdue
                                <span class="filter-tab-count"><?php echo $stats['overdue']; ?></span>
                            </a>
                            <a href="?status=due_soon&type=<?php echo $filter_type; ?>&date=<?php echo $filter_date; ?>&search=<?php echo urlencode($search_query); ?>&barangay=<?php echo $filter_barangay; ?>&establishment=<?php echo $filter_establishment; ?>&assigned_to=<?php echo $filter_assigned_to; ?>" class="filter-tab <?php echo $filter_status === 'due_soon' ? 'active' : ''; ?>">
                                <i class='bx bxs-time-five'></i>
                                Due Soon
                                <span class="filter-tab-count"><?php echo $stats['due_soon']; ?></span>
                            </a>
                            <a href="?status=pending&type=<?php echo $filter_type; ?>&date=<?php echo $filter_date; ?>&search=<?php echo urlencode($search_query); ?>&barangay=<?php echo $filter_barangay; ?>&establishment=<?php echo $filter_establishment; ?>&assigned_to=<?php echo $filter_assigned_to; ?>" class="filter-tab <?php echo $filter_status === 'pending' ? 'active' : ''; ?>">
                                <i class='bx bxs-hourglass'></i>
                                Pending
                                <span class="filter-tab-count"><?php echo $stats['pending']; ?></span>
                            </a>
                            <a href="?status=scheduled&type=<?php echo $filter_type; ?>&date=<?php echo $filter_date; ?>&search=<?php echo urlencode($search_query); ?>&barangay=<?php echo $filter_barangay; ?>&establishment=<?php echo $filter_establishment; ?>&assigned_to=<?php echo $filter_assigned_to; ?>" class="filter-tab <?php echo $filter_status === 'scheduled' ? 'active' : ''; ?>">
                                <i class='bx bxs-calendar'></i>
                                Scheduled
                                <span class="filter-tab-count"><?php echo $stats['scheduled']; ?></span>
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
                                            <i class='bx bxs-calendar'></i>
                                            Scheduled Date
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
                                            <i class='bx bxs-category'></i>
                                            Follow-up Type
                                        </label>
                                        <select class="filter-select" name="type">
                                            <?php foreach ($filter_type_options as $value => $label): ?>
                                                <option value="<?php echo $value; ?>" <?php echo $filter_type === $value ? 'selected' : ''; ?>>
                                                    <?php echo $label; ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    
                                    <div class="filter-group">
                                        <label class="filter-label">
                                            <i class='bx bxs-building'></i>
                                            Barangay
                                        </label>
                                        <select class="filter-select" name="barangay">
                                            <option value="">All Barangays</option>
                                            <?php foreach ($barangays as $barangay): ?>
                                                <option value="<?php echo htmlspecialchars($barangay); ?>" <?php echo $filter_barangay === $barangay ? 'selected' : ''; ?>>
                                                    <?php echo htmlspecialchars($barangay); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    
                                    <div class="filter-group">
                                        <label class="filter-label">
                                            <i class='bx bxs-business'></i>
                                            Establishment
                                        </label>
                                        <select class="filter-select" name="establishment">
                                            <option value="">All Establishments</option>
                                            <?php foreach ($establishments as $establishment): ?>
                                                <option value="<?php echo $establishment['id']; ?>" <?php echo $filter_establishment == $establishment['id'] ? 'selected' : ''; ?>>
                                                    <?php echo htmlspecialchars($establishment['establishment_name']); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>
                                
                                <div class="filter-row">
                                    <div class="filter-group">
                                        <label class="filter-label">
                                            <i class='bx bxs-user'></i>
                                            Assigned To
                                        </label>
                                        <select class="filter-select" name="assigned_to">
                                            <option value="">All Assignments</option>
                                            <option value="unassigned" <?php echo $filter_assigned_to === 'unassigned' ? 'selected' : ''; ?>>Unassigned</option>
                                            <?php foreach ($inspectors as $inspector): ?>
                                                <option value="<?php echo $inspector['id']; ?>" <?php echo $filter_assigned_to == $inspector['id'] ? 'selected' : ''; ?>>
                                                    <?php echo htmlspecialchars($inspector['full_name']); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    
                                    <div class="filter-group">
                                        <label class="filter-label">
                                            <i class='bx bx-search'></i>
                                            Search
                                        </label>
                                        <input type="text" class="filter-input" name="search" placeholder="Search by establishment, owner, notes..." value="<?php echo htmlspecialchars($search_query); ?>">
                                    </div>
                                </div>
                                
                                <div class="filter-actions">
                                    <a href="track_follow_up.php" class="filter-button clear-filters">
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
                    
                    <!-- Follow-ups Table -->
                    <div class="follow-ups-table-container">
                        <div class="table-header">
                            <div>Follow-up Type</div>
                            <div>Establishment</div>
                            <div>Scheduled Date</div>
                            <div>Status</div>
                            <div>Priority</div>
                            <div>Assigned To</div>
                            <div>Notes</div>
                            <div>Actions</div>
                        </div>
                        <div style="max-height: 500px; overflow-y: auto;">
                            <?php if (count($follow_ups) > 0): ?>
                                <?php foreach ($follow_ups as $index => $follow_up): ?>
                                    <?php 
                                    $followUpTypeClass = 'follow-up-type-' . $follow_up['follow_up_type'];
                                    $followUpTypeLabel = $follow_up_types[$follow_up['follow_up_type']] ?? ucfirst(str_replace('_', ' ', $follow_up['follow_up_type']));
                                    $priority = $follow_up['priority_status'];
                                    ?>
                                    <div class="table-row" style="animation-delay: <?php echo $index * 0.05; ?>s;">
                                        <div class="table-cell" data-label="Follow-up Type">
                                            <div class="follow-up-type"><?php echo $followUpTypeLabel; ?></div>
                                            <span class="follow-up-type-badge <?php echo $followUpTypeClass; ?>">
                                                <?php echo $followUpTypeLabel; ?>
                                            </span>
                                            <?php if ($follow_up['pending_violations'] > 0): ?>
                                                <div class="violation-count">
                                                    <i class='bx bxs-error-circle'></i>
                                                    <?php echo $follow_up['pending_violations']; ?> violation(s)
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                        <div class="table-cell" data-label="Establishment">
                                            <div class="establishment-name"><?php echo htmlspecialchars($follow_up['establishment_name']); ?></div>
                                            <div class="establishment-info">
                                                <?php echo htmlspecialchars($follow_up['establishment_type']); ?> • <?php echo htmlspecialchars($follow_up['barangay']); ?>
                                            </div>
                                            <div class="establishment-info" style="font-size: 11px;">
                                                Owner: <?php echo htmlspecialchars($follow_up['owner_name']); ?>
                                            </div>
                                        </div>
                                        <div class="table-cell" data-label="Scheduled Date">
                                            <div style="font-weight: 600;"><?php echo formatDate($follow_up['scheduled_date']); ?></div>
                                            <div class="calendar-info">
                                                <i class='bx bxs-calendar'></i>
                                                <?php 
                                                if ($follow_up['actual_date']) {
                                                    echo 'Completed: ' . formatDate($follow_up['actual_date']);
                                                } elseif ($follow_up['scheduled_date']) {
                                                    $days_until = $follow_up['days_until_due'];
                                                    if ($days_until > 0) {
                                                        echo "In $days_until days";
                                                    } elseif ($days_until < 0) {
                                                        echo abs($days_until) . " days ago";
                                                    } else {
                                                        echo "Today";
                                                    }
                                                }
                                                ?>
                                            </div>
                                        </div>
                                        <div class="table-cell" data-label="Status">
                                            <?php echo getStatusBadge($follow_up['status']); ?>
                                        </div>
                                        <div class="table-cell" data-label="Priority">
                                            <?php if (in_array($priority, ['overdue', 'due_soon'])): ?>
                                                <?php echo getPriorityBadge($priority, $follow_up['days_until_due']); ?>
                                            <?php else: ?>
                                                <span style="color: var(--text-light); font-size: 12px;">Normal</span>
                                            <?php endif; ?>
                                        </div>
                                        <div class="table-cell" data-label="Assigned To">
                                            <?php if ($follow_up['assigned_to_name']): ?>
                                                <div style="font-weight: 600;"><?php echo htmlspecialchars($follow_up['assigned_to_name']); ?></div>
                                            <?php else: ?>
                                                <span style="color: var(--text-light); font-style: italic;">Unassigned</span>
                                            <?php endif; ?>
                                        </div>
                                        <div class="table-cell" data-label="Notes">
                                            <?php if ($follow_up['notes']): ?>
                                                <div class="notes-preview" title="<?php echo htmlspecialchars($follow_up['notes']); ?>">
                                                    <?php echo htmlspecialchars(substr($follow_up['notes'], 0, 100)); ?>
                                                    <?php echo strlen($follow_up['notes']) > 100 ? '...' : ''; ?>
                                                </div>
                                            <?php else: ?>
                                                <span style="color: var(--text-light); font-style: italic;">No notes</span>
                                            <?php endif; ?>
                                            <?php if ($follow_up['outcome']): ?>
                                                <div style="font-size: 11px; color: var(--success); margin-top: 4px;">
                                                    <i class='bx bxs-check-circle'></i> Outcome recorded
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                        <div class="table-cell" data-label="Actions">
                                            <div class="action-buttons">
                                                <button class="action-button update-button" onclick="updateFollowUp(<?php echo $follow_up['id']; ?>, '<?php echo $follow_up['status']; ?>')">
                                                    <i class='bx bxs-edit'></i>
                                                    Update
                                                </button>
                                                
                                                <?php if (!$follow_up['assigned_to'] && $follow_up['status'] !== 'completed' && $follow_up['status'] !== 'cancelled'): ?>
                                                    <button class="action-button assign-button" onclick="assignFollowUp(<?php echo $follow_up['id']; ?>)">
                                                        <i class='bx bxs-user-plus'></i>
                                                        Assign
                                                    </button>
                                                <?php endif; ?>
                                                
                                                <?php if ($follow_up['status'] !== 'completed' && $follow_up['status'] !== 'cancelled'): ?>
                                                    <button class="action-button reschedule-button" onclick="rescheduleFollowUp(<?php echo $follow_up['id']; ?>, '<?php echo $follow_up['scheduled_date']; ?>')">
                                                        <i class='bx bxs-calendar-edit'></i>
                                                        Reschedule
                                                    </button>
                                                <?php endif; ?>
                                                
                                                <?php if ($follow_up['status'] === 'pending' || $follow_up['status'] === 'scheduled'): ?>
                                                    <button class="action-button complete-button" onclick="completeFollowUp(<?php echo $follow_up['id']; ?>)">
                                                        <i class='bx bxs-check-circle'></i>
                                                        Complete
                                                    </button>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <div class="no-follow-ups">
                                    <div class="no-follow-ups-icon">
                                        <i class='bx bxs-tasks'></i>
                                    </div>
                                    <h3>No Follow-ups Found</h3>
                                    <p>No follow-up actions match your current filters.</p>
                                    <?php if ($filter_status !== 'all' || $filter_type !== 'all' || $filter_date !== '' || $search_query !== '' || $filter_barangay !== '' || $filter_establishment !== '' || $filter_assigned_to !== ''): ?>
                                        <a href="track_follow_up.php" class="filter-button" style="margin-top: 16px;">
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
            const modals = ['update', 'assign', 'reschedule'];
            modals.forEach(modal => {
                const modalElement = document.getElementById(modal + '-modal');
                const closeButton = document.getElementById(modal + '-modal-close');
                const cancelButton = document.getElementById('cancel-' + modal);
                
                if (closeButton) closeButton.addEventListener('click', () => closeModal(modal));
                if (cancelButton) cancelButton.addEventListener('click', () => closeModal(modal));
                
                modalElement.addEventListener('click', function(e) {
                    if (e.target === this) {
                        closeModal(modal);
                    }
                });
            });
            
            // Form submissions
            const updateForm = document.getElementById('update-follow-up-form');
            const assignForm = document.getElementById('assign-follow-up-form');
            const rescheduleForm = document.getElementById('reschedule-follow-up-form');
            
            if (updateForm) {
                updateForm.addEventListener('submit', function(e) {
                    e.preventDefault();
                    this.submit();
                });
            }
            
            if (assignForm) {
                assignForm.addEventListener('submit', function(e) {
                    e.preventDefault();
                    this.submit();
                });
            }
            
            if (rescheduleForm) {
                rescheduleForm.addEventListener('submit', function(e) {
                    e.preventDefault();
                    this.submit();
                });
            }
            
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
            if (window.innerWidth <= 992) {
                const tableCells = document.querySelectorAll('.table-cell');
                const headers = ['Follow-up Type', 'Establishment', 'Scheduled Date', 'Status', 'Priority', 'Assigned To', 'Notes', 'Actions'];
                
                tableCells.forEach((cell, index) => {
                    const rowIndex = Math.floor(index / 8);
                    const colIndex = index % 8;
                    
                    if (colIndex < headers.length) {
                        cell.setAttribute('data-label', headers[colIndex]);
                    }
                });
            }
        }
        
        function updateFollowUp(followUpId, currentStatus) {
            const updateModal = document.getElementById('update-modal');
            const updateFollowUpId = document.getElementById('update-follow-up-id');
            const statusSelect = document.getElementById('status');
            
            updateFollowUpId.value = followUpId;
            statusSelect.value = currentStatus;
            
            // Open modal
            openModal('update');
        }
        
        function assignFollowUp(followUpId) {
            const assignModal = document.getElementById('assign-modal');
            const assignFollowUpId = document.getElementById('assign-follow-up-id');
            
            assignFollowUpId.value = followUpId;
            
            // Open modal
            openModal('assign');
        }
        
        function rescheduleFollowUp(followUpId, scheduledDate) {
            const rescheduleModal = document.getElementById('reschedule-modal');
            const rescheduleFollowUpId = document.getElementById('reschedule-follow-up-id');
            const scheduledDateInput = document.getElementById('scheduled_date');
            
            rescheduleFollowUpId.value = followUpId;
            if (scheduledDate) {
                scheduledDateInput.value = scheduledDate.split(' ')[0];
            } else {
                const tomorrow = new Date();
                tomorrow.setDate(tomorrow.getDate() + 1);
                scheduledDateInput.value = tomorrow.toISOString().split('T')[0];
            }
            
            // Open modal
            openModal('reschedule');
        }
        
        function completeFollowUp(followUpId) {
            if (confirm('Are you sure you want to mark this follow-up as completed?')) {
                // Create a form and submit it
                const form = document.createElement('form');
                form.method = 'POST';
                form.style.display = 'none';
                
                const followUpIdInput = document.createElement('input');
                followUpIdInput.type = 'hidden';
                followUpIdInput.name = 'follow_up_id';
                followUpIdInput.value = followUpId;
                
                const statusInput = document.createElement('input');
                statusInput.type = 'hidden';
                statusInput.name = 'status';
                statusInput.value = 'completed';
                
                const submitInput = document.createElement('input');
                submitInput.type = 'hidden';
                submitInput.name = 'update_follow_up_status';
                submitInput.value = '1';
                
                form.appendChild(followUpIdInput);
                form.appendChild(statusInput);
                form.appendChild(submitInput);
                document.body.appendChild(form);
                form.submit();
            }
        }
        
        function createNewFollowUp() {
            showNotification('info', 'New follow-up creation feature coming soon...');
        }
        
        function generateFollowUpReport() {
            showNotification('info', 'Generating follow-up report...');
            
            // In a real implementation, this would generate a report PDF
            setTimeout(() => {
                showNotification('success', 'Follow-up report generated successfully!');
            }, 1500);
        }
        
        function showOverdueTasks() {
            // Filter to show overdue tasks
            const url = new URL(window.location.href);
            url.searchParams.set('status', 'overdue');
            window.location.href = url.toString();
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
                    <i class='bx ${type === 'success' ? 'bx-check-circle' : type === 'error' ? 'bx-error-circle' : type === 'warning' ? 'bx-error' : 'bx-info-circle'}'></i>
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
            } else if (type === 'error') {
                notification.style.background = 'linear-gradient(135deg, rgba(220, 38, 38, 0.9), rgba(220, 38, 38, 0.8))';
                notification.style.color = 'white';
                notification.style.border = '1px solid rgba(220, 38, 38, 0.3)';
            } else if (type === 'warning') {
                notification.style.background = 'linear-gradient(135deg, rgba(245, 158, 11, 0.9), rgba(245, 158, 11, 0.8))';
                notification.style.color = 'white';
                notification.style.border = '1px solid rgba(245, 158, 11, 0.3)';
            } else {
                notification.style.background = 'linear-gradient(135deg, rgba(59, 130, 246, 0.9), rgba(59, 130, 246, 0.8))';
                notification.style.color = 'white';
                notification.style.border = '1px solid rgba(59, 130, 246, 0.3)';
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
        
        function openModal(modal) {
            document.getElementById(modal + '-modal').classList.add('active');
        }
        
        function closeModal(modal) {
            document.getElementById(modal + '-modal').classList.remove('active');
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
                case 'overdue':
                    filterByStatus('overdue');
                    break;
                case 'due_soon':
                    filterByStatus('due_soon');
                    break;
                case 'pending':
                    filterByStatus('pending');
                    break;
                case 'scheduled':
                    filterByStatus('scheduled');
                    break;
                case 'in_progress':
                    filterByStatus('in_progress');
                    break;
                case 'completed':
                    filterByStatus('completed');
                    break;
                case 'unassigned':
                    filterByAssigned('unassigned');
                    break;
            }
        }
        
        function filterByStatus(status) {
            const url = new URL(window.location.href);
            url.searchParams.set('status', status);
            window.location.href = url.toString();
        }
        
        function filterByAssigned(assigned) {
            const url = new URL(window.location.href);
            url.searchParams.set('assigned_to', assigned);
            window.location.href = url.toString();
        }
        
        // Handle window resize for responsive layout
        window.addEventListener('resize', addDataLabels);
    </script>
</body>
</html>