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
           va.unit_id, u.unit_name, u.unit_code, u.unit_type, u.location as unit_location
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
$unit_location = htmlspecialchars($volunteer['unit_location']);

// Get ALL incidents (including those where your unit was suggested)
$incidents_query = "
    SELECT 
        ai.*,
        di.id as dispatch_id,
        di.unit_id as dispatched_unit_id,
        di.status as dispatch_status,
        di.dispatched_at,
        di.dispatched_by,
        du.unit_name as dispatched_unit_name,
        du.unit_code as dispatched_unit_code,
        du.location as dispatched_unit_location,
        duser.first_name as dispatcher_first,
        duser.last_name as dispatcher_last,
        duser.email as dispatcher_email,
        CASE 
            WHEN di.id IS NOT NULL THEN 'dispatched'
            ELSE 'pending'
        END as map_status
    FROM api_incidents ai
    LEFT JOIN dispatch_incidents di ON ai.id = di.incident_id
    LEFT JOIN units du ON di.unit_id = du.id
    LEFT JOIN users duser ON di.dispatched_by = duser.id
    WHERE ai.status IN ('pending', 'processing', 'responded')
        AND ai.emergency_type IN ('fire', 'rescue', 'medical')
        AND ai.location IS NOT NULL
        AND ai.location != ''
    ORDER BY ai.created_at DESC
";

$incidents_stmt = $pdo->prepare($incidents_query);
$incidents_stmt->execute();
$all_incidents = $incidents_stmt->fetchAll();

// Get incidents where YOUR unit was specifically suggested/dispatched
$my_unit_incidents_query = "
    SELECT 
        ai.*,
        di.id as dispatch_id,
        di.unit_id,
        di.status as dispatch_status,
        di.dispatched_at,
        di.dispatched_by,
        du.unit_name,
        du.unit_code,
        du.location as unit_location,
        duser.first_name as dispatcher_first,
        duser.last_name as dispatcher_last,
        duser.email as dispatcher_email
    FROM api_incidents ai
    INNER JOIN dispatch_incidents di ON ai.id = di.incident_id
    INNER JOIN units du ON di.unit_id = du.id
    LEFT JOIN users duser ON di.dispatched_by = duser.id
    WHERE di.unit_id = ?
        AND ai.status IN ('pending', 'processing', 'responded')
        AND ai.location IS NOT NULL
        AND ai.location != ''
    ORDER BY di.dispatched_at DESC
";

$my_unit_incidents_stmt = $pdo->prepare($my_unit_incidents_query);
$my_unit_incidents_stmt->execute([$unit_id]);
$my_unit_incidents = $my_unit_incidents_stmt->fetchAll();

// Get active incidents count
$active_incidents_query = "
    SELECT COUNT(*) as count 
    FROM api_incidents 
    WHERE status IN ('pending', 'processing')
      AND emergency_type IN ('fire', 'rescue', 'medical')
      AND location IS NOT NULL
      AND location != ''
";
$active_incidents_stmt = $pdo->prepare($active_incidents_query);
$active_incidents_stmt->execute();
$active_incidents_count = $active_incidents_stmt->fetchColumn();

// Prepare incidents data for JavaScript
$incidents_data = [];
foreach ($all_incidents as $incident) {
    $incidents_data[] = [
        'id' => $incident['id'],
        'external_id' => $incident['external_id'],
        'title' => $incident['title'],
        'emergency_type' => $incident['emergency_type'],
        'severity' => $incident['severity'],
        'location' => $incident['location'],
        'description' => $incident['description'],
        'caller_name' => $incident['caller_name'],
        'caller_phone' => $incident['caller_phone'],
        'status' => $incident['status'],
        'affected_barangays' => $incident['affected_barangays'],
        'created_at' => $incident['created_at'],
        'dispatch_id' => $incident['dispatch_id'],
        'dispatch_status' => $incident['dispatch_status'],
        'dispatched_unit_id' => $incident['dispatched_unit_id'],
        'dispatched_unit_name' => $incident['dispatched_unit_name'],
        'dispatched_unit_code' => $incident['dispatched_unit_code'],
        'dispatcher_name' => $incident['dispatcher_first'] && $incident['dispatcher_last'] ? 
            $incident['dispatcher_first'] . ' ' . $incident['dispatcher_last'] : 'Unknown',
        'map_status' => $incident['map_status'],
        'is_my_unit' => $incident['dispatched_unit_id'] == $unit_id ? 'yes' : 'no'
    ];
}

// Get notifications for the volunteer
$notifications_query = "
    SELECT * FROM notifications 
    WHERE user_id = ? 
      AND is_read = 0
      AND (type LIKE '%dispatch%' OR type LIKE '%incident%' OR type LIKE '%suggested%')
    ORDER BY created_at DESC
    LIMIT 10
";

$notifications_stmt = $pdo->prepare($notifications_query);
$notifications_stmt->execute([$user_id]);
$notifications = $notifications_stmt->fetchAll();

// Mark notifications as read when viewing this page
if (!empty($notifications)) {
    $mark_read_query = "UPDATE notifications SET is_read = 1 WHERE user_id = ? AND (type LIKE '%dispatch%' OR type LIKE '%incident%' OR type LIKE '%suggested%')";
    $mark_read_stmt = $pdo->prepare($mark_read_query);
    $mark_read_stmt->execute([$user_id]);
}

// Close statements
$stmt = null;
$volunteer_stmt = null;
$incidents_stmt = null;
$my_unit_incidents_stmt = null;
$active_incidents_stmt = null;
$notifications_stmt = null;
if (isset($mark_read_stmt)) $mark_read_stmt = null;
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Incident Location Map - Fire & Rescue Services Management</title>
    <link href='https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css' rel='stylesheet'>
    <link rel="icon" type="image/png" sizes="32x32" href="../../img/frsm-logo.png">
    <link rel="stylesheet" href="../../css/dashboard.css">
    
    <!-- Leaflet CSS -->
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css"
          integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY="
          crossorigin=""/>
    
    <!-- Leaflet JS -->
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"
            integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo="
            crossorigin=""></script>
    
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
            grid-template-columns: 1fr 400px;
            gap: 30px;
        }

        @media (max-width: 1200px) {
            .main-grid {
                grid-template-columns: 1fr;
            }
        }

        /* Map Container */
        .map-container {
            height: 700px;
            border-radius: 12px;
            overflow: hidden;
            border: 1px solid var(--border-color);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
        }

        #incident-map {
            height: 100%;
            width: 100%;
        }

        /* Legend */
        .map-legend {
            margin-top: 20px;
            padding: 20px;
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: 12px;
        }

        .legend-title {
            font-size: 16px;
            font-weight: 700;
            color: var(--text-color);
            margin-bottom: 15px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .legend-items {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 12px;
        }

        @media (max-width: 768px) {
            .legend-items {
                grid-template-columns: 1fr;
            }
        }

        .legend-item {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 8px 12px;
            background: var(--gray-100);
            border-radius: 8px;
            border: 1px solid var(--border-color);
        }

        .dark-mode .legend-item {
            background: var(--gray-800);
        }

        .legend-icon {
            width: 20px;
            height: 20px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 10px;
            color: white;
            font-weight: bold;
        }

        .legend-text {
            font-size: 13px;
            color: var(--text-color);
        }

        /* Incident Details Panel */
        .incident-details-panel {
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: 12px;
            padding: 25px;
            max-height: 700px;
            overflow-y: auto;
        }

        .panel-header {
            margin-bottom: 20px;
        }

        .panel-title {
            font-size: 18px;
            font-weight: 700;
            color: var(--text-color);
            margin-bottom: 5px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .panel-subtitle {
            font-size: 13px;
            color: var(--text-light);
        }

        .incident-list {
            display: flex;
            flex-direction: column;
            gap: 15px;
        }

        .incident-item {
            background: var(--background-color);
            border: 1px solid var(--border-color);
            border-radius: 10px;
            padding: 20px;
            cursor: pointer;
            transition: all 0.3s ease;
            position: relative;
        }

        .incident-item:hover {
            transform: translateY(-3px);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
        }

        .incident-item.active {
            border-color: var(--primary-color);
            border-width: 2px;
            background: rgba(220, 38, 38, 0.05);
        }

        .incident-item-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 12px;
        }

        .incident-title {
            font-size: 16px;
            font-weight: 700;
            color: var(--text-color);
            margin: 0;
        }

        .incident-status {
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
        }

        .status-pending {
            background: rgba(245, 158, 11, 0.1);
            color: var(--warning);
            border: 1px solid rgba(245, 158, 11, 0.2);
        }

        .status-processing {
            background: rgba(59, 130, 246, 0.1);
            color: var(--info);
            border: 1px solid rgba(59, 130, 246, 0.2);
        }

        .status-responded {
            background: rgba(16, 185, 129, 0.1);
            color: var(--success);
            border: 1px solid rgba(16, 185, 129, 0.2);
        }

        .incident-details {
            margin-bottom: 15px;
        }

        .detail-row {
            display: flex;
            align-items: center;
            margin-bottom: 8px;
            gap: 10px;
        }

        .detail-label {
            font-size: 12px;
            color: var(--text-light);
            min-width: 80px;
        }

        .detail-value {
            font-size: 13px;
            color: var(--text-color);
            font-weight: 500;
        }

        .unit-badge {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 4px 10px;
            background: rgba(59, 130, 246, 0.1);
            color: var(--info);
            border: 1px solid rgba(59, 130, 246, 0.2);
            border-radius: 6px;
            font-size: 11px;
            font-weight: 600;
        }

        .unit-badge.my-unit {
            background: rgba(16, 185, 129, 0.1);
            color: var(--success);
            border: 1px solid rgba(16, 185, 129, 0.2);
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
            flex-wrap: wrap;
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

        /* Unit Information */
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

        /* Stats Cards */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 15px;
            margin-bottom: 25px;
        }

        @media (max-width: 768px) {
            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }
        }

        @media (max-width: 480px) {
            .stats-grid {
                grid-template-columns: 1fr;
            }
        }

        .stat-card {
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: 10px;
            padding: 20px;
            text-align: center;
            transition: all 0.3s ease;
        }

        .stat-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
        }

        .stat-icon {
            width: 50px;
            height: 50px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 15px;
            font-size: 24px;
        }

        .stat-icon.fire {
            background: rgba(220, 38, 38, 0.1);
            color: var(--danger);
        }

        .stat-icon.rescue {
            background: rgba(59, 130, 246, 0.1);
            color: var(--info);
        }

        .stat-icon.medical {
            background: rgba(16, 185, 129, 0.1);
            color: var(--success);
        }

        .stat-number {
            font-size: 28px;
            font-weight: 700;
            color: var(--text-color);
            margin-bottom: 5px;
        }

        .stat-label {
            font-size: 13px;
            color: var(--text-light);
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

        /* User profile dropdown */
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
            
            .map-container {
                height: 500px;
            }
            
            .incident-details-panel {
                max-height: 500px;
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
            
            .map-container {
                height: 400px;
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
                        <a href="suggested_unit.php" class="submenu-item">Suggested Unit</a>
                        <a href="incident_location.php" class="submenu-item active">Incident Location</a>
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
                            <input type="text" placeholder="Search incidents by location..." class="search-input" id="search-input">
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
            
            <!-- Dashboard Content -->
            <div class="dashboard-content">
                <div class="dashboard-header">
                    <div>
                        <h1 class="dashboard-title">Incident Location Map</h1>
                        <p class="dashboard-subtitle">View all active incidents on the map and see which units were suggested for response</p>
                    </div>
                </div>
                
                <!-- Content Container -->
                <div class="content-container">
                    <!-- Statistics Cards -->
                    <div class="stats-grid">
                        <div class="stat-card">
                            <div class="stat-icon fire">
                                <i class='bx bx-fire'></i>
                            </div>
                            <div class="stat-number" id="active-incidents-count"><?php echo $active_incidents_count; ?></div>
                            <div class="stat-label">Active Incidents</div>
                        </div>
                        <div class="stat-card">
                            <div class="stat-icon rescue">
                                <i class='bx bx-first-aid'></i>
                            </div>
                            <div class="stat-number"><?php echo count($my_unit_incidents); ?></div>
                            <div class="stat-label">My Unit's Dispatches</div>
                        </div>
                        <div class="stat-card">
                            <div class="stat-icon medical">
                                <i class='bx bx-plus-medical'></i>
                            </div>
                            <div class="stat-number"><?php echo count($all_incidents); ?></div>
                            <div class="stat-label">Total Incidents</div>
                        </div>
                    </div>
                    
                    <!-- Filter Tabs -->
                    <div class="section-container">
                        <div class="filter-tabs" id="filter-tabs">
                            <div class="filter-tab active" onclick="filterIncidents('all')">All Incidents</div>
                            <div class="filter-tab" onclick="filterIncidents('fire')">Fire</div>
                            <div class="filter-tab" onclick="filterIncidents('rescue')">Rescue</div>
                            <div class="filter-tab" onclick="filterIncidents('medical')">Medical</div>
                            <div class="filter-tab" onclick="filterIncidents('my_unit')">My Unit Only</div>
                            <div class="filter-tab" onclick="filterIncidents('dispatched')">Dispatched</div>
                        </div>
                    </div>
                    
                    <!-- Main Grid -->
                    <div class="main-grid">
                        <!-- Left Column: Map -->
                        <div>
                            <!-- Map Container -->
                            <div class="section-container" style="padding: 0;">
                                <div class="map-container">
                                    <div id="incident-map"></div>
                                </div>
                                
                                <!-- Legend -->
                                <div class="map-legend">
                                    <h4 class="legend-title">
                                        <i class='bx bx-map'></i>
                                        Map Legend
                                    </h4>
                                    <div class="legend-items">
                                        <div class="legend-item">
                                            <div class="legend-icon" style="background-color: #dc2626;">
                                                <i class='bx bx-fire'></i>
                                            </div>
                                            <span class="legend-text">Fire Incidents</span>
                                        </div>
                                        <div class="legend-item">
                                            <div class="legend-icon" style="background-color: #3b82f6;">
                                                <i class='bx bx-first-aid'></i>
                                            </div>
                                            <span class="legend-text">Rescue Incidents</span>
                                        </div>
                                        <div class="legend-item">
                                            <div class="legend-icon" style="background-color: #10b981;">
                                                <i class='bx bx-plus-medical'></i>
                                            </div>
                                            <span class="legend-text">Medical Incidents</span>
                                        </div>
                                        <div class="legend-item">
                                            <div class="legend-icon" style="background-color: #8b5cf6;">
                                                <i class='bx bx-question-mark'></i>
                                            </div>
                                            <span class="legend-text">Other Incidents</span>
                                        </div>
                                        <div class="legend-item">
                                            <div class="legend-icon" style="background-color: #f59e0b; border: 2px solid white;">
                                                <i class='bx bx-star'></i>
                                            </div>
                                            <span class="legend-text">My Unit's Location</span>
                                        </div>
                                        <div class="legend-item">
                                            <div class="legend-icon" style="background-color: #ffffff; color: #000; border: 2px solid #10b981;">
                                                <i class='bx bx-check'></i>
                                            </div>
                                            <span class="legend-text">My Unit Suggested</span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Right Column: Incident Details -->
                        <div>
                            <!-- Unit Information -->
                            <div class="unit-info-card">
                                <h3 class="unit-card-title">
                                    <i class='bx bx-building'></i>
                                    Your Unit Information
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
                            </div>
                            
                            <!-- Incident Details Panel -->
                            <div class="section-container">
                                <div class="panel-header">
                                    <h3 class="panel-title">
                                        <i class='bx bx-list-ul'></i>
                                        Incident Details
                                    </h3>
                                    <p class="panel-subtitle">Click on an incident to view details and see it on the map</p>
                                </div>
                                
                                <div class="incident-details-panel">
                                    <div class="incident-list" id="incident-list">
                                        <?php if (!empty($all_incidents)): ?>
                                            <?php foreach ($all_incidents as $index => $incident): 
                                                $dispatcher_name = $incident['dispatcher_first'] && $incident['dispatcher_last'] ? 
                                                    htmlspecialchars($incident['dispatcher_first'] . ' ' . $incident['dispatcher_last']) : 'Not dispatched';
                                                
                                                $severity_class = 'severity-' . $incident['severity'];
                                                $status_class = 'status-' . $incident['status'];
                                                
                                                $created_at = new DateTime($incident['created_at']);
                                                $is_my_unit = $incident['dispatched_unit_id'] == $unit_id;
                                                
                                                // Determine icon based on emergency type
                                                $emergency_icon = 'bx-question-mark';
                                                $emergency_color = '#8b5cf6';
                                                
                                                switch ($incident['emergency_type']) {
                                                    case 'fire':
                                                        $emergency_icon = 'bx-fire';
                                                        $emergency_color = '#dc2626';
                                                        break;
                                                    case 'rescue':
                                                        $emergency_icon = 'bx-first-aid';
                                                        $emergency_color = '#3b82f6';
                                                        break;
                                                    case 'medical':
                                                        $emergency_icon = 'bx-plus-medical';
                                                        $emergency_color = '#10b981';
                                                        break;
                                                }
                                            ?>
                                                <div class="incident-item" 
                                                     data-id="<?php echo $incident['id']; ?>"
                                                     data-type="<?php echo $incident['emergency_type']; ?>"
                                                     data-my-unit="<?php echo $is_my_unit ? 'yes' : 'no'; ?>"
                                                     data-dispatched="<?php echo $incident['dispatch_id'] ? 'yes' : 'no'; ?>"
                                                     onclick="selectIncident(<?php echo $index; ?>)">
                                                    
                                                    <div class="incident-item-header">
                                                        <h4 class="incident-title">
                                                            <?php echo htmlspecialchars($incident['title']); ?>
                                                        </h4>
                                                        <span class="incident-status <?php echo $status_class; ?>">
                                                            <?php echo ucfirst($incident['status']); ?>
                                                        </span>
                                                    </div>
                                                    
                                                    <div class="incident-details">
                                                        <div class="detail-row">
                                                            <span class="detail-label">Type:</span>
                                                            <span class="detail-value" style="display: flex; align-items: center; gap: 5px;">
                                                                <i class='bx <?php echo $emergency_icon; ?>' style="color: <?php echo $emergency_color; ?>;"></i>
                                                                <?php echo ucfirst($incident['emergency_type']); ?>
                                                            </span>
                                                        </div>
                                                        <div class="detail-row">
                                                            <span class="detail-label">Severity:</span>
                                                            <span class="severity-badge <?php echo $severity_class; ?>">
                                                                <?php echo ucfirst($incident['severity']); ?>
                                                            </span>
                                                        </div>
                                                        <div class="detail-row">
                                                            <span class="detail-label">Location:</span>
                                                            <span class="detail-value"><?php echo htmlspecialchars($incident['location']); ?></span>
                                                        </div>
                                                        <div class="detail-row">
                                                            <span class="detail-label">Reported:</span>
                                                            <span class="detail-value"><?php echo $created_at->format('M j, Y g:i A'); ?></span>
                                                        </div>
                                                    </div>
                                                    
                                                    <?php if ($incident['dispatch_id']): ?>
                                                        <div class="detail-row">
                                                            <span class="detail-label">Assigned Unit:</span>
                                                            <span class="detail-value">
                                                                <span class="unit-badge <?php echo $is_my_unit ? 'my-unit' : ''; ?>">
                                                                    <i class='bx bx-buildings'></i>
                                                                    <?php echo $incident['dispatched_unit_name'] ? htmlspecialchars($incident['dispatched_unit_name']) : 'Unknown Unit'; ?>
                                                                    <?php if ($is_my_unit): ?>
                                                                        <i class='bx bx-check' style="margin-left: 5px;"></i>
                                                                    <?php endif; ?>
                                                                </span>
                                                            </span>
                                                        </div>
                                                        <div class="detail-row">
                                                            <span class="detail-label">Dispatched By:</span>
                                                            <span class="detail-value"><?php echo $dispatcher_name; ?></span>
                                                        </div>
                                                    <?php else: ?>
                                                        <div class="detail-row">
                                                            <span class="detail-label">Status:</span>
                                                            <span class="detail-value" style="color: var(--warning); font-weight: 600;">
                                                                <i class='bx bx-time'></i>
                                                                Awaiting Dispatch
                                                            </span>
                                                        </div>
                                                    <?php endif; ?>
                                                </div>
                                            <?php endforeach; ?>
                                        <?php else: ?>
                                            <div class="empty-state">
                                                <i class='bx bx-map-alt'></i>
                                                <h3>No Active Incidents</h3>
                                                <p>There are currently no active incidents to display on the map.</p>
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
    </div>
    
    <script>
        // Pass PHP data to JavaScript
        const incidentsData = <?php echo json_encode($incidents_data); ?>;
        const myUnitId = <?php echo $unit_id; ?>;
        const myUnitName = "<?php echo addslashes($unit_name); ?>";
        const myUnitLocation = "<?php echo addslashes($unit_location); ?>";
        
        // Map and markers
        let map;
        let markers = [];
        let selectedMarker = null;
        
        // Location to coordinates mapping (based on location names in your database)
        const locationCoordinates = {
            // Commonwealth locations
            'Brgy. Commonwealth': { lat: 14.7236, lng: 121.0845 },
            'Commonwealth': { lat: 14.7236, lng: 121.0845 },
            'Zone 3, Brgy. Commonwealth, QC': { lat: 14.7200, lng: 121.0800 },
            'Zone 1, Brgy. Commonwealth, QC': { lat: 14.7250, lng: 121.0900 },
            
            // Holy Spirit locations
            'Brgy. Holy Spirit': { lat: 14.6900, lng: 121.0800 },
            'Holy Spirit': { lat: 14.6900, lng: 121.0800 },
            'Street 5, Brgy. Holy Spirit, QC': { lat: 14.6920, lng: 121.0820 },
            'Taga jan lang po': { lat: 14.6900, lng: 121.0800 },
            'mary rose strore sanchez street': { lat: 14.6920, lng: 121.0820 },
            '57 sanchez street': { lat: 14.6920, lng: 121.0820 },
            
            // Bagong Silangan locations
            'Barangay Bagong Silangan': { lat: 14.7400, lng: 121.1200 },
            'Bagong Silangan': { lat: 14.7400, lng: 121.1200 },
            'Block 12, Brgy. Bagong Silangan, QC': { lat: 14.7420, lng: 121.1220 },
            'Block 8, Brgy. Bagong Silangan, QC': { lat: 14.7380, lng: 121.1180 },
            
            // Batasan Hills locations
            'Barangay Batasan Hills, Quezon City': { lat: 14.6800, lng: 121.0900 },
            'Batasan Hills': { lat: 14.6800, lng: 121.0900 },
            'Block 10, Brgy. Batasan, QC': { lat: 14.6820, lng: 121.0920 },
            '456 Elm St, Batasan Hills, QC': { lat: 14.6820, lng: 121.0920 },
            
            // Payatas locations
            'Payatas': { lat: 14.7100, lng: 121.1100 },
            'Sitio Masagana, Brgy. Payatas, QC': { lat: 14.7120, lng: 121.1120 },
            
            // Other locations from your database
            '123 Main St, Holy Spirit, QC': { lat: 14.6900, lng: 121.0800 },
            'Testing': { lat: 14.7000, lng: 121.1000 },
            '8-4C HACIENDA BALAI, BRGY. KALIGAYAHAN, QUEZON CITY': { lat: 14.6500, lng: 121.0500 },
            'Quezon city': { lat: 14.6760, lng: 121.0437 },
            'asddddddddddd': { lat: 14.7000, lng: 121.1000 },
            'jashdkjahdkjahdkahsdkhakdhaskjdhkjasa': { lat: 14.7000, lng: 121.1000 },
            'adasdasdasdadsadasdad': { lat: 14.7000, lng: 121.1000 },
            
            // Default for unknown locations
            'default': { lat: 14.6760, lng: 121.0437 } // Quezon City center
        };
        
        // Get coordinates for a location name
        function getCoordinatesForLocation(locationName) {
            if (!locationName) return locationCoordinates.default;
            
            const lowerLocation = locationName.toLowerCase();
            
            // Check for exact matches first
            for (const [key, coords] of Object.entries(locationCoordinates)) {
                if (lowerLocation.includes(key.toLowerCase()) || key.toLowerCase().includes(lowerLocation)) {
                    return coords;
                }
            }
            
            // Check for barangay names
            if (lowerLocation.includes('commonwealth')) return locationCoordinates['Commonwealth'];
            if (lowerLocation.includes('holy spirit')) return locationCoordinates['Holy Spirit'];
            if (lowerLocation.includes('bagong silangan')) return locationCoordinates['Bagong Silangan'];
            if (lowerLocation.includes('batasan')) return locationCoordinates['Batasan Hills'];
            if (lowerLocation.includes('payatas')) return locationCoordinates['Payatas'];
            if (lowerLocation.includes('quezon city')) return locationCoordinates['Quezon city'];
            
            // Add a small random offset for locations at the same coordinates
            const baseCoords = locationCoordinates.default;
            return {
                lat: baseCoords.lat + (Math.random() * 0.01 - 0.005),
                lng: baseCoords.lng + (Math.random() * 0.01 - 0.005)
            };
        }
        
        // Initialize map
        function initMap() {
            // Default to Quezon City center
            const defaultCoords = locationCoordinates.default;
            
            // Create map
            map = L.map('incident-map').setView([defaultCoords.lat, defaultCoords.lng], 13);
            
            // Add OpenStreetMap tiles
            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors',
                maxZoom: 19
            }).addTo(map);
            
            // Add your unit's location
            const unitCoords = getCoordinatesForLocation(myUnitLocation);
            const unitIcon = L.divIcon({
                className: 'custom-div-icon',
                html: `<div style="background-color: #f59e0b; width: 30px; height: 30px; border-radius: 50%; border: 3px solid white; display: flex; align-items: center; justify-content: center; color: white; font-weight: bold; box-shadow: 0 2px 5px rgba(0,0,0,0.3);">
                         <i class='bx bx-building'></i>
                       </div>`,
                iconSize: [30, 30],
                iconAnchor: [15, 15],
                popupAnchor: [0, -15]
            });
            
            const unitMarker = L.marker([unitCoords.lat, unitCoords.lng], { icon: unitIcon }).addTo(map);
            unitMarker.bindPopup(`
                <div style="padding: 10px;">
                    <h4 style="margin: 0 0 10px 0; color: #f59e0b;">
                        <i class='bx bx-building'></i> ${myUnitName}
                    </h4>
                    <p style="margin: 0; font-size: 12px;">
                        <strong>Your Unit Location:</strong><br>
                        ${myUnitLocation}<br><br>
                        <strong>Status:</strong> Active
                    </p>
                </div>
            `);
            
            // Add all incident markers
            incidentsData.forEach((incident, index) => {
                addIncidentMarker(incident, index);
            });
            
            // Fit map to show all markers
            if (markers.length > 0) {
                const group = new L.featureGroup(markers);
                map.fitBounds(group.getBounds().pad(0.1));
            }
        }
        
        // Add an incident marker to the map
        function addIncidentMarker(incident, index) {
            const coords = getCoordinatesForLocation(incident.location);
            
            // Determine marker color based on emergency type
            let color = '#8b5cf6'; // Default purple for other
            let iconClass = 'bx-question-mark';
            
            switch (incident.emergency_type) {
                case 'fire':
                    color = '#dc2626';
                    iconClass = 'bx-fire';
                    break;
                case 'rescue':
                    color = '#3b82f6';
                    iconClass = 'bx-first-aid';
                    break;
                case 'medical':
                    color = '#10b981';
                    iconClass = 'bx-plus-medical';
                    break;
            }
            
            // Check if this incident has your unit suggested
            const isMyUnit = incident.is_my_unit === 'yes';
            const borderColor = isMyUnit ? '#10b981' : 'white';
            const borderWidth = isMyUnit ? 3 : 2;
            
            // Create custom div icon
            const icon = L.divIcon({
                className: 'custom-div-icon',
                html: `<div style="background-color: ${color}; width: 35px; height: 35px; border-radius: 50%; border: ${borderWidth}px solid ${borderColor}; display: flex; align-items: center; justify-content: center; color: white; font-weight: bold; box-shadow: 0 2px 5px rgba(0,0,0,0.3); cursor: pointer;">
                         <i class='bx ${iconClass}'></i>
                         ${isMyUnit ? '<div style="position: absolute; top: -5px; right: -5px; background: #10b981; width: 15px; height: 15px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 10px; border: 2px solid white;"><i class=\'bx bx-check\'></i></div>' : ''}
                       </div>`,
                iconSize: [35, 35],
                iconAnchor: [17, 17],
                popupAnchor: [0, -17]
            });
            
            // Create marker
            const marker = L.marker([coords.lat, coords.lng], { icon: icon });
            
            // Add popup with incident details
            const popupContent = `
                <div style="padding: 10px; max-width: 300px;">
                    <h4 style="margin: 0 0 10px 0; color: ${color};">
                        <i class='bx ${iconClass}'></i> ${incident.title}
                    </h4>
                    <div style="font-size: 12px; line-height: 1.4;">
                        <p style="margin: 5px 0;"><strong>Type:</strong> ${incident.emergency_type}</p>
                        <p style="margin: 5px 0;"><strong>Severity:</strong> <span class="severity-${incident.severity}">${incident.severity}</span></p>
                        <p style="margin: 5px 0;"><strong>Location:</strong> ${incident.location}</p>
                        <p style="margin: 5px 0;"><strong>Reported:</strong> ${new Date(incident.created_at).toLocaleString()}</p>
                        <p style="margin: 5px 0;"><strong>Caller:</strong> ${incident.caller_name} (${incident.caller_phone})</p>
                        <p style="margin: 5px 0;"><strong>Status:</strong> ${incident.status}</p>
                        ${incident.dispatched_unit_name ? `
                            <p style="margin: 5px 0;">
                                <strong>Assigned Unit:</strong> 
                                <span style="color: ${incident.is_my_unit === 'yes' ? '#10b981' : '#3b82f6'}; font-weight: bold;">
                                    ${incident.dispatched_unit_name} ${incident.is_my_unit === 'yes' ? '(Your Unit)' : ''}
                                </span>
                            </p>
                            <p style="margin: 5px 0;"><strong>Dispatched By:</strong> ${incident.dispatcher_name}</p>
                        ` : '<p style="margin: 5px 0; color: #f59e0b;"><strong>Status:</strong> Awaiting Dispatch</p>'}
                    </div>
                </div>
            `;
            
            marker.bindPopup(popupContent);
            marker.addTo(map);
            
            // Store reference to marker
            markers[index] = marker;
            
            // Add click event to highlight incident in list
            marker.on('click', function() {
                selectIncident(index);
            });
            
            return marker;
        }
        
        // Select an incident from the list
        function selectIncident(index) {
            // Remove active class from all incident items
            document.querySelectorAll('.incident-item').forEach(item => {
                item.classList.remove('active');
            });
            
            // Add active class to selected incident
            const incidentItems = document.querySelectorAll('.incident-item');
            if (incidentItems[index]) {
                incidentItems[index].classList.add('active');
                
                // Scroll to selected incident
                incidentItems[index].scrollIntoView({ behavior: 'smooth', block: 'nearest' });
            }
            
            // Close any open popups
            map.closePopup();
            
            // Open popup for selected marker
            if (markers[index]) {
                markers[index].openPopup();
                map.setView(markers[index].getLatLng(), 15);
                
                // Highlight the marker
                if (selectedMarker) {
                    // Reset previous selected marker style if needed
                }
                selectedMarker = markers[index];
            }
        }
        
        // Filter incidents
        function filterIncidents(filter) {
            // Update active tab
            document.querySelectorAll('.filter-tab').forEach(tab => {
                tab.classList.remove('active');
            });
            event.target.classList.add('active');
            
            // Filter incident items
            document.querySelectorAll('.incident-item').forEach(item => {
                const type = item.getAttribute('data-type');
                const isMyUnit = item.getAttribute('data-my-unit');
                const isDispatched = item.getAttribute('data-dispatched');
                
                let show = false;
                
                switch (filter) {
                    case 'all':
                        show = true;
                        break;
                    case 'fire':
                        show = type === 'fire';
                        break;
                    case 'rescue':
                        show = type === 'rescue';
                        break;
                    case 'medical':
                        show = type === 'medical';
                        break;
                    case 'my_unit':
                        show = isMyUnit === 'yes';
                        break;
                    case 'dispatched':
                        show = isDispatched === 'yes';
                        break;
                }
                
                item.style.display = show ? 'block' : 'none';
                
                // Also show/hide markers
                const index = Array.from(document.querySelectorAll('.incident-item')).indexOf(item);
                if (markers[index]) {
                    if (show) {
                        map.addLayer(markers[index]);
                    } else {
                        map.removeLayer(markers[index]);
                    }
                }
            });
        }
        
        document.addEventListener('DOMContentLoaded', function() {
            // Initialize map
            initMap();
            
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
                    
                    document.querySelectorAll('.incident-item').forEach(item => {
                        const text = item.textContent.toLowerCase();
                        if (text.includes(searchTerm)) {
                            item.style.display = 'block';
                            
                            // Show corresponding marker
                            const index = Array.from(document.querySelectorAll('.incident-item')).indexOf(item);
                            if (markers[index] && !map.hasLayer(markers[index])) {
                                map.addLayer(markers[index]);
                            }
                        } else {
                            item.style.display = 'none';
                            
                            // Hide corresponding marker
                            const index = Array.from(document.querySelectorAll('.incident-item')).indexOf(item);
                            if (markers[index] && map.hasLayer(markers[index])) {
                                map.removeLayer(markers[index]);
                            }
                        }
                    });
                });
            }
            
            // Auto-refresh data every 60 seconds
            setInterval(refreshData, 60000);
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
                    
                    // Refresh map tiles for theme
                    setTimeout(() => map.invalidateSize(), 300);
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
        
        // Refresh data
        function refreshData() {
            console.log('Refreshing incident data...');
            // In a real application, this would make an AJAX call
            // For now, we'll reload the page every 5 minutes
            // setTimeout(() => location.reload(), 300000);
        }
    </script>
</body>
</html>