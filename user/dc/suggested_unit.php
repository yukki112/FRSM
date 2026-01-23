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

// Get volunteer ID and unit assignment
$volunteer_query = "
    SELECT v.id, v.first_name, v.last_name, v.contact_number, 
           va.unit_id, u.unit_name, u.unit_code, u.unit_type, u.location
    FROM volunteers v
    LEFT JOIN volunteer_assignments va ON v.id = va.volunteer_id AND va.status = 'Active'
    LEFT JOIN units u ON va.unit_id = u.id
    WHERE v.user_id = ?
";
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
$unit_id = $volunteer['unit_id'];
$unit_name = htmlspecialchars($volunteer['unit_name']);
$unit_code = htmlspecialchars($volunteer['unit_code']);
$unit_type = htmlspecialchars($volunteer['unit_type']);
$unit_location = htmlspecialchars($volunteer['location']);

// Get suggested dispatches for the volunteer's unit
$suggested_query = "
    SELECT 
        di.*,
        ai.id as incident_id,
        ai.external_id,
        ai.emergency_type,
        ai.severity,
        ai.title,
        ai.caller_name,
        ai.caller_phone,
        ai.location as incident_location,
        ai.description,
        ai.affected_barangays,
        ai.created_at as incident_created,
        u.unit_name,
        u.unit_code,
        u.unit_type,
        u.location as unit_location,
        duser.first_name as dispatcher_first,
        duser.last_name as dispatcher_last,
        CASE 
            WHEN di.status = 'pending' THEN 'suggested'
            WHEN di.status = 'dispatched' THEN 'dispatched'
            WHEN di.status = 'en_route' THEN 'en_route'
            WHEN di.status = 'arrived' THEN 'arrived'
            WHEN di.status = 'completed' THEN 'completed'
            WHEN di.status = 'cancelled' THEN 'cancelled'
            ELSE di.status
        END as display_status
    FROM dispatch_incidents di
    INNER JOIN api_incidents ai ON di.incident_id = ai.id
    INNER JOIN units u ON di.unit_id = u.id
    LEFT JOIN users duser ON di.dispatched_by = duser.id
    WHERE di.unit_id = ?
    ORDER BY di.dispatched_at DESC
";

$suggested_stmt = $pdo->prepare($suggested_query);
$suggested_stmt->execute([$unit_id]);
$suggested_dispatches = $suggested_stmt->fetchAll();

// Get unit team members
$team_query = "
    SELECT 
        v.id,
        v.first_name,
        v.middle_name,
        v.last_name,
        v.contact_number,
        v.volunteer_status,
        v.skills_basic_firefighting,
        v.skills_first_aid_cpr,
        v.skills_search_rescue,
        v.skills_driving,
        v.skills_communication,
        v.email,
        u.username
    FROM volunteers v
    LEFT JOIN users u ON v.user_id = u.id
    LEFT JOIN volunteer_assignments va ON v.id = va.volunteer_id AND va.status = 'Active'
    WHERE va.unit_id = ? 
      AND v.status = 'approved'
    ORDER BY v.last_name, v.first_name
";

$team_stmt = $pdo->prepare($team_query);
$team_stmt->execute([$unit_id]);
$team_members = $team_stmt->fetchAll();

// Get notifications for the volunteer
$notifications_query = "
    SELECT * FROM notifications 
    WHERE user_id = ? 
      AND is_read = 0
      AND (type = 'dispatch' OR type LIKE '%suggested%' OR type LIKE '%assigned%')
    ORDER BY created_at DESC
    LIMIT 10
";

$notifications_stmt = $pdo->prepare($notifications_query);
$notifications_stmt->execute([$user_id]);
$notifications = $notifications_stmt->fetchAll();

// Mark notifications as read when viewing this page
if (!empty($notifications)) {
    $mark_read_query = "UPDATE notifications SET is_read = 1 WHERE user_id = ? AND (type = 'dispatch' OR type LIKE '%suggested%' OR type LIKE '%assigned%')";
    $mark_read_stmt = $pdo->prepare($mark_read_query);
    $mark_read_stmt->execute([$user_id]);
}

// Close statements
$stmt = null;
$volunteer_stmt = null;
$suggested_stmt = null;
$team_stmt = null;
$notifications_stmt = null;
if (isset($mark_read_stmt)) $mark_read_stmt = null;
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Suggested Unit & Dispatch - Fire & Rescue Services Management</title>
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

        /* Main Grid Layout */
        .main-grid {
            display: grid;
            grid-template-columns: 1fr 350px;
            gap: 30px;
        }

        @media (max-width: 1200px) {
            .main-grid {
                grid-template-columns: 1fr;
            }
        }

        /* Dispatch Cards */
        .dispatch-cards {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        @media (max-width: 768px) {
            .dispatch-cards {
                grid-template-columns: 1fr;
            }
        }

        .dispatch-card {
            background: var(--background-color);
            border: 1px solid var(--border-color);
            border-radius: 12px;
            padding: 25px;
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
        }

        .dispatch-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.1);
        }

        .dispatch-card-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 15px;
        }

        .dispatch-title {
            font-size: 18px;
            font-weight: 700;
            color: var(--text-color);
            margin: 0;
        }

        .dispatch-status {
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 700;
            text-transform: uppercase;
        }

        .status-suggested {
            background: rgba(59, 130, 246, 0.1);
            color: var(--info);
            border: 1px solid rgba(59, 130, 246, 0.2);
        }

        .status-dispatched {
            background: rgba(245, 158, 11, 0.1);
            color: var(--warning);
            border: 1px solid rgba(245, 158, 11, 0.2);
        }

        .status-en_route {
            background: rgba(139, 92, 246, 0.1);
            color: var(--purple);
            border: 1px solid rgba(139, 92, 246, 0.2);
        }

        .status-arrived {
            background: rgba(16, 185, 129, 0.1);
            color: var(--success);
            border: 1px solid rgba(16, 185, 129, 0.2);
        }

        .status-completed {
            background: rgba(16, 185, 129, 0.1);
            color: var(--success);
            border: 1px solid rgba(16, 185, 129, 0.2);
        }

        .status-cancelled {
            background: rgba(107, 114, 128, 0.1);
            color: var(--gray-500);
            border: 1px solid rgba(107, 114, 128, 0.2);
        }

        .dispatch-details {
            margin-bottom: 20px;
        }

        .detail-row {
            display: flex;
            align-items: center;
            margin-bottom: 10px;
            gap: 10px;
        }

        .detail-label {
            font-size: 12px;
            color: var(--text-light);
            min-width: 100px;
        }

        .detail-value {
            font-size: 14px;
            color: var(--text-color);
            font-weight: 500;
        }

        .vehicles-section {
            margin-top: 20px;
            padding-top: 20px;
            border-top: 1px solid var(--border-color);
        }

        .vehicles-title {
            font-size: 14px;
            font-weight: 600;
            color: var(--text-color);
            margin-bottom: 10px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .vehicles-list {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
        }

        .vehicle-badge {
            padding: 6px 12px;
            background: var(--gray-100);
            border: 1px solid var(--border-color);
            border-radius: 6px;
            font-size: 12px;
            display: flex;
            align-items: center;
            gap: 5px;
        }

        .dark-mode .vehicle-badge {
            background: var(--gray-800);
        }

        /* Unit Information Card */
        .unit-info-card {
            background: linear-gradient(135deg, #fef2f2 0%, #fee2e2 100%);
            border: 1px solid #fecaca;
            border-radius: 12px;
            padding: 25px;
            margin-bottom: 20px;
        }

        .dark-mode .unit-info-card {
            background: linear-gradient(135deg, #1e293b 0%, #2d3748 100%);
            border-color: #4b5563;
        }

        .unit-card-title {
            font-size: 18px;
            font-weight: 700;
            color: var(--danger);
            margin-bottom: 15px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .unit-details-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 15px;
        }

        .unit-detail {
            display: flex;
            flex-direction: column;
        }

        .unit-label {
            font-size: 12px;
            color: var(--text-light);
            margin-bottom: 4px;
        }

        .unit-value {
            font-weight: 600;
            color: var(--text-color);
            font-size: 14px;
        }

        /* Team Members */
        .team-section {
            margin-top: 30px;
        }

        .team-title {
            font-size: 16px;
            font-weight: 700;
            color: var(--text-color);
            margin-bottom: 15px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .team-members {
            max-height: 300px;
            overflow-y: auto;
        }

        .team-member {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 12px;
            border-bottom: 1px solid var(--border-color);
            transition: all 0.3s ease;
        }

        .team-member:hover {
            background: var(--gray-100);
        }

        .dark-mode .team-member:hover {
            background: var(--gray-800);
        }

        .team-member:last-child {
            border-bottom: none;
        }

        .member-avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            font-size: 14px;
        }

        .member-info h4 {
            margin: 0 0 4px 0;
            color: var(--text-color);
            font-size: 14px;
        }

        .member-info p {
            margin: 0;
            color: var(--text-light);
            font-size: 11px;
        }

        .member-skills {
            display: flex;
            gap: 4px;
            flex-wrap: wrap;
            margin-top: 4px;
        }

        .skill-badge {
            padding: 2px 6px;
            background: var(--gray-100);
            border: 1px solid var(--border-color);
            border-radius: 4px;
            font-size: 10px;
            color: var(--text-light);
        }

        .dark-mode .skill-badge {
            background: var(--gray-800);
        }

        /* Empty States */
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

        /* Severity Badges */
        .severity-badge {
            padding: 4px 10px;
            border-radius: 4px;
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
        }

        .severity-critical {
            background: rgba(220, 38, 38, 0.1);
            color: var(--danger);
            border: 1px solid rgba(220, 38, 38, 0.2);
        }

        .severity-high {
            background: rgba(245, 158, 11, 0.1);
            color: var(--warning);
            border: 1px solid rgba(245, 158, 11, 0.2);
        }

        .severity-medium {
            background: rgba(59, 130, 246, 0.1);
            color: var(--info);
            border: 1px solid rgba(59, 130, 246, 0.2);
        }

        .severity-low {
            background: rgba(107, 114, 128, 0.1);
            color: var(--gray-500);
            border: 1px solid rgba(107, 114, 128, 0.2);
        }

        /* Filter Tabs */
        .filter-tabs {
            display: flex;
            gap: 10px;
            margin-bottom: 25px;
            border-bottom: 1px solid var(--border-color);
            padding-bottom: 15px;
        }

        .filter-tab {
            padding: 8px 16px;
            background: var(--gray-100);
            border: 1px solid var(--border-color);
            border-radius: 8px;
            font-size: 13px;
            font-weight: 600;
            color: var(--text-color);
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .filter-tab:hover {
            background: var(--gray-200);
        }

        .filter-tab.active {
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            color: white;
            border-color: var(--primary-color);
        }

        .dark-mode .filter-tab {
            background: var(--gray-800);
        }

        .dark-mode .filter-tab:hover {
            background: var(--gray-700);
        }

        /* Notification Styles */
        .notification-badge {
            position: absolute;
            top: 10px;
            right: 10px;
            background: var(--danger);
            color: white;
            border-radius: 50%;
            width: 20px;
            height: 20px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 10px;
            font-weight: 700;
        }

        .notification-panel {
            position: fixed;
            top: 100px;
            right: 20px;
            width: 350px;
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: 12px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.15);
            z-index: 1000;
            display: none;
        }

        .notification-panel.show {
            display: block;
        }

        .notification-header {
            padding: 15px;
            border-bottom: 1px solid var(--border-color);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .notification-title {
            font-size: 16px;
            font-weight: 700;
            color: var(--text-color);
        }

        .notification-close {
            background: none;
            border: none;
            color: var(--text-light);
            cursor: pointer;
            font-size: 20px;
        }

        .notification-list {
            max-height: 400px;
            overflow-y: auto;
        }

        .notification-item {
            padding: 15px;
            border-bottom: 1px solid var(--border-color);
            transition: all 0.3s ease;
        }

        .notification-item:hover {
            background: var(--gray-100);
        }

        .dark-mode .notification-item:hover {
            background: var(--gray-800);
        }

        .notification-item.unread {
            background: rgba(59, 130, 246, 0.05);
        }

        .notification-message {
            font-size: 13px;
            color: var(--text-color);
            margin-bottom: 5px;
        }

        .notification-time {
            font-size: 11px;
            color: var(--text-light);
        }

        .notification-bell {
            position: relative;
            cursor: pointer;
            margin-right: 15px;
        }

        .notification-count {
            position: absolute;
            top: -5px;
            right: -5px;
            background: var(--danger);
            color: white;
            border-radius: 50%;
            width: 18px;
            height: 18px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 10px;
            font-weight: 700;
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

        /* Badge Styles */
        .badge {
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
        }

        .badge-info {
            background: rgba(59, 130, 246, 0.1);
            color: var(--info);
            border: 1px solid rgba(59, 130, 246, 0.2);
        }

        .badge-success {
            background: rgba(16, 185, 129, 0.1);
            color: var(--success);
            border: 1px solid rgba(16, 185, 129, 0.2);
        }

        .badge-warning {
            background: rgba(245, 158, 11, 0.1);
            color: var(--warning);
            border: 1px solid rgba(245, 158, 11, 0.2);
        }

        .badge-danger {
            background: rgba(220, 38, 38, 0.1);
            color: var(--danger);
            border: 1px solid rgba(220, 38, 38, 0.2);
        }

        /* Time Display */
        .time-display {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 8px 12px;
            background: var(--gray-100);
            border-radius: 8px;
            font-size: 12px;
            color: var(--text-color);
        }

        .dark-mode .time-display {
            background: var(--gray-800);
        }

        /* Responsive Design */
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
            
            .notification-panel {
                width: 300px;
                right: 10px;
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
            
            .notification-panel {
                width: 90%;
                right: 5%;
                left: 5%;
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
                    <div id="postincident" class="submenu active">
                        <a href="suggested_unit.php" class="submenu-item active">Suggested Unit</a>
                        <a href="incident_location.php" class="submenu-item">Incident Location</a>
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
                        <a href="../vr/volunteer_list.php" class="submenu-item">Volunteer List</a>
                        <a href="../vr/roles_skills.php" class="submenu-item">Roles & Skills</a>
                        <a href="../vr/availability.php" class="submenu-item">Availability</a>
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
                    <div id="schedule" class="submenu">
                        <a href="../sds/view_shifts.php" class="submenu-item">Shift Calendar</a>
                        <a href="../sds/confirm_availability.php" class="submenu-item">Confirm Availability</a>
                        <a href="../sds/duty_assignments.php" class="submenu-item">Duty Assignments</a>
                        <a href="../sds/attendance_logs.php" class="submenu-item">Attendance Logs</a>
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
                            <input type="text" placeholder="Search dispatch incidents..." class="search-input" id="search-input">
                        </div>
                    </div>
                    
                    <div class="header-actions">
                        <!-- Notification Bell -->
                        <div class="notification-bell" id="notification-bell">
                            <i class='bx bx-bell' style="font-size: 24px; color: var(--text-color);"></i>
                            <?php if (count($notifications) > 0): ?>
                                <span class="notification-count"><?php echo count($notifications); ?></span>
                            <?php endif; ?>
                        </div>
                        
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
                                <img src="../uploads/avatars/<?php echo $avatar; ?>" alt="User" class="user-avatar">
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
            
            <!-- Notification Panel -->
            <div class="notification-panel" id="notification-panel">
                <div class="notification-header">
                    <h3 class="notification-title">Dispatch Notifications</h3>
                    <button class="notification-close" id="notification-close">&times;</button>
                </div>
                <div class="notification-list">
                    <?php if (!empty($notifications)): ?>
                        <?php foreach ($notifications as $notification): ?>
                            <div class="notification-item unread">
                                <div class="notification-message"><?php echo htmlspecialchars($notification['message']); ?></div>
                                <div class="notification-time">
                                    <?php 
                                    $time = new DateTime($notification['created_at']);
                                    echo $time->format('M j, Y g:i A');
                                    ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="notification-item">
                            <div class="notification-message">No new notifications</div>
                            <div class="notification-time">You're all caught up!</div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- Dashboard Content -->
            <div class="dashboard-content">
                <div class="dashboard-header">
                    <div>
                        <h1 class="dashboard-title">Suggested Unit & Dispatch</h1>
                        <p class="dashboard-subtitle">View dispatch suggestions and assignments for <?php echo htmlspecialchars($unit_name); ?> (<?php echo htmlspecialchars($unit_code); ?>)</p>
                    </div>
                </div>
                
                <!-- Content Container -->
                <div class="content-container">
                    <!-- Filter Tabs -->
                    <div class="section-container">
                        <div class="filter-tabs" id="filter-tabs">
                            <div class="filter-tab active" onclick="filterDispatches('all')">All</div>
                            <div class="filter-tab" onclick="filterDispatches('suggested')">Suggested</div>
                            <div class="filter-tab" onclick="filterDispatches('dispatched')">Dispatched</div>
                            <div class="filter-tab" onclick="filterDispatches('en_route')">En Route</div>
                            <div class="filter-tab" onclick="filterDispatches('arrived')">Arrived</div>
                            <div class="filter-tab" onclick="filterDispatches('completed')">Completed</div>
                            <div class="filter-tab" onclick="filterDispatches('cancelled')">Cancelled</div>
                        </div>
                    </div>
                    
                    <!-- Main Grid -->
                    <div class="main-grid">
                        <!-- Left Column: Dispatch Cards -->
                        <div>
                            <!-- Dispatch Section -->
                            <div class="section-container">
                                <h3 class="section-title">
                                    <i class='bx bx-car'></i>
                                    Dispatch Assignments
                                    <?php if (count($suggested_dispatches) > 0): ?>
                                        <span class="badge badge-info"><?php echo count($suggested_dispatches); ?> assignments</span>
                                    <?php endif; ?>
                                </h3>
                                
                                <?php if (count($suggested_dispatches) > 0): ?>
                                    <div class="dispatch-cards" id="dispatch-cards">
                                        <?php foreach ($suggested_dispatches as $dispatch): 
                                            $dispatcher_name = $dispatch['dispatcher_first'] && $dispatch['dispatcher_last'] ? 
                                                htmlspecialchars($dispatch['dispatcher_first'] . ' ' . $dispatch['dispatcher_last']) : 'Unknown';
                                            
                                            $severity_class = 'severity-' . $dispatch['severity'];
                                            $status_class = 'status-' . $dispatch['display_status'];
                                            
                                            // Parse vehicles JSON
                                            $vehicles = [];
                                            if ($dispatch['vehicles_json']) {
                                                $vehicles_data = json_decode($dispatch['vehicles_json'], true);
                                                if (is_array($vehicles_data)) {
                                                    foreach ($vehicles_data as $vehicle) {
                                                        if (isset($vehicle['vehicle_name'])) {
                                                            $vehicles[] = $vehicle['vehicle_name'];
                                                        }
                                                    }
                                                }
                                            }
                                            
                                            $created_at = new DateTime($dispatch['incident_created']);
                                            $dispatched_at = new DateTime($dispatch['dispatched_at']);
                                        ?>
                                            <div class="dispatch-card" data-status="<?php echo $dispatch['display_status']; ?>">
                                                <div class="dispatch-card-header">
                                                    <h4 class="dispatch-title"><?php echo htmlspecialchars($dispatch['title']); ?></h4>
                                                    <span class="dispatch-status <?php echo $status_class; ?>">
                                                        <?php echo ucfirst($dispatch['display_status']); ?>
                                                    </span>
                                                </div>
                                                
                                                <div class="dispatch-details">
                                                    <div class="detail-row">
                                                        <span class="detail-label">Incident ID:</span>
                                                        <span class="detail-value">#<?php echo $dispatch['external_id']; ?></span>
                                                    </div>
                                                    <div class="detail-row">
                                                        <span class="detail-label">Type:</span>
                                                        <span class="detail-value"><?php echo ucfirst($dispatch['emergency_type']); ?></span>
                                                    </div>
                                                    <div class="detail-row">
                                                        <span class="detail-label">Severity:</span>
                                                        <span class="severity-badge <?php echo $severity_class; ?>">
                                                            <?php echo ucfirst($dispatch['severity']); ?>
                                                        </span>
                                                    </div>
                                                    <div class="detail-row">
                                                        <span class="detail-label">Location:</span>
                                                        <span class="detail-value"><?php echo htmlspecialchars($dispatch['incident_location']); ?></span>
                                                    </div>
                                                    <div class="detail-row">
                                                        <span class="detail-label">Reported:</span>
                                                        <span class="detail-value"><?php echo $created_at->format('M j, Y g:i A'); ?></span>
                                                    </div>
                                                    <div class="detail-row">
                                                        <span class="detail-label">Dispatcher:</span>
                                                        <span class="detail-value"><?php echo $dispatcher_name; ?></span>
                                                    </div>
                                                    <div class="detail-row">
                                                        <span class="detail-label">Caller:</span>
                                                        <span class="detail-value"><?php echo htmlspecialchars($dispatch['caller_name']); ?></span>
                                                    </div>
                                                    <div class="detail-row">
                                                        <span class="detail-label">Phone:</span>
                                                        <span class="detail-value"><?php echo htmlspecialchars($dispatch['caller_phone']); ?></span>
                                                    </div>
                                                </div>
                                                
                                                <?php if (!empty($vehicles)): ?>
                                                <div class="vehicles-section">
                                                    <h5 class="vehicles-title">
                                                        <i class='bx bx-car'></i>
                                                        Assigned Vehicles
                                                    </h5>
                                                    <div class="vehicles-list">
                                                        <?php foreach ($vehicles as $vehicle): ?>
                                                            <span class="vehicle-badge">
                                                                <i class='bx bx-car'></i>
                                                                <?php echo htmlspecialchars($vehicle); ?>
                                                            </span>
                                                        <?php endforeach; ?>
                                                    </div>
                                                </div>
                                                <?php endif; ?>
                                                
                                                <?php if ($dispatch['er_notes']): ?>
                                                <div class="vehicles-section">
                                                    <h5 class="vehicles-title">
                                                        <i class='bx bx-note'></i>
                                                        ER Notes
                                                    </h5>
                                                    <div style="font-size: 13px; color: var(--text-color); background: var(--gray-100); padding: 10px; border-radius: 6px; margin-top: 10px;">
                                                        <?php echo htmlspecialchars($dispatch['er_notes']); ?>
                                                    </div>
                                                </div>
                                                <?php endif; ?>
                                                
                                                <div style="margin-top: 20px; padding-top: 15px; border-top: 1px solid var(--border-color); font-size: 11px; color: var(--text-light);">
                                                    <div style="display: flex; justify-content: space-between;">
                                                        <span>Dispatch ID: <?php echo $dispatch['id']; ?></span>
                                                        <span>Dispatched: <?php echo $dispatched_at->format('M j, Y g:i A'); ?></span>
                                                    </div>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                <?php else: ?>
                                    <div class="empty-state">
                                        <i class='bx bx-car'></i>
                                        <h3>No Dispatch Assignments</h3>
                                        <p>Your unit hasn't been suggested or dispatched to any incidents yet.</p>
                                        <p style="margin-top: 10px; font-size: 12px;">
                                            <i class='bx bx-info-circle'></i>
                                            When an employee/admin suggests your unit for a response, it will appear here.
                                        </p>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                        
                        <!-- Right Column: Unit Information -->
                        <div>
                            <!-- Unit Information -->
                            <div class="unit-info-card">
                                <h3 class="unit-card-title">
                                    <i class='bx bx-building'></i>
                                    Unit Information
                                </h3>
                                <div class="unit-details-grid">
                                    <div class="unit-detail">
                                        <span class="unit-label">Unit Name</span>
                                        <span class="unit-value"><?php echo $unit_name; ?></span>
                                    </div>
                                    <div class="unit-detail">
                                        <span class="unit-label">Unit Code</span>
                                        <span class="unit-value"><?php echo $unit_code; ?></span>
                                    </div>
                                    <div class="unit-detail">
                                        <span class="unit-label">Unit Type</span>
                                        <span class="unit-value"><?php echo $unit_type; ?></span>
                                    </div>
                                    <div class="unit-detail">
                                        <span class="unit-label">Location</span>
                                        <span class="unit-value"><?php echo $unit_location; ?></span>
                                    </div>
                                </div>
                                
                                <div style="margin-top: 20px; padding: 15px; background: rgba(59, 130, 246, 0.05); border-radius: 8px; border: 1px solid rgba(59, 130, 246, 0.2);">
                                    <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 5px;">
                                        <i class='bx bx-info-circle' style="color: var(--info);"></i>
                                        <span style="font-size: 13px; font-weight: 600; color: var(--info);">Your Status</span>
                                    </div>
                                    <p style="font-size: 12px; color: var(--text-light); margin: 0;">
                                        You are currently assigned to this unit. When an employee/admin suggests this unit for a response, 
                                        you'll see the dispatch details here. If the ER dispatches the unit, the status will update accordingly.
                                    </p>
                                </div>
                            </div>
                            
                            <!-- Team Members -->
                            <div class="team-section">
                                <div class="section-container" style="padding: 20px;">
                                    <h4 class="team-title">
                                        <i class='bx bx-group'></i>
                                        Unit Team Members
                                        <span class="badge badge-info"><?php echo count($team_members); ?></span>
                                    </h4>
                                    <div class="team-members">
                                        <?php if (!empty($team_members)): ?>
                                            <?php foreach ($team_members as $member): 
                                                $member_name = htmlspecialchars($member['first_name'] . ' ' . $member['last_name']);
                                                $initials = strtoupper(substr($member['first_name'], 0, 1) . substr($member['last_name'], 0, 1));
                                                
                                                // Determine skills
                                                $skills = [];
                                                if ($member['skills_basic_firefighting']) $skills[] = 'Firefighting';
                                                if ($member['skills_first_aid_cpr']) $skills[] = 'First Aid';
                                                if ($member['skills_search_rescue']) $skills[] = 'Rescue';
                                                if ($member['skills_driving']) $skills[] = 'Driver';
                                                if ($member['skills_communication']) $skills[] = 'Comms';
                                                if (empty($skills)) $skills[] = 'General';
                                            ?>
                                                <div class="team-member">
                                                    <div class="member-avatar"><?php echo $initials; ?></div>
                                                    <div class="member-info">
                                                        <h4><?php echo $member_name; ?></h4>
                                                        <p><?php echo htmlspecialchars($member['email']); ?></p>
                                                        <div class="member-skills">
                                                            <?php foreach (array_slice($skills, 0, 3) as $skill): ?>
                                                                <span class="skill-badge"><?php echo $skill; ?></span>
                                                            <?php endforeach; ?>
                                                            <?php if (count($skills) > 3): ?>
                                                                <span class="skill-badge">+<?php echo count($skills) - 3; ?> more</span>
                                                            <?php endif; ?>
                                                        </div>
                                                    </div>
                                                </div>
                                            <?php endforeach; ?>
                                        <?php else: ?>
                                            <div class="empty-state" style="padding: 20px 0;">
                                                <i class='bx bx-user-x'></i>
                                                <p style="font-size: 12px;">No team members found</p>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Quick Stats -->
                            <div class="section-container" style="padding: 20px;">
                                <h4 class="team-title">
                                    <i class='bx bx-stats'></i>
                                    Dispatch Summary
                                </h4>
                                <div style="display: grid; gap: 12px;">
                                    <?php 
                                    $status_counts = [];
                                    foreach ($suggested_dispatches as $dispatch) {
                                        $status = $dispatch['display_status'];
                                        if (!isset($status_counts[$status])) {
                                            $status_counts[$status] = 0;
                                        }
                                        $status_counts[$status]++;
                                    }
                                    ?>
                                    <?php foreach ($status_counts as $status => $count): ?>
                                    <div style="display: flex; justify-content: space-between; align-items: center;">
                                        <span style="font-size: 13px; color: var(--text-light);">
                                            <i class='bx bx-circle' style="color: var(--<?php echo $status === 'suggested' ? 'info' : ($status === 'dispatched' ? 'warning' : ($status === 'completed' ? 'success' : 'text-light')); ?>);"></i>
                                            <?php echo ucfirst($status); ?>
                                        </span>
                                        <span style="font-weight: 600; color: var(--text-color);"><?php echo $count; ?></span>
                                    </div>
                                    <?php endforeach; ?>
                                    
                                    <?php if (empty($status_counts)): ?>
                                    <div style="text-align: center; padding: 10px; font-size: 12px; color: var(--text-light);">
                                        <i class='bx bx-info-circle'></i>
                                        No dispatch statistics available
                                    </div>
                                    <?php endif; ?>
                                </div>
                            </div>
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
                    const dispatchCards = document.querySelectorAll('.dispatch-card');
                    
                    dispatchCards.forEach(card => {
                        const text = card.textContent.toLowerCase();
                        if (text.includes(searchTerm)) {
                            card.style.display = 'block';
                        } else {
                            card.style.display = 'none';
                        }
                    });
                });
            }
            
            // Auto-refresh dispatch data every 30 seconds
            setInterval(refreshDispatchData, 30000);
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
            
            // Notification bell
            const notificationBell = document.getElementById('notification-bell');
            const notificationPanel = document.getElementById('notification-panel');
            const notificationClose = document.getElementById('notification-close');
            
            if (notificationBell && notificationPanel) {
                notificationBell.addEventListener('click', function(e) {
                    e.stopPropagation();
                    notificationPanel.classList.toggle('show');
                });
            }
            
            if (notificationClose && notificationPanel) {
                notificationClose.addEventListener('click', function() {
                    notificationPanel.classList.remove('show');
                });
            }
            
            // Close dropdowns when clicking outside
            document.addEventListener('click', function() {
                if (userDropdown) {
                    userDropdown.classList.remove('show');
                }
                if (notificationPanel) {
                    notificationPanel.classList.remove('show');
                }
            });
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
        
        // Filter dispatches by status
        function filterDispatches(status) {
            const tabs = document.querySelectorAll('.filter-tab');
            tabs.forEach(tab => tab.classList.remove('active'));
            
            // Activate clicked tab
            event.target.classList.add('active');
            
            const dispatchCards = document.querySelectorAll('.dispatch-card');
            dispatchCards.forEach(card => {
                if (status === 'all' || card.getAttribute('data-status') === status) {
                    card.style.display = 'block';
                } else {
                    card.style.display = 'none';
                }
            });
        }
        
        // Refresh dispatch data
        function refreshDispatchData() {
            // In a real application, this would make an AJAX call to refresh the data
            console.log('Refreshing dispatch data...');
            // For now, we'll just reload the page after 5 minutes
            // setTimeout(() => location.reload(), 300000); // 5 minutes
        }
        
        // Show notification panel if there are notifications
        <?php if (count($notifications) > 0): ?>
        setTimeout(() => {
            const notificationPanel = document.getElementById('notification-panel');
            if (notificationPanel) {
                notificationPanel.classList.add('show');
                
                // Auto-hide after 10 seconds
                setTimeout(() => {
                    notificationPanel.classList.remove('show');
                }, 10000);
            }
        }, 1500);
        <?php endif; ?>
    </script>
</body>
</html>