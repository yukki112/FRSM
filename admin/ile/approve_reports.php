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
$filter_status = isset($_GET['status']) ? $_GET['status'] : 'submitted';
$filter_date = isset($_GET['date']) ? $_GET['date'] : '';
$search_query = isset($_GET['search']) ? $_GET['search'] : '';
$filter_barangay = isset($_GET['barangay']) ? $_GET['barangay'] : '';
$filter_establishment_type = isset($_GET['establishment_type']) ? $_GET['establishment_type'] : '';

// Get all inspection reports for admin approval
function getInspectionReports($pdo, $filter_status = 'submitted', $filter_date = '', $search_query = '', $filter_barangay = '', $filter_establishment_type = '') {
    $sql = "SELECT 
                ir.id,
                ir.report_number,
                ir.inspection_date,
                ir.status,
                ir.overall_compliance_score,
                ir.risk_assessment,
                ir.fire_hazard_level,
                ir.admin_reviewed_by,
                ir.admin_reviewed_at,
                ir.created_at,
                ie.establishment_name,
                ie.establishment_type,
                ie.barangay,
                ie.address,
                ie.owner_name,
                ie.owner_contact,
                CONCAT(inspector.first_name, ' ', inspector.last_name) as inspector_name,
                CONCAT(reviewer.first_name, ' ', reviewer.last_name) as reviewer_name,
                COUNT(DISTINCT CASE WHEN iv.severity = 'critical' THEN iv.id END) as critical_violations,
                COUNT(DISTINCT CASE WHEN iv.severity = 'major' THEN iv.id END) as major_violations,
                COUNT(DISTINCT CASE WHEN iv.severity = 'minor' THEN iv.id END) as minor_violations,
                COUNT(DISTINCT CASE WHEN iv.status != 'rectified' THEN iv.id END) as pending_violations
            FROM inspection_reports ir
            LEFT JOIN inspection_establishments ie ON ir.establishment_id = ie.id
            LEFT JOIN users inspector ON ir.inspected_by = inspector.id
            LEFT JOIN users reviewer ON ir.admin_reviewed_by = reviewer.id
            LEFT JOIN inspection_violations iv ON ir.id = iv.inspection_id
            WHERE 1=1";
    
    $params = [];
    
    // Apply status filter
    if ($filter_status !== 'all') {
        if ($filter_status === 'pending_review') {
            $sql .= " AND ir.status IN ('submitted', 'under_review')";
        } else if ($filter_status === 'completed') {
            $sql .= " AND ir.status IN ('approved', 'rejected', 'completed')";
        } else {
            $sql .= " AND ir.status = ?";
            $params[] = $filter_status;
        }
    }
    
    // Apply date filter
    if (!empty($filter_date)) {
        if ($filter_date === 'today') {
            $sql .= " AND ir.inspection_date = CURDATE()";
        } elseif ($filter_date === 'yesterday') {
            $sql .= " AND ir.inspection_date = DATE_SUB(CURDATE(), INTERVAL 1 DAY)";
        } elseif ($filter_date === 'week') {
            $sql .= " AND ir.inspection_date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)";
        } elseif ($filter_date === 'month') {
            $sql .= " AND ir.inspection_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)";
        } elseif ($filter_date === 'year') {
            $sql .= " AND ir.inspection_date >= DATE_SUB(CURDATE(), INTERVAL 365 DAY)";
        }
    }
    
    // Apply barangay filter
    if (!empty($filter_barangay)) {
        $sql .= " AND ie.barangay LIKE ?";
        $params[] = "%$filter_barangay%";
    }
    
    // Apply establishment type filter
    if (!empty($filter_establishment_type)) {
        $sql .= " AND ie.establishment_type = ?";
        $params[] = $filter_establishment_type;
    }
    
    // Apply search query
    if (!empty($search_query)) {
        $sql .= " AND (
                    ir.report_number LIKE ? OR 
                    ie.establishment_name LIKE ? OR 
                    ie.owner_name LIKE ? OR 
                    ie.address LIKE ? OR 
                    ie.barangay LIKE ? OR
                    CONCAT(inspector.first_name, ' ', inspector.last_name) LIKE ?
                )";
        $search_param = "%$search_query%";
        $params = array_merge($params, [
            $search_param, $search_param, $search_param, $search_param,
            $search_param, $search_param
        ]);
    }
    
    $sql .= " GROUP BY ir.id ORDER BY ir.inspection_date DESC, ir.created_at DESC";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Get report statistics for admin
function getInspectionStats($pdo) {
    $sql = "SELECT 
                COUNT(*) as total,
                SUM(CASE WHEN status = 'submitted' THEN 1 ELSE 0 END) as submitted,
                SUM(CASE WHEN status = 'under_review' THEN 1 ELSE 0 END) as under_review,
                SUM(CASE WHEN status = 'approved' THEN 1 ELSE 0 END) as approved,
                SUM(CASE WHEN status = 'rejected' THEN 1 ELSE 0 END) as rejected,
                SUM(CASE WHEN status IN ('submitted', 'under_review') THEN 1 ELSE 0 END) as pending_review,
                SUM(CASE WHEN status IN ('approved', 'completed') THEN 1 ELSE 0 END) as completed,
                SUM(CASE WHEN risk_assessment = 'critical' THEN 1 ELSE 0 END) as critical_risk,
                SUM(CASE WHEN risk_assessment = 'high' THEN 1 ELSE 0 END) as high_risk,
                SUM(CASE WHEN fire_hazard_level = 'extreme' THEN 1 ELSE 0 END) as extreme_hazard
            FROM inspection_reports";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute();
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    
    $stats = [
        'total' => 0,
        'submitted' => 0,
        'under_review' => 0,
        'approved' => 0,
        'rejected' => 0,
        'pending_review' => 0,
        'completed' => 0,
        'critical_risk' => 0,
        'high_risk' => 0,
        'extreme_hazard' => 0
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

// Get all establishment types for filtering
function getEstablishmentTypes($pdo) {
    $sql = "SELECT DISTINCT establishment_type FROM inspection_establishments ORDER BY establishment_type";
    $stmt = $pdo->prepare($sql);
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_COLUMN, 0);
}

// Get data for filters
$barangays = getBarangays($pdo);
$establishment_types = getEstablishmentTypes($pdo);

// Get reports based on filters
$reports = getInspectionReports($pdo, $filter_status, $filter_date, $search_query, $filter_barangay, $filter_establishment_type);
$stats = getInspectionStats($pdo);

// Date filter options
$date_options = [
    '' => 'All Dates',
    'today' => 'Today',
    'yesterday' => 'Yesterday',
    'week' => 'Last 7 Days',
    'month' => 'Last 30 Days',
    'year' => 'Last Year'
];

// Status options
$status_options = [
    'all' => 'All Reports',
    'pending_review' => 'Pending Review',
    'submitted' => 'Submitted',
    'under_review' => 'Under Review',
    'approved' => 'Approved',
    'rejected' => 'Rejected',
    'completed' => 'Completed',
    'revision_requested' => 'Revision Requested'
];

// Risk level colors
$risk_colors = [
    'low' => '#10b981',
    'medium' => '#f59e0b',
    'high' => '#dc2626',
    'critical' => '#7c2d12'
];

// Hazard level colors
$hazard_colors = [
    'low' => '#10b981',
    'medium' => '#f59e0b',
    'high' => '#dc2626',
    'extreme' => '#7c2d12'
];

// Status colors
$status_colors = [
    'draft' => '#6b7280',
    'submitted' => '#3b82f6',
    'under_review' => '#f59e0b',
    'approved' => '#10b981',
    'rejected' => '#dc2626',
    'completed' => '#6366f1',
    'revision_requested' => '#8b5cf6'
];

// Format date helper
function formatDate($date) {
    if (!$date) return 'N/A';
    return date('M j, Y', strtotime($date));
}

// Get status badge HTML
function getStatusBadge($status) {
    global $status_colors;
    $status = strtolower($status);
    $color = $status_colors[$status] ?? '#6b7280';
    $text = ucfirst(str_replace('_', ' ', $status));
    
    return <<<HTML
        <span class="status-badge" style="background: rgba(${hexToRgb($color)}, 0.1); color: {$color}; border-color: rgba(${hexToRgb($color)}, 0.3);">
            {$text}
        </span>
    HTML;
}

// Get risk level badge HTML
function getRiskBadge($risk) {
    global $risk_colors;
    $risk = strtolower($risk);
    $color = $risk_colors[$risk] ?? '#6b7280';
    $text = ucfirst($risk);
    
    return <<<HTML
        <span class="risk-badge" style="background: rgba(${hexToRgb($color)}, 0.1); color: {$color}; border-color: rgba(${hexToRgb($color)}, 0.3);">
            {$text}
        </span>
    HTML;
}

// Get hazard level badge HTML
function getHazardBadge($hazard) {
    global $hazard_colors;
    $hazard = strtolower($hazard);
    $color = $hazard_colors[$hazard] ?? '#6b7280';
    $text = ucfirst($hazard);
    
    return <<<HTML
        <span class="hazard-badge" style="background: rgba(${hexToRgb($color)}, 0.1); color: {$color}; border-color: rgba(${hexToRgb($color)}, 0.3);">
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
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Approve Inspection Reports - Admin - FRSM</title>
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
        
        .stat-card[data-type="pending_review"] .stat-icon-container {
            background: rgba(245, 158, 11, 0.1);
            color: var(--warning);
        }
        
        .stat-card[data-type="submitted"] .stat-icon-container {
            background: rgba(59, 130, 246, 0.1);
            color: var(--info);
        }
        
        .stat-card[data-type="under_review"] .stat-icon-container {
            background: rgba(139, 92, 246, 0.1);
            color: var(--purple);
        }
        
        .stat-card[data-type="approved"] .stat-icon-container {
            background: rgba(16, 185, 129, 0.1);
            color: var(--success);
        }
        
        .stat-card[data-type="rejected"] .stat-icon-container {
            background: rgba(220, 38, 38, 0.1);
            color: var(--danger);
        }
        
        .stat-card[data-type="completed"] .stat-icon-container {
            background: rgba(99, 102, 241, 0.1);
            color: var(--indigo);
        }
        
        .stat-card[data-type="critical_risk"] .stat-icon-container {
            background: rgba(124, 45, 18, 0.1);
            color: #7c2d12;
        }
        
        .stat-card[data-type="high_risk"] .stat-icon-container {
            background: rgba(220, 38, 38, 0.1);
            color: var(--danger);
        }
        
        .stat-card[data-type="extreme_hazard"] .stat-icon-container {
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

        /* Enhanced Filter Tabs */
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
        .reports-table-container {
            background: var(--card-bg);
            border-radius: 16px;
            overflow: hidden;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
        }

        .table-header {
            display: grid;
            grid-template-columns: 120px 200px 120px 100px 120px 120px 140px 150px;
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
            grid-template-columns: 120px 200px 120px 100px 120px 120px 140px 150px;
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
        
        .report-number {
            font-weight: 700;
            color: var(--primary-color);
            font-size: 15px;
        }
        
        .establishment-name {
            font-weight: 600;
            color: var(--text-color);
            font-size: 15px;
        }
        
        .establishment-info {
            font-size: 12px;
            color: var(--text-light);
        }
        
        /* Status Badge */
        .status-badge {
            padding: 8px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            text-transform: uppercase;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            width: fit-content;
            white-space: nowrap;
            border: 2px solid transparent;
        }
        
        .risk-badge, .hazard-badge {
            padding: 6px 10px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            border: 1px solid;
            border-color: inherit;
        }

        /* Enhanced Action Buttons */
        .action-buttons {
            display: flex;
            gap: 8px;
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
            font-size: 13px;
            min-width: 80px;
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
        
        .review-button {
            background: linear-gradient(135deg, rgba(245, 158, 11, 0.1), rgba(245, 158, 11, 0.2));
            color: var(--warning);
            border: 1px solid rgba(245, 158, 11, 0.3);
        }
        
        .review-button:hover {
            background: var(--warning);
            color: white;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(245, 158, 11, 0.3);
        }
        
        .approve-button {
            background: linear-gradient(135deg, rgba(16, 185, 129, 0.1), rgba(16, 185, 129, 0.2));
            color: var(--success);
            border: 1px solid rgba(16, 185, 129, 0.3);
        }
        
        .approve-button:hover {
            background: var(--success);
            color: white;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(16, 185, 129, 0.3);
        }
        
        .reject-button {
            background: linear-gradient(135deg, rgba(220, 38, 38, 0.1), rgba(220, 38, 38, 0.2));
            color: var(--danger);
            border: 1px solid rgba(220, 38, 38, 0.3);
        }
        
        .reject-button:hover {
            background: var(--danger);
            color: white;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(220, 38, 38, 0.3);
        }
        
        .certificate-button {
            background: linear-gradient(135deg, rgba(139, 92, 246, 0.1), rgba(139, 92, 246, 0.2));
            color: var(--purple);
            border: 1px solid rgba(139, 92, 246, 0.3);
        }
        
        .certificate-button:hover {
            background: var(--purple);
            color: white;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(139, 92, 246, 0.3);
        }

        .no-reports {
            text-align: center;
            padding: 60px 20px;
            color: var(--text-light);
            grid-column: 1 / -1;
        }
        
        .no-reports-icon {
            font-size: 64px;
            margin-bottom: 16px;
            color: var(--text-light);
            opacity: 0.5;
        }

        /* Violation Indicators */
        .violation-indicators {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
        }
        
        .violation-badge {
            padding: 4px 8px;
            border-radius: 6px;
            font-size: 11px;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 4px;
        }
        
        .critical-violation {
            background: rgba(124, 45, 18, 0.1);
            color: #7c2d12;
            border: 1px solid rgba(124, 45, 18, 0.3);
        }
        
        .major-violation {
            background: rgba(220, 38, 38, 0.1);
            color: var(--danger);
            border: 1px solid rgba(220, 38, 38, 0.3);
        }
        
        .minor-violation {
            background: rgba(245, 158, 11, 0.1);
            color: var(--warning);
            border: 1px solid rgba(245, 158, 11, 0.3);
        }
        
        .pending-violation {
            background: rgba(59, 130, 246, 0.1);
            color: var(--info);
            border: 1px solid rgba(59, 130, 246, 0.3);
        }

        /* Compliance Score */
        .compliance-score {
            font-size: 24px;
            font-weight: 800;
            line-height: 1;
        }
        
        .compliance-score-high {
            color: var(--success);
        }
        
        .compliance-score-medium {
            color: var(--warning);
        }
        
        .compliance-score-low {
            color: var(--danger);
        }
        
        .compliance-score-critical {
            color: #7c2d12;
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
            background: linear-gradient(135deg, rgba(59, 130, 246, 0.1), rgba(59, 130, 246, 0.2));
            color: var(--info);
        }
        
        .quick-action-card:nth-child(2) .action-icon {
            background: linear-gradient(135deg, rgba(16, 185, 129, 0.1), rgba(16, 185, 129, 0.2));
            color: var(--success);
        }
        
        .quick-action-card:nth-child(3) .action-icon {
            background: linear-gradient(135deg, rgba(139, 92, 246, 0.1), rgba(139, 92, 246, 0.2));
            color: var(--purple);
        }
        
        .quick-action-card:nth-child(4) .action-icon {
            background: linear-gradient(135deg, rgba(245, 158, 11, 0.1), rgba(245, 158, 11, 0.2));
            color: var(--warning);
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
            max-width: 1000px;
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
        
        .form-select, .form-textarea, .form-input {
            width: 100%;
            padding: 12px 16px;
            border-radius: 10px;
            border: 1px solid var(--border-color);
            background: var(--card-bg);
            color: var(--text-color);
            font-size: 14px;
            transition: all 0.3s ease;
        }
        
        .form-select:focus, .form-textarea:focus, .form-input:focus {
            outline: none;
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(220, 38, 38, 0.1);
        }
        
        .form-textarea {
            min-height: 100px;
            resize: vertical;
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

        /* Responsive Design */
        @media (max-width: 1400px) {
            .table-header, .table-row {
                grid-template-columns: 100px 180px 100px 90px 110px 110px 120px 140px;
                gap: 12px;
                padding: 16px;
            }
        }

        @media (max-width: 1200px) {
            .table-header, .table-row {
                grid-template-columns: 90px 160px 90px 80px 100px 100px 110px 130px;
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
                grid-template-columns: 140px 1fr;
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

        .reports-table-container {
            max-height: 600px;
            overflow-y: auto;
        }

        .reports-table-container::-webkit-scrollbar {
            width: 6px;
        }
        
        .reports-table-container::-webkit-scrollbar-track {
            background: var(--gray-100);
            border-radius: 3px;
        }
        
        .reports-table-container::-webkit-scrollbar-thumb {
            background: var(--gray-400);
            border-radius: 3px;
        }
        
        .reports-table-container::-webkit-scrollbar-thumb:hover {
            background: var(--gray-500);
        }
        
        .dark-mode .reports-table-container::-webkit-scrollbar-track {
            background: var(--gray-800);
        }
        
        .dark-mode .reports-table-container::-webkit-scrollbar-thumb {
            background: var(--gray-600);
        }
        
        .dark-mode .reports-table-container::-webkit-scrollbar-thumb:hover {
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

        .table-row:nth-child(even) {
            background: rgba(220, 38, 38, 0.01);
        }
        
        .dark-mode .table-row:nth-child(even) {
            background: rgba(255, 255, 255, 0.01);
        }

        /* Compliance Score Circle */
        .compliance-circle {
            width: 60px;
            height: 60px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 800;
            font-size: 18px;
            position: relative;
            margin: 0 auto;
        }
        
        .compliance-circle-high {
            background: rgba(16, 185, 129, 0.1);
            color: var(--success);
            border: 3px solid var(--success);
        }
        
        .compliance-circle-medium {
            background: rgba(245, 158, 11, 0.1);
            color: var(--warning);
            border: 3px solid var(--warning);
        }
        
        .compliance-circle-low {
            background: rgba(220, 38, 38, 0.1);
            color: var(--danger);
            border: 3px solid var(--danger);
        }
        
        .compliance-circle-critical {
            background: rgba(124, 45, 18, 0.1);
            color: #7c2d12;
            border: 3px solid #7c2d12;
        }
    </style>
</head>
<body>
    <!-- Report Details Modal -->
    <div class="modal-overlay" id="details-modal">
        <div class="modal">
            <div class="modal-header">
                <h2 class="modal-title">Inspection Report Details</h2>
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
    
    <!-- Review Report Modal -->
    <div class="modal-overlay" id="review-modal">
        <div class="modal">
            <div class="modal-header">
                <h2 class="modal-title">Review Inspection Report</h2>
                <button class="modal-close" id="review-modal-close">&times;</button>
            </div>
            <div class="modal-body">
                <form id="review-report-form">
                    <input type="hidden" id="review-report-id" name="report_id">
                    
                    <div class="form-group">
                        <label class="form-label" for="review_status">Review Status</label>
                        <select class="form-select" id="review_status" name="status" required>
                            <option value="approved">Approve Report</option>
                            <option value="rejected">Reject Report</option>
                            <option value="revision_requested">Request Revision</option>
                            <option value="under_review">Mark as Under Review</option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label" for="review_notes">Review Notes & Comments</label>
                        <textarea class="form-textarea" id="review_notes" name="review_notes" placeholder="Enter your review comments, suggestions for revision, or reasons for approval/rejection..." required></textarea>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label" for="compliance_deadline">Compliance Deadline (if applicable)</label>
                        <input type="date" class="form-input" id="compliance_deadline" name="compliance_deadline">
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label" for="certificate_decision">Certificate Decision</label>
                        <select class="form-select" id="certificate_decision" name="certificate_decision">
                            <option value="none">No Decision</option>
                            <option value="issue">Issue Fire Safety Certificate</option>
                            <option value="renew">Renew Existing Certificate</option>
                            <option value="revoke">Revoke Certificate</option>
                        </select>
                    </div>
                    
                    <div class="modal-actions">
                        <button type="button" class="btn btn-secondary" id="cancel-review">Cancel</button>
                        <button type="submit" class="btn btn-primary">Submit Review</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Certificate Issue Modal -->
    <div class="modal-overlay" id="certificate-modal">
        <div class="modal">
            <div class="modal-header">
                <h2 class="modal-title">Issue Fire Safety Certificate</h2>
                <button class="modal-close" id="certificate-modal-close">&times;</button>
            </div>
            <div class="modal-body">
                <form id="issue-certificate-form">
                    <input type="hidden" id="certificate-report-id" name="report_id">
                    <input type="hidden" id="certificate-establishment-id" name="establishment_id">
                    
                    <div class="form-group">
                        <label class="form-label" for="certificate_type">Certificate Type</label>
                        <select class="form-select" id="certificate_type" name="certificate_type" required>
                            <option value="fsic">Fire Safety Inspection Certificate (FSIC)</option>
                            <option value="compliance">Compliance Certificate</option>
                            <option value="provisional">Provisional Certificate</option>
                            <option value="exemption">Exemption Certificate</option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label" for="issue_date">Issue Date</label>
                        <input type="date" class="form-input" id="issue_date" name="issue_date" required value="<?php echo date('Y-m-d'); ?>">
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label" for="valid_until">Valid Until</label>
                        <input type="date" class="form-input" id="valid_until" name="valid_until" required>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label" for="certificate_notes">Certificate Notes (Optional)</label>
                        <textarea class="form-textarea" id="certificate_notes" name="certificate_notes" placeholder="Any additional notes for the certificate..."></textarea>
                    </div>
                    
                    <div class="modal-actions">
                        <button type="button" class="btn btn-secondary" id="cancel-certificate">Cancel</button>
                        <button type="submit" class="btn btn-primary">Issue Certificate</button>
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
                        <svg class="dropdown-arrow menu-icon rotated" fill="none" stroke="currentColor" viewBox="0 0 24 24">
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
                        <a href="approve_reports.php" class="submenu-item active">Approve Reports</a>
                        <a href="review_violations.php" class="submenu-item">Review Violations</a>
                        <a href="issue_certificates.php" class="submenu-item">Issue Certificates</a>
                        <a href="track_follow_up.php" class="submenu-item">Track Follow-Up</a>
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
                            <input type="text" placeholder="Search inspection reports..." class="search-input" id="search-input">
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
                        <h1 class="dashboard-title">Inspection Reports Approval</h1>
                        <p class="dashboard-subtitle">Admin Panel - Review and approve establishment inspection reports</p>
                    </div>
                </div>
                
                <!-- Content Container -->
                <div class="content-container">
                    <!-- Quick Actions -->
                    <div class="quick-actions">
                        <a href="../ile/review_violations.php" class="quick-action-card">
                            <div class="action-icon">
                                <i class='bx bxs-error-circle'></i>
                            </div>
                            <div class="action-content">
                                <div class="action-title">Review Violations</div>
                                <div class="action-description">Review and process violation reports</div>
                            </div>
                        </a>
                        <a href="../ile/issue_certificates.php" class="quick-action-card">
                            <div class="action-icon">
                                <i class='bx bxs-certificate'></i>
                            </div>
                            <div class="action-content">
                                <div class="action-title">Issue Certificates</div>
                                <div class="action-description">Generate and issue fire safety certificates</div>
                            </div>
                        </a>
                        <a href="../ile/track_follow_up.php" class="quick-action-card">
                            <div class="action-icon">
                                <i class='bx bxs-calendar-check'></i>
                            </div>
                            <div class="action-content">
                                <div class="action-title">Track Follow-ups</div>
                                <div class="action-description">Monitor compliance follow-up actions</div>
                            </div>
                        </a>
                        <a href="#" class="quick-action-card" onclick="generateInspectionSummary()">
                            <div class="action-icon">
                                <i class='bx bxs-report'></i>
                            </div>
                            <div class="action-content">
                                <div class="action-title">Generate Report</div>
                                <div class="action-description">Create inspection summary report</div>
                            </div>
                        </a>
                    </div>
                    
                    <!-- Statistics Cards -->
                    <div class="stats-grid">
                        <div class="stat-card" data-type="total" onclick="filterByStatus('all')">
                            <div class="stat-header">
                                <div class="stat-icon-container">
                                    <i class='bx bxs-file'></i>
                                </div>
                                <div class="stat-trend">
                                    <i class='bx bx-up-arrow-alt'></i>
                                    +15%
                                </div>
                            </div>
                            <div class="stat-value"><?php echo $stats['total']; ?></div>
                            <div class="stat-label">Total Reports</div>
                        </div>
                        <div class="stat-card" data-type="pending_review" onclick="filterByStatus('pending_review')">
                            <div class="stat-header">
                                <div class="stat-icon-container">
                                    <i class='bx bxs-time-five'></i>
                                </div>
                                <div class="stat-trend">
                                    <i class='bx bx-up-arrow-alt'></i>
                                    +8%
                                </div>
                            </div>
                            <div class="stat-value"><?php echo $stats['pending_review']; ?></div>
                            <div class="stat-label">Pending Review</div>
                        </div>
                        <div class="stat-card" data-type="approved" onclick="filterByStatus('approved')">
                            <div class="stat-header">
                                <div class="stat-icon-container">
                                    <i class='bx bxs-check-circle'></i>
                                </div>
                                <div class="stat-trend">
                                    <i class='bx bx-up-arrow-alt'></i>
                                    +12%
                                </div>
                            </div>
                            <div class="stat-value"><?php echo $stats['approved']; ?></div>
                            <div class="stat-label">Approved</div>
                        </div>
                        <div class="stat-card" data-type="rejected" onclick="filterByStatus('rejected')">
                            <div class="stat-header">
                                <div class="stat-icon-container">
                                    <i class='bx bxs-x-circle'></i>
                                </div>
                                <div class="stat-trend">
                                    <i class='bx bx-down-arrow-alt'></i>
                                    -3%
                                </div>
                            </div>
                            <div class="stat-value"><?php echo $stats['rejected']; ?></div>
                            <div class="stat-label">Rejected</div>
                        </div>
                        <div class="stat-card" data-type="critical_risk" onclick="filterByRisk('critical')">
                            <div class="stat-header">
                                <div class="stat-icon-container">
                                    <i class='bx bxs-alarm-exclamation'></i>
                                </div>
                                <div class="stat-trend">
                                    <i class='bx bx-up-arrow-alt'></i>
                                    +5%
                                </div>
                            </div>
                            <div class="stat-value"><?php echo $stats['critical_risk']; ?></div>
                            <div class="stat-label">Critical Risk</div>
                        </div>
                        <div class="stat-card" data-type="high_risk" onclick="filterByRisk('high')">
                            <div class="stat-header">
                                <div class="stat-icon-container">
                                    <i class='bx bxs-error-circle'></i>
                                </div>
                                <div class="stat-trend">
                                    <i class='bx bx-up-arrow-alt'></i>
                                    +7%
                                </div>
                            </div>
                            <div class="stat-value"><?php echo $stats['high_risk']; ?></div>
                            <div class="stat-label">High Risk</div>
                        </div>
                        <div class="stat-card" data-type="extreme_hazard" onclick="filterByHazard('extreme')">
                            <div class="stat-header">
                                <div class="stat-icon-container">
                                    <i class='bx bxs-flame'></i>
                                </div>
                                <div class="stat-trend">
                                    <i class='bx bx-up-arrow-alt'></i>
                                    +4%
                                </div>
                            </div>
                            <div class="stat-value"><?php echo $stats['extreme_hazard']; ?></div>
                            <div class="stat-label">Extreme Hazard</div>
                        </div>
                        <div class="stat-card" data-type="completed" onclick="filterByStatus('completed')">
                            <div class="stat-header">
                                <div class="stat-icon-container">
                                    <i class='bx bxs-check-double'></i>
                                </div>
                                <div class="stat-trend">
                                    <i class='bx bx-up-arrow-alt'></i>
                                    +10%
                                </div>
                            </div>
                            <div class="stat-value"><?php echo $stats['completed']; ?></div>
                            <div class="stat-label">Completed</div>
                        </div>
                    </div>
                    
                    <!-- Filter Tabs Container -->
                    <div class="filter-tabs-container">
                        <div class="filter-header">
                            <h3 class="filter-title">
                                <i class='bx bxs-check-shield'></i>
                                Inspection Reports - Approval Queue
                            </h3>
                        </div>
                        
                        <div class="filter-tabs">
                            <a href="?status=all&date=<?php echo $filter_date; ?>&search=<?php echo urlencode($search_query); ?>&barangay=<?php echo $filter_barangay; ?>&establishment_type=<?php echo $filter_establishment_type; ?>" class="filter-tab <?php echo $filter_status === 'all' ? 'active' : ''; ?>">
                                <i class='bx bxs-dashboard'></i>
                                All Reports
                                <span class="filter-tab-count"><?php echo $stats['total']; ?></span>
                            </a>
                            <a href="?status=pending_review&date=<?php echo $filter_date; ?>&search=<?php echo urlencode($search_query); ?>&barangay=<?php echo $filter_barangay; ?>&establishment_type=<?php echo $filter_establishment_type; ?>" class="filter-tab <?php echo $filter_status === 'pending_review' ? 'active' : ''; ?>">
                                <i class='bx bxs-time-five'></i>
                                Pending Review
                                <span class="filter-tab-count"><?php echo $stats['pending_review']; ?></span>
                            </a>
                            <a href="?status=submitted&date=<?php echo $filter_date; ?>&search=<?php echo urlencode($search_query); ?>&barangay=<?php echo $filter_barangay; ?>&establishment_type=<?php echo $filter_establishment_type; ?>" class="filter-tab <?php echo $filter_status === 'submitted' ? 'active' : ''; ?>">
                                <i class='bx bxs-send'></i>
                                Submitted
                                <span class="filter-tab-count"><?php echo $stats['submitted']; ?></span>
                            </a>
                            <a href="?status=under_review&date=<?php echo $filter_date; ?>&search=<?php echo urlencode($search_query); ?>&barangay=<?php echo $filter_barangay; ?>&establishment_type=<?php echo $filter_establishment_type; ?>" class="filter-tab <?php echo $filter_status === 'under_review' ? 'active' : ''; ?>">
                                <i class='bx bxs-edit'></i>
                                Under Review
                                <span class="filter-tab-count"><?php echo $stats['under_review']; ?></span>
                            </a>
                            <a href="?status=approved&date=<?php echo $filter_date; ?>&search=<?php echo urlencode($search_query); ?>&barangay=<?php echo $filter_barangay; ?>&establishment_type=<?php echo $filter_establishment_type; ?>" class="filter-tab <?php echo $filter_status === 'approved' ? 'active' : ''; ?>">
                                <i class='bx bxs-check-circle'></i>
                                Approved
                            </a>
                            <a href="?status=rejected&date=<?php echo $filter_date; ?>&search=<?php echo urlencode($search_query); ?>&barangay=<?php echo $filter_barangay; ?>&establishment_type=<?php echo $filter_establishment_type; ?>" class="filter-tab <?php echo $filter_status === 'rejected' ? 'active' : ''; ?>">
                                <i class='bx bxs-x-circle'></i>
                                Rejected
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
                                            <i class='bx bx-calendar'></i>
                                            Inspection Date
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
                                    
                                    <div class="filter-group">
                                        <label class="filter-label">
                                            <i class='bx bxs-business'></i>
                                            Establishment Type
                                        </label>
                                        <select class="filter-select" name="establishment_type">
                                            <option value="">All Types</option>
                                            <?php foreach ($establishment_types as $type): ?>
                                                <option value="<?php echo htmlspecialchars($type); ?>" <?php echo $filter_establishment_type === $type ? 'selected' : ''; ?>>
                                                    <?php echo htmlspecialchars($type); ?>
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
                                        <input type="text" class="filter-input" name="search" placeholder="Search by report number, establishment name, owner..." value="<?php echo htmlspecialchars($search_query); ?>">
                                    </div>
                                </div>
                                
                                <div class="filter-actions">
                                    <a href="approve_reports.php" class="filter-button clear-filters">
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
                    
                    <!-- Reports Table -->
                    <div class="reports-table-container">
                        <div class="table-header">
                            <div>Report #</div>
                            <div>Establishment</div>
                            <div>Inspection Date</div>
                            <div>Compliance</div>
                            <div>Risk Level</div>
                            <div>Violations</div>
                            <div>Status</div>
                            <div>Actions</div>
                        </div>
                        <div class="reports-table-container" style="max-height: 500px;">
                            <?php if (count($reports) > 0): ?>
                                <?php foreach ($reports as $index => $report): ?>
                                    <?php 
                                    $inspectionDate = new DateTime($report['inspection_date']);
                                    $complianceScore = $report['overall_compliance_score'];
                                    
                                    // Determine compliance score class
                                    if ($complianceScore >= 80) {
                                        $scoreClass = 'compliance-score-high';
                                        $circleClass = 'compliance-circle-high';
                                    } elseif ($complianceScore >= 60) {
                                        $scoreClass = 'compliance-score-medium';
                                        $circleClass = 'compliance-circle-medium';
                                    } elseif ($complianceScore >= 40) {
                                        $scoreClass = 'compliance-score-low';
                                        $circleClass = 'compliance-circle-low';
                                    } else {
                                        $scoreClass = 'compliance-score-critical';
                                        $circleClass = 'compliance-circle-critical';
                                    }
                                    ?>
                                    <div class="table-row" style="animation-delay: <?php echo $index * 0.05; ?>s;">
                                        <div class="table-cell" data-label="Report #">
                                            <div class="report-number"><?php echo $report['report_number']; ?></div>
                                        </div>
                                        <div class="table-cell" data-label="Establishment">
                                            <div class="establishment-name"><?php echo htmlspecialchars($report['establishment_name']); ?></div>
                                            <div class="establishment-info">
                                                <?php echo htmlspecialchars($report['establishment_type']); ?> • <?php echo htmlspecialchars($report['barangay']); ?>
                                            </div>
                                            <div class="establishment-info" style="font-size: 11px;">
                                                Owner: <?php echo htmlspecialchars($report['owner_name']); ?>
                                            </div>
                                        </div>
                                        <div class="table-cell" data-label="Inspection Date">
                                            <div style="font-weight: 600;"><?php echo formatDate($report['inspection_date']); ?></div>
                                            <div style="font-size: 12px; color: var(--text-light);">
                                                Inspector: <?php echo htmlspecialchars($report['inspector_name']); ?>
                                            </div>
                                        </div>
                                        <div class="table-cell" data-label="Compliance">
                                            <div class="compliance-circle <?php echo $circleClass; ?>">
                                                <?php echo $complianceScore; ?>%
                                            </div>
                                        </div>
                                        <div class="table-cell" data-label="Risk Level">
                                            <div>
                                                <?php echo getRiskBadge($report['risk_assessment']); ?>
                                            </div>
                                            <div style="margin-top: 4px;">
                                                <?php echo getHazardBadge($report['fire_hazard_level']); ?>
                                            </div>
                                        </div>
                                        <div class="table-cell" data-label="Violations">
                                            <div class="violation-indicators">
                                                <?php if ($report['critical_violations'] > 0): ?>
                                                    <span class="violation-badge critical-violation">
                                                        <i class='bx bxs-error'></i>
                                                        <?php echo $report['critical_violations']; ?> Critical
                                                    </span>
                                                <?php endif; ?>
                                                <?php if ($report['major_violations'] > 0): ?>
                                                    <span class="violation-badge major-violation">
                                                        <i class='bx bxs-error-circle'></i>
                                                        <?php echo $report['major_violations']; ?> Major
                                                    </span>
                                                <?php endif; ?>
                                                <?php if ($report['minor_violations'] > 0): ?>
                                                    <span class="violation-badge minor-violation">
                                                        <i class='bx bxs-error-alt'></i>
                                                        <?php echo $report['minor_violations']; ?> Minor
                                                    </span>
                                                <?php endif; ?>
                                                <?php if ($report['pending_violations'] > 0): ?>
                                                    <span class="violation-badge pending-violation">
                                                        <i class='bx bxs-time'></i>
                                                        <?php echo $report['pending_violations']; ?> Pending
                                                    </span>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                        <div class="table-cell" data-label="Status">
                                            <?php echo getStatusBadge($report['status']); ?>
                                            <?php if ($report['reviewer_name']): ?>
                                                <div style="font-size: 11px; color: var(--text-light); margin-top: 4px;">
                                                    Reviewed by: <?php echo htmlspecialchars($report['reviewer_name']); ?>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                        <div class="table-cell" data-label="Actions">
                                            <div class="action-buttons">
                                                <button class="action-button view-button" onclick="viewReportDetails(<?php echo $report['id']; ?>)">
                                                    <i class='bx bxs-info-circle'></i>
                                                    View
                                                </button>
                                                
                                                <?php if (in_array($report['status'], ['submitted', 'under_review', 'revision_requested'])): ?>
                                                    <button class="action-button review-button" onclick="reviewReport(<?php echo $report['id']; ?>)">
                                                        <i class='bx bxs-edit'></i>
                                                        Review
                                                    </button>
                                                    
                                                    <button class="action-button approve-button" onclick="quickApprove(<?php echo $report['id']; ?>)">
                                                        <i class='bx bxs-check-circle'></i>
                                                        Approve
                                                    </button>
                                                    
                                                    <button class="action-button reject-button" onclick="quickReject(<?php echo $report['id']; ?>)">
                                                        <i class='bx bxs-x-circle'></i>
                                                        Reject
                                                    </button>
                                                <?php endif; ?>
                                                
                                                <?php if ($report['status'] === 'approved' && !$report['certificate_number']): ?>
                                                    <button class="action-button certificate-button" onclick="issueCertificate(<?php echo $report['id']; ?>, <?php echo isset($report['establishment_id']) ? $report['establishment_id'] : 'null'; ?>)">
                                                        <i class='bx bxs-certificate'></i>
                                                        Certificate
                                                    </button>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <div class="no-reports">
                                    <div class="no-reports-icon">
                                        <i class='bx bxs-file-blank'></i>
                                    </div>
                                    <h3>No Inspection Reports Found</h3>
                                    <p>No reports match your current filters.</p>
                                    <?php if ($filter_status !== 'all' || $filter_date !== '' || $search_query !== '' || $filter_barangay !== '' || $filter_establishment_type !== ''): ?>
                                        <a href="approve_reports.php" class="filter-button" style="margin-top: 16px;">
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
            
            // Set default valid until date to one year from now
            const validUntilInput = document.getElementById('valid_until');
            if (validUntilInput) {
                const oneYearFromNow = new Date();
                oneYearFromNow.setFullYear(oneYearFromNow.getFullYear() + 1);
                validUntilInput.value = oneYearFromNow.toISOString().split('T')[0];
            }
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
            
            // Review modal functionality
            const reviewModal = document.getElementById('review-modal');
            const reviewModalClose = document.getElementById('review-modal-close');
            const cancelReview = document.getElementById('cancel-review');
            
            reviewModalClose.addEventListener('click', closeReviewModal);
            cancelReview.addEventListener('click', closeReviewModal);
            
            reviewModal.addEventListener('click', function(e) {
                if (e.target === this) {
                    closeReviewModal();
                }
            });
            
            // Certificate modal functionality
            const certificateModal = document.getElementById('certificate-modal');
            const certificateModalClose = document.getElementById('certificate-modal-close');
            const cancelCertificate = document.getElementById('cancel-certificate');
            
            certificateModalClose.addEventListener('click', closeCertificateModal);
            cancelCertificate.addEventListener('click', closeCertificateModal);
            
            certificateModal.addEventListener('click', function(e) {
                if (e.target === this) {
                    closeCertificateModal();
                }
            });
            
            // Review report form submission
            const reviewForm = document.getElementById('review-report-form');
            reviewForm.addEventListener('submit', function(e) {
                e.preventDefault();
                submitReview();
            });
            
            // Issue certificate form submission
            const certificateForm = document.getElementById('issue-certificate-form');
            certificateForm.addEventListener('submit', function(e) {
                e.preventDefault();
                issueCertificateSubmit();
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
                    const rowIndex = Math.floor(index / 8); // 8 columns
                    const colIndex = index % 8;
                    
                    if (colIndex < headerLabels.length) {
                        cell.setAttribute('data-label', headerLabels[colIndex]);
                    }
                });
            }
        }
        
        function viewReportDetails(reportId) {
            const detailsModal = document.getElementById('details-modal');
            const detailsContent = document.getElementById('details-content');
            
            // Show loading animation
            detailsContent.innerHTML = `
                <div style="text-align: center; padding: 40px;">
                    <div style="width: 60px; height: 60px; margin: 0 auto 20px; border: 4px solid rgba(220, 38, 38, 0.1); border-top-color: var(--primary-color); border-radius: 50%; animation: spin 1s linear infinite;"></div>
                    <p style="color: var(--text-light);">Loading report details...</p>
                </div>
                <style>
                    @keyframes spin {
                        0% { transform: rotate(0deg); }
                        100% { transform: rotate(360deg); }
                    }
                </style>
            `;
            
            // Fetch report details via AJAX
            fetch(`get_report_details.php?id=${reportId}`)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        renderReportDetails(data.report);
                    } else {
                        detailsContent.innerHTML = `
                            <div style="text-align: center; padding: 40px; color: var(--danger);">
                                <i class="bx bx-error" style="font-size: 48px; margin-bottom: 16px;"></i>
                                <h3 style="margin-bottom: 8px;">Error</h3>
                                <p>${data.message || 'Failed to load report details'}</p>
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
                            <p>Failed to load report details. Please check your connection and try again.</p>
                        </div>
                    `;
                });
            
            // Open modal
            detailsModal.classList.add('active');
        }
        
        function renderReportDetails(report) {
            const detailsContent = document.getElementById('details-content');
            
            // Determine compliance score class
            let complianceClass = 'compliance-score-high';
            let complianceCircleClass = 'compliance-circle-high';
            if (report.overall_compliance_score < 80) complianceClass = 'compliance-score-medium';
            if (report.overall_compliance_score < 60) complianceClass = 'compliance-score-low';
            if (report.overall_compliance_score < 40) complianceClass = 'compliance-score-critical';
            
            // Generate violation summary HTML
            let violationsHtml = '';
            if (report.violations_summary) {
                violationsHtml = `
                    <div style="background: linear-gradient(135deg, rgba(220, 38, 38, 0.05), rgba(220, 38, 38, 0.1)); border-radius: 12px; padding: 20px; margin-bottom: 20px; border-left: 4px solid var(--danger);">
                        <h4 style="margin: 0 0 16px 0; color: var(--danger); display: flex; align-items: center; gap: 8px;">
                            <i class='bx bxs-error-circle'></i>
                            Violations Summary
                        </h4>
                        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 16px;">
                            <div style="background: var(--card-bg); border: 1px solid var(--border-color); border-radius: 8px; padding: 12px; text-align: center;">
                                <div style="font-size: 24px; font-weight: 800; color: #7c2d12;">${report.violations_summary.critical || 0}</div>
                                <div style="font-size: 11px; color: var(--text-light); text-transform: uppercase; letter-spacing: 0.5px;">Critical</div>
                            </div>
                            <div style="background: var(--card-bg); border: 1px solid var(--border-color); border-radius: 8px; padding: 12px; text-align: center;">
                                <div style="font-size: 24px; font-weight: 800; color: var(--danger);">${report.violations_summary.major || 0}</div>
                                <div style="font-size: 11px; color: var(--text-light); text-transform: uppercase; letter-spacing: 0.5px;">Major</div>
                            </div>
                            <div style="background: var(--card-bg); border: 1px solid var(--border-color); border-radius: 8px; padding: 12px; text-align: center;">
                                <div style="font-size: 24px; font-weight: 800; color: var(--warning);">${report.violations_summary.minor || 0}</div>
                                <div style="font-size: 11px; color: var(--text-light); text-transform: uppercase; letter-spacing: 0.5px;">Minor</div>
                            </div>
                            <div style="background: var(--card-bg); border: 1px solid var(--border-color); border-radius: 8px; padding: 12px; text-align: center;">
                                <div style="font-size: 24px; font-weight: 800; color: var(--info);">${report.violations_summary.pending || 0}</div>
                                <div style="font-size: 11px; color: var(--text-light); text-transform: uppercase; letter-spacing: 0.5px;">Pending</div>
                            </div>
                        </div>
                    </div>`;
            }
            
            let detailsHtml = `
                <div class="report-details">
                    <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 24px;">
                        <div>
                            <h3 style="margin: 0; color: var(--primary-color);">${report.report_number}</h3>
                            <p style="margin: 4px 0 0; color: var(--text-light);">Inspection Date: ${new Date(report.inspection_date).toLocaleDateString('en-US', { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' })}</p>
                        </div>
                        <div style="display: flex; gap: 8px;">
                            ${getStatusBadge(report.status)}
                            ${getRiskBadge(report.risk_assessment)}
                        </div>
                    </div>
                    
                    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 20px; margin-bottom: 24px;">
                        <div style="background: var(--card-bg); border: 1px solid var(--border-color); border-radius: 12px; padding: 16px; text-align: center;">
                            <div style="font-size: 12px; color: var(--text-light); margin-bottom: 4px;">Compliance Score</div>
                            <div class="compliance-circle ${complianceCircleClass}" style="margin: 0 auto;">
                                ${report.overall_compliance_score}%
                            </div>
                        </div>
                        <div style="background: var(--card-bg); border: 1px solid var(--border-color); border-radius: 12px; padding: 16px;">
                            <div style="font-size: 12px; color: var(--text-light); margin-bottom: 4px;">Fire Hazard Level</div>
                            <div style="font-size: 16px; font-weight: 600;">${getHazardBadge(report.fire_hazard_level)}</div>
                        </div>
                        <div style="background: var(--card-bg); border: 1px solid var(--border-color); border-radius: 12px; padding: 16px;">
                            <div style="font-size: 12px; color: var(--text-light); margin-bottom: 4px;">Inspection Type</div>
                            <div style="font-size: 16px; font-weight: 600;">${report.inspection_type ? report.inspection_type.replace('_', ' ').toUpperCase() : 'ROUTINE'}</div>
                        </div>
                    </div>
                    
                    <div style="background: linear-gradient(135deg, rgba(59, 130, 246, 0.05), rgba(59, 130, 246, 0.1)); border-radius: 12px; padding: 20px; margin-bottom: 20px; border-left: 4px solid var(--info);">
                        <h4 style="margin: 0 0 16px 0; color: var(--info); display: flex; align-items: center; gap: 8px;">
                            <i class='bx bxs-building'></i>
                            Establishment Details
                        </h4>
                        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 16px;">
                            <div>
                                <div style="font-size: 11px; color: var(--text-light); text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 4px;">Name</div>
                                <div style="font-size: 16px; font-weight: 600;">${report.establishment_name}</div>
                            </div>
                            <div>
                                <div style="font-size: 11px; color: var(--text-light); text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 4px;">Type</div>
                                <div style="font-size: 16px; font-weight: 600;">${report.establishment_type}</div>
                            </div>
                            <div>
                                <div style="font-size: 11px; color: var(--text-light); text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 4px;">Owner</div>
                                <div style="font-size: 16px; font-weight: 600;">${report.owner_name}</div>
                            </div>
                            <div>
                                <div style="font-size: 11px; color: var(--text-light); text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 4px;">Barangay</div>
                                <div style="font-size: 16px; font-weight: 600;">${report.barangay}</div>
                            </div>
                        </div>
                        ${report.address ? `<div style="margin-top: 12px; font-size: 14px; color: var(--text-light);"><i class='bx bxs-map'></i> ${report.address}</div>` : ''}
                    </div>
                    
                    ${violationsHtml}
                    
                    ${report.recommendations ? `
                    <div style="background: linear-gradient(135deg, rgba(16, 185, 129, 0.05), rgba(16, 185, 129, 0.1)); border-radius: 12px; padding: 20px; margin-bottom: 20px; border-left: 4px solid var(--success);">
                        <h4 style="margin: 0 0 16px 0; color: var(--success); display: flex; align-items: center; gap: 8px;">
                            <i class='bx bxs-check-circle'></i>
                            Recommendations
                        </h4>
                        <div style="white-space: pre-line; font-size: 14px; line-height: 1.6;">${report.recommendations}</div>
                    </div>` : ''}
                    
                    ${report.corrective_actions_required ? `
                    <div style="background: linear-gradient(135deg, rgba(245, 158, 11, 0.05), rgba(245, 158, 11, 0.1)); border-radius: 12px; padding: 20px; margin-bottom: 20px; border-left: 4px solid var(--warning);">
                        <h4 style="margin: 0 0 16px 0; color: var(--warning); display: flex; align-items: center; gap: 8px;">
                            <i class='bx bxs-error-alt'></i>
                            Corrective Actions Required
                        </h4>
                        <div style="white-space: pre-line; font-size: 14px; line-height: 1.6;">${report.corrective_actions_required}</div>
                        ${report.compliance_deadline ? `<div style="margin-top: 12px; font-size: 14px; font-weight: 600;"><i class='bx bxs-calendar'></i> Deadline: ${new Date(report.compliance_deadline).toLocaleDateString()}</div>` : ''}
                    </div>` : ''}
                    
                    ${report.admin_review_notes ? `
                    <div style="background: linear-gradient(135deg, rgba(139, 92, 246, 0.05), rgba(139, 92, 246, 0.1)); border-radius: 12px; padding: 20px; margin-bottom: 20px; border-left: 4px solid var(--purple);">
                        <h4 style="margin: 0 0 16px 0; color: var(--purple); display: flex; align-items: center; gap: 8px;">
                            <i class='bx bxs-edit'></i>
                            Admin Review Notes
                        </h4>
                        <div style="white-space: pre-line; font-size: 14px; line-height: 1.6;">${report.admin_review_notes}</div>
                        ${report.admin_reviewed_by ? `<div style="margin-top: 12px; font-size: 12px; color: var(--text-light);">Reviewed by: ${report.reviewer_name} on ${new Date(report.admin_reviewed_at).toLocaleString()}</div>` : ''}
                    </div>` : ''}
                    
                    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 20px;">
                        <div style="background: var(--card-bg); border: 1px solid var(--border-color); border-radius: 12px; padding: 16px;">
                            <div style="font-size: 12px; color: var(--text-light); margin-bottom: 4px;">Inspected By</div>
                            <div style="font-size: 14px; font-weight: 600;">${report.inspector_name || 'N/A'}</div>
                        </div>
                        <div style="background: var(--card-bg); border: 1px solid var(--border-color); border-radius: 12px; padding: 16px;">
                            <div style="font-size: 12px; color: var(--text-light); margin-bottom: 4px;">Report Created</div>
                            <div style="font-size: 14px; font-weight: 600;">${new Date(report.created_at).toLocaleString()}</div>
                        </div>
                        ${report.certificate_number ? `
                        <div style="background: var(--card-bg); border: 1px solid var(--border-color); border-radius: 12px; padding: 16px;">
                            <div style="font-size: 12px; color: var(--text-light); margin-bottom: 4px;">Certificate</div>
                            <div style="font-size: 14px; font-weight: 600;">${report.certificate_number}</div>
                            <div style="font-size: 12px; color: var(--text-light);">Valid until: ${new Date(report.certificate_valid_until).toLocaleDateString()}</div>
                        </div>` : ''}
                    </div>
                </div>`;
            
            detailsContent.innerHTML = detailsHtml;
        }
        
        function reviewReport(reportId) {
            const reviewModal = document.getElementById('review-modal');
            const reviewReportId = document.getElementById('review-report-id');
            
            reviewReportId.value = reportId;
            
            // Open modal
            reviewModal.classList.add('active');
        }
        
        function issueCertificate(reportId, establishmentId) {
            const certificateModal = document.getElementById('certificate-modal');
            const certificateReportId = document.getElementById('certificate-report-id');
            const certificateEstablishmentId = document.getElementById('certificate-establishment-id');
            
            certificateReportId.value = reportId;
            certificateEstablishmentId.value = establishmentId;
            
            // Open modal
            certificateModal.classList.add('active');
        }
        
        function quickApprove(reportId) {
            if (confirm('Are you sure you want to approve this inspection report? This action cannot be undone.')) {
                fetch('approve_report.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({ 
                        report_id: reportId,
                        action: 'approve',
                        review_notes: 'Approved via quick action.'
                    })
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        showNotification('success', data.message || 'Report approved successfully!');
                        setTimeout(() => {
                            location.reload();
                        }, 1500);
                    } else {
                        showNotification('error', data.message || 'Failed to approve report');
                    }
                })
                .catch(error => {
                    showNotification('error', 'Error: ' + error.message);
                });
            }
        }
        
        function quickReject(reportId) {
            if (confirm('Are you sure you want to reject this inspection report? This action cannot be undone.')) {
                fetch('approve_report.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({ 
                        report_id: reportId,
                        action: 'reject',
                        review_notes: 'Rejected via quick action.'
                    })
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        showNotification('success', data.message || 'Report rejected successfully!');
                        setTimeout(() => {
                            location.reload();
                        }, 1500);
                    } else {
                        showNotification('error', data.message || 'Failed to reject report');
                    }
                })
                .catch(error => {
                    showNotification('error', 'Error: ' + error.message);
                });
            }
        }
        
        function submitReview() {
            const form = document.getElementById('review-report-form');
            const formData = new FormData(form);
            
            fetch('approve_report.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showNotification('success', data.message || 'Review submitted successfully!');
                    closeReviewModal();
                    setTimeout(() => {
                        location.reload();
                    }, 1500);
                } else {
                    showNotification('error', data.message || 'Failed to submit review');
                }
            })
            .catch(error => {
                showNotification('error', 'Error: ' + error.message);
            });
        }
        
        function issueCertificateSubmit() {
            const form = document.getElementById('issue-certificate-form');
            const formData = new FormData(form);
            
            fetch('issue_certificate.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showNotification('success', data.message || 'Certificate issued successfully!');
                    closeCertificateModal();
                    setTimeout(() => {
                        location.reload();
                    }, 1500);
                } else {
                    showNotification('error', data.message || 'Failed to issue certificate');
                }
            })
            .catch(error => {
                showNotification('error', 'Error: ' + error.message);
            });
        }
        
        function generateInspectionSummary() {
            // Show loading
            showNotification('info', 'Generating inspection summary report...');
            
            fetch('generate_inspection_summary.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({ 
                    filter_status: '<?php echo $filter_status; ?>',
                    filter_date: '<?php echo $filter_date; ?>',
                    filter_barangay: '<?php echo $filter_barangay; ?>',
                    filter_establishment_type: '<?php echo $filter_establishment_type; ?>'
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
        
        function closeReviewModal() {
            document.getElementById('review-modal').classList.remove('active');
        }
        
        function closeCertificateModal() {
            document.getElementById('certificate-modal').classList.remove('active');
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
                case 'pending_review':
                    filterByStatus('pending_review');
                    break;
                case 'submitted':
                    filterByStatus('submitted');
                    break;
                case 'under_review':
                    filterByStatus('under_review');
                    break;
                case 'approved':
                    filterByStatus('approved');
                    break;
                case 'rejected':
                    filterByStatus('rejected');
                    break;
                case 'critical_risk':
                    filterByRisk('critical');
                    break;
                case 'high_risk':
                    filterByRisk('high');
                    break;
                case 'extreme_hazard':
                    filterByHazard('extreme');
                    break;
                case 'completed':
                    filterByStatus('completed');
                    break;
            }
        }
        
        function filterByStatus(status) {
            const url = new URL(window.location.href);
            url.searchParams.set('status', status);
            window.location.href = url.toString();
        }
        
        function filterByRisk(risk) {
            // This would need backend implementation
            showNotification('info', 'Risk filtering coming soon...');
        }
        
        function filterByHazard(hazard) {
            // This would need backend implementation
            showNotification('info', 'Hazard filtering coming soon...');
        }
        
        // Handle window resize for responsive layout
        window.addEventListener('resize', addDataLabels);
    </script>
</body>
</html>