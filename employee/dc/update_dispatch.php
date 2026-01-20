<?php
// update_dispatch.php
session_start();
require_once '../../config/db_connection.php';

if (!isset($_SESSION['user_id'])) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Not authenticated']);
    exit();
}

// Get JSON data
$data = json_decode(file_get_contents('php://input'), true);
$dispatch_id = $data['dispatch_id'] ?? null;
$vehicles = $data['vehicles'] ?? [];

if (!$dispatch_id) {
    echo json_encode(['success' => false, 'message' => 'No dispatch ID provided']);
    exit();
}

try {
    $pdo->beginTransaction();
    
    // Update vehicles_json in dispatch_incidents
    $vehicles_json = json_encode($vehicles);
    
    $update_query = "UPDATE dispatch_incidents SET vehicles_json = ? WHERE id = ?";
    $stmt = $pdo->prepare($update_query);
    $stmt->execute([$vehicles_json, $dispatch_id]);
    
    // Also update vehicle_status table
    // First, remove existing dispatch assignments for this dispatch
    $clear_vehicles_query = "UPDATE vehicle_status SET dispatch_id = NULL, suggestion_id = NULL, status = 'available' WHERE dispatch_id = ?";
    $clear_stmt = $pdo->prepare($clear_vehicles_query);
    $clear_stmt->execute([$dispatch_id]);
    
    // Now update vehicle_status for the selected vehicles
    foreach ($vehicles as $vehicle) {
        $update_vehicle_query = "UPDATE vehicle_status SET 
                                 dispatch_id = ?, 
                                 suggestion_id = ?, 
                                 status = 'suggested' 
                                 WHERE vehicle_id = ?";
        $vehicle_stmt = $pdo->prepare($update_vehicle_query);
        $vehicle_stmt->execute([$dispatch_id, $dispatch_id, $vehicle['id']]);
    }
    
    $pdo->commit();
    
    echo json_encode(['success' => true, 'message' => 'Dispatch updated successfully']);
    
} catch (PDOException $e) {
    $pdo->rollBack();
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
?>