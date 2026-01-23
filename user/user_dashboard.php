<?php
session_start();
require_once '../config/db_connection.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
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

$stmt = null;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Fire & Rescue Services Management</title>
    <link href='https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css' rel='stylesheet'>
    <link rel="icon" type="image/png" sizes="32x32" href="../img/frsm-logo.png">
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap');

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        :root {
            --primary-color: #dc2626;
            --primary-dark: #b91c1c;
            --secondary-color: #ef4444;
            --secondary-dark: #dc2626;
            --background-color: #ffffff;
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
        }

        .dark-mode {
            --background-color: #0f172a;
            --text-color: #f1f5f9;
            --text-light: #94a3b8;
            --border-color: #1e293b;
            --card-bg: #1e293b;
            --sidebar-bg: #0f172a;
        }

        body {
            font-family: 'Inter', sans-serif;
            background-color: #f8fafc;
            color: var(--text-color);
            transition: background-color 0.3s, color 0.3s;
        }

        .dark-mode body {
            background-color: #0f172a;
        }

        .container {
            display: flex;
            height: 100vh;
        }

        .sidebar {
            width: 256px;
            background-color: var(--sidebar-bg);
            padding: 24px;
            display: flex;
            flex-direction: column;
            overflow-y: auto;
            transition: background-color 0.3s;
            border-right: 1px solid var(--border-color);
            box-shadow: 2px 0 8px rgba(0, 0, 0, 0.05);
        }

        .logo {
            display: flex;
            align-items: center;
            gap: 8px;
            margin-bottom: 40px;
        }

        .logo-icon {
            width: 40px;
            height: 40px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .logo-text {
            font-size: 20px;
            font-weight: 600;
        }

        .menu-section {
            flex: 1;
        }

        .menu-title {
            font-size: 12px;
            color: var(--text-light);
            text-transform: uppercase;
            letter-spacing: 0.025em;
            margin-bottom: 16px;
        }

        .menu-items {
            display: flex;
            flex-direction: column;
            gap: 4px;
        }

        .menu-item {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 12px 16px;
            color: var(--text-color);
            text-decoration: none;
            border-radius: 8px;
            transition: all 0.3s ease;
            cursor: pointer;
            background: transparent;
            border: 1px solid transparent;
        }

        .menu-item:hover {
            background-color: rgba(0, 0, 0, 0.05);
        }

        .dark-mode .menu-item:hover {
            background-color: rgba(255, 255, 255, 0.05);
        }

        .menu-item.active {
            background-color: var(--icon-bg-red);
            color: var(--primary-color);
            border-left: 4px solid var(--primary-color);
        }

        .menu-item-badge {
            margin-left: auto;
            background-color: var(--primary-color);
            color: white;
            font-size: 12px;
            padding: 2px 8px;
            border-radius: 4px;
        }

        .menu-icon {
            width: 24px;
            height: 24px;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .icon-box {
            width: 40px;
            height: 40px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
            background: transparent;
            border: 1px solid transparent;
        }

        .icon-red { color: var(--icon-red); }
        .icon-blue { color: var(--icon-blue); }
        .icon-green { color: var(--icon-green); }
        .icon-purple { color: var(--icon-purple); }
        .icon-yellow { color: var(--icon-yellow); }
        .icon-indigo { color: var(--icon-indigo); }
        .icon-cyan { color: var(--icon-cyan); }
        .icon-orange { color: var(--icon-orange); }
        .icon-pink { color: var(--icon-pink); }
        .icon-teal { color: var(--icon-teal); }

        .icon-bg-red { background-color: var(--icon-bg-red); }
        .icon-bg-blue { background-color: var(--icon-bg-blue); }
        .icon-bg-green { background-color: var(--icon-bg-green); }
        .icon-bg-purple { background-color: var(--icon-bg-purple); }
        .icon-bg-yellow { background-color: var(--icon-bg-yellow); }
        .icon-bg-indigo { background-color: var(--icon-bg-indigo); }
        .icon-bg-cyan { background-color: var(--icon-bg-cyan); }
        .icon-bg-orange { background-color: var(--icon-bg-orange); }
        .icon-bg-pink { background-color: var(--icon-bg-pink); }
        .icon-bg-teal { background-color: var(--icon-bg-teal); }

        .submenu {
            display: none;
            margin-left: 20px;
            margin-top: 8px;
            padding-left: 12px;
            border-left: 2px solid var(--border-color);
        }

        .submenu.active {
            display: block;
        }

        .submenu-item {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 8px 12px;
            color: var(--text-color);
            text-decoration: none;
            border-radius: 6px;
            transition: all 0.3s ease;
            font-size: 14px;
            background: transparent;
            border: 1px solid transparent;
        }

        .submenu-item:hover {
            background-color: rgba(0, 0, 0, 0.05);
            transform: translateX(4px);
        }

        .dark-mode .submenu-item:hover {
            background-color: rgba(255, 255, 255, 0.05);
        }

        .submenu-item.active {
            background-color: var(--icon-bg-red);
            color: var(--primary-color);
        }

        .dropdown-arrow {
            margin-left: auto;
            transition: transform 0.3s;
        }

        .dropdown-arrow.rotated {
            transform: rotate(180deg);
        }

        .main-content {
            flex: 1;
            overflow: auto;
            padding: 10px;
        }

        .header {
            background-color: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: 16px;
            padding: 16px 32px;
            margin-bottom: 10px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
        }

        .header-content {
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .search-container {
            display: flex;
            align-items: center;
            gap: 16px;
            flex: 1;
            max-width: 384px;
        }

        .search-box {
            position: relative;
            flex: 1;
        }

        .search-icon {
            position: absolute;
            left: 12px;
            top: 50%;
            transform: translateY(-50%);
            width: 20px;
            height: 20px;
            color: var(--text-light);
        }

        .search-input {
            width: 100%;
            padding: 8px 40px 8px 40px;
            border: 1px solid var(--border-color);
            border-radius: 8px;
            outline: none;
            background-color: var(--background-color);
            color: var(--text-color);
            transition: all 0.3s ease;
        }

        .search-input:focus {
            border-color: var(--primary-color);
            box-shadow: 0 0 0 2px rgba(220, 38, 38, 0.2);
        }

        .search-shortcut {
            position: absolute;
            right: 12px;
            top: 50%;
            transform: translateY(-50%);
            padding: 2px 8px;
            background-color: var(--card-bg);
            color: var(--text-light);
            font-size: 12px;
            border-radius: 4px;
            border: 1px solid var(--border-color);
        }

        .header-actions {
            display: flex;
            align-items: center;
            gap: 16px;
        }

        .header-button {
            padding: 8px;
            background: var(--background-color);
            border: 1px solid var(--border-color);
            border-radius: 8px;
            cursor: pointer;
            transition: all 0.3s ease;
            color: var(--text-color);
        }

        .header-button:hover {
            background-color: rgba(0, 0, 0, 0.05);
        }

        .dark-mode .header-button:hover {
            background-color: rgba(255, 255, 255, 0.05);
        }

        .header-button-icon {
            width: 24px;
            height: 24px;
            color: var(--text-color);
        }

        .theme-toggle {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 8px 12px;
            background: var(--background-color);
            border: 1px solid var(--border-color);
            border-radius: 8px;
            cursor: pointer;
            color: var(--text-color);
            transition: all 0.3s ease;
        }

        .theme-toggle:hover {
            background-color: rgba(0, 0, 0, 0.05);
        }

        .dark-mode .theme-toggle:hover {
            background-color: rgba(255, 255, 255, 0.05);
        }

        .time-display {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 8px 12px;
            background: var(--background-color);
            border: 1px solid var(--border-color);
            border-radius: 8px;
            color: var(--text-color);
            font-size: 14px;
            font-weight: 500;
            min-width: 160px;
            justify-content: center;
        }

        .time-icon {
            width: 16px;
            height: 16px;
        }

        .user-profile {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .user-avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            border: 2px solid var(--border-color);
        }

        .user-info {
            display: flex;
            flex-direction: column;
        }

        .user-name {
            font-size: 14px;
            font-weight: 600;
        }

        .user-email {
            font-size: 12px;
            color: var(--text-light);
        }

        .dashboard-content {
            padding: 32px;
        }

        .dashboard-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 24px;
        }

        .dashboard-title {
            font-size: 30px;
            font-weight: 700;
            margin-bottom: 8px;
        }

        .dashboard-subtitle {
            color: var(--text-light);
        }

        .dashboard-actions {
            display: flex;
            gap: 12px;
        }

        .primary-button {
            background-color: var(--secondary-color);
            color: white;
            padding: 12px 24px;
            border-radius: 8px;
            font-weight: 500;
            border: none;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 8px;
            transition: all 0.3s ease;
        }

        .primary-button:hover {
            background-color: var(--secondary-dark);
            transform: translateY(-2px);
        }

        .secondary-button {
            background-color: var(--card-bg);
            color: var(--text-color);
            padding: 12px 24px;
            border-radius: 8px;
            font-weight: 500;
            border: 1px solid var(--border-color);
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .secondary-button:hover {
            background-color: rgba(0, 0, 0, 0.05);
        }

        .dark-mode .secondary-button:hover {
            background-color: rgba(255, 255, 255, 0.05);
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 24px;
            margin-bottom: 32px;
        }

        .stat-card {
            border-radius: 16px;
            padding: 24px;
            position: relative;
            overflow: hidden;
            transition: all 0.3s ease;
            background: var(--card-bg);
            border: 1px solid var(--border-color);
        }

        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.1);
        }

        .dark-mode .stat-card:hover {
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.3);
        }

        .stat-card-primary {
            background: var(--secondary-color);
            color: white;
            border: 1px solid #dc2626;
        }

        .stat-card-white {
            background: var(--card-bg);
            border: 1px solid var(--border-color);
        }

        .stat-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 16px;
        }

        .stat-title {
            font-size: 14px;
            font-weight: 500;
        }

        .stat-button {
            width: 32px;
            height: 32px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            border: 1px solid var(--border-color);
            cursor: pointer;
            transition: all 0.3s ease;
            background: var(--background-color);
        }

        .stat-button-primary {
            background-color: rgba(255, 255, 255, 0.2);
        }

        .stat-button-primary:hover {
            background-color: rgba(255, 255, 255, 0.3);
        }

        .stat-button-white {
            background-color: var(--background-color);
        }

        .stat-button-white:hover {
            background-color: rgba(0, 0, 0, 0.05);
        }

        .dark-mode .stat-button-white:hover {
            background-color: rgba(255, 255, 255, 0.05);
        }

        .stat-value {
            font-size: 48px;
            font-weight: 700;
            margin-bottom: 8px;
        }

        .stat-info {
            display: flex;
            align-items: center;
            gap: 4px;
            font-size: 14px;
        }

        .stat-icon {
            width: 16px;
            height: 16px;
        }

        .main-grid {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 24px;
        }
        
        .left-column {
            display: flex;
            flex-direction: column;
            gap: 24px;
        }
        
        .right-column {
            display: flex;
            flex-direction: column;
            gap: 24px;
        }
        
        .card {
            background-color: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: 16px;
            padding: 24px;
            transition: all 0.3s ease;
        }
        
        .card:hover {
            transform: translateY(-3px);
            box-shadow: 0 8px 20px rgba(0, 0, 0, 0.1);
        }
        
        .card-title {
            font-size: 20px;
            font-weight: 600;
            margin-bottom: 24px;
        }
        
        .response-chart {
            display: flex;
            align-items: flex-end;
            justify-content: space-around;
            height: 256px;
        }
        
        .chart-bar {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 8px;
        }
        
        .chart-bar-value {
            width: 64px;
            border-radius: 8px 8px 0 0;
            transition: height 0.5s ease;
            background: #e5e7eb;
        }
        
        .chart-bar-label {
            font-size: 14px;
            color: var(--text-light);
        }
        
        .bar-red {
            background-color: #ef4444;
        }
        
        .bar-orange {
            background-color: #f97316;
        }
        
        .bar-yellow {
            background-color: #f59e0b;
        }
        
        .bar-green {
            background-color: #10b981;
        }
        
        .bar-blue {
            background-color: #3b82f6;
        }
        
        .bar-purple {
            background-color: #8b5cf6;
        }
        
        .bar-pink {
            background-color: #ec4899;
        }
        
        .two-column-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 24px;
        }
        
        .quick-actions {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 16px;
        }
        
        .action-button {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 20px;
            background-color: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: 12px;
            cursor: pointer;
            transition: all 0.3s ease;
        }
        
        .action-button:hover {
            background-color: rgba(0, 0, 0, 0.05);
            transform: translateY(-4px);
        }
        
        .dark-mode .action-button:hover {
            background-color: rgba(255, 255, 255, 0.05);
        }
        
        .action-icon {
            width: 36px;
            height: 36px;
            margin-bottom: 12px;
        }
        
        .action-label {
            font-size: 14px;
            font-weight: 500;
            text-align: center;
        }
        
        .incident-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 24px;
        }
        
        .incident-list {
            display: flex;
            flex-direction: column;
            gap: 16px;
        }
        
        .incident-item {
            display: flex;
            align-items: flex-start;
            gap: 12px;
            padding: 12px;
            border-radius: 8px;
            transition: all 0.3s ease;
            border: 1px solid var(--border-color);
        }
        
        .incident-item:hover {
            background-color: rgba(0, 0, 0, 0.02);
            transform: translateY(-2px);
        }
        
        .dark-mode .incident-item:hover {
            background-color: rgba(255, 255, 255, 0.02);
        }
        
        .incident-icon {
            width: 40px;
            height: 40px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }
        
        .incident-info {
            flex: 1;
            min-width: 0;
        }
        
        .incident-name {
            font-size: 14px;
            font-weight: 500;
        }
        
        .incident-location {
            font-size: 12px;
            color: var(--text-light);
        }
        
        .status-badge {
            font-size: 12px;
            padding: 4px 8px;
            border-radius: 4px;
            background: #e5e7eb;
        }
        
        .status-completed {
            background-color: #dcfce7;
            color: #166534;
        }
        
        .status-progress {
            background-color: #fef3c7;
            color: #92400e;
        }
        
        .status-pending {
            background-color: #fee2e2;
            color: #991b1b;
        }
        
        .equipment-container {
            display: flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 24px;
        }
        
        .equipment-circle {
            position: relative;
            width: 192px;
            height: 192px;
        }
        
        .equipment-svg {
            transform: rotate(-90deg);
            width: 192px;
            height: 192px;
        }
        
        .equipment-background {
            fill: none;
            stroke: #e5e7eb;
            stroke-width: 16;
        }
        
        .equipment-fill {
            fill: none;
            stroke: var(--primary-color);
            stroke-width: 16;
            stroke-dasharray: 502;
            stroke-dashoffset: 295;
            stroke-linecap: round;
            transition: stroke-dashoffset 1s ease;
        }
        
        .equipment-text {
            position: absolute;
            inset: 0;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
        }
        
        .equipment-value {
            font-size: 48px;
            font-weight: 700;
        }
        
        .equipment-label {
            font-size: 14px;
            color: var(--text-light);
        }
        
        .equipment-legend {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 24px;
            font-size: 14px;
        }
        
        .legend-item {
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .legend-dot {
            width: 12px;
            height: 12px;
            border-radius: 50%;
        }
        
        .dot-operational {
            background-color: var(--secondary-color);
        }
        
        .dot-maintenance {
            background-color: #6b7280;
        }
        
        .dot-offline {
            background-color: #ef4444;
        }
        
        .alert-card {
            background-color: #fee2e2;
            border: 1px solid #fca5a5;
            border-radius: 12px;
            padding: 16px;
            margin-bottom: 16px;
            transition: all 0.3s ease;
        }
        
        .dark-mode .alert-card {
            background-color: #7f1d1d;
            border: 1px solid #991b1b;
        }
        
        .alert-card:hover {
            transform: translateY(-3px);
        }
        
        .alert-title {
            font-weight: 600;
            margin-bottom: 8px;
        }
        
        .alert-time {
            font-size: 14px;
            color: var(--text-light);
            margin-bottom: 12px;
        }
        
        .alert-button {
            background-color: var(--secondary-color);
            color: white;
            padding: 8px 16px;
            border-radius: 8px;
            font-weight: 500;
            border: none;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            transition: all 0.3s ease;
            font-size: 14px;
            width: auto;
            margin: 0 auto;
        }
        
        .alert-button:hover {
            background-color: var(--secondary-dark);
            transform: translateY(-2px);
        }
        
        .personnel-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 24px;
        }
        
        .personnel-list {
            display: flex;
            flex-direction: column;
            gap: 16px;
        }
        
        .personnel-item {
            display: flex;
            align-items: flex-start;
            gap: 12px;
            padding: 12px;
            border-radius: 8px;
            transition: all 0.3s ease;
            border: 1px solid var(--border-color);
        }
        
        .personnel-item:hover {
            background-color: rgba(0, 0, 0, 0.02);
            transform: translateY(-2px);
        }
        
        .dark-mode .personnel-item:hover {
            background-color: rgba(255, 255, 255, 0.02);
        }
        
        .personnel-icon {
            width: 40px;
            height: 40px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }
        
        .personnel-info {
            flex: 1;
            min-width: 0;
        }
        
        .personnel-name {
            font-size: 14px;
            font-weight: 500;
        }
        
        .personnel-details {
            font-size: 12px;
            color: var(--text-light);
        }
        
        .button-icon {
            width: 18px;
            height: 18px;
        }
        
        .progress-container {
            margin-top: 16px;
        }
        
        .progress-bar {
            height: 8px;
            background-color: #e5e7eb;
            border-radius: 4px;
            overflow: hidden;
        }
        
        .progress-fill {
            height: 100%;
            background-color: var(--primary-color);
            border-radius: 4px;
            transition: width 0.5s ease;
        }
        
        .progress-labels {
            display: flex;
            justify-content: space-between;
            margin-top: 8px;
            font-size: 12px;
            color: var(--text-light);
        }

        @media (max-width: 1200px) {
            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }
            .main-grid {
                grid-template-columns: 1fr;
            }
            .two-column-grid {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 768px) {
            .container {
                flex-direction: column;
            }
            .sidebar {
                width: 100%;
                height: auto;
                padding: 16px;
            }
            .stats-grid {
                grid-template-columns: 1fr;
            }
            .dashboard-content {
                padding: 16px;
            }
            .dashboard-header {
                flex-direction: column;
                align-items: flex-start;
                gap: 16px;
            }
            .header-content {
                flex-direction: column;
                gap: 16px;
            }
            .search-container {
                max-width: 100%;
            }
            .header-actions {
                flex-wrap: wrap;
                justify-content: flex-end;
            }
            .time-display {
                min-width: 140px;
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
                    <img src="../img/frsm-logo.png" alt="Fire & Rescue Logo" style="width: 40px; height: 45px;">
                </div>
                <span class="logo-text">Fire & Rescue</span>
            </div>
            
            <!-- Menu Section -->
            <div class="menu-section">
                <p class="menu-title">FIRE & RESCUE MANAGEMENT</p>
                
                <div class="menu-items">
                    <a href="user_dashboard.php" class="menu-item active" id="dashboard-menu">
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
                        <a href="fir/active_incidents.php" class="submenu-item">Active Incidents</a>
                        <a href="fir/response_history.php" class="submenu-item">Response History</a>
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
                        <a href="dc/suggested_unit.php" class="submenu-item">Suggested Unit</a>
                        <a href="dc/incident_location.php" class="submenu-item">Incident Location</a>
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
                        <a href="vr/volunteer_list.php" class="submenu-item">Volunteer List</a>
                        <a href="vr/roles_skills.php" class="submenu-item">Roles & Skills</a>
                        <a href="vr/availability.php" class="submenu-item">Availability</a>
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
                        <a href="ri/equipment_list.php" class="submenu-item">Equipment List</a>
                        <a href="ri/stock_levels.php" class="submenu-item">Stock Levels</a>
                        <a href="ri/maintenance_logs.php" class="submenu-item">Maintenance Logs</a>
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
                        <a href="sds/view_shifts.php" class="submenu-item">Shift Calendar</a>
                        <a href="sds/confirm_availability.php" class="submenu-item">Confirm Availability</a>
                        <a href="sds/duty_assignments.php" class="submenu-item">Duty Assignments</a>
                        <a href="sds/attendance_logs.php" class="submenu-item">Attendance Logs</a>
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
                        <a href="tc/view_available_training.php" class="submenu-item">Register for Training</a>
                        <a href="tc/training_records.php" class="submenu-item">Training Records</a>
                        <a href="tc/certification_status.php" class="submenu-item">Certification Status</a>
                        <a href="tc/track_progress.php" class="submenu-item">Track Progress</a>
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
                    
                    <a href="../includes/logout.php" class="menu-item">
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
                            <input type="text" placeholder="Search incidents, personnel, equipment..." class="search-input">
                            <kbd class="search-shortcut">🔥</kbd>
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
                        <button class="header-button">
                            <svg class="header-button-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"></path>
                            </svg>
                        </button>
                        <button class="header-button">
                            <svg class="header-button-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"></path>
                            </svg>
                        </button>
                        <div class="user-profile">
                            <img src="../img/rei.jfif" alt="User" class="user-avatar">
                            <div class="user-info">
                                <p class="user-name"><?php echo $full_name; ?></p>
                                <p class="user-email"><?php echo $role; ?></p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Dashboard Content -->
            <div class="dashboard-content">
                <div class="dashboard-header">
                    <div>
                        <h1 class="dashboard-title">Fire & Rescue Dashboard</h1>
                        <p class="dashboard-subtitle">Monitor, manage, and coordinate fire & rescue operations.</p>
                    </div>
                    <div class="dashboard-actions">
                        <button class="primary-button">
                            <span style="font-size: 20px;">+</span>
                            New Incident
                        </button>
                        <button class="secondary-button">
                            Export Reports
                        </button>
                    </div>
                </div>
                
                <!-- Stats Cards -->
                <div class="stats-grid">
                    <div class="stat-card stat-card-primary">
                        <div class="stat-header">
                            <span class="stat-title">Active Incidents</span>
                            <button class="stat-button stat-button-primary">
                                <svg class="menu-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path>
                                </svg>
                            </button>
                        </div>
                        <div class="stat-value">8</div>
                        <div class="stat-info">
                            <svg class="stat-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7h8m0 0v8m0-8l-8 8-4-4-6 6"></path>
                            </svg>
                            <span>2 new in last hour</span>
                        </div>
                    </div>
                    
                    <div class="stat-card stat-card-white">
                        <div class="stat-header">
                            <span class="stat-title">Avg Response Time</span>
                            <button class="stat-button stat-button-white">
                                <svg class="menu-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path>
                                </svg>
                            </button>
                        </div>
                        <div class="stat-value">4.2<span style="font-size: 24px;">min</span></div>
                        <div class="stat-info">
                            <svg class="stat-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7h8m0 0v8m0-8l-8 8-4-4-6 6"></path>
                            </svg>
                            <span>Improved from last month</span>
                        </div>
                    </div>
                    
                    <div class="stat-card stat-card-white">
                        <div class="stat-header">
                            <span class="stat-title">Equipment Operational</span>
                            <button class="stat-button stat-button-white">
                                <svg class="menu-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path>
                                </svg>
                            </button>
                        </div>
                        <div class="stat-value">96%</div>
                        <div class="stat-info">
                            <svg class="stat-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7h8m0 0v8m0-8l-8 8-4-4-6 6"></path>
                            </svg>
                            <span>5 units in maintenance</span>
                        </div>
                    </div>
                    
                    <div class="stat-card stat-card-white">
                        <div class="stat-header">
                            <span class="stat-title">Personnel On Duty</span>
                            <button class="stat-button stat-button-white">
                                <svg class="menu-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path>
                                </svg>
                            </button>
                        </div>
                        <div class="stat-value">42</div>
                        <div class="stat-info">
                            <span>Across 6 stations</span>
                        </div>
                    </div>
                </div>
                
                <!-- Main Grid -->
                <div class="main-grid">
                    <div class="left-column">
                        <div class="card">
                            <h2 class="card-title">Incident Response Analysis</h2>
                            <div class="response-chart">
                                <div class="chart-bar">
                                    <div class="chart-bar-value bar-red" style="height: 35%;"></div>
                                    <span class="chart-bar-label">Residential</span>
                                </div>
                                <div class="chart-bar">
                                    <div class="chart-bar-value bar-orange" style="height: 75%;"></div>
                                    <span class="chart-bar-label">Commercial</span>
                                </div>
                                <div class="chart-bar">
                                    <div class="chart-bar-value bar-yellow" style="height: 90%;"></div>
                                    <span class="chart-bar-label">Vehicle</span>
                                </div>
                                <div class="chart-bar">
                                    <div class="chart-bar-value bar-green" style="height: 100%;"></div>
                                    <span class="chart-bar-label">Medical</span>
                                </div>
                                <div class="chart-bar">
                                    <div class="chart-bar-value bar-blue" style="height: 40%;"></div>
                                    <span class="chart-bar-label">Hazmat</span>
                                </div>
                                <div class="chart-bar">
                                    <div class="chart-bar-value bar-purple" style="height: 55%;"></div>
                                    <span class="chart-bar-label">Rescue</span>
                                </div>
                                <div class="chart-bar">
                                    <div class="chart-bar-value bar-pink" style="height: 45%;"></div>
                                    <span class="chart-bar-label">Other</span>
                                </div>
                            </div>
                            <div class="progress-container">
                                <div class="progress-bar">
                                    <div class="progress-fill" style="width: 75%;"></div>
                                </div>
                                <div class="progress-labels">
                                    <span>Response Goal: 5 min</span>
                                    <span>Current Avg: 4.2 min</span>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Quick Actions & Incident Reports -->
                        <div class="two-column-grid">
                            <div class="card">
                                <h2 class="card-title">Quick Actions</h2>
                                <div class="quick-actions">
                                    <div class="action-button">
                                        <div class="icon-box icon-bg-red">
                                            <i class='bx bxs-report icon-red'></i>
                                        </div>
                                        <span class="action-label">Report Incident</span>
                                    </div>
                                    <div class="action-button">
                                        <div class="icon-box icon-bg-blue">
                                            <i class='bx bxs-cog icon-blue'></i>
                                        </div>
                                        <span class="action-label">Check Equipment</span>
                                    </div>
                                    <div class="action-button">
                                        <div class="icon-box icon-bg-purple">
                                            <i class='bx bxs-calendar icon-purple'></i>
                                        </div>
                                        <span class="action-label">Schedule Personnel</span>
                                    </div>
                                    <div class="action-button">
                                        <div class="icon-box icon-bg-yellow">
                                            <i class='bx bxs-check-shield icon-yellow'></i>
                                        </div>
                                        <span class="action-label">Inspection Report</span>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Incident Reports -->
                            <div class="card">
                                <div class="incident-header">
                                    <h2 class="card-title">Active Incidents</h2>
                                    <button class="secondary-button" style="font-size: 14px; padding: 8px 16px;">View All</button>
                                </div>
                                <div class="incident-list">
                                    <div class="incident-item">
                                        <div class="incident-icon icon-bg-red">
                                            <i class='bx bxs-map icon-red'></i>
                                        </div>
                                        <div class="incident-info">
                                            <p class="incident-name">Structure Fire - 124 Main St</p>
                                            <p class="incident-location">Units: Engine 1, Ladder 3, Rescue 2</p>
                                        </div>
                                        <span class="status-badge status-pending">Active</span>
                                    </div>
                                    <div class="incident-item">
                                        <div class="incident-icon icon-bg-yellow">
                                            <i class='bx bxs-car-crash icon-yellow'></i>
                                        </div>
                                        <div class="incident-info">
                                            <p class="incident-name">Vehicle Accident - Highway 101</p>
                                            <p class="incident-location">Units: Engine 4, Medic 2</p>
                                        </div>
                                        <span class="status-badge status-progress">En Route</span>
                                    </div>
                                    <div class="incident-item">
                                        <div class="incident-icon icon-bg-blue">
                                            <i class='bx bxs-first-aid icon-blue'></i>
                                        </div>
                                        <div class="incident-info">
                                            <p class="incident-name">Medical Emergency - 58 Park Ave</p>
                                            <p class="incident-location">Units: Medic 1, Engine 2</p>
                                        </div>
                                        <span class="status-badge status-completed">Stabilized</span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="right-column">
                        <div class="card">
                            <h2 class="card-title">Emergency Alerts</h2>
                            <div class="alert-card">
                                <h3 class="alert-title">High Fire Risk - Northwest District</h3>
                                <p class="alert-time">Issued: Today 10:30 AM | Expires: Tomorrow 6:00 PM</p>
                                <button class="alert-button">
                                    <svg class="button-icon" fill="currentColor" viewBox="0 0 24 24">
                                        <path d="M9.4 16.6L4.8 12l4.6-4.6L8 6l-6 6 6 6 1.4-1.4z"></path>
                                    </svg>
                                    View Details
                                </button>
                            </div>
                            <div class="alert-card">
                                <h3 class="alert-title">Hydrant Maintenance - Central Area</h3>
                                <p class="alert-time">Schedule: Tomorrow 8 AM - 4 PM | 15 hydrants affected</p>
                                <button class="alert-button">
                                    <svg class="button-icon" fill="currentColor" viewBox="0 0 24 24">
                                        <path d="M9.4 16.6L4.8 12l4.6-4.6L8 6l-6 6 6 6 1.4-1.4z"></path>
                                    </svg>
                                    View Map
                                </button>
                            </div>
                        </div>
                        
                        <!-- Personnel Status -->
                        <div class="card">
                            <div class="personnel-header">
                                <h2 class="card-title">Personnel Status</h2>
                                <button class="secondary-button" style="font-size: 14px; padding: 8px 16px;">Refresh</button>
                            </div>
                            <div class="personnel-list">
                                <div class="personnel-item">
                                    <div class="personnel-icon icon-bg-cyan">
                                        <i class='bx bxs-user icon-cyan'></i>
                                    </div>
                                    <div class="personnel-info">
                                        <p class="personnel-name">Station 1 - A Shift</p>
                                        <p class="personnel-details">8 personnel on duty | 2 available</p>
                                    </div>
                                </div>
                                <div class="personnel-item">
                                    <div class="personnel-icon icon-bg-purple">
                                        <i class='bx bxs-user icon-purple'></i>
                                    </div>
                                    <div class="personnel-info">
                                        <p class="personnel-name">Station 2 - B Shift</p>
                                        <p class="personnel-details">7 personnel on duty | 5 available</p>
                                    </div>
                                </div>
                                <div class="personnel-item">
                                    <div class="personnel-icon icon-bg-indigo">
                                        <i class='bx bxs-user-badge icon-indigo'></i>
                                    </div>
                                    <div class="personnel-info">
                                        <p class="personnel-name">Special Operations</p>
                                        <p class="personnel-details">12 personnel | 8 on call</p>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Equipment Status -->
                        <div class="card">
                            <h2 class="card-title">Equipment Status</h2>
                            <div class="equipment-container">
                                <div class="equipment-circle">
                                    <svg class="equipment-svg">
                                        <circle cx="96" cy="96" r="80" class="equipment-background"></circle>
                                        <circle cx="96" cy="96" r="80" class="equipment-fill"></circle>
                                    </svg>
                                    <div class="equipment-text">
                                        <span class="equipment-value">96%</span>
                                        <span class="equipment-label">Operational</span>
                                    </div>
                                </div>
                            </div>
                            <div class="equipment-legend">
                                <div class="legend-item">
                                    <div class="legend-dot dot-operational"></div>
                                    <span class="text-gray-600">Operational</span>
                                </div>
                                <div class="legend-item">
                                    <div class="legend-dot dot-maintenance"></div>
                                    <span class="text-gray-600">Maintenance</span>
                                </div>
                                <div class="legend-item">
                                    <div class="legend-dot dot-offline"></div>
                                    <span class="text-gray-600">Offline</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <script>
        function toggleSubmenu(id) {
            const submenu = document.getElementById(id);
            const arrow = document.querySelector(`#${id}`).previousElementSibling.querySelector('.dropdown-arrow');
            
            submenu.classList.toggle('active');
            arrow.classList.toggle('rotated');
        }
        
        document.querySelectorAll('.menu-item').forEach(item => {
            item.addEventListener('click', function() {
                document.querySelectorAll('.menu-item').forEach(i => {
                    i.classList.remove('active');
                });
                
                this.classList.add('active');
            });
        });
        
        document.querySelectorAll('.submenu-item').forEach(item => {
            item.addEventListener('click', function() {
                document.querySelectorAll('.submenu-item').forEach(i => {
                    i.classList.remove('active');
                });
                
                this.classList.add('active');
            });
        });
        
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
        
        window.addEventListener('load', function() {
            const bars = document.querySelectorAll('.chart-bar-value');
            bars.forEach(bar => {
                const height = bar.style.height;
                bar.style.height = '0%';
                setTimeout(() => {
                    bar.style.height = height;
                }, 300);
            });
        });
        
        document.querySelectorAll('.card').forEach(card => {
            card.addEventListener('mouseenter', function() {
                this.style.transform = 'translateY(-5px)';
            });
            
            card.addEventListener('mouseleave', function() {
                this.style.transform = 'translateY(0)';
            });
        });
        
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