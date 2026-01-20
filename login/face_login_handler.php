<?php
session_start();
require_once '../config/db_connection.php';
require_once '../includes/functions.php';

// Security headers
header("X-Frame-Options: DENY");
header("X-Content-Type-Options: nosniff");

// CSRF validation
if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
    header('Location: login.php?error=security_failed');
    exit();
}

// Check if this is a face login
if (!isset($_POST['face_login']) || $_POST['face_login'] != '1') {
    header('Location: login.php');
    exit();
}

$user_id = $_POST['user_id'] ?? null;
$email = $_POST['email'] ?? null;
$face_only = isset($_POST['face_only']) && $_POST['face_only'] == '1';

if (!$user_id || !$email) {
    header('Location: login.php?error=invalid_login');
    exit();
}

try {
    // Get user from database
    $stmt = $pdo->prepare("SELECT id, first_name, last_name, email, username, role, is_verified FROM users WHERE id = ? AND email = ?");
    $stmt->execute([$user_id, $email]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($user && $user['is_verified']) {
        // Regenerate session ID
        session_regenerate_id(true);
        
        // Set session variables
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['first_name'] = $user['first_name'];
        $_SESSION['last_name'] = $user['last_name'];
        $_SESSION['email'] = $user['email'];
        $_SESSION['username'] = $user['username'];
        $_SESSION['role'] = $user['role'];
        $_SESSION['login_time'] = time();
        $_SESSION['face_login'] = true;
        $_SESSION['face_only'] = $face_only;
        
        // Record login attempt
        $ip = $_SERVER['REMOTE_ADDR'];
        $stmt = $pdo->prepare("INSERT INTO login_attempts (ip_address, email, attempt_time, successful) VALUES (?, ?, NOW(), 1)");
        $stmt->execute([$ip, $user['email']]);
        
        // Clear failed attempts
        $stmt = $pdo->prepare("DELETE FROM login_attempts WHERE ip_address = ? AND successful = 0");
        $stmt->execute([$ip]);
        
        // Update last face login
        $stmt = $pdo->prepare("UPDATE users SET last_face_login = NOW() WHERE id = ?");
        $stmt->execute([$user['id']]);
        
        // Regenerate CSRF token
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        
        // Redirect based on role
        switch ($user['role']) {
            case 'ADMIN':
                header('Location: ../admin/admin_dashboard.php');
                break;
            case 'EMPLOYEE':
                header('Location: ../employee/employee_dashboard.php');
                break;
            case 'USER':
            default:
                header('Location: ../user/user_dashboard.php');
                break;
        }
        exit();
    } else {
        header('Location: login.php?error=face_login_failed');
        exit();
    }
} catch (PDOException $e) {
    error_log("Face login error: " . $e->getMessage());
    header('Location: login.php?error=database_error');
    exit();
}
?>