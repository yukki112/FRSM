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
$volunteer_contact = htmlspecialchars($volunteer['contact_number']);
$unit_id = $volunteer['unit_id'];
$unit_name = htmlspecialchars($volunteer['unit_name']);
$unit_code = htmlspecialchars($volunteer['unit_code']);

// Get unit information
$unit_query = "SELECT * FROM units WHERE id = ?";
$unit_stmt = $pdo->prepare($unit_query);
$unit_stmt->execute([$unit_id]);
$unit_info = $unit_stmt->fetch();

// Check if viewing incident details
$incident_id = isset($_GET['incident_id']) ? intval($_GET['incident_id']) : null;

if ($incident_id) {
    // Get specific incident details
    $incident_details_query = "
        SELECT 
            ai.*,
            di.id as dispatch_id,
            di.status as dispatch_status,
            di.dispatched_at,
            di.status_updated_at,
            di.er_notes,
            di.vehicles_json,
            u.unit_name,
            u.unit_code,
            u.unit_type,
            CASE 
                WHEN di.status = 'pending' THEN 'suggested'
                WHEN di.status = 'dispatched' THEN 'dispatched'
                WHEN di.status = 'en_route' THEN 'en_route'
                WHEN di.status = 'arrived' THEN 'arrived'
                WHEN di.status = 'completed' THEN 'completed'
                WHEN di.status = 'cancelled' THEN 'cancelled'
                ELSE 'available'
            END as unit_involvement_status
        FROM api_incidents ai
        LEFT JOIN dispatch_incidents di ON ai.id = di.incident_id
        LEFT JOIN units u ON di.unit_id = u.id
        WHERE ai.id = ? AND (di.unit_id = ? OR di.unit_id IS NULL)
    ";
    
    $incident_details_stmt = $pdo->prepare($incident_details_query);
    $incident_details_stmt->execute([$incident_id, $unit_id]);
    $incident_details = $incident_details_stmt->fetch();
    
    if (!$incident_details) {
        // Incident not found or not related to user's unit
        header("Location: active_incidents.php");
        exit();
    }
    
    // Get vehicles for this incident
    $incident_vehicles = [];
    if (!empty($incident_details['vehicles_json'])) {
        $vehicles_data = json_decode($incident_details['vehicles_json'], true);
        if (is_array($vehicles_data)) {
            $incident_vehicles = $vehicles_data;
        }
    }
    
    // Get all volunteers in the unit
    $volunteers_query = "
        SELECT 
            v.id,
            v.first_name,
            v.middle_name,
            v.last_name,
            v.contact_number,
            v.email,
            v.volunteer_status,
            v.skills_basic_firefighting,
            v.skills_first_aid_cpr,
            v.skills_search_rescue,
            v.skills_driving,
            v.skills_communication,
            v.available_days,
            v.available_hours,
            v.emergency_response,
            u.username
        FROM volunteers v
        LEFT JOIN users u ON v.user_id = u.id
        LEFT JOIN volunteer_assignments va ON v.id = va.volunteer_id AND va.status = 'Active'
        WHERE va.unit_id = ? 
          AND v.status = 'approved'
        ORDER BY v.last_name, v.first_name
    ";
    
    $volunteers_stmt = $pdo->prepare($volunteers_query);
    $volunteers_stmt->execute([$unit_id]);
    $unit_volunteers = $volunteers_stmt->fetchAll();
    
} else {
    // 1. GET ALL INCIDENTS ASSIGNED/SUGGESTED TO OUR UNIT
    $incidents_query = "
        SELECT 
            ai.*,
            di.id as dispatch_id,
            di.status as dispatch_status,
            di.dispatched_at,
            di.status_updated_at,
            di.er_notes,
            di.vehicles_json,
            u.unit_name,
            u.unit_code,
            u.unit_type,
            CASE 
                WHEN di.status = 'pending' THEN 'suggested'
                WHEN di.status = 'dispatched' THEN 'dispatched'
                WHEN di.status = 'en_route' THEN 'en_route'
                WHEN di.status = 'arrived' THEN 'arrived'
                WHEN di.status = 'completed' THEN 'completed'
                WHEN di.status = 'cancelled' THEN 'cancelled'
                ELSE 'available'
            END as unit_involvement_status
        FROM api_incidents ai
        LEFT JOIN dispatch_incidents di ON ai.id = di.incident_id
        LEFT JOIN units u ON di.unit_id = u.id
        WHERE (
            di.unit_id = ? 
            OR (
                ai.dispatch_status = 'processing' 
                AND ai.dispatch_id IN (
                    SELECT id FROM dispatch_incidents WHERE unit_id = ? AND status = 'pending'
                )
            )
        )
        AND ai.status IN ('pending', 'processing', 'responded')
        ORDER BY 
            CASE 
                WHEN di.status = 'dispatched' THEN 1
                WHEN di.status = 'en_route' THEN 2
                WHEN di.status = 'arrived' THEN 3
                WHEN di.status = 'pending' THEN 4
                WHEN di.status = 'cancelled' THEN 5
                ELSE 6
            END,
            ai.severity DESC,
            ai.created_at DESC
    ";

    $incidents_stmt = $pdo->prepare($incidents_query);
    $incidents_stmt->execute([$unit_id, $unit_id]);
    $incidents = $incidents_stmt->fetchAll();

    // 2. GET ALL VOLUNTEERS IN THE UNIT
    $volunteers_query = "
        SELECT 
            v.id,
            v.first_name,
            v.middle_name,
            v.last_name,
            v.contact_number,
            v.email,
            v.volunteer_status,
            v.skills_basic_firefighting,
            v.skills_first_aid_cpr,
            v.skills_search_rescue,
            v.skills_driving,
            v.skills_communication,
            v.available_days,
            v.available_hours,
            v.emergency_response,
            u.username
        FROM volunteers v
        LEFT JOIN users u ON v.user_id = u.id
        LEFT JOIN volunteer_assignments va ON v.id = va.volunteer_id AND va.status = 'Active'
        WHERE va.unit_id = ? 
          AND v.status = 'approved'
        ORDER BY v.last_name, v.first_name
    ";

    $volunteers_stmt = $pdo->prepare($volunteers_query);
    $volunteers_stmt->execute([$unit_id]);
    $unit_volunteers = $volunteers_stmt->fetchAll();

    // 3. GET VEHICLES ASSIGNED TO OUR UNIT (available, suggested, or dispatched)
    $vehicles_query = "
        SELECT 
            vs.*,
            di.status as dispatch_status,
            ai.title as incident_title,
            ai.location as incident_location,
            CASE 
                WHEN vs.status = 'suggested' THEN 'suggested'
                WHEN vs.status = 'dispatched' THEN 'dispatched'
                WHEN vs.status = 'available' THEN 'available'
                ELSE vs.status
            END as display_status
        FROM vehicle_status vs
        LEFT JOIN dispatch_incidents di ON vs.suggestion_id = di.id OR vs.dispatch_id = di.id
        LEFT JOIN api_incidents ai ON di.incident_id = ai.id
        WHERE vs.unit_id = ?
        ORDER BY 
            CASE vs.status
                WHEN 'dispatched' THEN 1
                WHEN 'suggested' THEN 2
                WHEN 'available' THEN 3
                ELSE 4
            END,
            vs.last_updated DESC
    ";

    $vehicles_stmt = $pdo->prepare($vehicles_query);
    $vehicles_stmt->execute([$unit_id]);
    $unit_vehicles = $vehicles_stmt->fetchAll();

    // 4. GET PENDING SUGGESTIONS FOR OUR UNIT
    $pending_suggestions_query = "
        SELECT 
            di.*,
            ai.id as incident_id,
            ai.title,
            ai.location,
            ai.severity,
            ai.emergency_type,
            ai.description,
            ai.caller_name,
            ai.caller_phone,
            ai.created_at as incident_created,
            u.unit_name,
            u.unit_code,
            (SELECT COUNT(*) FROM vehicle_status vs WHERE vs.suggestion_id = di.id) as suggested_vehicle_count
        FROM dispatch_incidents di
        JOIN api_incidents ai ON di.incident_id = ai.id
        JOIN units u ON di.unit_id = u.id
        WHERE di.unit_id = ?
          AND di.status = 'pending'
          AND ai.dispatch_status = 'processing'
        ORDER BY ai.severity DESC, di.dispatched_at DESC
    ";

    $pending_suggestions_stmt = $pdo->prepare($pending_suggestions_query);
    $pending_suggestions_stmt->execute([$unit_id]);
    $pending_suggestions = $pending_suggestions_stmt->fetchAll();

    // 5. GET ACTIVE DISPATCHES FOR OUR UNIT
    $active_dispatches_query = "
        SELECT 
            di.*,
            ai.id as incident_id,
            ai.title,
            ai.location,
            ai.severity,
            ai.emergency_type,
            ai.description,
            ai.caller_name,
            ai.caller_phone,
            u.unit_name,
            u.unit_code,
            (SELECT COUNT(*) FROM vehicle_status vs WHERE vs.dispatch_id = di.id) as dispatched_vehicle_count
        FROM dispatch_incidents di
        JOIN api_incidents ai ON di.incident_id = ai.id
        JOIN units u ON di.unit_id = u.id
        WHERE di.unit_id = ?
          AND di.status IN ('dispatched', 'en_route', 'arrived')
        ORDER BY 
            CASE di.status
                WHEN 'dispatched' THEN 1
                WHEN 'en_route' THEN 2
                WHEN 'arrived' THEN 3
            END,
            di.dispatched_at DESC
    ";

    $active_dispatches_stmt = $pdo->prepare($active_dispatches_query);
    $active_dispatches_stmt->execute([$unit_id]);
    $active_dispatches = $active_dispatches_stmt->fetchAll();

    // Calculate statistics
    $total_incidents = count($incidents);
    $suggested_incidents = 0;
    $dispatched_incidents = 0;
    $active_dispatches_count = count($active_dispatches);
    $total_volunteers = count($unit_volunteers);
    $available_vehicles = 0;
    $dispatched_vehicles = 0;
    $suggested_vehicles = 0;

    foreach ($incidents as $incident) {
        if ($incident['unit_involvement_status'] === 'suggested') {
            $suggested_incidents++;
        } elseif (in_array($incident['unit_involvement_status'], ['dispatched', 'en_route', 'arrived'])) {
            $dispatched_incidents++;
        }
    }

    foreach ($unit_vehicles as $vehicle) {
        if ($vehicle['display_status'] === 'available') {
            $available_vehicles++;
        } elseif ($vehicle['display_status'] === 'dispatched') {
            $dispatched_vehicles++;
        } elseif ($vehicle['display_status'] === 'suggested') {
            $suggested_vehicles++;
        }
    }

    // Calculate response readiness
    $response_readiness = 0;
    if ($total_volunteers > 0) {
        $ready_volunteers = array_filter($unit_volunteers, function($vol) {
            return $vol['emergency_response'] == 1;
        });
        $response_readiness = round((count($ready_volunteers) / $total_volunteers) * 100);
    }
}

// Get notifications for this user
$notifications_query = "
    SELECT * FROM notifications 
    WHERE user_id = ? 
      AND is_read = 0
      AND type = 'dispatch'
    ORDER BY created_at DESC
    LIMIT 10
";

$notifications_stmt = $pdo->prepare($notifications_query);
$notifications_stmt->execute([$user_id]);
$notifications = $notifications_stmt->fetchAll();

// Mark notifications as read when viewing this page
if (!empty($notifications)) {
    $mark_read_query = "UPDATE notifications SET is_read = 1 WHERE user_id = ? AND type = 'dispatch'";
    $mark_read_stmt = $pdo->prepare($mark_read_query);
    $mark_read_stmt->execute([$user_id]);
}

// Handle filters (only for main incidents page)
if (!$incident_id) {
    $status_filter = $_GET['status'] ?? 'all';
    $severity_filter = $_GET['severity'] ?? 'all';
    $type_filter = $_GET['type'] ?? 'all';

    // Filter incidents
    $filtered_incidents = [];
    foreach ($incidents as $incident) {
        $match = true;
        
        if ($status_filter !== 'all') {
            if ($status_filter === 'suggested' && $incident['unit_involvement_status'] !== 'suggested') {
                $match = false;
            } elseif ($status_filter === 'dispatched' && !in_array($incident['unit_involvement_status'], ['dispatched', 'en_route', 'arrived'])) {
                $match = false;
            } elseif ($status_filter === 'active' && !in_array($incident['dispatch_status'], ['dispatched', 'en_route', 'arrived'])) {
                $match = false;
            }
        }
        
        if ($severity_filter !== 'all' && $incident['severity'] !== $severity_filter) {
            $match = false;
        }
        
        if ($type_filter !== 'all' && $incident['emergency_type'] !== $type_filter) {
            $match = false;
        }
        
        if ($match) {
            $filtered_incidents[] = $incident;
        }
    }
}

// Close statements
$stmt = null;
$volunteer_stmt = null;
$unit_stmt = null;
if (isset($incidents_stmt)) $incidents_stmt = null;
if (isset($volunteers_stmt)) $volunteers_stmt = null;
if (isset($vehicles_stmt)) $vehicles_stmt = null;
if (isset($pending_suggestions_stmt)) $pending_suggestions_stmt = null;
if (isset($active_dispatches_stmt)) $active_dispatches_stmt = null;
if (isset($incident_details_stmt)) $incident_details_stmt = null;
$notifications_stmt = null;
if (isset($mark_read_stmt)) $mark_read_stmt = null;
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $incident_id ? 'Incident Details' : 'Active Incidents'; ?> - Fire & Rescue Services Management</title>
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

        /* Enhanced Statistics Dashboard */
        .stats-dashboard {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .stat-card-enhanced {
            background: var(--background-color);
            border: 1px solid var(--border-color);
            border-radius: 12px;
            padding: 25px;
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
        }

        .stat-card-enhanced:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.1);
        }

        .stat-card-enhanced.urgent {
            border-left: 4px solid var(--danger);
        }

        .stat-card-enhanced.warning {
            border-left: 4px solid var(--warning);
        }

        .stat-card-enhanced.info {
            border-left: 4px solid var(--info);
        }

        .stat-card-enhanced.success {
            border-left: 4px solid var(--success);
        }

        .stat-icon {
            width: 60px;
            height: 60px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 15px;
            font-size: 24px;
        }

        .stat-icon.urgent {
            background: linear-gradient(135deg, rgba(220, 38, 38, 0.1), rgba(220, 38, 38, 0.2));
            color: var(--danger);
        }

        .stat-icon.warning {
            background: linear-gradient(135deg, rgba(245, 158, 11, 0.1), rgba(245, 158, 11, 0.2));
            color: var(--warning);
        }

        .stat-icon.info {
            background: linear-gradient(135deg, rgba(59, 130, 246, 0.1), rgba(59, 130, 246, 0.2));
            color: var(--info);
        }

        .stat-icon.success {
            background: linear-gradient(135deg, rgba(16, 185, 129, 0.1), rgba(16, 185, 129, 0.2));
            color: var(--success);
        }

        .stat-value {
            font-size: 32px;
            font-weight: 800;
            margin-bottom: 5px;
        }

        .stat-label {
            font-size: 14px;
            color: var(--text-light);
            margin-bottom: 8px;
            font-weight: 600;
        }

        .stat-subtext {
            font-size: 12px;
            color: var(--text-light);
        }

        /* Unit Overview */
        .unit-overview {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .unit-card {
            background: linear-gradient(135deg, #fef2f2 0%, #fee2e2 100%);
            border: 1px solid #fecaca;
            border-radius: 12px;
            padding: 25px;
            position: relative;
            overflow: hidden;
        }

        .dark-mode .unit-card {
            background: linear-gradient(135deg, #1e293b 0%, #2d3748 100%);
            border-color: #4b5563;
        }

        .unit-card-title {
            font-size: 18px;
            font-weight: 700;
            color: var(--danger);
            margin-bottom: 15px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .unit-details-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 15px;
            margin-top: 15px;
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

        .readiness-indicator {
            margin-top: 20px;
        }

        .readiness-bar {
            height: 8px;
            background: var(--gray-200);
            border-radius: 4px;
            overflow: hidden;
            margin-bottom: 8px;
        }

        .readiness-fill {
            height: 100%;
            background: linear-gradient(90deg, var(--primary-color), var(--secondary-color));
            border-radius: 4px;
        }

        /* Main Content Grid */
        .main-grid {
            display: grid;
            grid-template-columns: 1fr 350px;
            gap: 30px;
        }

        @media (max-width: 1200px) {
            .main-grid {
                grid-template-columns: 1fr;
            }
        }

        /* Incidents Section */
        .incidents-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 25px;
        }

        .filter-container {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 15px;
            margin-bottom: 25px;
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

        .filter-select {
            padding: 10px 12px;
            border: 1px solid var(--border-color);
            border-radius: 8px;
            background: var(--background-color);
            color: var(--text-color);
            font-size: 14px;
        }

        .filter-select:focus {
            outline: none;
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(220, 38, 38, 0.1);
        }

        .filter-actions {
            display: flex;
            align-items: flex-end;
            gap: 10px;
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

        /* Incident Cards */
        .incident-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(400px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        @media (max-width: 768px) {
            .incident-grid {
                grid-template-columns: 1fr;
            }
        }

        .incident-card {
            background: var(--background-color);
            border: 1px solid var(--border-color);
            border-radius: 12px;
            padding: 20px;
            transition: all 0.3s ease;
            position: relative;
        }

        .incident-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 16px rgba(0, 0, 0, 0.1);
        }

        .incident-card.suggested {
            border-left: 4px solid var(--warning);
        }

        .incident-card.dispatched {
            border-left: 4px solid var(--info);
        }

        .incident-card.en_route {
            border-left: 4px solid var(--purple);
        }

        .incident-card.arrived {
            border-left: 4px solid var(--success);
        }

        .incident-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 15px;
            padding-bottom: 15px;
            border-bottom: 1px solid var(--border-color);
        }

        .incident-title-section {
            flex: 1;
        }

        .incident-title {
            margin: 0 0 8px 0;
            color: var(--text-color);
            font-size: 18px;
            font-weight: 700;
        }

        .incident-meta {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            align-items: center;
        }

        .incident-status {
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .status-suggested {
            background: rgba(245, 158, 11, 0.1);
            color: var(--warning);
            border: 1px solid rgba(245, 158, 11, 0.2);
        }

        .status-dispatched {
            background: rgba(59, 130, 246, 0.1);
            color: var(--info);
            border: 1px solid rgba(59, 130, 246, 0.2);
        }

        .status-en_route {
            background: rgba(139, 92, 246, 0.1);
            color: var(--purple);
            border: 1px solid rgba(139, 92, 246, 0.2);
        }

        .status-arrived {
            background: rgba(16, 185, 129, 0.1);
            color: var(--success);
            border: 1px solid rgba(16, 185, 129, 0.2);
        }

        .severity-badge {
            padding: 4px 10px;
            border-radius: 4px;
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
        }

        .severity-critical {
            background: rgba(220, 38, 38, 0.1);
            color: var(--danger);
            border: 1px solid rgba(220, 38, 38, 0.2);
        }

        .severity-high {
            background: rgba(245, 158, 11, 0.1);
            color: var(--warning);
            border: 1px solid rgba(245, 158, 11, 0.2);
        }

        .severity-medium {
            background: rgba(59, 130, 246, 0.1);
            color: var(--info);
            border: 1px solid rgba(59, 130, 246, 0.2);
        }

        .severity-low {
            background: rgba(107, 114, 128, 0.1);
            color: var(--gray-500);
            border: 1px solid rgba(107, 114, 128, 0.2);
        }

        /* Incident Details Page Styles */
        .incident-details-container {
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: 16px;
            padding: 30px;
            margin-bottom: 30px;
        }

        .back-button {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 10px 20px;
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: 8px;
            color: var(--text-color);
            text-decoration: none;
            margin-bottom: 20px;
            transition: all 0.3s ease;
        }

        .back-button:hover {
            background: var(--gray-100);
            transform: translateX(-5px);
        }

        .dark-mode .back-button:hover {
            background: var(--gray-800);
        }

        .details-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 30px;
            margin-top: 20px;
        }

        @media (max-width: 992px) {
            .details-grid {
                grid-template-columns: 1fr;
            }
        }

        .details-section {
            background: var(--background-color);
            border: 1px solid var(--border-color);
            border-radius: 12px;
            padding: 20px;
        }

        .details-title {
            font-size: 18px;
            font-weight: 700;
            color: var(--text-color);
            margin-bottom: 15px;
            padding-bottom: 10px;
            border-bottom: 2px solid var(--border-color);
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
        }

        .info-item {
            display: flex;
            flex-direction: column;
        }

        .info-label {
            font-size: 12px;
            color: var(--text-light);
            margin-bottom: 4px;
            font-weight: 600;
        }

        .info-value {
            font-weight: 600;
            color: var(--text-color);
            font-size: 14px;
        }

        .description-box {
            padding: 15px;
            background: var(--gray-100);
            border: 1px solid var(--border-color);
            border-radius: 8px;
            margin-top: 15px;
            font-size: 14px;
            line-height: 1.6;
        }

        .dark-mode .description-box {
            background: var(--gray-800);
        }

        /* Volunteers and Vehicles Lists */
        .resource-list {
            max-height: 400px;
            overflow-y: auto;
        }

        .resource-item {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 12px;
            border-bottom: 1px solid var(--border-color);
            transition: all 0.3s ease;
        }

        .resource-item:hover {
            background: var(--gray-100);
        }

        .dark-mode .resource-item:hover {
            background: var(--gray-800);
        }

        .resource-item:last-child {
            border-bottom: none;
        }

        .resource-avatar {
            width: 45px;
            height: 45px;
            border-radius: 50%;
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            font-size: 16px;
        }

        .resource-info {
            flex: 1;
        }

        .resource-info h4 {
            margin: 0 0 4px 0;
            color: var(--text-color);
            font-size: 14px;
        }

        .resource-info p {
            margin: 0;
            color: var(--text-light);
            font-size: 11px;
        }

        .resource-skills {
            display: flex;
            flex-wrap: wrap;
            gap: 4px;
            margin-top: 4px;
        }

        .skill-badge {
            padding: 2px 6px;
            border-radius: 4px;
            font-size: 9px;
            font-weight: 600;
            background: var(--gray-100);
            color: var(--text-light);
        }

        .dark-mode .skill-badge {
            background: var(--gray-800);
        }

        .skill-badge.fire {
            background: rgba(220, 38, 38, 0.1);
            color: var(--danger);
        }

        .skill-badge.medical {
            background: rgba(16, 185, 129, 0.1);
            color: var(--success);
        }

        .skill-badge.rescue {
            background: rgba(245, 158, 11, 0.1);
            color: var(--warning);
        }

        .skill-badge.driving {
            background: rgba(59, 130, 246, 0.1);
            color: var(--info);
        }

        .vehicle-details {
            display: flex;
            align-items: center;
            justify-content: space-between;
            width: 100%;
        }

        .vehicle-status {
            padding: 4px 10px;
            border-radius: 6px;
            font-size: 11px;
            font-weight: 600;
        }

        .status-available {
            background: rgba(16, 185, 129, 0.1);
            color: var(--success);
            border: 1px solid rgba(16, 185, 129, 0.2);
        }

        .status-dispatched {
            background: rgba(59, 130, 246, 0.1);
            color: var(--info);
            border: 1px solid rgba(59, 130, 246, 0.2);
        }

        .status-suggested {
            background: rgba(245, 158, 11, 0.1);
            color: var(--warning);
            border: 1px solid rgba(245, 158, 11, 0.2);
        }

        /* Sidebar Sections */
        .sidebar-section {
            margin-bottom: 30px;
        }

        .sidebar-card {
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 20px;
        }

        .sidebar-title {
            font-size: 16px;
            font-weight: 700;
            color: var(--text-color);
            margin-bottom: 15px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .volunteers-list {
            max-height: 300px;
            overflow-y: auto;
        }

        .volunteer-item {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 10px;
            border-bottom: 1px solid var(--border-color);
            transition: all 0.3s ease;
        }

        .volunteer-item:hover {
            background: var(--gray-100);
        }

        .dark-mode .volunteer-item:hover {
            background: var(--gray-800);
        }

        .volunteer-item:last-child {
            border-bottom: none;
        }

        .volunteer-avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            font-size: 14px;
        }

        .volunteer-info h4 {
            margin: 0 0 4px 0;
            color: var(--text-color);
            font-size: 14px;
        }

        .volunteer-info p {
            margin: 0;
            color: var(--text-light);
            font-size: 11px;
        }

        .volunteer-status {
            padding: 2px 8px;
            border-radius: 10px;
            font-size: 9px;
            font-weight: 600;
            text-transform: uppercase;
            margin-left: auto;
        }

        .status-active {
            background: rgba(16, 185, 129, 0.1);
            color: var(--success);
            border: 1px solid rgba(16, 185, 129, 0.2);
        }

        .vehicles-list {
            display: flex;
            flex-direction: column;
            gap: 10px;
        }

        .vehicle-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 12px;
            background: var(--background-color);
            border: 1px solid var(--border-color);
            border-radius: 8px;
        }

        .vehicle-info h4 {
            margin: 0 0 4px 0;
            color: var(--text-color);
            font-size: 14px;
        }

        .vehicle-info p {
            margin: 0;
            color: var(--text-light);
            font-size: 11px;
        }

        /* Empty States */
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

        /* Notification Styles */
        .notification-badge {
            position: absolute;
            top: 10px;
            right: 10px;
            background: var(--danger);
            color: white;
            border-radius: 50%;
            width: 20px;
            height: 20px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 10px;
            font-weight: 700;
        }

        .notification-panel {
            position: fixed;
            top: 100px;
            right: 20px;
            width: 350px;
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: 12px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.15);
            z-index: 1000;
            display: none;
        }

        .notification-panel.show {
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
            font-size: 16px;
            font-weight: 700;
            color: var(--text-color);
        }

        .notification-close {
            background: none;
            border: none;
            color: var(--text-light);
            cursor: pointer;
            font-size: 20px;
        }

        .notification-list {
            max-height: 400px;
            overflow-y: auto;
        }

        .notification-item {
            padding: 15px;
            border-bottom: 1px solid var(--border-color);
            transition: all 0.3s ease;
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

        .notification-message {
            font-size: 13px;
            color: var(--text-color);
            margin-bottom: 5px;
        }

        .notification-time {
            font-size: 11px;
            color: var(--text-light);
        }

        .notification-bell {
            position: relative;
            cursor: pointer;
            margin-right: 15px;
        }

        .notification-count {
            position: absolute;
            top: -5px;
            right: -5px;
            background: var(--danger);
            color: white;
            border-radius: 50%;
            width: 18px;
            height: 18px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 10px;
            font-weight: 700;
        }

        /* Responsive Design */
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
            
            .stats-dashboard {
                grid-template-columns: repeat(2, 1fr);
            }
            
            .unit-overview {
                grid-template-columns: 1fr;
            }
            
            .main-grid {
                grid-template-columns: 1fr;
            }
            
            .filter-container {
                grid-template-columns: 1fr;
            }
            
            .notification-panel {
                width: 300px;
                right: 10px;
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
            
            .stats-dashboard {
                grid-template-columns: 1fr;
            }
            
            .incident-grid {
                grid-template-columns: 1fr;
            }
            
            .filter-actions {
                flex-direction: column;
            }
            
            .notification-panel {
                width: 90%;
                right: 5%;
                left: 5%;
            }
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

        /* Badge Styles */
        .badge {
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
        }

        .badge-info {
            background: rgba(59, 130, 246, 0.1);
            color: var(--info);
            border: 1px solid rgba(59, 130, 246, 0.2);
        }

        .badge-success {
            background: rgba(16, 185, 129, 0.1);
            color: var(--success);
            border: 1px solid rgba(16, 185, 129, 0.2);
        }

        .badge-warning {
            background: rgba(245, 158, 11, 0.1);
            color: var(--warning);
            border: 1px solid rgba(245, 158, 11, 0.2);
        }

        .badge-danger {
            background: rgba(220, 38, 38, 0.1);
            color: var(--danger);
            border: 1px solid rgba(220, 38, 38, 0.2);
        }
        
        /* Action Buttons */
        .action-buttons {
            display: flex;
            gap: 10px;
            margin-top: 15px;
            flex-wrap: wrap;
        }
        
        .info-row {
            display: flex;
            margin-bottom: 8px;
            font-size: 13px;
        }
        
        .info-label {
            min-width: 80px;
            color: var(--text-light);
            font-weight: 600;
        }
        
        .info-value {
            flex: 1;
            color: var(--text-color);
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
                    <div id="fire-incident" class="submenu active">
                        <a href="active_incidents.php" class="submenu-item <?php echo !$incident_id ? 'active' : ''; ?>">Active Incidents</a>
                        <a href="response_history.php" class="submenu-item">Response History</a>
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
                        <a href="../vr/volunteer_list.php" class="submenu-item">Volunteer List</a>
                        <a href="../vr/roles_skills.php" class="submenu-item">Roles & Skills</a>
                        <a href="../vr/availability.php" class="submenu-item">Availability</a>
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
                        <a href="../ri/equipment_list.php" class="submenu-item">Equipment List</a>
                        <a href="../ri/stock_levels.php" class="submenu-item">Stock Levels</a>
                        <a href="../ri/maintenance_logs.php" class="submenu-item">Maintenance Logs</a>
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
                            <input type="text" placeholder="Search <?php echo $incident_id ? 'details...' : 'incidents...'; ?>" class="search-input" id="search-input">
                        </div>
                    </div>
                    
                    <div class="header-actions">
                        <!-- Notification Bell -->
                        <div class="notification-bell" id="notification-bell">
                            <i class='bx bx-bell' style="font-size: 24px; color: var(--text-color);"></i>
                            <?php if (count($notifications) > 0): ?>
                                <span class="notification-count"><?php echo count($notifications); ?></span>
                            <?php endif; ?>
                        </div>
                        
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
                                <img src="../uploads/avatars/<?php echo $avatar; ?>" alt="User" class="user-avatar">
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
            
            <!-- Notification Panel -->
            <div class="notification-panel" id="notification-panel">
                <div class="notification-header">
                    <h3 class="notification-title">Dispatch Notifications</h3>
                    <button class="notification-close" id="notification-close">&times;</button>
                </div>
                <div class="notification-list">
                    <?php if (!empty($notifications)): ?>
                        <?php foreach ($notifications as $notification): ?>
                            <div class="notification-item unread">
                                <div class="notification-message"><?php echo htmlspecialchars($notification['message']); ?></div>
                                <div class="notification-time">
                                    <?php 
                                    $time = new DateTime($notification['created_at']);
                                    echo $time->format('M j, Y g:i A');
                                    ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="notification-item">
                            <div class="notification-message">No new notifications</div>
                            <div class="notification-time">You're all caught up!</div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- Dashboard Content -->
            <div class="dashboard-content">
                <div class="dashboard-header">
                    <div>
                        <h1 class="dashboard-title">
                            <?php if ($incident_id): ?>
                                Incident Details
                            <?php else: ?>
                                Active Incidents Dashboard
                            <?php endif; ?>
                        </h1>
                        <p class="dashboard-subtitle">
                            <?php if ($incident_id): ?>
                                Complete overview of incident details and assigned resources
                            <?php else: ?>
                                Complete overview of incidents and resources for <?php echo htmlspecialchars($unit_name); ?> (<?php echo htmlspecialchars($unit_code); ?>)
                            <?php endif; ?>
                        </p>
                    </div>
                </div>
                
                <!-- Content Container -->
                <div class="content-container">
                    <?php if ($incident_id): ?>
                        <!-- Incident Details Page -->
                        <a href="active_incidents.php" class="back-button">
                            <i class='bx bx-arrow-back'></i>
                            Back to Active Incidents
                        </a>
                        
                        <div class="incident-details-container">
                            <!-- Incident Status and Severity -->
                            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
                                <div>
                                    <h2 style="font-size: 24px; color: var(--text-color); margin-bottom: 8px;">
                                        <?php echo htmlspecialchars($incident_details['title']); ?>
                                    </h2>
                                    <div style="display: flex; gap: 10px; align-items: center;">
                                        <span class="incident-status status-<?php echo $incident_details['unit_involvement_status']; ?>">
                                            <?php echo ucfirst($incident_details['unit_involvement_status']); ?>
                                        </span>
                                        <span class="severity-badge severity-<?php echo $incident_details['severity']; ?>">
                                            <?php echo ucfirst($incident_details['severity']); ?> Severity
                                        </span>
                                        <span style="font-size: 12px; color: var(--text-light);">
                                            Reported: <?php echo (new DateTime($incident_details['created_at']))->format('M j, Y g:i A'); ?>
                                        </span>
                                    </div>
                                </div>
                                
                                <?php if ($incident_details['unit_involvement_status'] === 'dispatched'): ?>
                                    <div class="action-buttons">
                                        <button class="btn btn-primary" onclick="markEnRoute()">
                                            <i class='bx bx-map'></i> Mark En Route
                                        </button>
                                        <button class="btn btn-success" onclick="markArrived()">
                                            <i class='bx bx-check'></i> Mark Arrived
                                        </button>
                                    </div>
                                <?php endif; ?>
                            </div>
                            
                            <!-- Main Details Grid -->
                            <div class="details-grid">
                                <!-- Left Column: Incident Information -->
                                <div>
                                    <div class="details-section">
                                        <h3 class="details-title">
                                            <i class='bx bx-info-circle'></i>
                                            Incident Information
                                        </h3>
                                        
                                        <div class="info-grid">
                                            <div class="info-item">
                                                <span class="info-label">Emergency Type</span>
                                                <span class="info-value"><?php echo ucfirst($incident_details['emergency_type']); ?></span>
                                            </div>
                                            
                                            <div class="info-item">
                                                <span class="info-label">Assistance Needed</span>
                                                <span class="info-value"><?php echo htmlspecialchars($incident_details['assistance_needed']); ?></span>
                                            </div>
                                            
                                            <div class="info-item">
                                                <span class="info-label">Alert Type</span>
                                                <span class="info-value"><?php echo htmlspecialchars($incident_details['alert_type']); ?></span>
                                            </div>
                                            
                                            <div class="info-item">
                                                <span class="info-label">Fire/Rescue Related</span>
                                                <span class="info-value">
                                                    <?php echo $incident_details['is_fire_rescue_related'] ? 'Yes' : 'No'; ?>
                                                    <?php if ($incident_details['rescue_category']): ?>
                                                        (<?php echo str_replace('_', ' ', ucfirst($incident_details['rescue_category'])); ?>)
                                                    <?php endif; ?>
                                                </span>
                                            </div>
                                        </div>
                                        
                                        <div style="margin-top: 15px;">
                                            <span class="info-label">Location</span>
                                            <div class="info-value" style="font-weight: 600; font-size: 16px; margin-top: 5px;">
                                                <?php echo htmlspecialchars($incident_details['location']); ?>
                                            </div>
                                        </div>
                                        
                                        <div style="margin-top: 15px;">
                                            <span class="info-label">Affected Barangays</span>
                                            <div class="info-value" style="margin-top: 5px;">
                                                <?php echo htmlspecialchars($incident_details['affected_barangays']); ?>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <!-- Caller Information -->
                                    <div class="details-section" style="margin-top: 20px;">
                                        <h3 class="details-title">
                                            <i class='bx bx-phone'></i>
                                            Caller Information
                                        </h3>
                                        
                                        <div class="info-grid">
                                            <div class="info-item">
                                                <span class="info-label">Name</span>
                                                <span class="info-value"><?php echo htmlspecialchars($incident_details['caller_name']); ?></span>
                                            </div>
                                            
                                            <div class="info-item">
                                                <span class="info-label">Phone</span>
                                                <span class="info-value">
                                                    <a href="tel:<?php echo htmlspecialchars($incident_details['caller_phone']); ?>" style="color: var(--info); text-decoration: none;">
                                                        <?php echo htmlspecialchars($incident_details['caller_phone']); ?>
                                                    </a>
                                                </span>
                                            </div>
                                            
                                            <div class="info-item">
                                                <span class="info-label">Issued By</span>
                                                <span class="info-value"><?php echo htmlspecialchars($incident_details['issued_by']); ?></span>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                
                                <!-- Right Column: Resources -->
                                <div>
                                    <!-- Assigned Unit -->
                                    <div class="details-section">
                                        <h3 class="details-title">
                                            <i class='bx bx-building'></i>
                                            Assigned Unit
                                        </h3>
                                        
                                        <div class="info-grid">
                                            <div class="info-item">
                                                <span class="info-label">Unit Name</span>
                                                <span class="info-value"><?php echo htmlspecialchars($incident_details['unit_name']); ?></span>
                                            </div>
                                            
                                            <div class="info-item">
                                                <span class="info-label">Unit Code</span>
                                                <span class="info-value"><?php echo htmlspecialchars($incident_details['unit_code']); ?></span>
                                            </div>
                                            
                                            <div class="info-item">
                                                <span class="info-label">Unit Type</span>
                                                <span class="info-value"><?php echo htmlspecialchars($incident_details['unit_type']); ?></span>
                                            </div>
                                            
                                            <div class="info-item">
                                                <span class="info-label">Dispatch Status</span>
                                                <span class="info-value">
                                                    <span class="badge badge-<?php echo $incident_details['dispatch_status'] === 'pending' ? 'warning' : ($incident_details['dispatch_status'] === 'dispatched' ? 'info' : ($incident_details['dispatch_status'] === 'arrived' ? 'success' : 'info')); ?>">
                                                        <?php echo ucfirst($incident_details['dispatch_status']); ?>
                                                    </span>
                                                </span>
                                            </div>
                                        </div>
                                        
                                        <?php if ($incident_details['dispatched_at']): ?>
                                            <div style="margin-top: 15px;">
                                                <span class="info-label">Dispatched At</span>
                                                <div class="info-value">
                                                    <?php echo (new DateTime($incident_details['dispatched_at']))->format('M j, Y g:i A'); ?>
                                                </div>
                                            </div>
                                        <?php endif; ?>
                                        
                                        <?php if (!empty($incident_details['er_notes'])): ?>
                                            <div style="margin-top: 15px;">
                                                <span class="info-label">Emergency Response Notes</span>
                                                <div class="description-box">
                                                    <?php echo htmlspecialchars($incident_details['er_notes']); ?>
                                                </div>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                    
                                    <!-- Assigned Vehicles -->
                                    <?php if (!empty($incident_vehicles)): ?>
                                        <div class="details-section" style="margin-top: 20px;">
                                            <h3 class="details-title">
                                                <i class='bx bx-car'></i>
                                                Assigned Vehicles
                                                <span class="badge badge-info"><?php echo count($incident_vehicles); ?></span>
                                            </h3>
                                            
                                            <div class="resource-list">
                                                <?php foreach ($incident_vehicles as $vehicle): ?>
                                                    <div class="resource-item">
                                                        <div class="resource-avatar">
                                                            <i class='bx bx-car'></i>
                                                        </div>
                                                        <div class="resource-info">
                                                            <h4><?php echo htmlspecialchars($vehicle['vehicle_name'] ?? 'Unknown Vehicle'); ?></h4>
                                                            <p>Type: <?php echo htmlspecialchars($vehicle['type'] ?? 'Unknown'); ?></p>
                                                            <p>Status: <?php echo htmlspecialchars($vehicle['status'] ?? 'Unknown'); ?></p>
                                                        </div>
                                                        <span class="vehicle-status status-<?php echo $incident_details['unit_involvement_status']; ?>">
                                                            <?php echo ucfirst($incident_details['unit_involvement_status']); ?>
                                                        </span>
                                                    </div>
                                                <?php endforeach; ?>
                                            </div>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                            
                            <!-- Incident Description -->
                            <?php if (!empty($incident_details['description'])): ?>
                                <div class="details-section" style="margin-top: 20px;">
                                    <h3 class="details-title">
                                        <i class='bx bx-file'></i>
                                        Incident Description
                                    </h3>
                                    <div class="description-box">
                                        <?php echo nl2br(htmlspecialchars($incident_details['description'])); ?>
                                    </div>
                                </div>
                            <?php endif; ?>
                            
                            <!-- Unit Volunteers -->
                            <?php if (!empty($unit_volunteers)): ?>
                                <div class="details-section" style="margin-top: 20px;">
                                    <h3 class="details-title">
                                        <i class='bx bx-group'></i>
                                        Unit Volunteers
                                        <span class="badge badge-info"><?php echo count($unit_volunteers); ?></span>
                                    </h3>
                                    
                                    <div class="resource-list">
                                        <?php foreach ($unit_volunteers as $volunteer): 
                                            $full_name = htmlspecialchars($volunteer['first_name'] . ' ' . $volunteer['last_name']);
                                            $initials = strtoupper(substr($volunteer['first_name'], 0, 1) . substr($volunteer['last_name'], 0, 1));
                                            $skills = [];
                                            if ($volunteer['skills_basic_firefighting']) $skills[] = 'Firefighting';
                                            if ($volunteer['skills_first_aid_cpr']) $skills[] = 'First Aid/CPR';
                                            if ($volunteer['skills_search_rescue']) $skills[] = 'Search & Rescue';
                                            if ($volunteer['skills_driving']) $skills[] = 'Driving';
                                            if ($volunteer['skills_communication']) $skills[] = 'Communication';
                                        ?>
                                            <div class="resource-item">
                                                <div class="resource-avatar">
                                                    <?php echo $initials; ?>
                                                </div>
                                                <div class="resource-info">
                                                    <h4><?php echo $full_name; ?></h4>
                                                    <p><?php echo htmlspecialchars($volunteer['contact_number']); ?> • 
                                                       <?php echo htmlspecialchars($volunteer['email']); ?></p>
                                                    <?php if (!empty($skills)): ?>
                                                        <div class="resource-skills">
                                                            <?php foreach ($skills as $skill): 
                                                                $skill_class = strtolower(str_replace(['/', ' & '], ['', '_'], $skill));
                                                            ?>
                                                                <span class="skill-badge <?php echo $skill_class; ?>">
                                                                    <?php echo $skill; ?>
                                                                </span>
                                                            <?php endforeach; ?>
                                                        </div>
                                                    <?php endif; ?>
                                                </div>
                                                <span class="volunteer-status <?php echo $volunteer['emergency_response'] ? 'status-active' : 'status-inactive'; ?>">
                                                    <?php echo $volunteer['emergency_response'] ? 'Ready' : 'Not Ready'; ?>
                                                </span>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            <?php endif; ?>
                            
                            <!-- Notes Section -->
                            <?php if (!empty($incident_details['notes'])): ?>
                                <div class="details-section" style="margin-top: 20px;">
                                    <h3 class="details-title">
                                        <i class='bx bx-edit'></i>
                                        Additional Notes
                                    </h3>
                                    <div class="description-box">
                                        <?php echo nl2br(htmlspecialchars($incident_details['notes'])); ?>
                                    </div>
                                </div>
                            <?php endif; ?>
                        </div>
                        
                    <?php else: ?>
                        <!-- Main Active Incidents Page -->
                        <!-- Enhanced Statistics Dashboard -->
                        <div class="section-container">
                            <h3 class="section-title">
                                <i class='bx bx-stats'></i>
                                Unit Status Overview
                            </h3>
                            
                            <div class="stats-dashboard">
                                <!-- Active Dispatches -->
                                <div class="stat-card-enhanced urgent">
                                    <div class="stat-icon urgent">
                                        <i class='bx bx-run'></i>
                                    </div>
                                    <div class="stat-value"><?php echo $active_dispatches_count; ?></div>
                                    <div class="stat-label">Active Dispatches</div>
                                    <div class="stat-subtext">Units currently responding</div>
                                </div>
                                
                                <!-- Suggested Incidents -->
                                <div class="stat-card-enhanced warning">
                                    <div class="stat-icon warning">
                                        <i class='bx bx-time'></i>
                                    </div>
                                    <div class="stat-value"><?php echo $suggested_incidents; ?></div>
                                    <div class="stat-label">Pending Suggestions</div>
                                    <div class="stat-subtext">Awaiting approval</div>
                                </div>
                                
                                <!-- Unit Volunteers -->
                                <div class="stat-card-enhanced info">
                                    <div class="stat-icon info">
                                        <i class='bx bx-group'></i>
                                    </div>
                                    <div class="stat-value"><?php echo $total_volunteers; ?></div>
                                    <div class="stat-label">Volunteers</div>
                                    <div class="stat-subtext">Assigned to unit</div>
                                </div>
                                
                                <!-- Available Vehicles -->
                                <div class="stat-card-enhanced success">
                                    <div class="stat-icon success">
                                        <i class='bx bx-car'></i>
                                    </div>
                                    <div class="stat-value"><?php echo $available_vehicles; ?>/<?php echo count($unit_vehicles); ?></div>
                                    <div class="stat-label">Available Vehicles</div>
                                    <div class="stat-subtext">Ready for dispatch</div>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Unit Information -->
                        <div class="unit-overview">
                            <?php if ($unit_info): ?>
                                <div class="unit-card">
                                    <h3 class="unit-card-title">
                                        <i class='bx bx-building'></i>
                                        <?php echo htmlspecialchars($unit_info['unit_name']); ?> Unit
                                    </h3>
                                    <div class="unit-details-grid">
                                        <div class="unit-detail">
                                            <span class="unit-label">Unit Code</span>
                                            <span class="unit-value"><?php echo htmlspecialchars($unit_info['unit_code']); ?></span>
                                        </div>
                                        <div class="unit-detail">
                                            <span class="unit-label">Unit Type</span>
                                            <span class="unit-value"><?php echo htmlspecialchars($unit_info['unit_type']); ?></span>
                                        </div>
                                        <div class="unit-detail">
                                            <span class="unit-label">Location</span>
                                            <span class="unit-value"><?php echo htmlspecialchars($unit_info['location']); ?></span>
                                        </div>
                                        <div class="unit-detail">
                                            <span class="unit-label">Current Status</span>
                                            <span class="unit-value">
                                                <span class="badge badge-<?php echo $unit_info['current_status'] === 'available' ? 'success' : ($unit_info['current_status'] === 'dispatched' ? 'info' : 'warning'); ?>">
                                                    <?php echo ucfirst($unit_info['current_status']); ?>
                                                </span>
                                            </span>
                                        </div>
                                    </div>
                                    
                                    <!-- Response Readiness -->
                                    <div class="readiness-indicator">
                                        <div class="unit-label">Response Readiness</div>
                                        <div class="readiness-bar">
                                            <div class="readiness-fill" style="width: <?php echo $response_readiness; ?>%"></div>
                                        </div>
                                        <div style="display: flex; justify-content: space-between; font-size: 12px;">
                                            <span style="color: var(--text-light);"><?php echo $response_readiness; ?>% Ready</span>
                                            <span style="color: var(--text-light);"><?php echo array_filter($unit_volunteers, function($vol) { return $vol['emergency_response'] == 1; }) ? count(array_filter($unit_volunteers, function($vol) { return $vol['emergency_response'] == 1; })) : 0; ?>/<?php echo $total_volunteers; ?> volunteers</span>
                                        </div>
                                    </div>
                                </div>
                            <?php endif; ?>
                        </div>
                        
                        <!-- Main Content Grid -->
                        <div class="main-grid">
                            <!-- Left Column: Incidents -->
                            <div>
                                <!-- Incidents Section -->
                                <div class="section-container">
                                    <div class="incidents-header">
                                        <h3 class="section-title" style="margin-bottom: 0;">
                                            <i class='bx bxs-alarm-exclamation'></i>
                                            Active Incidents
                                            <?php if (count($filtered_incidents) > 0): ?>
                                                <span class="badge badge-info"><?php echo count($filtered_incidents); ?> incidents</span>
                                            <?php endif; ?>
                                        </h3>
                                        
                                        <div style="display: flex; gap: 10px;">
                                            <a href="response_history.php" class="btn btn-secondary">
                                                <i class='bx bx-history'></i> View History
                                            </a>
                                        </div>
                                    </div>
                                    
                                    <!-- Filters -->
                                    <div class="filter-container">
                                        <form method="GET" action="" id="filter-form">
                                            <div class="filter-group">
                                                <label class="filter-label">Status</label>
                                                <select name="status" class="filter-select">
                                                    <option value="all" <?php echo $status_filter === 'all' ? 'selected' : ''; ?>>All Status</option>
                                                    <option value="suggested" <?php echo $status_filter === 'suggested' ? 'selected' : ''; ?>>Suggested</option>
                                                    <option value="dispatched" <?php echo $status_filter === 'dispatched' ? 'selected' : ''; ?>>Dispatched</option>
                                                    <option value="active" <?php echo $status_filter === 'active' ? 'selected' : ''; ?>>Active</option>
                                                </select>
                                            </div>
                                            
                                            <div class="filter-group">
                                                <label class="filter-label">Severity</label>
                                                <select name="severity" class="filter-select">
                                                    <option value="all" <?php echo $severity_filter === 'all' ? 'selected' : ''; ?>>All Severity</option>
                                                    <option value="critical" <?php echo $severity_filter === 'critical' ? 'selected' : ''; ?>>Critical</option>
                                                    <option value="high" <?php echo $severity_filter === 'high' ? 'selected' : ''; ?>>High</option>
                                                    <option value="medium" <?php echo $severity_filter === 'medium' ? 'selected' : ''; ?>>Medium</option>
                                                    <option value="low" <?php echo $severity_filter === 'low' ? 'selected' : ''; ?>>Low</option>
                                                </select>
                                            </div>
                                            
                                            <div class="filter-group">
                                                <label class="filter-label">Emergency Type</label>
                                                <select name="type" class="filter-select">
                                                    <option value="all" <?php echo $type_filter === 'all' ? 'selected' : ''; ?>>All Types</option>
                                                    <option value="fire" <?php echo $type_filter === 'fire' ? 'selected' : ''; ?>>Fire</option>
                                                    <option value="medical" <?php echo $type_filter === 'medical' ? 'selected' : ''; ?>>Medical</option>
                                                    <option value="rescue" <?php echo $type_filter === 'rescue' ? 'selected' : ''; ?>>Rescue</option>
                                                    <option value="other" <?php echo $type_filter === 'other' ? 'selected' : ''; ?>>Other</option>
                                                </select>
                                            </div>
                                            
                                            <div class="filter-actions">
                                                <button type="submit" class="btn btn-primary">
                                                    <i class='bx bx-filter-alt'></i> Apply Filters
                                                </button>
                                                <a href="active_incidents.php" class="btn btn-secondary">
                                                    <i class='bx bx-reset'></i> Clear Filters
                                                </a>
                                            </div>
                                        </form>
                                    </div>
                                    
                                    <!-- Incidents List -->
                                    <?php if (count($filtered_incidents) > 0): ?>
                                        <div class="incident-grid">
                                            <?php foreach ($filtered_incidents as $incident): 
                                                $status_class = 'status-' . $incident['unit_involvement_status'];
                                                $severity_class = 'severity-' . $incident['severity'];
                                                $card_class = $incident['unit_involvement_status'];
                                            ?>
                                                <div class="incident-card <?php echo $card_class; ?>">
                                                    <?php if (in_array($incident['unit_involvement_status'], ['dispatched', 'en_route'])): ?>
                                                        <div class="notification-badge">
                                                            <i class='bx bx-bell' style="font-size: 12px;"></i>
                                                        </div>
                                                    <?php endif; ?>
                                                    
                                                    <div class="incident-header">
                                                        <div class="incident-title-section">
                                                            <h4 class="incident-title"><?php echo htmlspecialchars($incident['title']); ?></h4>
                                                            <div class="incident-meta">
                                                                <span class="incident-status <?php echo $status_class; ?>">
                                                                    <?php echo ucfirst($incident['unit_involvement_status']); ?>
                                                                </span>
                                                                <span class="severity-badge <?php echo $severity_class; ?>">
                                                                    <?php echo ucfirst($incident['severity']); ?>
                                                                </span>
                                                                <span style="font-size: 11px; color: var(--text-light);">
                                                                    <?php 
                                                                    $created_at = new DateTime($incident['created_at']);
                                                                    echo $created_at->format('M j, Y g:i A');
                                                                    ?>
                                                                </span>
                                                            </div>
                                                        </div>
                                                    </div>
                                                    
                                                    <div class="incident-info">
                                                        <div class="info-row">
                                                            <span class="info-label">Type:</span>
                                                            <span class="info-value"><?php echo ucfirst($incident['emergency_type']); ?></span>
                                                        </div>
                                                        <div class="info-row">
                                                            <span class="info-label">Location:</span>
                                                            <span class="info-value"><?php echo htmlspecialchars($incident['location']); ?></span>
                                                        </div>
                                                        <div class="info-row">
                                                            <span class="info-label">Caller:</span>
                                                            <span class="info-value"><?php echo htmlspecialchars($incident['caller_name']); ?> (<?php echo htmlspecialchars($incident['caller_phone']); ?>)</span>
                                                        </div>
                                                        <div class="info-row">
                                                            <span class="info-label">Barangay:</span>
                                                            <span class="info-value"><?php echo htmlspecialchars($incident['affected_barangays']); ?></span>
                                                        </div>
                                                    </div>
                                                    
                                                    <?php if (!empty($incident['description'])): ?>
                                                        <div class="incident-description" style="padding: 12px; background: var(--card-bg); border-radius: 8px; margin: 10px 0; font-size: 13px;">
                                                            <?php echo htmlspecialchars($incident['description']); ?>
                                                        </div>
                                                    <?php endif; ?>
                                                    
                                                    <!-- Status Messages -->
                                                    <?php if ($incident['unit_involvement_status'] === 'suggested'): ?>
                                                        <div style="margin: 15px 0; padding: 12px; background: rgba(245, 158, 11, 0.1); border: 1px solid rgba(245, 158, 11, 0.2); border-radius: 6px;">
                                                            <div style="display: flex; align-items: center; gap: 8px; margin-bottom: 5px;">
                                                                <i class='bx bx-time' style="color: var(--warning);"></i>
                                                                <span style="font-size: 12px; color: var(--warning); font-weight: 600;">Pending Approval</span>
                                                            </div>
                                                            <div style="font-size: 11px; color: var(--text-light);">
                                                                Your unit has been suggested for this incident. Waiting for Emergency Response approval.
                                                            </div>
                                                        </div>
                                                    <?php elseif ($incident['unit_involvement_status'] === 'dispatched'): ?>
                                                        <div style="margin: 15px 0; padding: 12px; background: rgba(59, 130, 246, 0.1); border: 1px solid rgba(59, 130, 246, 0.2); border-radius: 6px;">
                                                            <div style="display: flex; align-items: center; gap: 8px; margin-bottom: 5px;">
                                                                <i class='bx bx-check-circle' style="color: var(--info);"></i>
                                                                <span style="font-size: 12px; color: var(--info); font-weight: 600;">Unit Dispatched</span>
                                                            </div>
                                                            <div style="font-size: 11px; color: var(--text-light);">
                                                                Your unit has been dispatched. Please report immediately.
                                                            </div>
                                                            <?php if ($incident['dispatched_at']): ?>
                                                                <div style="font-size: 11px; color: var(--text-light); margin-top: 5px;">
                                                                    Dispatched: <?php echo (new DateTime($incident['dispatched_at']))->format('g:i A'); ?>
                                                                </div>
                                                            <?php endif; ?>
                                                        </div>
                                                    <?php endif; ?>
                                                    
                                                    <div class="action-buttons">
                                                        <?php if ($incident['unit_involvement_status'] === 'dispatched'): ?>
                                                            <button class="btn btn-primary btn-sm" onclick="markEnRoute(<?php echo $incident['id']; ?>)">
                                                                <i class='bx bx-map'></i> Mark En Route
                                                            </button>
                                                            <button class="btn btn-success btn-sm" onclick="markArrived(<?php echo $incident['id']; ?>)">
                                                                <i class='bx bx-check'></i> Mark Arrived
                                                            </button>
                                                        <?php endif; ?>
                                                        <a href="active_incidents.php?incident_id=<?php echo $incident['id']; ?>" class="btn btn-secondary btn-sm">
                                                            <i class='bx bx-detail'></i> View Details
                                                        </a>
                                                    </div>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php else: ?>
                                        <div class="empty-state">
                                            <i class='bx bx-alarm-off'></i>
                                            <h3>No Incidents Found</h3>
                                            <p>No incidents match your search criteria or there are no incidents involving your unit.</p>
                                            <?php if ($status_filter !== 'all' || $severity_filter !== 'all' || $type_filter !== 'all'): ?>
                                                <div style="margin-top: 20px;">
                                                    <a href="active_incidents.php" class="btn btn-primary">
                                                        <i class='bx bx-reset'></i> Clear Filters
                                                    </a>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                            
                            <!-- Right Column: Resources -->
                            <div>
                                <!-- Volunteers Section -->
                                <div class="sidebar-section">
                                    <div class="sidebar-card">
                                        <h4 class="sidebar-title">
                                            <i class='bx bx-group'></i>
                                            Unit Volunteers
                                            <span class="badge badge-info"><?php echo $total_volunteers; ?></span>
                                        </h4>
                                        <div class="volunteers-list">
                                            <?php if (!empty($unit_volunteers)): ?>
                                                <?php foreach (array_slice($unit_volunteers, 0, 5) as $vol): 
                                                    $full_name = htmlspecialchars($vol['first_name'] . ' ' . $vol['last_name']);
                                                    $initials = strtoupper(substr($vol['first_name'], 0, 1) . substr($vol['last_name'], 0, 1));
                                                ?>
                                                    <div class="volunteer-item">
                                                        <div class="volunteer-avatar"><?php echo $initials; ?></div>
                                                        <div class="volunteer-info">
                                                            <h4><?php echo $full_name; ?></h4>
                                                            <p><?php echo htmlspecialchars($vol['skills_basic_firefighting'] ? 'Firefighting' : ($vol['skills_first_aid_cpr'] ? 'First Aid' : 'General')); ?></p>
                                                        </div>
                                                        <span class="volunteer-status <?php echo $vol['volunteer_status'] === 'Active' ? 'status-active' : 'status-inactive'; ?>">
                                                            <?php echo $vol['volunteer_status']; ?>
                                                        </span>
                                                    </div>
                                                <?php endforeach; ?>
                                                <?php if (count($unit_volunteers) > 5): ?>
                                                    <div style="text-align: center; padding: 10px; font-size: 12px; color: var(--text-light);">
                                                        + <?php echo count($unit_volunteers) - 5; ?> more volunteers
                                                    </div>
                                                <?php endif; ?>
                                            <?php else: ?>
                                                <div class="empty-state" style="padding: 20px 0;">
                                                    <i class='bx bx-user-x'></i>
                                                    <p style="font-size: 12px;">No volunteers assigned</p>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                                
                                <!-- Vehicles Section -->
                                <div class="sidebar-section">
                                    <div class="sidebar-card">
                                        <h4 class="sidebar-title">
                                            <i class='bx bx-car'></i>
                                            Unit Vehicles
                                            <span class="badge badge-info"><?php echo count($unit_vehicles); ?></span>
                                        </h4>
                                        <div class="vehicles-list">
                                            <?php if (!empty($unit_vehicles)): ?>
                                                <?php foreach ($unit_vehicles as $vehicle): 
                                                    $status_class = 'status-' . $vehicle['display_status'];
                                                ?>
                                                    <div class="vehicle-item">
                                                        <div class="vehicle-info">
                                                            <h4><?php echo htmlspecialchars($vehicle['vehicle_name']); ?></h4>
                                                            <p><?php echo htmlspecialchars($vehicle['vehicle_type']); ?></p>
                                                        </div>
                                                        <span class="vehicle-status <?php echo $status_class; ?>">
                                                            <?php echo ucfirst($vehicle['display_status']); ?>
                                                        </span>
                                                    </div>
                                                <?php endforeach; ?>
                                            <?php else: ?>
                                                <div class="empty-state" style="padding: 20px 0;">
                                                    <i class='bx bx-car'></i>
                                                    <p style="font-size: 12px;">No vehicles assigned</p>
                                                </div>
                                            <?php endif; ?>
                                    </div>
                                </div>
                                
                                <!-- Active Dispatches -->
                                <div class="sidebar-section">
                                    <div class="sidebar-card">
                                        <h4 class="sidebar-title">
                                            <i class='bx bx-run'></i>
                                            Active Dispatches
                                            <span class="badge badge-danger"><?php echo $active_dispatches_count; ?></span>
                                        </h4>
                                        <?php if (!empty($active_dispatches)): ?>
                                            <div class="vehicles-list">
                                                <?php foreach ($active_dispatches as $dispatch): ?>
                                                    <div class="vehicle-item">
                                                        <div class="vehicle-info">
                                                            <h4><?php echo htmlspecialchars($dispatch['title']); ?></h4>
                                                            <p style="font-size: 10px; color: var(--text-light);">
                                                                <?php echo htmlspecialchars($dispatch['location']); ?>
                                                            </p>
                                                        </div>
                                                        <span class="badge badge-<?php echo $dispatch['status'] === 'dispatched' ? 'info' : ($dispatch['status'] === 'en_route' ? 'warning' : 'success'); ?>">
                                                            <?php echo ucfirst($dispatch['status']); ?>
                                                        </span>
                                                    </div>
                                                <?php endforeach; ?>
                                            </div>
                                        <?php else: ?>
                                            <div class="empty-state" style="padding: 20px 0;">
                                                <i class='bx bx-check-circle'></i>
                                                <p style="font-size: 12px;">No active dispatches</p>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>
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
            
            <?php if (!$incident_id): ?>
                // Handle search (only on main incidents page)
                const searchInput = document.getElementById('search-input');
                if (searchInput) {
                    searchInput.addEventListener('keyup', function() {
                        const searchTerm = this.value.toLowerCase();
                        const incidentCards = document.querySelectorAll('.incident-card');
                        
                        incidentCards.forEach(card => {
                            const title = card.querySelector('.incident-title')?.textContent.toLowerCase() || '';
                            const location = card.querySelectorAll('.info-value')[1]?.textContent.toLowerCase() || '';
                            const description = card.querySelector('.incident-description')?.textContent.toLowerCase() || '';
                            
                            if (title.includes(searchTerm) || location.includes(searchTerm) || description.includes(searchTerm)) {
                                card.style.display = 'block';
                            } else {
                                card.style.display = 'none';
                            }
                        });
                    });
                }
                
                // Auto-refresh every 30 seconds (only on main incidents page)
                setTimeout(() => {
                    location.reload();
                }, 30000);
            <?php endif; ?>
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
            
            // Notification bell
            const notificationBell = document.getElementById('notification-bell');
            const notificationPanel = document.getElementById('notification-panel');
            const notificationClose = document.getElementById('notification-close');
            
            if (notificationBell && notificationPanel) {
                notificationBell.addEventListener('click', function(e) {
                    e.stopPropagation();
                    notificationPanel.classList.toggle('show');
                });
            }
            
            if (notificationClose && notificationPanel) {
                notificationClose.addEventListener('click', function() {
                    notificationPanel.classList.remove('show');
                });
            }
            
            // Close dropdowns when clicking outside
            document.addEventListener('click', function() {
                if (userDropdown) {
                    userDropdown.classList.remove('show');
                }
                if (notificationPanel) {
                    notificationPanel.classList.remove('show');
                }
            });
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
        
        // Show notification panel if there are notifications
        <?php if (count($notifications) > 0): ?>
        setTimeout(() => {
            const notificationPanel = document.getElementById('notification-panel');
            if (notificationPanel) {
                notificationPanel.classList.add('show');
            }
        }, 1000);
        <?php endif; ?>
        
        // Incident action functions
        function markEnRoute(incidentId = null) {
            if (confirm('Mark this unit as En Route to the incident location?')) {
                // In a real application, you would make an AJAX call here
                alert('Unit marked as En Route. This would update the database in a real application.');
                if (incidentId) {
                    // Refresh the page to show updated status
                    setTimeout(() => {
                        location.reload();
                    }, 1000);
                }
            }
        }
        
        function markArrived(incidentId = null) {
            if (confirm('Mark this unit as Arrived at the incident location?')) {
                // In a real application, you would make an AJAX call here
                alert('Unit marked as Arrived. This would update the database in a real application.');
                if (incidentId) {
                    // Refresh the page to show updated status
                    setTimeout(() => {
                        location.reload();
                    }, 1000);
                }
            }
        }
    </script>
</body>
</html>