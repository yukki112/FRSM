<?php
session_start();
require_once '../../config/db_connection.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit();
}

$user_id = $_SESSION['user_id'];
$query = "SELECT first_name, middle_name, last_name, role FROM users WHERE id = ?";
$stmt = $pdo->prepare($query);
$stmt->execute([$user_id]);
$user = $stmt->fetch();

if (!$user || $user['role'] !== 'EMPLOYEE') {
    header("Location: ../unauthorized.php");
    exit();
}

$first_name = htmlspecialchars($user['first_name']);
$middle_name = htmlspecialchars($user['middle_name']);
$last_name = htmlspecialchars($user['last_name']);
$role = htmlspecialchars($user['role']);

$full_name = $first_name;
if (!empty($middle_name)) {
    $full_name .= " " . $middle_name;
}
$full_name .= " " . $last_name;

// Get current registration status
$status_query = "SELECT vs.status, u.first_name, u.last_name, vs.updated_at 
                 FROM volunteer_registration_status vs 
                 LEFT JOIN users u ON vs.updated_by = u.id 
                 ORDER BY vs.updated_at DESC LIMIT 1";
$status_stmt = $pdo->query($status_query);
$current_status = $status_stmt->fetch();

if (!$current_status) {
    $current_status = ['status' => 'closed', 'first_name' => null, 'last_name' => null, 'updated_at' => null];
}

// Handle status toggle with password confirmation
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $password = $_POST['password'] ?? '';
    
    if (in_array($action, ['open', 'closed'])) {
        // Verify password
        $password_query = "SELECT password FROM users WHERE id = ?";
        $password_stmt = $pdo->prepare($password_query);
        $password_stmt->execute([$user_id]);
        $user_data = $password_stmt->fetch();
        
        if ($user_data && password_verify($password, $user_data['password'])) {
            $update_query = "UPDATE volunteer_registration_status SET status = ?, updated_by = ?";
            $update_stmt = $pdo->prepare($update_query);
            $update_stmt->execute([$action, $user_id]);
            
            $_SESSION['success_message'] = "Volunteer registration has been " . ($action === 'open' ? 'opened' : 'closed');
            header("Location: toggle_volunteer_registration.php");
            exit();
        } else {
            $_SESSION['error_message'] = "Incorrect password. Please try again.";
            header("Location: toggle_volunteer_registration.php");
            exit();
        }
    }
}

$stmt = null;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Toggle Volunteer Registration - FRSM</title>
    <link href='https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css' rel='stylesheet'>
    <link rel="icon" type="image/png" sizes="32x32" href="../img/frsm-logo.png">
    <link rel="stylesheet" href="../../css/dashboard.css">
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
            --border-color: #1e293b;
            --card-bg: #1e293b;
            --sidebar-bg: #0f172a;
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            font-size: 14px;
            line-height: 1.5;
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

        .control-center {
            display: grid;
            grid-template-columns: 1fr 400px;
            gap: 30px;
            padding: 0 40px;
            margin-bottom: 40px;
        }

        .main-controls {
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: 25px;
            padding: 40px;
            position: relative;
            overflow: hidden;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
        }

        .main-controls::before {
            content: '';
            position: absolute;
            top: -100px;
            right: -100px;
            width: 300px;
            height: 300px;
            background: radial-gradient(circle, rgba(220, 38, 38, 0.1) 0%, transparent 70%);
            border-radius: 50%;
        }

        .status-display {
            text-align: center;
            margin-bottom: 40px;
        }

        .status-icon {
            width: 120px;
            height: 120px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 25px;
            font-size: 3.5rem;
            box-shadow: 0 15px 35px rgba(0, 0, 0, 0.15);
            border: 4px solid white;
        }

        .status-open .status-icon {
            background: linear-gradient(135deg, #10b981, #059669);
            color: white;
        }

        .status-closed .status-icon {
            background: linear-gradient(135deg, #dc2626, #991b1b);
            color: white;
        }

        .status-text {
            font-size: 1.8rem;
            font-weight: 800;
            margin-bottom: 10px;
            color: var(--text-color);
        }

        .status-description {
            color: var(--text-light);
            font-size: 1.1rem;
            line-height: 1.6;
        }

        .toggle-section {
            background: #f8fafc;
            border-radius: 20px;
            padding: 30px;
            margin: 30px 0;
            border: 2px solid #e5e7eb;
        }

        .dark-mode .toggle-section {
            background: #1e293b;
            border-color: #475569;
        }

        .toggle-title {
            text-align: center;
            font-size: 1.4rem;
            font-weight: 700;
            margin-bottom: 25px;
            color: var(--text-color);
        }

        .toggle-buttons {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
        }

        .btn-toggle {
            padding: 20px;
            border: none;
            border-radius: 15px;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 12px;
            font-size: 1.1rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.15);
        }

        .btn-open {
            background: linear-gradient(135deg, #10b981, #059669);
            color: white;
        }

        .btn-open:hover {
            background: linear-gradient(135deg, #059669, #047857);
            transform: translateY(-3px);
            box-shadow: 0 20px 40px rgba(16, 185, 129, 0.4);
        }

        .btn-close {
            background: linear-gradient(135deg, #dc2626, #991b1b);
            color: white;
        }

        .btn-close:hover {
            background: linear-gradient(135deg, #991b1b, #7f1d1d);
            transform: translateY(-3px);
            box-shadow: 0 20px 40px rgba(220, 38, 38, 0.4);
        }

        .btn-toggle:disabled {
            opacity: 0.6;
            cursor: not-allowed;
            transform: none !important;
        }

        .side-panel {
            display: flex;
            flex-direction: column;
            gap: 25px;
        }

        .info-card {
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: 20px;
            padding: 30px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
        }

        .info-card h3 {
            color: var(--text-color);
            margin-bottom: 20px;
            font-size: 1.3rem;
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .info-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 12px 0;
            border-bottom: 1px solid #e5e7eb;
        }

        .dark-mode .info-item {
            border-bottom-color: #334155;
        }

        .info-item:last-child {
            border-bottom: none;
        }

        .info-label {
            color: var(--text-light);
            font-weight: 500;
        }

        .info-value {
            color: var(--text-color);
            font-weight: 600;
        }

        .impact-preview {
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: 25px;
            padding: 40px;
            margin: 0 40px 40px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
        }

        .impact-title {
            text-align: center;
            font-size: 2rem;
            font-weight: 800;
            margin-bottom: 40px;
            color: var(--text-color);
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }

        .impact-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 25px;
        }

        .impact-card {
            background: linear-gradient(135deg, #f8fafc, #f1f5f9);
            border-radius: 20px;
            padding: 30px;
            text-align: center;
            border: 1px solid #e5e7eb;
            border-left: 5px solid var(--primary-color);
            transition: all 0.3s ease;
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.08);
        }

        .dark-mode .impact-card {
            background: linear-gradient(135deg, #1e293b, #334155);
        }

        .impact-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 15px 40px rgba(0, 0, 0, 0.15);
        }

        .impact-icon {
            width: 70px;
            height: 70px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 20px;
            font-size: 2rem;
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            color: white;
        }

        .impact-card h4 {
            color: var(--text-color);
            margin-bottom: 15px;
            font-size: 1.3rem;
            font-weight: 700;
        }

        .impact-card p {
            color: var(--text-light);
            line-height: 1.6;
        }

        .alert-message {
            background: linear-gradient(135deg, #d1fae5, #a7f3d0);
            color: #065f46;
            padding: 20px 25px;
            border-radius: 15px;
            margin: 0 40px 25px;
            border: 2px solid #6ee7b7;
            display: flex;
            align-items: center;
            gap: 15px;
            font-weight: 600;
            box-shadow: 0 10px 30px rgba(16, 185, 129, 0.2);
        }

        .dark-mode .alert-message {
            background: linear-gradient(135deg, #064e3b, #065f46);
            color: #d1fae5;
            border-color: #10b981;
        }

        .alert-message.error {
            background: linear-gradient(135deg, #fee2e2, #fecaca);
            color: #7f1d1d;
            border-color: #fca5a5;
        }

        .dark-mode .alert-message.error {
            background: linear-gradient(135deg, #7f1d1d, #991b1b);
            color: #fecaca;
            border-color: #ef4444;
        }

        .quick-stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin: 25px 0;
        }

        .stat-item {
            background: var(--card-bg);
            padding: 20px;
            border-radius: 15px;
            text-align: center;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
            border: 1px solid var(--border-color);
        }

        .stat-number {
            font-size: 2rem;
            font-weight: 800;
            color: var(--primary-color);
            margin-bottom: 5px;
        }

        .stat-label {
            color: var(--text-light);
            font-size: 0.9rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .modal-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 9999;
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
            box-shadow: 0 25px 50px rgba(0, 0, 0, 0.25);
            width: 90%;
            max-width: 500px;
            transform: translateY(20px);
            transition: transform 0.3s ease;
        }

        .modal-overlay.active .modal {
            transform: translateY(0);
        }

        .modal-header {
            padding: 25px 30px 20px;
            border-bottom: 1px solid var(--border-color);
            display: flex;
            align-items: center;
            gap: 15px;
        }

        .modal-icon {
            width: 50px;
            height: 50px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            color: white;
        }

        .modal-icon.open {
            background: linear-gradient(135deg, #10b981, #059669);
        }

        .modal-icon.close {
            background: linear-gradient(135deg, #dc2626, #991b1b);
        }

        .modal-title {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--text-color);
        }

        .modal-body {
            padding: 25px 30px;
        }

        .modal-message {
            color: var(--text-color);
            margin-bottom: 20px;
            line-height: 1.6;
        }

        .password-input {
            width: 100%;
            padding: 15px;
            border: 2px solid #e5e7eb;
            border-radius: 12px;
            font-size: 1rem;
            transition: all 0.3s ease;
            background: var(--background-color);
            color: var(--text-color);
        }

        .dark-mode .password-input {
            border-color: #475569;
            background: #1e293b;
        }

        .password-input:focus {
            outline: none;
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(220, 38, 38, 0.1);
        }

        .modal-footer {
            padding: 20px 30px 25px;
            display: flex;
            gap: 15px;
            justify-content: flex-end;
            border-top: 1px solid var(--border-color);
        }

        .btn-modal {
            padding: 12px 24px;
            border: none;
            border-radius: 12px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .btn-modal-cancel {
            background: #e5e7eb;
            color: var(--text-color);
        }

        .dark-mode .btn-modal-cancel {
            background: #475569;
        }

        .btn-modal-cancel:hover {
            background: #d1d5db;
        }

        .dark-mode .btn-modal-cancel:hover {
            background: #4b5563;
        }

        .btn-modal-confirm {
            background: var(--primary-color);
            color: white;
        }

        .btn-modal-confirm:hover {
            background: var(--primary-dark);
        }

        .btn-modal-confirm.open {
            background: linear-gradient(135deg, #10b981, #059669);
        }

        .btn-modal-confirm.open:hover {
            background: linear-gradient(135deg, #059669, #047857);
        }

        .btn-modal-confirm.close {
            background: linear-gradient(135deg, #dc2626, #991b1b);
        }

        .btn-modal-confirm.close:hover {
            background: linear-gradient(135deg, #991b1b, #7f1d1d);
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

        .user-profile {
            position: relative;
            cursor: pointer;
        }

        .user-profile-dropdown {
            position: absolute;
            top: 100%;
            right: 0;
            margin-top: 8px;
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
            border-radius: 12px;
            padding: 8px;
            min-width: 200px;
            z-index: 1001;
            opacity: 0;
            visibility: hidden;
            transform: translateY(-10px);
            transition: all 0.3s ease;
        }

        .user-profile-dropdown.show {
            opacity: 1;
            visibility: visible;
            transform: translateY(0);
        }

        .dropdown-item {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 12px 16px;
            border-radius: 8px;
            color: var(--text-color);
            text-decoration: none;
            transition: all 0.3s ease;
        }

        .dropdown-item:hover {
            background: rgba(220, 38, 38, 0.1);
        }

        .dropdown-item i {
            font-size: 18px;
            color: var(--primary-color);
        }

        .dropdown-divider {
            height: 1px;
            background: var(--border-color);
            margin: 8px 0;
        }

        .notification-bell {
            position: relative;
        }

        .notification-badge {
            position: absolute;
            top: -5px;
            right: -5px;
            background: var(--danger);
            color: white;
            border-radius: 50%;
            width: 18px;
            height: 18px;
            font-size: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 600;
        }

        .notification-dropdown {
            position: absolute;
            top: 100%;
            right: 0;
            margin-top: 8px;
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
            border-radius: 12px;
            width: 320px;
            max-height: 400px;
            overflow-y: auto;
            z-index: 1001;
            opacity: 0;
            visibility: hidden;
            transform: translateY(-10px);
            transition: all 0.3s ease;
        }

        .notification-dropdown.show {
            opacity: 1;
            visibility: visible;
            transform: translateY(0);
        }

        .notification-header {
            padding: 16px;
            border-bottom: 1px solid var(--border-color);
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .notification-title {
            font-size: 16px;
            font-weight: 600;
        }

        .notification-clear {
            background: none;
            border: none;
            color: var(--primary-color);
            font-size: 14px;
            cursor: pointer;
        }

        .notification-list {
            padding: 8px 0;
        }

        .notification-item {
            display: flex;
            align-items: flex-start;
            gap: 12px;
            padding: 12px 16px;
            transition: all 0.3s ease;
            cursor: pointer;
        }

        .notification-item:hover {
            background: rgba(220, 38, 38, 0.05);
        }

        .notification-item.unread {
            background: rgba(59, 130, 246, 0.05);
        }

        .notification-item-icon {
            font-size: 18px;
            margin-top: 2px;
            flex-shrink: 0;
        }

        .notification-item-content {
            flex: 1;
        }

        .notification-item-title {
            font-size: 14px;
            font-weight: 500;
            margin-bottom: 4px;
        }

        .notification-item-message {
            font-size: 13px;
            color: var(--text-light);
            margin-bottom: 4px;
        }

        .notification-item-time {
            font-size: 12px;
            color: var(--text-light);
        }

        .notification-empty {
            padding: 40px 20px;
            text-align: center;
            color: var(--text-light);
        }

        .notification-empty i {
            font-size: 32px;
            margin-bottom: 12px;
            opacity: 0.5;
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
            background: #e5e7eb;
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

        .status-badge {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 8px 16px;
            border-radius: 25px;
            font-weight: 600;
            font-size: 0.9rem;
            margin-left: 15px;
        }

        .status-badge.open {
            background: #d1fae5;
            color: #065f46;
        }

        .dark-mode .status-badge.open {
            background: #064e3b;
            color: #a7f3d0;
        }

        .status-badge.closed {
            background: #fee2e2;
            color: #dc2626;
        }

        .dark-mode .status-badge.closed {
            background: #7f1d1d;
            color: #fecaca;
        }

        @media (max-width: 1024px) {
            .control-center {
                grid-template-columns: 1fr;
            }
            
            .side-panel {
                order: -1;
            }
        }

        @media (max-width: 768px) {
            .dashboard-header {
                padding: 40px 25px 30px;
                border-radius: 0 0 20px 20px;
            }
            
            .dashboard-title {
                font-size: 2.2rem;
            }
            
            .control-center {
                padding: 0 25px;
            }
            
            .main-controls {
                padding: 30px 25px;
            }
            
            .toggle-buttons {
                grid-template-columns: 1fr;
            }
            
            .impact-preview {
                margin: 0 25px 30px;
                padding: 30px 25px;
            }
            
            .impact-grid {
                grid-template-columns: 1fr;
            }
        }

        @keyframes pulse {
            0% { transform: scale(1); }
            50% { transform: scale(1.05); }
            100% { transform: scale(1); }
        }

        .status-icon.pulse {
            animation: pulse 2s infinite;
        }

        .btn-toggle:active {
            transform: scale(0.98);
        }
    </style>
</head>
<body>
    <div class="dashboard-animation" id="dashboard-animation">
        <div class="animation-logo">
            <div class="animation-logo-icon">
                <img src="../../img/frsm-logo.png" alt="Fire & Rescue Logo" style="width: 70px; height: 75px;">
            </div>
            <div class="animation-logo-text">Fire & Rescue</div>
        </div>
        <div class="animation-progress">
            <div class="animation-progress-fill" id="animation-progress"></div>
        </div>
        <div class="animation-text" id="animation-text">Loading Dashboard...</div>
    </div>
    
    <!-- Notification Container -->
    <div class="notification-container" id="notification-container"></div>
    
    <!-- Confirmation Modal -->
    <div class="modal-overlay" id="confirmation-modal">
        <div class="modal">
            <div class="modal-header">
                <div class="modal-icon" id="modal-icon">
                    <i class='bx bxs-lock'></i>
                </div>
                <h3 class="modal-title" id="modal-title">Confirm Action</h3>
            </div>
            <div class="modal-body">
                <p class="modal-message" id="modal-message">Are you sure you want to perform this action?</p>
                <input type="password" class="password-input" id="password-input" placeholder="Enter your password to confirm" required>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn-modal btn-modal-cancel" id="modal-cancel">Cancel</button>
                <button type="button" class="btn-modal btn-modal-confirm" id="modal-confirm">Confirm</button>
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
                         <a href="../fir/recieve_data.php" class="submenu-item">Receive Data</a>
                        <a href="../fir/manual_reporting.php" class="submenu-item">Manual Reporting</a>
                        <a href="../fir/update_status.php" class="submenu-item">Update Status</a>
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
                   <div id="volunteer" class="submenu active">
                        <a href="review_data.php" class="submenu-item">Review/Aprroved Data Management</a>
                        <a href="approve_applications.php" class="submenu-item">Assign Volunteers</a>
                        <a href="view_availability.php" class="submenu-item">View Availability</a>
                        <a href="remove_volunteers.php" class="submenu-item">Remove Volunteers</a>
                        <a href="toggle_volunteer_registration.php" class="submenu-item active">Open/Close Registration</a>
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
                    
                    <a href="../profile/profile.php" class="menu-item">
                        <div class="icon-box icon-bg-orange">
                            <i class='bx bxs-user icon-orange'></i>
                        </div>
                        <span class="font-medium">Profile</span>
                    </a>
                    
                    <a href="../../includes/logout.php" class="menu-item">
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
                            <input type="text" placeholder="Search incidents, personnel, equipment..." class="search-input">
                            <kbd class="search-shortcut">🔥</kbd>
                        </div>
                    </div>
                    
                    <div class="header-actions">
                        <button class="theme-toggle" id="theme-toggle">
                            <i class='bx bx-moon'></i>
                            <span>Dark Mode</span>
                        </button>
                        <div class="time-display" id="time-display">
                            <i class='bx bx-time time-icon'></i>
                            <span id="current-time">Loading...</span>
                        </div>
                        <button class="header-button">
                            <svg class="header-button-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"></path>
                            </svg>
                        </button>
                        <div class="notification-bell">
                            <button class="header-button" id="notification-bell">
                                <svg class="header-button-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"></path>
                                </svg>
                            </button>
                            <div class="notification-badge" id="notification-count">3</div>
                            <div class="notification-dropdown" id="notification-dropdown">
                                <div class="notification-header">
                                    <h3 class="notification-title">Notifications</h3>
                                    <button class="notification-clear">Clear All</button>
                                </div>
                                <div class="notification-list" id="notification-list">
                                    <div class="notification-item unread">
                                        <i class='bx bxs-user-plus notification-item-icon' style="color: var(--success);"></i>
                                        <div class="notification-item-content">
                                            <div class="notification-item-title">New Volunteer Application</div>
                                            <div class="notification-item-message">Maria Santos submitted a volunteer application</div>
                                            <div class="notification-item-time">5 minutes ago</div>
                                        </div>
                                    </div>
                                    <div class="notification-item unread">
                                        <i class='bx bxs-bell-ring notification-item-icon' style="color: var(--warning);"></i>
                                        <div class="notification-item-content">
                                            <div class="notification-item-title">Training Reminder</div>
                                            <div class="notification-item-message">Basic Firefighting training scheduled for tomorrow</div>
                                            <div class="notification-item-time">1 hour ago</div>
                                        </div>
                                    </div>
                                    <div class="notification-item">
                                        <i class='bx bxs-check-circle notification-item-icon' style="color: var(--success);"></i>
                                        <div class="notification-item-content">
                                            <div class="notification-item-title">Application Approved</div>
                                            <div class="notification-item-message">Carlos Mendoza's application was approved</div>
                                            <div class="notification-item-time">2 hours ago</div>
                                        </div>
                                    </div>
                                    <div class="notification-item">
                                        <i class='bx bxs-error notification-item-icon' style="color: var(--danger);"></i>
                                        <div class="notification-item-content">
                                            <div class="notification-item-title">System Update</div>
                                            <div class="notification-item-message">Scheduled maintenance this weekend</div>
                                            <div class="notification-item-time">Yesterday</div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="user-profile" id="user-profile">
                            <img src="../../img/rei.jfif" alt="User" class="user-avatar">
                            <div class="user-info">
                                <p class="user-name"><?php echo $full_name; ?></p>
                                <p class="user-email"><?php echo $role; ?></p>
                            </div>
                            <div class="user-profile-dropdown" id="user-dropdown">
                                <a href="../profile/profile.php" class="dropdown-item">
                                    <i class='bx bx-user'></i>
                                    <span>Profile</span>
                                </a>
                                <a href="../settings.php" class="dropdown-item">
                                    <i class='bx bx-cog'></i>
                                    <span>Settings</span>
                                </a>
                                <div class="dropdown-divider"></div>
                                <a href="../../includes/logout.php" class="dropdown-item">
                                    <i class='bx bx-log-out'></i>
                                    <span>Logout</span>
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
          <!-- COMPLETELY NEW DASHBOARD CONTENT DESIGN -->
            <div class="dashboard-content">
                <!-- Hero Header -->
                <div class="dashboard-header">
                    <div>
                        <h1 class="dashboard-title">Registration Control Center</h1>
                        <p class="dashboard-subtitle">Manage volunteer application accessibility on the public portal</p>
                    </div>
                </div>

                <!-- Success Message -->
                <?php if (isset($_SESSION['success_message'])): ?>
                    <div class="alert-message">
                        <i class='bx bxs-check-circle'></i>
                        <?php echo $_SESSION['success_message']; unset($_SESSION['success_message']); ?>
                    </div>
                <?php endif; ?>

                <!-- Error Message -->
                <?php if (isset($_SESSION['error_message'])): ?>
                    <div class="alert-message error">
                        <i class='bx bxs-error-circle'></i>
                        <?php echo $_SESSION['error_message']; unset($_SESSION['error_message']); ?>
                    </div>
                <?php endif; ?>

                <!-- Control Center -->
                <div class="control-center">
                    <!-- Main Controls -->
                    <div class="main-controls">
                        <div class="status-display <?php echo $current_status['status'] === 'open' ? 'status-open' : 'status-closed'; ?>">
                            <div class="status-icon <?php echo $current_status['status'] === 'open' ? 'pulse' : ''; ?>">
                                <i class='bx <?php echo $current_status['status'] === 'open' ? 'bxs-lock-open' : 'bxs-lock'; ?>'></i>
                            </div>
                            <div class="status-text">
                                Registration is <?php echo strtoupper($current_status['status']); ?>
                                <span class="status-badge <?php echo $current_status['status']; ?>">
                                    <i class='bx <?php echo $current_status['status'] === 'open' ? 'bxs-check-circle' : 'bxs-x-circle'; ?>'></i>
                                    <?php echo $current_status['status'] === 'open' ? 'ACTIVE' : 'INACTIVE'; ?>
                                </span>
                            </div>
                            <div class="status-description">
                                <?php if ($current_status['status'] === 'open'): ?>
                                    The volunteer application portal is currently accepting new submissions from the public.
                                <?php else: ?>
                                    The volunteer application portal is temporarily closed for new submissions.
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- Quick Stats -->
                        <div class="quick-stats">
                            <div class="stat-item">
                                <div class="stat-number">24/7</div>
                                <div class="stat-label">System Ready</div>
                            </div>
                            <div class="stat-item">
                                <div class="stat-number"><?php echo $current_status['status'] === 'open' ? 'Live' : 'Offline'; ?></div>
                                <div class="stat-label">Portal Status</div>
                            </div>
                            <div class="stat-item">
                                <div class="stat-number">Real-time</div>
                                <div class="stat-label">Updates</div>
                            </div>
                        </div>

                        <!-- Toggle Section -->
                        <div class="toggle-section">
                            <div class="toggle-title">Change Registration Status</div>
                            <div class="toggle-buttons">
                                <?php if ($current_status['status'] === 'closed'): ?>
                                    <button type="button" class="btn-toggle btn-open" id="open-registration">
                                        <i class='bx bxs-lock-open'></i>
                                        Open Registration
                                    </button>
                                    <button type="button" class="btn-toggle" disabled style="background: #6b7280; color: white;">
                                        <i class='bx bxs-lock'></i>
                                        Currently Closed
                                    </button>
                                <?php else: ?>
                                    <button type="button" class="btn-toggle" disabled style="background: #10b981; color: white;">
                                        <i class='bx bxs-lock-open'></i>
                                        Currently Open
                                    </button>
                                    <button type="button" class="btn-toggle btn-close" id="close-registration">
                                        <i class='bx bxs-lock'></i>
                                        Close Registration
                                    </button>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <!-- Side Panel -->
                    <div class="side-panel">
                        <!-- System Info -->
                        <div class="info-card">
                            <h3><i class='bx bxs-info-circle'></i> System Information</h3>
                            <div class="info-item">
                                <span class="info-label">Current Status</span>
                                <span class="info-value" style="color: <?php echo $current_status['status'] === 'open' ? '#10b981' : '#dc2626'; ?>;">
                                    <?php echo strtoupper($current_status['status']); ?>
                                </span>
                            </div>
                            <div class="info-item">
                                <span class="info-label">Last Updated</span>
                                <span class="info-value">
                                    <?php echo $current_status['updated_at'] ? date('M j, Y g:i A', strtotime($current_status['updated_at'])) : 'Never'; ?>
                                </span>
                            </div>
                            <?php if ($current_status['first_name']): ?>
                            <div class="info-item">
                                <span class="info-label">Updated By</span>
                                <span class="info-value"><?php echo $current_status['first_name'] . ' ' . $current_status['last_name']; ?></span>
                            </div>
                            <?php endif; ?>
                            <div class="info-item">
                                <span class="info-label">System Time</span>
                                <span class="info-value" id="live-time">Loading...</span>
                            </div>
                        </div>

                        <!-- Quick Actions -->
                        <div class="info-card">
                            <h3><i class='bx bxs-zap'></i> Quick Actions</h3>
                            <div style="display: flex; flex-direction: column; gap: 12px;">
                                <a href="review_data.php" class="btn-toggle" style=" text-decoration: none; text-align: center;">
                                    <i class='bx bxs-user-check'></i>
                                    Review Applications
                                </a>
                                <a href="../../index.php" target="_blank" class="btn-toggle" style=" text-decoration: none; text-align: center;">
                                    <i class='bx bx-show'></i>
                                    View Portal
                                </a>
                                <a href="view_availability.php" class="btn-toggle" style=" text-decoration: none; text-align: center;">
                                    <i class='bx bxs-calendar-check'></i>
                                    Check Availability
                                </a>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Impact Preview -->
                <div class="impact-preview">
                    <h2 class="impact-title">Registration Status Impact</h2>
                    <div class="impact-grid">
                        <div class="impact-card">
                            <div class="impact-icon">
                                <i class='bx bxs-user-plus'></i>
                            </div>
                            <h4>Public Access</h4>
                            <p>
                                <?php if ($current_status['status'] === 'open'): ?>
                                    Visitors can submit volunteer applications through the public portal with full form access.
                                <?php else: ?>
                                    Application form is hidden with a "Registration Closed" message displayed.
                                <?php endif; ?>
                            </p>
                        </div>
                        
                        <div class="impact-card">
                            <div class="impact-icon">
                                <i class='bx bxs-data'></i>
                            </div>
                            <h4>Data Collection</h4>
                            <p>
                                <?php if ($current_status['status'] === 'open'): ?>
                                    System is actively collecting and storing new volunteer applications in real-time.
                                <?php else: ?>
                                    No new applications are being accepted. Existing data remains accessible.
                                <?php endif; ?>
                            </p>
                        </div>
                        
                        <div class="impact-card">
                            <div class="impact-icon">
                                <i class='bx bxs-bell'></i>
                            </div>
                            <h4>User Experience</h4>
                            <p>
                                <?php if ($current_status['status'] === 'open'): ?>
                                    Positive engagement with clear application process and success confirmation.
                                <?php else: ?>
                                    Informative message about temporary closure and when to check back.
                                <?php endif; ?>
                            </p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Hidden Form for Submission -->
    <form method="POST" id="status-form" style="display: none;">
        <input type="hidden" name="action" id="action-input">
        <input type="hidden" name="password" id="password-input-hidden">
    </form>
    
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const animationOverlay = document.getElementById('dashboard-animation');
            const animationProgress = document.getElementById('animation-progress');
            const animationText = document.getElementById('animation-text');
            const animationLogo = document.querySelector('.animation-logo');
            
            setTimeout(() => {
            animationLogo.style.opacity = '1';
            animationLogo.style.transform = 'translateY(0)';
            }, 10);
            
            setTimeout(() => {
            animationText.style.opacity = '1';
            }, 400);
            
            setTimeout(() => {
            animationProgress.style.width = '180%';
            }, 100);
            
            // Changed loading time to 1500ms for faster load
            setTimeout(() => {
            animationOverlay.style.opacity = '0';
            setTimeout(() => {
                animationOverlay.style.display = 'none';
            }, 400);
            }, 1500);
            
            // Initialize dropdowns
            initDropdowns();
            
            // Initialize modal functionality
            initModal();
        });
        
        function initDropdowns() {
            // User profile dropdown
            const userProfile = document.getElementById('user-profile');
            const userDropdown = document.getElementById('user-dropdown');
            
            userProfile.addEventListener('click', function(e) {
                e.stopPropagation();
                userDropdown.classList.toggle('show');
                // Close notification dropdown if open
                notificationDropdown.classList.remove('show');
            });
            
            // Notification bell dropdown
            const notificationBell = document.getElementById('notification-bell');
            const notificationDropdown = document.getElementById('notification-dropdown');
            
            notificationBell.addEventListener('click', function(e) {
                e.stopPropagation();
                notificationDropdown.classList.toggle('show');
                // Close user dropdown if open
                userDropdown.classList.remove('show');
                
                // Mark notifications as read when dropdown is opened
                if (notificationDropdown.classList.contains('show')) {
                    document.querySelectorAll('.notification-item.unread').forEach(item => {
                        item.classList.remove('unread');
                    });
                    document.getElementById('notification-count').textContent = '0';
                }
            });
            
            // Clear all notifications
            document.querySelector('.notification-clear').addEventListener('click', function(e) {
                e.stopPropagation();
                document.getElementById('notification-list').innerHTML = `
                    <div class="notification-empty">
                        <i class='bx bxs-bell-off'></i>
                        <p>No notifications</p>
                    </div>
                `;
                document.getElementById('notification-count').textContent = '0';
            });
            
            // Close dropdowns when clicking outside
            document.addEventListener('click', function() {
                userDropdown.classList.remove('show');
                notificationDropdown.classList.remove('show');
            });
        }
        
        function initModal() {
            const modal = document.getElementById('confirmation-modal');
            const modalIcon = document.getElementById('modal-icon');
            const modalTitle = document.getElementById('modal-title');
            const modalMessage = document.getElementById('modal-message');
            const passwordInput = document.getElementById('password-input');
            const modalCancel = document.getElementById('modal-cancel');
            const modalConfirm = document.getElementById('modal-confirm');
            const statusForm = document.getElementById('status-form');
            const actionInput = document.getElementById('action-input');
            const passwordInputHidden = document.getElementById('password-input-hidden');
            
            let currentAction = '';
            
            // Open registration button
            const openBtn = document.getElementById('open-registration');
            if (openBtn) {
                openBtn.addEventListener('click', function() {
                    currentAction = 'open';
                    showModal(
                        'open',
                        'Open Registration',
                        'Are you sure you want to OPEN volunteer registration? This will allow the public to submit new applications through the portal.',
                        'Confirm Opening'
                    );
                });
            }
            
            // Close registration button
            const closeBtn = document.getElementById('close-registration');
            if (closeBtn) {
                closeBtn.addEventListener('click', function() {
                    currentAction = 'closed';
                    showModal(
                        'close',
                        'Close Registration',
                        'Are you sure you want to CLOSE volunteer registration? This will prevent new applications from being submitted through the portal.',
                        'Confirm Closing'
                    );
                });
            }
            
            function showModal(type, title, message, confirmText) {
                // Update modal content
                modalTitle.textContent = title;
                modalMessage.textContent = message;
                modalConfirm.textContent = confirmText;
                
                // Update modal icon and styling
                modalIcon.className = 'modal-icon';
                modalConfirm.className = 'btn-modal btn-modal-confirm';
                
                if (type === 'open') {
                    modalIcon.classList.add('open');
                    modalIcon.innerHTML = '<i class=\'bx bxs-lock-open\'></i>';
                    modalConfirm.classList.add('open');
                } else {
                    modalIcon.classList.add('close');
                    modalIcon.innerHTML = '<i class=\'bx bxs-lock\'></i>';
                    modalConfirm.classList.add('close');
                }
                
                // Clear password field
                passwordInput.value = '';
                
                // Show modal
                modal.classList.add('active');
                passwordInput.focus();
            }
            
            // Cancel button
            modalCancel.addEventListener('click', function() {
                modal.classList.remove('active');
            });
            
            // Confirm button
            modalConfirm.addEventListener('click', function() {
                const password = passwordInput.value.trim();
                
                if (!password) {
                    passwordInput.focus();
                    return;
                }
                
                // Set form values and submit
                actionInput.value = currentAction;
                passwordInputHidden.value = password;
                statusForm.submit();
            });
            
            // Close modal when clicking outside
            modal.addEventListener('click', function(e) {
                if (e.target === modal) {
                    modal.classList.remove('active');
                }
            });
            
            // Handle Enter key in password field
            passwordInput.addEventListener('keypress', function(e) {
                if (e.key === 'Enter') {
                    modalConfirm.click();
                }
            });
        }
        
        function toggleSubmenu(id) {
            const submenu = document.getElementById(id);
            const arrow = document.querySelector(`#${id}`).previousElementSibling.querySelector('.dropdown-arrow');
            
            submenu.classList.toggle('active');
            arrow.classList.toggle('rotated');
        }
        
        document.querySelectorAll('.menu-item').forEach(item => {
            item.addEventListener('click', function() {
                document.querySelectorAll('.menu-item').forEach(i => {
                    i.classList.remove('active');
                });
                
                this.classList.add('active');
            });
        });
        
        document.querySelectorAll('.submenu-item').forEach(item => {
            item.addEventListener('click', function() {
                document.querySelectorAll('.submenu-item').forEach(i => {
                    i.classList.remove('active');
                });
                
                this.classList.add('active');
            });
        });
        
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
        
        // Enhanced time display
        function updateTime() {
            const now = new Date();
            const utc = now.getTime() + (now.getTimezoneOffset() * 60000);
            const gmt8 = new Date(utc + (8 * 3600000));
            
            const hours = gmt8.getHours().toString().padStart(2, '0');
            const minutes = gmt8.getMinutes().toString().padStart(2, '0');
            const seconds = gmt8.getSeconds().toString().padStart(2, '0');
            
            const timeString = `${hours}:${minutes}:${seconds} UTC+8`;
            document.getElementById('current-time').textContent = timeString;
            document.getElementById('live-time').textContent = timeString;
        }
        
        updateTime();
        setInterval(updateTime, 1000);

        // Add interactive effects
        document.querySelectorAll('.btn-toggle').forEach(btn => {
            btn.addEventListener('mouseenter', function() {
                if (!this.disabled) {
                    this.style.transform = 'translateY(-2px)';
                }
            });
            
            btn.addEventListener('mouseleave', function() {
                if (!this.disabled) {
                    this.style.transform = 'translateY(0)';
                }
            });
        });
    </script>
</body>
</html>