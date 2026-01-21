<?php
session_start();
require_once '../../config/db_connection.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

$user_id = $_SESSION['user_id'];
$shift_id = isset($_POST['shift_id']) ? intval($_POST['shift_id']) : 0;
$attendance_status = isset($_POST['attendance_status']) ? $_POST['attendance_status'] : '';
$check_in = isset($_POST['check_in']) ? $_POST['check_in'] : null;
$check_out = isset($_POST['check_out']) ? $_POST['check_out'] : null;
$attendance_notes = isset($_POST['attendance_notes']) ? $_POST['attendance_notes'] : '';

if ($shift_id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid shift ID']);
    exit();
}

// Check if user is admin
$user_query = "SELECT role FROM users WHERE id = ?";
$user_stmt = $pdo->prepare($user_query);
$user_stmt->execute([$user_id]);
$user = $user_stmt->fetch();

if (!$user || $user['role'] !== 'ADMIN') {
    echo json_encode(['success' => false, 'message' => 'Access denied']);
    exit();
}

// Get shift info to know if it's for user or volunteer
$shift_sql = "SELECT user_id, volunteer_id, shift_for FROM shifts WHERE id = ?";
$shift_stmt = $pdo->prepare($shift_sql);
$shift_stmt->execute([$shift_id]);
$shift = $shift_stmt->fetch();

if (!$shift) {
    echo json_encode(['success' => false, 'message' => 'Shift not found']);
    exit();
}

// Update shift attendance
$sql = "UPDATE shifts SET 
            attendance_status = ?,
            check_in_time = ?,
            check_out_time = ?,
            attendance_notes = ?,
            updated_at = NOW()
        WHERE id = ?";
    
$stmt = $pdo->prepare($sql);
$success = $stmt->execute([$attendance_status, $check_in, $check_out, $attendance_notes, $shift_id]);

if ($success) {
    // Also update attendance_logs if needed
    if ($check_in || $check_out) {
        // Check if attendance log exists
        $log_sql = "SELECT id FROM attendance_logs WHERE shift_id = ?";
        $log_stmt = $pdo->prepare($log_sql);
        $log_stmt->execute([$shift_id]);
        $log = $log_stmt->fetch();
        
        if ($log) {
            // Update existing log
            $update_log_sql = "UPDATE attendance_logs SET 
                                check_in = ?,
                                check_out = ?,
                                attendance_status = ?,
                                notes = ?,
                                updated_at = NOW()
                              WHERE shift_id = ?";
            $update_log_stmt = $pdo->prepare($update_log_sql);
            $update_log_stmt->execute([$check_in, $check_out, $attendance_status, $attendance_notes, $shift_id]);
        } else {
            // Create new log
            $insert_log_sql = "INSERT INTO attendance_logs (shift_id, volunteer_id, user_id, shift_date, check_in, check_out, attendance_status, notes)
                              SELECT s.id, s.volunteer_id, s.user_id, s.shift_date, ?, ?, ?, ?
                              FROM shifts s WHERE s.id = ?";
            $insert_log_stmt = $pdo->prepare($insert_log_sql);
            $insert_log_stmt->execute([$check_in, $check_out, $attendance_status, $attendance_notes, $shift_id]);
        }
    }
    
    // Create notification
    $notification_sql = "INSERT INTO notifications (user_id, type, title, message) 
                        VALUES (?, 'attendance_updated', 'Attendance Updated', 'Attendance for Shift #$shift_id has been updated by admin')";
    $notification_stmt = $pdo->prepare($notification_sql);
    $notification_stmt->execute([$user_id]);
    
    echo json_encode(['success' => true, 'message' => 'Attendance updated successfully']);
} else {
    echo json_encode(['success' => false, 'message' => 'Failed to update attendance']);
}
?>