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
    $request_type = $_POST['request_type'] ?? '';
    $resource_id = $_POST['resource_id'] ?? '';
    $quantity_requested = $_POST['quantity_requested'] ?? 1;
    $priority = $_POST['priority'] ?? 'medium';
    $description = $_POST['description'] ?? '';
    $justification = $_POST['justification'] ?? '';
    $urgency_level = $_POST['urgency_level'] ?? '';
    $estimated_cost = $_POST['estimated_cost'] ?? '';
    $expected_delivery_date = $_POST['expected_delivery_date'] ?? '';
    $unit_id = $_POST['unit_id'] ?? '';
    $incident_id = $_POST['incident_id'] ?? '';
    
    // Validate required fields
    $required_fields = ['request_type', 'resource_id', 'quantity_requested', 'priority', 'description'];
    $missing_fields = [];
    
    foreach ($required_fields as $field) {
        if (empty($_POST[$field])) {
            $missing_fields[] = $field;
        }
    }
    
    if (!empty($missing_fields)) {
        $error_message = "Please fill in all required fields: " . implode(', ', $missing_fields);
        $form_data = $_POST;
    } else {
        try {
            // Start transaction
            $pdo->beginTransaction();
            
            // Get current resource information
            $resource_query = "SELECT id, resource_name, quantity, available_quantity, 
                              condition_status, minimum_stock_level, reorder_quantity 
                             FROM resources WHERE id = ?";
            $resource_stmt = $pdo->prepare($resource_query);
            $resource_stmt->execute([$resource_id]);
            $resource = $resource_stmt->fetch();
            
            if (!$resource) {
                throw new Exception("Resource not found.");
            }
            
            // Validate quantity requested
            if ($quantity_requested <= 0) {
                throw new Exception("Quantity requested must be at least 1.");
            }
            
            // Create maintenance request with different request types
            if ($request_type === 'supply_request') {
                // For supply requests
                $maintenance_query = "INSERT INTO maintenance_requests 
                                     (resource_id, requested_by, request_type, priority, description, 
                                      requested_date, status, estimated_cost) 
                                     VALUES (?, ?, 'routine_maintenance', ?, ?, NOW(), 'pending', ?)";
                
                $maintenance_desc = "SUPPLY REQUEST - Need to Replenish Stock\n" .
                                   "==============================\n" .
                                   "Resource: " . $resource['resource_name'] . "\n" .
                                   "Quantity Requested: " . $quantity_requested . "\n" .
                                   "Current Stock: " . $resource['available_quantity'] . "/" . $resource['quantity'] . "\n" .
                                   "Minimum Stock Level: " . $resource['minimum_stock_level'] . "\n" .
                                   "Justification: " . $justification . "\n";
                
                if ($urgency_level) {
                    $maintenance_desc .= "Urgency Level: " . $urgency_level . "\n";
                }
                if ($unit_id) {
                    $maintenance_desc .= "For Unit ID: " . $unit_id . "\n";
                }
                if ($incident_id) {
                    $maintenance_desc .= "Related to Incident ID: " . $incident_id . "\n";
                }
                if ($expected_delivery_date) {
                    $maintenance_desc .= "Expected Delivery: " . $expected_delivery_date . "\n";
                }
                $maintenance_desc .= "\nAdditional Details:\n" . $description;
                
            } else {
                // For repair requests
                $maintenance_query = "INSERT INTO maintenance_requests 
                                     (resource_id, requested_by, request_type, priority, description, 
                                      requested_date, status, estimated_cost) 
                                     VALUES (?, ?, 'repair', ?, ?, NOW(), 'pending', ?)";
                
                $maintenance_desc = "REPAIR REQUEST - Equipment Needs Repair\n" .
                                   "==============================\n" .
                                   "Resource: " . $resource['resource_name'] . "\n" .
                                   "Current Condition: " . $resource['condition_status'] . "\n" .
                                   "Justification: " . $justification . "\n";
                
                if ($urgency_level) {
                    $maintenance_desc .= "Urgency Level: " . $urgency_level . "\n";
                }
                if ($unit_id) {
                    $maintenance_desc .= "For Unit ID: " . $unit_id . "\n";
                }
                if ($incident_id) {
                    $maintenance_desc .= "Related to Incident ID: " . $incident_id . "\n";
                }
                if ($expected_delivery_date) {
                    $maintenance_desc .= "Expected Completion: " . $expected_delivery_date . "\n";
                }
                $maintenance_desc .= "\nIssue Description:\n" . $description;
            }
            
            $maintenance_stmt = $pdo->prepare($maintenance_query);
            $maintenance_stmt->execute([
                $resource_id,
                $user_id,
                $priority,
                $maintenance_desc,
                $estimated_cost ?: null
            ]);
            $maintenance_id = $pdo->lastInsertId();
            
            // Create service history entry
            $service_query = "INSERT INTO service_history 
                             (resource_id, maintenance_id, service_type, service_date, 
                              performed_by_id, service_notes, status_after_service, cost) 
                             VALUES (?, ?, ?, NOW(), ?, ?, ?, ?)";
            
            $service_type = ($request_type === 'supply_request') ? 'supply_request' : 'repair_request';
            $service_notes = ($request_type === 'supply_request') ? 
                "SUPPLY REQUEST\n==============\n" : 
                "REPAIR REQUEST\n==============\n";
            
            $service_notes .= "Requested By: " . $full_name . " (Employee ID: " . $user_id . ")\n" .
                            "Resource: " . $resource['resource_name'] . "\n" .
                            "Priority: " . strtoupper($priority) . "\n" .
                            "Justification: " . $justification . "\n";
            
            if ($request_type === 'supply_request') {
                $service_notes .= "Quantity Requested: " . $quantity_requested . "\n" .
                                "Current Available: " . $resource['available_quantity'] . "/" . $resource['quantity'] . "\n" .
                                "Minimum Stock Level: " . $resource['minimum_stock_level'] . "\n";
            }
            
            if ($unit_id) {
                $service_notes .= "For Unit ID: " . $unit_id . "\n";
            }
            if ($incident_id) {
                $service_notes .= "Related to Incident ID: " . $incident_id . "\n";
            }
            if ($urgency_level) {
                $service_notes .= "Urgency: " . $urgency_level . "\n";
            }
            if ($expected_delivery_date) {
                $service_notes .= "Expected Date: " . $expected_delivery_date . "\n";
            }
            if ($estimated_cost) {
                $service_notes .= "Estimated Cost: ₱" . number_format($estimated_cost, 2) . "\n";
            }
            
            $service_notes .= "\nDescription:\n" . $description;
            
            $service_stmt = $pdo->prepare($service_query);
            $service_stmt->execute([
                $resource_id,
                $maintenance_id,
                $service_type,
                $user_id,
                $service_notes,
                $resource['condition_status'],
                $estimated_cost ?: null
            ]);
            
            // Update resource if it's a supply request and stock is low
            if ($request_type === 'supply_request') {
                // Check if current stock is below minimum
                if ($resource['available_quantity'] <= $resource['minimum_stock_level']) {
                    $update_resource_query = "UPDATE resources SET 
                                             maintenance_notes = CONCAT(COALESCE(maintenance_notes, ''), '\n', ?)
                                             WHERE id = ?";
                    
                    $stock_note = date('Y-m-d H:i:s') . " - LOW STOCK ALERT:\n" .
                                 "Current: " . $resource['available_quantity'] . "\n" .
                                 "Minimum: " . $resource['minimum_stock_level'] . "\n" .
                                 "Supply Request #" . $maintenance_id . " submitted by " . $full_name . "\n" .
                                 "Quantity Requested: " . $quantity_requested . "\n";
                    
                    $update_stmt = $pdo->prepare($update_resource_query);
                    $update_stmt->execute([
                        $stock_note,
                        $resource_id
                    ]);
                }
            }
            
            $pdo->commit();
            
            $success_message = ucfirst(str_replace('_', ' ', $request_type)) . " submitted successfully for " . 
                             $resource['resource_name'] . ". Request #" . $maintenance_id . " has been created and is pending approval.";
            $form_data = [];
            
        } catch (Exception $e) {
            $pdo->rollBack();
            $error_message = "Error submitting request: " . $e->getMessage();
            $form_data = $_POST;
        }
    }
}

// Fetch resources for dropdown (all active resources)
$resources_query = "SELECT id, resource_name, resource_type, quantity, available_quantity, 
                   condition_status, category, unit_of_measure, minimum_stock_level, reorder_quantity 
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
$incidents_query = "SELECT id, emergency_type, location, created_at, severity 
                   FROM api_incidents 
                   WHERE (is_fire_rescue_related = 1 OR emergency_type IN ('fire', 'rescue'))
                   AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
                   ORDER BY created_at DESC 
                   LIMIT 20";
$incidents_stmt = $pdo->query($incidents_query);
$incidents = $incidents_stmt->fetchAll();

// Fetch recent requests
$recent_requests_query = "SELECT mr.id, r.resource_name, r.resource_type, r.category,
                         mr.request_type, mr.priority, mr.requested_date, 
                         mr.description, mr.status, mr.estimated_cost,
                         u.first_name, u.last_name,
                         mr.scheduled_date
                         FROM maintenance_requests mr
                         JOIN resources r ON mr.resource_id = r.id
                         JOIN users u ON mr.requested_by = u.id
                         WHERE mr.request_type IN ('routine_maintenance', 'repair')
                         ORDER BY mr.requested_date DESC
                         LIMIT 15";
$recent_requests_stmt = $pdo->query($recent_requests_query);
$recent_requests = $recent_requests_stmt->fetchAll();

// Get statistics for low stock items
$stats_query = "SELECT 
                COUNT(*) as total_resources,
                COUNT(CASE WHEN available_quantity <= minimum_stock_level THEN 1 END) as low_stock,
                COUNT(CASE WHEN condition_status = 'Under Maintenance' THEN 1 END) as under_maintenance,
                COUNT(CASE WHEN condition_status = 'Condemned' THEN 1 END) as condemned,
                SUM(CASE WHEN available_quantity <= minimum_stock_level THEN reorder_quantity ELSE 0 END) as total_reorder_qty
                FROM resources 
                WHERE is_active = 1";
$stats_stmt = $pdo->query($stats_query);
$stats = $stats_stmt->fetch();

$stmt = null;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Request Supplies/Repairs - FRSM</title>
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

        .form-section, .requests-section {
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

        .stock-adequate {
            color: #059669;
        }

        .stock-low {
            color: #d97706;
        }

        .stock-critical {
            color: #dc2626;
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

        .request-type-tabs {
            display: flex;
            gap: 10px;
            margin-bottom: 25px;
        }

        .request-type-tab {
            flex: 1;
            padding: 15px 20px;
            text-align: center;
            border: 2px solid var(--border-color);
            border-radius: 12px;
            cursor: pointer;
            transition: all 0.3s ease;
            font-weight: 600;
            color: var(--text-light);
            background: var(--background-color);
        }

        .request-type-tab:hover {
            border-color: var(--primary-color);
            color: var(--primary-color);
        }

        .request-type-tab.active {
            border-color: var(--primary-color);
            background: var(--primary-color);
            color: white;
        }

        .request-type-tab i {
            margin-right: 8px;
            font-size: 1.2rem;
        }

        .request-details {
            display: none;
            animation: fadeIn 0.3s ease;
        }

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

        .request-details.active {
            display: block;
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

        .requests-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
        }

        .requests-table th {
            text-align: left;
            padding: 12px 16px;
            background: var(--border-color);
            color: var(--text-color);
            font-weight: 600;
            border-bottom: 2px solid var(--border-color);
        }

        .dark-mode .requests-table th {
            background: #334155;
            border-bottom-color: #475569;
        }

        .requests-table td {
            padding: 12px 16px;
            border-bottom: 1px solid var(--border-color);
            color: var(--text-light);
        }

        .dark-mode .requests-table td {
            border-bottom-color: #475569;
        }

        .requests-table tr:hover {
            background: rgba(220, 38, 38, 0.05);
        }

        .dark-mode .requests-table tr:hover {
            background: rgba(220, 38, 38, 0.1);
        }

        .resource-name-cell {
            font-weight: 600;
            color: var(--text-color);
        }

        .request-type-badge {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
        }

        .type-supply {
            background: #dbeafe;
            color: #1d4ed8;
        }

        .type-repair {
            background: #fef3c7;
            color: #d97706;
        }

        .priority-badge {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
        }

        .priority-low {
            background: #d1fae5;
            color: #065f46;
        }

        .priority-medium {
            background: #fef3c7;
            color: #d97706;
        }

        .priority-high {
            background: #fee2e2;
            color: #dc2626;
        }

        .priority-critical {
            background: #1f2937;
            color: #ffffff;
        }

        .status-badge {
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

        .stat-low-stock {
            color: #d97706;
        }

        .stat-maintenance {
            color: #ef4444;
        }

        .stat-reorder {
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
            
            .form-section, .requests-section {
                padding: 30px 25px;
            }
            
            .quick-stats {
                grid-template-columns: 1fr;
                padding: 0 25px;
            }
            
            .request-type-tabs {
                flex-direction: column;
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
                        <a href="log_usage.php" class="submenu-item">Log Usage</a>
                        <a href="report_damages.php" class="submenu-item">Report Damages</a>
                        <a href="request_supplies.php" class="submenu-item active">Request Supplies</a>
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
            <!-- Header -->
            <div class="header">
                <div class="header-content">
                    <div class="search-container">
                        <div class="search-box">
                            <svg class="search-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path>
                            </svg>
                            <input type="text" placeholder="Search requests, resources..." class="search-input">
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
                        <h1 class="dashboard-title">Request Supplies/Repairs</h1>
                        <p class="dashboard-subtitle">Request new supplies or repairs for equipment and resources</p>
                    </div>
                </div>

                <!-- Inventory Statistics -->
                <div class="quick-stats">
                    <div class="quick-stat-item">
                        <div class="quick-stat-number stat-total"><?php echo $stats['total_resources'] ?? 0; ?></div>
                        <div class="quick-stat-label">Total Resources</div>
                        <div class="quick-stat-desc">Active inventory items</div>
                    </div>
                    <div class="quick-stat-item">
                        <div class="quick-stat-number stat-low-stock"><?php echo $stats['low_stock'] ?? 0; ?></div>
                        <div class="quick-stat-label">Low Stock</div>
                        <div class="quick-stat-desc">Below minimum level</div>
                    </div>
                    <div class="quick-stat-item">
                        <div class="quick-stat-number stat-maintenance"><?php echo $stats['under_maintenance'] ?? 0; ?></div>
                        <div class="quick-stat-label">Under Maintenance</div>
                        <div class="quick-stat-desc">Items being repaired</div>
                    </div>
                    <div class="quick-stat-item">
                        <div class="quick-stat-number stat-reorder"><?php echo $stats['total_reorder_qty'] ?? 0; ?></div>
                        <div class="quick-stat-label">Reorder Quantity</div>
                        <div class="quick-stat-desc">Total needed units</div>
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
                    <!-- Request Form -->
                    <div class="form-section">
                        <h2 class="section-title">
                            <i class='bx bxs-package'></i>
                            Submit Request
                        </h2>

                        <!-- Request Type Tabs -->
                        <div class="request-type-tabs">
                            <div class="request-type-tab active" data-type="supply_request">
                                <i class='bx bxs-cart-add'></i>
                                Request Supplies
                            </div>
                            <div class="request-type-tab" data-type="repair_request">
                                <i class='bx bxs-wrench'></i>
                                Request Repairs
                            </div>
                        </div>

                        <form method="POST" id="request-form">
                            <input type="hidden" name="request_type" id="request_type_input" value="supply_request">
                            
                            <div class="form-group">
                                <label class="form-label">
                                    <span class="required">*</span> Resource
                                </label>
                                <select class="form-control" name="resource_id" id="resource_id" required>
                                    <option value="">Select a resource...</option>
                                    <?php foreach ($resources as $resource): 
                                        // Determine stock status
                                        $stock_class = '';
                                        $stock_status = '';
                                        $available = $resource['available_quantity'];
                                        $minimum = $resource['minimum_stock_level'];
                                        
                                        if ($minimum > 0) {
                                            if ($available <= 0) {
                                                $stock_class = 'stock-critical';
                                                $stock_status = 'OUT OF STOCK';
                                            } elseif ($available <= $minimum) {
                                                $stock_class = 'stock-low';
                                                $stock_status = 'LOW STOCK';
                                            } else {
                                                $stock_class = 'stock-adequate';
                                                $stock_status = 'IN STOCK';
                                            }
                                        } else {
                                            $stock_class = 'stock-adequate';
                                            $stock_status = 'IN STOCK';
                                        }
                                        
                                        // Determine condition class
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
                                            data-minimum="<?php echo $resource['minimum_stock_level']; ?>"
                                            data-reorder="<?php echo $resource['reorder_quantity']; ?>"
                                            data-type="<?php echo $resource['resource_type']; ?>"
                                            data-category="<?php echo $resource['category']; ?>"
                                            data-unit="<?php echo $resource['unit_of_measure'] ?? 'units'; ?>"
                                            data-stock-class="<?php echo $stock_class; ?>"
                                            data-stock-status="<?php echo $stock_status; ?>">
                                            <?php echo htmlspecialchars($resource['resource_name']); ?> 
                                            (<?php echo $resource['resource_type']; ?>)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <div id="resource-info-display" class="resource-info" style="display: none;">
                                    <div class="info-item">
                                        <span class="info-label">Stock:</span>
                                        <span class="info-value" id="stock-status"></span>
                                    </div>
                                    <div class="info-item">
                                        <span class="info-label">Available:</span>
                                        <span class="info-value" id="available-quantity">0</span>
                                        <span id="quantity-unit">units</span>
                                    </div>
                                    <div class="info-item">
                                        <span class="info-label">Total:</span>
                                        <span class="info-value" id="total-quantity">0</span>
                                        <span>units</span>
                                    </div>
                                    <div class="info-item">
                                        <span class="info-label">Condition:</span>
                                        <span class="info-value" id="condition-status"></span>
                                    </div>
                                    <div class="info-item">
                                        <span class="info-label">Minimum:</span>
                                        <span class="info-value" id="minimum-stock">0</span>
                                        <span>units</span>
                                    </div>
                                    <div class="info-item">
                                        <span class="info-label">Reorder Qty:</span>
                                        <span class="info-value" id="reorder-quantity">0</span>
                                        <span>units</span>
                                    </div>
                                </div>
                            </div>

                            <!-- Supply Request Details (Default) -->
                            <div id="supply-details" class="request-details active">
                                <div class="form-group">
                                    <label class="form-label">
                                        <span class="required">*</span> Quantity Requested
                                    </label>
                                    <input type="number" 
                                           class="form-control" 
                                           name="quantity_requested" 
                                           id="quantity_requested"
                                           min="1" 
                                           step="1" 
                                           required
                                           value="<?php echo htmlspecialchars($form_data['quantity_requested'] ?? '1'); ?>"
                                           placeholder="Number of units needed">
                                    <div class="form-text" id="quantity-hint">Suggested reorder quantity: <span id="suggested-quantity">0</span> units</div>
                                </div>

                                <div class="form-group">
                                    <label class="form-label">
                                        <span class="required">*</span> Justification for Request
                                    </label>
                                    <textarea class="form-control" 
                                              name="justification" 
                                              rows="3"
                                              required
                                              placeholder="Why do you need these supplies? (e.g., running low, upcoming event, emergency response needs...)"><?php echo htmlspecialchars($form_data['justification'] ?? ''); ?></textarea>
                                    <div class="form-text">Explain why these supplies are needed</div>
                                </div>

                                <div class="form-group">
                                    <label class="form-label">Expected Delivery Date (Optional)</label>
                                    <input type="date" 
                                           class="form-control" 
                                           name="expected_delivery_date"
                                           min="<?php echo date('Y-m-d'); ?>"
                                           value="<?php echo htmlspecialchars($form_data['expected_delivery_date'] ?? ''); ?>">
                                    <div class="form-text">When do you expect to receive these supplies?</div>
                                </div>
                            </div>

                            <!-- Repair Request Details (Hidden by default) -->
                            <div id="repair-details" class="request-details">
                                <div class="form-group">
                                    <label class="form-label">
                                        <span class="required">*</span> Justification for Repair
                                    </label>
                                    <textarea class="form-control" 
                                              name="justification" 
                                              rows="3"
                                              required
                                              placeholder="Why does this equipment need repair? (e.g., malfunction, damage, safety concern...)"><?php echo htmlspecialchars($form_data['justification'] ?? ''); ?></textarea>
                                    <div class="form-text">Explain why this repair is necessary</div>
                                </div>

                                <div class="form-group">
                                    <label class="form-label">Expected Completion Date (Optional)</label>
                                    <input type="date" 
                                           class="form-control" 
                                           name="expected_delivery_date"
                                           min="<?php echo date('Y-m-d'); ?>"
                                           value="<?php echo htmlspecialchars($form_data['expected_delivery_date'] ?? ''); ?>">
                                    <div class="form-text">When should the repair be completed?</div>
                                </div>
                            </div>

                            <!-- Common Fields -->
                            <div class="form-group">
                                <label class="form-label">
                                    <span class="required">*</span> Priority
                                </label>
                                <select class="form-control" name="priority" id="priority" required>
                                    <option value="">Select priority...</option>
                                    <option value="low" <?php echo ($form_data['priority'] ?? '') == 'low' ? 'selected' : ''; ?>>Low - Routine request</option>
                                    <option value="medium" <?php echo ($form_data['priority'] ?? '') == 'medium' ? 'selected' : ''; ?>>Medium - Important but not urgent</option>
                                    <option value="high" <?php echo ($form_data['priority'] ?? '') == 'high' ? 'selected' : ''; ?>>High - Urgent request</option>
                                    <option value="critical" <?php echo ($form_data['priority'] ?? '') == 'critical' ? 'selected' : ''; ?>>Critical - Emergency situation</option>
                                </select>
                            </div>

                            <div class="form-group">
                                <label class="form-label">Urgency Level (Optional)</label>
                                <select class="form-control" name="urgency_level">
                                    <option value="">Select urgency...</option>
                                    <option value="routine" <?php echo ($form_data['urgency_level'] ?? '') == 'routine' ? 'selected' : ''; ?>>Routine - Normal processing</option>
                                    <option value="urgent" <?php echo ($form_data['urgency_level'] ?? '') == 'urgent' ? 'selected' : ''; ?>>Urgent - Expedite processing</option>
                                    <option value="emergency" <?php echo ($form_data['urgency_level'] ?? '') == 'emergency' ? 'selected' : ''; ?>>Emergency - Immediate attention</option>
                                </select>
                            </div>

                            <div class="form-group">
                                <label class="form-label">
                                    <span class="required">*</span> Description
                                </label>
                                <textarea class="form-control" 
                                          name="description" 
                                          id="description"
                                          rows="4"
                                          required
                                          placeholder="Provide detailed description..."><?php echo htmlspecialchars($form_data['description'] ?? ''); ?></textarea>
                                <div class="form-text" id="description-hint">Describe what supplies are needed or what needs to be repaired</div>
                            </div>

                            <div class="form-group">
                                <label class="form-label">Estimated Cost (Optional)</label>
                                <div style="position: relative;">
                                    <span style="position: absolute; left: 12px; top: 50%; transform: translateY(-50%); color: var(--text-light);">₱</span>
                                    <input type="number" 
                                           class="form-control" 
                                           name="estimated_cost"
                                           style="padding-left: 30px;"
                                           min="0" 
                                           step="0.01"
                                           value="<?php echo htmlspecialchars($form_data['estimated_cost'] ?? ''); ?>"
                                           placeholder="0.00">
                                </div>
                                <div class="form-text">Estimated cost in PHP (leave blank if unknown)</div>
                            </div>

                            <div class="form-group">
                                <label class="form-label">For Specific Unit (Optional)</label>
                                <select class="form-control" name="unit_id">
                                    <option value="">Select unit...</option>
                                    <?php foreach ($units as $unit): ?>
                                        <option value="<?php echo $unit['id']; ?>"
                                            <?php echo ($form_data['unit_id'] ?? '') == $unit['id'] ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($unit['unit_name']); ?> (<?php echo $unit['unit_code']; ?>)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <div class="form-text">Which unit needs this resource?</div>
                            </div>

                            <div class="form-group">
                                <label class="form-label">Related to Incident (Optional)</label>
                                <select class="form-control" name="incident_id">
                                    <option value="">Select incident...</option>
                                    <?php foreach ($incidents as $incident): 
                                        $severity_class = '';
                                        switch ($incident['severity']) {
                                            case 'low':
                                                $severity_class = 'priority-low';
                                                break;
                                            case 'medium':
                                                $severity_class = 'priority-medium';
                                                break;
                                            case 'high':
                                            case 'critical':
                                                $severity_class = 'priority-critical';
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
                                <div class="form-text">Link this request to a specific incident</div>
                            </div>

                            <button type="submit" class="btn-submit" id="submit-btn">
                                <i class='bx bx-send'></i>
                                Submit Request
                            </button>
                        </form>
                    </div>

                    <!-- Recent Requests -->
                    <div class="requests-section">
                        <h2 class="section-title">
                            <i class='bx bxs-history'></i>
                            Recent Requests
                        </h2>

                        <?php if (empty($recent_requests)): ?>
                            <div class="no-data">
                                <i class='bx bx-package'></i>
                                <p>No recent requests found</p>
                                <p class="form-text">Requests will appear here after submission</p>
                            </div>
                        <?php else: ?>
                            <div style="overflow-x: auto;">
                                <table class="requests-table">
                                    <thead>
                                        <tr>
                                            <th>Resource</th>
                                            <th>Type</th>
                                            <th>Priority</th>
                                            <th>Status</th>
                                            <th>Date</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($recent_requests as $request): 
                                            // Determine CSS class for request type
                                            $type_class = ($request['request_type'] === 'routine_maintenance') ? 'type-supply' : 'type-repair';
                                            $type_text = ($request['request_type'] === 'routine_maintenance') ? 'Supply' : 'Repair';
                                            
                                            // Determine CSS class for priority
                                            $priority_class = '';
                                            switch ($request['priority']) {
                                                case 'low':
                                                    $priority_class = 'priority-low';
                                                    break;
                                                case 'medium':
                                                    $priority_class = 'priority-medium';
                                                    break;
                                                case 'high':
                                                    $priority_class = 'priority-high';
                                                    break;
                                                case 'critical':
                                                    $priority_class = 'priority-critical';
                                                    break;
                                                default:
                                                    $priority_class = 'priority-medium';
                                            }
                                            
                                            // Determine CSS class for status
                                            $status_class = '';
                                            switch ($request['status']) {
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
                                                <div class="resource-name-cell"><?php echo htmlspecialchars($request['resource_name']); ?></div>
                                                <div class="form-text" style="font-size: 0.75rem;">
                                                    <?php echo $request['category']; ?> • <?php echo $request['resource_type']; ?>
                                                </div>
                                            </td>
                                            <td>
                                                <span class="request-type-badge <?php echo $type_class; ?>">
                                                    <?php echo $type_text; ?>
                                                </span>
                                            </td>
                                            <td>
                                                <span class="priority-badge <?php echo $priority_class; ?>">
                                                    <?php echo ucfirst($request['priority']); ?>
                                                </span>
                                            </td>
                                            <td>
                                                <span class="status-badge <?php echo $status_class; ?>">
                                                    <?php echo ucfirst(str_replace('_', ' ', $request['status'])); ?>
                                                </span>
                                            </td>
                                            <td>
                                                <?php echo date('M j', strtotime($request['requested_date'])); ?><br>
                                                <div class="form-text" style="font-size: 0.75rem;">
                                                    <?php echo date('g:i A', strtotime($request['requested_date'])); ?>
                                                </div>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                            
                            <div style="text-align: center; margin-top: 20px;">
                                <a href="#" class="btn-submit" style="width: auto; padding: 10px 20px;">
                                    <i class='bx bx-list-ul'></i>
                                    View All Requests
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
            
            // Request type tabs
            const tabs = document.querySelectorAll('.request-type-tab');
            const supplyDetails = document.getElementById('supply-details');
            const repairDetails = document.getElementById('repair-details');
            const requestTypeInput = document.getElementById('request_type_input');
            const descriptionField = document.getElementById('description');
            const descriptionHint = document.getElementById('description-hint');
            const quantityField = document.getElementById('quantity_requested');
            const suggestedQuantitySpan = document.getElementById('suggested-quantity');
            
            tabs.forEach(tab => {
                tab.addEventListener('click', function() {
                    const type = this.getAttribute('data-type');
                    
                    // Update active tab
                    tabs.forEach(t => t.classList.remove('active'));
                    this.classList.add('active');
                    
                    // Update request type input
                    requestTypeInput.value = type === 'supply_request' ? 'supply_request' : 'repair_request';
                    
                    // Show/hide appropriate details
                    if (type === 'supply_request') {
                        supplyDetails.classList.add('active');
                        repairDetails.classList.remove('active');
                        descriptionField.placeholder = 'Provide detailed description of supplies needed...';
                        descriptionHint.textContent = 'Describe what supplies are needed, specifications, and intended use';
                        if (quantityField) quantityField.required = true;
                    } else {
                        supplyDetails.classList.remove('active');
                        repairDetails.classList.add('active');
                        descriptionField.placeholder = 'Describe the repair needed, symptoms, and troubleshooting steps tried...';
                        descriptionHint.textContent = 'Describe what needs to be repaired, symptoms, and any troubleshooting already attempted';
                        if (quantityField) quantityField.required = false;
                    }
                });
            });
            
            // Resource information display
            const resourceSelect = document.getElementById('resource_id');
            const resourceInfo = document.getElementById('resource-info-display');
            const totalQuantity = document.getElementById('total-quantity');
            const availableQuantity = document.getElementById('available-quantity');
            const conditionStatus = document.getElementById('condition-status');
            const stockStatus = document.getElementById('stock-status');
            const minimumStock = document.getElementById('minimum-stock');
            const reorderQuantity = document.getElementById('reorder-quantity');
            const quantityUnit = document.getElementById('quantity-unit');
            const submitBtn = document.getElementById('submit-btn');
            
            resourceSelect.addEventListener('change', function() {
                const selectedOption = this.options[this.selectedIndex];
                const total = selectedOption.getAttribute('data-quantity');
                const available = selectedOption.getAttribute('data-available');
                const condition = selectedOption.getAttribute('data-condition');
                const minimum = selectedOption.getAttribute('data-minimum');
                const reorder = selectedOption.getAttribute('data-reorder');
                const unit = selectedOption.getAttribute('data-unit') || 'units';
                const stockClass = selectedOption.getAttribute('data-stock-class');
                const stockStatusText = selectedOption.getAttribute('data-stock-status');
                
                if (total !== null) {
                    totalQuantity.textContent = total;
                    availableQuantity.textContent = available;
                    minimumStock.textContent = minimum;
                    reorderQuantity.textContent = reorder;
                    
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
                    
                    // Set stock status with color
                    stockStatus.textContent = stockStatusText;
                    stockStatus.className = 'info-value ' + stockClass;
                    
                    quantityUnit.textContent = unit;
                    resourceInfo.style.display = 'flex';
                    
                    // Update suggested quantity for supply requests
                    const minQty = parseInt(minimum) || 0;
                    const availQty = parseInt(available) || 0;
                    const reorderQty = parseInt(reorder) || 0;
                    
                    if (reorderQty > 0) {
                        suggestedQuantitySpan.textContent = reorderQty;
                    } else if (minQty > 0 && availQty < minQty) {
                        suggestedQuantitySpan.textContent = minQty - availQty;
                    } else {
                        suggestedQuantitySpan.textContent = '1';
                    }
                    
                    // Set default quantity for supply requests
                    if (document.querySelector('.request-type-tab.active').getAttribute('data-type') === 'supply_request') {
                        quantityField.value = suggestedQuantitySpan.textContent;
                    }
                    
                } else {
                    resourceInfo.style.display = 'none';
                    suggestedQuantitySpan.textContent = '0';
                }
            });
            
            // Validate form before submission
            document.getElementById('request-form').addEventListener('submit', function(e) {
                const resourceId = resourceSelect.value;
                const requestType = requestTypeInput.value;
                const description = descriptionField.value.trim();
                
                if (!resourceId) {
                    e.preventDefault();
                    alert('Please select a resource.');
                    resourceSelect.focus();
                    return false;
                }
                
                if (description.length < 10) {
                    e.preventDefault();
                    alert('Please provide a more detailed description (at least 10 characters).');
                    descriptionField.focus();
                    return false;
                }
                
                if (requestType === 'supply_request') {
                    const quantity = parseInt(quantityField.value);
                    if (isNaN(quantity) || quantity <= 0) {
                        e.preventDefault();
                        alert('Please enter a valid quantity (must be at least 1).');
                        quantityField.focus();
                        return false;
                    }
                }
                
                // Show loading state
                submitBtn.disabled = true;
                submitBtn.innerHTML = '<i class="bx bx-loader-circle bx-spin"></i> Submitting Request...';
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
            
            // Set default date for expected delivery (1 week from now)
            const deliveryDateField = document.querySelector('input[name="expected_delivery_date"]');
            if (deliveryDateField && !deliveryDateField.value) {
                const oneWeekLater = new Date();
                oneWeekLater.setDate(oneWeekLater.getDate() + 7);
                deliveryDateField.value = oneWeekLater.toISOString().split('T')[0];
            }
        });
    </script>
</body>
</html>