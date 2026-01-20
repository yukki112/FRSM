<?php
require_once '../config/db_connection.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = isset($_POST['name']) ? trim($_POST['name']) : null;
    $email = isset($_POST['email']) ? trim($_POST['email']) : null;
    $rating = isset($_POST['rating']) ? intval($_POST['rating']) : 5;
    $message = isset($_POST['message']) ? trim($_POST['message']) : '';
    $is_anonymous = isset($_POST['is_anonymous']) ? 1 : 0;
    
    // Validate inputs
    if (empty($message)) {
        echo json_encode(['success' => false, 'message' => 'Please provide your feedback message.']);
        exit;
    }
    
    if ($rating < 1 || $rating > 5) {
        $rating = 5;
    }
    
    // Get user ID if logged in
    $user_id = null;
    session_start();
    if (isset($_SESSION['user_id'])) {
        $user_id = $_SESSION['user_id'];
    }
    
    try {
        $stmt = $pdo->prepare("
            INSERT INTO feedbacks 
            (name, email, rating, message, is_anonymous, is_approved, user_id) 
            VALUES (?, ?, ?, ?, ?, 0, ?)
        ");
        
        $stmt->execute([
            $is_anonymous ? null : $name,
            $is_anonymous ? null : $email,
            $rating,
            $message,
            $is_anonymous,
            $user_id
        ]);
        
        echo json_encode([
            'success' => true, 
            'message' => 'Thank you for your feedback! It will be reviewed and may appear on our website.'
        ]);
        
    } catch (PDOException $e) {
        error_log("Feedback submission error: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'An error occurred. Please try again.']);
    }
} else {
    echo json_encode(['success' => false, 'message' => 'Invalid request method.']);
}