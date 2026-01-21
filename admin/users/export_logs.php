<?php
session_start();
require_once '../../config/db_connection.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit();
}

$user_id = $_SESSION['user_id'];
$query = "SELECT role FROM users WHERE id = ?";
$stmt = $pdo->prepare($query);
$stmt->execute([$user_id]);
$user = $stmt->fetch();

if (!$user || $user['role'] !== 'ADMIN') {
    header("Location: ../unauthorized.php");
    exit();
}

// Get filters from GET parameters
$filter_type = $_GET['type'] ?? 'all';
$filter_date = $_GET['date'] ?? '';
$filter_ip = $_GET['ip'] ?? '';
$filter_user = $_GET['user'] ?? '';
$search_query = $_GET['search'] ?? '';

// Build queries (same as in audit_logs.php)
$login_query = "SELECT 
    la.id,
    la.ip_address,
    la.email,
    la.attempt_time,
    la.successful,
    u.username,
    CONCAT(u.first_name, ' ', COALESCE(u.middle_name, ''), ' ', u.last_name) as user_full_name
FROM login_attempts la
LEFT JOIN users u ON la.email = u.email
WHERE 1=1";

$registration_query = "SELECT 
    ra.id,
    ra.ip_address,
    ra.email,
    ra.attempt_time,
    ra.successful,
    u.username,
    CONCAT(u.first_name, ' ', COALESCE(u.middle_name, ''), ' ', u.last_name) as user_full_name
FROM registration_attempts ra
LEFT JOIN users u ON ra.email = u.email
WHERE 1=1";

$incident_query = "SELECT 
    isl.id,
    isl.incident_id,
    isl.old_status,
    isl.new_status,
    isl.changed_by,
    isl.change_notes,
    isl.changed_at,
    CONCAT(u.first_name, ' ', COALESCE(u.middle_name, ''), ' ', u.last_name) as changed_by_name,
    ai.title as incident_title
FROM incident_status_logs isl
LEFT JOIN users u ON isl.changed_by = u.id
LEFT JOIN api_incidents ai ON isl.incident_id = ai.id
WHERE 1=1";

// Apply filters
$login_params = [];
$reg_params = [];
$incident_params = [];

if (!empty($filter_date)) {
    $login_query .= " AND DATE(la.attempt_time) = ?";
    $registration_query .= " AND DATE(ra.attempt_time) = ?";
    $incident_query .= " AND DATE(isl.changed_at) = ?";
    $login_params[] = $filter_date;
    $reg_params[] = $filter_date;
    $incident_params[] = $filter_date;
}

if (!empty($filter_ip)) {
    $login_query .= " AND la.ip_address LIKE ?";
    $registration_query .= " AND ra.ip_address LIKE ?";
    $login_params[] = "%$filter_ip%";
    $reg_params[] = "%$filter_ip%";
}

if (!empty($filter_user)) {
    $login_query .= " AND (la.email LIKE ? OR u.username LIKE ?)";
    $registration_query .= " AND (ra.email LIKE ? OR u.username LIKE ?)";
    $incident_query .= " AND u.username LIKE ?";
    $login_params[] = "%$filter_user%";
    $login_params[] = "%$filter_user%";
    $reg_params[] = "%$filter_user%";
    $reg_params[] = "%$filter_user%";
    $incident_params[] = "%$filter_user%";
}

if (!empty($search_query)) {
    $search_param = "%$search_query%";
    $login_query .= " AND (la.ip_address LIKE ? OR la.email LIKE ? OR la.attempt_time LIKE ?)";
    $registration_query .= " AND (ra.ip_address LIKE ? OR ra.email LIKE ? OR ra.attempt_time LIKE ?)";
    $incident_query .= " AND (isl.old_status LIKE ? OR isl.new_status LIKE ? OR isl.change_notes LIKE ? OR ai.title LIKE ?)";
    
    $login_params[] = $search_param;
    $login_params[] = $search_param;
    $login_params[] = $search_param;
    
    $reg_params[] = $search_param;
    $reg_params[] = $search_param;
    $reg_params[] = $search_param;
    
    $incident_params[] = $search_param;
    $incident_params[] = $search_param;
    $incident_params[] = $search_param;
    $incident_params[] = $search_param;
}

// Execute queries based on filter type
$csv_data = [];

if ($filter_type === 'all' || $filter_type === 'login') {
    $stmt = $pdo->prepare($login_query);
    $stmt->execute($login_params);
    $login_attempts = $stmt->fetchAll();
    
    foreach ($login_attempts as $log) {
        $csv_data[] = [
            'LOGIN',
            $log['id'],
            $log['ip_address'],
            $log['email'],
            $log['username'] ?? '',
            $log['attempt_time'],
            $log['successful'] ? 'SUCCESS' : 'FAILED',
            $log['successful'] ? 'Successful login' : 'Failed login'
        ];
    }
}

if ($filter_type === 'all' || $filter_type === 'registration') {
    $stmt = $pdo->prepare($registration_query);
    $stmt->execute($reg_params);
    $registration_attempts = $stmt->fetchAll();
    
    foreach ($registration_attempts as $log) {
        $csv_data[] = [
            'REGISTRATION',
            $log['id'],
            $log['ip_address'],
            $log['email'],
            $log['username'] ?? '',
            $log['attempt_time'],
            $log['successful'] ? 'SUCCESS' : 'FAILED',
            $log['successful'] ? 'Successful registration' : 'Failed registration'
        ];
    }
}

if ($filter_type === 'all' || $filter_type === 'incident') {
    $stmt = $pdo->prepare($incident_query);
    $stmt->execute($incident_params);
    $incident_logs = $stmt->fetchAll();
    
    foreach ($incident_logs as $log) {
        $csv_data[] = [
            'INCIDENT_CHANGE',
            $log['id'],
            $log['incident_id'],
            $log['incident_title'] ?? '',
            $log['changed_by_name'] ?? 'System',
            $log['changed_at'],
            $log['old_status'] . ' -> ' . $log['new_status'],
            $log['change_notes'] ?? ''
        ];
    }
}

// Generate CSV
$filename = 'audit_logs_' . date('Y-m-d_H-i-s') . '.csv';

header('Content-Type: text/csv');
header('Content-Disposition: attachment; filename="' . $filename . '"');

$output = fopen('php://output', 'w');

// Write headers based on filter type
if ($filter_type === 'login') {
    fputcsv($output, ['Log Type', 'ID', 'IP Address', 'Email', 'Username', 'Attempt Time', 'Status', 'Details']);
} elseif ($filter_type === 'registration') {
    fputcsv($output, ['Log Type', 'ID', 'IP Address', 'Email', 'Username', 'Attempt Time', 'Status', 'Details']);
} elseif ($filter_type === 'incident') {
    fputcsv($output, ['Log Type', 'ID', 'Incident ID', 'Incident Title', 'Changed By', 'Change Time', 'Status Change', 'Notes']);
} else {
    fputcsv($output, ['Log Type', 'ID', 'IP/Incident ID', 'Email/Incident Title', 'Username/Changed By', 'Time', 'Status/Change', 'Details/Notes']);
}

// Write data
foreach ($csv_data as $row) {
    fputcsv($output, $row);
}

fclose($output);
exit();