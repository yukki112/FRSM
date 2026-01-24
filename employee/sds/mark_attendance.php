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

// Get today's date
$today = date('Y-m-d');
$selected_date = $_GET['date'] ?? $today;

// Function to get volunteers with shifts for a specific date
function getVolunteersWithShifts($pdo, $date) {
    $sql = "SELECT 
                s.id as shift_id,
                s.volunteer_id,
                s.shift_date,
                s.start_time,
                s.end_time,
                s.location,
                s.attendance_status,
                s.check_in_time,
                s.check_out_time,
                s.status as shift_status,
                s.confirmation_status,
                v.first_name,
                v.last_name,
                v.contact_number,
                v.email,
                v.volunteer_status,
                u.unit_name,
                u.unit_code,
                da.duty_type,
                da.duty_description,
                da.priority as duty_priority,
                da.required_equipment,
                da.required_training
            FROM shifts s
            INNER JOIN volunteers v ON s.volunteer_id = v.id
            LEFT JOIN units u ON s.unit_id = u.id
            LEFT JOIN duty_assignments da ON s.duty_assignment_id = da.id
            WHERE s.shift_date = ?
            AND s.shift_for = 'volunteer'
            AND s.status != 'cancelled'
            AND v.status = 'approved'
            ORDER BY s.start_time, v.last_name, v.first_name";
    
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$date]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Error fetching volunteers with shifts: " . $e->getMessage());
        return [];
    }
}

// Function to get upcoming shifts
function getUpcomingShifts($pdo, $days_ahead = 7) {
    $start_date = date('Y-m-d');
    $end_date = date('Y-m-d', strtotime("+$days_ahead days"));
    
    $sql = "SELECT 
                s.id as shift_id,
                s.volunteer_id,
                s.shift_date,
                s.start_time,
                s.end_time,
                s.location,
                s.attendance_status,
                s.status as shift_status,
                s.confirmation_status,
                v.first_name,
                v.last_name,
                v.contact_number,
                v.email,
                u.unit_name,
                da.duty_type,
                da.duty_description,
                da.priority as duty_priority
            FROM shifts s
            INNER JOIN volunteers v ON s.volunteer_id = v.id
            LEFT JOIN units u ON s.unit_id = u.id
            LEFT JOIN duty_assignments da ON s.duty_assignment_id = da.id
            WHERE s.shift_date BETWEEN ? AND ?
            AND s.shift_for = 'volunteer'
            AND s.status != 'cancelled'
            AND v.status = 'approved'
            ORDER BY s.shift_date, s.start_time, v.last_name, v.first_name";
    
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$start_date, $end_date]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Error fetching upcoming shifts: " . $e->getMessage());
        return [];
    }
}

// Function to mark attendance
function markAttendance($pdo, $shift_id, $action, $admin_id, $notes = null) {
    try {
        $pdo->beginTransaction();
        
        // Get shift details
        $sql = "SELECT s.*, v.user_id as volunteer_user_id 
                FROM shifts s
                LEFT JOIN volunteers v ON s.volunteer_id = v.id
                WHERE s.id = ?";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$shift_id]);
        $shift = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$shift) {
            throw new Exception("Shift not found.");
        }
        
        $current_time = date('Y-m-d H:i:s');
        $update_values = [];
        $update_fields = [];
        
        if ($action === 'check_in') {
            if ($shift['attendance_status'] === 'checked_in' || $shift['attendance_status'] === 'checked_out') {
                throw new Exception("Volunteer is already checked in or checked out.");
            }
            
            $update_fields[] = "attendance_status = 'checked_in'";
            $update_fields[] = "check_in_time = ?";
            $update_fields[] = "status = 'in_progress'";
            $update_values[] = $current_time;
            
            // Create attendance log
            $log_sql = "INSERT INTO attendance_logs (shift_id, volunteer_id, shift_date, user_id, check_in, attendance_status, notes, created_at)
                       VALUES (?, ?, ?, ?, ?, 'present', ?, NOW())";
            $log_stmt = $pdo->prepare($log_sql);
            $log_stmt->execute([
                $shift_id,
                $shift['volunteer_id'],
                $shift['shift_date'],
                $shift['volunteer_user_id'],
                $current_time,
                $notes ?: 'Checked in by admin'
            ]);
            
            $log_id = $pdo->lastInsertId();
            
            // Create notification for volunteer
            if ($shift['volunteer_user_id']) {
                $notif_sql = "INSERT INTO notifications (user_id, type, title, message, created_at)
                             VALUES (?, 'attendance_checkin', 'Checked In Successfully', 
                                     'You have been checked in for your shift starting at " . date('g:i A', strtotime($shift['start_time'])) . ".', NOW())";
                $notif_stmt = $pdo->prepare($notif_sql);
                $notif_stmt->execute([$shift['volunteer_user_id']]);
            }
            
        } elseif ($action === 'check_out') {
            if ($shift['attendance_status'] !== 'checked_in') {
                throw new Exception("Volunteer must be checked in before checking out.");
            }
            
            if ($shift['check_out_time']) {
                throw new Exception("Volunteer is already checked out.");
            }
            
            // Calculate hours worked
            $check_in_time = new DateTime($shift['check_in_time']);
            $check_out_time = new DateTime($current_time);
            $interval = $check_in_time->diff($check_out_time);
            $hours_worked = $interval->h + ($interval->i / 60);
            
            $update_fields[] = "attendance_status = 'checked_out'";
            $update_fields[] = "check_out_time = ?";
            $update_fields[] = "status = 'completed'";
            $update_values[] = $current_time;
            
            // Update attendance log
            $log_sql = "UPDATE attendance_logs 
                       SET check_out = ?, 
                           total_hours = ?,
                           notes = CONCAT(COALESCE(notes, ''), ' Checked out: " . ($notes ?: 'Completed shift') . "'),
                           updated_at = NOW()
                       WHERE shift_id = ? AND volunteer_id = ? 
                       ORDER BY created_at DESC LIMIT 1";
            $log_stmt = $pdo->prepare($log_sql);
            $log_stmt->execute([
                $current_time,
                round($hours_worked, 2),
                $shift_id,
                $shift['volunteer_id']
            ]);
            
            // Create notification for volunteer
            if ($shift['volunteer_user_id']) {
                $notif_sql = "INSERT INTO notifications (user_id, type, title, message, created_at)
                             VALUES (?, 'attendance_checkout', 'Checked Out Successfully', 
                                     'You have been checked out from your shift. Total hours: " . round($hours_worked, 2) . ".', NOW())";
                $notif_stmt = $pdo->prepare($notif_sql);
                $notif_stmt->execute([$shift['volunteer_user_id']]);
            }
            
        } elseif ($action === 'mark_absent') {
            $update_fields[] = "attendance_status = 'absent'";
            $update_fields[] = "status = 'absent'";
            
            // Create attendance log for absence
            $log_sql = "INSERT INTO attendance_logs (shift_id, volunteer_id, shift_date, user_id, attendance_status, notes, created_at)
                       VALUES (?, ?, ?, ?, 'absent', ?, NOW())";
            $log_stmt = $pdo->prepare($log_sql);
            $log_stmt->execute([
                $shift_id,
                $shift['volunteer_id'],
                $shift['shift_date'],
                $shift['volunteer_user_id'],
                $notes ?: 'Marked absent by admin'
            ]);
            
            // Create notification for volunteer
            if ($shift['volunteer_user_id']) {
                $notif_sql = "INSERT INTO notifications (user_id, type, title, message, created_at)
                             VALUES (?, 'attendance_absent', 'Marked Absent', 
                                     'You have been marked absent for your shift on " . date('F j, Y', strtotime($shift['shift_date'])) . ".', NOW())";
                $notif_stmt = $pdo->prepare($notif_sql);
                $notif_stmt->execute([$shift['volunteer_user_id']]);
            }
            
        } elseif ($action === 'mark_excused') {
            $update_fields[] = "attendance_status = 'excused'";
            $update_fields[] = "status = 'excused'";
            
            // Create attendance log for excused absence
            $log_sql = "INSERT INTO attendance_logs (shift_id, volunteer_id, shift_date, user_id, attendance_status, notes, created_at)
                       VALUES (?, ?, ?, ?, 'excused', ?, NOW())";
            $log_stmt = $pdo->prepare($log_sql);
            $log_stmt->execute([
                $shift_id,
                $shift['volunteer_id'],
                $shift['shift_date'],
                $shift['volunteer_user_id'],
                $notes ?: 'Marked excused by admin'
            ]);
        }
        
        // Always update the shift
        $update_fields[] = "updated_at = NOW()";
        $update_values[] = $shift_id;
        
        $update_sql = "UPDATE shifts SET " . implode(", ", $update_fields) . " WHERE id = ?";
        $update_stmt = $pdo->prepare($update_sql);
        $update_stmt->execute($update_values);
        
        $pdo->commit();
        return true;
        
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log("Error in markAttendance: " . $e->getMessage());
        return false;
    }
}

// Handle attendance actions
$success_message = '';
$error_message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['attendance_action'])) {
        $shift_id = $_POST['shift_id'] ?? null;
        $action = $_POST['attendance_action'] ?? null;
        $notes = $_POST['attendance_notes'] ?? null;
        
        if ($shift_id && $action) {
            if (markAttendance($pdo, $shift_id, $action, $user_id, $notes)) {
                $success_message = "Attendance marked successfully!";
            } else {
                $error_message = "Failed to mark attendance. Please try again.";
            }
        } else {
            $error_message = "Missing required parameters.";
        }
    }
}

// Get data
$todays_shifts = getVolunteersWithShifts($pdo, $selected_date);
$upcoming_shifts = getUpcomingShifts($pdo, 14); // Next 14 days

// Get statistics
$total_today = count($todays_shifts);
$checked_in_today = count(array_filter($todays_shifts, fn($shift) => $shift['attendance_status'] === 'checked_in'));
$checked_out_today = count(array_filter($todays_shifts, fn($shift) => $shift['attendance_status'] === 'checked_out'));
$pending_today = count(array_filter($todays_shifts, fn($shift) => $shift['attendance_status'] === 'pending'));

// Get next 7 days for calendar
$next_days = [];
for ($i = 0; $i < 7; $i++) {
    $date = date('Y-m-d', strtotime("+$i days"));
    $next_days[] = [
        'date' => $date,
        'display' => date('D, M j', strtotime($date)),
        'shifts_count' => 0 // You can populate this if needed
    ];
}

$stmt = null;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Mark Attendance - Fire & Rescue Management</title>
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

        .stat-icon.checked-in {
            background: rgba(16, 185, 129, 0.1);
            color: var(--success);
        }

        .stat-icon.checked-out {
            background: rgba(59, 130, 246, 0.1);
            color: var(--info);
        }

        .stat-icon.pending {
            background: rgba(245, 158, 11, 0.1);
            color: var(--warning);
        }

        .stat-icon.absent {
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

        .stat-percentage {
            font-size: 12px;
            color: var(--text-light);
            margin-top: 4px;
        }

        .date-selector-container {
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: 16px;
            padding: 24px;
            margin-bottom: 30px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
        }

        .date-selector-title {
            font-size: 18px;
            font-weight: 600;
            margin-bottom: 20px;
            color: var(--text-color);
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .date-selector-title i {
            color: var(--primary-color);
        }

        .date-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(140px, 1fr));
            gap: 12px;
            margin-bottom: 20px;
        }

        .date-option {
            padding: 16px;
            border: 2px solid var(--border-color);
            border-radius: 12px;
            text-align: center;
            cursor: pointer;
            transition: all 0.3s ease;
            background: var(--card-bg);
        }

        .date-option:hover {
            border-color: var(--primary-color);
            transform: translateY(-2px);
        }

        .date-option.active {
            border-color: var(--primary-color);
            background: rgba(220, 38, 38, 0.05);
            box-shadow: 0 4px 12px rgba(220, 38, 38, 0.1);
        }

        .date-day {
            font-size: 12px;
            color: var(--text-light);
            margin-bottom: 4px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .date-number {
            font-size: 24px;
            font-weight: 700;
            margin-bottom: 4px;
        }

        .date-month {
            font-size: 14px;
            color: var(--text-color);
            font-weight: 600;
        }

        .date-shifts {
            font-size: 11px;
            color: var(--text-light);
            margin-top: 4px;
        }

        .custom-date-selector {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-top: 20px;
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

        .volunteer-info {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .volunteer-avatar {
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

        .volunteer-details {
            display: flex;
            flex-direction: column;
        }

        .volunteer-name {
            font-weight: 600;
            color: var(--text-color);
        }

        .volunteer-contact {
            font-size: 12px;
            color: var(--text-light);
            margin-top: 2px;
        }

        .shift-details {
            display: flex;
            flex-direction: column;
            gap: 4px;
        }

        .shift-time {
            font-weight: 600;
            color: var(--text-color);
            font-size: 14px;
        }

        .shift-location {
            font-size: 12px;
            color: var(--text-light);
            display: flex;
            align-items: center;
            gap: 4px;
        }

        .unit-badge {
            display: inline-block;
            padding: 3px 8px;
            border-radius: 10px;
            font-size: 10px;
            font-weight: 600;
            background: rgba(59, 130, 246, 0.1);
            color: var(--info);
            margin-top: 4px;
        }

        .duty-badge {
            display: inline-block;
            padding: 3px 8px;
            border-radius: 10px;
            font-size: 10px;
            font-weight: 600;
            text-transform: uppercase;
            margin-top: 4px;
        }

        .duty-fire_suppression {
            background: rgba(220, 38, 38, 0.1);
            color: var(--danger);
        }

        .duty-rescue_operations {
            background: rgba(59, 130, 246, 0.1);
            color: var(--info);
        }

        .duty-emergency_medical {
            background: rgba(16, 185, 129, 0.1);
            color: var(--success);
        }

        .duty-logistics_support {
            background: rgba(245, 158, 11, 0.1);
            color: var(--warning);
        }

        .duty-command_post {
            background: rgba(139, 92, 246, 0.1);
            color: var(--purple);
        }

        .attendance-status-badge {
            display: inline-block;
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            text-align: center;
            min-width: 100px;
        }

        .status-pending {
            background: rgba(245, 158, 11, 0.1);
            color: var(--warning);
            border: 1px solid rgba(245, 158, 11, 0.2);
        }

        .status-checked_in {
            background: rgba(16, 185, 129, 0.1);
            color: var(--success);
            border: 1px solid rgba(16, 185, 129, 0.2);
        }

        .status-checked_out {
            background: rgba(59, 130, 246, 0.1);
            color: var(--info);
            border: 1px solid rgba(59, 130, 246, 0.2);
        }

        .status-absent {
            background: rgba(220, 38, 38, 0.1);
            color: var(--danger);
            border: 1px solid rgba(220, 38, 38, 0.2);
        }

        .status-excused {
            background: rgba(139, 92, 246, 0.1);
            color: var(--purple);
            border: 1px solid rgba(139, 92, 246, 0.2);
        }

        .time-info {
            font-size: 11px;
            color: var(--text-light);
            margin-top: 4px;
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
            max-width: 500px;
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

        .form-textarea {
            min-height: 100px;
            resize: vertical;
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
            
            .date-grid {
                grid-template-columns: repeat(auto-fill, minmax(120px, 1fr));
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
            
            .date-grid {
                grid-template-columns: repeat(3, 1fr);
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
            
            .volunteer-avatar {
                width: 32px;
                height: 32px;
                font-size: 14px;
            }
        }

        @media (max-width: 576px) {
            .date-grid {
                grid-template-columns: repeat(2, 1fr);
            }
            
            .custom-date-selector {
                flex-direction: column;
                align-items: stretch;
            }
            
            .btn {
                justify-content: center;
            }
        }
    </style>
</head>
<body>
    <!-- Attendance Modal -->
    <div class="modal-overlay" id="attendance-modal">
        <div class="modal">
            <div class="modal-header">
                <h2 class="modal-title" id="modal-title">Mark Attendance</h2>
                <button class="modal-close" id="modal-close">&times;</button>
            </div>
            <form method="POST" id="attendance-form">
                <div class="modal-body">
                    <input type="hidden" name="shift_id" id="modal-shift-id">
                    <input type="hidden" name="attendance_action" id="modal-action">
                    
                    <div class="form-group">
                        <div class="form-label" id="volunteer-info">Loading volunteer information...</div>
                        <div class="form-label" id="shift-info">Loading shift details...</div>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label" for="attendance_notes">Notes (Optional)</label>
                        <textarea class="form-textarea" id="attendance_notes" name="attendance_notes" 
                                  placeholder="Add any notes about this attendance..."></textarea>
                    </div>
                    
                    <div class="form-group" id="confirmation-message" style="display: none;">
                        <div style="background: rgba(220, 38, 38, 0.05); padding: 15px; border-radius: 10px; border-left: 4px solid var(--primary-color);">
                            <strong>Confirmation Required</strong>
                            <p style="margin-top: 5px; font-size: 13px; color: var(--text-light);" id="confirmation-text"></p>
                        </div>
                    </div>
                </div>
                <div class="modal-actions">
                    <button type="button" class="btn btn-secondary" id="close-modal">Cancel</button>
                    <button type="submit" class="btn" id="submit-btn">
                        <span id="submit-text">Submit</span>
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
                        <a href="../fir/receive_data.php" class="submenu-item">Receive Data</a>
                      
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
                      <a href="../vra/review_data.php" class="submenu-item">Review/Aprroved Data Management</a>
                        <a href="../vra/approve_applications.php" class="submenu-item">Assign Volunteers</a>
                        <a href="../vra/view_availability.php" class="submenu-item">View Availability</a>
                        <a href="../vra/remove_volunteers.php" class="submenu-item">Remove Volunteers</a>
                        <a href="../vra/toggle_volunteer_registration.php" class="submenu-item">Open/Close Registration</a>
                    </div>
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
                        <a href="../ri/log_usage.php" class="submenu-item">Log Usage</a>
                        <a href="../ri/report_damages.php" class="submenu-item">Report Damages</a>
                        <a href="../ri/request_supplies.php" class="submenu-item">Request Supplies</a>
                        <a href="../ri/tag_resources.php" class="submenu-item">Tag Resources</a>
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
                    <div id="schedule" class="submenu active">
                         <a href="create_shifts.php" class="submenu-item">Create Shifts</a>
                        <a href="view_shifts.php" class="submenu-item">View Shifts</a>
                        <a href="confirm_availability.php" class="submenu-item">Confirm Availability</a>
                        <a href="request_change.php" class="submenu-item">Request Change</a>
                        <a href="mark_attendance.php" class="submenu-item active">Mark Attendance</a>
                       
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
                          <a href="../tc/view_available_training.php" class="submenu-item">View Available Training</a>
                        <a href="../tc/submit_training.php" class="submenu-item">Submit Training</a>
                        
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
                        <a href="../il/conduct_inspections.php" class="submenu-item">Conduct Inspections</a>
                        <a href="../il/submit_findings.php" class="submenu-item">Submit Findings</a>
                       
                        <a href="../il/tag_violations.php" class="submenu-item">Tag Violations</a>
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
                        <a href="../pi/post_incident_reporting.php" class="submenu-item">Incident Reports</a>
                        
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
                            <input type="text" placeholder="Search volunteers..." class="search-input" id="search-input">
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
                        <h1 class="dashboard-title">Volunteer Attendance</h1>
                        <p class="dashboard-subtitle">Track and manage volunteer attendance for scheduled shifts</p>
                    </div>
                </div>
                
                <!-- Content Container -->
                <div class="content-container">
                    <!-- Statistics Cards -->
                    <div class="stats-container">
                        <div class="stat-card">
                            <div class="stat-icon pending">
                                <i class='bx bx-time-five'></i>
                            </div>
                            <div class="stat-value"><?php echo $pending_today; ?></div>
                            <div class="stat-label">Pending Today</div>
                            <div class="stat-percentage">
                                <?php echo $total_today > 0 ? number_format(($pending_today/$total_today)*100, 1) : 0; ?>% of total
                            </div>
                        </div>
                        
                        <div class="stat-card">
                            <div class="stat-icon checked-in">
                                <i class='bx bx-log-in'></i>
                            </div>
                            <div class="stat-value"><?php echo $checked_in_today; ?></div>
                            <div class="stat-label">Checked In</div>
                            <div class="stat-percentage">
                                <?php echo $total_today > 0 ? number_format(($checked_in_today/$total_today)*100, 1) : 0; ?>% of total
                            </div>
                        </div>
                        
                        <div class="stat-card">
                            <div class="stat-icon checked-out">
                                <i class='bx bx-log-out'></i>
                            </div>
                            <div class="stat-value"><?php echo $checked_out_today; ?></div>
                            <div class="stat-label">Checked Out</div>
                            <div class="stat-percentage">
                                <?php echo $total_today > 0 ? number_format(($checked_out_today/$total_today)*100, 1) : 0; ?>% of total
                            </div>
                        </div>
                        
                        <div class="stat-card">
                            <div class="stat-icon absent">
                                <i class='bx bx-user-x'></i>
                            </div>
                            <div class="stat-value"><?php echo $total_today; ?></div>
                            <div class="stat-label">Total Shifts Today</div>
                            <div class="stat-percentage">
                                <?php echo date('F j, Y', strtotime($selected_date)); ?>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Date Selector -->
                    <div class="date-selector-container">
                        <h3 class="date-selector-title">
                            <i class='bx bx-calendar'></i>
                            Select Date
                        </h3>
                        
                        <div class="date-grid">
                            <?php foreach ($next_days as $day): 
                                $is_active = $selected_date === $day['date'];
                                $is_today = $day['date'] === $today;
                            ?>
                            <div class="date-option <?php echo $is_active ? 'active' : ''; ?>" 
                                 onclick="window.location.href='mark_attendance.php?date=<?php echo $day['date']; ?>'">
                                <div class="date-day"><?php echo $is_today ? 'Today' : substr($day['display'], 0, 3); ?></div>
                                <div class="date-number"><?php echo date('j', strtotime($day['date'])); ?></div>
                                <div class="date-month"><?php echo date('M', strtotime($day['date'])); ?></div>
                                <?php if ($day['shifts_count'] > 0): ?>
                                    <div class="date-shifts"><?php echo $day['shifts_count']; ?> shifts</div>
                                <?php endif; ?>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        
                        <div class="custom-date-selector">
                            <input type="date" class="form-input" id="custom-date" value="<?php echo $selected_date; ?>">
                            <button type="button" class="btn btn-primary" onclick="goToCustomDate()">
                                <i class='bx bx-calendar-check'></i>
                                Go to Date
                            </button>
                            <?php if ($selected_date !== $today): ?>
                                <button type="button" class="btn btn-secondary" onclick="window.location.href='mark_attendance.php'">
                                    <i class='bx bx-calendar'></i>
                                    Today
                                </button>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <!-- Today's Shifts Table -->
                    <div class="table-container">
                        <div class="table-header">
                            <h3 class="table-title">
                                <i class='bx bx-list-check'></i>
                                Shifts for <?php echo date('F j, Y', strtotime($selected_date)); ?>
                                <span style="font-size: 14px; font-weight: normal; color: var(--text-light); margin-left: 10px;">
                                    <?php echo count($todays_shifts); ?> volunteers scheduled
                                </span>
                            </h3>
                            <div>
                                <button type="button" class="btn btn-primary" onclick="refreshPage()">
                                    <i class='bx bx-refresh'></i>
                                    Refresh
                                </button>
                            </div>
                        </div>
                        
                        <?php if (count($todays_shifts) > 0): ?>
                            <table class="table">
                                <thead>
                                    <tr>
                                        <th>Volunteer</th>
                                        <th>Shift Time & Location</th>
                                        <th>Duty Assignment</th>
                                        <th>Status</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($todays_shifts as $shift): 
                                        $start_time = date('g:i A', strtotime($shift['start_time']));
                                        $end_time = date('g:i A', strtotime($shift['end_time']));
                                        
                                        $duty_type_class = 'duty-' . ($shift['duty_type'] ?? 'other');
                                        $duty_display = $shift['duty_type'] ? str_replace('_', ' ', $shift['duty_type']) : 'Not assigned';
                                        $duty_display = ucwords($duty_display);
                                        
                                        $attendance_status = $shift['attendance_status'] ?? 'pending';
                                        $status_display = ucfirst(str_replace('_', ' ', $attendance_status));
                                        
                                        $check_in_time = $shift['check_in_time'] ? date('g:i A', strtotime($shift['check_in_time'])) : null;
                                        $check_out_time = $shift['check_out_time'] ? date('g:i A', strtotime($shift['check_out_time'])) : null;
                                    ?>
                                    <tr>
                                        <td>
                                            <div class="volunteer-info">
                                                <div class="volunteer-avatar">
                                                    <?php echo strtoupper(substr($shift['first_name'], 0, 1)); ?>
                                                </div>
                                                <div class="volunteer-details">
                                                    <div class="volunteer-name">
                                                        <?php echo htmlspecialchars($shift['first_name'] . ' ' . $shift['last_name']); ?>
                                                    </div>
                                                    <div class="volunteer-contact">
                                                        <i class='bx bx-phone'></i> <?php echo htmlspecialchars($shift['contact_number']); ?>
                                                    </div>
                                                </div>
                                            </div>
                                        </td>
                                        <td>
                                            <div class="shift-details">
                                                <div class="shift-time">
                                                    <?php echo $start_time; ?> - <?php echo $end_time; ?>
                                                </div>
                                                <?php if ($shift['location']): ?>
                                                    <div class="shift-location">
                                                        <i class='bx bx-map'></i> <?php echo htmlspecialchars($shift['location']); ?>
                                                    </div>
                                                <?php endif; ?>
                                                <?php if ($shift['unit_name']): ?>
                                                    <div class="unit-badge">
                                                        <?php echo htmlspecialchars($shift['unit_name']); ?>
                                                    </div>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                        <td>
                                            <?php if ($shift['duty_type']): ?>
                                                <div class="duty-badge <?php echo $duty_type_class; ?>">
                                                    <?php echo $duty_display; ?>
                                                </div>
                                                <?php if ($shift['duty_description']): ?>
                                                    <div style="font-size: 11px; color: var(--text-light); margin-top: 4px;">
                                                        <?php echo htmlspecialchars(substr($shift['duty_description'], 0, 50)); ?>...
                                                    </div>
                                                <?php endif; ?>
                                            <?php else: ?>
                                                <span style="color: var(--text-light); font-style: italic;">No duty assigned</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div class="attendance-status-badge status-<?php echo $attendance_status; ?>">
                                                <?php echo $status_display; ?>
                                            </div>
                                            <?php if ($check_in_time): ?>
                                                <div class="time-info">
                                                    <i class='bx bx-log-in'></i> <?php echo $check_in_time; ?>
                                                </div>
                                            <?php endif; ?>
                                            <?php if ($check_out_time): ?>
                                                <div class="time-info">
                                                    <i class='bx bx-log-out'></i> <?php echo $check_out_time; ?>
                                                </div>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div class="action-buttons">
                                                <?php if ($attendance_status === 'pending'): ?>
                                                    <button type="button" class="btn btn-success btn-sm mark-attendance-btn"
                                                            data-shift-id="<?php echo $shift['shift_id']; ?>"
                                                            data-volunteer-name="<?php echo htmlspecialchars($shift['first_name'] . ' ' . $shift['last_name']); ?>"
                                                            data-shift-time="<?php echo $start_time . ' - ' . $end_time; ?>"
                                                            data-action="check_in">
                                                        <i class='bx bx-log-in'></i> Check In
                                                    </button>
                                                    <button type="button" class="btn btn-danger btn-sm mark-attendance-btn"
                                                            data-shift-id="<?php echo $shift['shift_id']; ?>"
                                                            data-volunteer-name="<?php echo htmlspecialchars($shift['first_name'] . ' ' . $shift['last_name']); ?>"
                                                            data-shift-time="<?php echo $start_time . ' - ' . $end_time; ?>"
                                                            data-action="mark_absent">
                                                        <i class='bx bx-user-x'></i> Absent
                                                    </button>
                                                    <button type="button" class="btn btn-warning btn-sm mark-attendance-btn"
                                                            data-shift-id="<?php echo $shift['shift_id']; ?>"
                                                            data-volunteer-name="<?php echo htmlspecialchars($shift['first_name'] . ' ' . $shift['last_name']); ?>"
                                                            data-shift-time="<?php echo $start_time . ' - ' . $end_time; ?>"
                                                            data-action="mark_excused">
                                                        <i class='bx bx-user-check'></i> Excused
                                                    </button>
                                                <?php elseif ($attendance_status === 'checked_in'): ?>
                                                    <button type="button" class="btn btn-info btn-sm mark-attendance-btn"
                                                            data-shift-id="<?php echo $shift['shift_id']; ?>"
                                                            data-volunteer-name="<?php echo htmlspecialchars($shift['first_name'] . ' ' . $shift['last_name']); ?>"
                                                            data-shift-time="<?php echo $start_time . ' - ' . $end_time; ?>"
                                                            data-checkin-time="<?php echo $check_in_time; ?>"
                                                            data-action="check_out">
                                                        <i class='bx bx-log-out'></i> Check Out
                                                    </button>
                                                <?php elseif ($attendance_status === 'checked_out'): ?>
                                                    <span style="color: var(--success); font-size: 12px;">
                                                        <i class='bx bx-check-circle'></i> Shift Completed
                                                    </span>
                                                <?php elseif (in_array($attendance_status, ['absent', 'excused'])): ?>
                                                    <span style="color: var(--text-light); font-size: 12px; font-style: italic;">
                                                        Attendance already marked
                                                    </span>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php else: ?>
                            <div class="empty-state">
                                <i class='bx bx-calendar-x'></i>
                                <h3>No Shifts Scheduled</h3>
                                <p>There are no volunteer shifts scheduled for <?php echo date('F j, Y', strtotime($selected_date)); ?>.</p>
                            </div>
                        <?php endif; ?>
                    </div>
                    
                    <!-- Upcoming Shifts Table -->
                    <div class="table-container">
                        <div class="table-header">
                            <h3 class="table-title">
                                <i class='bx bx-calendar-plus'></i>
                                Upcoming Shifts (Next 14 Days)
                                <span style="font-size: 14px; font-weight: normal; color: var(--text-light); margin-left: 10px;">
                                    <?php echo count($upcoming_shifts); ?> shifts scheduled
                                </span>
                            </h3>
                        </div>
                        
                        <?php if (count($upcoming_shifts) > 0): ?>
                            <table class="table">
                                <thead>
                                    <tr>
                                        <th>Date</th>
                                        <th>Volunteer</th>
                                        <th>Shift Time</th>
                                        <th>Duty Assignment</th>
                                        <th>Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php 
                                    $current_date = null;
                                    foreach ($upcoming_shifts as $shift): 
                                        $shift_date = date('D, M j', strtotime($shift['shift_date']));
                                        $start_time = date('g:i A', strtotime($shift['start_time']));
                                        $end_time = date('g:i A', strtotime($shift['end_time']));
                                        
                                        $duty_type_class = 'duty-' . ($shift['duty_type'] ?? 'other');
                                        $duty_display = $shift['duty_type'] ? str_replace('_', ' ', $shift['duty_type']) : 'Not assigned';
                                        $duty_display = ucwords($duty_display);
                                        
                                        $attendance_status = $shift['attendance_status'] ?? 'pending';
                                        $status_display = ucfirst(str_replace('_', ' ', $attendance_status));
                                        
                                        // Check if we need to show date header
                                        $show_date_header = $current_date !== $shift['shift_date'];
                                        $current_date = $shift['shift_date'];
                                    ?>
                                    <?php if ($show_date_header): ?>
                                    <tr style="background: rgba(220, 38, 38, 0.03);">
                                        <td colspan="5" style="font-weight: 600; color: var(--primary-color);">
                                            <i class='bx bx-calendar'></i> <?php echo $shift_date; ?>
                                        </td>
                                    </tr>
                                    <?php endif; ?>
                                    <tr>
                                        <td>
                                            <div style="color: var(--text-light); font-size: 12px;">
                                                <?php echo date('D', strtotime($shift['shift_date'])); ?>
                                            </div>
                                        </td>
                                        <td>
                                            <div class="volunteer-info">
                                                <div class="volunteer-avatar" style="width: 32px; height: 32px; font-size: 14px;">
                                                    <?php echo strtoupper(substr($shift['first_name'], 0, 1)); ?>
                                                </div>
                                                <div class="volunteer-details">
                                                    <div class="volunteer-name" style="font-size: 13px;">
                                                        <?php echo htmlspecialchars($shift['first_name'] . ' ' . $shift['last_name']); ?>
                                                    </div>
                                                </div>
                                            </div>
                                        </td>
                                        <td>
                                            <div style="font-size: 13px;">
                                                <?php echo $start_time; ?> - <?php echo $end_time; ?>
                                            </div>
                                            <?php if ($shift['location']): ?>
                                                <div style="font-size: 11px; color: var(--text-light);">
                                                    <?php echo htmlspecialchars($shift['location']); ?>
                                                </div>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($shift['duty_type']): ?>
                                                <div class="duty-badge <?php echo $duty_type_class; ?>" style="font-size: 10px; padding: 2px 6px;">
                                                    <?php echo $duty_display; ?>
                                                </div>
                                            <?php else: ?>
                                                <span style="color: var(--text-light); font-size: 11px; font-style: italic;">None</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div class="attendance-status-badge status-<?php echo $attendance_status; ?>" style="font-size: 11px; padding: 4px 8px;">
                                                <?php echo $status_display; ?>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php else: ?>
                            <div class="empty-state">
                                <i class='bx bx-calendar'></i>
                                <h3>No Upcoming Shifts</h3>
                                <p>There are no upcoming volunteer shifts scheduled for the next 14 days.</p>
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
            
            // Setup attendance modal
            setupAttendanceModal();
            
            // Setup search functionality
            setupSearch();
            
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
        }
        
        function setupAttendanceModal() {
            const attendanceModal = document.getElementById('attendance-modal');
            const modalClose = document.getElementById('modal-close');
            const closeModal = document.getElementById('close-modal');
            const attendanceButtons = document.querySelectorAll('.mark-attendance-btn');
            
            modalClose.addEventListener('click', () => attendanceModal.classList.remove('active'));
            closeModal.addEventListener('click', () => attendanceModal.classList.remove('active'));
            
            attendanceModal.addEventListener('click', function(e) {
                if (e.target === this) {
                    attendanceModal.classList.remove('active');
                }
            });
            
            attendanceButtons.forEach(button => {
                button.addEventListener('click', function() {
                    const shiftId = this.getAttribute('data-shift-id');
                    const volunteerName = this.getAttribute('data-volunteer-name');
                    const shiftTime = this.getAttribute('data-shift-time');
                    const action = this.getAttribute('data-action');
                    const checkinTime = this.getAttribute('data-checkin-time');
                    
                    // Set modal content based on action
                    document.getElementById('modal-shift-id').value = shiftId;
                    document.getElementById('modal-action').value = action;
                    document.getElementById('volunteer-info').textContent = volunteerName;
                    document.getElementById('shift-info').textContent = shiftTime;
                    
                    let title = '';
                    let submitText = '';
                    let submitClass = '';
                    let confirmationText = '';
                    let showConfirmation = false;
                    
                    switch (action) {
                        case 'check_in':
                            title = 'Check In Volunteer';
                            submitText = 'Check In';
                            submitClass = 'btn-success';
                            confirmationText = 'Are you sure you want to check in this volunteer? This will mark them as present and start tracking their shift time.';
                            showConfirmation = true;
                            break;
                        case 'check_out':
                            title = 'Check Out Volunteer';
                            submitText = 'Check Out';
                            submitClass = 'btn-info';
                            confirmationText = 'Are you sure you want to check out this volunteer? This will mark the shift as completed and calculate total hours worked.';
                            showConfirmation = true;
                            break;
                        case 'mark_absent':
                            title = 'Mark as Absent';
                            submitText = 'Mark Absent';
                            submitClass = 'btn-danger';
                            confirmationText = 'Are you sure you want to mark this volunteer as absent? This cannot be undone.';
                            showConfirmation = true;
                            break;
                        case 'mark_excused':
                            title = 'Mark as Excused';
                            submitText = 'Mark Excused';
                            submitClass = 'btn-warning';
                            confirmationText = 'Are you sure you want to mark this volunteer as excused? This cannot be undone.';
                            showConfirmation = true;
                            break;
                    }
                    
                    document.getElementById('modal-title').textContent = title;
                    document.getElementById('submit-text').textContent = submitText;
                    
                    // Update submit button class
                    const submitBtn = document.getElementById('submit-btn');
                    submitBtn.className = 'btn ' + submitClass;
                    
                    // Show/hide confirmation message
                    const confirmationDiv = document.getElementById('confirmation-message');
                    const confirmationTextDiv = document.getElementById('confirmation-text');
                    
                    if (showConfirmation) {
                        confirmationDiv.style.display = 'block';
                        confirmationTextDiv.textContent = confirmationText;
                    } else {
                        confirmationDiv.style.display = 'none';
                    }
                    
                    // Clear notes
                    document.getElementById('attendance_notes').value = '';
                    
                    attendanceModal.classList.add('active');
                });
            });
        }
        
        function setupSearch() {
            const searchInput = document.getElementById('search-input');
            const tableRows = document.querySelectorAll('.table tbody tr');
            
            searchInput.addEventListener('input', function() {
                const searchTerm = this.value.toLowerCase().trim();
                
                tableRows.forEach(row => {
                    const rowText = row.textContent.toLowerCase();
                    if (rowText.includes(searchTerm)) {
                        row.style.display = '';
                    } else {
                        row.style.display = 'none';
                    }
                });
            });
        }
        
        function goToCustomDate() {
            const dateInput = document.getElementById('custom-date');
            if (dateInput.value) {
                window.location.href = 'mark_attendance.php?date=' + dateInput.value;
            }
        }
        
        function refreshPage() {
            window.location.reload();
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