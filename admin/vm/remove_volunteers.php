<?php
session_start();
require_once '../../config/db_connection.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: ../../login/login.php");
    exit();
}

$user_id = $_SESSION['user_id'];
$query = "SELECT first_name, middle_name, last_name, role, avatar FROM users WHERE id = ?";
$stmt = $pdo->prepare($query);
$stmt->execute([$user_id]);
$user = $stmt->fetch();

if ($user) {
    $first_name = htmlspecialchars($user['first_name']);
    $middle_name = htmlspecialchars($user['middle_name']);
    $last_name = htmlspecialchars($user['last_name']);
    $role = htmlspecialchars($user['role']);
    $avatar = htmlspecialchars($user['avatar']);
    
    $full_name = $first_name;
    if (!empty($middle_name)) {
        $full_name .= " " . $middle_name;
    }
    $full_name .= " " . $last_name;
} else {
    $full_name = "User";
    $role = "USER";
    $avatar = "";
}

// Handle volunteer removal
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action']) && isset($_POST['volunteer_id'])) {
        $volunteer_id = intval($_POST['volunteer_id']);
        $action = $_POST['action'];
        
        try {
            if ($action === 'deactivate') {
                // Deactivate volunteer (make inactive)
                $update_query = "UPDATE volunteers SET volunteer_status = 'Inactive' WHERE id = ?";
                $stmt = $pdo->prepare($update_query);
                $stmt->execute([$volunteer_id]);
                
                $_SESSION['success_message'] = "Volunteer has been deactivated successfully.";
                
            } elseif ($action === 'delete') {
                // Get volunteer email for user deactivation
                $email_query = "SELECT email FROM volunteers WHERE id = ?";
                $stmt = $pdo->prepare($email_query);
                $stmt->execute([$volunteer_id]);
                $volunteer = $stmt->fetch();
                
                if ($volunteer) {
                    // Deactivate user account if exists
                    $user_deactivate_query = "UPDATE users SET is_verified = 0 WHERE email = ?";
                    $stmt = $pdo->prepare($user_deactivate_query);
                    $stmt->execute([$volunteer['email']]);
                    
                    // Delete volunteer assignments
                    $delete_assignments_query = "DELETE FROM volunteer_assignments WHERE volunteer_id = ?";
                    $stmt = $pdo->prepare($delete_assignments_query);
                    $stmt->execute([$volunteer_id]);
                    
                    // Delete volunteer record
                    $delete_volunteer_query = "DELETE FROM volunteers WHERE id = ?";
                    $stmt = $pdo->prepare($delete_volunteer_query);
                    $stmt->execute([$volunteer_id]);
                    
                    $_SESSION['success_message'] = "Volunteer has been permanently removed and user account deactivated.";
                }
            }
            
            // Redirect to prevent form resubmission
            header("Location: remove_volunteers.php?success=1");
            exit();
            
        } catch (PDOException $e) {
            $_SESSION['error_message'] = "Error: " . $e->getMessage();
            header("Location: remove_volunteers.php?error=1");
            exit();
        }
    }
}

// Get filter parameters
$status_filter = isset($_GET['status']) ? $_GET['status'] : 'all';
$search_term = isset($_GET['search']) ? $_GET['search'] : '';
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$limit = 10;
$offset = ($page - 1) * $limit;

// Build query with filters
$where_conditions = [];
$params = [];

if (!empty($status_filter) && $status_filter !== 'all') {
    $where_conditions[] = "volunteer_status = ?";
    $params[] = $status_filter;
}

if (!empty($search_term)) {
    $where_conditions[] = "(CONCAT(first_name, ' ', COALESCE(middle_name, ''), ' ', last_name) LIKE ? OR email LIKE ? OR contact_number LIKE ?)";
    $params[] = "%$search_term%";
    $params[] = "%$search_term%";
    $params[] = "%$search_term%";
}

$where_clause = '';
if (!empty($where_conditions)) {
    $where_clause = "WHERE " . implode(" AND ", $where_conditions);
}

// Count total volunteers for pagination
$count_query = "SELECT COUNT(*) as total FROM volunteers $where_clause";
$count_stmt = $pdo->prepare($count_query);
$count_stmt->execute($params);
$total_volunteers = $count_stmt->fetch()['total'];
$total_pages = ceil($total_volunteers / $limit);

// Fetch volunteers with filters and pagination
$volunteers_query = "SELECT *, CONCAT(first_name, ' ', COALESCE(middle_name, ''), ' ', last_name) as full_name FROM volunteers $where_clause ORDER BY created_at DESC LIMIT ? OFFSET ?";
$volunteers_stmt = $pdo->prepare($volunteers_query);

// Bind parameters
$param_types = array_fill(0, count($params), PDO::PARAM_STR);
$param_types[] = PDO::PARAM_INT; // For LIMIT
$param_types[] = PDO::PARAM_INT; // For OFFSET

$all_params = array_merge($params, [$limit, $offset]);

// Execute with parameter types
foreach ($all_params as $key => $value) {
    $volunteers_stmt->bindValue($key + 1, $value, $param_types[$key] ?? PDO::PARAM_STR);
}

$volunteers_stmt->execute();
$volunteers = $volunteers_stmt->fetchAll();

// Get counts for each status
$status_counts_query = "SELECT volunteer_status, COUNT(*) as count FROM volunteers GROUP BY volunteer_status";
$status_counts_stmt = $pdo->prepare($status_counts_query);
$status_counts_stmt->execute();
$status_counts = $status_counts_stmt->fetchAll(PDO::FETCH_KEY_PAIR);

$stmt = null;
$volunteers_stmt = null;
$status_counts_stmt = null;
$count_stmt = null;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Remove Volunteers - Fire & Rescue Services</title>
    <link href='https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css' rel='stylesheet'>
    <link rel="icon" type="image/png" sizes="32x32" href="../../img/frsm-logo.png">
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
            overflow-x: hidden;
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

        .availability-container {
            display: flex;
            flex-direction: column;
            gap: 24px;
            padding: 0 40px 40px;
        }
        
        .availability-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 24px;
        }
        
        .availability-title {
            font-size: 28px;
            font-weight: 800;
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            margin-bottom: 8px;
        }
        
        .availability-subtitle {
            color: var(--text-light);
            font-size: 16px;
        }
        
        .filters-container {
            display: flex;
            gap: 16px;
            margin-bottom: 24px;
            flex-wrap: wrap;
            align-items: flex-end;
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: 16px;
            padding: 20px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
        }
        
        .filter-group {
            display: flex;
            flex-direction: column;
            gap: 8px;
        }
        
        .filter-label {
            font-size: 14px;
            font-weight: 600;
            color: var(--gray-700);
        }
        
        .dark-mode .filter-label {
            color: var(--gray-300);
        }
        
        .filter-select, .filter-input {
            padding: 10px 16px;
            border-radius: 10px;
            border: 1px solid var(--border-color);
            background: var(--card-bg);
            color: var(--text-color);
            font-size: 14px;
            min-width: 180px;
            transition: all 0.3s ease;
        }
        
        .filter-select:focus, .filter-input:focus {
            outline: none;
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(220, 38, 38, 0.1);
        }
        
        .stats-container {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(340px, 1fr));
            gap: 16px;
            margin-bottom: 24px;
        }
        
        .stat-card {
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: 16px;
            padding: 20px;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            text-align: center;
            transition: all 0.3s ease;
            cursor: pointer;
            position: relative;
            overflow: hidden;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
        }
        
        .stat-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
        }
        
        .stat-card[data-status="all"]::before {
            background: linear-gradient(90deg, var(--primary-color), var(--secondary-color));
        }
        
        .stat-card[data-status="New Volunteer"]::before {
            background: var(--warning);
        }
        
        .stat-card[data-status="Active"]::before {
            background: var(--success);
        }
        
        .stat-card[data-status="Inactive"]::before {
            background: var(--danger);
        }
        
        .stat-card[data-status="On Leave"]::before {
            background: var(--info);
        }
        
        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.1);
        }
        
        .stat-card.active {
            border-color: var(--primary-color);
            box-shadow: 0 0 0 2px rgba(220, 38, 38, 0.2);
        }
        
        .stat-icon {
            font-size: 28px;
            margin-bottom: 12px;
            padding: 12px;
            border-radius: 12px;
            background: rgba(220, 38, 38, 0.1);
            color: var(--primary-color);
            display: flex;
            align-items: center;
            justify-content: center;
            width: 52px;
            height: 52px;
            flex-shrink: 0;
        }
        
        .stat-card[data-status="New Volunteer"] .stat-icon {
            background: rgba(245, 158, 11, 0.1);
            color: var(--warning);
        }
        
        .stat-card[data-status="Active"] .stat-icon {
            background: rgba(16, 185, 129, 0.1);
            color: var(--success);
        }
        
        .stat-card[data-status="Inactive"] .stat-icon {
            background: rgba(220, 38, 38, 0.1);
            color: var(--danger);
        }
        
        .stat-card[data-status="On Leave"] .stat-icon {
            background: rgba(59, 130, 246, 0.1);
            color: var(--info);
        }
        
        .stat-value {
            font-size: 28px;
            font-weight: 800;
            margin-bottom: 4px;
        }
        
        .stat-label {
            font-size: 14px;
            color: var(--text-light);
        }
        
        .volunteers-table {
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: 16px;
            overflow: hidden;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
        }
        
        .table-header {
            display: grid;
            grid-template-columns: 1.5fr 1fr 1fr 1fr 2fr;
            gap: 16px;
            padding: 20px;
            background: rgba(220, 38, 38, 0.02);
            border-bottom: 1px solid var(--border-color);
            font-weight: 600;
            color: var(--text-color);
        }
        
        .table-row {
            display: grid;
            grid-template-columns: 1.5fr 1fr 1fr 1fr 2fr;
            gap: 16px;
            padding: 20px;
            border-bottom: 1px solid var(--border-color);
            transition: all 0.3s ease;
        }
        
        .table-row:hover {
            background: rgba(220, 38, 38, 0.03);
        }
        
        .table-row:last-child {
            border-bottom: none;
        }
        
        .table-cell {
            display: flex;
            align-items: center;
            color: var(--text-color);
        }
        
        .volunteer-avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 700;
            font-size: 16px;
            margin-right: 12px;
        }
        
        .volunteer-info {
            display: flex;
            flex-direction: column;
        }
        
        .volunteer-name {
            font-weight: 600;
            margin-bottom: 4px;
        }
        
        .volunteer-email {
            color: var(--text-light);
            font-size: 12px;
        }
        
        .status-badge {
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            text-transform: uppercase;
        }
        
        .status-new {
            background: rgba(245, 158, 11, 0.1);
            color: var(--warning);
        }
        
        .status-active {
            background: rgba(16, 185, 129, 0.1);
            color: var(--success);
        }
        
        .status-inactive {
            background: rgba(220, 38, 38, 0.1);
            color: var(--danger);
        }
        
        .status-leave {
            background: rgba(59, 130, 246, 0.1);
            color: var(--info);
        }
        
        .action-button {
            padding: 8px 12px;
            border-radius: 8px;
            font-weight: 500;
            border: none;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            transition: all 0.3s ease;
            font-size: 13px;
        }
        
        .deactivate-button {
            background-color: rgba(245, 158, 11, 0.1);
            color: var(--warning);
        }
        
        .deactivate-button:hover {
            background-color: var(--warning);
            color: white;
        }
        
        .delete-button {
            background-color: rgba(220, 38, 38, 0.1);
            color: var(--danger);
        }
        
        .delete-button:hover {
            background-color: var(--danger);
            color: white;
        }
        
        .view-button {
            background-color: rgba(59, 130, 246, 0.1);
            color: var(--info);
        }
        
        .view-button:hover {
            background-color: var(--info);
            color: white;
        }
        
        .no-volunteers {
            text-align: center;
            padding: 60px 20px;
            color: var(--text-light);
        }
        
        .no-volunteers-icon {
            font-size: 64px;
            margin-bottom: 16px;
            color: var(--text-light);
            opacity: 0.5;
        }
        
        .pagination {
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 12px;
            margin-top: 30px;
        }
        
        .pagination-button {
            padding: 8px 16px;
            border-radius: 8px;
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            color: var(--gray-700);
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 6px;
        }
        
        .pagination-button:hover:not(:disabled) {
            background: var(--primary-color);
            color: white;
            border-color: var(--primary-color);
        }
        
        .pagination-button:disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }
        
        .pagination-info {
            font-size: 14px;
            color: var(--text-light);
        }
        
        .pagination-numbers {
            display: flex;
            gap: 6px;
        }
        
        .pagination-number {
            width: 36px;
            height: 36px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 8px;
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            color: var(--gray-700);
            cursor: pointer;
            transition: all 0.3s ease;
            font-size: 14px;
        }
        
        .pagination-number.active {
            background: var(--primary-color);
            color: white;
            border-color: var(--primary-color);
        }
        
        .pagination-number:hover:not(.active) {
            background: var(--gray-100);
        }
        
        .modal-overlay {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.5);
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
            max-height: 90vh;
            overflow-y: auto;
            transform: scale(0.9);
            transition: all 0.3s ease;
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.15);
        }
        
        .modal-overlay.active .modal {
            transform: scale(1);
        }
        
        .modal-header {
            padding: 24px;
            border-bottom: 1px solid var(--border-color);
            display: flex;
            align-items: center;
            justify-content: space-between;
            background: rgba(220, 38, 38, 0.02);
        }
        
        .modal-title {
            font-size: 24px;
            font-weight: 700;
        }
        
        .modal-close {
            background: none;
            border: none;
            font-size: 24px;
            cursor: pointer;
            color: var(--text-light);
            transition: all 0.3s ease;
        }
        
        .modal-close:hover {
            color: var(--danger);
        }
        
        .modal-body {
            padding: 24px;
        }
        
        .modal-section {
            margin-bottom: 20px;
        }
        
        .modal-section-title {
            font-size: 18px;
            font-weight: 700;
            margin-bottom: 12px;
            padding-bottom: 8px;
            border-bottom: 1px solid var(--border-color);
            color: var(--primary-color);
        }
        
        .modal-detail {
            margin-bottom: 12px;
        }
        
        .modal-detail-label {
            font-size: 14px;
            color: var(--text-light);
            margin-bottom: 4px;
        }
        
        .modal-detail-value {
            font-size: 16px;
            color: var(--text-color);
            font-weight: 500;
        }
        
        .modal-footer {
            padding: 20px 24px;
            border-top: 1px solid var(--border-color);
            display: flex;
            justify-content: flex-end;
            gap: 12px;
            background: rgba(220, 38, 38, 0.02);
        }
        
        .modal-button {
            padding: 10px 20px;
            border-radius: 10px;
            font-weight: 600;
            border: none;
            cursor: pointer;
            transition: all 0.3s ease;
        }
        
        .modal-primary {
            background: var(--primary-color);
            color: white;
        }
        
        .modal-primary:hover {
            background: var(--primary-dark);
        }
        
        .modal-secondary {
            background: var(--gray-200);
            color: var(--gray-700);
        }
        
        .dark-mode .modal-secondary {
            background: var(--gray-700);
            color: var(--gray-200);
        }
        
        .modal-secondary:hover {
            background: var(--gray-300);
        }
        
        .dark-mode .modal-secondary:hover {
            background: var(--gray-600);
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
            border-radius: 12px;
            padding: 8px;
            min-width: 200px;
            z-index: 1001;
            opacity: 0;
            visibility: hidden;
            transform: translateY(-10px);
            transition: all 0.3s ease;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
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
            background: rgba(220, 38, 38, 0.05);
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
            border-radius: 12px;
            width: 320px;
            max-height: 400px;
            overflow-y: auto;
            z-index: 1001;
            opacity: 0;
            visibility: hidden;
            transform: translateY(-10px);
            transition: all 0.3s ease;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
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

        .warning-box {
            background: rgba(245, 158, 11, 0.1);
            border: 1px solid rgba(245, 158, 11, 0.3);
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 24px;
            display: flex;
            align-items: flex-start;
            gap: 12px;
        }

        .warning-box i {
            color: var(--warning);
            font-size: 24px;
            margin-top: 2px;
        }

        .warning-content h3 {
            color: var(--warning);
            margin-bottom: 8px;
        }

        .warning-content p {
            color: var(--text-light);
            margin-bottom: 4px;
        }

        .confirmation-modal-content {
            text-align: center;
            padding: 20px;
        }

        .confirmation-icon {
            font-size: 48px;
            margin-bottom: 20px;
        }

        .confirmation-title {
            font-size: 20px;
            font-weight: 700;
            margin-bottom: 12px;
        }

        .confirmation-message {
            color: var(--text-light);
            margin-bottom: 20px;
            line-height: 1.6;
        }

        .volunteer-info-summary {
            background: var(--gray-100);
            border-radius: 10px;
            padding: 15px;
            margin: 20px 0;
            text-align: left;
        }

        .dark-mode .volunteer-info-summary {
            background: var(--gray-800);
        }

        .info-item {
            display: flex;
            justify-content: space-between;
            margin-bottom: 8px;
        }

        .info-label {
            color: var(--text-light);
        }

        .info-value {
            font-weight: 500;
        }

        .table-actions {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
        }

        .filter-tabs {
            display: flex;
            gap: 8px;
            margin-bottom: 20px;
            border-bottom: 1px solid var(--border-color);
            padding-bottom: 16px;
        }

        .filter-tab {
            padding: 10px 20px;
            border-radius: 8px;
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            color: var(--text-color);
            cursor: pointer;
            transition: all 0.3s ease;
            font-weight: 500;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .filter-tab.active {
            background: var(--primary-color);
            color: white;
            border-color: var(--primary-color);
        }

        .filter-tab:hover:not(.active) {
            background: var(--gray-100);
        }

        .dark-mode .filter-tab:hover:not(.active) {
            background: var(--gray-800);
        }

        .filter-tab-count {
            background: rgba(255, 255, 255, 0.2);
            padding: 2px 8px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: 600;
        }

        .filter-tab.active .filter-tab-count {
            background: rgba(255, 255, 255, 0.3);
        }
        
        @media (max-width: 768px) {
            .table-header, .table-row {
                grid-template-columns: 1fr;
                gap: 8px;
            }
            
            .filters-container {
                flex-direction: column;
            }
            
            .filter-select, .filter-input {
                min-width: 100%;
            }
            
            .modal-footer {
                flex-direction: column;
            }

            .dashboard-header {
                padding: 40px 25px 30px;
            }
            
            .dashboard-title {
                font-size: 32px;
            }
            
            .availability-container {
                padding: 0 25px 30px;
            }
            
            .stats-container {
                grid-template-columns: 1fr;
            }

            .filter-tabs {
                flex-wrap: wrap;
            }

            .table-actions {
                flex-direction: column;
            }
        }
    </style>
</head>
<body>
   
    
    <!-- Notification Container -->
    <div class="notification-container" id="notification-container"></div>
    
    <!-- Confirmation Modal -->
    <div class="modal-overlay" id="confirmation-modal">
        <div class="modal">
            <div class="modal-header">
                <h2 class="modal-title" id="confirmation-title">Confirm Action</h2>
                <button class="modal-close" id="confirmation-modal-close">&times;</button>
            </div>
            <div class="confirmation-modal-content" id="confirmation-modal-body">
                <!-- Content will be loaded dynamically -->
            </div>
            <div class="modal-footer">
                <button class="modal-button modal-secondary" id="confirmation-cancel">Cancel</button>
                <button class="modal-button modal-primary" id="confirmation-confirm">Confirm</button>
            </div>
        </div>
    </div>
    
    <!-- View Volunteer Modal -->
    <div class="modal-overlay" id="view-modal">
        <div class="modal">
            <div class="modal-header">
                <h2 class="modal-title">Volunteer Details</h2>
                <button class="modal-close" id="view-modal-close">&times;</button>
            </div>
            <div class="modal-body" id="view-modal-body">
                <!-- Content will be loaded dynamically -->
            </div>
            <div class="modal-footer">
                <button class="modal-button modal-secondary" id="view-modal-close-btn">Close</button>
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
                    <a href="../admin_dashboard.php" class="menu-item" id="dashboard-menu">
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
                    <div id="user-management" class="submenu">
                        <a href="#" class="submenu-item">Manage Users</a>
                        <a href="#" class="submenu-item">Role Control</a>
                        <a href="#" class="submenu-item">Monitor Activity</a>
                        <a href="#" class="submenu-item">Reset Passwords</a>
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

                    
                    
                  <div class="menu-item active" onclick="toggleSubmenu('volunteer-management')">
    <div class="icon-box icon-bg-blue">
        <i class='bx bxs-user-detail icon-blue'></i>
    </div>
    <span class="font-medium">Volunteer Management</span>
    <svg class="dropdown-arrow menu-icon rotated" fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
    </svg>
</div>
<div id="volunteer-management" class="submenu active">
    <a href="review_data.php" class="submenu-item">Review Data</a>
    <a href="approve_applications.php" class="submenu-item">Assign Volunteers</a>
    <a href="view_availability.php" class="submenu-item">View Availability</a>
    <a href="remove_volunteers.php" class="submenu-item active">Remove Volunteers</a>
    <a href="toggle_volunteer_registration.php" class="submenu-item">Toggle Volunteer Registration Access</a>
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
                            <input type="text" placeholder="Search volunteers..." class="search-input" id="search-input" value="<?php echo htmlspecialchars($search_term); ?>">
                            <kbd class="search-shortcut">/</kbd>
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
                            <?php if ($avatar): ?>
                                <img src="../profile/uploads/avatars/<?php echo $avatar; ?>" alt="User" class="user-avatar">
                            <?php else: ?>
                                <div class="user-avatar" style="background: linear-gradient(135deg, var(--primary-color), var(--secondary-color)); color: white; display: flex; align-items: center; justify-content: center; font-weight: bold; border-radius: 50%; width: 40px; height: 40px;">
                                    <?php echo strtoupper(substr($full_name, 0, 1)); ?>
                                </div>
                            <?php endif; ?>
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
            
            <!-- Dashboard Content -->
            <div class="dashboard-content">
                <div class="dashboard-header">
                    <div>
                        <h1 class="dashboard-title">Remove Volunteers</h1>
                        <p class="dashboard-subtitle">Manage volunteer accounts - deactivate or permanently remove</p>
                    </div>
                    <div class="dashboard-actions">
                        <button class="secondary-button" id="refresh-button">
                            <i class='bx bx-refresh'></i>
                            Refresh Data
                        </button>
                    </div>
                </div>
                
                <!-- Warning Box -->
                <div class="availability-container">
                    <div class="warning-box">
                        <i class='bx bxs-error-circle'></i>
                        <div class="warning-content">
                            <h3>Important Notice</h3>
                            <p>• <strong>Deactivate:</strong> Makes the volunteer inactive and prevents login</p>
                            <p>• <strong>Delete:</strong> Permanently removes volunteer data and deactivates user account</p>
                            <p>• Deleted volunteers cannot be recovered</p>
                        </div>
                    </div>
                    
                    <!-- Filter Tabs -->
                    <div class="filter-tabs">
                        <div class="filter-tab <?php echo $status_filter === 'all' ? 'active' : ''; ?>" data-filter="status" data-value="all">
                            <i class='bx bxs-user'></i>
                            All Volunteers
                            <span class="filter-tab-count"><?php echo array_sum($status_counts); ?></span>
                        </div>
                        <div class="filter-tab <?php echo $status_filter === 'New Volunteer' ? 'active' : ''; ?>" data-filter="status" data-value="New Volunteer">
                            <i class='bx bx-user-plus'></i>
                            New
                            <span class="filter-tab-count"><?php echo isset($status_counts['New Volunteer']) ? $status_counts['New Volunteer'] : 0; ?></span>
                        </div>
                        <div class="filter-tab <?php echo $status_filter === 'Active' ? 'active' : ''; ?>" data-filter="status" data-value="Active">
                            <i class='bx bx-check-circle'></i>
                            Active
                            <span class="filter-tab-count"><?php echo isset($status_counts['Active']) ? $status_counts['Active'] : 0; ?></span>
                        </div>
                        <div class="filter-tab <?php echo $status_filter === 'Inactive' ? 'active' : ''; ?>" data-filter="status" data-value="Inactive">
                            <i class='bx bx-user-x'></i>
                            Inactive
                            <span class="filter-tab-count"><?php echo isset($status_counts['Inactive']) ? $status_counts['Inactive'] : 0; ?></span>
                        </div>
                    </div>
                    
                    <!-- Stats Cards -->
                    <div class="stats-container">
                        <div class="stat-card <?php echo $status_filter === 'all' ? 'active' : ''; ?>" data-status="all">
                            <div class="stat-icon">
                                <i class='bx bxs-user'></i>
                            </div>
                            <div class="stat-value"><?php echo array_sum($status_counts); ?></div>
                            <div class="stat-label">Total Volunteers</div>
                        </div>
                        <div class="stat-card <?php echo $status_filter === 'New Volunteer' ? 'active' : ''; ?>" data-status="New Volunteer">
                            <div class="stat-icon">
                                <i class='bx bx-user-plus'></i>
                            </div>
                            <div class="stat-value"><?php echo isset($status_counts['New Volunteer']) ? $status_counts['New Volunteer'] : 0; ?></div>
                            <div class="stat-label">New Volunteers</div>
                        </div>
                        <div class="stat-card <?php echo $status_filter === 'Active' ? 'active' : ''; ?>" data-status="Active">
                            <div class="stat-icon">
                                <i class='bx bx-check-circle'></i>
                            </div>
                            <div class="stat-value"><?php echo isset($status_counts['Active']) ? $status_counts['Active'] : 0; ?></div>
                            <div class="stat-label">Active Volunteers</div>
                        </div>
                        <div class="stat-card <?php echo $status_filter === 'Inactive' ? 'active' : ''; ?>" data-status="Inactive">
                            <div class="stat-icon">
                                <i class='bx bx-user-x'></i>
                            </div>
                            <div class="stat-value"><?php echo isset($status_counts['Inactive']) ? $status_counts['Inactive'] : 0; ?></div>
                            <div class="stat-label">Inactive Volunteers</div>
                        </div>
                    </div>
                    
                    <!-- Filters -->
                    <div class="filters-container">
                        <div class="filter-group">
                            <label class="filter-label">Status</label>
                            <select class="filter-select" id="status-filter">
                                <option value="all" <?php echo $status_filter === 'all' ? 'selected' : ''; ?>>All Statuses</option>
                                <option value="New Volunteer" <?php echo $status_filter === 'New Volunteer' ? 'selected' : ''; ?>>New Volunteer</option>
                                <option value="Active" <?php echo $status_filter === 'Active' ? 'selected' : ''; ?>>Active</option>
                                <option value="Inactive" <?php echo $status_filter === 'Inactive' ? 'selected' : ''; ?>>Inactive</option>
                                <option value="On Leave" <?php echo $status_filter === 'On Leave' ? 'selected' : ''; ?>>On Leave</option>
                            </select>
                        </div>
                        <div class="filter-group">
                            <label class="filter-label">Search</label>
                            <input type="text" class="filter-input" id="search-filter" placeholder="Search by name, email, or phone..." value="<?php echo htmlspecialchars($search_term); ?>">
                        </div>
                        <div class="filter-group" style="align-self: flex-end;">
                            <button class="action-button view-button" id="apply-filters">
                                <i class='bx bx-filter-alt'></i>
                                Apply Filters
                            </button>
                        </div>
                        <div class="filter-group" style="align-self: flex-end;">
                            <button class="action-button delete-button" id="reset-filters">
                                <i class='bx bx-reset'></i>
                                Reset
                            </button>
                        </div>
                    </div>
                    
                    <!-- Volunteers Table -->
                    <div class="volunteers-table">
                        <div class="table-header">
                            <div>Volunteer</div>
                            <div>Status</div>
                            <div>Contact</div>
                            <div>Registration Date</div>
                            <div>Actions</div>
                        </div>
                        
                        <?php if (count($volunteers) > 0): ?>
                            <?php foreach ($volunteers as $volunteer): 
                                // Calculate full name from parts
                                $volunteer_full_name = $volunteer['first_name'];
                                if (!empty($volunteer['middle_name'])) {
                                    $volunteer_full_name .= ' ' . $volunteer['middle_name'];
                                }
                                $volunteer_full_name .= ' ' . $volunteer['last_name'];
                            ?>
                                <div class="table-row" data-id="<?php echo $volunteer['id']; ?>">
                                    <div class="table-cell">
                                        <div class="volunteer-avatar">
                                            <?php echo strtoupper(substr($volunteer_full_name, 0, 1)); ?>
                                        </div>
                                        <div class="volunteer-info">
                                            <div class="volunteer-name"><?php echo htmlspecialchars($volunteer_full_name); ?></div>
                                            <div class="volunteer-email"><?php echo htmlspecialchars($volunteer['email']); ?></div>
                                        </div>
                                    </div>
                                    <div class="table-cell">
                                        <div class="status-badge status-<?php echo strtolower(str_replace(' ', '-', $volunteer['volunteer_status'])); ?>">
                                            <?php echo $volunteer['volunteer_status']; ?>
                                        </div>
                                    </div>
                                    <div class="table-cell">
                                        <div class="volunteer-phone"><?php echo htmlspecialchars($volunteer['contact_number']); ?></div>
                                    </div>
                                    <div class="table-cell">
                                        <?php echo date('M d, Y', strtotime($volunteer['application_date'])); ?>
                                    </div>
                                    <div class="table-cell">
                                        <div class="table-actions">
                                            <button class="action-button view-button" onclick="viewVolunteerModal(<?php echo $volunteer['id']; ?>)">
                                                <i class='bx bx-show'></i>
                                                View
                                            </button>
                                            <?php if ($volunteer['volunteer_status'] !== 'Inactive'): ?>
                                                <button class="action-button deactivate-button" onclick="confirmDeactivate(<?php echo $volunteer['id']; ?>, '<?php echo htmlspecialchars(addslashes($volunteer_full_name)); ?>')">
                                                    <i class='bx bx-user-x'></i>
                                                    Deactivate
                                                </button>
                                            <?php endif; ?>
                                            <button class="action-button delete-button" onclick="confirmDelete(<?php echo $volunteer['id']; ?>, '<?php echo htmlspecialchars(addslashes($volunteer_full_name)); ?>')">
                                                <i class='bx bx-trash'></i>
                                                Delete
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="no-volunteers">
                                <div class="no-volunteers-icon">
                                    <i class='bx bx-user-x'></i>
                                </div>
                                <h3>No Volunteers Found</h3>
                                <p>No volunteers match your current filters.</p>
                            </div>
                        <?php endif; ?>
                    </div>
                    
                    <!-- Pagination -->
                    <div class="pagination">
                        <button class="pagination-button" id="prev-page" <?php echo $page <= 1 ? 'disabled' : ''; ?>>
                            <i class='bx bx-chevron-left'></i>
                            Previous
                        </button>
                        
                        <div class="pagination-numbers">
                            <?php
                            $start_page = max(1, $page - 2);
                            $end_page = min($total_pages, $page + 2);
                            
                            if ($start_page > 1) {
                                echo '<div class="pagination-number" data-page="1">1</div>';
                                if ($start_page > 2) {
                                    echo '<div class="pagination-number disabled">...</div>';
                                }
                            }
                            
                            for ($i = $start_page; $i <= $end_page; $i++) {
                                $active = $i == $page ? 'active' : '';
                                echo "<div class='pagination-number $active' data-page='$i'>$i</div>";
                            }
                            
                            if ($end_page < $total_pages) {
                                if ($end_page < $total_pages - 1) {
                                    echo '<div class="pagination-number disabled">...</div>';
                                }
                                echo "<div class='pagination-number' data-page='$total_pages'>$total_pages</div>";
                            }
                            ?>
                        </div>
                        
                        <span class="pagination-info">Page <?php echo $page; ?> of <?php echo $total_pages; ?></span>
                        
                        <button class="pagination-button" id="next-page" <?php echo $page >= $total_pages ? 'disabled' : ''; ?>>
                            Next
                            <i class='bx bx-chevron-right'></i>
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <script>
        // Global variables
        let currentVolunteerId = null;
        let currentAction = null;
        
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
            
            // Show success/error messages
            const urlParams = new URLSearchParams(window.location.search);
            if (urlParams.has('success')) {
                showNotification('success', 'Success', 'Action completed successfully');
            }
            if (urlParams.has('error')) {
                showNotification('error', 'Error', 'An error occurred while processing your request');
            }
        });
        
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
            
            // Filter functionality
            document.getElementById('apply-filters').addEventListener('click', applyFilters);
            document.getElementById('reset-filters').addEventListener('click', resetFilters);
            document.getElementById('search-filter').addEventListener('keypress', function(e) {
                if (e.key === 'Enter') {
                    applyFilters();
                }
            });
            
            // Search input in header
            document.getElementById('search-input').addEventListener('keypress', function(e) {
                if (e.key === 'Enter') {
                    document.getElementById('search-filter').value = this.value;
                    applyFilters();
                }
            });
            
            // Status filter cards
            document.querySelectorAll('.stat-card').forEach(card => {
                card.addEventListener('click', function() {
                    const status = this.getAttribute('data-status');
                    document.getElementById('status-filter').value = status;
                    applyFilters();
                });
            });
            
            // Filter tabs
            document.querySelectorAll('.filter-tab').forEach(tab => {
                tab.addEventListener('click', function() {
                    const filterType = this.getAttribute('data-filter');
                    const filterValue = this.getAttribute('data-value');
                    
                    if (filterType === 'status') {
                        document.getElementById('status-filter').value = filterValue;
                        applyFilters();
                    }
                });
            });
            
            // Confirmation modal
            document.getElementById('confirmation-modal-close').addEventListener('click', closeConfirmationModal);
            document.getElementById('confirmation-cancel').addEventListener('click', closeConfirmationModal);
            document.getElementById('confirmation-confirm').addEventListener('click', performAction);
            
            // View modal
            document.getElementById('view-modal-close').addEventListener('click', closeViewModal);
            document.getElementById('view-modal-close-btn').addEventListener('click', closeViewModal);
            
            // Refresh button
            document.getElementById('refresh-button').addEventListener('click', refreshData);
            
            // Pagination
            document.getElementById('prev-page').addEventListener('click', previousPage);
            document.getElementById('next-page').addEventListener('click', nextPage);
            document.querySelectorAll('.pagination-number:not(.disabled)').forEach(number => {
                number.addEventListener('click', function() {
                    const page = this.getAttribute('data-page');
                    goToPage(parseInt(page));
                });
            });
            
            // Keyboard shortcuts
            document.addEventListener('keydown', function(e) {
                // Search shortcut - forward slash
                if (e.key === '/' && !e.ctrlKey && !e.altKey && !e.metaKey) {
                    e.preventDefault();
                    document.getElementById('search-input').focus();
                }
                
                // Escape key to close modal
                if (e.key === 'Escape') {
                    closeConfirmationModal();
                    closeViewModal();
                    userDropdown.classList.remove('show');
                    notificationDropdown.classList.remove('show');
                }
            });
        }
        
        function applyFilters() {
            const status = document.getElementById('status-filter').value;
            const search = document.getElementById('search-filter').value;
            
            let url = 'remove_volunteers.php?';
            if (status !== 'all') {
                url += `status=${status}&`;
            }
            if (search) {
                url += `search=${encodeURIComponent(search)}&`;
            }
            
            window.location.href = url;
        }
        
        function resetFilters() {
            document.getElementById('status-filter').value = 'all';
            document.getElementById('search-filter').value = '';
            applyFilters();
        }
        
        function viewVolunteerModal(id) {
            // Show loading state
            document.getElementById('view-modal-body').innerHTML = `
                <div style="text-align: center; padding: 40px;">
                    <i class='bx bx-loader-circle bx-spin' style="font-size: 40px; color: var(--primary-color);"></i>
                    <p style="margin-top: 16px;">Loading volunteer details...</p>
                </div>
            `;
            
            document.getElementById('view-modal').classList.add('active');
            
            // Fetch volunteer details
            fetch(`volunteer_details.php?id=${id}`)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        const volunteer = data.volunteer;
                        // Calculate full name
                        let fullName = volunteer.first_name;
                        if (volunteer.middle_name) {
                            fullName += ' ' + volunteer.middle_name;
                        }
                        fullName += ' ' + volunteer.last_name;
                        
                        let skills = [];
                        if (volunteer.skills_basic_firefighting) skills.push('Firefighting');
                        if (volunteer.skills_first_aid_cpr) skills.push('First Aid');
                        if (volunteer.skills_search_rescue) skills.push('Rescue');
                        if (volunteer.skills_driving) skills.push('Driving');
                        
                        document.getElementById('view-modal-body').innerHTML = `
                            <div class="modal-section">
                                <h3 class="modal-section-title">Personal Information</h3>
                                <div class="modal-grid">
                                    <div class="modal-detail">
                                        <div class="modal-detail-label">Full Name</div>
                                        <div class="modal-detail-value">${fullName}</div>
                                    </div>
                                    <div class="modal-detail">
                                        <div class="modal-detail-label">Email</div>
                                        <div class="modal-detail-value">${volunteer.email}</div>
                                    </div>
                                    <div class="modal-detail">
                                        <div class="modal-detail-label">Phone</div>
                                        <div class="modal-detail-value">${volunteer.contact_number}</div>
                                    </div>
                                    <div class="modal-detail">
                                        <div class="modal-detail-label">Address</div>
                                        <div class="modal-detail-value">${volunteer.address}</div>
                                    </div>
                                    <div class="modal-detail">
                                        <div class="modal-detail-label">Date of Birth</div>
                                        <div class="modal-detail-value">${volunteer.date_of_birth}</div>
                                    </div>
                                    <div class="modal-detail">
                                        <div class="modal-detail-label">Gender</div>
                                        <div class="modal-detail-value">${volunteer.gender}</div>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="modal-section">
                                <h3 class="modal-section-title">Volunteer Information</h3>
                                <div class="modal-grid">
                                    <div class="modal-detail">
                                        <div class="modal-detail-label">Status</div>
                                        <div class="status-badge status-${volunteer.volunteer_status.toLowerCase().replace(' ', '-')}">${volunteer.volunteer_status}</div>
                                    </div>
                                    <div class="modal-detail">
                                        <div class="modal-detail-label">Registration Date</div>
                                        <div class="modal-detail-value">${volunteer.application_date}</div>
                                    </div>
                                    <div class="modal-detail">
                                        <div class="modal-detail-label">Available Days</div>
                                        <div class="modal-detail-value">${volunteer.available_days}</div>
                                    </div>
                                    <div class="modal-detail">
                                        <div class="modal-detail-label">Available Hours</div>
                                        <div class="modal-detail-value">${volunteer.available_hours}</div>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="modal-section">
                                <h3 class="modal-section-title">Skills & Training</h3>
                                <div class="modal-grid">
                                    <div class="modal-detail">
                                        <div class="modal-detail-label">Skills</div>
                                        <div class="modal-detail-value">
                                            <div class="skills-list">
                                                ${skills.length > 0 ? 
                                                    skills.map(skill => `<span class="skill-tag">${skill}</span>`).join('') : 
                                                    '<span class="skill-tag">No skills listed</span>'}
                                            </div>
                                        </div>
                                    </div>
                                    <div class="modal-detail">
                                        <div class="modal-detail-label">Specialized Training</div>
                                        <div class="modal-detail-value">${volunteer.specialized_training || 'None'}</div>
                                    </div>
                                    <div class="modal-detail">
                                        <div class="modal-detail-label">Education</div>
                                        <div class="modal-detail-value">${volunteer.education}</div>
                                    </div>
                                </div>
                            </div>
                        `;
                    } else {
                        document.getElementById('view-modal-body').innerHTML = `
                            <div style="text-align: center; padding: 40px;">
                                <i class='bx bx-error' style="font-size: 40px; color: var(--danger);"></i>
                                <p style="margin-top: 16px;">Failed to load volunteer details</p>
                            </div>
                        `;
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    document.getElementById('view-modal-body').innerHTML = `
                        <div style="text-align: center; padding: 40px;">
                            <i class='bx bx-error' style="font-size: 40px; color: var(--danger);"></i>
                            <p style="margin-top: 16px;">Error loading volunteer details</p>
                        </div>
                    `;
                });
        }
        
        function closeViewModal() {
            document.getElementById('view-modal').classList.remove('active');
        }
        
        function confirmDeactivate(id, name) {
            currentVolunteerId = id;
            currentAction = 'deactivate';
            
            document.getElementById('confirmation-title').textContent = 'Confirm Deactivation';
            document.getElementById('confirmation-modal-body').innerHTML = `
                <div class="confirmation-icon">
                    <i class='bx bx-user-x' style="color: var(--warning);"></i>
                </div>
                <div class="confirmation-title">Deactivate Volunteer</div>
                <div class="confirmation-message">
                    Are you sure you want to deactivate <strong>${name}</strong>?<br>
                    This will:
                    <ul style="text-align: left; margin: 10px 0; padding-left: 20px;">
                        <li>Change their status to "Inactive"</li>
                        <li>Prevent them from logging in</li>
                        <li>Keep their data in the system</li>
                    </ul>
                </div>
                <div class="volunteer-info-summary">
                    <div class="info-item">
                        <span class="info-label">Action:</span>
                        <span class="info-value">Deactivate Account</span>
                    </div>
                    <div class="info-item">
                        <span class="info-label">Result:</span>
                        <span class="info-value">Volunteer cannot login</span>
                    </div>
                    <div class="info-item">
                        <span class="info-label">Reversible:</span>
                        <span class="info-value">Yes (by an admin)</span>
                    </div>
                </div>
            `;
            
            document.getElementById('confirmation-confirm').innerHTML = `
                <i class='bx bx-user-x'></i>
                Deactivate
            `;
            document.getElementById('confirmation-confirm').style.backgroundColor = 'var(--warning)';
            
            document.getElementById('confirmation-modal').classList.add('active');
        }
        
        function confirmDelete(id, name) {
            currentVolunteerId = id;
            currentAction = 'delete';
            
            document.getElementById('confirmation-title').textContent = 'Confirm Permanent Removal';
            document.getElementById('confirmation-modal-body').innerHTML = `
                <div class="confirmation-icon">
                    <i class='bx bxs-error-circle' style="color: var(--danger);"></i>
                </div>
                <div class="confirmation-title">Permanently Remove Volunteer</div>
                <div class="confirmation-message">
                    <strong style="color: var(--danger);">WARNING:</strong> This action cannot be undone!<br><br>
                    You are about to permanently remove <strong>${name}</strong>.<br>
                    This will:
                    <ul style="text-align: left; margin: 10px 0; padding-left: 20px;">
                        <li>Delete all volunteer data</li>
                        <li>Remove volunteer assignments</li>
                        <li>Deactivate their user account</li>
                        <li>This action is PERMANENT</li>
                    </ul>
                </div>
                <div class="volunteer-info-summary">
                    <div class="info-item">
                        <span class="info-label">Action:</span>
                        <span class="info-value" style="color: var(--danger);">Permanent Removal</span>
                    </div>
                    <div class="info-item">
                        <span class="info-label">Data Recovery:</span>
                        <span class="info-value" style="color: var(--danger);">Not Possible</span>
                    </div>
                    <div class="info-item">
                        <span class="info-label">Reversible:</span>
                        <span class="info-value" style="color: var(--danger);">No</span>
                    </div>
                </div>
                <div style="margin-top: 20px; padding: 10px; background: rgba(220, 38, 38, 0.1); border-radius: 8px;">
                    <i class='bx bx-error' style="color: var(--danger);"></i>
                    <strong>This action cannot be undone. Please confirm carefully.</strong>
                </div>
            `;
            
            document.getElementById('confirmation-confirm').innerHTML = `
                <i class='bx bx-trash'></i>
                Delete Permanently
            `;
            document.getElementById('confirmation-confirm').style.backgroundColor = 'var(--danger)';
            
            document.getElementById('confirmation-modal').classList.add('active');
        }
        
        function closeConfirmationModal() {
            document.getElementById('confirmation-modal').classList.remove('active');
            currentVolunteerId = null;
            currentAction = null;
        }
        
        function performAction() {
            if (!currentVolunteerId || !currentAction) return;
            
            const form = document.createElement('form');
            form.method = 'POST';
            form.style.display = 'none';
            
            const volunteerIdInput = document.createElement('input');
            volunteerIdInput.type = 'hidden';
            volunteerIdInput.name = 'volunteer_id';
            volunteerIdInput.value = currentVolunteerId;
            
            const actionInput = document.createElement('input');
            actionInput.type = 'hidden';
            actionInput.name = 'action';
            actionInput.value = currentAction;
            
            form.appendChild(volunteerIdInput);
            form.appendChild(actionInput);
            document.body.appendChild(form);
            
            form.submit();
        }
        
        function refreshData() {
            showNotification('info', 'Refreshing Data', 'Fetching the latest volunteer information');
            location.reload();
        }
        
        function previousPage() {
            const currentPage = <?php echo $page; ?>;
            if (currentPage > 1) {
                goToPage(currentPage - 1);
            }
        }
        
        function nextPage() {
            const currentPage = <?php echo $page; ?>;
            const totalPages = <?php echo $total_pages; ?>;
            if (currentPage < totalPages) {
                goToPage(currentPage + 1);
            }
        }
        
        function goToPage(page) {
            const status = document.getElementById('status-filter').value;
            const search = document.getElementById('search-filter').value;
            
            let url = `remove_volunteers.php?page=${page}`;
            if (status !== 'all') {
                url += `&status=${status}`;
            }
            if (search) {
                url += `&search=${encodeURIComponent(search)}`;
            }
            
            window.location.href = url;
        }
        
        function showNotification(type, title, message, playSound = false) {
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
        
        function toggleSubmenu(id) {
            const submenu = document.getElementById(id);
            const arrow = submenu.previousElementSibling.querySelector('.dropdown-arrow');
            
            submenu.classList.toggle('active');
            arrow.classList.toggle('rotated');
        }
        
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
    </script>
</body>
</html>