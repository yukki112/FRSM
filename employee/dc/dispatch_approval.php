<?php
// dispatch_approval.php - UPDATED VERSION
require_once '../../config/db_connection.php';
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit();
}

$data = json_decode(file_get_contents('php://input'), true);
$action = $data['action'] ?? null; // 'approve' or 'reject'
$suggestion_id = $data['suggestion_id'] ?? null;
$er_notes = $data['er_notes'] ?? null;
$approved_by = $data['approved_by'] ?? null;

if (!$action || !$suggestion_id) {
    echo json_encode(['success' => false, 'message' => 'Missing required data']);
    exit();
}

try {
    $pdo->beginTransaction();
    
    // Get suggestion details
    $suggestion_query = "
        SELECT di.*, ai.id as incident_id, ai.title, u.id as unit_id, u.unit_name, u.current_status as unit_current_status
        FROM dispatch_incidents di
        JOIN api_incidents ai ON di.incident_id = ai.id
        JOIN units u ON di.unit_id = u.id
        WHERE di.id = ?
    ";
    $suggestion_stmt = $pdo->prepare($suggestion_query);
    $suggestion_stmt->execute([$suggestion_id]);
    $suggestion = $suggestion_stmt->fetch();
    
    if (!$suggestion) {
        throw new Exception('Suggestion not found');
    }
    
    if ($action === 'approve') {
        // Approve the suggestion - make it an actual dispatch
        $update_query = "
            UPDATE dispatch_incidents 
            SET status = 'dispatched',
                er_notes = COALESCE(?, er_notes),
                status_updated_at = NOW()
            WHERE id = ?
        ";
        $update_stmt = $pdo->prepare($update_query);
        $update_stmt->execute([$er_notes, $suggestion_id]);
        
        // Update incident status
        $update_incident = "
            UPDATE api_incidents 
            SET dispatch_status = 'processing',
                status = 'processing',
                responded_at = NOW(),
                responded_by = ?
            WHERE id = ?
        ";
        $update_incident_stmt = $pdo->prepare($update_incident);
        $update_incident_stmt->execute([$approved_by, $suggestion['incident_id']]);
        
        // NOW update unit status to "dispatched" (only when ER approves)
        $update_unit = "
            UPDATE units 
            SET current_status = 'dispatched',
                current_dispatch_id = ?,
                last_status_change = NOW()
            WHERE id = ?
        ";
        $update_unit_stmt = $pdo->prepare($update_unit);
        $update_unit_stmt->execute([$suggestion_id, $suggestion['unit_id']]);
        
        // Get vehicles from suggestion
        $vehicles = json_decode($suggestion['vehicles_json'], true) ?? [];
        
        // Update suggested vehicles to dispatched
        $vehicle_query = "
            UPDATE vehicle_status 
            SET status = 'dispatched',
                last_updated = NOW()
            WHERE suggestion_id = ?
        ";
        $vehicle_stmt = $pdo->prepare($vehicle_query);
        $vehicle_stmt->execute([$suggestion_id]);
        
        $message = 'Dispatch approved and activated';
        
    } elseif ($action === 'reject') {
        // Reject the suggestion
        $update_query = "
            UPDATE dispatch_incidents 
            SET status = 'cancelled',
                er_notes = COALESCE(?, er_notes),
                status_updated_at = NOW()
            WHERE id = ?
        ";
        $update_stmt = $pdo->prepare($update_query);
        $update_stmt->execute([$er_notes, $suggestion_id]);
        
        // Reset incident status
        $update_incident = "
            UPDATE api_incidents 
            SET dispatch_status = 'for_dispatch',
                dispatch_id = NULL
            WHERE id = ?
        ";
        $update_incident_stmt = $pdo->prepare($update_incident);
        $update_incident_stmt->execute([$suggestion['incident_id']]);
        
        // Reset unit status (remove current_dispatch_id but keep as available)
        $update_unit = "
            UPDATE units 
            SET current_dispatch_id = NULL
            WHERE id = ?
        ";
        $update_unit_stmt = $pdo->prepare($update_unit);
        $update_unit_stmt->execute([$suggestion['unit_id']]);
        
        // Make vehicles available again (remove suggested status)
        $reset_vehicles = "
            UPDATE vehicle_status 
            SET status = 'available',
                dispatch_id = NULL,
                suggestion_id = NULL,
                last_updated = NOW()
            WHERE suggestion_id = ?
        ";
        $reset_vehicles_stmt = $pdo->prepare($reset_vehicles);
        $reset_vehicles_stmt->execute([$suggestion_id]);
        
        $message = 'Suggestion rejected and resources made available';
    }
    
    $pdo->commit();
    
    echo json_encode([
        'success' => true,
        'message' => $message,
        'suggestion_id' => $suggestion_id,
        'action' => $action
    ]);
    
} catch (Exception $e) {
    $pdo->rollBack();
    echo json_encode([
        'success' => false,
        'message' => 'Failed to process action: ' . $e->getMessage()
    ]);
}
?>