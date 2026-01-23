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
        v.emergency_contact_name,
        v.emergency_contact_relationship,
        v.emergency_contact_number,
        v.emergency_contact_address,
        v.volunteered_before,
        v.previous_volunteer_experience,
        v.volunteer_motivation,
        v.currently_employed,
        v.occupation,
        v.company,
        v.available_days,
        v.available_hours,
        v.emergency_response,
        u.username,
        va.assignment_date,
        COUNT(DISTINCT s.id) as total_shifts,
        COUNT(DISTINCT CASE WHEN s.status = 'completed' THEN s.id END) as completed_shifts
    FROM volunteers v
    LEFT JOIN users u ON v.user_id = u.id
    LEFT JOIN volunteer_assignments va ON v.id = va.volunteer_id AND va.status = 'Active'
    LEFT JOIN shifts s ON v.id = s.volunteer_id
    WHERE va.unit_id = ? AND v.status = 'approved'
    GROUP BY v.id, v.first_name, v.last_name, v.contact_number, v.email, 
             v.gender, v.date_of_birth, v.civil_status, v.volunteer_status, 
             v.status, v.created_at, u.username, va.assignment_date,
             v.skills_basic_firefighting, v.skills_first_aid_cpr, v.skills_search_rescue,
             v.skills_driving, v.skills_communication, v.skills_mechanical, 
             v.skills_logistics, v.area_interest_fire_suppression, 
             v.area_interest_rescue_operations, v.area_interest_ems, 
             v.area_interest_disaster_response, v.area_interest_admin_logistics,
             v.address, v.education, v.specialized_training, v.physical_fitness,
             v.languages_spoken, v.driving_license_no, v.emergency_contact_name,
             v.emergency_contact_relationship, v.emergency_contact_number,
             v.emergency_contact_address, v.volunteered_before, 
             v.previous_volunteer_experience, v.volunteer_motivation,
             v.currently_employed, v.occupation, v.company, v.available_days,
             v.available_hours, v.emergency_response
    ORDER BY v.last_name, v.first_name
";

$volunteers_stmt = $pdo->prepare($volunteers_query);
$volunteers_stmt->execute([$unit_id]);
$unit_volunteers = $volunteers_stmt->fetchAll();

// Get unit information
$unit_query = "SELECT * FROM units WHERE id = ?";
$unit_stmt = $pdo->prepare($unit_query);
$unit_stmt->execute([$unit_id]);
$unit_info = $unit_stmt->fetch();

// Get attendance statistics for unit volunteers
$attendance_stats_query = "
    SELECT 
        v.id,
        COUNT(al.id) as total_attendance,
        SUM(CASE WHEN al.attendance_status = 'present' THEN 1 ELSE 0 END) as present_count,
        SUM(CASE WHEN al.attendance_status = 'late' THEN 1 ELSE 0 END) as late_count,
        SUM(CASE WHEN al.attendance_status = 'absent' THEN 1 ELSE 0 END) as absent_count,
        COALESCE(SUM(al.total_hours), 0) as total_hours,
        COALESCE(SUM(al.overtime_hours), 0) as total_overtime
    FROM volunteers v
    LEFT JOIN attendance_logs al ON v.id = al.volunteer_id
    WHERE v.id IN (
        SELECT v2.id 
        FROM volunteers v2
        LEFT JOIN volunteer_assignments va2 ON v2.id = va2.volunteer_id
        WHERE va2.unit_id = ? AND v2.status = 'approved'
    )
    GROUP BY v.id
";

$attendance_stats_stmt = $pdo->prepare($attendance_stats_query);
$attendance_stats_stmt->execute([$unit_id]);
$attendance_stats = [];
while ($row = $attendance_stats_stmt->fetch()) {
    $attendance_stats[$row['id']] = $row;
}

// Calculate statistics
$total_volunteers = count($unit_volunteers);
$active_volunteers = 0;
$inactive_volunteers = 0;
$new_volunteers = 0;
$male_count = 0;
$female_count = 0;

foreach ($unit_volunteers as $vol) {
    if ($vol['volunteer_status'] === 'Active') $active_volunteers++;
    if ($vol['volunteer_status'] === 'Inactive') $inactive_volunteers++;
    if ($vol['volunteer_status'] === 'New Volunteer') $new_volunteers++;
    if ($vol['gender'] === 'Male') $male_count++;
    if ($vol['gender'] === 'Female') $female_count++;
}

// Handle filters
$search_filter = $_GET['search'] ?? '';
$status_filter = $_GET['status'] ?? 'all';
$gender_filter = $_GET['gender'] ?? 'all';

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
    
    // Apply gender filter
    if ($gender_filter !== 'all' && $volunteer['gender'] !== $gender_filter) {
        $match = false;
    }
    
    if ($match) {
        $filtered_volunteers[] = $volunteer;
    }
}

// Close statements
$stmt = null;
$volunteer_stmt = null;
$volunteers_stmt = null;
$unit_stmt = null;
$attendance_stats_stmt = null;
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Volunteer Roster - Fire & Rescue Services Management</title>
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

        .btn-view-details {
            padding: 6px 14px;
            background: linear-gradient(135deg, var(--info), #60a5fa);
            color: white;
            border: none;
            border-radius: 8px;
            font-size: 12px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 5px;
            text-decoration: none;
        }

        .btn-view-details:hover {
            transform: translateY(-1px);
            box-shadow: 0 4px 8px rgba(59, 130, 246, 0.3);
            background: linear-gradient(135deg, #2563eb, #3b82f6);
        }

        .volunteer-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
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

        .volunteer-card.active {
            border-left: 4px solid var(--success);
        }

        .volunteer-card.inactive {
            border-left: 4px solid var(--text-light);
        }

        .volunteer-card.new {
            border-left: 4px solid var(--info);
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

        .volunteer-details {
            margin-bottom: 15px;
        }

        .detail-row {
            display: flex;
            justify-content: space-between;
            margin-bottom: 8px;
        }

        .detail-label {
            color: var(--text-light);
            font-size: 12px;
        }

        .detail-value {
            color: var(--text-color);
            font-weight: 500;
            font-size: 13px;
        }

        .skills-section {
            margin-top: 15px;
            padding-top: 15px;
            border-top: 1px solid var(--border-color);
        }

        .skills-title {
            font-size: 12px;
            color: var(--text-light);
            margin-bottom: 8px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .skills-tags {
            display: flex;
            flex-wrap: wrap;
            gap: 5px;
        }

        .skill-tag {
            background: rgba(59, 130, 246, 0.1);
            color: var(--info);
            padding: 3px 8px;
            border-radius: 4px;
            font-size: 10px;
            font-weight: 600;
        }

        .skill-tag.fire {
            background: rgba(220, 38, 38, 0.1);
            color: var(--danger);
        }

        .skill-tag.medical {
            background: rgba(16, 185, 129, 0.1);
            color: var(--success);
        }

        .skill-tag.rescue {
            background: rgba(245, 158, 11, 0.1);
            color: var(--warning);
        }

        .skill-tag.driving {
            background: rgba(139, 92, 246, 0.1);
            color: #8b5cf6;
        }

        .attendance-stats {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 10px;
            margin-top: 15px;
            padding: 10px;
            background: rgba(245, 158, 11, 0.05);
            border-radius: 8px;
        }

        .attendance-stat {
            text-align: center;
        }

        .attendance-number {
            font-size: 16px;
            font-weight: 700;
            color: var(--text-color);
        }

        .attendance-label {
            font-size: 10px;
            color: var(--text-light);
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

        .table-container {
            overflow-x: auto;
            margin-top: 20px;
        }

        .volunteer-table {
            width: 100%;
            border-collapse: collapse;
        }

        .volunteer-table th {
            background: var(--gray-100);
            color: var(--text-color);
            font-weight: 600;
            text-align: left;
            padding: 12px 16px;
            border-bottom: 2px solid var(--border-color);
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .dark-mode .volunteer-table th {
            background: var(--gray-800);
        }

        .volunteer-table td {
            padding: 12px 16px;
            border-bottom: 1px solid var(--border-color);
            vertical-align: middle;
            font-size: 13px;
        }

        .volunteer-table tr:hover {
            background: var(--gray-50);
        }

        .dark-mode .volunteer-table tr:hover {
            background: var(--gray-800);
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

        .badge-danger {
            background: rgba(220, 38, 38, 0.1);
            color: var(--danger);
            border: 1px solid rgba(220, 38, 38, 0.2);
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

        /* Modal Styles */
        .modal-overlay {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.5);
            display: none;
            justify-content: center;
            align-items: center;
            z-index: 10000;
            padding: 20px;
        }

        .modal-overlay.active {
            display: flex;
        }

        .modal {
            background: var(--background-color);
            border-radius: 16px;
            width: 90%;
            max-width: 800px;
            max-height: 90vh;
            overflow-y: auto;
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.2);
            border: 1px solid var(--border-color);
        }

        .modal-header {
            padding: 24px;
            border-bottom: 1px solid var(--border-color);
            display: flex;
            justify-content: space-between;
            align-items: center;
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            border-radius: 16px 16px 0 0;
            color: white;
        }

        .modal-title {
            font-size: 24px;
            font-weight: 700;
            margin: 0;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .modal-close {
            background: rgba(255, 255, 255, 0.2);
            border: none;
            color: white;
            width: 32px;
            height: 32px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .modal-close:hover {
            background: rgba(255, 255, 255, 0.3);
            transform: rotate(90deg);
        }

        .modal-body {
            padding: 24px;
        }

        .modal-section {
            margin-bottom: 30px;
            padding-bottom: 20px;
            border-bottom: 1px solid var(--border-color);
        }

        .modal-section:last-child {
            border-bottom: none;
            margin-bottom: 0;
        }

        .modal-section-title {
            font-size: 18px;
            font-weight: 700;
            color: var(--primary-color);
            margin-bottom: 16px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .modal-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 16px;
        }

        .modal-info-item {
            display: flex;
            flex-direction: column;
        }

        .modal-label {
            font-size: 12px;
            color: var(--text-light);
            margin-bottom: 4px;
            font-weight: 600;
        }

        .modal-value {
            color: var(--text-color);
            font-size: 14px;
            line-height: 1.5;
        }

        .modal-value strong {
            color: var(--primary-color);
        }

        .modal-badges {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            margin-top: 8px;
        }

        .modal-badge {
            padding: 6px 12px;
            background: rgba(59, 130, 246, 0.1);
            color: var(--info);
            border-radius: 8px;
            font-size: 12px;
            font-weight: 600;
            border: 1px solid rgba(59, 130, 246, 0.2);
        }

        .modal-badge.fire {
            background: rgba(220, 38, 38, 0.1);
            color: var(--danger);
            border-color: rgba(220, 38, 38, 0.2);
        }

        .modal-badge.medical {
            background: rgba(16, 185, 129, 0.1);
            color: var(--success);
            border-color: rgba(16, 185, 129, 0.2);
        }

        .modal-badge.rescue {
            background: rgba(245, 158, 11, 0.1);
            color: var(--warning);
            border-color: rgba(245, 158, 11, 0.2);
        }

        .modal-badge.driving {
            background: rgba(139, 92, 246, 0.1);
            color: #8b5cf6;
            border-color: rgba(139, 92, 246, 0.2);
        }

        .modal-stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(120px, 1fr));
            gap: 12px;
            margin-top: 16px;
        }

        .modal-stat {
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: 8px;
            padding: 16px;
            text-align: center;
        }

        .modal-stat-number {
            font-size: 24px;
            font-weight: 800;
            color: var(--primary-color);
            margin-bottom: 4px;
        }

        .modal-stat-label {
            font-size: 11px;
            color: var(--text-light);
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .modal-footer {
            padding: 16px 24px;
            border-top: 1px solid var(--border-color);
            display: flex;
            justify-content: flex-end;
            gap: 12px;
            background: var(--card-bg);
            border-radius: 0 0 16px 16px;
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
                grid-template-columns: repeat(auto-fill, minmax(250px, 1fr));
            }
            
            .stats-container {
                grid-template-columns: repeat(2, 1fr);
            }
            
            .filter-container {
                grid-template-columns: 1fr;
            }
            
            .modal {
                width: 95%;
                max-height: 85vh;
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
            
            .volunteer-table {
                font-size: 12px;
            }
            
            .volunteer-table th,
            .volunteer-table td {
                padding: 8px 10px;
            }
            
            .filter-actions {
                flex-direction: column;
            }
            
            .modal-header {
                padding: 16px;
            }
            
            .modal-body {
                padding: 16px;
            }
            
            .modal-title {
                font-size: 20px;
            }
            
            .modal-grid {
                grid-template-columns: 1fr;
            }
            
            .modal-stats {
                grid-template-columns: repeat(2, 1fr);
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
                        <a href="volunteer_list.php" class="submenu-item active">Volunteer List</a>
                        <a href="roles_skills.php" class="submenu-item">Roles & Skills</a>
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
                        <h1 class="dashboard-title">Volunteer Roster</h1>
                        <p class="dashboard-subtitle">View and manage volunteers in your unit/roster</p>
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
                    
                    <!-- Unit Statistics -->
                    <div class="section-container">
                        <h3 class="section-title">
                            <i class='bx bx-stats'></i>
                            Unit Statistics
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
                                <div class="stat-value" style="color: var(--info);">
                                    <?php echo $new_volunteers; ?>
                                </div>
                                <div class="stat-label">New Volunteers</div>
                            </div>
                            
                            <div class="stat-card">
                                <div class="stat-value" style="color: var(--text-light);">
                                    <?php echo $inactive_volunteers; ?>
                                </div>
                                <div class="stat-label">Inactive</div>
                            </div>
                            
                            <div class="stat-card">
                                <div class="stat-value" style="color: var(--info);">
                                    <?php echo $male_count; ?>
                                </div>
                                <div class="stat-label">Male</div>
                            </div>
                            
                            <div class="stat-card">
                                <div class="stat-value" style="color: #ec4899;">
                                    <?php echo $female_count; ?>
                                </div>
                                <div class="stat-label">Female</div>
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
                                <label class="filter-label">Gender</label>
                                <select name="gender" class="filter-select">
                                    <option value="all" <?php echo $gender_filter === 'all' ? 'selected' : ''; ?>>All Genders</option>
                                    <option value="Male" <?php echo $gender_filter === 'Male' ? 'selected' : ''; ?>>Male</option>
                                    <option value="Female" <?php echo $gender_filter === 'Female' ? 'selected' : ''; ?>>Female</option>
                                    <option value="Other" <?php echo $gender_filter === 'Other' ? 'selected' : ''; ?>>Other</option>
                                </select>
                            </div>
                            
                            <div class="filter-actions">
                                <button type="submit" class="btn btn-primary">
                                    <i class='bx bx-filter-alt'></i> Apply Filters
                                </button>
                                <a href="volunteer_list.php" class="btn btn-secondary">
                                    <i class='bx bx-reset'></i> Clear Filters
                                </a>
                            </div>
                        </form>
                    </div>
                    
                    <!-- Volunteers List -->
                    <div class="section-container">
                        <h3 class="section-title">
                            <i class='bx bx-user-check'></i>
                            Volunteers in Unit
                            <?php if (count($filtered_volunteers) > 0): ?>
                                <span class="badge badge-info"><?php echo count($filtered_volunteers); ?> volunteers</span>
                            <?php endif; ?>
                        </h3>
                        
                        <?php if (count($filtered_volunteers) > 0): ?>
                            <!-- Grid View (default) -->
                            <div class="volunteer-grid">
                                <?php foreach ($filtered_volunteers as $vol): 
                                    $full_name = htmlspecialchars($vol['first_name'] . ' ' . $vol['last_name']);
                                    $initials = strtoupper(substr($vol['first_name'], 0, 1) . substr($vol['last_name'], 0, 1));
                                    $status_class = 'status-' . strtolower(str_replace(' ', '_', $vol['volunteer_status']));
                                    $card_class = strtolower(str_replace(' ', '_', $vol['volunteer_status']));
                                    $age = date_diff(date_create($vol['date_of_birth']), date_create('today'))->y;
                                    $stats = $attendance_stats[$vol['id']] ?? null;
                                ?>
                                    <div class="volunteer-card <?php echo $card_class; ?>">
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
                                        
                                        <div class="volunteer-details">
                                            <div class="detail-row">
                                                <span class="detail-label">Contact</span>
                                                <span class="detail-value"><?php echo htmlspecialchars($vol['contact_number']); ?></span>
                                            </div>
                                            <div class="detail-row">
                                                <span class="detail-label">Gender</span>
                                                <span class="detail-value"><?php echo htmlspecialchars($vol['gender']); ?></span>
                                            </div>
                                            <div class="detail-row">
                                                <span class="detail-label">Age</span>
                                                <span class="detail-value"><?php echo $age; ?> years</span>
                                            </div>
                                            <div class="detail-row">
                                                <span class="detail-label">Civil Status</span>
                                                <span class="detail-value"><?php echo htmlspecialchars($vol['civil_status']); ?></span>
                                            </div>
                                            <div class="detail-row">
                                                <span class="detail-label">Member Since</span>
                                                <span class="detail-value"><?php echo date('M Y', strtotime($vol['created_at'])); ?></span>
                                            </div>
                                        </div>
                                        
                                        <?php if ($stats && $stats['total_attendance'] > 0): ?>
                                            <div class="attendance-stats">
                                                <div class="attendance-stat">
                                                    <div class="attendance-number"><?php echo $stats['present_count']; ?></div>
                                                    <div class="attendance-label">Present</div>
                                                </div>
                                                <div class="attendance-stat">
                                                    <div class="attendance-number"><?php echo $stats['late_count']; ?></div>
                                                    <div class="attendance-label">Late</div>
                                                </div>
                                                <div class="attendance-stat">
                                                    <div class="attendance-number"><?php echo $stats['absent_count']; ?></div>
                                                    <div class="attendance-label">Absent</div>
                                                </div>
                                                <div class="attendance-stat">
                                                    <div class="attendance-number"><?php echo number_format($stats['total_hours'], 0); ?></div>
                                                    <div class="attendance-label">Hours</div>
                                                </div>
                                            </div>
                                        <?php endif; ?>
                                        
                                        <div class="skills-section">
                                            <div class="skills-title">Skills & Interests</div>
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
                                                    <span class="skill-tag">Communication</span>
                                                <?php endif; ?>
                                                <?php if ($vol['area_interest_fire_suppression']): ?>
                                                    <span class="skill-tag fire">Fire Suppression</span>
                                                <?php endif; ?>
                                                <?php if ($vol['area_interest_rescue_operations']): ?>
                                                    <span class="skill-tag rescue">Rescue Operations</span>
                                                <?php endif; ?>
                                                <?php if ($vol['area_interest_ems']): ?>
                                                    <span class="skill-tag medical">EMS</span>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                        
                                        <div style="margin-top: 15px; display: flex; justify-content: space-between; align-items: center;">
                                            <div>
                                                <span style="font-size: 11px; color: var(--text-light);">
                                                    <i class='bx bx-calendar'></i> Assigned: <?php echo $vol['assignment_date'] ? date('M d, Y', strtotime($vol['assignment_date'])) : 'Not assigned'; ?>
                                                </span>
                                            </div>
                                            <button class="btn-view-details" onclick="openVolunteerModal(<?php echo $vol['id']; ?>)">
                                                <i class='bx bx-show'></i> View Details
                                            </button>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                            
                            <!-- Table View (hidden by default, can be toggled) -->
                            <div class="table-container" style="display: none;" id="tableView">
                                <table class="volunteer-table">
                                    <thead>
                                        <tr>
                                            <th>Name</th>
                                            <th>Contact</th>
                                            <th>Gender</th>
                                            <th>Age</th>
                                            <th>Status</th>
                                            <th>Member Since</th>
                                            <th>Shifts</th>
                                            <th>Attendance Rate</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($filtered_volunteers as $vol): 
                                            $full_name = htmlspecialchars($vol['first_name'] . ' ' . $vol['last_name']);
                                            $age = date_diff(date_create($vol['date_of_birth']), date_create('today'))->y;
                                            $stats = $attendance_stats[$vol['id']] ?? null;
                                            $total_shifts = $vol['total_shifts'] ?? 0;
                                            $completed_shifts = $vol['completed_shifts'] ?? 0;
                                            $attendance_rate = $total_shifts > 0 ? round(($completed_shifts / $total_shifts) * 100) : 0;
                                            
                                            $status_badge_class = 'badge-';
                                            switch ($vol['volunteer_status']) {
                                                case 'Active': $status_badge_class .= 'success'; break;
                                                case 'Inactive': $status_badge_class .= 'light'; break;
                                                case 'New Volunteer': $status_badge_class .= 'info'; break;
                                                default: $status_badge_class .= 'light'; break;
                                            }
                                            
                                            $attendance_badge_class = 'badge-';
                                            if ($attendance_rate >= 90) {
                                                $attendance_badge_class .= 'success';
                                            } elseif ($attendance_rate >= 70) {
                                                $attendance_badge_class .= 'warning';
                                            } else {
                                                $attendance_badge_class .= 'danger';
                                            }
                                        ?>
                                            <tr>
                                                <td>
                                                    <div style="display: flex; align-items: center; gap: 10px;">
                                                        <div style="width: 30px; height: 30px; border-radius: 50%; background: linear-gradient(135deg, var(--primary-color), var(--secondary-color)); color: white; display: flex; align-items: center; justify-content: center; font-weight: bold; font-size: 12px;">
                                                            <?php echo strtoupper(substr($vol['first_name'], 0, 1) . substr($vol['last_name'], 0, 1)); ?>
                                                        </div>
                                                        <div>
                                                            <div style="font-weight: 600;"><?php echo $full_name; ?></div>
                                                            <div style="font-size: 11px; color: var(--text-light);"><?php echo htmlspecialchars($vol['email']); ?></div>
                                                        </div>
                                                    </div>
                                                </td>
                                                <td><?php echo htmlspecialchars($vol['contact_number']); ?></td>
                                                <td><?php echo htmlspecialchars($vol['gender']); ?></td>
                                                <td><?php echo $age; ?></td>
                                                <td><span class="badge <?php echo $status_badge_class; ?>"><?php echo $vol['volunteer_status']; ?></span></td>
                                                <td><?php echo date('M Y', strtotime($vol['created_at'])); ?></td>
                                                <td>
                                                    <div style="font-size: 11px;">
                                                        <div>Total: <?php echo $total_shifts; ?></div>
                                                        <div>Completed: <?php echo $completed_shifts; ?></div>
                                                    </div>
                                                </td>
                                                <td>
                                                    <span class="badge <?php echo $attendance_badge_class; ?>">
                                                        <?php echo $attendance_rate; ?>%
                                                    </span>
                                                </td>
                                                <td>
                                                    <button class="btn-view-details" onclick="openVolunteerModal(<?php echo $vol['id']; ?>)">
                                                        <i class='bx bx-show'></i> View
                                                    </button>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                            
                            <!-- View Toggle -->
                            <div style="display: flex; justify-content: center; gap: 10px; margin-top: 20px;">
                                <button class="btn btn-sm btn-primary" id="gridViewBtn">
                                    <i class='bx bx-grid-alt'></i> Grid View
                                </button>
                                <button class="btn btn-sm btn-secondary" id="tableViewBtn">
                                    <i class='bx bx-table'></i> Table View
                                </button>
                            </div>
                        <?php else: ?>
                            <div class="empty-state">
                                <i class='bx bx-user-x'></i>
                                <h3>No Volunteers Found</h3>
                                <p>No volunteers match your search criteria or there are no volunteers in your unit.</p>
                                <?php if ($search_filter || $status_filter !== 'all' || $gender_filter !== 'all'): ?>
                                    <div style="margin-top: 20px;">
                                        <a href="volunteer_list.php" class="btn btn-primary">
                                            <i class='bx bx-reset'></i> Clear Filters
                                        </a>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                    
                    <!-- Unit Information -->
                    <div class="section-container">
                        <h3 class="section-title">
                            <i class='bx bx-info-circle'></i>
                            About Your Unit
                        </h3>
                        
                        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 20px;">
                            <div style="background: rgba(220, 38, 38, 0.1); padding: 20px; border-radius: 10px; border: 1px solid rgba(220, 38, 38, 0.2);">
                                <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 10px;">
                                    <div style="background: var(--danger); color: white; width: 30px; height: 30px; border-radius: 50%; display: flex; align-items: center; justify-content: center;">
                                        <i class='bx bx-group'></i>
                                    </div>
                                    <h4 style="margin: 0; color: var(--text-color);">Unit Membership</h4>
                                </div>
                                <p style="margin: 0; color: var(--text-color); font-size: 13px;">
                                    You are assigned to <strong><?php echo htmlspecialchars($unit_name); ?></strong> (<?php echo htmlspecialchars($unit_code); ?>). 
                                    This unit specializes in <?php echo htmlspecialchars($unit_info['unit_type'] ?? 'fire and rescue'); ?> operations.
                                </p>
                            </div>
                            
                            <div style="background: rgba(16, 185, 129, 0.1); padding: 20px; border-radius: 10px; border: 1px solid rgba(16, 185, 129, 0.2);">
                                <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 10px;">
                                    <div style="background: var(--success); color: white; width: 30px; height: 30px; border-radius: 50%; display: flex; align-items: center; justify-content: center;">
                                        <i class='bx bx-calendar-check'></i>
                                    </div>
                                    <h4 style="margin: 0; color: var(--text-color);">Team Coordination</h4>
                                </div>
                                <p style="margin: 0; color: var(--text-color); font-size: 13px;">
                                    Work closely with your unit members during shifts and emergencies. 
                                    Coordinate with your unit leader for assignments and updates.
                                </p>
                            </div>
                            
                            <div style="background: rgba(59, 130, 246, 0.1); padding: 20px; border-radius: 10px; border: 1px solid rgba(59, 130, 246, 0.2);">
                                <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 10px;">
                                    <div style="background: var(--info); color: white; width: 30px; height: 30px; border-radius: 50%; display: flex; align-items: center; justify-content: center;">
                                        <i class='bx bx-chat'></i>
                                    </div>
                                    <h4 style="margin: 0; color: var(--text-color);">Communication</h4>
                                </div>
                                <p style="margin: 0; color: var(--text-color); font-size: 13px;">
                                    Maintain regular communication with your unit. 
                                    Report any issues or concerns to your unit leader immediately.
                                </p>
                            </div>
                        </div>
                        
                        <div style="margin-top: 20px; padding: 15px; background: rgba(245, 158, 11, 0.05); border-radius: 8px; border-left: 3px solid var(--warning);">
                            <h4 style="margin: 0 0 10px 0; color: var(--warning);">Important Notes for Volunteers:</h4>
                            <ul style="margin: 0; padding-left: 20px; color: var(--text-color); font-size: 13px;">
                                <li>Always check in for your scheduled shifts on time</li>
                                <li>Wear appropriate uniform and safety gear during shifts</li>
                                <li>Report any safety concerns to your unit leader immediately</li>
                                <li>Participate actively in unit training sessions</li>
                                <li>Maintain good communication with fellow volunteers</li>
                                <li>Follow chain of command during emergency responses</li>
                                <li>Keep your contact information updated in the system</li>
                                <li>Notify your unit leader if you cannot attend scheduled shifts</li>
                                <li>Respect and support all members of your unit</li>
                                <li>Your performance and attendance affect unit effectiveness</li>
                            </ul>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Volunteer Details Modal -->
    <div class="modal-overlay" id="volunteerModal">
        <div class="modal">
            <div class="modal-header">
                <h3 class="modal-title">
                    <i class='bx bx-user-circle'></i>
                    <span id="modal-volunteer-name">Loading...</span>
                </h3>
                <button class="modal-close" onclick="closeVolunteerModal()">
                    <i class='bx bx-x'></i>
                </button>
            </div>
            <div class="modal-body" id="modal-body">
                <!-- Content will be loaded here dynamically -->
                <div style="text-align: center; padding: 40px;">
                    <i class='bx bx-loader-alt bx-spin' style="font-size: 48px; color: var(--primary-color);"></i>
                    <p style="margin-top: 16px; color: var(--text-light);">Loading volunteer details...</p>
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary" onclick="closeVolunteerModal()">
                    <i class='bx bx-x'></i> Close
                </button>
                <button class="btn btn-primary" id="contact-volunteer-btn">
                    <i class='bx bx-phone'></i> Contact Volunteer
                </button>
            </div>
        </div>
    </div>
    
    <script>
        // Store volunteer data for modal access
        const volunteerData = <?php echo json_encode($unit_volunteers); ?>;
        const attendanceStats = <?php echo json_encode($attendance_stats); ?>;
        
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
                        const contact = card.querySelector('.detail-row:nth-child(1) .detail-value').textContent.toLowerCase();
                        
                        if (name.includes(searchTerm) || email.includes(searchTerm) || contact.includes(searchTerm)) {
                            card.style.display = 'block';
                        } else {
                            card.style.display = 'none';
                        }
                    });
                });
            }
            
            // View toggle functionality
            const gridViewBtn = document.getElementById('gridViewBtn');
            const tableViewBtn = document.getElementById('tableViewBtn');
            const gridView = document.querySelector('.volunteer-grid');
            const tableView = document.getElementById('tableView');
            
            if (gridViewBtn && tableViewBtn) {
                gridViewBtn.addEventListener('click', function() {
                    gridView.style.display = 'grid';
                    tableView.style.display = 'none';
                    gridViewBtn.classList.remove('btn-secondary');
                    gridViewBtn.classList.add('btn-primary');
                    tableViewBtn.classList.remove('btn-primary');
                    tableViewBtn.classList.add('btn-secondary');
                });
                
                tableViewBtn.addEventListener('click', function() {
                    gridView.style.display = 'none';
                    tableView.style.display = 'block';
                    tableViewBtn.classList.remove('btn-secondary');
                    tableViewBtn.classList.add('btn-primary');
                    gridViewBtn.classList.remove('btn-primary');
                    gridViewBtn.classList.add('btn-secondary');
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
            
            // Close modal when clicking outside
            const modal = document.getElementById('volunteerModal');
            if (modal) {
                modal.addEventListener('click', function(e) {
                    if (e.target === modal) {
                        closeVolunteerModal();
                    }
                });
            }
            
            // Close modal with Escape key
            document.addEventListener('keydown', function(e) {
                if (e.key === 'Escape') {
                    closeVolunteerModal();
                }
            });
            
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
        
        // Open volunteer details modal
        function openVolunteerModal(volunteerId) {
            const volunteer = volunteerData.find(v => v.id == volunteerId);
            if (!volunteer) return;
            
            const stats = attendanceStats[volunteerId] || {};
            const modal = document.getElementById('volunteerModal');
            const modalName = document.getElementById('modal-volunteer-name');
            const modalBody = document.getElementById('modal-body');
            const contactBtn = document.getElementById('contact-volunteer-btn');
            
            // Set volunteer name
            modalName.textContent = `${volunteer.first_name} ${volunteer.last_name}`;
            
            // Calculate age
            const age = Math.floor((new Date() - new Date(volunteer.date_of_birth)) / (365.25 * 24 * 60 * 60 * 1000));
            
            // Calculate shift statistics
            const totalShifts = volunteer.total_shifts || 0;
            const completedShifts = volunteer.completed_shifts || 0;
            const attendanceRate = totalShifts > 0 ? Math.round((completedShifts / totalShifts) * 100) : 0;
            
            // Create modal content
            const content = `
                <div class="modal-section">
                    <h4 class="modal-section-title">
                        <i class='bx bx-user'></i>
                        Basic Information
                    </h4>
                    <div class="modal-grid">
                        <div class="modal-info-item">
                            <span class="modal-label">Full Name</span>
                            <span class="modal-value">${volunteer.first_name} ${volunteer.middle_name || ''} ${volunteer.last_name}</span>
                        </div>
                        <div class="modal-info-item">
                            <span class="modal-label">Contact Number</span>
                            <span class="modal-value">${volunteer.contact_number}</span>
                        </div>
                        <div class="modal-info-item">
                            <span class="modal-label">Email Address</span>
                            <span class="modal-value">${volunteer.email}</span>
                        </div>
                        <div class="modal-info-item">
                            <span class="modal-label">Gender</span>
                            <span class="modal-value">${volunteer.gender}</span>
                        </div>
                        <div class="modal-info-item">
                            <span class="modal-label">Date of Birth</span>
                            <span class="modal-value">${new Date(volunteer.date_of_birth).toLocaleDateString('en-US', { year: 'numeric', month: 'long', day: 'numeric' })} (${age} years old)</span>
                        </div>
                        <div class="modal-info-item">
                            <span class="modal-label">Civil Status</span>
                            <span class="modal-value">${volunteer.civil_status}</span>
                        </div>
                        <div class="modal-info-item">
                            <span class="modal-label">Address</span>
                            <span class="modal-value">${volunteer.address || 'Not provided'}</span>
                        </div>
                        <div class="modal-info-item">
                            <span class="modal-label">Languages Spoken</span>
                            <span class="modal-value">${volunteer.languages_spoken || 'Not specified'}</span>
                        </div>
                    </div>
                </div>
                
                <div class="modal-section">
                    <h4 class="modal-section-title">
                        <i class='bx bx-briefcase'></i>
                        Professional Information
                    </h4>
                    <div class="modal-grid">
                        <div class="modal-info-item">
                            <span class="modal-label">Currently Employed</span>
                            <span class="modal-value">${volunteer.currently_employed}</span>
                        </div>
                        ${volunteer.currently_employed === 'Yes' ? `
                            <div class="modal-info-item">
                                <span class="modal-label">Occupation</span>
                                <span class="modal-value">${volunteer.occupation || 'Not specified'}</span>
                            </div>
                            <div class="modal-info-item">
                                <span class="modal-label">Company</span>
                                <span class="modal-value">${volunteer.company || 'Not specified'}</span>
                            </div>
                        ` : ''}
                        <div class="modal-info-item">
                            <span class="modal-label">Education Level</span>
                            <span class="modal-value">${volunteer.education}</span>
                        </div>
                        <div class="modal-info-item">
                            <span class="modal-label">Specialized Training</span>
                            <span class="modal-value">${volunteer.specialized_training || 'None specified'}</span>
                        </div>
                        <div class="modal-info-item">
                            <span class="modal-label">Physical Fitness</span>
                            <span class="modal-value">${volunteer.physical_fitness}</span>
                        </div>
                    </div>
                </div>
                
                <div class="modal-section">
                    <h4 class="modal-section-title">
                        <i class='bx bx-shield-alt'></i>
                        Emergency & Volunteer Information
                    </h4>
                    <div class="modal-grid">
                        <div class="modal-info-item">
                            <span class="modal-label">Emergency Contact</span>
                            <span class="modal-value"><strong>${volunteer.emergency_contact_name}</strong> (${volunteer.emergency_contact_relationship})</span>
                        </div>
                        <div class="modal-info-item">
                            <span class="modal-label">Emergency Contact Number</span>
                            <span class="modal-value">${volunteer.emergency_contact_number}</span>
                        </div>
                        <div class="modal-info-item">
                            <span class="modal-label">Volunteered Before</span>
                            <span class="modal-value">${volunteer.volunteered_before}</span>
                        </div>
                        ${volunteer.volunteered_before === 'Yes' && volunteer.previous_volunteer_experience ? `
                            <div class="modal-info-item">
                                <span class="modal-label">Previous Experience</span>
                                <span class="modal-value">${volunteer.previous_volunteer_experience}</span>
                            </div>
                        ` : ''}
                        <div class="modal-info-item">
                            <span class="modal-label">Volunteer Motivation</span>
                            <span class="modal-value">${volunteer.volunteer_motivation}</span>
                        </div>
                        <div class="modal-info-item">
                            <span class="modal-label">Available Days</span>
                            <span class="modal-value">${volunteer.available_days}</span>
                        </div>
                        <div class="modal-info-item">
                            <span class="modal-label">Available Hours</span>
                            <span class="modal-value">${volunteer.available_hours}</span>
                        </div>
                        <div class="modal-info-item">
                            <span class="modal-label">Emergency Response</span>
                            <span class="modal-value">${volunteer.emergency_response}</span>
                        </div>
                    </div>
                </div>
                
                <div class="modal-section">
                    <h4 class="modal-section-title">
                        <i class='bx bx-cog'></i>
                        Skills & Certifications
                    </h4>
                    <div class="modal-badges">
                        ${volunteer.skills_basic_firefighting ? `<span class="modal-badge fire">Basic Firefighting</span>` : ''}
                        ${volunteer.skills_first_aid_cpr ? `<span class="modal-badge medical">First Aid/CPR</span>` : ''}
                        ${volunteer.skills_search_rescue ? `<span class="modal-badge rescue">Search & Rescue</span>` : ''}
                        ${volunteer.skills_driving ? `<span class="modal-badge driving">Driving${volunteer.driving_license_no ? ` (${volunteer.driving_license_no})` : ''}</span>` : ''}
                        ${volunteer.skills_communication ? `<span class="modal-badge">Communication</span>` : ''}
                        ${volunteer.skills_mechanical ? `<span class="modal-badge">Mechanical Skills</span>` : ''}
                        ${volunteer.skills_logistics ? `<span class="modal-badge">Logistics</span>` : ''}
                    </div>
                    
                    <h5 style="margin: 16px 0 8px 0; color: var(--text-color); font-size: 14px;">Areas of Interest:</h5>
                    <div class="modal-badges">
                        ${volunteer.area_interest_fire_suppression ? `<span class="modal-badge fire">Fire Suppression</span>` : ''}
                        ${volunteer.area_interest_rescue_operations ? `<span class="modal-badge rescue">Rescue Operations</span>` : ''}
                        ${volunteer.area_interest_ems ? `<span class="modal-badge medical">EMS</span>` : ''}
                        ${volunteer.area_interest_disaster_response ? `<span class="modal-badge">Disaster Response</span>` : ''}
                        ${volunteer.area_interest_admin_logistics ? `<span class="modal-badge">Admin & Logistics</span>` : ''}
                    </div>
                </div>
                
                <div class="modal-section">
                    <h4 class="modal-section-title">
                        <i class='bx bx-chart'></i>
                        Performance Statistics
                    </h4>
                    <div class="modal-stats">
                        <div class="modal-stat">
                            <div class="modal-stat-number">${totalShifts}</div>
                            <div class="modal-stat-label">Total Shifts</div>
                        </div>
                        <div class="modal-stat">
                            <div class="modal-stat-number">${completedShifts}</div>
                            <div class="modal-stat-label">Completed</div>
                        </div>
                        <div class="modal-stat">
                            <div class="modal-stat-number">${attendanceRate}%</div>
                            <div class="modal-stat-label">Attendance Rate</div>
                        </div>
                        ${stats.total_attendance > 0 ? `
                            <div class="modal-stat">
                                <div class="modal-stat-number">${stats.present_count || 0}</div>
                                <div class="modal-stat-label">Present</div>
                            </div>
                            <div class="modal-stat">
                                <div class="modal-stat-number">${stats.late_count || 0}</div>
                                <div class="modal-stat-label">Late</div>
                            </div>
                            <div class="modal-stat">
                                <div class="modal-stat-number">${stats.absent_count || 0}</div>
                                <div class="modal-stat-label">Absent</div>
                            </div>
                            <div class="modal-stat">
                                <div class="modal-stat-number">${Math.round(stats.total_hours || 0)}</div>
                                <div class="modal-stat-label">Total Hours</div>
                            </div>
                            <div class="modal-stat">
                                <div class="modal-stat-number">${Math.round(stats.total_overtime || 0)}</div>
                                <div class="modal-stat-label">Overtime Hours</div>
                            </div>
                        ` : ''}
                    </div>
                </div>
                
                <div class="modal-section">
                    <h4 class="modal-section-title">
                        <i class='bx bx-calendar'></i>
                        Unit Assignment
                    </h4>
                    <div class="modal-grid">
                        <div class="modal-info-item">
                            <span class="modal-label">Volunteer Status</span>
                            <span class="modal-value">
                                <span class="badge ${volunteer.volunteer_status === 'Active' ? 'badge-success' : volunteer.volunteer_status === 'New Volunteer' ? 'badge-info' : 'badge-light'}">
                                    ${volunteer.volunteer_status}
                                </span>
                            </span>
                        </div>
                        <div class="modal-info-item">
                            <span class="modal-label">Application Status</span>
                            <span class="modal-value">
                                <span class="badge badge-success">${volunteer.application_status}</span>
                            </span>
                        </div>
                        <div class="modal-info-item">
                            <span class="modal-label">Assigned Unit</span>
                            <span class="modal-value">
                                <strong><?php echo htmlspecialchars($unit_name); ?></strong> (<?php echo htmlspecialchars($unit_code); ?>)
                            </span>
                        </div>
                        <div class="modal-info-item">
                            <span class="modal-label">Assignment Date</span>
                            <span class="modal-value">${volunteer.assignment_date ? new Date(volunteer.assignment_date).toLocaleDateString('en-US', { year: 'numeric', month: 'long', day: 'numeric' }) : 'Not assigned'}</span>
                        </div>
                        <div class="modal-info-item">
                            <span class="modal-label">Member Since</span>
                            <span class="modal-value">${new Date(volunteer.created_at).toLocaleDateString('en-US', { year: 'numeric', month: 'long', day: 'numeric' })}</span>
                        </div>
                    </div>
                </div>
            `;
            
            // Update modal body
            modalBody.innerHTML = content;
            
            // Update contact button
            contactBtn.onclick = function() {
                window.location.href = `tel:${volunteer.contact_number}`;
            };
            
            // Show modal
            modal.classList.add('active');
            document.body.style.overflow = 'hidden';
        }
        
        // Close volunteer details modal
        function closeVolunteerModal() {
            const modal = document.getElementById('volunteerModal');
            modal.classList.remove('active');
            document.body.style.overflow = 'auto';
        }
    </script>
</body>
</html>