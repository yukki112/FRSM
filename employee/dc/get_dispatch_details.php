<?php
require_once '../../config/db_connection.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit();
}

$data = json_decode(file_get_contents('php://input'), true);
$dispatch_id = $data['dispatch_id'] ?? null;

if (!$dispatch_id) {
    echo json_encode(['success' => false, 'message' => 'No dispatch ID provided']);
    exit();
}

try {
    // Get dispatch details
    $query = "
        SELECT 
            di.*,
            ai.title as incident_title,
            ai.location as incident_location,
            ai.emergency_type as incident_type,
            ai.severity as incident_severity,
            u.unit_name,
            u.unit_code,
            u.unit_type,
            u.location as unit_location,
            u.current_status as unit_status,
            CONCAT(us.first_name, ' ', us.last_name) as dispatcher_name
        FROM dispatch_incidents di
        JOIN api_incidents ai ON di.incident_id = ai.id
        JOIN units u ON di.unit_id = u.id
        LEFT JOIN users us ON di.dispatched_by = us.id
        WHERE di.id = ?
    ";
    
    $stmt = $pdo->prepare($query);
    $stmt->execute([$dispatch_id]);
    $dispatch = $stmt->fetch();
    
    if (!$dispatch) {
        echo json_encode(['success' => false, 'message' => 'Dispatch not found']);
        exit();
    }
    
    // Get vehicles for this dispatch
    $vehicles = [];
    if ($dispatch['vehicles_json']) {
        $vehicles = json_decode($dispatch['vehicles_json'], true);
        if (!is_array($vehicles)) {
            $vehicles = [];
        }
    }
    
    // Get volunteers for this unit
    $volunteers_query = "
        SELECT v.full_name, v.contact_number, v.email
        FROM volunteer_assignments va
        JOIN volunteers v ON va.volunteer_id = v.id
        WHERE va.unit_id = ? AND va.status = 'Active' AND v.status = 'approved'
    ";
    $volunteers_stmt = $pdo->prepare($volunteers_query);
    $volunteers_stmt->execute([$dispatch['unit_id']]);
    $volunteers = $volunteers_stmt->fetchAll();
    
    $dispatch['vehicles'] = $vehicles;
    $dispatch['volunteers'] = $volunteers;
    
    echo json_encode([
        'success' => true,
        'dispatch' => $dispatch
    ]);
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Error: ' . $e->getMessage()
    ]);
}
?>