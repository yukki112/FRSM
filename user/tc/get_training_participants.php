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

// Get user role to check if they're a volunteer
$user_id = $_SESSION['user_id'];
$user_query = $pdo->prepare("SELECT role FROM users WHERE id = ?");
$user_query->execute([$user_id]);
$user_role = $user_query->fetch()['role'];

// Get training details
$training_query = $pdo->prepare("SELECT title, training_date, location FROM trainings WHERE id = ?");
$training_query->execute([$training_id]);
$training = $training_query->fetch();

if (!$training) {
    die('<div class="empty-state"><i class="bx bx-error"></i><h3>Training Not Found</h3><p>The training you are looking for does not exist.</p></div>');
}

// Function to get training participants (same as in main file)
function getTrainingParticipants($pdo, $training_id) {
    $sql = "SELECT tr.*, 
            v.first_name, v.last_name, v.contact_number, v.email,
            v.volunteer_status,
            CASE 
                WHEN tr.completion_status = 'completed' AND tr.admin_approved = 1 THEN 'certified'
                WHEN tr.completion_status = 'completed' AND tr.employee_submitted = 1 THEN 'pending_approval'
                WHEN tr.completion_status = 'completed' THEN 'needs_verification'
                WHEN tr.completion_status = 'in_progress' THEN 'in_progress'
                ELSE 'registered'
            END as participant_status
            FROM training_registrations tr
            INNER JOIN volunteers v ON tr.volunteer_id = v.id
            WHERE tr.training_id = ? 
            AND tr.status != 'cancelled'
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
$total_participants = count($participants);

// Display training info and participants
?>
<div style="margin-bottom: 20px;">
    <h4 style="margin: 0 0 10px 0; color: var(--text-color);"><?php echo htmlspecialchars($training['title']); ?></h4>
    <div style="display: flex; gap: 15px; font-size: 13px; color: var(--text-light);">
        <div><i class='bx bx-calendar'></i> <?php echo date('M j, Y', strtotime($training['training_date'])); ?></div>
        <?php if ($training['location']): ?>
            <div><i class='bx bx-map'></i> <?php echo htmlspecialchars($training['location']); ?></div>
        <?php endif; ?>
        <div><i class='bx bx-group'></i> <?php echo $total_participants; ?> participants</div>
    </div>
</div>

<?php if ($total_participants === 0): ?>
    <div class="empty-state" style="padding: 30px 20px;">
        <i class='bx bx-user-x'></i>
        <h3>No Participants</h3>
        <p>No volunteers have registered for this training yet.</p>
    </div>
<?php else: ?>
    <div class="participants-list">
        <?php foreach ($participants as $participant): 
            $status_class = 'status-' . $participant['participant_status'];
            $status_display = str_replace('_', ' ', $participant['participant_status']);
            $status_display = ucwords($status_display);
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
                    <div style="font-size: 11px; color: var(--text-light); margin-top: 2px;">
                        <?php if ($user_role === 'EMPLOYEE' || $user_role === 'ADMIN'): ?>
                            <i class='bx bx-phone'></i> <?php echo htmlspecialchars($participant['contact_number']); ?>
                        <?php else: ?>
                            <i class='bx bx-user'></i> Volunteer
                        <?php endif; ?>
                        <?php if ($participant['volunteer_status']): ?>
                            <span style="margin-left: 10px;">
                                <i class='bx bx-badge-check'></i> <?php echo ucfirst($participant['volunteer_status']); ?>
                            </span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            
            <div class="participant-status">
                <div class="participant-status-badge <?php echo $status_class; ?>">
                    <?php echo $status_display; ?>
                </div>
                
                <?php if ($participant['completion_date']): ?>
                    <div style="font-size: 10px; color: var(--text-light); margin-top: 4px;">
                        <i class='bx bx-calendar-check'></i> <?php echo date('M j, Y', strtotime($participant['completion_date'])); ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    
    <div style="margin-top: 20px; padding-top: 15px; border-top: 1px solid var(--border-color);">
        <div style="display: flex; gap: 15px; font-size: 12px; color: var(--text-light); flex-wrap: wrap;">
            <div style="display: flex; align-items: center; gap: 5px;">
                <div style="width: 10px; height: 10px; border-radius: 50%; background: rgba(16, 185, 129, 0.3); border: 1px solid rgba(16, 185, 129, 0.5);"></div>
                <span>Certified</span>
            </div>
            <div style="display: flex; align-items: center; gap: 5px;">
                <div style="width: 10px; height: 10px; border-radius: 50%; background: rgba(245, 158, 11, 0.3); border: 1px solid rgba(245, 158, 11, 0.5);"></div>
                <span>Pending Approval</span>
            </div>
            <div style="display: flex; align-items: center; gap: 5px;">
                <div style="width: 10px; height: 10px; border-radius: 50%; background: rgba(59, 130, 246, 0.3); border: 1px solid rgba(59, 130, 246, 0.5);"></div>
                <span>Needs Verification</span>
            </div>
            <div style="display: flex; align-items: center; gap: 5px;">
                <div style="width: 10px; height: 10px; border-radius: 50%; background: rgba(139, 92, 246, 0.3); border: 1px solid rgba(139, 92, 246, 0.5);"></div>
                <span>In Progress</span>
            </div>
            <div style="display: flex; align-items: center; gap: 5px;">
                <div style="width: 10px; height: 10px; border-radius: 50%; background: rgba(156, 163, 175, 0.3); border: 1px solid rgba(156, 163, 175, 0.5);"></div>
                <span>Registered</span>
            </div>
        </div>
    </div>
<?php endif; ?>