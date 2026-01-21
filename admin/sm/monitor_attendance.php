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
if ($role !== 'ADMIN' && $role !== 'EMPLOYEE') {
    header("Location: ../admin_dashboard.php");
    exit();
}

// Function to get attendance records with filters
function getAttendanceRecords($pdo, $filter_date_from = null, $filter_date_to = null, $filter_volunteer = null, $filter_status = null) {
    $sql = "SELECT 
                al.id as attendance_id,
                al.shift_id,
                al.volunteer_id,
                al.shift_date,
                al.user_id,
                al.check_in,
                al.check_out,
                al.attendance_status,
                al.total_hours,
                al.overtime_hours,
                al.notes,
                al.verified_by,
                al.verified_at,
                al.created_at as attendance_created,
                s.shift_type,
                s.start_time,
                s.end_time,
                s.location as shift_location,
                s.status as shift_status,
                v.first_name as volunteer_first_name,
                v.last_name as volunteer_last_name,
                v.contact_number as volunteer_contact,
                v.email as volunteer_email,
                v.volunteer_status,
                u.id as unit_id,
                u.unit_name,
                u.unit_code,
                uv.first_name as verified_first_name,
                uv.last_name as verified_last_name
            FROM attendance_logs al
            INNER JOIN shifts s ON al.shift_id = s.id
            INNER JOIN volunteers v ON al.volunteer_id = v.id
            LEFT JOIN units u ON s.unit_id = u.id
            LEFT JOIN users uv ON al.verified_by = uv.id
            WHERE 1=1";
    
    $params = [];
    
    if ($filter_date_from) {
        $sql .= " AND DATE(al.shift_date) >= ?";
        $params[] = $filter_date_from;
    }
    
    if ($filter_date_to) {
        $sql .= " AND DATE(al.shift_date) <= ?";
        $params[] = $filter_date_to;
    }
    
    if ($filter_volunteer && $filter_volunteer !== 'all') {
        $sql .= " AND al.volunteer_id = ?";
        $params[] = $filter_volunteer;
    }
    
    if ($filter_status && $filter_status !== 'all') {
        $sql .= " AND al.attendance_status = ?";
        $params[] = $filter_status;
    }
    
    $sql .= " ORDER BY al.shift_date DESC, al.check_in DESC";
    
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Error fetching attendance records: " . $e->getMessage());
        return [];
    }
}

// Function to get attendance statistics
function getAttendanceStatistics($pdo, $filter_date_from = null, $filter_date_to = null) {
    $sql = "SELECT 
                COUNT(*) as total_records,
                SUM(CASE WHEN attendance_status = 'present' THEN 1 ELSE 0 END) as present_count,
                SUM(CASE WHEN attendance_status = 'late' THEN 1 ELSE 0 END) as late_count,
                SUM(CASE WHEN attendance_status = 'absent' THEN 1 ELSE 0 END) as absent_count,
                SUM(CASE WHEN attendance_status = 'excused' THEN 1 ELSE 0 END) as excused_count,
                SUM(CASE WHEN attendance_status = 'on_leave' THEN 1 ELSE 0 END) as on_leave_count,
                COUNT(DISTINCT volunteer_id) as unique_volunteers,
                AVG(total_hours) as avg_hours_per_shift,
                SUM(total_hours) as total_hours_worked,
                SUM(overtime_hours) as total_overtime_hours
            FROM attendance_logs
            WHERE 1=1";
    
    $params = [];
    
    if ($filter_date_from) {
        $sql .= " AND DATE(shift_date) >= ?";
        $params[] = $filter_date_from;
    }
    
    if ($filter_date_to) {
        $sql .= " AND DATE(shift_date) <= ?";
        $params[] = $filter_date_to;
    }
    
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // Calculate percentages
        $total = $result['total_records'] ?? 0;
        if ($total > 0) {
            $result['present_percent'] = ($result['present_count'] / $total) * 100;
            $result['late_percent'] = ($result['late_count'] / $total) * 100;
            $result['absent_percent'] = ($result['absent_count'] / $total) * 100;
            $result['excused_percent'] = ($result['excused_count'] / $total) * 100;
            $result['on_leave_percent'] = ($result['on_leave_count'] / $total) * 100;
        } else {
            $result['present_percent'] = $result['late_percent'] = $result['absent_percent'] = 
            $result['excused_percent'] = $result['on_leave_percent'] = 0;
        }
        
        return $result;
    } catch (PDOException $e) {
        error_log("Error fetching attendance statistics: " . $e->getMessage());
        return [
            'total_records' => 0,
            'present_count' => 0,
            'late_count' => 0,
            'absent_count' => 0,
            'excused_count' => 0,
            'on_leave_count' => 0,
            'unique_volunteers' => 0,
            'avg_hours_per_shift' => 0,
            'total_hours_worked' => 0,
            'total_overtime_hours' => 0,
            'present_percent' => 0,
            'late_percent' => 0,
            'absent_percent' => 0,
            'excused_percent' => 0,
            'on_leave_percent' => 0
        ];
    }
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

// Handle form submissions
$success_message = '';
$error_message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['update_attendance'])) {
        $attendance_id = $_POST['attendance_id'] ?? null;
        $attendance_status = $_POST['attendance_status'] ?? null;
        $notes = $_POST['notes'] ?? null;
        $total_hours = $_POST['total_hours'] ?? null;
        $overtime_hours = $_POST['overtime_hours'] ?? null;
        
        if ($attendance_id && $attendance_status) {
            try {
                $updateSql = "UPDATE attendance_logs 
                             SET attendance_status = ?, 
                                 notes = ?, 
                                 total_hours = ?,
                                 overtime_hours = ?,
                                 verified_by = ?,
                                 verified_at = NOW(),
                                 updated_at = NOW()
                             WHERE id = ?";
                
                $updateStmt = $pdo->prepare($updateSql);
                $updateStmt->execute([
                    $attendance_status,
                    $notes,
                    $total_hours,
                    $overtime_hours,
                    $user_id,
                    $attendance_id
                ]);
                
                $success_message = "Attendance record updated successfully!";
            } catch (PDOException $e) {
                error_log("Error updating attendance: " . $e->getMessage());
                $error_message = "Failed to update attendance record. Please try again.";
            }
        } else {
            $error_message = "Missing required parameters.";
        }
    } elseif (isset($_POST['delete_attendance'])) {
        $attendance_id = $_POST['attendance_id'] ?? null;
        
        if ($attendance_id) {
            try {
                $deleteSql = "DELETE FROM attendance_logs WHERE id = ?";
                $deleteStmt = $pdo->prepare($deleteSql);
                $deleteStmt->execute([$attendance_id]);
                
                $success_message = "Attendance record deleted successfully!";
            } catch (PDOException $e) {
                error_log("Error deleting attendance: " . $e->getMessage());
                $error_message = "Failed to delete attendance record. Please try again.";
            }
        } else {
            $error_message = "Missing attendance ID.";
        }
    }
}

// Get filter values
$filter_date_from = $_GET['date_from'] ?? date('Y-m-d', strtotime('-30 days'));
$filter_date_to = $_GET['date_to'] ?? date('Y-m-d');
$filter_volunteer = $_GET['volunteer'] ?? 'all';
$filter_status = $_GET['status'] ?? 'all';

// Get data
$attendance_records = getAttendanceRecords($pdo, $filter_date_from, $filter_date_to, $filter_volunteer, $filter_status);
$volunteers = getVolunteers($pdo);
$stats = getAttendanceStatistics($pdo, $filter_date_from, $filter_date_to);

$stmt = null;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Monitor Attendance - Fire & Rescue Management</title>
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

        .stat-icon.present {
            background: rgba(16, 185, 129, 0.1);
            color: var(--success);
        }

        .stat-icon.late {
            background: rgba(245, 158, 11, 0.1);
            color: var(--warning);
        }

        .stat-icon.absent {
            background: rgba(220, 38, 38, 0.1);
            color: var(--danger);
        }

        .stat-icon.hours {
            background: rgba(59, 130, 246, 0.1);
            color: var(--info);
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

        .status-present {
            background: rgba(16, 185, 129, 0.1);
            color: var(--success);
            border: 1px solid rgba(16, 185, 129, 0.2);
        }

        .status-late {
            background: rgba(245, 158, 11, 0.1);
            color: var(--warning);
            border: 1px solid rgba(245, 158, 11, 0.2);
        }

        .status-absent {
            background: rgba(220, 38, 38, 0.1);
            color: var(--danger);
            border: 1px solid rgba(220, 38, 38, 0.2);
        }

        .status-excused {
            background: rgba(59, 130, 246, 0.1);
            color: var(--info);
            border: 1px solid rgba(59, 130, 246, 0.2);
        }

        .status-on_leave {
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

        .time-details {
            display: flex;
            flex-direction: column;
            gap: 2px;
        }

        .check-in-time, .check-out-time {
            font-size: 12px;
            color: var(--text-light);
            display: flex;
            align-items: center;
            gap: 5px;
        }

        .hours-display {
            font-weight: 600;
            font-size: 14px;
            margin-top: 4px;
        }

        .hours-total {
            color: var(--info);
        }

        .hours-overtime {
            color: var(--warning);
            font-size: 12px;
            margin-left: 5px;
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

        .attendance-detail-item {
            margin-bottom: 15px;
            padding-bottom: 15px;
            border-bottom: 1px solid var(--border-color);
        }

        .attendance-detail-item:last-child {
            border-bottom: none;
            margin-bottom: 0;
        }

        .attendance-detail-label {
            font-weight: 600;
            color: var(--text-color);
            margin-bottom: 5px;
            font-size: 13px;
        }

        .attendance-detail-value {
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

        .unit-badge {
            display: inline-block;
            padding: 4px 8px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 600;
            margin-top: 4px;
            background: rgba(59, 130, 246, 0.1);
            color: var(--info);
            border: 1px solid rgba(59, 130, 246, 0.2);
        }

        .verified-badge {
            display: inline-block;
            padding: 3px 8px;
            border-radius: 12px;
            font-size: 10px;
            font-weight: 600;
            margin-top: 4px;
            background: rgba(16, 185, 129, 0.1);
            color: var(--success);
            border: 1px solid rgba(16, 185, 129, 0.2);
        }

        .notes-box {
            background: rgba(245, 158, 11, 0.05);
            border-radius: 8px;
            padding: 10px;
            margin-top: 4px;
            border-left: 3px solid var(--warning);
            font-size: 12px;
            color: var(--text-light);
            max-height: 80px;
            overflow-y: auto;
        }

        .export-buttons {
            display: flex;
            gap: 10px;
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
            
            .export-buttons {
                flex-direction: column;
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
        }
    </style>
</head>
<body>
    <!-- Update Attendance Modal -->
    <div class="modal-overlay" id="update-modal">
        <div class="modal">
            <div class="modal-header">
                <h2 class="modal-title">Update Attendance</h2>
                <button class="modal-close" id="update-modal-close">&times;</button>
            </div>
            <form method="POST" id="update-form">
                <div class="modal-body">
                    <input type="hidden" name="attendance_id" id="modal-attendance-id">
                    <input type="hidden" name="update_attendance" value="1">
                    
                    <div class="attendance-detail-item">
                        <div class="attendance-detail-label">Volunteer</div>
                        <div class="attendance-detail-value" id="modal-volunteer-name">Loading...</div>
                    </div>
                    
                    <div class="attendance-detail-item">
                        <div class="attendance-detail-label">Shift Details</div>
                        <div class="attendance-detail-value" id="modal-shift-details">Loading...</div>
                    </div>
                    
                    <div class="attendance-detail-item">
                        <div class="attendance-detail-label">Check-in/Check-out Times</div>
                        <div class="attendance-detail-value" id="modal-times">Loading...</div>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label" for="attendance_status">Attendance Status</label>
                        <select class="form-select" id="attendance_status" name="attendance_status" required>
                            <option value="present">Present</option>
                            <option value="late">Late</option>
                            <option value="absent">Absent</option>
                            <option value="excused">Excused</option>
                            <option value="on_leave">On Leave</option>
                        </select>
                    </div>
                    
                    <div class="form-group" style="margin-top: 15px;">
                        <label class="form-label" for="total_hours">Total Hours</label>
                        <input type="number" class="form-input" id="total_hours" name="total_hours" 
                               step="0.01" min="0" max="24" placeholder="Enter total hours">
                    </div>
                    
                    <div class="form-group" style="margin-top: 15px;">
                        <label class="form-label" for="overtime_hours">Overtime Hours</label>
                        <input type="number" class="form-input" id="overtime_hours" name="overtime_hours" 
                               step="0.01" min="0" max="24" placeholder="Enter overtime hours">
                    </div>
                    
                    <div class="form-group" style="margin-top: 15px;">
                        <label class="form-label" for="notes">Notes</label>
                        <textarea class="form-input" id="notes" name="notes" 
                                  placeholder="Add notes about attendance..." rows="4"></textarea>
                        <small style="color: var(--text-light); font-size: 12px; display: block; margin-top: 5px;">
                            These notes will be saved with the attendance record.
                        </small>
                    </div>
                </div>
                <div class="modal-actions">
                    <button type="button" class="btn btn-secondary" id="close-update-modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Update Attendance</button>
                </div>
            </form>
        </div>
    </div>
    
    <!-- View Details Modal -->
    <div class="modal-overlay" id="details-modal">
        <div class="modal">
            <div class="modal-header">
                <h2 class="modal-title">Attendance Details</h2>
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
                        <a href="../volunteer/review_data.php" class="submenu-item">Review Data</a>
                        <a href="../volunteer/approve_applications.php" class="submenu-item">Approve Applications</a>
                        <a href="../volunteer/assign_volunteers.php" class="submenu-item">Assign Volunteers</a>
                        <a href="../volunteer/view_availability.php" class="submenu-item">View Availability</a>
                        <a href="../volunteer/remove_volunteers.php" class="submenu-item">Remove Volunteers</a>
                        <a href="../volunteer/toggle_volunteer_registration.php" class="submenu-item">Toggle Volunteer Registration Access</a>
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
                        <a href="confirm_availability.php" class="submenu-item">Confirm Availability</a>
                        <a href="request_change.php" class="submenu-item">Request Change</a>
                        <a href="monitor_attendance.php" class="submenu-item active">Monitor Attendance</a>
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
                            <input type="text" placeholder="Search attendance..." class="search-input" id="search-input">
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
                        <h1 class="dashboard-title">Monitor Attendance</h1>
                        <p class="dashboard-subtitle">Track and manage volunteer attendance records</p>
                    </div>
                </div>
                
                <!-- Content Container -->
                <div class="content-container">
                    <!-- Statistics Cards -->
                    <div class="stats-container">
                        <div class="stat-card">
                            <div class="stat-icon present">
                                <i class='bx bx-check-circle'></i>
                            </div>
                            <div class="stat-value"><?php echo $stats['present_count']; ?></div>
                            <div class="stat-label">Present</div>
                            <div class="stat-percentage"><?php echo number_format($stats['present_percent'], 1); ?>%</div>
                        </div>
                        
                        <div class="stat-card">
                            <div class="stat-icon late">
                                <i class='bx bx-time-five'></i>
                            </div>
                            <div class="stat-value"><?php echo $stats['late_count']; ?></div>
                            <div class="stat-label">Late Arrivals</div>
                            <div class="stat-percentage"><?php echo number_format($stats['late_percent'], 1); ?>%</div>
                        </div>
                        
                        <div class="stat-card">
                            <div class="stat-icon absent">
                                <i class='bx bx-x-circle'></i>
                            </div>
                            <div class="stat-value"><?php echo $stats['absent_count']; ?></div>
                            <div class="stat-label">Absent</div>
                            <div class="stat-percentage"><?php echo number_format($stats['absent_percent'], 1); ?>%</div>
                        </div>
                        
                        <div class="stat-card">
                            <div class="stat-icon hours">
                                <i class='bx bx-time'></i>
                            </div>
                            <div class="stat-value"><?php echo number_format($stats['total_hours_worked'], 1); ?></div>
                            <div class="stat-label">Total Hours Worked</div>
                            <div class="stat-percentage">
                                <?php echo $stats['unique_volunteers']; ?> volunteers
                            </div>
                        </div>
                    </div>
                    
                    <!-- Filters -->
                    <div class="filters-container">
                        <h3 class="filters-title">
                            <i class='bx bx-filter-alt'></i>
                            Filter Attendance Records
                        </h3>
                        
                        <form method="GET" class="filters-form">
                            <div class="form-group">
                                <label class="form-label" for="date_from">Date From</label>
                                <input type="date" class="form-input" id="date_from" name="date_from" 
                                       value="<?php echo htmlspecialchars($filter_date_from); ?>">
                            </div>
                            
                            <div class="form-group">
                                <label class="form-label" for="date_to">Date To</label>
                                <input type="date" class="form-input" id="date_to" name="date_to" 
                                       value="<?php echo htmlspecialchars($filter_date_to); ?>">
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
                                <label class="form-label" for="status">Status</label>
                                <select class="form-select" id="status" name="status">
                                    <option value="all">All Statuses</option>
                                    <option value="present" <?php echo $filter_status === 'present' ? 'selected' : ''; ?>>Present</option>
                                    <option value="late" <?php echo $filter_status === 'late' ? 'selected' : ''; ?>>Late</option>
                                    <option value="absent" <?php echo $filter_status === 'absent' ? 'selected' : ''; ?>>Absent</option>
                                    <option value="excused" <?php echo $filter_status === 'excused' ? 'selected' : ''; ?>>Excused</option>
                                    <option value="on_leave" <?php echo $filter_status === 'on_leave' ? 'selected' : ''; ?>>On Leave</option>
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
                    
                    <!-- Export Section -->
                    <div class="table-container" style="margin-bottom: 20px;">
                        <div class="table-header">
                            <h3 class="table-title">
                                <i class='bx bx-export'></i>
                                Export Options
                            </h3>
                        </div>
                        <div style="padding: 20px;">
                            <div class="export-buttons">
                                <a href="export_attendance.php?type=csv&date_from=<?php echo urlencode($filter_date_from); ?>&date_to=<?php echo urlencode($filter_date_to); ?>&volunteer=<?php echo urlencode($filter_volunteer); ?>&status=<?php echo urlencode($filter_status); ?>" 
                                   class="btn btn-success">
                                    <i class='bx bx-download'></i>
                                    Export as CSV
                                </a>
                                <button class="btn btn-info" onclick="window.print()">
                                    <i class='bx bx-printer'></i>
                                    Print Report
                                </button>
                                <button class="btn btn-secondary" onclick="resetFilters()">
                                    <i class='bx bx-reset'></i>
                                    Reset Filters
                                </button>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Attendance Table -->
                    <div class="table-container">
                        <div class="table-header">
                            <h3 class="table-title">
                                <i class='bx bx-calendar-check'></i>
                                Attendance Records
                                <span style="font-size: 14px; font-weight: normal; color: var(--text-light); margin-left: 10px;">
                                    <?php echo count($attendance_records); ?> records found
                                </span>
                            </h3>
                        </div>
                        
                        <?php if (count($attendance_records) > 0): ?>
                            <table class="table">
                                <thead>
                                    <tr>
                                        <th>Volunteer</th>
                                        <th>Shift Details</th>
                                        <th>Attendance Times</th>
                                        <th>Hours</th>
                                        <th>Status</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($attendance_records as $record): 
                                        $shift_date = date('M j, Y', strtotime($record['shift_date']));
                                        $check_in = $record['check_in'] ? date('g:i A', strtotime($record['check_in'])) : 'Not checked in';
                                        $check_out = $record['check_out'] ? date('g:i A', strtotime($record['check_out'])) : 'Not checked out';
                                        $total_hours = $record['total_hours'] ? number_format($record['total_hours'], 2) : '0.00';
                                        $overtime_hours = $record['overtime_hours'] ? number_format($record['overtime_hours'], 2) : '0.00';
                                        $verified_by = $record['verified_first_name'] ? 
                                            $record['verified_first_name'] . ' ' . $record['verified_last_name'] : null;
                                    ?>
                                    <tr>
                                        <td>
                                            <div class="volunteer-info">
                                                <div class="volunteer-avatar">
                                                    <?php echo strtoupper(substr($record['volunteer_first_name'], 0, 1)); ?>
                                                </div>
                                                <div class="volunteer-details">
                                                    <div class="volunteer-name">
                                                        <?php echo htmlspecialchars($record['volunteer_first_name'] . ' ' . $record['volunteer_last_name']); ?>
                                                    </div>
                                                    <div class="volunteer-contact">
                                                        <i class='bx bx-phone'></i> <?php echo htmlspecialchars($record['volunteer_contact']); ?>
                                                    </div>
                                                    <?php if ($record['unit_name']): ?>
                                                        <div class="unit-badge">
                                                            <i class='bx bx-building-house'></i> <?php echo htmlspecialchars($record['unit_name']); ?>
                                                        </div>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        </td>
                                        <td>
                                            <div class="shift-details">
                                                <div class="shift-date">
                                                    <?php echo $shift_date; ?>
                                                </div>
                                                <div class="shift-time">
                                                    Scheduled: <?php echo date('g:i A', strtotime($record['start_time'])) . ' - ' . 
                                                                   date('g:i A', strtotime($record['end_time'])); ?>
                                                </div>
                                                <?php if ($record['shift_location']): ?>
                                                    <div style="font-size: 12px; color: var(--text-light); margin-top: 4px;">
                                                        <i class='bx bx-map'></i> <?php echo htmlspecialchars($record['shift_location']); ?>
                                                    </div>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                        <td>
                                            <div class="time-details">
                                                <div class="check-in-time">
                                                    <i class='bx bx-log-in' style="color: var(--success);"></i>
                                                    <span>In: <?php echo $check_in; ?></span>
                                                </div>
                                                <div class="check-out-time">
                                                    <i class='bx bx-log-out' style="color: var(--danger);"></i>
                                                    <span>Out: <?php echo $check_out; ?></span>
                                                </div>
                                                <?php if ($record['verified_at']): ?>
                                                    <div class="verified-badge">
                                                        <i class='bx bx-check-shield'></i>
                                                        Verified: <?php echo date('M j', strtotime($record['verified_at'])); ?>
                                                    </div>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                        <td>
                                            <div class="hours-display">
                                                <span class="hours-total"><?php echo $total_hours; ?> hrs</span>
                                                <?php if ($overtime_hours > 0): ?>
                                                    <span class="hours-overtime">(+<?php echo $overtime_hours; ?> OT)</span>
                                                <?php endif; ?>
                                            </div>
                                            <?php if ($verified_by): ?>
                                                <div style="font-size: 11px; color: var(--text-light); margin-top: 4px;">
                                                    By: <?php echo htmlspecialchars($verified_by); ?>
                                                </div>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div class="status-badge status-<?php echo $record['attendance_status']; ?>">
                                                <?php echo ucfirst(str_replace('_', ' ', $record['attendance_status'])); ?>
                                            </div>
                                            <?php if ($record['notes']): ?>
                                                <div class="notes-box">
                                                    <?php echo htmlspecialchars($record['notes']); ?>
                                                </div>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div class="action-buttons">
                                                <button type="button" class="btn btn-primary update-attendance-btn" 
                                                        data-attendance-id="<?php echo $record['attendance_id']; ?>"
                                                        data-volunteer-name="<?php echo htmlspecialchars($record['volunteer_first_name'] . ' ' . $record['volunteer_last_name']); ?>"
                                                        data-shift-details="<?php echo $shift_date . ' - ' . 
                                                                              date('g:i A', strtotime($record['start_time'])) . ' to ' . 
                                                                              date('g:i A', strtotime($record['end_time'])); ?>"
                                                        data-check-in="<?php echo $check_in; ?>"
                                                        data-check-out="<?php echo $check_out; ?>"
                                                        data-attendance-status="<?php echo $record['attendance_status']; ?>"
                                                        data-total-hours="<?php echo $total_hours; ?>"
                                                        data-overtime-hours="<?php echo $overtime_hours; ?>"
                                                        data-notes="<?php echo htmlspecialchars($record['notes'] ?? ''); ?>">
                                                    <i class='bx bx-edit'></i> Edit
                                                </button>
                                                
                                                <button type="button" class="btn btn-info view-attendance-btn"
                                                        data-attendance-id="<?php echo $record['attendance_id']; ?>"
                                                        data-volunteer-name="<?php echo htmlspecialchars($record['volunteer_first_name'] . ' ' . $record['volunteer_last_name']); ?>"
                                                        data-volunteer-contact="<?php echo htmlspecialchars($record['volunteer_contact']); ?>"
                                                        data-volunteer-email="<?php echo htmlspecialchars($record['volunteer_email']); ?>"
                                                        data-volunteer-status="<?php echo $record['volunteer_status']; ?>"
                                                        data-shift-date="<?php echo $shift_date; ?>"
                                                        data-shift-time="<?php echo date('g:i A', strtotime($record['start_time'])) . ' to ' . 
                                                                           date('g:i A', strtotime($record['end_time'])); ?>"
                                                        data-shift-location="<?php echo htmlspecialchars($record['shift_location'] ?? 'Not specified'); ?>"
                                                        data-shift-status="<?php echo $record['shift_status']; ?>"
                                                        data-unit-name="<?php echo htmlspecialchars($record['unit_name'] ?? 'Not assigned'); ?>"
                                                        data-check-in="<?php echo $check_in; ?>"
                                                        data-check-out="<?php echo $check_out; ?>"
                                                        data-attendance-status="<?php echo ucfirst(str_replace('_', ' ', $record['attendance_status'])); ?>"
                                                        data-total-hours="<?php echo $total_hours; ?>"
                                                        data-overtime-hours="<?php echo $overtime_hours; ?>"
                                                        data-notes="<?php echo htmlspecialchars($record['notes'] ?? ''); ?>"
                                                        data-verified-by="<?php echo htmlspecialchars($verified_by ?? 'Not verified'); ?>"
                                                        data-verified-at="<?php echo $record['verified_at'] ? date('M j, Y g:i A', strtotime($record['verified_at'])) : 'Not verified'; ?>"
                                                        data-attendance-created="<?php echo date('M j, Y', strtotime($record['attendance_created'])); ?>">
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
                                <i class='bx bx-calendar-x'></i>
                                <h3>No Attendance Records Found</h3>
                                <p>No attendance records match your current filters. Try adjusting your search criteria or check if volunteers have logged their attendance.</p>
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
            setupUpdateModal();
            setupDetailsModal();
            
            // Setup search functionality
            setupSearch();
            
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
        
        function setupUpdateModal() {
            const updateModal = document.getElementById('update-modal');
            const updateModalClose = document.getElementById('update-modal-close');
            const closeUpdateModal = document.getElementById('close-update-modal');
            const updateButtons = document.querySelectorAll('.update-attendance-btn');
            
            updateModalClose.addEventListener('click', () => updateModal.classList.remove('active'));
            closeUpdateModal.addEventListener('click', () => updateModal.classList.remove('active'));
            
            updateModal.addEventListener('click', function(e) {
                if (e.target === this) {
                    updateModal.classList.remove('active');
                }
            });
            
            updateButtons.forEach(button => {
                button.addEventListener('click', function() {
                    const attendanceId = this.getAttribute('data-attendance-id');
                    const volunteerName = this.getAttribute('data-volunteer-name');
                    const shiftDetails = this.getAttribute('data-shift-details');
                    const checkIn = this.getAttribute('data-check-in');
                    const checkOut = this.getAttribute('data-check-out');
                    const attendanceStatus = this.getAttribute('data-attendance-status');
                    const totalHours = this.getAttribute('data-total-hours');
                    const overtimeHours = this.getAttribute('data-overtime-hours');
                    const notes = this.getAttribute('data-notes');
                    
                    document.getElementById('modal-attendance-id').value = attendanceId;
                    document.getElementById('modal-volunteer-name').textContent = volunteerName;
                    document.getElementById('modal-shift-details').textContent = shiftDetails;
                    document.getElementById('modal-times').innerHTML = `
                        <strong>Check-in:</strong> ${checkIn}<br>
                        <strong>Check-out:</strong> ${checkOut}
                    `;
                    
                    // Set form values
                    document.getElementById('attendance_status').value = attendanceStatus;
                    document.getElementById('total_hours').value = totalHours;
                    document.getElementById('overtime_hours').value = overtimeHours;
                    document.getElementById('notes').value = notes;
                    
                    updateModal.classList.add('active');
                });
            });
        }
        
        function setupDetailsModal() {
            const detailsModal = document.getElementById('details-modal');
            const detailsModalClose = document.getElementById('details-modal-close');
            const closeDetailsModal = document.getElementById('close-details-modal');
            const viewButtons = document.querySelectorAll('.view-attendance-btn');
            
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
                        volunteerStatus: this.getAttribute('data-volunteer-status'),
                        shiftDate: this.getAttribute('data-shift-date'),
                        shiftTime: this.getAttribute('data-shift-time'),
                        shiftLocation: this.getAttribute('data-shift-location'),
                        shiftStatus: this.getAttribute('data-shift-status'),
                        unitName: this.getAttribute('data-unit-name'),
                        checkIn: this.getAttribute('data-check-in'),
                        checkOut: this.getAttribute('data-check-out'),
                        attendanceStatus: this.getAttribute('data-attendance-status'),
                        totalHours: this.getAttribute('data-total-hours'),
                        overtimeHours: this.getAttribute('data-overtime-hours'),
                        notes: this.getAttribute('data-notes'),
                        verifiedBy: this.getAttribute('data-verified-by'),
                        verifiedAt: this.getAttribute('data-verified-at'),
                        attendanceCreated: this.getAttribute('data-attendance-created')
                    };
                    
                    let detailsHtml = `
                        <div class="attendance-detail-item">
                            <div class="attendance-detail-label">Volunteer Information</div>
                            <div class="attendance-detail-value">
                                <strong>Name:</strong> ${details.volunteerName}<br>
                                <strong>Contact:</strong> ${details.volunteerContact}<br>
                                <strong>Email:</strong> ${details.volunteerEmail}<br>
                                <strong>Status:</strong> ${details.volunteerStatus}
                            </div>
                        </div>
                        
                        <div class="attendance-detail-item">
                            <div class="attendance-detail-label">Shift Information</div>
                            <div class="attendance-detail-value">
                                <strong>Date:</strong> ${details.shiftDate}<br>
                                <strong>Time:</strong> ${details.shiftTime}<br>
                                <strong>Location:</strong> ${details.shiftLocation}<br>
                                <strong>Status:</strong> ${details.shiftStatus}<br>
                                <strong>Unit:</strong> ${details.unitName}
                            </div>
                        </div>
                        
                        <div class="attendance-detail-item">
                            <div class="attendance-detail-label">Attendance Information</div>
                            <div class="attendance-detail-value">
                                <strong>Check-in:</strong> ${details.checkIn}<br>
                                <strong>Check-out:</strong> ${details.checkOut}<br>
                                <strong>Status:</strong> <span class="status-badge status-${details.attendanceStatus.toLowerCase()}">${details.attendanceStatus}</span><br>
                                <strong>Total Hours:</strong> ${details.totalHours} hrs<br>
                                <strong>Overtime Hours:</strong> ${details.overtimeHours} hrs<br>
                                <strong>Record Created:</strong> ${details.attendanceCreated}
                            </div>
                        </div>
                    `;
                    
                    if (details.notes) {
                        detailsHtml += `
                            <div class="attendance-detail-item">
                                <div class="attendance-detail-label">Notes</div>
                                <div class="attendance-detail-value">${details.notes}</div>
                            </div>
                        `;
                    }
                    
                    if (details.verifiedBy !== 'Not verified') {
                        detailsHtml += `
                            <div class="attendance-detail-item">
                                <div class="attendance-detail-label">Verification</div>
                                <div class="attendance-detail-value">
                                    <strong>Verified By:</strong> ${details.verifiedBy}<br>
                                    <strong>Verified At:</strong> ${details.verifiedAt}
                                </div>
                            </div>
                        `;
                    }
                    
                    document.getElementById('details-content').innerHTML = detailsHtml;
                    detailsModal.classList.add('active');
                });
            });
        }
        
        function setupSearch() {
            const searchInput = document.getElementById('search-input');
            if (!searchInput) return;
            
            searchInput.addEventListener('input', function() {
                const searchTerm = this.value.toLowerCase();
                const rows = document.querySelectorAll('.table tbody tr');
                
                rows.forEach(row => {
                    const text = row.textContent.toLowerCase();
                    row.style.display = text.includes(searchTerm) ? '' : 'none';
                });
            });
        }
        
        function resetFilters() {
            window.location.href = 'monitor_attendance.php';
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