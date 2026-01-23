<?php
session_start();
require_once '../config/db_connection.php';

if (!isset($_SESSION['user_id'])) {
    die('Unauthorized');
}

$id = $_GET['id'] ?? 0;
$user_id = $_SESSION['user_id'];

$query = "
    SELECT 
        mr.*,
        r.resource_name,
        r.resource_type,
        r.category,
        r.condition_status,
        r.location,
        u.unit_name,
        requested_user.first_name as requester_first_name,
        requested_user.last_name as requester_last_name,
        requested_user.email as requester_email,
        approved_user.first_name as approver_first_name,
        approved_user.last_name as approver_last_name,
        approved_user.email as approver_email,
        completed_user.first_name as completer_first_name,
        completed_user.last_name as completer_last_name,
        completed_user.email as completer_email
    FROM maintenance_requests mr
    JOIN resources r ON mr.resource_id = r.id
    LEFT JOIN units u ON r.unit_id = u.id
    LEFT JOIN users requested_user ON mr.requested_by = requested_user.id
    LEFT JOIN users approved_user ON mr.approved_by = approved_user.id
    LEFT JOIN users completed_user ON mr.completed_by = completed_user.id
    WHERE mr.id = ? AND mr.requested_by = ?
";

$stmt = $pdo->prepare($query);
$stmt->execute([$id, $user_id]);
$request = $stmt->fetch();

if ($request) {
    $status_class = 'status-' . $request['status'];
    $priority_class = 'priority-' . $request['priority'];
    
    $requester_name = $request['requester_first_name'] . ' ' . $request['requester_last_name'];
    $approver_name = $request['approver_first_name'] ? 
        $request['approver_first_name'] . ' ' . $request['approver_last_name'] : 'Not approved';
    $completer_name = $request['completer_first_name'] ? 
        $request['completer_first_name'] . ' ' . $request['completer_last_name'] : 'Not completed';
    
    echo '<div style="padding: 20px;">';
    echo '<h3 style="margin-bottom: 20px; color: var(--text-color);">' . htmlspecialchars($request['resource_name']) . '</h3>';
    
    echo '<div class="details-grid">';
    echo '<div class="detail-group">';
    echo '<div class="detail-label">Request Type</div>';
    echo '<div class="detail-value">' . ucfirst(str_replace('_', ' ', $request['request_type'])) . '</div>';
    echo '</div>';
    
    echo '<div class="detail-group">';
    echo '<div class="detail-label">Priority</div>';
    echo '<div class="detail-value"><span class="priority-badge ' . $priority_class . '">' . ucfirst($request['priority']) . '</span></div>';
    echo '</div>';
    
    echo '<div class="detail-group">';
    echo '<div class="detail-label">Status</div>';
    echo '<div class="detail-value"><span class="status-badge ' . $status_class . '">' . ucfirst(str_replace('_', ' ', $request['status'])) . '</span></div>';
    echo '</div>';
    
    echo '<div class="detail-group">';
    echo '<div class="detail-label">Date Requested</div>';
    echo '<div class="detail-value">' . date('F j, Y g:i A', strtotime($request['requested_date'])) . '</div>';
    echo '</div>';
    
    if ($request['scheduled_date']) {
        echo '<div class="detail-group">';
        echo '<div class="detail-label">Scheduled Date</div>';
        echo '<div class="detail-value">' . date('F j, Y', strtotime($request['scheduled_date'])) . '</div>';
        echo '</div>';
    }
    
    if ($request['estimated_cost']) {
        echo '<div class="detail-group">';
        echo '<div class="detail-label">Estimated Cost</div>';
        echo '<div class="detail-value">₱' . number_format($request['estimated_cost'], 2) . '</div>';
        echo '</div>';
    }
    
    echo '<div class="detail-group">';
    echo '<div class="detail-label">Equipment Category</div>';
    echo '<div class="detail-value">' . htmlspecialchars($request['category']) . '</div>';
    echo '</div>';
    
    echo '<div class="detail-group">';
    echo '<div class="detail-label">Equipment Location</div>';
    echo '<div class="detail-value">' . htmlspecialchars($request['location'] ?: 'Not specified') . '</div>';
    echo '</div>';
    
    echo '<div class="detail-group">';
    echo '<div class="detail-label">Assigned Unit</div>';
    echo '<div class="detail-value">' . htmlspecialchars($request['unit_name'] ?: 'Unassigned') . '</div>';
    echo '</div>';
    echo '</div>';
    
    echo '<div class="detail-group" style="margin-top: 20px;">';
    echo '<div class="detail-label">Description</div>';
    echo '<div class="detail-value" style="background: var(--card-bg); padding: 15px; border-radius: 8px; border: 1px solid var(--border-color);">';
    echo nl2br(htmlspecialchars($request['description']));
    echo '</div>';
    echo '</div>';
    
    if ($request['notes']) {
        echo '<div class="detail-group" style="margin-top: 20px;">';
        echo '<div class="detail-label">Additional Notes</div>';
        echo '<div class="detail-value" style="background: var(--card-bg); padding: 15px; border-radius: 8px; border: 1px solid var(--border-color);">';
        echo nl2br(htmlspecialchars($request['notes']));
        echo '</div>';
        echo '</div>';
    }
    
    echo '<div style="margin-top: 30px; padding-top: 20px; border-top: 2px solid var(--border-color);">';
    echo '<h4 style="margin-bottom: 15px; color: var(--text-color);">Request Timeline</h4>';
    echo '<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px;">';
    
    echo '<div style="background: var(--card-bg); padding: 12px; border-radius: 8px; border: 1px solid var(--border-color);">';
    echo '<div style="font-size: 12px; color: var(--text-light); margin-bottom: 5px;">Requested By</div>';
    echo '<div style="font-weight: 600;">' . htmlspecialchars($requester_name) . '</div>';
    echo '<div style="font-size: 11px; color: var(--text-light);">' . date('M d, Y', strtotime($request['requested_date'])) . '</div>';
    echo '</div>';
    
    if ($request['approved_date']) {
        echo '<div style="background: var(--card-bg); padding: 12px; border-radius: 8px; border: 1px solid var(--border-color);">';
        echo '<div style="font-size: 12px; color: var(--text-light); margin-bottom: 5px;">Approved By</div>';
        echo '<div style="font-weight: 600;">' . htmlspecialchars($approver_name) . '</div>';
        echo '<div style="font-size: 11px; color: var(--text-light);">' . date('M d, Y', strtotime($request['approved_date'])) . '</div>';
        echo '</div>';
    }
    
    if ($request['completed_date']) {
        echo '<div style="background: var(--card-bg); padding: 12px; border-radius: 8px; border: 1px solid var(--border-color);">';
        echo '<div style="font-size: 12px; color: var(--text-light); margin-bottom: 5px;">Completed By</div>';
        echo '<div style="font-weight: 600;">' . htmlspecialchars($completer_name) . '</div>';
        echo '<div style="font-size: 11px; color: var(--text-light);">' . date('M d, Y', strtotime($request['completed_date'])) . '</div>';
        echo '</div>';
    }
    
    echo '</div>';
    echo '</div>';
    
    echo '</div>';
} else {
    echo '<div style="padding: 20px; text-align: center; color: var(--text-light);">';
    echo '<i class="bx bx-error" style="font-size: 48px; margin-bottom: 20px;"></i>';
    echo '<p>Request not found or you do not have permission to view it.</p>';
    echo '</div>';
}
?>