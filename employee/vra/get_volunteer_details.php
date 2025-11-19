<?php
session_start();
require_once '../../config/db_connection.php';

if (!isset($_SESSION['user_id'])) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['id'])) {
    $volunteer_id = $_GET['id'];
    
    try {
        // Get volunteer details with unit assignment
        $query = "SELECT v.*, u.unit_name, u.unit_code 
                  FROM volunteers v 
                  LEFT JOIN volunteer_assignments va ON v.id = va.volunteer_id 
                  LEFT JOIN units u ON va.unit_id = u.id 
                  WHERE v.id = ?";
        $stmt = $pdo->prepare($query);
        $stmt->execute([$volunteer_id]);
        $volunteer = $stmt->fetch();
        
        if ($volunteer) {
            echo json_encode(['success' => true, 'volunteer' => $volunteer]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Volunteer not found']);
        }
        
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
    }
} else {
    echo json_encode(['success' => false, 'message' => 'Invalid request']);
}
?>