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

// Check if user has permission (EMPLOYEE or ADMIN only)
if ($role !== 'EMPLOYEE' && $role !== 'ADMIN') {
    header("Location: ../employee_dashboard.php");
    exit();
}

// Handle actions
$success_message = '';
$error_message = '';

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['submit_report'])) {
        $incident_id = $_POST['incident_id'];
        $debrief_notes = $_POST['debrief_notes'];
        $equipment_used = isset($_POST['equipment_used']) ? json_encode($_POST['equipment_used']) : '[]';
        $completion_status = $_POST['completion_status'];
        
        // Upload field report files
        $uploaded_files = [];
        if (!empty($_FILES['field_reports']['name'][0])) {
            foreach ($_FILES['field_reports']['tmp_name'] as $key => $tmp_name) {
                if ($_FILES['field_reports']['error'][$key] === UPLOAD_ERR_OK) {
                    $file_name = basename($_FILES['field_reports']['name'][$key]);
                    $file_ext = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));
                    $new_file_name = "field_report_" . $incident_id . "_" . time() . "_" . $key . "." . $file_ext;
                    $upload_dir = "../../uploads/post_incident_reports/";
                    
                    // Create directory if it doesn't exist
                    if (!file_exists($upload_dir)) {
                        mkdir($upload_dir, 0777, true);
                    }
                    
                    $file_path = $upload_dir . $new_file_name;
                    
                    if (move_uploaded_file($tmp_name, $file_path)) {
                        $uploaded_files[] = "post_incident_reports/" . $new_file_name;
                    }
                }
            }
        }
        
        $field_reports_json = json_encode($uploaded_files);
        
        try {
            // Start transaction
            $pdo->beginTransaction();
            
            // Insert post-incident report
            $stmt = $pdo->prepare("
                INSERT INTO post_incident_reports (
                    incident_id, submitted_by, field_reports, equipment_used_json, 
                    debrief_notes, completion_status, submitted_at
                ) VALUES (?, ?, ?, ?, ?, ?, NOW())
            ");
            $stmt->execute([$incident_id, $user_id, $field_reports_json, $equipment_used, $debrief_notes, $completion_status]);
            $report_id = $pdo->lastInsertId();
            
            // Update incident status if marked as completed
            if ($completion_status === 'completed') {
                $stmt = $pdo->prepare("
                    UPDATE api_incidents 
                    SET status = 'closed', dispatch_status = 'closed'
                    WHERE id = ?
                ");
                $stmt->execute([$incident_id]);
                
                // Update dispatch status if exists
                $stmt = $pdo->prepare("
                    UPDATE dispatch_incidents 
                    SET status = 'completed', status_updated_at = NOW()
                    WHERE incident_id = ? AND status != 'completed'
                ");
                $stmt->execute([$incident_id]);
                
                // Update unit status
                $stmt = $pdo->prepare("
                    UPDATE units u
                    JOIN dispatch_incidents di ON u.id = di.unit_id
                    SET u.current_status = 'available', u.current_dispatch_id = NULL
                    WHERE di.incident_id = ? AND di.status = 'completed'
                ");
                $stmt->execute([$incident_id]);
            }
            
            $pdo->commit();
            $success_message = "Post-incident report submitted successfully!";
            
            // Redirect to prevent form resubmission
            header("Location: post_incident_reporting.php?success=1");
            exit();
            
        } catch (Exception $e) {
            $pdo->rollBack();
            $error_message = "Failed to submit report: " . $e->getMessage();
        }
    }
}

// Get incidents for selection
$stmt = $pdo->prepare("
    SELECT 
        ai.id,
        ai.external_id,
        ai.title,
        ai.emergency_type,
        ai.location,
        ai.description,
        ai.caller_name,
        ai.caller_phone,
        ai.status,
        ai.dispatch_status,
        ai.created_at,
        di.status as dispatch_status_detail,
        di.dispatched_at,
        u.unit_name
    FROM api_incidents ai
    LEFT JOIN dispatch_incidents di ON ai.id = di.incident_id
    LEFT JOIN units u ON di.unit_id = u.id
    WHERE ai.status IN ('processing', 'responded')
    AND ai.is_fire_rescue_related = 1
    ORDER BY ai.created_at DESC
");
$stmt->execute();
$incidents = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get available equipment
$stmt = $pdo->prepare("
    SELECT id, resource_name, resource_type, category, available_quantity
    FROM resources 
    WHERE is_active = 1 
    AND condition_status = 'Serviceable'
    AND available_quantity > 0
    ORDER BY resource_type, resource_name
");
$stmt->execute();
$equipment_list = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get submitted reports for viewing
$stmt = $pdo->prepare("
    SELECT 
        pir.*,
        ai.title,
        ai.emergency_type,
        ai.location,
        u.first_name,
        u.last_name,
        di.unit_id,
        un.unit_name
    FROM post_incident_reports pir
    JOIN api_incidents ai ON pir.incident_id = ai.id
    JOIN users u ON pir.submitted_by = u.id
    LEFT JOIN dispatch_incidents di ON pir.incident_id = di.incident_id
    LEFT JOIN units un ON di.unit_id = un.id
    ORDER BY pir.submitted_at DESC
    LIMIT 10
");
$stmt->execute();
$submitted_reports = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Handle success message from redirect
if (isset($_GET['success']) && $_GET['success'] == '1') {
    $success_message = "Post-incident report submitted successfully!";
}

$stmt = null;
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Post-Incident Reporting - Fire & Rescue Management</title>
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

        .tabs-container {
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: 16px;
            margin-bottom: 30px;
            overflow: hidden;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
        }

        .tabs-header {
            display: flex;
            border-bottom: 1px solid var(--border-color);
        }

        .tab {
            padding: 20px 30px;
            background: none;
            border: none;
            font-size: 16px;
            font-weight: 600;
            color: var(--text-light);
            cursor: pointer;
            transition: all 0.3s ease;
            position: relative;
        }

        .tab:hover {
            color: var(--text-color);
        }

        .tab.active {
            color: var(--primary-color);
        }

        .tab.active::after {
            content: '';
            position: absolute;
            bottom: 0;
            left: 0;
            right: 0;
            height: 3px;
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            border-radius: 3px 3px 0 0;
        }

        .tab-content {
            display: none;
            padding: 30px;
        }

        .tab-content.active {
            display: block;
        }

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
            min-height: 120px;
        }

        .form-file {
            padding: 10px;
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

        .incident-card {
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 15px;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .incident-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
        }

        .incident-card.selected {
            border-color: var(--primary-color);
            border-width: 2px;
            background: rgba(220, 38, 38, 0.02);
        }

        .incident-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 15px;
        }

        .incident-title {
            font-size: 18px;
            font-weight: 700;
            color: var(--text-color);
            margin-bottom: 5px;
        }

        .incident-type {
            display: inline-block;
            padding: 4px 12px;
            background: rgba(220, 38, 38, 0.1);
            color: var(--primary-color);
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
        }

        .incident-details {
            color: var(--text-light);
            font-size: 14px;
            margin-bottom: 10px;
        }

        .incident-meta {
            display: flex;
            gap: 15px;
            font-size: 13px;
            color: var(--text-light);
        }

        .status-badge {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
        }

        .status-processing {
            background: rgba(59, 130, 246, 0.1);
            color: var(--info);
        }

        .status-responded {
            background: rgba(16, 185, 129, 0.1);
            color: var(--success);
        }

        .status-closed {
            background: rgba(107, 114, 128, 0.1);
            color: var(--gray-500);
        }

        .equipment-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
            gap: 15px;
            margin-top: 10px;
        }

        .equipment-item {
            background: var(--gray-100);
            border: 1px solid var(--border-color);
            border-radius: 8px;
            padding: 12px;
            transition: all 0.3s ease;
        }

        .dark-mode .equipment-item {
            background: var(--gray-800);
        }

        .equipment-item:hover {
            border-color: var(--primary-color);
        }

        .equipment-item.selected {
            border-color: var(--primary-color);
            background: rgba(220, 38, 38, 0.05);
        }

        .equipment-name {
            font-weight: 600;
            margin-bottom: 5px;
        }

        .equipment-details {
            font-size: 12px;
            color: var(--text-light);
            margin-bottom: 5px;
        }

        .equipment-quantity {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .quantity-input {
            width: 60px;
            padding: 4px 8px;
            border-radius: 6px;
            border: 1px solid var(--border-color);
            background: var(--card-bg);
            color: var(--text-color);
        }

        .file-upload-container {
            border: 2px dashed var(--border-color);
            border-radius: 12px;
            padding: 30px;
            text-align: center;
            transition: all 0.3s ease;
        }

        .file-upload-container:hover {
            border-color: var(--primary-color);
        }

        .file-upload-container.dragover {
            border-color: var(--primary-color);
            background: rgba(220, 38, 38, 0.02);
        }

        .file-upload-icon {
            font-size: 48px;
            color: var(--text-light);
            margin-bottom: 15px;
        }

        .file-upload-text {
            margin-bottom: 15px;
            color: var(--text-light);
        }

        .file-upload-text strong {
            color: var(--primary-color);
        }

        .file-preview {
            margin-top: 20px;
        }

        .file-preview-item {
            display: flex;
            align-items: center;
            gap: 10px;
            background: var(--gray-100);
            border-radius: 8px;
            padding: 10px;
            margin-bottom: 10px;
        }

        .dark-mode .file-preview-item {
            background: var(--gray-800);
        }

        .file-icon {
            font-size: 20px;
            color: var(--primary-color);
        }

        .file-name {
            flex: 1;
            font-size: 14px;
            word-break: break-all;
        }

        .file-remove {
            background: none;
            border: none;
            color: var(--danger);
            cursor: pointer;
            font-size: 18px;
        }

        .reports-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));
            gap: 20px;
        }

        .report-card {
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: 12px;
            padding: 20px;
            transition: all 0.3s ease;
        }

        .report-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
        }

        .report-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 15px;
            padding-bottom: 15px;
            border-bottom: 1px solid var(--border-color);
        }

        .report-title {
            font-size: 18px;
            font-weight: 700;
            color: var(--text-color);
            margin-bottom: 5px;
        }

        .report-date {
            font-size: 12px;
            color: var(--text-light);
        }

        .report-details {
            margin-bottom: 15px;
        }

        .report-detail-item {
            display: flex;
            justify-content: space-between;
            margin-bottom: 8px;
            font-size: 14px;
        }

        .report-detail-label {
            color: var(--text-light);
        }

        .report-detail-value {
            font-weight: 600;
            color: var(--text-color);
        }

        .report-files {
            margin-top: 15px;
        }

        .report-files-title {
            font-weight: 600;
            margin-bottom: 10px;
            color: var(--text-color);
        }

        .file-list {
            display: flex;
            flex-direction: column;
            gap: 5px;
        }

        .file-link {
            color: var(--primary-color);
            text-decoration: none;
            font-size: 14px;
            display: flex;
            align-items: center;
            gap: 5px;
            transition: color 0.3s ease;
        }

        .file-link:hover {
            color: var(--secondary-color);
            text-decoration: underline;
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
            
            .tabs-header {
                flex-direction: column;
            }
            
            .tab {
                text-align: center;
                border-right: none;
                border-bottom: 1px solid var(--border-color);
            }
            
            .reports-grid {
                grid-template-columns: 1fr;
            }
            
            .equipment-grid {
                grid-template-columns: repeat(2, 1fr);
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
            
            .tab-content {
                padding: 20px;
            }
            
            .form-row {
                flex-direction: column;
                gap: 15px;
            }
            
            .equipment-grid {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 576px) {
            .btn {
                justify-content: center;
            }
            
            .incident-header {
                flex-direction: column;
                gap: 10px;
            }
            
            .incident-meta {
                flex-wrap: wrap;
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
                    <div id="inspection" class="submenu">
                        <a href="conduct_inspections.php" class="submenu-item">Conduct Inspections</a>
                        <a href="submit_findings.php" class="submenu-item">Submit Findings</a>
                        <a href="tag_violations.php" class="submenu-item">Tag Violations</a>
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
                    <div id="postincident" class="submenu active">
                        <a href="post_incident_reporting.php" class="submenu-item active">Reporting</a>
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
                            <input type="text" placeholder="Search reports..." class="search-input" id="search-input">
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
                        <h1 class="dashboard-title">Post-Incident Reporting</h1>
                        <p class="dashboard-subtitle">Submit field reports, debrief notes, and mark incidents as completed</p>
                    </div>
                </div>
                
                <!-- Content Container -->
                <div class="content-container">
                    <!-- Tabs -->
                    <div class="tabs-container">
                        <div class="tabs-header">
                            <button class="tab active" data-tab="submit-report">Submit Report</button>
                            <button class="tab" data-tab="view-reports">View Submitted Reports</button>
                        </div>
                        
                        <!-- Submit Report Tab -->
                        <div class="tab-content active" id="submit-report">
                            <form method="POST" id="report-form" enctype="multipart/form-data">
                                <!-- Step 1: Select Incident -->
                                <div class="form-section">
                                    <h3 class="section-title">
                                        <i class='bx bx-search-alt'></i>
                                        Step 1: Select Incident
                                    </h3>
                                    
                                    <div class="form-group">
                                        <label class="form-label required">Active Incidents</label>
                                        <div id="incidents-list">
                                            <?php if (count($incidents) > 0): ?>
                                                <?php foreach ($incidents as $incident): 
                                                    $created_date = date('M j, Y H:i', strtotime($incident['created_at']));
                                                    $dispatched_date = $incident['dispatched_at'] ? date('M j, Y H:i', strtotime($incident['dispatched_at'])) : 'Not dispatched';
                                                    $status_class = 'status-' . $incident['status'];
                                                ?>
                                                <div class="incident-card" data-incident-id="<?php echo $incident['id']; ?>">
                                                    <div class="incident-header">
                                                        <div>
                                                            <div class="incident-title"><?php echo htmlspecialchars($incident['title']); ?></div>
                                                            <div class="incident-type"><?php echo htmlspecialchars($incident['emergency_type']); ?></div>
                                                        </div>
                                                        <div class="status-badge <?php echo $status_class; ?>">
                                                            <?php echo htmlspecialchars(ucfirst($incident['status'])); ?>
                                                        </div>
                                                    </div>
                                                    <div class="incident-details">
                                                        <?php echo htmlspecialchars(substr($incident['description'], 0, 100)); ?><?php echo strlen($incident['description']) > 100 ? '...' : ''; ?>
                                                    </div>
                                                    <div class="incident-meta">
                                                        <div><i class='bx bx-map'></i> <?php echo htmlspecialchars($incident['location']); ?></div>
                                                        <div><i class='bx bx-calendar'></i> <?php echo $created_date; ?></div>
                                                        <div><i class='bx bx-user'></i> <?php echo htmlspecialchars($incident['caller_name']); ?></div>
                                                        <?php if ($incident['unit_name']): ?>
                                                        <div><i class='bx bx-truck'></i> <?php echo htmlspecialchars($incident['unit_name']); ?></div>
                                                        <?php endif; ?>
                                                    </div>
                                                </div>
                                                <?php endforeach; ?>
                                            <?php else: ?>
                                                <div class="empty-state">
                                                    <i class='bx bx-check-circle'></i>
                                                    <h3>No Active Incidents</h3>
                                                    <p>All incidents have been completed or there are no active fire/rescue incidents requiring reports.</p>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                        <input type="hidden" name="incident_id" id="selected-incident" required>
                                    </div>
                                </div>
                                
                                <!-- Step 2: Upload Field Reports -->
                                <div class="form-section">
                                    <h3 class="section-title">
                                        <i class='bx bx-upload'></i>
                                        Step 2: Upload Field Reports (Optional)
                                    </h3>
                                    
                                    <div class="file-upload-container" id="file-upload-container">
                                        <i class='bx bx-cloud-upload file-upload-icon'></i>
                                        <p class="file-upload-text">
                                            <strong>Click to upload</strong> or drag and drop<br>
                                            Photos, videos, PDF reports (Max 10 files, 5MB each)
                                        </p>
                                        <input type="file" class="form-file" id="field-reports" name="field_reports[]" multiple accept="image/*,.pdf,.doc,.docx,.mp4,.avi" style="display: none;">
                                        <button type="button" class="btn btn-secondary" onclick="document.getElementById('field-reports').click()">
                                            <i class='bx bx-folder-open'></i>
                                            Browse Files
                                        </button>
                                    </div>
                                    
                                    <div class="file-preview" id="file-preview"></div>
                                </div>
                                
                                <!-- Step 3: Attach Equipment Used -->
                                <div class="form-section">
                                    <h3 class="section-title">
                                        <i class='bx bx-cog'></i>
                                        Step 3: Attach Equipment Used (Optional)
                                    </h3>
                                    
                                    <div class="form-group">
                                        <label class="form-label">Select Equipment Used</label>
                                        <div class="equipment-grid" id="equipment-grid">
                                            <?php if (count($equipment_list) > 0): ?>
                                                <?php foreach ($equipment_list as $equipment): ?>
                                                <div class="equipment-item" data-equipment-id="<?php echo $equipment['id']; ?>">
                                                    <div class="equipment-name"><?php echo htmlspecialchars($equipment['resource_name']); ?></div>
                                                    <div class="equipment-details">
                                                        <?php echo htmlspecialchars($equipment['resource_type']); ?> • 
                                                        <?php echo htmlspecialchars($equipment['category']); ?>
                                                    </div>
                                                    <div class="equipment-quantity">
                                                        <span>Available: <?php echo $equipment['available_quantity']; ?></span>
                                                        <input type="number" class="quantity-input" 
                                                               min="1" 
                                                               max="<?php echo $equipment['available_quantity']; ?>" 
                                                               value="1" 
                                                               data-equipment-id="<?php echo $equipment['id']; ?>"
                                                               style="display: none;">
                                                    </div>
                                                </div>
                                                <?php endforeach; ?>
                                            <?php else: ?>
                                                <p style="color: var(--text-light);">No equipment available in inventory.</p>
                                            <?php endif; ?>
                                        </div>
                                        <input type="hidden" name="equipment_used_json" id="equipment-used-json" value="[]">
                                    </div>
                                </div>
                                
                                <!-- Step 4: Add Debrief Notes -->
                                <div class="form-section">
                                    <h3 class="section-title">
                                        <i class='bx bx-edit'></i>
                                        Step 4: Add Debrief Notes
                                    </h3>
                                    
                                    <div class="form-group">
                                        <label class="form-label required" for="debrief-notes">Debrief Notes</label>
                                        <textarea class="form-textarea" id="debrief-notes" name="debrief_notes" 
                                                  placeholder="Describe the incident response, challenges faced, lessons learned, recommendations for improvement..." 
                                                  rows="8" required></textarea>
                                    </div>
                                </div>
                                
                                <!-- Step 5: Mark Incident Status -->
                                <div class="form-section">
                                    <h3 class="section-title">
                                        <i class='bx bx-check-circle'></i>
                                        Step 5: Mark Incident Status
                                    </h3>
                                    
                                    <div class="form-row">
                                        <div class="form-group">
                                            <label class="form-label required" for="completion-status">Completion Status</label>
                                            <select class="form-select" id="completion-status" name="completion_status" required>
                                                <option value="draft">Draft Report</option>
                                                <option value="completed">Incident Completed</option>
                                            </select>
                                            <p style="font-size: 12px; color: var(--text-light); margin-top: 8px;">
                                                <strong>Note:</strong> Selecting "Incident Completed" will close the incident and free up resources.
                                            </p>
                                        </div>
                                    </div>
                                </div>
                                
                                <!-- Submit Button -->
                                <div class="form-section" style="text-align: right;">
                                    <button type="submit" name="submit_report" class="btn btn-success">
                                        <i class='bx bx-send'></i>
                                        Submit Post-Incident Report
                                    </button>
                                </div>
                            </form>
                        </div>
                        
                        <!-- View Submitted Reports Tab -->
                        <div class="tab-content" id="view-reports">
                            <?php if (count($submitted_reports) > 0): ?>
                                <div class="reports-grid">
                                    <?php foreach ($submitted_reports as $report): 
                                        $submitted_date = date('M j, Y H:i', strtotime($report['submitted_at']));
                                        $equipment_used = json_decode($report['equipment_used_json'], true) ?: [];
                                        $field_reports = json_decode($report['field_reports'], true) ?: [];
                                    ?>
                                    <div class="report-card">
                                        <div class="report-header">
                                            <div>
                                                <div class="report-title"><?php echo htmlspecialchars($report['title']); ?></div>
                                                <div class="report-date">Submitted: <?php echo $submitted_date; ?></div>
                                            </div>
                                            <div class="status-badge status-<?php echo $report['completion_status']; ?>">
                                                <?php echo htmlspecialchars(ucfirst($report['completion_status'])); ?>
                                            </div>
                                        </div>
                                        
                                        <div class="report-details">
                                            <div class="report-detail-item">
                                                <span class="report-detail-label">Incident Type:</span>
                                                <span class="report-detail-value"><?php echo htmlspecialchars($report['emergency_type']); ?></span>
                                            </div>
                                            <div class="report-detail-item">
                                                <span class="report-detail-label">Location:</span>
                                                <span class="report-detail-value"><?php echo htmlspecialchars($report['location']); ?></span>
                                            </div>
                                            <div class="report-detail-item">
                                                <span class="report-detail-label">Submitted By:</span>
                                                <span class="report-detail-value"><?php echo htmlspecialchars($report['first_name'] . ' ' . $report['last_name']); ?></span>
                                            </div>
                                            <?php if ($report['unit_name']): ?>
                                            <div class="report-detail-item">
                                                <span class="report-detail-label">Unit:</span>
                                                <span class="report-detail-value"><?php echo htmlspecialchars($report['unit_name']); ?></span>
                                            </div>
                                            <?php endif; ?>
                                        </div>
                                        
                                        <?php if (!empty($equipment_used)): ?>
                                        <div class="report-details">
                                            <div class="report-detail-label">Equipment Used:</div>
                                            <div style="font-size: 13px; color: var(--text-color);">
                                                <?php foreach ($equipment_used as $equipment): ?>
                                                <div>• <?php echo htmlspecialchars($equipment['name'] ?? $equipment['resource_name']); ?> 
                                                    <?php if (isset($equipment['quantity'])): ?>(x<?php echo $equipment['quantity']; ?>)<?php endif; ?>
                                                </div>
                                                <?php endforeach; ?>
                                            </div>
                                        </div>
                                        <?php endif; ?>
                                        
                                        <?php if (!empty($field_reports)): ?>
                                        <div class="report-files">
                                            <div class="report-files-title">Attached Files:</div>
                                            <div class="file-list">
                                                <?php foreach ($field_reports as $file): 
                                                    $file_name = basename($file);
                                                    $file_ext = pathinfo($file_name, PATHINFO_EXTENSION);
                                                    $icon = 'bx-file';
                                                    if (in_array($file_ext, ['jpg', 'jpeg', 'png', 'gif'])) $icon = 'bx-image';
                                                    if (in_array($file_ext, ['pdf'])) $icon = 'bx-file-pdf';
                                                    if (in_array($file_ext, ['doc', 'docx'])) $icon = 'bx-file-doc';
                                                ?>
                                                <a href="../../uploads/<?php echo htmlspecialchars($file); ?>" target="_blank" class="file-link">
                                                    <i class='bx <?php echo $icon; ?>'></i>
                                                    <?php echo htmlspecialchars($file_name); ?>
                                                </a>
                                                <?php endforeach; ?>
                                            </div>
                                        </div>
                                        <?php endif; ?>
                                        
                                        <div style="margin-top: 15px; padding-top: 15px; border-top: 1px solid var(--border-color);">
                                            <div class="report-detail-label">Debrief Notes:</div>
                                            <div style="font-size: 14px; color: var(--text-color); margin-top: 5px;">
                                                <?php echo nl2br(htmlspecialchars(substr($report['debrief_notes'], 0, 200))); ?><?php echo strlen($report['debrief_notes']) > 200 ? '...' : ''; ?>
                                            </div>
                                        </div>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php else: ?>
                                <div class="empty-state">
                                    <i class='bx bx-file'></i>
                                    <h3>No Reports Submitted Yet</h3>
                                    <p>No post-incident reports have been submitted. Complete an incident response to submit your first report.</p>
                                </div>
                            <?php endif; ?>
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
            
            // Tabs
            document.querySelectorAll('.tab').forEach(tab => {
                tab.addEventListener('click', function() {
                    const tabId = this.getAttribute('data-tab');
                    
                    // Update tabs
                    document.querySelectorAll('.tab').forEach(t => t.classList.remove('active'));
                    this.classList.add('active');
                    
                    // Update content
                    document.querySelectorAll('.tab-content').forEach(content => {
                        content.classList.remove('active');
                    });
                    document.getElementById(tabId).classList.add('active');
                });
            });
            
            // Notification close
            const notificationClose = document.getElementById('notification-close');
            if (notificationClose) {
                notificationClose.addEventListener('click', function() {
                    document.getElementById('notification').classList.remove('show');
                });
            }
            
            // Incident selection
            document.querySelectorAll('.incident-card').forEach(card => {
                card.addEventListener('click', function() {
                    // Remove selection from all cards
                    document.querySelectorAll('.incident-card').forEach(c => {
                        c.classList.remove('selected');
                    });
                    
                    // Select this card
                    this.classList.add('selected');
                    
                    // Update hidden input
                    const incidentId = this.getAttribute('data-incident-id');
                    document.getElementById('selected-incident').value = incidentId;
                    
                    // Enable form submission
                    document.querySelector('button[type="submit"]').disabled = false;
                });
            });
            
            // File upload
            const fileInput = document.getElementById('field-reports');
            const fileUploadContainer = document.getElementById('file-upload-container');
            const filePreview = document.getElementById('file-preview');
            
            // Drag and drop
            ['dragenter', 'dragover', 'dragleave', 'drop'].forEach(eventName => {
                fileUploadContainer.addEventListener(eventName, preventDefaults, false);
            });
            
            function preventDefaults(e) {
                e.preventDefault();
                e.stopPropagation();
            }
            
            ['dragenter', 'dragover'].forEach(eventName => {
                fileUploadContainer.addEventListener(eventName, highlight, false);
            });
            
            ['dragleave', 'drop'].forEach(eventName => {
                fileUploadContainer.addEventListener(eventName, unhighlight, false);
            });
            
            function highlight() {
                fileUploadContainer.classList.add('dragover');
            }
            
            function unhighlight() {
                fileUploadContainer.classList.remove('dragover');
            }
            
            fileUploadContainer.addEventListener('drop', handleDrop, false);
            
            function handleDrop(e) {
                const dt = e.dataTransfer;
                const files = dt.files;
                handleFiles(files);
            }
            
            fileInput.addEventListener('change', function() {
                handleFiles(this.files);
            });
            
            // Equipment selection
            document.querySelectorAll('.equipment-item').forEach(item => {
                item.addEventListener('click', function(e) {
                    if (e.target.classList.contains('quantity-input')) return;
                    
                    this.classList.toggle('selected');
                    const quantityInput = this.querySelector('.quantity-input');
                    
                    if (this.classList.contains('selected')) {
                        quantityInput.style.display = 'block';
                    } else {
                        quantityInput.style.display = 'none';
                        quantityInput.value = '1';
                    }
                    
                    updateEquipmentUsed();
                });
            });
            
            // Quantity input change
            document.addEventListener('change', function(e) {
                if (e.target.classList.contains('quantity-input')) {
                    updateEquipmentUsed();
                }
            });
            
            // Form submission
            document.getElementById('report-form').addEventListener('submit', function(e) {
                const incidentId = document.getElementById('selected-incident').value;
                const debriefNotes = document.getElementById('debrief-notes').value;
                
                if (!incidentId) {
                    e.preventDefault();
                    alert('Please select an incident to report on.');
                    return;
                }
                
                if (!debriefNotes.trim()) {
                    e.preventDefault();
                    alert('Please provide debrief notes.');
                    return;
                }
                
                // Update equipment used JSON
                updateEquipmentUsed();
            });
        }
        
        function handleFiles(files) {
            const filePreview = document.getElementById('file-preview');
            let previewHTML = '';
            
            for (let i = 0; i < files.length; i++) {
                const file = files[i];
                
                // Check file size (max 5MB)
                if (file.size > 5 * 1024 * 1024) {
                    alert(`File "${file.name}" is too large. Maximum size is 5MB.`);
                    continue;
                }
                
                const fileExt = file.name.split('.').pop().toLowerCase();
                const allowedTypes = ['jpg', 'jpeg', 'png', 'gif', 'pdf', 'doc', 'docx', 'mp4', 'avi'];
                
                if (!allowedTypes.includes(fileExt)) {
                    alert(`File "${file.name}" has an unsupported format.`);
                    continue;
                }
                
                const icon = getFileIcon(fileExt);
                previewHTML += `
                    <div class="file-preview-item">
                        <i class='bx ${icon} file-icon'></i>
                        <span class="file-name">${file.name}</span>
                        <button type="button" class="file-remove" onclick="removeFile(${i})">&times;</button>
                    </div>
                `;
            }
            
            if (previewHTML) {
                filePreview.innerHTML = previewHTML;
                filePreview.style.display = 'block';
            }
        }
        
        function getFileIcon(ext) {
            switch(ext) {
                case 'jpg':
                case 'jpeg':
                case 'png':
                case 'gif':
                    return 'bx-image';
                case 'pdf':
                    return 'bx-file-pdf';
                case 'doc':
                case 'docx':
                    return 'bx-file-doc';
                case 'mp4':
                case 'avi':
                    return 'bx-video';
                default:
                    return 'bx-file';
            }
        }
        
        function removeFile(index) {
            // This would require more complex file management
            // For now, we'll just remove the preview item
            const filePreview = document.getElementById('file-preview');
            const items = filePreview.querySelectorAll('.file-preview-item');
            if (items[index]) {
                items[index].remove();
            }
            
            // If no files left, hide preview
            if (filePreview.children.length === 0) {
                filePreview.style.display = 'none';
            }
        }
        
        function updateEquipmentUsed() {
            const equipmentItems = document.querySelectorAll('.equipment-item.selected');
            const equipmentData = [];
            
            equipmentItems.forEach(item => {
                const equipmentId = item.getAttribute('data-equipment-id');
                const equipmentName = item.querySelector('.equipment-name').textContent;
                const quantityInput = item.querySelector('.quantity-input');
                const quantity = parseInt(quantityInput.value) || 1;
                
                equipmentData.push({
                    id: equipmentId,
                    name: equipmentName,
                    quantity: quantity
                });
            });
            
            document.getElementById('equipment-used-json').value = JSON.stringify(equipmentData);
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