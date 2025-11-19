<?php
/**
 * Handle assignment after AI recommendation selection
 */

session_start();
require_once '../../../config/db_connection.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

try {
    $volunteer_id = $_POST['volunteer_id'] ?? null;
    $unit_id = $_POST['unit_id'] ?? null;
    $password = $_POST['password'] ?? null;
    
    if (!$volunteer_id || !$unit_id || !$password) {
        echo json_encode(['success' => false, 'message' => 'Missing required fields']);
        exit();
    }
    
    // Verify password
    $userQuery = "SELECT password FROM users WHERE id = ?";
    $userStmt = $pdo->prepare($userQuery);
    $userStmt->execute([$_SESSION['user_id']]);
    $user = $userStmt->fetch();
    
    if (!$user || !password_verify($password, $user['password'])) {
        echo json_encode(['success' => false, 'message' => 'Invalid password']);
        exit();
    }
    
    // Check if volunteer already assigned
    $checkQuery = "SELECT id FROM volunteer_assignments WHERE volunteer_id = ? AND status = 'Active'";
    $checkStmt = $pdo->prepare($checkQuery);
    $checkStmt->execute([$volunteer_id]);
    
    if ($checkStmt->rowCount() > 0) {
        // Update existing assignment
        $updateQuery = "UPDATE volunteer_assignments SET unit_id = ?, assignment_date = CURDATE() WHERE volunteer_id = ? AND status = 'Active'";
        $updateStmt = $pdo->prepare($updateQuery);
        $updateStmt->execute([$unit_id, $volunteer_id]);
    } else {
        // Create new assignment
        $insertQuery = "INSERT INTO volunteer_assignments (volunteer_id, unit_id, assigned_by, assignment_date, status) VALUES (?, ?, ?, CURDATE(), 'Active')";
        $insertStmt = $pdo->prepare($insertQuery);
        $insertStmt->execute([$volunteer_id, $unit_id, $_SESSION['user_id']]);
    }
    
    // Update unit current count
    $updateUnitQuery = "UPDATE units SET current_count = current_count + 1 WHERE id = ? AND current_count < capacity";
    $updateUnitStmt = $pdo->prepare($updateUnitQuery);
    $updateUnitStmt->execute([$unit_id]);
    
    echo json_encode(['success' => true, 'message' => 'Volunteer assigned successfully']);
    
} catch (Exception $e) {
    error_log('Error in assign_from_ai: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Server error: ' . $e->getMessage()]);
}
?>
