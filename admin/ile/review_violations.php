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

if (!$user || $user['role'] !== 'ADMIN') {
    header("Location: ../../login/unauthorized.php");
    exit();
}

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

// Get filter parameters
$filter_status = isset($_GET['status']) ? $_GET['status'] : 'pending';
$filter_severity = isset($_GET['severity']) ? $_GET['severity'] : '';
$search_query = isset($_GET['search']) ? $_GET['search'] : '';
$filter_barangay = isset($_GET['barangay']) ? $_GET['barangay'] : '';
$filter_date = isset($_GET['date']) ? $_GET['date'] : '';

// Get all violations with filters
function getViolations($pdo, $filter_status = 'pending', $filter_severity = '', $search_query = '', $filter_barangay = '', $filter_date = '') {
    $sql = "SELECT 
                iv.id,
                iv.violation_code,
                iv.violation_description,
                iv.severity,
                iv.section_violated,
                iv.fine_amount,
                iv.compliance_deadline,
                iv.status,
                iv.rectified_at,
                iv.rectified_evidence,
                iv.admin_notes,
                iv.created_at,
                ir.report_number,
                ir.inspection_date,
                ie.establishment_name,
                ie.establishment_type,
                ie.barangay,
                ie.address,
                ie.owner_name,
                ie.owner_contact,
                CONCAT(inspector.first_name, ' ', inspector.last_name) as inspector_name,
                CONCAT(reviewer.first_name, ' ', reviewer.last_name) as admin_reviewer_name
            FROM inspection_violations iv
            LEFT JOIN inspection_reports ir ON iv.inspection_id = ir.id
            LEFT JOIN inspection_establishments ie ON ir.establishment_id = ie.id
            LEFT JOIN users inspector ON ir.inspected_by = inspector.id
            LEFT JOIN users reviewer ON ir.admin_reviewed_by = reviewer.id
            WHERE 1=1";
    
    $params = [];
    
    // Apply status filter
    if ($filter_status !== 'all') {
        $sql .= " AND iv.status = ?";
        $params[] = $filter_status;
    }
    
    // Apply severity filter
    if (!empty($filter_severity)) {
        $sql .= " AND iv.severity = ?";
        $params[] = $filter_severity;
    }
    
    // Apply date filter
    if (!empty($filter_date)) {
        if ($filter_date === 'overdue') {
            $sql .= " AND iv.compliance_deadline < CURDATE() AND iv.status != 'rectified'";
        } elseif ($filter_date === 'today') {
            $sql .= " AND DATE(iv.created_at) = CURDATE()";
        } elseif ($filter_date === 'week') {
            $sql .= " AND iv.created_at >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)";
        } elseif ($filter_date === 'month') {
            $sql .= " AND iv.created_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)";
        }
    }
    
    // Apply barangay filter
    if (!empty($filter_barangay)) {
        $sql .= " AND ie.barangay LIKE ?";
        $params[] = "%$filter_barangay%";
    }
    
    // Apply search query
    if (!empty($search_query)) {
        $sql .= " AND (
                    iv.violation_code LIKE ? OR 
                    iv.violation_description LIKE ? OR 
                    ie.establishment_name LIKE ? OR 
                    ie.owner_name LIKE ? OR 
                    ie.address LIKE ? OR 
                    ie.barangay LIKE ? OR
                    ir.report_number LIKE ?
                )";
        $search_param = "%$search_query%";
        $params = array_merge($params, [
            $search_param, $search_param, $search_param, $search_param,
            $search_param, $search_param, $search_param
        ]);
    }
    
    $sql .= " ORDER BY 
                CASE WHEN iv.status = 'overdue' THEN 1
                     WHEN iv.status = 'pending' THEN 2
                     WHEN iv.status = 'rectified' THEN 3
                     ELSE 4 END,
                CASE WHEN iv.severity = 'critical' THEN 1
                     WHEN iv.severity = 'major' THEN 2
                     WHEN iv.severity = 'minor' THEN 3
                     ELSE 4 END,
                iv.compliance_deadline ASC,
                iv.created_at DESC";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Get violation statistics
function getViolationStats($pdo) {
    $sql = "SELECT 
                COUNT(*) as total,
                SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending,
                SUM(CASE WHEN status = 'rectified' THEN 1 ELSE 0 END) as rectified,
                SUM(CASE WHEN status = 'overdue' THEN 1 ELSE 0 END) as overdue,
                SUM(CASE WHEN status = 'escalated' THEN 1 ELSE 0 END) as escalated,
                SUM(CASE WHEN status = 'waived' THEN 1 ELSE 0 END) as waived,
                SUM(CASE WHEN severity = 'critical' THEN 1 ELSE 0 END) as critical,
                SUM(CASE WHEN severity = 'major' THEN 1 ELSE 0 END) as major,
                SUM(CASE WHEN severity = 'minor' THEN 1 ELSE 0 END) as minor,
                SUM(CASE WHEN compliance_deadline < CURDATE() AND status != 'rectified' THEN 1 ELSE 0 END) as past_deadline
            FROM inspection_violations";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute();
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    
    $stats = [
        'total' => 0,
        'pending' => 0,
        'rectified' => 0,
        'overdue' => 0,
        'escalated' => 0,
        'waived' => 0,
        'critical' => 0,
        'major' => 0,
        'minor' => 0,
        'past_deadline' => 0
    ];
    
    if ($result) {
        $stats = array_merge($stats, $result);
    }
    
    return $stats;
}

// Get all barangays for filtering
function getBarangays($pdo) {
    $sql = "SELECT DISTINCT barangay FROM inspection_establishments WHERE barangay IS NOT NULL AND barangay != '' ORDER BY barangay";
    $stmt = $pdo->prepare($sql);
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_COLUMN, 0);
}

// Get data for filters
$barangays = getBarangays($pdo);

// Get violations based on filters
$violations = getViolations($pdo, $filter_status, $filter_severity, $search_query, $filter_barangay, $filter_date);
$stats = getViolationStats($pdo);

// Date filter options
$date_options = [
    '' => 'All Dates',
    'today' => 'Today',
    'week' => 'Last 7 Days',
    'month' => 'Last 30 Days',
    'overdue' => 'Overdue'
];

// Status options
$status_options = [
    'all' => 'All Violations',
    'pending' => 'Pending',
    'rectified' => 'Rectified',
    'overdue' => 'Overdue',
    'escalated' => 'Escalated',
    'waived' => 'Waived'
];

// Severity options
$severity_options = [
    '' => 'All Severities',
    'critical' => 'Critical',
    'major' => 'Major',
    'minor' => 'Minor'
];

// Status colors
$status_colors = [
    'pending' => '#f59e0b',
    'rectified' => '#10b981',
    'overdue' => '#dc2626',
    'escalated' => '#8b5cf6',
    'waived' => '#6b7280'
];

// Severity colors
$severity_colors = [
    'critical' => '#7c2d12',
    'major' => '#dc2626',
    'minor' => '#f59e0b'
];

// Format date helper
function formatDate($date) {
    if (!$date) return 'N/A';
    return date('M j, Y', strtotime($date));
}

// Format currency
function formatCurrency($amount) {
    if (!$amount) return 'N/A';
    return '₱' . number_format($amount, 2);
}

// Get status badge HTML
function getStatusBadge($status) {
    global $status_colors;
    $status = strtolower($status);
    $color = $status_colors[$status] ?? '#6b7280';
    $text = ucfirst($status);
    
    return <<<HTML
        <span class="status-badge" style="background: rgba(${hexToRgb($color)}, 0.1); color: {$color}; border-color: rgba(${hexToRgb($color)}, 0.3);">
            {$text}
        </span>
    HTML;
}

// Get severity badge HTML
function getSeverityBadge($severity) {
    global $severity_colors;
    $severity = strtolower($severity);
    $color = $severity_colors[$severity] ?? '#6b7280';
    $text = ucfirst($severity);
    
    return <<<HTML
        <span class="severity-badge" style="background: rgba(${hexToRgb($color)}, 0.1); color: {$color}; border-color: rgba(${hexToRgb($color)}, 0.3);">
            {$text}
        </span>
    HTML;
}

// Helper function to convert hex to RGB
function hexToRgb($hex) {
    $hex = str_replace('#', '', $hex);
    if(strlen($hex) == 3) {
        $r = hexdec(substr($hex,0,1).substr($hex,0,1));
        $g = hexdec(substr($hex,1,1).substr($hex,1,1));
        $b = hexdec(substr($hex,2,1).substr($hex,2,1));
    } else {
        $r = hexdec(substr($hex,0,2));
        $g = hexdec(substr($hex,2,2));
        $b = hexdec(substr($hex,4,2));
    }
    return "$r, $g, $b";
}

// Check if date is overdue
function isOverdue($deadline, $status) {
    if (!$deadline || $status === 'rectified') return false;
    return strtotime($deadline) < time();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Review Violations - Admin - FRSM</title>
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
            --indigo: #6366f1;
            --pink: #ec4899;
            --teal: #14b8a6;
            --orange: #f97316;
            --amber: #f59e0b;
            --lime: #84cc16;
            --emerald: #10b981;
            --cyan: #06b6d4;
            --light-blue: #0ea5e9;
            --violet: #8b5cf6;
            --fuchsia: #d946ef;
            --rose: #f43f5e;
            --warm-gray: #78716c;
            
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

        /* Enhanced Stats Container */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        
        .stat-card {
            background: linear-gradient(135deg, var(--card-bg) 0%, #ffffff 100%);
            border: 1px solid var(--border-color);
            border-radius: 16px;
            padding: 24px;
            display: flex;
            flex-direction: column;
            gap: 12px;
            transition: all 0.3s ease;
            cursor: pointer;
            position: relative;
            overflow: hidden;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
        }
        
        .dark-mode .stat-card {
            background: linear-gradient(135deg, var(--card-bg) 0%, #2d3748 100%);
        }
        
        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.1);
            border-color: var(--primary-color);
        }
        
        .stat-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        
        .stat-icon-container {
            width: 48px;
            height: 48px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
        }
        
        .stat-card[data-type="total"] .stat-icon-container {
            background: rgba(59, 130, 246, 0.1);
            color: var(--info);
        }
        
        .stat-card[data-type="pending"] .stat-icon-container {
            background: rgba(245, 158, 11, 0.1);
            color: var(--warning);
        }
        
        .stat-card[data-type="rectified"] .stat-icon-container {
            background: rgba(16, 185, 129, 0.1);
            color: var(--success);
        }
        
        .stat-card[data-type="overdue"] .stat-icon-container {
            background: rgba(220, 38, 38, 0.1);
            color: var(--danger);
        }
        
        .stat-card[data-type="critical"] .stat-icon-container {
            background: rgba(124, 45, 18, 0.1);
            color: #7c2d12;
        }
        
        .stat-card[data-type="major"] .stat-icon-container {
            background: rgba(220, 38, 38, 0.1);
            color: var(--danger);
        }
        
        .stat-card[data-type="minor"] .stat-icon-container {
            background: rgba(245, 158, 11, 0.1);
            color: var(--warning);
        }
        
        .stat-card[data-type="past_deadline"] .stat-icon-container {
            background: rgba(124, 45, 18, 0.1);
            color: #7c2d12;
        }
        
        .stat-info {
            display: flex;
            flex-direction: column;
            gap: 4px;
        }
        
        .stat-value {
            font-size: 32px;
            font-weight: 800;
            line-height: 1;
        }
        
        .stat-label {
            font-size: 14px;
            color: var(--text-light);
        }
        
        .stat-trend {
            font-size: 12px;
            display: flex;
            align-items: center;
            gap: 4px;
            color: var(--success);
        }
        
        .stat-trend.down {
            color: var(--danger);
        }

        /* Filter Tabs Container */
        .filter-tabs-container {
            background: var(--card-bg);
            border-radius: 16px;
            padding: 20px;
            margin-bottom: 20px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
        }

        .filter-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }

        .filter-title {
            font-size: 20px;
            font-weight: 700;
            color: var(--text-color);
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .filter-title i {
            color: var(--primary-color);
        }

        .filter-tabs {
            display: flex;
            gap: 8px;
            margin-bottom: 20px;
            flex-wrap: wrap;
        }

        .filter-tab {
            padding: 10px 20px;
            border-radius: 10px;
            background: var(--gray-100);
            border: 2px solid transparent;
            color: var(--text-color);
            cursor: pointer;
            transition: all 0.3s ease;
            font-weight: 500;
            display: flex;
            align-items: center;
            gap: 8px;
            text-decoration: none;
        }

        .dark-mode .filter-tab {
            background: var(--gray-800);
        }

        .filter-tab.active {
            background: var(--primary-color);
            color: white;
            border-color: var(--primary-color);
        }

        .filter-tab:hover:not(.active) {
            background: var(--gray-200);
            text-decoration: none;
        }

        .dark-mode .filter-tab:hover:not(.active) {
            background: var(--gray-700);
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

        /* Advanced Filters */
        .filters-container {
            background: var(--card-bg);
            border-radius: 16px;
            padding: 24px;
            margin-bottom: 30px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
        }

        .filter-section {
            margin-bottom: 24px;
        }

        .filter-section-title {
            font-size: 16px;
            font-weight: 600;
            color: var(--text-color);
            margin-bottom: 16px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .filter-section-title i {
            color: var(--primary-color);
        }

        .filter-row {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
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
            display: flex;
            align-items: center;
            gap: 6px;
        }
        
        .filter-label i {
            color: var(--primary-color);
            font-size: 16px;
        }
        
        .dark-mode .filter-label {
            color: var(--gray-300);
        }
        
        .filter-select, .filter-input {
            padding: 12px 16px;
            border-radius: 10px;
            border: 1px solid var(--border-color);
            background: var(--card-bg);
            color: var(--text-color);
            font-size: 14px;
            width: 100%;
            transition: all 0.3s ease;
        }
        
        .filter-select:focus, .filter-input:focus {
            outline: none;
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(220, 38, 38, 0.1);
        }

        .filter-actions {
            display: flex;
            gap: 12px;
            justify-content: flex-end;
            margin-top: 20px;
            padding-top: 20px;
            border-top: 1px solid var(--border-color);
        }

        .filter-button {
            padding: 12px 24px;
            border-radius: 10px;
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            color: white;
            border: none;
            cursor: pointer;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 8px;
            transition: all 0.3s ease;
            text-decoration: none;
            justify-content: center;
        }

        .filter-button:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(220, 38, 38, 0.3);
            text-decoration: none;
        }

        .clear-filters {
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            color: var(--text-color);
        }

        .clear-filters:hover {
            background: var(--gray-100);
        }

        .dark-mode .clear-filters:hover {
            background: var(--gray-800);
        }

        /* Enhanced Table Styles */
        .violations-table-container {
            background: var(--card-bg);
            border-radius: 16px;
            overflow: hidden;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
        }

        .table-header {
            display: grid;
            grid-template-columns: 80px 150px 150px 100px 120px 120px 120px 100px 150px;
            gap: 15px;
            padding: 20px;
            background: rgba(220, 38, 38, 0.03);
            border-bottom: 1px solid var(--border-color);
            font-weight: 600;
            color: var(--text-color);
            font-size: 13px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .table-row {
            display: grid;
            grid-template-columns: 80px 150px 150px 100px 120px 120px 120px 100px 150px;
            gap: 15px;
            padding: 20px;
            border-bottom: 1px solid var(--border-color);
            transition: all 0.3s ease;
            align-items: center;
            background: var(--card-bg);
        }
        
        .table-row:hover {
            background: rgba(220, 38, 38, 0.03);
        }
        
        .table-row.overdue {
            background: rgba(124, 45, 18, 0.05);
            border-left: 4px solid #7c2d12;
        }
        
        .table-row.critical {
            background: rgba(124, 45, 18, 0.03);
        }
        
        .table-row.major {
            background: rgba(220, 38, 38, 0.03);
        }
        
        .dark-mode .table-row.overdue {
            background: rgba(124, 45, 18, 0.1);
        }
        
        .dark-mode .table-row.critical {
            background: rgba(124, 45, 18, 0.07);
        }
        
        .dark-mode .table-row.major {
            background: rgba(220, 38, 38, 0.07);
        }
        
        .table-row:last-child {
            border-bottom: none;
        }
        
        .table-cell {
            display: flex;
            flex-direction: column;
            gap: 4px;
            color: var(--text-color);
            min-height: 40px;
            justify-content: center;
        }
        
        .violation-code {
            font-weight: 700;
            color: var(--primary-color);
            font-size: 14px;
        }
        
        .establishment-name {
            font-weight: 600;
            color: var(--text-color);
            font-size: 14px;
        }
        
        .report-number {
            font-weight: 600;
            color: var(--info);
            font-size: 13px;
        }
        
        .violation-description {
            font-size: 13px;
            color: var(--text-light);
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
            overflow: hidden;
            line-height: 1.4;
        }
        
        /* Status Badge */
        .status-badge, .severity-badge {
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            border: 1px solid;
            border-color: inherit;
            width: fit-content;
            white-space: nowrap;
        }

        /* Enhanced Action Buttons */
        .action-buttons {
            display: flex;
            gap: 6px;
            flex-wrap: wrap;
        }
        
        .action-button {
            padding: 8px 12px;
            border-radius: 8px;
            font-weight: 600;
            border: none;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            transition: all 0.3s ease;
            font-size: 12px;
            min-width: 70px;
            position: relative;
            overflow: hidden;
        }
        
        .action-button::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.2), transparent);
            transition: left 0.5s ease;
        }
        
        .action-button:hover::before {
            left: 100%;
        }
        
        .view-button {
            background: linear-gradient(135deg, rgba(59, 130, 246, 0.1), rgba(59, 130, 246, 0.2));
            color: var(--info);
            border: 1px solid rgba(59, 130, 246, 0.3);
        }
        
        .view-button:hover {
            background: var(--info);
            color: white;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(59, 130, 246, 0.3);
        }
        
        .rectify-button {
            background: linear-gradient(135deg, rgba(16, 185, 129, 0.1), rgba(16, 185, 129, 0.2));
            color: var(--success);
            border: 1px solid rgba(16, 185, 129, 0.3);
        }
        
        .rectify-button:hover {
            background: var(--success);
            color: white;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(16, 185, 129, 0.3);
        }
        
        .escalate-button {
            background: linear-gradient(135deg, rgba(139, 92, 246, 0.1), rgba(139, 92, 246, 0.2));
            color: var(--purple);
            border: 1px solid rgba(139, 92, 246, 0.3);
        }
        
        .escalate-button:hover {
            background: var(--purple);
            color: white;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(139, 92, 246, 0.3);
        }
        
        .waive-button {
            background: linear-gradient(135deg, rgba(107, 114, 128, 0.1), rgba(107, 114, 128, 0.2));
            color: var(--gray-500);
            border: 1px solid rgba(107, 114, 128, 0.3);
        }
        
        .waive-button:hover {
            background: var(--gray-500);
            color: white;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(107, 114, 128, 0.3);
        }
        
        .edit-button {
            background: linear-gradient(135deg, rgba(245, 158, 11, 0.1), rgba(245, 158, 11, 0.2));
            color: var(--warning);
            border: 1px solid rgba(245, 158, 11, 0.3);
        }
        
        .edit-button:hover {
            background: var(--warning);
            color: white;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(245, 158, 11, 0.3);
        }

        .no-violations {
            text-align: center;
            padding: 60px 20px;
            color: var(--text-light);
            grid-column: 1 / -1;
        }
        
        .no-violations-icon {
            font-size: 64px;
            margin-bottom: 16px;
            color: var(--text-light);
            opacity: 0.5;
        }

        /* Quick Actions Panel */
        .quick-actions {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        
        .quick-action-card {
            background: linear-gradient(135deg, var(--card-bg), #ffffff);
            border: 1px solid var(--border-color);
            border-radius: 16px;
            padding: 24px;
            display: flex;
            align-items: center;
            gap: 16px;
            transition: all 0.3s ease;
            cursor: pointer;
            text-decoration: none;
            color: var(--text-color);
        }
        
        .dark-mode .quick-action-card {
            background: linear-gradient(135deg, var(--card-bg), #2d3748);
        }
        
        .quick-action-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.1);
            text-decoration: none;
            color: var(--text-color);
            border-color: var(--primary-color);
        }
        
        .action-icon {
            width: 56px;
            height: 56px;
            border-radius: 14px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
        }
        
        .quick-action-card:nth-child(1) .action-icon {
            background: linear-gradient(135deg, rgba(245, 158, 11, 0.1), rgba(245, 158, 11, 0.2));
            color: var(--warning);
        }
        
        .quick-action-card:nth-child(2) .action-icon {
            background: linear-gradient(135deg, rgba(220, 38, 38, 0.1), rgba(220, 38, 38, 0.2));
            color: var(--danger);
        }
        
        .quick-action-card:nth-child(3) .action-icon {
            background: linear-gradient(135deg, rgba(59, 130, 246, 0.1), rgba(59, 130, 246, 0.2));
            color: var(--info);
        }
        
        .quick-action-card:nth-child(4) .action-icon {
            background: linear-gradient(135deg, rgba(16, 185, 129, 0.1), rgba(16, 185, 129, 0.2));
            color: var(--success);
        }
        
        .action-content {
            flex: 1;
        }
        
        .action-title {
            font-size: 16px;
            font-weight: 600;
            margin-bottom: 4px;
        }
        
        .action-description {
            font-size: 13px;
            color: var(--text-light);
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
            backdrop-filter: blur(5px);
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
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
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
            position: sticky;
            top: 0;
            z-index: 10;
            backdrop-filter: blur(10px);
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
            width: 40px;
            height: 40px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 50%;
        }
        
        .modal-close:hover {
            background: var(--gray-100);
            color: var(--danger);
        }
        
        .dark-mode .modal-close:hover {
            background: var(--gray-800);
        }
        
        .modal-body {
            padding: 24px;
        }
        
        .form-group {
            margin-bottom: 20px;
        }
        
        .form-label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: var(--text-color);
        }
        
        .form-select, .form-textarea, .form-input, .form-file {
            width: 100%;
            padding: 12px 16px;
            border-radius: 10px;
            border: 1px solid var(--border-color);
            background: var(--card-bg);
            color: var(--text-color);
            font-size: 14px;
            transition: all 0.3s ease;
        }
        
        .form-select:focus, .form-textarea:focus, .form-input:focus, .form-file:focus {
            outline: none;
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(220, 38, 38, 0.1);
        }
        
        .form-textarea {
            min-height: 100px;
            resize: vertical;
        }
        
        .form-file {
            padding: 10px;
        }
        
        .modal-actions {
            display: flex;
            gap: 12px;
            justify-content: flex-end;
            margin-top: 24px;
            position: sticky;
            bottom: 0;
            background: var(--card-bg);
            padding: 16px 0;
            border-top: 1px solid var(--border-color);
            backdrop-filter: blur(10px);
        }
        
        .btn {
            padding: 12px 24px;
            border-radius: 10px;
            font-weight: 600;
            border: none;
            cursor: pointer;
            transition: all 0.3s ease;
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
        
        /* Evidence Preview */
        .evidence-preview {
            margin-top: 20px;
            padding: 20px;
            background: rgba(59, 130, 246, 0.05);
            border-radius: 12px;
            border: 1px dashed rgba(59, 130, 246, 0.3);
        }
        
        .evidence-preview img {
            max-width: 200px;
            max-height: 200px;
            border-radius: 8px;
            border: 1px solid var(--border-color);
        }

        /* Responsive Design */
        @media (max-width: 1400px) {
            .table-header, .table-row {
                grid-template-columns: 70px 140px 140px 90px 110px 110px 110px 90px 130px;
                gap: 12px;
                padding: 16px;
            }
        }

        @media (max-width: 1200px) {
            .table-header, .table-row {
                grid-template-columns: 60px 120px 120px 80px 100px 100px 100px 80px 120px;
                gap: 10px;
                padding: 14px;
            }
        }

        @media (max-width: 992px) {
            .table-header {
                display: none;
            }
            
            .table-row {
                grid-template-columns: 1fr;
                gap: 16px;
                padding: 20px;
                border: 1px solid var(--border-color);
                border-radius: 12px;
                margin-bottom: 12px;
            }
            
            .table-cell {
                display: grid;
                grid-template-columns: 120px 1fr;
                gap: 16px;
                align-items: start;
                border-bottom: 1px solid var(--border-color);
                padding-bottom: 12px;
            }
            
            .table-cell:last-child {
                border-bottom: none;
                padding-bottom: 0;
            }
            
            .table-cell::before {
                content: attr(data-label);
                font-weight: 600;
                color: var(--text-light);
                font-size: 13px;
            }
            
            .table-cell .action-buttons {
                grid-column: 1 / -1;
                display: flex;
                flex-wrap: wrap;
                gap: 8px;
                justify-content: center;
                margin-top: 10px;
            }
            
            .table-cell .action-button {
                flex: 1;
                min-width: 120px;
            }
            
            .filter-row {
                grid-template-columns: 1fr;
                gap: 16px;
            }
            
            .filter-actions {
                flex-direction: column;
            }
            
            .filter-button, .clear-filters {
                width: 100%;
                justify-content: center;
            }
            
            .dashboard-header {
                padding: 40px 25px 30px;
            }
            
            .dashboard-title {
                font-size: 32px;
            }
            
            .content-container {
                padding: 0 25px 30px;
            }
            
            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }

            .filter-tabs {
                flex-direction: column;
            }

            .modal {
                width: 95%;
                margin: 10px;
            }

            .quick-actions {
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
            
            .stats-grid {
                grid-template-columns: 1fr;
                gap: 15px;
            }
            
            .stat-card {
                padding: 20px;
            }
            
            .filters-container {
                padding: 20px;
            }
            
            .action-buttons {
                flex-direction: column;
            }
            
            .action-button {
                width: 100%;
            }
        }

        .violations-table-container {
            max-height: 600px;
            overflow-y: auto;
        }

        .violations-table-container::-webkit-scrollbar {
            width: 6px;
        }
        
        .violations-table-container::-webkit-scrollbar-track {
            background: var(--gray-100);
            border-radius: 3px;
        }
        
        .violations-table-container::-webkit-scrollbar-thumb {
            background: var(--gray-400);
            border-radius: 3px;
        }
        
        .violations-table-container::-webkit-scrollbar-thumb:hover {
            background: var(--gray-500);
        }
        
        .dark-mode .violations-table-container::-webkit-scrollbar-track {
            background: var(--gray-800);
        }
        
        .dark-mode .violations-table-container::-webkit-scrollbar-thumb {
            background: var(--gray-600);
        }
        
        .dark-mode .violations-table-container::-webkit-scrollbar-thumb:hover {
            background: var(--gray-500);
        }

        .modal::-webkit-scrollbar {
            width: 6px;
        }
        
        .modal::-webkit-scrollbar-track {
            background: var(--card-bg);
            border-radius: 3px;
        }
        
        .modal::-webkit-scrollbar-thumb {
            background: var(--border-color);
            border-radius: 3px;
        }
        
        .modal::-webkit-scrollbar-thumb:hover {
            background: var(--gray-400);
        }

        /* Animation for table rows */
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

        .table-row {
            animation: fadeIn 0.3s ease forwards;
        }

        /* Overdue warning */
        .overdue-warning {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 4px 8px;
            background: rgba(124, 45, 18, 0.1);
            color: #7c2d12;
            border-radius: 6px;
            font-size: 11px;
            font-weight: 600;
            margin-top: 4px;
        }
        
        .deadline-text {
            font-size: 12px;
            color: var(--text-light);
            margin-top: 2px;
        }
        
        .deadline-overdue {
            color: #7c2d12;
            font-weight: 600;
        }
        
        .deadline-urgent {
            color: var(--danger);
            font-weight: 600;
        }
        
        .deadline-normal {
            color: var(--success);
            font-weight: 600;
        }

        /* Fine amount styling */
        .fine-amount {
            font-weight: 700;
            color: var(--danger);
            font-size: 14px;
        }
    </style>
</head>
<body>
    <!-- Violation Details Modal -->
    <div class="modal-overlay" id="details-modal">
        <div class="modal">
            <div class="modal-header">
                <h2 class="modal-title">Violation Details</h2>
                <button class="modal-close" id="details-modal-close">&times;</button>
            </div>
            <div class="modal-body">
                <div id="details-content">
                    <!-- Details will be loaded here via JavaScript -->
                </div>
                <div class="modal-actions">
                    <button type="button" class="btn btn-secondary" id="close-details">Close</button>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Mark as Rectified Modal -->
    <div class="modal-overlay" id="rectify-modal">
        <div class="modal">
            <div class="modal-header">
                <h2 class="modal-title">Mark Violation as Rectified</h2>
                <button class="modal-close" id="rectify-modal-close">&times;</button>
            </div>
            <div class="modal-body">
                <form id="rectify-form" enctype="multipart/form-data">
                    <input type="hidden" id="rectify-violation-id" name="violation_id">
                    
                    <div class="form-group">
                        <label class="form-label" for="rectify_date">Rectification Date</label>
                        <input type="date" class="form-input" id="rectify_date" name="rectify_date" required value="<?php echo date('Y-m-d'); ?>">
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label" for="rectify_notes">Rectification Details</label>
                        <textarea class="form-textarea" id="rectify_notes" name="rectify_notes" placeholder="Describe how the violation was rectified..." required></textarea>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label" for="rectify_evidence">Evidence of Rectification</label>
                        <input type="file" class="form-file" id="rectify_evidence" name="rectify_evidence" accept="image/*,.pdf,.doc,.docx">
                        <small style="color: var(--text-light); font-size: 12px;">Upload photos, documents, or other evidence showing the violation has been fixed</small>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label" for="admin_notes">Admin Notes (Optional)</label>
                        <textarea class="form-textarea" id="admin_notes" name="admin_notes" placeholder="Any additional admin notes..."></textarea>
                    </div>
                    
                    <div class="modal-actions">
                        <button type="button" class="btn btn-secondary" id="cancel-rectify">Cancel</button>
                        <button type="submit" class="btn btn-primary">Mark as Rectified</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Escalate Violation Modal -->
    <div class="modal-overlay" id="escalate-modal">
        <div class="modal">
            <div class="modal-header">
                <h2 class="modal-title">Escalate Violation</h2>
                <button class="modal-close" id="escalate-modal-close">&times;</button>
            </div>
            <div class="modal-body">
                <form id="escalate-form">
                    <input type="hidden" id="escalate-violation-id" name="violation_id">
                    
                    <div class="form-group">
                        <label class="form-label" for="escalate_reason">Escalation Reason</label>
                        <select class="form-select" id="escalate_reason" name="escalate_reason" required>
                            <option value="">Select a reason</option>
                            <option value="non_compliance">Non-compliance with previous notices</option>
                            <option value="repeat_offender">Repeat offender</option>
                            <option value="serious_risk">Presents serious risk to public safety</option>
                            <option value="legal_action">Legal action required</option>
                            <option value="other">Other</option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label" for="escalate_notes">Escalation Details</label>
                        <textarea class="form-textarea" id="escalate_notes" name="escalate_notes" placeholder="Provide details about why this violation needs to be escalated..." required></textarea>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label" for="escalate_action">Recommended Action</label>
                        <select class="form-select" id="escalate_action" name="escalate_action" required>
                            <option value="">Select recommended action</option>
                            <option value="legal_notice">Issue legal notice</option>
                            <option value="fine_increase">Increase fine amount</option>
                            <option value="temporary_closure">Recommend temporary closure</option>
                            <option value="legal_proceedings">Initiate legal proceedings</option>
                            <option value="other_action">Other action</option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label" for="new_deadline">New Compliance Deadline (Optional)</label>
                        <input type="date" class="form-input" id="new_deadline" name="new_deadline">
                    </div>
                    
                    <div class="modal-actions">
                        <button type="button" class="btn btn-secondary" id="cancel-escalate">Cancel</button>
                        <button type="submit" class="btn btn-primary">Escalate Violation</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Waive Violation Modal -->
    <div class="modal-overlay" id="waive-modal">
        <div class="modal">
            <div class="modal-header">
                <h2 class="modal-title">Waive Violation</h2>
                <button class="modal-close" id="waive-modal-close">&times;</button>
            </div>
            <div class="modal-body">
                <form id="waive-form">
                    <input type="hidden" id="waive-violation-id" name="violation_id">
                    
                    <div class="form-group">
                        <label class="form-label" for="waive_reason">Waiver Reason</label>
                        <select class="form-select" id="waive_reason" name="waive_reason" required>
                            <option value="">Select a reason</option>
                            <option value="false_report">False or erroneous report</option>
                            <option value="minor_issue">Minor issue not requiring action</option>
                            <option value="already_resolved">Issue already resolved</option>
                            <option value="special_circumstances">Special circumstances warrant waiver</option>
                            <option value="other">Other</option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label" for="waive_notes">Waiver Details</label>
                        <textarea class="form-textarea" id="waive_notes" name="waive_notes" placeholder="Provide justification for waiving this violation..." required></textarea>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label" for="waive_fine">Waive Fine Amount?</label>
                        <select class="form-select" id="waive_fine" name="waive_fine" required>
                            <option value="1">Yes, waive the fine</option>
                            <option value="0">No, maintain the fine</option>
                        </select>
                    </div>
                    
                    <div class="modal-actions">
                        <button type="button" class="btn btn-secondary" id="cancel-waive">Cancel</button>
                        <button type="submit" class="btn btn-primary">Waive Violation</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Edit Violation Modal -->
    <div class="modal-overlay" id="edit-modal">
        <div class="modal">
            <div class="modal-header">
                <h2 class="modal-title">Edit Violation Details</h2>
                <button class="modal-close" id="edit-modal-close">&times;</button>
            </div>
            <div class="modal-body">
                <form id="edit-form">
                    <input type="hidden" id="edit-violation-id" name="violation_id">
                    
                    <div class="form-group">
                        <label class="form-label" for="edit_severity">Severity Level</label>
                        <select class="form-select" id="edit_severity" name="severity" required>
                            <option value="minor">Minor</option>
                            <option value="major">Major</option>
                            <option value="critical">Critical</option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label" for="edit_fine_amount">Fine Amount (₱)</label>
                        <input type="number" class="form-input" id="edit_fine_amount" name="fine_amount" min="0" step="0.01" placeholder="0.00">
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label" for="edit_compliance_deadline">Compliance Deadline</label>
                        <input type="date" class="form-input" id="edit_compliance_deadline" name="compliance_deadline">
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label" for="edit_admin_notes">Admin Notes</label>
                        <textarea class="form-textarea" id="edit_admin_notes" name="admin_notes" placeholder="Update admin notes for this violation..."></textarea>
                    </div>
                    
                    <div class="modal-actions">
                        <button type="button" class="btn btn-secondary" id="cancel-edit">Cancel</button>
                        <button type="submit" class="btn btn-primary">Update Violation</button>
                    </div>
                </form>
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
                    <a href="../admin/dashboard.php" class="menu-item">
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
                        <a href="manage_users.php" class="submenu-item">Manage Users</a>
                        <a href="role_control.php" class="submenu-item">Role Control</a>
                        <a href="audit_logs.php" class="submenu-item">Audit & Activity Logs</a>
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
                        <a href="../volunteer/review_data.php" class="submenu-item">Review Data</a>
                        <a href="../volunteer/approve_applications.php" class="submenu-item">Approve Applications</a>
                        <a href="../volunteer/assign_volunteers.php" class="submenu-item">Assign Volunteers</a>
                        <a href="../volunteer/view_availability.php" class="submenu-item">View Availability</a>
                        <a href="../volunteer/remove_volunteers.php" class="submenu-item">Remove Volunteers</a>
                        <a href="../volunteer/toggle_volunteer_registration.php" class="submenu-item">Toggle Volunteer Registration Access</a>
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
                        <a href="#" class="submenu-item">View Equipment</a>
                        <a href="#" class="submenu-item">Approve Maintenance</a>
                        <a href="#" class="submenu-item">Approve Resources</a>
                        <a href="#" class="submenu-item">Review Deployment</a>
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
                       <a href="view_shifts.php" class="submenu-item">View Shifts</a>
                        <a href="create_schedule.php" class="submenu-item">Create Schedule</a>
                          <a href="confirm_availability.php" class="submenu-item">Confirm Availability</a>
                        <a href="request_change.php" class="submenu-item">Request Change</a>
                        <a href="monitor_attendance.php" class="submenu-item">Monitor Attendance</a>
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
                        <a href="../tc/approve_completions.php" class="submenu-item">Approve Completions</a>
                        <a href="../tc/view_training_records.php" class="submenu-item">View Records</a>
                        <a href="../tc/assign_training.php" class="submenu-item">Assign Training</a>
                        <a href="../tc/track_expiry.php" class="submenu-item">Track Expiry</a>
                    </div>
                    
                    <!-- Inspection Logs for Establishments -->
                    <div class="menu-item active" onclick="toggleSubmenu('inspection-management')">
                        <div class="icon-box icon-bg-cyan">
                            <i class='bx bxs-check-shield icon-cyan'></i>
                        </div>
                        <span class="font-medium">Inspection Management</span>
                        <svg class="dropdown-arrow menu-icon rotated" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                        </svg>
                    </div>
                    <div id="inspection-management" class="submenu active">
                        <a href="../ile/approve_reports.php" class="submenu-item">Approve Reports</a>
                        <a href="../ile/review_violations.php" class="submenu-item active">Review Violations</a>
                        <a href="../ile/issue_certificates.php" class="submenu-item">Issue Certificates</a>
                        <a href="../ile/track_follow_up.php" class="submenu-item">Track Follow-Up</a>
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
                            <input type="text" placeholder="Search violations..." class="search-input" id="search-input">
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
                                <img src="../../img/<?php echo $avatar; ?>" alt="User" class="user-avatar">
                            <?php else: ?>
                                <div class="user-avatar" style="background: linear-gradient(135deg, var(--primary-color), var(--secondary-color)); color: white; display: flex; align-items: center; justify-content: center; font-weight: bold; border-radius: 50%; width: 40px; height: 40px;">
                                    <?php echo strtoupper(substr($full_name, 0, 1)); ?>
                                </div>
                            <?php endif; ?>
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
                        <h1 class="dashboard-title">Review Violations</h1>
                        <p class="dashboard-subtitle">Admin Panel - Review and process establishment inspection violations</p>
                    </div>
                </div>
                
                <!-- Content Container -->
                <div class="content-container">
                    <!-- Quick Actions -->
                    <div class="quick-actions">
                        <a href="#" class="quick-action-card" onclick="viewOverdueViolations()">
                            <div class="action-icon">
                                <i class='bx bxs-error-circle'></i>
                            </div>
                            <div class="action-content">
                                <div class="action-title">Overdue Violations</div>
                                <div class="action-description">View violations past their compliance deadline</div>
                            </div>
                        </a>
                        <a href="#" class="quick-action-card" onclick="viewCriticalViolations()">
                            <div class="action-icon">
                                <i class='bx bxs-alarm-exclamation'></i>
                            </div>
                            <div class="action-content">
                                <div class="action-title">Critical Violations</div>
                                <div class="action-description">Review critical severity violations</div>
                            </div>
                        </a>
                        <a href="#" class="quick-action-card" onclick="generateViolationReport()">
                            <div class="action-icon">
                                <i class='bx bxs-report'></i>
                            </div>
                            <div class="action-content">
                                <div class="action-title">Generate Report</div>
                                <div class="action-description">Create violation summary report</div>
                            </div>
                        </a>
                        <a href="#" class="quick-action-card" onclick="showBulkActions()">
                            <div class="action-icon">
                                <i class='bx bxs-bolt-circle'></i>
                            </div>
                            <div class="action-content">
                                <div class="action-title">Bulk Actions</div>
                                <div class="action-description">Process multiple violations at once</div>
                            </div>
                        </a>
                    </div>
                    
                    <!-- Statistics Cards -->
                    <div class="stats-grid">
                        <div class="stat-card" data-type="total" onclick="filterByStatus('all')">
                            <div class="stat-header">
                                <div class="stat-icon-container">
                                    <i class='bx bxs-error'></i>
                                </div>
                                <div class="stat-trend">
                                    <i class='bx bx-up-arrow-alt'></i>
                                    +12%
                                </div>
                            </div>
                            <div class="stat-value"><?php echo $stats['total']; ?></div>
                            <div class="stat-label">Total Violations</div>
                        </div>
                        <div class="stat-card" data-type="pending" onclick="filterByStatus('pending')">
                            <div class="stat-header">
                                <div class="stat-icon-container">
                                    <i class='bx bxs-time-five'></i>
                                </div>
                                <div class="stat-trend">
                                    <i class='bx bx-up-arrow-alt'></i>
                                    +8%
                                </div>
                            </div>
                            <div class="stat-value"><?php echo $stats['pending']; ?></div>
                            <div class="stat-label">Pending</div>
                        </div>
                        <div class="stat-card" data-type="rectified" onclick="filterByStatus('rectified')">
                            <div class="stat-header">
                                <div class="stat-icon-container">
                                    <i class='bx bxs-check-circle'></i>
                                </div>
                                <div class="stat-trend">
                                    <i class='bx bx-up-arrow-alt'></i>
                                    +15%
                                </div>
                            </div>
                            <div class="stat-value"><?php echo $stats['rectified']; ?></div>
                            <div class="stat-label">Rectified</div>
                        </div>
                        <div class="stat-card" data-type="overdue" onclick="filterByStatus('overdue')">
                            <div class="stat-header">
                                <div class="stat-icon-container">
                                    <i class='bx bxs-alarm-exclamation'></i>
                                </div>
                                <div class="stat-trend">
                                    <i class='bx bx-up-arrow-alt'></i>
                                    +5%
                                </div>
                            </div>
                            <div class="stat-value"><?php echo $stats['overdue']; ?></div>
                            <div class="stat-label">Overdue</div>
                        </div>
                        <div class="stat-card" data-type="critical" onclick="filterBySeverity('critical')">
                            <div class="stat-header">
                                <div class="stat-icon-container">
                                    <i class='bx bxs-error-circle'></i>
                                </div>
                                <div class="stat-trend">
                                    <i class='bx bx-up-arrow-alt'></i>
                                    +3%
                                </div>
                            </div>
                            <div class="stat-value"><?php echo $stats['critical']; ?></div>
                            <div class="stat-label">Critical</div>
                        </div>
                        <div class="stat-card" data-type="major" onclick="filterBySeverity('major')">
                            <div class="stat-header">
                                <div class="stat-icon-container">
                                    <i class='bx bxs-error-alt'></i>
                                </div>
                                <div class="stat-trend">
                                    <i class='bx bx-up-arrow-alt'></i>
                                    +7%
                                </div>
                            </div>
                            <div class="stat-value"><?php echo $stats['major']; ?></div>
                            <div class="stat-label">Major</div>
                        </div>
                        <div class="stat-card" data-type="minor" onclick="filterBySeverity('minor')">
                            <div class="stat-header">
                                <div class="stat-icon-container">
                                    <i class='bx bxs-info-circle'></i>
                                </div>
                                <div class="stat-trend">
                                    <i class='bx bx-up-arrow-alt'></i>
                                    +10%
                                </div>
                            </div>
                            <div class="stat-value"><?php echo $stats['minor']; ?></div>
                            <div class="stat-label">Minor</div>
                        </div>
                        <div class="stat-card" data-type="past_deadline" onclick="filterByDate('overdue')">
                            <div class="stat-header">
                                <div class="stat-icon-container">
                                    <i class='bx bxs-calendar-x'></i>
                                </div>
                                <div class="stat-trend">
                                    <i class='bx bx-up-arrow-alt'></i>
                                    +6%
                                </div>
                            </div>
                            <div class="stat-value"><?php echo $stats['past_deadline']; ?></div>
                            <div class="stat-label">Past Deadline</div>
                        </div>
                    </div>
                    
                    <!-- Filter Tabs Container -->
                    <div class="filter-tabs-container">
                        <div class="filter-header">
                            <h3 class="filter-title">
                                <i class='bx bxs-error-circle'></i>
                                Inspection Violations - Review Queue
                            </h3>
                        </div>
                        
                        <div class="filter-tabs">
                            <a href="?status=all&severity=<?php echo $filter_severity; ?>&search=<?php echo urlencode($search_query); ?>&barangay=<?php echo $filter_barangay; ?>&date=<?php echo $filter_date; ?>" class="filter-tab <?php echo $filter_status === 'all' ? 'active' : ''; ?>">
                                <i class='bx bxs-dashboard'></i>
                                All Violations
                                <span class="filter-tab-count"><?php echo $stats['total']; ?></span>
                            </a>
                            <a href="?status=pending&severity=<?php echo $filter_severity; ?>&search=<?php echo urlencode($search_query); ?>&barangay=<?php echo $filter_barangay; ?>&date=<?php echo $filter_date; ?>" class="filter-tab <?php echo $filter_status === 'pending' ? 'active' : ''; ?>">
                                <i class='bx bxs-time-five'></i>
                                Pending
                                <span class="filter-tab-count"><?php echo $stats['pending']; ?></span>
                            </a>
                            <a href="?status=overdue&severity=<?php echo $filter_severity; ?>&search=<?php echo urlencode($search_query); ?>&barangay=<?php echo $filter_barangay; ?>&date=<?php echo $filter_date; ?>" class="filter-tab <?php echo $filter_status === 'overdue' ? 'active' : ''; ?>">
                                <i class='bx bxs-alarm-exclamation'></i>
                                Overdue
                                <span class="filter-tab-count"><?php echo $stats['overdue']; ?></span>
                            </a>
                            <a href="?status=rectified&severity=<?php echo $filter_severity; ?>&search=<?php echo urlencode($search_query); ?>&barangay=<?php echo $filter_barangay; ?>&date=<?php echo $filter_date; ?>" class="filter-tab <?php echo $filter_status === 'rectified' ? 'active' : ''; ?>">
                                <i class='bx bxs-check-circle'></i>
                                Rectified
                                <span class="filter-tab-count"><?php echo $stats['rectified']; ?></span>
                            </a>
                            <a href="?status=escalated&severity=<?php echo $filter_severity; ?>&search=<?php echo urlencode($search_query); ?>&barangay=<?php echo $filter_barangay; ?>&date=<?php echo $filter_date; ?>" class="filter-tab <?php echo $filter_status === 'escalated' ? 'active' : ''; ?>">
                                <i class='bx bxs-up-arrow-circle'></i>
                                Escalated
                                <span class="filter-tab-count"><?php echo $stats['escalated']; ?></span>
                            </a>
                            <a href="?status=waived&severity=<?php echo $filter_severity; ?>&search=<?php echo urlencode($search_query); ?>&barangay=<?php echo $filter_barangay; ?>&date=<?php echo $filter_date; ?>" class="filter-tab <?php echo $filter_status === 'waived' ? 'active' : ''; ?>">
                                <i class='bx bxs-check-shield'></i>
                                Waived
                                <span class="filter-tab-count"><?php echo $stats['waived']; ?></span>
                            </a>
                        </div>
                    </div>
                    
                    <!-- Advanced Filters -->
                    <div class="filters-container">
                        <div class="filter-section">
                            <h4 class="filter-section-title">
                                <i class='bx bx-filter-alt'></i>
                                Advanced Filters
                            </h4>
                            
                            <form method="GET" id="filter-form">
                                <div class="filter-row">
                                    <div class="filter-group">
                                        <label class="filter-label">
                                            <i class='bx bxs-error-circle'></i>
                                            Severity Level
                                        </label>
                                        <select class="filter-select" name="severity">
                                            <?php foreach ($severity_options as $value => $label): ?>
                                                <option value="<?php echo $value; ?>" <?php echo $filter_severity === $value ? 'selected' : ''; ?>>
                                                    <?php echo $label; ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    
                                    <div class="filter-group">
                                        <label class="filter-label">
                                            <i class='bx bx-calendar'></i>
                                            Date Filter
                                        </label>
                                        <select class="filter-select" name="date">
                                            <?php foreach ($date_options as $value => $label): ?>
                                                <option value="<?php echo $value; ?>" <?php echo $filter_date === $value ? 'selected' : ''; ?>>
                                                    <?php echo $label; ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    
                                    <div class="filter-group">
                                        <label class="filter-label">
                                            <i class='bx bxs-building'></i>
                                            Barangay
                                        </label>
                                        <select class="filter-select" name="barangay">
                                            <option value="">All Barangays</option>
                                            <?php foreach ($barangays as $barangay): ?>
                                                <option value="<?php echo htmlspecialchars($barangay); ?>" <?php echo $filter_barangay === $barangay ? 'selected' : ''; ?>>
                                                    <?php echo htmlspecialchars($barangay); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>
                                
                                <div class="filter-row">
                                    <div class="filter-group">
                                        <label class="filter-label">
                                            <i class='bx bx-search'></i>
                                            Search
                                        </label>
                                        <input type="text" class="filter-input" name="search" placeholder="Search by violation code, establishment name, description..." value="<?php echo htmlspecialchars($search_query); ?>">
                                    </div>
                                </div>
                                
                                <div class="filter-actions">
                                    <a href="review_violations.php" class="filter-button clear-filters">
                                        <i class='bx bx-x'></i>
                                        Clear All Filters
                                    </a>
                                    <button type="submit" class="filter-button">
                                        <i class='bx bx-filter-alt'></i>
                                        Apply Filters
                                    </button>
                                </div>
                                
                                <!-- Hidden field to preserve status filter -->
                                <input type="hidden" name="status" value="<?php echo $filter_status; ?>">
                            </form>
                        </div>
                    </div>
                    
                    <!-- Violations Table -->
                    <div class="violations-table-container">
                        <div class="table-header">
                            <div>Code</div>
                            <div>Establishment</div>
                            <div>Violation</div>
                            <div>Severity</div>
                            <div>Fine</div>
                            <div>Deadline</div>
                            <div>Status</div>
                            <div>Report</div>
                            <div>Actions</div>
                        </div>
                        <div class="violations-table-container" style="max-height: 500px;">
                            <?php if (count($violations) > 0): ?>
                                <?php foreach ($violations as $index => $violation): ?>
                                    <?php 
                                    // Determine row class based on severity and overdue status
                                    $rowClass = '';
                                    if (isOverdue($violation['compliance_deadline'], $violation['status'])) {
                                        $rowClass = 'overdue';
                                    } elseif ($violation['severity'] === 'critical') {
                                        $rowClass = 'critical';
                                    } elseif ($violation['severity'] === 'major') {
                                        $rowClass = 'major';
                                    }
                                    
                                    // Determine deadline display
                                    $deadlineClass = 'deadline-normal';
                                    $deadlineText = formatDate($violation['compliance_deadline']);
                                    
                                    if (isOverdue($violation['compliance_deadline'], $violation['status'])) {
                                        $deadlineClass = 'deadline-overdue';
                                        $deadlineText = 'OVERDUE: ' . formatDate($violation['compliance_deadline']);
                                    } elseif ($violation['compliance_deadline']) {
                                        $daysRemaining = ceil((strtotime($violation['compliance_deadline']) - time()) / (60 * 60 * 24));
                                        if ($daysRemaining <= 3) {
                                            $deadlineClass = 'deadline-urgent';
                                            $deadlineText .= " ({$daysRemaining}d left)";
                                        }
                                    }
                                    ?>
                                    <div class="table-row <?php echo $rowClass; ?>" style="animation-delay: <?php echo $index * 0.05; ?>s;">
                                        <div class="table-cell" data-label="Code">
                                            <div class="violation-code"><?php echo $violation['violation_code']; ?></div>
                                            <?php if ($violation['section_violated']): ?>
                                                <div style="font-size: 11px; color: var(--text-light);">Sec: <?php echo $violation['section_violated']; ?></div>
                                            <?php endif; ?>
                                        </div>
                                        <div class="table-cell" data-label="Establishment">
                                            <div class="establishment-name"><?php echo htmlspecialchars($violation['establishment_name']); ?></div>
                                            <div style="font-size: 12px; color: var(--text-light);">
                                                <?php echo htmlspecialchars($violation['establishment_type']); ?> • <?php echo htmlspecialchars($violation['barangay']); ?>
                                            </div>
                                        </div>
                                        <div class="table-cell" data-label="Violation">
                                            <div class="violation-description"><?php echo htmlspecialchars($violation['violation_description']); ?></div>
                                        </div>
                                        <div class="table-cell" data-label="Severity">
                                            <?php echo getSeverityBadge($violation['severity']); ?>
                                        </div>
                                        <div class="table-cell" data-label="Fine">
                                            <?php if ($violation['fine_amount']): ?>
                                                <div class="fine-amount"><?php echo formatCurrency($violation['fine_amount']); ?></div>
                                            <?php else: ?>
                                                <div style="color: var(--text-light); font-size: 12px;">N/A</div>
                                            <?php endif; ?>
                                        </div>
                                        <div class="table-cell" data-label="Deadline">
                                            <?php if ($violation['compliance_deadline']): ?>
                                                <div class="<?php echo $deadlineClass; ?>"><?php echo $deadlineText; ?></div>
                                                <?php if (isOverdue($violation['compliance_deadline'], $violation['status'])): ?>
                                                    <div class="overdue-warning">
                                                        <i class='bx bxs-error'></i>
                                                        Past Deadline
                                                    </div>
                                                <?php endif; ?>
                                            <?php else: ?>
                                                <div style="color: var(--text-light); font-size: 12px;">N/A</div>
                                            <?php endif; ?>
                                        </div>
                                        <div class="table-cell" data-label="Status">
                                            <?php echo getStatusBadge($violation['status']); ?>
                                            <?php if ($violation['rectified_at']): ?>
                                                <div style="font-size: 11px; color: var(--text-light); margin-top: 4px;">
                                                    Rectified: <?php echo formatDate($violation['rectified_at']); ?>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                        <div class="table-cell" data-label="Report">
                                            <div class="report-number"><?php echo $violation['report_number']; ?></div>
                                            <div style="font-size: 11px; color: var(--text-light);">
                                                <?php echo formatDate($violation['inspection_date']); ?>
                                            </div>
                                        </div>
                                        <div class="table-cell" data-label="Actions">
                                            <div class="action-buttons">
                                                <button class="action-button view-button" onclick="viewViolationDetails(<?php echo $violation['id']; ?>)">
                                                    <i class='bx bxs-info-circle'></i>
                                                    View
                                                </button>
                                                
                                                <?php if (in_array($violation['status'], ['pending', 'overdue'])): ?>
                                                    <button class="action-button rectify-button" onclick="markAsRectified(<?php echo $violation['id']; ?>)">
                                                        <i class='bx bxs-check-circle'></i>
                                                        Rectify
                                                    </button>
                                                    
                                                    <button class="action-button escalate-button" onclick="escalateViolation(<?php echo $violation['id']; ?>)">
                                                        <i class='bx bxs-up-arrow-circle'></i>
                                                        Escalate
                                                    </button>
                                                    
                                                    <button class="action-button waive-button" onclick="waiveViolation(<?php echo $violation['id']; ?>)">
                                                        <i class='bx bxs-check-shield'></i>
                                                        Waive
                                                    </button>
                                                <?php endif; ?>
                                                
                                                <?php if ($violation['status'] !== 'rectified'): ?>
                                                    <button class="action-button edit-button" onclick="editViolation(<?php echo $violation['id']; ?>)">
                                                        <i class='bx bxs-edit'></i>
                                                        Edit
                                                    </button>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <div class="no-violations">
                                    <div class="no-violations-icon">
                                        <i class='bx bxs-check-shield'></i>
                                    </div>
                                    <h3>No Violations Found</h3>
                                    <p>No violations match your current filters.</p>
                                    <?php if ($filter_status !== 'all' || $filter_severity !== '' || $search_query !== '' || $filter_barangay !== '' || $filter_date !== ''): ?>
                                        <a href="review_violations.php" class="filter-button" style="margin-top: 16px;">
                                            <i class='bx bx-x'></i>
                                            Clear Filters
                                        </a>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>
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
            
            // Initialize search functionality
            initSearch();
            
            // Add data labels for mobile view
            addDataLabels();
            
            // Add animation to stat cards
            animateStatCards();
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
                
                // Save theme preference
                localStorage.setItem('theme', document.body.classList.contains('dark-mode') ? 'dark' : 'light');
            });
            
            // Load saved theme preference
            const savedTheme = localStorage.getItem('theme');
            if (savedTheme === 'dark') {
                document.body.classList.add('dark-mode');
                themeIcon.className = 'bx bx-sun';
                themeText.textContent = 'Light Mode';
            }
            
            // Modal functionality
            const detailsModal = document.getElementById('details-modal');
            const detailsModalClose = document.getElementById('details-modal-close');
            const closeDetails = document.getElementById('close-details');
            
            detailsModalClose.addEventListener('click', closeDetailsModal);
            closeDetails.addEventListener('click', closeDetailsModal);
            
            detailsModal.addEventListener('click', function(e) {
                if (e.target === this) {
                    closeDetailsModal();
                }
            });
            
            // Rectify modal functionality
            const rectifyModal = document.getElementById('rectify-modal');
            const rectifyModalClose = document.getElementById('rectify-modal-close');
            const cancelRectify = document.getElementById('cancel-rectify');
            
            rectifyModalClose.addEventListener('click', closeRectifyModal);
            cancelRectify.addEventListener('click', closeRectifyModal);
            
            rectifyModal.addEventListener('click', function(e) {
                if (e.target === this) {
                    closeRectifyModal();
                }
            });
            
            // Escalate modal functionality
            const escalateModal = document.getElementById('escalate-modal');
            const escalateModalClose = document.getElementById('escalate-modal-close');
            const cancelEscalate = document.getElementById('cancel-escalate');
            
            escalateModalClose.addEventListener('click', closeEscalateModal);
            cancelEscalate.addEventListener('click', closeEscalateModal);
            
            escalateModal.addEventListener('click', function(e) {
                if (e.target === this) {
                    closeEscalateModal();
                }
            });
            
            // Waive modal functionality
            const waiveModal = document.getElementById('waive-modal');
            const waiveModalClose = document.getElementById('waive-modal-close');
            const cancelWaive = document.getElementById('cancel-waive');
            
            waiveModalClose.addEventListener('click', closeWaiveModal);
            cancelWaive.addEventListener('click', closeWaiveModal);
            
            waiveModal.addEventListener('click', function(e) {
                if (e.target === this) {
                    closeWaiveModal();
                }
            });
            
            // Edit modal functionality
            const editModal = document.getElementById('edit-modal');
            const editModalClose = document.getElementById('edit-modal-close');
            const cancelEdit = document.getElementById('cancel-edit');
            
            editModalClose.addEventListener('click', closeEditModal);
            cancelEdit.addEventListener('click', closeEditModal);
            
            editModal.addEventListener('click', function(e) {
                if (e.target === this) {
                    closeEditModal();
                }
            });
            
            // Form submissions
            const rectifyForm = document.getElementById('rectify-form');
            const escalateForm = document.getElementById('escalate-form');
            const waiveForm = document.getElementById('waive-form');
            const editForm = document.getElementById('edit-form');
            
            rectifyForm.addEventListener('submit', function(e) {
                e.preventDefault();
                submitRectify();
            });
            
            escalateForm.addEventListener('submit', function(e) {
                e.preventDefault();
                submitEscalate();
            });
            
            waiveForm.addEventListener('submit', function(e) {
                e.preventDefault();
                submitWaive();
            });
            
            editForm.addEventListener('submit', function(e) {
                e.preventDefault();
                submitEdit();
            });
            
            // Filter form submission
            const filterForm = document.getElementById('filter-form');
            
            // Handle filter select changes
            filterForm.querySelectorAll('select').forEach(select => {
                select.addEventListener('change', function() {
                    filterForm.submit();
                });
            });
            
            // Add click handlers for stat cards
            document.querySelectorAll('.stat-card').forEach(card => {
                card.addEventListener('click', function() {
                    const type = this.dataset.type;
                    handleStatCardClick(type);
                });
            });
        }
        
        function initSearch() {
            const searchInput = document.getElementById('search-input');
            const filterForm = document.getElementById('filter-form');
            const searchParam = filterForm.querySelector('input[name="search"]');
            
            // Set search input value from URL parameter
            searchInput.value = '<?php echo htmlspecialchars($search_query); ?>';
            
            // Add event listener for search input
            searchInput.addEventListener('keypress', function(e) {
                if (e.key === 'Enter') {
                    searchParam.value = this.value;
                    filterForm.submit();
                }
            });
            
            // Add debounced search
            let searchTimeout;
            searchInput.addEventListener('input', function() {
                clearTimeout(searchTimeout);
                searchTimeout = setTimeout(() => {
                    if (this.value.length >= 2 || this.value.length === 0) {
                        searchParam.value = this.value;
                        filterForm.submit();
                    }
                }, 500);
            });
        }
        
        function animateStatCards() {
            document.querySelectorAll('.stat-card').forEach((card, index) => {
                card.style.animationDelay = `${index * 0.1}s`;
                card.style.opacity = '0';
                card.style.transform = 'translateY(20px)';
                
                setTimeout(() => {
                    card.style.transition = 'all 0.5s ease';
                    card.style.opacity = '1';
                    card.style.transform = 'translateY(0)';
                }, index * 100);
            });
        }
        
        function addDataLabels() {
            // This function adds data-label attributes for mobile responsive view
            const tableCells = document.querySelectorAll('.table-cell');
            const headers = document.querySelectorAll('.table-header > div');
            
            if (window.innerWidth <= 992) {
                const headerLabels = Array.from(headers).map(header => header.textContent);
                
                tableCells.forEach((cell, index) => {
                    const rowIndex = Math.floor(index / 9); // 9 columns
                    const colIndex = index % 9;
                    
                    if (colIndex < headerLabels.length) {
                        cell.setAttribute('data-label', headerLabels[colIndex]);
                    }
                });
            }
        }
        
        function viewViolationDetails(violationId) {
            const detailsModal = document.getElementById('details-modal');
            const detailsContent = document.getElementById('details-content');
            
            // Show loading animation
            detailsContent.innerHTML = `
                <div style="text-align: center; padding: 40px;">
                    <div style="width: 60px; height: 60px; margin: 0 auto 20px; border: 4px solid rgba(220, 38, 38, 0.1); border-top-color: var(--primary-color); border-radius: 50%; animation: spin 1s linear infinite;"></div>
                    <p style="color: var(--text-light);">Loading violation details...</p>
                </div>
                <style>
                    @keyframes spin {
                        0% { transform: rotate(0deg); }
                        100% { transform: rotate(360deg); }
                    }
                </style>
            `;
            
            // Fetch violation details via AJAX
            fetch(`get_violation_details.php?id=${violationId}`)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        renderViolationDetails(data.violation);
                    } else {
                        detailsContent.innerHTML = `
                            <div style="text-align: center; padding: 40px; color: var(--danger);">
                                <i class="bx bx-error" style="font-size: 48px; margin-bottom: 16px;"></i>
                                <h3 style="margin-bottom: 8px;">Error</h3>
                                <p>${data.message || 'Failed to load violation details'}</p>
                            </div>
                        `;
                    }
                })
                .catch(error => {
                    console.error('Fetch error:', error);
                    detailsContent.innerHTML = `
                        <div style="text-align: center; padding: 40px; color: var(--danger);">
                            <i class="bx bx-error" style="font-size: 48px; margin-bottom: 16px;"></i>
                            <h3 style="margin-bottom: 8px;">Network Error</h3>
                            <p>Failed to load violation details. Please check your connection and try again.</p>
                        </div>
                    `;
                });
            
            // Open modal
            detailsModal.classList.add('active');
        }
        
        function renderViolationDetails(violation) {
            const detailsContent = document.getElementById('details-content');
            
            // Determine deadline status
            let deadlineStatus = '';
            let deadlineClass = '';
            if (violation.compliance_deadline) {
                const today = new Date();
                const deadline = new Date(violation.compliance_deadline);
                const daysRemaining = Math.ceil((deadline - today) / (1000 * 60 * 60 * 24));
                
                if (daysRemaining < 0 && violation.status !== 'rectified') {
                    deadlineStatus = `Overdue by ${Math.abs(daysRemaining)} days`;
                    deadlineClass = 'deadline-overdue';
                } else if (daysRemaining <= 3) {
                    deadlineStatus = `${daysRemaining} days remaining`;
                    deadlineClass = 'deadline-urgent';
                } else {
                    deadlineStatus = `${daysRemaining} days remaining`;
                    deadlineClass = 'deadline-normal';
                }
            }
            
            // Format date
            function formatDateString(dateString) {
                if (!dateString) return 'N/A';
                const date = new Date(dateString);
                return date.toLocaleDateString('en-US', { 
                    weekday: 'long', 
                    year: 'numeric', 
                    month: 'long', 
                    day: 'numeric' 
                });
            }
            
            let detailsHtml = `
                <div class="violation-details">
                    <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 24px;">
                        <div>
                            <h3 style="margin: 0; color: var(--primary-color);">${violation.violation_code}</h3>
                            <p style="margin: 4px 0 0; color: var(--text-light);">${violation.section_violated ? 'Section: ' + violation.section_violated : ''}</p>
                        </div>
                        <div style="display: flex; gap: 8px;">
                            ${getSeverityBadge(violation.severity)}
                            ${getStatusBadge(violation.status)}
                        </div>
                    </div>
                    
                    <div style="background: linear-gradient(135deg, rgba(59, 130, 246, 0.05), rgba(59, 130, 246, 0.1)); border-radius: 12px; padding: 20px; margin-bottom: 20px; border-left: 4px solid var(--info);">
                        <h4 style="margin: 0 0 16px 0; color: var(--info); display: flex; align-items: center; gap: 8px;">
                            <i class='bx bxs-building'></i>
                            Establishment Details
                        </h4>
                        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 16px;">
                            <div>
                                <div style="font-size: 11px; color: var(--text-light); text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 4px;">Name</div>
                                <div style="font-size: 16px; font-weight: 600;">${violation.establishment_name}</div>
                            </div>
                            <div>
                                <div style="font-size: 11px; color: var(--text-light); text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 4px;">Type</div>
                                <div style="font-size: 16px; font-weight: 600;">${violation.establishment_type}</div>
                            </div>
                            <div>
                                <div style="font-size: 11px; color: var(--text-light); text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 4px;">Owner</div>
                                <div style="font-size: 16px; font-weight: 600;">${violation.owner_name}</div>
                            </div>
                            <div>
                                <div style="font-size: 11px; color: var(--text-light); text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 4px;">Barangay</div>
                                <div style="font-size: 16px; font-weight: 600;">${violation.barangay}</div>
                            </div>
                        </div>
                        ${violation.address ? `<div style="margin-top: 12px; font-size: 14px; color: var(--text-light);"><i class='bx bxs-map'></i> ${violation.address}</div>` : ''}
                        ${violation.owner_contact ? `<div style="margin-top: 8px; font-size: 14px; color: var(--text-light);"><i class='bx bxs-phone'></i> ${violation.owner_contact}</div>` : ''}
                    </div>
                    
                    <div style="background: linear-gradient(135deg, rgba(220, 38, 38, 0.05), rgba(220, 38, 38, 0.1)); border-radius: 12px; padding: 20px; margin-bottom: 20px; border-left: 4px solid var(--danger);">
                        <h4 style="margin: 0 0 16px 0; color: var(--danger); display: flex; align-items: center; gap: 8px;">
                            <i class='bx bxs-error-circle'></i>
                            Violation Details
                        </h4>
                        <div style="white-space: pre-line; font-size: 14px; line-height: 1.6; margin-bottom: 16px;">${violation.violation_description}</div>
                        
                        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 16px;">
                            <div>
                                <div style="font-size: 11px; color: var(--text-light); text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 4px;">Fine Amount</div>
                                <div style="font-size: 20px; font-weight: 700; color: var(--danger);">${violation.fine_amount ? '₱' + parseFloat(violation.fine_amount).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) : 'N/A'}</div>
                            </div>
                            <div>
                                <div style="font-size: 11px; color: var(--text-light); text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 4px;">Compliance Deadline</div>
                                <div style="font-size: 16px; font-weight: 600; ${deadlineClass === 'deadline-overdue' ? 'color: #7c2d12;' : deadlineClass === 'deadline-urgent' ? 'color: var(--danger);' : 'color: var(--success);'}">
                                    ${formatDateString(violation.compliance_deadline)}
                                </div>
                                ${deadlineStatus ? `<div style="font-size: 12px; ${deadlineClass === 'deadline-overdue' ? 'color: #7c2d12;' : deadlineClass === 'deadline-urgent' ? 'color: var(--danger);' : 'color: var(--success);'}">${deadlineStatus}</div>` : ''}
                            </div>
                            <div>
                                <div style="font-size: 11px; color: var(--text-light); text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 4px;">Report Number</div>
                                <div style="font-size: 16px; font-weight: 600; color: var(--info);">${violation.report_number}</div>
                            </div>
                        </div>
                    </div>
                    
                    ${violation.admin_notes ? `
                    <div style="background: linear-gradient(135deg, rgba(139, 92, 246, 0.05), rgba(139, 92, 246, 0.1)); border-radius: 12px; padding: 20px; margin-bottom: 20px; border-left: 4px solid var(--purple);">
                        <h4 style="margin: 0 0 16px 0; color: var(--purple); display: flex; align-items: center; gap: 8px;">
                            <i class='bx bxs-edit'></i>
                            Admin Notes
                        </h4>
                        <div style="white-space: pre-line; font-size: 14px; line-height: 1.6;">${violation.admin_notes}</div>
                    </div>` : ''}
                    
                    ${violation.rectified_at ? `
                    <div style="background: linear-gradient(135deg, rgba(16, 185, 129, 0.05), rgba(16, 185, 129, 0.1)); border-radius: 12px; padding: 20px; margin-bottom: 20px; border-left: 4px solid var(--success);">
                        <h4 style="margin: 0 0 16px 0; color: var(--success); display: flex; align-items: center; gap: 8px;">
                            <i class='bx bxs-check-circle'></i>
                            Rectification Details
                        </h4>
                        <div style="white-space: pre-line; font-size: 14px; line-height: 1.6; margin-bottom: 12px;">${violation.rectify_notes || 'No rectification notes provided.'}</div>
                        <div style="font-size: 14px; font-weight: 600;"><i class='bx bxs-calendar'></i> Rectified on: ${formatDateString(violation.rectified_at)}</div>
                        ${violation.rectified_evidence ? `
                        <div style="margin-top: 12px;">
                            <div style="font-size: 12px; color: var(--text-light); margin-bottom: 4px;">Evidence:</div>
                            <a href="uploads/evidence/${violation.rectified_evidence}" target="_blank" style="display: inline-flex; align-items: center; gap: 6px; color: var(--info); text-decoration: none; font-size: 14px;">
                                <i class='bx bxs-file'></i>
                                View Evidence
                            </a>
                        </div>` : ''}
                    </div>` : ''}
                    
                    ${violation.inspector_name ? `
                    <div style="background: var(--card-bg); border: 1px solid var(--border-color); border-radius: 12px; padding: 16px; margin-bottom: 20px;">
                        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 16px;">
                            <div>
                                <div style="font-size: 11px; color: var(--text-light); text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 4px;">Inspected By</div>
                                <div style="font-size: 14px; font-weight: 600;">${violation.inspector_name}</div>
                            </div>
                            <div>
                                <div style="font-size: 11px; color: var(--text-light); text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 4px;">Inspection Date</div>
                                <div style="font-size: 14px; font-weight: 600;">${formatDateString(violation.inspection_date)}</div>
                            </div>
                            <div>
                                <div style="font-size: 11px; color: var(--text-light); text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 4px;">Violation Created</div>
                                <div style="font-size: 14px; font-weight: 600;">${formatDateString(violation.created_at)}</div>
                            </div>
                        </div>
                    </div>` : ''}
                </div>`;
            
            detailsContent.innerHTML = detailsHtml;
        }
        
        function markAsRectified(violationId) {
            const rectifyModal = document.getElementById('rectify-modal');
            const rectifyViolationId = document.getElementById('rectify-violation-id');
            
            rectifyViolationId.value = violationId;
            
            // Open modal
            rectifyModal.classList.add('active');
        }
        
        function escalateViolation(violationId) {
            const escalateModal = document.getElementById('escalate-modal');
            const escalateViolationId = document.getElementById('escalate-violation-id');
            
            escalateViolationId.value = violationId;
            
            // Open modal
            escalateModal.classList.add('active');
        }
        
        function waiveViolation(violationId) {
            const waiveModal = document.getElementById('waive-modal');
            const waiveViolationId = document.getElementById('waive-violation-id');
            
            waiveViolationId.value = violationId;
            
            // Open modal
            waiveModal.classList.add('active');
        }
        
        function editViolation(violationId) {
            const editModal = document.getElementById('edit-modal');
            const editViolationId = document.getElementById('edit-violation-id');
            
            editViolationId.value = violationId;
            
            // Fetch current violation data
            fetch(`get_violation_details.php?id=${violationId}`)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        const violation = data.violation;
                        
                        // Populate form fields
                        document.getElementById('edit_severity').value = violation.severity;
                        document.getElementById('edit_fine_amount').value = violation.fine_amount || '';
                        document.getElementById('edit_compliance_deadline').value = violation.compliance_deadline || '';
                        document.getElementById('edit_admin_notes').value = violation.admin_notes || '';
                        
                        // Open modal
                        editModal.classList.add('active');
                    } else {
                        showNotification('error', 'Failed to load violation details');
                    }
                })
                .catch(error => {
                    showNotification('error', 'Error: ' + error.message);
                });
        }
        
        function submitRectify() {
            const form = document.getElementById('rectify-form');
            const formData = new FormData(form);
            
            fetch('mark_rectified.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showNotification('success', data.message || 'Violation marked as rectified successfully!');
                    closeRectifyModal();
                    setTimeout(() => {
                        location.reload();
                    }, 1500);
                } else {
                    showNotification('error', data.message || 'Failed to mark violation as rectified');
                }
            })
            .catch(error => {
                showNotification('error', 'Error: ' + error.message);
            });
        }
        
        function submitEscalate() {
            const form = document.getElementById('escalate-form');
            const formData = new FormData(form);
            
            fetch('escalate_violation.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showNotification('success', data.message || 'Violation escalated successfully!');
                    closeEscalateModal();
                    setTimeout(() => {
                        location.reload();
                    }, 1500);
                } else {
                    showNotification('error', data.message || 'Failed to escalate violation');
                }
            })
            .catch(error => {
                showNotification('error', 'Error: ' + error.message);
            });
        }
        
        function submitWaive() {
            const form = document.getElementById('waive-form');
            const formData = new FormData(form);
            
            fetch('waive_violation.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showNotification('success', data.message || 'Violation waived successfully!');
                    closeWaiveModal();
                    setTimeout(() => {
                        location.reload();
                    }, 1500);
                } else {
                    showNotification('error', data.message || 'Failed to waive violation');
                }
            })
            .catch(error => {
                showNotification('error', 'Error: ' + error.message);
            });
        }
        
        function submitEdit() {
            const form = document.getElementById('edit-form');
            const formData = new FormData(form);
            
            fetch('edit_violation.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showNotification('success', data.message || 'Violation updated successfully!');
                    closeEditModal();
                    setTimeout(() => {
                        location.reload();
                    }, 1500);
                } else {
                    showNotification('error', data.message || 'Failed to update violation');
                }
            })
            .catch(error => {
                showNotification('error', 'Error: ' + error.message);
            });
        }
        
        function viewOverdueViolations() {
            const url = new URL(window.location.href);
            url.searchParams.set('status', 'overdue');
            window.location.href = url.toString();
        }
        
        function viewCriticalViolations() {
            const url = new URL(window.location.href);
            url.searchParams.set('severity', 'critical');
            url.searchParams.set('status', 'all');
            window.location.href = url.toString();
        }
        
        function generateViolationReport() {
            // Show loading
            showNotification('info', 'Generating violation report...');
            
            fetch('generate_violation_report.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({ 
                    filter_status: '<?php echo $filter_status; ?>',
                    filter_severity: '<?php echo $filter_severity; ?>',
                    filter_date: '<?php echo $filter_date; ?>',
                    filter_barangay: '<?php echo $filter_barangay; ?>'
                })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showNotification('success', 'Report generated successfully! Download started.');
                    // Trigger download
                    if (data.download_url) {
                        window.open(data.download_url, '_blank');
                    }
                } else {
                    showNotification('error', data.message || 'Failed to generate report');
                }
            })
            .catch(error => {
                showNotification('error', 'Error: ' + error.message);
            });
        }
        
        function showBulkActions() {
            // Get selected violations
            const selectedIds = [];
            // This would need checkboxes in the table rows for selection
            
            if (selectedIds.length === 0) {
                showNotification('warning', 'Please select at least one violation to perform bulk actions');
                return;
            }
            
            // Show bulk action modal or perform action directly
            const action = prompt('Enter bulk action (rectify, escalate, waive):');
            if (action && ['rectify', 'escalate', 'waive'].includes(action.toLowerCase())) {
                if (confirm(`Are you sure you want to ${action} ${selectedIds.length} violation(s)?`)) {
                    fetch('bulk_action_violations.php', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                        },
                        body: JSON.stringify({ 
                            violation_ids: selectedIds,
                            action: action.toLowerCase()
                        })
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            showNotification('success', data.message || 'Bulk action completed successfully!');
                            setTimeout(() => {
                                location.reload();
                            }, 1500);
                        } else {
                            showNotification('error', data.message || 'Failed to perform bulk action');
                        }
                    })
                    .catch(error => {
                        showNotification('error', 'Error: ' + error.message);
                    });
                }
            }
        }
        
        function showNotification(type, message) {
            // Remove existing notifications
            const existingNotification = document.querySelector('.notification');
            if (existingNotification) {
                existingNotification.remove();
            }
            
            const notification = document.createElement('div');
            notification.className = `notification notification-${type}`;
            notification.innerHTML = `
                <div style="display: flex; align-items: center; gap: 10px;">
                    <i class='bx ${type === 'success' ? 'bx-check-circle' : type === 'error' ? 'bx-error-circle' : 'bx-info-circle'}'></i>
                    <span>${message}</span>
                </div>
                <button onclick="this.parentElement.remove()" style="background: none; border: none; color: inherit; cursor: pointer;">
                    <i class='bx bx-x'></i>
                </button>
            `;
            
            // Add styles
            notification.style.cssText = `
                position: fixed;
                top: 20px;
                right: 20px;
                padding: 16px 20px;
                border-radius: 12px;
                display: flex;
                align-items: center;
                justify-content: space-between;
                gap: 15px;
                max-width: 400px;
                z-index: 9999;
                animation: slideIn 0.3s ease;
                box-shadow: 0 4px 20px rgba(0, 0, 0, 0.15);
                backdrop-filter: blur(10px);
            `;
            
            if (type === 'success') {
                notification.style.background = 'linear-gradient(135deg, rgba(16, 185, 129, 0.9), rgba(16, 185, 129, 0.8))';
                notification.style.color = 'white';
                notification.style.border = '1px solid rgba(16, 185, 129, 0.3)';
            } else if (type === 'error') {
                notification.style.background = 'linear-gradient(135deg, rgba(220, 38, 38, 0.9), rgba(220, 38, 38, 0.8))';
                notification.style.color = 'white';
                notification.style.border = '1px solid rgba(220, 38, 38, 0.3)';
            } else if (type === 'warning') {
                notification.style.background = 'linear-gradient(135deg, rgba(245, 158, 11, 0.9), rgba(245, 158, 11, 0.8))';
                notification.style.color = 'white';
                notification.style.border = '1px solid rgba(245, 158, 11, 0.3)';
            } else {
                notification.style.background = 'linear-gradient(135deg, rgba(59, 130, 246, 0.9), rgba(59, 130, 246, 0.8))';
                notification.style.color = 'white';
                notification.style.border = '1px solid rgba(59, 130, 246, 0.3)';
            }
            
            document.body.appendChild(notification);
            
            // Auto-remove after 5 seconds
            setTimeout(() => {
                if (notification.parentNode) {
                    notification.style.animation = 'slideOut 0.3s ease';
                    setTimeout(() => {
                        if (notification.parentNode) {
                            notification.remove();
                        }
                    }, 300);
                }
            }, 5000);
            
            // Add keyframes
            if (!document.querySelector('#notification-styles')) {
                const style = document.createElement('style');
                style.id = 'notification-styles';
                style.textContent = `
                    @keyframes slideIn {
                        from { transform: translateX(100%); opacity: 0; }
                        to { transform: translateX(0); opacity: 1; }
                    }
                    @keyframes slideOut {
                        from { transform: translateX(0); opacity: 1; }
                        to { transform: translateX(100%); opacity: 0; }
                    }
                `;
                document.head.appendChild(style);
            }
        }
        
        function closeDetailsModal() {
            document.getElementById('details-modal').classList.remove('active');
        }
        
        function closeRectifyModal() {
            document.getElementById('rectify-modal').classList.remove('active');
        }
        
        function closeEscalateModal() {
            document.getElementById('escalate-modal').classList.remove('active');
        }
        
        function closeWaiveModal() {
            document.getElementById('waive-modal').classList.remove('active');
        }
        
        function closeEditModal() {
            document.getElementById('edit-modal').classList.remove('active');
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
        
        function handleStatCardClick(type) {
            switch(type) {
                case 'total':
                    filterByStatus('all');
                    break;
                case 'pending':
                    filterByStatus('pending');
                    break;
                case 'rectified':
                    filterByStatus('rectified');
                    break;
                case 'overdue':
                    filterByStatus('overdue');
                    break;
                case 'critical':
                    filterBySeverity('critical');
                    break;
                case 'major':
                    filterBySeverity('major');
                    break;
                case 'minor':
                    filterBySeverity('minor');
                    break;
                case 'past_deadline':
                    filterByDate('overdue');
                    break;
            }
        }
        
        function filterByStatus(status) {
            const url = new URL(window.location.href);
            url.searchParams.set('status', status);
            window.location.href = url.toString();
        }
        
        function filterBySeverity(severity) {
            const url = new URL(window.location.href);
            url.searchParams.set('severity', severity);
            window.location.href = url.toString();
        }
        
        function filterByDate(date) {
            const url = new URL(window.location.href);
            url.searchParams.set('date', date);
            window.location.href = url.toString();
        }
        
        // Handle window resize for responsive layout
        window.addEventListener('resize', addDataLabels);
    </script>
</body>
</html>