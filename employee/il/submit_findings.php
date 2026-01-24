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

// Handle AJAX requests for report details
if (isset($_GET['action']) && $_GET['action'] === 'get_report_details' && isset($_GET['report_id'])) {
    $report_id = intval($_GET['report_id']);
    $report = getReportDetails($pdo, $report_id);
    
    if ($report) {
        // Format data for JSON response
        $response = [
            'id' => $report['id'],
            'report_number' => $report['report_number'],
            'inspection_date' => $report['inspection_date'],
            'created_at' => $report['created_at'],
            'inspection_type' => $report['inspection_type'],
            'status' => $report['status'],
            'overall_compliance_score' => $report['overall_compliance_score'],
            'risk_assessment' => $report['risk_assessment'],
            'fire_hazard_level' => $report['fire_hazard_level'],
            'recommendations' => $report['recommendations'],
            'corrective_actions_required' => $report['corrective_actions_required'],
            'compliance_deadline' => $report['compliance_deadline'],
            'admin_review_notes' => $report['admin_review_notes'],
            
            // Establishment details
            'establishment_name' => $report['establishment_name'],
            'establishment_type' => $report['establishment_type'],
            'owner_name' => $report['owner_name'],
            'address' => $report['address'],
            'barangay' => $report['barangay'],
            'business_permit_number' => $report['business_permit_number'],
            
            // Checklist responses
            'checklist_responses' => $report['checklist_responses'] ?? [],
            
            // Violations
            'violations' => $report['violations'] ?? []
        ];
        
        header('Content-Type: application/json');
        echo json_encode($response);
        exit();
    } else {
        http_response_code(404);
        echo json_encode(['error' => 'Report not found']);
        exit();
    }
}

// Handle AJAX request for checklist items
if (isset($_GET['action']) && $_GET['action'] === 'get_checklist_items') {
    try {
        $sql = "SELECT id, item_code, item_description, category, compliance_standard 
                FROM inspection_checklist_items 
                WHERE is_active = 1 
                ORDER BY category, item_code";
        $stmt = $pdo->query($sql);
        $checklist_items = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        header('Content-Type: application/json');
        echo json_encode($checklist_items);
        exit();
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Failed to fetch checklist items']);
        exit();
    }
}

// Function to get inspection reports for submission
function getInspectionReports($pdo, $user_id, $status = null, $search = null, $date_from = null, $date_to = null) {
    $sql = "SELECT ir.*, ie.establishment_name, ie.address, ie.barangay, 
                   ie.owner_name, ie.establishment_type,
                   u.first_name, u.middle_name, u.last_name
            FROM inspection_reports ir
            JOIN inspection_establishments ie ON ir.establishment_id = ie.id
            LEFT JOIN users u ON ir.inspected_by = u.id
            WHERE ir.status IN ('draft', 'revision_requested') 
            AND ir.inspected_by = ?";
    
    $params = [$user_id];
    
    if ($status && $status !== 'all') {
        $sql .= " AND ir.status = ?";
        $params[] = $status;
    }
    
    if ($search) {
        $sql .= " AND (ie.establishment_name LIKE ? OR ir.report_number LIKE ? OR ie.owner_name LIKE ?)";
        $search_param = "%$search%";
        $params[] = $search_param;
        $params[] = $search_param;
        $params[] = $search_param;
    }
    
    if ($date_from) {
        $sql .= " AND DATE(ir.inspection_date) >= ?";
        $params[] = $date_from;
    }
    
    if ($date_to) {
        $sql .= " AND DATE(ir.inspection_date) <= ?";
        $params[] = $date_to;
    }
    
    $sql .= " ORDER BY ir.inspection_date DESC, ir.created_at DESC";
    
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Error fetching inspection reports: " . $e->getMessage());
        return [];
    }
}

// Function to get report details
function getReportDetails($pdo, $report_id) {
    $sql = "SELECT ir.*, ie.*, 
                   u.first_name as inspector_first, u.middle_name as inspector_middle, 
                   u.last_name as inspector_last
            FROM inspection_reports ir
            JOIN inspection_establishments ie ON ir.establishment_id = ie.id
            LEFT JOIN users u ON ir.inspected_by = u.id
            WHERE ir.id = ?";
    
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$report_id]);
        $report = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($report) {
            // Get checklist responses
            $checklist_sql = "SELECT icr.*, ici.item_code, ici.item_description, ici.category,
                                     ici.compliance_standard
                             FROM inspection_checklist_responses icr
                             JOIN inspection_checklist_items ici ON icr.checklist_item_id = ici.id
                             WHERE icr.inspection_id = ?
                             ORDER BY ici.category, ici.item_code";
            $checklist_stmt = $pdo->prepare($checklist_sql);
            $checklist_stmt->execute([$report_id]);
            $report['checklist_responses'] = $checklist_stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Get violations
            $violations_sql = "SELECT * FROM inspection_violations 
                              WHERE inspection_id = ? 
                              ORDER BY severity DESC, violation_code";
            $violations_stmt = $pdo->prepare($violations_sql);
            $violations_stmt->execute([$report_id]);
            $report['violations'] = $violations_stmt->fetchAll(PDO::FETCH_ASSOC);
        }
        
        return $report;
    } catch (PDOException $e) {
        error_log("Error fetching report details: " . $e->getMessage());
        return null;
    }
}

// Function to submit report for review
function submitReportForReview($pdo, $report_id, $user_id, $notes = null) {
    try {
        $sql = "UPDATE inspection_reports 
                SET status = 'submitted',
                    admin_reviewed_at = NULL,
                    admin_reviewed_by = NULL,
                    admin_review_notes = NULL,
                    updated_at = NOW(),
                    submitted_at = NOW()
                WHERE id = ? AND inspected_by = ? AND status IN ('draft', 'revision_requested')";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$report_id, $user_id]);
        
        return $stmt->rowCount() > 0;
    } catch (PDOException $e) {
        error_log("Error submitting report: " . $e->getMessage());
        return false;
    }
}

// Function to save report revisions
function saveReportRevisions($pdo, $report_id, $user_id, $report_data) {
    try {
        $pdo->beginTransaction();
        
        // Update main report
        $sql = "UPDATE inspection_reports 
                SET overall_compliance_score = ?,
                    risk_assessment = ?,
                    fire_hazard_level = ?,
                    recommendations = ?,
                    corrective_actions_required = ?,
                    compliance_deadline = ?,
                    status = 'draft',
                    updated_at = NOW()
                WHERE id = ? AND inspected_by = ? AND status IN ('draft', 'revision_requested')";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            $report_data['overall_compliance_score'],
            $report_data['risk_assessment'],
            $report_data['fire_hazard_level'],
            $report_data['recommendations'] ?? null,
            $report_data['corrective_actions'] ?? null,
            $report_data['compliance_deadline'] ?? null,
            $report_id,
            $user_id
        ]);
        
        if ($stmt->rowCount() > 0) {
            // Update checklist responses if provided
            if (isset($report_data['checklist_responses']) && is_array($report_data['checklist_responses'])) {
                foreach ($report_data['checklist_responses'] as $item_id => $response) {
                    // Check if response already exists
                    $check_sql = "SELECT id FROM inspection_checklist_responses 
                                 WHERE inspection_id = ? AND checklist_item_id = ?";
                    $check_stmt = $pdo->prepare($check_sql);
                    $check_stmt->execute([$report_id, $item_id]);
                    
                    if ($check_stmt->fetch()) {
                        // Update existing
                        $update_sql = "UPDATE inspection_checklist_responses 
                                       SET compliance_status = ?, score = ?, notes = ?
                                       WHERE inspection_id = ? AND checklist_item_id = ?";
                        $update_stmt = $pdo->prepare($update_sql);
                        $update_stmt->execute([
                            $response['status'],
                            $response['score'],
                            $response['notes'] ?? null,
                            $report_id,
                            $item_id
                        ]);
                    } else {
                        // Insert new
                        $insert_sql = "INSERT INTO inspection_checklist_responses 
                                      (inspection_id, checklist_item_id, compliance_status, score, notes)
                                      VALUES (?, ?, ?, ?, ?)";
                        $insert_stmt = $pdo->prepare($insert_sql);
                        $insert_stmt->execute([
                            $report_id,
                            $item_id,
                            $response['status'],
                            $response['score'],
                            $response['notes'] ?? null
                        ]);
                    }
                }
            }
            
            // Update violations if provided
            if (isset($report_data['violations']) && is_array($report_data['violations'])) {
                // First delete existing violations
                $delete_sql = "DELETE FROM inspection_violations WHERE inspection_id = ?";
                $delete_stmt = $pdo->prepare($delete_sql);
                $delete_stmt->execute([$report_id]);
                
                // Then insert new violations
                foreach ($report_data['violations'] as $violation) {
                    if (!empty($violation['code']) && !empty($violation['description'])) {
                        $violation_sql = "INSERT INTO inspection_violations 
                                         (inspection_id, violation_code, violation_description, 
                                          severity, section_violated, fine_amount, compliance_deadline, status) 
                                         VALUES (?, ?, ?, ?, ?, ?, ?, 'pending')";
                        $violation_stmt = $pdo->prepare($violation_sql);
                        $violation_stmt->execute([
                            $report_id,
                            $violation['code'],
                            $violation['description'],
                            $violation['severity'] ?? 'minor',
                            $violation['section'] ?? null,
                            $violation['fine'] ?? null,
                            $violation['deadline'] ?? null
                        ]);
                    }
                }
            }
            
            // Update establishment compliance rating
            $establishment_sql = "UPDATE inspection_establishments 
                                 SET compliance_rating = ?,
                                     overall_risk_level = ?,
                                     updated_at = NOW()
                                 WHERE id = (SELECT establishment_id FROM inspection_reports WHERE id = ?)";
            $establishment_stmt = $pdo->prepare($establishment_sql);
            $establishment_stmt->execute([
                $report_data['overall_compliance_score'],
                $report_data['risk_assessment'],
                $report_id
            ]);
            
            $pdo->commit();
            return true;
        }
        
        $pdo->rollBack();
        return false;
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log("Error saving report revisions: " . $e->getMessage());
        return false;
    }
}

// Handle actions
$success_message = '';
$error_message = '';

// Handle report submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['submit_report'])) {
        $report_id = intval($_POST['report_id']);
        
        if (submitReportForReview($pdo, $report_id, $user_id)) {
            $success_message = "Report submitted for review successfully!";
        } else {
            $error_message = "Failed to submit report. It may have already been submitted or you don't have permission.";
        }
    } elseif (isset($_POST['save_revisions'])) {
        $report_id = intval($_POST['report_id']);
        
        // Prepare report data
        $report_data = [
            'overall_compliance_score' => intval($_POST['overall_compliance_score']),
            'risk_assessment' => $_POST['risk_assessment'],
            'fire_hazard_level' => $_POST['fire_hazard_level'],
            'recommendations' => $_POST['recommendations'] ?? '',
            'corrective_actions' => $_POST['corrective_actions'] ?? '',
            'compliance_deadline' => !empty($_POST['compliance_deadline']) ? $_POST['compliance_deadline'] : null
        ];
        
        // Process checklist responses
        if (isset($_POST['checklist_response'])) {
            $checklist_responses = [];
            foreach ($_POST['checklist_response'] as $item_id => $response) {
                $checklist_responses[$item_id] = [
                    'status' => $response['status'],
                    'score' => isset($response['score']) ? intval($response['score']) : 0,
                    'notes' => $response['notes'] ?? null
                ];
            }
            $report_data['checklist_responses'] = $checklist_responses;
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
                        'fine' => isset($violation['fine']) && $violation['fine'] !== '' ? floatval($violation['fine']) : null,
                        'deadline' => !empty($violation['deadline']) ? $violation['deadline'] : null
                    ];
                }
            }
            $report_data['violations'] = $violations;
        }
        
        if (saveReportRevisions($pdo, $report_id, $user_id, $report_data)) {
            $success_message = "Report revisions saved successfully!";
        } else {
            $error_message = "Failed to save revisions. Please try again.";
        }
    }
}

// Get parameters for filtering
$status_filter = $_GET['status'] ?? null;
$search_term = $_GET['search'] ?? null;
$date_from = $_GET['date_from'] ?? null;
$date_to = $_GET['date_to'] ?? null;

// Get reports
$reports = getInspectionReports($pdo, $user_id, $status_filter, $search_term, $date_from, $date_to);

// Statistics
$total_reports = count($reports);
$draft_reports = 0;
$revision_requested = 0;
$average_compliance = 0;
$high_risk_reports = 0;

foreach ($reports as $report) {
    if ($report['status'] === 'draft') $draft_reports++;
    if ($report['status'] === 'revision_requested') $revision_requested++;
    $average_compliance += $report['overall_compliance_score'] ?? 0;
    if ($report['risk_assessment'] === 'high' || $report['risk_assessment'] === 'critical') $high_risk_reports++;
}

if ($total_reports > 0) {
    $average_compliance = round($average_compliance / $total_reports);
}

$stmt = null;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Submit Findings - Fire & Rescue Management</title>
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

        .stat-icon.draft {
            background: rgba(59, 130, 246, 0.1);
            color: var(--info);
        }

        .stat-icon.revision {
            background: rgba(245, 158, 11, 0.1);
            color: var(--warning);
        }

        .stat-icon.compliance {
            background: rgba(16, 185, 129, 0.1);
            color: var(--success);
        }

        .stat-icon.high-risk {
            background: rgba(220, 38, 38, 0.1);
            color: var(--danger);
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

        .report-info {
            display: flex;
            flex-direction: column;
            gap: 8px;
        }

        .report-number {
            font-weight: 700;
            color: var(--text-color);
            font-size: 16px;
        }

        .establishment-name {
            font-size: 14px;
            color: var(--text-light);
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

        .status-badge {
            display: inline-block;
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            text-align: center;
            min-width: 80px;
        }

        .status-draft {
            background: rgba(59, 130, 246, 0.1);
            color: var(--info);
            border: 1px solid rgba(59, 130, 246, 0.2);
        }

        .status-revision {
            background: rgba(245, 158, 11, 0.1);
            color: var(--warning);
            border: 1px solid rgba(245, 158, 11, 0.2);
        }

        .status-submitted {
            background: rgba(16, 185, 129, 0.1);
            color: var(--success);
            border: 1px solid rgba(16, 185, 129, 0.2);
        }

        .status-approved {
            background: rgba(139, 92, 246, 0.1);
            color: var(--purple);
            border: 1px solid rgba(139, 92, 246, 0.2);
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

        /* Report Details */
        .report-details-section {
            margin-bottom: 25px;
            padding-bottom: 25px;
            border-bottom: 1px solid var(--border-color);
        }

        .report-details-section:last-child {
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

        .details-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
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

        /* Checklist Styles */
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

        /* Violations */
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

        /* Summary Stats */
        .summary-stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 15px;
            margin-bottom: 20px;
        }

        .summary-stat {
            text-align: center;
            padding: 15px;
            border-radius: 10px;
            background: var(--gray-100);
        }

        .dark-mode .summary-stat {
            background: var(--gray-800);
        }

        .summary-stat-label {
            font-size: 12px;
            color: var(--text-light);
            margin-bottom: 5px;
        }

        .summary-stat-value {
            font-size: 24px;
            font-weight: 700;
            color: var(--text-color);
        }

        /* Form Styles */
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
            
            .summary-stats {
                grid-template-columns: 1fr;
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
    
    <!-- Review Modal -->
    <div class="modal" id="reviewModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title">
                    <i class='bx bx-file'></i>
                    Review & Submit Report
                </h3>
                <button class="close-modal" id="closeReviewModal">&times;</button>
            </div>
            <div class="modal-body" id="reviewModalBody">
                <!-- Report details will be loaded here via AJAX -->
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" id="cancelReviewBtn">Cancel</button>
                <button type="button" class="btn btn-success" id="submitReportBtn">
                    <i class='bx bx-send'></i>
                    Submit for Review
                </button>
            </div>
        </div>
    </div>
    
    <!-- Edit Modal -->
    <div class="modal" id="editModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title">
                    <i class='bx bx-edit'></i>
                    Edit Report
                </h3>
                <button class="close-modal" id="closeEditModal">&times;</button>
            </div>
            <form method="POST" id="editForm">
                <div class="modal-body" id="editModalBody">
                    <!-- Edit form will be loaded here via AJAX -->
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" id="cancelEditBtn">Cancel</button>
                    <button type="submit" name="save_revisions" class="btn btn-success">
                        <i class='bx bx-save'></i>
                        Save Revisions
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
                    <div id="inspection" class="submenu active">
                        <a href="conduct_inspections.php" class="submenu-item">Conduct Inspections</a>
                        <a href="submit_findings.php" class="submenu-item active">Submit Findings</a>
                      
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
            <!-- Header -->
            <div class="header">
                <div class="header-content">
                    <div class="search-container">
                        <div class="search-box">
                            <svg class="search-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path>
                            </svg>
                            <input type="text" placeholder="Search reports..." class="search-input" id="search-input">
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
                        <h1 class="dashboard-title">Submit Findings</h1>
                        <p class="dashboard-subtitle">Review, edit, and submit inspection reports for Commonwealth establishments</p>
                    </div>
                </div>
                
                <!-- Content Container -->
                <div class="content-container">
                    <!-- Statistics Cards -->
                    <div class="stats-container">
                        <div class="stat-card">
                            <div class="stat-icon total">
                                <i class='bx bx-file'></i>
                            </div>
                            <div class="stat-value"><?php echo $total_reports; ?></div>
                            <div class="stat-label">Total Reports</div>
                        </div>
                        
                        <div class="stat-card">
                            <div class="stat-icon draft">
                                <i class='bx bx-edit-alt'></i>
                            </div>
                            <div class="stat-value"><?php echo $draft_reports; ?></div>
                            <div class="stat-label">Draft Reports</div>
                        </div>
                        
                        <div class="stat-card">
                            <div class="stat-icon revision">
                                <i class='bx bx-revision'></i>
                            </div>
                            <div class="stat-value"><?php echo $revision_requested; ?></div>
                            <div class="stat-label">Revision Requested</div>
                        </div>
                        
                        <div class="stat-card">
                            <div class="stat-icon compliance">
                                <i class='bx bx-trending-up'></i>
                            </div>
                            <div class="stat-value"><?php echo $average_compliance; ?>%</div>
                            <div class="stat-label">Avg Compliance</div>
                        </div>
                    </div>
                    
                    <!-- Filters -->
                    <div class="filters-container">
                        <h3 class="filters-title">
                            <i class='bx bx-filter-alt'></i>
                            Filter Reports
                        </h3>
                        
                        <form method="GET" id="filters-form">
                            <div class="filters-grid">
                                <div class="filter-group">
                                    <label class="filter-label" for="search">Search</label>
                                    <input type="text" class="filter-input" id="search" name="search" 
                                           value="<?php echo htmlspecialchars($search_term ?? ''); ?>" 
                                           placeholder="Search by report number, establishment...">
                                </div>
                                
                                <div class="filter-group">
                                    <label class="filter-label" for="status">Status</label>
                                    <select class="filter-select" id="status" name="status">
                                        <option value="all">All Status</option>
                                        <option value="draft" <?php echo $status_filter === 'draft' ? 'selected' : ''; ?>>Draft</option>
                                        <option value="revision_requested" <?php echo $status_filter === 'revision_requested' ? 'selected' : ''; ?>>Revision Requested</option>
                                    </select>
                                </div>
                                
                                <div class="filter-group">
                                    <label class="filter-label" for="date_from">From Date</label>
                                    <input type="date" class="filter-input" id="date_from" name="date_from" 
                                           value="<?php echo htmlspecialchars($date_from ?? ''); ?>">
                                </div>
                                
                                <div class="filter-group">
                                    <label class="filter-label" for="date_to">To Date</label>
                                    <input type="date" class="filter-input" id="date_to" name="date_to" 
                                           value="<?php echo htmlspecialchars($date_to ?? ''); ?>">
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
                    
                    <!-- Reports Table -->
                    <div class="table-container">
                        <?php if (count($reports) > 0): ?>
                            <div class="table-header">
                                <h3 class="table-title">
                                    <i class='bx bx-list-ul'></i>
                                    Inspection Reports
                                </h3>
                            </div>
                            
                            <table class="table">
                                <thead>
                                    <tr>
                                        <th>Report Details</th>
                                        <th>Establishment</th>
                                        <th>Inspection Details</th>
                                        <th>Compliance & Risk</th>
                                        <th>Status</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($reports as $report): 
                                        $inspection_date = $report['inspection_date'] ? date('M j, Y', strtotime($report['inspection_date'])) : 'N/A';
                                        $created_date = date('M j, Y', strtotime($report['created_at']));
                                        $compliance_rating = $report['overall_compliance_score'] ?? 0;
                                        
                                        // Determine risk class
                                        $risk_class = 'risk-' . strtolower($report['risk_assessment'] ?? 'medium');
                                        
                                        // Determine compliance class
                                        if ($compliance_rating >= 85) {
                                            $compliance_class = 'compliance-high';
                                        } elseif ($compliance_rating >= 70) {
                                            $compliance_class = 'compliance-medium';
                                        } else {
                                            $compliance_class = 'compliance-low';
                                        }
                                        
                                        // Determine status class
                                        $status_class = 'status-' . str_replace('_', '-', $report['status']);
                                    ?>
                                    <tr>
                                        <td>
                                            <div class="report-info">
                                                <div class="report-number">
                                                    <?php echo htmlspecialchars($report['report_number']); ?>
                                                </div>
                                                <div class="establishment-details">
                                                    <div class="detail-item">
                                                        <i class='bx bx-calendar'></i>
                                                        <span>Created: <?php echo $created_date; ?></span>
                                                    </div>
                                                    <div class="detail-item">
                                                        <i class='bx bx-user'></i>
                                                        <span>Inspector: <?php echo htmlspecialchars($report['first_name'] . ' ' . $report['last_name']); ?></span>
                                                    </div>
                                                </div>
                                            </div>
                                        </td>
                                        <td>
                                            <div class="inspection-date">
                                                <div class="date-label">Establishment</div>
                                                <div class="date-value"><?php echo htmlspecialchars($report['establishment_name']); ?></div>
                                                
                                                <div class="date-label" style="margin-top: 8px;">Type</div>
                                                <div class="date-value"><?php echo htmlspecialchars($report['establishment_type']); ?></div>
                                                
                                                <div class="date-label" style="margin-top: 8px;">Barangay</div>
                                                <div class="date-value"><?php echo htmlspecialchars($report['barangay']); ?></div>
                                            </div>
                                        </td>
                                        <td>
                                            <div class="inspection-date">
                                                <div class="date-label">Inspection Date</div>
                                                <div class="date-value"><?php echo $inspection_date; ?></div>
                                                
                                                <div class="date-label" style="margin-top: 8px;">Type</div>
                                                <div class="date-value"><?php echo htmlspecialchars(ucfirst($report['inspection_type'] ?? 'routine')); ?></div>
                                                
                                                <div class="date-label" style="margin-top: 8px;">Hazard Level</div>
                                                <div class="date-value">
                                                    <?php echo htmlspecialchars(ucfirst($report['fire_hazard_level'] ?? 'medium')); ?>
                                                </div>
                                            </div>
                                        </td>
                                        <td>
                                            <div class="compliance-badge <?php echo $compliance_class; ?>" style="margin-bottom: 8px;">
                                                <?php echo $compliance_rating; ?>%
                                            </div>
                                            <div class="risk-level <?php echo $risk_class; ?>">
                                                <?php echo htmlspecialchars(ucfirst($report['risk_assessment'] ?? 'medium')); ?>
                                            </div>
                                        </td>
                                        <td>
                                            <div class="status-badge <?php echo $status_class; ?>">
                                                <?php 
                                                    $status_text = str_replace('_', ' ', $report['status']);
                                                    echo ucwords($status_text);
                                                ?>
                                            </div>
                                            <?php if ($report['status'] === 'revision_requested' && !empty($report['admin_review_notes'])): ?>
                                                <div style="font-size: 11px; color: var(--warning); margin-top: 5px;">
                                                    <i class='bx bx-message-detail'></i> Revision requested
                                                </div>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div class="action-buttons">
                                                <button type="button" class="btn btn-info btn-sm review-report-btn"
                                                        data-report-id="<?php echo $report['id']; ?>"
                                                        data-report-number="<?php echo htmlspecialchars($report['report_number']); ?>">
                                                    <i class='bx bx-show'></i>
                                                    Review
                                                </button>
                                                <button type="button" class="btn btn-primary btn-sm edit-report-btn"
                                                        data-report-id="<?php echo $report['id']; ?>"
                                                        data-report-number="<?php echo htmlspecialchars($report['report_number']); ?>">
                                                    <i class='bx bx-edit'></i>
                                                    Edit
                                                </button>
                                                <?php if ($report['status'] === 'draft'): ?>
                                                <button type="button" class="btn btn-success btn-sm submit-report-btn"
                                                        data-report-id="<?php echo $report['id']; ?>"
                                                        data-report-number="<?php echo htmlspecialchars($report['report_number']); ?>">
                                                    <i class='bx bx-send'></i>
                                                    Submit
                                                </button>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php else: ?>
                            <div class="empty-state">
                                <i class='bx bx-file'></i>
                                <h3>No Reports Found</h3>
                                <p>No inspection reports match your search criteria. Try adjusting your filters or create new inspections.</p>
                                <a href="conduct_inspections.php" class="btn btn-primary" style="margin-top: 20px;">
                                    <i class='bx bx-plus'></i>
                                    Conduct New Inspection
                                </a>
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
            
            // Review buttons
            document.querySelectorAll('.review-report-btn').forEach(btn => {
                btn.addEventListener('click', function() {
                    const reportId = this.getAttribute('data-report-id');
                    const reportNumber = this.getAttribute('data-report-number');
                    showReportReview(reportId, reportNumber);
                });
            });
            
            // Edit buttons
            document.querySelectorAll('.edit-report-btn').forEach(btn => {
                btn.addEventListener('click', function() {
                    const reportId = this.getAttribute('data-report-id');
                    const reportNumber = this.getAttribute('data-report-number');
                    showEditForm(reportId, reportNumber);
                });
            });
            
            // Submit buttons
            document.querySelectorAll('.submit-report-btn').forEach(btn => {
                btn.addEventListener('click', function() {
                    const reportId = this.getAttribute('data-report-id');
                    const reportNumber = this.getAttribute('data-report-number');
                    submitReport(reportId, reportNumber);
                });
            });
            
            // Modal close buttons
            document.getElementById('closeReviewModal').addEventListener('click', function() {
                document.getElementById('reviewModal').classList.remove('show');
            });
            
            document.getElementById('cancelReviewBtn').addEventListener('click', function() {
                document.getElementById('reviewModal').classList.remove('show');
            });
            
            document.getElementById('closeEditModal').addEventListener('click', function() {
                document.getElementById('editModal').classList.remove('show');
            });
            
            document.getElementById('cancelEditBtn').addEventListener('click', function() {
                document.getElementById('editModal').classList.remove('show');
            });
            
            // Submit report button
            document.getElementById('submitReportBtn').addEventListener('click', function() {
                const reportId = this.getAttribute('data-report-id');
                const reportNumber = document.querySelector('#reviewModal .report-number')?.textContent || 'Report';
                if (reportId) {
                    submitReport(reportId, reportNumber);
                }
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
        
        async function showReportReview(reportId, reportNumber) {
            try {
                const response = await fetch(`submit_findings.php?action=get_report_details&report_id=${reportId}`);
                const report = await response.json();
                
                if (report.error) {
                    throw new Error(report.error);
                }
                
                // Format dates
                const inspectionDate = report.inspection_date ? 
                    new Date(report.inspection_date).toLocaleDateString('en-US', { 
                        year: 'numeric', 
                        month: 'short', 
                        day: 'numeric' 
                    }) : 'N/A';
                
                const createdDate = new Date(report.created_at).toLocaleDateString('en-US', { 
                    year: 'numeric', 
                    month: 'short', 
                    day: 'numeric' 
                });
                
                // Determine compliance class
                const rating = report.overall_compliance_score || 0;
                let complianceClass = 'compliance-badge';
                if (rating >= 85) complianceClass += ' compliance-high';
                else if (rating >= 70) complianceClass += ' compliance-medium';
                else complianceClass += ' compliance-low';
                
                // Determine risk class
                const riskClass = 'risk-' + (report.risk_assessment || 'medium').toLowerCase();
                
                // Calculate checklist statistics
                let compliantCount = 0;
                let nonCompliantCount = 0;
                let notApplicableCount = 0;
                let partialCount = 0;
                
                if (report.checklist_responses) {
                    report.checklist_responses.forEach(response => {
                        switch(response.compliance_status) {
                            case 'compliant':
                                compliantCount++;
                                break;
                            case 'non_compliant':
                                nonCompliantCount++;
                                break;
                            case 'not_applicable':
                                notApplicableCount++;
                                break;
                            case 'partial':
                                partialCount++;
                                break;
                        }
                    });
                }
                
                const totalResponses = report.checklist_responses ? report.checklist_responses.length : 0;
                
                // Build the review HTML
                let checklistHTML = '';
                if (report.checklist_responses && report.checklist_responses.length > 0) {
                    // Group by category
                    const byCategory = {};
                    report.checklist_responses.forEach(response => {
                        if (!byCategory[response.category]) {
                            byCategory[response.category] = [];
                        }
                        byCategory[response.category].push(response);
                    });
                    
                    Object.keys(byCategory).forEach(category => {
                        checklistHTML += `
                            <div class="checklist-category">
                                <h4 class="category-title">${category}</h4>
                        `;
                        
                        byCategory[category].forEach(response => {
                            let statusClass = '';
                            let statusIcon = '';
                            switch(response.compliance_status) {
                                case 'compliant':
                                    statusClass = 'status-success';
                                    statusIcon = 'bx-check-circle';
                                    break;
                                case 'non_compliant':
                                    statusClass = 'status-danger';
                                    statusIcon = 'bx-x-circle';
                                    break;
                                case 'partial':
                                    statusClass = 'status-warning';
                                    statusIcon = 'bx-time-five';
                                    break;
                                case 'not_applicable':
                                    statusClass = 'status-info';
                                    statusIcon = 'bx-minus-circle';
                                    break;
                            }
                            
                            checklistHTML += `
                                <div class="checklist-item">
                                    <div class="checklist-item-content">
                                        <div class="checklist-item-code">${response.item_code}</div>
                                        <div class="checklist-item-desc">${response.item_description}</div>
                                        ${response.compliance_standard ? `<div class="checklist-item-standard">Standard: ${response.compliance_standard}</div>` : ''}
                                        <div class="checklist-item-standard">Score: ${response.score}%</div>
                                        ${response.notes ? `<div class="checklist-item-notes" style="margin-top: 5px; font-size: 12px; color: var(--text-light);"><strong>Notes:</strong> ${response.notes}</div>` : ''}
                                    </div>
                                    <div class="checklist-response">
                                        <span class="status-badge ${statusClass}" style="min-width: 120px;">
                                            <i class='bx ${statusIcon}'></i>
                                            ${response.compliance_status.replace('_', ' ').toUpperCase()}
                                        </span>
                                    </div>
                                </div>
                            `;
                        });
                        
                        checklistHTML += `</div>`;
                    });
                }
                
                let violationsHTML = '';
                if (report.violations && report.violations.length > 0) {
                    violationsHTML = `
                        <div class="violations-container">
                            ${report.violations.map(violation => `
                                <div class="violation-item">
                                    <div class="violation-fields">
                                        <div>
                                            <div class="detail-label">Violation Code</div>
                                            <div class="detail-value">${violation.violation_code}</div>
                                        </div>
                                        <div>
                                            <div class="detail-label">Description</div>
                                            <div class="detail-value">${violation.violation_description}</div>
                                        </div>
                                        <div>
                                            <div class="detail-label">Severity</div>
                                            <div class="detail-value">
                                                <span class="risk-level risk-${violation.severity}">
                                                    ${violation.severity}
                                                </span>
                                            </div>
                                        </div>
                                        ${violation.section_violated ? `
                                        <div>
                                            <div class="detail-label">Section Violated</div>
                                            <div class="detail-value">${violation.section_violated}</div>
                                        </div>` : ''}
                                        ${violation.fine_amount ? `
                                        <div>
                                            <div class="detail-label">Fine Amount</div>
                                            <div class="detail-value">₱${parseFloat(violation.fine_amount).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}</div>
                                        </div>` : ''}
                                        ${violation.compliance_deadline ? `
                                        <div>
                                            <div class="detail-label">Compliance Deadline</div>
                                            <div class="detail-value">${new Date(violation.compliance_deadline).toLocaleDateString('en-US', { year: 'numeric', month: 'short', day: 'numeric' })}</div>
                                        </div>` : ''}
                                    </div>
                                </div>
                            `).join('')}
                        </div>
                    `;
                }
                
                const reviewHTML = `
                    <input type="hidden" id="review-report-id" value="${reportId}">
                    
                    <div class="report-details-section">
                        <h3 class="section-title">
                            <i class='bx bx-info-circle'></i>
                            Report Information
                        </h3>
                        
                        <div class="summary-stats">
                            <div class="summary-stat">
                                <div class="summary-stat-label">Compliance Score</div>
                                <div class="summary-stat-value">
                                    <span class="${complianceClass}">${rating}%</span>
                                </div>
                            </div>
                            <div class="summary-stat">
                                <div class="summary-stat-label">Risk Assessment</div>
                                <div class="summary-stat-value">
                                    <span class="risk-level ${riskClass}">${(report.risk_assessment || 'medium').charAt(0).toUpperCase() + (report.risk_assessment || 'medium').slice(1)}</span>
                                </div>
                            </div>
                            <div class="summary-stat">
                                <div class="summary-stat-label">Hazard Level</div>
                                <div class="summary-stat-value">${(report.fire_hazard_level || 'medium').charAt(0).toUpperCase() + (report.fire_hazard_level || 'medium').slice(1)}</div>
                            </div>
                            <div class="summary-stat">
                                <div class="summary-stat-label">Checklist Items</div>
                                <div class="summary-stat-value">${totalResponses}</div>
                            </div>
                        </div>
                        
                        <div class="details-grid">
                            <div class="detail-box">
                                <div class="detail-label">Report Number</div>
                                <div class="detail-value">${report.report_number}</div>
                            </div>
                            <div class="detail-box">
                                <div class="detail-label">Inspection Date</div>
                                <div class="detail-value">${inspectionDate}</div>
                            </div>
                            <div class="detail-box">
                                <div class="detail-label">Inspection Type</div>
                                <div class="detail-value">${(report.inspection_type || 'routine').replace('_', ' ')}</div>
                            </div>
                            <div class="detail-box">
                                <div class="detail-label">Report Status</div>
                                <div class="detail-value">
                                    <span class="status-badge status-${report.status.replace('_', '-')}">
                                        ${report.status.replace('_', ' ')}
                                    </span>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="report-details-section">
                        <h3 class="section-title">
                            <i class='bx bx-building'></i>
                            Establishment Details
                        </h3>
                        
                        <div class="details-grid">
                            <div class="detail-box">
                                <div class="detail-label">Establishment Name</div>
                                <div class="detail-value">${report.establishment_name}</div>
                            </div>
                            <div class="detail-box">
                                <div class="detail-label">Establishment Type</div>
                                <div class="detail-value">${report.establishment_type}</div>
                            </div>
                            <div class="detail-box">
                                <div class="detail-label">Owner Name</div>
                                <div class="detail-value">${report.owner_name}</div>
                            </div>
                            <div class="detail-box">
                                <div class="detail-label">Address</div>
                                <div class="detail-value">${report.address}</div>
                            </div>
                            <div class="detail-box">
                                <div class="detail-label">Barangay</div>
                                <div class="detail-value">${report.barangay}</div>
                            </div>
                            <div class="detail-box">
                                <div class="detail-label">Business Permit</div>
                                <div class="detail-value">${report.business_permit_number || 'N/A'}</div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="report-details-section">
                        <h3 class="section-title">
                            <i class='bx bx-check-square'></i>
                            Checklist Summary
                        </h3>
                        
                        <div class="summary-stats">
                            <div class="summary-stat">
                                <div class="summary-stat-label">Compliant</div>
                                <div class="summary-stat-value" style="color: var(--success);">${compliantCount}</div>
                            </div>
                            <div class="summary-stat">
                                <div class="summary-stat-label">Non-Compliant</div>
                                <div class="summary-stat-value" style="color: var(--danger);">${nonCompliantCount}</div>
                            </div>
                            <div class="summary-stat">
                                <div class="summary-stat-label">Partial</div>
                                <div class="summary-stat-value" style="color: var(--warning);">${partialCount}</div>
                            </div>
                            <div class="summary-stat">
                                <div class="summary-stat-label">Not Applicable</div>
                                <div class="summary-stat-value" style="color: var(--info);">${notApplicableCount}</div>
                            </div>
                        </div>
                        
                        <div class="checklist-container">
                            ${checklistHTML || '<p style="text-align: center; color: var(--text-light); padding: 20px;">No checklist data available.</p>'}
                        </div>
                    </div>
                    
                    ${report.violations && report.violations.length > 0 ? `
                    <div class="report-details-section">
                        <h3 class="section-title">
                            <i class='bx bx-error'></i>
                            Violations (${report.violations.length})
                        </h3>
                        ${violationsHTML}
                    </div>` : ''}
                    
                    ${report.recommendations || report.corrective_actions_required ? `
                    <div class="report-details-section">
                        <h3 class="section-title">
                            <i class='bx bx-message-detail'></i>
                            Recommendations & Actions
                        </h3>
                        
                        ${report.recommendations ? `
                        <div style="margin-bottom: 20px;">
                            <div class="detail-label">Recommendations</div>
                            <div class="detail-value" style="white-space: pre-wrap;">${report.recommendations}</div>
                        </div>` : ''}
                        
                        ${report.corrective_actions_required ? `
                        <div style="margin-bottom: 20px;">
                            <div class="detail-label">Corrective Actions Required</div>
                            <div class="detail-value" style="white-space: pre-wrap;">${report.corrective_actions_required}</div>
                        </div>` : ''}
                        
                        ${report.compliance_deadline ? `
                        <div>
                            <div class="detail-label">Compliance Deadline</div>
                            <div class="detail-value">${new Date(report.compliance_deadline).toLocaleDateString('en-US', { year: 'numeric', month: 'short', day: 'numeric' })}</div>
                        </div>` : ''}
                    </div>` : ''}
                    
                    ${report.admin_review_notes ? `
                    <div class="report-details-section">
                        <h3 class="section-title">
                            <i class='bx bx-message-dots'></i>
                            Revision Request Notes
                        </h3>
                        
                        <div class="detail-box" style="background: rgba(245, 158, 11, 0.1);">
                            <div class="detail-label">Admin Review Notes</div>
                            <div class="detail-value" style="white-space: pre-wrap;">${report.admin_review_notes}</div>
                        </div>
                    </div>` : ''}
                `;
                
                // Update modal content
                document.getElementById('reviewModalBody').innerHTML = reviewHTML;
                
                // Set report ID on submit button
                document.getElementById('submitReportBtn').setAttribute('data-report-id', reportId);
                
                // Show modal
                document.getElementById('reviewModal').classList.add('show');
            } catch (error) {
                console.error('Error loading report details:', error);
                alert('Failed to load report details. Please try again.');
            }
        }
        
        async function showEditForm(reportId, reportNumber) {
            try {
                const response = await fetch(`submit_findings.php?action=get_report_details&report_id=${reportId}`);
                const report = await response.json();
                
                if (report.error) {
                    throw new Error(report.error);
                }
                
                // Get checklist items
                const checklistResponse = await fetch('submit_findings.php?action=get_checklist_items');
                const checklistItems = await checklistResponse.json();
                
                // Organize checklist items by category
                const itemsByCategory = {};
                checklistItems.forEach(item => {
                    if (!itemsByCategory[item.category]) {
                        itemsByCategory[item.category] = [];
                    }
                    itemsByCategory[item.category].push(item);
                });
                
                // Create lookup for existing responses
                const existingResponses = {};
                if (report.checklist_responses) {
                    report.checklist_responses.forEach(response => {
                        existingResponses[response.checklist_item_id] = response;
                    });
                }
                
                // Build checklist HTML
                let checklistHTML = '';
                Object.keys(itemsByCategory).forEach(category => {
                    checklistHTML += `
                        <div class="checklist-category">
                            <h4 class="category-title">${category}</h4>
                    `;
                    
                    itemsByCategory[category].forEach(item => {
                        const existing = existingResponses[item.id];
                        const status = existing ? existing.compliance_status : '';
                        const score = existing ? existing.score : 0;
                        const notes = existing ? existing.notes : '';
                        
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
                                        <option value="compliant" ${status === 'compliant' ? 'selected' : ''}>Compliant</option>
                                        <option value="non_compliant" ${status === 'non_compliant' ? 'selected' : ''}>Non-Compliant</option>
                                        <option value="not_applicable" ${status === 'not_applicable' ? 'selected' : ''}>Not Applicable</option>
                                        <option value="partial" ${status === 'partial' ? 'selected' : ''}>Partial</option>
                                    </select>
                                    
                                    <input type="number" class="form-input score-input" 
                                           name="checklist_response[${item.id}][score]" 
                                           min="0" max="100" value="${score}" placeholder="Score">
                                    
                                    <input type="text" class="form-input notes-input" 
                                           name="checklist_response[${item.id}][notes]" 
                                           value="${notes || ''}" placeholder="Notes (optional)">
                                </div>
                            </div>
                        `;
                    });
                    
                    checklistHTML += `</div>`;
                });
                
                // Build violations HTML
                let violationsHTML = '';
                if (report.violations && report.violations.length > 0) {
                    report.violations.forEach((violation, index) => {
                        const fineValue = violation.fine_amount ? parseFloat(violation.fine_amount) : '';
                        const deadlineValue = violation.compliance_deadline ? violation.compliance_deadline.split(' ')[0] : '';
                        
                        violationsHTML += `
                            <div class="violation-item">
                                <div class="violation-fields">
                                    <div>
                                        <label class="form-label">Violation Code</label>
                                        <input type="text" class="form-input" name="violations[${index}][code]" 
                                               value="${violation.violation_code}" placeholder="e.g., FS-001" required>
                                    </div>
                                    <div>
                                        <label class="form-label">Description</label>
                                        <input type="text" class="form-input" name="violations[${index}][description]" 
                                               value="${violation.violation_description}" placeholder="Description of violation" required>
                                    </div>
                                    <div>
                                        <label class="form-label">Severity</label>
                                        <select class="form-select" name="violations[${index}][severity]" required>
                                            <option value="minor" ${violation.severity === 'minor' ? 'selected' : ''}>Minor</option>
                                            <option value="major" ${violation.severity === 'major' ? 'selected' : ''}>Major</option>
                                            <option value="critical" ${violation.severity === 'critical' ? 'selected' : ''}>Critical</option>
                                        </select>
                                    </div>
                                    <div>
                                        <label class="form-label">Section Violated</label>
                                        <input type="text" class="form-input" name="violations[${index}][section]" 
                                               value="${violation.section_violated || ''}" placeholder="e.g., NFPA 101">
                                    </div>
                                    <div>
                                        <label class="form-label">Fine Amount (₱)</label>
                                        <input type="number" class="form-input" name="violations[${index}][fine]" 
                                               min="0" step="0.01" value="${fineValue}" placeholder="0.00">
                                    </div>
                                    <div>
                                        <label class="form-label">Compliance Deadline</label>
                                        <input type="date" class="form-input" name="violations[${index}][deadline]" 
                                               value="${deadlineValue}">
                                    </div>
                                </div>
                                <button type="button" class="remove-btn remove-violation-btn">
                                    <i class='bx bx-trash'></i>
                                </button>
                            </div>
                        `;
                    });
                }
                
                // Build the edit form HTML
                const complianceDeadlineValue = report.compliance_deadline ? report.compliance_deadline.split(' ')[0] : '';
                
                const editHTML = `
                    <input type="hidden" name="report_id" value="${reportId}">
                    
                    <div class="form-section">
                        <h3 class="section-title">
                            <i class='bx bx-info-circle'></i>
                            Report Summary
                        </h3>
                        
                        <div class="form-row">
                            <div class="form-group">
                                <label class="form-label required" for="overall_compliance_score">Overall Compliance Score (%)</label>
                                <input type="number" class="form-input" id="overall_compliance_score" 
                                       name="overall_compliance_score" min="0" max="100" 
                                       value="${report.overall_compliance_score || 0}" required>
                            </div>
                            
                            <div class="form-group">
                                <label class="form-label required" for="risk_assessment">Risk Assessment</label>
                                <select class="form-select" id="risk_assessment" name="risk_assessment" required>
                                    <option value="low" ${report.risk_assessment === 'low' ? 'selected' : ''}>Low</option>
                                    <option value="medium" ${report.risk_assessment === 'medium' || !report.risk_assessment ? 'selected' : ''}>Medium</option>
                                    <option value="high" ${report.risk_assessment === 'high' ? 'selected' : ''}>High</option>
                                    <option value="critical" ${report.risk_assessment === 'critical' ? 'selected' : ''}>Critical</option>
                                </select>
                            </div>
                            
                            <div class="form-group">
                                <label class="form-label required" for="fire_hazard_level">Fire Hazard Level</label>
                                <select class="form-select" id="fire_hazard_level" name="fire_hazard_level" required>
                                    <option value="low" ${report.fire_hazard_level === 'low' ? 'selected' : ''}>Low</option>
                                    <option value="medium" ${report.fire_hazard_level === 'medium' || !report.fire_hazard_level ? 'selected' : ''}>Medium</option>
                                    <option value="high" ${report.fire_hazard_level === 'high' ? 'selected' : ''}>High</option>
                                    <option value="extreme" ${report.fire_hazard_level === 'extreme' ? 'selected' : ''}>Extreme</option>
                                </select>
                            </div>
                        </div>
                    </div>
                    
                    <div class="form-section">
                        <h3 class="section-title">
                            <i class='bx bx-check-square'></i>
                            Fire Safety Checklist
                        </h3>
                        
                        <div class="checklist-container">
                            ${checklistHTML}
                        </div>
                    </div>
                    
                    <div class="form-section">
                        <h3 class="section-title">
                            <i class='bx bx-error'></i>
                            Violations (if any)
                        </h3>
                        
                        <div class="violations-container" id="violations-container">
                            ${violationsHTML}
                            
                            <div class="violation-item" id="violation-template" style="display: none;">
                                <div class="violation-fields">
                                    <div>
                                        <label class="form-label">Violation Code</label>
                                        <input type="text" class="form-input" name="violations[0][code]" placeholder="e.g., FS-001" required>
                                    </div>
                                    <div>
                                        <label class="form-label">Description</label>
                                        <input type="text" class="form-input" name="violations[0][description]" placeholder="Description of violation" required>
                                    </div>
                                    <div>
                                        <label class="form-label">Severity</label>
                                        <select class="form-select" name="violations[0][severity]" required>
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
                                          rows="4">${report.recommendations || ''}</textarea>
                            </div>
                        </div>
                        
                        <div class="form-row">
                            <div class="form-group">
                                <label class="form-label" for="corrective_actions">Corrective Actions Required</label>
                                <textarea class="form-textarea" id="corrective_actions" name="corrective_actions" 
                                          placeholder="Specific corrective actions required..." 
                                          rows="4">${report.corrective_actions_required || ''}</textarea>
                            </div>
                        </div>
                        
                        <div class="form-row">
                            <div class="form-group">
                                <label class="form-label" for="compliance_deadline">Compliance Deadline</label>
                                <input type="date" class="form-input" id="compliance_deadline" 
                                       name="compliance_deadline" value="${complianceDeadlineValue}">
                            </div>
                        </div>
                    </div>
                    
                    ${report.admin_review_notes ? `
                    <div class="form-section">
                        <h3 class="section-title">
                            <i class='bx bx-message-dots'></i>
                            Revision Request Notes
                        </h3>
                        
                        <div class="form-row">
                            <div class="form-group">
                                <div class="detail-box" style="background: rgba(245, 158, 11, 0.1); padding: 15px; border-radius: 8px;">
                                    <div class="detail-label">Admin Review Notes</div>
                                    <div class="detail-value" style="white-space: pre-wrap;">${report.admin_review_notes}</div>
                                </div>
                            </div>
                        </div>
                    </div>` : ''}
                `;
                
                // Update modal content
                document.getElementById('editModalBody').innerHTML = editHTML;
                
                // Initialize checklist scoring
                initChecklistScoring();
                
                // Initialize violation removal
                document.querySelectorAll('.remove-violation-btn').forEach(btn => {
                    btn.addEventListener('click', function() {
                        this.closest('.violation-item').remove();
                    });
                });
                
                // Show modal
                document.getElementById('editModal').classList.add('show');
            } catch (error) {
                console.error('Error loading edit form:', error);
                alert('Failed to load edit form. Please try again.');
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
        
        function addViolation() {
            const container = document.getElementById('violations-container');
            const template = document.getElementById('violation-template');
            const addButton = container.querySelector('.add-violation-btn');
            
            // Count existing violations
            const existingViolations = container.querySelectorAll('.violation-item:not(#violation-template)');
            const newIndex = existingViolations.length;
            
            const newViolation = template.cloneNode(true);
            newViolation.id = '';
            newViolation.style.display = 'flex';
            
            // Update all input names with new index
            const inputs = newViolation.querySelectorAll('input, select');
            inputs.forEach(input => {
                const name = input.getAttribute('name');
                if (name) {
                    input.setAttribute('name', name.replace('[0]', '[' + newIndex + ']'));
                }
            });
            
            // Add remove functionality
            const removeBtn = newViolation.querySelector('.remove-violation-btn');
            removeBtn.addEventListener('click', function() {
                this.closest('.violation-item').remove();
            });
            
            // Insert before the add button
            container.insertBefore(newViolation, addButton);
        }
        
        function submitReport(reportId, reportNumber = '') {
            if (confirm(`Are you sure you want to submit report ${reportNumber} for review? Once submitted, you cannot make further changes until it's reviewed by an administrator.`)) {
                const form = document.createElement('form');
                form.method = 'POST';
                form.style.display = 'none';
                
                const input = document.createElement('input');
                input.type = 'hidden';
                input.name = 'submit_report';
                input.value = '1';
                form.appendChild(input);
                
                const reportIdInput = document.createElement('input');
                reportIdInput.type = 'hidden';
                reportIdInput.name = 'report_id';
                reportIdInput.value = reportId;
                form.appendChild(reportIdInput);
                
                document.body.appendChild(form);
                form.submit();
            }
        }
        
        function clearFilters() {
            window.location.href = 'submit_findings.php';
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