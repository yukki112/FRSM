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
if ($role !== 'ADMIN' && $role !== 'ADMIN') {
    header("Location: ../admin_dashboard.php");
    exit();
}

// Function to get shift confirmations with filters
function getShiftConfirmations($pdo, $filter_date = null, $filter_status = null, $filter_unit = null) {
    $sql = "SELECT 
                s.id as shift_id,
                s.shift_date,
                s.start_time,
                s.end_time,
                s.shift_type,
                s.status as shift_status,
                s.confirmation_status,
                s.confirmed_at,
                s.declined_reason,
                s.change_request_notes,
                s.location,
                s.notes as shift_notes,
                s.created_at as shift_created_at,
                v.id as volunteer_id,
                v.first_name as volunteer_first_name,
                v.last_name as volunteer_last_name,
                v.contact_number as volunteer_contact,
                v.email as volunteer_email,
                v.volunteer_status,
                v.user_id as volunteer_user_id,
                u.id as unit_id,
                u.unit_name,
                u.unit_code,
                sc.id as confirmation_id,
                sc.status as confirmation_response_status,
                sc.response_notes as confirmation_notes,
                sc.responded_at as confirmation_responded_at,
                va.id as assignment_id,
                va.assignment_date,
                va.status as assignment_status
            FROM shifts s
            INNER JOIN volunteers v ON s.volunteer_id = v.id
            LEFT JOIN units u ON s.unit_id = u.id
            LEFT JOIN volunteer_assignments va ON v.id = va.volunteer_id AND va.status = 'Active'
            LEFT JOIN shift_confirmations sc ON s.id = sc.shift_id AND v.id = sc.volunteer_id
            WHERE s.shift_for = 'volunteer'
            AND s.volunteer_id IS NOT NULL
            AND v.status = 'approved'";
    
    $params = [];
    
    if ($filter_date) {
        $sql .= " AND s.shift_date = ?";
        $params[] = $filter_date;
    } else {
        // Default to upcoming shifts (next 30 days)
        $sql .= " AND s.shift_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)";
    }
    
    if ($filter_status) {
        if ($filter_status === 'confirmed') {
            $sql .= " AND (s.confirmation_status = 'confirmed' OR sc.status = 'confirmed')";
        } elseif ($filter_status === 'pending') {
            $sql .= " AND (s.confirmation_status = 'pending' OR sc.status = 'pending' OR (s.confirmation_status IS NULL AND (sc.status IS NULL OR sc.status = 'pending')))";
        } elseif ($filter_status === 'declined') {
            $sql .= " AND (s.confirmation_status = 'declined' OR sc.status = 'declined')";
        } elseif ($filter_status === 'change_requested') {
            $sql .= " AND s.confirmation_status = 'change_requested'";
        }
    }
    
    if ($filter_unit && $filter_unit !== 'all') {
        $sql .= " AND s.unit_id = ?";
        $params[] = $filter_unit;
    }
    
    $sql .= " ORDER BY 
                CASE 
                    WHEN s.confirmation_status = 'declined' OR sc.status = 'declined' THEN 1
                    WHEN s.confirmation_status = 'pending' OR sc.status = 'pending' OR s.confirmation_status IS NULL THEN 2
                    WHEN s.confirmation_status = 'change_requested' THEN 3
                    WHEN s.confirmation_status = 'confirmed' OR sc.status = 'confirmed' THEN 4
                    ELSE 5
                END,
                s.shift_date ASC,
                s.start_time ASC";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Function to get volunteer confirmation statistics
function getVolunteerConfirmationStats($pdo, $volunteer_id = null) {
    $sql = "SELECT 
                v.id as volunteer_id,
                v.first_name,
                v.last_name,
                COUNT(DISTINCT s.id) as total_shifts_assigned,
                COUNT(DISTINCT CASE WHEN s.confirmation_status = 'confirmed' OR sc.status = 'confirmed' THEN s.id END) as confirmed_shifts,
                COUNT(DISTINCT CASE WHEN s.confirmation_status = 'declined' OR sc.status = 'declined' THEN s.id END) as declined_shifts,
                COUNT(DISTINCT CASE WHEN s.confirmation_status = 'pending' OR sc.status = 'pending' OR s.confirmation_status IS NULL THEN s.id END) as pending_shifts,
                COUNT(DISTINCT CASE WHEN s.confirmation_status = 'change_requested' THEN s.id END) as change_requested_shifts,
                MIN(s.shift_date) as first_shift_date,
                MAX(s.shift_date) as last_shift_date,
                AVG(CASE 
                    WHEN s.confirmation_status = 'confirmed' OR sc.status = 'confirmed' 
                    THEN TIMESTAMPDIFF(HOUR, s.created_at, COALESCE(sc.responded_at, s.confirmed_at))
                    ELSE NULL 
                END) as avg_confirmation_time_hours
            FROM volunteers v
            LEFT JOIN shifts s ON v.id = s.volunteer_id AND s.shift_for = 'volunteer'
            LEFT JOIN shift_confirmations sc ON s.id = sc.shift_id AND v.id = sc.volunteer_id
            WHERE v.status = 'approved'";
    
    $params = [];
    
    if ($volunteer_id) {
        $sql .= " AND v.id = ?";
        $params[] = $volunteer_id;
    } else {
        $sql .= " AND s.id IS NOT NULL";
    }
    
    $sql .= " GROUP BY v.id, v.first_name, v.last_name
              HAVING total_shifts_assigned > 0
              ORDER BY confirmed_shifts DESC, total_shifts_assigned DESC";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Function to get all active units
function getUnits($pdo) {
    $sql = "SELECT id, unit_name, unit_code FROM units WHERE status = 'Active' ORDER BY unit_name";
    $stmt = $pdo->query($sql);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
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
    
    // Fix: Remove LIMIT and OFFSET from the prepared statement and use integers directly
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

// Function to find available replacements for a shift
function findAvailableReplacements($pdo, $shift_id, $exclude_volunteer_id = null) {
    $sql = "SELECT 
                v.id,
                v.first_name,
                v.last_name,
                v.contact_number,
                v.email,
                v.volunteer_status,
                v.available_days,
                v.available_hours,
                v.skills_basic_firefighting,
                v.skills_first_aid_cpr,
                v.skills_search_rescue,
                u.unit_name,
                u.unit_code,
                va.assignment_date,
                COUNT(DISTINCT s2.id) as assigned_shifts_on_date,
                (SELECT COUNT(*) FROM shifts s3 
                 WHERE s3.volunteer_id = v.id 
                 AND s3.confirmation_status = 'confirmed'
                 AND s3.shift_date BETWEEN DATE_SUB(CURDATE(), INTERVAL 30 DAY) AND CURDATE()
                ) as confirmed_past_month
            FROM volunteers v
            LEFT JOIN volunteer_assignments va ON v.id = va.volunteer_id AND va.status = 'Active'
            LEFT JOIN units u ON va.unit_id = u.id
            LEFT JOIN shifts s2 ON v.id = s2.volunteer_id 
                AND s2.shift_for = 'volunteer'
                AND s2.shift_date = (SELECT shift_date FROM shifts WHERE id = ?)
            WHERE v.status = 'approved'
            AND v.volunteer_status IN ('Active', 'New Volunteer')
            AND v.id != ?";
    
    $params = [$shift_id, $exclude_volunteer_id];
    
    $sql .= " GROUP BY v.id, v.first_name, v.last_name, v.contact_number, v.email, 
                v.volunteer_status, v.available_days, v.available_hours, 
                v.skills_basic_firefighting, v.skills_first_aid_cpr, v.skills_search_rescue,
                u.unit_name, u.unit_code, va.assignment_date
            HAVING assigned_shifts_on_date = 0
            ORDER BY confirmed_past_month DESC, va.assignment_date DESC
            LIMIT 10";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Function to update shift confirmation status
function updateConfirmationStatus($pdo, $shift_id, $status, $admin_id, $notes = null) {
    try {
        $pdo->beginTransaction();
        
        // Update shift confirmation status
        $sql = "UPDATE shifts SET 
                confirmation_status = ?,
                confirmed_at = CASE WHEN ? = 'confirmed' THEN NOW() ELSE confirmed_at END,
                updated_at = NOW()
                WHERE id = ?";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$status, $status, $shift_id]);
        
        // Log the update
        $log_sql = "INSERT INTO shift_change_requests (shift_id, volunteer_id, request_type, request_details, status, reviewed_at, reviewed_by)
                    SELECT s.id, s.volunteer_id, 'other', ?, 'approved', NOW(), ?
                    FROM shifts s
                    WHERE s.id = ?";
        
        $stmt = $pdo->prepare($log_sql);
        $stmt->execute([$notes ?: "Admin updated confirmation status to: $status", $admin_id, $shift_id]);
        
        $pdo->commit();
        return true;
    } catch (Exception $e) {
        $pdo->rollBack();
        error_log("Error updating confirmation status: " . $e->getMessage());
        return false;
    }
}

// Function to assign replacement volunteer
function assignReplacementVolunteer($pdo, $shift_id, $new_volunteer_id, $admin_id) {
    try {
        $pdo->beginTransaction();
        
        // Get old volunteer info
        $old_sql = "SELECT volunteer_id, confirmation_status FROM shifts WHERE id = ?";
        $old_stmt = $pdo->prepare($old_sql);
        $old_stmt->execute([$shift_id]);
        $old_data = $old_stmt->fetch();
        
        // Update shift with new volunteer
        $update_sql = "UPDATE shifts SET 
                      volunteer_id = ?,
                      confirmation_status = 'pending',
                      confirmed_at = NULL,
                      declined_reason = NULL,
                      change_request_notes = NULL,
                      updated_at = NOW()
                      WHERE id = ?";
        
        $update_stmt = $pdo->prepare($update_sql);
        $update_stmt->execute([$new_volunteer_id, $shift_id]);
        
        // Get new volunteer's user_id if exists
        $volunteer_sql = "SELECT user_id FROM volunteers WHERE id = ?";
        $volunteer_stmt = $pdo->prepare($volunteer_sql);
        $volunteer_stmt->execute([$new_volunteer_id]);
        $volunteer_data = $volunteer_stmt->fetch();
        
        if ($volunteer_data['user_id']) {
            $user_sql = "UPDATE shifts SET user_id = ? WHERE id = ?";
            $user_stmt = $pdo->prepare($user_sql);
            $user_stmt->execute([$volunteer_data['user_id'], $shift_id]);
        }
        
        // Log the replacement
        $log_sql = "INSERT INTO shift_change_requests (shift_id, volunteer_id, request_type, request_details, status, reviewed_at, reviewed_by)
                    VALUES (?, ?, 'swap', ?, 'approved', NOW(), ?)";
        
        $log_stmt = $pdo->prepare($log_sql);
        $log_details = "Admin reassigned shift from volunteer ID {$old_data['volunteer_id']} to volunteer ID $new_volunteer_id";
        $log_stmt->execute([$shift_id, $new_volunteer_id, $log_details, $admin_id]);
        
        $pdo->commit();
        return true;
    } catch (Exception $e) {
        $pdo->rollBack();
        error_log("Error assigning replacement: " . $e->getMessage());
        return false;
    }
}

// Handle form submissions
$success_message = '';
$error_message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['update_confirmation'])) {
        $shift_id = $_POST['shift_id'];
        $status = $_POST['confirmation_status'];
        $notes = $_POST['admin_notes'] ?? null;
        
        if (updateConfirmationStatus($pdo, $shift_id, $status, $user_id, $notes)) {
            $success_message = "Confirmation status updated successfully!";
        } else {
            $error_message = "Failed to update confirmation status.";
        }
    } elseif (isset($_POST['assign_replacement'])) {
        $shift_id = $_POST['shift_id'];
        $new_volunteer_id = $_POST['replacement_volunteer_id'];
        
        if (assignReplacementVolunteer($pdo, $shift_id, $new_volunteer_id, $user_id)) {
            $success_message = "Volunteer replaced successfully!";
        } else {
            $error_message = "Failed to assign replacement volunteer.";
        }
    } elseif (isset($_POST['send_reminder'])) {
        $shift_id = $_POST['shift_id'];
        $volunteer_id = $_POST['volunteer_id'];
        
        // Get volunteer contact info with proper join
        $contact_sql = "SELECT 
                            v.first_name, 
                            v.last_name, 
                            v.contact_number, 
                            v.email,
                            s.shift_date, 
                            s.start_time, 
                            s.location,
                            u.unit_name
                        FROM shifts s
                        INNER JOIN volunteers v ON s.volunteer_id = v.id
                        LEFT JOIN units u ON s.unit_id = u.id
                        WHERE s.id = ? AND v.id = ?";
        
        $contact_stmt = $pdo->prepare($contact_sql);
        $contact_stmt->execute([$shift_id, $volunteer_id]);
        $contact_data = $contact_stmt->fetch();
        
        if ($contact_data) {
            // Log the reminder attempt
            $log_sql = "INSERT INTO sms_logs (recipient, message, status, sent_at) 
                       VALUES (?, ?, 'sent', NOW())";
            
            $message = "Reminder: You have a shift on {$contact_data['shift_date']} at {$contact_data['start_time']} - {$contact_data['location']}. Please confirm your availability.";
            
            $log_stmt = $pdo->prepare($log_sql);
            $log_stmt->execute([$contact_data['contact_number'], $message]);
            
            $success_message = "Reminder sent to {$contact_data['first_name']} {$contact_data['last_name']}!";
        } else {
            $error_message = "Could not send reminder - volunteer information not found.";
        }
    }
}

// Get filter values
$filter_date = $_GET['date'] ?? null;
$filter_status = $_GET['status'] ?? null;
$filter_unit = $_GET['unit'] ?? null;

// Get data
$confirmations = getShiftConfirmations($pdo, $filter_date, $filter_status, $filter_unit);
$stats = getVolunteerConfirmationStats($pdo);
$units = getUnits($pdo);

// Calculate overall statistics
$total_shifts = count($confirmations);
$confirmed_shifts = count(array_filter($confirmations, function($c) {
    return $c['confirmation_status'] === 'confirmed' || $c['confirmation_response_status'] === 'confirmed';
}));
$pending_shifts = count(array_filter($confirmations, function($c) {
    return $c['confirmation_status'] === 'pending' || 
           $c['confirmation_response_status'] === 'pending' || 
           $c['confirmation_status'] === null;
}));
$declined_shifts = count(array_filter($confirmations, function($c) {
    return $c['confirmation_status'] === 'declined' || $c['confirmation_response_status'] === 'declined';
}));
$change_requested = count(array_filter($confirmations, function($c) {
    return $c['confirmation_status'] === 'change_requested';
}));

// Get duty assignments with pagination
$duty_page = isset($_GET['duty_page']) ? max(1, intval($_GET['duty_page'])) : 1;
$duty_per_page = 5;
$duty_shift_filter = $_GET['duty_shift_id'] ?? null;
$duty_type_filter = $_GET['duty_type'] ?? null;
$duty_data = getDutyAssignments($pdo, $duty_page, $duty_per_page, $duty_shift_filter, $duty_type_filter);

$stmt = null;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Confirm Availability - Fire & Rescue Management</title>
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

        .stat-icon.confirmed {
            background: rgba(16, 185, 129, 0.1);
            color: var(--success);
        }

        .stat-icon.pending {
            background: rgba(245, 158, 11, 0.1);
            color: var(--warning);
        }

        .stat-icon.declined {
            background: rgba(220, 38, 38, 0.1);
            color: var(--danger);
        }

        .stat-icon.change {
            background: rgba(139, 92, 246, 0.1);
            color: var(--purple);
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

        .status-confirmed {
            background: rgba(16, 185, 129, 0.1);
            color: var(--success);
            border: 1px solid rgba(16, 185, 129, 0.2);
        }

        .status-pending {
            background: rgba(245, 158, 11, 0.1);
            color: var(--warning);
            border: 1px solid rgba(245, 158, 11, 0.2);
        }

        .status-declined {
            background: rgba(220, 38, 38, 0.1);
            color: var(--danger);
            border: 1px solid rgba(220, 38, 38, 0.2);
        }

        .status-change_requested {
            background: rgba(139, 92, 246, 0.1);
            color: var(--purple);
            border: 1px solid rgba(139, 92, 246, 0.2);
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

        .unit-info {
            display: flex;
            flex-direction: column;
            gap: 4px;
        }

        .unit-name {
            font-weight: 600;
            color: var(--text-color);
        }

        .unit-code {
            font-size: 12px;
            color: var(--text-light);
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
            max-width: 600px;
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

        .replacement-list {
            max-height: 300px;
            overflow-y: auto;
            margin-top: 20px;
        }

        .replacement-item {
            padding: 15px;
            border: 1px solid var(--border-color);
            border-radius: 8px;
            margin-bottom: 10px;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .replacement-item:hover {
            border-color: var(--primary-color);
            background: rgba(220, 38, 38, 0.05);
        }

        .replacement-item.selected {
            border-color: var(--primary-color);
            background: rgba(220, 38, 38, 0.1);
        }

        .replacement-info {
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .replacement-skills {
            display: flex;
            gap: 5px;
            margin-top: 8px;
            flex-wrap: wrap;
        }

        .skill-badge {
            display: inline-block;
            padding: 3px 8px;
            border-radius: 12px;
            font-size: 11px;
            background: rgba(59, 130, 246, 0.1);
            color: var(--info);
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

        .reliability-badge {
            display: inline-block;
            padding: 4px 8px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 600;
            margin-left: 5px;
        }

        .reliability-high {
            background: rgba(16, 185, 129, 0.2);
            color: var(--success);
        }

        .reliability-medium {
            background: rgba(245, 158, 11, 0.2);
            color: var(--warning);
        }

        .reliability-low {
            background: rgba(220, 38, 38, 0.2);
            color: var(--danger);
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
    </style>
</head>
<body>
    <!-- Confirmation Modal -->
    <div class="modal-overlay" id="confirmation-modal">
        <div class="modal">
            <div class="modal-header">
                <h2 class="modal-title">Update Confirmation Status</h2>
                <button class="modal-close" id="confirmation-modal-close">&times;</button>
            </div>
            <form method="POST" id="confirmation-form">
                <div class="modal-body">
                    <input type="hidden" name="shift_id" id="modal-shift-id">
                    <input type="hidden" name="update_confirmation" value="1">
                    
                    <div class="form-group">
                        <label class="form-label" for="confirmation_status">Confirmation Status</label>
                        <select class="form-select" id="confirmation_status" name="confirmation_status" required>
                            <option value="pending">Pending</option>
                            <option value="confirmed">Confirmed</option>
                            <option value="declined">Declined</option>
                            <option value="change_requested">Change Requested</option>
                        </select>
                    </div>
                    
                    <div class="form-group" style="margin-top: 20px;">
                        <label class="form-label" for="admin_notes">Admin Notes</label>
                        <textarea class="form-input" id="admin_notes" name="admin_notes" 
                                  placeholder="Add any notes about this confirmation..." rows="4"></textarea>
                    </div>
                </div>
                <div class="modal-actions">
                    <button type="button" class="btn btn-secondary" id="close-confirmation-modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Update Status</button>
                </div>
            </form>
        </div>
    </div>
    
    <!-- Replacement Modal -->
    <div class="modal-overlay" id="replacement-modal">
        <div class="modal">
            <div class="modal-header">
                <h2 class="modal-title">Assign Replacement Volunteer</h2>
                <button class="modal-close" id="replacement-modal-close">&times;</button>
            </div>
            <form method="POST" id="replacement-form">
                <div class="modal-body">
                    <input type="hidden" name="shift_id" id="replacement-shift-id">
                    <input type="hidden" name="assign_replacement" value="1">
                    
                    <p id="replacement-info-text">Loading available volunteers...</p>
                    
                    <div class="replacement-list" id="replacement-list">
                        <!-- Available volunteers will be loaded here -->
                    </div>
                    
                    <input type="hidden" name="replacement_volunteer_id" id="selected-replacement-id">
                </div>
                <div class="modal-actions">
                    <button type="button" class="btn btn-secondary" id="close-replacement-modal">Cancel</button>
                    <button type="submit" class="btn btn-primary" id="assign-replacement-btn" disabled>Assign Replacement</button>
                </div>
            </form>
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
        <!-- Sidebar (same as your existing sidebar) -->
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
                    <a href="../admin/dashboard.php" class="menu-item">
                        <div class="icon-box icon-bg-red">
                            <i class='bx bxs-dashboard icon-red'></i>
                        </div>
                        <span class="font-medium">Dashboard</span>
                    </a>
                    
                    <!-- User Management -->
                    <div class="menu-item" onclick="toggleSubmenu('user-management')">
                        <div class="icon-box icon-bg-orange">
                            <i class='bx bxs-user icon-orange'></i>
                        </div>
                        <span class="font-medium">User Management</span>
                        <svg class="dropdown-arrow menu-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                        </svg>
                    </div>
                    <div id="user-management" class="submenu">
                        <a href="manage_users.php" class="submenu-item">Manage Users</a>
                        <a href="role_control.php" class="submenu-item">Role Control</a>
                        <a href="audit_logs.php" class="submenu-item">Audit & Activity Logs</a>
                    </div>
                    
                    <!-- Fire & Incident Reporting Management -->
                    <div class="menu-item" onclick="toggleSubmenu('incident-management')">
                        <div class="icon-box icon-bg-yellow">
                            <i class='bx bxs-alarm-exclamation icon-yellow'></i>
                        </div>
                        <span class="font-medium">Incident Management</span>
                        <svg class="dropdown-arrow menu-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                        </svg>
                    </div>
                    <div id="incident-management" class="submenu">
                        <a href="#" class="submenu-item">View Reports</a>
                        <a href="#" class="submenu-item">Validate Data</a>
                        <a href="#" class="submenu-item">Assign Severity</a>
                        <a href="#" class="submenu-item">Track Progress</a>
                        <a href="#" class="submenu-item">Mark Resolved</a>
                    </div>
                    
                    <!-- Volunteer Management -->
                    <div class="menu-item" onclick="toggleSubmenu('volunteer-management')">
                        <div class="icon-box icon-bg-blue">
                            <i class='bx bxs-user-detail icon-blue'></i>
                        </div>
                        <span class="font-medium">Volunteer Management</span>
                        <svg class="dropdown-arrow menu-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                        </svg>
                    </div>
                    <div id="volunteer-management" class="submenu">
                        <a href="../vm/review_data.php" class="submenu-item">Review Data</a>
                        <a href="../vm/assign_volunteers.php" class="submenu-item">Assign Volunteers</a>
                        <a href="../vm/view_availability.php" class="submenu-item">View Availability</a>
                        <a href="../vm/remove_volunteers.php" class="submenu-item">Remove Volunteers</a>
                        <a href="../vm/toggle_volunteer_registration.php" class="submenu-item">Toggle Volunteer Registration Access</a>
                    </div>
                    
                    <!-- Resource Inventory Management -->
                    <div class="menu-item" onclick="toggleSubmenu('resource-management')">
                        <div class="icon-box icon-bg-green">
                            <i class='bx bxs-cube icon-green'></i>
                        </div>
                        <span class="font-medium">Resource Management</span>
                        <svg class="dropdown-arrow menu-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                        </svg>
                    </div>
                    <div id="resource-management" class="submenu">
                        <a href="#" class="submenu-item">View Equipment</a>
                        <a href="#" class="submenu-item">Approve Maintenance</a>
                        <a href="#" class="submenu-item">Approve Resources</a>
                        <a href="#" class="submenu-item">Review Deployment</a>
                    </div>
                    
                    <!-- Shift & Duty Scheduling -->
                    <div class="menu-item active" onclick="toggleSubmenu('schedule-management')">
                        <div class="icon-box icon-bg-purple">
                            <i class='bx bxs-calendar icon-purple'></i>
                        </div>
                        <span class="font-medium">Schedule Management</span>
                        <svg class="dropdown-arrow menu-icon rotated" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                        </svg>
                    </div>
                    <div id="schedule-management" class="submenu active">
                       <a href="view_shifts.php" class="submenu-item">View Shifts</a>
                        <a href="create_schedule.php" class="submenu-item">Create Schedule</a>
                        <a href="confirm_availability.php" class="submenu-item active">Confirm Availability</a>
                      <a href="request_change.php" class="submenu-item">Request Change</a>
                        <a href="monitor_attendance.php" class="submenu-item">Monitor Attendance</a>
                    </div>
                    
                    <!-- Training & Certification Monitoring -->
                    <div class="menu-item" onclick="toggleSubmenu('training-management')">
                        <div class="icon-box icon-bg-teal">
                            <i class='bx bxs-graduation icon-teal'></i>
                        </div>
                        <span class="font-medium">Training Management</span>
                        <svg class="dropdown-arrow menu-icon rotated" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                        </svg>
                    </div>
                    <div id="training-management" class="submenu">
                        <a href="../tc/approve_completions.php" class="submenu-item">Approve Completions</a>
                        <a href="../tc/view_training_records.php" class="submenu-item">View Records</a>
                        <a href="../tc/assign_training.php" class="submenu-item">Assign Training</a>
                        <a href="../tc/track_expiry.php" class="submenu-item">Track Expiry</a>
                    </div>

                    <!-- Inspection Logs for Establishments -->
                    <div class="menu-item" onclick="toggleSubmenu('inspection-management')">
                        <div class="icon-box icon-bg-cyan">
                            <i class='bx bxs-check-shield icon-cyan'></i>
                        </div>
                        <span class="font-medium">Inspection Management</span>
                        <svg class="dropdown-arrow menu-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                        </svg>
                    </div>
                    <div id="inspection-management" class="submenu">
                        <a href="#" class="submenu-item">Approve Reports</a>
                        <a href="#" class="submenu-item">Review Violations</a>
                        <a href="#" class="submenu-item">Issue Certificates</a>
                        <a href="#" class="submenu-item">Track Follow-Up</a>
                    </div>
                    
                    <!-- Post-Incident Reporting & Analytics -->
                    <div class="menu-item" onclick="toggleSubmenu('analytics-management')">
                        <div class="icon-box icon-bg-pink">
                            <i class='bx bxs-file-doc icon-pink'></i>
                        </div>
                        <span class="font-medium">Analytics & Reports</span>
                        <svg class="dropdown-arrow menu-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                        </svg>
                    </div>
                    <div id="analytics-management" class="submenu">
                        <a href="#" class="submenu-item">Review Summaries</a>
                        <a href="#" class="submenu-item">Analyze Data</a>
                        <a href="#" class="submenu-item">Export Reports</a>
                        <a href="#" class="submenu-item">Generate Statistics</a>
                    </div>
                </div>
                
                <p class="menu-title" style="margin-top: 32px;">GENERAL</p>
                
                <div class="menu-items">
                    <a href="#" class="menu-item">
                        <div class="icon-box icon-bg-teal">
                            <i class='bx bxs-cog icon-teal'></i>
                        </div>
                        <span class="font-medium">Settings</span>
                    </a>
                    
                    <a href="../profile.php" class="menu-item">
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
                            <input type="text" placeholder="Search volunteers..." class="search-input" id="search-input">
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
                        <h1 class="dashboard-title">Confirm Availability</h1>
                        <p class="dashboard-subtitle">Track volunteer shift confirmations and identify staffing gaps early</p>
                    </div>
                </div>
                
                <!-- Content Container -->
                <div class="content-container">
                    <!-- Statistics Cards -->
                    <div class="stats-container">
                        <div class="stat-card">
                            <div class="stat-icon confirmed">
                                <i class='bx bx-check-circle'></i>
                            </div>
                            <div class="stat-value"><?php echo $confirmed_shifts; ?></div>
                            <div class="stat-label">Confirmed Shifts</div>
                        </div>
                        
                        <div class="stat-card">
                            <div class="stat-icon pending">
                                <i class='bx bx-time-five'></i>
                            </div>
                            <div class="stat-value"><?php echo $pending_shifts; ?></div>
                            <div class="stat-label">Pending Confirmation</div>
                        </div>
                        
                        <div class="stat-card">
                            <div class="stat-icon declined">
                                <i class='bx bx-x-circle'></i>
                            </div>
                            <div class="stat-value"><?php echo $declined_shifts; ?></div>
                            <div class="stat-label">Declined Shifts</div>
                        </div>
                        
                        <div class="stat-card">
                            <div class="stat-icon change">
                                <i class='bx bx-edit'></i>
                            </div>
                            <div class="stat-value"><?php echo $change_requested; ?></div>
                            <div class="stat-label">Change Requests</div>
                        </div>
                    </div>
                    
                    <!-- Filters -->
                    <div class="filters-container">
                        <h3 class="filters-title">
                            <i class='bx bx-filter-alt'></i>
                            Filter Shifts
                        </h3>
                        
                        <form method="GET" class="filters-form">
                            <div class="form-group">
                                <label class="form-label" for="date">Shift Date</label>
                                <input type="date" class="form-input" id="date" name="date" 
                                       value="<?php echo htmlspecialchars($filter_date ?? ''); ?>">
                            </div>
                            
                            <div class="form-group">
                                <label class="form-label" for="status">Confirmation Status</label>
                                <select class="form-select" id="status" name="status">
                                    <option value="">All Statuses</option>
                                    <option value="confirmed" <?php echo $filter_status === 'confirmed' ? 'selected' : ''; ?>>Confirmed</option>
                                    <option value="pending" <?php echo $filter_status === 'pending' ? 'selected' : ''; ?>>Pending</option>
                                    <option value="declined" <?php echo $filter_status === 'declined' ? 'selected' : ''; ?>>Declined</option>
                                    <option value="change_requested" <?php echo $filter_status === 'change_requested' ? 'selected' : ''; ?>>Change Requested</option>
                                </select>
                            </div>
                            
                            <div class="form-group">
                                <label class="form-label" for="unit">Assigned Unit</label>
                                <select class="form-select" id="unit" name="unit">
                                    <option value="all">All Units</option>
                                    <?php foreach ($units as $unit): ?>
                                        <option value="<?php echo $unit['id']; ?>" 
                                            <?php echo $filter_unit == $unit['id'] ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($unit['unit_name']); ?>
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
                    
                    <!-- Confirmation Status Table -->
                    <div class="table-container">
                        <div class="table-header">
                            <h3 class="table-title">
                                <i class='bx bx-calendar-check'></i>
                                Shift Confirmations
                                <span style="font-size: 14px; font-weight: normal; color: var(--text-light); margin-left: 10px;">
                                    <?php echo $total_shifts; ?> shifts found
                                </span>
                            </h3>
                            <div>
                                <?php if ($filter_date || $filter_status || $filter_unit): ?>
                                    <a href="confirm_availability.php" class="btn btn-secondary">
                                        <i class='bx bx-reset'></i>
                                        Clear Filters
                                    </a>
                                <?php endif; ?>
                            </div>
                        </div>
                        
                        <?php if (count($confirmations) > 0): ?>
                            <table class="table">
                                <thead>
                                    <tr>
                                        <th>Volunteer</th>
                                        <th>Shift Details</th>
                                        <th>Unit</th>
                                        <th>Confirmation Status</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($confirmations as $confirmation): 
                                        // Determine actual confirmation status
                                        $actual_status = $confirmation['confirmation_status'];
                                        if (empty($actual_status) && $confirmation['confirmation_response_status']) {
                                            $actual_status = $confirmation['confirmation_response_status'];
                                        }
                                        if (empty($actual_status)) {
                                            $actual_status = 'pending';
                                        }
                                        
                                        // Get volunteer reliability
                                        $volunteer_stats = array_filter($stats, function($stat) use ($confirmation) {
                                            return $stat['volunteer_id'] == $confirmation['volunteer_id'];
                                        });
                                        $volunteer_stats = reset($volunteer_stats);
                                        
                                        $reliability_class = '';
                                        $reliability_text = 'New';
                                        $confirmation_rate = 0;
                                        
                                        if ($volunteer_stats) {
                                            $confirmation_rate = $volunteer_stats['total_shifts_assigned'] > 0 ? 
                                                ($volunteer_stats['confirmed_shifts'] / $volunteer_stats['total_shifts_assigned']) * 100 : 0;
                                            
                                            if ($confirmation_rate >= 80) {
                                                $reliability_class = 'reliability-high';
                                                $reliability_text = 'High';
                                            } elseif ($confirmation_rate >= 60) {
                                                $reliability_class = 'reliability-medium';
                                                $reliability_text = 'Medium';
                                            } else {
                                                $reliability_class = 'reliability-low';
                                                $reliability_text = 'Low';
                                            }
                                        }
                                        
                                        // Format dates
                                        $shift_date = date('M j, Y', strtotime($confirmation['shift_date']));
                                        $confirmed_date = $confirmation['confirmed_at'] ? 
                                            date('M j, Y g:i A', strtotime($confirmation['confirmed_at'])) : null;
                                        $confirmation_date = $confirmation['confirmation_responded_at'] ? 
                                            date('M j, Y g:i A', strtotime($confirmation['confirmation_responded_at'])) : null;
                                    ?>
                                    <tr>
                                        <td>
                                            <div class="volunteer-info">
                                                <div class="volunteer-avatar">
                                                    <?php echo strtoupper(substr($confirmation['volunteer_first_name'], 0, 1)); ?>
                                                </div>
                                                <div class="volunteer-details">
                                                    <div class="volunteer-name">
                                                        <?php echo htmlspecialchars($confirmation['volunteer_first_name'] . ' ' . $confirmation['volunteer_last_name']); ?>
                                                        <?php if ($volunteer_stats): ?>
                                                            <span class="reliability-badge <?php echo $reliability_class; ?>" 
                                                                  title="Confirmation Rate: <?php echo number_format($confirmation_rate, 1); ?>%">
                                                                <?php echo $reliability_text; ?>
                                                            </span>
                                                        <?php else: ?>
                                                            <span class="reliability-badge reliability-medium" title="No confirmation history yet">
                                                                New
                                                            </span>
                                                        <?php endif; ?>
                                                    </div>
                                                    <div class="volunteer-contact">
                                                        <i class='bx bx-phone'></i> <?php echo htmlspecialchars($confirmation['volunteer_contact']); ?>
                                                        <?php if ($confirmation['volunteer_user_id']): ?>
                                                            <span style="margin-left: 10px; color: var(--success);">
                                                                <i class='bx bx-user-check'></i> Has Account
                                                            </span>
                                                        <?php endif; ?>
                                                    </div>
                                                </div>
                                            </div>
                                        </td>
                                        <td>
                                            <div class="shift-details">
                                                <div class="shift-date">
                                                    <?php echo $shift_date; ?>
                                                </div>
                                                <div class="shift-time">
                                                    <?php echo date('g:i A', strtotime($confirmation['start_time'])); ?> - 
                                                    <?php echo date('g:i A', strtotime($confirmation['end_time'])); ?>
                                                </div>
                                                <?php if ($confirmation['location']): ?>
                                                    <div style="font-size: 12px; color: var(--text-light); margin-top: 4px;">
                                                        <i class='bx bx-map'></i> <?php echo htmlspecialchars($confirmation['location']); ?>
                                                    </div>
                                                <?php endif; ?>
                                                <?php if ($confirmed_date || $confirmation_date): ?>
                                                    <div style="font-size: 11px; color: var(--text-light); margin-top: 4px;">
                                                        <?php echo $confirmation_date ? "Responded: $confirmation_date" : ($confirmed_date ? "Confirmed: $confirmed_date" : ''); ?>
                                                    </div>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                        <td>
                                            <?php if ($confirmation['unit_name']): ?>
                                                <div class="unit-info">
                                                    <div class="unit-name"><?php echo htmlspecialchars($confirmation['unit_name']); ?></div>
                                                    <div class="unit-code"><?php echo htmlspecialchars($confirmation['unit_code']); ?></div>
                                                </div>
                                            <?php else: ?>
                                                <span style="color: var(--text-light); font-style: italic;">No unit assigned</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div class="status-badge status-<?php echo $actual_status; ?>">
                                                <?php echo ucfirst($actual_status); ?>
                                            </div>
                                            <?php if ($confirmation['declined_reason']): ?>
                                                <div style="font-size: 12px; color: var(--danger); margin-top: 5px;">
                                                    <i class='bx bx-info-circle'></i> <?php echo htmlspecialchars($confirmation['declined_reason']); ?>
                                                </div>
                                            <?php endif; ?>
                                            <?php if ($confirmation['change_request_notes']): ?>
                                                <div style="font-size: 12px; color: var(--purple); margin-top: 5px;">
                                                    <i class='bx bx-edit'></i> <?php echo htmlspecialchars($confirmation['change_request_notes']); ?>
                                                </div>
                                            <?php endif; ?>
                                            <?php if ($confirmation['confirmation_notes']): ?>
                                                <div style="font-size: 12px; color: var(--info); margin-top: 5px;">
                                                    <i class='bx bx-message'></i> <?php echo htmlspecialchars($confirmation['confirmation_notes']); ?>
                                                </div>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div class="action-buttons">
                                                <button type="button" class="btn btn-info update-confirmation-btn" 
                                                        data-shift-id="<?php echo $confirmation['shift_id']; ?>"
                                                        data-current-status="<?php echo $actual_status; ?>">
                                                    <i class='bx bx-edit'></i> Update
                                                </button>
                                                
                                                <?php if ($actual_status === 'declined' || $actual_status === 'pending'): ?>
                                                    <button type="button" class="btn btn-warning find-replacement-btn"
                                                            data-shift-id="<?php echo $confirmation['shift_id']; ?>"
                                                            data-volunteer-id="<?php echo $confirmation['volunteer_id']; ?>">
                                                        <i class='bx bx-user-plus'></i> Replace
                                                    </button>
                                                <?php endif; ?>
                                                
                                                <?php if ($actual_status === 'pending'): ?>
                                                    <form method="POST" style="display: inline;">
                                                        <input type="hidden" name="shift_id" value="<?php echo $confirmation['shift_id']; ?>">
                                                        <input type="hidden" name="volunteer_id" value="<?php echo $confirmation['volunteer_id']; ?>">
                                                        <button type="submit" name="send_reminder" class="btn btn-secondary">
                                                            <i class='bx bx-bell'></i> Remind
                                                        </button>
                                                    </form>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php else: ?>
                            <div class="empty-state">
                                <i class='bx bx-calendar-x'></i>
                                <h3>No Shifts Found</h3>
                                <p>No shift confirmations match your current filters. Try adjusting your search criteria or create new shifts.</p>
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
                                    <a href="confirm_availability.php" class="btn btn-secondary">
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
                                            onclick="window.location.href='confirm_availability.php?duty_page=1<?php echo $duty_shift_filter ? '&duty_shift_id=' . $duty_shift_filter : ''; ?><?php echo $duty_type_filter ? '&duty_type=' . $duty_type_filter : ''; ?>'"
                                            <?php echo $duty_page == 1 ? 'disabled' : ''; ?>>
                                        <i class='bx bx-first-page'></i> First
                                    </button>
                                    <button class="pagination-btn" 
                                            onclick="window.location.href='confirm_availability.php?duty_page=<?php echo $duty_page - 1; ?><?php echo $duty_shift_filter ? '&duty_shift_id=' . $duty_shift_filter : ''; ?><?php echo $duty_type_filter ? '&duty_type=' . $duty_type_filter : ''; ?>'"
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
                                                    onclick="window.location.href='confirm_availability.php?duty_page=<?php echo $i; ?><?php echo $duty_shift_filter ? '&duty_shift_id=' . $duty_shift_filter : ''; ?><?php echo $duty_type_filter ? '&duty_type=' . $duty_type_filter : ''; ?>'">
                                                <?php echo $i; ?>
                                            </button>
                                        <?php endfor;
                                        
                                        if ($end_page < $duty_data['total_pages']) {
                                            echo '<span style="padding: 10px;">...</span>';
                                        }
                                        ?>
                                    </div>
                                    
                                    <button class="pagination-btn" 
                                            onclick="window.location.href='confirm_availability.php?duty_page=<?php echo $duty_page + 1; ?><?php echo $duty_shift_filter ? '&duty_shift_id=' . $duty_shift_filter : ''; ?><?php echo $duty_type_filter ? '&duty_type=' . $duty_type_filter : ''; ?>'"
                                            <?php echo $duty_page == $duty_data['total_pages'] ? 'disabled' : ''; ?>>
                                        Next <i class='bx bx-chevron-right'></i>
                                    </button>
                                    <button class="pagination-btn" 
                                            onclick="window.location.href='confirm_availability.php?duty_page=<?php echo $duty_data['total_pages']; ?><?php echo $duty_shift_filter ? '&duty_shift_id=' . $duty_shift_filter : ''; ?><?php echo $duty_type_filter ? '&duty_type=' . $duty_type_filter : ''; ?>'"
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
                    
                    <!-- Volunteer Reliability Statistics -->
                    <?php if (count($stats) > 0): ?>
                    <div class="table-container">
                        <div class="table-header">
                            <h3 class="table-title">
                                <i class='bx bx-trending-up'></i>
                                Volunteer Reliability Statistics
                            </h3>
                        </div>
                        
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>Volunteer</th>
                                    <th>Total Shifts</th>
                                    <th>Confirmed</th>
                                    <th>Declined</th>
                                    <th>Pending</th>
                                    <th>Confirmation Rate</th>
                                    <th>Avg. Response Time</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($stats as $stat): 
                                    $confirmation_rate = $stat['total_shifts_assigned'] > 0 ? 
                                        ($stat['confirmed_shifts'] / $stat['total_shifts_assigned']) * 100 : 0;
                                    
                                    $reliability_class = '';
                                    if ($confirmation_rate >= 80) {
                                        $reliability_class = 'reliability-high';
                                    } elseif ($confirmation_rate >= 60) {
                                        $reliability_class = 'reliability-medium';
                                    } else {
                                        $reliability_class = 'reliability-low';
                                    }
                                ?>
                                <tr>
                                    <td>
                                        <div class="volunteer-info">
                                            <div class="volunteer-avatar">
                                                <?php echo strtoupper(substr($stat['first_name'], 0, 1)); ?>
                                            </div>
                                            <div class="volunteer-details">
                                                <div class="volunteer-name">
                                                    <?php echo htmlspecialchars($stat['first_name'] . ' ' . $stat['last_name']); ?>
                                                    <span class="reliability-badge <?php echo $reliability_class; ?>">
                                                        <?php echo number_format($confirmation_rate, 1); ?>%
                                                    </span>
                                                </div>
                                                <div class="volunteer-contact">
                                                    <?php if ($stat['first_shift_date']): ?>
                                                        <i class='bx bx-calendar'></i> Since <?php echo date('M Y', strtotime($stat['first_shift_date'])); ?>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        </div>
                                    </td>
                                    <td><strong><?php echo $stat['total_shifts_assigned']; ?></strong></td>
                                    <td>
                                        <span style="color: var(--success); font-weight: 600;">
                                            <?php echo $stat['confirmed_shifts']; ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span style="color: var(--danger); font-weight: 600;">
                                            <?php echo $stat['declined_shifts']; ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span style="color: var(--warning); font-weight: 600;">
                                            <?php echo $stat['pending_shifts']; ?>
                                        </span>
                                    </td>
                                    <td>
                                        <div class="status-badge <?php echo $reliability_class; ?>" style="background: transparent; border: none; padding: 0;">
                                            <?php echo number_format($confirmation_rate, 1); ?>%
                                        </div>
                                    </td>
                                    <td>
                                        <?php if ($stat['avg_confirmation_time_hours']): ?>
                                            <?php echo number_format($stat['avg_confirmation_time_hours'], 1); ?> hours
                                        <?php else: ?>
                                            <span style="color: var(--text-light); font-style: italic;">N/A</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php endif; ?>
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
            
            // Setup confirmation modal buttons
            setupConfirmationModal();
            
            // Setup replacement modal buttons
            setupReplacementModal();
            
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
        
        function setupConfirmationModal() {
            const confirmationModal = document.getElementById('confirmation-modal');
            const confirmationModalClose = document.getElementById('confirmation-modal-close');
            const closeConfirmationModal = document.getElementById('close-confirmation-modal');
            const updateButtons = document.querySelectorAll('.update-confirmation-btn');
            
            confirmationModalClose.addEventListener('click', () => confirmationModal.classList.remove('active'));
            closeConfirmationModal.addEventListener('click', () => confirmationModal.classList.remove('active'));
            
            confirmationModal.addEventListener('click', function(e) {
                if (e.target === this) {
                    confirmationModal.classList.remove('active');
                }
            });
            
            updateButtons.forEach(button => {
                button.addEventListener('click', function() {
                    const shiftId = this.getAttribute('data-shift-id');
                    const currentStatus = this.getAttribute('data-current-status');
                    
                    document.getElementById('modal-shift-id').value = shiftId;
                    document.getElementById('confirmation_status').value = currentStatus;
                    
                    confirmationModal.classList.add('active');
                });
            });
        }
        
        function setupReplacementModal() {
            const replacementModal = document.getElementById('replacement-modal');
            const replacementModalClose = document.getElementById('replacement-modal-close');
            const closeReplacementModal = document.getElementById('close-replacement-modal');
            const findButtons = document.querySelectorAll('.find-replacement-btn');
            
            replacementModalClose.addEventListener('click', () => replacementModal.classList.remove('active'));
            closeReplacementModal.addEventListener('click', () => replacementModal.classList.remove('active'));
            
            replacementModal.addEventListener('click', function(e) {
                if (e.target === this) {
                    replacementModal.classList.remove('active');
                }
            });
            
            findButtons.forEach(button => {
                button.addEventListener('click', async function() {
                    const shiftId = this.getAttribute('data-shift-id');
                    const volunteerId = this.getAttribute('data-volunteer-id');
                    
                    document.getElementById('replacement-shift-id').value = shiftId;
                    
                    // Show loading state
                    document.getElementById('replacement-list').innerHTML = `
                        <div style="text-align: center; padding: 20px; color: var(--text-light);">
                            <i class='bx bx-loader-alt bx-spin'></i>
                            <p>Loading available volunteers...</p>
                        </div>
                    `;
                    
                    document.getElementById('assign-replacement-btn').disabled = true;
                    
                    replacementModal.classList.add('active');
                    
                    try {
                        // Fetch available replacements via AJAX
                        const response = await fetch(`find_replacements.php?shift_id=${shiftId}&exclude_volunteer_id=${volunteerId}`);
                        const replacements = await response.json();
                        
                        if (replacements.length > 0) {
                            let replacementsHtml = '';
                            
                            replacements.forEach(replacement => {
                                const skills = [];
                                if (replacement.skills_basic_firefighting) skills.push('Firefighting');
                                if (replacement.skills_first_aid_cpr) skills.push('First Aid/CPR');
                                if (replacement.skills_search_rescue) skills.push('Search & Rescue');
                                
                                replacementsHtml += `
                                    <div class="replacement-item" data-volunteer-id="${replacement.id}">
                                        <div class="replacement-info">
                                            <div>
                                                <div style="font-weight: 600; color: var(--text-color);">
                                                    ${replacement.first_name} ${replacement.last_name}
                                                    <span style="font-size: 11px; color: var(--success); margin-left: 5px;">
                                                        <i class='bx bx-check-circle'></i> ${replacement.confirmed_past_month || 0} past confirmations
                                                    </span>
                                                </div>
                                                <div style="font-size: 12px; color: var(--text-light); margin-top: 4px;">
                                                    <i class='bx bx-phone'></i> ${replacement.contact_number}
                                                    ${replacement.unit_name ? ` • <i class='bx bx-building-house'></i> ${replacement.unit_name}` : ''}
                                                </div>
                                                ${skills.length > 0 ? `
                                                    <div class="replacement-skills">
                                                        ${skills.map(skill => `<span class="skill-badge">${skill}</span>`).join('')}
                                                    </div>
                                                ` : ''}
                                            </div>
                                            <i class='bx bx-check' style="color: var(--success); font-size: 20px; display: none;"></i>
                                        </div>
                                    </div>
                                `;
                            });
                            
                            document.getElementById('replacement-info-text').textContent = 
                                `Found ${replacements.length} available volunteers for this shift. Select one to assign as replacement:`;
                            document.getElementById('replacement-list').innerHTML = replacementsHtml;
                            
                            // Add click handlers for replacement items
                            document.querySelectorAll('.replacement-item').forEach(item => {
                                item.addEventListener('click', function() {
                                    // Remove selection from all items
                                    document.querySelectorAll('.replacement-item').forEach(i => {
                                        i.classList.remove('selected');
                                        i.querySelector('.bx-check').style.display = 'none';
                                    });
                                    
                                    // Add selection to clicked item
                                    this.classList.add('selected');
                                    this.querySelector('.bx-check').style.display = 'block';
                                    
                                    // Update hidden input
                                    const volunteerId = this.getAttribute('data-volunteer-id');
                                    document.getElementById('selected-replacement-id').value = volunteerId;
                                    document.getElementById('assign-replacement-btn').disabled = false;
                                });
                            });
                        } else {
                            document.getElementById('replacement-info-text').textContent = 
                                'No available volunteers found for this shift date.';
                            document.getElementById('replacement-list').innerHTML = `
                                <div style="text-align: center; padding: 20px; color: var(--text-light);">
                                    <i class='bx bx-user-x'></i>
                                    <p>No volunteers available on this date. Try manual assignment.</p>
                                </div>
                            `;
                        }
                    } catch (error) {
                        console.error('Error loading replacements:', error);
                        document.getElementById('replacement-info-text').textContent = 
                            'Error loading available volunteers. Please try again.';
                        document.getElementById('replacement-list').innerHTML = `
                            <div style="text-align: center; padding: 20px; color: var(--danger);">
                                <i class='bx bx-error'></i>
                                <p>Failed to load available volunteers.</p>
                            </div>
                        `;
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