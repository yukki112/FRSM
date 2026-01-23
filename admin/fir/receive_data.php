<?php
session_start();
require_once 'fetch_from_api.php';
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

// Function to fetch incidents from API
function fetchIncidentsFromAPI() {
    $api_url = "https://ecs.jampzdev.com/api/emergencies/active";
    
    try {
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
                return $data['data'];
            }
        }
    } catch (Exception $e) {
        error_log("Error fetching from API: " . $e->getMessage());
    }
    return [];
}

// Function to save API incidents to database with fire/rescue categorization
function saveApiIncidentsToDatabase($pdo, $api_incidents) {
    $new_incidents = [];
    
    foreach ($api_incidents as $incident) {
        // Check if incident already exists in database
        $check_sql = "SELECT id FROM api_incidents WHERE external_id = ?";
        $check_stmt = $pdo->prepare($check_sql);
        $check_stmt->execute([$incident['id']]);
        $existing = $check_stmt->fetch();
        
        if (!$existing) {
            // Determine if it's fire/rescue related
            $is_fire_rescue = 0;
            $rescue_category = NULL;
            
            $emergency_type = strtolower($incident['emergency_type'] ?? '');
            $description = strtolower($incident['description'] ?? '');
            
            // Check for fire incidents
            if ($emergency_type == 'fire') {
                $is_fire_rescue = 1;
            } 
            // Check for rescue incidents
            elseif ($emergency_type == 'rescue' || $emergency_type == 'other') {
                if (strpos($description, 'rescue') !== false || 
                    strpos($description, 'collapsing') !== false ||
                    strpos($description, 'accident') !== false ||
                    strpos($description, 'height') !== false ||
                    strpos($description, 'water') !== false) {
                    $is_fire_rescue = 1;
                    
                    // Determine rescue category
                    if (strpos($description, 'collapsing building') !== false || 
                        strpos($description, 'building collapse') !== false) {
                        $rescue_category = 'building_collapse';
                    } elseif (strpos($description, 'vehicle accident') !== false) {
                        $rescue_category = 'vehicle_accident';
                    } elseif (strpos($description, 'height') !== false) {
                        $rescue_category = 'height_rescue';
                    } elseif (strpos($description, 'water') !== false) {
                        $rescue_category = 'water_rescue';
                    } elseif (strpos($description, 'rescue') !== false) {
                        $rescue_category = 'other_rescue';
                    }
                }
            }
            
            // Insert new incident
            $insert_sql = "INSERT INTO api_incidents (
                external_id, alert_type, emergency_type, assistance_needed, severity,
                title, caller_name, caller_phone, location, description, status,
                affected_barangays, issued_by, valid_until, created_at,
                sync_status, created_at_local, is_fire_rescue_related, rescue_category
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'synced', NOW(), ?, ?)";
            
            $insert_stmt = $pdo->prepare($insert_sql);
            $insert_stmt->execute([
                $incident['id'],
                $incident['alert_type'] ?? '',
                $incident['emergency_type'] ?? '',
                $incident['assistance_needed'] ?? '',
                $incident['severity'] ?? 'medium',
                $incident['title'] ?? '',
                $incident['name'] ?? '',
                $incident['phone'] ?? '',
                $incident['location'] ?? '',
                $incident['description'] ?? '',
                $incident['status'] ?? 'pending',
                $incident['affected_barangays'] ?? '',
                $incident['issued_by'] ?? '',
                $incident['valid_until'] ?? NULL,
                $incident['created_at'] ?? date('Y-m-d H:i:s'),
                $is_fire_rescue,
                $rescue_category
            ]);
            
            $new_incidents[] = [
                'id' => $pdo->lastInsertId(),
                'external_id' => $incident['id']
            ];
        }
    }
    
    return $new_incidents;
}

// Function to get BOTH fire AND rescue related incidents from API incidents
function getFireAndRescueIncidentsFromDatabase($pdo) {
    $sql = "SELECT 
                id as db_id,
                external_id,
                alert_type,
                emergency_type as incident_type,
                assistance_needed,
                severity as emergency_level,
                title,
                caller_name,
                caller_phone,
                location,
                description,
                status,
                affected_barangays,
                issued_by,
                valid_until,
                created_at,
                responded_at,
                notes,
                is_fire_rescue_related,
                rescue_category,
                'Emergency Communication' as source
            FROM api_incidents 
            WHERE (emergency_type IN ('fire', 'rescue') 
                   OR is_fire_rescue_related = 1
                   OR rescue_category IS NOT NULL)
            ORDER BY created_at DESC";
    
    $stmt = $pdo->query($sql);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Function to get incident counts for fire AND rescue
function getFireAndRescueIncidentCounts($pdo) {
    $counts = [
        'total' => 0,
        'by_status' => [
            'all' => 0,
            'pending' => 0,
            'processing' => 0,
            'responded' => 0,
            'closed' => 0
        ],
        'by_emergency' => [
            'low' => 0,
            'medium' => 0,
            'high' => 0,
            'critical' => 0
        ],
        'by_type' => [
            'fire' => 0,
            'rescue' => 0,
            'other_fire_rescue' => 0
        ],
        'by_rescue_category' => [
            'building_collapse' => 0,
            'vehicle_accident' => 0,
            'height_rescue' => 0,
            'water_rescue' => 0,
            'other_rescue' => 0
        ]
    ];
    
    // Get fire AND rescue incident counts
    $count_sql = "SELECT 
        COUNT(*) as total,
        SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending,
        SUM(CASE WHEN status = 'processing' THEN 1 ELSE 0 END) as processing,
        SUM(CASE WHEN status = 'responded' THEN 1 ELSE 0 END) as responded,
        SUM(CASE WHEN status = 'closed' THEN 1 ELSE 0 END) as closed,
        SUM(CASE WHEN severity = 'low' THEN 1 ELSE 0 END) as low,
        SUM(CASE WHEN severity = 'medium' THEN 1 ELSE 0 END) as medium,
        SUM(CASE WHEN severity = 'high' THEN 1 ELSE 0 END) as high,
        SUM(CASE WHEN severity = 'critical' THEN 1 ELSE 0 END) as critical,
        SUM(CASE WHEN emergency_type = 'fire' THEN 1 ELSE 0 END) as fire,
        SUM(CASE WHEN emergency_type = 'rescue' OR rescue_category IS NOT NULL THEN 1 ELSE 0 END) as rescue,
        SUM(CASE WHEN emergency_type NOT IN ('fire', 'rescue') AND rescue_category IS NULL AND is_fire_rescue_related = 1 THEN 1 ELSE 0 END) as other_fire_rescue,
        SUM(CASE WHEN rescue_category = 'building_collapse' THEN 1 ELSE 0 END) as building_collapse,
        SUM(CASE WHEN rescue_category = 'vehicle_accident' THEN 1 ELSE 0 END) as vehicle_accident,
        SUM(CASE WHEN rescue_category = 'height_rescue' THEN 1 ELSE 0 END) as height_rescue,
        SUM(CASE WHEN rescue_category = 'water_rescue' THEN 1 ELSE 0 END) as water_rescue,
        SUM(CASE WHEN rescue_category = 'other_rescue' THEN 1 ELSE 0 END) as other_rescue
    FROM api_incidents 
    WHERE (emergency_type IN ('fire', 'rescue') 
           OR is_fire_rescue_related = 1
           OR rescue_category IS NOT NULL)";
    
    $count_stmt = $pdo->query($count_sql);
    $result = $count_stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($result) {
        $counts['total'] = $result['total'] ?? 0;
        $counts['by_status']['all'] = $counts['total'];
        $counts['by_status']['pending'] = $result['pending'] ?? 0;
        $counts['by_status']['processing'] = $result['processing'] ?? 0;
        $counts['by_status']['responded'] = $result['responded'] ?? 0;
        $counts['by_status']['closed'] = $result['closed'] ?? 0;
        
        $counts['by_emergency']['low'] = $result['low'] ?? 0;
        $counts['by_emergency']['medium'] = $result['medium'] ?? 0;
        $counts['by_emergency']['high'] = $result['high'] ?? 0;
        $counts['by_emergency']['critical'] = $result['critical'] ?? 0;
        
        $counts['by_type']['fire'] = $result['fire'] ?? 0;
        $counts['by_type']['rescue'] = $result['rescue'] ?? 0;
        $counts['by_type']['other_fire_rescue'] = $result['other_fire_rescue'] ?? 0;
        
        $counts['by_rescue_category']['building_collapse'] = $result['building_collapse'] ?? 0;
        $counts['by_rescue_category']['vehicle_accident'] = $result['vehicle_accident'] ?? 0;
        $counts['by_rescue_category']['height_rescue'] = $result['height_rescue'] ?? 0;
        $counts['by_rescue_category']['water_rescue'] = $result['water_rescue'] ?? 0;
        $counts['by_rescue_category']['other_rescue'] = $result['other_rescue'] ?? 0;
    }
    
    return $counts;
}

// Function to continuously check API for new incidents
function checkAndSyncNewIncidents($pdo) {
    $api_incidents = fetchIncidentsFromAPI();
    if (!empty($api_incidents)) {
        $new_incidents = saveApiIncidentsToDatabase($pdo, $api_incidents);
        return $new_incidents;
    }
    return [];
}

// MAIN LOGIC: Fetch from API and sync on every page load
$new_incidents = checkAndSyncNewIncidents($pdo);

// Get BOTH fire AND rescue incidents from database
$db_incidents = getFireAndRescueIncidentsFromDatabase($pdo);
$incidents = [];
$last_incident_id = 0;

// Transform database incidents to match expected format
foreach ($db_incidents as $incident) {
    $incident_type = strtolower($incident['incident_type']);
    $status = strtolower($incident['status']);
    $emergency_level = strtolower($incident['emergency_level']);
    
    // Determine display type based on categorization
    $display_type = $incident_type;
    if ($incident['rescue_category']) {
        switch($incident['rescue_category']) {
            case 'building_collapse':
                $display_type = 'rescue (building collapse)';
                break;
            case 'vehicle_accident':
                $display_type = 'rescue (vehicle accident)';
                break;
            case 'height_rescue':
                $display_type = 'rescue (height)';
                break;
            case 'water_rescue':
                $display_type = 'rescue (water)';
                break;
            case 'other_rescue':
                $display_type = 'rescue';
                break;
        }
    } elseif ($incident_type == 'fire') {
        $display_type = 'fire';
    } elseif ($incident['is_fire_rescue_related']) {
        $display_type = 'fire/rescue related';
    }
    
    $transformed_incident = [
        'ID' => $incident['external_id'],
        'Location' => $incident['location'],
        'Incident Type' => ucfirst($display_type),
        'Emergency Level' => ucfirst($emergency_level),
        'Status' => ucfirst($status),
        'Original Status' => $status,
        'Incident Description' => $incident['description'],
        'Caller Name' => $incident['caller_name'],
        'Phone' => $incident['caller_phone'],
        'Date Reported' => $incident['created_at'],
        'Date Resolved' => $incident['responded_at'],
        'Additional Notes' => $incident['notes'],
        'Affected Barangays' => $incident['affected_barangays'],
        'Assistance Needed' => $incident['assistance_needed'],
        'Title' => $incident['title'],
        'Issued By' => $incident['issued_by'],
        'Alert Type' => $incident['alert_type'],
        'DatabaseID' => $incident['db_id'],
        'Source' => 'ECS',
        'RescueCategory' => $incident['rescue_category'],
        'IsFireRescue' => $incident['is_fire_rescue_related']
    ];
    
    $incidents[] = $transformed_incident;
    
    // Track the highest external ID
    if ($incident['external_id'] > $last_incident_id) {
        $last_incident_id = $incident['external_id'];
    }
}

// Get incident counts for fire AND rescue
$incident_counts = getFireAndRescueIncidentCounts($pdo);

// Get filter parameters
$status_filter = isset($_GET['status']) ? $_GET['status'] : 'all';
$emergency_level_filter = isset($_GET['emergency_level']) ? $_GET['emergency_level'] : 'all';
$incident_type_filter = isset($_GET['incident_type']) ? $_GET['incident_type'] : 'all';
$search_term = isset($_GET['search']) ? $_GET['search'] : '';

// Filter incidents based on criteria
$filtered_incidents = $incidents;

if (!empty($status_filter) && $status_filter !== 'all') {
    $filtered_incidents = array_filter($filtered_incidents, function($incident) use ($status_filter) {
        return strtolower($incident['Status']) === strtolower($status_filter);
    });
}

if (!empty($emergency_level_filter) && $emergency_level_filter !== 'all') {
    $filtered_incidents = array_filter($filtered_incidents, function($incident) use ($emergency_level_filter) {
        return strtolower($incident['Emergency Level']) === strtolower($emergency_level_filter);
    });
}

if (!empty($incident_type_filter) && $incident_type_filter !== 'all') {
    $filtered_incidents = array_filter($filtered_incidents, function($incident) use ($incident_type_filter) {
        $incident_type_lower = strtolower($incident['Incident Type']);
        $filter_lower = strtolower($incident_type_filter);
        
        if ($filter_lower === 'fire') {
            return $incident_type_lower === 'fire';
        } elseif ($filter_lower === 'rescue') {
            return strpos($incident_type_lower, 'rescue') !== false;
        } elseif ($filter_lower === 'other_fire_rescue') {
            return $incident_type_lower === 'fire/rescue related';
        }
        return false;
    });
}

if (!empty($search_term)) {
    $filtered_incidents = array_filter($filtered_incidents, function($incident) use ($search_term) {
        $searchable_fields = [
            $incident['Location'],
            $incident['Incident Type'],
            $incident['Incident Description'],
            $incident['Caller Name'],
            $incident['Affected Barangays'],
            $incident['Title']
        ];
        
        foreach ($searchable_fields as $field) {
            if (stripos($field, $search_term) !== false) {
                return true;
            }
        }
        return false;
    });
}

$stmt = null;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Fire & Rescue Incidents - Fire Incident Reporting</title>
    <link href='https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css' rel='stylesheet'>
    <link rel="icon" type="image/png" sizes="32x32" href="../../img/frsm-logo.png">
    <link rel="stylesheet" href="../../css/dashboard.css">
    <style>
        /* All your existing CSS styles remain exactly the same */
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
            gap: 25px;
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
        
        .stat-card[data-status="pending"]::before {
            background: var(--warning);
        }
        
        .stat-card[data-status="processing"]::before {
            background: var(--info);
        }
        
        .stat-card[data-status="responded"]::before {
            background: var(--primary-color);
        }
        
        .stat-card[data-status="closed"]::before {
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
        
        .stat-card[data-status="pending"] .stat-icon {
            background: rgba(245, 158, 11, 0.1);
            color: var(--warning);
        }
        
        .stat-card[data-status="processing"] .stat-icon {
            background: rgba(59, 130, 246, 0.1);
            color: var(--info);
        }
        
        .stat-card[data-status="responded"] .stat-icon {
            background: rgba(220, 38, 38, 0.1);
            color: var(--primary-color);
        }
        
        .stat-card[data-status="closed"] .stat-icon {
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
            grid-template-columns: 80px 1fr 1fr 120px 120px 120px 120px 120px 100px;
            gap: 16px;
            padding: 20px;
            background: rgba(220, 38, 38, 0.02);
            border-bottom: 1px solid var(--border-color);
            font-weight: 600;
            color: var(--text-color);
        }
        
        .table-row {
            display: grid;
            grid-template-columns: 80px 1fr 1fr 120px 120px 120px 120px 120px 100px;
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
        
        .status-pending {
            background: rgba(245, 158, 11, 0.1);
            color: var(--warning);
        }
        
        .status-processing {
            background: rgba(59, 130, 246, 0.1);
            color: var(--info);
        }
        
        .status-responded {
            background: rgba(220, 38, 38, 0.1);
            color: var(--primary-color);
        }
        
        .status-closed {
            background: rgba(16, 185, 129, 0.1);
            color: var(--success);
        }
        
        .source-badge {
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            text-transform: uppercase;
            background: rgba(59, 130, 246, 0.1);
            color: var(--info);
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
                grid-template-columns: 60px 1fr 1fr 100px 100px 100px 100px 100px 80px;
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

        /* Rescue category badges */
        .rescue-badge {
            padding: 4px 8px;
            border-radius: 12px;
            font-size: 10px;
            font-weight: 600;
            margin-top: 4px;
            display: inline-block;
        }
        
        .rescue-building {
            background: rgba(245, 158, 11, 0.1);
            color: var(--warning);
        }
        
        .rescue-vehicle {
            background: rgba(59, 130, 246, 0.1);
            color: var(--info);
        }
        
        .rescue-height {
            background: rgba(139, 92, 246, 0.1);
            color: var(--purple);
        }
        
        .rescue-water {
            background: rgba(6, 182, 212, 0.1);
            color: var(--cyan);
        }
        
        .rescue-other {
            background: rgba(16, 185, 129, 0.1);
            color: var(--success);
        }
        
        /* Fire badge */
        .fire-badge {
            padding: 4px 8px;
            border-radius: 12px;
            font-size: 10px;
            font-weight: 600;
            margin-top: 4px;
            display: inline-block;
            background: rgba(239, 68, 68, 0.1);
            color: var(--icon-red);
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
                <h2 class="modal-title">Fire & Rescue Incident Details</h2>
                <button class="modal-close" id="view-modal-close">&times;</button>
            </div>
            <div class="modal-body" id="view-modal-body">
                <!-- Content will be loaded dynamically -->
            </div>
        </div>
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
                    <a href="#" class="menu-item" id="dashboard-menu">
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
                        <a href="../users/manage_users.php" class="submenu-item">Manage Users</a>
                        <a href="../users/role_control.php" class="submenu-item">Role Control</a>
                        <a href="../users/monitor_activity.php" class="submenu-item">Audit & Activity Logs</a>
                       
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
                    <div id="incident-management" class="submenu active">
                     
                        <a href="receive_data.php" class="submenu-item active">Recieve Data</a>
                         <a href="track_status.php" class="submenu-item">Track Status</a>
                        <a href="update_status.php" class="submenu-item">Update Status</a>
                        <a href="incidents_analytics.php" class="submenu-item">Incidents Analytics</a>
                        
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
                        <a href="../vm/review_data.php" class="submenu-item">Review Data</a>
                        <a href="../vm/approve_applications.php" class="submenu-item">Approve Applications</a>
                        <a href="../vm/assign_volunteers.php" class="submenu-item">Assign Volunteers</a>
                        <a href="../vm/view_availability.php" class="submenu-item">View Availability</a>
                        <a href="../vm/remove_volunteers.php" class="submenu-item">Remove Volunteers</a>
                        <a href="../vm/toggle_volunteer_registration.php" class="submenu-item">Toggle Volunteer Registration Access</a>
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
                        <a href="../rm/view_equipment.php" class="submenu-item">View Equipment</a>
                        <a href="../rm/approve_maintenance.php" class="submenu-item">Approve Maintenance</a>
                        <a href="../rm/approve_resources.php" class="submenu-item">Approve Resources</a>
                        <a href="../rm/review_deployment.php" class="submenu-item">Review Deployment</a>
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
                        <a href="../sm/approve_shifts.php" class="submenu-item">Approve Shifts</a>
                        <a href="../sm/override_assignments.php" class="submenu-item">Override Assignments</a>
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
                        <a href="../tc/approve_completions.php" class="submenu-item">Approve Completions</a>
                        <a href="../tc/view_training_records.php" class="submenu-item">View Records</a>
                        <a href="../tc/assign_training.php" class="submenu-item">Assign Training</a>
                        <a href="../tc/track_expiry.php" class="submenu-item">Track Expiry</a>
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
                        <h1 class="dashboard-title">Fire & Rescue Incidents</h1>
                        <p class="dashboard-subtitle">Real-time fire and rescue incidents from ECS monitoring system. Showing all fire and rescue-related incidents (Total: <?php echo $incident_counts['total']; ?>)</p>
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
                        <?php if (!empty($new_incidents)): ?>
                            <button class="secondary-button" id="new-incident-alert" style="background: var(--warning); color: white; animation: pulse 2s infinite;">
                                <i class='bx bxs-bell-ring'></i>
                                <?php echo count($new_incidents); ?> New Incident<?php echo count($new_incidents) > 1 ? 's' : ''; ?> Detected!
                            </button>
                        <?php endif; ?>
                    </div>
                </div>
                
                <!-- Incidents Section -->
                <div class="incidents-container">
                    <!-- Filter Tabs -->
                    <div class="filter-tabs">
                        <div class="filter-tab <?php echo $status_filter === 'all' ? 'active' : ''; ?>" data-filter="status" data-value="all">
                            <i class='bx bxs-dashboard'></i>
                            All Fire & Rescue Incidents
                            <span class="filter-tab-count"><?php echo $incident_counts['by_status']['all']; ?></span>
                        </div>
                        <div class="filter-tab <?php echo $status_filter === 'pending' ? 'active' : ''; ?>" data-filter="status" data-value="pending">
                            <i class='bx bxs-megaphone'></i>
                            Pending
                            <span class="filter-tab-count"><?php echo $incident_counts['by_status']['pending']; ?></span>
                        </div>
                        <div class="filter-tab <?php echo $status_filter === 'processing' ? 'active' : ''; ?>" data-filter="status" data-value="processing">
                            <i class='bx bxs-truck'></i>
                            Processing
                            <span class="filter-tab-count"><?php echo $incident_counts['by_status']['processing']; ?></span>
                        </div>
                        <div class="filter-tab <?php echo $status_filter === 'responded' ? 'active' : ''; ?>" data-filter="status" data-value="responded">
                            <i class='bx bxs-time'></i>
                            Responded
                            <span class="filter-tab-count"><?php echo $incident_counts['by_status']['responded']; ?></span>
                        </div>
                        <div class="filter-tab <?php echo $status_filter === 'closed' ? 'active' : ''; ?>" data-filter="status" data-value="closed">
                            <i class='bx bxs-check-circle'></i>
                            Closed
                            <span class="filter-tab-count"><?php echo $incident_counts['by_status']['closed']; ?></span>
                        </div>
                    </div>
                    
                    <!-- Type Filter Tabs -->
                    <div class="filter-tabs">
                        <div class="filter-tab <?php echo $incident_type_filter === 'all' ? 'active' : ''; ?>" data-type="all">
                            <i class='bx bxs-layer'></i>
                            All Types
                            <span class="filter-tab-count"><?php echo $incident_counts['total']; ?></span>
                        </div>
                        <div class="filter-tab <?php echo $incident_type_filter === 'fire' ? 'active' : ''; ?>" data-type="fire">
                            <i class='bx bxs-fire'></i>
                            Fire Incidents
                            <span class="filter-tab-count"><?php echo $incident_counts['by_type']['fire']; ?></span>
                        </div>
                        <div class="filter-tab <?php echo $incident_type_filter === 'rescue' ? 'active' : ''; ?>" data-type="rescue">
                            <i class='bx bxs-first-aid'></i>
                            Rescue Operations
                            <span class="filter-tab-count"><?php echo $incident_counts['by_type']['rescue']; ?></span>
                        </div>
                        <div class="filter-tab <?php echo $incident_type_filter === 'other_fire_rescue' ? 'active' : ''; ?>" data-type="other_fire_rescue">
                            <i class='bx bxs-shield'></i>
                            Related Incidents
                            <span class="filter-tab-count"><?php echo $incident_counts['by_type']['other_fire_rescue']; ?></span>
                        </div>
                    </div>
                    
                    <!-- Stats Cards -->
                    <div class="stats-container">
                        <div class="stat-card <?php echo $status_filter === 'all' ? 'active' : ''; ?>" data-status="all">
                            <div class="stat-icon">
                                <i class='bx bxs-dashboard'></i>
                            </div>
                            <div class="stat-value"><?php echo $incident_counts['by_status']['all']; ?></div>
                            <div class="stat-label">Total Fire & Rescue</div>
                        </div>
                        <div class="stat-card <?php echo $status_filter === 'pending' ? 'active' : ''; ?>" data-status="pending">
                            <div class="stat-icon">
                                <i class='bx bxs-megaphone'></i>
                            </div>
                            <div class="stat-value"><?php echo $incident_counts['by_status']['pending']; ?></div>
                            <div class="stat-label">Pending</div>
                        </div>
                        <div class="stat-card <?php echo $status_filter === 'processing' ? 'active' : ''; ?>" data-status="processing">
                            <div class="stat-icon">
                                <i class='bx bxs-truck'></i>
                            </div>
                            <div class="stat-value"><?php echo $incident_counts['by_status']['processing']; ?></div>
                            <div class="stat-label">Processing</div>
                        </div>
                        <div class="stat-card <?php echo $status_filter === 'responded' ? 'active' : ''; ?>" data-status="responded">
                            <div class="stat-icon">
                                <i class='bx bxs-time'></i>
                            </div>
                            <div class="stat-value"><?php echo $incident_counts['by_status']['responded']; ?></div>
                            <div class="stat-label">Responded</div>
                        </div>
                        <div class="stat-card <?php echo $status_filter === 'closed' ? 'active' : ''; ?>" data-status="closed">
                            <div class="stat-icon">
                                <i class='bx bxs-check-circle'></i>
                            </div>
                            <div class="stat-value"><?php echo $incident_counts['by_status']['closed']; ?></div>
                            <div class="stat-label">Closed</div>
                        </div>
                    </div>
                    
                    <!-- Type Breakdown Stats -->
                    <div class="stats-container">
                        <div class="stat-card" data-type="fire">
                            <div class="stat-icon" style="background: rgba(239, 68, 68, 0.1); color: var(--icon-red);">
                                <i class='bx bxs-fire'></i>
                            </div>
                            <div class="stat-value"><?php echo $incident_counts['by_type']['fire']; ?></div>
                            <div class="stat-label">Fire Incidents</div>
                        </div>
                        <div class="stat-card" data-type="rescue">
                            <div class="stat-icon" style="background: rgba(16, 185, 129, 0.1); color: var(--success);">
                                <i class='bx bxs-first-aid'></i>
                            </div>
                            <div class="stat-value"><?php echo $incident_counts['by_type']['rescue']; ?></div>
                            <div class="stat-label">Rescue Operations</div>
                        </div>
                        <div class="stat-card" data-type="other_fire_rescue">
                            <div class="stat-icon" style="background: rgba(59, 130, 246, 0.1); color: var(--info);">
                                <i class='bx bxs-shield'></i>
                            </div>
                            <div class="stat-value"><?php echo $incident_counts['by_type']['other_fire_rescue']; ?></div>
                            <div class="stat-label">Related Incidents</div>
                        </div>
                    </div>
                    
                    <!-- Rescue Category Stats -->
                    <?php if ($incident_counts['by_type']['rescue'] > 0): ?>
                    <div class="stats-container">
                        <div class="stat-card" data-rescue="building_collapse">
                            <div class="stat-icon" style="background: rgba(245, 158, 11, 0.1); color: var(--warning);">
                                <i class='bx bxs-building-house'></i>
                            </div>
                            <div class="stat-value"><?php echo $incident_counts['by_rescue_category']['building_collapse']; ?></div>
                            <div class="stat-label">Building Collapse</div>
                        </div>
                        <div class="stat-card" data-rescue="vehicle_accident">
                            <div class="stat-icon" style="background: rgba(59, 130, 246, 0.1); color: var(--info);">
                                <i class='bx bxs-car'></i>
                            </div>
                            <div class="stat-value"><?php echo $incident_counts['by_rescue_category']['vehicle_accident']; ?></div>
                            <div class="stat-label">Vehicle Accidents</div>
                        </div>
                        <div class="stat-card" data-rescue="height_rescue">
                            <div class="stat-icon" style="background: rgba(139, 92, 246, 0.1); color: var(--purple);">
                                <i class='bx bxs-up-arrow'></i>
                            </div>
                            <div class="stat-value"><?php echo $incident_counts['by_rescue_category']['height_rescue']; ?></div>
                            <div class="stat-label">Height Rescue</div>
                        </div>
                        <div class="stat-card" data-rescue="water_rescue">
                            <div class="stat-icon" style="background: rgba(6, 182, 212, 0.1); color: var(--cyan);">
                                <i class='bx bxs-water'></i>
                            </div>
                            <div class="stat-value"><?php echo $incident_counts['by_rescue_category']['water_rescue']; ?></div>
                            <div class="stat-label">Water Rescue</div>
                        </div>
                        <div class="stat-card" data-rescue="other_rescue">
                            <div class="stat-icon" style="background: rgba(16, 185, 129, 0.1); color: var(--success);">
                                <i class='bx bxs-first-aid'></i>
                            </div>
                            <div class="stat-value"><?php echo $incident_counts['by_rescue_category']['other_rescue']; ?></div>
                            <div class="stat-label">Other Rescue</div>
                        </div>
                    </div>
                    <?php endif; ?>
                    
                    <!-- Emergency Level Stats -->
                    <div class="stats-container">
                        <div class="stat-card" data-emergency="low">
                            <div class="stat-icon">
                                <i class='bx bxs-check-circle'></i>
                            </div>
                            <div class="stat-value"><?php echo $incident_counts['by_emergency']['low']; ?></div>
                            <div class="stat-label">Low Priority</div>
                        </div>
                        <div class="stat-card" data-emergency="medium">
                            <div class="stat-icon">
                                <i class='bx bxs-info-circle'></i>
                            </div>
                            <div class="stat-value"><?php echo $incident_counts['by_emergency']['medium']; ?></div>
                            <div class="stat-label">Medium Priority</div>
                        </div>
                        <div class="stat-card" data-emergency="high">
                            <div class="stat-icon">
                                <i class='bx bxs-error'></i>
                            </div>
                            <div class="stat-value"><?php echo $incident_counts['by_emergency']['high']; ?></div>
                            <div class="stat-label">High Priority</div>
                        </div>
                        <div class="stat-card" data-emergency="critical">
                            <div class="stat-icon">
                                <i class='bx bxs-alarm'></i>
                            </div>
                            <div class="stat-value"><?php echo $incident_counts['by_emergency']['critical']; ?></div>
                            <div class="stat-label">Critical</div>
                        </div>
                    </div>
                    
                    <!-- Filters -->
                    <div class="filters-container">
                        <div class="filter-group">
                            <label class="filter-label">Status</label>
                            <select class="filter-select" id="status-filter">
                                <option value="all" <?php echo $status_filter === 'all' ? 'selected' : ''; ?>>All Statuses</option>
                                <option value="pending" <?php echo $status_filter === 'pending' ? 'selected' : ''; ?>>Pending</option>
                                <option value="processing" <?php echo $status_filter === 'processing' ? 'selected' : ''; ?>>Processing</option>
                                <option value="responded" <?php echo $status_filter === 'responded' ? 'selected' : ''; ?>>Responded</option>
                                <option value="closed" <?php echo $status_filter === 'closed' ? 'selected' : ''; ?>>Closed</option>
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
                                <option value="all" <?php echo $incident_type_filter === 'all' ? 'selected' : ''; ?>>All Fire & Rescue Types</option>
                                <option value="fire" <?php echo $incident_type_filter === 'fire' ? 'selected' : ''; ?>>Fire</option>
                                <option value="rescue" <?php echo $incident_type_filter === 'rescue' ? 'selected' : ''; ?>>Rescue Operations</option>
                                <option value="other_fire_rescue" <?php echo $incident_type_filter === 'other_fire_rescue' ? 'selected' : ''; ?>>Related Incidents</option>
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
                            <div>Emergency</div>
                            <div>Status</div>
                            <div>Source</div>
                            <div>Reported</div>
                            <div>Actions</div>
                        </div>
                        <div class="incident-table-container" id="incident-table-container">
                            <?php if (count($filtered_incidents) > 0): ?>
                                <?php foreach ($filtered_incidents as $incident): ?>
                                    <div class="table-row" data-id="<?php echo $incident['ID']; ?>" data-source="<?php echo $incident['Source']; ?>">
                                        <div class="table-cell">
                                            <div class="incident-id">#<?php echo $incident['ID']; ?></div>
                                        </div>
                                        <div class="table-cell">
                                            <div class="incident-location"><?php echo htmlspecialchars($incident['Location']); ?></div>
                                        </div>
                                        <div class="table-cell">
                                            <div class="incident-description"><?php echo htmlspecialchars($incident['Incident Description']); ?>
                                                <?php if ($incident['RescueCategory']): ?>
                                                    <?php 
                                                    $rescueClass = '';
                                                    $rescueText = '';
                                                    switch($incident['RescueCategory']) {
                                                        case 'building_collapse':
                                                            $rescueClass = 'rescue-building';
                                                            $rescueText = 'Building Collapse';
                                                            break;
                                                        case 'vehicle_accident':
                                                            $rescueClass = 'rescue-vehicle';
                                                            $rescueText = 'Vehicle Accident';
                                                            break;
                                                        case 'height_rescue':
                                                            $rescueClass = 'rescue-height';
                                                            $rescueText = 'Height Rescue';
                                                            break;
                                                        case 'water_rescue':
                                                            $rescueClass = 'rescue-water';
                                                            $rescueText = 'Water Rescue';
                                                            break;
                                                        case 'other_rescue':
                                                            $rescueClass = 'rescue-other';
                                                            $rescueText = 'Rescue Operation';
                                                            break;
                                                    }
                                                    ?>
                                                    <div class="rescue-badge <?php echo $rescueClass; ?>"><?php echo $rescueText; ?></div>
                                                <?php elseif ($incident['Incident Type'] == 'Fire'): ?>
                                                    <div class="fire-badge">Fire</div>
                                                <?php endif; ?>
                                            </div>
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
                                                <?php echo ucfirst($incident['Status']); ?>
                                            </div>
                                        </div>
                                        <div class="table-cell">
                                            <div class="source-badge">
                                                <?php echo strtoupper($incident['Source']); ?>
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
                                                <?php if (strtolower($incident['Status']) === 'pending' || strtolower($incident['Status']) === 'processing'): ?>
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
                                    <h3>No Fire & Rescue Incidents Found</h3>
                                    <p>No fire or rescue incidents match your current filters.</p>
                                    <p>Total fire & rescue incidents in system: <?php echo $incident_counts['total']; ?></p>
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
        let newIncidentsDetected = <?php echo !empty($new_incidents) ? 'true' : 'false'; ?>;
        
        // Notification sound
        const notificationSound = new Audio('data:audio/wav;base64,UklGRigAAABXQVZFZm10IBAAAAABAAEARKwAAIhYAQACABAAZGF0YQQAAAAAAA==');
        
        document.addEventListener('DOMContentLoaded', function() {
            // Initialize event listeners
            initEventListeners();
            
            // Start checking for new incidents
            startIncidentMonitoring();
            
            // Show welcome notification
            showNotification('success', 'System Ready', 'Fire and Rescue incident monitoring system is now active');
            
            // If new incident was detected, show alert
            if (newIncidentsDetected) {
                showNotification('warning', 'New Incident Detected', 'New fire/rescue incident(s) have been added to the system');
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
                    const emergency = this.getAttribute('data-emergency');
                    const type = this.getAttribute('data-type');
                    const rescue = this.getAttribute('data-rescue');
                    
                    if (status) {
                        document.getElementById('status-filter').value = status;
                        applyFilters();
                    } else if (emergency) {
                        document.getElementById('emergency-level-filter').value = emergency;
                        applyFilters();
                    } else if (type) {
                        document.getElementById('incident-type-filter').value = type;
                        applyFilters();
                    } else if (rescue) {
                        document.getElementById('incident-type-filter').value = 'rescue';
                        applyFilters();
                    }
                });
            });
            
            // Filter tabs
            document.querySelectorAll('.filter-tab').forEach(tab => {
                tab.addEventListener('click', function() {
                    const filterValue = this.getAttribute('data-value');
                    const filterType = this.getAttribute('data-type');
                    
                    if (filterValue) {
                        document.getElementById('status-filter').value = filterValue;
                        applyFilters();
                    } else if (filterType) {
                        document.getElementById('incident-type-filter').value = filterType;
                        applyFilters();
                    }
                });
            });
            
            // Export and refresh buttons
            document.getElementById('export-button').addEventListener('click', exportReport);
            document.getElementById('refresh-button').addEventListener('click', refreshData);
            
            // Sound toggle
            document.getElementById('sound-toggle').addEventListener('click', toggleSound);
            
            // View modal functionality
            document.getElementById('view-modal-close').addEventListener('click', closeViewModal);
            document.getElementById('view-modal').addEventListener('click', function(e) {
                if (e.target === this) {
                    closeViewModal();
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
            // Check for new incidents every 30 seconds
            checkInterval = setInterval(checkForNewIncidents, 30000);
        }
        
        function checkForNewIncidents() {
            fetch(`https://ecs.jampzdev.com/api/emergencies/active`)
                .then(response => response.json())
                .then(data => {
                    if (data && data.success && data.data && data.data.length > 0) {
                        // Check if there are new incidents
                        const latestIncidentId = Math.max(...data.data.map(inc => inc.id));
                        
                        if (latestIncidentId > lastIncidentId) {
                            // Send request to sync with database
                            fetch('sync_incidents.php', {
                                method: 'POST',
                                headers: {
                                    'Content-Type': 'application/json',
                                },
                                body: JSON.stringify({ incidents: data.data })
                            })
                            .then(response => response.json())
                            .then(syncResult => {
                                if (syncResult.success && syncResult.new_incidents > 0) {
                                    // Show notification and update page
                                    showNewIncidentNotification(syncResult.new_incidents);
                                    setTimeout(() => {
                                        location.reload();
                                    }, 3000);
                                }
                            })
                            .catch(error => {
                                console.error('Error syncing incidents:', error);
                            });
                        }
                    }
                })
                .catch(error => {
                    console.error('Error checking for new incidents:', error);
                    showNotification('error', 'API Error', 'Failed to check for new incidents');
                });
        }
        
        function showNewIncidentNotification(count) {
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
            notificationCount.textContent = currentCount + count;
            
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
                    <div class="notification-item-title">${count} New Fire/Rescue Incident${count > 1 ? 's' : ''} Detected</div>
                    <div class="notification-item-message">New incident${count > 1 ? 's have' : ' has'} been added to the system</div>
                    <div class="notification-item-time">Just now</div>
                </div>
            `;
            
            notificationList.insertBefore(notificationItem, notificationList.firstChild);
            
            // Show desktop notification
            if (Notification.permission === 'granted') {
                new Notification('New Fire & Rescue Incident' + (count > 1 ? 's' : ''), {
                    body: `${count} new incident${count > 1 ? 's have' : ' has'} been detected and added to the system`,
                    icon: '../../img/frsm-logo.png'
                });
            }
            
            // Show in-page notification
            showNotification('warning', 'New Incident' + (count > 1 ? 's' : '') + ' Detected', 
                           `${count} new fire/rescue incident${count > 1 ? 's have' : ' has'} been added to the system. Page will refresh in 3 seconds...`);
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
            
            // Fetch incident from database
            fetch(`get_incident_details.php?id=${id}`)
                .then(response => response.json())
                .then(data => {
                    if (data && data.success) {
                        displayIncidentDetails(data.incident);
                    } else {
                        document.getElementById('view-modal-body').innerHTML = `
                            <div style="text-align: center; padding: 40px;">
                                <i class='bx bxs-error' style="font-size: 40px; color: var(--danger);"></i>
                                <p style="margin-top: 16px;">Incident not found</p>
                            </div>
                        `;
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
            const reportedDate = new Date(incident.created_at);
            const resolvedDate = incident.responded_at ? new Date(incident.responded_at) : null;
            
            // Determine rescue category display
            let rescueDisplay = '';
            if (incident.rescue_category) {
                let rescueText = '';
                let rescueClass = '';
                
                switch(incident.rescue_category) {
                    case 'building_collapse':
                        rescueText = 'Building Collapse';
                        rescueClass = 'rescue-building';
                        break;
                    case 'vehicle_accident':
                        rescueText = 'Vehicle Accident';
                        rescueClass = 'rescue-vehicle';
                        break;
                    case 'height_rescue':
                        rescueText = 'Height Rescue';
                        rescueClass = 'rescue-height';
                        break;
                    case 'water_rescue':
                        rescueText = 'Water Rescue';
                        rescueClass = 'rescue-water';
                        break;
                    case 'other_rescue':
                        rescueText = 'Rescue Operation';
                        rescueClass = 'rescue-other';
                        break;
                }
                
                rescueDisplay = `<div class="rescue-badge ${rescueClass}" style="display: inline-block; margin-left: 8px;">${rescueText}</div>`;
            }
            
            modalBody.innerHTML = `
                <div class="modal-section">
                    <h3 class="modal-section-title">Fire & Rescue Incident Information</h3>
                    <div class="modal-grid">
                        <div class="modal-detail">
                            <div class="modal-detail-label">Incident ID</div>
                            <div class="modal-detail-value">#${incident.external_id}</div>
                        </div>
                        <div class="modal-detail">
                            <div class="modal-detail-label">Incident Type</div>
                            <div class="modal-detail-value">
                                ${escapeHtml(incident.emergency_type ? incident.emergency_type.charAt(0).toUpperCase() + incident.emergency_type.slice(1) : 'N/A')}
                                ${rescueDisplay}
                            </div>
                        </div>
                        <div class="modal-detail">
                            <div class="modal-detail-label">Emergency Level</div>
                            <div class="emergency-badge emergency-${incident.severity ? incident.severity.toLowerCase() : 'medium'}">
                                ${incident.severity ? incident.severity.charAt(0).toUpperCase() + incident.severity.slice(1) : 'Medium'}
                            </div>
                        </div>
                        <div class="modal-detail">
                            <div class="modal-detail-label">Status</div>
                            <div class="status-badge status-${incident.status ? incident.status.toLowerCase() : 'pending'}">
                                ${incident.status ? incident.status.charAt(0).toUpperCase() + incident.status.slice(1) : 'Pending'}
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="modal-section">
                    <h3 class="modal-section-title">Location Details</h3>
                    <div class="modal-grid">
                        <div class="modal-detail">
                            <div class="modal-detail-label">Location</div>
                            <div class="modal-detail-value">${escapeHtml(incident.location)}</div>
                        </div>
                        <div class="modal-detail">
                            <div class="modal-detail-label">Incident Description</div>
                            <div class="modal-detail-value">${escapeHtml(incident.description)}</div>
                        </div>
                        <div class="modal-detail">
                            <div class="modal-detail-label">Affected Barangays</div>
                            <div class="modal-detail-value">${escapeHtml(incident.affected_barangays)}</div>
                        </div>
                        <div class="modal-detail">
                            <div class="modal-detail-label">Assistance Needed</div>
                            <div class="modal-detail-value">${escapeHtml(incident.assistance_needed)}</div>
                        </div>
                    </div>
                </div>
                
                <div class="modal-section">
                    <h3 class="modal-section-title">Caller Information</h3>
                    <div class="modal-grid">
                        <div class="modal-detail">
                            <div class="modal-detail-label">Caller Name</div>
                            <div class="modal-detail-value">${escapeHtml(incident.caller_name)}</div>
                        </div>
                        <div class="modal-detail">
                            <div class="modal-detail-label">Phone Number</div>
                            <div class="modal-detail-value">${escapeHtml(incident.caller_phone)}</div>
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
                            <div class="modal-detail-label">Date Responded</div>
                            <div class="modal-detail-value">${resolvedDate.toLocaleString()}</div>
                        </div>
                        ` : ''}
                        <div class="modal-detail">
                            <div class="modal-detail-label">Alert Type</div>
                            <div class="modal-detail-value">${escapeHtml(incident.alert_type)}</div>
                        </div>
                        <div class="modal-detail">
                            <div class="modal-detail-label">Issued By</div>
                            <div class="modal-detail-value">${escapeHtml(incident.issued_by)}</div>
                        </div>
                    </div>
                </div>
                
                ${incident.notes ? `
                <div class="modal-section">
                    <h3 class="modal-section-title">Additional Notes</h3>
                    <div class="modal-detail">
                        <div class="modal-detail-value">${escapeHtml(incident.notes)}</div>
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
            window.location.href = `../dc/select_unit.php?incident=${id}&source=api`;
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