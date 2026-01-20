<?php
session_start();
require_once '../config/db_connection.php';
require_once '../includes/functions.php';

// Security headers
header("X-Frame-Options: DENY");
header("X-Content-Type-Options: nosniff");
header("Referrer-Policy: strict-origin-when-cross-origin");
header("Content-Security-Policy: default-src 'self' http://127.0.0.1:5001; script-src 'self' 'unsafe-inline' 'unsafe-eval'; style-src 'self' 'unsafe-inline';");

// Function to redirect based on user role
function redirectBasedOnRole($role) {
    switch ($role) {
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
}

// Redirect to appropriate dashboard if already logged in
if (isset($_SESSION['user_id'])) {
    redirectBasedOnRole($_SESSION['role']);
    exit();
}

// CSRF token generation
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Login attempt tracking
$ip = $_SERVER['REMOTE_ADDR'];
$max_attempts = 5;
$lockout_time = 15; // minutes

// Check if IP is locked out
$stmt = $pdo->prepare("SELECT COUNT(*) FROM login_attempts WHERE ip_address = ? AND attempt_time > DATE_SUB(NOW(), INTERVAL ? MINUTE) AND successful = 0");
$stmt->execute([$ip, $lockout_time]);
$failed_attempts = $stmt->fetchColumn();

if ($failed_attempts >= $max_attempts) {
    $errors['general'] = "Too many failed login attempts. Please try again in $lockout_time minutes.";
}

$errors = [];
$show_resend_option = false;
$unverified_email = '';
$auto_sent_verification = false;

// Face Login Handler
if (isset($_POST['face_login']) && $_POST['face_login'] == '1') {
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $errors['general'] = "Security validation failed.";
    } else {
        $face_user_id = $_POST['face_user_id'] ?? null;
        $face_user_email = $_POST['face_user_email'] ?? null;
        
        if ($face_user_id && $face_user_email) {
            try {
                // Get user from database
                $stmt = $pdo->prepare("SELECT id, first_name, last_name, email, username, password, role, is_verified FROM users WHERE id = ? AND email = ?");
                $stmt->execute([$face_user_id, $face_user_email]);
                $user = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($user && $user['is_verified']) {
                    // Verify password (for additional security)
                    $provided_password = $_POST['face_password'] ?? '';
                    if (password_verify($provided_password, $user['password'])) {
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
                        
                        // Record successful login
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
                        
                        redirectBasedOnRole($user['role']);
                        exit();
                    } else {
                        $errors['general'] = "Face verification failed. Please try again.";
                    }
                } else {
                    $errors['general'] = "User not found or not verified.";
                }
            } catch (PDOException $e) {
                error_log("Face login error: " . $e->getMessage());
                $errors['general'] = "Login failed. Please try again.";
            }
        }
    }
}

// Traditional Login Handler
if ($_SERVER['REQUEST_METHOD'] == 'POST' && !isset($_POST['face_login'])) {
    // CSRF token validation
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $errors['general'] = "Security validation failed. Please try again.";
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    } else {
        // Sanitize input data
        $login_identifier = isset($_POST['login_identifier']) ? sanitize_input($_POST['login_identifier']) : '';
        $password = isset($_POST['password']) ? $_POST['password'] : '';
        $remember = isset($_POST['remember']) ? true : false;
        
        // Validation
        if (empty($login_identifier)) {
            $errors['login_identifier'] = "Email or username is required";
        }
        
        if (empty($password)) {
            $errors['password'] = "Password is required";
        }
        
        // If no errors, proceed with login
        if (empty($errors)) {
            try {
                // Check if user exists by email OR username and is verified
                $stmt = $pdo->prepare("SELECT id, first_name, last_name, email, username, password, role, is_verified FROM users WHERE email = ? OR username = ?");
                $stmt->execute([$login_identifier, $login_identifier]);
                $user = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($user && $user['is_verified']) {
                    // Verify password
                    if (password_verify($password, $user['password'])) {
                        // Check if password needs rehashing
                        if (password_needs_rehash($user['password'], PASSWORD_DEFAULT, ['cost' => 12])) {
                            $newHash = password_hash($password, PASSWORD_DEFAULT, ['cost' => 12]);
                            $stmt = $pdo->prepare("UPDATE users SET password = ? WHERE id = ?");
                            $stmt->execute([$newHash, $user['id']]);
                        }
                        
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
                        
                        // Record successful login attempt
                        $stmt = $pdo->prepare("INSERT INTO login_attempts (ip_address, email, attempt_time, successful) VALUES (?, ?, NOW(), 1)");
                        $stmt->execute([$ip, $user['email']]);
                        
                        // Clear failed attempts for this IP
                        $stmt = $pdo->prepare("DELETE FROM login_attempts WHERE ip_address = ? AND successful = 0");
                        $stmt->execute([$ip]);
                        
                        // Regenerate CSRF token
                        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
                        
                        // Redirect based on role
                        redirectBasedOnRole($user['role']);
                        exit();
                    } else {
                        // Invalid password
                        $errors['general'] = "Invalid email/username or password";
                    }
                } else if ($user && !$user['is_verified']) {
                    // User exists but email is not verified
                    $errors['general'] = "Your account is not verified. Please check your inbox for the verification link we sent you.";
                    $show_resend_option = true;
                    $unverified_email = $user['email'];
                    $_SESSION['unverified_email'] = $user['email'];
                    
                    // AUTOMATICALLY SEND VERIFICATION EMAIL
                    try {
                        $verification_code = generate_verification_code();
                        $expiry_time = date('Y-m-d H:i:s', strtotime('+30 minutes'));
                        
                        $stmt = $pdo->prepare("UPDATE users SET verification_code = ?, code_expiry = ? WHERE email = ?");
                        if ($stmt->execute([$verification_code, $expiry_time, $user['email']])) {
                            $stmt = $pdo->prepare("INSERT INTO verification_codes (email, code, expiry) VALUES (?, ?, ?)");
                            $stmt->execute([$user['email'], $verification_code, $expiry_time]);
                            
                            if (send_verification_email_with_link($user['email'], $user['first_name'], $verification_code)) {
                                $auto_sent_verification = true;
                                $errors['general'] = "Your account is not verified. We've automatically sent a new verification link to your email. Please check your inbox and spam folder.";
                            } else {
                                $errors['general'] = "Your account is not verified. Failed to send verification email. Please try again.";
                            }
                        } else {
                            $errors['general'] = "Your account is not verified. Failed to generate verification code. Please try again.";
                        }
                    } catch (PDOException $e) {
                        error_log("Auto resend verification error: " . $e->getMessage());
                        $errors['general'] = "Your account is not verified. An error occurred while sending verification email. Please try again.";
                    }
                } else {
                    // User not found
                    $errors['general'] = "Invalid email/username or password";
                }
                
                // Record failed login attempt
                if (!empty($errors)) {
                    $stmt = $pdo->prepare("INSERT INTO login_attempts (ip_address, email, attempt_time, successful) VALUES (?, ?, NOW(), 0)");
                    $stmt->execute([$ip, $login_identifier]);
                }
                
            } catch (PDOException $e) {
                error_log("Login error: " . $e->getMessage());
                $errors['general'] = "Login failed. Please try again.";
            }
        }
        
        // Regenerate CSRF token after form submission
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
}

// Handle resend verification request
if (isset($_POST['resend_verification'])) {
    $email = isset($_POST['resend_email']) ? sanitize_input($_POST['resend_email']) : '';
    
    if (empty($email)) {
        $errors['general'] = "Email address is required to resend verification.";
    } else {
        try {
            // Check if user exists and is not verified
            $stmt = $pdo->prepare("SELECT id, first_name, is_verified FROM users WHERE email = ?");
            $stmt->execute([$email]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($user && !$user['is_verified']) {
                // Generate new verification code
                $verification_code = generate_verification_code();
                $expiry_time = date('Y-m-d H:i:s', strtotime('+30 minutes'));
                
                // Update user with new verification code
                $stmt = $pdo->prepare("UPDATE users SET verification_code = ?, code_expiry = ? WHERE email = ?");
                if ($stmt->execute([$verification_code, $expiry_time, $email])) {
                    
                    // Also store in verification_codes table for redundancy
                    $stmt = $pdo->prepare("INSERT INTO verification_codes (email, code, expiry) VALUES (?, ?, ?)");
                    $stmt->execute([$email, $verification_code, $expiry_time]);
                    
                    // Send verification email with link
                    if (send_verification_email_with_link($email, $user['first_name'], $verification_code)) {
                        $success_message = "A new verification email has been sent to your email address. Please check your inbox and spam folder.";
                    } else {
                        $errors['general'] = "Failed to send verification email. Please try again.";
                    }
                } else {
                    $errors['general'] = "Failed to generate verification code. Please try again.";
                }
            } else {
                $errors['general'] = "Email not found or already verified.";
            }
        } catch (PDOException $e) {
            error_log("Resend verification error: " . $e->getMessage());
            $errors['general'] = "An error occurred. Please try again.";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - Fire & Rescue Services Management</title>
    <link rel="icon" type="image/png" href="../assets/images/logo.png">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Inter', 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        
        :root {
            --primary-color: #ff6b6b;
            --primary-dark: #ff5252;
            --secondary-color: #ff8e8e;
            --secondary-dark: #ff6b6b;
            --background-color: #fff5f5;
            --text-color: #1f2937;
            --text-light: #6b7280;
            --border-color: #ffd6d6;
            --card-bg: #ffffff;
            --sidebar-bg: #ffffff;
            
            /* Icon colors */
            --icon-red: #ff6b6b;
            --icon-blue: #3b82f6;
            --icon-green: #10b981;
            --icon-purple: #8b5cf6;
            --icon-indigo: #6366f1;
            --icon-cyan: #06b6d4;
            --icon-orange: #f97316;
            --icon-pink: #ec4899;
            --icon-teal: #14b8a6;
            
            /* Icon background colors */
            --icon-bg-red: #ffeaea;
            --icon-bg-blue: #dbeafe;
            --icon-bg-green: #dcfce7;
            --icon-bg-purple: #f3e8ff;
            --icon-bg-indigo: #e0e7ff;
            --icon-bg-cyan: #cffafe;
            --icon-bg-orange: #ffedd5;
            --icon-bg-pink: #fce7f3;
            --icon-bg-teal: #ccfbf1;
            
            /* Chart colors */
            --chart-red: #ff6b6b;
            --chart-orange: #f97316;
            --chart-green: #10b981;
            --chart-blue: #3b82f6;
            --chart-purple: #8b5cf6;
            --chart-pink: #ec4899;
        }
        
        /* Dark mode variables */
        .dark-mode {
            --background-color: #0f172a;
            --text-color: #f1f5f9;
            --text-light: #94a3b8;
            --border-color: #334155;
            --card-bg: #1e293b;
            --sidebar-bg: #1e293b;
        }
        
        body {
            min-height: 100vh;
            background: linear-gradient(135deg, var(--primary-color) 0%, var(--primary-dark) 50%, var(--secondary-dark) 100%);
            position: relative;
            overflow-x: hidden;
            transition: all 0.6s cubic-bezier(0.4, 0, 0.2, 1);
        }
        
        /* Enhanced animated background with mesh gradient effect */
        .bg-decoration {
            position: fixed;
            border-radius: 50%;
            opacity: 0.15;
            z-index: 0;
            pointer-events: none;
            filter: blur(80px);
        }
        
        .bg-decoration-1 {
            width: 700px;
            height: 700px;
            background: radial-gradient(circle, var(--icon-green) 0%, transparent 70%);
            top: -250px;
            left: -250px;
            animation: float 25s ease-in-out infinite;
        }
        
        .bg-decoration-2 {
            width: 500px;
            height: 500px;
            background: radial-gradient(circle, var(--icon-blue) 0%, transparent 70%);
            bottom: -150px;
            right: -150px;
            animation: float 20s ease-in-out infinite reverse;
        }
        
        .bg-decoration-3 {
            width: 400px;
            height: 400px;
            background: radial-gradient(circle, var(--icon-purple) 0%, transparent 70%);
            top: 40%;
            left: 20%;
            animation: float 30s ease-in-out infinite;
        }
        
        .bg-decoration-4 {
            width: 350px;
            height: 350px;
            background: radial-gradient(circle, rgba(255, 255, 255, 0.4) 0%, transparent 70%);
            top: 60%;
            right: 25%;
            animation: float 22s ease-in-out infinite reverse;
        }
        
        @keyframes float {
            0%, 100% { transform: translate(0, 0) scale(1) rotate(0deg); }
            33% { transform: translate(60px, -60px) scale(1.15) rotate(120deg); }
            66% { transform: translate(-40px, 40px) scale(0.85) rotate(240deg); }
        }
        
        /* Enhanced watermark with glow effect */
        .watermark-logo {
            position: fixed;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            width: 700px;
            height: 700px;
            opacity: 0.12;
            z-index: 0;
            pointer-events: none;
            transition: opacity 0.6s ease;
            animation: floatWatermark 25s ease-in-out infinite;
            filter: drop-shadow(0 0 60px rgba(255, 107, 107, 0.3));
        }
        
        @keyframes floatWatermark {
            0%, 100% { transform: translate(-50%, -50%) scale(1) rotate(0deg); }
            50% { transform: translate(-50%, -52%) scale(1.08) rotate(5deg); }
        }
        
        /* Enhanced dark mode toggle with gradient */
        .dark-mode-toggle {
            position: fixed;
            top: 40px;
            right: 20px;
            background: linear-gradient(135deg, rgba(255, 107, 107, 0.25), rgba(255, 82, 82, 0.25));
            backdrop-filter: blur(15px);
            border: 2px solid rgba(255, 255, 255, 0.35);
            color: white;
            width: 55px;
            height: 55px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            font-size: 22px;
            transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
            z-index: 1000;
            opacity: 0;
            animation: slideInRight 0.8s ease forwards 0.3s;
            box-shadow: 0 8px 25px rgba(0,0,0,0.3);
        }
        
        .dark-mode-toggle:hover {
            background: linear-gradient(135deg, rgba(255, 107, 107, 0.4), rgba(255, 82, 82, 0.4));
            transform: rotate(180deg) scale(1.15);
            box-shadow: 0 12px 35px rgba(255, 107, 107, 0.4);
        }
        
        .back-button {
            position: fixed;
            top: 20px;
            left: 20px;
            background: linear-gradient(135deg, rgba(255, 107, 107, 0.25), rgba(255, 82, 82, 0.25));
            backdrop-filter: blur(15px);
            border: 2px solid rgba(255, 255, 255, 0.35);
            color: white;
            padding: 14px 28px;
            border-radius: 50px;
            text-decoration: none;
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 12px;
            transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
            z-index: 1000;
            opacity: 0;
            animation: slideInLeft 0.8s ease forwards 0.5s;
            box-shadow: 0 8px 25px rgba(0,0,0,0.3);
            font-size: 15px;
        }
        
        .back-button:hover {
            background: linear-gradient(135deg, rgba(255, 107, 107, 0.4), rgba(255, 82, 82, 0.4));
            transform: translateX(-8px);
            box-shadow: 0 12px 35px rgba(255, 107, 107, 0.4);
        }
        
        /* Enhanced left side branding with glass morphism */
        .logo-left {
            position: fixed;
            top: 50%;
            left: 180px;
            transform: translateY(-50%);
            z-index: 1;
            opacity: 0;
            animation: fadeInScale 1.2s ease forwards 1s;
            text-align: center;
        }
        
        .logo-left img {
            width: 240px;
            height: auto;
            filter: drop-shadow(0 20px 50px rgba(0,0,0,0.5)) drop-shadow(0 0 30px rgba(255, 107, 107, 0.3));
            margin-bottom: 35px;
            animation: pulse 4s ease-in-out infinite;
        }
        
        .logo-left h1 {
            color: white;
            font-size: 48px;
            font-weight: 900;
            margin-bottom: 18px;
            text-shadow: 0 6px 25px rgba(0,0,0,0.4), 0 0 40px rgba(255, 107, 107, 0.3);
            letter-spacing: 3px;
            background: linear-gradient(135deg, #ff8e8e, #FFF, #ff8e8e);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }
        
        .logo-left .tagline {
            color: rgba(255, 255, 255, 0.95);
            font-size: 22px;
            font-weight: 600;
            margin-bottom: 45px;
            text-shadow: 0 3px 15px rgba(0,0,0,0.3);
            letter-spacing: 1px;
        }
        
        /* Enhanced security features with gradient borders */
        .security-features {
            background: linear-gradient(135deg, rgba(255, 107, 107, 0.12), rgba(255, 82, 82, 0.12));
            backdrop-filter: blur(20px);
            border: 2px solid rgba(255, 255, 255, 0.3);
            border-radius: 25px;
            padding: 35px;
            margin-top: 25px;
            box-shadow: 0 15px 50px rgba(0,0,0,0.3), inset 0 1px 0 rgba(255,255,255,0.2);
            position: relative;
            overflow: hidden;
        }
        
        .security-features::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 2px;
            background: linear-gradient(90deg, transparent, var(--icon-red), transparent);
            animation: shimmer 3s infinite;
        }
        
        @keyframes shimmer {
            0%, 100% { transform: translateX(-100%); }
            50% { transform: translateX(100%); }
        }
        
        .security-features h3 {
            color: white;
            font-size: 22px;
            margin-bottom: 25px;
            display: flex;
            align-items: center;
            gap: 12px;
            font-weight: 700;
        }
        
        .security-item {
            display: flex;
            align-items: center;
            gap: 18px;
            color: rgba(255, 255, 255, 0.95);
            margin-bottom: 18px;
            font-size: 16px;
            transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
            padding: 8px;
            border-radius: 12px;
        }
        
        .security-item:last-child {
            margin-bottom: 0;
        }
        
        .security-item:hover {
            transform: translateX(8px);
            background: rgba(255, 255, 255, 0.08);
        }
        
        .security-item i {
            width: 40px;
            height: 40px;
            background: linear-gradient(135deg, rgba(255, 82, 82, 0.4), rgba(255, 107, 107, 0.3));
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 18px;
            box-shadow: 0 4px 15px rgba(255, 82, 82, 0.3);
            transition: all 0.3s ease;
        }
        
        .security-item:hover i {
            transform: scale(1.1) rotate(5deg);
            box-shadow: 0 6px 20px rgba(255, 107, 107, 0.5);
        }
        
        /* Enhanced login container with glass morphism and organic shape */
        .login-container {
            position: fixed;
            right: 100px;
            top: 50%;
            transform: translateY(-50%);
            width: 500px;
            background: var(--card-bg);
            backdrop-filter: blur(25px);
            border-radius: 45px 35px 45px 35px;
            padding: 45px 40px;
            box-shadow: 0 30px 80px rgba(0,0,0,0.3), 
                        0 0 0 1px rgba(255, 255, 255, 0.25),
                        inset 0 1px 0 rgba(255, 255, 255, 0.3);
            z-index: 10;
            opacity: 0;
            animation: slideInRight 1.2s ease forwards 1.5s;
            border: 1px solid rgba(255, 255, 255, 0.25);
            transition: all 0.6s ease;
            overflow: hidden;
            max-height: 90vh;
            overflow-y: auto;
        }
        
        .login-container::-webkit-scrollbar {
            width: 6px;
        }
        
        .login-container::-webkit-scrollbar-track {
            background: transparent;
        }
        
        .login-container::-webkit-scrollbar-thumb {
            background: var(--primary-color);
            border-radius: 10px;
        }
        
        .login-container::before {
            content: '';
            position: absolute;
            top: -50%;
            left: -50%;
            width: 200%;
            height: 200%;
            background: linear-gradient(45deg, transparent, rgba(255, 107, 107, 0.05), transparent);
            animation: rotate-gradient 10s linear infinite;
        }
        
        @keyframes rotate-gradient {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
        
        .login-container > * {
            position: relative;
            z-index: 1;
        }
        
        /* Enhanced logo with bounce animation */
        .login-logo {
            text-align: center;
            margin-bottom: 25px;
        }
        
        .login-logo img {
            width: 100px;
            height: auto;
            filter: drop-shadow(0 8px 20px rgba(0,0,0,0.3)) drop-shadow(0 0 20px rgba(255, 107, 107, 0.2));
            animation: bounce 3s ease-in-out infinite;
        }
        
        @keyframes bounce {
            0%, 100% { transform: translateY(0); }
            50% { transform: translateY(-15px); }
        }
        
        .login-header {
            text-align: center;
            margin-bottom: 30px;
        }
        
        .login-header h2 {
            color: var(--text-color);
            font-size: 32px;
            margin-bottom: 10px;
            font-weight: 800;
            transition: color 0.3s ease;
            background: linear-gradient(135deg, var(--text-color), var(--primary-color));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }
        
        .login-header p {
            color: var(--text-light);
            font-size: 15px;
            transition: color 0.3s ease;
            font-weight: 500;
        }
        
        .form-group {
            margin-bottom: 22px;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 10px;
            font-weight: 700;
            color: var(--text-color);
            font-size: 14px;
            transition: color 0.3s ease;
            letter-spacing: 0.5px;
        }
        
        .input-wrapper {
            position: relative;
        }
        
        .input-wrapper i {
            position: absolute;
            left: 18px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--text-light);
            font-size: 16px;
            transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
        }
        
        .form-group input {
            width: 100%;
            padding: 15px 20px 15px 50px;
            border: 2px solid var(--border-color);
            border-radius: 15px;
            font-size: 15px;
            transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
            background: var(--card-bg);
            color: var(--text-color);
            font-weight: 500;
        }
        
        .form-group input:focus {
            outline: none;
            border-color: var(--primary-color);
            box-shadow: 0 0 0 5px rgba(255, 107, 107, 0.15), 0 8px 20px rgba(255, 107, 107, 0.1);
            transform: translateY(-3px);
        }
        
        .form-group input:focus + .input-wrapper i {
            color: var(--primary-color);
            transform: translateY(-50%) scale(1.15);
        }
        
        .password-toggle {
            position: absolute;
            right: 55px;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            color: var(--text-light);
            cursor: pointer;
            font-size: 17px;
            transition: all 0.3s ease;
        }
        
        .password-toggle:hover {
            color: var(--primary-color);
            transform: translateY(-50%) scale(1.25);
        }
        
        .form-options {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 24px;
        }
        
        .remember {
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .remember input {
            width: 16px;
            height: 16px;
            accent-color: var(--primary-color);
        }
        
        .remember label {
            margin-bottom: 0;
            font-size: 14px;
            color: var(--text-light);
        }
        
        .forgot-password {
            color: var(--primary-color);
            font-size: 14px;
            font-weight: 500;
            text-decoration: none;
        }
        
        .forgot-password:hover {
            text-decoration: underline;
        }
        
        /* Enhanced button with gradient and ripple effect */
        .btn-primary {
            width: 100%;
            padding: 16px;
            background: linear-gradient(135deg, var(--primary-color) 0%, var(--primary-dark) 50%, var(--secondary-dark) 100%);
            color: white;
            border: none;
            border-radius: 15px;
            font-size: 17px;
            font-weight: 800;
            cursor: pointer;
            transition: all 0.5s cubic-bezier(0.4, 0, 0.2, 1);
            box-shadow: 0 8px 25px rgba(255, 107, 107, 0.5);
            position: relative;
            overflow: hidden;
            letter-spacing: 1px;
        }
        
        .btn-primary::before {
            content: '';
            position: absolute;
            top: 50%;
            left: 50%;
            width: 0;
            height: 0;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.25);
            transform: translate(-50%, -50%);
            transition: width 0.8s, height 0.8s;
        }
        
        .btn-primary:hover::before {
            width: 400px;
            height: 400px;
        }
        
        .btn-primary:hover {
            transform: translateY(-4px);
            box-shadow: 0 15px 40px rgba(255, 107, 107, 0.6), 0 0 30px rgba(255, 107, 107, 0.3);
        }
        
        .btn-primary:active {
            transform: translateY(-2px);
        }
        
        .register-link {
            text-align: center;
            margin-top: 25px;
            color: var(--text-light);
            font-size: 14px;
            transition: color 0.3s ease;
            font-weight: 500;
        }
        
        .register-link a {
            color: var(--primary-color);
            text-decoration: none;
            font-weight: 800;
            transition: all 0.3s ease;
            position: relative;
        }
        
        .register-link a::after {
            content: '';
            position: absolute;
            bottom: -2px;
            left: 0;
            width: 0;
            height: 2px;
            background: var(--primary-color);
            transition: width 0.3s ease;
        }
        
        .register-link a:hover::after {
            width: 100%;
        }
        
        .footer {
            text-align: center;
            margin-top: 25px;
            font-size: 13px;
            color: var(--text-light);
            transition: color 0.3s ease;
            font-weight: 600;
            letter-spacing: 0.5px;
        }
        
        /* Enhanced error message */
        .error-message {
            color: #dc3545;
            background: linear-gradient(135deg, rgba(220, 53, 69, 0.15), rgba(220, 53, 69, 0.08));
            border: 2px solid rgba(220, 53, 69, 0.5);
            padding: 14px 18px;
            border-radius: 15px;
            margin-bottom: 22px;
            font-size: 14px;
            animation: shake 0.6s ease;
            box-shadow: 0 4px 15px rgba(220, 53, 69, 0.2);
            font-weight: 600;
        }
        
        @keyframes shake {
            0%, 100% { transform: translateX(0); }
            25% { transform: translateX(-12px); }
            75% { transform: translateX(12px); }
        }
        
        /* Enhanced success message */
        .success-message {
            color: #28a745;
            background: linear-gradient(135deg, rgba(40, 167, 69, 0.15), rgba(40, 167, 69, 0.08));
            border: 2px solid rgba(40, 167, 69, 0.5);
            padding: 14px 18px;
            border-radius: 15px;
            margin-bottom: 22px;
            font-size: 14px;
            animation: slideDown 0.6s ease;
            box-shadow: 0 4px 15px rgba(40, 167, 69, 0.2);
            font-weight: 600;
        }
        
        @keyframes slideDown {
            from {
                opacity: 0;
                transform: translateY(-25px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        /* Resend verification section */
        .resend-verification {
            margin-top: 20px;
            padding: 20px;
            background: linear-gradient(135deg, rgba(255, 107, 107, 0.08), rgba(255, 82, 82, 0.05));
            border-radius: 15px;
            border-left: 4px solid var(--primary-color);
            text-align: center;
        }
        
        .resend-verification p {
            color: var(--text-light);
            font-size: 14px;
            margin-bottom: 15px;
            font-weight: 500;
        }
        
        .resend-btn {
            background: transparent;
            border: 2px solid var(--primary-color);
            color: var(--primary-color);
            padding: 10px 20px;
            border-radius: 10px;
            cursor: pointer;
            font-size: 14px;
            font-weight: 600;
            transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
        }
        
        .resend-btn:hover {
            background: var(--primary-color);
            color: white;
            transform: translateY(-3px);
            box-shadow: 0 8px 20px rgba(255, 107, 107, 0.3);
        }
        
        @keyframes slideInLeft {
            from {
                opacity: 0;
                transform: translateX(-60px);
            }
            to {
                opacity: 1;
                transform: translateX(0);
            }
        }
        
        @keyframes slideInRight {
            from {
                opacity: 0;
                transform: translate(60px, -50%);
            }
            to {
                opacity: 1;
                transform: translate(0, -50%);
            }
        }
        
        @keyframes fadeInScale {
            from {
                opacity: 0;
                transform: translateY(-50%) scale(0.85);
            }
            to {
                opacity: 1;
                transform: translateY(-50%) scale(1);
            }
        }
        
        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }
        
        @keyframes pulse {
            0%, 100% {
                transform: scale(1);
            }
            50% {
                transform: scale(1.1);
            }
        }
        
        /* Loading state */
        .btn-primary.loading {
            pointer-events: none;
            opacity: 0.7;
            position: relative;
        }

        .btn-primary.loading::after {
            content: "";
            position: absolute;
            width: 20px;
            height: 20px;
            top: 50%;
            left: 50%;
            margin-left: -10px;
            margin-top: -10px;
            border: 2px solid #ffffff;
            border-radius: 50%;
            border-top-color: transparent;
            animation: spin 1s ease-in-out infinite;
        }

        @keyframes spin {
            to { transform: rotate(360deg); }
        }
        
        /* ========== RESPONSIVE DESIGN ========== */
        
        /* Large screens (1200px and above) */
        @media (min-width: 1200px) {
            .logo-left {
                left: 180px;
            }
            
            .login-container {
                right: 100px;
                width: 500px;
            }
        }
        
        /* Medium screens (992px to 1199px) */
        @media (max-width: 1199px) and (min-width: 992px) {
            .logo-left {
                left: 80px;
            }
            
            .logo-left img {
                width: 200px;
            }
            
            .logo-left h1 {
                font-size: 38px;
            }
            
            .logo-left .tagline {
                font-size: 18px;
            }
            
            .login-container {
                right: 60px;
                width: 450px;
            }
        }
        
        /* Small screens (768px to 991px) */
        @media (max-width: 991px) and (min-width: 768px) {
            .logo-left {
                left: 40px;
            }
            
            .logo-left img {
                width: 180px;
            }
            
            .logo-left h1 {
                font-size: 32px;
            }
            
            .logo-left .tagline {
                font-size: 16px;
            }
            
            .login-container {
                right: 40px;
                width: 420px;
            }
            
            .security-features {
                padding: 25px;
            }
            
            .security-features h3 {
                font-size: 18px;
            }
            
            .security-item {
                font-size: 14px;
            }
        }
        
        /* Mobile screens (max-width: 767px) */
        @media (max-width: 767px) {
            body {
                padding: 20px;
                display: flex;
                flex-direction: column;
                justify-content: center;
                align-items: center;
                min-height: 100vh;
            }
            
            .logo-left {
                position: relative;
                top: auto;
                left: auto;
                transform: none;
                margin-bottom: 30px;
                text-align: center;
                width: 100%;
                animation: fadeIn 1s ease forwards;
            }
            
            .logo-left img {
                width: 120px;
                margin-bottom: 15px;
            }
            
            .logo-left h1 {
                font-size: 28px;
                margin-bottom: 10px;
            }
            
            .logo-left .tagline {
                font-size: 14px;
                margin-bottom: 20px;
            }
            
            .security-features {
                display: none;
            }
            
            .login-container {
                position: relative;
                right: auto;
                top: auto;
                transform: none;
                width: 100%;
                max-width: 400px;
                margin: 0 auto;
                padding: 30px 25px;
                border-radius: 25px;
                animation: fadeIn 1s ease forwards 0.5s;
            }
            
            .login-logo img {
                width: 80px;
            }
            
            .login-header h2 {
                font-size: 26px;
            }
            
            .login-header p {
                font-size: 14px;
            }
            
            .form-options {
                flex-direction: column;
                gap: 15px;
                align-items: flex-start;
            }
            
            .forgot-password {
                font-size: 13px;
            }
            
            .watermark-logo {
                width: 400px;
                height: 400px;
            }
            
            .bg-decoration {
                display: none;
            }
            
            .dark-mode-toggle {
                top: 10px;
                right: 10px;
                width: 45px;
                height: 45px;
                font-size: 18px;
            }
            
            .back-button {
                top: 10px;
                left: 10px;
                padding: 10px 20px;
                font-size: 13px;
            }
        }
        
        /* Extra small screens (max-width: 480px) */
        @media (max-width: 480px) {
            body {
                padding: 15px;
            }
            
            .logo-left img {
                width: 100px;
            }
            
            .logo-left h1 {
                font-size: 24px;
            }
            
            .logo-left .tagline {
                font-size: 13px;
            }
            
            .login-container {
                padding: 25px 20px;
                border-radius: 20px;
            }
            
            .login-logo img {
                width: 70px;
            }
            
            .login-header h2 {
                font-size: 22px;
            }
            
            .login-header p {
                font-size: 13px;
            }
            
            .form-group input {
                padding: 12px 15px 12px 45px;
                font-size: 14px;
            }
            
            .input-wrapper i {
                left: 15px;
                font-size: 14px;
            }
            
            .password-toggle {
                right: 45px;
                font-size: 15px;
            }
            
            .btn-primary {
                padding: 14px;
                font-size: 16px;
            }
            
            .register-link {
                font-size: 13px;
            }
            
            .footer {
                font-size: 12px;
            }
            
            .dark-mode-toggle {
                width: 40px;
                height: 40px;
                font-size: 16px;
                margin-top: 17px;
            }
            
            .back-button {
                padding: 8px 16px;
                font-size: 12px;
            }
        }
        
        /* Very small screens (max-width: 360px) */
        @media (max-width: 360px) {
            .login-container {
                padding: 20px 15px;
            }
            
            .form-group input {
                padding: 10px 12px 10px 40px;
            }
            
            .input-wrapper i {
                left: 12px;
            }
            
            .password-toggle {
                right: 40px;
            }
            
            .btn-primary {
                padding: 12px;
                font-size: 15px;
            }
        }
        
        /* Landscape orientation for mobile */
        @media (max-height: 600px) and (max-width: 767px) {
            body {
                padding: 10px;
            }
            
            .logo-left {
                margin-bottom: 15px;
            }
            
            .logo-left img {
                width: 80px;
                margin-bottom: 10px;
            }
            
            .logo-left h1 {
                font-size: 20px;
                margin-bottom: 5px;
            }
            
            .logo-left .tagline {
                font-size: 12px;
                margin-bottom: 10px;
            }
            
            .login-container {
                max-height: 80vh;
                overflow-y: auto;
                padding: 20px 15px;
            }
            
            .login-logo {
                margin-bottom: 15px;
            }
            
            .login-logo img {
                width: 60px;
            }
            
            .login-header {
                margin-bottom: 20px;
            }
            
            .login-header h2 {
                font-size: 20px;
            }
            
            .login-header p {
                font-size: 12px;
            }
            
            .form-group {
                margin-bottom: 15px;
            }
        }


      /* Face Login Modal Styles */
        .face-login-modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.85);
            z-index: 10000;
            justify-content: center;
            align-items: center;
            animation: fadeIn 0.3s ease;
        }
        
        .face-login-content {
            background: var(--card-bg);
            padding: 30px;
            border-radius: 25px;
            text-align: center;
            width: 90%;
            max-width: 500px;
            box-shadow: 0 30px 80px rgba(0,0,0,0.5);
            position: relative;
            border: 2px solid var(--primary-color);
        }
        
        .face-login-close {
            position: absolute;
            top: 15px;
            right: 15px;
            background: none;
            border: none;
            font-size: 24px;
            color: var(--text-light);
            cursor: pointer;
            transition: color 0.3s;
        }
        
        .face-login-close:hover {
            color: var(--primary-color);
        }
        
        .face-login-video {
            width: 100%;
            max-width: 400px;
            height: 300px;
            background: #000;
            border-radius: 15px;
            margin: 20px auto;
            overflow: hidden;
            position: relative;
        }
        
        .face-login-video video {
            width: 100%;
            height: 100%;
            object-fit: cover;
            transform: scaleX(-1); /* Mirror effect */
        }
        
        .face-login-status {
            margin: 15px 0;
            padding: 10px;
            border-radius: 10px;
            font-size: 14px;
            font-weight: 600;
            min-height: 40px;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .face-login-status.success {
            background: linear-gradient(135deg, rgba(40, 167, 69, 0.15), rgba(40, 167, 69, 0.08));
            border: 2px solid rgba(40, 167, 69, 0.5);
            color: #28a745;
        }
        
        .face-login-status.error {
            background: linear-gradient(135deg, rgba(220, 53, 69, 0.15), rgba(220, 53, 69, 0.08));
            border: 2px solid rgba(220, 53, 69, 0.5);
            color: #dc3545;
        }
        
        .face-login-status.info {
            background: linear-gradient(135deg, rgba(23, 162, 184, 0.15), rgba(23, 162, 184, 0.08));
            border: 2px solid rgba(23, 162, 184, 0.5);
            color: #17a2b8;
        }
        
        .face-login-controls {
            display: flex;
            gap: 10px;
            justify-content: center;
            margin: 20px 0;
        }
        
        .face-login-btn {
            padding: 12px 25px;
            background: linear-gradient(135deg, var(--primary-color) 0%, var(--primary-dark) 50%, var(--secondary-dark) 100%);
            color: white;
            border: none;
            border-radius: 10px;
            font-size: 16px;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.3s;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .face-login-btn:hover {
            transform: translateY(-3px);
            box-shadow: 0 10px 20px rgba(255, 107, 107, 0.3);
        }
        
        .face-login-btn.secondary {
            background: linear-gradient(135deg, #6c757d 0%, #495057 100%);
        }
        
        .face-login-btn:disabled {
            opacity: 0.6;
            cursor: not-allowed;
            transform: none !important;
        }
        
        .face-login-loading {
            display: none;
            margin: 15px 0;
            color: var(--primary-color);
            font-size: 14px;
        }
        
        .face-login-loading i {
            animation: spin 1s linear infinite;
            margin-right: 8px;
        }
        
        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }
        
        @keyframes spin {
            to { transform: rotate(360deg); }
        }
        
        .face-login-face {
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            width: 250px;
            height: 300px;
            border: 3px solid var(--primary-color);
            border-radius: 15px;
            pointer-events: none;
            opacity: 0.3;
            animation: pulseFace 2s infinite;
        }
        
        @keyframes pulseFace {
            0%, 100% { opacity: 0.3; }
            50% { opacity: 0.5; }
        }
        
        /* Face Login Button in Main Form */
        #faceLoginBtn {
            width: 100%;
            padding: 16px;
            background: linear-gradient(135deg, #4f46e5 0%, #7c3aed 50%, #9333ea 100%);
            color: white;
            border: none;
            border-radius: 15px;
            font-size: 17px;
            font-weight: 800;
            cursor: pointer;
            margin-top: 15px;
            transition: all 0.5s cubic-bezier(0.4, 0, 0.2, 1);
            box-shadow: 0 8px 25px rgba(79, 70, 229, 0.5);
            position: relative;
            overflow: hidden;
            letter-spacing: 1px;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
        }
        
        #faceLoginBtn:hover {
            transform: translateY(-4px);
            box-shadow: 0 15px 40px rgba(79, 70, 229, 0.6), 0 0 30px rgba(79, 70, 229, 0.3);
        }
        
        #faceLoginBtn:active {
            transform: translateY(-2px);
        }
        
        #faceLoginBtn i {
            font-size: 20px;
        }
    </style>
</head>
<body>
    
    <div class="bg-decoration bg-decoration-1"></div>
    <div class="bg-decoration bg-decoration-2"></div>
    <div class="bg-decoration bg-decoration-3"></div>
    <div class="bg-decoration bg-decoration-4"></div>
    
    <img src="../img/frsm-logo.png" alt="Fire & Rescue Services Watermark" class="watermark-logo">
    
    <button class="dark-mode-toggle" id="darkModeToggle" title="Toggle Dark Mode">
        <i class="fas fa-moon"></i>
    </button>
    
    <a href="../index.php" class="back-button">
        <i class="fas fa-arrow-left"></i>
        Back to Home Page
    </a>
    
    <div class="logo-left">
        <img src="../img/frsm-logo.png" alt="Fire & Rescue Services Logo">
        <h1>FIRE & RESCUE</h1>
        <p class="tagline">Emergency Services Management</p>
    </div>
    
    <div class="login-container">
        <div class="login-logo">
            <img src="../img/frsm-logo.png" alt="Fire & Rescue Services">
        </div>
        
        <div class="login-header">
            <h2>Login to Your Account</h2>
            <p>Access your fire and rescue management dashboard</p>
        </div>
        
        <?php if (!empty($errors['general'])): ?>
            <div class="error-message">
                <p><i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($errors['general']); ?></p>
            </div>
        <?php endif; ?>
        
        <?php if (isset($success_message)): ?>
            <div class="success-message">
                <p><i class="fas fa-check-circle"></i> <?php echo $success_message; ?></p>
            </div>
        <?php endif; ?>
        
        <?php if (isset($_GET['verified']) && $_GET['verified'] == 'success'): ?>
            <div class="success-message">
                <p><i class="fas fa-check-circle"></i> Your email has been verified successfully. You can now log in.</p>
            </div>
        <?php endif; ?>
        
        <form method="POST" action="" id="loginForm">
            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
            
            <div class="form-group">
                <label for="login_identifier">Email or Username</label>
                <div class="input-wrapper">
                    <i class="fas fa-user"></i>
                    <input type="text" id="login_identifier" name="login_identifier" 
                           value="<?php echo isset($_POST['login_identifier']) ? htmlspecialchars($_POST['login_identifier']) : ''; ?>" 
                           placeholder="Enter your email or username" 
                           class="<?php echo !empty($errors['login_identifier']) ? 'error' : (isset($_POST['login_identifier']) && empty($errors['login_identifier']) ? 'success' : ''); ?>"
                           required>
                </div>
                <?php if (!empty($errors['login_identifier'])): ?>
                    <span class="error" style="color: #dc3545; font-size: 13px; margin-top: 5px; display: block;"><?php echo $errors['login_identifier']; ?></span>
                <?php endif; ?>
            </div>
            
            <div class="form-group">
                <label for="password">Password</label>
                <div class="input-wrapper">
                    <i class="fas fa-lock"></i>
                    <input type="password" id="password" name="password" 
                           placeholder="Enter your password" 
                           class="<?php echo !empty($errors['password']) ? 'error' : (isset($_POST['password']) && empty($errors['password']) ? 'success' : ''); ?>"
                           required>
                    <button type="button" class="password-toggle" id="togglePassword">
                        <i class="fas fa-eye"></i>
                    </button>
                </div>
                <?php if (!empty($errors['password'])): ?>
                    <span class="error" style="color: #dc3545; font-size: 13px; margin-top: 5px; display: block;"><?php echo $errors['password']; ?></span>
                <?php endif; ?>
            </div>
            
            <div class="form-options">
                <div class="remember">
                    <input type="checkbox" id="remember" name="remember" <?php echo (isset($_POST['remember']) && $_POST['remember']) ? 'checked' : ''; ?>>
                    <label for="remember">Remember Me</label>
                </div>
                <a href="forgot_password.php" class="forgot-password">Forgot Password?</a>
            </div>
            
            <button type="submit" class="btn-primary" id="submitBtn">
                <i class="fas fa-sign-in-alt"></i> Login
            </button>
            
            <!-- Face Login Button -->
            <button type="button" class="btn-primary" id="faceLoginBtn" style="background: linear-gradient(135deg, #4f46e5 0%, #7c3aed 50%, #9333ea 100%);">
                <i class="fas fa-camera"></i> Login with Face Recognition
            </button>
        </form>
        
        <?php if ($show_resend_option && !$auto_sent_verification): ?>
        <div class="resend-verification">
            <p>Didn't receive the verification email?</p>
            <form method="POST" action="" style="display: inline;">
                <input type="hidden" name="resend_email" value="<?php echo htmlspecialchars($unverified_email); ?>">
                <input type="hidden" name="resend_verification" value="1">
                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                <button type="submit" class="resend-btn">
                    <i class="fas fa-paper-plane"></i> Resend Verification Email
                </button>
            </form>
        </div>
        <?php endif; ?>
        
        <div class="register-link">
            Don't have an account? <a href="register.php">Register here</a>
        </div>
        
        <div class="footer">
            © 2025 Fire & Rescue Services Management
        </div>
    </div>
    
    <!-- Face Login Modal -->
    <div class="face-login-modal" id="faceLoginModal">
        <div class="face-login-content">
            <button class="face-login-close" id="faceLoginClose">
                <i class="fas fa-times"></i>
            </button>
            
            <h2 style="color: var(--primary-color); margin-bottom: 10px;">
                <i class="fas fa-camera"></i> Face Recognition Login
            </h2>
            <p style="color: var(--text-light); margin-bottom: 20px;">
                Position your face in the frame and click "Capture & Verify"
            </p>
            
            <div class="face-login-video">
                <video id="faceVideo" autoplay playsinline></video>
                <canvas id="faceCanvas" style="display: none;"></canvas>
                <div class="face-login-face"></div>
            </div>
            
            <div class="face-login-status info" id="faceLoginStatus">
                <i class="fas fa-info-circle"></i> Ready to start camera
            </div>
            
            <div class="face-login-loading" id="faceLoginLoading">
                <i class="fas fa-spinner"></i> Processing face recognition...
            </div>
            
            <div class="face-login-controls">
                <button class="face-login-btn" id="startCameraBtn">
                    <i class="fas fa-video"></i> Start Camera
                </button>
                <button class="face-login-btn" id="captureBtn" disabled>
                    <i class="fas fa-camera"></i> Capture & Verify
                </button>
                <button class="face-login-btn secondary" id="cancelBtn">
                    <i class="fas fa-times"></i> Cancel
                </button>
            </div>
            
            <p style="font-size: 12px; color: var(--text-light); margin-top: 20px;">
                <i class="fas fa-shield-alt"></i> Your face data is securely processed and not stored as images
            </p>
        </div>
    </div>
    
    <!-- Hidden form for face login submission -->
    <form id="faceLoginForm" method="POST" style="display: none;">
        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
        <input type="hidden" name="face_login" value="1">
        <input type="hidden" name="face_user_id" id="faceUserId">
        <input type="hidden" name="face_user_email" id="faceUserEmail">
        <input type="hidden" name="face_password" id="facePassword">
    </form>
    
   <script>
    // Dark mode toggle functionality
    const darkModeToggle = document.getElementById('darkModeToggle');
    const body = document.body;
    const darkModeIcon = darkModeToggle.querySelector('i');
    
    if (localStorage.getItem('darkMode') === 'enabled') {
        body.classList.add('dark-mode');
        darkModeIcon.classList.remove('fa-moon');
        darkModeIcon.classList.add('fa-sun');
    }
    
    darkModeToggle.addEventListener('click', function() {
        body.classList.toggle('dark-mode');
        
        if (body.classList.contains('dark-mode')) {
            darkModeIcon.classList.remove('fa-moon');
            darkModeIcon.classList.add('fa-sun');
            localStorage.setItem('darkMode', 'enabled');
        } else {
            darkModeIcon.classList.remove('fa-sun');
            darkModeIcon.classList.add('fa-moon');
            localStorage.setItem('darkMode', 'disabled');
        }
    });
    
    // Password toggle
    const togglePassword = document.getElementById('togglePassword');
    const passwordInput = document.getElementById('password');
    
    togglePassword.addEventListener('click', function() {
        const type = passwordInput.getAttribute('type') === 'password' ? 'text' : 'password';
        passwordInput.setAttribute('type', type);
        this.innerHTML = type === 'password' ? '<i class="fas fa-eye"></i>' : '<i class="fas fa-eye-slash"></i>';
    });
    
    // Face Login Functionality
    const faceLoginBtn = document.getElementById('faceLoginBtn');
    const faceLoginModal = document.getElementById('faceLoginModal');
    const faceLoginClose = document.getElementById('faceLoginClose');
    const cancelBtn = document.getElementById('cancelBtn');
    const startCameraBtn = document.getElementById('startCameraBtn');
    const captureBtn = document.getElementById('captureBtn');
    const faceVideo = document.getElementById('faceVideo');
    const faceCanvas = document.getElementById('faceCanvas');
    const faceLoginStatus = document.getElementById('faceLoginStatus');
    const faceLoginLoading = document.getElementById('faceLoginLoading');
    const faceLoginForm = document.getElementById('faceLoginForm');
    const faceUserId = document.getElementById('faceUserId');
    const faceUserEmail = document.getElementById('faceUserEmail');
    const facePassword = document.getElementById('facePassword');
    
    let videoStream = null;
    let isCameraActive = false;
    const API_BASE_URL = 'http://127.0.0.1:5001';
    
    // Open face login modal - NO EMAIL REQUIRED FIRST
    faceLoginBtn.addEventListener('click', () => {
        // Check if API is running
        checkAPIHealth().then(isHealthy => {
            if (!isHealthy) {
                showStatus('Face recognition service is offline', 'error');
                return;
            }
            
            // Open camera modal immediately
            faceLoginModal.style.display = 'flex';
            resetFaceLogin();
            showFaceStatus('Ready for face recognition. Click "Start Camera" to begin.', 'info');
        });
    });
    
    // Close modal
    faceLoginClose.addEventListener('click', closeFaceLogin);
    cancelBtn.addEventListener('click', closeFaceLogin);
    
    // Close modal when clicking outside
    faceLoginModal.addEventListener('click', (e) => {
        if (e.target === faceLoginModal) {
            closeFaceLogin();
        }
    });
    
    function closeFaceLogin() {
        faceLoginModal.style.display = 'none';
        stopCamera();
        resetFaceLogin();
    }
    
    function resetFaceLogin() {
        faceLoginStatus.className = 'face-login-status info';
        faceLoginStatus.innerHTML = '<i class="fas fa-info-circle"></i> Ready to start camera';
        faceLoginLoading.style.display = 'none';
        startCameraBtn.disabled = false;
        captureBtn.disabled = true;
        isCameraActive = false;
    }
    
    // Start camera
    startCameraBtn.addEventListener('click', async () => {
        try {
            videoStream = await navigator.mediaDevices.getUserMedia({ 
                video: { 
                    width: { ideal: 640 },
                    height: { ideal: 480 },
                    facingMode: 'user'
                } 
            });
            
            faceVideo.srcObject = videoStream;
            isCameraActive = true;
            
            startCameraBtn.disabled = true;
            captureBtn.disabled = false;
            
            showFaceStatus('Camera started. Position your face and click "Capture & Login"', 'success');
            
        } catch (error) {
            console.error('Camera error:', error);
            let errorMsg = 'Camera error: ';
            
            if (error.name === 'NotAllowedError') {
                errorMsg = 'Camera access denied. Please allow camera permissions.';
            } else if (error.name === 'NotFoundError') {
                errorMsg = 'No camera found. Please connect a camera.';
            } else if (error.name === 'NotReadableError') {
                errorMsg = 'Camera is in use by another application.';
            } else {
                errorMsg += error.message;
            }
            
            showFaceStatus(errorMsg, 'error');
        }
    });
    
    // Stop camera
    function stopCamera() {
        if (videoStream) {
            videoStream.getTracks().forEach(track => track.stop());
            videoStream = null;
        }
        isCameraActive = false;
    }
    
    // Capture and verify face - FIND USER BY FACE ONLY
    captureBtn.addEventListener('click', async () => {
        if (!isCameraActive) {
            showFaceStatus('Camera not active', 'error');
            return;
        }
        
        // Draw video frame to canvas
        const context = faceCanvas.getContext('2d');
        faceCanvas.width = faceVideo.videoWidth;
        faceCanvas.height = faceVideo.videoHeight;
        context.drawImage(faceVideo, 0, 0, faceCanvas.width, faceCanvas.height);
        
        // Convert canvas to base64
        const imageBase64 = faceCanvas.toDataURL('image/jpeg', 0.8);
        
        // Show loading
        faceLoginLoading.style.display = 'block';
        captureBtn.disabled = true;
        
        try {
            // NEW: Search for user by face (no email/username needed)
            showFaceStatus('Searching for matching face...', 'info');
            
            // First, get ALL users with registered faces
            const allUsersResponse = await fetch('get_all_face_users.php');
            const allUsersData = await allUsersResponse.json();
            
            if (!allUsersData.success || allUsersData.users.length === 0) {
                showFaceStatus('No registered faces found in system.', 'error');
                faceLoginLoading.style.display = 'none';
                captureBtn.disabled = false;
                return;
            }
            
            console.log(`Found ${allUsersData.users.length} users with registered faces`);
            
            // Try to find matching face among all users
            let matchingUser = null;
            let bestSimilarity = 0;
            
            // Test face against each user
            for (const user of allUsersData.users) {
                const testResponse = await fetch(`${API_BASE_URL}/api/face/test/${user.id}`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        image: imageBase64.split(',')[1]
                    })
                });
                
                const testData = await testResponse.json();
                
                if (testData.success && testData.match) {
                    if (testData.similarity > bestSimilarity) {
                        bestSimilarity = testData.similarity;
                        matchingUser = user;
                        console.log(`Found potential match: User ${user.id}, similarity: ${testData.similarity}`);
                    }
                }
            }
            
            faceLoginLoading.style.display = 'none';
            
            if (matchingUser && bestSimilarity >= 0.75) {
                // Face recognized! Ask for password for security
                showFaceStatus(`Face recognized! Welcome ${matchingUser.first_name}.`, 'success');
                
                // Ask for password (optional security step)
                setTimeout(async () => {
                    const userPassword = prompt(
                        `Face recognized as ${matchingUser.first_name} ${matchingUser.last_name}.\n` +
                        `For security, please enter your password:\n` +
                        `(Leave empty to cancel)`
                    );
                    
                    if (userPassword === null || userPassword === '') {
                        showFaceStatus('Login cancelled', 'info');
                        captureBtn.disabled = false;
                        return;
                    }
                    
                    // Verify password
                    showFaceStatus('Verifying password...', 'info');
                    const passwordValid = await verifyPassword(matchingUser.email, userPassword);
                    
                    if (passwordValid) {
                        // Login successful
                        faceUserId.value = matchingUser.id;
                        faceUserEmail.value = matchingUser.email;
                        facePassword.value = userPassword;
                        
                        showFaceStatus('Login successful! Redirecting...', 'success');
                        
                        // Submit the form
                        setTimeout(() => {
                            faceLoginForm.submit();
                        }, 1000);
                    } else {
                        showFaceStatus('Invalid password. Please try again.', 'error');
                        captureBtn.disabled = false;
                    }
                }, 1000);
                
            } else {
                // No matching face found
                showFaceStatus('Face not recognized. Please try again or use traditional login.', 'error');
                captureBtn.disabled = false;
                
                // Option to switch to traditional login
                setTimeout(() => {
                    if (confirm('Face not recognized. Would you like to try traditional login?')) {
                        closeFaceLogin();
                        document.getElementById('login_identifier').focus();
                    }
                }, 1500);
            }
            
        } catch (error) {
            console.error('Face verification error:', error);
            faceLoginLoading.style.display = 'none';
            showFaceStatus(`Connection error: ${error.message}`, 'error');
            captureBtn.disabled = false;
        }
    });
    
    // Helper function to verify password
    async function verifyPassword(email, password) {
        try {
            const response = await fetch('verify_password.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ email: email, password: password })
            });
            
            const data = await response.json();
            return data.success;
        } catch (error) {
            return false;
        }
    }
    
    // Check if API is running
    async function checkAPIHealth() {
        try {
            const response = await fetch(`${API_BASE_URL}/api/health`);
            const data = await response.json();
            return data.status === 'healthy';
        } catch (error) {
            console.warn('Face recognition API not running:', error.message);
            return false;
        }
    }
    
    // Show face login status
    function showFaceStatus(message, type = 'info') {
        faceLoginStatus.textContent = message;
        faceLoginStatus.className = 'face-login-status ' + type;
        
        let icon = 'fa-info-circle';
        if (type === 'error') icon = 'fa-exclamation-circle';
        if (type === 'success') icon = 'fa-check-circle';
        
        faceLoginStatus.innerHTML = `<i class="fas ${icon}"></i> ${message}`;
    }
    
    // Show status on main page
    function showStatus(message, type = 'info') {
        let statusDiv = document.getElementById('tempStatus');
        if (!statusDiv) {
            statusDiv = document.createElement('div');
            statusDiv.id = 'tempStatus';
            statusDiv.style.cssText = `
                margin: 15px 0;
                padding: 12px;
                border-radius: 10px;
                font-size: 14px;
                font-weight: 600;
            `;
            const loginHeader = document.querySelector('.login-header');
            loginHeader.parentNode.insertBefore(statusDiv, loginHeader.nextSibling);
        }
        
        statusDiv.textContent = message;
        
        if (type === 'error') {
            statusDiv.style.background = 'linear-gradient(135deg, rgba(220, 53, 69, 0.15), rgba(220, 53, 69, 0.08))';
            statusDiv.style.border = '2px solid rgba(220, 53, 69, 0.5)';
            statusDiv.style.color = '#dc3545';
        } else if (type === 'success') {
            statusDiv.style.background = 'linear-gradient(135deg, rgba(40, 167, 69, 0.15), rgba(40, 167, 69, 0.08))';
            statusDiv.style.border = '2px solid rgba(40, 167, 69, 0.5)';
            statusDiv.style.color = '#28a745';
        } else {
            statusDiv.style.background = 'linear-gradient(135deg, rgba(23, 162, 184, 0.15), rgba(23, 162, 184, 0.08))';
            statusDiv.style.border = '2px solid rgba(23, 162, 184, 0.5)';
            statusDiv.style.color = '#17a2b8';
        }
        
        setTimeout(() => {
            if (statusDiv.parentNode) {
                statusDiv.parentNode.removeChild(statusDiv);
            }
        }, 5000);
    }
    
    // Check API health on page load
    window.addEventListener('load', () => {
        checkAPIHealth().then(isHealthy => {
            if (!isHealthy) {
                faceLoginBtn.disabled = true;
                faceLoginBtn.innerHTML = '<i class="fas fa-exclamation-triangle"></i> Face Login (Service Offline)';
                faceLoginBtn.style.opacity = '0.7';
                faceLoginBtn.title = 'Face recognition service is not running';
            }
        });
    });
    
    // Form validation (existing)
    const form = document.getElementById('loginForm');
    const submitBtn = document.getElementById('submitBtn');
    
    // Real-time validation for all fields
    const inputs = form.querySelectorAll('input');
    inputs.forEach(input => {
        input.addEventListener('blur', function() {
            validateField(this);
        });
        
        input.addEventListener('input', function() {
            if (this.classList.contains('error')) {
                this.classList.remove('error');
                const errorElement = this.parentNode.parentNode.querySelector('.error');
                if (errorElement) {
                    errorElement.textContent = '';
                }
            }
        });
    });
    
    function validateField(field) {
        const value = field.value.trim();
        let isValid = true;
        let errorMessage = '';
        
        if (field.hasAttribute('required') && value === '') {
            isValid = false;
            errorMessage = 'This field is required';
        }
        
        if (!isValid) {
            field.classList.add('error');
        } else if (value !== '') {
            field.classList.remove('error');
        }
        
        let errorElement = field.parentNode.parentNode.querySelector('.error');
        if (!isValid) {
            if (!errorElement) {
                errorElement = document.createElement('span');
                errorElement.className = 'error';
                field.parentNode.parentNode.appendChild(errorElement);
            }
            errorElement.textContent = errorMessage;
        } else if (errorElement) {
            errorElement.textContent = '';
        }
        
        return isValid;
    }
    
    form.addEventListener('submit', function(e) {
        let isValid = true;
        
        inputs.forEach(input => {
            if (!validateField(input)) {
                isValid = false;
            }
        });
        
        if (!isValid) {
            e.preventDefault();
            const firstError = form.querySelector('.error');
            if (firstError) {
                firstError.scrollIntoView({ behavior: 'smooth', block: 'center' });
            }
        } else {
            submitBtn.classList.add('loading');
            submitBtn.disabled = true;
            submitBtn.innerHTML = '<i class="fas fa-spinner"></i> Logging in...';
        }
    });
    
    // Enhanced input animations
    inputs.forEach(input => {
        input.addEventListener('focus', function() {
            this.parentElement.style.transform = 'scale(1.02)';
        });
        
        input.addEventListener('blur', function() {
            this.parentElement.style.transform = 'scale(1)';
        });
    });
    
    // Handle page visibility change
    document.addEventListener('visibilitychange', () => {
        if (document.hidden && videoStream) {
            stopCamera();
            if (isCameraActive) {
                showFaceStatus('Camera paused - tab not active', 'info');
                isCameraActive = false;
            }
        }
    });
    
    // Keyboard shortcuts
    document.addEventListener('keydown', (e) => {
        // ESC closes face login modal
        if (e.key === 'Escape' && faceLoginModal.style.display === 'flex') {
            closeFaceLogin();
        }
        
        // Ctrl+F for face login
        if (e.ctrlKey && e.key === 'f') {
            e.preventDefault();
            faceLoginBtn.click();
        }
    });
</script>
</body>
</html>