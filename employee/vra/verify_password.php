<?php
session_start();

// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

error_log("=== PASSWORD VERIFICATION DEBUG ===");
error_log("Session ID: " . session_id());
error_log("Session user_id: " . ($_SESSION['user_id'] ?? 'NOT SET'));

if (!isset($_SESSION['user_id'])) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Unauthorized - No session user_id']);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    $password = $input['password'] ?? '';
    
    error_log("Password received: " . (!empty($password) ? 'YES' : 'EMPTY'));
    error_log("User ID from session: " . $_SESSION['user_id']);
    
    if (empty($password)) {
        echo json_encode(['success' => false, 'message' => 'Password is required']);
        exit();
    }
    
    $user_id = $_SESSION['user_id'];
    
    try {
        require_once '../../config/db_connection.php';
        
        // Get user's current password hash
        $query = "SELECT password, email FROM users WHERE id = ?";
        $stmt = $pdo->prepare($query);
        $stmt->execute([$user_id]);
        $user = $stmt->fetch();
        
        error_log("User found: " . ($user ? 'YES' : 'NO'));
        if ($user) {
            error_log("User email: " . $user['email']);
            error_log("Stored hash: " . $user['password']);
            error_log("Hash length: " . strlen($user['password']));
            error_log("Hash starts with: " . substr($user['password'], 0, 10));
            
            // Check if it's a bcrypt hash
            if (strpos($user['password'], '$2y$') === 0) {
                error_log("Hash format: BCRYPT");
            } else {
                error_log("Hash format: UNKNOWN - may not be hashed with password_hash()");
            }
        }
        
        // Test password verification
        if ($user) {
            $verification_result = password_verify($password, $user['password']);
            error_log("password_verify result: " . ($verification_result ? 'TRUE' : 'FALSE'));
            
            if ($verification_result) {
                error_log("SUCCESS: Password correct");
                echo json_encode(['success' => true, 'message' => 'Password verified']);
            } else {
                error_log("FAILED: Password incorrect");
                
                // TEMPORARY: Try direct comparison for testing
                if ($password === $user['password']) {
                    error_log("DIRECT COMPARISON: PASSED - password is stored in plain text!");
                    echo json_encode(['success' => true, 'message' => 'Password verified (plain text)']);
                } else {
                    error_log("DIRECT COMPARISON: FAILED");
                    echo json_encode(['success' => false, 'message' => 'Invalid password']);
                }
            }
        } else {
            echo json_encode(['success' => false, 'message' => 'User not found']);
        }
    } catch (Exception $e) {
        error_log("DATABASE ERROR: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
    }
} else {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
}

error_log("=== END DEBUG ===");
?>