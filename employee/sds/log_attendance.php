<?php
session_start();
require_once '../../config/db_connection.php';

if (!isset($_SESSION['user_id'])) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

if (!isset($_POST['shift_id']) || !isset($_POST['check_in'])) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Missing required fields']);
    exit();
}

$user_id = $_SESSION['user_id'];
$shift_id = intval($_POST['shift_id']);
$check_in = $_POST['check_in'];
$check_out = isset($_POST['check_out']) ? $_POST['check_out'] : null;
$notes = isset($_POST['attendance_notes']) ? $_POST['attendance_notes'] : null;

// Verify user has permission to log attendance for this shift
$shift_query = "SELECT * FROM shifts WHERE id = ? AND (user_id = ? OR volunteer_id IN (SELECT id FROM volunteers WHERE user_id = ?))";
$shift_stmt = $pdo->prepare($shift_query);
$shift_stmt->execute([$shift_id, $user_id, $user_id]);
$shift = $shift_stmt->fetch();

if (!$shift) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'You do not have permission to log attendance for this shift']);
    exit();
}

// Check if attendance already logged
$attendance_query = "SELECT * FROM attendance_logs WHERE shift_id = ? AND user_id = ?";
$attendance_stmt = $pdo->prepare($attendance_query);
$attendance_stmt->execute([$shift_id, $user_id]);
$existing_attendance = $attendance_stmt->fetch();

if ($existing_attendance) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Attendance already logged for this shift']);
    exit();
}

// Calculate total hours if check-out is provided
$total_hours = null;
if ($check_out) {
    $check_in_time = new DateTime($check_in);
    $check_out_time = new DateTime($check_out);
    $interval = $check_in_time->diff($check_out_time);
    $total_hours = $interval->h + ($interval->i / 60);
}

// Insert attendance log
$insert_query = "INSERT INTO attendance_logs (shift_id, user_id, check_in, check_out, attendance_status, total_hours, notes, created_at, updated_at) 
                 VALUES (?, ?, ?, ?, 'present', ?, ?, NOW(), NOW())";
$insert_stmt = $pdo->prepare($insert_query);
$result = $insert_stmt->execute([$shift_id, $user_id, $check_in, $check_out, $total_hours, $notes]);

if ($result) {
    // Update shift status to completed if check-out was logged
    if ($check_out) {
        $update_shift_query = "UPDATE shifts SET status = 'completed', updated_at = NOW() WHERE id = ?";
        $update_shift_stmt = $pdo->prepare($update_shift_query);
        $update_shift_stmt->execute([$shift_id]);
    }
    
    header('Content-Type: application/json');
    echo json_encode(['success' => true, 'message' => 'Attendance logged successfully']);
} else {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Failed to log attendance']);
}
?>