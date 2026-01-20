<?php
// create_suggestion.php - UPDATED VERSION
require_once '../../config/db_connection.php';
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit();
}

$data = json_decode(file_get_contents('php://input'), true);
$incident_id = $data['incident_id'] ?? null;
$unit_id = $data['unit_id'] ?? null;
$unit_name = $data['unit_name'] ?? null;
$unit_code = $data['unit_code'] ?? null;
$vehicles = $data['vehicles'] ?? [];
$suggested_by = $data['suggested_by'] ?? null;
$match_score = $data['match_score'] ?? 0;
$reasoning = $data['reasoning'] ?? '';

// Debug log
error_log("=== CREATE SUGGESTION START ===");
error_log("Incident ID: " . $incident_id);
error_log("Unit ID: " . $unit_id);
error_log("Unit Name: " . $unit_name);
error_log("Vehicles count: " . count($vehicles));
error_log("Vehicles data: " . json_encode($vehicles));
error_log("Suggested by: " . $suggested_by);

if (!$incident_id || !$unit_id) {
    echo json_encode(['success' => false, 'message' => 'Missing required data']);
    exit();
}

try {
    $pdo->beginTransaction();
    
    // Check if incident exists
    $incident_query = "SELECT * FROM api_incidents WHERE id = ?";
    $incident_stmt = $pdo->prepare($incident_query);
    $incident_stmt->execute([$incident_id]);
    $incident = $incident_stmt->fetch();
    
    if (!$incident) {
        throw new Exception('Incident not found');
    }
    
    // Check if unit exists and is available
    $unit_query = "SELECT * FROM units WHERE id = ?";
    $unit_stmt = $pdo->prepare($unit_query);
    $unit_stmt->execute([$unit_id]);
    $unit = $unit_stmt->fetch();
    
    if (!$unit) {
        throw new Exception('Unit not found');
    }
    
    // Check if unit is available
    if ($unit['current_status'] !== 'available') {
        // Check if unit is actually dispatched or has a pending suggestion
        $dispatch_check = "SELECT id FROM dispatch_incidents WHERE unit_id = ? AND status IN ('dispatched', 'en_route', 'arrived', 'pending')";
        $dispatch_stmt = $pdo->prepare($dispatch_check);
        $dispatch_stmt->execute([$unit_id]);
        $active_dispatch = $dispatch_stmt->fetch();
        
        if ($active_dispatch) {
            throw new Exception('Unit is currently on an active dispatch or has a pending suggestion');
        } else {
            // Unit status might be wrong, update it to available
            $fix_status = "UPDATE units SET current_status = 'available' WHERE id = ?";
            $fix_stmt = $pdo->prepare($fix_status);
            $fix_stmt->execute([$unit_id]);
            $unit['current_status'] = 'available';
        }
    }
    
    // Check if incident already has a pending suggestion
    $existing_suggestion = "SELECT id FROM dispatch_incidents WHERE incident_id = ? AND status = 'pending'";
    $existing_stmt = $pdo->prepare($existing_suggestion);
    $existing_stmt->execute([$incident_id]);
    $existing = $existing_stmt->fetch();
    
    if ($existing) {
        throw new Exception('This incident already has a pending suggestion');
    }
    
    // Prepare vehicles JSON for storage
    $vehicles_to_store = [];
    foreach ($vehicles as $vehicle) {
        // Ensure vehicle has required fields
        if (!isset($vehicle['id']) || !isset($vehicle['vehicle_name'])) {
            error_log("Invalid vehicle data skipped: " . json_encode($vehicle));
            continue;
        }
        
        $vehicles_to_store[] = [
            'id' => (int)$vehicle['id'],
            'vehicle_name' => $vehicle['vehicle_name'],
            'type' => $vehicle['type'] ?? 'Unknown',
            'available' => $vehicle['available'] ?? 1,
            'status' => $vehicle['status'] ?? 'Available'
        ];
    }
    
    $vehicles_json = json_encode($vehicles_to_store);
    error_log("Vehicles JSON to save: " . $vehicles_json);
    error_log("Number of valid vehicles: " . count($vehicles_to_store));
    
    // Create suggestion record with status 'pending'
    $suggestion_query = "
        INSERT INTO dispatch_incidents 
        (incident_id, unit_id, vehicles_json, dispatched_by, dispatched_at, status) 
        VALUES (?, ?, ?, ?, NOW(), 'pending')
    ";
    $suggestion_stmt = $pdo->prepare($suggestion_query);
    $suggestion_stmt->execute([
        $incident_id,
        $unit_id,
        $vehicles_json,
        $suggested_by
    ]);
    
    $suggestion_id = $pdo->lastInsertId();
    error_log("Created suggestion ID: " . $suggestion_id);
    
    // Update incident status to "processing" and set dispatch_id
    $update_incident = "
        UPDATE api_incidents 
        SET dispatch_status = 'processing',
            status = 'processing',
            dispatch_id = ?
        WHERE id = ?
    ";
    $update_stmt = $pdo->prepare($update_incident);
    $update_stmt->execute([$suggestion_id, $incident_id]);
    
    // DO NOT update unit status to "dispatched" yet - keep it as "available"
    // Unit status will only change when ER approves the suggestion
    // Just set the current_dispatch_id to track the suggestion
    $update_unit = "
        UPDATE units 
        SET current_dispatch_id = ?,
            last_status_change = NOW()
        WHERE id = ?
    ";
    $update_unit_stmt = $pdo->prepare($update_unit);
    $update_unit_stmt->execute([$suggestion_id, $unit_id]);
    
    // Mark vehicles as "suggested" (not dispatched yet)
    $vehicle_count = 0;
    foreach ($vehicles_to_store as $vehicle) {
        // First, check if vehicle is already suggested or dispatched
        $vehicle_check = "SELECT id FROM vehicle_status WHERE vehicle_id = ? AND status IN ('suggested', 'dispatched')";
        $vehicle_check_stmt = $pdo->prepare($vehicle_check);
        $vehicle_check_stmt->execute([$vehicle['id']]);
        $vehicle_unavailable = $vehicle_check_stmt->fetch();
        
        if ($vehicle_unavailable) {
            error_log("Vehicle " . $vehicle['id'] . " is already suggested or dispatched, skipping");
            continue;
        }
        
        // Insert or update vehicle status as "suggested"
        $vehicle_query = "
            INSERT INTO vehicle_status 
            (vehicle_id, vehicle_name, vehicle_type, unit_id, dispatch_id, suggestion_id, status) 
            VALUES (?, ?, ?, ?, ?, ?, 'suggested')
            ON DUPLICATE KEY UPDATE 
            status = 'suggested',
            unit_id = ?,
            dispatch_id = ?,
            suggestion_id = ?,
            last_updated = NOW()
        ";
        $vehicle_stmt = $pdo->prepare($vehicle_query);
        $vehicle_stmt->execute([
            $vehicle['id'],
            $vehicle['vehicle_name'],
            $vehicle['type'],
            $unit_id,
            $suggestion_id,
            $suggestion_id, // suggestion_id parameter
            $unit_id,
            $suggestion_id,
            $suggestion_id  // suggestion_id for update
        ]);
        $vehicle_count++;
        error_log("Marked vehicle as suggested: " . $vehicle['vehicle_name'] . " (ID: " . $vehicle['id'] . ")");
    }
    
    error_log("Successfully marked " . $vehicle_count . " vehicles as suggested");
    
    $pdo->commit();
    
    echo json_encode([
        'success' => true,
        'message' => 'Suggestion created successfully',
        'suggestion_id' => $suggestion_id,
        'incident' => [
            'id' => $incident['id'],
            'title' => $incident['title'],
            'status' => 'processing'
        ],
        'unit' => [
            'id' => $unit['id'],
            'name' => $unit['unit_name'],
            'status' => 'available' // Unit remains available
        ],
        'vehicle_count' => $vehicle_count,
        'vehicles_saved' => $vehicles_to_store
    ]);
    
} catch (Exception $e) {
    $pdo->rollBack();
    error_log("Error creating suggestion: " . $e->getMessage());
    error_log("Stack trace: " . $e->getTraceAsString());
    echo json_encode([
        'success' => false,
        'message' => 'Failed to create suggestion: ' . $e->getMessage()
    ]);
}
?>