<?php
session_start();
require_once '../../config/db_connection.php';

if (!isset($_SESSION['user_id'])) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

$input = json_decode(file_get_contents('php://input'), true);

if (!isset($input['id']) || !isset($input['status'])) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Missing required fields']);
    exit();
}

$volunteer_id = intval($input['id']);
$status = $input['status'];

// Validate status
if (!in_array($status, ['approved', 'rejected'])) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Invalid status']);
    exit();
}

// Start transaction
$pdo->beginTransaction();

try {
    // First, get volunteer details
    $query = "SELECT * FROM volunteers WHERE id = ?";
    $stmt = $pdo->prepare($query);
    $stmt->execute([$volunteer_id]);
    $volunteer = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$volunteer) {
        throw new Exception('Volunteer not found');
    }
    
    // Update volunteer status
    $query = "UPDATE volunteers SET status = ?, updated_at = NOW() WHERE id = ?";
    $stmt = $pdo->prepare($query);
    $stmt->execute([$status, $volunteer_id]);
    
    // If status is 'approved', create user account
    if ($status === 'approved') {
        // Check if user already exists with this email
        $check_query = "SELECT id FROM users WHERE email = ?";
        $check_stmt = $pdo->prepare($check_query);
        $check_stmt->execute([$volunteer['email']]);
        
        if ($check_stmt->rowCount() > 0) {
            throw new Exception('A user with this email already exists');
        }
        
        // Generate default password: #(first letter of first name)ST0000
        // Example: Stephen Kyle Viray -> #ST0000
        $first_letter = strtoupper(substr(trim($volunteer['first_name']), 0, 1));
        $default_password = "#" . $first_letter . "0000";
        
        // Hash the password
        $hashed_password = password_hash($default_password, PASSWORD_DEFAULT);
        
        // Generate username from email (take part before @)
        $username_parts = explode('@', $volunteer['email']);
        $base_username = $username_parts[0];
        
        // Check if username exists and append numbers if needed
        $username = $base_username;
        $counter = 1;
        while (true) {
            $username_check = "SELECT id FROM users WHERE username = ?";
            $username_stmt = $pdo->prepare($username_check);
            $username_stmt->execute([$username]);
            
            if ($username_stmt->rowCount() === 0) {
                break;
            }
            $username = $base_username . $counter;
            $counter++;
        }
        
        // Insert into users table
        $user_query = "INSERT INTO users (
            first_name, middle_name, last_name, username, 
            contact, address, date_of_birth, email, 
            password, role, is_verified, created_at, updated_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'USER', 1, NOW(), NOW())";
        
        $user_stmt = $pdo->prepare($user_query);
        $user_stmt->execute([
            $volunteer['first_name'],
            $volunteer['middle_name'],
            $volunteer['last_name'],
            $username,
            $volunteer['contact_number'],
            $volunteer['address'],
            $volunteer['date_of_birth'],
            $volunteer['email'],
            $hashed_password
        ]);
        
        $user_id = $pdo->lastInsertId();
        
        // Update volunteer record with user_id
        $update_volunteer_query = "UPDATE volunteers SET user_id = ? WHERE id = ?";
        $update_volunteer_stmt = $pdo->prepare($update_volunteer_query);
        $update_volunteer_stmt->execute([$user_id, $volunteer_id]);
        
        // Log the creation (optional)
        $log_query = "INSERT INTO email_logs (recipient, subject, body, status) VALUES (?, ?, ?, 'sent')";
        $log_stmt = $pdo->prepare($log_query);
        $log_stmt->execute([
            $volunteer['email'],
            'Volunteer Application Approved - Account Created',
            "Dear " . $volunteer['first_name'] . ",\n\n" .
            "Your volunteer application has been approved!\n\n" .
            "Your login credentials:\n" .
            "Username: " . $username . "\n" .
            "Password: " . $default_password . "\n\n" .
            "Please login at: [Your Login URL]\n\n" .
            "Note: This is your default password. Please change it after your first login.\n\n" .
            "Best regards,\nFire & Rescue Team",
        ]);
    }
    
    // Commit transaction
    $pdo->commit();
    
    header('Content-Type: application/json');
    echo json_encode([
        'success' => true, 
        'message' => 'Status updated successfully' . ($status === 'approved' ? ' and user account created' : '')
    ]);
    
} catch (Exception $e) {
    // Rollback transaction on error
    $pdo->rollBack();
    
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false, 
        'message' => 'Failed to update status: ' . $e->getMessage()
    ]);
}
?>