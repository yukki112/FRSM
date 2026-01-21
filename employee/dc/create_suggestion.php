<?php
// create_suggestion.php
require_once '../../config/db_connection.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit();
}

$data = json_decode(file_get_contents('php://input'), true);

$incident_id = $data['incident_id'] ?? null;
$unit_id = $data['unit_id'] ?? null;
$vehicles = $data['vehicles'] ?? [];
$suggested_by = $data['suggested_by'] ?? null;

if (!$incident_id || !$unit_id) {
    echo json_encode(['success' => false, 'message' => 'Missing required fields']);
    exit();
}

try {
    $pdo->beginTransaction();
    
    // Check if unit is available
    $unit_check = "SELECT current_status FROM units WHERE id = ?";
    $unit_stmt = $pdo->prepare($unit_check);
    $unit_stmt->execute([$unit_id]);
    $unit_status = $unit_stmt->fetchColumn();
    
    if ($unit_status !== 'available') {
        throw new Exception('Unit is not available');
    }
    
    // Check if incident is ready for dispatch
    $incident_check = "SELECT dispatch_status FROM api_incidents WHERE id = ?";
    $incident_stmt = $pdo->prepare($incident_check);
    $incident_stmt->execute([$incident_id]);
    $incident_status = $incident_stmt->fetchColumn();
    
    if ($incident_status !== 'for_dispatch') {
        throw new Exception('Incident is not ready for dispatch');
    }
    
    // Create suggestion in dispatch_incidents
    $insert_query = "
        INSERT INTO dispatch_incidents 
        (incident_id, unit_id, vehicles_json, suggested_by, dispatched_at, status)
        VALUES (?, ?, ?, ?, NOW(), 'pending')
    ";
    
    $vehicles_json = json_encode($vehicles);
    $insert_stmt = $pdo->prepare($insert_query);
    $insert_stmt->execute([$incident_id, $unit_id, $vehicles_json, $suggested_by]);
    
    $suggestion_id = $pdo->lastInsertId();
    
    // Update incident status
    $update_incident = "
        UPDATE api_incidents 
        SET dispatch_status = 'processing',
            dispatch_id = ?,
            status = 'processing'
        WHERE id = ?
    ";
    $update_stmt = $pdo->prepare($update_incident);
    $update_stmt->execute([$suggestion_id, $incident_id]);
    
    // Update unit status to 'suggested'
    $update_unit = "
        UPDATE units 
        SET current_status = 'suggested',
            current_dispatch_id = ?
        WHERE id = ?
    ";
    $unit_stmt = $pdo->prepare($update_unit);
    $unit_stmt->execute([$suggestion_id, $unit_id]);
    
    // Update vehicle status to 'suggested'
    foreach ($vehicles as $vehicle) {
        // Check if vehicle exists
        $vehicle_check = "SELECT id FROM vehicle_status WHERE vehicle_id = ?";
        $vehicle_stmt = $pdo->prepare($vehicle_check);
        $vehicle_stmt->execute([$vehicle['id']]);
        $vehicle_exists = $vehicle_stmt->fetchColumn();
        
        if ($vehicle_exists) {
            // Update existing vehicle
            $update_vehicle = "
                UPDATE vehicle_status 
                SET status = 'suggested',
                    suggestion_id = ?,
                    last_updated = NOW()
                WHERE vehicle_id = ?
            ";
            $update_vehicle_stmt = $pdo->prepare($update_vehicle);
            $update_vehicle_stmt->execute([$suggestion_id, $vehicle['id']]);
        } else {
            // Insert new vehicle
            $insert_vehicle = "
                INSERT INTO vehicle_status 
                (vehicle_id, vehicle_name, vehicle_type, unit_id, suggestion_id, status, last_updated)
                VALUES (?, ?, ?, ?, ?, 'suggested', NOW())
            ";
            $insert_vehicle_stmt = $pdo->prepare($insert_vehicle);
            $insert_vehicle_stmt->execute([
                $vehicle['id'],
                $vehicle['vehicle_name'],
                $vehicle['type'],
                $unit_id,
                $suggestion_id
            ]);
        }
    }
    
    $pdo->commit();
    
    echo json_encode([
        'success' => true,
        'message' => 'Suggestion created successfully',
        'suggestion_id' => $suggestion_id,
        'created_at' => date('Y-m-d H:i:s')
    ]);
    
} catch (Exception $e) {
    $pdo->rollBack();
    echo json_encode([
        'success' => false,
        'message' => 'Failed to create suggestion: ' . $e->getMessage()
    ]);
}
?>