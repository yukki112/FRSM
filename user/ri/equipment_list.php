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
           va.unit_id, u.unit_name, u.unit_code
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
$unit_id = $volunteer['unit_id'];
$unit_name = htmlspecialchars($volunteer['unit_name']);

// Get all equipment/resources
$equipment_query = "
    SELECT 
        r.id,
        r.resource_name,
        r.resource_type,
        r.category,
        r.description,
        r.quantity,
        r.available_quantity,
        r.unit_of_measure,
        r.condition_status,
        r.location,
        r.storage_area,
        r.unit_id,
        r.last_inspection,
        r.next_inspection,
        r.is_active,
        r.created_at,
        r.updated_at,
        u.unit_name
    FROM resources r
    LEFT JOIN units u ON r.unit_id = u.id
    WHERE r.is_active = 1
    ORDER BY r.resource_name
";

$equipment_stmt = $pdo->prepare($equipment_query);
$equipment_stmt->execute();
$equipment_list = $equipment_stmt->fetchAll();

// Get equipment assigned to volunteer's unit
$unit_equipment = array_filter($equipment_list, function($item) use ($unit_id) {
    return $item['unit_id'] == $unit_id;
});

// Handle maintenance request submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_maintenance_request'])) {
    $resource_id = $_POST['resource_id'];
    $request_type = $_POST['request_type'];
    $priority = $_POST['priority'];
    $description = $_POST['description'];
    $notes = $_POST['notes'] ?? '';
    
    // Validate input
    if (empty($resource_id) || empty($description)) {
        $error_message = "Please fill in all required fields";
    } else {
        try {
            // Insert maintenance request
            $insert_query = "
                INSERT INTO maintenance_requests 
                (resource_id, requested_by, request_type, priority, description, notes, status, requested_date)
                VALUES (?, ?, ?, ?, ?, ?, 'pending', NOW())
            ";
            
            $insert_stmt = $pdo->prepare($insert_query);
            $insert_stmt->execute([
                $resource_id,
                $user_id,
                $request_type,
                $priority,
                $description,
                $notes
            ]);
            
            $success_message = "Maintenance request submitted successfully!";
            
            // Refresh equipment list
            $equipment_stmt->execute();
            $equipment_list = $equipment_stmt->fetchAll();
            $unit_equipment = array_filter($equipment_list, function($item) use ($unit_id) {
                return $item['unit_id'] == $unit_id;
            });
            
        } catch (PDOException $e) {
            $error_message = "Error submitting request: " . $e->getMessage();
        }
    }
}

// Get maintenance requests submitted by this user
$maintenance_requests_query = "
    SELECT 
        mr.*,
        r.resource_name,
        r.resource_type,
        r.category,
        u.unit_name
    FROM maintenance_requests mr
    JOIN resources r ON mr.resource_id = r.id
    LEFT JOIN units u ON r.unit_id = u.id
    WHERE mr.requested_by = ?
    ORDER BY mr.requested_date DESC
    LIMIT 20
";

$maintenance_requests_stmt = $pdo->prepare($maintenance_requests_query);
$maintenance_requests_stmt->execute([$user_id]);
$user_maintenance_requests = $maintenance_requests_stmt->fetchAll();

// Get maintenance request types for dropdown
$request_types = ['routine_maintenance', 'repair', 'inspection', 'calibration', 'disposal'];
$priority_levels = ['low', 'medium', 'high', 'critical'];

// Close statements
$stmt = null;
$volunteer_stmt = null;
$equipment_stmt = null;
$maintenance_requests_stmt = null;
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Equipment List - Fire & Rescue Services Management</title>
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

        .btn-sm {
            padding: 6px 12px;
            font-size: 12px;
            border-radius: 6px;
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

        .equipment-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
        }

        .equipment-table th {
            background: var(--card-bg);
            padding: 15px;
            text-align: left;
            font-weight: 600;
            color: var(--text-color);
            border-bottom: 2px solid var(--border-color);
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }

        .equipment-table td {
            padding: 15px;
            border-bottom: 1px solid var(--border-color);
            font-size: 14px;
        }

        .equipment-table tr:hover {
            background: var(--gray-100);
        }

        .dark-mode .equipment-table tr:hover {
            background: var(--gray-800);
        }

        .status-badge {
            padding: 5px 12px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 600;
            display: inline-block;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .status-serviceable {
            background: rgba(16, 185, 129, 0.1);
            color: var(--success);
            border: 1px solid rgba(16, 185, 129, 0.2);
        }

        .status-maintenance {
            background: rgba(245, 158, 11, 0.1);
            color: var(--warning);
            border: 1px solid rgba(245, 158, 11, 0.2);
        }

        .status-condemned {
            background: rgba(220, 38, 38, 0.1);
            color: var(--danger);
            border: 1px solid rgba(220, 38, 38, 0.2);
        }

        .status-out {
            background: rgba(107, 114, 128, 0.1);
            color: var(--gray-500);
            border: 1px solid rgba(107, 114, 128, 0.2);
        }

        .category-badge {
            padding: 4px 10px;
            border-radius: 6px;
            font-size: 11px;
            font-weight: 600;
            display: inline-block;
        }

        .category-firefighting {
            background: rgba(220, 38, 38, 0.1);
            color: var(--danger);
            border: 1px solid rgba(220, 38, 38, 0.2);
        }

        .category-medical {
            background: rgba(16, 185, 129, 0.1);
            color: var(--success);
            border: 1px solid rgba(16, 185, 129, 0.2);
        }

        .category-rescue {
            background: rgba(59, 130, 246, 0.1);
            color: var(--info);
            border: 1px solid rgba(59, 130, 246, 0.2);
        }

        .category-ppe {
            background: rgba(168, 85, 247, 0.1);
            color: #8b5cf6;
            border: 1px solid rgba(168, 85, 247, 0.2);
        }

        .category-communication {
            background: rgba(245, 158, 11, 0.1);
            color: var(--warning);
            border: 1px solid rgba(245, 158, 11, 0.2);
        }

        .category-other {
            background: rgba(107, 114, 128, 0.1);
            color: var(--gray-500);
            border: 1px solid rgba(107, 114, 128, 0.2);
        }

        .availability-indicator {
            display: inline-block;
            width: 10px;
            height: 10px;
            border-radius: 50%;
            margin-right: 8px;
        }

        .available-high {
            background: var(--success);
        }

        .available-medium {
            background: var(--warning);
        }

        .available-low {
            background: var(--danger);
        }

        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.5);
            overflow: auto;
        }

        .modal-content {
            background-color: var(--background-color);
            margin: 5% auto;
            padding: 30px;
            border-radius: 16px;
            width: 90%;
            max-width: 600px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.2);
        }

        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 2px solid var(--border-color);
        }

        .modal-title {
            font-size: 20px;
            font-weight: 700;
            color: var(--text-color);
            margin: 0;
        }

        .close-modal {
            background: none;
            border: none;
            font-size: 24px;
            cursor: pointer;
            color: var(--text-light);
            transition: color 0.3s;
        }

        .close-modal:hover {
            color: var(--primary-color);
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: var(--text-color);
            font-size: 14px;
        }

        .form-control {
            width: 100%;
            padding: 12px;
            border: 1px solid var(--border-color);
            border-radius: 8px;
            background: var(--background-color);
            color: var(--text-color);
            font-size: 14px;
        }

        .form-control:focus {
            outline: none;
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(220, 38, 38, 0.1);
        }

        .form-textarea {
            min-height: 120px;
            resize: vertical;
        }

        .form-actions {
            display: flex;
            justify-content: flex-end;
            gap: 10px;
            margin-top: 30px;
        }

        .alert {
            padding: 15px 20px;
            border-radius: 8px;
            margin-bottom: 20px;
            font-size: 14px;
        }

        .alert-success {
            background: rgba(16, 185, 129, 0.1);
            color: var(--success);
            border: 1px solid rgba(16, 185, 129, 0.2);
        }

        .alert-error {
            background: rgba(220, 38, 38, 0.1);
            color: var(--danger);
            border: 1px solid rgba(220, 38, 38, 0.2);
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

        .tab-container {
            margin-bottom: 20px;
        }

        .tab-buttons {
            display: flex;
            border-bottom: 2px solid var(--border-color);
        }

        .tab-button {
            padding: 12px 24px;
            background: none;
            border: none;
            cursor: pointer;
            font-size: 14px;
            font-weight: 600;
            color: var(--text-light);
            transition: all 0.3s ease;
            position: relative;
        }

        .tab-button.active {
            color: var(--primary-color);
        }

        .tab-button.active::after {
            content: '';
            position: absolute;
            bottom: -2px;
            left: 0;
            right: 0;
            height: 2px;
            background: var(--primary-color);
        }

        .tab-content {
            display: none;
        }

        .tab-content.active {
            display: block;
        }

        .unit-info-card {
            background: linear-gradient(135deg, #fef2f2 0%, #fee2e2 100%);
            border: 1px solid #fecaca;
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 20px;
        }

        .dark-mode .unit-info-card {
            background: linear-gradient(135deg, #1e293b 0%, #2d3748 100%);
            border-color: #4b5563;
        }

        .unit-title {
            font-size: 18px;
            font-weight: 700;
            color: var(--danger);
            margin-bottom: 10px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .unit-details {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
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
            
            .stats-container {
                grid-template-columns: repeat(2, 1fr);
            }
            
            .filter-container {
                grid-template-columns: 1fr;
            }
            
            .equipment-table {
                display: block;
                overflow-x: auto;
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
            
            .tab-buttons {
                flex-direction: column;
            }
            
            .tab-button {
                width: 100%;
                text-align: left;
            }
            
            .filter-actions {
                flex-direction: column;
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
                    <div id="inventory" class="submenu active">
                        <a href="equipment_list.php" class="submenu-item active">Equipment List</a>
                        <a href="stock_levels.php" class="submenu-item">Stock Levels</a>
                        <a href="maintenance_logs.php" class="submenu-item">Maintenance Logs</a>
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
                            <input type="text" placeholder="Search equipment..." class="search-input" id="search-input">
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
                        <h1 class="dashboard-title">Equipment List</h1>
                        <p class="dashboard-subtitle">View and request maintenance for equipment and resources</p>
                    </div>
                </div>
                
                <!-- Content Container -->
                <div class="content-container">
                    <?php if (isset($success_message)): ?>
                        <div class="alert alert-success">
                            <i class='bx bx-check-circle'></i> <?php echo $success_message; ?>
                        </div>
                    <?php endif; ?>
                    
                    <?php if (isset($error_message)): ?>
                        <div class="alert alert-error">
                            <i class='bx bx-error-circle'></i> <?php echo $error_message; ?>
                        </div>
                    <?php endif; ?>
                    
                    <!-- Unit Information -->
                    <?php if ($unit_name): ?>
                        <div class="unit-info-card">
                            <h3 class="unit-title">
                                <i class='bx bx-group'></i>
                                Your Unit: <?php echo $unit_name; ?>
                            </h3>
                            <div class="unit-details">
                                <div class="unit-detail">
                                    <span class="unit-label">Assigned Equipment</span>
                                    <span class="unit-value"><?php echo count($unit_equipment); ?> items</span>
                                </div>
                                <div class="unit-detail">
                                    <span class="unit-label">Total Equipment</span>
                                    <span class="unit-value"><?php echo count($equipment_list); ?> items</span>
                                </div>
                                <div class="unit-detail">
                                    <span class="unit-label">Serviceable Equipment</span>
                                    <span class="unit-value">
                                        <?php 
                                        $serviceable_count = count(array_filter($equipment_list, function($item) {
                                            return $item['condition_status'] === 'Serviceable';
                                        }));
                                        echo $serviceable_count;
                                        ?>
                                    </span>
                                </div>
                                <div class="unit-detail">
                                    <span class="unit-label">Your Requests</span>
                                    <span class="unit-value"><?php echo count($user_maintenance_requests); ?> requests</span>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>
                    
                    <!-- Tabs -->
                    <div class="tab-container">
                        <div class="tab-buttons">
                            <button class="tab-button active" onclick="switchTab('all-equipment')">
                                <i class='bx bx-list-ul'></i> All Equipment
                            </button>
                            <button class="tab-button" onclick="switchTab('unit-equipment')">
                                <i class='bx bx-group'></i> My Unit Equipment
                            </button>
                            <button class="tab-button" onclick="switchTab('maintenance-requests')">
                                <i class='bx bx-wrench'></i> My Maintenance Requests
                            </button>
                            <button class="tab-button" onclick="openMaintenanceModal()">
                                <i class='bx bx-plus'></i> Request Maintenance
                            </button>
                        </div>
                        
                        <!-- All Equipment Tab -->
                        <div id="all-equipment" class="tab-content active">
                            <div class="section-container">
                                <h3 class="section-title">
                                    <i class='bx bx-list-ul'></i>
                                    All Equipment & Resources
                                </h3>
                                
                                <?php if (!empty($equipment_list)): ?>
                                    <div class="filter-container">
                                        <div class="filter-group">
                                            <label class="filter-label">Search</label>
                                            <input type="text" id="search-all" class="filter-input" placeholder="Search by name, category, or status...">
                                        </div>
                                        
                                        <div class="filter-group">
                                            <label class="filter-label">Category</label>
                                            <select id="category-filter-all" class="filter-select">
                                                <option value="all">All Categories</option>
                                                <option value="Firefighting">Firefighting</option>
                                                <option value="Medical">Medical</option>
                                                <option value="Rescue">Rescue</option>
                                                <option value="PPE">PPE</option>
                                                <option value="Communication">Communication</option>
                                                <option value="Other">Other</option>
                                            </select>
                                        </div>
                                        
                                        <div class="filter-group">
                                            <label class="filter-label">Status</label>
                                            <select id="status-filter-all" class="filter-select">
                                                <option value="all">All Status</option>
                                                <option value="Serviceable">Serviceable</option>
                                                <option value="Under Maintenance">Under Maintenance</option>
                                                <option value="Condemned">Condemned</option>
                                            </select>
                                        </div>
                                        
                                        <div class="filter-actions">
                                            <button onclick="filterEquipment('all')" class="btn btn-primary">
                                                <i class='bx bx-filter-alt'></i> Apply Filters
                                            </button>
                                            <button onclick="resetFilters('all')" class="btn btn-secondary">
                                                <i class='bx bx-reset'></i> Reset
                                            </button>
                                        </div>
                                    </div>
                                    
                                    <table class="equipment-table" id="all-equipment-table">
                                        <thead>
                                            <tr>
                                                <th>Equipment Name</th>
                                                <th>Type</th>
                                                <th>Category</th>
                                                <th>Quantity</th>
                                                <th>Available</th>
                                                <th>Status</th>
                                                <th>Location</th>
                                                <th>Unit</th>
                                                <th>Actions</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($equipment_list as $equipment): 
                                                $available_percentage = $equipment['quantity'] > 0 ? 
                                                    ($equipment['available_quantity'] / $equipment['quantity']) * 100 : 0;
                                                
                                                $availability_class = 'available-high';
                                                if ($available_percentage < 30) {
                                                    $availability_class = 'available-low';
                                                } elseif ($available_percentage < 60) {
                                                    $availability_class = 'available-medium';
                                                }
                                                
                                                $category_class = 'category-other';
                                                switch ($equipment['category']) {
                                                    case 'Firefighting': $category_class = 'category-firefighting'; break;
                                                    case 'Medical': $category_class = 'category-medical'; break;
                                                    case 'Rescue': $category_class = 'category-rescue'; break;
                                                    case 'PPE': $category_class = 'category-ppe'; break;
                                                    case 'Communication': $category_class = 'category-communication'; break;
                                                }
                                                
                                                $status_class = 'status-serviceable';
                                                switch ($equipment['condition_status']) {
                                                    case 'Under Maintenance': $status_class = 'status-maintenance'; break;
                                                    case 'Condemned': $status_class = 'status-condemned'; break;
                                                    case 'Out of Service': $status_class = 'status-out'; break;
                                                }
                                            ?>
                                                <tr data-category="<?php echo $equipment['category']; ?>" 
                                                    data-status="<?php echo $equipment['condition_status']; ?>">
                                                    <td>
                                                        <strong><?php echo htmlspecialchars($equipment['resource_name']); ?></strong>
                                                        <?php if (!empty($equipment['description'])): ?>
                                                            <br><small style="color: var(--text-light);"><?php echo substr(htmlspecialchars($equipment['description']), 0, 50) . '...'; ?></small>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td><?php echo htmlspecialchars($equipment['resource_type']); ?></td>
                                                    <td>
                                                        <span class="category-badge <?php echo $category_class; ?>">
                                                            <?php echo htmlspecialchars($equipment['category']); ?>
                                                        </span>
                                                    </td>
                                                    <td><?php echo $equipment['quantity']; ?> <?php echo $equipment['unit_of_measure'] ?: 'units'; ?></td>
                                                    <td>
                                                        <span class="availability-indicator <?php echo $availability_class; ?>"></span>
                                                        <?php echo $equipment['available_quantity']; ?> available
                                                    </td>
                                                    <td>
                                                        <span class="status-badge <?php echo $status_class; ?>">
                                                            <?php echo htmlspecialchars($equipment['condition_status']); ?>
                                                        </span>
                                                    </td>
                                                    <td><?php echo htmlspecialchars($equipment['location'] ?: 'Not specified'); ?></td>
                                                    <td><?php echo htmlspecialchars($equipment['unit_name'] ?: 'Unassigned'); ?></td>
                                                    <td>
                                                        <button onclick="viewEquipmentDetails(<?php echo $equipment['id']; ?>)" 
                                                                class="btn btn-sm btn-secondary" style="margin-bottom: 5px;">
                                                            <i class='bx bx-info-circle'></i> Details
                                                        </button>
                                                        <?php if ($equipment['condition_status'] !== 'Serviceable'): ?>
                                                            <button onclick="requestMaintenanceFor(<?php echo $equipment['id']; ?>, '<?php echo htmlspecialchars($equipment['resource_name']); ?>')" 
                                                                    class="btn btn-sm btn-primary">
                                                                <i class='bx bx-wrench'></i> Request
                                                            </button>
                                                        <?php endif; ?>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                <?php else: ?>
                                    <div class="empty-state">
                                        <i class='bx bx-cube-alt'></i>
                                        <h3>No Equipment Found</h3>
                                        <p>There are no equipment records in the system.</p>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                        
                        <!-- Unit Equipment Tab -->
                        <div id="unit-equipment" class="tab-content">
                            <div class="section-container">
                                <h3 class="section-title">
                                    <i class='bx bx-group'></i>
                                    My Unit Equipment
                                    <?php if (!empty($unit_equipment)): ?>
                                        <span class="badge badge-info"><?php echo count($unit_equipment); ?> items</span>
                                    <?php endif; ?>
                                </h3>
                                
                                <?php if (!empty($unit_equipment)): ?>
                                    <div class="filter-container">
                                        <div class="filter-group">
                                            <label class="filter-label">Search</label>
                                            <input type="text" id="search-unit" class="filter-input" placeholder="Search unit equipment...">
                                        </div>
                                        
                                        <div class="filter-group">
                                            <label class="filter-label">Category</label>
                                            <select id="category-filter-unit" class="filter-select">
                                                <option value="all">All Categories</option>
                                                <option value="Firefighting">Firefighting</option>
                                                <option value="Medical">Medical</option>
                                                <option value="Rescue">Rescue</option>
                                                <option value="PPE">PPE</option>
                                                <option value="Communication">Communication</option>
                                                <option value="Other">Other</option>
                                            </select>
                                        </div>
                                        
                                        <div class="filter-actions">
                                            <button onclick="filterUnitEquipment()" class="btn btn-primary">
                                                <i class='bx bx-filter-alt'></i> Apply Filters
                                            </button>
                                            <button onclick="resetUnitFilters()" class="btn btn-secondary">
                                                <i class='bx bx-reset'></i> Reset
                                            </button>
                                        </div>
                                    </div>
                                    
                                    <table class="equipment-table" id="unit-equipment-table">
                                        <thead>
                                            <tr>
                                                <th>Equipment Name</th>
                                                <th>Type</th>
                                                <th>Category</th>
                                                <th>Quantity</th>
                                                <th>Available</th>
                                                <th>Status</th>
                                                <th>Location</th>
                                                <th>Last Inspection</th>
                                                <th>Actions</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($unit_equipment as $equipment): 
                                                $available_percentage = $equipment['quantity'] > 0 ? 
                                                    ($equipment['available_quantity'] / $equipment['quantity']) * 100 : 0;
                                                
                                                $availability_class = 'available-high';
                                                if ($available_percentage < 30) {
                                                    $availability_class = 'available-low';
                                                } elseif ($available_percentage < 60) {
                                                    $availability_class = 'available-medium';
                                                }
                                                
                                                $category_class = 'category-other';
                                                switch ($equipment['category']) {
                                                    case 'Firefighting': $category_class = 'category-firefighting'; break;
                                                    case 'Medical': $category_class = 'category-medical'; break;
                                                    case 'Rescue': $category_class = 'category-rescue'; break;
                                                    case 'PPE': $category_class = 'category-ppe'; break;
                                                    case 'Communication': $category_class = 'category-communication'; break;
                                                }
                                                
                                                $status_class = 'status-serviceable';
                                                switch ($equipment['condition_status']) {
                                                    case 'Under Maintenance': $status_class = 'status-maintenance'; break;
                                                    case 'Condemned': $status_class = 'status-condemned'; break;
                                                    case 'Out of Service': $status_class = 'status-out'; break;
                                                }
                                                
                                                $last_inspection = $equipment['last_inspection'] ? 
                                                    date('M d, Y', strtotime($equipment['last_inspection'])) : 'Never';
                                            ?>
                                                <tr data-category="<?php echo $equipment['category']; ?>">
                                                    <td>
                                                        <strong><?php echo htmlspecialchars($equipment['resource_name']); ?></strong>
                                                        <?php if (!empty($equipment['description'])): ?>
                                                            <br><small style="color: var(--text-light);"><?php echo substr(htmlspecialchars($equipment['description']), 0, 50) . '...'; ?></small>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td><?php echo htmlspecialchars($equipment['resource_type']); ?></td>
                                                    <td>
                                                        <span class="category-badge <?php echo $category_class; ?>">
                                                            <?php echo htmlspecialchars($equipment['category']); ?>
                                                        </span>
                                                    </td>
                                                    <td><?php echo $equipment['quantity']; ?> <?php echo $equipment['unit_of_measure'] ?: 'units'; ?></td>
                                                    <td>
                                                        <span class="availability-indicator <?php echo $availability_class; ?>"></span>
                                                        <?php echo $equipment['available_quantity']; ?> available
                                                    </td>
                                                    <td>
                                                        <span class="status-badge <?php echo $status_class; ?>">
                                                            <?php echo htmlspecialchars($equipment['condition_status']); ?>
                                                        </span>
                                                    </td>
                                                    <td><?php echo htmlspecialchars($equipment['location'] ?: 'Not specified'); ?></td>
                                                    <td><?php echo $last_inspection; ?></td>
                                                    <td>
                                                        <button onclick="viewEquipmentDetails(<?php echo $equipment['id']; ?>)" 
                                                                class="btn btn-sm btn-secondary" style="margin-bottom: 5px;">
                                                            <i class='bx bx-info-circle'></i> Details
                                                        </button>
                                                        <?php if ($equipment['condition_status'] !== 'Serviceable'): ?>
                                                            <button onclick="requestMaintenanceFor(<?php echo $equipment['id']; ?>, '<?php echo htmlspecialchars($equipment['resource_name']); ?>')" 
                                                                    class="btn btn-sm btn-primary">
                                                                <i class='bx bx-wrench'></i> Request
                                                            </button>
                                                        <?php endif; ?>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                <?php else: ?>
                                    <div class="empty-state">
                                        <i class='bx bx-group'></i>
                                        <h3>No Unit Equipment</h3>
                                        <p>There are no equipment items assigned to your unit.</p>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                        
                        <!-- Maintenance Requests Tab -->
                        <div id="maintenance-requests" class="tab-content">
                            <div class="section-container">
                                <h3 class="section-title">
                                    <i class='bx bx-wrench'></i>
                                    My Maintenance Requests
                                    <?php if (!empty($user_maintenance_requests)): ?>
                                        <span class="badge badge-info"><?php echo count($user_maintenance_requests); ?> requests</span>
                                    <?php endif; ?>
                                </h3>
                                
                                <?php if (!empty($user_maintenance_requests)): ?>
                                    <table class="equipment-table">
                                        <thead>
                                            <tr>
                                                <th>Resource</th>
                                                <th>Type</th>
                                                <th>Priority</th>
                                                <th>Description</th>
                                                <th>Date Requested</th>
                                                <th>Status</th>
                                                <th>Actions</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($user_maintenance_requests as $request): 
                                                $status_class = 'status-maintenance';
                                                switch ($request['status']) {
                                                    case 'approved': $status_class = 'status-serviceable'; break;
                                                    case 'completed': $status_class = 'status-serviceable'; break;
                                                    case 'rejected': $status_class = 'status-condemned'; break;
                                                    case 'cancelled': $status_class = 'status-out'; break;
                                                }
                                                
                                                $priority_class = '';
                                                switch ($request['priority']) {
                                                    case 'high': $priority_class = 'status-condemned'; break;
                                                    case 'critical': $priority_class = 'status-condemned'; break;
                                                    case 'medium': $priority_class = 'status-maintenance'; break;
                                                    case 'low': $priority_class = 'status-serviceable'; break;
                                                }
                                            ?>
                                                <tr>
                                                    <td>
                                                        <strong><?php echo htmlspecialchars($request['resource_name']); ?></strong>
                                                        <br>
                                                        <small style="color: var(--text-light);">
                                                            <?php echo htmlspecialchars($request['category']); ?> - 
                                                            <?php echo htmlspecialchars($request['unit_name'] ?: 'Unassigned'); ?>
                                                        </small>
                                                    </td>
                                                    <td>
                                                        <span class="category-badge category-other">
                                                            <?php echo str_replace('_', ' ', htmlspecialchars($request['request_type'])); ?>
                                                        </span>
                                                    </td>
                                                    <td>
                                                        <span class="status-badge <?php echo $priority_class; ?>">
                                                            <?php echo htmlspecialchars($request['priority']); ?>
                                                        </span>
                                                    </td>
                                                    <td style="max-width: 200px;">
                                                        <?php echo substr(htmlspecialchars($request['description']), 0, 80); ?>
                                                        <?php if (strlen($request['description']) > 80): ?>...<?php endif; ?>
                                                        <?php if (!empty($request['notes'])): ?>
                                                            <br><small style="color: var(--text-light);">Notes: <?php echo substr(htmlspecialchars($request['notes']), 0, 50); ?>...</small>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td>
                                                        <?php echo date('M d, Y', strtotime($request['requested_date'])); ?>
                                                        <br>
                                                        <small style="color: var(--text-light);">
                                                            <?php echo date('g:i A', strtotime($request['requested_date'])); ?>
                                                        </small>
                                                    </td>
                                                    <td>
                                                        <span class="status-badge <?php echo $status_class; ?>">
                                                            <?php echo ucfirst(htmlspecialchars($request['status'])); ?>
                                                        </span>
                                                    </td>
                                                    <td>
                                                        <button onclick="viewRequestDetails(<?php echo $request['id']; ?>)" 
                                                                class="btn btn-sm btn-secondary">
                                                            <i class='bx bx-info-circle'></i> View
                                                        </button>
                                                        <?php if ($request['status'] === 'pending'): ?>
                                                            <button onclick="cancelRequest(<?php echo $request['id']; ?>)" 
                                                                    class="btn btn-sm btn-danger" style="margin-top: 5px;">
                                                                <i class='bx bx-x'></i> Cancel
                                                            </button>
                                                        <?php endif; ?>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                <?php else: ?>
                                    <div class="empty-state">
                                        <i class='bx bx-wrench'></i>
                                        <h3>No Maintenance Requests</h3>
                                        <p>You haven't submitted any maintenance requests yet.</p>
                                        <div style="margin-top: 20px;">
                                            <button onclick="openMaintenanceModal()" class="btn btn-primary">
                                                <i class='bx bx-plus'></i> Submit Your First Request
                                            </button>
                                        </div>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Maintenance Request Modal -->
    <div id="maintenanceModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title">
                    <i class='bx bx-wrench'></i>
                    Request Maintenance
                </h3>
                <button class="close-modal" onclick="closeMaintenanceModal()">&times;</button>
            </div>
            
            <form method="POST" action="" id="maintenanceForm">
                <input type="hidden" name="resource_id" id="resource_id">
                
                <div class="form-group">
                    <label class="form-label">Equipment *</label>
                    <input type="text" id="equipment_name" class="form-control" readonly>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Request Type *</label>
                    <select name="request_type" class="form-control" required>
                        <option value="">Select type...</option>
                        <?php foreach ($request_types as $type): ?>
                            <option value="<?php echo $type; ?>">
                                <?php echo ucfirst(str_replace('_', ' ', $type)); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Priority *</label>
                    <select name="priority" class="form-control" required>
                        <option value="">Select priority...</option>
                        <?php foreach ($priority_levels as $level): ?>
                            <option value="<?php echo $level; ?>">
                                <?php echo ucfirst($level); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Description *</label>
                    <textarea name="description" class="form-control form-textarea" 
                              placeholder="Describe the issue or maintenance needed..." required></textarea>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Additional Notes</label>
                    <textarea name="notes" class="form-control form-textarea" 
                              placeholder="Any additional information..."></textarea>
                </div>
                
                <div class="form-actions">
                    <button type="button" class="btn btn-secondary" onclick="closeMaintenanceModal()">
                        Cancel
                    </button>
                    <button type="submit" name="submit_maintenance_request" class="btn btn-primary">
                        <i class='bx bx-wrench'></i> Submit Request
                    </button>
                </div>
            </form>
        </div>
    </div>
    
    <!-- Equipment Details Modal -->
    <div id="detailsModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title">
                    <i class='bx bx-info-circle'></i>
                    Equipment Details
                </h3>
                <button class="close-modal" onclick="closeDetailsModal()">&times;</button>
            </div>
            <div id="equipmentDetails"></div>
        </div>
    </div>
    
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            initEventListeners();
            updateTime();
            setInterval(updateTime, 1000);
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
            
            // Search functionality
            const searchInput = document.getElementById('search-input');
            if (searchInput) {
                searchInput.addEventListener('keyup', function() {
                    const searchTerm = this.value.toLowerCase();
                    const currentTab = document.querySelector('.tab-content.active').id;
                    const table = document.getElementById(currentTab + '-table');
                    
                    if (table) {
                        const rows = table.getElementsByTagName('tr');
                        
                        for (let i = 1; i < rows.length; i++) {
                            const cells = rows[i].getElementsByTagName('td');
                            let match = false;
                            
                            for (let j = 0; j < cells.length; j++) {
                                if (cells[j].textContent.toLowerCase().includes(searchTerm)) {
                                    match = true;
                                    break;
                                }
                            }
                            
                            rows[i].style.display = match ? '' : 'none';
                        }
                    }
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
        
        function switchTab(tabId) {
            // Hide all tabs
            const tabs = document.querySelectorAll('.tab-content');
            tabs.forEach(tab => tab.classList.remove('active'));
            
            // Deactivate all tab buttons
            const tabButtons = document.querySelectorAll('.tab-button');
            tabButtons.forEach(button => button.classList.remove('active'));
            
            // Show selected tab
            document.getElementById(tabId).classList.add('active');
            
            // Activate clicked button
            event.target.classList.add('active');
        }
        
        function openMaintenanceModal(resourceId = '', resourceName = '') {
            const modal = document.getElementById('maintenanceModal');
            const resourceIdField = document.getElementById('resource_id');
            const equipmentNameField = document.getElementById('equipment_name');
            
            if (resourceId && resourceName) {
                resourceIdField.value = resourceId;
                equipmentNameField.value = resourceName;
            } else {
                resourceIdField.value = '';
                equipmentNameField.value = '';
            }
            
            modal.style.display = 'block';
        }
        
        function closeMaintenanceModal() {
            document.getElementById('maintenanceModal').style.display = 'none';
            document.getElementById('maintenanceForm').reset();
        }
        
        function closeDetailsModal() {
            document.getElementById('detailsModal').style.display = 'none';
        }
        
        function requestMaintenanceFor(resourceId, resourceName) {
            openMaintenanceModal(resourceId, resourceName);
        }
        
        function viewEquipmentDetails(id) {
            fetch(`get_equipment_details.php?id=${id}`)
                .then(response => response.text())
                .then(data => {
                    document.getElementById('equipmentDetails').innerHTML = data;
                    document.getElementById('detailsModal').style.display = 'block';
                })
                .catch(error => {
                    document.getElementById('equipmentDetails').innerHTML = 
                        '<div style="padding: 20px; text-align: center; color: var(--text-light);">' +
                        '<i class="bx bx-error" style="font-size: 48px; margin-bottom: 20px;"></i>' +
                        '<p>Error loading equipment details. Please try again.</p>' +
                        '</div>';
                    document.getElementById('detailsModal').style.display = 'block';
                });
        }
        
        function viewRequestDetails(id) {
            alert('View request details for ID: ' + id + '\nThis feature would show detailed request information.');
        }
        
        function cancelRequest(id) {
            if (confirm('Are you sure you want to cancel this maintenance request?')) {
                fetch(`cancel_maintenance_request.php?id=${id}`)
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            alert('Request cancelled successfully.');
                            location.reload();
                        } else {
                            alert('Error: ' + data.message);
                        }
                    })
                    .catch(error => {
                        alert('Error cancelling request. Please try again.');
                    });
            }
        }
        
        function filterEquipment(type) {
            const table = document.getElementById(type + '-equipment-table');
            const categoryFilter = document.getElementById('category-filter-' + type).value;
            const statusFilter = document.getElementById('status-filter-' + type)?.value || 'all';
            const searchTerm = document.getElementById('search-' + type)?.value.toLowerCase() || '';
            
            if (table) {
                const rows = table.getElementsByTagName('tr');
                
                for (let i = 1; i < rows.length; i++) {
                    const row = rows[i];
                    const category = row.getAttribute('data-category');
                    const status = row.getAttribute('data-status');
                    let show = true;
                    
                    // Filter by category
                    if (categoryFilter !== 'all' && category !== categoryFilter) {
                        show = false;
                    }
                    
                    // Filter by status
                    if (statusFilter !== 'all' && status !== statusFilter) {
                        show = false;
                    }
                    
                    // Filter by search
                    if (searchTerm && show) {
                        const cells = row.getElementsByTagName('td');
                        let match = false;
                        
                        for (let j = 0; j < cells.length; j++) {
                            if (cells[j].textContent.toLowerCase().includes(searchTerm)) {
                                match = true;
                                break;
                            }
                        }
                        
                        if (!match) {
                            show = false;
                        }
                    }
                    
                    row.style.display = show ? '' : 'none';
                }
            }
        }
        
        function filterUnitEquipment() {
            filterEquipment('unit');
        }
        
        function resetFilters(type) {
            document.getElementById('category-filter-' + type).value = 'all';
            if (document.getElementById('status-filter-' + type)) {
                document.getElementById('status-filter-' + type).value = 'all';
            }
            if (document.getElementById('search-' + type)) {
                document.getElementById('search-' + type).value = '';
            }
            filterEquipment(type);
        }
        
        function resetUnitFilters() {
            resetFilters('unit');
        }
        
        // Close modals when clicking outside
        window.onclick = function(event) {
            const maintenanceModal = document.getElementById('maintenanceModal');
            const detailsModal = document.getElementById('detailsModal');
            
            if (event.target === maintenanceModal) {
                closeMaintenanceModal();
            }
            
            if (event.target === detailsModal) {
                closeDetailsModal();
            }
        };
    </script>
</body>
</html>