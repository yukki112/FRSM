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

if (!$user || $user['role'] !== 'ADMIN') {
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

// Handle form submissions
$message = '';
$message_type = '';

// Get all users with their roles
$query = "SELECT id, username, email, CONCAT(first_name, ' ', COALESCE(middle_name, ''), ' ', last_name) as full_name, 
          role, is_verified, created_at,
          CASE 
            WHEN is_verified = 1 THEN 'Active'
            ELSE 'Inactive'
          END as status_display
          FROM users 
          ORDER BY created_at DESC";
$users = $pdo->query($query)->fetchAll();

// Handle role update
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['update_role'])) {
        $target_user_id = $_POST['user_id'];
        $new_role = $_POST['new_role'];
        $admin_password = $_POST['admin_password'];
        
        // Verify admin password first
        $verify_query = "SELECT password FROM users WHERE id = ?";
        $verify_stmt = $pdo->prepare($verify_query);
        $verify_stmt->execute([$user_id]);
        $admin_data = $verify_stmt->fetch();
        
        if (!$admin_data || !password_verify($admin_password, $admin_data['password'])) {
            $message = "Incorrect password. Role update cancelled.";
            $message_type = 'error';
        } else {
            // Get current user info for logging
            $current_query = "SELECT username, role FROM users WHERE id = ?";
            $current_stmt = $pdo->prepare($current_query);
            $current_stmt->execute([$target_user_id]);
            $current_user = $current_stmt->fetch();
            
            // Update role
            $update_query = "UPDATE users SET role = ? WHERE id = ?";
            $update_stmt = $pdo->prepare($update_query);
            
            if ($update_stmt->execute([$new_role, $target_user_id])) {
                $message = "Role updated successfully. " . htmlspecialchars($current_user['username']) . 
                          " changed from " . $current_user['role'] . " to " . $new_role . ".";
                $message_type = 'success';
                
                // Refresh users list
                $users = $pdo->query($query)->fetchAll();
            } else {
                $message = "Failed to update role.";
                $message_type = 'error';
            }
        }
    }
    
    // Handle bulk role assignment
    if (isset($_POST['bulk_assign_roles'])) {
        $selected_users = $_POST['selected_users'] ?? [];
        $bulk_role = $_POST['bulk_role'];
        $bulk_password = $_POST['bulk_password'];
        
        if (empty($selected_users)) {
            $message = "No users selected for role assignment.";
            $message_type = 'error';
        } else {
            // Verify admin password
            $verify_query = "SELECT password FROM users WHERE id = ?";
            $verify_stmt = $pdo->prepare($verify_query);
            $verify_stmt->execute([$user_id]);
            $admin_data = $verify_stmt->fetch();
            
            if (!$admin_data || !password_verify($bulk_password, $admin_data['password'])) {
                $message = "Incorrect password. Bulk role assignment cancelled.";
                $message_type = 'error';
            } else {
                // Prepare update query
                $update_query = "UPDATE users SET role = ? WHERE id = ?";
                $update_stmt = $pdo->prepare($update_query);
                
                $success_count = 0;
                $failed_count = 0;
                
                foreach ($selected_users as $user_id_to_update) {
                    if ($update_stmt->execute([$bulk_role, $user_id_to_update])) {
                        $success_count++;
                    } else {
                        $failed_count++;
                    }
                }
                
                if ($success_count > 0) {
                    $message = "Bulk role assignment completed. Successfully updated $success_count user(s).";
                    if ($failed_count > 0) {
                        $message .= " Failed to update $failed_count user(s).";
                    }
                    $message_type = 'success';
                    
                    // Refresh users list
                    $users = $pdo->query($query)->fetchAll();
                } else {
                    $message = "Failed to update any users.";
                    $message_type = 'error';
                }
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Role Control - FRSM</title>
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
            --card-bg: #ffffff;
            --sidebar-bg: #ffffff;

            --glass-bg: #ffffff;
            --glass-border: #e5e7eb;
            --glass-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
            
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
            
            --icon-bg-red: #fef2f2;
            --icon-bg-blue: #eff6ff;
            --icon-bg-green: #f0fdf4;
            --icon-bg-purple: #faf5ff;
            --icon-bg-yellow: #fefce8;
            --icon-bg-indigo: #eef2ff;
            --icon-bg-cyan: #ecfeff;
            --icon-bg-orange: #fff7ed;
            --icon-bg-pink: #fdf2f8;
            --icon-bg-teal: #f0fdfa;

            --chart-red: #ef4444;
            --chart-orange: #f97316;
            --chart-yellow: #f59e0b;
            --chart-green: #10b981;
            --chart-blue: #3b82f6;
            --chart-purple: #8b5cf6;
            --chart-pink: #ec4899;

            /* Additional variables for consistency */
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
        
        /* Dark mode variables */
        .dark-mode {
            --background-color: #111827;
            --text-color: #f9fafb;
            --text-light: #9ca3af;
            --border-color: #374151;
            --card-bg: #1f2937;
            --sidebar-bg: #1f2937;
            
            --glass-bg: #1f2937;
            --glass-border: #374151;
            --glass-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.2), 0 2px 4px -1px rgba(0, 0, 0, 0.1);
            
            --icon-bg-red: #7f1d1d;
            --icon-bg-blue: #1e3a8a;
            --icon-bg-green: #065f46;
            --icon-bg-purple: #5b21b6;
            --icon-bg-yellow: #854d0e;
            --icon-bg-indigo: #3730a3;
            --icon-bg-cyan: #155e75;
            --icon-bg-orange: #9a3412;
            --icon-bg-pink: #831843;
            --icon-bg-teal: #134e4a;
        }

        /* Font and size from reference */
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            font-size: 14px;
            line-height: 1.5;
            color: var(--text-color);
            background-color: var(--background-color);
            margin: 0;
            padding: 0;
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

        /* COMPLETELY NEW LAYOUT DESIGN */
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

        /* Notification Container */
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
            background: var(--glass-bg);
            border: 1px solid var(--glass-border);
            box-shadow: var(--glass-shadow);
            display: flex;
            align-items: center;
            gap: 12px;
            transform: translateX(100%);
            opacity: 0;
            transition: all 0.3s ease;
            max-width: 350px;
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

        .notification-error .notification-icon {
            color: var(--danger);
        }

        /* Role Control Styles */
        .role-control-container {
            padding: 0 40px 40px;
        }

        .role-control-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 30px;
            flex-wrap: wrap;
            gap: 20px;
        }

        .role-stats {
            display: flex;
            gap: 20px;
            flex-wrap: wrap;
        }

        .stat-card {
            background: var(--glass-bg);
            border: 1px solid var(--glass-border);
            box-shadow: var(--glass-shadow);
            border-radius: 16px;
            padding: 20px;
            min-width: 150px;
        }

        .stat-value {
            font-size: 28px;
            font-weight: 700;
            margin-bottom: 8px;
            color: var(--primary-color);
        }

        .stat-label {
            font-size: 14px;
            color: var(--text-light);
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .role-actions {
            display: flex;
            gap: 15px;
            flex-wrap: wrap;
        }

        .btn-action {
            padding: 12px 24px;
            border: none;
            border-radius: 12px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .btn-bulk-assign {
            background: var(--primary-color);
            color: white;
        }

        .btn-bulk-assign:hover {
            background: var(--primary-dark);
            transform: translateY(-2px);
        }

        .btn-export {
            background: var(--icon-green);
            color: white;
        }

        .btn-export:hover {
            background: #0da271;
            transform: translateY(-2px);
        }

        /* Bulk Role Assignment Section */
        .bulk-assignment-section {
            background: var(--glass-bg);
            border: 1px solid var(--glass-border);
            box-shadow: var(--glass-shadow);
            border-radius: 20px;
            padding: 30px;
            margin-bottom: 30px;
            display: none;
        }

        .bulk-assignment-section.active {
            display: block;
            animation: slideDown 0.3s ease;
        }

        @keyframes slideDown {
            from {
                opacity: 0;
                transform: translateY(-10px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .bulk-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }

        .bulk-title {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--text-color);
        }

        .bulk-close {
            background: none;
            border: none;
            font-size: 24px;
            color: var(--text-light);
            cursor: pointer;
            padding: 5px;
            border-radius: 6px;
            transition: all 0.3s ease;
        }

        .bulk-close:hover {
            background: var(--gray-200);
            color: var(--text-color);
        }

        .dark-mode .bulk-close:hover {
            background: #374151;
        }

        .bulk-form {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
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
            padding: 12px;
            border: 2px solid var(--gray-300);
            border-radius: 8px;
            font-size: 14px;
            transition: all 0.3s ease;
            background: var(--background-color);
            color: var(--text-color);
        }

        .dark-mode .form-input {
            border-color: #475569;
            background: #1e293b;
        }

        .form-input:focus {
            outline: none;
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(220, 38, 38, 0.1);
        }

        .form-select {
            width: 100%;
            padding: 12px;
            border: 2px solid var(--gray-300);
            border-radius: 8px;
            font-size: 14px;
            background: var(--background-color);
            color: var(--text-color);
            cursor: pointer;
        }

        .dark-mode .form-select {
            border-color: #475569;
            background: #1e293b;
        }

        .form-full-width {
            grid-column: 1 / -1;
        }

        .selected-count {
            background: var(--icon-blue);
            color: white;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            margin-left: 10px;
        }

        /* Users Table */
        .users-table-container {
            background: var(--glass-bg);
            border: 1px solid var(--glass-border);
            box-shadow: var(--glass-shadow);
            border-radius: 20px;
            overflow: hidden;
            margin-bottom: 30px;
        }

        .users-table {
            width: 100%;
            border-collapse: collapse;
        }

        .users-table th {
            padding: 20px;
            text-align: left;
            font-weight: 600;
            color: var(--text-color);
            border-bottom: 2px solid var(--border-color);
            background: rgba(255, 255, 255, 0.1);
            user-select: none;
        }

        .users-table td {
            padding: 16px 20px;
            border-bottom: 1px solid var(--border-color);
            vertical-align: middle;
        }

        .users-table tr:hover {
            background: rgba(255, 255, 255, 0.05);
        }

        .user-avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            object-fit: cover;
            margin-right: 12px;
        }

        .user-info-cell {
            display: flex;
            align-items: center;
        }

        .user-name {
            font-weight: 600;
            color: var(--text-color);
        }

        .user-email {
            font-size: 12px;
            color: var(--text-light);
        }

        .role-badge {
            display: inline-block;
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .role-admin {
            background: linear-gradient(135deg, rgba(220, 38, 38, 0.1), rgba(185, 28, 28, 0.1));
            color: var(--primary-color);
            border: 1px solid rgba(220, 38, 38, 0.3);
        }

        .role-employee {
            background: linear-gradient(135deg, rgba(59, 130, 246, 0.1), rgba(29, 78, 216, 0.1));
            color: var(--icon-blue);
            border: 1px solid rgba(59, 130, 246, 0.3);
        }

        .role-user {
            background: linear-gradient(135deg, rgba(16, 185, 129, 0.1), rgba(5, 150, 105, 0.1));
            color: var(--success);
            border: 1px solid rgba(16, 185, 129, 0.3);
        }

        .status-badge {
            display: inline-block;
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
        }

        .status-active {
            background: linear-gradient(135deg, rgba(16, 185, 129, 0.1), rgba(5, 150, 105, 0.1));
            color: var(--success);
            border: 1px solid rgba(16, 185, 129, 0.3);
        }

        .status-inactive {
            background: linear-gradient(135deg, rgba(220, 38, 38, 0.1), rgba(185, 28, 28, 0.1));
            color: var(--danger);
            border: 1px solid rgba(220, 38, 38, 0.3);
        }

        /* Role Select */
        .role-select {
            padding: 8px 12px;
            border: 2px solid var(--gray-300);
            border-radius: 8px;
            font-size: 14px;
            background: var(--background-color);
            color: var(--text-color);
            cursor: pointer;
            min-width: 120px;
        }

        .dark-mode .role-select {
            border-color: #475569;
            background: #1e293b;
        }

        .role-select:focus {
            outline: none;
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(220, 38, 38, 0.1);
        }

        .btn-update {
            padding: 8px 16px;
            background: var(--primary-color);
            color: white;
            border: none;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .btn-update:hover {
            background: var(--primary-dark);
            transform: translateY(-2px);
        }

        /* Checkbox */
        .user-checkbox {
            width: 18px;
            height: 18px;
            cursor: pointer;
            accent-color: var(--primary-color);
        }

        /* Password Input */
        .password-input {
            width: 100%;
            padding: 12px;
            border: 2px solid var(--gray-300);
            border-radius: 8px;
            font-size: 14px;
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

        /* Modal */
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
            background: var(--background-color);
            border-radius: 20px;
            box-shadow: 0 25px 50px rgba(0, 0, 0, 0.25);
            width: 90%;
            max-width: 400px;
            transform: translateY(20px);
            transition: transform 0.3s ease;
        }

        .modal-overlay.active .modal {
            transform: translateY(0);
        }

        .modal-header {
            padding: 25px 30px 20px;
            border-bottom: 1px solid var(--gray-200);
            display: flex;
            align-items: center;
            gap: 15px;
        }

        .dark-mode .modal-header {
            border-bottom-color: #334155;
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
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
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

        .modal-footer {
            padding: 20px 30px 25px;
            display: flex;
            gap: 15px;
            justify-content: flex-end;
            border-top: 1px solid var(--gray-200);
        }

        .dark-mode .modal-footer {
            border-top-color: #334155;
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
            background: var(--gray-200);
            color: var(--text-color);
        }

        .dark-mode .btn-modal-cancel {
            background: #334155;
        }

        .btn-modal-cancel:hover {
            background: var(--gray-300);
        }

        .dark-mode .btn-modal-cancel:hover {
            background: #475569;
        }

        .btn-modal-confirm {
            background: var(--primary-color);
            color: white;
        }

        .btn-modal-confirm:hover {
            background: var(--primary-dark);
        }

        /* Responsive Design */
        @media (max-width: 768px) {
            .dashboard-header {
                padding: 40px 25px 30px;
                border-radius: 0 0 20px 20px;
            }
            
            .dashboard-title {
                font-size: 2.2rem;
            }
            
            .role-control-container {
                padding: 0 25px 30px;
            }
            
            .role-control-header {
                flex-direction: column;
                align-items: flex-start;
            }
            
            .role-stats {
                width: 100%;
            }
            
            .stat-card {
                flex: 1;
                min-width: unset;
            }
            
            .bulk-form {
                grid-template-columns: 1fr;
            }
            
            .users-table {
                display: block;
                overflow-x: auto;
            }
            
            .action-buttons {
                flex-direction: column;
            }
        }

        @media (max-width: 480px) {
            .role-stats {
                flex-direction: column;
            }
            
            .role-actions {
                flex-direction: column;
                width: 100%;
            }
            
            .btn-action {
                width: 100%;
                justify-content: center;
            }
        }
    </style>
</head>
<body>
    <!-- Notification Container -->
    <div class="notification-container" id="notification-container">
        <?php if ($message): ?>
            <div class="notification notification-<?php echo $message_type === 'error' ? 'error' : 'success'; ?> show">
                <i class='bx <?php echo $message_type === 'error' ? 'bxs-error-circle' : 'bxs-check-circle'; ?> notification-icon'></i>
                <div class="notification-content">
                    <div class="notification-message"><?php echo $message; ?></div>
                </div>
                <button class="notification-close" onclick="this.parentElement.remove()">
                    <i class='bx bx-x'></i>
                </button>
            </div>
        <?php endif; ?>
    </div>
    
    <!-- Update Role Modal -->
    <div class="modal-overlay" id="update-role-modal">
        <div class="modal">
            <div class="modal-header">
                <div class="modal-icon">
                    <i class='bx bxs-lock'></i>
                </div>
                <h3 class="modal-title">Update Role</h3>
            </div>
            <form method="POST" id="update-role-form">
                <input type="hidden" name="update_role" value="1">
                <input type="hidden" name="user_id" id="update-user-id">
                <div class="modal-body">
                    <p class="modal-message" id="update-message">Are you sure you want to update this user's role?</p>
                    <input type="password" class="password-input" name="admin_password" placeholder="Enter your password to confirm" required autocomplete="off">
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn-modal btn-modal-cancel" id="update-modal-cancel">Cancel</button>
                    <button type="submit" class="btn-modal btn-modal-confirm">Update Role</button>
                </div>
            </form>
        </div>
    </div>
    
    <!-- Bulk Assignment Modal -->
    <div class="modal-overlay" id="bulk-modal">
        <div class="modal">
            <div class="modal-header">
                <div class="modal-icon">
                    <i class='bx bxs-user-check'></i>
                </div>
                <h3 class="modal-title">Bulk Role Assignment</h3>
            </div>
            <form method="POST" id="bulk-role-form">
                <input type="hidden" name="bulk_assign_roles" value="1">
                <div class="modal-body">
                    <p class="modal-message" id="bulk-message">Are you sure you want to assign the selected role to <span id="selected-count">0</span> user(s)?</p>
                    <input type="password" class="password-input" name="bulk_password" placeholder="Enter your password to confirm" required autocomplete="off">
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn-modal btn-modal-cancel" id="bulk-modal-cancel">Cancel</button>
                    <button type="submit" class="btn-modal btn-modal-confirm">Assign Roles</button>
                </div>
            </form>
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
                    <a href="#" class="menu-item" id="dashboard-menu">
                        <div class="icon-box icon-bg-red">
                            <i class='bx bxs-dashboard icon-red'></i>
                        </div>
                        <span class="font-medium">Dashboard</span>
                    </a>
                    
                    <!-- User Management -->
                    <div class="menu-item" onclick="toggleSubmenu('user-management')">
                        <div class="icon-box icon-bg-orange">
                            <i class='bx bxs-user icon-orange'></i>
                        </div>
                        <span class="font-medium">User Management</span>
                        <svg class="dropdown-arrow menu-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                        </svg>
                    </div>
                    <div id="user-management" class="submenu active">
                        <a href="manage_users.php" class="submenu-item">Manage Users</a>
                        <a href="role_control.php" class="submenu-item active">Role Control</a>
                        <a href="audit_logs.php" class="submenu-item">Audit & Activity Logs</a>
                    </div>
                    
                  <!-- Fire & Incident Reporting Management -->
                    <div class="menu-item" onclick="toggleSubmenu('incident-management')">
                        <div class="icon-box icon-bg-yellow">
                            <i class='bx bxs-alarm-exclamation icon-yellow'></i>
                        </div>
                        <span class="font-medium">Incident Management</span>
                        <svg class="dropdown-arrow menu-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                        </svg>
                    </div>
                    <div id="incident-management" class="submenu">
                     
                        <a href="../fir/receive_data.php" class="submenu-item">Recieve Data</a>
                         <a href="../fir/track_status.php" class="submenu-item">Track Status</a>
                        <a href="../fir/update_status.php" class="submenu-item">Update Status</a>
                        <a href="../fir/incidents_analytics.php" class="submenu-item">Incidents Analytics</a>
                        
                    </div>
                    
                  <!-- Barangay Volunteer Roster Management -->
                    <div class="menu-item" onclick="toggleSubmenu('volunteer-management')">
                        <div class="icon-box icon-bg-blue">
                            <i class='bx bxs-user-detail icon-blue'></i>
                        </div>
                        <span class="font-medium">Volunteer Management</span>
                        <svg class="dropdown-arrow menu-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                        </svg>
                    </div>
                    <div id="volunteer-management" class="submenu">
                        <a href="../vm/review_data.php" class="submenu-item ">Review Data</a>
                        <a href="../vm/approve_applications.php" class="submenu-item">Assign Volunteers</a>
                        <a href="../vm/view_availability.php" class="submenu-item">View Availability</a>
                        <a href="../vm/toggle_volunteer_registration.php" class="submenu-item">Toggle Registration</a>
                    </div>
                    
                    <!-- Resource Inventory Management -->
                    <div class="menu-item" onclick="toggleSubmenu('resource-management')">
                        <div class="icon-box icon-bg-green">
                            <i class='bx bxs-cube icon-green'></i>
                        </div>
                        <span class="font-medium">Resource Management</span>
                        <svg class="dropdown-arrow menu-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                        </svg>
                    </div>
                    <div id="resource-management" class="submenu">
                        <a href="../rm/view_equipment.php" class="submenu-item">View Equipment</a>
                        <a href="../rm/approve_maintenance.php" class="submenu-item">Approve Maintenance</a>
                        <a href="../rm/approve_resources.php" class="submenu-item">Approve Resources</a>
                        <a href="../rm/review_deployment.php" class="submenu-item">Review Deployment</a>
                    </div>
                    
                    <!-- Shift & Duty Scheduling -->
                    <div class="menu-item" onclick="toggleSubmenu('schedule-management')">
                        <div class="icon-box icon-bg-purple">
                            <i class='bx bxs-calendar icon-purple'></i>
                        </div>
                        <span class="font-medium">Schedule Management</span>
                        <svg class="dropdown-arrow menu-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                        </svg>
                    </div>
                    <div id="schedule-management" class="submenu">
                        <a href="../sm/view_shifts.php" class="submenu-item">View Shifts</a>
                        <a href="../sm/create_schedule.php" class="submenu-item">Create Schedule</a>
                        <a href="../sm/approve_shifts.php" class="submenu-item">Approve Shifts</a>
                        <a href="../sm/override_assignments.php" class="submenu-item">Override Assignments</a>
                        <a href="../sm/monitor_attendance.php" class="submenu-item">Monitor Attendance</a>
                    </div>
                    
                    <!-- Training & Certification Monitoring -->
                    <div class="menu-item" onclick="toggleSubmenu('training-management')">
                        <div class="icon-box icon-bg-teal">
                            <i class='bx bxs-graduation icon-teal'></i>
                        </div>
                        <span class="font-medium">Training Management</span>
                        <svg class="dropdown-arrow menu-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                        </svg>
                    </div>
                    <div id="training-management" class="submenu">
                        <a href="../tc/approve_completions.php" class="submenu-item">Approve Completions</a>
                        <a href="../tc/view_training_records.php" class="submenu-item">View Records</a>
                        <a href="../tc/assign_training.php" class="submenu-item">Assign Training</a>
                        <a href="../tc/track_expiry.php" class="submenu-item">Track Expiry</a>
                    </div>
                    
                    <!-- Inspection Logs for Establishments -->
                    <div class="menu-item" onclick="toggleSubmenu('inspection-management')">
                        <div class="icon-box icon-bg-cyan">
                            <i class='bx bxs-check-shield icon-cyan'></i>
                        </div>
                        <span class="font-medium">Inspection Management</span>
                        <svg class="dropdown-arrow menu-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                        </svg>
                    </div>
                    <div id="inspection-management" class="submenu">
                        <a href="../ile/approve_reports.php" class="submenu-item">Approve Reports</a>
                        <a href="../ile/review_violations.php" class="submenu-item">Review Violations</a>
                        <a href="../ile/issue_certificates.php" class="submenu-item">Issue Certificates</a>
                        <a href="../ile/track_followup.php" class="submenu-item">Track Follow-Up</a>
                    </div>
                    
                    <!-- Post-Incident Reporting & Analytics -->
                    <div class="menu-item" onclick="toggleSubmenu('analytics-management')">
                        <div class="icon-box icon-bg-pink">
                            <i class='bx bxs-file-doc icon-pink'></i>
                        </div>
                        <span class="font-medium">Analytics & Reports</span>
                        <svg class="dropdown-arrow menu-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                        </svg>
                    </div>
                    <div id="analytics-management" class="submenu">
                        <a href="../pir/review_summaries.php" class="submenu-item">Review Summaries</a>
                    </div>
                </div>
                
                <p class="menu-title" style="margin-top: 32px;">GENERAL</p>
                
                <div class="menu-items">
                    <a href="#" class="menu-item">
                        <div class="icon-box icon-bg-teal">
                            <i class='bx bxs-cog icon-teal'></i>
                        </div>
                        <span class="font-medium">Settings</span>
                    </a>
                    
                    <a href="../profile.php" class="menu-item">
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
                        <div class="user-profile" id="user-profile">
                            <img src="../../img/rei.jfif" alt="User" class="user-avatar">
                            <div class="user-info">
                                <p class="user-name"><?php echo $full_name; ?></p>
                                <p class="user-email"><?php echo $role; ?></p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Role Control Content -->
            <div class="dashboard-content">
                <!-- Hero Header -->
                <div class="dashboard-header">
                    <div>
                        <h1 class="dashboard-title">Role Control</h1>
                        <p class="dashboard-subtitle">Manage user roles, permissions, and access levels</p>
                    </div>
                </div>
                
                <!-- Role Control Container -->
                <div class="role-control-container">
                    <!-- Role Statistics and Actions -->
                    <div class="role-control-header">
                        <div class="role-stats">
                            <?php
                            // Count users by role
                            $role_counts = ['ADMIN' => 0, 'EMPLOYEE' => 0, 'USER' => 0];
                            foreach ($users as $user) {
                                $role_counts[$user['role']]++;
                            }
                            ?>
                            <div class="stat-card">
                                <div class="stat-value"><?php echo $role_counts['ADMIN']; ?></div>
                                <div class="stat-label">Administrators</div>
                            </div>
                            <div class="stat-card">
                                <div class="stat-value"><?php echo $role_counts['EMPLOYEE']; ?></div>
                                <div class="stat-label">Employees</div>
                            </div>
                            <div class="stat-card">
                                <div class="stat-value"><?php echo $role_counts['USER']; ?></div>
                                <div class="stat-label">Users</div>
                            </div>
                            <div class="stat-card">
                                <div class="stat-value"><?php echo count($users); ?></div>
                                <div class="stat-label">Total Users</div>
                            </div>
                        </div>
                        
                        <div class="role-actions">
                            <button type="button" class="btn-action btn-bulk-assign" id="show-bulk-assign">
                                <i class='bx bxs-user-check'></i>
                                Bulk Assign Roles
                            </button>
                            <button type="button" class="btn-action btn-export" onclick="exportRoles()">
                                <i class='bx bxs-download'></i>
                                Export Roles
                            </button>
                        </div>
                    </div>
                    
                    <!-- Bulk Role Assignment Section -->
                    <div class="bulk-assignment-section" id="bulk-assignment-section">
                        <div class="bulk-header">
                            <h3 class="bulk-title">Bulk Role Assignment</h3>
                            <button type="button" class="bulk-close" id="close-bulk-assign">
                                <i class='bx bx-x'></i>
                            </button>
                        </div>
                        <form method="POST" id="bulk-assign-form">
                            <div class="bulk-form">
                                <div class="form-group">
                                    <label class="form-label">Select Role</label>
                                    <select class="form-select" name="bulk_role" required>
                                        <option value="">Choose a role...</option>
                                        <option value="ADMIN">Administrator</option>
                                        <option value="EMPLOYEE">Employee</option>
                                        <option value="USER">User</option>
                                    </select>
                                </div>
                                
                                <div class="form-group">
                                    <label class="form-label">Selected Users <span class="selected-count" id="bulk-selected-count">0</span></label>
                                    <div style="background: var(--gray-100); padding: 15px; border-radius: 8px; max-height: 200px; overflow-y: auto;">
                                        <small>Users selected in the table below will be updated.</small>
                                    </div>
                                </div>
                                
                                <div class="form-group form-full-width">
                                    <label class="form-label">Admin Password *</label>
                                    <input type="password" class="password-input" name="bulk_password" placeholder="Enter your password to confirm bulk assignment" required autocomplete="off">
                                </div>
                                
                                <div class="form-group form-full-width">
                                    <button type="submit" class="btn-action btn-bulk-assign" style="width: 100%; justify-content: center;">
                                        <i class='bx bxs-user-check'></i>
                                        Assign Role to Selected Users
                                    </button>
                                </div>
                            </div>
                        </form>
                    </div>
                    
                    <!-- Users Table -->
                    <div class="users-table-container">
                        <table class="users-table">
                            <thead>
                                <tr>
                                    <th width="50">
                                        <input type="checkbox" id="select-all" class="user-checkbox">
                                    </th>
                                    <th>User</th>
                                    <th>Username</th>
                                    <th>Email</th>
                                    <th>Current Role</th>
                                    <th>Status</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($users)): ?>
                                    <tr>
                                        <td colspan="7" style="text-align: center; padding: 40px;">
                                            No users found.
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($users as $user): ?>
                                        <tr>
                                            <td>
                                                <input type="checkbox" class="user-checkbox user-select" name="selected_users[]" value="<?php echo $user['id']; ?>" data-username="<?php echo htmlspecialchars($user['username']); ?>">
                                            </td>
                                            <td>
                                                <div class="user-info-cell">
                                                    <img src="<?php echo !empty($user['avatar']) ? htmlspecialchars($user['avatar']) : '../../img/default-avatar.png'; ?>" 
                                                         alt="User Avatar" class="user-avatar">
                                                    <div>
                                                        <div class="user-name"><?php echo htmlspecialchars($user['full_name']); ?></div>
                                                        <div class="user-email">ID: #<?php echo $user['id']; ?></div>
                                                    </div>
                                                </div>
                                            </td>
                                            <td><?php echo htmlspecialchars($user['username']); ?></td>
                                            <td><?php echo htmlspecialchars($user['email']); ?></td>
                                            <td>
                                                <span class="role-badge role-<?php echo strtolower($user['role']); ?>">
                                                    <?php echo $user['role']; ?>
                                                </span>
                                            </td>
                                            <td>
                                                <span class="status-badge <?php echo $user['is_verified'] ? 'status-active' : 'status-inactive'; ?>">
                                                    <?php echo $user['status_display']; ?>
                                                </span>
                                            </td>
                                            <td>
                                                <div class="action-buttons" style="display: flex; gap: 10px; align-items: center;">
                                                    <select class="role-select" id="role-select-<?php echo $user['id']; ?>" onchange="prepareRoleUpdate(<?php echo $user['id']; ?>, '<?php echo addslashes($user['username']); ?>', '<?php echo $user['role']; ?>', this.value)">
                                                        <option value="USER" <?php echo $user['role'] === 'USER' ? 'selected' : ''; ?>>User</option>
                                                        <option value="EMPLOYEE" <?php echo $user['role'] === 'EMPLOYEE' ? 'selected' : ''; ?>>Employee</option>
                                                        <option value="ADMIN" <?php echo $user['role'] === 'ADMIN' ? 'selected' : ''; ?>>Admin</option>
                                                    </select>
                                                    <button type="button" class="btn-update" onclick="confirmRoleUpdate(<?php echo $user['id']; ?>, '<?php echo addslashes($user['username']); ?>', '<?php echo $user['role']; ?>')">
                                                        <i class='bx bx-sync'></i> Update
                                                    </button>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <script>
        // Store selected role for each user
        let pendingRoleUpdates = {};
        
        document.addEventListener('DOMContentLoaded', function() {
            // Initialize theme toggle
            initThemeToggle();
            
            // Initialize time display
            initTimeDisplay();
            
            // Auto-hide notifications after 5 seconds
            const notifications = document.querySelectorAll('.notification.show');
            notifications.forEach(notification => {
                setTimeout(() => {
                    notification.style.opacity = '0';
                    setTimeout(() => notification.remove(), 300);
                }, 5000);
            });
            
            // Initialize checkboxes
            initCheckboxes();
            
            // Initialize bulk assignment
            initBulkAssignment();
            
            // Initialize modals
            initModals();
        });
        
        function initCheckboxes() {
            const selectAll = document.getElementById('select-all');
            const userCheckboxes = document.querySelectorAll('.user-select');
            
            selectAll.addEventListener('change', function() {
                const isChecked = this.checked;
                userCheckboxes.forEach(checkbox => {
                    checkbox.checked = isChecked;
                });
                updateSelectedCount();
            });
            
            userCheckboxes.forEach(checkbox => {
                checkbox.addEventListener('change', updateSelectedCount);
            });
            
            function updateSelectedCount() {
                const selectedCount = document.querySelectorAll('.user-select:checked').length;
                document.getElementById('bulk-selected-count').textContent = selectedCount;
                
                // Update select all checkbox state
                const totalCheckboxes = userCheckboxes.length;
                const checkedCount = selectedCount;
                selectAll.checked = totalCheckboxes > 0 && checkedCount === totalCheckboxes;
                selectAll.indeterminate = checkedCount > 0 && checkedCount < totalCheckboxes;
            }
            
            // Initial count update
            updateSelectedCount();
        }
        
        function initBulkAssignment() {
            const showBulkBtn = document.getElementById('show-bulk-assign');
            const closeBulkBtn = document.getElementById('close-bulk-assign');
            const bulkSection = document.getElementById('bulk-assignment-section');
            
            showBulkBtn.addEventListener('click', function() {
                const selectedCount = document.querySelectorAll('.user-select:checked').length;
                if (selectedCount === 0) {
                    alert('Please select at least one user to assign roles.');
                    return;
                }
                bulkSection.classList.add('active');
            });
            
            closeBulkBtn.addEventListener('click', function() {
                bulkSection.classList.remove('active');
            });
            
            // Handle bulk form submission
            const bulkForm = document.getElementById('bulk-assign-form');
            bulkForm.addEventListener('submit', function(e) {
                const selectedCount = document.querySelectorAll('.user-select:checked').length;
                if (selectedCount === 0) {
                    e.preventDefault();
                    alert('Please select at least one user to assign roles.');
                    return;
                }
                
                // Collect selected user IDs
                const selectedUsers = [];
                document.querySelectorAll('.user-select:checked').forEach(checkbox => {
                    selectedUsers.push(checkbox.value);
                });
                
                // Add hidden inputs for selected users
                selectedUsers.forEach(userId => {
                    const input = document.createElement('input');
                    input.type = 'hidden';
                    input.name = 'selected_users[]';
                    input.value = userId;
                    bulkForm.appendChild(input);
                });
            });
        }
        
        function initModals() {
            const updateModal = document.getElementById('update-role-modal');
            const bulkModal = document.getElementById('bulk-modal');
            
            // Close update modal
            updateModal.querySelector('#update-modal-cancel').addEventListener('click', function() {
                updateModal.classList.remove('active');
            });
            
            updateModal.addEventListener('click', function(e) {
                if (e.target === updateModal) {
                    updateModal.classList.remove('active');
                }
            });
            
            // Handle update role form submission
            const updateForm = document.getElementById('update-role-form');
            updateForm.addEventListener('submit', function(e) {
                // Get the selected role from the pending updates
                const userId = document.getElementById('update-user-id').value;
                const newRole = pendingRoleUpdates[userId];
                
                if (!newRole) {
                    e.preventDefault();
                    alert('No role selected. Please select a role first.');
                    return;
                }
                
                // Add hidden input for new role
                const roleInput = document.createElement('input');
                roleInput.type = 'hidden';
                roleInput.name = 'new_role';
                roleInput.value = newRole;
                updateForm.appendChild(roleInput);
            });
            
            // Close bulk modal
            bulkModal.querySelector('#bulk-modal-cancel').addEventListener('click', function() {
                bulkModal.classList.remove('active');
            });
            
            bulkModal.addEventListener('click', function(e) {
                if (e.target === bulkModal) {
                    bulkModal.classList.remove('active');
                }
            });
            
            // Handle bulk modal form submission
            const bulkModalForm = document.getElementById('bulk-role-form');
            const bulkAssignForm = document.getElementById('bulk-assign-form');
            
            bulkAssignForm.addEventListener('submit', function(e) {
                e.preventDefault();
                
                const selectedCount = document.querySelectorAll('.user-select:checked').length;
                if (selectedCount === 0) {
                    alert('Please select at least one user to assign roles.');
                    return;
                }
                
                const bulkRole = bulkAssignForm.querySelector('select[name="bulk_role"]').value;
                const bulkPassword = bulkAssignForm.querySelector('input[name="bulk_password"]').value;
                
                if (!bulkRole) {
                    alert('Please select a role to assign.');
                    return;
                }
                
                if (!bulkPassword) {
                    alert('Please enter your password to confirm.');
                    return;
                }
                
                // Set modal content
                document.getElementById('selected-count').textContent = selectedCount;
                document.getElementById('bulk-message').textContent = 
                    `Are you sure you want to assign the ${bulkRole} role to ${selectedCount} user(s)?`;
                
                // Transfer data to modal form
                bulkModalForm.querySelector('select[name="bulk_role"]').value = bulkRole;
                bulkModalForm.querySelector('input[name="bulk_password"]').value = '';
                
                // Show modal
                bulkModal.classList.add('active');
                setTimeout(() => bulkModalForm.querySelector('input[name="bulk_password"]').focus(), 300);
                
                // Handle modal form submission
                bulkModalForm.onsubmit = function() {
                    // Collect selected user IDs
                    const selectedUsers = [];
                    document.querySelectorAll('.user-select:checked').forEach(checkbox => {
                        selectedUsers.push(checkbox.value);
                    });
                    
                    // Add hidden inputs for selected users
                    selectedUsers.forEach(userId => {
                        const input = document.createElement('input');
                        input.type = 'hidden';
                        input.name = 'selected_users[]';
                        input.value = userId;
                        bulkModalForm.appendChild(input);
                    });
                    
                    // Submit the actual form
                    bulkAssignForm.submit();
                };
            });
        }
        
        function prepareRoleUpdate(userId, username, currentRole, newRole) {
            pendingRoleUpdates[userId] = newRole;
        }
        
        function confirmRoleUpdate(userId, username, currentRole) {
            const newRole = pendingRoleUpdates[userId] || document.getElementById(`role-select-${userId}`).value;
            
            if (!newRole) {
                alert('Please select a role first.');
                return;
            }
            
            if (newRole === currentRole) {
                alert('User already has this role.');
                return;
            }
            
            // Set modal content
            document.getElementById('update-user-id').value = userId;
            document.getElementById('update-message').textContent = 
                `Are you sure you want to change ${username}'s role from ${currentRole} to ${newRole}?`;
            
            // Store the selected role
            pendingRoleUpdates[userId] = newRole;
            
            // Show modal
            const updateModal = document.getElementById('update-role-modal');
            updateModal.classList.add('active');
            setTimeout(() => updateModal.querySelector('.password-input').focus(), 300);
        }
        
        function toggleSubmenu(id) {
            const submenu = document.getElementById(id);
            const arrow = document.querySelector(`#${id}`).previousElementSibling.querySelector('.dropdown-arrow');
            
            submenu.classList.toggle('active');
            arrow.classList.toggle('rotated');
        }
        
        function initThemeToggle() {
            const themeToggle = document.getElementById('theme-toggle');
            const themeIcon = themeToggle.querySelector('i');
            const themeText = themeToggle.querySelector('span');
            
            // Check for saved theme preference or respect prefers-color-scheme
            const prefersDarkScheme = window.matchMedia('(prefers-color-scheme: dark)');
            const currentTheme = localStorage.getItem('theme');
            
            if (currentTheme === 'dark' || (!currentTheme && prefersDarkScheme.matches)) {
                document.body.classList.add('dark-mode');
                themeIcon.className = 'bx bx-sun';
                themeText.textContent = 'Light Mode';
            }
            
            themeToggle.addEventListener('click', function() {
                document.body.classList.toggle('dark-mode');
                
                let theme = 'light';
                if (document.body.classList.contains('dark-mode')) {
                    themeIcon.className = 'bx bx-sun';
                    themeText.textContent = 'Light Mode';
                    theme = 'dark';
                } else {
                    themeIcon.className = 'bx bx-moon';
                    themeText.textContent = 'Dark Mode';
                }
                
                localStorage.setItem('theme', theme);
            });
        }
        
        function initTimeDisplay() {
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
            
            updateTime();
            setInterval(updateTime, 1000);
        }
        
        function exportRoles() {
            // Create CSV content
            let csv = 'ID,Full Name,Username,Email,Role,Status,Created\n';
            
            document.querySelectorAll('.users-table tbody tr').forEach(row => {
                const cells = row.querySelectorAll('td');
                if (cells.length >= 7) {
                    const id = row.querySelector('.user-select')?.value || '';
                    const name = cells[1].querySelector('.user-name')?.textContent || '';
                    const username = cells[2]?.textContent || '';
                    const email = cells[3]?.textContent || '';
                    const role = cells[4].querySelector('.role-badge')?.textContent || '';
                    const status = cells[5].querySelector('.status-badge')?.textContent || '';
                    
                    csv += `"${id}","${name}","${username}","${email}","${role}","${status}"\n`;
                }
            });
            
            // Create download link
            const blob = new Blob([csv], { type: 'text/csv' });
            const url = window.URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = `user_roles_${new Date().toISOString().split('T')[0]}.csv`;
            document.body.appendChild(a);
            a.click();
            document.body.removeChild(a);
            window.URL.revokeObjectURL(url);
        }
        
        // Search functionality
        let searchTimeout;
        document.querySelector('.search-input').addEventListener('input', function() {
            clearTimeout(searchTimeout);
            searchTimeout = setTimeout(() => {
                const searchTerm = this.value.toLowerCase();
                const rows = document.querySelectorAll('.users-table tbody tr');
                
                rows.forEach(row => {
                    const name = row.querySelector('.user-name')?.textContent.toLowerCase() || '';
                    const username = row.querySelector('td:nth-child(3)')?.textContent.toLowerCase() || '';
                    const email = row.querySelector('td:nth-child(4)')?.textContent.toLowerCase() || '';
                    const role = row.querySelector('.role-badge')?.textContent.toLowerCase() || '';
                    
                    if (name.includes(searchTerm) || username.includes(searchTerm) || 
                        email.includes(searchTerm) || role.includes(searchTerm)) {
                        row.style.display = '';
                    } else {
                        row.style.display = 'none';
                    }
                });
            }, 300);
        });
    </script>
</body>
</html>