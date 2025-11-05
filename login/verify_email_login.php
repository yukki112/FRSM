<?php
session_start();
require_once '../config/db_connection.php';
require_once '../includes/functions.php';

// Check if user came from login process
if (!isset($_SESSION['unverified_user_id']) && !isset($_GET['email']) && !isset($_GET['code'])) {
    header("Location: login.php");
    exit();
}

$email = isset($_SESSION['unverified_email']) ? $_SESSION['unverified_email'] : (isset($_GET['email']) ? $_GET['email'] : '');
$user_id = isset($_SESSION['unverified_user_id']) ? $_SESSION['unverified_user_id'] : '';
$name = isset($_SESSION['unverified_name']) ? $_SESSION['unverified_name'] : '';

$errors = [];
$success = "";

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $entered_code = sanitize_input($_POST['verification_code']);
    
    if (empty($entered_code)) {
        $errors['verification_code'] = "Verification code is required";
    } else {
        // Check the verification code
        $stmt = $pdo->prepare("SELECT * FROM verification_codes WHERE email = ? AND code = ? ORDER BY created_at DESC LIMIT 1");
        $stmt->execute([$email, $entered_code]);
        $code_record = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($code_record) {
            // Check if the code is not expired
            $current_time = date('Y-m-d H:i:s');
            $expiry_time = $code_record['expiry'];
            
            if (strtotime($current_time) <= strtotime($expiry_time)) {
                // Mark user as verified
                $stmt = $pdo->prepare("UPDATE users SET is_verified = 1, verification_code = NULL, code_expiry = NULL WHERE email = ?");
                if ($stmt->execute([$email])) {
                    // Delete used verification code
                    $stmt = $pdo->prepare("DELETE FROM verification_codes WHERE email = ? AND code = ?");
                    $stmt->execute([$email, $entered_code]);
                    
                    $success = "Email verified successfully! Redirecting you to login...";
                    
                    // Clear session variables
                    unset($_SESSION['unverified_user_id']);
                    unset($_SESSION['unverified_email']);
                    unset($_SESSION['unverified_name']);
                    
                    // Redirect to login page after 2 seconds
                    header("refresh:2;url=login.php?verified=success");
                } else {
                    $errors['verification_code'] = "Failed to verify email. Please try again.";
                }
            } else {
                $errors['verification_code'] = "Verification code has expired. Please request a new one.";
            }
        } else {
            // Check the users table as fallback
            $stmt = $pdo->prepare("SELECT * FROM users WHERE email = ? AND verification_code = ?");
            $stmt->execute([$email, $entered_code]);
            $user_code_record = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($user_code_record) {
                // Check if the code is not expired in users table
                $current_time = date('Y-m-d H:i:s');
                $expiry_time = $user_code_record['code_expiry'];
                
                if (strtotime($current_time) <= strtotime($expiry_time)) {
                    // Mark user as verified
                    $stmt = $pdo->prepare("UPDATE users SET is_verified = 1, verification_code = NULL, code_expiry = NULL WHERE email = ?");
                    if ($stmt->execute([$email])) {
                        $success = "Email verified successfully! Redirecting you to login...";
                        
                        // Clear session variables
                        unset($_SESSION['unverified_user_id']);
                        unset($_SESSION['unverified_email']);
                        unset($_SESSION['unverified_name']);
                        
                        // Redirect to login page after 2 seconds
                        header("refresh:2;url=login.php?verified=success");
                    } else {
                        $errors['verification_code'] = "Failed to verify email. Please try again.";
                    }
                } else {
                    $errors['verification_code'] = "Verification code has expired. Please request a new one.";
                }
            } else {
                $errors['verification_code'] = "Invalid verification code. Please check and try again.";
            }
        }
    }
}

// Handle resend verification request
if (isset($_POST['resend_verification'])) {
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
        send_verification_email_with_link($email, explode(' ', $name)[0], $verification_code);
        
        $success_message = "A new verification code has been sent to your email.";
    } else {
        $errors['general'] = "Failed to send verification code. Please try again.";
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Verify Email - Fire & Rescue Services Management</title>
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
        
        /* Enhanced verification container with glass morphism and organic shape */
        .verify-container {
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
        
        .verify-container::-webkit-scrollbar {
            width: 6px;
        }
        
        .verify-container::-webkit-scrollbar-track {
            background: transparent;
        }
        
        .verify-container::-webkit-scrollbar-thumb {
            background: var(--primary-color);
            border-radius: 10px;
        }
        
        .verify-container::before {
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
        
        .verify-container > * {
            position: relative;
            z-index: 1;
        }
        
        /* Enhanced logo with bounce animation */
        .verify-logo {
            text-align: center;
            margin-bottom: 25px;
        }
        
        .verify-logo img {
            width: 100px;
            height: auto;
            filter: drop-shadow(0 8px 20px rgba(0,0,0,0.3)) drop-shadow(0 0 20px rgba(255, 107, 107, 0.2));
            animation: bounce 3s ease-in-out infinite;
        }
        
        @keyframes bounce {
            0%, 100% { transform: translateY(0); }
            50% { transform: translateY(-15px); }
        }
        
        .verify-header {
            text-align: center;
            margin-bottom: 30px;
        }
        
        .verify-header h2 {
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
        
        .verify-header p {
            color: var(--text-light);
            font-size: 15px;
            transition: color 0.3s ease;
            font-weight: 500;
            line-height: 1.5;
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
        
        .verification-code-input {
            width: 100%;
            padding: 15px 20px 15px 50px;
            border: 2px solid var(--border-color);
            border-radius: 15px;
            font-size: 24px;
            text-align: center;
            letter-spacing: 8px;
            font-weight: bold;
            transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
            background: var(--card-bg);
            color: var(--text-color);
            font-weight: 500;
        }
        
        .verification-code-input:focus {
            outline: none;
            border-color: var(--primary-color);
            box-shadow: 0 0 0 5px rgba(255, 107, 107, 0.15), 0 8px 20px rgba(255, 107, 107, 0.1);
            transform: translateY(-3px);
        }
        
        .verification-code-input:focus + .input-wrapper i {
            color: var(--primary-color);
            transform: translateY(-50%) scale(1.15);
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
        
        .resend-container {
            margin-top: 25px;
            padding: 20px;
            background: linear-gradient(135deg, rgba(255, 107, 107, 0.08), rgba(255, 82, 82, 0.05));
            border-radius: 15px;
            border-left: 4px solid var(--primary-color);
            text-align: center;
        }
        
        .resend-container p {
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
        
        .back-to-register {
            display: inline-block;
            margin-top: 25px;
            color: var(--primary-color);
            text-decoration: none;
            font-weight: 700;
            transition: all 0.3s ease;
            position: relative;
            font-size: 14px;
        }
        
        .back-to-register::after {
            content: '';
            position: absolute;
            bottom: -2px;
            left: 0;
            width: 0;
            height: 2px;
            background: var(--primary-color);
            transition: width 0.3s ease;
        }
        
        .back-to-register:hover::after {
            width: 100%;
        }
        
        /* Enhanced success message */
        .success-message {
            color: #28a745;
            background: linear-gradient(135deg, rgba(40, 167, 69, 0.15), rgba(40, 167, 69, 0.08));
            border: 2px solid rgba(40, 167, 69, 0.5);
            padding: 20px;
            border-radius: 15px;
            margin-bottom: 25px;
            font-size: 15px;
            animation: slideDown 0.6s ease;
            box-shadow: 0 4px 15px rgba(40, 167, 69, 0.2);
            font-weight: 600;
            text-align: center;
        }
        
        .redirect-message {
            color: var(--text-light);
            font-size: 14px;
            margin-top: 10px;
            font-weight: 500;
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
        
        @media (max-width: 1400px) {
            .logo-left {
                left: 60px;
            }
            
            .verify-container {
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
            
            .verify-container {
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
            
            .verify-container {
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
            
            .verification-code-input {
                font-size: 20px;
                padding: 12px 20px 12px 50px;
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
    
    <a href="login.php" class="back-button">
        <i class="fas fa-arrow-left"></i>
        Back to Login
    </a>
    
    <div class="logo-left">
        <img src="../img/frsm-logo.png" alt="Fire & Rescue Services Logo">
        <h1>FIRE & RESCUE</h1>
        <p class="tagline">Emergency Services Management</p>
    </div>
    
    <div class="verify-container">
        <div class="verify-logo">
            <img src="../img/frsm-logo.png" alt="Fire & Rescue Services">
        </div>
        
        <div class="verify-header">
            <h2>Verify Your Email</h2>
            <p>We've sent a verification code to <strong><?php echo htmlspecialchars($email); ?></strong></p>
            <p>Please enter the code to complete your login.</p>
        </div>
        
        <?php if (!empty($errors['verification_code'])): ?>
            <div class="error-message">
                <p><i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($errors['verification_code']); ?></p>
            </div>
        <?php endif; ?>
        
        <?php if (isset($success_message)): ?>
            <div class="success-message">
                <p><i class="fas fa-check-circle"></i> <?php echo $success_message; ?></p>
            </div>
        <?php endif; ?>
        
        <?php if ($success): ?>
            <div class="success-message">
                <p><i class="fas fa-check-circle"></i> <?php echo $success; ?></p>
                <div class="redirect-message">Redirecting you to login page...</div>
            </div>
        <?php else: ?>
            <form method="POST" action="" id="verificationForm">
                <div class="form-group">
                    <label for="verification_code">Verification Code</label>
                    <div class="input-wrapper">
                        <i class="fas fa-shield-alt"></i>
                        <input type="text" id="verification_code" name="verification_code" class="verification-code-input" maxlength="6" placeholder="000000" required value="<?php echo isset($_POST['verification_code']) ? htmlspecialchars($_POST['verification_code']) : ''; ?>">
                    </div>
                </div>
                
                <button type="submit" class="btn-primary" id="verifyBtn">
                    <i class="fas fa-check-circle"></i> Verify Email & Login
                </button>
            </form>
            
            <div class="resend-container">
                <p>Didn't receive the code?</p>
                <form method="POST" action="" style="display: inline;">
                    <input type="hidden" name="resend_verification" value="1">
                    <button type="submit" class="resend-btn">
                        <i class="fas fa-redo"></i> Resend Verification Code
                    </button>
                </form>
            </div>
        <?php endif; ?>
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
        
        // Auto-focus on verification code input
        document.getElementById('verification_code').focus();
        
        // Remove auto-submit functionality and only allow numeric input
        document.getElementById('verification_code').addEventListener('input', function(e) {
            // Remove non-digit characters
            this.value = this.value.replace(/\D/g, '');
            
            // Enable/disable submit button based on code length
            const verifyBtn = document.getElementById('verifyBtn');
            if (this.value.length === 6) {
                verifyBtn.disabled = false;
            } else {
                verifyBtn.disabled = true;
            }
        });

        // Prevent non-numeric input
        document.getElementById('verification_code').addEventListener('keypress', function(e) {
            if (!/\d/.test(e.key)) {
                e.preventDefault();
            }
        });

        // Initially disable the submit button
        document.getElementById('verifyBtn').disabled = true;

        // Form validation
        document.getElementById('verificationForm').addEventListener('submit', function(e) {
            const code = document.getElementById('verification_code').value;
            if (code.length !== 6) {
                e.preventDefault();
                alert('Please enter a 6-digit verification code.');
                return false;
            }
        });
    </script>
</body>
</html>
