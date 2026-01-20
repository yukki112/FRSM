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
$volunteer_query = "SELECT id FROM volunteers WHERE user_id = ?";
$volunteer_stmt = $pdo->prepare($volunteer_query);
$volunteer_stmt->execute([$user_id]);
$volunteer = $volunteer_stmt->fetch();

if (!$volunteer) {
    // User is not registered as a volunteer
    header("Location: ../dashboard.php");
    exit();
}

$volunteer_id = $volunteer['id'];

// Get current month and year
$current_month = isset($_GET['month']) ? (int)$_GET['month'] : date('n');
$current_year = isset($_GET['year']) ? (int)$_GET['year'] : date('Y');

// Validate month and year
if ($current_month < 1 || $current_month > 12) {
    $current_month = date('n');
}
if ($current_year < 2020 || $current_year > 2100) {
    $current_year = date('Y');
}

// Calculate previous and next months
$prev_month = $current_month - 1;
$prev_year = $current_year;
if ($prev_month < 1) {
    $prev_month = 12;
    $prev_year--;
}

$next_month = $current_month + 1;
$next_year = $current_year;
if ($next_month > 12) {
    $next_month = 1;
    $next_year++;
}

// Get number of days in month
$days_in_month = cal_days_in_month(CAL_GREGORIAN, $current_month, $current_year);

// Get first day of month
$first_day_of_month = date('N', strtotime("$current_year-$current_month-01"));

// Month names
$month_names = [
    1 => 'January', 2 => 'February', 3 => 'March', 4 => 'April',
    5 => 'May', 6 => 'June', 7 => 'July', 8 => 'August',
    9 => 'September', 10 => 'October', 11 => 'November', 12 => 'December'
];

// Get volunteer's shifts for this month with duty assignment information
$start_date = "$current_year-$current_month-01";
$end_date = "$current_year-$current_month-$days_in_month";

$shifts_query = "
    SELECT 
        s.*, 
        u.unit_name, 
        u.unit_code, 
        u.unit_type, 
        u.location as unit_location,
        da.duty_type,
        da.duty_description,
        da.priority,
        da.required_equipment,
        da.required_training,
        da.notes as duty_notes,
        CASE 
            WHEN s.shift_type = 'morning' THEN '🌅 Morning'
            WHEN s.shift_type = 'afternoon' THEN '☀️ Afternoon'
            WHEN s.shift_type = 'evening' THEN '🌆 Evening'
            WHEN s.shift_type = 'night' THEN '🌙 Night'
            WHEN s.shift_type = 'full_day' THEN '🌞 Full Day'
        END as shift_type_display
    FROM shifts s 
    LEFT JOIN units u ON s.unit_id = u.id 
    LEFT JOIN duty_assignments da ON s.duty_assignment_id = da.id
    WHERE s.volunteer_id = ? 
    AND s.shift_date BETWEEN ? AND ?
    ORDER BY s.shift_date, s.start_time
";
$shifts_stmt = $pdo->prepare($shifts_query);
$shifts_stmt->execute([$volunteer_id, $start_date, $end_date]);
$shifts = $shifts_stmt->fetchAll();

// Organize shifts by day
$shifts_by_day = [];
foreach ($shifts as $shift) {
    $day = (int)date('j', strtotime($shift['shift_date']));
    $shifts_by_day[$day][] = $shift;
}

// Get volunteer assignments to show unit info
$assignments_query = "
    SELECT u.unit_name, u.unit_code, u.unit_type, u.location
    FROM volunteer_assignments va
    JOIN units u ON va.unit_id = u.id
    WHERE va.volunteer_id = ? AND va.status = 'Active'
    ORDER BY va.assignment_date DESC
    LIMIT 1
";
$assignments_stmt = $pdo->prepare($assignments_query);
$assignments_stmt->execute([$volunteer_id]);
$assignment = $assignments_stmt->fetch();

// Get upcoming shifts (next 7 days) with duty assignment information
$today = date('Y-m-d');
$next_week = date('Y-m-d', strtotime('+7 days'));

$upcoming_query = "
    SELECT 
        s.*, 
        u.unit_name, 
        u.unit_code, 
        u.unit_type,
        da.duty_type,
        da.duty_description,
        da.priority,
        CASE 
            WHEN s.shift_type = 'morning' THEN '🌅 Morning'
            WHEN s.shift_type = 'afternoon' THEN '☀️ Afternoon'
            WHEN s.shift_type = 'evening' THEN '🌆 Evening'
            WHEN s.shift_type = 'night' THEN '🌙 Night'
            WHEN s.shift_type = 'full_day' THEN '🌞 Full Day'
        END as shift_type_display
    FROM shifts s 
    LEFT JOIN units u ON s.unit_id = u.id 
    LEFT JOIN duty_assignments da ON s.duty_assignment_id = da.id
    WHERE s.volunteer_id = ? 
    AND s.shift_date >= ? 
    AND s.shift_date <= ?
    ORDER BY s.shift_date, s.start_time
    LIMIT 10
";
$upcoming_stmt = $pdo->prepare($upcoming_query);
$upcoming_stmt->execute([$volunteer_id, $today, $next_week]);
$upcoming_shifts = $upcoming_stmt->fetchAll();

// Get shift statistics
$stats_query = "
    SELECT 
        COUNT(*) as total_shifts,
        SUM(CASE WHEN s.status = 'completed' THEN 1 ELSE 0 END) as completed_shifts,
        SUM(CASE WHEN s.status = 'scheduled' THEN 1 ELSE 0 END) as scheduled_shifts,
        SUM(CASE WHEN s.status = 'confirmed' THEN 1 ELSE 0 END) as confirmed_shifts,
        SUM(CASE WHEN s.status = 'cancelled' THEN 1 ELSE 0 END) as cancelled_shifts,
        SUM(CASE WHEN s.status = 'absent' THEN 1 ELSE 0 END) as absent_shifts,
        COUNT(DISTINCT DATE_FORMAT(s.shift_date, '%Y-%m')) as months_volunteered
    FROM shifts s 
    WHERE s.volunteer_id = ?
";
$stats_stmt = $pdo->prepare($stats_query);
$stats_stmt->execute([$volunteer_id]);
$stats = $stats_stmt->fetch();

// Get total hours volunteered
$hours_query = "
    SELECT 
        SUM(TIME_TO_SEC(TIMEDIFF(end_time, start_time)) / 3600) as total_hours
    FROM shifts 
    WHERE volunteer_id = ? AND status = 'completed'
";
$hours_stmt = $pdo->prepare($hours_query);
$hours_stmt->execute([$volunteer_id]);
$hours_data = $hours_stmt->fetch();
$total_hours = $hours_data['total_hours'] ?? 0;

// Close statements
$stmt = null;
$volunteer_stmt = null;
$shifts_stmt = null;
$assignments_stmt = null;
$upcoming_stmt = null;
$stats_stmt = null;
$hours_stmt = null;
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Shift Calendar - Fire & Rescue Services Management</title>
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

        .calendar-container {
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: 16px;
            padding: 30px;
            margin-bottom: 30px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
        }

        .calendar-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }

        .calendar-title {
            font-size: 20px;
            font-weight: 700;
            color: var(--text-color);
        }

        .calendar-nav {
            display: flex;
            gap: 10px;
        }

        .calendar-nav-btn {
            padding: 8px 12px;
            border-radius: 8px;
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            color: var(--text-color);
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 5px;
            text-decoration: none;
        }

        .calendar-nav-btn:hover {
            background: var(--gray-100);
            border-color: var(--primary-color);
        }

        .dark-mode .calendar-nav-btn:hover {
            background: var(--gray-800);
        }

        .calendar-grid {
            display: grid;
            grid-template-columns: repeat(7, 1fr);
            gap: 1px;
            background: var(--border-color);
            border: 1px solid var(--border-color);
            border-radius: 8px;
            overflow: hidden;
        }

        .calendar-day-header {
            background: rgba(220, 38, 38, 0.1);
            padding: 12px;
            text-align: center;
            font-weight: 600;
            color: var(--text-color);
            border-bottom: 1px solid var(--border-color);
        }

        .calendar-day {
            background: var(--card-bg);
            min-height: 120px;
            padding: 10px;
            border: 1px solid var(--border-color);
            transition: all 0.3s ease;
            position: relative;
        }

        .calendar-day:hover {
            background: var(--gray-100);
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.1);
            z-index: 1;
        }

        .dark-mode .calendar-day:hover {
            background: var(--gray-800);
        }

        .calendar-day.today {
            background: rgba(220, 38, 38, 0.05);
            border: 2px solid var(--primary-color);
        }

        .calendar-day.other-month {
            background: var(--gray-100);
            color: var(--text-light);
        }

        .dark-mode .calendar-day.other-month {
            background: var(--gray-800);
        }

        .calendar-day.has-shift {
            background: rgba(16, 185, 129, 0.05);
            border-color: rgba(16, 185, 129, 0.3);
        }

        .day-number {
            font-weight: 600;
            margin-bottom: 8px;
            color: var(--text-color);
            font-size: 14px;
        }

        .shift-count-badge {
            position: absolute;
            top: 5px;
            right: 5px;
            background: var(--primary-color);
            color: white;
            width: 20px;
            height: 20px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 11px;
            font-weight: 600;
        }

        .shift-item {
            background: rgba(59, 130, 246, 0.1);
            border-left: 3px solid var(--info);
            padding: 6px 8px;
            margin-bottom: 5px;
            border-radius: 4px;
            font-size: 11px;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .shift-item:hover {
            background: rgba(59, 130, 246, 0.2);
            transform: translateX(2px);
        }

        .shift-item.volunteer {
            background: rgba(16, 185, 129, 0.1);
            border-left-color: var(--success);
        }

        .shift-item.volunteer:hover {
            background: rgba(16, 185, 129, 0.2);
        }

        .shift-time {
            font-weight: 600;
            font-size: 10px;
            color: var(--text-color);
        }

        .shift-unit {
            font-size: 9px;
            color: var(--text-light);
            margin-top: 2px;
        }

        .no-shifts {
            text-align: center;
            padding: 40px;
            color: var(--text-light);
        }

        .no-shifts i {
            font-size: 48px;
            margin-bottom: 16px;
            opacity: 0.5;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
            gap: 16px;
            margin-bottom: 30px;
        }

        .stat-card {
            background: var(--card-bg);
            border-radius: 12px;
            padding: 20px;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
            border-left: 4px solid var(--primary-color);
            display: flex;
            align-items: center;
            gap: 15px;
            transition: all 0.3s ease;
        }

        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 16px rgba(0, 0, 0, 0.1);
        }

        .stat-icon {
            font-size: 32px;
            color: var(--primary-color);
        }

        .stat-content {
            flex: 1;
        }

        .stat-number {
            font-size: 32px;
            font-weight: 700;
            color: var(--text-color);
            line-height: 1;
        }

        .stat-label {
            font-size: 14px;
            color: var(--text-light);
            margin-top: 4px;
        }

        .upcoming-shifts-container {
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: 16px;
            padding: 30px;
            margin-top: 30px;
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

        .shifts-table {
            width: 100%;
            border-collapse: collapse;
        }

        .shifts-table th {
            text-align: left;
            padding: 12px;
            background: rgba(220, 38, 38, 0.1);
            color: var(--text-color);
            font-weight: 600;
            border-bottom: 2px solid var(--border-color);
        }

        .shifts-table td {
            padding: 12px;
            border-bottom: 1px solid var(--border-color);
        }

        .shifts-table tr:hover {
            background: var(--gray-100);
        }

        .dark-mode .shifts-table tr:hover {
            background: var(--gray-800);
        }

        .status-badge {
            padding: 4px 8px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 600;
            display: inline-block;
        }

        .status-scheduled {
            background: rgba(245, 158, 11, 0.1);
            color: var(--warning);
        }

        .status-confirmed {
            background: rgba(16, 185, 129, 0.1);
            color: var(--success);
        }

        .status-completed {
            background: rgba(59, 130, 246, 0.1);
            color: var(--info);
        }

        .status-cancelled {
            background: rgba(107, 114, 128, 0.1);
            color: var(--gray-500);
        }

        .status-absent {
            background: rgba(220, 38, 38, 0.1);
            color: var(--danger);
        }

        .assignment-card {
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: 16px;
            padding: 30px;
            margin-top: 30px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
        }

        .assignment-info {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-top: 20px;
        }

        .info-item {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .info-icon {
            color: var(--primary-color);
            font-size: 20px;
        }

        .info-content {
            flex: 1;
        }

        .info-label {
            font-size: 12px;
            color: var(--text-light);
            margin-bottom: 4px;
        }

        .info-value {
            font-weight: 600;
            color: var(--text-color);
            font-size: 14px;
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
            max-width: 700px;
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
            color: var(--text-color);
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

        .shift-detail-item {
            background: rgba(220, 38, 38, 0.05);
            border: 1px solid rgba(220, 38, 38, 0.2);
            border-radius: 10px;
            padding: 16px;
            margin-bottom: 12px;
        }

        .shift-detail-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 12px;
        }

        .shift-detail-time {
            font-weight: 600;
            color: var(--primary-color);
            font-size: 16px;
        }

        .shift-detail-info {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 12px;
            margin-top: 12px;
        }

        .detail-item {
            display: flex;
            flex-direction: column;
        }

        .detail-label {
            font-size: 11px;
            color: var(--text-light);
            margin-bottom: 4px;
        }

        .detail-value {
            font-weight: 500;
            color: var(--text-color);
            font-size: 13px;
        }

        .duty-assignment-section {
            background: rgba(59, 130, 246, 0.05);
            border: 1px solid rgba(59, 130, 246, 0.2);
            border-radius: 8px;
            padding: 16px;
            margin-top: 16px;
        }

        .duty-title {
            font-weight: 600;
            color: var(--info);
            margin-bottom: 8px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .duty-description {
            font-size: 13px;
            line-height: 1.5;
            margin-bottom: 12px;
            color: var(--text-color);
        }

        .duty-details {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 12px;
            margin-top: 12px;
        }

        .duty-badge {
            display: inline-block;
            padding: 4px 8px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 600;
            margin-right: 8px;
        }

        .badge-primary {
            background: rgba(220, 38, 38, 0.1);
            color: var(--primary-color);
        }

        .badge-secondary {
            background: rgba(245, 158, 11, 0.1);
            color: var(--warning);
        }

        .badge-support {
            background: rgba(107, 114, 128, 0.1);
            color: var(--gray-500);
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

        .mobile-view {
            display: none;
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
            
            .calendar-grid {
                grid-template-columns: repeat(1, 1fr);
            }
            
            .calendar-day {
                min-height: auto;
            }
            
            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }
            
            .assignment-info {
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
            
            .calendar-container, .upcoming-shifts-container, .assignment-card {
                padding: 20px;
            }
            
            .stats-grid {
                grid-template-columns: 1fr;
            }
            
            .desktop-view {
                display: none;
            }
            
            .mobile-view {
                display: block;
            }
            
            .shifts-table {
                display: block;
                overflow-x: auto;
            }
        }

        .unit-badge {
            display: inline-block;
            padding: 6px 12px;
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            color: white;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            margin-left: 10px;
        }

        .legend {
            display: flex;
            flex-wrap: wrap;
            gap: 16px;
            margin-top: 20px;
            padding: 16px;
            background: var(--card-bg);
            border-radius: 8px;
            border: 1px solid var(--border-color);
        }

        .legend-item {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 12px;
            color: var(--text-color);
        }

        .legend-color {
            width: 16px;
            height: 16px;
            border-radius: 4px;
        }

        .legend-today {
            background: rgba(220, 38, 38, 0.1);
            border: 2px solid var(--primary-color);
        }

        .legend-has-shift {
            background: rgba(16, 185, 129, 0.1);
            border: 1px solid var(--success);
        }

        .legend-shift {
            background: rgba(59, 130, 246, 0.1);
            border: 1px solid var(--info);
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

        .print-btn {
            margin-left: auto;
        }

        .calendar-actions {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
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
    <!-- Shift Details Modal -->
    <div class="modal-overlay" id="shift-modal">
        <div class="modal">
            <div class="modal-header">
                <h2 class="modal-title" id="modal-title">Shift Details</h2>
                <button class="modal-close" id="shift-modal-close">&times;</button>
            </div>
            <div class="modal-body">
                <div id="shift-details-content">
                    <!-- Shift details will be loaded here -->
                </div>
            </div>
            <div class="modal-actions">
                <button type="button" class="btn btn-secondary" id="close-shift-modal">Close</button>
            </div>
        </div>
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
            <a href="view_shifts.php" class="submenu-item active">Shift Calendar</a>
              <a href="confirm_availability.php" class="submenu-item">Confirm Availability</a>
            <a href="duty_assignments.php" class="submenu-item">Duty Assignments</a>
            <a href="attendance_logs.php" class="submenu-item">Attendance Logs</a>
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
            <a href="#" class="submenu-item">Training Records</a>
            <a href="#" class="submenu-item">Certification Status</a>
            <a href="#" class="submenu-item">Upcoming Seminars</a>
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
            <!-- Header - Fixed Version -->
            <div class="header">
                <div class="header-content">
                    <div class="search-container">
                        <div class="search-box">
                            <svg class="search-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path>
                            </svg>
                            <input type="text" placeholder="Search incidents, personnel, equipment..." class="search-input" id="search-input">
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
                        <h1 class="dashboard-title">Shift Calendar</h1>
                        <p class="dashboard-subtitle">View and manage your volunteer shifts</p>
                    </div>
                    <?php if ($assignment): ?>
                        <span class="unit-badge">
                            <?php echo htmlspecialchars($assignment['unit_code']); ?> - 
                            <?php echo htmlspecialchars($assignment['unit_type']); ?>
                        </span>
                    <?php endif; ?>
                </div>
                
                <!-- Content Container -->
                <div class="content-container">
                    <!-- Statistics Cards -->
                    <div class="stats-grid">
                        <div class="stat-card">
                            <i class='bx bxs-calendar stat-icon'></i>
                            <div class="stat-content">
                                <div class="stat-number"><?php echo $stats['total_shifts'] ?? 0; ?></div>
                                <div class="stat-label">Total Shifts</div>
                            </div>
                        </div>
                        
                        <div class="stat-card">
                            <i class='bx bxs-check-circle stat-icon'></i>
                            <div class="stat-content">
                                <div class="stat-number"><?php echo $stats['completed_shifts'] ?? 0; ?></div>
                                <div class="stat-label">Completed</div>
                            </div>
                        </div>
                        
                        <div class="stat-card">
                            <i class='bx bxs-time stat-icon'></i>
                            <div class="stat-content">
                                <div class="stat-number"><?php echo $stats['scheduled_shifts'] ?? 0; ?></div>
                                <div class="stat-label">Scheduled</div>
                            </div>
                        </div>
                        
                        <div class="stat-card">
                            <i class='bx bxs-timer stat-icon'></i>
                            <div class="stat-content">
                                <div class="stat-number"><?php echo number_format($total_hours, 1); ?></div>
                                <div class="stat-label">Total Hours</div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Calendar Desktop View -->
                    <div class="desktop-view">
                        <div class="calendar-container">
                            <div class="calendar-header">
                                <h3 class="calendar-title"><?php echo $month_names[$current_month]; ?> <?php echo $current_year; ?></h3>
                                <div class="calendar-nav">
                                    <a href="?month=<?php echo $prev_month; ?>&year=<?php echo $prev_year; ?>" 
                                       class="calendar-nav-btn">
                                        <i class='bx bx-chevron-left'></i> Previous
                                    </a>
                                    <a href="view_shifts.php" class="calendar-nav-btn">
                                        Today
                                    </a>
                                    <a href="?month=<?php echo $next_month; ?>&year=<?php echo $next_year; ?>" 
                                       class="calendar-nav-btn">
                                        Next <i class='bx bx-chevron-right'></i>
                                    </a>
                                </div>
                            </div>
                            
                            <!-- Calendar Grid -->
                            <div class="calendar-grid">
                                <!-- Day Headers -->
                                <div class="calendar-day-header">Mon</div>
                                <div class="calendar-day-header">Tue</div>
                                <div class="calendar-day-header">Wed</div>
                                <div class="calendar-day-header">Thu</div>
                                <div class="calendar-day-header">Fri</div>
                                <div class="calendar-day-header">Sat</div>
                                <div class="calendar-day-header">Sun</div>
                                
                                <!-- Empty days before first day of month -->
                                <?php for ($i = 1; $i < $first_day_of_month; $i++): ?>
                                    <div class="calendar-day other-month"></div>
                                <?php endfor; ?>
                                
                                <!-- Days of the month -->
                                <?php for ($day = 1; $day <= $days_in_month; $day++): 
                                    $current_date = "$current_year-$current_month-" . sprintf("%02d", $day);
                                    $is_today = ($current_date == date('Y-m-d'));
                                    $has_shifts = isset($shifts_by_day[$day]);
                                    $day_class = '';
                                    if ($is_today) $day_class .= ' today';
                                    if ($has_shifts) $day_class .= ' has-shift';
                                ?>
                                    <div class="calendar-day <?php echo $day_class; ?>" 
                                         onclick="showDayDetails(<?php echo $day; ?>)"
                                         style="cursor: pointer;">
                                        
                                        <div class="day-number">
                                            <?php echo $day; ?>
                                            <?php if ($is_today): ?>
                                                <span style="font-size: 10px; color: var(--primary-color);">(Today)</span>
                                            <?php endif; ?>
                                        </div>
                                        
                                        <?php if ($has_shifts): ?>
                                            <div class="shift-count-badge">
                                                <?php echo count($shifts_by_day[$day]); ?>
                                            </div>
                                            
                                            <?php foreach ($shifts_by_day[$day] as $shift): ?>
                                                <div class="shift-item volunteer" 
                                                     onclick="event.stopPropagation(); showShiftDetails(<?php echo $shift['id']; ?>)">
                                                    <div class="shift-time">
                                                        <?php echo date('g:i A', strtotime($shift['start_time'])); ?> - 
                                                        <?php echo date('g:i A', strtotime($shift['end_time'])); ?>
                                                    </div>
                                                    <div class="shift-unit">
                                                        <?php if ($shift['unit_code']): ?>
                                                            <?php echo htmlspecialchars($shift['unit_code']); ?> • 
                                                        <?php endif; ?>
                                                        <?php echo $shift['shift_type_display']; ?>
                                                        <?php if ($shift['duty_type']): ?>
                                                            <br><span style="color: var(--info);">📋 <?php echo htmlspecialchars($shift['duty_type']); ?></span>
                                                        <?php endif; ?>
                                                    </div>
                                                </div>
                                            <?php endforeach; ?>
                                        <?php else: ?>
                                            <div style="text-align: center; padding: 20px 0; color: var(--text-light);">
                                                <i class='bx bx-calendar-x'></i>
                                                <div style="font-size: 11px; margin-top: 5px;">No shifts</div>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                <?php endfor; ?>
                                
                                <!-- Empty days after last day of month -->
                                <?php 
                                    $total_cells = 42; // 6 rows * 7 columns
                                    $days_so_far = $first_day_of_month - 1 + $days_in_month;
                                    $empty_days_after = $total_cells - $days_so_far;
                                    if ($empty_days_after > 0) {
                                        for ($i = 0; $i < $empty_days_after; $i++) {
                                            echo '<div class="calendar-day other-month"></div>';
                                        }
                                    }
                                ?>
                            </div>
                            
                            <!-- Legend -->
                            <div class="legend">
                                <div class="legend-item">
                                    <div class="legend-color legend-today"></div>
                                    <span>Today</span>
                                </div>
                                <div class="legend-item">
                                    <div class="legend-color legend-has-shift"></div>
                                    <span>Has Shifts</span>
                                </div>
                                <div class="legend-item">
                                    <div class="legend-color legend-shift"></div>
                                    <span>Scheduled Shift</span>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Mobile View -->
                    <div class="mobile-view">
                        <div class="calendar-container">
                            <div class="calendar-header">
                                <h3 class="calendar-title">My Shifts for <?php echo $month_names[$current_month]; ?></h3>
                            </div>
                            
                            <?php if (count($shifts) > 0): ?>
                                <?php foreach ($shifts as $shift): 
                                    $shift_date = date('F j, Y', strtotime($shift['shift_date']));
                                    $is_today = $shift['shift_date'] == date('Y-m-d');
                                    $day_class = $is_today ? 'today' : '';
                                ?>
                                    <div class="shift-detail-item <?php echo $day_class; ?>" 
                                         onclick="showShiftDetails(<?php echo $shift['id']; ?>)"
                                         style="cursor: pointer;">
                                        <div class="shift-detail-header">
                                            <div class="shift-detail-time">
                                                <?php echo $shift['shift_type_display']; ?>
                                                <?php if ($is_today): ?> • <span style="color: var(--primary-color);">Today</span><?php endif; ?>
                                                <?php if ($shift['duty_type']): ?>
                                                    <br><span style="font-size: 11px; color: var(--info);">📋 <?php echo htmlspecialchars($shift['duty_type']); ?></span>
                                                <?php endif; ?>
                                            </div>
                                            <span class="status-badge status-<?php echo $shift['status']; ?>">
                                                <?php echo ucfirst($shift['status']); ?>
                                            </span>
                                        </div>
                                        
                                        <div class="shift-detail-info">
                                            <div class="detail-item">
                                                <span class="detail-label">Date</span>
                                                <span class="detail-value"><?php echo $shift_date; ?></span>
                                            </div>
                                            <div class="detail-item">
                                                <span class="detail-label">Time</span>
                                                <span class="detail-value">
                                                    <?php echo date('g:i A', strtotime($shift['start_time'])); ?> - 
                                                    <?php echo date('g:i A', strtotime($shift['end_time'])); ?>
                                                </span>
                                            </div>
                                            <div class="detail-item">
                                                <span class="detail-label">Unit</span>
                                                <span class="detail-value">
                                                    <?php echo htmlspecialchars($shift['unit_name'] ?? 'Not Assigned'); ?>
                                                    <?php if ($shift['unit_code']): ?>
                                                        (<?php echo htmlspecialchars($shift['unit_code']); ?>)
                                                    <?php endif; ?>
                                                </span>
                                            </div>
                                            <?php if ($shift['duty_type']): ?>
                                            <div class="detail-item">
                                                <span class="detail-label">Duty Assignment</span>
                                                <span class="detail-value"><?php echo htmlspecialchars($shift['duty_type']); ?></span>
                                            </div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <div class="empty-state">
                                    <i class='bx bx-calendar-x'></i>
                                    <h3>No Shifts Scheduled</h3>
                                    <p>You don't have any shifts scheduled for <?php echo $month_names[$current_month]; ?>.</p>
                                </div>
                            <?php endif; ?>
                            
                            <!-- Calendar Navigation for Mobile -->
                            <div style="display: flex; justify-content: space-between; margin-top: 20px;">
                                <a href="?month=<?php echo $prev_month; ?>&year=<?php echo $prev_year; ?>" 
                                   class="btn btn-secondary">
                                    <i class='bx bx-chevron-left'></i> Previous
                                </a>
                                <a href="view_shifts.php" class="btn btn-secondary">
                                    Today
                                </a>
                                <a href="?month=<?php echo $next_month; ?>&year=<?php echo $next_year; ?>" 
                                   class="btn btn-secondary">
                                    Next <i class='bx bx-chevron-right'></i>
                                </a>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Upcoming Shifts -->
                    <?php if (count($upcoming_shifts) > 0): ?>
                    <div class="upcoming-shifts-container">
                        <h3 class="section-title">
                            <i class='bx bx-calendar-star'></i>
                            Upcoming Shifts (Next 7 Days)
                        </h3>
                        
                        <div style="overflow-x: auto;">
                            <table class="shifts-table">
                                <thead>
                                    <tr>
                                        <th>Date</th>
                                        <th>Shift</th>
                                        <th>Time</th>
                                        <th>Unit</th>
                                        <th>Duty</th>
                                        <th>Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($upcoming_shifts as $shift): ?>
                                    <tr onclick="showShiftDetails(<?php echo $shift['id']; ?>)" style="cursor: pointer;">
                                        <td>
                                            <?php echo date('D, M j', strtotime($shift['shift_date'])); ?>
                                            <?php if ($shift['shift_date'] == date('Y-m-d')): ?>
                                                <span style="background: rgba(245, 158, 11, 0.1); color: var(--warning); padding: 2px 8px; border-radius: 12px; font-size: 11px; margin-left: 8px;">Today</span>
                                            <?php endif; ?>
                                        </td>
                                        <td><?php echo $shift['shift_type_display']; ?></td>
                                        <td>
                                            <?php echo date('g:i A', strtotime($shift['start_time'])); ?> - 
                                            <?php echo date('g:i A', strtotime($shift['end_time'])); ?>
                                        </td>
                                        <td>
                                            <?php echo htmlspecialchars($shift['unit_name'] ?? 'Not Assigned'); ?>
                                            <?php if ($shift['unit_code']): ?>
                                                <br><small style="color: var(--text-light);"><?php echo htmlspecialchars($shift['unit_code']); ?></small>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($shift['duty_type']): ?>
                                                <span style="color: var(--info); font-size: 11px;"><?php echo htmlspecialchars($shift['duty_type']); ?></span>
                                            <?php else: ?>
                                                <span style="color: var(--text-light); font-size: 11px;">Not assigned</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <span class="status-badge status-<?php echo $shift['status']; ?>">
                                                <?php echo ucfirst($shift['status']); ?>
                                            </span>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                    <?php endif; ?>
                    
                    <!-- Assignment Information -->
                    <?php if ($assignment): ?>
                    <div class="assignment-card">
                        <h3 class="section-title">
                            <i class='bx bxs-user-badge'></i>
                            Your Current Assignment
                        </h3>
                        
                        <div class="assignment-info">
                            <div class="info-item">
                                <i class='bx bxs-building info-icon'></i>
                                <div class="info-content">
                                    <div class="info-label">Assigned Unit</div>
                                    <div class="info-value"><?php echo htmlspecialchars($assignment['unit_name']); ?></div>
                                </div>
                            </div>
                            
                            <div class="info-item">
                                <i class='bx bx-id-card info-icon'></i>
                                <div class="info-content">
                                    <div class="info-label">Unit Code</div>
                                    <div class="info-value"><?php echo htmlspecialchars($assignment['unit_code']); ?></div>
                                </div>
                            </div>
                            
                            <div class="info-item">
                                <i class='bx bxs-category info-icon'></i>
                                <div class="info-content">
                                    <div class="info-label">Unit Type</div>
                                    <div class="info-value"><?php echo htmlspecialchars($assignment['unit_type']); ?></div>
                                </div>
                            </div>
                            
                            <div class="info-item">
                                <i class='bx bx-map info-icon'></i>
                                <div class="info-content">
                                    <div class="info-label">Location</div>
                                    <div class="info-value"><?php echo htmlspecialchars($assignment['location']); ?></div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>
                    
                    <!-- Print Button -->
                    <div style="text-align: center; margin-top: 30px;">
                        <button class="btn btn-primary" onclick="window.print()">
                            <i class='bx bx-printer'></i> Print Schedule
                        </button>
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
            
            // Shift modal - FIXED CLOSING ISSUE
            const shiftModal = document.getElementById('shift-modal');
            const shiftModalClose = document.getElementById('shift-modal-close');
            const closeShiftModal = document.getElementById('close-shift-modal');
            
            if (shiftModalClose) {
                shiftModalClose.addEventListener('click', function() {
                    shiftModal.classList.remove('active');
                });
            }
            
            if (closeShiftModal) {
                closeShiftModal.addEventListener('click', function() {
                    shiftModal.classList.remove('active');
                });
            }
            
            if (shiftModal) {
                shiftModal.addEventListener('click', function(e) {
                    if (e.target === this) {
                        this.classList.remove('active');
                    }
                });
            }
            
            // Close modal with Escape key
            document.addEventListener('keydown', function(e) {
                if (e.key === 'Escape' && shiftModal.classList.contains('active')) {
                    shiftModal.classList.remove('active');
                }
            });
            
            // Search functionality
            const searchInput = document.getElementById('search-input');
            if (searchInput) {
                searchInput.addEventListener('keyup', function() {
                    const searchTerm = this.value.toLowerCase();
                    // You can implement search functionality here
                    console.log('Searching for:', searchTerm);
                });
            }
        }
        
        function showDayDetails(day) {
            const month = <?php echo $current_month; ?>;
            const year = <?php echo $current_year; ?>;
            const dateStr = `${year}-${month.toString().padStart(2, '0')}-${day.toString().padStart(2, '0')}`;
            
            // Get shifts for this day
            const shiftsForDay = <?php echo json_encode($shifts_by_day); ?>[day] || [];
            
            if (shiftsForDay.length === 0) {
                showShiftDetailsModal(dateStr, []);
            } else {
                showShiftDetailsModal(dateStr, shiftsForDay);
            }
        }
        
        function showShiftDetails(shiftId) {
            // Find the shift in the data
            const allShifts = <?php echo json_encode($shifts); ?>;
            const shift = allShifts.find(s => s.id == shiftId);
            
            if (shift) {
                showShiftDetailsModal(shift.shift_date, [shift]);
            }
        }
        
        function showShiftDetailsModal(dateStr, shifts) {
            const modal = document.getElementById('shift-modal');
            const title = document.getElementById('modal-title');
            const content = document.getElementById('shift-details-content');
            
            if (!modal || !title || !content) return;
            
            const date = new Date(dateStr);
            const formattedDate = date.toLocaleDateString('en-US', { 
                weekday: 'long', 
                year: 'numeric', 
                month: 'long', 
                day: 'numeric' 
            });
            
            title.textContent = `Shifts for ${formattedDate}`;
            
            if (shifts.length === 0) {
                content.innerHTML = `
                    <div class="empty-state">
                        <i class='bx bx-calendar-x'></i>
                        <h3>No Shifts Scheduled</h3>
                        <p>You don't have any shifts scheduled for this date.</p>
                    </div>
                `;
            } else {
                let shiftsHtml = '';
                
                shifts.forEach(shift => {
                    const startTime = shift.start_time ? new Date('1970-01-01T' + shift.start_time + 'Z').toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit', hour12: true }) : '';
                    const endTime = shift.end_time ? new Date('1970-01-01T' + shift.end_time + 'Z').toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit', hour12: true }) : '';
                    
                    // Get priority badge class
                    let priorityBadgeClass = '';
                    let priorityText = '';
                    if (shift.priority === 'primary') {
                        priorityBadgeClass = 'badge-primary';
                        priorityText = 'Primary';
                    } else if (shift.priority === 'secondary') {
                        priorityBadgeClass = 'badge-secondary';
                        priorityText = 'Secondary';
                    } else {
                        priorityBadgeClass = 'badge-support';
                        priorityText = 'Support';
                    }
                    
                    shiftsHtml += `
                        <div class="shift-detail-item">
                            <div class="shift-detail-header">
                                <div class="shift-detail-time">
                                    ${startTime} - ${endTime}
                                </div>
                                <span class="status-badge status-${shift.status}">
                                    ${shift.status.charAt(0).toUpperCase() + shift.status.slice(1)}
                                </span>
                            </div>
                            
                            <div class="shift-detail-info">
                                <div class="detail-item">
                                    <span class="detail-label">Shift Type</span>
                                    <span class="detail-value">${shift.shift_type_display}</span>
                                </div>
                                
                                <div class="detail-item">
                                    <span class="detail-label">Unit</span>
                                    <span class="detail-value">
                                        ${shift.unit_name || 'Not Assigned'}
                                        ${shift.unit_code ? `(${shift.unit_code})` : ''}
                                    </span>
                                </div>
                                
                                <div class="detail-item">
                                    <span class="detail-label">Unit Type</span>
                                    <span class="detail-value">${shift.unit_type || 'Not Specified'}</span>
                                </div>
                                
                                <div class="detail-item">
                                    <span class="detail-label">Location</span>
                                    <span class="detail-value">${shift.location || shift.unit_location || 'Main Station'}</span>
                                </div>
                                
                                ${shift.notes ? `
                                <div class="detail-item" style="grid-column: 1 / -1;">
                                    <span class="detail-label">Shift Notes</span>
                                    <span class="detail-value">${escapeHtml(shift.notes)}</span>
                                </div>
                                ` : ''}
                            </div>
                            
                            ${shift.duty_type ? `
                            <div class="duty-assignment-section">
                                <div class="duty-title">
                                    <i class='bx bxs-clipboard'></i>
                                    Duty Assignment
                                </div>
                                <div class="duty-description">${escapeHtml(shift.duty_description || 'No description available')}</div>
                                
                                <div class="duty-details">
                                    <div class="detail-item">
                                        <span class="detail-label">Duty Type</span>
                                        <span class="detail-value">${escapeHtml(shift.duty_type)}</span>
                                    </div>
                                    
                                    <div class="detail-item">
                                        <span class="detail-label">Priority</span>
                                        <span class="detail-value">
                                            <span class="duty-badge ${priorityBadgeClass}">${priorityText}</span>
                                        </span>
                                    </div>
                                    
                                    ${shift.required_equipment ? `
                                    <div class="detail-item">
                                        <span class="detail-label">Required Equipment</span>
                                        <span class="detail-value">${escapeHtml(shift.required_equipment)}</span>
                                    </div>
                                    ` : ''}
                                    
                                    ${shift.required_training ? `
                                    <div class="detail-item">
                                        <span class="detail-label">Required Training</span>
                                        <span class="detail-value">${escapeHtml(shift.required_training)}</span>
                                    </div>
                                    ` : ''}
                                </div>
                                
                                ${shift.duty_notes ? `
                                <div class="detail-item" style="margin-top: 12px;">
                                    <span class="detail-label">Duty Notes</span>
                                    <span class="detail-value">${escapeHtml(shift.duty_notes)}</span>
                                </div>
                                ` : ''}
                            </div>
                            ` : ''}
                        </div>
                    `;
                });
                
                content.innerHTML = shiftsHtml;
            }
            
            modal.classList.add('active');
        }
        
        function escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
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
    </script>
</body>
</html>