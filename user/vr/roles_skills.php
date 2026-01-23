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

// Get unit information
$unit_query = "SELECT * FROM units WHERE id = ?";
$unit_stmt = $pdo->prepare($unit_query);
$unit_stmt->execute([$unit_id]);
$unit_info = $unit_stmt->fetch();

// Get all volunteers in the same unit with their skills and roles
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
        v.volunteer_status,
        v.education,
        v.specialized_training,
        v.physical_fitness,
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
        v.area_interest_admin_logistics,
        COUNT(DISTINCT s.id) as total_shifts,
        COUNT(DISTINCT CASE WHEN s.status = 'completed' THEN s.id END) as completed_shifts
    FROM volunteers v
    LEFT JOIN volunteer_assignments va ON v.id = va.volunteer_id AND va.status = 'Active'
    LEFT JOIN shifts s ON v.id = s.volunteer_id
    WHERE va.unit_id = ? AND v.status = 'approved'
    GROUP BY v.id, v.first_name, v.last_name, v.contact_number, v.email, 
             v.gender, v.date_of_birth, v.volunteer_status,
             v.education, v.specialized_training, v.physical_fitness,
             v.skills_basic_firefighting, v.skills_first_aid_cpr, v.skills_search_rescue,
             v.skills_driving, v.skills_communication, v.skills_mechanical, 
             v.skills_logistics, v.area_interest_fire_suppression, 
             v.area_interest_rescue_operations, v.area_interest_ems, 
             v.area_interest_disaster_response, v.area_interest_admin_logistics
    ORDER BY v.last_name, v.first_name
";

$volunteers_stmt = $pdo->prepare($volunteers_query);
$volunteers_stmt->execute([$unit_id]);
$unit_volunteers = $volunteers_stmt->fetchAll();

// Calculate statistics
$total_volunteers = count($unit_volunteers);
$active_volunteers = 0;
$male_count = 0;
$female_count = 0;

// Count volunteers by skills
$firefighting_skills = 0;
$first_aid_skills = 0;
$rescue_skills = 0;
$driving_skills = 0;
$communication_skills = 0;
$mechanical_skills = 0;
$logistics_skills = 0;

// Count volunteers by areas of interest
$fire_suppression_interest = 0;
$rescue_ops_interest = 0;
$ems_interest = 0;
$disaster_response_interest = 0;
$admin_logistics_interest = 0;

foreach ($unit_volunteers as $vol) {
    if ($vol['volunteer_status'] === 'Active') $active_volunteers++;
    if ($vol['gender'] === 'Male') $male_count++;
    if ($vol['gender'] === 'Female') $female_count++;
    
    // Count skills
    if ($vol['skills_basic_firefighting']) $firefighting_skills++;
    if ($vol['skills_first_aid_cpr']) $first_aid_skills++;
    if ($vol['skills_search_rescue']) $rescue_skills++;
    if ($vol['skills_driving']) $driving_skills++;
    if ($vol['skills_communication']) $communication_skills++;
    if ($vol['skills_mechanical']) $mechanical_skills++;
    if ($vol['skills_logistics']) $logistics_skills++;
    
    // Count interests
    if ($vol['area_interest_fire_suppression']) $fire_suppression_interest++;
    if ($vol['area_interest_rescue_operations']) $rescue_ops_interest++;
    if ($vol['area_interest_ems']) $ems_interest++;
    if ($vol['area_interest_disaster_response']) $disaster_response_interest++;
    if ($vol['area_interest_admin_logistics']) $admin_logistics_interest++;
}

// Close statements
$stmt = null;
$volunteer_stmt = null;
$volunteers_stmt = null;
$unit_stmt = null;
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Roles & Skills - Fire & Rescue Services Management</title>
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

        .skills-summary {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .skill-category {
            background: var(--background-color);
            border: 1px solid var(--border-color);
            border-radius: 12px;
            padding: 20px;
        }

        .skill-category-title {
            font-size: 16px;
            font-weight: 700;
            color: var(--text-color);
            margin-bottom: 15px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .skill-category-title i {
            color: var(--primary-color);
        }

        .skill-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 10px;
            padding-bottom: 10px;
            border-bottom: 1px solid var(--border-color);
        }

        .skill-item:last-child {
            border-bottom: none;
            margin-bottom: 0;
        }

        .skill-name {
            color: var(--text-color);
            font-size: 14px;
        }

        .skill-count {
            background: var(--primary-color);
            color: white;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            min-width: 40px;
            text-align: center;
        }

        .volunteer-skills-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));
            gap: 20px;
        }

        .volunteer-card {
            background: var(--background-color);
            border: 1px solid var(--border-color);
            border-radius: 12px;
            padding: 20px;
            transition: all 0.3s ease;
        }

        .volunteer-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 16px rgba(0, 0, 0, 0.1);
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
            padding: 4px 10px;
            border-radius: 12px;
            font-size: 10px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            display: inline-block;
        }

        .status-active {
            background: rgba(16, 185, 129, 0.1);
            color: var(--success);
            border: 1px solid rgba(16, 185, 129, 0.2);
        }

        .status-inactive {
            background: rgba(107, 114, 128, 0.1);
            color: var(--gray-500);
            border: 1px solid rgba(107, 114, 128, 0.2);
        }

        .status-new {
            background: rgba(59, 130, 246, 0.1);
            color: var(--info);
            border: 1px solid rgba(59, 130, 246, 0.2);
        }

        .skills-section {
            margin-top: 15px;
        }

        .skills-title {
            font-size: 12px;
            color: var(--text-light);
            margin-bottom: 10px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .skills-tags {
            display: flex;
            flex-wrap: wrap;
            gap: 6px;
            margin-bottom: 15px;
        }

        .skill-tag {
            padding: 4px 10px;
            border-radius: 6px;
            font-size: 11px;
            font-weight: 600;
        }

        .skill-tag.fire {
            background: rgba(220, 38, 38, 0.1);
            color: var(--danger);
            border: 1px solid rgba(220, 38, 38, 0.2);
        }

        .skill-tag.medical {
            background: rgba(16, 185, 129, 0.1);
            color: var(--success);
            border: 1px solid rgba(16, 185, 129, 0.2);
        }

        .skill-tag.rescue {
            background: rgba(245, 158, 11, 0.1);
            color: var(--warning);
            border: 1px solid rgba(245, 158, 11, 0.2);
        }

        .skill-tag.driving {
            background: rgba(139, 92, 246, 0.1);
            color: var(--purple);
            border: 1px solid rgba(139, 92, 246, 0.2);
        }

        .skill-tag.other {
            background: rgba(59, 130, 246, 0.1);
            color: var(--info);
            border: 1px solid rgba(59, 130, 246, 0.2);
        }

        .interests-section {
            margin-top: 15px;
            padding-top: 15px;
            border-top: 1px solid var(--border-color);
        }

        .interests-title {
            font-size: 12px;
            color: var(--text-light);
            margin-bottom: 10px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .interests-tags {
            display: flex;
            flex-wrap: wrap;
            gap: 6px;
        }

        .role-badge {
            padding: 6px 12px;
            border-radius: 6px;
            font-size: 12px;
            font-weight: 600;
            margin-top: 5px;
            display: inline-block;
        }

        .role-primary {
            background: rgba(220, 38, 38, 0.15);
            color: var(--danger);
            border: 1px solid rgba(220, 38, 38, 0.3);
        }

        .role-secondary {
            background: rgba(59, 130, 246, 0.15);
            color: var(--info);
            border: 1px solid rgba(59, 130, 246, 0.3);
        }

        .role-support {
            background: rgba(16, 185, 129, 0.15);
            color: var(--success);
            border: 1px solid rgba(16, 185, 129, 0.3);
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

        .chart-container {
            background: var(--background-color);
            border: 1px solid var(--border-color);
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 20px;
        }

        .chart-title {
            font-size: 16px;
            font-weight: 700;
            color: var(--text-color);
            margin-bottom: 15px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .progress-bar {
            height: 8px;
            background: var(--gray-200);
            border-radius: 4px;
            margin-bottom: 5px;
            overflow: hidden;
        }

        .progress-fill {
            height: 100%;
            border-radius: 4px;
        }

        .progress-label {
            display: flex;
            justify-content: space-between;
            margin-bottom: 15px;
        }

        .progress-name {
            font-size: 12px;
            color: var(--text-color);
        }

        .progress-value {
            font-size: 12px;
            font-weight: 600;
            color: var(--text-color);
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
            
            .volunteer-skills-grid {
                grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            }
            
            .skills-summary {
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
                grid-template-columns: repeat(2, 1fr);
            }
            
            .volunteer-skills-grid {
                grid-template-columns: 1fr;
            }
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
                        <a href="../fir/incident_reports.php" class="submenu-item">Incident Reports</a>
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
                        <a href="roles_skills.php" class="submenu-item active">Roles & Skills</a>
                        <a href="availability.php" class="submenu-item">Availability</a>
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
                            <input type="text" placeholder="Search skills or volunteers..." class="search-input" id="search-input">
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
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Dashboard Content -->
            <div class="dashboard-content">
                <div class="dashboard-header">
                    <div>
                        <h1 class="dashboard-title">Roles & Skills</h1>
                        <p class="dashboard-subtitle">View skills, expertise, and roles of volunteers in your unit</p>
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
                                    <span class="unit-label">Specialization</span>
                                    <span class="unit-value">
                                        <?php 
                                        $unit_type = $unit_info['unit_type'];
                                        if ($unit_type === 'Fire') {
                                            echo 'Firefighting & Suppression';
                                        } elseif ($unit_type === 'Rescue') {
                                            echo 'Search & Rescue Operations';
                                        } elseif ($unit_type === 'EMS') {
                                            echo 'Emergency Medical Services';
                                        } elseif ($unit_type === 'Logistics') {
                                            echo 'Support & Logistics';
                                        } else {
                                            echo 'Command & Control';
                                        }
                                        ?>
                                    </span>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>
                    
                    <!-- Unit Statistics -->
                    <div class="section-container">
                        <h3 class="section-title">
                            <i class='bx bx-stats'></i>
                            Unit Skill Statistics
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
                                <div class="stat-label">Active Volunteers</div>
                            </div>
                            
                            <div class="stat-card">
                                <div class="stat-value" style="color: var(--danger);">
                                    <?php echo $firefighting_skills; ?>
                                </div>
                                <div class="stat-label">Firefighting Skills</div>
                            </div>
                            
                            <div class="stat-card">
                                <div class="stat-value" style="color: var(--success);">
                                    <?php echo $first_aid_skills; ?>
                                </div>
                                <div class="stat-label">First Aid/CPR</div>
                            </div>
                            
                            <div class="stat-card">
                                <div class="stat-value" style="color: var(--warning);">
                                    <?php echo $rescue_skills; ?>
                                </div>
                                <div class="stat-label">Rescue Skills</div>
                            </div>
                            
                            <div class="stat-card">
                                <div class="stat-value" style="color: var(--purple);">
                                    <?php echo $driving_skills; ?>
                                </div>
                                <div class="stat-label">Driving Skills</div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Skills Distribution -->
                    <div class="section-container">
                        <h3 class="section-title">
                            <i class='bx bx-pie-chart-alt'></i>
                            Skills Distribution
                        </h3>
                        
                        <div class="skills-summary">
                            <div class="skill-category">
                                <h4 class="skill-category-title">
                                    <i class='bx bx-shield'></i>
                                    Core Emergency Skills
                                </h4>
                                <?php
                                $core_skills = [
                                    ['name' => 'Basic Firefighting', 'count' => $firefighting_skills, 'color' => '#dc2626'],
                                    ['name' => 'First Aid/CPR', 'count' => $first_aid_skills, 'color' => '#10b981'],
                                    ['name' => 'Search & Rescue', 'count' => $rescue_skills, 'color' => '#f59e0b'],
                                ];
                                ?>
                                <?php foreach ($core_skills as $skill): ?>
                                    <div class="skill-item">
                                        <span class="skill-name"><?php echo $skill['name']; ?></span>
                                        <span class="skill-count" style="background-color: <?php echo $skill['color']; ?>">
                                            <?php echo $skill['count']; ?>
                                        </span>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                            
                            <div class="skill-category">
                                <h4 class="skill-category-title">
                                    <i class='bx bx-cog'></i>
                                    Support Skills
                                </h4>
                                <?php
                                $support_skills = [
                                    ['name' => 'Driving', 'count' => $driving_skills, 'color' => '#8b5cf6'],
                                    ['name' => 'Communication', 'count' => $communication_skills, 'color' => '#3b82f6'],
                                    ['name' => 'Mechanical', 'count' => $mechanical_skills, 'color' => '#0ea5e9'],
                                    ['name' => 'Logistics', 'count' => $logistics_skills, 'color' => '#06b6d4'],
                                ];
                                ?>
                                <?php foreach ($support_skills as $skill): ?>
                                    <div class="skill-item">
                                        <span class="skill-name"><?php echo $skill['name']; ?></span>
                                        <span class="skill-count" style="background-color: <?php echo $skill['color']; ?>">
                                            <?php echo $skill['count']; ?>
                                        </span>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                            
                            <div class="skill-category">
                                <h4 class="skill-category-title">
                                    <i class='bx bx-target-lock'></i>
                                    Areas of Interest
                                </h4>
                                <?php
                                $interest_areas = [
                                    ['name' => 'Fire Suppression', 'count' => $fire_suppression_interest, 'color' => '#dc2626'],
                                    ['name' => 'Rescue Operations', 'count' => $rescue_ops_interest, 'color' => '#f59e0b'],
                                    ['name' => 'EMS', 'count' => $ems_interest, 'color' => '#10b981'],
                                    ['name' => 'Disaster Response', 'count' => $disaster_response_interest, 'color' => '#3b82f6'],
                                    ['name' => 'Admin & Logistics', 'count' => $admin_logistics_interest, 'color' => '#8b5cf6'],
                                ];
                                ?>
                                <?php foreach ($interest_areas as $area): ?>
                                    <div class="skill-item">
                                        <span class="skill-name"><?php echo $area['name']; ?></span>
                                        <span class="skill-count" style="background-color: <?php echo $area['color']; ?>">
                                            <?php echo $area['count']; ?>
                                        </span>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Volunteer Skills Grid -->
                    <div class="section-container">
                        <h3 class="section-title">
                            <i class='bx bx-user-check'></i>
                            Volunteer Skills & Roles
                            <?php if (count($unit_volunteers) > 0): ?>
                                <span style="font-size: 12px; color: var(--text-light); margin-left: 10px;">
                                    <?php echo count($unit_volunteers); ?> volunteers
                                </span>
                            <?php endif; ?>
                        </h3>
                        
                        <?php if (count($unit_volunteers) > 0): ?>
                            <div class="volunteer-skills-grid" id="volunteers-grid">
                                <?php foreach ($unit_volunteers as $vol): 
                                    $full_name = htmlspecialchars($vol['first_name'] . ' ' . $vol['last_name']);
                                    $initials = strtoupper(substr($vol['first_name'], 0, 1) . substr($vol['last_name'], 0, 1));
                                    $status_class = 'status-' . strtolower(str_replace(' ', '_', $vol['volunteer_status']));
                                    $age = date_diff(date_create($vol['date_of_birth']), date_create('today'))->y;
                                    
                                    // Determine primary role based on skills
                                    $primary_skill = '';
                                    $role_class = 'role-secondary';
                                    
                                    if ($vol['skills_basic_firefighting']) {
                                        $primary_skill = 'Firefighter';
                                        $role_class = 'role-primary';
                                    } elseif ($vol['skills_first_aid_cpr']) {
                                        $primary_skill = 'Medical Responder';
                                        $role_class = 'role-primary';
                                    } elseif ($vol['skills_search_rescue']) {
                                        $primary_skill = 'Rescue Specialist';
                                        $role_class = 'role-primary';
                                    } elseif ($vol['skills_driving']) {
                                        $primary_skill = 'Driver/Operator';
                                        $role_class = 'role-secondary';
                                    } elseif ($vol['skills_communication'] || $vol['skills_logistics']) {
                                        $primary_skill = 'Support Staff';
                                        $role_class = 'role-support';
                                    } else {
                                        $primary_skill = 'General Volunteer';
                                        $role_class = 'role-support';
                                    }
                                ?>
                                    <div class="volunteer-card" data-name="<?php echo strtolower($full_name); ?>" data-skills="<?php 
                                        $skills = [];
                                        if ($vol['skills_basic_firefighting']) $skills[] = 'firefighting';
                                        if ($vol['skills_first_aid_cpr']) $skills[] = 'firstaid';
                                        if ($vol['skills_search_rescue']) $skills[] = 'rescue';
                                        if ($vol['skills_driving']) $skills[] = 'driving';
                                        if ($vol['skills_communication']) $skills[] = 'communication';
                                        if ($vol['skills_mechanical']) $skills[] = 'mechanical';
                                        if ($vol['skills_logistics']) $skills[] = 'logistics';
                                        echo htmlspecialchars(implode(' ', $skills));
                                    ?>">
                                        <div class="volunteer-header">
                                            <div class="volunteer-avatar"><?php echo $initials; ?></div>
                                            <div class="volunteer-info">
                                                <h4><?php echo $full_name; ?></h4>
                                                <p><?php echo htmlspecialchars($vol['email']); ?></p>
                                            </div>
                                            <span class="volunteer-status <?php echo $status_class; ?>">
                                                <?php echo $vol['volunteer_status']; ?>
                                            </span>
                                        </div>
                                        
                                        <div class="skills-section">
                                            <div class="skills-title">Skills & Certifications</div>
                                            <div class="skills-tags">
                                                <?php if ($vol['skills_basic_firefighting']): ?>
                                                    <span class="skill-tag fire">Firefighting</span>
                                                <?php endif; ?>
                                                <?php if ($vol['skills_first_aid_cpr']): ?>
                                                    <span class="skill-tag medical">First Aid/CPR</span>
                                                <?php endif; ?>
                                                <?php if ($vol['skills_search_rescue']): ?>
                                                    <span class="skill-tag rescue">Search & Rescue</span>
                                                <?php endif; ?>
                                                <?php if ($vol['skills_driving']): ?>
                                                    <span class="skill-tag driving">Driving</span>
                                                <?php endif; ?>
                                                <?php if ($vol['skills_communication']): ?>
                                                    <span class="skill-tag other">Communication</span>
                                                <?php endif; ?>
                                                <?php if ($vol['skills_mechanical']): ?>
                                                    <span class="skill-tag other">Mechanical</span>
                                                <?php endif; ?>
                                                <?php if ($vol['skills_logistics']): ?>
                                                    <span class="skill-tag other">Logistics</span>
                                                <?php endif; ?>
                                            </div>
                                            
                                            <div class="skills-title" style="margin-top: 15px;">Primary Role</div>
                                            <span class="role-badge <?php echo $role_class; ?>">
                                                <?php echo $primary_skill; ?>
                                            </span>
                                        </div>
                                        
                                        <div class="interests-section">
                                            <div class="interests-title">Areas of Interest</div>
                                            <div class="interests-tags">
                                                <?php if ($vol['area_interest_fire_suppression']): ?>
                                                    <span class="skill-tag fire">Fire Suppression</span>
                                                <?php endif; ?>
                                                <?php if ($vol['area_interest_rescue_operations']): ?>
                                                    <span class="skill-tag rescue">Rescue Operations</span>
                                                <?php endif; ?>
                                                <?php if ($vol['area_interest_ems']): ?>
                                                    <span class="skill-tag medical">EMS</span>
                                                <?php endif; ?>
                                                <?php if ($vol['area_interest_disaster_response']): ?>
                                                    <span class="skill-tag other">Disaster Response</span>
                                                <?php endif; ?>
                                                <?php if ($vol['area_interest_admin_logistics']): ?>
                                                    <span class="skill-tag other">Admin & Logistics</span>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                        
                                        <div style="margin-top: 15px; padding-top: 15px; border-top: 1px solid var(--border-color); font-size: 12px; color: var(--text-light);">
                                            <div style="display: flex; justify-content: space-between;">
                                                <span>
                                                    <i class='bx bx-calendar'></i> 
                                                    <?php echo $age; ?> years old
                                                </span>
                                                <span>
                                                    <i class='bx bx-male-female'></i> 
                                                    <?php echo htmlspecialchars($vol['gender']); ?>
                                                </span>
                                            </div>
                                            <?php if ($vol['specialized_training']): ?>
                                                <div style="margin-top: 8px;">
                                                    <i class='bx bx-certification'></i> 
                                                    <?php echo htmlspecialchars(substr($vol['specialized_training'], 0, 50)); ?>
                                                    <?php if (strlen($vol['specialized_training']) > 50): ?>...<?php endif; ?>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <div class="empty-state">
                                <i class='bx bx-user-x'></i>
                                <h3>No Volunteers in Unit</h3>
                                <p>There are currently no volunteers assigned to your unit.</p>
                            </div>
                        <?php endif; ?>
                    </div>
                    
                    <!-- Unit Capabilities -->
                    <div class="section-container">
                        <h3 class="section-title">
                            <i class='bx bx-bar-chart-alt'></i>
                            Unit Capabilities Analysis
                        </h3>
                        
                        <div class="chart-container">
                            <div class="chart-title">Skill Coverage</div>
                            <?php
                            $skill_coverage = [
                                ['name' => 'Firefighting', 'value' => $firefighting_skills, 'max' => $total_volunteers, 'color' => '#dc2626'],
                                ['name' => 'Medical Response', 'value' => $first_aid_skills, 'max' => $total_volunteers, 'color' => '#10b981'],
                                ['name' => 'Search & Rescue', 'value' => $rescue_skills, 'max' => $total_volunteers, 'color' => '#f59e0b'],
                                ['name' => 'Driving', 'value' => $driving_skills, 'max' => $total_volunteers, 'color' => '#8b5cf6'],
                            ];
                            ?>
                            <?php foreach ($skill_coverage as $skill): 
                                $percentage = $total_volunteers > 0 ? round(($skill['value'] / $skill['max']) * 100) : 0;
                            ?>
                                <div class="progress-label">
                                    <span class="progress-name"><?php echo $skill['name']; ?></span>
                                    <span class="progress-value"><?php echo $skill['value']; ?>/<?php echo $skill['max']; ?> (<?php echo $percentage; ?>%)</span>
                                </div>
                                <div class="progress-bar">
                                    <div class="progress-fill" style="width: <?php echo $percentage; ?>%; background-color: <?php echo $skill['color']; ?>;"></div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        
                        <div style="margin-top: 20px; padding: 15px; background: rgba(245, 158, 11, 0.05); border-radius: 8px; border-left: 3px solid var(--warning);">
                            <h4 style="margin: 0 0 10px 0; color: var(--warning);">Unit Readiness Assessment:</h4>
                            <ul style="margin: 0; padding-left: 20px; color: var(--text-color); font-size: 13px;">
                                <?php
                                $assessment = [];
                                
                                if ($firefighting_skills >= 3) {
                                    $assessment[] = "✓ Firefighting capability: <strong>Good</strong> ($firefighting_skills volunteers)";
                                } elseif ($firefighting_skills > 0) {
                                    $assessment[] = "⚠ Firefighting capability: <strong>Limited</strong> ($firefighting_skills volunteers)";
                                } else {
                                    $assessment[] = "✗ Firefighting capability: <strong>None</strong>";
                                }
                                
                                if ($first_aid_skills >= 2) {
                                    $assessment[] = "✓ Medical response: <strong>Good</strong> ($first_aid_skills volunteers)";
                                } elseif ($first_aid_skills > 0) {
                                    $assessment[] = "⚠ Medical response: <strong>Limited</strong> ($first_aid_skills volunteers)";
                                } else {
                                    $assessment[] = "✗ Medical response: <strong>None</strong>";
                                }
                                
                                if ($rescue_skills >= 2) {
                                    $assessment[] = "✓ Rescue capability: <strong>Good</strong> ($rescue_skills volunteers)";
                                } elseif ($rescue_skills > 0) {
                                    $assessment[] = "⚠ Rescue capability: <strong>Limited</strong> ($rescue_skills volunteers)";
                                } else {
                                    $assessment[] = "✗ Rescue capability: <strong>None</strong>";
                                }
                                
                                if ($driving_skills >= 1) {
                                    $assessment[] = "✓ Driver availability: <strong>Good</strong> ($driving_skills volunteers)";
                                } else {
                                    $assessment[] = "✗ Driver availability: <strong>None</strong>";
                                }
                                ?>
                                
                                <?php foreach ($assessment as $item): ?>
                                    <li><?php echo $item; ?></li>
                                <?php endforeach; ?>
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
                    const volunteerCards = document.querySelectorAll('.volunteer-card');
                    
                    volunteerCards.forEach(card => {
                        const name = card.getAttribute('data-name');
                        const skills = card.getAttribute('data-skills');
                        
                        if (name.includes(searchTerm) || skills.includes(searchTerm)) {
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
            
            // Auto-submit filters on change
            const statusFilter = document.querySelector('select[name="status"]');
            const genderFilter = document.querySelector('select[name="gender"]');
            
            if (statusFilter) statusFilter.addEventListener('change', function() { 
                if (document.querySelector('input[name="search"]').value === '' && 
                    genderFilter.value === 'all') {
                    document.getElementById('filter-form').submit();
                }
            });
            
            if (genderFilter) genderFilter.addEventListener('change', function() { 
                if (document.querySelector('input[name="search"]').value === '' && 
                    statusFilter.value === 'all') {
                    document.getElementById('filter-form').submit();
                }
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
    </script>
</body>
</html>