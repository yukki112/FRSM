<?php
session_start();
require_once '../../config/db_connection.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: ../../login.php");
    exit();
}

$user_id = $_SESSION['user_id'];

// Get user information
$query = "SELECT first_name, middle_name, last_name, role, email, avatar FROM users WHERE id = ?";
$stmt = $pdo->prepare($query);
$stmt->execute([$user_id]);
$user = $stmt->fetch();

if (!$user) {
    header("Location: ../../login.php");
    exit();
}

$first_name = htmlspecialchars($user['first_name']);
$middle_name = htmlspecialchars($user['middle_name']);
$last_name = htmlspecialchars($user['last_name']);
$role = htmlspecialchars($user['role']);
$email = htmlspecialchars($user['email']);
$avatar = htmlspecialchars($user['avatar']);

$full_name = $first_name;
if (!empty($middle_name)) {
    $full_name .= " " . $middle_name;
}
$full_name .= " " . $last_name;

// Check if user is a volunteer (USER role)
if ($role !== 'USER') {
    header("Location: ../dashboard.php");
    exit();
}

// Get volunteer ID from volunteers table
$volunteer_query = "SELECT id, first_name, last_name, contact_number, volunteer_status, 
                    training_completion_status, first_training_completed_at, active_since 
                    FROM volunteers WHERE user_id = ?";
$volunteer_stmt = $pdo->prepare($volunteer_query);
$volunteer_stmt->execute([$user_id]);
$volunteer = $volunteer_stmt->fetch();

if (!$volunteer) {
    // User is not registered as a volunteer
    header("Location: ../dashboard.php");
    exit();
}

$volunteer_id = $volunteer['id'];
$volunteer_name = htmlspecialchars($volunteer['first_name'] . ' ' . $volunteer['last_name']);
$volunteer_contact = htmlspecialchars($volunteer['contact_number']);
$volunteer_status = htmlspecialchars($volunteer['volunteer_status']);
$training_status = htmlspecialchars($volunteer['training_completion_status']);
$first_training_completed = $volunteer['first_training_completed_at'];
$active_since = $volunteer['active_since'];

// Get all training records for this volunteer
$records_query = "SELECT 
                    tr.*,
                    t.title,
                    t.description,
                    t.training_date,
                    t.training_end_date,
                    t.duration_hours,
                    t.instructor,
                    t.location,
                    t.status as training_status,
                    tc.certificate_number,
                    tc.issue_date,
                    tc.expiry_date,
                    tc.certificate_file,
                    tc.verified as certificate_verified
                  FROM training_registrations tr
                  LEFT JOIN trainings t ON tr.training_id = t.id
                  LEFT JOIN training_certificates tc ON tr.id = tc.registration_id
                  WHERE tr.volunteer_id = ?
                  AND tr.status != 'cancelled'
                  ORDER BY tr.registration_date DESC";

$records_stmt = $pdo->prepare($records_query);
$records_stmt->execute([$volunteer_id]);
$training_records = $records_stmt->fetchAll();

// Calculate statistics
$total_trainings = count($training_records);
$completed_trainings = 0;
$certified_trainings = 0;
$in_progress_trainings = 0;
$pending_verification = 0;

foreach ($training_records as $record) {
    if ($record['completion_status'] === 'completed') {
        $completed_trainings++;
        if ($record['certificate_issued']) {
            $certified_trainings++;
        }
        if (!$record['admin_approved'] && $record['employee_submitted']) {
            $pending_verification++;
        }
    } elseif ($record['completion_status'] === 'in_progress') {
        $in_progress_trainings++;
    }
}

// Handle viewing certificate
$certificate_view = null;
if (isset($_GET['view_certificate']) && is_numeric($_GET['view_certificate'])) {
    $certificate_id = $_GET['view_certificate'];
    
    $cert_query = "SELECT tc.*, t.title, v.first_name, v.last_name 
                   FROM training_certificates tc
                   JOIN training_registrations tr ON tc.registration_id = tr.id
                   JOIN trainings t ON tr.training_id = t.id
                   JOIN volunteers v ON tr.volunteer_id = v.id
                   WHERE tc.id = ? AND tr.volunteer_id = ?";
    
    $cert_stmt = $pdo->prepare($cert_query);
    $cert_stmt->execute([$certificate_id, $volunteer_id]);
    $certificate_view = $cert_stmt->fetch();
}

// Handle download certificate
if (isset($_GET['download_certificate']) && is_numeric($_GET['download_certificate'])) {
    $certificate_id = $_GET['download_certificate'];
    
    $cert_query = "SELECT tc.*, t.title, v.first_name, v.last_name 
                   FROM training_certificates tc
                   JOIN training_registrations tr ON tc.registration_id = tr.id
                   JOIN trainings t ON tr.training_id = t.id
                   JOIN volunteers v ON tr.volunteer_id = v.id
                   WHERE tc.id = ? AND tr.volunteer_id = ?";
    
    $cert_stmt = $pdo->prepare($cert_query);
    $cert_stmt->execute([$certificate_id, $volunteer_id]);
    $certificate = $cert_stmt->fetch();
    
    if ($certificate && $certificate['certificate_file']) {
        $file_path = '../../' . $certificate['certificate_file'];
        if (file_exists($file_path)) {
            header('Content-Type: application/pdf');
            header('Content-Disposition: attachment; filename="Certificate_' . $certificate['certificate_number'] . '.pdf"');
            readfile($file_path);
            exit();
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Training Records - Fire & Rescue Services Management</title>
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
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-bottom: 30px;
        }

        .stat-card {
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: 16px;
            padding: 20px;
            text-align: center;
            transition: all 0.3s ease;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
        }

        .stat-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 8px 16px rgba(0, 0, 0, 0.1);
        }

        .stat-icon {
            width: 50px;
            height: 50px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 12px;
            font-size: 20px;
        }

        .stat-icon.total {
            background: rgba(59, 130, 246, 0.1);
            color: var(--info);
        }

        .stat-icon.completed {
            background: rgba(16, 185, 129, 0.1);
            color: var(--success);
        }

        .stat-icon.certified {
            background: rgba(139, 92, 246, 0.1);
            color: var(--purple);
        }

        .stat-icon.in-progress {
            background: rgba(245, 158, 11, 0.1);
            color: var(--warning);
        }

        .stat-icon.pending {
            background: rgba(245, 158, 11, 0.1);
            color: var(--warning);
        }

        .stat-value {
            font-size: 28px;
            font-weight: 800;
            margin-bottom: 5px;
        }

        .stat-label {
            color: var(--text-light);
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

        .training-info {
            display: flex;
            flex-direction: column;
            gap: 8px;
        }

        .training-title {
            font-weight: 700;
            color: var(--text-color);
            font-size: 16px;
        }

        .training-description {
            font-size: 13px;
            color: var(--text-light);
            line-height: 1.5;
        }

        .training-details {
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
            margin-top: 8px;
        }

        .detail-item {
            display: flex;
            align-items: center;
            gap: 6px;
            font-size: 13px;
            color: var(--text-light);
        }

        .detail-item i {
            color: var(--primary-color);
            font-size: 14px;
        }

        .date-cell {
            display: flex;
            flex-direction: column;
            gap: 4px;
        }

        .date-label {
            font-size: 11px;
            color: var(--text-light);
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .date-value {
            font-weight: 600;
            color: var(--text-color);
            font-size: 14px;
        }

        .status-badge {
            display: inline-block;
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            text-align: center;
            min-width: 100px;
        }

        .status-registered {
            background: rgba(156, 163, 175, 0.1);
            color: var(--gray-500);
            border: 1px solid rgba(156, 163, 175, 0.2);
        }

        .status-attending {
            background: rgba(59, 130, 246, 0.1);
            color: var(--info);
            border: 1px solid rgba(59, 130, 246, 0.2);
        }

        .status-completed {
            background: rgba(16, 185, 129, 0.1);
            color: var(--success);
            border: 1px solid rgba(16, 185, 129, 0.2);
        }

        .status-cancelled {
            background: rgba(220, 38, 38, 0.1);
            color: var(--danger);
            border: 1px solid rgba(220, 38, 38, 0.2);
        }

        .status-no_show {
            background: rgba(220, 38, 38, 0.1);
            color: var(--danger);
            border: 1px solid rgba(220, 38, 38, 0.2);
        }

        .completion-badge {
            display: inline-block;
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            text-align: center;
            min-width: 120px;
        }

        .completion-not_started {
            background: rgba(156, 163, 175, 0.1);
            color: var(--gray-500);
            border: 1px solid rgba(156, 163, 175, 0.2);
        }

        .completion-in_progress {
            background: rgba(59, 130, 246, 0.1);
            color: var(--info);
            border: 1px solid rgba(59, 130, 246, 0.2);
        }

        .completion-completed {
            background: rgba(16, 185, 129, 0.1);
            color: var(--success);
            border: 1px solid rgba(16, 185, 129, 0.2);
        }

        .completion-failed {
            background: rgba(220, 38, 38, 0.1);
            color: var(--danger);
            border: 1px solid rgba(220, 38, 38, 0.2);
        }

        .certificate-info {
            display: flex;
            flex-direction: column;
            gap: 4px;
        }

        .certificate-number {
            font-weight: 600;
            color: var(--text-color);
            font-size: 13px;
        }

        .certificate-date {
            font-size: 12px;
            color: var(--text-light);
        }

        .action-buttons {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
        }

        .btn {
            padding: 8px 16px;
            border-radius: 10px;
            font-weight: 600;
            border: none;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 6px;
            font-size: 13px;
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

        .btn-info {
            background: linear-gradient(135deg, var(--info), #2563eb);
            color: white;
        }

        .btn-info:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(59, 130, 246, 0.3);
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

        /* Certificate Modal */
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
            max-width: 800px;
            max-height: 80vh;
            transform: scale(0.9);
            transition: all 0.3s ease;
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.15);
            display: flex;
            flex-direction: column;
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
            flex-shrink: 0;
        }
        
        .modal-title {
            font-size: 20px;
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 10px;
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
            overflow-y: auto;
            flex-grow: 1;
        }
        
        .modal-actions {
            padding: 24px;
            border-top: 1px solid var(--border-color);
            display: flex;
            gap: 12px;
            justify-content: flex-end;
            flex-shrink: 0;
        }

        .certificate-preview {
            background: white;
            border: 1px solid var(--border-color);
            border-radius: 12px;
            padding: 30px;
            text-align: center;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.1);
            margin-bottom: 20px;
        }

        .certificate-header {
            margin-bottom: 30px;
        }

        .certificate-header h2 {
            font-size: 24px;
            color: var(--primary-color);
            margin: 0;
        }

        .certificate-header p {
            font-size: 14px;
            color: var(--text-light);
            margin: 5px 0 0 0;
        }

        .certificate-content {
            margin-bottom: 30px;
        }

        .certificate-content h3 {
            font-size: 28px;
            color: var(--text-color);
            margin: 0 0 20px 0;
        }

        .certificate-content h4 {
            font-size: 20px;
            color: var(--text-color);
            margin: 0 0 10px 0;
        }

        .certificate-content p {
            font-size: 16px;
            color: var(--text-light);
            margin: 5px 0;
        }

        .certificate-details {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-top: 30px;
            text-align: left;
        }

        .certificate-detail {
            display: flex;
            flex-direction: column;
            gap: 5px;
        }

        .detail-label {
            font-size: 12px;
            color: var(--text-light);
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .detail-value {
            font-size: 16px;
            font-weight: 600;
            color: var(--text-color);
        }

        .certificate-footer {
            border-top: 2px solid var(--border-color);
            padding-top: 20px;
            margin-top: 30px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .certificate-verified {
            display: flex;
            align-items: center;
            gap: 10px;
            color: var(--success);
            font-weight: 600;
        }

        .progress-indicator {
            display: flex;
            align-items: center;
            gap: 10px;
            margin: 5px 0;
            font-size: 12px;
            color: var(--text-light);
        }

        .progress-dot {
            width: 8px;
            height: 8px;
            border-radius: 50%;
        }

        .progress-dot.active {
            background: var(--success);
        }

        .progress-dot.inactive {
            background: var(--border-color);
        }
         .volunteer-status-banner {
            background: linear-gradient(135deg, #f59e0b, #d97706);
            color: white;
            padding: 15px 20px;
            border-radius: 10px;
            margin-bottom: 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .volunteer-status-banner.success {
            background: linear-gradient(135deg, var(--success), #0da271);
        }
        
        .volunteer-status-banner.warning {
            background: linear-gradient(135deg, var(--warning), #d97706);
        }
        
        .volunteer-status-content {
            display: flex;
            align-items: center;
            gap: 15px;
        }
        
        .volunteer-status-icon {
            font-size: 24px;
        }

        /* Date status indicators */
        .date-status {
            display: flex;
            align-items: center;
            gap: 5px;
            margin-top: 4px;
            font-size: 11px;
        }
        
        .date-status.upcoming {
            color: var(--info);
        }
        
        .date-status.ongoing {
            color: var(--warning);
            font-weight: 600;
        }
        
        .date-status.completed {
            color: var(--success);
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
            
            .table {
                display: block;
                overflow-x: auto;
            }
            
            .action-buttons {
                flex-direction: column;
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
            
            .training-info {
                flex-direction: column;
                align-items: flex-start;
                gap: 8px;
            }
            
            .training-details {
                flex-direction: column;
                gap: 8px;
            }
        }

        /* User profile dropdown styles */
        .user-profile-dropdown {
            position: absolute;
            top: 100%;
            right: 0;
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: 8px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
            min-width: 200px;
            z-index: 1000;
            display: none;
            margin-top: 10px;
        }
        
        .user-profile-dropdown.show {
            display: block;
        }
        
        .dropdown-item {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 12px 16px;
            color: var(--text-color);
            text-decoration: none;
            transition: all 0.3s ease;
        }
        
        .dropdown-item:hover {
            background: var(--gray-100);
        }
        
        .dropdown-item i {
            font-size: 18px;
        }
        
        .dropdown-divider {
            height: 1px;
            background: var(--border-color);
            margin: 4px 0;
        }

        .verification-status {
            display: flex;
            flex-direction: column;
            gap: 5px;
        }

        .verification-step {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 12px;
        }

        .verification-step.completed {
            color: var(--success);
        }

        .verification-step.pending {
            color: var(--warning);
        }

        .verification-step.not-started {
            color: var(--gray-400);
        }
    </style>
</head>
<body>
    <!-- Certificate View Modal -->
    <?php if ($certificate_view): ?>
    <div class="modal-overlay active" id="certificate-modal">
        <div class="modal">
            <div class="modal-header">
                <h2 class="modal-title">
                    <i class='bx bx-certificate'></i>
                    Training Certificate
                </h2>
                <button class="modal-close" onclick="closeCertificateModal()">&times;</button>
            </div>
            <div class="modal-body">
                <div class="certificate-preview">
                    <div class="certificate-header">
                        <h2>CERTIFICATE OF COMPLETION</h2>
                        <p>Fire & Rescue Services Management</p>
                    </div>
                    
                    <div class="certificate-content">
                        <h3>THIS CERTIFICATE IS AWARDED TO</h3>
                        <h4><?php echo htmlspecialchars($certificate_view['first_name'] . ' ' . $certificate_view['last_name']); ?></h4>
                        <p>for successfully completing</p>
                        <h4><?php echo htmlspecialchars($certificate_view['title']); ?></h4>
                    </div>
                    
                    <div class="certificate-details">
                        <div class="certificate-detail">
                            <span class="detail-label">Certificate Number</span>
                            <span class="detail-value"><?php echo htmlspecialchars($certificate_view['certificate_number']); ?></span>
                        </div>
                        
                        <div class="certificate-detail">
                            <span class="detail-label">Date Issued</span>
                            <span class="detail-value"><?php echo date('F j, Y', strtotime($certificate_view['issue_date'])); ?></span>
                        </div>
                        
                        <?php if ($certificate_view['expiry_date']): ?>
                        <div class="certificate-detail">
                            <span class="detail-label">Valid Until</span>
                            <span class="detail-value"><?php echo date('F j, Y', strtotime($certificate_view['expiry_date'])); ?></span>
                        </div>
                        <?php endif; ?>
                        
                        <div class="certificate-detail">
                            <span class="detail-label">Verification Status</span>
                            <span class="detail-value" style="color: <?php echo $certificate_view['verified'] ? 'var(--success)' : 'var(--warning)'; ?>">
                                <?php echo $certificate_view['verified'] ? 'Verified ✓' : 'Pending Verification'; ?>
                            </span>
                        </div>
                    </div>
                    
                    <?php if ($certificate_view['verified']): ?>
                    <div class="certificate-footer">
                        <div class="certificate-verified">
                            <i class='bx bx-check-circle'></i>
                            <span>Certificate Verified</span>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
                
                <div class="verification-status">
                    <h4 style="margin: 0 0 10px 0;">Certificate Status</h4>
                    <div class="verification-step <?php echo $certificate_view['verified'] ? 'completed' : 'pending'; ?>">
                        <i class='bx <?php echo $certificate_view['verified'] ? 'bx-check-circle' : 'bx-time'; ?>'></i>
                        <span>Certificate <?php echo $certificate_view['verified'] ? 'Verified' : 'Pending Verification'; ?></span>
                    </div>
                    <?php if ($certificate_view['verified']): ?>
                    <div class="verification-step completed">
                        <i class='bx bx-check-circle'></i>
                        <span>Ready for Download</span>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            <div class="modal-actions">
                <button type="button" class="btn btn-secondary" onclick="closeCertificateModal()">
                    <i class='bx bx-x'></i>
                    Close
                </button>
                <?php if ($certificate_view['certificate_file']): ?>
                <a href="?download_certificate=<?php echo $certificate_view['id']; ?>" class="btn btn-primary">
                    <i class='bx bx-download'></i>
                    Download Certificate
                </a>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <?php endif; ?>
    
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
                    <a href="../user_dashboard.php" class="menu-item" id="dashboard-menu">
                        <div class="icon-box icon-bg-red">
                            <i class='bx bxs-dashboard icon-red'></i>
                        </div>
                        <span class="font-medium">Dashboard</span>
                    </a>
                    
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
                        <a href="../fir/active_incidents.php" class="submenu-item">Active Incidents</a>

                        <a href="../fir/response_history.php" class="submenu-item">Response History</a>
                    </div>
                    
                    <div class="menu-item" onclick="toggleSubmenu('postincident')">
            <div class="icon-box icon-bg-pink">
                <i class='bx bxs-file-doc icon-pink'></i>
            </div>
            <span class="font-medium">Dispatch Coordination</span>
            <svg class="dropdown-arrow menu-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
            </svg>
        </div>
        <div id="postincident" class="submenu">
            <a href="../dc/suggested_unit.php" class="submenu-item">Suggested Unit</a>
            <a href="../dc/incident_location.php" class="submenu-item">Incident Location</a>
            
        </div>
                    
                    <div class="menu-item" onclick="toggleSubmenu('volunteer')">
                        <div class="icon-box icon-bg-blue">
                            <i class='bx bxs-user-detail icon-blue'></i>
                        </div>
                        <span class="font-medium">Volunteer Roster</span>
                        <svg class="dropdown-arrow menu-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                        </svg>
                    </div>
                    <div id="volunteer" class="submenu">
                        <a href="../vr/volunteer_list.php" class="submenu-item">Volunteer List</a>
                        <a href="../vr/roles_skills.php" class="submenu-item">Roles & Skills</a>
                        <a href="../vr/availability.php" class="submenu-item">Availability</a>
                    </div>
                    
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
                        <a href="../ri/equipment_list.php" class="submenu-item">Equipment List</a>
                        <a href="../ri/stock_levels.php" class="submenu-item">Stock Levels</a>
                        <a href="../ri/maintenance_logs.php" class="submenu-item">Maintenance Logs</a>
                    </div>
                    
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
                      <a href="../sds/view_shifts.php" class="submenu-item">Shift Calendar</a>
                        <a href="../sds/confirm_availability.php" class="submenu-item">Confirm Availability</a>
                        <a href="../sds/duty_assignments.php" class="submenu-item">Duty Assignments</a>
                        <a href="../sds/attendance_logs.php" class="submenu-item">Attendance Logs</a>
                    </div>
                    
                    <div class="menu-item" onclick="toggleSubmenu('training')">
                        <div class="icon-box icon-bg-teal">
                            <i class='bx bxs-graduation icon-teal'></i>
                        </div>
                        <span class="font-medium">Training & Certification</span>
                        <svg class="dropdown-arrow menu-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                        </svg>
                    </div>
                    <div id="training" class="submenu active">
                         <a href="register_training.php" class="submenu-item">Register for Training</a>
            <a href="training_records.php" class="submenu-item active">Training Records</a>
            <a href="certification_status.php" class="submenu-item">Certification Status</a>
          
                    </div>
                    
                   
                
                </div>
                
                <p class="menu-title" style="margin-top: 32px;">GENERAL</p>
                
                <div class="menu-items">
                    <a href="../settings.php" class="menu-item">
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
                            <input type="text" placeholder="Search training records..." class="search-input" id="search-input">
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
                                <img src="../../profile/uploads/avatars/<?php echo $avatar; ?>" alt="User" class="user-avatar">
                            <?php else: ?>
                                <div class="user-avatar" style="background: linear-gradient(135deg, var(--primary-color), var(--secondary-color)); color: white; display: flex; align-items: center; justify-content: center; font-weight: bold; border-radius: 50%; width: 40px; height: 40px;">
                                    <?php echo strtoupper(substr($first_name, 0, 1) . substr($last_name, 0, 1)); ?>
                                </div>
                            <?php endif; ?>
                            <div class="user-info">
                                <p class="user-name"><?php echo $full_name; ?></p>
                                <p class="user-email"><?php echo $email; ?></p>
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
                        <h1 class="dashboard-title">Training Records</h1>
                        <p class="dashboard-subtitle">View your complete training history and certificates</p>
                    </div>
                </div>
                
                <!-- Content Container -->
                <div class="content-container">
                    <!-- Volunteer Status Banner -->
                    <div class="volunteer-status-banner <?php echo $volunteer_status === 'Active' ? 'success' : 'warning'; ?>" style="margin-bottom: 20px;">
                        <div class="volunteer-status-content">
                            <i class='volunteer-status-icon bx <?php echo $volunteer_status === 'Active' ? 'bx-check-circle' : 'bx-info-circle'; ?>'></i>
                            <div>
                                <h3 style="margin: 0; font-size: 16px;">Volunteer Status: <?php echo $volunteer_status; ?></h3>
                                <p style="margin: 5px 0 0 0; font-size: 13px; opacity: 0.9;">
                                    <?php if ($volunteer_status === 'Active'): ?>
                                        Active since: <?php echo date('M j, Y', strtotime($active_since)); ?>
                                    <?php elseif ($volunteer_status === 'New Volunteer'): ?>
                                        Complete your first training to become an Active Volunteer
                                    <?php else: ?>
                                        Contact administrator for status update
                                    <?php endif; ?>
                                </p>
                            </div>
                        </div>
                        <?php if ($volunteer_status === 'Active'): ?>
                        <div style="display: flex; align-items: center; gap: 10px;">
                            <i class='bx bx-certificate' style="font-size: 20px;"></i>
                            <span style="font-size: 13px;"><?php echo $certified_trainings; ?> Certified Training<?php echo $certified_trainings != 1 ? 's' : ''; ?></span>
                        </div>
                        <?php endif; ?>
                    </div>
                    
                    <!-- Statistics -->
                    <div class="stats-container">
                        <div class="stat-card">
                            <div class="stat-icon total">
                                <i class='bx bx-book'></i>
                            </div>
                            <div class="stat-value"><?php echo $total_trainings; ?></div>
                            <div class="stat-label">Total Trainings</div>
                        </div>
                        
                        <div class="stat-card">
                            <div class="stat-icon completed">
                                <i class='bx bx-check-circle'></i>
                            </div>
                            <div class="stat-value"><?php echo $completed_trainings; ?></div>
                            <div class="stat-label">Completed</div>
                        </div>
                        
                        <div class="stat-card">
                            <div class="stat-icon certified">
                                <i class='bx bx-certificate'></i>
                            </div>
                            <div class="stat-value"><?php echo $certified_trainings; ?></div>
                            <div class="stat-label">Certified</div>
                        </div>
                        
                        <div class="stat-card">
                            <div class="stat-icon in-progress">
                                <i class='bx bx-time-five'></i>
                            </div>
                            <div class="stat-value"><?php echo $in_progress_trainings; ?></div>
                            <div class="stat-label">In Progress</div>
                        </div>
                        
                        <?php if ($pending_verification > 0): ?>
                        <div class="stat-card">
                            <div class="stat-icon pending">
                                <i class='bx bx-time'></i>
                            </div>
                            <div class="stat-value"><?php echo $pending_verification; ?></div>
                            <div class="stat-label">Pending Verification</div>
                        </div>
                        <?php endif; ?>
                    </div>
                    
                    <!-- Training Records Table -->
                    <div class="table-container">
                        <div class="table-header">
                            <h3 class="table-title">
                                <i class='bx bx-history'></i>
                                Training History
                                <span style="font-size: 14px; font-weight: normal; color: var(--text-light); margin-left: 10px;">
                                    <?php echo $total_trainings; ?> training<?php echo $total_trainings != 1 ? 's' : ''; ?> recorded
                                </span>
                            </h3>
                        </div>
                        
                        <?php if (count($training_records) > 0): ?>
                            <table class="table" id="training-records-table">
                                <thead>
                                    <tr>
                                        <th>Training Details</th>
                                        <th>Date & Duration</th>
                                        <th>Status</th>
                                        <th>Completion</th>
                                        <th>Certificate</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($training_records as $record): 
                                        $start_date = date('M j, Y', strtotime($record['training_date']));
                                        $end_date = $record['training_end_date'] ? date('M j, Y', strtotime($record['training_end_date'])) : null;
                                        $duration = $record['duration_hours'] ? number_format($record['duration_hours'], 1) . ' hours' : 'N/A';
                                        $registration_date = date('M j, Y', strtotime($record['registration_date']));
                                        
                                        // Determine verification progress
                                        $has_certificate = !empty($record['certificate_number']);
                                        $is_verified = $record['certificate_verified'] == 1;
                                        $is_approved = $record['admin_approved'] == 1;
                                        $is_submitted = $record['employee_submitted'] == 1;
                                        
                                        // Determine action buttons based on status
                                        $show_certificate_btn = $has_certificate;
                                        $certificate_id = $has_certificate ? $record['id'] : null; // This should be certificate ID, not registration ID
                                        // We need to get the actual certificate ID
                                        $cert_id_query = $pdo->prepare("SELECT id FROM training_certificates WHERE registration_id = ?");
                                        $cert_id_query->execute([$record['id']]);
                                        $cert_data = $cert_id_query->fetch();
                                        $certificate_id = $cert_data ? $cert_data['id'] : null;
                                    ?>
                                    <tr>
                                        <td>
                                            <div class="training-info">
                                                <div class="training-title">
                                                    <?php echo htmlspecialchars($record['title']); ?>
                                                </div>
                                                <div class="training-description">
                                                    <?php echo htmlspecialchars(substr($record['description'], 0, 100)); ?>...
                                                </div>
                                                <div class="training-details">
                                                    <?php if ($record['instructor']): ?>
                                                    <div class="detail-item">
                                                        <i class='bx bx-user'></i>
                                                        <span><?php echo htmlspecialchars($record['instructor']); ?></span>
                                                    </div>
                                                    <?php endif; ?>
                                                    
                                                    <?php if ($record['location']): ?>
                                                    <div class="detail-item">
                                                        <i class='bx bx-map'></i>
                                                        <span><?php echo htmlspecialchars($record['location']); ?></span>
                                                    </div>
                                                    <?php endif; ?>
                                                    
                                                    <div class="detail-item">
                                                        <i class='bx bx-calendar-plus'></i>
                                                        <span>Registered: <?php echo $registration_date; ?></span>
                                                    </div>
                                                </div>
                                            </div>
                                        </td>
                                        <td>
                                            <div class="date-cell">
                                                <div class="date-label">Start Date</div>
                                                <div class="date-value"><?php echo $start_date; ?></div>
                                                
                                                <?php if ($end_date && $end_date !== $start_date): ?>
                                                <div class="date-label" style="margin-top: 8px;">End Date</div>
                                                <div class="date-value"><?php echo $end_date; ?></div>
                                                <?php endif; ?>
                                                
                                                <div class="date-label" style="margin-top: 8px;">Duration</div>
                                                <div class="date-value"><?php echo $duration; ?></div>
                                            </div>
                                        </td>
                                        <td>
                                            <div class="status-badge status-<?php echo $record['status']; ?>">
                                                <?php echo ucfirst($record['status']); ?>
                                            </div>
                                            
                                            <?php if ($record['training_status']): ?>
                                            <div style="margin-top: 5px; font-size: 11px; color: var(--text-light);">
                                                <?php echo ucfirst($record['training_status']); ?>
                                            </div>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div class="completion-badge completion-<?php echo $record['completion_status']; ?>">
                                                <?php echo ucfirst(str_replace('_', ' ', $record['completion_status'])); ?>
                                            </div>
                                            
                                            <?php if ($record['completion_date']): ?>
                                            <div style="margin-top: 5px; font-size: 11px; color: var(--text-light);">
                                                Completed: <?php echo date('M j, Y', strtotime($record['completion_date'])); ?>
                                            </div>
                                            <?php endif; ?>
                                            
                                            <!-- Verification Progress -->
                                            <?php if ($record['completion_status'] === 'completed'): ?>
                                            <div class="verification-status" style="margin-top: 8px;">
                                                <?php if ($is_approved && $has_certificate): ?>
                                                <div class="verification-step completed">
                                                    <i class='bx bx-check-circle'></i>
                                                    <span>Certificate Approved</span>
                                                </div>
                                                <?php elseif ($is_submitted): ?>
                                                <div class="verification-step pending">
                                                    <i class='bx bx-time'></i>
                                                    <span>Submitted to Admin</span>
                                                </div>
                                                <?php elseif ($record['completion_verified']): ?>
                                                <div class="verification-step completed">
                                                    <i class='bx bx-check-circle'></i>
                                                    <span>Employee Verified</span>
                                                </div>
                                                <?php else: ?>
                                                <div class="verification-step pending">
                                                    <i class='bx bx-time'></i>
                                                    <span>Needs Verification</span>
                                                </div>
                                                <?php endif; ?>
                                            </div>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($has_certificate): ?>
                                            <div class="certificate-info">
                                                <div class="certificate-number">
                                                    <?php echo htmlspecialchars($record['certificate_number']); ?>
                                                </div>
                                                <div class="certificate-date">
                                                    Issued: <?php echo date('M j, Y', strtotime($record['issue_date'])); ?>
                                                </div>
                                                <?php if ($record['expiry_date']): ?>
                                                <div class="certificate-date">
                                                    Expires: <?php echo date('M j, Y', strtotime($record['expiry_date'])); ?>
                                                </div>
                                                <?php endif; ?>
                                                <div class="certificate-date" style="color: <?php echo $is_verified ? 'var(--success)' : 'var(--warning)'; ?>">
                                                    <?php echo $is_verified ? '✓ Verified' : 'Pending Verification'; ?>
                                                </div>
                                            </div>
                                            <?php else: ?>
                                            <div style="color: var(--text-light); font-size: 12px;">
                                                No certificate issued yet
                                            </div>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div class="action-buttons">
                                                <?php if ($has_certificate && $certificate_id): ?>
                                                <button type="button" class="btn btn-info btn-sm view-certificate-btn" 
                                                        onclick="viewCertificate(<?php echo $certificate_id; ?>)">
                                                    <i class='bx bx-certificate'></i>
                                                    View
                                                </button>
                                                
                                                <?php if ($record['certificate_file']): ?>
                                                <a href="?download_certificate=<?php echo $certificate_id; ?>" class="btn btn-success btn-sm">
                                                    <i class='bx bx-download'></i>
                                                    Download
                                                </a>
                                                <?php endif; ?>
                                                <?php endif; ?>
                                                
                                                <?php if ($record['completion_status'] === 'completed' && !$is_approved && !$has_certificate): ?>
                                                <div style="font-size: 11px; color: var(--warning); margin-top: 5px;">
                                                    <i class='bx bx-time'></i>
                                                    Awaiting certificate
                                                </div>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php else: ?>
                            <div class="empty-state">
                                <i class='bx bx-book'></i>
                                <h3>No Training Records Found</h3>
                                <p>You haven't completed any training modules yet. Start by registering for available trainings to build your skills and track your progress.</p>
                                <a href="register_training.php" class="btn btn-primary" style="margin-top: 20px;">
                                    <i class='bx bx-search'></i>
                                    Browse Available Trainings
                                </a>
                            </div>
                        <?php endif; ?>
                    </div>
                    
                    <!-- Information Section -->
                    <div class="section-container" style="border: 1px solid var(--border-color); border-radius: 16px; padding: 24px; margin-top: 20px;">
                        <h3 style="font-size: 18px; font-weight: 700; color: var(--text-color); margin-bottom: 20px; display: flex; align-items: center; gap: 10px;">
                            <i class='bx bx-info-circle'></i>
                            About Training Records & Certificates
                        </h3>
                        
                        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 20px; margin-bottom: 20px;">
                            <div style="background: rgba(59, 130, 246, 0.1); padding: 20px; border-radius: 10px; border: 1px solid rgba(59, 130, 246, 0.2);">
                                <h4 style="margin: 0 0 10px 0; color: var(--text-color);">Training Status</h4>
                                <ul style="margin: 0; padding-left: 20px; font-size: 13px; color: var(--text-color);">
                                    <li><strong>Registered:</strong> You have signed up for the training</li>
                                    <li><strong>Attending:</strong> Currently participating in training</li>
                                    <li><strong>Completed:</strong> Successfully finished the training</li>
                                    <li><strong>Cancelled:</strong> Registration was cancelled</li>
                                    <li><strong>No Show:</strong> Did not attend the training</li>
                                </ul>
                            </div>
                            
                            <div style="background: rgba(16, 185, 129, 0.1); padding: 20px; border-radius: 10px; border: 1px solid rgba(16, 185, 129, 0.2);">
                                <h4 style="margin: 0 0 10px 0; color: var(--text-color);">Completion Status</h4>
                                <ul style="margin: 0; padding-left: 20px; font-size: 13px; color: var(--text-color);">
                                    <li><strong>Not Started:</strong> Training hasn't begun yet</li>
                                    <li><strong>In Progress:</strong> Currently undergoing training</li>
                                    <li><strong>Completed:</strong> Successfully completed all requirements</li>
                                    <li><strong>Failed:</strong> Did not meet completion requirements</li>
                                </ul>
                            </div>
                            
                            <div style="background: rgba(139, 92, 246, 0.1); padding: 20px; border-radius: 10px; border: 1px solid rgba(139, 92, 246, 0.2);">
                                <h4 style="margin: 0 0 10px 0; color: var(--text-color);">Certificate Process</h4>
                                <ul style="margin: 0; padding-left: 20px; font-size: 13px; color: var(--text-color);">
                                    <li>1. Complete training requirements</li>
                                    <li>2. Employee verifies completion</li>
                                    <li>3. Employee submits to admin</li>
                                    <li>4. Admin approves and issues certificate</li>
                                    <li>5. Certificate becomes available for download</li>
                                </ul>
                            </div>
                        </div>
                        
                        <div style="margin-top: 20px; padding: 15px; background: rgba(220, 38, 38, 0.05); border-radius: 8px; border-left: 3px solid var(--primary-color);">
                            <h4 style="margin: 0 0 10px 0; color: var(--primary-color);">Important Notes:</h4>
                            <ul style="margin: 0; padding-left: 20px; color: var(--text-color); font-size: 13px;">
                                <li>Certificates are only issued for completed trainings with admin approval</li>
                                <li>Certificate verification may take 3-5 business days after completion</li>
                                <li>Keep your certificates safe as proof of your qualifications</li>
                                <li>Expired certificates may require renewal or refresher courses</li>
                                <li>Contact the training coordinator if you have issues with certificates</li>
                            </ul>
                        </div>
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
            
            // Search functionality
            setupSearch();
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
        }
        
        function setupSearch() {
            const searchInput = document.getElementById('search-input');
            const table = document.getElementById('training-records-table');
            
            if (!searchInput || !table) return;
            
            searchInput.addEventListener('input', function() {
                const searchTerm = this.value.toLowerCase();
                const rows = table.querySelectorAll('tbody tr');
                
                rows.forEach(row => {
                    const text = row.textContent.toLowerCase();
                    if (text.includes(searchTerm)) {
                        row.style.display = '';
                    } else {
                        row.style.display = 'none';
                    }
                });
            });
        }
        
        function viewCertificate(certificateId) {
            window.location.href = '?view_certificate=' + certificateId;
        }
        
        function closeCertificateModal() {
            window.location.href = 'training_records.php';
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