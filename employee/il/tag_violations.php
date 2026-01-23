<?php
session_start();
require_once '../../config/db_connection.php';
require_once 'tag_violations_functions.php';

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

// Check if user has permission (EMPLOYEE or ADMIN only)
if ($role !== 'EMPLOYEE' && $role !== 'ADMIN') {
    header("Location: ../employee_dashboard.php");
    exit();
}

// Handle actions
$success_message = '';
$error_message = '';

// Handle status update
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['update_status'])) {
        $violation_id = $_POST['violation_id'];
        $new_status = $_POST['status'];
        $notes = $_POST['admin_notes'] ?? null;
        
        // Handle file upload
        $evidence_file = null;
        if (isset($_FILES['evidence_file']) && $_FILES['evidence_file']['error'] === UPLOAD_ERR_OK) {
            try {
                $evidence_file = uploadEvidenceFile($_FILES['evidence_file'], $violation_id);
            } catch (Exception $e) {
                $error_message = $e->getMessage();
            }
        }
        
        if (!$error_message && updateViolationStatus($pdo, $violation_id, $new_status, $user_id, $notes, $evidence_file)) {
            $success_message = "Violation status updated successfully!";
        } else {
            $error_message = $error_message ?: "Failed to update violation status. Please try again.";
        }
    }
}

// Get parameters for filtering
$status_filter = $_GET['status'] ?? null;
$search_term = $_GET['search'] ?? null;
$date_from = $_GET['date_from'] ?? null;
$date_to = $_GET['date_to'] ?? null;
$severity_filter = $_GET['severity'] ?? null;

// Get violations
$violations = getViolationsByStatus($pdo, $status_filter, $search_term, $date_from, $date_to, $severity_filter);

// Get statistics
$stats = getViolationStatistics($pdo, $user_id);

$stmt = null;

// Handle AJAX requests
if (isset($_GET['action'])) {
    switch ($_GET['action']) {
        case 'get_violation_details':
            if (isset($_GET['violation_id'])) {
                $violation = getViolationDetails($pdo, $_GET['violation_id']);
                header('Content-Type: application/json');
                echo json_encode($violation);
                exit();
            }
            break;
            
        case 'get_violations':
            $violations = getViolationsByStatus(
                $pdo, 
                $_GET['status'] ?? null,
                $_GET['search'] ?? null,
                $_GET['date_from'] ?? null,
                $_GET['date_to'] ?? null,
                $_GET['severity'] ?? null
            );
            header('Content-Type: application/json');
            echo json_encode($violations);
            exit();
            break;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Tag Violations - Fire & Rescue Management</title>
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
            
            --success: #10b981;
            --warning: #f59e0b;
            --danger: #dc2626;
            --info: #3b82f6;
            --purple: #8b5cf6;
            
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

        .content-container {
            padding: 0 40px 40px;
        }

        .stats-container {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .stat-card {
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: 16px;
            padding: 24px;
            text-align: center;
            transition: all 0.3s ease;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
        }

        .stat-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 8px 16px rgba(0, 0, 0, 0.1);
        }

        .stat-icon {
            width: 60px;
            height: 60px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 16px;
            font-size: 24px;
        }

        .stat-icon.total {
            background: rgba(139, 92, 246, 0.1);
            color: var(--purple);
        }

        .stat-icon.pending {
            background: rgba(59, 130, 246, 0.1);
            color: var(--info);
        }

        .stat-icon.rectified {
            background: rgba(16, 185, 129, 0.1);
            color: var(--success);
        }

        .stat-icon.overdue {
            background: rgba(245, 158, 11, 0.1);
            color: var(--warning);
        }

        .stat-icon.fines {
            background: rgba(220, 38, 38, 0.1);
            color: var(--danger);
        }

        .stat-value {
            font-size: 32px;
            font-weight: 800;
            margin-bottom: 8px;
        }

        .stat-label {
            color: var(--text-light);
            font-size: 14px;
        }

        .filters-container {
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: 16px;
            padding: 24px;
            margin-bottom: 30px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
        }

        .filters-title {
            font-size: 18px;
            font-weight: 600;
            margin-bottom: 20px;
            color: var(--text-color);
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .filters-title i {
            color: var(--primary-color);
        }

        .filters-grid {
            display: flex;
            gap: 15px;
            flex-wrap: wrap;
            align-items: flex-end;
        }

        .filter-group {
            flex: 1;
            min-width: 200px;
        }

        .filter-label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: var(--text-color);
            font-size: 14px;
        }

        .filter-input, .filter-select {
            width: 100%;
            padding: 12px 16px;
            border-radius: 10px;
            border: 1px solid var(--border-color);
            background: var(--card-bg);
            color: var(--text-color);
            font-size: 14px;
            transition: all 0.3s ease;
        }

        .filter-input:focus, .filter-select:focus {
            outline: none;
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(220, 38, 38, 0.1);
        }

        .btn {
            padding: 12px 24px;
            border-radius: 10px;
            font-weight: 600;
            border: none;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 14px;
        }

        .btn-primary {
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            color: white;
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(220, 38, 38, 0.3);
        }

        .btn-success {
            background: linear-gradient(135deg, var(--success), #0da271);
            color: white;
        }

        .btn-success:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(16, 185, 129, 0.3);
        }

        .btn-warning {
            background: linear-gradient(135deg, var(--warning), #d97706);
            color: white;
        }

        .btn-warning:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(245, 158, 11, 0.3);
        }

        .btn-info {
            background: linear-gradient(135deg, var(--info), #2563eb);
            color: white;
        }

        .btn-info:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(59, 130, 246, 0.3);
        }

        .btn-danger {
            background: linear-gradient(135deg, var(--danger), #b91c1c);
            color: white;
        }

        .btn-danger:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(220, 38, 38, 0.3);
        }

        .btn-secondary {
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            color: var(--text-color);
        }

        .btn-secondary:hover {
            background: var(--gray-100);
        }

        .dark-mode .btn-secondary:hover {
            background: var(--gray-800);
        }

        .btn-sm {
            padding: 8px 16px;
            font-size: 13px;
        }

        .table-container {
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: 16px;
            overflow: hidden;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
            margin-bottom: 30px;
        }

        .table-header {
            padding: 20px 24px;
            border-bottom: 1px solid var(--border-color);
            display: flex;
            justify-content: space-between;
            align-items: center;
            background: rgba(220, 38, 38, 0.02);
        }

        .table-title {
            font-size: 18px;
            font-weight: 600;
            color: var(--text-color);
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .table {
            width: 100%;
            border-collapse: collapse;
        }

        .table th {
            background: rgba(220, 38, 38, 0.05);
            padding: 16px 24px;
            text-align: left;
            font-weight: 600;
            color: var(--text-color);
            border-bottom: 1px solid var(--border-color);
            font-size: 13px;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }

        .table td {
            padding: 16px 24px;
            border-bottom: 1px solid var(--border-color);
            vertical-align: middle;
        }

        .table tbody tr {
            transition: all 0.3s ease;
        }

        .table tbody tr:hover {
            background: rgba(220, 38, 38, 0.02);
        }

        .table tbody tr:last-child td {
            border-bottom: none;
        }

        .violation-info {
            display: flex;
            flex-direction: column;
            gap: 8px;
        }

        .violation-code {
            font-weight: 700;
            color: var(--text-color);
            font-size: 16px;
        }

        .violation-desc {
            font-size: 14px;
            color: var(--text-light);
        }

        .establishment-details {
            display: flex;
            flex-direction: column;
            gap: 8px;
        }

        .establishment-name {
            font-weight: 600;
            color: var(--text-color);
            font-size: 14px;
        }

        .establishment-address {
            font-size: 13px;
            color: var(--text-light);
        }

        .report-details {
            display: flex;
            flex-direction: column;
            gap: 4px;
        }

        .report-number {
            font-weight: 600;
            color: var(--text-color);
            font-size: 14px;
        }

        .report-date {
            font-size: 13px;
            color: var(--text-light);
        }

        .status-badge {
            display: inline-block;
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            text-align: center;
            min-width: 80px;
        }

        .status-pending {
            background: rgba(59, 130, 246, 0.1);
            color: var(--info);
            border: 1px solid rgba(59, 130, 246, 0.2);
        }

        .status-rectified {
            background: rgba(16, 185, 129, 0.1);
            color: var(--success);
            border: 1px solid rgba(16, 185, 129, 0.2);
        }

        .status-overdue {
            background: rgba(245, 158, 11, 0.1);
            color: var(--warning);
            border: 1px solid rgba(245, 158, 11, 0.2);
        }

        .status-escalated {
            background: rgba(139, 92, 246, 0.1);
            color: var(--purple);
            border: 1px solid rgba(139, 92, 246, 0.2);
        }

        .status-waived {
            background: rgba(107, 114, 128, 0.1);
            color: var(--gray-500);
            border: 1px solid rgba(107, 114, 128, 0.2);
        }

        .severity-badge {
            display: inline-block;
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            text-align: center;
            min-width: 70px;
        }

        .severity-critical {
            background: rgba(220, 38, 38, 0.1);
            color: var(--danger);
            border: 1px solid rgba(220, 38, 38, 0.2);
        }

        .severity-major {
            background: rgba(245, 158, 11, 0.1);
            color: var(--warning);
            border: 1px solid rgba(245, 158, 11, 0.2);
        }

        .severity-minor {
            background: rgba(59, 130, 246, 0.1);
            color: var(--info);
            border: 1px solid rgba(59, 130, 246, 0.2);
        }

        .action-buttons {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
        }

        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: var(--text-light);
        }

        .empty-state i {
            font-size: 48px;
            margin-bottom: 16px;
            opacity: 0.5;
        }

        .empty-state h3 {
            font-size: 18px;
            margin-bottom: 8px;
            color: var(--text-color);
        }

        .empty-state p {
            font-size: 14px;
            max-width: 400px;
            margin: 0 auto;
        }

        /* Modal Styles */
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.5);
            z-index: 1000;
            opacity: 0;
            transition: opacity 0.3s ease;
        }

        .modal.show {
            display: flex;
            align-items: center;
            justify-content: center;
            opacity: 1;
        }

        .modal-content {
            background: var(--card-bg);
            border-radius: 20px;
            width: 90%;
            max-width: 800px;
            max-height: 90vh;
            overflow-y: auto;
            transform: translateY(-20px);
            transition: transform 0.3s ease;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
            border: 1px solid var(--border-color);
        }

        .modal.show .modal-content {
            transform: translateY(0);
        }

        .modal-header {
            padding: 24px 32px;
            border-bottom: 1px solid var(--border-color);
            display: flex;
            justify-content: space-between;
            align-items: center;
            background: rgba(220, 38, 38, 0.02);
            border-radius: 20px 20px 0 0;
        }

        .modal-title {
            font-size: 24px;
            font-weight: 700;
            color: var(--text-color);
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .modal-title i {
            color: var(--primary-color);
        }

        .close-modal {
            background: none;
            border: none;
            font-size: 28px;
            color: var(--text-light);
            cursor: pointer;
            width: 40px;
            height: 40px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.3s ease;
        }

        .close-modal:hover {
            background: var(--gray-100);
            color: var(--danger);
        }

        .dark-mode .close-modal:hover {
            background: var(--gray-800);
        }

        .modal-body {
            padding: 32px;
        }

        .modal-footer {
            padding: 24px 32px;
            border-top: 1px solid var(--border-color);
            display: flex;
            justify-content: flex-end;
            gap: 15px;
            background: rgba(220, 38, 38, 0.02);
            border-radius: 0 0 20px 20px;
        }

        /* Violation Details */
        .violation-details-section {
            margin-bottom: 25px;
            padding-bottom: 25px;
            border-bottom: 1px solid var(--border-color);
        }

        .violation-details-section:last-child {
            border-bottom: none;
            margin-bottom: 0;
            padding-bottom: 0;
        }

        .section-title {
            font-size: 20px;
            font-weight: 700;
            margin-bottom: 20px;
            color: var(--text-color);
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .section-title i {
            color: var(--primary-color);
        }

        .details-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
        }

        .detail-box {
            background: var(--gray-100);
            border-radius: 10px;
            padding: 15px;
        }

        .dark-mode .detail-box {
            background: var(--gray-800);
        }

        .detail-label {
            font-size: 12px;
            color: var(--text-light);
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 5px;
        }

        .detail-value {
            font-size: 14px;
            font-weight: 600;
            color: var(--text-color);
        }

        /* Form Styles */
        .form-section {
            margin-bottom: 30px;
            padding-bottom: 30px;
            border-bottom: 1px solid var(--border-color);
        }

        .form-section:last-child {
            border-bottom: none;
            margin-bottom: 0;
            padding-bottom: 0;
        }

        .form-row {
            display: flex;
            gap: 20px;
            margin-bottom: 20px;
        }

        .form-group {
            flex: 1;
        }

        .form-label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: var(--text-color);
            font-size: 14px;
        }

        .required::after {
            content: " *";
            color: var(--danger);
        }

        .form-input, .form-textarea, .form-select, .form-file {
            width: 100%;
            padding: 12px 16px;
            border-radius: 10px;
            border: 1px solid var(--border-color);
            background: var(--card-bg);
            color: var(--text-color);
            font-size: 14px;
            transition: all 0.3s ease;
        }

        .form-input:focus, .form-textarea:focus, .form-select:focus {
            outline: none;
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(220, 38, 38, 0.1);
        }

        .form-textarea {
            resize: vertical;
            min-height: 100px;
        }

        .form-file {
            padding: 10px;
        }

        /* Timeline */
        .timeline {
            position: relative;
            padding-left: 30px;
        }

        .timeline::before {
            content: '';
            position: absolute;
            left: 10px;
            top: 0;
            bottom: 0;
            width: 2px;
            background: var(--border-color);
        }

        .timeline-item {
            position: relative;
            margin-bottom: 20px;
        }

        .timeline-item:last-child {
            margin-bottom: 0;
        }

        .timeline-marker {
            position: absolute;
            left: -20px;
            top: 0;
            width: 20px;
            height: 20px;
            border-radius: 50%;
            background: var(--primary-color);
            border: 3px solid var(--card-bg);
        }

        .timeline-content {
            background: var(--gray-100);
            border-radius: 10px;
            padding: 15px;
        }

        .dark-mode .timeline-content {
            background: var(--gray-800);
        }

        .timeline-date {
            font-size: 12px;
            color: var(--text-light);
            margin-bottom: 5px;
        }

        .timeline-text {
            font-size: 14px;
            color: var(--text-color);
        }

        /* Notification */
        .notification {
            position: fixed;
            top: 20px;
            right: 20px;
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
            z-index: 1000;
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

        /* Fine Amount */
        .fine-amount {
            font-weight: 700;
            color: var(--danger);
        }

        .fine-paid {
            color: var(--success);
        }

        /* Deadline Status */
        .deadline-status {
            display: flex;
            flex-direction: column;
            gap: 4px;
        }

        .deadline-date {
            font-weight: 600;
        }

        .deadline-status-badge {
            display: inline-block;
            padding: 4px 8px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 600;
            text-align: center;
            min-width: 70px;
        }

        .deadline-upcoming {
            background: rgba(59, 130, 246, 0.1);
            color: var(--info);
        }

        .deadline-today {
            background: rgba(245, 158, 11, 0.1);
            color: var(--warning);
        }

        .deadline-overdue {
            background: rgba(220, 38, 38, 0.1);
            color: var(--danger);
        }

        @media (max-width: 992px) {
            .content-container {
                padding: 0 25px 30px;
            }
            
            .dashboard-header {
                padding: 40px 25px 30px;
            }
            
            .dashboard-title {
                font-size: 32px;
            }
            
            .stats-container {
                grid-template-columns: repeat(2, 1fr);
            }
            
            .filters-grid {
                flex-direction: column;
            }
            
            .filter-group {
                min-width: 100%;
            }
            
            .table {
                display: block;
                overflow-x: auto;
            }
            
            .action-buttons {
                flex-direction: column;
            }
            
            .form-row {
                flex-direction: column;
                gap: 15px;
            }
            
            .modal-content {
                width: 95%;
                max-height: 95vh;
            }
            
            .modal-body {
                padding: 20px;
            }
            
            .details-grid {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 768px) {
            .content-container {
                padding: 0 20px 30px;
            }
            
            .dashboard-header {
                padding: 30px 20px 25px;
            }
            
            .dashboard-title {
                font-size: 28px;
            }
            
            .stats-container {
                grid-template-columns: 1fr;
            }
            
            .table-header {
                flex-direction: column;
                gap: 15px;
                align-items: stretch;
            }
            
            .modal-header, .modal-footer {
                padding: 20px;
            }
        }

        @media (max-width: 576px) {
            .btn {
                justify-content: center;
            }
            
            .timeline {
                padding-left: 20px;
            }
            
            .timeline-marker {
                left: -15px;
                width: 15px;
                height: 15px;
            }
        }
    </style>
</head>
<body>
    <!-- Notification -->
    <div class="notification <?php echo $success_message ? 'notification-success show' : ($error_message ? 'notification-error show' : ''); ?>" id="notification">
        <i class='notification-icon bx <?php echo $success_message ? 'bx-check-circle' : ($error_message ? 'bx-error' : ''); ?>'></i>
        <div class="notification-content">
            <div class="notification-title"><?php echo $success_message ? 'Success' : ($error_message ? 'Error' : ''); ?></div>
            <div class="notification-message"><?php echo $success_message ?: $error_message; ?></div>
        </div>
        <button class="notification-close" id="notification-close">&times;</button>
    </div>
    
    <!-- Update Status Modal -->
    <div class="modal" id="updateStatusModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title">
                    <i class='bx bx-edit-alt'></i>
                    Update Violation Status
                </h3>
                <button class="close-modal" id="closeUpdateModal">&times;</button>
            </div>
            <form method="POST" id="updateStatusForm" enctype="multipart/form-data">
                <div class="modal-body" id="updateStatusModalBody">
                    <!-- Update form will be loaded here via AJAX -->
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" id="cancelUpdateBtn">Cancel</button>
                    <button type="submit" name="update_status" class="btn btn-success">
                        <i class='bx bx-save'></i>
                        Update Status
                    </button>
                </div>
            </form>
        </div>
    </div>
    
    <!-- View Details Modal -->
    <div class="modal" id="viewDetailsModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title">
                    <i class='bx bx-show'></i>
                    Violation Details
                </h3>
                <button class="close-modal" id="closeViewModal">&times;</button>
            </div>
            <div class="modal-body" id="viewDetailsModalBody">
                <!-- Violation details will be loaded here via AJAX -->
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" id="closeViewBtn">Close</button>
            </div>
        </div>
    </div>
    
    <div class="container">
        <!-- Sidebar (Copy from your existing code) -->
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
                        <a href="../fire/receive_data.php" class="submenu-item">Receive Data</a>
                        <a href="../fire/update_status.php" class="submenu-item">View Status</a>
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
                        <a href="../dc/select_unit.php" class="submenu-item">Select Unit</a>
                        <a href="../dc/send_dispatch.php" class="submenu-item">Send Dispatch Info</a>
                        <a href="../dc/track_status.php" class="submenu-item">Track Status</a>
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
                        <a href="../vra/review_data.php" class="submenu-item">Review/Approve Data Management</a>
                        <a href="../vra/approve_applications.php" class="submenu-item">Assign Volunteers</a>
                        <a href="../vra/view_availability.php" class="submenu-item">View Availability</a>
                        <a href="../vra/remove_volunteers.php" class="submenu-item">Remove Volunteers</a>
                        <a href="../vra/toggle_volunteer_registration.php" class="submenu-item">Open/Close Registration</a>
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
                        <a href="../schedule/create_shifts.php" class="submenu-item">Create Shifts</a>
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
                        <a href="../training/view_available_training.php" class="submenu-item">View Available Training</a>
                        <a href="../training/submit_training.php" class="submenu-item">Submit Training</a>
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
                    <div id="inspection" class="submenu active">
                        <a href="conduct_inspections.php" class="submenu-item">Conduct Inspections</a>
                        <a href="submit_findings.php" class="submenu-item">Submit Findings</a>
                        <a href="tag_violations.php" class="submenu-item active">Tag Violations</a>
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
                        <a href="../pi/post_incident_reporting.php" class="submenu-item">Reporting</a>
                        
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
                            <input type="text" placeholder="Search violations..." class="search-input" id="search-input">
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
                        <h1 class="dashboard-title">Tag Violations</h1>
                        <p class="dashboard-subtitle">Track and manage fire safety code violations for Commonwealth establishments</p>
                    </div>
                </div>
                
                <!-- Content Container -->
                <div class="content-container">
                    <!-- Statistics Cards -->
                    <div class="stats-container">
                        <div class="stat-card">
                            <div class="stat-icon total">
                                <i class='bx bx-error'></i>
                            </div>
                            <div class="stat-value"><?php echo $stats['total']; ?></div>
                            <div class="stat-label">Total Violations</div>
                        </div>
                        
                        <div class="stat-card">
                            <div class="stat-icon pending">
                                <i class='bx bx-time'></i>
                            </div>
                            <div class="stat-value"><?php echo $stats['pending']; ?></div>
                            <div class="stat-label">Pending</div>
                        </div>
                        
                        <div class="stat-card">
                            <div class="stat-icon rectified">
                                <i class='bx bx-check-circle'></i>
                            </div>
                            <div class="stat-value"><?php echo $stats['rectified']; ?></div>
                            <div class="stat-label">Rectified</div>
                        </div>
                        
                        <div class="stat-card">
                            <div class="stat-icon overdue">
                                <i class='bx bx-alarm-exclamation'></i>
                            </div>
                            <div class="stat-value"><?php echo $stats['overdue']; ?></div>
                            <div class="stat-label">Overdue</div>
                        </div>
                        
                        <div class="stat-card">
                            <div class="stat-icon fines">
                                <i class='bx bx-money'></i>
                            </div>
                            <div class="stat-value">₱<?php echo number_format($stats['total_fines'], 2); ?></div>
                            <div class="stat-label">Total Fines</div>
                        </div>
                    </div>
                    
                    <!-- Filters -->
                    <div class="filters-container">
                        <h3 class="filters-title">
                            <i class='bx bx-filter-alt'></i>
                            Filter Violations
                        </h3>
                        
                        <form method="GET" id="filters-form">
                            <div class="filters-grid">
                                <div class="filter-group">
                                    <label class="filter-label" for="search">Search</label>
                                    <input type="text" class="filter-input" id="search" name="search" 
                                           value="<?php echo htmlspecialchars($search_term ?? ''); ?>" 
                                           placeholder="Search by code, establishment, description...">
                                </div>
                                
                                <div class="filter-group">
                                    <label class="filter-label" for="status">Status</label>
                                    <select class="filter-select" id="status" name="status">
                                        <option value="all">All Status</option>
                                        <option value="pending" <?php echo $status_filter === 'pending' ? 'selected' : ''; ?>>Pending</option>
                                        <option value="rectified" <?php echo $status_filter === 'rectified' ? 'selected' : ''; ?>>Rectified</option>
                                        <option value="overdue" <?php echo $status_filter === 'overdue' ? 'selected' : ''; ?>>Overdue</option>
                                        <option value="escalated" <?php echo $status_filter === 'escalated' ? 'selected' : ''; ?>>Escalated</option>
                                        <option value="waived" <?php echo $status_filter === 'waived' ? 'selected' : ''; ?>>Waived</option>
                                    </select>
                                </div>
                                
                                <div class="filter-group">
                                    <label class="filter-label" for="severity">Severity</label>
                                    <select class="filter-select" id="severity" name="severity">
                                        <option value="all">All Severity</option>
                                        <option value="critical" <?php echo $severity_filter === 'critical' ? 'selected' : ''; ?>>Critical</option>
                                        <option value="major" <?php echo $severity_filter === 'major' ? 'selected' : ''; ?>>Major</option>
                                        <option value="minor" <?php echo $severity_filter === 'minor' ? 'selected' : ''; ?>>Minor</option>
                                    </select>
                                </div>
                                
                                <div class="filter-group">
                                    <label class="filter-label" for="date_from">From Date</label>
                                    <input type="date" class="filter-input" id="date_from" name="date_from" 
                                           value="<?php echo htmlspecialchars($date_from ?? ''); ?>">
                                </div>
                                
                                <div class="filter-group">
                                    <label class="filter-label" for="date_to">To Date</label>
                                    <input type="date" class="filter-input" id="date_to" name="date_to" 
                                           value="<?php echo htmlspecialchars($date_to ?? ''); ?>">
                                </div>
                                
                                <div class="filter-group">
                                    <label class="filter-label">&nbsp;</label>
                                    <div style="display: flex; gap: 10px;">
                                        <button type="submit" class="btn btn-primary">
                                            <i class='bx bx-search'></i>
                                            Filter
                                        </button>
                                        <button type="button" class="btn btn-secondary" onclick="clearFilters()">
                                            <i class='bx bx-reset'></i>
                                            Clear
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </form>
                    </div>
                    
                    <!-- Violations Table -->
                    <div class="table-container">
                        <?php if (count($violations) > 0): ?>
                            <div class="table-header">
                                <h3 class="table-title">
                                    <i class='bx bx-list-ul'></i>
                                    Fire Safety Violations
                                </h3>
                            </div>
                            
                            <table class="table">
                                <thead>
                                    <tr>
                                        <th>Violation Details</th>
                                        <th>Establishment</th>
                                        <th>Report Details</th>
                                        <th>Severity & Status</th>
                                        <th>Deadline & Fine</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($violations as $violation): 
                                        $created_date = date('M j, Y', strtotime($violation['created_at']));
                                        $inspection_date = $violation['inspection_date'] ? date('M j, Y', strtotime($violation['inspection_date'])) : 'N/A';
                                        
                                        // Determine status class
                                        $status_class = 'status-' . $violation['status'];
                                        
                                        // Determine severity class
                                        $severity_class = 'severity-' . $violation['severity'];
                                        
                                        // Calculate deadline status
                                        $deadline_status = '';
                                        if ($violation['compliance_deadline']) {
                                            $deadline = new DateTime($violation['compliance_deadline']);
                                            $today = new DateTime();
                                            $interval = $today->diff($deadline);
                                            
                                            if ($today > $deadline) {
                                                $deadline_status = 'deadline-overdue';
                                                $deadline_text = 'Overdue';
                                            } elseif ($interval->days === 0) {
                                                $deadline_status = 'deadline-today';
                                                $deadline_text = 'Today';
                                            } elseif ($interval->days <= 7) {
                                                $deadline_status = 'deadline-upcoming';
                                                $deadline_text = $interval->days . ' days';
                                            } else {
                                                $deadline_status = '';
                                                $deadline_text = date('M j, Y', strtotime($violation['compliance_deadline']));
                                            }
                                        }
                                    ?>
                                    <tr>
                                        <td>
                                            <div class="violation-info">
                                                <div class="violation-code">
                                                    <?php echo htmlspecialchars($violation['violation_code']); ?>
                                                </div>
                                                <div class="violation-desc">
                                                    <?php echo htmlspecialchars(substr($violation['violation_description'], 0, 80)); ?><?php echo strlen($violation['violation_description']) > 80 ? '...' : ''; ?>
                                                </div>
                                                <div style="font-size: 11px; color: var(--text-light); margin-top: 4px;">
                                                    Created: <?php echo $created_date; ?>
                                                </div>
                                            </div>
                                        </td>
                                        <td>
                                            <div class="establishment-details">
                                                <div class="establishment-name">
                                                    <?php echo htmlspecialchars($violation['establishment_name']); ?>
                                                </div>
                                                <div class="establishment-address">
                                                    <?php echo htmlspecialchars($violation['barangay']); ?>
                                                </div>
                                                <div style="font-size: 11px; color: var(--text-light); margin-top: 4px;">
                                                    <?php echo htmlspecialchars($violation['address']); ?>
                                                </div>
                                            </div>
                                        </td>
                                        <td>
                                            <div class="report-details">
                                                <div class="report-number">
                                                    <?php echo htmlspecialchars($violation['report_number']); ?>
                                                </div>
                                                <div class="report-date">
                                                    Inspected: <?php echo $inspection_date; ?>
                                                </div>
                                                <?php if ($violation['first_name']): ?>
                                                <div style="font-size: 11px; color: var(--text-light); margin-top: 4px;">
                                                    Inspector: <?php echo htmlspecialchars($violation['first_name'] . ' ' . $violation['last_name']); ?>
                                                </div>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                        <td>
                                            <div class="severity-badge <?php echo $severity_class; ?>" style="margin-bottom: 8px;">
                                                <?php echo ucfirst($violation['severity']); ?>
                                            </div>
                                            <div class="status-badge <?php echo $status_class; ?>">
                                                <?php echo ucfirst($violation['status']); ?>
                                            </div>
                                        </td>
                                        <td>
                                            <?php if ($violation['compliance_deadline']): ?>
                                            <div class="deadline-status">
                                                <div class="deadline-date">
                                                    <?php echo date('M j, Y', strtotime($violation['compliance_deadline'])); ?>
                                                </div>
                                                <?php if ($deadline_status): ?>
                                                <div class="deadline-status-badge <?php echo $deadline_status; ?>">
                                                    <?php echo $deadline_text; ?>
                                                </div>
                                                <?php endif; ?>
                                            </div>
                                            <?php else: ?>
                                            <div style="color: var(--text-light); font-size: 13px;">
                                                No deadline
                                            </div>
                                            <?php endif; ?>
                                            
                                            <?php if ($violation['fine_amount']): ?>
                                            <div class="fine-amount" style="margin-top: 8px;">
                                                ₱<?php echo number_format($violation['fine_amount'], 2); ?>
                                            </div>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div class="action-buttons">
                                                <button type="button" class="btn btn-info btn-sm view-violation-btn"
                                                        data-violation-id="<?php echo $violation['id']; ?>">
                                                    <i class='bx bx-show'></i>
                                                    View
                                                </button>
                                                <?php if ($violation['status'] !== 'rectified' && $violation['status'] !== 'waived'): ?>
                                                <button type="button" class="btn btn-success btn-sm update-status-btn"
                                                        data-violation-id="<?php echo $violation['id']; ?>"
                                                        data-violation-code="<?php echo htmlspecialchars($violation['violation_code']); ?>">
                                                    <i class='bx bx-edit-alt'></i>
                                                    Update
                                                </button>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php else: ?>
                            <div class="empty-state">
                                <i class='bx bx-check-circle'></i>
                                <h3>No Violations Found</h3>
                                <p>No fire safety violations match your search criteria. Try adjusting your filters or conduct new inspections.</p>
                                <a href="conduct_inspections.php" class="btn btn-primary" style="margin-top: 20px;">
                                    <i class='bx bx-plus'></i>
                                    Conduct New Inspection
                                </a>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Initialize event listeners
            initEventListeners();
            
            // Update time display
            updateTime();
            setInterval(updateTime, 1000);
            
            // Auto-hide notification after 5 seconds
            setTimeout(() => {
                const notification = document.getElementById('notification');
                if (notification) {
                    notification.classList.remove('show');
                }
            }, 5000);
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
            });
            
            // Close dropdowns when clicking outside
            document.addEventListener('click', function() {
                userDropdown.classList.remove('show');
            });
            
            // Notification close
            const notificationClose = document.getElementById('notification-close');
            if (notificationClose) {
                notificationClose.addEventListener('click', function() {
                    document.getElementById('notification').classList.remove('show');
                });
            }
            
            // Search input
            const searchInput = document.getElementById('search-input');
            searchInput.addEventListener('keypress', function(e) {
                if (e.key === 'Enter') {
                    document.getElementById('filters-form').submit();
                }
            });
            
            // View buttons
            document.querySelectorAll('.view-violation-btn').forEach(btn => {
                btn.addEventListener('click', function() {
                    const violationId = this.getAttribute('data-violation-id');
                    showViolationDetails(violationId);
                });
            });
            
            // Update buttons
            document.querySelectorAll('.update-status-btn').forEach(btn => {
                btn.addEventListener('click', function() {
                    const violationId = this.getAttribute('data-violation-id');
                    const violationCode = this.getAttribute('data-violation-code');
                    showUpdateForm(violationId, violationCode);
                });
            });
            
            // Modal close buttons
            document.getElementById('closeUpdateModal').addEventListener('click', function() {
                document.getElementById('updateStatusModal').classList.remove('show');
            });
            
            document.getElementById('cancelUpdateBtn').addEventListener('click', function() {
                document.getElementById('updateStatusModal').classList.remove('show');
            });
            
            document.getElementById('closeViewModal').addEventListener('click', function() {
                document.getElementById('viewDetailsModal').classList.remove('show');
            });
            
            document.getElementById('closeViewBtn').addEventListener('click', function() {
                document.getElementById('viewDetailsModal').classList.remove('show');
            });
            
            // Close modals when clicking outside
            document.querySelectorAll('.modal').forEach(modal => {
                modal.addEventListener('click', function(e) {
                    if (e.target === this) {
                        this.classList.remove('show');
                    }
                });
            });
            
            // File input preview
            document.addEventListener('change', function(e) {
                if (e.target.type === 'file' && e.target.id === 'evidence_file') {
                    const fileName = e.target.files[0] ? e.target.files[0].name : 'No file chosen';
                    const label = e.target.nextElementSibling;
                    if (label && label.classList.contains('custom-file-label')) {
                        label.textContent = fileName;
                    }
                }
            });
        }
        
        async function showViolationDetails(violationId) {
            try {
                const response = await fetch(`tag_violations.php?action=get_violation_details&violation_id=${violationId}`);
                const violation = await response.json();
                
                if (violation) {
                    // Format dates
                    const createdDate = new Date(violation.created_at).toLocaleDateString('en-US', { 
                        year: 'numeric', 
                        month: 'short', 
                        day: 'numeric',
                        hour: '2-digit',
                        minute: '2-digit'
                    });
                    
                    const updatedDate = violation.updated_at ? 
                        new Date(violation.updated_at).toLocaleDateString('en-US', { 
                            year: 'numeric', 
                            month: 'short', 
                            day: 'numeric',
                            hour: '2-digit',
                            minute: '2-digit'
                        }) : 'N/A';
                    
                    const inspectionDate = violation.inspection_date ? 
                        new Date(violation.inspection_date).toLocaleDateString('en-US', { 
                            year: 'numeric', 
                            month: 'short', 
                            day: 'numeric'
                        }) : 'N/A';
                    
                    const deadlineDate = violation.compliance_deadline ? 
                        new Date(violation.compliance_deadline).toLocaleDateString('en-US', { 
                            year: 'numeric', 
                            month: 'short', 
                            day: 'numeric'
                        }) : 'N/A';
                    
                    const rectifiedDate = violation.rectified_at ? 
                        new Date(violation.rectified_at).toLocaleDateString('en-US', { 
                            year: 'numeric', 
                            month: 'short', 
                            day: 'numeric',
                            hour: '2-digit',
                            minute: '2-digit'
                        }) : 'N/A';
                    
                    // Determine status class
                    const statusClass = 'status-' + violation.status;
                    
                    // Determine severity class
                    const severityClass = 'severity-' + violation.severity;
                    
                    // Build the details HTML
                    const detailsHTML = `
                        <div class="violation-details-section">
                            <h3 class="section-title">
                                <i class='bx bx-info-circle'></i>
                                Violation Information
                            </h3>
                            
                            <div class="details-grid">
                                <div class="detail-box">
                                    <div class="detail-label">Violation Code</div>
                                    <div class="detail-value">${violation.violation_code}</div>
                                </div>
                                <div class="detail-box">
                                    <div class="detail-label">Severity</div>
                                    <div class="detail-value">
                                        <span class="severity-badge ${severityClass}">${violation.severity ? violation.severity.charAt(0).toUpperCase() + violation.severity.slice(1) : 'N/A'}</span>
                                    </div>
                                </div>
                                <div class="detail-box">
                                    <div class="detail-label">Status</div>
                                    <div class="detail-value">
                                        <span class="status-badge ${statusClass}">${violation.status ? violation.status.charAt(0).toUpperCase() + violation.status.slice(1) : 'N/A'}</span>
                                    </div>
                                </div>
                                <div class="detail-box">
                                    <div class="detail-label">Section Violated</div>
                                    <div class="detail-value">${violation.section_violated || 'N/A'}</div>
                                </div>
                            </div>
                            
                            <div style="margin-top: 20px;">
                                <div class="detail-label">Description</div>
                                <div class="detail-value" style="white-space: pre-wrap; background: var(--gray-100); padding: 15px; border-radius: 8px;">${violation.violation_description || 'No description'}</div>
                            </div>
                        </div>
                        
                        <div class="violation-details-section">
                            <h3 class="section-title">
                                <i class='bx bx-building'></i>
                                Establishment Details
                            </h3>
                            
                            <div class="details-grid">
                                <div class="detail-box">
                                    <div class="detail-label">Establishment Name</div>
                                    <div class="detail-value">${violation.establishment_name || 'N/A'}</div>
                                </div>
                                <div class="detail-box">
                                    <div class="detail-label">Establishment Type</div>
                                    <div class="detail-value">${violation.establishment_type || 'N/A'}</div>
                                </div>
                                <div class="detail-box">
                                    <div class="detail-label">Owner Name</div>
                                    <div class="detail-value">${violation.owner_name || 'N/A'}</div>
                                </div>
                                <div class="detail-box">
                                    <div class="detail-label">Address</div>
                                    <div class="detail-value">${violation.address || 'N/A'}</div>
                                </div>
                                <div class="detail-box">
                                    <div class="detail-label">Barangay</div>
                                    <div class="detail-value">${violation.barangay || 'N/A'}</div>
                                </div>
                                <div class="detail-box">
                                    <div class="detail-label">Business Permit</div>
                                    <div class="detail-value">${violation.business_permit_number || 'N/A'}</div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="violation-details-section">
                            <h3 class="section-title">
                                <i class='bx bx-file'></i>
                                Report Details
                            </h3>
                            
                            <div class="details-grid">
                                <div class="detail-box">
                                    <div class="detail-label">Report Number</div>
                                    <div class="detail-value">${violation.report_number || 'N/A'}</div>
                                </div>
                                <div class="detail-box">
                                    <div class="detail-label">Inspection Date</div>
                                    <div class="detail-value">${inspectionDate}</div>
                                </div>
                                <div class="detail-box">
                                    <div class="detail-label">Inspection Type</div>
                                    <div class="detail-value">${violation.inspection_type ? violation.inspection_type.replace('_', ' ') : 'Routine'}</div>
                                </div>
                                <div class="detail-box">
                                    <div class="detail-label">Inspector</div>
                                    <div class="detail-value">${violation.inspector_first ? violation.inspector_first + ' ' + violation.inspector_last : 'N/A'}</div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="violation-details-section">
                            <h3 class="section-title">
                                <i class='bx bx-calendar'></i>
                                Compliance Details
                            </h3>
                            
                            <div class="details-grid">
                                ${violation.fine_amount ? `
                                <div class="detail-box">
                                    <div class="detail-label">Fine Amount</div>
                                    <div class="detail-value fine-amount">₱${parseFloat(violation.fine_amount).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}</div>
                                </div>` : ''}
                                
                                ${violation.compliance_deadline ? `
                                <div class="detail-box">
                                    <div class="detail-label">Compliance Deadline</div>
                                    <div class="detail-value">${deadlineDate}</div>
                                </div>` : ''}
                                
                                <div class="detail-box">
                                    <div class="detail-label">Created Date</div>
                                    <div class="detail-value">${createdDate}</div>
                                </div>
                                
                                <div class="detail-box">
                                    <div class="detail-label">Last Updated</div>
                                    <div class="detail-value">${updatedDate}</div>
                                </div>
                            </div>
                            
                            ${violation.rectified_at ? `
                            <div style="margin-top: 20px;">
                                <div class="detail-label">Rectification Details</div>
                                <div class="detail-value">
                                    <div style="background: rgba(16, 185, 129, 0.1); padding: 15px; border-radius: 8px; border-left: 4px solid var(--success);">
                                        <div><strong>Rectified Date:</strong> ${rectifiedDate}</div>
                                        ${violation.rectified_by_first ? `<div><strong>Rectified By:</strong> ${violation.rectified_by_first} ${violation.rectified_by_last}</div>` : ''}
                                        ${violation.rectified_evidence ? `<div><strong>Evidence:</strong> <a href="../../uploads/${violation.rectified_evidence}" target="_blank" style="color: var(--primary-color); text-decoration: underline;">View Evidence</a></div>` : ''}
                                    </div>
                                </div>
                            </div>` : ''}
                            
                            ${violation.admin_notes ? `
                            <div style="margin-top: 20px;">
                                <div class="detail-label">Admin Notes</div>
                                <div class="detail-value" style="white-space: pre-wrap; background: var(--gray-100); padding: 15px; border-radius: 8px;">${violation.admin_notes}</div>
                            </div>` : ''}
                        </div>
                    `;
                    
                    // Update modal content
                    document.getElementById('viewDetailsModalBody').innerHTML = detailsHTML;
                    
                    // Show modal
                    document.getElementById('viewDetailsModal').classList.add('show');
                } else {
                    alert('Violation not found or failed to load details.');
                }
            } catch (error) {
                console.error('Error loading violation details:', error);
                alert('Failed to load violation details. Please try again.');
            }
        }
        
        async function showUpdateForm(violationId, violationCode) {
            try {
                const response = await fetch(`tag_violations.php?action=get_violation_details&violation_id=${violationId}`);
                const violation = await response.json();
                
                if (violation) {
                    // Build the update form HTML
                    const updateHTML = `
                        <input type="hidden" name="violation_id" value="${violationId}">
                        
                        <div class="form-section">
                            <h3 class="section-title">
                                <i class='bx bx-info-circle'></i>
                                Violation Information
                            </h3>
                            
                            <div class="details-grid">
                                <div class="detail-box">
                                    <div class="detail-label">Violation Code</div>
                                    <div class="detail-value">${violation.violation_code || violationCode}</div>
                                </div>
                                <div class="detail-box">
                                    <div class="detail-label">Establishment</div>
                                    <div class="detail-value">${violation.establishment_name || 'N/A'}</div>
                                </div>
                                <div class="detail-box">
                                    <div class="detail-label">Current Status</div>
                                    <div class="detail-value">
                                        <span class="status-badge status-${violation.status}">${violation.status ? violation.status.charAt(0).toUpperCase() + violation.status.slice(1) : 'N/A'}</span>
                                    </div>
                                </div>
                            </div>
                            
                            <div style="margin-top: 15px;">
                                <div class="detail-label">Description</div>
                                <div class="detail-value" style="font-size: 13px; color: var(--text-light);">${violation.violation_description || 'No description'}</div>
                            </div>
                        </div>
                        
                        <div class="form-section">
                            <h3 class="section-title">
                                <i class='bx bx-edit-alt'></i>
                                Update Status
                            </h3>
                            
                            <div class="form-row">
                                <div class="form-group">
                                    <label class="form-label required" for="status">New Status</label>
                                    <select class="form-select" id="status" name="status" required>
                                        <option value="">Select Status</option>
                                        <option value="pending" ${violation.status === 'pending' ? 'selected' : ''}>Pending</option>
                                        <option value="rectified" ${violation.status === 'rectified' ? 'selected' : ''}>Rectified</option>
                                        <option value="overdue" ${violation.status === 'overdue' ? 'selected' : ''}>Overdue</option>
                                        <option value="escalated" ${violation.status === 'escalated' ? 'selected' : ''}>Escalated</option>
                                        <option value="waived" ${violation.status === 'waived' ? 'selected' : ''}>Waived</option>
                                    </select>
                                </div>
                            </div>
                            
                            <div class="form-row">
                                <div class="form-group">
                                    <label class="form-label" for="evidence_file">Evidence File (Optional)</label>
                                    <div style="position: relative;">
                                        <input type="file" class="form-file" id="evidence_file" name="evidence_file" accept="image/*,.pdf,.doc,.docx">
                                    </div>
                                    <div style="font-size: 12px; color: var(--text-light); margin-top: 5px;">
                                        Accepted formats: JPG, PNG, PDF, DOC (Max 5MB)
                                    </div>
                                </div>
                            </div>
                            
                            <div class="form-row">
                                <div class="form-group">
                                    <label class="form-label" for="admin_notes">Notes (Optional)</label>
                                    <textarea class="form-textarea" id="admin_notes" name="admin_notes" 
                                              placeholder="Add notes about the status update..." 
                                              rows="4">${violation.admin_notes || ''}</textarea>
                                </div>
                            </div>
                        </div>
                        
                        <div class="form-section">
                            <h3 class="section-title">
                                <i class='bx bx-history'></i>
                                Status History
                            </h3>
                            
                            <div class="timeline">
                                <div class="timeline-item">
                                    <div class="timeline-marker"></div>
                                    <div class="timeline-content">
                                        <div class="timeline-date">${new Date(violation.created_at).toLocaleDateString('en-US', { 
                                            year: 'numeric', 
                                            month: 'short', 
                                            day: 'numeric',
                                            hour: '2-digit',
                                            minute: '2-digit'
                                        })}</div>
                                        <div class="timeline-text">
                                            <strong>Violation Created</strong><br>
                                            Status: <span class="status-badge status-pending">Pending</span>
                                        </div>
                                    </div>
                                </div>
                                
                                ${violation.updated_at && violation.updated_at !== violation.created_at ? `
                                <div class="timeline-item">
                                    <div class="timeline-marker"></div>
                                    <div class="timeline-content">
                                        <div class="timeline-date">${new Date(violation.updated_at).toLocaleDateString('en-US', { 
                                            year: 'numeric', 
                                            month: 'short', 
                                            day: 'numeric',
                                            hour: '2-digit',
                                            minute: '2-digit'
                                        })}</div>
                                        <div class="timeline-text">
                                            <strong>Last Updated</strong><br>
                                            Status: <span class="status-badge status-${violation.status}">${violation.status ? violation.status.charAt(0).toUpperCase() + violation.status.slice(1) : 'N/A'}</span>
                                        </div>
                                    </div>
                                </div>` : ''}
                            </div>
                        </div>
                    `;
                    
                    // Update modal content
                    document.getElementById('updateStatusModalBody').innerHTML = updateHTML;
                    
                    // Show modal
                    document.getElementById('updateStatusModal').classList.add('show');
                } else {
                    alert('Violation not found or failed to load form.');
                }
            } catch (error) {
                console.error('Error loading update form:', error);
                alert('Failed to load update form. Please try again.');
            }
        }
        
        function clearFilters() {
            window.location.href = 'tag_violations.php';
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
        
        function toggleSubmenu(id) {
            const submenu = document.getElementById(id);
            const arrow = submenu.previousElementSibling.querySelector('.dropdown-arrow');
            
            submenu.classList.toggle('active');
            arrow.classList.toggle('rotated');
        }
    </script>
</body>
</html>