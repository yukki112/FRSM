<?php
session_start();
require_once '../../config/db_connection.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

if (!isset($_GET['id'])) {
    echo json_encode(['success' => false, 'message' => 'Incident ID required']);
    exit();
}

$incident_id = $_GET['id'];

try {
    $sql = "SELECT * FROM incident_reports WHERE external_id = ?";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$incident_id]);
    $incident = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($incident) {
        echo json_encode(['success' => true, 'incident' => $incident]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Incident not found']);
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Error fetching incident: ' . $e->getMessage()]);
}