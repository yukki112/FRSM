<?php
session_start();
require_once '../config/db_connection.php';
require_once '../includes/functions.php';

// Redirect to dashboard if already logged in
if (isset($_SESSION['user_id'])) {
    header("Location: dashboard.php");
    exit();
}

// Security headers
header("X-Frame-Options: DENY");
header("X-Content-Type-Options: nosniff");
header("Referrer-Policy: strict-origin-when-cross-origin");
header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline'; style-src 'self' 'unsafe-inline';");

$errors = [];
$success = "";

// CSRF token generation
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    // CSRF token validation
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $errors['general'] = "Security validation failed. Please try again.";
        // Regenerate token after failed validation
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    } else {
        // Sanitize input data
        $first_name = sanitize_input($_POST['first_name']);
        $middle_name = sanitize_input($_POST['middle_name']);
        $last_name = sanitize_input($_POST['last_name']);
        $username = sanitize_input($_POST['username']);
        $contact = sanitize_input($_POST['contact']);
        $address = sanitize_input($_POST['address']);
        $date_of_birth = $_POST['date_of_birth'];
        $email = sanitize_input($_POST['email']);
        $password = $_POST['password'];
        $confirm_password = $_POST['confirm_password'];
        $terms = isset($_POST['terms']) ? true : false;
        
        // Validation
        if (empty($first_name)) {
            $errors['first_name'] = "First name is required";
        } elseif (strlen($first_name) > 50) {
            $errors['first_name'] = "First name must be less than 50 characters";
        } elseif (!preg_match('/^[a-zA-Z\s\-\.\']+$/', $first_name)) {
            $errors['first_name'] = "First name can only contain letters, spaces, hyphens, dots, and apostrophes";
        }
        
        if (!empty($middle_name) && strlen($middle_name) > 50) {
            $errors['middle_name'] = "Middle name must be less than 50 characters";
        } elseif (!empty($middle_name) && !preg_match('/^[a-zA-Z\s\-\.\']+$/', $middle_name)) {
            $errors['middle_name'] = "Middle name can only contain letters, spaces, hyphens, dots, and apostrophes";
        }
        
        if (empty($last_name)) {
            $errors['last_name'] = "Last name is required";
        } elseif (strlen($last_name) > 50) {
            $errors['last_name'] = "Last name must be less than 50 characters";
        } elseif (!preg_match('/^[a-zA-Z\s\-\.\']+$/', $last_name)) {
            $errors['last_name'] = "Last name can only contain letters, spaces, hyphens, dots, and apostrophes";
        }
        
        // Username validation
        if (empty($username)) {
            $errors['username'] = "Username is required";
        } elseif (strlen($username) < 3) {
            $errors['username'] = "Username must be at least 3 characters long";
        } elseif (strlen($username) > 50) {
            $errors['username'] = "Username must be less than 50 characters";
        } elseif (!preg_match('/^[a-zA-Z0-9_]+$/', $username)) {
            $errors['username'] = "Username can only contain letters, numbers, and underscores";
        } else {
            // Check if username already exists
            $stmt = $pdo->prepare("SELECT id FROM users WHERE username = ?");
            $stmt->execute([$username]);
            if ($stmt->rowCount() > 0) {
                $errors['username'] = "This username is already taken";
            }
        }
        
        if (empty($contact)) {
            $errors['contact'] = "Contact number is required";
        } elseif (!preg_match('/^[\+]?[0-9\s\-\(\)]{10,15}$/', $contact)) {
            $errors['contact'] = "Please enter a valid contact number (10-15 digits)";
        }
        
        if (empty($address)) {
            $errors['address'] = "Address is required";
        } elseif (strlen($address) > 255) {
            $errors['address'] = "Address must be less than 255 characters";
        }
        
        if (empty($date_of_birth)) {
            $errors['date_of_birth'] = "Date of birth is required";
        } else {
            // Check if user is 18 or older
            $today = new DateTime();
            $birthdate = new DateTime($date_of_birth);
            $age = $today->diff($birthdate)->y;
            
            if ($age < 18) {
                $errors['date_of_birth'] = "You must be 18 years or older to register";
            } elseif ($age > 120) {
                $errors['date_of_birth'] = "Please enter a valid date of birth";
            }
        }
        
        if (empty($email)) {
            $errors['email'] = "Email is required";
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = "Please enter a valid email address";
        } elseif (strlen($email) > 100) {
            $errors['email'] = "Email must be less than 100 characters";
        } else {
            // Check if email already exists
            $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
            $stmt->execute([$email]);
            if ($stmt->rowCount() > 0) {
                $errors['email'] = "This email is already registered";
            }
        }
        
        if (empty($password)) {
            $errors['password'] = "Password is required";
        } elseif (strlen($password) < 8) {
            $errors['password'] = "Password must be at least 8 characters long";
        } elseif (strlen($password) > 72) { // bcrypt limit
            $errors['password'] = "Password must be less than 72 characters";
        } elseif (!preg_match('/[A-Z]/', $password) || !preg_match('/[a-z]/', $password) || !preg_match('/[0-9]/', $password) || !preg_match('/[!@#$%^&*()\-_=+{};:,<.>]/', $password)) {
            $errors['password'] = "Password must contain at least one uppercase letter, one lowercase letter, one number, and one special character (!@#$%^&*)";
        }
        
        // Check for common passwords
        $common_passwords = ['12345678', 'password', 'qwerty123', 'admin123', 'welcome123'];
        if (in_array(strtolower($password), $common_passwords)) {
            $errors['password'] = "This password is too common. Please choose a more secure one.";
        }
        
        if (empty($confirm_password)) {
            $errors['confirm_password'] = "Please confirm your password";
        } elseif ($password !== $confirm_password) {
            $errors['confirm_password'] = "Passwords do not match";
        }
        
        if (!$terms) {
            $errors['terms'] = "You must agree to the Terms and Conditions";
        }
        
        // Rate limiting - check if too many registration attempts from this IP
        $ip = $_SERVER['REMOTE_ADDR'];
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM registration_attempts WHERE ip_address = ? AND attempt_time > DATE_SUB(NOW(), INTERVAL 1 HOUR)");
        $stmt->execute([$ip]);
        $attempts = $stmt->fetchColumn();
        
        if ($attempts > 5) {
            $errors['general'] = "Too many registration attempts. Please try again later.";
        }
        
        // If no errors, proceed with registration
        if (empty($errors)) {
            // Generate verification code
            $verification_code = generate_verification_code();
            $code_expiry = date('Y-m-d H:i:s', strtotime('+15 minutes'));
            
            // Hash password with cost factor of 12
            $hashed_password = password_hash($password, PASSWORD_DEFAULT, ['cost' => 12]);
            
            try {
                $pdo->beginTransaction();
                
                // Insert user data
                $stmt = $pdo->prepare("INSERT INTO users (first_name, middle_name, last_name, username, contact, address, date_of_birth, email, password, verification_code, code_expiry) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([$first_name, $middle_name, $last_name, $username, $contact, $address, $date_of_birth, $email, $hashed_password, $verification_code, $code_expiry]);
                
                // Store verification code separately
                $stmt = $pdo->prepare("INSERT INTO verification_codes (email, code, expiry) VALUES (?, ?, ?)");
                $stmt->execute([$email, $verification_code, $code_expiry]);
                
                // Record registration attempt
                $stmt = $pdo->prepare("INSERT INTO registration_attempts (ip_address, email, attempt_time, successful) VALUES (?, ?, NOW(), 1)");
                $stmt->execute([$ip, $email]);
                
                // Send verification email
                if (send_verification_email($email, $first_name, $verification_code)) {
                    $pdo->commit();
                    $_SESSION['verification_email'] = $email;
                    $_SESSION['registration_success'] = true;
                    header("Location: verify_email.php");
                    exit();
                } else {
                    $pdo->rollBack();
                    $errors['general'] = "Failed to send verification email. Please try again.";
                }
            } catch (PDOException $e) {
                $pdo->rollBack();
                // Record failed attempt
                $stmt = $pdo->prepare("INSERT INTO registration_attempts (ip_address, email, attempt_time, successful) VALUES (?, ?, NOW(), 0)");
                $stmt->execute([$ip, $email]);
                
                $errors['general'] = "Registration failed. Please try again.";
                error_log("Registration error: " . $e->getMessage());
            }
        } else {
            // Record failed attempt
            $stmt = $pdo->prepare("INSERT INTO registration_attempts (ip_address, email, attempt_time, successful) VALUES (?, ?, NOW(), 0)");
            $stmt->execute([$ip, $email ?? '']);
        }
        
        // Regenerate CSRF token after successful validation
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Register - Fire & Rescue Services Management</title>
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
        
        /* Enhanced register container with glass morphism and organic shape */
        .register-container {
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
        
        .register-container::-webkit-scrollbar {
            width: 6px;
        }
        
        .register-container::-webkit-scrollbar-track {
            background: transparent;
        }
        
        .register-container::-webkit-scrollbar-thumb {
            background: var(--primary-color);
            border-radius: 10px;
        }
        
        .register-container::before {
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
        
        .register-container > * {
            position: relative;
            z-index: 1;
        }
        
        /* Enhanced logo with bounce animation */
        .register-logo {
            text-align: center;
            margin-bottom: 25px;
        }
        
        .register-logo img {
            width: 100px;
            height: auto;
            filter: drop-shadow(0 8px 20px rgba(0,0,0,0.3)) drop-shadow(0 0 20px rgba(255, 107, 107, 0.2));
            animation: bounce 3s ease-in-out infinite;
        }
        
        @keyframes bounce {
            0%, 100% { transform: translateY(0); }
            50% { transform: translateY(-15px); }
        }
        
        .register-header {
            text-align: center;
            margin-bottom: 30px;
        }
        
        .register-header h2 {
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
        
        .register-header p {
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
        
        .form-group input, .form-group select, .form-group textarea {
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
        
        .form-group textarea {
            min-height: 100px;
            resize: vertical;
        }
        
        .form-group input:focus, .form-group select:focus, .form-group textarea:focus {
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
        
        .form-row {
            display: flex;
            gap: 15px;
            margin-bottom: 22px;
        }
        
        .form-row .form-group {
            flex: 1;
            margin-bottom: 0;
        }
        
        .center-field {
            display: flex;
            justify-content: center;
            margin-bottom: 22px;
        }
        
        .center-field .form-group {
            width: 80%;
            text-align: center;
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
        
        .terms {
            display: flex;
            align-items: flex-start;
            gap: 12px;
            margin: 25px 0;
            padding: 15px;
            background-color: rgba(255, 107, 107, 0.08);
            border-radius: 10px;
            border-left: 4px solid var(--primary-color);
        }
        
        .terms input[type="checkbox"] {
            width: 18px;
            height: 18px;
            margin-top: 3px;
            flex-shrink: 0;
            cursor: pointer;
        }
        
        .terms label {
            font-size: 14px;
            color: var(--text-light);
            text-align: left;
            line-height: 1.5;
            margin: 0;
            cursor: pointer;
            flex: 1;
        }
        
        .terms a {
            color: var(--primary-color);
            text-decoration: none;
            font-weight: 600;
        }
        
        .terms a:hover {
            text-decoration: underline;
            color: var(--text-color);
        }
        
        .login-link {
            text-align: center;
            margin-top: 25px;
            color: var(--text-light);
            font-size: 14px;
            transition: color 0.3s ease;
            font-weight: 500;
        }
        
        .login-link a {
            color: var(--primary-color);
            text-decoration: none;
            font-weight: 800;
            transition: all 0.3s ease;
            position: relative;
        }
        
        .login-link a::after {
            content: '';
            position: absolute;
            bottom: -2px;
            left: 0;
            width: 0;
            height: 2px;
            background: var(--primary-color);
            transition: width 0.3s ease;
        }
        
        .login-link a:hover::after {
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
        
        /* Password strength indicator */
        .password-strength {
            height: 4px;
            background-color: var(--border-color);
            border-radius: 2px;
            margin-top: 8px;
            overflow: hidden;
        }
        
        .strength-bar {
            height: 100%;
            width: 0%;
            background-color: #dc3545;
            transition: all 0.3s;
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
        
        /* Field requirements */
        .field-requirements {
            font-size: 13px;
            color: var(--text-light);
            margin-top: 8px;
            line-height: 1.5;
        }
        
        @media (max-width: 1400px) {
            .logo-left {
                left: 60px;
            }
            
            .register-container {
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
            
            .register-container {
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
            
            .register-container {
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
            
            .form-row {
                flex-direction: column;
                gap: 22px;
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
    
     
    <div class="register-container">
        <div class="register-logo">
            <img src="../img/frsm-logo.png" alt="Fire & Rescue Services">
        </div>
        
        <div class="register-header">
            <h2>Create Account</h2>
            <p>Join Fire & Rescue Services Management System</p>
        </div>
        
        <?php if (!empty($errors['general'])): ?>
            <div class="error-message">
                <p><i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($errors['general']); ?></p>
            </div>
        <?php endif; ?>
        
        <?php if ($success): ?>
            <div class="success-message">
                <p><i class="fas fa-check-circle"></i> <?php echo $success; ?></p>
            </div>
        <?php endif; ?>
        
        <form method="POST" action="" id="registerForm">
            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
            
            <div class="form-row">
                <div class="form-group">
                    <label for="first_name">First Name</label>
                    <div class="input-wrapper">
                        <i class="fas fa-user"></i>
                        <input type="text" id="first_name" name="first_name" 
                               value="<?php echo isset($_POST['first_name']) ? htmlspecialchars($_POST['first_name']) : ''; ?>" 
                               placeholder="Enter your first name" 
                               class="<?php echo !empty($errors['first_name']) ? 'error' : (isset($_POST['first_name']) && empty($errors['first_name']) ? 'success' : ''); ?>"
                               required>
                    </div>
                    <?php if (!empty($errors['first_name'])): ?>
                        <span class="error" style="color: #dc3545; font-size: 13px; margin-top: 5px; display: block;"><?php echo $errors['first_name']; ?></span>
                    <?php endif; ?>
                </div>
                
                <div class="form-group">
                    <label for="middle_name">Middle Name</label>
                    <div class="input-wrapper">
                        <i class="fas fa-user"></i>
                        <input type="text" id="middle_name" name="middle_name" 
                               value="<?php echo isset($_POST['middle_name']) ? htmlspecialchars($_POST['middle_name']) : ''; ?>" 
                               placeholder="Enter your middle name"
                               class="<?php echo !empty($errors['middle_name']) ? 'error' : (isset($_POST['middle_name']) && empty($errors['middle_name']) ? 'success' : ''); ?>">
                    </div>
                    <?php if (!empty($errors['middle_name'])): ?>
                        <span class="error" style="color: #dc3545; font-size: 13px; margin-top: 5px; display: block;"><?php echo $errors['middle_name']; ?></span>
                    <?php endif; ?>
                </div>
            </div>
            
            <div class="form-row">
                <div class="form-group">
                    <label for="last_name">Last Name</label>
                    <div class="input-wrapper">
                        <i class="fas fa-user"></i>
                        <input type="text" id="last_name" name="last_name" 
                               value="<?php echo isset($_POST['last_name']) ? htmlspecialchars($_POST['last_name']) : ''; ?>" 
                               placeholder="Enter your last name" 
                               class="<?php echo !empty($errors['last_name']) ? 'error' : (isset($_POST['last_name']) && empty($errors['last_name']) ? 'success' : ''); ?>"
                               required>
                    </div>
                    <?php if (!empty($errors['last_name'])): ?>
                        <span class="error" style="color: #dc3545; font-size: 13px; margin-top: 5px; display: block;"><?php echo $errors['last_name']; ?></span>
                    <?php endif; ?>
                </div>
                
                <div class="form-group">
                    <label for="username">Username</label>
                    <div class="input-wrapper">
                        <i class="fas fa-at"></i>
                        <input type="text" id="username" name="username" 
                               value="<?php echo isset($_POST['username']) ? htmlspecialchars($_POST['username']) : ''; ?>" 
                               placeholder="Choose a username" 
                               class="<?php echo !empty($errors['username']) ? 'error' : (isset($_POST['username']) && empty($errors['username']) ? 'success' : ''); ?>"
                               required>
                    </div>
                    <?php if (!empty($errors['username'])): ?>
                        <span class="error" style="color: #dc3545; font-size: 13px; margin-top: 5px; display: block;"><?php echo $errors['username']; ?></span>
                    <?php endif; ?>
                    <div class="field-requirements">
                        Must be 3-50 characters and can only contain letters, numbers, and underscores
                    </div>
                </div>
            </div>
            
            <div class="form-row">
                <div class="form-group">
                    <label for="contact">Contact Number</label>
                    <div class="input-wrapper">
                        <i class="fas fa-phone"></i>
                        <input type="tel" id="contact" name="contact" 
                               value="<?php echo isset($_POST['contact']) ? htmlspecialchars($_POST['contact']) : ''; ?>" 
                               placeholder="Enter your phone number" 
                               class="<?php echo !empty($errors['contact']) ? 'error' : (isset($_POST['contact']) && empty($errors['contact']) ? 'success' : ''); ?>"
                               required>
                    </div>
                    <?php if (!empty($errors['contact'])): ?>
                        <span class="error" style="color: #dc3545; font-size: 13px; margin-top: 5px; display: block;"><?php echo $errors['contact']; ?></span>
                    <?php endif; ?>
                </div>
                
                <div class="form-group">
                    <label for="date_of_birth">Date of Birth</label>
                    <div class="input-wrapper">
                        <i class="fas fa-calendar"></i>
                        <input type="date" id="date_of_birth" name="date_of_birth" 
                               value="<?php echo isset($_POST['date_of_birth']) ? $_POST['date_of_birth'] : ''; ?>" 
                               class="<?php echo !empty($errors['date_of_birth']) ? 'error' : (isset($_POST['date_of_birth']) && empty($errors['date_of_birth']) ? 'success' : ''); ?>"
                               required>
                    </div>
                    <?php if (!empty($errors['date_of_birth'])): ?>
                        <span class="error" style="color: #dc3545; font-size: 13px; margin-top: 5px; display: block;"><?php echo $errors['date_of_birth']; ?></span>
                    <?php endif; ?>
                </div>
            </div>
            
            <div class="form-group">
                <label for="address">Address</label>
                <div class="input-wrapper">
                    <i class="fas fa-map-marker-alt"></i>
                    <textarea id="address" name="address" 
                              placeholder="Enter your complete address" 
                              class="<?php echo !empty($errors['address']) ? 'error' : (isset($_POST['address']) && empty($errors['address']) ? 'success' : ''); ?>"
                              required><?php echo isset($_POST['address']) ? htmlspecialchars($_POST['address']) : ''; ?></textarea>
                </div>
                <?php if (!empty($errors['address'])): ?>
                    <span class="error" style="color: #dc3545; font-size: 13px; margin-top: 5px; display: block;"><?php echo $errors['address']; ?></span>
                <?php endif; ?>
            </div>
            
            <div class="form-group">
                <label for="email">Email Address</label>
                <div class="input-wrapper">
                    <i class="fas fa-envelope"></i>
                    <input type="email" id="email" name="email" 
                           value="<?php echo isset($_POST['email']) ? htmlspecialchars($_POST['email']) : ''; ?>" 
                           placeholder="Enter your email address" 
                           class="<?php echo !empty($errors['email']) ? 'error' : (isset($_POST['email']) && empty($errors['email']) ? 'success' : ''); ?>"
                           required>
                </div>
                <?php if (!empty($errors['email'])): ?>
                    <span class="error" style="color: #dc3545; font-size: 13px; margin-top: 5px; display: block;"><?php echo $errors['email']; ?></span>
                <?php endif; ?>
            </div>
            
            <div class="form-row">
                <div class="form-group">
                    <label for="password">Password</label>
                    <div class="input-wrapper">
                        <i class="fas fa-lock"></i>
                        <input type="password" id="password" name="password" 
                               placeholder="Create a strong password" 
                               class="<?php echo !empty($errors['password']) ? 'error' : (isset($_POST['password']) && empty($errors['password']) ? 'success' : ''); ?>"
                               required>
                        <button type="button" class="password-toggle" id="togglePassword">
                            <i class="fas fa-eye"></i>
                        </button>
                    </div>
                    <div class="password-strength">
                        <div class="strength-bar" id="strengthBar"></div>
                    </div>
                    <?php if (!empty($errors['password'])): ?>
                        <span class="error" style="color: #dc3545; font-size: 13px; margin-top: 5px; display: block;"><?php echo $errors['password']; ?></span>
                    <?php endif; ?>
                    <div class="field-requirements">
                        Must be at least 8 characters and include uppercase, lowercase, number, and special character
                    </div>
                </div>
                
                <div class="form-group">
                    <label for="confirm_password">Confirm Password</label>
                    <div class="input-wrapper">
                        <i class="fas fa-lock"></i>
                        <input type="password" id="confirm_password" name="confirm_password" 
                               placeholder="Re-enter your password" 
                               class="<?php echo !empty($errors['confirm_password']) ? 'error' : (isset($_POST['confirm_password']) && empty($errors['confirm_password']) ? 'success' : ''); ?>"
                               required>
                        <button type="button" class="password-toggle" id="toggleConfirmPassword">
                            <i class="fas fa-eye"></i>
                        </button>
                    </div>
                    <?php if (!empty($errors['confirm_password'])): ?>
                        <span class="error" style="color: #dc3545; font-size: 13px; margin-top: 5px; display: block;"><?php echo $errors['confirm_password']; ?></span>
                    <?php endif; ?>
                </div>
            </div>
            
            <div class="terms">
                <input type="checkbox" id="terms" name="terms" required <?php echo (isset($_POST['terms']) && $_POST['terms']) ? 'checked' : ''; ?>>
                <label for="terms">
                    I agree to the <a href="terms.php" target="_blank">Terms and Conditions</a> and <a href="privacy.php" target="_blank">Privacy Policy</a>. 
                    I consent to receive communications about my account.
                </label>
            </div>
            <?php if (!empty($errors['terms'])): ?>
                <span class="error" style="color: #dc3545; font-size: 13px; margin-top: -10px; margin-bottom: 15px; display: block;"><?php echo $errors['terms']; ?></span>
            <?php endif; ?>
            
            <button type="submit" class="btn-primary">
                <i class="fas fa-user-plus"></i> Create Account
            </button>
        </form>
        
        <div class="login-link">
            Already have an account? <a href="login.php">Sign in</a>
        </div>
        
        <div class="footer">
            © 2025 Fire & Rescue Services Management
        </div>
    </div>
    
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
        const toggleConfirmPassword = document.getElementById('toggleConfirmPassword');
        const confirmPasswordInput = document.getElementById('confirm_password');
        
        togglePassword.addEventListener('click', function() {
            const type = passwordInput.getAttribute('type') === 'password' ? 'text' : 'password';
            passwordInput.setAttribute('type', type);
            this.innerHTML = type === 'password' ? '<i class="fas fa-eye"></i>' : '<i class="fas fa-eye-slash"></i>';
        });
        
        toggleConfirmPassword.addEventListener('click', function() {
            const type = confirmPasswordInput.getAttribute('type') === 'password' ? 'text' : 'password';
            confirmPasswordInput.setAttribute('type', type);
            this.innerHTML = type === 'password' ? '<i class="fas fa-eye"></i>' : '<i class="fas fa-eye-slash"></i>';
        });
        
        // Password strength indicator
        const strengthBar = document.getElementById('strengthBar');
        
        passwordInput.addEventListener('input', function() {
            const password = this.value;
            let strength = 0;
            
            // Length check
            if (password.length >= 8) strength += 1;
            if (password.length >= 12) strength += 1;
            
            // Complexity checks
            if (/[A-Z]/.test(password)) strength += 1;
            if (/[a-z]/.test(password)) strength += 1;
            if (/[0-9]/.test(password)) strength += 1;
            if (/[!@#$%^&*()\-_=+{};:,<.>]/.test(password)) strength += 1;
            
            // Update strength bar
            let width = 0;
            let color = '#dc3545'; // Red
            
            if (strength > 3) {
                width = 100;
                color = '#28a745'; // Green
            } else if (strength > 1) {
                width = 66;
                color = '#fd7e14'; // Orange
            } else if (password.length > 0) {
                width = 33;
            }
            
            strengthBar.style.width = width + '%';
            strengthBar.style.backgroundColor = color;
        });
        
        // Phone number formatting
        document.getElementById('contact').addEventListener('input', function(e) {
            // Remove all non-digit characters
            let value = this.value.replace(/\D/g, '');
            
            // Limit to 15 characters
            if (value.length > 15) {
                value = value.substring(0, 15);
            }
            
            this.value = value;
        });
        
        // Username validation
        document.getElementById('username').addEventListener('input', function() {
            this.value = this.value.replace(/[^a-zA-Z0-9_]/g, '');
        });
        
        // Form validation
        document.getElementById('registerForm').addEventListener('submit', function(e) {
            const password = document.getElementById('password').value;
            const confirmPassword = document.getElementById('confirm_password').value;
            const agreeTerms = document.getElementById('terms').checked;
            
            if (password !== confirmPassword) {
                e.preventDefault();
                alert('Passwords do not match');
                return false;
            }
            
            if (!agreeTerms) {
                e.preventDefault();
                alert('You must agree to the terms and conditions');
                return false;
            }
            
            return true;
        });
        
        // Enhanced input animations
        const inputs = document.querySelectorAll('.form-group input, .form-group select, .form-group textarea');
        inputs.forEach(input => {
            input.addEventListener('focus', function() {
                this.parentElement.style.transform = 'scale(1.02)';
            });
            
            input.addEventListener('blur', function() {
                this.parentElement.style.transform = 'scale(1)';
            });
        });
        
        // Age validation
        document.getElementById('date_of_birth').addEventListener('change', function() {
            const dob = new Date(this.value);
            const today = new Date();
            const age = today.getFullYear() - dob.getFullYear();
            const monthDiff = today.getMonth() - dob.getMonth();
            
            if (monthDiff < 0 || (monthDiff === 0 && today.getDate() < dob.getDate())) {
                age--;
            }
            
            if (age < 18) {
                this.setCustomValidity('You must be 18 years or older to register');
                this.classList.add('error');
                this.classList.remove('success');
            } else if (age > 120) {
                this.setCustomValidity('Please enter a valid date of birth');
                this.classList.add('error');
                this.classList.remove('success');
            } else {
                this.setCustomValidity('');
                this.classList.remove('error');
                this.classList.add('success');
            }
        });
    </script>
</body>
</html>