<?php
session_start();
require_once '../../config/db_connection.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: ../../login/login.php");
    exit();
}

$user_id = $_SESSION['user_id'];
$query = "SELECT first_name, middle_name, last_name, role FROM users WHERE id = ?";
$stmt = $pdo->prepare($query);
$stmt->execute([$user_id]);
$user = $stmt->fetch();

if ($user) {
    $first_name = htmlspecialchars($user['first_name']);
    $middle_name = htmlspecialchars($user['middle_name']);
    $last_name = htmlspecialchars($user['last_name']);
    $role = htmlspecialchars($user['role']);
    
    $full_name = $first_name;
    if (!empty($middle_name)) {
        $full_name .= " " . $middle_name;
    }
    $full_name .= " " . $last_name;
} else {
    $full_name = "User";
    $role = "USER";
}

// Check if user is admin
if ($role !== 'ADMIN') {
    header("Location: ../admin_dashboard.php");
    exit();
}

// Create maintenance_requests table if it doesn't exist
$create_table_query = "
CREATE TABLE IF NOT EXISTS maintenance_requests (
    id INT(11) NOT NULL AUTO_INCREMENT,
    resource_id INT(11) NOT NULL,
    requested_by INT(11) NOT NULL,
    request_type ENUM('routine_maintenance', 'repair', 'inspection', 'calibration', 'disposal') NOT NULL,
    priority ENUM('low', 'medium', 'high', 'critical') DEFAULT 'medium',
    description TEXT NOT NULL,
    requested_date DATETIME DEFAULT CURRENT_TIMESTAMP,
    scheduled_date DATE DEFAULT NULL,
    estimated_cost DECIMAL(10,2) DEFAULT NULL,
    status ENUM('pending', 'approved', 'in_progress', 'completed', 'rejected', 'cancelled') DEFAULT 'pending',
    approved_by INT(11) DEFAULT NULL,
    approved_date DATETIME DEFAULT NULL,
    completed_by INT(11) DEFAULT NULL,
    completed_date DATETIME DEFAULT NULL,
    notes TEXT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    FOREIGN KEY (resource_id) REFERENCES resources(id) ON DELETE CASCADE,
    FOREIGN KEY (requested_by) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (approved_by) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (completed_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
";

try {
    $pdo->exec($create_table_query);
} catch (PDOException $e) {
    error_log("Error creating maintenance_requests table: " . $e->getMessage());
}

// Create service_history table if it doesn't exist
$create_history_query = "
CREATE TABLE IF NOT EXISTS service_history (
    id INT(11) NOT NULL AUTO_INCREMENT,
    resource_id INT(11) NOT NULL,
    maintenance_id INT(11) DEFAULT NULL,
    service_type VARCHAR(100) NOT NULL,
    service_date DATE NOT NULL,
    next_service_date DATE DEFAULT NULL,
    performed_by VARCHAR(100) DEFAULT NULL,
    performed_by_id INT(11) DEFAULT NULL,
    service_provider VARCHAR(100) DEFAULT NULL,
    cost DECIMAL(10,2) DEFAULT NULL,
    parts_replaced TEXT DEFAULT NULL,
    labor_hours DECIMAL(5,2) DEFAULT NULL,
    service_notes TEXT DEFAULT NULL,
    status_after_service ENUM('Serviceable', 'Under Maintenance', 'Condemned') DEFAULT 'Serviceable',
    documentation VARCHAR(255) DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    FOREIGN KEY (resource_id) REFERENCES resources(id) ON DELETE CASCADE,
    FOREIGN KEY (maintenance_id) REFERENCES maintenance_requests(id) ON DELETE SET NULL,
    FOREIGN KEY (performed_by_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
";

try {
    $pdo->exec($create_history_query);
} catch (PDOException $e) {
    error_log("Error creating service_history table: " . $e->getMessage());
}

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['approve_request'])) {
        $request_id = $_POST['request_id'];
        $scheduled_date = $_POST['scheduled_date'];
        $estimated_cost = $_POST['estimated_cost'];
        $notes = $_POST['approval_notes'];
        
        $update_query = "
            UPDATE maintenance_requests 
            SET status = 'approved', 
                approved_by = ?, 
                approved_date = NOW(),
                scheduled_date = ?,
                estimated_cost = ?,
                notes = CONCAT(IFNULL(notes, ''), '\n\n[Approval] ', ?)
            WHERE id = ?
        ";
        
        $stmt = $pdo->prepare($update_query);
        $stmt->execute([$user_id, $scheduled_date, $estimated_cost, $notes, $request_id]);
        
        // Update resource status to Under Maintenance
        $resource_query = "SELECT resource_id FROM maintenance_requests WHERE id = ?";
        $resource_stmt = $pdo->prepare($resource_query);
        $resource_stmt->execute([$request_id]);
        $resource = $resource_stmt->fetch();
        
        if ($resource) {
            $update_resource = "UPDATE resources SET condition_status = 'Under Maintenance' WHERE id = ?";
            $update_stmt = $pdo->prepare($update_resource);
            $update_stmt->execute([$resource['resource_id']]);
        }
        
        $_SESSION['success_message'] = "Maintenance request approved successfully!";
        
    } elseif (isset($_POST['reject_request'])) {
        $request_id = $_POST['request_id'];
        $rejection_reason = $_POST['rejection_reason'];
        
        $update_query = "
            UPDATE maintenance_requests 
            SET status = 'rejected', 
                notes = CONCAT(IFNULL(notes, ''), '\n\n[Rejection] ', ?)
            WHERE id = ?
        ";
        
        $stmt = $pdo->prepare($update_query);
        $stmt->execute([$rejection_reason, $request_id]);
        
        $_SESSION['success_message'] = "Maintenance request rejected successfully!";
        
    } elseif (isset($_POST['complete_service'])) {
        $request_id = $_POST['request_id'];
        $service_date = $_POST['service_date'];
        $performed_by = $_POST['performed_by'];
        $parts_replaced = $_POST['parts_replaced'];
        $labor_hours = $_POST['labor_hours'];
        $cost = $_POST['cost'];
        $service_notes = $_POST['service_notes'];
        $status_after = $_POST['status_after'];
        $next_service_date = $_POST['next_service_date'];
        
        // Get resource ID from maintenance request
        $resource_query = "SELECT resource_id FROM maintenance_requests WHERE id = ?";
        $resource_stmt = $pdo->prepare($resource_query);
        $resource_stmt->execute([$request_id]);
        $maintenance = $resource_stmt->fetch();
        
        if ($maintenance) {
            // Insert into service history
            $history_query = "
                INSERT INTO service_history (
                    resource_id, maintenance_id, service_type, service_date, 
                    next_service_date, performed_by, performed_by_id, cost,
                    parts_replaced, labor_hours, service_notes, status_after_service
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ";
            
            $history_stmt = $pdo->prepare($history_query);
            $history_stmt->execute([
                $maintenance['resource_id'],
                $request_id,
                'maintenance',
                $service_date,
                $next_service_date,
                $performed_by,
                $user_id,
                $cost,
                $parts_replaced,
                $labor_hours,
                $service_notes,
                $status_after
            ]);
            
            // Update maintenance request
            $update_query = "
                UPDATE maintenance_requests 
                SET status = 'completed', 
                    completed_by = ?,
                    completed_date = NOW(),
                    notes = CONCAT(IFNULL(notes, ''), '\n\n[Completed] ', ?)
                WHERE id = ?
            ";
            
            $update_stmt = $pdo->prepare($update_query);
            $update_stmt->execute([$user_id, $service_notes, $request_id]);
            
            // Update resource status
            $resource_update = "UPDATE resources SET condition_status = ? WHERE id = ?";
            $resource_update_stmt = $pdo->prepare($resource_update);
            $resource_update_stmt->execute([$status_after, $maintenance['resource_id']]);
            
            $_SESSION['success_message'] = "Service completed and recorded successfully!";
        }
        
    } elseif (isset($_POST['record_inspection'])) {
        $resource_id = $_POST['resource_id'];
        $inspection_date = $_POST['inspection_date'];
        $inspector = $_POST['inspector'];
        $inspection_type = $_POST['inspection_type'];
        $inspection_result = $_POST['inspection_result'];
        $findings = $_POST['findings'];
        $recommendations = $_POST['recommendations'];
        $next_inspection_date = $_POST['next_inspection_date'];
        
        $history_query = "
            INSERT INTO service_history (
                resource_id, service_type, service_date, next_service_date,
                performed_by, inspection_result, service_notes, status_after_service
            ) VALUES (?, ?, ?, ?, ?, ?, ?, 'Serviceable')
        ";
        
        $notes = "Findings: " . $findings . "\nRecommendations: " . $recommendations;
        
        $history_stmt = $pdo->prepare($history_query);
        $history_stmt->execute([
            $resource_id,
            $inspection_type,
            $inspection_date,
            $next_inspection_date,
            $inspector,
            $inspection_result,
            $notes
        ]);
        
        // Update resource's last inspection date
        $update_resource = "UPDATE resources SET last_inspection = ?, next_inspection = ? WHERE id = ?";
        $update_stmt = $pdo->prepare($update_resource);
        $update_stmt->execute([$inspection_date, $next_inspection_date, $resource_id]);
        
        $_SESSION['success_message'] = "Inspection recorded successfully!";
        
    } elseif (isset($_POST['approve_disposal'])) {
        $resource_id = $_POST['resource_id'];
        $disposal_date = $_POST['disposal_date'];
        $disposal_method = $_POST['disposal_method'];
        $disposal_reason = $_POST['disposal_reason'];
        $disposal_notes = $_POST['disposal_notes'];
        
        // Create disposal request
        $request_query = "
            INSERT INTO maintenance_requests (
                resource_id, requested_by, request_type, priority, description, 
                status, approved_by, approved_date, notes
            ) VALUES (?, ?, 'disposal', 'medium', ?, 'approved', ?, NOW(), ?)
        ";
        
        $request_stmt = $pdo->prepare($request_query);
        $request_stmt->execute([
            $resource_id,
            $user_id,
            $disposal_reason,
            $user_id,
            "Disposal Method: " . $disposal_method . "\nNotes: " . $disposal_notes
        ]);
        
        $request_id = $pdo->lastInsertId();
        
        // Record disposal in service history
        $history_query = "
            INSERT INTO service_history (
                resource_id, maintenance_id, service_type, service_date,
                performed_by, performed_by_id, service_notes, status_after_service
            ) VALUES (?, ?, 'disposal', ?, ?, ?, ?, 'Condemned')
        ";
        
        $history_stmt = $pdo->prepare($history_query);
        $history_stmt->execute([
            $resource_id,
            $request_id,
            $disposal_date,
            'System Administrator',
            $user_id,
            "Disposal approved. Method: " . $disposal_method . "\nReason: " . $disposal_reason . "\nNotes: " . $disposal_notes
        ]);
        
        // Update resource status to Condemned and mark as inactive
        $update_resource = "UPDATE resources SET condition_status = 'Condemned', is_active = 0 WHERE id = ?";
        $update_stmt = $pdo->prepare($update_resource);
        $update_stmt->execute([$resource_id]);
        
        $_SESSION['success_message'] = "Disposal approved and resource marked as condemned!";
    }
    
    // Redirect to prevent form resubmission
    header("Location: approve_maintenance.php");
    exit();
}

// Get filter parameters
$status_filter = isset($_GET['status']) ? $_GET['status'] : 'pending';
$type_filter = isset($_GET['type']) ? $_GET['type'] : 'all';
$priority_filter = isset($_GET['priority']) ? $_GET['priority'] : 'all';
$resource_filter = isset($_GET['resource']) ? $_GET['resource'] : 'all';

// Build query for maintenance requests
$where_conditions = [];
$params = [];

if ($status_filter !== 'all') {
    $where_conditions[] = "mr.status = ?";
    $params[] = $status_filter;
}

if ($type_filter !== 'all') {
    $where_conditions[] = "mr.request_type = ?";
    $params[] = $type_filter;
}

if ($priority_filter !== 'all') {
    $where_conditions[] = "mr.priority = ?";
    $params[] = $priority_filter;
}

if ($resource_filter !== 'all') {
    $where_conditions[] = "mr.resource_id = ?";
    $params[] = $resource_filter;
}

$where_clause = '';
if (!empty($where_conditions)) {
    $where_clause = "WHERE " . implode(" AND ", $where_conditions);
}

// Fetch maintenance requests
$requests_query = "
    SELECT mr.*, 
           r.resource_name, r.resource_type, r.category, r.condition_status as resource_status,
           u1.first_name as requester_first, u1.last_name as requester_last,
           u2.first_name as approver_first, u2.last_name as approver_last,
           u3.first_name as completer_first, u3.last_name as completer_last
    FROM maintenance_requests mr
    LEFT JOIN resources r ON mr.resource_id = r.id
    LEFT JOIN users u1 ON mr.requested_by = u1.id
    LEFT JOIN users u2 ON mr.approved_by = u2.id
    LEFT JOIN users u3 ON mr.completed_by = u3.id
    $where_clause
    ORDER BY 
        CASE mr.priority 
            WHEN 'critical' THEN 1
            WHEN 'high' THEN 2
            WHEN 'medium' THEN 3
            WHEN 'low' THEN 4
            ELSE 5
        END,
        mr.requested_date DESC
";

$requests_stmt = $pdo->prepare($requests_query);
$requests_stmt->execute($params);
$requests = $requests_stmt->fetchAll();

// Fetch resources for filter dropdown
$resources_query = "SELECT id, resource_name FROM resources WHERE is_active = 1 ORDER BY resource_name";
$resources_stmt = $pdo->prepare($resources_query);
$resources_stmt->execute();
$all_resources = $resources_stmt->fetchAll();

// Fetch service history for recently completed services
$history_query = "
    SELECT sh.*, r.resource_name, r.resource_type
    FROM service_history sh
    LEFT JOIN resources r ON sh.resource_id = r.id
    ORDER BY sh.service_date DESC, sh.created_at DESC
    LIMIT 10
";

$history_stmt = $pdo->prepare($history_query);
$history_stmt->execute();
$recent_history = $history_stmt->fetchAll();

// Get statistics
$stats_query = "
    SELECT 
        COUNT(*) as total_requests,
        SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending,
        SUM(CASE WHEN status = 'approved' THEN 1 ELSE 0 END) as approved,
        SUM(CASE WHEN status = 'in_progress' THEN 1 ELSE 0 END) as in_progress,
        SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed,
        SUM(CASE WHEN status = 'rejected' THEN 1 ELSE 0 END) as rejected,
        SUM(CASE WHEN request_type = 'disposal' THEN 1 ELSE 0 END) as disposal_requests
    FROM maintenance_requests
";

$stats_stmt = $pdo->prepare($stats_query);
$stats_stmt->execute();
$stats = $stats_stmt->fetch();

// Get resources due for inspection
$inspection_due_query = "
    SELECT * FROM resources 
    WHERE (next_inspection IS NOT NULL AND next_inspection <= DATE_ADD(CURDATE(), INTERVAL 7 DAY))
       OR (last_inspection IS NULL AND created_at < DATE_SUB(CURDATE(), INTERVAL 90 DAY))
    AND condition_status = 'Serviceable'
    AND is_active = 1
    ORDER BY next_inspection ASC
    LIMIT 10
";

$inspection_due_stmt = $pdo->prepare($inspection_due_query);
$inspection_due_stmt->execute();
$inspection_due = $inspection_due_stmt->fetchAll();

// Get resources under maintenance
$under_maintenance_query = "
    SELECT r.*, mr.request_type, mr.requested_date, mr.scheduled_date
    FROM resources r
    LEFT JOIN maintenance_requests mr ON r.id = mr.resource_id 
        AND mr.status IN ('approved', 'in_progress')
    WHERE r.condition_status = 'Under Maintenance'
    ORDER BY mr.priority, mr.scheduled_date
";

$under_maintenance_stmt = $pdo->prepare($under_maintenance_query);
$under_maintenance_stmt->execute();
$under_maintenance = $under_maintenance_stmt->fetchAll();

// Get resources marked as condemned
$condemned_query = "
    SELECT r.*, 
           (SELECT MAX(service_date) FROM service_history WHERE resource_id = r.id AND service_type = 'disposal') as disposal_date
    FROM resources r
    WHERE r.condition_status = 'Condemned'
    ORDER BY r.updated_at DESC
";

$condemned_stmt = $pdo->prepare($condemned_query);
$condemned_stmt->execute();
$condemned_resources = $condemned_stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Approve Maintenance - Fire & Rescue Services</title>
    <link href='https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css' rel='stylesheet'>
    <link rel="icon" type="image/png" sizes="32x32" href="../../img/frsm-logo.png">
    <link rel="stylesheet" href="../../css/dashboard.css">
    <style>
        :root {
            --primary-color: #dc2626;
            --primary-dark: #b91c1c;
            --secondary-color: #ef4444;
            --background-color: #f8fafc;
            --text-color: #1f2937;
            --text-light: #6b7280;
            --border-color: #e5e7eb;
            --card-bg: #ffffff;
            --sidebar-bg: #ffffff;
            --success: #10b981;
            --warning: #f59e0b;
            --danger: #dc2626;
            --info: #3b82f6;
        }
        
        .dark-mode {
            --background-color: #0f172a;
            --text-color: #f1f5f9;
            --text-light: #94a3b8;
            --border-color: #334155;
            --card-bg: #1e293b;
            --sidebar-bg: #1e293b;
        }
        
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background-color: var(--background-color);
            color: var(--text-color);
            margin: 0;
            padding: 0;
        }
        
        .dashboard-content {
            padding: 0;
        }
        
        .dashboard-header {
            color: white;
            padding: 60px 40px 40px;
            border-radius: 0 0 30px 30px;
            margin-bottom: 40px;
            background: linear-gradient(135deg, #f8fafc 0%, #e2e8f0 100%);
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.08);
            border-bottom: 1px solid var(--border-color);
        }
        
        .dark-mode .dashboard-header {
            background: linear-gradient(135deg, #1e293b 0%, #334155 100%);
        }
        
        .dashboard-title {
            font-size: 40px;
            margin-bottom: 12px;
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }
        
        .dashboard-subtitle {
            font-size: 16px;
            color: var(--text-color);
            opacity: 0.9;
        }
        
        .dashboard-actions {
            display: flex;
            gap: 12px;
            margin-top: 20px;
        }
        
        .primary-button, .secondary-button {
            padding: 12px 20px;
            border-radius: 12px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 8px;
            border: none;
            font-size: 14px;
        }
        
        .primary-button {
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            color: white;
            box-shadow: 0 4px 15px rgba(220, 38, 38, 0.3);
        }
        
        .primary-button:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(220, 38, 38, 0.4);
        }
        
        .secondary-button {
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            color: var(--text-color);
        }
        
        .maintenance-container {
            padding: 0 40px 40px;
        }
        
        .stats-container {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 16px;
            margin-bottom: 24px;
        }
        
        .stat-card {
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: 16px;
            padding: 20px;
            display: flex;
            align-items: center;
            gap: 16px;
            transition: all 0.3s ease;
            cursor: pointer;
        }
        
        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.15);
        }
        
        .stat-icon {
            width: 52px;
            height: 52px;
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
            font-size: 28px;
            font-weight: 800;
            margin-bottom: 4px;
        }
        
        .stat-label {
            font-size: 14px;
            color: var(--text-light);
        }
        
        .filters-container {
            display: flex;
            gap: 16px;
            margin-bottom: 24px;
            flex-wrap: wrap;
            background: var(--card-bg);
            padding: 20px;
            border-radius: 16px;
            border: 1px solid var(--border-color);
        }
        
        .filter-group {
            display: flex;
            flex-direction: column;
            gap: 8px;
            flex: 1;
            min-width: 200px;
        }
        
        .filter-label {
            font-size: 14px;
            font-weight: 600;
        }
        
        .filter-select {
            padding: 10px 16px;
            border-radius: 10px;
            border: 1px solid var(--border-color);
            background: var(--card-bg);
            color: var(--text-color);
            font-size: 14px;
        }
        
        .tabs {
            display: flex;
            gap: 8px;
            margin-bottom: 24px;
            border-bottom: 1px solid var(--border-color);
            padding-bottom: 16px;
        }
        
        .tab {
            padding: 12px 24px;
            border-radius: 10px;
            background: none;
            border: none;
            color: var(--text-light);
            cursor: pointer;
            font-weight: 600;
            transition: all 0.3s ease;
        }
        
        .tab:hover {
            background: var(--background-color);
        }
        
        .tab.active {
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            color: white;
        }
        
        .tab-content {
            display: none;
        }
        
        .tab-content.active {
            display: block;
        }
        
        .requests-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));
            gap: 20px;
            margin-bottom: 40px;
        }
        
        .request-card {
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: 16px;
            overflow: hidden;
            transition: all 0.3s ease;
        }
        
        .request-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.15);
        }
        
        .request-header {
            padding: 20px;
            border-bottom: 1px solid var(--border-color);
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        
        .request-title {
            font-size: 18px;
            font-weight: 700;
            margin-bottom: 4px;
        }
        
        .request-type {
            font-size: 12px;
            padding: 4px 8px;
            border-radius: 4px;
            display: inline-block;
        }
        
        .request-body {
            padding: 20px;
        }
        
        .request-info {
            display: flex;
            flex-direction: column;
            gap: 12px;
            margin-bottom: 16px;
        }
        
        .info-item {
            display: flex;
            justify-content: space-between;
        }
        
        .info-label {
            color: var(--text-light);
            font-size: 13px;
        }
        
        .info-value {
            font-weight: 600;
            text-align: right;
        }
        
        .request-footer {
            padding: 16px 20px;
            border-top: 1px solid var(--border-color);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .status-badge {
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            text-transform: uppercase;
        }
        
        .status-pending {
            background: rgba(245, 158, 11, 0.1);
            color: var(--warning);
        }
        
        .status-approved {
            background: rgba(59, 130, 246, 0.1);
            color: var(--info);
        }
        
        .status-in-progress {
            background: rgba(139, 92, 246, 0.1);
            color: #8b5cf6;
        }
        
        .status-completed {
            background: rgba(16, 185, 129, 0.1);
            color: var(--success);
        }
        
        .status-rejected {
            background: rgba(220, 38, 38, 0.1);
            color: var(--danger);
        }
        
        .priority-badge {
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 11px;
            font-weight: 600;
        }
        
        .priority-critical {
            background: rgba(220, 38, 38, 0.1);
            color: var(--danger);
        }
        
        .priority-high {
            background: rgba(245, 158, 11, 0.1);
            color: var(--warning);
        }
        
        .priority-medium {
            background: rgba(59, 130, 246, 0.1);
            color: var(--info);
        }
        
        .priority-low {
            background: rgba(107, 114, 128, 0.1);
            color: var(--text-light);
        }
        
        .action-buttons {
            display: flex;
            gap: 8px;
        }
        
        .action-button {
            padding: 8px 16px;
            border-radius: 8px;
            border: none;
            cursor: pointer;
            font-weight: 500;
            font-size: 13px;
            display: flex;
            align-items: center;
            gap: 6px;
            transition: all 0.3s ease;
        }
        
        .approve-button {
            background: var(--success);
            color: white;
        }
        
        .reject-button {
            background: var(--danger);
            color: white;
        }
        
        .view-button {
            background: var(--info);
            color: white;
        }
        
        .history-table {
            width: 100%;
            border-collapse: collapse;
            background: var(--card-bg);
            border-radius: 16px;
            overflow: hidden;
            border: 1px solid var(--border-color);
        }
        
        .history-table th {
            padding: 16px;
            text-align: left;
            background: var(--background-color);
            font-weight: 600;
            border-bottom: 1px solid var(--border-color);
        }
        
        .history-table td {
            padding: 16px;
            border-bottom: 1px solid var(--border-color);
        }
        
        .history-table tr:last-child td {
            border-bottom: none;
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
            border-radius: 20px;
            width: 90%;
            max-width: 600px;
            max-height: 90vh;
            overflow-y: auto;
        }
        
        .modal-header {
            padding: 24px;
            border-bottom: 1px solid var(--border-color);
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        
        .modal-title {
            font-size: 24px;
            font-weight: 700;
        }
        
        .modal-close {
            background: none;
            border: none;
            font-size: 24px;
            cursor: pointer;
            color: var(--text-light);
        }
        
        .modal-body {
            padding: 24px;
        }
        
        .modal-section {
            margin-bottom: 24px;
        }
        
        .modal-section-title {
            font-size: 18px;
            font-weight: 700;
            margin-bottom: 16px;
            color: var(--primary-color);
        }
        
        .form-group {
            margin-bottom: 16px;
        }
        
        .form-label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
        }
        
        .form-input {
            width: 100%;
            padding: 12px;
            border: 1px solid var(--border-color);
            border-radius: 8px;
            background: var(--card-bg);
            color: var(--text-color);
            font-size: 14px;
        }
        
        .form-input:focus {
            outline: none;
            border-color: var(--primary-color);
        }
        
        .modal-footer {
            padding: 20px 24px;
            border-top: 1px solid var(--border-color);
            display: flex;
            justify-content: flex-end;
            gap: 12px;
        }
        
        .alert {
            padding: 15px 20px;
            border-radius: 10px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 12px;
            animation: slideIn 0.3s ease;
        }
        
        .alert-success {
            background: rgba(16, 185, 129, 0.1);
            border: 1px solid rgba(16, 185, 129, 0.2);
            color: var(--success);
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
        
        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: var(--text-light);
        }
        
        .empty-state-icon {
            font-size: 64px;
            margin-bottom: 16px;
            opacity: 0.5;
        }
    </style>
</head>
<body>

    <!-- Loading Animation -->
    <div class="dashboard-animation" id="dashboard-animation">
        <div class="animation-logo">
            <div class="animation-logo-icon">
                <img src="../../img/frsm-logo.png" alt="Fire & Rescue Logo">
            </div>
            <span class="animation-logo-text">Fire & Rescue</span>
        </div>
        <div class="animation-progress">
            <div class="animation-progress-fill" id="animation-progress"></div>
        </div>
        <div class="animation-text" id="animation-text">Loading Maintenance System...</div>
    </div>
    
    <!-- Approve Modal -->
    <div class="modal-overlay" id="approve-modal">
        <div class="modal">
            <div class="modal-header">
                <h2 class="modal-title">Approve Maintenance Request</h2>
                <button class="modal-close" id="approve-close">&times;</button>
            </div>
            <form method="POST" action="approve_maintenance.php">
                <div class="modal-body">
                    <input type="hidden" name="request_id" id="approve-request-id">
                    <div class="modal-section">
                        <h3 class="modal-section-title">Maintenance Details</h3>
                        <div class="form-group">
                            <label class="form-label">Scheduled Date</label>
                            <input type="date" class="form-input" name="scheduled_date" required>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Estimated Cost</label>
                            <input type="number" class="form-input" name="estimated_cost" step="0.01" min="0" placeholder="0.00" required>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Approval Notes</label>
                            <textarea class="form-input" name="approval_notes" rows="4" placeholder="Add any additional notes or instructions..."></textarea>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="secondary-button" id="approve-cancel">Cancel</button>
                    <button type="submit" class="primary-button" name="approve_request">
                        <i class='bx bx-check'></i>
                        Approve Request
                    </button>
                </div>
            </form>
        </div>
    </div>
    
    <!-- Reject Modal -->
    <div class="modal-overlay" id="reject-modal">
        <div class="modal">
            <div class="modal-header">
                <h2 class="modal-title">Reject Maintenance Request</h2>
                <button class="modal-close" id="reject-close">&times;</button>
            </div>
            <form method="POST" action="approve_maintenance.php">
                <div class="modal-body">
                    <input type="hidden" name="request_id" id="reject-request-id">
                    <div class="modal-section">
                        <h3 class="modal-section-title">Rejection Details</h3>
                        <div class="form-group">
                            <label class="form-label">Reason for Rejection</label>
                            <textarea class="form-input" name="rejection_reason" rows="4" placeholder="Please explain why this request is being rejected..." required></textarea>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="secondary-button" id="reject-cancel">Cancel</button>
                    <button type="submit" class="primary-button reject-button" name="reject_request">
                        <i class='bx bx-x'></i>
                        Reject Request
                    </button>
                </div>
            </form>
        </div>
    </div>
    
    <!-- Complete Service Modal -->
    <div class="modal-overlay" id="complete-modal">
        <div class="modal">
            <div class="modal-header">
                <h2 class="modal-title">Complete Maintenance Service</h2>
                <button class="modal-close" id="complete-close">&times;</button>
            </div>
            <form method="POST" action="approve_maintenance.php">
                <div class="modal-body">
                    <input type="hidden" name="request_id" id="complete-request-id">
                    <div class="modal-section">
                        <h3 class="modal-section-title">Service Details</h3>
                        <div class="form-group">
                            <label class="form-label">Service Date</label>
                            <input type="date" class="form-input" name="service_date" required>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Performed By</label>
                            <input type="text" class="form-input" name="performed_by" required>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Parts Replaced</label>
                            <textarea class="form-input" name="parts_replaced" rows="3" placeholder="List any parts that were replaced..."></textarea>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Labor Hours</label>
                            <input type="number" class="form-input" name="labor_hours" step="0.5" min="0">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Total Cost</label>
                            <input type="number" class="form-input" name="cost" step="0.01" min="0" required>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Service Notes</label>
                            <textarea class="form-input" name="service_notes" rows="4" placeholder="Describe the work performed..." required></textarea>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Status After Service</label>
                            <select class="form-input" name="status_after" required>
                                <option value="Serviceable">Serviceable</option>
                                <option value="Under Maintenance">Under Maintenance</option>
                                <option value="Condemned">Condemned</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Next Service Date (Optional)</label>
                            <input type="date" class="form-input" name="next_service_date">
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="secondary-button" id="complete-cancel">Cancel</button>
                    <button type="submit" class="primary-button" name="complete_service">
                        <i class='bx bx-check'></i>
                        Complete Service
                    </button>
                </div>
            </form>
        </div>
    </div>
    
    <!-- Record Inspection Modal -->
    <div class="modal-overlay" id="inspection-modal">
        <div class="modal">
            <div class="modal-header">
                <h2 class="modal-title">Record Inspection</h2>
                <button class="modal-close" id="inspection-close">&times;</button>
            </div>
            <form method="POST" action="approve_maintenance.php">
                <div class="modal-body">
                    <input type="hidden" name="resource_id" id="inspection-resource-id">
                    <div class="modal-section">
                        <h3 class="modal-section-title">Inspection Details</h3>
                        <div class="form-group">
                            <label class="form-label">Inspection Date</label>
                            <input type="date" class="form-input" name="inspection_date" required>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Inspector</label>
                            <input type="text" class="form-input" name="inspector" required>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Inspection Type</label>
                            <select class="form-input" name="inspection_type" required>
                                <option value="routine">Routine Inspection</option>
                                <option value="preventive">Preventive Maintenance</option>
                                <option value="safety">Safety Inspection</option>
                                <option value="compliance">Compliance Check</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Inspection Result</label>
                            <select class="form-input" name="inspection_result" required>
                                <option value="passed">Passed</option>
                                <option value="passed_with_notes">Passed with Notes</option>
                                <option value="failed">Failed</option>
                                <option value="requires_maintenance">Requires Maintenance</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Findings</label>
                            <textarea class="form-input" name="findings" rows="3" required placeholder="Describe inspection findings..."></textarea>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Recommendations</label>
                            <textarea class="form-input" name="recommendations" rows="3" placeholder="Any recommendations based on findings..."></textarea>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Next Inspection Date</label>
                            <input type="date" class="form-input" name="next_inspection_date">
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="secondary-button" id="inspection-cancel">Cancel</button>
                    <button type="submit" class="primary-button" name="record_inspection">
                        <i class='bx bx-save'></i>
                        Record Inspection
                    </button>
                </div>
            </form>
        </div>
    </div>
    
    <!-- Approve Disposal Modal -->
    <div class="modal-overlay" id="disposal-modal">
        <div class="modal">
            <div class="modal-header">
                <h2 class="modal-title">Approve Resource Disposal</h2>
                <button class="modal-close" id="disposal-close">&times;</button>
            </div>
            <form method="POST" action="approve_maintenance.php">
                <div class="modal-body">
                    <input type="hidden" name="resource_id" id="disposal-resource-id">
                    <div class="modal-section">
                        <h3 class="modal-section-title">Disposal Details</h3>
                        <div class="form-group">
                            <label class="form-label">Disposal Date</label>
                            <input type="date" class="form-input" name="disposal_date" required>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Disposal Method</label>
                            <select class="form-input" name="disposal_method" required>
                                <option value="recycled">Recycled</option>
                                <option value="auctioned">Auctioned</option>
                                <option value="donated">Donated</option>
                                <option value="destroyed">Destroyed</option>
                                <option value="scrapped">Scrapped</option>
                                <option value="sold">Sold</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Disposal Reason</label>
                            <select class="form-input" name="disposal_reason" required>
                                <option value="end_of_life">End of Life</option>
                                <option value="beyond_repair">Beyond Repair</option>
                                <option value="obsolete">Obsolete Technology</option>
                                <option value="safety_hazard">Safety Hazard</option>
                                <option value="costly_repair">Costly to Repair</option>
                                <option value="replacement">Replaced with New Equipment</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Disposal Notes</label>
                            <textarea class="form-input" name="disposal_notes" rows="4" placeholder="Any additional details about the disposal..."></textarea>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="secondary-button" id="disposal-cancel">Cancel</button>
                    <button type="submit" class="primary-button" name="approve_disposal">
                        <i class='bx bx-trash'></i>
                        Approve Disposal
                    </button>
                </div>
            </form>
        </div>
    </div>
    
    <div class="container">
        <!-- Sidebar (same as view_equipment.php) -->
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
                    <a href="../admin_dashboard.php" class="menu-item" id="dashboard-menu">
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
                        <a href="../user/manage_users.php" class="submenu-item">Manage Users</a>
                        <a href="../user/role_control.php" class="submenu-item">Role Control</a>
                        <a href="../user/monitor_activity.php" class="submenu-item">Monitor Activity</a>
                        <a href="../user/reset_passwords.php" class="submenu-item">Reset Passwords</a>
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
                        <a href="../vm/review_data.php" class="submenu-item">Review Data</a>
                        <a href="../vm/approve_applications.php" class="submenu-item">Approve Applications</a>
                        <a href="../vm/assign_volunteers.php" class="submenu-item">Assign Volunteers</a>
                        <a href="../vm/view_availability.php" class="submenu-item">View Availability</a>
                        <a href="../vm/remove_volunteers.php" class="submenu-item">Remove Volunteers</a>
                        <a href="../vm/toggle_volunteer_registration.php" class="submenu-item">Toggle Volunteer Registration Access</a>
                    </div>
                    
                    <!-- Resource Inventory Management -->
                    <div class="menu-item active" onclick="toggleSubmenu('resource-management')">
                        <div class="icon-box icon-bg-green">
                            <i class='bx bxs-cube icon-green'></i>
                        </div>
                        <span class="font-medium">Resource Management</span>
                        <svg class="dropdown-arrow menu-icon rotated" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                        </svg>
                    </div>
                    <div id="resource-management" class="submenu active">
                           <a href="view_equipment.php" class="submenu-item">View Equipment</a>
                        <a href="approve_maintenance.php" class="submenu-item active">Approve Maintenance</a>
                         <a href="review_deployment.php" class="submenu-item">Review Deployment</a>
                        <a href="reports_analytics.php" class="submenu-item">Reports & Analytics</a>
                       
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
                        <a href="../sm/confirm_availability.php" class="submenu-item">Confirm Availability</a>
                        <a href="../sm/request_change.php" class="submenu-item">Request Change</a>
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
                        <a href="../tm/approve_completions.php" class="submenu-item">Approve Completions</a>
                        <a href="../tm/view_training_records.php" class="submenu-item">View Records</a>
                        <a href="../tm/assign_training.php" class="submenu-item">Assign Training</a>
                        <a href="../tm/track_expiry.php" class="submenu-item">Track Expiry</a>
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
                        <a href="#" class="submenu-item">Approve Reports</a>
                        <a href="#" class="submenu-item">Review Violations</a>
                        <a href="#" class="submenu-item">Issue Certificates</a>
                        <a href="#" class="submenu-item">Track Follow-Up</a>
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
                        <a href="#" class="submenu-item">Review Summaries</a>
                        <a href="#" class="submenu-item">Analyze Data</a>
                        <a href="#" class="submenu-item">Export Reports</a>
                        <a href="#" class="submenu-item">Generate Statistics</a>
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
            <!-- Header (same as view_equipment.php) -->
            <div class="header">
                <div class="header-content">
                    <div class="search-container">
                        <div class="search-box">
                            <svg class="search-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path>
                            </svg>
                            <input type="text" placeholder="Search maintenance..." class="search-input" id="search-input">
                            <kbd class="search-shortcut">/</kbd>
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
                <?php if (isset($_SESSION['success_message'])): ?>
                    <div class="alert alert-success">
                        <i class='bx bx-check-circle'></i>
                        <div>
                            <strong>Success!</strong> <?php echo $_SESSION['success_message']; ?>
                        </div>
                    </div>
                    <?php unset($_SESSION['success_message']); ?>
                <?php endif; ?>
                
                <div class="dashboard-header">
                    <div>
                        <h1 class="dashboard-title">Maintenance Approval System</h1>
                        <p class="dashboard-subtitle">Approve maintenance requests, track service history, and manage end-of-life disposal</p>
                    </div>
                    <div class="dashboard-actions">
                        <button class="primary-button" onclick="showInspectionModal()">
                            <i class='bx bx-clipboard'></i>
                            Record Inspection
                        </button>
                    </div>
                </div>
                
                <!-- Maintenance Section -->
                <div class="maintenance-container">
                    <!-- Stats Cards -->
                    <div class="stats-container">
                        <div class="stat-card" onclick="setStatusFilter('pending')">
                            <div class="stat-icon" style="background: rgba(245, 158, 11, 0.1); color: var(--warning);">
                                <i class='bx bx-time-five'></i>
                            </div>
                            <div class="stat-content">
                                <div class="stat-value"><?php echo $stats['pending'] ?? 0; ?></div>
                                <div class="stat-label">Pending Requests</div>
                            </div>
                        </div>
                        <div class="stat-card" onclick="setStatusFilter('approved')">
                            <div class="stat-icon" style="background: rgba(59, 130, 246, 0.1); color: var(--info);">
                                <i class='bx bx-check-circle'></i>
                            </div>
                            <div class="stat-content">
                                <div class="stat-value"><?php echo $stats['approved'] ?? 0; ?></div>
                                <div class="stat-label">Approved</div>
                            </div>
                        </div>
                        <div class="stat-card" onclick="setStatusFilter('in_progress')">
                            <div class="stat-icon" style="background: rgba(139, 92, 246, 0.1); color: #8b5cf6;">
                                <i class='bx bx-wrench'></i>
                            </div>
                            <div class="stat-content">
                                <div class="stat-value"><?php echo $stats['in_progress'] ?? 0; ?></div>
                                <div class="stat-label">In Progress</div>
                            </div>
                        </div>
                        <div class="stat-card" onclick="setStatusFilter('completed')">
                            <div class="stat-icon" style="background: rgba(16, 185, 129, 0.1); color: var(--success);">
                                <i class='bx bx-check-square'></i>
                            </div>
                            <div class="stat-content">
                                <div class="stat-value"><?php echo $stats['completed'] ?? 0; ?></div>
                                <div class="stat-label">Completed</div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Filters -->
                    <div class="filters-container">
                        <div class="filter-group">
                            <label class="filter-label">Status</label>
                            <select class="filter-select" id="status-filter" onchange="applyFilters()">
                                <option value="pending" <?php echo $status_filter === 'pending' ? 'selected' : ''; ?>>Pending</option>
                                <option value="approved" <?php echo $status_filter === 'approved' ? 'selected' : ''; ?>>Approved</option>
                                <option value="in_progress" <?php echo $status_filter === 'in_progress' ? 'selected' : ''; ?>>In Progress</option>
                                <option value="completed" <?php echo $status_filter === 'completed' ? 'selected' : ''; ?>>Completed</option>
                                <option value="rejected" <?php echo $status_filter === 'rejected' ? 'selected' : ''; ?>>Rejected</option>
                                <option value="all" <?php echo $status_filter === 'all' ? 'selected' : ''; ?>>All Statuses</option>
                            </select>
                        </div>
                        <div class="filter-group">
                            <label class="filter-label">Request Type</label>
                            <select class="filter-select" id="type-filter" onchange="applyFilters()">
                                <option value="all" <?php echo $type_filter === 'all' ? 'selected' : ''; ?>>All Types</option>
                                <option value="routine_maintenance" <?php echo $type_filter === 'routine_maintenance' ? 'selected' : ''; ?>>Routine Maintenance</option>
                                <option value="repair" <?php echo $type_filter === 'repair' ? 'selected' : ''; ?>>Repair</option>
                                <option value="inspection" <?php echo $type_filter === 'inspection' ? 'selected' : ''; ?>>Inspection</option>
                                <option value="calibration" <?php echo $type_filter === 'calibration' ? 'selected' : ''; ?>>Calibration</option>
                                <option value="disposal" <?php echo $type_filter === 'disposal' ? 'selected' : ''; ?>>Disposal</option>
                            </select>
                        </div>
                        <div class="filter-group">
                            <label class="filter-label">Priority</label>
                            <select class="filter-select" id="priority-filter" onchange="applyFilters()">
                                <option value="all" <?php echo $priority_filter === 'all' ? 'selected' : ''; ?>>All Priorities</option>
                                <option value="critical" <?php echo $priority_filter === 'critical' ? 'selected' : ''; ?>>Critical</option>
                                <option value="high" <?php echo $priority_filter === 'high' ? 'selected' : ''; ?>>High</option>
                                <option value="medium" <?php echo $priority_filter === 'medium' ? 'selected' : ''; ?>>Medium</option>
                                <option value="low" <?php echo $priority_filter === 'low' ? 'selected' : ''; ?>>Low</option>
                            </select>
                        </div>
                        <div class="filter-group">
                            <label class="filter-label">Resource</label>
                            <select class="filter-select" id="resource-filter" onchange="applyFilters()">
                                <option value="all" <?php echo $resource_filter === 'all' ? 'selected' : ''; ?>>All Resources</option>
                                <?php foreach ($all_resources as $resource): ?>
                                    <option value="<?php echo $resource['id']; ?>" <?php echo $resource_filter == $resource['id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($resource['resource_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    
                    <!-- Tabs -->
                    <div class="tabs">
                        <button class="tab active" onclick="switchTab('requests')">Maintenance Requests</button>
                        <button class="tab" onclick="switchTab('under-maintenance')">Under Maintenance</button>
                        <button class="tab" onclick="switchTab('service-history')">Service History</button>
                        <button class="tab" onclick="switchTab('disposal')">Disposal Management</button>
                    </div>
                    
                    <!-- Requests Tab -->
                    <div id="requests-tab" class="tab-content active">
                        <?php if (count($requests) > 0): ?>
                            <div class="requests-grid">
                                <?php foreach ($requests as $request): 
                                    $requester_name = $request['requester_first'] . ' ' . $request['requester_last'];
                                    $status_class = 'status-' . str_replace('_', '-', $request['status']);
                                    $priority_class = 'priority-' . $request['priority'];
                                ?>
                                    <div class="request-card">
                                        <div class="request-header">
                                            <div>
                                                <div class="request-title"><?php echo htmlspecialchars($request['resource_name']); ?></div>
                                                <span class="request-type"><?php echo ucfirst(str_replace('_', ' ', $request['request_type'])); ?></span>
                                            </div>
                                            <div class="status-badge <?php echo $status_class; ?>">
                                                <?php echo ucfirst(str_replace('_', ' ', $request['status'])); ?>
                                            </div>
                                        </div>
                                        
                                        <div class="request-body">
                                            <div class="request-info">
                                                <div class="info-item">
                                                    <span class="info-label">Requested By:</span>
                                                    <span class="info-value"><?php echo htmlspecialchars($requester_name); ?></span>
                                                </div>
                                                <div class="info-item">
                                                    <span class="info-label">Requested Date:</span>
                                                    <span class="info-value"><?php echo date('M d, Y', strtotime($request['requested_date'])); ?></span>
                                                </div>
                                                <div class="info-item">
                                                    <span class="info-label">Priority:</span>
                                                    <span class="info-value">
                                                        <span class="priority-badge <?php echo $priority_class; ?>">
                                                            <?php echo ucfirst($request['priority']); ?>
                                                        </span>
                                                    </span>
                                                </div>
                                                <?php if ($request['scheduled_date']): ?>
                                                    <div class="info-item">
                                                        <span class="info-label">Scheduled Date:</span>
                                                        <span class="info-value"><?php echo date('M d, Y', strtotime($request['scheduled_date'])); ?></span>
                                                    </div>
                                                <?php endif; ?>
                                                <?php if ($request['estimated_cost']): ?>
                                                    <div class="info-item">
                                                        <span class="info-label">Estimated Cost:</span>
                                                        <span class="info-value">₱<?php echo number_format($request['estimated_cost'], 2); ?></span>
                                                    </div>
                                                <?php endif; ?>
                                            </div>
                                            
                                            <div style="margin-top: 16px;">
                                                <div class="info-label">Description:</div>
                                                <p style="margin-top: 4px; font-size: 14px;"><?php echo htmlspecialchars($request['description']); ?></p>
                                            </div>
                                        </div>
                                        
                                        <div class="request-footer">
                                            <div style="font-size: 12px; color: var(--text-light);">
                                                ID: MREQ-<?php echo str_pad($request['id'], 6, '0', STR_PAD_LEFT); ?>
                                            </div>
                                            <div class="action-buttons">
                                                <?php if ($request['status'] === 'pending'): ?>
                                                    <button class="action-button approve-button" onclick="showApproveModal(<?php echo $request['id']; ?>)">
                                                        <i class='bx bx-check'></i>
                                                        Approve
                                                    </button>
                                                    <button class="action-button reject-button" onclick="showRejectModal(<?php echo $request['id']; ?>)">
                                                        <i class='bx bx-x'></i>
                                                        Reject
                                                    </button>
                                                <?php elseif ($request['status'] === 'approved' || $request['status'] === 'in_progress'): ?>
                                                    <button class="action-button view-button" onclick="showCompleteModal(<?php echo $request['id']; ?>)">
                                                        <i class='bx bx-wrench'></i>
                                                        Complete
                                                    </button>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <div class="empty-state">
                                <div class="empty-state-icon">
                                    <i class='bx bx-wrench'></i>
                                </div>
                                <h3>No Maintenance Requests Found</h3>
                                <p>No requests match your current filters. Try adjusting your filter criteria.</p>
                            </div>
                        <?php endif; ?>
                    </div>
                    
                    <!-- Under Maintenance Tab -->
                    <div id="under-maintenance-tab" class="tab-content">
                        <?php if (count($under_maintenance) > 0): ?>
                            <table class="history-table">
                                <thead>
                                    <tr>
                                        <th>Resource</th>
                                        <th>Type</th>
                                        <th>Maintenance Type</th>
                                        <th>Requested Date</th>
                                        <th>Scheduled Date</th>
                                        <th>Priority</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($under_maintenance as $resource): ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($resource['resource_name']); ?></td>
                                            <td><?php echo htmlspecialchars($resource['resource_type']); ?></td>
                                            <td><?php echo $resource['request_type'] ? ucfirst(str_replace('_', ' ', $resource['request_type'])) : 'Unknown'; ?></td>
                                            <td><?php echo $resource['requested_date'] ? date('M d, Y', strtotime($resource['requested_date'])) : '-'; ?></td>
                                            <td><?php echo $resource['scheduled_date'] ? date('M d, Y', strtotime($resource['scheduled_date'])) : '-'; ?></td>
                                            <td>
                                                <span class="priority-badge priority-<?php echo $resource['priority'] ?? 'medium'; ?>">
                                                    <?php echo ucfirst($resource['priority'] ?? 'medium'); ?>
                                                </span>
                                            </td>
                                            <td>
                                                <button class="action-button view-button" onclick="showCompleteModal(<?php echo $resource['id']; ?>)">
                                                    <i class='bx bx-wrench'></i>
                                                    Complete
                                                </button>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php else: ?>
                            <div class="empty-state">
                                <div class="empty-state-icon">
                                    <i class='bx bx-check-circle'></i>
                                </div>
                                <h3>No Resources Under Maintenance</h3>
                                <p>All equipment is currently serviceable.</p>
                            </div>
                        <?php endif; ?>
                    </div>
                    
                    <!-- Service History Tab -->
                    <div id="service-history-tab" class="tab-content">
                        <?php if (count($recent_history) > 0): ?>
                            <table class="history-table">
                                <thead>
                                    <tr>
                                        <th>Date</th>
                                        <th>Resource</th>
                                        <th>Service Type</th>
                                        <th>Performed By</th>
                                        <th>Cost</th>
                                        <th>Status After</th>
                                        <th>Next Service</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($recent_history as $history): ?>
                                        <tr>
                                            <td><?php echo date('M d, Y', strtotime($history['service_date'])); ?></td>
                                            <td><?php echo htmlspecialchars($history['resource_name']); ?></td>
                                            <td><?php echo ucfirst($history['service_type']); ?></td>
                                            <td><?php echo htmlspecialchars($history['performed_by'] ?? 'N/A'); ?></td>
                                            <td><?php echo $history['cost'] ? '₱' . number_format($history['cost'], 2) : '-'; ?></td>
                                            <td>
                                                <span class="status-badge status-<?php echo strtolower(str_replace(' ', '-', $history['status_after_service'])); ?>">
                                                    <?php echo $history['status_after_service']; ?>
                                                </span>
                                            </td>
                                            <td><?php echo $history['next_service_date'] ? date('M d, Y', strtotime($history['next_service_date'])) : '-'; ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php else: ?>
                            <div class="empty-state">
                                <div class="empty-state-icon">
                                    <i class='bx bx-history'></i>
                                </div>
                                <h3>No Service History Available</h3>
                                <p>Service records will appear here once maintenance is completed.</p>
                            </div>
                        <?php endif; ?>
                    </div>
                    
                    <!-- Disposal Management Tab -->
                    <div id="disposal-tab" class="tab-content">
                        <div style="margin-bottom: 24px;">
                            <h3 style="margin-bottom: 16px;">Resources Due for Disposal</h3>
                            <?php if (count($condemned_resources) > 0): ?>
                                <table class="history-table">
                                    <thead>
                                        <tr>
                                            <th>Resource</th>
                                            <th>Type</th>
                                            <th>Category</th>
                                            <th>Last Service</th>
                                            <th>Disposal Date</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($condemned_resources as $resource): ?>
                                            <tr>
                                                <td><?php echo htmlspecialchars($resource['resource_name']); ?></td>
                                                <td><?php echo htmlspecialchars($resource['resource_type']); ?></td>
                                                <td><?php echo htmlspecialchars($resource['category']); ?></td>
                                                <td><?php echo $resource['last_inspection'] ? date('M d, Y', strtotime($resource['last_inspection'])) : 'Never'; ?></td>
                                                <td><?php echo $resource['disposal_date'] ? date('M d, Y', strtotime($resource['disposal_date'])) : 'Pending'; ?></td>
                                                <td>
                                                    <?php if (!$resource['disposal_date']): ?>
                                                        <button class="action-button reject-button" onclick="showDisposalModal(<?php echo $resource['id']; ?>)">
                                                            <i class='bx bx-trash'></i>
                                                            Approve Disposal
                                                        </button>
                                                    <?php else: ?>
                                                        <span style="color: var(--text-light); font-size: 12px;">Disposed</span>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            <?php else: ?>
                                <div class="empty-state" style="padding: 30px;">
                                    <i class='bx bx-check-shield' style="font-size: 48px; margin-bottom: 16px; opacity: 0.5;"></i>
                                    <p>No resources currently marked for disposal.</p>
                                </div>
                            <?php endif; ?>
                        </div>
                        
                        <div>
                            <h3 style="margin-bottom: 16px;">Upcoming Inspections</h3>
                            <?php if (count($inspection_due) > 0): ?>
                                <table class="history-table">
                                    <thead>
                                        <tr>
                                            <th>Resource</th>
                                            <th>Last Inspection</th>
                                            <th>Next Inspection</th>
                                            <th>Days Until Due</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($inspection_due as $resource): 
                                            $next_date = $resource['next_inspection'] ? new DateTime($resource['next_inspection']) : new DateTime('+90 days');
                                            $today = new DateTime();
                                            $days_until = $today->diff($next_date)->days;
                                            $is_overdue = $next_date < $today;
                                        ?>
                                            <tr>
                                                <td><?php echo htmlspecialchars($resource['resource_name']); ?></td>
                                                <td><?php echo $resource['last_inspection'] ? date('M d, Y', strtotime($resource['last_inspection'])) : 'Never'; ?></td>
                                                <td>
                                                    <?php if ($resource['next_inspection']): ?>
                                                        <?php echo date('M d, Y', strtotime($resource['next_inspection'])); ?>
                                                    <?php else: ?>
                                                        <span style="color: var(--warning);">Not Scheduled</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <span style="color: <?php echo $is_overdue ? 'var(--danger)' : ($days_until <= 7 ? 'var(--warning)' : 'var(--success)'); ?>;">
                                                        <?php echo $is_overdue ? 'Overdue by ' . $days_until . ' days' : $days_until . ' days'; ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <button class="action-button view-button" onclick="recordInspection(<?php echo $resource['id']; ?>)">
                                                        <i class='bx bx-clipboard'></i>
                                                        Record Inspection
                                                    </button>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            <?php else: ?>
                                <div class="empty-state" style="padding: 30px;">
                                    <i class='bx bx-calendar-check' style="font-size: 48px; margin-bottom: 16px; opacity: 0.5;"></i>
                                    <p>No inspections due in the next 7 days.</p>
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
            // Loading animation
            const animationOverlay = document.getElementById('dashboard-animation');
            const animationProgress = document.getElementById('animation-progress');
            
            setTimeout(() => {
                animationProgress.style.width = '100%';
            }, 100);
            
            setTimeout(() => {
                animationOverlay.style.opacity = '0';
                setTimeout(() => {
                    animationOverlay.style.display = 'none';
                }, 300);
            }, 1000);
            
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
            
            // Modal functionality
            initModals();
        });
        
        function initModals() {
            // Approve Modal
            const approveModal = document.getElementById('approve-modal');
            const approveClose = document.getElementById('approve-close');
            const approveCancel = document.getElementById('approve-cancel');
            
            approveClose.addEventListener('click', () => approveModal.classList.remove('active'));
            approveCancel.addEventListener('click', () => approveModal.classList.remove('active'));
            
            // Reject Modal
            const rejectModal = document.getElementById('reject-modal');
            const rejectClose = document.getElementById('reject-close');
            const rejectCancel = document.getElementById('reject-cancel');
            
            rejectClose.addEventListener('click', () => rejectModal.classList.remove('active'));
            rejectCancel.addEventListener('click', () => rejectModal.classList.remove('active'));
            
            // Complete Modal
            const completeModal = document.getElementById('complete-modal');
            const completeClose = document.getElementById('complete-close');
            const completeCancel = document.getElementById('complete-cancel');
            
            completeClose.addEventListener('click', () => completeModal.classList.remove('active'));
            completeCancel.addEventListener('click', () => completeModal.classList.remove('active'));
            
            // Inspection Modal
            const inspectionModal = document.getElementById('inspection-modal');
            const inspectionClose = document.getElementById('inspection-close');
            const inspectionCancel = document.getElementById('inspection-cancel');
            
            inspectionClose.addEventListener('click', () => inspectionModal.classList.remove('active'));
            inspectionCancel.addEventListener('click', () => inspectionModal.classList.remove('active'));
            
            // Disposal Modal
            const disposalModal = document.getElementById('disposal-modal');
            const disposalClose = document.getElementById('disposal-close');
            const disposalCancel = document.getElementById('disposal-cancel');
            
            disposalClose.addEventListener('click', () => disposalModal.classList.remove('active'));
            disposalCancel.addEventListener('click', () => disposalModal.classList.remove('active'));
            
            // Close modals when clicking outside
            document.querySelectorAll('.modal-overlay').forEach(overlay => {
                overlay.addEventListener('click', function(e) {
                    if (e.target === this) {
                        this.classList.remove('active');
                    }
                });
            });
        }
        
        function showApproveModal(requestId) {
            document.getElementById('approve-request-id').value = requestId;
            document.getElementById('approve-modal').classList.add('active');
            
            // Set default date to tomorrow
            const tomorrow = new Date();
            tomorrow.setDate(tomorrow.getDate() + 1);
            document.querySelector('#approve-modal input[name="scheduled_date"]').valueAsDate = tomorrow;
        }
        
        function showRejectModal(requestId) {
            document.getElementById('reject-request-id').value = requestId;
            document.getElementById('reject-modal').classList.add('active');
        }
        
        function showCompleteModal(requestId) {
            document.getElementById('complete-request-id').value = requestId;
            document.getElementById('complete-modal').classList.add('active');
            
            // Set default date to today
            document.querySelector('#complete-modal input[name="service_date"]').valueAsDate = new Date();
            
            // Set default performer to current user
            document.querySelector('#complete-modal input[name="performed_by"]').value = '<?php echo $full_name; ?>';
        }
        
        function showInspectionModal(resourceId = null) {
            const modal = document.getElementById('inspection-modal');
            if (resourceId) {
                document.getElementById('inspection-resource-id').value = resourceId;
            }
            modal.classList.add('active');
            
            // Set default date to today
            document.querySelector('#inspection-modal input[name="inspection_date"]').valueAsDate = new Date();
            
            // Set default inspector to current user
            document.querySelector('#inspection-modal input[name="inspector"]').value = '<?php echo $full_name; ?>';
            
            // Set next inspection date to 90 days from now
            const nextDate = new Date();
            nextDate.setDate(nextDate.getDate() + 90);
            document.querySelector('#inspection-modal input[name="next_inspection_date"]').valueAsDate = nextDate;
        }
        
        function recordInspection(resourceId) {
            showInspectionModal(resourceId);
        }
        
        function showDisposalModal(resourceId) {
            document.getElementById('disposal-resource-id').value = resourceId;
            document.getElementById('disposal-modal').classList.add('active');
            
            // Set default date to today
            document.querySelector('#disposal-modal input[name="disposal_date"]').valueAsDate = new Date();
        }
        
        function switchTab(tabName) {
            // Update tabs
            document.querySelectorAll('.tab').forEach(tab => {
                tab.classList.remove('active');
            });
            event.target.classList.add('active');
            
            // Update tab content
            document.querySelectorAll('.tab-content').forEach(content => {
                content.classList.remove('active');
            });
            document.getElementById(tabName + '-tab').classList.add('active');
        }
        
        function setStatusFilter(status) {
            document.getElementById('status-filter').value = status;
            applyFilters();
        }
        
        function applyFilters() {
            const status = document.getElementById('status-filter').value;
            const type = document.getElementById('type-filter').value;
            const priority = document.getElementById('priority-filter').value;
            const resource = document.getElementById('resource-filter').value;
            
            let url = 'approve_maintenance.php?';
            if (status !== 'all') url += `status=${status}&`;
            if (type !== 'all') url += `type=${type}&`;
            if (priority !== 'all') url += `priority=${priority}&`;
            if (resource !== 'all') url += `resource=${resource}`;
            
            window.location.href = url;
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
        
        updateTime();
        setInterval(updateTime, 1000);
    </script>
</body>
</html>