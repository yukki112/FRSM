<?php
// dispatch_unit.php
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
$dispatched_by = $data['dispatched_by'] ?? null;

if (!$incident_id || !$unit_id) {
    echo json_encode(['success' => false, 'message' => 'Missing required data']);
    exit();
}

try {
    $pdo->beginTransaction();
    
    // Get incident details
    $incident_query = "SELECT * FROM api_incidents WHERE id = ?";
    $incident_stmt = $pdo->prepare($incident_query);
    $incident_stmt->execute([$incident_id]);
    $incident = $incident_stmt->fetch();
    
    if (!$incident) {
        throw new Exception('Incident not found');
    }
    
    // Get unit details
    $unit_query = "SELECT * FROM units WHERE id = ?";
    $unit_stmt = $pdo->prepare($unit_query);
    $unit_stmt->execute([$unit_id]);
    $unit = $unit_stmt->fetch();
    
    if (!$unit) {
        throw new Exception('Unit not found');
    }
    
    // Check if already dispatched
    $check_dispatch = "SELECT id FROM dispatch_incidents WHERE incident_id = ? AND status IN ('dispatched', 'en_route')";
    $check_stmt = $pdo->prepare($check_dispatch);
    $check_stmt->execute([$incident_id]);
    $existing = $check_stmt->fetch();
    
    if ($existing) {
        throw new Exception('This incident already has an active dispatch');
    }
    
    // Create dispatch record
    $dispatch_query = "
        INSERT INTO dispatch_incidents 
        (incident_id, unit_id, vehicles_json, dispatched_by, dispatched_at, status) 
        VALUES (?, ?, ?, ?, NOW(), 'dispatched')
    ";
    $dispatch_stmt = $pdo->prepare($dispatch_query);
    $dispatch_stmt->execute([
        $incident_id,
        $unit_id,
        json_encode($vehicles),
        $dispatched_by
    ]);
    
    $dispatch_id = $pdo->lastInsertId();
    
    // Update incident status
    $update_incident = "
        UPDATE api_incidents 
        SET status = 'processing', 
            responded_at = NOW(),
            responded_by = ?
        WHERE id = ?
    ";
    $update_stmt = $pdo->prepare($update_incident);
    $update_stmt->execute([$dispatched_by, $incident_id]);
    
    // Get volunteers in the unit to notify
    $volunteers_query = "
        SELECT v.id, v.full_name, v.contact_number, v.email
        FROM volunteer_assignments va
        JOIN volunteers v ON va.volunteer_id = v.id
        WHERE va.unit_id = ? AND v.status = 'approved' AND va.status = 'Active'
    ";
    $volunteers_stmt = $pdo->prepare($volunteers_query);
    $volunteers_stmt->execute([$unit_id]);
    $volunteers = $volunteers_stmt->fetchAll();
    
    // Create notifications for volunteers (if notifications table exists)
    try {
        $notification_table = $pdo->query("SHOW TABLES LIKE 'notifications'")->fetch();
        if ($notification_table) {
            foreach ($volunteers as $volunteer) {
                $notification_query = "
                    INSERT INTO notifications 
                    (user_id, type, title, message, is_read, created_at) 
                    VALUES (?, 'dispatch', 'Dispatch Alert', ?, 0, NOW())
                ";
                $notification_msg = "🚨 DISPATCH ALERT: Unit {$unit['unit_code']} has been dispatched to: {$incident['title']} at {$incident['location']}. Severity: {$incident['severity']}";
                
                $notification_stmt = $pdo->prepare($notification_query);
                $notification_stmt->execute([$volunteer['id'], $notification_msg]);
            }
        }
    } catch (Exception $e) {
        // Notifications table might not exist, continue anyway
        error_log("Notification error: " . $e->getMessage());
    }
    
    $pdo->commit();
    
    echo json_encode([
        'success' => true,
        'message' => 'Dispatch successful',
        'dispatch_id' => $dispatch_id,
        'incident' => [
            'id' => $incident['id'],
            'title' => $incident['title'],
            'location' => $incident['location']
        ],
        'unit' => [
            'id' => $unit['id'],
            'name' => $unit['unit_name'],
            'code' => $unit['unit_code']
        ],
        'volunteer_count' => count($volunteers)
    ]);
    
} catch (Exception $e) {
    $pdo->rollBack();
    echo json_encode([
        'success' => false,
        'message' => 'Dispatch failed: ' . $e->getMessage()
    ]);
}
?>