<?php
// get_vehicles_for_unit.php - UPDATED VERSION
require_once '../../config/db_connection.php';
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit();
}

$data = json_decode(file_get_contents('php://input'), true);
$unit_id = $data['unit_id'] ?? null;

try {
    // Get all available vehicles from API
    $vehicles = [];
    $url = 'https://ers.jampzdev.com/api/staff/Sub3/Vehicles.php';
    $context = stream_context_create([
        'ssl' => ['verify_peer' => false, 'verify_peer_name' => false],
        'http' => ['timeout' => 5, 'ignore_errors' => true]
    ]);
    
    $response = @file_get_contents($url, false, $context);
    
    if ($response !== false) {
        $data = json_decode($response, true);
        if (isset($data['vehicles']) && is_array($data['vehicles'])) {
            $vehicles = $data['vehicles'];
        }
    }
    
    // Get suggested and dispatched vehicle IDs from database
    $unavailable_stmt = $pdo->query("
        SELECT vehicle_id 
        FROM vehicle_status 
        WHERE status IN ('dispatched', 'suggested')
    ");
    $unavailable_vehicle_ids = $unavailable_stmt->fetchAll(PDO::FETCH_COLUMN, 0);
    
    // Filter available vehicles (not dispatched or suggested)
    $available_vehicles = array_filter($vehicles, function($v) use ($unavailable_vehicle_ids) {
        return isset($v['available']) && $v['available'] == 1 && 
               isset($v['status']) && $v['status'] === 'Available' &&
               !in_array($v['id'] ?? 0, $unavailable_vehicle_ids);
    });
    
    // Get unit details to filter by type
    if ($unit_id) {
        $unit_query = "SELECT unit_type FROM units WHERE id = ?";
        $unit_stmt = $pdo->prepare($unit_query);
        $unit_stmt->execute([$unit_id]);
        $unit = $unit_stmt->fetch();
        
        if ($unit) {
            // Filter vehicles by unit type
            $type_mapping = [
                'Fire' => ['Fire', 'Truck', 'Engine', 'Pumper', 'Ladder'],
                'Rescue' => ['Rescue', 'Truck', 'Ambulance', 'Utility', 'Support'],
                'EMS' => ['Ambulance', 'Medical', 'Van', 'Response', 'Rescue'],
                'Logistics' => ['Utility', 'Supply', 'Support', 'Truck', 'Van'],
                'Command' => ['Command', 'Communication', 'Van', 'Car', 'SUV']
            ];
            
            $needed_keywords = $type_mapping[$unit['unit_type']] ?? ['Vehicle'];
            
            $available_vehicles = array_filter($available_vehicles, function($v) use ($needed_keywords) {
                $vehicle_name = strtolower($v['vehicle_name'] ?? '');
                $vehicle_type = strtolower($v['type'] ?? '');
                
                foreach ($needed_keywords as $keyword) {
                    $keyword_lower = strtolower($keyword);
                    if (strpos($vehicle_name, $keyword_lower) !== false || 
                        strpos($vehicle_type, $keyword_lower) !== false) {
                        return true;
                    }
                }
                return false;
            });
        }
    }
    
    echo json_encode([
        'success' => true,
        'vehicles' => array_values($available_vehicles),
        'count' => count($available_vehicles)
    ]);
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Failed to get vehicles: ' . $e->getMessage(),
        'vehicles' => [],
        'count' => 0
    ]);
}
?>