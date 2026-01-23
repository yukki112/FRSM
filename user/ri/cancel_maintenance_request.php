<?php
session_start();
require_once '../../config/db_connection.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

$id = $_GET['id'] ?? 0;
$user_id = $_SESSION['user_id'];

// Check if request belongs to user
$check_query = "SELECT id FROM maintenance_requests WHERE id = ? AND requested_by = ? AND status = 'pending'";
$check_stmt = $pdo->prepare($check_query);
$check_stmt->execute([$id, $user_id]);
$request = $check_stmt->fetch();

if (!$request) {
    echo json_encode(['success' => false, 'message' => 'Request not found or cannot be cancelled']);
    exit();
}

// Update request status
$update_query = "UPDATE maintenance_requests SET status = 'cancelled', completed_date = NOW() WHERE id = ?";
$update_stmt = $pdo->prepare($update_query);
$success = $update_stmt->execute([$id]);

if ($success) {
    echo json_encode(['success' => true, 'message' => 'Request cancelled']);
} else {
    echo json_encode(['success' => false, 'message' => 'Failed to cancel request']);
}
?>