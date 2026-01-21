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

// Handle status update via AJAX
if (isset($_POST['update_resource_status'])) {
    $resource_id = $_POST['resource_id'];
    $new_status = $_POST['new_status'];
    $notes = $_POST['notes'] ?? '';
    
    // Update the resource status
    $update_query = "UPDATE resources SET condition_status = ?, updated_at = NOW() WHERE id = ?";
    $update_stmt = $pdo->prepare($update_query);
    
    if ($update_stmt->execute([$new_status, $resource_id])) {
        // Log the status change (you can create a separate table for status change logs if needed)
        $log_query = "INSERT INTO maintenance_requests (resource_id, requested_by, request_type, priority, description, status, requested_date) 
                      VALUES (?, ?, 'repair', 'medium', ?, 'completed', NOW())";
        $log_stmt = $pdo->prepare($log_query);
        $log_stmt->execute([$resource_id, $user_id, "Status changed to: $new_status. Notes: $notes"]);
        
        echo json_encode(['success' => true, 'message' => 'Status updated successfully']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Failed to update status']);
    }
    exit();
}

// API Configuration
$api_url = 'https://ers.jampzdev.com/api/staff/Sub3/Resources.php';

// Function to fetch resources from API
function fetchResourcesFromAPI() {
    global $api_url, $pdo;
    
    try {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $api_url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        
        $response = curl_exec($ch);
        
        if (curl_errno($ch)) {
            error_log("CURL Error: " . curl_error($ch));
            return ['success' => false, 'message' => 'Failed to connect to API'];
        }
        
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($http_code !== 200) {
            error_log("API returned HTTP code: " . $http_code);
            return ['success' => false, 'message' => 'API returned error code: ' . $http_code];
        }
        
        $data = json_decode($response, true);
        
        if (!$data || !isset($data['success']) || !$data['success']) {
            error_log("API returned invalid data");
            return ['success' => false, 'message' => 'Invalid API response'];
        }
        
        // Process and store in database
        if (isset($data['data']) && is_array($data['data'])) {
            processAndStoreResources($data['data']);
        }
        
        return ['success' => true, 'data' => $data['data'] ?? []];
        
    } catch (Exception $e) {
        error_log("Exception in fetchResourcesFromAPI: " . $e->getMessage());
        return ['success' => false, 'message' => 'Error fetching resources: ' . $e->getMessage()];
    }
}

// Process and store resources in database
function processAndStoreResources($resources) {
    global $pdo;
    
    foreach ($resources as $resource) {
        // Determine resource type and category
        $resource_type = determineResourceType($resource['vehicle_type'] ?? '', $resource['emergency_type'] ?? '');
        $category = determineCategory($resource['emergency_type'] ?? '', $resource['equipment_list'] ?? []);
        
        // Extract equipment list
        $equipment_list = [];
        if (isset($resource['equipment_list']) && is_array($resource['equipment_list'])) {
            foreach ($resource['equipment_list'] as $item) {
                $equipment_list[] = [
                    'name' => $item,
                    'is_mandatory' => in_array($item, $resource['mandatory_items'] ?? []),
                    'is_recommended' => in_array($item, $resource['recommended_items'] ?? [])
                ];
            }
        }
        
        // Check if resource already exists
        $check_query = "SELECT id FROM resources WHERE external_id = ?";
        $check_stmt = $pdo->prepare($check_query);
        $check_stmt->execute([$resource['id']]);
        $existing = $check_stmt->fetch();
        
        if ($existing) {
            // Update existing resource
            $update_query = "
                UPDATE resources SET
                    resource_name = ?,
                    resource_type = ?,
                    category = ?,
                    description = ?,
                    quantity = ?,
                    condition_status = 'Serviceable',
                    updated_at = NOW(),
                    sync_status = 'synced',
                    last_sync_at = NOW()
                WHERE external_id = ?
            ";
            
            $update_stmt = $pdo->prepare($update_query);
            $update_stmt->execute([
                $resource['profile_name'] ?? 'Unnamed Resource',
                $resource_type,
                $category,
                json_encode([
                    'equipment_list' => $equipment_list,
                    'mandatory_items' => $resource['mandatory_items'] ?? [],
                    'recommended_items' => $resource['recommended_items'] ?? [],
                    'stats' => $resource['stats'] ?? []
                ]),
                count($equipment_list),
                $resource['id']
            ]);
        } else {
            // Insert new resource
            $insert_query = "
                INSERT INTO resources (
                    external_id,
                    resource_name,
                    resource_type,
                    category,
                    description,
                    quantity,
                    available_quantity,
                    condition_status,
                    is_active,
                    sync_status,
                    last_sync_at,
                    created_at,
                    updated_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, 'Serviceable', 1, 'synced', NOW(), NOW(), NOW())
            ";
            
            $insert_stmt = $pdo->prepare($insert_query);
            $insert_stmt->execute([
                $resource['id'],
                $resource['profile_name'] ?? 'Unnamed Resource',
                $resource_type,
                $category,
                json_encode([
                    'equipment_list' => $equipment_list,
                    'mandatory_items' => $resource['mandatory_items'] ?? [],
                    'recommended_items' => $resource['recommended_items'] ?? [],
                    'stats' => $resource['stats'] ?? []
                ]),
                count($equipment_list),
                count($equipment_list)
            ]);
        }
    }
}

function determineResourceType($vehicle_type, $emergency_type) {
    $vehicle_type = strtolower($vehicle_type);
    $emergency_type = strtolower($emergency_type);
    
    if (strpos($vehicle_type, 'fire') !== false || strpos($emergency_type, 'fire') !== false) {
        return 'Vehicle';
    } elseif (strpos($vehicle_type, 'ambulance') !== false || strpos($emergency_type, 'medical') !== false) {
        return 'Vehicle';
    } elseif (strpos($vehicle_type, 'rescue') !== false || $emergency_type === 'rescue') {
        return 'Vehicle';
    }
    
    return 'Other';
}

function determineCategory($emergency_type, $equipment_list) {
    $emergency_type = strtolower($emergency_type);
    
    if ($emergency_type === 'fire') {
        return 'Firefighting';
    } elseif ($emergency_type === 'medical') {
        return 'Medical';
    } elseif ($emergency_type === 'rescue') {
        // Check equipment list for specific items
        foreach ($equipment_list as $item) {
            $item_lower = strtolower($item);
            if (strpos($item_lower, 'rope') !== false || 
                strpos($item_lower, 'harness') !== false || 
                strpos($item_lower, 'rescue') !== false) {
                return 'Rescue';
            }
        }
        return 'Rescue';
    }
    
    return 'Other';
}

// Fetch resources from database
$resources = [];
$total_resources = 0;
$stats = [
    'total' => 0,
    'serviceable' => 0,
    'maintenance' => 0,
    'condemned' => 0
];

// Get filter parameters
$category_filter = isset($_GET['category']) ? $_GET['category'] : 'all';
$status_filter = isset($_GET['status']) ? $_GET['status'] : 'all';
$search_term = isset($_GET['search']) ? $_GET['search'] : '';

// Build query with filters
$where_conditions = [];
$params = [];

if ($category_filter !== 'all') {
    $where_conditions[] = "category = ?";
    $params[] = $category_filter;
}

if ($status_filter !== 'all') {
    $where_conditions[] = "condition_status = ?";
    $params[] = $status_filter;
}

if (!empty($search_term)) {
    $where_conditions[] = "(resource_name LIKE ? OR description LIKE ?)";
    $params[] = "%$search_term%";
    $params[] = "%$search_term%";
}

$where_clause = '';
if (!empty($where_conditions)) {
    $where_clause = "WHERE " . implode(" AND ", $where_conditions);
}

// Fetch resources from database
$resources_query = "
    SELECT * FROM resources 
    $where_clause 
    ORDER BY resource_type, category, resource_name
";

$resources_stmt = $pdo->prepare($resources_query);
$resources_stmt->execute($params);
$resources = $resources_stmt->fetchAll();

// Get stats
$stats_query = "
    SELECT 
        COUNT(*) as total,
        SUM(CASE WHEN condition_status = 'Serviceable' THEN 1 ELSE 0 END) as serviceable,
        SUM(CASE WHEN condition_status = 'Under Maintenance' THEN 1 ELSE 0 END) as maintenance,
        SUM(CASE WHEN condition_status = 'Condemned' THEN 1 ELSE 0 END) as condemned
    FROM resources
";

$stats_stmt = $pdo->prepare($stats_query);
$stats_stmt->execute();
$stats = $stats_stmt->fetch();

// Fix: Get total count without filters for the footer
$total_resources = $stats['total'] ?? 0;

// Get all categories for filter
$categories_query = "SELECT DISTINCT category FROM resources WHERE category IS NOT NULL ORDER BY category";
$categories_stmt = $pdo->prepare($categories_query);
$categories_stmt->execute();
$all_categories = $categories_stmt->fetchAll();

// Handle AJAX requests for fetching from API
if (isset($_GET['ajax']) && $_GET['ajax'] === 'true') {
    if (isset($_GET['sync_resources'])) {
        $result = fetchResourcesFromAPI();
        echo json_encode($result);
        exit();
    }
    
    if (isset($_GET['get_resource_details'])) {
        $resource_id = $_GET['id'];
        $query = "SELECT * FROM resources WHERE id = ?";
        $stmt = $pdo->prepare($query);
        $stmt->execute([$resource_id]);
        $resource = $stmt->fetch();
        
        if ($resource) {
            $description = json_decode($resource['description'], true);
            echo json_encode([
                'success' => true,
                'data' => $resource,
                'parsed_description' => $description
            ]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Resource not found']);
        }
        exit();
    }
}

$stmt = null;
$resources_stmt = null;
$stats_stmt = null;
$categories_stmt = null;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>View Equipment - Fire & Rescue Services</title>
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
            overflow-x: hidden;
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

        .resources-container {
            display: flex;
            flex-direction: column;
            gap: 24px;
            padding: 0 40px 40px;
        }
        
        .resources-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 24px;
        }
        
        .resources-title {
            font-size: 28px;
            font-weight: 800;
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            margin-bottom: 8px;
        }
        
        .resources-subtitle {
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
        
        .stat-card[data-status="serviceable"]::before {
            background: var(--success);
        }
        
        .stat-card[data-status="maintenance"]::before {
            background: var(--warning);
        }
        
        .stat-card[data-status="condemned"]::before {
            background: var(--danger);
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
        
        .stat-card[data-status="serviceable"] .stat-icon {
            background: rgba(16, 185, 129, 0.1);
            color: var(--success);
        }
        
        .stat-card[data-status="maintenance"] .stat-icon {
            background: rgba(245, 158, 11, 0.1);
            color: var(--warning);
        }
        
        .stat-card[data-status="condemned"] .stat-icon {
            background: rgba(220, 38, 38, 0.1);
            color: var(--danger);
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
        
        .resources-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 20px;
            margin-bottom: 24px;
        }
        
        .resource-card {
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
            border-radius: 16px;
            overflow: hidden;
            transition: all 0.3s ease;
        }
        
        .resource-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.15);
        }
        
        .resource-header {
            padding: 20px;
            border-bottom: 1px solid var(--border-color);
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        
        .resource-title {
            font-size: 18px;
            font-weight: 700;
            margin-bottom: 4px;
        }
        
        .resource-type {
            font-size: 12px;
            color: var(--text-light);
            background: var(--gray-100);
            padding: 4px 8px;
            border-radius: 4px;
            display: inline-block;
        }
        
        .dark-mode .resource-type {
            background: var(--gray-800);
        }
        
        .resource-body {
            padding: 20px;
        }
        
        .resource-info {
            display: flex;
            flex-direction: column;
            gap: 12px;
            margin-bottom: 16px;
        }
        
        .info-item {
            display: flex;
            justify-content: space-between;
        }
        
        .info-label {
            color: var(--text-light);
            font-size: 13px;
        }
        
        .info-value {
            font-weight: 600;
        }
        
        .status-badge {
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            text-transform: uppercase;
            display: inline-block;
            text-align: center;
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
        
        .category-badge {
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 11px;
            font-weight: 600;
            display: inline-block;
        }
        
        .category-firefighting {
            background: rgba(239, 68, 68, 0.1);
            color: var(--icon-red);
        }
        
        .category-medical {
            background: rgba(59, 130, 246, 0.1);
            color: var(--icon-blue);
        }
        
        .category-rescue {
            background: rgba(245, 158, 11, 0.1);
            color: var(--icon-yellow);
        }
        
        .category-ppe {
            background: rgba(139, 92, 246, 0.1);
            color: var(--icon-purple);
        }
        
        .category-other {
            background: rgba(107, 114, 128, 0.1);
            color: var(--gray-500);
        }
        
        .equipment-list {
            margin-top: 16px;
        }
        
        .equipment-title {
            font-size: 14px;
            font-weight: 600;
            margin-bottom: 8px;
            color: var(--text-light);
        }
        
        .equipment-items {
            max-height: 150px;
            overflow-y: auto;
            padding-right: 8px;
        }
        
        .equipment-item {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 6px 0;
            border-bottom: 1px solid var(--border-color);
        }
        
        .equipment-item:last-child {
            border-bottom: none;
        }
        
        .equipment-name {
            flex: 1;
            font-size: 13px;
        }
        
        .mandatory-badge {
            font-size: 10px;
            padding: 2px 6px;
            border-radius: 3px;
            background: rgba(239, 68, 68, 0.1);
            color: var(--icon-red);
        }
        
        .recommended-badge {
            font-size: 10px;
            padding: 2px 6px;
            border-radius: 3px;
            background: rgba(59, 130, 246, 0.1);
            color: var(--icon-blue);
        }
        
        .resource-footer {
            padding: 16px 20px;
            border-top: 1px solid var(--border-color);
            display: flex;
            justify-content: space-between;
            align-items: center;
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
        
        .update-button {
            background-color: rgba(16, 185, 129, 0.1);
            color: var(--success);
        }
        
        .update-button:hover {
            background-color: var(--success);
            color: white;
        }
        
        .no-resources {
            text-align: center;
            padding: 60px 20px;
            color: var(--text-light);
            grid-column: 1 / -1;
        }
        
        .no-resources-icon {
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
            max-width: 800px;
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
        
        .equipment-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 12px;
        }
        
        .equipment-table th {
            padding: 12px;
            text-align: left;
            background: var(--gray-100);
            color: var(--text-color);
            font-weight: 600;
            font-size: 13px;
        }
        
        .dark-mode .equipment-table th {
            background: var(--gray-800);
        }
        
        .equipment-table td {
            padding: 12px;
            border-bottom: 1px solid var(--border-color);
        }
        
        .equipment-table tr:last-child td {
            border-bottom: none;
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
        
        .modal-primary {
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            color: white;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .modal-primary:hover {
            background: linear-gradient(135deg, var(--primary-dark), var(--primary-color));
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
        
        .alert-warning {
            background: rgba(245, 158, 11, 0.1);
            border: 1px solid rgba(245, 158, 11, 0.2);
            color: var(--warning);
        }
        
        .alert i {
            font-size: 20px;
        }
        
        /* Loading overlay for sync */
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
            border: 4px solid var(--gray-200);
            border-top-color: var(--primary-color);
            border-radius: 50%;
            animation: spin 1s linear infinite;
            margin-bottom: 20px;
        }
        
        @keyframes spin {
            to { transform: rotate(360deg); }
        }
        
        .loading-text {
            color: white;
            font-size: 16px;
        }
        
        /* Table Footer */
        .table-footer {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 15px 0;
            border-top: 1px solid var(--border-color);
            margin-top: 20px;
        }
        
        .records-per-page {
            font-size: 14px;
            color: var(--text-light);
        }
        
        /* Header dropdown fixes */
        .header {
            top: 0;
            z-index: 100;
            background: var(--card-bg);
            border-bottom: 1px solid var(--border-color);
        }
        
        .notification-dropdown {
            position: absolute;
            top: 100%;
            right: 0;
            width: 350px;
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: 12px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.15);
            margin-top: 10px;
            opacity: 0;
            visibility: hidden;
            transform: translateY(-10px);
            transition: all 0.3s ease;
            z-index: 1000;
        }
        
        .notification-dropdown.show {
            opacity: 1;
            visibility: visible;
            transform: translateY(0);
        }
        
        .notification-header {
            padding: 16px 20px;
            border-bottom: 1px solid var(--border-color);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .notification-title {
            font-size: 16px;
            font-weight: 600;
            color: var(--text-color);
            margin: 0;
        }
        
        .notification-clear {
            background: none;
            border: none;
            color: var(--primary-color);
            font-size: 13px;
            cursor: pointer;
            font-weight: 500;
            padding: 4px 8px;
            border-radius: 6px;
            transition: all 0.2s;
        }
        
        .notification-clear:hover {
            background: rgba(220, 38, 38, 0.1);
        }
        
        .notification-list {
            max-height: 300px;
            overflow-y: auto;
        }
        
        .notification-item {
            padding: 16px 20px;
            display: flex;
            gap: 12px;
            align-items: flex-start;
            border-bottom: 1px solid var(--border-color);
            transition: all 0.2s;
            cursor: pointer;
        }
        
        .notification-item:hover {
            background: var(--gray-100);
        }
        
        .dark-mode .notification-item:hover {
            background: var(--gray-800);
        }
        
        .notification-item.unread {
            background: rgba(59, 130, 246, 0.05);
        }
        
        .dark-mode .notification-item.unread {
            background: rgba(59, 130, 246, 0.1);
        }
        
        .notification-item.unread:hover {
            background: rgba(59, 130, 246, 0.1);
        }
        
        .dark-mode .notification-item.unread:hover {
            background: rgba(59, 130, 246, 0.15);
        }
        
        .notification-item-icon {
            font-size: 20px;
            margin-top: 2px;
            flex-shrink: 0;
        }
        
        .notification-item-content {
            flex: 1;
        }
        
        .notification-item-title {
            font-weight: 600;
            font-size: 14px;
            color: var(--text-color);
            margin-bottom: 4px;
        }
        
        .notification-item-message {
            font-size: 13px;
            color: var(--text-light);
            margin-bottom: 4px;
            line-height: 1.4;
        }
        
        .notification-item-time {
            font-size: 11px;
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
        
        .notification-empty p {
            margin: 0;
            font-size: 14px;
        }
        
        .notification-bell {
            position: relative;
        }
        
        .notification-badge {
            position: absolute;
            top: -6px;
            right: -6px;
            background: var(--primary-color);
            color: white;
            font-size: 10px;
            font-weight: 600;
            min-width: 16px;
            height: 16px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 0 4px;
        }
        
        .user-profile-dropdown {
            position: absolute;
            top: 100%;
            right: 0;
            width: 200px;
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: 12px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.15);
            margin-top: 10px;
            opacity: 0;
            visibility: hidden;
            transform: translateY(-10px);
            transition: all 0.3s ease;
            z-index: 1000;
        }
        
        .user-profile-dropdown.show {
            opacity: 1;
            visibility: visible;
            transform: translateY(0);
        }
        
        .user-profile {
            position: relative;
            cursor: pointer;
        }
        
        .dropdown-item {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 12px 16px;
            color: var(--text-color);
            text-decoration: none;
            transition: all 0.2s;
            border-bottom: 1px solid var(--border-color);
        }
        
        .dropdown-item:last-child {
            border-bottom: none;
        }
        
        .dropdown-item:hover {
            background: var(--gray-100);
        }
        
        .dark-mode .dropdown-item:hover {
            background: var(--gray-800);
        }
        
        .dropdown-item i {
            font-size: 18px;
            width: 20px;
            text-align: center;
        }
        
        .dropdown-divider {
            height: 1px;
            background: var(--border-color);
            margin: 8px 0;
        }
        
        /* Update Status Modal */
        .status-modal {
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.2);
            border-radius: 20px;
            width: 90%;
            max-width: 500px;
            max-height: 90vh;
            overflow-y: auto;
            transform: scale(0.9);
            transition: all 0.3s ease;
        }
        
        .status-options {
            display: flex;
            flex-direction: column;
            gap: 12px;
            margin: 20px 0;
        }
        
        .status-option {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 16px;
            border: 2px solid var(--border-color);
            border-radius: 12px;
            cursor: pointer;
            transition: all 0.3s ease;
        }
        
        .status-option:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
        }
        
        .status-option.selected {
            border-color: var(--primary-color);
            background: rgba(220, 38, 38, 0.05);
        }
        
        .status-option-icon {
            width: 40px;
            height: 40px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
            flex-shrink: 0;
        }
        
        .status-option-content {
            flex: 1;
        }
        
        .status-option-title {
            font-weight: 600;
            font-size: 15px;
            margin-bottom: 4px;
            color: var(--text-color);
        }
        
        .status-option-description {
            font-size: 13px;
            color: var(--text-light);
            line-height: 1.4;
        }
        
        .status-notes {
            margin-top: 20px;
        }
        
        .status-notes textarea {
            width: 100%;
            padding: 12px;
            border: 1px solid var(--border-color);
            border-radius: 8px;
            background: var(--card-bg);
            color: var(--text-color);
            font-size: 14px;
            resize: vertical;
            min-height: 80px;
        }
        
        .status-notes textarea:focus {
            outline: none;
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(220, 38, 38, 0.1);
        }
        
        /* Responsive */
        @media (max-width: 768px) {
            .dashboard-header {
                padding: 40px 25px 30px;
            }
            
            .dashboard-title {
                font-size: 32px;
            }
            
            .resources-container {
                padding: 0 25px 30px;
            }
            
            .stats-container {
                grid-template-columns: repeat(2, 1fr);
            }
            
            .resources-grid {
                grid-template-columns: 1fr;
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
            
            .notification-dropdown {
                width: 300px;
                right: -50px;
            }
        }
        
        @media (max-width: 480px) {
            .stats-container {
                grid-template-columns: 1fr;
            }
            
            .action-buttons {
                flex-direction: column;
                align-items: flex-start;
            }
            
            .modal-footer {
                flex-direction: column;
            }
            
            .notification-dropdown {
                width: 280px;
                right: -100px;
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
        <div class="animation-text" id="animation-text">Loading Equipment Resources...</div>
    </div>
    
    <!-- Sync Loading Overlay -->
    <div class="loading-overlay" id="loading-overlay">
        <div class="loading-spinner"></div>
        <div class="loading-text" id="loading-text">Syncing with External API...</div>
    </div>
    
    <!-- Resource Details Modal -->
    <div class="modal-overlay" id="resource-modal">
        <div class="modal">
            <div class="modal-header">
                <h2 class="modal-title">Resource Details</h2>
                <button class="modal-close" id="modal-close">&times;</button>
            </div>
            <div class="modal-body" id="modal-body">
                <!-- Content will be loaded via JavaScript -->
            </div>
            <div class="modal-footer">
                <button class="modal-button modal-secondary" id="modal-close-btn">Close</button>
                <button class="modal-button modal-primary" id="modal-update-status-btn">
                    <i class='bx bx-refresh'></i>
                    Update Status
                </button>
            </div>
        </div>
    </div>
    
    <!-- Update Status Modal -->
    <div class="modal-overlay" id="status-modal-overlay">
        <div class="status-modal">
            <div class="modal-header">
                <h2 class="modal-title">Update Resource Status</h2>
                <button class="modal-close" id="status-modal-close">&times;</button>
            </div>
            <div class="modal-body">
                <div class="modal-section">
                    <h3 class="modal-section-title">
                        <i class='bx bxs-cube'></i> Current Resource
                    </h3>
                    <div class="modal-detail">
                        <div class="modal-detail-label">Resource Name</div>
                        <div class="modal-detail-value" id="status-resource-name">Loading...</div>
                    </div>
                    <div class="modal-detail">
                        <div class="modal-detail-label">Current Status</div>
                        <div class="modal-detail-value" id="status-current-status">Loading...</div>
                    </div>
                </div>
                
                <div class="modal-section">
                    <h3 class="modal-section-title">
                        <i class='bx bx-transfer-alt'></i> Select New Status
                    </h3>
                    <div class="status-options">
                        <div class="status-option" data-status="Serviceable">
                            <div class="status-option-icon" style="background: rgba(16, 185, 129, 0.1); color: var(--success);">
                                <i class='bx bx-check-circle'></i>
                            </div>
                            <div class="status-option-content">
                                <div class="status-option-title">Serviceable</div>
                                <div class="status-option-description">Equipment is in good working condition and ready for deployment.</div>
                            </div>
                        </div>
                        <div class="status-option" data-status="Under Maintenance">
                            <div class="status-option-icon" style="background: rgba(245, 158, 11, 0.1); color: var(--warning);">
                                <i class='bx bx-wrench'></i>
                            </div>
                            <div class="status-option-content">
                                <div class="status-option-title">Under Maintenance</div>
                                <div class="status-option-description">Equipment is currently being repaired or serviced. Not available for deployment.</div>
                            </div>
                        </div>
                        <div class="status-option" data-status="Condemned">
                            <div class="status-option-icon" style="background: rgba(220, 38, 38, 0.1); color: var(--danger);">
                                <i class='bx bx-x-circle'></i>
                            </div>
                            <div class="status-option-content">
                                <div class="status-option-title">Condemned</div>
                                <div class="status-option-description">Equipment is no longer functional and cannot be repaired. Should be removed from inventory.</div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="modal-section">
                    <h3 class="modal-section-title">
                        <i class='bx bx-note'></i> Additional Notes
                    </h3>
                    <div class="status-notes">
                        <textarea id="status-notes" placeholder="Enter any additional notes about this status change (optional)"></textarea>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button class="modal-button modal-secondary" id="status-cancel-btn">Cancel</button>
                <button class="modal-button modal-primary" id="status-save-btn">
                    <i class='bx bx-save'></i>
                    Update Status
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
                        <a href="view_equipment.php" class="submenu-item active">View Equipment</a>
                        <a href="approve_maintenance.php" class="submenu-item">Approve Maintenance</a>
                         <a href="review_deployment.php" class="submenu-item">Review Deployment</a>
                        <a href="reports_analytics.php" class="submenu-item">Reports & Analytics</a>
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
                            <input type="text" placeholder="Search equipment resources..." class="search-input" id="search-input" value="<?php echo htmlspecialchars($search_term); ?>">
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
                            <div class="notification-badge" id="notification-count">2</div>
                            <div class="notification-dropdown" id="notification-dropdown">
                                <div class="notification-header">
                                    <h3 class="notification-title">Notifications</h3>
                                    <button class="notification-clear">Clear All</button>
                                </div>
                                <div class="notification-list" id="notification-list">
                                    <div class="notification-item unread">
                                        <i class='bx bxs-cube notification-item-icon' style="color: var(--warning);"></i>
                                        <div class="notification-item-content">
                                            <div class="notification-item-title">Equipment Maintenance Due</div>
                                            <div class="notification-item-message">Fire Truck 1 requires maintenance inspection</div>
                                            <div class="notification-item-time">1 hour ago</div>
                                        </div>
                                    </div>
                                    <div class="notification-item unread">
                                        <i class='bx bxs-bell-ring notification-item-icon' style="color: var(--info);"></i>
                                        <div class="notification-item-content">
                                            <div class="notification-item-title">New Resource Added</div>
                                            <div class="notification-item-message">New equipment profile synced from ER system</div>
                                            <div class="notification-item-time">2 hours ago</div>
                                        </div>
                                    </div>
                                    <div class="notification-item">
                                        <i class='bx bxs-check-circle notification-item-icon' style="color: var(--success);"></i>
                                        <div class="notification-item-content">
                                            <div class="notification-item-title">Sync Completed</div>
                                            <div class="notification-item-message">Equipment resources successfully synced</div>
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
                <?php if (isset($_GET['sync_success'])): ?>
                    <div class="alert alert-success">
                        <i class='bx bx-check-circle'></i>
                        <div>
                            <strong>Success!</strong> Equipment resources have been successfully synced with the external API.
                        </div>
                    </div>
                <?php endif; ?>
                
                <?php if (isset($_GET['sync_error'])): ?>
                    <div class="alert alert-warning">
                        <i class='bx bx-error-circle'></i>
                        <div>
                            <strong>Sync Error!</strong> <?php echo htmlspecialchars($_GET['sync_error']); ?>
                        </div>
                    </div>
                <?php endif; ?>
                
                <div class="dashboard-header">
                    <div>
                        <h1 class="dashboard-title">Equipment Resources</h1>
                        <p class="dashboard-subtitle">View all equipment, vehicles, tools, and supplies with real-time sync from ER system</p>
                    </div>
                    <div class="dashboard-actions">
                        <button class="primary-button" id="sync-button">
                            <i class='bx bx-refresh'></i>
                            Sync with ER System
                        </button>
                        <button class="secondary-button" id="refresh-button">
                            <i class='bx bx-repost'></i>
                            Refresh List
                        </button>
                    </div>
                </div>
                
                <!-- Resources Section -->
                <div class="resources-container">
                    <!-- Stats Cards -->
                    <div class="stats-container">
                        <div class="stat-card <?php echo $status_filter === 'all' ? 'active' : ''; ?>" data-status="all">
                            <div class="stat-icon">
                                <i class='bx bxs-cube'></i>
                            </div>
                            <div class="stat-value"><?php echo $stats['total']; ?></div>
                            <div class="stat-label">Total Resources</div>
                        </div>
                        <div class="stat-card <?php echo $status_filter === 'serviceable' ? 'active' : ''; ?>" data-status="serviceable">
                            <div class="stat-icon">
                                <i class='bx bx-check-circle'></i>
                            </div>
                            <div class="stat-value"><?php echo $stats['serviceable']; ?></div>
                            <div class="stat-label">Serviceable</div>
                        </div>
                        <div class="stat-card <?php echo $status_filter === 'maintenance' ? 'active' : ''; ?>" data-status="maintenance">
                            <div class="stat-icon">
                                <i class='bx bx-wrench'></i>
                            </div>
                            <div class="stat-value"><?php echo $stats['maintenance']; ?></div>
                            <div class="stat-label">Under Maintenance</div>
                        </div>
                        <div class="stat-card <?php echo $status_filter === 'condemned' ? 'active' : ''; ?>" data-status="condemned">
                            <div class="stat-icon">
                                <i class='bx bx-x-circle'></i>
                            </div>
                            <div class="stat-value"><?php echo $stats['condemned']; ?></div>
                            <div class="stat-label">Condemned</div>
                        </div>
                    </div>
                    
                    <!-- Enhanced Filters -->
                    <div class="filters-container">
                        <div class="filter-group">
                            <label class="filter-label">Category</label>
                            <select class="filter-select" id="category-filter">
                                <option value="all" <?php echo $category_filter === 'all' ? 'selected' : ''; ?>>All Categories</option>
                                <option value="Firefighting" <?php echo $category_filter === 'Firefighting' ? 'selected' : ''; ?>>Firefighting</option>
                                <option value="Medical" <?php echo $category_filter === 'Medical' ? 'selected' : ''; ?>>Medical</option>
                                <option value="Rescue" <?php echo $category_filter === 'Rescue' ? 'selected' : ''; ?>>Rescue</option>
                                <option value="PPE" <?php echo $category_filter === 'PPE' ? 'selected' : ''; ?>>PPE</option>
                                <option value="Other" <?php echo $category_filter === 'Other' ? 'selected' : ''; ?>>Other</option>
                            </select>
                        </div>
                        <div class="filter-group">
                            <label class="filter-label">Status</label>
                            <select class="filter-select" id="status-filter">
                                <option value="all" <?php echo $status_filter === 'all' ? 'selected' : ''; ?>>All Statuses</option>
                                <option value="Serviceable" <?php echo $status_filter === 'Serviceable' ? 'selected' : ''; ?>>Serviceable</option>
                                <option value="Under Maintenance" <?php echo $status_filter === 'Under Maintenance' ? 'selected' : ''; ?>>Under Maintenance</option>
                                <option value="Condemned" <?php echo $status_filter === 'Condemned' ? 'selected' : ''; ?>>Condemned</option>
                            </select>
                        </div>
                        <div class="filter-group">
                            <label class="filter-label">Search</label>
                            <input type="text" class="filter-input" id="search-filter" placeholder="Search by name, description..." value="<?php echo htmlspecialchars($search_term); ?>">
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
                    
                    <!-- Resources Grid -->
                    <div class="resources-grid">
                        <?php if (count($resources) > 0): ?>
                            <?php foreach ($resources as $resource): 
                                $description = json_decode($resource['description'], true);
                                $equipment_list = $description['equipment_list'] ?? [];
                                $mandatory_items = $description['mandatory_items'] ?? [];
                                $recommended_items = $description['recommended_items'] ?? [];
                                $stats = $description['stats'] ?? [];
                                
                                // Determine category badge
                                $category_badge = '';
                                switch ($resource['category']) {
                                    case 'Firefighting':
                                        $category_badge = '<span class="category-badge category-firefighting">Firefighting</span>';
                                        break;
                                    case 'Medical':
                                        $category_badge = '<span class="category-badge category-medical">Medical</span>';
                                        break;
                                    case 'Rescue':
                                        $category_badge = '<span class="category-badge category-rescue">Rescue</span>';
                                        break;
                                    case 'PPE':
                                        $category_badge = '<span class="category-badge category-ppe">PPE</span>';
                                        break;
                                    default:
                                        $category_badge = '<span class="category-badge category-other">Other</span>';
                                }
                                
                                // Determine status badge
                                $status_badge = '';
                                switch ($resource['condition_status']) {
                                    case 'Serviceable':
                                        $status_badge = '<span class="status-badge status-serviceable">Serviceable</span>';
                                        break;
                                    case 'Under Maintenance':
                                        $status_badge = '<span class="status-badge status-maintenance">Under Maintenance</span>';
                                        break;
                                    case 'Condemned':
                                        $status_badge = '<span class="status-badge status-condemned">Condemned</span>';
                                        break;
                                }
                            ?>
                                <div class="resource-card">
                                    <div class="resource-header">
                                        <div>
                                            <div class="resource-title"><?php echo htmlspecialchars($resource['resource_name']); ?></div>
                                            <div style="display: flex; gap: 8px; margin-top: 4px;">
                                                <?php echo $category_badge; ?>
                                                <span class="resource-type"><?php echo htmlspecialchars($resource['resource_type']); ?></span>
                                            </div>
                                        </div>
                                        <?php echo $status_badge; ?>
                                    </div>
                                    
                                    <div class="resource-body">
                                        <div class="resource-info">
                                            <div class="info-item">
                                                <span class="info-label">Quantity:</span>
                                                <span class="info-value"><?php echo $resource['quantity']; ?> units</span>
                                            </div>
                                            <div class="info-item">
                                                <span class="info-label">Available:</span>
                                                <span class="info-value"><?php echo $resource['available_quantity'] ?? $resource['quantity']; ?> units</span>
                                            </div>
                                            <div class="info-item">
                                                <span class="info-label">Last Updated:</span>
                                                <span class="info-value"><?php echo date('M d, Y', strtotime($resource['updated_at'])); ?></span>
                                            </div>
                                        </div>
                                        
                                        <?php if (!empty($equipment_list)): ?>
                                            <div class="equipment-list">
                                                <div class="equipment-title">Equipment Items (<?php echo count($equipment_list); ?>)</div>
                                                <div class="equipment-items">
                                                    <?php foreach (array_slice($equipment_list, 0, 5) as $item): ?>
                                                        <div class="equipment-item">
                                                            <span class="equipment-name"><?php echo htmlspecialchars($item['name'] ?? $item); ?></span>
                                                            <?php if (isset($item['is_mandatory']) && $item['is_mandatory']): ?>
                                                                <span class="mandatory-badge">Mandatory</span>
                                                            <?php elseif (isset($item['is_recommended']) && $item['is_recommended']): ?>
                                                                <span class="recommended-badge">Recommended</span>
                                                            <?php endif; ?>
                                                        </div>
                                                    <?php endforeach; ?>
                                                    <?php if (count($equipment_list) > 5): ?>
                                                        <div class="equipment-item">
                                                            <span class="equipment-name" style="color: var(--text-light); font-style: italic;">
                                                                + <?php echo count($equipment_list) - 5; ?> more items
                                                            </span>
                                                        </div>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                    
                                    <div class="resource-footer">
                                        <div style="font-size: 11px; color: var(--text-light);">
                                            ID: <?php echo $resource['external_id'] ? 'ER-' . $resource['external_id'] : 'LOCAL-' . $resource['id']; ?>
                                        </div>
                                        <div class="action-buttons">
                                            <button class="action-button view-button" onclick="viewResource(<?php echo $resource['id']; ?>)">
                                                <i class='bx bx-show'></i>
                                                View Details
                                            </button>
                                            <button class="action-button update-button" onclick="showUpdateStatusModal(<?php echo $resource['id']; ?>, '<?php echo htmlspecialchars($resource['resource_name'], ENT_QUOTES); ?>', '<?php echo $resource['condition_status']; ?>')">
                                                <i class='bx bx-edit'></i>
                                                Update Status
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="no-resources">
                                <div class="no-resources-icon">
                                    <i class='bx bxs-cube'></i>
                                </div>
                                <h3>No Equipment Resources Found</h3>
                                <p>No resources match your current filters. Try syncing with the ER system or adjusting your filters.</p>
                                <button class="primary-button" id="sync-empty-button" style="margin-top: 20px;">
                                    <i class='bx bx-refresh'></i>
                                    Sync with ER System
                                </button>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <script>
        let currentResourceId = null;
        let currentResourceName = null;
        let currentResourceStatus = null;
        let selectedStatus = null;
        
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
                const notificationDropdown = document.getElementById('notification-dropdown');
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
            document.addEventListener('click', function(e) {
                if (!userProfile.contains(e.target)) {
                    userDropdown.classList.remove('show');
                }
                if (!notificationBell.contains(e.target)) {
                    notificationDropdown.classList.remove('show');
                }
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
                    document.getElementById('status-filter').value = status;
                    applyFilters();
                });
            });
            
            // Resource Details Modal functionality
            document.getElementById('modal-close').addEventListener('click', closeResourceModal);
            document.getElementById('modal-close-btn').addEventListener('click', closeResourceModal);
            document.getElementById('modal-update-status-btn').addEventListener('click', function() {
                if (currentResourceId) {
                    closeResourceModal();
                    setTimeout(() => {
                        showUpdateStatusModal(currentResourceId, 'Sample Resource', 'Serviceable');
                    }, 300);
                }
            });
            
            // Update Status Modal functionality
            document.getElementById('status-modal-close').addEventListener('click', closeStatusModal);
            document.getElementById('status-cancel-btn').addEventListener('click', closeStatusModal);
            document.getElementById('status-save-btn').addEventListener('click', saveStatusUpdate);
            
            // Status option selection
            document.querySelectorAll('.status-option').forEach(option => {
                option.addEventListener('click', function() {
                    document.querySelectorAll('.status-option').forEach(opt => {
                        opt.classList.remove('selected');
                    });
                    
                    this.classList.add('selected');
                    selectedStatus = this.getAttribute('data-status');
                });
            });
            
            // Sync buttons
            document.getElementById('sync-button').addEventListener('click', syncResources);
            document.getElementById('sync-empty-button')?.addEventListener('click', syncResources);
            document.getElementById('refresh-button').addEventListener('click', refreshData);
            
            // Keyboard shortcuts
            document.addEventListener('keydown', function(e) {
                if (e.key === '/' && !e.ctrlKey && !e.altKey && !e.metaKey) {
                    e.preventDefault();
                    document.getElementById('search-input').focus();
                }
                
                if (e.key === 'Escape') {
                    closeResourceModal();
                    closeStatusModal();
                    userDropdown.classList.remove('show');
                    notificationDropdown.classList.remove('show');
                }
                
                if (e.ctrlKey && e.key === 's') {
                    e.preventDefault();
                    syncResources();
                }
            });
        }
        
        function applyFilters() {
            const category = document.getElementById('category-filter').value;
            const status = document.getElementById('status-filter').value;
            const search = document.getElementById('search-filter').value;
            
            let url = 'view_equipment.php?';
            if (category !== 'all') {
                url += `category=${category}&`;
            }
            if (status !== 'all') {
                url += `status=${status}&`;
            }
            if (search) {
                url += `search=${encodeURIComponent(search)}`;
            }
            
            window.location.href = url;
        }
        
        function resetFilters() {
            document.getElementById('category-filter').value = 'all';
            document.getElementById('status-filter').value = 'all';
            document.getElementById('search-filter').value = '';
            applyFilters();
        }
        
        function viewResource(id) {
            currentResourceId = id;
            
            document.getElementById('modal-body').innerHTML = `
                <div style="text-align: center; padding: 40px;">
                    <i class='bx bx-loader-circle bx-spin' style="font-size: 48px; color: var(--primary-color);"></i>
                    <p style="margin-top: 16px; color: var(--text-light);">Loading resource details...</p>
                </div>
            `;
            
            document.getElementById('resource-modal').classList.add('active');
            
            fetch(`view_equipment.php?ajax=true&get_resource_details=true&id=${id}`)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        populateResourceModal(data.data, data.parsed_description);
                    } else {
                        alert('Failed to load resource details: ' + data.message);
                        closeResourceModal();
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('Failed to load resource details');
                    closeResourceModal();
                });
        }
        
        function populateResourceModal(resource, description) {
            const modalBody = document.getElementById('modal-body');
            
            let category_badge = '';
            switch (resource.category) {
                case 'Firefighting':
                    category_badge = '<span class="category-badge category-firefighting">Firefighting</span>';
                    break;
                case 'Medical':
                    category_badge = '<span class="category-badge category-medical">Medical</span>';
                    break;
                case 'Rescue':
                    category_badge = '<span class="category-badge category-rescue">Rescue</span>';
                    break;
                case 'PPE':
                    category_badge = '<span class="category-badge category-ppe">PPE</span>';
                    break;
                default:
                    category_badge = '<span class="category-badge category-other">Other</span>';
            }
            
            let status_badge = '';
            switch (resource.condition_status) {
                case 'Serviceable':
                    status_badge = '<span class="status-badge status-serviceable">Serviceable</span>';
                    break;
                case 'Under Maintenance':
                    status_badge = '<span class="status-badge status-maintenance">Under Maintenance</span>';
                    break;
                case 'Condemned':
                    status_badge = '<span class="status-badge status-condemned">Condemned</span>';
                    break;
            }
            
            const equipment_list = description.equipment_list || [];
            const mandatory_items = description.mandatory_items || [];
            const recommended_items = description.recommended_items || [];
            const stats = description.stats || {};
            
            let html = `
                <div class="modal-section">
                    <h3 class="modal-section-title">
                        <i class='bx bxs-cube'></i> Resource Information
                    </h3>
                    <div class="modal-grid">
                        <div class="modal-detail">
                            <div class="modal-detail-label">Resource Name</div>
                            <div class="modal-detail-value">${resource.resource_name}</div>
                        </div>
                        <div class="modal-detail">
                            <div class="modal-detail-label">Resource Type</div>
                            <div class="modal-detail-value">${resource.resource_type}</div>
                        </div>
                        <div class="modal-detail">
                            <div class="modal-detail-label">Category</div>
                            <div class="modal-detail-value">${category_badge}</div>
                        </div>
                        <div class="modal-detail">
                            <div class="modal-detail-label">Status</div>
                            <div class="modal-detail-value">${status_badge}</div>
                        </div>
                        <div class="modal-detail">
                            <div class="modal-detail-label">Quantity</div>
                            <div class="modal-detail-value">${resource.quantity} units</div>
                        </div>
                        <div class="modal-detail">
                            <div class="modal-detail-label">Available Quantity</div>
                            <div class="modal-detail-value">${resource.available_quantity || resource.quantity} units</div>
                        </div>
                        <div class="modal-detail">
                            <div class="modal-detail-label">External ID</div>
                            <div class="modal-detail-value">${resource.external_id ? 'ER-' + resource.external_id : 'LOCAL-' + resource.id}</div>
                        </div>
                        <div class="modal-detail">
                            <div class="modal-detail-label">Last Synced</div>
                            <div class="modal-detail-value">${resource.last_sync_at ? new Date(resource.last_sync_at).toLocaleDateString('en-US', { year: 'numeric', month: 'long', day: 'numeric', hour: '2-digit', minute: '2-digit' }) : 'Never'}</div>
                        </div>
                    </div>
                </div>
            `;
            
            if (equipment_list.length > 0) {
                const mandatory_count = stats.mandatory_count || mandatory_items.length;
                const recommended_count = stats.recommended_count || recommended_items.length;
                
                html += `
                    <div class="modal-section">
                        <h3 class="modal-section-title">
                            <i class='bx bxs-wrench'></i> Equipment List
                        </h3>
                        <div class="modal-detail">
                            <div class="modal-detail-label">Total Items</div>
                            <div class="modal-detail-value">${stats.total_items || equipment_list.length} items (${mandatory_count} mandatory, ${recommended_count} recommended)</div>
                        </div>
                        <div class="modal-detail">
                            <div class="modal-detail-label">Completeness</div>
                            <div class="modal-detail-value">${stats.completeness_percentage || 100}% complete</div>
                        </div>
                        
                        <table class="equipment-table">
                            <thead>
                                <tr>
                                    <th>Item Name</th>
                                    <th>Type</th>
                                </tr>
                            </thead>
                            <tbody>
                `;
                
                equipment_list.forEach(item => {
                    const item_name = item.name || item;
                    const is_mandatory = item.is_mandatory || mandatory_items.includes(item_name);
                    const is_recommended = item.is_recommended || recommended_items.includes(item_name);
                    
                    let item_type = 'Standard';
                    if (is_mandatory) item_type = 'Mandatory';
                    else if (is_recommended) item_type = 'Recommended';
                    
                    html += `
                        <tr>
                            <td>${item_name}</td>
                            <td>
                                ${is_mandatory ? '<span class="mandatory-badge">Mandatory</span>' : 
                                  is_recommended ? '<span class="recommended-badge">Recommended</span>' : 
                                  '<span style="font-size: 11px; color: var(--text-light);">Standard</span>'}
                            </td>
                        </tr>
                    `;
                });
                
                html += `
                            </tbody>
                        </table>
                    </div>
                `;
            }
            
            if (description && Object.keys(description).length > 0) {
                const readableDescription = formatEquipmentDescription(description);
                
                html += `
                    <div class="modal-section">
                        <h3 class="modal-section-title">
                            <i class='bx bx-note'></i> Additional Information
                        </h3>
                        <div class="modal-detail">
                            <div class="modal-detail-label">Description</div>
                            <div class="modal-detail-value" style="background: var(--gray-100); padding: 15px; border-radius: 8px; font-family: monospace; font-size: 12px; line-height: 1.4; overflow-x: auto;">
                                ${readableDescription}
                            </div>
                        </div>
                    </div>
                `;
            }
            
            modalBody.innerHTML = html;
        }
        
        function formatEquipmentDescription(description) {
            if (!description) return '';
            
            let html = '<div style="color: var(--text-color);">';
            
            if (description.stats) {
                html += '<div style="margin-bottom: 15px; padding-bottom: 10px; border-bottom: 1px solid var(--border-color);">';
                html += '<strong style="color: var(--primary-color);">Equipment Stats:</strong><br>';
                html += `Total Items: ${description.stats.total_items || 0}<br>`;
                html += `Mandatory: ${description.stats.mandatory_count || 0}<br>`;
                html += `Recommended: ${description.stats.recommended_count || 0}<br>`;
                html += `Completeness: ${description.stats.completeness_percentage || 0}%<br>`;
                html += `Categories: ${description.stats.categories_count || 0}`;
                html += '</div>';
            }
            
            if (description.equipment_list && description.equipment_list.length > 0) {
                html += '<div style="margin-bottom: 15px;">';
                html += '<strong style="color: var(--primary-color);">Equipment Items:</strong><br>';
                
                description.equipment_list.forEach((item, index) => {
                    const itemName = item.name || item;
                    const isMandatory = item.is_mandatory || (description.mandatory_items && description.mandatory_items.includes(itemName));
                    const isRecommended = item.is_recommended || (description.recommended_items && description.recommended_items.includes(itemName));
                    
                    let typeBadge = '';
                    if (isMandatory) {
                        typeBadge = '<span style="background: #fee2e2; color: #dc2626; padding: 2px 6px; border-radius: 3px; font-size: 10px; margin-left: 5px;">Mandatory</span>';
                    } else if (isRecommended) {
                        typeBadge = '<span style="background: #dbeafe; color: #2563eb; padding: 2px 6px; border-radius: 3px; font-size: 10px; margin-left: 5px;">Recommended</span>';
                    }
                    
                    html += `${index + 1}. ${itemName} ${typeBadge}<br>`;
                });
                
                html += '</div>';
            }
            
            if (description.mandatory_items && description.mandatory_items.length > 0) {
                html += '<div style="margin-bottom: 15px;">';
                html += '<strong style="color: #dc2626;">Mandatory Items:</strong><br>';
                description.mandatory_items.forEach((item, index) => {
                    html += `${index + 1}. ${item}<br>`;
                });
                html += '</div>';
            }
            
            if (description.recommended_items && description.recommended_items.length > 0) {
                html += '<div>';
                html += '<strong style="color: #2563eb;">Recommended Items:</strong><br>';
                description.recommended_items.forEach((item, index) => {
                    html += `${index + 1}. ${item}<br>`;
                });
                html += '</div>';
            }
            
            html += '</div>';
            return html;
        }
        
        function closeResourceModal() {
            document.getElementById('resource-modal').classList.remove('active');
            currentResourceId = null;
        }
        
        function showUpdateStatusModal(resourceId, resourceName, currentStatus) {
            currentResourceId = resourceId;
            currentResourceName = resourceName;
            currentResourceStatus = currentStatus;
            
            document.getElementById('status-resource-name').textContent = resourceName;
            document.getElementById('status-current-status').textContent = currentStatus;
            
            selectedStatus = null;
            document.querySelectorAll('.status-option').forEach(opt => {
                opt.classList.remove('selected');
            });
            
            document.getElementById('status-notes').value = '';
            
            document.getElementById('status-modal-overlay').classList.add('active');
        }
        
        function closeStatusModal() {
            document.getElementById('status-modal-overlay').classList.remove('active');
            currentResourceId = null;
            currentResourceName = null;
            currentResourceStatus = null;
            selectedStatus = null;
        }
        
        function saveStatusUpdate() {
            if (!selectedStatus) {
                alert('Please select a new status.');
                return;
            }
            
            const notes = document.getElementById('status-notes').value;
            
            if (confirm(`Are you sure you want to update ${currentResourceName} from ${currentResourceStatus} to ${selectedStatus}?`)) {
                const saveBtn = document.getElementById('status-save-btn');
                const originalText = saveBtn.innerHTML;
                saveBtn.innerHTML = '<i class="bx bx-loader-circle bx-spin"></i> Updating...';
                saveBtn.disabled = true;
                
                // Make AJAX call to update status
                const formData = new FormData();
                formData.append('update_resource_status', 'true');
                formData.append('resource_id', currentResourceId);
                formData.append('new_status', selectedStatus);
                formData.append('notes', notes);
                
                fetch('view_equipment.php', {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    saveBtn.innerHTML = originalText;
                    saveBtn.disabled = false;
                    
                    if (data.success) {
                        alert(`Status updated successfully!\n\nResource: ${currentResourceName}\nNew Status: ${selectedStatus}\nNotes: ${notes || 'No notes provided'}`);
                        
                        closeStatusModal();
                        refreshData();
                    } else {
                        alert('Failed to update status: ' + data.message);
                    }
                })
                .catch(error => {
                    saveBtn.innerHTML = originalText;
                    saveBtn.disabled = false;
                    console.error('Error:', error);
                    alert('Failed to update status. Please try again.');
                });
            }
        }
        
        function syncResources() {
            const loadingOverlay = document.getElementById('loading-overlay');
            const loadingText = document.getElementById('loading-text');
            loadingOverlay.classList.add('active');
            
            fetch('view_equipment.php?ajax=true&sync_resources=true')
                .then(response => response.json())
                .then(data => {
                    loadingOverlay.classList.remove('active');
                    
                    if (data.success) {
                        const count = data.data ? data.data.length : 0;
                        alert(`Successfully synced ${count} resources from the ER system!`);
                        window.location.href = 'view_equipment.php?sync_success=true';
                    } else {
                        alert('Failed to sync resources: ' + data.message);
                        window.location.href = 'view_equipment.php?sync_error=' + encodeURIComponent(data.message);
                    }
                })
                .catch(error => {
                    loadingOverlay.classList.remove('active');
                    console.error('Error:', error);
                    alert('Failed to sync resources. Please check your connection and try again.');
                });
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
        
        updateTime();
        setInterval(updateTime, 1000);
    </script>
</body>
</html>