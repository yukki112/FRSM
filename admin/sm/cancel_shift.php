<?php
session_start();
require_once '../../config/db_connection.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

$user_id = $_SESSION['user_id'];
$data = json_decode(file_get_contents('php://input'), true);
$shift_id = isset($data['shift_id']) ? intval($data['shift_id']) : 0;

if ($shift_id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid shift ID']);
    exit();
}

// Check if user is admin
$user_query = "SELECT role FROM users WHERE id = ?";
$user_stmt = $pdo->prepare($user_query);
$user_stmt->execute([$user_id]);
$user = $user_stmt->fetch();

if (!$user || $user['role'] !== 'ADMIN') {
    echo json_encode(['success' => false, 'message' => 'Access denied']);
    exit();
}

// Update shift status to cancelled
$sql = "UPDATE shifts SET 
            status = 'cancelled',
            confirmation_status = 'declined',
            updated_at = NOW()
        WHERE id = ?";
    
$stmt = $pdo->prepare($sql);
$success = $stmt->execute([$shift_id]);

if ($success) {
    // Create notification
    $notification_sql = "INSERT INTO notifications (user_id, type, title, message) 
                        VALUES (?, 'shift_cancelled', 'Shift Cancelled', 'Shift #$shift_id has been cancelled by admin')";
    $notification_stmt = $pdo->prepare($notification_sql);
    $notification_stmt->execute([$user_id]);
    
    echo json_encode(['success' => true, 'message' => 'Shift cancelled successfully']);
} else {
    echo json_encode(['success' => false, 'message' => 'Failed to cancel shift']);
}
?>