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

if ($user) {
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
} else {
    $full_name = "User";
    $role = "USER";
    $avatar = "";
}

// Check if user has permission (EMPLOYEE or ADMIN)
if ($role !== 'ADMIN' && $role !== 'ADMIN') {
    header("Location: ../admin_dashboard.php");
    exit();
}

// Get all fire/rescue incidents for display with filtering
function getFireRescueIncidents($pdo, $status_filter = 'all', $severity_filter = 'all', $date_filter = '', $search_query = '') {
    $sql = "SELECT 
                id,
                external_id,
                title,
                location,
                description,
                emergency_type,
                severity,
                status,
                dispatch_status,
                caller_name,
                caller_phone,
                affected_barangays,
                created_at,
                responded_at,
                notes,
                rescue_category,
                is_fire_rescue_related,
                dispatch_id
            FROM api_incidents 
            WHERE (emergency_type IN ('fire', 'rescue') 
                   OR is_fire_rescue_related = 1
                   OR rescue_category IS NOT NULL
                   OR (assistance_needed IN ('fire', 'rescue', 'fire_truck'))
                   OR (description LIKE '%fire%' OR description LIKE '%rescue%')
                   OR (emergency_type = 'other' AND (description LIKE '%rescue%' OR description LIKE '%collapsing%' OR description LIKE '%fire%')))";
    
    $params = [];
    
    // Apply status filter
    if ($status_filter !== 'all') {
        $sql .= " AND status = ?";
        $params[] = $status_filter;
    }
    
    // Apply severity filter
    if ($severity_filter !== 'all') {
        $sql .= " AND severity = ?";
        $params[] = $severity_filter;
    }
    
    // Apply date filter
    if (!empty($date_filter)) {
        if ($date_filter === 'today') {
            $sql .= " AND DATE(created_at) = CURDATE()";
        } elseif ($date_filter === 'yesterday') {
            $sql .= " AND DATE(created_at) = DATE_SUB(CURDATE(), INTERVAL 1 DAY)";
        } elseif ($date_filter === 'week') {
            $sql .= " AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)";
        } elseif ($date_filter === 'month') {
            $sql .= " AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)";
        }
    }
    
    // Apply search query
    if (!empty($search_query)) {
        $sql .= " AND (external_id LIKE ? OR title LIKE ? OR location LIKE ? OR description LIKE ? OR caller_name LIKE ? OR emergency_type LIKE ?)";
        $search_param = "%$search_query%";
        $params = array_merge($params, [$search_param, $search_param, $search_param, $search_param, $search_param, $search_param]);
    }
    
    $sql .= " ORDER BY created_at DESC, id DESC";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Get incident counts for statistics
function getIncidentCounts($pdo, $status_filter = 'all', $severity_filter = 'all', $date_filter = '', $search_query = '') {
    $sql = "SELECT 
                COUNT(*) as total,
                SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending,
                SUM(CASE WHEN status = 'processing' THEN 1 ELSE 0 END) as processing,
                SUM(CASE WHEN status = 'responded' THEN 1 ELSE 0 END) as responded,
                SUM(CASE WHEN status = 'closed' THEN 1 ELSE 0 END) as closed,
                SUM(CASE WHEN severity = 'critical' THEN 1 ELSE 0 END) as critical,
                SUM(CASE WHEN severity = 'high' THEN 1 ELSE 0 END) as high,
                SUM(CASE WHEN severity = 'medium' THEN 1 ELSE 0 END) as medium,
                SUM(CASE WHEN severity = 'low' THEN 1 ELSE 0 END) as low
            FROM api_incidents 
            WHERE (emergency_type IN ('fire', 'rescue') 
                   OR is_fire_rescue_related = 1
                   OR rescue_category IS NOT NULL
                   OR (assistance_needed IN ('fire', 'rescue', 'fire_truck'))
                   OR (description LIKE '%fire%' OR description LIKE '%rescue%')
                   OR (emergency_type = 'other' AND (description LIKE '%rescue%' OR description LIKE '%collapsing%' OR description LIKE '%fire%')))";
    
    $params = [];
    
    // Apply status filter
    if ($status_filter !== 'all') {
        $sql .= " AND status = ?";
        $params[] = $status_filter;
    }
    
    // Apply severity filter
    if ($severity_filter !== 'all') {
        $sql .= " AND severity = ?";
        $params[] = $severity_filter;
    }
    
    // Apply date filter
    if (!empty($date_filter)) {
        if ($date_filter === 'today') {
            $sql .= " AND DATE(created_at) = CURDATE()";
        } elseif ($date_filter === 'yesterday') {
            $sql .= " AND DATE(created_at) = DATE_SUB(CURDATE(), INTERVAL 1 DAY)";
        } elseif ($date_filter === 'week') {
            $sql .= " AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)";
        } elseif ($date_filter === 'month') {
            $sql .= " AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)";
        }
    }
    
    // Apply search query
    if (!empty($search_query)) {
        $sql .= " AND (external_id LIKE ? OR title LIKE ? OR location LIKE ? OR description LIKE ? OR caller_name LIKE ? OR emergency_type LIKE ?)";
        $search_param = "%$search_query%";
        $params = array_merge($params, [$search_param, $search_param, $search_param, $search_param, $search_param, $search_param]);
    }
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

// Check if incident_status_logs table exists, create if not
function checkAndCreateLogTable($pdo) {
    $check_sql = "SHOW TABLES LIKE 'incident_status_logs'";
    $stmt = $pdo->query($check_sql);
    
    if ($stmt->rowCount() == 0) {
        $create_sql = "CREATE TABLE incident_status_logs (
            id INT(11) NOT NULL AUTO_INCREMENT,
            incident_id INT(11) NOT NULL,
            old_status VARCHAR(50) NOT NULL,
            new_status VARCHAR(50) NOT NULL,
            changed_by INT(11) NOT NULL,
            change_notes TEXT,
            changed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_incident_id (incident_id),
            KEY idx_changed_at (changed_at),
            KEY idx_changed_by (changed_by),
            FOREIGN KEY (incident_id) REFERENCES api_incidents(id) ON DELETE CASCADE,
            FOREIGN KEY (changed_by) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci";
        
        $pdo->exec($create_sql);
    }
}

// Create logs table if it doesn't exist
checkAndCreateLogTable($pdo);

// Get filter parameters
$status_filter = isset($_GET['status']) ? $_GET['status'] : 'all';
$severity_filter = isset($_GET['severity']) ? $_GET['severity'] : 'all';
$date_filter = isset($_GET['date']) ? $_GET['date'] : '';
$search_query = isset($_GET['search']) ? $_GET['search'] : '';

// Get incidents and counts with filters
$incidents = getFireRescueIncidents($pdo, $status_filter, $severity_filter, $date_filter, $search_query);
$counts = getIncidentCounts($pdo, 'all', 'all', $date_filter, $search_query);

// Get dispatch information for each incident
foreach ($incidents as &$incident) {
    if ($incident['dispatch_id']) {
        $dispatch_query = "SELECT 
            di.status as dispatch_status,
            di.dispatched_at,
            di.status_updated_at,
            u.unit_name,
            u.unit_code
            FROM dispatch_incidents di
            LEFT JOIN units u ON di.unit_id = u.id
            WHERE di.id = ?";
        
        $dispatch_stmt = $pdo->prepare($dispatch_query);
        $dispatch_stmt->execute([$incident['dispatch_id']]);
        $dispatch_info = $dispatch_stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($dispatch_info) {
            $incident['dispatch_info'] = $dispatch_info;
        }
    }
}

// Available status options
$status_options = [
    'pending' => 'Pending',
    'processing' => 'Processing',
    'responded' => 'Responded',
    'closed' => 'Closed'
];

// Status icons
$status_icons = [
    'pending' => 'bx-time',
    'processing' => 'bx-refresh',
    'responded' => 'bx-check-circle',
    'closed' => 'bx-check-double'
];

// Status colors
$status_colors = [
    'pending' => 'warning',
    'processing' => 'info',
    'responded' => 'primary',
    'closed' => 'success'
];

// Date filter options
$date_options = [
    '' => 'All Time',
    'today' => 'Today',
    'yesterday' => 'Yesterday',
    'week' => 'Last 7 Days',
    'month' => 'Last 30 Days'
];

$stmt = null;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Incident Status - Fire & Rescue Management</title>
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

        .stats-container {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 25px;
            margin-bottom: 30px;
        }
        
        .stat-card {
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: 16px;
            padding: 20px;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            text-align: center;
            transition: all 0.3s ease;
            cursor: pointer;
            position: relative;
            overflow: hidden;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
        }
        
        .stat-card.active {
            border-color: var(--primary-color);
            box-shadow: 0 0 0 2px rgba(220, 38, 38, 0.2);
        }
        
        .stat-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
        }
        
        .stat-card[data-status="pending"]::before {
            background: var(--warning);
        }
        
        .stat-card[data-status="processing"]::before {
            background: var(--info);
        }
        
        .stat-card[data-status="responded"]::before {
            background: var(--primary-color);
        }
        
        .stat-card[data-status="closed"]::before {
            background: var(--success);
        }
        
        .stat-card[data-severity="critical"]::before {
            background: var(--danger);
        }
        
        .stat-card[data-severity="high"]::before {
            background: var(--primary-color);
        }
        
        .stat-card[data-severity="medium"]::before {
            background: var(--warning);
        }
        
        .stat-card[data-severity="low"]::before {
            background: var(--success);
        }
        
        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.1);
        }
        
        .stat-icon {
            font-size: 28px;
            margin-bottom: 12px;
            padding: 12px;
            border-radius: 12px;
            background: rgba(220, 38, 38, 0.1);
            color: var(--primary-color);
            display: flex;
            align-items: center;
            justify-content: center;
            width: 52px;
            height: 52px;
            flex-shrink: 0;
        }
        
        .stat-card[data-status="pending"] .stat-icon {
            background: rgba(245, 158, 11, 0.1);
            color: var(--warning);
        }
        
        .stat-card[data-status="processing"] .stat-icon {
            background: rgba(59, 130, 246, 0.1);
            color: var(--info);
        }
        
        .stat-card[data-status="responded"] .stat-icon {
            background: rgba(220, 38, 38, 0.1);
            color: var(--primary-color);
        }
        
        .stat-card[data-status="closed"] .stat-icon {
            background: rgba(16, 185, 129, 0.1);
            color: var(--success);
        }
        
        .stat-value {
            font-size: 28px;
            font-weight: 800;
            margin-bottom: 4px;
        }
        
        .stat-label {
            font-size: 14px;
            color: var(--text-light);
        }

        .filter-tabs {
            display: flex;
            gap: 8px;
            margin-bottom: 20px;
            flex-wrap: wrap;
            border-bottom: 1px solid var(--border-color);
            padding-bottom: 16px;
        }

        .filter-tab {
            padding: 10px 20px;
            border-radius: 8px;
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            color: var(--text-color);
            cursor: pointer;
            transition: all 0.3s ease;
            font-weight: 500;
            display: flex;
            align-items: center;
            gap: 8px;
            text-decoration: none;
        }

        .filter-tab.active {
            background: var(--primary-color);
            color: white;
            border-color: var(--primary-color);
        }

        .filter-tab:hover:not(.active) {
            background: var(--gray-100);
            text-decoration: none;
        }

        .dark-mode .filter-tab:hover:not(.active) {
            background: var(--gray-800);
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

        /* FIXED FILTERS CONTAINER */
        .filters-container {
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: 16px;
            padding: 24px;
            margin-bottom: 30px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
        }

        .filter-header {
            margin-bottom: 20px;
            padding-bottom: 16px;
            border-bottom: 1px solid var(--border-color);
        }

        .filter-title {
            font-size: 18px;
            font-weight: 600;
            color: var(--text-color);
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .filter-title i {
            color: var(--primary-color);
        }

        .filter-row {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 20px;
            align-items: end;
        }

        .filter-group {
            display: flex;
            flex-direction: column;
            gap: 8px;
            flex: 1;
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
            margin-top: 10px;
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

        .incidents-table {
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: 16px;
            overflow: hidden;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
        }
        
        /* FIXED TABLE LAYOUT - Better column widths */
        .table-header {
            display: grid;
            grid-template-columns: 80px 100px minmax(200px, 1fr) 180px 100px 120px 140px 120px;
            gap: 12px;
            padding: 16px 20px;
            background: rgba(220, 38, 38, 0.02);
            border-bottom: 1px solid var(--border-color);
            font-weight: 600;
            color: var(--text-color);
            font-size: 13px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .table-row {
            display: grid;
            grid-template-columns: 80px 100px minmax(200px, 1fr) 180px 100px 120px 140px 120px;
            gap: 12px;
            padding: 16px 20px;
            border-bottom: 1px solid var(--border-color);
            transition: all 0.3s ease;
            align-items: start;
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
            color: var(--text-color);
            min-height: 40px;
            justify-content: center;
        }
        
        .incident-id {
            font-weight: 700;
            color: var(--primary-color);
            font-size: 15px;
        }
        
        .incident-description {
            color: var(--text-light);
            font-size: 13px;
            line-height: 1.4;
            overflow: hidden;
            text-overflow: ellipsis;
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
        }
        
        .status-badge {
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            text-transform: uppercase;
            display: inline-flex;
            align-items: center;
            gap: 4px;
            width: fit-content;
            white-space: nowrap;
        }
        
        .status-pending {
            background: rgba(245, 158, 11, 0.1);
            color: var(--warning);
        }
        
        .status-processing {
            background: rgba(59, 130, 246, 0.1);
            color: var(--info);
        }
        
        .status-responded {
            background: rgba(220, 38, 38, 0.1);
            color: var(--primary-color);
        }
        
        .status-closed {
            background: rgba(16, 185, 129, 0.1);
            color: var(--success);
        }
        
        .emergency-badge {
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            text-transform: uppercase;
            width: fit-content;
            white-space: nowrap;
        }
        
        .emergency-low {
            background: rgba(16, 185, 129, 0.1);
            color: var(--success);
        }
        
        .emergency-medium {
            background: rgba(245, 158, 11, 0.1);
            color: var(--warning);
        }
        
        .emergency-high {
            background: rgba(220, 38, 38, 0.1);
            color: var(--primary-color);
        }
        
        .emergency-critical {
            background: rgba(220, 38, 38, 0.2);
            color: var(--danger);
            border: 1px solid var(--danger);
        }

        .action-button {
            padding: 8px 12px;
            border-radius: 8px;
            font-weight: 500;
            border: none;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            transition: all 0.3s ease;
            font-size: 13px;
            width: 100%;
            max-width: 120px;
        }
        
        .update-button {
            background-color: rgba(16, 185, 129, 0.1);
            color: var(--success);
        }
        
        .update-button:hover {
            background-color: var(--success);
            color: white;
        }
        
        .view-button {
            background-color: rgba(59, 130, 246, 0.1);
            color: var(--info);
        }
        
        .view-button:hover {
            background-color: var(--info);
            color: white;
        }

        .no-incidents {
            text-align: center;
            padding: 60px 20px;
            color: var(--text-light);
            grid-column: 1 / -1;
        }
        
        .no-incidents-icon {
            font-size: 64px;
            margin-bottom: 16px;
            color: var(--text-light);
            opacity: 0.5;
        }

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
            transform: scale(0.9);
            transition: all 0.3s ease;
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.15);
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
        }
        
        .modal-title {
            font-size: 20px;
            font-weight: 700;
        }
        
        .modal-close {
            background: none;
            border: none;
            font-size: 24px;
            cursor: pointer;
            color: var(--text-light);
            transition: all 0.3s ease;
        }
        
        .modal-close:hover {
            color: var(--danger);
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
        
        .form-select, .form-textarea {
            width: 100%;
            padding: 12px 16px;
            border-radius: 10px;
            border: 1px solid var(--border-color);
            background: var(--card-bg);
            color: var(--text-color);
            font-size: 14px;
            transition: all 0.3s ease;
        }
        
        .form-select:focus, .form-textarea:focus {
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

        .notification {
            position: fixed;
            top: 20px;
            right: 20px;
            padding: 16px 20px;
            border-radius: 12px;
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            display: flex;
            align-items: center;
            gap: 12px;
            transform: translateX(100%);
            opacity: 0;
            transition: all 0.3s ease;
            max-width: 350px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
            z-index: 1000;
        }
        
        .notification.show {
            transform: translateX(0);
            opacity: 1;
        }
        
        .notification-icon {
            font-size: 20px;
            flex-shrink: 0;
        }
        
        .notification-success .notification-icon {
            color: var(--success);
        }
        
        .notification-error .notification-icon {
            color: var(--danger);
        }
        
        .notification-warning .notification-icon {
            color: var(--warning);
        }
        
        .notification-info .notification-icon {
            color: var(--info);
        }
        
        .notification-content {
            flex: 1;
        }
        
        .notification-title {
            font-weight: 600;
            margin-bottom: 4px;
        }
        
        .notification-message {
            font-size: 14px;
            color: var(--text-light);
        }
        
        .notification-close {
            background: none;
            border: none;
            font-size: 16px;
            cursor: pointer;
            color: var(--text-light);
            flex-shrink: 0;
        }

        /* FIXED DISPATCH INFO STYLING */
        .dispatch-info {
            font-size: 11px;
            color: var(--text-light);
            margin-top: 4px;
            line-height: 1.3;
            display: flex;
            align-items: center;
            gap: 4px;
        }
        
        .dispatch-info i {
            font-size: 10px;
            flex-shrink: 0;
        }
        
        .dispatch-text {
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }
        
        .location-cell {
            min-height: 60px;
        }
        
        .location-text {
            font-size: 13px;
            color: var(--text-color);
            line-height: 1.4;
            margin-bottom: 4px;
        }

        @media (max-width: 1400px) {
            .table-header, .table-row {
                grid-template-columns: 70px 90px minmax(180px, 1fr) 160px 90px 110px 130px 110px;
                gap: 10px;
                padding: 14px 16px;
            }
        }

        @media (max-width: 1200px) {
            .table-header, .table-row {
                grid-template-columns: 60px 80px minmax(150px, 1fr) 140px 80px 100px 120px 100px;
                gap: 10px;
                padding: 12px 14px;
            }
            
            .action-button {
                padding: 6px 10px;
                font-size: 12px;
                max-width: 100px;
            }
        }

        @media (max-width: 992px) {
            .table-header {
                display: none;
            }
            
            .table-row {
                grid-template-columns: 1fr;
                gap: 12px;
                padding: 20px;
                border: 1px solid var(--border-color);
                border-radius: 12px;
                margin-bottom: 12px;
            }
            
            .table-cell {
                display: grid;
                grid-template-columns: 120px 1fr;
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
            
            .table-cell .action-button {
                max-width: none;
                width: 100%;
            }
            
            .dispatch-info {
                grid-column: 1 / -1;
                margin-top: 8px;
                padding-left: 120px;
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
            
            .stats-container {
                grid-template-columns: 1fr;
            }

            .filter-tabs {
                flex-direction: column;
            }

            .modal {
                width: 95%;
                margin: 10px;
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
            
            .stats-container {
                gap: 15px;
            }
            
            .stat-card {
                padding: 15px;
            }
            
            .filters-container {
                padding: 20px;
            }
        }

        .incident-table-container {
            max-height: 600px;
            overflow-y: auto;
        }

        /* Hide scrollbar but keep functionality */
        .incident-table-container::-webkit-scrollbar {
            width: 6px;
        }
        
        .incident-table-container::-webkit-scrollbar-track {
            background: var(--gray-100);
            border-radius: 3px;
        }
        
        .incident-table-container::-webkit-scrollbar-thumb {
            background: var(--gray-400);
            border-radius: 3px;
        }
        
        .incident-table-container::-webkit-scrollbar-thumb:hover {
            background: var(--gray-500);
        }
        
        .dark-mode .incident-table-container::-webkit-scrollbar-track {
            background: var(--gray-800);
        }
        
        .dark-mode .incident-table-container::-webkit-scrollbar-thumb {
            background: var(--gray-600);
        }
        
        .dark-mode .incident-table-container::-webkit-scrollbar-thumb:hover {
            background: var(--gray-500);
        }
    </style>
</head>
<body>
    <!-- View Details Modal -->
    <div class="modal-overlay" id="details-modal">
        <div class="modal">
            <div class="modal-header">
                <h2 class="modal-title">Incident Details</h2>
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
                    <a href="#" class="menu-item" id="dashboard-menu">
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
                    <div id="incident-management" class="submenu active">
                     
                        <a href="receive_data.php" class="submenu-item">Recieve Data</a>
                         <a href="track_status.php" class="submenu-item">Track Status</a>
                        <a href="update_status.php" class="submenu-item active">Update Status</a>
                        <a href="incidents_analytics.php" class="submenu-item">Incidents Analytics</a>
                        
                    </div>
                    
                    <!-- Barangay Volunteer Roster Management -->
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
                        <a href="../vm/review_data.php" class="submenu-item ">Review Data</a>
                        <a href="../vm/approve_applications.php" class="submenu-item">Assign Volunteers</a>
                        <a href="../vm/view_availability.php" class="submenu-item">View Availability</a>
                        <a href="../vm/toggle_volunteer_registration.php" class="submenu-item">Toggle Registration</a>
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
                        <a href="../rm/view_equipment.php" class="submenu-item">View Equipment</a>
                        <a href="../rm/approve_maintenance.php" class="submenu-item">Approve Maintenance</a>
                        <a href="../rm/approve_resources.php" class="submenu-item">Approve Resources</a>
                        <a href="../rm/review_deployment.php" class="submenu-item">Review Deployment</a>
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
                        <a href="../sm/view_shifts.php" class="submenu-item">View Shifts</a>
                        <a href="../sm/create_schedule.php" class="submenu-item">Create Schedule</a>
                        <a href="../sm/approve_shifts.php" class="submenu-item">Approve Shifts</a>
                        <a href="../sm/override_assignments.php" class="submenu-item">Override Assignments</a>
                        <a href="../sm/monitor_attendance.php" class="submenu-item">Monitor Attendance</a>
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
                        <a href="../ile/approve_reports.php" class="submenu-item">Approve Reports</a>
                        <a href="../ile/review_violations.php" class="submenu-item">Review Violations</a>
                        <a href="../ile/issue_certificates.php" class="submenu-item">Issue Certificates</a>
                        <a href="../ile/track_followup.php" class="submenu-item">Track Follow-Up</a>
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
                    
                    <a href="../includes/logout.php" class="menu-item">
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
                            <input type="text" placeholder="Search incidents..." class="search-input" id="search-input">
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
                                <img src="../profile/uploads/avatars/<?php echo $avatar; ?>" alt="User" class="user-avatar">
                            <?php else: ?>
                                <div class="user-avatar" style="background: linear-gradient(135deg, var(--primary-color), var(--secondary-color)); color: white; display: flex; align-items: center; justify-content: center; font-weight: bold; border-radius: 50%; width: 40px; height: 40px;">
                                    <?php echo strtoupper(substr($full_name, 0, 1)); ?>
                                </div>
                            <?php endif; ?>
                            <div class="user-info">
                                <p class="user-name"><?php echo $full_name; ?></p>
                                <p class="user-email"><?php echo $role; ?></p>
                            </div>
                            <div class="user-profile-dropdown" id="user-dropdown">
                                <a href="../profile/profile.php" class="dropdown-item">
                                    <i class='bx bx-user'></i>
                                    <span>Profile</span>
                                </a>
                                <a href="../settings.php" class="dropdown-item">
                                    <i class='bx bx-cog'></i>
                                    <span>Settings</span>
                                </a>
                                <div class="dropdown-divider"></div>
                                <a href="../../includes/logout.php" class="dropdown-item">
                                    <i class='bx bx-log-out'></i>
                                    <span>Logout</span>
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Dashboard Content -->
            <div class="dashboard-content">
                <div class="dashboard-header">
                    <div>
                        <h1 class="dashboard-title">Incident Status</h1>
                        <p class="dashboard-subtitle">View the status of fire and rescue incidents. Status is automatically updated when ER dispatches units. Total: <?php echo $counts['total'] ?? 0; ?> fire/rescue incidents</p>
                    </div>
                </div>
                
                <!-- Content Container -->
                <div class="content-container">
                    <!-- Statistics Cards -->
                    <div class="stats-container">
                        <div class="stat-card <?php echo $status_filter === 'all' ? 'active' : ''; ?>" data-status="all">
                            <div class="stat-icon">
                                <i class='bx bxs-dashboard'></i>
                            </div>
                            <div class="stat-value"><?php echo $counts['total'] ?? 0; ?></div>
                            <div class="stat-label">Total Incidents</div>
                        </div>
                        <div class="stat-card <?php echo $status_filter === 'pending' ? 'active' : ''; ?>" data-status="pending">
                            <div class="stat-icon">
                                <i class='bx bxs-time'></i>
                            </div>
                            <div class="stat-value"><?php echo $counts['pending'] ?? 0; ?></div>
                            <div class="stat-label">Pending</div>
                        </div>
                        <div class="stat-card <?php echo $status_filter === 'processing' ? 'active' : ''; ?>" data-status="processing">
                            <div class="stat-icon">
                                <i class='bx bxs-refresh'></i>
                            </div>
                            <div class="stat-value"><?php echo $counts['processing'] ?? 0; ?></div>
                            <div class="stat-label">Processing</div>
                        </div>
                        <div class="stat-card <?php echo $status_filter === 'responded' ? 'active' : ''; ?>" data-status="responded">
                            <div class="stat-icon">
                                <i class='bx bxs-check-circle'></i>
                            </div>
                            <div class="stat-value"><?php echo $counts['responded'] ?? 0; ?></div>
                            <div class="stat-label">Responded</div>
                        </div>
                        <div class="stat-card <?php echo $status_filter === 'closed' ? 'active' : ''; ?>" data-status="closed">
                            <div class="stat-icon">
                                <i class='bx bxs-check-double'></i>
                            </div>
                            <div class="stat-value"><?php echo $counts['closed'] ?? 0; ?></div>
                            <div class="stat-label">Closed</div>
                        </div>
                    </div>
                    
                    <!-- Filter Tabs -->
                    <div class="filter-tabs">
                        <a href="?status=all&severity=<?php echo $severity_filter; ?>&date=<?php echo $date_filter; ?>&search=<?php echo urlencode($search_query); ?>" class="filter-tab <?php echo $status_filter === 'all' ? 'active' : ''; ?>">
                            <i class='bx bxs-dashboard'></i>
                            All
                            <span class="filter-tab-count"><?php echo $counts['total'] ?? 0; ?></span>
                        </a>
                        <a href="?status=pending&severity=<?php echo $severity_filter; ?>&date=<?php echo $date_filter; ?>&search=<?php echo urlencode($search_query); ?>" class="filter-tab <?php echo $status_filter === 'pending' ? 'active' : ''; ?>">
                            <i class='bx bxs-time'></i>
                            Pending
                            <span class="filter-tab-count"><?php echo $counts['pending'] ?? 0; ?></span>
                        </a>
                        <a href="?status=processing&severity=<?php echo $severity_filter; ?>&date=<?php echo $date_filter; ?>&search=<?php echo urlencode($search_query); ?>" class="filter-tab <?php echo $status_filter === 'processing' ? 'active' : ''; ?>">
                            <i class='bx bxs-refresh'></i>
                            Processing
                            <span class="filter-tab-count"><?php echo $counts['processing'] ?? 0; ?></span>
                        </a>
                        <a href="?status=responded&severity=<?php echo $severity_filter; ?>&date=<?php echo $date_filter; ?>&search=<?php echo urlencode($search_query); ?>" class="filter-tab <?php echo $status_filter === 'responded' ? 'active' : ''; ?>">
                            <i class='bx bxs-check-circle'></i>
                            Responded
                            <span class="filter-tab-count"><?php echo $counts['responded'] ?? 0; ?></span>
                        </a>
                        <a href="?status=closed&severity=<?php echo $severity_filter; ?>&date=<?php echo $date_filter; ?>&search=<?php echo urlencode($search_query); ?>" class="filter-tab <?php echo $status_filter === 'closed' ? 'active' : ''; ?>">
                            <i class='bx bxs-check-double'></i>
                            Closed
                            <span class="filter-tab-count"><?php echo $counts['closed'] ?? 0; ?></span>
                        </a>
                    </div>
                    
                    <!-- Emergency Level Filters -->
                    <div class="filter-tabs">
                        <a href="?status=<?php echo $status_filter; ?>&severity=all&date=<?php echo $date_filter; ?>&search=<?php echo urlencode($search_query); ?>" class="filter-tab <?php echo $severity_filter === 'all' ? 'active' : ''; ?>">
                            <i class='bx bxs-layer'></i>
                            All Levels
                        </a>
                        <a href="?status=<?php echo $status_filter; ?>&severity=critical&date=<?php echo $date_filter; ?>&search=<?php echo urlencode($search_query); ?>" class="filter-tab <?php echo $severity_filter === 'critical' ? 'active' : ''; ?>">
                            <i class='bx bxs-alarm'></i>
                            Critical
                            <span class="filter-tab-count"><?php echo $counts['critical'] ?? 0; ?></span>
                        </a>
                        <a href="?status=<?php echo $status_filter; ?>&severity=high&date=<?php echo $date_filter; ?>&search=<?php echo urlencode($search_query); ?>" class="filter-tab <?php echo $severity_filter === 'high' ? 'active' : ''; ?>">
                            <i class='bx bxs-error'></i>
                            High
                            <span class="filter-tab-count"><?php echo $counts['high'] ?? 0; ?></span>
                        </a>
                        <a href="?status=<?php echo $status_filter; ?>&severity=medium&date=<?php echo $date_filter; ?>&search=<?php echo urlencode($search_query); ?>" class="filter-tab <?php echo $severity_filter === 'medium' ? 'active' : ''; ?>">
                            <i class='bx bxs-info-circle'></i>
                            Medium
                            <span class="filter-tab-count"><?php echo $counts['medium'] ?? 0; ?></span>
                        </a>
                        <a href="?status=<?php echo $status_filter; ?>&severity=low&date=<?php echo $date_filter; ?>&search=<?php echo urlencode($search_query); ?>" class="filter-tab <?php echo $severity_filter === 'low' ? 'active' : ''; ?>">
                            <i class='bx bxs-check-circle'></i>
                            Low
                            <span class="filter-tab-count"><?php echo $counts['low'] ?? 0; ?></span>
                        </a>
                    </div>
                    
                    <!-- FIXED FILTERS CONTAINER -->
                    <div class="filters-container">
                        <div class="filter-header">
                            <h3 class="filter-title">
                                <i class='bx bx-filter'></i>
                                Advanced Filters
                            </h3>
                        </div>
                        
                        <form method="GET" id="filter-form">
                            <div class="filter-row">
                                <div class="filter-group">
                                    <label class="filter-label">
                                        <i class='bx bx-calendar'></i>
                                        Date Range
                                    </label>
                                    <select class="filter-select" name="date">
                                        <?php foreach ($date_options as $value => $label): ?>
                                            <option value="<?php echo $value; ?>" <?php echo $date_filter === $value ? 'selected' : ''; ?>>
                                                <?php echo $label; ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                
                                <div class="filter-group">
                                    <label class="filter-label">
                                        <i class='bx bx-search'></i>
                                        Search Incidents
                                    </label>
                                    <input type="text" class="filter-input" name="search" placeholder="Search by ID, title, location, caller..." value="<?php echo htmlspecialchars($search_query); ?>">
                                </div>
                            </div>
                            
                            <div class="filter-actions">
                                <a href="update_status.php" class="filter-button clear-filters">
                                    <i class='bx bx-x'></i>
                                    Clear All Filters
                                </a>
                                <button type="submit" class="filter-button">
                                    <i class='bx bx-filter'></i>
                                    Apply Filters
                                </button>
                            </div>
                            
                            <!-- Hidden fields to preserve other filters -->
                            <input type="hidden" name="status" value="<?php echo $status_filter; ?>">
                            <input type="hidden" name="severity" value="<?php echo $severity_filter; ?>">
                        </form>
                    </div>
                    
                    <!-- Incidents Table -->
                    <div class="incidents-table">
                        <div class="table-header">
                            <div>ID</div>
                            <div>Type</div>
                            <div>Description</div>
                            <div>Location</div>
                            <div>Emergency</div>
                            <div>Status</div>
                            <div>Reported</div>
                            <div>Actions</div>
                        </div>
                        <div class="incident-table-container">
                            <?php if (count($incidents) > 0): ?>
                                <?php foreach ($incidents as $incident): ?>
                                    <?php 
                                    $reportedDate = new DateTime($incident['created_at']);
                                    $emergencyClass = 'emergency-' . strtolower($incident['severity']);
                                    $statusClass = 'status-' . strtolower($incident['status']);
                                    ?>
                                    <div class="table-row">
                                        <div class="table-cell" data-label="ID">
                                            <div class="incident-id">#<?php echo $incident['external_id']; ?></div>
                                        </div>
                                        <div class="table-cell" data-label="Type">
                                            <?php 
                                            // Display incident type with icon
                                            if ($incident['emergency_type'] === 'fire') {
                                                echo '<i class="bx bxs-truck" style="color: #dc2626; margin-right: 5px;"></i>Fire';
                                            } elseif ($incident['emergency_type'] === 'rescue') {
                                                echo '<i class="bx bxs-first-aid" style="color: #3b82f6; margin-right: 5px;"></i>Rescue';
                                            } else {
                                                echo htmlspecialchars(ucfirst($incident['emergency_type']));
                                            }
                                            ?>
                                            <?php if ($incident['rescue_category']): ?>
                                                <div style="font-size: 10px; color: var(--text-light); margin-top: 2px;">
                                                    <?php echo htmlspecialchars(str_replace('_', ' ', $incident['rescue_category'])); ?>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                        <div class="table-cell" data-label="Description">
                                            <div class="incident-description"><?php echo htmlspecialchars($incident['description']); ?></div>
                                        </div>
                                        <div class="table-cell location-cell" data-label="Location">
                                            <div class="location-text"><?php echo htmlspecialchars($incident['location']); ?></div>
                                            <?php if (isset($incident['dispatch_info']) && $incident['dispatch_info']['unit_name']): ?>
                                                <div class="dispatch-info">
                                                    <i class='bx bxs-truck'></i>
                                                    <span class="dispatch-text"><?php echo htmlspecialchars($incident['dispatch_info']['unit_name']); ?></span>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                        <div class="table-cell" data-label="Emergency">
                                            <div class="emergency-badge <?php echo $emergencyClass; ?>">
                                                <?php echo ucfirst($incident['severity']); ?>
                                            </div>
                                        </div>
                                        <div class="table-cell" data-label="Status">
                                            <div class="status-badge <?php echo $statusClass; ?>">
                                                <i class='bx <?php echo $status_icons[strtolower($incident['status'])]; ?>'></i>
                                                <?php echo ucfirst($incident['status']); ?>
                                            </div>
                                            <?php if (isset($incident['dispatch_info']) && $incident['dispatch_info']['dispatched_at']): ?>
                                                <div class="dispatch-info">
                                                    <i class='bx bxs-time-five'></i>
                                                    <span class="dispatch-text">
                                                        <?php 
                                                        $dispatchedDate = new DateTime($incident['dispatch_info']['dispatched_at']);
                                                        echo $dispatchedDate->format('M j, g:i A');
                                                        ?>
                                                    </span>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                        <div class="table-cell" data-label="Reported">
                                            <?php echo $reportedDate->format('M j, Y g:i A'); ?>
                                        </div>
                                        <div class="table-cell" data-label="Actions">
                                            <div class="table-actions">
                                                <button class="action-button view-button" onclick="viewIncidentDetails(<?php echo $incident['id']; ?>)">
                                                    <i class='bx bxs-info-circle'></i>
                                                    View Details
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <div class="no-incidents">
                                    <div class="no-incidents-icon">
                                        <i class='bx bxs-alarm-off'></i>
                                    </div>
                                    <h3>No Incidents Found</h3>
                                    <p>No fire/rescue incidents match your current filters.</p>
                                    <?php if ($status_filter !== 'all' || $severity_filter !== 'all' || $date_filter !== '' || $search_query !== ''): ?>
                                        <a href="update_status.php" class="filter-button" style="margin-top: 16px;">
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
            });
            
            // User profile dropdown
            const userProfile = document.getElementById('user-profile');
            const userDropdown = document.getElementById('user-dropdown');
            
            userProfile.addEventListener('click', function(e) {
                e.stopPropagation();
                userDropdown.classList.toggle('show');
            });
            
            // Close dropdowns when clicking outside
            document.addEventListener('click', function() {
                userDropdown.classList.remove('show');
            });
            
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
            
            // Filter form submission
            const filterForm = document.getElementById('filter-form');
            
            // Handle filter select changes
            filterForm.querySelectorAll('select[name="date"]').forEach(select => {
                select.addEventListener('change', function() {
                    filterForm.submit();
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
        
        function viewIncidentDetails(incidentId) {
            const detailsModal = document.getElementById('details-modal');
            const detailsContent = document.getElementById('details-content');
            
            // Show loading
            detailsContent.innerHTML = '<div style="text-align: center; padding: 40px;"><i class="bx bx-loader bx-spin" style="font-size: 48px; color: var(--primary-color);"></i><p>Loading incident details...</p></div>';
            
            // Fetch incident details via AJAX
            fetch(`get_incident_details.php?id=${incidentId}`)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        const incident = data.incident;
                        
                        let detailsHtml = `
                            <div class="incident-details">
                                <h3 style="margin-bottom: 20px; color: var(--primary-color);">Incident #${incident.external_id}</h3>
                                
                                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 20px;">
                                    <div>
                                        <h4>Basic Information</h4>
                                        <p><strong>Title:</strong> ${incident.title || 'N/A'}</p>
                                        <p><strong>Type:</strong> ${incident.emergency_type}</p>
                                        <p><strong>Severity:</strong> <span class="emergency-badge emergency-${incident.severity.toLowerCase()}">${incident.severity}</span></p>
                                        <p><strong>Status:</strong> <span class="status-badge status-${incident.status.toLowerCase()}">${incident.status}</span></p>
                                        <p><strong>Dispatch Status:</strong> ${incident.dispatch_status}</p>
                                    </div>
                                    <div>
                                        <h4>Location & Contact</h4>
                                        <p><strong>Location:</strong> ${incident.location}</p>
                                        <p><strong>Barangay:</strong> ${incident.affected_barangays || 'N/A'}</p>
                                        <p><strong>Caller:</strong> ${incident.caller_name}</p>
                                        <p><strong>Phone:</strong> ${incident.caller_phone}</p>
                                        <p><strong>Reported:</strong> ${new Date(incident.created_at).toLocaleString()}</p>
                                    </div>
                                </div>
                                
                                <div style="margin-bottom: 20px;">
                                    <h4>Description</h4>
                                    <p>${incident.description}</p>
                                </div>`;
                        
                        if (incident.dispatch_info) {
                            detailsHtml += `
                                <div style="margin-bottom: 20px; padding: 15px; background: rgba(220, 38, 38, 0.05); border-radius: 10px; border-left: 4px solid var(--primary-color);">
                                    <h4>Dispatch Information</h4>
                                    <p><strong>Unit:</strong> ${incident.dispatch_info.unit_name} (${incident.dispatch_info.unit_code})</p>
                                    <p><strong>Dispatch Status:</strong> ${incident.dispatch_info.dispatch_status}</p>
                                    <p><strong>Dispatched:</strong> ${new Date(incident.dispatch_info.dispatched_at).toLocaleString()}</p>
                                    <p><strong>Last Update:</strong> ${incident.dispatch_info.status_updated_at ? new Date(incident.dispatch_info.status_updated_at).toLocaleString() : 'N/A'}</p>
                                </div>`;
                        }
                        
                        if (incident.responded_at) {
                            detailsHtml += `
                                <div style="margin-bottom: 20px; padding: 15px; background: rgba(16, 185, 129, 0.05); border-radius: 10px; border-left: 4px solid var(--success);">
                                    <h4>Response Information</h4>
                                    <p><strong>Responded At:</strong> ${new Date(incident.responded_at).toLocaleString()}</p>
                                    <p><strong>Responded By:</strong> ${incident.responded_by || 'Emergency Response Team'}</p>
                                </div>`;
                        }
                        
                        if (incident.notes) {
                            detailsHtml += `
                                <div style="margin-bottom: 20px;">
                                    <h4>Notes</h4>
                                    <p style="white-space: pre-wrap;">${incident.notes}</p>
                                </div>`;
                        }
                        
                        detailsHtml += `</div>`;
                        
                        detailsContent.innerHTML = detailsHtml;
                    } else {
                        detailsContent.innerHTML = `<div style="text-align: center; padding: 40px; color: var(--danger);"><i class="bx bx-error" style="font-size: 48px;"></i><p>${data.message || 'Failed to load incident details'}</p></div>`;
                    }
                })
                .catch(error => {
                    detailsContent.innerHTML = `<div style="text-align: center; padding: 40px; color: var(--danger);"><i class="bx bx-error" style="font-size: 48px;"></i><p>Error loading details: ${error.message}</p></div>`;
                });
            
            // Open modal
            detailsModal.classList.add('active');
        }
        
        function closeDetailsModal() {
            document.getElementById('details-modal').classList.remove('active');
        }
        
        function getStatusIcon(status) {
            const icons = {
                'pending': 'bx-time',
                'processing': 'bx-refresh',
                'responded': 'bx-check-circle',
                'closed': 'bx-check-double'
            };
            return icons[status.toLowerCase()] || 'bx-info-circle';
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
        
        // Handle window resize for responsive layout
        window.addEventListener('resize', addDataLabels);
    </script>
</body>
</html>