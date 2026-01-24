<?php
session_start();
require_once '../../config/db_connection.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit();
}

$user_id = $_SESSION['user_id'];
$query = "SELECT first_name, middle_name, last_name, role FROM users WHERE id = ?";
$stmt = $pdo->prepare($query);
$stmt->execute([$user_id]);
$user = $stmt->fetch();

if (!$user || $user['role'] !== 'ADMIN') {
    header("Location: ../unauthorized.php");
    exit();
}

$first_name = htmlspecialchars($user['first_name']);
$middle_name = htmlspecialchars($user['middle_name']);
$last_name = htmlspecialchars($user['last_name']);
$role = htmlspecialchars($user['role']);

$full_name = $first_name;
if (!empty($middle_name)) {
    $full_name .= " " . $middle_name;
}
$full_name .= " " . $last_name;

// Handle form submissions and filters
$filter_type = $_GET['type'] ?? 'all';
$filter_date = $_GET['date'] ?? '';
$filter_ip = $_GET['ip'] ?? '';
$filter_user = $_GET['user'] ?? '';
$search_query = $_GET['search'] ?? '';

// Build base query for login attempts
$login_query = "SELECT 
    la.id,
    la.ip_address,
    la.email,
    la.attempt_time,
    la.successful,
    u.username,
    CONCAT(u.first_name, ' ', COALESCE(u.middle_name, ''), ' ', u.last_name) as user_full_name
FROM login_attempts la
LEFT JOIN users u ON la.email = u.email
WHERE 1=1";

// Build base query for registration attempts
$registration_query = "SELECT 
    ra.id,
    ra.ip_address,
    ra.email,
    ra.attempt_time,
    ra.successful,
    u.username,
    CONCAT(u.first_name, ' ', COALESCE(u.middle_name, ''), ' ', u.last_name) as user_full_name
FROM registration_attempts ra
LEFT JOIN users u ON ra.email = u.email
WHERE 1=1";

// Build base query for incident logs (from incident_status_logs)
$incident_query = "SELECT 
    isl.id,
    isl.incident_id,
    isl.old_status,
    isl.new_status,
    isl.changed_by,
    isl.change_notes,
    isl.changed_at,
    CONCAT(u.first_name, ' ', COALESCE(u.middle_name, ''), ' ', u.last_name) as changed_by_name,
    ai.title as incident_title
FROM incident_status_logs isl
LEFT JOIN users u ON isl.changed_by = u.id
LEFT JOIN api_incidents ai ON isl.incident_id = ai.id
WHERE 1=1";

// Apply filters
$params = [];
$login_params = [];
$reg_params = [];
$incident_params = [];

// Date filter
if (!empty($filter_date)) {
    $login_query .= " AND DATE(la.attempt_time) = ?";
    $registration_query .= " AND DATE(ra.attempt_time) = ?";
    $incident_query .= " AND DATE(isl.changed_at) = ?";
    $login_params[] = $filter_date;
    $reg_params[] = $filter_date;
    $incident_params[] = $filter_date;
}

// IP filter
if (!empty($filter_ip)) {
    $login_query .= " AND la.ip_address LIKE ?";
    $registration_query .= " AND ra.ip_address LIKE ?";
    $login_params[] = "%$filter_ip%";
    $reg_params[] = "%$filter_ip%";
}

// User filter
if (!empty($filter_user)) {
    $login_query .= " AND (la.email LIKE ? OR u.username LIKE ?)";
    $registration_query .= " AND (ra.email LIKE ? OR u.username LIKE ?)";
    $incident_query .= " AND u.username LIKE ?";
    $login_params[] = "%$filter_user%";
    $login_params[] = "%$filter_user%";
    $reg_params[] = "%$filter_user%";
    $reg_params[] = "%$filter_user%";
    $incident_params[] = "%$filter_user%";
}

// Search query
if (!empty($search_query)) {
    $login_query .= " AND (la.ip_address LIKE ? OR la.email LIKE ? OR la.attempt_time LIKE ?)";
    $registration_query .= " AND (ra.ip_address LIKE ? OR ra.email LIKE ? OR ra.attempt_time LIKE ?)";
    $incident_query .= " AND (isl.old_status LIKE ? OR isl.new_status LIKE ? OR isl.change_notes LIKE ? OR ai.title LIKE ?)";
    
    $search_param = "%$search_query%";
    $login_params[] = $search_param;
    $login_params[] = $search_param;
    $login_params[] = $search_param;
    
    $reg_params[] = $search_param;
    $reg_params[] = $search_param;
    $reg_params[] = $search_param;
    
    $incident_params[] = $search_param;
    $incident_params[] = $search_param;
    $incident_params[] = $search_param;
    $incident_params[] = $search_param;
}

// Order by
$login_query .= " ORDER BY la.attempt_time DESC";
$registration_query .= " ORDER BY ra.attempt_time DESC";
$incident_query .= " ORDER BY isl.changed_at DESC";

// Execute queries based on filter type
$login_attempts = [];
$registration_attempts = [];
$incident_logs = [];
$stats = [];

if ($filter_type === 'all' || $filter_type === 'login') {
    $stmt = $pdo->prepare($login_query);
    $stmt->execute($login_params);
    $login_attempts = $stmt->fetchAll();
}

if ($filter_type === 'all' || $filter_type === 'registration') {
    $stmt = $pdo->prepare($registration_query);
    $stmt->execute($reg_params);
    $registration_attempts = $stmt->fetchAll();
}

if ($filter_type === 'all' || $filter_type === 'incident') {
    $stmt = $pdo->prepare($incident_query);
    $stmt->execute($incident_params);
    $incident_logs = $stmt->fetchAll();
}

// Get statistics
$stats_query = "SELECT 
    (SELECT COUNT(*) FROM login_attempts WHERE DATE(attempt_time) = CURDATE()) as today_logins,
    (SELECT COUNT(*) FROM login_attempts WHERE successful = 0 AND DATE(attempt_time) = CURDATE()) as failed_logins_today,
    (SELECT COUNT(*) FROM registration_attempts WHERE DATE(attempt_time) = CURDATE()) as today_registrations,
    (SELECT COUNT(*) FROM login_attempts WHERE successful = 1) as total_successful_logins,
    (SELECT COUNT(*) FROM login_attempts WHERE successful = 0) as total_failed_logins,
    (SELECT COUNT(*) FROM registration_attempts WHERE successful = 1) as total_successful_registrations,
    (SELECT COUNT(DISTINCT ip_address) FROM login_attempts WHERE DATE(attempt_time) = CURDATE()) as unique_ips_today,
    (SELECT COUNT(*) FROM incident_status_logs WHERE DATE(changed_at) = CURDATE()) as incident_changes_today";
$stats = $pdo->query($stats_query)->fetch();

// Get recent suspicious activity
$suspicious_query = "SELECT 
    la.*,
    u.username
FROM login_attempts la
LEFT JOIN users u ON la.email = u.email
WHERE la.successful = 0 
ORDER BY la.attempt_time DESC 
LIMIT 10";
$suspicious_activity = $pdo->query($suspicious_query)->fetchAll();

// Get top IPs with failed attempts
$top_ips_query = "SELECT 
    ip_address,
    COUNT(*) as attempt_count,
    SUM(CASE WHEN successful = 1 THEN 1 ELSE 0 END) as success_count,
    SUM(CASE WHEN successful = 0 THEN 1 ELSE 0 END) as fail_count
FROM login_attempts 
GROUP BY ip_address 
HAVING fail_count > 0 
ORDER BY fail_count DESC 
LIMIT 10";
$top_ips = $pdo->query($top_ips_query)->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Audit & Activity Logs - FRSM</title>
    <link href='https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css' rel='stylesheet'>
    <link rel="icon" type="image/png" sizes="32x32" href="../img/frsm-logo.png">
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
            --card-bg: #ffffff;
            --sidebar-bg: #ffffff;

            --glass-bg: #ffffff;
            --glass-border: #e5e7eb;
            --glass-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
            
            --icon-red: #ef4444;
            --icon-blue: #3b82f6;
            --icon-green: #10b981;
            --icon-purple: #8b5cf6;
            --icon-yellow: #f59e0b;
            --icon-indigo: #6366f1;
            --icon-cyan: #06b6d4;
            --icon-orange: #f97316;
            --icon-pink: #ec4899;
            --icon-teal: #14b8a6;
            
            --icon-bg-red: #fef2f2;
            --icon-bg-blue: #eff6ff;
            --icon-bg-green: #f0fdf4;
            --icon-bg-purple: #faf5ff;
            --icon-bg-yellow: #fefce8;
            --icon-bg-indigo: #eef2ff;
            --icon-bg-cyan: #ecfeff;
            --icon-bg-orange: #fff7ed;
            --icon-bg-pink: #fdf2f8;
            --icon-bg-teal: #f0fdfa;

            --chart-red: #ef4444;
            --chart-orange: #f97316;
            --chart-yellow: #f59e0b;
            --chart-green: #10b981;
            --chart-blue: #3b82f6;
            --chart-purple: #8b5cf6;
            --chart-pink: #ec4899;

            /* Additional variables for consistency */
            --primary: var(--primary-color);
            --primary-dark: var(--primary-dark);
            --secondary: var(--secondary-color);
            --success: var(--icon-green);
            --warning: var(--icon-yellow);
            --danger: var(--primary-color);
            --info: var(--icon-blue);
            --light: #f9fafb;
            --dark: #1f2937;
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
        
        /* Dark mode variables */
        .dark-mode {
            --background-color: #111827;
            --text-color: #f9fafb;
            --text-light: #9ca3af;
            --border-color: #374151;
            --card-bg: #1f2937;
            --sidebar-bg: #1f2937;
            
            --glass-bg: #1f2937;
            --glass-border: #374151;
            --glass-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.2), 0 2px 4px -1px rgba(0, 0, 0, 0.1);
            
            --icon-bg-red: #7f1d1d;
            --icon-bg-blue: #1e3a8a;
            --icon-bg-green: #065f46;
            --icon-bg-purple: #5b21b6;
            --icon-bg-yellow: #854d0e;
            --icon-bg-indigo: #3730a3;
            --icon-bg-cyan: #155e75;
            --icon-bg-orange: #9a3412;
            --icon-bg-pink: #831843;
            --icon-bg-teal: #134e4a;
        }

        /* Font and size from reference */
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            font-size: 14px;
            line-height: 1.5;
            color: var(--text-color);
            background-color: var(--background-color);
            margin: 0;
            padding: 0;
        }

        h1, h2, h3, h4, h5, h6 {
            font-weight: 600;
        }

        .dashboard-title {
            font-size: 28px;
            font-weight: 800;
        }

        .dashboard-subtitle {
            font-size: 16px;
        }

        /* COMPLETELY NEW LAYOUT DESIGN */
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

        /* Audit Logs Container */
        .audit-container {
            padding: 0 40px 40px;
        }

        .audit-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 30px;
            flex-wrap: wrap;
            gap: 20px;
        }

        /* Statistics Cards */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .stat-card {
            background: var(--glass-bg);
            border: 1px solid var(--glass-border);
            box-shadow: var(--glass-shadow);
            border-radius: 16px;
            padding: 20px;
            display: flex;
            align-items: center;
            gap: 15px;
            transition: all 0.3s ease;
        }

        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.15);
        }

        .stat-icon {
            width: 50px;
            height: 50px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
        }

        .stat-content {
            flex: 1;
        }

        .stat-value {
            font-size: 24px;
            font-weight: 700;
            margin-bottom: 4px;
        }

        .stat-label {
            font-size: 13px;
            color: var(--text-light);
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .stat-change {
            font-size: 12px;
            font-weight: 600;
        }

        .stat-change.positive {
            color: var(--success);
        }

        .stat-change.negative {
            color: var(--danger);
        }

        /* Filters Section */
        .filters-section {
            background: var(--glass-bg);
            border: 1px solid var(--glass-border);
            box-shadow: var(--glass-shadow);
            border-radius: 20px;
            padding: 25px;
            margin-bottom: 30px;
        }

        .filters-title {
            font-size: 1.2rem;
            font-weight: 600;
            margin-bottom: 20px;
            color: var(--text-color);
        }

        .filters-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-bottom: 20px;
        }

        .filter-group {
            margin-bottom: 10px;
        }

        .filter-label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: var(--text-color);
            font-size: 14px;
        }

        .filter-select {
            width: 100%;
            padding: 10px;
            border: 2px solid var(--gray-300);
            border-radius: 8px;
            font-size: 14px;
            background: var(--background-color);
            color: var(--text-color);
            cursor: pointer;
        }

        .dark-mode .filter-select {
            border-color: #475569;
            background: #1e293b;
        }

        .filter-input {
            width: 100%;
            padding: 10px;
            border: 2px solid var(--gray-300);
            border-radius: 8px;
            font-size: 14px;
            background: var(--background-color);
            color: var(--text-color);
        }

        .dark-mode .filter-input {
            border-color: #475569;
            background: #1e293b;
        }

        .filter-actions {
            display: flex;
            gap: 10px;
            justify-content: flex-end;
            padding-top: 10px;
            border-top: 1px solid var(--border-color);
        }

        .btn-filter {
            padding: 10px 20px;
            border: none;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .btn-apply {
            background: var(--primary-color);
            color: white;
        }

        .btn-apply:hover {
            background: var(--primary-dark);
        }

        .btn-reset {
            background: var(--gray-200);
            color: var(--text-color);
        }

        .dark-mode .btn-reset {
            background: #334155;
        }

        .btn-reset:hover {
            background: var(--gray-300);
        }

        .dark-mode .btn-reset:hover {
            background: #475569;
        }

        /* Tabs */
        .audit-tabs {
            display: flex;
            gap: 10px;
            margin-bottom: 20px;
            border-bottom: 2px solid var(--border-color);
            padding-bottom: 10px;
        }

        .audit-tab {
            padding: 10px 20px;
            border: none;
            background: none;
            font-size: 14px;
            font-weight: 600;
            color: var(--text-light);
            cursor: pointer;
            border-radius: 8px 8px 0 0;
            transition: all 0.3s ease;
            position: relative;
        }

        .audit-tab:hover {
            color: var(--text-color);
            background: var(--gray-100);
        }

        .dark-mode .audit-tab:hover {
            background: #374151;
        }

        .audit-tab.active {
            color: var(--primary-color);
        }

        .audit-tab.active::after {
            content: '';
            position: absolute;
            bottom: -12px;
            left: 0;
            width: 100%;
            height: 3px;
            background: var(--primary-color);
            border-radius: 2px;
        }

        .tab-badge {
            background: var(--primary-color);
            color: white;
            padding: 2px 8px;
            border-radius: 12px;
            font-size: 11px;
            margin-left: 6px;
        }

        /* Logs Table */
        .logs-table-container {
            background: var(--glass-bg);
            border: 1px solid var(--glass-border);
            box-shadow: var(--glass-shadow);
            border-radius: 20px;
            overflow: hidden;
            margin-bottom: 30px;
        }

        .logs-table {
            width: 100%;
            border-collapse: collapse;
        }

        .logs-table th {
            padding: 18px;
            text-align: left;
            font-weight: 600;
            color: var(--text-color);
            border-bottom: 2px solid var(--border-color);
            background: rgba(255, 255, 255, 0.1);
            user-select: none;
        }

        .logs-table td {
            padding: 16px 18px;
            border-bottom: 1px solid var(--border-color);
            vertical-align: middle;
        }

        .logs-table tr:hover {
            background: rgba(255, 255, 255, 0.05);
        }

        /* Status Badges */
        .status-badge {
            display: inline-block;
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .status-success {
            background: linear-gradient(135deg, rgba(16, 185, 129, 0.1), rgba(5, 150, 105, 0.1));
            color: var(--success);
            border: 1px solid rgba(16, 185, 129, 0.3);
        }

        .status-failed {
            background: linear-gradient(135deg, rgba(220, 38, 38, 0.1), rgba(185, 28, 28, 0.1));
            color: var(--danger);
            border: 1px solid rgba(220, 38, 38, 0.3);
        }

        .status-pending {
            background: linear-gradient(135deg, rgba(245, 158, 11, 0.1), rgba(217, 119, 6, 0.1));
            color: var(--warning);
            border: 1px solid rgba(245, 158, 11, 0.3);
        }

        /* IP Address styling */
        .ip-address {
            font-family: 'Courier New', monospace;
            font-size: 13px;
            background: var(--gray-100);
            padding: 4px 8px;
            border-radius: 4px;
            color: var(--text-color);
        }

        .dark-mode .ip-address {
            background: #374151;
        }

        /* Time styling */
        .log-time {
            color: var(--text-light);
            font-size: 13px;
        }

        /* User info */
        .user-info-cell {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .user-avatar-small {
            width: 32px;
            height: 32px;
            border-radius: 50%;
            object-fit: cover;
        }

        .user-name {
            font-weight: 600;
            color: var(--text-color);
        }

        .user-email {
            font-size: 12px;
            color: var(--text-light);
        }

        /* Incident status change */
        .status-change {
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .status-arrow {
            color: var(--text-light);
        }

        /* Export Button */
        .btn-export {
            padding: 10px 20px;
            background: var(--success);
            color: white;
            border: none;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .btn-export:hover {
            background: #0da271;
            transform: translateY(-2px);
        }

        /* No data message */
        .no-data {
            text-align: center;
            padding: 40px;
            color: var(--text-light);
        }

        /* Responsive Design */
        @media (max-width: 768px) {
            .dashboard-header {
                padding: 40px 25px 30px;
                border-radius: 0 0 20px 20px;
            }
            
            .dashboard-title {
                font-size: 2.2rem;
            }
            
            .audit-container {
                padding: 0 25px 30px;
            }
            
            .audit-header {
                flex-direction: column;
                align-items: flex-start;
            }
            
            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }
            
            .filters-grid {
                grid-template-columns: 1fr;
            }
            
            .logs-table {
                display: block;
                overflow-x: auto;
            }
            
            .audit-tabs {
                overflow-x: auto;
                flex-wrap: nowrap;
            }
        }

        @media (max-width: 480px) {
            .stats-grid {
                grid-template-columns: 1fr;
            }
            
            .audit-tabs {
                flex-direction: column;
            }
            
            .audit-tab {
                text-align: left;
            }
            
            .audit-tab.active::after {
                display: none;
            }
        }
    </style>
</head>
<body>
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
                    <div id="user-management" class="submenu active">
                        <a href="manage_users.php" class="submenu-item">Manage Users</a>
                        <a href="role_control.php" class="submenu-item">Role Control</a>
                        <a href="audit_logs.php" class="submenu-item active">Audit & Activity Logs</a>
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
                     
                        <a href="../fir/receive_data.php" class="submenu-item">Recieve Data</a>
                         <a href="../fir/track_status.php" class="submenu-item">Track Status</a>
                        <a href="../fir/update_status.php" class="submenu-item">Update Status</a>
                        <a href="../fir/incidents_analytics.php" class="submenu-item">Incidents Analytics</a>
                        
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
                            <input type="text" placeholder="Search audit logs..." class="search-input" id="search-input">
                            <kbd class="search-shortcut">🔍</kbd>
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
                        <button class="header-button">
                            <svg class="header-button-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"></path>
                            </svg>
                        </button>
                        <div class="user-profile" id="user-profile">
                            <img src="../../img/rei.jfif" alt="User" class="user-avatar">
                            <div class="user-info">
                                <p class="user-name"><?php echo $full_name; ?></p>
                                <p class="user-email"><?php echo $role; ?></p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Audit Logs Content -->
            <div class="dashboard-content">
                <!-- Hero Header -->
                <div class="dashboard-header">
                    <div>
                        <h1 class="dashboard-title">Audit & Activity Logs</h1>
                        <p class="dashboard-subtitle">Monitor system access, user activities, and security events</p>
                    </div>
                </div>
                
                <!-- Audit Container -->
                <div class="audit-container">
                    <!-- Statistics -->
                    <div class="stats-grid">
                        <div class="stat-card">
                            <div class="stat-icon" style="background: var(--icon-bg-blue); color: var(--icon-blue);">
                                <i class='bx bx-log-in'></i>
                            </div>
                            <div class="stat-content">
                                <div class="stat-value"><?php echo $stats['today_logins'] ?? 0; ?></div>
                                <div class="stat-label">Today's Logins</div>
                            </div>
                        </div>
                        
                        <div class="stat-card">
                            <div class="stat-icon" style="background: var(--icon-bg-red); color: var(--icon-red);">
                                <i class='bx bx-error'></i>
                            </div>
                            <div class="stat-content">
                                <div class="stat-value"><?php echo $stats['failed_logins_today'] ?? 0; ?></div>
                                <div class="stat-label">Failed Logins Today</div>
                            </div>
                        </div>
                        
                        <div class="stat-card">
                            <div class="stat-icon" style="background: var(--icon-bg-green); color: var(--icon-green);">
                                <i class='bx bx-user-plus'></i>
                            </div>
                            <div class="stat-content">
                                <div class="stat-value"><?php echo $stats['today_registrations'] ?? 0; ?></div>
                                <div class="stat-label">Today's Registrations</div>
                            </div>
                        </div>
                        
                        <div class="stat-card">
                            <div class="stat-icon" style="background: var(--icon-bg-purple); color: var(--icon-purple);">
                                <i class='bx bx-shield-alt'></i>
                            </div>
                            <div class="stat-content">
                                <div class="stat-value"><?php echo $stats['unique_ips_today'] ?? 0; ?></div>
                                <div class="stat-label">Unique IPs Today</div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Filters -->
                    <div class="filters-section">
                        <h3 class="filters-title">Filter Logs</h3>
                        <form method="GET" id="filter-form">
                            <div class="filters-grid">
                                <div class="filter-group">
                                    <label class="filter-label">Log Type</label>
                                    <select class="filter-select" name="type" id="log-type">
                                        <option value="all" <?php echo $filter_type === 'all' ? 'selected' : ''; ?>>All Logs</option>
                                        <option value="login" <?php echo $filter_type === 'login' ? 'selected' : ''; ?>>Login Attempts</option>
                                        <option value="registration" <?php echo $filter_type === 'registration' ? 'selected' : ''; ?>>Registration Attempts</option>
                                        <option value="incident" <?php echo $filter_type === 'incident' ? 'selected' : ''; ?>>Incident Changes</option>
                                    </select>
                                </div>
                                
                                <div class="filter-group">
                                    <label class="filter-label">Date</label>
                                    <input type="date" class="filter-input" name="date" value="<?php echo htmlspecialchars($filter_date); ?>" id="log-date">
                                </div>
                                
                                <div class="filter-group">
                                    <label class="filter-label">IP Address</label>
                                    <input type="text" class="filter-input" name="ip" value="<?php echo htmlspecialchars($filter_ip); ?>" placeholder="e.g., 192.168.1.1">
                                </div>
                                
                                <div class="filter-group">
                                    <label class="filter-label">User/Email</label>
                                    <input type="text" class="filter-input" name="user" value="<?php echo htmlspecialchars($filter_user); ?>" placeholder="Username or email">
                                </div>
                                
                                <div class="filter-group">
                                    <label class="filter-label">Search</label>
                                    <input type="text" class="filter-input" name="search" value="<?php echo htmlspecialchars($search_query); ?>" placeholder="Search in logs...">
                                </div>
                            </div>
                            
                            <div class="filter-actions">
                                <button type="button" class="btn-filter btn-reset" onclick="resetFilters()">Reset Filters</button>
                                <button type="submit" class="btn-filter btn-apply">Apply Filters</button>
                            </div>
                        </form>
                    </div>
                    
                    <!-- Tabs -->
                    <div class="audit-tabs" id="audit-tabs">
                        <button type="button" class="audit-tab <?php echo $filter_type === 'all' ? 'active' : ''; ?>" data-tab="all">
                            All Logs
                            <span class="tab-badge"><?php echo count($login_attempts) + count($registration_attempts) + count($incident_logs); ?></span>
                        </button>
                        <button type="button" class="audit-tab <?php echo $filter_type === 'login' ? 'active' : ''; ?>" data-tab="login">
                            Login Attempts
                            <span class="tab-badge"><?php echo count($login_attempts); ?></span>
                        </button>
                        <button type="button" class="audit-tab <?php echo $filter_type === 'registration' ? 'active' : ''; ?>" data-tab="registration">
                            Registration Attempts
                            <span class="tab-badge"><?php echo count($registration_attempts); ?></span>
                        </button>
                        <button type="button" class="audit-tab <?php echo $filter_type === 'incident' ? 'active' : ''; ?>" data-tab="incident">
                            Incident Changes
                            <span class="tab-badge"><?php echo count($incident_logs); ?></span>
                        </button>
                    </div>
                    
                    <!-- Export Button -->
                    <div style="margin-bottom: 20px; display: flex; justify-content: flex-end;">
                        <button type="button" class="btn-export" onclick="exportLogs()">
                            <i class='bx bxs-download'></i>
                            Export Logs
                        </button>
                    </div>
                    
                    <!-- Logs Table -->
                    <div class="logs-table-container">
                        <?php if ($filter_type === 'all' || $filter_type === 'login'): ?>
                            <?php if (!empty($login_attempts)): ?>
                                <table class="logs-table">
                                    <thead>
                                        <tr>
                                            <th width="50">ID</th>
                                            <th>IP Address</th>
                                            <th>User/Email</th>
                                            <th>Attempt Time</th>
                                            <th>Status</th>
                                            <th>Details</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($login_attempts as $log): ?>
                                            <tr>
                                                <td>#<?php echo $log['id']; ?></td>
                                                <td>
                                                    <span class="ip-address"><?php echo htmlspecialchars($log['ip_address']); ?></span>
                                                </td>
                                                <td>
                                                    <?php if ($log['username']): ?>
                                                        <div class="user-info-cell">
                                                            <div>
                                                                <div class="user-name"><?php echo htmlspecialchars($log['username']); ?></div>
                                                                <div class="user-email"><?php echo htmlspecialchars($log['email']); ?></div>
                                                            </div>
                                                        </div>
                                                    <?php else: ?>
                                                        <div class="user-email"><?php echo htmlspecialchars($log['email']); ?></div>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <div class="log-time">
                                                        <?php 
                                                        $time = new DateTime($log['attempt_time']);
                                                        echo $time->format('M d, Y H:i:s');
                                                        ?>
                                                    </div>
                                                </td>
                                                <td>
                                                    <span class="status-badge <?php echo $log['successful'] ? 'status-success' : 'status-failed'; ?>">
                                                        <?php echo $log['successful'] ? 'SUCCESS' : 'FAILED'; ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <?php if ($log['successful']): ?>
                                                        Successful login attempt
                                                    <?php else: ?>
                                                        Failed login attempt
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            <?php else: ?>
                                <div class="no-data">
                                    <p>No login attempts found with the current filters.</p>
                                </div>
                            <?php endif; ?>
                        <?php endif; ?>
                        
                        <?php if ($filter_type === 'registration'): ?>
                            <?php if (!empty($registration_attempts)): ?>
                                <table class="logs-table">
                                    <thead>
                                        <tr>
                                            <th width="50">ID</th>
                                            <th>IP Address</th>
                                            <th>Email</th>
                                            <th>Attempt Time</th>
                                            <th>Status</th>
                                            <th>Details</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($registration_attempts as $log): ?>
                                            <tr>
                                                <td>#<?php echo $log['id']; ?></td>
                                                <td>
                                                    <span class="ip-address"><?php echo htmlspecialchars($log['ip_address']); ?></span>
                                                </td>
                                                <td>
                                                    <div class="user-email"><?php echo htmlspecialchars($log['email']); ?></div>
                                                    <?php if ($log['username']): ?>
                                                        <small>Registered as: <?php echo htmlspecialchars($log['username']); ?></small>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <div class="log-time">
                                                        <?php 
                                                        $time = new DateTime($log['attempt_time']);
                                                        echo $time->format('M d, Y H:i:s');
                                                        ?>
                                                    </div>
                                                </td>
                                                <td>
                                                    <span class="status-badge <?php echo $log['successful'] ? 'status-success' : 'status-failed'; ?>">
                                                        <?php echo $log['successful'] ? 'SUCCESS' : 'FAILED'; ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <?php if ($log['successful']): ?>
                                                        Successful registration
                                                    <?php else: ?>
                                                        Failed registration attempt
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            <?php else: ?>
                                <div class="no-data">
                                    <p>No registration attempts found with the current filters.</p>
                                </div>
                            <?php endif; ?>
                        <?php endif; ?>
                        
                        <?php if ($filter_type === 'incident'): ?>
                            <?php if (!empty($incident_logs)): ?>
                                <table class="logs-table">
                                    <thead>
                                        <tr>
                                            <th width="50">ID</th>
                                            <th>Incident</th>
                                            <th>Status Change</th>
                                            <th>Changed By</th>
                                            <th>Change Time</th>
                                            <th>Notes</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($incident_logs as $log): ?>
                                            <tr>
                                                <td>#<?php echo $log['id']; ?></td>
                                                <td>
                                                    <?php if ($log['incident_title']): ?>
                                                        <strong><?php echo htmlspecialchars($log['incident_title']); ?></strong>
                                                        <div>Incident #<?php echo $log['incident_id']; ?></div>
                                                    <?php else: ?>
                                                        Incident #<?php echo $log['incident_id']; ?>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <div class="status-change">
                                                        <span class="status-badge status-pending"><?php echo htmlspecialchars($log['old_status']); ?></span>
                                                        <i class='bx bx-right-arrow-alt status-arrow'></i>
                                                        <span class="status-badge status-success"><?php echo htmlspecialchars($log['new_status']); ?></span>
                                                    </div>
                                                </td>
                                                <td>
                                                    <?php if ($log['changed_by_name']): ?>
                                                        <div class="user-name"><?php echo htmlspecialchars($log['changed_by_name']); ?></div>
                                                        <small>User ID: <?php echo $log['changed_by']; ?></small>
                                                    <?php else: ?>
                                                        System
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <div class="log-time">
                                                        <?php 
                                                        $time = new DateTime($log['changed_at']);
                                                        echo $time->format('M d, Y H:i:s');
                                                        ?>
                                                    </div>
                                                </td>
                                                <td>
                                                    <?php echo htmlspecialchars($log['change_notes'] ?? 'No notes provided'); ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            <?php else: ?>
                                <div class="no-data">
                                    <p>No incident status changes found with the current filters.</p>
                                </div>
                            <?php endif; ?>
                        <?php endif; ?>
                        
                        <?php if ($filter_type === 'all' && empty($login_attempts) && empty($registration_attempts) && empty($incident_logs)): ?>
                            <div class="no-data">
                                <p>No audit logs found with the current filters.</p>
                            </div>
                        <?php endif; ?>
                    </div>
                    
                    <!-- Additional Information -->
                    <div style="margin-top: 30px; padding: 20px; background: var(--glass-bg); border-radius: 12px; border: 1px solid var(--glass-border);">
                        <h3 style="margin-bottom: 15px; color: var(--text-color);">Security Information</h3>
                        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 20px;">
                            <div>
                                <h4 style="margin-bottom: 10px; color: var(--text-color); font-size: 14px;">Suspicious Activity</h4>
                                <?php if (!empty($suspicious_activity)): ?>
                                    <ul style="list-style: none; padding: 0; margin: 0;">
                                        <?php foreach ($suspicious_activity as $activity): ?>
                                            <li style="padding: 8px 0; border-bottom: 1px solid var(--border-color);">
                                                <div style="display: flex; justify-content: space-between; align-items: center;">
                                                    <span style="font-size: 13px;">
                                                        <span class="ip-address"><?php echo htmlspecialchars($activity['ip_address']); ?></span>
                                                        - <?php echo htmlspecialchars($activity['email'] ?? 'Unknown'); ?>
                                                    </span>
                                                    <span style="font-size: 12px; color: var(--text-light);">
                                                        <?php 
                                                        $time = new DateTime($activity['attempt_time']);
                                                        echo $time->format('H:i:s');
                                                        ?>
                                                    </span>
                                                </div>
                                            </li>
                                        <?php endforeach; ?>
                                    </ul>
                                <?php else: ?>
                                    <p style="font-size: 13px; color: var(--text-light);">No recent suspicious activity detected.</p>
                                <?php endif; ?>
                            </div>
                            
                            <div>
                                <h4 style="margin-bottom: 10px; color: var(--text-color); font-size: 14px;">Top IPs with Failed Attempts</h4>
                                <?php if (!empty($top_ips)): ?>
                                    <ul style="list-style: none; padding: 0; margin: 0;">
                                        <?php foreach ($top_ips as $ip): ?>
                                            <li style="padding: 8px 0; border-bottom: 1px solid var(--border-color);">
                                                <div style="display: flex; justify-content: space-between; align-items: center;">
                                                    <span style="font-size: 13px;">
                                                        <span class="ip-address"><?php echo htmlspecialchars($ip['ip_address']); ?></span>
                                                    </span>
                                                    <span style="font-size: 12px; color: var(--danger); font-weight: 600;">
                                                        <?php echo $ip['fail_count']; ?> failed attempts
                                                    </span>
                                                </div>
                                            </li>
                                        <?php endforeach; ?>
                                    </ul>
                                <?php else: ?>
                                    <p style="font-size: 13px; color: var(--text-light);">No IPs with multiple failed attempts.</p>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Initialize theme toggle
            initThemeToggle();
            
            // Initialize time display
            initTimeDisplay();
            
            // Initialize tabs
            initTabs();
            
            // Initialize search
            initSearch();
            
            // Set today's date as default if no date is selected
            if (!document.getElementById('log-date').value) {
                const today = new Date().toISOString().split('T')[0];
                document.getElementById('log-date').value = today;
            }
        });
        
        function initThemeToggle() {
            const themeToggle = document.getElementById('theme-toggle');
            const themeIcon = themeToggle.querySelector('i');
            const themeText = themeToggle.querySelector('span');
            
            const prefersDarkScheme = window.matchMedia('(prefers-color-scheme: dark)');
            const currentTheme = localStorage.getItem('theme');
            
            if (currentTheme === 'dark' || (!currentTheme && prefersDarkScheme.matches)) {
                document.body.classList.add('dark-mode');
                themeIcon.className = 'bx bx-sun';
                themeText.textContent = 'Light Mode';
            }
            
            themeToggle.addEventListener('click', function() {
                document.body.classList.toggle('dark-mode');
                
                let theme = 'light';
                if (document.body.classList.contains('dark-mode')) {
                    themeIcon.className = 'bx bx-sun';
                    themeText.textContent = 'Light Mode';
                    theme = 'dark';
                } else {
                    themeIcon.className = 'bx bx-moon';
                    themeText.textContent = 'Dark Mode';
                }
                
                localStorage.setItem('theme', theme);
            });
        }
        
        function initTimeDisplay() {
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
            
            updateTime();
            setInterval(updateTime, 1000);
        }
        
        function initTabs() {
            const tabs = document.querySelectorAll('.audit-tab');
            const logTypeSelect = document.getElementById('log-type');
            
            tabs.forEach(tab => {
                tab.addEventListener('click', function() {
                    const tabType = this.getAttribute('data-tab');
                    logTypeSelect.value = tabType;
                    document.getElementById('filter-form').submit();
                });
            });
        }
        
        function initSearch() {
            const searchInput = document.getElementById('search-input');
            let searchTimeout;
            
            searchInput.addEventListener('input', function() {
                clearTimeout(searchTimeout);
                searchTimeout = setTimeout(() => {
                    const form = document.getElementById('filter-form');
                    const searchField = form.querySelector('[name="search"]');
                    searchField.value = this.value;
                    form.submit();
                }, 500);
            });
        }
        
        function resetFilters() {
            const form = document.getElementById('filter-form');
            form.reset();
            form.submit();
        }
        
        function exportLogs() {
            // Get current filters
            const form = document.getElementById('filter-form');
            const formData = new FormData(form);
            const params = new URLSearchParams(formData);
            
            // Create export URL
            const exportUrl = `export_logs.php?${params.toString()}&export=1`;
            
            // Open in new tab
            window.open(exportUrl, '_blank');
        }
        
        function toggleSubmenu(id) {
            const submenu = document.getElementById(id);
            const arrow = document.querySelector(`#${id}`).previousElementSibling.querySelector('.dropdown-arrow');
            
            submenu.classList.toggle('active');
            arrow.classList.toggle('rotated');
        }
    </script>
</body>
</html>