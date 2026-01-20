<?php
session_start();
require_once '../../config/db_connection.php';

if (!isset($_SESSION['user_id'])) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

if (!isset($_GET['id'])) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Shift ID required']);
    exit();
}

$shift_id = intval($_GET['id']);

$query = "SELECT 
            s.*,
            u.unit_name,
            u.unit_code,
            u.unit_type,
            a.check_in,
            a.check_out,
            a.attendance_status,
            a.total_hours,
            a.overtime_hours,
            a.notes as attendance_notes,
            CONCAT(creator.first_name, ' ', creator.last_name) as created_by_name,
            CASE 
                WHEN s.shift_for = 'user' THEN CONCAT(usr.first_name, ' ', usr.last_name)
                WHEN s.shift_for = 'volunteer' THEN CONCAT(v.first_name, ' ', v.last_name)
                ELSE 'Not assigned'
            END as assigned_to
          FROM shifts s
          LEFT JOIN units u ON s.unit_id = u.id
          LEFT JOIN attendance_logs a ON s.id = a.shift_id
          LEFT JOIN users creator ON s.created_by = creator.id
          LEFT JOIN users usr ON s.user_id = usr.id
          LEFT JOIN volunteers v ON s.volunteer_id = v.id
          WHERE s.id = ?";

$stmt = $pdo->prepare($query);
$stmt->execute([$shift_id]);
$shift = $stmt->fetch(PDO::FETCH_ASSOC);

if ($shift) {
    // Add shift type icon
    $shift_type_icons = [
        'morning' => 'bx-sun',
        'afternoon' => 'bx-cloud',
        'evening' => 'bx-moon',
        'night' => 'bx-bed',
        'full_day' => 'bx-calendar'
    ];
    
    $shift['shift_type_icon'] = $shift_type_icons[strtolower($shift['shift_type'])] ?? 'bx-time';
    $shift['shift_type'] = ucfirst(str_replace('_', ' ', $shift['shift_type']));
    
    header('Content-Type: application/json');
    echo json_encode(['success' => true, 'shift' => $shift]);
} else {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Shift not found']);
}
?>