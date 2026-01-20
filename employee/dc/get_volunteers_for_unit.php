<?php
// get_volunteers_for_unit.php
require_once '../../config/db_connection.php';
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit();
}

$data = json_decode(file_get_contents('php://input'), true);
$unit_id = $data['unit_id'] ?? null;

if (!$unit_id) {
    echo json_encode(['success' => false, 'message' => 'No unit ID provided']);
    exit();
}

try {
    // Get volunteers assigned to this unit
    $volunteers_query = "
        SELECT v.* 
        FROM volunteers v
        JOIN volunteer_assignments va ON v.id = va.volunteer_id
        WHERE va.unit_id = ? 
          AND v.status = 'approved' 
          AND va.status = 'Active'
        ORDER BY v.full_name
    ";
    
    $volunteers_stmt = $pdo->prepare($volunteers_query);
    $volunteers_stmt->execute([$unit_id]);
    $volunteers = $volunteers_stmt->fetchAll();
    
    echo json_encode([
        'success' => true,
        'volunteers' => $volunteers,
        'count' => count($volunteers)
    ]);
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Failed to get volunteers: ' . $e->getMessage(),
        'volunteers' => [],
        'count' => 0
    ]);
}
?>