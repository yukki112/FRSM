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
$expiry_filter = isset($_GET['expiry']) ? $_GET['expiry'] : 'all';
$volunteer_filter = isset($_GET['volunteer']) ? $_GET['volunteer'] : 'all';
$training_filter = isset($_GET['training']) ? $_GET['training'] : 'all';
$search_term = isset($_GET['search']) ? $_GET['search'] : '';

// Build query with filters
$where_conditions = ["tc.certificate_number IS NOT NULL"];
$params = [];

if ($expiry_filter !== 'all') {
    $today = date('Y-m-d');
    
    if ($expiry_filter === 'expired') {
        $where_conditions[] = "tc.expiry_date < ?";
        $params[] = $today;
    } elseif ($expiry_filter === 'expiring_30') {
        $thirty_days = date('Y-m-d', strtotime('+30 days'));
        $where_conditions[] = "tc.expiry_date BETWEEN ? AND ?";
        $params[] = $today;
        $params[] = $thirty_days;
    } elseif ($expiry_filter === 'expiring_60') {
        $sixty_days = date('Y-m-d', strtotime('+60 days'));
        $thirty_days = date('Y-m-d', strtotime('+30 days'));
        $where_conditions[] = "tc.expiry_date BETWEEN ? AND ?";
        $params[] = $thirty_days;
        $params[] = $sixty_days;
    } elseif ($expiry_filter === 'expiring_90') {
        $ninety_days = date('Y-m-d', strtotime('+90 days'));
        $sixty_days = date('Y-m-d', strtotime('+60 days'));
        $where_conditions[] = "tc.expiry_date BETWEEN ? AND ?";
        $params[] = $sixty_days;
        $params[] = $ninety_days;
    } elseif ($expiry_filter === 'valid') {
        $where_conditions[] = "tc.expiry_date >= ?";
        $params[] = date('Y-m-d', strtotime('+90 days'));
    }
}

if ($volunteer_filter !== 'all') {
    $where_conditions[] = "tc.volunteer_id = ?";
    $params[] = $volunteer_filter;
}

if ($training_filter !== 'all') {
    $where_conditions[] = "tc.training_id = ?";
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

// Fetch certificates with expiry info
$certificates_query = "
    SELECT 
        tc.*,
        v.id as volunteer_id,
        v.first_name,
        v.middle_name,
        v.last_name,
        CONCAT(v.first_name, ' ', COALESCE(v.middle_name, ''), ' ', v.last_name) as volunteer_full_name,
        v.email as volunteer_email,
        v.contact_number,
        v.volunteer_status,
        t.id as training_id,
        t.title as training_title,
        t.description as training_description,
        t.training_date,
        DATEDIFF(tc.expiry_date, CURDATE()) as days_until_expiry,
        CASE 
            WHEN tc.expiry_date < CURDATE() THEN 'expired'
            WHEN DATEDIFF(tc.expiry_date, CURDATE()) <= 30 THEN 'expiring_30'
            WHEN DATEDIFF(tc.expiry_date, CURDATE()) <= 60 THEN 'expiring_60'
            WHEN DATEDIFF(tc.expiry_date, CURDATE()) <= 90 THEN 'expiring_90'
            ELSE 'valid'
        END as expiry_status,
        u.first_name as issued_by_first,
        u.last_name as issued_by_last
    FROM training_certificates tc
    JOIN volunteers v ON tc.volunteer_id = v.id
    JOIN trainings t ON tc.training_id = t.id
    LEFT JOIN users u ON tc.issued_by = u.id
    $where_clause 
    ORDER BY tc.expiry_date ASC, v.last_name ASC, v.first_name ASC
";

$certificates_stmt = $pdo->prepare($certificates_query);
$certificates_stmt->execute($params);
$certificates = $certificates_stmt->fetchAll();

// Get all volunteers for filter dropdown
$volunteers_query = "SELECT id, first_name, middle_name, last_name FROM volunteers WHERE status = 'approved' ORDER BY first_name, last_name";
$volunteers_stmt = $pdo->prepare($volunteers_query);
$volunteers_stmt->execute();
$all_volunteers = $volunteers_stmt->fetchAll();

// Get all trainings for filter dropdown
$trainings_query = "SELECT id, title FROM trainings WHERE id IN (SELECT DISTINCT training_id FROM training_certificates) ORDER BY title";
$trainings_stmt = $pdo->prepare($trainings_query);
$trainings_stmt->execute();
$all_trainings = $trainings_stmt->fetchAll();

// Get expiry statistics
$stats_query = "
    SELECT 
        COUNT(*) as total_certificates,
        SUM(CASE WHEN tc.expiry_date < CURDATE() THEN 1 ELSE 0 END) as expired_count,
        SUM(CASE WHEN DATEDIFF(tc.expiry_date, CURDATE()) BETWEEN 1 AND 30 THEN 1 ELSE 0 END) as expiring_30_count,
        SUM(CASE WHEN DATEDIFF(tc.expiry_date, CURDATE()) BETWEEN 31 AND 60 THEN 1 ELSE 0 END) as expiring_60_count,
        SUM(CASE WHEN DATEDIFF(tc.expiry_date, CURDATE()) BETWEEN 61 AND 90 THEN 1 ELSE 0 END) as expiring_90_count,
        SUM(CASE WHEN DATEDIFF(tc.expiry_date, CURDATE()) > 90 OR tc.expiry_date IS NULL THEN 1 ELSE 0 END) as valid_count,
        MIN(tc.expiry_date) as next_expiry,
        MAX(tc.expiry_date) as last_expiry
    FROM training_certificates tc
    $where_clause
";

$stats_stmt = $pdo->prepare($stats_query);
$stats_stmt->execute($params);
$stats = $stats_stmt->fetch();

// Calculate expiry summary
$expiry_summary = [
    'expired' => $stats['expired_count'] ?? 0,
    'expiring_30' => $stats['expiring_30_count'] ?? 0,
    'expiring_60' => $stats['expiring_60_count'] ?? 0,
    'expiring_90' => $stats['expiring_90_count'] ?? 0,
    'valid' => $stats['valid_count'] ?? 0,
    'total' => $stats['total_certificates'] ?? 0
];

// Get upcoming expiries for dashboard
$upcoming_expiries_query = "
    SELECT tc.*, v.first_name, v.middle_name, v.last_name, t.title,
           DATEDIFF(tc.expiry_date, CURDATE()) as days_left
    FROM training_certificates tc
    JOIN volunteers v ON tc.volunteer_id = v.id
    JOIN trainings t ON tc.training_id = t.id
    WHERE tc.expiry_date >= CURDATE()
    AND tc.expiry_date <= DATE_ADD(CURDATE(), INTERVAL 30 DAY)
    ORDER BY tc.expiry_date ASC
    LIMIT 10
";

$upcoming_expiries_stmt = $pdo->prepare($upcoming_expiries_query);
$upcoming_expiries_stmt->execute();
$upcoming_expiries = $upcoming_expiries_stmt->fetchAll();

// Get recently expired certificates
$recently_expired_query = "
    SELECT tc.*, v.first_name, v.middle_name, v.last_name, t.title,
           DATEDIFF(CURDATE(), tc.expiry_date) as days_expired
    FROM training_certificates tc
    JOIN volunteers v ON tc.volunteer_id = v.id
    JOIN trainings t ON tc.training_id = t.id
    WHERE tc.expiry_date < CURDATE()
    ORDER BY tc.expiry_date DESC
    LIMIT 10
";

$recently_expired_stmt = $pdo->prepare($recently_expired_query);
$recently_expired_stmt->execute();
$recently_expired = $recently_expired_stmt->fetchAll();

// Handle AJAX requests for certificate details
if (isset($_GET['ajax']) && $_GET['ajax'] === 'true') {
    if (isset($_GET['get_certificate_details'])) {
        echo json_encode(getCertificateDetails($_GET['id']));
        exit();
    }
}

function getCertificateDetails($certificate_id) {
    global $pdo;
    
    $query = "SELECT 
                tc.*,
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
                t.title as training_title,
                t.description,
                t.training_date,
                t.training_end_date,
                t.duration_hours,
                t.instructor,
                t.location,
                DATEDIFF(tc.expiry_date, CURDATE()) as days_until_expiry,
                CASE 
                    WHEN tc.expiry_date < CURDATE() THEN 'expired'
                    WHEN DATEDIFF(tc.expiry_date, CURDATE()) <= 30 THEN 'expiring_30'
                    WHEN DATEDIFF(tc.expiry_date, CURDATE()) <= 60 THEN 'expiring_60'
                    WHEN DATEDIFF(tc.expiry_date, CURDATE()) <= 90 THEN 'expiring_90'
                    ELSE 'valid'
                END as expiry_status,
                u.first_name as issued_by_first,
                u.last_name as issued_by_last,
                u2.first_name as verified_by_first,
                u2.last_name as verified_by_last
              FROM training_certificates tc
              JOIN volunteers v ON tc.volunteer_id = v.id
              JOIN trainings t ON tc.training_id = t.id
              LEFT JOIN users u ON tc.issued_by = u.id
              LEFT JOIN users u2 ON tc.verified_by = u2.id
              WHERE tc.id = ?";
    
    $stmt = $pdo->prepare($query);
    $stmt->execute([$certificate_id]);
    $data = $stmt->fetch();
    
    if ($data) {
        // Get certificate download link
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
            'message' => 'Certificate not found'
        ];
    }
}

$stmt = null;
$certificates_stmt = null;
$volunteers_stmt = null;
$trainings_stmt = null;
$stats_stmt = null;
$upcoming_expiries_stmt = null;
$recently_expired_stmt = null;
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Track Certificate Expiry - Fire & Rescue Services</title>
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

        .track-container {
            display: flex;
            flex-direction: column;
            gap: 24px;
            padding: 0 40px 40px;
        }
        
        .track-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 24px;
        }
        
        .track-title {
            font-size: 28px;
            font-weight: 800;
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            margin-bottom: 8px;
        }
        
        .track-subtitle {
            color: var(--text-light);
            font-size: 16px;
        }
        
        .filters-container {
            display: flex;
            gap: 16px;
            margin-bottom: 24px;
            flex-wrap: wrap;
            align-items: flex-end;
        }
        
        .filter-group {
            display: flex;
            flex-direction: column;
            gap: 8px;
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
        }
        
        .filter-select:focus, .filter-input:focus {
            outline: none;
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(220, 38, 38, 0.1);
        }
        
        .stats-container {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
            gap: 16px;
            margin-bottom: 24px;
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
        
        .stat-card[data-status="expired"]::before {
            background: var(--danger);
        }
        
        .stat-card[data-status="expiring_30"]::before {
            background: var(--warning);
        }
        
        .stat-card[data-status="expiring_60"]::before {
            background: var(--orange);
        }
        
        .stat-card[data-status="expiring_90"]::before {
            background: var(--info);
        }
        
        .stat-card[data-status="valid"]::before {
            background: var(--success);
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
        
        .stat-card[data-status="expired"] .stat-icon {
            background: rgba(220, 38, 38, 0.1);
            color: var(--danger);
        }
        
        .stat-card[data-status="expiring_30"] .stat-icon {
            background: rgba(245, 158, 11, 0.1);
            color: var(--warning);
        }
        
        .stat-card[data-status="expiring_60"] .stat-icon {
            background: rgba(249, 115, 22, 0.1);
            color: var(--orange);
        }
        
        .stat-card[data-status="expiring_90"] .stat-icon {
            background: rgba(59, 130, 246, 0.1);
            color: var(--info);
        }
        
        .stat-card[data-status="valid"] .stat-icon {
            background: rgba(16, 185, 129, 0.1);
            color: var(--success);
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
        
        .summary-container {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 24px;
            margin-bottom: 24px;
        }
        
        @media (max-width: 1024px) {
            .summary-container {
                grid-template-columns: 1fr;
            }
        }
        
        .chart-card, .upcoming-card {
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
            border-radius: 16px;
            padding: 24px;
        }
        
        .chart-title, .upcoming-title {
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
        
        .chart-title i, .upcoming-title i {
            font-size: 20px;
        }
        
        .chart-container {
            height: 300px;
            position: relative;
        }
        
        .upcoming-list {
            max-height: 300px;
            overflow-y: auto;
        }
        
        .upcoming-item {
            padding: 12px 0;
            border-bottom: 1px solid var(--border-color);
        }
        
        .upcoming-item:last-child {
            border-bottom: none;
        }
        
        .upcoming-volunteer {
            font-weight: 600;
            margin-bottom: 4px;
        }
        
        .upcoming-training {
            font-size: 13px;
            color: var(--text-light);
            margin-bottom: 4px;
        }
        
        .upcoming-days {
            display: inline-block;
            padding: 4px 8px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: 600;
        }
        
        .days-expired {
            background: rgba(220, 38, 38, 0.1);
            color: var(--danger);
        }
        
        .days-30 {
            background: rgba(245, 158, 11, 0.1);
            color: var(--warning);
        }
        
        .days-60 {
            background: rgba(249, 115, 22, 0.1);
            color: var(--orange);
        }
        
        .days-90 {
            background: rgba(59, 130, 246, 0.1);
            color: var(--info);
        }
        
        .days-valid {
            background: rgba(16, 185, 129, 0.1);
            color: var(--success);
        }
        
        .certificates-table-container {
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
            border-radius: 16px;
            overflow: hidden;
        }
        
        .certificates-table {
            width: 100%;
            border-collapse: collapse;
        }
        
        .certificates-table thead {
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
        }
        
        .certificates-table th {
            padding: 16px;
            text-align: left;
            color: white;
            font-weight: 600;
            font-size: 14px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .certificates-table th i {
            margin-right: 8px;
        }
        
        .certificates-table tbody tr {
            border-bottom: 1px solid var(--border-color);
            transition: all 0.3s ease;
        }
        
        .certificates-table tbody tr:hover {
            background: rgba(220, 38, 38, 0.05);
        }
        
        .certificates-table tbody tr:last-child {
            border-bottom: none;
        }
        
        .certificates-table td {
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
        
        .certificate-info {
            display: flex;
            flex-direction: column;
        }
        
        .certificate-number {
            font-weight: 600;
            font-size: 13px;
            margin-bottom: 4px;
        }
        
        .certificate-dates {
            font-size: 11px;
            color: var(--text-light);
        }
        
        .expiry-status {
            display: inline-block;
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            text-transform: uppercase;
            text-align: center;
        }
        
        .status-expired {
            background: rgba(220, 38, 38, 0.1);
            color: var(--danger);
        }
        
        .status-expiring_30 {
            background: rgba(245, 158, 11, 0.1);
            color: var(--warning);
        }
        
        .status-expiring_60 {
            background: rgba(249, 115, 22, 0.1);
            color: var(--orange);
        }
        
        .status-expiring_90 {
            background: rgba(59, 130, 246, 0.1);
            color: var(--info);
        }
        
        .status-valid {
            background: rgba(16, 185, 129, 0.1);
            color: var(--success);
        }
        
        .days-count {
            font-weight: 600;
            font-size: 16px;
            text-align: center;
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
        
        .renew-button {
            background-color: rgba(139, 92, 246, 0.1);
            color: var(--purple);
        }
        
        .renew-button:hover {
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
        
        .certificates-per-page {
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .no-certificates {
            text-align: center;
            padding: 60px 20px;
            color: var(--text-light);
        }
        
        .no-certificates-icon {
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
        
        .modal-renew {
            background: var(--purple);
            color: white;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .modal-renew:hover {
            background: #7c3aed;
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
        
        /* User Profile Dropdown */
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

        /* Notification Bell */
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

        /* Notification Dropdown */
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
        
        /* Expiry timeline */
        .expiry-timeline {
            width: 100%;
            height: 10px;
            background: var(--gray-200);
            border-radius: 5px;
            margin-top: 8px;
            overflow: hidden;
            position: relative;
        }
        
        .expiry-timeline-fill {
            position: absolute;
            top: 0;
            left: 0;
            height: 100%;
            border-radius: 5px;
        }
        
        .expiry-timeline-fill.expired {
            background: var(--danger);
            width: 100%;
        }
        
        .expiry-timeline-fill.expiring_30 {
            background: var(--warning);
            width: 25%;
        }
        
        .expiry-timeline-fill.expiring_60 {
            background: var(--orange);
            width: 50%;
        }
        
        .expiry-timeline-fill.expiring_90 {
            background: var(--info);
            width: 75%;
        }
        
        .expiry-timeline-fill.valid {
            background: var(--success);
            width: 100%;
        }
        
        /* Responsive Table */
        @media (max-width: 1200px) {
            .certificates-table {
                display: block;
                overflow-x: auto;
            }
        }
        
        @media (max-width: 768px) {
            .dashboard-header {
                padding: 40px 25px 30px;
            }
            
            .dashboard-title {
                font-size: 32px;
            }
            
            .track-container {
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
        <div class="animation-text" id="animation-text">Loading Certificate Expiry Tracker...</div>
    </div>
    
    <!-- Certificate Details Modal -->
    <div class="modal-overlay" id="certificate-modal">
        <div class="modal">
            <div class="modal-header">
                <h2 class="modal-title">Certificate Details</h2>
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
                <button class="modal-button modal-renew" id="modal-renew-btn" style="display: none;">
                    <i class='bx bx-reset'></i>
                    Renew Certificate
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
                        <svg class="dropdown-arrow menu-icon rotated" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                        </svg>
                    </div>
                    <div id="schedule-management" class="submenu">
                       <a href="../sm/view_shifts.php" class="submenu-item">View Shifts</a>
                        <a href="../sm/create_schedule.php" class="submenu-item">Create Schedule</a>
                        <a href="../sm/confirm_availability.php" class="submenu-item">Confirm Availability</a>
                        <a href="../sm/request_change.php" class="submenu-item">Request Change</a>
                        <a href="../sm/monitor_attendance.php" class="submenu-item">Monitor Attendance</a>
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
                        <a href="view_training_records.php" class="submenu-item">View Records</a>
                        <a href="assign_training.php" class="submenu-item">Assign Training</a>
                        <a href="track_expiry.php" class="submenu-item active">Track Expiry</a>
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
                            <input type="text" placeholder="Search certificates..." class="search-input" id="search-input" value="<?php echo htmlspecialchars($search_term); ?>">
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
                        <h1 class="dashboard-title">Certificate Expiry Tracker</h1>
                        <p class="dashboard-subtitle">Track and manage certificate expiry dates</p>
                    </div>
                    <div class="dashboard-actions">
                        <button class="primary-button" id="export-button">
                            <i class='bx bx-export'></i>
                            Export Report
                        </button>
                        <button class="secondary-button" id="refresh-button">
                            <i class='bx bx-refresh'></i>
                            Refresh Data
                        </button>
                    </div>
                </div>
                
                <!-- Certificate Expiry Section -->
                <div class="track-container">
                    <!-- Stats Cards -->
                    <div class="stats-container">
                        <div class="stat-card <?php echo $expiry_filter === 'all' ? 'active' : ''; ?>" data-status="all">
                            <div class="stat-icon">
                                <i class='bx bxs-certification'></i>
                            </div>
                            <div class="stat-value"><?php echo $expiry_summary['total']; ?></div>
                            <div class="stat-label">Total Certificates</div>
                        </div>
                        <div class="stat-card <?php echo $expiry_filter === 'expired' ? 'active' : ''; ?>" data-status="expired">
                            <div class="stat-icon">
                                <i class='bx bxs-time'></i>
                            </div>
                            <div class="stat-value"><?php echo $expiry_summary['expired']; ?></div>
                            <div class="stat-label">Expired</div>
                        </div>
                        <div class="stat-card <?php echo $expiry_filter === 'expiring_30' ? 'active' : ''; ?>" data-status="expiring_30">
                            <div class="stat-icon">
                                <i class='bx bxs-alarm'></i>
                            </div>
                            <div class="stat-value"><?php echo $expiry_summary['expiring_30']; ?></div>
                            <div class="stat-label">Expiring in 30 Days</div>
                        </div>
                        <div class="stat-card <?php echo $expiry_filter === 'expiring_60' ? 'active' : ''; ?>" data-status="expiring_60">
                            <div class="stat-icon">
                                <i class='bx bxs-bell'></i>
                            </div>
                            <div class="stat-value"><?php echo $expiry_summary['expiring_60']; ?></div>
                            <div class="stat-label">Expiring in 60 Days</div>
                        </div>
                        <div class="stat-card <?php echo $expiry_filter === 'expiring_90' ? 'active' : ''; ?>" data-status="expiring_90">
                            <div class="stat-icon">
                                <i class='bx bxs-notification'></i>
                            </div>
                            <div class="stat-value"><?php echo $expiry_summary['expiring_90']; ?></div>
                            <div class="stat-label">Expiring in 90 Days</div>
                        </div>
                        <div class="stat-card <?php echo $expiry_filter === 'valid' ? 'active' : ''; ?>" data-status="valid">
                            <div class="stat-icon">
                                <i class='bx bxs-check-circle'></i>
                            </div>
                            <div class="stat-value"><?php echo $expiry_summary['valid']; ?></div>
                            <div class="stat-label">Valid (90+ Days)</div>
                        </div>
                    </div>
                    
                    <!-- Summary Section -->
                    <div class="summary-container">
                        <div class="chart-card">
                            <h3 class="chart-title">
                                <i class='bx bx-pie-chart-alt'></i> Expiry Distribution
                            </h3>
                            <div class="chart-container" id="expiry-chart">
                                <!-- Chart will be rendered by JavaScript -->
                                <div style="display: flex; align-items: center; justify-content: center; height: 100%; color: var(--text-light);">
                                    <i class='bx bx-pie-chart-alt' style="font-size: 48px; opacity: 0.3;"></i>
                                </div>
                            </div>
                        </div>
                        
                        <div class="upcoming-card">
                            <h3 class="upcoming-title">
                                <i class='bx bxs-alarm'></i> Upcoming Expiries (30 Days)
                            </h3>
                            <div class="upcoming-list">
                                <?php if (count($upcoming_expiries) > 0): ?>
                                    <?php foreach ($upcoming_expiries as $cert): 
                                        $volunteer_name = $cert['first_name'] . ' ' . ($cert['middle_name'] ? $cert['middle_name'] . ' ' : '') . $cert['last_name'];
                                        $days_left = $cert['days_left'];
                                        $status_class = '';
                                        
                                        if ($days_left <= 0) {
                                            $status_class = 'days-expired';
                                            $days_text = 'Expired';
                                        } elseif ($days_left <= 7) {
                                            $status_class = 'days-30';
                                            $days_text = $days_left . ' days';
                                        } elseif ($days_left <= 14) {
                                            $status_class = 'days-60';
                                            $days_text = $days_left . ' days';
                                        } else {
                                            $status_class = 'days-90';
                                            $days_text = $days_left . ' days';
                                        }
                                    ?>
                                        <div class="upcoming-item">
                                            <div class="upcoming-volunteer"><?php echo htmlspecialchars($volunteer_name); ?></div>
                                            <div class="upcoming-training"><?php echo htmlspecialchars($cert['title']); ?></div>
                                            <div>
                                                <span class="upcoming-days <?php echo $status_class; ?>">
                                                    <?php echo $days_text; ?>
                                                </span>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <div style="text-align: center; padding: 20px; color: var(--text-light);">
                                        <i class='bx bxs-check-circle' style="font-size: 32px; opacity: 0.5; margin-bottom: 12px; display: block;"></i>
                                        <p>No certificates expiring in the next 30 days</p>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Enhanced Filters -->
                    <div class="filters-container">
                        <div class="filter-group">
                            <label class="filter-label">Expiry Status</label>
                            <select class="filter-select" id="expiry-filter">
                                <option value="all" <?php echo $expiry_filter === 'all' ? 'selected' : ''; ?>>All Statuses</option>
                                <option value="expired" <?php echo $expiry_filter === 'expired' ? 'selected' : ''; ?>>Expired</option>
                                <option value="expiring_30" <?php echo $expiry_filter === 'expiring_30' ? 'selected' : ''; ?>>Expiring in 30 Days</option>
                                <option value="expiring_60" <?php echo $expiry_filter === 'expiring_60' ? 'selected' : ''; ?>>Expiring in 60 Days</option>
                                <option value="expiring_90" <?php echo $expiry_filter === 'expiring_90' ? 'selected' : ''; ?>>Expiring in 90 Days</option>
                                <option value="valid" <?php echo $expiry_filter === 'valid' ? 'selected' : ''; ?>>Valid (90+ Days)</option>
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
                            <input type="text" class="filter-input" id="search-filter" placeholder="Search by name, certificate number..." value="<?php echo htmlspecialchars($search_term); ?>">
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
                    
                    <!-- Certificates Table -->
                    <div class="certificates-table-container">
                        <?php if (count($certificates) > 0): ?>
                            <table class="certificates-table">
                                <thead>
                                    <tr>
                                        <th><i class='bx bx-user'></i> Volunteer</th>
                                        <th><i class='bx bx-book'></i> Training</th>
                                        <th><i class='bx bx-certification'></i> Certificate</th>
                                        <th><i class='bx bx-calendar'></i> Expiry Date</th>
                                        <th><i class='bx bx-time'></i> Days Remaining</th>
                                        <th><i class='bx bx-task'></i> Status</th>
                                        <th><i class='bx bx-cog'></i> Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($certificates as $cert): 
                                        $volunteer_name = $cert['volunteer_full_name'];
                                        $initial = strtoupper(substr($cert['first_name'], 0, 1));
                                        $expiry_date = date('M d, Y', strtotime($cert['expiry_date']));
                                        $days_until_expiry = $cert['days_until_expiry'];
                                        $expiry_status = $cert['expiry_status'];
                                        
                                        // Determine status badge and days text
                                        $status_badge = '';
                                        $days_text = '';
                                        $timeline_class = '';
                                        
                                        if ($expiry_status === 'expired') {
                                            $status_badge = '<span class="expiry-status status-expired">Expired</span>';
                                            $days_text = '<span style="color: var(--danger);">' . abs($days_until_expiry) . ' days ago</span>';
                                            $timeline_class = 'expired';
                                        } elseif ($expiry_status === 'expiring_30') {
                                            $status_badge = '<span class="expiry-status status-expiring_30">Expiring Soon</span>';
                                            $days_text = '<span style="color: var(--warning);">' . $days_until_expiry . ' days</span>';
                                            $timeline_class = 'expiring_30';
                                        } elseif ($expiry_status === 'expiring_60') {
                                            $status_badge = '<span class="expiry-status status-expiring_60">Expiring Soon</span>';
                                            $days_text = '<span style="color: var(--orange);">' . $days_until_expiry . ' days</span>';
                                            $timeline_class = 'expiring_60';
                                        } elseif ($expiry_status === 'expiring_90') {
                                            $status_badge = '<span class="expiry-status status-expiring_90">Expiring</span>';
                                            $days_text = '<span style="color: var(--info);">' . $days_until_expiry . ' days</span>';
                                            $timeline_class = 'expiring_90';
                                        } else {
                                            $status_badge = '<span class="expiry-status status-valid">Valid</span>';
                                            $days_text = '<span style="color: var(--success);">' . $days_until_expiry . ' days</span>';
                                            $timeline_class = 'valid';
                                        }
                                    ?>
                                        <tr>
                                            <td>
                                                <div class="volunteer-info">
                                                    <div class="volunteer-avatar">
                                                        <?php echo $initial; ?>
                                                    </div>
                                                    <div class="volunteer-details">
                                                        <div class="volunteer-name"><?php echo htmlspecialchars($volunteer_name); ?></div>
                                                        <div class="volunteer-email"><?php echo htmlspecialchars($cert['volunteer_email']); ?></div>
                                                        <div style="font-size: 11px; color: var(--text-light); margin-top: 2px;">
                                                            <?php echo htmlspecialchars($cert['volunteer_status']); ?>
                                                        </div>
                                                    </div>
                                                </div>
                                            </td>
                                            <td>
                                                <div class="training-info">
                                                    <div class="training-title"><?php echo htmlspecialchars($cert['training_title']); ?></div>
                                                    <div class="training-date">
                                                        <?php echo date('M d, Y', strtotime($cert['training_date'])); ?>
                                                    </div>
                                                </div>
                                            </td>
                                            <td>
                                                <div class="certificate-info">
                                                    <div class="certificate-number"><?php echo htmlspecialchars($cert['certificate_number']); ?></div>
                                                    <div class="certificate-dates">
                                                        Issued: <?php echo date('M d, Y', strtotime($cert['issue_date'])); ?>
                                                    </div>
                                                </div>
                                            </td>
                                            <td>
                                                <div><?php echo $expiry_date; ?></div>
                                                <div class="expiry-timeline">
                                                    <div class="expiry-timeline-fill <?php echo $timeline_class; ?>"></div>
                                                </div>
                                            </td>
                                            <td>
                                                <div class="days-count">
                                                    <?php echo $days_text; ?>
                                                </div>
                                            </td>
                                            <td>
                                                <?php echo $status_badge; ?>
                                            </td>
                                            <td>
                                                <div class="action-buttons">
                                                    <button class="action-button view-button" onclick="viewCertificate(<?php echo $cert['id']; ?>)">
                                                        <i class='bx bx-show'></i>
                                                        View
                                                    </button>
                                                    <?php if ($cert['certificate_file']): ?>
                                                        <button class="action-button download-button" onclick="downloadCertificate('<?php echo $cert['id']; ?>')">
                                                            <i class='bx bx-download'></i>
                                                            Download
                                                        </button>
                                                    <?php endif; ?>
                                                    <?php if ($expiry_status === 'expired' || $expiry_status === 'expiring_30'): ?>
                                                        <button class="action-button renew-button" onclick="renewCertificate(<?php echo $cert['id']; ?>)">
                                                            <i class='bx bx-reset'></i>
                                                            Renew
                                                        </button>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                            
                            <div class="table-footer">
                                <div class="certificates-per-page">
                                    <span>Showing <?php echo count($certificates); ?> of <?php echo $expiry_summary['total']; ?> certificates</span>
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
                            <div class="no-certificates">
                                <div class="no-certificates-icon">
                                    <i class='bx bx-certification'></i>
                                </div>
                                <h3>No Certificates Found</h3>
                                <p>No certificates match your current filters.</p>
                            </div>
                        <?php endif; ?>
                    </div>
                    
                    <!-- Recently Expired Section -->
                    <?php if (count($recently_expired) > 0): ?>
                    <div class="certificates-table-container" style="margin-top: 40px;">
                        <div style="padding: 20px 24px 0;">
                            <h3 class="section-title" style="color: var(--danger);">
                                <i class='bx bxs-time'></i> Recently Expired Certificates
                            </h3>
                        </div>
                        
                        <table class="certificates-table">
                            <thead>
                                <tr>
                                    <th><i class='bx bx-user'></i> Volunteer</th>
                                    <th><i class='bx bx-book'></i> Training</th>
                                    <th><i class='bx bx-certification'></i> Certificate</th>
                                    <th><i class='bx bx-calendar'></i> Expiry Date</th>
                                    <th><i class='bx bx-time'></i> Days Expired</th>
                                    <th><i class='bx bx-cog'></i> Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($recently_expired as $cert): 
                                    $volunteer_name = $cert['first_name'] . ' ' . ($cert['middle_name'] ? $cert['middle_name'] . ' ' : '') . $cert['last_name'];
                                    $initial = strtoupper(substr($cert['first_name'], 0, 1));
                                    $expiry_date = date('M d, Y', strtotime($cert['expiry_date']));
                                    $days_expired = $cert['days_expired'];
                                ?>
                                    <tr>
                                        <td>
                                            <div class="volunteer-info">
                                                <div class="volunteer-avatar">
                                                    <?php echo $initial; ?>
                                                </div>
                                                <div class="volunteer-details">
                                                    <div class="volunteer-name"><?php echo htmlspecialchars($volunteer_name); ?></div>
                                                </div>
                                            </div>
                                        </td>
                                        <td>
                                            <div class="training-info">
                                                <div class="training-title"><?php echo htmlspecialchars($cert['title']); ?></div>
                                            </div>
                                        </td>
                                        <td>
                                            <div class="certificate-info">
                                                <div class="certificate-number"><?php echo htmlspecialchars($cert['certificate_number']); ?></div>
                                            </div>
                                        </td>
                                        <td>
                                            <div><?php echo $expiry_date; ?></div>
                                        </td>
                                        <td>
                                            <div class="days-count" style="color: var(--danger);">
                                                <?php echo $days_expired; ?> days ago
                                            </div>
                                        </td>
                                        <td>
                                            <div class="action-buttons">
                                                <button class="action-button view-button" onclick="viewCertificate(<?php echo $cert['id']; ?>)">
                                                    <i class='bx bx-show'></i>
                                                    View
                                                </button>
                                                <button class="action-button renew-button" onclick="renewCertificate(<?php echo $cert['id']; ?>)">
                                                    <i class='bx bx-reset'></i>
                                                    Renew
                                                </button>
                                            </div>
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
    
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script>
        let currentCertificateId = null;
        let currentDownloadLink = null;
        let expiryChart = null;
        
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
            
            // Initialize expiry chart
            initExpiryChart();
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
                
                // Update chart colors for dark mode
                if (expiryChart) {
                    updateChartColors();
                }
            });
            
            // User profile dropdown
            const userProfile = document.getElementById('user-profile');
            const userDropdown = document.getElementById('user-dropdown');
            
            userProfile.addEventListener('click', function(e) {
                e.stopPropagation();
                userDropdown.classList.toggle('show');
                notificationDropdown.classList.remove('show');
            });
            
            // Notification bell dropdown
            const notificationBell = document.getElementById('notification-bell');
            const notificationDropdown = document.getElementById('notification-dropdown');
            
            notificationBell.addEventListener('click', function(e) {
                e.stopPropagation();
                notificationDropdown.classList.toggle('show');
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
                    document.getElementById('expiry-filter').value = status;
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
            document.getElementById('modal-renew-btn').addEventListener('click', function() {
                if (currentCertificateId) {
                    renewCertificate(currentCertificateId);
                }
            });
            
            // Export and refresh buttons
            document.getElementById('export-button').addEventListener('click', exportReport);
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
        
        function initExpiryChart() {
            const ctx = document.getElementById('expiry-chart').getContext('2d');
            const isDarkMode = document.body.classList.contains('dark-mode');
            
            const data = {
                labels: ['Expired', 'Expiring in 30 Days', 'Expiring in 60 Days', 'Expiring in 90 Days', 'Valid (90+ Days)'],
                datasets: [{
                    data: [
                        <?php echo $expiry_summary['expired']; ?>,
                        <?php echo $expiry_summary['expiring_30']; ?>,
                        <?php echo $expiry_summary['expiring_60']; ?>,
                        <?php echo $expiry_summary['expiring_90']; ?>,
                        <?php echo $expiry_summary['valid']; ?>
                    ],
                    backgroundColor: [
                        'rgba(220, 38, 38, 0.8)',
                        'rgba(245, 158, 11, 0.8)',
                        'rgba(249, 115, 22, 0.8)',
                        'rgba(59, 130, 246, 0.8)',
                        'rgba(16, 185, 129, 0.8)'
                    ],
                    borderColor: [
                        'rgb(220, 38, 38)',
                        'rgb(245, 158, 11)',
                        'rgb(249, 115, 22)',
                        'rgb(59, 130, 246)',
                        'rgb(16, 185, 129)'
                    ],
                    borderWidth: 1
                }]
            };
            
            const options = {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: {
                            color: isDarkMode ? '#f1f5f9' : '#1f2937',
                            padding: 20,
                            font: {
                                size: 12
                            }
                        }
                    },
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                const label = context.label || '';
                                const value = context.raw || 0;
                                const total = context.dataset.data.reduce((a, b) => a + b, 0);
                                const percentage = total > 0 ? Math.round((value / total) * 100) : 0;
                                return `${label}: ${value} (${percentage}%)`;
                            }
                        }
                    }
                }
            };
            
            expiryChart = new Chart(ctx, {
                type: 'pie',
                data: data,
                options: options
            });
        }
        
        function updateChartColors() {
            const isDarkMode = document.body.classList.contains('dark-mode');
            
            if (expiryChart) {
                expiryChart.options.plugins.legend.labels.color = isDarkMode ? '#f1f5f9' : '#1f2937';
                expiryChart.update();
            }
        }
        
        function applyFilters() {
            const expiry = document.getElementById('expiry-filter').value;
            const volunteer = document.getElementById('volunteer-filter').value;
            const training = document.getElementById('training-filter').value;
            const search = document.getElementById('search-filter').value;
            
            let url = 'track_expiry.php?';
            if (expiry !== 'all') {
                url += `expiry=${expiry}&`;
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
            document.getElementById('expiry-filter').value = 'all';
            document.getElementById('volunteer-filter').value = 'all';
            document.getElementById('training-filter').value = 'all';
            document.getElementById('search-filter').value = '';
            applyFilters();
        }
        
        function viewCertificate(id) {
            currentCertificateId = id;
            
            // Show loading state
            document.getElementById('modal-body').innerHTML = `
                <div style="text-align: center; padding: 40px;">
                    <i class='bx bx-loader-circle bx-spin' style="font-size: 48px; color: var(--primary-color);"></i>
                    <p style="margin-top: 16px; color: var(--text-light);">Loading certificate details...</p>
                </div>
            `;
            
            // Hide download and renew buttons initially
            document.getElementById('modal-download-btn').style.display = 'none';
            document.getElementById('modal-renew-btn').style.display = 'none';
            
            // Show modal
            document.getElementById('certificate-modal').classList.add('active');
            
            // Fetch certificate details via AJAX
            fetch(`track_expiry.php?ajax=true&get_certificate_details=true&id=${id}`)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        populateCertificateModal(data.data, data.download_link);
                    } else {
                        alert('Failed to load certificate details: ' + data.message);
                        closeModal();
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('Failed to load certificate details');
                    closeModal();
                });
        }
        
        function populateCertificateModal(data, downloadLink) {
            const modalBody = document.getElementById('modal-body');
            
            // Set download link if available
            currentDownloadLink = downloadLink;
            if (currentDownloadLink) {
                document.getElementById('modal-download-btn').style.display = 'flex';
            }
            
            // Show renew button for expired or expiring certificates
            if (data.expiry_status === 'expired' || data.expiry_status === 'expiring_30') {
                document.getElementById('modal-renew-btn').style.display = 'flex';
            }
            
            // Build the full name properly
            const fullName = `${data.first_name} ${data.middle_name ? data.middle_name + ' ' : ''}${data.last_name}`;
            
            // Calculate expiry status text
            let expiryText = '';
            let expiryClass = '';
            let expiryIcon = '';
            
            if (data.expiry_status === 'expired') {
                expiryText = `Expired ${Math.abs(data.days_until_expiry)} days ago`;
                expiryClass = 'status-expired';
                expiryIcon = 'bx bxs-time';
            } else if (data.expiry_status === 'expiring_30') {
                expiryText = `Expiring in ${data.days_until_expiry} days`;
                expiryClass = 'status-expiring_30';
                expiryIcon = 'bx bxs-alarm';
            } else if (data.expiry_status === 'expiring_60') {
                expiryText = `Expiring in ${data.days_until_expiry} days`;
                expiryClass = 'status-expiring_60';
                expiryIcon = 'bx bxs-bell';
            } else if (data.expiry_status === 'expiring_90') {
                expiryText = `Expiring in ${data.days_until_expiry} days`;
                expiryClass = 'status-expiring_90';
                expiryIcon = 'bx bxs-notification';
            } else {
                expiryText = `Valid for ${data.days_until_expiry} more days`;
                expiryClass = 'status-valid';
                expiryIcon = 'bx bxs-check-circle';
            }
            
            const issueDate = data.issue_date ? new Date(data.issue_date).toLocaleDateString('en-US', { year: 'numeric', month: 'long', day: 'numeric' }) : 'N/A';
            const expiryDate = data.expiry_date ? new Date(data.expiry_date).toLocaleDateString('en-US', { year: 'numeric', month: 'long', day: 'numeric' }) : 'N/A';
            const trainingDate = data.training_date ? new Date(data.training_date).toLocaleDateString('en-US', { year: 'numeric', month: 'long', day: 'numeric' }) : 'N/A';
            const issuedBy = data.issued_by_first ? `${data.issued_by_first} ${data.issued_by_last}` : 'System';
            const verifiedBy = data.verified_by_first ? `${data.verified_by_first} ${data.verified_by_last}` : 'Not Verified';
            
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
                            <div class="modal-detail-label">Volunteer Status</div>
                            <div class="modal-detail-value">${data.volunteer_status}</div>
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
                            <div class="modal-detail-label">Training Date</div>
                            <div class="modal-detail-value">${trainingDate}</div>
                        </div>
                        <div class="modal-detail">
                            <div class="modal-detail-label">Instructor</div>
                            <div class="modal-detail-value">${data.instructor}</div>
                        </div>
                        <div class="modal-detail">
                            <div class="modal-detail-label">Location</div>
                            <div class="modal-detail-value">${data.location}</div>
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
                            <div class="modal-detail-label">Expiry Status</div>
                            <div class="modal-detail-value">
                                <span class="expiry-status ${expiryClass}">
                                    <i class='${expiryIcon}'></i>
                                    ${expiryText}
                                </span>
                            </div>
                        </div>
                        <div class="modal-detail">
                            <div class="modal-detail-label">Issued By</div>
                            <div class="modal-detail-value">${issuedBy}</div>
                        </div>
                        <div class="modal-detail">
                            <div class="modal-detail-label">Verified By</div>
                            <div class="modal-detail-value">${verifiedBy}</div>
                        </div>
                        <div class="modal-detail">
                            <div class="modal-detail-label">Certificate Verified</div>
                            <div class="modal-detail-value">${data.verified ? 'Yes' : 'No'}</div>
                        </div>
                    </div>
                </div>
                
                <div class="modal-section">
                    <h3 class="modal-section-title">
                        <i class='bx bx-note'></i> Certificate Data
                    </h3>
                    <div class="modal-detail">
                        <div class="modal-detail-value" style="white-space: pre-wrap; background: var(--gray-100); padding: 15px; border-radius: 8px; font-family: monospace; font-size: 12px;">
                            ${data.certificate_data || 'No additional data available'}
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
                        <div style="text-align: center; padding: 20px; border: 2px dashed var(--border-color); border-radius: 12px;">
                            <p>Certificate file available for download</p>
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
            
            modalBody.innerHTML = html;
        }
        
        function closeModal() {
            document.getElementById('certificate-modal').classList.remove('active');
            currentCertificateId = null;
            currentDownloadLink = null;
        }
        
        function downloadCertificate(id) {
            // In a real implementation, this would trigger a download
            // For now, we'll just redirect to the certificate file
            fetch(`track_expiry.php?ajax=true&get_certificate_details=true&id=${id}`)
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
        
        function renewCertificate(id) {
            if (confirm('Are you sure you want to renew this certificate? This will extend the expiry date by 1 year.')) {
                showLoading('Renewing certificate...');
                
                // In a real implementation, this would make an AJAX call to renew the certificate
                // For now, we'll simulate the process
                setTimeout(() => {
                    alert('Certificate renewal feature will be implemented in the next update.');
                    hideLoading();
                }, 1000);
            }
        }
        
        function exportReport() {
            // Collect filter values
            const expiry = document.getElementById('expiry-filter').value;
            const volunteer = document.getElementById('volunteer-filter').value;
            const training = document.getElementById('training-filter').value;
            const search = document.getElementById('search-filter').value;
            
            // Create export URL
            let url = 'export_expiry_report.php?';
            if (expiry !== 'all') url += `expiry=${expiry}&`;
            if (volunteer !== 'all') url += `volunteer=${volunteer}&`;
            if (training !== 'all') url += `training=${training}&`;
            if (search) url += `search=${encodeURIComponent(search)}`;
            
            // Open export in new window
            window.open(url, '_blank');
            
            alert('Export started. The report will download shortly.');
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
        
        function showLoading(message) {
            const loadingOverlay = document.createElement('div');
            loadingOverlay.className = 'dashboard-animation';
            loadingOverlay.style.display = 'flex';
            loadingOverlay.style.opacity = '1';
            loadingOverlay.style.zIndex = '9998';
            loadingOverlay.innerHTML = `
                <div class="animation-logo" style="opacity: 1; transform: translateY(0);">
                    <div class="animation-logo-icon">
                        <img src="../../img/frsm-logo.png" alt="Fire & Rescue Logo">
                    </div>
                    <span class="animation-logo-text">Fire & Rescue</span>
                </div>
                <div class="animation-progress">
                    <div class="animation-progress-fill" style="width: 30%;"></div>
                </div>
                <div class="animation-text" style="opacity: 1;">${message}</div>
            `;
            
            document.body.appendChild(loadingOverlay);
            
            // Simulate progress
            let progress = 30;
            const progressInterval = setInterval(() => {
                progress += 10;
                loadingOverlay.querySelector('.animation-progress-fill').style.width = progress + '%';
                
                if (progress >= 90) {
                    clearInterval(progressInterval);
                }
            }, 200);
        }
        
        function hideLoading() {
            const loadingOverlay = document.querySelector('.dashboard-animation');
            if (loadingOverlay && loadingOverlay.style.zIndex === '9998') {
                loadingOverlay.style.opacity = '0';
                setTimeout(() => {
                    if (loadingOverlay.parentNode) {
                        loadingOverlay.parentNode.removeChild(loadingOverlay);
                    }
                }, 300);
            }
        }
        
        updateTime();
        setInterval(updateTime, 1000);
    </script>
</body>
</html>