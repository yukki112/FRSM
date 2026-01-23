<?php
// get_incident_details.php
session_start();
require_once '../../config/db_connection.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Not authenticated']);
    exit();
}

$incident_id = $_GET['id'] ?? null;

if (!$incident_id) {
    echo json_encode(['success' => false, 'message' => 'No incident ID provided']);
    exit();
}

try {
    // Get incident details
    $incident_query = "SELECT 
        ai.*,
        u.first_name as responder_first_name,
        u.last_name as responder_last_name
    FROM api_incidents ai
    LEFT JOIN users u ON ai.responded_by = u.id
    WHERE ai.id = ?";
    
    $incident_stmt = $pdo->prepare($incident_query);
    $incident_stmt->execute([$incident_id]);
    $incident = $incident_stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$incident) {
        echo json_encode(['success' => false, 'message' => 'Incident not found']);
        exit();
    }
    
    // Get dispatch info if available
    if ($incident['dispatch_id']) {
        $dispatch_query = "SELECT 
            di.status as dispatch_status,
            di.dispatched_at,
            di.status_updated_at,
            u.unit_name,
            u.unit_code
        FROM dispatch_incidents di
        LEFT JOIN units u ON di.unit_id = u.id
        WHERE di.id = ?";
        
        $dispatch_stmt = $pdo->prepare($dispatch_query);
        $dispatch_stmt->execute([$incident['dispatch_id']]);
        $dispatch_info = $dispatch_stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($dispatch_info) {
            $incident['dispatch_info'] = $dispatch_info;
        }
    }
    
    echo json_encode([
        'success' => true,
        'incident' => $incident
    ]);
    
} catch (PDOException $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Database error: ' . $e->getMessage()
    ]);
}