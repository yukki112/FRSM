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

// Get all certificates for this volunteer with training details
$certificates_query = "SELECT 
                        tc.*,
                        t.title as training_title,
                        t.description as training_description,
                        t.training_date,
                        t.training_end_date,
                        t.duration_hours,
                        t.instructor,
                        tr.registration_date,
                        tr.completion_date,
                        tr.admin_approved,
                        tr.employee_submitted,
                        tr.completion_verified,
                        v.first_name,
                        v.last_name
                      FROM training_certificates tc
                      JOIN training_registrations tr ON tc.registration_id = tr.id
                      JOIN trainings t ON tr.training_id = t.id
                      JOIN volunteers v ON tr.volunteer_id = v.id
                      WHERE tr.volunteer_id = ?
                      ORDER BY tc.issue_date DESC";

$certificates_stmt = $pdo->prepare($certificates_query);
$certificates_stmt->execute([$volunteer_id]);
$certificates = $certificates_stmt->fetchAll();

// Get all completed trainings (including those without certificates yet)
$completed_trainings_query = "SELECT 
                                tr.*,
                                t.title,
                                t.description,
                                t.training_date,
                                t.training_end_date,
                                t.instructor,
                                tc.id as certificate_id,
                                tc.certificate_number,
                                tc.verified as certificate_verified
                              FROM training_registrations tr
                              JOIN trainings t ON tr.training_id = t.id
                              LEFT JOIN training_certificates tc ON tr.id = tc.registration_id
                              WHERE tr.volunteer_id = ?
                              AND tr.completion_status = 'completed'
                              AND tr.status != 'cancelled'
                              ORDER BY tr.completion_date DESC";

$completed_stmt = $pdo->prepare($completed_trainings_query);
$completed_stmt->execute([$volunteer_id]);
$completed_trainings = $completed_stmt->fetchAll();

// Calculate statistics
$total_certificates = count($certificates);
$verified_certificates = 0;
$expired_certificates = 0;
$expiring_soon = 0;
$pending_certificates = 0;

$current_date = new DateTime();
foreach ($certificates as $cert) {
    if ($cert['verified'] == 1) {
        $verified_certificates++;
    }
    
    if ($cert['expiry_date']) {
        $expiry_date = new DateTime($cert['expiry_date']);
        $days_until_expiry = $current_date->diff($expiry_date)->days;
        
        if ($expiry_date < $current_date) {
            $expired_certificates++;
        } elseif ($days_until_expiry <= 30) {
            $expiring_soon++;
        }
    }
}

// Count pending certificates (completed trainings without certificates)
foreach ($completed_trainings as $training) {
    if (!$training['certificate_id']) {
        $pending_certificates++;
    }
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
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Certification Status - Fire & Rescue Services Management</title>
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

        .stat-icon.verified {
            background: rgba(16, 185, 129, 0.1);
            color: var(--success);
        }

        .stat-icon.pending {
            background: rgba(245, 158, 11, 0.1);
            color: var(--warning);
        }

        .stat-icon.expired {
            background: rgba(220, 38, 38, 0.1);
            color: var(--danger);
        }

        .stat-icon.expiring {
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

        .tabs-container {
            display: flex;
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: 12px;
            padding: 4px;
            margin-bottom: 30px;
            width: fit-content;
        }

        .tab {
            padding: 12px 24px;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 8px;
            color: var(--text-color);
        }

        .tab:hover {
            background: var(--gray-100);
        }

        .tab.active {
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            color: white;
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

        .certificate-info {
            display: flex;
            flex-direction: column;
            gap: 8px;
        }

        .certificate-title {
            font-weight: 700;
            color: var(--text-color);
            font-size: 16px;
        }

        .certificate-number {
            font-weight: 600;
            color: var(--text-color);
            font-size: 13px;
        }

        .certificate-details {
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

        .status-verified {
            background: rgba(16, 185, 129, 0.1);
            color: var(--success);
            border: 1px solid rgba(16, 185, 129, 0.2);
        }

        .status-pending {
            background: rgba(245, 158, 11, 0.1);
            color: var(--warning);
            border: 1px solid rgba(245, 158, 11, 0.2);
        }

        .status-expired {
            background: rgba(220, 38, 38, 0.1);
            color: var(--danger);
            border: 1px solid rgba(220, 38, 38, 0.2);
        }

        .status-active {
            background: rgba(59, 130, 246, 0.1);
            color: var(--info);
            border: 1px solid rgba(59, 130, 246, 0.2);
        }

        .status-warning {
            background: rgba(245, 158, 11, 0.1);
            color: var(--warning);
            border: 1px solid rgba(245, 158, 11, 0.2);
        }

        .expiry-indicator {
            display: flex;
            flex-direction: column;
            gap: 4px;
        }

        .expiry-progress {
            width: 100%;
            height: 6px;
            background: var(--border-color);
            border-radius: 3px;
            overflow: hidden;
            margin-top: 4px;
        }

        .expiry-fill {
            height: 100%;
            border-radius: 3px;
            transition: width 0.3s ease;
        }

        .expiry-fill.good {
            background: var(--success);
        }

        .expiry-fill.warning {
            background: var(--warning);
        }

        .expiry-fill.danger {
            background: var(--danger);
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

        .btn-warning {
            background: linear-gradient(135deg, var(--warning), #d97706);
            color: white;
        }

        .btn-warning:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(245, 158, 11, 0.3);
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

        .pending-certificate-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 16px;
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: 10px;
            margin-bottom: 10px;
        }

        .pending-info {
            flex: 1;
        }

        .pending-title {
            font-weight: 600;
            color: var(--text-color);
            font-size: 14px;
        }

        .pending-details {
            display: flex;
            gap: 15px;
            margin-top: 5px;
            font-size: 12px;
            color: var(--text-light);
        }

        .progress-steps {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-top: 20px;
            padding: 20px;
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: 12px;
        }

        .progress-step {
            display: flex;
            flex-direction: column;
            align-items: center;
            text-align: center;
            flex: 1;
            position: relative;
        }

        .progress-step::before {
            content: '';
            position: absolute;
            top: 15px;
            left: -50%;
            width: 100%;
            height: 2px;
            background: var(--border-color);
            z-index: 1;
        }

        .progress-step:first-child::before {
            display: none;
        }

        .step-number {
            width: 30px;
            height: 30px;
            border-radius: 50%;
            background: var(--border-color);
            color: var(--text-color);
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 600;
            margin-bottom: 8px;
            z-index: 2;
            position: relative;
        }

        .step-number.completed {
            background: var(--success);
            color: white;
        }

        .step-number.active {
            background: var(--primary-color);
            color: white;
        }

        .step-label {
            font-size: 12px;
            color: var(--text-light);
            max-width: 100px;
        }

        .step-label.active {
            color: var(--text-color);
            font-weight: 600;
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
            
            .progress-steps {
                flex-direction: column;
                gap: 20px;
                align-items: flex-start;
            }
            
            .progress-step {
                flex-direction: row;
                align-items: center;
                gap: 15px;
                width: 100%;
            }
            
            .progress-step::before {
                top: 15px;
                left: 15px;
                width: 2px;
                height: 100%;
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
            
            .certificate-info {
                flex-direction: column;
                align-items: flex-start;
                gap: 8px;
            }
            
            .certificate-details {
                flex-direction: column;
                gap: 8px;
            }
            
            .pending-certificate-item {
                flex-direction: column;
                gap: 12px;
                align-items: flex-start;
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

        .expiry-status {
            display: flex;
            align-items: center;
            gap: 8px;
            margin-top: 5px;
        }

        .expiry-status .days-left {
            font-size: 11px;
            padding: 2px 6px;
            border-radius: 10px;
            font-weight: 600;
        }

        .days-left.good {
            background: rgba(16, 185, 129, 0.1);
            color: var(--success);
        }

        .days-left.warning {
            background: rgba(245, 158, 11, 0.1);
            color: var(--warning);
        }

        .days-left.danger {
            background: rgba(220, 38, 38, 0.1);
            color: var(--danger);
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
                    <a href="../dashboard.php" class="menu-item" id="dashboard-menu">
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
                        <a href="#" class="submenu-item">Active Incidents</a>
                        <a href="#" class="submenu-item">Incident Reports</a>
                        <a href="#" class="submenu-item">Response History</a>
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
                        <a href="#" class="submenu-item">Volunteer List</a>
                        <a href="#" class="submenu-item">Roles & Skills</a>
                        <a href="#" class="submenu-item">Availability</a>
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
                        <a href="#" class="submenu-item">Equipment List</a>
                        <a href="#" class="submenu-item">Stock Levels</a>
                        <a href="#" class="submenu-item">Maintenance Logs</a>
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
                        <a href="training_records.php" class="submenu-item">Training Records</a>
                        <a href="certification_status.php" class="submenu-item active">Certification Status</a>
                       
                    </div>
                    
                    <div class="menu-item" onclick="toggleSubmenu('inspection')">
                        <div class="icon-box icon-bg-yellow">
                            <i class='bx bxs-check-shield icon-yellow'></i>
                        </div>
                        <span class="font-medium">Establishment Inspections</span>
                        <svg class="dropdown-arrow menu-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                        </svg>
                    </div>
                    <div id="inspection" class="submenu">
                        <a href="#" class="submenu-item">Inspection Scheduler</a>
                        <a href="#" class="submenu-item">Inspection Results</a>
                        <a href="#" class="submenu-item">Violation Notices</a>
                    </div>
                    
                    <div class="menu-item" onclick="toggleSubmenu('postincident')">
                        <div class="icon-box icon-bg-pink">
                            <i class='bx bxs-file-doc icon-pink'></i>
                        </div>
                        <span class="font-medium">Post-Incident Analytics</span>
                        <svg class="dropdown-arrow menu-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                        </svg>
                    </div>
                    <div id="postincident" class="submenu">
                        <a href="#" class="submenu-item">Analytics Dashboard</a>
                        <a href="#" class="submenu-item">Incident Trends</a>
                        <a href="#" class="submenu-item">Lessons Learned</a>
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
                            <input type="text" placeholder="Search certificates..." class="search-input" id="search-input">
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
                        <h1 class="dashboard-title">Certification Status</h1>
                        <p class="dashboard-subtitle">Manage and track your training certificates and qualifications</p>
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
                        <div style="display: flex; align-items: center; gap: 10px;">
                            <i class='bx bx-certificate' style="font-size: 20px;"></i>
                            <span style="font-size: 13px;"><?php echo $verified_certificates; ?> Verified Certificate<?php echo $verified_certificates != 1 ? 's' : ''; ?></span>
                        </div>
                    </div>
                    
                    <!-- Statistics -->
                    <div class="stats-container">
                        <div class="stat-card">
                            <div class="stat-icon total">
                                <i class='bx bx-certificate'></i>
                            </div>
                            <div class="stat-value"><?php echo $total_certificates; ?></div>
                            <div class="stat-label">Total Certificates</div>
                        </div>
                        
                        <div class="stat-card">
                            <div class="stat-icon verified">
                                <i class='bx bx-check-circle'></i>
                            </div>
                            <div class="stat-value"><?php echo $verified_certificates; ?></div>
                            <div class="stat-label">Verified</div>
                        </div>
                        
                        <div class="stat-card">
                            <div class="stat-icon pending">
                                <i class='bx bx-time'></i>
                            </div>
                            <div class="stat-value"><?php echo $pending_certificates; ?></div>
                            <div class="stat-label">Pending</div>
                        </div>
                        
                        <div class="stat-card">
                            <div class="stat-icon expired">
                                <i class='bx bx-error-circle'></i>
                            </div>
                            <div class="stat-value"><?php echo $expired_certificates; ?></div>
                            <div class="stat-label">Expired</div>
                        </div>
                        
                        <?php if ($expiring_soon > 0): ?>
                        <div class="stat-card">
                            <div class="stat-icon expiring">
                                <i class='bx bx-alarm-exclamation'></i>
                            </div>
                            <div class="stat-value"><?php echo $expiring_soon; ?></div>
                            <div class="stat-label">Expiring Soon</div>
                        </div>
                        <?php endif; ?>
                    </div>
                    
                    <!-- Certificate Progress Steps -->
                    <div class="progress-steps">
                        <div class="progress-step">
                            <div class="step-number <?php echo $total_certificates > 0 ? 'completed' : ''; ?>">
                                1
                            </div>
                            <div class="step-label <?php echo $total_certificates > 0 ? 'active' : ''; ?>">
                                Complete Training
                            </div>
                        </div>
                        
                        <div class="progress-step">
                            <div class="step-number <?php echo $verified_certificates > 0 ? 'completed' : ($total_certificates > 0 ? 'active' : ''); ?>">
                                2
                            </div>
                            <div class="step-label <?php echo $verified_certificates > 0 ? 'active' : ($total_certificates > 0 ? 'active' : ''); ?>">
                                Employee Verification
                            </div>
                        </div>
                        
                        <div class="progress-step">
                            <div class="step-number <?php echo $verified_certificates > 0 ? 'completed' : ''; ?>">
                                3
                            </div>
                            <div class="step-label <?php echo $verified_certificates > 0 ? 'active' : ''; ?>">
                                Admin Approval
                            </div>
                        </div>
                        
                        <div class="progress-step">
                            <div class="step-number <?php echo $verified_certificates > 0 ? 'completed' : ''; ?>">
                                4
                            </div>
                            <div class="step-label <?php echo $verified_certificates > 0 ? 'active' : ''; ?>">
                                Certificate Issued
                            </div>
                        </div>
                    </div>
                    
                    <!-- Tabs -->
                    <div class="tabs-container">
                        <div class="tab active" onclick="showTab('certificates')" id="tab-certificates">
                            <i class='bx bx-certificate'></i>
                            <span>My Certificates</span>
                        </div>
                        <div class="tab" onclick="showTab('pending')" id="tab-pending">
                            <i class='bx bx-time'></i>
                            <span>Pending Certificates</span>
                            <?php if ($pending_certificates > 0): ?>
                            <span style="background: var(--warning); color: white; padding: 2px 6px; border-radius: 10px; font-size: 11px; margin-left: 5px;">
                                <?php echo $pending_certificates; ?>
                            </span>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <!-- Certificates Tab -->
                    <div class="table-container" id="certificates-tab">
                        <div class="table-header">
                            <h3 class="table-title">
                                <i class='bx bx-certificate'></i>
                                My Training Certificates
                                <span style="font-size: 14px; font-weight: normal; color: var(--text-light); margin-left: 10px;">
                                    <?php echo $total_certificates; ?> certificate<?php echo $total_certificates != 1 ? 's' : ''; ?> issued
                                </span>
                            </h3>
                        </div>
                        
                        <?php if (count($certificates) > 0): ?>
                            <table class="table" id="certificates-table">
                                <thead>
                                    <tr>
                                        <th>Certificate Details</th>
                                        <th>Training Information</th>
                                        <th>Validity Period</th>
                                        <th>Status</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($certificates as $cert): 
                                        $issue_date = date('M j, Y', strtotime($cert['issue_date']));
                                        $expiry_date = $cert['expiry_date'] ? date('M j, Y', strtotime($cert['expiry_date'])) : null;
                                        $training_date = date('M j, Y', strtotime($cert['training_date']));
                                        $completion_date = $cert['completion_date'] ? date('M j, Y', strtotime($cert['completion_date'])) : null;
                                        
                                        // Calculate expiry status
                                        $expiry_status = 'active';
                                        $days_until_expiry = null;
                                        $expiry_progress = 100;
                                        
                                        if ($expiry_date) {
                                            $current_date = new DateTime();
                                            $expiry_date_obj = new DateTime($expiry_date);
                                            $issue_date_obj = new DateTime($cert['issue_date']);
                                            
                                            $days_until_expiry = $current_date->diff($expiry_date_obj)->days;
                                            $total_days = $issue_date_obj->diff($expiry_date_obj)->days;
                                            
                                            if ($expiry_date_obj < $current_date) {
                                                $expiry_status = 'expired';
                                                $days_until_expiry = 0;
                                            } elseif ($days_until_expiry <= 30) {
                                                $expiry_status = 'warning';
                                            }
                                            
                                            if ($total_days > 0) {
                                                $days_used = $issue_date_obj->diff($current_date)->days;
                                                $expiry_progress = min(100, ($days_used / $total_days) * 100);
                                            }
                                        }
                                        
                                        // Get certificate ID from certificate data
                                        $cert_id = $cert['id'];
                                    ?>
                                    <tr>
                                        <td>
                                            <div class="certificate-info">
                                                <div class="certificate-number">
                                                    <?php echo htmlspecialchars($cert['certificate_number']); ?>
                                                </div>
                                                <div class="detail-item">
                                                    <i class='bx bx-calendar'></i>
                                                    <span>Issued: <?php echo $issue_date; ?></span>
                                                </div>
                                                <div class="detail-item">
                                                    <i class='bx bx-user'></i>
                                                    <span>Issued to: <?php echo htmlspecialchars($cert['first_name'] . ' ' . $cert['last_name']); ?></span>
                                                </div>
                                            </div>
                                        </td>
                                        <td>
                                            <div class="certificate-info">
                                                <div class="certificate-title">
                                                    <?php echo htmlspecialchars($cert['training_title']); ?>
                                                </div>
                                                <div class="detail-item">
                                                    <i class='bx bx-calendar-event'></i>
                                                    <span>Training: <?php echo $training_date; ?></span>
                                                </div>
                                                <?php if ($completion_date): ?>
                                                <div class="detail-item">
                                                    <i class='bx bx-check-circle'></i>
                                                    <span>Completed: <?php echo $completion_date; ?></span>
                                                </div>
                                                <?php endif; ?>
                                                <div class="detail-item">
                                                    <i class='bx bx-user'></i>
                                                    <span>Instructor: <?php echo htmlspecialchars($cert['instructor']); ?></span>
                                                </div>
                                            </div>
                                        </td>
                                        <td>
                                            <?php if ($expiry_date): ?>
                                            <div class="date-cell">
                                                <div class="date-label">Valid Until</div>
                                                <div class="date-value"><?php echo $expiry_date; ?></div>
                                                
                                                <div class="expiry-status">
                                                    <?php if ($expiry_status === 'expired'): ?>
                                                        <span class="status-badge status-expired">Expired</span>
                                                    <?php elseif ($expiry_status === 'warning'): ?>
                                                        <span class="status-badge status-warning">Expires in <?php echo $days_until_expiry; ?> days</span>
                                                    <?php else: ?>
                                                        <span class="status-badge status-active">Valid</span>
                                                    <?php endif; ?>
                                                    
                                                    <?php if ($days_until_expiry !== null): ?>
                                                    <span class="days-left <?php echo $expiry_status; ?>">
                                                        <?php echo $days_until_expiry; ?> days
                                                    </span>
                                                    <?php endif; ?>
                                                </div>
                                                
                                                <div class="expiry-progress">
                                                    <div class="expiry-fill <?php echo $expiry_status; ?>" style="width: <?php echo $expiry_progress; ?>%;"></div>
                                                </div>
                                            </div>
                                            <?php else: ?>
                                            <div style="color: var(--text-light); font-size: 12px;">
                                                No expiry date
                                            </div>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($cert['verified'] == 1): ?>
                                                <div class="status-badge status-verified">Verified</div>
                                            <?php else: ?>
                                                <div class="status-badge status-pending">Pending Verification</div>
                                            <?php endif; ?>
                                            
                                            <?php if ($cert['admin_approved']): ?>
                                                <div style="margin-top: 5px; font-size: 11px; color: var(--success);">
                                                    <i class='bx bx-check'></i> Admin Approved
                                                </div>
                                            <?php elseif ($cert['employee_submitted']): ?>
                                                <div style="margin-top: 5px; font-size: 11px; color: var(--warning);">
                                                    <i class='bx bx-time'></i> Submitted to Admin
                                                </div>
                                            <?php elseif ($cert['completion_verified']): ?>
                                                <div style="margin-top: 5px; font-size: 11px; color: var(--info);">
                                                    <i class='bx bx-user-check'></i> Employee Verified
                                                </div>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div class="action-buttons">
                                                <button type="button" class="btn btn-info btn-sm view-certificate-btn" 
                                                        onclick="viewCertificate(<?php echo $cert_id; ?>)">
                                                    <i class='bx bx-show'></i>
                                                    View
                                                </button>
                                                
                                                <?php if ($cert['certificate_file']): ?>
                                                <a href="?download_certificate=<?php echo $cert_id; ?>" class="btn btn-success btn-sm">
                                                    <i class='bx bx-download'></i>
                                                    Download
                                                </a>
                                                <?php endif; ?>
                                                
                                                <?php if ($expiry_status === 'expired'): ?>
                                                <button type="button" class="btn btn-warning btn-sm" onclick="requestRenewal(<?php echo $cert_id; ?>)">
                                                    <i class='bx bx-refresh'></i>
                                                    Renew
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
                                <i class='bx bx-certificate'></i>
                                <h3>No Certificates Yet</h3>
                                <p>You haven't earned any certificates yet. Complete trainings and get them approved to receive certificates.</p>
                                <a href="training_records.php" class="btn btn-primary" style="margin-top: 20px;">
                                    <i class='bx bx-history'></i>
                                    View Training Records
                                </a>
                            </div>
                        <?php endif; ?>
                    </div>
                    
                    <!-- Pending Certificates Tab -->
                    <div class="table-container" id="pending-tab" style="display: none;">
                        <div class="table-header">
                            <h3 class="table-title">
                                <i class='bx bx-time'></i>
                                Pending Certificates
                                <span style="font-size: 14px; font-weight: normal; color: var(--text-light); margin-left: 10px;">
                                    <?php echo $pending_certificates; ?> training<?php echo $pending_certificates != 1 ? 's' : ''; ?> pending certificates
                                </span>
                            </h3>
                        </div>
                        
                        <?php if (count($completed_trainings) > 0): 
                            $has_pending = false;
                        ?>
                            <table class="table">
                                <thead>
                                    <tr>
                                        <th>Training Details</th>
                                        <th>Completion Date</th>
                                        <th>Verification Status</th>
                                        <th>Estimated Timeline</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($completed_trainings as $training): 
                                        if ($training['certificate_id']) continue;
                                        
                                        $has_pending = true;
                                        $training_date = date('M j, Y', strtotime($training['training_date']));
                                        $completion_date = $training['completion_date'] ? date('M j, Y', strtotime($training['completion_date'])) : null;
                                        $completion_days_ago = $completion_date ? floor((time() - strtotime($completion_date)) / (60 * 60 * 24)) : null;
                                        
                                        // Determine verification stage
                                        $verification_stage = 1;
                                        $stage_label = 'Employee Verification';
                                        
                                        if ($training['completion_verified']) {
                                            $verification_stage = 2;
                                            $stage_label = 'Submitted to Admin';
                                        }
                                        
                                        if ($training['employee_submitted']) {
                                            $verification_stage = 3;
                                            $stage_label = 'Admin Approval';
                                        }
                                        
                                        if ($training['admin_approved']) {
                                            $verification_stage = 4;
                                            $stage_label = 'Certificate Generation';
                                        }
                                    ?>
                                    <tr>
                                        <td>
                                            <div class="certificate-info">
                                                <div class="certificate-title">
                                                    <?php echo htmlspecialchars($training['title']); ?>
                                                </div>
                                                <div class="detail-item">
                                                    <i class='bx bx-calendar-event'></i>
                                                    <span>Training Date: <?php echo $training_date; ?></span>
                                                </div>
                                                <div class="detail-item">
                                                    <i class='bx bx-user'></i>
                                                    <span>Instructor: <?php echo htmlspecialchars($training['instructor']); ?></span>
                                                </div>
                                            </div>
                                        </td>
                                        <td>
                                            <div class="date-cell">
                                                <?php if ($completion_date): ?>
                                                <div class="date-label">Completed On</div>
                                                <div class="date-value"><?php echo $completion_date; ?></div>
                                                <div style="font-size: 11px; color: var(--text-light); margin-top: 4px;">
                                                    <?php echo $completion_days_ago; ?> day<?php echo $completion_days_ago != 1 ? 's' : ''; ?> ago
                                                </div>
                                                <?php else: ?>
                                                <div style="color: var(--text-light); font-size: 12px;">
                                                    Completion date not recorded
                                                </div>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                        <td>
                                            <div class="status-badge status-<?php echo $verification_stage < 4 ? 'pending' : 'active'; ?>">
                                                <?php echo $stage_label; ?>
                                            </div>
                                            
                                            <div class="progress-steps" style="margin-top: 10px; padding: 10px; background: transparent;">
                                                <?php for ($i = 1; $i <= 4; $i++): ?>
                                                <div class="progress-step" style="flex: none; margin: 0 5px;">
                                                    <div class="step-number <?php echo $i <= $verification_stage ? 'completed' : ''; ?>">
                                                        <?php echo $i; ?>
                                                    </div>
                                                </div>
                                                <?php endfor; ?>
                                            </div>
                                        </td>
                                        <td>
                                            <div style="font-size: 12px; color: var(--text-light);">
                                                <?php if ($verification_stage == 1): ?>
                                                    <p>Waiting for employee to verify completion</p>
                                                    <p><strong>Typical timeline:</strong> 1-3 business days</p>
                                                <?php elseif ($verification_stage == 2): ?>
                                                    <p>Employee has verified, awaiting submission to admin</p>
                                                    <p><strong>Typical timeline:</strong> 1-2 business days</p>
                                                <?php elseif ($verification_stage == 3): ?>
                                                    <p>Submitted to admin for approval</p>
                                                    <p><strong>Typical timeline:</strong> 3-5 business days</p>
                                                <?php elseif ($verification_stage == 4): ?>
                                                    <p>Approved, certificate being generated</p>
                                                    <p><strong>Typical timeline:</strong> 1-2 business days</p>
                                                <?php endif; ?>
                                                
                                                <?php if ($completion_days_ago && $completion_days_ago > 14): ?>
                                                <div style="margin-top: 10px; padding: 8px; background: rgba(245, 158, 11, 0.1); border-radius: 6px; border-left: 3px solid var(--warning);">
                                                    <strong>Delayed:</strong> Contact training coordinator
                                                </div>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                    
                                    <?php if (!$has_pending): ?>
                                    <tr>
                                        <td colspan="4">
                                            <div class="empty-state" style="padding: 20px;">
                                                <i class='bx bx-check-circle' style="color: var(--success);"></i>
                                                <h3 style="font-size: 16px;">No Pending Certificates</h3>
                                                <p>All your completed trainings have certificates issued!</p>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        <?php else: ?>
                            <div class="empty-state">
                                <i class='bx bx-check-circle'></i>
                                <h3>No Pending Certificates</h3>
                                <p>You don't have any completed trainings waiting for certificates.</p>
                                <a href="register_training.php" class="btn btn-primary" style="margin-top: 20px;">
                                    <i class='bx bx-search'></i>
                                    Register for Trainings
                                </a>
                            </div>
                        <?php endif; ?>
                    </div>
                    
                    <!-- Information Section -->
                    <div class="section-container" style="border: 1px solid var(--border-color); border-radius: 16px; padding: 24px; margin-top: 20px;">
                        <h3 style="font-size: 18px; font-weight: 700; color: var(--text-color); margin-bottom: 20px; display: flex; align-items: center; gap: 10px;">
                            <i class='bx bx-info-circle'></i>
                            About Certificates & Verification
                        </h3>
                        
                        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 20px; margin-bottom: 20px;">
                            <div style="background: rgba(16, 185, 129, 0.1); padding: 20px; border-radius: 10px; border: 1px solid rgba(16, 185, 129, 0.2);">
                                <h4 style="margin: 0 0 10px 0; color: var(--text-color);">Certificate Types</h4>
                                <ul style="margin: 0; padding-left: 20px; font-size: 13px; color: var(--text-color);">
                                    <li><strong>Completion Certificate:</strong> Awarded for finishing training</li>
                                    <li><strong>Proficiency Certificate:</strong> Demonstrates skill mastery</li>
                                    <li><strong>Specialization Certificate:</strong> For advanced training areas</li>
                                    <li><strong>Recertification Certificate:</strong> Renewal of expired certificates</li>
                                </ul>
                            </div>
                            
                            <div style="background: rgba(59, 130, 246, 0.1); padding: 20px; border-radius: 10px; border: 1px solid rgba(59, 130, 246, 0.2);">
                                <h4 style="margin: 0 0 10px 0; color: var(--text-color);">Verification Process</h4>
                                <ul style="margin: 0; padding-left: 20px; font-size: 13px; color: var(--text-color);">
                                    <li>1. Training completion verification</li>
                                    <li>2. Skills assessment review</li>
                                    <li>3. Attendance confirmation</li>
                                    <li>4. Final approval by supervisor</li>
                                    <li>5. Certificate issuance and recording</li>
                                </ul>
                            </div>
                            
                            <div style="background: rgba(245, 158, 11, 0.1); padding: 20px; border-radius: 10px; border: 1px solid rgba(245, 158, 11, 0.2);">
                                <h4 style="margin: 0 0 10px 0; color: var(--text-color);">Validity Periods</h4>
                                <ul style="margin: 0; padding-left: 20px; font-size: 13px; color: var(--text-color);">
                                    <li><strong>Basic Training:</strong> 2 years validity</li>
                                    <li><strong>Advanced Training:</strong> 3 years validity</li>
                                    <li><strong>Specialized Training:</strong> 1-2 years validity</li>
                                    <li><strong>Annual Refreshers:</strong> 1 year validity</li>
                                    <li><strong>Some certificates:</strong> No expiration</li>
                                </ul>
                            </div>
                        </div>
                        
                        <div style="margin-top: 20px; padding: 15px; background: rgba(220, 38, 38, 0.05); border-radius: 8px; border-left: 3px solid var(--primary-color);">
                            <h4 style="margin: 0 0 10px 0; color: var(--primary-color);">Important Information:</h4>
                            <ul style="margin: 0; padding-left: 20px; color: var(--text-color); font-size: 13px;">
                                <li>Keep digital copies of all certificates for your records</li>
                                <li>Expired certificates must be renewed within 30 days of expiration</li>
                                <li>Some positions require current (non-expired) certificates</li>
                                <li>Certificate verification can take 5-10 business days</li>
                                <li>Contact the training department for certificate issues</li>
                                <li>Report lost certificates immediately for replacement</li>
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
            const certificatesTable = document.getElementById('certificates-table');
            
            if (!searchInput || !certificatesTable) return;
            
            searchInput.addEventListener('input', function() {
                const searchTerm = this.value.toLowerCase();
                const rows = certificatesTable.querySelectorAll('tbody tr');
                
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
        
        function showTab(tabName) {
            // Hide all tabs
            document.getElementById('certificates-tab').style.display = 'none';
            document.getElementById('pending-tab').style.display = 'none';
            
            // Remove active class from all tabs
            document.getElementById('tab-certificates').classList.remove('active');
            document.getElementById('tab-pending').classList.remove('active');
            
            // Show selected tab
            document.getElementById(tabName + '-tab').style.display = 'block';
            document.getElementById('tab-' + tabName).classList.add('active');
        }
        
        function viewCertificate(certificateId) {
            window.location.href = '?view_certificate=' + certificateId;
        }
        
        function closeCertificateModal() {
            window.location.href = 'certification_status.php';
        }
        
        function requestRenewal(certificateId) {
            if (confirm('Request renewal for this certificate? This will notify the training coordinator.')) {
                // In a real implementation, this would make an AJAX call to request renewal
                alert('Renewal request sent! The training coordinator will contact you about renewal options.');
                // Example: fetch('request_renewal.php?id=' + certificateId);
            }
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