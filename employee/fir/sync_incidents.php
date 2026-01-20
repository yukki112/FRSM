<?php
require_once '../../config/db_connection.php';

// Get JSON input
$input = json_decode(file_get_contents('php://input'), true);

if (!isset($input['incidents'])) {
    echo json_encode(['success' => false, 'message' => 'No incidents data provided']);
    exit();
}

$new_incidents_count = 0;
$new_incident_ids = [];

foreach ($input['incidents'] as $incident) {
    // Check if incident already exists in database
    $check_sql = "SELECT id FROM api_incidents WHERE external_id = ?";
    $check_stmt = $pdo->prepare($check_sql);
    $check_stmt->execute([$incident['id']]);
    $existing = $check_stmt->fetch();
    
    if (!$existing) {
        // Determine if it's fire/rescue related
        $is_fire_rescue = 0;
        $rescue_category = NULL;
        
        $emergency_type = strtolower($incident['emergency_type'] ?? '');
        $description = strtolower($incident['description'] ?? '');
        
        if ($emergency_type == 'fire') {
            $is_fire_rescue = 1;
        } elseif ($emergency_type == 'other' || strpos($description, 'rescue') !== false) {
            if (strpos($description, 'collapsing building') !== false || 
                strpos($description, 'building collapse') !== false) {
                $is_fire_rescue = 1;
                $rescue_category = 'building_collapse';
            } elseif (strpos($description, 'vehicle accident') !== false) {
                $is_fire_rescue = 1;
                $rescue_category = 'vehicle_accident';
            } elseif (strpos($description, 'height') !== false) {
                $is_fire_rescue = 1;
                $rescue_category = 'height_rescue';
            } elseif (strpos($description, 'water') !== false) {
                $is_fire_rescue = 1;
                $rescue_category = 'water_rescue';
            } elseif (strpos($description, 'rescue') !== false) {
                $is_fire_rescue = 1;
                $rescue_category = 'other_rescue';
            }
        }
        
        // Insert new incident - FIXED FIELD NAMES TO MATCH API RESPONSE
        $insert_sql = "INSERT INTO api_incidents (
            external_id, alert_type, emergency_type, assistance_needed, severity,
            title, caller_name, caller_phone, location, description, status,
            affected_barangays, issued_by, valid_until, created_at,
            sync_status, created_at_local, is_fire_rescue_related, rescue_category
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'synced', NOW(), ?, ?)";
        
        $insert_stmt = $pdo->prepare($insert_sql);
        $insert_stmt->execute([
            $incident['id'],
            $incident['alert_type'] ?? '',
            $incident['emergency_type'] ?? '',
            $incident['assistance_needed'] ?? '',
            $incident['severity'] ?? 'medium',
            $incident['title'] ?? '',
            $incident['name'] ?? '',  // API uses 'name' not 'caller_name'
            $incident['phone'] ?? '',  // API uses 'phone' not 'caller_phone'
            $incident['location'] ?? '',
            $incident['description'] ?? '',
            $incident['status'] ?? 'pending',
            $incident['affected_barangays'] ?? '',
            $incident['issued_by'] ?? '',
            $incident['valid_until'] ?? NULL,
            $incident['created_at'] ?? date('Y-m-d H:i:s'),
            $is_fire_rescue,
            $rescue_category
        ]);
        
        $new_incidents_count++;
        $new_incident_ids[] = $pdo->lastInsertId();
    }
}

echo json_encode([
    'success' => true,
    'new_incidents' => $new_incidents_count,
    'new_incident_ids' => $new_incident_ids,
    'message' => 'Incidents synced successfully'
]);
?>