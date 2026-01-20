<?php
// reset_volunteer_password.php
session_start();
require_once '../../config/db_connection.php';

if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'EMPLOYEE') {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

$input = json_decode(file_get_contents('php://input'), true);

if (!isset($input['volunteer_id'])) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Missing volunteer ID']);
    exit();
}

$volunteer_id = intval($input['volunteer_id']);

try {
    // Get volunteer and user details
    $query = "SELECT v.first_name, v.email, u.id as user_id, u.username 
              FROM volunteers v 
              LEFT JOIN users u ON v.user_id = u.id 
              WHERE v.id = ? AND v.status = 'approved'";
    $stmt = $pdo->prepare($query);
    $stmt->execute([$volunteer_id]);
    $data = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$data || !$data['user_id']) {
        throw new Exception('Approved volunteer with user account not found');
    }
    
    // Generate new default password
    $first_letter = strtoupper(substr(trim($data['first_name']), 0, 1));
    $new_password = "#" . $first_letter . "ST" . rand(1000, 9999);
    $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
    
    // Update password
    $update_query = "UPDATE users SET password = ?, updated_at = NOW() WHERE id = ?";
    $update_stmt = $pdo->prepare($update_query);
    $update_stmt->execute([$hashed_password, $data['user_id']]);
    
    // Log the reset
    $log_query = "INSERT INTO email_logs (recipient, subject, body, status) VALUES (?, ?, ?, 'pending')";
    $log_stmt = $pdo->prepare($log_query);
    $log_stmt->execute([
        $data['email'],
        'Password Reset - Fire & Rescue Volunteer Account',
        "Dear " . $data['first_name'] . ",\n\n" .
        "Your password has been reset by an administrator.\n\n" .
        "Your new login credentials:\n" .
        "Username: " . $data['username'] . "\n" .
        "Password: " . $new_password . "\n\n" .
        "Please login and change your password immediately.\n\n" .
        "Best regards,\nFire & Rescue Team",
    ]);
    
    header('Content-Type: application/json');
    echo json_encode([
        'success' => true, 
        'message' => 'Password reset successfully',
        'new_password' => $new_password // Only for admin display
    ]);
    
} catch (Exception $e) {
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false, 
        'message' => 'Failed to reset password: ' . $e->getMessage()
    ]);
}
?>