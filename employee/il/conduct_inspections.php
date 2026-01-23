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

// Check if user has permission (EMPLOYEE or ADMIN only)
if ($role !== 'EMPLOYEE' && $role !== 'ADMIN') {
    header("Location: ../employee_dashboard.php");
    exit();
}

// Function to get establishments for inspection
function getEstablishmentsForInspection($pdo, $search = null, $barangay = null, $type = null, $status = null) {
    $sql = "SELECT * FROM inspection_establishments WHERE status = 'active'";
    $params = [];
    
    if ($search) {
        $sql .= " AND (establishment_name LIKE ? OR owner_name LIKE ? OR address LIKE ?)";
        $search_param = "%$search%";
        $params[] = $search_param;
        $params[] = $search_param;
        $params[] = $search_param;
    }
    
    if ($barangay && $barangay !== 'all') {
        $sql .= " AND barangay = ?";
        $params[] = $barangay;
    }
    
    if ($type && $type !== 'all') {
        $sql .= " AND establishment_type = ?";
        $params[] = $type;
    }
    
    if ($status && $status !== 'all') {
        if ($status === 'overdue') {
            $sql .= " AND (next_scheduled_inspection IS NOT NULL AND next_scheduled_inspection < CURDATE())";
        } elseif ($status === 'upcoming') {
            $sql .= " AND (next_scheduled_inspection IS NOT NULL AND next_scheduled_inspection BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY))";
        } elseif ($status === 'no_schedule') {
            $sql .= " AND next_scheduled_inspection IS NULL";
        }
    }
    
    $sql .= " ORDER BY next_scheduled_inspection ASC, establishment_name ASC";
    
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Error fetching establishments: " . $e->getMessage());
        return [];
    }
}

// Function to get establishment details
function getEstablishmentDetails($pdo, $establishment_id) {
    $sql = "SELECT * FROM inspection_establishments WHERE id = ?";
    
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$establishment_id]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Error fetching establishment details: " . $e->getMessage());
        return null;
    }
}

// Function to get inspection checklist items
function getChecklistItems($pdo, $category = null) {
    $sql = "SELECT * FROM inspection_checklist_items WHERE active = 1";
    $params = [];
    
    if ($category && $category !== 'all') {
        $sql .= " AND category = ?";
        $params[] = $category;
    }
    
    $sql .= " ORDER BY category, item_code";
    
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Error fetching checklist items: " . $e->getMessage());
        return [];
    }
}

// Function to get barangays for filter
function getBarangays($pdo) {
    $sql = "SELECT DISTINCT barangay FROM inspection_establishments WHERE barangay IS NOT NULL AND barangay != '' ORDER BY barangay";
    
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    } catch (PDOException $e) {
        error_log("Error fetching barangays: " . $e->getMessage());
        return [];
    }
}

// Function to get inspection types
function getInspectionTypes() {
    return ['routine', 'follow_up', 'complaint', 'random', 'pre-license', 'renewal'];
}

// Function to create new inspection
function createInspection($pdo, $establishment_id, $employee_id, $inspection_data) {
    try {
        $pdo->beginTransaction();
        
        // Generate report number
        $report_number = 'INSP-' . date('Ymd') . '-' . str_pad(rand(1000, 9999), 4, '0', STR_PAD_LEFT);
        
        // Calculate overall score
        $total_score = 0;
        $total_items = 0;
        
        if (isset($inspection_data['checklist_responses']) && is_array($inspection_data['checklist_responses'])) {
            foreach ($inspection_data['checklist_responses'] as $response) {
                if ($response['status'] !== 'not_applicable') {
                    $total_score += $response['score'];
                    $total_items++;
                }
            }
        }
        
        $overall_score = $total_items > 0 ? round($total_score / $total_items) : 0;
        
        // Determine risk assessment based on score
        if ($overall_score >= 85) {
            $risk_assessment = 'low';
            $fire_hazard = 'low';
        } elseif ($overall_score >= 70) {
            $risk_assessment = 'medium';
            $fire_hazard = 'medium';
        } else {
            $risk_assessment = 'high';
            $fire_hazard = 'high';
        }
        
        // Create inspection report
        $sql = "INSERT INTO inspection_reports 
                (establishment_id, inspection_date, inspected_by, inspection_type, report_number, 
                 status, overall_compliance_score, risk_assessment, fire_hazard_level, recommendations, 
                 corrective_actions_required, compliance_deadline, created_at) 
                VALUES (?, ?, ?, ?, ?, 'draft', ?, ?, ?, ?, ?, ?, NOW())";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            $establishment_id,
            $inspection_data['inspection_date'],
            $employee_id,
            $inspection_data['inspection_type'],
            $report_number,
            $overall_score,
            $risk_assessment,
            $fire_hazard,
            $inspection_data['recommendations'] ?? null,
            $inspection_data['corrective_actions'] ?? null,
            $inspection_data['compliance_deadline'] ?? null
        ]);
        
        $inspection_id = $pdo->lastInsertId();
        
        // Save checklist responses
        if (isset($inspection_data['checklist_responses']) && is_array($inspection_data['checklist_responses'])) {
            foreach ($inspection_data['checklist_responses'] as $item_id => $response) {
                $response_sql = "INSERT INTO inspection_checklist_responses 
                                (inspection_id, checklist_item_id, compliance_status, score, notes) 
                                VALUES (?, ?, ?, ?, ?)";
                $response_stmt = $pdo->prepare($response_sql);
                $response_stmt->execute([
                    $inspection_id,
                    $item_id,
                    $response['status'],
                    $response['score'],
                    $response['notes'] ?? null
                ]);
            }
        }
        
        // Save violations if any
        if (isset($inspection_data['violations']) && is_array($inspection_data['violations'])) {
            foreach ($inspection_data['violations'] as $violation) {
                $violation_sql = "INSERT INTO inspection_violations 
                                 (inspection_id, violation_code, violation_description, severity, 
                                  section_violated, fine_amount, compliance_deadline, status) 
                                 VALUES (?, ?, ?, ?, ?, ?, ?, 'pending')";
                $violation_stmt = $pdo->prepare($violation_sql);
                $violation_stmt->execute([
                    $inspection_id,
                    $violation['code'],
                    $violation['description'],
                    $violation['severity'],
                    $violation['section'] ?? null,
                    $violation['fine'] ?? null,
                    $violation['deadline'] ?? null
                ]);
            }
        }
        
        // Update establishment's last inspection date and schedule next
        $update_sql = "UPDATE inspection_establishments 
                      SET last_inspection_date = ?,
                          next_scheduled_inspection = DATE_ADD(?, INTERVAL 
                          CASE inspection_frequency 
                              WHEN 'monthly' THEN 1 
                              WHEN 'quarterly' THEN 3 
                              WHEN 'semi-annual' THEN 6 
                              WHEN 'annual' THEN 12 
                              WHEN 'biannual' THEN 24 
                              ELSE 12 
                          END MONTH),
                          compliance_rating = ?,
                          overall_risk_level = ?
                      WHERE id = ?";
        
        $update_stmt = $pdo->prepare($update_sql);
        $update_stmt->execute([
            $inspection_data['inspection_date'],
            $inspection_data['inspection_date'],
            $overall_score,
            $risk_assessment,
            $establishment_id
        ]);
        
        $pdo->commit();
        return ['success' => true, 'inspection_id' => $inspection_id, 'report_number' => $report_number];
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log("Error creating inspection: " . $e->getMessage());
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

// Handle actions
$success_message = '';
$error_message = '';

// Handle new inspection submission via modal
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_inspection'])) {
    $establishment_id = $_POST['establishment_id'];
    $inspection_date = $_POST['inspection_date'];
    $inspection_type = $_POST['inspection_type'];
    $recommendations = $_POST['recommendations'] ?? '';
    $corrective_actions = $_POST['corrective_actions'] ?? '';
    $compliance_deadline = $_POST['compliance_deadline'] ?? null;
    
    // Prepare inspection data
    $inspection_data = [
        'inspection_date' => $inspection_date,
        'inspection_type' => $inspection_type,
        'recommendations' => $recommendations,
        'corrective_actions' => $corrective_actions,
        'compliance_deadline' => $compliance_deadline
    ];
    
    // Process checklist responses
    if (isset($_POST['checklist_response'])) {
        $checklist_responses = [];
        foreach ($_POST['checklist_response'] as $item_id => $response) {
            $checklist_responses[$item_id] = [
                'status' => $response['status'],
                'score' => isset($response['score']) ? (int)$response['score'] : 0,
                'notes' => $response['notes'] ?? null
            ];
        }
        $inspection_data['checklist_responses'] = $checklist_responses;
    }
    
    // Process violations
    if (isset($_POST['violations']) && is_array($_POST['violations'])) {
        $violations = [];
        foreach ($_POST['violations'] as $violation) {
            if (!empty($violation['code']) && !empty($violation['description'])) {
                $violations[] = [
                    'code' => $violation['code'],
                    'description' => $violation['description'],
                    'severity' => $violation['severity'] ?? 'minor',
                    'section' => $violation['section'] ?? null,
                    'fine' => isset($violation['fine']) ? (float)$violation['fine'] : null,
                    'deadline' => $violation['deadline'] ?? null
                ];
            }
        }
        $inspection_data['violations'] = $violations;
    }
    
    $result = createInspection($pdo, $establishment_id, $user_id, $inspection_data);
    
    if ($result['success']) {
        $success_message = "Inspection created successfully! Report Number: " . $result['report_number'];
    } else {
        $error_message = "Failed to create inspection: " . $result['error'];
    }
}

// Handle AJAX request for establishment details
if (isset($_GET['action']) && $_GET['action'] === 'get_establishment_details') {
    $establishment_id = $_GET['establishment_id'] ?? null;
    if ($establishment_id) {
        $details = getEstablishmentDetails($pdo, $establishment_id);
        if ($details) {
            header('Content-Type: application/json');
            echo json_encode($details);
            exit();
        }
    }
    echo json_encode(null);
    exit();
}

// Get parameters for filtering
$search_term = $_GET['search'] ?? null;
$barangay_filter = $_GET['barangay'] ?? null;
$type_filter = $_GET['type'] ?? null;
$status_filter = $_GET['status'] ?? null;

// Get data
$establishments = getEstablishmentsForInspection($pdo, $search_term, $barangay_filter, $type_filter, $status_filter);
$barangays = getBarangays($pdo);
$inspection_types = getInspectionTypes();
$establishment_types = ['Commercial', 'Residential', 'Industrial', 'Educational', 'Healthcare', 'Government', 'Other'];

// Get checklist items
$checklist_items = getChecklistItems($pdo);
$checklist_categories = [];
foreach ($checklist_items as $item) {
    if (!in_array($item['category'], $checklist_categories)) {
        $checklist_categories[] = $item['category'];
    }
}

// Get statistics
$total_establishments = count($establishments);
$overdue_inspections = 0;
$upcoming_inspections = 0;
$total_commercial = 0;
$total_high_risk = 0;

foreach ($establishments as $est) {
    if ($est['next_scheduled_inspection'] && strtotime($est['next_scheduled_inspection']) < time()) {
        $overdue_inspections++;
    }
    if ($est['next_scheduled_inspection'] && strtotime($est['next_scheduled_inspection']) <= strtotime('+7 days')) {
        $upcoming_inspections++;
    }
    if ($est['establishment_type'] === 'Commercial') {
        $total_commercial++;
    }
    if ($est['overall_risk_level'] === 'high' || $est['overall_risk_level'] === 'critical') {
        $total_high_risk++;
    }
}

$stmt = null;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Conduct Inspections - Fire & Rescue Management</title>
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

        .stats-container {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .stat-card {
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: 16px;
            padding: 24px;
            text-align: center;
            transition: all 0.3s ease;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
        }

        .stat-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 8px 16px rgba(0, 0, 0, 0.1);
        }

        .stat-icon {
            width: 60px;
            height: 60px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 16px;
            font-size: 24px;
        }

        .stat-icon.total {
            background: rgba(139, 92, 246, 0.1);
            color: var(--purple);
        }

        .stat-icon.overdue {
            background: rgba(220, 38, 38, 0.1);
            color: var(--danger);
        }

        .stat-icon.upcoming {
            background: rgba(245, 158, 11, 0.1);
            color: var(--warning);
        }

        .stat-icon.commercial {
            background: rgba(59, 130, 246, 0.1);
            color: var(--info);
        }

        .stat-icon.high-risk {
            background: rgba(16, 185, 129, 0.1);
            color: var(--success);
        }

        .stat-value {
            font-size: 32px;
            font-weight: 800;
            margin-bottom: 8px;
        }

        .stat-label {
            color: var(--text-light);
            font-size: 14px;
        }

        .filters-container {
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: 16px;
            padding: 24px;
            margin-bottom: 30px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
        }

        .filters-title {
            font-size: 18px;
            font-weight: 600;
            margin-bottom: 20px;
            color: var(--text-color);
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .filters-title i {
            color: var(--primary-color);
        }

        .filters-grid {
            display: flex;
            gap: 15px;
            flex-wrap: wrap;
            align-items: flex-end;
        }

        .filter-group {
            flex: 1;
            min-width: 200px;
        }

        .filter-label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: var(--text-color);
            font-size: 14px;
        }

        .filter-input, .filter-select {
            width: 100%;
            padding: 12px 16px;
            border-radius: 10px;
            border: 1px solid var(--border-color);
            background: var(--card-bg);
            color: var(--text-color);
            font-size: 14px;
            transition: all 0.3s ease;
        }

        .filter-input:focus, .filter-select:focus {
            outline: none;
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(220, 38, 38, 0.1);
        }

        .btn {
            padding: 12px 24px;
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

        .btn-success {
            background: linear-gradient(135deg, var(--success), #0da271);
            color: white;
        }

        .btn-success:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(16, 185, 129, 0.3);
        }

        .btn-warning {
            background: linear-gradient(135deg, var(--warning), #d97706);
            color: white;
        }

        .btn-warning:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(245, 158, 11, 0.3);
        }

        .btn-info {
            background: linear-gradient(135deg, var(--info), #2563eb);
            color: white;
        }

        .btn-info:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(59, 130, 246, 0.3);
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
            padding: 8px 16px;
            font-size: 13px;
        }

        .btn-danger {
            background: linear-gradient(135deg, var(--danger), #b91c1c);
            color: white;
        }

        .btn-danger:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(220, 38, 38, 0.3);
        }

        .table-container {
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: 16px;
            overflow: hidden;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
            margin-bottom: 30px;
        }

        .table-header {
            padding: 20px 24px;
            border-bottom: 1px solid var(--border-color);
            display: flex;
            justify-content: space-between;
            align-items: center;
            background: rgba(220, 38, 38, 0.02);
        }

        .table-title {
            font-size: 18px;
            font-weight: 600;
            color: var(--text-color);
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .table {
            width: 100%;
            border-collapse: collapse;
        }

        .table th {
            background: rgba(220, 38, 38, 0.05);
            padding: 16px 24px;
            text-align: left;
            font-weight: 600;
            color: var(--text-color);
            border-bottom: 1px solid var(--border-color);
            font-size: 13px;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }

        .table td {
            padding: 16px 24px;
            border-bottom: 1px solid var(--border-color);
            vertical-align: middle;
        }

        .table tbody tr {
            transition: all 0.3s ease;
        }

        .table tbody tr:hover {
            background: rgba(220, 38, 38, 0.02);
        }

        .table tbody tr:last-child td {
            border-bottom: none;
        }

        .establishment-info {
            display: flex;
            flex-direction: column;
            gap: 8px;
        }

        .establishment-name {
            font-weight: 700;
            color: var(--text-color);
            font-size: 16px;
        }

        .establishment-address {
            font-size: 13px;
            color: var(--text-light);
            line-height: 1.5;
        }

        .establishment-details {
            display: flex;
            flex-wrap: wrap;
            gap: 15px;
            margin-top: 8px;
        }

        .detail-item {
            display: flex;
            align-items: center;
            gap: 6px;
            font-size: 13px;
            color: var(--text-light);
        }

        .detail-item i {
            color: var(--primary-color);
            font-size: 14px;
        }

        .inspection-date {
            display: flex;
            flex-direction: column;
            gap: 4px;
        }

        .date-label {
            font-size: 11px;
            color: var(--text-light);
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .date-value {
            font-weight: 600;
            color: var(--text-color);
            font-size: 14px;
        }

        .risk-level {
            display: inline-block;
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            text-align: center;
            min-width: 80px;
        }

        .risk-low {
            background: rgba(16, 185, 129, 0.1);
            color: var(--success);
            border: 1px solid rgba(16, 185, 129, 0.2);
        }

        .risk-medium {
            background: rgba(245, 158, 11, 0.1);
            color: var(--warning);
            border: 1px solid rgba(245, 158, 11, 0.2);
        }

        .risk-high {
            background: rgba(220, 38, 38, 0.1);
            color: var(--danger);
            border: 1px solid rgba(220, 38, 38, 0.2);
        }

        .risk-critical {
            background: rgba(139, 92, 246, 0.1);
            color: var(--purple);
            border: 1px solid rgba(139, 92, 246, 0.2);
        }

        .compliance-badge {
            display: inline-block;
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            text-align: center;
            min-width: 60px;
            background: var(--gray-100);
            color: var(--text-color);
        }

        .dark-mode .compliance-badge {
            background: var(--gray-800);
        }

        .compliance-high {
            background: rgba(16, 185, 129, 0.1);
            color: var(--success);
        }

        .compliance-medium {
            background: rgba(245, 158, 11, 0.1);
            color: var(--warning);
        }

        .compliance-low {
            background: rgba(220, 38, 38, 0.1);
            color: var(--danger);
        }

        .action-buttons {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
        }

        .empty-state {
            text-align: center;
            padding: 60px 20px;
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

        /* Modal Styles */
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.5);
            z-index: 1000;
            opacity: 0;
            transition: opacity 0.3s ease;
        }

        .modal.show {
            display: flex;
            align-items: center;
            justify-content: center;
            opacity: 1;
        }

        .modal-content {
            background: var(--card-bg);
            border-radius: 20px;
            width: 90%;
            max-width: 1000px;
            max-height: 90vh;
            overflow-y: auto;
            transform: translateY(-20px);
            transition: transform 0.3s ease;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
            border: 1px solid var(--border-color);
        }

        .modal.show .modal-content {
            transform: translateY(0);
        }

        .modal-header {
            padding: 24px 32px;
            border-bottom: 1px solid var(--border-color);
            display: flex;
            justify-content: space-between;
            align-items: center;
            background: rgba(220, 38, 38, 0.02);
            border-radius: 20px 20px 0 0;
        }

        .modal-title {
            font-size: 24px;
            font-weight: 700;
            color: var(--text-color);
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .modal-title i {
            color: var(--primary-color);
        }

        .close-modal {
            background: none;
            border: none;
            font-size: 28px;
            color: var(--text-light);
            cursor: pointer;
            width: 40px;
            height: 40px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.3s ease;
        }

        .close-modal:hover {
            background: var(--gray-100);
            color: var(--danger);
        }

        .dark-mode .close-modal:hover {
            background: var(--gray-800);
        }

        .modal-body {
            padding: 32px;
        }

        .modal-footer {
            padding: 24px 32px;
            border-top: 1px solid var(--border-color);
            display: flex;
            justify-content: flex-end;
            gap: 15px;
            background: rgba(220, 38, 38, 0.02);
            border-radius: 0 0 20px 20px;
        }

        /* Establishment Details Card */
        .establishment-card {
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: 16px;
            padding: 24px;
            margin-bottom: 30px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
        }

        .establishment-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 20px;
        }

        .establishment-title {
            font-size: 24px;
            font-weight: 700;
            color: var(--text-color);
        }

        .establishment-type {
            display: inline-block;
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            background: var(--gray-100);
            color: var(--text-color);
            margin-left: 10px;
        }

        .dark-mode .establishment-type {
            background: var(--gray-800);
        }

        .establishment-details-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-bottom: 20px;
        }

        .detail-box {
            background: var(--gray-100);
            border-radius: 10px;
            padding: 15px;
        }

        .dark-mode .detail-box {
            background: var(--gray-800);
        }

        .detail-label {
            font-size: 12px;
            color: var(--text-light);
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 5px;
        }

        .detail-value {
            font-size: 14px;
            font-weight: 600;
            color: var(--text-color);
        }

        /* Inspection Form Styles */
        .inspection-form-container {
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: 16px;
            padding: 30px;
            margin-bottom: 30px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
        }

        .form-section {
            margin-bottom: 30px;
            padding-bottom: 30px;
            border-bottom: 1px solid var(--border-color);
        }

        .form-section:last-child {
            border-bottom: none;
            margin-bottom: 0;
            padding-bottom: 0;
        }

        .section-title {
            font-size: 20px;
            font-weight: 700;
            margin-bottom: 20px;
            color: var(--text-color);
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .section-title i {
            color: var(--primary-color);
        }

        .form-row {
            display: flex;
            gap: 20px;
            margin-bottom: 20px;
        }

        .form-group {
            flex: 1;
        }

        .form-label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: var(--text-color);
            font-size: 14px;
        }

        .form-label.required:after {
            content: " *";
            color: var(--danger);
        }

        .form-input, .form-textarea, .form-select {
            width: 100%;
            padding: 12px 16px;
            border-radius: 10px;
            border: 1px solid var(--border-color);
            background: var(--card-bg);
            color: var(--text-color);
            font-size: 14px;
            transition: all 0.3s ease;
        }

        .form-input:focus, .form-textarea:focus, .form-select:focus {
            outline: none;
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(220, 38, 38, 0.1);
        }

        .form-textarea {
            resize: vertical;
            min-height: 100px;
        }

        .checklist-container {
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: 10px;
            padding: 20px;
            max-height: 400px;
            overflow-y: auto;
        }

        .checklist-category {
            margin-bottom: 25px;
            padding-bottom: 20px;
            border-bottom: 1px solid var(--border-color);
        }

        .checklist-category:last-child {
            border-bottom: none;
            margin-bottom: 0;
            padding-bottom: 0;
        }

        .category-title {
            font-size: 16px;
            font-weight: 700;
            margin-bottom: 15px;
            color: var(--text-color);
            padding-left: 10px;
            border-left: 4px solid var(--primary-color);
        }

        .checklist-item {
            display: flex;
            align-items: flex-start;
            gap: 15px;
            margin-bottom: 15px;
            padding: 15px;
            border-radius: 8px;
            background: var(--gray-100);
            transition: all 0.3s ease;
        }

        .dark-mode .checklist-item {
            background: var(--gray-800);
        }

        .checklist-item:hover {
            background: var(--gray-200);
        }

        .dark-mode .checklist-item:hover {
            background: var(--gray-700);
        }

        .checklist-item-content {
            flex: 1;
        }

        .checklist-item-code {
            font-weight: 600;
            color: var(--primary-color);
            margin-bottom: 5px;
        }

        .checklist-item-desc {
            font-size: 14px;
            color: var(--text-color);
            margin-bottom: 5px;
        }

        .checklist-item-standard {
            font-size: 12px;
            color: var(--text-light);
            font-style: italic;
        }

        .checklist-response {
            display: flex;
            gap: 10px;
            align-items: center;
            min-width: 300px;
        }

        .response-select {
            min-width: 150px;
        }

        .score-input {
            width: 80px;
            text-align: center;
        }

        .notes-input {
            flex: 1;
            min-width: 200px;
        }

        .violations-container {
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: 10px;
            padding: 20px;
        }

        .violation-item {
            display: flex;
            gap: 15px;
            margin-bottom: 15px;
            padding: 15px;
            border-radius: 8px;
            background: rgba(220, 38, 38, 0.05);
            border: 1px solid rgba(220, 38, 38, 0.1);
        }

        .violation-fields {
            flex: 1;
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
        }

        .add-violation-btn {
            width: 100%;
            margin-top: 10px;
        }

        .remove-btn {
            background: none;
            border: none;
            color: var(--danger);
            cursor: pointer;
            font-size: 20px;
            display: flex;
            align-items: center;
            justify-content: center;
            width: 40px;
            height: 40px;
            border-radius: 50%;
            transition: all 0.3s ease;
        }

        .remove-btn:hover {
            background: rgba(220, 38, 38, 0.1);
        }

        /* Notification */
        .notification {
            position: fixed;
            top: 20px;
            right: 20px;
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
            z-index: 1000;
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

        /* Details Modal */
        .details-modal .modal-content {
            max-width: 600px;
        }

        .details-section {
            margin-bottom: 25px;
            padding-bottom: 25px;
            border-bottom: 1px solid var(--border-color);
        }

        .details-section:last-child {
            border-bottom: none;
            margin-bottom: 0;
            padding-bottom: 0;
        }

        .details-section-title {
            font-size: 18px;
            font-weight: 600;
            margin-bottom: 15px;
            color: var(--text-color);
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .details-section-title i {
            color: var(--primary-color);
        }

        .details-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
        }

        .detail-item-large {
            display: flex;
            flex-direction: column;
            gap: 5px;
        }

        .detail-item-label {
            font-size: 12px;
            color: var(--text-light);
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .detail-item-value {
            font-size: 14px;
            font-weight: 600;
            color: var(--text-color);
            word-break: break-word;
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
            
            .stats-container {
                grid-template-columns: repeat(2, 1fr);
            }
            
            .filters-grid {
                flex-direction: column;
            }
            
            .filter-group {
                min-width: 100%;
            }
            
            .table {
                display: block;
                overflow-x: auto;
            }
            
            .action-buttons {
                flex-direction: column;
            }
            
            .form-row {
                flex-direction: column;
                gap: 15px;
            }
            
            .checklist-response {
                flex-direction: column;
                align-items: stretch;
                min-width: 100%;
            }
            
            .violation-fields {
                grid-template-columns: 1fr;
            }
            
            .modal-content {
                width: 95%;
                max-height: 95vh;
            }
            
            .modal-body {
                padding: 20px;
            }
            
            .details-grid {
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
            
            .stats-container {
                grid-template-columns: 1fr;
            }
            
            .table-header {
                flex-direction: column;
                gap: 15px;
                align-items: stretch;
            }
            
            .establishment-header {
                flex-direction: column;
                gap: 15px;
                align-items: flex-start;
            }
            
            .establishment-details-grid {
                grid-template-columns: 1fr;
            }
            
            .modal-header, .modal-footer {
                padding: 20px;
            }
        }

        @media (max-width: 576px) {
            .checklist-item {
                flex-direction: column;
                gap: 10px;
            }
            
            .violation-item {
                flex-direction: column;
            }
            
            .btn {
                justify-content: center;
            }
        }
    </style>
</head>
<body>
    <!-- Notification -->
    <div class="notification <?php echo $success_message ? 'notification-success show' : ($error_message ? 'notification-error show' : ''); ?>" id="notification">
        <i class='notification-icon bx <?php echo $success_message ? 'bx-check-circle' : ($error_message ? 'bx-error' : ''); ?>'></i>
        <div class="notification-content">
            <div class="notification-title"><?php echo $success_message ? 'Success' : ($error_message ? 'Error' : ''); ?></div>
            <div class="notification-message"><?php echo $success_message ?: $error_message; ?></div>
        </div>
        <button class="notification-close" id="notification-close">&times;</button>
    </div>
    
    <!-- Details Modal -->
    <div class="modal details-modal" id="detailsModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title">
                    <i class='bx bx-info-circle'></i>
                    Establishment Details
                </h3>
                <button class="close-modal" id="closeDetailsModal">&times;</button>
            </div>
            <div class="modal-body" id="detailsModalBody">
                <!-- Details will be loaded here via AJAX -->
            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary" id="closeDetailsModalBtn">Close</button>
            </div>
        </div>
    </div>
    
    <!-- Inspection Modal -->
    <div class="modal" id="inspectionModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title">
                    <i class='bx bx-check-shield'></i>
                    Conduct Inspection
                </h3>
                <button class="close-modal" id="closeInspectionModal">&times;</button>
            </div>
            <form method="POST" id="inspectionForm" enctype="multipart/form-data">
                <div class="modal-body" id="inspectionModalBody">
                    <!-- Inspection form will be loaded here via JavaScript -->
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" id="cancelInspectionBtn">Cancel</button>
                    <button type="submit" name="submit_inspection" class="btn btn-success">
                        <i class='bx bx-save'></i>
                        Submit Inspection Report
                    </button>
                </div>
            </form>
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
                        <a href="../fire/receive_data.php" class="submenu-item">Receive Data</a>
                        <a href="../fire/update_status.php" class="submenu-item">View Status</a>
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
                    <div id="dispatch" class="submenu">
                        <a href="../dc/select_unit.php" class="submenu-item">Select Unit</a>
                        <a href="../dc/send_dispatch.php" class="submenu-item">Send Dispatch Info</a>
                        <a href="../dc/track_status.php" class="submenu-item">Track Status</a>
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
                        <a href="../vra/review_data.php" class="submenu-item">Review/Approve Data Management</a>
                        <a href="../vra/approve_applications.php" class="submenu-item">Assign Volunteers</a>
                        <a href="../vra/view_availability.php" class="submenu-item">View Availability</a>
                        <a href="../vra/remove_volunteers.php" class="submenu-item">Remove Volunteers</a>
                        <a href="../vra/toggle_volunteer_registration.php" class="submenu-item">Open/Close Registration</a>
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
                        <a href="../inventory/log_usage.php" class="submenu-item">Log Usage</a>
                        <a href="../inventory/report_damages.php" class="submenu-item">Report Damages</a>
                        <a href="../inventory/request_supplies.php" class="submenu-item">Request Supplies</a>
                        <a href="../inventory/tag_resources.php" class="submenu-item">Tag Resources</a>
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
                        <a href="../schedule/create_shifts.php" class="submenu-item">Create Shifts</a>
                        <a href="../schedule/view_shifts.php" class="submenu-item">View Shifts</a>
                        <a href="../schedule/confirm_availability.php" class="submenu-item">Confirm Availability</a>
                        <a href="../schedule/request_change.php" class="submenu-item">Request Change</a>
                        <a href="../schedule/mark_attendance.php" class="submenu-item">Mark Attendance</a>
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
                        <a href="../training/view_available_training.php" class="submenu-item">View Available Training</a>
                        <a href="../training/submit_training.php" class="submenu-item">Submit Training</a>
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
                    <div id="inspection" class="submenu active">
                        <a href="conduct_inspections.php" class="submenu-item active">Conduct Inspections</a>
                        <a href="submit_findings.php" class="submenu-item">Submit Findings</a>
                      
                        <a href="tag_violations.php" class="submenu-item">Tag Violations</a>
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
                        <a href="../postincident/upload_reports.php" class="submenu-item">Upload Reports</a>
                        <a href="../postincident/add_notes.php" class="submenu-item">Add Notes</a>
                        <a href="../postincident/attach_equipment.php" class="submenu-item">Attach Equipment</a>
                        <a href="../postincident/mark_completed.php" class="submenu-item">Mark Completed</a>
                    </div>
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
            <!-- Header -->
            <div class="header">
                <div class="header-content">
                    <div class="search-container">
                        <div class="search-box">
                            <svg class="search-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path>
                            </svg>
                            <input type="text" placeholder="Search establishments..." class="search-input" id="search-input">
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
                        <h1 class="dashboard-title">Conduct Inspections</h1>
                        <p class="dashboard-subtitle">Schedule and conduct fire safety inspections for establishments in Commonwealth</p>
                    </div>
                </div>
                
                <!-- Content Container -->
                <div class="content-container">
                    <!-- Statistics Cards -->
                    <div class="stats-container">
                        <div class="stat-card">
                            <div class="stat-icon total">
                                <i class='bx bx-building'></i>
                            </div>
                            <div class="stat-value"><?php echo $total_establishments; ?></div>
                            <div class="stat-label">Total Establishments</div>
                        </div>
                        
                        <div class="stat-card">
                            <div class="stat-icon overdue">
                                <i class='bx bx-time-five'></i>
                            </div>
                            <div class="stat-value"><?php echo $overdue_inspections; ?></div>
                            <div class="stat-label">Overdue Inspections</div>
                        </div>
                        
                        <div class="stat-card">
                            <div class="stat-icon upcoming">
                                <i class='bx bx-calendar-event'></i>
                            </div>
                            <div class="stat-value"><?php echo $upcoming_inspections; ?></div>
                            <div class="stat-label">Upcoming This Week</div>
                        </div>
                        
                        <div class="stat-card">
                            <div class="stat-icon high-risk">
                                <i class='bx bx-shield-x'></i>
                            </div>
                            <div class="stat-value"><?php echo $total_high_risk; ?></div>
                            <div class="stat-label">High-Risk Establishments</div>
                        </div>
                    </div>
                    
                    <!-- Filters -->
                    <div class="filters-container">
                        <h3 class="filters-title">
                            <i class='bx bx-filter-alt'></i>
                            Filter Establishments
                        </h3>
                        
                        <form method="GET" id="filters-form">
                            <div class="filters-grid">
                                <div class="filter-group">
                                    <label class="filter-label" for="search">Search</label>
                                    <input type="text" class="filter-input" id="search" name="search" 
                                           value="<?php echo htmlspecialchars($search_term ?? ''); ?>" 
                                           placeholder="Search by name, owner, address...">
                                </div>
                                
                                <div class="filter-group">
                                    <label class="filter-label" for="barangay">Barangay</label>
                                    <select class="filter-select" id="barangay" name="barangay">
                                        <option value="all">All Barangays</option>
                                        <?php foreach ($barangays as $barangay): ?>
                                            <option value="<?php echo htmlspecialchars($barangay); ?>" 
                                                <?php echo $barangay_filter === $barangay ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($barangay); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                
                                <div class="filter-group">
                                    <label class="filter-label" for="type">Establishment Type</label>
                                    <select class="filter-select" id="type" name="type">
                                        <option value="all">All Types</option>
                                        <?php foreach ($establishment_types as $type): ?>
                                            <option value="<?php echo htmlspecialchars($type); ?>" 
                                                <?php echo $type_filter === $type ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($type); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                
                                <div class="filter-group">
                                    <label class="filter-label" for="status">Inspection Status</label>
                                    <select class="filter-select" id="status" name="status">
                                        <option value="all">All Status</option>
                                        <option value="overdue" <?php echo $status_filter === 'overdue' ? 'selected' : ''; ?>>Overdue</option>
                                        <option value="upcoming" <?php echo $status_filter === 'upcoming' ? 'selected' : ''; ?>>Upcoming This Week</option>
                                        <option value="no_schedule" <?php echo $status_filter === 'no_schedule' ? 'selected' : ''; ?>>No Schedule</option>
                                    </select>
                                </div>
                                
                                <div class="filter-group">
                                    <label class="filter-label">&nbsp;</label>
                                    <div style="display: flex; gap: 10px;">
                                        <button type="submit" class="btn btn-primary">
                                            <i class='bx bx-search'></i>
                                            Filter
                                        </button>
                                        <button type="button" class="btn btn-secondary" onclick="clearFilters()">
                                            <i class='bx bx-reset'></i>
                                            Clear
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </form>
                    </div>
                    
                    <!-- Establishments Table -->
                    <div class="table-container">
                        <?php if (count($establishments) > 0): ?>
                            <div class="table-header">
                                <h3 class="table-title">
                                    <i class='bx bx-list-ul'></i>
                                    Establishments for Inspection
                                </h3>
                            </div>
                            
                            <table class="table">
                                <thead>
                                    <tr>
                                        <th>Establishment Details</th>
                                        <th>Type & Location</th>
                                        <th>Inspection Schedule</th>
                                        <th>Risk Level</th>
                                        <th>Compliance</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($establishments as $est): 
                                        $next_inspection = $est['next_scheduled_inspection'] ? date('M j, Y', strtotime($est['next_scheduled_inspection'])) : 'Not Scheduled';
                                        $last_inspection = $est['last_inspection_date'] ? date('M j, Y', strtotime($est['last_inspection_date'])) : 'Never';
                                        $compliance_rating = $est['compliance_rating'] ?? 0;
                                        
                                        // Determine risk class
                                        $risk_class = 'risk-' . strtolower($est['overall_risk_level']);
                                        
                                        // Determine compliance class
                                        if ($compliance_rating >= 85) {
                                            $compliance_class = 'compliance-high';
                                        } elseif ($compliance_rating >= 70) {
                                            $compliance_class = 'compliance-medium';
                                        } else {
                                            $compliance_class = 'compliance-low';
                                        }
                                    ?>
                                    <tr>
                                        <td>
                                            <div class="establishment-info">
                                                <div class="establishment-name">
                                                    <?php echo htmlspecialchars($est['establishment_name']); ?>
                                                </div>
                                                <div class="establishment-address">
                                                    <?php echo htmlspecialchars($est['address']); ?>
                                                </div>
                                                <div class="establishment-details">
                                                    <div class="detail-item">
                                                        <i class='bx bx-user'></i>
                                                        <span>Owner: <?php echo htmlspecialchars($est['owner_name']); ?></span>
                                                    </div>
                                                    <div class="detail-item">
                                                        <i class='bx bx-phone'></i>
                                                        <span><?php echo htmlspecialchars($est['owner_contact']); ?></span>
                                                    </div>
                                                </div>
                                            </div>
                                        </td>
                                        <td>
                                            <div class="inspection-date">
                                                <div class="date-label">Type</div>
                                                <div class="date-value"><?php echo htmlspecialchars($est['establishment_type']); ?></div>
                                                
                                                <div class="date-label" style="margin-top: 8px;">Barangay</div>
                                                <div class="date-value"><?php echo htmlspecialchars($est['barangay']); ?></div>
                                                
                                                <div class="date-label" style="margin-top: 8px;">Last Inspection</div>
                                                <div class="date-value"><?php echo $last_inspection; ?></div>
                                            </div>
                                        </td>
                                        <td>
                                            <div class="inspection-date">
                                                <div class="date-label">Next Inspection</div>
                                                <div class="date-value"><?php echo $next_inspection; ?></div>
                                                
                                                <div class="date-label" style="margin-top: 8px;">Frequency</div>
                                                <div class="date-value"><?php echo htmlspecialchars($est['inspection_frequency']); ?></div>
                                            </div>
                                        </td>
                                        <td>
                                            <div class="risk-level <?php echo $risk_class; ?>">
                                                <?php echo htmlspecialchars(ucfirst($est['overall_risk_level'])); ?>
                                            </div>
                                        </td>
                                        <td>
                                            <div class="compliance-badge <?php echo $compliance_class; ?>">
                                                <?php echo $compliance_rating; ?>%
                                            </div>
                                        </td>
                                        <td>
                                            <div class="action-buttons">
                                                <button type="button" class="btn btn-primary btn-sm conduct-inspection-btn"
                                                        data-est-id="<?php echo $est['id']; ?>"
                                                        data-est-name="<?php echo htmlspecialchars($est['establishment_name']); ?>">
                                                    <i class='bx bx-edit'></i>
                                                    Conduct Inspection
                                                </button>
                                                <button type="button" class="btn btn-info btn-sm view-establishment-btn"
                                                        data-est-id="<?php echo $est['id']; ?>"
                                                        data-est-name="<?php echo htmlspecialchars($est['establishment_name']); ?>">
                                                    <i class='bx bx-info-circle'></i>
                                                    Details
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php else: ?>
                            <div class="empty-state">
                                <i class='bx bx-building'></i>
                                <h3>No Establishments Found</h3>
                                <p>No establishments match your search criteria. Try adjusting your filters.</p>
                            </div>
                        <?php endif; ?>
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
            
            // Auto-hide notification after 5 seconds
            setTimeout(() => {
                const notification = document.getElementById('notification');
                if (notification) {
                    notification.classList.remove('show');
                }
            }, 5000);
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
            });
            
            // Close dropdowns when clicking outside
            document.addEventListener('click', function() {
                userDropdown.classList.remove('show');
            });
            
            // Notification close
            const notificationClose = document.getElementById('notification-close');
            if (notificationClose) {
                notificationClose.addEventListener('click', function() {
                    document.getElementById('notification').classList.remove('show');
                });
            }
            
            // Search input
            const searchInput = document.getElementById('search-input');
            searchInput.addEventListener('keypress', function(e) {
                if (e.key === 'Enter') {
                    document.getElementById('filters-form').submit();
                }
            });
            
            // View Details buttons
            document.querySelectorAll('.view-establishment-btn').forEach(btn => {
                btn.addEventListener('click', function() {
                    const estId = this.getAttribute('data-est-id');
                    const estName = this.getAttribute('data-est-name');
                    showEstablishmentDetails(estId, estName);
                });
            });
            
            // Conduct Inspection buttons
            document.querySelectorAll('.conduct-inspection-btn').forEach(btn => {
                btn.addEventListener('click', function() {
                    const estId = this.getAttribute('data-est-id');
                    const estName = this.getAttribute('data-est-name');
                    showInspectionForm(estId, estName);
                });
            });
            
            // Modal close buttons
            document.getElementById('closeDetailsModal').addEventListener('click', function() {
                document.getElementById('detailsModal').classList.remove('show');
            });
            
            document.getElementById('closeDetailsModalBtn').addEventListener('click', function() {
                document.getElementById('detailsModal').classList.remove('show');
            });
            
            document.getElementById('closeInspectionModal').addEventListener('click', function() {
                document.getElementById('inspectionModal').classList.remove('show');
            });
            
            document.getElementById('cancelInspectionBtn').addEventListener('click', function() {
                document.getElementById('inspectionModal').classList.remove('show');
            });
            
            // Close modals when clicking outside
            document.querySelectorAll('.modal').forEach(modal => {
                modal.addEventListener('click', function(e) {
                    if (e.target === this) {
                        this.classList.remove('show');
                    }
                });
            });
        }
        
        async function showEstablishmentDetails(establishmentId, establishmentName) {
            try {
                // Fetch establishment details via AJAX
                const response = await fetch(`conduct_inspections.php?action=get_establishment_details&establishment_id=${establishmentId}`);
                const data = await response.json();
                
                if (data) {
                    // Format last inspection date
                    const lastInspection = data.last_inspection_date ? 
                        new Date(data.last_inspection_date).toLocaleDateString('en-US', { 
                            year: 'numeric', 
                            month: 'short', 
                            day: 'numeric' 
                        }) : 'Never';
                    
                    // Format next inspection date
                    const nextInspection = data.next_scheduled_inspection ? 
                        new Date(data.next_scheduled_inspection).toLocaleDateString('en-US', { 
                            year: 'numeric', 
                            month: 'short', 
                            day: 'numeric' 
                        }) : 'Not Scheduled';
                    
                    // Format business permit expiry
                    const permitExpiry = data.business_permit_expiry ? 
                        new Date(data.business_permit_expiry).toLocaleDateString('en-US', { 
                            year: 'numeric', 
                            month: 'short', 
                            day: 'numeric' 
                        }) : 'N/A';
                    
                    // Determine compliance class
                    const rating = data.compliance_rating || 0;
                    let complianceClass = 'compliance-badge';
                    if (rating >= 85) complianceClass += ' compliance-high';
                    else if (rating >= 70) complianceClass += ' compliance-medium';
                    else complianceClass += ' compliance-low';
                    
                    // Determine risk class
                    const riskClass = 'risk-' + (data.overall_risk_level || 'medium').toLowerCase();
                    
                    // Build the details HTML
                    const detailsHTML = `
                        <div class="details-section">
                            <h4 class="details-section-title">
                                <i class='bx bx-info-circle'></i>
                                Basic Information
                            </h4>
                            <div class="details-grid">
                                <div class="detail-item-large">
                                    <div class="detail-item-label">Establishment Name</div>
                                    <div class="detail-item-value">${data.establishment_name}</div>
                                </div>
                                <div class="detail-item-large">
                                    <div class="detail-item-label">Establishment Type</div>
                                    <div class="detail-item-value">${data.establishment_type}</div>
                                </div>
                                <div class="detail-item-large">
                                    <div class="detail-item-label">Owner Name</div>
                                    <div class="detail-item-value">${data.owner_name}</div>
                                </div>
                                <div class="detail-item-large">
                                    <div class="detail-item-label">Owner Contact</div>
                                    <div class="detail-item-value">${data.owner_contact}</div>
                                </div>
                                <div class="detail-item-large">
                                    <div class="detail-item-label">Owner Email</div>
                                    <div class="detail-item-value">${data.owner_email || 'N/A'}</div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="details-section">
                            <h4 class="details-section-title">
                                <i class='bx bx-map'></i>
                                Location Information
                            </h4>
                            <div class="details-grid">
                                <div class="detail-item-large">
                                    <div class="detail-item-label">Address</div>
                                    <div class="detail-item-value">${data.address}</div>
                                </div>
                                <div class="detail-item-large">
                                    <div class="detail-item-label">Barangay</div>
                                    <div class="detail-item-value">${data.barangay}</div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="details-section">
                            <h4 class="details-section-title">
                                <i class='bx bx-building'></i>
                                Building Information
                            </h4>
                            <div class="details-grid">
                                <div class="detail-item-large">
                                    <div class="detail-item-label">Occupancy Type</div>
                                    <div class="detail-item-value">${data.occupancy_type || 'N/A'}</div>
                                </div>
                                <div class="detail-item-large">
                                    <div class="detail-item-label">Occupancy Count</div>
                                    <div class="detail-item-value">${data.occupancy_count || 'N/A'}</div>
                                </div>
                                <div class="detail-item-large">
                                    <div class="detail-item-label">Floor Area (sq.m.)</div>
                                    <div class="detail-item-value">${data.floor_area ? parseFloat(data.floor_area).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) : 'N/A'}</div>
                                </div>
                                <div class="detail-item-large">
                                    <div class="detail-item-label">Number of Floors</div>
                                    <div class="detail-item-value">${data.number_of_floors || 'N/A'}</div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="details-section">
                            <h4 class="details-section-title">
                                <i class='bx bx-shield'></i>
                                Safety Information
                            </h4>
                            <div class="details-grid">
                                <div class="detail-item-large">
                                    <div class="detail-item-label">Fire Safety Officer</div>
                                    <div class="detail-item-value">${data.fire_safety_officer || 'Not assigned'}</div>
                                </div>
                                <div class="detail-item-large">
                                    <div class="detail-item-label">FSO Contact</div>
                                    <div class="detail-item-value">${data.fso_contact || 'N/A'}</div>
                                </div>
                                <div class="detail-item-large">
                                    <div class="detail-item-label">Business Permit Number</div>
                                    <div class="detail-item-value">${data.business_permit_number || 'N/A'}</div>
                                </div>
                                <div class="detail-item-large">
                                    <div class="detail-item-label">Permit Expiry</div>
                                    <div class="detail-item-value">${permitExpiry}</div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="details-section">
                            <h4 class="details-section-title">
                                <i class='bx bx-calendar-check'></i>
                                Inspection Information
                            </h4>
                            <div class="details-grid">
                                <div class="detail-item-large">
                                    <div class="detail-item-label">Last Inspection Date</div>
                                    <div class="detail-item-value">${lastInspection}</div>
                                </div>
                                <div class="detail-item-large">
                                    <div class="detail-item-label">Next Scheduled Inspection</div>
                                    <div class="detail-item-value">${nextInspection}</div>
                                </div>
                                <div class="detail-item-large">
                                    <div class="detail-item-label">Inspection Frequency</div>
                                    <div class="detail-item-value">${data.inspection_frequency || 'Annual'}</div>
                                </div>
                                <div class="detail-item-large">
                                    <div class="detail-item-label">Compliance Rating</div>
                                    <div class="detail-item-value">
                                        <span class="${complianceClass}">${rating}%</span>
                                    </div>
                                </div>
                                <div class="detail-item-large">
                                    <div class="detail-item-label">Overall Risk Level</div>
                                    <div class="detail-item-value">
                                        <span class="risk-level ${riskClass}">${(data.overall_risk_level || 'medium').charAt(0).toUpperCase() + (data.overall_risk_level || 'medium').slice(1)}</span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    `;
                    
                    // Update modal content
                    document.getElementById('detailsModalBody').innerHTML = detailsHTML;
                    
                    // Show modal
                    document.getElementById('detailsModal').classList.add('show');
                }
            } catch (error) {
                console.error('Error fetching establishment details:', error);
                alert('Failed to load establishment details. Please try again.');
            }
        }
        
        async function showInspectionForm(establishmentId, establishmentName) {
            try {
                // Fetch establishment details to populate the form
                const response = await fetch(`conduct_inspections.php?action=get_establishment_details&establishment_id=${establishmentId}`);
                const data = await response.json();
                
                if (data) {
                    // Get checklist items
                    const checklistItems = <?php echo json_encode($checklist_items); ?>;
                    const inspectionTypes = <?php echo json_encode($inspection_types); ?>;
                    
                    // Organize checklist items by category
                    const itemsByCategory = {};
                    checklistItems.forEach(item => {
                        if (!itemsByCategory[item.category]) {
                            itemsByCategory[item.category] = [];
                        }
                        itemsByCategory[item.category].push(item);
                    });
                    
                    // Build checklist HTML
                    let checklistHTML = '';
                    Object.keys(itemsByCategory).forEach(category => {
                        checklistHTML += `
                            <div class="checklist-category">
                                <h4 class="category-title">${category}</h4>
                        `;
                        
                        itemsByCategory[category].forEach(item => {
                            checklistHTML += `
                                <div class="checklist-item">
                                    <div class="checklist-item-content">
                                        <div class="checklist-item-code">${item.item_code}</div>
                                        <div class="checklist-item-desc">${item.item_description}</div>
                                        ${item.compliance_standard ? `<div class="checklist-item-standard">Standard: ${item.compliance_standard}</div>` : ''}
                                    </div>
                                    
                                    <div class="checklist-response">
                                        <select class="form-select response-select" name="checklist_response[${item.id}][status]" required>
                                            <option value="">Select...</option>
                                            <option value="compliant">Compliant</option>
                                            <option value="non_compliant">Non-Compliant</option>
                                            <option value="not_applicable">Not Applicable</option>
                                            <option value="partial">Partial</option>
                                        </select>
                                        
                                        <input type="number" class="form-input score-input" 
                                               name="checklist_response[${item.id}][score]" 
                                               min="0" max="100" value="0" placeholder="Score">
                                        
                                        <input type="text" class="form-input notes-input" 
                                               name="checklist_response[${item.id}][notes]" 
                                               placeholder="Notes (optional)">
                                    </div>
                                </div>
                            `;
                        });
                        
                        checklistHTML += `</div>`;
                    });
                    
                    // Build inspection types options
                    let inspectionTypesHTML = '<option value="">Select type...</option>';
                    inspectionTypes.forEach(type => {
                        inspectionTypesHTML += `<option value="${type}">${type.charAt(0).toUpperCase() + type.slice(1).replace('_', ' ')}</option>`;
                    });
                    
                    // Build the form HTML
                    const formHTML = `
                        <input type="hidden" name="establishment_id" value="${establishmentId}">
                        
                        <!-- Basic Information Section -->
                        <div class="form-section">
                            <h3 class="section-title">
                                <i class='bx bx-info-circle'></i>
                                Inspection Information
                            </h3>
                            
                            <div class="form-row">
                                <div class="form-group">
                                    <label class="form-label required" for="inspection_date">Inspection Date</label>
                                    <input type="date" class="form-input" id="inspection_date" name="inspection_date" 
                                           value="${new Date().toISOString().split('T')[0]}" required>
                                </div>
                                
                                <div class="form-group">
                                    <label class="form-label required" for="inspection_type">Inspection Type</label>
                                    <select class="form-select" id="inspection_type" name="inspection_type" required>
                                        ${inspectionTypesHTML}
                                    </select>
                                </div>
                            </div>
                            
                            <div class="form-row">
                                <div class="form-group">
                                    <div class="establishment-info">
                                        <div class="establishment-name">${data.establishment_name}</div>
                                        <div class="establishment-address">${data.address}</div>
                                        <div class="establishment-details">
                                            <div class="detail-item">
                                                <i class='bx bx-user'></i>
                                                <span>Owner: ${data.owner_name}</span>
                                            </div>
                                            <div class="detail-item">
                                                <i class='bx bx-phone'></i>
                                                <span>${data.owner_contact}</span>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Checklist Section -->
                        <div class="form-section">
                            <h3 class="section-title">
                                <i class='bx bx-check-square'></i>
                                Fire Safety Checklist
                            </h3>
                            
                            <div class="checklist-container">
                                ${checklistHTML}
                            </div>
                        </div>
                        
                        <!-- Violations Section -->
                        <div class="form-section">
                            <h3 class="section-title">
                                <i class='bx bx-error'></i>
                                Violations (if any)
                            </h3>
                            
                            <div class="violations-container" id="violations-container">
                                <div class="violation-item" id="violation-template" style="display: none;">
                                    <div class="violation-fields">
                                        <div>
                                            <label class="form-label">Violation Code</label>
                                            <input type="text" class="form-input" name="violations[0][code]" placeholder="e.g., FS-001">
                                        </div>
                                        <div>
                                            <label class="form-label">Description</label>
                                            <input type="text" class="form-input" name="violations[0][description]" placeholder="Description of violation">
                                        </div>
                                        <div>
                                            <label class="form-label">Severity</label>
                                            <select class="form-select" name="violations[0][severity]">
                                                <option value="minor">Minor</option>
                                                <option value="major">Major</option>
                                                <option value="critical">Critical</option>
                                            </select>
                                        </div>
                                        <div>
                                            <label class="form-label">Section Violated</label>
                                            <input type="text" class="form-input" name="violations[0][section]" placeholder="e.g., NFPA 101">
                                        </div>
                                        <div>
                                            <label class="form-label">Fine Amount (₱)</label>
                                            <input type="number" class="form-input" name="violations[0][fine]" min="0" step="0.01" placeholder="0.00">
                                        </div>
                                        <div>
                                            <label class="form-label">Compliance Deadline</label>
                                            <input type="date" class="form-input" name="violations[0][deadline]">
                                        </div>
                                    </div>
                                    <button type="button" class="remove-btn remove-violation-btn">
                                        <i class='bx bx-trash'></i>
                                    </button>
                                </div>
                                
                                <button type="button" class="btn btn-secondary add-violation-btn" onclick="addViolation()">
                                    <i class='bx bx-plus'></i>
                                    Add Violation
                                </button>
                            </div>
                        </div>
                        
                        <!-- Recommendations Section -->
                        <div class="form-section">
                            <h3 class="section-title">
                                <i class='bx bx-message-detail'></i>
                                Recommendations & Actions
                            </h3>
                            
                            <div class="form-row">
                                <div class="form-group">
                                    <label class="form-label" for="recommendations">Recommendations</label>
                                    <textarea class="form-textarea" id="recommendations" name="recommendations" 
                                              placeholder="General recommendations and observations..." 
                                              rows="4"></textarea>
                                </div>
                            </div>
                            
                            <div class="form-row">
                                <div class="form-group">
                                    <label class="form-label" for="corrective_actions">Corrective Actions Required</label>
                                    <textarea class="form-textarea" id="corrective_actions" name="corrective_actions" 
                                              placeholder="Specific corrective actions required..." 
                                              rows="4"></textarea>
                                </div>
                            </div>
                            
                            <div class="form-row">
                                <div class="form-group">
                                    <label class="form-label" for="compliance_deadline">Compliance Deadline</label>
                                    <input type="date" class="form-input" id="compliance_deadline" name="compliance_deadline">
                                </div>
                            </div>
                        </div>
                    `;
                    
                    // Update modal content
                    document.getElementById('inspectionModalBody').innerHTML = formHTML;
                    
                    // Initialize checklist scoring
                    initChecklistScoring();
                    
                    // Show modal
                    document.getElementById('inspectionModal').classList.add('show');
                }
            } catch (error) {
                console.error('Error loading inspection form:', error);
                alert('Failed to load inspection form. Please try again.');
            }
        }
        
        function initChecklistScoring() {
            // Auto-set score based on compliance status
            document.querySelectorAll('.response-select').forEach(select => {
                select.addEventListener('change', function() {
                    const scoreInput = this.closest('.checklist-response').querySelector('.score-input');
                    switch(this.value) {
                        case 'compliant':
                            scoreInput.value = 100;
                            break;
                        case 'partial':
                            scoreInput.value = 50;
                            break;
                        case 'non_compliant':
                            scoreInput.value = 0;
                            break;
                        case 'not_applicable':
                            scoreInput.value = 0;
                            break;
                    }
                });
            });
        }
        
        let violationCount = 0;
        
        function addViolation() {
            const container = document.getElementById('violations-container');
            const template = document.getElementById('violation-template');
            
            const newViolation = template.cloneNode(true);
            newViolation.id = '';
            newViolation.style.display = 'flex';
            
            // Update all input names with new index
            const inputs = newViolation.querySelectorAll('input, select');
            inputs.forEach(input => {
                const name = input.getAttribute('name');
                if (name) {
                    input.setAttribute('name', name.replace('[0]', '[' + violationCount + ']'));
                }
            });
            
            // Add remove functionality
            const removeBtn = newViolation.querySelector('.remove-violation-btn');
            removeBtn.addEventListener('click', function() {
                this.closest('.violation-item').remove();
            });
            
            // Insert before the add button
            const addButton = container.querySelector('.add-violation-btn');
            container.insertBefore(newViolation, addButton);
            
            violationCount++;
        }
        
        function clearFilters() {
            window.location.href = 'conduct_inspections.php';
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
        
        function toggleSubmenu(id) {
            const submenu = document.getElementById(id);
            const arrow = submenu.previousElementSibling.querySelector('.dropdown-arrow');
            
            submenu.classList.toggle('active');
            arrow.classList.toggle('rotated');
        }
    </script>
</body>
</html>