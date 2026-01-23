<?php
session_start();
require_once '../../config/db_connection.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: ../../login.php");
    exit();
}

$user_id = $_SESSION['user_id'];

// Get user information
$query = "SELECT first_name, middle_name, last_name, role, email, avatar FROM users WHERE id = ?";
$stmt = $pdo->prepare($query);
$stmt->execute([$user_id]);
$user = $stmt->fetch();

if (!$user) {
    header("Location: ../../login.php");
    exit();
}

$first_name = htmlspecialchars($user['first_name']);
$middle_name = htmlspecialchars($user['middle_name']);
$last_name = htmlspecialchars($user['last_name']);
$role = htmlspecialchars($user['role']);
$email = htmlspecialchars($user['email']);
$avatar = htmlspecialchars($user['avatar']);

$full_name = $first_name;
if (!empty($middle_name)) {
    $full_name .= " " . $middle_name;
}
$full_name .= " " . $last_name;

// Check if user is a volunteer (USER role)
if ($role !== 'USER') {
    header("Location: ../dashboard.php");
    exit();
}

// Get volunteer ID from volunteers table
$volunteer_query = "SELECT id, first_name, last_name, contact_number FROM volunteers WHERE user_id = ?";
$volunteer_stmt = $pdo->prepare($volunteer_query);
$volunteer_stmt->execute([$user_id]);
$volunteer = $volunteer_stmt->fetch();

if (!$volunteer) {
    // User is not registered as a volunteer
    header("Location: ../dashboard.php");
    exit();
}

$volunteer_id = $volunteer['id'];
$volunteer_name = htmlspecialchars($volunteer['first_name'] . ' ' . $volunteer['last_name']);
$volunteer_contact = htmlspecialchars($volunteer['contact_number']);

// Handle filters
$status_filter = $_GET['status'] ?? 'all';
$date_filter = $_GET['date'] ?? '';
$unit_filter = $_GET['unit'] ?? '';

// Build query for duty assignments
$base_query = "
    SELECT 
        da.*,
        s.shift_date,
        s.shift_type,
        s.start_time,
        s.end_time,
        s.location as shift_location,
        s.status as shift_status,
        s.confirmation_status,
        s.notes as shift_notes,
        u.unit_name,
        u.unit_code,
        u.unit_type,
        u.location as unit_location,
        v.first_name as volunteer_first_name,
        v.last_name as volunteer_last_name,
        CONCAT(v.first_name, ' ', v.last_name) as volunteer_full_name
    FROM duty_assignments da
    LEFT JOIN shifts s ON da.shift_id = s.id
    LEFT JOIN units u ON s.unit_id = u.id
    LEFT JOIN volunteers v ON s.volunteer_id = v.id
    WHERE s.volunteer_id = ?
";

$params = [$volunteer_id];
$conditions = [];

// Apply filters
if ($status_filter !== 'all') {
    $conditions[] = "s.status = ?";
    $params[] = $status_filter;
}

if (!empty($date_filter)) {
    $conditions[] = "s.shift_date = ?";
    $params[] = $date_filter;
}

if (!empty($unit_filter)) {
    $conditions[] = "s.unit_id = ?";
    $params[] = $unit_filter;
}

if (count($conditions) > 0) {
    $base_query .= " AND " . implode(" AND ", $conditions);
}

$base_query .= " ORDER BY s.shift_date DESC, da.priority, da.duty_type";

// Get duty assignments
$duty_stmt = $pdo->prepare($base_query);
$duty_stmt->execute($params);
$duty_assignments = $duty_stmt->fetchAll();

// Get distinct units for filter dropdown
$units_query = "
    SELECT DISTINCT u.id, u.unit_name, u.unit_code
    FROM shifts s
    LEFT JOIN units u ON s.unit_id = u.id
    WHERE s.volunteer_id = ?
    ORDER BY u.unit_name
";
$units_stmt = $pdo->prepare($units_query);
$units_stmt->execute([$volunteer_id]);
$available_units = $units_stmt->fetchAll();

// Get statistics
$stats_query = "
    SELECT 
        COUNT(DISTINCT da.id) as total_duties,
        COUNT(DISTINCT CASE WHEN s.shift_date >= CURDATE() THEN da.id END) as upcoming_duties,
        COUNT(DISTINCT CASE WHEN s.shift_date < CURDATE() THEN da.id END) as past_duties,
        COUNT(DISTINCT CASE WHEN da.priority = 'primary' THEN da.id END) as primary_duties,
        COUNT(DISTINCT CASE WHEN da.priority = 'secondary' THEN da.id END) as secondary_duties,
        COUNT(DISTINCT CASE WHEN da.priority = 'support' THEN da.id END) as support_duties,
        GROUP_CONCAT(DISTINCT da.duty_type) as all_duty_types
    FROM duty_assignments da
    LEFT JOIN shifts s ON da.shift_id = s.id
    WHERE s.volunteer_id = ?
";
$stats_stmt = $pdo->prepare($stats_query);
$stats_stmt->execute([$volunteer_id]);
$duty_stats = $stats_stmt->fetch();

// Close statements
$stmt = null;
$volunteer_stmt = null;
$duty_stmt = null;
$units_stmt = null;
$stats_stmt = null;
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Duty Assignments - Fire & Rescue Services Management</title>
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

        .section-container {
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: 16px;
            padding: 30px;
            margin-bottom: 30px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
        }

        .section-title {
            font-size: 20px;
            font-weight: 700;
            color: var(--text-color);
            margin-bottom: 20px;
            padding-bottom: 12px;
            border-bottom: 2px solid var(--border-color);
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .section-title i {
            color: var(--primary-color);
        }

        .stats-container {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 15px;
            margin-bottom: 25px;
        }

        .stat-card {
            background: var(--background-color);
            border: 1px solid var(--border-color);
            border-radius: 12px;
            padding: 20px;
            text-align: center;
            transition: all 0.3s ease;
        }

        .stat-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
        }

        .stat-value {
            font-size: 28px;
            font-weight: 800;
            margin-bottom: 5px;
        }

        .stat-label {
            font-size: 12px;
            color: var(--text-light);
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }

        .filter-container {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-bottom: 20px;
            padding: 20px;
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: 12px;
        }

        .filter-group {
            display: flex;
            flex-direction: column;
        }

        .filter-label {
            font-size: 12px;
            color: var(--text-light);
            margin-bottom: 6px;
            font-weight: 600;
        }

        .filter-select, .filter-input {
            padding: 10px 12px;
            border: 1px solid var(--border-color);
            border-radius: 8px;
            background: var(--background-color);
            color: var(--text-color);
            font-size: 14px;
        }

        .filter-select:focus, .filter-input:focus {
            outline: none;
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(220, 38, 38, 0.1);
        }

        .filter-actions {
            display: flex;
            align-items: flex-end;
            gap: 10px;
        }

        .btn {
            padding: 10px 20px;
            border-radius: 10px;
            font-weight: 600;
            border: none;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 14px;
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

        .duty-card {
            background: var(--background-color);
            border: 1px solid var(--border-color);
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 20px;
            transition: all 0.3s ease;
        }

        .duty-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 16px rgba(0, 0, 0, 0.1);
        }

        .duty-card.upcoming {
            border-left: 4px solid var(--success);
        }

        .duty-card.past {
            border-left: 4px solid var(--warning);
        }

        .duty-card.cancelled {
            border-left: 4px solid var(--danger);
        }

        .duty-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
        }

        .duty-title {
            font-size: 18px;
            font-weight: 700;
            color: var(--text-color);
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .duty-title i {
            color: var(--primary-color);
        }

        .duty-status {
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            display: inline-block;
        }

        .status-scheduled {
            background: rgba(245, 158, 11, 0.1);
            color: var(--warning);
            border: 1px solid rgba(245, 158, 11, 0.2);
        }

        .status-confirmed {
            background: rgba(16, 185, 129, 0.1);
            color: var(--success);
            border: 1px solid rgba(16, 185, 129, 0.2);
        }

        .status-completed {
            background: rgba(59, 130, 246, 0.1);
            color: var(--info);
            border: 1px solid rgba(59, 130, 246, 0.2);
        }

        .status-cancelled {
            background: rgba(220, 38, 38, 0.1);
            color: var(--danger);
            border: 1px solid rgba(220, 38, 38, 0.2);
        }

        .duty-details {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-bottom: 20px;
        }

        .detail-item {
            display: flex;
            flex-direction: column;
        }

        .detail-label {
            font-size: 12px;
            color: var(--text-light);
            margin-bottom: 4px;
        }

        .detail-value {
            font-weight: 500;
            color: var(--text-color);
            font-size: 14px;
        }

        .priority-badge {
            display: inline-block;
            padding: 3px 8px;
            border-radius: 12px;
            font-size: 10px;
            font-weight: 600;
            text-transform: uppercase;
            margin-left: 8px;
        }

        .priority-primary {
            background: rgba(16, 185, 129, 0.1);
            color: var(--success);
            border: 1px solid rgba(16, 185, 129, 0.2);
        }

        .priority-secondary {
            background: rgba(245, 158, 11, 0.1);
            color: var(--warning);
            border: 1px solid rgba(245, 158, 11, 0.2);
        }

        .priority-support {
            background: rgba(59, 130, 246, 0.1);
            color: var(--info);
            border: 1px solid rgba(59, 130, 246, 0.2);
        }

        .equipment-section, .training-section {
            margin-top: 15px;
            padding: 15px;
            background: rgba(59, 130, 246, 0.05);
            border-radius: 8px;
            border-left: 3px solid var(--info);
        }

        .section-subtitle {
            font-size: 14px;
            font-weight: 600;
            color: var(--info);
            margin-bottom: 8px;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .equipment-list, .training-list {
            display: flex;
            flex-wrap: wrap;
            gap: 5px;
            margin-top: 5px;
        }

        .equipment-item, .training-item {
            background: var(--background-color);
            border: 1px solid var(--border-color);
            border-radius: 6px;
            padding: 4px 8px;
            font-size: 11px;
            color: var(--text-color);
        }

        .shift-info {
            margin-top: 15px;
            padding: 15px;
            background: rgba(220, 38, 38, 0.05);
            border-radius: 8px;
            border-left: 3px solid var(--primary-color);
        }

        .shift-info-title {
            font-size: 14px;
            font-weight: 600;
            color: var(--primary-color);
            margin-bottom: 8px;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .shift-details-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 10px;
        }

        .shift-detail-item {
            display: flex;
            flex-direction: column;
        }

        .shift-detail-label {
            font-size: 11px;
            color: var(--text-light);
            margin-bottom: 2px;
        }

        .shift-detail-value {
            font-weight: 500;
            color: var(--text-color);
            font-size: 13px;
        }

        .empty-state {
            text-align: center;
            padding: 40px 20px;
            color: var(--text-light);
        }

        .empty-state i {
            font-size: 48px;
            margin-bottom: 16px;
            opacity: 0.5;
        }

        .empty-state h3 {
            font-size: 18px;
            margin-bottom: 8px;
            color: var(--text-color);
        }

        .empty-state p {
            font-size: 14px;
            max-width: 400px;
            margin: 0 auto;
        }

        .date-badge {
            background: rgba(245, 158, 11, 0.1);
            color: var(--warning);
            padding: 2px 8px;
            border-radius: 12px;
            font-size: 11px;
            margin-left: 8px;
        }

        .today-badge {
            background: rgba(16, 185, 129, 0.1);
            color: var(--success);
            padding: 2px 8px;
            border-radius: 12px;
            font-size: 11px;
            margin-left: 8px;
        }

        @media (max-width: 992px) {
            .content-container {
                padding: 0 25px 30px;
            }
            
            .dashboard-header {
                padding: 40px 25px 30px;
            }
            
            .dashboard-title {
                font-size: 32px;
            }
            
            .section-container {
                padding: 20px;
            }
            
            .duty-details {
                grid-template-columns: 1fr;
            }
            
            .stats-container {
                grid-template-columns: repeat(2, 1fr);
            }
            
            .filter-container {
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
            
            .section-container {
                padding: 15px;
            }
            
            .stats-container {
                grid-template-columns: 1fr;
            }
            
            .duty-header {
                flex-direction: column;
                align-items: flex-start;
                gap: 10px;
            }
            
            .filter-actions {
                flex-direction: column;
            }
        }

        /* User profile dropdown styles */
        .user-profile-dropdown {
            position: absolute;
            top: 100%;
            right: 0;
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: 8px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
            min-width: 200px;
            z-index: 1000;
            display: none;
            margin-top: 10px;
        }
        
        .user-profile-dropdown.show {
            display: block;
        }
        
        .dropdown-item {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 12px 16px;
            color: var(--text-color);
            text-decoration: none;
            transition: all 0.3s ease;
        }
        
        .dropdown-item:hover {
            background: var(--gray-100);
        }
        
        .dropdown-item i {
            font-size: 18px;
        }
        
        .dropdown-divider {
            height: 1px;
            background: var(--border-color);
            margin: 4px 0;
        }
        
        .duty-type-badge {
            display: inline-block;
            padding: 3px 8px;
            border-radius: 6px;
            font-size: 10px;
            font-weight: 600;
            text-transform: uppercase;
            background: rgba(220, 38, 38, 0.1);
            color: var(--primary-color);
            border: 1px solid rgba(220, 38, 38, 0.2);
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
        <a href="../user_dashboard.php" class="menu-item" id="dashboard-menu">
            <div class="icon-box icon-bg-red">
                <i class='bx bxs-dashboard icon-red'></i>
            </div>
            <span class="font-medium">Dashboard</span>
        </a>
        
        <div class="menu-item" onclick="toggleSubmenu('fire-incident')">
            <div class="icon-box icon-bg-orange">
                <i class='bx bxs-alarm-exclamation icon-orange'></i>
            </div>
            <span class="font-medium">Fire & Incident Reporting</span>
            <svg class="dropdown-arrow menu-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
            </svg>
        </div>
        <div id="fire-incident" class="submenu">
            <a href="../fir/active_incidents.php" class="submenu-item">Active Incidents</a>
            <a href="../fir/response_history.php" class="submenu-item">Response History</a>
        </div>

          <div class="menu-item" onclick="toggleSubmenu('postincident')">
            <div class="icon-box icon-bg-pink">
                <i class='bx bxs-file-doc icon-pink'></i>
            </div>
            <span class="font-medium">Dispatch Coordination</span>
            <svg class="dropdown-arrow menu-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
            </svg>
        </div>
        <div id="postincident" class="submenu">
            <a href="../dc/suggested_unit.php" class="submenu-item">Suggested Unit</a>
            <a href="../dc/incident_location.php" class="submenu-item">Incident Location</a>
            
        </div>
        
        <div class="menu-item" onclick="toggleSubmenu('volunteer')">
            <div class="icon-box icon-bg-blue">
                <i class='bx bxs-user-detail icon-blue'></i>
            </div>
            <span class="font-medium">Volunteer Roster</span>
            <svg class="dropdown-arrow menu-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
            </svg>
        </div>
        <div id="volunteer" class="submenu">
            <a href="../vra/volunteer_list.php" class="submenu-item">Volunteer List</a>
            <a href="../vra/roles_skills.php" class="submenu-item">Roles & Skills</a>
            <a href="../vra/availability.php" class="submenu-item">Availability</a>
        </div>
        
        <div class="menu-item" onclick="toggleSubmenu('inventory')">
            <div class="icon-box icon-bg-green">
                <i class='bx bxs-cube icon-green'></i>
            </div>
            <span class="font-medium">Resource Inventory</span>
            <svg class="dropdown-arrow menu-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
            </svg>
        </div>
        <div id="inventory" class="submenu">
            <a href="../ri/equipment_list.php" class="submenu-item">Equipment List</a>
            <a href="../ri/stock_levels.php" class="submenu-item">Stock Levels</a>
            <a href="../ri/maintenance_logs.php" class="submenu-item">Maintenance Logs</a>
        </div>
        
        <div class="menu-item" onclick="toggleSubmenu('schedule')">
            <div class="icon-box icon-bg-purple">
                <i class='bx bxs-calendar icon-purple'></i>
            </div>
            <span class="font-medium">Shift & Duty Scheduling</span>
            <svg class="dropdown-arrow menu-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
            </svg>
        </div>
        <div id="schedule" class="submenu active">
            <a href="view_shifts.php" class="submenu-item">Shift Calendar</a>
              <a href="confirm_availability.php" class="submenu-item">Confirm Availability</a>
            <a href="duty_assignments.php" class="submenu-item active">Duty Assignments</a>
            <a href="attendance_logs.php" class="submenu-item">Attendance Logs</a>
        </div>
        
        <div class="menu-item" onclick="toggleSubmenu('training')">
                        <div class="icon-box icon-bg-teal">
                            <i class='bx bxs-graduation icon-teal'></i>
                        </div>
                        <span class="font-medium">Training & Certification</span>
                        <svg class="dropdown-arrow menu-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                        </svg>
                    </div>
                    <div id="training" class="submenu">
                        <a href="../tc/register_training.php" class="submenu-item">Register for Training</a>
                        <a href="../tc/training_records.php" class="submenu-item">Training Records</a>
                        <a href="../tc/certification_status.php" class="submenu-item">Certification Status</a>
                    </div>
       
    </div>
    
    <p class="menu-title" style="margin-top: 32px;">GENERAL</p>
    
    <div class="menu-items">
        <a href="../settings.php" class="menu-item">
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
                            <input type="text" placeholder="Search duty assignments..." class="search-input" id="search-input">
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
                                <img src="../../profile/uploads/avatars/<?php echo $avatar; ?>" alt="User" class="user-avatar">
                            <?php else: ?>
                                <div class="user-avatar" style="background: linear-gradient(135deg, var(--primary-color), var(--secondary-color)); color: white; display: flex; align-items: center; justify-content: center; font-weight: bold; border-radius: 50%; width: 40px; height: 40px;">
                                    <?php echo strtoupper(substr($first_name, 0, 1) . substr($last_name, 0, 1)); ?>
                                </div>
                            <?php endif; ?>
                            <div class="user-info">
                                <p class="user-name"><?php echo $full_name; ?></p>
                                <p class="user-email"><?php echo $email; ?></p>
                            </div>
                            <div class="user-profile-dropdown" id="user-dropdown">
                                <a href="../profile.php" class="dropdown-item">
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
                        <h1 class="dashboard-title">Duty Assignments</h1>
                        <p class="dashboard-subtitle">View your assigned duties for upcoming and past shifts</p>
                    </div>
                </div>
                
                <!-- Content Container -->
                <div class="content-container">
                    <!-- Duty Statistics -->
                    <div class="section-container">
                        <h3 class="section-title">
                            <i class='bx bx-stats'></i>
                            Duty Assignment Statistics
                        </h3>
                        
                        <div class="stats-container">
                            <div class="stat-card">
                                <div class="stat-value" style="color: var(--primary-color);">
                                    <?php echo $duty_stats ? $duty_stats['total_duties'] : '0'; ?>
                                </div>
                                <div class="stat-label">Total Duties</div>
                            </div>
                            
                            <div class="stat-card">
                                <div class="stat-value" style="color: var(--success);">
                                    <?php echo $duty_stats ? $duty_stats['upcoming_duties'] : '0'; ?>
                                </div>
                                <div class="stat-label">Upcoming</div>
                            </div>
                            
                            <div class="stat-card">
                                <div class="stat-value" style="color: var(--warning);">
                                    <?php echo $duty_stats ? $duty_stats['past_duties'] : '0'; ?>
                                </div>
                                <div class="stat-label">Completed</div>
                            </div>
                            
                            <div class="stat-card">
                                <div class="stat-value" style="color: var(--info);">
                                    <?php 
                                    if ($duty_stats && $duty_stats['all_duty_types']) {
                                        $duty_types = explode(',', $duty_stats['all_duty_types']);
                                        echo count(array_unique($duty_types));
                                    } else {
                                        echo '0';
                                    }
                                    ?>
                                </div>
                                <div class="stat-label">Duty Types</div>
                            </div>
                        </div>
                        
                        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(120px, 1fr)); gap: 10px; margin-top: 15px;">
                            <div style="background: rgba(16, 185, 129, 0.1); padding: 10px; border-radius: 8px; text-align: center;">
                                <span style="font-weight: 700; color: var(--success);"><?php echo $duty_stats ? $duty_stats['primary_duties'] : '0'; ?></span>
                                <span style="color: var(--text-light); font-size: 11px;">Primary</span>
                            </div>
                            <div style="background: rgba(245, 158, 11, 0.1); padding: 10px; border-radius: 8px; text-align: center;">
                                <span style="font-weight: 700; color: var(--warning);"><?php echo $duty_stats ? $duty_stats['secondary_duties'] : '0'; ?></span>
                                <span style="color: var(--text-light); font-size: 11px;">Secondary</span>
                            </div>
                            <div style="background: rgba(59, 130, 246, 0.1); padding: 10px; border-radius: 8px; text-align: center;">
                                <span style="font-weight: 700; color: var(--info);"><?php echo $duty_stats ? $duty_stats['support_duties'] : '0'; ?></span>
                                <span style="color: var(--text-light); font-size: 11px;">Support</span>
                            </div>
                        </div>
                        
                        <div style="background: rgba(220, 38, 38, 0.05); padding: 15px; border-radius: 8px; margin-top: 15px;">
                            <p style="margin: 0; color: var(--text-color); font-size: 13px;">
                                <i class='bx bx-info-circle' style="color: var(--primary-color);"></i>
                                <strong>Volunteer:</strong> <?php echo $volunteer_name; ?> 
                                | <strong>Contact:</strong> <?php echo $volunteer_contact; ?>
                                | <strong>Total Assignments:</strong> <?php echo count($duty_assignments); ?>
                            </p>
                        </div>
                    </div>
                    
                    <!-- Filters -->
                    <div class="filter-container">
                        <form method="GET" action="" id="filter-form">
                            <div class="filter-group">
                                <label class="filter-label">Status</label>
                                <select name="status" class="filter-select">
                                    <option value="all" <?php echo $status_filter === 'all' ? 'selected' : ''; ?>>All Status</option>
                                    <option value="scheduled" <?php echo $status_filter === 'scheduled' ? 'selected' : ''; ?>>Scheduled</option>
                                    <option value="confirmed" <?php echo $status_filter === 'confirmed' ? 'selected' : ''; ?>>Confirmed</option>
                                    <option value="completed" <?php echo $status_filter === 'completed' ? 'selected' : ''; ?>>Completed</option>
                                    <option value="cancelled" <?php echo $status_filter === 'cancelled' ? 'selected' : ''; ?>>Cancelled</option>
                                </select>
                            </div>
                            
                            <div class="filter-group">
                                <label class="filter-label">Date</label>
                                <input type="date" name="date" class="filter-input" value="<?php echo htmlspecialchars($date_filter); ?>">
                            </div>
                            
                            <div class="filter-group">
                                <label class="filter-label">Unit</label>
                                <select name="unit" class="filter-select">
                                    <option value="">All Units</option>
                                    <?php foreach ($available_units as $unit): ?>
                                        <option value="<?php echo $unit['id']; ?>" <?php echo $unit_filter == $unit['id'] ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($unit['unit_name']); ?> (<?php echo htmlspecialchars($unit['unit_code']); ?>)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div class="filter-actions">
                                <button type="submit" class="btn btn-primary">
                                    <i class='bx bx-filter-alt'></i> Apply Filters
                                </button>
                                <a href="duty_assignments.php" class="btn btn-secondary">
                                    <i class='bx bx-reset'></i> Clear Filters
                                </a>
                            </div>
                        </form>
                    </div>
                    
                    <!-- Duty Assignments List -->
                    <div class="section-container">
                        <h3 class="section-title">
                            <i class='bx bx-task'></i>
                            Your Duty Assignments
                            <?php if (count($duty_assignments) > 0): ?>
                                <span class="date-badge"><?php echo count($duty_assignments); ?> assignments</span>
                            <?php endif; ?>
                        </h3>
                        
                        <?php if (count($duty_assignments) > 0): ?>
                            <?php foreach ($duty_assignments as $duty): 
                                $shift_date = date('F j, Y', strtotime($duty['shift_date']));
                                $start_time = date('g:i A', strtotime($duty['start_time']));
                                $end_time = date('g:i A', strtotime($duty['end_time']));
                                
                                // Determine card class based on date
                                $current_date = date('Y-m-d');
                                $card_class = '';
                                if ($duty['shift_date'] > $current_date) {
                                    $card_class = 'upcoming';
                                } elseif ($duty['shift_date'] < $current_date) {
                                    $card_class = 'past';
                                } elseif ($duty['shift_status'] === 'cancelled') {
                                    $card_class = 'cancelled';
                                } else {
                                    $card_class = 'upcoming';
                                }
                                
                                // Status class
                                $status_class = 'status-' . $duty['shift_status'];
                            ?>
                                <div class="duty-card <?php echo $card_class; ?>">
                                    <div class="duty-header">
                                        <div class="duty-title">
                                            <i class='bx bx-task'></i>
                                            <?php echo htmlspecialchars($duty['duty_type']); ?>
                                            <span class="duty-type-badge"><?php echo htmlspecialchars(str_replace('_', ' ', $duty['duty_type'])); ?></span>
                                        </div>
                                        <span class="duty-status <?php echo $status_class; ?>">
                                            <?php echo ucfirst($duty['shift_status']); ?>
                                        </span>
                                    </div>
                                    
                                    <div class="duty-details">
                                        <div class="detail-item">
                                            <span class="detail-label">Priority</span>
                                            <span class="detail-value">
                                                <span class="priority-badge priority-<?php echo htmlspecialchars($duty['priority']); ?>">
                                                    <?php echo htmlspecialchars($duty['priority']); ?>
                                                </span>
                                            </span>
                                        </div>
                                        
                                        <div class="detail-item">
                                            <span class="detail-label">Assigned Unit</span>
                                            <span class="detail-value">
                                                <?php echo htmlspecialchars($duty['unit_name'] ?? 'Not Assigned'); ?>
                                                <?php if ($duty['unit_code']): ?>
                                                    (<?php echo htmlspecialchars($duty['unit_code']); ?>)
                                                <?php endif; ?>
                                            </span>
                                        </div>
                                        
                                        <div class="detail-item">
                                            <span class="detail-label">Confirmation Status</span>
                                            <span class="detail-value">
                                                <?php if ($duty['confirmation_status']): ?>
                                                    <span style="color: <?php echo $duty['confirmation_status'] === 'confirmed' ? 'var(--success)' : ($duty['confirmation_status'] === 'declined' ? 'var(--danger)' : 'var(--warning)'); ?>; font-weight: 600;">
                                                        <?php echo ucfirst($duty['confirmation_status']); ?>
                                                    </span>
                                                <?php else: ?>
                                                    <span style="color: var(--warning); font-weight: 600;">Pending</span>
                                                <?php endif; ?>
                                            </span>
                                        </div>
                                    </div>
                                    
                                    <div class="shift-info">
                                        <div class="shift-info-title">
                                            <i class='bx bx-calendar'></i>
                                            Shift Information
                                            <?php if ($duty['shift_date'] == date('Y-m-d')): ?>
                                                <span class="today-badge">Today</span>
                                            <?php endif; ?>
                                        </div>
                                        
                                        <div class="shift-details-grid">
                                            <div class="shift-detail-item">
                                                <span class="shift-detail-label">Date</span>
                                                <span class="shift-detail-value"><?php echo $shift_date; ?></span>
                                            </div>
                                            
                                            <div class="shift-detail-item">
                                                <span class="shift-detail-label">Time</span>
                                                <span class="shift-detail-value"><?php echo $start_time; ?> - <?php echo $end_time; ?></span>
                                            </div>
                                            
                                            <div class="shift-detail-item">
                                                <span class="shift-detail-label">Shift Type</span>
                                                <span class="shift-detail-value"><?php echo ucfirst(str_replace('_', ' ', $duty['shift_type'])); ?></span>
                                            </div>
                                            
                                            <div class="shift-detail-item">
                                                <span class="shift-detail-label">Location</span>
                                                <span class="shift-detail-value"><?php echo htmlspecialchars($duty['shift_location'] ?? ($duty['unit_location'] ?? 'Main Station')); ?></span>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <!-- Duty Description -->
                                    <div style="margin-top: 15px;">
                                        <span style="font-weight: 600; color: var(--text-color); font-size: 13px;">Duty Description:</span>
                                        <p style="margin-top: 5px; color: var(--text-color); font-size: 14px; line-height: 1.5;">
                                            <?php echo htmlspecialchars($duty['duty_description']); ?>
                                        </p>
                                    </div>
                                    
                                    <!-- Required Equipment -->
                                    <?php if ($duty['required_equipment']): ?>
                                        <div class="equipment-section">
                                            <div class="section-subtitle">
                                                <i class='bx bx-wrench'></i>
                                                Required Equipment
                                            </div>
                                            <div class="equipment-list">
                                                <?php 
                                                $equipment_items = explode(',', $duty['required_equipment']);
                                                foreach ($equipment_items as $item):
                                                    $item = trim($item);
                                                    if (!empty($item)):
                                                ?>
                                                    <span class="equipment-item"><?php echo htmlspecialchars($item); ?></span>
                                                <?php 
                                                    endif;
                                                endforeach; 
                                                ?>
                                            </div>
                                        </div>
                                    <?php endif; ?>
                                    
                                    <!-- Required Training -->
                                    <?php if ($duty['required_training']): ?>
                                        <div class="training-section">
                                            <div class="section-subtitle">
                                                <i class='bx bx-graduation-cap'></i>
                                                Required Training/Certifications
                                            </div>
                                            <div class="training-list">
                                                <?php 
                                                $training_items = explode(',', $duty['required_training']);
                                                foreach ($training_items as $item):
                                                    $item = trim($item);
                                                    if (!empty($item)):
                                                ?>
                                                    <span class="training-item"><?php echo htmlspecialchars($item); ?></span>
                                                <?php 
                                                    endif;
                                                endforeach; 
                                                ?>
                                            </div>
                                        </div>
                                    <?php endif; ?>
                                    
                                    <!-- Additional Notes -->
                                    <?php if ($duty['notes']): ?>
                                        <div style="margin-top: 15px; padding: 12px; background: rgba(245, 158, 11, 0.05); border-radius: 8px; border-left: 3px solid var(--warning);">
                                            <span style="font-weight: 600; color: var(--warning); font-size: 12px;">Additional Notes:</span>
                                            <p style="margin-top: 5px; color: var(--text-color); font-size: 13px;">
                                                <?php echo htmlspecialchars($duty['notes']); ?>
                                            </p>
                                        </div>
                                    <?php endif; ?>
                                    
                                    <?php if ($duty['shift_notes']): ?>
                                        <div style="margin-top: 10px; padding: 10px; background: rgba(220, 38, 38, 0.05); border-radius: 8px; border-left: 3px solid var(--primary-color);">
                                            <span style="font-weight: 600; color: var(--primary-color); font-size: 12px;">Shift Notes:</span>
                                            <p style="margin-top: 5px; color: var(--text-color); font-size: 13px;"><?php echo htmlspecialchars($duty['shift_notes']); ?></p>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="empty-state">
                                <i class='bx bx-task'></i>
                                <h3>No Duty Assignments Found</h3>
                                <p>You don't have any duty assignments at the moment. Check back later or contact your supervisor.</p>
                                <div style="margin-top: 20px;">
                                    <a href="confirm_availability.php" class="btn btn-primary">
                                        <i class='bx bx-check-shield'></i> Check Shift Availability
                                    </a>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                    
                    <!-- Information Section -->
                    <div class="section-container">
                        <h3 class="section-title">
                            <i class='bx bx-help-circle'></i>
                            About Duty Assignments
                        </h3>
                        
                        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 20px;">
                            <div style="background: rgba(16, 185, 129, 0.1); padding: 20px; border-radius: 10px; border: 1px solid rgba(16, 185, 129, 0.2);">
                                <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 10px;">
                                    <div style="background: var(--success); color: white; width: 30px; height: 30px; border-radius: 50%; display: flex; align-items: center; justify-content: center;">
                                        <i class='bx bx-target-lock'></i>
                                    </div>
                                    <h4 style="margin: 0; color: var(--text-color);">Primary Duties</h4>
                                </div>
                                <p style="margin: 0; color: var(--text-color); font-size: 13px;">
                                    Your main responsibilities during the shift. These are the most critical tasks you need to focus on.
                                </p>
                            </div>
                            
                            <div style="background: rgba(245, 158, 11, 0.1); padding: 20px; border-radius: 10px; border: 1px solid rgba(245, 158, 11, 0.2);">
                                <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 10px;">
                                    <div style="background: var(--warning); color: white; width: 30px; height: 30px; border-radius: 50%; display: flex; align-items: center; justify-content: center;">
                                        <i class='bx bx-shield-quarter'></i>
                                    </div>
                                    <h4 style="margin: 0; color: var(--text-color);">Secondary Duties</h4>
                                </div>
                                <p style="margin: 0; color: var(--text-color); font-size: 13px;">
                                    Supporting responsibilities. Perform these after completing your primary duties or when needed.
                                </p>
                            </div>
                            
                            <div style="background: rgba(59, 130, 246, 0.1); padding: 20px; border-radius: 10px; border: 1px solid rgba(59, 130, 246, 0.2);">
                                <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 10px;">
                                    <div style="background: var(--info); color: white; width: 30px; height: 30px; border-radius: 50%; display: flex; align-items: center; justify-content: center;">
                                        <i class='bx bx-support'></i>
                                    </div>
                                    <h4 style="margin: 0; color: var(--text-color);">Support Duties</h4>
                                </div>
                                <p style="margin: 0; color: var(--text-color); font-size: 13px;">
                                    General support tasks. These help the team function smoothly and are assigned as needed.
                                </p>
                            </div>
                        </div>
                        
                        <div style="margin-top: 20px; padding: 15px; background: rgba(220, 38, 38, 0.05); border-radius: 8px; border-left: 3px solid var(--primary-color);">
                            <h4 style="margin: 0 0 10px 0; color: var(--primary-color);">Important Notes:</h4>
                            <ul style="margin: 0; padding-left: 20px; color: var(--text-color); font-size: 13px;">
                                <li>Review your duty assignments before each shift to ensure you're prepared</li>
                                <li>Make sure you have access to all required equipment listed</li>
                                <li>If you're missing any required training, notify your supervisor immediately</li>
                                <li>Complete your primary duties before moving to secondary or support duties</li>
                                <li>Report any issues with equipment or supplies to your unit coordinator</li>
                                <li>Arrive early to prepare for your assigned duties</li>
                                <li>Always follow safety protocols specific to your duty type</li>
                                <li>Contact your supervisor if you have questions about any duty assignment</li>
                            </ul>
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
            
            // Handle search
            const searchInput = document.getElementById('search-input');
            if (searchInput) {
                searchInput.addEventListener('keyup', function() {
                    const searchTerm = this.value.toLowerCase();
                    const dutyCards = document.querySelectorAll('.duty-card');
                    
                    dutyCards.forEach(card => {
                        const title = card.querySelector('.duty-title').textContent.toLowerCase();
                        const description = card.querySelector('p')?.textContent.toLowerCase() || '';
                        const unit = card.querySelector('.detail-value').textContent.toLowerCase();
                        
                        if (title.includes(searchTerm) || description.includes(searchTerm) || unit.includes(searchTerm)) {
                            card.style.display = 'block';
                        } else {
                            card.style.display = 'none';
                        }
                    });
                });
            }
        });
        
        function initEventListeners() {
            // Theme toggle
            const themeToggle = document.getElementById('theme-toggle');
            const themeIcon = themeToggle.querySelector('i');
            const themeText = themeToggle.querySelector('span');
            
            if (themeToggle) {
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
            }
            
            // User profile dropdown
            const userProfile = document.getElementById('user-profile');
            const userDropdown = document.getElementById('user-dropdown');
            
            if (userProfile && userDropdown) {
                userProfile.addEventListener('click', function(e) {
                    e.stopPropagation();
                    userDropdown.classList.toggle('show');
                });
            }
            
            // Close dropdowns when clicking outside
            document.addEventListener('click', function() {
                if (userDropdown) {
                    userDropdown.classList.remove('show');
                }
            });
            
            // Auto-submit date filter on change
            const dateFilter = document.querySelector('input[name="date"]');
            if (dateFilter) {
                dateFilter.addEventListener('change', function() {
                    document.getElementById('filter-form').submit();
                });
            }
        }
        
        function updateTime() {
            const now = new Date();
            const utc = now.getTime() + (now.getTimezoneOffset() * 60000);
            const gmt8 = new Date(utc + (8 * 3600000));
            
            const hours = gmt8.getHours().toString().padStart(2, '0');
            const minutes = gmt8.getMinutes().toString().padStart(2, '0');
            const seconds = gmt8.getSeconds().toString().padStart(2, '0');
            
            const timeString = `${hours}:${minutes}:${seconds} UTC+8`;
            const timeDisplay = document.getElementById('current-time');
            if (timeDisplay) {
                timeDisplay.textContent = timeString;
            }
        }
        
        function toggleSubmenu(id) {
            const submenu = document.getElementById(id);
            const arrow = submenu.previousElementSibling.querySelector('.dropdown-arrow');
            
            submenu.classList.toggle('active');
            arrow.classList.toggle('rotated');
        }
    </script>
</body>
</html>