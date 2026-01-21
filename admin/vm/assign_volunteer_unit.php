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
    $unit_id = $input['unit_id'] ?? null;
    $assigned_by = $input['assigned_by'] ?? null;
    
    if (!$volunteer_id || !$unit_id || !$assigned_by) {
        echo json_encode(['success' => false, 'message' => 'Missing required fields']);
        exit();
    }
    
    try {
        $pdo->beginTransaction();
        
        // Check if volunteer already has an assignment
        $check_stmt = $pdo->prepare("SELECT id FROM volunteer_assignments WHERE volunteer_id = ?");
        $check_stmt->execute([$volunteer_id]);
        $existing_assignment = $check_stmt->fetch();
        
        if ($existing_assignment) {
            // Update existing assignment
            $update_stmt = $pdo->prepare("UPDATE volunteer_assignments SET unit_id = ?, assigned_by = ?, assignment_date = CURDATE(), updated_at = NOW() WHERE volunteer_id = ?");
            $update_stmt->execute([$unit_id, $assigned_by, $volunteer_id]);
        } else {
            // Create new assignment
            $insert_stmt = $pdo->prepare("INSERT INTO volunteer_assignments (volunteer_id, unit_id, assigned_by, assignment_date, status, created_at, updated_at) VALUES (?, ?, ?, CURDATE(), 'Active', NOW(), NOW())");
            $insert_stmt->execute([$volunteer_id, $unit_id, $assigned_by]);
        }
        
        // Update unit current count
        $update_unit_stmt = $pdo->prepare("UPDATE units SET current_count = current_count + 1, updated_at = NOW() WHERE id = ?");
        $update_unit_stmt->execute([$unit_id]);
        
        $pdo->commit();
        
        echo json_encode(['success' => true, 'message' => 'Volunteer assigned successfully']);
        
    } catch (Exception $e) {
        $pdo->rollBack();
        echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
    }
} else {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
}
?>