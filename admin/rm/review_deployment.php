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

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['review_deployment'])) {
        $dispatch_id = $_POST['dispatch_id'];
        $review_status = $_POST['review_status'];
        $review_notes = $_POST['review_notes'];
        $action = $_POST['action'];
        
        if ($action === 'approve') {
            // Update dispatch status
            $update_query = "
                UPDATE dispatch_incidents 
                SET status = 'approved',
                    status_updated_at = NOW(),
                    er_notes = CONCAT(IFNULL(er_notes, ''), '\n\n[Admin Review] Approved: ', ?)
                WHERE id = ?
            ";
            
            $stmt = $pdo->prepare($update_query);
            $stmt->execute([$review_notes, $dispatch_id]);
            
            // Update incident status to processing
            $incident_query = "SELECT incident_id FROM dispatch_incidents WHERE id = ?";
            $incident_stmt = $pdo->prepare($incident_query);
            $incident_stmt->execute([$dispatch_id]);
            $dispatch = $incident_stmt->fetch();
            
            if ($dispatch) {
                $update_incident = "
                    UPDATE api_incidents 
                    SET status = 'processing',
                        dispatch_status = 'processing',
                        updated_at = NOW()
                    WHERE id = ?
                ";
                
                $update_stmt = $pdo->prepare($update_incident);
                $update_stmt->execute([$dispatch['incident_id']]);
            }
            
            $_SESSION['success_message'] = "Deployment approved successfully!";
            
        } elseif ($action === 'modify') {
            // Store modification request
            $modify_query = "
                UPDATE dispatch_incidents 
                SET status = 'modification_required',
                    status_updated_at = NOW(),
                    er_notes = CONCAT(IFNULL(er_notes, ''), '\n\n[Admin Review] Modifications Required: ', ?)
                WHERE id = ?
            ";
            
            $stmt = $pdo->prepare($modify_query);
            $stmt->execute([$review_notes, $dispatch_id]);
            
            $_SESSION['success_message'] = "Deployment modifications requested!";
            
        } elseif ($action === 'reject') {
            // Reject deployment
            $reject_query = "
                UPDATE dispatch_incidents 
                SET status = 'rejected',
                    status_updated_at = NOW(),
                    er_notes = CONCAT(IFNULL(er_notes, ''), '\n\n[Admin Review] Rejected: ', ?)
                WHERE id = ?
            ";
            
            $stmt = $pdo->prepare($reject_query);
            $stmt->execute([$review_notes, $dispatch_id]);
            
            // Update incident status back to pending
            $incident_query = "SELECT incident_id FROM dispatch_incidents WHERE id = ?";
            $incident_stmt = $pdo->prepare($incident_query);
            $incident_stmt->execute([$dispatch_id]);
            $dispatch = $incident_stmt->fetch();
            
            if ($dispatch) {
                $update_incident = "
                    UPDATE api_incidents 
                    SET status = 'pending',
                        dispatch_status = 'for_dispatch',
                        dispatch_id = NULL,
                        updated_at = NOW()
                    WHERE id = ?
                ";
                
                $update_stmt = $pdo->prepare($update_incident);
                $update_stmt->execute([$dispatch['incident_id']]);
            }
            
            $_SESSION['success_message'] = "Deployment rejected!";
        }
        
    } elseif (isset($_POST['dispatch_unit'])) {
        $dispatch_id = $_POST['dispatch_id'];
        $unit_id = $_POST['unit_id'];
        $vehicles_json = $_POST['vehicles_json'];
        $dispatch_notes = $_POST['dispatch_notes'];
        
        // Update dispatch with unit assignment
        $update_query = "
            UPDATE dispatch_incidents 
            SET unit_id = ?,
                vehicles_json = ?,
                status = 'dispatched',
                status_updated_at = NOW(),
                er_notes = CONCAT(IFNULL(er_notes, ''), '\n\n[Dispatched] Unit Assigned: ', ?)
            WHERE id = ?
        ";
        
        $stmt = $pdo->prepare($update_query);
        $stmt->execute([$unit_id, $vehicles_json, $dispatch_notes, $dispatch_id]);
        
        // Update unit status
        $unit_update = "
            UPDATE units 
            SET current_status = 'dispatched',
                current_dispatch_id = ?,
                last_status_change = NOW()
            WHERE id = ?
        ";
        
        $unit_stmt = $pdo->prepare($unit_update);
        $unit_stmt->execute([$dispatch_id, $unit_id]);
        
        // Update incident status
        $incident_query = "SELECT incident_id FROM dispatch_incidents WHERE id = ?";
        $incident_stmt = $pdo->prepare($incident_query);
        $incident_stmt->execute([$dispatch_id]);
        $dispatch = $incident_stmt->fetch();
        
        if ($dispatch) {
            $update_incident = "
                UPDATE api_incidents 
                SET dispatch_status = 'dispatched',
                    status = 'processing',
                    updated_at = NOW()
                WHERE id = ?
            ";
            
            $update_stmt = $pdo->prepare($update_incident);
            $update_stmt->execute([$dispatch['incident_id']]);
        }
        
        $_SESSION['success_message'] = "Unit dispatched successfully!";
        
    } elseif (isset($_POST['update_status'])) {
        $dispatch_id = $_POST['dispatch_id'];
        $new_status = $_POST['new_status'];
        $status_notes = $_POST['status_notes'];
        
        // Update dispatch status
        $update_query = "
            UPDATE dispatch_incidents 
            SET status = ?,
                status_updated_at = NOW(),
                er_notes = CONCAT(IFNULL(er_notes, ''), '\n\n[Status Update] ', ?, ' - ', ?)
            WHERE id = ?
        ";
        
        $stmt = $pdo->prepare($update_query);
        $stmt->execute([$new_status, $new_status, $status_notes, $dispatch_id]);
        
        // Update incident status based on dispatch status
        $incident_query = "SELECT incident_id FROM dispatch_incidents WHERE id = ?";
        $incident_stmt = $pdo->prepare($incident_query);
        $incident_stmt->execute([$dispatch_id]);
        $dispatch = $incident_stmt->fetch();
        
        if ($dispatch) {
            $incident_status = 'processing';
            $dispatch_status = 'dispatched';
            
            switch ($new_status) {
                case 'en_route':
                    $incident_status = 'processing';
                    $dispatch_status = 'processing';
                    break;
                case 'arrived':
                    $incident_status = 'processing';
                    $dispatch_status = 'processing';
                    break;
                case 'completed':
                    $incident_status = 'responded';
                    $dispatch_status = 'responded';
                    
                    // Free up the unit
                    $unit_query = "SELECT unit_id FROM dispatch_incidents WHERE id = ?";
                    $unit_stmt = $pdo->prepare($unit_query);
                    $unit_stmt->execute([$dispatch_id]);
                    $dispatch_info = $unit_stmt->fetch();
                    
                    if ($dispatch_info && $dispatch_info['unit_id']) {
                        $free_unit = "
                            UPDATE units 
                            SET current_status = 'available',
                                current_dispatch_id = NULL,
                                last_status_change = NOW()
                            WHERE id = ?
                        ";
                        
                        $free_stmt = $pdo->prepare($free_unit);
                        $free_stmt->execute([$dispatch_info['unit_id']]);
                    }
                    break;
                case 'cancelled':
                    $incident_status = 'pending';
                    $dispatch_status = 'for_dispatch';
                    
                    // Free up the unit
                    $unit_query = "SELECT unit_id FROM dispatch_incidents WHERE id = ?";
                    $unit_stmt = $pdo->prepare($unit_query);
                    $unit_stmt->execute([$dispatch_id]);
                    $dispatch_info = $unit_stmt->fetch();
                    
                    if ($dispatch_info && $dispatch_info['unit_id']) {
                        $free_unit = "
                            UPDATE units 
                            SET current_status = 'available',
                                current_dispatch_id = NULL,
                                last_status_change = NOW()
                            WHERE id = ?
                        ";
                        
                        $free_stmt = $pdo->prepare($free_unit);
                        $free_stmt->execute([$dispatch_info['unit_id']]);
                    }
                    break;
            }
            
            $update_incident = "
                UPDATE api_incidents 
                SET status = ?,
                    dispatch_status = ?,
                    updated_at = NOW()
                WHERE id = ?
            ";
            
            $update_stmt = $pdo->prepare($update_incident);
            $update_stmt->execute([$incident_status, $dispatch_status, $dispatch['incident_id']]);
        }
        
        $_SESSION['success_message'] = "Status updated successfully!";
    }
    
    // Redirect to prevent form resubmission
    header("Location: review_deployment.php");
    exit();
}

// Get filter parameters
$status_filter = isset($_GET['status']) ? $_GET['status'] : 'pending_review';
$incident_type_filter = isset($_GET['incident_type']) ? $_GET['incident_type'] : 'all';
$priority_filter = isset($_GET['priority']) ? $_GET['priority'] : 'all';
$date_filter = isset($_GET['date']) ? $_GET['date'] : '';

// Build query for dispatch incidents
$where_conditions = [];
$params = [];

if ($status_filter !== 'all') {
    $where_conditions[] = "di.status = ?";
    $params[] = $status_filter;
}

if ($incident_type_filter !== 'all') {
    $where_conditions[] = "ai.emergency_type = ?";
    $params[] = $incident_type_filter;
}

if ($priority_filter !== 'all') {
    $where_conditions[] = "ai.severity = ?";
    $params[] = $priority_filter;
}

if ($date_filter) {
    $where_conditions[] = "DATE(di.dispatched_at) = ?";
    $params[] = $date_filter;
}

$where_clause = '';
if (!empty($where_conditions)) {
    $where_clause = "WHERE " . implode(" AND ", $where_conditions);
}

// Fetch dispatch incidents
$dispatch_query = "
    SELECT di.*, 
           ai.id as incident_id, ai.title, ai.emergency_type, ai.severity, 
           ai.location, ai.description, ai.caller_name, ai.caller_phone,
           ai.affected_barangays, ai.created_at as incident_created,
           u.unit_name, u.unit_type, u.current_status as unit_status,
           ud.first_name as dispatcher_first, ud.last_name as dispatcher_last,
           COUNT(vs.id) as vehicle_count
    FROM dispatch_incidents di
    LEFT JOIN api_incidents ai ON di.incident_id = ai.id
    LEFT JOIN units u ON di.unit_id = u.id
    LEFT JOIN users ud ON di.dispatched_by = ud.id
    LEFT JOIN vehicle_status vs ON di.id = vs.dispatch_id
    $where_clause
    GROUP BY di.id
    ORDER BY 
        CASE di.status
            WHEN 'pending' THEN 1
            WHEN 'pending_review' THEN 2
            WHEN 'approved' THEN 3
            WHEN 'dispatched' THEN 4
            WHEN 'en_route' THEN 5
            WHEN 'arrived' THEN 6
            WHEN 'completed' THEN 7
            WHEN 'cancelled' THEN 8
            ELSE 9
        END,
        CASE ai.severity
            WHEN 'critical' THEN 1
            WHEN 'high' THEN 2
            WHEN 'medium' THEN 3
            WHEN 'low' THEN 4
            ELSE 5
        END,
        di.dispatched_at DESC
";

$dispatch_stmt = $pdo->prepare($dispatch_query);
$dispatch_stmt->execute($params);
$dispatch_incidents = $dispatch_stmt->fetchAll();

// Fetch available units for dispatch
$units_query = "
    SELECT u.*, 
           COUNT(vs.id) as available_vehicles,
           GROUP_CONCAT(vs.vehicle_name SEPARATOR ', ') as vehicle_list
    FROM units u
    LEFT JOIN vehicle_status vs ON u.id = vs.unit_id AND vs.status = 'available'
    WHERE u.status = 'Active' 
      AND (u.current_status = 'available' OR u.current_status IS NULL)
    GROUP BY u.id
    ORDER BY u.unit_type, u.unit_name
";

$units_stmt = $pdo->prepare($units_query);
$units_stmt->execute();
$available_units = $units_stmt->fetchAll();

// Fetch all vehicles
$vehicles_query = "
    SELECT vs.*, u.unit_name
    FROM vehicle_status vs
    LEFT JOIN units u ON vs.unit_id = u.id
    WHERE vs.status = 'available'
    ORDER BY vs.vehicle_type, vs.vehicle_name
";

$vehicles_stmt = $pdo->prepare($vehicles_query);
$vehicles_stmt->execute();
$available_vehicles = $vehicles_stmt->fetchAll();

// Get statistics
$stats_query = "
    SELECT 
        COUNT(*) as total_dispatches,
        SUM(CASE WHEN di.status = 'pending_review' THEN 1 ELSE 0 END) as pending_review,
        SUM(CASE WHEN di.status = 'approved' THEN 1 ELSE 0 END) as approved,
        SUM(CASE WHEN di.status = 'dispatched' THEN 1 ELSE 0 END) as dispatched,
        SUM(CASE WHEN di.status = 'en_route' THEN 1 ELSE 0 END) as en_route,
        SUM(CASE WHEN di.status = 'arrived' THEN 1 ELSE 0 END) as arrived,
        SUM(CASE WHEN di.status = 'completed' THEN 1 ELSE 0 END) as completed,
        SUM(CASE WHEN di.status IN ('dispatched', 'en_route', 'arrived') THEN 1 ELSE 0 END) as active_incidents,
        SUM(CASE WHEN ai.severity = 'critical' THEN 1 ELSE 0 END) as critical,
        SUM(CASE WHEN ai.severity = 'high' THEN 1 ELSE 0 END) as high,
        SUM(CASE WHEN ai.severity = 'medium' THEN 1 ELSE 0 END) as medium
    FROM dispatch_incidents di
    LEFT JOIN api_incidents ai ON di.incident_id = ai.id
    WHERE di.status != 'cancelled'
";

$stats_stmt = $pdo->prepare($stats_query);
$stats_stmt->execute();
$stats = $stats_stmt->fetch();

// Get recent activity
$activity_query = "
    SELECT di.*, ai.title, ai.emergency_type,
           u.unit_name, ud.first_name, ud.last_name,
           DATE_FORMAT(di.status_updated_at, '%Y-%m-%d %H:%i:%s') as status_time
    FROM dispatch_incidents di
    LEFT JOIN api_incidents ai ON di.incident_id = ai.id
    LEFT JOIN units u ON di.unit_id = u.id
    LEFT JOIN users ud ON di.dispatched_by = ud.id
    WHERE di.status_updated_at IS NOT NULL
    ORDER BY di.status_updated_at DESC
    LIMIT 10
";

$activity_stmt = $pdo->prepare($activity_query);
$activity_stmt->execute();
$recent_activity = $activity_stmt->fetchAll();

// Get incident types for filter
$types_query = "
    SELECT DISTINCT emergency_type 
    FROM api_incidents 
    WHERE emergency_type IS NOT NULL AND emergency_type != ''
    ORDER BY emergency_type
";

$types_stmt = $pdo->prepare($types_query);
$types_stmt->execute();
$incident_types = $types_stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Review Deployment - Fire & Rescue Services</title>
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
            --purple: #8b5cf6;
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
        
        .deployment-container {
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
        
        .filter-select, .filter-input {
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
        
        .deployments-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(400px, 1fr));
            gap: 20px;
            margin-bottom: 40px;
        }
        
        .deployment-card {
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: 16px;
            overflow: hidden;
            transition: all 0.3s ease;
        }
        
        .deployment-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.15);
        }
        
        .deployment-header {
            padding: 20px;
            border-bottom: 1px solid var(--border-color);
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        
        .deployment-title {
            font-size: 18px;
            font-weight: 700;
            margin-bottom: 4px;
        }
        
        .deployment-type {
            font-size: 12px;
            padding: 4px 8px;
            border-radius: 4px;
            display: inline-block;
        }
        
        .deployment-body {
            padding: 20px;
        }
        
        .deployment-info {
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
            max-width: 60%;
            word-break: break-word;
        }
        
        .deployment-footer {
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
        
        .status-pending_review {
            background: rgba(245, 158, 11, 0.1);
            color: var(--warning);
        }
        
        .status-approved {
            background: rgba(59, 130, 246, 0.1);
            color: var(--info);
        }
        
        .status-dispatched {
            background: rgba(139, 92, 246, 0.1);
            color: var(--purple);
        }
        
        .status-en_route {
            background: rgba(16, 185, 129, 0.1);
            color: var(--success);
        }
        
        .status-arrived {
            background: rgba(16, 185, 129, 0.2);
            color: var(--success);
        }
        
        .status-completed {
            background: rgba(16, 185, 129, 0.3);
            color: var(--success);
        }
        
        .status-cancelled {
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
        
        .review-button {
            background: var(--info);
            color: white;
        }
        
        .dispatch-button {
            background: var(--success);
            color: white;
        }
        
        .update-button {
            background: var(--warning);
            color: white;
        }
        
        .view-button {
            background: var(--purple);
            color: white;
        }
        
        .activity-table {
            width: 100%;
            border-collapse: collapse;
            background: var(--card-bg);
            border-radius: 16px;
            overflow: hidden;
            border: 1px solid var(--border-color);
        }
        
        .activity-table th {
            padding: 16px;
            text-align: left;
            background: var(--background-color);
            font-weight: 600;
            border-bottom: 1px solid var(--border-color);
        }
        
        .activity-table td {
            padding: 16px;
            border-bottom: 1px solid var(--border-color);
        }
        
        .activity-table tr:last-child td {
            border-bottom: none;
        }
        
        .units-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 20px;
            margin-bottom: 40px;
        }
        
        .unit-card {
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: 16px;
            padding: 20px;
            transition: all 0.3s ease;
        }
        
        .unit-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.15);
        }
        
        .unit-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 16px;
        }
        
        .unit-name {
            font-size: 18px;
            font-weight: 700;
        }
        
        .unit-status {
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
        }
        
        .unit-available {
            background: rgba(16, 185, 129, 0.1);
            color: var(--success);
        }
        
        .unit-dispatched {
            background: rgba(245, 158, 11, 0.1);
            color: var(--warning);
        }
        
        .unit-details {
            margin-bottom: 16px;
        }
        
        .detail-item {
            display: flex;
            justify-content: space-between;
            margin-bottom: 8px;
        }
        
        .detail-label {
            color: var(--text-light);
            font-size: 13px;
        }
        
        .detail-value {
            font-weight: 600;
        }
        
        .vehicle-list {
            margin-top: 16px;
            padding-top: 16px;
            border-top: 1px solid var(--border-color);
        }
        
        .vehicle-item {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 8px 0;
            border-bottom: 1px solid var(--border-color);
        }
        
        .vehicle-item:last-child {
            border-bottom: none;
        }
        
        .vehicle-name {
            font-weight: 500;
        }
        
        .vehicle-type {
            font-size: 11px;
            padding: 2px 6px;
            border-radius: 3px;
            background: var(--background-color);
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
        
        .form-input, .form-textarea, .form-select {
            width: 100%;
            padding: 12px;
            border: 1px solid var(--border-color);
            border-radius: 8px;
            background: var(--card-bg);
            color: var(--text-color);
            font-size: 14px;
        }
        
        .form-textarea {
            resize: vertical;
            min-height: 100px;
        }
        
        .form-input:focus, .form-textarea:focus, .form-select:focus {
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
        
        .modal-button {
            padding: 10px 20px;
            border-radius: 10px;
            border: none;
            cursor: pointer;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .modal-primary {
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            color: white;
        }
        
        .modal-secondary {
            background: var(--background-color);
            color: var(--text-color);
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
        
        .vehicle-selection {
            margin-top: 16px;
            max-height: 200px;
            overflow-y: auto;
            border: 1px solid var(--border-color);
            border-radius: 8px;
            padding: 12px;
        }
        
        .vehicle-option {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 8px;
            border-bottom: 1px solid var(--border-color);
        }
        
        .vehicle-option:last-child {
            border-bottom: none;
        }
        
        .vehicle-checkbox {
            margin-right: 12px;
        }
        
        .loading-overlay {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.7);
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            z-index: 2000;
            opacity: 0;
            visibility: hidden;
            transition: all 0.3s ease;
        }
        
        .loading-overlay.active {
            opacity: 1;
            visibility: visible;
        }
        
        .loading-spinner {
            width: 50px;
            height: 50px;
            border: 4px solid var(--border-color);
            border-top-color: var(--primary-color);
            border-radius: 50%;
            animation: spin 1s linear infinite;
            margin-bottom: 20px;
        }
        
        @keyframes spin {
            to { transform: rotate(360deg); }
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
        <div class="animation-text" id="animation-text">Loading Deployment Review System...</div>
    </div>
    
    <!-- Loading Overlay -->
    <div class="loading-overlay" id="loading-overlay">
        <div class="loading-spinner"></div>
        <div class="loading-text" id="loading-text">Processing...</div>
    </div>
    
    <!-- Review Deployment Modal -->
    <div class="modal-overlay" id="review-modal">
        <div class="modal">
            <div class="modal-header">
                <h2 class="modal-title">Review Deployment Plan</h2>
                <button class="modal-close" id="review-close">&times;</button>
            </div>
            <form method="POST" action="review_deployment.php" id="review-form">
                <div class="modal-body">
                    <input type="hidden" name="dispatch_id" id="review-dispatch-id">
                    <div class="modal-section">
                        <h3 class="modal-section-title">Deployment Details</h3>
                        <div id="review-details">
                            <!-- Details will be loaded via JavaScript -->
                        </div>
                    </div>
                    <div class="modal-section">
                        <h3 class="modal-section-title">Review Action</h3>
                        <div class="form-group">
                            <label class="form-label">Select Action</label>
                            <div style="display: flex; gap: 12px; margin-bottom: 16px;">
                                <label style="display: flex; align-items: center; gap: 8px; cursor: pointer;">
                                    <input type="radio" name="action" value="approve" checked>
                                    <span>Approve Deployment</span>
                                </label>
                                <label style="display: flex; align-items: center; gap: 8px; cursor: pointer;">
                                    <input type="radio" name="action" value="modify">
                                    <span>Request Modifications</span>
                                </label>
                                <label style="display: flex; align-items: center; gap: 8px; cursor: pointer;">
                                    <input type="radio" name="action" value="reject">
                                    <span>Reject Deployment</span>
                                </label>
                            </div>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Review Notes</label>
                            <textarea class="form-textarea" name="review_notes" placeholder="Provide details about your review decision..." required></textarea>
                        </div>
                        <input type="hidden" name="review_status" value="reviewed">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="modal-button modal-secondary" id="review-cancel">Cancel</button>
                    <button type="submit" class="modal-button modal-primary" name="review_deployment">
                        <i class='bx bx-check'></i>
                        Submit Review
                    </button>
                </div>
            </form>
        </div>
    </div>
    
    <!-- Dispatch Unit Modal -->
    <div class="modal-overlay" id="dispatch-modal">
        <div class="modal">
            <div class="modal-header">
                <h2 class="modal-title">Dispatch Unit</h2>
                <button class="modal-close" id="dispatch-close">&times;</button>
            </div>
            <form method="POST" action="review_deployment.php" id="dispatch-form">
                <div class="modal-body">
                    <input type="hidden" name="dispatch_id" id="dispatch-dispatch-id">
                    <div class="modal-section">
                        <h3 class="modal-section-title">Incident Details</h3>
                        <div id="dispatch-incident-details">
                            <!-- Details will be loaded via JavaScript -->
                        </div>
                    </div>
                    <div class="modal-section">
                        <h3 class="modal-section-title">Unit Assignment</h3>
                        <div class="form-group">
                            <label class="form-label">Select Unit</label>
                            <select class="form-select" name="unit_id" id="unit-select" required>
                                <option value="">Select a unit...</option>
                                <?php foreach ($available_units as $unit): ?>
                                    <option value="<?php echo $unit['id']; ?>" data-vehicles="<?php echo htmlspecialchars($unit['vehicle_list'] ?? ''); ?>">
                                        <?php echo htmlspecialchars($unit['unit_name'] . ' (' . $unit['unit_type'] . ')'); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Available Vehicles</label>
                            <div class="vehicle-selection" id="vehicle-selection">
                                <!-- Vehicles will be loaded based on unit selection -->
                                <div style="color: var(--text-light); text-align: center; padding: 20px;">
                                    Select a unit to view available vehicles
                                </div>
                            </div>
                            <input type="hidden" name="vehicles_json" id="vehicles-json">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Dispatch Notes</label>
                            <textarea class="form-textarea" name="dispatch_notes" placeholder="Add any dispatch instructions or notes..."></textarea>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="modal-button modal-secondary" id="dispatch-cancel">Cancel</button>
                    <button type="submit" class="modal-button modal-primary" name="dispatch_unit">
                        <i class='bx bx-paper-plane'></i>
                        Dispatch Unit
                    </button>
                </div>
            </form>
        </div>
    </div>
    
    <!-- Update Status Modal -->
    <div class="modal-overlay" id="status-modal">
        <div class="modal">
            <div class="modal-header">
                <h2 class="modal-title">Update Deployment Status</h2>
                <button class="modal-close" id="status-close">&times;</button>
            </div>
            <form method="POST" action="review_deployment.php" id="status-form">
                <div class="modal-body">
                    <input type="hidden" name="dispatch_id" id="status-dispatch-id">
                    <div class="modal-section">
                        <h3 class="modal-section-title">Current Status</h3>
                        <div id="current-status-details">
                            <!-- Details will be loaded via JavaScript -->
                        </div>
                    </div>
                    <div class="modal-section">
                        <h3 class="modal-section-title">Update Status</h3>
                        <div class="form-group">
                            <label class="form-label">New Status</label>
                            <select class="form-select" name="new_status" required>
                                <option value="dispatched">Dispatched</option>
                                <option value="en_route">En Route</option>
                                <option value="arrived">Arrived at Scene</option>
                                <option value="completed">Completed</option>
                                <option value="cancelled">Cancelled</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Status Notes</label>
                            <textarea class="form-textarea" name="status_notes" placeholder="Provide details about the status update..." required></textarea>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="modal-button modal-secondary" id="status-cancel">Cancel</button>
                    <button type="submit" class="modal-button modal-primary" name="update_status">
                        <i class='bx bx-update'></i>
                        Update Status
                    </button>
                </div>
            </form>
        </div>
    </div>
    
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
                        <a href="approve_maintenance.php" class="submenu-item">Approve Maintenance</a>
                         <a href="review_deployment.php" class="submenu-item active">Review Deployment</a>
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
            <!-- Header -->
            <div class="header">
                <div class="header-content">
                    <div class="search-container">
                        <div class="search-box">
                            <svg class="search-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path>
                            </svg>
                            <input type="text" placeholder="Search deployments..." class="search-input" id="search-input">
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
                        <h1 class="dashboard-title">Deployment Review System</h1>
                        <p class="dashboard-subtitle">Review, approve, and monitor emergency response deployments</p>
                    </div>
                    <div class="dashboard-actions">
                        <button class="primary-button" onclick="refreshPage()">
                            <i class='bx bx-refresh'></i>
                            Refresh
                        </button>
                    </div>
                </div>
                
                <!-- Deployment Section -->
                <div class="deployment-container">
                    <!-- Stats Cards -->
                    <div class="stats-container">
                        <div class="stat-card" onclick="setStatusFilter('pending_review')">
                            <div class="stat-icon" style="background: rgba(245, 158, 11, 0.1); color: var(--warning);">
                                <i class='bx bx-time-five'></i>
                            </div>
                            <div class="stat-content">
                                <div class="stat-value"><?php echo $stats['pending_review'] ?? 0; ?></div>
                                <div class="stat-label">Pending Review</div>
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
                        <div class="stat-card" onclick="setStatusFilter('dispatched')">
                            <div class="stat-icon" style="background: rgba(139, 92, 246, 0.1); color: var(--purple);">
                                <i class='bx bx-paper-plane'></i>
                            </div>
                            <div class="stat-content">
                                <div class="stat-value"><?php echo $stats['dispatched'] ?? 0; ?></div>
                                <div class="stat-label">Dispatched</div>
                            </div>
                        </div>
                        <div class="stat-card" onclick="setStatusFilter('active_incidents')">
                            <div class="stat-icon" style="background: rgba(220, 38, 38, 0.1); color: var(--danger);">
                                <i class='bx bx-alarm-exclamation'></i>
                            </div>
                            <div class="stat-content">
                                <div class="stat-value"><?php echo $stats['active_incidents'] ?? 0; ?></div>
                                <div class="stat-label">Active Incidents</div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Filters -->
                    <div class="filters-container">
                        <div class="filter-group">
                            <label class="filter-label">Status</label>
                            <select class="filter-select" id="status-filter" onchange="applyFilters()">
                                <option value="pending_review" <?php echo $status_filter === 'pending_review' ? 'selected' : ''; ?>>Pending Review</option>
                                <option value="approved" <?php echo $status_filter === 'approved' ? 'selected' : ''; ?>>Approved</option>
                                <option value="dispatched" <?php echo $status_filter === 'dispatched' ? 'selected' : ''; ?>>Dispatched</option>
                                <option value="en_route" <?php echo $status_filter === 'en_route' ? 'selected' : ''; ?>>En Route</option>
                                <option value="arrived" <?php echo $status_filter === 'arrived' ? 'selected' : ''; ?>>Arrived</option>
                                <option value="completed" <?php echo $status_filter === 'completed' ? 'selected' : ''; ?>>Completed</option>
                                <option value="all" <?php echo $status_filter === 'all' ? 'selected' : ''; ?>>All Statuses</option>
                            </select>
                        </div>
                        <div class="filter-group">
                            <label class="filter-label">Incident Type</label>
                            <select class="filter-select" id="incident-type-filter" onchange="applyFilters()">
                                <option value="all" <?php echo $incident_type_filter === 'all' ? 'selected' : ''; ?>>All Types</option>
                                <?php foreach ($incident_types as $type): ?>
                                    <option value="<?php echo htmlspecialchars($type['emergency_type']); ?>" <?php echo $incident_type_filter === $type['emergency_type'] ? 'selected' : ''; ?>>
                                        <?php echo ucfirst(htmlspecialchars($type['emergency_type'])); ?>
                                    </option>
                                <?php endforeach; ?>
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
                            <label class="filter-label">Date</label>
                            <input type="date" class="filter-input" id="date-filter" value="<?php echo htmlspecialchars($date_filter); ?>" onchange="applyFilters()">
                        </div>
                    </div>
                    
                    <!-- Tabs -->
                    <div class="tabs">
                        <button class="tab active" onclick="switchTab('deployments')">Deployments</button>
                        <button class="tab" onclick="switchTab('units')">Available Units</button>
                        <button class="tab" onclick="switchTab('activity')">Recent Activity</button>
                    </div>
                    
                    <!-- Deployments Tab -->
                    <div id="deployments-tab" class="tab-content active">
                        <?php if (count($dispatch_incidents) > 0): ?>
                            <div class="deployments-grid">
                                <?php foreach ($dispatch_incidents as $dispatch): 
                                    $status_class = 'status-' . str_replace(' ', '_', $dispatch['status']);
                                    $priority_class = 'priority-' . $dispatch['severity'];
                                    $dispatcher_name = $dispatch['dispatcher_first'] ? $dispatch['dispatcher_first'] . ' ' . $dispatch['dispatcher_last'] : 'System';
                                ?>
                                    <div class="deployment-card">
                                        <div class="deployment-header">
                                            <div>
                                                <div class="deployment-title"><?php echo htmlspecialchars($dispatch['title'] ?: 'Emergency Response'); ?></div>
                                                <span class="deployment-type"><?php echo ucfirst(htmlspecialchars($dispatch['emergency_type'])); ?></span>
                                            </div>
                                            <div class="status-badge <?php echo $status_class; ?>">
                                                <?php echo ucfirst(str_replace('_', ' ', $dispatch['status'])); ?>
                                            </div>
                                        </div>
                                        
                                        <div class="deployment-body">
                                            <div class="deployment-info">
                                                <div class="info-item">
                                                    <span class="info-label">Location:</span>
                                                    <span class="info-value"><?php echo htmlspecialchars($dispatch['location']); ?></span>
                                                </div>
                                                <div class="info-item">
                                                    <span class="info-label">Caller:</span>
                                                    <span class="info-value"><?php echo htmlspecialchars($dispatch['caller_name']); ?></span>
                                                </div>
                                                <div class="info-item">
                                                    <span class="info-label">Priority:</span>
                                                    <span class="info-value">
                                                        <span class="priority-badge <?php echo $priority_class; ?>">
                                                            <?php echo ucfirst($dispatch['severity']); ?>
                                                        </span>
                                                    </span>
                                                </div>
                                                <?php if ($dispatch['unit_name']): ?>
                                                    <div class="info-item">
                                                        <span class="info-label">Assigned Unit:</span>
                                                        <span class="info-value"><?php echo htmlspecialchars($dispatch['unit_name']); ?></span>
                                                    </div>
                                                <?php endif; ?>
                                                <?php if ($dispatch['dispatched_at']): ?>
                                                    <div class="info-item">
                                                        <span class="info-label">Dispatched:</span>
                                                        <span class="info-value"><?php echo date('M d, Y H:i', strtotime($dispatch['dispatched_at'])); ?></span>
                                                    </div>
                                                <?php endif; ?>
                                                <?php if ($dispatch['vehicle_count'] > 0): ?>
                                                    <div class="info-item">
                                                        <span class="info-label">Vehicles:</span>
                                                        <span class="info-value"><?php echo $dispatch['vehicle_count']; ?> assigned</span>
                                                    </div>
                                                <?php endif; ?>
                                            </div>
                                            
                                            <div style="margin-top: 16px;">
                                                <div class="info-label">Description:</div>
                                                <p style="margin-top: 4px; font-size: 14px; max-height: 60px; overflow: hidden; text-overflow: ellipsis;">
                                                    <?php echo htmlspecialchars($dispatch['description']); ?>
                                                </p>
                                            </div>
                                        </div>
                                        
                                        <div class="deployment-footer">
                                            <div style="font-size: 12px; color: var(--text-light);">
                                                ID: DISP-<?php echo str_pad($dispatch['id'], 6, '0', STR_PAD_LEFT); ?>
                                            </div>
                                            <div class="action-buttons">
                                                <?php if ($dispatch['status'] === 'pending' || $dispatch['status'] === 'pending_review'): ?>
                                                    <button class="action-button review-button" onclick="showReviewModal(<?php echo $dispatch['id']; ?>, '<?php echo addslashes($dispatch['title']); ?>')">
                                                        <i class='bx bx-check'></i>
                                                        Review
                                                    </button>
                                                <?php elseif ($dispatch['status'] === 'approved'): ?>
                                                    <button class="action-button dispatch-button" onclick="showDispatchModal(<?php echo $dispatch['id']; ?>, '<?php echo addslashes($dispatch['title']); ?>')">
                                                        <i class='bx bx-paper-plane'></i>
                                                        Dispatch
                                                    </button>
                                                <?php elseif (in_array($dispatch['status'], ['dispatched', 'en_route', 'arrived'])): ?>
                                                    <button class="action-button update-button" onclick="showStatusModal(<?php echo $dispatch['id']; ?>, '<?php echo $dispatch['status']; ?>')">
                                                        <i class='bx bx-update'></i>
                                                        Update Status
                                                    </button>
                                                <?php endif; ?>
                                                <button class="action-button view-button" onclick="viewDeploymentDetails(<?php echo $dispatch['id']; ?>)">
                                                    <i class='bx bx-show'></i>
                                                    View
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <div class="empty-state">
                                <div class="empty-state-icon">
                                    <i class='bx bx-paper-plane'></i>
                                </div>
                                <h3>No Deployments Found</h3>
                                <p>No deployments match your current filters. Try adjusting your filter criteria.</p>
                            </div>
                        <?php endif; ?>
                    </div>
                    
                    <!-- Units Tab -->
                    <div id="units-tab" class="tab-content">
                        <?php if (count($available_units) > 0): ?>
                            <div class="units-grid">
                                <?php foreach ($available_units as $unit): 
                                    $status_class = $unit['current_status'] === 'dispatched' ? 'unit-dispatched' : 'unit-available';
                                    $status_text = $unit['current_status'] === 'dispatched' ? 'Dispatched' : 'Available';
                                ?>
                                    <div class="unit-card">
                                        <div class="unit-header">
                                            <div class="unit-name"><?php echo htmlspecialchars($unit['unit_name']); ?></div>
                                            <div class="unit-status <?php echo $status_class; ?>"><?php echo $status_text; ?></div>
                                        </div>
                                        <div class="unit-details">
                                            <div class="detail-item">
                                                <span class="detail-label">Unit Code:</span>
                                                <span class="detail-value"><?php echo htmlspecialchars($unit['unit_code']); ?></span>
                                            </div>
                                            <div class="detail-item">
                                                <span class="detail-label">Type:</span>
                                                <span class="detail-value"><?php echo htmlspecialchars($unit['unit_type']); ?></span>
                                            </div>
                                            <div class="detail-item">
                                                <span class="detail-label">Location:</span>
                                                <span class="detail-value"><?php echo htmlspecialchars($unit['location']); ?></span>
                                            </div>
                                            <div class="detail-item">
                                                <span class="detail-label">Capacity:</span>
                                                <span class="detail-value"><?php echo $unit['current_count']; ?>/<?php echo $unit['capacity']; ?></span>
                                            </div>
                                            <div class="detail-item">
                                                <span class="detail-label">Available Vehicles:</span>
                                                <span class="detail-value"><?php echo $unit['available_vehicles']; ?></span>
                                            </div>
                                        </div>
                                        <?php if ($unit['vehicle_list']): ?>
                                            <div class="vehicle-list">
                                                <div style="font-size: 12px; color: var(--text-light); margin-bottom: 8px;">Vehicle List:</div>
                                                <?php 
                                                $vehicles = explode(', ', $unit['vehicle_list']);
                                                foreach ($vehicles as $vehicle):
                                                ?>
                                                    <div class="vehicle-item">
                                                        <span class="vehicle-name"><?php echo htmlspecialchars($vehicle); ?></span>
                                                        <span class="vehicle-type">Available</span>
                                                    </div>
                                                <?php endforeach; ?>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <div class="empty-state">
                                <div class="empty-state-icon">
                                    <i class='bx bx-car'></i>
                                </div>
                                <h3>No Units Available</h3>
                                <p>All units are currently dispatched or unavailable.</p>
                            </div>
                        <?php endif; ?>
                    </div>
                    
                    <!-- Activity Tab -->
                    <div id="activity-tab" class="tab-content">
                        <?php if (count($recent_activity) > 0): ?>
                            <table class="activity-table">
                                <thead>
                                    <tr>
                                        <th>Time</th>
                                        <th>Incident</th>
                                        <th>Unit</th>
                                        <th>Status</th>
                                        <th>Updated By</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($recent_activity as $activity): ?>
                                        <tr>
                                            <td><?php echo date('M d, H:i', strtotime($activity['status_time'])); ?></td>
                                            <td><?php echo htmlspecialchars($activity['title']); ?></td>
                                            <td><?php echo htmlspecialchars($activity['unit_name'] ?: 'Not Assigned'); ?></td>
                                            <td>
                                                <span class="status-badge status-<?php echo str_replace(' ', '_', strtolower($activity['status'])); ?>">
                                                    <?php echo ucfirst(str_replace('_', ' ', $activity['status'])); ?>
                                                </span>
                                            </td>
                                            <td><?php echo htmlspecialchars($activity['first_name'] . ' ' . $activity['last_name']); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php else: ?>
                            <div class="empty-state">
                                <div class="empty-state-icon">
                                    <i class='bx bx-history'></i>
                                </div>
                                <h3>No Recent Activity</h3>
                                <p>Activity records will appear here as deployments are updated.</p>
                            </div>
                        <?php endif; ?>
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
            
            // Unit selection for dispatch
            const unitSelect = document.getElementById('unit-select');
            if (unitSelect) {
                unitSelect.addEventListener('change', function() {
                    updateVehicleSelection(this.value, this.options[this.selectedIndex].getAttribute('data-vehicles'));
                });
            }
        });
        
        function initModals() {
            // Review Modal
            const reviewModal = document.getElementById('review-modal');
            const reviewClose = document.getElementById('review-close');
            const reviewCancel = document.getElementById('review-cancel');
            
            reviewClose.addEventListener('click', () => reviewModal.classList.remove('active'));
            reviewCancel.addEventListener('click', () => reviewModal.classList.remove('active'));
            
            // Dispatch Modal
            const dispatchModal = document.getElementById('dispatch-modal');
            const dispatchClose = document.getElementById('dispatch-close');
            const dispatchCancel = document.getElementById('dispatch-cancel');
            
            dispatchClose.addEventListener('click', () => dispatchModal.classList.remove('active'));
            dispatchCancel.addEventListener('click', () => dispatchModal.classList.remove('active'));
            
            // Status Modal
            const statusModal = document.getElementById('status-modal');
            const statusClose = document.getElementById('status-close');
            const statusCancel = document.getElementById('status-cancel');
            
            statusClose.addEventListener('click', () => statusModal.classList.remove('active'));
            statusCancel.addEventListener('click', () => statusModal.classList.remove('active'));
            
            // Close modals when clicking outside
            document.querySelectorAll('.modal-overlay').forEach(overlay => {
                overlay.addEventListener('click', function(e) {
                    if (e.target === this) {
                        this.classList.remove('active');
                    }
                });
            });
            
            // Form submission loading
            document.querySelectorAll('form').forEach(form => {
                form.addEventListener('submit', function() {
                    showLoading();
                });
            });
        }
        
        function showReviewModal(dispatchId, title) {
            document.getElementById('review-dispatch-id').value = dispatchId;
            
            // Load deployment details via AJAX
            loadDeploymentDetails(dispatchId, 'review-details');
            
            document.getElementById('review-modal').classList.add('active');
        }
        
        function showDispatchModal(dispatchId, title) {
            document.getElementById('dispatch-dispatch-id').value = dispatchId;
            
            // Load incident details via AJAX
            loadIncidentDetails(dispatchId);
            
            document.getElementById('dispatch-modal').classList.add('active');
        }
        
        function showStatusModal(dispatchId, currentStatus) {
            document.getElementById('status-dispatch-id').value = dispatchId;
            
            // Load current status details
            loadStatusDetails(dispatchId, currentStatus);
            
            document.getElementById('status-modal').classList.add('active');
        }
        
        function loadDeploymentDetails(dispatchId, targetElementId) {
            const target = document.getElementById(targetElementId);
            target.innerHTML = `
                <div style="text-align: center; padding: 20px;">
                    <i class='bx bx-loader-circle bx-spin' style="font-size: 24px; color: var(--primary-color);"></i>
                    <p style="margin-top: 12px; color: var(--text-light);">Loading deployment details...</p>
                </div>
            `;
            
            // Simulate AJAX call - in production, you would fetch from server
            setTimeout(() => {
                target.innerHTML = `
                    <div class="detail-item">
                        <span class="detail-label">Incident:</span>
                        <span class="detail-value">Emergency Response #${dispatchId}</span>
                    </div>
                    <div class="detail-item">
                        <span class="detail-label">Priority:</span>
                        <span class="detail-value"><span class="priority-badge priority-high">High</span></span>
                    </div>
                    <div class="detail-item">
                        <span class="detail-label">Requested Units:</span>
                        <span class="detail-value">Fire Unit, Ambulance</span>
                    </div>
                    <div class="detail-item">
                        <span class="detail-label">Location:</span>
                        <span class="detail-value">Commonwealth Area, QC</span>
                    </div>
                `;
            }, 500);
        }
        
        function loadIncidentDetails(dispatchId) {
            const target = document.getElementById('dispatch-incident-details');
            target.innerHTML = `
                <div style="text-align: center; padding: 20px;">
                    <i class='bx bx-loader-circle bx-spin' style="font-size: 24px; color: var(--primary-color);"></i>
                    <p style="margin-top: 12px; color: var(--text-light);">Loading incident details...</p>
                </div>
            `;
            
            // Simulate AJAX call
            setTimeout(() => {
                target.innerHTML = `
                    <div class="detail-item">
                        <span class="detail-label">Incident Type:</span>
                        <span class="detail-value">Fire Emergency</span>
                    </div>
                    <div class="detail-item">
                        <span class="detail-label">Location:</span>
                        <span class="detail-value">Commonwealth Area, QC</span>
                    </div>
                    <div class="detail-item">
                        <span class="detail-label">Reported By:</span>
                        <span class="detail-value">John Doe (09171234567)</span>
                    </div>
                    <div class="detail-item">
                        <span class="detail-label">Priority:</span>
                        <span class="detail-value"><span class="priority-badge priority-high">High</span></span>
                    </div>
                `;
            }, 500);
        }
        
        function loadStatusDetails(dispatchId, currentStatus) {
            const target = document.getElementById('current-status-details');
            const statusText = currentStatus.replace('_', ' ').toUpperCase();
            
            target.innerHTML = `
                <div class="detail-item">
                    <span class="detail-label">Current Status:</span>
                    <span class="detail-value"><span class="status-badge status-${currentStatus}">${statusText}</span></span>
                </div>
                <div class="detail-item">
                    <span class="detail-label">Dispatch ID:</span>
                    <span class="detail-value">DISP-${dispatchId.toString().padStart(6, '0')}</span>
                </div>
                <div class="detail-item">
                    <span class="detail-label">Last Updated:</span>
                    <span class="detail-value">Just now</span>
                </div>
            `;
            
            // Set appropriate next status options
            const statusSelect = document.querySelector('select[name="new_status"]');
            if (statusSelect) {
                let options = '';
                switch(currentStatus) {
                    case 'approved':
                        options = '<option value="dispatched">Dispatched</option>';
                        break;
                    case 'dispatched':
                        options = '<option value="en_route">En Route</option><option value="cancelled">Cancelled</option>';
                        break;
                    case 'en_route':
                        options = '<option value="arrived">Arrived at Scene</option><option value="cancelled">Cancelled</option>';
                        break;
                    case 'arrived':
                        options = '<option value="completed">Completed</option><option value="cancelled">Cancelled</option>';
                        break;
                    default:
                        options = '<option value="dispatched">Dispatched</option><option value="en_route">En Route</option><option value="arrived">Arrived at Scene</option><option value="completed">Completed</option><option value="cancelled">Cancelled</option>';
                }
                statusSelect.innerHTML = options;
            }
        }
        
        function updateVehicleSelection(unitId, vehicleList) {
            const vehicleSelection = document.getElementById('vehicle-selection');
            const vehiclesJson = document.getElementById('vehicles-json');
            
            if (!unitId) {
                vehicleSelection.innerHTML = '<div style="color: var(--text-light); text-align: center; padding: 20px;">Select a unit to view available vehicles</div>';
                vehiclesJson.value = '';
                return;
            }
            
            // Simulate fetching vehicles for the selected unit
            showLoading();
            
            setTimeout(() => {
                // This would be an AJAX call to fetch vehicles for the unit
                const vehicles = [
                    { id: 1, name: 'Fire Truck 1', type: 'Fire' },
                    { id: 2, name: 'Ambulance 1', type: 'Medical' },
                    { id: 3, name: 'Rescue Truck', type: 'Rescue' }
                ];
                
                let html = '';
                let selectedVehicles = [];
                
                vehicles.forEach(vehicle => {
                    html += `
                        <div class="vehicle-option">
                            <div>
                                <input type="checkbox" class="vehicle-checkbox" id="vehicle-${vehicle.id}" value='${JSON.stringify(vehicle)}' onchange="updateSelectedVehicles()">
                                <label for="vehicle-${vehicle.id}" style="cursor: pointer;">
                                    <strong>${vehicle.name}</strong>
                                    <span style="font-size: 12px; color: var(--text-light); margin-left: 8px;">${vehicle.type}</span>
                                </label>
                            </div>
                            <span class="vehicle-type">Available</span>
                        </div>
                    `;
                });
                
                vehicleSelection.innerHTML = html;
                vehiclesJson.value = JSON.stringify([]);
                hideLoading();
            }, 800);
        }
        
        function updateSelectedVehicles() {
            const checkboxes = document.querySelectorAll('.vehicle-checkbox:checked');
            const vehiclesJson = document.getElementById('vehicles-json');
            const selectedVehicles = [];
            
            checkboxes.forEach(checkbox => {
                try {
                    selectedVehicles.push(JSON.parse(checkbox.value));
                } catch (e) {
                    console.error('Error parsing vehicle data:', e);
                }
            });
            
            vehiclesJson.value = JSON.stringify(selectedVehicles);
        }
        
        function viewDeploymentDetails(dispatchId) {
            // In a real implementation, this would open a detailed view
            alert(`Viewing details for deployment DISP-${dispatchId.toString().padStart(6, '0')}\n\nThis would show complete deployment history, unit details, and incident information.`);
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
            const incidentType = document.getElementById('incident-type-filter').value;
            const priority = document.getElementById('priority-filter').value;
            const date = document.getElementById('date-filter').value;
            
            let url = 'review_deployment.php?';
            if (status !== 'all') url += `status=${status}&`;
            if (incidentType !== 'all') url += `incident_type=${incidentType}&`;
            if (priority !== 'all') url += `priority=${priority}&`;
            if (date) url += `date=${date}`;
            
            window.location.href = url;
        }
        
        function refreshPage() {
            window.location.reload();
        }
        
        function showLoading(text = 'Processing...') {
            document.getElementById('loading-text').textContent = text;
            document.getElementById('loading-overlay').classList.add('active');
        }
        
        function hideLoading() {
            document.getElementById('loading-overlay').classList.remove('active');
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