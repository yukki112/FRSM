<?php
// get_available_units.php
require_once '../../config/db_connection.php';
header('Content-Type: application/json');

try {
    // Get all available units (not currently dispatched AND not in pending suggestions)
    $units_query = "
        SELECT u.*, 
               COUNT(DISTINCT va.volunteer_id) as volunteer_count
        FROM units u
        LEFT JOIN volunteer_assignments va ON u.id = va.unit_id AND va.status = 'Active'
        LEFT JOIN volunteers v ON va.volunteer_id = v.id AND v.status = 'approved'
        WHERE u.status = 'Active' 
          AND u.current_status = 'available'
          AND NOT EXISTS (
              SELECT 1 FROM dispatch_incidents di 
              WHERE di.unit_id = u.id AND di.status = 'pending'
          )
        GROUP BY u.id
        ORDER BY u.unit_type, u.unit_name
    ";
    $units_stmt = $pdo->query($units_query);
    $units = $units_stmt->fetchAll();
    
    echo json_encode([
        'success' => true,
        'units' => $units,
        'count' => count($units)
    ]);
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Failed to get units: ' . $e->getMessage(),
        'units' => [],
        'count' => 0
    ]);
}
?>