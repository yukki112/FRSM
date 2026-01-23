<?php
session_start();
require_once '../../config/db_connection.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: ../../login.php");
    exit();
}

$user_id = $_SESSION['user_id'];

// Get user information
$query = "SELECT first_name, middle_name, last_name, role, email, avatar FROM users WHERE id = ?";
$stmt = $pdo->prepare($query);
$stmt->execute([$user_id]);
$user = $stmt->fetch();

if (!$user) {
    header("Location: ../../login.php");
    exit();
}

$first_name = htmlspecialchars($user['first_name']);
$middle_name = htmlspecialchars($user['middle_name']);
$last_name = htmlspecialchars($user['last_name']);
$role = htmlspecialchars($user['role']);
$email = htmlspecialchars($user['email']);
$avatar = htmlspecialchars($user['avatar']);

$full_name = $first_name;
if (!empty($middle_name)) {
    $full_name .= " " . $middle_name;
}
$full_name .= " " . $last_name;

// Check if user is a volunteer (USER role)
if ($role !== 'USER') {
    header("Location: ../dashboard.php");
    exit();
}

// Get volunteer ID and unit assignment
$volunteer_query = "
    SELECT v.id, v.first_name, v.last_name, v.contact_number, 
           va.unit_id, u.unit_name, u.unit_code
    FROM volunteers v
    LEFT JOIN volunteer_assignments va ON v.id = va.volunteer_id AND va.status = 'Active'
    LEFT JOIN units u ON va.unit_id = u.id
    WHERE v.user_id = ?
";
$volunteer_stmt = $pdo->prepare($volunteer_query);
$volunteer_stmt->execute([$user_id]);
$volunteer = $volunteer_stmt->fetch();

if (!$volunteer) {
    // User is not registered as a volunteer
    header("Location: ../dashboard.php");
    exit();
}

$volunteer_id = $volunteer['id'];
$volunteer_name = htmlspecialchars($volunteer['first_name'] . ' ' . $volunteer['last_name']);
$unit_id = $volunteer['unit_id'];
$unit_name = htmlspecialchars($volunteer['unit_name']);

// Get stock levels for all equipment
$stock_query = "
    SELECT 
        r.id,
        r.resource_name,
        r.resource_type,
        r.category,
        r.description,
        r.quantity,
        r.available_quantity,
        r.unit_of_measure,
        r.minimum_stock_level,
        r.reorder_quantity,
        r.condition_status,
        r.location,
        r.storage_area,
        r.unit_id,
        r.is_active,
        u.unit_name,
        CASE 
            WHEN r.available_quantity <= r.minimum_stock_level THEN 'critical'
            WHEN r.available_quantity <= r.minimum_stock_level * 1.5 THEN 'low'
            WHEN r.available_quantity <= r.quantity * 0.3 THEN 'medium'
            ELSE 'good'
        END as stock_level
    FROM resources r
    LEFT JOIN units u ON r.unit_id = u.id
    WHERE r.is_active = 1
    ORDER BY 
        CASE 
            WHEN r.available_quantity <= r.minimum_stock_level THEN 1
            WHEN r.available_quantity <= r.minimum_stock_level * 1.5 THEN 2
            WHEN r.available_quantity <= r.quantity * 0.3 THEN 3
            ELSE 4
        END,
        r.category,
        r.resource_name
";

$stock_stmt = $pdo->prepare($stock_query);
$stock_stmt->execute();
$stock_items = $stock_stmt->fetchAll();

// Get stock levels for volunteer's unit
$unit_stock = array_filter($stock_items, function($item) use ($unit_id) {
    return $item['unit_id'] == $unit_id;
});

// Calculate statistics
$total_items = count($stock_items);
$unit_items = count($unit_stock);

$critical_items = count(array_filter($stock_items, function($item) {
    return $item['stock_level'] === 'critical';
}));

$low_items = count(array_filter($stock_items, function($item) {
    return $item['stock_level'] === 'low';
}));

$medium_items = count(array_filter($stock_items, function($item) {
    return $item['stock_level'] === 'medium';
}));

$good_items = count(array_filter($stock_items, function($item) {
    return $item['stock_level'] === 'good';
}));

// Calculate total value (simplified - would need purchase price data)
$total_quantity = array_sum(array_column($stock_items, 'quantity'));
$available_quantity = array_sum(array_column($stock_items, 'available_quantity'));

// Get categories for filter
$categories = array_unique(array_column($stock_items, 'category'));
sort($categories);

// Get stock levels for filter
$stock_levels = ['critical', 'low', 'medium', 'good'];

// Handle filters
$category_filter = $_GET['category'] ?? 'all';
$level_filter = $_GET['level'] ?? 'all';
$search_filter = $_GET['search'] ?? '';

// Apply filters
$filtered_items = $stock_items;
if ($category_filter !== 'all') {
    $filtered_items = array_filter($filtered_items, function($item) use ($category_filter) {
        return $item['category'] === $category_filter;
    });
}

if ($level_filter !== 'all') {
    $filtered_items = array_filter($filtered_items, function($item) use ($level_filter) {
        return $item['stock_level'] === $level_filter;
    });
}

if ($search_filter) {
    $search_lower = strtolower($search_filter);
    $filtered_items = array_filter($filtered_items, function($item) use ($search_lower) {
        $name = strtolower($item['resource_name']);
        $category = strtolower($item['category']);
        $location = strtolower($item['location'] ?? '');
        
        return strpos($name, $search_lower) !== false || 
               strpos($category, $search_lower) !== false ||
               strpos($location, $search_lower) !== false;
    });
}

// Get low stock items (critical + low)
$low_stock_items = array_filter($stock_items, function($item) {
    return $item['stock_level'] === 'critical' || $item['stock_level'] === 'low';
});

// Get items needing reorder
$reorder_items = array_filter($stock_items, function($item) {
    return $item['available_quantity'] <= $item['minimum_stock_level'] && 
           $item['reorder_quantity'] > 0;
});

// Close statements
$stmt = null;
$volunteer_stmt = null;
$stock_stmt = null;
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Stock Levels - Fire & Rescue Services Management</title>
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

        .section-container {
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: 16px;
            padding: 30px;
            margin-bottom: 30px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
        }

        .section-title {
            font-size: 20px;
            font-weight: 700;
            color: var(--text-color);
            margin-bottom: 20px;
            padding-bottom: 12px;
            border-bottom: 2px solid var(--border-color);
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .section-title i {
            color: var(--primary-color);
        }

        .stats-container {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 15px;
            margin-bottom: 25px;
        }

        .stat-card {
            background: var(--background-color);
            border: 1px solid var(--border-color);
            border-radius: 12px;
            padding: 20px;
            text-align: center;
            transition: all 0.3s ease;
        }

        .stat-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
        }

        .stat-value {
            font-size: 28px;
            font-weight: 800;
            margin-bottom: 5px;
        }

        .stat-label {
            font-size: 12px;
            color: var(--text-light);
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }

        .btn {
            padding: 10px 20px;
            border-radius: 10px;
            font-weight: 600;
            border: none;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 14px;
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

        .btn-sm {
            padding: 6px 12px;
            font-size: 12px;
            border-radius: 6px;
        }

        .filter-container {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-bottom: 20px;
            padding: 20px;
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: 12px;
        }

        .filter-group {
            display: flex;
            flex-direction: column;
        }

        .filter-label {
            font-size: 12px;
            color: var(--text-light);
            margin-bottom: 6px;
            font-weight: 600;
        }

        .filter-select, .filter-input {
            padding: 10px 12px;
            border: 1px solid var(--border-color);
            border-radius: 8px;
            background: var(--background-color);
            color: var(--text-color);
            font-size: 14px;
        }

        .filter-select:focus, .filter-input:focus {
            outline: none;
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(220, 38, 38, 0.1);
        }

        .filter-actions {
            display: flex;
            align-items: flex-end;
            gap: 10px;
        }

        .stock-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
        }

        .stock-table th {
            background: var(--card-bg);
            padding: 15px;
            text-align: left;
            font-weight: 600;
            color: var(--text-color);
            border-bottom: 2px solid var(--border-color);
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }

        .stock-table td {
            padding: 15px;
            border-bottom: 1px solid var(--border-color);
            font-size: 14px;
        }

        .stock-table tr:hover {
            background: var(--gray-100);
        }

        .dark-mode .stock-table tr:hover {
            background: var(--gray-800);
        }

        .stock-level-badge {
            padding: 5px 12px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 600;
            display: inline-block;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .level-critical {
            background: rgba(220, 38, 38, 0.1);
            color: var(--danger);
            border: 1px solid rgba(220, 38, 38, 0.2);
        }

        .level-low {
            background: rgba(245, 158, 11, 0.1);
            color: var(--warning);
            border: 1px solid rgba(245, 158, 11, 0.2);
        }

        .level-medium {
            background: rgba(59, 130, 246, 0.1);
            color: var(--info);
            border: 1px solid rgba(59, 130, 246, 0.2);
        }

        .level-good {
            background: rgba(16, 185, 129, 0.1);
            color: var(--success);
            border: 1px solid rgba(16, 185, 129, 0.2);
        }

        .category-badge {
            padding: 4px 10px;
            border-radius: 6px;
            font-size: 11px;
            font-weight: 600;
            display: inline-block;
        }

        .category-firefighting {
            background: rgba(220, 38, 38, 0.1);
            color: var(--danger);
            border: 1px solid rgba(220, 38, 38, 0.2);
        }

        .category-medical {
            background: rgba(16, 185, 129, 0.1);
            color: var(--success);
            border: 1px solid rgba(16, 185, 129, 0.2);
        }

        .category-rescue {
            background: rgba(59, 130, 246, 0.1);
            color: var(--info);
            border: 1px solid rgba(59, 130, 246, 0.2);
        }

        .category-ppe {
            background: rgba(168, 85, 247, 0.1);
            color: #8b5cf6;
            border: 1px solid rgba(168, 85, 247, 0.2);
        }

        .category-communication {
            background: rgba(245, 158, 11, 0.1);
            color: var(--warning);
            border: 1px solid rgba(245, 158, 11, 0.2);
        }

        .category-other {
            background: rgba(107, 114, 128, 0.1);
            color: var(--gray-500);
            border: 1px solid rgba(107, 114, 128, 0.2);
        }

        .progress-bar {
            height: 8px;
            background: var(--gray-200);
            border-radius: 4px;
            overflow: hidden;
            margin-top: 5px;
        }

        .progress-fill {
            height: 100%;
            border-radius: 4px;
        }

        .progress-critical {
            background: var(--danger);
        }

        .progress-low {
            background: var(--warning);
        }

        .progress-medium {
            background: var(--info);
        }

        .progress-good {
            background: var(--success);
        }

        .empty-state {
            text-align: center;
            padding: 40px 20px;
            color: var(--text-light);
        }

        .empty-state i {
            font-size: 48px;
            margin-bottom: 16px;
            opacity: 0.5;
        }

        .empty-state h3 {
            font-size: 18px;
            margin-bottom: 8px;
            color: var(--text-color);
        }

        .empty-state p {
            font-size: 14px;
            max-width: 400px;
            margin: 0 auto;
        }

        .unit-info-card {
            background: linear-gradient(135deg, #fef2f2 0%, #fee2e2 100%);
            border: 1px solid #fecaca;
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 20px;
        }

        .dark-mode .unit-info-card {
            background: linear-gradient(135deg, #1e293b 0%, #2d3748 100%);
            border-color: #4b5563;
        }

        .unit-title {
            font-size: 18px;
            font-weight: 700;
            color: var(--danger);
            margin-bottom: 10px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .unit-details {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
        }

        .unit-detail {
            display: flex;
            flex-direction: column;
        }

        .unit-label {
            font-size: 12px;
            color: var(--text-light);
            margin-bottom: 4px;
        }

        .unit-value {
            font-weight: 600;
            color: var(--text-color);
            font-size: 14px;
        }

        /* User profile dropdown styles */
        .user-profile-dropdown {
            position: absolute;
            top: 100%;
            right: 0;
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: 8px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
            min-width: 200px;
            z-index: 1000;
            display: none;
            margin-top: 10px;
        }
        
        .user-profile-dropdown.show {
            display: block;
        }
        
        .dropdown-item {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 12px 16px;
            color: var(--text-color);
            text-decoration: none;
            transition: all 0.3s ease;
        }
        
        .dropdown-item:hover {
            background: var(--gray-100);
        }
        
        .dropdown-item i {
            font-size: 18px;
        }
        
        .dropdown-divider {
            height: 1px;
            background: var(--border-color);
            margin: 4px 0;
        }

        .tab-container {
            margin-bottom: 20px;
        }

        .tab-buttons {
            display: flex;
            border-bottom: 2px solid var(--border-color);
            flex-wrap: wrap;
        }

        .tab-button {
            padding: 12px 24px;
            background: none;
            border: none;
            cursor: pointer;
            font-size: 14px;
            font-weight: 600;
            color: var(--text-light);
            transition: all 0.3s ease;
            position: relative;
        }

        .tab-button.active {
            color: var(--primary-color);
        }

        .tab-button.active::after {
            content: '';
            position: absolute;
            bottom: -2px;
            left: 0;
            right: 0;
            height: 2px;
            background: var(--primary-color);
        }

        .tab-content {
            display: none;
        }

        .tab-content.active {
            display: block;
        }

        .stock-summary {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .summary-card {
            background: var(--background-color);
            border: 1px solid var(--border-color);
            border-radius: 12px;
            padding: 20px;
        }

        .summary-title {
            font-size: 14px;
            color: var(--text-light);
            margin-bottom: 10px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .summary-value {
            font-size: 24px;
            font-weight: 700;
            margin-bottom: 10px;
        }

        .summary-details {
            font-size: 12px;
            color: var(--text-light);
        }

        .alert-card {
            background: linear-gradient(135deg, #fef3c7 0%, #fde68a 100%);
            border: 1px solid #fbbf24;
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 20px;
        }

        .dark-mode .alert-card {
            background: linear-gradient(135deg, #78350f 0%, #92400e 100%);
            border-color: #f59e0b;
        }

        .alert-title {
            font-size: 16px;
            font-weight: 700;
            color: #92400e;
            margin-bottom: 10px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .dark-mode .alert-title {
            color: #fbbf24;
        }

        .alert-list {
            list-style: none;
            padding: 0;
            margin: 0;
        }

        .alert-item {
            padding: 8px 0;
            border-bottom: 1px solid rgba(251, 191, 36, 0.3);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .alert-item:last-child {
            border-bottom: none;
        }

        .alert-item-name {
            font-weight: 600;
        }

        .alert-item-quantity {
            color: var(--danger);
            font-weight: 700;
        }

        .stock-chart {
            height: 300px;
            margin-top: 20px;
            position: relative;
        }

        .chart-container {
            background: var(--background-color);
            border: 1px solid var(--border-color);
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 20px;
        }

        .chart-placeholder {
            height: 250px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--text-light);
            font-style: italic;
        }

        @media (max-width: 992px) {
            .content-container {
                padding: 0 25px 30px;
            }
            
            .dashboard-header {
                padding: 40px 25px 30px;
            }
            
            .dashboard-title {
                font-size: 32px;
            }
            
            .section-container {
                padding: 20px;
            }
            
            .stats-container {
                grid-template-columns: repeat(2, 1fr);
            }
            
            .filter-container {
                grid-template-columns: 1fr;
            }
            
            .stock-table {
                display: block;
                overflow-x: auto;
            }
            
            .stock-summary {
                grid-template-columns: 1fr;
            }
            
            .tab-buttons {
                flex-direction: column;
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
            
            .section-container {
                padding: 15px;
            }
            
            .stats-container {
                grid-template-columns: 1fr;
            }
            
            .tab-button {
                width: 100%;
                text-align: left;
            }
            
            .filter-actions {
                flex-direction: column;
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
                    <img src="../../img/frsm-logo.png" alt="Fire & Rescue Logo" style="width: 40px; height: 45px;">
                </div>
                <span class="logo-text">Fire & Rescue</span>
            </div>
            
             <!-- Menu Section -->
            <div class="menu-section">
                <p class="menu-title">FIRE & RESCUE MANAGEMENT</p>
                
                <div class="menu-items">
                    <a href="../user_dashboard.php" class="menu-item" id="dashboard-menu">
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
                        <a href="../fir/active_incidents.php" class="submenu-item">Active Incidents</a>
                       
                        <a href="../fir/response_history.php" class="submenu-item">Response History</a>
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
            <a href="../dc/suggested_unit.php" class="submenu-item">Suggested Unit</a>
            <a href="../dc/incident_location.php" class="submenu-item">Incident Location</a>
            
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
                        <a href="../vra/volunteer_list.php" class="submenu-item">Volunteer List</a>
                        <a href="../vra/roles_skills.php" class="submenu-item">Roles & Skills</a>
                        <a href="../vra/availability.php" class="submenu-item">Availability</a>
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
                    <div id="inventory" class="submenu active">
                        <a href="equipment_list.php" class="submenu-item">Equipment List</a>
                        <a href="stock_levels.php" class="submenu-item active">Stock Levels</a>
                        <a href="maintenance_logs.php" class="submenu-item">Maintenance Logs</a>
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
                        <a href="../sds/view_shifts.php" class="submenu-item">Shift Calendar</a>
                        <a href="../sds/confirm_availability.php" class="submenu-item">Confirm Availability</a>
                        <a href="../sds/duty_assignments.php" class="submenu-item">Duty Assignments</a>
                        <a href="../sds/attendance_logs.php" class="submenu-item">Attendance Logs</a>
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
                        <a href="../tc/register_training.php" class="submenu-item">Register for Training</a>
                        <a href="../tc/training_records.php" class="submenu-item">Training Records</a>
                        <a href="../tc/certification_status.php" class="submenu-item">Certification Status</a>
                    </div>
                    
                   
                </div>
                
                <p class="menu-title" style="margin-top: 32px;">GENERAL</p>
                
                <div class="menu-items">
                    <a href="../settings.php" class="menu-item">
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
                            <input type="text" placeholder="Search stock items..." class="search-input" id="search-input">
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
                                <img src="../../profile/uploads/avatars/<?php echo $avatar; ?>" alt="User" class="user-avatar">
                            <?php else: ?>
                                <div class="user-avatar" style="background: linear-gradient(135deg, var(--primary-color), var(--secondary-color)); color: white; display: flex; align-items: center; justify-content: center; font-weight: bold; border-radius: 50%; width: 40px; height: 40px;">
                                    <?php echo strtoupper(substr($first_name, 0, 1) . substr($last_name, 0, 1)); ?>
                                </div>
                            <?php endif; ?>
                            <div class="user-info">
                                <p class="user-name"><?php echo $full_name; ?></p>
                                <p class="user-email"><?php echo $email; ?></p>
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
                <div class="dashboard-header">
                    <div>
                        <h1 class="dashboard-title">Stock Levels</h1>
                        <p class="dashboard-subtitle">Monitor inventory levels and track stock status</p>
                    </div>
                </div>
                
                <!-- Content Container -->
                <div class="content-container">
                    <!-- Unit Information -->
                    <?php if ($unit_name): ?>
                        <div class="unit-info-card">
                            <h3 class="unit-title">
                                <i class='bx bx-group'></i>
                                Your Unit: <?php echo $unit_name; ?>
                            </h3>
                            <div class="unit-details">
                                <div class="unit-detail">
                                    <span class="unit-label">Unit Stock Items</span>
                                    <span class="unit-value"><?php echo $unit_items; ?> items</span>
                                </div>
                                <div class="unit-detail">
                                    <span class="unit-label">Low Stock Items</span>
                                    <span class="unit-value" style="color: var(--warning);">
                                        <?php 
                                        $unit_low_stock = count(array_filter($unit_stock, function($item) {
                                            return $item['stock_level'] === 'critical' || $item['stock_level'] === 'low';
                                        }));
                                        echo $unit_low_stock;
                                        ?>
                                    </span>
                                </div>
                                <div class="unit-detail">
                                    <span class="unit-label">Available Quantity</span>
                                    <span class="unit-value">
                                        <?php 
                                        $unit_available = array_sum(array_column($unit_stock, 'available_quantity'));
                                        echo $unit_available;
                                        ?>
                                    </span>
                                </div>
                                <div class="unit-detail">
                                    <span class="unit-label">Total Quantity</span>
                                    <span class="unit-value">
                                        <?php 
                                        $unit_total = array_sum(array_column($unit_stock, 'quantity'));
                                        echo $unit_total;
                                        ?>
                                    </span>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>
                    
                    <!-- Stock Overview Statistics -->
                    <div class="section-container">
                        <h3 class="section-title">
                            <i class='bx bx-stats'></i>
                            Stock Overview
                        </h3>
                        
                        <div class="stats-container">
                            <div class="stat-card">
                                <div class="stat-value" style="color: var(--primary-color);">
                                    <?php echo $total_items; ?>
                                </div>
                                <div class="stat-label">Total Items</div>
                            </div>
                            
                            <div class="stat-card">
                                <div class="stat-value" style="color: var(--danger);">
                                    <?php echo $critical_items; ?>
                                </div>
                                <div class="stat-label">Critical</div>
                            </div>
                            
                            <div class="stat-card">
                                <div class="stat-value" style="color: var(--warning);">
                                    <?php echo $low_items; ?>
                                </div>
                                <div class="stat-label">Low Stock</div>
                            </div>
                            
                            <div class="stat-card">
                                <div class="stat-value" style="color: var(--success);">
                                    <?php echo $good_items; ?>
                                </div>
                                <div class="stat-label">Good Stock</div>
                            </div>
                        </div>
                        
                        <div class="stock-summary">
                            <div class="summary-card">
                                <div class="summary-title">
                                    <i class='bx bx-package'></i>
                                    Total Inventory
                                </div>
                                <div class="summary-value"><?php echo $total_quantity; ?> units</div>
                                <div class="summary-details">
                                    <?php echo $available_quantity; ?> available • 
                                    <?php echo $total_quantity - $available_quantity; ?> in use/reserved
                                </div>
                            </div>
                            
                            <div class="summary-card">
                                <div class="summary-title">
                                    <i class='bx bx-trending-down'></i>
                                    Items Needing Attention
                                </div>
                                <div class="summary-value"><?php echo $critical_items + $low_items; ?> items</div>
                                <div class="summary-details">
                                    <?php echo $critical_items; ?> critical • <?php echo $low_items; ?> low
                                </div>
                            </div>
                            
                            <div class="summary-card">
                                <div class="summary-title">
                                    <i class='bx bx-refresh'></i>
                                    Reorder Needed
                                </div>
                                <div class="summary-value"><?php echo count($reorder_items); ?> items</div>
                                <div class="summary-details">
                                    Below minimum stock level
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Stock Alerts -->
                    <?php if (!empty($low_stock_items)): ?>
                        <div class="alert-card">
                            <h3 class="alert-title">
                                <i class='bx bx-alarm-exclamation'></i>
                                Low Stock Alerts
                            </h3>
                            <ul class="alert-list">
                                <?php 
                                $alert_count = 0;
                                foreach ($low_stock_items as $item): 
                                    if ($alert_count >= 5) break;
                                ?>
                                    <li class="alert-item">
                                        <span class="alert-item-name"><?php echo htmlspecialchars($item['resource_name']); ?></span>
                                        <span class="alert-item-quantity">
                                            <?php echo $item['available_quantity']; ?> / 
                                            <?php echo $item['quantity']; ?> 
                                            <?php echo $item['unit_of_measure'] ?: 'units'; ?>
                                        </span>
                                    </li>
                                <?php 
                                    $alert_count++;
                                endforeach; 
                                ?>
                            </ul>
                            <?php if (count($low_stock_items) > 5): ?>
                                <div style="margin-top: 10px; text-align: center; color: var(--text-light); font-size: 12px;">
                                    + <?php echo count($low_stock_items) - 5; ?> more items with low stock
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                    
                    <!-- Tabs -->
                    <div class="tab-container">
                        <div class="tab-buttons">
                            <button class="tab-button active" onclick="switchTab('all-stock')">
                                <i class='bx bx-list-ul'></i> All Stock
                            </button>
                            <button class="tab-button" onclick="switchTab('unit-stock')">
                                <i class='bx bx-group'></i> Unit Stock
                            </button>
                            <button class="tab-button" onclick="switchTab('low-stock')">
                                <i class='bx bx-trending-down'></i> Low Stock
                            </button>
                            <button class="tab-button" onclick="switchTab('reorder')">
                                <i class='bx bx-refresh'></i> Reorder Needed
                            </button>
                        </div>
                        
                        <!-- All Stock Tab -->
                        <div id="all-stock" class="tab-content active">
                            <div class="section-container">
                                <h3 class="section-title">
                                    <i class='bx bx-list-ul'></i>
                                    All Stock Items
                                    <span class="badge badge-info"><?php echo count($filtered_items); ?> items</span>
                                </h3>
                                
                                <!-- Filters -->
                                <div class="filter-container">
                                    <form method="GET" action="" id="filter-form">
                                        <div class="filter-group">
                                            <label class="filter-label">Search</label>
                                            <input type="text" name="search" class="filter-input" 
                                                   placeholder="Search by name, category, or location..." 
                                                   value="<?php echo htmlspecialchars($search_filter); ?>">
                                        </div>
                                        
                                        <div class="filter-group">
                                            <label class="filter-label">Category</label>
                                            <select name="category" class="filter-select">
                                                <option value="all">All Categories</option>
                                                <?php foreach ($categories as $category): ?>
                                                    <option value="<?php echo htmlspecialchars($category); ?>" 
                                                            <?php echo $category_filter === $category ? 'selected' : ''; ?>>
                                                        <?php echo htmlspecialchars($category); ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        
                                        <div class="filter-group">
                                            <label class="filter-label">Stock Level</label>
                                            <select name="level" class="filter-select">
                                                <option value="all">All Levels</option>
                                                <?php foreach ($stock_levels as $level): ?>
                                                    <option value="<?php echo $level; ?>" 
                                                            <?php echo $level_filter === $level ? 'selected' : ''; ?>>
                                                        <?php echo ucfirst($level); ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        
                                        <div class="filter-actions">
                                            <button type="submit" class="btn btn-primary">
                                                <i class='bx bx-filter-alt'></i> Apply Filters
                                            </button>
                                            <a href="stock_levels.php" class="btn btn-secondary">
                                                <i class='bx bx-reset'></i> Clear Filters
                                            </a>
                                        </div>
                                    </form>
                                </div>
                                
                                <?php if (!empty($filtered_items)): ?>
                                    <table class="stock-table">
                                        <thead>
                                            <tr>
                                                <th>Item Name</th>
                                                <th>Category</th>
                                                <th>Stock Level</th>
                                                <th>Quantity</th>
                                                <th>Available</th>
                                                <th>Minimum</th>
                                                <th>Location</th>
                                                <th>Unit</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($filtered_items as $item): 
                                                $percentage = $item['quantity'] > 0 ? 
                                                    ($item['available_quantity'] / $item['quantity']) * 100 : 0;
                                                
                                                $level_class = 'level-' . $item['stock_level'];
                                                $progress_class = 'progress-' . $item['stock_level'];
                                                
                                                $category_class = 'category-other';
                                                switch ($item['category']) {
                                                    case 'Firefighting': $category_class = 'category-firefighting'; break;
                                                    case 'Medical': $category_class = 'category-medical'; break;
                                                    case 'Rescue': $category_class = 'category-rescue'; break;
                                                    case 'PPE': $category_class = 'category-ppe'; break;
                                                    case 'Communication': $category_class = 'category-communication'; break;
                                                }
                                            ?>
                                                <tr>
                                                    <td>
                                                        <strong><?php echo htmlspecialchars($item['resource_name']); ?></strong>
                                                        <?php if (!empty($item['description'])): ?>
                                                            <br><small style="color: var(--text-light);"><?php echo substr(htmlspecialchars($item['description']), 0, 50) . '...'; ?></small>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td>
                                                        <span class="category-badge <?php echo $category_class; ?>">
                                                            <?php echo htmlspecialchars($item['category']); ?>
                                                        </span>
                                                    </td>
                                                    <td>
                                                        <span class="stock-level-badge <?php echo $level_class; ?>">
                                                            <?php echo ucfirst($item['stock_level']); ?>
                                                        </span>
                                                    </td>
                                                    <td>
                                                        <?php echo $item['quantity']; ?> <?php echo $item['unit_of_measure'] ?: 'units'; ?>
                                                        <div class="progress-bar">
                                                            <div class="progress-fill <?php echo $progress_class; ?>" 
                                                                 style="width: <?php echo min($percentage, 100); ?>%"></div>
                                                        </div>
                                                    </td>
                                                    <td>
                                                        <strong><?php echo $item['available_quantity']; ?></strong>
                                                        <br>
                                                        <small style="color: var(--text-light);">
                                                            <?php echo round($percentage, 1); ?>% available
                                                        </small>
                                                    </td>
                                                    <td>
                                                        <?php echo $item['minimum_stock_level'] ?: 'N/A'; ?>
                                                        <?php if ($item['reorder_quantity']): ?>
                                                            <br>
                                                            <small style="color: var(--text-light);">
                                                                Reorder: <?php echo $item['reorder_quantity']; ?>
                                                            </small>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td><?php echo htmlspecialchars($item['location'] ?: 'Not specified'); ?></td>
                                                    <td><?php echo htmlspecialchars($item['unit_name'] ?: 'Unassigned'); ?></td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                <?php else: ?>
                                    <div class="empty-state">
                                        <i class='bx bx-package'></i>
                                        <h3>No Stock Items Found</h3>
                                        <p>No items match your search criteria.</p>
                                        <?php if ($search_filter || $category_filter !== 'all' || $level_filter !== 'all'): ?>
                                            <div style="margin-top: 20px;">
                                                <a href="stock_levels.php" class="btn btn-primary">
                                                    <i class='bx bx-reset'></i> Clear Filters
                                                </a>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                        
                        <!-- Unit Stock Tab -->
                        <div id="unit-stock" class="tab-content">
                            <div class="section-container">
                                <h3 class="section-title">
                                    <i class='bx bx-group'></i>
                                    Unit Stock Levels
                                    <?php if (!empty($unit_stock)): ?>
                                        <span class="badge badge-info"><?php echo count($unit_stock); ?> items</span>
                                    <?php endif; ?>
                                </h3>
                                
                                <?php if (!empty($unit_stock)): ?>
                                    <table class="stock-table">
                                        <thead>
                                            <tr>
                                                <th>Item Name</th>
                                                <th>Category</th>
                                                <th>Stock Level</th>
                                                <th>Quantity</th>
                                                <th>Available</th>
                                                <th>Minimum</th>
                                                <th>Location</th>
                                                <th>Status</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($unit_stock as $item): 
                                                $percentage = $item['quantity'] > 0 ? 
                                                    ($item['available_quantity'] / $item['quantity']) * 100 : 0;
                                                
                                                $level_class = 'level-' . $item['stock_level'];
                                                $progress_class = 'progress-' . $item['stock_level'];
                                                
                                                $category_class = 'category-other';
                                                switch ($item['category']) {
                                                    case 'Firefighting': $category_class = 'category-firefighting'; break;
                                                    case 'Medical': $category_class = 'category-medical'; break;
                                                    case 'Rescue': $category_class = 'category-rescue'; break;
                                                    case 'PPE': $category_class = 'category-ppe'; break;
                                                    case 'Communication': $category_class = 'category-communication'; break;
                                                }
                                                
                                                $status_class = 'status-serviceable';
                                                switch ($item['condition_status']) {
                                                    case 'Under Maintenance': $status_class = 'status-maintenance'; break;
                                                    case 'Condemned': $status_class = 'status-condemned'; break;
                                                    case 'Out of Service': $status_class = 'status-out'; break;
                                                }
                                            ?>
                                                <tr>
                                                    <td>
                                                        <strong><?php echo htmlspecialchars($item['resource_name']); ?></strong>
                                                        <?php if (!empty($item['description'])): ?>
                                                            <br><small style="color: var(--text-light);"><?php echo substr(htmlspecialchars($item['description']), 0, 50) . '...'; ?></small>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td>
                                                        <span class="category-badge <?php echo $category_class; ?>">
                                                            <?php echo htmlspecialchars($item['category']); ?>
                                                        </span>
                                                    </td>
                                                    <td>
                                                        <span class="stock-level-badge <?php echo $level_class; ?>">
                                                            <?php echo ucfirst($item['stock_level']); ?>
                                                        </span>
                                                    </td>
                                                    <td>
                                                        <?php echo $item['quantity']; ?> <?php echo $item['unit_of_measure'] ?: 'units'; ?>
                                                        <div class="progress-bar">
                                                            <div class="progress-fill <?php echo $progress_class; ?>" 
                                                                 style="width: <?php echo min($percentage, 100); ?>%"></div>
                                                        </div>
                                                    </td>
                                                    <td>
                                                        <strong><?php echo $item['available_quantity']; ?></strong>
                                                        <br>
                                                        <small style="color: var(--text-light);">
                                                            <?php echo round($percentage, 1); ?>% available
                                                        </small>
                                                    </td>
                                                    <td>
                                                        <?php echo $item['minimum_stock_level'] ?: 'N/A'; ?>
                                                        <?php if ($item['reorder_quantity']): ?>
                                                            <br>
                                                            <small style="color: var(--text-light);">
                                                                Reorder: <?php echo $item['reorder_quantity']; ?>
                                                            </small>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td><?php echo htmlspecialchars($item['location'] ?: 'Not specified'); ?></td>
                                                    <td>
                                                        <span class="status-badge <?php echo $status_class; ?>">
                                                            <?php echo htmlspecialchars($item['condition_status']); ?>
                                                        </span>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                <?php else: ?>
                                    <div class="empty-state">
                                        <i class='bx bx-group'></i>
                                        <h3>No Unit Stock</h3>
                                        <p>There are no stock items assigned to your unit.</p>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                        
                        <!-- Low Stock Tab -->
                        <div id="low-stock" class="tab-content">
                            <div class="section-container">
                                <h3 class="section-title">
                                    <i class='bx bx-trending-down'></i>
                                    Low Stock Items
                                    <?php if (!empty($low_stock_items)): ?>
                                        <span class="badge badge-danger"><?php echo count($low_stock_items); ?> items</span>
                                    <?php endif; ?>
                                </h3>
                                
                                <?php if (!empty($low_stock_items)): ?>
                                    <table class="stock-table">
                                        <thead>
                                            <tr>
                                                <th>Item Name</th>
                                                <th>Category</th>
                                                <th>Stock Level</th>
                                                <th>Available</th>
                                                <th>Minimum</th>
                                                <th>Reorder Qty</th>
                                                <th>Unit</th>
                                                <th>Location</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($low_stock_items as $item): 
                                                $level_class = 'level-' . $item['stock_level'];
                                                
                                                $category_class = 'category-other';
                                                switch ($item['category']) {
                                                    case 'Firefighting': $category_class = 'category-firefighting'; break;
                                                    case 'Medical': $category_class = 'category-medical'; break;
                                                    case 'Rescue': $category_class = 'category-rescue'; break;
                                                    case 'PPE': $category_class = 'category-ppe'; break;
                                                    case 'Communication': $category_class = 'category-communication'; break;
                                                }
                                            ?>
                                                <tr>
                                                    <td>
                                                        <strong><?php echo htmlspecialchars($item['resource_name']); ?></strong>
                                                        <?php if (!empty($item['description'])): ?>
                                                            <br><small style="color: var(--text-light);"><?php echo substr(htmlspecialchars($item['description']), 0, 50) . '...'; ?></small>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td>
                                                        <span class="category-badge <?php echo $category_class; ?>">
                                                            <?php echo htmlspecialchars($item['category']); ?>
                                                        </span>
                                                    </td>
                                                    <td>
                                                        <span class="stock-level-badge <?php echo $level_class; ?>">
                                                            <?php echo ucfirst($item['stock_level']); ?>
                                                        </span>
                                                    </td>
                                                    <td>
                                                        <strong style="color: var(--danger);">
                                                            <?php echo $item['available_quantity']; ?>
                                                        </strong>
                                                        <br>
                                                        <small style="color: var(--text-light);">
                                                            of <?php echo $item['quantity']; ?> total
                                                        </small>
                                                    </td>
                                                    <td>
                                                        <?php echo $item['minimum_stock_level'] ?: 'N/A'; ?>
                                                    </td>
                                                    <td>
                                                        <?php if ($item['reorder_quantity']): ?>
                                                            <span style="color: var(--info); font-weight: 600;">
                                                                <?php echo $item['reorder_quantity']; ?>
                                                            </span>
                                                        <?php else: ?>
                                                            <span style="color: var(--text-light);">Not set</span>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td><?php echo htmlspecialchars($item['unit_name'] ?: 'Unassigned'); ?></td>
                                                    <td><?php echo htmlspecialchars($item['location'] ?: 'Not specified'); ?></td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                <?php else: ?>
                                    <div class="empty-state">
                                        <i class='bx bx-check-circle'></i>
                                        <h3>All Stock Levels Are Good</h3>
                                        <p>There are no items with low or critical stock levels.</p>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                        
                        <!-- Reorder Needed Tab -->
                        <div id="reorder" class="tab-content">
                            <div class="section-container">
                                <h3 class="section-title">
                                    <i class='bx bx-refresh'></i>
                                    Items Needing Reorder
                                    <?php if (!empty($reorder_items)): ?>
                                        <span class="badge badge-warning"><?php echo count($reorder_items); ?> items</span>
                                    <?php endif; ?>
                                </h3>
                                
                                <?php if (!empty($reorder_items)): ?>
                                    <table class="stock-table">
                                        <thead>
                                            <tr>
                                                <th>Item Name</th>
                                                <th>Category</th>
                                                <th>Current Stock</th>
                                                <th>Minimum Level</th>
                                                <th>Reorder Quantity</th>
                                                <th>Unit</th>
                                                <th>Location</th>
                                                <th>Status</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($reorder_items as $item): 
                                                $category_class = 'category-other';
                                                switch ($item['category']) {
                                                    case 'Firefighting': $category_class = 'category-firefighting'; break;
                                                    case 'Medical': $category_class = 'category-medical'; break;
                                                    case 'Rescue': $category_class = 'category-rescue'; break;
                                                    case 'PPE': $category_class = 'category-ppe'; break;
                                                    case 'Communication': $category_class = 'category-communication'; break;
                                                }
                                                
                                                $shortage = $item['minimum_stock_level'] - $item['available_quantity'];
                                            ?>
                                                <tr>
                                                    <td>
                                                        <strong><?php echo htmlspecialchars($item['resource_name']); ?></strong>
                                                        <?php if (!empty($item['description'])): ?>
                                                            <br><small style="color: var(--text-light);"><?php echo substr(htmlspecialchars($item['description']), 0, 50) . '...'; ?></small>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td>
                                                        <span class="category-badge <?php echo $category_class; ?>">
                                                            <?php echo htmlspecialchars($item['category']); ?>
                                                        </span>
                                                    </td>
                                                    <td>
                                                        <strong style="color: var(--danger);">
                                                            <?php echo $item['available_quantity']; ?>
                                                        </strong>
                                                        <br>
                                                        <small style="color: var(--text-light);">
                                                            Shortage: <?php echo $shortage; ?>
                                                        </small>
                                                    </td>
                                                    <td>
                                                        <?php echo $item['minimum_stock_level']; ?>
                                                    </td>
                                                    <td>
                                                        <span style="color: var(--info); font-weight: 600;">
                                                            <?php echo $item['reorder_quantity']; ?>
                                                        </span>
                                                    </td>
                                                    <td><?php echo htmlspecialchars($item['unit_name'] ?: 'Unassigned'); ?></td>
                                                    <td><?php echo htmlspecialchars($item['location'] ?: 'Not specified'); ?></td>
                                                    <td>
                                                        <span class="stock-level-badge level-critical">
                                                            Reorder Now
                                                        </span>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                <?php else: ?>
                                    <div class="empty-state">
                                        <i class='bx bx-check-circle'></i>
                                        <h3>No Reorders Needed</h3>
                                        <p>All items are above their minimum stock levels.</p>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Stock Chart (Placeholder) -->
                    <div class="section-container">
                        <h3 class="section-title">
                            <i class='bx bx-bar-chart-alt'></i>
                            Stock Level Distribution
                        </h3>
                        
                        <div class="chart-container">
                            <div class="chart-placeholder">
                                <div style="text-align: center;">
                                    <i class='bx bx-bar-chart-alt' style="font-size: 48px; opacity: 0.3; margin-bottom: 20px;"></i>
                                    <p>Stock level chart visualization would appear here.</p>
                                    <p style="font-size: 12px; margin-top: 10px;">Showing: Critical (<?php echo $critical_items; ?>) • 
                                        Low (<?php echo $low_items; ?>) • Medium (<?php echo $medium_items; ?>) • 
                                        Good (<?php echo $good_items; ?>)</p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            initEventListeners();
            updateTime();
            setInterval(updateTime, 1000);
        });
        
        function initEventListeners() {
            // Theme toggle
            const themeToggle = document.getElementById('theme-toggle');
            const themeIcon = themeToggle.querySelector('i');
            const themeText = themeToggle.querySelector('span');
            
            if (themeToggle) {
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
            }
            
            // User profile dropdown
            const userProfile = document.getElementById('user-profile');
            const userDropdown = document.getElementById('user-dropdown');
            
            if (userProfile && userDropdown) {
                userProfile.addEventListener('click', function(e) {
                    e.stopPropagation();
                    userDropdown.classList.toggle('show');
                });
            }
            
            // Close dropdowns when clicking outside
            document.addEventListener('click', function() {
                if (userDropdown) {
                    userDropdown.classList.remove('show');
                }
            });
            
            // Auto-submit filters on change
            const categoryFilter = document.querySelector('select[name="category"]');
            const levelFilter = document.querySelector('select[name="level"]');
            const searchInput = document.querySelector('input[name="search"]');
            
            if (categoryFilter) {
                categoryFilter.addEventListener('change', function() {
                    if (!searchInput.value && levelFilter.value === 'all') {
                        document.getElementById('filter-form').submit();
                    }
                });
            }
            
            if (levelFilter) {
                levelFilter.addEventListener('change', function() {
                    if (!searchInput.value && categoryFilter.value === 'all') {
                        document.getElementById('filter-form').submit();
                    }
                });
            }
            
            // Search functionality
            const globalSearch = document.getElementById('search-input');
            if (globalSearch) {
                globalSearch.addEventListener('keyup', function(e) {
                    if (e.key === 'Enter') {
                        const searchTerm = this.value.toLowerCase();
                        const currentTab = document.querySelector('.tab-content.active').id.replace('-stock', '');
                        filterTable(searchTerm, currentTab);
                    }
                });
            }
        }
        
        function updateTime() {
            const now = new Date();
            const utc = now.getTime() + (now.getTimezoneOffset() * 60000);
            const gmt8 = new Date(utc + (8 * 3600000));
            
            const hours = gmt8.getHours().toString().padStart(2, '0');
            const minutes = gmt8.getMinutes().toString().padStart(2, '0');
            const seconds = gmt8.getSeconds().toString().padStart(2, '0');
            
            const timeString = `${hours}:${minutes}:${seconds} UTC+8`;
            const timeDisplay = document.getElementById('current-time');
            if (timeDisplay) {
                timeDisplay.textContent = timeString;
            }
        }
        
        function toggleSubmenu(id) {
            const submenu = document.getElementById(id);
            const arrow = submenu.previousElementSibling.querySelector('.dropdown-arrow');
            
            submenu.classList.toggle('active');
            arrow.classList.toggle('rotated');
        }
        
        function switchTab(tabId) {
            // Hide all tabs
            const tabs = document.querySelectorAll('.tab-content');
            tabs.forEach(tab => tab.classList.remove('active'));
            
            // Deactivate all tab buttons
            const tabButtons = document.querySelectorAll('.tab-button');
            tabButtons.forEach(button => button.classList.remove('active'));
            
            // Show selected tab
            document.getElementById(tabId).classList.add('active');
            
            // Activate clicked button
            event.target.classList.add('active');
        }
        
        function filterTable(searchTerm, tableType) {
            const table = document.querySelector(`#${tableType}-stock .stock-table`);
            if (!table) return;
            
            const rows = table.getElementsByTagName('tr');
            
            for (let i = 1; i < rows.length; i++) {
                const cells = rows[i].getElementsByTagName('td');
                let match = false;
                
                for (let j = 0; j < cells.length; j++) {
                    if (cells[j].textContent.toLowerCase().includes(searchTerm)) {
                        match = true;
                        break;
                    }
                }
                
                rows[i].style.display = match ? '' : 'none';
            }
        }
        
        // Initialize chart visualization (simplified)
        function initStockChart() {
            const critical = <?php echo $critical_items; ?>;
            const low = <?php echo $low_items; ?>;
            const medium = <?php echo $medium_items; ?>;
            const good = <?php echo $good_items; ?>;
            
            // This would be replaced with actual chart library code
            console.log('Stock levels:', {critical, low, medium, good});
        }
        
        // Call chart initialization
        initStockChart();
    </script>
</body>
</html>