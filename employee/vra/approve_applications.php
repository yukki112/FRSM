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

// Get approved volunteers with pagination
$volunteers_query = "SELECT v.*, u.unit_name, u.unit_code, u.id as unit_id
                     FROM volunteers v 
                     LEFT JOIN volunteer_assignments va ON v.id = va.volunteer_id 
                     LEFT JOIN units u ON va.unit_id = u.id 
                     WHERE v.status = 'approved' 
                     ORDER BY v.full_name ASC
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
    <title>Approve & Assign Volunteers - Fire Rescue Service Management</title>
    <link rel="icon" type="image/x-icon" href="../../assets/images/logo.ico">
    <link href='https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css' rel='stylesheet'>
    <style>
        /* ... existing CSS remains unchanged ... keep all previous styles ... */
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        :root {
            --primary: #ff6b6b;
            --primary-dark: #ff5252;
            --secondary: #4ecdc4;
            --background: #0f1419;
            --surface: #1a1f2e;
            --surface-light: #252d3d;
            --text: #e0e0e0;
            --text-light: #9ca3af;
            --border: #2d3748;
            --success: #51cf66;
            --warning: #ffd43b;
            --error: #ff6b6b;
            --info: #4dabf7;
        }
        
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, Cantarell, sans-serif;
            background-color: var(--background);
            color: var(--text);
            line-height: 1.6;
            overflow-x: hidden;
        }
        
        body.dark-mode {
            background-color: #0a0e14;
        }
        
        .container {
            display: flex;
            height: 100vh;
        }
        
        .sidebar {
            width: 280px;
            background-color: var(--surface);
            border-right: 1px solid var(--border);
            padding: 30px 20px;
            overflow-y: auto;
            position: fixed;
            height: 100vh;
            left: 0;
            top: 0;
        }
        
        .main-content {
            margin-left: 280px;
            flex: 1;
            display: flex;
            flex-direction: column;
            height: 100vh;
        }
        
        .header {
            background-color: var(--surface);
            border-bottom: 1px solid var(--border);
            padding: 20px 40px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            position: sticky;
            top: 0;
            z-index: 100;
        }
        
        .header-title {
            font-size: 24px;
            font-weight: 700;
            color: var(--text);
        }
        
        .header-actions {
            display: flex;
            gap: 15px;
            align-items: center;
        }
        
        .header-button {
            background: none;
            border: none;
            color: var(--text);
            cursor: pointer;
            font-size: 20px;
            padding: 8px;
            border-radius: 8px;
            transition: all 0.3s ease;
        }
        
        .header-button:hover {
            background-color: var(--surface-light);
            color: var(--primary);
        }
        
        .content {
            flex: 1;
            overflow-y: auto;
            padding: 30px 40px;
        }
        
        .approve-container {
            animation: fadeIn 0.5s ease;
        }
        
        @keyframes fadeIn {
            from {
                opacity: 0;
                transform: translateY(10px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        .stats-container {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-bottom: 40px;
        }
        
        .stat-card {
            background: linear-gradient(135deg, var(--surface-light), var(--surface));
            border: 1px solid var(--border);
            border-radius: 12px;
            padding: 25px;
            display: flex;
            gap: 20px;
            align-items: center;
            transition: all 0.3s ease;
        }
        
        .stat-card:hover {
            transform: translateY(-5px);
            border-color: var(--primary);
            box-shadow: 0 8px 16px rgba(255, 107, 107, 0.1);
        }
        
        .stat-icon {
            font-size: 32px;
            color: var(--primary);
            background: rgba(255, 107, 107, 0.1);
            width: 60px;
            height: 60px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 12px;
        }
        
        .stat-content {
            flex: 1;
        }
        
        .stat-value {
            font-size: 24px;
            font-weight: 700;
            color: var(--text);
        }
        
        .stat-label {
            font-size: 14px;
            color: var(--text-light);
            margin-top: 5px;
        }
        
        .volunteers-table-container {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 12px;
            overflow: hidden;
        }
        
        .table-header {
            background: var(--surface-light);
            padding: 20px;
            border-bottom: 1px solid var(--border);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .table-title {
            font-size: 18px;
            font-weight: 600;
            color: var(--text);
        }
        
        .table-info {
            font-size: 13px;
            color: var(--text-light);
        }
        
        .volunteers-table {
            width: 100%;
            border-collapse: collapse;
        }
        
        .volunteers-table thead {
            background: var(--surface-light);
        }
        
        .volunteers-table th {
            padding: 15px 20px;
            text-align: left;
            font-weight: 600;
            color: var(--text-light);
            font-size: 13px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            border-bottom: 1px solid var(--border);
        }
        
        .volunteers-table td {
            padding: 15px 20px;
            border-bottom: 1px solid var(--border);
        }
        
        .volunteer-info {
            display: flex;
            gap: 12px;
            align-items: center;
        }
        
        .volunteer-avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            color: white;
            font-size: 16px;
        }
        
        .volunteer-name {
            font-weight: 600;
            color: var(--text);
        }
        
        .volunteer-email {
            font-size: 13px;
            color: var(--text-light);
            margin-top: 4px;
        }
        
        .skills-tags {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
        }
        
        .skill-tag {
            display: inline-block;
            padding: 5px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 500;
        }
        
        .skill-fire {
            background: rgba(255, 107, 107, 0.2);
            color: #ff8787;
        }
        
        .skill-medical {
            background: rgba(76, 205, 196, 0.2);
            color: #72e0d8;
        }
        
        .skill-rescue {
            background: rgba(255, 176, 59, 0.2);
            color: #ffc857;
        }
        
        .skill-drive {
            background: rgba(81, 207, 102, 0.2);
            color: #69f0ae;
        }
        
        .assigned-unit {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 6px 12px;
            background: rgba(81, 207, 102, 0.1);
            border-radius: 6px;
            color: #69f0ae;
            font-size: 13px;
        }
        
        .action-buttons {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }
        
        .action-button {
            padding: 8px 12px;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-size: 13px;
            font-weight: 500;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            transition: all 0.3s ease;
        }
        
        .view-button {
            background: rgba(77, 171, 247, 0.1);
            color: #4dabf7;
        }
        
        .view-button:hover {
            background: rgba(77, 171, 247, 0.2);
            transform: translateY(-2px);
        }
        
        .assign-button {
            background: rgba(81, 207, 102, 0.1);
            color: #69f0ae;
        }
        
        .assign-button:hover {
            background: rgba(81, 207, 102, 0.2);
            transform: translateY(-2px);
        }
        
        .reassign-button {
            background: rgba(255, 176, 59, 0.1);
            color: #ffc857;
        }
        
        .reassign-button:hover {
            background: rgba(255, 176, 59, 0.2);
            transform: translateY(-2px);
        }
        
        .unit-select {
            padding: 8px 12px;
            background: var(--surface-light);
            border: 1px solid var(--border);
            border-radius: 6px;
            color: var(--text);
            font-size: 13px;
            cursor: pointer;
        }
        
        .unit-select:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(255, 107, 107, 0.1);
        }
        
        .pagination {
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 10px;
            padding: 20px;
            border-top: 1px solid var(--border);
        }
        
        .pagination-button {
            padding: 8px 12px;
            background: rgba(77, 171, 247, 0.1);
            border: 1px solid var(--border);
            color: var(--text);
            border-radius: 6px;
            cursor: pointer;
            font-size: 13px;
            display: flex;
            align-items: center;
            gap: 5px;
            transition: all 0.3s ease;
        }
        
        .pagination-button:hover:not(:disabled) {
            background: rgba(77, 171, 247, 0.2);
            border-color: #4dabf7;
        }
        
        .pagination-button:disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }
        
        .pagination-info {
            color: var(--text-light);
            font-size: 13px;
        }
        
        .pagination-numbers {
            display: flex;
            gap: 5px;
        }
        
        .page-number {
            width: 32px;
            height: 32px;
            display: flex;
            align-items: center;
            justify-content: center;
            border: 1px solid var(--border);
            border-radius: 6px;
            color: var(--text);
            text-decoration: none;
            cursor: pointer;
            font-size: 13px;
            transition: all 0.3s ease;
        }
        
        .page-number:hover {
            border-color: #4dabf7;
            color: #4dabf7;
        }
        
        .page-number.active {
            background: var(--primary);
            border-color: var(--primary);
            color: white;
        }
        
        .no-volunteers {
            text-align: center;
            padding: 60px 20px;
        }
        
        .no-volunteers-icon {
            font-size: 64px;
            color: var(--text-light);
            margin-bottom: 20px;
        }
        
        .no-volunteers h3 {
            font-size: 24px;
            color: var(--text);
            margin-bottom: 10px;
        }
        
        .no-volunteers p {
            color: var(--text-light);
            margin-bottom: 20px;
        }
        
        /* Modal Styles */
        .modal-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.7);
            z-index: 1000;
            align-items: center;
            justify-content: center;
        }
        
        .modal-overlay.active {
            display: flex;
        }
        
        .modal {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 12px;
            max-width: 500px;
            width: 90%;
            max-height: 90vh;
            overflow-y: auto;
        }
        
        .modal-header {
            padding: 20px;
            border-bottom: 1px solid var(--border);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .modal-title {
            font-size: 18px;
            font-weight: 600;
            color: var(--text);
        }
        
        .modal-close {
            background: none;
            border: none;
            font-size: 28px;
            color: var(--text-light);
            cursor: pointer;
            transition: all 0.3s ease;
        }
        
        .modal-close:hover {
            color: var(--primary);
        }
        
        .modal-body {
            padding: 20px;
        }
        
        .modal-detail {
            margin-bottom: 15px;
        }
        
        .modal-label {
            display: block;
            font-size: 14px;
            font-weight: 500;
            color: var(--text);
            margin-bottom: 8px;
        }
        
        .modal-input {
            width: 100%;
            padding: 10px;
            background: var(--surface-light);
            border: 1px solid var(--border);
            border-radius: 6px;
            color: var(--text);
            font-size: 14px;
        }
        
        .modal-input:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(255, 107, 107, 0.1);
        }
        
        .modal-footer {
            padding: 20px;
            border-top: 1px solid var(--border);
            display: flex;
            gap: 10px;
            justify-content: flex-end;
        }
        
        /* New AI Recommendation Styles */
        .ai-recommendation-button {
            background: linear-gradient(135deg, var(--primary), #ff8787);
            color: white;
            padding: 10px 16px;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: all 0.3s ease;
            font-size: 13px;
        }
        
        .ai-recommendation-button:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 16px rgba(255, 107, 107, 0.3);
        }
        
        .ai-recommendation-button:disabled {
            opacity: 0.6;
            cursor: not-allowed;
        }
        
        .recommendation-modal {
            max-width: 600px;
        }
        
        .recommendation-card {
            background: var(--surface-light);
            border: 1px solid var(--border);
            border-radius: 8px;
            padding: 20px;
            margin-bottom: 15px;
            transition: all 0.3s ease;
        }
        
        .recommendation-card:hover {
            border-color: var(--primary);
            box-shadow: 0 4px 12px rgba(255, 107, 107, 0.1);
        }
        
        .recommendation-rank {
            display: inline-block;
            background: var(--primary);
            color: white;
            width: 28px;
            height: 28px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            font-size: 14px;
            margin-bottom: 10px;
        }
        
        .recommendation-header {
            display: flex;
            justify-content: space-between;
            align-items: start;
            margin-bottom: 12px;
        }
        
        .recommendation-unit-name {
            font-size: 16px;
            font-weight: 600;
            color: var(--text);
        }
        
        .recommendation-code {
            font-size: 12px;
            color: var(--text-light);
            margin-top: 4px;
        }
        
        .recommendation-score {
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .score-badge {
            background: rgba(81, 207, 102, 0.1);
            color: #69f0ae;
            padding: 6px 12px;
            border-radius: 20px;
            font-weight: 600;
            font-size: 14px;
        }
        
        .recommendation-details {
            margin-top: 12px;
            font-size: 13px;
            color: var(--text-light);
        }
        
        .recommendation-detail-item {
            display: flex;
            align-items: center;
            gap: 8px;
            margin-bottom: 6px;
        }
        
        .detail-icon {
            color: var(--secondary);
        }
        
        .recommendation-action {
            margin-top: 15px;
        }
        
        .accept-recommendation-btn {
            width: 100%;
            padding: 10px;
            background: rgba(81, 207, 102, 0.1);
            border: 1px solid #69f0ae;
            color: #69f0ae;
            border-radius: 6px;
            cursor: pointer;
            font-weight: 600;
            transition: all 0.3s ease;
        }
        
        .accept-recommendation-btn:hover {
            background: rgba(81, 207, 102, 0.2);
        }
        
        .ai-thinking {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            color: var(--text-light);
            font-size: 13px;
        }
        
        .spinner {
            display: inline-block;
            width: 16px;
            height: 16px;
            border: 2px solid rgba(255, 107, 107, 0.2);
            border-top-color: var(--primary);
            border-radius: 50%;
            animation: spin 0.8s linear infinite;
        }
        
        @keyframes spin {
            to { transform: rotate(360deg); }
        }

        /* ... remaining styles ... */
        .sidebar-menu {
            list-style: none;
        }
        
        .sidebar-item {
            margin-bottom: 10px;
        }
        
        .sidebar-link {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 12px;
            text-decoration: none;
            color: var(--text-light);
            border-radius: 8px;
            transition: all 0.3s ease;
        }
        
        .sidebar-link:hover {
            background: var(--surface-light);
            color: var(--primary);
        }
        
        .sidebar-link.active {
            background: var(--primary);
            color: white;
        }
        
        .user-profile {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 12px;
            background: var(--surface-light);
            border-radius: 8px;
            cursor: pointer;
            position: relative;
        }
        
        .user-avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            color: white;
        }
        
        .profile-dropdown {
            position: absolute;
            bottom: -120px;
            left: 0;
            right: 0;
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 8px;
            overflow: hidden;
            display: none;
            z-index: 1000;
        }
        
        .profile-dropdown.active {
            display: block;
        }
        
        .profile-dropdown-item {
            display: block;
            width: 100%;
            padding: 12px;
            background: none;
            border: none;
            color: var(--text);
            text-align: left;
            cursor: pointer;
            border-bottom: 1px solid var(--border);
            transition: all 0.3s ease;
        }
        
        .profile-dropdown-item:last-child {
            border-bottom: none;
        }
        
        .profile-dropdown-item:hover {
            background: var(--surface-light);
            color: var(--primary);
        }

        /* Notification System */
        .notification {
            position: fixed;
            top: 20px;
            right: 20px;
            padding: 16px 20px;
            border-radius: 8px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.3);
            z-index: 2000;
            animation: slideIn 0.3s ease;
            display: flex;
            align-items: center;
            gap: 12px;
            max-width: 400px;
        }
        
        @keyframes slideIn {
            from {
                transform: translateX(400px);
                opacity: 0;
            }
            to {
                transform: translateX(0);
                opacity: 1;
            }
        }
        
        .notification.success {
            background: rgba(81, 207, 102, 0.1);
            border: 1px solid #69f0ae;
            color: #69f0ae;
        }
        
        .notification.error {
            background: rgba(255, 107, 107, 0.1);
            border: 1px solid #ff8787;
            color: #ff8787;
        }
        
        .notification.info {
            background: rgba(77, 171, 247, 0.1);
            border: 1px solid #4dabf7;
            color: #4dabf7;
        }
        
        .notification.warning {
            background: rgba(255, 176, 59, 0.1);
            border: 1px solid #ffc857;
            color: #ffc857;
        }

        /* Profile Modal */
        .profile-modal {
            max-width: 800px;
            max-height: 85vh;
        }

        .profile-header {
            text-align: center;
            padding-bottom: 20px;
            border-bottom: 1px solid var(--border);
        }

        .profile-avatar {
            width: 80px;
            height: 80px;
            border-radius: 50%;
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            color: white;
            font-size: 32px;
            margin: 0 auto 15px;
        }

        .profile-name {
            font-size: 24px;
            font-weight: 700;
            color: var(--text);
        }

        .profile-status {
            font-size: 13px;
            color: var(--text-light);
            margin-top: 8px;
        }

        .profile-content {
            padding: 20px 0;
        }

        .section {
            margin-bottom: 30px;
        }

        .section-title {
            font-size: 16px;
            font-weight: 600;
            color: var(--text);
            margin-bottom: 15px;
            padding-bottom: 10px;
            border-bottom: 1px solid var(--border);
        }

        .info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
        }

        .info-item {
            display: flex;
            flex-direction: column;
        }

        .info-label {
            font-size: 12px;
            color: var(--text-light);
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 6px;
            font-weight: 600;
        }

        .info-value {
            font-size: 14px;
            color: var(--text);
        }

        .skills-container {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
        }

        .profile-skill-tag {
            display: inline-block;
            padding: 8px 16px;
            border-radius: 20px;
            font-size: 13px;
            font-weight: 500;
            background: rgba(76, 205, 196, 0.1);
            color: #72e0d8;
        }

        .profile-skill-tag.skill-active {
            background: rgba(81, 207, 102, 0.1);
            color: #69f0ae;
        }

        .id-photos {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-top: 15px;
        }

        .id-photo {
            display: flex;
            flex-direction: column;
        }

        .id-photo-img {
            width: 100%;
            height: 250px;
            object-fit: cover;
            border-radius: 8px;
            margin-top: 10px;
            border: 1px solid var(--border);
        }

        .primary-button {
            background: var(--primary);
            color: white;
            padding: 12px 24px;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-weight: 600;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: all 0.3s ease;
        }

        .primary-button:hover {
            background: var(--primary-dark);
            transform: translateY(-2px);
        }
    </style>
</head>
<body>
    <div class="container">
        <!-- Sidebar -->
        <div class="sidebar">
            <div style="margin-bottom: 40px;">
                <h2 style="font-size: 24px; font-weight: 700; color: var(--text); margin-bottom: 5px;">FRSM</h2>
                <p style="font-size: 12px; color: var(--text-light); text-transform: uppercase; letter-spacing: 1px;">System</p>
            </div>
            
            <ul class="sidebar-menu">
                <li class="sidebar-item">
                    <a href="../../dashboard/admin_dashboard.php" class="sidebar-link">
                        <i class='bx bx-grid-alt'></i>
                        <span>Dashboard</span>
                    </a>
                </li>
                <li class="sidebar-item">
                    <a href="review_data.php" class="sidebar-link">
                        <i class='bx bx-list-check'></i>
                        <span>Review Applications</span>
                    </a>
                </li>
                <li class="sidebar-item">
                    <a href="approve_applications.php" class="sidebar-link active">
                        <i class='bx bx-user-check'></i>
                        <span>Approve & Assign</span>
                    </a>
                </li>
                <li class="sidebar-item">
                    <a href="../../dashboard/manage_volunteers.php" class="sidebar-link">
                        <i class='bx bx-group'></i>
                        <span>Manage Volunteers</span>
                    </a>
                </li>
            </ul>
            
            <div style="position: absolute; bottom: 30px; left: 20px; right: 20px;">
                <div id="user-profile" class="user-profile">
                    <div class="user-avatar"><?php echo strtoupper(substr($first_name, 0, 1)); ?></div>
                    <div style="flex: 1;">
                        <div style="font-size: 14px; font-weight: 600; color: var(--text);"><?php echo htmlspecialchars($first_name); ?></div>
                        <div style="font-size: 12px; color: var(--text-light);"><?php echo htmlspecialchars($role); ?></div>
                    </div>
                    <i class='bx bx-chevron-down'></i>
                </div>
                <div id="user-profile-dropdown" class="profile-dropdown">
                    <button class="profile-dropdown-item" id="theme-toggle">
                        <i class='bx bx-moon'></i> Dark Mode
                    </button>
                    <button class="profile-dropdown-item" onclick="window.location.href='../../auth/logout.php'">
                        <i class='bx bx-log-out'></i> Logout
                    </button>
                </div>
            </div>
        </div>
        
        <!-- Main Content -->
        <div class="main-content">
            <div class="header">
                <h1 class="header-title">Approve & Assign Volunteers</h1>
                <div class="header-actions">
                    <button class="header-button" id="search-button" title="Search">
                        <i class='bx bx-search'></i>
                    </button>
                    <button class="header-button" id="refresh-button" title="Refresh">
                        <i class='bx bx-refresh'></i>
                    </button>
                    <button class="header-button" id="export-button" title="Export">
                        <i class='bx bx-download'></i>
                    </button>
                </div>
            </div>
            
            <div class="content">
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
                                                        <?php echo strtoupper(substr($volunteer['full_name'], 0, 1)); ?>
                                                    </div>
                                                    <div>
                                                        <div class="volunteer-name"><?php echo htmlspecialchars($volunteer['full_name']); ?></div>
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
                                                    <!-- Added AI Recommendation Button -->
                                                    <?php if (empty($volunteer['unit_name'])): ?>
                                                        <button class="ai-recommendation-button" onclick="getAIRecommendation(<?php echo $volunteer['id']; ?>, '<?php echo htmlspecialchars($volunteer['full_name']); ?>')">
                                                            <i class='bx bx-brain'></i>
                                                            AI Suggest
                                                        </button>
                                                        <select class="unit-select" id="unit-select-<?php echo $volunteer['id']; ?>">
                                                            <option value="">Select Unit</option>
                                                            <?php foreach ($units as $unit): ?>
                                                                <option value="<?php echo $unit['id']; ?>">
                                                                    <?php echo htmlspecialchars($unit['unit_name']); ?> (<?php echo htmlspecialchars($unit['unit_code']); ?>)
                                                                </option>
                                                            <?php endforeach; ?>
                                                        </select>
                                                        <button class="action-button assign-button" onclick="confirmAssignToUnit(<?php echo $volunteer['id']; ?>)">
                                                            <i class='bx bx-user-plus'></i>
                                                            Assign
                                                        </button>
                                                    <?php else: ?>
                                                        <button class="action-button reassign-button" onclick="confirmReassignUnit(<?php echo $volunteer['id']; ?>)">
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
    
    <!-- Search Modal -->
    <div class="modal-overlay" id="search-modal">
        <div class="modal" style="max-width: 600px;">
            <div class="modal-header">
                <h2 class="modal-title">Search Volunteers</h2>
                <button class="modal-close" onclick="document.getElementById('search-modal').classList.remove('active')">&times;</button>
            </div>
            <div class="modal-body">
                <div class="modal-detail">
                    <input type="text" class="modal-input" id="search-input" placeholder="Search by name, email, or phone...">
                </div>
            </div>
        </div>
    </div>
    
    <!-- Password Verification Modal -->
    <div class="modal-overlay" id="password-modal">
        <div class="modal">
            <div class="modal-header">
                <h2 class="modal-title">Security Verification</h2>
                <button class="modal-close" id="password-modal-close">&times;</button>
            </div>
            <div class="modal-body">
                <div class="modal-detail">
                    <label class="modal-label">Enter your password to confirm this action:</label>
                    <input type="password" class="modal-input" id="confirm-password" placeholder="Enter your password">
                    <input type="hidden" id="confirm-action">
                    <input type="hidden" id="confirm-volunteer-id">
                    <input type="hidden" id="confirm-unit-id">
                </div>
            </div>
            <div class="modal-footer">
                <button class="action-button view-button" id="password-cancel">Cancel</button>
                <button class="action-button assign-button" id="password-confirm">Confirm Action</button>
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
    
    <!-- AI Recommendation Modal -->
    <div class="modal-overlay" id="recommendation-modal">
        <div class="modal recommendation-modal">
            <div class="modal-header">
                <h2 class="modal-title"><i class='bx bx-brain'></i> AI Unit Recommendations</h2>
                <button class="modal-close" id="recommendation-modal-close">&times;</button>
            </div>
            <div class="modal-body" id="recommendation-content">
                <div style="text-align: center; padding: 40px 20px;">
                    <div class="spinner" style="width: 40px; height: 40px; display: inline-block;"></div>
                    <p style="margin-top: 20px; color: var(--text-light);">AI is analyzing volunteer skills...</p>
                </div>
            </div>
        </div>
    </div>

    <script>
        let currentAssignment = {
            volunteerId: null,
            unitId: null,
            action: null
        };

        document.addEventListener('DOMContentLoaded', function() {
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
            
            // Password modal events
            document.getElementById('password-modal-close').addEventListener('click', closePasswordModal);
            document.getElementById('password-cancel').addEventListener('click', closePasswordModal);
            document.getElementById('password-confirm').addEventListener('click', executeConfirmedAction);
            
            // Profile modal events
            document.getElementById('profile-modal-close').addEventListener('click', closeProfileModal);
            document.getElementById('profile-close').addEventListener('click', closeProfileModal);
            
            document.getElementById('recommendation-modal-close').addEventListener('click', closeRecommendationModal);
            
            // Enter key in password field
            document.getElementById('confirm-password').addEventListener('keypress', function(e) {
                if (e.key === 'Enter') {
                    executeConfirmedAction();
                }
            });
            
            // Close modals when clicking outside
            document.addEventListener('click', function(e) {
                if (e.target.classList.contains('modal-overlay')) {
                    closePasswordModal();
                    closeProfileModal();
                    closeRecommendationModal();
                }
            });
            
            // Escape key to close modals
            document.addEventListener('keydown', function(e) {
                if (e.key === 'Escape') {
                    closePasswordModal();
                    closeProfileModal();
                    closeRecommendationModal();
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
            
            // Search button
            document.getElementById('search-button').addEventListener('click', function() {
                document.getElementById('search-modal').classList.add('active');
                document.getElementById('search-input').focus();
            });
        }
        
        function getAIRecommendation(volunteerId, volunteerName) {
            const modal = document.getElementById('recommendation-modal');
            modal.classList.add('active');
            
            // Fetch recommendations from the API
            fetch('get_recommendation_api.php', {
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
                if (data.success && data.recommendations) {
                    displayRecommendations(data.recommendations, volunteerId);
                } else {
                    showRecommendationError('Failed to get recommendations. Please try again.');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showRecommendationError('Error getting AI recommendations. Please try again.');
            });
        }
        
        function displayRecommendations(recommendations, volunteerId) {
            const contentDiv = document.getElementById('recommendation-content');
            let html = '<div style="margin-bottom: 20px;">';
            
            recommendations.forEach((rec, index) => {
                const rank = index + 1;
                html += `
                    <div class="recommendation-card">
                        <div style="display: flex; justify-content: space-between; align-items: start; margin-bottom: 12px;">
                            <div style="flex: 1;">
                                <div style="display: inline-block; background: ${rank === 1 ? '#51cf66' : rank === 2 ? '#4dabf7' : '#ffc857'}; color: white; width: 30px; height: 30px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-weight: 700; margin-bottom: 10px; margin-right: 10px;">${rank}</div>
                                <div class="recommendation-unit-name">${rec.unit_name}</div>
                                <div class="recommendation-code">${rec.unit_code}</div>
                            </div>
                            <div class="score-badge">${rec.score}% Match</div>
                        </div>
                        <div class="recommendation-details">
                            <div class="recommendation-detail-item">
                                <span class="detail-icon">📍</span>
                                <span>${rec.unit_type} Unit - ${rec.location}</span>
                            </div>
                            <div class="recommendation-detail-item">
                                <span class="detail-icon">👥</span>
                                <span>${rec.current_count}/${rec.capacity} capacity</span>
                            </div>
                            <div style="margin-top: 10px; padding-top: 10px; border-top: 1px solid var(--border);">
                                <div style="font-size: 12px; font-weight: 600; color: var(--text-light); margin-bottom: 8px;">Matched Skills:</div>
                                <div style="display: flex; flex-wrap: wrap; gap: 6px;">
                                    ${rec.matched_skills.map(skill => `<span style="background: rgba(81, 207, 102, 0.2); color: #69f0ae; padding: 4px 10px; border-radius: 4px; font-size: 12px;">✓ ${skill}</span>`).join('')}
                                </div>
                            </div>
                        </div>
                        <div class="recommendation-action">
                            <button class="accept-recommendation-btn" onclick="acceptRecommendation(${volunteerId}, ${rec.unit_id}, '${rec.unit_name}')">
                                Accept This Recommendation
                            </button>
                        </div>
                    </div>
                `;
            });
            
            html += '</div>';
            contentDiv.innerHTML = html;
        }
        
        function acceptRecommendation(volunteerId, unitId, unitName) {
            const selectElement = document.getElementById(`unit-select-${volunteerId}`);
            if (selectElement) {
                selectElement.value = unitId;
                closeRecommendationModal();
                showNotification('info', 'Unit Selected', `${unitName} has been selected. Click Assign to confirm.`);
            }
        }
        
        function showRecommendationError(message) {
            const contentDiv = document.getElementById('recommendation-content');
            contentDiv.innerHTML = `
                <div style="text-align: center; padding: 40px 20px;">
                    <i class='bx bx-error' style="font-size: 48px; color: var(--error); margin-bottom: 20px;"></i>
                    <p style="color: var(--text-light);">${message}</p>
                </div>
            `;
        }
        
        function closeRecommendationModal() {
            document.getElementById('recommendation-modal').classList.remove('active');
        }
        
        function confirmAssignToUnit(volunteerId) {
            const unitSelect = document.getElementById(`unit-select-${volunteerId}`);
            const unitId = unitSelect.value;
            
            if (!unitId) {
                showNotification('error', 'Selection Required', 'Please select a unit first');
                return;
            }
            
            // Get unit name for confirmation message
            const unitName = unitSelect.options[unitSelect.selectedIndex].text;
            const volunteerName = document.querySelector(`tr[data-id="${volunteerId}"] .volunteer-name`).textContent;
            
            currentAssignment = {
                volunteerId: volunteerId,
                unitId: unitId,
                action: 'assign'
            };
            
            showPasswordModal(`Assign ${volunteerName} to ${unitName}`);
        }
        
        function confirmReassignUnit(volunteerId) {
            const volunteerName = document.querySelector(`tr[data-id="${volunteerId}"] .volunteer-name`).textContent;
            
            currentAssignment = {
                volunteerId: volunteerId,
                unitId: null,
                action: 'reassign'
            };
            
            showPasswordModal(`Reassign ${volunteerName} to a different unit`);
        }
        
        function showPasswordModal(actionText) {
            document.getElementById('confirm-action').value = currentAssignment.action;
            document.getElementById('confirm-volunteer-id').value = currentAssignment.volunteerId;
            document.getElementById('confirm-unit-id').value = currentAssignment.unitId;
            
            document.getElementById('password-modal').classList.add('active');
            document.getElementById('confirm-password').value = '';
            document.getElementById('confirm-password').focus();
        }
        
        function closePasswordModal() {
            document.getElementById('password-modal').classList.remove('active');
            currentAssignment = { volunteerId: null, unitId: null, action: null };
        }
        
        function executeConfirmedAction() {
            const password = document.getElementById('confirm-password').value;
            
            if (!password) {
                showNotification('error', 'Password Required', 'Please enter your password to confirm this action');
                return;
            }
            
            // Verify password first
            verifyPassword(password)
                .then(isValid => {
                    if (isValid) {
                        if (currentAssignment.action === 'assign') {
                            assignToUnit(currentAssignment.volunteerId, currentAssignment.unitId);
                        } else if (currentAssignment.action === 'reassign') {
                            reassignUnit(currentAssignment.volunteerId);
                        }
                        closePasswordModal();
                    } else {
                        showNotification('error', 'Invalid Password', 'The password you entered is incorrect');
                        document.getElementById('confirm-password').value = '';
                        document.getElementById('confirm-password').focus();
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    showNotification('error', 'Verification Failed', 'Unable to verify password. Please try again.');
                });
        }
        
        function verifyPassword(password) {
            return fetch('verify_password.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    password: password
                })
            })
            .then(response => response.json())
            .then(data => data.valid);
        }
        
        function assignToUnit(volunteerId, unitId) {
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
                    showNotification('success', 'Assignment Successful', 'Volunteer has been securely assigned to the unit');
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
        
        function viewVolunteerProfile(volunteerId) {
            fetch(`get_volunteer_details.php?id=${volunteerId}`)
                .then(response => response.json())
                .then(volunteer => {
                    populateProfileModal(volunteer);
                    document.getElementById('profile-modal').classList.add('active');
                })
                .catch(error => {
                    console.error('Error:', error);
                    showNotification('error', 'Error', 'Failed to load volunteer profile');
                });
        }
        
        function populateProfileModal(volunteer) {
            const modalBody = document.getElementById('profile-modal-body');
            
            // Format ID photo paths
            const getImagePath = (filename) => {
                if (!filename) return null;
                const paths = [
                    `../../${filename}`,
                    `../${filename}`,
                    filename,
                    `../../uploads/volunteer_id_photos/${filename.split('/').pop()}`,
                    `../uploads/volunteer_id_photos/${filename.split('/').pop()}`
                ];
                return paths[0]; 
            };
            
            const frontPhoto = getImagePath(volunteer.id_front_photo);
            const backPhoto = getImagePath(volunteer.id_back_photo);
            
            let html = `
                <div class="profile-header">
                    <div class="profile-avatar">
                        ${volunteer.full_name.charAt(0).toUpperCase()}
                    </div>
                    <h1 class="profile-name">${volunteer.full_name}</h1>
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
                                <div class="info-value">${volunteer.full_name}</div>
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
                            <div class="info-value">${volunteer.address.replace(/\n/g, '<br>')}</div>
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
                                <div class="info-value">${volunteer.education}</div>
                            </div>
                            <div class="info-item">
                                <div class="info-label">Physical Fitness</div>
                                <div class="info-value">${volunteer.physical_fitness}</div>
                            </div>
                            <div class="info-item">
                                <div class="info-label">Languages Spoken</div>
                                <div class="info-value">${volunteer.languages_spoken}</div>
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
                                <div class="info-value">${volunteer.valid_id_type}</div>
                            </div>
                            <div class="info-item">
                                <div class="info-label">Valid ID Number</div>
                                <div class="info-value">${volunteer.valid_id_number}</div>
                            </div>
                        </div>
                        <div class="id-photos">
                            <div class="id-photo">
                                <div class="info-label">ID Front Photo</div>
                                ${frontPhoto ? 
                                    `<img src="${frontPhoto}" alt="ID Front" class="id-photo-img" onerror="this.style.display='none';">` : 
                                    '<div style="padding: 40px; text-align: center; color: var(--text-light); background: rgba(255,255,255,0.1); border-radius: 10px;">No ID Front Photo Uploaded</div>'}
                            </div>
                            <div class="id-photo">
                                <div class="info-label">ID Back Photo</div>
                                ${backPhoto ? 
                                    `<img src="${backPhoto}" alt="ID Back" class="id-photo-img" onerror="this.style.display='none';">` : 
                                    '<div style="padding: 40px; text-align: center; color: var(--text-light); background: rgba(255,255,255,0.1); border-radius: 10px;">No ID Back Photo Uploaded</div>'}
                            </div>
                        </div>
                    </div>
                </div>
            `;
            
            modalBody.innerHTML = html;
        }
        
        function closeProfileModal() {
            document.getElementById('profile-modal').classList.remove('active');
        }
        
        function showNotification(type, title, message) {
            const notification = document.createElement('div');
            notification.className = `notification ${type}`;
            notification.innerHTML = `
                <i class='bx ${
                    type === 'success' ? 'bx-check-circle' :
                    type === 'error' ? 'bx-error-circle' :
                    type === 'warning' ? 'bx-exclamation-circle' :
                    'bx-info-circle'
                }'></i>
                <div>
                    <strong>${title}</strong>
                    <p>${message}</p>
                </div>
            `;
            document.body.appendChild(notification);
            
            setTimeout(() => {
                notification.style.animation = 'slideOut 0.3s ease forwards';
                setTimeout(() => notification.remove(), 300);
            }, 5000);
        }
    </script>
</body>
</html>
