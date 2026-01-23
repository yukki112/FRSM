<?php
session_start();
require_once '../../config/db_connection.php';

if (!isset($_SESSION['user_id'])) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

$user_id = $_SESSION['user_id'];
$query = "SELECT role FROM users WHERE id = ?";
$stmt = $pdo->prepare($query);
$stmt->execute([$user_id]);
$user = $stmt->fetch();

if (!$user || $user['role'] !== 'ADMIN') {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Invalid violation ID']);
    exit();
}

$violation_id = intval($_GET['id']);

$sql = "SELECT 
            iv.*,
            ir.report_number,
            ir.inspection_date,
            ie.establishment_name,
            ie.establishment_type,
            ie.barangay,
            ie.address,
            ie.owner_name,
            ie.owner_contact,
            CONCAT(inspector.first_name, ' ', inspector.last_name) as inspector_name
        FROM inspection_violations iv
        LEFT JOIN inspection_reports ir ON iv.inspection_id = ir.id
        LEFT JOIN inspection_establishments ie ON ir.establishment_id = ie.id
        LEFT JOIN users inspector ON ir.inspected_by = inspector.id
        WHERE iv.id = ?";

$stmt = $pdo->prepare($sql);
$stmt->execute([$violation_id]);
$violation = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$violation) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Violation not found']);
    exit();
}

header('Content-Type: application/json');
echo json_encode([
    'success' => true,
    'violation' => $violation
]);
?>