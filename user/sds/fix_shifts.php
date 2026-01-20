<?php
session_start();
require_once '../../config/db_connection.php';

// Only allow volunteers to fix their own shifts
if (!isset($_SESSION['user_id'])) {
    die('Not authorized');
}

$user_id = $_SESSION['user_id'];

// Check if user is a volunteer
$user_query = "SELECT role FROM users WHERE id = ?";
$user_stmt = $pdo->prepare($user_query);
$user_stmt->execute([$user_id]);
$user = $user_stmt->fetch();

if (!$user || $user['role'] !== 'USER') {
    die('Not authorized');
}

// Get volunteer ID
$volunteer_query = "SELECT id FROM volunteers WHERE user_id = ?";
$volunteer_stmt = $pdo->prepare($volunteer_query);
$volunteer_stmt->execute([$user_id]);
$volunteer = $volunteer_stmt->fetch();

if (!$volunteer) {
    die('Not a volunteer');
}

$volunteer_id = $volunteer['id'];

// Check if requested volunteer ID matches logged in user
$requested_volunteer_id = $_POST['volunteer_id'] ?? 0;
if ($requested_volunteer_id != $volunteer_id) {
    die('Invalid volunteer ID');
}

$action = $_POST['action'] ?? '';

try {
    if ($action === 'fix_status') {
        // Fix shift confirmation status
        $update_query = "UPDATE shifts 
                        SET confirmation_status = 'pending', 
                            status = 'scheduled',
                            updated_at = NOW()
                        WHERE volunteer_id = ? 
                        AND shift_date >= CURDATE()
                        AND confirmation_status IS NOT NULL
                        AND confirmation_status != 'confirmed'";
        
        $stmt = $pdo->prepare($update_query);
        $stmt->execute([$volunteer_id]);
        
        echo "Fixed " . $stmt->rowCount() . " shift(s)";
        
    } elseif ($action === 'add_test_shift') {
        // Add a test shift for tomorrow
        $tomorrow = date('Y-m-d', strtotime('+1 day'));
        
        $insert_query = "INSERT INTO shifts 
                        (volunteer_id, shift_for, unit_id, shift_date, shift_type, start_time, end_time, status, location, notes, created_by, confirmation_status, created_at, updated_at)
                        VALUES (?, 'volunteer', 1, ?, 'morning', '08:00:00', '16:00:00', 'scheduled', 'Main Station', 'Test shift for confirmation', 8, 'pending', NOW(), NOW())";
        
        $stmt = $pdo->prepare($insert_query);
        $stmt->execute([$volunteer_id, $tomorrow]);
        
        echo "Test shift added for " . $tomorrow;
    }
    
} catch (Exception $e) {
    die("Error: " . $e->getMessage());
}
?>