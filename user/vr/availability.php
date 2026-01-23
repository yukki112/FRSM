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

// Get volunteer ID and unit assignment
$volunteer_query = "
    SELECT v.id, v.first_name, v.last_name, v.contact_number, 
           va.unit_id, u.unit_name, u.unit_code
    FROM volunteers v
    LEFT JOIN volunteer_assignments va ON v.id = va.volunteer_id AND va.status = 'Active'
    LEFT JOIN units u ON va.unit_id = u.id
    WHERE v.user_id = ?
";
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
$unit_id = $volunteer['unit_id'];
$unit_name = htmlspecialchars($volunteer['unit_name']);
$unit_code = htmlspecialchars($volunteer['unit_code']);

// Get all volunteers in the same unit
$volunteers_query = "
    SELECT 
        v.id,
        v.first_name,
        v.middle_name,
        v.last_name,
        v.contact_number,
        v.email,
        v.gender,
        v.date_of_birth,
        v.civil_status,
        v.volunteer_status,
        v.status as application_status,
        v.created_at,
        v.address,
        v.education,
        v.specialized_training,
        v.physical_fitness,
        v.languages_spoken,
        v.available_days,
        v.available_hours,
        v.emergency_response,
        u.username,
        va.assignment_date,
        v.skills_basic_firefighting,
        v.skills_first_aid_cpr,
        v.skills_search_rescue,
        v.skills_driving,
        v.driving_license_no,
        v.skills_communication,
        v.skills_mechanical,
        v.skills_logistics,
        v.area_interest_fire_suppression,
        v.area_interest_rescue_operations,
        v.area_interest_ems,
        v.area_interest_disaster_response,
        v.area_interest_admin_logistics
    FROM volunteers v
    LEFT JOIN users u ON v.user_id = u.id
    LEFT JOIN volunteer_assignments va ON v.id = va.volunteer_id AND va.status = 'Active'
    WHERE va.unit_id = ? AND v.status = 'approved'
    GROUP BY v.id, v.first_name, v.last_name, v.contact_number, v.email, 
             v.gender, v.date_of_birth, v.civil_status, v.volunteer_status, 
             v.status, v.created_at, u.username, va.assignment_date,
             v.available_days, v.available_hours, v.emergency_response
    ORDER BY v.last_name, v.first_name
";

$volunteers_stmt = $pdo->prepare($volunteers_query);
$volunteers_stmt->execute([$unit_id]);
$unit_volunteers = $volunteers_stmt->fetchAll();

// Get upcoming shifts for volunteers in the unit
$upcoming_shifts_query = "
    SELECT 
        s.*,
        v.first_name,
        v.last_name,
        v.contact_number,
        v.email
    FROM shifts s
    JOIN volunteers v ON s.volunteer_id = v.id
    LEFT JOIN volunteer_assignments va ON v.id = va.volunteer_id AND va.status = 'Active'
    WHERE va.unit_id = ? 
        AND s.shift_date >= CURDATE()
        AND s.shift_for = 'volunteer'
        AND s.status IN ('scheduled', 'confirmed', 'in_progress')
    ORDER BY s.shift_date, s.start_time
    LIMIT 50
";

$upcoming_shifts_stmt = $pdo->prepare($upcoming_shifts_query);
$upcoming_shifts_stmt->execute([$unit_id]);
$upcoming_shifts = $upcoming_shifts_stmt->fetchAll();

// Get unit information
$unit_query = "SELECT * FROM units WHERE id = ?";
$unit_stmt = $pdo->prepare($unit_query);
$unit_stmt->execute([$unit_id]);
$unit_info = $unit_stmt->fetch();

// Calculate statistics
$total_volunteers = count($unit_volunteers);
$active_volunteers = 0;
$available_today = 0;
$on_duty = 0;

foreach ($unit_volunteers as $vol) {
    if ($vol['volunteer_status'] === 'Active') $active_volunteers++;
    
    // Check if available today (simple check based on available_days)
    $today = date('l'); // Get current day name
    $available_days = explode(',', $vol['available_days']);
    if (in_array($today, $available_days)) {
        $available_today++;
    }
}

// Count volunteers on duty today
$today = date('Y-m-d');
$on_duty_query = "
    SELECT COUNT(DISTINCT s.volunteer_id) as on_duty_count
    FROM shifts s
    JOIN volunteers v ON s.volunteer_id = v.id
    LEFT JOIN volunteer_assignments va ON v.id = va.volunteer_id AND va.status = 'Active'
    WHERE va.unit_id = ? 
        AND s.shift_date = ?
        AND s.status IN ('scheduled', 'confirmed', 'in_progress')
        AND s.shift_for = 'volunteer'
";

$on_duty_stmt = $pdo->prepare($on_duty_query);
$on_duty_stmt->execute([$unit_id, $today]);
$on_duty_result = $on_duty_stmt->fetch();
$on_duty = $on_duty_result['on_duty_count'];

// Handle filters
$search_filter = $_GET['search'] ?? '';
$status_filter = $_GET['status'] ?? 'all';
$availability_filter = $_GET['availability'] ?? 'all';
$day_filter = $_GET['day'] ?? date('l'); // Default to current day

// Filter volunteers based on search criteria
$filtered_volunteers = [];
foreach ($unit_volunteers as $volunteer) {
    $match = true;
    
    // Apply search filter
    if ($search_filter) {
        $search_terms = strtolower($search_filter);
        $full_name = strtolower($volunteer['first_name'] . ' ' . $volunteer['last_name']);
        $contact = strtolower($volunteer['contact_number']);
        $email = strtolower($volunteer['email']);
        
        if (!str_contains($full_name, $search_terms) && 
            !str_contains($contact, $search_terms) && 
            !str_contains($email, $search_terms)) {
            $match = false;
        }
    }
    
    // Apply status filter
    if ($status_filter !== 'all' && $volunteer['volunteer_status'] !== $status_filter) {
        $match = false;
    }
    
    // Apply availability filter
    if ($availability_filter !== 'all') {
        $available_days = explode(',', $volunteer['available_days']);
        if ($availability_filter === 'available' && !in_array($day_filter, $available_days)) {
            $match = false;
        } elseif ($availability_filter === 'unavailable' && in_array($day_filter, $available_days)) {
            $match = false;
        }
    }
    
    if ($match) {
        $filtered_volunteers[] = $volunteer;
    }
}

// Get all days of the week for filter
$days_of_week = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];

// Close statements
$stmt = null;
$volunteer_stmt = null;
$volunteers_stmt = null;
$unit_stmt = null;
$upcoming_shifts_stmt = null;
$on_duty_stmt = null;
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Volunteer Availability - Fire & Rescue Services Management</title>
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

        .btn-sm {
            padding: 6px 12px;
            font-size: 12px;
            border-radius: 6px;
        }

        .volunteer-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));
            gap: 20px;
            margin-bottom: 20px;
        }

        .volunteer-card {
            background: var(--background-color);
            border: 1px solid var(--border-color);
            border-radius: 12px;
            padding: 20px;
            transition: all 0.3s ease;
            position: relative;
        }

        .volunteer-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 16px rgba(0, 0, 0, 0.1);
        }

        .volunteer-card.available {
            border-left: 4px solid var(--success);
        }

        .volunteer-card.unavailable {
            border-left: 4px solid var(--gray-400);
        }

        .volunteer-header {
            display: flex;
            align-items: center;
            gap: 15px;
            margin-bottom: 15px;
            padding-bottom: 15px;
            border-bottom: 1px solid var(--border-color);
        }

        .volunteer-avatar {
            width: 50px;
            height: 50px;
            border-radius: 50%;
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            font-size: 18px;
        }

        .volunteer-info h4 {
            margin: 0 0 5px 0;
            color: var(--text-color);
            font-size: 16px;
        }

        .volunteer-info p {
            margin: 0;
            color: var(--text-light);
            font-size: 12px;
        }

        .volunteer-status {
            position: absolute;
            top: 20px;
            right: 20px;
            padding: 4px 10px;
            border-radius: 12px;
            font-size: 10px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .status-available {
            background: rgba(16, 185, 129, 0.1);
            color: var(--success);
            border: 1px solid rgba(16, 185, 129, 0.2);
        }

        .status-unavailable {
            background: rgba(107, 114, 128, 0.1);
            color: var(--gray-500);
            border: 1px solid rgba(107, 114, 128, 0.2);
        }

        .status-onduty {
            background: rgba(245, 158, 11, 0.1);
            color: var(--warning);
            border: 1px solid rgba(245, 158, 11, 0.2);
        }

        .availability-section {
            margin-bottom: 15px;
        }

        .availability-title {
            font-size: 12px;
            color: var(--text-light);
            margin-bottom: 8px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .availability-days {
            display: flex;
            flex-wrap: wrap;
            gap: 5px;
            margin-bottom: 10px;
        }

        .day-tag {
            padding: 4px 10px;
            border-radius: 6px;
            font-size: 11px;
            font-weight: 600;
            border: 1px solid var(--border-color);
        }

        .day-tag.available {
            background: rgba(16, 185, 129, 0.1);
            color: var(--success);
            border-color: rgba(16, 185, 129, 0.2);
        }

        .day-tag.selected {
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            color: white;
            border-color: var(--primary-color);
        }

        .availability-hours {
            padding: 10px;
            background: var(--card-bg);
            border-radius: 8px;
            border: 1px solid var(--border-color);
        }

        .hours-label {
            font-size: 12px;
            color: var(--text-light);
            margin-bottom: 5px;
        }

        .hours-value {
            font-size: 13px;
            color: var(--text-color);
            font-weight: 500;
        }

        .upcoming-shifts {
            margin-top: 15px;
            padding-top: 15px;
            border-top: 1px solid var(--border-color);
        }

        .shifts-title {
            font-size: 12px;
            color: var(--text-light);
            margin-bottom: 8px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .shift-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 8px 0;
            border-bottom: 1px solid var(--border-color);
        }

        .shift-item:last-child {
            border-bottom: none;
        }

        .shift-date {
            font-size: 12px;
            font-weight: 600;
            color: var(--text-color);
        }

        .shift-time {
            font-size: 11px;
            color: var(--text-light);
        }

        .shift-status {
            padding: 3px 8px;
            border-radius: 4px;
            font-size: 10px;
            font-weight: 600;
        }

        .status-scheduled {
            background: rgba(59, 130, 246, 0.1);
            color: var(--info);
        }

        .status-confirmed {
            background: rgba(16, 185, 129, 0.1);
            color: var(--success);
        }

        .status-in_progress {
            background: rgba(245, 158, 11, 0.1);
            color: var(--warning);
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

        .unit-info-card {
            background: linear-gradient(135deg, #fef2f2 0%, #fee2e2 100%);
            border: 1px solid #fecaca;
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 20px;
        }

        .dark-mode .unit-info-card {
            background: linear-gradient(135deg, #1e293b 0%, #2d3748 100%);
            border-color: #4b5563;
        }

        .unit-title {
            font-size: 18px;
            font-weight: 700;
            color: var(--danger);
            margin-bottom: 10px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .unit-details {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
        }

        .unit-detail {
            display: flex;
            flex-direction: column;
        }

        .unit-label {
            font-size: 12px;
            color: var(--text-light);
            margin-bottom: 4px;
        }

        .unit-value {
            font-weight: 600;
            color: var(--text-color);
            font-size: 14px;
        }

        .calendar-container {
            margin-top: 20px;
        }

        .calendar-grid {
            display: grid;
            grid-template-columns: repeat(7, 1fr);
            gap: 5px;
            margin-bottom: 20px;
        }

        .calendar-day-header {
            text-align: center;
            padding: 10px;
            font-size: 12px;
            font-weight: 600;
            color: var(--text-color);
            background: var(--card-bg);
            border-radius: 6px;
        }

        .calendar-day {
            text-align: center;
            padding: 15px 5px;
            border: 1px solid var(--border-color);
            border-radius: 8px;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .calendar-day:hover {
            background: var(--gray-100);
        }

        .dark-mode .calendar-day:hover {
            background: var(--gray-800);
        }

        .calendar-day.selected {
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            color: white;
            border-color: var(--primary-color);
        }

        .calendar-day-number {
            font-size: 14px;
            font-weight: 600;
            margin-bottom: 5px;
        }

        .calendar-day-availability {
            font-size: 10px;
            opacity: 0.8;
        }

        .badge {
            padding: 4px 8px;
            border-radius: 12px;
            font-size: 10px;
            font-weight: 600;
            display: inline-block;
        }

        .badge-success {
            background: rgba(16, 185, 129, 0.1);
            color: var(--success);
            border: 1px solid rgba(16, 185, 129, 0.2);
        }

        .badge-warning {
            background: rgba(245, 158, 11, 0.1);
            color: var(--warning);
            border: 1px solid rgba(245, 158, 11, 0.2);
        }

        .badge-info {
            background: rgba(59, 130, 246, 0.1);
            color: var(--info);
            border: 1px solid rgba(59, 130, 246, 0.2);
        }

        .badge-light {
            background: rgba(107, 114, 128, 0.1);
            color: var(--gray-500);
            border: 1px solid rgba(107, 114, 128, 0.2);
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
            
            .volunteer-grid {
                grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
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
            
            .volunteer-grid {
                grid-template-columns: 1fr;
            }
            
            .calendar-grid {
                grid-template-columns: repeat(7, 1fr);
                gap: 3px;
            }
            
            .calendar-day {
                padding: 10px 3px;
            }
            
            .calendar-day-number {
                font-size: 12px;
            }
            
            .calendar-day-availability {
                font-size: 9px;
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

        /* Calendar styles */
        .current-day {
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            color: white !important;
            border-color: var(--primary-color) !important;
        }

        .availability-count {
            font-size: 10px;
            margin-top: 3px;
        }

        .today-badge {
            background: var(--primary-color);
            color: white;
            padding: 2px 6px;
            border-radius: 4px;
            font-size: 9px;
            margin-top: 3px;
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
                    <a href="../user_dashboard.php" class="menu-item" id="dashboard-menu">
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
                        <a href="../fir/active_incidents.php" class="submenu-item">Active Incidents</a>
                       
                        <a href="../fir/response_history.php" class="submenu-item">Response History</a>
                    </div>

                      <div class="menu-item" onclick="toggleSubmenu('postincident')">
            <div class="icon-box icon-bg-pink">
                <i class='bx bxs-file-doc icon-pink'></i>
            </div>
            <span class="font-medium">Dispatch Coordination</span>
            <svg class="dropdown-arrow menu-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
            </svg>
        </div>
        <div id="postincident" class="submenu">
            <a href="../dc/suggested_unit.php" class="submenu-item">Suggested Unit</a>
            <a href="../dc/incident_location.php" class="submenu-item">Incident Location</a>
            
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
                    <div id="volunteer" class="submenu active">
                        <a href="volunteer_list.php" class="submenu-item">Volunteer List</a>
                        <a href="roles_skills.php" class="submenu-item">Roles & Skills</a>
                        <a href="availability.php" class="submenu-item active">Availability</a>
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
                        <a href="../ri/equipment_list.php" class="submenu-item">Equipment List</a>
                        <a href="../ri/stock_levels.php" class="submenu-item">Stock Levels</a>
                        <a href="../ri/maintenance_logs.php" class="submenu-item">Maintenance Logs</a>
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
                    <div id="schedule" class="submenu">
                        <a href="../sds/view_shifts.php" class="submenu-item">Shift Calendar</a>
                        <a href="../sds/confirm_availability.php" class="submenu-item">Confirm Availability</a>
                        <a href="../sds/duty_assignments.php" class="submenu-item">Duty Assignments</a>
                        <a href="../sds/attendance_logs.php" class="submenu-item">Attendance Logs</a>
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
                        <h1 class="dashboard-title">Volunteer Availability</h1>
                        <p class="dashboard-subtitle">View availability and schedules of volunteers in your unit</p>
                    </div>
                </div>
                
                <!-- Content Container -->
                <div class="content-container">
                    <!-- Unit Information -->
                    <?php if ($unit_info): ?>
                        <div class="unit-info-card">
                            <h3 class="unit-title">
                                <i class='bx bx-group'></i>
                                Unit Information
                            </h3>
                            <div class="unit-details">
                                <div class="unit-detail">
                                    <span class="unit-label">Unit Name</span>
                                    <span class="unit-value"><?php echo htmlspecialchars($unit_info['unit_name']); ?></span>
                                </div>
                                <div class="unit-detail">
                                    <span class="unit-label">Unit Code</span>
                                    <span class="unit-value"><?php echo htmlspecialchars($unit_info['unit_code']); ?></span>
                                </div>
                                <div class="unit-detail">
                                    <span class="unit-label">Unit Type</span>
                                    <span class="unit-value"><?php echo htmlspecialchars($unit_info['unit_type']); ?></span>
                                </div>
                                <div class="unit-detail">
                                    <span class="unit-label">Location</span>
                                    <span class="unit-value"><?php echo htmlspecialchars($unit_info['location']); ?></span>
                                </div>
                                <div class="unit-detail">
                                    <span class="unit-label">Capacity</span>
                                    <span class="unit-value"><?php echo $unit_info['capacity']; ?> volunteers</span>
                                </div>
                                <div class="unit-detail">
                                    <span class="unit-label">Current Count</span>
                                    <span class="unit-value"><?php echo $unit_info['current_count']; ?> volunteers</span>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>
                    
                    <!-- Availability Statistics -->
                    <div class="section-container">
                        <h3 class="section-title">
                            <i class='bx bx-stats'></i>
                            Availability Overview
                        </h3>
                        
                        <div class="stats-container">
                            <div class="stat-card">
                                <div class="stat-value" style="color: var(--primary-color);">
                                    <?php echo $total_volunteers; ?>
                                </div>
                                <div class="stat-label">Total Volunteers</div>
                            </div>
                            
                            <div class="stat-card">
                                <div class="stat-value" style="color: var(--success);">
                                    <?php echo $active_volunteers; ?>
                                </div>
                                <div class="stat-label">Active</div>
                            </div>
                            
                            <div class="stat-card">
                                <div class="stat-value" style="color: var(--warning);">
                                    <?php echo $on_duty; ?>
                                </div>
                                <div class="stat-label">On Duty Today</div>
                            </div>
                            
                            <div class="stat-card">
                                <div class="stat-value" style="color: var(--info);">
                                    <?php echo $available_today; ?>
                                </div>
                                <div class="stat-label">Available Today</div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Weekly Availability Calendar -->
                    <div class="section-container">
                        <h3 class="section-title">
                            <i class='bx bx-calendar'></i>
                            Weekly Availability Calendar
                        </h3>
                        
                        <div class="calendar-container">
                            <div class="calendar-grid">
                                <?php foreach ($days_of_week as $day): 
                                    $available_on_day = 0;
                                    foreach ($unit_volunteers as $vol) {
                                        $available_days = explode(',', $vol['available_days']);
                                        if (in_array($day, $available_days)) {
                                            $available_on_day++;
                                        }
                                    }
                                    
                                    $is_today = $day === date('l');
                                    $is_selected = $day === $day_filter;
                                ?>
                                    <div class="calendar-day-header">
                                        <?php echo $day; ?>
                                    </div>
                                <?php endforeach; ?>
                                
                                <?php 
                                // Calculate start of week (Monday)
                                $current_date = new DateTime();
                                $day_of_week = $current_date->format('N'); // 1 (Monday) to 7 (Sunday)
                                $current_date->modify('-' . ($day_of_week - 1) . ' days'); // Go back to Monday
                                
                                for ($i = 0; $i < 7; $i++):
                                    $day_date = clone $current_date;
                                    $day_date->modify('+' . $i . ' days');
                                    $day_name = $day_date->format('l');
                                    $day_number = $day_date->format('j');
                                    $is_today = $day_date->format('Y-m-d') === date('Y-m-d');
                                    $is_selected = $day_name === $day_filter;
                                    
                                    $available_on_day = 0;
                                    foreach ($unit_volunteers as $vol) {
                                        $available_days = explode(',', $vol['available_days']);
                                        if (in_array($day_name, $available_days)) {
                                            $available_on_day++;
                                        }
                                    }
                                ?>
                                    <div class="calendar-day <?php echo $is_today ? 'current-day' : ''; ?> <?php echo $is_selected ? 'selected' : ''; ?>" 
                                         onclick="selectDay('<?php echo $day_name; ?>')"
                                         style="cursor: pointer;">
                                        <div class="calendar-day-number"><?php echo $day_number; ?></div>
                                        <div class="calendar-day-availability">
                                            <?php echo $available_on_day; ?> available
                                        </div>
                                        <?php if ($is_today): ?>
                                            <div class="today-badge">Today</div>
                                        <?php endif; ?>
                                    </div>
                                <?php endfor; ?>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Filters -->
                    <div class="filter-container">
                        <form method="GET" action="" id="filter-form">
                            <div class="filter-group">
                                <label class="filter-label">Search</label>
                                <input type="text" name="search" class="filter-input" placeholder="Search by name, contact, or email..." value="<?php echo htmlspecialchars($search_filter); ?>">
                            </div>
                            
                            <div class="filter-group">
                                <label class="filter-label">Status</label>
                                <select name="status" class="filter-select">
                                    <option value="all" <?php echo $status_filter === 'all' ? 'selected' : ''; ?>>All Status</option>
                                    <option value="Active" <?php echo $status_filter === 'Active' ? 'selected' : ''; ?>>Active</option>
                                    <option value="Inactive" <?php echo $status_filter === 'Inactive' ? 'selected' : ''; ?>>Inactive</option>
                                    <option value="New Volunteer" <?php echo $status_filter === 'New Volunteer' ? 'selected' : ''; ?>>New Volunteer</option>
                                </select>
                            </div>
                            
                            <div class="filter-group">
                                <label class="filter-label">Availability</label>
                                <select name="availability" class="filter-select">
                                    <option value="all" <?php echo $availability_filter === 'all' ? 'selected' : ''; ?>>All Volunteers</option>
                                    <option value="available" <?php echo $availability_filter === 'available' ? 'selected' : ''; ?>>Available on <?php echo $day_filter; ?></option>
                                    <option value="unavailable" <?php echo $availability_filter === 'unavailable' ? 'selected' : ''; ?>>Unavailable on <?php echo $day_filter; ?></option>
                                </select>
                            </div>
                            
                            <div class="filter-group">
                                <label class="filter-label">Day of Week</label>
                                <select name="day" class="filter-select" id="day-select">
                                    <?php foreach ($days_of_week as $day): ?>
                                        <option value="<?php echo $day; ?>" <?php echo $day_filter === $day ? 'selected' : ''; ?>>
                                            <?php echo $day; ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div class="filter-actions">
                                <button type="submit" class="btn btn-primary">
                                    <i class='bx bx-filter-alt'></i> Apply Filters
                                </button>
                                <a href="availability.php" class="btn btn-secondary">
                                    <i class='bx bx-reset'></i> Clear Filters
                                </a>
                            </div>
                        </form>
                    </div>
                    
                    <!-- Volunteers Availability -->
                    <div class="section-container">
                        <h3 class="section-title">
                            <i class='bx bx-user-check'></i>
                            Volunteer Availability for <?php echo $day_filter; ?>
                            <?php if (count($filtered_volunteers) > 0): ?>
                                <span class="badge badge-info"><?php echo count($filtered_volunteers); ?> volunteers</span>
                            <?php endif; ?>
                        </h3>
                        
                        <?php if (count($filtered_volunteers) > 0): ?>
                            <div class="volunteer-grid">
                                <?php foreach ($filtered_volunteers as $vol): 
                                    $full_name = htmlspecialchars($vol['first_name'] . ' ' . $vol['last_name']);
                                    $initials = strtoupper(substr($vol['first_name'], 0, 1) . substr($vol['last_name'], 0, 1));
                                    
                                    // Check if available on selected day
                                    $available_days = explode(',', $vol['available_days']);
                                    $is_available = in_array($day_filter, $available_days);
                                    $availability_class = $is_available ? 'available' : 'unavailable';
                                    $status_class = $is_available ? 'status-available' : 'status-unavailable';
                                    $status_text = $is_available ? 'Available' : 'Unavailable';
                                    
                                    // Check if on duty today
                                    $on_duty_today = false;
                                    foreach ($upcoming_shifts as $shift) {
                                        if ($shift['volunteer_id'] == $vol['id'] && 
                                            $shift['shift_date'] == date('Y-m-d') && 
                                            $shift['status'] !== 'cancelled') {
                                            $on_duty_today = true;
                                            if ($shift['status'] == 'in_progress') {
                                                $status_class = 'status-onduty';
                                                $status_text = 'On Duty';
                                            }
                                            break;
                                        }
                                    }
                                ?>
                                    <div class="volunteer-card <?php echo $availability_class; ?>">
                                        <div class="volunteer-header">
                                            <div class="volunteer-avatar"><?php echo $initials; ?></div>
                                            <div class="volunteer-info">
                                                <h4><?php echo $full_name; ?></h4>
                                                <p><?php echo htmlspecialchars($vol['email']); ?></p>
                                            </div>
                                            <span class="volunteer-status <?php echo $status_class; ?>">
                                                <?php echo $status_text; ?>
                                            </span>
                                        </div>
                                        
                                        <div class="availability-section">
                                            <div class="availability-title">Available Days</div>
                                            <div class="availability-days">
                                                <?php foreach ($days_of_week as $day): 
                                                    $is_available_day = in_array($day, $available_days);
                                                    $is_selected_day = $day === $day_filter;
                                                ?>
                                                    <span class="day-tag <?php echo $is_available_day ? 'available' : ''; ?> <?php echo $is_selected_day ? 'selected' : ''; ?>">
                                                        <?php echo substr($day, 0, 3); ?>
                                                    </span>
                                                <?php endforeach; ?>
                                            </div>
                                        </div>
                                        
                                        <div class="availability-section">
                                            <div class="availability-title">Available Hours</div>
                                            <div class="availability-hours">
                                                <div class="hours-label">Preferred Hours:</div>
                                                <div class="hours-value"><?php echo $vol['available_hours']; ?></div>
                                                <?php if ($vol['emergency_response'] == 'Yes'): ?>
                                                    <div class="hours-label" style="margin-top: 8px;">Emergency Response:</div>
                                                    <div class="hours-value" style="color: var(--success); font-weight: 600;">
                                                        <i class='bx bx-check-circle'></i> Available for emergencies
                                                    </div>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                        
                                        <?php 
                                        // Get upcoming shifts for this volunteer
                                        $volunteer_shifts = array_filter($upcoming_shifts, function($shift) use ($vol) {
                                            return $shift['volunteer_id'] == $vol['id'];
                                        });
                                        ?>
                                        
                                        <?php if (!empty($volunteer_shifts)): ?>
                                            <div class="upcoming-shifts">
                                                <div class="shifts-title">Upcoming Shifts</div>
                                                <?php 
                                                $count = 0;
                                                foreach ($volunteer_shifts as $shift):
                                                    if ($count >= 3) break;
                                                    $shift_date = new DateTime($shift['shift_date']);
                                                    $start_time = new DateTime($shift['start_time']);
                                                    $end_time = new DateTime($shift['end_time']);
                                                ?>
                                                    <div class="shift-item">
                                                        <div>
                                                            <div class="shift-date">
                                                                <?php echo $shift_date->format('M j'); ?>
                                                            </div>
                                                            <div class="shift-time">
                                                                <?php echo $start_time->format('g:i A') . ' - ' . $end_time->format('g:i A'); ?>
                                                            </div>
                                                        </div>
                                                        <span class="shift-status status-<?php echo $shift['status']; ?>">
                                                            <?php echo ucfirst(str_replace('_', ' ', $shift['status'])); ?>
                                                        </span>
                                                    </div>
                                                    <?php $count++; ?>
                                                <?php endforeach; ?>
                                            </div>
                                        <?php endif; ?>
                                        
                                        <div style="margin-top: 15px; display: flex; justify-content: space-between; align-items: center;">
                                            <div>
                                                <span style="font-size: 11px; color: var(--text-light);">
                                                    <i class='bx bx-phone'></i> <?php echo htmlspecialchars($vol['contact_number']); ?>
                                                </span>
                                            </div>
                                            <?php if ($on_duty_today): ?>
                                                <span class="badge badge-warning" style="font-size: 10px;">
                                                    <i class='bx bx-time'></i> On Duty Today
                                                </span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <div class="empty-state">
                                <i class='bx bx-user-x'></i>
                                <h3>No Volunteers Found</h3>
                                <p>No volunteers match your search criteria or there are no volunteers in your unit.</p>
                                <?php if ($search_filter || $status_filter !== 'all' || $availability_filter !== 'all'): ?>
                                    <div style="margin-top: 20px;">
                                        <a href="availability.php" class="btn btn-primary">
                                            <i class='bx bx-reset'></i> Clear Filters
                                        </a>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                    
                    <!-- Upcoming Shifts for Unit -->
                    <div class="section-container">
                        <h3 class="section-title">
                            <i class='bx bx-calendar-event'></i>
                            Upcoming Shifts for Your Unit
                        </h3>
                        
                        <?php if (!empty($upcoming_shifts)): ?>
                            <div style="overflow-x: auto;">
                                <table style="width: 100%; border-collapse: collapse;">
                                    <thead>
                                        <tr>
                                            <th style="padding: 12px; text-align: left; border-bottom: 2px solid var(--border-color); font-size: 12px; color: var(--text-light);">Date</th>
                                            <th style="padding: 12px; text-align: left; border-bottom: 2px solid var(--border-color); font-size: 12px; color: var(--text-light);">Volunteer</th>
                                            <th style="padding: 12px; text-align: left; border-bottom: 2px solid var(--border-color); font-size: 12px; color: var(--text-light);">Time</th>
                                            <th style="padding: 12px; text-align: left; border-bottom: 2px solid var(--border-color); font-size: 12px; color: var(--text-light);">Status</th>
                                            <th style="padding: 12px; text-align: left; border-bottom: 2px solid var(--border-color); font-size: 12px; color: var(--text-light);">Location</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($upcoming_shifts as $shift): 
                                            $shift_date = new DateTime($shift['shift_date']);
                                            $start_time = new DateTime($shift['start_time']);
                                            $end_time = new DateTime($shift['end_time']);
                                            $volunteer_name = htmlspecialchars($shift['first_name'] . ' ' . $shift['last_name']);
                                        ?>
                                            <tr style="border-bottom: 1px solid var(--border-color);">
                                                <td style="padding: 12px; font-size: 13px;">
                                                    <?php echo $shift_date->format('D, M j, Y'); ?>
                                                </td>
                                                <td style="padding: 12px; font-size: 13px;">
                                                    <div style="display: flex; align-items: center; gap: 8px;">
                                                        <div style="width: 30px; height: 30px; border-radius: 50%; background: linear-gradient(135deg, var(--primary-color), var(--secondary-color)); color: white; display: flex; align-items: center; justify-content: center; font-weight: bold; font-size: 12px;">
                                                            <?php echo strtoupper(substr($shift['first_name'], 0, 1) . substr($shift['last_name'], 0, 1)); ?>
                                                        </div>
                                                        <div>
                                                            <div style="font-weight: 600;"><?php echo $volunteer_name; ?></div>
                                                            <div style="font-size: 11px; color: var(--text-light);"><?php echo htmlspecialchars($shift['contact_number']); ?></div>
                                                        </div>
                                                    </div>
                                                </td>
                                                <td style="padding: 12px; font-size: 13px;">
                                                    <?php echo $start_time->format('g:i A') . ' - ' . $end_time->format('g:i A'); ?>
                                                </td>
                                                <td style="padding: 12px;">
                                                    <span class="shift-status status-<?php echo $shift['status']; ?>" style="display: inline-block;">
                                                        <?php echo ucfirst(str_replace('_', ' ', $shift['status'])); ?>
                                                    </span>
                                                </td>
                                                <td style="padding: 12px; font-size: 13px;">
                                                    <?php echo htmlspecialchars($shift['location'] ?: 'Main Station'); ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php else: ?>
                            <div class="empty-state" style="padding: 20px;">
                                <i class='bx bx-calendar-x'></i>
                                <h3>No Upcoming Shifts</h3>
                                <p>There are no scheduled shifts for volunteers in your unit.</p>
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
            
            // Handle search
            const searchInput = document.getElementById('search-input');
            if (searchInput) {
                searchInput.addEventListener('keyup', function() {
                    const searchTerm = this.value.toLowerCase();
                    const volunteerCards = document.querySelectorAll('.volunteer-card');
                    
                    volunteerCards.forEach(card => {
                        const name = card.querySelector('h4').textContent.toLowerCase();
                        const email = card.querySelector('.volunteer-info p').textContent.toLowerCase();
                        
                        if (name.includes(searchTerm) || email.includes(searchTerm)) {
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
            const statusFilter = document.querySelector('select[name="status"]');
            const availabilityFilter = document.querySelector('select[name="availability"]');
            const daySelect = document.getElementById('day-select');
            
            if (statusFilter) statusFilter.addEventListener('change', function() { 
                if (document.querySelector('input[name="search"]').value === '' && 
                    availabilityFilter.value === 'all') {
                    document.getElementById('filter-form').submit();
                }
            });
            
            if (availabilityFilter) availabilityFilter.addEventListener('change', function() { 
                if (document.querySelector('input[name="search"]').value === '' && 
                    statusFilter.value === 'all') {
                    document.getElementById('filter-form').submit();
                }
            });
            
            if (daySelect) daySelect.addEventListener('change', function() {
                document.getElementById('filter-form').submit();
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
        
        function selectDay(day) {
            document.getElementById('day-select').value = day;
            document.getElementById('filter-form').submit();
        }
    </script>
</body>
</html>