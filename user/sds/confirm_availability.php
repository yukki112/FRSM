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
$volunteer_query = "SELECT id, first_name, last_name, contact_number FROM volunteers WHERE user_id = ?";
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

// Handle shift confirmation
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action']) && isset($_POST['shift_id'])) {
        $shift_id = (int)$_POST['shift_id'];
        $action = $_POST['action'];
        
        // Get shift details
        $shift_query = "SELECT * FROM shifts WHERE id = ? AND volunteer_id = ?";
        $shift_stmt = $pdo->prepare($shift_query);
        $shift_stmt->execute([$shift_id, $volunteer_id]);
        $shift = $shift_stmt->fetch();
        
        if ($shift) {
            try {
                $pdo->beginTransaction();
                
                if ($action === 'confirm') {
                    // Confirm shift
                    $update_query = "UPDATE shifts SET 
                        confirmation_status = 'confirmed',
                        status = 'confirmed',
                        confirmed_at = NOW(),
                        updated_at = NOW()
                        WHERE id = ? AND volunteer_id = ?";
                    $update_stmt = $pdo->prepare($update_query);
                    $update_stmt->execute([$shift_id, $volunteer_id]);
                    
                    // Insert into shift_confirmations table
                    $confirm_query = "INSERT INTO shift_confirmations (shift_id, volunteer_id, status, responded_at)
                                     VALUES (?, ?, 'confirmed', NOW())";
                    $confirm_stmt = $pdo->prepare($confirm_query);
                    $confirm_stmt->execute([$shift_id, $volunteer_id]);
                    
                    // Send notification (if you have a notification system)
                    $notification_query = "INSERT INTO notifications (user_id, type, title, message, is_read, created_at)
                                          VALUES (?, 'shift_confirmation', 'Shift Confirmed', 'You have confirmed your shift scheduled on " . date('Y-m-d', strtotime($shift['shift_date'])) . "', 0, NOW())";
                    $notification_stmt = $pdo->prepare($notification_query);
                    $notification_stmt->execute([$user_id]);
                    
                    $success_message = "Shift confirmed successfully!";
                    
                } elseif ($action === 'decline') {
                    $reason = trim($_POST['decline_reason'] ?? '');
                    
                    if (empty($reason)) {
                        $error_message = "Please provide a reason for declining the shift.";
                    } else {
                        // Decline shift
                        $update_query = "UPDATE shifts SET 
                            confirmation_status = 'declined',
                            declined_reason = ?,
                            status = 'cancelled',
                            updated_at = NOW()
                            WHERE id = ? AND volunteer_id = ?";
                        $update_stmt = $pdo->prepare($update_query);
                        $update_stmt->execute([$reason, $shift_id, $volunteer_id]);
                        
                        // Insert into shift_confirmations table
                        $confirm_query = "INSERT INTO shift_confirmations (shift_id, volunteer_id, status, response_notes, responded_at)
                                         VALUES (?, ?, 'declined', ?, NOW())";
                        $confirm_stmt = $pdo->prepare($confirm_query);
                        $confirm_stmt->execute([$shift_id, $volunteer_id, $reason]);
                        
                        // Send notification
                        $notification_query = "INSERT INTO notifications (user_id, type, title, message, is_read, created_at)
                                              VALUES (?, 'shift_declined', 'Shift Declined', 'You have declined your shift scheduled on " . date('Y-m-d', strtotime($shift['shift_date'])) . "', 0, NOW())";
                        $notification_stmt = $pdo->prepare($notification_query);
                        $notification_stmt->execute([$user_id]);
                        
                        $success_message = "Shift declined successfully.";
                    }
                    
                } elseif ($action === 'request_change') {
                    $request_type = $_POST['request_type'] ?? '';
                    $request_details = trim($_POST['request_details'] ?? '');
                    
                    if (empty($request_details)) {
                        $error_message = "Please provide details for the change request.";
                    } else {
                        // Create change request
                        $change_request_query = "INSERT INTO shift_change_requests 
                            (shift_id, volunteer_id, request_type, request_details, proposed_date, proposed_start_time, proposed_end_time, swap_with_volunteer_id, status, requested_at)
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'pending', NOW())";
                        
                        $change_stmt = $pdo->prepare($change_request_query);
                        
                        if ($request_type === 'time_change') {
                            $proposed_date = $_POST['proposed_date'] ?? null;
                            $proposed_start = $_POST['proposed_start_time'] ?? null;
                            $proposed_end = $_POST['proposed_end_time'] ?? null;
                            
                            $change_stmt->execute([
                                $shift_id, $volunteer_id, $request_type, $request_details,
                                $proposed_date, $proposed_start, $proposed_end, null
                            ]);
                            
                        } elseif ($request_type === 'swap') {
                            $swap_with = $_POST['swap_with_volunteer'] ?? null;
                            $change_stmt->execute([
                                $shift_id, $volunteer_id, $request_type, $request_details,
                                null, null, null, $swap_with
                            ]);
                            
                        } else {
                            $change_stmt->execute([
                                $shift_id, $volunteer_id, $request_type, $request_details,
                                null, null, null, null
                            ]);
                        }
                        
                        // Update shift confirmation status
                        $update_query = "UPDATE shifts SET 
                            confirmation_status = 'change_requested',
                            change_request_notes = ?,
                            updated_at = NOW()
                            WHERE id = ? AND volunteer_id = ?";
                        $update_stmt = $pdo->prepare($update_query);
                        $update_stmt->execute([$request_details, $shift_id, $volunteer_id]);
                        
                        // Send notification
                        $notification_query = "INSERT INTO notifications (user_id, type, title, message, is_read, created_at)
                                              VALUES (?, 'shift_change_request', 'Shift Change Requested', 'You have requested a change for your shift scheduled on " . date('Y-m-d', strtotime($shift['shift_date'])) . "', 0, NOW())";
                        $notification_stmt = $pdo->prepare($notification_query);
                        $notification_stmt->execute([$user_id]);
                        
                        $success_message = "Change request submitted successfully. An administrator will review your request.";
                    }
                }
                
                $pdo->commit();
                
                // Redirect to refresh the page and show updated data
                header("Location: confirm_availability.php?success=" . urlencode($success_message ?? ''));
                exit();
                
            } catch (Exception $e) {
                $pdo->rollBack();
                $error_message = "An error occurred: " . $e->getMessage();
                error_log("Shift confirmation error: " . $e->getMessage());
            }
        } else {
            $error_message = "Shift not found or you don't have permission to modify it.";
        }
    }
}

// Check for success message in URL
if (isset($_GET['success'])) {
    $success_message = $_GET['success'];
}

// Get pending shifts (shifts that need confirmation) with duty assignment details
$pending_shifts_query = "
    SELECT s.*, 
           u.unit_name, 
           u.unit_code, 
           u.unit_type,
           u.location as unit_location,
           da.id as duty_assignment_id,
           da.duty_type,
           da.duty_description,
           da.priority,
           da.required_equipment,
           da.required_training,
           da.notes as duty_notes,
           CASE 
               WHEN s.shift_type = 'morning' THEN '🌅 Morning (6AM-2PM)'
               WHEN s.shift_type = 'afternoon' THEN '☀️ Afternoon (2PM-10PM)'
               WHEN s.shift_type = 'evening' THEN '🌆 Evening (6PM-2AM)'
               WHEN s.shift_type = 'night' THEN '🌙 Night (10PM-6AM)'
               WHEN s.shift_type = 'full_day' THEN '🌞 Full Day (8AM-5PM)'
               ELSE '🕐 Custom Hours'
           END as shift_type_display,
           s.confirmation_status,
           s.declined_reason,
           s.change_request_notes,
           sc.status as confirmation_response_status,
           sc.response_notes as confirmation_notes,
           sc.responded_at as confirmation_date
    FROM shifts s 
    LEFT JOIN units u ON s.unit_id = u.id 
    LEFT JOIN duty_assignments da ON s.duty_assignment_id = da.id
    LEFT JOIN shift_confirmations sc ON s.id = sc.shift_id AND s.volunteer_id = sc.volunteer_id
    WHERE s.volunteer_id = ? 
    AND s.shift_date >= CURDATE()
    AND s.status = 'scheduled'
    AND (
        s.confirmation_status IS NULL 
        OR s.confirmation_status = 'pending' 
        OR s.confirmation_status = ''
        OR (s.confirmation_status = 'change_requested' AND sc.status IS NULL)
    )
    ORDER BY s.shift_date ASC, s.start_time ASC
";
$pending_shifts_stmt = $pdo->prepare($pending_shifts_query);
$pending_shifts_stmt->execute([$volunteer_id]);
$pending_shifts = $pending_shifts_stmt->fetchAll();

// Get confirmed shifts (next 30 days) with duty assignment details
$confirmed_shifts_query = "
    SELECT s.*, 
           u.unit_name, 
           u.unit_code, 
           u.unit_type,
           u.location as unit_location,
           da.id as duty_assignment_id,
           da.duty_type,
           da.duty_description,
           da.priority,
           da.required_equipment,
           da.required_training,
           da.notes as duty_notes,
           CASE 
               WHEN s.shift_type = 'morning' THEN '🌅 Morning (6AM-2PM)'
               WHEN s.shift_type = 'afternoon' THEN '☀️ Afternoon (2PM-10PM)'
               WHEN s.shift_type = 'evening' THEN '🌆 Evening (6PM-2AM)'
               WHEN s.shift_type = 'night' THEN '🌙 Night (10PM-6AM)'
               WHEN s.shift_type = 'full_day' THEN '🌞 Full Day (8AM-5PM)'
               ELSE '🕐 Custom Hours'
           END as shift_type_display,
           s.confirmed_at,
           sc.responded_at
    FROM shifts s 
    LEFT JOIN units u ON s.unit_id = u.id 
    LEFT JOIN duty_assignments da ON s.duty_assignment_id = da.id
    LEFT JOIN shift_confirmations sc ON s.id = sc.shift_id AND s.volunteer_id = sc.volunteer_id
    WHERE s.volunteer_id = ? 
    AND s.shift_date >= CURDATE()
    AND (
        s.confirmation_status = 'confirmed'
        OR sc.status = 'confirmed'
        OR s.status = 'confirmed'
    )
    ORDER BY s.shift_date ASC, s.start_time ASC
    LIMIT 20
";
$confirmed_shifts_stmt = $pdo->prepare($confirmed_shifts_query);
$confirmed_shifts_stmt->execute([$volunteer_id]);
$confirmed_shifts = $confirmed_shifts_stmt->fetchAll();

// Get other volunteers for swap requests (excluding self)
$other_volunteers_query = "
    SELECT v.id, 
           v.first_name, 
           v.last_name, 
           v.email,
           v.contact_number,
           u.unit_name
    FROM volunteers v
    LEFT JOIN volunteer_assignments va ON v.id = va.volunteer_id AND va.status = 'Active'
    LEFT JOIN units u ON va.unit_id = u.id
    WHERE v.id != ? 
    AND v.status = 'approved'
    AND v.volunteer_status IN ('Active', 'New Volunteer')
    ORDER BY v.first_name, v.last_name
";
$other_volunteers_stmt = $pdo->prepare($other_volunteers_query);
$other_volunteers_stmt->execute([$volunteer_id]);
$other_volunteers = $other_volunteers_stmt->fetchAll();

// Get volunteer statistics for display
$stats_query = "
    SELECT 
        COUNT(*) as total_shifts,
        SUM(CASE WHEN s.confirmation_status = 'confirmed' OR sc.status = 'confirmed' OR s.status = 'confirmed' THEN 1 ELSE 0 END) as confirmed_shifts,
        SUM(CASE WHEN s.confirmation_status = 'declined' OR sc.status = 'declined' OR s.status = 'cancelled' THEN 1 ELSE 0 END) as declined_shifts,
        SUM(CASE WHEN s.confirmation_status IN ('pending', 'change_requested') OR s.confirmation_status IS NULL OR s.confirmation_status = '' THEN 1 ELSE 0 END) as pending_shifts,
        MIN(s.shift_date) as first_shift_date,
        MAX(s.shift_date) as last_shift_date
    FROM shifts s
    LEFT JOIN shift_confirmations sc ON s.id = sc.shift_id AND s.volunteer_id = sc.volunteer_id
    WHERE s.volunteer_id = ?
";
$stats_stmt = $pdo->prepare($stats_query);
$stats_stmt->execute([$volunteer_id]);
$volunteer_stats = $stats_stmt->fetch();

// Close statements
$stmt = null;
$volunteer_stmt = null;
$pending_shifts_stmt = null;
$confirmed_shifts_stmt = null;
$other_volunteers_stmt = null;
$stats_stmt = null;
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Confirm Availability - Fire & Rescue Services Management</title>
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

        .section-container {
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: 16px;
            padding: 30px;
            margin-bottom: 30px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
        }

        .section-title {
            font-size: 20px;
            font-weight: 700;
            color: var(--text-color);
            margin-bottom: 20px;
            padding-bottom: 12px;
            border-bottom: 2px solid var(--border-color);
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .section-title i {
            color: var(--primary-color);
        }

        .stats-container {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 15px;
            margin-bottom: 25px;
        }

        .stat-card {
            background: var(--background-color);
            border: 1px solid var(--border-color);
            border-radius: 12px;
            padding: 20px;
            text-align: center;
            transition: all 0.3s ease;
        }

        .stat-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
        }

        .stat-value {
            font-size: 28px;
            font-weight: 800;
            margin-bottom: 5px;
        }

        .stat-label {
            font-size: 12px;
            color: var(--text-light);
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }

        .shift-card {
            background: var(--background-color);
            border: 1px solid var(--border-color);
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 20px;
            transition: all 0.3s ease;
        }

        .shift-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 16px rgba(0, 0, 0, 0.1);
        }

        .shift-card.pending {
            border-left: 4px solid var(--warning);
        }

        .shift-card.confirmed {
            border-left: 4px solid var(--success);
        }

        .shift-card.change_requested {
            border-left: 4px solid var(--info);
        }

        .shift-card.declined {
            border-left: 4px solid var(--danger);
        }

        .shift-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
        }

        .shift-date {
            font-size: 18px;
            font-weight: 700;
            color: var(--text-color);
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .shift-date i {
            color: var(--primary-color);
        }

        .shift-status {
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            display: inline-block;
        }

        .status-pending {
            background: rgba(245, 158, 11, 0.1);
            color: var(--warning);
            border: 1px solid rgba(245, 158, 11, 0.2);
        }

        .status-confirmed {
            background: rgba(16, 185, 129, 0.1);
            color: var(--success);
            border: 1px solid rgba(16, 185, 129, 0.2);
        }

        .status-change_requested {
            background: rgba(59, 130, 246, 0.1);
            color: var(--info);
            border: 1px solid rgba(59, 130, 246, 0.2);
        }

        .status-declined {
            background: rgba(220, 38, 38, 0.1);
            color: var(--danger);
            border: 1px solid rgba(220, 38, 38, 0.2);
        }

        .shift-details {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
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
            font-weight: 500;
            color: var(--text-color);
            font-size: 14px;
        }

        .shift-actions {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }

        .btn {
            padding: 10px 20px;
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
            background: linear-gradient(135deg, var(--success), #34d399);
            color: white;
        }

        .btn-success:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(16, 185, 129, 0.3);
        }

        .btn-danger {
            background: linear-gradient(135deg, var(--danger), #ef4444);
            color: white;
        }

        .btn-danger:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(220, 38, 38, 0.3);
        }

        .btn-info {
            background: linear-gradient(135deg, var(--info), #60a5fa);
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
        }
        
        .modal-close:hover {
            color: var(--danger);
        }
        
        .modal-body {
            padding: 24px;
        }
        
        .modal-footer {
            padding: 24px;
            border-top: 1px solid var(--border-color);
            display: flex;
            gap: 12px;
            justify-content: flex-end;
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

        .form-control {
            width: 100%;
            padding: 10px 12px;
            border: 1px solid var(--border-color);
            border-radius: 8px;
            background: var(--background-color);
            color: var(--text-color);
            font-size: 14px;
            transition: all 0.3s ease;
        }

        .form-control:focus {
            outline: none;
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(220, 38, 38, 0.1);
        }

        textarea.form-control {
            min-height: 100px;
            resize: vertical;
        }

        .alert {
            padding: 15px 20px;
            border-radius: 10px;
            margin-bottom: 20px;
            border-left: 4px solid;
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .alert-success {
            background: rgba(16, 185, 129, 0.1);
            border-left-color: var(--success);
            color: var(--success);
        }

        .alert-danger {
            background: rgba(220, 38, 38, 0.1);
            border-left-color: var(--danger);
            color: var(--danger);
        }

        .alert-info {
            background: rgba(59, 130, 246, 0.1);
            border-left-color: var(--info);
            color: var(--info);
        }

        .alert-warning {
            background: rgba(245, 158, 11, 0.1);
            border-left-color: var(--warning);
            color: var(--warning);
        }

        .empty-state {
            text-align: center;
            padding: 40px 20px;
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

        .mobile-view {
            display: none;
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
            
            .section-container {
                padding: 20px;
            }
            
            .shift-details {
                grid-template-columns: 1fr;
            }
            
            .shift-actions {
                flex-direction: column;
            }
            
            .stats-container {
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
            
            .section-container {
                padding: 15px;
            }
            
            .desktop-view {
                display: none;
            }
            
            .mobile-view {
                display: block;
            }
            
            .stats-container {
                grid-template-columns: 1fr;
            }
            
            .shift-header {
                flex-direction: column;
                align-items: flex-start;
                gap: 10px;
            }
        }

        .request-type-group {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 10px;
            margin-bottom: 20px;
        }

        .request-type-option {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 10px;
            border: 1px solid var(--border-color);
            border-radius: 8px;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .request-type-option:hover {
            border-color: var(--primary-color);
            background: rgba(220, 38, 38, 0.05);
        }

        .request-type-option input[type="radio"] {
            margin: 0;
        }

        .time-input-group {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 15px;
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
        
        .confirmation-badge {
            display: inline-block;
            padding: 4px 8px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 600;
            margin-left: 8px;
            background: rgba(16, 185, 129, 0.1);
            color: var(--success);
        }
        
        .today-badge {
            background: rgba(245, 158, 11, 0.1);
            color: var(--warning);
            padding: 2px 8px;
            border-radius: 12px;
            font-size: 11px;
            margin-left: 8px;
        }
        
        /* Duty Assignment Styles */
        .duty-assignment-section {
            margin-top: 20px;
            padding: 15px;
            background: rgba(59, 130, 246, 0.05);
            border-radius: 8px;
            border-left: 3px solid var(--info);
        }
        
        .duty-assignment-title {
            font-size: 16px;
            font-weight: 700;
            color: var(--info);
            margin-bottom: 10px;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .duty-details {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-bottom: 15px;
        }
        
        .duty-item {
            display: flex;
            flex-direction: column;
        }
        
        .duty-label {
            font-size: 11px;
            color: var(--text-light);
            margin-bottom: 4px;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }
        
        .duty-value {
            font-weight: 500;
            color: var(--text-color);
            font-size: 13px;
        }
        
        .priority-badge {
            display: inline-block;
            padding: 3px 8px;
            border-radius: 12px;
            font-size: 10px;
            font-weight: 600;
            text-transform: uppercase;
        }
        
        .priority-primary {
            background: rgba(16, 185, 129, 0.1);
            color: var(--success);
            border: 1px solid rgba(16, 185, 129, 0.2);
        }
        
        .priority-secondary {
            background: rgba(245, 158, 11, 0.1);
            color: var(--warning);
            border: 1px solid rgba(245, 158, 11, 0.2);
        }
        
        .priority-support {
            background: rgba(59, 130, 246, 0.1);
            color: var(--info);
            border: 1px solid rgba(59, 130, 246, 0.2);
        }
        
        .equipment-list {
            display: flex;
            flex-wrap: wrap;
            gap: 5px;
            margin-top: 5px;
        }
        
        .equipment-item {
            background: var(--background-color);
            border: 1px solid var(--border-color);
            border-radius: 6px;
            padding: 4px 8px;
            font-size: 11px;
            color: var(--text-color);
        }
    </style>
</head>
<body>
    <!-- Decline Shift Modal -->
    <div class="modal-overlay" id="decline-modal">
        <div class="modal">
            <form method="POST" action="">
                <div class="modal-header">
                    <h2 class="modal-title">Decline Shift</h2>
                    <button type="button" class="modal-close" id="decline-modal-close">&times;</button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="shift_id" id="decline_shift_id">
                    <input type="hidden" name="action" value="decline">
                    
                    <div class="form-group">
                        <label class="form-label">Reason for Declining *</label>
                        <textarea name="decline_reason" class="form-control" 
                                  placeholder="Please provide a reason for declining this shift..." required></textarea>
                        <small style="color: var(--text-light); font-size: 12px;">
                            This information helps us plan and adjust schedules accordingly.
                        </small>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" id="cancel-decline">Cancel</button>
                    <button type="submit" class="btn btn-danger">Decline Shift</button>
                </div>
            </form>
        </div>
    </div>
    
    <!-- Request Change Modal -->
    <div class="modal-overlay" id="change-modal">
        <div class="modal">
            <form method="POST" action="">
                <div class="modal-header">
                    <h2 class="modal-title">Request Shift Change</h2>
                    <button type="button" class="modal-close" id="change-modal-close">&times;</button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="shift_id" id="change_shift_id">
                    <input type="hidden" name="action" value="request_change">
                    
                    <div class="form-group">
                        <label class="form-label">Type of Change</label>
                        <div class="request-type-group">
                            <label class="request-type-option">
                                <input type="radio" name="request_type" value="time_change" required>
                                <span>Time Change</span>
                            </label>
                            <label class="request-type-option">
                                <input type="radio" name="request_type" value="swap" required>
                                <span>Swap with Volunteer</span>
                            </label>
                            <label class="request-type-option">
                                <input type="radio" name="request_type" value="other" required>
                                <span>Other Change</span>
                            </label>
                        </div>
                    </div>
                    
                    <div id="time-change-fields" style="display: none;">
                        <div class="form-group">
                            <label class="form-label">Proposed Date</label>
                            <input type="date" name="proposed_date" class="form-control" min="<?php echo date('Y-m-d'); ?>">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Proposed Time</label>
                            <div class="time-input-group">
                                <div>
                                    <label class="form-label">Start Time</label>
                                    <input type="time" name="proposed_start_time" class="form-control">
                                </div>
                                <div>
                                    <label class="form-label">End Time</label>
                                    <input type="time" name="proposed_end_time" class="form-control">
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div id="swap-fields" style="display: none;">
                        <div class="form-group">
                            <label class="form-label">Swap with Volunteer</label>
                            <select name="swap_with_volunteer" class="form-control">
                                <option value="">Select a volunteer...</option>
                                <?php foreach ($other_volunteers as $vol): ?>
                                    <option value="<?php echo $vol['id']; ?>">
                                        <?php echo htmlspecialchars($vol['first_name'] . ' ' . $vol['last_name']); ?>
                                        <?php if ($vol['unit_name']): ?>
                                            (<?php echo htmlspecialchars($vol['unit_name']); ?>)
                                        <?php endif; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <small style="color: var(--text-light); font-size: 12px;">
                                Note: The other volunteer must also agree to the swap.
                            </small>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Change Details *</label>
                        <textarea name="request_details" class="form-control" 
                                  placeholder="Please explain the change you're requesting..." required></textarea>
                        <small style="color: var(--text-light); font-size: 12px;">
                            Be specific about what you need changed and why.
                        </small>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" id="cancel-change">Cancel</button>
                    <button type="submit" class="btn btn-info">Submit Request</button>
                </div>
            </form>
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
            <a href="../vra/volunteer_list.php" class="submenu-item">Volunteer List</a>
            <a href="../vra/roles_skills.php" class="submenu-item">Roles & Skills</a>
            <a href="../vra/availability.php" class="submenu-item">Availability</a>
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
        <div id="schedule" class="submenu active">
            <a href="view_shifts.php" class="submenu-item">Shift Calendar</a>
              <a href="confirm_availability.php" class="submenu-item active">Confirm Availability</a>
            <a href="duty_assignments.php" class="submenu-item">Duty Assignments</a>
            <a href="attendance_logs.php" class="submenu-item">Attendance Logs</a>
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
                    <div id="training" class="submenu">
                        <a href="../tc/register_training.php" class="submenu-item">Register for Training</a>
                        <a href="../tc/training_records.php" class="submenu-item">Training Records</a>
                        <a href="../tc/certification_status.php" class="submenu-item">Certification Status</a>
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
                            <input type="text" placeholder="Search incidents, personnel, equipment..." class="search-input" id="search-input">
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
                        <h1 class="dashboard-title">Confirm Availability</h1>
                        <p class="dashboard-subtitle">Confirm, decline, or request changes to your assigned shifts</p>
                    </div>
                </div>
                
                <!-- Content Container -->
                <div class="content-container">
                    <!-- Messages -->
                    <?php if (isset($success_message)): ?>
                        <div class="alert alert-success">
                            <i class='bx bx-check-circle'></i> <?php echo htmlspecialchars($success_message); ?>
                        </div>
                    <?php endif; ?>
                    
                    <?php if (isset($error_message)): ?>
                        <div class="alert alert-danger">
                            <i class='bx bx-error-circle'></i> <?php echo htmlspecialchars($error_message); ?>
                        </div>
                    <?php endif; ?>
                    
                    <!-- Volunteer Statistics -->
                    <div class="section-container">
                        <h3 class="section-title">
                            <i class='bx bx-stats'></i>
                            Your Volunteer Statistics
                        </h3>
                        
                        <div class="stats-container">
                            <div class="stat-card">
                                <div class="stat-value" style="color: var(--primary-color);">
                                    <?php echo $volunteer_stats ? $volunteer_stats['total_shifts'] : '0'; ?>
                                </div>
                                <div class="stat-label">Total Shifts</div>
                            </div>
                            
                            <div class="stat-card">
                                <div class="stat-value" style="color: var(--success);">
                                    <?php echo $volunteer_stats ? $volunteer_stats['confirmed_shifts'] : '0'; ?>
                                </div>
                                <div class="stat-label">Confirmed</div>
                            </div>
                            
                            <div class="stat-card">
                                <div class="stat-value" style="color: var(--danger);">
                                    <?php echo $volunteer_stats ? $volunteer_stats['declined_shifts'] : '0'; ?>
                                </div>
                                <div class="stat-label">Declined</div>
                            </div>
                            
                            <div class="stat-card">
                                <div class="stat-value" style="color: var(--warning);">
                                    <?php echo $volunteer_stats ? $volunteer_stats['pending_shifts'] : '0'; ?>
                                </div>
                                <div class="stat-label">Pending</div>
                            </div>
                        </div>
                        
                        <div style="background: rgba(220, 38, 38, 0.05); padding: 15px; border-radius: 8px; margin-top: 15px;">
                            <p style="margin: 0; color: var(--text-color); font-size: 13px;">
                                <i class='bx bx-info-circle' style="color: var(--primary-color);"></i>
                                <strong>Volunteer:</strong> <?php echo $volunteer_name; ?> 
                                | <strong>Contact:</strong> <?php echo $volunteer_contact; ?>
                                <?php if ($volunteer_stats && $volunteer_stats['first_shift_date']): ?>
                                    | <strong>First Shift:</strong> <?php echo date('M j, Y', strtotime($volunteer_stats['first_shift_date'])); ?>
                                <?php endif; ?>
                            </p>
                        </div>
                    </div>
                    
                    <!-- Pending Shifts Section -->
                    <div class="section-container">
                        <h3 class="section-title">
                            <i class='bx bx-time'></i>
                            Shifts Awaiting Confirmation
                            <?php if (count($pending_shifts) > 0): ?>
                                <span class="confirmation-badge"><?php echo count($pending_shifts); ?> pending</span>
                            <?php endif; ?>
                        </h3>
                        
                        <?php if (count($pending_shifts) > 0): ?>
                            <?php foreach ($pending_shifts as $shift): 
                                $shift_date = date('F j, Y', strtotime($shift['shift_date']));
                                $start_time = date('g:i A', strtotime($shift['start_time']));
                                $end_time = date('g:i A', strtotime($shift['end_time']));
                                
                                // Determine status
                                $status = $shift['confirmation_status'];
                                if (empty($status) && $shift['confirmation_response_status']) {
                                    $status = $shift['confirmation_response_status'];
                                }
                                if (empty($status)) {
                                    $status = 'pending';
                                }
                                
                                $status_class = 'status-' . $status;
                                $card_class = str_replace('_', '-', $status);
                            ?>
                                <div class="shift-card <?php echo $card_class; ?>">
                                    <div class="shift-header">
                                        <div class="shift-date">
                                            <i class='bx bx-calendar'></i>
                                            <?php echo $shift_date; ?>
                                            <?php if ($shift['shift_date'] == date('Y-m-d')): ?>
                                                <span class="today-badge">Today</span>
                                            <?php endif; ?>
                                        </div>
                                        <span class="shift-status <?php echo $status_class; ?>">
                                            <?php echo ucfirst(str_replace('_', ' ', $status)); ?>
                                        </span>
                                    </div>
                                    
                                    <div class="shift-details">
                                        <div class="detail-item">
                                            <span class="detail-label">Shift Type</span>
                                            <span class="detail-value"><?php echo $shift['shift_type_display']; ?></span>
                                        </div>
                                        
                                        <div class="detail-item">
                                            <span class="detail-label">Time</span>
                                            <span class="detail-value"><?php echo $start_time; ?> - <?php echo $end_time; ?></span>
                                        </div>
                                        
                                        <div class="detail-item">
                                            <span class="detail-label">Unit</span>
                                            <span class="detail-value">
                                                <?php echo htmlspecialchars($shift['unit_name'] ?? 'Not Assigned'); ?>
                                                <?php if ($shift['unit_code']): ?>
                                                    (<?php echo htmlspecialchars($shift['unit_code']); ?>)
                                                <?php endif; ?>
                                            </span>
                                        </div>
                                        
                                        <div class="detail-item">
                                            <span class="detail-label">Location</span>
                                            <span class="detail-value"><?php echo htmlspecialchars($shift['location'] ?? ($shift['unit_location'] ?? 'Main Station')); ?></span>
                                        </div>
                                    </div>
                                    
                                    <!-- Duty Assignment Section -->
                                    <?php if ($shift['duty_assignment_id'] && $shift['duty_type']): ?>
                                        <div class="duty-assignment-section">
                                            <h4 class="duty-assignment-title">
                                                <i class='bx bx-task'></i>
                                                Duty Assignment
                                            </h4>
                                            
                                            <div class="duty-details">
                                                <div class="duty-item">
                                                    <span class="duty-label">Duty Type</span>
                                                    <span class="duty-value">
                                                        <?php echo htmlspecialchars($shift['duty_type']); ?>
                                                        <span class="priority-badge priority-<?php echo htmlspecialchars($shift['priority']); ?>">
                                                            <?php echo htmlspecialchars($shift['priority']); ?>
                                                        </span>
                                                    </span>
                                                </div>
                                                
                                                <div class="duty-item">
                                                    <span class="duty-label">Description</span>
                                                    <span class="duty-value"><?php echo htmlspecialchars($shift['duty_description']); ?></span>
                                                </div>
                                                
                                                <?php if ($shift['required_equipment']): ?>
                                                    <div class="duty-item">
                                                        <span class="duty-label">Required Equipment</span>
                                                        <div class="equipment-list">
                                                            <?php 
                                                            $equipment_items = explode(',', $shift['required_equipment']);
                                                            foreach ($equipment_items as $item):
                                                                $item = trim($item);
                                                                if (!empty($item)):
                                                            ?>
                                                                <span class="equipment-item"><?php echo htmlspecialchars($item); ?></span>
                                                            <?php 
                                                                endif;
                                                            endforeach; 
                                                            ?>
                                                        </div>
                                                    </div>
                                                <?php endif; ?>
                                                
                                                <?php if ($shift['required_training']): ?>
                                                    <div class="duty-item">
                                                        <span class="duty-label">Required Training</span>
                                                        <span class="duty-value"><?php echo htmlspecialchars($shift['required_training']); ?></span>
                                                    </div>
                                                <?php endif; ?>
                                            </div>
                                            
                                            <?php if ($shift['duty_notes']): ?>
                                                <div style="margin-top: 10px; padding: 8px; background: rgba(255, 255, 255, 0.1); border-radius: 6px; border-left: 2px solid var(--info);">
                                                    <span style="font-weight: 600; color: var(--info); font-size: 11px;">Additional Notes:</span>
                                                    <p style="margin-top: 4px; color: var(--text-color); font-size: 12px;"><?php echo htmlspecialchars($shift['duty_notes']); ?></p>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    <?php endif; ?>
                                    
                                    <?php if ($status === 'pending'): ?>
                                        <div class="shift-actions">
                                            <form method="POST" action="" style="display: inline;">
                                                <input type="hidden" name="shift_id" value="<?php echo $shift['id']; ?>">
                                                <input type="hidden" name="action" value="confirm">
                                                <button type="submit" class="btn btn-success" onclick="return confirm('Are you sure you can work this shift?')">
                                                    <i class='bx bx-check'></i> Confirm Shift
                                                </button>
                                            </form>
                                            
                                            <button type="button" class="btn btn-danger" onclick="openDeclineModal(<?php echo $shift['id']; ?>)">
                                                <i class='bx bx-x'></i> Decline
                                            </button>
                                            
                                            <button type="button" class="btn btn-info" onclick="openChangeModal(<?php echo $shift['id']; ?>)">
                                                <i class='bx bx-edit'></i> Request Change
                                            </button>
                                        </div>
                                    <?php elseif ($status === 'change_requested'): ?>
                                        <div class="alert alert-info">
                                            <i class='bx bx-info-circle'></i>
                                            You have requested a change for this shift. An administrator will review your request.
                                            <?php if ($shift['change_request_notes']): ?>
                                                <p style="margin: 5px 0 0 0; font-size: 13px;">
                                                    <strong>Your note:</strong> <?php echo htmlspecialchars($shift['change_request_notes']); ?>
                                                </p>
                                            <?php endif; ?>
                                        </div>
                                    <?php elseif ($status === 'declined' && $shift['declined_reason']): ?>
                                        <div class="alert alert-warning">
                                            <i class='bx bx-info-circle'></i>
                                            You have declined this shift.
                                            <p style="margin: 5px 0 0 0; font-size: 13px;">
                                                <strong>Reason:</strong> <?php echo htmlspecialchars($shift['declined_reason']); ?>
                                            </p>
                                        </div>
                                    <?php endif; ?>
                                    
                                    <?php if ($shift['notes']): ?>
                                        <div style="margin-top: 15px; padding: 10px; background: rgba(220, 38, 38, 0.05); border-radius: 8px; border-left: 3px solid var(--primary-color);">
                                            <span style="font-weight: 600; color: var(--primary-color);">Shift Notes:</span>
                                            <p style="margin-top: 5px; color: var(--text-color); font-size: 13px;"><?php echo htmlspecialchars($shift['notes']); ?></p>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="empty-state">
                                <i class='bx bx-check-shield'></i>
                                <h3>No Shifts Awaiting Confirmation</h3>
                                <p>All your assigned shifts have been confirmed or you don't have any upcoming shifts.</p>
                            </div>
                        <?php endif; ?>
                    </div>
                    
                    <!-- Confirmed Shifts Section -->
                    <div class="section-container">
                        <h3 class="section-title">
                            <i class='bx bx-check-circle'></i>
                            Confirmed Shifts (Upcoming)
                        </h3>
                        
                        <?php if (count($confirmed_shifts) > 0): ?>
                            <div style="overflow-x: auto;">
                                <table style="width: 100%; border-collapse: collapse;">
                                    <thead>
                                        <tr>
                                            <th style="text-align: left; padding: 12px; background: rgba(220, 38, 38, 0.1); color: var(--text-color); font-weight: 600; border-bottom: 2px solid var(--border-color);">Date</th>
                                            <th style="text-align: left; padding: 12px; background: rgba(220, 38, 38, 0.1); color: var(--text-color); font-weight: 600; border-bottom: 2px solid var(--border-color);">Shift</th>
                                            <th style="text-align: left; padding: 12px; background: rgba(220, 38, 38, 0.1); color: var(--text-color); font-weight: 600; border-bottom: 2px solid var(--border-color);">Time</th>
                                            <th style="text-align: left; padding: 12px; background: rgba(220, 38, 38, 0.1); color: var(--text-color); font-weight: 600; border-bottom: 2px solid var(--border-color);">Unit & Location</th>
                                            <th style="text-align: left; padding: 12px; background: rgba(220, 38, 38, 0.1); color: var(--text-color); font-weight: 600; border-bottom: 2px solid var(--border-color);">Duty Assignment</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($confirmed_shifts as $shift): 
                                            $shift_date = date('D, M j', strtotime($shift['shift_date']));
                                            $start_time = date('g:i A', strtotime($shift['start_time']));
                                            $end_time = date('g:i A', strtotime($shift['end_time']));
                                        ?>
                                        <tr style="border-bottom: 1px solid var(--border-color); transition: all 0.3s ease;">
                                            <td style="padding: 12px;">
                                                <?php echo $shift_date; ?>
                                                <?php if ($shift['shift_date'] == date('Y-m-d')): ?>
                                                    <span class="today-badge" style="margin-left: 5px;">Today</span>
                                                <?php endif; ?>
                                            </td>
                                            <td style="padding: 12px;"><?php echo $shift['shift_type_display']; ?></td>
                                            <td style="padding: 12px;">
                                                <?php echo $start_time; ?> - <?php echo $end_time; ?>
                                            </td>
                                            <td style="padding: 12px;">
                                                <?php echo htmlspecialchars($shift['unit_name'] ?? 'Not Assigned'); ?>
                                                <?php if ($shift['unit_code']): ?>
                                                    <br><small style="color: var(--text-light);"><?php echo htmlspecialchars($shift['unit_code']); ?></small>
                                                <?php endif; ?>
                                                <?php if ($shift['location']): ?>
                                                    <br><small style="color: var(--text-light); font-size: 12px;">
                                                        <i class='bx bx-map'></i> <?php echo htmlspecialchars($shift['location']); ?>
                                                    </small>
                                                <?php endif; ?>
                                            </td>
                                            <td style="padding: 12px;">
                                                <?php if ($shift['duty_type']): ?>
                                                    <strong><?php echo htmlspecialchars($shift['duty_type']); ?></strong>
                                                    <br><small style="color: var(--text-light); font-size: 11px;">
                                                        <span class="priority-badge priority-<?php echo htmlspecialchars($shift['priority']); ?>" style="margin-right: 5px;">
                                                            <?php echo htmlspecialchars($shift['priority']); ?>
                                                        </span>
                                                        <?php echo htmlspecialchars(substr($shift['duty_description'], 0, 50)); ?>...
                                                    </small>
                                                    <?php if ($shift['required_equipment']): ?>
                                                        <br><small style="color: var(--text-light); font-size: 10px;">
                                                            <i class='bx bx-wrench'></i> Equipment required
                                                        </small>
                                                    <?php endif; ?>
                                                <?php else: ?>
                                                    <span style="color: var(--text-light); font-style: italic;">No duty assigned</span>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                            
                            <div style="margin-top: 20px; text-align: center;">
                                <a href="view_shifts.php" class="btn btn-secondary">
                                    <i class='bx bx-calendar'></i> View Full Calendar
                                </a>
                            </div>
                        <?php else: ?>
                            <div class="empty-state">
                                <i class='bx bx-calendar'></i>
                                <h3>No Confirmed Shifts</h3>
                                <p>You don't have any confirmed shifts scheduled yet.</p>
                            </div>
                        <?php endif; ?>
                    </div>
                    
                    <!-- Instructions Section -->
                    <div class="section-container">
                        <h3 class="section-title">
                            <i class='bx bx-help-circle'></i>
                            How to Use This Page
                        </h3>
                        
                        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 20px;">
                            <div style="background: rgba(16, 185, 129, 0.1); padding: 20px; border-radius: 10px; border: 1px solid rgba(16, 185, 129, 0.2);">
                                <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 10px;">
                                    <div style="background: var(--success); color: white; width: 30px; height: 30px; border-radius: 50%; display: flex; align-items: center; justify-content: center;">
                                        <i class='bx bx-check'></i>
                                    </div>
                                    <h4 style="margin: 0; color: var(--text-color);">Confirm Shift</h4>
                                </div>
                                <p style="margin: 0; color: var(--text-color); font-size: 13px;">
                                    Click "Confirm Shift" when you can work the assigned shift. You'll receive a confirmation.
                                </p>
                            </div>
                            
                            <div style="background: rgba(220, 38, 38, 0.1); padding: 20px; border-radius: 10px; border: 1px solid rgba(220, 38, 38, 0.2);">
                                <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 10px;">
                                    <div style="background: var(--danger); color: white; width: 30px; height: 30px; border-radius: 50%; display: flex; align-items: center; justify-content: center;">
                                        <i class='bx bx-x'></i>
                                    </div>
                                    <h4 style="margin: 0; color: var(--text-color);">Decline Shift</h4>
                                </div>
                                <p style="margin: 0; color: var(--text-color); font-size: 13px;">
                                    Click "Decline" if you cannot work the shift. Please provide a reason to help with scheduling.
                                </p>
                            </div>
                            
                            <div style="background: rgba(59, 130, 246, 0.1); padding: 20px; border-radius: 10px; border: 1px solid rgba(59, 130, 246, 0.2);">
                                <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 10px;">
                                    <div style="background: var(--info); color: white; width: 30px; height: 30px; border-radius: 50%; display: flex; align-items: center; justify-content: center;">
                                        <i class='bx bx-edit'></i>
                                    </div>
                                    <h4 style="margin: 0; color: var(--text-color);">Request Change</h4>
                                </div>
                                <p style="margin: 0; color: var(--text-color); font-size: 13px;">
                                    Need to change the time or swap with another volunteer? Click "Request Change".
                                </p>
                            </div>
                        </div>
                        
                        <div style="margin-top: 20px; padding: 15px; background: rgba(220, 38, 38, 0.05); border-radius: 8px; border-left: 3px solid var(--primary-color);">
                            <h4 style="margin: 0 0 10px 0; color: var(--primary-color);">Important Notes:</h4>
                            <ul style="margin: 0; padding-left: 20px; color: var(--text-color); font-size: 13px;">
                                <li>Please respond to shift requests within 48 hours</li>
                                <li>If you decline a shift, you may be asked to provide documentation</li>
                                <li>Change requests require administrator approval</li>
                                <li>Contact your unit coordinator for urgent schedule changes</li>
                                <li>Always arrive 15 minutes before your scheduled shift start time</li>
                                <li>Notify your supervisor immediately if you need to leave early</li>
                                <li><strong>Duty Assignments:</strong> Review your assigned duties and required equipment before confirming</li>
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
            
            // Handle request type selection
            const requestTypeRadios = document.querySelectorAll('input[name="request_type"]');
            requestTypeRadios.forEach(radio => {
                radio.addEventListener('change', handleRequestTypeChange);
            });
        });
        
        function initEventListeners() {
            // Theme toggle
            const themeToggle = document.getElementById('theme-toggle');
            const themeIcon = themeToggle.querySelector('i');
            const themeText = themeToggle.querySelector('span');
            
            if (themeToggle) {
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
            }
            
            // User profile dropdown
            const userProfile = document.getElementById('user-profile');
            const userDropdown = document.getElementById('user-dropdown');
            
            if (userProfile && userDropdown) {
                userProfile.addEventListener('click', function(e) {
                    e.stopPropagation();
                    userDropdown.classList.toggle('show');
                });
            }
            
            // Close dropdowns when clicking outside
            document.addEventListener('click', function() {
                if (userDropdown) {
                    userDropdown.classList.remove('show');
                }
            });
            
            // Decline modal
            const declineModal = document.getElementById('decline-modal');
            const declineModalClose = document.getElementById('decline-modal-close');
            const cancelDecline = document.getElementById('cancel-decline');
            
            if (declineModalClose) {
                declineModalClose.addEventListener('click', function() {
                    declineModal.classList.remove('active');
                });
            }
            
            if (cancelDecline) {
                cancelDecline.addEventListener('click', function() {
                    declineModal.classList.remove('active');
                });
            }
            
            if (declineModal) {
                declineModal.addEventListener('click', function(e) {
                    if (e.target === this) {
                        this.classList.remove('active');
                    }
                });
            }
            
            // Change modal
            const changeModal = document.getElementById('change-modal');
            const changeModalClose = document.getElementById('change-modal-close');
            const cancelChange = document.getElementById('cancel-change');
            
            if (changeModalClose) {
                changeModalClose.addEventListener('click', function() {
                    changeModal.classList.remove('active');
                });
            }
            
            if (cancelChange) {
                cancelChange.addEventListener('click', function() {
                    changeModal.classList.remove('active');
                });
            }
            
            if (changeModal) {
                changeModal.addEventListener('click', function(e) {
                    if (e.target === this) {
                        this.classList.remove('active');
                    }
                });
            }
            
            // Close modals with Escape key
            document.addEventListener('keydown', function(e) {
                if (e.key === 'Escape') {
                    const modals = document.querySelectorAll('.modal-overlay.active');
                    modals.forEach(modal => {
                        modal.classList.remove('active');
                    });
                }
            });
            
            // Search functionality
            const searchInput = document.getElementById('search-input');
            if (searchInput) {
                searchInput.addEventListener('keyup', function() {
                    const searchTerm = this.value.toLowerCase();
                    // You can implement search functionality here
                    console.log('Searching for:', searchTerm);
                });
            }
        }
        
        function openDeclineModal(shiftId) {
            const modal = document.getElementById('decline-modal');
            const shiftIdInput = document.getElementById('decline_shift_id');
            
            if (modal && shiftIdInput) {
                shiftIdInput.value = shiftId;
                modal.classList.add('active');
                
                // Clear any existing text
                const textarea = modal.querySelector('textarea');
                if (textarea) {
                    textarea.value = '';
                    textarea.focus();
                }
            }
        }
        
        function openChangeModal(shiftId) {
            const modal = document.getElementById('change-modal');
            const shiftIdInput = document.getElementById('change_shift_id');
            
            if (modal && shiftIdInput) {
                shiftIdInput.value = shiftId;
                modal.classList.add('active');
                
                // Reset form
                const form = modal.querySelector('form');
                if (form) {
                    form.reset();
                }
                
                // Hide extra fields
                document.getElementById('time-change-fields').style.display = 'none';
                document.getElementById('swap-fields').style.display = 'none';
                
                // Focus on first textarea
                const textarea = modal.querySelector('textarea');
                if (textarea) {
                    setTimeout(() => textarea.focus(), 300);
                }
            }
        }
        
        function handleRequestTypeChange() {
            const selectedValue = document.querySelector('input[name="request_type"]:checked').value;
            const timeFields = document.getElementById('time-change-fields');
            const swapFields = document.getElementById('swap-fields');
            
            if (selectedValue === 'time_change') {
                timeFields.style.display = 'block';
                swapFields.style.display = 'none';
                
                // Set min date for proposed date
                const dateInput = timeFields.querySelector('input[type="date"]');
                if (dateInput) {
                    dateInput.min = new Date().toISOString().split('T')[0];
                }
            } else if (selectedValue === 'swap') {
                timeFields.style.display = 'none';
                swapFields.style.display = 'block';
            } else {
                timeFields.style.display = 'none';
                swapFields.style.display = 'none';
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
            const timeDisplay = document.getElementById('current-time');
            if (timeDisplay) {
                timeDisplay.textContent = timeString;
            }
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