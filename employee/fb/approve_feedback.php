<?php
session_start();
require_once '../../config/db_connection.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: ../../login/login.php");
    exit();
}

$user_id = $_SESSION['user_id'];
$query = "SELECT first_name, middle_name, last_name, role FROM users WHERE id = ?";
$stmt = $pdo->prepare($query);
$stmt->execute([$user_id]);
$user = $stmt->fetch();

if ($user) {
    $first_name = htmlspecialchars($user['first_name']);
    $middle_name = htmlspecialchars($user['middle_name']);
    $last_name = htmlspecialchars($user['last_name']);
    $role = htmlspecialchars($user['role']);
    
    $full_name = $first_name;
    if (!empty($middle_name)) {
        $full_name .= " " . $middle_name;
    }
    $full_name .= " " . $last_name;
} else {
    $full_name = "User";
    $role = "USER";
}

// Check if user has permission (EMPLOYEE or ADMIN)
if ($role !== 'ADMIN' && $role !== 'EMPLOYEE') {
    header("Location: ../employee_dashboard.php");
    exit();
}

// Pagination setup
$records_per_page = 10;
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$offset = ($page - 1) * $records_per_page;

// Handle feedback actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        $feedback_id = (int)$_POST['feedback_id'];
        
        switch ($_POST['action']) {
            case 'approve':
                $update_query = "UPDATE feedbacks SET is_approved = 1 WHERE id = ?";
                $action_message = "Feedback approved successfully";
                break;
                
            case 'reject':
                $update_query = "UPDATE feedbacks SET is_approved = 0 WHERE id = ?";
                $action_message = "Feedback rejected";
                break;
                
            case 'delete':
                $update_query = "DELETE FROM feedbacks WHERE id = ?";
                $action_message = "Feedback deleted successfully";
                break;
                
            default:
                $action_message = "Invalid action";
                break;
        }
        
        if (isset($update_query)) {
            $update_stmt = $pdo->prepare($update_query);
            $update_stmt->execute([$feedback_id]);
            
            // Show success message
            $_SESSION['success_message'] = $action_message;
            header("Location: approve_feedback.php");
            exit();
        }
    }
}

// Get filter parameters
$filter = isset($_GET['filter']) ? $_GET['filter'] : 'all';
$search = isset($_GET['search']) ? trim($_GET['search']) : '';

// Build query based on filter
$where_conditions = [];
$params = [];

if ($filter === 'pending') {
    $where_conditions[] = "is_approved = 0";
} elseif ($filter === 'approved') {
    $where_conditions[] = "is_approved = 1";
} elseif ($filter === 'anonymous') {
    $where_conditions[] = "is_anonymous = 1";
} elseif ($filter === 'named') {
    $where_conditions[] = "is_anonymous = 0";
}

if (!empty($search)) {
    $where_conditions[] = "(message LIKE ? OR name LIKE ? OR email LIKE ?)";
    $search_term = "%$search%";
    $params[] = $search_term;
    $params[] = $search_term;
    $params[] = $search_term;
}

$where_clause = "";
if (!empty($where_conditions)) {
    $where_clause = "WHERE " . implode(" AND ", $where_conditions);
}

// Get total count
$count_query = "SELECT COUNT(*) as total FROM feedbacks $where_clause";
$count_stmt = $pdo->prepare($count_query);
$count_stmt->execute($params);
$total_records = $count_stmt->fetch()['total'];
$total_pages = ceil($total_records / $records_per_page);

// Get feedback with pagination
$feedback_query = "SELECT f.*, 
                   CASE 
                       WHEN f.is_anonymous = 1 THEN 'Anonymous'
                       ELSE CONCAT(COALESCE(f.name, ''), ' (', COALESCE(f.email, ''), ')')
                   END as display_name
                   FROM feedbacks f 
                   $where_clause 
                   ORDER BY f.created_at DESC
                   LIMIT :offset, :records_per_page";
                   
$feedback_stmt = $pdo->prepare($feedback_query);

// Add parameters if any
if (!empty($params)) {
    foreach ($params as $key => $param) {
        $feedback_stmt->bindValue($key + 1, $param, PDO::PARAM_STR);
    }
}

$feedback_stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$feedback_stmt->bindValue(':records_per_page', $records_per_page, PDO::PARAM_INT);
$feedback_stmt->execute();
$feedbacks = $feedback_stmt->fetchAll();

// Get statistics
$stats_query = "SELECT 
                COUNT(*) as total_feedback,
                SUM(CASE WHEN is_approved = 1 THEN 1 ELSE 0 END) as approved_count,
                SUM(CASE WHEN is_approved = 0 THEN 1 ELSE 0 END) as pending_count,
                SUM(CASE WHEN is_anonymous = 1 THEN 1 ELSE 0 END) as anonymous_count,
                AVG(rating) as average_rating
                FROM feedbacks";
$stats_stmt = $pdo->prepare($stats_query);
$stats_stmt->execute();
$stats = $stats_stmt->fetch();

$stmt = null;
$feedback_stmt = null;
$stats_stmt = null;
$count_stmt = null;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Approve Feedback - Fire & Rescue Services</title>
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
            --text-light: #f1f5f9;
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

        .feedback-container {
            display: flex;
            flex-direction: column;
            gap: 24px;
            padding: 0 40px 40px;
        }
        
        .feedback-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 24px;
        }
        
        .feedback-title {
            font-size: 28px;
            font-weight: 800;
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            margin-bottom: 8px;
        }
        
        .feedback-subtitle {
            color: var(--text-light);
            font-size: 16px;
        }
        
        .stats-container {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 20px;
            margin-bottom: 24px;
        }
        
        .stat-card {
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: 20px;
            padding: 20px;
            display: flex;
            align-items: center;
            gap: 15px;
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
            background: linear-gradient(90deg, var(--primary-color), var(--secondary-color));
        }
        
        .stat-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 8px 20px rgba(0, 0, 0, 0.1);
        }
        
        .stat-icon {
            font-size: 28px;
            padding: 12px;
            border-radius: 12px;
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            width: 50px;
            height: 50px;
            flex-shrink: 0;
        }
        
        .stat-content {
            flex: 1;
        }
        
        .stat-value {
            font-size: 24px;
            font-weight: 800;
            margin-bottom: 4px;
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }
        
        .stat-label {
            font-size: 13px;
            color: var(--text-light);
            font-weight: 500;
        }
        
        .filters-container {
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: 15px;
            padding: 20px;
            margin-bottom: 24px;
            display: flex;
            flex-wrap: wrap;
            gap: 15px;
            align-items: center;
        }
        
        .filter-group {
            display: flex;
            flex-direction: column;
            gap: 8px;
        }
        
        .filter-label {
            font-size: 12px;
            font-weight: 600;
            color: var(--text-light);
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .filter-select, .search-input {
            padding: 10px 12px;
            border-radius: 10px;
            border: 1px solid var(--border-color);
            background: var(--card-bg);
            color: var(--text-color);
            font-size: 14px;
            min-width: 150px;
            transition: all 0.3s ease;
        }
        
        .filter-select:focus, .search-input:focus {
            outline: none;
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(220, 38, 38, 0.1);
        }
        
        .search-input {
            min-width: 250px;
            padding-left: 40px;
        }
        
        .search-box {
            position: relative;
        }
        
        .search-icon {
            position: absolute;
            left: 12px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--text-light);
            font-size: 18px;
        }
        
        .apply-filters {
            padding: 10px 20px;
            border-radius: 10px;
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            color: white;
            border: none;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.3s ease;
        }
        
        .apply-filters:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(220, 38, 38, 0.3);
        }
        
        .feedback-table-container {
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: 20px;
            overflow: hidden;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
        }
        
        .table-header {
            padding: 25px;
            border-bottom: 1px solid var(--border-color);
            display: flex;
            align-items: center;
            justify-content: space-between;
            background: rgba(220, 38, 38, 0.02);
        }
        
        .table-title {
            font-size: 20px;
            font-weight: 700;
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }
        
        .feedback-table {
            width: 100%;
            border-collapse: collapse;
        }
        
        .feedback-table th {
            background: rgba(220, 38, 38, 0.05);
            padding: 18px;
            text-align: left;
            font-weight: 600;
            color: var(--text-color);
            border-bottom: 1px solid var(--border-color);
            font-size: 14px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .feedback-table td {
            padding: 18px;
            border-bottom: 1px solid var(--border-color);
            vertical-align: middle;
        }
        
        .feedback-table tr:last-child td {
            border-bottom: none;
        }
        
        .feedback-table tr:hover {
            background: rgba(220, 38, 38, 0.02);
        }
        
        .rating-stars {
            display: flex;
            gap: 2px;
        }
        
        .star {
            color: var(--icon-yellow);
            font-size: 16px;
        }
        
        .star.empty {
            color: var(--gray-300);
        }
        
        .feedback-message {
            max-width: 300px;
            overflow: hidden;
            text-overflow: ellipsis;
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
            line-height: 1.4;
        }
        
        .feedback-name {
            font-weight: 600;
            margin-bottom: 4px;
        }
        
        .feedback-email {
            color: var(--text-light);
            font-size: 13px;
        }
        
        .status-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.3px;
        }
        
        .status-pending {
            background: rgba(245, 158, 11, 0.1);
            color: var(--warning);
            border: 1px solid rgba(245, 158, 11, 0.2);
        }
        
        .status-approved {
            background: rgba(16, 185, 129, 0.1);
            color: var(--success);
            border: 1px solid rgba(16, 185, 129, 0.2);
        }
        
        .status-anonymous {
            background: rgba(107, 114, 128, 0.1);
            color: var(--text-light);
            border: 1px solid rgba(107, 114, 128, 0.2);
        }
        
        .action-buttons {
            display: flex;
            gap: 8px;
        }
        
        .action-button {
            padding: 8px 12px;
            border-radius: 8px;
            border: none;
            cursor: pointer;
            font-weight: 500;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 6px;
            font-size: 13px;
        }
        
        .view-button {
            background: rgba(59, 130, 246, 0.1);
            color: var(--info);
        }
        
        .view-button:hover {
            background: var(--info);
            color: white;
            transform: translateY(-1px);
        }
        
        .approve-button {
            background: rgba(16, 185, 129, 0.1);
            color: var(--success);
        }
        
        .approve-button:hover {
            background: var(--success);
            color: white;
            transform: translateY(-1px);
        }
        
        .reject-button {
            background: rgba(239, 68, 68, 0.1);
            color: var(--danger);
        }
        
        .reject-button:hover {
            background: var(--danger);
            color: white;
            transform: translateY(-1px);
        }
        
        .delete-button {
            background: rgba(239, 68, 68, 0.1);
            color: var(--danger);
        }
        
        .delete-button:hover {
            background: var(--danger);
            color: white;
            transform: translateY(-1px);
        }
        
        .no-feedback {
            text-align: center;
            padding: 80px 20px;
            color: var(--text-light);
        }
        
        .no-feedback-icon {
            font-size: 80px;
            margin-bottom: 20px;
            color: var(--text-light);
            opacity: 0.3;
        }
        
        .pagination {
            display: flex;
            justify-content: center;
            align-items: center;
            padding: 25px;
            border-top: 1px solid var(--border-color);
            background: rgba(220, 38, 38, 0.02);
            gap: 12px;
        }
        
        .pagination-button {
            padding: 10px 16px;
            border-radius: 10px;
            border: 1px solid var(--border-color);
            background: var(--card-bg);
            color: var(--text-color);
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 6px;
            font-size: 13px;
            font-weight: 500;
        }
        
        .pagination-button:hover:not(:disabled) {
            background: var(--primary-color);
            color: white;
            border-color: var(--primary-color);
            transform: translateY(-1px);
        }
        
        .pagination-button:disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }
        
        .pagination-info {
            color: var(--text-light);
            font-size: 14px;
            margin: 0 16px;
        }
        
        .pagination-numbers {
            display: flex;
            gap: 6px;
            margin: 0 16px;
        }
        
        .page-number {
            padding: 8px 12px;
            border-radius: 8px;
            border: 1px solid var(--border-color);
            background: var(--card-bg);
            color: var(--text-color);
            cursor: pointer;
            transition: all 0.3s ease;
            font-size: 13px;
            font-weight: 500;
            min-width: 40px;
            text-align: center;
        }
        
        .page-number:hover {
            background: var(--primary-color);
            color: white;
            border-color: var(--primary-color);
        }
        
        .page-number.active {
            background: var(--primary-color);
            color: white;
            border-color: var(--primary-color);
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
            max-width: 600px;
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
        
        .feedback-modal-content {
            padding: 20px;
        }
        
        .feedback-modal-header {
            display: flex;
            align-items: center;
            gap: 15px;
            margin-bottom: 20px;
        }
        
        .feedback-modal-avatar {
            width: 60px;
            height: 60px;
            border-radius: 50%;
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 24px;
            font-weight: bold;
        }
        
        .feedback-modal-user {
            flex: 1;
        }
        
        .feedback-modal-name {
            font-size: 18px;
            font-weight: 600;
            margin-bottom: 4px;
        }
        
        .feedback-modal-rating {
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .feedback-modal-message {
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 20px;
            line-height: 1.6;
        }
        
        .feedback-modal-info {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-bottom: 20px;
        }
        
        .info-item {
            display: flex;
            flex-direction: column;
            gap: 4px;
        }
        
        .info-label {
            font-size: 12px;
            color: var(--text-light);
            font-weight: 500;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .info-value {
            font-size: 14px;
            font-weight: 500;
        }
        
        .delete-confirmation-modal {
            text-align: center;
            padding: 30px;
        }
        
        .delete-confirmation-icon {
            font-size: 60px;
            color: var(--danger);
            margin-bottom: 20px;
        }
        
        .delete-confirmation-message {
            font-size: 16px;
            margin-bottom: 25px;
            line-height: 1.5;
        }
        
        .confirmation-actions {
            display: flex;
            justify-content: center;
            gap: 15px;
        }
        
        @media (max-width: 768px) {
            .feedback-table {
                display: block;
                overflow-x: auto;
            }
            
            .dashboard-header {
                padding: 40px 25px 30px;
            }
            
            .dashboard-title {
                font-size: 32px;
            }
            
            .feedback-container {
                padding: 0 25px 30px;
            }
            
            .stats-container {
                grid-template-columns: 1fr;
            }
            
            .filters-container {
                flex-direction: column;
                align-items: stretch;
            }
            
            .filter-group {
                width: 100%;
            }
            
            .search-input {
                min-width: 100%;
            }
            
            .table-header {
                flex-direction: column;
                gap: 16px;
                align-items: flex-start;
            }
            
            .action-buttons {
                flex-direction: column;
                align-items: stretch;
            }
            
            .action-button {
                justify-content: center;
            }
            
            .pagination {
                flex-wrap: wrap;
                gap: 8px;
            }
            
            .pagination-numbers {
                order: -1;
                width: 100%;
                justify-content: center;
                margin: 8px 0;
            }
            
            .modal {
                max-width: 95%;
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
        <div class="animation-text" id="animation-text">Loading Feedback Dashboard...</div>
    </div>
    
    <!-- Notification Container -->
    <div class="notification-container" id="notification-container">
        <?php if (isset($_SESSION['success_message'])): ?>
            <div class="notification notification-success show">
                <i class='bx bx-check-circle notification-icon'></i>
                <div class="notification-content">
                    <div class="notification-title">Success!</div>
                    <div class="notification-message"><?php echo $_SESSION['success_message']; ?></div>
                </div>
                <button class="notification-close">&times;</button>
            </div>
            <?php unset($_SESSION['success_message']); ?>
        <?php endif; ?>
    </div>
    
    <!-- View Feedback Modal -->
    <div class="modal-overlay" id="view-modal">
        <div class="modal">
            <div class="modal-header">
                <h2 class="modal-title">Feedback Details</h2>
                <button class="modal-close" id="view-modal-close">&times;</button>
            </div>
            <div class="modal-body feedback-modal-content" id="view-modal-body">
                <!-- Feedback content will be loaded here -->
            </div>
            <div class="modal-footer">
                <button class="action-button view-button" id="view-close">Close</button>
            </div>
        </div>
    </div>
    
    <!-- Delete Confirmation Modal -->
    <div class="modal-overlay" id="delete-modal">
        <div class="modal">
            <div class="modal-body delete-confirmation-modal">
                <div class="delete-confirmation-icon">
                    <i class='bx bx-trash'></i>
                </div>
                <h3>Delete Feedback?</h3>
                <p class="delete-confirmation-message" id="delete-message">Are you sure you want to delete this feedback? This action cannot be undone.</p>
                <div class="confirmation-actions">
                    <form method="POST" id="delete-form">
                        <input type="hidden" name="feedback_id" id="delete-feedback-id">
                        <input type="hidden" name="action" value="delete">
                        <button type="button" class="action-button view-button" id="delete-cancel">Cancel</button>
                        <button type="submit" class="action-button delete-button">Delete Permanently</button>
                    </form>
                </div>
            </div>
        </div>
    </div>
    
    <div class="container">
        <!-- Sidebar (same as your existing sidebar) -->
        <div class="sidebar">
            <div class="logo">
                <div class="logo-icon">
                    <img src="../../img/frsm-logo.png" alt="Fire & Rescue Logo" style="width: 40px; height: 45px;">
                </div>
                <span class="logo-text">Fire & Rescue</span>
            </div>
            
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
                    <div id="volunteer" class="submenu">
                        <a href="review_data.php" class="submenu-item">Review/Aprroved Data Management</a>
                        <a href="approve_applications.php" class="submenu-item">Assign Volunteers</a>
                        <a href="view_availability.php" class="submenu-item">View Availability</a>
                        <a href="remove_volunteers.php" class="submenu-item">Remove Volunteers</a>
                        <a href="toggle_volunteer_registration.php" class="submenu-item">Open/Close Registration</a>
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

                    
                    <!-- Feedback & Suggestions -->
                    <div class="menu-item" onclick="toggleSubmenu('feedback')">
                        <div class="icon-box icon-bg-indigo">
                            <i class='bx bxs-message-square-detail icon-indigo'></i>
                        </div>
                        <span class="font-medium">Feedback & Suggestions</span>
                        <svg class="dropdown-arrow menu-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                        </svg>
                    </div>
                    <div id="feedback" class="submenu active">
                        <a href="approve_feedback.php" class="submenu-item active">Approve Feedback</a>
                        <a href="#" class="submenu-item">All Feedback</a>
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
                            <input type="text" placeholder="Search feedback..." class="search-input" id="header-search-input">
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
                        <div class="user-profile" id="user-profile">
                            <img src="../../img/rei.jfif" alt="User" class="user-avatar">
                            <div class="user-info">
                                <p class="user-name"><?php echo $full_name; ?></p>
                                <p class="user-email"><?php echo $role; ?></p>
                            </div>
                            <div class="user-profile-dropdown" id="user-profile-dropdown">
                                <a href="../settings.php" class="user-profile-dropdown-item settings">
                                    <i class='bx bxs-cog'></i>
                                    <span>Settings</span>
                                </a>
                                <a href="../profile/profile.php" class="user-profile-dropdown-item profile">
                                    <i class='bx bxs-user'></i>
                                    <span>Profile</span>
                                </a>
                                <a href="../../includes/logout.php" class="user-profile-dropdown-item logout">
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
                        <h1 class="dashboard-title">Approve Feedback</h1>
                        <p class="dashboard-subtitle">Manage and moderate community feedback and suggestions</p>
                    </div>
                    <div class="dashboard-actions">
                        <button class="primary-button" id="refresh-button">
                            <i class='bx bx-refresh'></i>
                            Refresh Feedback
                        </button>
                        <button class="secondary-button" id="export-button">
                            <i class='bx bx-export'></i>
                            Export Feedback
                        </button>
                    </div>
                </div>
                
                <!-- Feedback Management Section -->
                <div class="feedback-container">
                    <!-- Enhanced Stats Cards -->
                    <div class="stats-container">
                        <div class="stat-card">
                            <div class="stat-icon">
                                <i class='bx bx-message-detail'></i>
                            </div>
                            <div class="stat-content">
                                <div class="stat-value"><?php echo $stats['total_feedback']; ?></div>
                                <div class="stat-label">Total Feedback</div>
                            </div>
                        </div>
                        <div class="stat-card">
                            <div class="stat-icon">
                                <i class='bx bx-check-circle'></i>
                            </div>
                            <div class="stat-content">
                                <div class="stat-value"><?php echo $stats['approved_count']; ?></div>
                                <div class="stat-label">Approved</div>
                            </div>
                        </div>
                        <div class="stat-card">
                            <div class="stat-icon">
                                <i class='bx bx-time-five'></i>
                            </div>
                            <div class="stat-content">
                                <div class="stat-value"><?php echo $stats['pending_count']; ?></div>
                                <div class="stat-label">Pending Review</div>
                            </div>
                        </div>
                        <div class="stat-card">
                            <div class="stat-icon">
                                <i class='bx bx-star'></i>
                            </div>
                            <div class="stat-content">
                                <div class="stat-value"><?php echo number_format($stats['average_rating'], 1); ?></div>
                                <div class="stat-label">Avg. Rating</div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Filters -->
                    <div class="filters-container">
                        <div class="filter-group">
                            <label class="filter-label">Filter By Status</label>
                            <select class="filter-select" id="filter-status">
                                <option value="all" <?php echo $filter === 'all' ? 'selected' : ''; ?>>All Feedback</option>
                                <option value="pending" <?php echo $filter === 'pending' ? 'selected' : ''; ?>>Pending Review</option>
                                <option value="approved" <?php echo $filter === 'approved' ? 'selected' : ''; ?>>Approved</option>
                                <option value="anonymous" <?php echo $filter === 'anonymous' ? 'selected' : ''; ?>>Anonymous Only</option>
                                <option value="named" <?php echo $filter === 'named' ? 'selected' : ''; ?>>Named Only</option>
                            </select>
                        </div>
                        
                        <div class="filter-group">
                            <label class="filter-label">Search Feedback</label>
                            <div class="search-box">
                                <i class='bx bx-search search-icon'></i>
                                <input type="text" class="search-input" id="search-feedback" placeholder="Search by message, name, or email..." value="<?php echo htmlspecialchars($search); ?>">
                            </div>
                        </div>
                        
                        <button class="apply-filters" id="apply-filters">
                            <i class='bx bx-filter-alt'></i>
                            Apply Filters
                        </button>
                    </div>
                    
                    <!-- Feedback Table -->
                    <div class="feedback-table-container">
                        <div class="table-header">
                            <h3 class="table-title">Community Feedback Management</h3>
                            <div class="table-actions">
                                <span class="table-info">Showing <?php echo count($feedbacks); ?> of <?php echo $total_records; ?> feedback entries</span>
                            </div>
                        </div>
                        
                        <?php if (count($feedbacks) > 0): ?>
                            <table class="feedback-table">
                                <thead>
                                    <tr>
                                        <th>User / Rating</th>
                                        <th>Feedback Message</th>
                                        <th>Status</th>
                                        <th>Date</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($feedbacks as $feedback): ?>
                                        <tr data-id="<?php echo $feedback['id']; ?>">
                                            <td>
                                                <div>
                                                    <div class="feedback-name">
                                                        <?php echo htmlspecialchars($feedback['display_name']); ?>
                                                    </div>
                                                    <div class="rating-stars">
                                                        <?php for ($i = 1; $i <= 5; $i++): ?>
                                                            <i class='bx bxs-star star <?php echo $i <= $feedback['rating'] ? '' : 'empty'; ?>'></i>
                                                        <?php endfor; ?>
                                                        <span style="margin-left: 8px; font-size: 13px; color: var(--text-light);">
                                                            (<?php echo $feedback['rating']; ?>/5)
                                                        </span>
                                                    </div>
                                                </div>
                                            </td>
                                            <td>
                                                <div class="feedback-message" title="<?php echo htmlspecialchars($feedback['message']); ?>">
                                                    <?php echo htmlspecialchars(substr($feedback['message'], 0, 100)); ?>
                                                    <?php if (strlen($feedback['message']) > 100): ?>...<?php endif; ?>
                                                </div>
                                            </td>
                                            <td>
                                                <?php if ($feedback['is_approved']): ?>
                                                    <span class="status-badge status-approved">
                                                        <i class='bx bx-check-circle'></i>
                                                        Approved
                                                    </span>
                                                <?php else: ?>
                                                    <span class="status-badge status-pending">
                                                        <i class='bx bx-time'></i>
                                                        Pending
                                                    </span>
                                                <?php endif; ?>
                                                
                                                <?php if ($feedback['is_anonymous']): ?>
                                                    <span class="status-badge status-anonymous" style="margin-top: 5px; display: inline-block;">
                                                        <i class='bx bx-user-x'></i>
                                                        Anonymous
                                                    </span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <div style="font-weight: 500;">
                                                    <?php echo date('M d, Y', strtotime($feedback['created_at'])); ?>
                                                </div>
                                                <div style="font-size: 12px; color: var(--text-light);">
                                                    <?php echo date('h:i A', strtotime($feedback['created_at'])); ?>
                                                </div>
                                            </td>
                                            <td>
                                                <div class="action-buttons">
                                                    <button class="action-button view-button" onclick="viewFeedback(<?php echo $feedback['id']; ?>)">
                                                        <i class='bx bx-show'></i>
                                                        View
                                                    </button>
                                                    
                                                    <?php if (!$feedback['is_approved']): ?>
                                                        <form method="POST" style="display: inline;">
                                                            <input type="hidden" name="feedback_id" value="<?php echo $feedback['id']; ?>">
                                                            <input type="hidden" name="action" value="approve">
                                                            <button type="submit" class="action-button approve-button" onclick="return confirm('Approve this feedback?')">
                                                                <i class='bx bx-check'></i>
                                                                Approve
                                                            </button>
                                                        </form>
                                                    <?php else: ?>
                                                        <form method="POST" style="display: inline;">
                                                            <input type="hidden" name="feedback_id" value="<?php echo $feedback['id']; ?>">
                                                            <input type="hidden" name="action" value="reject">
                                                            <button type="submit" class="action-button reject-button" onclick="return confirm('Mark this feedback as pending?')">
                                                                <i class='bx bx-x'></i>
                                                                Unapprove
                                                            </button>
                                                        </form>
                                                    <?php endif; ?>
                                                    
                                                    <button class="action-button delete-button" onclick="showDeleteConfirmation(<?php echo $feedback['id']; ?>, '<?php echo htmlspecialchars(addslashes(substr($feedback['message'], 0, 50))); ?>')">
                                                        <i class='bx bx-trash'></i>
                                                        Delete
                                                    </button>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                            
                            <!-- Pagination -->
                            <div class="pagination">
                                <?php if ($page > 1): ?>
                                    <a href="?page=<?php echo $page - 1; ?>&filter=<?php echo $filter; ?>&search=<?php echo urlencode($search); ?>" class="pagination-button">
                                        <i class='bx bx-chevron-left'></i>
                                        Previous
                                    </a>
                                <?php else: ?>
                                    <button class="pagination-button" disabled>
                                        <i class='bx bx-chevron-left'></i>
                                        Previous
                                    </button>
                                <?php endif; ?>
                                
                                <div class="pagination-info">
                                    Page <?php echo $page; ?> of <?php echo $total_pages; ?>
                                </div>
                                
                                <div class="pagination-numbers">
                                    <?php
                                    // Show page numbers
                                    $start_page = max(1, $page - 2);
                                    $end_page = min($total_pages, $page + 2);
                                    
                                    for ($i = $start_page; $i <= $end_page; $i++):
                                    ?>
                                        <a href="?page=<?php echo $i; ?>&filter=<?php echo $filter; ?>&search=<?php echo urlencode($search); ?>" class="page-number <?php echo $i == $page ? 'active' : ''; ?>">
                                            <?php echo $i; ?>
                                        </a>
                                    <?php endfor; ?>
                                </div>
                                
                                <?php if ($page < $total_pages): ?>
                                    <a href="?page=<?php echo $page + 1; ?>&filter=<?php echo $filter; ?>&search=<?php echo urlencode($search); ?>" class="pagination-button">
                                        Next
                                        <i class='bx bx-chevron-right'></i>
                                    </a>
                                <?php else: ?>
                                    <button class="pagination-button" disabled>
                                        Next
                                        <i class='bx bx-chevron-right'></i>
                                    </button>
                                <?php endif; ?>
                            </div>
                        <?php else: ?>
                            <div class="no-feedback">
                                <div class="no-feedback-icon">
                                    <i class='bx bx-message-square-x'></i>
                                </div>
                                <h3>No Feedback Found</h3>
                                <p>No feedback entries match your current filters.</p>
                                <a href="approve_feedback.php" class="primary-button" style="margin-top: 20px; display: inline-flex;">
                                    <i class='bx bx-reset'></i>
                                    Reset Filters
                                </a>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <script>
        // View Feedback Details
        function viewFeedback(feedbackId) {
            // Show loading state
            document.getElementById('view-modal-body').innerHTML = `
                <div style="text-align: center; padding: 40px;">
                    <i class='bx bx-loader-circle bx-spin' style="font-size: 48px; color: var(--primary-color);"></i>
                    <p style="margin-top: 16px; color: var(--text-light);">Loading feedback details...</p>
                </div>
            `;
            
            // Show view modal
            document.getElementById('view-modal').classList.add('active');
            
            // Fetch feedback details via AJAX
            fetch(`get_feedback_details.php?id=${feedbackId}`)
                .then(response => {
                    if (!response.ok) {
                        throw new Error('Network response was not ok');
                    }
                    return response.json();
                })
                .then(data => {
                    if (data.success) {
                        populateViewModal(data.feedback);
                    } else {
                        throw new Error(data.message || 'Failed to load feedback');
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    document.getElementById('view-modal-body').innerHTML = `
                        <div style="text-align: center; padding: 40px;">
                            <i class='bx bx-error' style="font-size: 48px; color: var(--danger);"></i>
                            <h3 style="margin-top: 16px;">Error Loading Feedback</h3>
                            <p style="color: var(--text-light);">${error.message}</p>
                        </div>
                    `;
                });
        }
        
        // Populate View Modal with Feedback Details
        function populateViewModal(feedback) {
            const modalBody = document.getElementById('view-modal-body');
            
            // Create stars HTML
            let starsHtml = '';
            for (let i = 1; i <= 5; i++) {
                starsHtml += `<i class='bx bxs-star star ${i <= feedback.rating ? '' : 'empty'}'></i>`;
            }
            
            // Format date
            const date = new Date(feedback.created_at);
            const formattedDate = date.toLocaleDateString('en-US', {
                year: 'numeric',
                month: 'long',
                day: 'numeric',
                hour: '2-digit',
                minute: '2-digit'
            });
            
            let html = `
                <div class="feedback-modal-header">
                    <div class="feedback-modal-avatar">
                        ${feedback.is_anonymous ? 'A' : (feedback.name ? feedback.name.charAt(0).toUpperCase() : 'U')}
                    </div>
                    <div class="feedback-modal-user">
                        <div class="feedback-modal-name">
                            ${feedback.is_anonymous ? 'Anonymous User' : (feedback.name || 'Unknown User')}
                        </div>
                        <div class="feedback-modal-rating">
                            ${starsHtml}
                            <span style="font-size: 14px; color: var(--text-light);">
                                ${feedback.rating}/5 Rating
                            </span>
                        </div>
                    </div>
                </div>
                
                <div class="feedback-modal-message">
                    ${feedback.message.replace(/\n/g, '<br>')}
                </div>
                
                <div class="feedback-modal-info">
                    <div class="info-item">
                        <div class="info-label">Email</div>
                        <div class="info-value">${feedback.email || 'Not provided'}</div>
                    </div>
                    <div class="info-item">
                        <div class="info-label">Status</div>
                        <div class="info-value">
                            ${feedback.is_approved ? 
                                '<span class="status-badge status-approved" style="font-size: 12px;"><i class="bx bx-check-circle"></i> Approved</span>' : 
                                '<span class="status-badge status-pending" style="font-size: 12px;"><i class="bx bx-time"></i> Pending</span>'}
                        </div>
                    </div>
                    <div class="info-item">
                        <div class="info-label">Submitted</div>
                        <div class="info-value">${formattedDate}</div>
                    </div>
                    <div class="info-item">
                        <div class="info-label">Visibility</div>
                        <div class="info-value">
                            ${feedback.is_anonymous ? 
                                '<span class="status-badge status-anonymous" style="font-size: 12px;"><i class="bx bx-user-x"></i> Anonymous</span>' : 
                                '<span style="color: var(--success);"><i class="bx bx-user-check"></i> Public</span>'}
                        </div>
                    </div>
                </div>
            `;
            
            modalBody.innerHTML = html;
        }
        
        // Show Delete Confirmation Modal
        function showDeleteConfirmation(feedbackId, messagePreview) {
            document.getElementById('delete-feedback-id').value = feedbackId;
            document.getElementById('delete-message').textContent = 
                `Are you sure you want to delete the feedback "${messagePreview}..."? This action cannot be undone.`;
            document.getElementById('delete-modal').classList.add('active');
        }
        
        // Close View Modal
        function closeViewModal() {
            document.getElementById('view-modal').classList.remove('active');
        }
        
        // Close Delete Modal
        function closeDeleteModal() {
            document.getElementById('delete-modal').classList.remove('active');
        }
        
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
            
            // Refresh button
            document.getElementById('refresh-button').addEventListener('click', function() {
                showNotification('info', 'Refreshing', 'Reloading feedback data...');
                setTimeout(() => {
                    window.location.href = 'approve_feedback.php';
                }, 500);
            });
            
            // Export button
            document.getElementById('export-button').addEventListener('click', function() {
                showNotification('info', 'Export Started', 'Preparing feedback report for download');
                // In real implementation, trigger export process
            });
            
            // Apply filters button
            document.getElementById('apply-filters').addEventListener('click', function() {
                const filter = document.getElementById('filter-status').value;
                const search = document.getElementById('search-feedback').value;
                
                let url = `approve_feedback.php?filter=${filter}`;
                if (search) {
                    url += `&search=${encodeURIComponent(search)}`;
                }
                
                window.location.href = url;
            });
            
            // Header search input
            document.getElementById('header-search-input').addEventListener('keypress', function(e) {
                if (e.key === 'Enter') {
                    const search = this.value;
                    const filter = document.getElementById('filter-status').value;
                    
                    let url = `approve_feedback.php?filter=${filter}`;
                    if (search) {
                        url += `&search=${encodeURIComponent(search)}`;
                    }
                    
                    window.location.href = url;
                }
            });
            
            // Search feedback input
            document.getElementById('search-feedback').addEventListener('keypress', function(e) {
                if (e.key === 'Enter') {
                    document.getElementById('apply-filters').click();
                }
            });
            
            // Modal close events
            document.getElementById('view-modal-close').addEventListener('click', closeViewModal);
            document.getElementById('view-close').addEventListener('click', closeViewModal);
            document.getElementById('delete-cancel').addEventListener('click', closeDeleteModal);
            
            // Close modals when clicking outside
            document.addEventListener('click', function(e) {
                if (e.target.classList.contains('modal-overlay')) {
                    closeViewModal();
                    closeDeleteModal();
                }
            });
            
            // Escape key to close modals
            document.addEventListener('keydown', function(e) {
                if (e.key === 'Escape') {
                    closeViewModal();
                    closeDeleteModal();
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
            
            // Auto-close notifications after 5 seconds
            const notifications = document.querySelectorAll('.notification.show');
            notifications.forEach(notification => {
                setTimeout(() => {
                    if (notification.parentNode) {
                        notification.classList.remove('show');
                        setTimeout(() => {
                            if (notification.parentNode) {
                                notification.parentNode.removeChild(notification);
                            }
                        }, 300);
                    }
                }, 5000);
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
            
            // Auto-close success notification after 5 seconds
            const successNotification = document.querySelector('.notification-success');
            if (successNotification) {
                setTimeout(() => {
                    successNotification.classList.remove('show');
                    setTimeout(() => {
                        if (successNotification.parentNode) {
                            successNotification.parentNode.removeChild(successNotification);
                        }
                    }, 300);
                }, 5000);
            }
        });

        // Initialize time and set interval
        updateTime();
        setInterval(updateTime, 1000);
    </script>
</body>
</html>