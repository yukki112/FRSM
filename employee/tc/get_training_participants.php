<?php
session_start();
require_once '../../config/db_connection.php';

if (!isset($_SESSION['user_id'])) {
    die(json_encode(['error' => 'Unauthorized']));
}

$training_id = $_GET['training_id'] ?? null;

if (!$training_id) {
    die('<div class="empty-state"><i class="bx bx-user-x"></i><h3>No training selected</h3><p>Please select a training to view participants.</p></div>');
}

// Function to get training participants (same as in main file)
function getTrainingParticipants($pdo, $training_id) {
    $sql = "SELECT tr.*, v.first_name, v.last_name, v.contact_number, v.email,
            u.username as user_username
            FROM training_registrations tr
            INNER JOIN volunteers v ON tr.volunteer_id = v.id
            LEFT JOIN users u ON tr.user_id = u.id
            WHERE tr.training_id = ?
            ORDER BY tr.registration_date DESC";
    
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$training_id]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Error fetching training participants: " . $e->getMessage());
        return [];
    }
}

// Get participants
$participants = getTrainingParticipants($pdo, $training_id);

if (count($participants) === 0) {
    echo '<div class="empty-state"><i class="bx bx-user-x"></i><h3>No Participants</h3><p>No volunteers have registered for this training yet.</p></div>';
    exit();
}

// Display participants
?>
<div class="participants-list">
    <?php foreach ($participants as $participant): 
        $status_class = 'badge-' . $participant['status'];
        $completion_class = 'badge-' . $participant['completion_status'];
        $completion_display = str_replace('_', ' ', $participant['completion_status']);
        $completion_display = ucwords($completion_display);
    ?>
    <div class="participant-item">
        <div class="participant-info">
            <div class="participant-avatar">
                <?php echo strtoupper(substr($participant['first_name'], 0, 1)); ?>
            </div>
            <div class="participant-details">
                <div class="participant-name">
                    <?php echo htmlspecialchars($participant['first_name'] . ' ' . $participant['last_name']); ?>
                </div>
                <div class="participant-contact">
                    <i class='bx bx-phone'></i> <?php echo htmlspecialchars($participant['contact_number']); ?>
                    <?php if ($participant['email']): ?>
                        <span style="margin-left: 10px;">
                            <i class='bx bx-envelope'></i> <?php echo htmlspecialchars($participant['email']); ?>
                        </span>
                    <?php endif; ?>
                </div>
                <div class="participant-contact" style="margin-top: 4px;">
                    <i class='bx bx-calendar'></i> Registered: <?php echo date('M j, Y', strtotime($participant['registration_date'])); ?>
                </div>
            </div>
        </div>
        
        <div class="participant-status">
            <div class="registration-badge <?php echo $status_class; ?>">
                <?php echo ucfirst($participant['status']); ?>
            </div>
            
            <div class="completion-badge <?php echo $completion_class; ?>" style="margin-top: 5px;">
                <?php echo $completion_display; ?>
            </div>
            
            <?php if ($participant['completion_date']): ?>
                <div class="certificate-info" style="margin-top: 5px;">
                    <i class='bx bx-calendar-check'></i> Completed: <?php echo date('M j, Y', strtotime($participant['completion_date'])); ?>
                </div>
            <?php endif; ?>
            
            <?php if ($participant['certificate_issued']): ?>
                <div class="certificate-info" style="margin-top: 5px; color: var(--success);">
                    <i class='bx bx-certificate'></i> Certificate Issued
                </div>
            <?php elseif ($participant['employee_submitted']): ?>
                <div class="certificate-info" style="margin-top: 5px; color: var(--warning);">
                    <i class='bx bx-time'></i> Submitted to Admin
                </div>
            <?php elseif ($participant['admin_approved']): ?>
                <div class="certificate-info" style="margin-top: 5px; color: var(--info);">
                    <i class='bx bx-check-circle'></i> Approved by Admin
                </div>
            <?php endif; ?>
        </div>
    </div>
    <?php endforeach; ?>
</div>