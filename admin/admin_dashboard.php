<?php
session_start();
require_once '../config/db_connection.php';

// Include FPDF for report generation
require_once('../vendor/setasign/fpdf/fpdf.php');

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

// Fetch real data from database for dashboard stats
$stats = [];

// Pending Approvals (volunteer applications + maintenance requests + change requests)
$query = "SELECT COUNT(*) as count FROM volunteers WHERE status = 'pending'";
$stmt = $pdo->query($query);
$pendingVolunteers = $stmt->fetch()['count'];

$query = "SELECT COUNT(*) as count FROM maintenance_requests WHERE status = 'pending'";
$stmt = $pdo->query($query);
$pendingMaintenance = $stmt->fetch()['count'];

$query = "SELECT COUNT(*) as count FROM change_requests WHERE status = 'pending'";
$stmt = $pdo->query($query);
$pendingChanges = $stmt->fetch()['count'];

$stats['pending_approvals'] = $pendingVolunteers + $pendingMaintenance + $pendingChanges;

// Active Incidents
$query = "SELECT COUNT(*) as count FROM api_incidents WHERE status IN ('pending', 'processing')";
$stmt = $pdo->query($query);
$stats['active_incidents'] = $stmt->fetch()['count'];

// System Users
$query = "SELECT COUNT(*) as count FROM users";
$stmt = $pdo->query($query);
$stats['total_users'] = $stmt->fetch()['count'];

$query = "SELECT COUNT(*) as count FROM volunteers WHERE status = 'approved'";
$stmt = $pdo->query($query);
$stats['total_volunteers'] = $stmt->fetch()['count'];

// Uptime (simulated - you can implement actual uptime tracking)
$stats['uptime'] = "99.8%";

// System Performance metrics
$query = "SELECT COUNT(*) as total, 
                 SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed,
                 SUM(CASE WHEN status IN ('pending', 'processing') THEN 1 ELSE 0 END) as pending
          FROM api_incidents 
          WHERE DATE(created_at_local) >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)";
$stmt = $pdo->query($query);
$incidentStats = $stmt->fetch();
$performance_percentage = $incidentStats['total'] > 0 ? 
    round(($incidentStats['completed'] / $incidentStats['total']) * 100) : 0;

// Recent incidents for display
$query = "SELECT title, location, status, created_at_local 
          FROM api_incidents 
          WHERE status IN ('pending', 'processing')
          ORDER BY created_at_local DESC 
          LIMIT 3";
$stmt = $pdo->query($query);
$recentIncidents = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Recent user activity
$query = "SELECT email, attempt_time 
          FROM login_attempts 
          WHERE successful = 1 
          ORDER BY attempt_time DESC 
          LIMIT 3";
$stmt = $pdo->query($query);
$recentLogins = $stmt->fetchAll(PDO::FETCH_ASSOC);

// System alerts (certificates expiring soon, low resources, etc.)
$query = "SELECT COUNT(*) as count FROM training_certificates 
          WHERE expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)";
$stmt = $pdo->query($query);
$expiringCertificates = $stmt->fetch()['count'];

// Equipment status
$query = "SELECT COUNT(*) as total,
                 SUM(CASE WHEN condition_status = 'Serviceable' THEN 1 ELSE 0 END) as operational,
                 SUM(CASE WHEN condition_status = 'Under Maintenance' THEN 1 ELSE 0 END) as maintenance,
                 SUM(CASE WHEN condition_status IN ('Condemned', 'Out of Service') THEN 1 ELSE 0 END) as offline
          FROM resources 
          WHERE is_active = 1";
$stmt = $pdo->query($query);
$equipmentStats = $stmt->fetch();

$operational_percentage = $equipmentStats['total'] > 0 ? 
    round(($equipmentStats['operational'] / $equipmentStats['total']) * 100) : 0;

$stmt = null;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Fire & Rescue Services Management - Admin Dashboard</title>
    <link href='https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css' rel='stylesheet'>
    <link rel="icon" type="image/png" sizes="32x32" href="../img/frsm-logo.png">
    <link rel="stylesheet" href="../css/dashboard.css">
</head>
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
    
    --icon-bg-red: rgba(254, 226, 226, 0.9);
    --icon-bg-blue: rgba(219, 234, 254, 0.9);
    --icon-bg-green: rgba(220, 252, 231, 0.9);
    --icon-bg-purple: rgba(243, 232, 255, 0.9);
    --icon-bg-yellow: rgba(254, 243, 199, 0.9);
    --icon-bg-indigo: rgba(224, 231, 255, 0.9);
    --icon-bg-cyan: rgba(207, 250, 254, 0.9);
    --icon-bg-orange: rgba(255, 237, 213, 0.9);
    --icon-bg-pink: rgba(252, 231, 243, 0.9);
    --icon-bg-teal: rgba(204, 251, 241, 0.9);
    
    --chart-red: #ef4444;
    --chart-orange: #f97316;
    --chart-yellow: #f59e0b;
    --chart-green: #10b981;
    --chart-blue: #3b82f6;
    --chart-purple: #8b5cf6;
    --chart-pink: #ec4899;
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
    background-color: var(--background-color);
    color: var(--text-color);
    transition: background-color 0.3s, color 0.3s;
}

.container {
    display: flex;
    min-height: 100vh;
    position: relative;
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
    position: sticky;
    top: 0;
    height: 100vh;
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
    background-color: var(--card-bg);
    transform: translateY(-1px);
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
    background: var(--card-bg);
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
    background-color: var(--card-bg);
    transform: translateX(4px);
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
    transition: all 0.3s ease;
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
    background-color: var(--card-bg);
    transform: translateY(-1px);
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
    background-color: var(--card-bg);
    transform: translateY(-1px);
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
    background-color: var(--background-color);
    transform: translateY(-2px);
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
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
}

.stat-card:hover {
    transform: translateY(-3px);
}

.stat-card-primary {
    background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%);
    color: white;
    border: none;
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
    border-color: rgba(255, 255, 255, 0.3);
}

.stat-button-primary:hover {
    background-color: rgba(255, 255, 255, 0.3);
}

.stat-button-white {
    background-color: var(--background-color);
}

.stat-button-white:hover {
    background-color: var(--card-bg);
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

/* Main Grid */
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

/* Card Styles */
.card {
    background-color: var(--card-bg);
    border: 1px solid var(--border-color);
    border-radius: 16px;
    padding: 24px;
    transition: all 0.3s ease;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
}

.card:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
}

.card-title {
    font-size: 20px;
    font-weight: 600;
    margin-bottom: 24px;
}

/* Incident Response Chart */
.response-chart {
    display: flex;
    align-items: flex-end;
    justify-content: space-around;
    height: 256px;
    margin-bottom: 24px;
}

.chart-bar {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 8px;
}

.chart-bar-value {
    width: 32px;
    border-radius: 8px 8px 0 0;
    transition: height 0.5s ease;
    background: var(--chart-red);
}

.chart-bar-label {
    font-size: 14px;
    color: var(--text-light);
}

.bar-red { background-color: var(--chart-red); }
.bar-orange { background-color: var(--chart-orange); }
.bar-yellow { background-color: var(--chart-yellow); }
.bar-green { background-color: var(--chart-green); }
.bar-blue { background-color: var(--chart-blue); }
.bar-purple { background-color: var(--chart-purple); }
.bar-pink { background-color: var(--chart-pink); }

/* Two Column Grid */
.two-column-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 24px;
}

/* Quick Actions */
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
    background-color: var(--background-color);
    transform: translateY(-2px);
    box-shadow: 0 4px 8px rgba(0, 0, 0, 0.1);
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

/* Incident Reports */
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
    background: var(--card-bg);
    border: 1px solid var(--border-color);
}

.incident-item:hover {
    background-color: var(--background-color);
    transform: translateY(-1px);
}

.incident-icon {
    width: 40px;
    height: 40px;
    border-radius: 8px;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
    background: var(--card-bg);
    border: 1px solid var(--border-color);
}

.incident-info {
    flex: 1;
    min-width: 0;
}

.incident-name {
    font-size: 14px;
    font-weight: 500;
    margin-bottom: 4px;
}

.incident-location {
    font-size: 12px;
    color: var(--text-light);
}

.status-badge {
    font-size: 12px;
    padding: 4px 8px;
    border-radius: 4px;
    font-weight: 500;
}

.status-completed {
    background-color: var(--icon-bg-green);
    color: #166534;
}

.status-progress {
    background-color: var(--icon-bg-yellow);
    color: #92400e;
}

.status-pending {
    background-color: var(--icon-bg-red);
    color: #991b1b;
}

/* Equipment Status */
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
    stroke: var(--border-color);
    stroke-width: 16;
}

.equipment-fill {
    fill: none;
    stroke: var(--primary-color);
    stroke-width: 16;
    stroke-dasharray: 502;
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

.dot-operational { background-color: var(--secondary-color); }
.dot-maintenance { background-color: var(--text-color); }
.dot-offline { background-color: var(--icon-red); }

/* Alerts */
.alert-card {
    background-color: var(--icon-bg-red);
    border: 1px solid var(--border-color);
    border-radius: 12px;
    padding: 16px;
    margin-bottom: 16px;
    transition: all 0.3s ease;
}

.alert-card:hover {
    transform: translateY(-2px);
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
}

.alert-button:hover {
    background-color: var(--secondary-dark);
    transform: translateY(-1px);
}

/* Personnel Status */
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
    background: var(--card-bg);
    border: 1px solid var(--border-color);
}

.personnel-item:hover {
    background-color: var(--background-color);
    transform: translateY(-1px);
}

.personnel-icon {
    width: 40px;
    height: 40px;
    border-radius: 8px;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
    background: var(--card-bg);
    border: 1px solid var(--border-color);
}

.personnel-info {
    flex: 1;
    min-width: 0;
}

.personnel-name {
    font-size: 14px;
    font-weight: 500;
    margin-bottom: 4px;
}

.personnel-details {
    font-size: 12px;
    color: var(--text-light);
}

.button-icon {
    width: 18px;
    height: 18px;
}

/* Progress Bar */
.progress-container {
    margin-top: 16px;
}

.progress-bar {
    height: 8px;
    background-color: var(--border-color);
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

/* Responsive Design */
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
        position: relative;
        height: auto;
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
                    <a href="#" class="menu-item active" id="dashboard-menu">
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
                        <a href="users/manage_users.php" class="submenu-item">Manage Users</a>
                        <a href="users/role_control.php" class="submenu-item">Role Control</a>
                        <a href="users/monitor_activity.php" class="submenu-item">Audit & Activity Logs</a>
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
                        <a href="fir/receive_data.php" class="submenu-item">Recieve Data</a>
                        <a href="fir/update_status.php" class="submenu-item">Update Status</a>
                        <a href="fir/incidents_analytics.php" class="submenu-item">Incidents Analytics</a>
                    </div>
                    
                    <!-- Barangay Volunteer Roster Management -->
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
                        <a href="vm/review_data.php" class="submenu-item">Review Data</a>
                        <a href="vm/approve_applications.php" class="submenu-item">Approve Applications</a>
                        <a href="vm/assign_volunteers.php" class="submenu-item">Assign Volunteers</a>
                        <a href="vm/view_availability.php" class="submenu-item">View Availability</a>
                        <a href="vm/toggle_volunteer_registration.php" class="submenu-item">Toggle Registration</a>
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
                        <a href="rm/view_equipment.php" class="submenu-item">View Equipment</a>
                        <a href="rm/approve_maintenance.php" class="submenu-item">Approve Maintenance</a>
                        <a href="rm/approve_resources.php" class="submenu-item">Approve Resources</a>
                        <a href="rm/review_deployment.php" class="submenu-item">Review Deployment</a>
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
                        <a href="sm/view_shifts.php" class="submenu-item">View Shifts</a>
                        <a href="sm/create_schedule.php" class="submenu-item">Create Schedule</a>
                        <a href="sm/approve_shifts.php" class="submenu-item">Approve Shifts</a>
                        <a href="sm/override_assignments.php" class="submenu-item">Override Assignments</a>
                        <a href="sm/monitor_attendance.php" class="submenu-item">Monitor Attendance</a>
                    </div>
                    
                    <!-- Training & Certification Monitoring -->
                    <div class="menu-item" onclick="toggleSubmenu('training-management')">
                        <div class="icon-box icon-bg-teal">
                            <i class='bx bxs-graduation icon-teal'></i>
                        </div>
                        <span class="font-medium">Training Management</span>
                        <svg class="dropdown-arrow menu-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                        </svg>
                    </div>
                    <div id="training-management" class="submenu">
                        <a href="tc/approve_completions.php" class="submenu-item">Approve Completions</a>
                        <a href="tc/view_training_records.php" class="submenu-item">View Records</a>
                        <a href="tc/assign_training.php" class="submenu-item">Assign Training</a>
                        <a href="tc/track_expiry.php" class="submenu-item">Track Expiry</a>
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
                        <a href="ile/approve_reports.php" class="submenu-item">Approve Reports</a>
                        <a href="ile/review_violations.php" class="submenu-item">Review Violations</a>
                        <a href="ile/issue_certificates.php" class="submenu-item">Issue Certificates</a>
                        <a href="ile/track_followup.php" class="submenu-item">Track Follow-Up</a>
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
                        <a href="pir/review_summaries.php" class="submenu-item">Review Summaries</a>
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
                        <button class="header-button" onclick="location.href='../notifications.php'">
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
                        <h1 class="dashboard-title">Administrative Dashboard</h1>
                        <p class="dashboard-subtitle">Oversee, approve, configure, and analyze the system.</p>
                    </div>
                    <div class="dashboard-actions">
                        <button class="primary-button" onclick="generateReport()">
                            <span style="font-size: 20px;">📊</span>
                            Generate Report
                        </button>
                        <button class="secondary-button" onclick="runSystemBackup()">
                            <i class='bx bx-data'></i>
                            System Backup
                        </button>
                    </div>
                </div>
                
                <!-- Stats Cards -->
                <div class="stats-grid">
                    <div class="stat-card stat-card-primary">
                        <div class="stat-header">
                            <span class="stat-title">Pending Approvals</span>
                            <button class="stat-button stat-button-primary" onclick="location.href='vm/review_data.php'">
                                <svg class="menu-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path>
                                </svg>
                            </button>
                        </div>
                        <div class="stat-value"><?php echo $stats['pending_approvals']; ?></div>
                        <div class="stat-info">
                            <svg class="stat-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7h8m0 0v8m0-8l-8 8-4-4-6 6"></path>
                            </svg>
                            <span><?php echo $pendingVolunteers; ?> volunteer applications</span>
                        </div>
                    </div>
                    
                    <div class="stat-card stat-card-white">
                        <div class="stat-header">
                            <span class="stat-title">Active Incidents</span>
                            <button class="stat-button stat-button-white" onclick="location.href='fir/receive_data.php'">
                                <svg class="menu-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path>
                                </svg>
                            </button>
                        </div>
                        <div class="stat-value"><?php echo $stats['active_incidents']; ?></div>
                        <div class="stat-info">
                            <svg class="stat-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7h8m0 0v8m0-8l-8 8-4-4-6 6"></path>
                            </svg>
                            <span>Requiring attention</span>
                        </div>
                    </div>
                    
                    <div class="stat-card stat-card-white">
                        <div class="stat-header">
                            <span class="stat-title">System Users</span>
                            <button class="stat-button stat-button-white" onclick="location.href='users/manage_users.php'">
                                <svg class="menu-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path>
                                </svg>
                            </button>
                        </div>
                        <div class="stat-value"><?php echo $stats['total_users']; ?></div>
                        <div class="stat-info">
                            <svg class="stat-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7h8m0 0v8m0-8l-8 8-4-4-6 6"></path>
                            </svg>
                            <span><?php echo $stats['total_volunteers']; ?> volunteers</span>
                        </div>
                    </div>
                    
                    <div class="stat-card stat-card-white">
                        <div class="stat-header">
                            <span class="stat-title">Uptime</span>
                            <button class="stat-button stat-button-white">
                                <svg class="menu-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path>
                                </svg>
                            </button>
                        </div>
                        <div class="stat-value"><?php echo $stats['uptime']; ?></div>
                        <div class="stat-info">
                            <span>Last 30 days</span>
                        </div>
                    </div>
                </div>
                
                <!-- Main Grid -->
                <div class="main-grid">
                    <div class="left-column">
                        <div class="card">
                            <h2 class="card-title">System Overview</h2>
                            <div class="response-chart">
                                <div class="chart-bar">
                                    <div class="chart-bar-value bar-red" id="incidents-bar" style="height: 0%"></div>
                                    <span class="chart-bar-label">Incidents</span>
                                </div>
                                <div class="chart-bar">
                                    <div class="chart-bar-value bar-orange" id="users-bar" style="height: 0%"></div>
                                    <span class="chart-bar-label">Users</span>
                                </div>
                                <div class="chart-bar">
                                    <div class="chart-bar-value bar-yellow" id="volunteers-bar" style="height: 0%"></div>
                                    <span class="chart-bar-label">Volunteers</span>
                                </div>
                                <div class="chart-bar">
                                    <div class="chart-bar-value bar-green" id="resources-bar" style="height: 0%"></div>
                                    <span class="chart-bar-label">Resources</span>
                                </div>
                                <div class="chart-bar">
                                    <div class="chart-bar-value bar-blue" id="training-bar" style="height: 0%"></div>
                                    <span class="chart-bar-label">Training</span>
                                </div>
                                <div class="chart-bar">
                                    <div class="chart-bar-value bar-purple" id="inspections-bar" style="height: 0%"></div>
                                    <span class="chart-bar-label">Inspections</span>
                                </div>
                            </div>
                            <div class="progress-container">
                                <div class="progress-bar">
                                    <div class="progress-fill" id="performance-bar" style="width: 0%"></div>
                                </div>
                                <div class="progress-labels">
                                    <span>System Performance</span>
                                    <span id="performance-text">0% Optimal</span>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Quick Actions & Pending Approvals -->
                        <div class="two-column-grid">
                            <div class="card">
                                <h2 class="card-title">Quick Actions</h2>
                                <div class="quick-actions">
                                    <div class="action-button" onclick="location.href='vm/approve_applications.php'">
                                        <div class="icon-box icon-bg-red">
                                            <i class='bx bxs-user-check icon-red'></i>
                                        </div>
                                        <span class="action-label">Approve Users</span>
                                    </div>
                                    <div class="action-button" onclick="location.href='fir/receive_data.php'">
                                        <div class="icon-box icon-bg-blue">
                                            <i class='bx bxs-file-check icon-blue'></i>
                                        </div>
                                        <span class="action-label">Review Reports</span>
                                    </div>
                                    <div class="action-button" onclick="location.href='rm/approve_maintenance.php'">
                                        <div class="icon-box icon-bg-purple">
                                            <i class='bx bxs-cog icon-purple'></i>
                                        </div>
                                        <span class="action-label">Maintenance</span>
                                    </div>
                                    <div class="action-button" onclick="location.href='ar/analyze_data.php'">
                                        <div class="icon-box icon-bg-yellow">
                                            <i class='bx bxs-bar-chart-alt-2 icon-yellow'></i>
                                        </div>
                                        <span class="action-label">View Analytics</span>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Pending Approvals -->
                            <div class="card">
                                <div class="incident-header">
                                    <h2 class="card-title">Pending Approvals</h2>
                                    <button class="secondary-button" style="font-size: 14px; padding: 8px 16px;" onclick="location.href='vm/review_data.php'">View All</button>
                                </div>
                                <div class="incident-list">
                                    <div class="incident-item">
                                        <div class="incident-icon icon-red">
                                            <i class='bx bxs-user-plus icon-red'></i>
                                        </div>
                                        <div class="incident-info">
                                            <p class="incident-name">New Volunteer Applications</p>
                                            <p class="incident-location"><?php echo $pendingVolunteers; ?> applications pending review</p>
                                        </div>
                                        <span class="status-badge status-pending">Review</span>
                                    </div>
                                    <div class="incident-item">
                                        <div class="incident-icon icon-yellow">
                                            <i class='bx bxs-cog icon-yellow'></i>
                                        </div>
                                        <div class="incident-info">
                                            <p class="incident-name">Maintenance Requests</p>
                                            <p class="incident-location"><?php echo $pendingMaintenance; ?> equipment repairs pending</p>
                                        </div>
                                        <span class="status-badge status-progress">Approve</span>
                                    </div>
                                    <div class="incident-item">
                                        <div class="incident-icon icon-blue">
                                            <i class='bx bxs-edit-alt icon-blue'></i>
                                        </div>
                                        <div class="incident-info">
                                            <p class="incident-name">Change Requests</p>
                                            <p class="incident-location"><?php echo $pendingChanges; ?> profile updates pending</p>
                                        </div>
                                        <span class="status-badge status-completed">Review</span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="right-column">
                        <div class="card">
                            <h2 class="card-title">System Alerts</h2>
                            <?php if ($expiringCertificates > 0): ?>
                            <div class="alert-card">
                                <h3 class="alert-title">Certificate Expiry Notice</h3>
                                <p class="alert-time"><?php echo $expiringCertificates; ?> training certificates expiring in 30 days</p>
                                <button class="alert-button" onclick="location.href='tc/track_expiry.php'">
                                    <svg class="button-icon" fill="currentColor" viewBox="0 0 24 24">
                                        <path d="M9.4 16.6L4.8 12l4.6-4.6L8 6l-6 6 6 6 1.4-1.4z"></path>
                                    </svg>
                                    View Details
                                </button>
                            </div>
                            <?php endif; ?>
                            <div class="alert-card">
                                <h3 class="alert-title">System Backup Required</h3>
                                <p class="alert-time">Last backup: <?php echo date('F j, Y'); ?> | Recommended: Daily</p>
                                <button class="alert-button" onclick="runSystemBackup()">
                                    <svg class="button-icon" fill="currentColor" viewBox="0 0 24 24">
                                        <path d="M9.4 16.6L4.8 12l4.6-4.6L8 6l-6 6 6 6 1.4-1.4z"></path>
                                    </svg>
                                    Run Backup
                                </button>
                            </div>
                        </div>
                        
                        <!-- User Activity -->
                        <div class="card">
                            <div class="personnel-header">
                                <h2 class="card-title">Recent User Activity</h2>
                                <button class="secondary-button" style="font-size: 14px; padding: 8px 16px;" onclick="refreshActivity()">Refresh</button>
                            </div>
                            <div class="personnel-list" id="user-activity-list">
                                <?php foreach ($recentLogins as $login): ?>
                                <div class="personnel-item">
                                    <div class="personnel-icon icon-cyan">
                                        <i class='bx bxs-user icon-cyan'></i>
                                    </div>
                                    <div class="personnel-info">
                                        <p class="personnel-name"><?php echo htmlspecialchars($login['email']); ?></p>
                                        <p class="personnel-details">Logged in at <?php echo date('H:i', strtotime($login['attempt_time'])); ?></p>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        
                        <!-- System Status -->
                        <div class="card">
                            <h2 class="card-title">System Status</h2>
                            <div class="equipment-container">
                                <div class="equipment-circle">
                                    <svg class="equipment-svg">
                                        <circle cx="96" cy="96" r="80" class="equipment-background"></circle>
                                        <circle cx="96" cy="96" r="80" class="equipment-fill" id="equipment-fill"></circle>
                                    </svg>
                                    <div class="equipment-text">
                                        <span class="equipment-value" id="equipment-value">0%</span>
                                        <span class="equipment-label">Operational</span>
                                    </div>
                                </div>
                            </div>
                            <div class="equipment-legend">
                                <div class="legend-item">
                                    <div class="legend-dot dot-operational"></div>
                                    <span>Operational (<?php echo $equipmentStats['operational']; ?>)</span>
                                </div>
                                <div class="legend-item">
                                    <div class="legend-dot dot-maintenance"></div>
                                    <span>Maintenance (<?php echo $equipmentStats['maintenance']; ?>)</span>
                                </div>
                                <div class="legend-item">
                                    <div class="legend-dot dot-offline"></div>
                                    <span>Offline (<?php echo $equipmentStats['offline']; ?>)</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <script>
        // Function to generate PDF report using FPDF
        function generateReport() {
            // Show loading indicator
            const generateBtn = document.querySelector('.primary-button');
            const originalText = generateBtn.innerHTML;
            generateBtn.innerHTML = '<span>⏳</span> Generating Report...';
            generateBtn.disabled = true;
            
            // Create form for PDF generation
            const form = document.createElement('form');
            form.method = 'POST';
            form.action = 'generate_report.php';
            
            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = 'generate_report';
            input.value = '1';
            
            form.appendChild(input);
            document.body.appendChild(form);
            form.submit();
        }
        
        function runSystemBackup() {
            const backupBtn = document.querySelector('.secondary-button');
            const originalText = backupBtn.innerHTML;
            backupBtn.innerHTML = '<i class="bx bx-loader bx-spin"></i> Backing up...';
            backupBtn.disabled = true;
            
            fetch('system_backup.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({ action: 'backup' })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    alert('Backup completed successfully!');
                } else {
                    alert('Backup failed: ' + data.message);
                }
                backupBtn.innerHTML = originalText;
                backupBtn.disabled = false;
            })
            .catch(error => {
                console.error('Error:', error);
                alert('Backup failed. Please try again.');
                backupBtn.innerHTML = originalText;
                backupBtn.disabled = false;
            });
        }
        
        function refreshActivity() {
            const refreshBtn = document.querySelector('.secondary-button');
            const activityList = document.getElementById('user-activity-list');
            
            refreshBtn.innerHTML = '<i class="bx bx-loader bx-spin"></i> Refreshing...';
            refreshBtn.disabled = true;
            
            fetch('get_user_activity.php')
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        activityList.innerHTML = data.html;
                    }
                    refreshBtn.innerHTML = 'Refresh';
                    refreshBtn.disabled = false;
                })
                .catch(error => {
                    console.error('Error:', error);
                    refreshBtn.innerHTML = 'Refresh';
                    refreshBtn.disabled = false;
                });
        }
        
        function toggleSubmenu(id) {
            const submenu = document.getElementById(id);
            const arrow = document.querySelector(`#${id}`).previousElementSibling.querySelector('.dropdown-arrow');
            
            submenu.classList.toggle('active');
            arrow.classList.toggle('rotated');
        }
        
        document.querySelectorAll('.menu-item').forEach(item => {
            item.addEventListener('click', function(e) {
                if (!this.querySelector('.dropdown-arrow')) {
                    document.querySelectorAll('.menu-item').forEach(i => {
                        i.classList.remove('active');
                    });
                    this.classList.add('active');
                }
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
                localStorage.setItem('theme', 'dark');
            } else {
                themeIcon.className = 'bx bx-moon';
                themeText.textContent = 'Dark Mode';
                localStorage.setItem('theme', 'light');
            }
        });
        
        // Check for saved theme preference
        const savedTheme = localStorage.getItem('theme');
        if (savedTheme === 'dark') {
            document.body.classList.add('dark-mode');
            themeIcon.className = 'bx bx-sun';
            themeText.textContent = 'Light Mode';
        }
        
        // Animate chart bars on load
        document.addEventListener('DOMContentLoaded', function() {
            // Animate performance bar
            const performanceBar = document.getElementById('performance-bar');
            const performanceText = document.getElementById('performance-text');
            const equipmentFill = document.getElementById('equipment-fill');
            const equipmentValue = document.getElementById('equipment-value');
            
            // Set chart heights based on data
            const incidentsBar = document.getElementById('incidents-bar');
            const usersBar = document.getElementById('users-bar');
            const volunteersBar = document.getElementById('volunteers-bar');
            const resourcesBar = document.getElementById('resources-bar');
            const trainingBar = document.getElementById('training-bar');
            const inspectionsBar = document.getElementById('inspections-bar');
            
            // Animate performance bar
            setTimeout(() => {
                performanceBar.style.width = '<?php echo $performance_percentage; ?>%';
                performanceText.textContent = '<?php echo $performance_percentage; ?>% Optimal';
            }, 100);
            
            // Animate equipment status
            setTimeout(() => {
                const dashOffset = 502 - (502 * <?php echo $operational_percentage; ?> / 100);
                equipmentFill.style.strokeDashoffset = dashOffset;
                equipmentValue.textContent = '<?php echo $operational_percentage; ?>%';
            }, 300);
            
            // Animate chart bars with staggered delay
            const bars = [
                { element: incidentsBar, height: 65 },
                { element: usersBar, height: 45 },
                { element: volunteersBar, height: 80 },
                { element: resourcesBar, height: 90 },
                { element: trainingBar, height: 55 },
                { element: inspectionsBar, height: 70 }
            ];
            
            bars.forEach((bar, index) => {
                setTimeout(() => {
                    bar.element.style.height = bar.height + '%';
                }, 100 + (index * 100));
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
        
        // Search functionality
        const searchInput = document.querySelector('.search-input');
        searchInput.addEventListener('keypress', function(e) {
            if (e.key === 'Enter') {
                const query = this.value.trim();
                if (query) {
                    // Implement search functionality here
                    alert('Searching for: ' + query);
                    // You can redirect to a search results page or make an AJAX call
                }
            }
        });
    </script>
</body>
</html>