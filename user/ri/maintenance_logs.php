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

// Get maintenance logs - requests made by this user
$maintenance_query = "
    SELECT 
        mr.id,
        mr.resource_id,
        mr.requested_by,
        mr.request_type,
        mr.priority,
        mr.description,
        mr.requested_date,
        mr.scheduled_date,
        mr.estimated_cost,
        mr.status,
        mr.approved_by,
        mr.approved_date,
        mr.completed_by,
        mr.completed_date,
        mr.notes,
        r.resource_name,
        r.resource_type,
        r.category,
        r.condition_status,
        u.unit_name,
        requested_user.first_name as requester_first_name,
        requested_user.last_name as requester_last_name,
        approved_user.first_name as approver_first_name,
        approved_user.last_name as approver_last_name,
        completed_user.first_name as completer_first_name,
        completed_user.last_name as completer_last_name
    FROM maintenance_requests mr
    JOIN resources r ON mr.resource_id = r.id
    LEFT JOIN units u ON r.unit_id = u.id
    LEFT JOIN users requested_user ON mr.requested_by = requested_user.id
    LEFT JOIN users approved_user ON mr.approved_by = approved_user.id
    LEFT JOIN users completed_user ON mr.completed_by = completed_user.id
    WHERE mr.requested_by = ?
    ORDER BY mr.requested_date DESC
";

$maintenance_stmt = $pdo->prepare($maintenance_query);
$maintenance_stmt->execute([$user_id]);
$maintenance_logs = $maintenance_stmt->fetchAll();

// Get service history for equipment in volunteer's unit
$service_history_query = "
    SELECT 
        sh.id,
        sh.resource_id,
        sh.maintenance_id,
        sh.service_type,
        sh.service_date,
        sh.next_service_date,
        sh.performed_by,
        sh.performed_by_id,
        sh.service_provider,
        sh.cost,
        sh.parts_replaced,
        sh.labor_hours,
        sh.service_notes,
        sh.status_after_service,
        sh.documentation,
        sh.created_at,
        sh.updated_at,
        r.resource_name,
        r.resource_type,
        r.category,
        r.unit_id,
        u.unit_name,
        performer_user.first_name as performer_first_name,
        performer_user.last_name as performer_last_name
    FROM service_history sh
    JOIN resources r ON sh.resource_id = r.id
    LEFT JOIN units u ON r.unit_id = u.id
    LEFT JOIN users performer_user ON sh.performed_by_id = performer_user.id
    WHERE r.unit_id = ? OR sh.performed_by_id = ?
    ORDER BY sh.service_date DESC, sh.created_at DESC
    LIMIT 50
";

$service_history_stmt = $pdo->prepare($service_history_query);
$service_history_stmt->execute([$unit_id, $user_id]);
$service_history = $service_history_stmt->fetchAll();

// Calculate statistics
$total_requests = count($maintenance_logs);
$pending_requests = count(array_filter($maintenance_logs, function($log) {
    return $log['status'] === 'pending';
}));
$approved_requests = count(array_filter($maintenance_logs, function($log) {
    return $log['status'] === 'approved';
}));
$in_progress_requests = count(array_filter($maintenance_logs, function($log) {
    return $log['status'] === 'in_progress';
}));
$completed_requests = count(array_filter($maintenance_logs, function($log) {
    return $log['status'] === 'completed';
}));
$rejected_requests = count(array_filter($maintenance_logs, function($log) {
    return $log['status'] === 'rejected';
}));

// Get active maintenance requests (pending + approved + in_progress)
$active_requests = array_filter($maintenance_logs, function($log) {
    return in_array($log['status'], ['pending', 'approved', 'in_progress']);
});

// Get request types for filter
$request_types = array_unique(array_column($maintenance_logs, 'request_type'));
sort($request_types);

// Get statuses for filter
$statuses = ['pending', 'approved', 'in_progress', 'completed', 'rejected', 'cancelled'];

// Handle filters
$type_filter = $_GET['type'] ?? 'all';
$status_filter = $_GET['status'] ?? 'all';
$date_from = $_GET['date_from'] ?? '';
$date_to = $_GET['date_to'] ?? '';
$search_filter = $_GET['search'] ?? '';

// Apply filters
$filtered_logs = $maintenance_logs;
if ($type_filter !== 'all') {
    $filtered_logs = array_filter($filtered_logs, function($log) use ($type_filter) {
        return $log['request_type'] === $type_filter;
    });
}

if ($status_filter !== 'all') {
    $filtered_logs = array_filter($filtered_logs, function($log) use ($status_filter) {
        return $log['status'] === $status_filter;
    });
}

if ($date_from) {
    $filtered_logs = array_filter($filtered_logs, function($log) use ($date_from) {
        return strtotime($log['requested_date']) >= strtotime($date_from);
    });
}

if ($date_to) {
    $filtered_logs = array_filter($filtered_logs, function($log) use ($date_to) {
        return strtotime($log['requested_date']) <= strtotime($date_to . ' 23:59:59');
    });
}

if ($search_filter) {
    $search_lower = strtolower($search_filter);
    $filtered_logs = array_filter($filtered_logs, function($log) use ($search_lower) {
        $name = strtolower($log['resource_name']);
        $description = strtolower($log['description']);
        $notes = strtolower($log['notes'] ?? '');
        
        return strpos($name, $search_lower) !== false || 
               strpos($description, $search_lower) !== false ||
               strpos($notes, $search_lower) !== false;
    });
}

// Get recent service history (last 7 days)
$recent_service = array_filter($service_history, function($service) {
    $service_date = strtotime($service['service_date']);
    $week_ago = strtotime('-7 days');
    return $service_date >= $week_ago;
});

// Calculate total estimated cost
$total_estimated_cost = array_sum(array_column($maintenance_logs, 'estimated_cost'));

// Calculate average completion time (for completed requests)
$completed_with_dates = array_filter($maintenance_logs, function($log) {
    return $log['status'] === 'completed' && $log['requested_date'] && $log['completed_date'];
});

$total_completion_days = 0;
foreach ($completed_with_dates as $log) {
    $requested = new DateTime($log['requested_date']);
    $completed = new DateTime($log['completed_date']);
    $total_completion_days += $requested->diff($completed)->days;
}

$average_completion_days = count($completed_with_dates) > 0 ? 
    round($total_completion_days / count($completed_with_dates), 1) : 0;

// Close statements
$stmt = null;
$volunteer_stmt = null;
$maintenance_stmt = null;
$service_history_stmt = null;
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Maintenance Logs - Fire & Rescue Services Management</title>
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

        .maintenance-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
        }

        .maintenance-table th {
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

        .maintenance-table td {
            padding: 15px;
            border-bottom: 1px solid var(--border-color);
            font-size: 14px;
        }

        .maintenance-table tr:hover {
            background: var(--gray-100);
        }

        .dark-mode .maintenance-table tr:hover {
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

        .status-pending {
            background: rgba(59, 130, 246, 0.1);
            color: var(--info);
            border: 1px solid rgba(59, 130, 246, 0.2);
        }

        .status-approved {
            background: rgba(16, 185, 129, 0.1);
            color: var(--success);
            border: 1px solid rgba(16, 185, 129, 0.2);
        }

        .status-in_progress {
            background: rgba(245, 158, 11, 0.1);
            color: var(--warning);
            border: 1px solid rgba(245, 158, 11, 0.2);
        }

        .status-completed {
            background: rgba(16, 185, 129, 0.1);
            color: var(--success);
            border: 1px solid rgba(16, 185, 129, 0.2);
        }

        .status-rejected {
            background: rgba(220, 38, 38, 0.1);
            color: var(--danger);
            border: 1px solid rgba(220, 38, 38, 0.2);
        }

        .status-cancelled {
            background: rgba(107, 114, 128, 0.1);
            color: var(--gray-500);
            border: 1px solid rgba(107, 114, 128, 0.2);
        }

        .priority-badge {
            padding: 4px 10px;
            border-radius: 6px;
            font-size: 11px;
            font-weight: 600;
            display: inline-block;
        }

        .priority-critical {
            background: rgba(220, 38, 38, 0.1);
            color: var(--danger);
            border: 1px solid rgba(220, 38, 38, 0.2);
        }

        .priority-high {
            background: rgba(245, 158, 11, 0.1);
            color: var(--warning);
            border: 1px solid rgba(245, 158, 11, 0.2);
        }

        .priority-medium {
            background: rgba(59, 130, 246, 0.1);
            color: var(--info);
            border: 1px solid rgba(59, 130, 246, 0.2);
        }

        .priority-low {
            background: rgba(107, 114, 128, 0.1);
            color: var(--gray-500);
            border: 1px solid rgba(107, 114, 128, 0.2);
        }

        .type-badge {
            padding: 4px 10px;
            border-radius: 6px;
            font-size: 11px;
            font-weight: 600;
            display: inline-block;
            background: var(--gray-100);
            color: var(--gray-700);
            border: 1px solid var(--gray-200);
        }

        .dark-mode .type-badge {
            background: var(--gray-800);
            color: var(--gray-300);
            border-color: var(--gray-700);
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

        .tab-container {
            margin-bottom: 20px;
        }

        .tab-buttons {
            display: flex;
            border-bottom: 2px solid var(--border-color);
            flex-wrap: wrap;
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

        .timeline {
            position: relative;
            padding-left: 30px;
            margin-top: 20px;
        }

        .timeline::before {
            content: '';
            position: absolute;
            left: 10px;
            top: 0;
            bottom: 0;
            width: 2px;
            background: var(--border-color);
        }

        .timeline-item {
            position: relative;
            margin-bottom: 20px;
            padding-left: 20px;
        }

        .timeline-item::before {
            content: '';
            position: absolute;
            left: -10px;
            top: 5px;
            width: 12px;
            height: 12px;
            border-radius: 50%;
            background: var(--primary-color);
            border: 2px solid var(--background-color);
        }

        .timeline-date {
            font-size: 12px;
            color: var(--text-light);
            margin-bottom: 5px;
        }

        .timeline-content {
            background: var(--background-color);
            border: 1px solid var(--border-color);
            border-radius: 8px;
            padding: 15px;
        }

        .timeline-title {
            font-weight: 600;
            margin-bottom: 5px;
            color: var(--text-color);
        }

        .timeline-description {
            font-size: 13px;
            color: var(--text-light);
            margin-bottom: 10px;
        }

        .timeline-meta {
            display: flex;
            justify-content: space-between;
            font-size: 11px;
            color: var(--text-light);
        }

        .cost-badge {
            padding: 4px 10px;
            border-radius: 6px;
            font-size: 11px;
            font-weight: 600;
            background: rgba(16, 185, 129, 0.1);
            color: var(--success);
            border: 1px solid rgba(16, 185, 129, 0.2);
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
            max-width: 800px;
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

        .details-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-bottom: 20px;
        }

        .detail-group {
            margin-bottom: 15px;
        }

        .detail-label {
            font-size: 12px;
            color: var(--text-light);
            margin-bottom: 5px;
            font-weight: 600;
        }

        .detail-value {
            font-size: 14px;
            color: var(--text-color);
        }

        .service-history-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
        }

        .service-history-table th {
            background: var(--card-bg);
            padding: 12px;
            text-align: left;
            font-weight: 600;
            color: var(--text-color);
            border-bottom: 2px solid var(--border-color);
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }

        .service-history-table td {
            padding: 12px;
            border-bottom: 1px solid var(--border-color);
            font-size: 13px;
        }

        .alert-card {
            background: linear-gradient(135deg, #fef3c7 0%, #fde68a 100%);
            border: 1px solid #fbbf24;
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 20px;
        }

        .dark-mode .alert-card {
            background: linear-gradient(135deg, #78350f 0%, #92400e 100%);
            border-color: #f59e0b;
        }

        .alert-title {
            font-size: 16px;
            font-weight: 700;
            color: #92400e;
            margin-bottom: 10px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .dark-mode .alert-title {
            color: #fbbf24;
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
            
            .maintenance-table {
                display: block;
                overflow-x: auto;
            }
            
            .tab-buttons {
                flex-direction: column;
            }
            
            .details-grid {
                grid-template-columns: 1fr;
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
            
            .tab-button {
                width: 100%;
                text-align: left;
            }
            
            .filter-actions {
                flex-direction: column;
            }
            
            .timeline {
                padding-left: 20px;
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
                        <a href="equipment_list.php" class="submenu-item">Equipment List</a>
                        <a href="stock_levels.php" class="submenu-item">Stock Levels</a>
                        <a href="maintenance_logs.php" class="submenu-item active">Maintenance Logs</a>
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
                            <input type="text" placeholder="Search maintenance logs..." class="search-input" id="search-input">
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
                        <h1 class="dashboard-title">Maintenance Logs</h1>
                        <p class="dashboard-subtitle">Track your maintenance requests and service history</p>
                    </div>
                </div>
                
                <!-- Content Container -->
                <div class="content-container">
                    <!-- Unit Information -->
                    <?php if ($unit_name): ?>
                        <div class="unit-info-card">
                            <h3 class="unit-title">
                                <i class='bx bx-group'></i>
                                Your Unit: <?php echo $unit_name; ?>
                            </h3>
                            <div class="unit-details">
                                <div class="unit-detail">
                                    <span class="unit-label">Your Requests</span>
                                    <span class="unit-value"><?php echo $total_requests; ?> requests</span>
                                </div>
                                <div class="unit-detail">
                                    <span class="unit-label">Active Requests</span>
                                    <span class="unit-value" style="color: var(--warning);">
                                        <?php echo count($active_requests); ?>
                                    </span>
                                </div>
                                <div class="unit-detail">
                                    <span class="unit-label">Completed</span>
                                    <span class="unit-value" style="color: var(--success);">
                                        <?php echo $completed_requests; ?>
                                    </span>
                                </div>
                                <div class="unit-detail">
                                    <span class="unit-label">Avg. Completion</span>
                                    <span class="unit-value"><?php echo $average_completion_days; ?> days</span>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>
                    
                    <!-- Maintenance Statistics -->
                    <div class="section-container">
                        <h3 class="section-title">
                            <i class='bx bx-stats'></i>
                            Maintenance Overview
                        </h3>
                        
                        <div class="stats-container">
                            <div class="stat-card">
                                <div class="stat-value" style="color: var(--primary-color);">
                                    <?php echo $total_requests; ?>
                                </div>
                                <div class="stat-label">Total Requests</div>
                            </div>
                            
                            <div class="stat-card">
                                <div class="stat-value" style="color: var(--info);">
                                    <?php echo $pending_requests; ?>
                                </div>
                                <div class="stat-label">Pending</div>
                            </div>
                            
                            <div class="stat-card">
                                <div class="stat-value" style="color: var(--warning);">
                                    <?php echo $in_progress_requests; ?>
                                </div>
                                <div class="stat-label">In Progress</div>
                            </div>
                            
                            <div class="stat-card">
                                <div class="stat-value" style="color: var(--success);">
                                    <?php echo $completed_requests; ?>
                                </div>
                                <div class="stat-label">Completed</div>
                            </div>
                        </div>
                        
                        <div style="margin-top: 30px;">
                            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
                                <h4 style="font-size: 16px; font-weight: 600; color: var(--text-color);">
                                    <i class='bx bx-dollar-circle'></i> Estimated Costs
                                </h4>
                                <span class="cost-badge">
                                    Total: ₱<?php echo number_format($total_estimated_cost, 2); ?>
                                </span>
                            </div>
                            
                            <div style="background: var(--card-bg); border-radius: 8px; padding: 15px;">
                                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px;">
                                    <div>
                                        <div style="font-size: 12px; color: var(--text-light); margin-bottom: 5px;">Pending Cost</div>
                                        <div style="font-size: 18px; font-weight: 700; color: var(--info);">
                                            ₱<?php 
                                            $pending_cost = array_sum(array_filter(array_column($maintenance_logs, 'estimated_cost'), function($cost, $key) use ($maintenance_logs) {
                                                return $maintenance_logs[$key]['status'] === 'pending';
                                            }, ARRAY_FILTER_USE_BOTH));
                                            echo number_format($pending_cost, 2);
                                            ?>
                                        </div>
                                    </div>
                                    <div>
                                        <div style="font-size: 12px; color: var(--text-light); margin-bottom: 5px;">Approved Cost</div>
                                        <div style="font-size: 18px; font-weight: 700; color: var(--success);">
                                            ₱<?php 
                                            $approved_cost = array_sum(array_filter(array_column($maintenance_logs, 'estimated_cost'), function($cost, $key) use ($maintenance_logs) {
                                                return $maintenance_logs[$key]['status'] === 'approved';
                                            }, ARRAY_FILTER_USE_BOTH));
                                            echo number_format($approved_cost, 2);
                                            ?>
                                        </div>
                                    </div>
                                    <div>
                                        <div style="font-size: 12px; color: var(--text-light); margin-bottom: 5px;">Completed Cost</div>
                                        <div style="font-size: 18px; font-weight: 700; color: var(--success);">
                                            ₱<?php 
                                            $completed_cost = array_sum(array_filter(array_column($maintenance_logs, 'estimated_cost'), function($cost, $key) use ($maintenance_logs) {
                                                return $maintenance_logs[$key]['status'] === 'completed';
                                            }, ARRAY_FILTER_USE_BOTH));
                                            echo number_format($completed_cost, 2);
                                            ?>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Active Requests Alert -->
                    <?php if (!empty($active_requests)): ?>
                        <div class="alert-card">
                            <h3 class="alert-title">
                                <i class='bx bx-alarm-exclamation'></i>
                                Active Maintenance Requests
                            </h3>
                            <p style="margin-bottom: 15px; color: var(--text-color);">
                                You have <?php echo count($active_requests); ?> active maintenance request(s) that need attention.
                            </p>
                            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 15px;">
                                <?php 
                                $alert_count = 0;
                                foreach ($active_requests as $request): 
                                    if ($alert_count >= 3) break;
                                ?>
                                    <div style="background: var(--background-color); border-radius: 8px; padding: 12px; border: 1px solid var(--border-color);">
                                        <div style="display: flex; justify-content: space-between; align-items: start; margin-bottom: 8px;">
                                            <strong style="font-size: 13px;"><?php echo htmlspecialchars($request['resource_name']); ?></strong>
                                            <span class="priority-badge priority-<?php echo $request['priority']; ?>">
                                                <?php echo ucfirst($request['priority']); ?>
                                            </span>
                                        </div>
                                        <div style="font-size: 12px; color: var(--text-light); margin-bottom: 5px;">
                                            <?php echo substr(htmlspecialchars($request['description']), 0, 60); ?>...
                                        </div>
                                        <div style="font-size: 11px; color: var(--text-light);">
                                            Requested: <?php echo date('M d, Y', strtotime($request['requested_date'])); ?>
                                        </div>
                                    </div>
                                <?php 
                                    $alert_count++;
                                endforeach; 
                                ?>
                            </div>
                        </div>
                    <?php endif; ?>
                    
                    <!-- Tabs -->
                    <div class="tab-container">
                        <div class="tab-buttons">
                            <button class="tab-button active" onclick="switchTab('my-requests')">
                                <i class='bx bx-list-ul'></i> My Requests
                            </button>
                            <button class="tab-button" onclick="switchTab('service-history')">
                                <i class='bx bx-history'></i> Service History
                            </button>
                            <button class="tab-button" onclick="switchTab('timeline')">
                                <i class='bx bx-timeline'></i> Timeline
                            </button>
                        </div>
                        
                        <!-- My Requests Tab -->
                        <div id="my-requests" class="tab-content active">
                            <div class="section-container">
                                <h3 class="section-title">
                                    <i class='bx bx-list-ul'></i>
                                    My Maintenance Requests
                                    <?php if (!empty($filtered_logs)): ?>
                                        <span class="badge badge-info"><?php echo count($filtered_logs); ?> requests</span>
                                    <?php endif; ?>
                                </h3>
                                
                                <!-- Filters -->
                                <div class="filter-container">
                                    <form method="GET" action="" id="filter-form">
                                        <div class="filter-group">
                                            <label class="filter-label">Search</label>
                                            <input type="text" name="search" class="filter-input" 
                                                   placeholder="Search by equipment or description..." 
                                                   value="<?php echo htmlspecialchars($search_filter); ?>">
                                        </div>
                                        
                                        <div class="filter-group">
                                            <label class="filter-label">Request Type</label>
                                            <select name="type" class="filter-select">
                                                <option value="all">All Types</option>
                                                <?php foreach ($request_types as $type): ?>
                                                    <option value="<?php echo htmlspecialchars($type); ?>" 
                                                            <?php echo $type_filter === $type ? 'selected' : ''; ?>>
                                                        <?php echo ucfirst(str_replace('_', ' ', $type)); ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        
                                        <div class="filter-group">
                                            <label class="filter-label">Status</label>
                                            <select name="status" class="filter-select">
                                                <option value="all">All Status</option>
                                                <?php foreach ($statuses as $status): ?>
                                                    <option value="<?php echo $status; ?>" 
                                                            <?php echo $status_filter === $status ? 'selected' : ''; ?>>
                                                        <?php echo ucfirst(str_replace('_', ' ', $status)); ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        
                                        <div class="filter-group">
                                            <label class="filter-label">Date Range</label>
                                            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px;">
                                                <input type="date" name="date_from" class="filter-input" 
                                                       value="<?php echo htmlspecialchars($date_from); ?>" 
                                                       placeholder="From">
                                                <input type="date" name="date_to" class="filter-input" 
                                                       value="<?php echo htmlspecialchars($date_to); ?>" 
                                                       placeholder="To">
                                            </div>
                                        </div>
                                        
                                        <div class="filter-actions">
                                            <button type="submit" class="btn btn-primary">
                                                <i class='bx bx-filter-alt'></i> Apply Filters
                                            </button>
                                            <a href="maintenance_logs.php" class="btn btn-secondary">
                                                <i class='bx bx-reset'></i> Clear Filters
                                            </a>
                                        </div>
                                    </form>
                                </div>
                                
                                <?php if (!empty($filtered_logs)): ?>
                                    <table class="maintenance-table">
                                        <thead>
                                            <tr>
                                                <th>Equipment</th>
                                                <th>Type</th>
                                                <th>Priority</th>
                                                <th>Description</th>
                                                <th>Date Requested</th>
                                                <th>Status</th>
                                                <th>Estimated Cost</th>
                                                <th>Actions</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($filtered_logs as $log): 
                                                $status_class = 'status-' . $log['status'];
                                                $priority_class = 'priority-' . $log['priority'];
                                                
                                                $requester_name = $log['requester_first_name'] . ' ' . $log['requester_last_name'];
                                                $approver_name = $log['approver_first_name'] ? 
                                                    $log['approver_first_name'] . ' ' . $log['approver_last_name'] : 'Not approved';
                                                $completer_name = $log['completer_first_name'] ? 
                                                    $log['completer_first_name'] . ' ' . $log['completer_last_name'] : 'Not completed';
                                            ?>
                                                <tr>
                                                    <td>
                                                        <strong><?php echo htmlspecialchars($log['resource_name']); ?></strong>
                                                        <br>
                                                        <small style="color: var(--text-light);">
                                                            <?php echo htmlspecialchars($log['category']); ?> • 
                                                            <?php echo htmlspecialchars($log['unit_name'] ?: 'Unassigned'); ?>
                                                        </small>
                                                    </td>
                                                    <td>
                                                        <span class="type-badge">
                                                            <?php echo ucfirst(str_replace('_', ' ', $log['request_type'])); ?>
                                                        </span>
                                                    </td>
                                                    <td>
                                                        <span class="priority-badge <?php echo $priority_class; ?>">
                                                            <?php echo ucfirst($log['priority']); ?>
                                                        </span>
                                                    </td>
                                                    <td style="max-width: 200px;">
                                                        <?php echo substr(htmlspecialchars($log['description']), 0, 80); ?>
                                                        <?php if (strlen($log['description']) > 80): ?>...<?php endif; ?>
                                                        <?php if (!empty($log['notes'])): ?>
                                                            <br><small style="color: var(--text-light);">Notes: <?php echo substr(htmlspecialchars($log['notes']), 0, 50); ?>...</small>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td>
                                                        <?php echo date('M d, Y', strtotime($log['requested_date'])); ?>
                                                        <br>
                                                        <small style="color: var(--text-light);">
                                                            <?php echo date('g:i A', strtotime($log['requested_date'])); ?>
                                                        </small>
                                                    </td>
                                                    <td>
                                                        <span class="status-badge <?php echo $status_class; ?>">
                                                            <?php echo ucfirst(str_replace('_', ' ', $log['status'])); ?>
                                                        </span>
                                                    </td>
                                                    <td>
                                                        <?php if ($log['estimated_cost']): ?>
                                                            <span class="cost-badge">
                                                                ₱<?php echo number_format($log['estimated_cost'], 2); ?>
                                                            </span>
                                                        <?php else: ?>
                                                            <span style="color: var(--text-light); font-size: 12px;">Not set</span>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td>
                                                        <button onclick="viewRequestDetails(<?php echo $log['id']; ?>)" 
                                                                class="btn btn-sm btn-secondary" style="margin-bottom: 5px;">
                                                            <i class='bx bx-info-circle'></i> Details
                                                        </button>
                                                        <?php if ($log['status'] === 'pending'): ?>
                                                            <button onclick="cancelRequest(<?php echo $log['id']; ?>)" 
                                                                    class="btn btn-sm btn-danger">
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
                                        <h3>No Maintenance Requests Found</h3>
                                        <p>You haven't submitted any maintenance requests yet, or no requests match your search criteria.</p>
                                        <?php if ($search_filter || $type_filter !== 'all' || $status_filter !== 'all' || $date_from || $date_to): ?>
                                            <div style="margin-top: 20px;">
                                                <a href="maintenance_logs.php" class="btn btn-primary">
                                                    <i class='bx bx-reset'></i> Clear Filters
                                                </a>
                                            </div>
                                        <?php else: ?>
                                            <div style="margin-top: 20px;">
                                                <a href="equipment_list.php" class="btn btn-primary">
                                                    <i class='bx bx-plus'></i> Submit Your First Request
                                                </a>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                        
                        <!-- Service History Tab -->
                        <div id="service-history" class="tab-content">
                            <div class="section-container">
                                <h3 class="section-title">
                                    <i class='bx bx-history'></i>
                                    Service History
                                    <?php if (!empty($service_history)): ?>
                                        <span class="badge badge-info"><?php echo count($service_history); ?> records</span>
                                    <?php endif; ?>
                                </h3>
                                
                                <?php if (!empty($service_history)): ?>
                                    <table class="service-history-table">
                                        <thead>
                                            <tr>
                                                <th>Date</th>
                                                <th>Equipment</th>
                                                <th>Service Type</th>
                                                <th>Performed By</th>
                                                <th>Cost</th>
                                                <th>Status After</th>
                                                <th>Notes</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($service_history as $service): 
                                                $performer_name = $service['performed_by'] ?: 
                                                    ($service['performer_first_name'] ? 
                                                     $service['performer_first_name'] . ' ' . $service['performer_last_name'] : 
                                                     $service['service_provider']);
                                                
                                                $status_class = 'status-serviceable';
                                                switch ($service['status_after_service']) {
                                                    case 'Under Maintenance': $status_class = 'status-maintenance'; break;
                                                    case 'Condemned': $status_class = 'status-condemned'; break;
                                                }
                                            ?>
                                                <tr>
                                                    <td>
                                                        <?php echo date('M d, Y', strtotime($service['service_date'])); ?>
                                                        <?php if ($service['next_service_date']): ?>
                                                            <br>
                                                            <small style="color: var(--text-light); font-size: 11px;">
                                                                Next: <?php echo date('M d, Y', strtotime($service['next_service_date'])); ?>
                                                            </small>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td>
                                                        <strong><?php echo htmlspecialchars($service['resource_name']); ?></strong>
                                                        <br>
                                                        <small style="color: var(--text-light); font-size: 11px;">
                                                            <?php echo htmlspecialchars($service['category']); ?>
                                                        </small>
                                                    </td>
                                                    <td>
                                                        <span class="type-badge">
                                                            <?php echo ucfirst(str_replace('_', ' ', $service['service_type'])); ?>
                                                        </span>
                                                    </td>
                                                    <td>
                                                        <?php echo htmlspecialchars($performer_name ?: 'Unknown'); ?>
                                                        <?php if ($service['labor_hours']): ?>
                                                            <br>
                                                            <small style="color: var(--text-light); font-size: 11px;">
                                                                <?php echo $service['labor_hours']; ?> hours
                                                            </small>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td>
                                                        <?php if ($service['cost']): ?>
                                                            <span class="cost-badge">
                                                                ₱<?php echo number_format($service['cost'], 2); ?>
                                                            </span>
                                                        <?php else: ?>
                                                            <span style="color: var(--text-light); font-size: 11px;">No cost</span>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td>
                                                        <span class="status-badge <?php echo $status_class; ?>">
                                                            <?php echo htmlspecialchars($service['status_after_service']); ?>
                                                        </span>
                                                    </td>
                                                    <td style="max-width: 200px;">
                                                        <?php if ($service['parts_replaced']): ?>
                                                            <small style="color: var(--text-light);">
                                                                Parts: <?php echo substr(htmlspecialchars($service['parts_replaced']), 0, 50); ?>...
                                                            </small>
                                                            <br>
                                                        <?php endif; ?>
                                                        <?php if ($service['service_notes']): ?>
                                                            <small style="color: var(--text-light);">
                                                                <?php echo substr(htmlspecialchars($service['service_notes']), 0, 50); ?>...
                                                            </small>
                                                        <?php endif; ?>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                <?php else: ?>
                                    <div class="empty-state">
                                        <i class='bx bx-history'></i>
                                        <h3>No Service History</h3>
                                        <p>No service history records found for your unit or performed by you.</p>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                        
                        <!-- Timeline Tab -->
                        <div id="timeline" class="tab-content">
                            <div class="section-container">
                                <h3 class="section-title">
                                    <i class='bx bx-timeline'></i>
                                    Maintenance Timeline
                                </h3>
                                
                                <?php if (!empty($maintenance_logs) || !empty($recent_service)): ?>
                                    <div class="timeline">
                                        <?php 
                                        // Combine and sort all events by date
                                        $all_events = [];
                                        
                                        foreach ($maintenance_logs as $log) {
                                            $all_events[] = [
                                                'date' => $log['requested_date'],
                                                'type' => 'request',
                                                'title' => 'Maintenance Request: ' . $log['resource_name'],
                                                'description' => $log['description'],
                                                'status' => $log['status'],
                                                'priority' => $log['priority']
                                            ];
                                            
                                            if ($log['approved_date']) {
                                                $all_events[] = [
                                                    'date' => $log['approved_date'],
                                                    'type' => 'approval',
                                                    'title' => 'Request Approved: ' . $log['resource_name'],
                                                    'description' => 'Request was approved for maintenance',
                                                    'status' => 'approved'
                                                ];
                                            }
                                            
                                            if ($log['completed_date']) {
                                                $all_events[] = [
                                                    'date' => $log['completed_date'],
                                                    'type' => 'completion',
                                                    'title' => 'Maintenance Completed: ' . $log['resource_name'],
                                                    'description' => 'Maintenance work completed',
                                                    'status' => 'completed'
                                                ];
                                            }
                                        }
                                        
                                        foreach ($recent_service as $service) {
                                            $all_events[] = [
                                                'date' => $service['service_date'],
                                                'type' => 'service',
                                                'title' => 'Service Performed: ' . $service['resource_name'],
                                                'description' => $service['service_type'] . ' - ' . ($service['service_notes'] ?: 'Service completed'),
                                                'status' => 'completed'
                                            ];
                                        }
                                        
                                        // Sort events by date (newest first)
                                        usort($all_events, function($a, $b) {
                                            return strtotime($b['date']) - strtotime($a['date']);
                                        });
                                        
                                        // Display events
                                        $event_count = 0;
                                        foreach ($all_events as $event):
                                            if ($event_count >= 10) break;
                                            $event_date = new DateTime($event['date']);
                                        ?>
                                            <div class="timeline-item">
                                                <div class="timeline-date">
                                                    <?php echo $event_date->format('F j, Y • g:i A'); ?>
                                                </div>
                                                <div class="timeline-content">
                                                    <div class="timeline-title">
                                                        <?php echo htmlspecialchars($event['title']); ?>
                                                        <?php if ($event['type'] === 'request'): ?>
                                                            <span class="priority-badge priority-<?php echo $event['priority']; ?>" style="margin-left: 10px;">
                                                                <?php echo ucfirst($event['priority']); ?>
                                                            </span>
                                                        <?php endif; ?>
                                                    </div>
                                                    <div class="timeline-description">
                                                        <?php echo substr(htmlspecialchars($event['description']), 0, 100); ?>
                                                        <?php if (strlen($event['description']) > 100): ?>...<?php endif; ?>
                                                    </div>
                                                    <div class="timeline-meta">
                                                        <span>
                                                            <?php 
                                                            $type_labels = [
                                                                'request' => 'Request',
                                                                'approval' => 'Approval',
                                                                'completion' => 'Completion',
                                                                'service' => 'Service'
                                                            ];
                                                            echo $type_labels[$event['type']];
                                                            ?>
                                                        </span>
                                                        <span class="status-badge status-<?php echo $event['status']; ?>">
                                                            <?php echo ucfirst($event['status']); ?>
                                                        </span>
                                                    </div>
                                                </div>
                                            </div>
                                        <?php 
                                            $event_count++;
                                        endforeach; 
                                        ?>
                                    </div>
                                    
                                    <?php if (count($all_events) > 10): ?>
                                        <div style="text-align: center; margin-top: 20px; color: var(--text-light); font-size: 12px;">
                                            Showing 10 most recent events of <?php echo count($all_events); ?> total events
                                        </div>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <div class="empty-state">
                                        <i class='bx bx-timeline'></i>
                                        <h3>No Timeline Events</h3>
                                        <p>No maintenance or service events found in your timeline.</p>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Recent Service History -->
                    <?php if (!empty($recent_service)): ?>
                        <div class="section-container">
                            <h3 class="section-title">
                                <i class='bx bx-calendar-check'></i>
                                Recent Service Activity (Last 7 Days)
                            </h3>
                            
                            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 20px;">
                                <?php 
                                $recent_count = 0;
                                foreach ($recent_service as $service): 
                                    if ($recent_count >= 4) break;
                                    $service_date = new DateTime($service['service_date']);
                                ?>
                                    <div style="background: var(--background-color); border: 1px solid var(--border-color); border-radius: 12px; padding: 15px;">
                                        <div style="display: flex; justify-content: space-between; align-items: start; margin-bottom: 10px;">
                                            <div>
                                                <strong style="font-size: 14px;"><?php echo htmlspecialchars($service['resource_name']); ?></strong>
                                                <div style="font-size: 12px; color: var(--text-light); margin-top: 2px;">
                                                    <?php echo ucfirst(str_replace('_', ' ', $service['service_type'])); ?>
                                                </div>
                                            </div>
                                            <span class="status-badge status-completed">
                                                Completed
                                            </span>
                                        </div>
                                        
                                        <div style="font-size: 12px; color: var(--text-color); margin-bottom: 10px;">
                                            <?php echo substr(htmlspecialchars($service['service_notes'] ?: 'Service performed'), 0, 80); ?>
                                            <?php if (strlen($service['service_notes']) > 80): ?>...<?php endif; ?>
                                        </div>
                                        
                                        <div style="display: flex; justify-content: space-between; align-items: center; font-size: 11px; color: var(--text-light);">
                                            <div>
                                                <i class='bx bx-calendar'></i> <?php echo $service_date->format('M d'); ?>
                                            </div>
                                            <div>
                                                <?php if ($service['cost']): ?>
                                                    <i class='bx bx-dollar'></i> ₱<?php echo number_format($service['cost'], 2); ?>
                                                <?php endif; ?>
                                            </div>
                                            <div>
                                                <?php if ($service['performed_by']): ?>
                                                    <i class='bx bx-user'></i> <?php echo htmlspecialchars($service['performed_by']); ?>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </div>
                                <?php 
                                    $recent_count++;
                                endforeach; 
                                ?>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Request Details Modal -->
    <div id="detailsModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title">
                    <i class='bx bx-info-circle'></i>
                    Maintenance Request Details
                </h3>
                <button class="close-modal" onclick="closeDetailsModal()">&times;</button>
            </div>
            <div id="requestDetails"></div>
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
            
            // Auto-submit filters on change
            const typeFilter = document.querySelector('select[name="type"]');
            const statusFilter = document.querySelector('select[name="status"]');
            
            if (typeFilter) typeFilter.addEventListener('change', function() { 
                if (!document.querySelector('input[name="search"]').value && 
                    statusFilter.value === 'all' &&
                    !document.querySelector('input[name="date_from"]').value &&
                    !document.querySelector('input[name="date_to"]').value) {
                    document.getElementById('filter-form').submit();
                }
            });
            
            if (statusFilter) statusFilter.addEventListener('change', function() { 
                if (!document.querySelector('input[name="search"]').value && 
                    typeFilter.value === 'all' &&
                    !document.querySelector('input[name="date_from"]').value &&
                    !document.querySelector('input[name="date_to"]').value) {
                    document.getElementById('filter-form').submit();
                }
            });
            
            // Search functionality
            const searchInput = document.getElementById('search-input');
            if (searchInput) {
                searchInput.addEventListener('keyup', function() {
                    const searchTerm = this.value.toLowerCase();
                    const currentTab = document.querySelector('.tab-content.active').id;
                    
                    if (currentTab === 'my-requests') {
                        const table = document.querySelector('.maintenance-table');
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
        
        function viewRequestDetails(id) {
            fetch(`get_request_details.php?id=${id}`)
                .then(response => response.text())
                .then(data => {
                    document.getElementById('requestDetails').innerHTML = data;
                    document.getElementById('detailsModal').style.display = 'block';
                })
                .catch(error => {
                    document.getElementById('requestDetails').innerHTML = 
                        '<div style="padding: 20px; text-align: center; color: var(--text-light);">' +
                        '<i class="bx bx-error" style="font-size: 48px; margin-bottom: 20px;"></i>' +
                        '<p>Error loading request details. Please try again.</p>' +
                        '</div>';
                    document.getElementById('detailsModal').style.display = 'block';
                });
        }
        
        function closeDetailsModal() {
            document.getElementById('detailsModal').style.display = 'none';
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
        
        // Close modal when clicking outside
        window.onclick = function(event) {
            const detailsModal = document.getElementById('detailsModal');
            if (event.target === detailsModal) {
                closeDetailsModal();
            }
        };
    </script>
</body>
</html>