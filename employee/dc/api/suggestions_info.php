<?php
// api/suggestions_info.php - For ER to see and approve suggestions
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

// API key authentication
$api_key = $_SERVER['HTTP_X_API_KEY'] ?? $_GET['api_key'] ?? '';
$valid_api_key = 'YUKKIAPIKEY';



if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    // GET: Get suggestions for approval
    $suggestion_id = $_GET['suggestion_id'] ?? null;
    $status = $_GET['status'] ?? 'pending'; // pending, approved, rejected
    
    if ($suggestion_id) {
        // Get specific suggestion
        $query = "
            SELECT di.*, 
                   ai.id as incident_id, ai.title, ai.location, ai.severity, ai.emergency_type,
                   ai.description, ai.caller_name, ai.caller_phone, ai.dispatch_status,
                   u.unit_name, u.unit_code, u.unit_type, u.location as unit_location,
                   u.current_status as unit_status,
                   usr.first_name as suggested_by_first, usr.last_name as suggested_by_last
            FROM dispatch_incidents di
            JOIN api_incidents ai ON di.incident_id = ai.id
            JOIN units u ON di.unit_id = u.id
            LEFT JOIN users usr ON di.dispatched_by = usr.id
            WHERE di.id = ?
        ";
        $stmt = $pdo->prepare($query);
        $stmt->execute([$suggestion_id]);
        $suggestion = $stmt->fetch();
        
        if ($suggestion) {
            // Get vehicles for this suggestion
            $vehicles = json_decode($suggestion['vehicles_json'], true) ?? [];
            
            // Get volunteers assigned to the unit
            $volunteers_query = "
                SELECT v.full_name, v.contact_number, v.email,
                       v.skills_basic_firefighting, v.skills_first_aid_cpr,
                       v.skills_search_rescue, v.skills_driving, v.skills_communication,
                       v.available_days, v.available_hours
                FROM volunteer_assignments va
                JOIN volunteers v ON va.volunteer_id = v.id
                WHERE va.unit_id = ? AND v.status = 'approved' AND va.status = 'Active'
            ";
            $volunteers_stmt = $pdo->prepare($volunteers_query);
            $volunteers_stmt->execute([$suggestion['unit_id']]);
            $volunteers = $volunteers_stmt->fetchAll();
            
            // Get vehicle details from vehicle_status table
            $vehicle_details_query = "
                SELECT vs.* 
                FROM vehicle_status vs
                WHERE vs.dispatch_id = ?
            ";
            $vehicle_details_stmt = $pdo->prepare($vehicle_details_query);
            $vehicle_details_stmt->execute([$suggestion_id]);
            $vehicle_status = $vehicle_details_stmt->fetchAll();
            
            echo json_encode([
                'success' => true,
                'type' => 'suggestion',
                'suggestion' => $suggestion,
                'vehicles' => $vehicles,
                'vehicle_status' => $vehicle_status,
                'volunteers' => $volunteers,
                'volunteer_count' => count($volunteers),
                'suggested_time' => $suggestion['dispatched_at'],
                'incident_status' => $suggestion['dispatch_status']
            ]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Suggestion not found']);
        }
    } else {
        // Get all pending suggestions (for approval)
        $query = "
            SELECT di.id, di.status as suggestion_status, di.dispatched_at as suggested_at,
                   ai.id as incident_id, ai.title, ai.location, ai.severity, ai.emergency_type,
                   ai.dispatch_status, ai.created_at as incident_reported,
                   u.unit_name, u.unit_code, u.unit_type,
                   usr.first_name as suggested_by_first, usr.last_name as suggested_by_last,
                   (SELECT COUNT(*) FROM vehicle_status vs WHERE vs.dispatch_id = di.id) as vehicle_count,
                   (SELECT COUNT(*) FROM volunteer_assignments va 
                    JOIN volunteers v ON va.volunteer_id = v.id 
                    WHERE va.unit_id = u.id AND v.status = 'approved') as volunteer_count
            FROM dispatch_incidents di
            JOIN api_incidents ai ON di.incident_id = ai.id
            JOIN units u ON di.unit_id = u.id
            LEFT JOIN users usr ON di.dispatched_by = usr.id
            WHERE di.status = ? 
              AND ai.dispatch_status = 'processing'
            ORDER BY ai.severity DESC, di.dispatched_at DESC
            LIMIT 50
        ";
        $stmt = $pdo->prepare($query);
        $stmt->execute([$status]);
        $suggestions = $stmt->fetchAll();
        
        echo json_encode([
            'success' => true,
            'type' => 'suggestions_list',
            'suggestions' => $suggestions,
            'count' => count($suggestions),
            'status' => $status,
            'timestamp' => date('Y-m-d H:i:s')
        ]);
    }
    
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // POST: Approve/reject suggestions (from Emergency Response system)
    $data = json_decode(file_get_contents('php://input'), true);
    $action = $data['action'] ?? null; // 'approve' or 'reject'
    $suggestion_id = $data['suggestion_id'] ?? null;
    $er_notes = $data['notes'] ?? null;
    $er_user_id = $data['er_user_id'] ?? null; // ER user who approves
    
    if (!$action || !$suggestion_id) {
        echo json_encode(['success' => false, 'message' => 'Missing required data']);
        exit();
    }
    
    // Validate action
    if (!in_array($action, ['approve', 'reject'])) {
        echo json_encode(['success' => false, 'message' => 'Invalid action']);
        exit();
    }
    
    try {
        $pdo->beginTransaction();
        
        // Get suggestion details
        $suggestion_query = "
            SELECT di.*, ai.id as incident_id, ai.title, u.id as unit_id, u.unit_name
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
            $update_incident_stmt->execute([$er_user_id, $suggestion['incident_id']]);
            
            // Update unit status
            $update_unit = "
                UPDATE units 
                SET current_status = 'dispatched',
                    current_dispatch_id = ?,
                    last_status_change = NOW()
                WHERE id = ?
            ";
            $update_unit_stmt = $pdo->prepare($update_unit);
            $update_unit_stmt->execute([$suggestion_id, $suggestion['unit_id']]);
            
            // Mark vehicles as "dispatched" (actually dispatched now)
            $vehicles = json_decode($suggestion['vehicles_json'], true) ?? [];
            foreach ($vehicles as $vehicle) {
                $vehicle_query = "
                    UPDATE vehicle_status 
                    SET status = 'dispatched',
                        last_updated = NOW()
                    WHERE vehicle_id = ? AND dispatch_id = ?
                ";
                $vehicle_stmt = $pdo->prepare($vehicle_query);
                $vehicle_stmt->execute([$vehicle['id'], $suggestion_id]);
            }
            
            $message = 'Suggestion approved and dispatched';
            $new_status = 'dispatched';
            
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
            
            // Reset incident status back to "for_dispatch"
            $update_incident = "
                UPDATE api_incidents 
                SET dispatch_status = 'for_dispatch',
                    dispatch_id = NULL,
                    status = 'pending'
                WHERE id = ?
            ";
            $update_incident_stmt = $pdo->prepare($update_incident);
            $update_incident_stmt->execute([$suggestion['incident_id']]);
            
            // Reset unit status
            $update_unit = "
                UPDATE units 
                SET current_status = 'available',
                    current_dispatch_id = NULL,
                    last_status_change = NOW()
                WHERE id = ?
            ";
            $update_unit_stmt = $pdo->prepare($update_unit);
            $update_unit_stmt->execute([$suggestion['unit_id']]);
            
            // Make vehicles available again
            $reset_vehicles = "
                UPDATE vehicle_status 
                SET status = 'available',
                    dispatch_id = NULL,
                    last_updated = NOW()
                WHERE dispatch_id = ?
            ";
            $reset_vehicles_stmt = $pdo->prepare($reset_vehicles);
            $reset_vehicles_stmt->execute([$suggestion_id]);
            
            $message = 'Suggestion rejected';
            $new_status = 'cancelled';
        }
        
        $pdo->commit();
        
        echo json_encode([
            'success' => true,
            'message' => $message,
            'suggestion_id' => $suggestion_id,
            'action' => $action,
            'new_status' => $new_status,
            'updated_at' => date('Y-m-d H:i:s')
        ]);
        
    } catch (Exception $e) {
        $pdo->rollBack();
        echo json_encode([
            'success' => false,
            'message' => 'Failed to process action: ' . $e->getMessage()
        ]);
    }
}
?>