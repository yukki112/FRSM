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

// Function to fetch trainings from API
function fetchTrainingsFromAPI($pdo) {
    $api_url = "https://frsm.qcprotektado.com/api.php";
    
    try {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $api_url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        
        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($http_code === 200 && $response) {
            $trainings = json_decode($response, true);
            
            if (is_array($trainings)) {
                // Clear existing trainings (optional, or use upsert)
                // For now, we'll do upsert based on external_id
                
                foreach ($trainings as $training) {
                    // Check if training exists by external_id or title
                    $check_sql = "SELECT id FROM trainings WHERE external_id = ? OR (title = ? AND training_date = ?)";
                    $check_stmt = $pdo->prepare($check_sql);
                    $check_stmt->execute([
                        $training['id'] ?? null,
                        $training['title'],
                        $training['training_date']
                    ]);
                    $existing = $check_stmt->fetch();
                    
                    if ($existing) {
                        // Update existing training
                        $update_sql = "UPDATE trainings SET 
                            title = ?,
                            description = ?,
                            training_date = ?,
                            training_end_date = ?,
                            duration_hours = ?,
                            instructor = ?,
                            location = ?,
                            max_participants = ?,
                            current_participants = ?,
                            status = ?,
                            updated_at = NOW(),
                            last_sync_at = NOW()
                            WHERE id = ?";
                        
                        $update_stmt = $pdo->prepare($update_sql);
                        $update_stmt->execute([
                            $training['title'],
                            $training['description'],
                            $training['training_date'],
                            $training['training_end_date'] ?? null,
                            $training['duration_hours'] ?? 0,
                            $training['instructor'] ?? null,
                            $training['location'] ?? null,
                            $training['max_participants'] ?? 0,
                            $training['current_participants'] ?? 0,
                            $training['status'] ?? 'scheduled',
                            $existing['id']
                        ]);
                    } else {
                        // Insert new training
                        $insert_sql = "INSERT INTO trainings (
                            external_id, title, description, training_date, training_end_date,
                            duration_hours, instructor, location, max_participants,
                            current_participants, status, last_sync_at
                        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())";
                        
                        $insert_stmt = $pdo->prepare($insert_sql);
                        $insert_stmt->execute([
                            $training['id'] ?? null,
                            $training['title'],
                            $training['description'],
                            $training['training_date'],
                            $training['training_end_date'] ?? null,
                            $training['duration_hours'] ?? 0,
                            $training['instructor'] ?? null,
                            $training['location'] ?? null,
                            $training['max_participants'] ?? 0,
                            $training['current_participants'] ?? 0,
                            $training['status'] ?? 'scheduled'
                        ]);
                    }
                }
                
                return true;
            }
        }
    } catch (Exception $e) {
        error_log("Error fetching trainings from API: " . $e->getMessage());
    }
    
    return false;
}

// Function to get all trainings
function getTrainings($pdo, $status = null, $search = null) {
    $sql = "SELECT t.*, 
            (SELECT COUNT(*) FROM training_registrations tr WHERE tr.training_id = t.id) as registered_count,
            (SELECT COUNT(*) FROM training_registrations tr WHERE tr.training_id = t.id AND tr.status = 'completed') as completed_count
            FROM trainings t WHERE 1=1";
    
    $params = [];
    
    if ($status) {
        $sql .= " AND t.status = ?";
        $params[] = $status;
    }
    
    if ($search) {
        $sql .= " AND (t.title LIKE ? OR t.description LIKE ? OR t.instructor LIKE ? OR t.location LIKE ?)";
        $search_param = "%$search%";
        $params[] = $search_param;
        $params[] = $search_param;
        $params[] = $search_param;
        $params[] = $search_param;
    }
    
    $sql .= " ORDER BY t.training_date DESC, t.created_at DESC";
    
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Error fetching trainings: " . $e->getMessage());
        return [];
    }
}

// Function to get training participants
function getTrainingParticipants($pdo, $training_id) {
    $sql = "SELECT tr.*, v.first_name, v.last_name, v.contact_number, v.email,
            u.username as user_username
            FROM training_registrations tr
            INNER JOIN volunteers v ON tr.volunteer_id = v.id
            LEFT JOIN users u ON tr.user_id = u.id
            WHERE tr.training_id = ?
            ORDER BY tr.registration_date DESC";
    
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$training_id]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Error fetching training participants: " . $e->getMessage());
        return [];
    }
}

// Function to get volunteer's registered trainings
function getVolunteerTrainings($pdo, $volunteer_id) {
    $sql = "SELECT tr.*, t.title, t.training_date, t.training_end_date, t.location,
            t.status as training_status
            FROM training_registrations tr
            INNER JOIN trainings t ON tr.training_id = t.id
            WHERE tr.volunteer_id = ?
            ORDER BY t.training_date DESC";
    
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$volunteer_id]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Error fetching volunteer trainings: " . $e->getMessage());
        return [];
    }
}

// Function to submit training completion to admin
function submitTrainingToAdmin($pdo, $training_id, $employee_id) {
    try {
        $pdo->beginTransaction();
        
        // Get all completed registrations for this training
        $sql = "SELECT tr.*, v.first_name, v.last_name, v.email
                FROM training_registrations tr
                INNER JOIN volunteers v ON tr.volunteer_id = v.id
                WHERE tr.training_id = ? AND tr.completion_status = 'completed' 
                AND tr.employee_submitted = 0";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$training_id]);
        $completions = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (count($completions) === 0) {
            throw new Exception("No completed trainings to submit.");
        }
        
        // Mark as submitted by employee
        $update_sql = "UPDATE training_registrations 
                      SET employee_submitted = 1,
                          employee_submitted_by = ?,
                          employee_submitted_at = NOW()
                      WHERE training_id = ? AND completion_status = 'completed'";
        
        $update_stmt = $pdo->prepare($update_sql);
        $update_stmt->execute([$employee_id, $training_id]);
        
        // Create notification for admin
        $notif_sql = "INSERT INTO notifications (user_id, type, title, message, created_at)
                     SELECT id, 'training_submission', 'Training Completion Submitted',
                            'Employee has submitted " . count($completions) . " training completions for review.',
                            NOW()
                     FROM users WHERE role = 'ADMIN' LIMIT 1";
        
        $pdo->exec($notif_sql);
        
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

// Handle actions
$success_message = '';
$error_message = '';

// Handle sync from API
if (isset($_GET['action']) && $_GET['action'] === 'sync') {
    if (fetchTrainingsFromAPI($pdo)) {
        $success_message = "Trainings synced successfully from API!";
    } else {
        $error_message = "Failed to sync trainings from API. Using cached data.";
    }
}

// Handle submit to admin
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['submit_to_admin'])) {
        $training_id = $_POST['training_id'];
        $result = submitTrainingToAdmin($pdo, $training_id, $user_id);
        
        if ($result['success']) {
            $success_message = "Successfully submitted " . $result['count'] . " training completion(s) to admin for approval!";
        } else {
            $error_message = $result['error'];
        }
    }
}

// Get parameters
$status_filter = $_GET['status'] ?? null;
$search_term = $_GET['search'] ?? null;
$training_id = $_GET['training_id'] ?? null;

// Get data
$trainings = getTrainings($pdo, $status_filter, $search_term);
$training_participants = $training_id ? getTrainingParticipants($pdo, $training_id) : [];

// Get statistics
$total_trainings = count($trainings);
$upcoming_trainings = count(array_filter($trainings, fn($t) => 
    strtotime($t['training_date']) > time() && $t['status'] === 'scheduled'));
$ongoing_trainings = count(array_filter($trainings, fn($t) => $t['status'] === 'ongoing'));
$completed_trainings = count(array_filter($trainings, fn($t) => $t['status'] === 'completed'));

$stmt = null;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>View Available Training - Fire & Rescue Management</title>
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

        .stat-icon.upcoming {
            background: rgba(59, 130, 246, 0.1);
            color: var(--info);
        }

        .stat-icon.ongoing {
            background: rgba(245, 158, 11, 0.1);
            color: var(--warning);
        }

        .stat-icon.completed {
            background: rgba(16, 185, 129, 0.1);
            color: var(--success);
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

        .stat-percentage {
            font-size: 12px;
            color: var(--text-light);
            margin-top: 4px;
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

        .btn-danger {
            background: linear-gradient(135deg, var(--danger), #b91c1c);
            color: white;
        }

        .btn-danger:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(220, 38, 38, 0.3);
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

        .training-participants {
            text-align: center;
        }

        .participants-count {
            font-size: 20px;
            font-weight: 700;
            color: var(--text-color);
        }

        .participants-label {
            font-size: 12px;
            color: var(--text-light);
        }

        .participants-progress {
            width: 100px;
            height: 6px;
            background: var(--border-color);
            border-radius: 3px;
            margin: 8px auto 0;
            overflow: hidden;
        }

        .participants-fill {
            height: 100%;
            background: linear-gradient(90deg, var(--primary-color), var(--secondary-color));
            border-radius: 3px;
            transition: width 0.3s ease;
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

        .status-scheduled {
            background: rgba(59, 130, 246, 0.1);
            color: var(--info);
            border: 1px solid rgba(59, 130, 246, 0.2);
        }

        .status-ongoing {
            background: rgba(245, 158, 11, 0.1);
            color: var(--warning);
            border: 1px solid rgba(245, 158, 11, 0.2);
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

        .participants-list {
            display: grid;
            gap: 15px;
        }

        .participant-item {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 15px;
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: 10px;
        }

        .participant-info {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .participant-avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            font-size: 16px;
            flex-shrink: 0;
        }

        .participant-details {
            display: flex;
            flex-direction: column;
        }

        .participant-name {
            font-weight: 600;
            color: var(--text-color);
        }

        .participant-contact {
            font-size: 12px;
            color: var(--text-light);
            margin-top: 2px;
        }

        .participant-status {
            display: flex;
            flex-direction: column;
            align-items: flex-end;
            gap: 4px;
        }

        .registration-badge {
            display: inline-block;
            padding: 4px 10px;
            border-radius: 15px;
            font-size: 11px;
            font-weight: 600;
        }

        .badge-registered {
            background: rgba(59, 130, 246, 0.1);
            color: var(--info);
        }

        .badge-attending {
            background: rgba(245, 158, 11, 0.1);
            color: var(--warning);
        }

        .badge-completed {
            background: rgba(16, 185, 129, 0.1);
            color: var(--success);
        }

        .badge-no_show {
            background: rgba(220, 38, 38, 0.1);
            color: var(--danger);
        }

        .completion-badge {
            display: inline-block;
            padding: 4px 10px;
            border-radius: 15px;
            font-size: 11px;
            font-weight: 600;
        }

        .badge-not_started {
            background: rgba(156, 163, 175, 0.1);
            color: var(--gray-500);
        }

        .badge-in_progress {
            background: rgba(59, 130, 246, 0.1);
            color: var(--info);
        }

        .badge-completed {
            background: rgba(16, 185, 129, 0.1);
            color: var(--success);
        }

        .badge-failed {
            background: rgba(220, 38, 38, 0.1);
            color: var(--danger);
        }

        .certificate-info {
            font-size: 11px;
            color: var(--text-light);
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

        .form-input, .form-select, .form-textarea {
            width: 100%;
            padding: 12px 16px;
            border-radius: 10px;
            border: 1px solid var(--border-color);
            background: var(--card-bg);
            color: var(--text-color);
            font-size: 14px;
            transition: all 0.3s ease;
        }

        .form-input:focus, .form-select:focus, .form-textarea:focus {
            outline: none;
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(220, 38, 38, 0.1);
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
            
            .participant-item {
                flex-direction: column;
                gap: 15px;
                align-items: flex-start;
            }
            
            .participant-status {
                align-items: flex-start;
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
        }
    </style>
</head>
<body>
    <!-- Participants Modal -->
    <div class="modal-overlay" id="participants-modal">
        <div class="modal">
            <div class="modal-header">
                <h2 class="modal-title" id="modal-title">Training Participants</h2>
                <button class="modal-close" id="modal-close">&times;</button>
            </div>
            <div class="modal-body">
                <div id="modal-participants-content">
                    <!-- Participants list will be loaded here -->
                </div>
            </div>
            <div class="modal-actions">
                <button type="button" class="btn btn-secondary" id="close-participants-modal">Close</button>
            </div>
        </div>
    </div>
    
    <!-- Submit Confirmation Modal -->
    <div class="modal-overlay" id="submit-modal">
        <div class="modal">
            <div class="modal-header">
                <h2 class="modal-title">Submit Training Completions</h2>
                <button class="modal-close" id="submit-modal-close">&times;</button>
            </div>
            <form method="POST" id="submit-form">
                <div class="modal-body">
                    <input type="hidden" name="training_id" id="submit-training-id">
                    
                    <div class="form-group">
                        <div class="form-label" id="submit-training-info">Loading training information...</div>
                    </div>
                    
                    <div class="form-group">
                        <div style="background: rgba(220, 38, 38, 0.05); padding: 15px; border-radius: 10px; border-left: 4px solid var(--primary-color);">
                            <strong>Confirmation Required</strong>
                            <p style="margin-top: 5px; font-size: 13px; color: var(--text-light);" id="confirmation-text">
                                This will submit all completed training registrations to the admin for certificate approval.
                                Once submitted, you cannot make changes to the completion status.
                            </p>
                        </div>
                    </div>
                </div>
                <div class="modal-actions">
                    <button type="button" class="btn btn-secondary" id="close-submit-modal">Cancel</button>
                    <button type="submit" class="btn btn-success">
                        <i class='bx bx-check'></i>
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
                        <a href="../sds/create_shifts.php" class="submenu-item">Create Shifts</a>
                        <a href="../sds/view_shifts.php" class="submenu-item">View Shifts</a>
                        <a href="../sds/confirm_availability.php" class="submenu-item">Confirm Availability</a>
                        <a href="../sds/request_change.php" class="submenu-item">Request Change</a>
                        <a href="../sds/mark_attendance.php" class="submenu-item">Mark Attendance</a>
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
                        <a href="view_available_training.php" class="submenu-item active">View Available Training</a>
                        <a href="submit_training.php" class="submenu-item">Submit Training</a>
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
                            <input type="text" placeholder="Search trainings..." class="search-input" id="search-input">
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
                        <h1 class="dashboard-title">Available Training</h1>
                        <p class="dashboard-subtitle">View and manage training modules for volunteers</p>
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
                            <div class="stat-value"><?php echo $total_trainings; ?></div>
                            <div class="stat-label">Total Trainings</div>
                            <div class="stat-percentage">
                                Synced from API
                            </div>
                        </div>
                        
                        <div class="stat-card">
                            <div class="stat-icon upcoming">
                                <i class='bx bx-calendar'></i>
                            </div>
                            <div class="stat-value"><?php echo $upcoming_trainings; ?></div>
                            <div class="stat-label">Upcoming</div>
                            <div class="stat-percentage">
                                <?php echo $total_trainings > 0 ? number_format(($upcoming_trainings/$total_trainings)*100, 1) : 0; ?>% of total
                            </div>
                        </div>
                        
                        <div class="stat-card">
                            <div class="stat-icon ongoing">
                                <i class='bx bx-time-five'></i>
                            </div>
                            <div class="stat-value"><?php echo $ongoing_trainings; ?></div>
                            <div class="stat-label">Ongoing</div>
                            <div class="stat-percentage">
                                <?php echo $total_trainings > 0 ? number_format(($ongoing_trainings/$total_trainings)*100, 1) : 0; ?>% of total
                            </div>
                        </div>
                        
                        <div class="stat-card">
                            <div class="stat-icon completed">
                                <i class='bx bx-check-circle'></i>
                            </div>
                            <div class="stat-value"><?php echo $completed_trainings; ?></div>
                            <div class="stat-label">Completed</div>
                            <div class="stat-percentage">
                                <?php echo $total_trainings > 0 ? number_format(($completed_trainings/$total_trainings)*100, 1) : 0; ?>% of total
                            </div>
                        </div>
                    </div>
                    
                    <!-- Filters -->
                    <div class="filters-container">
                        <h3 class="filters-title">
                            <i class='bx bx-filter-alt'></i>
                            Filter Trainings
                        </h3>
                        
                        <form method="GET" id="filters-form">
                            <div class="filters-grid">
                                <div class="filter-group">
                                    <label class="filter-label" for="status">Status</label>
                                    <select class="filter-select" id="status" name="status" onchange="this.form.submit()">
                                        <option value="">All Status</option>
                                        <option value="scheduled" <?php echo $status_filter === 'scheduled' ? 'selected' : ''; ?>>Scheduled</option>
                                        <option value="ongoing" <?php echo $status_filter === 'ongoing' ? 'selected' : ''; ?>>Ongoing</option>
                                        <option value="completed" <?php echo $status_filter === 'completed' ? 'selected' : ''; ?>>Completed</option>
                                        <option value="cancelled" <?php echo $status_filter === 'cancelled' ? 'selected' : ''; ?>>Cancelled</option>
                                    </select>
                                </div>
                                
                                <div class="filter-group">
                                    <label class="filter-label" for="search">Search</label>
                                    <input type="text" class="filter-input" id="search" name="search" 
                                           value="<?php echo htmlspecialchars($search_term ?? ''); ?>" 
                                           placeholder="Search by title, description, instructor...">
                                </div>
                                
                                <div class="filter-group">
                                    <label class="filter-label">&nbsp;</label>
                                    <div style="display: flex; gap: 10px;">
                                        <button type="submit" class="btn btn-primary">
                                            <i class='bx bx-search'></i>
                                            Apply Filters
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
                    
                    <!-- Actions -->
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
                        <h3 style="font-size: 18px; font-weight: 600; color: var(--text-color);">
                            <i class='bx bx-list-ul'></i>
                            Available Training Modules
                        </h3>
                        <div style="display: flex; gap: 10px;">
                            <a href="?action=sync" class="btn btn-success">
                                <i class='bx bx-refresh'></i>
                                Sync from API
                            </a>
                        </div>
                    </div>
                    
                    <!-- Trainings Table -->
                    <div class="table-container">
                        <?php if (count($trainings) > 0): ?>
                            <table class="table">
                                <thead>
                                    <tr>
                                        <th>Training Details</th>
                                        <th>Date & Duration</th>
                                        <th>Participants</th>
                                        <th>Status</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($trainings as $training): 
                                        $start_date = date('M j, Y', strtotime($training['training_date']));
                                        $end_date = $training['training_end_date'] ? date('M j, Y', strtotime($training['training_end_date'])) : null;
                                        $duration = $training['duration_hours'] ? number_format($training['duration_hours'], 1) . ' hours' : 'N/A';
                                        
                                        $participants_percentage = $training['max_participants'] > 0 ? 
                                            min(100, ($training['registered_count'] / $training['max_participants']) * 100) : 0;
                                        
                                        $completed_count = $training['completed_count'] ?? 0;
                                        $can_submit = $completed_count > 0 && $training['status'] === 'completed';
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
                                                        <span><?php echo htmlspecialchars($training['instructor']); ?></span>
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
                                                <div class="date-label">Start Date</div>
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
                                            <div class="training-participants">
                                                <div class="participants-count">
                                                    <?php echo $training['registered_count']; ?>
                                                </div>
                                                <div class="participants-label">
                                                    Registered
                                                </div>
                                                
                                                <?php if ($training['max_participants'] > 0): ?>
                                                <div class="participants-progress">
                                                    <div class="participants-fill" style="width: <?php echo $participants_percentage; ?>%;"></div>
                                                </div>
                                                <div class="participants-label">
                                                    <?php echo $training['registered_count']; ?> / <?php echo $training['max_participants']; ?>
                                                </div>
                                                <?php endif; ?>
                                                
                                                <?php if ($completed_count > 0): ?>
                                                <div class="participants-label" style="margin-top: 8px; color: var(--success);">
                                                    <i class='bx bx-check'></i> <?php echo $completed_count; ?> completed
                                                </div>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                        <td>
                                            <div class="status-badge status-<?php echo $training['status']; ?>">
                                                <?php echo ucfirst($training['status']); ?>
                                            </div>
                                        </td>
                                        <td>
                                            <div class="action-buttons">
                                                <button type="button" class="btn btn-info btn-sm view-participants-btn"
                                                        data-training-id="<?php echo $training['id']; ?>"
                                                        data-training-title="<?php echo htmlspecialchars($training['title']); ?>">
                                                    <i class='bx bx-group'></i>
                                                    View Participants
                                                </button>
                                                
                                                <?php if ($can_submit): ?>
                                                <button type="button" class="btn btn-success btn-sm submit-training-btn"
                                                        data-training-id="<?php echo $training['id']; ?>"
                                                        data-training-title="<?php echo htmlspecialchars($training['title']); ?>"
                                                        data-completed-count="<?php echo $completed_count; ?>">
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
                                <i class='bx bx-book'></i>
                                <h3>No Training Modules Found</h3>
                                <p>No training modules are currently available. Try syncing from the API or check back later.</p>
                                <a href="?action=sync" class="btn btn-primary" style="margin-top: 20px;">
                                    <i class='bx bx-refresh'></i>
                                    Sync from API
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
            
            // Setup modals
            setupParticipantsModal();
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
        
        function setupParticipantsModal() {
            const participantsModal = document.getElementById('participants-modal');
            const modalClose = document.getElementById('modal-close');
            const closeModal = document.getElementById('close-participants-modal');
            const viewButtons = document.querySelectorAll('.view-participants-btn');
            
            modalClose.addEventListener('click', () => participantsModal.classList.remove('active'));
            closeModal.addEventListener('click', () => participantsModal.classList.remove('active'));
            
            participantsModal.addEventListener('click', function(e) {
                if (e.target === this) {
                    participantsModal.classList.remove('active');
                }
            });
            
            viewButtons.forEach(button => {
                button.addEventListener('click', function() {
                    const trainingId = this.getAttribute('data-training-id');
                    const trainingTitle = this.getAttribute('data-training-title');
                    
                    document.getElementById('modal-title').textContent = 'Participants - ' + trainingTitle;
                    
                    // Load participants via AJAX
                    fetchParticipants(trainingId);
                    
                    participantsModal.classList.add('active');
                });
            });
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
                    const completedCount = this.getAttribute('data-completed-count');
                    
                    document.getElementById('submit-training-id').value = trainingId;
                    document.getElementById('submit-training-info').textContent = 
                        trainingTitle + ' (' + completedCount + ' completions ready for submission)';
                    
                    document.getElementById('confirmation-text').textContent = 
                        'This will submit ' + completedCount + ' completed training registrations for "' + 
                        trainingTitle + '" to the admin for certificate approval. Once submitted, you cannot make changes to the completion status.';
                    
                    submitModal.classList.add('active');
                });
            });
        }
        
        function fetchParticipants(trainingId) {
            const contentDiv = document.getElementById('modal-participants-content');
            contentDiv.innerHTML = '<div style="text-align: center; padding: 40px;"><i class="bx bx-loader-alt bx-spin" style="font-size: 40px; color: var(--text-light);"></i><p>Loading participants...</p></div>';
            
            fetch('get_training_participants.php?training_id=' + trainingId)
                .then(response => response.text())
                .then(html => {
                    contentDiv.innerHTML = html;
                })
                .catch(error => {
                    contentDiv.innerHTML = '<div style="text-align: center; padding: 40px; color: var(--danger);"><i class="bx bx-error"></i><p>Error loading participants. Please try again.</p></div>';
                    console.error('Error:', error);
                });
        }
        
        function clearFilters() {
            window.location.href = 'view_available_training.php';
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