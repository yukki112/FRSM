<?php
session_start();
require_once '../../config/db_connection.php';

if (!isset($_SESSION['user_id'])) {
    header("HTTP/1.1 403 Forbidden");
    exit();
}

$user_id = $_SESSION['user_id'];
$query = "SELECT role FROM users WHERE id = ?";
$stmt = $pdo->prepare($query);
$stmt->execute([$user_id]);
$user = $stmt->fetch();

if (!$user || ($user['role'] !== 'EMPLOYEE' && $user['role'] !== 'ADMIN')) {
    header("HTTP/1.1 403 Forbidden");
    exit();
}

if (!isset($_GET['training_id'])) {
    header("HTTP/1.1 400 Bad Request");
    echo "Training ID is required";
    exit();
}

$training_id = intval($_GET['training_id']);

// Function to get volunteers in training
function getVolunteersInTraining($pdo, $training_id) {
    $sql = "SELECT tr.*, v.id as volunteer_id, v.first_name, v.last_name, 
                   v.contact_number, v.email, v.specialized_training,
                   u.username as user_username,
                   tr.completion_date, tr.notes as completion_notes,
                   tr.completion_verified, tr.completion_verified_at,
                   tr.completion_proof
            FROM training_registrations tr
            INNER JOIN volunteers v ON tr.volunteer_id = v.id
            LEFT JOIN users u ON tr.user_id = u.id
            WHERE tr.training_id = ? 
            AND tr.completion_status = 'completed'
            ORDER BY v.last_name, v.first_name";
    
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$training_id]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Error fetching training volunteers: " . $e->getMessage());
        return [];
    }
}

// Function to get training info
function getTrainingInfo($pdo, $training_id) {
    $sql = "SELECT title, training_date, training_end_date, instructor, location 
            FROM trainings WHERE id = ?";
    
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$training_id]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Error fetching training info: " . $e->getMessage());
        return null;
    }
}

$volunteers = getVolunteersInTraining($pdo, $training_id);
$training = getTrainingInfo($pdo, $training_id);

if (empty($volunteers)) {
    echo '<div class="empty-state">';
    echo '<i class="bx bx-user-x"></i>';
    echo '<h3>No Volunteers Found</h3>';
    echo '<p>No volunteers have completed this training yet.</p>';
    echo '</div>';
    exit();
}
?>

<div class="volunteers-list">
    <div style="margin-bottom: 20px; padding: 15px; background: rgba(59, 130, 246, 0.05); border-radius: 10px; border-left: 4px solid var(--info);">
        <div style="font-weight: 700; margin-bottom: 5px;">Training Information</div>
        <div style="font-size: 13px; color: var(--text-light);">
            <strong>Title:</strong> <?php echo htmlspecialchars($training['title']); ?> | 
            <strong>Instructor:</strong> <?php echo htmlspecialchars($training['instructor']); ?> | 
            <strong>Date:</strong> <?php echo date('M j, Y', strtotime($training['training_date'])); ?>
        </div>
    </div>
    
    <?php foreach ($volunteers as $volunteer): 
        $full_name = htmlspecialchars($volunteer['first_name'] . ' ' . $volunteer['last_name']);
        $completion_date = $volunteer['completion_date'] ? date('M j, Y', strtotime($volunteer['completion_date'])) : 'Not specified';
        $is_verified = $volunteer['completion_verified'] == 1;
        $proof_file = $volunteer['completion_proof'];
    ?>
    <div class="volunteer-item">
        <div class="volunteer-header">
            <div class="volunteer-info">
                <div class="volunteer-avatar">
                    <?php echo strtoupper(substr($volunteer['first_name'], 0, 1)); ?>
                </div>
                <div class="volunteer-details">
                    <div class="volunteer-name"><?php echo $full_name; ?></div>
                    <div class="volunteer-contact">
                        <i class='bx bx-phone'></i> <?php echo htmlspecialchars($volunteer['contact_number']); ?> | 
                        <i class='bx bx-envelope'></i> <?php echo htmlspecialchars($volunteer['email']); ?>
                    </div>
                </div>
            </div>
            <div class="volunteer-status">
                <span class="verification-badge <?php echo $is_verified ? 'badge-verified' : 'badge-pending'; ?>">
                    <?php echo $is_verified ? 'Verified' : 'Pending Verification'; ?>
                </span>
                <?php if (!$is_verified): ?>
                <button type="button" class="btn btn-sm btn-success verify-volunteer-btn"
                        data-registration-id="<?php echo $volunteer['id']; ?>"
                        data-volunteer-name="<?php echo $full_name; ?>"
                        data-training-title="<?php echo htmlspecialchars($training['title']); ?>">
                    <i class='bx bx-check'></i>
                    Verify
                </button>
                <?php endif; ?>
            </div>
        </div>
        
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 15px; margin-top: 10px;">
            <div>
                <div style="font-size: 12px; color: var(--text-light); margin-bottom: 5px;">Completion Date</div>
                <div style="font-weight: 600;"><?php echo $completion_date; ?></div>
            </div>
            <div>
                <div style="font-size: 12px; color: var(--text-light); margin-bottom: 5px;">User Account</div>
                <div style="font-weight: 600;">
                    <?php echo $volunteer['user_username'] ? htmlspecialchars($volunteer['user_username']) : 'No account linked'; ?>
                </div>
            </div>
            <?php if ($proof_file): ?>
            <div>
                <div style="font-size: 12px; color: var(--text-light); margin-bottom: 5px;">Proof File</div>
                <a href="../../uploads/training_proofs/<?php echo htmlspecialchars($proof_file); ?>" 
                   target="_blank" class="btn btn-sm btn-secondary">
                    <i class='bx bx-file'></i>
                    View Proof
                </a>
            </div>
            <?php endif; ?>
        </div>
        
        <?php if ($volunteer['completion_notes']): ?>
        <div style="margin-top: 15px;">
            <div style="font-size: 12px; color: var(--text-light); margin-bottom: 5px;">Notes</div>
            <div style="padding: 10px; background: var(--card-bg); border: 1px solid var(--border-color); border-radius: 8px; font-size: 13px;">
                <?php echo nl2br(htmlspecialchars($volunteer['completion_notes'])); ?>
            </div>
        </div>
        <?php endif; ?>
        
        <?php if ($volunteer['specialized_training']): ?>
        <div style="margin-top: 10px;">
            <div style="font-size: 12px; color: var(--text-light); margin-bottom: 5px;">Previous Training</div>
            <div style="font-size: 13px;"><?php echo htmlspecialchars($volunteer['specialized_training']); ?></div>
        </div>
        <?php endif; ?>
    </div>
    <?php endforeach; ?>
</div>