<?php
// select_unit.php
session_start();
require_once '../../config/db_connection.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: ../../login/login.php");
    exit();
}

// Check if user has dispatch coordination access
$user_id = $_SESSION['user_id'];
$query = "SELECT first_name, middle_name, last_name, role, avatar FROM users WHERE id = ?";
$stmt = $pdo->prepare($query);
$stmt->execute([$user_id]);
$user = $stmt->fetch();

if ($user['role'] !== 'ADMIN' && $user['role'] !== 'EMPLOYEE') {
    header("Location: ../employee_dashboard.php");
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

// Create dispatch_incidents table if it doesn't exist
$create_table_sql = "
    CREATE TABLE IF NOT EXISTS dispatch_incidents (
        id INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
        incident_id INT(11) NOT NULL,
        unit_id INT(11) NOT NULL,
        vehicles_json TEXT DEFAULT NULL,
        dispatched_by INT(11) DEFAULT NULL,
        dispatched_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        status ENUM('pending','dispatched','en_route','arrived','completed','cancelled') DEFAULT 'pending',
        status_updated_at DATETIME DEFAULT NULL,
        er_notes TEXT DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (incident_id) REFERENCES api_incidents(id) ON DELETE CASCADE,
        FOREIGN KEY (unit_id) REFERENCES units(id) ON DELETE CASCADE,
        KEY idx_incident (incident_id),
        KEY idx_unit (unit_id),
        KEY idx_status (status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
";

// Create vehicle_status table if it doesn't exist
$create_vehicle_table_sql = "
    CREATE TABLE IF NOT EXISTS vehicle_status (
        id INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
        vehicle_id INT(11) NOT NULL,
        vehicle_name VARCHAR(100) NOT NULL,
        vehicle_type VARCHAR(50) NOT NULL,
        unit_id INT(11) DEFAULT NULL,
        dispatch_id INT(11) DEFAULT NULL,
        suggestion_id INT(11) DEFAULT NULL,
        status ENUM('available','suggested','dispatched','maintenance','out_of_service') DEFAULT 'available',
        current_location VARCHAR(255) DEFAULT NULL,
        last_updated TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_vehicle_status (status),
        INDEX idx_vehicle_unit (unit_id),
        INDEX idx_vehicle_dispatch (dispatch_id),
        INDEX idx_vehicle_suggestion (suggestion_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
";

try {
    $pdo->exec($create_table_sql);
    $pdo->exec($create_vehicle_table_sql);
    
    // Add columns if they don't exist
    $check_columns = [
        "ALTER TABLE units ADD COLUMN IF NOT EXISTS current_status ENUM('available','dispatched','unavailable','maintenance') DEFAULT 'available'",
        "ALTER TABLE units ADD COLUMN IF NOT EXISTS current_dispatch_id INT(11) DEFAULT NULL",
        "ALTER TABLE units ADD COLUMN IF NOT EXISTS last_status_change TIMESTAMP NULL DEFAULT NULL",
        "ALTER TABLE api_incidents ADD COLUMN IF NOT EXISTS dispatch_status ENUM('for_dispatch','processing','responded','closed') DEFAULT 'for_dispatch'",
        "ALTER TABLE api_incidents ADD COLUMN IF NOT EXISTS dispatch_id INT(11) DEFAULT NULL"
    ];
    
    foreach ($check_columns as $sql) {
        try {
            $pdo->exec($sql);
        } catch (PDOException $e) {
            // Column might already exist, continue
        }
    }
} catch (PDOException $e) {
    // Tables might already exist, continue
}

// Get incidents for dispatch (only those with dispatch_status = 'for_dispatch')
// MODIFIED QUERY: Include both fire and rescue incidents
$incidents_query = "
    SELECT ai.*, 
           (SELECT COUNT(*) FROM units WHERE current_status = 'available' AND status = 'Active') as available_units,
           (SELECT COUNT(*) FROM volunteer_assignments va 
            JOIN volunteers v ON va.volunteer_id = v.id 
            WHERE v.status = 'approved' AND va.status = 'Active') as available_volunteers
    FROM api_incidents ai
    WHERE ai.dispatch_status = 'for_dispatch'
      AND (
          ai.is_fire_rescue_related = 1 
          OR ai.emergency_type = 'rescue'
          OR ai.rescue_category IS NOT NULL
      )
    ORDER BY 
        CASE ai.severity
            WHEN 'critical' THEN 1
            WHEN 'high' THEN 2
            WHEN 'medium' THEN 3
            WHEN 'low' THEN 4
            ELSE 5
        END,
        ai.created_at DESC
";
$incidents_stmt = $pdo->query($incidents_query);
$all_incidents_for_dispatch = $incidents_stmt->fetchAll();

// Get available units for dispatch
$units_query = "
    SELECT u.*, 
           COUNT(DISTINCT va.volunteer_id) as volunteer_count,
           COUNT(DISTINCT vs.vehicle_id) as vehicle_count,
           d.status as current_dispatch_status,
           ai.title as current_incident_title
    FROM units u
    LEFT JOIN volunteer_assignments va ON u.id = va.unit_id AND va.status = 'Active'
    LEFT JOIN vehicle_status vs ON u.id = vs.unit_id AND vs.status = 'available'
    LEFT JOIN dispatch_incidents d ON u.current_dispatch_id = d.id 
        AND d.status IN ('dispatched', 'en_route', 'arrived')
    LEFT JOIN api_incidents ai ON d.incident_id = ai.id
    WHERE u.status = 'active'
      AND u.current_status = 'available'
      AND u.id NOT IN (
          SELECT DISTINCT unit_id 
          FROM dispatch_incidents 
          WHERE status = 'pending'
      )
    GROUP BY u.id
    ORDER BY u.unit_type, u.unit_name
";
$units_stmt = $pdo->query($units_query);
$available_units = $units_stmt->fetchAll();

// Get dispatch statistics - MODIFIED: Include rescue incidents in counts
$stats_query = "
    SELECT 
        (SELECT COUNT(*) FROM api_incidents WHERE dispatch_status = 'for_dispatch' 
         AND (is_fire_rescue_related = 1 OR emergency_type = 'rescue' OR rescue_category IS NOT NULL)
        ) as pending_incidents,
        (SELECT COUNT(*) FROM api_incidents WHERE dispatch_status = 'responded' 
         AND (is_fire_rescue_related = 1 OR emergency_type = 'rescue' OR rescue_category IS NOT NULL)
         AND DATE(created_at) = CURDATE()
        ) as responded_today,
        (SELECT COUNT(*) FROM units WHERE current_status = 'available' AND status = 'Active') as available_units,
        (SELECT COUNT(*) FROM volunteer_assignments va 
         JOIN volunteers v ON va.volunteer_id = v.id 
         WHERE v.status = 'approved' AND va.status = 'Active') as assigned_volunteers,
        (SELECT COUNT(*) FROM vehicle_status WHERE status = 'available') as available_vehicles
";
$stats_stmt = $pdo->query($stats_query);
$stats = $stats_stmt->fetch();

// Get all dispatches for pagination - MODIFIED: Include rescue incidents
$all_dispatches_query = "
    SELECT 
        di.*,
        ai.id as incident_id,
        ai.title,
        ai.location,
        ai.severity,
        ai.emergency_type,
        ai.rescue_category,
        ai.is_fire_rescue_related,
        ai.dispatch_status,
        u.unit_name,
        u.unit_code,
        u.current_status as unit_status,
        JSON_LENGTH(di.vehicles_json) as vehicle_count
    FROM dispatch_incidents di
    JOIN api_incidents ai ON di.incident_id = ai.id
    JOIN units u ON di.unit_id = u.id
    WHERE di.dispatched_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
      AND di.status NOT IN ('cancelled')
      AND (
          ai.is_fire_rescue_related = 1 
          OR ai.emergency_type = 'rescue'
          OR ai.rescue_category IS NOT NULL
      )
    ORDER BY di.dispatched_at DESC
";
$all_dispatches_stmt = $pdo->query($all_dispatches_query);
$all_dispatches = $all_dispatches_stmt->fetchAll();

// Pagination for recent dispatches (3 per page)
$dispatch_per_page = 3;
$total_dispatches = count($all_dispatches);
$total_dispatch_pages = ceil($total_dispatches / $dispatch_per_page);

// Get current dispatch page from URL, default to 1
$dispatch_page = isset($_GET['dispatch_page']) ? max(1, intval($_GET['dispatch_page'])) : 1;
$dispatch_offset = ($dispatch_page - 1) * $dispatch_per_page;

// Get dispatches for current page
$dispatches_for_page = array_slice($all_dispatches, $dispatch_offset, $dispatch_per_page);

// Pagination setup for incidents
$incidents_per_page = 5;
$total_incidents = count($all_incidents_for_dispatch);
$total_pages = ceil($total_incidents / $incidents_per_page);

// Get current page from URL, default to 1
$current_page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$offset = ($current_page - 1) * $incidents_per_page;

// Filtering parameters
$severity_filter = isset($_GET['severity']) ? $_GET['severity'] : 'all';
$type_filter = isset($_GET['type']) ? $_GET['type'] : 'all';

// Apply filters
$filtered_incidents = $all_incidents_for_dispatch;
if ($severity_filter !== 'all') {
    $filtered_incidents = array_filter($filtered_incidents, function($incident) use ($severity_filter) {
        return strtolower($incident['severity']) === $severity_filter;
    });
}

if ($type_filter !== 'all') {
    $filtered_incidents = array_filter($filtered_incidents, function($incident) use ($type_filter) {
        // Handle both emergency_type and rescue_category
        $incident_type = strtolower($incident['emergency_type']);
        $rescue_cat = $incident['rescue_category'] ? strtolower(str_replace('_', ' ', $incident['rescue_category'])) : '';
        
        if ($type_filter === 'rescue') {
            return $incident_type === 'rescue' || !empty($rescue_cat);
        } else if ($type_filter === 'fire') {
            return $incident_type === 'fire' || $incident['is_fire_rescue_related'] == 1;
        } else {
            return $incident_type === $type_filter;
        }
    });
}

// Re-index array after filtering
$filtered_incidents = array_values($filtered_incidents);
$total_filtered_incidents = count($filtered_incidents);
$total_filtered_pages = ceil($total_filtered_incidents / $incidents_per_page);

// Get incidents for current page
$incidents_for_dispatch = array_slice($filtered_incidents, $offset, $incidents_per_page);

$stmt = null;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dispatch Coordination - AI Unit Selection</title>
    <link href='https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css' rel='stylesheet'>
    <link rel="icon" type="image/png" sizes="32x32" href="../../img/frsm-logo.png">
    <link rel="stylesheet" href="../../css/dashboard.css">
    <style>
        /* Dashboard Variables */
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
            --cyan: #06b6d4;
            
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
            --gray-100: #1e293b;
            --gray-200: #334155;
            --gray-300: #475569;
        }
        
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background-color: var(--background-color);
            color: var(--text-color);
            transition: all 0.3s ease;
            margin: 0;
            padding: 0;
            overflow-x: hidden;
        }

        .container {
            display: flex;
            min-height: 100vh;
        }

        /* Main Content */
        .main-content {
            flex: 1;
            overflow-y: auto;
        }

        /* Dashboard Content */
        .dashboard-content {
            padding: 0;
            min-height: 100vh;
        }

        .dashboard-container {
            padding: 0 40px 40px;
        }
        
        /* Header matching reference */
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
        
        .dashboard-header .header-content h1 {
            font-size: 40px;
            margin-bottom: 12px;
            color: var(--text-color);
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }

        .dashboard-header .header-content p {
            font-size: 16px;
            opacity: 0.9;
            color: var(--text-color);
        }
        
        .header-actions {
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
        
        /* Stats Grid */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        
        .stat-card {
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: 16px;
            padding: 25px;
            display: flex;
            align-items: center;
            gap: 15px;
            transition: all 0.3s ease;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
        }
        
        .stat-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 6px 20px rgba(0, 0, 0, 0.1);
            border-color: var(--primary-color);
        }
        
        .stat-icon {
            width: 56px;
            height: 56px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
            flex-shrink: 0;
        }
        
        .stat-content {
            flex: 1;
        }
        
        .stat-content .value {
            font-size: 32px;
            font-weight: 800;
            line-height: 1;
            margin-bottom: 4px;
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }
        
        .stat-content .label {
            color: var(--text-light);
            font-size: 14px;
            font-weight: 500;
        }
        
        /* Dispatches Container */
        .dispatches-container {
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: 16px;
            margin-bottom: 30px;
            overflow: hidden;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
            padding: 30px;
        }
        
        .dispatches-header {
            margin-bottom: 20px;
        }
        
        .dispatches-header h3 {
            font-size: 24px;
            font-weight: 700;
            color: var(--text-color);
            margin-bottom: 8px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .dispatches-header p {
            color: var(--text-light);
            font-size: 14px;
        }
        
        .dispatch-item {
            padding: 15px;
            border: 1px solid var(--border-color);
            border-radius: 10px;
            margin-bottom: 10px;
            background: var(--card-bg);
            transition: all 0.3s ease;
        }
        
        .dispatch-item:hover {
            border-color: var(--primary-color);
            transform: translateX(2px);
        }
        
        .dispatch-item-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 10px;
        }
        
        .dispatch-item-title {
            font-weight: 600;
            font-size: 15px;
            color: var(--text-color);
        }
        
        .dispatch-item-location {
            font-size: 13px;
            color: var(--text-light);
            margin-top: 4px;
            display: flex;
            align-items: center;
            gap: 5px;
        }
        
        .dispatch-item-details {
            display: flex;
            align-items: center;
            gap: 15px;
            font-size: 13px;
            color: var(--text-light);
            flex-wrap: wrap;
        }
        
        .dispatch-item-unit {
            display: flex;
            align-items: center;
            gap: 5px;
        }
        
        .dispatch-item-vehicles {
            display: flex;
            align-items: center;
            gap: 5px;
        }
        
        .dispatch-item-time {
            font-size: 11px;
            color: var(--text-light);
            text-align: right;
        }
        
        .dispatch-actions {
            display: flex;
            gap: 5px;
            margin-top: 10px;
        }
        
        .action-btn {
            padding: 5px 10px;
            border-radius: 6px;
            border: none;
            cursor: pointer;
            font-size: 12px;
            display: flex;
            align-items: center;
            gap: 4px;
            transition: all 0.3s ease;
        }
        
        .action-btn.view {
            background: var(--info);
            color: white;
        }
        
        .action-btn.edit {
            background: var(--warning);
            color: white;
        }
        
        .action-btn:hover {
            transform: translateY(-2px);
            opacity: 0.9;
        }
        
        /* Incident List */
        .incident-list-container {
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: 16px;
            margin-bottom: 30px;
            overflow: hidden;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
            padding: 30px;
        }
        
        .incident-header {
            margin-bottom: 20px;
        }
        
        .incident-header h3 {
            font-size: 24px;
            font-weight: 700;
            color: var(--text-color);
            margin-bottom: 8px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .incident-header p {
            color: var(--text-light);
            font-size: 14px;
        }
        
        .filters-container {
            display: flex;
            gap: 10px;
            align-items: center;
            flex-wrap: wrap;
            margin-top: 15px;
        }
        
        .filter-select {
            padding: 10px 15px;
            border: 1px solid var(--border-color);
            border-radius: 10px;
            background: var(--card-bg);
            color: var(--text-color);
            font-size: 14px;
            min-width: 150px;
        }
        
        .filter-select:focus {
            outline: none;
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(220, 38, 38, 0.1);
        }
        
        .incident-table-container {
            overflow-x: auto;
            margin-top: 20px;
        }
        
        .incident-table {
            width: 100%;
            border-collapse: collapse;
        }
        
        .incident-table thead {
            background: rgba(220, 38, 38, 0.05);
        }
        
        .incident-table th {
            padding: 15px;
            text-align: left;
            font-weight: 600;
            color: var(--text-color);
            border-bottom: 2px solid var(--border-color);
            white-space: nowrap;
        }
        
        .incident-table td {
            padding: 15px;
            border-bottom: 1px solid var(--border-color);
            vertical-align: middle;
        }
        
        .incident-table tbody tr {
            transition: all 0.3s ease;
        }
        
        .incident-table tbody tr:hover {
            background: rgba(220, 38, 38, 0.03);
        }
        
        .severity-badge, .dispatch-status-badge, .status-badge {
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            text-transform: uppercase;
            display: inline-block;
        }
        
        .severity-critical { background: #dc2626; color: white; }
        .severity-high { background: #ef4444; color: white; }
        .severity-medium { background: #f59e0b; color: white; }
        .severity-low { background: #10b981; color: white; }
        
        .status-for_dispatch { background: #f59e0b; color: white; }
        .status-processing { background: #3b82f6; color: white; }
        .status-responded { background: #10b981; color: white; }
        .status-closed { background: #6b7280; color: white; }
        
        .status-pending { background: #f59e0b; color: white; }
        .status-dispatched { background: #3b82f6; color: white; }
        .status-en_route { background: #8b5cf6; color: white; }
        .status-arrived { background: #10b981; color: white; }
        .status-completed { background: #6b7280; color: white; }
        
        .action-buttons {
            display: flex;
            gap: 6px;
            flex-wrap: wrap;
        }
        
        .btn {
            padding: 10px 16px;
            border: none;
            border-radius: 10px;
            cursor: pointer;
            font-weight: 600;
            font-size: 14px;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            transition: all 0.3s ease;
            text-decoration: none;
        }
        
        .btn-primary {
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            color: white;
        }
        
        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 15px rgba(220, 38, 38, 0.3);
        }
        
        .btn-secondary {
            background: var(--gray-200);
            color: var(--text-color);
            border: 1px solid var(--border-color);
        }
        
        .btn-secondary:hover {
            background: var(--gray-300);
            transform: translateY(-2px);
        }
        
        .btn-ai {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
        }
        
        .btn-ai:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 15px rgba(102, 126, 234, 0.3);
        }
        
        .btn-success {
            background: linear-gradient(135deg, var(--success), #0da271);
            color: white;
        }
        
        .btn-success:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 15px rgba(16, 185, 129, 0.3);
        }
        
        /* Pagination */
        .pagination-container {
            padding: 20px 0;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 15px;
        }
        
        .pagination-info {
            font-size: 14px;
            color: var(--text-light);
        }
        
        .pagination {
            display: flex;
            gap: 5px;
            align-items: center;
        }
        
        .page-link, .page-item {
            padding: 8px 14px;
            border: 1px solid var(--border-color);
            background: var(--card-bg);
            color: var(--text-color);
            border-radius: 8px;
            text-decoration: none;
            transition: all 0.3s ease;
            font-size: 14px;
        }
        
        .page-link:hover:not(.active) {
            background: var(--gray-100);
            border-color: var(--gray-300);
        }
        
        .page-link.active {
            background: var(--primary-color);
            color: white;
            border-color: var(--primary-color);
        }
        
        .page-item.disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }
        
        /* Modal Styles */
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.5);
            z-index: 1000;
            align-items: center;
            justify-content: center;
            backdrop-filter: blur(4px);
        }
        
        .modal.active {
            display: flex;
        }
        
        .modal-content {
            background: var(--card-bg);
            border-radius: 16px;
            width: 90%;
            max-width: 900px;
            max-height: 85vh;
            overflow-y: auto;
            animation: modalSlideIn 0.3s ease;
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.2);
        }
        
        @keyframes modalSlideIn {
            from {
                opacity: 0;
                transform: translateY(-20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        .modal-header {
            padding: 25px 30px;
            border-bottom: 1px solid var(--border-color);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .modal-header h3 {
            margin: 0;
            font-size: 24px;
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .modal-header button {
            background: none;
            border: none;
            font-size: 28px;
            cursor: pointer;
            color: var(--text-light);
            transition: color 0.3s ease;
            width: 40px;
            height: 40px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 50%;
        }
        
        .modal-header button:hover {
            background: var(--gray-100);
            color: var(--danger);
        }
        
        .modal-body {
            padding: 30px;
        }
        
        /* Loading States */
        .ai-loading {
            text-align: center;
            padding: 50px;
        }
        
        .ai-spinner {
            width: 50px;
            height: 50px;
            border: 4px solid var(--border-color);
            border-top-color: var(--primary-color);
            border-radius: 50%;
            animation: spin 1s linear infinite;
            margin: 0 auto 20px;
        }
        
        @keyframes spin {
            to { transform: rotate(360deg); }
        }
        
        /* Recommendation Grid */
        .recommendation-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(350px, 1fr));
            gap: 20px;
            margin: 25px 0;
        }
        
        .recommendation-card {
            background: var(--card-bg);
            border: 2px solid var(--border-color);
            border-radius: 12px;
            padding: 20px;
            transition: all 0.3s ease;
            cursor: pointer;
        }
        
        .recommendation-card:hover {
            border-color: var(--primary-color);
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
        }
        
        .recommendation-card.selected {
            border-color: var(--primary-color);
            background: rgba(220, 38, 38, 0.05);
        }
        
        .match-score {
            float: right;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 6px 14px;
            border-radius: 20px;
            font-size: 13px;
            font-weight: 600;
        }
        
        /* Volunteer List in Recommendation */
        .volunteer-list {
            margin-top: 15px;
            border-top: 1px solid var(--border-color);
            padding-top: 15px;
        }
        
        .volunteer-item {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 8px;
            border-radius: 8px;
            transition: background-color 0.2s;
        }
        
        .volunteer-item:hover {
            background: var(--gray-100);
        }
        
        .volunteer-avatar {
            width: 32px;
            height: 32px;
            border-radius: 50%;
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            font-size: 14px;
        }
        
        .volunteer-info {
            flex: 1;
        }
        
        .volunteer-name {
            font-weight: 600;
            font-size: 14px;
        }
        
        .volunteer-contact {
            font-size: 12px;
            color: var(--text-light);
        }
        
        /* Manual Selection Styles */
        .manual-selection-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(350px, 1fr));
            gap: 20px;
            margin: 25px 0;
        }
        
        .unit-card {
            background: var(--card-bg);
            border: 2px solid var(--border-color);
            border-radius: 12px;
            padding: 20px;
            transition: all 0.3s ease;
        }
        
        .unit-card:hover {
            border-color: var(--primary-color);
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
        }
        
        .vehicle-selection {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
            gap: 15px;
            margin-top: 15px;
        }
        
        .vehicle-option {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 12px;
            border: 1px solid var(--border-color);
            border-radius: 8px;
            transition: all 0.3s ease;
        }
        
        .vehicle-option:hover {
            border-color: var(--primary-color);
            background: rgba(220, 38, 38, 0.05);
        }
        
        /* Empty States */
        .no-data {
            text-align: center;
            padding: 60px 20px;
            color: var(--text-light);
        }
        
        .no-data i {
            font-size: 64px;
            margin-bottom: 20px;
            display: block;
            opacity: 0.5;
        }
        
        .no-data p {
            margin: 0;
            font-size: 18px;
        }
        
        .no-data .subtext {
            font-size: 14px;
            margin-top: 8px;
        }
        
        /* Notification Container */
        #notification-container {
            position: fixed;
            top: 20px;
            right: 20px;
            z-index: 9999;
        }
        
        /* User Profile Dropdown */
        .user-profile {
            position: relative;
        }
        
        .user-profile-dropdown {
            display: none;
            position: absolute;
            top: 100%;
            right: 0;
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: 10px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.15);
            min-width: 200px;
            z-index: 1000;
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
            transition: background-color 0.2s;
        }
        
        .dropdown-item:hover {
            background: var(--gray-100);
        }
        
        .dropdown-divider {
            height: 1px;
            background: var(--border-color);
            margin: 5px 0;
        }
        
        /* Notification Dropdown */
        .notification-bell {
            position: relative;
        }
        
        .notification-dropdown {
            display: none;
            position: absolute;
            top: 100%;
            right: 0;
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: 10px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.15);
            width: 350px;
            max-height: 400px;
            overflow-y: auto;
            z-index: 1000;
            margin-top: 10px;
        }
        
        .notification-dropdown.show {
            display: block;
        }
        
        .notification-header {
            padding: 15px;
            border-bottom: 1px solid var(--border-color);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .notification-title {
            margin: 0;
            font-size: 16px;
            font-weight: 600;
        }
        
        .notification-clear {
            background: none;
            border: none;
            color: var(--primary-color);
            cursor: pointer;
            font-size: 13px;
            font-weight: 600;
        }
        
        .notification-list {
            padding: 10px;
        }
        
        .notification-empty {
            text-align: center;
            padding: 30px;
            color: var(--text-light);
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
            font-size: 11px;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        /* Sound Toggle */
        .sound-toggle {
            position: fixed;
            bottom: 20px;
            right: 20px;
            width: 50px;
            height: 50px;
            border-radius: 50%;
            background: var(--primary-color);
            color: white;
            border: none;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
            z-index: 100;
            box-shadow: 0 4px 15px rgba(220, 38, 38, 0.3);
            transition: all 0.3s ease;
        }
        
        .sound-toggle:hover {
            transform: scale(1.1);
            box-shadow: 0 6px 20px rgba(220, 38, 38, 0.4);
        }
        
        /* View/Edit Modal Specific */
        .dispatch-details {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        
        .detail-section {
            background: var(--gray-100);
            padding: 15px;
            border-radius: 8px;
        }
        
        .detail-section h4 {
            margin-top: 0;
            margin-bottom: 10px;
            font-size: 16px;
            color: var(--text-color);
        }
        
        .detail-item {
            display: flex;
            justify-content: space-between;
            margin-bottom: 8px;
            font-size: 14px;
        }
        
        .detail-label {
            font-weight: 600;
            color: var(--text-color);
        }
        
        .detail-value {
            color: var(--text-light);
        }
        
        /* Responsive */
        @media (max-width: 1200px) {
            .dashboard-container {
                padding: 0 25px 30px;
            }
        }
        
        @media (max-width: 992px) {
            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }
            
            .recommendation-grid,
            .manual-selection-grid {
                grid-template-columns: 1fr;
            }
            
            .dashboard-header {
                padding: 40px 25px 30px;
            }
            
            .dashboard-header .header-content h1 {
                font-size: 32px;
            }
        }
        
        @media (max-width: 768px) {
            .stats-grid {
                grid-template-columns: 1fr;
            }
            
            .filters-container {
                flex-direction: column;
                align-items: stretch;
            }
            
            .filter-select {
                width: 100%;
            }
            
            .action-buttons {
                flex-direction: column;
            }
            
            .btn {
                width: 100%;
                justify-content: center;
            }
            
            .pagination-container {
                flex-direction: column;
                text-align: center;
            }
            
            .pagination {
                justify-content: center;
                flex-wrap: wrap;
            }
            
            .incident-table {
                font-size: 14px;
            }
            
            .incident-table th,
            .incident-table td {
                padding: 10px;
            }
        }
        
        @media (max-width: 480px) {
            .dashboard-container {
                padding: 0 15px 20px;
            }
            
            .dashboard-header {
                padding: 30px 20px 20px;
                border-radius: 0 0 20px 20px;
            }
            
            .dashboard-header .header-content h1 {
                font-size: 24px;
            }
            
            .modal-content {
                width: 95%;
                margin: 10px;
            }
            
            .notification-dropdown {
                width: 300px;
            }
        }
    </style>
</head>
<body>
    <!-- Sound Toggle Button -->
    <button class="sound-toggle" id="sound-toggle" title="Toggle notification sound">
        <i class='bx bx-bell'></i>
    </button>

    <!-- AI Recommendation Modal -->
    <div class="modal" id="aiModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class='bx bx-sparkles'></i> AI Unit Recommendation</h3>
                <button onclick="closeModal()">&times;</button>
            </div>
            <div class="modal-body" id="aiModalBody">
                <!-- Content loaded dynamically -->
            </div>
        </div>
    </div>
    
    <!-- Manual Selection Modal -->
    <div class="modal" id="manualModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class='bx bx-select-multiple'></i> Manual Unit Selection</h3>
                <button onclick="closeManualModal()">&times;</button>
            </div>
            <div class="modal-body" id="manualModalBody">
                <!-- Content loaded dynamically -->
            </div>
        </div>
    </div>
    
    <!-- View Dispatch Modal -->
    <div class="modal" id="viewDispatchModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class='bx bx-show'></i> Dispatch Details</h3>
                <button onclick="closeViewModal()">&times;</button>
            </div>
            <div class="modal-body" id="viewDispatchBody">
                <!-- Content loaded dynamically -->
            </div>
        </div>
    </div>
    
    <!-- Edit Dispatch Modal -->
    <div class="modal" id="editDispatchModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class='bx bx-edit'></i> Edit Dispatch</h3>
                <button onclick="closeEditModal()">&times;</button>
            </div>
            <div class="modal-body" id="editDispatchBody">
                <!-- Content loaded dynamically -->
            </div>
        </div>
    </div>
    
    <!-- Notification Container -->
    <div id="notification-container"></div>
    
    <div class="container">
        <!-- Sidebar (same as reference) -->
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
                    <div id="dispatch" class="submenu active">
                        <a href="select_unit.php" class="submenu-item active">Select Unit</a>
                        <a href="send_dispatch.php" class="submenu-item">Send Dispatch Info</a>
                        
                        <a href="track_status.php" class="submenu-item">Track Status</a>
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
                      <a href="../vra/review_data.php" class="submenu-item">Review/Aprroved Data Management</a>
                        <a href="../vra/approve_applications.php" class="submenu-item">Assign Volunteers</a>
                        <a href="../vra/view_availability.php" class="submenu-item">View Availability</a>
                        <a href="../vra/remove_volunteers.php" class="submenu-item">Remove Volunteers</a>
                        <a href="../vra/toggle_volunteer_registration.php" class="submenu-item">Open/Close Registration</a>
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
                        <a href="../ri/log_usage.php" class="submenu-item">Log Usage</a>
                        <a href="../ri/report_damages.php" class="submenu-item">Report Damages</a>
                        <a href="../ri/request_supplies.php" class="submenu-item">Request Supplies</a>
                        <a href="../ri/tag_resources.php" class="submenu-item">Tag Resources</a>
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
                         <a href="../sds/create_shifts.php" class="submenu-item">Create Shifts</a>
                        <a href="../sds/view_shifts.php" class="submenu-item">View Shifts</a>
                        <a href="../sds/confirm_availability.php" class="submenu-item">Confirm Availability</a>
                        <a href="../sds/request_change.php" class="submenu-item">Request Change</a>
                        <a href="../sds/mark_attendance.php" class="submenu-item">Mark Attendance</a>
                       
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
                          <a href="../tc/view_available_training.php" class="submenu-item">View Available Training</a>
                        <a href="../tc/submit_training.php" class="submenu-item">Submit Training</a>
                        
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
                        <a href="../il/conduct_inspections.php" class="submenu-item">Conduct Inspections</a>
                        <a href="../il/submit_findings.php" class="submenu-item">Submit Findings</a>
                       
                        <a href="../il/tag_violations.php" class="submenu-item">Tag Violations</a>
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
                        <a href="../pi/post_incident_reporting.php" class="submenu-item">Incident Reports</a>
                        
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
            <!-- Header (same as reference) -->
            <div class="header">
                <div class="header-content">
                    <div class="search-container">
                        <div class="search-box">
                            <svg class="search-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path>
                            </svg>
                            <input type="text" placeholder="Search..." class="search-input" id="search-input">
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
                                <i class='bx bx-bell'></i>
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
                <div class="dashboard-container">
                    <!-- Dashboard Header -->
                    <div class="dashboard-header">
                        <div class="header-content">
                            <h1><i class='bx bx-radar'></i> Fire & Rescue Dispatch Coordination Center</h1>
                            <p>AI-Powered Unit Selection & Emergency Response Dispatch for Fire and Rescue Incidents</p>
                        </div>
                        <div class="header-actions">
                            <button class="secondary-button" onclick="refreshData()">
                                <i class='bx bx-refresh'></i> Refresh Data
                            </button>
                        </div>
                    </div>
                    
                    <!-- Statistics -->
                    <div class="stats-grid">
                        <div class="stat-card">
                            <div class="stat-icon" style="background: rgba(239, 68, 68, 0.1); color: var(--primary-color);">
                                <i class='bx bx-alarm-exclamation'></i>
                            </div>
                            <div class="stat-content">
                                <div class="value"><?php echo $stats['pending_incidents'] ?? 0; ?></div>
                                <div class="label">Fire & Rescue Incidents for Dispatch</div>
                            </div>
                        </div>
                        <div class="stat-card">
                            <div class="stat-icon" style="background: rgba(59, 130, 246, 0.1); color: #3b82f6;">
                                <i class='bx bx-check-circle'></i>
                            </div>
                            <div class="stat-content">
                                <div class="value"><?php echo $stats['responded_today'] ?? 0; ?></div>
                                <div class="label">Fire & Rescue Responded Today</div>
                            </div>
                        </div>
                        <div class="stat-card">
                            <div class="stat-icon" style="background: rgba(16, 185, 129, 0.1); color: #10b981;">
                                <i class='bx bx-building'></i>
                            </div>
                            <div class="stat-content">
                                <div class="value"><?php echo $stats['available_units'] ?? 0; ?></div>
                                <div class="label">Available Fire & Rescue Units</div>
                            </div>
                        </div>
                        <div class="stat-card">
                            <div class="stat-icon" style="background: rgba(168, 85, 247, 0.1); color: #a855f7;">
                                <i class='bx bx-car'></i>
                            </div>
                            <div class="stat-content">
                                <div class="value"><?php echo $stats['available_vehicles'] ?? 0; ?></div>
                                <div class="label">Available Fire & Rescue Vehicles</div>
                            </div>
                        </div>
                    </div>
                    
                   <!-- Recent Dispatches with Pagination -->
<div class="dispatches-container">
    <div class="dispatches-header">
        <h3><i class='bx bx-history'></i> Recent Fire & Rescue Dispatches (Last 24 Hours)</h3>
        <p>Track active and completed fire and rescue emergency responses</p>
    </div>
    
    <?php if (count($dispatches_for_page) > 0): ?>
        <?php foreach ($dispatches_for_page as $dispatch): ?>
            <?php 
            // Get vehicles for this dispatch
            $vehicles_query = "SELECT vehicles_json FROM dispatch_incidents WHERE id = ?";
            $vehicles_stmt = $pdo->prepare($vehicles_query);
            $vehicles_stmt->execute([$dispatch['id']]);
            $vehicles_data = $vehicles_stmt->fetch();
            
            $vehicle_count = 0;
            $vehicle_names = [];
            if ($vehicles_data && $vehicles_data['vehicles_json']) {
                $vehicles = json_decode($vehicles_data['vehicles_json'], true);
                if (is_array($vehicles)) {
                    $vehicle_count = count($vehicles);
                    foreach ($vehicles as $vehicle) {
                        if (isset($vehicle['vehicle_name'])) {
                            $vehicle_names[] = $vehicle['vehicle_name'];
                        }
                    }
                }
            }
            
            // Get incident details for type determination
            $incident_query = "SELECT emergency_type, rescue_category, is_fire_rescue_related FROM api_incidents WHERE id = ?";
            $incident_stmt = $pdo->prepare($incident_query);
            $incident_stmt->execute([$dispatch['incident_id']]);
            $incident_details = $incident_stmt->fetch();
            
            // Determine incident type icon and label
            $incident_type = isset($incident_details['emergency_type']) ? strtolower($incident_details['emergency_type']) : '';
            $rescue_cat = isset($incident_details['rescue_category']) ? strtolower(str_replace('_', ' ', $incident_details['rescue_category'])) : '';
            $is_fire_rescue = isset($incident_details['is_fire_rescue_related']) ? $incident_details['is_fire_rescue_related'] : 0;
            
            $type_label = '';
            $type_icon = '';
            
            if ($incident_type === 'fire' || $is_fire_rescue == 1) {
                $type_label = 'Fire';
                $type_icon = 'bx bx-fire';
            } else if ($incident_type === 'rescue' || !empty($rescue_cat)) {
                $type_label = 'Rescue';
                $type_icon = 'bx bx-first-aid';
                if (!empty($rescue_cat)) {
                    $type_label .= ' (' . ucwords($rescue_cat) . ')';
                }
            } else {
                $type_label = ucfirst($incident_type ?: 'Other');
                $type_icon = 'bx bx-help-circle';
            }
            ?>
            <div class="dispatch-item">
                <div class="dispatch-item-header">
                    <div>
                        <div class="dispatch-item-title">
                            <i class='<?php echo $type_icon; ?>' style="margin-right: 5px; color: var(--primary-color);"></i>
                            <?php echo htmlspecialchars($dispatch['title']); ?>
                        </div>
                        <div class="dispatch-item-location">
                            <i class='bx bx-map'></i> <?php echo htmlspecialchars($dispatch['location']); ?>
                        </div>
                    </div>
                    <div>
                        <span class="status-badge status-<?php echo strtolower($dispatch['status'] ?? 'pending'); ?>">
                            <?php echo ucfirst($dispatch['status'] ?? 'Pending'); ?>
                        </span>
                        <div style="font-size: 10px; margin-top: 2px; color: var(--text-light);">
                            <?php echo $type_label; ?>
                        </div>
                    </div>
                </div>
                <div class="dispatch-item-details">
                    <div class="dispatch-item-unit">
                        <i class='bx bx-building'></i>
                        <span><?php echo htmlspecialchars($dispatch['unit_name']); ?> (<?php echo htmlspecialchars($dispatch['unit_code']); ?>)</span>
                    </div>
                    <?php if ($vehicle_count > 0): ?>
                        <div class="dispatch-item-vehicles">
                            <i class='bx bx-car'></i>
                            <span><?php echo $vehicle_count; ?> vehicle<?php echo $vehicle_count !== 1 ? 's' : ''; ?></span>
                        </div>
                    <?php endif; ?>
                    <div class="dispatch-item-time">
                        <?php 
                        $dispatched = new DateTime($dispatch['dispatched_at']);
                        echo $dispatched->format('H:i');
                        ?>
                    </div>
                </div>
                <div class="dispatch-actions">
                    <button class="action-btn view" onclick="viewDispatch(<?php echo $dispatch['id']; ?>)">
                        <i class='bx bx-show'></i> View
                    </button>
                    <?php if ($dispatch['status'] === 'pending' || $dispatch['status'] === 'dispatched'): ?>
                        <button class="action-btn edit" onclick="editDispatch(<?php echo $dispatch['id']; ?>)">
                            <i class='bx bx-edit'></i> Edit
                        </button>
                    <?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>
        
        <!-- Pagination for dispatches -->
        <?php if ($total_dispatch_pages > 1): ?>
            <div class="pagination-container">
                <div class="pagination-info">
                    Showing <?php echo min($dispatch_per_page, $total_dispatches - $dispatch_offset); ?> of <?php echo $total_dispatches; ?> dispatches
                </div>
                <div class="pagination">
                    <?php if ($dispatch_page > 1): ?>
                        <a href="?dispatch_page=1&page=<?php echo $current_page; ?>&severity=<?php echo $severity_filter; ?>&type=<?php echo $type_filter; ?>" class="page-link">
                            <i class='bx bx-chevrons-left'></i>
                        </a>
                        <a href="?dispatch_page=<?php echo $dispatch_page - 1; ?>&page=<?php echo $current_page; ?>&severity=<?php echo $severity_filter; ?>&type=<?php echo $type_filter; ?>" class="page-link">
                            <i class='bx bx-chevron-left'></i>
                        </a>
                    <?php else: ?>
                        <span class="page-item disabled">
                            <i class='bx bx-chevrons-left'></i>
                        </span>
                        <span class="page-item disabled">
                            <i class='bx bx-chevron-left'></i>
                        </span>
                    <?php endif; ?>
                    
                    <?php 
                    $start_page = max(1, $dispatch_page - 2);
                    $end_page = min($total_dispatch_pages, $dispatch_page + 2);
                    
                    if ($start_page > 1) {
                        echo '<span class="page-item">...</span>';
                    }
                    
                    for ($i = $start_page; $i <= $end_page; $i++): ?>
                        <a href="?dispatch_page=<?php echo $i; ?>&page=<?php echo $current_page; ?>&severity=<?php echo $severity_filter; ?>&type=<?php echo $type_filter; ?>" 
                           class="page-link <?php echo $i == $dispatch_page ? 'active' : ''; ?>">
                            <?php echo $i; ?>
                        </a>
                    <?php endfor; ?>
                    
                    <?php if ($end_page < $total_dispatch_pages): ?>
                        <span class="page-item">...</span>
                    <?php endif; ?>
                    
                    <?php if ($dispatch_page < $total_dispatch_pages): ?>
                        <a href="?dispatch_page=<?php echo $dispatch_page + 1; ?>&page=<?php echo $current_page; ?>&severity=<?php echo $severity_filter; ?>&type=<?php echo $type_filter; ?>" class="page-link">
                            <i class='bx bx-chevron-right'></i>
                        </a>
                        <a href="?dispatch_page=<?php echo $total_dispatch_pages; ?>&page=<?php echo $current_page; ?>&severity=<?php echo $severity_filter; ?>&type=<?php echo $type_filter; ?>" class="page-link">
                            <i class='bx bx-chevrons-right'></i>
                        </a>
                    <?php else: ?>
                        <span class="page-item disabled">
                            <i class='bx bx-chevron-right'></i>
                        </span>
                        <span class="page-item disabled">
                            <i class='bx bx-chevrons-right'></i>
                        </span>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>
    <?php else: ?>
        <div class="no-data">
            <i class='bx bx-info-circle'></i>
            <p>No recent fire or rescue dispatches found</p>
        </div>
    <?php endif; ?>
</div>
                    
                    <!-- Incidents for Dispatch with Filtering and Pagination -->
                    <div class="incident-list-container">
                        <div class="incident-header">
                            <h3><i class='bx bx-list-ul'></i> Fire & Rescue Incidents for Dispatch</h3>
                            <p>Select fire or rescue incidents for AI-powered or manual unit assignment</p>
                            <div class="filters-container">
                                <select class="filter-select" id="severity-filter">
                                    <option value="all" <?php echo $severity_filter === 'all' ? 'selected' : ''; ?>>All Severities</option>
                                    <option value="critical" <?php echo $severity_filter === 'critical' ? 'selected' : ''; ?>>Critical</option>
                                    <option value="high" <?php echo $severity_filter === 'high' ? 'selected' : ''; ?>>High</option>
                                    <option value="medium" <?php echo $severity_filter === 'medium' ? 'selected' : ''; ?>>Medium</option>
                                    <option value="low" <?php echo $severity_filter === 'low' ? 'selected' : ''; ?>>Low</option>
                                </select>
                                
                                <select class="filter-select" id="type-filter">
                                    <option value="all" <?php echo $type_filter === 'all' ? 'selected' : ''; ?>>All Types</option>
                                    <option value="fire" <?php echo $type_filter === 'fire' ? 'selected' : ''; ?>>Fire</option>
                                    <option value="rescue" <?php echo $type_filter === 'rescue' ? 'selected' : ''; ?>>Rescue</option>
                                    <option value="medical" <?php echo $type_filter === 'medical' ? 'selected' : ''; ?>>Medical</option>
                                    <option value="other" <?php echo $type_filter === 'other' ? 'selected' : ''; ?>>Other</option>
                                </select>
                                
                                <button class="btn btn-secondary" onclick="applyFilters()">
                                    <i class='bx bx-filter-alt'></i> Apply Filters
                                </button>
                                
                                <?php if (count($incidents_for_dispatch) > 0): ?>
                                    <button class="btn btn-ai" onclick="getAllAIRecommendations()">
                                        <i class='bx bx-sparkles'></i> AI Analyze All
                                    </button>
                                <?php endif; ?>
                            </div>
                        </div>
                        
                        <div class="incident-table-container">
                            <?php if (count($incidents_for_dispatch) > 0): ?>
                                <table class="incident-table">
                                    <thead>
                                        <tr>
                                            <th>Incident</th>
                                            <th>Type</th>
                                            <th>Location</th>
                                            <th>Severity</th>
                                            <th>Status</th>
                                            <th>Reported</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($incidents_for_dispatch as $incident): ?>
                                            <?php 
                                            // Determine incident type
                                            $type = $incident['emergency_type'];
                                            $rescue_cat = $incident['rescue_category'] ? strtolower(str_replace('_', ' ', $incident['rescue_category'])) : '';
                                            
                                            // Set icon and label based on type
                                            if ($type === 'fire' || $incident['is_fire_rescue_related'] == 1) {
                                                $icon = 'bx bx-fire';
                                                $type_label = 'Fire';
                                                $type_color = 'var(--primary-color)';
                                            } else if ($type === 'rescue' || !empty($rescue_cat)) {
                                                $icon = 'bx bx-first-aid';
                                                $type_label = 'Rescue';
                                                $type_color = '#3b82f6';
                                                if (!empty($rescue_cat)) {
                                                    $type_label .= ' (' . ucwords($rescue_cat) . ')';
                                                }
                                            } else if ($type === 'medical') {
                                                $icon = 'bx bx-plus-medical';
                                                $type_label = 'Medical';
                                                $type_color = '#10b981';
                                            } else {
                                                $icon = 'bx bx-help-circle';
                                                $type_label = ucfirst($type ?: 'Other');
                                                $type_color = 'var(--text-light)';
                                            }
                                            ?>
                                            <tr data-incident-id="<?php echo $incident['id']; ?>">
                                                <td>
                                                    <strong><?php echo htmlspecialchars($incident['title']); ?></strong>
                                                    <div style="font-size: 12px; color: var(--text-light); margin-top: 2px;">
                                                        <?php echo htmlspecialchars($incident['caller_name']); ?> - <?php echo htmlspecialchars($incident['caller_phone']); ?>
                                                    </div>
                                                </td>
                                                <td>
                                                    <i class='<?php echo $icon; ?>' style="color: <?php echo $type_color; ?>;"></i>
                                                    <?php echo $type_label; ?>
                                                </td>
                                                <td><?php echo htmlspecialchars($incident['location']); ?></td>
                                                <td>
                                                    <span class="severity-badge severity-<?php echo strtolower($incident['severity']); ?>">
                                                        <?php echo ucfirst($incident['severity']); ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <span class="dispatch-status-badge status-<?php echo strtolower($incident['dispatch_status'] ?? 'for_dispatch'); ?>">
                                                        <?php echo ucfirst(str_replace('_', ' ', $incident['dispatch_status'] ?? 'for_dispatch')); ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <?php 
                                                    $created = new DateTime($incident['created_at']);
                                                    echo $created->format('M d, H:i');
                                                    ?>
                                                </td>
                                                <td>
                                                    <div class="action-buttons">
                                                        <button class="btn btn-ai" onclick="getAIRecommendation(<?php echo $incident['id']; ?>)">
                                                            <i class='bx bx-sparkles'></i> AI Select
                                                        </button>
                                                        <button class="btn btn-primary" onclick="manualSelect(<?php echo $incident['id']; ?>)">
                                                            <i class='bx bx-select-multiple'></i> Manual
                                                        </button>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            <?php else: ?>
                                <div class="no-data">
                                    <i class='bx bx-check-circle'></i>
                                    <p>No fire or rescue incidents for dispatch at the moment</p>
                                    <p class="subtext">All fire and rescue incidents have been assigned or are being handled</p>
                                </div>
                            <?php endif; ?>
                        </div>
                        
                        <!-- Pagination -->
                        <?php if ($total_filtered_pages > 1): ?>
                            <div class="pagination-container">
                                <div class="pagination-info">
                                    Showing <?php echo min($incidents_per_page, $total_filtered_incidents - $offset); ?> of <?php echo $total_filtered_incidents; ?> incidents
                                    <?php if ($severity_filter !== 'all' || $type_filter !== 'all'): ?>
                                        (filtered)
                                    <?php endif; ?>
                                </div>
                                <div class="pagination">
                                    <?php if ($current_page > 1): ?>
                                        <a href="?page=1&dispatch_page=<?php echo $dispatch_page; ?>&severity=<?php echo $severity_filter; ?>&type=<?php echo $type_filter; ?>" class="page-link">
                                            <i class='bx bx-chevrons-left'></i>
                                        </a>
                                        <a href="?page=<?php echo $current_page - 1; ?>&dispatch_page=<?php echo $dispatch_page; ?>&severity=<?php echo $severity_filter; ?>&type=<?php echo $type_filter; ?>" class="page-link">
                                            <i class='bx bx-chevron-left'></i>
                                        </a>
                                    <?php else: ?>
                                        <span class="page-item disabled">
                                            <i class='bx bx-chevrons-left'></i>
                                        </span>
                                        <span class="page-item disabled">
                                            <i class='bx bx-chevron-left'></i>
                                        </span>
                                    <?php endif; ?>
                                    
                                    <?php 
                                    $start_page = max(1, $current_page - 2);
                                    $end_page = min($total_filtered_pages, $current_page + 2);
                                    
                                    if ($start_page > 1) {
                                        echo '<span class="page-item">...</span>';
                                    }
                                    
                                    for ($i = $start_page; $i <= $end_page; $i++): ?>
                                        <a href="?page=<?php echo $i; ?>&dispatch_page=<?php echo $dispatch_page; ?>&severity=<?php echo $severity_filter; ?>&type=<?php echo $type_filter; ?>" 
                                           class="page-link <?php echo $i == $current_page ? 'active' : ''; ?>">
                                            <?php echo $i; ?>
                                        </a>
                                    <?php endfor; ?>
                                    
                                    <?php if ($end_page < $total_filtered_pages): ?>
                                        <span class="page-item">...</span>
                                    <?php endif; ?>
                                    
                                    <?php if ($current_page < $total_filtered_pages): ?>
                                        <a href="?page=<?php echo $current_page + 1; ?>&dispatch_page=<?php echo $dispatch_page; ?>&severity=<?php echo $severity_filter; ?>&type=<?php echo $type_filter; ?>" class="page-link">
                                            <i class='bx bx-chevron-right'></i>
                                        </a>
                                        <a href="?page=<?php echo $total_filtered_pages; ?>&dispatch_page=<?php echo $dispatch_page; ?>&severity=<?php echo $severity_filter; ?>&type=<?php echo $type_filter; ?>" class="page-link">
                                            <i class='bx bx-chevrons-right'></i>
                                        </a>
                                    <?php else: ?>
                                        <span class="page-item disabled">
                                            <i class='bx bx-chevron-right'></i>
                                        </span>
                                        <span class="page-item disabled">
                                            <i class='bx bx-chevrons-right'></i>
                                        </span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <script>
        let currentIncidentId = null;
        let selectedUnit = null;
        let selectedVehicles = [];
        
        // Get AI Recommendation for an incident
        function getAIRecommendation(incidentId) {
            currentIncidentId = incidentId;
            selectedUnit = null;
            selectedVehicles = [];
            
            const modal = document.getElementById('aiModal');
            const modalBody = document.getElementById('aiModalBody');
            
            modal.classList.add('active');
            modalBody.innerHTML = `
                <div class="ai-loading">
                    <div class="ai-spinner"></div>
                    <p>Analyzing incident and available resources...</p>
                    <p style="font-size: 12px; color: var(--text-light); margin-top: 10px;">
                        Using AI to match incident requirements with optimal fire & rescue units and vehicles
                    </p>
                </div>
            `;
            
            // Fetch AI recommendation
            fetch('ai_recommendation.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({ incident_id: incidentId })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    displayAIRecommendation(data);
                } else {
                    showError(data.message || 'Failed to get AI recommendation');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showError('Failed to connect to AI service');
            });
        }
        
        // Display AI Recommendation
        function displayAIRecommendation(data) {
            const modalBody = document.getElementById('aiModalBody');
            
            modalBody.innerHTML = `
                <div style="margin-bottom: 20px;">
                    <h4 style="font-size: 18px; margin-bottom: 15px;">Fire & Rescue Incident Analysis</h4>
                    <div style="background: rgba(220, 38, 38, 0.05); padding: 20px; border-radius: 10px; margin-bottom: 15px;">
                        <strong>${data.incident.title}</strong>
                        <div style="font-size: 14px; margin-top: 5px;">
                            <i class='bx bx-map'></i> ${data.incident.location}
                        </div>
                        <div style="font-size: 14px;">
                            <i class='bx bx-alarm-exclamation'></i> Severity: 
                            <span class="severity-badge severity-${data.incident.severity.toLowerCase()}">
                                ${data.incident.severity}
                            </span>
                        </div>
                        <div style="font-size: 12px; color: var(--text-light); margin-top: 5px;">
                            ${data.incident.description}
                        </div>
                    </div>
                    
                    <h4 style="font-size: 18px; margin-bottom: 15px;">AI Analysis</h4>
                    <div style="background: rgba(102, 126, 234, 0.05); padding: 20px; border-radius: 10px; margin-bottom: 15px;">
                        <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 10px;">
                            <i class='bx bx-chip' style="color: #667eea; font-size: 20px;"></i>
                            <strong>AI Recommendation Engine</strong>
                        </div>
                        <p style="margin-bottom: 10px;"><strong>Reasoning:</strong> ${data.ai_reasoning}</p>
                        <p><strong>Confidence:</strong> ${data.ai_confidence}%</p>
                    </div>
                </div>
                
                <h4 style="font-size: 18px; margin-bottom: 15px;">Recommended Fire & Rescue Units</h4>
                <p style="color: var(--text-light); font-size: 14px; margin-bottom: 15px;">
                    Select a unit and vehicles to suggest for dispatch:
                </p>
                <div class="recommendation-grid" id="recommendationGrid">
                    ${data.recommendations.map((rec, index) => `
                        <div class="recommendation-card" onclick="selectAIRecommendation(${index}, ${rec.unit_id})" id="rec-${index}">
                            <div style="display: flex; justify-content: space-between; align-items: start; margin-bottom: 10px;">
                                <div>
                                    <h5 style="margin: 0; font-size: 16px;">${rec.unit_name}</h5>
                                    <div style="font-size: 12px; color: var(--text-light);">
                                        ${rec.unit_code} • ${rec.unit_type}
                                    </div>
                                </div>
                                <span class="match-score">${rec.match_score}% Match</span>
                            </div>
                            <div style="font-size: 13px; margin-bottom: 10px;">
                                <div><i class='bx bx-map'></i> ${rec.location}</div>
                                <div><i class='bx bx-group'></i> ${rec.current_count}/${rec.capacity} volunteers</div>
                            </div>
                            <div style="font-size: 12px; color: var(--text-light); margin-bottom: 10px;">
                                <strong>Why this unit:</strong> ${rec.reasoning}
                            </div>
                            
                            <!-- Volunteers in this unit -->
                            <div class="volunteer-list">
                                <strong>Volunteers in Unit:</strong>
                                <div id="volunteers-${index}" style="margin-top: 10px;">
                                    <div style="text-align: center; padding: 10px; color: var(--text-light);">
                                        <div class="ai-spinner" style="width: 20px; height: 20px; margin: 0 auto 5px;"></div>
                                        <div style="font-size: 11px;">Loading volunteers...</div>
                                    </div>
                                </div>
                            </div>
                            
                            ${rec.vehicles && rec.vehicles.length > 0 ? `
                                <div class="vehicle-list" style="margin-top: 15px;">
                                    <strong>Available Fire & Rescue Vehicles:</strong>
                                    <div id="vehicle-select-${index}" style="margin-top: 10px;">
                                        ${rec.vehicles.map((vehicle, vIndex) => `
                                            <div class="vehicle-option">
                                                <input type="checkbox" 
                                                       id="vehicle-${index}-${vIndex}" 
                                                       class="vehicle-checkbox"
                                                       data-vehicle-id="${vehicle.id}"
                                                       data-vehicle-name="${vehicle.vehicle_name}"
                                                       data-vehicle-type="${vehicle.type}"
                                                       data-available="${vehicle.available || 1}"
                                                       data-status="${vehicle.status || 'Available'}"
                                                       onchange="toggleVehicle(${index}, ${vehicle.id}, this.checked)">
                                                <label for="vehicle-${index}-${vIndex}" style="flex: 1; cursor: pointer;">
                                                    <div><strong>${vehicle.vehicle_name}</strong></div>
                                                    <div style="font-size: 11px; color: var(--text-light);">${vehicle.type}</div>
                                                </label>
                                            </div>
                                        `).join('')}
                                    </div>
                                </div>
                            ` : '<div style="color: var(--text-light); font-size: 12px; margin-top: 15px;">No vehicles available for this unit</div>'}
                        </div>
                    `).join('')}
                </div>
                
                <div style="margin-top: 20px; padding-top: 20px; border-top: 1px solid var(--border-color);">
                    <button class="btn btn-success" style="width: 100%; padding: 14px;" onclick="saveSuggestion()" id="saveSuggestionBtn" disabled>
                        <i class='bx bx-save'></i> Save Suggestion for Emergency Response
                    </button>
                    <p style="text-align: center; font-size: 12px; color: var(--text-light); margin-top: 10px;">
                        This suggestion will be sent to the Emergency Response team for approval and dispatch
                    </p>
                </div>
            `;
            
            // Load volunteers for each recommendation
            data.recommendations.forEach((rec, index) => {
                loadVolunteersForUnit(rec.unit_id, index);
            });
        }
        
        // Load volunteers for a unit
        function loadVolunteersForUnit(unitId, index) {
            fetch('get_volunteers_for_unit.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({ unit_id: unitId })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    const volunteersContainer = document.getElementById(`volunteers-${index}`);
                    if (data.volunteers.length > 0) {
                        volunteersContainer.innerHTML = data.volunteers.map(volunteer => `
                            <div class="volunteer-item">
                                <div class="volunteer-avatar">
                                    ${volunteer.full_name ? volunteer.full_name.charAt(0).toUpperCase() : 'V'}
                                </div>
                                <div class="volunteer-info">
                                    <div class="volunteer-name">${volunteer.full_name || 'Unknown Volunteer'}</div>
                                    <div class="volunteer-contact">
                                        ${volunteer.contact_number || ''}
                                    </div>
                                </div>
                            </div>
                        `).join('');
                    } else {
                        volunteersContainer.innerHTML = `
                            <div style="text-align: center; padding: 10px; color: var(--text-light); font-size: 12px;">
                                <i class='bx bx-user-x'></i>
                                <div>No volunteers assigned to this unit</div>
                            </div>
                        `;
                    }
                }
            })
            .catch(error => {
                console.error('Error loading volunteers:', error);
            });
        }
        
        // Select an AI recommendation
        function selectAIRecommendation(index, unitId) {
            console.log('Selecting AI recommendation:', index, unitId);
            
            // Remove previous selection
            document.querySelectorAll('.recommendation-card').forEach(card => {
                card.classList.remove('selected');
            });
            
            // Add selection to clicked card
            const card = document.getElementById(`rec-${index}`);
            card.classList.add('selected');
            
            // Get recommendation data
            fetch('ai_recommendation.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({ 
                    incident_id: currentIncidentId,
                    get_recommendation: index 
                })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success && data.selected_recommendation) {
                    selectedUnit = data.selected_recommendation;
                    selectedVehicles = []; // Reset vehicles
                    
                    // Get checked vehicles from this card
                    const vehicleCheckboxes = card.querySelectorAll('.vehicle-checkbox:checked');
                    selectedVehicles = Array.from(vehicleCheckboxes).map(cb => ({
                        id: parseInt(cb.dataset.vehicleId),
                        vehicle_name: cb.dataset.vehicleName,
                        type: cb.dataset.vehicleType,
                        available: parseInt(cb.dataset.available) || 1,
                        status: cb.dataset.status || 'Available'
                    }));
                    
                    console.log('Selected unit:', selectedUnit);
                    console.log('Selected vehicles:', selectedVehicles);
                    
                    // Enable save button
                    document.getElementById('saveSuggestionBtn').disabled = false;
                } else {
                    console.error('Failed to get recommendation data:', data);
                }
            })
            .catch(error => {
                console.error('Error getting recommendation:', error);
            });
        }
        
        // Toggle vehicle selection
        function toggleVehicle(recommendationIndex, vehicleId, isChecked) {
            if (!selectedUnit) return;
            
            if (isChecked) {
                // Find the vehicle checkbox to get all data
                const vehicleCheckbox = document.querySelector(`input[data-vehicle-id="${vehicleId}"]`);
                if (vehicleCheckbox) {
                    // Check if vehicle is already in the array
                    const existingIndex = selectedVehicles.findIndex(v => v.id === vehicleId);
                    if (existingIndex === -1) {
                        selectedVehicles.push({
                            id: vehicleId,
                            vehicle_name: vehicleCheckbox.dataset.vehicleName,
                            type: vehicleCheckbox.dataset.vehicleType,
                            available: parseInt(vehicleCheckbox.dataset.available) || 1,
                            status: vehicleCheckbox.dataset.status || 'Available'
                        });
                    }
                }
            } else {
                // Remove vehicle
                selectedVehicles = selectedVehicles.filter(v => v.id !== vehicleId);
            }
            
            console.log('Current selectedVehicles:', selectedVehicles);
        }
        
        // Save suggestion for Emergency Response
        function saveSuggestion() {
            if (!selectedUnit || !currentIncidentId) {
                showError('Please select a unit first');
                return;
            }
            
            console.log('Saving suggestion with data:');
            console.log('Incident ID:', currentIncidentId);
            console.log('Selected Unit:', selectedUnit);
            console.log('Selected Vehicles:', selectedVehicles);
            
            const saveBtn = document.getElementById('saveSuggestionBtn');
            saveBtn.innerHTML = '<i class="bx bx-loader-circle bx-spin"></i> Saving Suggestion...';
            saveBtn.disabled = true;
            
            // Create a suggestion (not a dispatch yet)
            fetch('create_suggestion.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    incident_id: currentIncidentId,
                    unit_id: selectedUnit.unit_id,
                    unit_name: selectedUnit.unit_name,
                    unit_code: selectedUnit.unit_code,
                    vehicles: selectedVehicles,
                    suggested_by: <?php echo $user_id; ?>,
                    match_score: selectedUnit.match_score,
                    reasoning: selectedUnit.reasoning
                })
            })
            .then(response => response.json())
            .then(data => {
                console.log('API Response:', data);
                if (data.success) {
                    showNotification('success', '✅ Suggestion Saved!', 'Your suggestion has been saved for Emergency Response review.');
                    
                    closeModal();
                    
                    // Refresh page after 2 seconds
                    setTimeout(() => {
                        location.reload();
                    }, 2000);
                } else {
                    showError(data.message || 'Failed to save suggestion');
                    saveBtn.innerHTML = '<i class="bx bx-save"></i> Save Suggestion for Emergency Response';
                    saveBtn.disabled = false;
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showError('Failed to save suggestion');
                saveBtn.innerHTML = '<i class="bx bx-save"></i> Save Suggestion for Emergency Response';
                saveBtn.disabled = false;
            });
        }
        
        // Manual unit selection
        function manualSelect(incidentId) {
            currentIncidentId = incidentId;
            selectedUnit = null;
            selectedVehicles = [];
            
            const modal = document.getElementById('manualModal');
            const modalBody = document.getElementById('manualModalBody');
            
            modal.classList.add('active');
            modalBody.innerHTML = `
                <div class="ai-loading">
                    <div class="ai-spinner"></div>
                    <p>Loading available fire & rescue units and vehicles...</p>
                </div>
            `;
            
            // Fetch available units and vehicles
            fetch('get_available_units.php')
                .then(response => response.json())
                .then(data => {
                    if (data.success && data.units.length > 0) {
                        displayManualSelection(data.units);
                    } else {
                        modalBody.innerHTML = `
                            <div class="no-data">
                                <i class='bx bx-error-circle'></i>
                                <p>No fire & rescue units available for manual selection</p>
                            </div>
                        `;
                    }
                })
                .catch(error => {
                    modalBody.innerHTML = `
                        <div class="no-data">
                            <i class='bx bx-error'></i>
                            <p>Failed to load units: ${error.message}</p>
                        </div>
                    `;
                });
        }
        
        // Display manual selection
        function displayManualSelection(units) {
            const modalBody = document.getElementById('manualModalBody');
            
            modalBody.innerHTML = `
                <div style="margin-bottom: 20px;">
                    <h4 style="font-size: 18px; margin-bottom: 15px;">Manual Fire & Rescue Unit Selection</h4>
                    <p style="color: var(--text-light); font-size: 14px;">
                        Select a unit and available vehicles to suggest for dispatch:
                    </p>
                </div>
                
                <div class="manual-selection-grid" id="manualUnitsGrid">
                    ${units.map(unit => `
                        <div class="unit-card" id="unit-${unit.id}">
                            <h5 style="margin: 0 0 5px 0; font-size: 16px;">${unit.unit_name}</h5>
                            <div style="font-size: 13px; color: var(--text-light); margin-bottom: 10px;">
                                ${unit.unit_code} • ${unit.unit_type}
                            </div>
                            <div style="font-size: 12px; margin-bottom: 10px;">
                                <div><i class='bx bx-map'></i> ${unit.location}</div>
                                <div><i class='bx bx-group'></i> ${unit.volunteer_count || 0}/${unit.capacity} volunteers</div>
                            </div>
                            
                            <!-- Volunteers in this unit -->
                            <div class="volunteer-list">
                                <strong>Volunteers in Unit:</strong>
                                <div id="manual-volunteers-${unit.id}" style="margin-top: 10px;">
                                    <div style="text-align: center; padding: 10px; color: var(--text-light);">
                                        <div class="ai-spinner" style="width: 20px; height: 20px; margin: 0 auto 5px;"></div>
                                        <div style="font-size: 11px;">Loading volunteers...</div>
                                    </div>
                                </div>
                            </div>
                            
                            <div style="margin-top: 15px;">
                                <button class="btn btn-primary" onclick="selectManualUnit(${unit.id}, '${unit.unit_name}')" style="width: 100%;">
                                    <i class='bx bx-check'></i> Select This Unit
                                </button>
                            </div>
                        </div>
                    `).join('')}
                </div>
                
                <div id="vehicleSelectionSection" style="display: none; margin-top: 30px;">
                    <h4 style="font-size: 18px; margin-bottom: 15px;">Select Fire & Rescue Vehicles for <span id="selectedUnitName"></span></h4>
                    <div id="vehicleSelectionGrid" class="vehicle-selection">
                        <!-- Vehicles will be loaded here -->
                    </div>
                    <div style="margin-top: 20px;">
                        <button class="btn btn-success save-selection-btn" onclick="saveManualSuggestion()" style="width: 100%; padding: 14px;">
                            <i class='bx bx-save'></i> Save Suggestion for Emergency Response
                        </button>
                    </div>
                </div>
            `;
            
            // Load volunteers for each unit
            units.forEach(unit => {
                loadManualVolunteersForUnit(unit.id);
            });
        }
        
        // Load volunteers for manual selection
        function loadManualVolunteersForUnit(unitId) {
            fetch('get_volunteers_for_unit.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({ unit_id: unitId })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    const volunteersContainer = document.getElementById(`manual-volunteers-${unitId}`);
                    if (data.volunteers.length > 0) {
                        volunteersContainer.innerHTML = data.volunteers.map(volunteer => `
                            <div class="volunteer-item">
                                <div class="volunteer-avatar">
                                    ${volunteer.full_name ? volunteer.full_name.charAt(0).toUpperCase() : 'V'}
                                </div>
                                <div class="volunteer-info">
                                    <div class="volunteer-name">${volunteer.full_name || 'Unknown Volunteer'}</div>
                                    <div class="volunteer-contact">
                                        ${volunteer.contact_number || ''}
                                    </div>
                                </div>
                            </div>
                        `).join('');
                    } else {
                        volunteersContainer.innerHTML = `
                            <div style="text-align: center; padding: 10px; color: var(--text-light); font-size: 12px;">
                                <i class='bx bx-user-x'></i>
                                <div>No volunteers assigned</div>
                            </div>
                        `;
                    }
                }
            })
            .catch(error => {
                console.error('Error loading volunteers:', error);
            });
        }
        
        // Select a unit in manual mode
        function selectManualUnit(unitId, unitName) {
            selectedUnit = {
                unit_id: unitId,
                unit_name: unitName
            };
            selectedVehicles = [];
            
            // Highlight selected unit
            document.querySelectorAll('.unit-card').forEach(card => {
                card.style.borderColor = 'var(--border-color)';
            });
            document.getElementById(`unit-${unitId}`).style.borderColor = 'var(--primary-color)';
            
            // Show vehicle selection
            document.getElementById('selectedUnitName').textContent = unitName;
            document.getElementById('vehicleSelectionSection').style.display = 'block';
            
            // Load available vehicles for this unit
            fetch('get_vehicles_for_unit.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({ unit_id: unitId })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    displayAvailableVehicles(data.vehicles);
                }
            });
        }
        
        // Display available vehicles for manual selection
        function displayAvailableVehicles(vehicles) {
            const vehicleGrid = document.getElementById('vehicleSelectionGrid');
            
            if (vehicles.length > 0) {
                vehicleGrid.innerHTML = vehicles.map(vehicle => `
                    <div class="vehicle-option">
                        <input type="checkbox" 
                               id="manual-vehicle-${vehicle.id}" 
                               class="vehicle-checkbox"
                               data-vehicle-id="${vehicle.id}"
                               data-vehicle-name="${vehicle.vehicle_name}"
                               data-vehicle-type="${vehicle.type}"
                               data-available="${vehicle.available || 1}"
                               data-status="${vehicle.status || 'Available'}"
                               onchange="toggleManualVehicle(${vehicle.id}, this.checked)">
                        <label for="manual-vehicle-${vehicle.id}" style="flex: 1; cursor: pointer;">
                            <div><strong>${vehicle.vehicle_name}</strong></div>
                            <div style="font-size: 11px; color: var(--text-light);">${vehicle.type}</div>
                            <div style="font-size: 10px; color: var(--text-light);">Status: ${vehicle.status}</div>
                        </label>
                    </div>
                `).join('');
            } else {
                vehicleGrid.innerHTML = '<div style="color: var(--text-light); text-align: center; padding: 20px;">No vehicles available for this unit</div>';
            }
        }
        
        // Toggle vehicle in manual selection
        function toggleManualVehicle(vehicleId, isChecked) {
            const vehicleCheckbox = document.querySelector(`#manual-vehicle-${vehicleId}`);
            if (!vehicleCheckbox) return;
            
            if (isChecked) {
                // Add vehicle with all required data
                selectedVehicles.push({
                    id: vehicleId,
                    vehicle_name: vehicleCheckbox.dataset.vehicleName,
                    type: vehicleCheckbox.dataset.vehicleType,
                    available: parseInt(vehicleCheckbox.dataset.available) || 1,
                    status: vehicleCheckbox.dataset.status || 'Available'
                });
            } else {
                // Remove vehicle
                selectedVehicles = selectedVehicles.filter(v => v.id !== vehicleId);
            }
        }
        
        // Save manual suggestion
        function saveManualSuggestion() {
            if (!selectedUnit || !currentIncidentId) {
                showError('Please select a unit first');
                return;
            }
            
            const saveBtn = document.querySelector('.save-selection-btn');
            saveBtn.innerHTML = '<i class="bx bx-loader-circle bx-spin"></i> Saving Suggestion...';
            saveBtn.disabled = true;
            
            console.log('Saving manual suggestion with vehicles:', selectedVehicles);
            
            // Create a suggestion
            fetch('create_suggestion.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    incident_id: currentIncidentId,
                    unit_id: selectedUnit.unit_id,
                    unit_name: selectedUnit.unit_name,
                    unit_code: selectedUnit.unit_code || '',
                    vehicles: selectedVehicles,
                    suggested_by: <?php echo $user_id; ?>,
                    match_score: 0,
                    reasoning: 'Manually selected by dispatcher'
                })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showNotification('success', '✅ Suggestion Saved!', 'Your manual suggestion has been saved for Emergency Response review.');
                    
                    closeManualModal();
                    
                    // Refresh page after 2 seconds
                    setTimeout(() => {
                        location.reload();
                    }, 2000);
                } else {
                    showError(data.message || 'Failed to save suggestion');
                    saveBtn.innerHTML = '<i class="bx bx-save"></i> Save Suggestion for Emergency Response';
                    saveBtn.disabled = false;
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showError('Failed to save suggestion');
                saveBtn.innerHTML = '<i class="bx bx-save"></i> Save Suggestion for Emergency Response';
                saveBtn.disabled = false;
            });
        }
        
        // View dispatch details
        function viewDispatch(dispatchId) {
            const modal = document.getElementById('viewDispatchModal');
            const modalBody = document.getElementById('viewDispatchBody');
            
            modal.classList.add('active');
            modalBody.innerHTML = `
                <div class="ai-loading">
                    <div class="ai-spinner"></div>
                    <p>Loading fire & rescue dispatch details...</p>
                </div>
            `;
            
            fetch('get_dispatch_details.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({ dispatch_id: dispatchId })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    displayDispatchDetails(data.dispatch);
                } else {
                    showError('Failed to load dispatch details');
                    closeViewModal();
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showError('Failed to load dispatch details');
                closeViewModal();
            });
        }
        
        // Display dispatch details
        function displayDispatchDetails(dispatch) {
            const modalBody = document.getElementById('viewDispatchBody');
            
            modalBody.innerHTML = `
                <div class="dispatch-details">
                    <div class="detail-section">
                        <h4><i class='bx bx-info-circle'></i> Fire & Rescue Incident Information</h4>
                        <div class="detail-item">
                            <span class="detail-label">Title:</span>
                            <span class="detail-value">${dispatch.incident_title}</span>
                        </div>
                        <div class="detail-item">
                            <span class="detail-label">Location:</span>
                            <span class="detail-value">${dispatch.incident_location}</span>
                        </div>
                        <div class="detail-item">
                            <span class="detail-label">Type:</span>
                            <span class="detail-value">${dispatch.incident_type}</span>
                        </div>
                        <div class="detail-item">
                            <span class="detail-label">Severity:</span>
                            <span class="detail-value">
                                <span class="severity-badge severity-${dispatch.incident_severity.toLowerCase()}">
                                    ${dispatch.incident_severity}
                                </span>
                            </span>
                        </div>
                    </div>
                    
                    <div class="detail-section">
                        <h4><i class='bx bx-building'></i> Fire & Rescue Unit Information</h4>
                        <div class="detail-item">
                            <span class="detail-label">Unit Name:</span>
                            <span class="detail-value">${dispatch.unit_name}</span>
                        </div>
                        <div class="detail-item">
                            <span class="detail-label">Unit Code:</span>
                            <span class="detail-value">${dispatch.unit_code}</span>
                        </div>
                        <div class="detail-item">
                            <span class="detail-label">Unit Type:</span>
                            <span class="detail-value">${dispatch.unit_type}</span>
                        </div>
                        <div class="detail-item">
                            <span class="detail-label">Current Status:</span>
                            <span class="detail-value">
                                <span class="status-badge status-${dispatch.unit_status.toLowerCase()}">
                                    ${dispatch.unit_status}
                                </span>
                            </span>
                        </div>
                    </div>
                    
                    <div class="detail-section">
                        <h4><i class='bx bx-user'></i> Dispatch Information</h4>
                        <div class="detail-item">
                            <span class="detail-label">Status:</span>
                            <span class="detail-value">
                                <span class="status-badge status-${dispatch.status.toLowerCase()}">
                                    ${dispatch.status}
                                </span>
                            </span>
                        </div>
                        <div class="detail-item">
                            <span class="detail-label">Dispatched At:</span>
                            <span class="detail-value">${dispatch.dispatched_at}</span>
                        </div>
                        <div class="detail-item">
                            <span class="detail-label">Dispatched By:</span>
                            <span class="detail-value">${dispatch.dispatcher_name || 'System'}</span>
                        </div>
                        ${dispatch.er_notes ? `
                            <div class="detail-item">
                                <span class="detail-label">ER Notes:</span>
                                <span class="detail-value">${dispatch.er_notes}</span>
                            </div>
                        ` : ''}
                    </div>
                </div>
                
                <div class="detail-section" style="margin-top: 20px;">
                    <h4><i class='bx bx-car'></i> Assigned Fire & Rescue Vehicles (${dispatch.vehicles ? dispatch.vehicles.length : 0})</h4>
                    ${dispatch.vehicles && dispatch.vehicles.length > 0 ? `
                        <div class="vehicle-selection" style="margin-top: 10px;">
                            ${dispatch.vehicles.map(vehicle => `
                                <div class="vehicle-option" style="background: rgba(16, 185, 129, 0.1);">
                                    <div>
                                        <div><strong>${vehicle.vehicle_name}</strong></div>
                                        <div style="font-size: 11px; color: var(--text-light);">${vehicle.type}</div>
                                        <div style="font-size: 10px; color: var(--text-light);">Status: ${vehicle.status}</div>
                                    </div>
                                </div>
                            `).join('')}
                        </div>
                    ` : '<p style="color: var(--text-light); text-align: center;">No vehicles assigned</p>'}
                </div>
                
                <div class="detail-section" style="margin-top: 20px;">
                    <h4><i class='bx bx-group'></i> Unit Volunteers (${dispatch.volunteers ? dispatch.volunteers.length : 0})</h4>
                    ${dispatch.volunteers && dispatch.volunteers.length > 0 ? `
                        <div style="margin-top: 10px; max-height: 200px; overflow-y: auto;">
                            ${dispatch.volunteers.map(volunteer => `
                                <div class="volunteer-item">
                                    <div class="volunteer-avatar">
                                        ${volunteer.full_name ? volunteer.full_name.charAt(0).toUpperCase() : 'V'}
                                    </div>
                                    <div class="volunteer-info">
                                        <div class="volunteer-name">${volunteer.full_name || 'Unknown Volunteer'}</div>
                                        <div class="volunteer-contact">
                                            ${volunteer.contact_number || ''} • ${volunteer.email || ''}
                                        </div>
                                    </div>
                                </div>
                            `).join('')}
                        </div>
                    ` : '<p style="color: var(--text-light); text-align: center;">No volunteers assigned to this unit</p>'}
                </div>
                
                <div style="margin-top: 30px; text-align: center;">
                    <button class="btn btn-primary" onclick="closeViewModal()">
                        <i class='bx bx-x'></i> Close
                    </button>
                </div>
            `;
        }
        
        // Edit dispatch
        function editDispatch(dispatchId) {
            const modal = document.getElementById('editDispatchModal');
            const modalBody = document.getElementById('editDispatchBody');
            
            modal.classList.add('active');
            modalBody.innerHTML = `
                <div class="ai-loading">
                    <div class="ai-spinner"></div>
                    <p>Loading fire & rescue dispatch for editing...</p>
                </div>
            `;
            
            fetch('get_dispatch_details.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({ dispatch_id: dispatchId })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    displayEditDispatch(data.dispatch);
                } else {
                    showError('Failed to load dispatch details');
                    closeEditModal();
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showError('Failed to load dispatch details');
                closeEditModal();
            });
        }
        
       // Display edit dispatch form
function displayEditDispatch(dispatch) {
    const modalBody = document.getElementById('editDispatchBody');
    
    modalBody.innerHTML = `
        <div style="margin-bottom: 20px;">
            <h4 style="font-size: 18px; margin-bottom: 10px;">Edit Fire & Rescue Dispatch: ${dispatch.incident_title}</h4>
            <p style="color: var(--text-light); font-size: 14px;">
                Update fire & rescue vehicles and dispatch information
            </p>
        </div>
        
        <div class="detail-section">
            <h4><i class='bx bx-building'></i> Current Fire & Rescue Unit</h4>
            <div style="padding: 15px; background: rgba(59, 130, 246, 0.1); border-radius: 8px; margin-top: 10px;">
                <div><strong>${dispatch.unit_name}</strong> (${dispatch.unit_code})</div>
                <div style="font-size: 13px; color: var(--text-light);">
                    ${dispatch.unit_type} • ${dispatch.unit_location}
                </div>
            </div>
        </div>
        
        <div class="detail-section" style="margin-top: 20px;">
            <h4><i class='bx bx-car'></i> Current Fire & Rescue Vehicles</h4>
            <div id="currentVehicles" style="margin-top: 10px;">
                ${dispatch.vehicles && dispatch.vehicles.length > 0 ? `
                    <div class="vehicle-selection" id="currentVehiclesList">
                        ${dispatch.vehicles.map(vehicle => `
                            <div class="vehicle-option" style="background: rgba(16, 185, 129, 0.1);">
                                <input type="checkbox" 
                                       id="edit-vehicle-${vehicle.id}" 
                                       class="edit-vehicle-checkbox"
                                       data-vehicle-id="${vehicle.id}"
                                       data-vehicle-name="${vehicle.vehicle_name}"
                                       data-vehicle-type="${vehicle.type}"
                                       checked
                                       onchange="updateEditVehicle(${vehicle.id}, this.checked)">
                                <label for="edit-vehicle-${vehicle.id}" style="flex: 1; cursor: pointer;">
                                    <div><strong>${vehicle.vehicle_name}</strong></div>
                                    <div style="font-size: 11px; color: var(--text-light);">${vehicle.type}</div>
                                </label>
                            </div>
                        `).join('')}
                    </div>
                ` : '<p style="color: var(--text-light); text-align: center;">No fire & rescue vehicles currently assigned</p>'}
            </div>
        </div>
        
        <div class="detail-section" style="margin-top: 20px;">
            <h4><i class='bx bx-plus'></i> Add More Fire & Rescue Vehicles</h4>
            <div id="availableVehicles" style="margin-top: 10px;">
                <div style="text-align: center; padding: 20px; color: var(--text-light);">
                    <div class="ai-spinner" style="width: 30px; height: 30px; margin: 0 auto 10px;"></div>
                    <div>Loading available fire & rescue vehicles...</div>
                </div>
            </div>
        </div>
        
        <div style="margin-top: 30px; padding-top: 20px; border-top: 1px solid var(--border-color);">
            <button class="btn btn-success" style="width: 100%; padding: 14px;" onclick="saveEditDispatch(${dispatch.id})" id="saveEditBtn">
                <i class='bx bx-save'></i> Save Changes
            </button>
            <p style="text-align: center; font-size: 12px; color: var(--text-light); margin-top: 10px;">
                Changes will update the fire & rescue dispatch immediately
            </p>
        </div>
    `;
    
    // Store dispatch data for later use
    window.currentEditDispatch = {
        id: dispatch.id,
        vehicles: dispatch.vehicles || []
    };
    
    // Load available vehicles
    loadAvailableVehiclesForEdit(dispatch.id);
}

// Load available vehicles for editing
function loadAvailableVehiclesForEdit(dispatchId) {
    fetch('get_available_vehicles_for_edit.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
        },
        body: JSON.stringify({ dispatch_id: dispatchId })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            displayAvailableVehiclesForEdit(data.vehicles);
        }
    })
    .catch(error => {
        console.error('Error loading vehicles:', error);
    });
}

// Display available vehicles for editing
function displayAvailableVehiclesForEdit(vehicles) {
    const vehiclesContainer = document.getElementById('availableVehicles');
    
    if (vehicles.length > 0) {
        vehiclesContainer.innerHTML = `
            <div class="vehicle-selection" id="availableVehiclesList">
                ${vehicles.map(vehicle => `
                    <div class="vehicle-option">
                        <input type="checkbox" 
                               id="available-vehicle-${vehicle.id}" 
                               class="available-vehicle-checkbox"
                               data-vehicle-id="${vehicle.id}"
                               data-vehicle-name="${vehicle.vehicle_name}"
                               data-vehicle-type="${vehicle.type}"
                               onchange="addVehicleToEdit(${vehicle.id}, this.checked)">
                        <label for="available-vehicle-${vehicle.id}" style="flex: 1; cursor: pointer;">
                            <div><strong>${vehicle.vehicle_name}</strong></div>
                            <div style="font-size: 11px; color: var(--text-light);">${vehicle.type}</div>
                            <div style="font-size: 10px; color: var(--text-light);">Status: ${vehicle.status}</div>
                        </label>
                    </div>
                `).join('')}
            </div>
        `;
    } else {
        vehiclesContainer.innerHTML = '<p style="color: var(--text-light); text-align: center;">No additional fire & rescue vehicles available</p>';
    }
}

// Update vehicle selection in edit mode
function updateEditVehicle(vehicleId, isChecked) {
    if (!isChecked) {
        // Remove vehicle from current edit dispatch
        window.currentEditDispatch.vehicles = window.currentEditDispatch.vehicles.filter(v => v.id !== vehicleId);
        
        // Visual feedback
        const vehicleCheckbox = document.querySelector(`#edit-vehicle-${vehicleId}`);
        if (vehicleCheckbox) {
            vehicleCheckbox.parentElement.style.opacity = '0.5';
            setTimeout(() => {
                vehicleCheckbox.parentElement.remove();
            }, 300);
        }
    }
}

// Add vehicle to edit selection
function addVehicleToEdit(vehicleId, isChecked) {
    const vehicleCheckbox = document.querySelector(`#available-vehicle-${vehicleId}`);
    if (!vehicleCheckbox) return;
    
    if (isChecked) {
        // Check if vehicle is already in current selection
        const existingVehicle = window.currentEditDispatch.vehicles.find(v => v.id === vehicleId);
        if (!existingVehicle) {
            // Add to current vehicles array
            window.currentEditDispatch.vehicles.push({
                id: vehicleId,
                vehicle_name: vehicleCheckbox.dataset.vehicleName,
                type: vehicleCheckbox.dataset.vehicleType
            });
            
            // Add to current vehicles section
            const currentVehiclesList = document.getElementById('currentVehiclesList');
            if (currentVehiclesList) {
                const vehicleOption = document.createElement('div');
                vehicleOption.className = 'vehicle-option';
                vehicleOption.style.background = 'rgba(16, 185, 129, 0.1)';
                vehicleOption.innerHTML = `
                    <input type="checkbox" 
                           id="edit-vehicle-${vehicleId}" 
                           class="edit-vehicle-checkbox"
                           data-vehicle-id="${vehicleId}"
                           data-vehicle-name="${vehicleCheckbox.dataset.vehicleName}"
                           data-vehicle-type="${vehicleCheckbox.dataset.vehicleType}"
                           checked
                           onchange="updateEditVehicle(${vehicleId}, this.checked)">
                    <label for="edit-vehicle-${vehicleId}" style="flex: 1; cursor: pointer;">
                        <div><strong>${vehicleCheckbox.dataset.vehicleName}</strong></div>
                        <div style="font-size: 11px; color: var(--text-light);">${vehicleCheckbox.dataset.vehicleType}</div>
                    </label>
                `;
                currentVehiclesList.appendChild(vehicleOption);
            }
            
            // Uncheck from available
            vehicleCheckbox.checked = false;
        }
    }
}

// Save edited dispatch - FIXED VERSION
function saveEditDispatch(dispatchId) {
    // Use the stored vehicles array from currentEditDispatch
    const vehicles = window.currentEditDispatch?.vehicles || [];
    
    console.log('Saving fire & rescue vehicles:', vehicles);
    
    const saveBtn = document.getElementById('saveEditBtn');
    saveBtn.innerHTML = '<i class="bx bx-loader-circle bx-spin"></i> Saving Changes...';
    saveBtn.disabled = true;
    
    fetch('update_dispatch.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
        },
        body: JSON.stringify({
            dispatch_id: dispatchId,
            vehicles: vehicles
        })
    })
    .then(response => response.json())
    .then(data => {
        console.log('Update response:', data);
        if (data.success) {
            showNotification('success', '✅ Fire & Rescue Dispatch Updated!', 'The fire & rescue dispatch has been updated successfully.');
            
            closeEditModal();
            
            // Refresh page after 2 seconds
            setTimeout(() => {
                location.reload();
            }, 2000);
        } else {
            showError(data.message || 'Failed to update fire & rescue dispatch');
            saveBtn.innerHTML = '<i class="bx bx-save"></i> Save Changes';
            saveBtn.disabled = false;
        }
    })
    .catch(error => {
        console.error('Error:', error);
        showError('Failed to update fire & rescue dispatch');
        saveBtn.innerHTML = '<i class="bx bx-save"></i> Save Changes';
        saveBtn.disabled = false;
    });
}
        
        // AI Analyze All incidents
        function getAllAIRecommendations() {
            const incidents = document.querySelectorAll('tbody tr[data-incident-id]');
            if (incidents.length === 0) {
                showNotification('info', 'No Fire & Rescue Incidents', 'There are no fire or rescue incidents to analyze.');
                return;
            }
            
            showNotification('info', 'AI Analysis Started', `Analyzing ${incidents.length} fire & rescue incidents...`);
            
            let analyzedCount = 0;
            let successCount = 0;
            
            incidents.forEach(row => {
                const incidentId = row.getAttribute('data-incident-id');
                
                // Simulate AI analysis for each incident
                setTimeout(() => {
                    analyzedCount++;
                    
                    // Randomly decide if AI would recommend something (simulation)
                    const hasRecommendation = Math.random() > 0.3;
                    
                    if (hasRecommendation) {
                        successCount++;
                        row.style.backgroundColor = 'rgba(16, 185, 129, 0.1)';
                        
                        // Add AI badge to the row
                        const actionsCell = row.querySelector('td:last-child');
                        if (actionsCell && !actionsCell.querySelector('.ai-badge')) {
                            const aiBadge = document.createElement('span');
                            aiBadge.className = 'ai-badge';
                            aiBadge.style.cssText = `
                                background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
                                color: white;
                                padding: 4px 8px;
                                border-radius: 12px;
                                font-size: 10px;
                                font-weight: 600;
                                margin-left: 8px;
                                display: inline-block;
                            `;
                            aiBadge.innerHTML = '<i class="bx bx-sparkles"></i> AI Ready';
                            actionsCell.querySelector('.action-buttons').appendChild(aiBadge);
                        }
                    }
                    
                    // Update progress
                    if (analyzedCount === incidents.length) {
                        showNotification('success', 'AI Analysis Complete', 
                            `Analyzed ${analyzedCount} fire & rescue incidents. ${successCount} have AI recommendations ready.`);
                    }
                }, Math.random() * 1000); // Random delay for simulation
            });
        }
        
        // Close modals
        function closeModal() {
            document.getElementById('aiModal').classList.remove('active');
        }
        
        function closeManualModal() {
            document.getElementById('manualModal').classList.remove('active');
        }
        
        function closeViewModal() {
            document.getElementById('viewDispatchModal').classList.remove('active');
        }
        
        function closeEditModal() {
            document.getElementById('editDispatchModal').classList.remove('active');
        }
        
        // Show error message
        function showError(message) {
            const modalBody = document.getElementById('aiModalBody');
            modalBody.innerHTML = `
                <div style="text-align: center; padding: 40px;">
                    <i class='bx bx-error-circle' style="font-size: 48px; color: #ef4444; margin-bottom: 20px;"></i>
                    <h4>Error</h4>
                    <p>${message}</p>
                    <button class="btn btn-primary" onclick="closeModal()" style="margin-top: 20px;">
                        <i class='bx bx-x'></i> Close
                    </button>
                </div>
            `;
        }
        
        // Show notification
        function showNotification(type, title, message) {
            const container = document.getElementById('notification-container');
            const notification = document.createElement('div');
            
            notification.style.cssText = `
                background: ${type === 'success' ? '#10b981' : type === 'error' ? '#ef4444' : '#3b82f6'};
                color: white;
                padding: 15px;
                border-radius: 8px;
                margin-bottom: 10px;
                box-shadow: 0 4px 12px rgba(0,0,0,0.15);
                animation: slideIn 0.3s ease;
                max-width: 300px;
            `;
            
            notification.innerHTML = `
                <div style="font-weight: bold; margin-bottom: 5px;">${title}</div>
                <div style="font-size: 14px;">${message}</div>
            `;
            
            container.appendChild(notification);
            
            // Auto remove after 5 seconds
            setTimeout(() => {
                notification.style.animation = 'slideOut 0.3s ease';
                setTimeout(() => {
                    if (notification.parentNode) {
                        container.removeChild(notification);
                    }
                }, 300);
            }, 5000);
        }
        
        // Add CSS for animations
        const style = document.createElement('style');
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
        
        // Refresh data
        function refreshData() {
            showNotification('info', '🔄 Refreshing', 'Loading latest fire & rescue data...');
            location.reload();
        }
        
        // Apply filters
        function applyFilters() {
            const severity = document.getElementById('severity-filter').value;
            const type = document.getElementById('type-filter').value;
            
            let url = '?page=1&dispatch_page=<?php echo $dispatch_page; ?>';
            if (severity !== 'all') {
                url += `&severity=${severity}`;
            }
            if (type !== 'all') {
                url += `&type=${type}`;
            }
            
            window.location.href = url;
        }
        
        // Initialize event listeners
        document.addEventListener('DOMContentLoaded', () => {
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
            });
            
            // Notification dropdown
            const notificationBell = document.getElementById('notification-bell');
            const notificationDropdown = document.getElementById('notification-dropdown');
            
            notificationBell.addEventListener('click', function(e) {
                e.stopPropagation();
                notificationDropdown.classList.toggle('show');
            });
            
            // Close dropdowns when clicking outside
            document.addEventListener('click', function() {
                userDropdown.classList.remove('show');
                notificationDropdown.classList.remove('show');
            });
            
            // Sound toggle
            const soundToggle = document.getElementById('sound-toggle');
            let soundEnabled = true;
            
            soundToggle.addEventListener('click', function() {
                soundEnabled = !soundEnabled;
                if (soundEnabled) {
                    soundToggle.innerHTML = '<i class="bx bx-bell"></i>';
                    soundToggle.style.background = 'var(--primary-color)';
                } else {
                    soundToggle.innerHTML = '<i class="bx bx-bell-off"></i>';
                    soundToggle.style.background = 'var(--gray-500)';
                }
            });
            
            // Toggle submenus
            function toggleSubmenu(id) {
                const submenu = document.getElementById(id);
                const arrow = submenu.previousElementSibling.querySelector('.dropdown-arrow');
                
                submenu.classList.toggle('active');
                arrow.classList.toggle('rotated');
            }
            
            // Expose to global scope
            window.toggleSubmenu = toggleSubmenu;
            
            // Update time
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
            
            // Initialize
            updateTime();
            setInterval(updateTime, 1000);
        });
    </script>
</body>
</html>