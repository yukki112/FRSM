<?php
session_start();
require_once '../../config/db_connection.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: ../../login/login.php");
    exit();
}

$user_id = $_SESSION['user_id'];
$query = "SELECT first_name, middle_name, last_name, role FROM users WHERE id = ?";
$stmt = $pdo->prepare($query);
$stmt->execute([$user_id]);
$user = $stmt->fetch();

if ($user) {
    $first_name = htmlspecialchars($user['first_name']);
    $middle_name = htmlspecialchars($user['middle_name']);
    $last_name = htmlspecialchars($user['last_name']);
    $role = htmlspecialchars($user['role']);
    
    $full_name = $first_name;
    if (!empty($middle_name)) {
        $full_name .= " " . $middle_name;
    }
    $full_name .= " " . $last_name;
} else {
    $full_name = "User";
    $role = "USER";
}

// Check if user is admin
if ($role !== 'ADMIN') {
    header("Location: ../admin_dashboard.php");
    exit();
}

// Get filter parameters
$status_filter = isset($_GET['status']) ? $_GET['status'] : 'all';
$volunteer_filter = isset($_GET['volunteer']) ? $_GET['volunteer'] : 'all';
$training_filter = isset($_GET['training']) ? $_GET['training'] : 'all';
$search_term = isset($_GET['search']) ? $_GET['search'] : '';

// Build query with filters
$where_conditions = [];
$params = [];

if ($status_filter !== 'all') {
    if ($status_filter === 'certified') {
        $where_conditions[] = "tr.certificate_issued = 1";
    } elseif ($status_filter === 'completed') {
        $where_conditions[] = "tr.completion_status = 'completed'";
    } elseif ($status_filter === 'in_progress') {
        $where_conditions[] = "tr.completion_status = 'in_progress'";
    } elseif ($status_filter === 'registered') {
        $where_conditions[] = "tr.status = 'registered'";
    } elseif ($status_filter === 'cancelled') {
        $where_conditions[] = "tr.status = 'cancelled'";
    }
}

if ($volunteer_filter !== 'all') {
    $where_conditions[] = "tr.volunteer_id = ?";
    $params[] = $volunteer_filter;
}

if ($training_filter !== 'all') {
    $where_conditions[] = "tr.training_id = ?";
    $params[] = $training_filter;
}

if (!empty($search_term)) {
    $where_conditions[] = "(CONCAT(v.first_name, ' ', COALESCE(v.middle_name, ''), ' ', v.last_name) LIKE ? OR v.email LIKE ? OR t.title LIKE ? OR tc.certificate_number LIKE ?)";
    $params[] = "%$search_term%";
    $params[] = "%$search_term%";
    $params[] = "%$search_term%";
    $params[] = "%$search_term%";
}

$where_clause = '';
if (!empty($where_conditions)) {
    $where_clause = "WHERE " . implode(" AND ", $where_conditions);
}

// Fetch all training records with volunteer and certificate info
$records_query = "
    SELECT 
        tr.*,
        v.id as volunteer_id,
        v.first_name,
        v.middle_name,
        v.last_name,
        CONCAT(v.first_name, ' ', COALESCE(v.middle_name, ''), ' ', v.last_name) as volunteer_full_name,
        v.email as volunteer_email,
        v.volunteer_status,
        v.training_completion_status as volunteer_training_status,
        t.id as training_id,
        t.title as training_title,
        t.description as training_description,
        t.training_date,
        t.training_end_date,
        t.duration_hours,
        t.instructor,
        t.location as training_location,
        t.status as training_status,
        tc.certificate_number,
        tc.issue_date,
        tc.expiry_date,
        tc.certificate_file,
        tc.verified as certificate_verified,
        tc.verified_by as certificate_verified_by,
        tc.verified_at as certificate_verified_at,
        u.first_name as cert_verifier_first_name,
        u.last_name as cert_verifier_last_name
    FROM training_registrations tr
    JOIN volunteers v ON tr.volunteer_id = v.id
    JOIN trainings t ON tr.training_id = t.id
    LEFT JOIN training_certificates tc ON tr.id = tc.registration_id
    LEFT JOIN users u ON tc.issued_by = u.id
    $where_clause 
    ORDER BY tr.registration_date DESC, t.training_date DESC
";

$records_stmt = $pdo->prepare($records_query);
$records_stmt->execute($params);
$records = $records_stmt->fetchAll();

// Get all volunteers for filter dropdown
$volunteers_query = "SELECT id, first_name, middle_name, last_name FROM volunteers WHERE status = 'approved' ORDER BY first_name, last_name";
$volunteers_stmt = $pdo->prepare($volunteers_query);
$volunteers_stmt->execute();
$all_volunteers = $volunteers_stmt->fetchAll();

// Get all trainings for filter dropdown
$trainings_query = "SELECT id, title FROM trainings ORDER BY title";
$trainings_stmt = $pdo->prepare($trainings_query);
$trainings_stmt->execute();
$all_trainings = $trainings_stmt->fetchAll();

// Get counts for statistics
$stats_query = "
    SELECT 
        COUNT(*) as total_records,
        SUM(CASE WHEN tr.certificate_issued = 1 THEN 1 ELSE 0 END) as certified_count,
        SUM(CASE WHEN tr.completion_status = 'completed' AND tr.certificate_issued = 0 THEN 1 ELSE 0 END) as completed_pending,
        SUM(CASE WHEN tr.completion_status = 'in_progress' THEN 1 ELSE 0 END) as in_progress_count,
        SUM(CASE WHEN tr.status = 'registered' THEN 1 ELSE 0 END) as registered_count,
        SUM(CASE WHEN tr.status = 'cancelled' THEN 1 ELSE 0 END) as cancelled_count
    FROM training_registrations tr
    JOIN volunteers v ON tr.volunteer_id = v.id
    JOIN trainings t ON tr.training_id = t.id
    $where_clause
";

$stats_stmt = $pdo->prepare($stats_query);
$stats_stmt->execute($params);
$stats = $stats_stmt->fetch();

// Handle AJAX requests for record details
if (isset($_GET['ajax']) && $_GET['ajax'] === 'true') {
    if (isset($_GET['get_record_details'])) {
        echo json_encode(getRecordDetails($_GET['id']));
        exit();
    }
}

function getRecordDetails($registration_id) {
    global $pdo;
    
    $query = "SELECT 
                tr.*,
                v.id as volunteer_id,
                v.first_name,
                v.middle_name,
                v.last_name,
                CONCAT(v.first_name, ' ', COALESCE(v.middle_name, ''), ' ', v.last_name) as volunteer_full_name,
                v.email as volunteer_email,
                v.contact_number,
                v.address,
                v.date_of_birth,
                v.volunteer_status,
                v.training_completion_status,
                t.title as training_title,
                t.description,
                t.training_date,
                t.training_end_date,
                t.duration_hours,
                t.instructor,
                t.location,
                t.max_participants,
                t.current_participants,
                tr.completion_proof,
                tr.completion_notes,
                tr.completion_verified_by,
                tr.completion_verified_at,
                tc.certificate_number,
                tc.issue_date,
                tc.expiry_date,
                tc.certificate_file,
                tc.verified as certificate_verified,
                u.first_name as verifier_first_name,
                u.last_name as verifier_last_name,
                u2.first_name as completion_verifier_first_name,
                u2.last_name as completion_verifier_last_name
              FROM training_registrations tr
              JOIN volunteers v ON tr.volunteer_id = v.id
              JOIN trainings t ON tr.training_id = t.id
              LEFT JOIN training_certificates tc ON tr.id = tc.registration_id
              LEFT JOIN users u ON tc.issued_by = u.id
              LEFT JOIN users u2 ON tr.completion_verified_by = u2.id
              WHERE tr.id = ?";
    
    $stmt = $pdo->prepare($query);
    $stmt->execute([$registration_id]);
    $data = $stmt->fetch();
    
    if ($data) {
        // Get certificate download link if exists
        $download_link = null;
        if ($data['certificate_file']) {
            $download_link = '../../' . $data['certificate_file'];
        }
        
        return [
            'success' => true,
            'data' => $data,
            'download_link' => $download_link
        ];
    } else {
        return [
            'success' => false,
            'message' => 'Training record not found'
        ];
    }
}

$stmt = null;
$records_stmt = null;
$volunteers_stmt = null;
$trainings_stmt = null;
$stats_stmt = null;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>View Training Records - Fire & Rescue Services</title>
    <link href='https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css' rel='stylesheet'>
    <link rel="icon" type="image/png" sizes="32x32" href="../../img/frsm-logo.png">
    <link rel="stylesheet" href="../../css/dashboard.css">
    <style>
        :root {
            --primary-color: #dc2626;
            --primary-dark: #b91c1c;
            --secondary-color: #ef4444;
            --background-color: #f8fafc;
            --text-color: #1f2937;
            --text-light: #6b7280;
            --border-color: #e5e7eb;
            --card-bg: #ffffff;
            --sidebar-bg: #ffffff;

            --icon-red: #ef4444;
            --icon-blue: #3b82f6;
            --icon-green: #10b981;
            --icon-purple: #8b5cf6;
            --icon-yellow: #f59e0b;
            --icon-indigo: #6366f1;
            --icon-cyan: #06b6d4;
            --icon-orange: #f97316;
            --icon-pink: #ec4899;
            --icon-teal: #14b8a6;
            
            --icon-bg-red: #fee2e2;
            --icon-bg-blue: #dbeafe;
            --icon-bg-green: #dcfce7;
            --icon-bg-purple: #f3e8ff;
            --icon-bg-yellow: #fef3c7;
            --icon-bg-indigo: #e0e7ff;
            --icon-bg-cyan: #cffafe;
            --icon-bg-orange: #ffedd5;
            --icon-bg-pink: #fce7f3;
            --icon-bg-teal: #ccfbf1;

            --chart-red: #ef4444;
            --chart-orange: #f97316;
            --chart-yellow: #f59e0b;
            --chart-green: #10b981;
            --chart-blue: #3b82f6;
            --chart-purple: #8b5cf6;
            --chart-pink: #ec4899;

            --primary: var(--primary-color);
            --primary-dark: var(--primary-dark);
            --secondary: var(--secondary-color);
            --success: var(--icon-green);
            --warning: var(--icon-yellow);
            --danger: var(--primary-color);
            --info: var(--icon-blue);
            --light: #f9fafb;
            --dark: #1f2937;
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
        
        /* Dark mode variables */
        .dark-mode {
            --background-color: #0f172a;
            --text-color: #f1f5f9;
            --text-light: #94a3b8;
            --border-color: #334155;
            --card-bg: #1e293b;
            --sidebar-bg: #1e293b;
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            font-size: 14px;
            line-height: 1.5;
            color: var(--text-color);
            background-color: var(--background-color);
            margin: 0;
            padding: 0;
        }

        h1, h2, h3, h4, h5, h6 {
            font-weight: 600;
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
            border-bottom: 1px solid var(--border-color);
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

        .dashboard-actions {
            display: flex;
            gap: 12px;
            margin-top: 20px;
        }

        .primary-button, .secondary-button {
            padding: 12px 20px;
            border-radius: 12px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 8px;
            border: none;
            font-size: 14px;
        }

        .primary-button {
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            color: white;
            box-shadow: 0 4px 15px rgba(220, 38, 38, 0.3);
        }

        .primary-button:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(220, 38, 38, 0.4);
        }

        .secondary-button {
            background: var(--gray-100);
            border: 1px solid var(--border-color);
            color: var(--text-color);
        }

        .secondary-button:hover {
            background: var(--gray-200);
            transform: translateY(-2px);
        }

        .dark-mode .secondary-button {
            background: var(--gray-800);
            border-color: var(--gray-700);
        }

        .dark-mode .secondary-button:hover {
            background: var(--gray-700);
        }

        .records-container {
            display: flex;
            flex-direction: column;
            gap: 24px;
            padding: 0 40px 40px;
        }
        
        .records-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 24px;
        }
        
        .records-title {
            font-size: 28px;
            font-weight: 800;
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            margin-bottom: 8px;
        }
        
        .records-subtitle {
            color: var(--text-light);
            font-size: 16px;
        }
        
        .filters-container {
            display: flex;
            gap: 16px;
            margin-bottom: 24px;
            flex-wrap: wrap;
            align-items: flex-end;
            position: relative;
            z-index: 100;
        }
        
        .filter-group {
            display: flex;
            flex-direction: column;
            gap: 8px;
            position: relative;
            z-index: 101;
        }
        
        .filter-label {
            font-size: 14px;
            font-weight: 600;
            color: var(--gray-700);
        }
        
        .dark-mode .filter-label {
            color: var(--gray-300);
        }
        
        .filter-select, .filter-input {
            padding: 10px 16px;
            border-radius: 10px;
            border: 1px solid var(--border-color);
            background: var(--card-bg);
            font-size: 14px;
            min-width: 180px;
            transition: all 0.3s ease;
            position: relative;
            z-index: 101;
        }
        
        .filter-select:focus, .filter-input:focus {
            outline: none;
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(220, 38, 38, 0.1);
            z-index: 102;
        }
        
        .stats-container {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
            gap: 16px;
            margin-bottom: 24px;
            position: relative;
            z-index: 1;
        }
        
        .stat-card {
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
            border-radius: 16px;
            padding: 20px;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            text-align: center;
            transition: all 0.3s ease;
            cursor: pointer;
            position: relative;
            overflow: hidden;
        }
        
        .stat-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
        }
        
        .stat-card[data-status="all"]::before {
            background: linear-gradient(90deg, var(--primary-color), var(--secondary-color));
        }
        
        .stat-card[data-status="registered"]::before {
            background: var(--info);
        }
        
        .stat-card[data-status="in_progress"]::before {
            background: var(--warning);
        }
        
        .stat-card[data-status="completed"]::before {
            background: var(--success);
        }
        
        .stat-card[data-status="certified"]::before {
            background: var(--purple);
        }
        
        .stat-card[data-status="cancelled"]::before {
            background: var(--danger);
        }
        
        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.15);
        }
        
        .stat-card.active {
            border-color: var(--primary-color);
            box-shadow: 0 0 0 2px rgba(220, 38, 38, 0.2);
        }
        
        .stat-icon {
            font-size: 28px;
            margin-bottom: 12px;
            padding: 12px;
            border-radius: 12px;
            background: rgba(220, 38, 38, 0.1);
            color: var(--primary-color);
            display: flex;
            align-items: center;
            justify-content: center;
            width: 52px;
            height: 52px;
            flex-shrink: 0;
        }
        
        .stat-card[data-status="registered"] .stat-icon {
            background: rgba(59, 130, 246, 0.1);
            color: var(--info);
        }
        
        .stat-card[data-status="in_progress"] .stat-icon {
            background: rgba(245, 158, 11, 0.1);
            color: var(--warning);
        }
        
        .stat-card[data-status="completed"] .stat-icon {
            background: rgba(16, 185, 129, 0.1);
            color: var(--success);
        }
        
        .stat-card[data-status="certified"] .stat-icon {
            background: rgba(139, 92, 246, 0.1);
            color: var(--purple);
        }
        
        .stat-card[data-status="cancelled"] .stat-icon {
            background: rgba(220, 38, 38, 0.1);
            color: var(--danger);
        }
        
        .stat-value {
            font-size: 28px;
            font-weight: 800;
            margin-bottom: 4px;
        }
        
        .stat-label {
            font-size: 14px;
            color: var(--text-light);
        }
        
        .records-table-container {
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
            border-radius: 16px;
            overflow: hidden;
        }
        
        .records-table {
            width: 100%;
            border-collapse: collapse;
        }
        
        .records-table thead {
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
        }
        
        .records-table th {
            padding: 16px;
            text-align: left;
            color: white;
            font-weight: 600;
            font-size: 14px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .records-table th i {
            margin-right: 8px;
        }
        
        .records-table tbody tr {
            border-bottom: 1px solid var(--border-color);
            transition: all 0.3s ease;
        }
        
        .records-table tbody tr:hover {
            background: rgba(220, 38, 38, 0.05);
        }
        
        .records-table tbody tr:last-child {
            border-bottom: none;
        }
        
        .records-table td {
            padding: 16px;
            font-size: 14px;
            vertical-align: middle;
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
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 700;
            font-size: 16px;
            flex-shrink: 0;
        }
        
        .volunteer-details {
            display: flex;
            flex-direction: column;
        }
        
        .volunteer-name {
            font-weight: 600;
            margin-bottom: 2px;
        }
        
        .volunteer-email {
            font-size: 12px;
            color: var(--text-light);
        }
        
        .training-info {
            display: flex;
            flex-direction: column;
        }
        
        .training-title {
            font-weight: 600;
            margin-bottom: 4px;
        }
        
        .training-date {
            font-size: 12px;
            color: var(--text-light);
        }
        
        .status-badge {
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            text-transform: uppercase;
            display: inline-block;
            text-align: center;
        }
        
        .status-registered {
            background: rgba(59, 130, 246, 0.1);
            color: var(--info);
        }
        
        .status-in_progress {
            background: rgba(245, 158, 11, 0.1);
            color: var(--warning);
        }
        
        .status-completed {
            background: rgba(16, 185, 129, 0.1);
            color: var(--success);
        }
        
        .status-certified {
            background: rgba(139, 92, 246, 0.1);
            color: var(--purple);
        }
        
        .status-cancelled {
            background: rgba(220, 38, 38, 0.1);
            color: var(--danger);
        }
        
        .certificate-info {
            display: flex;
            flex-direction: column;
            gap: 4px;
        }
        
        .certificate-number {
            font-weight: 600;
            font-size: 13px;
        }
        
        .certificate-dates {
            font-size: 11px;
            color: var(--text-light);
        }
        
        .expiry-badge {
            padding: 4px 8px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 600;
            display: inline-block;
            margin-top: 4px;
        }
        
        .expiry-valid {
            background: rgba(16, 185, 129, 0.1);
            color: var(--success);
        }
        
        .expiry-warning {
            background: rgba(245, 158, 11, 0.1);
            color: var(--warning);
        }
        
        .expiry-expired {
            background: rgba(220, 38, 38, 0.1);
            color: var(--danger);
        }
        
        .action-buttons {
            display: flex;
            gap: 8px;
        }
        
        .action-button {
            padding: 6px 12px;
            border-radius: 8px;
            font-weight: 500;
            border: none;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 6px;
            transition: all 0.3s ease;
            font-size: 13px;
        }
        
        .view-button {
            background-color: rgba(59, 130, 246, 0.1);
            color: var(--info);
        }
        
        .view-button:hover {
            background-color: var(--info);
            color: white;
        }
        
        .download-button {
            background-color: rgba(16, 185, 129, 0.1);
            color: var(--success);
        }
        
        .download-button:hover {
            background-color: var(--success);
            color: white;
        }
        
        .verify-button {
            background-color: rgba(139, 92, 246, 0.1);
            color: var(--purple);
        }
        
        .verify-button:hover {
            background-color: var(--purple);
            color: white;
        }
        
        .table-footer {
            padding: 16px;
            border-top: 1px solid var(--border-color);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .pagination {
            display: flex;
            gap: 8px;
            align-items: center;
        }
        
        .pagination-button {
            padding: 8px 12px;
            border-radius: 8px;
            border: 1px solid var(--border-color);
            background: var(--card-bg);
            color: var(--text-color);
            cursor: pointer;
            transition: all 0.3s ease;
        }
        
        .pagination-button:hover {
            background: var(--gray-100);
        }
        
        .pagination-button.active {
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            color: white;
            border-color: transparent;
        }
        
        .pagination-button:disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }
        
        .records-per-page {
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .no-records {
            text-align: center;
            padding: 60px 20px;
            color: var(--text-light);
        }
        
        .no-records-icon {
            font-size: 64px;
            margin-bottom: 16px;
            color: var(--text-light);
            opacity: 0.5;
        }
        
        /* Modal Styles */
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
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.2);
            border-radius: 20px;
            width: 90%;
            max-width: 1000px;
            max-height: 90vh;
            overflow-y: auto;
            transform: scale(0.9);
            transition: all 0.3s ease;
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
        }
        
        .modal-title {
            font-size: 24px;
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
        }
        
        .modal-section {
            margin-bottom: 30px;
        }
        
        .modal-section-title {
            font-size: 18px;
            font-weight: 700;
            margin-bottom: 16px;
            padding-bottom: 8px;
            border-bottom: 1px solid var(--border-color);
            color: var(--primary-color);
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .modal-section-title i {
            font-size: 20px;
        }
        
        .modal-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(250px, 1fr));
            gap: 16px;
        }
        
        .modal-detail {
            margin-bottom: 12px;
        }
        
        .modal-detail-label {
            font-size: 14px;
            color: var(--text-light);
            margin-bottom: 4px;
        }
        
        .modal-detail-value {
            font-size: 16px;
            font-weight: 500;
        }
        
        .modal-footer {
            padding: 20px 24px;
            border-top: 1px solid var(--border-color);
            display: flex;
            justify-content: flex-end;
            gap: 12px;
        }
        
        .modal-button {
            padding: 10px 20px;
            border-radius: 10px;
            font-weight: 600;
            border: none;
            cursor: pointer;
            transition: all 0.3s ease;
        }
        
        .modal-download {
            background: var(--success);
            color: white;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .modal-download:hover {
            background: #0d8c5f;
        }
        
        .modal-secondary {
            background: var(--gray-200);
            color: var(--gray-700);
        }
        
        .dark-mode .modal-secondary {
            background: var(--gray-700);
            color: var(--gray-200);
        }
        
        .modal-secondary:hover {
            background: var(--gray-300);
        }
        
        .dark-mode .modal-secondary:hover {
            background: var(--gray-600);
        }
        
        /* Loading Animation */
        .dashboard-animation {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: var(--background-color);
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            z-index: 9999;
            transition: opacity 0.5s ease;
        }

        .animation-logo {
            display: flex;
            align-items: center;
            gap: 16px;
            margin-bottom: 30px;
            opacity: 0;
            transform: translateY(20px);
            transition: all 0.5s ease;
        }

        .animation-logo-icon img {
            width: 70px;
            height: 75px;
            filter: drop-shadow(0 4px 8px rgba(0, 0, 0, 0.2));
        }

        .animation-logo-text {
            font-size: 28px;
            font-weight: 800;
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }

        .animation-progress {
            width: 200px;
            height: 4px;
            background: var(--gray-200);
            border-radius: 2px;
            overflow: hidden;
            margin-bottom: 20px;
        }

        .animation-progress-fill {
            height: 100%;
            background: linear-gradient(90deg, var(--primary-color), var(--secondary-color));
            border-radius: 2px;
            transition: width 1s ease;
            width: 0%;
        }

        .animation-text {
            font-size: 16px;
            color: var(--text-light);
            opacity: 0;
            transition: opacity 0.5s ease;
        }
        
        /* User Profile Dropdown - FIXED POSITIONING */
        .user-profile {
            position: relative;
            cursor: pointer;
        }

        .user-profile-dropdown {
            position: absolute;
            top: 100%;
            right: 0;
            margin-top: 8px;
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.2);
            border-radius: 12px;
            padding: 8px;
            min-width: 200px;
            z-index: 1001;
            opacity: 0;
            visibility: hidden;
            transform: translateY(-10px);
            transition: all 0.3s ease;
        }

        .user-profile-dropdown.show {
            opacity: 1;
            visibility: visible;
            transform: translateY(0);
        }

        .dropdown-item {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 12px 16px;
            border-radius: 8px;
            color: var(--text-color);
            text-decoration: none;
            transition: all 0.3s ease;
        }

        .dropdown-item:hover {
            background: rgba(220, 38, 38, 0.1);
        }

        .dropdown-item i {
            font-size: 18px;
            color: var(--primary-color);
        }

        .dropdown-divider {
            height: 1px;
            background: var(--border-color);
            margin: 8px 0;
        }

        /* Notification Bell - FIXED POSITIONING */
        .notification-bell {
            position: relative;
        }

        .notification-badge {
            position: absolute;
            top: -5px;
            right: -5px;
            background: var(--danger);
            color: white;
            border-radius: 50%;
            width: 18px;
            height: 18px;
            font-size: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 600;
        }

        /* Notification Dropdown - FIXED POSITIONING */
        .notification-dropdown {
            position: absolute;
            top: 100%;
            right: 0;
            margin-top: 8px;
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.2);
            border-radius: 12px;
            width: 320px;
            max-height: 400px;
            overflow-y: auto;
            z-index: 1001;
            opacity: 0;
            visibility: hidden;
            transform: translateY(-10px);
            transition: all 0.3s ease;
        }

        .notification-dropdown.show {
            opacity: 1;
            visibility: visible;
            transform: translateY(0);
        }

        .notification-header {
            padding: 16px;
            border-bottom: 1px solid var(--border-color);
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .notification-title {
            font-size: 16px;
            font-weight: 600;
        }

        .notification-clear {
            background: none;
            border: none;
            color: var(--primary-color);
            font-size: 14px;
            cursor: pointer;
        }

        .notification-list {
            padding: 8px 0;
        }

        .notification-item {
            display: flex;
            align-items: flex-start;
            gap: 12px;
            padding: 12px 16px;
            transition: all 0.3s ease;
            cursor: pointer;
        }

        .notification-item:hover {
            background: rgba(220, 38, 38, 0.05);
        }

        .notification-item.unread {
            background: rgba(59, 130, 246, 0.05);
        }

        .notification-item-icon {
            font-size: 18px;
            margin-top: 2px;
            flex-shrink: 0;
        }

        .notification-item-content {
            flex: 1;
        }

        .notification-item-title {
            font-size: 14px;
            font-weight: 500;
            margin-bottom: 4px;
        }

        .notification-item-message {
            font-size: 13px;
            color: var(--text-light);
            margin-bottom: 4px;
        }

        .notification-item-time {
            font-size: 12px;
            color: var(--text-light);
        }

        .notification-empty {
            padding: 40px 20px;
            text-align: center;
            color: var(--text-light);
        }

        .notification-empty i {
            font-size: 32px;
            margin-bottom: 12px;
            opacity: 0.5;
        }

        /* Alert messages */
        .alert {
            padding: 15px 20px;
            border-radius: 10px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 12px;
            animation: slideIn 0.3s ease;
        }
        
        @keyframes slideIn {
            from {
                transform: translateY(-20px);
                opacity: 0;
            }
            to {
                transform: translateY(0);
                opacity: 1;
            }
        }
        
        .alert-success {
            background: rgba(16, 185, 129, 0.1);
            border: 1px solid rgba(16, 185, 129, 0.2);
            color: var(--success);
        }
        
        .alert-info {
            background: rgba(59, 130, 246, 0.1);
            border: 1px solid rgba(59, 130, 246, 0.2);
            color: var(--info);
        }
        
        .alert i {
            font-size: 20px;
        }
        
        /* Responsive Table */
        @media (max-width: 1200px) {
            .records-table {
                display: block;
                overflow-x: auto;
            }
            
            .records-table th:nth-child(4),
            .records-table td:nth-child(4),
            .records-table th:nth-child(5),
            .records-table td:nth-child(5),
            .records-table th:nth-child(6),
            .records-table td:nth-child(6) {
                min-width: 150px;
            }
        }
        
        @media (max-width: 768px) {
            .dashboard-header {
                padding: 40px 25px 30px;
            }
            
            .dashboard-title {
                font-size: 32px;
            }
            
            .records-container {
                padding: 0 25px 30px;
            }
            
            .stats-container {
                grid-template-columns: repeat(2, 1fr);
            }
            
            .filters-container {
                flex-direction: column;
            }
            
            .filter-select, .filter-input {
                min-width: 100%;
            }
            
            .action-buttons {
                flex-direction: column;
            }
            
            .table-footer {
                flex-direction: column;
                gap: 16px;
            }
            
            .modal-grid {
                grid-template-columns: 1fr;
            }
        }
        
        @media (max-width: 480px) {
            .stats-container {
                grid-template-columns: 1fr;
            }
            
            .volunteer-info {
                flex-direction: column;
                align-items: flex-start;
            }
            
            .action-buttons {
                flex-direction: column;
                align-items: flex-start;
            }
            
            .modal-footer {
                flex-direction: column;
            }
        }
        
        /* Expiry indicator */
        .expiry-indicator {
            width: 100%;
            height: 4px;
            border-radius: 2px;
            margin-top: 4px;
        }
        
        .expiry-indicator.valid {
            background: var(--success);
        }
        
        .expiry-indicator.warning {
            background: var(--warning);
        }
        
        .expiry-indicator.expired {
            background: var(--danger);
        }
        
        /* Certificate preview */
        .certificate-preview {
            text-align: center;
            padding: 20px;
            border: 2px dashed var(--border-color);
            border-radius: 12px;
            margin-top: 16px;
        }
        
        .certificate-preview img {
            max-width: 100%;
            max-height: 300px;
            border-radius: 8px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
        }
    </style>
</head>
<body>

    <!-- Loading Animation -->
    <div class="dashboard-animation" id="dashboard-animation">
        <div class="animation-logo">
            <div class="animation-logo-icon">
                <img src="../../img/frsm-logo.png" alt="Fire & Rescue Logo">
            </div>
            <span class="animation-logo-text">Fire & Rescue</span>
        </div>
        <div class="animation-progress">
            <div class="animation-progress-fill" id="animation-progress"></div>
        </div>
        <div class="animation-text" id="animation-text">Loading Training Records...</div>
    </div>
    
    <!-- Record Details Modal -->
    <div class="modal-overlay" id="record-modal">
        <div class="modal">
            <div class="modal-header">
                <h2 class="modal-title">Training Record Details</h2>
                <button class="modal-close" id="modal-close">&times;</button>
            </div>
            <div class="modal-body" id="modal-body">
                <!-- Content will be loaded via JavaScript -->
            </div>
            <div class="modal-footer">
                <button class="modal-button modal-secondary" id="modal-close-btn">Close</button>
                <button class="modal-button modal-download" id="modal-download-btn" style="display: none;">
                    <i class='bx bx-download'></i>
                    Download Certificate
                </button>
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
                    <a href="../admin_dashboard.php" class="menu-item" id="dashboard-menu">
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
                        <a href="#" class="submenu-item">Manage Users</a>
                        <a href="#" class="submenu-item">Role Control</a>
                        <a href="#" class="submenu-item">Monitor Activity</a>
                        <a href="#" class="submenu-item">Reset Passwords</a>
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
                        <a href="../review_data.php" class="submenu-item">Review Data</a>
                        <a href="../approve-applications.php" class="submenu-item">Approve Applications</a>
                        <a href="../assign-volunteers.php" class="submenu-item">Assign Volunteers</a>
                        <a href="../view-availability.php" class="submenu-item">View Availability</a>
                        <a href="../remove-volunteers.php" class="submenu-item">Remove Volunteers</a>
                        <a href="../toggle_volunteer_registration.php" class="submenu-item">Toggle Volunteer Registration Access</a>
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
                        <a href="../rm/view_equipment.php" class="submenu-item">View Equipment</a>
                        <a href="../rm/approve_maintenance.php" class="submenu-item">Approve Maintenance</a>
                        <a href="../rm/approve_resources.php" class="submenu-item">Approve Resources</a>
                        <a href="../rm/review_deployment.php" class="submenu-item">Review Deployment</a>
                    </div>
                    
                    <!-- Shift & Duty Scheduling -->
                    <div class="menu-item" onclick="toggleSubmenu('schedule-management')">
                        <div class="icon-box icon-bg-purple">
                            <i class='bx bxs-calendar icon-purple'></i>
                        </div>
                        <span class="font-medium">Schedule Management</span>
                        <svg class="dropdown-arrow menu-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                        </svg>
                    </div>
                    <div id="schedule-management" class="submenu">
                        <a href="#" class="submenu-item">Create Schedule</a>
                        <a href="#" class="submenu-item">Approve Shifts</a>
                        <a href="#" class="submenu-item">Override Assignments</a>
                        <a href="#" class="submenu-item">Monitor Attendance</a>
                    </div>
                    
                   <!-- Training & Certification Monitoring -->
                    <div class="menu-item active" onclick="toggleSubmenu('training-management')">
                        <div class="icon-box icon-bg-teal">
                            <i class='bx bxs-graduation icon-teal'></i>
                        </div>
                        <span class="font-medium">Training Management</span>
                        <svg class="dropdown-arrow menu-icon rotated" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                        </svg>
                    </div>
                    <div id="training-management" class="submenu active">
                        <a href="approve_completions.php" class="submenu-item">Approve Completions</a>
                        <a href="view_training_records.php" class="submenu-item active">View Records</a>
                        <a href="assign_training.php" class="submenu-item">Assign Training</a>
                        <a href="track_expiry.php" class="submenu-item">Track Expiry</a>
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
                            <input type="text" placeholder="Search training records..." class="search-input" id="search-input" value="<?php echo htmlspecialchars($search_term); ?>">
                            <kbd class="search-shortcut">/</kbd>
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
                        <div class="notification-bell">
                            <button class="header-button" id="notification-bell">
                                <svg class="header-button-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"></path>
                                </svg>
                            </button>
                            <div class="notification-badge" id="notification-count">3</div>
                            <div class="notification-dropdown" id="notification-dropdown">
                                <div class="notification-header">
                                    <h3 class="notification-title">Notifications</h3>
                                    <button class="notification-clear">Clear All</button>
                                </div>
                                <div class="notification-list" id="notification-list">
                                    <div class="notification-item unread">
                                        <i class='bx bxs-user-plus notification-item-icon' style="color: var(--success);"></i>
                                        <div class="notification-item-content">
                                            <div class="notification-item-title">New Volunteer Application</div>
                                            <div class="notification-item-message">Maria Santos submitted a volunteer application</div>
                                            <div class="notification-item-time">5 minutes ago</div>
                                        </div>
                                    </div>
                                    <div class="notification-item unread">
                                        <i class='bx bxs-bell-ring notification-item-icon' style="color: var(--warning);"></i>
                                        <div class="notification-item-content">
                                            <div class="notification-item-title">Training Reminder</div>
                                            <div class="notification-item-message">Basic Firefighting training scheduled for tomorrow</div>
                                            <div class="notification-item-time">1 hour ago</div>
                                        </div>
                                    </div>
                                    <div class="notification-item">
                                        <i class='bx bxs-check-circle notification-item-icon' style="color: var(--success);"></i>
                                        <div class="notification-item-content">
                                            <div class="notification-item-title">Application Approved</div>
                                            <div class="notification-item-message">Carlos Mendoza's application was approved</div>
                                            <div class="notification-item-time">2 hours ago</div>
                                        </div>
                                    </div>
                                    <div class="notification-item">
                                        <i class='bx bxs-error notification-item-icon' style="color: var(--danger);"></i>
                                        <div class="notification-item-content">
                                            <div class="notification-item-title">System Update</div>
                                            <div class="notification-item-message">Scheduled maintenance this weekend</div>
                                            <div class="notification-item-time">Yesterday</div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="user-profile" id="user-profile">
                            <img src="../../img/rei.jfif" alt="User" class="user-avatar">
                            <div class="user-info">
                                <p class="user-name"><?php echo $full_name; ?></p>
                                <p class="user-email"><?php echo $role; ?></p>
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
                <?php if (isset($_GET['success'])): ?>
                    <div class="alert alert-info">
                        <i class='bx bx-info-circle'></i>
                        <div>
                            <strong>Success!</strong> Action completed successfully.
                        </div>
                    </div>
                <?php endif; ?>
                
                <div class="dashboard-header">
                    <div>
                        <h1 class="dashboard-title">Training Records</h1>
                        <p class="dashboard-subtitle">View all training records and certificates for volunteers</p>
                    </div>
                    <div class="dashboard-actions">
                        <button class="primary-button" id="export-button">
                            <i class='bx bx-export'></i>
                            Export Records
                        </button>
                        <button class="secondary-button" id="refresh-button">
                            <i class='bx bx-refresh'></i>
                            Refresh Data
                        </button>
                    </div>
                </div>
                
                <!-- Training Records Section -->
                <div class="records-container">
                    <!-- Stats Cards -->
                    <div class="stats-container">
                        <div class="stat-card <?php echo $status_filter === 'all' ? 'active' : ''; ?>" data-status="all">
                            <div class="stat-icon">
                                <i class='bx bxs-book'></i>
                            </div>
                            <div class="stat-value"><?php echo $stats['total_records']; ?></div>
                            <div class="stat-label">Total Records</div>
                        </div>
                        <div class="stat-card <?php echo $status_filter === 'registered' ? 'active' : ''; ?>" data-status="registered">
                            <div class="stat-icon">
                                <i class='bx bx-time-five'></i>
                            </div>
                            <div class="stat-value"><?php echo $stats['registered_count']; ?></div>
                            <div class="stat-label">Registered</div>
                        </div>
                        <div class="stat-card <?php echo $status_filter === 'in_progress' ? 'active' : ''; ?>" data-status="in_progress">
                            <div class="stat-icon">
                                <i class='bx bx-loader-circle'></i>
                            </div>
                            <div class="stat-value"><?php echo $stats['in_progress_count']; ?></div>
                            <div class="stat-label">In Progress</div>
                        </div>
                        <div class="stat-card <?php echo $status_filter === 'completed' ? 'active' : ''; ?>" data-status="completed">
                            <div class="stat-icon">
                                <i class='bx bx-check-circle'></i>
                            </div>
                            <div class="stat-value"><?php echo $stats['completed_pending']; ?></div>
                            <div class="stat-label">Pending Approval</div>
                        </div>
                        <div class="stat-card <?php echo $status_filter === 'certified' ? 'active' : ''; ?>" data-status="certified">
                            <div class="stat-icon">
                                <i class='bx bx-certification'></i>
                            </div>
                            <div class="stat-value"><?php echo $stats['certified_count']; ?></div>
                            <div class="stat-label">Certified</div>
                        </div>
                        <div class="stat-card <?php echo $status_filter === 'cancelled' ? 'active' : ''; ?>" data-status="cancelled">
                            <div class="stat-icon">
                                <i class='bx bx-x-circle'></i>
                            </div>
                            <div class="stat-value"><?php echo $stats['cancelled_count']; ?></div>
                            <div class="stat-label">Cancelled</div>
                        </div>
                    </div>
                    
                    <!-- Enhanced Filters -->
                    <div class="filters-container">
                        <div class="filter-group">
                            <label class="filter-label">Status</label>
                            <select class="filter-select" id="status-filter">
                                <option value="all" <?php echo $status_filter === 'all' ? 'selected' : ''; ?>>All Statuses</option>
                                <option value="registered" <?php echo $status_filter === 'registered' ? 'selected' : ''; ?>>Registered</option>
                                <option value="in_progress" <?php echo $status_filter === 'in_progress' ? 'selected' : ''; ?>>In Progress</option>
                                <option value="completed" <?php echo $status_filter === 'completed' ? 'selected' : ''; ?>>Completed (Pending)</option>
                                <option value="certified" <?php echo $status_filter === 'certified' ? 'selected' : ''; ?>>Certified</option>
                                <option value="cancelled" <?php echo $status_filter === 'cancelled' ? 'selected' : ''; ?>>Cancelled</option>
                            </select>
                        </div>
                        <div class="filter-group">
                            <label class="filter-label">Volunteer</label>
                            <select class="filter-select" id="volunteer-filter">
                                <option value="all">All Volunteers</option>
                                <?php foreach ($all_volunteers as $volunteer): 
                                    $volunteer_name = $volunteer['first_name'] . ' ' . ($volunteer['middle_name'] ? $volunteer['middle_name'] . ' ' : '') . $volunteer['last_name'];
                                ?>
                                    <option value="<?php echo $volunteer['id']; ?>" <?php echo $volunteer_filter == $volunteer['id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($volunteer_name); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="filter-group">
                            <label class="filter-label">Training</label>
                            <select class="filter-select" id="training-filter">
                                <option value="all">All Trainings</option>
                                <?php foreach ($all_trainings as $training): ?>
                                    <option value="<?php echo $training['id']; ?>" <?php echo $training_filter == $training['id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($training['title']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="filter-group">
                            <label class="filter-label">Search</label>
                            <input type="text" class="filter-input" id="search-filter" placeholder="Search by name, email, training..." value="<?php echo htmlspecialchars($search_term); ?>">
                        </div>
                        <div class="filter-group" style="align-self: flex-end;">
                            <button class="action-button view-button" id="apply-filters">
                                <i class='bx bx-filter-alt'></i>
                                Apply Filters
                            </button>
                        </div>
                        <div class="filter-group" style="align-self: flex-end;">
                            <button class="action-button download-button" id="reset-filters">
                                <i class='bx bx-reset'></i>
                                Reset
                            </button>
                        </div>
                    </div>
                    
                    <!-- Records Table -->
                    <div class="records-table-container">
                        <?php if (count($records) > 0): ?>
                            <table class="records-table">
                                <thead>
                                    <tr>
                                        <th><i class='bx bx-user'></i> Volunteer</th>
                                        <th><i class='bx bx-book'></i> Training</th>
                                        <th><i class='bx bx-calendar'></i> Training Date</th>
                                        <th><i class='bx bx-task'></i> Status</th>
                                        <th><i class='bx bx-certification'></i> Certificate</th>
                                        <th><i class='bx bx-cog'></i> Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($records as $record): 
                                        // Calculate expiry status
                                        $expiry_status = '';
                                        $expiry_badge = '';
                                        $expiry_indicator = '';
                                        
                                        if ($record['expiry_date']) {
                                            $today = new DateTime();
                                            $expiry_date = new DateTime($record['expiry_date']);
                                            $days_until_expiry = $today->diff($expiry_date)->days;
                                            
                                            if ($expiry_date > $today) {
                                                if ($days_until_expiry <= 60) {
                                                    $expiry_status = 'Expiring in ' . $days_until_expiry . ' days';
                                                    $expiry_badge = '<span class="expiry-badge expiry-warning">' . $expiry_status . '</span>';
                                                    $expiry_indicator = 'expiry-indicator warning';
                                                } else {
                                                    $expiry_status = 'Valid';
                                                    $expiry_badge = '<span class="expiry-badge expiry-valid">Valid</span>';
                                                    $expiry_indicator = 'expiry-indicator valid';
                                                }
                                            } else {
                                                $expiry_status = 'Expired';
                                                $expiry_badge = '<span class="expiry-badge expiry-expired">Expired</span>';
                                                $expiry_indicator = 'expiry-indicator expired';
                                            }
                                        }
                                        
                                        // Determine status badge
                                        $status_badge = '';
                                        if ($record['certificate_issued']) {
                                            $status_badge = '<span class="status-badge status-certified">Certified</span>';
                                        } elseif ($record['completion_status'] === 'completed') {
                                            $status_badge = '<span class="status-badge status-completed">Completed</span>';
                                        } elseif ($record['completion_status'] === 'in_progress') {
                                            $status_badge = '<span class="status-badge status-in_progress">In Progress</span>';
                                        } elseif ($record['status'] === 'cancelled') {
                                            $status_badge = '<span class="status-badge status-cancelled">Cancelled</span>';
                                        } else {
                                            $status_badge = '<span class="status-badge status-registered">Registered</span>';
                                        }
                                    ?>
                                        <tr>
                                            <td>
                                                <div class="volunteer-info">
                                                    <div class="volunteer-avatar">
                                                        <?php echo strtoupper(substr($record['volunteer_full_name'], 0, 1)); ?>
                                                    </div>
                                                    <div class="volunteer-details">
                                                        <div class="volunteer-name"><?php echo htmlspecialchars($record['volunteer_full_name']); ?></div>
                                                        <div class="volunteer-email"><?php echo htmlspecialchars($record['volunteer_email']); ?></div>
                                                        <div style="font-size: 11px; color: var(--text-light); margin-top: 2px;">
                                                            <?php echo htmlspecialchars($record['volunteer_status']); ?>
                                                        </div>
                                                    </div>
                                                </div>
                                            </td>
                                            <td>
                                                <div class="training-info">
                                                    <div class="training-title"><?php echo htmlspecialchars($record['training_title']); ?></div>
                                                    <div class="training-date">
                                                        <?php echo date('M d, Y', strtotime($record['training_date'])); ?>
                                                        <?php if ($record['training_end_date'] && $record['training_end_date'] != $record['training_date']): ?>
                                                            - <?php echo date('M d, Y', strtotime($record['training_end_date'])); ?>
                                                        <?php endif; ?>
                                                    </div>
                                                    <div style="font-size: 11px; color: var(--text-light); margin-top: 2px;">
                                                        <?php echo $record['duration_hours']; ?> hours
                                                    </div>
                                                </div>
                                            </td>
                                            <td>
                                                <div><?php echo date('M d, Y', strtotime($record['training_date'])); ?></div>
                                                <div style="font-size: 12px; color: var(--text-light);">
                                                    <?php echo $record['instructor']; ?>
                                                </div>
                                            </td>
                                            <td>
                                                <?php echo $status_badge; ?>
                                                <div style="margin-top: 8px; font-size: 12px;">
                                                    <?php echo date('M d, Y', strtotime($record['registration_date'])); ?>
                                                </div>
                                            </td>
                                            <td>
                                                <?php if ($record['certificate_number']): ?>
                                                    <div class="certificate-info">
                                                        <div class="certificate-number"><?php echo $record['certificate_number']; ?></div>
                                                        <div class="certificate-dates">
                                                            Issued: <?php echo date('M d, Y', strtotime($record['issue_date'])); ?><br>
                                                            Expires: <?php echo date('M d, Y', strtotime($record['expiry_date'])); ?>
                                                        </div>
                                                        <?php echo $expiry_badge; ?>
                                                    </div>
                                                <?php else: ?>
                                                    <div style="color: var(--text-light); font-size: 12px; font-style: italic;">
                                                        No certificate issued
                                                    </div>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <div class="action-buttons">
                                                    <button class="action-button view-button" onclick="viewRecord(<?php echo $record['id']; ?>)">
                                                        <i class='bx bx-show'></i>
                                                        View
                                                    </button>
                                                    <?php if ($record['certificate_file']): ?>
                                                        <button class="action-button download-button" onclick="downloadCertificate('<?php echo $record['id']; ?>')">
                                                            <i class='bx bx-download'></i>
                                                            Download
                                                        </button>
                                                    <?php endif; ?>
                                                    <?php if (!$record['certificate_issued'] && $record['completion_status'] === 'completed'): ?>
                                                        <button class="action-button verify-button" onclick="location.href='approve_completions.php?registration_id=<?php echo $record['id']; ?>'">
                                                            <i class='bx bx-check'></i>
                                                            Approve
                                                        </button>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                            
                            <div class="table-footer">
                                <div class="records-per-page">
                                    <span>Showing <?php echo count($records); ?> of <?php echo $stats['total_records']; ?> records</span>
                                </div>
                                <div class="pagination">
                                    <button class="pagination-button" disabled>
                                        <i class='bx bx-chevron-left'></i>
                                    </button>
                                    <button class="pagination-button active">1</button>
                                    <button class="pagination-button">2</button>
                                    <button class="pagination-button">3</button>
                                    <button class="pagination-button">
                                        <i class='bx bx-chevron-right'></i>
                                    </button>
                                </div>
                            </div>
                        <?php else: ?>
                            <div class="no-records">
                                <div class="no-records-icon">
                                    <i class='bx bx-book-open'></i>
                                </div>
                                <h3>No Training Records Found</h3>
                                <p>No training records match your current filters.</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <script>
        let currentRecordId = null;
        let currentDownloadLink = null;
        
        document.addEventListener('DOMContentLoaded', function() {
            const animationOverlay = document.getElementById('dashboard-animation');
            const animationProgress = document.getElementById('animation-progress');
            const animationText = document.getElementById('animation-text');
            const animationLogo = document.querySelector('.animation-logo');
            
            // Show logo and text immediately
            setTimeout(() => {
                animationLogo.style.opacity = '1';
                animationLogo.style.transform = 'translateY(0)';
            }, 100);
            
            setTimeout(() => {
                animationText.style.opacity = '1';
            }, 300);
            
            // Faster loading - 1 second only
            setTimeout(() => {
                animationProgress.style.width = '100%';
            }, 100);
            
            setTimeout(() => {
                animationOverlay.style.opacity = '0';
                setTimeout(() => {
                    animationOverlay.style.display = 'none';
                }, 300);
            }, 1000);
            
            // Initialize event listeners
            initEventListeners();
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
                // Close notification dropdown if open
                notificationDropdown.classList.remove('show');
            });
            
            // Notification bell dropdown
            const notificationBell = document.getElementById('notification-bell');
            const notificationDropdown = document.getElementById('notification-dropdown');
            
            notificationBell.addEventListener('click', function(e) {
                e.stopPropagation();
                notificationDropdown.classList.toggle('show');
                // Close user dropdown if open
                userDropdown.classList.remove('show');
                
                // Mark notifications as read when dropdown is opened
                if (notificationDropdown.classList.contains('show')) {
                    document.querySelectorAll('.notification-item.unread').forEach(item => {
                        item.classList.remove('unread');
                    });
                    document.getElementById('notification-count').textContent = '0';
                }
            });
            
            // Clear all notifications
            document.querySelector('.notification-clear').addEventListener('click', function(e) {
                e.stopPropagation();
                document.getElementById('notification-list').innerHTML = `
                    <div class="notification-empty">
                        <i class='bx bxs-bell-off'></i>
                        <p>No notifications</p>
                    </div>
                `;
                document.getElementById('notification-count').textContent = '0';
            });
            
            // Close dropdowns when clicking outside
            document.addEventListener('click', function() {
                userDropdown.classList.remove('show');
                notificationDropdown.classList.remove('show');
            });
            
            // Filter functionality
            document.getElementById('apply-filters').addEventListener('click', applyFilters);
            document.getElementById('reset-filters').addEventListener('click', resetFilters);
            document.getElementById('search-filter').addEventListener('keypress', function(e) {
                if (e.key === 'Enter') {
                    applyFilters();
                }
            });
            
            // Search input in header
            document.getElementById('search-input').addEventListener('keypress', function(e) {
                if (e.key === 'Enter') {
                    document.getElementById('search-filter').value = this.value;
                    applyFilters();
                }
            });
            
            // Status filter cards
            document.querySelectorAll('.stat-card').forEach(card => {
                card.addEventListener('click', function() {
                    const status = this.getAttribute('data-status');
                    document.getElementById('status-filter').value = status;
                    applyFilters();
                });
            });
            
            // Modal functionality
            document.getElementById('modal-close').addEventListener('click', closeModal);
            document.getElementById('modal-close-btn').addEventListener('click', closeModal);
            document.getElementById('modal-download-btn').addEventListener('click', function() {
                if (currentDownloadLink) {
                    window.open(currentDownloadLink, '_blank');
                }
            });
            
            // Export and refresh buttons
            document.getElementById('export-button').addEventListener('click', exportRecords);
            document.getElementById('refresh-button').addEventListener('click', refreshData);
            
            // Keyboard shortcuts
            document.addEventListener('keydown', function(e) {
                // Search shortcut - forward slash
                if (e.key === '/' && !e.ctrlKey && !e.altKey && !e.metaKey) {
                    e.preventDefault();
                    document.getElementById('search-input').focus();
                }
                
                // Escape key to close modal
                if (e.key === 'Escape') {
                    closeModal();
                    userDropdown.classList.remove('show');
                    notificationDropdown.classList.remove('show');
                }
            });
        }
        
        function applyFilters() {
            const status = document.getElementById('status-filter').value;
            const volunteer = document.getElementById('volunteer-filter').value;
            const training = document.getElementById('training-filter').value;
            const search = document.getElementById('search-filter').value;
            
            let url = 'view_training_records.php?';
            if (status !== 'all') {
                url += `status=${status}&`;
            }
            if (volunteer !== 'all') {
                url += `volunteer=${volunteer}&`;
            }
            if (training !== 'all') {
                url += `training=${training}&`;
            }
            if (search) {
                url += `search=${encodeURIComponent(search)}`;
            }
            
            window.location.href = url;
        }
        
        function resetFilters() {
            document.getElementById('status-filter').value = 'all';
            document.getElementById('volunteer-filter').value = 'all';
            document.getElementById('training-filter').value = 'all';
            document.getElementById('search-filter').value = '';
            applyFilters();
        }
        
        function viewRecord(id) {
            currentRecordId = id;
            
            // Show loading state
            document.getElementById('modal-body').innerHTML = `
                <div style="text-align: center; padding: 40px;">
                    <i class='bx bx-loader-circle bx-spin' style="font-size: 48px; color: var(--primary-color);"></i>
                    <p style="margin-top: 16px; color: var(--text-light);">Loading record details...</p>
                </div>
            `;
            
            // Hide download button initially
            document.getElementById('modal-download-btn').style.display = 'none';
            
            // Show modal
            document.getElementById('record-modal').classList.add('active');
            
            // Fetch record details via AJAX
            fetch(`view_training_records.php?ajax=true&get_record_details=true&id=${id}`)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        populateRecordModal(data.data, data.download_link);
                    } else {
                        alert('Failed to load record details: ' + data.message);
                        closeModal();
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('Failed to load record details');
                    closeModal();
                });
        }
        
        function populateRecordModal(data, downloadLink) {
            const modalBody = document.getElementById('modal-body');
            
            // Set download link if available
            currentDownloadLink = downloadLink;
            if (currentDownloadLink) {
                document.getElementById('modal-download-btn').style.display = 'flex';
            }
            
            // Build the full name properly
            const fullName = `${data.first_name} ${data.middle_name ? data.middle_name + ' ' : ''}${data.last_name}`;
            
            // Calculate expiry status
            let expiryStatus = '';
            let expiryClass = '';
            let expiryText = '';
            
            if (data.expiry_date) {
                const today = new Date();
                const expiryDate = new Date(data.expiry_date);
                const daysUntilExpiry = Math.ceil((expiryDate - today) / (1000 * 60 * 60 * 24));
                
                if (expiryDate > today) {
                    if (daysUntilExpiry <= 60) {
                        expiryStatus = 'warning';
                        expiryText = `Expiring in ${daysUntilExpiry} days`;
                    } else {
                        expiryStatus = 'valid';
                        expiryText = 'Valid';
                    }
                } else {
                    expiryStatus = 'expired';
                    expiryText = 'Expired';
                }
            }
            
            let html = `
                <div class="modal-section">
                    <h3 class="modal-section-title">
                        <i class='bx bx-user'></i> Volunteer Information
                    </h3>
                    <div class="modal-grid">
                        <div class="modal-detail">
                            <div class="modal-detail-label">Full Name</div>
                            <div class="modal-detail-value">${fullName}</div>
                        </div>
                        <div class="modal-detail">
                            <div class="modal-detail-label">Email</div>
                            <div class="modal-detail-value">${data.volunteer_email}</div>
                        </div>
                        <div class="modal-detail">
                            <div class="modal-detail-label">Contact Number</div>
                            <div class="modal-detail-value">${data.contact_number || 'N/A'}</div>
                        </div>
                        <div class="modal-detail">
                            <div class="modal-detail-label">Date of Birth</div>
                            <div class="modal-detail-value">${data.date_of_birth || 'N/A'}</div>
                        </div>
                        <div class="modal-detail">
                            <div class="modal-detail-label">Address</div>
                            <div class="modal-detail-value">${data.address || 'N/A'}</div>
                        </div>
                        <div class="modal-detail">
                            <div class="modal-detail-label">Volunteer Status</div>
                            <div class="modal-detail-value">${data.volunteer_status}</div>
                        </div>
                        <div class="modal-detail">
                            <div class="modal-detail-label">Training Completion Status</div>
                            <div class="modal-detail-value">${data.training_completion_status || 'N/A'}</div>
                        </div>
                    </div>
                </div>
                
                <div class="modal-section">
                    <h3 class="modal-section-title">
                        <i class='bx bx-book'></i> Training Information
                    </h3>
                    <div class="modal-grid">
                        <div class="modal-detail">
                            <div class="modal-detail-label">Training Title</div>
                            <div class="modal-detail-value">${data.training_title}</div>
                        </div>
                        <div class="modal-detail">
                            <div class="modal-detail-label">Training Dates</div>
                            <div class="modal-detail-value">
                                ${new Date(data.training_date).toLocaleDateString('en-US', { year: 'numeric', month: 'long', day: 'numeric' })} 
                                ${data.training_end_date && data.training_end_date !== data.training_date ? 
                                    ' to ' + new Date(data.training_end_date).toLocaleDateString('en-US', { year: 'numeric', month: 'long', day: 'numeric' }) : ''}
                            </div>
                        </div>
                        <div class="modal-detail">
                            <div class="modal-detail-label">Duration</div>
                            <div class="modal-detail-value">${data.duration_hours} hours</div>
                        </div>
                        <div class="modal-detail">
                            <div class="modal-detail-label">Instructor</div>
                            <div class="modal-detail-value">${data.instructor}</div>
                        </div>
                        <div class="modal-detail">
                            <div class="modal-detail-label">Location</div>
                            <div class="modal-detail-value">${data.location}</div>
                        </div>
                        <div class="modal-detail">
                            <div class="modal-detail-label">Max Participants</div>
                            <div class="modal-detail-value">${data.max_participants || 'N/A'}</div>
                        </div>
                        <div class="modal-detail">
                            <div class="modal-detail-label">Current Participants</div>
                            <div class="modal-detail-value">${data.current_participants || 'N/A'}</div>
                        </div>
                    </div>
                    ${data.description ? `
                        <div class="modal-detail" style="margin-top: 16px;">
                            <div class="modal-detail-label">Description</div>
                            <div class="modal-detail-value">${data.description}</div>
                        </div>
                    ` : ''}
                </div>
                
                <div class="modal-section">
                    <h3 class="modal-section-title">
                        <i class='bx bx-task'></i> Registration Details
                    </h3>
                    <div class="modal-grid">
                        <div class="modal-detail">
                            <div class="modal-detail-label">Registration Date</div>
                            <div class="modal-detail-value">${new Date(data.registration_date).toLocaleDateString('en-US', { year: 'numeric', month: 'long', day: 'numeric' })}</div>
                        </div>
                        <div class="modal-detail">
                            <div class="modal-detail-label">Registration Status</div>
                            <div class="modal-detail-value">${data.status}</div>
                        </div>
                        <div class="modal-detail">
                            <div class="modal-detail-label">Completion Status</div>
                            <div class="modal-detail-value">${data.completion_status}</div>
                        </div>
                        <div class="modal-detail">
                            <div class="modal-detail-label">Certificate Issued</div>
                            <div class="modal-detail-value">${data.certificate_issued ? 'Yes' : 'No'}</div>
                        </div>
                    </div>
                </div>
            `;
            
            // Add completion notes if available
            if (data.completion_notes) {
                html += `
                    <div class="modal-section">
                        <h3 class="modal-section-title">
                            <i class='bx bx-note'></i> Completion Notes
                        </h3>
                        <div class="modal-detail">
                            <div class="modal-detail-value" style="white-space: pre-wrap; background: var(--gray-100); padding: 15px; border-radius: 8px;">
                                ${data.completion_notes}
                            </div>
                        </div>
                    </div>
                `;
            }
            
            // Add completion proof if available
            if (data.completion_proof) {
                const proofPath = '../../uploads/training_proofs/' + data.completion_proof;
                html += `
                    <div class="modal-section">
                        <h3 class="modal-section-title">
                            <i class='bx bx-image'></i> Completion Proof
                        </h3>
                        <div style="text-align: center;">
                            <img src="${proofPath}" alt="Completion Proof" style="max-width: 100%; max-height: 300px; border-radius: 8px; border: 1px solid var(--border-color);" 
                                 onerror="this.style.display='none'; this.nextElementSibling.style.display='block';">
                            <div style="display: none; padding: 20px; background: var(--gray-100); border-radius: 8px; color: var(--text-light);">
                                Proof image not available
                            </div>
                        </div>
                    </div>
                `;
            }
            
            // Add certificate information if available
            if (data.certificate_number) {
                const issueDate = data.issue_date ? new Date(data.issue_date).toLocaleDateString('en-US', { year: 'numeric', month: 'long', day: 'numeric' }) : 'N/A';
                const expiryDate = data.expiry_date ? new Date(data.expiry_date).toLocaleDateString('en-US', { year: 'numeric', month: 'long', day: 'numeric' }) : 'N/A';
                const verifierName = data.verifier_first_name ? `${data.verifier_first_name} ${data.verifier_last_name}` : 'N/A';
                const completionVerifier = data.completion_verifier_first_name ? `${data.completion_verifier_first_name} ${data.completion_verifier_last_name}` : 'N/A';
                
                html += `
                    <div class="modal-section">
                        <h3 class="modal-section-title" style="color: var(--success);">
                            <i class='bx bx-certification'></i> Certificate Information
                        </h3>
                        <div class="modal-grid">
                            <div class="modal-detail">
                                <div class="modal-detail-label">Certificate Number</div>
                                <div class="modal-detail-value">${data.certificate_number}</div>
                            </div>
                            <div class="modal-detail">
                                <div class="modal-detail-label">Issue Date</div>
                                <div class="modal-detail-value">${issueDate}</div>
                            </div>
                            <div class="modal-detail">
                                <div class="modal-detail-label">Expiry Date</div>
                                <div class="modal-detail-value">${expiryDate}</div>
                            </div>
                            <div class="modal-detail">
                                <div class="modal-detail-label">Certificate Status</div>
                                <div class="modal-detail-value">
                                    ${data.certificate_verified ? 'Verified' : 'Not Verified'}
                                    ${expiryText ? ` (${expiryText})` : ''}
                                </div>
                            </div>
                            <div class="modal-detail">
                                <div class="modal-detail-label">Issued By</div>
                                <div class="modal-detail-value">${verifierName}</div>
                            </div>
                            <div class="modal-detail">
                                <div class="modal-detail-label">Completion Verified By</div>
                                <div class="modal-detail-value">${completionVerifier}</div>
                            </div>
                            <div class="modal-detail">
                                <div class="modal-detail-label">Completion Verified At</div>
                                <div class="modal-detail-value">${data.completion_verified_at ? new Date(data.completion_verified_at).toLocaleDateString('en-US', { year: 'numeric', month: 'long', day: 'numeric' }) : 'N/A'}</div>
                            </div>
                        </div>
                    </div>
                `;
                
                // Add certificate preview if available
                if (currentDownloadLink) {
                    html += `
                        <div class="modal-section">
                            <h3 class="modal-section-title">
                                <i class='bx bx-file'></i> Certificate Preview
                            </h3>
                            <div class="certificate-preview">
                                <p>Click "Download Certificate" to view the full certificate</p>
                                <div style="margin-top: 16px;">
                                    <button class="action-button download-button" onclick="window.open('${currentDownloadLink}', '_blank')" style="margin: 0 auto;">
                                        <i class='bx bx-download'></i>
                                        Open Certificate
                                    </button>
                                </div>
                            </div>
                        </div>
                    `;
                }
            }
            
            modalBody.innerHTML = html;
        }
        
        function closeModal() {
            document.getElementById('record-modal').classList.remove('active');
            currentRecordId = null;
            currentDownloadLink = null;
        }
        
        function downloadCertificate(id) {
            // In a real implementation, this would trigger a download
            // For now, we'll just redirect to the certificate file
            fetch(`view_training_records.php?ajax=true&get_record_details=true&id=${id}`)
                .then(response => response.json())
                .then(data => {
                    if (data.download_link) {
                        window.open(data.download_link, '_blank');
                    } else {
                        alert('No certificate available for download');
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('Failed to download certificate');
                });
        }
        
        function exportRecords() {
            // Collect filter values
            const status = document.getElementById('status-filter').value;
            const volunteer = document.getElementById('volunteer-filter').value;
            const training = document.getElementById('training-filter').value;
            const search = document.getElementById('search-filter').value;
            
            // Create export URL
            let url = 'export_training_records.php?';
            if (status !== 'all') url += `status=${status}&`;
            if (volunteer !== 'all') url += `volunteer=${volunteer}&`;
            if (training !== 'all') url += `training=${training}&`;
            if (search) url += `search=${encodeURIComponent(search)}`;
            
            // Open export in new window
            window.open(url, '_blank');
            
            alert('Export started. The file will download shortly.');
        }
        
        function refreshData() {
            location.reload();
        }
        
        function toggleSubmenu(id) {
            const submenu = document.getElementById(id);
            const arrow = submenu.previousElementSibling.querySelector('.dropdown-arrow');
            
            submenu.classList.toggle('active');
            arrow.classList.toggle('rotated');
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
        
        updateTime();
        setInterval(updateTime, 1000);
    </script>
</body>
</html>