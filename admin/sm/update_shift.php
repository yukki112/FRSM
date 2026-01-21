<?php
session_start();
require_once '../../config/db_connection.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

$user_id = $_SESSION['user_id'];
$shift_id = isset($_POST['shift_id']) ? intval($_POST['shift_id']) : 0;
$status = isset($_POST['status']) ? $_POST['status'] : '';
$confirmation_status = isset($_POST['confirmation_status']) ? $_POST['confirmation_status'] : '';
$notes = isset($_POST['notes']) ? $_POST['notes'] : '';

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

// Update shift
$sql = "UPDATE shifts SET 
            status = ?,
            confirmation_status = ?,
            notes = ?,
            updated_at = NOW()
        WHERE id = ?";
    
$stmt = $pdo->prepare($sql);
$success = $stmt->execute([$status, $confirmation_status, $notes, $shift_id]);

if ($success) {
    // Create notification
    $notification_sql = "INSERT INTO notifications (user_id, type, title, message) 
                        VALUES (?, 'shift_updated', 'Shift Updated', 'Shift #$shift_id has been updated by admin')";
    $notification_stmt = $pdo->prepare($notification_sql);
    $notification_stmt->execute([$user_id]);
    
    echo json_encode(['success' => true, 'message' => 'Shift updated successfully']);
} else {
    echo json_encode(['success' => false, 'message' => 'Failed to update shift']);
}
?>