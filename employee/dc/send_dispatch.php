<?php
// send_dispatch.php
session_start();
require_once '../../config/db_connection.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: ../../login/login.php");
    exit();
}

// Check if user has dispatch coordination access
$user_id = $_SESSION['user_id'];
$query = "SELECT first_name, middle_name, last_name, role, avatar FROM users WHERE id = ?";
$stmt = $pdo->prepare($query);
$stmt->execute([$user_id]);
$user = $stmt->fetch();

if ($user['role'] !== 'ADMIN' && $user['role'] !== 'EMPLOYEE') {
    header("Location: ../employee_dashboard.php");
    exit();
}

$first_name = htmlspecialchars($user['first_name']);
$middle_name = htmlspecialchars($user['middle_name']);
$last_name = htmlspecialchars($user['last_name']);
$role = htmlspecialchars($user['role']);
$avatar = htmlspecialchars($user['avatar']);

$full_name = $first_name;
if (!empty($middle_name)) {
    $full_name .= " " . $middle_name;
}
$full_name .= " " . $last_name;

// Handle update dispatch status
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Handle update dispatch status
    if (isset($_POST['update_status'])) {
        $dispatch_id = $_POST['dispatch_id'];
        $new_status = $_POST['new_status'];
        $notes = $_POST['status_notes'] ?? '';
        
        try {
            $pdo->beginTransaction();
            
            // Update dispatch status
            $update_query = "UPDATE dispatch_incidents SET status = ?, status_updated_at = NOW(), er_notes = CONCAT(IFNULL(er_notes, ''), '\nStatus Update (', NOW(), '): ', ?) WHERE id = ?";
            $stmt = $pdo->prepare($update_query);
            $stmt->execute([$new_status, $notes, $dispatch_id]);
            
            // If status is completed, update related records
            if ($new_status === 'completed') {
                // Get dispatch details
                $get_dispatch = "SELECT di.incident_id, di.unit_id FROM dispatch_incidents di WHERE di.id = ?";
                $stmt = $pdo->prepare($get_dispatch);
                $stmt->execute([$dispatch_id]);
                $dispatch_data = $stmt->fetch();
                
                if ($dispatch_data) {
                    // Update incident status to closed
                    $update_incident = "UPDATE api_incidents SET 
                        dispatch_status = 'closed', 
                        status = 'closed',
                        updated_at = NOW() 
                        WHERE id = ?";
                    $stmt = $pdo->prepare($update_incident);
                    $stmt->execute([$dispatch_data['incident_id']]);
                    
                    // Update unit status back to available
                    $update_unit = "UPDATE units SET 
                        current_status = 'available', 
                        current_dispatch_id = NULL, 
                        last_status_change = NOW() 
                        WHERE id = ?";
                    $stmt = $pdo->prepare($update_unit);
                    $stmt->execute([$dispatch_data['unit_id']]);
                    
                    // Update vehicles back to available
                    $update_vehicle = "UPDATE vehicle_status SET 
                        status = 'available', 
                        dispatch_id = NULL,
                        suggestion_id = NULL,
                        last_updated = NOW() 
                        WHERE dispatch_id = ?";
                    $stmt = $pdo->prepare($update_vehicle);
                    $stmt->execute([$dispatch_id]);
                }
            }
            
            // If status is cancelled, also free resources
            if ($new_status === 'cancelled') {
                // Get dispatch details
                $get_dispatch = "SELECT di.unit_id FROM dispatch_incidents di WHERE di.id = ?";
                $stmt = $pdo->prepare($get_dispatch);
                $stmt->execute([$dispatch_id]);
                $dispatch_data = $stmt->fetch();
                
                if ($dispatch_data) {
                    // Update unit status back to available
                    $update_unit = "UPDATE units SET 
                        current_status = 'available', 
                        current_dispatch_id = NULL, 
                        last_status_change = NOW() 
                        WHERE id = ?";
                    $stmt = $pdo->prepare($update_unit);
                    $stmt->execute([$dispatch_data['unit_id']]);
                    
                    // Update vehicles back to available
                    $update_vehicle = "UPDATE vehicle_status SET 
                        status = 'available', 
                        dispatch_id = NULL,
                        suggestion_id = NULL,
                        last_updated = NOW() 
                        WHERE dispatch_id = ?";
                    $stmt = $pdo->prepare($update_vehicle);
                    $stmt->execute([$dispatch_id]);
                }
            }
            
            $pdo->commit();
            
            $_SESSION['success_message'] = "Dispatch status updated to " . ucfirst($new_status);
            header("Location: send_dispatch.php");
            exit();
            
        } catch (PDOException $e) {
            $pdo->rollBack();
            $_SESSION['error_message'] = "Failed to update status: " . $e->getMessage();
        }
    }
    
    // Handle send notification
    if (isset($_POST['send_notification'])) {
        $dispatch_id = $_POST['dispatch_id'];
        $notification_type = $_POST['notification_type'];
        $message = $_POST['notification_message'];
        
        try {
            $log_query = "UPDATE dispatch_incidents SET er_notes = CONCAT(IFNULL(er_notes, ''), '\nNotification Sent (', ?, '): ', ?) WHERE id = ?";
            $stmt = $pdo->prepare($log_query);
            $stmt->execute([$notification_type, $message, $dispatch_id]);
            
            $_SESSION['success_message'] = "Notification sent successfully!";
            header("Location: send_dispatch.php");
            exit();
            
        } catch (PDOException $e) {
            $_SESSION['error_message'] = "Failed to send notification: " . $e->getMessage();
        }
    }
}

// Get dispatched incidents (status = 'dispatched')
$dispatched_query = "
    SELECT 
        di.*,
        ai.id as incident_id,
        ai.title,
        ai.location,
        ai.severity,
        ai.emergency_type,
        ai.rescue_category,
        ai.description,
        ai.caller_name,
        ai.caller_phone,
        ai.affected_barangays,
        ai.created_at as incident_time,
        u.unit_name,
        u.unit_code,
        u.unit_type,
        u.location as unit_location,
        u.current_status as unit_status,
        u.capacity,
        u.current_count,
        ub.first_name as dispatcher_first,
        ub.last_name as dispatcher_last,
        (SELECT COUNT(*) FROM vehicle_status vs WHERE vs.dispatch_id = di.id) as vehicle_count
    FROM dispatch_incidents di
    JOIN api_incidents ai ON di.incident_id = ai.id
    JOIN units u ON di.unit_id = u.id
    LEFT JOIN users ub ON di.dispatched_by = ub.id
    WHERE di.status = 'dispatched'
    ORDER BY 
        CASE ai.severity
            WHEN 'critical' THEN 1
            WHEN 'high' THEN 2
            WHEN 'medium' THEN 3
            WHEN 'low' THEN 4
            ELSE 5
        END,
        di.dispatched_at DESC
";
$dispatched_stmt = $pdo->query($dispatched_query);
$dispatched_incidents = $dispatched_stmt->fetchAll();

// Get active dispatches (status IN ('en_route', 'arrived'))
$active_dispatches_query = "
    SELECT 
        di.*,
        ai.id as incident_id,
        ai.title,
        ai.location,
        ai.severity,
        ai.emergency_type,
        ai.rescue_category,
        ai.description,
        ai.caller_name,
        ai.caller_phone,
        ai.affected_barangays,
        ai.created_at as incident_time,
        u.unit_name,
        u.unit_code,
        u.unit_type,
        u.location as unit_location,
        u.current_status as unit_status,
        u.capacity,
        u.current_count,
        ub.first_name as dispatcher_first,
        ub.last_name as dispatcher_last,
        (SELECT COUNT(*) FROM vehicle_status vs WHERE vs.dispatch_id = di.id) as vehicle_count
    FROM dispatch_incidents di
    JOIN api_incidents ai ON di.incident_id = ai.id
    JOIN units u ON di.unit_id = u.id
    LEFT JOIN users ub ON di.dispatched_by = ub.id
    WHERE di.status IN ('en_route', 'arrived')
    ORDER BY 
        CASE ai.severity
            WHEN 'critical' THEN 1
            WHEN 'high' THEN 2
            WHEN 'medium' THEN 3
            WHEN 'low' THEN 4
            ELSE 5
        END,
        di.status_updated_at DESC
";
$active_dispatches_stmt = $pdo->query($active_dispatches_query);
$active_dispatches = $active_dispatches_stmt->fetchAll();

// Get completed dispatches
$completed_dispatches_query = "
    SELECT 
        di.*,
        ai.id as incident_id,
        ai.title,
        ai.location,
        ai.severity,
        ai.emergency_type,
        ai.rescue_category,
        ai.description,
        ai.caller_name,
        ai.caller_phone,
        ai.affected_barangays,
        ai.created_at as incident_time,
        u.unit_name,
        u.unit_code,
        u.unit_type,
        u.location as unit_location,
        u.current_status as unit_status,
        u.capacity,
        u.current_count,
        ub.first_name as dispatcher_first,
        ub.last_name as dispatcher_last,
        (SELECT COUNT(*) FROM vehicle_status vs WHERE vs.dispatch_id = di.id) as vehicle_count
    FROM dispatch_incidents di
    JOIN api_incidents ai ON di.incident_id = ai.id
    JOIN units u ON di.unit_id = u.id
    LEFT JOIN users ub ON di.dispatched_by = ub.id
    WHERE di.status = 'completed'
    ORDER BY di.status_updated_at DESC
    LIMIT 20
";
$completed_dispatches_stmt = $pdo->query($completed_dispatches_query);
$completed_dispatches = $completed_dispatches_stmt->fetchAll();

// Get statistics
$stats_query = "
    SELECT 
        (SELECT COUNT(*) FROM dispatch_incidents WHERE status = 'dispatched') as dispatched_count,
        (SELECT COUNT(*) FROM dispatch_incidents WHERE status IN ('en_route', 'arrived')) as active_count,
        (SELECT COUNT(*) FROM dispatch_incidents WHERE status = 'completed' AND DATE(status_updated_at) = CURDATE()) as completed_today,
        (SELECT COUNT(*) FROM units WHERE current_status = 'dispatched') as dispatched_units,
        (SELECT COUNT(*) FROM api_incidents WHERE dispatch_status = 'processing') as incidents_in_progress,
        (SELECT COUNT(*) FROM vehicle_status WHERE status = 'dispatched') as vehicles_dispatched
";
$stats_stmt = $pdo->query($stats_query);
$stats = $stats_stmt->fetch();

// Check for success/error messages
$success_message = $_SESSION['success_message'] ?? null;
$error_message = $_SESSION['error_message'] ?? null;
unset($_SESSION['success_message']);
unset($_SESSION['error_message']);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dispatch Information - Emergency Response</title>
    <link href='https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css' rel='stylesheet'>
    <link rel="icon" type="image/png" sizes="32x32" href="../../img/frsm-logo.png">
    <link rel="stylesheet" href="../../css/dashboard.css">
    <style>
        /* Dashboard Variables */
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
            --cyan: #06b6d4;
            
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
            --gray-100: #1e293b;
            --gray-200: #334155;
            --gray-300: #475569;
        }
        
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background-color: var(--background-color);
            color: var(--text-color);
            transition: all 0.3s ease;
            margin: 0;
            padding: 0;
            overflow-x: hidden;
        }

        .container {
            display: flex;
            min-height: 100vh;
        }

        .main-content {
            flex: 1;
            overflow-y: auto;
        }

        .dashboard-content {
            padding: 0;
            min-height: 100vh;
        }

        .dashboard-container {
            padding: 0 40px 40px;
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
        
        .dashboard-header .header-content h1 {
            font-size: 40px;
            margin-bottom: 12px;
            color: var(--text-color);
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }

        .dashboard-header .header-content p {
            font-size: 16px;
            opacity: 0.9;
            color: var(--text-color);
        }
        
        .header-actions {
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

        .secondary-button:hover {
            background: var(--gray-100);
            transform: translateY(-2px);
        }

        .dark-mode .secondary-button:hover {
            background: var(--gray-800);
        }
        
        /* Stats Grid */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        
        .stat-card {
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: 16px;
            padding: 25px;
            display: flex;
            align-items: center;
            gap: 15px;
            transition: all 0.3s ease;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
        }
        
        .stat-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 6px 20px rgba(0, 0, 0, 0.1);
            border-color: var(--primary-color);
        }
        
        .stat-icon {
            width: 56px;
            height: 56px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
            flex-shrink: 0;
        }
        
        .stat-content {
            flex: 1;
        }
        
        .stat-content .value {
            font-size: 32px;
            font-weight: 800;
            line-height: 1;
            margin-bottom: 4px;
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }
        
        .stat-content .label {
            color: var(--text-light);
            font-size: 14px;
            font-weight: 500;
        }
        
        /* Dispatch Sections */
        .dispatch-section {
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: 16px;
            margin-bottom: 30px;
            overflow: hidden;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
            padding: 30px;
        }
        
        .section-header {
            margin-bottom: 20px;
        }
        
        .section-header h3 {
            font-size: 24px;
            font-weight: 700;
            color: var(--text-color);
            margin-bottom: 8px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .section-header p {
            color: var(--text-light);
            font-size: 14px;
        }
        
        /* Dispatch Cards */
        .dispatch-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(400px, 1fr));
            gap: 20px;
            margin-top: 20px;
        }
        
        .dispatch-card {
            background: var(--card-bg);
            border: 2px solid var(--border-color);
            border-radius: 12px;
            padding: 20px;
            transition: all 0.3s ease;
        }
        
        .dispatch-card:hover {
            border-color: var(--primary-color);
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(0, 0, 0, 0.1);
        }
        
        .dispatch-card.dispatched {
            border-left: 4px solid var(--info);
        }
        
        .dispatch-card.active {
            border-left: 4px solid var(--warning);
        }
        
        .dispatch-card.completed {
            border-left: 4px solid var(--success);
        }
        
        .dispatch-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 15px;
        }
        
        .dispatch-title {
            font-size: 18px;
            font-weight: 700;
            margin: 0;
            color: var(--text-color);
        }
        
        .dispatch-status {
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            text-transform: uppercase;
        }
        
        .status-dispatched { background: var(--info); color: white; }
        .status-en_route { background: var(--purple); color: white; }
        .status-arrived { background: var(--warning); color: white; }
        .status-completed { background: var(--success); color: white; }
        .status-cancelled { background: var(--danger); color: white; }
        
        .severity-badge {
            padding: 4px 8px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
            display: inline-block;
            margin-top: 5px;
        }
        
        .severity-critical { background: #dc2626; color: white; }
        .severity-high { background: #ef4444; color: white; }
        .severity-medium { background: #f59e0b; color: white; }
        .severity-low { background: #10b981; color: white; }
        
        .dispatch-details {
            margin-bottom: 15px;
        }
        
        .detail-item {
            display: flex;
            align-items: center;
            gap: 8px;
            margin-bottom: 8px;
            font-size: 14px;
        }
        
        .detail-label {
            font-weight: 600;
            color: var(--text-light);
            min-width: 120px;
        }
        
        .detail-value {
            flex: 1;
        }
        
        .dispatch-actions {
            display: flex;
            gap: 8px;
            margin-top: 15px;
            flex-wrap: wrap;
        }
        
        .btn {
            padding: 8px 16px;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-weight: 600;
            font-size: 14px;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            transition: all 0.3s ease;
            text-decoration: none;
        }
        
        .btn-sm {
            padding: 6px 12px;
            font-size: 13px;
        }
        
        .btn-success {
            background: linear-gradient(135deg, var(--success), #0da271);
            color: white;
        }
        
        .btn-success:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 15px rgba(16, 185, 129, 0.3);
        }
        
        .btn-primary {
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            color: white;
        }
        
        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 15px rgba(220, 38, 38, 0.3);
        }
        
        .btn-secondary {
            background: var(--gray-200);
            color: var(--text-color);
            border: 1px solid var(--border-color);
        }
        
        .btn-secondary:hover {
            background: var(--gray-300);
            transform: translateY(-2px);
        }
        
        .btn-danger {
            background: linear-gradient(135deg, var(--danger), #b91c1c);
            color: white;
        }
        
        .btn-danger:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 15px rgba(220, 38, 38, 0.3);
        }
        
        .btn-warning {
            background: linear-gradient(135deg, var(--warning), #d97706);
            color: white;
        }
        
        .btn-warning:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 15px rgba(245, 158, 11, 0.3);
        }
        
        /* Modal Styles */
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.5);
            z-index: 1000;
            align-items: center;
            justify-content: center;
            backdrop-filter: blur(4px);
        }
        
        .modal.active {
            display: flex;
        }
        
        .modal-content {
            background: var(--card-bg);
            border-radius: 16px;
            width: 90%;
            max-width: 500px;
            max-height: 85vh;
            overflow-y: auto;
            animation: modalSlideIn 0.3s ease;
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.2);
        }
        
        @keyframes modalSlideIn {
            from {
                opacity: 0;
                transform: translateY(-20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        .modal-header {
            padding: 25px 30px;
            border-bottom: 1px solid var(--border-color);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .modal-header h3 {
            margin: 0;
            font-size: 24px;
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .modal-header button {
            background: none;
            border: none;
            font-size: 28px;
            cursor: pointer;
            color: var(--text-light);
            transition: color 0.3s ease;
            width: 40px;
            height: 40px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 50%;
        }
        
        .modal-header button:hover {
            background: var(--gray-100);
            color: var(--danger);
        }
        
        .modal-body {
            padding: 30px;
        }
        
        .form-group {
            margin-bottom: 20px;
        }
        
        .form-label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: var(--text-color);
        }
        
        .form-control {
            width: 100%;
            padding: 12px;
            border: 1px solid var(--border-color);
            border-radius: 8px;
            background: var(--card-bg);
            color: var(--text-color);
            font-size: 14px;
        }
        
        .form-control:focus {
            outline: none;
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(220, 38, 38, 0.1);
        }
        
        .modal-actions {
            display: flex;
            gap: 10px;
            justify-content: flex-end;
            margin-top: 20px;
            padding-top: 20px;
            border-top: 1px solid var(--border-color);
        }
        
        /* No Data State */
        .no-data {
            text-align: center;
            padding: 60px 20px;
            color: var(--text-light);
        }
        
        .no-data i {
            font-size: 64px;
            margin-bottom: 20px;
            display: block;
            opacity: 0.5;
        }
        
        .no-data p {
            margin: 0;
            font-size: 18px;
        }
        
        .no-data .subtext {
            font-size: 14px;
            margin-top: 8px;
        }
        
        /* Alerts */
        .alert {
            padding: 16px 20px;
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
        
        .alert-error {
            background: rgba(239, 68, 68, 0.1);
            border: 1px solid rgba(239, 68, 68, 0.2);
            color: var(--danger);
        }
        
        @keyframes slideIn {
            from {
                opacity: 0;
                transform: translateY(-10px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        /* Responsive */
        @media (max-width: 992px) {
            .dashboard-container {
                padding: 0 25px 30px;
            }
            
            .dispatch-grid {
                grid-template-columns: 1fr;
            }
            
            .dashboard-header {
                padding: 40px 25px 30px;
            }
            
            .dashboard-header .header-content h1 {
                font-size: 32px;
            }
        }
        
        @media (max-width: 768px) {
            .stats-grid {
                grid-template-columns: 1fr;
            }
            
            .dispatch-actions {
                flex-direction: column;
            }
            
            .btn {
                width: 100%;
                justify-content: center;
            }
            
            .detail-item {
                flex-direction: column;
                align-items: flex-start;
                gap: 4px;
            }
            
            .detail-label {
                min-width: auto;
            }
        }
        
        @media (max-width: 480px) {
            .dashboard-container {
                padding: 0 15px 20px;
            }
            
            .dashboard-header {
                padding: 30px 20px 20px;
                border-radius: 0 0 20px 20px;
            }
            
            .dashboard-header .header-content h1 {
                font-size: 24px;
            }
            
            .modal-content {
                width: 95%;
                margin: 10px;
            }
        }
    </style>
</head>
<body>
    <!-- Status Update Modal -->
    <div class="modal" id="statusModal">
        <div class="modal-content">
            <form method="POST">
                <div class="modal-header">
                    <h3><i class='bx bx-refresh'></i> Update Dispatch Status</h3>
                    <button type="button" onclick="closeModal('statusModal')">&times;</button>
                </div>
                <div class="modal-body">
                    <div class="form-group">
                        <label class="form-label">New Status</label>
                        <select class="form-control" name="new_status" id="new_status">
                            <option value="dispatched">Dispatched</option>
                            <option value="en_route">En Route</option>
                            <option value="arrived">Arrived</option>
                            <option value="completed">Completed</option>
                            <option value="cancelled">Cancelled</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Notes (Optional)</label>
                        <textarea class="form-control" name="status_notes" rows="3" placeholder="Additional notes about status change"></textarea>
                    </div>
                    <input type="hidden" name="dispatch_id" id="status_dispatch_id">
                </div>
                <div class="modal-actions">
                    <button type="button" class="btn btn-secondary" onclick="closeModal('statusModal')">Cancel</button>
                    <button type="submit" name="update_status" class="btn btn-primary">Update Status</button>
                </div>
            </form>
        </div>
    </div>
    
    <!-- Notification Modal -->
    <div class="modal" id="notificationModal">
        <div class="modal-content">
            <form method="POST">
                <div class="modal-header">
                    <h3><i class='bx bx-bell'></i> Send Notification</h3>
                    <button type="button" onclick="closeModal('notificationModal')">&times;</button>
                </div>
                <div class="modal-body">
                    <div class="form-group">
                        <label class="form-label">Notification Type</label>
                        <select class="form-control" name="notification_type" id="notification_type">
                            <option value="urgent">Urgent - Immediate Action Required</option>
                            <option value="update">Status Update</option>
                            <option value="reminder">Reminder</option>
                            <option value="info">Information</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Message</label>
                        <textarea class="form-control" name="notification_message" rows="5" placeholder="Enter notification message..." required></textarea>
                    </div>
                    <input type="hidden" name="dispatch_id" id="notification_dispatch_id">
                </div>
                <div class="modal-actions">
                    <button type="button" class="btn btn-secondary" onclick="closeModal('notificationModal')">Cancel</button>
                    <button type="submit" name="send_notification" class="btn btn-primary">Send Notification</button>
                </div>
            </form>
        </div>
    </div>
    
    <div class="container">
        <!-- Sidebar (keep your existing sidebar code) -->
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
                    <div id="dispatch" class="submenu active">
                        <a href="select_unit.php" class="submenu-item">Select Unit</a>
                        <a href="send_dispatch.php" class="submenu-item active">Send Dispatch Info</a>
                        
                        <a href="track_status.php" class="submenu-item">Track Status</a>
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
                    <div id="inventory" class="submenu">
                        <a href="../ri/log_usage.php" class="submenu-item">Log Usage</a>
                        <a href="../ri/report_damages.php" class="submenu-item">Report Damages</a>
                        <a href="../ri/request_supplies.php" class="submenu-item">Request Supplies</a>
                        <a href="../ri/tag_resources.php" class="submenu-item">Tag Resources</a>
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
                            <input type="text" placeholder="Search..." class="search-input" id="search-input">
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
                            <?php if ($avatar): ?>
                                <img src="../profile/uploads/avatars/<?php echo $avatar; ?>" alt="User" class="user-avatar">
                            <?php else: ?>
                                <div class="user-avatar" style="background: linear-gradient(135deg, var(--primary-color), var(--secondary-color)); color: white; display: flex; align-items: center; justify-content: center; font-weight: bold; border-radius: 50%; width: 40px; height: 40px;">
                                    <?php echo strtoupper(substr($full_name, 0, 1)); ?>
                                </div>
                            <?php endif; ?>
                            <div class="user-info">
                                <p class="user-name"><?php echo $full_name; ?></p>
                                <p class="user-email"><?php echo $role; ?> - Emergency Response</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Dashboard Content -->
            <div class="dashboard-content">
                <div class="dashboard-container">
                    <!-- Dashboard Header -->
                    <div class="dashboard-header">
                        <div class="header-content">
                            <h1><i class='bx bx-send'></i> ER - Dispatch Information</h1>
                         
                        </div>
                        <div class="header-actions">
                            <button class="secondary-button" onclick="location.reload()">
                                <i class='bx bx-refresh'></i> Refresh Data
                            </button>
                        </div>
                    </div>
                    
                    <!-- Show Success/Error Messages -->
                    <?php if ($success_message): ?>
                        <div class="alert alert-success">
                            <i class='bx bx-check-circle'></i>
                            <?php echo htmlspecialchars($success_message); ?>
                        </div>
                    <?php endif; ?>
                    
                    <?php if ($error_message): ?>
                        <div class="alert alert-error">
                            <i class='bx bx-error-circle'></i>
                            <?php echo htmlspecialchars($error_message); ?>
                        </div>
                    <?php endif; ?>
                    
                    <!-- Statistics -->
                    <div class="stats-grid">
                        <div class="stat-card">
                            <div class="stat-icon" style="background: rgba(59, 130, 246, 0.1); color: #3b82f6;">
                                <i class='bx bx-send'></i>
                            </div>
                            <div class="stat-content">
                                <div class="value"><?php echo $stats['dispatched_count'] ?? 0; ?></div>
                                <div class="label">Dispatched Incidents</div>
                            </div>
                        </div>
                        <div class="stat-card">
                            <div class="stat-icon" style="background: rgba(245, 158, 11, 0.1); color: var(--warning);">
                                <i class='bx bx-radar'></i>
                            </div>
                            <div class="stat-content">
                                <div class="value"><?php echo $stats['active_count'] ?? 0; ?></div>
                                <div class="label">Active Responses</div>
                            </div>
                        </div>
                        <div class="stat-card">
                            <div class="stat-icon" style="background: rgba(16, 185, 129, 0.1); color: #10b981;">
                                <i class='bx bx-check-double'></i>
                            </div>
                            <div class="stat-content">
                                <div class="value"><?php echo $stats['completed_today'] ?? 0; ?></div>
                                <div class="label">Completed Today</div>
                            </div>
                        </div>
                        <div class="stat-card">
                            <div class="stat-icon" style="background: rgba(220, 38, 38, 0.1); color: var(--danger);">
                                <i class='bx bx-car'></i>
                            </div>
                            <div class="stat-content">
                                <div class="value"><?php echo $stats['vehicles_dispatched'] ?? 0; ?></div>
                                <div class="label">Vehicles Deployed</div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Dispatched Incidents -->
                    <div class="dispatch-section">
                        <div class="section-header">
                            <h3><i class='bx bx-send'></i> Dispatched Incidents</h3>
                            <p>Recently dispatched emergency responses - Units and vehicles are currently deployed</p>
                        </div>
                        
                        <?php if (count($dispatched_incidents) > 0): ?>
                            <div class="dispatch-grid">
                                <?php foreach ($dispatched_incidents as $dispatch): ?>
                                    <?php
                                    // Format dispatch time
                                    $dispatch_time = new DateTime($dispatch['dispatched_at']);
                                    $time_ago = getTimeAgo($dispatch_time);
                                    
                                    // Get vehicles for this dispatch
                                    $vehicles = [];
                                    if ($dispatch['vehicles_json']) {
                                        $vehicles = json_decode($dispatch['vehicles_json'], true);
                                    }
                                    ?>
                                    
                                    <div class="dispatch-card dispatched">
                                        <div class="dispatch-header">
                                            <div>
                                                <h4 class="dispatch-title"><?php echo htmlspecialchars($dispatch['title']); ?></h4>
                                                <span class="severity-badge severity-<?php echo strtolower($dispatch['severity']); ?>">
                                                    <?php echo ucfirst($dispatch['severity']); ?>
                                                </span>
                                            </div>
                                            <span class="dispatch-status status-dispatched">Dispatched</span>
                                        </div>
                                        
                                        <div class="dispatch-details">
                                            <div class="detail-item">
                                                <span class="detail-label">Incident:</span>
                                                <span class="detail-value"><?php echo htmlspecialchars($dispatch['emergency_type']); ?>
                                                    <?php if ($dispatch['rescue_category']): ?>
                                                        <br><small><?php echo str_replace('_', ' ', $dispatch['rescue_category']); ?></small>
                                                    <?php endif; ?>
                                                </span>
                                            </div>
                                            <div class="detail-item">
                                                <span class="detail-label">Location:</span>
                                                <span class="detail-value"><?php echo htmlspecialchars($dispatch['location']); ?></span>
                                            </div>
                                            <div class="detail-item">
                                                <span class="detail-label">Unit:</span>
                                                <span class="detail-value"><?php echo htmlspecialchars($dispatch['unit_name']); ?> (<?php echo htmlspecialchars($dispatch['unit_code']); ?>)</span>
                                            </div>
                                            <div class="detail-item">
                                                <span class="detail-label">Dispatched:</span>
                                                <span class="detail-value"><?php echo $time_ago; ?> ago</span>
                                            </div>
                                            <?php if ($dispatch['vehicle_count'] > 0): ?>
                                                <div class="detail-item">
                                                    <span class="detail-label">Vehicles:</span>
                                                    <span class="detail-value"><?php echo $dispatch['vehicle_count']; ?> deployed</span>
                                                </div>
                                            <?php endif; ?>
                                            <?php if ($dispatch['dispatcher_first']): ?>
                                                <div class="detail-item">
                                                    <span class="detail-label">Dispatched By:</span>
                                                    <span class="detail-value"><?php echo htmlspecialchars($dispatch['dispatcher_first'] . ' ' . $dispatch['dispatcher_last']); ?></span>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                        
                                        <div class="dispatch-actions">
                                            <button class="btn btn-primary btn-sm" 
                                                    onclick="showStatusModal(<?php echo $dispatch['id']; ?>, 'dispatched')">
                                                <i class='bx bx-refresh'></i> Update Status
                                            </button>
                                            <button class="btn btn-warning btn-sm" 
                                                    onclick="showNotificationModal(<?php echo $dispatch['id']; ?>)">
                                                <i class='bx bx-bell'></i> Notify
                                            </button>
                                            <button class="btn btn-secondary btn-sm" onclick="viewDispatchDetails(<?php echo $dispatch['id']; ?>)">
                                                <i class='bx bx-detail'></i> Details
                                            </button>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <div class="no-data">
                                <i class='bx bx-info-circle'></i>
                                <p>No dispatched incidents at the moment</p>
                                <p class="subtext">All units are available for new incidents</p>
                            </div>
                        <?php endif; ?>
                    </div>
                    
                    <!-- Active Dispatches -->
                    <div class="dispatch-section">
                        <div class="section-header">
                            <h3><i class='bx bx-radar'></i> Active Responses</h3>
                            <p>Units currently en route or arrived at incident location</p>
                        </div>
                        
                        <?php if (count($active_dispatches) > 0): ?>
                            <div class="dispatch-grid">
                                <?php foreach ($active_dispatches as $dispatch): ?>
                                    <?php
                                    // Format dispatch time
                                    $dispatch_time = new DateTime($dispatch['dispatched_at']);
                                    $time_ago = getTimeAgo($dispatch_time);
                                    
                                    // Calculate response time
                                    $response_time = '';
                                    if ($dispatch['status_updated_at']) {
                                        $status_time = new DateTime($dispatch['status_updated_at']);
                                        $interval = $dispatch_time->diff($status_time);
                                        $response_time = $interval->format('%h hours %i minutes');
                                    }
                                    ?>
                                    
                                    <div class="dispatch-card active">
                                        <div class="dispatch-header">
                                            <div>
                                                <h4 class="dispatch-title"><?php echo htmlspecialchars($dispatch['title']); ?></h4>
                                                <span class="severity-badge severity-<?php echo strtolower($dispatch['severity']); ?>">
                                                    <?php echo ucfirst($dispatch['severity']); ?>
                                                </span>
                                            </div>
                                            <span class="dispatch-status status-<?php echo strtolower($dispatch['status']); ?>">
                                                <?php echo ucfirst(str_replace('_', ' ', $dispatch['status'])); ?>
                                            </span>
                                        </div>
                                        
                                        <div class="dispatch-details">
                                            <div class="detail-item">
                                                <span class="detail-label">Unit:</span>
                                                <span class="detail-value"><?php echo htmlspecialchars($dispatch['unit_name']); ?></span>
                                            </div>
                                            <div class="detail-item">
                                                <span class="detail-label">Location:</span>
                                                <span class="detail-value"><?php echo htmlspecialchars($dispatch['location']); ?></span>
                                            </div>
                                            <div class="detail-item">
                                                <span class="detail-label">Dispatched:</span>
                                                <span class="detail-value"><?php echo $time_ago; ?> ago</span>
                                            </div>
                                            <?php if ($response_time): ?>
                                                <div class="detail-item">
                                                    <span class="detail-label">Response Time:</span>
                                                    <span class="detail-value"><?php echo $response_time; ?></span>
                                                </div>
                                            <?php endif; ?>
                                            <?php if ($dispatch['vehicle_count'] > 0): ?>
                                                <div class="detail-item">
                                                    <span class="detail-label">Vehicles:</span>
                                                    <span class="detail-value"><?php echo $dispatch['vehicle_count']; ?> deployed</span>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                        
                                        <div class="dispatch-actions">
                                            <button class="btn btn-primary btn-sm" 
                                                    onclick="showStatusModal(<?php echo $dispatch['id']; ?>, '<?php echo $dispatch['status']; ?>')">
                                                <i class='bx bx-refresh'></i> Update Status
                                            </button>
                                            <button class="btn btn-warning btn-sm" 
                                                    onclick="showNotificationModal(<?php echo $dispatch['id']; ?>)">
                                                <i class='bx bx-bell'></i> Notify
                                            </button>
                                            <button class="btn btn-secondary btn-sm" onclick="viewDispatchDetails(<?php echo $dispatch['id']; ?>)">
                                                <i class='bx bx-detail'></i> Details
                                            </button>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <div class="no-data">
                                <i class='bx bx-check-circle'></i>
                                <p>No active responses at the moment</p>
                            </div>
                        <?php endif; ?>
                    </div>
                    
                    <!-- Completed Dispatches -->
                    <div class="dispatch-section">
                        <div class="section-header">
                            <h3><i class='bx bx-history'></i> Recently Completed Responses</h3>
                            <p>Units that have completed their response and are now available</p>
                        </div>
                        
                        <?php if (count($completed_dispatches) > 0): ?>
                            <div class="dispatch-grid">
                                <?php foreach ($completed_dispatches as $dispatch): ?>
                                    <?php
                                    // Format completion time
                                    $completion_time = new DateTime($dispatch['status_updated_at']);
                                    $time_ago = getTimeAgo($completion_time);
                                    
                                    // Calculate total response time
                                    $total_time = '';
                                    if ($dispatch['dispatched_at'] && $dispatch['status_updated_at']) {
                                        $dispatch_time = new DateTime($dispatch['dispatched_at']);
                                        $complete_time = new DateTime($dispatch['status_updated_at']);
                                        $interval = $dispatch_time->diff($complete_time);
                                        $total_time = $interval->format('%h hours %i minutes');
                                    }
                                    ?>
                                    
                                    <div class="dispatch-card completed">
                                        <div class="dispatch-header">
                                            <div>
                                                <h4 class="dispatch-title"><?php echo htmlspecialchars($dispatch['title']); ?></h4>
                                                <span class="severity-badge severity-<?php echo strtolower($dispatch['severity']); ?>">
                                                    <?php echo ucfirst($dispatch['severity']); ?>
                                                </span>
                                            </div>
                                            <span class="dispatch-status status-completed">Completed</span>
                                        </div>
                                        
                                        <div class="dispatch-details">
                                            <div class="detail-item">
                                                <span class="detail-label">Unit:</span>
                                                <span class="detail-value"><?php echo htmlspecialchars($dispatch['unit_name']); ?></span>
                                            </div>
                                            <div class="detail-item">
                                                <span class="detail-label">Location:</span>
                                                <span class="detail-value"><?php echo htmlspecialchars($dispatch['location']); ?></span>
                                            </div>
                                            <div class="detail-item">
                                                <span class="detail-label">Completed:</span>
                                                <span class="detail-value"><?php echo $time_ago; ?> ago</span>
                                            </div>
                                            <?php if ($total_time): ?>
                                                <div class="detail-item">
                                                    <span class="detail-label">Total Response:</span>
                                                    <span class="detail-value"><?php echo $total_time; ?></span>
                                                </div>
                                            <?php endif; ?>
                                            <?php if ($dispatch['vehicle_count'] > 0): ?>
                                                <div class="detail-item">
                                                    <span class="detail-label">Vehicles:</span>
                                                    <span class="detail-value"><?php echo $dispatch['vehicle_count']; ?> returned</span>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                        
                                        <div class="dispatch-actions">
                                            <button class="btn btn-secondary btn-sm" onclick="viewDispatchDetails(<?php echo $dispatch['id']; ?>)">
                                                <i class='bx bx-detail'></i> View Details
                                            </button>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <div class="no-data">
                                <i class='bx bx-info-circle'></i>
                                <p>No completed responses in recent history</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <script>
        // Modal functions
        function showStatusModal(dispatchId, currentStatus) {
            document.getElementById('status_dispatch_id').value = dispatchId;
            document.getElementById('new_status').value = currentStatus;
            document.getElementById('statusModal').classList.add('active');
        }
        
        function showNotificationModal(dispatchId) {
            document.getElementById('notification_dispatch_id').value = dispatchId;
            document.getElementById('notificationModal').classList.add('active');
        }
        
        function closeModal(modalId) {
            document.getElementById(modalId).classList.remove('active');
        }
        
        function viewDispatchDetails(dispatchId) {
            // In a real implementation, this would open a detailed view
            alert('Detailed view for dispatch #' + dispatchId + ' would open here.\n\nThis would show: \n- Complete incident details\n- Unit information\n- Vehicle assignments\n- Status history\n- Communications log\n- Post-incident reports');
        }
        
        // Theme toggle
        document.addEventListener('DOMContentLoaded', () => {
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
            
            // Update time
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
            
            // Toggle submenus
            function toggleSubmenu(id) {
                const submenu = document.getElementById(id);
                const arrow = submenu.previousElementSibling.querySelector('.dropdown-arrow');
                
                submenu.classList.toggle('active');
                arrow.classList.toggle('rotated');
            }
            
            window.toggleSubmenu = toggleSubmenu;
        });
    </script>
</body>
</html>

<?php
// Helper function to format time ago
function getTimeAgo($datetime) {
    $now = new DateTime();
    $interval = $now->diff($datetime);
    
    if ($interval->y > 0) {
        return $interval->format('%y year' . ($interval->y > 1 ? 's' : ''));
    } elseif ($interval->m > 0) {
        return $interval->format('%m month' . ($interval->m > 1 ? 's' : ''));
    } elseif ($interval->d > 0) {
        return $interval->format('%d day' . ($interval->d > 1 ? 's' : ''));
    } elseif ($interval->h > 0) {
        return $interval->format('%h hour' . ($interval->h > 1 ? 's' : ''));
    } elseif ($interval->i > 0) {
        return $interval->format('%i minute' . ($interval->i > 1 ? 's' : ''));
    } else {
        return 'just now';
    }
}
?>