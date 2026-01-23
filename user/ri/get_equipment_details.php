<?php
session_start();
require_once '../../config/db_connection.php';

if (!isset($_SESSION['user_id'])) {
    die('Unauthorized');
}

$id = $_GET['id'] ?? 0;

$query = "SELECT * FROM resources WHERE id = ?";
$stmt = $pdo->prepare($query);
$stmt->execute([$id]);
$equipment = $stmt->fetch();

if ($equipment) {
    $available_percentage = $equipment['quantity'] > 0 ? 
        ($equipment['available_quantity'] / $equipment['quantity']) * 100 : 0;
    
    $status_class = '';
    switch ($equipment['condition_status']) {
        case 'Serviceable': $status_class = 'status-serviceable'; break;
        case 'Under Maintenance': $status_class = 'status-maintenance'; break;
        case 'Condemned': $status_class = 'status-condemned'; break;
        case 'Out of Service': $status_class = 'status-out'; break;
    }
    
    echo '<div style="padding: 20px;">';
    echo '<h3 style="margin-bottom: 20px;">' . htmlspecialchars($equipment['resource_name']) . '</h3>';
    
    echo '<div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 20px;">';
    echo '<div><strong>Type:</strong><br>' . htmlspecialchars($equipment['resource_type']) . '</div>';
    echo '<div><strong>Category:</strong><br>' . htmlspecialchars($equipment['category']) . '</div>';
    echo '<div><strong>Quantity:</strong><br>' . $equipment['quantity'] . ' ' . ($equipment['unit_of_measure'] ?: 'units') . '</div>';
    echo '<div><strong>Available:</strong><br>' . $equipment['available_quantity'] . ' (' . round($available_percentage, 1) . '%)</div>';
    echo '<div><strong>Status:</strong><br><span class="status-badge ' . $status_class . '">' . htmlspecialchars($equipment['condition_status']) . '</span></div>';
    echo '<div><strong>Location:</strong><br>' . htmlspecialchars($equipment['location'] ?: 'Not specified') . '</div>';
    echo '</div>';
    
    if ($equipment['description']) {
        echo '<div style="margin-bottom: 20px;">';
        echo '<strong>Description:</strong>';
        echo '<p style="margin-top: 5px; color: var(--text-light);">' . nl2br(htmlspecialchars($equipment['description'])) . '</p>';
        echo '</div>';
    }
    
    if ($equipment['last_inspection']) {
        echo '<div style="margin-bottom: 20px;">';
        echo '<strong>Last Inspection:</strong><br>' . date('F j, Y', strtotime($equipment['last_inspection']));
        if ($equipment['next_inspection']) {
            echo '<br><strong>Next Inspection:</strong><br>' . date('F j, Y', strtotime($equipment['next_inspection']));
        }
        echo '</div>';
    }
    
    if ($equipment['maintenance_notes']) {
        echo '<div style="margin-bottom: 20px;">';
        echo '<strong>Maintenance Notes:</strong>';
        echo '<p style="margin-top: 5px; color: var(--text-light);">' . nl2br(htmlspecialchars($equipment['maintenance_notes'])) . '</p>';
        echo '</div>';
    }
    
    echo '</div>';
} else {
    echo '<div style="padding: 20px; text-align: center; color: var(--text-light);">';
    echo '<i class="bx bx-error" style="font-size: 48px; margin-bottom: 20px;"></i>';
    echo '<p>Equipment not found.</p>';
    echo '</div>';
}
?>