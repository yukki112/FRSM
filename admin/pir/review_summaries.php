<?php
session_start();
require_once '../../config/db_connection.php';
require_once '../../vendor/setasign/fpdf/fpdf.php';

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
$filter_type = isset($_GET['type']) ? $_GET['type'] : 'all';
$filter_status = isset($_GET['status']) ? $_GET['status'] : 'all';
$filter_date_from = isset($_GET['date_from']) ? $_GET['date_from'] : '';
$filter_date_to = isset($_GET['date_to']) ? $_GET['date_to'] : '';
$filter_barangay = isset($_GET['barangay']) ? $_GET['barangay'] : '';
$search_query = isset($_GET['search']) ? $_GET['search'] : '';

// Handle report generation
if (isset($_GET['export']) && $_GET['export'] == 'pdf') {
    generatePDFReport($pdo, $filter_type, $filter_status, $filter_date_from, $filter_date_to, $filter_barangay, $search_query);
    exit();
}

// Handle AI prediction request
if (isset($_GET['ai_predict']) && $_GET['ai_predict'] == 'true') {
    $predictions = generatePredictions($pdo);
    echo json_encode($predictions);
    exit();
}

// Get statistics data
function getStatistics($pdo, $filter_type = 'all', $filter_status = 'all', $filter_date_from = '', $filter_date_to = '', $filter_barangay = '', $search_query = '') {
    $stats = [];
    
    // Incident Statistics
    $sql = "SELECT 
                COUNT(*) as total_incidents,
                SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending_incidents,
                SUM(CASE WHEN status = 'processing' THEN 1 ELSE 0 END) as processing_incidents,
                SUM(CASE WHEN status = 'responded' THEN 1 ELSE 0 END) as responded_incidents,
                SUM(CASE WHEN status = 'closed' THEN 1 ELSE 0 END) as closed_incidents,
                AVG(CASE WHEN severity = 'critical' THEN 1 ELSE 0 END) * 100 as critical_percentage,
                AVG(CASE WHEN severity = 'high' THEN 1 ELSE 0 END) * 100 as high_percentage,
                AVG(CASE WHEN severity = 'medium' THEN 1 ELSE 0 END) * 100 as medium_percentage,
                AVG(CASE WHEN severity = 'low' THEN 1 ELSE 0 END) * 100 as low_percentage
            FROM api_incidents WHERE 1=1";
    
    $params = [];
    
    if (!empty($filter_date_from)) {
        $sql .= " AND DATE(created_at) >= ?";
        $params[] = $filter_date_from;
    }
    
    if (!empty($filter_date_to)) {
        $sql .= " AND DATE(created_at) <= ?";
        $params[] = $filter_date_to;
    }
    
    if (!empty($filter_barangay)) {
        $sql .= " AND affected_barangays LIKE ?";
        $params[] = "%$filter_barangay%";
    }
    
    if (!empty($search_query)) {
        $sql .= " AND (title LIKE ? OR description LIKE ? OR location LIKE ?)";
        $search_param = "%$search_query%";
        $params[] = $search_param;
        $params[] = $search_param;
        $params[] = $search_param;
    }
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $stats['incidents'] = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Volunteer Statistics
    $sql = "SELECT 
                COUNT(*) as total_volunteers,
                SUM(CASE WHEN status = 'approved' THEN 1 ELSE 0 END) as active_volunteers,
                SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending_volunteers,
                SUM(CASE WHEN status = 'rejected' THEN 1 ELSE 0 END) as rejected_volunteers,
                AVG(CASE WHEN volunteer_status = 'Active' THEN 1 ELSE 0 END) * 100 as active_percentage
            FROM volunteers WHERE 1=1";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute();
    $stats['volunteers'] = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Training Statistics
    $sql = "SELECT 
                COUNT(*) as total_trainings,
                SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed_trainings,
                SUM(CASE WHEN status = 'ongoing' THEN 1 ELSE 0 END) as ongoing_trainings,
                SUM(CASE WHEN status = 'scheduled' THEN 1 ELSE 0 END) as scheduled_trainings,
                AVG(current_participants) as avg_participants
            FROM trainings WHERE 1=1";
    
    if (!empty($filter_date_from)) {
        $sql .= " AND DATE(training_date) >= ?";
        $params2[] = $filter_date_from;
    }
    
    if (!empty($filter_date_to)) {
        $sql .= " AND DATE(training_date) <= ?";
        $params2[] = $filter_date_to;
    }
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params2 ?? []);
    $stats['trainings'] = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Inspection Statistics
    $sql = "SELECT 
                COUNT(*) as total_inspections,
                SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed_inspections,
                SUM(CASE WHEN status = 'draft' THEN 1 ELSE 0 END) as draft_inspections,
                SUM(CASE WHEN status = 'submitted' THEN 1 ELSE 0 END) as submitted_inspections,
                AVG(overall_compliance_score) as avg_compliance_score,
                SUM(CASE WHEN risk_assessment = 'high' THEN 1 ELSE 0 END) as high_risk_inspections,
                SUM(CASE WHEN risk_assessment = 'critical' THEN 1 ELSE 0 END) as critical_risk_inspections
            FROM inspection_reports WHERE 1=1";
    
    if (!empty($filter_date_from)) {
        $sql .= " AND DATE(inspection_date) >= ?";
        $params3[] = $filter_date_from;
    }
    
    if (!empty($filter_date_to)) {
        $sql .= " AND DATE(inspection_date) <= ?";
        $params3[] = $filter_date_to;
    }
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params3 ?? []);
    $stats['inspections'] = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Resource Statistics
    $sql = "SELECT 
                COUNT(*) as total_resources,
                SUM(CASE WHEN condition_status = 'Serviceable' THEN 1 ELSE 0 END) as serviceable_resources,
                SUM(CASE WHEN condition_status = 'Under Maintenance' THEN 1 ELSE 0 END) as maintenance_resources,
                SUM(CASE WHEN condition_status = 'Condemned' THEN 1 ELSE 0 END) as condemned_resources,
                SUM(available_quantity) as total_available_quantity
            FROM resources WHERE is_active = 1";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute();
    $stats['resources'] = $stmt->fetch(PDO::FETCH_ASSOC);
    
    return $stats;
}

// Generate AI predictions
function generatePredictions($pdo) {
    $predictions = [];
    
    // Predict incident trends based on historical data
    $sql = "SELECT 
                emergency_type,
                COUNT(*) as count,
                MONTH(created_at) as month,
                AVG(CASE WHEN severity = 'critical' THEN 1 ELSE 0 END) as critical_rate
            FROM api_incidents 
            WHERE created_at >= DATE_SUB(NOW(), INTERVAL 6 MONTH)
            GROUP BY emergency_type, MONTH(created_at)
            ORDER BY emergency_type, month";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute();
    $incident_data = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Analyze trends
    $trends = [];
    foreach ($incident_data as $data) {
        $type = $data['emergency_type'];
        if (!isset($trends[$type])) {
            $trends[$type] = [];
        }
        $trends[$type][] = $data['count'];
    }
    
    // Make predictions for next month
    foreach ($trends as $type => $counts) {
        if (count($counts) >= 3) {
            // Simple linear regression for prediction
            $last_three = array_slice($counts, -3);
            $trend = ($last_three[2] - $last_three[0]) / 2;
            $prediction = end($last_three) + $trend;
            
            $predictions['incident_predictions'][] = [
                'type' => $type,
                'predicted_count' => max(0, round($prediction)),
                'confidence' => 75,
                'trend' => $trend > 0 ? 'increasing' : ($trend < 0 ? 'decreasing' : 'stable')
            ];
        }
    }
    
    // Predict resource needs
    $sql = "SELECT 
                resource_type,
                AVG(quantity - available_quantity) as avg_usage,
                MONTH(created_at) as month
            FROM resources 
            WHERE created_at >= DATE_SUB(NOW(), INTERVAL 6 MONTH)
            GROUP BY resource_type, MONTH(created_at)";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute();
    $resource_data = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($resource_data as $data) {
        $predictions['resource_predictions'][] = [
            'resource_type' => $data['resource_type'],
            'predicted_need' => round($data['avg_usage'] * 1.1), // 10% buffer
            'confidence' => 70
        ];
    }
    
    // Predict training needs based on incident types
    $sql = "SELECT 
                emergency_type,
                COUNT(*) as frequency
            FROM api_incidents 
            WHERE created_at >= DATE_SUB(NOW(), INTERVAL 3 MONTH)
            GROUP BY emergency_type
            ORDER BY frequency DESC
            LIMIT 3";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute();
    $training_needs = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($training_needs as $need) {
        $predictions['training_predictions'][] = [
            'type' => $need['emergency_type'],
            'priority' => 'high',
            'recommended_training' => getTrainingForIncidentType($need['emergency_type'])
        ];
    }
    
    return $predictions;
}

function getTrainingForIncidentType($type) {
    $training_map = [
        'fire' => 'Fire Safety Basics, Advanced Firefighting',
        'medical' => 'Emergency Medical Response, First Aid/CPR',
        'rescue' => 'Advanced Rescue Techniques, Vehicle Extrication',
        'other' => 'Incident Command System'
    ];
    
    return $training_map[$type] ?? 'General Emergency Response';
}

// Generate PDF Report
function generatePDFReport($pdo, $filter_type, $filter_status, $date_from, $date_to, $barangay, $search) {
    $stats = getStatistics($pdo, $filter_type, $filter_status, $date_from, $date_to, $barangay, $search);
    $predictions = generatePredictions($pdo);
    
    $pdf = new FPDF();
    $pdf->AddPage();
    
    // Header
    $pdf->SetFont('Arial', 'B', 16);
    $pdf->Cell(0, 10, 'Fire & Rescue Management System - Analytics Report', 0, 1, 'C');
    $pdf->SetFont('Arial', '', 12);
    $pdf->Cell(0, 10, 'Generated on: ' . date('Y-m-d H:i:s'), 0, 1, 'C');
    $pdf->Ln(10);
    
    // Filter Information
    $pdf->SetFont('Arial', 'B', 12);
    $pdf->Cell(0, 10, 'Report Filters:', 0, 1);
    $pdf->SetFont('Arial', '', 10);
    $pdf->Cell(0, 6, 'Date Range: ' . ($date_from ? $date_from : 'All') . ' to ' . ($date_to ? $date_to : 'All'), 0, 1);
    $pdf->Cell(0, 6, 'Barangay: ' . ($barangay ? $barangay : 'All'), 0, 1);
    $pdf->Cell(0, 6, 'Search: ' . ($search ? $search : 'None'), 0, 1);
    $pdf->Ln(10);
    
    // Incident Statistics
    $pdf->SetFont('Arial', 'B', 14);
    $pdf->Cell(0, 10, 'Incident Statistics', 0, 1);
    $pdf->SetFont('Arial', '', 10);
    
    $incident_data = $stats['incidents'];
    $pdf->Cell(0, 6, 'Total Incidents: ' . $incident_data['total_incidents'], 0, 1);
    $pdf->Cell(0, 6, 'Pending: ' . $incident_data['pending_incidents'], 0, 1);
    $pdf->Cell(0, 6, 'Processing: ' . $incident_data['processing_incidents'], 0, 1);
    $pdf->Cell(0, 6, 'Responded: ' . $incident_data['responded_incidents'], 0, 1);
    $pdf->Cell(0, 6, 'Closed: ' . $incident_data['closed_incidents'], 0, 1);
    $pdf->Ln(5);
    
    // Severity Distribution
    $pdf->Cell(0, 6, 'Severity Distribution:', 0, 1);
    $pdf->Cell(0, 6, 'Critical: ' . number_format($incident_data['critical_percentage'], 1) . '%', 0, 1);
    $pdf->Cell(0, 6, 'High: ' . number_format($incident_data['high_percentage'], 1) . '%', 0, 1);
    $pdf->Cell(0, 6, 'Medium: ' . number_format($incident_data['medium_percentage'], 1) . '%', 0, 1);
    $pdf->Cell(0, 6, 'Low: ' . number_format($incident_data['low_percentage'], 1) . '%', 0, 1);
    $pdf->Ln(10);
    
    // Volunteer Statistics
    $pdf->SetFont('Arial', 'B', 14);
    $pdf->Cell(0, 10, 'Volunteer Statistics', 0, 1);
    $pdf->SetFont('Arial', '', 10);
    
    $volunteer_data = $stats['volunteers'];
    $pdf->Cell(0, 6, 'Total Volunteers: ' . $volunteer_data['total_volunteers'], 0, 1);
    $pdf->Cell(0, 6, 'Active: ' . $volunteer_data['active_volunteers'], 0, 1);
    $pdf->Cell(0, 6, 'Pending: ' . $volunteer_data['pending_volunteers'], 0, 1);
    $pdf->Cell(0, 6, 'Rejected: ' . $volunteer_data['rejected_volunteers'], 0, 1);
    $pdf->Cell(0, 6, 'Active Rate: ' . number_format($volunteer_data['active_percentage'], 1) . '%', 0, 1);
    $pdf->Ln(10);
    
    // Training Statistics
    $pdf->SetFont('Arial', 'B', 14);
    $pdf->Cell(0, 10, 'Training Statistics', 0, 1);
    $pdf->SetFont('Arial', '', 10);
    
    $training_data = $stats['trainings'];
    $pdf->Cell(0, 6, 'Total Trainings: ' . $training_data['total_trainings'], 0, 1);
    $pdf->Cell(0, 6, 'Completed: ' . $training_data['completed_trainings'], 0, 1);
    $pdf->Cell(0, 6, 'Ongoing: ' . $training_data['ongoing_trainings'], 0, 1);
    $pdf->Cell(0, 6, 'Scheduled: ' . $training_data['scheduled_trainings'], 0, 1);
    $pdf->Cell(0, 6, 'Average Participants: ' . number_format($training_data['avg_participants'], 1), 0, 1);
    $pdf->Ln(10);
    
    // Inspection Statistics
    $pdf->SetFont('Arial', 'B', 14);
    $pdf->Cell(0, 10, 'Inspection Statistics', 0, 1);
    $pdf->SetFont('Arial', '', 10);
    
    $inspection_data = $stats['inspections'];
    $pdf->Cell(0, 6, 'Total Inspections: ' . $inspection_data['total_inspections'], 0, 1);
    $pdf->Cell(0, 6, 'Completed: ' . $inspection_data['completed_inspections'], 0, 1);
    $pdf->Cell(0, 6, 'Average Compliance Score: ' . number_format($inspection_data['avg_compliance_score'], 1) . '%', 0, 1);
    $pdf->Cell(0, 6, 'High Risk Inspections: ' . $inspection_data['high_risk_inspections'], 0, 1);
    $pdf->Cell(0, 6, 'Critical Risk Inspections: ' . $inspection_data['critical_risk_inspections'], 0, 1);
    $pdf->Ln(10);
    
    // Resource Statistics
    $pdf->SetFont('Arial', 'B', 14);
    $pdf->Cell(0, 10, 'Resource Statistics', 0, 1);
    $pdf->SetFont('Arial', '', 10);
    
    $resource_data = $stats['resources'];
    $pdf->Cell(0, 6, 'Total Resources: ' . $resource_data['total_resources'], 0, 1);
    $pdf->Cell(0, 6, 'Serviceable: ' . $resource_data['serviceable_resources'], 0, 1);
    $pdf->Cell(0, 6, 'Under Maintenance: ' . $resource_data['maintenance_resources'], 0, 1);
    $pdf->Cell(0, 6, 'Condemned: ' . $resource_data['condemned_resources'], 0, 1);
    $pdf->Cell(0, 6, 'Total Available Quantity: ' . $resource_data['total_available_quantity'], 0, 1);
    $pdf->Ln(10);
    
    // AI Predictions Section
    $pdf->SetFont('Arial', 'B', 14);
    $pdf->Cell(0, 10, 'AI Predictive Analytics', 0, 1);
    $pdf->SetFont('Arial', '', 10);
    
    if (isset($predictions['incident_predictions'])) {
        $pdf->Cell(0, 6, 'Incident Predictions for Next Month:', 0, 1);
        foreach ($predictions['incident_predictions'] as $prediction) {
            $pdf->Cell(0, 6, '- ' . ucfirst($prediction['type']) . ': ' . $prediction['predicted_count'] . ' incidents (' . $prediction['trend'] . ' trend)', 0, 1);
        }
    }
    
    if (isset($predictions['resource_predictions'])) {
        $pdf->Ln(5);
        $pdf->Cell(0, 6, 'Resource Needs Prediction:', 0, 1);
        foreach ($predictions['resource_predictions'] as $prediction) {
            $pdf->Cell(0, 6, '- ' . $prediction['resource_type'] . ': ' . $prediction['predicted_need'] . ' units needed', 0, 1);
        }
    }
    
    if (isset($predictions['training_predictions'])) {
        $pdf->Ln(5);
        $pdf->Cell(0, 6, 'Recommended Training:', 0, 1);
        foreach ($predictions['training_predictions'] as $prediction) {
            $pdf->Cell(0, 6, '- ' . ucfirst($prediction['type']) . ' incidents: ' . $prediction['recommended_training'], 0, 1);
        }
    }
    
    // Footer
    $pdf->SetY(-15);
    $pdf->SetFont('Arial', 'I', 8);
    $pdf->Cell(0, 10, 'Page ' . $pdf->PageNo(), 0, 0, 'C');
    
    $pdf->Output('I', 'FRSM_Analytics_Report_' . date('Y-m-d') . '.pdf');
}

// Get barangays for filter
function getBarangays($pdo) {
    $sql = "SELECT DISTINCT affected_barangays FROM api_incidents WHERE affected_barangays IS NOT NULL AND affected_barangays != ''";
    $stmt = $pdo->prepare($sql);
    $stmt->execute();
    $barangays = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    $unique_barangays = [];
    foreach ($barangays as $barangay_list) {
        $list = explode(',', $barangay_list);
        foreach ($list as $barangay) {
            $barangay = trim($barangay);
            if ($barangay && !in_array($barangay, $unique_barangays)) {
                $unique_barangays[] = $barangay;
            }
        }
    }
    
    sort($unique_barangays);
    return $unique_barangays;
}

// Get statistics data
$barangays = getBarangays($pdo);
$stats = getStatistics($pdo, $filter_type, $filter_status, $filter_date_from, $filter_date_to, $filter_barangay, $search_query);
$predictions = generatePredictions($pdo);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Review Summaries - Analytics & Reports - Admin - FRSM</title>
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

        /* Stats Grid */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
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

        /* Tabs */
        .tabs-container {
            background: var(--card-bg);
            border-radius: 16px;
            overflow: hidden;
            margin-bottom: 30px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
        }

        .tabs-header {
            display: flex;
            background: rgba(220, 38, 38, 0.03);
            border-bottom: 1px solid var(--border-color);
        }

        .tab {
            padding: 20px 30px;
            font-weight: 600;
            color: var(--text-color);
            cursor: pointer;
            transition: all 0.3s ease;
            border-bottom: 3px solid transparent;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .tab.active {
            color: var(--primary-color);
            border-bottom-color: var(--primary-color);
            background: rgba(220, 38, 38, 0.05);
        }

        .tab:hover:not(.active) {
            background: rgba(220, 38, 38, 0.02);
        }

        .tab-content {
            padding: 30px;
            display: none;
        }

        .tab-content.active {
            display: block;
        }

        /* Filters */
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

        /* AI Predictions Section */
        .ai-predictions {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border-radius: 16px;
            padding: 30px;
            margin-bottom: 30px;
            color: white;
            position: relative;
            overflow: hidden;
        }

        .ai-predictions::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: url('data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100" preserveAspectRatio="none"><path d="M0,0 L100,0 L100,100 Z" fill="rgba(255,255,255,0.1)"/></svg>');
            background-size: cover;
        }

        .ai-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 20px;
            position: relative;
            z-index: 1;
        }

        .ai-title {
            font-size: 24px;
            font-weight: 700;
            margin: 0;
        }

        .ai-refresh {
            background: rgba(255, 255, 255, 0.2);
            border: none;
            color: white;
            padding: 10px 20px;
            border-radius: 10px;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 8px;
            transition: all 0.3s ease;
        }

        .ai-refresh:hover {
            background: rgba(255, 255, 255, 0.3);
        }

        .predictions-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 20px;
            position: relative;
            z-index: 1;
        }

        .prediction-card {
            background: rgba(255, 255, 255, 0.1);
            backdrop-filter: blur(10px);
            border-radius: 12px;
            padding: 20px;
            border: 1px solid rgba(255, 255, 255, 0.2);
        }

        .prediction-title {
            font-size: 16px;
            font-weight: 600;
            margin-bottom: 10px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .prediction-content {
            font-size: 14px;
            opacity: 0.9;
        }

        .prediction-item {
            margin-bottom: 8px;
            padding-left: 20px;
            position: relative;
        }

        .prediction-item::before {
            content: '•';
            position: absolute;
            left: 0;
            color: rgba(255, 255, 255, 0.8);
        }

        /* Charts */
        .charts-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(400px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .chart-container {
            background: var(--card-bg);
            border-radius: 16px;
            padding: 24px;
            border: 1px solid var(--border-color);
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
        }

        .chart-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 20px;
        }

        .chart-title {
            font-size: 18px;
            font-weight: 600;
            color: var(--text-color);
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .chart {
            height: 300px;
            position: relative;
        }

        /* Export Section */
        .export-section {
            background: var(--card-bg);
            border-radius: 16px;
            padding: 30px;
            margin-bottom: 30px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
        }

        .export-options {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-top: 20px;
        }

        .export-option {
            background: linear-gradient(135deg, var(--card-bg), #ffffff);
            border: 2px solid var(--border-color);
            border-radius: 12px;
            padding: 20px;
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 12px;
            cursor: pointer;
            transition: all 0.3s ease;
            text-decoration: none;
            color: var(--text-color);
        }

        .dark-mode .export-option {
            background: linear-gradient(135deg, var(--card-bg), #2d3748);
        }

        .export-option:hover {
            transform: translateY(-5px);
            border-color: var(--primary-color);
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.1);
            text-decoration: none;
            color: var(--text-color);
        }

        .export-icon {
            width: 60px;
            height: 60px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 28px;
            background: rgba(220, 38, 38, 0.1);
            color: var(--primary-color);
        }

        .export-title {
            font-size: 16px;
            font-weight: 600;
            text-align: center;
        }

        .export-description {
            font-size: 13px;
            color: var(--text-light);
            text-align: center;
        }

        /* Responsive Design */
        @media (max-width: 1200px) {
            .charts-grid {
                grid-template-columns: 1fr;
            }
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
            
            .tabs-header {
                flex-direction: column;
            }
            
            .tab {
                justify-content: center;
            }
            
            .filter-row {
                grid-template-columns: 1fr;
            }
            
            .filter-actions {
                flex-direction: column;
            }
            
            .filter-button, .clear-filters {
                width: 100%;
                justify-content: center;
            }
            
            .predictions-grid {
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
            
            .ai-predictions {
                padding: 20px;
            }
        }

        /* Loading Animation */
        .loading {
            display: inline-block;
            width: 20px;
            height: 20px;
            border: 3px solid rgba(255, 255, 255, 0.3);
            border-radius: 50%;
            border-top-color: white;
            animation: spin 1s ease-in-out infinite;
        }

        @keyframes spin {
            to { transform: rotate(360deg); }
        }

        /* Success Message */
        .success-message {
            background: linear-gradient(135deg, rgba(16, 185, 129, 0.1), rgba(16, 185, 129, 0.2));
            border: 1px solid rgba(16, 185, 129, 0.3);
            border-radius: 12px;
            padding: 16px 20px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            animation: slideIn 0.3s ease;
        }

        .success-message-content {
            display: flex;
            align-items: center;
            gap: 12px;
            color: var(--success);
        }

        .close-message {
            background: none;
            border: none;
            color: var(--success);
            cursor: pointer;
            font-size: 18px;
            width: 30px;
            height: 30px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 50%;
            transition: all 0.3s ease;
        }

        .close-message:hover {
            background: rgba(16, 185, 129, 0.1);
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
    </style>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
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
                        <a href="../admin/manage_users.php" class="submenu-item">Manage Users</a>
                        <a href="../admin/role_control.php" class="submenu-item">Role Control</a>
                        <a href="../admin/audit_logs.php" class="submenu-item">Audit & Activity Logs</a>
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
                       <a href="../admin/view_shifts.php" class="submenu-item">View Shifts</a>
                        <a href="../admin/create_schedule.php" class="submenu-item">Create Schedule</a>
                          <a href="../admin/confirm_availability.php" class="submenu-item">Confirm Availability</a>
                        <a href="../admin/request_change.php" class="submenu-item">Request Change</a>
                        <a href="../admin/monitor_attendance.php" class="submenu-item">Monitor Attendance</a>
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
                        <a href="../admin/approve_reports.php" class="submenu-item">Approve Reports</a>
                        <a href="../admin/review_violations.php" class="submenu-item">Review Violations</a>
                        <a href="../admin/issue_certificates.php" class="submenu-item">Issue Certificates</a>
                        <a href="../admin/track_follow_up.php" class="submenu-item">Track Follow-Up</a>
                    </div>
                    
                    <!-- Post-Incident Reporting & Analytics -->
                    <div class="menu-item active" onclick="toggleSubmenu('analytics-management')">
                        <div class="icon-box icon-bg-pink">
                            <i class='bx bxs-file-doc icon-pink'></i>
                        </div>
                        <span class="font-medium">Analytics & Reports</span>
                        <svg class="dropdown-arrow menu-icon rotated" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                        </svg>
                    </div>
                    <div id="analytics-management" class="submenu active">
                        <a href="../pir/review_summaries.php" class="submenu-item active">Review Summaries</a>
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
                            <input type="text" placeholder="Search analytics..." class="search-input" id="search-input">
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
                        <h1 class="dashboard-title">Review Summaries - Analytics & Reports</h1>
                        <p class="dashboard-subtitle">Comprehensive data analysis and reporting dashboard</p>
                    </div>
                </div>
                
                <!-- Content Container -->
                <div class="content-container">
                    <!-- Tabs -->
                    <div class="tabs-container">
                        <div class="tabs-header">
                            <div class="tab active" onclick="switchTab('review-summaries')">
                                <i class='bx bxs-dashboard'></i>
                                Review Summaries
                            </div>
                            <div class="tab" onclick="switchTab('analyze-data')">
                                <i class='bx bxs-analyse'></i>
                                Analyze Data
                            </div>
                            <div class="tab" onclick="switchTab('export-reports')">
                                <i class='bx bxs-file-export'></i>
                                Export Reports
                            </div>
                            <div class="tab" onclick="switchTab('generate-statistics')">
                                <i class='bx bxs-bar-chart-alt-2'></i>
                                Generate Statistics
                            </div>
                        </div>
                        
                        <!-- Review Summaries Tab -->
                        <div id="review-summaries" class="tab-content active">
                            <!-- AI Predictions Section -->
                            <div class="ai-predictions">
                                <div class="ai-header">
                                    <h2 class="ai-title">AI Predictive Analytics</h2>
                                    <button class="ai-refresh" onclick="refreshPredictions()">
                                        <i class='bx bx-refresh'></i>
                                        Refresh Predictions
                                    </button>
                                </div>
                                <div class="predictions-grid">
                                    <div class="prediction-card">
                                        <h3 class="prediction-title">
                                            <i class='bx bxs-trending-up'></i>
                                            Incident Predictions
                                        </h3>
                                        <div class="prediction-content">
                                            <?php if (isset($predictions['incident_predictions'])): ?>
                                                <?php foreach ($predictions['incident_predictions'] as $prediction): ?>
                                                    <div class="prediction-item">
                                                        <?php echo ucfirst($prediction['type']); ?>: 
                                                        <strong><?php echo $prediction['predicted_count']; ?></strong> incidents expected
                                                        (<?php echo $prediction['trend']; ?> trend)
                                                    </div>
                                                <?php endforeach; ?>
                                            <?php else: ?>
                                                <div class="prediction-item">No prediction data available</div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <div class="prediction-card">
                                        <h3 class="prediction-title">
                                            <i class='bx bxs-cube'></i>
                                            Resource Needs
                                        </h3>
                                        <div class="prediction-content">
                                            <?php if (isset($predictions['resource_predictions'])): ?>
                                                <?php foreach ($predictions['resource_predictions'] as $prediction): ?>
                                                    <div class="prediction-item">
                                                        <?php echo $prediction['resource_type']; ?>: 
                                                        <strong><?php echo $prediction['predicted_need']; ?></strong> units needed
                                                    </div>
                                                <?php endforeach; ?>
                                            <?php else: ?>
                                                <div class="prediction-item">No resource prediction data</div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <div class="prediction-card">
                                        <h3 class="prediction-title">
                                            <i class='bx bxs-graduation'></i>
                                            Training Recommendations
                                        </h3>
                                        <div class="prediction-content">
                                            <?php if (isset($predictions['training_predictions'])): ?>
                                                <?php foreach ($predictions['training_predictions'] as $prediction): ?>
                                                    <div class="prediction-item">
                                                        <?php echo ucfirst($prediction['type']); ?> incidents: 
                                                        <?php echo $prediction['recommended_training']; ?>
                                                    </div>
                                                <?php endforeach; ?>
                                            <?php else: ?>
                                                <div class="prediction-item">No training recommendations</div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Statistics Cards -->
                            <div class="stats-grid">
                                <div class="stat-card">
                                    <div class="stat-header">
                                        <div class="stat-icon-container" style="background: rgba(220, 38, 38, 0.1); color: var(--danger);">
                                            <i class='bx bxs-alarm-exclamation'></i>
                                        </div>
                                        <div class="stat-trend" style="color: var(--success);">
                                            <i class='bx bx-up-arrow-alt'></i>
                                            +15%
                                        </div>
                                    </div>
                                    <div class="stat-value"><?php echo $stats['incidents']['total_incidents']; ?></div>
                                    <div class="stat-label">Total Incidents</div>
                                </div>
                                
                                <div class="stat-card">
                                    <div class="stat-header">
                                        <div class="stat-icon-container" style="background: rgba(59, 130, 246, 0.1); color: var(--info);">
                                            <i class='bx bxs-user-detail'></i>
                                        </div>
                                        <div class="stat-trend" style="color: var(--success);">
                                            <i class='bx bx-up-arrow-alt'></i>
                                            +8%
                                        </div>
                                    </div>
                                    <div class="stat-value"><?php echo $stats['volunteers']['active_volunteers']; ?></div>
                                    <div class="stat-label">Active Volunteers</div>
                                </div>
                                
                                <div class="stat-card">
                                    <div class="stat-header">
                                        <div class="stat-icon-container" style="background: rgba(16, 185, 129, 0.1); color: var(--success);">
                                            <i class='bx bxs-graduation'></i>
                                        </div>
                                        <div class="stat-trend" style="color: var(--success);">
                                            <i class='bx bx-up-arrow-alt'></i>
                                            +12%
                                        </div>
                                    </div>
                                    <div class="stat-value"><?php echo $stats['trainings']['completed_trainings']; ?></div>
                                    <div class="stat-label">Completed Trainings</div>
                                </div>
                                
                                <div class="stat-card">
                                    <div class="stat-header">
                                        <div class="stat-icon-container" style="background: rgba(245, 158, 11, 0.1); color: var(--warning);">
                                            <i class='bx bxs-check-shield'></i>
                                        </div>
                                        <div class="stat-trend" style="color: var(--success);">
                                            <i class='bx bx-up-arrow-alt'></i>
                                            +5%
                                        </div>
                                    </div>
                                    <div class="stat-value"><?php echo number_format($stats['inspections']['avg_compliance_score'], 1); ?>%</div>
                                    <div class="stat-label">Avg Compliance Score</div>
                                </div>
                                
                                <div class="stat-card">
                                    <div class="stat-header">
                                        <div class="stat-icon-container" style="background: rgba(139, 92, 246, 0.1); color: var(--purple);">
                                            <i class='bx bxs-cube'></i>
                                        </div>
                                        <div class="stat-trend" style="color: var(--danger);">
                                            <i class='bx bx-down-arrow-alt'></i>
                                            -3%
                                        </div>
                                    </div>
                                    <div class="stat-value"><?php echo $stats['resources']['serviceable_resources']; ?></div>
                                    <div class="stat-label">Serviceable Resources</div>
                                </div>
                                
                                <div class="stat-card">
                                    <div class="stat-header">
                                        <div class="stat-icon-container" style="background: rgba(236, 72, 153, 0.1); color: var(--pink);">
                                            <i class='bx bxs-time'></i>
                                        </div>
                                        <div class="stat-trend" style="color: var(--danger);">
                                            <i class='bx bx-up-arrow-alt'></i>
                                            +10%
                                        </div>
                                    </div>
                                    <div class="stat-value"><?php echo $stats['incidents']['pending_incidents']; ?></div>
                                    <div class="stat-label">Pending Incidents</div>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Analyze Data Tab -->
                        <div id="analyze-data" class="tab-content">
                            <div class="filters-container">
                                <h3 class="filter-section-title">
                                    <i class='bx bx-filter-alt'></i>
                                    Data Analysis Filters
                                </h3>
                                <form method="GET" id="analysis-form">
                                    <div class="filter-row">
                                        <div class="filter-group">
                                            <label class="filter-label">
                                                <i class='bx bxs-calendar'></i>
                                                Date From
                                            </label>
                                            <input type="date" class="filter-input" name="date_from" value="<?php echo $filter_date_from; ?>">
                                        </div>
                                        
                                        <div class="filter-group">
                                            <label class="filter-label">
                                                <i class='bx bxs-calendar'></i>
                                                Date To
                                            </label>
                                            <input type="date" class="filter-input" name="date_to" value="<?php echo $filter_date_to; ?>">
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
                                            <input type="text" class="filter-input" name="search" placeholder="Search data..." value="<?php echo htmlspecialchars($search_query); ?>">
                                        </div>
                                        
                                        <div class="filter-group">
                                            <label class="filter-label">
                                                <i class='bx bxs-category'></i>
                                                Data Type
                                            </label>
                                            <select class="filter-select" name="type">
                                                <option value="all" <?php echo $filter_type === 'all' ? 'selected' : ''; ?>>All Data</option>
                                                <option value="incidents" <?php echo $filter_type === 'incidents' ? 'selected' : ''; ?>>Incidents Only</option>
                                                <option value="volunteers" <?php echo $filter_type === 'volunteers' ? 'selected' : ''; ?>>Volunteers Only</option>
                                                <option value="trainings" <?php echo $filter_type === 'trainings' ? 'selected' : ''; ?>>Trainings Only</option>
                                            </select>
                                        </div>
                                    </div>
                                    
                                    <div class="filter-actions">
                                        <a href="review_summaries.php" class="filter-button clear-filters">
                                            <i class='bx bx-x'></i>
                                            Clear Filters
                                        </a>
                                        <button type="submit" class="filter-button">
                                            <i class='bx bx-analyse'></i>
                                            Analyze Data
                                        </button>
                                    </div>
                                </form>
                            </div>
                            
                            <!-- Charts Section -->
                            <div class="charts-grid">
                                <div class="chart-container">
                                    <div class="chart-header">
                                        <h3 class="chart-title">
                                            <i class='bx bxs-pie-chart-alt-2'></i>
                                            Incident Status Distribution
                                        </h3>
                                    </div>
                                    <div class="chart">
                                        <canvas id="incidentStatusChart"></canvas>
                                    </div>
                                </div>
                                
                                <div class="chart-container">
                                    <div class="chart-header">
                                        <h3 class="chart-title">
                                            <i class='bx bxs-bar-chart-alt-2'></i>
                                            Severity Distribution
                                        </h3>
                                    </div>
                                    <div class="chart">
                                        <canvas id="severityChart"></canvas>
                                    </div>
                                </div>
                                
                                <div class="chart-container">
                                    <div class="chart-header">
                                        <h3 class="chart-title">
                                            <i class='bx bxs-line-chart'></i>
                                            Monthly Incident Trend
                                        </h3>
                                    </div>
                                    <div class="chart">
                                        <canvas id="monthlyTrendChart"></canvas>
                                    </div>
                                </div>
                                
                                <div class="chart-container">
                                    <div class="chart-header">
                                        <h3 class="chart-title">
                                            <i class='bx bxs-doughnut-chart'></i>
                                            Resource Condition
                                        </h3>
                                    </div>
                                    <div class="chart">
                                        <canvas id="resourceConditionChart"></canvas>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Export Reports Tab -->
                        <div id="export-reports" class="tab-content">
                            <div class="export-section">
                                <h3 class="filter-section-title">
                                    <i class='bx bxs-file-export'></i>
                                    Export Reports
                                </h3>
                                <p>Generate and download comprehensive reports in various formats.</p>
                                
                                <div class="export-options">
                                    <a href="?export=pdf&type=<?php echo $filter_type; ?>&status=<?php echo $filter_status; ?>&date_from=<?php echo $filter_date_from; ?>&date_to=<?php echo $filter_date_to; ?>&barangay=<?php echo $filter_barangay; ?>&search=<?php echo urlencode($search_query); ?>" class="export-option" target="_blank">
                                        <div class="export-icon">
                                            <i class='bx bxs-file-pdf'></i>
                                        </div>
                                        <div class="export-title">PDF Report</div>
                                        <div class="export-description">Comprehensive PDF report with all analytics</div>
                                    </a>
                                    
                                    <a href="#" class="export-option" onclick="exportToCSV()">
                                        <div class="export-icon" style="background: rgba(16, 185, 129, 0.1); color: var(--success);">
                                            <i class='bx bxs-file'></i>
                                        </div>
                                        <div class="export-title">CSV Export</div>
                                        <div class="export-description">Export data in CSV format for Excel</div>
                                    </a>
                                    
                                    <a href="#" class="export-option" onclick="exportToExcel()">
                                        <div class="export-icon" style="background: rgba(59, 130, 246, 0.1); color: var(--info);">
                                            <i class='bx bxs-spreadsheet'></i>
                                        </div>
                                        <div class="export-title">Excel Report</div>
                                        <div class="export-description">Generate Excel spreadsheet with charts</div>
                                    </a>
                                    
                                    <a href="#" class="export-option" onclick="generateSummary()">
                                        <div class="export-icon" style="background: rgba(245, 158, 11, 0.1); color: var(--warning);">
                                            <i class='bx bxs-report'></i>
                                        </div>
                                        <div class="export-title">Executive Summary</div>
                                        <div class="export-description">Brief summary report for management</div>
                                    </a>
                                </div>
                            </div>
                            
                            <!-- Export History -->
                            <div class="chart-container">
                                <div class="chart-header">
                                    <h3 class="chart-title">
                                        <i class='bx bxs-history'></i>
                                        Recent Exports
                                    </h3>
                                </div>
                                <div style="padding: 20px;">
                                    <p>No recent exports found. Generate your first report!</p>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Generate Statistics Tab -->
                        <div id="generate-statistics" class="tab-content">
                            <div class="filters-container">
                                <h3 class="filter-section-title">
                                    <i class='bx bxs-stats'></i>
                                    Statistics Configuration
                                </h3>
                                <form id="statistics-form">
                                    <div class="filter-row">
                                        <div class="filter-group">
                                            <label class="filter-label">
                                                <i class='bx bxs-calendar'></i>
                                                Time Period
                                            </label>
                                            <select class="filter-select" id="time-period">
                                                <option value="7">Last 7 Days</option>
                                                <option value="30">Last 30 Days</option>
                                                <option value="90">Last 90 Days</option>
                                                <option value="365">Last Year</option>
                                                <option value="all">All Time</option>
                                            </select>
                                        </div>
                                        
                                        <div class="filter-group">
                                            <label class="filter-label">
                                                <i class='bx bxs-category'></i>
                                                Statistics Type
                                            </label>
                                            <select class="filter-select" id="statistics-type">
                                                <option value="overview">Overview Statistics</option>
                                                <option value="detailed">Detailed Analysis</option>
                                                <option value="comparative">Comparative Analysis</option>
                                                <option value="predictive">Predictive Statistics</option>
                                            </select>
                                        </div>
                                    </div>
                                    
                                    <div class="filter-row">
                                        <div class="filter-group">
                                            <label class="filter-label">
                                                <i class='bx bxs-data'></i>
                                                Include Data
                                            </label>
                                            <div style="display: flex; flex-direction: column; gap: 8px; margin-top: 5px;">
                                                <label style="display: flex; align-items: center; gap: 8px;">
                                                    <input type="checkbox" checked> Incident Data
                                                </label>
                                                <label style="display: flex; align-items: center; gap: 8px;">
                                                    <input type="checkbox" checked> Volunteer Data
                                                </label>
                                                <label style="display: flex; align-items: center; gap: 8px;">
                                                    <input type="checkbox" checked> Training Data
                                                </label>
                                                <label style="display: flex; align-items: center; gap: 8px;">
                                                    <input type="checkbox"> Resource Data
                                                </label>
                                            </div>
                                        </div>
                                        
                                        <div class="filter-group">
                                            <label class="filter-label">
                                                <i class='bx bxs-chart'></i>
                                                Chart Type
                                            </label>
                                            <select class="filter-select" id="chart-type">
                                                <option value="bar">Bar Chart</option>
                                                <option value="line">Line Chart</option>
                                                <option value="pie">Pie Chart</option>
                                                <option value="doughnut">Doughnut Chart</option>
                                            </select>
                                        </div>
                                    </div>
                                    
                                    <div class="filter-actions">
                                        <button type="button" class="filter-button clear-filters" onclick="resetStatistics()">
                                            <i class='bx bx-reset'></i>
                                            Reset
                                        </button>
                                        <button type="button" class="filter-button" onclick="generateStatistics()">
                                            <i class='bx bxs-bar-chart-alt-2'></i>
                                            Generate Statistics
                                        </button>
                                    </div>
                                </form>
                            </div>
                            
                            <!-- Statistics Results -->
                            <div class="chart-container">
                                <div class="chart-header">
                                    <h3 class="chart-title">
                                        <i class='bx bxs-dashboard'></i>
                                        Generated Statistics
                                    </h3>
                                    <button class="filter-button" style="padding: 8px 16px; font-size: 13px;" onclick="downloadStatistics()">
                                        <i class='bx bxs-download'></i>
                                        Download
                                    </button>
                                </div>
                                <div class="chart">
                                    <canvas id="statisticsChart"></canvas>
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
            // Initialize event listeners
            initEventListeners();
            
            // Update time display
            updateTime();
            setInterval(updateTime, 1000);
            
            // Initialize charts
            initializeCharts();
            
            // Initialize search
            initSearch();
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
                
                // Update charts for dark mode
                updateChartsForTheme();
            });
            
            // Load saved theme preference
            const savedTheme = localStorage.getItem('theme');
            if (savedTheme === 'dark') {
                document.body.classList.add('dark-mode');
                themeIcon.className = 'bx bx-sun';
                themeText.textContent = 'Light Mode';
            }
        }
        
        function initSearch() {
            const searchInput = document.getElementById('search-input');
            const analysisForm = document.getElementById('analysis-form');
            const searchParam = analysisForm.querySelector('input[name="search"]');
            
            // Set search input value from URL parameter
            searchInput.value = '<?php echo htmlspecialchars($search_query); ?>';
            
            // Add event listener for search input
            searchInput.addEventListener('keypress', function(e) {
                if (e.key === 'Enter') {
                    searchParam.value = this.value;
                    analysisForm.submit();
                }
            });
        }
        
        function switchTab(tabId) {
            // Hide all tab contents
            document.querySelectorAll('.tab-content').forEach(tab => {
                tab.classList.remove('active');
            });
            
            // Remove active class from all tabs
            document.querySelectorAll('.tab').forEach(tab => {
                tab.classList.remove('active');
            });
            
            // Show selected tab content
            document.getElementById(tabId).classList.add('active');
            
            // Add active class to clicked tab
            event.currentTarget.classList.add('active');
            
            // Update charts when switching to analyze data tab
            if (tabId === 'analyze-data') {
                updateCharts();
            }
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
        
        function refreshPredictions() {
            const refreshBtn = event.currentTarget;
            const originalHTML = refreshBtn.innerHTML;
            
            // Show loading state
            refreshBtn.innerHTML = '<div class="loading"></div>';
            refreshBtn.disabled = true;
            
            // Fetch new predictions
            fetch('review_summaries.php?ai_predict=true')
                .then(response => response.json())
                .then(data => {
                    // Update predictions display
                    updatePredictionsDisplay(data);
                    
                    // Restore button
                    setTimeout(() => {
                        refreshBtn.innerHTML = originalHTML;
                        refreshBtn.disabled = false;
                        
                        // Show success message
                        showNotification('success', 'Predictions refreshed successfully!');
                    }, 500);
                })
                .catch(error => {
                    console.error('Error refreshing predictions:', error);
                    refreshBtn.innerHTML = originalHTML;
                    refreshBtn.disabled = false;
                    showNotification('error', 'Failed to refresh predictions');
                });
        }
        
        function updatePredictionsDisplay(predictions) {
            // Update incident predictions
            if (predictions.incident_predictions) {
                const incidentContainer = document.querySelector('.prediction-card:nth-child(1) .prediction-content');
                incidentContainer.innerHTML = '';
                predictions.incident_predictions.forEach(prediction => {
                    const item = document.createElement('div');
                    item.className = 'prediction-item';
                    item.innerHTML = `
                        ${ucfirst(prediction.type)}: 
                        <strong>${prediction.predicted_count}</strong> incidents expected
                        (${prediction.trend} trend)
                    `;
                    incidentContainer.appendChild(item);
                });
            }
            
            // Update resource predictions
            if (predictions.resource_predictions) {
                const resourceContainer = document.querySelector('.prediction-card:nth-child(2) .prediction-content');
                resourceContainer.innerHTML = '';
                predictions.resource_predictions.forEach(prediction => {
                    const item = document.createElement('div');
                    item.className = 'prediction-item';
                    item.innerHTML = `
                        ${prediction.resource_type}: 
                        <strong>${prediction.predicted_need}</strong> units needed
                    `;
                    resourceContainer.appendChild(item);
                });
            }
            
            // Update training predictions
            if (predictions.training_predictions) {
                const trainingContainer = document.querySelector('.prediction-card:nth-child(3) .prediction-content');
                trainingContainer.innerHTML = '';
                predictions.training_predictions.forEach(prediction => {
                    const item = document.createElement('div');
                    item.className = 'prediction-item';
                    item.innerHTML = `
                        ${ucfirst(prediction.type)} incidents: 
                        ${prediction.recommended_training}
                    `;
                    trainingContainer.appendChild(item);
                });
            }
        }
        
        function ucfirst(str) {
            return str.charAt(0).toUpperCase() + str.slice(1);
        }
        
        function initializeCharts() {
            // Incident Status Chart
            const incidentStatusCtx = document.getElementById('incidentStatusChart').getContext('2d');
            window.incidentStatusChart = new Chart(incidentStatusCtx, {
                type: 'doughnut',
                data: {
                    labels: ['Pending', 'Processing', 'Responded', 'Closed'],
                    datasets: [{
                        data: [
                            <?php echo $stats['incidents']['pending_incidents']; ?>,
                            <?php echo $stats['incidents']['processing_incidents']; ?>,
                            <?php echo $stats['incidents']['responded_incidents']; ?>,
                            <?php echo $stats['incidents']['closed_incidents']; ?>
                        ],
                        backgroundColor: [
                            'rgba(245, 158, 11, 0.8)',
                            'rgba(59, 130, 246, 0.8)',
                            'rgba(139, 92, 246, 0.8)',
                            'rgba(16, 185, 129, 0.8)'
                        ],
                        borderColor: [
                            'rgba(245, 158, 11, 1)',
                            'rgba(59, 130, 246, 1)',
                            'rgba(139, 92, 246, 1)',
                            'rgba(16, 185, 129, 1)'
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
                                color: getComputedStyle(document.documentElement).getPropertyValue('--text-color')
                            }
                        }
                    }
                }
            });
            
            // Severity Chart
            const severityCtx = document.getElementById('severityChart').getContext('2d');
            window.severityChart = new Chart(severityCtx, {
                type: 'bar',
                data: {
                    labels: ['Critical', 'High', 'Medium', 'Low'],
                    datasets: [{
                        label: 'Incidents by Severity',
                        data: [
                            <?php echo $stats['incidents']['critical_percentage']; ?>,
                            <?php echo $stats['incidents']['high_percentage']; ?>,
                            <?php echo $stats['incidents']['medium_percentage']; ?>,
                            <?php echo $stats['incidents']['low_percentage']; ?>
                        ],
                        backgroundColor: [
                            'rgba(220, 38, 38, 0.8)',
                            'rgba(245, 158, 11, 0.8)',
                            'rgba(59, 130, 246, 0.8)',
                            'rgba(16, 185, 129, 0.8)'
                        ],
                        borderColor: [
                            'rgba(220, 38, 38, 1)',
                            'rgba(245, 158, 11, 1)',
                            'rgba(59, 130, 246, 1)',
                            'rgba(16, 185, 129, 1)'
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
                            ticks: {
                                color: getComputedStyle(document.documentElement).getPropertyValue('--text-color')
                            },
                            grid: {
                                color: getComputedStyle(document.documentElement).getPropertyValue('--border-color')
                            }
                        },
                        x: {
                            ticks: {
                                color: getComputedStyle(document.documentElement).getPropertyValue('--text-color')
                            },
                            grid: {
                                color: getComputedStyle(document.documentElement).getPropertyValue('--border-color')
                            }
                        }
                    },
                    plugins: {
                        legend: {
                            labels: {
                                color: getComputedStyle(document.documentElement).getPropertyValue('--text-color')
                            }
                        }
                    }
                }
            });
            
            // Monthly Trend Chart (sample data)
            const monthlyTrendCtx = document.getElementById('monthlyTrendChart').getContext('2d');
            window.monthlyTrendChart = new Chart(monthlyTrendCtx, {
                type: 'line',
                data: {
                    labels: ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'],
                    datasets: [{
                        label: 'Incidents',
                        data: [12, 19, 15, 25, 22, 30, 28, 35, 32, 40, 38, 45],
                        borderColor: 'rgba(220, 38, 38, 0.8)',
                        backgroundColor: 'rgba(220, 38, 38, 0.1)',
                        tension: 0.4,
                        fill: true
                    }, {
                        label: 'Resolved',
                        data: [8, 12, 10, 18, 16, 22, 20, 25, 24, 30, 28, 35],
                        borderColor: 'rgba(16, 185, 129, 0.8)',
                        backgroundColor: 'rgba(16, 185, 129, 0.1)',
                        tension: 0.4,
                        fill: true
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    scales: {
                        y: {
                            beginAtZero: true,
                            ticks: {
                                color: getComputedStyle(document.documentElement).getPropertyValue('--text-color')
                            },
                            grid: {
                                color: getComputedStyle(document.documentElement).getPropertyValue('--border-color')
                            }
                        },
                        x: {
                            ticks: {
                                color: getComputedStyle(document.documentElement).getPropertyValue('--text-color')
                            },
                            grid: {
                                color: getComputedStyle(document.documentElement).getPropertyValue('--border-color')
                            }
                        }
                    },
                    plugins: {
                        legend: {
                            labels: {
                                color: getComputedStyle(document.documentElement).getPropertyValue('--text-color')
                            }
                        }
                    }
                }
            });
            
            // Resource Condition Chart
            const resourceConditionCtx = document.getElementById('resourceConditionChart').getContext('2d');
            window.resourceConditionChart = new Chart(resourceConditionCtx, {
                type: 'pie',
                data: {
                    labels: ['Serviceable', 'Under Maintenance', 'Condemned'],
                    datasets: [{
                        data: [
                            <?php echo $stats['resources']['serviceable_resources']; ?>,
                            <?php echo $stats['resources']['maintenance_resources']; ?>,
                            <?php echo $stats['resources']['condemned_resources']; ?>
                        ],
                        backgroundColor: [
                            'rgba(16, 185, 129, 0.8)',
                            'rgba(245, 158, 11, 0.8)',
                            'rgba(220, 38, 38, 0.8)'
                        ],
                        borderColor: [
                            'rgba(16, 185, 129, 1)',
                            'rgba(245, 158, 11, 1)',
                            'rgba(220, 38, 38, 1)'
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
                                color: getComputedStyle(document.documentElement).getPropertyValue('--text-color')
                            }
                        }
                    }
                }
            });
            
            // Statistics Chart
            const statisticsCtx = document.getElementById('statisticsChart').getContext('2d');
            window.statisticsChart = new Chart(statisticsCtx, {
                type: 'bar',
                data: {
                    labels: ['Incidents', 'Volunteers', 'Trainings', 'Inspections', 'Resources'],
                    datasets: [{
                        label: 'Total Count',
                        data: [
                            <?php echo $stats['incidents']['total_incidents']; ?>,
                            <?php echo $stats['volunteers']['total_volunteers']; ?>,
                            <?php echo $stats['trainings']['total_trainings']; ?>,
                            <?php echo $stats['inspections']['total_inspections']; ?>,
                            <?php echo $stats['resources']['total_resources']; ?>
                        ],
                        backgroundColor: 'rgba(220, 38, 38, 0.8)',
                        borderColor: 'rgba(220, 38, 38, 1)',
                        borderWidth: 1
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    scales: {
                        y: {
                            beginAtZero: true,
                            ticks: {
                                color: getComputedStyle(document.documentElement).getPropertyValue('--text-color')
                            },
                            grid: {
                                color: getComputedStyle(document.documentElement).getPropertyValue('--border-color')
                            }
                        },
                        x: {
                            ticks: {
                                color: getComputedStyle(document.documentElement).getPropertyValue('--text-color')
                            },
                            grid: {
                                color: getComputedStyle(document.documentElement).getPropertyValue('--border-color')
                            }
                        }
                    },
                    plugins: {
                        legend: {
                            labels: {
                                color: getComputedStyle(document.documentElement).getPropertyValue('--text-color')
                            }
                        }
                    }
                }
            });
        }
        
        function updateCharts() {
            // This function would update charts with filtered data
            // For now, we'll just refresh the existing charts
            if (window.incidentStatusChart) window.incidentStatusChart.update();
            if (window.severityChart) window.severityChart.update();
            if (window.monthlyTrendChart) window.monthlyTrendChart.update();
            if (window.resourceConditionChart) window.resourceConditionChart.update();
            if (window.statisticsChart) window.statisticsChart.update();
        }
        
        function updateChartsForTheme() {
            const textColor = getComputedStyle(document.documentElement).getPropertyValue('--text-color');
            const borderColor = getComputedStyle(document.documentElement).getPropertyValue('--border-color');
            
            // Update all charts
            const charts = [
                window.incidentStatusChart,
                window.severityChart,
                window.monthlyTrendChart,
                window.resourceConditionChart,
                window.statisticsChart
            ];
            
            charts.forEach(chart => {
                if (chart) {
                    // Update axis labels
                    if (chart.options.scales) {
                        Object.values(chart.options.scales).forEach(scale => {
                            if (scale.ticks) {
                                scale.ticks.color = textColor;
                            }
                            if (scale.grid) {
                                scale.grid.color = borderColor;
                            }
                        });
                    }
                    
                    // Update legend labels
                    if (chart.options.plugins?.legend?.labels) {
                        chart.options.plugins.legend.labels.color = textColor;
                    }
                    
                    chart.update();
                }
            });
        }
        
        function exportToCSV() {
            showNotification('info', 'Generating CSV report...');
            // In a real implementation, this would generate a CSV file
            setTimeout(() => {
                showNotification('success', 'CSV report generated successfully!');
                // Trigger download
                const link = document.createElement('a');
                link.href = 'data:text/csv;charset=utf-8,' + encodeURIComponent(generateCSVData());
                link.download = 'FRSM_Report_' + new Date().toISOString().split('T')[0] + '.csv';
                link.click();
            }, 1500);
        }
        
        function exportToExcel() {
            showNotification('info', 'Generating Excel report...');
            // In a real implementation, this would generate an Excel file
            setTimeout(() => {
                showNotification('success', 'Excel report generated successfully!');
            }, 1500);
        }
        
        function generateSummary() {
            showNotification('info', 'Generating executive summary...');
            // In a real implementation, this would generate a summary report
            setTimeout(() => {
                showNotification('success', 'Executive summary generated successfully!');
            }, 1500);
        }
        
        function generateCSVData() {
            // Generate CSV data from statistics
            let csv = 'Category,Total,Active,Pending,Completed,Percentage\n';
            
            // Incidents
            csv += `Incidents,${stats.incidents.total_incidents},${stats.incidents.processing_incidents},${stats.incidents.pending_incidents},${stats.incidents.closed_incidents},${stats.incidents.critical_percentage}%\n`;
            
            // Volunteers
            csv += `Volunteers,${stats.volunteers.total_volunteers},${stats.volunteers.active_volunteers},${stats.volunteers.pending_volunteers},${stats.volunteers.rejected_volunteers},${stats.volunteers.active_percentage}%\n`;
            
            // Trainings
            csv += `Trainings,${stats.trainings.total_trainings},${stats.trainings.ongoing_trainings},${stats.trainings.scheduled_trainings},${stats.trainings.completed_trainings},${stats.trainings.avg_participants}\n`;
            
            // Inspections
            csv += `Inspections,${stats.inspections.total_inspections},${stats.inspections.completed_inspections},${stats.inspections.draft_inspections},${stats.inspections.submitted_inspections},${stats.inspections.avg_compliance_score}%\n`;
            
            // Resources
            csv += `Resources,${stats.resources.total_resources},${stats.resources.serviceable_resources},${stats.resources.maintenance_resources},${stats.resources.condemned_resources},${stats.resources.total_available_quantity}\n`;
            
            return csv;
        }
        
        function generateStatistics() {
            showNotification('info', 'Generating custom statistics...');
            
            const timePeriod = document.getElementById('time-period').value;
            const statsType = document.getElementById('statistics-type').value;
            const chartType = document.getElementById('chart-type').value;
            
            // Update chart type
            window.statisticsChart.config.type = chartType;
            window.statisticsChart.update();
            
            setTimeout(() => {
                showNotification('success', 'Statistics generated successfully!');
            }, 1000);
        }
        
        function resetStatistics() {
            document.getElementById('time-period').value = '7';
            document.getElementById('statistics-type').value = 'overview';
            document.getElementById('chart-type').value = 'bar';
            
            // Reset checkboxes
            document.querySelectorAll('#statistics-form input[type="checkbox"]').forEach(cb => {
                cb.checked = true;
            });
            
            showNotification('info', 'Statistics configuration reset');
        }
        
        function downloadStatistics() {
            const canvas = document.getElementById('statisticsChart');
            const link = document.createElement('a');
            link.download = 'FRSM_Statistics_' + new Date().toISOString().split('T')[0] + '.png';
            link.href = canvas.toDataURL('image/png');
            link.click();
            
            showNotification('success', 'Statistics chart downloaded!');
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
                    <i class='bx ${type === 'success' ? 'bx-check-circle' : type === 'error' ? 'bx-error-circle' : type === 'warning' ? 'bx-error' : 'bx-info-circle'}'></i>
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
        
        // PHP statistics data for JavaScript access
        const stats = {
            incidents: <?php echo json_encode($stats['incidents']); ?>,
            volunteers: <?php echo json_encode($stats['volunteers']); ?>,
            trainings: <?php echo json_encode($stats['trainings']); ?>,
            inspections: <?php echo json_encode($stats['inspections']); ?>,
            resources: <?php echo json_encode($stats['resources']); ?>
        };
    </script>
</body>
</html>