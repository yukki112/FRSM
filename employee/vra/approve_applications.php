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

// Pagination setup
$records_per_page = 10;
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$offset = ($page - 1) * $records_per_page;

// Get total count of approved volunteers
$count_query = "SELECT COUNT(*) as total FROM volunteers WHERE status = 'approved'";
$count_stmt = $pdo->prepare($count_query);
$count_stmt->execute();
$total_records = $count_stmt->fetch()['total'];
$total_pages = ceil($total_records / $records_per_page);

// Get approved volunteers with pagination - FIXED QUERY
$volunteers_query = "SELECT 
                        v.*, 
                        u.unit_name, 
                        u.unit_code, 
                        u.id as unit_id,
                        -- Construct full name if empty
                        CASE 
                            WHEN v.full_name IS NULL OR v.full_name = '' 
                            THEN CONCAT(
                                v.first_name, 
                                CASE WHEN v.middle_name IS NOT NULL AND v.middle_name != '' THEN CONCAT(' ', v.middle_name) ELSE '' END,
                                ' ',
                                v.last_name
                            )
                            ELSE v.full_name 
                        END as display_full_name
                     FROM volunteers v 
                     LEFT JOIN volunteer_assignments va ON v.id = va.volunteer_id 
                     LEFT JOIN units u ON va.unit_id = u.id 
                     WHERE v.status = 'approved' 
                     ORDER BY display_full_name ASC
                     LIMIT :offset, :records_per_page";
$volunteers_stmt = $pdo->prepare($volunteers_query);
$volunteers_stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$volunteers_stmt->bindValue(':records_per_page', $records_per_page, PDO::PARAM_INT);
$volunteers_stmt->execute();
$volunteers = $volunteers_stmt->fetchAll();

// Get all units for assignment
$units_query = "SELECT * FROM units WHERE status = 'Active' ORDER BY unit_name ASC";
$units_stmt = $pdo->prepare($units_query);
$units_stmt->execute();
$units = $units_stmt->fetchAll();

// Get assignment statistics
$stats_query = "SELECT 
                COUNT(*) as total_approved,
                COUNT(va.id) as total_assigned,
                (SELECT COUNT(*) FROM units WHERE status = 'Active') as total_units
                FROM volunteers v
                LEFT JOIN volunteer_assignments va ON v.id = va.volunteer_id
                WHERE v.status = 'approved'";
$stats_stmt = $pdo->prepare($stats_query);
$stats_stmt->execute();
$stats = $stats_stmt->fetch();

$stmt = null;
$volunteers_stmt = null;
$units_stmt = null;
$stats_stmt = null;
$count_stmt = null;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Approve Applications & Assign Units - Fire & Rescue Services</title>
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
            
            --icon-bg-red: rgba(254, 226, 226, 0.7);
            --icon-bg-blue: rgba(219, 234, 254, 0.7);
            --icon-bg-green: rgba(220, 252, 231, 0.7);
            --icon-bg-purple: rgba(243, 232, 255, 0.7);
            --icon-bg-yellow: rgba(254, 243, 199, 0.7);
            --icon-bg-indigo: rgba(224, 231, 255, 0.7);
            --icon-bg-cyan: rgba(207, 250, 254, 0.7);
            --icon-bg-orange: rgba(255, 237, 213, 0.7);
            --icon-bg-pink: rgba(252, 231, 243, 0.7);
            --icon-bg-teal: rgba(204, 251, 241, 0.7);

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
        
        .dark-mode {
            --background-color: #0f172a;
            --text-color: #f1f5f9;
            --text-light: #f1f5f9;
            --border-color: #1e293b;
            --card-bg: #1e293b;
            --sidebar-bg: #0f172a;
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            font-size: 14px;
            line-height: 1.5;
            color: var(--text-color);
            background-color: var(--background-color);
        }

        h1, h2, h3, h4, h5, h6 {
            font-weight: 600;
        }

        .dashboard-title {
            font-size: 28px;
            font-weight: 800;
        }

        .dashboard-subtitle {
            font-size: 16px;
        }

        .dashboard-content {
            padding: 0;
            min-height: 100vh;
            position: relative;
            z-index: 1;
        }

        .header {
            position: relative;
            z-index: 1000;
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
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            color: var(--text-color);
        }

        .secondary-button:hover {
            background: var(--gray-100);
            transform: translateY(-2px);
        }

        .dark-mode .secondary-button:hover {
            background: var(--gray-800);
        }

        .approve-container {
            display: flex;
            flex-direction: column;
            gap: 24px;
            padding: 0 40px 40px;
        }
        
        .approve-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 24px;
        }
        
        .approve-title {
            font-size: 28px;
            font-weight: 800;
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            margin-bottom: 8px;
        }
        
        .approve-subtitle {
            color: var(--text-light);
            font-size: 16px;
        }
        
        .stats-container {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 20px;
            margin-bottom: 24px;
        }
        
        .stat-card {
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: 20px;
            padding: 25px;
            display: flex;
            align-items: center;
            gap: 20px;
            transition: all 0.3s ease;
            cursor: pointer;
            position: relative;
            overflow: hidden;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
        }
        
        .stat-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(90deg, var(--primary-color), var(--secondary-color));
        }
        
        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.1);
        }
        
        .stat-icon {
            font-size: 32px;
            padding: 15px;
            border-radius: 16px;
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            width: 60px;
            height: 60px;
            flex-shrink: 0;
        }
        
        .stat-content {
            flex: 1;
        }
        
        .stat-value {
            font-size: 32px;
            font-weight: 800;
            margin-bottom: 4px;
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }
        
        .stat-label {
            font-size: 14px;
            color: var(--text-light);
            font-weight: 500;
        }
        
        .volunteers-table-container {
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: 20px;
            overflow: hidden;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
        }
        
        .table-header {
            padding: 25px;
            border-bottom: 1px solid var(--border-color);
            display: flex;
            align-items: center;
            justify-content: space-between;
            background: rgba(220, 38, 38, 0.02);
        }
        
        .table-title {
            font-size: 20px;
            font-weight: 700;
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }
        
        .volunteers-table {
            width: 100%;
            border-collapse: collapse;
        }
        
        .volunteers-table th {
            background: rgba(220, 38, 38, 0.05);
            padding: 18px;
            text-align: left;
            font-weight: 600;
            color: var(--text-color);
            border-bottom: 1px solid var(--border-color);
            font-size: 14px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .volunteers-table td {
            padding: 18px;
            border-bottom: 1px solid var(--border-color);
            vertical-align: middle;
        }
        
        .volunteers-table tr:last-child td {
            border-bottom: none;
        }
        
        .volunteers-table tr:hover {
            background: rgba(220, 38, 38, 0.02);
            transform: scale(1.01);
            transition: all 0.2s ease;
        }
        
        .volunteer-avatar {
            width: 45px;
            height: 45px;
            border-radius: 12px;
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 700;
            font-size: 18px;
            margin-right: 15px;
            box-shadow: 0 4px 12px rgba(220, 38, 38, 0.3);
        }
        
        .volunteer-info {
            display: flex;
            align-items: center;
        }
        
        .volunteer-name {
            font-weight: 600;
            margin-bottom: 4px;
            font-size: 15px;
        }
        
        .volunteer-email {
            color: var(--text-light);
            font-size: 13px;
        }
        
        .unit-select {
            padding: 10px 12px;
            border-radius: 10px;
            border: 1px solid var(--border-color);
            background: var(--card-bg);
            color: var(--text-color);
            font-size: 14px;
            min-width: 200px;
            transition: all 0.3s ease;
        }
        
        .unit-select:focus {
            outline: none;
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(220, 38, 38, 0.1);
        }
        
        .action-button {
            padding: 8px 16px;
            border-radius: 10px;
            border: none;
            cursor: pointer;
            font-weight: 500;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 6px;
            font-size: 13px;
        }
        
        .view-button {
            background: rgba(59, 130, 246, 0.1);
            color: var(--info);
        }
        
        .view-button:hover {
            background: var(--info);
            color: white;
            transform: translateY(-1px);
        }
        
        .assign-button {
            background: var(--success);
            color: white;
        }
        
        .assign-button:hover {
            background: #0d8c5f;
            transform: translateY(-1px);
        }
        
        .assign-button:disabled {
            background: var(--gray-400);
            cursor: not-allowed;
            transform: none;
        }
        
        .reassign-button {
            background: rgba(245, 158, 11, 0.1);
            color: var(--warning);
        }
        
        .reassign-button:hover {
            background: var(--warning);
            color: white;
            transform: translateY(-1px);
        }
        
        .assigned-unit {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 8px 16px;
            background: linear-gradient(135deg, rgba(16, 185, 129, 0.1), rgba(16, 185, 129, 0.2));
            color: var(--success);
            border-radius: 12px;
            font-size: 13px;
            font-weight: 500;
            border: 1px solid rgba(16, 185, 129, 0.2);
        }
        
        .skills-tags {
            display: flex;
            flex-wrap: wrap;
            gap: 6px;
        }
        
        .skill-tag {
            padding: 4px 10px;
            border-radius: 8px;
            font-size: 11px;
            font-weight: 500;
            text-transform: uppercase;
            letter-spacing: 0.3px;
        }
        
        .skill-fire {
            background: rgba(220, 38, 38, 0.1);
            color: var(--danger);
            border: 1px solid rgba(220, 38, 38, 0.2);
        }
        
        .skill-medical {
            background: rgba(16, 185, 129, 0.1);
            color: var(--success);
            border: 1px solid rgba(16, 185, 129, 0.2);
        }
        
        .skill-rescue {
            background: rgba(59, 130, 246, 0.1);
            color: var(--info);
            border: 1px solid rgba(59, 130, 246, 0.2);
        }
        
        .skill-drive {
            background: rgba(245, 158, 11, 0.1);
            color: var(--warning);
            border: 1px solid rgba(245, 158, 11, 0.2);
        }
        
        .no-volunteers {
            text-align: center;
            padding: 80px 20px;
            color: var(--text-light);
        }
        
        .no-volunteers-icon {
            font-size: 80px;
            margin-bottom: 20px;
            color: var(--text-light);
            opacity: 0.3;
        }
        
        .pagination {
            display: flex;
            justify-content: center;
            align-items: center;
            padding: 25px;
            border-top: 1px solid var(--border-color);
            background: rgba(220, 38, 38, 0.02);
            gap: 12px;
        }
        
        .pagination-button {
            padding: 10px 16px;
            border-radius: 10px;
            border: 1px solid var(--border-color);
            background: var(--card-bg);
            color: var(--text-color);
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 6px;
            font-size: 13px;
            font-weight: 500;
        }
        
        .pagination-button:hover:not(:disabled) {
            background: var(--primary-color);
            color: white;
            border-color: var(--primary-color);
            transform: translateY(-1px);
        }
        
        .pagination-button:disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }
        
        .pagination-info {
            color: var(--text-light);
            font-size: 14px;
            margin: 0 16px;
        }
        
        .pagination-numbers {
            display: flex;
            gap: 6px;
            margin: 0 16px;
        }
        
        .page-number {
            padding: 8px 12px;
            border-radius: 8px;
            border: 1px solid var(--border-color);
            background: var(--card-bg);
            color: var(--text-color);
            cursor: pointer;
            transition: all 0.3s ease;
            font-size: 13px;
            font-weight: 500;
            min-width: 40px;
            text-align: center;
        }
        
        .page-number:hover {
            background: var(--primary-color);
            color: white;
            border-color: var(--primary-color);
        }
        
        .page-number.active {
            background: var(--primary-color);
            color: white;
            border-color: var(--primary-color);
        }
        
        .modal-overlay {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.6);
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
            transform: scale(0.9);
            transition: all 0.3s ease;
            max-height: 90vh;
            overflow-y: auto;
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.15);
        }
        
        .modal-overlay.active .modal {
            transform: scale(1);
        }
        
        .modal-header {
            padding: 25px;
            border-bottom: 1px solid var(--border-color);
            display: flex;
            align-items: center;
            justify-content: space-between;
            background: rgba(220, 38, 38, 0.02);
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
            padding: 5px;
            border-radius: 8px;
        }
        
        .modal-close:hover {
            color: var(--danger);
            background: rgba(220, 38, 38, 0.1);
        }
        
        .modal-body {
            padding: 25px;
        }
        
        .modal-footer {
            padding: 20px 25px;
            border-top: 1px solid var(--border-color);
            display: flex;
            justify-content: flex-end;
            gap: 12px;
            background: rgba(220, 38, 38, 0.02);
        }
        
        .modal-input {
            width: 100%;
            padding: 12px 16px;
            border-radius: 10px;
            border: 1px solid var(--border-color);
            background: var(--card-bg);
            color: var(--text-color);
            font-size: 14px;
            margin-bottom: 16px;
            transition: all 0.3s ease;
        }
        
        .modal-input:focus {
            outline: none;
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(220, 38, 38, 0.1);
        }
        
        .modal-label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: var(--text-color);
        }
        
        .profile-modal {
            max-width: 900px;
            max-height: 75vh;
        }
        
        .profile-header {
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            color: white;
            padding: 30px;
            text-align: center;
            position: relative;
        }
        
        .profile-avatar {
            width: 80px;
            height: 80px;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.2);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 32px;
            font-weight: bold;
            margin: 0 auto 15px;
            border: 4px solid rgba(255, 255, 255, 0.3);
        }
        
        .profile-name {
            font-size: 24px;
            font-weight: 700;
            margin-bottom: 8px;
        }
        
        .profile-status {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 6px 16px;
            background: rgba(255, 255, 255, 0.2);
            border-radius: 20px;
            font-size: 13px;
            font-weight: 500;
        }
        
        .profile-content {
            padding: 30px;
        }
        
        .section {
            margin-bottom: 30px;
        }
        
        .section-title {
            font-size: 18px;
            font-weight: 700;
            margin-bottom: 16px;
            padding-bottom: 10px;
            border-bottom: 2px solid var(--border-color);
            color: var(--primary-color);
        }
        
        .info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 16px;
        }
        
        .info-item {
            margin-bottom: 12px;
        }
        
        .info-label {
            font-size: 13px;
            color: black;
            margin-bottom: 4px;
            font-weight: 500;
        }
        
        .info-value {
            font-size: 15px;
            font-weight: 500;
            color: black;
        }
        
        .skills-container {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
        }
        
        .profile-skill-tag {
            padding: 6px 12px;
            border-radius: 10px;
            font-size: 12px;
            font-weight: 500;
        }
        
        .skill-active {
            background: rgba(16, 185, 129, 0.1);
            color: var(--success);
            border: 1px solid rgba(16, 185, 129, 0.2);
        }
        
        .skill-inactive {
            background: rgba(107, 114, 128, 0.1);
            color: var(--text-light);
            border: 1px solid rgba(107, 114, 128, 0.2);
        }
        
        .id-photos {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 16px;
            margin-top: 16px;
        }
        
        .id-photo {
            text-align: center;
        }
        
        .id-photo img {
            max-width: 100%;
            max-height: 200px;
            border-radius: 10px;
            border: 1px solid var(--border-color);
        }
        
        .notification-container {
            position: fixed;
            top: 20px;
            right: 20px;
            z-index: 1100;
            display: flex;
            flex-direction: column;
            gap: 10px;
        }
        
        .notification {
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
        
        .notification-info .notification-icon {
            color: var(--info);
        }
        
        .notification-warning .notification-icon {
            color: var(--warning);
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
        
        .action-buttons {
            display: flex;
            gap: 8px;
            align-items: center;
        }
        
        .user-profile {
            position: relative;
            cursor: pointer;
            z-index: 2000;
        }
        
        .user-profile-dropdown {
            position: absolute;
            top: 100%;
            right: 0;
            margin-top: 8px;
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: 12px;
            min-width: 180px;
            z-index: 2001;
            opacity: 0;
            visibility: hidden;
            transform: translateY(-10px);
            transition: all 0.3s ease;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
        }
        
        .user-profile-dropdown.active {
            opacity: 1;
            visibility: visible;
            transform: translateY(0);
        }
        
        .user-profile-dropdown-item {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 12px 16px;
            color: var(--text-color);
            text-decoration: none;
            transition: all 0.2s ease;
            border-bottom: 1px solid var(--border-color);
        }
        
        .user-profile-dropdown-item:last-child {
            border-bottom: none;
        }
        
        .user-profile-dropdown-item:hover {
            background: rgba(220, 38, 38, 0.05);
        }
        
        .user-profile-dropdown-item i {
            font-size: 18px;
            width: 20px;
            text-align: center;
        }
        
        .user-profile-dropdown-item.settings i {
            color: var(--icon-indigo);
        }
        
        .user-profile-dropdown-item.profile i {
            color: var(--icon-orange);
        }
        
        .user-profile-dropdown-item.logout i {
            color: var(--icon-red);
        }

        .action-button.ai-button {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border: none;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 8px 14px;
            border-radius: 6px;
            font-size: 13px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.3s ease;
            white-space: nowrap;
        }

        .action-button.ai-button:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(102, 126, 234, 0.4);
        }

        .action-button.ai-button i {
            font-size: 16px;
        }

        .ai-modal {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.6);
            display: none;
            align-items: center;
            justify-content: center;
            z-index: 1000;
        }

        .ai-modal.active {
            display: flex;
        }

        .ai-modal-content {
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: 20px;
            width: 90%;
            max-width: 600px;
            max-height: 80vh;
            overflow-y: auto;
            animation: slideIn 0.3s ease;
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.15);
        }

        @keyframes slideIn {
            from {
                transform: translateY(-50px);
                opacity: 0;
            }
            to {
                transform: translateY(0);
                opacity: 1;
            }
        }

        .ai-modal-header {
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            color: white;
            padding: 20px;
            border-radius: 20px 20px 0 0;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .ai-modal-header h2 {
            margin: 0;
            font-size: 20px;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .ai-modal-close {
            background: none;
            border: none;
            color: white;
            font-size: 24px;
            cursor: pointer;
            width: 32px;
            height: 32px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 4px;
            transition: background 0.2s;
        }

        .ai-modal-close:hover {
            background: rgba(255, 255, 255, 0.2);
        }

        .ai-modal-body {
            padding: 20px;
            color: var(--text-color);
        }

        .ai-loading {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 40px 20px;
        }

        .ai-spinner {
            width: 50px;
            height: 50px;
            border: 4px solid var(--gray-200);
            border-top-color: var(--primary-color);
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }

        @keyframes spin {
            to {
                transform: rotate(360deg);
            }
        }

        .ai-loading-text {
            margin-top: 15px;
            color: var(--text-light);
            font-weight: 500;
        }

        .ai-volunteer-info {
            background: var(--card-bg);
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
            border-left: 4px solid var(--primary-color);
            border: 1px solid var(--border-color);
        }

        .ai-volunteer-name {
            font-size: 16px;
            font-weight: 600;
            color: var(--text-color);
        }

        .ai-volunteer-skills {
            font-size: 13px;
            color: var(--text-light);
            margin-top: 8px;
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
        }

        .ai-skill-badge {
            background: var(--background-color);
            padding: 4px 10px;
            border-radius: 4px;
            border: 1px solid var(--border-color);
            font-size: 12px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.05);
        }

        .ai-recommendations {
            display: flex;
            flex-direction: column;
            gap: 12px;
        }

        .ai-recommendation-card {
            background: var(--card-bg);
            border: 2px solid var(--border-color);
            border-radius: 12px;
            padding: 15px;
            transition: all 0.3s ease;
            cursor: pointer;
        }

        .ai-recommendation-card:hover {
            border-color: var(--primary-color);
            box-shadow: 0 6px 15px rgba(220, 38, 38, 0.2);
        }

        .ai-recommendation-card.selected {
            border-color: var(--primary-color);
            background: rgba(220, 38, 38, 0.05);
        }

        .ai-recommendation-header {
            display: flex;
            justify-content: space-between;
            align-items: start;
            margin-bottom: 10px;
        }

        .ai-unit-name {
            font-size: 15px;
            font-weight: 600;
            color: var(--text-color);
        }

        .ai-match-score {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
        }

        .ai-recommendation-details {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 10px;
            font-size: 13px;
            margin-bottom: 10px;
        }

        .ai-detail-item {
            display: flex;
            align-items: center;
            gap: 6px;
            color: var(--text-light);
        }

        .ai-detail-item i {
            color: var(--primary-color);
            font-size: 14px;
        }

        .ai-matched-skills {
            display: flex;
            flex-wrap: wrap;
            gap: 6px;
            padding-top: 10px;
            border-top: 1px solid var(--border-color);
        }

        .ai-matched-skill-tag {
            background: var(--icon-bg-green);
            color: var(--success);
            padding: 3px 8px;
            border-radius: 4px;
            font-size: 12px;
            font-weight: 500;
        }

        .ai-no-match {
            text-align: center;
            padding: 30px 20px;
            color: var(--text-light);
        }

        .ai-error {
            background: var(--icon-bg-red);
            border: 1px solid var(--danger);
            color: var(--danger);
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 15px;
            display: none;
        }

        .ai-modal-footer {
            padding: 15px 20px;
            background: rgba(220, 38, 38, 0.02);
            border-top: 1px solid var(--border-color);
            display: flex;
            justify-content: flex-end;
            gap: 10px;
            border-radius: 0 0 20px 20px;
        }

        .ai-button-assign {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 6px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .ai-button-assign:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(102, 126, 234, 0.4);
        }

        .ai-button-assign:disabled {
            opacity: 0.5;
            cursor: not-allowed;
            transform: none;
            box-shadow: none;
        }

        .ai-button-cancel {
            background: var(--gray-200);
            color: var(--text-color);
            border: none;
            padding: 10px 20px;
            border-radius: 6px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.2s;
        }

        .ai-button-cancel:hover {
            background: var(--gray-300);
        }
        
        @media (max-width: 768px) {
            .volunteers-table {
                display: block;
                overflow-x: auto;
            }
            
            .dashboard-header {
                padding: 40px 25px 30px;
            }
            
            .dashboard-title {
                font-size: 32px;
            }
            
            .approve-container {
                padding: 0 25px 30px;
            }
            
            .stats-container {
                grid-template-columns: 1fr;
            }
            
            .table-header {
                flex-direction: column;
                gap: 16px;
                align-items: flex-start;
            }
            
            .action-buttons {
                flex-direction: column;
                align-items: stretch;
            }
            
            .action-button {
                justify-content: center;
            }
            
            .pagination {
                flex-wrap: wrap;
                gap: 8px;
            }
            
            .pagination-numbers {
                order: -1;
                width: 100%;
                justify-content: center;
                margin: 8px 0;
            }
            
            .profile-modal {
                max-width: 95%;
            }
            
            .info-grid {
                grid-template-columns: 1fr;
            }
            
            .id-photos {
                grid-template-columns: 1fr;
            }
        }
    </style>

</head>
<body>
   
    
    <!-- Notification Container -->
    <div class="notification-container" id="notification-container"></div>
    
    <!-- AI Recommendation Modal -->
    <div class="ai-modal" id="ai-modal">
        <div class="ai-modal-content">
            <div class="ai-modal-header">
                <h2>
                    <i class='bx bx-sparkles'></i>
                    AI Unit Recommendation
                </h2>
                <button class="ai-modal-close" id="ai-modal-close">&times;</button>
            </div>
            <div class="ai-modal-body">
                <div id="ai-loading" class="ai-loading">
                    <div class="ai-spinner"></div>
                    <div class="ai-loading-text">Analyzing skills and finding best matches...</div>
                </div>
                
                <div id="ai-content" style="display: none;">
                    <div id="ai-volunteer-info" class="ai-volunteer-info"></div>
                    <div id="ai-error" class="ai-error"></div>
                    <div id="ai-recommendations" class="ai-recommendations"></div>
                </div>
            </div>
            <div class="ai-modal-footer">
                <button class="ai-button-cancel" id="ai-button-close">Close</button>
                <button class="ai-button-assign" id="ai-button-assign" disabled>Assign Selected Unit</button>
            </div>
        </div>
    </div>

    <!-- Profile View Modal -->
    <div class="modal-overlay" id="profile-modal">
        <div class="modal profile-modal">
            <div class="modal-header profile-header">
                <h2 class="modal-title">Volunteer Profile</h2>
                <button class="modal-close" id="profile-modal-close">&times;</button>
            </div>
            <div class="modal-body profile-content" id="profile-modal-body">
                <!-- Profile content will be loaded here -->
            </div>
            <div class="modal-footer">
                <button class="action-button view-button" id="profile-close">Close Profile</button>
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
                         <a href="../fir/receive_data.php" class="submenu-item">Receive Data</a>
                    
                        <a href="../fir/update_status.php" class="submenu-item">Update Status</a>
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
                    <div id="volunteer" class="submenu active">
                        <a href="review_data.php" class="submenu-item">Review/Aprroved Data Management</a>
                        <a href="approve_applications.php" class="submenu-item active">Assign Volunteers</a>
                        <a href="view_availability.php" class="submenu-item">View Availability</a>
                        <a href="remove_volunteers.php" class="submenu-item">Remove Volunteers</a>
                        <a href="toggle_volunteer_registration.php" class="submenu-item">Open/Close Registration</a>
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
                    <div id="schedule" class="submenu">
                        <a href="../sds/view_shifts.php" class="submenu-item">View Shifts</a>
                        <a href="../sds/confirm_availability.php" class="submenu-item">Confirm Availability</a>
                        <a href="../sds/request_change.php" class="submenu-item">Request Change</a>
                        
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

                      <!-- Feedback & Suggestions -->
                <div class="menu-item" onclick="toggleSubmenu('feedback')">
                    <div class="icon-box icon-bg-indigo">
                        <i class='bx bxs-message-square-detail icon-indigo'></i>
                    </div>
                    <span class="font-medium">Feedback & Suggestions</span>
                    <svg class="dropdown-arrow menu-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                    </svg>
                </div>
                <div id="feedback" class="submenu">
                    <a href="../fb/approve_feedback.php" class="submenu-item">Approve Feedback</a>
                    <a href="#" class="submenu-item">All Feedback</a>
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
                            <input type="text" placeholder="Search approved volunteers..." class="search-input" id="search-input">
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
                        <div class="user-profile" id="user-profile">
                            <img src="../../img/rei.jfif" alt="User" class="user-avatar">
                            <div class="user-info">
                                <p class="user-name"><?php echo $full_name; ?></p>
                                <p class="user-email"><?php echo $role; ?></p>
                            </div>
                            <div class="user-profile-dropdown" id="user-profile-dropdown">
                                <a href="../settings.php" class="user-profile-dropdown-item settings">
                                    <i class='bx bxs-cog'></i>
                                    <span>Settings</span>
                                </a>
                                <a href="../profile/profile.php" class="user-profile-dropdown-item profile">
                                    <i class='bx bxs-user'></i>
                                    <span>Profile</span>
                                </a>
                                <a href="../../includes/logout.php" class="user-profile-dropdown-item logout">
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
                        <h1 class="dashboard-title">Assign Units</h1>
                        <p class="dashboard-subtitle">Assign approved volunteers to response units with enhanced security</p>
                    </div>
                    <div class="dashboard-actions">
                        <button class="primary-button" id="refresh-button">
                            <i class='bx bx-refresh'></i>
                            Refresh Data
                        </button>
                        <button class="secondary-button" id="export-button">
                            <i class='bx bx-export'></i>
                            Export Assignments
                        </button>
                    </div>
                </div>
                
                <!-- Approve Applications Section -->
                <div class="approve-container">
                    <!-- Enhanced Stats Cards -->
                    <div class="stats-container">
                        <div class="stat-card">
                            <div class="stat-icon">
                                <i class='bx bx-user-check'></i>
                            </div>
                            <div class="stat-content">
                                <div class="stat-value"><?php echo $stats['total_approved']; ?></div>
                                <div class="stat-label">Approved Volunteers</div>
                            </div>
                        </div>
                        <div class="stat-card">
                            <div class="stat-icon">
                                <i class='bx bxs-building'></i>
                            </div>
                            <div class="stat-content">
                                <div class="stat-value"><?php echo $stats['total_units']; ?></div>
                                <div class="stat-label">Available Units</div>
                            </div>
                        </div>
                        <div class="stat-card">
                            <div class="stat-icon">
                                <i class='bx bx-group'></i>
                            </div>
                            <div class="stat-content">
                                <div class="stat-value"><?php echo $stats['total_assigned']; ?></div>
                                <div class="stat-label">Assigned to Units</div>
                            </div>
                        </div>
                        <div class="stat-card">
                            <div class="stat-icon">
                                <i class='bx bx-shield-quarter'></i>
                            </div>
                            <div class="stat-content">
                                <div class="stat-value">100%</div>
                                <div class="stat-label">Security Verified</div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Volunteers Table -->
                    <div class="volunteers-table-container">
                        <div class="table-header">
                            <h3 class="table-title">Approved Volunteers Management</h3>
                            <div class="table-actions">
                                <span class="table-info">Showing <?php echo count($volunteers); ?> of <?php echo $total_records; ?> approved volunteers</span>
                            </div>
                        </div>
                        
                        <?php if (count($volunteers) > 0): ?>
                            <table class="volunteers-table">
                                <thead>
                                    <tr>
                                        <th>Volunteer Profile</th>
                                        <th>Contact Information</th>
                                        <th>Skills & Expertise</th>
                                        <th>Unit Assignment</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($volunteers as $volunteer): ?>
                                        <tr data-id="<?php echo $volunteer['id']; ?>">
                                            <td>
                                                <div class="volunteer-info">
                                                    <div class="volunteer-avatar">
                                                        <?php echo strtoupper(substr($volunteer['display_full_name'], 0, 1)); ?>
                                                    </div>
                                                    <div>
                                                        <div class="volunteer-name"><?php echo htmlspecialchars($volunteer['display_full_name']); ?></div>
                                                        <div class="volunteer-email"><?php echo htmlspecialchars($volunteer['email']); ?></div>
                                                    </div>
                                                </div>
                                            </td>
                                            <td>
                                                <div style="font-weight: 500;"><?php echo htmlspecialchars($volunteer['contact_number']); ?></div>
                                                <div style="font-size: 12px; color: var(--text-light); margin-top: 4px;">
                                                    Applied: <?php echo htmlspecialchars($volunteer['application_date']); ?>
                                                </div>
                                            </td>
                                            <td>
                                                <div class="skills-tags">
                                                    <?php if ($volunteer['skills_basic_firefighting']): ?>
                                                        <span class="skill-tag skill-fire">Firefighting</span>
                                                    <?php endif; ?>
                                                    <?php if ($volunteer['skills_first_aid_cpr']): ?>
                                                        <span class="skill-tag skill-medical">First Aid</span>
                                                    <?php endif; ?>
                                                    <?php if ($volunteer['skills_search_rescue']): ?>
                                                        <span class="skill-tag skill-rescue">Rescue</span>
                                                    <?php endif; ?>
                                                    <?php if ($volunteer['skills_driving']): ?>
                                                        <span class="skill-tag skill-drive">Driving</span>
                                                    <?php endif; ?>
                                                    <?php if ($volunteer['skills_communication']): ?>
                                                        <span class="skill-tag skill-rescue">Comms</span>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                            <td>
                                                <?php if (!empty($volunteer['unit_name'])): ?>
                                                    <span class="assigned-unit">
                                                        <i class='bx bxs-check-circle'></i>
                                                        <?php echo htmlspecialchars($volunteer['unit_name']); ?> (<?php echo htmlspecialchars($volunteer['unit_code']); ?>)
                                                    </span>
                                                <?php else: ?>
                                                    <span style="color: var(--text-light); font-style: italic; font-size: 13px;">Awaiting assignment</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <div class="action-buttons">
                                                    <button class="action-button view-button" onclick="viewVolunteerProfile(<?php echo $volunteer['id']; ?>)">
                                                        <i class='bx bx-show'></i>
                                                        View
                                                    </button>
                                                    <!-- <CHANGE> Added AI Recommend button -->
                                                    <button class="action-button ai-button" onclick="getAIRecommendation(<?php echo $volunteer['id']; ?>, '<?php echo htmlspecialchars($volunteer['display_full_name']); ?>')">
                                                        <i class='bx bx-sparkles'></i>
                                                        AI Suggest
                                                    </button>
                                                    <?php if (empty($volunteer['unit_name'])): ?>
                                                        <select class="unit-select" id="unit-select-<?php echo $volunteer['id']; ?>">
                                                            <option value="">Select Unit</option>
                                                            <?php foreach ($units as $unit): ?>
                                                                <option value="<?php echo $unit['id']; ?>">
                                                                    <?php echo htmlspecialchars($unit['unit_name']); ?> (<?php echo htmlspecialchars($unit['unit_code']); ?>)
                                                                </option>
                                                            <?php endforeach; ?>
                                                        </select>
                                                        <button class="action-button assign-button" onclick="assignToUnit(<?php echo $volunteer['id']; ?>)">
                                                            <i class='bx bx-user-plus'></i>
                                                            Assign
                                                        </button>
                                                    <?php else: ?>
                                                        <button class="action-button reassign-button" onclick="reassignUnit(<?php echo $volunteer['id']; ?>)">
                                                            <i class='bx bx-transfer'></i>
                                                            Reassign
                                                        </button>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                            
                            <!-- Pagination -->
                            <div class="pagination">
                                <?php if ($page > 1): ?>
                                    <a href="?page=<?php echo $page - 1; ?>" class="pagination-button">
                                        <i class='bx bx-chevron-left'></i>
                                        Previous
                                    </a>
                                <?php else: ?>
                                    <button class="pagination-button" disabled>
                                        <i class='bx bx-chevron-left'></i>
                                        Previous
                                    </button>
                                <?php endif; ?>
                                
                                <div class="pagination-info">
                                    Page <?php echo $page; ?> of <?php echo $total_pages; ?>
                                </div>
                                
                                <div class="pagination-numbers">
                                    <?php
                                    // Show page numbers
                                    $start_page = max(1, $page - 2);
                                    $end_page = min($total_pages, $page + 2);
                                    
                                    for ($i = $start_page; $i <= $end_page; $i++):
                                    ?>
                                        <a href="?page=<?php echo $i; ?>" class="page-number <?php echo $i == $page ? 'active' : ''; ?>">
                                            <?php echo $i; ?>
                                        </a>
                                    <?php endfor; ?>
                                </div>
                                
                                <?php if ($page < $total_pages): ?>
                                    <a href="?page=<?php echo $page + 1; ?>" class="pagination-button">
                                        Next
                                        <i class='bx bx-chevron-right'></i>
                                    </a>
                                <?php else: ?>
                                    <button class="pagination-button" disabled>
                                        Next
                                        <i class='bx bx-chevron-right'></i>
                                    </button>
                                <?php endif; ?>
                            </div>
                        <?php else: ?>
                            <div class="no-volunteers">
                                <div class="no-volunteers-icon">
                                    <i class='bx bx-user-check'></i>
                                </div>
                                <h3>No Approved Volunteers</h3>
                                <p>There are no approved volunteers to assign to units yet.</p>
                                <a href="review_data.php" class="primary-button" style="margin-top: 20px; display: inline-flex;">
                                    <i class='bx bx-list-check'></i>
                                    Review Applications
                                </a>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
  <script>
    let selectedUnitId = null;
    let selectedVolunteerId = null;
    
    // Get AI Recommendation for a volunteer
    function getAIRecommendation(volunteerId, volunteerName) {
        console.log('🚀 Getting AI Recommendation for:', volunteerId, volunteerName);
        
        selectedVolunteerId = volunteerId;
        selectedUnitId = null;
        
        const modal = document.getElementById('ai-modal');
        const loading = document.getElementById('ai-loading');
        const content = document.getElementById('ai-content');
        
        modal.classList.add('active');
        loading.style.display = 'flex';
        content.style.display = 'none';
        
        // Clear previous content
        document.getElementById('ai-volunteer-info').innerHTML = '';
        document.getElementById('ai-recommendations').innerHTML = '';
        document.getElementById('ai-error').style.display = 'none';
        
        // Show AI loading message
        loading.innerHTML = `
            <div class="ai-spinner"></div>
            <div class="ai-loading-text">
                <div>🤖 Connecting to Google Dialogflow AI...</div>
                <div style="font-size: 12px; margin-top: 8px; opacity: 0.8;">Analyzing volunteer skills and unit requirements</div>
            </div>
        `;
        
        // Use URLSearchParams for proper form data
        const formData = new URLSearchParams();
        formData.append('volunteer_id', volunteerId);
        
        fetch('ai/get_ai_recommendation.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: formData
        })
        .then(response => {
            console.log('📥 Response status:', response.status);
            
            if (!response.ok) {
                throw new Error(`HTTP ${response.status}`);
            }
            return response.json();
        })
        .then(data => {
            console.log('✅ AI Response:', data);
            
            const loading = document.getElementById('ai-loading');
            const content = document.getElementById('ai-content');
            
            loading.style.display = 'none';
            content.style.display = 'block';
            
            if (data.success && data.recommendations) {
                displayAIRecommendations(data.recommendations, data.volunteer);
                showNotification('success', 'AI Analysis Complete', 'Dialogflow AI provided recommendations');
            } else {
                showAIError(data.message || 'No recommendations from AI');
            }
        })
        .catch(error => {
            console.error('❌ AI Request failed:', error);
            showAIError('Failed to connect to AI service: ' + error.message);
        });
    }

    // Display AI Recommendations
    function displayAIRecommendations(recommendations, volunteerInfo) {
        const volunteerInfo_div = document.getElementById('ai-volunteer-info');
        const recommendationsDiv = document.getElementById('ai-recommendations');
        
        console.log('🎯 Displaying AI recommendations:', recommendations);
        
        // Show volunteer info
        const skillsHtml = recommendations.length > 0 && recommendations[0].matched_skills.length > 0
            ? '<div class="ai-volunteer-skills">' +
              recommendations[0].matched_skills
                .map(skill => `<span class="ai-skill-badge">🔧 ${skill}</span>`).join('') +
              '</div>'
            : '<div class="ai-volunteer-skills"><span class="ai-skill-badge">⏳ Analyzing skills...</span></div>';
        
        volunteerInfo_div.innerHTML = `
            <div class="ai-volunteer-name">👤 ${volunteerInfo.name}</div>
            ${skillsHtml}
            <div style="font-size: 12px; color: var(--text-light); margin-top: 8px; display: flex; align-items: center; gap: 5px;">
                <i class='bx bx-chip' style="color: #4285f4;"></i>
                <span>${recommendations[0]?.ai_model || 'Google Dialogflow AI'}</span>
            </div>
        `;
        
        // Show recommendations
        if (recommendations.length > 0) {
            recommendationsDiv.innerHTML = recommendations.map((rec, index) => {
                const badgeColor = rec.score >= 80 ? '#0f9d58' : rec.score >= 60 ? '#f4b400' : '#db4437';
                const rankIcon = index === 0 ? '🥇' : index === 1 ? '🥈' : '🥉';
                
                return `
                    <div class="ai-recommendation-card" onclick="selectUnit(${rec.unit_id}, this)">
                        <div class="ai-recommendation-header">
                            <div>
                                <div class="ai-unit-name">${rankIcon} ${rec.unit_name}</div>
                                <div style="font-size: 12px; color: var(--text-light);">
                                    📋 ${rec.unit_code} • 🏷️ ${rec.unit_type}
                                </div>
                            </div>
                            <span class="ai-match-score" style="background: linear-gradient(135deg, ${badgeColor}, ${badgeColor}99);">
                                ${rec.score}% Match
                            </span>
                        </div>
                        <div class="ai-recommendation-details">
                            <div class="ai-detail-item">
                                <i class='bx bx-map' style="color: #4285f4;"></i>
                                <span>📍 ${rec.location}</span>
                            </div>
                            <div class="ai-detail-item">
                                <i class='bx bx-group' style="color: #0f9d58;"></i>
                                <span>👥 ${rec.current_count}/${rec.capacity} Members</span>
                            </div>
                            <div class="ai-detail-item">
                                <i class='bx bx-chip' style="color: #db4437;"></i>
                                <span>🤖 ${rec.ai_confidence}% AI Confidence</span>
                            </div>
                        </div>
                        <div class="ai-recommendation-details">
                            <div class="ai-detail-item">
                                <i class='bx bx-brain' style="color: #9c27b0;"></i>
                                <span style="font-size: 12px; line-height: 1.4;">💡 ${rec.ai_reasoning}</span>
                            </div>
                        </div>
                        <div class="ai-matched-skills">
                            ${rec.matched_skills.map(skill => 
                                `<span class="ai-matched-skill-tag">✅ ${skill}</span>`
                            ).join('')}
                        </div>
                    </div>
                `;
            }).join('');
        } else {
            recommendationsDiv.innerHTML = `
                <div class="ai-no-match">
                    <i class='bx bx-search-alt' style="font-size: 48px; color: var(--text-light); margin-bottom: 16px;"></i>
                    <h3>No AI Matches Found</h3>
                    <p>Dialogflow AI couldn't find suitable units based on current skills and availability.</p>
                </div>
            `;
        }
        
        document.getElementById('ai-error').style.display = 'none';
        document.getElementById('ai-button-assign').disabled = true;
    }

    // Select Unit from AI Recommendation
    function selectUnit(unitId, element) {
        selectedUnitId = unitId;
        
        // Remove previous selection
        document.querySelectorAll('.ai-recommendation-card').forEach(card => {
            card.classList.remove('selected');
            card.style.transform = 'scale(1)';
        });
        
        // Add selection to clicked card
        element.classList.add('selected');
        element.style.transform = 'scale(1.02)';
        
        // Enable assign button
        document.getElementById('ai-button-assign').disabled = false;
        
        // Show selection feedback
        const selectedUnit = element.querySelector('.ai-unit-name').textContent;
        console.log('✅ Selected unit:', selectedUnit, 'ID:', unitId);
    }

    // Show AI Error
    function showAIError(message) {
        const loading = document.getElementById('ai-loading');
        const content = document.getElementById('ai-content');
        
        loading.style.display = 'none';
        content.style.display = 'block';
        
        const errorDiv = document.getElementById('ai-error');
        errorDiv.innerHTML = `
            <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 10px;">
                <i class='bx bx-error-circle' style="font-size: 24px; color: #db4437;"></i>
                <strong>Dialogflow AI Service Error</strong>
            </div>
            <div style="font-size: 14px; line-height: 1.5;">${message}</div>
        `;
        errorDiv.style.display = 'block';
        
        document.getElementById('ai-recommendations').innerHTML = `
            <div class="ai-no-match">
                <i class='bx bx-wifi-off' style="font-size: 48px; color: #db4437; margin-bottom: 16px;"></i>
                <h3>AI Service Unavailable</h3>
                <p>${message}</p>
                <div style="margin-top: 20px; display: flex; gap: 10px; justify-content: center;">
                    <button class="action-button ai-button" onclick="retryAIRecommendation()">
                        <i class='bx bx-refresh'></i>
                        Retry AI Analysis
                    </button>
                    <button class="action-button view-button" onclick="closeAIModal()">
                        <i class='bx bx-x'></i>
                        Close
                    </button>
                </div>
            </div>
        `;
        
        showNotification('error', 'AI Service Error', 'Dialogflow AI is currently unavailable');
    }

    // Retry AI Recommendation
    function retryAIRecommendation() {
        if (selectedVolunteerId) {
            const volunteerName = document.querySelector(`tr[data-id="${selectedVolunteerId}"] .volunteer-name`).textContent;
            getAIRecommendation(selectedVolunteerId, volunteerName);
        }
    }

    // Close AI Modal
    function closeAIModal() {
        document.getElementById('ai-modal').classList.remove('active');
        selectedUnitId = null;
        selectedVolunteerId = null;
        
        // Reset button state
        document.getElementById('ai-button-assign').disabled = true;
    }

    // Assign to Unit
    function assignToUnit(volunteerId) {
        const unitSelect = document.getElementById(`unit-select-${volunteerId}`);
        const unitId = unitSelect.value;
        
        if (!unitId) {
            showNotification('error', 'Selection Required', 'Please select a unit first');
            return;
        }
        
        const assignButton = document.querySelector(`tr[data-id="${volunteerId}"] .assign-button`);
        if (assignButton) {
            assignButton.disabled = true;
            assignButton.innerHTML = '<i class="bx bx-loader-circle bx-spin"></i> Assigning...';
        }
        
        fetch('assign_volunteer_unit.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                },
            body: JSON.stringify({
                volunteer_id: volunteerId,
                unit_id: unitId,
                assigned_by: <?php echo $user_id; ?>
            })
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                showNotification('success', 'Assignment Successful', 'Volunteer has been assigned to the unit');
                setTimeout(() => {
                    location.reload();
                }, 1500);
            } else {
                showNotification('error', 'Assignment Failed', data.message || 'Failed to assign volunteer to unit');
                if (assignButton) {
                    assignButton.disabled = false;
                    assignButton.innerHTML = '<i class="bx bx-user-plus"></i> Assign';
                }
            }
        })
        .catch(error => {
            console.error('Error:', error);
            showNotification('error', 'Error', 'Failed to assign volunteer to unit');
            if (assignButton) {
                assignButton.disabled = false;
                assignButton.innerHTML = '<i class="bx bx-user-plus"></i> Assign';
            }
        });
    }
    
    // Reassign Unit
    function reassignUnit(volunteerId) {
        const reassignButton = document.querySelector(`tr[data-id="${volunteerId}"] .reassign-button`);
        if (reassignButton) {
            reassignButton.disabled = true;
            reassignButton.innerHTML = '<i class="bx bx-loader-circle bx-spin"></i> Processing...';
        }
        
        fetch('remove_volunteer_assignment.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                },
            body: JSON.stringify({
                volunteer_id: volunteerId
            })
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                showNotification('info', 'Assignment Removed', 'Volunteer is now unassigned and can be reassigned');
                setTimeout(() => {
                    location.reload();
                }, 1500);
            } else {
                showNotification('error', 'Error', data.message || 'Failed to remove assignment');
                if (reassignButton) {
                    reassignButton.disabled = false;
                    reassignButton.innerHTML = '<i class="bx bx-transfer"></i> Reassign';
                }
            }
        })
        .catch(error => {
            console.error('Error:', error);
            showNotification('error', 'Error', 'Failed to remove assignment');
            if (reassignButton) {
                reassignButton.disabled = false;
                reassignButton.innerHTML = '<i class="bx bx-transfer"></i> Reassign';
            }
        });
    }
    
    // View Volunteer Profile
    function viewVolunteerProfile(volunteerId) {
        // Show loading state
        document.getElementById('profile-modal-body').innerHTML = `
            <div style="text-align: center; padding: 40px;">
                <i class='bx bx-loader-circle bx-spin' style="font-size: 48px; color: var(--primary-color);"></i>
                <p style="margin-top: 16px; color: var(--text-light);">Loading volunteer profile...</p>
            </div>
        `;
        
        // Show profile modal
        document.getElementById('profile-modal').classList.add('active');
        
        // Fetch volunteer details - also need to fix this to get proper full name
        fetch(`get_volunteer_details.php?id=${volunteerId}`)
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    populateProfileModal(data.volunteer);
                } else {
                    showNotification('error', 'Error', 'Failed to load volunteer profile');
                    closeProfileModal();
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showNotification('error', 'Error', 'Failed to load volunteer profile');
                closeProfileModal();
            });
    }
    
    // Populate Profile Modal
    function populateProfileModal(volunteer) {
        const modalBody = document.getElementById('profile-modal-body');
        
        // Format ID photo paths
        const getImagePath = (filename) => {
            if (!filename) return null;
            return `../../uploads/volunteer_id_photos/${filename.split('/').pop()}`;
        };
        
        const frontPhoto = getImagePath(volunteer.id_front_photo);
        const backPhoto = getImagePath(volunteer.id_back_photo);
        
        // Construct full name if empty
        const fullName = volunteer.full_name && volunteer.full_name.trim() !== '' 
            ? volunteer.full_name 
            : `${volunteer.first_name} ${volunteer.middle_name ? volunteer.middle_name + ' ' : ''}${volunteer.last_name}`;
        
        let html = `
            <div class="profile-header">
                <div class="profile-avatar">
                    ${fullName.charAt(0).toUpperCase()}
                </div>
                <h1 class="profile-name">${fullName}</h1>
                <div class="profile-status">
                    <i class='bx bx-badge-check'></i>
                    ${volunteer.status.charAt(0).toUpperCase() + volunteer.status.slice(1)} Volunteer
                    ${volunteer.unit_name ? `• Assigned to ${volunteer.unit_name}` : ''}
                </div>
            </div>
            
            <div class="profile-content">
                <!-- Personal Information -->
                <div class="section">
                    <h2 class="section-title">Personal Information</h2>
                    <div class="info-grid">
                        <div class="info-item">
                            <div class="info-label">Full Name</div>
                            <div class="info-value">${fullName}</div>
                        </div>
                        <div class="info-item">
                            <div class="info-label">Date of Birth</div>
                            <div class="info-value">${volunteer.date_of_birth}</div>
                        </div>
                        <div class="info-item">
                            <div class="info-label">Gender</div>
                            <div class="info-value">${volunteer.gender}</div>
                        </div>
                        <div class="info-item">
                            <div class="info-label">Civil Status</div>
                            <div class="info-value">${volunteer.civil_status}</div>
                        </div>
                        <div class="info-item">
                            <div class="info-label">Email</div>
                            <div class="info-value">${volunteer.email}</div>
                        </div>
                        <div class="info-item">
                            <div class="info-label">Contact Number</div>
                            <div class="info-value">${volunteer.contact_number}</div>
                        </div>
                    </div>
                    <div class="info-item">
                        <div class="info-label">Address</div>
                        <div class="info-value">${volunteer.address ? volunteer.address.replace(/\n/g, '<br>') : 'N/A'}</div>
                    </div>
                </div>
                
                <!-- Skills & Qualifications -->
                <div class="section">
                    <h2 class="section-title">Skills & Qualifications</h2>
                    <div class="skills-container">
        `;
        
        // Only show skills with value 1
        if (volunteer.skills_basic_firefighting == 1) {
            html += `<span class="profile-skill-tag skill-active">Basic Firefighting</span>`;
        }
        if (volunteer.skills_first_aid_cpr == 1) {
            html += `<span class="profile-skill-tag skill-active">First Aid/CPR</span>`;
        }
        if (volunteer.skills_search_rescue == 1) {
            html += `<span class="profile-skill-tag skill-active">Search & Rescue</span>`;
        }
        if (volunteer.skills_driving == 1) {
            html += `<span class="profile-skill-tag skill-active">Driving</span>`;
        }
        if (volunteer.skills_communication == 1) {
            html += `<span class="profile-skill-tag skill-active">Communication</span>`;
        }
        if (volunteer.skills_mechanical == 1) {
            html += `<span class="profile-skill-tag skill-active">Mechanical</span>`;
        }
        if (volunteer.skills_logistics == 1) {
            html += `<span class="profile-skill-tag skill-active">Logistics</span>`;
        }
        
        html += `
                    </div>
                </div>
                
                <!-- Additional Information -->
                <div class="section">
                    <h2 class="section-title">Additional Information</h2>
                    <div class="info-grid">
                        <div class="info-item">
                            <div class="info-label">Education</div>
                            <div class="info-value">${volunteer.education ? volunteer.education : 'N/A'}</div>
                        </div>
                        <div class="info-item">
                            <div class="info-label">Physical Fitness</div>
                            <div class="info-value">${volunteer.physical_fitness ? volunteer.physical_fitness : 'N/A'}</div>
                        </div>
                        <div class="info-item">
                            <div class="info-label">Languages Spoken</div>
                            <div class="info-value">${volunteer.languages_spoken ? volunteer.languages_spoken : 'N/A'}</div>
                        </div>
                        <div class="info-item">
                            <div class="info-label">Application Date</div>
                            <div class="info-value">${volunteer.application_date}</div>
                        </div>
                    </div>
                </div>
                
                <!-- Identification -->
                <div class="section">
                    <h2 class="section-title">Identification</h2>
                    <div class="info-grid">
                        <div class="info-item">
                            <div class="info-label">Valid ID Type</div>
                            <div class="info-value">${volunteer.valid_id_type ? volunteer.valid_id_type : 'N/A'}</div>
                        </div>
                        <div class="info-item">
                            <div class="info-label">Valid ID Number</div>
                            <div class="info-value">${volunteer.valid_id_number ? volunteer.valid_id_number : 'N/A'}</div>
                        </div>
                    </div>
                    <div class="id-photos">
                        <div class="id-photo">
                            <div class="info-label">ID Front Photo</div>
                            ${frontPhoto ? 
                                `<img src="${frontPhoto}" alt="ID Front" class="id-photo-img" onerror="this.onerror=null; this.src='../../img/placeholder-id.png'; this.alt='ID Front Placeholder';">` : 
                                '<div style="padding: 40px; text-align: center; color: var(--text-light); background: var(--card-bg); border-radius: 10px;">No ID Front Photo Uploaded</div>'}
                        </div>
                        <div class="id-photo">
                            <div class="info-label">ID Back Photo</div>
                            ${backPhoto ? 
                                `<img src="${backPhoto}" alt="ID Back" class="id-photo-img" onerror="this.onerror=null; this.src='../../img/placeholder-id.png'; this.alt='ID Back Placeholder';">` : 
                                '<div style="padding: 40px; text-align: center; color: var(--text-light); background: var(--card-bg); border-radius: 10px;">No ID Back Photo Uploaded</div>'}
                        </div>
                    </div>
                </div>
            </div>
        `;
        
        modalBody.innerHTML = html;
    }
    
    // Close Profile Modal
    function closeProfileModal() {
        document.getElementById('profile-modal').classList.remove('active');
    }
    
    // Show Notification
    function showNotification(type, title, message) {
        const container = document.getElementById('notification-container');
        const notification = document.createElement('div');
        notification.className = `notification notification-${type}`;
        
        let icon = 'bx-info-circle';
        if (type === 'success') icon = 'bx-check-circle';
        if (type === 'error') icon = 'bx-error';
        if (type === 'warning') icon = 'bx-error-circle';
        
        notification.innerHTML = `
            <i class='bx ${icon} notification-icon'></i>
            <div class="notification-content">
                <div class="notification-title">${title}</div>
                <div class="notification-message">${message}</div>
            </div>
            <button class="notification-close">&times;</button>
        `;
        
        container.appendChild(notification);
        
        // Add close event
        notification.querySelector('.notification-close').addEventListener('click', function() {
            notification.classList.remove('show');
            setTimeout(() => {
                container.removeChild(notification);
            }, 300);
        });
        
        // Show notification
        setTimeout(() => {
            notification.classList.add('show');
        }, 100);
        
        // Auto remove after 5 seconds
        setTimeout(() => {
            if (notification.parentNode) {
                notification.classList.remove('show');
                setTimeout(() => {
                    if (notification.parentNode) {
                        container.removeChild(notification);
                    }
                }, 300);
            }
        }, 5000);
    }
    
    // Toggle Submenu
    function toggleSubmenu(id) {
        const submenu = document.getElementById(id);
        const arrow = submenu.previousElementSibling.querySelector('.dropdown-arrow');
        
        submenu.classList.toggle('active');
        if (arrow) {
            arrow.classList.toggle('rotated');
        }
    }
    
    // Update Time
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
    
    // Initialize Event Listeners
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
        
        // Refresh button
        document.getElementById('refresh-button').addEventListener('click', function() {
            showNotification('info', 'Refreshing Data', 'Fetching the latest volunteer assignments');
            location.reload();
        });
        
        // Export button
        document.getElementById('export-button').addEventListener('click', function() {
            showNotification('info', 'Export Started', 'Preparing assignment report for download');
            // In real implementation, trigger export process
        });
        
        // Search functionality
        document.getElementById('search-input').addEventListener('input', function(e) {
            const searchTerm = e.target.value.toLowerCase();
            const rows = document.querySelectorAll('.volunteers-table tbody tr');
            
            rows.forEach(row => {
                const volunteerName = row.querySelector('.volunteer-name').textContent.toLowerCase();
                const volunteerEmail = row.querySelector('.volunteer-email').textContent.toLowerCase();
                const contactNumber = row.cells[1].textContent.toLowerCase();
                
                if (volunteerName.includes(searchTerm) || volunteerEmail.includes(searchTerm) || contactNumber.includes(searchTerm)) {
                    row.style.display = '';
                } else {
                    row.style.display = 'none';
                }
            });
        });
        
        // Profile modal events
        document.getElementById('profile-modal-close').addEventListener('click', closeProfileModal);
        document.getElementById('profile-close').addEventListener('click', closeProfileModal);
        
        // AI Modal Event Listeners
        document.getElementById('ai-modal-close').addEventListener('click', closeAIModal);
        document.getElementById('ai-button-close').addEventListener('click', closeAIModal);
        document.getElementById('ai-button-assign').addEventListener('click', function() {
            if (selectedUnitId && selectedVolunteerId) {
                // Direct assignment without password confirmation
                assignToUnitFromAI(selectedVolunteerId, selectedUnitId);
                closeAIModal();
            }
        });
        
        // Close modals when clicking outside
        document.addEventListener('click', function(e) {
            if (e.target.classList.contains('modal-overlay')) {
                closeProfileModal();
            }
            if (e.target.id === 'ai-modal') {
                closeAIModal();
            }
        });
        
        // Escape key to close modals
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                closeProfileModal();
                closeAIModal();
            }
        });
        
        // User profile dropdown functionality
        const userProfile = document.getElementById('user-profile');
        const userProfileDropdown = document.getElementById('user-profile-dropdown');
        
        userProfile.addEventListener('click', function(e) {
            e.stopPropagation();
            userProfileDropdown.classList.toggle('active');
        });
        
        // Close dropdown when clicking elsewhere
        document.addEventListener('click', function() {
            userProfileDropdown.classList.remove('active');
        });
    }

    // Assign to Unit from AI Recommendation
    function assignToUnitFromAI(volunteerId, unitId) {
        const assignButton = document.querySelector(`tr[data-id="${volunteerId}"] .assign-button`);
        if (assignButton) {
            assignButton.disabled = true;
            assignButton.innerHTML = '<i class="bx bx-loader-circle bx-spin"></i> Assigning...';
        }
        
        fetch('assign_volunteer_unit.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                },
            body: JSON.stringify({
                volunteer_id: volunteerId,
                unit_id: unitId,
                assigned_by: <?php echo $user_id; ?>
            })
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                showNotification('success', 'AI Assignment Successful', 'Volunteer has been assigned to the recommended unit');
                setTimeout(() => {
                    location.reload();
                }, 1500);
            } else {
                showNotification('error', 'Assignment Failed', data.message || 'Failed to assign volunteer to unit');
                if (assignButton) {
                    assignButton.disabled = false;
                    assignButton.innerHTML = '<i class="bx bx-user-plus"></i> Assign';
                }
            }
        })
        .catch(error => {
            console.error('Error:', error);
            showNotification('error', 'Error', 'Failed to assign volunteer to unit');
            if (assignButton) {
                assignButton.disabled = false;
                assignButton.innerHTML = '<i class="bx bx-user-plus"></i> Assign';
            }
        });
    }
    
    // DOM Content Loaded
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
        
        // Show welcome notification
        showNotification('success', 'System Ready', 'Volunteer assignment system with Dialogflow AI is now active');
    });

    // Initialize time and set interval
    updateTime();
    setInterval(updateTime, 1000);
</script>
</body>
</html>