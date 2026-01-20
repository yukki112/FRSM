<?php
session_start();
require_once '../config/db_connection.php';

header('Content-Type: application/json');

try {
    // Get all users who have registered faces
    $stmt = $pdo->prepare("
        SELECT id, email, first_name, last_name, face_registered 
        FROM users 
        WHERE face_registered = 1
        ORDER BY first_name
    ");
    $stmt->execute();
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if ($users) {
        echo json_encode([
            'success' => true,
            'users' => $users,
            'count' => count($users)
        ]);
    } else {
        echo json_encode([
            'success' => true,
            'users' => [],
            'count' => 0,
            'message' => 'No users with registered faces'
        ]);
    }
    
} catch (PDOException $e) {
    echo json_encode([
        'success' => false,
        'error' => 'Database error: ' . $e->getMessage()
    ]);
}
?>