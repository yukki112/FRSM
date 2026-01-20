<?php
session_start();
require_once '../../config/db_connection.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode([]);
    exit();
}

$user_id = $_SESSION['user_id'];
$date = $_GET['date'] ?? '';
$volunteer_id = $_GET['volunteer_id'] ?? 0;

if (!$date || !$volunteer_id) {
    echo json_encode([]);
    exit();
}

// Get volunteer's shifts for the specific date
$query = "
    SELECT s.*, u.unit_name, u.unit_code,
           DATE_FORMAT(s.start_time, '%h:%i %p') as start_time,
           DATE_FORMAT(s.end_time, '%h:%i %p') as end_time,
           CASE 
               WHEN s.shift_type = 'morning' THEN '🌅 Morning Shift'
               WHEN s.shift_type = 'afternoon' THEN '☀️ Afternoon Shift'
               WHEN s.shift_type = 'evening' THEN '🌆 Evening Shift'
               WHEN s.shift_type = 'night' THEN '🌙 Night Shift'
               WHEN s.shift_type = 'full_day' THEN '🌞 Full Day Shift'
           END as shift_type_display
    FROM shifts s 
    LEFT JOIN units u ON s.unit_id = u.id 
    WHERE s.volunteer_id = ? 
    AND s.shift_date = ?
    ORDER BY s.start_time
";

$stmt = $pdo->prepare($query);
$stmt->execute([$volunteer_id, $date]);
$shifts = $stmt->fetchAll();

echo json_encode($shifts);
?>