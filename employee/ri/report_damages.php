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
    $damage_type = $_POST['damage_type'] ?? '';
    $damage_severity = $_POST['damage_severity'] ?? '';
    $damage_description = $_POST['damage_description'] ?? '';
    $affected_quantity = $_POST['affected_quantity'] ?? 1;
    $incident_id = $_POST['incident_id'] ?? '';
    $unit_id = $_POST['unit_id'] ?? '';
    $estimated_repair_cost = $_POST['estimated_repair_cost'] ?? '';
    $estimated_repair_time = $_POST['estimated_repair_time'] ?? '';
    $urgency_level = $_POST['urgency_level'] ?? '';
    $notes = $_POST['notes'] ?? '';
    
    // Validate required fields
    if (empty($resource_id) || empty($damage_type) || empty($damage_severity) || empty($damage_description)) {
        $error_message = "Please fill in all required fields.";
        $form_data = $_POST;
    } else {
        try {
            // Start transaction
            $pdo->beginTransaction();
            
            // Get current resource information
            $resource_query = "SELECT id, resource_name, quantity, available_quantity, condition_status 
                             FROM resources WHERE id = ?";
            $resource_stmt = $pdo->prepare($resource_query);
            $resource_stmt->execute([$resource_id]);
            $resource = $resource_stmt->fetch();
            
            if (!$resource) {
                throw new Exception("Resource not found.");
            }
            
            // Validate affected quantity
            if ($affected_quantity > $resource['quantity']) {
                throw new Exception("Affected quantity cannot exceed total quantity of " . $resource['quantity']);
            }
            
            if ($affected_quantity > $resource['available_quantity']) {
                throw new Exception("Affected quantity cannot exceed available quantity of " . $resource['available_quantity']);
            }
            
            // Update resource condition status
            $new_status = 'Under Maintenance';
            if ($damage_severity === 'severe' || $damage_severity === 'total_loss') {
                $new_status = 'Condemned';
            }
            
            $update_resource_query = "UPDATE resources SET 
                                     condition_status = ?,
                                     available_quantity = available_quantity - ?,
                                     maintenance_notes = CONCAT(COALESCE(maintenance_notes, ''), '\n', ?)
                                     WHERE id = ?";
            
            $maintenance_note = date('Y-m-d H:i:s') . " - DAMAGE REPORTED:\n" .
                              "Type: " . $damage_type . "\n" .
                              "Severity: " . $damage_severity . "\n" .
                              "Description: " . $damage_description . "\n" .
                              "Affected Quantity: " . $affected_quantity . "\n" .
                              "Reported By: Employee ID " . $user_id . " (" . $full_name . ")\n";
            
            if ($incident_id) {
                $maintenance_note .= "Incident ID: " . $incident_id . "\n";
            }
            if ($urgency_level) {
                $maintenance_note .= "Urgency: " . $urgency_level . "\n";
            }
            if ($notes) {
                $maintenance_note .= "Additional Notes: " . $notes . "\n";
            }
            
            $update_stmt = $pdo->prepare($update_resource_query);
            $update_stmt->execute([
                $new_status,
                $affected_quantity,
                $maintenance_note,
                $resource_id
            ]);
            
            // Create maintenance request
            $maintenance_query = "INSERT INTO maintenance_requests 
                                 (resource_id, requested_by, request_type, priority, description, 
                                  requested_date, status, estimated_cost) 
                                 VALUES (?, ?, 'repair', ?, ?, NOW(), 'pending', ?)";
            
            $maintenance_desc = "Damage Report - " . $damage_type . "\n" .
                               "Severity: " . $damage_severity . "\n" .
                               "Description: " . $damage_description . "\n" .
                               "Affected Quantity: " . $affected_quantity;
            
            if ($unit_id) {
                $maintenance_desc .= "\nUnit ID: " . $unit_id;
            }
            if ($incident_id) {
                $maintenance_desc .= "\nIncident ID: " . $incident_id;
            }
            if ($urgency_level) {
                $maintenance_desc .= "\nUrgency Level: " . $urgency_level;
            }
            if ($notes) {
                $maintenance_desc .= "\nNotes: " . $notes;
            }
            
            $maintenance_stmt = $pdo->prepare($maintenance_query);
            $maintenance_stmt->execute([
                $resource_id,
                $user_id,
                $urgency_level ?: 'medium',
                $maintenance_desc,
                $estimated_repair_cost ?: null
            ]);
            $maintenance_id = $pdo->lastInsertId();
            
            // Create service history entry
            $service_query = "INSERT INTO service_history 
                             (resource_id, maintenance_id, service_type, service_date, 
                              performed_by_id, service_notes, status_after_service, 
                              parts_replaced, cost, labor_hours) 
                             VALUES (?, ?, 'damage_report', NOW(), ?, ?, ?, ?, ?, ?)";
            
            $service_notes = "DAMAGE REPORTED\n" .
                            "===============\n" .
                            "Damage Type: " . $damage_type . "\n" .
                            "Severity: " . $damage_severity . "\n" .
                            "Description: " . $damage_description . "\n" .
                            "Affected Quantity: " . $affected_quantity . "\n" .
                            "Urgency Level: " . ($urgency_level ?: 'Not specified') . "\n";
            
            if ($unit_id) {
                $service_notes .= "Unit ID: " . $unit_id . "\n";
            }
            if ($incident_id) {
                $service_notes .= "Incident ID: " . $incident_id . "\n";
            }
            if ($estimated_repair_cost) {
                $service_notes .= "Estimated Repair Cost: ₱" . number_format($estimated_repair_cost, 2) . "\n";
            }
            if ($estimated_repair_time) {
                $service_notes .= "Estimated Repair Time: " . $estimated_repair_time . " days\n";
            }
            if ($notes) {
                $service_notes .= "Additional Notes: " . $notes . "\n";
            }
            
            $service_stmt = $pdo->prepare($service_query);
            $service_stmt->execute([
                $resource_id,
                $maintenance_id,
                $user_id,
                $service_notes,
                $new_status,
                'Damage reported - requires repair/replacement',
                $estimated_repair_cost ?: null,
                $estimated_repair_time ?: null
            ]);
            
            $pdo->commit();
            
            $success_message = "Damage report submitted successfully for " . $resource['resource_name'] . 
                             ". Maintenance request #" . $maintenance_id . " has been created.";
            $form_data = [];
            
        } catch (Exception $e) {
            $pdo->rollBack();
            $error_message = "Error submitting damage report: " . $e->getMessage();
            $form_data = $_POST;
        }
    }
}

// Fetch resources for dropdown (only serviceable or under maintenance)
$resources_query = "SELECT id, resource_name, resource_type, quantity, available_quantity, 
                   condition_status, category, unit_of_measure 
                   FROM resources 
                   WHERE is_active = 1 
                   AND condition_status IN ('Serviceable', 'Under Maintenance')
                   ORDER BY resource_name";
$resources_stmt = $pdo->query($resources_query);
$resources = $resources_stmt->fetchAll();

// Fetch units for dropdown
$units_query = "SELECT id, unit_name, unit_code FROM units WHERE status = 'Active' ORDER BY unit_name";
$units_stmt = $pdo->query($units_query);
$units = $units_stmt->fetchAll();

// Fetch recent incidents for dropdown
$incidents_query = "SELECT id, emergency_type, location, created_at, severity 
                   FROM api_incidents 
                   WHERE (is_fire_rescue_related = 1 OR emergency_type IN ('fire', 'rescue'))
                   AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
                   ORDER BY created_at DESC 
                   LIMIT 20";
$incidents_stmt = $pdo->query($incidents_query);
$incidents = $incidents_stmt->fetchAll();

// Fetch recent damage reports
$damage_reports_query = "SELECT sh.id, r.resource_name, r.resource_type, r.category,
                         sh.service_date, sh.service_notes, 
                         u.first_name, u.last_name,
                         sh.status_after_service, sh.cost,
                         mr.status as maintenance_status
                         FROM service_history sh
                         JOIN resources r ON sh.resource_id = r.id
                         JOIN maintenance_requests mr ON sh.maintenance_id = mr.id
                         LEFT JOIN users u ON sh.performed_by_id = u.id
                         WHERE sh.service_type = 'damage_report'
                         ORDER BY sh.service_date DESC
                         LIMIT 15";
$damage_reports_stmt = $pdo->query($damage_reports_query);
$recent_damage_reports = $damage_reports_stmt->fetchAll();

// Get statistics
$stats_query = "SELECT 
                COUNT(*) as total_damage_reports,
                COUNT(CASE WHEN r.condition_status = 'Under Maintenance' THEN 1 END) as under_maintenance,
                COUNT(CASE WHEN r.condition_status = 'Condemned' THEN 1 END) as condemned,
                SUM(CASE WHEN sh.cost IS NOT NULL THEN sh.cost ELSE 0 END) as total_repair_cost
                FROM service_history sh
                JOIN resources r ON sh.resource_id = r.id
                WHERE sh.service_type = 'damage_report'
                AND sh.service_date >= DATE_SUB(NOW(), INTERVAL 30 DAY)";
$stats_stmt = $pdo->query($stats_query);
$stats = $stats_stmt->fetch();

$stmt = null;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Report Damages - FRSM</title>
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

        .form-section, .reports-section {
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

        textarea.form-control {
            min-height: 100px;
            resize: vertical;
        }

        .form-text {
            font-size: 0.875rem;
            color: var(--text-light);
            margin-top: 6px;
        }

        .resource-info {
            display: flex;
            flex-wrap: wrap;
            gap: 15px;
            margin-top: 8px;
            padding: 15px;
            background: #f3f4f6;
            border-radius: 8px;
            font-size: 0.9rem;
        }

        .dark-mode .resource-info {
            background: #334155;
        }

        .info-item {
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .info-label {
            font-weight: 600;
            color: var(--text-light);
        }

        .info-value {
            font-weight: 700;
            color: var(--text-color);
        }

        .condition-serviceable {
            color: #059669;
        }

        .condition-maintenance {
            color: #d97706;
        }

        .condition-condemned {
            color: #dc2626;
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

        .reports-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
        }

        .reports-table th {
            text-align: left;
            padding: 12px 16px;
            background: var(--border-color);
            color: var(--text-color);
            font-weight: 600;
            border-bottom: 2px solid var(--border-color);
        }

        .dark-mode .reports-table th {
            background: #334155;
            border-bottom-color: #475569;
        }

        .reports-table td {
            padding: 12px 16px;
            border-bottom: 1px solid var(--border-color);
            color: var(--text-light);
        }

        .dark-mode .reports-table td {
            border-bottom-color: #475569;
        }

        .reports-table tr:hover {
            background: rgba(220, 38, 38, 0.05);
        }

        .dark-mode .reports-table tr:hover {
            background: rgba(220, 38, 38, 0.1);
        }

        .resource-name-cell {
            font-weight: 600;
            color: var(--text-color);
        }

        .damage-severity {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
        }

        .severity-minor {
            background: #dbeafe;
            color: #1d4ed8;
        }

        .severity-moderate {
            background: #fef3c7;
            color: #d97706;
        }

        .severity-severe {
            background: #fecaca;
            color: #dc2626;
        }

        .severity-total_loss {
            background: #1f2937;
            color: #ffffff;
        }

        .dark-mode .severity-minor {
            background: #1e3a8a;
            color: #93c5fd;
        }

        .dark-mode .severity-moderate {
            background: #92400e;
            color: #fcd34d;
        }

        .dark-mode .severity-severe {
            background: #7f1d1d;
            color: #fecaca;
        }

        .maintenance-status {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
        }

        .status-pending {
            background: #fef3c7;
            color: #d97706;
        }

        .status-approved {
            background: #d1fae5;
            color: #065f46;
        }

        .status-in_progress {
            background: #dbeafe;
            color: #1d4ed8;
        }

        .status-completed {
            background: #dcfce7;
            color: #059669;
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

        .quick-stats {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 20px;
            margin-bottom: 30px;
            padding: 0 40px;
        }

        .quick-stat-item {
            background: var(--card-bg);
            padding: 25px;
            border-radius: 15px;
            text-align: center;
            border: 1px solid var(--border-color);
            transition: transform 0.3s ease;
        }

        .quick-stat-item:hover {
            transform: translateY(-5px);
        }

        .quick-stat-number {
            font-size: 2.5rem;
            font-weight: 800;
            margin-bottom: 10px;
        }

        .stat-total {
            color: #4f46e5;
        }

        .stat-maintenance {
            color: #d97706;
        }

        .stat-condemned {
            color: #dc2626;
        }

        .stat-cost {
            color: #059669;
        }

        .quick-stat-label {
            color: var(--text-light);
            font-size: 0.9rem;
            font-weight: 600;
        }

        .quick-stat-desc {
            font-size: 0.8rem;
            color: var(--text-light);
            margin-top: 5px;
        }

        .damage-type-badge {
            display: inline-block;
            padding: 4px 10px;
            border-radius: 6px;
            font-size: 0.75rem;
            font-weight: 600;
            background: #e5e7eb;
            color: #374151;
            margin-top: 5px;
        }

        .dark-mode .damage-type-badge {
            background: #475569;
            color: #e2e8f0;
        }

        .cost-cell {
            font-weight: 700;
        }

        @media (max-width: 1200px) {
            .quick-stats {
                grid-template-columns: repeat(2, 1fr);
            }
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
            
            .form-section, .reports-section {
                padding: 30px 25px;
            }
            
            .quick-stats {
                grid-template-columns: 1fr;
                padding: 0 25px;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <!-- Sidebar -->
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
                         <a href="../fir/recieve_data.php" class="submenu-item">Receive Data</a>
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
                        <a href="review_data.php" class="submenu-item">Review/Aprroved Data Management</a>
                        <a href="approve_applications.php" class="submenu-item">Assign Volunteers</a>
                        <a href="view_availability.php" class="submenu-item">View Availability</a>
                        <a href="remove_volunteers.php" class="submenu-item">Remove Volunteers</a>
                        <a href="toggle_volunteer_registration.php" class="submenu-item">Open/Close Registration</a>
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
                        <a href="log_usage.php" class="submenu-item">Log Usage</a>
                        <a href="../inventory/report_damages.php" class="submenu-item active">Report Damages</a>
                        <a href="../inventory/request_supplies.php" class="submenu-item">Request Supplies</a>
                        <a href="../inventory/tag_resources.php" class="submenu-item">Tag Resources</a>
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
                        <a href="../sds/view_shifts.php" class="submenu-item">View Shifts</a>
                        <a href="../sds/confirm_availability.php" class="submenu-item">Confirm Availability</a>
                        <a href="../sds/request_change.php" class="submenu-item">Request Change</a>
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
                        <a href="../training/submit_training.php" class="submenu-item">Submit Training</a>
                        <a href="../training/upload_certificates.php" class="submenu-item">Upload Certificates</a>
                        <a href="../training/request_training.php" class="submenu-item">Request Training</a>
                        <a href="../training/view_events.php" class="submenu-item">View Events</a>
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
                        <a href="../inspection/conduct_inspections.php" class="submenu-item">Conduct Inspections</a>
                        <a href="../inspection/submit_findings.php" class="submenu-item">Submit Findings</a>
                        <a href="../inspection/upload_photos.php" class="submenu-item">Upload Photos</a>
                        <a href="../inspection/tag_violations.php" class="submenu-item">Tag Violations</a>
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
                        <a href="../postincident/upload_reports.php" class="submenu-item">Upload Reports</a>
                        <a href="../postincident/add_notes.php" class="submenu-item">Add Notes</a>
                        <a href="../postincident/attach_equipment.php" class="submenu-item">Attach Equipment</a>
                        <a href="../postincident/mark_completed.php" class="submenu-item">Mark Completed</a>
                    </div>
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
            <!-- Header -->
            <div class="header">
                <div class="header-content">
                    <div class="search-container">
                        <div class="search-box">
                            <svg class="search-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path>
                            </svg>
                            <input type="text" placeholder="Search damage reports, resources..." class="search-input">
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
                        <h1 class="dashboard-title">Report Damages</h1>
                        <p class="dashboard-subtitle">Report and track damaged equipment for maintenance or replacement</p>
                    </div>
                </div>

                <!-- Damage Statistics -->
                <div class="quick-stats">
                    <div class="quick-stat-item">
                        <div class="quick-stat-number stat-total"><?php echo $stats['total_damage_reports'] ?? 0; ?></div>
                        <div class="quick-stat-label">Damage Reports (30 days)</div>
                        <div class="quick-stat-desc">Total reported damages</div>
                    </div>
                    <div class="quick-stat-item">
                        <div class="quick-stat-number stat-maintenance"><?php echo $stats['under_maintenance'] ?? 0; ?></div>
                        <div class="quick-stat-label">Under Maintenance</div>
                        <div class="quick-stat-desc">Items being repaired</div>
                    </div>
                    <div class="quick-stat-item">
                        <div class="quick-stat-number stat-condemned"><?php echo $stats['condemned'] ?? 0; ?></div>
                        <div class="quick-stat-label">Condemned</div>
                        <div class="quick-stat-desc">Beyond repair</div>
                    </div>
                    <div class="quick-stat-item">
                        <div class="quick-stat-number stat-cost">₱<?php echo number_format($stats['total_repair_cost'] ?? 0, 0); ?></div>
                        <div class="quick-stat-label">Repair Cost (30 days)</div>
                        <div class="quick-stat-desc">Estimated total</div>
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
                    <!-- Report Damage Form -->
                    <div class="form-section">
                        <h2 class="section-title">
                            <i class='bx bxs-report'></i>
                            Report New Damage
                        </h2>

                        <form method="POST" id="damage-form">
                            <div class="form-group">
                                <label class="form-label">
                                    <span class="required">*</span> Resource
                                </label>
                                <select class="form-control" name="resource_id" id="resource_id" required>
                                    <option value="">Select a resource...</option>
                                    <?php foreach ($resources as $resource): 
                                        $condition_class = '';
                                        switch ($resource['condition_status']) {
                                            case 'Serviceable':
                                                $condition_class = 'condition-serviceable';
                                                break;
                                            case 'Under Maintenance':
                                                $condition_class = 'condition-maintenance';
                                                break;
                                            case 'Condemned':
                                                $condition_class = 'condition-condemned';
                                                break;
                                        }
                                    ?>
                                        <option value="<?php echo $resource['id']; ?>"
                                            <?php echo ($form_data['resource_id'] ?? '') == $resource['id'] ? 'selected' : ''; ?>
                                            data-quantity="<?php echo $resource['quantity']; ?>"
                                            data-available="<?php echo $resource['available_quantity']; ?>"
                                            data-condition="<?php echo $resource['condition_status']; ?>"
                                            data-type="<?php echo $resource['resource_type']; ?>"
                                            data-category="<?php echo $resource['category']; ?>"
                                            data-unit="<?php echo $resource['unit_of_measure'] ?? 'units'; ?>">
                                            <?php echo htmlspecialchars($resource['resource_name']); ?> 
                                            (<?php echo $resource['resource_type']; ?>)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <div id="resource-info-display" class="resource-info" style="display: none;">
                                    <div class="info-item">
                                        <span class="info-label">Total:</span>
                                        <span class="info-value" id="total-quantity">0</span>
                                        <span id="quantity-unit">units</span>
                                    </div>
                                    <div class="info-item">
                                        <span class="info-label">Available:</span>
                                        <span class="info-value" id="available-quantity">0</span>
                                        <span>units</span>
                                    </div>
                                    <div class="info-item">
                                        <span class="info-label">Condition:</span>
                                        <span class="info-value" id="condition-status"></span>
                                    </div>
                                    <div class="info-item">
                                        <span class="info-label">Category:</span>
                                        <span class="info-value" id="resource-category"></span>
                                    </div>
                                </div>
                            </div>

                            <div class="form-group">
                                <label class="form-label">
                                    <span class="required">*</span> Damage Type
                                </label>
                                <select class="form-control" name="damage_type" required>
                                    <option value="">Select damage type...</option>
                                    <option value="mechanical_failure" <?php echo ($form_data['damage_type'] ?? '') == 'mechanical_failure' ? 'selected' : ''; ?>>Mechanical Failure</option>
                                    <option value="structural_damage" <?php echo ($form_data['damage_type'] ?? '') == 'structural_damage' ? 'selected' : ''; ?>>Structural Damage</option>
                                    <option value="electrical_fault" <?php echo ($form_data['damage_type'] ?? '') == 'electrical_fault' ? 'selected' : ''; ?>>Electrical Fault</option>
                                    <option value="wear_and_tear" <?php echo ($form_data['damage_type'] ?? '') == 'wear_and_tear' ? 'selected' : ''; ?>>Wear and Tear</option>
                                    <option value="water_damage" <?php echo ($form_data['damage_type'] ?? '') == 'water_damage' ? 'selected' : ''; ?>>Water Damage</option>
                                    <option value="fire_damage" <?php echo ($form_data['damage_type'] ?? '') == 'fire_damage' ? 'selected' : ''; ?>>Fire Damage</option>
                                    <option value="impact_damage" <?php echo ($form_data['damage_type'] ?? '') == 'impact_damage' ? 'selected' : ''; ?>>Impact Damage</option>
                                    <option value="corrosion" <?php echo ($form_data['damage_type'] ?? '') == 'corrosion' ? 'selected' : ''; ?>>Corrosion</option>
                                    <option value="other" <?php echo ($form_data['damage_type'] ?? '') == 'other' ? 'selected' : ''; ?>>Other</option>
                                </select>
                            </div>

                            <div class="form-group">
                                <label class="form-label">
                                    <span class="required">*</span> Damage Severity
                                </label>
                                <select class="form-control" name="damage_severity" required>
                                    <option value="">Select severity...</option>
                                    <option value="minor" <?php echo ($form_data['damage_severity'] ?? '') == 'minor' ? 'selected' : ''; ?>>Minor - Light repair needed</option>
                                    <option value="moderate" <?php echo ($form_data['damage_severity'] ?? '') == 'moderate' ? 'selected' : ''; ?>>Moderate - Significant repair needed</option>
                                    <option value="severe" <?php echo ($form_data['damage_severity'] ?? '') == 'severe' ? 'selected' : ''; ?>>Severe - Major repair/replacement</option>
                                    <option value="total_loss" <?php echo ($form_data['damage_severity'] ?? '') == 'total_loss' ? 'selected' : ''; ?>>Total Loss - Cannot be repaired</option>
                                </select>
                            </div>

                            <div class="form-group">
                                <label class="form-label">
                                    <span class="required">*</span> Damage Description
                                </label>
                                <textarea class="form-control" 
                                          name="damage_description" 
                                          rows="4"
                                          required
                                          placeholder="Describe the damage in detail..."><?php echo htmlspecialchars($form_data['damage_description'] ?? ''); ?></textarea>
                                <div class="form-text">Be specific about what is damaged and how it affects functionality</div>
                            </div>

                            <div class="form-group">
                                <label class="form-label">
                                    <span class="required">*</span> Affected Quantity
                                </label>
                                <input type="number" 
                                       class="form-control" 
                                       name="affected_quantity" 
                                       id="affected_quantity"
                                       min="1" 
                                       step="1" 
                                       required
                                       value="<?php echo htmlspecialchars($form_data['affected_quantity'] ?? '1'); ?>"
                                       placeholder="Number of units affected">
                                <div class="form-text" id="quantity-hint">Must be between 1 and available quantity</div>
                            </div>

                            <div class="form-group">
                                <label class="form-label">Urgency Level</label>
                                <select class="form-control" name="urgency_level">
                                    <option value="">Select urgency...</option>
                                    <option value="low" <?php echo ($form_data['urgency_level'] ?? '') == 'low' ? 'selected' : ''; ?>>Low - Can wait 1-2 weeks</option>
                                    <option value="medium" <?php echo ($form_data['urgency_level'] ?? '') == 'medium' ? 'selected' : ''; ?>>Medium - Needs attention within days</option>
                                    <option value="high" <?php echo ($form_data['urgency_level'] ?? '') == 'high' ? 'selected' : ''; ?>>High - Needs immediate attention</option>
                                    <option value="critical" <?php echo ($form_data['urgency_level'] ?? '') == 'critical' ? 'selected' : ''; ?>>Critical - Affects emergency response</option>
                                </select>
                            </div>

                            <div class="form-group">
                                <label class="form-label">Associated Incident (Optional)</label>
                                <select class="form-control" name="incident_id">
                                    <option value="">Select incident...</option>
                                    <?php foreach ($incidents as $incident): 
                                        $severity_class = '';
                                        switch ($incident['severity']) {
                                            case 'low':
                                                $severity_class = 'severity-minor';
                                                break;
                                            case 'medium':
                                                $severity_class = 'severity-moderate';
                                                break;
                                            case 'high':
                                            case 'critical':
                                                $severity_class = 'severity-severe';
                                                break;
                                        }
                                    ?>
                                        <option value="<?php echo $incident['id']; ?>"
                                            <?php echo ($form_data['incident_id'] ?? '') == $incident['id'] ? 'selected' : ''; ?>
                                            data-severity="<?php echo $incident['severity']; ?>">
                                            #<?php echo $incident['id']; ?> - 
                                            <?php echo htmlspecialchars($incident['emergency_type']); ?> - 
                                            <?php echo date('M j, Y', strtotime($incident['created_at'])); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <div class="form-text">Link this damage to a specific incident</div>
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
                                <label class="form-label">Estimated Repair Cost (Optional)</label>
                                <div style="position: relative;">
                                    <span style="position: absolute; left: 12px; top: 50%; transform: translateY(-50%); color: var(--text-light);">₱</span>
                                    <input type="number" 
                                           class="form-control" 
                                           name="estimated_repair_cost"
                                           style="padding-left: 30px;"
                                           min="0" 
                                           step="0.01"
                                           value="<?php echo htmlspecialchars($form_data['estimated_repair_cost'] ?? ''); ?>"
                                           placeholder="0.00">
                                </div>
                                <div class="form-text">Estimated cost to repair in PHP</div>
                            </div>

                            <div class="form-group">
                                <label class="form-label">Estimated Repair Time (Optional)</label>
                                <input type="number" 
                                       class="form-control" 
                                       name="estimated_repair_time"
                                       min="1" 
                                       step="1"
                                       value="<?php echo htmlspecialchars($form_data['estimated_repair_time'] ?? ''); ?>"
                                       placeholder="Number of days">
                                <div class="form-text">Estimated days needed for repair</div>
                            </div>

                            <div class="form-group">
                                <label class="form-label">Additional Notes (Optional)</label>
                                <textarea class="form-control" 
                                          name="notes" 
                                          rows="3"
                                          placeholder="Any additional information..."><?php echo htmlspecialchars($form_data['notes'] ?? ''); ?></textarea>
                            </div>

                            <button type="submit" class="btn-submit" id="submit-btn">
                                <i class='bx bx-save'></i>
                                Submit Damage Report
                            </button>
                        </form>
                    </div>

                    <!-- Recent Damage Reports -->
                    <div class="reports-section">
                        <h2 class="section-title">
                            <i class='bx bxs-history'></i>
                            Recent Damage Reports
                        </h2>

                        <?php if (empty($recent_damage_reports)): ?>
                            <div class="no-data">
                                <i class='bx bx-check-shield'></i>
                                <p>No damage reports found</p>
                                <p class="form-text">Damage reports will appear here after submission</p>
                            </div>
                        <?php else: ?>
                            <div style="overflow-x: auto;">
                                <table class="reports-table">
                                    <thead>
                                        <tr>
                                            <th>Resource</th>
                                            <th>Date</th>
                                            <th>Severity</th>
                                            <th>Status</th>
                                            <th>Cost</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($recent_damage_reports as $report): 
                                            // Parse service notes to get damage details
                                            $damage_type = 'Unknown';
                                            $damage_severity = 'minor';
                                            
                                            if (strpos($report['service_notes'], 'Damage Type:') !== false) {
                                                $lines = explode("\n", $report['service_notes']);
                                                foreach ($lines as $line) {
                                                    if (strpos($line, 'Damage Type:') === 0) {
                                                        $damage_type = trim(str_replace('Damage Type:', '', $line));
                                                    }
                                                    if (strpos($line, 'Severity:') === 0) {
                                                        $damage_severity = trim(str_replace('Severity:', '', $line));
                                                    }
                                                }
                                            }
                                            
                                            // Determine CSS class for severity
                                            $severity_class = '';
                                            switch ($damage_severity) {
                                                case 'minor':
                                                    $severity_class = 'severity-minor';
                                                    break;
                                                case 'moderate':
                                                    $severity_class = 'severity-moderate';
                                                    break;
                                                case 'severe':
                                                    $severity_class = 'severity-severe';
                                                    break;
                                                case 'total_loss':
                                                    $severity_class = 'severity-total_loss';
                                                    break;
                                                default:
                                                    $severity_class = 'severity-minor';
                                            }
                                            
                                            // Determine CSS class for maintenance status
                                            $status_class = '';
                                            switch ($report['maintenance_status']) {
                                                case 'pending':
                                                    $status_class = 'status-pending';
                                                    break;
                                                case 'approved':
                                                    $status_class = 'status-approved';
                                                    break;
                                                case 'in_progress':
                                                    $status_class = 'status-in_progress';
                                                    break;
                                                case 'completed':
                                                    $status_class = 'status-completed';
                                                    break;
                                                default:
                                                    $status_class = 'status-pending';
                                            }
                                        ?>
                                        <tr>
                                            <td>
                                                <div class="resource-name-cell"><?php echo htmlspecialchars($report['resource_name']); ?></div>
                                                <div class="damage-type-badge"><?php echo str_replace('_', ' ', $damage_type); ?></div>
                                                <div class="form-text" style="font-size: 0.75rem;">
                                                    <?php echo $report['category']; ?> • <?php echo $report['resource_type']; ?>
                                                </div>
                                            </td>
                                            <td>
                                                <?php echo date('M j, Y', strtotime($report['service_date'])); ?><br>
                                                <div class="form-text" style="font-size: 0.75rem;">
                                                    <?php echo date('g:i A', strtotime($report['service_date'])); ?>
                                                </div>
                                            </td>
                                            <td>
                                                <span class="damage-severity <?php echo $severity_class; ?>">
                                                    <?php echo ucfirst($damage_severity); ?>
                                                </span>
                                            </td>
                                            <td>
                                                <span class="maintenance-status <?php echo $status_class; ?>">
                                                    <?php echo ucfirst(str_replace('_', ' ', $report['maintenance_status'])); ?>
                                                </span>
                                            </td>
                                            <td class="cost-cell">
                                                <?php if ($report['cost']): ?>
                                                    ₱<?php echo number_format($report['cost'], 2); ?>
                                                <?php else: ?>
                                                    <span style="color: var(--text-light);">—</span>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                            
                            <div style="text-align: center; margin-top: 20px;">
                                <a href="#" class="btn-submit" style="width: auto; padding: 10px 20px;">
                                    <i class='bx bx-list-ul'></i>
                                    View All Reports
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
            
            // Resource information display
            const resourceSelect = document.getElementById('resource_id');
            const resourceInfo = document.getElementById('resource-info-display');
            const totalQuantity = document.getElementById('total-quantity');
            const availableQuantity = document.getElementById('available-quantity');
            const conditionStatus = document.getElementById('condition-status');
            const resourceCategory = document.getElementById('resource-category');
            const quantityUnit = document.getElementById('quantity-unit');
            const affectedQuantityInput = document.getElementById('affected_quantity');
            const quantityHint = document.getElementById('quantity-hint');
            const submitBtn = document.getElementById('submit-btn');
            
            resourceSelect.addEventListener('change', function() {
                const selectedOption = this.options[this.selectedIndex];
                const total = selectedOption.getAttribute('data-quantity');
                const available = selectedOption.getAttribute('data-available');
                const condition = selectedOption.getAttribute('data-condition');
                const category = selectedOption.getAttribute('data-category');
                const unit = selectedOption.getAttribute('data-unit') || 'units';
                const type = selectedOption.getAttribute('data-type');
                
                if (total !== null) {
                    totalQuantity.textContent = total;
                    availableQuantity.textContent = available;
                    
                    // Set condition with color
                    conditionStatus.textContent = condition;
                    conditionStatus.className = 'info-value ';
                    if (condition === 'Serviceable') {
                        conditionStatus.classList.add('condition-serviceable');
                    } else if (condition === 'Under Maintenance') {
                        conditionStatus.classList.add('condition-maintenance');
                    } else if (condition === 'Condemned') {
                        conditionStatus.classList.add('condition-condemned');
                    }
                    
                    resourceCategory.textContent = category;
                    quantityUnit.textContent = unit;
                    resourceInfo.style.display = 'flex';
                    
                    // Set max attribute on affected quantity input
                    const maxAffected = Math.min(parseInt(total), parseInt(available));
                    affectedQuantityInput.max = maxAffected;
                    affectedQuantityInput.value = Math.min(1, maxAffected);
                    
                    // Update hint
                    quantityHint.textContent = `Must be between 1 and ${maxAffected} ${unit}`;
                    
                    // Update placeholder
                    affectedQuantityInput.placeholder = `Max: ${maxAffected} ${unit}`;
                } else {
                    resourceInfo.style.display = 'none';
                    affectedQuantityInput.max = '';
                    affectedQuantityInput.placeholder = 'Number of units affected';
                    quantityHint.textContent = 'Must be a positive whole number';
                }
            });
            
            // Validate affected quantity
            affectedQuantityInput.addEventListener('input', function() {
                const max = parseInt(this.max);
                const value = parseInt(this.value);
                
                if (!isNaN(max) && !isNaN(value) && value > max) {
                    this.value = max;
                    alert(`Cannot exceed maximum available quantity of ${max}`);
                }
            });
            
            // Validate form before submission
            document.getElementById('damage-form').addEventListener('submit', function(e) {
                const resourceId = resourceSelect.value;
                const affectedQuantity = parseInt(affectedQuantityInput.value);
                const maxQuantity = parseInt(affectedQuantityInput.max);
                
                if (!resourceId) {
                    e.preventDefault();
                    alert('Please select a resource.');
                    resourceSelect.focus();
                    return false;
                }
                
                if (!isNaN(maxQuantity) && affectedQuantity > maxQuantity) {
                    e.preventDefault();
                    alert(`Error: Cannot affect more than ${maxQuantity} units. Available: ${maxQuantity}`);
                    affectedQuantityInput.focus();
                    return false;
                }
                
                // Show loading state
                submitBtn.disabled = true;
                submitBtn.innerHTML = '<i class="bx bx-loader-circle bx-spin"></i> Submitting Report...';
            });
            
            // Auto-hide success messages after 5 seconds
            const successMessage = document.querySelector('.alert-success');
            if (successMessage) {
                setTimeout(() => {
                    successMessage.style.display = 'none';
                }, 5000);
            }
            
            // Initialize resource info display if there's a pre-selected value
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