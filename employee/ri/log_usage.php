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

if (!$user || $user['role'] !== 'EMPLOYEE') {
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

// Handle form submission
$success_message = '';
$error_message = '';
$form_data = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $resource_id = $_POST['resource_id'] ?? '';
    $quantity_used = $_POST['quantity_used'] ?? '';
    $usage_type = $_POST['usage_type'] ?? '';
    $usage_date = $_POST['usage_date'] ?? '';
    $used_by = $_POST['used_by'] ?? '';
    $incident_id = $_POST['incident_id'] ?? '';
    $unit_id = $_POST['unit_id'] ?? '';
    $notes = $_POST['notes'] ?? '';
    
    // Validate required fields
    if (empty($resource_id) || empty($quantity_used) || empty($usage_type) || empty($usage_date)) {
        $error_message = "Please fill in all required fields.";
        $form_data = $_POST;
    } else {
        try {
            // Start transaction
            $pdo->beginTransaction();
            
            // Get current resource quantity
            $resource_query = "SELECT quantity, available_quantity, resource_name FROM resources WHERE id = ?";
            $resource_stmt = $pdo->prepare($resource_query);
            $resource_stmt->execute([$resource_id]);
            $resource = $resource_stmt->fetch();
            
            if (!$resource) {
                throw new Exception("Resource not found.");
            }
            
            if ($quantity_used > $resource['available_quantity']) {
                throw new Exception("Insufficient quantity available. Available: " . $resource['available_quantity']);
            }
            
            // Update resource quantity
            $new_available = $resource['available_quantity'] - $quantity_used;
            $update_query = "UPDATE resources SET available_quantity = ? WHERE id = ?";
            $update_stmt = $pdo->prepare($update_query);
            $update_stmt->execute([$new_available, $resource_id]);
            
            // Create maintenance request for logging usage
            $maintenance_query = "INSERT INTO maintenance_requests (resource_id, requested_by, request_type, priority, description, status, requested_date) 
                                 VALUES (?, ?, ?, 'low', ?, 'completed', NOW())";
            $maintenance_stmt = $pdo->prepare($maintenance_query);
            $maintenance_desc = "Resource usage logged: " . $quantity_used . " units used for " . $usage_type;
            if ($incident_id) {
                $maintenance_desc .= " (Incident ID: " . $incident_id . ")";
            }
            $maintenance_stmt->execute([$resource_id, $user_id, 'repair', $maintenance_desc]);
            $maintenance_id = $pdo->lastInsertId();
            
            // Create service history entry
            $service_query = "INSERT INTO service_history (resource_id, maintenance_id, service_type, service_date, performed_by_id, service_notes, status_after_service) 
                             VALUES (?, ?, ?, ?, ?, ?, ?)";
            $service_stmt = $pdo->prepare($service_query);
            $service_notes = "Usage Type: " . $usage_type . "\n";
            $service_notes .= "Quantity Used: " . $quantity_used . "\n";
            if ($used_by) $service_notes .= "Used By: " . $used_by . "\n";
            if ($unit_id) $service_notes .= "Unit ID: " . $unit_id . "\n";
            if ($incident_id) $service_notes .= "Incident ID: " . $incident_id . "\n";
            if ($notes) $service_notes .= "Notes: " . $notes;
            
            $service_stmt->execute([
                $resource_id,
                $maintenance_id,
                'resource_usage',
                $usage_date,
                $user_id,
                $service_notes,
                'Serviceable'
            ]);
            
            $pdo->commit();
            
            $success_message = "Successfully logged usage of " . $quantity_used . " units of " . $resource['resource_name'];
            $form_data = [];
            
        } catch (Exception $e) {
            $pdo->rollBack();
            $error_message = "Error logging usage: " . $e->getMessage();
            $form_data = $_POST;
        }
    }
}

// Fetch resources for dropdown
$resources_query = "SELECT id, resource_name, resource_type, available_quantity, unit_of_measure 
                   FROM resources 
                   WHERE is_active = 1 
                   ORDER BY resource_name";
$resources_stmt = $pdo->query($resources_query);
$resources = $resources_stmt->fetchAll();

// Fetch units for dropdown
$units_query = "SELECT id, unit_name, unit_code FROM units WHERE status = 'Active' ORDER BY unit_name";
$units_stmt = $pdo->query($units_query);
$units = $units_stmt->fetchAll();

// Fetch recent incidents for dropdown
$incidents_query = "SELECT id, emergency_type, location, created_at 
                   FROM api_incidents 
                   WHERE is_fire_rescue_related = 1 
                   ORDER BY created_at DESC 
                   LIMIT 20";
$incidents_stmt = $pdo->query($incidents_query);
$incidents = $incidents_stmt->fetchAll();

// Fetch recent usage logs
$logs_query = "SELECT sh.id, r.resource_name, sh.service_date, sh.service_notes, 
               u.first_name, u.last_name, sh.performed_by_id,
               sh.status_after_service
               FROM service_history sh
               JOIN resources r ON sh.resource_id = r.id
               LEFT JOIN users u ON sh.performed_by_id = u.id
               WHERE sh.service_type = 'resource_usage'
               ORDER BY sh.service_date DESC
               LIMIT 10";
$logs_stmt = $pdo->query($logs_query);
$recent_logs = $logs_stmt->fetchAll();

$stmt = null;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Log Resource Usage - FRSM</title>
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
            --card-bg: #f9fafb;
            --sidebar-bg: #ffffff;
        }
        
        .dark-mode {
            --background-color: #0f172a;
            --text-color: #f1f5f9;
            --text-light: #94a3b8;
            --border-color: #1e293b;
            --card-bg: #1e293b;
            --sidebar-bg: #0f172a;
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            font-size: 14px;
            line-height: 1.5;
            color: var(--text-color);
            background-color: var(--background-color);
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

        .content-wrapper {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 30px;
            padding: 0 40px;
            margin-bottom: 40px;
        }

        @media (max-width: 1024px) {
            .content-wrapper {
                grid-template-columns: 1fr;
            }
        }

        .form-section, .logs-section {
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: 25px;
            padding: 40px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
        }

        .section-title {
            font-size: 1.8rem;
            font-weight: 700;
            margin-bottom: 30px;
            color: var(--text-color);
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .section-title i {
            color: var(--primary-color);
        }

        .form-group {
            margin-bottom: 25px;
        }

        .form-label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: var(--text-color);
        }

        .form-label .required {
            color: var(--primary-color);
        }

        .form-control {
            width: 100%;
            padding: 12px 16px;
            border: 2px solid var(--border-color);
            border-radius: 12px;
            font-size: 1rem;
            transition: all 0.3s ease;
            background: var(--background-color);
            color: var(--text-color);
        }

        .dark-mode .form-control {
            border-color: #475569;
            background: #1e293b;
        }

        .form-control:focus {
            outline: none;
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(220, 38, 38, 0.1);
        }

        .form-control:disabled {
            background: #f3f4f6;
            cursor: not-allowed;
        }

        .dark-mode .form-control:disabled {
            background: #334155;
        }

        .form-text {
            font-size: 0.875rem;
            color: var(--text-light);
            margin-top: 6px;
        }

        .quantity-info {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-top: 8px;
            padding: 10px;
            background: #f3f4f6;
            border-radius: 8px;
            font-size: 0.9rem;
        }

        .dark-mode .quantity-info {
            background: #334155;
        }

        .available-quantity {
            font-weight: 700;
            color: var(--primary-color);
        }

        .btn-submit {
            width: 100%;
            padding: 16px;
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            color: white;
            border: none;
            border-radius: 12px;
            font-size: 1.1rem;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.3s ease;
            margin-top: 10px;
        }

        .btn-submit:hover {
            background: linear-gradient(135deg, var(--primary-dark), var(--secondary-dark));
            transform: translateY(-2px);
            box-shadow: 0 10px 30px rgba(220, 38, 38, 0.4);
        }

        .btn-submit:active {
            transform: translateY(0);
        }

        .btn-submit:disabled {
            opacity: 0.6;
            cursor: not-allowed;
            transform: none !important;
        }

        .alert-message {
            padding: 16px 20px;
            border-radius: 12px;
            margin-bottom: 25px;
            display: flex;
            align-items: center;
            gap: 12px;
            animation: slideIn 0.3s ease;
        }

        @keyframes slideIn {
            from {
                transform: translateY(-10px);
                opacity: 0;
            }
            to {
                transform: translateY(0);
                opacity: 1;
            }
        }

        .alert-success {
            background: linear-gradient(135deg, #d1fae5, #a7f3d0);
            color: #065f46;
            border: 2px solid #6ee7b7;
        }

        .dark-mode .alert-success {
            background: linear-gradient(135deg, #064e3b, #065f46);
            color: #d1fae5;
            border-color: #10b981;
        }

        .alert-error {
            background: linear-gradient(135deg, #fee2e2, #fecaca);
            color: #7f1d1d;
            border: 2px solid #fca5a5;
        }

        .dark-mode .alert-error {
            background: linear-gradient(135deg, #7f1d1d, #991b1b);
            color: #fecaca;
            border-color: #ef4444;
        }

        .logs-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
        }

        .logs-table th {
            text-align: left;
            padding: 12px 16px;
            background: var(--border-color);
            color: var(--text-color);
            font-weight: 600;
            border-bottom: 2px solid var(--border-color);
        }

        .dark-mode .logs-table th {
            background: #334155;
            border-bottom-color: #475569;
        }

        .logs-table td {
            padding: 12px 16px;
            border-bottom: 1px solid var(--border-color);
            color: var(--text-light);
        }

        .dark-mode .logs-table td {
            border-bottom-color: #475569;
        }

        .logs-table tr:hover {
            background: rgba(220, 38, 38, 0.05);
        }

        .dark-mode .logs-table tr:hover {
            background: rgba(220, 38, 38, 0.1);
        }

        .resource-name {
            font-weight: 600;
            color: var(--text-color);
        }

        .usage-type {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
        }

        .usage-type-emergency {
            background: #fee2e2;
            color: #dc2626;
        }

        .dark-mode .usage-type-emergency {
            background: #7f1d1d;
            color: #fecaca;
        }

        .usage-type-training {
            background: #dbeafe;
            color: #1d4ed8;
        }

        .dark-mode .usage-type-training {
            background: #1e3a8a;
            color: #93c5fd;
        }

        .usage-type-maintenance {
            background: #fef3c7;
            color: #d97706;
        }

        .dark-mode .usage-type-maintenance {
            background: #92400e;
            color: #fcd34d;
        }

        .usage-type-routine {
            background: #dcfce7;
            color: #059669;
        }

        .dark-mode .usage-type-routine {
            background: #064e3b;
            color: #6ee7b7;
        }

        .no-data {
            text-align: center;
            padding: 40px 20px;
            color: var(--text-light);
        }

        .no-data i {
            font-size: 48px;
            margin-bottom: 16px;
            opacity: 0.5;
        }

        .stat-card {
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            color: white;
            padding: 30px;
            border-radius: 20px;
            margin-bottom: 30px;
            text-align: center;
        }

        .stat-number {
            font-size: 3rem;
            font-weight: 800;
            margin-bottom: 10px;
        }

        .stat-label {
            font-size: 1.1rem;
            opacity: 0.9;
        }

        .quick-stats {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 20px;
            margin-bottom: 30px;
        }

        .quick-stat-item {
            background: var(--card-bg);
            padding: 20px;
            border-radius: 15px;
            text-align: center;
            border: 1px solid var(--border-color);
        }

        .quick-stat-number {
            font-size: 2rem;
            font-weight: 800;
            color: var(--primary-color);
            margin-bottom: 5px;
        }

        .quick-stat-label {
            color: var(--text-light);
            font-size: 0.9rem;
            font-weight: 600;
        }

        @media (max-width: 768px) {
            .dashboard-header {
                padding: 40px 25px 30px;
                border-radius: 0 0 20px 20px;
            }
            
            .dashboard-title {
                font-size: 2.2rem;
            }
            
            .content-wrapper {
                padding: 0 25px;
            }
            
            .form-section, .logs-section {
                padding: 30px 25px;
            }
            
            .quick-stats {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <!-- Sidebar (Same as your existing code) -->
        <div class="sidebar">
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
                    <a href="../employee_dashboard.php" class="menu-item">
                        <div class="icon-box icon-bg-red">
                            <i class='bx bxs-dashboard icon-red'></i>
                        </div>
                        <span class="font-medium">Dashboard</span>
                    </a>
                    
                    <!-- Fire & Incident Reporting -->
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
                        <a href="../fir/receive_data.php" class="submenu-item">Receive Data</a>
                      
                        <a href="../fir/update_status.php" class="submenu-item">Update Status</a>
                    </div>
                    
                    <!-- Dispatch Coordination -->
                    <div class="menu-item" onclick="toggleSubmenu('dispatch')">
                        <div class="icon-box icon-bg-yellow">
                            <i class='bx bxs-truck icon-yellow'></i>
                        </div>
                        <span class="font-medium">Dispatch Coordination</span>
                        <svg class="dropdown-arrow menu-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                        </svg>
                    </div>
                    <div id="dispatch" class="submenu">
                        <a href="../dc/select_unit.php" class="submenu-item">Select Unit</a>
                        <a href="../dc/send_dispatch.php" class="submenu-item">Send Dispatch Info</a>
                        
                        <a href="../dc/track_status.php" class="submenu-item">Track Status</a>
                    </div>
                    
                    <!-- Barangay Volunteer Roster Access -->
                    <div class="menu-item" onclick="toggleSubmenu('volunteer')">
                        <div class="icon-box icon-bg-blue">
                            <i class='bx bxs-user-detail icon-blue'></i>
                        </div>
                        <span class="font-medium">Volunteer Roster Access</span>
                        <svg class="dropdown-arrow menu-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                        </svg>
                    </div>
                    <div id="volunteer" class="submenu">
                      <a href="../vra/review_data.php" class="submenu-item">Review/Aprroved Data Management</a>
                        <a href="../vra/approve_applications.php" class="submenu-item">Assign Volunteers</a>
                        <a href="../vra/view_availability.php" class="submenu-item">View Availability</a>
                        <a href="../vra/remove_volunteers.php" class="submenu-item">Remove Volunteers</a>
                        <a href="../vra/toggle_volunteer_registration.php" class="submenu-item">Open/Close Registration</a>
                    </div>
                </div>
                
               <!-- Resource Inventory Updates -->
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
                        <a href="log_usage.php" class="submenu-item active">Log Usage</a>
                        <a href="report_damages.php" class="submenu-item">Report Damages</a>
                        <a href="request_supplies.php" class="submenu-item">Request Supplies</a>
                        <a href="tag_resources.php" class="submenu-item">Tag Resources</a>
                    </div>
                    
                    <!-- Shift & Duty Scheduling -->
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
                         <a href="../sds/create_shifts.php" class="submenu-item">Create Shifts</a>
                        <a href="../sds/view_shifts.php" class="submenu-item">View Shifts</a>
                        <a href="../sds/confirm_availability.php" class="submenu-item">Confirm Availability</a>
                        <a href="../sds/request_change.php" class="submenu-item">Request Change</a>
                        <a href="../sds/mark_attendance.php" class="submenu-item">Mark Attendance</a>
                       
                    </div>
                    
                      <!-- Training & Certification Logging -->
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
                          <a href="../tc/view_available_training.php" class="submenu-item">View Available Training</a>
                        <a href="../tc/submit_training.php" class="submenu-item">Submit Training</a>
                        
                    </div>
                    
                      <!-- Inspection Logs -->
                    <div class="menu-item" onclick="toggleSubmenu('inspection')">
                        <div class="icon-box icon-bg-cyan">
                            <i class='bx bxs-check-shield icon-cyan'></i>
                        </div>
                        <span class="font-medium">Inspection Logs</span>
                        <svg class="dropdown-arrow menu-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                        </svg>
                    </div>
                    <div id="inspection" class="submenu">
                        <a href="../il/conduct_inspections.php" class="submenu-item">Conduct Inspections</a>
                        <a href="../il/submit_findings.php" class="submenu-item">Submit Findings</a>
                       
                        <a href="../il/tag_violations.php" class="submenu-item">Tag Violations</a>
                    </div>
                    <!-- Post-Incident Reporting -->
                    <div class="menu-item" onclick="toggleSubmenu('postincident')">
                        <div class="icon-box icon-bg-pink">
                            <i class='bx bxs-file-doc icon-pink'></i>
                        </div>
                        <span class="font-medium">Post-Incident Reporting</span>
                        <svg class="dropdown-arrow menu-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                        </svg>
                    </div>
                    <div id="postincident" class="submenu">
                        <a href="../pi/post_incident_reporting.php" class="submenu-item">Incident Reports</a>
                        
                    </div>
            
                <p class="menu-title" style="margin-top: 32px;">GENERAL</p>
                
                <div class="menu-items">
                    <a href="../settings.php" class="menu-item">
                        <div class="icon-box icon-bg-indigo">
                            <i class='bx bxs-cog icon-indigo'></i>
                        </div>
                        <span class="font-medium">Settings</span>
                    </a>
                    
                    <a href="../profile/profile.php" class="menu-item">
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
            <!-- Header (Same as your existing code) -->
            <div class="header">
                <div class="header-content">
                    <div class="search-container">
                        <div class="search-box">
                            <svg class="search-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path>
                            </svg>
                            <input type="text" placeholder="Search resources, logs..." class="search-input">
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
                        <div class="user-profile">
                            <img src="../../img/rei.jfif" alt="User" class="user-avatar">
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
                <!-- Hero Header -->
                <div class="dashboard-header">
                    <div>
                        <h1 class="dashboard-title">Resource Usage Logging</h1>
                        <p class="dashboard-subtitle">Track and log resource consumption for inventory management</p>
                    </div>
                </div>

                <!-- Quick Stats -->
                <div class="quick-stats" style="padding: 0 40px; margin-bottom: 30px;">
                    <div class="quick-stat-item">
                        <div class="quick-stat-number"><?php echo count($resources); ?></div>
                        <div class="quick-stat-label">Active Resources</div>
                    </div>
                    <div class="quick-stat-item">
                        <div class="quick-stat-number"><?php echo count($recent_logs); ?></div>
                        <div class="quick-stat-label">Recent Logs</div>
                    </div>
                    <div class="quick-stat-item">
                        <div class="quick-stat-number">24/7</div>
                        <div class="quick-stat-label">Tracking</div>
                    </div>
                </div>

                <!-- Success/Error Messages -->
                <?php if ($success_message): ?>
                    <div class="alert-message alert-success" style="margin: 0 40px 25px;">
                        <i class='bx bxs-check-circle'></i>
                        <?php echo $success_message; ?>
                    </div>
                <?php endif; ?>

                <?php if ($error_message): ?>
                    <div class="alert-message alert-error" style="margin: 0 40px 25px;">
                        <i class='bx bxs-error-circle'></i>
                        <?php echo $error_message; ?>
                    </div>
                <?php endif; ?>

                <!-- Main Content -->
                <div class="content-wrapper">
                    <!-- Log Usage Form -->
                    <div class="form-section">
                        <h2 class="section-title">
                            <i class='bx bxs-edit'></i>
                            Log Resource Usage
                        </h2>

                        <form method="POST" id="usage-form">
                            <div class="form-group">
                                <label class="form-label">
                                    <span class="required">*</span> Resource
                                </label>
                                <select class="form-control" name="resource_id" id="resource_id" required>
                                    <option value="">Select a resource...</option>
                                    <?php foreach ($resources as $resource): ?>
                                        <option value="<?php echo $resource['id']; ?>"
                                            <?php echo ($form_data['resource_id'] ?? '') == $resource['id'] ? 'selected' : ''; ?>
                                            data-quantity="<?php echo $resource['available_quantity']; ?>"
                                            data-unit="<?php echo $resource['unit_of_measure'] ?? 'units'; ?>">
                                            <?php echo htmlspecialchars($resource['resource_name']); ?> 
                                            (<?php echo $resource['resource_type']; ?>)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <div id="quantity-display" class="quantity-info" style="display: none;">
                                    <span>Available:</span>
                                    <span class="available-quantity" id="available-quantity">0</span>
                                    <span id="quantity-unit">units</span>
                                </div>
                            </div>

                            <div class="form-group">
                                <label class="form-label">
                                    <span class="required">*</span> Quantity Used
                                </label>
                                <input type="number" 
                                       class="form-control" 
                                       name="quantity_used" 
                                       id="quantity_used"
                                       min="1" 
                                       step="1" 
                                       required
                                       value="<?php echo htmlspecialchars($form_data['quantity_used'] ?? ''); ?>"
                                       placeholder="Enter quantity used">
                                <div class="form-text">Must be a positive whole number</div>
                            </div>

                            <div class="form-group">
                                <label class="form-label">
                                    <span class="required">*</span> Usage Type
                                </label>
                                <select class="form-control" name="usage_type" required>
                                    <option value="">Select usage type...</option>
                                    <option value="emergency_response" <?php echo ($form_data['usage_type'] ?? '') == 'emergency_response' ? 'selected' : ''; ?>>Emergency Response</option>
                                    <option value="training" <?php echo ($form_data['usage_type'] ?? '') == 'training' ? 'selected' : ''; ?>>Training</option>
                                    <option value="maintenance" <?php echo ($form_data['usage_type'] ?? '') == 'maintenance' ? 'selected' : ''; ?>>Maintenance</option>
                                    <option value="routine_check" <?php echo ($form_data['usage_type'] ?? '') == 'routine_check' ? 'selected' : ''; ?>>Routine Check</option>
                                    <option value="other" <?php echo ($form_data['usage_type'] ?? '') == 'other' ? 'selected' : ''; ?>>Other</option>
                                </select>
                            </div>

                            <div class="form-group">
                                <label class="form-label">
                                    <span class="required">*</span> Usage Date & Time
                                </label>
                                <input type="datetime-local" 
                                       class="form-control" 
                                       name="usage_date" 
                                       required
                                       value="<?php echo htmlspecialchars($form_data['usage_date'] ?? date('Y-m-d\TH:i')); ?>">
                            </div>

                            <div class="form-group">
                                <label class="form-label">Used By (Optional)</label>
                                <input type="text" 
                                       class="form-control" 
                                       name="used_by"
                                       value="<?php echo htmlspecialchars($form_data['used_by'] ?? ''); ?>"
                                       placeholder="Name of person who used the resource">
                            </div>

                            <div class="form-group">
                                <label class="form-label">Associated Incident (Optional)</label>
                                <select class="form-control" name="incident_id">
                                    <option value="">Select incident...</option>
                                    <?php foreach ($incidents as $incident): ?>
                                        <option value="<?php echo $incident['id']; ?>"
                                            <?php echo ($form_data['incident_id'] ?? '') == $incident['id'] ? 'selected' : ''; ?>>
                                            #<?php echo $incident['id']; ?> - 
                                            <?php echo htmlspecialchars($incident['emergency_type']); ?> - 
                                            <?php echo date('M j, Y', strtotime($incident['created_at'])); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <div class="form-text">Link this usage to a specific incident</div>
                            </div>

                            <div class="form-group">
                                <label class="form-label">Assigned Unit (Optional)</label>
                                <select class="form-control" name="unit_id">
                                    <option value="">Select unit...</option>
                                    <?php foreach ($units as $unit): ?>
                                        <option value="<?php echo $unit['id']; ?>"
                                            <?php echo ($form_data['unit_id'] ?? '') == $unit['id'] ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($unit['unit_name']); ?> (<?php echo $unit['unit_code']; ?>)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="form-group">
                                <label class="form-label">Notes (Optional)</label>
                                <textarea class="form-control" 
                                          name="notes" 
                                          rows="3"
                                          placeholder="Additional notes about the usage..."><?php echo htmlspecialchars($form_data['notes'] ?? ''); ?></textarea>
                            </div>

                            <button type="submit" class="btn-submit" id="submit-btn">
                                <i class='bx bx-save'></i>
                                Log Usage
                            </button>
                        </form>
                    </div>

                    <!-- Recent Usage Logs -->
                    <div class="logs-section">
                        <h2 class="section-title">
                            <i class='bx bxs-time'></i>
                            Recent Usage Logs
                        </h2>

                        <?php if (empty($recent_logs)): ?>
                            <div class="no-data">
                                <i class='bx bx-package'></i>
                                <p>No usage logs found</p>
                                <p class="form-text">Usage logs will appear here after you log resource usage</p>
                            </div>
                        <?php else: ?>
                            <div style="overflow-x: auto;">
                                <table class="logs-table">
                                    <thead>
                                        <tr>
                                            <th>Resource</th>
                                            <th>Date</th>
                                            <th>Type</th>
                                            <th>Logged By</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($recent_logs as $log): 
                                            // Parse service notes to get usage type
                                            $usage_type = 'other';
                                            if (strpos($log['service_notes'], 'Usage Type:') !== false) {
                                                $lines = explode("\n", $log['service_notes']);
                                                foreach ($lines as $line) {
                                                    if (strpos($line, 'Usage Type:') === 0) {
                                                        $usage_type = trim(str_replace('Usage Type:', '', $line));
                                                        break;
                                                    }
                                                }
                                            }
                                            
                                            // Determine CSS class for usage type
                                            $usage_class = '';
                                            switch ($usage_type) {
                                                case 'emergency_response':
                                                    $usage_class = 'usage-type-emergency';
                                                    break;
                                                case 'training':
                                                    $usage_class = 'usage-type-training';
                                                    break;
                                                case 'maintenance':
                                                    $usage_class = 'usage-type-maintenance';
                                                    break;
                                                case 'routine_check':
                                                    $usage_class = 'usage-type-routine';
                                                    break;
                                                default:
                                                    $usage_class = 'usage-type-routine';
                                            }
                                        ?>
                                        <tr>
                                            <td>
                                                <div class="resource-name"><?php echo htmlspecialchars($log['resource_name']); ?></div>
                                                <div class="form-text" style="font-size: 0.75rem;">
                                                    <?php 
                                                    // Extract quantity from notes
                                                    $quantity = 'N/A';
                                                    if (strpos($log['service_notes'], 'Quantity Used:') !== false) {
                                                        $lines = explode("\n", $log['service_notes']);
                                                        foreach ($lines as $line) {
                                                            if (strpos($line, 'Quantity Used:') === 0) {
                                                                $quantity = trim(str_replace('Quantity Used:', '', $line));
                                                                break;
                                                            }
                                                        }
                                                    }
                                                    echo $quantity . ' units';
                                                    ?>
                                                </div>
                                            </td>
                                            <td>
                                                <?php echo date('M j, Y', strtotime($log['service_date'])); ?><br>
                                                <div class="form-text" style="font-size: 0.75rem;">
                                                    <?php echo date('g:i A', strtotime($log['service_date'])); ?>
                                                </div>
                                            </td>
                                            <td>
                                                <span class="usage-type <?php echo $usage_class; ?>">
                                                    <?php echo ucfirst(str_replace('_', ' ', $usage_type)); ?>
                                                </span>
                                            </td>
                                            <td>
                                                <?php if ($log['first_name']): ?>
                                                    <?php echo htmlspecialchars($log['first_name'] . ' ' . $log['last_name']); ?>
                                                <?php else: ?>
                                                    System
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                            
                            <div style="text-align: center; margin-top: 20px;">
                                <a href="#" class="btn-submit" style="width: auto; padding: 10px 20px;">
                                    <i class='bx bx-history'></i>
                                    View All Logs
                                </a>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
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
            
            // Time display
            function updateTime() {
                const now = new Date();
                const hours = now.getHours().toString().padStart(2, '0');
                const minutes = now.getMinutes().toString().padStart(2, '0');
                const seconds = now.getSeconds().toString().padStart(2, '0');
                
                const timeString = `${hours}:${minutes}:${seconds}`;
                document.getElementById('current-time').textContent = timeString;
            }
            
            updateTime();
            setInterval(updateTime, 1000);
            
            // Resource quantity display
            const resourceSelect = document.getElementById('resource_id');
            const quantityDisplay = document.getElementById('quantity-display');
            const availableQuantity = document.getElementById('available-quantity');
            const quantityUnit = document.getElementById('quantity-unit');
            const quantityInput = document.getElementById('quantity_used');
            const submitBtn = document.getElementById('submit-btn');
            
            resourceSelect.addEventListener('change', function() {
                const selectedOption = this.options[this.selectedIndex];
                const available = selectedOption.getAttribute('data-quantity');
                const unit = selectedOption.getAttribute('data-unit') || 'units';
                
                if (available !== null) {
                    availableQuantity.textContent = available;
                    quantityUnit.textContent = unit;
                    quantityDisplay.style.display = 'flex';
                    
                    // Set max attribute on quantity input
                    quantityInput.max = available;
                    
                    // Update placeholder
                    quantityInput.placeholder = `Max: ${available} ${unit}`;
                } else {
                    quantityDisplay.style.display = 'none';
                    quantityInput.max = '';
                    quantityInput.placeholder = 'Enter quantity used';
                }
            });
            
            // Validate quantity before submission
            document.getElementById('usage-form').addEventListener('submit', function(e) {
                const resourceId = resourceSelect.value;
                const quantity = parseInt(quantityInput.value);
                const maxQuantity = parseInt(quantityInput.max);
                
                if (resourceId && quantity > maxQuantity) {
                    e.preventDefault();
                    alert(`Error: Cannot use more than ${maxQuantity} units. Available: ${maxQuantity}`);
                    quantityInput.focus();
                    return false;
                }
                
                // Show loading state
                submitBtn.disabled = true;
                submitBtn.innerHTML = '<i class="bx bx-loader-circle bx-spin"></i> Logging Usage...';
            });
            
            // Auto-hide success messages after 5 seconds
            const successMessage = document.querySelector('.alert-success');
            if (successMessage) {
                setTimeout(() => {
                    successMessage.style.display = 'none';
                }, 5000);
            }
            
            // Initialize resource quantity display if there's a pre-selected value
            if (resourceSelect.value) {
                resourceSelect.dispatchEvent(new Event('change'));
            }
            
            // Toggle submenu function
            function toggleSubmenu(id) {
                const submenu = document.getElementById(id);
                const arrow = document.querySelector(`#${id}`).previousElementSibling.querySelector('.dropdown-arrow');
                
                submenu.classList.toggle('active');
                arrow.classList.toggle('rotated');
            }
            
            // Attach toggle function to window for sidebar
            window.toggleSubmenu = toggleSubmenu;
        });
    </script>
</body>
</html>