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
$volunteer_contact = htmlspecialchars($volunteer['contact_number']);
$unit_id = $volunteer['unit_id'];
$unit_name = htmlspecialchars($volunteer['unit_name']);
$unit_code = htmlspecialchars($volunteer['unit_code']);

// Get unit information
$unit_query = "SELECT * FROM units WHERE id = ?";
$unit_stmt = $pdo->prepare($unit_query);
$unit_stmt->execute([$unit_id]);
$unit_info = $unit_stmt->fetch();

// Get date range filter
$date_filter = $_GET['date_filter'] ?? 'month';
$start_date = $_GET['start_date'] ?? null;
$end_date = $_GET['end_date'] ?? null;

// Set default date range based on filter
$now = new DateTime();
$date_condition = "";
$params = [$unit_id];

switch ($date_filter) {
    case 'week':
        $start_date_default = $now->modify('-7 days')->format('Y-m-d');
        $end_date_default = (new DateTime())->format('Y-m-d');
        $date_condition = "AND DATE(ai.created_at) BETWEEN ? AND ?";
        $params[] = $start_date_default;
        $params[] = $end_date_default;
        break;
    case 'month':
        $start_date_default = $now->modify('-30 days')->format('Y-m-d');
        $end_date_default = (new DateTime())->format('Y-m-d');
        $date_condition = "AND DATE(ai.created_at) BETWEEN ? AND ?";
        $params[] = $start_date_default;
        $params[] = $end_date_default;
        break;
    case 'quarter':
        $start_date_default = $now->modify('-90 days')->format('Y-m-d');
        $end_date_default = (new DateTime())->format('Y-m-d');
        $date_condition = "AND DATE(ai.created_at) BETWEEN ? AND ?";
        $params[] = $start_date_default;
        $params[] = $end_date_default;
        break;
    case 'year':
        $start_date_default = $now->modify('-365 days')->format('Y-m-d');
        $end_date_default = (new DateTime())->format('Y-m-d');
        $date_condition = "AND DATE(ai.created_at) BETWEEN ? AND ?";
        $params[] = $start_date_default;
        $params[] = $end_date_default;
        break;
    case 'custom':
        if ($start_date && $end_date) {
            $date_condition = "AND DATE(ai.created_at) BETWEEN ? AND ?";
            $params[] = $start_date;
            $params[] = $end_date;
        }
        break;
    default:
        // Last 30 days as default
        $start_date_default = (new DateTime())->modify('-30 days')->format('Y-m-d');
        $end_date_default = (new DateTime())->format('Y-m-d');
        $date_condition = "AND DATE(ai.created_at) BETWEEN ? AND ?";
        $params[] = $start_date_default;
        $params[] = $end_date_default;
}

// Get response history for the unit
$history_query = "
    SELECT 
        ai.*,
        di.id as dispatch_id,
        di.status as dispatch_status,
        di.dispatched_at,
        di.status_updated_at,
        di.er_notes,
        di.vehicles_json,
        u.unit_name,
        u.unit_code,
        u.unit_type,
        CASE 
            WHEN di.status = 'completed' THEN 'completed'
            WHEN di.status = 'cancelled' THEN 'cancelled'
            WHEN di.status = 'arrived' THEN 'completed'
            ELSE 'other'
        END as resolution_status
    FROM api_incidents ai
    LEFT JOIN dispatch_incidents di ON ai.id = di.incident_id
    LEFT JOIN units u ON di.unit_id = u.id
    WHERE di.unit_id = ?
      AND di.status IN ('completed', 'cancelled', 'arrived')
      $date_condition
    ORDER BY ai.created_at DESC
    LIMIT 100
";

$history_stmt = $pdo->prepare($history_query);
$history_stmt->execute($params);
$history = $history_stmt->fetchAll();

// Get statistics for charts
$stats_query = "
    SELECT 
        DATE(ai.created_at) as incident_date,
        COUNT(*) as total_incidents,
        SUM(CASE WHEN di.status = 'completed' OR di.status = 'arrived' THEN 1 ELSE 0 END) as resolved_incidents,
        SUM(CASE WHEN di.status = 'cancelled' THEN 1 ELSE 0 END) as cancelled_incidents,
        ai.emergency_type,
        ai.severity
    FROM api_incidents ai
    LEFT JOIN dispatch_incidents di ON ai.id = di.incident_id
    WHERE di.unit_id = ?
      AND di.status IN ('completed', 'cancelled', 'arrived')
      $date_condition
    GROUP BY DATE(ai.created_at), ai.emergency_type, ai.severity
    ORDER BY incident_date
";

$stats_stmt = $pdo->prepare($stats_query);
$stats_stmt->execute($params);
$stats = $stats_stmt->fetchAll();

// Process data for charts
$dates = [];
$incident_counts = [];
$resolved_counts = [];
$cancelled_counts = [];

$emergency_types = [];
$severity_counts = [];

foreach ($stats as $stat) {
    $date = date('M j', strtotime($stat['incident_date']));
    if (!in_array($date, $dates)) {
        $dates[] = $date;
    }
    
    // Incident counts by date
    if (!isset($incident_counts[$date])) {
        $incident_counts[$date] = 0;
        $resolved_counts[$date] = 0;
        $cancelled_counts[$date] = 0;
    }
    $incident_counts[$date] += $stat['total_incidents'];
    $resolved_counts[$date] += $stat['resolved_incidents'];
    $cancelled_counts[$date] += $stat['cancelled_incidents'];
    
    // Emergency type counts
    $type = $stat['emergency_type'] ?: 'other';
    if (!isset($emergency_types[$type])) {
        $emergency_types[$type] = 0;
    }
    $emergency_types[$type] += $stat['total_incidents'];
    
    // Severity counts
    $severity = $stat['severity'] ?: 'unknown';
    if (!isset($severity_counts[$severity])) {
        $severity_counts[$severity] = 0;
    }
    $severity_counts[$severity] += $stat['total_incidents'];
}

// Prepare data for JavaScript
$dates_js = json_encode(array_values($dates));
$incident_counts_js = json_encode(array_values($incident_counts));
$resolved_counts_js = json_encode(array_values($resolved_counts));
$cancelled_counts_js = json_encode(array_values($cancelled_counts));

$emergency_types_labels = json_encode(array_keys($emergency_types));
$emergency_types_data = json_encode(array_values($emergency_types));

$severity_labels = json_encode(array_keys($severity_counts));
$severity_data = json_encode(array_values($severity_counts));

// Calculate overall statistics
$total_responses = count($history);
$completed_responses = 0;
$cancelled_responses = 0;
$response_times = [];

foreach ($history as $incident) {
    if ($incident['dispatch_status'] === 'completed' || $incident['dispatch_status'] === 'arrived') {
        $completed_responses++;
    } elseif ($incident['dispatch_status'] === 'cancelled') {
        $cancelled_responses++;
    }
    
    // Calculate response time if available
    if ($incident['dispatched_at'] && $incident['status_updated_at']) {
        $dispatched = new DateTime($incident['dispatched_at']);
        $updated = new DateTime($incident['status_updated_at']);
        $response_time = $updated->getTimestamp() - $dispatched->getTimestamp();
        $response_times[] = $response_time;
    }
}

// Calculate average response time in minutes
$avg_response_time = 0;
if (!empty($response_times)) {
    $avg_seconds = array_sum($response_times) / count($response_times);
    $avg_response_time = round($avg_seconds / 60, 1); // Convert to minutes
}

// Get volunteers in unit
$volunteers_query = "
    SELECT 
        v.id,
        v.first_name,
        v.middle_name,
        v.last_name,
        v.contact_number,
        v.email,
        v.volunteer_status,
        v.skills_basic_firefighting,
        v.skills_first_aid_cpr,
        v.skills_search_rescue,
        v.skills_driving,
        v.skills_communication,
        v.available_days,
        v.available_hours,
        v.emergency_response,
        u.username
    FROM volunteers v
    LEFT JOIN users u ON v.user_id = u.id
    LEFT JOIN volunteer_assignments va ON v.id = va.volunteer_id AND va.status = 'Active'
    WHERE va.unit_id = ? 
      AND v.status = 'approved'
    ORDER BY v.last_name, v.first_name
";

$volunteers_stmt = $pdo->prepare($volunteers_query);
$volunteers_stmt->execute([$unit_id]);
$unit_volunteers = $volunteers_stmt->fetchAll();
$total_volunteers = count($unit_volunteers);

// Get notifications
$notifications_query = "
    SELECT * FROM notifications 
    WHERE user_id = ? 
      AND is_read = 0
      AND type = 'dispatch'
    ORDER BY created_at DESC
    LIMIT 10
";

$notifications_stmt = $pdo->prepare($notifications_query);
$notifications_stmt->execute([$user_id]);
$notifications = $notifications_stmt->fetchAll();

// Mark notifications as read when viewing this page
if (!empty($notifications)) {
    $mark_read_query = "UPDATE notifications SET is_read = 1 WHERE user_id = ? AND type = 'dispatch'";
    $mark_read_stmt = $pdo->prepare($mark_read_query);
    $mark_read_stmt->execute([$user_id]);
}

// Handle filters
$status_filter = $_GET['status'] ?? 'all';
$severity_filter = $_GET['severity'] ?? 'all';
$type_filter = $_GET['type'] ?? 'all';

// Filter history
$filtered_history = [];
foreach ($history as $incident) {
    $match = true;
    
    if ($status_filter !== 'all') {
        if ($status_filter === 'completed' && !in_array($incident['dispatch_status'], ['completed', 'arrived'])) {
            $match = false;
        } elseif ($status_filter === 'cancelled' && $incident['dispatch_status'] !== 'cancelled') {
            $match = false;
        }
    }
    
    if ($severity_filter !== 'all' && $incident['severity'] !== $severity_filter) {
        $match = false;
    }
    
    if ($type_filter !== 'all' && $incident['emergency_type'] !== $type_filter) {
        $match = false;
    }
    
    if ($match) {
        $filtered_history[] = $incident;
    }
}

// Close statements
$stmt = null;
$volunteer_stmt = null;
$unit_stmt = null;
$history_stmt = null;
$stats_stmt = null;
$volunteers_stmt = null;
$notifications_stmt = null;
if (isset($mark_read_stmt)) $mark_read_stmt = null;
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Response History - Fire & Rescue Services Management</title>
    <link href='https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css' rel='stylesheet'>
    <link rel="icon" type="image/png" sizes="32x32" href="../../img/frsm-logo.png">
    <link rel="stylesheet" href="../../css/dashboard.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
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

        /* Enhanced Statistics Dashboard */
        .stats-dashboard {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .stat-card-enhanced {
            background: var(--background-color);
            border: 1px solid var(--border-color);
            border-radius: 12px;
            padding: 25px;
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
        }

        .stat-card-enhanced:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.1);
        }

        .stat-card-enhanced.urgent {
            border-left: 4px solid var(--danger);
        }

        .stat-card-enhanced.warning {
            border-left: 4px solid var(--warning);
        }

        .stat-card-enhanced.info {
            border-left: 4px solid var(--info);
        }

        .stat-card-enhanced.success {
            border-left: 4px solid var(--success);
        }

        .stat-icon {
            width: 60px;
            height: 60px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 15px;
            font-size: 24px;
        }

        .stat-icon.urgent {
            background: linear-gradient(135deg, rgba(220, 38, 38, 0.1), rgba(220, 38, 38, 0.2));
            color: var(--danger);
        }

        .stat-icon.warning {
            background: linear-gradient(135deg, rgba(245, 158, 11, 0.1), rgba(245, 158, 11, 0.2));
            color: var(--warning);
        }

        .stat-icon.info {
            background: linear-gradient(135deg, rgba(59, 130, 246, 0.1), rgba(59, 130, 246, 0.2));
            color: var(--info);
        }

        .stat-icon.success {
            background: linear-gradient(135deg, rgba(16, 185, 129, 0.1), rgba(16, 185, 129, 0.2));
            color: var(--success);
        }

        .stat-value {
            font-size: 32px;
            font-weight: 800;
            margin-bottom: 5px;
        }

        .stat-label {
            font-size: 14px;
            color: var(--text-light);
            margin-bottom: 8px;
            font-weight: 600;
        }

        .stat-subtext {
            font-size: 12px;
            color: var(--text-light);
        }

        /* Charts Section */
        .charts-container {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(400px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        @media (max-width: 768px) {
            .charts-container {
                grid-template-columns: 1fr;
            }
        }

        .chart-card {
            background: var(--background-color);
            border: 1px solid var(--border-color);
            border-radius: 12px;
            padding: 20px;
            height: 350px;
            display: flex;
            flex-direction: column;
        }

        .chart-title {
            font-size: 16px;
            font-weight: 700;
            color: var(--text-color);
            margin-bottom: 15px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .chart-container {
            flex: 1;
            position: relative;
        }

        /* Unit Overview */
        .unit-overview {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .unit-card {
            background: linear-gradient(135deg, #fef2f2 0%, #fee2e2 100%);
            border: 1px solid #fecaca;
            border-radius: 12px;
            padding: 25px;
            position: relative;
            overflow: hidden;
        }

        .dark-mode .unit-card {
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
            margin-top: 15px;
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

        /* Main Content Grid */
        .main-grid {
            display: grid;
            grid-template-columns: 1fr 350px;
            gap: 30px;
        }

        @media (max-width: 1200px) {
            .main-grid {
                grid-template-columns: 1fr;
            }
        }

        /* History Section */
        .history-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 25px;
        }

        .filter-container {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 15px;
            margin-bottom: 25px;
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

        .filter-select {
            padding: 10px 12px;
            border: 1px solid var(--border-color);
            border-radius: 8px;
            background: var(--background-color);
            color: var(--text-color);
            font-size: 14px;
        }

        .filter-select:focus {
            outline: none;
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(220, 38, 38, 0.1);
        }

        .filter-actions {
            display: flex;
            align-items: flex-end;
            gap: 10px;
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

        /* History Table */
        .history-table-container {
            overflow-x: auto;
            border: 1px solid var(--border-color);
            border-radius: 12px;
            background: var(--background-color);
        }

        .history-table {
            width: 100%;
            border-collapse: collapse;
            min-width: 800px;
        }

        .history-table thead {
            background: var(--card-bg);
            border-bottom: 2px solid var(--border-color);
        }

        .history-table th {
            padding: 15px;
            text-align: left;
            font-weight: 700;
            color: var(--text-color);
            font-size: 13px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .history-table tbody tr {
            border-bottom: 1px solid var(--border-color);
            transition: all 0.3s ease;
        }

        .history-table tbody tr:hover {
            background: var(--gray-100);
        }

        .dark-mode .history-table tbody tr:hover {
            background: var(--gray-800);
        }

        .history-table td {
            padding: 15px;
            color: var(--text-color);
            font-size: 13px;
        }

        /* Status and Severity Badges */
        .status-badge {
            padding: 4px 10px;
            border-radius: 4px;
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            display: inline-block;
        }

        .status-completed {
            background: rgba(16, 185, 129, 0.1);
            color: var(--success);
            border: 1px solid rgba(16, 185, 129, 0.2);
        }

        .status-cancelled {
            background: rgba(107, 114, 128, 0.1);
            color: var(--gray-500);
            border: 1px solid rgba(107, 114, 128, 0.2);
        }

        .status-arrived {
            background: rgba(59, 130, 246, 0.1);
            color: var(--info);
            border: 1px solid rgba(59, 130, 246, 0.2);
        }

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

        /* Sidebar Sections */
        .sidebar-section {
            margin-bottom: 30px;
        }

        .sidebar-card {
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 20px;
        }

        .sidebar-title {
            font-size: 16px;
            font-weight: 700;
            color: var(--text-color);
            margin-bottom: 15px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .volunteers-list {
            max-height: 300px;
            overflow-y: auto;
        }

        .volunteer-item {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 10px;
            border-bottom: 1px solid var(--border-color);
            transition: all 0.3s ease;
        }

        .volunteer-item:hover {
            background: var(--gray-100);
        }

        .dark-mode .volunteer-item:hover {
            background: var(--gray-800);
        }

        .volunteer-item:last-child {
            border-bottom: none;
        }

        .volunteer-avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            font-size: 14px;
        }

        .volunteer-info h4 {
            margin: 0 0 4px 0;
            color: var(--text-color);
            font-size: 14px;
        }

        .volunteer-info p {
            margin: 0;
            color: var(--text-light);
            font-size: 11px;
        }

        .volunteer-status {
            padding: 2px 8px;
            border-radius: 10px;
            font-size: 9px;
            font-weight: 600;
            text-transform: uppercase;
            margin-left: auto;
        }

        .status-active {
            background: rgba(16, 185, 129, 0.1);
            color: var(--success);
            border: 1px solid rgba(16, 185, 129, 0.2);
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

        .notification-panel {
            position: fixed;
            top: 100px;
            right: 20px;
            width: 350px;
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: 12px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.15);
            z-index: 1000;
            display: none;
        }

        .notification-panel.show {
            display: block;
        }

        .notification-header {
            padding: 15px;
            border-bottom: 1px solid var(--border-color);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .notification-title {
            font-size: 16px;
            font-weight: 700;
            color: var(--text-color);
        }

        .notification-close {
            background: none;
            border: none;
            color: var(--text-light);
            cursor: pointer;
            font-size: 20px;
        }

        .notification-list {
            max-height: 400px;
            overflow-y: auto;
        }

        .notification-item {
            padding: 15px;
            border-bottom: 1px solid var(--border-color);
            transition: all 0.3s ease;
        }

        .notification-item:hover {
            background: var(--gray-100);
        }

        .dark-mode .notification-item:hover {
            background: var(--gray-800);
        }

        .notification-item.unread {
            background: rgba(59, 130, 246, 0.05);
        }

        .notification-message {
            font-size: 13px;
            color: var(--text-color);
            margin-bottom: 5px;
        }

        .notification-time {
            font-size: 11px;
            color: var(--text-light);
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
            
            .stats-dashboard {
                grid-template-columns: repeat(2, 1fr);
            }
            
            .unit-overview {
                grid-template-columns: 1fr;
            }
            
            .main-grid {
                grid-template-columns: 1fr;
            }
            
            .filter-container {
                grid-template-columns: 1fr;
            }
            
            .notification-panel {
                width: 300px;
                right: 10px;
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
            
            .stats-dashboard {
                grid-template-columns: 1fr;
            }
            
            .charts-container {
                grid-template-columns: 1fr;
            }
            
            .filter-actions {
                flex-direction: column;
            }
            
            .notification-panel {
                width: 90%;
                right: 5%;
                left: 5%;
            }
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

        /* Badge Styles */
        .badge {
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
        }

        .badge-info {
            background: rgba(59, 130, 246, 0.1);
            color: var(--info);
            border: 1px solid rgba(59, 130, 246, 0.2);
        }

        .badge-success {
            background: rgba(16, 185, 129, 0.1);
            color: var(--success);
            border: 1px solid rgba(16, 185, 129, 0.2);
        }

        .badge-warning {
            background: rgba(245, 158, 11, 0.1);
            color: var(--warning);
            border: 1px solid rgba(245, 158, 11, 0.2);
        }

        .badge-danger {
            background: rgba(220, 38, 38, 0.1);
            color: var(--danger);
            border: 1px solid rgba(220, 38, 38, 0.2);
        }

        /* Date Range Picker */
        .date-range-container {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-bottom: 20px;
        }

        .date-input-group {
            display: flex;
            flex-direction: column;
        }

        .date-input {
            padding: 10px 12px;
            border: 1px solid var(--border-color);
            border-radius: 8px;
            background: var(--background-color);
            color: var(--text-color);
            font-size: 14px;
        }

        /* Chart Legend */
        .chart-legend {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            margin-top: 15px;
            font-size: 12px;
        }

        .legend-item {
            display: flex;
            align-items: center;
            gap: 5px;
        }

        .legend-color {
            width: 12px;
            height: 12px;
            border-radius: 3px;
        }

        /* Export Button */
        .export-actions {
            display: flex;
            gap: 10px;
            margin-top: 20px;
        }

        .export-btn {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 8px 16px;
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: 6px;
            color: var(--text-color);
            text-decoration: none;
            font-size: 13px;
            transition: all 0.3s ease;
        }

        .export-btn:hover {
            background: var(--gray-100);
        }

        .dark-mode .export-btn:hover {
            background: var(--gray-800);
        }

        /* Response Time Display */
        .response-time-display {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 15px;
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: 8px;
            margin-bottom: 20px;
        }

        .response-time-value {
            font-size: 24px;
            font-weight: 700;
            color: var(--primary-color);
        }

        .response-time-label {
            font-size: 12px;
            color: var(--text-light);
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
                    <div id="fire-incident" class="submenu active">
                        <a href="active_incidents.php" class="submenu-item">Active Incidents</a>
                        <a href="response_history.php" class="submenu-item <?php echo !$incident_id ? 'active' : ''; ?>">Response History</a>
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
                         <a href="register_training.php" class="submenu-item">Register for Training</a>
            <a href="training_records.php" class="submenu-item">Training Records</a>
            <a href="certification_status.php" class="submenu-item">Certification Status</a>
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
                            <input type="text" placeholder="Search response history..." class="search-input" id="search-input">
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
            
            <!-- Notification Panel -->
            <div class="notification-panel" id="notification-panel">
                <div class="notification-header">
                    <h3 class="notification-title">Dispatch Notifications</h3>
                    <button class="notification-close" id="notification-close">&times;</button>
                </div>
                <div class="notification-list">
                    <?php if (!empty($notifications)): ?>
                        <?php foreach ($notifications as $notification): ?>
                            <div class="notification-item unread">
                                <div class="notification-message"><?php echo htmlspecialchars($notification['message']); ?></div>
                                <div class="notification-time">
                                    <?php 
                                    $time = new DateTime($notification['created_at']);
                                    echo $time->format('M j, Y g:i A');
                                    ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="notification-item">
                            <div class="notification-message">No new notifications</div>
                            <div class="notification-time">You're all caught up!</div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- Dashboard Content -->
            <div class="dashboard-content">
                <div class="dashboard-header">
                    <div>
                        <h1 class="dashboard-title">Response History & Analytics</h1>
                        <p class="dashboard-subtitle">Historical data and performance metrics for <?php echo htmlspecialchars($unit_name); ?> (<?php echo htmlspecialchars($unit_code); ?>)</p>
                    </div>
                </div>
                
                <!-- Content Container -->
                <div class="content-container">
                    <!-- Enhanced Statistics Dashboard -->
                    <div class="section-container">
                        <h3 class="section-title">
                            <i class='bx bx-stats'></i>
                            Performance Overview
                        </h3>
                        
                        <div class="stats-dashboard">
                            <!-- Total Responses -->
                            <div class="stat-card-enhanced info">
                                <div class="stat-icon info">
                                    <i class='bx bx-history'></i>
                                </div>
                                <div class="stat-value"><?php echo $total_responses; ?></div>
                                <div class="stat-label">Total Responses</div>
                                <div class="stat-subtext">All time responses</div>
                            </div>
                            
                            <!-- Completed Responses -->
                            <div class="stat-card-enhanced success">
                                <div class="stat-icon success">
                                    <i class='bx bx-check-circle'></i>
                                </div>
                                <div class="stat-value"><?php echo $completed_responses; ?></div>
                                <div class="stat-label">Completed</div>
                                <div class="stat-subtext">Successfully resolved</div>
                            </div>
                            
                            <!-- Average Response Time -->
                            <div class="stat-card-enhanced warning">
                                <div class="stat-icon warning">
                                    <i class='bx bx-time-five'></i>
                                </div>
                                <div class="stat-value"><?php echo $avg_response_time; ?>m</div>
                                <div class="stat-label">Avg. Response Time</div>
                                <div class="stat-subtext">Dispatch to resolution</div>
                            </div>
                            
                            <!-- Cancelled Responses -->
                            <div class="stat-card-enhanced urgent">
                                <div class="stat-icon urgent">
                                    <i class='bx bx-x-circle'></i>
                                </div>
                                <div class="stat-value"><?php echo $cancelled_responses; ?></div>
                                <div class="stat-label">Cancelled</div>
                                <div class="stat-subtext">Incidents cancelled</div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Charts Section -->
                    <div class="section-container">
                        <h3 class="section-title">
                            <i class='bx bx-line-chart'></i>
                            Incident Trends & Analytics
                        </h3>
                        
                        <div class="charts-container">
                            <!-- Line Chart: Incidents Over Time -->
                            <div class="chart-card">
                                <h4 class="chart-title">
                                    <i class='bx bx-trending-up'></i>
                                    Incidents Over Time
                                </h4>
                                <div class="chart-container">
                                    <canvas id="incidentsOverTimeChart"></canvas>
                                </div>
                                <div class="chart-legend">
                                    <div class="legend-item">
                                        <div class="legend-color" style="background: #3b82f6;"></div>
                                        <span>Total Incidents</span>
                                    </div>
                                    <div class="legend-item">
                                        <div class="legend-color" style="background: #10b981;"></div>
                                        <span>Resolved</span>
                                    </div>
                                    <div class="legend-item">
                                        <div class="legend-color" style="background: #6b7280;"></div>
                                        <span>Cancelled</span>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Pie Chart: Emergency Types -->
                            <div class="chart-card">
                                <h4 class="chart-title">
                                    <i class='bx bx-pie-chart-alt'></i>
                                    Emergency Type Distribution
                                </h4>
                                <div class="chart-container">
                                    <canvas id="emergencyTypesChart"></canvas>
                                </div>
                            </div>
                            
                            <!-- Bar Chart: Severity Levels -->
                            <div class="chart-card">
                                <h4 class="chart-title">
                                    <i class='bx bx-bar-chart-alt'></i>
                                    Severity Levels
                                </h4>
                                <div class="chart-container">
                                    <canvas id="severityChart"></canvas>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Date Range Filter -->
                    <div class="section-container">
                        <h3 class="section-title">
                            <i class='bx bx-calendar'></i>
                            Filter by Date Range
                        </h3>
                        
                        <form method="GET" action="" id="date-filter-form">
                            <div class="filter-container">
                                <div class="filter-group">
                                    <label class="filter-label">Time Period</label>
                                    <select name="date_filter" class="filter-select" onchange="toggleDateRange(this.value)">
                                        <option value="week" <?php echo $date_filter === 'week' ? 'selected' : ''; ?>>Last 7 Days</option>
                                        <option value="month" <?php echo $date_filter === 'month' ? 'selected' : ''; ?>>Last 30 Days</option>
                                        <option value="quarter" <?php echo $date_filter === 'quarter' ? 'selected' : ''; ?>>Last 90 Days</option>
                                        <option value="year" <?php echo $date_filter === 'year' ? 'selected' : ''; ?>>Last 365 Days</option>
                                        <option value="custom" <?php echo $date_filter === 'custom' ? 'selected' : ''; ?>>Custom Range</option>
                                    </select>
                                </div>
                                
                                <div id="custom-date-range" style="display: <?php echo $date_filter === 'custom' ? 'grid' : 'none'; ?>; grid-template-columns: 1fr 1fr; gap: 15px;">
                                    <div class="date-input-group">
                                        <label class="filter-label">Start Date</label>
                                        <input type="date" name="start_date" class="date-input" value="<?php echo $start_date ?? ''; ?>">
                                    </div>
                                    <div class="date-input-group">
                                        <label class="filter-label">End Date</label>
                                        <input type="date" name="end_date" class="date-input" value="<?php echo $end_date ?? ''; ?>">
                                    </div>
                                </div>
                                
                                <div class="filter-actions">
                                    <button type="submit" class="btn btn-primary">
                                        <i class='bx bx-filter-alt'></i> Apply Date Filter
                                    </button>
                                    <a href="response_history.php" class="btn btn-secondary">
                                        <i class='bx bx-reset'></i> Reset Filters
                                    </a>
                                </div>
                            </div>
                        </form>
                    </div>
                    
                    <!-- Main Content Grid -->
                    <div class="main-grid">
                        <!-- Left Column: History Table -->
                        <div>
                            <!-- History Section -->
                            <div class="section-container">
                                <div class="history-header">
                                    <h3 class="section-title" style="margin-bottom: 0;">
                                        <i class='bx bx-history'></i>
                                        Response History
                                        <?php if (count($filtered_history) > 0): ?>
                                            <span class="badge badge-info"><?php echo count($filtered_history); ?> records</span>
                                        <?php endif; ?>
                                    </h3>
                                    
                                    <div style="display: flex; gap: 10px;">
                                        <a href="active_incidents.php" class="btn btn-secondary">
                                            <i class='bx bx-alarm-exclamation'></i> Active Incidents
                                        </a>
                                    </div>
                                </div>
                                
                                <!-- Filters -->
                                <div class="filter-container">
                                    <form method="GET" action="" id="filter-form">
                                        <input type="hidden" name="date_filter" value="<?php echo $date_filter; ?>">
                                        <?php if ($date_filter === 'custom' && $start_date && $end_date): ?>
                                            <input type="hidden" name="start_date" value="<?php echo $start_date; ?>">
                                            <input type="hidden" name="end_date" value="<?php echo $end_date; ?>">
                                        <?php endif; ?>
                                        
                                        <div class="filter-group">
                                            <label class="filter-label">Status</label>
                                            <select name="status" class="filter-select">
                                                <option value="all" <?php echo $status_filter === 'all' ? 'selected' : ''; ?>>All Status</option>
                                                <option value="completed" <?php echo $status_filter === 'completed' ? 'selected' : ''; ?>>Completed</option>
                                                <option value="cancelled" <?php echo $status_filter === 'cancelled' ? 'selected' : ''; ?>>Cancelled</option>
                                            </select>
                                        </div>
                                        
                                        <div class="filter-group">
                                            <label class="filter-label">Severity</label>
                                            <select name="severity" class="filter-select">
                                                <option value="all" <?php echo $severity_filter === 'all' ? 'selected' : ''; ?>>All Severity</option>
                                                <option value="critical" <?php echo $severity_filter === 'critical' ? 'selected' : ''; ?>>Critical</option>
                                                <option value="high" <?php echo $severity_filter === 'high' ? 'selected' : ''; ?>>High</option>
                                                <option value="medium" <?php echo $severity_filter === 'medium' ? 'selected' : ''; ?>>Medium</option>
                                                <option value="low" <?php echo $severity_filter === 'low' ? 'selected' : ''; ?>>Low</option>
                                            </select>
                                        </div>
                                        
                                        <div class="filter-group">
                                            <label class="filter-label">Emergency Type</label>
                                            <select name="type" class="filter-select">
                                                <option value="all" <?php echo $type_filter === 'all' ? 'selected' : ''; ?>>All Types</option>
                                                <option value="fire" <?php echo $type_filter === 'fire' ? 'selected' : ''; ?>>Fire</option>
                                                <option value="medical" <?php echo $type_filter === 'medical' ? 'selected' : ''; ?>>Medical</option>
                                                <option value="rescue" <?php echo $type_filter === 'rescue' ? 'selected' : ''; ?>>Rescue</option>
                                                <option value="other" <?php echo $type_filter === 'other' ? 'selected' : ''; ?>>Other</option>
                                            </select>
                                        </div>
                                        
                                        <div class="filter-actions">
                                            <button type="submit" class="btn btn-primary">
                                                <i class='bx bx-filter-alt'></i> Apply Filters
                                            </button>
                                            <a href="response_history.php<?php echo $date_filter === 'custom' && $start_date && $end_date ? '?date_filter=custom&start_date=' . $start_date . '&end_date=' . $end_date : ''; ?>" class="btn btn-secondary">
                                                <i class='bx bx-reset'></i> Clear Filters
                                            </a>
                                        </div>
                                    </form>
                                </div>
                                
                                <!-- Export Actions -->
                                <div class="export-actions">
                                    <a href="#" class="export-btn" onclick="exportToCSV()">
                                        <i class='bx bx-download'></i> Export to CSV
                                    </a>
                                    <a href="#" class="export-btn" onclick="exportToPDF()">
                                        <i class='bx bx-file'></i> Export to PDF
                                    </a>
                                    <a href="#" class="export-btn" onclick="printHistory()">
                                        <i class='bx bx-printer'></i> Print Report
                                    </a>
                                </div>
                                
                                <!-- History Table -->
                                <?php if (count($filtered_history) > 0): ?>
                                    <div class="history-table-container">
                                        <table class="history-table">
                                            <thead>
                                                <tr>
                                                    <th>Date & Time</th>
                                                    <th>Incident</th>
                                                    <th>Type</th>
                                                    <th>Severity</th>
                                                    <th>Location</th>
                                                    <th>Status</th>
                                                    <th>Response Time</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($filtered_history as $incident): 
                                                    $status_class = 'status-' . ($incident['dispatch_status'] === 'arrived' ? 'completed' : $incident['dispatch_status']);
                                                    $severity_class = 'severity-' . $incident['severity'];
                                                    $response_time = 'N/A';
                                                    
                                                    if ($incident['dispatched_at'] && $incident['status_updated_at']) {
                                                        $dispatched = new DateTime($incident['dispatched_at']);
                                                        $updated = new DateTime($incident['status_updated_at']);
                                                        $diff = $updated->diff($dispatched);
                                                        if ($diff->d > 0) {
                                                            $response_time = $diff->d . 'd ' . $diff->h . 'h';
                                                        } elseif ($diff->h > 0) {
                                                            $response_time = $diff->h . 'h ' . $diff->i . 'm';
                                                        } else {
                                                            $response_time = $diff->i . 'm ' . $diff->s . 's';
                                                        }
                                                    }
                                                ?>
                                                    <tr>
                                                        <td>
                                                            <?php 
                                                            $created_at = new DateTime($incident['created_at']);
                                                            echo $created_at->format('M j, Y') . '<br><small style="color: var(--text-light);">' . $created_at->format('g:i A') . '</small>';
                                                            ?>
                                                        </td>
                                                        <td>
                                                            <strong><?php echo htmlspecialchars($incident['title']); ?></strong><br>
                                                            <small style="color: var(--text-light);"><?php echo htmlspecialchars($incident['caller_name']); ?></small>
                                                        </td>
                                                        <td><?php echo ucfirst($incident['emergency_type']); ?></td>
                                                        <td>
                                                            <span class="severity-badge <?php echo $severity_class; ?>">
                                                                <?php echo ucfirst($incident['severity']); ?>
                                                            </span>
                                                        </td>
                                                        <td><?php echo htmlspecialchars($incident['location']); ?></td>
                                                        <td>
                                                            <span class="status-badge <?php echo $status_class; ?>">
                                                                <?php echo ucfirst($incident['dispatch_status'] === 'arrived' ? 'Completed' : $incident['dispatch_status']); ?>
                                                            </span>
                                                        </td>
                                                        <td><?php echo $response_time; ?></td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                    
                                    <!-- Pagination or summary -->
                                    <div style="margin-top: 20px; text-align: center; color: var(--text-light); font-size: 12px;">
                                        Showing <?php echo count($filtered_history); ?> of <?php echo $total_responses; ?> total responses
                                    </div>
                                <?php else: ?>
                                    <div class="empty-state">
                                        <i class='bx bx-history'></i>
                                        <h3>No Response History Found</h3>
                                        <p>No response records match your search criteria for the selected date range.</p>
                                        <?php if ($status_filter !== 'all' || $severity_filter !== 'all' || $type_filter !== 'all'): ?>
                                            <div style="margin-top: 20px;">
                                                <a href="response_history.php<?php echo $date_filter === 'custom' && $start_date && $end_date ? '?date_filter=custom&start_date=' . $start_date . '&end_date=' . $end_date : ''; ?>" class="btn btn-primary">
                                                    <i class='bx bx-reset'></i> Clear Filters
                                                </a>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                        
                        <!-- Right Column: Resources -->
                        <div>
                            <!-- Unit Information -->
                            <div class="sidebar-section">
                                <div class="sidebar-card">
                                    <h4 class="sidebar-title">
                                        <i class='bx bx-building'></i>
                                        Unit Information
                                    </h4>
                                    <?php if ($unit_info): ?>
                                        <div class="unit-details-grid">
                                            <div class="unit-detail">
                                                <span class="unit-label">Unit Name</span>
                                                <span class="unit-value"><?php echo htmlspecialchars($unit_info['unit_name']); ?></span>
                                            </div>
                                            <div class="unit-detail">
                                                <span class="unit-label">Unit Code</span>
                                                <span class="unit-value"><?php echo htmlspecialchars($unit_info['unit_code']); ?></span>
                                            </div>
                                            <div class="unit-detail">
                                                <span class="unit-label">Unit Type</span>
                                                <span class="unit-value"><?php echo htmlspecialchars($unit_info['unit_type']); ?></span>
                                            </div>
                                            <div class="unit-detail">
                                                <span class="unit-label">Location</span>
                                                <span class="unit-value"><?php echo htmlspecialchars($unit_info['location']); ?></span>
                                            </div>
                                        </div>
                                    <?php endif; ?>
                                    
                                    <!-- Response Time Summary -->
                                    <div class="response-time-display" style="margin-top: 20px;">
                                        <i class='bx bx-time' style="font-size: 24px; color: var(--primary-color);"></i>
                                        <div>
                                            <div class="response-time-value"><?php echo $avg_response_time; ?> min</div>
                                            <div class="response-time-label">Average Response Time</div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Volunteers Section -->
                            <div class="sidebar-section">
                                <div class="sidebar-card">
                                    <h4 class="sidebar-title">
                                        <i class='bx bx-group'></i>
                                        Unit Volunteers
                                        <span class="badge badge-info"><?php echo $total_volunteers; ?></span>
                                    </h4>
                                    <div class="volunteers-list">
                                        <?php if (!empty($unit_volunteers)): ?>
                                            <?php foreach (array_slice($unit_volunteers, 0, 5) as $vol): 
                                                $full_name = htmlspecialchars($vol['first_name'] . ' ' . $vol['last_name']);
                                                $initials = strtoupper(substr($vol['first_name'], 0, 1) . substr($vol['last_name'], 0, 1));
                                            ?>
                                                <div class="volunteer-item">
                                                    <div class="volunteer-avatar"><?php echo $initials; ?></div>
                                                    <div class="volunteer-info">
                                                        <h4><?php echo $full_name; ?></h4>
                                                        <p><?php echo htmlspecialchars($vol['skills_basic_firefighting'] ? 'Firefighting' : ($vol['skills_first_aid_cpr'] ? 'First Aid' : 'General')); ?></p>
                                                    </div>
                                                    <span class="volunteer-status <?php echo $vol['volunteer_status'] === 'Active' ? 'status-active' : 'status-inactive'; ?>">
                                                        <?php echo $vol['volunteer_status']; ?>
                                                    </span>
                                                </div>
                                            <?php endforeach; ?>
                                            <?php if (count($unit_volunteers) > 5): ?>
                                                <div style="text-align: center; padding: 10px; font-size: 12px; color: var(--text-light);">
                                                    + <?php echo count($unit_volunteers) - 5; ?> more volunteers
                                                </div>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            <div class="empty-state" style="padding: 20px 0;">
                                                <i class='bx bx-user-x'></i>
                                                <p style="font-size: 12px;">No volunteers assigned</p>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Performance Metrics -->
                            <div class="sidebar-section">
                                <div class="sidebar-card">
                                    <h4 class="sidebar-title">
                                        <i class='bx bx-trophy'></i>
                                        Performance Metrics
                                    </h4>
                                    <div style="display: grid; gap: 10px;">
                                        <div style="display: flex; justify-content: space-between; align-items: center;">
                                            <span style="font-size: 12px; color: var(--text-light);">Completion Rate</span>
                                            <span style="font-weight: 600; color: var(--text-color);">
                                                <?php echo $total_responses > 0 ? round(($completed_responses / $total_responses) * 100, 1) : 0; ?>%
                                            </span>
                                        </div>
                                        <div style="display: flex; justify-content: space-between; align-items: center;">
                                            <span style="font-size: 12px; color: var(--text-light);">Cancellation Rate</span>
                                            <span style="font-weight: 600; color: var(--text-color);">
                                                <?php echo $total_responses > 0 ? round(($cancelled_responses / $total_responses) * 100, 1) : 0; ?>%
                                            </span>
                                        </div>
                                        <div style="display: flex; justify-content: space-between; align-items: center;">
                                            <span style="font-size: 12px; color: var(--text-light);">Most Common Type</span>
                                            <span style="font-weight: 600; color: var(--text-color);">
                                                <?php 
                                                if (!empty($emergency_types)) {
                                                    arsort($emergency_types);
                                                    echo ucfirst(array_keys($emergency_types)[0]);
                                                } else {
                                                    echo 'N/A';
                                                }
                                                ?>
                                            </span>
                                        </div>
                                        <div style="display: flex; justify-content: space-between; align-items: center;">
                                            <span style="font-size: 12px; color: var(--text-light);">Highest Severity</span>
                                            <span style="font-weight: 600; color: var(--text-color);">
                                                <?php 
                                                if (!empty($severity_counts)) {
                                                    arsort($severity_counts);
                                                    echo ucfirst(array_keys($severity_counts)[0]);
                                                } else {
                                                    echo 'N/A';
                                                }
                                                ?>
                                            </span>
                                        </div>
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
        document.addEventListener('DOMContentLoaded', function() {
            // Initialize event listeners
            initEventListeners();
            
            // Update time display
            updateTime();
            setInterval(updateTime, 1000);
            
            // Initialize charts
            initializeCharts();
            
            // Handle search
            const searchInput = document.getElementById('search-input');
            if (searchInput) {
                searchInput.addEventListener('keyup', function() {
                    const searchTerm = this.value.toLowerCase();
                    const tableRows = document.querySelectorAll('.history-table tbody tr');
                    
                    tableRows.forEach(row => {
                        const cells = row.querySelectorAll('td');
                        let match = false;
                        
                        cells.forEach(cell => {
                            if (cell.textContent.toLowerCase().includes(searchTerm)) {
                                match = true;
                            }
                        });
                        
                        if (match) {
                            row.style.display = '';
                        } else {
                            row.style.display = 'none';
                        }
                    });
                });
            }
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
            
            // Notification bell
            const notificationBell = document.getElementById('notification-bell');
            const notificationPanel = document.getElementById('notification-panel');
            const notificationClose = document.getElementById('notification-close');
            
            if (notificationBell && notificationPanel) {
                notificationBell.addEventListener('click', function(e) {
                    e.stopPropagation();
                    notificationPanel.classList.toggle('show');
                });
            }
            
            if (notificationClose && notificationPanel) {
                notificationClose.addEventListener('click', function() {
                    notificationPanel.classList.remove('show');
                });
            }
            
            // Close dropdowns when clicking outside
            document.addEventListener('click', function() {
                if (userDropdown) {
                    userDropdown.classList.remove('show');
                }
                if (notificationPanel) {
                    notificationPanel.classList.remove('show');
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
        
        function toggleDateRange(value) {
            const customRangeDiv = document.getElementById('custom-date-range');
            if (value === 'custom') {
                customRangeDiv.style.display = 'grid';
            } else {
                customRangeDiv.style.display = 'none';
            }
        }
        
        // Show notification panel if there are notifications
        <?php if (count($notifications) > 0): ?>
        setTimeout(() => {
            const notificationPanel = document.getElementById('notification-panel');
            if (notificationPanel) {
                notificationPanel.classList.add('show');
            }
        }, 1000);
        <?php endif; ?>
        
        // Export functions
        function exportToCSV() {
            alert('CSV export feature would generate and download a CSV file with all response history data.');
            // In a real application, this would make an AJAX call to generate and download a CSV
        }
        
        function exportToPDF() {
            alert('PDF export feature would generate and download a PDF report.');
            // In a real application, this would make an AJAX call to generate and download a PDF
        }
        
        function printHistory() {
            window.print();
        }
        
        // Chart initialization
        function initializeCharts() {
            // Get data from PHP
            const dates = <?php echo $dates_js; ?>;
            const incidentCounts = <?php echo $incident_counts_js; ?>;
            const resolvedCounts = <?php echo $resolved_counts_js; ?>;
            const cancelledCounts = <?php echo $cancelled_counts_js; ?>;
            
            const emergencyTypesLabels = <?php echo $emergency_types_labels; ?>;
            const emergencyTypesData = <?php echo $emergency_types_data; ?>;
            
            const severityLabels = <?php echo $severity_labels; ?>;
            const severityData = <?php echo $severity_data; ?>;
            
            // Chart 1: Incidents Over Time (Line Chart)
            const incidentsOverTimeCtx = document.getElementById('incidentsOverTimeChart').getContext('2d');
            new Chart(incidentsOverTimeCtx, {
                type: 'line',
                data: {
                    labels: dates,
                    datasets: [
                        {
                            label: 'Total Incidents',
                            data: incidentCounts,
                            borderColor: '#3b82f6',
                            backgroundColor: 'rgba(59, 130, 246, 0.1)',
                            borderWidth: 2,
                            fill: true,
                            tension: 0.4
                        },
                        {
                            label: 'Resolved',
                            data: resolvedCounts,
                            borderColor: '#10b981',
                            backgroundColor: 'rgba(16, 185, 129, 0.1)',
                            borderWidth: 2,
                            fill: true,
                            tension: 0.4
                        },
                        {
                            label: 'Cancelled',
                            data: cancelledCounts,
                            borderColor: '#6b7280',
                            backgroundColor: 'rgba(107, 114, 128, 0.1)',
                            borderWidth: 2,
                            fill: true,
                            tension: 0.4
                        }
                    ]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            display: false
                        },
                        tooltip: {
                            mode: 'index',
                            intersect: false
                        }
                    },
                    scales: {
                        x: {
                            grid: {
                                display: false
                            },
                            ticks: {
                                maxRotation: 45,
                                minRotation: 45
                            }
                        },
                        y: {
                            beginAtZero: true,
                            grid: {
                                borderDash: [5, 5]
                            },
                            ticks: {
                                stepSize: 1
                            }
                        }
                    },
                    interaction: {
                        intersect: false,
                        mode: 'nearest'
                    }
                }
            });
            
            // Chart 2: Emergency Types (Pie Chart)
            const emergencyTypesCtx = document.getElementById('emergencyTypesChart').getContext('2d');
            new Chart(emergencyTypesCtx, {
                type: 'doughnut',
                data: {
                    labels: emergencyTypesLabels,
                    datasets: [{
                        data: emergencyTypesData,
                        backgroundColor: [
                            '#dc2626', // Fire - red
                            '#10b981', // Medical - green
                            '#f59e0b', // Rescue - orange
                            '#3b82f6', // Other - blue
                            '#8b5cf6', // Additional colors
                            '#ec4899',
                            '#14b8a6',
                            '#f97316'
                        ],
                        borderWidth: 1,
                        borderColor: document.body.classList.contains('dark-mode') ? '#334155' : '#e5e7eb'
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            position: 'right',
                            labels: {
                                padding: 20,
                                usePointStyle: true,
                                pointStyle: 'circle'
                            }
                        }
                    },
                    cutout: '60%'
                }
            });
            
            // Chart 3: Severity Levels (Bar Chart)
            const severityCtx = document.getElementById('severityChart').getContext('2d');
            new Chart(severityCtx, {
                type: 'bar',
                data: {
                    labels: severityLabels,
                    datasets: [{
                        label: 'Incidents by Severity',
                        data: severityData,
                        backgroundColor: [
                            '#dc2626', // Critical - red
                            '#f59e0b', // High - orange
                            '#3b82f6', // Medium - blue
                            '#6b7280', // Low - gray
                            '#8b5cf6'  // Unknown - purple
                        ],
                        borderColor: document.body.classList.contains('dark-mode') ? '#334155' : '#e5e7eb',
                        borderWidth: 1,
                        borderRadius: 6
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            display: false
                        }
                    },
                    scales: {
                        x: {
                            grid: {
                                display: false
                            }
                        },
                        y: {
                            beginAtZero: true,
                            grid: {
                                borderDash: [5, 5]
                            },
                            ticks: {
                                stepSize: 1
                            }
                        }
                    }
                }
            });
            
            // Update charts when theme changes
            const themeToggle = document.getElementById('theme-toggle');
            if (themeToggle) {
                themeToggle.addEventListener('click', function() {
                    setTimeout(() => {
                        // Recreate charts with updated theme
                        document.getElementById('incidentsOverTimeChart').remove();
                        document.getElementById('emergencyTypesChart').remove();
                        document.getElementById('severityChart').remove();
                        
                        const chartsContainer = document.querySelector('.charts-container');
                        chartsContainer.innerHTML = `
                            <div class="chart-card">
                                <h4 class="chart-title">
                                    <i class='bx bx-trending-up'></i>
                                    Incidents Over Time
                                </h4>
                                <div class="chart-container">
                                    <canvas id="incidentsOverTimeChart"></canvas>
                                </div>
                                <div class="chart-legend">
                                    <div class="legend-item">
                                        <div class="legend-color" style="background: #3b82f6;"></div>
                                        <span>Total Incidents</span>
                                    </div>
                                    <div class="legend-item">
                                        <div class="legend-color" style="background: #10b981;"></div>
                                        <span>Resolved</span>
                                    </div>
                                    <div class="legend-item">
                                        <div class="legend-color" style="background: #6b7280;"></div>
                                        <span>Cancelled</span>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="chart-card">
                                <h4 class="chart-title">
                                    <i class='bx bx-pie-chart-alt'></i>
                                    Emergency Type Distribution
                                </h4>
                                <div class="chart-container">
                                    <canvas id="emergencyTypesChart"></canvas>
                                </div>
                            </div>
                            
                            <div class="chart-card">
                                <h4 class="chart-title">
                                    <i class='bx bx-bar-chart-alt'></i>
                                    Severity Levels
                                </h4>
                                <div class="chart-container">
                                    <canvas id="severityChart"></canvas>
                                </div>
                            </div>
                        `;
                        
                        initializeCharts();
                    }, 100);
                });
            }
        }
    </script>
</body>
</html>