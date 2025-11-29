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

// Fetch incidents from API
$incidents = [];
$last_incident_id = 0;

try {
    $api_url = "https://frsm.qcprotektado.com/incidents.php";
    $context = stream_context_create([
        'ssl' => [
            'verify_peer' => false,
            'verify_peer_name' => false,
        ],
        'http' => [
            'timeout' => 10
        ]
    ]);
    
    $response = file_get_contents($api_url, false, $context);
    if ($response !== false) {
        $data = json_decode($response, true);
        if (isset($data['success']) && $data['success'] && isset($data['data'])) {
            $incidents = $data['data'];
            if (!empty($incidents)) {
                $last_incident_id = $incidents[0]['ID'];
            }
        }
    }
} catch (Exception $e) {
    error_log("Error fetching incidents: " . $e->getMessage());
}

// Get filter parameters
$status_filter = isset($_GET['status']) ? $_GET['status'] : 'all';
$emergency_level_filter = isset($_GET['emergency_level']) ? $_GET['emergency_level'] : 'all';
$incident_type_filter = isset($_GET['incident_type']) ? $_GET['incident_type'] : 'all';
$search_term = isset($_GET['search']) ? $_GET['search'] : '';

// Filter incidents based on criteria
$filtered_incidents = $incidents;

if (!empty($status_filter) && $status_filter !== 'all') {
    $filtered_incidents = array_filter($filtered_incidents, function($incident) use ($status_filter) {
        return $incident['Status'] === $status_filter;
    });
}

if (!empty($emergency_level_filter) && $emergency_level_filter !== 'all') {
    $filtered_incidents = array_filter($filtered_incidents, function($incident) use ($emergency_level_filter) {
        return $incident['Emergency Level'] === $emergency_level_filter;
    });
}

if (!empty($incident_type_filter) && $incident_type_filter !== 'all') {
    $filtered_incidents = array_filter($filtered_incidents, function($incident) use ($incident_type_filter) {
        return $incident['Incident Type'] === $incident_type_filter;
    });
}

if (!empty($search_term)) {
    $filtered_incidents = array_filter($filtered_incidents, function($incident) use ($search_term) {
        $searchable_fields = [
            $incident['Location'],
            $incident['Incident Type'],
            $incident['Incident Description'],
            $incident['Caller Name'],
            $incident['Phone']
        ];
        
        foreach ($searchable_fields as $field) {
            if (stripos($field, $search_term) !== false) {
                return true;
            }
        }
        return false;
    });
}

// Get counts for each status
$status_counts = [
    'all' => count($incidents),
    'reported' => 0,
    'dispatched' => 0,
    'in_progress' => 0,
    'resolved' => 0
];

$emergency_level_counts = [
    'low' => 0,
    'medium' => 0,
    'high' => 0,
    'critical' => 0
];

$incident_type_counts = [
    'fire' => 0,
    'medical' => 0,
    'rescue' => 0,
    'hazard' => 0
];

foreach ($incidents as $incident) {
    $status = strtolower($incident['Status']);
    $emergency_level = strtolower($incident['Emergency Level']);
    $incident_type = strtolower($incident['Incident Type']);
    
    if (isset($status_counts[$status])) {
        $status_counts[$status]++;
    }
    
    if (isset($emergency_level_counts[$emergency_level])) {
        $emergency_level_counts[$emergency_level]++;
    }
    
    if (isset($incident_type_counts[$incident_type])) {
        $incident_type_counts[$incident_type]++;
    }
}

$stmt = null;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Receive Data - Fire Incident Reporting</title>
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

        .incidents-container {
            display: flex;
            flex-direction: column;
            gap: 24px;
            padding: 0 40px 40px;
        }
        
        .incidents-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 24px;
        }
        
        .incidents-title {
            font-size: 28px;
            font-weight: 800;
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            margin-bottom: 8px;
        }
        
        .incidents-subtitle {
            color: var(--text-light);
            font-size: 16px;
        }
        
        .filters-container {
            display: flex;
            gap: 16px;
            margin-bottom: 24px;
            flex-wrap: wrap;
            align-items: flex-end;
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: 16px;
            padding: 20px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
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
            color: var(--text-color);
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
            grid-template-columns: repeat(auto-fill, minmax(240px, 1fr));
            gap: 16px;
            margin-bottom: 24px;
        }
        
        .stat-card {
            background: var(--card-bg);
            border: 1px solid var(--border-color);
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
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
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
        
        .stat-card[data-status="reported"]::before {
            background: var(--warning);
        }
        
        .stat-card[data-status="dispatched"]::before {
            background: var(--info);
        }
        
        .stat-card[data-status="in_progress"]::before {
            background: var(--primary-color);
        }
        
        .stat-card[data-status="resolved"]::before {
            background: var(--success);
        }
        
        .stat-card[data-emergency="low"]::before {
            background: var(--success);
        }
        
        .stat-card[data-emergency="medium"]::before {
            background: var(--warning);
        }
        
        .stat-card[data-emergency="high"]::before {
            background: var(--primary-color);
        }
        
        .stat-card[data-emergency="critical"]::before {
            background: var(--danger);
        }
        
        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.1);
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
        
        .stat-card[data-status="reported"] .stat-icon {
            background: rgba(245, 158, 11, 0.1);
            color: var(--warning);
        }
        
        .stat-card[data-status="dispatched"] .stat-icon {
            background: rgba(59, 130, 246, 0.1);
            color: var(--info);
        }
        
        .stat-card[data-status="in_progress"] .stat-icon {
            background: rgba(220, 38, 38, 0.1);
            color: var(--primary-color);
        }
        
        .stat-card[data-status="resolved"] .stat-icon {
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
        
        .incidents-table {
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: 16px;
            overflow: hidden;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
        }
        
        .table-header {
            display: grid;
            grid-template-columns: 80px 1fr 1fr 120px 120px 120px 120px 100px;
            gap: 16px;
            padding: 20px;
            background: rgba(220, 38, 38, 0.02);
            border-bottom: 1px solid var(--border-color);
            font-weight: 600;
            color: var(--text-color);
        }
        
        .table-row {
            display: grid;
            grid-template-columns: 80px 1fr 1fr 120px 120px 120px 120px 100px;
            gap: 16px;
            padding: 20px;
            border-bottom: 1px solid var(--border-color);
            transition: all 0.3s ease;
        }
        
        .table-row:hover {
            background: rgba(220, 38, 38, 0.03);
        }
        
        .table-row:last-child {
            border-bottom: none;
        }
        
        .table-cell {
            display: flex;
            align-items: center;
            color: var(--text-color);
        }
        
        .incident-id {
            font-weight: 700;
            color: var(--primary-color);
        }
        
        .incident-location {
            font-weight: 600;
        }
        
        .incident-description {
            color: var(--text-light);
            font-size: 13px;
            line-height: 1.4;
        }
        
        .status-badge {
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            text-transform: uppercase;
        }
        
        .status-reported {
            background: rgba(245, 158, 11, 0.1);
            color: var(--warning);
        }
        
        .status-dispatched {
            background: rgba(59, 130, 246, 0.1);
            color: var(--info);
        }
        
        .status-in_progress {
            background: rgba(220, 38, 38, 0.1);
            color: var(--primary-color);
        }
        
        .status-resolved {
            background: rgba(16, 185, 129, 0.1);
            color: var(--success);
        }
        
        .emergency-badge {
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            text-transform: uppercase;
        }
        
        .emergency-low {
            background: rgba(16, 185, 129, 0.1);
            color: var(--success);
        }
        
        .emergency-medium {
            background: rgba(245, 158, 11, 0.1);
            color: var(--warning);
        }
        
        .emergency-high {
            background: rgba(220, 38, 38, 0.1);
            color: var(--primary-color);
        }
        
        .emergency-critical {
            background: rgba(220, 38, 38, 0.2);
            color: var(--danger);
            border: 1px solid var(--danger);
        }
        
        .action-button {
            padding: 8px 12px;
            border-radius: 8px;
            font-weight: 500;
            border: none;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            transition: all 0.3s ease;
            font-size: 13px;
        }
        
        .dispatch-button {
            background-color: rgba(16, 185, 129, 0.1);
            color: var(--success);
        }
        
        .dispatch-button:hover {
            background-color: var(--success);
            color: white;
        }
        
        .view-button {
            background-color: rgba(59, 130, 246, 0.1);
            color: var(--info);
        }
        
        .view-button:hover {
            background-color: var(--info);
            color: white;
        }
        
        .no-incidents {
            text-align: center;
            padding: 60px 20px;
            color: var(--text-light);
        }
        
        .no-incidents-icon {
            font-size: 64px;
            margin-bottom: 16px;
            color: var(--text-light);
            opacity: 0.5;
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
            border-radius: 12px;
            padding: 8px;
            min-width: 200px;
            z-index: 1001;
            opacity: 0;
            visibility: hidden;
            transform: translateY(-10px);
            transition: all 0.3s ease;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
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
            background: rgba(220, 38, 38, 0.05);
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

        .notification-dropdown {
            position: absolute;
            top: 100%;
            right: 0;
            margin-top: 8px;
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: 12px;
            width: 320px;
            max-height: 400px;
            overflow-y: auto;
            z-index: 1001;
            opacity: 0;
            visibility: hidden;
            transform: translateY(-10px);
            transition: all 0.3s ease;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
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

        .filter-tabs {
            display: flex;
            gap: 8px;
            margin-bottom: 20px;
            border-bottom: 1px solid var(--border-color);
            padding-bottom: 16px;
            flex-wrap: wrap;
        }

        .filter-tab {
            padding: 10px 20px;
            border-radius: 8px;
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            color: var(--text-color);
            cursor: pointer;
            transition: all 0.3s ease;
            font-weight: 500;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .filter-tab.active {
            background: var(--primary-color);
            color: white;
            border-color: var(--primary-color);
        }

        .filter-tab:hover:not(.active) {
            background: var(--gray-100);
        }

        .dark-mode .filter-tab:hover:not(.active) {
            background: var(--gray-800);
        }

        .filter-tab-count {
            background: rgba(255, 255, 255, 0.2);
            padding: 2px 8px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: 600;
        }

        .filter-tab.active .filter-tab-count {
            background: rgba(255, 255, 255, 0.3);
        }

        .new-incident-alert {
            animation: pulse 2s infinite;
            border: 2px solid var(--danger) !important;
        }

        @keyframes pulse {
            0% {
                box-shadow: 0 0 0 0 rgba(220, 38, 38, 0.4);
            }
            70% {
                box-shadow: 0 0 0 10px rgba(220, 38, 38, 0);
            }
            100% {
                box-shadow: 0 0 0 0 rgba(220, 38, 38, 0);
            }
        }

        .sound-toggle {
            position: fixed;
            bottom: 20px;
            right: 20px;
            background: var(--primary-color);
            color: white;
            border: none;
            border-radius: 50%;
            width: 50px;
            height: 50px;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
            z-index: 1000;
            transition: all 0.3s ease;
        }

        .sound-toggle:hover {
            transform: scale(1.1);
            background: var(--primary-dark);
        }

        .sound-toggle.muted {
            background: var(--gray-500);
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
            max-width: 800px;
            max-height: 90vh;
            overflow-y: auto;
            transform: scale(0.9);
            transition: all 0.3s ease;
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.15);
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
            margin-bottom: 20px;
        }
        
        .modal-section-title {
            font-size: 18px;
            font-weight: 700;
            margin-bottom: 12px;
            padding-bottom: 8px;
            border-bottom: 1px solid var(--border-color);
            color: var(--primary-color);
        }
        
        .modal-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
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
            color: var(--text-color);
            font-weight: 500;
        }

        .incident-table-container {
            max-height: 500px;
            overflow-y: auto;
        }

        @media (max-width: 1200px) {
            .table-header, .table-row {
                grid-template-columns: 60px 1fr 1fr 100px 100px 100px 100px 80px;
                gap: 12px;
                padding: 15px;
            }
        }

        @media (max-width: 768px) {
            .table-header, .table-row {
                grid-template-columns: 1fr;
                gap: 8px;
            }
            
            .filters-container {
                flex-direction: column;
            }
            
            .filter-select, .filter-input {
                min-width: 100%;
            }
            
            .dashboard-header {
                padding: 40px 25px 30px;
            }
            
            .dashboard-title {
                font-size: 32px;
            }
            
            .incidents-container {
                padding: 0 25px 30px;
            }
            
            .stats-container {
                grid-template-columns: 1fr;
            }

            .filter-tabs {
                flex-direction: column;
            }

            .modal-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <!-- Sound Toggle Button -->
    <button class="sound-toggle" id="sound-toggle" title="Toggle notification sound">
        <i class='bx bx-bell'></i>
    </button>

    <!-- View Incident Modal -->
    <div class="modal-overlay" id="view-modal">
        <div class="modal">
            <div class="modal-header">
                <h2 class="modal-title">Incident Details</h2>
                <button class="modal-close" id="view-modal-close">&times;</button>
            </div>
            <div class="modal-body" id="view-modal-body">
                <!-- Content will be loaded dynamically -->
            </div>
            <div class="modal-footer">
                <button class="modal-button modal-secondary" id="view-modal-close-btn">Close</button>
                <button class="modal-button modal-primary" id="view-modal-dispatch">Dispatch Unit</button>
            </div>
        </div>
    </div>

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
        <div class="animation-text" id="animation-text">Loading Incident Data...</div>
    </div>
    
    <!-- Notification Container -->
    <div class="notification-container" id="notification-container"></div>
    
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
                    <div id="fire-incident" class="submenu active">
                        <a href="receive_data.php" class="submenu-item active">Receive Data</a>
                        <a href="../incident/manual_reporting.php" class="submenu-item">Manual Reporting</a>
                        <a href="../incident/update_status.php" class="submenu-item">Update Status</a>
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
                        <a href="../dispatch/select_unit.php" class="submenu-item">Select Unit</a>
                        <a href="../dispatch/send_dispatch.php" class="submenu-item">Send Dispatch Info</a>
                        <a href="../dispatch/notify_unit.php" class="submenu-item">Notify Unit</a>
                        <a href="../dispatch/track_status.php" class="submenu-item">Track Status</a>
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
                    <div id="volunteer" class="submenu">
                        <a href="../volunteer/review_data.php" class="submenu-item">Review/Approve Data Management</a>
                        <a href="../volunteer/approve_applications.php" class="submenu-item">Assign Volunteers</a>
                        <a href="../volunteer/view_availability.php" class="submenu-item">View Availability</a>
                        <a href="../volunteer/remove_volunteers.php" class="submenu-item">Remove Volunteers</a>
                        <a href="../volunteer/toggle_volunteer_registration.php" class="submenu-item">Open/Close Registration</a>
                    </div>
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
                        <a href="../schedule/view_shifts.php" class="submenu-item">View Shifts</a>
                        <a href="../schedule/confirm_availability.php" class="submenu-item">Confirm Availability</a>
                        <a href="../schedule/request_change.php" class="submenu-item">Request Change</a>
                        <a href="../schedule/mark_attendance.php" class="submenu-item">Mark Attendance</a>
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
                            <input type="text" placeholder="Search incidents..." class="search-input" id="search-input" value="<?php echo htmlspecialchars($search_term); ?>">
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
                            <div class="notification-badge" id="notification-count">0</div>
                            <div class="notification-dropdown" id="notification-dropdown">
                                <div class="notification-header">
                                    <h3 class="notification-title">Incident Notifications</h3>
                                    <button class="notification-clear">Clear All</button>
                                </div>
                                <div class="notification-list" id="notification-list">
                                    <div class="notification-empty">
                                        <i class='bx bxs-bell-off'></i>
                                        <p>No new incidents</p>
                                    </div>
                                </div>
                            </div>
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
                        <h1 class="dashboard-title">Receive Incident Data</h1>
                        <p class="dashboard-subtitle">Real-time incident reports from FRSM monitoring system</p>
                    </div>
                    <div class="dashboard-actions">
                        <button class="primary-button" id="refresh-button">
                            <i class='bx bx-refresh'></i>
                            Refresh Data
                        </button>
                        <button class="secondary-button" id="export-button">
                            <i class='bx bx-export'></i>
                            Export Report
                        </button>
                    </div>
                </div>
                
                <!-- Incidents Section -->
                <div class="incidents-container">
                    <!-- Filter Tabs -->
                    <div class="filter-tabs">
                        <div class="filter-tab <?php echo $status_filter === 'all' ? 'active' : ''; ?>" data-filter="status" data-value="all">
                            <i class='bx bxs-dashboard'></i>
                            All Incidents
                            <span class="filter-tab-count"><?php echo $status_counts['all']; ?></span>
                        </div>
                        <div class="filter-tab <?php echo $status_filter === 'reported' ? 'active' : ''; ?>" data-filter="status" data-value="reported">
                            <i class='bx bxs-megaphone'></i>
                            Reported
                            <span class="filter-tab-count"><?php echo $status_counts['reported']; ?></span>
                        </div>
                        <div class="filter-tab <?php echo $status_filter === 'dispatched' ? 'active' : ''; ?>" data-filter="status" data-value="dispatched">
                            <i class='bx bxs-truck'></i>
                            Dispatched
                            <span class="filter-tab-count"><?php echo $status_counts['dispatched']; ?></span>
                        </div>
                        <div class="filter-tab <?php echo $status_filter === 'in_progress' ? 'active' : ''; ?>" data-filter="status" data-value="in_progress">
                            <i class='bx bxs-time'></i>
                            In Progress
                            <span class="filter-tab-count"><?php echo $status_counts['in_progress']; ?></span>
                        </div>
                        <div class="filter-tab <?php echo $status_filter === 'resolved' ? 'active' : ''; ?>" data-filter="status" data-value="resolved">
                            <i class='bx bxs-check-circle'></i>
                            Resolved
                            <span class="filter-tab-count"><?php echo $status_counts['resolved']; ?></span>
                        </div>
                    </div>
                    
                    <!-- Stats Cards -->
                    <div class="stats-container">
                        <div class="stat-card <?php echo $status_filter === 'all' ? 'active' : ''; ?>" data-status="all">
                            <div class="stat-icon">
                                <i class='bx bxs-dashboard'></i>
                            </div>
                            <div class="stat-value"><?php echo $status_counts['all']; ?></div>
                            <div class="stat-label">Total Incidents</div>
                        </div>
                        <div class="stat-card <?php echo $status_filter === 'reported' ? 'active' : ''; ?>" data-status="reported">
                            <div class="stat-icon">
                                <i class='bx bxs-megaphone'></i>
                            </div>
                            <div class="stat-value"><?php echo $status_counts['reported']; ?></div>
                            <div class="stat-label">Reported</div>
                        </div>
                        <div class="stat-card <?php echo $status_filter === 'dispatched' ? 'active' : ''; ?>" data-status="dispatched">
                            <div class="stat-icon">
                                <i class='bx bxs-truck'></i>
                            </div>
                            <div class="stat-value"><?php echo $status_counts['dispatched']; ?></div>
                            <div class="stat-label">Dispatched</div>
                        </div>
                        <div class="stat-card <?php echo $status_filter === 'in_progress' ? 'active' : ''; ?>" data-status="in_progress">
                            <div class="stat-icon">
                                <i class='bx bxs-time'></i>
                            </div>
                            <div class="stat-value"><?php echo $status_counts['in_progress']; ?></div>
                            <div class="stat-label">In Progress</div>
                        </div>
                        <div class="stat-card <?php echo $status_filter === 'resolved' ? 'active' : ''; ?>" data-status="resolved">
                            <div class="stat-icon">
                                <i class='bx bxs-check-circle'></i>
                            </div>
                            <div class="stat-value"><?php echo $status_counts['resolved']; ?></div>
                            <div class="stat-label">Resolved</div>
                        </div>
                    </div>
                    
                    <!-- Emergency Level Stats -->
                    <div class="stats-container">
                        <div class="stat-card" data-emergency="low">
                            <div class="stat-icon">
                                <i class='bx bxs-check-circle'></i>
                            </div>
                            <div class="stat-value"><?php echo $emergency_level_counts['low']; ?></div>
                            <div class="stat-label">Low Priority</div>
                        </div>
                        <div class="stat-card" data-emergency="medium">
                            <div class="stat-icon">
                                <i class='bx bxs-info-circle'></i>
                            </div>
                            <div class="stat-value"><?php echo $emergency_level_counts['medium']; ?></div>
                            <div class="stat-label">Medium Priority</div>
                        </div>
                        <div class="stat-card" data-emergency="high">
                            <div class="stat-icon">
                                <i class='bx bxs-error'></i>
                            </div>
                            <div class="stat-value"><?php echo $emergency_level_counts['high']; ?></div>
                            <div class="stat-label">High Priority</div>
                        </div>
                        <div class="stat-card" data-emergency="critical">
                            <div class="stat-icon">
                                <i class='bx bxs-alarm'></i>
                            </div>
                            <div class="stat-value"><?php echo $emergency_level_counts['critical']; ?></div>
                            <div class="stat-label">Critical</div>
                        </div>
                    </div>
                    
                    <!-- Filters -->
                    <div class="filters-container">
                        <div class="filter-group">
                            <label class="filter-label">Status</label>
                            <select class="filter-select" id="status-filter">
                                <option value="all" <?php echo $status_filter === 'all' ? 'selected' : ''; ?>>All Statuses</option>
                                <option value="reported" <?php echo $status_filter === 'reported' ? 'selected' : ''; ?>>Reported</option>
                                <option value="dispatched" <?php echo $status_filter === 'dispatched' ? 'selected' : ''; ?>>Dispatched</option>
                                <option value="in_progress" <?php echo $status_filter === 'in_progress' ? 'selected' : ''; ?>>In Progress</option>
                                <option value="resolved" <?php echo $status_filter === 'resolved' ? 'selected' : ''; ?>>Resolved</option>
                            </select>
                        </div>
                        <div class="filter-group">
                            <label class="filter-label">Emergency Level</label>
                            <select class="filter-select" id="emergency-level-filter">
                                <option value="all" <?php echo $emergency_level_filter === 'all' ? 'selected' : ''; ?>>All Levels</option>
                                <option value="low" <?php echo $emergency_level_filter === 'low' ? 'selected' : ''; ?>>Low</option>
                                <option value="medium" <?php echo $emergency_level_filter === 'medium' ? 'selected' : ''; ?>>Medium</option>
                                <option value="high" <?php echo $emergency_level_filter === 'high' ? 'selected' : ''; ?>>High</option>
                                <option value="critical" <?php echo $emergency_level_filter === 'critical' ? 'selected' : ''; ?>>Critical</option>
                            </select>
                        </div>
                        <div class="filter-group">
                            <label class="filter-label">Incident Type</label>
                            <select class="filter-select" id="incident-type-filter">
                                <option value="all" <?php echo $incident_type_filter === 'all' ? 'selected' : ''; ?>>All Types</option>
                                <option value="fire" <?php echo $incident_type_filter === 'fire' ? 'selected' : ''; ?>>Fire</option>
                                <option value="medical" <?php echo $incident_type_filter === 'medical' ? 'selected' : ''; ?>>Medical</option>
                                <option value="rescue" <?php echo $incident_type_filter === 'rescue' ? 'selected' : ''; ?>>Rescue</option>
                                <option value="hazard" <?php echo $incident_type_filter === 'hazard' ? 'selected' : ''; ?>>Hazard</option>
                            </select>
                        </div>
                        <div class="filter-group">
                            <label class="filter-label">Search</label>
                            <input type="text" class="filter-input" id="search-filter" placeholder="Search by location, type, or description..." value="<?php echo htmlspecialchars($search_term); ?>">
                        </div>
                        <div class="filter-group" style="align-self: flex-end;">
                            <button class="action-button view-button" id="apply-filters">
                                <i class='bx bx-filter-alt'></i>
                                Apply Filters
                            </button>
                        </div>
                        <div class="filter-group" style="align-self: flex-end;">
                            <button class="action-button update-button" id="reset-filters">
                                <i class='bx bx-reset'></i>
                                Reset
                            </button>
                        </div>
                    </div>
                    
                    <!-- Incidents Table -->
                    <div class="incidents-table" id="incidents-table">
                        <div class="table-header">
                            <div>ID</div>
                            <div>Location</div>
                            <div>Description</div>
                            <div>Type</div>
                            <div>Emergency Level</div>
                            <div>Status</div>
                            <div>Reported</div>
                            <div>Actions</div>
                        </div>
                        <div class="incident-table-container" id="incident-table-container">
                            <?php if (count($filtered_incidents) > 0): ?>
                                <?php foreach ($filtered_incidents as $incident): ?>
                                    <div class="table-row" data-id="<?php echo $incident['ID']; ?>">
                                        <div class="table-cell">
                                            <div class="incident-id">#<?php echo $incident['ID']; ?></div>
                                        </div>
                                        <div class="table-cell">
                                            <div class="incident-location"><?php echo htmlspecialchars($incident['Location']); ?></div>
                                        </div>
                                        <div class="table-cell">
                                            <div class="incident-description"><?php echo htmlspecialchars($incident['Incident Description']); ?></div>
                                        </div>
                                        <div class="table-cell">
                                            <?php echo htmlspecialchars($incident['Incident Type']); ?>
                                        </div>
                                        <div class="table-cell">
                                            <div class="emergency-badge emergency-<?php echo strtolower($incident['Emergency Level']); ?>">
                                                <?php echo ucfirst($incident['Emergency Level']); ?>
                                            </div>
                                        </div>
                                        <div class="table-cell">
                                            <div class="status-badge status-<?php echo strtolower($incident['Status']); ?>">
                                                <?php echo ucfirst(str_replace('_', ' ', $incident['Status'])); ?>
                                            </div>
                                        </div>
                                        <div class="table-cell">
                                            <?php 
                                            $date = new DateTime($incident['Date Reported']);
                                            echo $date->format('M j, Y g:i A');
                                            ?>
                                        </div>
                                        <div class="table-cell">
                                            <div class="table-actions">
                                                <button class="action-button view-button" onclick="viewIncident(<?php echo $incident['ID']; ?>)">
                                                    <i class='bx bx-show'></i>
                                                    View
                                                </button>
                                                <?php if (strtolower($incident['Status']) === 'reported'): ?>
                                                    <button class="action-button dispatch-button" onclick="dispatchIncident(<?php echo $incident['ID']; ?>)">
                                                        <i class='bx bxs-truck'></i>
                                                        Dispatch
                                                    </button>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <div class="no-incidents">
                                    <div class="no-incidents-icon">
                                        <i class='bx bxs-alarm-off'></i>
                                    </div>
                                    <h3>No Incidents Found</h3>
                                    <p>No incidents match your current filters or no data is available from the API.</p>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <script>
        // Global variables
        let lastIncidentId = <?php echo $last_incident_id; ?>;
        let soundEnabled = true;
        let checkInterval;
        let currentIncidentId = null;
        
        // Notification sound - using a simple beep sound
        const notificationSound = new Audio('data:audio/wav;base64,UklGRigAAABXQVZFZm10IBAAAAABAAEARKwAAIhYAQACABAAZGF0YQQAAAAAAA==');
        
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
            
            // Start checking for new incidents
            startIncidentMonitoring();
            
            // Show welcome notification
            showNotification('success', 'System Ready', 'Incident monitoring system is now active');
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
            });
            
            // Clear all notifications
            document.querySelector('.notification-clear').addEventListener('click', function(e) {
                e.stopPropagation();
                document.getElementById('notification-list').innerHTML = `
                    <div class="notification-empty">
                        <i class='bx bxs-bell-off'></i>
                        <p>No new incidents</p>
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
                    if (status) {
                        document.getElementById('status-filter').value = status;
                        applyFilters();
                    }
                });
            });
            
            // Filter tabs
            document.querySelectorAll('.filter-tab').forEach(tab => {
                tab.addEventListener('click', function() {
                    const filterType = this.getAttribute('data-filter');
                    const filterValue = this.getAttribute('data-value');
                    
                    if (filterType === 'status') {
                        document.getElementById('status-filter').value = filterValue;
                    }
                    
                    applyFilters();
                });
            });
            
            // Export and refresh buttons
            document.getElementById('export-button').addEventListener('click', exportReport);
            document.getElementById('refresh-button').addEventListener('click', refreshData);
            
            // Sound toggle
            document.getElementById('sound-toggle').addEventListener('click', toggleSound);
            
            // View modal functionality
            document.getElementById('view-modal-close').addEventListener('click', closeViewModal);
            document.getElementById('view-modal-close-btn').addEventListener('click', closeViewModal);
            document.getElementById('view-modal-dispatch').addEventListener('click', function() {
                if (currentIncidentId) {
                    dispatchIncident(currentIncidentId);
                }
            });
            
            // Keyboard shortcuts
            document.addEventListener('keydown', function(e) {
                // Search shortcut - forward slash
                if (e.key === '/' && !e.ctrlKey && !e.altKey && !e.metaKey) {
                    e.preventDefault();
                    document.getElementById('search-input').focus();
                }
                
                // Escape key to close dropdowns and modals
                if (e.key === 'Escape') {
                    userDropdown.classList.remove('show');
                    notificationDropdown.classList.remove('show');
                    closeViewModal();
                }
            });
        }
        
        function startIncidentMonitoring() {
            // Check for new incidents every 10 seconds
            checkInterval = setInterval(checkForNewIncidents, 10000);
        }
        
        function checkForNewIncidents() {
            fetch(`https://frsm.qcprotektado.com/incidents.php?last_id=${lastIncidentId}`)
                .then(response => response.json())
                .then(data => {
                    if (data && data.success && data.data && data.data.length > 0) {
                        const newIncidents = data.data;
                        
                        // Update last incident ID
                        lastIncidentId = newIncidents[0].ID;
                        
                        // Show notifications for new incidents
                        newIncidents.forEach(incident => {
                            showNewIncidentNotification(incident);
                        });
                        
                        // Update the table with new incidents
                        updateIncidentsTable(newIncidents);
                    }
                })
                .catch(error => {
                    console.error('Error checking for new incidents:', error);
                });
        }
        
        function updateIncidentsTable(newIncidents) {
            const tableContainer = document.getElementById('incident-table-container');
            
            newIncidents.forEach(incident => {
                // Create new table row
                const newRow = document.createElement('div');
                newRow.className = 'table-row new-incident-alert';
                newRow.setAttribute('data-id', incident.ID);
                
                // Format date
                const date = new Date(incident['Date Reported']);
                const formattedDate = date.toLocaleDateString('en-US', { 
                    month: 'short', 
                    day: 'numeric', 
                    year: 'numeric',
                    hour: 'numeric',
                    minute: '2-digit',
                    hour12: true 
                });
                
                newRow.innerHTML = `
                    <div class="table-cell">
                        <div class="incident-id">#${incident.ID}</div>
                    </div>
                    <div class="table-cell">
                        <div class="incident-location">${escapeHtml(incident.Location)}</div>
                    </div>
                    <div class="table-cell">
                        <div class="incident-description">${escapeHtml(incident['Incident Description'])}</div>
                    </div>
                    <div class="table-cell">
                        ${escapeHtml(incident['Incident Type'])}
                    </div>
                    <div class="table-cell">
                        <div class="emergency-badge emergency-${incident['Emergency Level'].toLowerCase()}">
                            ${incident['Emergency Level'].charAt(0).toUpperCase() + incident['Emergency Level'].slice(1)}
                        </div>
                    </div>
                    <div class="table-cell">
                        <div class="status-badge status-${incident.Status.toLowerCase()}">
                            ${incident.Status.charAt(0).toUpperCase() + incident.Status.slice(1).replace('_', ' ')}
                        </div>
                    </div>
                    <div class="table-cell">
                        ${formattedDate}
                    </div>
                    <div class="table-cell">
                        <div class="table-actions">
                            <button class="action-button view-button" onclick="viewIncident(${incident.ID})">
                                <i class='bx bx-show'></i>
                                View
                            </button>
                            ${incident.Status.toLowerCase() === 'reported' ? `
                            <button class="action-button dispatch-button" onclick="dispatchIncident(${incident.ID})">
                                <i class='bx bxs-truck'></i>
                                Dispatch
                            </button>
                            ` : ''}
                        </div>
                    </div>
                `;
                
                // Insert at the top of the table
                tableContainer.insertBefore(newRow, tableContainer.firstChild);
                
                // Remove the alert animation after 5 seconds
                setTimeout(() => {
                    newRow.classList.remove('new-incident-alert');
                }, 5000);
            });
        }
        
        function showNewIncidentNotification(incident) {
            // Play sound if enabled
            if (soundEnabled) {
                try {
                    notificationSound.play().catch(e => console.log('Audio play failed:', e));
                } catch (e) {
                    console.log('Audio error:', e);
                }
            }
            
            // Update notification badge
            const notificationCount = document.getElementById('notification-count');
            let currentCount = parseInt(notificationCount.textContent) || 0;
            notificationCount.textContent = currentCount + 1;
            
            // Add to notification dropdown
            const notificationList = document.getElementById('notification-list');
            const notificationEmpty = notificationList.querySelector('.notification-empty');
            
            if (notificationEmpty) {
                notificationList.innerHTML = '';
            }
            
            const notificationItem = document.createElement('div');
            notificationItem.className = 'notification-item unread';
            notificationItem.innerHTML = `
                <i class='bx bxs-alarm notification-item-icon' style="color: var(--danger);"></i>
                <div class="notification-item-content">
                    <div class="notification-item-title">New ${incident['Incident Type']} Incident</div>
                    <div class="notification-item-message">${incident.Location} - ${incident['Incident Description']}</div>
                    <div class="notification-item-time">Just now</div>
                </div>
            `;
            
            notificationList.insertBefore(notificationItem, notificationList.firstChild);
            
            // Show desktop notification
            if (Notification.permission === 'granted') {
                new Notification(`New ${incident['Incident Type']} Incident`, {
                    body: `${incident.Location} - ${incident['Incident Description']}`,
                    icon: '../../img/frsm-logo.png'
                });
            }
            
            // Show in-page notification
            showNotification('warning', `New ${incident['Incident Type']} Incident`, 
                           `${incident.Location} - ${incident['Incident Description']}`);
        }
        
        function toggleSound() {
            const soundToggle = document.getElementById('sound-toggle');
            const soundIcon = soundToggle.querySelector('i');
            
            soundEnabled = !soundEnabled;
            
            if (soundEnabled) {
                soundToggle.classList.remove('muted');
                soundIcon.className = 'bx bx-bell';
                showNotification('success', 'Sound Enabled', 'Notification sounds are now enabled');
            } else {
                soundToggle.classList.add('muted');
                soundIcon.className = 'bx bx-bell-off';
                showNotification('info', 'Sound Disabled', 'Notification sounds are now disabled');
            }
        }
        
        function applyFilters() {
            const status = document.getElementById('status-filter').value;
            const emergencyLevel = document.getElementById('emergency-level-filter').value;
            const incidentType = document.getElementById('incident-type-filter').value;
            const search = document.getElementById('search-filter').value;
            
            let url = 'receive_data.php?';
            if (status !== 'all') {
                url += `status=${status}&`;
            }
            if (emergencyLevel !== 'all') {
                url += `emergency_level=${emergencyLevel}&`;
            }
            if (incidentType !== 'all') {
                url += `incident_type=${incidentType}&`;
            }
            if (search) {
                url += `search=${encodeURIComponent(search)}&`;
            }
            
            window.location.href = url;
        }
        
        function resetFilters() {
            document.getElementById('status-filter').value = 'all';
            document.getElementById('emergency-level-filter').value = 'all';
            document.getElementById('incident-type-filter').value = 'all';
            document.getElementById('search-filter').value = '';
            applyFilters();
        }
        
        function viewIncident(id) {
            currentIncidentId = id;
            
            // Show loading state
            document.getElementById('view-modal-body').innerHTML = `
                <div style="text-align: center; padding: 40px;">
                    <i class='bx bx-loader-circle bx-spin' style="font-size: 40px; color: var(--primary-color);"></i>
                    <p style="margin-top: 16px;">Loading incident details...</p>
                </div>
            `;
            
            document.getElementById('view-modal').classList.add('active');
            
            // Fetch incident details from API
            fetch(`https://frsm.qcprotektado.com/incidents.php`)
                .then(response => response.json())
                .then(data => {
                    if (data && data.success && data.data) {
                        const incident = data.data.find(inc => inc.ID == id);
                        if (incident) {
                            displayIncidentDetails(incident);
                        } else {
                            document.getElementById('view-modal-body').innerHTML = `
                                <div style="text-align: center; padding: 40px;">
                                    <i class='bx bxs-error' style="font-size: 40px; color: var(--danger);"></i>
                                    <p style="margin-top: 16px;">Incident not found</p>
                                </div>
                            `;
                        }
                    }
                })
                .catch(error => {
                    document.getElementById('view-modal-body').innerHTML = `
                        <div style="text-align: center; padding: 40px;">
                            <i class='bx bxs-error' style="font-size: 40px; color: var(--danger);"></i>
                            <p style="margin-top: 16px;">Error loading incident details</p>
                        </div>
                    `;
                });
        }
        
        function displayIncidentDetails(incident) {
            const modalBody = document.getElementById('view-modal-body');
            
            // Format dates
            const reportedDate = new Date(incident['Date Reported']);
            const resolvedDate = incident['Date Resolved'] ? new Date(incident['Date Resolved']) : null;
            
            modalBody.innerHTML = `
                <div class="modal-section">
                    <h3 class="modal-section-title">Incident Information</h3>
                    <div class="modal-grid">
                        <div class="modal-detail">
                            <div class="modal-detail-label">Incident ID</div>
                            <div class="modal-detail-value">#${incident.ID}</div>
                        </div>
                        <div class="modal-detail">
                            <div class="modal-detail-label">Incident Type</div>
                            <div class="modal-detail-value">${escapeHtml(incident['Incident Type'])}</div>
                        </div>
                        <div class="modal-detail">
                            <div class="modal-detail-label">Emergency Level</div>
                            <div class="emergency-badge emergency-${incident['Emergency Level'].toLowerCase()}">
                                ${incident['Emergency Level'].charAt(0).toUpperCase() + incident['Emergency Level'].slice(1)}
                            </div>
                        </div>
                        <div class="modal-detail">
                            <div class="modal-detail-label">Status</div>
                            <div class="status-badge status-${incident.Status.toLowerCase()}">
                                ${incident.Status.charAt(0).toUpperCase() + incident.Status.slice(1).replace('_', ' ')}
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="modal-section">
                    <h3 class="modal-section-title">Location Details</h3>
                    <div class="modal-grid">
                        <div class="modal-detail">
                            <div class="modal-detail-label">Location</div>
                            <div class="modal-detail-value">${escapeHtml(incident.Location)}</div>
                        </div>
                        <div class="modal-detail">
                            <div class="modal-detail-label">Incident Description</div>
                            <div class="modal-detail-value">${escapeHtml(incident['Incident Description'])}</div>
                        </div>
                    </div>
                </div>
                
                <div class="modal-section">
                    <h3 class="modal-section-title">Caller Information</h3>
                    <div class="modal-grid">
                        <div class="modal-detail">
                            <div class="modal-detail-label">Caller Name</div>
                            <div class="modal-detail-value">${escapeHtml(incident['Caller Name'])}</div>
                        </div>
                        <div class="modal-detail">
                            <div class="modal-detail-label">Phone Number</div>
                            <div class="modal-detail-value">${escapeHtml(incident.Phone)}</div>
                        </div>
                    </div>
                </div>
                
                <div class="modal-section">
                    <h3 class="modal-section-title">Timeline</h3>
                    <div class="modal-grid">
                        <div class="modal-detail">
                            <div class="modal-detail-label">Date Reported</div>
                            <div class="modal-detail-value">${reportedDate.toLocaleString()}</div>
                        </div>
                        ${resolvedDate ? `
                        <div class="modal-detail">
                            <div class="modal-detail-label">Date Resolved</div>
                            <div class="modal-detail-value">${resolvedDate.toLocaleString()}</div>
                        </div>
                        ` : ''}
                    </div>
                </div>
                
                ${incident['Responding Units'] ? `
                <div class="modal-section">
                    <h3 class="modal-section-title">Response Details</h3>
                    <div class="modal-grid">
                        <div class="modal-detail">
                            <div class="modal-detail-label">Responding Units</div>
                            <div class="modal-detail-value">${escapeHtml(incident['Responding Units'])}</div>
                        </div>
                    </div>
                </div>
                ` : ''}
                
                ${incident['Additional Notes'] ? `
                <div class="modal-section">
                    <h3 class="modal-section-title">Additional Notes</h3>
                    <div class="modal-detail">
                        <div class="modal-detail-value">${escapeHtml(incident['Additional Notes'])}</div>
                    </div>
                </div>
                ` : ''}
            `;
        }
        
        function closeViewModal() {
            document.getElementById('view-modal').classList.remove('active');
            currentIncidentId = null;
        }
        
        function dispatchIncident(id) {
            showNotification('info', 'Dispatching', 'Opening dispatch interface...');
            window.location.href = `../dispatch/select_unit.php?incident=${id}`;
        }
        
        function exportReport() {
            showNotification('info', 'Export Started', 'Your incident report is being generated and will download shortly');
        }
        
        function refreshData() {
            showNotification('info', 'Refreshing Data', 'Fetching the latest incident data');
            location.reload();
        }
        
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
        
        function escapeHtml(unsafe) {
            if (!unsafe) return '';
            return unsafe
                .replace(/&/g, "&amp;")
                .replace(/</g, "&lt;")
                .replace(/>/g, "&gt;")
                .replace(/"/g, "&quot;")
                .replace(/'/g, "&#039;");
        }
        
        // Request notification permission
        if ('Notification' in window && Notification.permission === 'default') {
            Notification.requestPermission();
        }
        
        updateTime();
        setInterval(updateTime, 1000);
    </script>
</body>
</html>