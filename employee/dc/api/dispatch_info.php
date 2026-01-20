<?php
// api/dispatch_info.php - For ER to see ACTUAL dispatches (approved suggestions)
require_once '../../../config/db_connection.php';
header('Content-Type: application/json');
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, X-API-Key");

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $dispatch_id = $_GET['dispatch_id'] ?? null;
    $status = $_GET['status'] ?? 'dispatched'; // dispatched, en_route, arrived, completed
    
    if ($dispatch_id) {
        // Get specific ACTUAL dispatch (status = dispatched, en_route, arrived, completed)
        $query = "
            SELECT di.*, 
                   ai.title, ai.location, ai.severity, ai.emergency_type,
                   ai.description, ai.caller_name, ai.caller_phone,
                   u.unit_name, u.unit_code, u.unit_type, u.location as unit_location,
                   u.current_status as unit_status
            FROM dispatch_incidents di
            JOIN api_incidents ai ON di.incident_id = ai.id
            JOIN units u ON di.unit_id = u.id
            WHERE di.id = ? 
              AND di.status IN ('dispatched', 'en_route', 'arrived', 'completed')
        ";
        $stmt = $pdo->prepare($query);
        $stmt->execute([$dispatch_id]);
        $dispatch = $stmt->fetch();
        
        if ($dispatch) {
            $vehicles = json_decode($dispatch['vehicles_json'], true) ?? [];
            
            $volunteers_query = "
                SELECT v.full_name, v.contact_number, v.email,
                       v.skills_basic_firefighting, v.skills_first_aid_cpr,
                       v.skills_search_rescue, v.skills_driving
                FROM volunteer_assignments va
                JOIN volunteers v ON va.volunteer_id = v.id
                WHERE va.unit_id = ? AND v.status = 'approved' AND va.status = 'Active'
            ";
            $volunteers_stmt = $pdo->prepare($volunteers_query);
            $volunteers_stmt->execute([$dispatch['unit_id']]);
            $volunteers = $volunteers_stmt->fetchAll();
            
            echo json_encode([
                'success' => true,
                'type' => 'active_dispatch',
                'dispatch' => $dispatch,
                'vehicles' => $vehicles,
                'volunteers' => $volunteers,
                'dispatch_time' => $dispatch['dispatched_at']
            ]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Active dispatch not found']);
        }
    } else {
        // Get all ACTIVE dispatches (not suggestions)
        $query = "
            SELECT di.id, di.status, di.dispatched_at, di.status_updated_at,
                   ai.title, ai.location, ai.severity, ai.emergency_type,
                   ai.dispatch_status as incident_status,
                   u.unit_name, u.unit_code, u.unit_type,
                   (SELECT COUNT(*) FROM vehicle_status vs WHERE vs.dispatch_id = di.id) as vehicle_count
            FROM dispatch_incidents di
            JOIN api_incidents ai ON di.incident_id = ai.id
            JOIN units u ON di.unit_id = u.id
            WHERE di.status IN ('dispatched', 'en_route', 'arrived', 'completed')
              AND di.dispatched_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
            ORDER BY 
                CASE di.status 
                    WHEN 'dispatched' THEN 1
                    WHEN 'en_route' THEN 2
                    WHEN 'arrived' THEN 3
                    WHEN 'completed' THEN 4
                    ELSE 5
                END,
                di.dispatched_at DESC
            LIMIT 50
        ";
        $stmt = $pdo->query($query);
        $dispatches = $stmt->fetchAll();
        
        echo json_encode([
            'success' => true,
            'type' => 'active_dispatches',
            'dispatches' => $dispatches,
            'count' => count($dispatches),
            'timestamp' => date('Y-m-d H:i:s')
        ]);
    }
    
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // POST: Update ACTUAL dispatch status
    $data = json_decode(file_get_contents('php://input'), true);
    $dispatch_id = $data['dispatch_id'] ?? null;
    $status = $data['status'] ?? null;
    $notes = $data['notes'] ?? null;
    
    if (!$dispatch_id || !$status) {
        echo json_encode(['success' => false, 'message' => 'Missing required data']);
        exit();
    }
    
    $valid_statuses = ['en_route', 'arrived', 'completed'];
    if (!in_array($status, $valid_statuses)) {
        echo json_encode(['success' => false, 'message' => 'Invalid status']);
        exit();
    }
    
    try {
        // Verify this is an actual dispatch (not a suggestion)
        $check_query = "SELECT status FROM dispatch_incidents WHERE id = ?";
        $check_stmt = $pdo->prepare($check_query);
        $check_stmt->execute([$dispatch_id]);
        $current_status = $check_stmt->fetchColumn();
        
        if (!$current_status || $current_status === 'pending') {
            throw new Exception('This is not an active dispatch');
        }
        
        // Update dispatch status
        $update_query = "
            UPDATE dispatch_incidents 
            SET status = ?, 
                status_updated_at = NOW(),
                er_notes = CONCAT_WS('\n', COALESCE(er_notes, ''), ?)
            WHERE id = ?
        ";
        $update_stmt = $pdo->prepare($update_query);
        $update_stmt->execute([$status, date('H:i') . ' - ' . $notes, $dispatch_id]);
        
        // Update incident status if completed
        if ($status === 'completed') {
            $incident_query = "SELECT incident_id FROM dispatch_incidents WHERE id = ?";
            $incident_stmt = $pdo->prepare($incident_query);
            $incident_stmt->execute([$dispatch_id]);
            $dispatch_data = $incident_stmt->fetch();
            
            if ($dispatch_data) {
                $update_incident = "
                    UPDATE api_incidents 
                    SET dispatch_status = 'closed',
                        status = 'closed'
                    WHERE id = ?
                ";
                $update_incident_stmt = $pdo->prepare($update_incident);
                $update_incident_stmt->execute([$dispatch_data['incident_id']]);
                
                // Make unit available again
                $unit_query = "SELECT unit_id FROM dispatch_incidents WHERE id = ?";
                $unit_stmt = $pdo->prepare($unit_query);
                $unit_stmt->execute([$dispatch_id]);
                $unit_data = $unit_stmt->fetch();
                
                if ($unit_data) {
                    $update_unit = "
                        UPDATE units 
                        SET current_status = 'available',
                            current_dispatch_id = NULL,
                            last_status_change = NOW()
                        WHERE id = ?
                    ";
                    $update_unit_stmt = $pdo->prepare($update_unit);
                    $update_unit_stmt->execute([$unit_data['unit_id']]);
                    
                    // Make vehicles available again
                    $reset_vehicles = "
                        UPDATE vehicle_status 
                        SET status = 'available',
                            dispatch_id = NULL,
                            last_updated = NOW()
                        WHERE dispatch_id = ?
                    ";
                    $reset_vehicles_stmt = $pdo->prepare($reset_vehicles);
                    $reset_vehicles_stmt->execute([$dispatch_id]);
                }
            }
        }
        
        echo json_encode([
            'success' => true,
            'message' => 'Dispatch status updated successfully',
            'dispatch_id' => $dispatch_id,
            'new_status' => $status,
            'updated_at' => date('Y-m-d H:i:s')
        ]);
        
    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'message' => 'Update failed: ' . $e->getMessage()
        ]);
    }
}
?>