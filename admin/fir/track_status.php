<?php
// track_status.php
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

if ($user['role'] !== 'ADMIN' && $user['role'] !== 'ADMIN') {
    header("Location: ../admin_dashboard.php");
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

// Get filter parameters
$status_filter = $_GET['status'] ?? 'all';
$unit_filter = $_GET['unit'] ?? 'all';
$severity_filter = $_GET['severity'] ?? 'all';
$date_filter = $_GET['date'] ?? '';
$type_filter = $_GET['type'] ?? 'all'; // 'suggested', 'dispatched', 'all'

// Build base query
$query_conditions = [];
$query_params = [];

if ($status_filter !== 'all') {
    if ($status_filter === 'active') {
        $query_conditions[] = "di.status IN ('dispatched', 'en_route', 'arrived')";
    } elseif ($status_filter === 'pending') {
        $query_conditions[] = "di.status = 'pending'";
    } elseif ($status_filter === 'completed') {
        $query_conditions[] = "di.status = 'completed'";
    } else {
        $query_conditions[] = "di.status = ?";
        $query_params[] = $status_filter;
    }
}

if ($unit_filter !== 'all') {
    $query_conditions[] = "di.unit_id = ?";
    $query_params[] = $unit_filter;
}

if ($severity_filter !== 'all') {
    $query_conditions[] = "ai.severity = ?";
    $query_params[] = $severity_filter;
}

if ($date_filter !== '') {
    // For suggestions, use dispatched_at (suggestion time)
    // For dispatched, use status_updated_at (dispatch time)
    if ($type_filter === 'suggested') {
        $query_conditions[] = "DATE(di.dispatched_at) = ?";
    } else {
        $query_conditions[] = "DATE(di.status_updated_at) = ?";
    }
    $query_params[] = $date_filter;
}

if ($type_filter !== 'all') {
    if ($type_filter === 'suggested') {
        $query_conditions[] = "di.status = 'pending'";
    } elseif ($type_filter === 'dispatched') {
        $query_conditions[] = "di.status IN ('dispatched', 'en_route', 'arrived', 'completed', 'cancelled')";
    }
}

// Get all dispatches with filters
$where_clause = '';
if (!empty($query_conditions)) {
    $where_clause = "WHERE " . implode(" AND ", $query_conditions);
}

$dispatches_query = "
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
        ai.dispatch_status as incident_dispatch_status,
        ai.responded_by as incident_responded_by,  -- Get responded_by from api_incidents
        u.unit_name,
        u.unit_code,
        u.unit_type,
        u.location as unit_location,
        u.current_status as unit_status,
        u.capacity,
        u.current_count,
        ub.first_name as suggested_by_first,
        ub.last_name as suggested_by_last,
        ub2.first_name as dispatched_by_first,
        ub2.last_name as dispatched_by_last,
        -- FIXED: Count vehicles properly from vehicles_json
        (SELECT COUNT(*) FROM vehicle_status vs WHERE vs.dispatch_id = di.id) as vehicle_count,
        -- Also get vehicle count from JSON if available
        (CASE 
            WHEN di.vehicles_json IS NOT NULL AND di.vehicles_json != '' 
            THEN JSON_LENGTH(di.vehicles_json) 
            ELSE 0 
        END) as json_vehicle_count,
        TIMESTAMPDIFF(MINUTE, di.dispatched_at, NOW()) as minutes_since_suggestion,
        TIMESTAMPDIFF(MINUTE, di.dispatched_at, di.status_updated_at) as suggestion_to_dispatch_minutes,
        CASE 
            WHEN di.status = 'completed' THEN TIMESTAMPDIFF(MINUTE, di.status_updated_at, di.dispatched_at)
            ELSE NULL
        END as dispatch_to_complete_minutes,
        di.er_notes,
        ai.responded_at as incident_responded_at  -- Get responded_at from api_incidents
    FROM dispatch_incidents di
    JOIN api_incidents ai ON di.incident_id = ai.id
    JOIN units u ON di.unit_id = u.id
    LEFT JOIN users ub ON di.dispatched_by = ub.id  -- Suggested by user (you)
    LEFT JOIN users ub2 ON ai.responded_by = ub2.id  -- Dispatched by user (ER) - from api_incidents
    $where_clause
    ORDER BY 
        CASE di.status 
            WHEN 'pending' THEN 1  -- Show suggestions first
            WHEN 'dispatched' THEN 2
            WHEN 'en_route' THEN 3
            WHEN 'arrived' THEN 4
            WHEN 'completed' THEN 5
            WHEN 'cancelled' THEN 6
            ELSE 7
        END,
        CASE WHEN di.status = 'pending' THEN di.dispatched_at ELSE di.status_updated_at END DESC
";

$stmt = $pdo->prepare($dispatches_query);
$stmt->execute($query_params);
$dispatches = $stmt->fetchAll();

// Get all units for filter dropdown
$units_query = "SELECT id, unit_name, unit_code FROM units WHERE status = 'active' ORDER BY unit_name";
$units_stmt = $pdo->query($units_query);
$all_units = $units_stmt->fetchAll();

// Get statistics
$stats_query = "
    SELECT 
        (SELECT COUNT(*) FROM dispatch_incidents WHERE status = 'pending') as pending_suggestions,
        (SELECT COUNT(*) FROM dispatch_incidents WHERE status IN ('dispatched', 'en_route', 'arrived')) as active_dispatches,
        (SELECT COUNT(*) FROM dispatch_incidents WHERE status = 'completed' AND DATE(status_updated_at) = CURDATE()) as completed_today,
        (SELECT COUNT(*) FROM units WHERE current_status = 'dispatched') as units_deployed,
        (SELECT AVG(TIMESTAMPDIFF(MINUTE, di.dispatched_at, di.status_updated_at)) 
         FROM dispatch_incidents di 
         WHERE di.status IN ('dispatched', 'en_route', 'arrived', 'completed') 
         AND di.status != 'pending' 
         AND di.status_updated_at IS NOT NULL) as avg_dispatch_time,
        (SELECT COUNT(*) FROM dispatch_incidents WHERE status = 'pending' AND DATE(dispatched_at) = CURDATE()) as suggestions_today,
        (SELECT COUNT(*) FROM vehicle_status WHERE status = 'dispatched') as vehicles_deployed
";
$stats_stmt = $pdo->query($stats_query);
$stats = $stats_stmt->fetch();

// Calculate additional stats
$total_dispatches = count($dispatches);
$pending_count = 0;
$dispatched_count = 0;
$en_route_count = 0;
$arrived_count = 0;
$completed_count = 0;
$cancelled_count = 0;

foreach ($dispatches as $dispatch) {
    switch ($dispatch['status']) {
        case 'pending':
            $pending_count++;
            break;
        case 'dispatched':
            $dispatched_count++;
            break;
        case 'en_route':
            $en_route_count++;
            break;
        case 'arrived':
            $arrived_count++;
            break;
        case 'completed':
            $completed_count++;
            break;
        case 'cancelled':
            $cancelled_count++;
            break;
    }
}

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
    <title>Track Dispatch Status - Emergency Response</title>
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
        
        /* Filter Section */
        .filter-section {
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: 16px;
            margin-bottom: 30px;
            overflow: hidden;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
            padding: 25px;
        }
        
        .filter-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }
        
        .filter-header h3 {
            font-size: 20px;
            font-weight: 700;
            color: var(--text-color);
            margin: 0;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .filter-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
        }
        
        .filter-group {
            margin-bottom: 0;
        }
        
        .filter-label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: var(--text-color);
            font-size: 14px;
        }
        
        .filter-select, .filter-input {
            width: 100%;
            padding: 10px 12px;
            border: 1px solid var(--border-color);
            border-radius: 8px;
            background: var(--card-bg);
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
            gap: 10px;
            margin-top: 20px;
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
        
        /* Dispatch Table */
        .dispatch-table-section {
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: 16px;
            margin-bottom: 30px;
            overflow: hidden;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
        }
        
        .table-header {
            padding: 25px 30px;
            border-bottom: 1px solid var(--border-color);
        }
        
        .table-header h3 {
            font-size: 24px;
            font-weight: 700;
            color: var(--text-color);
            margin: 0;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .table-container {
            overflow-x: auto;
        }
        
        .dispatch-table {
            width: 100%;
            border-collapse: collapse;
        }
        
        .dispatch-table th {
            background: var(--gray-100);
            padding: 16px 20px;
            text-align: left;
            font-weight: 600;
            color: var(--text-color);
            border-bottom: 1px solid var(--border-color);
            white-space: nowrap;
        }
        
        .dark-mode .dispatch-table th {
            background: var(--gray-800);
        }
        
        .dispatch-table td {
            padding: 16px 20px;
            border-bottom: 1px solid var(--border-color);
            vertical-align: top;
        }
        
        .dispatch-table tr:hover {
            background: var(--gray-100);
        }
        
        .dark-mode .dispatch-table tr:hover {
            background: var(--gray-800);
        }
        
        .dispatch-table tr:last-child td {
            border-bottom: none;
        }
        
        /* Status badges in table */
        .status-badge {
            padding: 4px 8px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
            display: inline-block;
        }
        
        .status-pending { background: var(--warning); color: white; }
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
        }
        
        .severity-critical { background: #dc2626; color: white; }
        .severity-high { background: #ef4444; color: white; }
        .severity-medium { background: #f59e0b; color: white; }
        .severity-low { background: #10b981; color: white; }
        
        /* Response time indicators */
        .response-time {
            font-weight: 600;
        }
        
        .response-fast { color: var(--success); }
        .response-medium { color: var(--warning); }
        .response-slow { color: var(--danger); }
        
        /* Progress bar for status */
        .status-progress {
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .progress-bar {
            flex: 1;
            height: 6px;
            background: var(--gray-200);
            border-radius: 3px;
            overflow: hidden;
            position: relative;
        }
        
        .progress-fill {
            position: absolute;
            top: 0;
            left: 0;
            height: 100%;
            transition: width 0.3s ease;
        }
        
        .progress-pending .progress-fill { 
            width: 20%; 
            background: var(--warning); 
        }
        
        .progress-dispatched .progress-fill { 
            width: 40%; 
            background: var(--info); 
        }
        
        .progress-en_route .progress-fill { 
            width: 60%; 
            background: var(--purple); 
        }
        
        .progress-arrived .progress-fill { 
            width: 80%; 
            background: var(--warning); 
        }
        
        .progress-completed .progress-fill { 
            width: 100%; 
            background: var(--success); 
        }
        
        .progress-cancelled .progress-fill { 
            width: 100%; 
            background: var(--danger); 
        }
        
        /* Status timeline */
        .status-timeline {
            display: flex;
            gap: 4px;
            margin-top: 8px;
        }
        
        .timeline-step {
            flex: 1;
            height: 4px;
            border-radius: 2px;
            background: var(--gray-300);
            position: relative;
        }
        
        .timeline-step.active {
            background: var(--primary-color);
        }
        
        .timeline-step.completed {
            background: var(--success);
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
            max-width: 600px;
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
        
        /* Dispatch Details */
        .details-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-bottom: 20px;
        }
        
        .detail-card {
            background: var(--gray-100);
            border-radius: 10px;
            padding: 20px;
        }
        
        .dark-mode .detail-card {
            background: var(--gray-800);
        }
        
        .detail-card h4 {
            margin: 0 0 10px 0;
            font-size: 16px;
            color: var(--text-light);
        }
        
        .detail-card p {
            margin: 0;
            font-size: 18px;
            font-weight: 600;
            color: var(--text-color);
        }
        
        /* Vehicle list in details */
        .vehicle-list {
            margin-top: 15px;
        }
        
        .vehicle-item {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 8px;
            background: rgba(59, 130, 246, 0.1);
            border-radius: 6px;
            margin-bottom: 5px;
        }
        
        .vehicle-name {
            font-weight: 600;
            font-size: 14px;
        }
        
        .vehicle-type {
            font-size: 12px;
            color: var(--text-light);
        }
        
        /* Responsive */
        @media (max-width: 992px) {
            .dashboard-container {
                padding: 0 25px 30px;
            }
            
            .dashboard-header {
                padding: 40px 25px 30px;
            }
            
            .dashboard-header .header-content h1 {
                font-size: 32px;
            }
            
            .filter-grid {
                grid-template-columns: 1fr;
            }
        }
        
        @media (max-width: 768px) {
            .stats-grid {
                grid-template-columns: 1fr;
            }
            
            .details-grid {
                grid-template-columns: 1fr;
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

        
        /* New filter type */
        .type-filter {
            display: flex;
            gap: 10px;
            margin-bottom: 15px;
        }
        
        .type-btn {
            padding: 8px 16px;
            border: 2px solid var(--border-color);
            border-radius: 8px;
            background: var(--card-bg);
            color: var(--text-color);
            cursor: pointer;
            font-weight: 600;
            transition: all 0.3s ease;
        }
        
        .type-btn.active {
            border-color: var(--primary-color);
            background: var(--primary-color);
            color: white;
        }
        
        .type-btn:hover:not(.active) {
            border-color: var(--primary-color);
            background: var(--gray-100);
        }
        
        /* New status indicator for suggestions */
        .suggestion-indicator {
            background: linear-gradient(135deg, var(--warning), #d97706);
            color: white;
            padding: 2px 8px;
            border-radius: 12px;
            font-size: 10px;
            font-weight: 600;
            text-transform: uppercase;
            margin-left: 5px;
        }
        
        .dispatch-indicator {
            background: linear-gradient(135deg, var(--info), #2563eb);
            color: white;
            padding: 2px 8px;
            border-radius: 12px;
            font-size: 10px;
            font-weight: 600;
            text-transform: uppercase;
            margin-left: 5px;
        }
        
        /* Timeline for suggestions */
        .suggestion-timeline {
            font-size: 12px;
            color: var(--text-light);
            margin-top: 5px;
        }
        
        .timeline-item {
            display: flex;
            align-items: center;
            gap: 5px;
            margin-bottom: 2px;
        }
        
        .timeline-dot {
            width: 6px;
            height: 6px;
            border-radius: 50%;
            background: var(--warning);
        }
        
        .timeline-dot.dispatched {
            background: var(--info);
        }
        
        .timeline-dot.completed {
            background: var(--success);
        }
        
        /* Action buttons for suggestions */
        .suggestion-actions {
            display: flex;
            gap: 5px;
            margin-top: 10px;
        }
        
        /* Notes display */
        .notes-display {
            background: var(--gray-100);
            border-radius: 8px;
            padding: 10px;
            margin-top: 10px;
            font-size: 12px;
            color: var(--text-light);
            max-height: 60px;
            overflow-y: auto;
        }
        
        .dark-mode .notes-display {
            background: var(--gray-800);
        }
        
        /* Additional table columns */
        .action-column {
            white-space: nowrap;
        }
        
        /* Show different dates for suggestions vs dispatches */
        .date-display {
            font-size: 12px;
            color: var(--text-light);
        }
        
        .date-label {
            font-weight: 600;
            color: var(--text-color);
            font-size: 11px;
        }
    </style>
</head>
<body>
    <!-- Dispatch Details Modal -->
    <div class="modal" id="detailsModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class='bx bx-detail'></i> Dispatch Details</h3>
                <button type="button" onclick="closeModal('detailsModal')">&times;</button>
            </div>
            <div class="modal-body">
                <div id="modalContent">
                    <!-- Content will be loaded here -->
                </div>
            </div>
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
                    <a href="#" class="menu-item" id="dashboard-menu">
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
                        <a href="../users/manage_users.php" class="submenu-item">Manage Users</a>
                        <a href="../users/role_control.php" class="submenu-item">Role Control</a>
                        <a href="../users/monitor_activity.php" class="submenu-item">Audit & Activity Logs</a>
                       
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
                    <div id="incident-management" class="submenu active">
                     
                        <a href="receive_data.php" class="submenu-item">Recieve Data</a>
                         <a href="track_status.php" class="submenu-item active">Track Status</a>
                        <a href="update_status.php" class="submenu-item">Update Status</a>
                        <a href="incidents_analytics.php" class="submenu-item">Incidents Analytics</a>
                        
                    </div>
                    
                    <!-- Barangay Volunteer Roster Management -->
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
                    <div class="menu-item" onclick="toggleSubmenu('resource-management')">
                        <div class="icon-box icon-bg-green">
                            <i class='bx bxs-cube icon-green'></i>
                        </div>
                        <span class="font-medium">Resource Management</span>
                        <svg class="dropdown-arrow menu-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                        </svg>
                    </div>
                    <div id="resource-management" class="submenu">
                        <a href="../rm/view_equipment.php" class="submenu-item">View Equipment</a>
                        <a href="../rm/approve_maintenance.php" class="submenu-item">Approve Maintenance</a>
                        <a href="../rm/approve_resources.php" class="submenu-item">Approve Resources</a>
                        <a href="../rm/review_deployment.php" class="submenu-item">Review Deployment</a>
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
                        <a href="../sm/approve_shifts.php" class="submenu-item">Approve Shifts</a>
                        <a href="../sm/override_assignments.php" class="submenu-item">Override Assignments</a>
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
                        <a href="../tc/approve_completions.php" class="submenu-item">Approve Completions</a>
                        <a href="../tc/view_training_records.php" class="submenu-item">View Records</a>
                        <a href="../tc/assign_training.php" class="submenu-item">Assign Training</a>
                        <a href="../tc/track_expiry.php" class="submenu-item">Track Expiry</a>
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
                    
                    <a href="../includes/logout.php" class="menu-item">
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
                                <p class="user-email"><?php echo $role; ?> - Dispatch Coordinator</p>
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
                            <h1><i class='bx bx-radar'></i> Track Dispatch Status</h1>
                            <p>Monitor your suggestions and track dispatch approvals from Emergency Response</p>
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
                            <div class="stat-icon" style="background: rgba(245, 158, 11, 0.1); color: var(--warning);">
                                <i class='bx bx-time-five'></i>
                            </div>
                            <div class="stat-content">
                                <div class="value"><?php echo $stats['pending_suggestions'] ?? 0; ?></div>
                                <div class="label">Pending Suggestions</div>
                            </div>
                        </div>
                        <div class="stat-card">
                            <div class="stat-icon" style="background: rgba(59, 130, 246, 0.1); color: #3b82f6;">
                                <i class='bx bx-radar'></i>
                            </div>
                            <div class="stat-content">
                                <div class="value"><?php echo $stats['active_dispatches'] ?? 0; ?></div>
                                <div class="label">Active Dispatches</div>
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
                            <div class="stat-icon" style="background: rgba(139, 92, 246, 0.1); color: var(--purple);">
                                <i class='bx bx-line-chart'></i>
                            </div>
                            <div class="stat-content">
                                <div class="value">
                                    <?php 
                                    if ($stats['avg_dispatch_time']) {
                                        echo round($stats['avg_dispatch_time']) . 'm';
                                    } else {
                                        echo '0m';
                                    }
                                    ?>
                                </div>
                                <div class="label">Avg Approval Time</div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Type Filter -->
                    <div class="filter-section">
                        <div class="filter-header">
                            <h3><i class='bx bx-filter-alt'></i> View Type</h3>
                        </div>
                        <div class="type-filter">
                            <button type="button" class="type-btn <?php echo $type_filter === 'all' ? 'active' : ''; ?>" onclick="setTypeFilter('all')">
                                All Activities
                            </button>
                            <button type="button" class="type-btn <?php echo $type_filter === 'suggested' ? 'active' : ''; ?>" onclick="setTypeFilter('suggested')">
                                My Suggestions
                            </button>
                            <button type="button" class="type-btn <?php echo $type_filter === 'dispatched' ? 'active' : ''; ?>" onclick="setTypeFilter('dispatched')">
                                Dispatched
                            </button>
                        </div>
                    </div>
                    
                    <!-- Filter Section -->
                    <div class="filter-section">
                        <div class="filter-header">
                            <h3><i class='bx bx-filter-alt'></i> Filter Dispatches</h3>
                        </div>
                        <form method="GET" id="filterForm">
                            <input type="hidden" name="type" id="typeFilter" value="<?php echo $type_filter; ?>">
                            <div class="filter-grid">
                                <div class="filter-group">
                                    <label class="filter-label">Status</label>
                                    <select class="filter-select" name="status" id="statusFilter">
                                        <option value="all" <?php echo $status_filter === 'all' ? 'selected' : ''; ?>>All Statuses</option>
                                        <option value="active" <?php echo $status_filter === 'active' ? 'selected' : ''; ?>>Active (Dispatched/En Route/Arrived)</option>
                                        <option value="pending" <?php echo $status_filter === 'pending' ? 'selected' : ''; ?>>Pending Suggestions</option>
                                        <option value="dispatched" <?php echo $status_filter === 'dispatched' ? 'selected' : ''; ?>>Dispatched</option>
                                        <option value="en_route" <?php echo $status_filter === 'en_route' ? 'selected' : ''; ?>>En Route</option>
                                        <option value="arrived" <?php echo $status_filter === 'arrived' ? 'selected' : ''; ?>>Arrived</option>
                                        <option value="completed" <?php echo $status_filter === 'completed' ? 'selected' : ''; ?>>Completed</option>
                                        <option value="cancelled" <?php echo $status_filter === 'cancelled' ? 'selected' : ''; ?>>Cancelled</option>
                                    </select>
                                </div>
                                <div class="filter-group">
                                    <label class="filter-label">Unit</label>
                                    <select class="filter-select" name="unit" id="unitFilter">
                                        <option value="all" <?php echo $unit_filter === 'all' ? 'selected' : ''; ?>>All Units</option>
                                        <?php foreach ($all_units as $unit): ?>
                                            <option value="<?php echo $unit['id']; ?>" <?php echo $unit_filter == $unit['id'] ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($unit['unit_name'] . ' (' . $unit['unit_code'] . ')'); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="filter-group">
                                    <label class="filter-label">Severity</label>
                                    <select class="filter-select" name="severity" id="severityFilter">
                                        <option value="all" <?php echo $severity_filter === 'all' ? 'selected' : ''; ?>>All Severities</option>
                                        <option value="critical" <?php echo $severity_filter === 'critical' ? 'selected' : ''; ?>>Critical</option>
                                        <option value="high" <?php echo $severity_filter === 'high' ? 'selected' : ''; ?>>High</option>
                                        <option value="medium" <?php echo $severity_filter === 'medium' ? 'selected' : ''; ?>>Medium</option>
                                        <option value="low" <?php echo $severity_filter === 'low' ? 'selected' : ''; ?>>Low</option>
                                    </select>
                                </div>
                                <div class="filter-group">
                                    <label class="filter-label">Date</label>
                                    <input type="date" class="filter-input" name="date" id="dateFilter" value="<?php echo $date_filter; ?>">
                                </div>
                            </div>
                            <div class="filter-actions">
                                <button type="submit" class="btn btn-primary">
                                    <i class='bx bx-filter'></i> Apply Filters
                                </button>
                                <button type="button" class="btn btn-secondary" onclick="resetFilters()">
                                    <i class='bx bx-reset'></i> Reset Filters
                                </button>
                            </div>
                        </form>
                    </div>
                    
                    <!-- Status Summary -->
                    <div class="filter-section">
                        <div class="filter-header">
                            <h3><i class='bx bx-stats'></i> Status Summary</h3>
                            <span class="status-badge status-<?php echo $status_filter; ?>">
                                <?php 
                                if ($status_filter === 'all') echo 'All';
                                elseif ($status_filter === 'active') echo 'Active';
                                else echo ucfirst($status_filter); 
                                ?>
                            </span>
                            <?php if ($type_filter !== 'all'): ?>
                                <span class="<?php echo $type_filter === 'suggested' ? 'suggestion-indicator' : 'dispatch-indicator'; ?>">
                                    <?php echo ucfirst($type_filter); ?>
                                </span>
                            <?php endif; ?>
                        </div>
                        <div class="details-grid">
                            <div class="detail-card">
                                <h4>Total</h4>
                                <p><?php echo $total_dispatches; ?></p>
                            </div>
                            <div class="detail-card">
                                <h4>Suggestions</h4>
                                <p style="color: var(--warning);"><?php echo $pending_count; ?></p>
                            </div>
                            <div class="detail-card">
                                <h4>Active</h4>
                                <p style="color: var(--info);"><?php echo $dispatched_count + $en_route_count + $arrived_count; ?></p>
                            </div>
                            <div class="detail-card">
                                <h4>Completed</h4>
                                <p style="color: var(--success);"><?php echo $completed_count; ?></p>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Dispatch Table -->
                    <div class="dispatch-table-section">
                        <div class="table-header">
                            <h3><i class='bx bx-list-ul'></i> Dispatch Tracking</h3>
                            <span><?php echo $total_dispatches; ?> record<?php echo $total_dispatches !== 1 ? 's' : ''; ?> found</span>
                        </div>
                        <div class="table-container">
                            <?php if (count($dispatches) > 0): ?>
                                <table class="dispatch-table">
                                    <thead>
                                        <tr>
                                            <th>ID</th>
                                            <th>Incident</th>
                                            <th>Status & Progress</th>
                                            <th>Unit</th>
                                            <th>Timeline</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($dispatches as $dispatch): ?>
                                            <?php
                                            // Format dates based on status
                                            if ($dispatch['status'] === 'pending') {
                                                // This is a suggestion
                                                $suggested_at = new DateTime($dispatch['dispatched_at']);
                                                $suggested_str = $suggested_at->format('M d, Y H:i');
                                                $time_since_suggestion = getTimeAgo($suggested_at);
                                                $is_suggestion = true;
                                            } else {
                                                // This has been dispatched
                                                $dispatched_at = new DateTime($dispatch['status_updated_at']);
                                                $dispatched_str = $dispatched_at->format('M d, Y H:i');
                                                $time_since_dispatch = getTimeAgo($dispatched_at);
                                                $is_suggestion = false;
                                                
                                                // If it was suggested first, show suggestion date too
                                                if ($dispatch['dispatched_at']) {
                                                    $suggested_at = new DateTime($dispatch['dispatched_at']);
                                                    $suggested_str = $suggested_at->format('M d, Y H:i');
                                                    $approval_time = $dispatch['suggestion_to_dispatch_minutes'] ?? null;
                                                }
                                            }
                                            
                                            // Get progress class
                                            $progress_class = 'progress-' . $dispatch['status'];
                                            
                                            // Determine who suggested and who dispatched
                                            $suggested_by = $dispatch['suggested_by_first'] ? 
                                                htmlspecialchars($dispatch['suggested_by_first'] . ' ' . $dispatch['suggested_by_last']) : 
                                                'Unknown';
                                            
                                            $dispatched_by = $dispatch['dispatched_by_first'] ? 
                                                htmlspecialchars($dispatch['dispatched_by_first'] . ' ' . $dispatch['dispatched_by_last']) : 
                                                'ER Team';
                                            
                                            // FIX: Get the correct vehicle count
                                            // Use json_vehicle_count if available, otherwise fall back to vehicle_count
                                            $vehicle_count = $dispatch['json_vehicle_count'] > 0 ? $dispatch['json_vehicle_count'] : $dispatch['vehicle_count'];
                                            ?>
                                            <tr>
                                                <td>
                                                    <strong>#<?php echo $dispatch['id']; ?></strong>
                                                    <?php if ($is_suggestion): ?>
                                                        <div class="suggestion-indicator">Suggested</div>
                                                    <?php else: ?>
                                                        <div class="dispatch-indicator">Dispatched</div>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <div>
                                                        <strong><?php echo htmlspecialchars($dispatch['title']); ?></strong>
                                                        <div style="font-size: 12px; color: var(--text-light);">
                                                            <?php echo htmlspecialchars($dispatch['location']); ?>
                                                        </div>
                                                        <div style="font-size: 11px; color: var(--text-light);">
                                                            <span class="severity-badge severity-<?php echo strtolower($dispatch['severity']); ?>">
                                                                <?php echo ucfirst($dispatch['severity']); ?>
                                                            </span>
                                                            • <?php echo htmlspecialchars($dispatch['emergency_type']); ?>
                                                            <?php if ($dispatch['rescue_category']): ?>
                                                                • <?php echo str_replace('_', ' ', $dispatch['rescue_category']); ?>
                                                            <?php endif; ?>
                                                        </div>
                                                    </div>
                                                </td>
                                                <td>
                                                    <div class="status-progress">
                                                        <span class="status-badge status-<?php echo $dispatch['status']; ?>">
                                                            <?php echo ucfirst(str_replace('_', ' ', $dispatch['status'])); ?>
                                                        </span>
                                                        <div class="progress-bar <?php echo $progress_class; ?>">
                                                            <div class="progress-fill"></div>
                                                        </div>
                                                    </div>
                                                    
                                                    <?php if ($dispatch['er_notes']): ?>
                                                        <div class="notes-display" title="<?php echo htmlspecialchars($dispatch['er_notes']); ?>">
                                                            <i class='bx bx-note'></i> 
                                                            <?php 
                                                            $notes_preview = substr($dispatch['er_notes'], 0, 50);
                                                            echo htmlspecialchars($notes_preview) . (strlen($dispatch['er_notes']) > 50 ? '...' : '');
                                                            ?>
                                                        </div>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <div>
                                                        <strong><?php echo htmlspecialchars($dispatch['unit_name']); ?></strong>
                                                        <div style="font-size: 12px; color: var(--text-light);">
                                                            <?php echo htmlspecialchars($dispatch['unit_code']); ?> • <?php echo htmlspecialchars($dispatch['unit_type']); ?>
                                                        </div>
                                                        <?php if ($vehicle_count > 0): ?>
                                                            <div style="font-size: 11px; color: var(--text-light);">
                                                                <i class='bx bx-car'></i> <?php echo $vehicle_count; ?> vehicle<?php echo $vehicle_count !== 1 ? 's' : ''; ?>
                                                            </div>
                                                        <?php endif; ?>
                                                    </div>
                                                </td>
                                                <td>
                                                    <div class="suggestion-timeline">
                                                        <?php if ($is_suggestion): ?>
                                                            <div class="timeline-item">
                                                                <div class="timeline-dot"></div>
                                                                <div>
                                                                    <span class="date-label">Suggested:</span> 
                                                                    <?php echo $suggested_str; ?>
                                                                </div>
                                                            </div>
                                                            <div class="timeline-item">
                                                                <div class="timeline-dot dispatched"></div>
                                                                <div>
                                                                    <span class="date-label">By:</span> 
                                                                    <?php echo $suggested_by; ?>
                                                                </div>
                                                            </div>
                                                            <div style="font-size: 11px; color: var(--warning); margin-top: 3px;">
                                                                <i class='bx bx-time'></i> Waiting for ER approval (<?php echo $time_since_suggestion; ?>)
                                                            </div>
                                                        <?php else: ?>
                                                            <div class="timeline-item">
                                                                <div class="timeline-dot"></div>
                                                                <div>
                                                                    <span class="date-label">Suggested:</span> 
                                                                    <?php echo $suggested_str ?? 'N/A'; ?>
                                                                </div>
                                                            </div>
                                                            <div class="timeline-item">
                                                                <div class="timeline-dot dispatched"></div>
                                                                <div>
                                                                    <span class="date-label">Dispatched:</span> 
                                                                    <?php echo $dispatched_str; ?>
                                                                </div>
                                                            </div>
                                                            <div class="timeline-item">
                                                                <div>
                                                                    <span class="date-label">By:</span> 
                                                                    <?php echo $dispatched_by; ?>
                                                                </div>
                                                            </div>
                                                            <?php if (isset($approval_time) && $approval_time > 0): ?>
                                                                <div style="font-size: 11px; color: var(--success); margin-top: 3px;">
                                                                    <i class='bx bx-check'></i> Approved in <?php echo $approval_time; ?> minutes
                                                                </div>
                                                            <?php endif; ?>
                                                        <?php endif; ?>
                                                    </div>
                                                </td>
                                                <td class="action-column">
                                                    <div style="display: flex; gap: 5px;">
                                                        <button class="btn btn-sm btn-secondary" onclick="viewDispatchDetails(<?php echo $dispatch['id']; ?>)">
                                                            <i class='bx bx-detail'></i>
                                                        </button>
                                                        <?php if ($dispatch['status'] === 'pending'): ?>
                                                            <button class="btn btn-sm btn-warning" onclick="editSuggestion(<?php echo $dispatch['id']; ?>)">
                                                                <i class='bx bx-edit'></i>
                                                            </button>
                                                        <?php else: ?>
                                                            <a href="send_dispatch.php?dispatch_id=<?php echo $dispatch['id']; ?>" class="btn btn-sm btn-primary">
                                                                <i class='bx bx-show'></i>
                                                            </a>
                                                        <?php endif; ?>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            <?php else: ?>
                                <div class="no-data">
                                    <i class='bx bx-search-alt'></i>
                                    <p>No dispatches found</p>
                                    <p class="subtext">Try adjusting your filters</p>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <script>
        // Modal functions
        function viewDispatchDetails(dispatchId) {
            fetch(`get_dispatch_details.php?id=${dispatchId}`)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        const dispatch = data.dispatch;
                        const modalContent = document.getElementById('modalContent');
                        
                        let html = `
                            <div class="details-grid">
                                <div class="detail-card">
                                    <h4>Dispatch ID</h4>
                                    <p>#${dispatch.id}</p>
                                </div>
                                <div class="detail-card">
                                    <h4>Status</h4>
                                    <p><span class="status-badge status-${dispatch.status}">${dispatch.status.replace('_', ' ')}</span></p>
                                </div>
                                <div class="detail-card">
                                    <h4>Severity</h4>
                                    <p><span class="severity-badge severity-${dispatch.severity}">${dispatch.severity}</span></p>
                                </div>
                            </div>
                            
                            <div class="form-group">
                                <label class="form-label">Incident</label>
                                <p><strong>${dispatch.title}</strong></p>
                                <p>${dispatch.location}</p>
                                <p>${dispatch.emergency_type} ${dispatch.rescue_category ? '• ' + dispatch.rescue_category.replace('_', ' ') : ''}</p>
                            </div>
                            
                            <div class="form-group">
                                <label class="form-label">Unit</label>
                                <p><strong>${dispatch.unit_name} (${dispatch.unit_code})</strong></p>
                                <p>${dispatch.unit_type} • ${dispatch.unit_location}</p>
                                ${dispatch.vehicle_count ? `<p><i class='bx bx-car'></i> ${dispatch.vehicle_count} vehicle${dispatch.vehicle_count !== 1 ? 's' : ''}</p>` : ''}
                            </div>
                            
                            <div class="form-group">
                                <label class="form-label">Timeline</label>
                                ${dispatch.suggested_by ? `<p><strong>Suggested by:</strong> ${dispatch.suggested_by} on ${new Date(dispatch.dispatched_at).toLocaleString()}</p>` : ''}
                                ${dispatch.dispatched_by ? `<p><strong>Dispatched by:</strong> ${dispatch.dispatched_by} on ${dispatch.status_updated_at ? new Date(dispatch.status_updated_at).toLocaleString() : 'N/A'}</p>` : ''}
                                ${dispatch.suggestion_to_dispatch_minutes ? `<p><strong>Approval Time:</strong> ${dispatch.suggestion_to_dispatch_minutes} minutes</p>` : ''}
                                ${dispatch.dispatch_to_complete_minutes ? `<p><strong>Response Time:</strong> ${dispatch.dispatch_to_complete_minutes} minutes</p>` : ''}
                            </div>
                            
                            <div class="form-group">
                                <label class="form-label">Description</label>
                                <p>${dispatch.description || 'No description provided'}</p>
                            </div>
                            
                            <div class="form-group">
                                <label class="form-label">Caller Information</label>
                                <p>${dispatch.caller_name} - ${dispatch.caller_phone}</p>
                            </div>
                            
                            ${dispatch.vehicles && dispatch.vehicles.length > 0 ? `
                            <div class="form-group">
                                <label class="form-label">Assigned Vehicles (${dispatch.vehicles.length})</label>
                                <div class="vehicle-list">
                                    ${dispatch.vehicles.map(vehicle => `
                                        <div class="vehicle-item">
                                            <i class='bx bx-car' style="color: #3b82f6;"></i>
                                            <div>
                                                <div class="vehicle-name">${vehicle.vehicle_name || vehicle.name || 'Unknown'}</div>
                                                <div class="vehicle-type">${vehicle.type || 'Unknown type'}</div>
                                            </div>
                                        </div>
                                    `).join('')}
                                </div>
                            </div>
                            ` : ''}
                            
                            ${dispatch.er_notes ? `
                            <div class="form-group">
                                <label class="form-label">ER Notes</label>
                                <div class="notes-display" style="max-height: 150px;">
                                    ${dispatch.er_notes}
                                </div>
                            </div>
                            ` : ''}
                        `;
                        
                        modalContent.innerHTML = html;
                        document.getElementById('detailsModal').classList.add('active');
                    } else {
                        alert('Error loading dispatch details: ' + (data.message || 'Unknown error'));
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('Error loading dispatch details. Check console for details.');
                });
        }
        
        function closeModal(modalId) {
            document.getElementById(modalId).classList.remove('active');
        }
        
        // Filter functions
        function setTypeFilter(type) {
            document.getElementById('typeFilter').value = type;
            document.getElementById('filterForm').submit();
        }
        
        function resetFilters() {
            document.getElementById('statusFilter').value = 'all';
            document.getElementById('unitFilter').value = 'all';
            document.getElementById('severityFilter').value = 'all';
            document.getElementById('dateFilter').value = '';
            document.getElementById('typeFilter').value = 'all';
            document.getElementById('filterForm').submit();
        }
        
        function editSuggestion(suggestionId) {
            // Redirect to select_unit.php with the suggestion ID to edit
            window.location.href = `select_unit.php?suggestion_id=${suggestionId}`;
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
            
            // Search functionality
            const searchInput = document.getElementById('search-input');
            searchInput.addEventListener('keyup', function(e) {
                if (e.key === 'Enter') {
                    const searchTerm = this.value.toLowerCase();
                    const rows = document.querySelectorAll('.dispatch-table tbody tr');
                    
                    rows.forEach(row => {
                        const text = row.textContent.toLowerCase();
                        if (text.includes(searchTerm)) {
                            row.style.display = '';
                        } else {
                            row.style.display = 'none';
                        }
                    });
                }
            });
            
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