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

// Handle report generation
$report_type = $_GET['report_type'] ?? 'resource_utilization';
$start_date = $_GET['start_date'] ?? date('Y-m-01'); // First day of current month
$end_date = $_GET['end_date'] ?? date('Y-m-t'); // Last day of current month
$unit_id = $_GET['unit_id'] ?? 'all';
$resource_type = $_GET['resource_type'] ?? 'all';
$category = $_GET['category'] ?? 'all';

// Fetch units for filters
$units_query = "SELECT id, unit_name FROM units WHERE status = 'Active' ORDER BY unit_name";
$units = $pdo->query($units_query)->fetchAll();

// Fetch resource types for filters
$resource_types_query = "SELECT DISTINCT resource_type FROM resources WHERE resource_type IS NOT NULL ORDER BY resource_type";
$resource_types = $pdo->query($resource_types_query)->fetchAll();

// Fetch categories for filters
$categories_query = "SELECT DISTINCT category FROM resources WHERE category IS NOT NULL ORDER BY category";
$categories = $pdo->query($categories_query)->fetchAll();

// Function to get resource utilization report
function getResourceUtilizationReport($pdo, $start_date, $end_date, $unit_id, $resource_type) {
    $conditions = [];
    $params = [];
    
    // Build the main query
    $query = "
        SELECT 
            r.id,
            r.resource_name,
            r.resource_type,
            r.category,
            r.quantity,
            r.available_quantity,
            r.condition_status,
            r.location,
            r.unit_id,
            u.unit_name,
            (SELECT COUNT(DISTINCT di.id) 
             FROM dispatch_incidents di 
             WHERE di.unit_id = r.unit_id 
             AND di.dispatched_at BETWEEN ? AND ?) as total_dispatches,
            (SELECT COUNT(DISTINCT mr.id) 
             FROM maintenance_requests mr 
             WHERE mr.resource_id = r.id 
             AND mr.requested_date BETWEEN ? AND ?) as maintenance_count,
            (SELECT COUNT(DISTINCT vs.id) 
             FROM vehicle_status vs 
             WHERE r.external_id = vs.vehicle_id 
             AND vs.status = 'dispatched' 
             AND vs.last_updated BETWEEN ? AND ?) as vehicle_dispatches,
            (SELECT COUNT(DISTINCT di.id) 
             FROM dispatch_incidents di 
             WHERE di.unit_id = r.unit_id 
             AND di.status IN ('completed', 'arrived')
             AND di.dispatched_at BETWEEN ? AND ?) as completed_dispatches,
            (SELECT COUNT(DISTINCT di.id) 
             FROM dispatch_incidents di 
             WHERE di.unit_id = r.unit_id 
             AND di.status = 'cancelled'
             AND di.dispatched_at BETWEEN ? AND ?) as cancelled_dispatches
        FROM resources r
        LEFT JOIN units u ON r.unit_id = u.id
        WHERE 1=1
    ";
    
    // Add date parameters (8 times for all subqueries)
    $date_params = [
        $start_date . ' 00:00:00', $end_date . ' 23:59:59', // total_dispatches
        $start_date . ' 00:00:00', $end_date . ' 23:59:59', // maintenance_count
        $start_date . ' 00:00:00', $end_date . ' 23:59:59', // vehicle_dispatches
        $start_date . ' 00:00:00', $end_date . ' 23:59:59', // completed_dispatches
        $start_date . ' 00:00:00', $end_date . ' 23:59:59'  // cancelled_dispatches
    ];
    
    $params = array_merge($date_params);
    
    // Add resource filters
    if ($unit_id !== 'all') {
        $conditions[] = "r.unit_id = ?";
        $params[] = $unit_id;
    }
    
    if ($resource_type !== 'all') {
        $conditions[] = "r.resource_type = ?";
        $params[] = $resource_type;
    }
    
    // Add WHERE clause if conditions exist
    if (!empty($conditions)) {
        $query .= " AND " . implode(" AND ", $conditions);
    }
    
    $query .= " GROUP BY r.id, r.resource_name, r.resource_type, r.category, r.quantity, r.available_quantity, 
                r.condition_status, r.location, r.unit_id, u.unit_name
                ORDER BY r.resource_type, r.category, r.resource_name";
    
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $resources = $stmt->fetchAll();
    
    // Calculate statistics
    $stats = [
        'total_resources' => 0,
        'total_quantity' => 0,
        'serviceable_count' => 0,
        'maintenance_count' => 0,
        'condemned_count' => 0,
        'utilization_rate' => 0,
        'by_type' => [],
        'by_category' => []
    ];
    
    foreach ($resources as $resource) {
        $stats['total_resources']++;
        $stats['total_quantity'] += $resource['quantity'];
        
        switch ($resource['condition_status']) {
            case 'Serviceable':
                $stats['serviceable_count']++;
                break;
            case 'Under Maintenance':
                $stats['maintenance_count']++;
                break;
            case 'Condemned':
                $stats['condemned_count']++;
                break;
        }
        
        // Group by type
        $type = $resource['resource_type'] ?: 'Unknown';
        if (!isset($stats['by_type'][$type])) {
            $stats['by_type'][$type] = 0;
        }
        $stats['by_type'][$type]++;
        
        // Group by category
        $category = $resource['category'] ?: 'Other';
        if (!isset($stats['by_category'][$category])) {
            $stats['by_category'][$category] = 0;
        }
        $stats['by_category'][$category]++;
        
        // Calculate utilization rate for vehicles
        if ($resource['resource_type'] === 'Vehicle' && $resource['total_dispatches'] > 0) {
            $utilization = ($resource['completed_dispatches'] / $resource['total_dispatches']) * 100;
            $resource['utilization_rate'] = round($utilization, 2);
        }
    }
    
    if ($stats['total_resources'] > 0) {
        $stats['serviceable_rate'] = round(($stats['serviceable_count'] / $stats['total_resources']) * 100, 2);
    }
    
    return [
        'resources' => $resources,
        'stats' => $stats,
        'params' => ['start_date' => $start_date, 'end_date' => $end_date, 'unit_id' => $unit_id, 'resource_type' => $resource_type]
    ];
}

// Function to get damage/loss report
function getDamageLossReport($pdo, $start_date, $end_date, $category) {
    $conditions = [];
    $params = [];
    
    $query = "
        SELECT 
            r.id,
            r.resource_name,
            r.resource_type,
            r.category,
            r.condition_status,
            r.quantity,
            r.available_quantity,
            r.location,
            r.last_inspection,
            r.next_inspection,
            (SELECT COUNT(mr.id) 
             FROM maintenance_requests mr 
             WHERE mr.resource_id = r.id 
             AND mr.requested_date BETWEEN ? AND ?) as maintenance_count,
            (SELECT COUNT(mr.id) 
             FROM maintenance_requests mr 
             WHERE mr.resource_id = r.id 
             AND mr.status = 'completed'
             AND mr.requested_date BETWEEN ? AND ?) as completed_maintenance,
            (SELECT COUNT(mr.id) 
             FROM maintenance_requests mr 
             WHERE mr.resource_id = r.id 
             AND mr.request_type = 'repair'
             AND mr.requested_date BETWEEN ? AND ?) as repair_count,
            (SELECT COUNT(mr.id) 
             FROM maintenance_requests mr 
             WHERE mr.resource_id = r.id 
             AND mr.priority = 'critical'
             AND mr.requested_date BETWEEN ? AND ?) as critical_issues,
            (SELECT MAX(mr.requested_date) 
             FROM maintenance_requests mr 
             WHERE mr.resource_id = r.id 
             AND mr.requested_date BETWEEN ? AND ?) as last_maintenance_date
        FROM resources r
        WHERE 1=1
    ";
    
    // Add date parameters (6 times for all subqueries)
    $date_params = [
        $start_date . ' 00:00:00', $end_date . ' 23:59:59', // maintenance_count
        $start_date . ' 00:00:00', $end_date . ' 23:59:59', // completed_maintenance
        $start_date . ' 00:00:00', $end_date . ' 23:59:59', // repair_count
        $start_date . ' 00:00:00', $end_date . ' 23:59:59', // critical_issues
        $start_date . ' 00:00:00', $end_date . ' 23:59:59'  // last_maintenance_date
    ];
    
    $params = array_merge($date_params);
    
    if ($category !== 'all') {
        $conditions[] = "r.category = ?";
        $params[] = $category;
    }
    
    if (!empty($conditions)) {
        $query .= " AND " . implode(" AND ", $conditions);
    }
    
    $query .= " HAVING maintenance_count > 0 OR r.condition_status IN ('Under Maintenance', 'Condemned')
                ORDER BY r.condition_status DESC, maintenance_count DESC";
    
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $items = $stmt->fetchAll();
    
    // Calculate statistics
    $stats = [
        'total_items' => 0,
        'under_maintenance' => 0,
        'condemned' => 0,
        'total_repairs' => 0,
        'critical_issues' => 0,
        'total_maintenance_cost' => 0,
        'by_category' => [],
        'by_status' => []
    ];
    
    foreach ($items as $item) {
        $stats['total_items']++;
        
        switch ($item['condition_status']) {
            case 'Under Maintenance':
                $stats['under_maintenance']++;
                break;
            case 'Condemned':
                $stats['condemned']++;
                break;
        }
        
        $stats['total_repairs'] += $item['repair_count'];
        $stats['critical_issues'] += $item['critical_issues'];
        
        // Group by category
        $cat = $item['category'] ?: 'Other';
        if (!isset($stats['by_category'][$cat])) {
            $stats['by_category'][$cat] = 0;
        }
        $stats['by_category'][$cat]++;
        
        // Group by status
        $status = $item['condition_status'];
        if (!isset($stats['by_status'][$status])) {
            $stats['by_status'][$status] = 0;
        }
        $stats['by_status'][$status]++;
    }
    
    return [
        'items' => $items,
        'stats' => $stats,
        'params' => ['start_date' => $start_date, 'end_date' => $end_date, 'category' => $category]
    ];
}

// Function to get cost/lifecycle report
function getCostLifecycleReport($pdo, $start_date, $end_date) {
    $query = "
        SELECT 
            r.id,
            r.resource_name,
            r.resource_type,
            r.category,
            r.purchase_date,
            r.purchase_price,
            r.condition_status,
            r.quantity,
            DATEDIFF(CURDATE(), r.purchase_date) as age_days,
            YEAR(CURDATE()) - YEAR(r.purchase_date) as age_years,
            r.last_inspection,
            r.next_inspection,
            (SELECT COUNT(DISTINCT mr.id) 
             FROM maintenance_requests mr 
             WHERE mr.resource_id = r.id 
             AND mr.requested_date BETWEEN ? AND ?) as total_maintenance,
            (SELECT COUNT(DISTINCT mr.id) 
             FROM maintenance_requests mr 
             WHERE mr.resource_id = r.id 
             AND mr.status = 'completed'
             AND mr.requested_date BETWEEN ? AND ?) as completed_maintenance,
            (SELECT COUNT(DISTINCT mr.id) 
             FROM maintenance_requests mr 
             WHERE mr.resource_id = r.id 
             AND mr.request_type = 'repair'
             AND mr.requested_date BETWEEN ? AND ?) as repair_count,
            (SELECT COALESCE(SUM(mr.estimated_cost), 0) 
             FROM maintenance_requests mr 
             WHERE mr.resource_id = r.id 
             AND mr.requested_date BETWEEN ? AND ?) as total_maintenance_cost
        FROM resources r
        WHERE r.purchase_date IS NOT NULL
        ORDER BY r.purchase_date DESC, total_maintenance_cost DESC
    ";
    
    $params = [
        $start_date . ' 00:00:00', $end_date . ' 23:59:59', // total_maintenance
        $start_date . ' 00:00:00', $end_date . ' 23:59:59', // completed_maintenance
        $start_date . ' 00:00:00', $end_date . ' 23:59:59', // repair_count
        $start_date . ' 00:00:00', $end_date . ' 23:59:59'  // total_maintenance_cost
    ];
    
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $items = $stmt->fetchAll();
    
    // Calculate statistics
    $stats = [
        'total_items' => 0,
        'total_purchase_value' => 0,
        'total_maintenance_cost' => 0,
        'average_age_years' => 0,
        'items_needing_replacement' => 0,
        'by_age_group' => [
            '0-1 years' => 0,
            '1-3 years' => 0,
            '3-5 years' => 0,
            '5+ years' => 0
        ],
        'by_cost_group' => [
            'Low (< ₱10,000)' => 0,
            'Medium (₱10,000-₱50,000)' => 0,
            'High (> ₱50,000)' => 0
        ]
    ];
    
    $total_age_years = 0;
    
    foreach ($items as $item) {
        $stats['total_items']++;
        $stats['total_purchase_value'] += ($item['purchase_price'] ?: 0) * ($item['quantity'] ?: 1);
        $stats['total_maintenance_cost'] += $item['total_maintenance_cost'];
        
        $age_years = $item['age_years'] ?: 0;
        $total_age_years += $age_years;
        
        // Categorize by age
        if ($age_years < 1) {
            $stats['by_age_group']['0-1 years']++;
        } elseif ($age_years < 3) {
            $stats['by_age_group']['1-3 years']++;
        } elseif ($age_years < 5) {
            $stats['by_age_group']['3-5 years']++;
        } else {
            $stats['by_age_group']['5+ years']++;
            
            // Flag items that might need replacement (5+ years old and in poor condition)
            if (in_array($item['condition_status'], ['Under Maintenance', 'Condemned'])) {
                $stats['items_needing_replacement']++;
            }
        }
        
        // Categorize by cost
        $price = $item['purchase_price'] ?: 0;
        if ($price < 10000) {
            $stats['by_cost_group']['Low (< ₱10,000)']++;
        } elseif ($price <= 50000) {
            $stats['by_cost_group']['Medium (₱10,000-₱50,000)']++;
        } else {
            $stats['by_cost_group']['High (> ₱50,000)']++;
        }
    }
    
    if ($stats['total_items'] > 0) {
        $stats['average_age_years'] = round($total_age_years / $stats['total_items'], 1);
        $stats['average_maintenance_cost'] = round($stats['total_maintenance_cost'] / $stats['total_items'], 2);
    }
    
    // Calculate ROI (Return on Investment) approximation
    $stats['total_investment'] = $stats['total_purchase_value'] + $stats['total_maintenance_cost'];
    $stats['depreciation_rate'] = $stats['total_items'] > 0 ? 
        round(($stats['items_needing_replacement'] / $stats['total_items']) * 100, 2) : 0;
    
    return [
        'items' => $items,
        'stats' => $stats,
        'params' => ['start_date' => $start_date, 'end_date' => $end_date]
    ];
}

// Fetch data based on report type
switch ($report_type) {
    case 'resource_utilization':
        $report_data = getResourceUtilizationReport($pdo, $start_date, $end_date, $unit_id, $resource_type);
        $report_title = "Resource Utilization Report";
        break;
        
    case 'damage_loss':
        $report_data = getDamageLossReport($pdo, $start_date, $end_date, $category);
        $report_title = "Damage & Loss Report";
        break;
        
    case 'cost_lifecycle':
        $report_data = getCostLifecycleReport($pdo, $start_date, $end_date);
        $report_title = "Cost & Lifecycle Report";
        break;
        
    default:
        $report_data = getResourceUtilizationReport($pdo, $start_date, $end_date, $unit_id, $resource_type);
        $report_title = "Resource Utilization Report";
}

// Handle report export
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="' . $report_title . '_' . date('Y-m-d') . '.csv"');
    
    $output = fopen('php://output', 'w');
    
    switch ($report_type) {
        case 'resource_utilization':
            $headers = ['Resource Name', 'Type', 'Category', 'Quantity', 'Available', 'Condition', 'Unit', 'Dispatches', 'Completed', 'Utilization Rate'];
            fputcsv($output, $headers);
            
            foreach ($report_data['resources'] as $resource) {
                $row = [
                    $resource['resource_name'],
                    $resource['resource_type'],
                    $resource['category'],
                    $resource['quantity'],
                    $resource['available_quantity'],
                    $resource['condition_status'],
                    $resource['unit_name'] ?: 'N/A',
                    $resource['total_dispatches'],
                    $resource['completed_dispatches'],
                    isset($resource['utilization_rate']) ? $resource['utilization_rate'] . '%' : 'N/A'
                ];
                fputcsv($output, $row);
            }
            break;
            
        case 'damage_loss':
            $headers = ['Resource Name', 'Type', 'Category', 'Condition', 'Quantity', 'Maintenance Count', 'Repairs', 'Critical Issues', 'Last Maintenance'];
            fputcsv($output, $headers);
            
            foreach ($report_data['items'] as $item) {
                $row = [
                    $item['resource_name'],
                    $item['resource_type'],
                    $item['category'],
                    $item['condition_status'],
                    $item['quantity'],
                    $item['maintenance_count'],
                    $item['repair_count'],
                    $item['critical_issues'],
                    $item['last_maintenance_date'] ? date('Y-m-d', strtotime($item['last_maintenance_date'])) : 'Never'
                ];
                fputcsv($output, $row);
            }
            break;
            
        case 'cost_lifecycle':
            $headers = ['Resource Name', 'Type', 'Category', 'Purchase Date', 'Age (Years)', 'Purchase Price', 'Maintenance Cost', 'Total Cost', 'Condition'];
            fputcsv($output, $headers);
            
            foreach ($report_data['items'] as $item) {
                $total_cost = ($item['purchase_price'] ?: 0) + $item['total_maintenance_cost'];
                $row = [
                    $item['resource_name'],
                    $item['resource_type'],
                    $item['category'],
                    $item['purchase_date'],
                    $item['age_years'],
                    '₱' . number_format($item['purchase_price'] ?: 0, 2),
                    '₱' . number_format($item['total_maintenance_cost'], 2),
                    '₱' . number_format($total_cost, 2),
                    $item['condition_status']
                ];
                fputcsv($output, $row);
            }
            break;
    }
    
    fclose($output);
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reports & Analytics - Fire & Rescue Services</title>
    <link href='https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css' rel='stylesheet'>
    <link rel="icon" type="image/png" sizes="32x32" href="../../img/frsm-logo.png">
    <link rel="stylesheet" href="../../css/dashboard.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
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
            --success: #10b981;
            --warning: #f59e0b;
            --danger: #dc2626;
            --info: #3b82f6;
            --purple: #8b5cf6;
            --teal: #14b8a6;
            --orange: #f97316;
        }
        
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
            background-color: var(--background-color);
            color: var(--text-color);
            margin: 0;
            padding: 0;
        }
        
        .dashboard-content {
            padding: 0;
        }
        
        .dashboard-header {
            color: white;
            padding: 60px 40px 40px;
            border-radius: 0 0 30px 30px;
            margin-bottom: 40px;
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
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }
        
        .dashboard-subtitle {
            font-size: 16px;
            color: var(--text-color);
            opacity: 0.9;
        }
        
        .reports-container {
            padding: 0 40px 40px;
        }
        
        .report-controls {
            display: flex;
            gap: 16px;
            margin-bottom: 24px;
            flex-wrap: wrap;
            background: var(--card-bg);
            padding: 20px;
            border-radius: 16px;
            border: 1px solid var(--border-color);
        }
        
        .control-group {
            display: flex;
            flex-direction: column;
            gap: 8px;
            flex: 1;
            min-width: 200px;
        }
        
        .control-label {
            font-size: 14px;
            font-weight: 600;
        }
        
        .control-select, .control-input, .control-button {
            padding: 10px 16px;
            border-radius: 10px;
            border: 1px solid var(--border-color);
            background: var(--card-bg);
            color: var(--text-color);
            font-size: 14px;
        }
        
        .control-button {
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            color: white;
            border: none;
            cursor: pointer;
            font-weight: 600;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            transition: all 0.3s ease;
        }
        
        .control-button:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(220, 38, 38, 0.3);
        }
        
        .export-button {
            background: linear-gradient(135deg, var(--success), #0d9488);
        }
        
        .export-button:hover {
            box-shadow: 0 5px 15px rgba(16, 185, 129, 0.3);
        }
        
        .report-tabs {
            display: flex;
            gap: 8px;
            margin-bottom: 24px;
            border-bottom: 1px solid var(--border-color);
            padding-bottom: 16px;
        }
        
        .report-tab {
            padding: 12px 24px;
            border-radius: 10px;
            background: none;
            border: none;
            color: var(--text-light);
            cursor: pointer;
            font-weight: 600;
            transition: all 0.3s ease;
        }
        
        .report-tab:hover {
            background: var(--background-color);
        }
        
        .report-tab.active {
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            color: white;
        }
        
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 16px;
            margin-bottom: 32px;
        }
        
        .stat-card {
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: 16px;
            padding: 24px;
            transition: all 0.3s ease;
        }
        
        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.15);
        }
        
        .stat-value {
            font-size: 32px;
            font-weight: 800;
            margin-bottom: 8px;
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }
        
        .stat-label {
            font-size: 14px;
            color: var(--text-light);
            margin-bottom: 12px;
        }
        
        .stat-trend {
            font-size: 12px;
            padding: 4px 8px;
            border-radius: 4px;
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }
        
        .trend-up {
            background: rgba(16, 185, 129, 0.1);
            color: var(--success);
        }
        
        .trend-down {
            background: rgba(220, 38, 38, 0.1);
            color: var(--danger);
        }
        
        .trend-neutral {
            background: rgba(107, 114, 128, 0.1);
            color: var(--text-light);
        }
        
        .charts-container {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(400px, 1fr));
            gap: 24px;
            margin-bottom: 32px;
        }
        
        .chart-card {
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: 16px;
            padding: 24px;
        }
        
        .chart-title {
            font-size: 18px;
            font-weight: 700;
            margin-bottom: 20px;
            color: var(--primary-color);
        }
        
        .chart-container {
            height: 300px;
            position: relative;
        }
        
        .data-table {
            width: 100%;
            border-collapse: collapse;
            background: var(--card-bg);
            border-radius: 16px;
            overflow: hidden;
            border: 1px solid var(--border-color);
            margin-top: 24px;
        }
        
        .data-table th {
            padding: 16px;
            text-align: left;
            background: var(--background-color);
            font-weight: 600;
            border-bottom: 1px solid var(--border-color);
        }
        
        .data-table td {
            padding: 16px;
            border-bottom: 1px solid var(--border-color);
        }
        
        .data-table tr:last-child td {
            border-bottom: none;
        }
        
        .data-table tr:hover {
            background: var(--background-color);
        }
        
        .status-badge {
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            text-transform: uppercase;
        }
        
        .status-serviceable {
            background: rgba(16, 185, 129, 0.1);
            color: var(--success);
        }
        
        .status-maintenance {
            background: rgba(245, 158, 11, 0.1);
            color: var(--warning);
        }
        
        .status-condemned {
            background: rgba(220, 38, 38, 0.1);
            color: var(--danger);
        }
        
        .priority-badge {
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 11px;
            font-weight: 600;
        }
        
        .priority-low {
            background: rgba(107, 114, 128, 0.1);
            color: var(--text-light);
        }
        
        .priority-medium {
            background: rgba(59, 130, 246, 0.1);
            color: var(--info);
        }
        
        .priority-high {
            background: rgba(245, 158, 11, 0.1);
            color: var(--warning);
        }
        
        .priority-critical {
            background: rgba(220, 38, 38, 0.1);
            color: var(--danger);
        }
        
        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: var(--text-light);
        }
        
        .empty-state-icon {
            font-size: 64px;
            margin-bottom: 16px;
            opacity: 0.5;
        }
        
        .loading-overlay {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.7);
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            z-index: 2000;
            opacity: 0;
            visibility: hidden;
            transition: all 0.3s ease;
        }
        
        .loading-overlay.active {
            opacity: 1;
            visibility: visible;
        }
        
        .loading-spinner {
            width: 50px;
            height: 50px;
            border: 4px solid var(--border-color);
            border-top-color: var(--primary-color);
            border-radius: 50%;
            animation: spin 1s linear infinite;
            margin-bottom: 20px;
        }
        
        @keyframes spin {
            to { transform: rotate(360deg); }
        }
        
        .dashboard-animation {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: var(--background-color);
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            z-index: 3000;
            transition: opacity 0.3s ease;
        }
        
        .animation-logo {
            display: flex;
            align-items: center;
            gap: 16px;
            margin-bottom: 40px;
        }
        
        .animation-logo-icon img {
            width: 60px;
            height: 60px;
        }
        
        .animation-logo-text {
            font-size: 32px;
            font-weight: 700;
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }
        
        .animation-progress {
            width: 300px;
            height: 4px;
            background: var(--border-color);
            border-radius: 2px;
            overflow: hidden;
        }
        
        .animation-progress-fill {
            height: 100%;
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            width: 0%;
            transition: width 1s ease;
        }
        
        .animation-text {
            margin-top: 20px;
            color: var(--text-light);
        }
        
        .info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 20px;
            margin-top: 24px;
        }
        
        .info-card {
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: 12px;
            padding: 20px;
        }
        
        .info-card-title {
            font-size: 16px;
            font-weight: 600;
            margin-bottom: 16px;
            color: var(--primary-color);
        }
        
        .info-item {
            display: flex;
            justify-content: space-between;
            margin-bottom: 12px;
            padding-bottom: 12px;
            border-bottom: 1px solid var(--border-color);
        }
        
        .info-item:last-child {
            border-bottom: none;
            margin-bottom: 0;
            padding-bottom: 0;
        }
        
        .info-label {
            color: var(--text-light);
            font-size: 14px;
        }
        
        .info-value {
            font-weight: 600;
            text-align: right;
        }
        
        .currency {
            font-family: monospace;
            color: var(--success);
        }
        
        .percentage {
            font-family: monospace;
            color: var(--info);
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
        <div class="animation-text" id="animation-text">Loading Reports & Analytics...</div>
    </div>
    
    <!-- Loading Overlay -->
    <div class="loading-overlay" id="loading-overlay">
        <div class="loading-spinner"></div>
        <div class="loading-text" id="loading-text">Generating Report...</div>
    </div>
    
    <div class="container">
        <!-- Sidebar -->
        <div class="sidebar">
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
                        <a href="../user/manage_users.php" class="submenu-item">Manage Users</a>
                        <a href="../user/role_control.php" class="submenu-item">Role Control</a>
                        <a href="../user/monitor_activity.php" class="submenu-item">Monitor Activity</a>
                        <a href="../user/reset_passwords.php" class="submenu-item">Reset Passwords</a>
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
                        <a href="../vm/review_data.php" class="submenu-item">Review Data</a>
                        <a href="../vm/approve_applications.php" class="submenu-item">Approve Applications</a>
                        <a href="../vm/assign_volunteers.php" class="submenu-item">Assign Volunteers</a>
                        <a href="../vm/view_availability.php" class="submenu-item">View Availability</a>
                        <a href="../vm/remove_volunteers.php" class="submenu-item">Remove Volunteers</a>
                        <a href="../vm/toggle_volunteer_registration.php" class="submenu-item">Toggle Volunteer Registration Access</a>
                    </div>
                    
                    <!-- Resource Inventory Management -->
                    <div class="menu-item active" onclick="toggleSubmenu('resource-management')">
                        <div class="icon-box icon-bg-green">
                            <i class='bx bxs-cube icon-green'></i>
                        </div>
                        <span class="font-medium">Resource Management</span>
                        <svg class="dropdown-arrow menu-icon rotated" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                        </svg>
                    </div>
                    <div id="resource-management" class="submenu active">
                           <a href="view_equipment.php" class="submenu-item">View Equipment</a>
                        <a href="approve_maintenance.php" class="submenu-item">Approve Maintenance</a>
                         <a href="review_deployment.php" class="submenu-item">Review Deployment</a>
                        <a href="reports_analytics.php" class="submenu-item active">Reports & Analytics</a>
                       
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
                       <a href="../sm/view_shifts.php" class="submenu-item">View Shifts</a>
                        <a href="../sm/create_schedule.php" class="submenu-item">Create Schedule</a>
                        <a href="../sm/confirm_availability.php" class="submenu-item">Confirm Availability</a>
                        <a href="../sm/request_change.php" class="submenu-item">Request Change</a>
                        <a href="../sm/monitor_attendance.php" class="submenu-item">Monitor Attendance</a>
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
                        <a href="../tm/approve_completions.php" class="submenu-item">Approve Completions</a>
                        <a href="../tm/view_training_records.php" class="submenu-item">View Records</a>
                        <a href="../tm/assign_training.php" class="submenu-item">Assign Training</a>
                        <a href="../tm/track_expiry.php" class="submenu-item">Track Expiry</a>
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
                            <input type="text" placeholder="Search reports..." class="search-input" id="search-input">
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
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Dashboard Content -->
            <div class="dashboard-content">
                <div class="dashboard-header">
                    <div>
                        <h1 class="dashboard-title">Reports & Analytics</h1>
                        <p class="dashboard-subtitle">Comprehensive resource management reports and analytics</p>
                    </div>
                    <div class="dashboard-actions">
                        <button class="primary-button" onclick="refreshPage()">
                            <i class='bx bx-refresh'></i>
                            Refresh
                        </button>
                    </div>
                </div>
                
                <!-- Reports Section -->
                <div class="reports-container">
                    <!-- Report Controls -->
                    <form method="GET" action="reports_analytics.php" class="report-controls" onsubmit="showLoading()">
                        <input type="hidden" name="report_type" id="report-type-input" value="<?php echo $report_type; ?>">
                        
                        <div class="control-group">
                            <label class="control-label">Report Type</label>
                            <select class="control-select" id="report-type-select" onchange="changeReportType(this.value)">
                                <option value="resource_utilization" <?php echo $report_type === 'resource_utilization' ? 'selected' : ''; ?>>Resource Utilization</option>
                                <option value="damage_loss" <?php echo $report_type === 'damage_loss' ? 'selected' : ''; ?>>Damage & Loss</option>
                                <option value="cost_lifecycle" <?php echo $report_type === 'cost_lifecycle' ? 'selected' : ''; ?>>Cost & Lifecycle</option>
                            </select>
                        </div>
                        
                        <div class="control-group">
                            <label class="control-label">Start Date</label>
                            <input type="date" class="control-input" name="start_date" value="<?php echo htmlspecialchars($start_date); ?>">
                        </div>
                        
                        <div class="control-group">
                            <label class="control-label">End Date</label>
                            <input type="date" class="control-input" name="end_date" value="<?php echo htmlspecialchars($end_date); ?>">
                        </div>
                        
                        <?php if ($report_type === 'resource_utilization'): ?>
                            <div class="control-group">
                                <label class="control-label">Unit</label>
                                <select class="control-select" name="unit_id">
                                    <option value="all">All Units</option>
                                    <?php foreach ($units as $unit): ?>
                                        <option value="<?php echo $unit['id']; ?>" <?php echo $unit_id == $unit['id'] ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($unit['unit_name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div class="control-group">
                                <label class="control-label">Resource Type</label>
                                <select class="control-select" name="resource_type">
                                    <option value="all">All Types</option>
                                    <?php foreach ($resource_types as $type): ?>
                                        <option value="<?php echo htmlspecialchars($type['resource_type']); ?>" <?php echo $resource_type == $type['resource_type'] ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($type['resource_type']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        <?php elseif ($report_type === 'damage_loss'): ?>
                            <div class="control-group">
                                <label class="control-label">Category</label>
                                <select class="control-select" name="category">
                                    <option value="all">All Categories</option>
                                    <?php foreach ($categories as $cat): ?>
                                        <option value="<?php echo htmlspecialchars($cat['category']); ?>" <?php echo $category == $cat['category'] ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($cat['category']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        <?php endif; ?>
                        
                        <div class="control-group" style="min-width: 120px;">
                            <label class="control-label">&nbsp;</label>
                            <button type="submit" class="control-button">
                                <i class='bx bx-filter-alt'></i>
                                Apply Filters
                            </button>
                        </div>
                        
                        <div class="control-group" style="min-width: 120px;">
                            <label class="control-label">&nbsp;</label>
                            <a href="reports_analytics.php?export=csv&<?php echo http_build_query($_GET); ?>" class="control-button export-button" onclick="showLoading('Exporting...')">
                                <i class='bx bx-download'></i>
                                Export CSV
                            </a>
                        </div>
                    </form>
                    
                    <!-- Report Tabs -->
                    <div class="report-tabs">
                        <button class="report-tab active" onclick="showReportSection('overview')">Overview</button>
                        <button class="report-tab" onclick="showReportSection('details')">Detailed Data</button>
                        <button class="report-tab" onclick="showReportSection('charts')">Charts & Graphs</button>
                    </div>
                    
                    <!-- Report Period -->
                    <div style="margin-bottom: 24px; padding: 16px; background: var(--card-bg); border-radius: 12px; border: 1px solid var(--border-color);">
                        <div style="font-size: 14px; color: var(--text-light);">Report Period:</div>
                        <div style="font-size: 16px; font-weight: 600;">
                            <?php echo date('F d, Y', strtotime($start_date)); ?> to <?php echo date('F d, Y', strtotime($end_date)); ?>
                        </div>
                    </div>
                    
                    <!-- Overview Section -->
                    <div id="overview-section" class="report-section">
                        <h2 style="margin-bottom: 24px; color: var(--primary-color);"><?php echo $report_title; ?></h2>
                        
                        <!-- Stats Grid -->
                        <div class="stats-grid">
                            <?php if ($report_type === 'resource_utilization'): ?>
                                <div class="stat-card">
                                    <div class="stat-value"><?php echo $report_data['stats']['total_resources']; ?></div>
                                    <div class="stat-label">Total Resources</div>
                                    <div class="stat-trend trend-up">
                                        <i class='bx bx-up-arrow-alt'></i>
                                        <?php echo $report_data['stats']['total_quantity']; ?> total units
                                    </div>
                                </div>
                                
                                <div class="stat-card">
                                    <div class="stat-value"><?php echo $report_data['stats']['serviceable_rate']; ?>%</div>
                                    <div class="stat-label">Serviceable Rate</div>
                                    <div class="stat-trend <?php echo $report_data['stats']['serviceable_rate'] >= 80 ? 'trend-up' : ($report_data['stats']['serviceable_rate'] >= 60 ? 'trend-neutral' : 'trend-down'); ?>">
                                        <i class='bx <?php echo $report_data['stats']['serviceable_rate'] >= 80 ? 'bx-up-arrow-alt' : ($report_data['stats']['serviceable_rate'] >= 60 ? 'bx-minus' : 'bx-down-arrow-alt'); ?>'></i>
                                        <?php echo $report_data['stats']['serviceable_count']; ?> serviceable items
                                    </div>
                                </div>
                                
                                <div class="stat-card">
                                    <div class="stat-value"><?php echo $report_data['stats']['maintenance_count']; ?></div>
                                    <div class="stat-label">Under Maintenance</div>
                                    <div class="stat-trend <?php echo $report_data['stats']['maintenance_count'] <= 5 ? 'trend-down' : 'trend-up'; ?>">
                                        <i class='bx <?php echo $report_data['stats']['maintenance_count'] <= 5 ? 'bx-down-arrow-alt' : 'bx-up-arrow-alt'; ?>'></i>
                                        <?php echo $report_data['stats']['condemned_count']; ?> condemned items
                                    </div>
                                </div>
                                
                                <div class="stat-card">
                                    <div class="stat-value"><?php echo isset($report_data['stats']['utilization_rate']) ? $report_data['stats']['utilization_rate'] : 'N/A'; ?></div>
                                    <div class="stat-label">Avg. Utilization</div>
                                    <div class="stat-trend trend-neutral">
                                        <i class='bx bx-bar-chart-alt'></i>
                                        Based on <?php echo count($report_data['resources']); ?> resources
                                    </div>
                                </div>
                                
                            <?php elseif ($report_type === 'damage_loss'): ?>
                                <div class="stat-card">
                                    <div class="stat-value"><?php echo $report_data['stats']['total_items']; ?></div>
                                    <div class="stat-label">Items with Issues</div>
                                    <div class="stat-trend <?php echo $report_data['stats']['total_items'] <= 10 ? 'trend-down' : 'trend-up'; ?>">
                                        <i class='bx <?php echo $report_data['stats']['total_items'] <= 10 ? 'bx-down-arrow-alt' : 'bx-up-arrow-alt'; ?>'></i>
                                        Tracked in period
                                    </div>
                                </div>
                                
                                <div class="stat-card">
                                    <div class="stat-value"><?php echo $report_data['stats']['under_maintenance']; ?></div>
                                    <div class="stat-label">Under Maintenance</div>
                                    <div class="stat-trend trend-up">
                                        <i class='bx bx-wrench'></i>
                                        <?php echo $report_data['stats']['total_repairs']; ?> total repairs
                                    </div>
                                </div>
                                
                                <div class="stat-card">
                                    <div class="stat-value"><?php echo $report_data['stats']['condemned']; ?></div>
                                    <div class="stat-label">Condemned Items</div>
                                    <div class="stat-trend <?php echo $report_data['stats']['condemned'] <= 2 ? 'trend-down' : 'trend-up'; ?>">
                                        <i class='bx <?php echo $report_data['stats']['condemned'] <= 2 ? 'bx-down-arrow-alt' : 'bx-up-arrow-alt'; ?>'></i>
                                        May need replacement
                                    </div>
                                </div>
                                
                                <div class="stat-card">
                                    <div class="stat-value"><?php echo $report_data['stats']['critical_issues']; ?></div>
                                    <div class="stat-label">Critical Issues</div>
                                    <div class="stat-trend <?php echo $report_data['stats']['critical_issues'] == 0 ? 'trend-down' : 'trend-up'; ?>">
                                        <i class='bx <?php echo $report_data['stats']['critical_issues'] == 0 ? 'bx-check-circle' : 'bx-error-circle'; ?>'></i>
                                        Require immediate attention
                                    </div>
                                </div>
                                
                            <?php elseif ($report_type === 'cost_lifecycle'): ?>
                                <div class="stat-card">
                                    <div class="stat-value" style="font-size: 28px;">₱<?php echo number_format($report_data['stats']['total_purchase_value'], 2); ?></div>
                                    <div class="stat-label">Total Asset Value</div>
                                    <div class="stat-trend trend-up">
                                        <i class='bx bx-dollar-circle'></i>
                                        <?php echo $report_data['stats']['total_items']; ?> items
                                    </div>
                                </div>
                                
                                <div class="stat-card">
                                    <div class="stat-value" style="font-size: 28px;">₱<?php echo number_format($report_data['stats']['total_maintenance_cost'], 2); ?></div>
                                    <div class="stat-label">Maintenance Cost</div>
                                    <div class="stat-trend <?php echo $report_data['stats']['total_maintenance_cost'] < 10000 ? 'trend-down' : 'trend-up'; ?>">
                                        <i class='bx <?php echo $report_data['stats']['total_maintenance_cost'] < 10000 ? 'bx-down-arrow-alt' : 'bx-up-arrow-alt'; ?>'></i>
                                        <?php echo $report_data['stats']['average_maintenance_cost'] ?? 0; ?> avg/item
                                    </div>
                                </div>
                                
                                <div class="stat-card">
                                    <div class="stat-value"><?php echo $report_data['stats']['average_age_years']; ?> yrs</div>
                                    <div class="stat-label">Average Age</div>
                                    <div class="stat-trend <?php echo $report_data['stats']['average_age_years'] < 3 ? 'trend-down' : ($report_data['stats']['average_age_years'] < 5 ? 'trend-neutral' : 'trend-up'); ?>">
                                        <i class='bx <?php echo $report_data['stats']['average_age_years'] < 3 ? 'bx-down-arrow-alt' : ($report_data['stats']['average_age_years'] < 5 ? 'bx-minus' : 'bx-up-arrow-alt'); ?>'></i>
                                        Asset lifecycle
                                    </div>
                                </div>
                                
                                <div class="stat-card">
                                    <div class="stat-value"><?php echo $report_data['stats']['depreciation_rate']; ?>%</div>
                                    <div class="stat-label">Depreciation Rate</div>
                                    <div class="stat-trend <?php echo $report_data['stats']['depreciation_rate'] < 10 ? 'trend-down' : ($report_data['stats']['depreciation_rate'] < 20 ? 'trend-neutral' : 'trend-up'); ?>">
                                        <i class='bx <?php echo $report_data['stats']['depreciation_rate'] < 10 ? 'bx-down-arrow-alt' : ($report_data['stats']['depreciation_rate'] < 20 ? 'bx-minus' : 'bx-up-arrow-alt'); ?>'></i>
                                        <?php echo $report_data['stats']['items_needing_replacement']; ?> items need replacement
                                    </div>
                                </div>
                            <?php endif; ?>
                        </div>
                        
                        <!-- Additional Info -->
                        <div class="info-grid">
                            <?php if ($report_type === 'resource_utilization'): ?>
                                <div class="info-card">
                                    <div class="info-card-title">Resource Distribution by Type</div>
                                    <?php foreach ($report_data['stats']['by_type'] as $type => $count): ?>
                                        <div class="info-item">
                                            <span class="info-label"><?php echo htmlspecialchars($type); ?></span>
                                            <span class="info-value"><?php echo $count; ?> items</span>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                                
                                <div class="info-card">
                                    <div class="info-card-title">Resource Distribution by Category</div>
                                    <?php foreach ($report_data['stats']['by_category'] as $category => $count): ?>
                                        <div class="info-item">
                                            <span class="info-label"><?php echo htmlspecialchars($category); ?></span>
                                            <span class="info-value"><?php echo $count; ?> items</span>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                                
                                <div class="info-card">
                                    <div class="info-card-title">Condition Summary</div>
                                    <div class="info-item">
                                        <span class="info-label">Serviceable</span>
                                        <span class="info-value"><?php echo $report_data['stats']['serviceable_count']; ?> items</span>
                                    </div>
                                    <div class="info-item">
                                        <span class="info-label">Under Maintenance</span>
                                        <span class="info-value"><?php echo $report_data['stats']['maintenance_count']; ?> items</span>
                                    </div>
                                    <div class="info-item">
                                        <span class="info-label">Condemned</span>
                                        <span class="info-value"><?php echo $report_data['stats']['condemned_count']; ?> items</span>
                                    </div>
                                </div>
                                
                            <?php elseif ($report_type === 'damage_loss'): ?>
                                <div class="info-card">
                                    <div class="info-card-title">Issues by Category</div>
                                    <?php foreach ($report_data['stats']['by_category'] as $category => $count): ?>
                                        <div class="info-item">
                                            <span class="info-label"><?php echo htmlspecialchars($category); ?></span>
                                            <span class="info-value"><?php echo $count; ?> items</span>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                                
                                <div class="info-card">
                                    <div class="info-card-title">Condition Status</div>
                                    <?php foreach ($report_data['stats']['by_status'] as $status => $count): ?>
                                        <div class="info-item">
                                            <span class="info-label"><?php echo htmlspecialchars($status); ?></span>
                                            <span class="info-value"><?php echo $count; ?> items</span>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                                
                                <div class="info-card">
                                    <div class="info-card-title">Maintenance Summary</div>
                                    <div class="info-item">
                                        <span class="info-label">Total Repairs</span>
                                        <span class="info-value"><?php echo $report_data['stats']['total_repairs']; ?></span>
                                    </div>
                                    <div class="info-item">
                                        <span class="info-label">Critical Issues</span>
                                        <span class="info-value"><?php echo $report_data['stats']['critical_issues']; ?></span>
                                    </div>
                                    <div class="info-item">
                                        <span class="info-label">Total Items Tracked</span>
                                        <span class="info-value"><?php echo $report_data['stats']['total_items']; ?></span>
                                    </div>
                                </div>
                                
                            <?php elseif ($report_type === 'cost_lifecycle'): ?>
                                <div class="info-card">
                                    <div class="info-card-title">Age Distribution</div>
                                    <?php foreach ($report_data['stats']['by_age_group'] as $age_group => $count): ?>
                                        <div class="info-item">
                                            <span class="info-label"><?php echo htmlspecialchars($age_group); ?></span>
                                            <span class="info-value"><?php echo $count; ?> items</span>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                                
                                <div class="info-card">
                                    <div class="info-card-title">Cost Distribution</div>
                                    <?php foreach ($report_data['stats']['by_cost_group'] as $cost_group => $count): ?>
                                        <div class="info-item">
                                            <span class="info-label"><?php echo htmlspecialchars($cost_group); ?></span>
                                            <span class="info-value"><?php echo $count; ?> items</span>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                                
                                <div class="info-card">
                                    <div class="info-card-title">Financial Summary</div>
                                    <div class="info-item">
                                        <span class="info-label">Total Investment</span>
                                        <span class="info-value currency">₱<?php echo number_format($report_data['stats']['total_investment'], 2); ?></span>
                                    </div>
                                    <div class="info-item">
                                        <span class="info-label">Avg. Maintenance Cost</span>
                                        <span class="info-value currency">₱<?php echo number_format($report_data['stats']['average_maintenance_cost'] ?? 0, 2); ?></span>
                                    </div>
                                    <div class="info-item">
                                        <span class="info-label">Items Needing Replacement</span>
                                        <span class="info-value"><?php echo $report_data['stats']['items_needing_replacement']; ?></span>
                                    </div>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <!-- Details Section -->
                    <div id="details-section" class="report-section" style="display: none;">
                        <h2 style="margin-bottom: 24px; color: var(--primary-color);">Detailed Data</h2>
                        
                        <?php if ($report_type === 'resource_utilization'): ?>
                            <?php if (count($report_data['resources']) > 0): ?>
                                <table class="data-table">
                                    <thead>
                                        <tr>
                                            <th>Resource Name</th>
                                            <th>Type</th>
                                            <th>Category</th>
                                            <th>Quantity</th>
                                            <th>Available</th>
                                            <th>Condition</th>
                                            <th>Unit</th>
                                            <th>Dispatches</th>
                                            <th>Utilization</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($report_data['resources'] as $resource): ?>
                                            <tr>
                                                <td><strong><?php echo htmlspecialchars($resource['resource_name']); ?></strong></td>
                                                <td><?php echo htmlspecialchars($resource['resource_type']); ?></td>
                                                <td><?php echo htmlspecialchars($resource['category']); ?></td>
                                                <td><?php echo $resource['quantity']; ?></td>
                                                <td><?php echo $resource['available_quantity'] ?? $resource['quantity']; ?></td>
                                                <td>
                                                    <span class="status-badge status-<?php echo strtolower(str_replace(' ', '_', $resource['condition_status'])); ?>">
                                                        <?php echo $resource['condition_status']; ?>
                                                    </span>
                                                </td>
                                                <td><?php echo $resource['unit_name'] ? htmlspecialchars($resource['unit_name']) : 'N/A'; ?></td>
                                                <td><?php echo $resource['total_dispatches']; ?></td>
                                                <td>
                                                    <?php if (isset($resource['utilization_rate'])): ?>
                                                        <span class="percentage"><?php echo $resource['utilization_rate']; ?>%</span>
                                                    <?php else: ?>
                                                        N/A
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            <?php else: ?>
                                <div class="empty-state">
                                    <div class="empty-state-icon">
                                        <i class='bx bx-package'></i>
                                    </div>
                                    <h3>No Resources Found</h3>
                                    <p>No resources match your current filter criteria.</p>
                                </div>
                            <?php endif; ?>
                            
                        <?php elseif ($report_type === 'damage_loss'): ?>
                            <?php if (count($report_data['items']) > 0): ?>
                                <table class="data-table">
                                    <thead>
                                        <tr>
                                            <th>Resource Name</th>
                                            <th>Type</th>
                                            <th>Category</th>
                                            <th>Condition</th>
                                            <th>Quantity</th>
                                            <th>Maintenance Count</th>
                                            <th>Repairs</th>
                                            <th>Critical Issues</th>
                                            <th>Last Maintenance</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($report_data['items'] as $item): ?>
                                            <tr>
                                                <td><strong><?php echo htmlspecialchars($item['resource_name']); ?></strong></td>
                                                <td><?php echo htmlspecialchars($item['resource_type']); ?></td>
                                                <td><?php echo htmlspecialchars($item['category']); ?></td>
                                                <td>
                                                    <span class="status-badge status-<?php echo strtolower(str_replace(' ', '_', $item['condition_status'])); ?>">
                                                        <?php echo $item['condition_status']; ?>
                                                    </span>
                                                </td>
                                                <td><?php echo $item['quantity']; ?></td>
                                                <td>
                                                    <span class="<?php echo $item['maintenance_count'] > 3 ? 'priority-high' : ($item['maintenance_count'] > 0 ? 'priority-medium' : 'priority-low'); ?>">
                                                        <?php echo $item['maintenance_count']; ?>
                                                    </span>
                                                </td>
                                                <td><?php echo $item['repair_count']; ?></td>
                                                <td>
                                                    <?php if ($item['critical_issues'] > 0): ?>
                                                        <span class="priority-critical"><?php echo $item['critical_issues']; ?></span>
                                                    <?php else: ?>
                                                        <span class="priority-low">0</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <?php echo $item['last_maintenance_date'] ? date('Y-m-d', strtotime($item['last_maintenance_date'])) : 'Never'; ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            <?php else: ?>
                                <div class="empty-state">
                                    <div class="empty-state-icon">
                                        <i class='bx bx-error-circle'></i>
                                    </div>
                                    <h3>No Damage/Loss Records</h3>
                                    <p>No items with maintenance or condition issues found in the selected period.</p>
                                </div>
                            <?php endif; ?>
                            
                        <?php elseif ($report_type === 'cost_lifecycle'): ?>
                            <?php if (count($report_data['items']) > 0): ?>
                                <table class="data-table">
                                    <thead>
                                        <tr>
                                            <th>Resource Name</th>
                                            <th>Type</th>
                                            <th>Category</th>
                                            <th>Purchase Date</th>
                                            <th>Age</th>
                                            <th>Purchase Price</th>
                                            <th>Maintenance Cost</th>
                                            <th>Total Cost</th>
                                            <th>Condition</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($report_data['items'] as $item): 
                                            $total_cost = ($item['purchase_price'] ?: 0) + $item['total_maintenance_cost'];
                                            $age_class = $item['age_years'] >= 5 ? 'priority-critical' : ($item['age_years'] >= 3 ? 'priority-high' : 'priority-low');
                                        ?>
                                            <tr>
                                                <td><strong><?php echo htmlspecialchars($item['resource_name']); ?></strong></td>
                                                <td><?php echo htmlspecialchars($item['resource_type']); ?></td>
                                                <td><?php echo htmlspecialchars($item['category']); ?></td>
                                                <td><?php echo $item['purchase_date'] ? date('Y-m-d', strtotime($item['purchase_date'])) : 'Unknown'; ?></td>
                                                <td>
                                                    <span class="<?php echo $age_class; ?>">
                                                        <?php echo $item['age_years']; ?> years
                                                    </span>
                                                </td>
                                                <td class="currency">₱<?php echo number_format($item['purchase_price'] ?: 0, 2); ?></td>
                                                <td class="currency">₱<?php echo number_format($item['total_maintenance_cost'], 2); ?></td>
                                                <td class="currency"><strong>₱<?php echo number_format($total_cost, 2); ?></strong></td>
                                                <td>
                                                    <span class="status-badge status-<?php echo strtolower(str_replace(' ', '_', $item['condition_status'])); ?>">
                                                        <?php echo $item['condition_status']; ?>
                                                    </span>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            <?php else: ?>
                                <div class="empty-state">
                                    <div class="empty-state-icon">
                                        <i class='bx bx-dollar-circle'></i>
                                    </div>
                                    <h3>No Cost/Lifecycle Data</h3>
                                    <p>No resources with purchase information found in the selected period.</p>
                                </div>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                    
                    <!-- Charts Section -->
                    <div id="charts-section" class="report-section" style="display: none;">
                        <h2 style="margin-bottom: 24px; color: var(--primary-color);">Visual Analytics</h2>
                        
                        <div class="charts-container">
                            <?php if ($report_type === 'resource_utilization'): ?>
                                <!-- Condition Distribution Chart -->
                                <div class="chart-card">
                                    <div class="chart-title">Resource Condition Distribution</div>
                                    <div class="chart-container">
                                        <canvas id="conditionChart"></canvas>
                                    </div>
                                </div>
                                
                                <!-- Resource Type Distribution -->
                                <div class="chart-card">
                                    <div class="chart-title">Resource Type Distribution</div>
                                    <div class="chart-container">
                                        <canvas id="typeChart"></canvas>
                                    </div>
                                </div>
                                
                                <!-- Category Distribution -->
                                <div class="chart-card">
                                    <div class="chart-title">Category Distribution</div>
                                    <div class="chart-container">
                                        <canvas id="categoryChart"></canvas>
                                    </div>
                                </div>
                                
                            <?php elseif ($report_type === 'damage_loss'): ?>
                                <!-- Issues by Category -->
                                <div class="chart-card">
                                    <div class="chart-title">Damage/Loss by Category</div>
                                    <div class="chart-container">
                                        <canvas id="damageCategoryChart"></canvas>
                                    </div>
                                </div>
                                
                                <!-- Condition Status -->
                                <div class="chart-card">
                                    <div class="chart-title">Condition Status Breakdown</div>
                                    <div class="chart-container">
                                        <canvas id="damageConditionChart"></canvas>
                                    </div>
                                </div>
                                
                            <?php elseif ($report_type === 'cost_lifecycle'): ?>
                                <!-- Age Distribution -->
                                <div class="chart-card">
                                    <div class="chart-title">Asset Age Distribution</div>
                                    <div class="chart-container">
                                        <canvas id="ageChart"></canvas>
                                    </div>
                                </div>
                                
                                <!-- Cost Distribution -->
                                <div class="chart-card">
                                    <div class="chart-title">Purchase Cost Distribution</div>
                                    <div class="chart-container">
                                        <canvas id="costChart"></canvas>
                                    </div>
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
            // Loading animation
            const animationOverlay = document.getElementById('dashboard-animation');
            const animationProgress = document.getElementById('animation-progress');
            
            setTimeout(() => {
                animationProgress.style.width = '100%';
            }, 100);
            
            setTimeout(() => {
                animationOverlay.style.opacity = '0';
                setTimeout(() => {
                    animationOverlay.style.display = 'none';
                }, 300);
            }, 1000);
            
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
                
                // Reinitialize charts with new theme
                setTimeout(initializeCharts, 100);
            });
            
            // Initialize charts
            initializeCharts();
            
            // Search functionality
            const searchInput = document.getElementById('search-input');
            if (searchInput) {
                searchInput.addEventListener('keyup', function(e) {
                    if (e.key === '/') {
                        e.preventDefault();
                        this.focus();
                    }
                    
                    const searchTerm = this.value.toLowerCase();
                    const currentSection = document.querySelector('.report-section:not([style*="display: none"])');
                    const table = currentSection.querySelector('.data-table');
                    
                    if (table) {
                        const rows = table.querySelectorAll('tbody tr');
                        rows.forEach(row => {
                            const text = row.textContent.toLowerCase();
                            row.style.display = text.includes(searchTerm) ? '' : 'none';
                        });
                    }
                });
                
                // Focus search on "/" key press
                document.addEventListener('keydown', function(e) {
                    if (e.key === '/' && !['INPUT', 'TEXTAREA'].includes(document.activeElement.tagName)) {
                        e.preventDefault();
                        searchInput.focus();
                    }
                });
            }
        });
        
        function initializeCharts() {
            const isDarkMode = document.body.classList.contains('dark-mode');
            const textColor = isDarkMode ? '#f1f5f9' : '#1f2937';
            const gridColor = isDarkMode ? '#334155' : '#e5e7eb';
            const backgroundColors = [
                'rgba(220, 38, 38, 0.7)',
                'rgba(245, 158, 11, 0.7)',
                'rgba(16, 185, 129, 0.7)',
                'rgba(59, 130, 246, 0.7)',
                'rgba(139, 92, 246, 0.7)',
                'rgba(14, 165, 233, 0.7)',
                'rgba(244, 63, 94, 0.7)',
                'rgba(251, 191, 36, 0.7)'
            ];
            
            const borderColors = [
                'rgb(220, 38, 38)',
                'rgb(245, 158, 11)',
                'rgb(16, 185, 129)',
                'rgb(59, 130, 246)',
                'rgb(139, 92, 246)',
                'rgb(14, 165, 233)',
                'rgb(244, 63, 94)',
                'rgb(251, 191, 36)'
            ];
            
            // Destroy existing charts
            Chart.helpers.each(Chart.instances, function(instance) {
                instance.destroy();
            });
            
            <?php if ($report_type === 'resource_utilization' && isset($report_data['stats'])): ?>
                // Condition Distribution Chart
                const conditionCtx = document.getElementById('conditionChart');
                if (conditionCtx) {
                    new Chart(conditionCtx, {
                        type: 'doughnut',
                        data: {
                            labels: ['Serviceable', 'Under Maintenance', 'Condemned'],
                            datasets: [{
                                data: [
                                    <?php echo $report_data['stats']['serviceable_count']; ?>,
                                    <?php echo $report_data['stats']['maintenance_count']; ?>,
                                    <?php echo $report_data['stats']['condemned_count']; ?>
                                ],
                                backgroundColor: [
                                    'rgba(16, 185, 129, 0.7)',
                                    'rgba(245, 158, 11, 0.7)',
                                    'rgba(220, 38, 38, 0.7)'
                                ],
                                borderColor: [
                                    'rgb(16, 185, 129)',
                                    'rgb(245, 158, 11)',
                                    'rgb(220, 38, 38)'
                                ],
                                borderWidth: 1
                            }]
                        },
                        options: {
                            responsive: true,
                            maintainAspectRatio: false,
                            plugins: {
                                legend: {
                                    position: 'bottom',
                                    labels: {
                                        color: textColor,
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
                                            const percentage = Math.round((value / total) * 100);
                                            return `${label}: ${value} items (${percentage}%)`;
                                        }
                                    }
                                }
                            }
                        }
                    });
                }
                
                // Resource Type Distribution Chart
                const typeCtx = document.getElementById('typeChart');
                if (typeCtx && <?php echo !empty($report_data['stats']['by_type']) ? 'true' : 'false'; ?>) {
                    const typeLabels = <?php echo json_encode(array_keys($report_data['stats']['by_type'])); ?>;
                    const typeData = <?php echo json_encode(array_values($report_data['stats']['by_type'])); ?>;
                    
                    new Chart(typeCtx, {
                        type: 'bar',
                        data: {
                            labels: typeLabels,
                            datasets: [{
                                label: 'Number of Resources',
                                data: typeData,
                                backgroundColor: backgroundColors.slice(0, typeLabels.length),
                                borderColor: borderColors.slice(0, typeLabels.length),
                                borderWidth: 1
                            }]
                        },
                        options: {
                            responsive: true,
                            maintainAspectRatio: false,
                            scales: {
                                y: {
                                    beginAtZero: true,
                                    grid: {
                                        color: gridColor
                                    },
                                    ticks: {
                                        color: textColor
                                    }
                                },
                                x: {
                                    grid: {
                                        display: false
                                    },
                                    ticks: {
                                        color: textColor,
                                        maxRotation: 45
                                    }
                                }
                            },
                            plugins: {
                                legend: {
                                    display: false
                                }
                            }
                        }
                    });
                }
                
                // Category Distribution Chart
                const categoryCtx = document.getElementById('categoryChart');
                if (categoryCtx && <?php echo !empty($report_data['stats']['by_category']) ? 'true' : 'false'; ?>) {
                    const categoryLabels = <?php echo json_encode(array_keys($report_data['stats']['by_category'])); ?>;
                    const categoryData = <?php echo json_encode(array_values($report_data['stats']['by_category'])); ?>;
                    
                    new Chart(categoryCtx, {
                        type: 'pie',
                        data: {
                            labels: categoryLabels,
                            datasets: [{
                                data: categoryData,
                                backgroundColor: backgroundColors.slice(0, categoryLabels.length),
                                borderColor: borderColors.slice(0, categoryLabels.length),
                                borderWidth: 1
                            }]
                        },
                        options: {
                            responsive: true,
                            maintainAspectRatio: false,
                            plugins: {
                                legend: {
                                    position: 'right',
                                    labels: {
                                        color: textColor,
                                        font: {
                                            size: 11
                                        }
                                    }
                                }
                            }
                        }
                    });
                }
                
            <?php elseif ($report_type === 'damage_loss' && isset($report_data['stats'])): ?>
                // Damage/Loss by Category Chart
                const damageCategoryCtx = document.getElementById('damageCategoryChart');
                if (damageCategoryCtx && <?php echo !empty($report_data['stats']['by_category']) ? 'true' : 'false'; ?>) {
                    const damageLabels = <?php echo json_encode(array_keys($report_data['stats']['by_category'])); ?>;
                    const damageData = <?php echo json_encode(array_values($report_data['stats']['by_category'])); ?>;
                    
                    new Chart(damageCategoryCtx, {
                        type: 'bar',
                        data: {
                            labels: damageLabels,
                            datasets: [{
                                label: 'Items with Issues',
                                data: damageData,
                                backgroundColor: backgroundColors.slice(0, damageLabels.length),
                                borderColor: borderColors.slice(0, damageLabels.length),
                                borderWidth: 1
                            }]
                        },
                        options: {
                            responsive: true,
                            maintainAspectRatio: false,
                            scales: {
                                y: {
                                    beginAtZero: true,
                                    grid: {
                                        color: gridColor
                                    },
                                    ticks: {
                                        color: textColor,
                                        stepSize: 1
                                    }
                                },
                                x: {
                                    grid: {
                                        display: false
                                    },
                                    ticks: {
                                        color: textColor,
                                        maxRotation: 45
                                    }
                                }
                            },
                            plugins: {
                                legend: {
                                    display: false
                                }
                            }
                        }
                    });
                }
                
                // Condition Status Chart
                const damageConditionCtx = document.getElementById('damageConditionChart');
                if (damageConditionCtx && <?php echo !empty($report_data['stats']['by_status']) ? 'true' : 'false'; ?>) {
                    const conditionLabels = <?php echo json_encode(array_keys($report_data['stats']['by_status'])); ?>;
                    const conditionData = <?php echo json_encode(array_values($report_data['stats']['by_status'])); ?>;
                    
                    new Chart(damageConditionCtx, {
                        type: 'doughnut',
                        data: {
                            labels: conditionLabels,
                            datasets: [{
                                data: conditionData,
                                backgroundColor: [
                                    'rgba(245, 158, 11, 0.7)', // Under Maintenance
                                    'rgba(220, 38, 38, 0.7)',  // Condemned
                                    'rgba(107, 114, 128, 0.7)' // Others
                                ],
                                borderColor: [
                                    'rgb(245, 158, 11)',
                                    'rgb(220, 38, 38)',
                                    'rgb(107, 114, 128)'
                                ],
                                borderWidth: 1
                            }]
                        },
                        options: {
                            responsive: true,
                            maintainAspectRatio: false,
                            plugins: {
                                legend: {
                                    position: 'bottom',
                                    labels: {
                                        color: textColor
                                    }
                                }
                            }
                        }
                    });
                }
                
            <?php elseif ($report_type === 'cost_lifecycle' && isset($report_data['stats'])): ?>
                // Age Distribution Chart
                const ageCtx = document.getElementById('ageChart');
                if (ageCtx) {
                    new Chart(ageCtx, {
                        type: 'bar',
                        data: {
                            labels: ['0-1 years', '1-3 years', '3-5 years', '5+ years'],
                            datasets: [{
                                label: 'Number of Assets',
                                data: [
                                    <?php echo $report_data['stats']['by_age_group']['0-1 years']; ?>,
                                    <?php echo $report_data['stats']['by_age_group']['1-3 years']; ?>,
                                    <?php echo $report_data['stats']['by_age_group']['3-5 years']; ?>,
                                    <?php echo $report_data['stats']['by_age_group']['5+ years']; ?>
                                ],
                                backgroundColor: [
                                    'rgba(16, 185, 129, 0.7)',
                                    'rgba(59, 130, 246, 0.7)',
                                    'rgba(245, 158, 11, 0.7)',
                                    'rgba(220, 38, 38, 0.7)'
                                ],
                                borderColor: [
                                    'rgb(16, 185, 129)',
                                    'rgb(59, 130, 246)',
                                    'rgb(245, 158, 11)',
                                    'rgb(220, 38, 38)'
                                ],
                                borderWidth: 1
                            }]
                        },
                        options: {
                            responsive: true,
                            maintainAspectRatio: false,
                            scales: {
                                y: {
                                    beginAtZero: true,
                                    grid: {
                                        color: gridColor
                                    },
                                    ticks: {
                                        color: textColor,
                                        stepSize: 1
                                    }
                                },
                                x: {
                                    grid: {
                                        display: false
                                    },
                                    ticks: {
                                        color: textColor
                                    }
                                }
                            },
                            plugins: {
                                legend: {
                                    display: false
                                }
                            }
                        }
                    });
                }
                
                // Cost Distribution Chart
                const costCtx = document.getElementById('costChart');
                if (costCtx) {
                    new Chart(costCtx, {
                        type: 'pie',
                        data: {
                            labels: ['Low (< ₱10,000)', 'Medium (₱10,000-₱50,000)', 'High (> ₱50,000)'],
                            datasets: [{
                                data: [
                                    <?php echo $report_data['stats']['by_cost_group']['Low (< ₱10,000)']; ?>,
                                    <?php echo $report_data['stats']['by_cost_group']['Medium (₱10,000-₱50,000)']; ?>,
                                    <?php echo $report_data['stats']['by_cost_group']['High (> ₱50,000)']; ?>
                                ],
                                backgroundColor: [
                                    'rgba(16, 185, 129, 0.7)',
                                    'rgba(59, 130, 246, 0.7)',
                                    'rgba(220, 38, 38, 0.7)'
                                ],
                                borderColor: [
                                    'rgb(16, 185, 129)',
                                    'rgb(59, 130, 246)',
                                    'rgb(220, 38, 38)'
                                ],
                                borderWidth: 1
                            }]
                        },
                        options: {
                            responsive: true,
                            maintainAspectRatio: false,
                            plugins: {
                                legend: {
                                    position: 'right',
                                    labels: {
                                        color: textColor,
                                        font: {
                                            size: 12
                                        }
                                    }
                                }
                            }
                        }
                    });
                }
                
            <?php endif; ?>
        }
        
        function changeReportType(type) {
            document.getElementById('report-type-input').value = type;
            
            // Update form fields based on report type
            const form = document.querySelector('.report-controls');
            const additionalFilters = form.querySelectorAll('.control-group');
            
            // Hide all additional filters first
            additionalFilters.forEach((group, index) => {
                if (index > 3) { // Skip first 4 groups (report type, dates, buttons)
                    group.style.display = 'none';
                }
            });
            
            // Show relevant filters
            if (type === 'resource_utilization') {
                form.querySelectorAll('.control-group')[3].style.display = 'flex'; // Unit filter
                form.querySelectorAll('.control-group')[4].style.display = 'flex'; // Resource type filter
            } else if (type === 'damage_loss') {
                form.querySelectorAll('.control-group')[3].style.display = 'flex'; // Category filter
            }
        }
        
        function showReportSection(section) {
            // Update tabs
            document.querySelectorAll('.report-tab').forEach(tab => {
                tab.classList.remove('active');
            });
            event.target.classList.add('active');
            
            // Update section visibility
            document.querySelectorAll('.report-section').forEach(sec => {
                sec.style.display = 'none';
            });
            document.getElementById(section + '-section').style.display = 'block';
            
            // Reinitialize charts if showing charts section
            if (section === 'charts') {
                setTimeout(initializeCharts, 100);
            }
        }
        
        function showLoading(text = 'Processing...') {
            document.getElementById('loading-text').textContent = text;
            document.getElementById('loading-overlay').classList.add('active');
        }
        
        function hideLoading() {
            document.getElementById('loading-overlay').classList.remove('active');
        }
        
        function toggleSubmenu(id) {
            const submenu = document.getElementById(id);
            const arrow = submenu.previousElementSibling.querySelector('.dropdown-arrow');
            
            submenu.classList.toggle('active');
            arrow.classList.toggle('rotated');
        }
        
        function refreshPage() {
            window.location.reload();
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
        
        // Initialize correct filters based on current report type
        document.addEventListener('DOMContentLoaded', function() {
            changeReportType('<?php echo $report_type; ?>');
        });
    </script>
</body>
</html>