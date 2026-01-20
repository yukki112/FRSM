<?php
session_start();
require_once '../../config/db_connection.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: ../../login.php");
    exit();
}

$user_id = $_SESSION['user_id'];

// Get user information
$query = "SELECT first_name, middle_name, last_name, role, email, avatar FROM users WHERE id = ?";
$stmt = $pdo->prepare($query);
$stmt->execute([$user_id]);
$user = $stmt->fetch();

if (!$user) {
    header("Location: ../../login.php");
    exit();
}

$first_name = htmlspecialchars($user['first_name']);
$middle_name = htmlspecialchars($user['middle_name']);
$last_name = htmlspecialchars($user['last_name']);
$role = htmlspecialchars($user['role']);
$email = htmlspecialchars($user['email']);
$avatar = htmlspecialchars($user['avatar']);

$full_name = $first_name;
if (!empty($middle_name)) {
    $full_name .= " " . $middle_name;
}
$full_name .= " " . $last_name;

// Check if user is a volunteer (USER role)
if ($role !== 'USER') {
    header("Location: ../dashboard.php");
    exit();
}

// Get volunteer ID from volunteers table
$volunteer_query = "SELECT id, first_name, last_name, contact_number FROM volunteers WHERE user_id = ?";
$volunteer_stmt = $pdo->prepare($volunteer_query);
$volunteer_stmt->execute([$user_id]);
$volunteer = $volunteer_stmt->fetch();

if (!$volunteer) {
    // User is not registered as a volunteer
    header("Location: ../dashboard.php");
    exit();
}

$volunteer_id = $volunteer['id'];
$volunteer_name = htmlspecialchars($volunteer['first_name'] . ' ' . $volunteer['last_name']);
$volunteer_contact = htmlspecialchars($volunteer['contact_number']);

// Handle filters
$year_filter = $_GET['year'] ?? date('Y');
$month_filter = $_GET['month'] ?? date('m');
$status_filter = $_GET['status'] ?? 'all';

// Calculate date range for the selected month
$start_date = date('Y-m-01', strtotime($year_filter . '-' . $month_filter . '-01'));
$end_date = date('Y-m-t', strtotime($start_date));

// Build query for attendance logs
$base_query = "
    SELECT 
        al.*,
        s.shift_date,
        s.shift_type,
        s.start_time,
        s.end_time,
        s.location as shift_location,
        s.attendance_status as shift_attendance_status,
        u.unit_name,
        u.unit_code,
        CONCAT(v.first_name, ' ', v.last_name) as volunteer_full_name,
        CONCAT(u2.first_name, ' ', u2.last_name) as verified_by_name
    FROM attendance_logs al
    LEFT JOIN shifts s ON al.shift_id = s.id
    LEFT JOIN units u ON s.unit_id = u.id
    LEFT JOIN volunteers v ON al.volunteer_id = v.id
    LEFT JOIN users u2 ON al.verified_by = u2.id
    WHERE al.volunteer_id = ?
";

$params = [$volunteer_id];
$conditions = [];

// Apply date filters
$conditions[] = "al.shift_date BETWEEN ? AND ?";
$params[] = $start_date;
$params[] = $end_date;

// Apply status filter
if ($status_filter !== 'all') {
    $conditions[] = "al.attendance_status = ?";
    $params[] = $status_filter;
}

if (count($conditions) > 0) {
    $base_query .= " AND " . implode(" AND ", $conditions);
}

$base_query .= " ORDER BY al.shift_date DESC, al.check_in DESC";

// Get attendance logs
$attendance_stmt = $pdo->prepare($base_query);
$attendance_stmt->execute($params);
$attendance_logs = $attendance_stmt->fetchAll();

// Calculate statistics
$stats_query = "
    SELECT 
        COUNT(*) as total_shifts,
        SUM(CASE WHEN attendance_status = 'present' THEN 1 ELSE 0 END) as present_count,
        SUM(CASE WHEN attendance_status = 'late' THEN 1 ELSE 0 END) as late_count,
        SUM(CASE WHEN attendance_status = 'absent' THEN 1 ELSE 0 END) as absent_count,
        SUM(CASE WHEN attendance_status = 'excused' THEN 1 ELSE 0 END) as excused_count,
        SUM(CASE WHEN attendance_status = 'on_leave' THEN 1 ELSE 0 END) as on_leave_count,
        COALESCE(SUM(total_hours), 0) as total_hours,
        COALESCE(SUM(overtime_hours), 0) as total_overtime
    FROM attendance_logs
    WHERE volunteer_id = ? AND shift_date BETWEEN ? AND ?
";

$stats_stmt = $pdo->prepare($stats_query);
$stats_stmt->execute([$volunteer_id, $start_date, $end_date]);
$attendance_stats = $stats_stmt->fetch();

// Get all years with attendance records for filter dropdown
$years_query = "
    SELECT DISTINCT YEAR(shift_date) as year 
    FROM attendance_logs 
    WHERE volunteer_id = ? 
    ORDER BY year DESC
";
$years_stmt = $pdo->prepare($years_query);
$years_stmt->execute([$volunteer_id]);
$available_years = $years_stmt->fetchAll();

// Get recent check-in (if any today)
$today = date('Y-m-d');
$today_checkin_query = "
    SELECT * FROM attendance_logs 
    WHERE volunteer_id = ? AND shift_date = ? 
    ORDER BY check_in DESC LIMIT 1
";
$today_checkin_stmt = $pdo->prepare($today_checkin_query);
$today_checkin_stmt->execute([$volunteer_id, $today]);
$today_checkin = $today_checkin_stmt->fetch();

// Get upcoming shifts (next 7 days)
$upcoming_query = "
    SELECT s.*, u.unit_name, u.unit_code 
    FROM shifts s
    LEFT JOIN units u ON s.unit_id = u.id
    WHERE s.volunteer_id = ? 
    AND s.shift_date >= CURDATE() 
    AND s.shift_date <= DATE_ADD(CURDATE(), INTERVAL 7 DAY)
    AND s.status != 'cancelled'
    ORDER BY s.shift_date, s.start_time
    LIMIT 5
";
$upcoming_stmt = $pdo->prepare($upcoming_query);
$upcoming_stmt->execute([$volunteer_id]);
$upcoming_shifts = $upcoming_stmt->fetchAll();

// Close statements
$stmt = null;
$volunteer_stmt = null;
$attendance_stmt = null;
$stats_stmt = null;
$years_stmt = null;
$today_checkin_stmt = null;
$upcoming_stmt = null;
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Attendance Logs - Fire & Rescue Services Management</title>
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

        .section-container {
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: 16px;
            padding: 30px;
            margin-bottom: 30px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
        }

        .section-title {
            font-size: 20px;
            font-weight: 700;
            color: var(--text-color);
            margin-bottom: 20px;
            padding-bottom: 12px;
            border-bottom: 2px solid var(--border-color);
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .section-title i {
            color: var(--primary-color);
        }

        .stats-container {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 15px;
            margin-bottom: 25px;
        }

        .stat-card {
            background: var(--background-color);
            border: 1px solid var(--border-color);
            border-radius: 12px;
            padding: 20px;
            text-align: center;
            transition: all 0.3s ease;
        }

        .stat-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
        }

        .stat-value {
            font-size: 28px;
            font-weight: 800;
            margin-bottom: 5px;
        }

        .stat-label {
            font-size: 12px;
            color: var(--text-light);
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }

        .filter-container {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-bottom: 20px;
            padding: 20px;
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: 12px;
        }

        .filter-group {
            display: flex;
            flex-direction: column;
        }

        .filter-label {
            font-size: 12px;
            color: var(--text-light);
            margin-bottom: 6px;
            font-weight: 600;
        }

        .filter-select, .filter-input {
            padding: 10px 12px;
            border: 1px solid var(--border-color);
            border-radius: 8px;
            background: var(--background-color);
            color: var(--text-color);
            font-size: 14px;
        }

        .filter-select:focus, .filter-input:focus {
            outline: none;
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(220, 38, 38, 0.1);
        }

        .filter-actions {
            display: flex;
            align-items: flex-end;
            gap: 10px;
        }

        .btn {
            padding: 10px 20px;
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

        .attendance-card {
            background: var(--background-color);
            border: 1px solid var(--border-color);
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 20px;
            transition: all 0.3s ease;
        }

        .attendance-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 16px rgba(0, 0, 0, 0.1);
        }

        .attendance-card.present {
            border-left: 4px solid var(--success);
        }

        .attendance-card.late {
            border-left: 4px solid var(--warning);
        }

        .attendance-card.absent {
            border-left: 4px solid var(--danger);
        }

        .attendance-card.excused {
            border-left: 4px solid var(--info);
        }

        .attendance-card.on_leave {
            border-left: 4px solid #8b5cf6;
        }

        .attendance-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
        }

        .attendance-title {
            font-size: 18px;
            font-weight: 700;
            color: var(--text-color);
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .attendance-title i {
            color: var(--primary-color);
        }

        .attendance-status {
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            display: inline-block;
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
            color: #8b5cf6;
            border: 1px solid rgba(139, 92, 246, 0.2);
        }

        .attendance-details {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-bottom: 20px;
        }

        .detail-item {
            display: flex;
            flex-direction: column;
        }

        .detail-label {
            font-size: 12px;
            color: var(--text-light);
            margin-bottom: 4px;
        }

        .detail-value {
            font-weight: 500;
            color: var(--text-color);
            font-size: 14px;
        }

        .time-section {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 15px;
            margin-top: 15px;
            padding: 15px;
            background: rgba(59, 130, 246, 0.05);
            border-radius: 8px;
        }

        .time-item {
            display: flex;
            flex-direction: column;
        }

        .time-label {
            font-size: 11px;
            color: var(--text-light);
            margin-bottom: 2px;
        }

        .time-value {
            font-weight: 600;
            color: var(--text-color);
            font-size: 14px;
        }

        .hours-badge {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            background: rgba(16, 185, 129, 0.1);
            color: var(--success);
            border: 1px solid rgba(16, 185, 129, 0.2);
        }

        .hours-badge.overtime {
            background: rgba(245, 158, 11, 0.1);
            color: var(--warning);
            border: 1px solid rgba(245, 158, 11, 0.2);
        }

        .notes-section {
            margin-top: 15px;
            padding: 15px;
            background: rgba(245, 158, 11, 0.05);
            border-radius: 8px;
            border-left: 3px solid var(--warning);
        }

        .notes-title {
            font-size: 14px;
            font-weight: 600;
            color: var(--warning);
            margin-bottom: 8px;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .empty-state {
            text-align: center;
            padding: 40px 20px;
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

        .date-badge {
            background: rgba(245, 158, 11, 0.1);
            color: var(--warning);
            padding: 2px 8px;
            border-radius: 12px;
            font-size: 11px;
            margin-left: 8px;
        }

        .today-badge {
            background: rgba(16, 185, 129, 0.1);
            color: var(--success);
            padding: 2px 8px;
            border-radius: 12px;
            font-size: 11px;
            margin-left: 8px;
        }

        .calendar-container {
            margin-top: 20px;
        }

        .calendar-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }

        .calendar-grid {
            display: grid;
            grid-template-columns: repeat(7, 1fr);
            gap: 5px;
        }

        .calendar-day-header {
            text-align: center;
            padding: 10px;
            font-weight: 600;
            color: var(--text-color);
            font-size: 12px;
        }

        .calendar-day {
            aspect-ratio: 1;
            border: 1px solid var(--border-color);
            border-radius: 8px;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 5px;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .calendar-day:hover {
            background: var(--gray-100);
        }

        .calendar-day.today {
            background: rgba(59, 130, 246, 0.1);
            border-color: var(--info);
        }

        .calendar-day.has-attendance {
            background: rgba(16, 185, 129, 0.1);
            border-color: rgba(16, 185, 129, 0.3);
        }

        .calendar-day.other-month {
            color: var(--text-light);
            opacity: 0.5;
        }

        .day-number {
            font-size: 14px;
            font-weight: 600;
        }

        .attendance-indicator {
            width: 6px;
            height: 6px;
            border-radius: 50%;
            margin-top: 2px;
        }

        .attendance-indicator.present {
            background: var(--success);
        }

        .attendance-indicator.late {
            background: var(--warning);
        }

        .attendance-indicator.absent {
            background: var(--danger);
        }

        .attendance-indicator.excused {
            background: var(--info);
        }

        .attendance-indicator.on_leave {
            background: #8b5cf6;
        }

        .upcoming-shifts {
            margin-top: 20px;
        }

        .shift-item {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 12px;
            border: 1px solid var(--border-color);
            border-radius: 8px;
            margin-bottom: 10px;
            transition: all 0.3s ease;
        }

        .shift-item:hover {
            background: var(--gray-100);
        }

        .shift-date {
            font-weight: 600;
            color: var(--text-color);
        }

        .shift-time {
            color: var(--text-light);
            font-size: 12px;
        }

        .shift-unit {
            font-size: 11px;
            color: var(--info);
        }

        .checkin-container {
            background: linear-gradient(135deg, var(--success), #34d399);
            color: white;
            padding: 20px;
            border-radius: 12px;
            margin-bottom: 20px;
        }

        .checkin-title {
            font-size: 18px;
            font-weight: 700;
            margin-bottom: 10px;
        }

        .checkin-time {
            font-size: 24px;
            font-weight: 800;
            margin-bottom: 15px;
        }

        .checkin-status {
            display: inline-block;
            padding: 6px 12px;
            background: rgba(255, 255, 255, 0.2);
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
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
            
            .section-container {
                padding: 20px;
            }
            
            .attendance-details {
                grid-template-columns: 1fr;
            }
            
            .stats-container {
                grid-template-columns: repeat(2, 1fr);
            }
            
            .filter-container {
                grid-template-columns: 1fr;
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
            
            .section-container {
                padding: 15px;
            }
            
            .stats-container {
                grid-template-columns: 1fr;
            }
            
            .attendance-header {
                flex-direction: column;
                align-items: flex-start;
                gap: 10px;
            }
            
            .filter-actions {
                flex-direction: column;
            }
        }

        /* User profile dropdown styles */
        .user-profile-dropdown {
            position: absolute;
            top: 100%;
            right: 0;
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: 8px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
            min-width: 200px;
            z-index: 1000;
            display: none;
            margin-top: 10px;
        }
        
        .user-profile-dropdown.show {
            display: block;
        }
        
        .dropdown-item {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 12px 16px;
            color: var(--text-color);
            text-decoration: none;
            transition: all 0.3s ease;
        }
        
        .dropdown-item:hover {
            background: var(--gray-100);
        }
        
        .dropdown-item i {
            font-size: 18px;
        }
        
        .dropdown-divider {
            height: 1px;
            background: var(--border-color);
            margin: 4px 0;
        }
    </style>
</head>
<body>
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
                    <a href="../dashboard.php" class="menu-item" id="dashboard-menu">
                        <div class="icon-box icon-bg-red">
                            <i class='bx bxs-dashboard icon-red'></i>
                        </div>
                        <span class="font-medium">Dashboard</span>
                    </a>
                    
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
                        <a href="#" class="submenu-item">Active Incidents</a>
                        <a href="#" class="submenu-item">Incident Reports</a>
                        <a href="#" class="submenu-item">Response History</a>
                    </div>
                    
                    <div class="menu-item" onclick="toggleSubmenu('volunteer')">
                        <div class="icon-box icon-bg-blue">
                            <i class='bx bxs-user-detail icon-blue'></i>
                        </div>
                        <span class="font-medium">Volunteer Roster</span>
                        <svg class="dropdown-arrow menu-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                        </svg>
                    </div>
                    <div id="volunteer" class="submenu">
                        <a href="#" class="submenu-item">Volunteer List</a>
                        <a href="#" class="submenu-item">Roles & Skills</a>
                        <a href="#" class="submenu-item">Availability</a>
                    </div>
                    
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
                        <a href="#" class="submenu-item">Equipment List</a>
                        <a href="#" class="submenu-item">Stock Levels</a>
                        <a href="#" class="submenu-item">Maintenance Logs</a>
                    </div>
                    
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
                        <a href="view_shifts.php" class="submenu-item">Shift Calendar</a>
                        <a href="confirm_availability.php" class="submenu-item">Confirm Availability</a>
                        <a href="duty_assignments.php" class="submenu-item">Duty Assignments</a>
                        <a href="attendance_logs.php" class="submenu-item active">Attendance Logs</a>
                    </div>
                    
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
                         <a href="../tc/register_training.php" class="submenu-item">Register for Training</a>
            <a href="../tc/training_records.php" class="submenu-item">Training Records</a>
            <a href="../tc/certification_status.php" class="submenu-item">Certification Status</a>
            <a href="../tc/track_progress.php" class="submenu-item">Track Progress</a>
                    </div>
                    
                    <div class="menu-item" onclick="toggleSubmenu('inspection')">
                        <div class="icon-box icon-bg-yellow">
                            <i class='bx bxs-check-shield icon-yellow'></i>
                        </div>
                        <span class="font-medium">Establishment Inspections</span>
                        <svg class="dropdown-arrow menu-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                        </svg>
                    </div>
                    <div id="inspection" class="submenu">
                        <a href="#" class="submenu-item">Inspection Scheduler</a>
                        <a href="#" class="submenu-item">Inspection Results</a>
                        <a href="#" class="submenu-item">Violation Notices</a>
                    </div>
                    
                    <div class="menu-item" onclick="toggleSubmenu('postincident')">
                        <div class="icon-box icon-bg-pink">
                            <i class='bx bxs-file-doc icon-pink'></i>
                        </div>
                        <span class="font-medium">Post-Incident Analytics</span>
                        <svg class="dropdown-arrow menu-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                        </svg>
                    </div>
                    <div id="postincident" class="submenu">
                        <a href="#" class="submenu-item">Analytics Dashboard</a>
                        <a href="#" class="submenu-item">Incident Trends</a>
                        <a href="#" class="submenu-item">Lessons Learned</a>
                    </div>
                </div>
                
                <p class="menu-title" style="margin-top: 32px;">GENERAL</p>
                
                <div class="menu-items">
                    <a href="../settings.php" class="menu-item">
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
                            <input type="text" placeholder="Search attendance logs..." class="search-input" id="search-input">
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
                                <img src="../../profile/uploads/avatars/<?php echo $avatar; ?>" alt="User" class="user-avatar">
                            <?php else: ?>
                                <div class="user-avatar" style="background: linear-gradient(135deg, var(--primary-color), var(--secondary-color)); color: white; display: flex; align-items: center; justify-content: center; font-weight: bold; border-radius: 50%; width: 40px; height: 40px;">
                                    <?php echo strtoupper(substr($first_name, 0, 1) . substr($last_name, 0, 1)); ?>
                                </div>
                            <?php endif; ?>
                            <div class="user-info">
                                <p class="user-name"><?php echo $full_name; ?></p>
                                <p class="user-email"><?php echo $email; ?></p>
                            </div>
                            <div class="user-profile-dropdown" id="user-dropdown">
                                <a href="../profile.php" class="dropdown-item">
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
                        <h1 class="dashboard-title">Attendance Logs</h1>
                        <p class="dashboard-subtitle">Track your attendance, hours worked, and shift history</p>
                    </div>
                </div>
                
                <!-- Content Container -->
                <div class="content-container">
                    <?php if ($today_checkin): 
                        $checkin_time = date('g:i A', strtotime($today_checkin['check_in']));
                        $checkout_time = $today_checkin['check_out'] ? date('g:i A', strtotime($today_checkin['check_out'])) : 'Not checked out';
                        $status_class = 'status-' . $today_checkin['attendance_status'];
                    ?>
                        <!-- Today's Check-in Status -->
                        <div class="checkin-container">
                            <div class="checkin-title">Today's Attendance</div>
                            <div class="checkin-time"><?php echo $checkin_time; ?></div>
                            <div style="display: flex; justify-content: space-between; align-items: center;">
                                <div>
                                    <div style="font-size: 14px; margin-bottom: 5px;">Check-out: <?php echo $checkout_time; ?></div>
                                    <div style="font-size: 14px;">Total Hours: <?php echo $today_checkin['total_hours'] ?: '0'; ?></div>
                                </div>
                                <span class="checkin-status <?php echo $status_class; ?>">
                                    <?php echo ucfirst($today_checkin['attendance_status']); ?>
                                </span>
                            </div>
                        </div>
                    <?php endif; ?>
                    
                    <!-- Attendance Statistics -->
                    <div class="section-container">
                        <h3 class="section-title">
                            <i class='bx bx-stats'></i>
                            Attendance Statistics - <?php echo date('F Y', strtotime($start_date)); ?>
                        </h3>
                        
                        <div class="stats-container">
                            <div class="stat-card">
                                <div class="stat-value" style="color: var(--success);">
                                    <?php echo $attendance_stats ? $attendance_stats['present_count'] : '0'; ?>
                                </div>
                                <div class="stat-label">Present</div>
                            </div>
                            
                            <div class="stat-card">
                                <div class="stat-value" style="color: var(--warning);">
                                    <?php echo $attendance_stats ? $attendance_stats['late_count'] : '0'; ?>
                                </div>
                                <div class="stat-label">Late</div>
                            </div>
                            
                            <div class="stat-card">
                                <div class="stat-value" style="color: var(--danger);">
                                    <?php echo $attendance_stats ? $attendance_stats['absent_count'] : '0'; ?>
                                </div>
                                <div class="stat-label">Absent</div>
                            </div>
                            
                            <div class="stat-card">
                                <div class="stat-value" style="color: var(--info);">
                                    <?php echo $attendance_stats ? ($attendance_stats['excused_count'] + $attendance_stats['on_leave_count']) : '0'; ?>
                                </div>
                                <div class="stat-label">Excused/Leave</div>
                            </div>
                            
                            <div class="stat-card">
                                <div class="stat-value" style="color: var(--primary-color);">
                                    <?php echo $attendance_stats ? number_format($attendance_stats['total_hours'], 1) : '0.0'; ?>
                                </div>
                                <div class="stat-label">Total Hours</div>
                            </div>
                            
                            <div class="stat-card">
                                <div class="stat-value" style="color: #8b5cf6;">
                                    <?php echo $attendance_stats ? number_format($attendance_stats['total_overtime'], 1) : '0.0'; ?>
                                </div>
                                <div class="stat-label">Overtime Hours</div>
                            </div>
                        </div>
                        
                        <div style="background: rgba(220, 38, 38, 0.05); padding: 15px; border-radius: 8px; margin-top: 15px;">
                            <p style="margin: 0; color: var(--text-color); font-size: 13px;">
                                <i class='bx bx-info-circle' style="color: var(--primary-color);"></i>
                                <strong>Volunteer:</strong> <?php echo $volunteer_name; ?> 
                                | <strong>Contact:</strong> <?php echo $volunteer_contact; ?>
                                | <strong>Attendance Rate:</strong> 
                                <?php 
                                if ($attendance_stats && $attendance_stats['total_shifts'] > 0) {
                                    $attendance_rate = (($attendance_stats['present_count'] + $attendance_stats['late_count']) / $attendance_stats['total_shifts']) * 100;
                                    echo number_format($attendance_rate, 1) . '%';
                                } else {
                                    echo 'N/A';
                                }
                                ?>
                            </p>
                        </div>
                    </div>
                    
                    <!-- Filters -->
                    <div class="filter-container">
                        <form method="GET" action="" id="filter-form">
                            <div class="filter-group">
                                <label class="filter-label">Year</label>
                                <select name="year" class="filter-select">
                                    <?php foreach ($available_years as $year): ?>
                                        <option value="<?php echo $year['year']; ?>" <?php echo $year_filter == $year['year'] ? 'selected' : ''; ?>>
                                            <?php echo $year['year']; ?>
                                        </option>
                                    <?php endforeach; ?>
                                    <?php if (empty($available_years)): ?>
                                        <option value="<?php echo date('Y'); ?>" selected><?php echo date('Y'); ?></option>
                                    <?php endif; ?>
                                </select>
                            </div>
                            
                            <div class="filter-group">
                                <label class="filter-label">Month</label>
                                <select name="month" class="filter-select">
                                    <?php 
                                    $months = [
                                        '01' => 'January', '02' => 'February', '03' => 'March',
                                        '04' => 'April', '05' => 'May', '06' => 'June',
                                        '07' => 'July', '08' => 'August', '09' => 'September',
                                        '10' => 'October', '11' => 'November', '12' => 'December'
                                    ];
                                    foreach ($months as $num => $name): ?>
                                        <option value="<?php echo $num; ?>" <?php echo $month_filter == $num ? 'selected' : ''; ?>>
                                            <?php echo $name; ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div class="filter-group">
                                <label class="filter-label">Status</label>
                                <select name="status" class="filter-select">
                                    <option value="all" <?php echo $status_filter === 'all' ? 'selected' : ''; ?>>All Status</option>
                                    <option value="present" <?php echo $status_filter === 'present' ? 'selected' : ''; ?>>Present</option>
                                    <option value="late" <?php echo $status_filter === 'late' ? 'selected' : ''; ?>>Late</option>
                                    <option value="absent" <?php echo $status_filter === 'absent' ? 'selected' : ''; ?>>Absent</option>
                                    <option value="excused" <?php echo $status_filter === 'excused' ? 'selected' : ''; ?>>Excused</option>
                                    <option value="on_leave" <?php echo $status_filter === 'on_leave' ? 'selected' : ''; ?>>On Leave</option>
                                </select>
                            </div>
                            
                            <div class="filter-actions">
                                <button type="submit" class="btn btn-primary">
                                    <i class='bx bx-filter-alt'></i> Apply Filters
                                </button>
                                <a href="attendance_logs.php" class="btn btn-secondary">
                                    <i class='bx bx-reset'></i> Clear Filters
                                </a>
                            </div>
                        </form>
                    </div>
                    
                    <!-- Upcoming Shifts -->
                    <?php if (!empty($upcoming_shifts)): ?>
                        <div class="section-container">
                            <h3 class="section-title">
                                <i class='bx bx-calendar-plus'></i>
                                Upcoming Shifts (Next 7 Days)
                            </h3>
                            
                            <div class="upcoming-shifts">
                                <?php foreach ($upcoming_shifts as $shift): 
                                    $shift_date = date('D, M j', strtotime($shift['shift_date']));
                                    $start_time = date('g:i A', strtotime($shift['start_time']));
                                    $end_time = date('g:i A', strtotime($shift['end_time']));
                                ?>
                                    <div class="shift-item">
                                        <div>
                                            <div class="shift-date"><?php echo $shift_date; ?></div>
                                            <div class="shift-time"><?php echo $start_time; ?> - <?php echo $end_time; ?></div>
                                        </div>
                                        <div class="shift-unit"><?php echo htmlspecialchars($shift['unit_name']); ?></div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endif; ?>
                    
                    <!-- Attendance Calendar View -->
                    <div class="section-container">
                        <h3 class="section-title">
                            <i class='bx bx-calendar'></i>
                            Calendar View - <?php echo date('F Y', strtotime($start_date)); ?>
                        </h3>
                        
                        <div class="calendar-container">
                            <?php 
                            // Get first day of the month
                            $first_day = date('N', strtotime($start_date));
                            $days_in_month = date('t', strtotime($start_date));
                            $today = date('Y-m-d');
                            
                            // Get attendance dates for this month
                            $attendance_dates = [];
                            foreach ($attendance_logs as $log) {
                                $attendance_dates[$log['shift_date']] = $log['attendance_status'];
                            }
                            ?>
                            
                            <div class="calendar-grid">
                                <!-- Day headers -->
                                <div class="calendar-day-header">Mon</div>
                                <div class="calendar-day-header">Tue</div>
                                <div class="calendar-day-header">Wed</div>
                                <div class="calendar-day-header">Thu</div>
                                <div class="calendar-day-header">Fri</div>
                                <div class="calendar-day-header">Sat</div>
                                <div class="calendar-day-header">Sun</div>
                                
                                <!-- Empty days before the first day -->
                                <?php for ($i = 1; $i < $first_day; $i++): ?>
                                    <div class="calendar-day other-month"></div>
                                <?php endfor; ?>
                                
                                <!-- Days of the month -->
                                <?php for ($day = 1; $day <= $days_in_month; $day++): 
                                    $current_date = date('Y-m-d', strtotime($year_filter . '-' . $month_filter . '-' . sprintf('%02d', $day)));
                                    $is_today = $current_date == $today;
                                    $has_attendance = isset($attendance_dates[$current_date]);
                                    $attendance_status = $has_attendance ? $attendance_dates[$current_date] : null;
                                    
                                    $day_class = 'calendar-day';
                                    if ($is_today) $day_class .= ' today';
                                    if ($has_attendance) $day_class .= ' has-attendance';
                                ?>
                                    <div class="<?php echo $day_class; ?>" 
                                         onclick="viewDayAttendance('<?php echo $current_date; ?>')"
                                         title="<?php echo $has_attendance ? 'Attendance: ' . ucfirst($attendance_status) : 'No attendance record'; ?>">
                                        <div class="day-number"><?php echo $day; ?></div>
                                        <?php if ($has_attendance): ?>
                                            <div class="attendance-indicator <?php echo $attendance_status; ?>"></div>
                                        <?php endif; ?>
                                    </div>
                                <?php endfor; ?>
                                
                                <!-- Empty days after the last day -->
                                <?php 
                                $last_day = date('N', strtotime($year_filter . '-' . $month_filter . '-' . $days_in_month));
                                $remaining_days = 7 - $last_day;
                                if ($remaining_days > 0) {
                                    for ($i = 0; $i < $remaining_days; $i++) {
                                        echo '<div class="calendar-day other-month"></div>';
                                    }
                                }
                                ?>
                            </div>
                        </div>
                        
                        <div style="display: flex; gap: 15px; margin-top: 20px; flex-wrap: wrap;">
                            <div style="display: flex; align-items: center; gap: 5px;">
                                <div class="attendance-indicator present" style="margin: 0;"></div>
                                <span style="font-size: 12px; color: var(--text-color);">Present</span>
                            </div>
                            <div style="display: flex; align-items: center; gap: 5px;">
                                <div class="attendance-indicator late" style="margin: 0;"></div>
                                <span style="font-size: 12px; color: var(--text-color);">Late</span>
                            </div>
                            <div style="display: flex; align-items: center; gap: 5px;">
                                <div class="attendance-indicator absent" style="margin: 0;"></div>
                                <span style="font-size: 12px; color: var(--text-color);">Absent</span>
                            </div>
                            <div style="display: flex; align-items: center; gap: 5px;">
                                <div class="attendance-indicator excused" style="margin: 0;"></div>
                                <span style="font-size: 12px; color: var(--text-color);">Excused</span>
                            </div>
                            <div style="display: flex; align-items: center; gap: 5px;">
                                <div class="attendance-indicator on_leave" style="margin: 0;"></div>
                                <span style="font-size: 12px; color: var(--text-color);">On Leave</span>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Attendance Logs List -->
                    <div class="section-container">
                        <h3 class="section-title">
                            <i class='bx bx-history'></i>
                            Attendance History
                            <?php if (count($attendance_logs) > 0): ?>
                                <span class="date-badge"><?php echo count($attendance_logs); ?> records</span>
                            <?php endif; ?>
                        </h3>
                        
                        <?php if (count($attendance_logs) > 0): ?>
                            <?php foreach ($attendance_logs as $log): 
                                $shift_date = date('F j, Y', strtotime($log['shift_date']));
                                $check_in_time = $log['check_in'] ? date('g:i A', strtotime($log['check_in'])) : 'Not checked in';
                                $check_out_time = $log['check_out'] ? date('g:i A', strtotime($log['check_out'])) : 'Not checked out';
                                $status_class = 'status-' . $log['attendance_status'];
                                
                                // Determine card class based on status
                                $card_class = $log['attendance_status'];
                            ?>
                                <div class="attendance-card <?php echo $card_class; ?>">
                                    <div class="attendance-header">
                                        <div class="attendance-title">
                                            <i class='bx bx-calendar-check'></i>
                                            <?php echo $shift_date; ?>
                                            <?php if ($log['shift_date'] == date('Y-m-d')): ?>
                                                <span class="today-badge">Today</span>
                                            <?php endif; ?>
                                        </div>
                                        <span class="attendance-status <?php echo $status_class; ?>">
                                            <?php echo ucfirst($log['attendance_status']); ?>
                                        </span>
                                    </div>
                                    
                                    <div class="attendance-details">
                                        <div class="detail-item">
                                            <span class="detail-label">Shift Type</span>
                                            <span class="detail-value"><?php echo ucfirst(str_replace('_', ' ', $log['shift_type'])); ?></span>
                                        </div>
                                        
                                        <div class="detail-item">
                                            <span class="detail-label">Assigned Unit</span>
                                            <span class="detail-value">
                                                <?php echo htmlspecialchars($log['unit_name'] ?? 'Not Assigned'); ?>
                                                <?php if ($log['unit_code']): ?>
                                                    (<?php echo htmlspecialchars($log['unit_code']); ?>)
                                                <?php endif; ?>
                                            </span>
                                        </div>
                                        
                                        <div class="detail-item">
                                            <span class="detail-label">Location</span>
                                            <span class="detail-value"><?php echo htmlspecialchars($log['shift_location'] ?? 'Main Station'); ?></span>
                                        </div>
                                        
                                        <div class="detail-item">
                                            <span class="detail-label">Verified By</span>
                                            <span class="detail-value"><?php echo htmlspecialchars($log['verified_by_name'] ?? 'Not Verified'); ?></span>
                                        </div>
                                    </div>
                                    
                                    <div class="time-section">
                                        <div class="time-item">
                                            <span class="time-label">Scheduled Time</span>
                                            <span class="time-value">
                                                <?php echo date('g:i A', strtotime($log['start_time'])); ?> - 
                                                <?php echo date('g:i A', strtotime($log['end_time'])); ?>
                                            </span>
                                        </div>
                                        
                                        <div class="time-item">
                                            <span class="time-label">Check-in Time</span>
                                            <span class="time-value"><?php echo $check_in_time; ?></span>
                                        </div>
                                        
                                        <div class="time-item">
                                            <span class="time-label">Check-out Time</span>
                                            <span class="time-value"><?php echo $check_out_time; ?></span>
                                        </div>
                                        
                                        <div class="time-item">
                                            <span class="time-label">Hours Worked</span>
                                            <span class="time-value">
                                                <span class="hours-badge <?php echo $log['overtime_hours'] > 0 ? 'overtime' : ''; ?>">
                                                    <?php echo $log['total_hours'] ? number_format($log['total_hours'], 2) : '0.00'; ?> hrs
                                                    <?php if ($log['overtime_hours'] > 0): ?>
                                                        <span>(+<?php echo number_format($log['overtime_hours'], 2); ?> OT)</span>
                                                    <?php endif; ?>
                                                </span>
                                            </span>
                                        </div>
                                    </div>
                                    
                                    <?php if ($log['notes']): ?>
                                        <div class="notes-section">
                                            <div class="notes-title">
                                                <i class='bx bx-note'></i>
                                                Notes
                                            </div>
                                            <p style="margin: 0; color: var(--text-color); font-size: 13px; line-height: 1.5;">
                                                <?php echo htmlspecialchars($log['notes']); ?>
                                            </p>
                                        </div>
                                    <?php endif; ?>
                                    
                                    <?php if ($log['verified_at']): ?>
                                        <div style="margin-top: 10px; font-size: 11px; color: var(--text-light);">
                                            Verified on <?php echo date('M j, Y g:i A', strtotime($log['verified_at'])); ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="empty-state">
                                <i class='bx bx-calendar-x'></i>
                                <h3>No Attendance Records Found</h3>
                                <p>You don't have any attendance records for the selected period. Check back after completing your shifts.</p>
                                <div style="margin-top: 20px;">
                                    <a href="view_shifts.php" class="btn btn-primary">
                                        <i class='bx bx-calendar'></i> View Upcoming Shifts
                                    </a>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                    
                    <!-- Information Section -->
                    <div class="section-container">
                        <h3 class="section-title">
                            <i class='bx bx-help-circle'></i>
                            About Attendance Tracking
                        </h3>
                        
                        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 20px;">
                            <div style="background: rgba(16, 185, 129, 0.1); padding: 20px; border-radius: 10px; border: 1px solid rgba(16, 185, 129, 0.2);">
                                <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 10px;">
                                    <div style="background: var(--success); color: white; width: 30px; height: 30px; border-radius: 50%; display: flex; align-items: center; justify-content: center;">
                                        <i class='bx bx-check-circle'></i>
                                    </div>
                                    <h4 style="margin: 0; color: var(--text-color);">Present</h4>
                                </div>
                                <p style="margin: 0; color: var(--text-color); font-size: 13px;">
                                    You arrived on time and completed your full shift. All hours are recorded normally.
                                </p>
                            </div>
                            
                            <div style="background: rgba(245, 158, 11, 0.1); padding: 20px; border-radius: 10px; border: 1px solid rgba(245, 158, 11, 0.2);">
                                <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 10px;">
                                    <div style="background: var(--warning); color: white; width: 30px; height: 30px; border-radius: 50%; display: flex; align-items: center; justify-content: center;">
                                        <i class='bx bx-time'></i>
                                    </div>
                                    <h4 style="margin: 0; color: var(--text-color);">Late</h4>
                                </div>
                                <p style="margin: 0; color: var(--text-color); font-size: 13px;">
                                    You arrived after the scheduled start time. Late arrivals may affect your performance rating.
                                </p>
                            </div>
                            
                            <div style="background: rgba(220, 38, 38, 0.1); padding: 20px; border-radius: 10px; border: 1px solid rgba(220, 38, 38, 0.2);">
                                <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 10px;">
                                    <div style="background: var(--danger); color: white; width: 30px; height: 30px; border-radius: 50%; display: flex; align-items: center; justify-content: center;">
                                        <i class='bx bx-x-circle'></i>
                                    </div>
                                    <h4 style="margin: 0; color: var(--text-color);">Absent</h4>
                                </div>
                                <p style="margin: 0; color: var(--text-color); font-size: 13px;">
                                    You did not attend your scheduled shift without prior notification or approval.
                                </p>
                            </div>
                            
                            <div style="background: rgba(59, 130, 246, 0.1); padding: 20px; border-radius: 10px; border: 1px solid rgba(59, 130, 246, 0.2);">
                                <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 10px;">
                                    <div style="background: var(--info); color: white; width: 30px; height: 30px; border-radius: 50%; display: flex; align-items: center; justify-content: center;">
                                        <i class='bx bx-user-check'></i>
                                    </div>
                                    <h4 style="margin: 0; color: var(--text-color);">Excused</h4>
                                </div>
                                <p style="margin: 0; color: var(--text-color); font-size: 13px;">
                                    Your absence was approved in advance. This does not count against your attendance record.
                                </p>
                            </div>
                        </div>
                        
                        <div style="margin-top: 20px; padding: 15px; background: rgba(220, 38, 38, 0.05); border-radius: 8px; border-left: 3px solid var(--primary-color);">
                            <h4 style="margin: 0 0 10px 0; color: var(--primary-color);">Important Notes:</h4>
                            <ul style="margin: 0; padding-left: 20px; color: var(--text-color); font-size: 13px;">
                                <li>Always check in and check out using the designated system or notify your supervisor</li>
                                <li>Late check-ins (more than 15 minutes after scheduled start time) are marked as "Late"</li>
                                <li>Missing check-out will result in incomplete attendance record</li>
                                <li>Overtime hours are calculated automatically when you work beyond scheduled hours</li>
                                <li>Attendance records are verified by supervisors and cannot be modified after verification</li>
                                <li>Regular late arrivals or absences may require a meeting with your supervisor</li>
                                <li>If you need to be excused from a shift, notify your supervisor at least 24 hours in advance</li>
                                <li>Your attendance record affects your volunteer performance rating and future shift assignments</li>
                            </ul>
                        </div>
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
            
            // Handle search
            const searchInput = document.getElementById('search-input');
            if (searchInput) {
                searchInput.addEventListener('keyup', function() {
                    const searchTerm = this.value.toLowerCase();
                    const attendanceCards = document.querySelectorAll('.attendance-card');
                    
                    attendanceCards.forEach(card => {
                        const date = card.querySelector('.attendance-title').textContent.toLowerCase();
                        const unit = card.querySelector('.detail-value').textContent.toLowerCase();
                        const status = card.querySelector('.attendance-status').textContent.toLowerCase();
                        
                        if (date.includes(searchTerm) || unit.includes(searchTerm) || status.includes(searchTerm)) {
                            card.style.display = 'block';
                        } else {
                            card.style.display = 'none';
                        }
                    });
                });
            }
        });
        
        function initEventListeners() {
            // Theme toggle
            const themeToggle = document.getElementById('theme-toggle');
            const themeIcon = themeToggle.querySelector('i');
            const themeText = themeToggle.querySelector('span');
            
            if (themeToggle) {
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
            }
            
            // User profile dropdown
            const userProfile = document.getElementById('user-profile');
            const userDropdown = document.getElementById('user-dropdown');
            
            if (userProfile && userDropdown) {
                userProfile.addEventListener('click', function(e) {
                    e.stopPropagation();
                    userDropdown.classList.toggle('show');
                });
            }
            
            // Close dropdowns when clicking outside
            document.addEventListener('click', function() {
                if (userDropdown) {
                    userDropdown.classList.remove('show');
                }
            });
            
            // Auto-submit filters on change
            const yearFilter = document.querySelector('select[name="year"]');
            const monthFilter = document.querySelector('select[name="month"]');
            const statusFilter = document.querySelector('select[name="status"]');
            
            if (yearFilter) yearFilter.addEventListener('change', function() { document.getElementById('filter-form').submit(); });
            if (monthFilter) monthFilter.addEventListener('change', function() { document.getElementById('filter-form').submit(); });
            if (statusFilter) statusFilter.addEventListener('change', function() { document.getElementById('filter-form').submit(); });
        }
        
        function updateTime() {
            const now = new Date();
            const utc = now.getTime() + (now.getTimezoneOffset() * 60000);
            const gmt8 = new Date(utc + (8 * 3600000));
            
            const hours = gmt8.getHours().toString().padStart(2, '0');
            const minutes = gmt8.getMinutes().toString().padStart(2, '0');
            const seconds = gmt8.getSeconds().toString().padStart(2, '0');
            
            const timeString = `${hours}:${minutes}:${seconds} UTC+8`;
            const timeDisplay = document.getElementById('current-time');
            if (timeDisplay) {
                timeDisplay.textContent = timeString;
            }
        }
        
        function toggleSubmenu(id) {
            const submenu = document.getElementById(id);
            const arrow = submenu.previousElementSibling.querySelector('.dropdown-arrow');
            
            submenu.classList.toggle('active');
            arrow.classList.toggle('rotated');
        }
        
        function viewDayAttendance(date) {
            // Filter attendance logs for the selected date
            const attendanceCards = document.querySelectorAll('.attendance-card');
            const searchInput = document.getElementById('search-input');
            
            // Format date for display
            const formattedDate = new Date(date).toLocaleDateString('en-US', { 
                weekday: 'long', 
                year: 'numeric', 
                month: 'long', 
                day: 'numeric' 
            });
            
            // Update search input to show this date
            if (searchInput) {
                searchInput.value = formattedDate;
                
                // Trigger search
                const searchTerm = formattedDate.toLowerCase();
                attendanceCards.forEach(card => {
                    const cardDate = card.querySelector('.attendance-title').textContent.toLowerCase();
                    
                    if (cardDate.includes(searchTerm)) {
                        card.style.display = 'block';
                    } else {
                        card.style.display = 'none';
                    }
                });
                
                // Scroll to logs section
                document.querySelector('.section-container:last-of-type').scrollIntoView({ behavior: 'smooth' });
            }
        }
    </script>
</body>
</html>