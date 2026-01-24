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

// Get filter parameters
$status_filter = isset($_GET['status']) ? $_GET['status'] : 'all';
$search_term = isset($_GET['search']) ? $_GET['search'] : '';

// Build query with filters
$where_conditions = [];
$params = [];

if (!empty($status_filter) && $status_filter !== 'all') {
    $where_conditions[] = "status = ?";
    $params[] = $status_filter;
}

if (!empty($search_term)) {
    $where_conditions[] = "(full_name LIKE ? OR email LIKE ? OR contact_number LIKE ?)";
    $params[] = "%$search_term%";
    $params[] = "%$search_term%";
    $params[] = "%$search_term%";
}

$where_clause = '';
if (!empty($where_conditions)) {
    $where_clause = "WHERE " . implode(" AND ", $where_conditions);
}

// Fetch volunteer applications with filters
$volunteers_query = "SELECT * FROM volunteers $where_clause ORDER BY created_at DESC";
$volunteers_stmt = $pdo->prepare($volunteers_query);
$volunteers_stmt->execute($params);
$volunteers = $volunteers_stmt->fetchAll();

// Get counts for each status
$status_counts_query = "SELECT status, COUNT(*) as count FROM volunteers GROUP BY status";
$status_counts_stmt = $pdo->prepare($status_counts_query);
$status_counts_stmt->execute();
$status_counts = $status_counts_stmt->fetchAll(PDO::FETCH_KEY_PAIR);

$stmt = null;
$volunteers_stmt = null;
$status_counts_stmt = null;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Review Data Management - Fire & Rescue Services</title>
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
            --card-bg: #ffffff;
            --sidebar-bg: #f9fafb;

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
            
            --icon-bg-red: #fee2e2;
            --icon-bg-blue: #dbeafe;
            --icon-bg-green: #dcfce7;
            --icon-bg-purple: #f3e8ff;
            --icon-bg-yellow: #fef3c7;
            --icon-bg-indigo: #e0e7ff;
            --icon-bg-cyan: #cffafe;
            --icon-bg-orange: #ffedd5;
            --icon-bg-pink: #fce7f3;
            --icon-bg-teal: #ccfbf1;

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
            --sidebar-bg: #111827;
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
            padding: 40px;
            margin-bottom: 20px;
            position: relative;
            overflow: hidden;
            background: var(--background-color);
            border-bottom: 1px solid var(--border-color);
        }

        .dashboard-title {
            font-size: 32px;
            margin-bottom: 8px;
            color: var(--text-color);
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
            border-radius: 8px;
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
            background: var(--primary-color);
            color: white;
        }

        .primary-button:hover {
            background: var(--primary-dark);
            transform: translateY(-1px);
        }

        .secondary-button {
            background: var(--gray-100);
            border: 1px solid var(--border-color);
            color: var(--text-color);
        }

        .secondary-button:hover {
            background: var(--gray-200);
            transform: translateY(-1px);
        }

        .dark-mode .secondary-button {
            background: var(--gray-800);
            border-color: var(--border-color);
        }

        .dark-mode .secondary-button:hover {
            background: var(--gray-700);
        }

        .review-data-container {
            display: flex;
            flex-direction: column;
            gap: 24px;
            padding: 0 40px 40px;
        }
        
        .review-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 24px;
        }
        
        .review-title {
            font-size: 28px;
            font-weight: 800;
            color: var(--text-color);
            margin-bottom: 8px;
        }
        
        .review-subtitle {
            color: var(--text-light);
            font-size: 16px;
        }
        
        .filters-container {
            display: flex;
            gap: 16px;
            margin-bottom: 24px;
            flex-wrap: wrap;
            align-items: flex-end;
            position: relative;
            z-index: 100;
        }
        
        .filter-group {
            display: flex;
            flex-direction: column;
            gap: 8px;
            position: relative;
            z-index: 101;
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
            border-radius: 8px;
            border: 1px solid var(--border-color);
            background: var(--card-bg);
            font-size: 14px;
            min-width: 180px;
            transition: all 0.3s ease;
            position: relative;
            z-index: 101;
        }
        
        .filter-select:focus, .filter-input:focus {
            outline: none;
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(220, 38, 38, 0.1);
            z-index: 102;
        }
        
        .stats-container {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(240px, 1fr));
            gap: 16px;
            margin-bottom: 24px;
            position: relative;
            z-index: 1;
        }
        
        .stat-card {
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: 12px;
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
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
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
            background: var(--primary-color);
        }
        
        .stat-card[data-status="pending"]::before {
            background: var(--warning);
        }
        
        .stat-card[data-status="approved"]::before {
            background: var(--success);
        }
        
        .stat-card[data-status="rejected"]::before {
            background: var(--danger);
        }
        
        .stat-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
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
        
        .stat-card[data-status="pending"] .stat-icon {
            background: rgba(245, 158, 11, 0.1);
            color: var(--warning);
        }
        
        .stat-card[data-status="approved"] .stat-icon {
            background: rgba(16, 185, 129, 0.1);
            color: var(--success);
        }
        
        .stat-card[data-status="rejected"] .stat-icon {
            background: rgba(220, 38, 38, 0.1);
            color: var(--danger);
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
        
        .volunteers-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));
            gap: 20px;
        }
        
        .volunteer-card {
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: 12px;
            padding: 20px;
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
        }
        
        .volunteer-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
        }
        
        .volunteer-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: var(--primary-color);
        }
        
        .volunteer-header {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            margin-bottom: 16px;
        }
        
        .volunteer-avatar {
            width: 50px;
            height: 50px;
            border-radius: 50%;
            background: var(--primary-color);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 700;
            font-size: 18px;
            margin-right: 12px;
        }
        
        .volunteer-info {
            flex: 1;
        }
        
        .volunteer-name {
            font-size: 18px;
            font-weight: 700;
            margin-bottom: 4px;
        }
        
        .volunteer-email {
            color: var(--text-light);
            font-size: 14px;
            margin-bottom: 8px;
        }
        
        .volunteer-status {
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            text-transform: uppercase;
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
        
        .status-rejected {
            background: rgba(220, 38, 38, 0.1);
            color: var(--danger);
            border: 1px solid rgba(220, 38, 38, 0.2);
        }
        
        .volunteer-details {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 12px;
            margin-bottom: 20px;
        }
        
        .detail-item {
            display: flex;
            flex-direction: column;
        }
        
        .detail-label {
            font-size: 12px;
            color: var(--text-light);
            margin-bottom: 4px;
        }
        
        .detail-value {
            font-size: 14px;
            font-weight: 500;
        }
        
        .volunteer-actions {
            display: flex;
            gap: 8px;
        }
        
        .action-button {
            flex: 1;
            padding: 8px 12px;
            border-radius: 6px;
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
        
        .view-button {
            background-color: rgba(59, 130, 246, 0.1);
            color: var(--info);
            border: 1px solid rgba(59, 130, 246, 0.2);
        }
        
        .view-button:hover {
            background-color: var(--info);
            color: white;
        }
        
        .approve-button {
            background-color: rgba(16, 185, 129, 0.1);
            color: var(--success);
            border: 1px solid rgba(16, 185, 129, 0.2);
        }
        
        .approve-button:hover {
            background-color: var(--success);
            color: white;
        }
        
        .reject-button {
            background-color: rgba(220, 38, 38, 0.1);
            color: var(--danger);
            border: 1px solid rgba(220, 38, 38, 0.2);
        }
        
        .reject-button:hover {
            background-color: var(--danger);
            color: white;
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
            border-radius: 6px;
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
        
        .no-volunteers {
            text-align: center;
            padding: 60px 20px;
            color: var(--text-light);
            grid-column: 1 / -1;
        }
        
        .no-volunteers-icon {
            font-size: 64px;
            margin-bottom: 16px;
            color: var(--text-light);
            opacity: 0.5;
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
            border-radius: 12px;
            width: 90%;
            max-width: 900px;
            max-height: 90vh;
            overflow-y: auto;
            transform: scale(0.9);
            transition: all 0.3s ease;
            box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1), 0 10px 10px -5px rgba(0, 0, 0, 0.04);
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
            margin-bottom: 30px;
        }
        
        .modal-section-title {
            font-size: 18px;
            font-weight: 700;
            margin-bottom: 16px;
            padding-bottom: 8px;
            border-bottom: 1px solid var(--border-color);
            color: var(--primary-color);
        }
        
        .modal-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(250px, 1fr));
            gap: 16px;
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
            font-weight: 500;
        }
        
        .skills-list {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
        }
        
        .skill-tag {
            padding: 4px 12px;
            background: rgba(220, 38, 38, 0.1);
            color: var(--primary-color);
            border-radius: 20px;
            font-size: 14px;
            border: 1px solid rgba(220, 38, 38, 0.2);
        }
        
        .skill-tag.active {
            background: var(--primary-color);
            color: white;
        }
        
        .id-photos-container {
            display: flex;
            gap: 20px;
            margin-top: 16px;
        }
        
        .id-photo-wrapper {
            flex: 1;
            text-align: center;
        }
        
        .id-photo-label {
            font-size: 14px;
            color: var(--text-light);
            margin-bottom: 8px;
        }
        
        .id-photo {
            max-width: 100%;
            max-height: 300px;
            border-radius: 8px;
            border: 1px solid var(--border-color);
            object-fit: contain;
            background: #f8f9fa;
        }
        
        .dark-mode .id-photo {
            background: #374151;
        }
        
        .no-photo {
            padding: 40px;
            text-align: center;
            color: var(--text-light);
            background: var(--gray-100);
            border-radius: 8px;
        }
        
        .dark-mode .no-photo {
            background: var(--gray-800);
        }
        
        .modal-footer {
            padding: 20px 24px;
            border-top: 1px solid var(--border-color);
            display: flex;
            justify-content: flex-end;
            gap: 12px;
        }
        
        .modal-button {
            padding: 10px 20px;
            border-radius: 8px;
            font-weight: 600;
            border: none;
            cursor: pointer;
            transition: all 0.3s ease;
        }
        
        .modal-approve {
            background: var(--success);
            color: white;
        }
        
        .modal-approve:hover {
            background: #0d8c5f;
        }
        
        .modal-reject {
            background: var(--danger);
            color: white;
        }
        
        .modal-reject:hover {
            background: #c81e1e;
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
        
        /* Notification Styles */
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
            border-radius: 8px;
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            display: flex;
            align-items: center;
            gap: 12px;
            transform: translateX(100%);
            opacity: 0;
            transition: all 0.3s ease;
            max-width: 350px;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
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

        /* User Profile Dropdown - FIXED POSITIONING */
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
            border-radius: 8px;
            padding: 8px;
            min-width: 200px;
            z-index: 1001;
            opacity: 0;
            visibility: hidden;
            transform: translateY(-10px);
            transition: all 0.3s ease;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
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
            border-radius: 6px;
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

        /* Notification Bell - FIXED POSITIONING */
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

        /* Notification Dropdown - FIXED POSITIONING */
        .notification-dropdown {
            position: absolute;
            top: 100%;
            right: 0;
            margin-top: 8px;
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: 8px;
            width: 320px;
            max-height: 400px;
            overflow-y: auto;
            z-index: 1001;
            opacity: 0;
            visibility: hidden;
            transform: translateY(-10px);
            transition: all 0.3s ease;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
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
        
        @media (max-width: 768px) {
            .volunteers-grid {
                grid-template-columns: 1fr;
            }
            
            .filters-container {
                flex-direction: column;
            }
            
            .filter-select, .filter-input {
                min-width: 100%;
            }
            
            .modal-grid {
                grid-template-columns: 1fr;
            }
            
            .id-photos-container {
                flex-direction: column;
            }
            
            .modal-footer {
                flex-direction: column;
            }

            .dashboard-header {
                padding: 30px 20px;
            }
            
            .dashboard-title {
                font-size: 28px;
            }
            
            .review-data-container {
                padding: 0 20px 30px;
            }
            
            .stats-container {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    
    <!-- Notification Container -->
    <div class="notification-container" id="notification-container"></div>
    
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
                    <div id="user-management" class="submenu">
                        <a href="../users/manage_users.php" class="submenu-item">Manage Users</a>
                        <a href="../users/role_control.php" class="submenu-item">Role Control</a>
                        <a href="../users/audit_logs.php" class="submenu-item">Audit & Activity Logs</a>
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
                    <div id="volunteer-management" class="submenu active">
                        <a href="review_data.php" class="submenu-item active">Review Data</a>
                        <a href="approve_applications.php" class="submenu-item">Assign Volunteers</a>
                        <a href="view_availability.php" class="submenu-item">View Availability</a>
                        <a href="toggle_volunteer_registration.php" class="submenu-item">Toggle Registration</a>
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
                            <img src="../../img/rei.jfif" alt="User" class="user-avatar">
                            <div class="user-info">
                                <p class="user-name"><?php echo $full_name; ?></p>
                                <p class="user-email"><?php echo $role; ?></p>
                            </div>
                            <div class="user-profile-dropdown" id="user-dropdown">
                                <a href="../profile.php" class="dropdown-item">
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
                        <h1 class="dashboard-title">Review Data Management</h1>
                        <p class="dashboard-subtitle">Manage and review volunteer applications</p>
                    </div>
                    <div class="dashboard-actions">
                        <button class="primary-button" id="export-button">
                            <i class='bx bx-export'></i>
                            Export Reports
                        </button>
                        <button class="secondary-button" id="refresh-button">
                            <i class='bx bx-refresh'></i>
                            Refresh Data
                        </button>
                    </div>
                </div>
                
                <!-- Review Data Section -->
                <div class="review-data-container">
                    <!-- Stats Cards -->
                    <div class="stats-container">
                        <div class="stat-card <?php echo $status_filter === 'all' ? 'active' : ''; ?>" data-status="all">
                            <div class="stat-icon">
                                <i class='bx bxs-user'></i>
                            </div>
                            <div class="stat-value"><?php echo array_sum($status_counts); ?></div>
                            <div class="stat-label">Total Applications</div>
                        </div>
                        <div class="stat-card <?php echo $status_filter === 'pending' ? 'active' : ''; ?>" data-status="pending">
                            <div class="stat-icon">
                                <i class='bx bx-time-five'></i>
                            </div>
                            <div class="stat-value"><?php echo isset($status_counts['pending']) ? $status_counts['pending'] : 0; ?></div>
                            <div class="stat-label">Pending Review</div>
                        </div>
                        <div class="stat-card <?php echo $status_filter === 'approved' ? 'active' : ''; ?>" data-status="approved">
                            <div class="stat-icon">
                                <i class='bx bx-check-circle'></i>
                            </div>
                            <div class="stat-value"><?php echo isset($status_counts['approved']) ? $status_counts['approved'] : 0; ?></div>
                            <div class="stat-label">Approved</div>
                        </div>
                        <div class="stat-card <?php echo $status_filter === 'rejected' ? 'active' : ''; ?>" data-status="rejected">
                            <div class="stat-icon">
                                <i class='bx bx-x-circle'></i>
                            </div>
                            <div class="stat-value"><?php echo isset($status_counts['rejected']) ? $status_counts['rejected'] : 0; ?></div>
                            <div class="stat-label">Rejected</div>
                        </div>
                    </div>
                    
                    <!-- Enhanced Filters -->
                    <div class="filters-container">
                        <div class="filter-group">
                            <label class="filter-label">Status</label>
                            <select class="filter-select" id="status-filter">
                                <option value="all" <?php echo $status_filter === 'all' ? 'selected' : ''; ?>>All Statuses</option>
                                <option value="pending" <?php echo $status_filter === 'pending' ? 'selected' : ''; ?>>Pending</option>
                                <option value="approved" <?php echo $status_filter === 'approved' ? 'selected' : ''; ?>>Approved</option>
                                <option value="rejected" <?php echo $status_filter === 'rejected' ? 'selected' : ''; ?>>Rejected</option>
                            </select>
                        </div>
                        <div class="filter-group">
                            <label class="filter-label">Search</label>
                            <input type="text" class="filter-input" id="search-filter" placeholder="Search by name, email, or phone..." value="<?php echo htmlspecialchars($search_term); ?>">
                        </div>
                        <div class="filter-group">
                            <label class="filter-label">Sort By</label>
                            <select class="filter-select" id="sort-filter">
                                <option value="newest">Newest First</option>
                                <option value="oldest">Oldest First</option>
                                <option value="name">Name A-Z</option>
                            </select>
                        </div>
                        <div class="filter-group" style="align-self: flex-end;">
                            <button class="action-button view-button" id="apply-filters">
                                <i class='bx bx-filter-alt'></i>
                                Apply Filters
                            </button>
                        </div>
                        <div class="filter-group" style="align-self: flex-end;">
                            <button class="action-button reject-button" id="reset-filters">
                                <i class='bx bx-reset'></i>
                                Reset
                            </button>
                        </div>
                    </div>
                    
                    <!-- Volunteers Grid -->
                    <div class="volunteers-grid">
                        <?php if (count($volunteers) > 0): ?>
                            <?php foreach ($volunteers as $volunteer): ?>
                                <div class="volunteer-card" data-id="<?php echo $volunteer['id']; ?>">
                                    <div class="volunteer-header">
                                        <div class="volunteer-avatar">
                                            <?php echo strtoupper(substr($volunteer['full_name'], 0, 1)); ?>
                                        </div>
                                        <div class="volunteer-info">
                                            <h3 class="volunteer-name"><?php echo htmlspecialchars($volunteer['full_name']); ?></h3>
                                            <p class="volunteer-email"><?php echo htmlspecialchars($volunteer['email']); ?></p>
                                        </div>
                                        <div class="volunteer-status status-<?php echo $volunteer['status']; ?>">
                                            <?php echo ucfirst($volunteer['status']); ?>
                                        </div>
                                    </div>
                                    
                                    <div class="volunteer-details">
                                        <div class="detail-item">
                                            <div class="detail-label">Date of Birth</div>
                                            <div class="detail-value"><?php echo htmlspecialchars($volunteer['date_of_birth']); ?></div>
                                        </div>
                                        <div class="detail-item">
                                            <div class="detail-label">Contact</div>
                                            <div class="detail-value"><?php echo htmlspecialchars($volunteer['contact_number']); ?></div>
                                        </div>
                                        <div class="detail-item">
                                            <div class="detail-label">ID Type</div>
                                            <div class="detail-value"><?php echo htmlspecialchars($volunteer['valid_id_type']); ?></div>
                                        </div>
                                        <div class="detail-item">
                                            <div class="detail-label">Applied</div>
                                            <div class="detail-value"><?php echo htmlspecialchars($volunteer['application_date']); ?></div>
                                        </div>
                                    </div>
                                    
                                    <div class="volunteer-actions">
                                        <button class="action-button view-button" onclick="viewVolunteer(<?php echo $volunteer['id']; ?>)">
                                            <i class='bx bx-show'></i>
                                            View
                                        </button>
                                        <?php if ($volunteer['status'] === 'pending'): ?>
                                            <button class="action-button approve-button" onclick="approveApplication(<?php echo $volunteer['id']; ?>)">
                                                <i class='bx bx-check'></i>
                                                Approve
                                            </button>
                                            <button class="action-button reject-button" onclick="rejectApplication(<?php echo $volunteer['id']; ?>)">
                                                <i class='bx bx-x'></i>
                                                Reject
                                            </button>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="no-volunteers">
                                <div class="no-volunteers-icon">
                                    <i class='bx bx-user-x'></i>
                                </div>
                                <h3>No Volunteer Applications Found</h3>
                                <p>No applications match your current filters.</p>
                            </div>
                        <?php endif; ?>
                    </div>
                    
                    <!-- Pagination -->
                    <div class="pagination">
                        <button class="pagination-button" id="prev-page" disabled>
                            <i class='bx bx-chevron-left'></i>
                            Previous
                        </button>
                        <span class="pagination-info">Page 1 of 1</span>
                        <button class="pagination-button" id="next-page" disabled>
                            Next
                            <i class='bx bx-chevron-right'></i>
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Volunteer Details Modal -->
    <div class="modal-overlay" id="volunteer-modal">
        <div class="modal">
            <div class="modal-header">
                <h2 class="modal-title">Volunteer Application Details</h2>
                <button class="modal-close" id="modal-close">&times;</button>
            </div>
            <div class="modal-body" id="modal-body">
                <!-- Content will be loaded via JavaScript -->
            </div>
            <div class="modal-footer">
                <button class="modal-button modal-secondary" id="modal-close-btn">Close</button>
                <button class="modal-button modal-reject" id="modal-reject-btn">Reject</button>
                <button class="modal-button modal-approve" id="modal-approve-btn">Approve</button>
            </div>
        </div>
    </div>
    
    <script>
        // Global variables
        let currentVolunteerId = null;
        let currentPage = 1;
        const itemsPerPage = 9;
        
        document.addEventListener('DOMContentLoaded', function() {
            // Show welcome notification
            showNotification('success', 'System Ready', 'Volunteer review system is now active');
            
            // Initialize event listeners
            initEventListeners();
            
            // Check for new applications periodically
            setInterval(checkForNewApplications, 30000);
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
            
            // Modal functionality
            document.getElementById('modal-close').addEventListener('click', closeModal);
            document.getElementById('modal-close-btn').addEventListener('click', closeModal);
            document.getElementById('modal-approve-btn').addEventListener('click', function() {
                if (currentVolunteerId) {
                    approveApplication(currentVolunteerId);
                }
            });
            document.getElementById('modal-reject-btn').addEventListener('click', function() {
                if (currentVolunteerId) {
                    rejectApplication(currentVolunteerId);
                }
            });
            
            // Export and refresh buttons
            document.getElementById('export-button').addEventListener('click', exportReports);
            document.getElementById('refresh-button').addEventListener('click', refreshData);
            
            // Pagination
            document.getElementById('prev-page').addEventListener('click', previousPage);
            document.getElementById('next-page').addEventListener('click', nextPage);
            
            // Keyboard shortcuts
            document.addEventListener('keydown', function(e) {
                // Search shortcut - forward slash
                if (e.key === '/' && !e.ctrlKey && !e.altKey && !e.metaKey) {
                    e.preventDefault();
                    document.getElementById('search-input').focus();
                }
                
                // Escape key to close modal
                if (e.key === 'Escape') {
                    closeModal();
                    userDropdown.classList.remove('show');
                    notificationDropdown.classList.remove('show');
                }
            });
        }
        
        function applyFilters() {
            const status = document.getElementById('status-filter').value;
            const search = document.getElementById('search-filter').value;
            const sort = document.getElementById('sort-filter').value;
            
            let url = 'review_data.php?';
            if (status !== 'all') {
                url += `status=${status}&`;
            }
            if (search) {
                url += `search=${encodeURIComponent(search)}&`;
            }
            if (sort !== 'newest') {
                url += `sort=${sort}`;
            }
            
            window.location.href = url;
        }
        
        function resetFilters() {
            document.getElementById('status-filter').value = 'all';
            document.getElementById('search-filter').value = '';
            document.getElementById('sort-filter').value = 'newest';
            applyFilters();
        }
        
        function viewVolunteer(id) {
            currentVolunteerId = id;
            
            // Show loading state
            document.getElementById('modal-body').innerHTML = `
                <div style="text-align: center; padding: 40px;">
                    <i class='bx bx-loader-circle bx-spin' style="font-size: 48px; color: var(--primary-color);"></i>
                    <p style="margin-top: 16px; color: var(--text-light);">Loading volunteer details...</p>
                </div>
            `;
            
            // Show modal
            document.getElementById('volunteer-modal').classList.add('active');
            
            // Fetch volunteer details via AJAX
            fetch(`get_volunteer_details.php?id=${id}`)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        populateModal(data.volunteer);
                    } else {
                        showNotification('error', 'Error', 'Failed to load volunteer details');
                        closeModal();
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    showNotification('error', 'Error', 'Failed to load volunteer details');
                    closeModal();
                });
        }
        
        function populateModal(data) {
            const modalBody = document.getElementById('modal-body');
            
            // Format ID photo paths - try multiple possible locations
            const getImagePath = (filename) => {
                if (!filename) return null;
                // Try different possible paths
                const paths = [
                    `../../${filename}`,
                    `../${filename}`,
                    filename,
                    `../../uploads/volunteer_id_photos/${filename.split('/').pop()}`,
                    `../uploads/volunteer_id_photos/${filename.split('/').pop()}`
                ];
                
                // Return the first path that exists or the original path
                return paths[0]; 
            };
            
            const frontPhoto = getImagePath(data.id_front_photo);
            const backPhoto = getImagePath(data.id_back_photo);
            
            let html = `
                <div class="modal-section">
                    <h3 class="modal-section-title">Personal Information</h3>
                    <div class="modal-grid">
                        <div class="modal-detail">
                            <div class="modal-detail-label">Full Name</div>
                            <div class="modal-detail-value">${data.full_name}</div>
                        </div>
                        <div class="modal-detail">
                            <div class="modal-detail-label">Date of Birth</div>
                            <div class="modal-detail-value">${data.date_of_birth}</div>
                        </div>
                        <div class="modal-detail">
                            <div class="modal-detail-label">Gender</div>
                            <div class="modal-detail-value">${data.gender}</div>
                        </div>
                        <div class="modal-detail">
                            <div class="modal-detail-label">Civil Status</div>
                            <div class="modal-detail-value">${data.civil_status}</div>
                        </div>
                        <div class="modal-detail">
                            <div class="modal-detail-label">Email</div>
                            <div class="modal-detail-value">${data.email}</div>
                        </div>
                        <div class="modal-detail">
                            <div class="modal-detail-label">Contact Number</div>
                            <div class="modal-detail-value">${data.contact_number}</div>
                        </div>
                        <div class="modal-detail">
                            <div class="modal-detail-label">Social Media</div>
                            <div class="modal-detail-value">${data.social_media || 'N/A'}</div>
                        </div>
                    </div>
                    <div class="modal-detail">
                        <div class="modal-detail-label">Address</div>
                        <div class="modal-detail-value">${data.address}</div>
                    </div>
                </div>
                
                <div class="modal-section">
                    <h3 class="modal-section-title">Identification</h3>
                    <div class="modal-grid">
                        <div class="modal-detail">
                            <div class="modal-detail-label">Valid ID Type</div>
                            <div class="modal-detail-value">${data.valid_id_type}</div>
                        </div>
                        <div class="modal-detail">
                            <div class="modal-detail-label">Valid ID Number</div>
                            <div class="modal-detail-value">${data.valid_id_number}</div>
                        </div>
                    </div>
                    <div class="id-photos-container">
                        <div class="id-photo-wrapper">
                            <div class="id-photo-label">ID Front Photo</div>
                            ${frontPhoto ? 
                                `<img src="${frontPhoto}" alt="ID Front" class="id-photo" onerror="this.style.display='none'; this.nextElementSibling.style.display='block';">
                                 <div class="no-photo" style="display: none;">Image failed to load</div>` : 
                                '<div class="no-photo">No ID Front Photo Uploaded</div>'}
                        </div>
                        <div class="id-photo-wrapper">
                            <div class="id-photo-label">ID Back Photo</div>
                            ${backPhoto ? 
                                `<img src="${backPhoto}" alt="ID Back" class="id-photo" onerror="this.style.display='none'; this.nextElementSibling.style.display='block';">
                                 <div class="no-photo" style="display: none;">Image failed to load</div>` : 
                                '<div class="no-photo">No ID Back Photo Uploaded</div>'}
                        </div>
                    </div>
                </div>
                
                <div class="modal-section">
                    <h3 class="modal-section-title">Emergency Contact</h3>
                    <div class="modal-grid">
                        <div class="modal-detail">
                            <div class="modal-detail-label">Name</div>
                            <div class="modal-detail-value">${data.emergency_contact_name}</div>
                        </div>
                        <div class="modal-detail">
                            <div class="modal-detail-label">Relationship</div>
                            <div class="modal-detail-value">${data.emergency_contact_relationship}</div>
                        </div>
                        <div class="modal-detail">
                            <div class="modal-detail-label">Contact Number</div>
                            <div class="modal-detail-value">${data.emergency_contact_number}</div>
                        </div>
                    </div>
                    <div class="modal-detail">
                        <div class="modal-detail-label">Address</div>
                        <div class="modal-detail-value">${data.emergency_contact_address}</div>
                    </div>
                </div>
                
                <div class="modal-section">
                    <h3 class="modal-section-title">Volunteer Experience & Motivation</h3>
                    <div class="modal-grid">
                        <div class="modal-detail">
                            <div class="modal-detail-label">Volunteered Before</div>
                            <div class="modal-detail-value">${data.volunteered_before}</div>
                        </div>
                        <div class="modal-detail">
                            <div class="modal-detail-label">Currently Employed</div>
                            <div class="modal-detail-value">${data.currently_employed}</div>
                        </div>
                    </div>
                    <div class="modal-detail">
                        <div class="modal-detail-label">Previous Volunteer Experience</div>
                        <div class="modal-detail-value">${data.previous_volunteer_experience || 'None'}</div>
                    </div>
                    <div class="modal-detail">
                        <div class="modal-detail-label">Volunteer Motivation</div>
                        <div class="modal-detail-value">${data.volunteer_motivation}</div>
                    </div>
                </div>
                
                <div class="modal-section">
                    <h3 class="modal-section-title">Education & Employment</h3>
                    <div class="modal-grid">
                        <div class="modal-detail">
                            <div class="modal-detail-label">Education</div>
                            <div class="modal-detail-value">${data.education}</div>
                        </div>
                        <div class="modal-detail">
                            <div class="modal-detail-label">Occupation</div>
                            <div class="modal-detail-value">${data.occupation || 'N/A'}</div>
                        </div>
                        <div class="modal-detail">
                            <div class="modal-detail-label">Company</div>
                            <div class="modal-detail-value">${data.company || 'N/A'}</div>
                        </div>
                    </div>
                    <div class="modal-detail">
                        <div class="modal-detail-label">Specialized Training</div>
                        <div class="modal-detail-value">${data.specialized_training || 'None'}</div>
                    </div>
                </div>
                
                <div class="modal-section">
                    <h3 class="modal-section-title">Skills & Abilities</h3>
                    <div class="modal-grid">
                        <div class="modal-detail">
                            <div class="modal-detail-label">Physical Fitness</div>
                            <div class="modal-detail-value">${data.physical_fitness}</div>
                        </div>
                        <div class="modal-detail">
                            <div class="modal-detail-label">Languages Spoken</div>
                            <div class="modal-detail-value">${data.languages_spoken}</div>
                        </div>
                    </div>
                    <div class="modal-detail">
                        <div class="modal-detail-label">Skills</div>
                        <div class="skills-list">
                            ${data.skills_basic_firefighting ? '<span class="skill-tag active">Basic Firefighting</span>' : '<span class="skill-tag">Basic Firefighting</span>'}
                            ${data.skills_first_aid_cpr ? '<span class="skill-tag active">First Aid/CPR</span>' : '<span class="skill-tag">First Aid/CPR</span>'}
                            ${data.skills_search_rescue ? '<span class="skill-tag active">Search & Rescue</span>' : '<span class="skill-tag">Search & Rescue</span>'}
                            ${data.skills_driving ? '<span class="skill-tag active">Driving</span>' : '<span class="skill-tag">Driving</span>'}
                            ${data.skills_communication ? '<span class="skill-tag active">Communication</span>' : '<span class="skill-tag">Communication</span>'}
                            ${data.skills_mechanical ? '<span class="skill-tag active">Mechanical</span>' : '<span class="skill-tag">Mechanical</span>'}
                            ${data.skills_logistics ? '<span class="skill-tag active">Logistics</span>' : '<span class="skill-tag">Logistics</span>'}
                        </div>
                    </div>
                    ${data.driving_license_no ? `
                    <div class="modal-detail">
                        <div class="modal-detail-label">Driving License Number</div>
                        <div class="modal-detail-value">${data.driving_license_no}</div>
                    </div>` : ''}
                </div>
                
                <div class="modal-section">
                    <h3 class="modal-section-title">Availability & Interests</h3>
                    <div class="modal-grid">
                        <div class="modal-detail">
                            <div class="modal-detail-label">Available Days</div>
                            <div class="modal-detail-value">${data.available_days}</div>
                        </div>
                        <div class="modal-detail">
                            <div class="modal-detail-label">Available Hours</div>
                            <div class="modal-detail-value">${data.available_hours}</div>
                        </div>
                        <div class="modal-detail">
                            <div class="modal-detail-label">Emergency Response</div>
                            <div class="modal-detail-value">${data.emergency_response}</div>
                        </div>
                    </div>
                    <div class="modal-detail">
                        <div class="modal-detail-label">Areas of Interest</div>
                        <div class="skills-list">
                            ${data.area_interest_fire_suppression ? '<span class="skill-tag active">Fire Suppression</span>' : '<span class="skill-tag">Fire Suppression</span>'}
                            ${data.area_interest_rescue_operations ? '<span class="skill-tag active">Rescue Operations</span>' : '<span class="skill-tag">Rescue Operations</span>'}
                            ${data.area_interest_ems ? '<span class="skill-tag active">EMS</span>' : '<span class="skill-tag">EMS</span>'}
                            ${data.area_interest_disaster_response ? '<span class="skill-tag active">Disaster Response</span>' : '<span class="skill-tag">Disaster Response</span>'}
                            ${data.area_interest_admin_logistics ? '<span class="skill-tag active">Admin/Logistics</span>' : '<span class="skill-tag">Admin/Logistics</span>'}
                        </div>
                    </div>
                </div>
                
                <div class="modal-section">
                    <h3 class="modal-section-title">Application Details</h3>
                    <div class="modal-grid">
                        <div class="modal-detail">
                            <div class="modal-detail-label">Application Date</div>
                            <div class="modal-detail-value">${data.application_date}</div>
                        </div>
                        <div class="modal-detail">
                            <div class="modal-detail-label">Status</div>
                            <div class="modal-detail-value">${data.status.charAt(0).toUpperCase() + data.status.slice(1)}</div>
                        </div>
                    </div>
                    <div class="modal-detail">
                        <div class="modal-detail-label">Signature</div>
                        <div class="modal-detail-value">${data.signature}</div>
                    </div>
                </div>
            `;
            
            modalBody.innerHTML = html;
            
            // Show/hide action buttons based on status
            if (data.status === 'pending') {
                document.getElementById('modal-approve-btn').style.display = 'inline-block';
                document.getElementById('modal-reject-btn').style.display = 'inline-block';
            } else {
                document.getElementById('modal-approve-btn').style.display = 'none';
                document.getElementById('modal-reject-btn').style.display = 'none';
            }
        }
        
        function closeModal() {
            document.getElementById('volunteer-modal').classList.remove('active');
            currentVolunteerId = null;
        }
        
        function approveApplication(id) {
            if (confirm('Are you sure you want to approve this volunteer application?')) {
                // Show loading state
                showNotification('info', 'Processing', 'Approving application...');
                
                fetch('update_volunteer_status.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({
                        id: id,
                        status: 'approved'
                    })
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        showNotification('success', 'Application Approved', 'The volunteer application has been approved successfully');
                        closeModal();
                        setTimeout(() => {
                            location.reload();
                        }, 1500);
                    } else {
                        showNotification('error', 'Error', data.message || 'Failed to approve application');
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    showNotification('error', 'Error', 'Failed to approve application');
                });
            }
        }
        
        function rejectApplication(id) {
            if (confirm('Are you sure you want to reject this volunteer application?')) {
                // Show loading state
                showNotification('info', 'Processing', 'Rejecting application...');
                
                fetch('update_volunteer_status.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({
                        id: id,
                        status: 'rejected'
                    })
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        showNotification('error', 'Application Rejected', 'The volunteer application has been rejected');
                        closeModal();
                        setTimeout(() => {
                            location.reload();
                        }, 1500);
                    } else {
                        showNotification('error', 'Error', data.message || 'Failed to reject application');
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    showNotification('error', 'Error', 'Failed to reject application');
                });
            }
        }
        
        function exportReports() {
            showNotification('info', 'Export Started', 'Your report is being generated and will download shortly');
            // In a real implementation, you would trigger the export process
        }
        
        function refreshData() {
            showNotification('info', 'Refreshing Data', 'Fetching the latest volunteer applications');
            location.reload();
        }
        
        function previousPage() {
            if (currentPage > 1) {
                currentPage--;
                updatePagination();
            }
        }
        
        function nextPage() {
            // In a real implementation, you would check if there are more pages
            const totalItems = <?php echo count($volunteers); ?>;
            const totalPages = Math.ceil(totalItems / itemsPerPage);
            
            if (currentPage < totalPages) {
                currentPage++;
                updatePagination();
            }
        }
        
        function updatePagination() {
            const totalItems = <?php echo count($volunteers); ?>;
            const totalPages = Math.ceil(totalItems / itemsPerPage);
            
            document.getElementById('prev-page').disabled = currentPage === 1;
            document.getElementById('next-page').disabled = currentPage === totalPages;
            document.querySelector('.pagination-info').textContent = `Page ${currentPage} of ${totalPages}`;
        }
        
        function checkForNewApplications() {
            fetch('check_new_applications.php')
                .then(response => response.json())
                .then(data => {
                    if (data.success && data.newApplications > 0) {
                        showNotification('info', 'New Applications', `${data.newApplications} new volunteer application(s) submitted`, true);
                        // Update notification badge
                        document.getElementById('notification-count').textContent = data.newApplications;
                    }
                })
                .catch(error => {
                    console.error('Error checking for new applications:', error);
                });
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
            
            // Play sound if requested
            if (playSound) {
                playNotificationSound();
            }
            
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
        
        function playNotificationSound() {
            // Create a simple notification sound
            try {
                const audioContext = new (window.AudioContext || window.webkitAudioContext)();
                const oscillator = audioContext.createOscillator();
                const gainNode = audioContext.createGain();
                
                oscillator.connect(gainNode);
                gainNode.connect(audioContext.destination);
                
                oscillator.frequency.value = 800;
                oscillator.type = 'sine';
                
                gainNode.gain.setValueAtTime(0, audioContext.currentTime);
                gainNode.gain.linearRampToValueAtTime(0.1, audioContext.currentTime + 0.01);
                gainNode.gain.exponentialRampToValueAtTime(0.001, audioContext.currentTime + 0.5);
                
                oscillator.start(audioContext.currentTime);
                oscillator.stop(audioContext.currentTime + 0.5);
            } catch (e) {
                console.log('Audio context not supported');
            }
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