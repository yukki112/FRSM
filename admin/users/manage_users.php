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

// Search and filter parameters
$search = $_GET['search'] ?? '';
$filter_role = $_GET['role'] ?? '';
$filter_status = $_GET['status'] ?? '';
$sort_by = $_GET['sort'] ?? 'created_at';
$sort_order = $_GET['order'] ?? 'DESC';

// Build query with filters
$where_conditions = [];
$params = [];

if (!empty($search)) {
    $where_conditions[] = "(username LIKE ? OR email LIKE ? OR first_name LIKE ? OR last_name LIKE ?)";
    $search_param = "%$search%";
    $params[] = $search_param;
    $params[] = $search_param;
    $params[] = $search_param;
    $params[] = $search_param;
}

if (!empty($filter_role) && $filter_role !== 'all') {
    $where_conditions[] = "role = ?";
    $params[] = $filter_role;
}

if (!empty($filter_status) && $filter_status !== 'all') {
    if ($filter_status === 'active') {
        $where_conditions[] = "is_verified = 1";
    } elseif ($filter_status === 'inactive') {
        $where_conditions[] = "is_verified = 0";
    }
}

$where_sql = '';
if (!empty($where_conditions)) {
    $where_sql = ' WHERE ' . implode(' AND ', $where_conditions);
}

// Count total users
$count_query = "SELECT COUNT(*) as total FROM users" . $where_sql;
$count_stmt = $pdo->prepare($count_query);
$count_stmt->execute($params);
$total_users = $count_stmt->fetch()['total'];

// Pagination
$per_page = 10;
$total_pages = ceil($total_users / $per_page);
$current_page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$offset = ($current_page - 1) * $per_page;

// Get users with pagination - FIXED LIMIT/OFFSET ISSUE
$order_sql = "ORDER BY $sort_by $sort_order";
$query = "SELECT *, 
          CONCAT(first_name, ' ', COALESCE(middle_name, ''), ' ', last_name) as full_name,
          CASE 
            WHEN is_verified = 1 THEN 'Active'
            ELSE 'Inactive'
          END as status_display
          FROM users 
          $where_sql 
          $order_sql 
          LIMIT $per_page OFFSET $offset";

$stmt = $pdo->prepare($query);
$stmt->execute($params);
$users = $stmt->fetchAll();

// Handle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        $action = $_POST['action'];
        $target_user_id = $_POST['user_id'] ?? 0;
        $admin_password = $_POST['admin_password'] ?? '';
        
        // Verify admin password first
        $verify_query = "SELECT password FROM users WHERE id = ?";
        $verify_stmt = $pdo->prepare($verify_query);
        $verify_stmt->execute([$user_id]);
        $admin_data = $verify_stmt->fetch();
        
        if (!$admin_data || !password_verify($admin_password, $admin_data['password'])) {
            $message = "Incorrect password. Action cancelled.";
            $message_type = 'error';
        } else {
            switch ($action) {
                case 'deactivate':
                    $update_query = "UPDATE users SET is_verified = 0 WHERE id = ?";
                    $update_stmt = $pdo->prepare($update_query);
                    if ($update_stmt->execute([$target_user_id])) {
                        $message = "User deactivated successfully.";
                        $message_type = 'success';
                        header("Location: manage_users.php?message=" . urlencode($message) . "&type=" . $message_type);
                        exit();
                    }
                    break;
                    
                case 'activate':
                    $update_query = "UPDATE users SET is_verified = 1 WHERE id = ?";
                    $update_stmt = $pdo->prepare($update_query);
                    if ($update_stmt->execute([$target_user_id])) {
                        $message = "User activated successfully.";
                        $message_type = 'success';
                        header("Location: manage_users.php?message=" . urlencode($message) . "&type=" . $message_type);
                        exit();
                    }
                    break;
                    
                case 'delete':
                    // Soft delete - set is_verified to 0 and mark as deleted
                    $update_query = "UPDATE users SET is_verified = 0, email = CONCAT(email, '_deleted_', UNIX_TIMESTAMP()) WHERE id = ?";
                    $update_stmt = $pdo->prepare($update_query);
                    if ($update_stmt->execute([$target_user_id])) {
                        $message = "User deleted successfully.";
                        $message_type = 'success';
                        header("Location: manage_users.php?message=" . urlencode($message) . "&type=" . $message_type);
                        exit();
                    }
                    break;
                    
                case 'reset_password':
                    $new_password = bin2hex(random_bytes(8));
                    $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
                    $update_query = "UPDATE users SET password = ?, reset_token = NULL WHERE id = ?";
                    $update_stmt = $pdo->prepare($update_query);
                    if ($update_stmt->execute([$hashed_password, $target_user_id])) {
                        $message = "Password reset successfully. New password: " . htmlspecialchars($new_password);
                        $message_type = 'success';
                        header("Location: manage_users.php?message=" . urlencode($message) . "&type=" . $message_type);
                        exit();
                    }
                    break;
            }
        }
    }
}

// Handle edit user form submission
if (isset($_POST['edit_user'])) {
    $edit_user_id = $_POST['edit_user_id'];
    $edit_admin_password = $_POST['edit_admin_password'];
    
    // Verify admin password
    $verify_query = "SELECT password FROM users WHERE id = ?";
    $verify_stmt = $pdo->prepare($verify_query);
    $verify_stmt->execute([$user_id]);
    $admin_data = $verify_stmt->fetch();
    
    if (!$admin_data || !password_verify($edit_admin_password, $admin_data['password'])) {
        $message = "Incorrect password. Update cancelled.";
        $message_type = 'error';
    } else {
        $update_data = [];
        $update_params = [];
        
        $update_fields = [
            'edit_first_name' => 'first_name',
            'edit_middle_name' => 'middle_name', 
            'edit_last_name' => 'last_name',
            'edit_email' => 'email',
            'edit_contact' => 'contact',
            'edit_address' => 'address',
            'edit_role' => 'role'
        ];
        
        foreach ($update_fields as $post_field => $db_field) {
            if (isset($_POST[$post_field]) && $_POST[$post_field] !== '') {
                $update_data[] = "$db_field = ?";
                $update_params[] = $_POST[$post_field];
            }
        }
        
        if (!empty($update_data)) {
            $update_query = "UPDATE users SET " . implode(', ', $update_data) . " WHERE id = ?";
            $update_params[] = $edit_user_id;
            
            $update_stmt = $pdo->prepare($update_query);
            if ($update_stmt->execute($update_params)) {
                $message = "User updated successfully.";
                $message_type = 'success';
                header("Location: manage_users.php?message=" . urlencode($message) . "&type=" . $message_type);
                exit();
            } else {
                $message = "Failed to update user. Database error.";
                $message_type = 'error';
            }
        } else {
            $message = "No fields to update.";
            $message_type = 'error';
        }
    }
}

// Handle add user form submission
if (isset($_POST['add_user'])) {
    $add_admin_password = $_POST['add_admin_password'];
    
    // Verify admin password
    $verify_query = "SELECT password FROM users WHERE id = ?";
    $verify_stmt = $pdo->prepare($verify_query);
    $verify_stmt->execute([$user_id]);
    $admin_data = $verify_stmt->fetch();
    
    if (!$admin_data || !password_verify($add_admin_password, $admin_data['password'])) {
        $message = "Incorrect password. User creation cancelled.";
        $message_type = 'error';
    } else {
        // Check if username or email already exists
        $check_query = "SELECT id FROM users WHERE username = ? OR email = ?";
        $check_stmt = $pdo->prepare($check_query);
        $check_stmt->execute([$_POST['add_username'], $_POST['add_email']]);
        if ($check_stmt->fetch()) {
            $message = "Username or email already exists.";
            $message_type = 'error';
        } else {
            // Generate random password
            $temp_password = bin2hex(random_bytes(8));
            $hashed_password = password_hash($temp_password, PASSWORD_DEFAULT);
            
            $insert_query = "INSERT INTO users (first_name, middle_name, last_name, username, email, contact, address, date_of_birth, password, role, is_verified, created_at) 
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, NOW())";
            
            $insert_stmt = $pdo->prepare($insert_query);
            $success = $insert_stmt->execute([
                $_POST['add_first_name'],
                $_POST['add_middle_name'] ?? null,
                $_POST['add_last_name'],
                $_POST['add_username'],
                $_POST['add_email'],
                $_POST['add_contact'],
                $_POST['add_address'],
                $_POST['add_date_of_birth'],
                $hashed_password,
                $_POST['add_role']
            ]);
            
            if ($success) {
                $message = "User created successfully. Temporary password: " . htmlspecialchars($temp_password);
                $message_type = 'success';
                header("Location: manage_users.php?message=" . urlencode($message) . "&type=" . $message_type);
                exit();
            } else {
                $message = "Failed to create user. Please check the data.";
                $message_type = 'error';
            }
        }
    }
}

// Check for URL parameters for messages
if (isset($_GET['message'])) {
    $message = urldecode($_GET['message']);
    $message_type = $_GET['type'] ?? 'success';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Users - FRSM</title>
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

        /* User Management Styles */
        .user-management-container {
            padding: 0 40px 40px;
        }

        .user-controls {
            display: flex;
            gap: 20px;
            margin-bottom: 30px;
            flex-wrap: wrap;
        }

        .search-box {
            flex: 1;
            min-width: 300px;
            position: relative;
        }

        .search-input {
            width: 100%;
            padding: 12px 16px 12px 44px;
            border: 2px solid var(--gray-300);
            border-radius: 12px;
            font-size: 14px;
            transition: all 0.3s ease;
            background: var(--background-color);
            color: var(--text-color);
        }

        .dark-mode .search-input {
            border-color: #475569;
            background: #1e293b;
        }

        .search-input:focus {
            outline: none;
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(220, 38, 38, 0.1);
        }

        .search-icon {
            position: absolute;
            left: 16px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--gray-400);
        }

        .filter-group {
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
        }

        .filter-select {
            padding: 12px 16px;
            border: 2px solid var(--gray-300);
            border-radius: 12px;
            font-size: 14px;
            background: var(--background-color);
            color: var(--text-color);
            cursor: pointer;
        }

        .dark-mode .filter-select {
            border-color: #475569;
            background: #1e293b;
        }

        .btn-add-user {
            padding: 12px 24px;
            background: var(--primary-color);
            color: white;
            border: none;
            border-radius: 12px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .btn-add-user:hover {
            background: var(--primary-dark);
            transform: translateY(-2px);
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
            cursor: pointer;
            user-select: none;
        }

        .users-table th:hover {
            background: rgba(255, 255, 255, 0.15);
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
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .role-admin {
            background: rgba(220, 38, 38, 0.1);
            color: var(--primary-color);
        }

        .role-employee {
            background: rgba(59, 130, 246, 0.1);
            color: var(--icon-blue);
        }

        .role-user {
            background: rgba(16, 185, 129, 0.1);
            color: var(--success);
        }

        .status-badge {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
        }

        .status-active {
            background: rgba(16, 185, 129, 0.1);
            color: var(--success);
        }

        .status-inactive {
            background: rgba(220, 38, 38, 0.1);
            color: var(--danger);
        }

        /* Action Buttons */
        .action-buttons {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
        }

        .btn-action {
            padding: 6px 12px;
            border: none;
            border-radius: 8px;
            font-size: 12px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 4px;
        }

        .btn-edit {
            background: rgba(59, 130, 246, 0.1);
            color: var(--icon-blue);
        }

        .btn-edit:hover {
            background: rgba(59, 130, 246, 0.2);
        }

        .btn-reset {
            background: rgba(245, 158, 11, 0.1);
            color: var(--warning);
        }

        .btn-reset:hover {
            background: rgba(245, 158, 11, 0.2);
        }

        .btn-toggle-status {
            background: rgba(16, 185, 129, 0.1);
            color: var(--success);
        }

        .btn-toggle-status:hover {
            background: rgba(16, 185, 129, 0.2);
        }

        .btn-delete {
            background: rgba(220, 38, 38, 0.1);
            color: var(--danger);
        }

        .btn-delete:hover {
            background: rgba(220, 38, 38, 0.2);
        }

        /* Pagination */
        .pagination {
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 12px;
            padding: 20px;
        }

        .pagination-btn {
            padding: 8px 16px;
            border: 2px solid var(--gray-300);
            border-radius: 8px;
            background: var(--background-color);
            color: var(--text-color);
            cursor: pointer;
            transition: all 0.3s ease;
            font-weight: 600;
        }

        .dark-mode .pagination-btn {
            border-color: #475569;
            background: #1e293b;
        }

        .pagination-btn:hover:not(:disabled) {
            border-color: var(--primary-color);
            color: var(--primary-color);
        }

        .pagination-btn:disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }

        .pagination-info {
            color: var(--text-light);
            font-size: 14px;
        }

        /* Modals */
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
            max-width: 500px;
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
        }

        .modal-icon.warning {
            background: linear-gradient(135deg, #f59e0b, #d97706);
        }

        .modal-icon.success {
            background: linear-gradient(135deg, #10b981, #059669);
        }

        .modal-icon.danger {
            background: linear-gradient(135deg, #dc2626, #991b1b);
        }

        .modal-icon.info {
            background: linear-gradient(135deg, #3b82f6, #1d4ed8);
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
            border: 2px solid var(--gray-300);
            border-radius: 12px;
            font-size: 1rem;
            transition: all 0.3s ease;
            background: var(--background-color);
            color: var(--text-color);
            margin-bottom: 15px;
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

        .btn-modal-confirm.warning {
            background: linear-gradient(135deg, #f59e0b, #d97706);
        }

        .btn-modal-confirm.warning:hover {
            background: linear-gradient(135deg, #d97706, #b45309);
        }

        .btn-modal-confirm.success {
            background: linear-gradient(135deg, #10b981, #059669);
        }

        .btn-modal-confirm.success:hover {
            background: linear-gradient(135deg, #059669, #047857);
        }

        .btn-modal-confirm.danger {
            background: linear-gradient(135deg, #dc2626, #991b1b);
        }

        .btn-modal-confirm.danger:hover {
            background: linear-gradient(135deg, #991b1b, #7f1d1d);
        }

        /* Edit/Add User Form */
        .form-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 20px;
            margin-bottom: 20px;
        }

        .form-group {
            margin-bottom: 15px;
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

        .form-textarea {
            min-height: 100px;
            resize: vertical;
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

        /* Responsive Design */
        @media (max-width: 768px) {
            .dashboard-header {
                padding: 40px 25px 30px;
                border-radius: 0 0 20px 20px;
            }
            
            .dashboard-title {
                font-size: 2.2rem;
            }
            
            .user-management-container {
                padding: 0 25px 30px;
            }
            
            .user-controls {
                flex-direction: column;
            }
            
            .search-box {
                min-width: 100%;
            }
            
            .form-grid {
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
    
    <!-- Action Confirmation Modal -->
    <div class="modal-overlay" id="confirmation-modal">
        <div class="modal">
            <div class="modal-header">
                <div class="modal-icon" id="modal-icon">
                    <i class='bx bxs-lock'></i>
                </div>
                <h3 class="modal-title" id="modal-title">Confirm Action</h3>
            </div>
            <form method="POST" id="action-form">
                <input type="hidden" name="action" id="action-type">
                <input type="hidden" name="user_id" id="target-user-id">
                <div class="modal-body">
                    <p class="modal-message" id="modal-message">Are you sure you want to perform this action?</p>
                    <input type="password" class="password-input" name="admin_password" placeholder="Enter your password to confirm" required autocomplete="off">
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn-modal btn-modal-cancel" id="modal-cancel">Cancel</button>
                    <button type="submit" class="btn-modal btn-modal-confirm" id="modal-confirm">Confirm</button>
                </div>
            </form>
        </div>
    </div>
    
    <!-- Edit User Modal -->
    <div class="modal-overlay" id="edit-user-modal">
        <div class="modal" style="max-width: 600px;">
            <div class="modal-header">
                <div class="modal-icon info">
                    <i class='bx bxs-edit'></i>
                </div>
                <h3 class="modal-title">Edit User</h3>
            </div>
            <form method="POST" id="edit-user-form">
                <input type="hidden" name="edit_user" value="1">
                <input type="hidden" name="edit_user_id" id="edit-user-id">
                <div class="modal-body">
                    <div class="form-grid">
                        <div class="form-group">
                            <label class="form-label">First Name *</label>
                            <input type="text" class="form-input" name="edit_first_name" id="edit-first-name" required>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Middle Name</label>
                            <input type="text" class="form-input" name="edit_middle_name" id="edit-middle-name">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Last Name *</label>
                            <input type="text" class="form-input" name="edit_last_name" id="edit-last-name" required>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Email *</label>
                            <input type="email" class="form-input" name="edit_email" id="edit-email" required>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Contact Number *</label>
                            <input type="text" class="form-input" name="edit_contact" id="edit-contact" required>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Role *</label>
                            <select class="form-select" name="edit_role" id="edit-role" required>
                                <option value="USER">User</option>
                                <option value="EMPLOYEE">Employee</option>
                                <option value="ADMIN">Admin</option>
                            </select>
                        </div>
                        <div class="form-group form-full-width">
                            <label class="form-label">Address *</label>
                            <textarea class="form-input form-textarea" name="edit_address" id="edit-address" required rows="3"></textarea>
                        </div>
                        <div class="form-group form-full-width">
                            <label class="form-label">Admin Password *</label>
                            <input type="password" class="password-input" name="edit_admin_password" placeholder="Enter your password to save changes" required autocomplete="off">
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn-modal btn-modal-cancel" id="edit-modal-cancel">Cancel</button>
                    <button type="submit" class="btn-modal btn-modal-confirm success">Save Changes</button>
                </div>
            </form>
        </div>
    </div>
    
    <!-- Add User Modal -->
    <div class="modal-overlay" id="add-user-modal">
        <div class="modal" style="max-width: 600px;">
            <div class="modal-header">
                <div class="modal-icon success">
                    <i class='bx bxs-user-plus'></i>
                </div>
                <h3 class="modal-title">Add New User</h3>
            </div>
            <form method="POST" id="add-user-form">
                <input type="hidden" name="add_user" value="1">
                <div class="modal-body">
                    <div class="form-grid">
                        <div class="form-group">
                            <label class="form-label">First Name *</label>
                            <input type="text" class="form-input" name="add_first_name" required>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Middle Name</label>
                            <input type="text" class="form-input" name="add_middle_name">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Last Name *</label>
                            <input type="text" class="form-input" name="add_last_name" required>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Username *</label>
                            <input type="text" class="form-input" name="add_username" required>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Email *</label>
                            <input type="email" class="form-input" name="add_email" required>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Contact Number *</label>
                            <input type="text" class="form-input" name="add_contact" required>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Date of Birth *</label>
                            <input type="date" class="form-input" name="add_date_of_birth" required>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Role *</label>
                            <select class="form-select" name="add_role" required>
                                <option value="USER">User</option>
                                <option value="EMPLOYEE">Employee</option>
                                <option value="ADMIN">Admin</option>
                            </select>
                        </div>
                        <div class="form-group form-full-width">
                            <label class="form-label">Address *</label>
                            <textarea class="form-input form-textarea" name="add_address" required rows="3"></textarea>
                        </div>
                        <div class="form-group form-full-width">
                            <label class="form-label">Admin Password *</label>
                            <input type="password" class="password-input" name="add_admin_password" placeholder="Enter your password to create user" required autocomplete="off">
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn-modal btn-modal-cancel" id="add-modal-cancel">Cancel</button>
                    <button type="submit" class="btn-modal btn-modal-confirm success">Create User</button>
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
                    <a href="../admin/dashboard.php" class="menu-item">
                        <div class="icon-box icon-bg-red">
                            <i class='bx bxs-dashboard icon-red'></i>
                        </div>
                        <span class="font-medium">Dashboard</span>
                    </a>
                    
                    <!-- User Management -->
                    <div class="menu-item active" onclick="toggleSubmenu('user-management')">
                        <div class="icon-box icon-bg-orange">
                            <i class='bx bxs-user icon-orange'></i>
                        </div>
                        <span class="font-medium">User Management</span>
                        <svg class="dropdown-arrow menu-icon rotated" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                        </svg>
                    </div>
                    <div id="user-management" class="submenu active">
                        <a href="manage_users.php" class="submenu-item active">Manage Users</a>
                        <a href="role_control.php" class="submenu-item">Role Control</a>
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
                        <a href="#" class="submenu-item">View Reports</a>
                        <a href="#" class="submenu-item">Validate Data</a>
                        <a href="#" class="submenu-item">Assign Severity</a>
                        <a href="#" class="submenu-item">Track Progress</a>
                        <a href="#" class="submenu-item">Mark Resolved</a>
                    </div>
                    
                    <!-- Volunteer Management -->
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
                        <a href="../volunteer/review_data.php" class="submenu-item">Review Data</a>
                        <a href="../volunteer/approve_applications.php" class="submenu-item">Approve Applications</a>
                        <a href="../volunteer/assign_volunteers.php" class="submenu-item">Assign Volunteers</a>
                        <a href="../volunteer/view_availability.php" class="submenu-item">View Availability</a>
                        <a href="../volunteer/remove_volunteers.php" class="submenu-item">Remove Volunteers</a>
                        <a href="../volunteer/toggle_volunteer_registration.php" class="submenu-item">Toggle Volunteer Registration Access</a>
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
                        <a href="#" class="submenu-item">View Equipment</a>
                        <a href="#" class="submenu-item">Approve Maintenance</a>
                        <a href="#" class="submenu-item">Approve Resources</a>
                        <a href="#" class="submenu-item">Review Deployment</a>
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
                        <a href="#" class="submenu-item">Create Schedule</a>
                        <a href="#" class="submenu-item">Approve Shifts</a>
                        <a href="#" class="submenu-item">Override Assignments</a>
                        <a href="#" class="submenu-item">Monitor Attendance</a>
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
                        <a href="#" class="submenu-item">View Records</a>
                        <a href="#" class="submenu-item">Approve Completions</a>
                        <a href="#" class="submenu-item">Assign Training</a>
                        <a href="#" class="submenu-item">Track Expiry</a>
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
                        <a href="#" class="submenu-item">Approve Reports</a>
                        <a href="#" class="submenu-item">Review Violations</a>
                        <a href="#" class="submenu-item">Issue Certificates</a>
                        <a href="#" class="submenu-item">Track Follow-Up</a>
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
                        <a href="#" class="submenu-item">Review Summaries</a>
                        <a href="#" class="submenu-item">Analyze Data</a>
                        <a href="#" class="submenu-item">Export Reports</a>
                        <a href="#" class="submenu-item">Generate Statistics</a>
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
            
            <!-- User Management Content -->
            <div class="dashboard-content">
                <!-- Hero Header -->
                <div class="dashboard-header">
                    <div>
                        <h1 class="dashboard-title">User Management</h1>
                        <p class="dashboard-subtitle">Manage all user accounts, roles, and permissions</p>
                    </div>
                </div>
                
                <!-- User Management Container -->
                <div class="user-management-container">
                    <!-- Controls -->
                    <div class="user-controls">
                        <div class="search-box">
                            <i class='bx bx-search search-icon'></i>
                            <form method="GET" id="search-form">
                                <input type="text" class="search-input" name="search" placeholder="Search users by name, email, or username..." value="<?php echo htmlspecialchars($search); ?>">
                                <input type="hidden" name="page" value="1">
                            </form>
                        </div>
                        
                        <div class="filter-group">
                            <select class="filter-select" name="role" onchange="this.form.submit()" form="search-form">
                                <option value="all" <?php echo $filter_role === 'all' || empty($filter_role) ? 'selected' : ''; ?>>All Roles</option>
                                <option value="ADMIN" <?php echo $filter_role === 'ADMIN' ? 'selected' : ''; ?>>Admin</option>
                                <option value="EMPLOYEE" <?php echo $filter_role === 'EMPLOYEE' ? 'selected' : ''; ?>>Employee</option>
                                <option value="USER" <?php echo $filter_role === 'USER' ? 'selected' : ''; ?>>User</option>
                            </select>
                            
                            <select class="filter-select" name="status" onchange="this.form.submit()" form="search-form">
                                <option value="all" <?php echo $filter_status === 'all' || empty($filter_status) ? 'selected' : ''; ?>>All Status</option>
                                <option value="active" <?php echo $filter_status === 'active' ? 'selected' : ''; ?>>Active</option>
                                <option value="inactive" <?php echo $filter_status === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                            </select>
                            
                            <button type="button" class="btn-add-user" id="add-user-btn">
                                <i class='bx bx-user-plus'></i>
                                Add User
                            </button>
                        </div>
                    </div>
                    
                    <!-- Users Table -->
                    <div class="users-table-container">
                        <table class="users-table">
                            <thead>
                                <tr>
                                    <th>User</th>
                                    <th>Username</th>
                                    <th>Email</th>
                                    <th>Contact</th>
                                    <th>Role</th>
                                    <th>Status</th>
                                    <th>Created</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($users)): ?>
                                    <tr>
                                        <td colspan="8" style="text-align: center; padding: 40px;">
                                            No users found matching your criteria.
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($users as $user): ?>
                                        <tr>
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
                                            <td><?php echo htmlspecialchars($user['contact']); ?></td>
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
                                            <td><?php echo date('M j, Y', strtotime($user['created_at'])); ?></td>
                                            <td>
                                                <div class="action-buttons">
                                                    <button type="button" class="btn-action btn-edit" 
                                                            onclick="openEditModal(<?php echo $user['id']; ?>, '<?php echo addslashes($user['first_name']); ?>', '<?php echo addslashes($user['middle_name'] ?? ''); ?>', '<?php echo addslashes($user['last_name']); ?>', '<?php echo addslashes($user['email']); ?>', '<?php echo addslashes($user['contact']); ?>', '<?php echo addslashes($user['address']); ?>', '<?php echo $user['role']; ?>')">
                                                        <i class='bx bx-edit'></i> Edit
                                                    </button>
                                                    
                                                    <button type="button" class="btn-action btn-reset" 
                                                            onclick="confirmAction('reset_password', <?php echo $user['id']; ?>, 'Are you sure you want to reset password for <?php echo addslashes($user['full_name']); ?>? A new password will be generated and displayed.')">
                                                        <i class='bx bx-refresh'></i> Reset
                                                    </button>
                                                    
                                                    <?php if ($user['is_verified']): ?>
                                                        <button type="button" class="btn-action btn-toggle-status" 
                                                                onclick="confirmAction('deactivate', <?php echo $user['id']; ?>, 'Are you sure you want to deactivate <?php echo addslashes($user['full_name']); ?>?')">
                                                            <i class='bx bx-user-x'></i> Deactivate
                                                        </button>
                                                    <?php else: ?>
                                                        <button type="button" class="btn-action btn-toggle-status" 
                                                                onclick="confirmAction('activate', <?php echo $user['id']; ?>, 'Are you sure you want to activate <?php echo addslashes($user['full_name']); ?>?')">
                                                            <i class='bx bx-user-check'></i> Activate
                                                        </button>
                                                    <?php endif; ?>
                                                    
                                                    <button type="button" class="btn-action btn-delete" 
                                                            onclick="confirmAction('delete', <?php echo $user['id']; ?>, 'Are you sure you want to delete <?php echo addslashes($user['full_name']); ?>? This action cannot be undone.')">
                                                        <i class='bx bx-trash'></i> Delete
                                                    </button>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                    
                    <!-- Pagination -->
                    <?php if ($total_pages > 1): ?>
                        <div class="pagination">
                            <button type="button" class="pagination-btn" 
                                    onclick="changePage(<?php echo max(1, $current_page - 1); ?>)" 
                                    <?php echo $current_page <= 1 ? 'disabled' : ''; ?>>
                                <i class='bx bx-chevron-left'></i> Previous
                            </button>
                            
                            <span class="pagination-info">
                                Page <?php echo $current_page; ?> of <?php echo $total_pages; ?>
                                (<?php echo $total_users; ?> total users)
                            </span>
                            
                            <button type="button" class="pagination-btn" 
                                    onclick="changePage(<?php echo min($total_pages, $current_page + 1); ?>)"
                                    <?php echo $current_page >= $total_pages ? 'disabled' : ''; ?>>
                                Next <i class='bx bx-chevron-right'></i>
                            </button>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Initialize modals
            initModals();
            
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
        });
        
        function initModals() {
            const confirmationModal = document.getElementById('confirmation-modal');
            const editModal = document.getElementById('edit-user-modal');
            const addModal = document.getElementById('add-user-modal');
            const addUserBtn = document.getElementById('add-user-btn');
            
            // Action confirmation modal
            window.confirmAction = function(action, userId, message) {
                const modal = confirmationModal;
                const modalIcon = modal.querySelector('.modal-icon');
                const modalTitle = modal.querySelector('.modal-title');
                const modalMessage = modal.querySelector('.modal-message');
                const confirmBtn = modal.querySelector('.btn-modal-confirm');
                
                // Set modal content based on action
                switch(action) {
                    case 'deactivate':
                        modalIcon.className = 'modal-icon warning';
                        modalIcon.innerHTML = '<i class="bx bxs-user-x"></i>';
                        modalTitle.textContent = 'Deactivate User';
                        modalMessage.textContent = message;
                        confirmBtn.className = 'btn-modal btn-modal-confirm warning';
                        confirmBtn.textContent = 'Deactivate';
                        break;
                        
                    case 'activate':
                        modalIcon.className = 'modal-icon success';
                        modalIcon.innerHTML = '<i class="bx bxs-user-check"></i>';
                        modalTitle.textContent = 'Activate User';
                        modalMessage.textContent = message;
                        confirmBtn.className = 'btn-modal btn-modal-confirm success';
                        confirmBtn.textContent = 'Activate';
                        break;
                        
                    case 'delete':
                        modalIcon.className = 'modal-icon danger';
                        modalIcon.innerHTML = '<i class="bx bxs-trash"></i>';
                        modalTitle.textContent = 'Delete User';
                        modalMessage.textContent = message;
                        confirmBtn.className = 'btn-modal btn-modal-confirm danger';
                        confirmBtn.textContent = 'Delete';
                        break;
                        
                    case 'reset_password':
                        modalIcon.className = 'modal-icon info';
                        modalIcon.innerHTML = '<i class="bx bxs-key"></i>';
                        modalTitle.textContent = 'Reset Password';
                        modalMessage.textContent = message;
                        confirmBtn.className = 'btn-modal btn-modal-confirm info';
                        confirmBtn.textContent = 'Reset';
                        break;
                }
                
                // Set form values
                document.getElementById('action-type').value = action;
                document.getElementById('target-user-id').value = userId;
                
                // Clear password field
                modal.querySelector('.password-input').value = '';
                
                // Show modal
                modal.classList.add('active');
                setTimeout(() => modal.querySelector('.password-input').focus(), 300);
            };
            
            // Close confirmation modal
            confirmationModal.querySelector('#modal-cancel').addEventListener('click', function() {
                confirmationModal.classList.remove('active');
            });
            
            confirmationModal.addEventListener('click', function(e) {
                if (e.target === confirmationModal) {
                    confirmationModal.classList.remove('active');
                }
            });
            
            // Edit user modal
            window.openEditModal = function(userId, firstName, middleName, lastName, email, contact, address, role) {
                document.getElementById('edit-user-id').value = userId;
                document.getElementById('edit-first-name').value = firstName;
                document.getElementById('edit-middle-name').value = middleName || '';
                document.getElementById('edit-last-name').value = lastName;
                document.getElementById('edit-email').value = email;
                document.getElementById('edit-contact').value = contact;
                document.getElementById('edit-address').value = address;
                document.getElementById('edit-role').value = role;
                
                editModal.classList.add('active');
                setTimeout(() => document.getElementById('edit-first-name').focus(), 300);
            };
            
            // Close edit modal
            editModal.querySelector('#edit-modal-cancel').addEventListener('click', function() {
                editModal.classList.remove('active');
            });
            
            editModal.addEventListener('click', function(e) {
                if (e.target === editModal) {
                    editModal.classList.remove('active');
                }
            });
            
            // Add user modal
            addUserBtn.addEventListener('click', function() {
                // Clear the form
                const addForm = document.getElementById('add-user-form');
                addForm.reset();
                
                // Set today's date as default for date of birth
                const today = new Date().toISOString().split('T')[0];
                addForm.querySelector('input[name="add_date_of_birth"]').value = today;
                
                addModal.classList.add('active');
                setTimeout(() => addForm.querySelector('input[name="add_first_name"]').focus(), 300);
            });
            
            // Close add modal
            addModal.querySelector('#add-modal-cancel').addEventListener('click', function() {
                addModal.classList.remove('active');
            });
            
            addModal.addEventListener('click', function(e) {
                if (e.target === addModal) {
                    addModal.classList.remove('active');
                }
            });
            
            // Prevent form submission from closing modal
            document.querySelectorAll('.modal form').forEach(form => {
                form.addEventListener('submit', function(e) {
                    // Don't prevent default - let the form submit normally
                });
            });
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
        
        function changePage(page) {
            const form = document.getElementById('search-form');
            form.querySelector('input[name="page"]').value = page;
            form.submit();
        }
        
        // Auto-submit search when typing stops
        let searchTimeout;
        document.querySelector('.search-input').addEventListener('input', function() {
            clearTimeout(searchTimeout);
            searchTimeout = setTimeout(() => {
                document.getElementById('search-form').querySelector('input[name="page"]').value = 1;
                document.getElementById('search-form').submit();
            }, 500);
        });
        
        // Sort table headers
        document.querySelectorAll('.users-table th').forEach((th, index) => {
            th.addEventListener('click', function() {
                let sortField;
                switch(index) {
                    case 0: sortField = 'first_name'; break;
                    case 1: sortField = 'username'; break;
                    case 2: sortField = 'email'; break;
                    case 3: sortField = 'contact'; break;
                    case 4: sortField = 'role'; break;
                    case 5: sortField = 'is_verified'; break;
                    case 6: sortField = 'created_at'; break;
                    default: return;
                }
                
                const url = new URL(window.location);
                url.searchParams.set('sort', sortField);
                url.searchParams.set('order', url.searchParams.get('sort') === sortField && url.searchParams.get('order') === 'ASC' ? 'DESC' : 'ASC');
                window.location = url.toString();
            });
        });
    </script>
</body>
</html>