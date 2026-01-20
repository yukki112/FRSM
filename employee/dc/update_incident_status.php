<?php
// update_incident_status.php
require_once '../../config/db_connection.php';
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit();
}

$data = json_decode(file_get_contents('php://input'), true);
$incident_id = $data['incident_id'] ?? null;
$status = $data['status'] ?? null;
$suggestion_id = $data['suggestion_id'] ?? null;

if (!$incident_id || !$status) {
    echo json_encode(['success' => false, 'message' => 'Missing required data']);
    exit();
}

try {
    $query = "UPDATE api_incidents SET dispatch_status = ?";
    $params = [$status];
    
    if ($suggestion_id) {
        $query .= ", dispatch_id = ?";
        $params[] = $suggestion_id;
    }
    
    $query .= " WHERE id = ?";
    $params[] = $incident_id;
    
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    
    echo json_encode([
        'success' => true,
        'message' => 'Incident status updated',
        'incident_id' => $incident_id,
        'new_status' => $status
    ]);
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Failed to update incident status: ' . $e->getMessage()
    ]);
}
?>