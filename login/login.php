<?php
session_start();
require_once '../config/db_connection.php';
require_once '../includes/functions.php';

// Security headers
header("X-Frame-Options: DENY");
header("X-Content-Type-Options: nosniff");
header("Referrer-Policy: strict-origin-when-cross-origin");
header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline'; style-src 'self' 'unsafe-inline';");

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

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    // CSRF token validation
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $errors['general'] = "Security validation failed. Please try again.";
        // Regenerate token after failed validation
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    } else {
        // Sanitize input data - FIXED: Check if keys exist before accessing
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
                        // Check if password needs rehashing (if algorithm/cost changed)
                        if (password_needs_rehash($user['password'], PASSWORD_DEFAULT, ['cost' => 12])) {
                            $newHash = password_hash($password, PASSWORD_DEFAULT, ['cost' => 12]);
                            $stmt = $pdo->prepare("UPDATE users SET password = ? WHERE id = ?");
                            $stmt->execute([$newHash, $user['id']]);
                        }
                        
                        // Regenerate session ID to prevent session fixation
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
                    // User exists but email is not verified - show error message and resend option
                    $errors['general'] = "Your account is not verified. Please check your inbox for the verification link we sent you.";
                    $show_resend_option = true;
                    $unverified_email = $user['email'];
                    
                    // Store email in session for resend functionality
                    $_SESSION['unverified_email'] = $user['email'];
                    
                    // AUTOMATICALLY SEND VERIFICATION EMAIL
                    try {
                        // Generate new verification code
                        $verification_code = generate_verification_code();
                        $expiry_time = date('Y-m-d H:i:s', strtotime('+30 minutes'));
                        
                        // Update user with new verification code
                        $stmt = $pdo->prepare("UPDATE users SET verification_code = ?, code_expiry = ? WHERE email = ?");
                        if ($stmt->execute([$verification_code, $expiry_time, $user['email']])) {
                            
                            // Also store in verification_codes table for redundancy
                            $stmt = $pdo->prepare("INSERT INTO verification_codes (email, code, expiry) VALUES (?, ?, ?)");
                            $stmt->execute([$user['email'], $verification_code, $expiry_time]);
                            
                            // Send verification email with link
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

// Handle resend verification request - FIXED: Check if keys exist
if (isset($_POST['resend_verification'])) {
    // FIX: Check if email key exists before accessing it
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
        
        @media (max-width: 1400px) {
            .logo-left {
                left: 60px;
            }
            
            .login-container {
                right: 60px;
            }
        }
        
        @media (max-width: 1200px) {
            .logo-left {
                left: 40px;
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
                right: 40px;
                width: 450px;
            }
        }
        
        @media (max-width: 768px) {
            .logo-left {
                top: 100px;
                left: 50%;
                transform: translateX(-50%);
            }
            
            .logo-left img {
                width: 160px;
            }
            
            .logo-left h1 {
                font-size: 32px;
            }
            
            .logo-left .tagline {
                font-size: 16px;
            }
            
            .security-features {
                display: none;
            }
            
            .login-container {
                right: 50%;
                transform: translate(50%, -50%);
                width: 90%;
                max-width: 420px;
                margin-top: 200px;
            }
            
            .watermark-logo {
                width: 600px;
                height: 600px;
            }
            
            .form-options {
                flex-direction: column;
                gap: 12px;
                align-items: flex-start;
            }
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
    
    <script>
        // Your existing JavaScript remains the same
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
        
        // Form validation
        const form = document.getElementById('loginForm');
        const submitBtn = document.getElementById('submitBtn');
        
        // Real-time validation for all fields
        const inputs = form.querySelectorAll('input');
        inputs.forEach(input => {
            input.addEventListener('blur', function() {
                validateField(this);
            });
            
            input.addEventListener('input', function() {
                // Clear error state when user starts typing
                if (this.classList.contains('error')) {
                    this.classList.remove('error');
                    const errorElement = this.parentNode.parentNode.querySelector('.error');
                    if (errorElement) {
                        errorElement.textContent = '';
                    }
                }
            });
        });
        
        // Field validation function
        function validateField(field) {
            const value = field.value.trim();
            let isValid = true;
            let errorMessage = '';
            
            // Required field validation
            if (field.hasAttribute('required') && value === '') {
                isValid = false;
                errorMessage = 'This field is required';
            }
            
            // Update field appearance
            if (!isValid) {
                field.classList.add('error');
            } else if (value !== '') {
                field.classList.remove('error');
            }
            
            // Update error message
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
        
        // Form submission
        form.addEventListener('submit', function(e) {
            let isValid = true;
            
            // Validate all fields
            inputs.forEach(input => {
                if (!validateField(input)) {
                    isValid = false;
                }
            });
            
            if (!isValid) {
                e.preventDefault();
                // Scroll to first error
                const firstError = form.querySelector('.error');
                if (firstError) {
                    firstError.scrollIntoView({ behavior: 'smooth', block: 'center' });
                }
            } else {
                // Show loading state
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
    </script>
</body>
</html>