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
$filter_status = isset($_GET['status']) ? $_GET['status'] : 'all';
$filter_date = isset($_GET['date']) ? $_GET['date'] : '';
$search_query = isset($_GET['search']) ? $_GET['search'] : '';
$filter_barangay = isset($_GET['barangay']) ? $_GET['barangay'] : '';
$filter_establishment_type = isset($_GET['establishment_type']) ? $_GET['establishment_type'] : '';
$filter_certificate_type = isset($_GET['certificate_type']) ? $_GET['certificate_type'] : '';

// Handle certificate generation
if (isset($_GET['generate_certificate'])) {
    $certificate_id = (int)$_GET['generate_certificate'];
    generateCertificatePDF($certificate_id, $pdo);
    exit();
}

// Handle certificate revocation
if (isset($_POST['revoke_certificate'])) {
    $certificate_id = (int)$_POST['certificate_id'];
    $revoked_reason = $_POST['revoked_reason'];
    
    $query = "UPDATE inspection_certificates 
              SET revoked = 1, revoked_at = NOW(), revoked_reason = ?, revoked_by = ?
              WHERE id = ?";
    $stmt = $pdo->prepare($query);
    $stmt->execute([$revoked_reason, $user_id, $certificate_id]);
    
    // Update the establishment's last inspection date
    $query = "UPDATE inspection_establishments ie
              INNER JOIN inspection_certificates ic ON ie.id = ic.establishment_id
              SET ie.last_inspection_date = ic.issue_date
              WHERE ic.id = ?";
    $stmt = $pdo->prepare($query);
    $stmt->execute([$certificate_id]);
    
    header("Location: issue_certificates.php?success=Certificate+revoked+successfully");
    exit();
}

// Get all certificates for display
function getCertificates($pdo, $filter_status = 'all', $filter_date = '', $search_query = '', $filter_barangay = '', $filter_establishment_type = '', $filter_certificate_type = '') {
    $sql = "SELECT 
                ic.id,
                ic.certificate_number,
                ic.certificate_type,
                ic.certificate_type_full,
                ic.issue_date,
                ic.valid_until,
                ic.revoked,
                ic.revoked_at,
                ic.revoked_reason,
                ic.revoked_by,
                CONCAT(issuer.first_name, ' ', issuer.last_name) as issued_by_name,
                CONCAT(revoker.first_name, ' ', revoker.last_name) as revoked_by_name,
                ie.establishment_name,
                ie.establishment_type,
                ie.barangay,
                ie.address,
                ie.owner_name,
                ir.report_number,
                ir.inspection_date,
                ir.overall_compliance_score,
                ir.risk_assessment,
                ir.fire_hazard_level,
                DATEDIFF(ic.valid_until, CURDATE()) as days_remaining,
                CASE 
                    WHEN ic.revoked = 1 THEN 'revoked'
                    WHEN ic.valid_until < CURDATE() THEN 'expired'
                    WHEN DATEDIFF(ic.valid_until, CURDATE()) <= 30 THEN 'expiring_soon'
                    ELSE 'valid'
                END as validity_status
            FROM inspection_certificates ic
            LEFT JOIN inspection_establishments ie ON ic.establishment_id = ie.id
            LEFT JOIN inspection_reports ir ON ic.inspection_id = ir.id
            LEFT JOIN users issuer ON ic.issued_by = issuer.id
            LEFT JOIN users revoker ON ic.revoked_by = revoker.id
            WHERE 1=1";
    
    $params = [];
    
    // Apply status filter
    if ($filter_status !== 'all') {
        if ($filter_status === 'valid') {
            $sql .= " AND ic.valid_until >= CURDATE() AND ic.revoked = 0";
        } elseif ($filter_status === 'expired') {
            $sql .= " AND ic.valid_until < CURDATE() AND ic.revoked = 0";
        } elseif ($filter_status === 'expiring_soon') {
            $sql .= " AND ic.valid_until >= CURDATE() AND ic.valid_until <= DATE_ADD(CURDATE(), INTERVAL 30 DAY) AND ic.revoked = 0";
        } elseif ($filter_status === 'revoked') {
            $sql .= " AND ic.revoked = 1";
        }
    }
    
    // Apply certificate type filter
    if (!empty($filter_certificate_type)) {
        $sql .= " AND ic.certificate_type = ?";
        $params[] = $filter_certificate_type;
    }
    
    // Apply date filter
    if (!empty($filter_date)) {
        if ($filter_date === 'today') {
            $sql .= " AND DATE(ic.issue_date) = CURDATE()";
        } elseif ($filter_date === 'yesterday') {
            $sql .= " AND DATE(ic.issue_date) = DATE_SUB(CURDATE(), INTERVAL 1 DAY)";
        } elseif ($filter_date === 'week') {
            $sql .= " AND ic.issue_date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)";
        } elseif ($filter_date === 'month') {
            $sql .= " AND ic.issue_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)";
        } elseif ($filter_date === 'year') {
            $sql .= " AND ic.issue_date >= DATE_SUB(CURDATE(), INTERVAL 365 DAY)";
        }
    }
    
    // Apply barangay filter
    if (!empty($filter_barangay)) {
        $sql .= " AND ie.barangay LIKE ?";
        $params[] = "%$filter_barangay%";
    }
    
    // Apply establishment type filter
    if (!empty($filter_establishment_type)) {
        $sql .= " AND ie.establishment_type = ?";
        $params[] = $filter_establishment_type;
    }
    
    // Apply search query
    if (!empty($search_query)) {
        $sql .= " AND (
                    ic.certificate_number LIKE ? OR 
                    ie.establishment_name LIKE ? OR 
                    ie.owner_name LIKE ? OR 
                    ie.address LIKE ? OR 
                    ie.barangay LIKE ?
                )";
        $search_param = "%$search_query%";
        $params = array_merge($params, [
            $search_param, $search_param, $search_param, $search_param,
            $search_param
        ]);
    }
    
    $sql .= " ORDER BY ic.issue_date DESC, ic.valid_until ASC";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Get certificate statistics
function getCertificateStats($pdo) {
    $sql = "SELECT 
                COUNT(*) as total,
                SUM(CASE WHEN revoked = 1 THEN 1 ELSE 0 END) as revoked,
                SUM(CASE WHEN valid_until >= CURDATE() AND revoked = 0 THEN 1 ELSE 0 END) as valid,
                SUM(CASE WHEN valid_until < CURDATE() AND revoked = 0 THEN 1 ELSE 0 END) as expired,
                SUM(CASE WHEN valid_until >= CURDATE() AND valid_until <= DATE_ADD(CURDATE(), INTERVAL 30 DAY) AND revoked = 0 THEN 1 ELSE 0 END) as expiring_soon,
                SUM(CASE WHEN certificate_type = 'fsic' THEN 1 ELSE 0 END) as fsic,
                SUM(CASE WHEN certificate_type = 'compliance' THEN 1 ELSE 0 END) as compliance,
                SUM(CASE WHEN certificate_type = 'provisional' THEN 1 ELSE 0 END) as provisional,
                SUM(CASE WHEN certificate_type = 'exemption' THEN 1 ELSE 0 END) as exemption
            FROM inspection_certificates";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute();
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    
    $stats = [
        'total' => 0,
        'revoked' => 0,
        'valid' => 0,
        'expired' => 0,
        'expiring_soon' => 0,
        'fsic' => 0,
        'compliance' => 0,
        'provisional' => 0,
        'exemption' => 0
    ];
    
    if ($result) {
        $stats = array_merge($stats, $result);
    }
    
    return $stats;
}

// Get all barangays for filtering
function getBarangays($pdo) {
    $sql = "SELECT DISTINCT barangay FROM inspection_establishments WHERE barangay IS NOT NULL AND barangay != '' ORDER BY barangay";
    $stmt = $pdo->prepare($sql);
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_COLUMN, 0);
}

// Get all establishment types for filtering
function getEstablishmentTypes($pdo) {
    $sql = "SELECT DISTINCT establishment_type FROM inspection_establishments ORDER BY establishment_type";
    $stmt = $pdo->prepare($sql);
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_COLUMN, 0);
}

// Get certificate types for filtering
$certificate_types = [
    'fsic' => 'Fire Safety Inspection Certificate (FSIC)',
    'compliance' => 'Compliance Certificate',
    'provisional' => 'Provisional Certificate',
    'exemption' => 'Exemption Certificate'
];

// Generate certificate PDF using FPDF
function generateCertificatePDF($certificate_id, $pdo) {
    // Get certificate data
    $sql = "SELECT 
                ic.*,
                ie.establishment_name,
                ie.establishment_type,
                ie.address,
                ie.barangay,
                ie.owner_name,
                ie.business_permit_number,
                ir.report_number,
                ir.inspection_date,
                ir.overall_compliance_score,
                CONCAT(issuer.first_name, ' ', issuer.last_name) as issued_by_name
            FROM inspection_certificates ic
            LEFT JOIN inspection_establishments ie ON ic.establishment_id = ie.id
            LEFT JOIN inspection_reports ir ON ic.inspection_id = ir.id
            LEFT JOIN users issuer ON ic.issued_by = issuer.id
            WHERE ic.id = ?";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$certificate_id]);
    $certificate = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$certificate) {
        die('Certificate not found');
    }
    
    // Create PDF
    $pdf = new FPDF('L', 'mm', 'A4');
    $pdf->AddPage();
    
    // Set document properties
    $pdf->SetTitle('Fire Safety Certificate - ' . $certificate['certificate_number']);
    $pdf->SetAuthor('Barangay Commonwealth Fire & Rescue');
    $pdf->SetSubject('Fire Safety Certificate');
    
    // Add border
    $pdf->SetLineWidth(1.5);
    $pdf->Rect(10, 10, 277, 190); // A4 landscape: 297x210, minus margins
    
    // Add decorative border corners
    $pdf->SetLineWidth(0.5);
    $pdf->SetDrawColor(220, 38, 38);
    $pdf->Rect(15, 15, 267, 180);
    
    // Add header with logo
    $pdf->SetFont('Arial', 'B', 24);
    $pdf->SetTextColor(220, 38, 38);
    $pdf->SetXY(0, 20);
    $pdf->Cell(0, 10, 'FIRE & RESCUE MANAGEMENT SYSTEM', 0, 1, 'C');
    
    $pdf->SetFont('Arial', 'B', 18);
    $pdf->SetTextColor(0, 0, 0);
    $pdf->SetXY(0, 32);
    $pdf->Cell(0, 10, 'BARANGAY COMMONWEALTH, QUEZON CITY', 0, 1, 'C');
    
    $pdf->SetFont('Arial', 'B', 28);
    $pdf->SetTextColor(220, 38, 38);
    $pdf->SetXY(0, 48);
    $pdf->Cell(0, 10, strtoupper($certificate_types[$certificate['certificate_type']]), 0, 1, 'C');
    
    // Certificate number
    $pdf->SetFont('Arial', 'B', 16);
    $pdf->SetTextColor(0, 0, 0);
    $pdf->SetXY(0, 65);
    $pdf->Cell(0, 10, 'CERTIFICATE NUMBER: ' . $certificate['certificate_number'], 0, 1, 'C');
    
    // Horizontal line
    $pdf->SetLineWidth(0.5);
    $pdf->SetDrawColor(220, 38, 38);
    $pdf->Line(30, 78, 267, 78);
    
    // Certificate body
    $pdf->SetFont('Arial', '', 12);
    $pdf->SetXY(0, 85);
    $pdf->MultiCell(0, 8, 'This is to certify that:', 0, 'C');
    
    $pdf->SetFont('Arial', 'B', 14);
    $pdf->SetXY(0, 100);
    $pdf->Cell(0, 10, $certificate['establishment_name'], 0, 1, 'C');
    
    $pdf->SetFont('Arial', '', 12);
    $pdf->SetXY(0, 110);
    $pdf->Cell(0, 8, 'Located at: ' . $certificate['address'] . ', ' . $certificate['barangay'], 0, 1, 'C');
    
    $pdf->SetXY(0, 118);
    $pdf->Cell(0, 8, 'Owner/Proprietor: ' . $certificate['owner_name'], 0, 1, 'C');
    
    $pdf->SetFont('Arial', 'I', 11);
    $pdf->SetXY(0, 128);
    $pdf->MultiCell(0, 6, 'Has been inspected and found to be in substantial compliance with the Fire Code of the Philippines (RA 9514) and its implementing rules and regulations.', 0, 'C');
    
    // Compliance score
    $pdf->SetFont('Arial', 'B', 12);
    $pdf->SetXY(0, 148);
    $pdf->Cell(0, 8, 'Overall Compliance Score: ' . $certificate['overall_compliance_score'] . '%', 0, 1, 'C');
    
    // Validity period
    $pdf->SetFont('Arial', '', 12);
    $pdf->SetXY(0, 158);
    $pdf->Cell(0, 8, 'Issued on: ' . date('F j, Y', strtotime($certificate['issue_date'])), 0, 1, 'C');
    $pdf->SetXY(0, 166);
    $pdf->Cell(0, 8, 'Valid until: ' . date('F j, Y', strtotime($certificate['valid_until'])), 0, 1, 'C');
    
    // Horizontal line
    $pdf->SetLineWidth(0.5);
    $pdf->SetDrawColor(220, 38, 38);
    $pdf->Line(30, 178, 267, 178);
    
    // Signatures
    $pdf->SetFont('Arial', 'B', 12);
    $pdf->SetXY(50, 182);
    $pdf->Cell(80, 8, '___________________________', 0, 0, 'C');
    
    $pdf->SetXY(170, 182);
    $pdf->Cell(80, 8, '___________________________', 0, 0, 'C');
    
    $pdf->SetFont('Arial', '', 11);
    $pdf->SetXY(50, 190);
    $pdf->Cell(80, 8, 'Fire Safety Inspector', 0, 0, 'C');
    
    $pdf->SetXY(170, 190);
    $pdf->Cell(80, 8, 'Barangay Chairman', 0, 0, 'C');
    
    $pdf->SetFont('Arial', 'I', 9);
    $pdf->SetXY(0, 200);
    $pdf->Cell(0, 6, 'Note: This certificate is non-transferable and must be displayed prominently on the premises.', 0, 1, 'C');
    $pdf->SetXY(0, 206);
    $pdf->Cell(0, 6, 'For verification, contact Barangay Commonwealth Fire & Rescue at (02) 1234-5678', 0, 1, 'C');
    
    // Add QR code placeholder text
    $pdf->SetFont('Arial', '', 8);
    $pdf->SetXY(260, 10);
    $pdf->Cell(25, 8, 'QR Code', 0, 1, 'R');
    
    // Output PDF
    $pdf->Output('I', 'Certificate_' . $certificate['certificate_number'] . '.pdf');
}

// Get data for filters
$barangays = getBarangays($pdo);
$establishment_types = getEstablishmentTypes($pdo);

// Get certificates based on filters
$certificates = getCertificates($pdo, $filter_status, $filter_date, $search_query, $filter_barangay, $filter_establishment_type, $filter_certificate_type);
$stats = getCertificateStats($pdo);

// Date filter options
$date_options = [
    '' => 'All Dates',
    'today' => 'Today',
    'yesterday' => 'Yesterday',
    'week' => 'Last 7 Days',
    'month' => 'Last 30 Days',
    'year' => 'Last Year'
];

// Status options
$status_options = [
    'all' => 'All Certificates',
    'valid' => 'Valid',
    'expiring_soon' => 'Expiring Soon (≤30 days)',
    'expired' => 'Expired',
    'revoked' => 'Revoked'
];

// Certificate type options
$cert_type_options = [
    '' => 'All Types',
    'fsic' => 'Fire Safety Inspection Certificate',
    'compliance' => 'Compliance Certificate',
    'provisional' => 'Provisional Certificate',
    'exemption' => 'Exemption Certificate'
];

// Status colors
$status_colors = [
    'valid' => '#10b981',
    'expiring_soon' => '#f59e0b',
    'expired' => '#dc2626',
    'revoked' => '#6b7280'
];

// Format date helper
function formatDate($date) {
    if (!$date) return 'N/A';
    return date('M j, Y', strtotime($date));
}

// Get status badge HTML
function getCertificateStatusBadge($status, $days_remaining = 0) {
    global $status_colors;
    $status = strtolower($status);
    $color = $status_colors[$status] ?? '#6b7280';
    $text = ucfirst(str_replace('_', ' ', $status));
    
    if ($status === 'expiring_soon' && $days_remaining > 0) {
        $text .= " ({$days_remaining} days)";
    }
    
    return <<<HTML
        <span class="status-badge" style="background: rgba(${hexToRgb($color)}, 0.1); color: {$color}; border-color: rgba(${hexToRgb($color)}, 0.3);">
            {$text}
        </span>
    HTML;
}

// Helper function to convert hex to RGB
function hexToRgb($hex) {
    $hex = str_replace('#', '', $hex);
    if(strlen($hex) == 3) {
        $r = hexdec(substr($hex,0,1).substr($hex,0,1));
        $g = hexdec(substr($hex,1,1).substr($hex,1,1));
        $b = hexdec(substr($hex,2,1).substr($hex,2,1));
    } else {
        $r = hexdec(substr($hex,0,2));
        $g = hexdec(substr($hex,2,2));
        $b = hexdec(substr($hex,4,2));
    }
    return "$r, $g, $b";
}

// Check for success message
$success_message = isset($_GET['success']) ? urldecode($_GET['success']) : '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Issue Fire Safety Certificates - Admin - FRSM</title>
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
            --warm-gray: #78716c;
            
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

        /* Stats Container */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
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
            cursor: pointer;
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
        
        .stat-card[data-type="total"] .stat-icon-container {
            background: rgba(59, 130, 246, 0.1);
            color: var(--info);
        }
        
        .stat-card[data-type="valid"] .stat-icon-container {
            background: rgba(16, 185, 129, 0.1);
            color: var(--success);
        }
        
        .stat-card[data-type="expiring_soon"] .stat-icon-container {
            background: rgba(245, 158, 11, 0.1);
            color: var(--warning);
        }
        
        .stat-card[data-type="expired"] .stat-icon-container {
            background: rgba(220, 38, 38, 0.1);
            color: var(--danger);
        }
        
        .stat-card[data-type="revoked"] .stat-icon-container {
            background: rgba(107, 114, 128, 0.1);
            color: var(--gray-500);
        }
        
        .stat-card[data-type="fsic"] .stat-icon-container {
            background: rgba(139, 92, 246, 0.1);
            color: var(--purple);
        }
        
        .stat-card[data-type="compliance"] .stat-icon-container {
            background: rgba(99, 102, 241, 0.1);
            color: var(--indigo);
        }
        
        .stat-card[data-type="provisional"] .stat-icon-container {
            background: rgba(14, 165, 233, 0.1);
            color: var(--light-blue);
        }
        
        .stat-card[data-type="exemption"] .stat-icon-container {
            background: rgba(20, 184, 166, 0.1);
            color: var(--teal);
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
        
        .stat-trend {
            font-size: 12px;
            display: flex;
            align-items: center;
            gap: 4px;
            color: var(--success);
        }
        
        .stat-trend.down {
            color: var(--danger);
        }

        /* Filter Tabs Container */
        .filter-tabs-container {
            background: var(--card-bg);
            border-radius: 16px;
            padding: 20px;
            margin-bottom: 20px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
        }

        .filter-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }

        .filter-title {
            font-size: 20px;
            font-weight: 700;
            color: var(--text-color);
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .filter-title i {
            color: var(--primary-color);
        }

        .filter-tabs {
            display: flex;
            gap: 8px;
            margin-bottom: 20px;
            flex-wrap: wrap;
        }

        .filter-tab {
            padding: 10px 20px;
            border-radius: 10px;
            background: var(--gray-100);
            border: 2px solid transparent;
            color: var(--text-color);
            cursor: pointer;
            transition: all 0.3s ease;
            font-weight: 500;
            display: flex;
            align-items: center;
            gap: 8px;
            text-decoration: none;
        }

        .dark-mode .filter-tab {
            background: var(--gray-800);
        }

        .filter-tab.active {
            background: var(--primary-color);
            color: white;
            border-color: var(--primary-color);
        }

        .filter-tab:hover:not(.active) {
            background: var(--gray-200);
            text-decoration: none;
        }

        .dark-mode .filter-tab:hover:not(.active) {
            background: var(--gray-700);
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

        /* Advanced Filters */
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

        /* Enhanced Table Styles */
        .certificates-table-container {
            background: var(--card-bg);
            border-radius: 16px;
            overflow: hidden;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
        }

        .table-header {
            display: grid;
            grid-template-columns: 120px 200px 150px 100px 100px 120px 150px 180px;
            gap: 15px;
            padding: 20px;
            background: rgba(220, 38, 38, 0.03);
            border-bottom: 1px solid var(--border-color);
            font-weight: 600;
            color: var(--text-color);
            font-size: 13px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .table-row {
            display: grid;
            grid-template-columns: 120px 200px 150px 100px 100px 120px 150px 180px;
            gap: 15px;
            padding: 20px;
            border-bottom: 1px solid var(--border-color);
            transition: all 0.3s ease;
            align-items: center;
            background: var(--card-bg);
        }
        
        .table-row:hover {
            background: rgba(220, 38, 38, 0.03);
        }
        
        .table-row:last-child {
            border-bottom: none;
        }
        
        .table-cell {
            display: flex;
            flex-direction: column;
            gap: 4px;
            color: var(--text-color);
            min-height: 40px;
            justify-content: center;
        }
        
        .certificate-number {
            font-weight: 700;
            color: var(--primary-color);
            font-size: 15px;
        }
        
        .establishment-name {
            font-weight: 600;
            color: var(--text-color);
            font-size: 15px;
        }
        
        .establishment-info {
            font-size: 12px;
            color: var(--text-light);
        }
        
        /* Status Badge */
        .status-badge {
            padding: 8px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            text-transform: uppercase;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            width: fit-content;
            white-space: nowrap;
            border: 2px solid transparent;
        }
        
        /* Enhanced Action Buttons */
        .action-buttons {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
        }
        
        .action-button {
            padding: 8px 12px;
            border-radius: 8px;
            font-weight: 600;
            border: none;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            transition: all 0.3s ease;
            font-size: 13px;
            min-width: 80px;
            position: relative;
            overflow: hidden;
        }
        
        .action-button::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.2), transparent);
            transition: left 0.5s ease;
        }
        
        .action-button:hover::before {
            left: 100%;
        }
        
        .view-button {
            background: linear-gradient(135deg, rgba(59, 130, 246, 0.1), rgba(59, 130, 246, 0.2));
            color: var(--info);
            border: 1px solid rgba(59, 130, 246, 0.3);
        }
        
        .view-button:hover {
            background: var(--info);
            color: white;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(59, 130, 246, 0.3);
        }
        
        .download-button {
            background: linear-gradient(135deg, rgba(16, 185, 129, 0.1), rgba(16, 185, 129, 0.2));
            color: var(--success);
            border: 1px solid rgba(16, 185, 129, 0.3);
        }
        
        .download-button:hover {
            background: var(--success);
            color: white;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(16, 185, 129, 0.3);
        }
        
        .revoke-button {
            background: linear-gradient(135deg, rgba(220, 38, 38, 0.1), rgba(220, 38, 38, 0.2));
            color: var(--danger);
            border: 1px solid rgba(220, 38, 38, 0.3);
        }
        
        .revoke-button:hover {
            background: var(--danger);
            color: white;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(220, 38, 38, 0.3);
        }
        
        .renew-button {
            background: linear-gradient(135deg, rgba(245, 158, 11, 0.1), rgba(245, 158, 11, 0.2));
            color: var(--warning);
            border: 1px solid rgba(245, 158, 11, 0.3);
        }
        
        .renew-button:hover {
            background: var(--warning);
            color: white;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(245, 158, 11, 0.3);
        }
        
        .verify-button {
            background: linear-gradient(135deg, rgba(139, 92, 246, 0.1), rgba(139, 92, 246, 0.2));
            color: var(--purple);
            border: 1px solid rgba(139, 92, 246, 0.3);
        }
        
        .verify-button:hover {
            background: var(--purple);
            color: white;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(139, 92, 246, 0.3);
        }

        .no-certificates {
            text-align: center;
            padding: 60px 20px;
            color: var(--text-light);
            grid-column: 1 / -1;
        }
        
        .no-certificates-icon {
            font-size: 64px;
            margin-bottom: 16px;
            color: var(--text-light);
            opacity: 0.5;
        }

        /* Quick Actions Panel */
        .quick-actions {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        
        .quick-action-card {
            background: linear-gradient(135deg, var(--card-bg), #ffffff);
            border: 1px solid var(--border-color);
            border-radius: 16px;
            padding: 24px;
            display: flex;
            align-items: center;
            gap: 16px;
            transition: all 0.3s ease;
            cursor: pointer;
            text-decoration: none;
            color: var(--text-color);
        }
        
        .dark-mode .quick-action-card {
            background: linear-gradient(135deg, var(--card-bg), #2d3748);
        }
        
        .quick-action-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.1);
            text-decoration: none;
            color: var(--text-color);
            border-color: var(--primary-color);
        }
        
        .action-icon {
            width: 56px;
            height: 56px;
            border-radius: 14px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
        }
        
        .quick-action-card:nth-child(1) .action-icon {
            background: linear-gradient(135deg, rgba(59, 130, 246, 0.1), rgba(59, 130, 246, 0.2));
            color: var(--info);
        }
        
        .quick-action-card:nth-child(2) .action-icon {
            background: linear-gradient(135deg, rgba(16, 185, 129, 0.1), rgba(16, 185, 129, 0.2));
            color: var(--success);
        }
        
        .quick-action-card:nth-child(3) .action-icon {
            background: linear-gradient(135deg, rgba(139, 92, 246, 0.1), rgba(139, 92, 246, 0.2));
            color: var(--purple);
        }
        
        .quick-action-card:nth-child(4) .action-icon {
            background: linear-gradient(135deg, rgba(245, 158, 11, 0.1), rgba(245, 158, 11, 0.2));
            color: var(--warning);
        }
        
        .action-content {
            flex: 1;
        }
        
        .action-title {
            font-size: 16px;
            font-weight: 600;
            margin-bottom: 4px;
        }
        
        .action-description {
            font-size: 13px;
            color: var(--text-light);
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
            backdrop-filter: blur(5px);
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
            max-width: 500px;
            max-height: 90vh;
            overflow-y: auto;
            transform: scale(0.9);
            transition: all 0.3s ease;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
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
            position: sticky;
            top: 0;
            z-index: 10;
            backdrop-filter: blur(10px);
        }
        
        .modal-title {
            font-size: 20px;
            font-weight: 700;
            color: var(--text-color);
        }
        
        .modal-close {
            background: none;
            border: none;
            font-size: 24px;
            cursor: pointer;
            color: var(--text-light);
            transition: all 0.3s ease;
            width: 40px;
            height: 40px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 50%;
        }
        
        .modal-close:hover {
            background: var(--gray-100);
            color: var(--danger);
        }
        
        .dark-mode .modal-close:hover {
            background: var(--gray-800);
        }
        
        .modal-body {
            padding: 24px;
        }
        
        .form-group {
            margin-bottom: 20px;
        }
        
        .form-label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: var(--text-color);
        }
        
        .form-select, .form-textarea, .form-input {
            width: 100%;
            padding: 12px 16px;
            border-radius: 10px;
            border: 1px solid var(--border-color);
            background: var(--card-bg);
            color: var(--text-color);
            font-size: 14px;
            transition: all 0.3s ease;
        }
        
        .form-select:focus, .form-textarea:focus, .form-input:focus {
            outline: none;
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(220, 38, 38, 0.1);
        }
        
        .form-textarea {
            min-height: 100px;
            resize: vertical;
        }
        
        .modal-actions {
            display: flex;
            gap: 12px;
            justify-content: flex-end;
            margin-top: 24px;
            position: sticky;
            bottom: 0;
            background: var(--card-bg);
            padding: 16px 0;
            border-top: 1px solid var(--border-color);
            backdrop-filter: blur(10px);
        }
        
        .btn {
            padding: 12px 24px;
            border-radius: 10px;
            font-weight: 600;
            border: none;
            cursor: pointer;
            transition: all 0.3s ease;
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

        .btn-danger {
            background: linear-gradient(135deg, var(--danger), #b91c1c);
            color: white;
        }
        
        .btn-danger:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(220, 38, 38, 0.3);
        }

        /* Responsive Design */
        @media (max-width: 1400px) {
            .table-header, .table-row {
                grid-template-columns: 100px 180px 140px 90px 90px 110px 140px 160px;
                gap: 12px;
                padding: 16px;
            }
        }

        @media (max-width: 1200px) {
            .table-header, .table-row {
                grid-template-columns: 90px 160px 130px 80px 80px 100px 130px 150px;
                gap: 10px;
                padding: 14px;
            }
        }

        @media (max-width: 992px) {
            .table-header {
                display: none;
            }
            
            .table-row {
                grid-template-columns: 1fr;
                gap: 16px;
                padding: 20px;
                border: 1px solid var(--border-color);
                border-radius: 12px;
                margin-bottom: 12px;
            }
            
            .table-cell {
                display: grid;
                grid-template-columns: 140px 1fr;
                gap: 16px;
                align-items: start;
                border-bottom: 1px solid var(--border-color);
                padding-bottom: 12px;
            }
            
            .table-cell:last-child {
                border-bottom: none;
                padding-bottom: 0;
            }
            
            .table-cell::before {
                content: attr(data-label);
                font-weight: 600;
                color: var(--text-light);
                font-size: 13px;
            }
            
            .table-cell .action-buttons {
                grid-column: 1 / -1;
                display: flex;
                flex-wrap: wrap;
                gap: 8px;
                justify-content: center;
                margin-top: 10px;
            }
            
            .table-cell .action-button {
                flex: 1;
                min-width: 120px;
            }
            
            .filter-row {
                grid-template-columns: 1fr;
                gap: 16px;
            }
            
            .filter-actions {
                flex-direction: column;
            }
            
            .filter-button, .clear-filters {
                width: 100%;
                justify-content: center;
            }
            
            .dashboard-header {
                padding: 40px 25px 30px;
            }
            
            .dashboard-title {
                font-size: 32px;
            }
            
            .content-container {
                padding: 0 25px 30px;
            }
            
            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }

            .filter-tabs {
                flex-direction: column;
            }

            .modal {
                width: 95%;
                margin: 10px;
            }

            .quick-actions {
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
            
            .filters-container {
                padding: 20px;
            }
            
            .action-buttons {
                flex-direction: column;
            }
            
            .action-button {
                width: 100%;
            }
        }

        .certificates-table-container {
            max-height: 600px;
            overflow-y: auto;
        }

        .certificates-table-container::-webkit-scrollbar {
            width: 6px;
        }
        
        .certificates-table-container::-webkit-scrollbar-track {
            background: var(--gray-100);
            border-radius: 3px;
        }
        
        .certificates-table-container::-webkit-scrollbar-thumb {
            background: var(--gray-400);
            border-radius: 3px;
        }
        
        .certificates-table-container::-webkit-scrollbar-thumb:hover {
            background: var(--gray-500);
        }
        
        .dark-mode .certificates-table-container::-webkit-scrollbar-track {
            background: var(--gray-800);
        }
        
        .dark-mode .certificates-table-container::-webkit-scrollbar-thumb {
            background: var(--gray-600);
        }
        
        .dark-mode .certificates-table-container::-webkit-scrollbar-thumb:hover {
            background: var(--gray-500);
        }

        .modal::-webkit-scrollbar {
            width: 6px;
        }
        
        .modal::-webkit-scrollbar-track {
            background: var(--card-bg);
            border-radius: 3px;
        }
        
        .modal::-webkit-scrollbar-thumb {
            background: var(--border-color);
            border-radius: 3px;
        }
        
        .modal::-webkit-scrollbar-thumb:hover {
            background: var(--gray-400);
        }

        /* Animation for table rows */
        @keyframes fadeIn {
            from {
                opacity: 0;
                transform: translateY(10px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .table-row {
            animation: fadeIn 0.3s ease forwards;
        }

        .table-row:nth-child(even) {
            background: rgba(220, 38, 38, 0.01);
        }
        
        .dark-mode .table-row:nth-child(even) {
            background: rgba(255, 255, 255, 0.01);
        }

        /* Success message */
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

        /* Certificate type badge */
        .certificate-type-badge {
            padding: 6px 10px;
            border-radius: 6px;
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            border: 1px solid;
            width: fit-content;
        }
        
        .certificate-type-fsic {
            background: rgba(139, 92, 246, 0.1);
            color: var(--purple);
            border-color: rgba(139, 92, 246, 0.3);
        }
        
        .certificate-type-compliance {
            background: rgba(99, 102, 241, 0.1);
            color: var(--indigo);
            border-color: rgba(99, 102, 241, 0.3);
        }
        
        .certificate-type-provisional {
            background: rgba(14, 165, 233, 0.1);
            color: var(--light-blue);
            border-color: rgba(14, 165, 233, 0.3);
        }
        
        .certificate-type-exemption {
            background: rgba(20, 184, 166, 0.1);
            color: var(--teal);
            border-color: rgba(20, 184, 166, 0.3);
        }
    </style>
</head>
<body>
    <!-- Revoke Certificate Modal -->
    <div class="modal-overlay" id="revoke-modal">
        <div class="modal">
            <div class="modal-header">
                <h2 class="modal-title">Revoke Certificate</h2>
                <button class="modal-close" id="revoke-modal-close">&times;</button>
            </div>
            <div class="modal-body">
                <form id="revoke-certificate-form">
                    <input type="hidden" id="revoke-certificate-id" name="certificate_id">
                    
                    <div class="form-group">
                        <label class="form-label" for="revoked_reason">Reason for Revocation</label>
                        <textarea class="form-textarea" id="revoked_reason" name="revoked_reason" placeholder="Enter the reason for revoking this certificate..." required></textarea>
                        <small style="color: var(--text-light); font-size: 12px;">This action cannot be undone.</small>
                    </div>
                    
                    <div class="modal-actions">
                        <button type="button" class="btn btn-secondary" id="cancel-revoke">Cancel</button>
                        <button type="submit" class="btn btn-danger">Revoke Certificate</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <div class="container">
        <!-- Sidebar (Same as your provided code) -->
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
                        <a href="manage_users.php" class="submenu-item">Manage Users</a>
                        <a href="role_control.php" class="submenu-item">Role Control</a>
                        <a href="audit_logs.php" class="submenu-item">Audit & Activity Logs</a>
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
                       <a href="view_shifts.php" class="submenu-item">View Shifts</a>
                        <a href="create_schedule.php" class="submenu-item">Create Schedule</a>
                          <a href="confirm_availability.php" class="submenu-item">Confirm Availability</a>
                        <a href="request_change.php" class="submenu-item">Request Change</a>
                        <a href="monitor_attendance.php" class="submenu-item">Monitor Attendance</a>
                    </div>
                    
                   <!-- Training & Certification Monitoring -->
                    <div class="menu-item" onclick="toggleSubmenu('training-management')">
                        <div class="icon-box icon-bg-teal">
                            <i class='bx bxs-graduation icon-teal'></i>
                        </div>
                        <span class="font-medium">Training Management</span>
                        <svg class="dropdown-arrow menu-icon rotated" fill="none" stroke="currentColor" viewBox="0 0 24 24">
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
                    <div class="menu-item active" onclick="toggleSubmenu('inspection-management')">
                        <div class="icon-box icon-bg-cyan">
                            <i class='bx bxs-check-shield icon-cyan'></i>
                        </div>
                        <span class="font-medium">Inspection Management</span>
                        <svg class="dropdown-arrow menu-icon rotated" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                        </svg>
                    </div>
                    <div id="inspection-management" class="submenu active">
                        <a href="approve_reports.php" class="submenu-item">Approve Reports</a>
                        <a href="review_violations.php" class="submenu-item">Review Violations</a>
                        <a href="issue_certificates.php" class="submenu-item active">Issue Certificates</a>
                        <a href="track_follow_up.php" class="submenu-item">Track Follow-Up</a>
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
                            <input type="text" placeholder="Search certificates..." class="search-input" id="search-input">
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
                        <h1 class="dashboard-title">Fire Safety Certificates</h1>
                        <p class="dashboard-subtitle">Admin Panel - Issue, manage, and track fire safety certificates</p>
                    </div>
                </div>
                
                <!-- Content Container -->
                <div class="content-container">
                    <?php if ($success_message): ?>
                        <div class="success-message" id="success-message">
                            <div class="success-message-content">
                                <i class='bx bx-check-circle' style="font-size: 24px;"></i>
                                <span><?php echo $success_message; ?></span>
                            </div>
                            <button class="close-message" onclick="document.getElementById('success-message').style.display='none'">
                                <i class='bx bx-x'></i>
                            </button>
                        </div>
                    <?php endif; ?>
                    
                    <!-- Quick Actions -->
                    <div class="quick-actions">
                        <a href="approve_reports.php" class="quick-action-card">
                            <div class="action-icon">
                                <i class='bx bxs-check-shield'></i>
                            </div>
                            <div class="action-content">
                                <div class="action-title">Approve Reports</div>
                                <div class="action-description">Review and approve inspection reports</div>
                            </div>
                        </a>
                        <a href="#" class="quick-action-card" onclick="generateBulkCertificates()">
                            <div class="action-icon">
                                <i class='bx bxs-file-pdf'></i>
                            </div>
                            <div class="action-content">
                                <div class="action-title">Bulk Export</div>
                                <div class="action-description">Export multiple certificates as PDF</div>
                            </div>
                        </a>
                        <a href="#" class="quick-action-card" onclick="generateExpiryReport()">
                            <div class="action-icon">
                                <i class='bx bxs-calendar-exclamation'></i>
                            </div>
                            <div class="action-content">
                                <div class="action-title">Expiry Report</div>
                                <div class="action-description">Generate certificate expiry report</div>
                            </div>
                        </a>
                        <a href="#" class="quick-action-card" onclick="showCertificateStats()">
                            <div class="action-icon">
                                <i class='bx bxs-bar-chart-alt-2'></i>
                            </div>
                            <div class="action-content">
                                <div class="action-title">View Statistics</div>
                                <div class="action-description">View certificate statistics and analytics</div>
                            </div>
                        </a>
                    </div>
                    
                    <!-- Statistics Cards -->
                    <div class="stats-grid">
                        <div class="stat-card" data-type="total" onclick="filterByStatus('all')">
                            <div class="stat-header">
                                <div class="stat-icon-container">
                                    <i class='bx bxs-certificate'></i>
                                </div>
                                <div class="stat-trend">
                                    <i class='bx bx-up-arrow-alt'></i>
                                    +12%
                                </div>
                            </div>
                            <div class="stat-value"><?php echo $stats['total']; ?></div>
                            <div class="stat-label">Total Certificates</div>
                        </div>
                        <div class="stat-card" data-type="valid" onclick="filterByStatus('valid')">
                            <div class="stat-header">
                                <div class="stat-icon-container">
                                    <i class='bx bxs-check-circle'></i>
                                </div>
                                <div class="stat-trend">
                                    <i class='bx bx-up-arrow-alt'></i>
                                    +8%
                                </div>
                            </div>
                            <div class="stat-value"><?php echo $stats['valid']; ?></div>
                            <div class="stat-label">Currently Valid</div>
                        </div>
                        <div class="stat-card" data-type="expiring_soon" onclick="filterByStatus('expiring_soon')">
                            <div class="stat-header">
                                <div class="stat-icon-container">
                                    <i class='bx bxs-time-five'></i>
                                </div>
                                <div class="stat-trend">
                                    <i class='bx bx-up-arrow-alt'></i>
                                    +5%
                                </div>
                            </div>
                            <div class="stat-value"><?php echo $stats['expiring_soon']; ?></div>
                            <div class="stat-label">Expiring Soon (≤30 days)</div>
                        </div>
                        <div class="stat-card" data-type="expired" onclick="filterByStatus('expired')">
                            <div class="stat-header">
                                <div class="stat-icon-container">
                                    <i class='bx bxs-error-circle'></i>
                                </div>
                                <div class="stat-trend">
                                    <i class='bx bx-down-arrow-alt'></i>
                                    -3%
                                </div>
                            </div>
                            <div class="stat-value"><?php echo $stats['expired']; ?></div>
                            <div class="stat-label">Expired</div>
                        </div>
                        <div class="stat-card" data-type="fsic" onclick="filterByCertificateType('fsic')">
                            <div class="stat-header">
                                <div class="stat-icon-container">
                                    <i class='bx bxs-shield'></i>
                                </div>
                                <div class="stat-trend">
                                    <i class='bx bx-up-arrow-alt'></i>
                                    +10%
                                </div>
                            </div>
                            <div class="stat-value"><?php echo $stats['fsic']; ?></div>
                            <div class="stat-label">FSIC Certificates</div>
                        </div>
                        <div class="stat-card" data-type="compliance" onclick="filterByCertificateType('compliance')">
                            <div class="stat-header">
                                <div class="stat-icon-container">
                                    <i class='bx bxs-check-square'></i>
                                </div>
                                <div class="stat-trend">
                                    <i class='bx bx-up-arrow-alt'></i>
                                    +7%
                                </div>
                            </div>
                            <div class="stat-value"><?php echo $stats['compliance']; ?></div>
                            <div class="stat-label">Compliance</div>
                        </div>
                        <div class="stat-card" data-type="provisional" onclick="filterByCertificateType('provisional')">
                            <div class="stat-header">
                                <div class="stat-icon-container">
                                    <i class='bx bxs-time'></i>
                                </div>
                                <div class="stat-trend">
                                    <i class='bx bx-up-arrow-alt'></i>
                                    +4%
                                </div>
                            </div>
                            <div class="stat-value"><?php echo $stats['provisional']; ?></div>
                            <div class="stat-label">Provisional</div>
                        </div>
                        <div class="stat-card" data-type="exemption" onclick="filterByCertificateType('exemption')">
                            <div class="stat-header">
                                <div class="stat-icon-container">
                                    <i class='bx bxs-star'></i>
                                </div>
                                <div class="stat-trend">
                                    <i class='bx bx-up-arrow-alt'></i>
                                    +2%
                                </div>
                            </div>
                            <div class="stat-value"><?php echo $stats['exemption']; ?></div>
                            <div class="stat-label">Exemption</div>
                        </div>
                    </div>
                    
                    <!-- Filter Tabs Container -->
                    <div class="filter-tabs-container">
                        <div class="filter-header">
                            <h3 class="filter-title">
                                <i class='bx bxs-certificate'></i>
                                Fire Safety Certificates
                            </h3>
                        </div>
                        
                        <div class="filter-tabs">
                            <a href="?status=all&date=<?php echo $filter_date; ?>&search=<?php echo urlencode($search_query); ?>&barangay=<?php echo $filter_barangay; ?>&establishment_type=<?php echo $filter_establishment_type; ?>&certificate_type=<?php echo $filter_certificate_type; ?>" class="filter-tab <?php echo $filter_status === 'all' ? 'active' : ''; ?>">
                                <i class='bx bxs-dashboard'></i>
                                All Certificates
                                <span class="filter-tab-count"><?php echo $stats['total']; ?></span>
                            </a>
                            <a href="?status=valid&date=<?php echo $filter_date; ?>&search=<?php echo urlencode($search_query); ?>&barangay=<?php echo $filter_barangay; ?>&establishment_type=<?php echo $filter_establishment_type; ?>&certificate_type=<?php echo $filter_certificate_type; ?>" class="filter-tab <?php echo $filter_status === 'valid' ? 'active' : ''; ?>">
                                <i class='bx bxs-check-circle'></i>
                                Valid
                                <span class="filter-tab-count"><?php echo $stats['valid']; ?></span>
                            </a>
                            <a href="?status=expiring_soon&date=<?php echo $filter_date; ?>&search=<?php echo urlencode($search_query); ?>&barangay=<?php echo $filter_barangay; ?>&establishment_type=<?php echo $filter_establishment_type; ?>&certificate_type=<?php echo $filter_certificate_type; ?>" class="filter-tab <?php echo $filter_status === 'expiring_soon' ? 'active' : ''; ?>">
                                <i class='bx bxs-time-five'></i>
                                Expiring Soon
                                <span class="filter-tab-count"><?php echo $stats['expiring_soon']; ?></span>
                            </a>
                            <a href="?status=expired&date=<?php echo $filter_date; ?>&search=<?php echo urlencode($search_query); ?>&barangay=<?php echo $filter_barangay; ?>&establishment_type=<?php echo $filter_establishment_type; ?>&certificate_type=<?php echo $filter_certificate_type; ?>" class="filter-tab <?php echo $filter_status === 'expired' ? 'active' : ''; ?>">
                                <i class='bx bxs-error-circle'></i>
                                Expired
                                <span class="filter-tab-count"><?php echo $stats['expired']; ?></span>
                            </a>
                            <a href="?status=revoked&date=<?php echo $filter_date; ?>&search=<?php echo urlencode($search_query); ?>&barangay=<?php echo $filter_barangay; ?>&establishment_type=<?php echo $filter_establishment_type; ?>&certificate_type=<?php echo $filter_certificate_type; ?>" class="filter-tab <?php echo $filter_status === 'revoked' ? 'active' : ''; ?>">
                                <i class='bx bxs-x-circle'></i>
                                Revoked
                                <span class="filter-tab-count"><?php echo $stats['revoked']; ?></span>
                            </a>
                        </div>
                    </div>
                    
                    <!-- Advanced Filters -->
                    <div class="filters-container">
                        <div class="filter-section">
                            <h4 class="filter-section-title">
                                <i class='bx bx-filter-alt'></i>
                                Advanced Filters
                            </h4>
                            
                            <form method="GET" id="filter-form">
                                <div class="filter-row">
                                    <div class="filter-group">
                                        <label class="filter-label">
                                            <i class='bx bx-calendar'></i>
                                            Issue Date
                                        </label>
                                        <select class="filter-select" name="date">
                                            <?php foreach ($date_options as $value => $label): ?>
                                                <option value="<?php echo $value; ?>" <?php echo $filter_date === $value ? 'selected' : ''; ?>>
                                                    <?php echo $label; ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
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
                                    
                                    <div class="filter-group">
                                        <label class="filter-label">
                                            <i class='bx bxs-business'></i>
                                            Establishment Type
                                        </label>
                                        <select class="filter-select" name="establishment_type">
                                            <option value="">All Types</option>
                                            <?php foreach ($establishment_types as $type): ?>
                                                <option value="<?php echo htmlspecialchars($type); ?>" <?php echo $filter_establishment_type === $type ? 'selected' : ''; ?>>
                                                    <?php echo htmlspecialchars($type); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    
                                    <div class="filter-group">
                                        <label class="filter-label">
                                            <i class='bx bxs-certificate'></i>
                                            Certificate Type
                                        </label>
                                        <select class="filter-select" name="certificate_type">
                                            <?php foreach ($cert_type_options as $value => $label): ?>
                                                <option value="<?php echo $value; ?>" <?php echo $filter_certificate_type === $value ? 'selected' : ''; ?>>
                                                    <?php echo $label; ?>
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
                                        <input type="text" class="filter-input" name="search" placeholder="Search by certificate number, establishment name, owner..." value="<?php echo htmlspecialchars($search_query); ?>">
                                    </div>
                                </div>
                                
                                <div class="filter-actions">
                                    <a href="issue_certificates.php" class="filter-button clear-filters">
                                        <i class='bx bx-x'></i>
                                        Clear All Filters
                                    </a>
                                    <button type="submit" class="filter-button">
                                        <i class='bx bx-filter-alt'></i>
                                        Apply Filters
                                    </button>
                                </div>
                                
                                <!-- Hidden field to preserve status filter -->
                                <input type="hidden" name="status" value="<?php echo $filter_status; ?>">
                            </form>
                        </div>
                    </div>
                    
                    <!-- Certificates Table -->
                    <div class="certificates-table-container">
                        <div class="table-header">
                            <div>Certificate #</div>
                            <div>Establishment</div>
                            <div>Certificate Type</div>
                            <div>Issue Date</div>
                            <div>Valid Until</div>
                            <div>Status</div>
                            <div>Issued By</div>
                            <div>Actions</div>
                        </div>
                        <div style="max-height: 500px; overflow-y: auto;">
                            <?php if (count($certificates) > 0): ?>
                                <?php foreach ($certificates as $index => $cert): ?>
                                    <?php 
                                    $certificateTypeClass = 'certificate-type-' . $cert['certificate_type'];
                                    $certificateTypeLabel = $certificate_types[$cert['certificate_type']] ?? ucfirst($cert['certificate_type']);
                                    ?>
                                    <div class="table-row" style="animation-delay: <?php echo $index * 0.05; ?>s;">
                                        <div class="table-cell" data-label="Certificate #">
                                            <div class="certificate-number"><?php echo $cert['certificate_number']; ?></div>
                                            <?php if ($cert['revoked']): ?>
                                                <div style="font-size: 11px; color: var(--danger);">
                                                    <i class='bx bxs-x-circle'></i> Revoked
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                        <div class="table-cell" data-label="Establishment">
                                            <div class="establishment-name"><?php echo htmlspecialchars($cert['establishment_name']); ?></div>
                                            <div class="establishment-info">
                                                <?php echo htmlspecialchars($cert['establishment_type']); ?> • <?php echo htmlspecialchars($cert['barangay']); ?>
                                            </div>
                                            <div class="establishment-info" style="font-size: 11px;">
                                                Owner: <?php echo htmlspecialchars($cert['owner_name']); ?>
                                            </div>
                                        </div>
                                        <div class="table-cell" data-label="Certificate Type">
                                            <span class="certificate-type-badge <?php echo $certificateTypeClass; ?>">
                                                <?php echo $certificateTypeLabel; ?>
                                            </span>
                                            <?php if ($cert['report_number']): ?>
                                                <div style="font-size: 11px; color: var(--text-light); margin-top: 4px;">
                                                    Report: <?php echo $cert['report_number']; ?>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                        <div class="table-cell" data-label="Issue Date">
                                            <div style="font-weight: 600;"><?php echo formatDate($cert['issue_date']); ?></div>
                                            <?php if ($cert['inspection_date']): ?>
                                                <div style="font-size: 11px; color: var(--text-light);">
                                                    Inspected: <?php echo formatDate($cert['inspection_date']); ?>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                        <div class="table-cell" data-label="Valid Until">
                                            <div style="font-weight: 600;"><?php echo formatDate($cert['valid_until']); ?></div>
                                            <?php if ($cert['days_remaining'] !== null && $cert['validity_status'] !== 'revoked'): ?>
                                                <div style="font-size: 11px; color: <?php echo $cert['days_remaining'] <= 30 ? 'var(--warning)' : 'var(--success)'; ?>;">
                                                    <?php echo $cert['days_remaining'] > 0 ? $cert['days_remaining'] . ' days remaining' : 'Expired ' . abs($cert['days_remaining']) . ' days ago'; ?>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                        <div class="table-cell" data-label="Status">
                                            <?php echo getCertificateStatusBadge($cert['validity_status'], $cert['days_remaining']); ?>
                                            <?php if ($cert['revoked'] && $cert['revoked_reason']): ?>
                                                <div style="font-size: 11px; color: var(--danger); margin-top: 4px;">
                                                    Reason: <?php echo htmlspecialchars(substr($cert['revoked_reason'], 0, 50)) . (strlen($cert['revoked_reason']) > 50 ? '...' : ''); ?>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                        <div class="table-cell" data-label="Issued By">
                                            <div style="font-weight: 600;"><?php echo htmlspecialchars($cert['issued_by_name']); ?></div>
                                            <?php if ($cert['revoked_by_name']): ?>
                                                <div style="font-size: 11px; color: var(--danger);">
                                                    Revoked by: <?php echo htmlspecialchars($cert['revoked_by_name']); ?>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                        <div class="table-cell" data-label="Actions">
                                            <div class="action-buttons">
                                                <a href="?generate_certificate=<?php echo $cert['id']; ?>" target="_blank" class="action-button download-button">
                                                    <i class='bx bxs-download'></i>
                                                    Download
                                                </a>
                                                
                                                <?php if (!$cert['revoked'] && $cert['validity_status'] !== 'expired'): ?>
                                                    <button class="action-button revoke-button" onclick="revokeCertificate(<?php echo $cert['id']; ?>)">
                                                        <i class='bx bxs-x-circle'></i>
                                                        Revoke
                                                    </button>
                                                <?php endif; ?>
                                                
                                                <?php if ($cert['validity_status'] === 'expired' || $cert['validity_status'] === 'expiring_soon'): ?>
                                                    <button class="action-button renew-button" onclick="renewCertificate(<?php echo $cert['id']; ?>)">
                                                        <i class='bx bxs-refresh'></i>
                                                        Renew
                                                    </button>
                                                <?php endif; ?>
                                                
                                                <button class="action-button verify-button" onclick="verifyCertificate('<?php echo $cert['certificate_number']; ?>')">
                                                    <i class='bx bxs-check-shield'></i>
                                                    Verify
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <div class="no-certificates">
                                    <div class="no-certificates-icon">
                                        <i class='bx bxs-certificate'></i>
                                    </div>
                                    <h3>No Certificates Found</h3>
                                    <p>No certificates match your current filters.</p>
                                    <?php if ($filter_status !== 'all' || $filter_date !== '' || $search_query !== '' || $filter_barangay !== '' || $filter_establishment_type !== '' || $filter_certificate_type !== ''): ?>
                                        <a href="issue_certificates.php" class="filter-button" style="margin-top: 16px;">
                                            <i class='bx bx-x'></i>
                                            Clear Filters
                                        </a>
                                    <?php endif; ?>
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
            // Initialize event listeners
            initEventListeners();
            
            // Update time display
            updateTime();
            setInterval(updateTime, 1000);
            
            // Initialize search functionality
            initSearch();
            
            // Add data labels for mobile view
            addDataLabels();
            
            // Add animation to stat cards
            animateStatCards();
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
            });
            
            // Load saved theme preference
            const savedTheme = localStorage.getItem('theme');
            if (savedTheme === 'dark') {
                document.body.classList.add('dark-mode');
                themeIcon.className = 'bx bx-sun';
                themeText.textContent = 'Light Mode';
            }
            
            // Revoke modal functionality
            const revokeModal = document.getElementById('revoke-modal');
            const revokeModalClose = document.getElementById('revoke-modal-close');
            const cancelRevoke = document.getElementById('cancel-revoke');
            
            revokeModalClose.addEventListener('click', closeRevokeModal);
            cancelRevoke.addEventListener('click', closeRevokeModal);
            
            revokeModal.addEventListener('click', function(e) {
                if (e.target === this) {
                    closeRevokeModal();
                }
            });
            
            // Revoke certificate form submission
            const revokeForm = document.getElementById('revoke-certificate-form');
            revokeForm.addEventListener('submit', function(e) {
                e.preventDefault();
                submitRevokeCertificate();
            });
            
            // Filter form submission
            const filterForm = document.getElementById('filter-form');
            
            // Handle filter select changes
            filterForm.querySelectorAll('select').forEach(select => {
                select.addEventListener('change', function() {
                    filterForm.submit();
                });
            });
            
            // Add click handlers for stat cards
            document.querySelectorAll('.stat-card').forEach(card => {
                card.addEventListener('click', function() {
                    const type = this.dataset.type;
                    handleStatCardClick(type);
                });
            });
        }
        
        function initSearch() {
            const searchInput = document.getElementById('search-input');
            const filterForm = document.getElementById('filter-form');
            const searchParam = filterForm.querySelector('input[name="search"]');
            
            // Set search input value from URL parameter
            searchInput.value = '<?php echo htmlspecialchars($search_query); ?>';
            
            // Add event listener for search input
            searchInput.addEventListener('keypress', function(e) {
                if (e.key === 'Enter') {
                    searchParam.value = this.value;
                    filterForm.submit();
                }
            });
            
            // Add debounced search
            let searchTimeout;
            searchInput.addEventListener('input', function() {
                clearTimeout(searchTimeout);
                searchTimeout = setTimeout(() => {
                    if (this.value.length >= 2 || this.value.length === 0) {
                        searchParam.value = this.value;
                        filterForm.submit();
                    }
                }, 500);
            });
        }
        
        function animateStatCards() {
            document.querySelectorAll('.stat-card').forEach((card, index) => {
                card.style.animationDelay = `${index * 0.1}s`;
                card.style.opacity = '0';
                card.style.transform = 'translateY(20px)';
                
                setTimeout(() => {
                    card.style.transition = 'all 0.5s ease';
                    card.style.opacity = '1';
                    card.style.transform = 'translateY(0)';
                }, index * 100);
            });
        }
        
        function addDataLabels() {
            // This function adds data-label attributes for mobile responsive view
            if (window.innerWidth <= 992) {
                const tableCells = document.querySelectorAll('.table-cell');
                const headers = ['Certificate #', 'Establishment', 'Certificate Type', 'Issue Date', 'Valid Until', 'Status', 'Issued By', 'Actions'];
                
                tableCells.forEach((cell, index) => {
                    const rowIndex = Math.floor(index / 8);
                    const colIndex = index % 8;
                    
                    if (colIndex < headers.length) {
                        cell.setAttribute('data-label', headers[colIndex]);
                    }
                });
            }
        }
        
        function revokeCertificate(certificateId) {
            const revokeModal = document.getElementById('revoke-modal');
            const revokeCertificateId = document.getElementById('revoke-certificate-id');
            
            revokeCertificateId.value = certificateId;
            
            // Open modal
            revokeModal.classList.add('active');
        }
        
        function renewCertificate(certificateId) {
            if (confirm('Are you sure you want to renew this certificate? A new certificate will be issued with updated validity period.')) {
                showNotification('info', 'Certificate renewal feature coming soon...');
            }
        }
        
        function verifyCertificate(certificateNumber) {
            showNotification('info', 'Verifying certificate: ' + certificateNumber + '...');
            // In a real implementation, this would open a verification modal or page
        }
        
        function submitRevokeCertificate() {
            const form = document.getElementById('revoke-certificate-form');
            const formData = new FormData(form);
            
            fetch('', {
                method: 'POST',
                body: formData
            })
            .then(response => response.text())
            .then(() => {
                // Since we're handling form submission traditionally, just show success
                showNotification('success', 'Certificate revoked successfully!');
                closeRevokeModal();
                setTimeout(() => {
                    location.reload();
                }, 1500);
            })
            .catch(error => {
                showNotification('error', 'Error: ' + error.message);
            });
        }
        
        function generateBulkCertificates() {
            const selectedCertificates = [];
            document.querySelectorAll('input[name="selected_certificates"]:checked').forEach(cb => {
                selectedCertificates.push(cb.value);
            });
            
            if (selectedCertificates.length === 0) {
                showNotification('warning', 'Please select certificates to export');
                return;
            }
            
            showNotification('info', 'Preparing bulk export for ' + selectedCertificates.length + ' certificates...');
            
            // In a real implementation, this would generate a ZIP file with multiple PDFs
            setTimeout(() => {
                showNotification('success', 'Bulk export completed! Download will start shortly.');
            }, 2000);
        }
        
        function generateExpiryReport() {
            showNotification('info', 'Generating certificate expiry report...');
            
            // In a real implementation, this would generate a report PDF
            setTimeout(() => {
                showNotification('success', 'Expiry report generated successfully!');
            }, 1500);
        }
        
        function showCertificateStats() {
            showNotification('info', 'Opening certificate statistics dashboard...');
            // In a real implementation, this would open a statistics page
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
        
        function closeRevokeModal() {
            document.getElementById('revoke-modal').classList.remove('active');
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
        
        function handleStatCardClick(type) {
            switch(type) {
                case 'total':
                    filterByStatus('all');
                    break;
                case 'valid':
                    filterByStatus('valid');
                    break;
                case 'expiring_soon':
                    filterByStatus('expiring_soon');
                    break;
                case 'expired':
                    filterByStatus('expired');
                    break;
                case 'revoked':
                    filterByStatus('revoked');
                    break;
                case 'fsic':
                    filterByCertificateType('fsic');
                    break;
                case 'compliance':
                    filterByCertificateType('compliance');
                    break;
                case 'provisional':
                    filterByCertificateType('provisional');
                    break;
                case 'exemption':
                    filterByCertificateType('exemption');
                    break;
            }
        }
        
        function filterByStatus(status) {
            const url = new URL(window.location.href);
            url.searchParams.set('status', status);
            window.location.href = url.toString();
        }
        
        function filterByCertificateType(type) {
            const url = new URL(window.location.href);
            url.searchParams.set('certificate_type', type);
            window.location.href = url.toString();
        }
        
        // Handle window resize for responsive layout
        window.addEventListener('resize', addDataLabels);
    </script>
</body>
</html>