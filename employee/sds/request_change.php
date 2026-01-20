<?php
session_start();
require_once '../../config/db_connection.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: ../../login/login.php");
    exit();
}

$user_id = $_SESSION['user_id'];
$query = "SELECT first_name, middle_name, last_name, role, avatar FROM users WHERE id = ?";
$stmt = $pdo->prepare($query);
$stmt->execute([$user_id]);
$user = $stmt->fetch();

if ($user) {
    $first_name = htmlspecialchars($user['first_name']);
    $middle_name = htmlspecialchars($user['middle_name']);
    $last_name = htmlspecialchars($user['last_name']);
    $role = htmlspecialchars($user['role']);
    $avatar = htmlspecialchars($user['avatar']);
    
    $full_name = $first_name;
    if (!empty($middle_name)) {
        $full_name .= " " . $middle_name;
    }
    $full_name .= " " . $last_name;
} else {
    $full_name = "User";
    $role = "USER";
    $avatar = "";
}

// Check if user has permission (EMPLOYEE or ADMIN only)
if ($role !== 'EMPLOYEE' && $role !== 'ADMIN') {
    header("Location: ../employee_dashboard.php");
    exit();
}

// Function to get shift change requests with filters
function getShiftChangeRequests($pdo, $filter_status = null, $filter_date_from = null, $filter_date_to = null, $filter_volunteer = null) {
    $sql = "SELECT 
                scr.id as request_id,
                scr.shift_id,
                scr.volunteer_id,
                scr.request_type,
                scr.request_details,
                scr.proposed_date,
                scr.proposed_start_time,
                scr.proposed_end_time,
                scr.swap_with_volunteer_id,
                scr.status as request_status,
                scr.admin_notes,
                scr.requested_at,
                scr.reviewed_at,
                scr.reviewed_by,
                s.shift_date,
                s.start_time as original_start_time,
                s.end_time as original_end_time,
                s.location as shift_location,
                s.notes as shift_notes,
                s.confirmation_status as shift_confirmation_status,
                s.status as shift_status,
                v.first_name as volunteer_first_name,
                v.last_name as volunteer_last_name,
                v.contact_number as volunteer_contact,
                v.email as volunteer_email,
                v.volunteer_status,
                u.id as unit_id,
                u.unit_name,
                u.unit_code,
                v2.first_name as swap_first_name,
                v2.last_name as swap_last_name,
                v2.contact_number as swap_contact,
                v2.email as swap_email,
                u2.unit_name as swap_unit_name,
                ru.first_name as reviewer_first_name,
                ru.last_name as reviewer_last_name
            FROM shift_change_requests scr
            INNER JOIN shifts s ON scr.shift_id = s.id
            INNER JOIN volunteers v ON scr.volunteer_id = v.id
            LEFT JOIN units u ON s.unit_id = u.id
            LEFT JOIN volunteers v2 ON scr.swap_with_volunteer_id = v2.id
            LEFT JOIN volunteer_assignments va2 ON v2.id = va2.volunteer_id AND va2.status = 'Active'
            LEFT JOIN units u2 ON va2.unit_id = u2.id
            LEFT JOIN users ru ON scr.reviewed_by = ru.id
            WHERE 1=1";
    
    $params = [];
    
    if ($filter_status && $filter_status !== 'all') {
        $sql .= " AND scr.status = ?";
        $params[] = $filter_status;
    }
    
    if ($filter_date_from) {
        $sql .= " AND DATE(s.shift_date) >= ?";
        $params[] = $filter_date_from;
    }
    
    if ($filter_date_to) {
        $sql .= " AND DATE(s.shift_date) <= ?";
        $params[] = $filter_date_to;
    }
    
    if ($filter_volunteer && $filter_volunteer !== 'all') {
        $sql .= " AND scr.volunteer_id = ?";
        $params[] = $filter_volunteer;
    }
    
    $sql .= " ORDER BY 
                CASE scr.status
                    WHEN 'pending' THEN 1
                    WHEN 'approved' THEN 2
                    WHEN 'rejected' THEN 3
                    WHEN 'cancelled' THEN 4
                    ELSE 5
                END,
                s.shift_date ASC,
                scr.requested_at DESC";
    
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Error fetching shift change requests: " . $e->getMessage());
        return [];
    }
}

// Function to get duty assignments with pagination
function getDutyAssignments($pdo, $page = 1, $per_page = 5, $filter_shift_id = null, $filter_duty_type = null) {
    $offset = ($page - 1) * $per_page;
    
    $sql = "SELECT 
                da.id,
                da.shift_id,
                da.duty_type,
                da.duty_description,
                da.priority,
                da.required_equipment,
                da.required_training,
                da.notes as duty_notes,
                da.created_at as duty_created_at,
                s.shift_date,
                s.start_time,
                s.end_time,
                s.shift_type,
                s.location as shift_location,
                s.status as shift_status,
                v.id as volunteer_id,
                v.first_name as volunteer_first_name,
                v.last_name as volunteer_last_name,
                u.id as unit_id,
                u.unit_name,
                u.unit_code
            FROM duty_assignments da
            LEFT JOIN shifts s ON da.shift_id = s.id
            LEFT JOIN volunteers v ON s.volunteer_id = v.id
            LEFT JOIN units u ON s.unit_id = u.id
            WHERE 1=1";
    
    $count_sql = "SELECT COUNT(*) as total FROM duty_assignments da WHERE 1=1";
    
    $params = [];
    $count_params = [];
    
    if ($filter_shift_id) {
        $sql .= " AND da.shift_id = ?";
        $count_sql .= " AND da.shift_id = ?";
        $params[] = $filter_shift_id;
        $count_params[] = $filter_shift_id;
    }
    
    if ($filter_duty_type) {
        $sql .= " AND da.duty_type = ?";
        $count_sql .= " AND da.duty_type = ?";
        $params[] = $filter_duty_type;
        $count_params[] = $filter_duty_type;
    }
    
    // Fix for MariaDB: Direct integer concatenation for LIMIT and OFFSET
    $sql .= " ORDER BY da.created_at DESC LIMIT " . (int)$per_page . " OFFSET " . (int)$offset;
    
    // Get total count
    $stmt_count = $pdo->prepare($count_sql);
    $stmt_count->execute($count_params);
    $total_result = $stmt_count->fetch(PDO::FETCH_ASSOC);
    $total_duties = $total_result['total'];
    
    // Get paginated data
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $duties = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    return [
        'duties' => $duties,
        'total' => $total_duties,
        'page' => $page,
        'per_page' => $per_page,
        'total_pages' => ceil($total_duties / $per_page)
    ];
}

// Function to get all volunteers
function getVolunteers($pdo) {
    $sql = "SELECT id, first_name, last_name FROM volunteers WHERE status = 'approved' ORDER BY first_name, last_name";
    try {
        $stmt = $pdo->query($sql);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Error fetching volunteers: " . $e->getMessage());
        return [];
    }
}

// Function to get request statistics
function getRequestStatistics($pdo) {
    $sql = "SELECT 
                COUNT(*) as total_requests,
                SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending_requests,
                SUM(CASE WHEN status = 'approved' THEN 1 ELSE 0 END) as approved_requests,
                SUM(CASE WHEN status = 'rejected' THEN 1 ELSE 0 END) as rejected_requests,
                SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END) as cancelled_requests,
                COUNT(DISTINCT volunteer_id) as unique_volunteers,
                AVG(CASE 
                    WHEN status = 'approved' AND reviewed_at IS NOT NULL 
                    THEN TIMESTAMPDIFF(HOUR, requested_at, reviewed_at) 
                    ELSE NULL 
                END) as avg_processing_hours
            FROM shift_change_requests
            WHERE DATE(requested_at) >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)";
    
    try {
        $stmt = $pdo->query($sql);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    } catch (PDOException $e) {
        error_log("Error fetching statistics: " . $e->getMessage());
        return [
            'total_requests' => 0,
            'pending_requests' => 0,
            'approved_requests' => 0,
            'rejected_requests' => 0,
            'cancelled_requests' => 0,
            'unique_volunteers' => 0,
            'avg_processing_hours' => 0
        ];
    }
}

// Function to update request status - FIXED VERSION
function updateRequestStatus($pdo, $request_id, $status, $admin_id, $admin_notes = null) {
    try {
        // Start transaction
        $pdo->beginTransaction();
        
        // 1. Get the request details
        $sql = "SELECT scr.*, s.confirmation_status, s.status as shift_status, 
                       v.user_id as volunteer_user_id, s.volunteer_id as current_volunteer_id,
                       s.id as shift_id, scr.volunteer_id, scr.request_type, 
                       scr.swap_with_volunteer_id, scr.proposed_date, 
                       scr.proposed_start_time, scr.proposed_end_time
               FROM shift_change_requests scr
               INNER JOIN shifts s ON scr.shift_id = s.id
               INNER JOIN volunteers v ON scr.volunteer_id = v.id
               WHERE scr.id = ?";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$request_id]);
        $request = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$request) {
            throw new Exception("Shift change request not found.");
        }
        
        // 2. Update the request status
        $updateSql = "UPDATE shift_change_requests 
                     SET status = ?, 
                         admin_notes = ?, 
                         reviewed_at = NOW(), 
                         reviewed_by = ?
                     WHERE id = ?";
        
        $updateStmt = $pdo->prepare($updateSql);
        $updateResult = $updateStmt->execute([$status, $admin_notes, $admin_id, $request_id]);
        
        if (!$updateResult) {
            throw new Exception("Failed to update shift change request status.");
        }
        
        // 3. Process based on status
        if ($status === 'approved') {
            if ($request['request_type'] === 'swap' && $request['swap_with_volunteer_id']) {
                // Handle swap request
                handleSwapRequest($pdo, $request);
            } elseif (in_array($request['request_type'], ['time_change', 'date_change', 'other'])) {
                // Handle time/date change request
                handleTimeDateChange($pdo, $request);
            }
            
            // Create notification
            createNotification($pdo, $request['volunteer_user_id'], 
                'shift_change_approved', 
                'Shift Change Request Approved',
                'Your shift change request has been approved.' . ($admin_notes ? " Notes: $admin_notes" : ''));
                
        } elseif ($status === 'rejected') {
            // For rejected requests, reset shift confirmation status if needed
            if ($request['confirmation_status'] === 'change_requested') {
                $resetSql = "UPDATE shifts 
                            SET confirmation_status = 'confirmed', 
                                updated_at = NOW()
                            WHERE id = ?";
                $resetStmt = $pdo->prepare($resetSql);
                $resetStmt->execute([$request['shift_id']]);
            }
            
            // Create notification
            createNotification($pdo, $request['volunteer_user_id'], 
                'shift_change_rejected', 
                'Shift Change Request Rejected',
                'Your shift change request has been rejected.' . ($admin_notes ? " Reason: $admin_notes" : ''));
                
        } elseif ($status === 'cancelled') {
            // Create notification
            createNotification($pdo, $request['volunteer_user_id'], 
                'shift_change_cancelled', 
                'Shift Change Request Cancelled',
                'Your shift change request has been cancelled.' . ($admin_notes ? " Reason: $admin_notes" : ''));
        }
        
        // Commit transaction
        $pdo->commit();
        return true;
        
    } catch (Exception $e) {
        // Rollback on error
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log("Error in updateRequestStatus: " . $e->getMessage());
        return false;
    }
}

// Helper function for swap requests
function handleSwapRequest($pdo, $request) {
    // Check if swap volunteer exists and is approved
    $checkSql = "SELECT id, user_id FROM volunteers 
                 WHERE id = ? AND status = 'approved'";
    $checkStmt = $pdo->prepare($checkSql);
    $checkStmt->execute([$request['swap_with_volunteer_id']]);
    $swapVolunteer = $checkStmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$swapVolunteer) {
        throw new Exception("Swap volunteer not found or not approved.");
    }
    
    // Update the shift with new volunteer
    $updateShiftSql = "UPDATE shifts 
                      SET volunteer_id = ?, 
                          user_id = ?,
                          confirmation_status = 'pending',
                          status = 'scheduled',
                          updated_at = NOW()
                      WHERE id = ?";
    
    $updateShiftStmt = $pdo->prepare($updateShiftSql);
    $updateShiftStmt->execute([
        $swapVolunteer['id'],
        $swapVolunteer['user_id'],
        $request['shift_id']
    ]);
    
    // Remove old shift confirmation
    $deleteSql = "DELETE FROM shift_confirmations 
                  WHERE shift_id = ? AND volunteer_id = ?";
    $deleteStmt = $pdo->prepare($deleteSql);
    $deleteStmt->execute([$request['shift_id'], $request['volunteer_id']]);
    
    // Create new shift confirmation for the swap volunteer
    $confirmSql = "INSERT INTO shift_confirmations (shift_id, volunteer_id, status, responded_at)
                   VALUES (?, ?, 'pending', NULL)";
    $confirmStmt = $pdo->prepare($confirmSql);
    $confirmStmt->execute([$request['shift_id'], $swapVolunteer['id']]);
    
    // Get shift details for notification
    $shiftSql = "SELECT shift_date, start_time, end_time, location 
                 FROM shifts WHERE id = ?";
    $shiftStmt = $pdo->prepare($shiftSql);
    $shiftStmt->execute([$request['shift_id']]);
    $shift = $shiftStmt->fetch(PDO::FETCH_ASSOC);
    
    // Create notification for new volunteer
    if ($swapVolunteer['user_id'] && $shift) {
        createNotification($pdo, $swapVolunteer['user_id'],
            'new_shift',
            'New Shift Assigned',
            "You have been assigned a new shift on " . 
            date('F j, Y', strtotime($shift['shift_date'])) . 
            " from " . date('g:i A', strtotime($shift['start_time'])) .
            " to " . date('g:i A', strtotime($shift['end_time'])) .
            ($shift['location'] ? " at " . $shift['location'] : "") .
            ". Please confirm your availability.");
    }
}

// Helper function for time/date change requests
function handleTimeDateChange($pdo, $request) {
    // Build update fields for shift
    $updateFields = [];
    $updateValues = [];
    
    if (!empty($request['proposed_date'])) {
        $updateFields[] = "shift_date = ?";
        $updateValues[] = $request['proposed_date'];
    }
    
    if (!empty($request['proposed_start_time'])) {
        $updateFields[] = "start_time = ?";
        $updateValues[] = $request['proposed_start_time'];
    }
    
    if (!empty($request['proposed_end_time'])) {
        $updateFields[] = "end_time = ?";
        $updateValues[] = $request['proposed_end_time'];
    }
    
    // Always update confirmation status and timestamp
    $updateFields[] = "confirmation_status = 'confirmed'";
    $updateFields[] = "status = 'confirmed'";
    $updateFields[] = "confirmed_at = NOW()";
    $updateFields[] = "updated_at = NOW()";
    
    // Add shift ID to values
    $updateValues[] = $request['shift_id'];
    
    // Execute update
    $updateSql = "UPDATE shifts SET " . implode(", ", $updateFields) . " WHERE id = ?";
    $updateStmt = $pdo->prepare($updateSql);
    $updateStmt->execute($updateValues);
    
    // Update or create shift confirmation
    $confirmSql = "INSERT INTO shift_confirmations (shift_id, volunteer_id, status, response_notes, responded_at)
                   VALUES (?, ?, 'confirmed', 'Time/Date change approved', NOW())
                   ON DUPLICATE KEY UPDATE 
                   status = 'confirmed',
                   response_notes = CONCAT(COALESCE(response_notes, ''), ' Time/Date change approved'),
                   responded_at = NOW()";
    
    $confirmStmt = $pdo->prepare($confirmSql);
    $confirmStmt->execute([$request['shift_id'], $request['volunteer_id']]);
}

// Helper function to create notifications
function createNotification($pdo, $user_id, $type, $title, $message) {
    if (!$user_id) return;
    
    $sql = "INSERT INTO notifications (user_id, type, title, message, created_at)
            VALUES (?, ?, ?, ?, NOW())";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$user_id, $type, $title, $message]);
}

// Handle form submissions
$success_message = '';
$error_message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['update_request_status'])) {
        $request_id = $_POST['request_id'] ?? null;
        $status = $_POST['request_status'] ?? null;
        $admin_notes = $_POST['admin_notes'] ?? null;
        
        if ($request_id && $status) {
            if (updateRequestStatus($pdo, $request_id, $status, $user_id, $admin_notes)) {
                $success_message = "Request status updated successfully!";
            } else {
                $error_message = "Failed to update request status. Please try again.";
            }
        } else {
            $error_message = "Missing required parameters.";
        }
    } elseif (isset($_POST['cancel_request'])) {
        $request_id = $_POST['request_id'] ?? null;
        $cancel_reason = $_POST['cancel_reason'] ?? 'Cancelled by admin';
        
        if ($request_id) {
            if (updateRequestStatus($pdo, $request_id, 'cancelled', $user_id, $cancel_reason)) {
                $success_message = "Request cancelled successfully!";
            } else {
                $error_message = "Failed to cancel request.";
            }
        } else {
            $error_message = "Missing request ID.";
        }
    }
}

// Get filter values
$filter_status = $_GET['status'] ?? 'pending';
$filter_date_from = $_GET['date_from'] ?? null;
$filter_date_to = $_GET['date_to'] ?? null;
$filter_volunteer = $_GET['volunteer'] ?? null;

// Get data
$requests = getShiftChangeRequests($pdo, $filter_status, $filter_date_from, $filter_date_to, $filter_volunteer);
$volunteers = getVolunteers($pdo);
$stats = getRequestStatistics($pdo);

// Get duty assignments with pagination
$duty_page = isset($_GET['duty_page']) ? max(1, intval($_GET['duty_page'])) : 1;
$duty_per_page = 5;
$duty_shift_filter = $_GET['duty_shift_id'] ?? null;
$duty_type_filter = $_GET['duty_type'] ?? null;
$duty_data = getDutyAssignments($pdo, $duty_page, $duty_per_page, $duty_shift_filter, $duty_type_filter);

// Calculate percentages
$total_requests = $stats['total_requests'] ?? 0;
$pending_requests = $stats['pending_requests'] ?? 0;
$approved_requests = $stats['approved_requests'] ?? 0;
$rejected_requests = $stats['rejected_requests'] ?? 0;
$cancelled_requests = $stats['cancelled_requests'] ?? 0;

if ($total_requests > 0) {
    $pending_percent = ($pending_requests / $total_requests) * 100;
    $approved_percent = ($approved_requests / $total_requests) * 100;
    $rejected_percent = ($rejected_requests / $total_requests) * 100;
    $cancelled_percent = ($cancelled_requests / $total_requests) * 100;
} else {
    $pending_percent = $approved_percent = $rejected_percent = $cancelled_percent = 0;
}

$stmt = null;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Shift Change Requests - Fire & Rescue Management</title>
    <link href='https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css' rel='stylesheet'>
    <link rel="icon" type="image/png" sizes="32x32" href="../../img/frsm-logo.png">
    <link rel="stylesheet" href="../../css/dashboard.css">
    <style>
        :root {
            --primary-color: #dc2626;
            --primary-dark: #b91c1c;
            --secondary-color: #ef4444;
            --secondary-dark: #dc2626;
            --background-color: #ffffff;
            --text-color: #1f2937;
            --text-light: #6b7280;
            --border-color: #e5e7eb;
            --card-bg: #f9fafb;
            --sidebar-bg: #ffffff;
            
            --success: #10b981;
            --warning: #f59e0b;
            --danger: #dc2626;
            --info: #3b82f6;
            --purple: #8b5cf6;
            
            --gray-100: #f3f4f6;
            --gray-200: #e5e7eb;
            --gray-300: #d1d5db;
            --gray-400: #9ca3af;
            --gray-500: #6b7280;
            --gray-600: #4b5563;
            --gray-700: #374151;
            --gray-800: #1f2937;
            --gray-900: #111827;
        }
        
        .dark-mode {
            --background-color: #0f172a;
            --text-color: #f1f5f9;
            --text-light: #94a3b8;
            --border-color: #334155;
            --card-bg: #1e293b;
            --sidebar-bg: #0f172a;
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            font-size: 14px;
            line-height: 1.6;
            color: var(--text-color);
            background-color: var(--background-color);
            overflow-x: hidden;
        }

        .dashboard-content {
            padding: 0;
            min-height: 100vh;
        }

        .dashboard-header {
            color: white;
            padding: 60px 40px 40px;
            border-radius: 0 0 30px 30px;
            margin-bottom: 40px;
            position: relative;
            overflow: hidden;
            background: linear-gradient(135deg, #f8fafc 0%, #e2e8f0 100%);
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.08);
            border: 1px solid var(--border-color);
        }

        .dark-mode .dashboard-header {
            background: linear-gradient(135deg, #1e293b 0%, #334155 100%);
        }
        
        .dashboard-title {
            font-size: 40px;
            margin-bottom: 12px;
            color: var(--text-color);
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }

        .dashboard-subtitle {
            font-size: 16px;
            opacity: 0.9;
            color: var(--text-color);
        }

        .content-container {
            padding: 0 40px 40px;
        }

        .stats-container {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .stat-card {
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: 16px;
            padding: 24px;
            text-align: center;
            transition: all 0.3s ease;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
        }

        .stat-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 8px 16px rgba(0, 0, 0, 0.1);
        }

        .stat-icon {
            width: 60px;
            height: 60px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 16px;
            font-size: 24px;
        }

        .stat-icon.pending {
            background: rgba(245, 158, 11, 0.1);
            color: var(--warning);
        }

        .stat-icon.approved {
            background: rgba(16, 185, 129, 0.1);
            color: var(--success);
        }

        .stat-icon.rejected {
            background: rgba(220, 38, 38, 0.1);
            color: var(--danger);
        }

        .stat-icon.cancelled {
            background: rgba(107, 114, 128, 0.1);
            color: var(--gray-500);
        }

        .stat-value {
            font-size: 32px;
            font-weight: 800;
            margin-bottom: 8px;
        }

        .stat-label {
            color: var(--text-light);
            font-size: 14px;
        }

        .stat-percentage {
            font-size: 12px;
            color: var(--text-light);
            margin-top: 4px;
        }

        .filters-container {
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: 16px;
            padding: 24px;
            margin-bottom: 30px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
        }

        .filters-title {
            font-size: 18px;
            font-weight: 600;
            margin-bottom: 20px;
            color: var(--text-color);
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .filters-title i {
            color: var(--primary-color);
        }

        .filters-form {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            align-items: end;
        }

        .form-group {
            margin-bottom: 0;
        }

        .form-label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: var(--text-color);
            font-size: 14px;
        }

        .form-input, .form-select {
            width: 100%;
            padding: 12px 16px;
            border-radius: 10px;
            border: 1px solid var(--border-color);
            background: var(--card-bg);
            color: var(--text-color);
            font-size: 14px;
            transition: all 0.3s ease;
        }

        .form-input:focus, .form-select:focus {
            outline: none;
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(220, 38, 38, 0.1);
        }

        .btn {
            padding: 12px 24px;
            border-radius: 10px;
            font-weight: 600;
            border: none;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 14px;
        }

        .btn-primary {
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            color: white;
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(220, 38, 38, 0.3);
        }

        .btn-secondary {
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            color: var(--text-color);
        }

        .btn-secondary:hover {
            background: var(--gray-100);
        }

        .dark-mode .btn-secondary:hover {
            background: var(--gray-800);
        }

        .btn-success {
            background: linear-gradient(135deg, var(--success), #0da271);
            color: white;
        }

        .btn-success:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(16, 185, 129, 0.3);
        }

        .btn-warning {
            background: linear-gradient(135deg, var(--warning), #d97706);
            color: white;
        }

        .btn-warning:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(245, 158, 11, 0.3);
        }

        .btn-danger {
            background: linear-gradient(135deg, var(--danger), #b91c1c);
            color: white;
        }

        .btn-danger:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(220, 38, 38, 0.3);
        }

        .btn-info {
            background: linear-gradient(135deg, var(--info), #2563eb);
            color: white;
        }

        .btn-info:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(59, 130, 246, 0.3);
        }

        .table-container {
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: 16px;
            overflow: hidden;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
            margin-bottom: 30px;
        }

        .table-header {
            padding: 20px 24px;
            border-bottom: 1px solid var(--border-color);
            display: flex;
            justify-content: space-between;
            align-items: center;
            background: rgba(220, 38, 38, 0.02);
        }

        .table-title {
            font-size: 18px;
            font-weight: 600;
            color: var(--text-color);
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .table {
            width: 100%;
            border-collapse: collapse;
        }

        .table th {
            background: rgba(220, 38, 38, 0.05);
            padding: 16px 24px;
            text-align: left;
            font-weight: 600;
            color: var(--text-color);
            border-bottom: 1px solid var(--border-color);
            font-size: 13px;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }

        .table td {
            padding: 16px 24px;
            border-bottom: 1px solid var(--border-color);
            vertical-align: middle;
        }

        .table tbody tr {
            transition: all 0.3s ease;
        }

        .table tbody tr:hover {
            background: rgba(220, 38, 38, 0.02);
        }

        .table tbody tr:last-child td {
            border-bottom: none;
        }

        .status-badge {
            display: inline-block;
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            text-align: center;
            min-width: 100px;
        }

        .status-pending {
            background: rgba(245, 158, 11, 0.1);
            color: var(--warning);
            border: 1px solid rgba(245, 158, 11, 0.2);
        }

        .status-approved {
            background: rgba(16, 185, 129, 0.1);
            color: var(--success);
            border: 1px solid rgba(16, 185, 129, 0.2);
        }

        .status-rejected {
            background: rgba(220, 38, 38, 0.1);
            color: var(--danger);
            border: 1px solid rgba(220, 38, 38, 0.2);
        }

        .status-cancelled {
            background: rgba(107, 114, 128, 0.1);
            color: var(--gray-500);
            border: 1px solid rgba(107, 114, 128, 0.2);
        }

        .request-type-badge {
            display: inline-block;
            padding: 4px 8px;
            border-radius: 6px;
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
        }

        .request-type-time {
            background: rgba(59, 130, 246, 0.1);
            color: var(--info);
        }

        .request-type-date {
            background: rgba(139, 92, 246, 0.1);
            color: var(--purple);
        }

        .request-type-swap {
            background: rgba(245, 158, 11, 0.1);
            color: var(--warning);
        }

        .request-type-other {
            background: rgba(107, 114, 128, 0.1);
            color: var(--gray-500);
        }

        .volunteer-info {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .volunteer-avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            font-size: 16px;
        }

        .volunteer-details {
            display: flex;
            flex-direction: column;
        }

        .volunteer-name {
            font-weight: 600;
            color: var(--text-color);
        }

        .volunteer-contact {
            font-size: 12px;
            color: var(--text-light);
            margin-top: 2px;
        }

        .shift-details {
            display: flex;
            flex-direction: column;
            gap: 4px;
        }

        .shift-date {
            font-weight: 600;
            color: var(--text-color);
        }

        .shift-time {
            font-size: 13px;
            color: var(--text-light);
        }

        .proposed-changes {
            background: rgba(59, 130, 246, 0.05);
            border-radius: 8px;
            padding: 10px;
            margin-top: 8px;
            border-left: 3px solid var(--info);
        }

        .proposed-changes strong {
            color: var(--info);
            font-size: 12px;
            display: block;
            margin-bottom: 4px;
        }

        .request-details {
            max-width: 300px;
            word-wrap: break-word;
        }

        .action-buttons {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
        }

        .action-buttons .btn {
            padding: 8px 12px;
            font-size: 12px;
        }

        /* Pagination Styles */
        .pagination-container {
            padding: 20px 24px;
            border-top: 1px solid var(--border-color);
            display: flex;
            justify-content: space-between;
            align-items: center;
            background: var(--card-bg);
        }

        .pagination-info {
            font-size: 14px;
            color: var(--text-light);
        }

        .pagination-controls {
            display: flex;
            gap: 8px;
            align-items: center;
        }

        .pagination-btn {
            padding: 8px 16px;
            border-radius: 8px;
            border: 1px solid var(--border-color);
            background: var(--card-bg);
            color: var(--text-color);
            cursor: pointer;
            font-size: 14px;
            transition: all 0.3s ease;
        }

        .pagination-btn:hover:not(:disabled) {
            background: var(--gray-100);
            border-color: var(--primary-color);
        }

        .dark-mode .pagination-btn:hover:not(:disabled) {
            background: var(--gray-800);
        }

        .pagination-btn:disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }

        .pagination-btn.active {
            background: var(--primary-color);
            color: white;
            border-color: var(--primary-color);
        }

        .page-numbers {
            display: flex;
            gap: 4px;
        }

        .page-btn {
            min-width: 40px;
            height: 40px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 8px;
            border: 1px solid var(--border-color);
            background: var(--card-bg);
            color: var(--text-color);
            cursor: pointer;
            font-size: 14px;
            transition: all 0.3s ease;
        }

        .page-btn:hover {
            background: var(--gray-100);
            border-color: var(--primary-color);
        }

        .dark-mode .page-btn:hover {
            background: var(--gray-800);
        }

        .page-btn.active {
            background: var(--primary-color);
            color: white;
            border-color: var(--primary-color);
        }

        /* Duty Assignment Specific Styles */
        .duty-type-badge {
            display: inline-block;
            padding: 4px 8px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .duty-type-fire_suppression {
            background: rgba(220, 38, 38, 0.1);
            color: var(--danger);
            border: 1px solid rgba(220, 38, 38, 0.2);
        }

        .duty-type-emergency_medical {
            background: rgba(16, 185, 129, 0.1);
            color: var(--success);
            border: 1px solid rgba(16, 185, 129, 0.2);
        }

        .duty-type-rescue_operations {
            background: rgba(59, 130, 246, 0.1);
            color: var(--info);
            border: 1px solid rgba(59, 130, 246, 0.2);
        }

        .duty-type-command_post {
            background: rgba(139, 92, 246, 0.1);
            color: var(--purple);
            border: 1px solid rgba(139, 92, 246, 0.2);
        }

        .duty-type-logistics_support {
            background: rgba(245, 158, 11, 0.1);
            color: var(--warning);
            border: 1px solid rgba(245, 158, 11, 0.2);
        }

        .priority-badge {
            display: inline-block;
            padding: 3px 8px;
            border-radius: 12px;
            font-size: 10px;
            font-weight: 600;
            text-transform: uppercase;
        }

        .priority-primary {
            background: rgba(220, 38, 38, 0.1);
            color: var(--danger);
        }

        .priority-secondary {
            background: rgba(59, 130, 246, 0.1);
            color: var(--info);
        }

        .priority-support {
            background: rgba(156, 163, 175, 0.1);
            color: var(--gray-500);
        }

        .equipment-list {
            display: flex;
            flex-wrap: wrap;
            gap: 4px;
            margin-top: 4px;
        }

        .equipment-item {
            font-size: 11px;
            padding: 2px 6px;
            background: rgba(156, 163, 175, 0.1);
            border-radius: 10px;
            color: var(--gray-600);
        }

        .dark-mode .equipment-item {
            background: rgba(156, 163, 175, 0.2);
            color: var(--gray-300);
        }

        .modal-overlay {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.5);
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 1000;
            opacity: 0;
            visibility: hidden;
            transition: all 0.3s ease;
        }
        
        .modal-overlay.active {
            opacity: 1;
            visibility: visible;
        }
        
        .modal {
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: 20px;
            width: 90%;
            max-width: 500px;
            max-height: 80vh;
            transform: scale(0.9);
            transition: all 0.3s ease;
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.15);
            display: flex;
            flex-direction: column;
        }
        
        .modal-overlay.active .modal {
            transform: scale(1);
        }
        
        .modal-header {
            padding: 24px;
            border-bottom: 1px solid var(--border-color);
            display: flex;
            align-items: center;
            justify-content: space-between;
            background: rgba(220, 38, 38, 0.02);
            flex-shrink: 0;
        }
        
        .modal-title {
            font-size: 20px;
            font-weight: 700;
        }
        
        .modal-close {
            background: none;
            border: none;
            font-size: 24px;
            cursor: pointer;
            color: var(--text-light);
            transition: all 0.3s ease;
        }
        
        .modal-close:hover {
            color: var(--danger);
        }
        
        .modal-body {
            padding: 24px;
            overflow-y: auto;
            flex-grow: 1;
        }
        
        .modal-actions {
            padding: 24px;
            border-top: 1px solid var(--border-color);
            display: flex;
            gap: 12px;
            justify-content: flex-end;
            flex-shrink: 0;
        }

        .request-detail-item {
            margin-bottom: 15px;
            padding-bottom: 15px;
            border-bottom: 1px solid var(--border-color);
        }

        .request-detail-item:last-child {
            border-bottom: none;
            margin-bottom: 0;
        }

        .request-detail-label {
            font-weight: 600;
            color: var(--text-color);
            margin-bottom: 5px;
            font-size: 13px;
        }

        .request-detail-value {
            color: var(--text-light);
            font-size: 14px;
        }

        .notification {
            position: fixed;
            top: 20px;
            right: 20px;
            padding: 16px 20px;
            border-radius: 12px;
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            display: flex;
            align-items: center;
            gap: 12px;
            transform: translateX(100%);
            opacity: 0;
            transition: all 0.3s ease;
            max-width: 350px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
            z-index: 1000;
        }
        
        .notification.show {
            transform: translateX(0);
            opacity: 1;
        }
        
        .notification-icon {
            font-size: 20px;
            flex-shrink: 0;
        }
        
        .notification-success .notification-icon {
            color: var(--success);
        }
        
        .notification-error .notification-icon {
            color: var(--danger);
        }
        
        .notification-content {
            flex: 1;
        }
        
        .notification-title {
            font-weight: 600;
            margin-bottom: 4px;
        }
        
        .notification-message {
            font-size: 14px;
            color: var(--text-light);
        }
        
        .notification-close {
            background: none;
            border: none;
            font-size: 16px;
            cursor: pointer;
            color: var(--text-light);
            flex-shrink: 0;
        }

        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: var(--text-light);
        }

        .empty-state i {
            font-size: 48px;
            margin-bottom: 16px;
            opacity: 0.5;
        }

        .empty-state h3 {
            font-size: 18px;
            margin-bottom: 8px;
            color: var(--text-color);
        }

        .empty-state p {
            font-size: 14px;
            max-width: 400px;
            margin: 0 auto;
        }

        .shift-status-badge {
            display: inline-block;
            padding: 4px 8px;
            border-radius: 6px;
            font-size: 11px;
            font-weight: 600;
            margin-top: 4px;
        }

        .shift-status-scheduled {
            background: rgba(107, 114, 128, 0.1);
            color: var(--gray-500);
        }

        .shift-status-confirmed {
            background: rgba(16, 185, 129, 0.1);
            color: var(--success);
        }

        .shift-status-pending {
            background: rgba(245, 158, 11, 0.1);
            color: var(--warning);
        }

        .shift-status-completed {
            background: rgba(59, 130, 246, 0.1);
            color: var(--info);
        }

        @media (max-width: 992px) {
            .content-container {
                padding: 0 25px 30px;
            }
            
            .dashboard-header {
                padding: 40px 25px 30px;
            }
            
            .dashboard-title {
                font-size: 32px;
            }
            
            .stats-container {
                grid-template-columns: repeat(2, 1fr);
            }
            
            .table {
                display: block;
                overflow-x: auto;
            }
            
            .filters-form {
                grid-template-columns: 1fr;
            }
            
            .action-buttons {
                flex-direction: column;
            }
            
            .pagination-container {
                flex-direction: column;
                gap: 15px;
                align-items: stretch;
            }
            
            .page-numbers {
                justify-content: center;
            }
        }

        @media (max-width: 768px) {
            .content-container {
                padding: 0 20px 30px;
            }
            
            .dashboard-header {
                padding: 30px 20px 25px;
            }
            
            .dashboard-title {
                font-size: 28px;
            }
            
            .stats-container {
                grid-template-columns: 1fr;
            }
            
            .table-header {
                flex-direction: column;
                gap: 15px;
                align-items: stretch;
            }
            
            .pagination-controls {
                flex-direction: column;
                gap: 15px;
            }
        }
    </style>
</head>
<body>
    <!-- Status Update Modal -->
    <div class="modal-overlay" id="status-modal">
        <div class="modal">
            <div class="modal-header">
                <h2 class="modal-title">Update Request Status</h2>
                <button class="modal-close" id="status-modal-close">&times;</button>
            </div>
            <form method="POST" id="status-form">
                <div class="modal-body">
                    <input type="hidden" name="request_id" id="modal-request-id">
                    <input type="hidden" name="update_request_status" value="1">
                    
                    <div class="request-detail-item">
                        <div class="request-detail-label">Volunteer</div>
                        <div class="request-detail-value" id="modal-volunteer-name">Loading...</div>
                    </div>
                    
                    <div class="request-detail-item">
                        <div class="request-detail-label">Original Shift</div>
                        <div class="request-detail-value" id="modal-original-shift">Loading...</div>
                    </div>
                    
                    <div class="request-detail-item">
                        <div class="request-detail-label">Request Type</div>
                        <div class="request-detail-value" id="modal-request-type">Loading...</div>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label" for="request_status">New Status</label>
                        <select class="form-select" id="request_status" name="request_status" required>
                            <option value="pending">Pending</option>
                            <option value="approved">Approve</option>
                            <option value="rejected">Reject</option>
                        </select>
                    </div>
                    
                    <div class="form-group" style="margin-top: 20px;">
                        <label class="form-label" for="admin_notes">Admin Notes</label>
                        <textarea class="form-input" id="admin_notes" name="admin_notes" 
                                  placeholder="Add notes about your decision..." rows="4"></textarea>
                        <small style="color: var(--text-light); font-size: 12px; display: block; margin-top: 5px;">
                            These notes will be visible to the volunteer.
                        </small>
                    </div>
                </div>
                <div class="modal-actions">
                    <button type="button" class="btn btn-secondary" id="close-status-modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Update Status</button>
                </div>
            </form>
        </div>
    </div>
    
    <!-- View Details Modal -->
    <div class="modal-overlay" id="details-modal">
        <div class="modal">
            <div class="modal-header">
                <h2 class="modal-title">Request Details</h2>
                <button class="modal-close" id="details-modal-close">&times;</button>
            </div>
            <div class="modal-body" id="details-content">
                <!-- Details will be loaded here -->
            </div>
            <div class="modal-actions">
                <button type="button" class="btn btn-secondary" id="close-details-modal">Close</button>
            </div>
        </div>
    </div>
    
    <!-- Notification -->
    <div class="notification <?php echo $success_message ? 'notification-success show' : ($error_message ? 'notification-error show' : ''); ?>" id="notification">
        <i class='notification-icon bx <?php echo $success_message ? 'bx-check-circle' : ($error_message ? 'bx-error' : ''); ?>'></i>
        <div class="notification-content">
            <div class="notification-title"><?php echo $success_message ? 'Success' : ($error_message ? 'Error' : ''); ?></div>
            <div class="notification-message"><?php echo $success_message ?: $error_message; ?></div>
        </div>
        <button class="notification-close" id="notification-close">&times;</button>
    </div>
    
    <div class="container">
        <!-- Sidebar -->
        <div class="sidebar">
            <!-- Logo -->
            <div class="logo">
                <div class="logo-icon">
                    <img src="../../img/frsm-logo.png" alt="Fire & Rescue Logo" style="width: 40px; height: 45px;">
                </div>
                <span class="logo-text">Fire & Rescue</span>
            </div>
            
            <!-- Menu Section -->
            <div class="menu-section">
                <p class="menu-title">FIRE & RESCUE MANAGEMENT</p>
                
                <div class="menu-items">
                    <a href="../employee_dashboard.php" class="menu-item">
                        <div class="icon-box icon-bg-red">
                            <i class='bx bxs-dashboard icon-red'></i>
                        </div>
                        <span class="font-medium">Dashboard</span>
                    </a>
                    
                    <!-- Fire & Incident Reporting -->
                    <div class="menu-item" onclick="toggleSubmenu('fire-incident')">
                        <div class="icon-box icon-bg-orange">
                            <i class='bx bxs-alarm-exclamation icon-orange'></i>
                        </div>
                        <span class="font-medium">Fire & Incident Reporting</span>
                        <svg class="dropdown-arrow menu-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                        </svg>
                    </div>
                    <div id="fire-incident" class="submenu">
                        <a href="../fire/receive_data.php" class="submenu-item">Receive Data</a>
                        <a href="../fire/update_status.php" class="submenu-item">View Status</a>
                    </div>
                    
                    <!-- Dispatch Coordination -->
                    <div class="menu-item" onclick="toggleSubmenu('dispatch')">
                        <div class="icon-box icon-bg-yellow">
                            <i class='bx bxs-truck icon-yellow'></i>
                        </div>
                        <span class="font-medium">Dispatch Coordination</span>
                        <svg class="dropdown-arrow menu-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                        </svg>
                    </div>
                    <div id="dispatch" class="submenu">
                        <a href="../dc/select_unit.php" class="submenu-item">Select Unit</a>
                        <a href="../dc/send_dispatch.php" class="submenu-item">Send Dispatch Info</a>
                        <a href="../dc/track_status.php" class="submenu-item">Track Status</a>
                    </div>
                    
                    <!-- Barangay Volunteer Roster Access -->
                    <div class="menu-item" onclick="toggleSubmenu('volunteer')">
                        <div class="icon-box icon-bg-blue">
                            <i class='bx bxs-user-detail icon-blue'></i>
                        </div>
                        <span class="font-medium">Volunteer Roster Access</span>
                        <svg class="dropdown-arrow menu-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                        </svg>
                    </div>
                    <div id="volunteer" class="submenu">
                        <a href="../vra/review_data.php" class="submenu-item">Review/Approve Data Management</a>
                        <a href="../vra/approve_applications.php" class="submenu-item">Assign Volunteers</a>
                        <a href="../vra/view_availability.php" class="submenu-item">View Availability</a>
                        <a href="../vra/remove_volunteers.php" class="submenu-item">Remove Volunteers</a>
                        <a href="../vra/toggle_volunteer_registration.php" class="submenu-item">Open/Close Registration</a>
                    </div>
                    
                    <!-- Resource Inventory Updates -->
                    <div class="menu-item" onclick="toggleSubmenu('inventory')">
                        <div class="icon-box icon-bg-green">
                            <i class='bx bxs-cube icon-green'></i>
                        </div>
                        <span class="font-medium">Resource Inventory</span>
                        <svg class="dropdown-arrow menu-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                        </svg>
                    </div>
                    <div id="inventory" class="submenu">
                        <a href="../inventory/log_usage.php" class="submenu-item">Log Usage</a>
                        <a href="../inventory/report_damages.php" class="submenu-item">Report Damages</a>
                        <a href="../inventory/request_supplies.php" class="submenu-item">Request Supplies</a>
                        <a href="../inventory/tag_resources.php" class="submenu-item">Tag Resources</a>
                    </div>
                    
                    <!-- Shift & Duty Scheduling -->
                    <div class="menu-item" onclick="toggleSubmenu('schedule')">
                        <div class="icon-box icon-bg-purple">
                            <i class='bx bxs-calendar icon-purple'></i>
                        </div>
                        <span class="font-medium">Shift & Duty Scheduling</span>
                        <svg class="dropdown-arrow menu-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                        </svg>
                    </div>
                    <div id="schedule" class="submenu active">
                        <a href="create_shifts.php" class="submenu-item">Create Shifts</a>
                        <a href="view_shifts.php" class="submenu-item">View Shifts</a>
                        <a href="confirm_availability.php" class="submenu-item">Confirm Availability</a>
                        <a href="request_change.php" class="submenu-item active">Request Change</a>
                        <a href="mark_attendance.php" class="submenu-item">Mark Attendance</a>
                    </div>
                    
                    <!-- Training & Certification Logging -->
                    <div class="menu-item" onclick="toggleSubmenu('training')">
                        <div class="icon-box icon-bg-teal">
                            <i class='bx bxs-graduation icon-teal'></i>
                        </div>
                        <span class="font-medium">Training & Certification</span>
                        <svg class="dropdown-arrow menu-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                        </svg>
                    </div>
                    <div id="training" class="submenu">
                        <a href="../training/submit_training.php" class="submenu-item">Submit Training</a>
                        <a href="../training/upload_certificates.php" class="submenu-item">Upload Certificates</a>
                        <a href="../training/request_training.php" class="submenu-item">Request Training</a>
                        <a href="../training/view_events.php" class="submenu-item">View Events</a>
                    </div>
                    
                    <!-- Inspection Logs -->
                    <div class="menu-item" onclick="toggleSubmenu('inspection')">
                        <div class="icon-box icon-bg-cyan">
                            <i class='bx bxs-check-shield icon-cyan'></i>
                        </div>
                        <span class="font-medium">Inspection Logs</span>
                        <svg class="dropdown-arrow menu-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                        </svg>
                    </div>
                    <div id="inspection" class="submenu">
                        <a href="../inspection/conduct_inspections.php" class="submenu-item">Conduct Inspections</a>
                        <a href="../inspection/submit_findings.php" class="submenu-item">Submit Findings</a>
                        <a href="../inspection/upload_photos.php" class="submenu-item">Upload Photos</a>
                        <a href="../inspection/tag_violations.php" class="submenu-item">Tag Violations</a>
                    </div>
                    
                    <!-- Post-Incident Reporting -->
                    <div class="menu-item" onclick="toggleSubmenu('postincident')">
                        <div class="icon-box icon-bg-pink">
                            <i class='bx bxs-file-doc icon-pink'></i>
                        </div>
                        <span class="font-medium">Post-Incident Reporting</span>
                        <svg class="dropdown-arrow menu-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                        </svg>
                    </div>
                    <div id="postincident" class="submenu">
                        <a href="../postincident/upload_reports.php" class="submenu-item">Upload Reports</a>
                        <a href="../postincident/add_notes.php" class="submenu-item">Add Notes</a>
                        <a href="../postincident/attach_equipment.php" class="submenu-item">Attach Equipment</a>
                        <a href="../postincident/mark_completed.php" class="submenu-item">Mark Completed</a>
                    </div>
                </div>
                
                <p class="menu-title" style="margin-top: 32px;">GENERAL</p>
                
                <div class="menu-items">
                    <a href="../settings.php" class="menu-item">
                        <div class="icon-box icon-bg-indigo">
                            <i class='bx bxs-cog icon-indigo'></i>
                        </div>
                        <span class="font-medium">Settings</span>
                    </a>
                    
                    <a href="../profile/profile.php" class="menu-item">
                        <div class="icon-box icon-bg-orange">
                            <i class='bx bxs-user icon-orange'></i>
                        </div>
                        <span class="font-medium">Profile</span>
                    </a>
                    
                    <a href="../../includes/logout.php" class="menu-item">
                        <div class="icon-box icon-bg-red">
                            <i class='bx bx-log-out icon-red'></i>
                        </div>
                        <span class="font-medium">Logout</span>
                    </a>
                </div>
            </div>
        </div>
        <!-- Main Content -->
        <div class="main-content">
            <!-- Header -->
            <div class="header">
                <div class="header-content">
                    <div class="search-container">
                        <div class="search-box">
                            <svg class="search-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path>
                            </svg>
                            <input type="text" placeholder="Search requests..." class="search-input" id="search-input">
                        </div>
                    </div>
                    
                    <div class="header-actions">
                        <button class="theme-toggle" id="theme-toggle">
                            <i class='bx bx-moon'></i>
                            <span>Dark Mode</span>
                        </button>
                        <div class="time-display" id="time-display">
                            <i class='bx bx-time time-icon'></i>
                            <span id="current-time">Loading...</span>
                        </div>
                        <div class="user-profile" id="user-profile">
                            <?php if ($avatar): ?>
                                <img src="../profile/uploads/avatars/<?php echo $avatar; ?>" alt="User" class="user-avatar">
                            <?php else: ?>
                                <div class="user-avatar" style="background: linear-gradient(135deg, var(--primary-color), var(--secondary-color)); color: white; display: flex; align-items: center; justify-content: center; font-weight: bold; border-radius: 50%; width: 40px; height: 40px;">
                                    <?php echo strtoupper(substr($full_name, 0, 1)); ?>
                                </div>
                            <?php endif; ?>
                            <div class="user-info">
                                <p class="user-name"><?php echo $full_name; ?></p>
                                <p class="user-email"><?php echo $role; ?></p>
                            </div>
                            <div class="user-profile-dropdown" id="user-dropdown">
                                <a href="../profile/profile.php" class="dropdown-item">
                                    <i class='bx bx-user'></i>
                                    <span>Profile</span>
                                </a>
                                <a href="../settings.php" class="dropdown-item">
                                    <i class='bx bx-cog'></i>
                                    <span>Settings</span>
                                </a>
                                <div class="dropdown-divider"></div>
                                <a href="../../includes/logout.php" class="dropdown-item">
                                    <i class='bx bx-log-out'></i>
                                    <span>Logout</span>
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Dashboard Content -->
            <div class="dashboard-content">
                <div class="dashboard-header">
                    <div>
                        <h1 class="dashboard-title">Shift Change Requests</h1>
                        <p class="dashboard-subtitle">Manage volunteer shift change requests and maintain schedule stability</p>
                    </div>
                </div>
                
                <!-- Content Container -->
                <div class="content-container">
                    <!-- Statistics Cards -->
                    <div class="stats-container">
                        <div class="stat-card">
                            <div class="stat-icon pending">
                                <i class='bx bx-time-five'></i>
                            </div>
                            <div class="stat-value"><?php echo $pending_requests; ?></div>
                            <div class="stat-label">Pending Requests</div>
                            <div class="stat-percentage"><?php echo number_format($pending_percent, 1); ?>% of total</div>
                        </div>
                        
                        <div class="stat-card">
                            <div class="stat-icon approved">
                                <i class='bx bx-check-circle'></i>
                            </div>
                            <div class="stat-value"><?php echo $approved_requests; ?></div>
                            <div class="stat-label">Approved</div>
                            <div class="stat-percentage"><?php echo number_format($approved_percent, 1); ?>% of total</div>
                        </div>
                        
                        <div class="stat-card">
                            <div class="stat-icon rejected">
                                <i class='bx bx-x-circle'></i>
                            </div>
                            <div class="stat-value"><?php echo $rejected_requests; ?></div>
                            <div class="stat-label">Rejected</div>
                            <div class="stat-percentage"><?php echo number_format($rejected_percent, 1); ?>% of total</div>
                        </div>
                        
                        <div class="stat-card">
                            <div class="stat-icon cancelled">
                                <i class='bx bx-block'></i>
                            </div>
                            <div class="stat-value"><?php echo $stats['unique_volunteers'] ?? 0; ?></div>
                            <div class="stat-label">Unique Volunteers</div>
                            <div class="stat-percentage">
                                <?php if ($stats['avg_processing_hours'] ?? 0): ?>
                                    Avg: <?php echo number_format($stats['avg_processing_hours'], 1); ?>h
                                <?php else: ?>
                                    No processing data
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Filters -->
                    <div class="filters-container">
                        <h3 class="filters-title">
                            <i class='bx bx-filter-alt'></i>
                            Filter Requests
                        </h3>
                        
                        <form method="GET" class="filters-form">
                            <div class="form-group">
                                <label class="form-label" for="status">Request Status</label>
                                <select class="form-select" id="status" name="status">
                                    <option value="all" <?php echo $filter_status === 'all' ? 'selected' : ''; ?>>All Statuses</option>
                                    <option value="pending" <?php echo $filter_status === 'pending' ? 'selected' : ''; ?>>Pending</option>
                                    <option value="approved" <?php echo $filter_status === 'approved' ? 'selected' : ''; ?>>Approved</option>
                                    <option value="rejected" <?php echo $filter_status === 'rejected' ? 'selected' : ''; ?>>Rejected</option>
                                    <option value="cancelled" <?php echo $filter_status === 'cancelled' ? 'selected' : ''; ?>>Cancelled</option>
                                </select>
                            </div>
                            
                            <div class="form-group">
                                <label class="form-label" for="date_from">Shift Date From</label>
                                <input type="date" class="form-input" id="date_from" name="date_from" 
                                       value="<?php echo htmlspecialchars($filter_date_from ?? ''); ?>">
                            </div>
                            
                            <div class="form-group">
                                <label class="form-label" for="date_to">Shift Date To</label>
                                <input type="date" class="form-input" id="date_to" name="date_to" 
                                       value="<?php echo htmlspecialchars($filter_date_to ?? ''); ?>">
                            </div>
                            
                            <div class="form-group">
                                <label class="form-label" for="volunteer">Volunteer</label>
                                <select class="form-select" id="volunteer" name="volunteer">
                                    <option value="all">All Volunteers</option>
                                    <?php foreach ($volunteers as $volunteer): ?>
                                        <option value="<?php echo $volunteer['id']; ?>" 
                                            <?php echo $filter_volunteer == $volunteer['id'] ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($volunteer['first_name'] . ' ' . $volunteer['last_name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div class="form-group">
                                <button type="submit" class="btn btn-primary" style="width: 100%;">
                                    <i class='bx bx-search'></i>
                                    Apply Filters
                                </button>
                            </div>
                        </form>
                    </div>
                    
                    <!-- Requests Table -->
                    <div class="table-container">
                        <div class="table-header">
                            <h3 class="table-title">
                                <i class='bx bx-transfer-alt'></i>
                                Shift Change Requests
                                <span style="font-size: 14px; font-weight: normal; color: var(--text-light); margin-left: 10px;">
                                    <?php echo count($requests); ?> requests found
                                </span>
                            </h3>
                            <div>
                                <?php if ($filter_status !== 'all' || $filter_date_from || $filter_date_to || $filter_volunteer): ?>
                                    <a href="request_change.php" class="btn btn-secondary">
                                        <i class='bx bx-reset'></i>
                                        Clear Filters
                                    </a>
                                <?php endif; ?>
                            </div>
                        </div>
                        
                        <?php if (count($requests) > 0): ?>
                            <table class="table">
                                <thead>
                                    <tr>
                                        <th>Volunteer</th>
                                        <th>Shift Details</th>
                                        <th>Request Type</th>
                                        <th>Request Details</th>
                                        <th>Status</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($requests as $request): 
                                        $request_date = date('M j, Y', strtotime($request['requested_at']));
                                        $original_date = date('M j, Y', strtotime($request['shift_date']));
                                        $original_time = date('g:i A', strtotime($request['original_start_time'])) . ' - ' . 
                                                       date('g:i A', strtotime($request['original_end_time']));
                                        
                                        $request_type_class = '';
                                        switch ($request['request_type']) {
                                            case 'time_change':
                                                $request_type_class = 'request-type-time';
                                                break;
                                            case 'date_change':
                                                $request_type_class = 'request-type-date';
                                                break;
                                            case 'swap':
                                                $request_type_class = 'request-type-swap';
                                                break;
                                            default:
                                                $request_type_class = 'request-type-other';
                                        }
                                        
                                        // Determine shift status badge
                                        $shift_status_badge = '';
                                        $shift_status_text = ucfirst($request['shift_status'] ?? 'scheduled');
                                        switch ($request['shift_status']) {
                                            case 'confirmed':
                                                $shift_status_badge = 'shift-status-confirmed';
                                                break;
                                            case 'pending':
                                                $shift_status_badge = 'shift-status-pending';
                                                break;
                                            case 'completed':
                                                $shift_status_badge = 'shift-status-completed';
                                                break;
                                            default:
                                                $shift_status_badge = 'shift-status-scheduled';
                                        }
                                    ?>
                                    <tr>
                                        <td>
                                            <div class="volunteer-info">
                                                <div class="volunteer-avatar">
                                                    <?php echo strtoupper(substr($request['volunteer_first_name'], 0, 1)); ?>
                                                </div>
                                                <div class="volunteer-details">
                                                    <div class="volunteer-name">
                                                        <?php echo htmlspecialchars($request['volunteer_first_name'] . ' ' . $request['volunteer_last_name']); ?>
                                                    </div>
                                                    <div class="volunteer-contact">
                                                        <i class='bx bx-phone'></i> <?php echo htmlspecialchars($request['volunteer_contact']); ?>
                                                    </div>
                                                </div>
                                            </div>
                                        </td>
                                        <td>
                                            <div class="shift-details">
                                                <div class="shift-date">
                                                    <?php echo $original_date; ?>
                                                </div>
                                                <div class="shift-time">
                                                    <?php echo $original_time; ?>
                                                </div>
                                                <?php if ($request['unit_name']): ?>
                                                    <div style="font-size: 12px; color: var(--text-light); margin-top: 4px;">
                                                        <i class='bx bx-building-house'></i> <?php echo htmlspecialchars($request['unit_name']); ?>
                                                    </div>
                                                <?php endif; ?>
                                                <div class="shift-status-badge <?php echo $shift_status_badge; ?>">
                                                    Shift: <?php echo $shift_status_text; ?>
                                                </div>
                                                <?php if ($request['shift_confirmation_status']): ?>
                                                    <div style="font-size: 11px; color: var(--text-light); margin-top: 2px;">
                                                        Confirmation: <?php echo ucfirst($request['shift_confirmation_status']); ?>
                                                    </div>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                        <td>
                                            <span class="request-type-badge <?php echo $request_type_class; ?>">
                                                <?php echo ucfirst(str_replace('_', ' ', $request['request_type'])); ?>
                                            </span>
                                            <div style="font-size: 11px; color: var(--text-light); margin-top: 4px;">
                                                <?php echo $request_date; ?>
                                            </div>
                                        </td>
                                        <td class="request-details">
                                            <div style="margin-bottom: 8px;">
                                                <?php echo htmlspecialchars($request['request_details']); ?>
                                            </div>
                                            
                                            <?php if ($request['request_type'] === 'time_change' || $request['request_type'] === 'date_change'): ?>
                                                <?php if ($request['proposed_date'] || $request['proposed_start_time']): ?>
                                                    <div class="proposed-changes">
                                                        <strong>Proposed Changes:</strong>
                                                        <?php if ($request['proposed_date']): ?>
                                                            <div>Date: <?php echo date('M j, Y', strtotime($request['proposed_date'])); ?></div>
                                                        <?php endif; ?>
                                                        <?php if ($request['proposed_start_time']): ?>
                                                            <div>Time: <?php echo date('g:i A', strtotime($request['proposed_start_time'])); ?> - 
                                                                   <?php echo date('g:i A', strtotime($request['proposed_end_time'])); ?></div>
                                                        <?php endif; ?>
                                                    </div>
                                                <?php endif; ?>
                                            <?php elseif ($request['request_type'] === 'swap' && $request['swap_first_name']): ?>
                                                <div class="proposed-changes">
                                                    <strong>Swap with:</strong>
                                                    <div><?php echo htmlspecialchars($request['swap_first_name'] . ' ' . $request['swap_last_name']); ?></div>
                                                    <?php if ($request['swap_unit_name']): ?>
                                                        <div style="font-size: 11px; color: var(--text-light);">
                                                            Unit: <?php echo htmlspecialchars($request['swap_unit_name']); ?>
                                                        </div>
                                                    <?php endif; ?>
                                                </div>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div class="status-badge status-<?php echo $request['request_status']; ?>">
                                                <?php echo ucfirst($request['request_status']); ?>
                                            </div>
                                            <?php if ($request['reviewed_at'] && $request['reviewer_first_name']): ?>
                                                <div style="font-size: 11px; color: var(--text-light); margin-top: 4px;">
                                                    By: <?php echo htmlspecialchars($request['reviewer_first_name'] . ' ' . $request['reviewer_last_name']); ?>
                                                    <br>
                                                    <?php echo date('M j, g:i A', strtotime($request['reviewed_at'])); ?>
                                                </div>
                                            <?php endif; ?>
                                            <?php if ($request['admin_notes']): ?>
                                                <div style="font-size: 11px; color: var(--info); margin-top: 4px;">
                                                    <i class='bx bx-message'></i> <?php echo htmlspecialchars($request['admin_notes']); ?>
                                                </div>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div class="action-buttons">
                                                <?php if ($request['request_status'] === 'pending'): ?>
                                                    <button type="button" class="btn btn-primary update-status-btn" 
                                                            data-request-id="<?php echo $request['request_id']; ?>"
                                                            data-volunteer-name="<?php echo htmlspecialchars($request['volunteer_first_name'] . ' ' . $request['volunteer_last_name']); ?>"
                                                            data-original-shift="<?php echo $original_date . ' ' . $original_time; ?>"
                                                            data-request-type="<?php echo $request['request_type']; ?>"
                                                            data-request-type-text="<?php echo ucfirst(str_replace('_', ' ', $request['request_type'])); ?>"
                                                            data-request-details="<?php echo htmlspecialchars($request['request_details']); ?>"
                                                            data-swap-volunteer="<?php echo $request['swap_first_name'] ? htmlspecialchars($request['swap_first_name'] . ' ' . $request['swap_last_name']) : ''; ?>">
                                                        <i class='bx bx-edit'></i> Process
                                                    </button>
                                                    
                                                    <button type="button" class="btn btn-danger cancel-request-btn" 
                                                            data-request-id="<?php echo $request['request_id']; ?>">
                                                        <i class='bx bx-x'></i> Cancel
                                                    </button>
                                                <?php endif; ?>
                                                
                                                <button type="button" class="btn btn-info view-details-btn"
                                                        data-request-id="<?php echo $request['request_id']; ?>"
                                                        data-volunteer-name="<?php echo htmlspecialchars($request['volunteer_first_name'] . ' ' . $request['volunteer_last_name']); ?>"
                                                        data-volunteer-contact="<?php echo htmlspecialchars($request['volunteer_contact']); ?>"
                                                        data-volunteer-email="<?php echo htmlspecialchars($request['volunteer_email']); ?>"
                                                        data-original-shift="<?php echo $original_date . ' ' . $original_time; ?>"
                                                        data-unit-name="<?php echo htmlspecialchars($request['unit_name'] ?? 'Not assigned'); ?>"
                                                        data-shift-status="<?php echo ucfirst($request['shift_status'] ?? 'scheduled'); ?>"
                                                        data-confirmation-status="<?php echo ucfirst($request['shift_confirmation_status'] ?? 'pending'); ?>"
                                                        data-request-type="<?php echo ucfirst(str_replace('_', ' ', $request['request_type'])); ?>"
                                                        data-request-details="<?php echo htmlspecialchars($request['request_details']); ?>"
                                                        data-request-date="<?php echo $request_date; ?>"
                                                        data-proposed-date="<?php echo $request['proposed_date'] ? date('M j, Y', strtotime($request['proposed_date'])) : ''; ?>"
                                                        data-proposed-time="<?php echo $request['proposed_start_time'] ? date('g:i A', strtotime($request['proposed_start_time'])) . ' - ' . 
                                                                                 date('g:i A', strtotime($request['proposed_end_time'])) : ''; ?>"
                                                        data-swap-volunteer="<?php echo $request['swap_first_name'] ? htmlspecialchars($request['swap_first_name'] . ' ' . $request['swap_last_name']) : ''; ?>"
                                                        data-swap-contact="<?php echo htmlspecialchars($request['swap_contact'] ?? ''); ?>"
                                                        data-swap-email="<?php echo htmlspecialchars($request['swap_email'] ?? ''); ?>"
                                                        data-swap-unit="<?php echo htmlspecialchars($request['swap_unit_name'] ?? ''); ?>"
                                                        data-status="<?php echo ucfirst($request['request_status']); ?>"
                                                        data-admin-notes="<?php echo htmlspecialchars($request['admin_notes'] ?? ''); ?>"
                                                        data-reviewed-by="<?php echo $request['reviewer_first_name'] ? htmlspecialchars($request['reviewer_first_name'] . ' ' . $request['reviewer_last_name']) : ''; ?>"
                                                        data-reviewed-at="<?php echo $request['reviewed_at'] ? date('M j, Y g:i A', strtotime($request['reviewed_at'])) : ''; ?>">
                                                    <i class='bx bx-info-circle'></i> Details
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php else: ?>
                            <div class="empty-state">
                                <i class='bx bx-file'></i>
                                <h3>No Requests Found</h3>
                                <p>No shift change requests match your current filters. Try adjusting your search criteria.</p>
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- Duty Assignments Section -->
                    <div class="table-container">
                        <div class="table-header">
                            <h3 class="table-title">
                                <i class='bx bx-task'></i>
                                Duty Assignments
                                <span style="font-size: 14px; font-weight: normal; color: var(--text-light); margin-left: 10px;">
                                    <?php echo $duty_data['total']; ?> duties found
                                </span>
                            </h3>
                            <div>
                                <?php if ($duty_shift_filter || $duty_type_filter): ?>
                                    <a href="request_change.php" class="btn btn-secondary">
                                        <i class='bx bx-reset'></i>
                                        Clear Filters
                                    </a>
                                <?php endif; ?>
                            </div>
                        </div>
                        
                        <?php if (count($duty_data['duties']) > 0): ?>
                            <table class="table">
                                <thead>
                                    <tr>
                                        <th>Duty Type</th>
                                        <th>Shift Details</th>
                                        <th>Volunteer</th>
                                        <th>Priority</th>
                                        <th>Equipment & Training</th>
                                        <th>Created</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($duty_data['duties'] as $duty): 
                                        // Format duty type for display
                                        $duty_type_display = str_replace('_', ' ', $duty['duty_type']);
                                        $duty_type_class = 'duty-type-' . $duty['duty_type'];
                                        
                                        // Format shift details
                                        $shift_date = $duty['shift_date'] ? date('M j, Y', strtotime($duty['shift_date'])) : 'N/A';
                                        $shift_time = $duty['start_time'] && $duty['end_time'] ? 
                                            date('g:i A', strtotime($duty['start_time'])) . ' - ' . 
                                            date('g:i A', strtotime($duty['end_time'])) : 'N/A';
                                        
                                        // Parse equipment if it exists
                                        $equipment_list = [];
                                        if ($duty['required_equipment']) {
                                            $equipment_list = explode(',', $duty['required_equipment']);
                                        }
                                    ?>
                                    <tr>
                                        <td>
                                            <div>
                                                <span class="duty-type-badge <?php echo $duty_type_class; ?>">
                                                    <?php echo ucwords($duty_type_display); ?>
                                                </span>
                                                <div style="font-size: 12px; color: var(--text-light); margin-top: 4px;">
                                                    <?php echo htmlspecialchars(substr($duty['duty_description'], 0, 100)); ?>...
                                                </div>
                                            </div>
                                        </td>
                                        <td>
                                            <div class="shift-details">
                                                <div class="shift-date">
                                                    <?php echo $shift_date; ?>
                                                </div>
                                                <div class="shift-time">
                                                    <?php echo $shift_time; ?>
                                                </div>
                                                <?php if ($duty['shift_location']): ?>
                                                    <div style="font-size: 12px; color: var(--text-light); margin-top: 4px;">
                                                        <i class='bx bx-map'></i> <?php echo htmlspecialchars($duty['shift_location']); ?>
                                                    </div>
                                                <?php endif; ?>
                                                <?php if ($duty['unit_name']): ?>
                                                    <div style="font-size: 11px; color: var(--info); margin-top: 4px;">
                                                        <i class='bx bx-building-house'></i> <?php echo htmlspecialchars($duty['unit_name']); ?>
                                                    </div>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                        <td>
                                            <?php if ($duty['volunteer_first_name']): ?>
                                                <div class="volunteer-info">
                                                    <div class="volunteer-avatar">
                                                        <?php echo strtoupper(substr($duty['volunteer_first_name'], 0, 1)); ?>
                                                    </div>
                                                    <div class="volunteer-details">
                                                        <div class="volunteer-name">
                                                            <?php echo htmlspecialchars($duty['volunteer_first_name'] . ' ' . $duty['volunteer_last_name']); ?>
                                                        </div>
                                                        <?php if ($duty['volunteer_id']): ?>
                                                            <div class="volunteer-contact">
                                                                <i class='bx bx-user'></i> ID: <?php echo $duty['volunteer_id']; ?>
                                                            </div>
                                                        <?php endif; ?>
                                                    </div>
                                                </div>
                                            <?php else: ?>
                                                <span style="color: var(--text-light); font-style: italic;">Unassigned</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <span class="priority-badge priority-<?php echo $duty['priority']; ?>">
                                                <?php echo ucfirst($duty['priority']); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <?php if (count($equipment_list) > 0): ?>
                                                <div class="equipment-list">
                                                    <?php foreach ($equipment_list as $equipment): ?>
                                                        <span class="equipment-item"><?php echo htmlspecialchars(trim($equipment)); ?></span>
                                                    <?php endforeach; ?>
                                                </div>
                                            <?php else: ?>
                                                <span style="color: var(--text-light); font-style: italic;">No equipment specified</span>
                                            <?php endif; ?>
                                            <?php if ($duty['required_training']): ?>
                                                <div style="font-size: 11px; color: var(--success); margin-top: 4px;">
                                                    <i class='bx bx-certification'></i> <?php echo htmlspecialchars(substr($duty['required_training'], 0, 50)); ?>...
                                                </div>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php echo date('M j, Y', strtotime($duty['duty_created_at'])); ?>
                                            <div style="font-size: 11px; color: var(--text-light); margin-top: 2px;">
                                                <?php echo date('g:i A', strtotime($duty['duty_created_at'])); ?>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                            
                            <!-- Pagination for Duty Assignments -->
                            <?php if ($duty_data['total_pages'] > 1): ?>
                            <div class="pagination-container">
                                <div class="pagination-info">
                                    Showing <?php echo (($duty_page - 1) * $duty_per_page) + 1; ?> - 
                                    <?php echo min($duty_page * $duty_per_page, $duty_data['total']); ?> of 
                                    <?php echo $duty_data['total']; ?> duties
                                </div>
                                <div class="pagination-controls">
                                    <button class="pagination-btn" 
                                            onclick="window.location.href='request_change.php?duty_page=1<?php echo $duty_shift_filter ? '&duty_shift_id=' . $duty_shift_filter : ''; ?><?php echo $duty_type_filter ? '&duty_type=' . $duty_type_filter : ''; ?>'"
                                            <?php echo $duty_page == 1 ? 'disabled' : ''; ?>>
                                        <i class='bx bx-first-page'></i> First
                                    </button>
                                    <button class="pagination-btn" 
                                            onclick="window.location.href='request_change.php?duty_page=<?php echo $duty_page - 1; ?><?php echo $duty_shift_filter ? '&duty_shift_id=' . $duty_shift_filter : ''; ?><?php echo $duty_type_filter ? '&duty_type=' . $duty_type_filter : ''; ?>'"
                                            <?php echo $duty_page == 1 ? 'disabled' : ''; ?>>
                                        <i class='bx bx-chevron-left'></i> Prev
                                    </button>
                                    
                                    <div class="page-numbers">
                                        <?php
                                        $start_page = max(1, $duty_page - 2);
                                        $end_page = min($duty_data['total_pages'], $duty_page + 2);
                                        
                                        if ($start_page > 1) {
                                            echo '<span style="padding: 10px;">...</span>';
                                        }
                                        
                                        for ($i = $start_page; $i <= $end_page; $i++): ?>
                                            <button class="page-btn <?php echo $i == $duty_page ? 'active' : ''; ?>" 
                                                    onclick="window.location.href='request_change.php?duty_page=<?php echo $i; ?><?php echo $duty_shift_filter ? '&duty_shift_id=' . $duty_shift_filter : ''; ?><?php echo $duty_type_filter ? '&duty_type=' . $duty_type_filter : ''; ?>'">
                                                <?php echo $i; ?>
                                            </button>
                                        <?php endfor;
                                        
                                        if ($end_page < $duty_data['total_pages']) {
                                            echo '<span style="padding: 10px;">...</span>';
                                        }
                                        ?>
                                    </div>
                                    
                                    <button class="pagination-btn" 
                                            onclick="window.location.href='request_change.php?duty_page=<?php echo $duty_page + 1; ?><?php echo $duty_shift_filter ? '&duty_shift_id=' . $duty_shift_filter : ''; ?><?php echo $duty_type_filter ? '&duty_type=' . $duty_type_filter : ''; ?>'"
                                            <?php echo $duty_page == $duty_data['total_pages'] ? 'disabled' : ''; ?>>
                                        Next <i class='bx bx-chevron-right'></i>
                                    </button>
                                    <button class="pagination-btn" 
                                            onclick="window.location.href='request_change.php?duty_page=<?php echo $duty_data['total_pages']; ?><?php echo $duty_shift_filter ? '&duty_shift_id=' . $duty_shift_filter : ''; ?><?php echo $duty_type_filter ? '&duty_type=' . $duty_type_filter : ''; ?>'"
                                            <?php echo $duty_page == $duty_data['total_pages'] ? 'disabled' : ''; ?>>
                                        Last <i class='bx bx-last-page'></i>
                                    </button>
                                </div>
                            </div>
                            <?php endif; ?>
                        <?php else: ?>
                            <div class="empty-state">
                                <i class='bx bx-task-x'></i>
                                <h3>No Duty Assignments Found</h3>
                                <p>No duty assignments match your current filters. Try creating new duty assignments or adjust your search criteria.</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Initialize event listeners
            initEventListeners();
            
            // Update time display
            updateTime();
            setInterval(updateTime, 1000);
            
            // Setup modals
            setupStatusModal();
            setupDetailsModal();
            
            // Setup cancel request buttons
            setupCancelButtons();
            
            // Auto-hide notification after 5 seconds
            setTimeout(() => {
                const notification = document.getElementById('notification');
                if (notification) {
                    notification.classList.remove('show');
                }
            }, 5000);
        });
        
        function initEventListeners() {
            // Theme toggle
            const themeToggle = document.getElementById('theme-toggle');
            const themeIcon = themeToggle.querySelector('i');
            const themeText = themeToggle.querySelector('span');
            
            themeToggle.addEventListener('click', function() {
                document.body.classList.toggle('dark-mode');
                
                if (document.body.classList.contains('dark-mode')) {
                    themeIcon.className = 'bx bx-sun';
                    themeText.textContent = 'Light Mode';
                } else {
                    themeIcon.className = 'bx bx-moon';
                    themeText.textContent = 'Dark Mode';
                }
            });
            
            // User profile dropdown
            const userProfile = document.getElementById('user-profile');
            const userDropdown = document.getElementById('user-dropdown');
            
            userProfile.addEventListener('click', function(e) {
                e.stopPropagation();
                userDropdown.classList.toggle('show');
            });
            
            // Close dropdowns when clicking outside
            document.addEventListener('click', function() {
                userDropdown.classList.remove('show');
            });
            
            // Notification close
            const notificationClose = document.getElementById('notification-close');
            if (notificationClose) {
                notificationClose.addEventListener('click', function() {
                    document.getElementById('notification').classList.remove('show');
                });
            }
        }
        
        function setupStatusModal() {
            const statusModal = document.getElementById('status-modal');
            const statusModalClose = document.getElementById('status-modal-close');
            const closeStatusModal = document.getElementById('close-status-modal');
            const updateButtons = document.querySelectorAll('.update-status-btn');
            
            statusModalClose.addEventListener('click', () => statusModal.classList.remove('active'));
            closeStatusModal.addEventListener('click', () => statusModal.classList.remove('active'));
            
            statusModal.addEventListener('click', function(e) {
                if (e.target === this) {
                    statusModal.classList.remove('active');
                }
            });
            
            updateButtons.forEach(button => {
                button.addEventListener('click', function() {
                    const requestId = this.getAttribute('data-request-id');
                    const volunteerName = this.getAttribute('data-volunteer-name');
                    const originalShift = this.getAttribute('data-original-shift');
                    const requestType = this.getAttribute('data-request-type');
                    const requestTypeText = this.getAttribute('data-request-type-text');
                    
                    document.getElementById('modal-request-id').value = requestId;
                    document.getElementById('modal-volunteer-name').textContent = volunteerName;
                    document.getElementById('modal-original-shift').textContent = originalShift;
                    document.getElementById('modal-request-type').textContent = requestTypeText;
                    document.getElementById('modal-request-type').setAttribute('data-request-type', requestType);
                    
                    // Reset form
                    document.getElementById('request_status').value = 'pending';
                    document.getElementById('admin_notes').value = '';
                    
                    statusModal.classList.add('active');
                });
            });
        }
        
        function setupDetailsModal() {
            const detailsModal = document.getElementById('details-modal');
            const detailsModalClose = document.getElementById('details-modal-close');
            const closeDetailsModal = document.getElementById('close-details-modal');
            const viewButtons = document.querySelectorAll('.view-details-btn');
            
            detailsModalClose.addEventListener('click', () => detailsModal.classList.remove('active'));
            closeDetailsModal.addEventListener('click', () => detailsModal.classList.remove('active'));
            
            detailsModal.addEventListener('click', function(e) {
                if (e.target === this) {
                    detailsModal.classList.remove('active');
                }
            });
            
            viewButtons.forEach(button => {
                button.addEventListener('click', function() {
                    const details = {
                        volunteerName: this.getAttribute('data-volunteer-name'),
                        volunteerContact: this.getAttribute('data-volunteer-contact'),
                        volunteerEmail: this.getAttribute('data-volunteer-email'),
                        originalShift: this.getAttribute('data-original-shift'),
                        unitName: this.getAttribute('data-unit-name'),
                        shiftStatus: this.getAttribute('data-shift-status'),
                        confirmationStatus: this.getAttribute('data-confirmation-status'),
                        requestType: this.getAttribute('data-request-type'),
                        requestDetails: this.getAttribute('data-request-details'),
                        requestDate: this.getAttribute('data-request-date'),
                        proposedDate: this.getAttribute('data-proposed-date'),
                        proposedTime: this.getAttribute('data-proposed-time'),
                        swapVolunteer: this.getAttribute('data-swap-volunteer'),
                        swapContact: this.getAttribute('data-swap-contact'),
                        swapEmail: this.getAttribute('data-swap-email'),
                        swapUnit: this.getAttribute('data-swap-unit'),
                        status: this.getAttribute('data-status'),
                        adminNotes: this.getAttribute('data-admin-notes'),
                        reviewedBy: this.getAttribute('data-reviewed-by'),
                        reviewedAt: this.getAttribute('data-reviewed-at')
                    };
                    
                    let detailsHtml = `
                        <div class="request-detail-item">
                            <div class="request-detail-label">Volunteer Information</div>
                            <div class="request-detail-value">
                                <strong>Name:</strong> ${details.volunteerName}<br>
                                <strong>Contact:</strong> ${details.volunteerContact}<br>
                                <strong>Email:</strong> ${details.volunteerEmail}
                            </div>
                        </div>
                        
                        <div class="request-detail-item">
                            <div class="request-detail-label">Original Shift</div>
                            <div class="request-detail-value">
                                <strong>Date & Time:</strong> ${details.originalShift}<br>
                                <strong>Unit:</strong> ${details.unitName}<br>
                                <strong>Shift Status:</strong> ${details.shiftStatus}<br>
                                <strong>Confirmation Status:</strong> ${details.confirmationStatus}
                            </div>
                        </div>
                        
                        <div class="request-detail-item">
                            <div class="request-detail-label">Request Information</div>
                            <div class="request-detail-value">
                                <strong>Type:</strong> ${details.requestType}<br>
                                <strong>Submitted:</strong> ${details.requestDate}<br>
                                <strong>Status:</strong> <span class="status-badge status-${details.status.toLowerCase()}">${details.status}</span>
                            </div>
                        </div>
                        
                        <div class="request-detail-item">
                            <div class="request-detail-label">Request Details</div>
                            <div class="request-detail-value">${details.requestDetails}</div>
                        </div>
                    `;
                    
                    if (details.proposedDate || details.proposedTime) {
                        detailsHtml += `
                            <div class="request-detail-item">
                                <div class="request-detail-label">Proposed Changes</div>
                                <div class="request-detail-value">
                                    ${details.proposedDate ? `<strong>Date:</strong> ${details.proposedDate}<br>` : ''}
                                    ${details.proposedTime ? `<strong>Time:</strong> ${details.proposedTime}` : ''}
                                </div>
                            </div>
                        `;
                    }
                    
                    if (details.swapVolunteer) {
                        detailsHtml += `
                            <div class="request-detail-item">
                                <div class="request-detail-label">Swap Volunteer</div>
                                <div class="request-detail-value">
                                    <strong>Name:</strong> ${details.swapVolunteer}<br>
                                    ${details.swapContact ? `<strong>Contact:</strong> ${details.swapContact}<br>` : ''}
                                    ${details.swapEmail ? `<strong>Email:</strong> ${details.swapEmail}<br>` : ''}
                                    ${details.swapUnit ? `<strong>Unit:</strong> ${details.swapUnit}` : ''}
                                </div>
                            </div>
                        `;
                    }
                    
                    if (details.adminNotes) {
                        detailsHtml += `
                            <div class="request-detail-item">
                                <div class="request-detail-label">Admin Notes</div>
                                <div class="request-detail-value">${details.adminNotes}</div>
                            </div>
                        `;
                    }
                    
                    if (details.reviewedBy) {
                        detailsHtml += `
                            <div class="request-detail-item">
                                <div class="request-detail-label">Review Information</div>
                                <div class="request-detail-value">
                                    <strong>Reviewed By:</strong> ${details.reviewedBy}<br>
                                    <strong>Reviewed At:</strong> ${details.reviewedAt}
                                </div>
                            </div>
                        `;
                    }
                    
                    document.getElementById('details-content').innerHTML = detailsHtml;
                    detailsModal.classList.add('active');
                });
            });
        }
        
        function setupCancelButtons() {
            const cancelButtons = document.querySelectorAll('.cancel-request-btn');
            
            cancelButtons.forEach(button => {
                button.addEventListener('click', function() {
                    const requestId = this.getAttribute('data-request-id');
                    
                    if (confirm('Are you sure you want to cancel this request? This action cannot be undone.')) {
                        const cancelReason = prompt('Please enter a reason for cancellation:', 'Cancelled by admin');
                        
                        if (cancelReason !== null) {
                            // Create a form dynamically
                            const form = document.createElement('form');
                            form.method = 'POST';
                            form.style.display = 'none';
                            
                            const requestIdInput = document.createElement('input');
                            requestIdInput.type = 'hidden';
                            requestIdInput.name = 'request_id';
                            requestIdInput.value = requestId;
                            form.appendChild(requestIdInput);
                            
                            const reasonInput = document.createElement('input');
                            reasonInput.type = 'hidden';
                            reasonInput.name = 'cancel_reason';
                            reasonInput.value = cancelReason;
                            form.appendChild(reasonInput);
                            
                            const cancelInput = document.createElement('input');
                            cancelInput.type = 'hidden';
                            cancelInput.name = 'cancel_request';
                            cancelInput.value = '1';
                            form.appendChild(cancelInput);
                            
                            document.body.appendChild(form);
                            form.submit();
                        }
                    }
                });
            });
        }
        
        function updateTime() {
            const now = new Date();
            const utc = now.getTime() + (now.getTimezoneOffset() * 60000);
            const gmt8 = new Date(utc + (8 * 3600000));
            
            const hours = gmt8.getHours().toString().padStart(2, '0');
            const minutes = gmt8.getMinutes().toString().padStart(2, '0');
            const seconds = gmt8.getSeconds().toString().padStart(2, '0');
            
            const timeString = `${hours}:${minutes}:${seconds} UTC+8`;
            document.getElementById('current-time').textContent = timeString;
        }
        
        function toggleSubmenu(id) {
            const submenu = document.getElementById(id);
            const arrow = submenu.previousElementSibling.querySelector('.dropdown-arrow');
            
            submenu.classList.toggle('active');
            arrow.classList.toggle('rotated');
        }
    </script>
</body>
</html>