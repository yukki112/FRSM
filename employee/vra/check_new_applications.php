<?php
session_start();
require_once '../../config/db_connection.php';

if (!isset($_SESSION['user_id'])) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

// Get the timestamp of the last check (you might want to store this in session or database)
$lastCheck = isset($_SESSION['last_application_check']) ? $_SESSION['last_application_check'] : date('Y-m-d H:i:s', strtotime('-1 hour'));

$query = "SELECT COUNT(*) as new_applications FROM volunteers WHERE created_at > ? AND status = 'pending'";
$stmt = $pdo->prepare($query);
$stmt->execute([$lastCheck]);
$result = $stmt->fetch();

// Update last check time
$_SESSION['last_application_check'] = date('Y-m-d H:i:s');

header('Content-Type: application/json');
echo json_encode([
    'success' => true, 
    'newApplications' => $result['new_applications']
]);
?>