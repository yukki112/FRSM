<?php
session_start();
require_once '../../config/db_connection.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: ../../login/login.php");
    exit();
}

$user_id = $_SESSION['user_id'];
$query = "SELECT first_name, middle_name, last_name, username, role, email, contact, address, date_of_birth, avatar FROM users WHERE id = ?";
$stmt = $pdo->prepare($query);
$stmt->execute([$user_id]);
$user = $stmt->fetch();

if ($user) {
    $first_name = htmlspecialchars($user['first_name']);
    $middle_name = htmlspecialchars($user['middle_name']);
    $last_name = htmlspecialchars($user['last_name']);
    $username = htmlspecialchars($user['username']);
    $role = htmlspecialchars($user['role']);
    $email = htmlspecialchars($user['email']);
    $contact = htmlspecialchars($user['contact']);
    $address = htmlspecialchars($user['address']);
    $date_of_birth = htmlspecialchars($user['date_of_birth']);
    $avatar = htmlspecialchars($user['avatar']);
    
    $full_name = $first_name;
    if (!empty($middle_name)) {
        $full_name .= " " . $middle_name;
    }
    $full_name .= " " . $last_name;
} else {
    $full_name = "User";
    $role = "USER";
    $email = "";
    $contact = "";
    $address = "";
    $date_of_birth = "";
    $avatar = "";
}

// Handle avatar upload
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'upload_avatar') {
    if (isset($_FILES['avatar']) && $_FILES['avatar']['error'] === UPLOAD_ERR_OK) {
        $upload_dir = 'uploads/avatars/';
        if (!is_dir($upload_dir)) {
            mkdir($upload_dir, 0755, true);
        }
        
        $file_extension = pathinfo($_FILES['avatar']['name'], PATHINFO_EXTENSION);
        $file_name = 'avatar_' . $user_id . '_' . time() . '.' . $file_extension;
        $file_path = $upload_dir . $file_name;
        
        // Check file type
        $allowed_types = ['jpg', 'jpeg', 'png', 'gif'];
        if (!in_array(strtolower($file_extension), $allowed_types)) {
            echo json_encode(['success' => false, 'message' => 'Invalid file type. Only JPG, PNG, and GIF are allowed.']);
            exit;
        }
        
        // Check file size (max 5MB)
        if ($_FILES['avatar']['size'] > 5 * 1024 * 1024) {
            echo json_encode(['success' => false, 'message' => 'File too large. Maximum size is 5MB.']);
            exit;
        }
        
        // Delete old avatar if exists
        if ($avatar && file_exists($upload_dir . $avatar)) {
            unlink($upload_dir . $avatar);
        }
        
        // Move uploaded file
        if (move_uploaded_file($_FILES['avatar']['tmp_name'], $file_path)) {
            // Update database
            $update_query = "UPDATE users SET avatar = ? WHERE id = ?";
            $update_stmt = $pdo->prepare($update_query);
            if ($update_stmt->execute([$file_name, $user_id])) {
                $avatar = $file_name;
                echo json_encode(['success' => true, 'avatar_url' => 'uploads/avatars/' . $file_name]);
            } else {
                echo json_encode(['success' => false, 'message' => 'Failed to update database.']);
            }
        } else {
            echo json_encode(['success' => false, 'message' => 'Failed to upload file.']);
        }
    } else {
        echo json_encode(['success' => false, 'message' => 'No file uploaded or upload error.']);
    }
    exit;
}

// Handle password change
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'change_password') {
    $current_password = $_POST['current_password'] ?? '';
    $new_password = $_POST['new_password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';
    
    // Validate inputs
    if (empty($current_password) || empty($new_password) || empty($confirm_password)) {
        echo json_encode(['success' => false, 'message' => 'All fields are required.']);
        exit;
    }
    
    if ($new_password !== $confirm_password) {
        echo json_encode(['success' => false, 'message' => 'New passwords do not match.']);
        exit;
    }
    
    if (strlen($new_password) < 8) {
        echo json_encode(['success' => false, 'message' => 'New password must be at least 8 characters long.']);
        exit;
    }
    
    // Verify current password
    $password_query = "SELECT password FROM users WHERE id = ?";
    $password_stmt = $pdo->prepare($password_query);
    $password_stmt->execute([$user_id]);
    $user_data = $password_stmt->fetch();
    
    if (!$user_data || !password_verify($current_password, $user_data['password'])) {
        echo json_encode(['success' => false, 'message' => 'Current password is incorrect.']);
        exit;
    }
    
    // Update password
    $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
    $update_query = "UPDATE users SET password = ? WHERE id = ?";
    $update_stmt = $pdo->prepare($update_query);
    
    if ($update_stmt->execute([$hashed_password, $user_id])) {
        echo json_encode(['success' => true, 'message' => 'Password updated successfully.']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Failed to update password.']);
    }
    exit;
}

$stmt = null;
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Profile - Fire & Rescue Services</title>
    <link href='https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css' rel='stylesheet'>
    <link rel="icon" type="image/png" sizes="32x32" href="../../img/frsm-logo.png">
    <link rel="stylesheet" href="../../css/dashboard.css">
    <script src="https://cdn.jsdelivr.net/npm/cropperjs@1.5.12/dist/cropper.min.js"></script>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/cropperjs@1.5.12/dist/cropper.min.css">
    <style>
        :root {
            --primary-color: #dc2626;
            --primary-dark: #b91c1c;
            --secondary-color: #ef4444;
            --secondary-dark: #dc2626;
            --background-color: #ffffff;
            --text-color: #1f2937;
            --text-light: #6b7280;
            --border-color: #e5e7eb;
            --card-bg: #f9fafb;
            --sidebar-bg: #ffffff;
            
            --icon-red: #ef4444;
            --icon-blue: #3b82f6;
            --icon-green: #10b981;
            --icon-purple: #8b5cf6;
            --icon-yellow: #f59e0b;
            --icon-indigo: #6366f1;
            --icon-cyan: #06b6d4;
            --icon-orange: #f97316;
            --icon-pink: #ec4899;
            --icon-teal: #14b8a6;
            
            --icon-bg-red: rgba(254, 226, 226, 0.7);
            --icon-bg-blue: rgba(219, 234, 254, 0.7);
            --icon-bg-green: rgba(220, 252, 231, 0.7);
            --icon-bg-purple: rgba(243, 232, 255, 0.7);
            --icon-bg-yellow: rgba(254, 243, 199, 0.7);
            --icon-bg-indigo: rgba(224, 231, 255, 0.7);
            --icon-bg-cyan: rgba(207, 250, 254, 0.7);
            --icon-bg-orange: rgba(255, 237, 213, 0.7);
            --icon-bg-pink: rgba(252, 231, 243, 0.7);
            --icon-bg-teal: rgba(204, 251, 241, 0.7);

            --chart-red: #ef4444;
            --chart-orange: #f97316;
            --chart-yellow: #f59e0b;
            --chart-green: #10b981;
            --chart-blue: #3b82f6;
            --chart-purple: #8b5cf6;
            --chart-pink: #ec4899;

            --primary: var(--primary-color);
            --primary-dark: var(--primary-dark);
            --secondary: var(--secondary-color);
            --success: var(--icon-green);
            --warning: var(--icon-yellow);
            --danger: var(--primary-color);
            --info: var(--icon-blue);
            --light: #f9fafb;
            --dark: #1f2937;
            --gray-100: #f3f4f6;
            --gray-200: #e5e7eb;
            --gray-300: #d1d5db;
            --gray-400: #9ca3af;
            --gray-500: #6b7280;
            --gray-600: #4b5563;
            --gray-700: #374151;
            --gray-800: #1f2937;
            --gray-900: #111827;
        }
        
        .dark-mode {
            --background-color: #0f172a;
            --text-color: #f1f5f9;
            --text-light: #94a3b8;
            --border-color: #334155;
            --card-bg: #1e293b;
            --sidebar-bg: #0f172a;
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            font-size: 14px;
            line-height: 1.6;
            color: var(--text-color);
            background-color: var(--background-color);
        }

        h1, h2, h3, h4, h5, h6 {
            font-weight: 600;
        }

        .dashboard-title {
            font-size: 28px;
            font-weight: 800;
        }

        .dashboard-subtitle {
            font-size: 16px;
        }

        .dashboard-content {
            padding: 0;
            min-height: 100vh;
            position: relative;
            z-index: 1;
        }

        .header {
            position: relative;
            z-index: 1000;
        }

        .dashboard-header {
            color: white;
            padding: 60px 40px 40px;
            border-radius: 0 0 30px 30px;
            margin-bottom: 40px;
            position: relative;
            overflow: hidden;
            background: linear-gradient(135deg, #f8fafc 0%, #e2e8f0 100%);
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.08);
            border: 1px solid var(--border-color);
        }

        .dark-mode .dashboard-header {
            background: linear-gradient(135deg, #1e293b 0%, #334155 100%);
        }
        
        .dashboard-title {
            font-size: 40px;
            margin-bottom: 12px;
            color: var(--text-color);
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }

        .dashboard-subtitle {
            font-size: 16px;
            opacity: 0.9;
            color: var(--text-color);
        }

        .dashboard-actions {
            display: flex;
            gap: 12px;
            margin-top: 20px;
        }

        .primary-button, .secondary-button {
            padding: 12px 20px;
            border-radius: 12px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 8px;
            border: none;
            font-size: 14px;
        }

        .primary-button {
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            color: white;
            box-shadow: 0 4px 15px rgba(220, 38, 38, 0.3);
        }

        .primary-button:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(220, 38, 38, 0.4);
        }

        .secondary-button {
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            color: var(--text-color);
        }

        .secondary-button:hover {
            background: var(--gray-100);
            transform: translateY(-2px);
        }

        .dark-mode .secondary-button:hover {
            background: var(--gray-800);
        }

        .profile-container {
            display: flex;
            flex-direction: column;
            gap: 24px;
            padding: 0 40px 40px;
        }
        
        .profile-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 24px;
        }
        
        .profile-title {
            font-size: 28px;
            font-weight: 800;
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            margin-bottom: 8px;
        }
        
        .profile-subtitle {
            color: var(--text-light);
            font-size: 16px;
        }
        
        .profile-content {
            display: flex;
            gap: 24px;
        }
        
        .profile-sidebar {
            width: 320px;
            flex-shrink: 0;
        }
        
        .profile-main {
            flex: 1;
        }
        
        .profile-card {
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: 20px;
            padding: 25px;
            margin-bottom: 24px;
            box-shadow: 0 2px 12px rgba(0, 0, 0, 0.08);
            transition: all 0.3s ease;
        }

        .profile-card:hover {
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.12);
            transform: translateY(-2px);
        }
        
        .profile-avatar-container {
            display: flex;
            flex-direction: column;
            align-items: center;
            text-align: center;
            padding: 20px;
        }
        
        .profile-avatar {
            width: 140px;
            height: 140px;
            border-radius: 50%;
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 700;
            font-size: 42px;
            margin-bottom: 20px;
            position: relative;
            overflow: hidden;
            border: 4px solid var(--card-bg);
            box-shadow: 0 6px 20px rgba(220, 38, 38, 0.3);
            transition: all 0.3s ease;
        }

        .profile-avatar:hover {
            transform: scale(1.05);
            box-shadow: 0 8px 25px rgba(220, 38, 38, 0.4);
        }
        
        .profile-avatar img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        
        .avatar-edit {
            position: absolute;
            bottom: 0;
            right: 0;
            background: var(--primary-color);
            color: white;
            width: 40px;
            height: 40px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            border: 3px solid var(--card-bg);
            transition: all 0.3s ease;
            font-size: 18px;
        }
        
        .avatar-edit:hover {
            transform: scale(1.1);
            background: var(--primary-dark);
        }
        
        .profile-name {
            font-size: 22px;
            font-weight: 700;
            margin-bottom: 6px;
            color: var(--text-color);
        }
        
        .profile-role {
            color: var(--text-light);
            font-size: 15px;
            margin-bottom: 20px;
            background: rgba(220, 38, 38, 0.1);
            padding: 6px 16px;
            border-radius: 20px;
            display: inline-block;
        }
        
        .profile-stats {
            display: flex;
            justify-content: space-between;
            width: 100%;
            margin-top: 20px;
            padding-top: 20px;
            border-top: 1px solid var(--border-color);
        }
        
        .stat-item {
            text-align: center;
            flex: 1;
        }
        
        .stat-value {
            font-size: 20px;
            font-weight: 800;
            color: var(--primary-color);
            margin-bottom: 4px;
        }
        
        .stat-label {
            font-size: 12px;
            color: var(--text-light);
            font-weight: 500;
        }
        
        .profile-tabs {
            display: flex;
            flex-direction: column;
            gap: 8px;
        }
        
        .profile-tab {
            padding: 16px 20px;
            border-radius: 12px;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 12px;
            font-weight: 500;
            border: 1px solid transparent;
        }
        
        .profile-tab:hover {
            background: rgba(220, 38, 38, 0.05);
            border-color: rgba(220, 38, 38, 0.1);
        }
        
        .profile-tab.active {
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            color: white;
            box-shadow: 0 4px 12px rgba(220, 38, 38, 0.3);
            border-color: var(--primary-color);
        }
        
        .profile-tab i {
            font-size: 20px;
        }
        
        .tab-content {
            display: none;
            animation: fadeIn 0.5s ease;
        }

        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        .tab-content.active {
            display: block;
        }
        
        .form-group {
            margin-bottom: 20px;
        }
        
        .form-label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: var(--text-color);
        }
        
        .form-input {
            width: 100%;
            padding: 12px 16px;
            border-radius: 10px;
            border: 1px solid var(--border-color);
            background: var(--card-bg);
            color: var(--text-color);
            font-size: 14px;
            transition: all 0.3s ease;
        }
        
        .form-input:focus {
            outline: none;
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(220, 38, 38, 0.1);
        }
        
        .form-input:disabled {
            background: var(--gray-100);
            color: var(--text-light);
            cursor: not-allowed;
        }
        
        .dark-mode .form-input:disabled {
            background: var(--gray-800);
        }
        
        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 16px;
        }
        
        .form-actions {
            display: flex;
            gap: 12px;
            margin-top: 24px;
        }
        
        .info-item {
            display: flex;
            justify-content: space-between;
            padding: 14px 0;
            border-bottom: 1px solid var(--border-color);
            transition: all 0.2s ease;
        }

        .info-item:hover {
            background: rgba(220, 38, 38, 0.03);
            padding-left: 10px;
            padding-right: 10px;
            border-radius: 8px;
        }
        
        .info-label {
            font-weight: 600;
            color: var(--text-color);
        }
        
        .info-value {
            color: var(--text-light);
            font-weight: 500;
        }
        
        .security-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 18px 0;
            border-bottom: 1px solid var(--border-color);
            transition: all 0.2s ease;
        }

        .security-item:hover {
            background: rgba(220, 38, 38, 0.03);
            padding-left: 15px;
            padding-right: 15px;
            border-radius: 8px;
        }
        
        .security-info {
            flex: 1;
        }
        
        .security-title {
            font-weight: 600;
            margin-bottom: 4px;
            font-size: 15px;
        }
        
        .security-description {
            color: var(--text-light);
            font-size: 13px;
            line-height: 1.5;
        }
        
        .toggle-switch {
            position: relative;
            display: inline-block;
            width: 50px;
            height: 24px;
        }
        
        .toggle-switch input {
            opacity: 0;
            width: 0;
            height: 0;
        }
        
        .toggle-slider {
            position: absolute;
            cursor: pointer;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background-color: var(--gray-300);
            transition: .4s;
            border-radius: 24px;
        }
        
        .toggle-slider:before {
            position: absolute;
            content: "";
            height: 16px;
            width: 16px;
            left: 4px;
            bottom: 4px;
            background-color: white;
            transition: .4s;
            border-radius: 50%;
        }
        
        input:checked + .toggle-slider {
            background-color: var(--primary-color);
        }
        
        input:checked + .toggle-slider:before {
            transform: translateX(26px);
        }
        
        .change-button {
            background: none;
            border: 1px solid var(--border-color);
            color: var(--text-color);
            padding: 8px 16px;
            border-radius: 8px;
            cursor: pointer;
            transition: all 0.3s ease;
            font-size: 13px;
            font-weight: 500;
        }
        
        .change-button:hover {
            background: var(--gray-100);
            border-color: var(--primary-color);
            color: var(--primary-color);
        }
        
        .dark-mode .change-button:hover {
            background: var(--gray-800);
        }
        
        .request-change-button {
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            color: white;
            border: none;
            padding: 10px 18px;
            border-radius: 10px;
            cursor: pointer;
            transition: all 0.3s ease;
            font-size: 14px;
            font-weight: 600;
            box-shadow: 0 2px 8px rgba(220, 38, 38, 0.2);
        }
        
        .request-change-button:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(220, 38, 38, 0.3);
        }
        
        .notification-container {
            position: fixed;
            top: 20px;
            right: 20px;
            z-index: 1100;
            display: flex;
            flex-direction: column;
            gap: 10px;
        }
        
        .notification {
            padding: 16px 20px;
            border-radius: 12px;
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            display: flex;
            align-items: center;
            gap: 12px;
            transform: translateX(100%);
            opacity: 0;
            transition: all 0.3s ease;
            max-width: 350px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
        }
        
        .notification.show {
            transform: translateX(0);
            opacity: 1;
        }
        
        .notification-icon {
            font-size: 20px;
            flex-shrink: 0;
        }
        
        .notification-success .notification-icon {
            color: var(--success);
        }
        
        .notification-info .notification-icon {
            color: var(--info);
        }
        
        .notification-warning .notification-icon {
            color: var(--warning);
        }
        
        .notification-error .notification-icon {
            color: var(--danger);
        }
        
        .notification-content {
            flex: 1;
        }
        
        .notification-title {
            font-weight: 600;
            margin-bottom: 4px;
        }
        
        .notification-message {
            font-size: 14px;
            color: var(--text-light);
        }
        
        .notification-close {
            background: none;
            border: none;
            font-size: 16px;
            cursor: pointer;
            color: var(--text-light);
            flex-shrink: 0;
        }
        
        .modal-overlay {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.6);
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 1000;
            opacity: 0;
            visibility: hidden;
            transition: all 0.3s ease;
        }
        
        .modal-overlay.active {
            opacity: 1;
            visibility: visible;
        }
        
        .modal {
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: 20px;
            width: 90%;
            max-width: 500px;
            transform: scale(0.9);
            transition: all 0.3s ease;
            max-height: 90vh;
            overflow-y: auto;
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.15);
        }
        
        .modal-overlay.active .modal {
            transform: scale(1);
        }
        
        .modal-header {
            padding: 25px;
            border-bottom: 1px solid var(--border-color);
            display: flex;
            align-items: center;
            justify-content: space-between;
            background: rgba(220, 38, 38, 0.02);
        }
        
        .modal-title {
            font-size: 20px;
            font-weight: 700;
            color: var(--text-color);
        }
        
        .modal-close {
            background: none;
            border: none;
            font-size: 24px;
            cursor: pointer;
            color: var(--text-light);
            transition: all 0.3s ease;
            padding: 5px;
            border-radius: 8px;
        }
        
        .modal-close:hover {
            color: var(--danger);
            background: rgba(220, 38, 38, 0.1);
        }
        
        .modal-body {
            padding: 25px;
        }
        
        .modal-footer {
            padding: 20px 25px;
            border-top: 1px solid var(--border-color);
            display: flex;
            justify-content: flex-end;
            gap: 12px;
            background: rgba(220, 38, 38, 0.02);
        }
        
        .avatar-modal {
            max-width: 700px;
        }
        
        .avatar-preview-container {
            text-align: center;
            margin-bottom: 20px;
        }
        
        .avatar-preview {
            width: 200px;
            height: 200px;
            border-radius: 50%;
            margin: 0 auto 20px;
            overflow: hidden;
            border: 4px solid var(--border-color);
            position: relative;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
        }
        
        .avatar-preview img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        
        .avatar-controls {
            display: flex;
            flex-direction: column;
            gap: 16px;
            margin-bottom: 20px;
        }
        
        .avatar-upload {
            text-align: center;
            padding: 25px;
            border: 2px dashed var(--border-color);
            border-radius: 12px;
            cursor: pointer;
            transition: all 0.3s ease;
            background: rgba(220, 38, 38, 0.02);
        }
        
        .avatar-upload:hover {
            border-color: var(--primary-color);
            background: rgba(220, 38, 38, 0.05);
        }
        
        .avatar-upload i {
            font-size: 48px;
            color: var(--text-light);
            margin-bottom: 12px;
        }
        
        .avatar-upload-text {
            font-weight: 600;
            margin-bottom: 4px;
            color: var(--text-color);
        }
        
        .avatar-upload-subtext {
            font-size: 13px;
            color: var(--text-light);
        }
        
        .avatar-crop-container {
            width: 100%;
            max-height: 400px;
            overflow: hidden;
            border-radius: 12px;
            margin-bottom: 16px;
            display: none;
        }
        
        .avatar-crop-area {
            width: 100%;
            height: 300px;
            background: var(--gray-100);
            border-radius: 12px;
            overflow: hidden;
            position: relative;
        }
        
        .dark-mode .avatar-crop-area {
            background: var(--gray-800);
        }
        
        .crop-preview {
            width: 150px;
            height: 150px;
            border-radius: 50%;
            overflow: hidden;
            margin: 0 auto;
            border: 3px solid var(--border-color);
            display: none;
        }
        
        .fullscreen-button {
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            color: var(--text-color);
            padding: 8px 12px;
            border-radius: 8px;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 6px;
            font-size: 13px;
            font-weight: 500;
        }
        
        .fullscreen-button:hover {
            background: var(--gray-100);
            border-color: var(--primary-color);
            color: var(--primary-color);
        }
        
        .dark-mode .fullscreen-button:hover {
            background: var(--gray-800);
        }
        
        .dashboard-animation {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: var(--background-color);
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            z-index: 9999;
            transition: opacity 0.5s ease;
        }

        .animation-logo {
            display: flex;
            align-items: center;
            gap: 16px;
            margin-bottom: 30px;
            opacity: 0;
            transform: translateY(20px);
            transition: all 0.5s ease;
        }

        .animation-logo-icon img {
            width: 70px;
            height: 75px;
            filter: drop-shadow(0 4px 8px rgba(0, 0, 0, 0.2));
        }

        .animation-logo-text {
            font-size: 28px;
            font-weight: 800;
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }

        .animation-progress {
            width: 200px;
            height: 4px;
            background: var(--gray-200);
            border-radius: 2px;
            overflow: hidden;
            margin-bottom: 20px;
        }

        .animation-progress-fill {
            height: 100%;
            background: linear-gradient(90deg, var(--primary-color), var(--secondary-color));
            border-radius: 2px;
            transition: width 1s ease;
            width: 0%;
        }

        .animation-text {
            font-size: 16px;
            color: var(--text-light);
            opacity: 0;
            transition: opacity 0.5s ease;
        }
        
        .user-profile {
            position: relative;
            cursor: pointer;
            z-index: 2000;
        }
        
        .user-profile-dropdown {
            position: absolute;
            top: 100%;
            right: 0;
            margin-top: 8px;
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: 12px;
            min-width: 180px;
            z-index: 2001;
            opacity: 0;
            visibility: hidden;
            transform: translateY(-10px);
            transition: all 0.3s ease;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
        }
        
        .user-profile-dropdown.active {
            opacity: 1;
            visibility: visible;
            transform: translateY(0);
        }
        
        .user-profile-dropdown-item {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 12px 16px;
            color: var(--text-color);
            text-decoration: none;
            transition: all 0.2s ease;
            border-bottom: 1px solid var(--border-color);
        }
        
        .user-profile-dropdown-item:last-child {
            border-bottom: none;
        }
        
        .user-profile-dropdown-item:hover {
            background: rgba(220, 38, 38, 0.05);
        }
        
        .user-profile-dropdown-item i {
            font-size: 18px;
            width: 20px;
            text-align: center;
        }
        
        .user-profile-dropdown-item.settings i {
            color: var(--icon-indigo);
        }
        
        .user-profile-dropdown-item.profile i {
            color: var(--icon-orange);
        }
        
        .user-profile-dropdown-item.logout i {
            color: var(--icon-red);
        }

        .password-strength {
            height: 4px;
            border-radius: 2px;
            margin-top: 8px;
            transition: all 0.3s ease;
        }

        .password-weak {
            background: #ef4444;
            width: 25%;
        }

        .password-medium {
            background: #f59e0b;
            width: 50%;
        }

        .password-strong {
            background: #10b981;
            width: 100%;
        }
        
        @media (max-width: 768px) {
            .profile-content {
                flex-direction: column;
            }
            
            .profile-sidebar {
                width: 100%;
            }
            
            .profile-container {
                padding: 0 25px 30px;
            }
            
            .dashboard-header {
                padding: 40px 25px 30px;
            }
            
            .dashboard-title {
                font-size: 32px;
            }
            
            .form-row {
                grid-template-columns: 1fr;
            }

            .profile-avatar {
                width: 120px;
                height: 120px;
                font-size: 36px;
            }
        }
    </style>
</head>
<body>
    <div class="dashboard-animation" id="dashboard-animation">
        <div class="animation-logo">
            <div class="animation-logo-icon">
                <img src="../../img/frsm-logo.png" alt="Fire & Rescue Logo">
            </div>
            <span class="animation-logo-text">Fire & Rescue</span>
        </div>
        <div class="animation-progress">
            <div class="animation-progress-fill" id="animation-progress"></div>
        </div>
        <div class="animation-text" id="animation-text">Loading Profile...</div>
    </div>
    
    <!-- Notification Container -->
    <div class="notification-container" id="notification-container"></div>
    
    <!-- Avatar Modal -->
    <div class="modal-overlay" id="avatar-modal">
        <div class="modal avatar-modal">
            <div class="modal-header">
                <h2 class="modal-title">Update Profile Picture</h2>
                <button class="modal-close" id="avatar-modal-close">&times;</button>
            </div>
            <div class="modal-body">
                <div class="avatar-preview-container">
                    <div class="avatar-preview">
                        <img src="<?php echo $avatar ? 'uploads/avatars/' . $avatar : '../img/placeholder-avatar.png'; ?>" alt="Avatar Preview" id="avatar-preview-img">
                    </div>
                    <div class="crop-preview" id="crop-preview"></div>
                </div>
                <div class="avatar-controls">
                    <div class="avatar-upload" id="avatar-upload">
                        <i class='bx bx-cloud-upload'></i>
                        <div class="avatar-upload-text">Upload New Image</div>
                        <div class="avatar-upload-subtext">JPG, PNG or GIF - Max 5MB</div>
                        <input type="file" id="avatar-file-input" accept="image/*" style="display: none;">
                    </div>
                    <div class="avatar-crop-container" id="avatar-crop-container">
                        <div class="avatar-crop-area" id="avatar-crop-area">
                            <!-- Cropper will be initialized here -->
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button class="secondary-button" id="avatar-cancel">Cancel</button>
                <button class="primary-button" id="avatar-save">Save Changes</button>
            </div>
        </div>
    </div>
    
    <!-- Change Request Modal -->
    <div class="modal-overlay" id="change-request-modal">
        <div class="modal">
            <div class="modal-header">
                <h2 class="modal-title">Request Information Change</h2>
                <button class="modal-close" id="change-request-modal-close">&times;</button>
            </div>
            <div class="modal-body">
                <div class="form-group">
                    <label class="form-label">Field to Change</label>
                    <input type="text" class="form-input" id="change-field" readonly>
                </div>
                <div class="form-group">
                    <label class="form-label">Current Value</label>
                    <input type="text" class="form-input" id="current-value" readonly>
                </div>
                <div class="form-group">
                    <label class="form-label">Requested Value</label>
                    <input type="text" class="form-input" id="requested-value">
                </div>
                <div class="form-group">
                    <label class="form-label">Reason for Change</label>
                    <textarea class="form-input" id="change-reason" rows="3" placeholder="Please provide a reason for this change request"></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button class="secondary-button" id="change-request-cancel">Cancel</button>
                <button class="primary-button" id="change-request-submit">Submit Request</button>
            </div>
        </div>
    </div>

    <!-- Change Password Modal -->
    <div class="modal-overlay" id="change-password-modal">
        <div class="modal">
            <div class="modal-header">
                <h2 class="modal-title">Change Password</h2>
                <button class="modal-close" id="change-password-modal-close">&times;</button>
            </div>
            <div class="modal-body">
                <div class="form-group">
                    <label class="form-label">Current Password</label>
                    <input type="password" class="form-input" id="current-password" placeholder="Enter your current password">
                </div>
                <div class="form-group">
                    <label class="form-label">New Password</label>
                    <input type="password" class="form-input" id="new-password" placeholder="Enter your new password">
                    <div class="password-strength" id="password-strength"></div>
                </div>
                <div class="form-group">
                    <label class="form-label">Confirm New Password</label>
                    <input type="password" class="form-input" id="confirm-password" placeholder="Confirm your new password">
                </div>
            </div>
            <div class="modal-footer">
                <button class="secondary-button" id="change-password-cancel">Cancel</button>
                <button class="primary-button" id="change-password-submit">Change Password</button>
            </div>
        </div>
    </div>
    
    <div class="container">
        <!-- Sidebar -->
        <div class="sidebar">
            <!-- Logo -->
            <div class="logo">
                <div class="logo-icon">
                    <img src="../../img/frsm-logo.png" alt="Fire & Rescue Logo" style="width: 40px; height: 45px;">
                </div>
                <span class="logo-text">Fire & Rescue</span>
            </div>
            
            <!-- Menu Section -->
            <div class="menu-section">
                <p class="menu-title">FIRE & RESCUE MANAGEMENT</p>
                
                <div class="menu-items">
                    <a href="../employee_dashboard.php" class="menu-item">
                        <div class="icon-box icon-bg-red">
                            <i class='bx bxs-dashboard icon-red'></i>
                        </div>
                        <span class="font-medium">Dashboard</span>
                    </a>
                    
                    <!-- Fire & Incident Reporting -->
                    <div class="menu-item" onclick="toggleSubmenu('fire-incident')">
                        <div class="icon-box icon-bg-orange">
                            <i class='bx bxs-alarm-exclamation icon-orange'></i>
                        </div>
                        <span class="font-medium">Fire & Incident Reporting</span>
                        <svg class="dropdown-arrow menu-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                        </svg>
                    </div>
                    <div id="fire-incident" class="submenu">
                        <a href="../incident/receive_data.php" class="submenu-item">Receive Data</a>
                        <a href="../incident/manual_reporting.php" class="submenu-item">Manual Reporting</a>
                        <a href="../incident/update_status.php" class="submenu-item">Update Status</a>
                    </div>
                    
                    <!-- Dispatch Coordination -->
                    <div class="menu-item" onclick="toggleSubmenu('dispatch')">
                        <div class="icon-box icon-bg-yellow">
                            <i class='bx bxs-truck icon-yellow'></i>
                        </div>
                        <span class="font-medium">Dispatch Coordination</span>
                        <svg class="dropdown-arrow menu-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                        </svg>
                    </div>
                    <div id="dispatch" class="submenu">
                        <a href="../dispatch/select_unit.php" class="submenu-item">Select Unit</a>
                        <a href="../dispatch/send_dispatch.php" class="submenu-item">Send Dispatch Info</a>
                        <a href="../dispatch/notify_unit.php" class="submenu-item">Notify Unit</a>
                        <a href="../dispatch/track_status.php" class="submenu-item">Track Status</a>
                    </div>
                    
                    <!-- Barangay Volunteer Roster Access -->
                    <div class="menu-item" onclick="toggleSubmenu('volunteer')">
                        <div class="icon-box icon-bg-blue">
                            <i class='bx bxs-user-detail icon-blue'></i>
                        </div>
                        <span class="font-medium">Volunteer Roster Access</span>
                        <svg class="dropdown-arrow menu-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                        </svg>
                    </div>
                    <div id="volunteer" class="submenu">
                        <a href="../volunteer/review_data.php" class="submenu-item">Review/Aprroved Data Management</a>
                        <a href="../volunteer/approve_applications.php" class="submenu-item">Assign Volunteers</a>
                        <a href="../volunteer/view_availability.php" class="submenu-item">View Availability</a>
                        <a href="../volunteer/remove_volunteers.php" class="submenu-item">Remove Volunteers</a>
                        <a href="../volunteer/toggle_volunteer_registration.php" class="submenu-item">Open/Close Registration</a>
                    </div>

                    <!-- Resource Inventory Updates -->
                    <div class="menu-item" onclick="toggleSubmenu('inventory')">
                        <div class="icon-box icon-bg-green">
                            <i class='bx bxs-cube icon-green'></i>
                        </div>
                        <span class="font-medium">Resource Inventory</span>
                        <svg class="dropdown-arrow menu-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                        </svg>
                    </div>
                    <div id="inventory" class="submenu">
                        <a href="../inventory/log_usage.php" class="submenu-item">Log Usage</a>
                        <a href="../inventory/report_damages.php" class="submenu-item">Report Damages</a>
                        <a href="../inventory/request_supplies.php" class="submenu-item">Request Supplies</a>
                        <a href="../inventory/tag_resources.php" class="submenu-item">Tag Resources</a>
                    </div>
                    
                    <!-- Shift & Duty Scheduling -->
                    <div class="menu-item" onclick="toggleSubmenu('schedule')">
                        <div class="icon-box icon-bg-purple">
                            <i class='bx bxs-calendar icon-purple'></i>
                        </div>
                        <span class="font-medium">Shift & Duty Scheduling</span>
                        <svg class="dropdown-arrow menu-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                        </svg>
                    </div>
                    <div id="schedule" class="submenu">
                        <a href="../schedule/view_shifts.php" class="submenu-item">View Shifts</a>
                        <a href="../schedule/confirm_availability.php" class="submenu-item">Confirm Availability</a>
                        <a href="../schedule/request_change.php" class="submenu-item">Request Change</a>
                        <a href="../schedule/mark_attendance.php" class="submenu-item">Mark Attendance</a>
                    </div>
                    
                    <!-- Training & Certification Logging -->
                    <div class="menu-item" onclick="toggleSubmenu('training')">
                        <div class="icon-box icon-bg-teal">
                            <i class='bx bxs-graduation icon-teal'></i>
                        </div>
                        <span class="font-medium">Training & Certification</span>
                        <svg class="dropdown-arrow menu-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                        </svg>
                    </div>
                    <div id="training" class="submenu">
                        <a href="../training/submit_training.php" class="submenu-item">Submit Training</a>
                        <a href="../training/upload_certificates.php" class="submenu-item">Upload Certificates</a>
                        <a href="../training/request_training.php" class="submenu-item">Request Training</a>
                        <a href="../training/view_events.php" class="submenu-item">View Events</a>
                    </div>
                    
                    <!-- Inspection Logs -->
                    <div class="menu-item" onclick="toggleSubmenu('inspection')">
                        <div class="icon-box icon-bg-cyan">
                            <i class='bx bxs-check-shield icon-cyan'></i>
                        </div>
                        <span class="font-medium">Inspection Logs</span>
                        <svg class="dropdown-arrow menu-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                        </svg>
                    </div>
                    <div id="inspection" class="submenu">
                        <a href="../inspection/conduct_inspections.php" class="submenu-item">Conduct Inspections</a>
                        <a href="../inspection/submit_findings.php" class="submenu-item">Submit Findings</a>
                        <a href="../inspection/upload_photos.php" class="submenu-item">Upload Photos</a>
                        <a href="../inspection/tag_violations.php" class="submenu-item">Tag Violations</a>
                    </div>
                    
                    <!-- Post-Incident Reporting -->
                    <div class="menu-item" onclick="toggleSubmenu('postincident')">
                        <div class="icon-box icon-bg-pink">
                            <i class='bx bxs-file-doc icon-pink'></i>
                        </div>
                        <span class="font-medium">Post-Incident Reporting</span>
                        <svg class="dropdown-arrow menu-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                        </svg>
                    </div>
                    <div id="postincident" class="submenu">
                        <a href="../postincident/upload_reports.php" class="submenu-item">Upload Reports</a>
                        <a href="../postincident/add_notes.php" class="submenu-item">Add Notes</a>
                        <a href="../postincident/attach_equipment.php" class="submenu-item">Attach Equipment</a>
                        <a href="../postincident/mark_completed.php" class="submenu-item">Mark Completed</a>
                    </div>
                </div>
                
                <p class="menu-title" style="margin-top: 32px;">GENERAL</p>
                
                <div class="menu-items">
                    <a href="../settings.php" class="menu-item">
                        <div class="icon-box icon-bg-indigo">
                            <i class='bx bxs-cog icon-indigo'></i>
                        </div>
                        <span class="font-medium">Settings</span>
                    </a>
                    
                    <a href="profile.php" class="menu-item active">
                        <div class="icon-box icon-bg-orange">
                            <i class='bx bxs-user icon-orange'></i>
                        </div>
                        <span class="font-medium">Profile</span>
                    </a>
                    
                    <a href="../includes/logout.php" class="menu-item">
                        <div class="icon-box icon-bg-red">
                            <i class='bx bx-log-out icon-red'></i>
                        </div>
                        <span class="font-medium">Logout</span>
                    </a>
                </div>
            </div>
        </div>
        
        <!-- Main Content -->
        <div class="main-content">
            <!-- Header -->
            <div class="header">
                <div class="header-content">
                    <div class="search-container">
                        <div class="search-box">
                            <svg class="search-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path>
                            </svg>
                            <input type="text" placeholder="Search..." class="search-input" id="search-input">
                            <kbd class="search-shortcut">/</kbd>
                        </div>
                    </div>
                    
                    <div class="header-actions">
                        <button class="fullscreen-button" id="fullscreen-button">
                            <i class='bx bx-fullscreen'></i>
                            <span>Full Screen</span>
                        </button>
                        <button class="theme-toggle" id="theme-toggle">
                            <i class='bx bx-moon'></i>
                            <span>Dark Mode</span>
                        </button>
                        <div class="time-display" id="time-display">
                            <i class='bx bx-time time-icon'></i>
                            <span id="current-time">Loading...</span>
                        </div>
                        <div class="user-profile" id="user-profile">
                            <?php if ($avatar): ?>
                                <img src="uploads/avatars/<?php echo $avatar; ?>" alt="User" class="user-avatar">
                            <?php else: ?>
                                <div class="user-avatar" style="background: linear-gradient(135deg, var(--primary-color), var(--secondary-color)); color: white; display: flex; align-items: center; justify-content: center; font-weight: bold; border-radius: 50%; width: 40px; height: 40px;">
                                    <?php echo strtoupper(substr($full_name, 0, 1)); ?>
                                </div>
                            <?php endif; ?>
                            <div class="user-info">
                                <p class="user-name"><?php echo $full_name; ?></p>
                                <p class="user-email"><?php echo $role; ?></p>
                            </div>
                            <div class="user-profile-dropdown" id="user-profile-dropdown">
                                <a href="../settings.php" class="user-profile-dropdown-item settings">
                                    <i class='bx bxs-cog'></i>
                                    <span>Settings</span>
                                </a>
                                <a href="profile.php" class="user-profile-dropdown-item profile">
                                    <i class='bx bxs-user'></i>
                                    <span>Profile</span>
                                </a>
                                <a href="../includes/logout.php" class="user-profile-dropdown-item logout">
                                    <i class='bx bx-log-out'></i>
                                    <span>Logout</span>
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Dashboard Content -->
            <div class="dashboard-content">
                <div class="dashboard-header">
                    <div>
                        <h1 class="dashboard-title">Profile Settings</h1>
                        <p class="dashboard-subtitle">Manage your account information and security settings</p>
                    </div>
                    <div class="dashboard-actions">
                        <button class="primary-button" id="refresh-button">
                            <i class='bx bx-refresh'></i>
                            Refresh
                        </button>
                        
                    </div>
                </div>
                
                <!-- Profile Section -->
                <div class="profile-container">
                    <div class="profile-content">
                        <div class="profile-sidebar">
                            <div class="profile-card">
                                <div class="profile-avatar-container">
                                    <div class="profile-avatar" id="profile-avatar">
                                        <?php if ($avatar): ?>
                                            <img src="uploads/avatars/<?php echo $avatar; ?>" alt="<?php echo $full_name; ?>">
                                        <?php else: ?>
                                            <?php echo strtoupper(substr($full_name, 0, 1)); ?>
                                        <?php endif; ?>
                                        <div class="avatar-edit" id="avatar-edit-button">
                                            <i class='bx bx-camera'></i>
                                        </div>
                                    </div>
                                    <div class="profile-name"><?php echo $full_name; ?></div>
                                    <div class="profile-role"><?php echo $role; ?></div>
                                    <div class="profile-stats">
                                        <div class="stat-item">
                                            <div class="stat-value">12</div>
                                            <div class="stat-label">Incidents</div>
                                        </div>
                                        <div class="stat-item">
                                            <div class="stat-value">5</div>
                                            <div class="stat-label">Trainings</div>
                                        </div>
                                        <div class="stat-item">
                                            <div class="stat-value">98%</div>
                                            <div class="stat-label">Activity</div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="profile-card">
                                <div class="profile-tabs">
                                    <div class="profile-tab active" data-tab="account">
                                        <i class='bx bx-user'></i>
                                        <span>Account Information</span>
                                    </div>
                                    <div class="profile-tab" data-tab="security">
                                        <i class='bx bx-shield'></i>
                                        <span>Security</span>
                                    </div>
                                    <div class="profile-tab" data-tab="additional">
                                        <i class='bx bx-info-circle'></i>
                                        <span>Additional Information</span>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="profile-main">
                            <!-- Account Information Tab -->
                            <div class="profile-card tab-content active" id="account-tab">
                                <h2 style="margin-bottom: 20px; color: var(--text-color);">Account Information</h2>
                                <form id="account-form">
                                    <div class="form-row">
                                        <div class="form-group">
                                            <label class="form-label">First Name</label>
                                            <input type="text" class="form-input" value="<?php echo $first_name; ?>" disabled>
                                        </div>
                                        <div class="form-group">
                                            <label class="form-label">Last Name</label>
                                            <input type="text" class="form-input" value="<?php echo $last_name; ?>" disabled>
                                        </div>
                                    </div>
                                    <div class="form-group">
                                        <label class="form-label">Middle Name</label>
                                        <input type="text" class="form-input" value="<?php echo $middle_name; ?>" disabled>
                                    </div>
                                    <div class="form-group">
                                        <label class="form-label">Username</label>
                                        <input type="text" class="form-input" value="<?php echo $username; ?>" disabled>
                                    </div>
                                    <div class="form-group">
                                        <label class="form-label">Email Address</label>
                                        <input type="email" class="form-input" value="<?php echo $email; ?>" disabled>
                                    </div>
                                    <div class="form-group">
                                        <label class="form-label">Contact Number</label>
                                        <input type="text" class="form-input" value="<?php echo $contact; ?>" disabled>
                                    </div>
                                    <div class="form-group">
                                        <label class="form-label">Date of Birth</label>
                                        <input type="text" class="form-input" value="<?php echo $date_of_birth; ?>" disabled>
                                    </div>
                                    <div class="form-group">
                                        <label class="form-label">Address</label>
                                        <textarea class="form-input" rows="3" disabled><?php echo $address; ?></textarea>
                                    </div>
                                    <div class="form-actions">
                                        <button type="button" class="request-change-button" data-field="first_name" data-value="<?php echo $first_name; ?>">Request Change</button>
                                    </div>
                                </form>
                            </div>
                            
                            <!-- Security Tab -->
                            <div class="profile-card tab-content" id="security-tab">
                                <h2 style="margin-bottom: 20px; color: var(--text-color);">Security Settings</h2>
                                <div class="security-item">
                                    <div class="security-info">
                                        <div class="security-title">Password</div>
                                        <div class="security-description">Last changed 3 months ago</div>
                                    </div>
                                    <button class="change-button" id="change-password-button">Change</button>
                                </div>
                                <div class="security-item">
                                    <div class="security-info">
                                        <div class="security-title">Two-Factor Authentication</div>
                                        <div class="security-description">Add an extra layer of security to your account</div>
                                    </div>
                                    <label class="toggle-switch">
                                        <input type="checkbox" id="two-factor-toggle">
                                        <span class="toggle-slider"></span>
                                    </label>
                                </div>
                                <div class="security-item">
                                    <div class="security-info">
                                        <div class="security-title">Login Notifications</div>
                                        <div class="security-description">Get notified when someone logs into your account</div>
                                    </div>
                                    <label class="toggle-switch">
                                        <input type="checkbox" id="login-notifications-toggle" checked>
                                        <span class="toggle-slider"></span>
                                    </label>
                                </div>
                                <div class="security-item">
                                    <div class="security-info">
                                        <div class="security-title">Active Sessions</div>
                                        <div class="security-description">Manage your active login sessions</div>
                                    </div>
                                    <button class="change-button" id="view-sessions-button">View</button>
                                </div>
                            </div>
                            
                            <!-- Additional Information Tab -->
                            <div class="profile-card tab-content" id="additional-tab">
                                <h2 style="margin-bottom: 20px; color: var(--text-color);">Additional Information</h2>
                                <div class="info-item">
                                    <div class="info-label">Member Since</div>
                                    <div class="info-value">November 2025</div>
                                </div>
                                <div class="info-item">
                                    <div class="info-label">Last Login</div>
                                    <div class="info-value">Today, 10:30 AM</div>
                                </div>
                                <div class="info-item">
                                    <div class="info-label">Account Status</div>
                                    <div class="info-value">Active</div>
                                </div>
                                <div class="info-item">
                                    <div class="info-label">Role</div>
                                    <div class="info-value"><?php echo $role; ?></div>
                                </div>
                                <div class="info-item">
                                    <div class="info-label">Department</div>
                                    <div class="info-value">Fire & Rescue Services</div>
                                </div>
                                <div class="info-item">
                                    <div class="info-label">Emergency Contact</div>
                                    <div class="info-value">Not set</div>
                                </div>
                                <div class="form-actions">
                                    <button type="button" class="request-change-button" data-field="emergency_contact" data-value="Not set">Update Information</button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        let cropper = null;
        let currentAvatarFile = null;

        // Show Notification
        function showNotification(type, title, message) {
            const container = document.getElementById('notification-container');
            const notification = document.createElement('div');
            notification.className = `notification notification-${type}`;
            
            let icon = 'bx-info-circle';
            if (type === 'success') icon = 'bx-check-circle';
            if (type === 'error') icon = 'bx-error';
            if (type === 'warning') icon = 'bx-error-circle';
            
            notification.innerHTML = `
                <i class='bx ${icon} notification-icon'></i>
                <div class="notification-content">
                    <div class="notification-title">${title}</div>
                    <div class="notification-message">${message}</div>
                </div>
                <button class="notification-close">&times;</button>
            `;
            
            container.appendChild(notification);
            
            // Add close event
            notification.querySelector('.notification-close').addEventListener('click', function() {
                notification.classList.remove('show');
                setTimeout(() => {
                    container.removeChild(notification);
                }, 300);
            });
            
            // Show notification
            setTimeout(() => {
                notification.classList.add('show');
            }, 100);
            
            // Auto remove after 5 seconds
            setTimeout(() => {
                if (notification.parentNode) {
                    notification.classList.remove('show');
                    setTimeout(() => {
                        if (notification.parentNode) {
                            container.removeChild(notification);
                        }
                    }, 300);
                }
            }, 5000);
        }
        
        // Toggle Submenu
        function toggleSubmenu(id) {
            const submenu = document.getElementById(id);
            const arrow = submenu.previousElementSibling.querySelector('.dropdown-arrow');
            
            submenu.classList.toggle('active');
            if (arrow) {
                arrow.classList.toggle('rotated');
            }
        }
        
        // Update Time
        function updateTime() {
            const now = new Date();
            const utc = now.getTime() + (now.getTimezoneOffset() * 60000);
            const gmt8 = new Date(utc + (8 * 3600000));
            
            const hours = gmt8.getHours().toString().padStart(2, '0');
            const minutes = gmt8.getMinutes().toString().padStart(2, '0');
            const seconds = gmt8.getSeconds().toString().padStart(2, '0');
            
            const timeString = `${hours}:${minutes}:${seconds} UTC+8`;
            document.getElementById('current-time').textContent = timeString;
        }
        
        // Toggle Fullscreen
        function toggleFullscreen() {
            if (!document.fullscreenElement) {
                document.documentElement.requestFullscreen().catch(err => {
                    console.log(`Error attempting to enable fullscreen: ${err.message}`);
                });
            } else {
                if (document.exitFullscreen) {
                    document.exitFullscreen();
                }
            }
        }

        // Check password strength
        function checkPasswordStrength(password) {
            let strength = 0;
            const strengthBar = document.getElementById('password-strength');
            
            if (password.length >= 8) strength++;
            if (password.match(/[a-z]/) && password.match(/[A-Z]/)) strength++;
            if (password.match(/\d/)) strength++;
            if (password.match(/[^a-zA-Z\d]/)) strength++;
            
            strengthBar.className = 'password-strength';
            if (password.length === 0) {
                strengthBar.style.width = '0';
            } else if (strength <= 2) {
                strengthBar.className += ' password-weak';
            } else if (strength === 3) {
                strengthBar.className += ' password-medium';
            } else {
                strengthBar.className += ' password-strong';
            }
        }
        
        // Initialize Event Listeners
        function initEventListeners() {
            // Theme toggle
            const themeToggle = document.getElementById('theme-toggle');
            const themeIcon = themeToggle.querySelector('i');
            const themeText = themeToggle.querySelector('span');
            
            themeToggle.addEventListener('click', function() {
                document.body.classList.toggle('dark-mode');
                
                if (document.body.classList.contains('dark-mode')) {
                    themeIcon.className = 'bx bx-sun';
                    themeText.textContent = 'Light Mode';
                } else {
                    themeIcon.className = 'bx bx-moon';
                    themeText.textContent = 'Dark Mode';
                }
            });
            
            // Fullscreen toggle
            document.getElementById('fullscreen-button').addEventListener('click', toggleFullscreen);
            
            // Refresh button
            document.getElementById('refresh-button').addEventListener('click', function() {
                showNotification('info', 'Refreshing', 'Profile information updated');
                setTimeout(() => {
                    location.reload();
                }, 1000);
            });
            
            // Profile tabs
            document.querySelectorAll('.profile-tab').forEach(tab => {
                tab.addEventListener('click', function() {
                    // Remove active class from all tabs
                    document.querySelectorAll('.profile-tab').forEach(t => {
                        t.classList.remove('active');
                    });
                    
                    // Add active class to clicked tab
                    this.classList.add('active');
                    
                    // Hide all tab contents
                    document.querySelectorAll('.tab-content').forEach(content => {
                        content.classList.remove('active');
                    });
                    
                    // Show corresponding tab content
                    const tabId = this.getAttribute('data-tab');
                    document.getElementById(`${tabId}-tab`).classList.add('active');
                });
            });
            
            // Avatar edit button
            document.getElementById('avatar-edit-button').addEventListener('click', function() {
                document.getElementById('avatar-modal').classList.add('active');
            });
            
            // Avatar modal close
            document.getElementById('avatar-modal-close').addEventListener('click', function() {
                document.getElementById('avatar-modal').classList.remove('active');
                if (cropper) {
                    cropper.destroy();
                    cropper = null;
                }
            });
            
            document.getElementById('avatar-cancel').addEventListener('click', function() {
                document.getElementById('avatar-modal').classList.remove('active');
                if (cropper) {
                    cropper.destroy();
                    cropper = null;
                }
            });
            
            // Avatar upload
            document.getElementById('avatar-upload').addEventListener('click', function() {
                document.getElementById('avatar-file-input').click();
            });
            
            document.getElementById('avatar-file-input').addEventListener('change', function(e) {
                if (e.target.files && e.target.files[0]) {
                    currentAvatarFile = e.target.files[0];
                    
                    // Check file size (max 5MB)
                    if (currentAvatarFile.size > 5 * 1024 * 1024) {
                        showNotification('error', 'File Too Large', 'Please select an image smaller than 5MB');
                        return;
                    }
                    
                    const reader = new FileReader();
                    
                    reader.onload = function(event) {
                        // Show crop container
                        document.getElementById('avatar-crop-container').style.display = 'block';
                        document.getElementById('crop-preview').style.display = 'block';
                        
                        // Initialize cropper
                        const image = document.createElement('img');
                        image.id = 'avatar-crop-image';
                        image.src = event.target.result;
                        
                        document.getElementById('avatar-crop-area').innerHTML = '';
                        document.getElementById('avatar-crop-area').appendChild(image);
                        
                        if (cropper) {
                            cropper.destroy();
                        }
                        
                        cropper = new Cropper(image, {
                            aspectRatio: 1,
                            viewMode: 1,
                            guides: true,
                            background: false,
                            autoCropArea: 0.8,
                            responsive: true,
                            checkCrossOrigin: false,
                            preview: '#crop-preview'
                        });
                    };
                    
                    reader.readAsDataURL(currentAvatarFile);
                }
            });
            
            // Avatar save
            document.getElementById('avatar-save').addEventListener('click', function() {
                if (!cropper) {
                    showNotification('error', 'No Image', 'Please upload an image first');
                    return;
                }
                
                // Get cropped canvas
                const canvas = cropper.getCroppedCanvas({
                    width: 300,
                    height: 300,
                    fillColor: '#fff',
                    imageSmoothingEnabled: true,
                    imageSmoothingQuality: 'high'
                });
                
                // Convert to blob and upload
                canvas.toBlob(function(blob) {
                    const formData = new FormData();
                    formData.append('avatar', blob, 'avatar.jpg');
                    formData.append('action', 'upload_avatar');
                    
                    // Show loading state
                    const saveButton = document.getElementById('avatar-save');
                    const originalText = saveButton.innerHTML;
                    saveButton.innerHTML = '<i class="bx bx-loader-circle bx-spin"></i> Uploading...';
                    saveButton.disabled = true;
                    
                    fetch('profile.php', {
                        method: 'POST',
                        body: formData
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            showNotification('success', 'Avatar Updated', 'Your profile picture has been updated successfully');
                            document.getElementById('avatar-modal').classList.remove('active');
                            
                            // Update the avatar everywhere
                            const newAvatarSrc = data.avatar_url + '?t=' + new Date().getTime();
                            document.getElementById('profile-avatar').innerHTML = `
                                <img src="${newAvatarSrc}" alt="<?php echo $full_name; ?>">
                                <div class="avatar-edit" id="avatar-edit-button">
                                    <i class='bx bx-camera'></i>
                                </div>
                            `;
                            
                            // Update header avatar
                            const headerAvatar = document.querySelector('.user-profile .user-avatar');
                            if (headerAvatar && headerAvatar.tagName === 'IMG') {
                                headerAvatar.src = newAvatarSrc;
                            } else if (headerAvatar) {
                                // Replace the placeholder with an image
                                const newHeaderAvatar = document.createElement('img');
                                newHeaderAvatar.src = newAvatarSrc;
                                newHeaderAvatar.alt = 'User';
                                newHeaderAvatar.className = 'user-avatar';
                                headerAvatar.parentNode.replaceChild(newHeaderAvatar, headerAvatar);
                            }
                            
                            // Reattach event listener to the new edit button
                            document.getElementById('avatar-edit-button').addEventListener('click', function() {
                                document.getElementById('avatar-modal').classList.add('active');
                            });
                            
                            if (cropper) {
                                cropper.destroy();
                                cropper = null;
                            }
                        } else {
                            showNotification('error', 'Upload Failed', data.message || 'Failed to upload avatar');
                        }
                    })
                    .catch(error => {
                        console.error('Error:', error);
                        showNotification('error', 'Upload Failed', 'An error occurred while uploading your avatar');
                    })
                    .finally(() => {
                        saveButton.innerHTML = originalText;
                        saveButton.disabled = false;
                    });
                }, 'image/jpeg', 0.9);
            });
            
            // Change request buttons
            document.querySelectorAll('.request-change-button').forEach(button => {
                button.addEventListener('click', function() {
                    const field = this.getAttribute('data-field');
                    const currentValue = this.getAttribute('data-value');
                    
                    document.getElementById('change-field').value = this.textContent.replace('Request Change', '').replace('Update Information', '').trim() || field.replace('_', ' ');
                    document.getElementById('current-value').value = currentValue;
                    document.getElementById('requested-value').value = '';
                    document.getElementById('change-reason').value = '';
                    
                    document.getElementById('change-request-modal').classList.add('active');
                });
            });
            
            // Change request modal
            document.getElementById('change-request-modal-close').addEventListener('click', function() {
                document.getElementById('change-request-modal').classList.remove('active');
            });
            
            document.getElementById('change-request-cancel').addEventListener('click', function() {
                document.getElementById('change-request-modal').classList.remove('active');
            });
            
            document.getElementById('change-request-submit').addEventListener('click', function() {
                const requestedValue = document.getElementById('requested-value').value;
                const reason = document.getElementById('change-reason').value;
                
                if (!requestedValue || !reason) {
                    showNotification('error', 'Missing Information', 'Please fill in all fields');
                    return;
                }
                
                // In a real implementation, you would submit the change request to the server
                showNotification('success', 'Change Request Submitted', 'Your request has been sent for approval');
                document.getElementById('change-request-modal').classList.remove('active');
            });
            
            // Change password button
            document.getElementById('change-password-button').addEventListener('click', function() {
                document.getElementById('change-password-modal').classList.add('active');
                document.getElementById('current-password').value = '';
                document.getElementById('new-password').value = '';
                document.getElementById('confirm-password').value = '';
                document.getElementById('password-strength').style.width = '0';
            });
            
            // Change password modal
            document.getElementById('change-password-modal-close').addEventListener('click', function() {
                document.getElementById('change-password-modal').classList.remove('active');
            });
            
            document.getElementById('change-password-cancel').addEventListener('click', function() {
                document.getElementById('change-password-modal').classList.remove('active');
            });
            
            // Password strength checker
            document.getElementById('new-password').addEventListener('input', function() {
                checkPasswordStrength(this.value);
            });
            
            // Change password submit
            document.getElementById('change-password-submit').addEventListener('click', function() {
                const currentPassword = document.getElementById('current-password').value;
                const newPassword = document.getElementById('new-password').value;
                const confirmPassword = document.getElementById('confirm-password').value;
                
                if (!currentPassword || !newPassword || !confirmPassword) {
                    showNotification('error', 'Missing Information', 'Please fill in all fields');
                    return;
                }
                
                if (newPassword !== confirmPassword) {
                    showNotification('error', 'Password Mismatch', 'New passwords do not match');
                    return;
                }
                
                if (newPassword.length < 8) {
                    showNotification('error', 'Weak Password', 'Password must be at least 8 characters long');
                    return;
                }
                
                // Submit password change
                const formData = new FormData();
                formData.append('action', 'change_password');
                formData.append('current_password', currentPassword);
                formData.append('new_password', newPassword);
                formData.append('confirm_password', confirmPassword);
                
                const submitButton = document.getElementById('change-password-submit');
                const originalText = submitButton.innerHTML;
                submitButton.innerHTML = '<i class="bx bx-loader-circle bx-spin"></i> Changing...';
                submitButton.disabled = true;
                
                fetch('profile.php', {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        showNotification('success', 'Password Changed', data.message);
                        document.getElementById('change-password-modal').classList.remove('active');
                    } else {
                        showNotification('error', 'Password Change Failed', data.message);
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    showNotification('error', 'Error', 'An error occurred while changing your password');
                })
                .finally(() => {
                    submitButton.innerHTML = originalText;
                    submitButton.disabled = false;
                });
            });
            
            // View sessions button
            document.getElementById('view-sessions-button').addEventListener('click', function() {
                showNotification('info', 'Active Sessions', 'Session management would be implemented here');
            });
            
            // Close modals when clicking outside
            document.addEventListener('click', function(e) {
                if (e.target.classList.contains('modal-overlay')) {
                    document.getElementById('avatar-modal').classList.remove('active');
                    document.getElementById('change-request-modal').classList.remove('active');
                    document.getElementById('change-password-modal').classList.remove('active');
                }
            });
            
            // Escape key to close modals
            document.addEventListener('keydown', function(e) {
                if (e.key === 'Escape') {
                    document.getElementById('avatar-modal').classList.remove('active');
                    document.getElementById('change-request-modal').classList.remove('active');
                    document.getElementById('change-password-modal').classList.remove('active');
                }
            });
            
            // User profile dropdown functionality
            const userProfile = document.getElementById('user-profile');
            const userProfileDropdown = document.getElementById('user-profile-dropdown');
            
            userProfile.addEventListener('click', function(e) {
                e.stopPropagation();
                userProfileDropdown.classList.toggle('active');
            });
            
            // Close dropdown when clicking elsewhere
            document.addEventListener('click', function() {
                userProfileDropdown.classList.remove('active');
            });
        }
        
        // DOM Content Loaded
        document.addEventListener('DOMContentLoaded', function() {
            const animationOverlay = document.getElementById('dashboard-animation');
            const animationProgress = document.getElementById('animation-progress');
            const animationText = document.getElementById('animation-text');
            const animationLogo = document.querySelector('.animation-logo');
            
            // Show logo and text immediately
            setTimeout(() => {
                animationLogo.style.opacity = '1';
                animationLogo.style.transform = 'translateY(0)';
            }, 100);
            
            setTimeout(() => {
                animationText.style.opacity = '1';
            }, 300);
            
            // Faster loading - 1 second only
            setTimeout(() => {
                animationProgress.style.width = '100%';
            }, 100);
            
            setTimeout(() => {
                animationOverlay.style.opacity = '0';
                setTimeout(() => {
                    animationOverlay.style.display = 'none';
                }, 300);
            }, 1000);
            
            // Initialize event listeners
            initEventListeners();
            
            // Show welcome notification
            showNotification('success', 'Profile Loaded', 'Your profile information is ready');
        });

        // Initialize time and set interval
        updateTime();
        setInterval(updateTime, 1000);
    </script>
</body>
</html>