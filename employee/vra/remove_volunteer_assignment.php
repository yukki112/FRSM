<?php
session_start();
require_once '../../config/db_connection.php';

if (!isset($_SESSION['user_id'])) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    
    $volunteer_id = $input['volunteer_id'] ?? null;
    
    if (!$volunteer_id) {
        echo json_encode(['success' => false, 'message' => 'Missing volunteer ID']);
        exit();
    }
    
    try {
        $pdo->beginTransaction();
        
        // Get the unit ID before deleting
        $get_unit_stmt = $pdo->prepare("SELECT unit_id FROM volunteer_assignments WHERE volunteer_id = ?");
        $get_unit_stmt->execute([$volunteer_id]);
        $assignment = $get_unit_stmt->fetch();
        
        if ($assignment) {
            $unit_id = $assignment['unit_id'];
            
            // Delete the assignment
            $delete_stmt = $pdo->prepare("DELETE FROM volunteer_assignments WHERE volunteer_id = ?");
            $delete_stmt->execute([$volunteer_id]);
            
            // Update unit current count
            $update_unit_stmt = $pdo->prepare("UPDATE units SET current_count = GREATEST(0, current_count - 1), updated_at = NOW() WHERE id = ?");
            $update_unit_stmt->execute([$unit_id]);
            
            $pdo->commit();
            echo json_encode(['success' => true, 'message' => 'Assignment removed successfully']);
        } else {
            echo json_encode(['success' => false, 'message' => 'No assignment found for this volunteer']);
        }
        
    } catch (Exception $e) {
        $pdo->rollBack();
        echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
    }
} else {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
}
?>