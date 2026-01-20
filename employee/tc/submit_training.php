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

// Function to get completed trainings with participants
function getCompletedTrainingsWithParticipants($pdo, $search = null, $volunteer_name = null) {
    $sql = "SELECT DISTINCT t.* 
            FROM trainings t
            INNER JOIN training_registrations tr ON t.id = tr.training_id
            INNER JOIN volunteers v ON tr.volunteer_id = v.id
            WHERE t.status = 'completed' 
            AND tr.completion_status = 'completed'
            AND tr.employee_submitted = 0";
    
    $params = [];
    
    if ($search) {
        $sql .= " AND (t.title LIKE ? OR t.description LIKE ? OR t.instructor LIKE ?)";
        $search_param = "%$search%";
        $params[] = $search_param;
        $params[] = $search_param;
        $params[] = $search_param;
    }
    
    if ($volunteer_name) {
        $sql .= " AND (v.first_name LIKE ? OR v.last_name LIKE ? OR CONCAT(v.first_name, ' ', v.last_name) LIKE ?)";
        $name_param = "%$volunteer_name%";
        $params[] = $name_param;
        $params[] = $name_param;
        $params[] = $name_param;
    }
    
    $sql .= " ORDER BY t.training_date DESC";
    
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Error fetching completed trainings: " . $e->getMessage());
        return [];
    }
}

// Function to get volunteers in completed training
function getVolunteersInTraining($pdo, $training_id) {
    $sql = "SELECT tr.*, v.id as volunteer_id, v.first_name, v.last_name, 
                   v.contact_number, v.email, v.specialized_training,
                   u.username as user_username,
                   tr.completion_date, tr.notes as completion_notes
            FROM training_registrations tr
            INNER JOIN volunteers v ON tr.volunteer_id = v.id
            LEFT JOIN users u ON tr.user_id = u.id
            WHERE tr.training_id = ? 
            AND tr.completion_status = 'completed'
            ORDER BY v.last_name, v.first_name";
    
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$training_id]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Error fetching training volunteers: " . $e->getMessage());
        return [];
    }
}

// Function to submit training completion proof
function submitTrainingProof($pdo, $registration_id, $employee_id, $notes, $proof_filename = null) {
    try {
        $sql = "UPDATE training_registrations 
                SET completion_verified = 1,
                    completion_verified_by = ?,
                    completion_verified_at = NOW(),
                    completion_proof = ?,
                    completion_notes = CONCAT(IFNULL(completion_notes, ''), '\nEmployee Verification: ', ?)
                WHERE id = ?";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$employee_id, $proof_filename, $notes, $registration_id]);
        
        return $stmt->rowCount() > 0;
    } catch (PDOException $e) {
        error_log("Error submitting training proof: " . $e->getMessage());
        return false;
    }
}

// Function to submit all verified trainings to admin
function submitVerifiedTrainingsToAdmin($pdo, $training_id, $employee_id) {
    try {
        $pdo->beginTransaction();
        
        // Get all verified registrations for this training
        $sql = "SELECT tr.*, v.first_name, v.last_name, v.email
                FROM training_registrations tr
                INNER JOIN volunteers v ON tr.volunteer_id = v.id
                WHERE tr.training_id = ? 
                AND tr.completion_status = 'completed'
                AND tr.completion_verified = 1
                AND tr.employee_submitted = 0";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$training_id]);
        $completions = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (count($completions) === 0) {
            throw new Exception("No verified training completions to submit.");
        }
        
        // Mark as submitted by employee
        $update_sql = "UPDATE training_registrations 
                      SET employee_submitted = 1,
                          employee_submitted_by = ?,
                          employee_submitted_at = NOW()
                      WHERE training_id = ? 
                      AND completion_status = 'completed'
                      AND completion_verified = 1";
        
        $update_stmt = $pdo->prepare($update_sql);
        $update_stmt->execute([$employee_id, $training_id]);
        
        // Create notification for admin
        $training_info_sql = "SELECT title FROM trainings WHERE id = ?";
        $training_stmt = $pdo->prepare($training_info_sql);
        $training_stmt->execute([$training_id]);
        $training = $training_stmt->fetch();
        
        $notif_sql = "INSERT INTO notifications (user_id, type, title, message, created_at)
                     SELECT id, 'training_submission', 'Training Completion Submitted',
                            CONCAT('Employee has submitted ', ?, ' verified training completions for \"', ?, '\" for certificate approval.'),
                            NOW()
                     FROM users WHERE role = 'ADMIN' LIMIT 1";
        
        $notif_stmt = $pdo->prepare($notif_sql);
        $notif_stmt->execute([count($completions), $training['title']]);
        
        $pdo->commit();
        return ['success' => true, 'count' => count($completions)];
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log("Error submitting training to admin: " . $e->getMessage());
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

// Handle file upload for proof
function uploadProofFile($file) {
    $upload_dir = '../../uploads/training_proofs/';
    
    // Create directory if it doesn't exist
    if (!file_exists($upload_dir)) {
        mkdir($upload_dir, 0777, true);
    }
    
    $allowed_types = ['image/jpeg', 'image/png', 'image/gif', 'application/pdf'];
    $max_size = 5 * 1024 * 1024; // 5MB
    
    if ($file['error'] !== UPLOAD_ERR_OK) {
        return ['success' => false, 'error' => 'File upload error'];
    }
    
    if (!in_array($file['type'], $allowed_types)) {
        return ['success' => false, 'error' => 'Only JPG, PNG, GIF, and PDF files are allowed'];
    }
    
    if ($file['size'] > $max_size) {
        return ['success' => false, 'error' => 'File size must be less than 5MB'];
    }
    
    $file_extension = pathinfo($file['name'], PATHINFO_EXTENSION);
    $filename = 'proof_' . time() . '_' . uniqid() . '.' . $file_extension;
    $filepath = $upload_dir . $filename;
    
    if (move_uploaded_file($file['tmp_name'], $filepath)) {
        return ['success' => true, 'filename' => $filename];
    }
    
    return ['success' => false, 'error' => 'Failed to upload file'];
}

// Handle actions
$success_message = '';
$error_message = '';

// Handle individual verification
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['verify_completion'])) {
    $registration_id = $_POST['registration_id'];
    $notes = $_POST['verification_notes'] ?? '';
    
    $proof_filename = null;
    if (isset($_FILES['proof_file']) && $_FILES['proof_file']['error'] === UPLOAD_ERR_OK) {
        $upload_result = uploadProofFile($_FILES['proof_file']);
        if ($upload_result['success']) {
            $proof_filename = $upload_result['filename'];
        } else {
            $error_message = $upload_result['error'];
        }
    }
    
    if (empty($error_message)) {
        if (submitTrainingProof($pdo, $registration_id, $user_id, $notes, $proof_filename)) {
            $success_message = "Training completion verified successfully!";
        } else {
            $error_message = "Failed to verify training completion.";
        }
    }
}

// Handle bulk submission to admin
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_to_admin'])) {
    $training_id = $_POST['training_id'];
    $result = submitVerifiedTrainingsToAdmin($pdo, $training_id, $user_id);
    
    if ($result['success']) {
        $success_message = "Successfully submitted " . $result['count'] . " verified training completion(s) to admin for certificate approval!";
    } else {
        $error_message = $result['error'];
    }
}

// Get parameters
$search_term = $_GET['search'] ?? null;
$volunteer_name = $_GET['volunteer_name'] ?? null;
$training_id = $_GET['training_id'] ?? null;

// Get data
$completed_trainings = getCompletedTrainingsWithParticipants($pdo, $search_term, $volunteer_name);
$training_volunteers = $training_id ? getVolunteersInTraining($pdo, $training_id) : [];

// Get statistics
$total_completed = count($completed_trainings);
$total_awaiting_submission = 0;
$total_verified = 0;

foreach ($completed_trainings as $training) {
    $sql = "SELECT COUNT(*) as count, 
                   SUM(CASE WHEN completion_verified = 1 THEN 1 ELSE 0 END) as verified_count
            FROM training_registrations 
            WHERE training_id = ? 
            AND completion_status = 'completed'";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$training['id']]);
    $stats = $stmt->fetch();
    
    $training['completions_count'] = $stats['count'];
    $training['verified_count'] = $stats['verified_count'];
    
    $total_awaiting_submission += $stats['count'];
    $total_verified += $stats['verified_count'];
}

$stmt = null;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Submit Training Completions - Fire & Rescue Management</title>
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

        .stat-icon.completed {
            background: rgba(16, 185, 129, 0.1);
            color: var(--success);
        }

        .stat-icon.verified {
            background: rgba(59, 130, 246, 0.1);
            color: var(--info);
        }

        .stat-icon.pending {
            background: rgba(245, 158, 11, 0.1);
            color: var(--warning);
        }

        .stat-icon.total {
            background: rgba(139, 92, 246, 0.1);
            color: var(--purple);
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
            gap: 15px;
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

        .training-date {
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

        .completion-stats {
            text-align: center;
        }

        .completion-count {
            font-size: 20px;
            font-weight: 700;
            color: var(--text-color);
        }

        .completion-label {
            font-size: 12px;
            color: var(--text-light);
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

        .status-completed {
            background: rgba(16, 185, 129, 0.1);
            color: var(--success);
            border: 1px solid rgba(16, 185, 129, 0.2);
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
            max-width: 900px;
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

        .volunteers-list {
            display: grid;
            gap: 15px;
        }

        .volunteer-item {
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: 10px;
            padding: 20px;
        }

        .volunteer-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
        }

        .volunteer-info {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .volunteer-avatar {
            width: 50px;
            height: 50px;
            border-radius: 50%;
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            font-size: 18px;
            flex-shrink: 0;
        }

        .volunteer-details {
            display: flex;
            flex-direction: column;
        }

        .volunteer-name {
            font-weight: 700;
            color: var(--text-color);
            font-size: 16px;
        }

        .volunteer-contact {
            font-size: 13px;
            color: var(--text-light);
            margin-top: 2px;
        }

        .volunteer-status {
            display: flex;
            gap: 8px;
        }

        .verification-badge {
            display: inline-block;
            padding: 6px 12px;
            border-radius: 15px;
            font-size: 12px;
            font-weight: 600;
        }

        .badge-verified {
            background: rgba(16, 185, 129, 0.1);
            color: var(--success);
        }

        .badge-pending {
            background: rgba(245, 158, 11, 0.1);
            color: var(--warning);
        }

        .verification-form {
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: 10px;
            padding: 20px;
            margin-top: 15px;
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: var(--text-color);
            font-size: 14px;
        }

        .form-label.required:after {
            content: " *";
            color: var(--danger);
        }

        .form-input, .form-textarea {
            width: 100%;
            padding: 12px 16px;
            border-radius: 10px;
            border: 1px solid var(--border-color);
            background: var(--card-bg);
            color: var(--text-color);
            font-size: 14px;
            transition: all 0.3s ease;
        }

        .form-input:focus, .form-textarea:focus {
            outline: none;
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(220, 38, 38, 0.1);
        }

        .form-textarea {
            resize: vertical;
            min-height: 100px;
        }

        .file-upload {
            position: relative;
            display: inline-block;
            width: 100%;
        }

        .file-upload-input {
            position: absolute;
            left: 0;
            top: 0;
            opacity: 0;
            width: 100%;
            height: 100%;
            cursor: pointer;
        }

        .file-upload-label {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 12px 16px;
            border: 2px dashed var(--border-color);
            border-radius: 10px;
            background: var(--card-bg);
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .file-upload-label:hover {
            border-color: var(--primary-color);
            background: rgba(220, 38, 38, 0.02);
        }

        .file-info {
            display: flex;
            align-items: center;
            gap: 10px;
            color: var(--text-light);
        }

        .file-name {
            max-width: 200px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .file-hint {
            font-size: 12px;
            color: var(--text-light);
            margin-top: 8px;
        }

        /* Verification Modal */
        .proof-preview {
            max-width: 300px;
            max-height: 200px;
            margin-top: 10px;
            border-radius: 8px;
            border: 1px solid var(--border-color);
        }

        /* Submit Confirmation Modal */
        .submit-summary {
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 20px;
        }

        .summary-item {
            display: flex;
            justify-content: space-between;
            margin-bottom: 10px;
        }

        .summary-label {
            color: var(--text-light);
        }

        .summary-value {
            font-weight: 600;
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
            
            .volunteer-header {
                flex-direction: column;
                align-items: flex-start;
                gap: 10px;
            }
            
            .volunteer-status {
                align-self: flex-start;
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
            
            .volunteer-info {
                flex-direction: column;
                align-items: flex-start;
                gap: 8px;
            }
        }

        @media (max-width: 576px) {
            .modal {
                width: 95%;
                max-width: 95%;
            }
            
            .btn {
                justify-content: center;
            }
            
            .modal-actions {
                flex-direction: column;
            }
            
            .modal-actions .btn {
                width: 100%;
            }
        }
    </style>
</head>
<body>
    <!-- Volunteers Modal -->
    <div class="modal-overlay" id="volunteers-modal">
        <div class="modal">
            <div class="modal-header">
                <h2 class="modal-title" id="modal-title">Volunteers in Training</h2>
                <button class="modal-close" id="modal-close">&times;</button>
            </div>
            <div class="modal-body">
                <div id="modal-volunteers-content">
                    <!-- Volunteers list will be loaded here -->
                </div>
            </div>
            <div class="modal-actions">
                <button type="button" class="btn btn-secondary" id="close-volunteers-modal">Close</button>
            </div>
        </div>
    </div>
    
    <!-- Verification Modal -->
    <div class="modal-overlay" id="verification-modal">
        <div class="modal">
            <div class="modal-header">
                <h2 class="modal-title" id="verification-title">Verify Training Completion</h2>
                <button class="modal-close" id="verification-modal-close">&times;</button>
            </div>
            <form method="POST" id="verification-form" enctype="multipart/form-data">
                <div class="modal-body">
                    <input type="hidden" name="registration_id" id="verification-registration-id">
                    
                    <div class="form-group">
                        <div class="form-label">Volunteer Information</div>
                        <div id="volunteer-info-display" style="padding: 15px; background: rgba(220, 38, 38, 0.05); border-radius: 10px;">
                            Loading volunteer information...
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label required">Verification Notes</label>
                        <textarea class="form-textarea" name="verification_notes" required 
                                  placeholder="Add notes about the verification (e.g., proof reviewed, completion confirmed, any issues...)" 
                                  id="verification-notes"></textarea>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Proof Upload (Optional)</label>
                        <div class="file-upload">
                            <input type="file" class="file-upload-input" id="proof-file" name="proof_file" 
                                   accept="image/*,.pdf" onchange="updateFileName(this)">
                            <label class="file-upload-label" for="proof-file">
                                <div class="file-info">
                                    <i class='bx bx-upload'></i>
                                    <span class="file-name" id="file-name">Choose file...</span>
                                </div>
                                <span>Browse</span>
                            </label>
                        </div>
                        <div class="file-hint">
                            Upload proof of completion (e.g., training photo, certificate, attendance sheet). Max size: 5MB. Allowed: JPG, PNG, GIF, PDF
                        </div>
                        <div id="proof-preview-container" style="display: none; margin-top: 10px;">
                            <img id="proof-preview" class="proof-preview" alt="Proof Preview">
                        </div>
                    </div>
                </div>
                <div class="modal-actions">
                    <button type="button" class="btn btn-secondary" id="close-verification-modal">Cancel</button>
                    <button type="submit" name="verify_completion" class="btn btn-success">
                        <i class='bx bx-check'></i>
                        Verify Completion
                    </button>
                </div>
            </form>
        </div>
    </div>
    
    <!-- Submit Confirmation Modal -->
    <div class="modal-overlay" id="submit-modal">
        <div class="modal">
            <div class="modal-header">
                <h2 class="modal-title">Submit to Admin for Certificate Approval</h2>
                <button class="modal-close" id="submit-modal-close">&times;</button>
            </div>
            <form method="POST" id="submit-form">
                <div class="modal-body">
                    <input type="hidden" name="training_id" id="submit-training-id">
                    
                    <div class="submit-summary">
                        <div class="summary-item">
                            <span class="summary-label">Training:</span>
                            <span class="summary-value" id="submit-training-title">Loading...</span>
                        </div>
                        <div class="summary-item">
                            <span class="summary-label">Total Completions:</span>
                            <span class="summary-value" id="submit-total-count">0</span>
                        </div>
                        <div class="summary-item">
                            <span class="summary-label">Verified Completions:</span>
                            <span class="summary-value" id="submit-verified-count">0</span>
                        </div>
                        <div class="summary-item">
                            <span class="summary-label">To be Submitted:</span>
                            <span class="summary-value" id="submit-submit-count">0</span>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <div style="background: rgba(220, 38, 38, 0.05); padding: 15px; border-radius: 10px; border-left: 4px solid var(--primary-color);">
                            <strong>Important Notice</strong>
                            <p style="margin-top: 5px; font-size: 13px; color: var(--text-light);">
                                This will submit all verified training completions to the admin for certificate approval. 
                                Once submitted, the admin will review and issue certificates. You cannot make changes after submission.
                                <br><br>
                                <strong>Only verified completions will be submitted.</strong>
                            </p>
                        </div>
                    </div>
                </div>
                <div class="modal-actions">
                    <button type="button" class="btn btn-secondary" id="close-submit-modal">Cancel</button>
                    <button type="submit" name="submit_to_admin" class="btn btn-success">
                        <i class='bx bx-upload'></i>
                        Submit to Admin
                    </button>
                </div>
            </form>
        </div>
    </div>
    
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
                    <div id="training" class="submenu active">
                        <a href="view_available_training.php" class="submenu-item">View Available Training</a>
                        <a href="submit_training.php" class="submenu-item active">Submit Training</a>
                        
                        <a href="view_events.php" class="submenu-item">View Events</a>
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
                            <input type="text" placeholder="Search trainings or volunteers..." class="search-input" id="search-input">
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
                        <h1 class="dashboard-title">Submit Training Completions</h1>
                        <p class="dashboard-subtitle">Verify and submit completed training for certificate approval</p>
                    </div>
                </div>
                
                <!-- Content Container -->
                <div class="content-container">
                    <!-- Statistics Cards -->
                    <div class="stats-container">
                        <div class="stat-card">
                            <div class="stat-icon total">
                                <i class='bx bxs-graduation'></i>
                            </div>
                            <div class="stat-value"><?php echo $total_completed; ?></div>
                            <div class="stat-label">Completed Trainings</div>
                        </div>
                        
                        <div class="stat-card">
                            <div class="stat-icon completed">
                                <i class='bx bx-user-check'></i>
                            </div>
                            <div class="stat-value"><?php echo $total_awaiting_submission; ?></div>
                            <div class="stat-label">Awaiting Submission</div>
                        </div>
                        
                        <div class="stat-card">
                            <div class="stat-icon verified">
                                <i class='bx bx-check-shield'></i>
                            </div>
                            <div class="stat-value"><?php echo $total_verified; ?></div>
                            <div class="stat-label">Verified</div>
                        </div>
                        
                        <div class="stat-card">
                            <div class="stat-icon pending">
                                <i class='bx bx-time'></i>
                            </div>
                            <div class="stat-value"><?php echo max(0, $total_awaiting_submission - $total_verified); ?></div>
                            <div class="stat-label">Pending Verification</div>
                        </div>
                    </div>
                    
                    <!-- Filters -->
                    <div class="filters-container">
                        <h3 class="filters-title">
                            <i class='bx bx-filter-alt'></i>
                            Search Trainings
                        </h3>
                        
                        <form method="GET" id="filters-form">
                            <div class="filters-grid">
                                <div class="filter-group">
                                    <label class="filter-label" for="search">Search Trainings</label>
                                    <input type="text" class="filter-input" id="search" name="search" 
                                           value="<?php echo htmlspecialchars($search_term ?? ''); ?>" 
                                           placeholder="Search by training title, instructor...">
                                </div>
                                
                                <div class="filter-group">
                                    <label class="filter-label" for="volunteer_name">Search Volunteers</label>
                                    <input type="text" class="filter-input" id="volunteer_name" name="volunteer_name" 
                                           value="<?php echo htmlspecialchars($volunteer_name ?? ''); ?>" 
                                           placeholder="Search volunteer name...">
                                </div>
                                
                                <div class="filter-group">
                                    <label class="filter-label">&nbsp;</label>
                                    <div style="display: flex; gap: 10px;">
                                        <button type="submit" class="btn btn-primary">
                                            <i class='bx bx-search'></i>
                                            Search
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
                    
                 
                    
                    <!-- Completed Trainings Table -->
                    <div class="table-container">
                        <?php if (count($completed_trainings) > 0): ?>
                            <div class="table-header">
                                <h3 class="table-title">
                                    <i class='bx bx-list-check'></i>
                                    Completed Trainings Ready for Submission
                                </h3>
                            </div>
                            
                            <table class="table">
                                <thead>
                                    <tr>
                                        <th>Training Details</th>
                                        <th>Date & Duration</th>
                                        <th>Completion Statistics</th>
                                        <th>Status</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($completed_trainings as $training): 
                                        $start_date = date('M j, Y', strtotime($training['training_date']));
                                        $end_date = $training['training_end_date'] ? date('M j, Y', strtotime($training['training_end_date'])) : null;
                                        $duration = $training['duration_hours'] ? number_format($training['duration_hours'], 1) . ' hours' : 'N/A';
                                        
                                        $completions_count = $training['completions_count'] ?? 0;
                                        $verified_count = $training['verified_count'] ?? 0;
                                        $pending_count = max(0, $completions_count - $verified_count);
                                        
                                        $can_submit = $verified_count > 0;
                                        $submit_count = $verified_count; // Only verified can be submitted
                                    ?>
                                    <tr>
                                        <td>
                                            <div class="training-info">
                                                <div class="training-title">
                                                    <?php echo htmlspecialchars($training['title']); ?>
                                                </div>
                                                <div class="training-description">
                                                    <?php echo htmlspecialchars(substr($training['description'], 0, 100)); ?>...
                                                </div>
                                                <div class="training-details">
                                                    <?php if ($training['instructor']): ?>
                                                    <div class="detail-item">
                                                        <i class='bx bx-user'></i>
                                                        <span>Instructor: <?php echo htmlspecialchars($training['instructor']); ?></span>
                                                    </div>
                                                    <?php endif; ?>
                                                    
                                                    <?php if ($training['location']): ?>
                                                    <div class="detail-item">
                                                        <i class='bx bx-map'></i>
                                                        <span><?php echo htmlspecialchars($training['location']); ?></span>
                                                    </div>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        </td>
                                        <td>
                                            <div class="training-date">
                                                <div class="date-label">Date</div>
                                                <div class="date-value"><?php echo $start_date; ?></div>
                                                
                                                <?php if ($end_date): ?>
                                                <div class="date-label" style="margin-top: 8px;">End Date</div>
                                                <div class="date-value"><?php echo $end_date; ?></div>
                                                <?php endif; ?>
                                                
                                                <div class="date-label" style="margin-top: 8px;">Duration</div>
                                                <div class="date-value"><?php echo $duration; ?></div>
                                            </div>
                                        </td>
                                        <td>
                                            <div class="completion-stats">
                                                <div class="completion-count">
                                                    <?php echo $completions_count; ?>
                                                </div>
                                                <div class="completion-label">
                                                    Total Completions
                                                </div>
                                                
                                                <div style="display: flex; gap: 10px; margin-top: 10px; justify-content: center;">
                                                    <div style="text-align: center;">
                                                        <div style="font-size: 18px; font-weight: 700; color: var(--success);">
                                                            <?php echo $verified_count; ?>
                                                        </div>
                                                        <div style="font-size: 11px; color: var(--text-light);">Verified</div>
                                                    </div>
                                                    
                                                    <div style="text-align: center;">
                                                        <div style="font-size: 18px; font-weight: 700; color: var(--warning);">
                                                            <?php echo $pending_count; ?>
                                                        </div>
                                                        <div style="font-size: 11px; color: var(--text-light);">Pending</div>
                                                    </div>
                                                </div>
                                            </div>
                                        </td>
                                        <td>
                                            <div class="status-badge status-completed">
                                                Completed
                                            </div>
                                        </td>
                                        <td>
                                            <div class="action-buttons">
                                                <button type="button" class="btn btn-info btn-sm view-volunteers-btn"
                                                        data-training-id="<?php echo $training['id']; ?>"
                                                        data-training-title="<?php echo htmlspecialchars($training['title']); ?>">
                                                    <i class='bx bx-group'></i>
                                                    View Volunteers
                                                </button>
                                                
                                                <?php if ($can_submit): ?>
                                                <button type="button" class="btn btn-success btn-sm submit-training-btn"
                                                        data-training-id="<?php echo $training['id']; ?>"
                                                        data-training-title="<?php echo htmlspecialchars($training['title']); ?>"
                                                        data-total-count="<?php echo $completions_count; ?>"
                                                        data-verified-count="<?php echo $verified_count; ?>"
                                                        data-submit-count="<?php echo $submit_count; ?>">
                                                    <i class='bx bx-upload'></i>
                                                    Submit to Admin
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
                                <h3>No Completed Trainings Found</h3>
                                <p>No training modules with completed volunteers are currently available. Check back when volunteers complete their training.</p>
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
            
            // Setup modals
            setupVolunteersModal();
            setupVerificationModal();
            setupSubmitModal();
            
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
        }
        
        function setupVolunteersModal() {
            const volunteersModal = document.getElementById('volunteers-modal');
            const modalClose = document.getElementById('modal-close');
            const closeModal = document.getElementById('close-volunteers-modal');
            const viewButtons = document.querySelectorAll('.view-volunteers-btn');
            
            modalClose.addEventListener('click', () => volunteersModal.classList.remove('active'));
            closeModal.addEventListener('click', () => volunteersModal.classList.remove('active'));
            
            volunteersModal.addEventListener('click', function(e) {
                if (e.target === this) {
                    volunteersModal.classList.remove('active');
                }
            });
            
            viewButtons.forEach(button => {
                button.addEventListener('click', function() {
                    const trainingId = this.getAttribute('data-training-id');
                    const trainingTitle = this.getAttribute('data-training-title');
                    
                    document.getElementById('modal-title').textContent = 'Volunteers - ' + trainingTitle;
                    
                    // Load volunteers via AJAX
                    fetchVolunteers(trainingId);
                    
                    volunteersModal.classList.add('active');
                });
            });
        }
        
        function setupVerificationModal() {
            const verificationModal = document.getElementById('verification-modal');
            const modalClose = document.getElementById('verification-modal-close');
            const closeModal = document.getElementById('close-verification-modal');
            
            modalClose.addEventListener('click', () => verificationModal.classList.remove('active'));
            closeModal.addEventListener('click', () => verificationModal.classList.remove('active'));
            
            verificationModal.addEventListener('click', function(e) {
                if (e.target === this) {
                    verificationModal.classList.remove('active');
                }
            });
            
            // Handle file preview
            const proofFileInput = document.getElementById('proof-file');
            if (proofFileInput) {
                proofFileInput.addEventListener('change', function(e) {
                    const file = e.target.files[0];
                    const previewContainer = document.getElementById('proof-preview-container');
                    const preview = document.getElementById('proof-preview');
                    
                    if (file && file.type.startsWith('image/')) {
                        const reader = new FileReader();
                        reader.onload = function(e) {
                            preview.src = e.target.result;
                            previewContainer.style.display = 'block';
                        }
                        reader.readAsDataURL(file);
                    } else {
                        previewContainer.style.display = 'none';
                    }
                });
            }
        }
        
        function setupSubmitModal() {
            const submitModal = document.getElementById('submit-modal');
            const modalClose = document.getElementById('submit-modal-close');
            const closeModal = document.getElementById('close-submit-modal');
            const submitButtons = document.querySelectorAll('.submit-training-btn');
            
            modalClose.addEventListener('click', () => submitModal.classList.remove('active'));
            closeModal.addEventListener('click', () => submitModal.classList.remove('active'));
            
            submitModal.addEventListener('click', function(e) {
                if (e.target === this) {
                    submitModal.classList.remove('active');
                }
            });
            
            submitButtons.forEach(button => {
                button.addEventListener('click', function() {
                    const trainingId = this.getAttribute('data-training-id');
                    const trainingTitle = this.getAttribute('data-training-title');
                    const totalCount = this.getAttribute('data-total-count');
                    const verifiedCount = this.getAttribute('data-verified-count');
                    const submitCount = this.getAttribute('data-submit-count');
                    
                    document.getElementById('submit-training-id').value = trainingId;
                    document.getElementById('submit-training-title').textContent = trainingTitle;
                    document.getElementById('submit-total-count').textContent = totalCount;
                    document.getElementById('submit-verified-count').textContent = verifiedCount;
                    document.getElementById('submit-submit-count').textContent = submitCount;
                    
                    submitModal.classList.add('active');
                });
            });
        }
        
        function fetchVolunteers(trainingId) {
            const contentDiv = document.getElementById('modal-volunteers-content');
            contentDiv.innerHTML = '<div style="text-align: center; padding: 40px;"><i class="bx bx-loader-alt bx-spin" style="font-size: 40px; color: var(--text-light);"></i><p>Loading volunteers...</p></div>';
            
            fetch('get_training_volunteers.php?training_id=' + trainingId)
                .then(response => response.text())
                .then(html => {
                    contentDiv.innerHTML = html;
                    // Add event listeners to verification buttons
                    attachVerificationListeners();
                })
                .catch(error => {
                    contentDiv.innerHTML = '<div style="text-align: center; padding: 40px; color: var(--danger);"><i class="bx bx-error"></i><p>Error loading volunteers. Please try again.</p></div>';
                    console.error('Error:', error);
                });
        }
        
        function attachVerificationListeners() {
            const verifyButtons = document.querySelectorAll('.verify-volunteer-btn');
            verifyButtons.forEach(button => {
                button.addEventListener('click', function() {
                    const registrationId = this.getAttribute('data-registration-id');
                    const volunteerName = this.getAttribute('data-volunteer-name');
                    const trainingTitle = this.getAttribute('data-training-title');
                    
                    openVerificationModal(registrationId, volunteerName, trainingTitle);
                });
            });
        }
        
        function openVerificationModal(registrationId, volunteerName, trainingTitle) {
            const verificationModal = document.getElementById('verification-modal');
            const title = document.getElementById('verification-title');
            const registrationInput = document.getElementById('verification-registration-id');
            const volunteerInfo = document.getElementById('volunteer-info-display');
            const notes = document.getElementById('verification-notes');
            
            title.textContent = 'Verify Completion - ' + volunteerName;
            registrationInput.value = registrationId;
            volunteerInfo.innerHTML = `
                <div style="display: flex; align-items: center; gap: 15px; margin-bottom: 10px;">
                    <div style="width: 40px; height: 40px; border-radius: 50%; background: linear-gradient(135deg, var(--primary-color), var(--secondary-color)); color: white; display: flex; align-items: center; justify-content: center; font-weight: bold;">
                        ${volunteerName.charAt(0).toUpperCase()}
                    </div>
                    <div>
                        <div style="font-weight: 700;">${volunteerName}</div>
                        <div style="font-size: 13px; color: var(--text-light);">Training: ${trainingTitle}</div>
                    </div>
                </div>
            `;
            notes.value = '';
            
            // Reset file input
            const fileInput = document.getElementById('proof-file');
            if (fileInput) {
                fileInput.value = '';
                document.getElementById('file-name').textContent = 'Choose file...';
                document.getElementById('proof-preview-container').style.display = 'none';
            }
            
            verificationModal.classList.add('active');
        }
        
        function updateFileName(input) {
            const fileName = input.files[0] ? input.files[0].name : 'Choose file...';
            document.getElementById('file-name').textContent = fileName;
        }
        
        function clearFilters() {
            window.location.href = 'submit_training.php';
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