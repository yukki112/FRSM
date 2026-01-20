<?php
session_start();
require_once '../config/db_connection.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true);
    $identifier = $data['identifier'] ?? '';
    
    if (empty($identifier)) {
        echo json_encode(['success' => false, 'error' => 'Email/username required']);
        exit;
    }
    
    try {
        // Check by email or username
        $stmt = $pdo->prepare("SELECT id, email, first_name, last_name FROM users WHERE email = ? OR username = ?");
        $stmt->execute([$identifier, $identifier]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($user) {
            echo json_encode([
                'success' => true,
                'user_id' => $user['id'],
                'email' => $user['email'],
                'first_name' => $user['first_name'],
                'last_name' => $user['last_name']
            ]);
        } else {
            echo json_encode(['success' => false, 'error' => 'User not found']);
        }
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'error' => 'Database error']);
    }
}
?>