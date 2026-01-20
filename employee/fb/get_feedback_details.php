<?php
session_start();
require_once '../../config/db_connection.php';

// Check if user is logged in and has permission
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

$user_id = $_SESSION['user_id'];
$query = "SELECT role FROM users WHERE id = ?";
$stmt = $pdo->prepare($query);
$stmt->execute([$user_id]);
$user = $stmt->fetch();

if (!$user || ($user['role'] !== 'ADMIN' && $user['role'] !== 'EMPLOYEE')) {
    echo json_encode(['success' => false, 'message' => 'Permission denied']);
    exit();
}

// Get feedback ID from request
if (!isset($_GET['id'])) {
    echo json_encode(['success' => false, 'message' => 'No feedback ID provided']);
    exit();
}

$feedback_id = (int)$_GET['id'];

// Fetch feedback details
$feedback_query = "SELECT * FROM feedbacks WHERE id = ?";
$feedback_stmt = $pdo->prepare($feedback_query);
$feedback_stmt->execute([$feedback_id]);
$feedback = $feedback_stmt->fetch();

if (!$feedback) {
    echo json_encode(['success' => false, 'message' => 'Feedback not found']);
    exit();
}

// Format the data
$feedback['created_at'] = date('Y-m-d H:i:s', strtotime($feedback['created_at']));

echo json_encode([
    'success' => true,
    'feedback' => $feedback
]);

$stmt = null;
$feedback_stmt = null;
?>