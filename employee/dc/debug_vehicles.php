<?php
// debug_vehicles.php
require_once '../../config/db_connection.php';

header('Content-Type: application/json');

// Get all dispatch incidents with vehicles
$query = "
    SELECT 
        di.id,
        di.incident_id,
        di.unit_id,
        di.vehicles_json,
        di.status,
        di.dispatched_at,
        ai.title as incident_title,
        u.unit_name
    FROM dispatch_incidents di
    JOIN api_incidents ai ON di.incident_id = ai.id
    JOIN units u ON di.unit_id = u.id
    ORDER BY di.dispatched_at DESC
    LIMIT 10
";

$stmt = $pdo->query($query);
$dispatches = $stmt->fetchAll();

// Decode vehicles_json for each dispatch
foreach ($dispatches as &$dispatch) {
    $dispatch['vehicles_decoded'] = json_decode($dispatch['vehicles_json'], true);
    $dispatch['vehicles_count'] = is_array($dispatch['vehicles_decoded']) ? count($dispatch['vehicles_decoded']) : 0;
}

echo json_encode([
    'success' => true,
    'dispatches' => $dispatches,
    'total' => count($dispatches)
], JSON_PRETTY_PRINT);
?>