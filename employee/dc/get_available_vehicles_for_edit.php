<?php
require_once '../../config/db_connection.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit();
}

$data = json_decode(file_get_contents('php://input'), true);
$dispatch_id = $data['dispatch_id'] ?? null;

if (!$dispatch_id) {
    echo json_encode(['success' => false, 'message' => 'No dispatch ID provided']);
    exit();
}

try {
    // Get current dispatch vehicles
    $query = "SELECT vehicles_json FROM dispatch_incidents WHERE id = ?";
    $stmt = $pdo->prepare($query);
    $stmt->execute([$dispatch_id]);
    $dispatch = $stmt->fetch();
    
    $current_vehicle_ids = [];
    if ($dispatch && $dispatch['vehicles_json']) {
        $vehicles = json_decode($dispatch['vehicles_json'], true);
        if (is_array($vehicles)) {
            $current_vehicle_ids = array_column($vehicles, 'id');
        }
    }
    
    // Get available vehicles from API (excluding current ones)
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
            $all_vehicles = $data['vehicles'];
            
            // Filter: available, not dispatched, and not in current dispatch
            $vehicles = array_filter($all_vehicles, function($v) use ($current_vehicle_ids) {
                return isset($v['available']) && $v['available'] == 1 && 
                       isset($v['status']) && $v['status'] === 'Available' &&
                       !in_array($v['id'] ?? 0, $current_vehicle_ids);
            });
            
            $vehicles = array_values($vehicles);
        }
    }
    
    echo json_encode([
        'success' => true,
        'vehicles' => $vehicles
    ]);
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Error: ' . $e->getMessage()
    ]);
}
?>