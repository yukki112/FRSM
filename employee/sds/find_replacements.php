<?php
// find_replacements.php
session_start();
require_once '../../config/db_connection.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode([]);
    exit();
}

$shift_id = $_GET['shift_id'] ?? null;
$exclude_volunteer_id = $_GET['exclude_volunteer_id'] ?? null;

if (!$shift_id || !$exclude_volunteer_id) {
    echo json_encode([]);
    exit();
}

// Function from the main file
function findAvailableReplacements($pdo, $shift_id, $exclude_volunteer_id) {
    $sql = "SELECT 
                v.id,
                v.first_name,
                v.last_name,
                v.contact_number,
                v.email,
                v.volunteer_status,
                v.available_days,
                v.available_hours,
                v.skills_basic_firefighting,
                v.skills_first_aid_cpr,
                v.skills_search_rescue,
                u.unit_name,
                u.unit_code,
                va.assignment_date,
                COUNT(DISTINCT s2.id) as assigned_shifts_on_date,
                (SELECT COUNT(*) FROM shifts s3 
                 WHERE s3.volunteer_id = v.id 
                 AND s3.confirmation_status = 'confirmed'
                 AND s3.shift_date BETWEEN DATE_SUB(CURDATE(), INTERVAL 30 DAY) AND CURDATE()
                ) as confirmed_past_month
            FROM volunteers v
            LEFT JOIN volunteer_assignments va ON v.id = va.volunteer_id AND va.status = 'Active'
            LEFT JOIN units u ON va.unit_id = u.id
            LEFT JOIN shifts s2 ON v.id = s2.volunteer_id 
                AND s2.shift_for = 'volunteer'
                AND s2.shift_date = (SELECT shift_date FROM shifts WHERE id = ?)
            WHERE v.status = 'approved'
            AND v.volunteer_status IN ('Active', 'New Volunteer')
            AND v.id != ?";
    
    $params = [$shift_id, $exclude_volunteer_id];
    
    $sql .= " GROUP BY v.id, v.first_name, v.last_name, v.contact_number, v.email, 
                v.volunteer_status, v.available_days, v.available_hours, 
                v.skills_basic_firefighting, v.skills_first_aid_cpr, v.skills_search_rescue,
                u.unit_name, u.unit_code, va.assignment_date
            HAVING assigned_shifts_on_date = 0
            ORDER BY confirmed_past_month DESC, va.assignment_date DESC
            LIMIT 10";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

$replacements = findAvailableReplacements($pdo, $shift_id, $exclude_volunteer_id);
echo json_encode($replacements);
?>