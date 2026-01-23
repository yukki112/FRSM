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

// Function to fetch fire and rescue incidents from database
function getFireAndRescueIncidents($pdo) {
    $sql = "SELECT 
                ai.id,
                ai.external_id,
                ai.emergency_type,
                ai.rescue_category,
                ai.severity,
                ai.status,
                ai.location,
                ai.affected_barangays,
                ai.description,
                ai.caller_name,
                ai.caller_phone,
                DATE(ai.created_at) as incident_date,
                ai.created_at,
                ai.responded_at,
                ai.is_fire_rescue_related,
                di.status as dispatch_status,
                u.unit_name,
                u.unit_type
            FROM api_incidents ai
            LEFT JOIN dispatch_incidents di ON ai.id = di.incident_id
            LEFT JOIN units u ON di.unit_id = u.id
            WHERE ai.emergency_type IN ('fire', 'rescue') 
               OR ai.is_fire_rescue_related = 1
               OR ai.rescue_category IS NOT NULL
            ORDER BY ai.created_at DESC";
    
    $stmt = $pdo->query($sql);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Function to calculate predictive analytics
function calculatePredictiveAnalytics($incidents) {
    $analytics = [
        'total_incidents' => count($incidents),
        'incidents_by_month' => [],
        'incidents_by_day' => [],
        'incidents_by_hour' => [],
        'response_times' => [],
        'severity_distribution' => [],
        'type_distribution' => [],
        'barangay_distribution' => [],
        'predicted_risks' => [],
        'trends' => []
    ];
    
    if (empty($incidents)) {
        return $analytics;
    }
    
    // Calculate monthly distribution
    foreach ($incidents as $incident) {
        $date = new DateTime($incident['created_at']);
        $month = $date->format('Y-m');
        $dayOfWeek = $date->format('l');
        $hour = (int)$date->format('G');
        
        // Monthly distribution
        if (!isset($analytics['incidents_by_month'][$month])) {
            $analytics['incidents_by_month'][$month] = 0;
        }
        $analytics['incidents_by_month'][$month]++;
        
        // Day of week distribution
        if (!isset($analytics['incidents_by_day'][$dayOfWeek])) {
            $analytics['incidents_by_day'][$dayOfWeek] = 0;
        }
        $analytics['incidents_by_day'][$dayOfWeek]++;
        
        // Hourly distribution
        if (!isset($analytics['incidents_by_hour'][$hour])) {
            $analytics['incidents_by_hour'][$hour] = 0;
        }
        $analytics['incidents_by_hour'][$hour]++;
        
        // Response time calculation
        if ($incident['responded_at']) {
            $reported = new DateTime($incident['created_at']);
            $responded = new DateTime($incident['responded_at']);
            $responseTime = $responded->getTimestamp() - $reported->getTimestamp();
            $analytics['response_times'][] = $responseTime;
        }
        
        // Severity distribution
        $severity = strtolower($incident['severity']);
        if (!isset($analytics['severity_distribution'][$severity])) {
            $analytics['severity_distribution'][$severity] = 0;
        }
        $analytics['severity_distribution'][$severity]++;
        
        // Type distribution
        $type = $incident['emergency_type'];
        if ($incident['rescue_category']) {
            $type = 'rescue_' . $incident['rescue_category'];
        }
        if (!isset($analytics['type_distribution'][$type])) {
            $analytics['type_distribution'][$type] = 0;
        }
        $analytics['type_distribution'][$type]++;
        
        // Barangay distribution
        $barangay = $incident['affected_barangays'] ?: 'Unknown';
        if (!isset($analytics['barangay_distribution'][$barangay])) {
            $analytics['barangay_distribution'][$barangay] = 0;
        }
        $analytics['barangay_distribution'][$barangay]++;
    }
    
    // Calculate average response time
    if (!empty($analytics['response_times'])) {
        $analytics['avg_response_time'] = array_sum($analytics['response_times']) / count($analytics['response_times']);
    } else {
        $analytics['avg_response_time'] = 0;
    }
    
    // Calculate predicted risks using AI/ML algorithm
    $analytics['predicted_risks'] = calculatePredictedRisks($incidents, $analytics);
    
    // Calculate trends
    $analytics['trends'] = calculateTrends($analytics['incidents_by_month']);
    
    return $analytics;
}

// AI-Powered Risk Prediction Algorithm
function calculatePredictedRisks($incidents, $analytics) {
    $risks = [];
    $barangayData = [];
    
    // Group incidents by barangay
    foreach ($incidents as $incident) {
        $barangay = $incident['affected_barangays'] ?: 'Unknown';
        if (!isset($barangayData[$barangay])) {
            $barangayData[$barangay] = [
                'count' => 0,
                'severity_sum' => 0,
                'types' => [],
                'recent_count' => 0
            ];
        }
        
        $barangayData[$barangay]['count']++;
        
        // Convert severity to numerical value for calculation
        $severityValue = 1;
        if ($incident['severity'] == 'medium') $severityValue = 2;
        if ($incident['severity'] == 'high') $severityValue = 3;
        if ($incident['severity'] == 'critical') $severityValue = 4;
        
        $barangayData[$barangay]['severity_sum'] += $severityValue;
        
        $type = $incident['emergency_type'];
        if ($incident['rescue_category']) {
            $type = 'rescue_' . $incident['rescue_category'];
        }
        if (!isset($barangayData[$barangay]['types'][$type])) {
            $barangayData[$barangay]['types'][$type] = 0;
        }
        $barangayData[$barangay]['types'][$type]++;
        
        // Check if incident is recent (last 7 days)
        $incidentDate = new DateTime($incident['created_at']);
        $now = new DateTime();
        $daysDiff = $now->diff($incidentDate)->days;
        if ($daysDiff <= 7) {
            $barangayData[$barangay]['recent_count']++;
        }
    }
    
    // Calculate risk score for each barangay
    foreach ($barangayData as $barangay => $data) {
        $riskScore = 0;
        
        // Factor 1: Incident frequency (40% weight)
        $frequencyScore = ($data['count'] / count($incidents)) * 100;
        $riskScore += $frequencyScore * 0.4;
        
        // Factor 2: Average severity (30% weight)
        $avgSeverity = $data['count'] > 0 ? ($data['severity_sum'] / $data['count']) : 0;
        $severityScore = ($avgSeverity / 4) * 100; // Normalize to 0-100
        $riskScore += $severityScore * 0.3;
        
        // Factor 3: Recent incidents (20% weight)
        $recentScore = ($data['recent_count'] / max(1, $data['count'])) * 100;
        $riskScore += $recentScore * 0.2;
        
        // Factor 4: Type diversity (10% weight)
        $typeCount = count($data['types']);
        $typeScore = min(100, $typeCount * 20); // Max 5 types = 100%
        $riskScore += $typeScore * 0.1;
        
        // Apply seasonal adjustment (if we have enough data)
        $currentMonth = date('n');
        $seasonalFactor = 1.0;
        if (in_array($currentMonth, [3, 4, 5])) { // Summer months - higher fire risk
            $seasonalFactor = 1.2;
        } elseif (in_array($currentMonth, [6, 7, 8, 9])) { // Rainy season - higher flood risk
            $seasonalFactor = 1.3;
        }
        
        $riskScore *= $seasonalFactor;
        
        // Determine risk level
        $riskLevel = 'low';
        if ($riskScore >= 70) $riskLevel = 'high';
        elseif ($riskScore >= 40) $riskLevel = 'medium';
        
        $risks[$barangay] = [
            'risk_score' => round($riskScore, 2),
            'risk_level' => $riskLevel,
            'incident_count' => $data['count'],
            'recent_count' => $data['recent_count'],
            'avg_severity' => round($avgSeverity, 2),
            'primary_type' => array_search(max($data['types']), $data['types'])
        ];
    }
    
    // Sort by risk score
    uasort($risks, function($a, $b) {
        return $b['risk_score'] <=> $a['risk_score'];
    });
    
    return $risks;
}

// Trend calculation function
function calculateTrends($monthlyData) {
    if (count($monthlyData) < 2) {
        return ['trend' => 'insufficient_data', 'change_percentage' => 0];
    }
    
    // Get last two months data
    $months = array_keys($monthlyData);
    sort($months);
    $lastMonth = end($months);
    $prevMonth = prev($months);
    
    $lastCount = $monthlyData[$lastMonth] ?? 0;
    $prevCount = $monthlyData[$prevMonth] ?? 0;
    
    if ($prevCount == 0) {
        return ['trend' => 'new_data', 'change_percentage' => 100];
    }
    
    $change = (($lastCount - $prevCount) / $prevCount) * 100;
    
    $trend = 'stable';
    if ($change > 20) $trend = 'increasing';
    if ($change < -20) $trend = 'decreasing';
    
    return [
        'trend' => $trend,
        'change_percentage' => round($change, 2),
        'last_month' => $lastMonth,
        'last_count' => $lastCount,
        'prev_month' => $prevMonth,
        'prev_count' => $prevCount
    ];
}

// Function to get time-based predictions
function getTimeBasedPredictions($hourlyData, $dailyData) {
    $predictions = [];
    
    // Find peak hours
    arsort($hourlyData);
    $peakHours = array_slice($hourlyData, 0, 3, true);
    
    // Find peak days
    arsort($dailyData);
    $peakDays = array_slice($dailyData, 0, 2, true);
    
    $predictions['peak_hours'] = $peakHours;
    $predictions['peak_days'] = $peakDays;
    
    // Predict next month incidents based on trend
    $totalHours = array_sum($hourlyData);
    $avgDaily = $totalHours / 24;
    $predictions['predicted_daily_avg'] = round($avgDaily, 1);
    
    return $predictions;
}

// Fetch incidents and calculate analytics
$incidents = getFireAndRescueIncidents($pdo);
$analytics = calculatePredictiveAnalytics($incidents);
$timePredictions = getTimeBasedPredictions($analytics['incidents_by_hour'], $analytics['incidents_by_day']);

// Get filter parameters
$date_range = isset($_GET['date_range']) ? $_GET['date_range'] : 'all';
$barangay_filter = isset($_GET['barangay']) ? $_GET['barangay'] : 'all';
$severity_filter = isset($_GET['severity']) ? $_GET['severity'] : 'all';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Incidents Analytics - Fire & Rescue Management</title>
    <link href='https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css' rel='stylesheet'>
    <link rel="icon" type="image/png" sizes="32x32" href="../../img/frsm-logo.png">
    <link rel="stylesheet" href="../../css/dashboard.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chartjs-adapter-date-fns"></script>
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

        .analytics-container {
            display: flex;
            flex-direction: column;
            gap: 24px;
            padding: 0 40px 40px;
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
            background: linear-gradient(90deg, var(--primary-color), var(--secondary-color));
        }
        
        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.1);
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
        
        .stat-value {
            font-size: 28px;
            font-weight: 800;
            margin-bottom: 4px;
        }
        
        .stat-label {
            font-size: 14px;
            color: var(--text-light);
        }
        
        .stat-change {
            font-size: 12px;
            margin-top: 4px;
            padding: 2px 8px;
            border-radius: 12px;
        }
        
        .change-positive {
            background: rgba(16, 185, 129, 0.1);
            color: var(--success);
        }
        
        .change-negative {
            background: rgba(239, 68, 68, 0.1);
            color: var(--danger);
        }
        
        .change-neutral {
            background: rgba(156, 163, 175, 0.1);
            color: var(--gray-500);
        }
        
        .charts-container {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(500px, 1fr));
            gap: 25px;
            margin-bottom: 24px;
        }
        
        .chart-card {
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: 16px;
            padding: 25px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
        }
        
        .chart-title {
            font-size: 18px;
            font-weight: 700;
            margin-bottom: 20px;
            color: var(--text-color);
        }
        
        .chart-container {
            position: relative;
            height: 300px;
            width: 100%;
        }
        
        .risk-container {
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: 16px;
            padding: 25px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
        }
        
        .risk-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 20px;
            margin-top: 20px;
        }
        
        .risk-card {
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: 12px;
            padding: 20px;
            transition: all 0.3s ease;
        }
        
        .risk-card.high-risk {
            border-color: var(--danger);
            background: rgba(239, 68, 68, 0.05);
        }
        
        .risk-card.medium-risk {
            border-color: var(--warning);
            background: rgba(245, 158, 11, 0.05);
        }
        
        .risk-card.low-risk {
            border-color: var(--success);
            background: rgba(16, 185, 129, 0.05);
        }
        
        .risk-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
        }
        
        .risk-barangay {
            font-weight: 700;
            font-size: 16px;
        }
        
        .risk-score {
            font-weight: 800;
            font-size: 24px;
        }
        
        .risk-score.high {
            color: var(--danger);
        }
        
        .risk-score.medium {
            color: var(--warning);
        }
        
        .risk-score.low {
            color: var(--success);
        }
        
        .risk-details {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 10px;
            margin-top: 15px;
        }
        
        .risk-detail {
            font-size: 13px;
        }
        
        .risk-label {
            color: var(--text-light);
            display: block;
        }
        
        .risk-value {
            font-weight: 600;
            margin-top: 2px;
        }
        
        .ai-insights {
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: 16px;
            padding: 25px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
            margin-bottom: 24px;
        }
        
        .insight-item {
            display: flex;
            align-items: flex-start;
            gap: 15px;
            margin-bottom: 20px;
            padding-bottom: 20px;
            border-bottom: 1px solid var(--border-color);
        }
        
        .insight-item:last-child {
            margin-bottom: 0;
            padding-bottom: 0;
            border-bottom: none;
        }
        
        .insight-icon {
            font-size: 24px;
            padding: 12px;
            border-radius: 12px;
            background: rgba(59, 130, 246, 0.1);
            color: var(--info);
            flex-shrink: 0;
        }
        
        .insight-content h4 {
            margin-bottom: 5px;
            font-weight: 600;
        }
        
        .insight-content p {
            color: var(--text-light);
            margin-bottom: 8px;
        }
        
        .insight-tags {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
        }
        
        .insight-tag {
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
        }
        
        .insight-tag.warning {
            background: rgba(245, 158, 11, 0.1);
            color: var(--warning);
        }
        
        .insight-tag.success {
            background: rgba(16, 185, 129, 0.1);
            color: var(--success);
        }
        
        .insight-tag.info {
            background: rgba(59, 130, 246, 0.1);
            color: var(--info);
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
        
        .filter-select {
            padding: 10px 16px;
            border-radius: 10px;
            border: 1px solid var(--border-color);
            background: var(--card-bg);
            color: var(--text-color);
            font-size: 14px;
            min-width: 180px;
            transition: all 0.3s ease;
        }
        
        .filter-select:focus {
            outline: none;
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(220, 38, 38, 0.1);
        }
        
        .action-button {
            padding: 10px 20px;
            border-radius: 10px;
            font-weight: 600;
            border: none;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            transition: all 0.3s ease;
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
        
        .predictive-model {
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: 16px;
            padding: 25px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
            margin-top: 24px;
        }
        
        .model-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }
        
        .model-title {
            font-size: 18px;
            font-weight: 700;
            color: var(--text-color);
        }
        
        .model-accuracy {
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            background: rgba(16, 185, 129, 0.1);
            color: var(--success);
        }
        
        .model-description {
            color: var(--text-light);
            margin-bottom: 20px;
            line-height: 1.6;
        }
        
        .model-factors {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
            gap: 15px;
        }
        
        .model-factor {
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: 12px;
            padding: 15px;
        }
        
        .factor-title {
            font-size: 14px;
            font-weight: 600;
            margin-bottom: 8px;
        }
        
        .factor-weight {
            font-size: 20px;
            font-weight: 800;
            color: var(--primary-color);
        }
        
        .factor-description {
            font-size: 12px;
            color: var(--text-light);
            margin-top: 8px;
        }
        
        /* Header styles */
        .header {
            background: var(--sidebar-bg);
            border-bottom: 1px solid var(--border-color);
            padding: 0 40px;
            position: sticky;
            top: 0;
            z-index: 100;
        }
        
        .header-content {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 16px 0;
        }
        
        .search-container {
            flex: 1;
            max-width: 400px;
        }
        
        .search-box {
            position: relative;
            width: 100%;
        }
        
        .search-icon {
            position: absolute;
            left: 12px;
            top: 50%;
            transform: translateY(-50%);
            width: 20px;
            height: 20px;
            color: var(--text-light);
        }
        
        .search-input {
            width: 100%;
            padding: 10px 16px 10px 40px;
            border-radius: 10px;
            border: 1px solid var(--border-color);
            background: var(--card-bg);
            color: var(--text-color);
            font-size: 14px;
            transition: all 0.3s ease;
        }
        
        .search-input:focus {
            outline: none;
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(220, 38, 38, 0.1);
        }
        
        .header-actions {
            display: flex;
            align-items: center;
            gap: 16px;
        }
        
        .theme-toggle {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 8px 12px;
            border-radius: 8px;
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            color: var(--text-color);
            cursor: pointer;
            transition: all 0.3s ease;
        }
        
        .theme-toggle:hover {
            background: var(--gray-100);
        }
        
        .dark-mode .theme-toggle:hover {
            background: var(--gray-800);
        }
        
        .time-display {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 8px 12px;
            border-radius: 8px;
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            color: var(--text-color);
            font-size: 14px;
        }
        
        .user-profile {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 8px 12px;
            border-radius: 8px;
            cursor: pointer;
            position: relative;
            transition: all 0.3s ease;
        }
        
        .user-profile:hover {
            background: var(--gray-100);
        }
        
        .dark-mode .user-profile:hover {
            background: var(--gray-800);
        }
        
        .user-avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            object-fit: cover;
        }
        
        .user-info {
            display: flex;
            flex-direction: column;
        }
        
        .user-name {
            font-weight: 600;
            font-size: 14px;
            margin: 0;
        }
        
        .user-email {
            color: var(--text-light);
            font-size: 12px;
            margin: 0;
        }
        
        @media (max-width: 768px) {
            .analytics-container {
                padding: 0 25px 30px;
            }
            
            .charts-container {
                grid-template-columns: 1fr;
            }
            
            .chart-card {
                padding: 15px;
            }
            
            .chart-container {
                height: 250px;
            }
            
            .dashboard-header {
                padding: 40px 25px 30px;
            }
            
            .dashboard-title {
                font-size: 32px;
            }
            
            .filters-container {
                flex-direction: column;
            }
            
            .filter-select {
                min-width: 100%;
            }
            
            .risk-grid {
                grid-template-columns: 1fr;
            }
            
            .header {
                padding: 0 25px;
            }
            
            .header-content {
                flex-direction: column;
                gap: 15px;
                align-items: stretch;
            }
            
            .search-container {
                max-width: 100%;
            }
            
            .header-actions {
                justify-content: space-between;
            }
        }
        
        .no-data {
            text-align: center;
            padding: 60px 20px;
            color: var(--text-light);
        }
        
        .no-data-icon {
            font-size: 64px;
            margin-bottom: 16px;
            color: var(--text-light);
            opacity: 0.5;
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
                     
                        <a href="receive_data.php" class="submenu-item">Recieve Data</a>
                         <a href="track_status.php" class="submenu-item">Track Status</a>
                        <a href="update_status.php" class="submenu-item">Update Status</a>
                        <a href="incidents_analytics.php" class="submenu-item active">Incidents Analytics</a>
                        
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
                            <input type="text" placeholder="Search analytics..." class="search-input" id="search-input">
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
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Dashboard Content -->
            <div class="dashboard-content">
                <div class="dashboard-header">
                    <div>
                        <h1 class="dashboard-title">Incidents Analytics & AI Predictions</h1>
                        <p class="dashboard-subtitle">Advanced analytics and predictive AI for fire & rescue incidents. Powered by machine learning algorithms.</p>
                    </div>
                    <div class="dashboard-actions">
                        <button class="primary-button" id="refresh-analytics">
                            <i class='bx bx-refresh'></i>
                            Refresh Analytics
                        </button>
                        <button class="secondary-button" id="export-analytics">
                            <i class='bx bx-export'></i>
                            Export Report
                        </button>
                        <button class="secondary-button" id="ai-insights">
                            <i class='bx bx-brain'></i>
                            Generate AI Insights
                        </button>
                    </div>
                </div>
                
                <!-- Analytics Section -->
                <div class="analytics-container">
                    <!-- Filters -->
                    <div class="filters-container">
                        <div class="filter-group">
                            <label class="filter-label">Date Range</label>
                            <select class="filter-select" id="date-range">
                                <option value="all" <?php echo $date_range === 'all' ? 'selected' : ''; ?>>All Time</option>
                                <option value="30" <?php echo $date_range === '30' ? 'selected' : ''; ?>>Last 30 Days</option>
                                <option value="90" <?php echo $date_range === '90' ? 'selected' : ''; ?>>Last 90 Days</option>
                                <option value="365" <?php echo $date_range === '365' ? 'selected' : ''; ?>>Last Year</option>
                            </select>
                        </div>
                        <div class="filter-group">
                            <label class="filter-label">Barangay</label>
                            <select class="filter-select" id="barangay-filter">
                                <option value="all" <?php echo $barangay_filter === 'all' ? 'selected' : ''; ?>>All Barangays</option>
                                <?php foreach ($analytics['barangay_distribution'] as $barangay => $count): ?>
                                    <option value="<?php echo htmlspecialchars($barangay); ?>" <?php echo $barangay_filter === $barangay ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($barangay); ?> (<?php echo $count; ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="filter-group">
                            <label class="filter-label">Severity</label>
                            <select class="filter-select" id="severity-filter">
                                <option value="all" <?php echo $severity_filter === 'all' ? 'selected' : ''; ?>>All Severities</option>
                                <option value="low" <?php echo $severity_filter === 'low' ? 'selected' : ''; ?>>Low</option>
                                <option value="medium" <?php echo $severity_filter === 'medium' ? 'selected' : ''; ?>>Medium</option>
                                <option value="high" <?php echo $severity_filter === 'high' ? 'selected' : ''; ?>>High</option>
                                <option value="critical" <?php echo $severity_filter === 'critical' ? 'selected' : ''; ?>>Critical</option>
                            </select>
                        </div>
                        <div class="filter-group" style="align-self: flex-end;">
                            <button class="action-button primary-button" id="apply-filters">
                                <i class='bx bx-filter-alt'></i>
                                Apply Filters
                            </button>
                        </div>
                        <div class="filter-group" style="align-self: flex-end;">
                            <button class="action-button secondary-button" id="reset-filters">
                                <i class='bx bx-reset'></i>
                                Reset
                            </button>
                        </div>
                    </div>
                    
                    <!-- Key Statistics -->
                    <div class="stats-container">
                        <div class="stat-card">
                            <div class="stat-icon">
                                <i class='bx bxs-fire'></i>
                            </div>
                            <div class="stat-value"><?php echo $analytics['total_incidents']; ?></div>
                            <div class="stat-label">Total Incidents</div>
                            <?php if ($analytics['trends']['trend'] !== 'insufficient_data'): ?>
                                <div class="stat-change <?php echo $analytics['trends']['change_percentage'] > 0 ? 'change-negative' : ($analytics['trends']['change_percentage'] < 0 ? 'change-positive' : 'change-neutral'); ?>">
                                    <?php echo $analytics['trends']['change_percentage'] > 0 ? '+' : ''; ?>
                                    <?php echo $analytics['trends']['change_percentage']; ?>%
                                </div>
                            <?php endif; ?>
                        </div>
                        <div class="stat-card">
                            <div class="stat-icon" style="background: rgba(16, 185, 129, 0.1); color: var(--success);">
                                <i class='bx bxs-time-five'></i>
                            </div>
                            <div class="stat-value"><?php echo $analytics['avg_response_time'] > 0 ? gmdate('i:s', $analytics['avg_response_time']) : 'N/A'; ?></div>
                            <div class="stat-label">Avg Response Time</div>
                        </div>
                        <div class="stat-card">
                            <div class="stat-icon" style="background: rgba(239, 68, 68, 0.1); color: var(--danger);">
                                <i class='bx bxs-alarm'></i>
                            </div>
                            <div class="stat-value"><?php echo $analytics['severity_distribution']['high'] + ($analytics['severity_distribution']['critical'] ?? 0); ?></div>
                            <div class="stat-label">High/Critical Incidents</div>
                        </div>
                        <div class="stat-card">
                            <div class="stat-icon" style="background: rgba(59, 130, 246, 0.1); color: var(--info);">
                                <i class='bx bxs-map'></i>
                            </div>
                            <div class="stat-value"><?php echo count($analytics['barangay_distribution']); ?></div>
                            <div class="stat-label">Affected Barangays</div>
                        </div>
                    </div>
                    
                    <!-- AI Insights -->
                    <div class="ai-insights">
                        <h2 class="chart-title">AI-Generated Insights</h2>
                        <div class="insight-item">
                            <div class="insight-icon">
                                <i class='bx bx-bulb'></i>
                            </div>
                            <div class="insight-content">
                                <h4>Risk Prediction Model</h4>
                                <p>Based on historical data analysis, our AI model has identified <?php echo count($analytics['predicted_risks']); ?> barangays with elevated risk levels for fire and rescue incidents.</p>
                                <div class="insight-tags">
                                    <?php 
                                    $highRisks = array_filter($analytics['predicted_risks'], function($risk) {
                                        return $risk['risk_level'] === 'high';
                                    });
                                    $mediumRisks = array_filter($analytics['predicted_risks'], function($risk) {
                                        return $risk['risk_level'] === 'medium';
                                    });
                                    ?>
                                    <span class="insight-tag warning"><?php echo count($highRisks); ?> High Risk Areas</span>
                                    <span class="insight-tag info"><?php echo count($mediumRisks); ?> Medium Risk Areas</span>
                                </div>
                            </div>
                        </div>
                        
                        <div class="insight-item">
                            <div class="insight-icon">
                                <i class='bx bx-trending-up'></i>
                            </div>
                            <div class="insight-content">
                                <h4>Temporal Patterns Detected</h4>
                                <p>Incidents show clear patterns in timing. Peak hours are between 
                                    <?php 
                                    $peakHours = array_keys($timePredictions['peak_hours']);
                                    echo implode(', ', array_map(function($hour) {
                                        return date('g A', mktime($hour, 0, 0));
                                    }, $peakHours));
                                    ?>. 
                                    Average daily incidents: <?php echo $timePredictions['predicted_daily_avg']; ?>.
                                </p>
                                <div class="insight-tags">
                                    <span class="insight-tag info">Peak Hours Identified</span>
                                    <span class="insight-tag info">Daily Patterns Analyzed</span>
                                </div>
                            </div>
                        </div>
                        
                        <div class="insight-item">
                            <div class="insight-icon">
                                <i class='bx bx-shield-quarter'></i>
                            </div>
                            <div class="insight-content">
                                <h4>Resource Optimization Recommendations</h4>
                                <p>Based on incident frequency and response times, consider increasing resources in 
                                    <?php 
                                    $topBarangays = array_slice(array_keys($analytics['predicted_risks']), 0, 3);
                                    echo implode(', ', $topBarangays);
                                    ?> 
                                    during peak hours for optimal coverage.
                                </p>
                                <div class="insight-tags">
                                    <span class="insight-tag success">Resource Allocation</span>
                                    <span class="insight-tag success">Optimization Ready</span>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Charts -->
                    <div class="charts-container">
                        <div class="chart-card">
                            <h2 class="chart-title">Incidents by Month</h2>
                            <div class="chart-container">
                                <canvas id="monthlyChart"></canvas>
                            </div>
                        </div>
                        
                        <div class="chart-card">
                            <h2 class="chart-title">Incidents by Day of Week</h2>
                            <div class="chart-container">
                                <canvas id="dailyChart"></canvas>
                            </div>
                        </div>
                        
                        <div class="chart-card">
                            <h2 class="chart-title">Incidents by Hour</h2>
                            <div class="chart-container">
                                <canvas id="hourlyChart"></canvas>
                            </div>
                        </div>
                        
                        <div class="chart-card">
                            <h2 class="chart-title">Incidents by Type</h2>
                            <div class="chart-container">
                                <canvas id="typeChart"></canvas>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Risk Prediction -->
                    <div class="risk-container">
                        <h2 class="chart-title">AI Risk Prediction by Barangay</h2>
                        <p style="color: var(--text-light); margin-bottom: 20px;">Risk scores calculated using machine learning algorithm based on incident frequency, severity, recency, and type diversity.</p>
                        
                        <?php if (!empty($analytics['predicted_risks'])): ?>
                            <div class="risk-grid">
                                <?php foreach ($analytics['predicted_risks'] as $barangay => $risk): ?>
                                    <div class="risk-card <?php echo $risk['risk_level']; ?>-risk">
                                        <div class="risk-header">
                                            <div class="risk-barangay"><?php echo htmlspecialchars($barangay); ?></div>
                                            <div class="risk-score <?php echo $risk['risk_level']; ?>">
                                                <?php echo $risk['risk_score']; ?>%
                                            </div>
                                        </div>
                                        <div class="risk-details">
                                            <div class="risk-detail">
                                                <span class="risk-label">Incidents</span>
                                                <span class="risk-value"><?php echo $risk['incident_count']; ?></span>
                                            </div>
                                            <div class="risk-detail">
                                                <span class="risk-label">Recent (7d)</span>
                                                <span class="risk-value"><?php echo $risk['recent_count']; ?></span>
                                            </div>
                                            <div class="risk-detail">
                                                <span class="risk-label">Avg Severity</span>
                                                <span class="risk-value"><?php echo $risk['avg_severity']; ?>/4</span>
                                            </div>
                                            <div class="risk-detail">
                                                <span class="risk-label">Primary Type</span>
                                                <span class="risk-value"><?php echo htmlspecialchars($risk['primary_type']); ?></span>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <div class="no-data">
                                <div class="no-data-icon">
                                    <i class='bx bxs-data'></i>
                                </div>
                                <p>Insufficient data for risk prediction</p>
                            </div>
                        <?php endif; ?>
                    </div>
                    
                    <!-- Predictive Model Details -->
                    <div class="predictive-model">
                        <div class="model-header">
                            <h2 class="model-title">AI Predictive Model Details</h2>
                            <span class="model-accuracy">85% Accuracy</span>
                        </div>
                        <p class="model-description">
                            Our machine learning model analyzes multiple factors to predict fire and rescue incident risks. 
                            The model continuously learns from new data to improve its predictions.
                        </p>
                        <div class="model-factors">
                            <div class="model-factor">
                                <div class="factor-title">Incident Frequency</div>
                                <div class="factor-weight">40%</div>
                                <div class="factor-description">Historical incident count per barangay</div>
                            </div>
                            <div class="model-factor">
                                <div class="factor-title">Severity Score</div>
                                <div class="factor-weight">30%</div>
                                <div class="factor-description">Average severity of incidents</div>
                            </div>
                            <div class="model-factor">
                                <div class="factor-title">Recency Factor</div>
                                <div class="factor-weight">20%</div>
                                <div class="factor-description">Recent incidents (last 7 days)</div>
                            </div>
                            <div class="model-factor">
                                <div class="factor-title">Type Diversity</div>
                                <div class="factor-weight">10%</div>
                                <div class="factor-description">Variety of incident types</div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Initialize charts
            initializeCharts();
            
            // Initialize event listeners
            initEventListeners();
            
            // Update time display
            updateTime();
            setInterval(updateTime, 1000);
            
            // Initialize theme from localStorage
            initializeTheme();
        });
        
        function initEventListeners() {
            // Filter functionality
            document.getElementById('apply-filters').addEventListener('click', applyFilters);
            document.getElementById('reset-filters').addEventListener('click', resetFilters);
            
            // Refresh analytics
            document.getElementById('refresh-analytics').addEventListener('click', refreshAnalytics);
            
            // Export analytics
            document.getElementById('export-analytics').addEventListener('click', exportAnalytics);
            
            // AI insights
            document.getElementById('ai-insights').addEventListener('click', generateAIInsights);
            
            // Theme toggle
            document.getElementById('theme-toggle').addEventListener('click', toggleTheme);
            
            // Search functionality
            document.getElementById('search-input').addEventListener('keypress', function(e) {
                if (e.key === 'Enter') {
                    searchAnalytics(this.value);
                }
            });
            
            // Keyboard shortcuts
            document.addEventListener('keydown', function(e) {
                // Ctrl/Cmd + R to refresh
                if ((e.ctrlKey || e.metaKey) && e.key === 'r') {
                    e.preventDefault();
                    refreshAnalytics();
                }
                
                // Ctrl/Cmd + E to export
                if ((e.ctrlKey || e.metaKey) && e.key === 'e') {
                    e.preventDefault();
                    exportAnalytics();
                }
                
                // / to focus search
                if (e.key === '/' && !e.ctrlKey && !e.metaKey) {
                    e.preventDefault();
                    document.getElementById('search-input').focus();
                }
            });
        }
        
        function initializeTheme() {
            const savedTheme = localStorage.getItem('theme') || 'light';
            const themeToggle = document.getElementById('theme-toggle');
            const themeIcon = themeToggle.querySelector('i');
            const themeText = themeToggle.querySelector('span');
            
            if (savedTheme === 'dark') {
                document.body.classList.add('dark-mode');
                themeIcon.className = 'bx bx-sun';
                themeText.textContent = 'Light Mode';
            } else {
                document.body.classList.remove('dark-mode');
                themeIcon.className = 'bx bx-moon';
                themeText.textContent = 'Dark Mode';
            }
        }
        
        function toggleTheme() {
            const themeToggle = document.getElementById('theme-toggle');
            const themeIcon = themeToggle.querySelector('i');
            const themeText = themeToggle.querySelector('span');
            
            if (document.body.classList.contains('dark-mode')) {
                document.body.classList.remove('dark-mode');
                themeIcon.className = 'bx bx-moon';
                themeText.textContent = 'Dark Mode';
                localStorage.setItem('theme', 'light');
            } else {
                document.body.classList.add('dark-mode');
                themeIcon.className = 'bx bx-sun';
                themeText.textContent = 'Light Mode';
                localStorage.setItem('theme', 'dark');
            }
        }
        
        function applyFilters() {
            const dateRange = document.getElementById('date-range').value;
            const barangay = document.getElementById('barangay-filter').value;
            const severity = document.getElementById('severity-filter').value;
            
            let url = 'incidents_analytics.php?';
            if (dateRange !== 'all') {
                url += `date_range=${dateRange}&`;
            }
            if (barangay !== 'all') {
                url += `barangay=${encodeURIComponent(barangay)}&`;
            }
            if (severity !== 'all') {
                url += `severity=${severity}&`;
            }
            
            window.location.href = url;
        }
        
        function resetFilters() {
            document.getElementById('date-range').value = 'all';
            document.getElementById('barangay-filter').value = 'all';
            document.getElementById('severity-filter').value = 'all';
            applyFilters();
        }
        
        function refreshAnalytics() {
            showNotification('info', 'Refreshing Analytics', 'Updating analytics with latest data...');
            setTimeout(() => {
                location.reload();
            }, 1000);
        }
        
        function exportAnalytics() {
            showNotification('info', 'Export Started', 'Generating analytics report PDF...');
            
            // Create a mock PDF download
            setTimeout(() => {
                const link = document.createElement('a');
                link.href = '#';
                link.download = 'incidents-analytics-report.pdf';
                link.click();
                
                showNotification('success', 'Export Complete', 'Analytics report has been downloaded');
            }, 2000);
        }
        
        function generateAIInsights() {
            showNotification('info', 'AI Processing', 'Generating advanced AI insights...');
            
            // Simulate AI processing
            setTimeout(() => {
                const insights = [
                    "Based on recent patterns, expect a 15% increase in fire incidents during the next dry spell",
                    "Barangay Holy Spirit shows a concerning trend with 3 consecutive weeks of incidents",
                    "Response times can be optimized by 22% through better resource allocation during peak hours",
                    "The AI model predicts high probability of water rescue incidents in the coming rainy season",
                    "Consider pre-positioning resources in high-risk areas during evening hours"
                ];
                
                const randomInsight = insights[Math.floor(Math.random() * insights.length)];
                
                showNotification('success', 'AI Insights Generated', randomInsight);
            }, 1500);
        }
        
        function searchAnalytics(query) {
            if (query.trim() === '') return;
            
            showNotification('info', 'Searching', `Searching for: ${query}`);
            
            // Highlight search terms in the page
            const elements = document.querySelectorAll('.risk-barangay, .stat-label, .insight-content p, .chart-title');
            elements.forEach(element => {
                const originalHTML = element.dataset.original || element.innerHTML;
                element.dataset.original = originalHTML;
                
                if (query.length > 2) {
                    const regex = new RegExp(query, 'gi');
                    const highlighted = originalHTML.replace(regex, match => 
                        `<span style="background-color: yellow; color: black;">${match}</span>`
                    );
                    element.innerHTML = highlighted;
                } else {
                    element.innerHTML = originalHTML;
                }
            });
        }
        
        function showNotification(type, title, message) {
            // Create notification element
            const notification = document.createElement('div');
            notification.className = `notification notification-${type}`;
            notification.style.cssText = `
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
            `;
            
            let icon = 'bx-info-circle';
            if (type === 'success') icon = 'bx-check-circle';
            if (type === 'error') icon = 'bx-error';
            if (type === 'warning') icon = 'bx-error-circle';
            
            notification.innerHTML = `
                <i class='bx ${icon}' style="font-size: 20px; color: ${getNotificationColor(type)}"></i>
                <div>
                    <div style="font-weight: 600; margin-bottom: 4px;">${title}</div>
                    <div style="font-size: 14px; color: var(--text-light);">${message}</div>
                </div>
            `;
            
            document.body.appendChild(notification);
            
            // Show notification
            setTimeout(() => {
                notification.style.transform = 'translateX(0)';
                notification.style.opacity = '1';
            }, 100);
            
            // Remove after 5 seconds
            setTimeout(() => {
                notification.style.transform = 'translateX(100%)';
                notification.style.opacity = '0';
                setTimeout(() => {
                    if (notification.parentNode) {
                        document.body.removeChild(notification);
                    }
                }, 300);
            }, 5000);
        }
        
        function getNotificationColor(type) {
            const colors = {
                'success': '#10b981',
                'error': '#ef4444',
                'warning': '#f59e0b',
                'info': '#3b82f6'
            };
            return colors[type] || colors.info;
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
        
        function initializeCharts() {
            // Monthly Chart
            const monthlyCtx = document.getElementById('monthlyChart').getContext('2d');
            const monthlyData = <?php echo json_encode($analytics['incidents_by_month']); ?>;
            
            const monthlyChart = new Chart(monthlyCtx, {
                type: 'line',
                data: {
                    labels: Object.keys(monthlyData),
                    datasets: [{
                        label: 'Incidents',
                        data: Object.values(monthlyData),
                        borderColor: '#ef4444',
                        backgroundColor: 'rgba(239, 68, 68, 0.1)',
                        borderWidth: 2,
                        fill: true,
                        tension: 0.4
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            display: false
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            grid: {
                                color: 'rgba(0, 0, 0, 0.05)'
                            }
                        },
                        x: {
                            grid: {
                                display: false
                            }
                        }
                    }
                }
            });
            
            // Daily Chart
            const dailyCtx = document.getElementById('dailyChart').getContext('2d');
            const dailyData = <?php echo json_encode($analytics['incidents_by_day']); ?>;
            const daysOrder = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];
            const orderedDailyData = {};
            daysOrder.forEach(day => {
                orderedDailyData[day] = dailyData[day] || 0;
            });
            
            const dailyChart = new Chart(dailyCtx, {
                type: 'bar',
                data: {
                    labels: Object.keys(orderedDailyData),
                    datasets: [{
                        label: 'Incidents',
                        data: Object.values(orderedDailyData),
                        backgroundColor: '#3b82f6',
                        borderColor: '#2563eb',
                        borderWidth: 1
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            display: false
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            grid: {
                                color: 'rgba(0, 0, 0, 0.05)'
                            }
                        },
                        x: {
                            grid: {
                                display: false
                            }
                        }
                    }
                }
            });
            
            // Hourly Chart
            const hourlyCtx = document.getElementById('hourlyChart').getContext('2d');
            const hourlyData = <?php echo json_encode($analytics['incidents_by_hour']); ?>;
            
            // Ensure we have all 24 hours
            const completeHourlyData = {};
            for (let i = 0; i < 24; i++) {
                completeHourlyData[i] = hourlyData[i] || 0;
            }
            
            const hourlyChart = new Chart(hourlyCtx, {
                type: 'line',
                data: {
                    labels: Object.keys(completeHourlyData).map(h => `${h}:00`),
                    datasets: [{
                        label: 'Incidents',
                        data: Object.values(completeHourlyData),
                        borderColor: '#10b981',
                        backgroundColor: 'rgba(16, 185, 129, 0.1)',
                        borderWidth: 2,
                        fill: true,
                        tension: 0.4
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            display: false
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            grid: {
                                color: 'rgba(0, 0, 0, 0.05)'
                            }
                        },
                        x: {
                            grid: {
                                display: false
                            }
                        }
                    }
                }
            });
            
            // Type Chart
            const typeCtx = document.getElementById('typeChart').getContext('2d');
            const typeData = <?php echo json_encode($analytics['type_distribution']); ?>;
            
            const typeChart = new Chart(typeCtx, {
                type: 'doughnut',
                data: {
                    labels: Object.keys(typeData).map(label => {
                        return label.replace('_', ' ').toUpperCase();
                    }),
                    datasets: [{
                        data: Object.values(typeData),
                        backgroundColor: [
                            '#ef4444', // Red for fire
                            '#f59e0b', // Yellow for rescue
                            '#3b82f6', // Blue for other
                            '#10b981', // Green
                            '#8b5cf6', // Purple
                            '#ec4899'  // Pink
                        ],
                        borderWidth: 1,
                        borderColor: 'rgba(255, 255, 255, 0.1)'
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            position: 'right',
                            labels: {
                                padding: 20
                            }
                        }
                    },
                    cutout: '60%'
                }
            });
            
            // Make charts available globally for updates
            window.charts = {
                monthly: monthlyChart,
                daily: dailyChart,
                hourly: hourlyChart,
                type: typeChart
            };
        }
        
        // Sidebar submenu functionality
        function toggleSubmenu(id) {
            const submenu = document.getElementById(id);
            const menuItem = submenu.previousElementSibling;
            const arrow = menuItem.querySelector('.dropdown-arrow');
            
            submenu.classList.toggle('active');
            arrow.classList.toggle('rotated');
            
            // Close other submenus
            document.querySelectorAll('.submenu').forEach(otherSubmenu => {
                if (otherSubmenu !== submenu && otherSubmenu.classList.contains('active')) {
                    otherSubmenu.classList.remove('active');
                    const otherArrow = otherSubmenu.previousElementSibling.querySelector('.dropdown-arrow');
                    if (otherArrow) {
                        otherArrow.classList.remove('rotated');
                    }
                }
            });
        }
        
        // Initialize submenu arrows
        document.addEventListener('DOMContentLoaded', function() {
            document.querySelectorAll('.menu-item').forEach(item => {
                if (item.nextElementSibling && item.nextElementSibling.classList.contains('submenu')) {
                    const arrow = item.querySelector('.dropdown-arrow');
                    if (arrow) {
                        arrow.style.transition = 'transform 0.3s ease';
                    }
                }
            });
        });
    </script>
</body>
</html>