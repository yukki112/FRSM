<?php
session_start();
require_once '../../config/db_connection.php';

if (!isset($_SESSION['user_id'])) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

$user_id = $_SESSION['user_id'];
$shift_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if ($shift_id <= 0) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Invalid shift ID']);
    exit();
}

// Check if user is admin
$user_query = "SELECT role FROM users WHERE id = ?";
$user_stmt = $pdo->prepare($user_query);
$user_stmt->execute([$user_id]);
$user = $user_stmt->fetch();

if (!$user || $user['role'] !== 'ADMIN') {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Access denied']);
    exit();
}

// Get shift details with all related information
$sql = "SELECT 
            s.id,
            s.user_id,
            s.volunteer_id,
            s.shift_for,
            s.unit_id,
            s.shift_date,
            s.shift_type,
            s.start_time,
            s.end_time,
            s.status,
            s.location,
            s.notes,
            s.created_at,
            s.updated_at,
            s.duty_assignment_id,
            s.confirmation_status,
            s.check_in_time,
            s.check_out_time,
            s.attendance_status,
            s.attendance_notes,
            u.unit_name,
            u.unit_code,
            u.unit_type,
            CONCAT(creator.first_name, ' ', creator.last_name) as created_by_name,
            da.duty_type,
            da.duty_description,
            da.priority,
            da.required_equipment,
            da.required_training,
            CASE 
                WHEN s.shift_for = 'user' THEN CONCAT(usr.first_name, ' ', usr.last_name)
                WHEN s.shift_for = 'volunteer' THEN CONCAT(v.first_name, ' ', v.last_name)
                ELSE 'Unassigned'
            END as assigned_to_name,
            CASE 
                WHEN s.shift_for = 'user' THEN usr.email
                WHEN s.shift_for = 'volunteer' THEN v.email
                ELSE ''
            END as assigned_to_email
        FROM shifts s
        LEFT JOIN units u ON s.unit_id = u.id
        LEFT JOIN users creator ON s.created_by = creator.id
        LEFT JOIN users usr ON s.user_id = usr.id
        LEFT JOIN volunteers v ON s.volunteer_id = v.id
        LEFT JOIN duty_assignments da ON s.duty_assignment_id = da.id
        WHERE s.id = ?";
    
$stmt = $pdo->prepare($sql);
$stmt->execute([$shift_id]);
$shift = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$shift) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Shift not found']);
    exit();
}

// Return JSON response
header('Content-Type: application/json');
echo json_encode([
    'success' => true,
    'shift' => $shift
]);
?>