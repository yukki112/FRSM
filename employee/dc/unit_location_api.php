<?php
// unit_location_api.php
require_once '../../config/db_connection.php';
header('Content-Type: application/json');
header("Access-Control-Allow-Origin: *");

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $unit_id = $_GET['unit_id'] ?? null;
    
    if ($unit_id) {
        // Get specific unit location
        $query = "
            SELECT u.*, 
                   di.status as dispatch_status,
                   ai.location as incident_location,
                   ai.title as incident_title
            FROM units u
            LEFT JOIN dispatch_incidents di ON u.current_dispatch_id = di.id
            LEFT JOIN api_incidents ai ON di.incident_id = ai.id
            WHERE u.id = ?
        ";
        $stmt = $pdo->prepare($query);
        $stmt->execute([$unit_id]);
        $unit = $stmt->fetch();
        
        if ($unit) {
            // Simulate GPS coordinates based on location
            $coordinates = getCoordinatesFromLocation($unit['location']);
            
            echo json_encode([
                'success' => true,
                'unit' => $unit,
                'coordinates' => $coordinates,
                'status' => $unit['current_status'],
                'last_updated' => date('Y-m-d H:i:s')
            ]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Unit not found']);
        }
    } else {
        // Get all units with locations
        $query = "
            SELECT u.*, 
                   di.status as dispatch_status,
                   ai.location as incident_location,
                   ai.title as incident_title
            FROM units u
            LEFT JOIN dispatch_incidents di ON u.current_dispatch_id = di.id
            LEFT JOIN api_incidents ai ON di.incident_id = ai.id
            WHERE u.status = 'Active'
            ORDER BY u.unit_type
        ";
        $stmt = $pdo->query($query);
        $units = $stmt->fetchAll();
        
        $unit_locations = [];
        foreach ($units as $unit) {
            $coordinates = getCoordinatesFromLocation($unit['location']);
            $unit_locations[] = [
                'id' => $unit['id'],
                'name' => $unit['unit_name'],
                'code' => $unit['unit_code'],
                'type' => $unit['unit_type'],
                'status' => $unit['current_status'],
                'coordinates' => $coordinates,
                'dispatch_status' => $unit['dispatch_status'],
                'incident_location' => $unit['incident_location']
            ];
        }
        
        echo json_encode([
            'success' => true,
            'units' => $unit_locations,
            'count' => count($unit_locations),
            'timestamp' => date('Y-m-d H:i:s')
        ]);
    }
}

function getCoordinatesFromLocation($location) {
    // This is a simplified function - in production, use Google Maps Geocoding API
    $locations = [
        'Brgy. Commonwealth, Near Market' => ['lat' => 14.7232, 'lng' => 121.0735],
        'Brgy. Commonwealth, Main Road' => ['lat' => 14.7240, 'lng' => 121.0740],
        'Brgy. Commonwealth Health Center' => ['lat' => 14.7225, 'lng' => 121.0720],
        'Brgy. Commonwealth HQ' => ['lat' => 14.7250, 'lng' => 121.0750],
        'Brgy. Commonwealth Hall' => ['lat' => 14.7260, 'lng' => 121.0760],
        'Brgy. Commonwealth, Batasan Area' => ['lat' => 14.7270, 'lng' => 121.0770],
        'Brgy. Commonwealth, Payatas Area' => ['lat' => 14.7280, 'lng' => 121.0780],
        'Brgy. Commonwealth, Various Locations' => ['lat' => 14.7290, 'lng' => 121.0790],
        'Brgy. Commonwealth Storage' => ['lat' => 14.7300, 'lng' => 121.0800]
    ];
    
    return $locations[$location] ?? ['lat' => 14.6760, 'lng' => 121.0437]; // Default to Quezon City
}
?>