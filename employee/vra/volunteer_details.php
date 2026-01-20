<?php
session_start();
require_once '../../config/db_connection.php';

if (!isset($_SESSION['user_id'])) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

if (!isset($_GET['id'])) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Volunteer ID required']);
    exit();
}

$volunteer_id = intval($_GET['id']);

try {
    $query = "SELECT * FROM volunteers WHERE id = ?";
    $stmt = $pdo->prepare($query);
    $stmt->execute([$volunteer_id]);
    $volunteer = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($volunteer) {
        header('Content-Type: application/json');
        echo json_encode(['success' => true, 'volunteer' => $volunteer]);
    } else {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Volunteer not found']);
    }
} catch (PDOException $e) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}