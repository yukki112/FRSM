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

// Check if user is admin
if ($role !== 'ADMIN') {
    header("Location: ../admin_dashboard.php");
    exit();
}

// Handle form submission
$success_message = '';
$error_message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['assign_training'])) {
        $volunteer_ids = isset($_POST['volunteer_ids']) ? $_POST['volunteer_ids'] : [];
        $training_id = isset($_POST['training_id']) ? intval($_POST['training_id']) : 0;
        
        if (empty($volunteer_ids)) {
            $error_message = "Please select at least one volunteer.";
        } elseif ($training_id <= 0) {
            $error_message = "Please select a training.";
        } else {
            try {
                $pdo->beginTransaction();
                
                // Get training details
                $training_query = "SELECT max_participants, current_participants, title FROM trainings WHERE id = ?";
                $training_stmt = $pdo->prepare($training_query);
                $training_stmt->execute([$training_id]);
                $training = $training_stmt->fetch();
                
                if (!$training) {
                    throw new Exception("Training not found.");
                }
                
                $max_participants = $training['max_participants'];
                $current_participants = $training['current_participants'];
                $available_slots = $max_participants - $current_participants;
                
                if (count($volunteer_ids) > $available_slots && $max_participants > 0) {
                    throw new Exception("Only {$available_slots} slots available for this training. You selected " . count($volunteer_ids) . " volunteers.");
                }
                
                $success_count = 0;
                $existing_count = 0;
                
                foreach ($volunteer_ids as $volunteer_id) {
                    // Check if already registered
                    $check_query = "SELECT id FROM training_registrations WHERE volunteer_id = ? AND training_id = ?";
                    $check_stmt = $pdo->prepare($check_query);
                    $check_stmt->execute([$volunteer_id, $training_id]);
                    
                    if ($check_stmt->fetch()) {
                        $existing_count++;
                        continue;
                    }
                    
                    // Insert registration
                    $insert_query = "INSERT INTO training_registrations (training_id, volunteer_id, status, registration_date, admin_approved, admin_approved_at, admin_approved_by) 
                                     VALUES (?, ?, 'registered', NOW(), 1, NOW(), ?)";
                    $insert_stmt = $pdo->prepare($insert_query);
                    
                    if ($insert_stmt->execute([$training_id, $volunteer_id, $user_id])) {
                        $success_count++;
                        
                        // Update training participants count
                        $update_query = "UPDATE trainings SET current_participants = current_participants + 1 WHERE id = ?";
                        $update_stmt = $pdo->prepare($update_query);
                        $update_stmt->execute([$training_id]);
                        
                        // Get volunteer details for notification
                        $volunteer_query = "SELECT first_name, middle_name, last_name, email FROM volunteers WHERE id = ?";
                        $volunteer_stmt = $pdo->prepare($volunteer_query);
                        $volunteer_stmt->execute([$volunteer_id]);
                        $volunteer = $volunteer_stmt->fetch();
                        
                        if ($volunteer) {
                            $volunteer_name = $volunteer['first_name'] . ' ' . ($volunteer['middle_name'] ? $volunteer['middle_name'] . ' ' : '') . $volunteer['last_name'];
                            
                            // Create notification for volunteer
                            $notification_query = "INSERT INTO notifications (user_id, type, title, message, is_read, created_at) 
                                                   SELECT u.id, 'training_assigned', 'Training Assigned', 
                                                          CONCAT('You have been assigned to training: ', ?, '. Training starts on: ', DATE_FORMAT(t.training_date, '%M %d, %Y')), 
                                                          0, NOW()
                                                   FROM users u 
                                                   JOIN trainings t ON t.id = ?
                                                   WHERE u.email = ?";
                            $notification_stmt = $pdo->prepare($notification_query);
                            $notification_stmt->execute([$training['title'], $training_id, $volunteer['email']]);
                        }
                    }
                }
                
                $pdo->commit();
                
                $message = "Successfully assigned {$success_count} volunteer(s) to training.";
                if ($existing_count > 0) {
                    $message .= " {$existing_count} volunteer(s) were already registered.";
                }
                $success_message = $message;
                
            } catch (Exception $e) {
                $pdo->rollBack();
                $error_message = "Error: " . $e->getMessage();
            }
        }
    }
}

// Fetch all active volunteers
$volunteers_query = "SELECT v.id, v.first_name, v.middle_name, v.last_name, v.email, v.volunteer_status, 
                     u.username, COUNT(tr.id) as training_count
                     FROM volunteers v
                     LEFT JOIN users u ON v.user_id = u.id
                     LEFT JOIN training_registrations tr ON v.id = tr.volunteer_id AND tr.status != 'cancelled'
                     WHERE v.status = 'approved'
                     GROUP BY v.id
                     ORDER BY v.first_name, v.last_name";
$volunteers_stmt = $pdo->prepare($volunteers_query);
$volunteers_stmt->execute();
$volunteers = $volunteers_stmt->fetchAll();

// Fetch all available trainings
$trainings_query = "SELECT t.*, 
                    (t.max_participants - t.current_participants) as available_slots,
                    COUNT(tr.id) as registered_count
                    FROM trainings t
                    LEFT JOIN training_registrations tr ON t.id = tr.training_id AND tr.status != 'cancelled'
                    WHERE t.status IN ('scheduled', 'ongoing')
                    AND (t.training_date >= CURDATE() OR t.training_end_date >= CURDATE())
                    GROUP BY t.id
                    ORDER BY t.training_date ASC, t.title ASC";
$trainings_stmt = $pdo->prepare($trainings_query);
$trainings_stmt->execute();
$trainings = $trainings_stmt->fetchAll();

// Get recent assignments
$recent_assignments_query = "SELECT tr.*, v.first_name, v.middle_name, v.last_name, t.title, 
                             u.first_name as assigned_by_first, u.last_name as assigned_by_last
                             FROM training_registrations tr
                             JOIN volunteers v ON tr.volunteer_id = v.id
                             JOIN trainings t ON tr.training_id = t.id
                             LEFT JOIN users u ON tr.admin_approved_by = u.id
                             WHERE tr.admin_approved = 1
                             ORDER BY tr.registration_date DESC
                             LIMIT 10";
$recent_assignments_stmt = $pdo->prepare($recent_assignments_query);
$recent_assignments_stmt->execute();
$recent_assignments = $recent_assignments_stmt->fetchAll();

// Get statistics
$stats_query = "SELECT 
                COUNT(DISTINCT v.id) as total_volunteers,
                COUNT(DISTINCT t.id) as total_trainings,
                COUNT(DISTINCT tr.id) as total_assignments,
                SUM(CASE WHEN t.training_date >= CURDATE() THEN 1 ELSE 0 END) as upcoming_trainings
                FROM volunteers v
                CROSS JOIN trainings t
                LEFT JOIN training_registrations tr ON v.id = tr.volunteer_id AND t.id = tr.training_id AND tr.status != 'cancelled'
                WHERE v.status = 'approved' 
                AND t.status IN ('scheduled', 'ongoing')";
$stats_stmt = $pdo->prepare($stats_query);
$stats_stmt->execute();
$stats = $stats_stmt->fetch();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Assign Training - Fire & Rescue Services</title>
    <link href='https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css' rel='stylesheet'>
    <link rel="icon" type="image/png" sizes="32x32" href="../../img/frsm-logo.png">
    <link rel="stylesheet" href="../../css/dashboard.css">
    <style>
        :root {
            --primary-color: #dc2626;
            --primary-dark: #b91c1c;
            --secondary-color: #ef4444;
            --background-color: #f8fafc;
            --text-color: #1f2937;
            --text-light: #6b7280;
            --border-color: #e5e7eb;
            --card-bg: #ffffff;
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
            --background-color: #0f172a;
            --text-color: #f1f5f9;
            --text-light: #94a3b8;
            --border-color: #334155;
            --card-bg: #1e293b;
            --sidebar-bg: #1e293b;
        }

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
            border-bottom: 1px solid var(--border-color);
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
            background: var(--gray-100);
            border: 1px solid var(--border-color);
            color: var(--text-color);
        }

        .secondary-button:hover {
            background: var(--gray-200);
            transform: translateY(-2px);
        }

        .dark-mode .secondary-button {
            background: var(--gray-800);
            border-color: var(--gray-700);
        }

        .dark-mode .secondary-button:hover {
            background: var(--gray-700);
        }

        .assign-container {
            display: flex;
            flex-direction: column;
            gap: 24px;
            padding: 0 40px 40px;
        }
        
        .assign-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 24px;
        }
        
        .assign-title {
            font-size: 28px;
            font-weight: 800;
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            margin-bottom: 8px;
        }
        
        .assign-subtitle {
            color: var(--text-light);
            font-size: 16px;
        }
        
        .stats-container {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
            gap: 16px;
            margin-bottom: 24px;
        }
        
        .stat-card {
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
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
            transform: translateY(-5px);
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.15);
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
        
        .stat-value {
            font-size: 28px;
            font-weight: 800;
            margin-bottom: 4px;
        }
        
        .stat-label {
            font-size: 14px;
            color: var(--text-light);
        }
        
        .assign-form-container {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 24px;
            margin-bottom: 24px;
        }
        
        @media (max-width: 1024px) {
            .assign-form-container {
                grid-template-columns: 1fr;
            }
        }
        
        .form-section {
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
            border-radius: 16px;
            padding: 24px;
        }
        
        .section-title {
            font-size: 18px;
            font-weight: 700;
            margin-bottom: 16px;
            padding-bottom: 8px;
            border-bottom: 1px solid var(--border-color);
            color: var(--primary-color);
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .section-title i {
            font-size: 20px;
        }
        
        .form-group {
            margin-bottom: 20px;
        }
        
        .form-label {
            display: block;
            font-size: 14px;
            font-weight: 600;
            color: var(--gray-700);
            margin-bottom: 8px;
        }
        
        .dark-mode .form-label {
            color: var(--gray-300);
        }
        
        .form-select, .form-input, .form-textarea {
            width: 100%;
            padding: 12px 16px;
            border-radius: 10px;
            border: 1px solid var(--border-color);
            background: var(--card-bg);
            font-size: 14px;
            transition: all 0.3s ease;
        }
        
        .form-select:focus, .form-input:focus, .form-textarea:focus {
            outline: none;
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(220, 38, 38, 0.1);
        }
        
        .volunteers-list {
            max-height: 300px;
            overflow-y: auto;
            border: 1px solid var(--border-color);
            border-radius: 10px;
            padding: 12px;
        }
        
        .volunteer-item {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 12px;
            border-bottom: 1px solid var(--border-color);
            transition: all 0.3s ease;
        }
        
        .volunteer-item:last-child {
            border-bottom: none;
        }
        
        .volunteer-item:hover {
            background: rgba(220, 38, 38, 0.05);
        }
        
        .volunteer-info {
            display: flex;
            align-items: center;
            gap: 12px;
            flex: 1;
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
            flex-shrink: 0;
        }
        
        .volunteer-details {
            display: flex;
            flex-direction: column;
            flex: 1;
        }
        
        .volunteer-name {
            font-weight: 600;
            margin-bottom: 2px;
        }
        
        .volunteer-email {
            font-size: 12px;
            color: var(--text-light);
        }
        
        .volunteer-status {
            display: inline-block;
            padding: 4px 8px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 600;
            margin-top: 4px;
            background: rgba(16, 185, 129, 0.1);
            color: var(--success);
        }
        
        .volunteer-status.inactive {
            background: rgba(107, 114, 128, 0.1);
            color: var(--gray-500);
        }
        
        .checkbox-container {
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .checkbox-container input[type="checkbox"] {
            width: 18px;
            height: 18px;
            border-radius: 4px;
            border: 2px solid var(--border-color);
            cursor: pointer;
        }
        
        .checkbox-container input[type="checkbox"]:checked {
            background-color: var(--primary-color);
            border-color: var(--primary-color);
        }
        
        .training-card {
            border: 1px solid var(--border-color);
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 16px;
            transition: all 0.3s ease;
            cursor: pointer;
        }
        
        .training-card:hover {
            border-color: var(--primary-color);
            box-shadow: 0 4px 12px rgba(220, 38, 38, 0.1);
        }
        
        .training-card.selected {
            border-color: var(--primary-color);
            background: rgba(220, 38, 38, 0.05);
        }
        
        .training-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 12px;
        }
        
        .training-title {
            font-weight: 700;
            font-size: 16px;
            color: var(--text-color);
        }
        
        .training-date {
            font-size: 12px;
            color: var(--text-light);
            background: var(--gray-100);
            padding: 4px 8px;
            border-radius: 8px;
        }
        
        .training-details {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 12px;
            margin-top: 12px;
        }
        
        .training-detail {
            display: flex;
            flex-direction: column;
            gap: 4px;
        }
        
        .detail-label {
            font-size: 12px;
            color: var(--text-light);
        }
        
        .detail-value {
            font-size: 14px;
            font-weight: 500;
        }
        
        .capacity-indicator {
            width: 100%;
            height: 8px;
            background: var(--gray-200);
            border-radius: 4px;
            margin-top: 8px;
            overflow: hidden;
        }
        
        .capacity-fill {
            height: 100%;
            border-radius: 4px;
            background: linear-gradient(90deg, var(--success), var(--green));
        }
        
        .capacity-text {
            font-size: 12px;
            color: var(--text-light);
            margin-top: 4px;
            text-align: right;
        }
        
        .assignments-table-container {
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
            border-radius: 16px;
            overflow: hidden;
        }
        
        .assignments-table {
            width: 100%;
            border-collapse: collapse;
        }
        
        .assignments-table thead {
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
        }
        
        .assignments-table th {
            padding: 16px;
            text-align: left;
            color: white;
            font-weight: 600;
            font-size: 14px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .assignments-table th i {
            margin-right: 8px;
        }
        
        .assignments-table tbody tr {
            border-bottom: 1px solid var(--border-color);
            transition: all 0.3s ease;
        }
        
        .assignments-table tbody tr:hover {
            background: rgba(220, 38, 38, 0.05);
        }
        
        .assignments-table tbody tr:last-child {
            border-bottom: none;
        }
        
        .assignments-table td {
            padding: 16px;
            font-size: 14px;
            vertical-align: middle;
        }
        
        .form-actions {
            display: flex;
            justify-content: flex-end;
            gap: 12px;
            margin-top: 24px;
        }
        
        .submit-button {
            padding: 12px 24px;
            border-radius: 12px;
            font-weight: 600;
            border: none;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .submit-button.primary {
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            color: white;
            box-shadow: 0 4px 15px rgba(220, 38, 38, 0.3);
        }
        
        .submit-button.primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(220, 38, 38, 0.4);
        }
        
        .submit-button.secondary {
            background: var(--gray-100);
            border: 1px solid var(--border-color);
            color: var(--text-color);
        }
        
        .submit-button.secondary:hover {
            background: var(--gray-200);
            transform: translateY(-2px);
        }
        
        .dark-mode .submit-button.secondary {
            background: var(--gray-800);
            border-color: var(--gray-700);
        }
        
        .dark-mode .submit-button.secondary:hover {
            background: var(--gray-700);
        }
        
        /* Alert messages */
        .alert {
            padding: 15px 20px;
            border-radius: 10px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 12px;
            animation: slideIn 0.3s ease;
        }
        
        @keyframes slideIn {
            from {
                transform: translateY(-20px);
                opacity: 0;
            }
            to {
                transform: translateY(0);
                opacity: 1;
            }
        }
        
        .alert-success {
            background: rgba(16, 185, 129, 0.1);
            border: 1px solid rgba(16, 185, 129, 0.2);
            color: var(--success);
        }
        
        .alert-error {
            background: rgba(220, 38, 38, 0.1);
            border: 1px solid rgba(220, 38, 38, 0.2);
            color: var(--danger);
        }
        
        .alert i {
            font-size: 20px;
        }
        
        .select-all-container {
            display: flex;
            align-items: center;
            gap: 8px;
            margin-bottom: 12px;
            padding: 8px;
            background: var(--gray-50);
            border-radius: 8px;
        }
        
        .dark-mode .select-all-container {
            background: var(--gray-800);
        }
        
        /* Loading Animation */
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
        
        /* User Profile Dropdown */
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
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.2);
            border-radius: 12px;
            padding: 8px;
            min-width: 200px;
            z-index: 1001;
            opacity: 0;
            visibility: hidden;
            transform: translateY(-10px);
            transition: all 0.3s ease;
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

        /* Notification Bell */
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

        /* Notification Dropdown */
        .notification-dropdown {
            position: absolute;
            top: 100%;
            right: 0;
            margin-top: 8px;
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.2);
            border-radius: 12px;
            width: 320px;
            max-height: 400px;
            overflow-y: auto;
            z-index: 1001;
            opacity: 0;
            visibility: hidden;
            transform: translateY(-10px);
            transition: all 0.3s ease;
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
        
        /* Empty state */
        .empty-state {
            text-align: center;
            padding: 40px 20px;
            color: var(--text-light);
        }
        
        .empty-state-icon {
            font-size: 48px;
            margin-bottom: 16px;
            color: var(--text-light);
            opacity: 0.5;
        }
        
        /* Training status indicators */
        .status-indicator {
            display: inline-block;
            width: 10px;
            height: 10px;
            border-radius: 50%;
            margin-right: 6px;
        }
        
        .status-scheduled {
            background-color: var(--info);
        }
        
        .status-ongoing {
            background-color: var(--warning);
        }
        
        .status-completed {
            background-color: var(--success);
        }
        
        .status-cancelled {
            background-color: var(--danger);
        }
        
        @media (max-width: 768px) {
            .dashboard-header {
                padding: 40px 25px 30px;
            }
            
            .dashboard-title {
                font-size: 32px;
            }
            
            .assign-container {
                padding: 0 25px 30px;
            }
            
            .stats-container {
                grid-template-columns: repeat(2, 1fr);
            }
            
            .training-details {
                grid-template-columns: 1fr;
            }
            
            .assignments-table {
                display: block;
                overflow-x: auto;
            }
        }
        
        @media (max-width: 480px) {
            .stats-container {
                grid-template-columns: 1fr;
            }
            
            .form-actions {
                flex-direction: column;
            }
            
            .volunteer-info {
                flex-direction: column;
                align-items: flex-start;
            }
        }
    </style>
</head>
<body>

    <!-- Loading Animation -->
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
        <div class="animation-text" id="animation-text">Loading Training Assignment...</div>
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
                        <a href="../review_data.php" class="submenu-item">Review Data</a>
                        <a href="../approve-applications.php" class="submenu-item">Approve Applications</a>
                        <a href="../assign-volunteers.php" class="submenu-item">Assign Volunteers</a>
                        <a href="../view-availability.php" class="submenu-item">View Availability</a>
                        <a href="../remove-volunteers.php" class="submenu-item">Remove Volunteers</a>
                        <a href="../toggle_volunteer_registration.php" class="submenu-item">Toggle Volunteer Registration Access</a>
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
                        <a href="#" class="submenu-item">Create Schedule</a>
                        <a href="#" class="submenu-item">Approve Shifts</a>
                        <a href="#" class="submenu-item">Override Assignments</a>
                        <a href="#" class="submenu-item">Monitor Attendance</a>
                    </div>
                    
                   <!-- Training & Certification Monitoring -->
                    <div class="menu-item active" onclick="toggleSubmenu('training-management')">
                        <div class="icon-box icon-bg-teal">
                            <i class='bx bxs-graduation icon-teal'></i>
                        </div>
                        <span class="font-medium">Training Management</span>
                        <svg class="dropdown-arrow menu-icon rotated" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                        </svg>
                    </div>
                    <div id="training-management" class="submenu active">
                        <a href="approve_completions.php" class="submenu-item">Approve Completions</a>
                        <a href="view_training_records.php" class="submenu-item">View Records</a>
                        <a href="assign_training.php" class="submenu-item active">Assign Training</a>
                        <a href="track_expiry.php" class="submenu-item">Track Expiry</a>
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
                            <input type="text" placeholder="Search volunteers or trainings..." class="search-input" id="search-input">
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
                <?php if ($success_message): ?>
                    <div class="alert alert-success">
                        <i class='bx bx-check-circle'></i>
                        <div>
                            <strong>Success!</strong> <?php echo $success_message; ?>
                        </div>
                    </div>
                <?php endif; ?>
                
                <?php if ($error_message): ?>
                    <div class="alert alert-error">
                        <i class='bx bx-error-circle'></i>
                        <div>
                            <strong>Error!</strong> <?php echo $error_message; ?>
                        </div>
                    </div>
                <?php endif; ?>
                
                <div class="dashboard-header">
                    <div>
                        <h1 class="dashboard-title">Assign Training</h1>
                        <p class="dashboard-subtitle">Assign volunteers to training sessions</p>
                    </div>
                    <div class="dashboard-actions">
                        <button class="primary-button" onclick="window.location.href='view_training_records.php'">
                            <i class='bx bx-book'></i>
                            View All Records
                        </button>
                        <button class="secondary-button" onclick="refreshPage()">
                            <i class='bx bx-refresh'></i>
                            Refresh
                        </button>
                    </div>
                </div>
                
                <!-- Assign Training Section -->
                <div class="assign-container">
                    <!-- Stats Cards -->
                    <div class="stats-container">
                        <div class="stat-card">
                            <div class="stat-icon">
                                <i class='bx bxs-user'></i>
                            </div>
                            <div class="stat-value"><?php echo $stats['total_volunteers']; ?></div>
                            <div class="stat-label">Active Volunteers</div>
                        </div>
                        <div class="stat-card">
                            <div class="stat-icon">
                                <i class='bx bxs-book'></i>
                            </div>
                            <div class="stat-value"><?php echo $stats['total_trainings']; ?></div>
                            <div class="stat-label">Available Trainings</div>
                        </div>
                        <div class="stat-card">
                            <div class="stat-icon">
                                <i class='bx bxs-task'></i>
                            </div>
                            <div class="stat-value"><?php echo $stats['total_assignments']; ?></div>
                            <div class="stat-label">Total Assignments</div>
                        </div>
                        <div class="stat-card">
                            <div class="stat-icon">
                                <i class='bx bxs-calendar'></i>
                            </div>
                            <div class="stat-value"><?php echo $stats['upcoming_trainings']; ?></div>
                            <div class="stat-label">Upcoming Trainings</div>
                        </div>
                    </div>
                    
                    <!-- Assign Form -->
                    <form method="POST" action="">
                        <div class="assign-form-container">
                            <!-- Volunteers Section -->
                            <div class="form-section">
                                <h3 class="section-title">
                                    <i class='bx bxs-user'></i> Select Volunteers
                                </h3>
                                
                                <div class="select-all-container">
                                    <input type="checkbox" id="select-all-volunteers">
                                    <label for="select-all-volunteers">Select All Volunteers</label>
                                </div>
                                
                                <div class="volunteers-list" id="volunteers-list">
                                    <?php if (count($volunteers) > 0): ?>
                                        <?php foreach ($volunteers as $volunteer): 
                                            $volunteer_name = $volunteer['first_name'] . ' ' . ($volunteer['middle_name'] ? $volunteer['middle_name'] . ' ' : '') . $volunteer['last_name'];
                                            $initial = strtoupper(substr($volunteer['first_name'], 0, 1));
                                            $status_class = $volunteer['volunteer_status'] === 'Active' ? '' : 'inactive';
                                        ?>
                                            <div class="volunteer-item">
                                                <div class="volunteer-info">
                                                    <div class="volunteer-avatar">
                                                        <?php echo $initial; ?>
                                                    </div>
                                                    <div class="volunteer-details">
                                                        <div class="volunteer-name"><?php echo htmlspecialchars($volunteer_name); ?></div>
                                                        <div class="volunteer-email"><?php echo htmlspecialchars($volunteer['email']); ?></div>
                                                        <span class="volunteer-status <?php echo $status_class; ?>">
                                                            <?php echo htmlspecialchars($volunteer['volunteer_status']); ?>
                                                        </span>
                                                        <div style="font-size: 11px; color: var(--text-light); margin-top: 2px;">
                                                            <?php echo $volunteer['training_count']; ?> training(s) assigned
                                                        </div>
                                                    </div>
                                                </div>
                                                <div class="checkbox-container">
                                                    <input type="checkbox" 
                                                           name="volunteer_ids[]" 
                                                           value="<?php echo $volunteer['id']; ?>" 
                                                           class="volunteer-checkbox"
                                                           id="volunteer_<?php echo $volunteer['id']; ?>">
                                                    <label for="volunteer_<?php echo $volunteer['id']; ?>"></label>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <div class="empty-state">
                                            <div class="empty-state-icon">
                                                <i class='bx bxs-user-x'></i>
                                            </div>
                                            <p>No volunteers available</p>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                            
                            <!-- Training Section -->
                            <div class="form-section">
                                <h3 class="section-title">
                                    <i class='bx bxs-book'></i> Select Training
                                </h3>
                                
                                <div class="form-group">
                                    <label class="form-label">Training Session</label>
                                    <select class="form-select" name="training_id" id="training-select" required>
                                        <option value="">Select a training...</option>
                                        <?php foreach ($trainings as $training): 
                                            $start_date = date('M d, Y', strtotime($training['training_date']));
                                            $end_date = $training['training_end_date'] ? date('M d, Y', strtotime($training['training_end_date'])) : '';
                                            $date_display = $end_date ? $start_date . ' - ' . $end_date : $start_date;
                                            $capacity_text = $training['max_participants'] > 0 ? 
                                                "{$training['current_participants']}/{$training['max_participants']} ({$training['available_slots']} slots)" : 
                                                "{$training['current_participants']} registered (unlimited)";
                                        ?>
                                            <option value="<?php echo $training['id']; ?>" 
                                                    data-slots="<?php echo $training['available_slots']; ?>"
                                                    data-max="<?php echo $training['max_participants']; ?>"
                                                    data-current="<?php echo $training['current_participants']; ?>">
                                                <?php echo htmlspecialchars($training['title']); ?> - 
                                                <?php echo $date_display; ?> - 
                                                <?php echo $capacity_text; ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                
                                <div id="training-details" style="display: none;">
                                    <?php foreach ($trainings as $training): 
                                        $start_date = date('M d, Y', strtotime($training['training_date']));
                                        $end_date = $training['training_end_date'] ? date('M d, Y', strtotime($training['training_end_date'])) : $start_date;
                                        $duration = $training['duration_hours'] . ' hours';
                                        $capacity_percentage = $training['max_participants'] > 0 ? 
                                            ($training['current_participants'] / $training['max_participants']) * 100 : 0;
                                    ?>
                                        <div class="training-card" id="training-card-<?php echo $training['id']; ?>" style="display: none;">
                                            <div class="training-header">
                                                <div class="training-title"><?php echo htmlspecialchars($training['title']); ?></div>
                                                <div class="training-date">
                                                    <span class="status-indicator status-<?php echo strtolower($training['status']); ?>"></span>
                                                    <?php echo $training['status']; ?>
                                                </div>
                                            </div>
                                            
                                            <div class="form-textarea" style="margin-top: 12px; min-height: 60px; font-size: 13px;">
                                                <?php echo htmlspecialchars($training['description']); ?>
                                            </div>
                                            
                                            <div class="training-details">
                                                <div class="training-detail">
                                                    <div class="detail-label">Dates</div>
                                                    <div class="detail-value"><?php echo $start_date; ?> to <?php echo $end_date; ?></div>
                                                </div>
                                                <div class="training-detail">
                                                    <div class="detail-label">Duration</div>
                                                    <div class="detail-value"><?php echo $duration; ?></div>
                                                </div>
                                                <div class="training-detail">
                                                    <div class="detail-label">Instructor</div>
                                                    <div class="detail-value"><?php echo htmlspecialchars($training['instructor']); ?></div>
                                                </div>
                                                <div class="training-detail">
                                                    <div class="detail-label">Location</div>
                                                    <div class="detail-value"><?php echo htmlspecialchars($training['location']); ?></div>
                                                </div>
                                            </div>
                                            
                                            <?php if ($training['max_participants'] > 0): ?>
                                                <div style="margin-top: 16px;">
                                                    <div class="capacity-indicator">
                                                        <div class="capacity-fill" style="width: <?php echo $capacity_percentage; ?>%;"></div>
                                                    </div>
                                                    <div class="capacity-text">
                                                        <?php echo $training['current_participants']; ?>/<?php echo $training['max_participants']; ?> 
                                                        (<?php echo $training['available_slots']; ?> slots available)
                                                    </div>
                                                </div>
                                            <?php else: ?>
                                                <div style="margin-top: 16px; font-size: 12px; color: var(--text-light);">
                                                    Unlimited participants - <?php echo $training['registered_count']; ?> currently registered
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                                
                                <div class="form-group" style="margin-top: 20px;">
                                    <label class="form-label">Notes (Optional)</label>
                                    <textarea class="form-textarea" name="notes" rows="3" placeholder="Add any additional notes about this assignment..."></textarea>
                                </div>
                            </div>
                        </div>
                        
                        <div class="form-actions">
                            <button type="button" class="submit-button secondary" onclick="clearSelection()">
                                <i class='bx bx-reset'></i>
                                Clear Selection
                            </button>
                            <button type="submit" name="assign_training" class="submit-button primary">
                                <i class='bx bx-check'></i>
                                Assign Training
                            </button>
                        </div>
                    </form>
                    
                    <!-- Recent Assignments -->
                    <div class="assignments-table-container" style="margin-top: 40px;">
                        <div style="padding: 20px 24px 0;">
                            <h3 class="section-title">
                                <i class='bx bx-history'></i> Recent Assignments
                            </h3>
                        </div>
                        
                        <?php if (count($recent_assignments) > 0): ?>
                            <table class="assignments-table">
                                <thead>
                                    <tr>
                                        <th><i class='bx bx-user'></i> Volunteer</th>
                                        <th><i class='bx bx-book'></i> Training</th>
                                        <th><i class='bx bx-calendar'></i> Assigned On</th>
                                        <th><i class='bx bx-user-check'></i> Assigned By</th>
                                        <th><i class='bx bx-task'></i> Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($recent_assignments as $assignment): 
                                        $volunteer_name = $assignment['first_name'] . ' ' . ($assignment['middle_name'] ? $assignment['middle_name'] . ' ' : '') . $assignment['last_name'];
                                        $assigned_by = $assignment['assigned_by_first'] ? $assignment['assigned_by_first'] . ' ' . $assignment['assigned_by_last'] : 'System';
                                        $assigned_date = date('M d, Y', strtotime($assignment['registration_date']));
                                    ?>
                                        <tr>
                                            <td>
                                                <div class="volunteer-info">
                                                    <div class="volunteer-avatar">
                                                        <?php echo strtoupper(substr($assignment['first_name'], 0, 1)); ?>
                                                    </div>
                                                    <div class="volunteer-details">
                                                        <div class="volunteer-name"><?php echo htmlspecialchars($volunteer_name); ?></div>
                                                    </div>
                                                </div>
                                            </td>
                                            <td>
                                                <div class="training-info">
                                                    <div class="training-title"><?php echo htmlspecialchars($assignment['title']); ?></div>
                                                </div>
                                            </td>
                                            <td>
                                                <?php echo $assigned_date; ?>
                                            </td>
                                            <td>
                                                <?php echo htmlspecialchars($assigned_by); ?>
                                            </td>
                                            <td>
                                                <?php if ($assignment['certificate_issued']): ?>
                                                    <span class="status-badge status-certified">Certified</span>
                                                <?php elseif ($assignment['completion_status'] === 'completed'): ?>
                                                    <span class="status-badge status-completed">Completed</span>
                                                <?php elseif ($assignment['completion_status'] === 'in_progress'): ?>
                                                    <span class="status-badge status-in_progress">In Progress</span>
                                                <?php else: ?>
                                                    <span class="status-badge status-registered">Registered</span>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php else: ?>
                            <div class="empty-state" style="padding: 40px 20px;">
                                <div class="empty-state-icon">
                                    <i class='bx bx-book-open'></i>
                                </div>
                                <p>No recent assignments found</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <script>
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
                notificationDropdown.classList.remove('show');
            });
            
            // Notification bell dropdown
            const notificationBell = document.getElementById('notification-bell');
            const notificationDropdown = document.getElementById('notification-dropdown');
            
            notificationBell.addEventListener('click', function(e) {
                e.stopPropagation();
                notificationDropdown.classList.toggle('show');
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
            
            // Select all volunteers
            document.getElementById('select-all-volunteers').addEventListener('change', function() {
                const checkboxes = document.querySelectorAll('.volunteer-checkbox');
                checkboxes.forEach(checkbox => {
                    checkbox.checked = this.checked;
                });
                updateSelectedCount();
            });
            
            // Individual checkbox change
            document.querySelectorAll('.volunteer-checkbox').forEach(checkbox => {
                checkbox.addEventListener('change', updateSelectedCount);
            });
            
            // Training select change
            document.getElementById('training-select').addEventListener('change', function() {
                const selectedOption = this.options[this.selectedIndex];
                const trainingId = this.value;
                
                // Hide all training cards
                document.querySelectorAll('.training-card').forEach(card => {
                    card.style.display = 'none';
                });
                
                // Show selected training card
                if (trainingId) {
                    const trainingCard = document.getElementById('training-card-' + trainingId);
                    if (trainingCard) {
                        trainingCard.style.display = 'block';
                        document.getElementById('training-details').style.display = 'block';
                        
                        // Update capacity warning
                        const maxSlots = parseInt(selectedOption.getAttribute('data-max'));
                        const availableSlots = parseInt(selectedOption.getAttribute('data-slots'));
                        const selectedCount = getSelectedVolunteersCount();
                        
                        if (maxSlots > 0 && selectedCount > availableSlots) {
                            showCapacityWarning(selectedCount, availableSlots);
                        } else {
                            hideCapacityWarning();
                        }
                    }
                } else {
                    document.getElementById('training-details').style.display = 'none';
                    hideCapacityWarning();
                }
            });
            
            // Form submission validation
            const form = document.querySelector('form');
            form.addEventListener('submit', function(e) {
                const selectedVolunteers = getSelectedVolunteersCount();
                const trainingId = document.getElementById('training-select').value;
                const selectedOption = document.getElementById('training-select').options[document.getElementById('training-select').selectedIndex];
                const maxSlots = parseInt(selectedOption.getAttribute('data-max'));
                const availableSlots = parseInt(selectedOption.getAttribute('data-slots'));
                
                if (selectedVolunteers === 0) {
                    e.preventDefault();
                    alert('Please select at least one volunteer.');
                    return;
                }
                
                if (!trainingId) {
                    e.preventDefault();
                    alert('Please select a training.');
                    return;
                }
                
                if (maxSlots > 0 && selectedVolunteers > availableSlots) {
                    e.preventDefault();
                    showCapacityWarning(selectedVolunteers, availableSlots, true);
                    return;
                }
                
                // Show loading state
                showLoading('Assigning volunteers to training...');
            });
            
            // Search functionality
            document.getElementById('search-input').addEventListener('input', function() {
                const searchTerm = this.value.toLowerCase();
                const volunteerItems = document.querySelectorAll('.volunteer-item');
                
                volunteerItems.forEach(item => {
                    const volunteerName = item.querySelector('.volunteer-name').textContent.toLowerCase();
                    const volunteerEmail = item.querySelector('.volunteer-email').textContent.toLowerCase();
                    
                    if (volunteerName.includes(searchTerm) || volunteerEmail.includes(searchTerm)) {
                        item.style.display = 'flex';
                    } else {
                        item.style.display = 'none';
                    }
                });
            });
            
            // Keyboard shortcuts
            document.addEventListener('keydown', function(e) {
                // Search shortcut - forward slash
                if (e.key === '/' && !e.ctrlKey && !e.altKey && !e.metaKey) {
                    e.preventDefault();
                    document.getElementById('search-input').focus();
                }
                
                // Escape key to close dropdowns
                if (e.key === 'Escape') {
                    userDropdown.classList.remove('show');
                    notificationDropdown.classList.remove('show');
                }
            });
        }
        
        function getSelectedVolunteersCount() {
            const checkboxes = document.querySelectorAll('.volunteer-checkbox:checked');
            return checkboxes.length;
        }
        
        function updateSelectedCount() {
            const selectedCount = getSelectedVolunteersCount();
            const selectAllCheckbox = document.getElementById('select-all-volunteers');
            const allCheckboxes = document.querySelectorAll('.volunteer-checkbox');
            
            // Update select all checkbox state
            if (selectedCount === 0) {
                selectAllCheckbox.checked = false;
                selectAllCheckbox.indeterminate = false;
            } else if (selectedCount === allCheckboxes.length) {
                selectAllCheckbox.checked = true;
                selectAllCheckbox.indeterminate = false;
            } else {
                selectAllCheckbox.checked = false;
                selectAllCheckbox.indeterminate = true;
            }
            
            // Check capacity if training is selected
            const trainingSelect = document.getElementById('training-select');
            if (trainingSelect.value) {
                const selectedOption = trainingSelect.options[trainingSelect.selectedIndex];
                const maxSlots = parseInt(selectedOption.getAttribute('data-max'));
                const availableSlots = parseInt(selectedOption.getAttribute('data-slots'));
                
                if (maxSlots > 0 && selectedCount > availableSlots) {
                    showCapacityWarning(selectedCount, availableSlots);
                } else {
                    hideCapacityWarning();
                }
            }
        }
        
        function showCapacityWarning(selectedCount, availableSlots, isAlert = false) {
            // Remove existing warning
            hideCapacityWarning();
            
            const warningMessage = `You have selected ${selectedCount} volunteer(s), but only ${availableSlots} slot(s) are available.`;
            
            if (isAlert) {
                alert(warningMessage + '\n\nPlease reduce the number of selected volunteers or choose a different training.');
                return;
            }
            
            // Create warning element
            const warningElement = document.createElement('div');
            warningElement.className = 'alert alert-error';
            warningElement.innerHTML = `
                <i class='bx bx-error-circle'></i>
                <div>
                    <strong>Capacity Warning!</strong> ${warningMessage}
                </div>
            `;
            
            // Insert after the form
            const form = document.querySelector('form');
            form.parentNode.insertBefore(warningElement, form.nextSibling);
        }
        
        function hideCapacityWarning() {
            const existingWarning = document.querySelector('.alert.alert-error');
            if (existingWarning) {
                existingWarning.remove();
            }
        }
        
        function clearSelection() {
            // Clear all checkboxes
            document.querySelectorAll('.volunteer-checkbox').forEach(checkbox => {
                checkbox.checked = false;
            });
            
            // Reset select all checkbox
            document.getElementById('select-all-volunteers').checked = false;
            document.getElementById('select-all-volunteers').indeterminate = false;
            
            // Reset training select
            document.getElementById('training-select').value = '';
            document.getElementById('training-details').style.display = 'none';
            
            // Clear search
            document.getElementById('search-input').value = '';
            
            // Show all volunteers
            document.querySelectorAll('.volunteer-item').forEach(item => {
                item.style.display = 'flex';
            });
            
            // Hide capacity warning
            hideCapacityWarning();
            
            // Show success message
            showMessage('Selection cleared successfully.', 'success');
        }
        
        function refreshPage() {
            showLoading('Refreshing data...');
            location.reload();
        }
        
        function showMessage(message, type = 'success') {
            // Remove existing messages
            document.querySelectorAll('.alert').forEach(alert => {
                if (!alert.closest('.dashboard-header')) {
                    alert.remove();
                }
            });
            
            // Create message element
            const messageElement = document.createElement('div');
            messageElement.className = `alert alert-${type}`;
            messageElement.innerHTML = `
                <i class='bx bx-${type === 'success' ? 'check-circle' : 'error-circle'}'></i>
                <div>
                    <strong>${type === 'success' ? 'Success!' : 'Error!'}</strong> ${message}
                </div>
            `;
            
            // Insert after dashboard header
            const dashboardContent = document.querySelector('.dashboard-content');
            const dashboardHeader = document.querySelector('.dashboard-header');
            dashboardContent.insertBefore(messageElement, dashboardHeader.nextSibling);
            
            // Auto-remove after 5 seconds
            setTimeout(() => {
                if (messageElement.parentNode) {
                    messageElement.style.opacity = '0';
                    messageElement.style.transform = 'translateY(-20px)';
                    setTimeout(() => {
                        if (messageElement.parentNode) {
                            messageElement.remove();
                        }
                    }, 300);
                }
            }, 5000);
        }
        
        function showLoading(message) {
            const loadingOverlay = document.createElement('div');
            loadingOverlay.className = 'dashboard-animation';
            loadingOverlay.style.display = 'flex';
            loadingOverlay.style.opacity = '1';
            loadingOverlay.style.zIndex = '9998';
            loadingOverlay.innerHTML = `
                <div class="animation-logo" style="opacity: 1; transform: translateY(0);">
                    <div class="animation-logo-icon">
                        <img src="../../img/frsm-logo.png" alt="Fire & Rescue Logo">
                    </div>
                    <span class="animation-logo-text">Fire & Rescue</span>
                </div>
                <div class="animation-progress">
                    <div class="animation-progress-fill" style="width: 30%;"></div>
                </div>
                <div class="animation-text" style="opacity: 1;">${message}</div>
            `;
            
            document.body.appendChild(loadingOverlay);
            
            // Simulate progress
            let progress = 30;
            const progressInterval = setInterval(() => {
                progress += 10;
                loadingOverlay.querySelector('.animation-progress-fill').style.width = progress + '%';
                
                if (progress >= 90) {
                    clearInterval(progressInterval);
                }
            }, 200);
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