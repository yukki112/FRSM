<?php
session_start();
require_once '../../config/db_connection.php';
require_once('../../vendor/setasign/fpdf/fpdf.php'); // Make sure FPDF is included

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

// Handle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'approve_completion':
                approveTrainingCompletion($_POST['registration_id']);
                break;
            case 'reject_completion':
                rejectTrainingCompletion($_POST['registration_id']);
                break;
        }
    }
}

// Handle AJAX requests
if (isset($_GET['ajax']) && $_GET['ajax'] === 'true') {
    if (isset($_GET['get_training_details'])) {
        echo json_encode(getTrainingDetails($_GET['id']));
        exit();
    }
}

// Get filter parameters
$status_filter = isset($_GET['status']) ? $_GET['status'] : 'all';
$training_filter = isset($_GET['training']) ? $_GET['training'] : 'all';
$search_term = isset($_GET['search']) ? $_GET['search'] : '';

// Build query with filters
$where_conditions = [];
$params = [];

$where_conditions[] = "tr.completion_status = 'completed' AND tr.completion_verified = 1";
$where_conditions[] = "tr.certificate_issued = 0";

if (!empty($status_filter) && $status_filter !== 'all') {
    $where_conditions[] = "tr.status = ?";
    $params[] = $status_filter;
}

if (!empty($training_filter) && $training_filter !== 'all') {
    $where_conditions[] = "tr.training_id = ?";
    $params[] = $training_filter;
}

if (!empty($search_term)) {
    $where_conditions[] = "(CONCAT(v.first_name, ' ', COALESCE(v.middle_name, ''), ' ', v.last_name) LIKE ? OR v.email LIKE ? OR t.title LIKE ?)";
    $params[] = "%$search_term%";
    $params[] = "%$search_term%";
    $params[] = "%$search_term%";
}

$where_clause = '';
if (!empty($where_conditions)) {
    $where_clause = "WHERE " . implode(" AND ", $where_conditions);
}

// Fetch training completions awaiting approval
$completions_query = "
    SELECT 
        tr.*,
        v.id as volunteer_id,
        v.first_name,
        v.middle_name,
        v.last_name,
        CONCAT(v.first_name, ' ', COALESCE(v.middle_name, ''), ' ', v.last_name) as full_name,
        v.email,
        v.volunteer_status,
        v.training_completion_status,
        t.title as training_title,
        t.training_date,
        t.training_end_date,
        t.duration_hours,
        t.instructor,
        u.first_name as verifier_first_name,
        u.last_name as verifier_last_name
    FROM training_registrations tr
    JOIN volunteers v ON tr.volunteer_id = v.id
    JOIN trainings t ON tr.training_id = t.id
    LEFT JOIN users u ON tr.completion_verified_by = u.id
    $where_clause 
    ORDER BY tr.completion_verified_at DESC
";

$completions_stmt = $pdo->prepare($completions_query);
$completions_stmt->execute($params);
$completions = $completions_stmt->fetchAll();

// Get all trainings for filter dropdown
$trainings_query = "SELECT id, title FROM trainings ORDER BY title";
$trainings_stmt = $pdo->prepare($trainings_query);
$trainings_stmt->execute();
$all_trainings = $trainings_stmt->fetchAll();

// Get counts for each status
$status_counts_query = "
    SELECT 
        CASE 
            WHEN tr.certificate_issued = 1 THEN 'certified'
            WHEN tr.completion_verified = 1 THEN 'verified'
            WHEN tr.completion_status = 'completed' THEN 'completed'
            ELSE tr.status
        END as status_group,
        COUNT(*) as count
    FROM training_registrations tr
    WHERE tr.completion_status = 'completed'
    GROUP BY status_group
";
$status_counts_stmt = $pdo->prepare($status_counts_query);
$status_counts_stmt->execute();
$status_counts_raw = $status_counts_stmt->fetchAll();
$status_counts = ['completed' => 0, 'verified' => 0, 'certified' => 0];
foreach ($status_counts_raw as $row) {
    $status_counts[$row['status_group']] = $row['count'];
}

$stmt = null;
$completions_stmt = null;
$trainings_stmt = null;
$status_counts_stmt = null;

// Function to approve training completion - UPDATED WITH EXPIRY DATE
function approveTrainingCompletion($registration_id) {
    global $pdo, $user_id, $full_name;
    
    try {
        $pdo->beginTransaction();
        
        // Get registration details
        $query = "SELECT tr.*, v.id as volunteer_id, v.volunteer_status, t.title 
                 FROM training_registrations tr
                 JOIN volunteers v ON tr.volunteer_id = v.id
                 JOIN trainings t ON tr.training_id = t.id
                 WHERE tr.id = ?";
        $stmt = $pdo->prepare($query);
        $stmt->execute([$registration_id]);
        $registration = $stmt->fetch();
        
        if (!$registration) {
            throw new Exception("Registration not found");
        }
        
        // Update registration
        $update_query = "UPDATE training_registrations 
                        SET certificate_issued = 1,
                            certificate_issued_at = NOW(),
                            status = 'completed'
                        WHERE id = ?";
        $update_stmt = $pdo->prepare($update_query);
        $update_stmt->execute([$registration_id]);
        
        // Generate certificate
        $certificate_number = generateCertificateNumber();
        $certificate_path = generateCertificate($registration_id, $certificate_number, $full_name);
        
        // Calculate expiry date (1 year from today)
        $issue_date = date('Y-m-d');
        $expiry_date = date('Y-m-d', strtotime('+1 year'));
        
        // Insert certificate record WITH EXPIRY DATE
        $cert_query = "INSERT INTO training_certificates 
                      (registration_id, volunteer_id, training_id, certificate_number, 
                       issue_date, expiry_date, certificate_file, issued_by, issued_at, verified)
                      VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(), 1)";
        $cert_stmt = $pdo->prepare($cert_query);
        $cert_stmt->execute([
            $registration_id,
            $registration['volunteer_id'],
            $registration['training_id'],
            $certificate_number,
            $issue_date,
            $expiry_date,
            $certificate_path,
            $user_id
        ]);
        
        // Update volunteer status if they were "New Volunteer"
        if ($registration['volunteer_status'] === 'New Volunteer') {
            $volunteer_query = "UPDATE volunteers 
                               SET volunteer_status = 'Active',
                                   training_completion_status = 'certified',
                                   first_training_completed_at = CURDATE(),
                                   active_since = CURDATE()
                               WHERE id = ?";
            $volunteer_stmt = $pdo->prepare($volunteer_query);
            $volunteer_stmt->execute([$registration['volunteer_id']]);
        } else {
            // Update training completion status only
            $volunteer_query = "UPDATE volunteers 
                               SET training_completion_status = 'certified'
                               WHERE id = ?";
            $volunteer_stmt = $pdo->prepare($volunteer_query);
            $volunteer_stmt->execute([$registration['volunteer_id']]);
        }
        
        $pdo->commit();
        
        // Redirect with success message
        header("Location: approve_completions.php?success=1&registration_id=" . $registration_id);
        exit();
        
    } catch (Exception $e) {
        $pdo->rollBack();
        header("Location: approve_completions.php?error=" . urlencode($e->getMessage()));
        exit();
    }
}

// Function to reject training completion
function rejectTrainingCompletion($registration_id) {
    global $pdo;
    
    try {
        $update_query = "UPDATE training_registrations 
                        SET completion_verified = 0,
                            completion_verified_by = NULL,
                            completion_verified_at = NULL,
                            completion_status = 'failed',
                            completion_notes = CONCAT(COALESCE(completion_notes, ''), '\nRejected by admin: ', NOW())
                        WHERE id = ?";
        $update_stmt = $pdo->prepare($update_query);
        $update_stmt->execute([$registration_id]);
        
        header("Location: approve_completions.php?success=2&registration_id=" . $registration_id);
        exit();
        
    } catch (Exception $e) {
        header("Location: approve_completions.php?error=" . urlencode($e->getMessage()));
        exit();
    }
}

// Function to generate certificate number
function generateCertificateNumber() {
    return 'CERT-' . date('Ymd') . '-' . str_pad(rand(1, 9999), 4, '0', STR_PAD_LEFT);
}

// Function to generate PDF certificate - UPDATED WITH EXPIRY DATE
function generateCertificate($registration_id, $certificate_number, $admin_name) {
    global $pdo;
    
    // Get registration details
    $query = "SELECT 
                tr.*,
                v.first_name,
                v.middle_name,
                v.last_name,
                CONCAT(v.first_name, ' ', COALESCE(v.middle_name, ''), ' ', v.last_name) as volunteer_name,
                v.date_of_birth,
                v.address,
                t.title as training_title,
                t.training_date,
                t.training_end_date,
                t.duration_hours,
                t.instructor,
                u.first_name as verifier_first_name,
                u.last_name as verifier_last_name
              FROM training_registrations tr
              JOIN volunteers v ON tr.volunteer_id = v.id
              JOIN trainings t ON tr.training_id = t.id
              LEFT JOIN users u ON tr.completion_verified_by = u.id
              WHERE tr.id = ?";
    $stmt = $pdo->prepare($query);
    $stmt->execute([$registration_id]);
    $data = $stmt->fetch();
    
    // Calculate dates
    $issue_date = date('F d, Y');
    $expiry_date = date('F d, Y', strtotime('+1 year'));
    
    // Create PDF in landscape A4
    $pdf = new FPDF('L', 'mm', 'A4');
    $pdf->AddPage();
    
    // Set margins - optimized for content (wider side margins for better centering)
    $pdf->SetMargins(25, 20, 25);
    
    // Get page width (297mm for A4 landscape)
    $pageWidth = 297;
    
    // Add decorative border
    $pdf->SetDrawColor(220, 38, 38);
    $pdf->SetLineWidth(1.5);
    $pdf->Rect(15, 15, 267, 180); // Outer border
    
    // Add logo - centered
    $logo_path = '../../img/frsm-logo.png';
    if (file_exists($logo_path)) {
        $pdf->Image($logo_path, 29, 22, 26);
    }
    
    // Calculate center position
    $centerX = $pageWidth / 2;
    
    // Add certificate header - SMALLER FONT
    $pdf->SetFont('Arial', 'B', 16); // Reduced from 18
    $pdf->SetTextColor(0, 0, 0);
    $pdf->SetY(25);
    $pdf->Cell(0, 10, 'FIRE & RESCUE SERVICES MANAGEMENT', 0, 1, 'C');
    
    $pdf->SetFont('Arial', 'B', 12); // Reduced from 14
    $pdf->SetTextColor(220, 38, 38);
    $pdf->SetY(32);
    $pdf->Cell(0, 10, 'OFFICE OF TRAINING AND CERTIFICATION', 0, 1, 'C');
    
    // Add certificate title - SMALLER
    $pdf->SetFont('Arial', 'B', 28); // Reduced from 32
    $pdf->SetTextColor(0, 0, 0);
    $pdf->SetY(45);
    $pdf->Cell(0, 10, 'CERTIFICATE OF COMPLETION', 0, 1, 'C');
    
    // Add decorative lines - centered
    $pdf->SetLineWidth(0.75);
    $pdf->SetDrawColor(220, 38, 38);
    $lineWidth = 120; // Width of the decorative lines
    $lineStartX = $centerX - ($lineWidth / 2);
    $pdf->Line($lineStartX, 58, $lineStartX + $lineWidth, 58);
    $pdf->Line($lineStartX + 5, 60, $lineStartX + $lineWidth - 5, 60);
    
    // Add "This is to certify that" text - SMALLER
    $pdf->SetFont('Arial', 'I', 12); // Reduced from 14
    $pdf->SetTextColor(100, 100, 100);
    $pdf->SetY(68);
    $pdf->Cell(0, 10, 'This is to certify that', 0, 1, 'C');
    
    // Add volunteer name - SMALLER
    $pdf->SetFont('Arial', 'B', 25); // Reduced from 22
    $pdf->SetTextColor(220, 38, 38);
    $pdf->SetY(78); // Adjusted position
    
    // Truncate name if too long
    $name = strtoupper($data['volunteer_name']);
    if (strlen($name) > 40) { // Increased threshold
        $name = substr($name, 0, 37) . '...';
    }
    $pdf->Cell(0, 10, $name, 0, 1, 'C');
    
    // Add training details - SMALLER
    $pdf->SetFont('Arial', '', 10); // Reduced from 12
    $pdf->SetTextColor(0, 0, 0);
    $pdf->SetY(92); // Adjusted position
    $pdf->Cell(0, 10, 'has successfully completed the training program', 0, 1, 'C');
    
    // Training title - SMALLER
    $training_title = '"' . $data['training_title'] . '"';
    if (strlen($training_title) > 50) { // Increased threshold
        $training_title = '"' . substr($data['training_title'], 0, 47) . '..."';
    }
    
    $pdf->SetFont('Arial', 'B', 18); // Reduced from 16
    $pdf->SetTextColor(220, 38, 38);
    $pdf->SetY(102); // Adjusted position
    $pdf->Cell(0, 10, $training_title, 0, 1, 'C');
    
    // Training date
    $pdf->SetFont('Arial', '', 10); // Reduced from 12
    $pdf->SetTextColor(0, 0, 0);
    $pdf->SetY(112); // Adjusted position
    $pdf->Cell(0, 10, 'held on ' . date('F d, Y', strtotime($data['training_date'])), 0, 1, 'C');
    
    // Duration and instructor - MORE COMPACT
    $pdf->SetY(120); // Adjusted position (closer together)
    $pdf->Cell(0, 10, 'Duration: ' . $data['duration_hours'] . ' hours | Instructor: ' . $data['instructor'], 0, 1, 'C');
    
    // Add certificate number and dates - UPDATED WITH EXPIRY DATE
    $pdf->SetFont('Arial', '', 9); // Reduced from 10
    $pdf->SetTextColor(80, 80, 80);
    
    // Certificate No (top)
    $pdf->SetY(128);
    $pdf->Cell(0, 10, 'Certificate No: ' . $certificate_number, 0, 1, 'C');
    
    // Issue Date and Expiry Date (centered)
    $pdf->SetY(135);
    $pdf->Cell(0, 10, 'Issued: ' . $issue_date . ' | Valid Until: ' . $expiry_date, 0, 1, 'C');
    
    // Add validity period note
    $pdf->SetFont('Arial', 'I', 8);
    $pdf->SetTextColor(150, 150, 150);
    $pdf->SetY(143);
    $pdf->Cell(0, 10, '(Certificate is valid for 1 year from date of issue)', 0, 1, 'C');
    
    // Add signatures section - BETTER ALIGNMENT AND SPACING
    $pdf->SetFont('Arial', '', 10); // Reduced from 11
    
    // Left signature (Administrator) - BETTER POSITION
    $signatureY = 152; // Adjusted position
    $leftSignatureX = $centerX - 70; // 70mm left of center
    
    $pdf->SetXY($leftSignatureX, $signatureY);
    $pdf->Cell(60, 10, '________________________', 0, 0, 'C');
    
    // Truncate admin name if too long
    $adminDisplayName = strtoupper($admin_name);
    if (strlen($adminDisplayName) > 22) { // Increased threshold
        $adminDisplayName = substr($adminDisplayName, 0, 19) . '...';
    }
    
    $pdf->SetXY($leftSignatureX, $signatureY + 5);
    $pdf->Cell(60, 10, $adminDisplayName, 0, 0, 'C');
    $pdf->SetXY($leftSignatureX, $signatureY + 10);
    $pdf->Cell(60, 10, 'Administrator', 0, 0, 'C');
    
    // Right signature (Instructor) - BETTER POSITION
    if (!empty($data['instructor'])) {
        $rightSignatureX = $centerX + 10; // 10mm right of center
        
        $pdf->SetXY($rightSignatureX, $signatureY);
        $pdf->Cell(60, 10, '________________________', 0, 0, 'C');
        
        // Truncate instructor name if too long
        $instructorName = strtoupper($data['instructor']);
        if (strlen($instructorName) > 22) { // Increased threshold
            $instructorName = substr($instructorName, 0, 19) . '...';
        }
        
        $pdf->SetXY($rightSignatureX, $signatureY + 5);
        $pdf->Cell(60, 10, $instructorName, 0, 0, 'C');
        $pdf->SetXY($rightSignatureX, $signatureY + 10);
        $pdf->Cell(60, 10, 'Instructor', 0, 0, 'C');
    }
    
    // Footer note - SMALLER
    $pdf->SetFont('Arial', 'I', 7); // Reduced from 8
    $pdf->SetTextColor(120, 120, 120);
    $pdf->SetY(172); // Adjusted position
    $pdf->Cell(0, 10, 'This certificate is issued by Fire & Rescue Services Management System', 0, 1, 'C');
    
    // Save certificate
    $certificates_dir = '../../uploads/certificates/';
    if (!file_exists($certificates_dir)) {
        mkdir($certificates_dir, 0777, true);
    }
    
    $filename = 'certificate_' . $registration_id . '_' . time() . '.pdf';
    $filepath = $certificates_dir . $filename;
    $pdf->Output($filepath, 'F');
    
    return 'uploads/certificates/' . $filename;
}

// Function to get training details - UPDATED TO INCLUDE EXPIRY DATE
function getTrainingDetails($registration_id) {
    global $pdo;
    
    $query = "SELECT 
                tr.*,
                v.first_name,
                v.middle_name,
                v.last_name,
                CONCAT(v.first_name, ' ', COALESCE(v.middle_name, ''), ' ', v.last_name) as full_name,
                v.email,
                v.contact_number,
                v.address,
                v.date_of_birth,
                v.volunteer_status,
                t.title as training_title,
                t.description,
                t.training_date,
                t.training_end_date,
                t.duration_hours,
                t.instructor,
                t.location,
                tr.completion_proof,
                tr.completion_notes,
                tc.certificate_number,
                tc.issue_date,
                tc.expiry_date,
                u.first_name as verifier_first_name,
                u.last_name as verifier_last_name
              FROM training_registrations tr
              JOIN volunteers v ON tr.volunteer_id = v.id
              JOIN trainings t ON tr.training_id = t.id
              LEFT JOIN training_certificates tc ON tr.id = tc.registration_id
              LEFT JOIN users u ON tr.completion_verified_by = u.id
              WHERE tr.id = ?";
    
    $stmt = $pdo->prepare($query);
    $stmt->execute([$registration_id]);
    $data = $stmt->fetch();
    
    if ($data) {
        return [
            'success' => true,
            'data' => $data
        ];
    } else {
        return [
            'success' => false,
            'message' => 'Registration not found'
        ];
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Approve Training Completions - Fire & Rescue Services</title>
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

            /* Additional variables for consistency */
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

        /* Font and size from reference */
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            font-size: 14px;
            line-height: 1.5;
            color: var(--text-color);
            background-color: var(--background-color);
            margin: 0;
            padding: 0;
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

        /* COMPLETELY NEW LAYOUT DESIGN */
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

        .review-data-container {
            display: flex;
            flex-direction: column;
            gap: 24px;
            padding: 0 40px 40px;
        }
        
        .review-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 24px;
        }
        
        .review-title {
            font-size: 28px;
            font-weight: 800;
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            margin-bottom: 8px;
        }
        
        .review-subtitle {
            color: var(--text-light);
            font-size: 16px;
        }
        
        .filters-container {
            display: flex;
            gap: 16px;
            margin-bottom: 24px;
            flex-wrap: wrap;
            align-items: flex-end;
            position: relative;
            z-index: 100;
        }
        
        .filter-group {
            display: flex;
            flex-direction: column;
            gap: 8px;
            position: relative;
            z-index: 101;
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
            position: relative;
            z-index: 101;
        }
        
        .filter-select:focus, .filter-input:focus {
            outline: none;
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(220, 38, 38, 0.1);
            z-index: 102;
        }
        
        .stats-container {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
            gap: 16px;
            margin-bottom: 24px;
            position: relative;
            z-index: 1;
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
        
        .stat-card[data-status="completed"]::before {
            background: var(--warning);
        }
        
        .stat-card[data-status="verified"]::before {
            background: var(--info);
        }
        
        .stat-card[data-status="certified"]::before {
            background: var(--success);
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
        
        .stat-card[data-status="completed"] .stat-icon {
            background: rgba(245, 158, 11, 0.1);
            color: var(--warning);
        }
        
        .stat-card[data-status="verified"] .stat-icon {
            background: rgba(59, 130, 246, 0.1);
            color: var(--info);
        }
        
        .stat-card[data-status="certified"] .stat-icon {
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
        
        .completions-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));
            gap: 20px;
        }
        
        .completion-card {
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
            border-radius: 16px;
            padding: 20px;
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
        }
        
        .completion-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.15);
        }
        
        .completion-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(90deg, var(--primary-color), var(--secondary-color));
        }
        
        .completion-header {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            margin-bottom: 16px;
        }
        
        .completion-avatar {
            width: 50px;
            height: 50px;
            border-radius: 50%;
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 700;
            font-size: 18px;
            margin-right: 12px;
        }
        
        .completion-info {
            flex: 1;
        }
        
        .completion-name {
            font-size: 18px;
            font-weight: 700;
            margin-bottom: 4px;
        }
        
        .completion-email {
            color: var(--text-light);
            font-size: 14px;
            margin-bottom: 8px;
        }
        
        .completion-status {
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            text-transform: uppercase;
        }
        
        .status-completed {
            background: rgba(245, 158, 11, 0.1);
            color: var(--warning);
        }
        
        .status-verified {
            background: rgba(59, 130, 246, 0.1);
            color: var(--info);
        }
        
        .status-certified {
            background: rgba(16, 185, 129, 0.1);
            color: var(--success);
        }
        
        .completion-details {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 12px;
            margin-bottom: 20px;
        }
        
        .detail-item {
            display: flex;
            flex-direction: column;
        }
        
        .detail-label {
            font-size: 12px;
            color: var(--text-light);
            margin-bottom: 4px;
        }
        
        .detail-value {
            font-size: 14px;
            font-weight: 500;
        }
        
        .completion-actions {
            display: flex;
            gap: 8px;
        }
        
        .action-button {
            flex: 1;
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
        
        .view-button {
            background-color: rgba(59, 130, 246, 0.1);
            color: var(--info);
        }
        
        .view-button:hover {
            background-color: var(--info);
            color: white;
        }
        
        .approve-button {
            background-color: rgba(16, 185, 129, 0.1);
            color: var(--success);
        }
        
        .approve-button:hover {
            background-color: var(--success);
            color: white;
        }
        
        .reject-button {
            background-color: rgba(220, 38, 38, 0.1);
            color: var(--danger);
        }
        
        .reject-button:hover {
            background-color: var(--danger);
            color: white;
        }
        
        .no-completions {
            text-align: center;
            padding: 60px 20px;
            color: var(--text-light);
            grid-column: 1 / -1;
        }
        
        .no-completions-icon {
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
        
        .proof-container {
            margin-top: 16px;
        }
        
        .proof-image {
            max-width: 100%;
            max-height: 300px;
            border-radius: 12px;
            border: 1px solid var(--border-color);
            object-fit: contain;
            background: #f8f9fa;
        }
        
        .dark-mode .proof-image {
            background: #374151;
        }
        
        .no-proof {
            padding: 40px;
            text-align: center;
            color: var(--text-light);
            background: var(--gray-100);
            border-radius: 12px;
        }

        .dark-mode .no-proof {
            background: var(--gray-800);
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
        
        .modal-approve {
            background: var(--success);
            color: white;
        }
        
        .modal-approve:hover {
            background: #0d8c5f;
        }
        
        .modal-reject {
            background: var(--danger);
            color: white;
        }
        
        .modal-reject:hover {
            background: #c81e1e;
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
        
        /* Notification Styles */
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
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.2);
            display: flex;
            align-items: center;
            gap: 12px;
            transform: translateX(100%);
            opacity: 0;
            transition: all 0.3s ease;
            max-width: 350px;
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

        /* User Profile Dropdown - FIXED POSITIONING */
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
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.2);
            border-radius: 12px;
            padding: 8px;
            min-width: 200px;
            z-index: 1001;
            opacity: 0;
            visibility: hidden;
            transform: translateY(-10px);
            transition: all 0.3s ease;
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
            background: rgba(220, 38, 38, 0.1);
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

        /* Notification Bell - FIXED POSITIONING */
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

        /* Notification Dropdown - FIXED POSITIONING */
        .notification-dropdown {
            position: absolute;
            top: 100%;
            right: 0;
            margin-top: 8px;
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.2);
            border-radius: 12px;
            width: 320px;
            max-height: 400px;
            overflow-y: auto;
            z-index: 1001;
            opacity: 0;
            visibility: hidden;
            transform: translateY(-10px);
            transition: all 0.3s ease;
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
        
        /* Certificate Info Badge */
        .certificate-info {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 6px 12px;
            background: rgba(16, 185, 129, 0.1);
            border-radius: 8px;
            margin-top: 8px;
            font-size: 12px;
        }
        
        .certificate-info i {
            color: var(--success);
        }
        
        .certificate-info.warning {
            background: rgba(245, 158, 11, 0.1);
        }
        
        .certificate-info.warning i {
            color: var(--warning);
        }
        
        .certificate-info.danger {
            background: rgba(220, 38, 38, 0.1);
        }
        
        .certificate-info.danger i {
            color: var(--danger);
        }
        
        @media (max-width: 768px) {
            .completions-grid {
                grid-template-columns: 1fr;
            }
            
            .filters-container {
                flex-direction: column;
            }
            
            .filter-select, .filter-input {
                min-width: 100%;
            }
            
            .modal-grid {
                grid-template-columns: 1fr;
            }
            
            .modal-footer {
                flex-direction: column;
            }

            .dashboard-header {
                padding: 40px 25px 30px;
            }
            
            .dashboard-title {
                font-size: 32px;
            }
            
            .review-data-container {
                padding: 0 25px 30px;
            }
            
            .stats-container {
                grid-template-columns: 1fr;
            }
        }
        
        /* Alert Messages */
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
        
        .alert-error {
            background: rgba(220, 38, 38, 0.1);
            border: 1px solid rgba(220, 38, 38, 0.2);
            color: var(--danger);
        }
        
        .alert-info {
            background: rgba(59, 130, 246, 0.1);
            border: 1px solid rgba(59, 130, 246, 0.2);
            color: var(--info);
        }
        
        .alert i {
            font-size: 20px;
        }
        
        /* Loading overlay for actions */
        .loading-overlay {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.7);
            display: none;
            align-items: center;
            justify-content: center;
            z-index: 2000;
        }
        
        .loading-overlay.active {
            display: flex;
        }
        
        .loading-spinner {
            background: var(--card-bg);
            padding: 30px;
            border-radius: 16px;
            text-align: center;
        }
        
        .loading-spinner i {
            font-size: 48px;
            color: var(--primary-color);
            animation: spin 1s linear infinite;
        }
        
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
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
        <div class="animation-text" id="animation-text">Loading Dashboard...</div>
    </div>
    
    <!-- Loading Overlay for Actions -->
    <div class="loading-overlay" id="loading-overlay">
        <div class="loading-spinner">
            <i class='bx bx-loader-circle bx-spin'></i>
            <p style="margin-top: 15px; color: var(--text-color);">Processing...</p>
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
                        <a href="#" class="submenu-item">Manage Users</a>
                        <a href="#" class="submenu-item">Role Control</a>
                        <a href="#" class="submenu-item">Monitor Activity</a>
                        <a href="#" class="submenu-item">Reset Passwords</a>
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
                        <a href="../review_data.php" class="submenu-item">Review Data</a>
                        <a href="../approve-applications.php" class="submenu-item">Approve Applications</a>
                        <a href="../assign-volunteers.php" class="submenu-item">Assign Volunteers</a>
                        <a href="../view-availability.php" class="submenu-item">View Availability</a>
                        <a href="../remove-volunteers.php" class="submenu-item">Remove Volunteers</a>
                        <a href="../toggle_volunteer_registration.php" class="submenu-item">Toggle Volunteer Registration Access</a>
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
                        <svg class="dropdown-arrow menu-icon rotated" fill="none" stroke="currentColor" viewBox="0 0 24 24">
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
                    <div class="menu-item active" onclick="toggleSubmenu('training-management')">
                        <div class="icon-box icon-bg-teal">
                            <i class='bx bxs-graduation icon-teal'></i>
                        </div>
                        <span class="font-medium">Training Management</span>
                        <svg class="dropdown-arrow menu-icon rotated" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                        </svg>
                    </div>
                    <div id="training-management" class="submenu active">
                        <a href="approve_completions.php" class="submenu-item active">Approve Completions</a>
                        <a href="view_training_records.php" class="submenu-item">View Records</a>
                        <a href="assign_training.php" class="submenu-item">Assign Training</a>
                        <a href="track_expiry.php" class="submenu-item">Track Expiry</a>
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
                            <input type="text" placeholder="Search completions..." class="search-input" id="search-input" value="<?php echo htmlspecialchars($search_term); ?>">
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
                        <button class="header-button">
                            <svg class="header-button-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"></path>
                            </svg>
                        </button>
                        <div class="notification-bell">
                            <button class="header-button" id="notification-bell">
                                <svg class="header-button-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"></path>
                                </svg>
                            </button>
                            <div class="notification-badge" id="notification-count">3</div>
                            <div class="notification-dropdown" id="notification-dropdown">
                                <div class="notification-header">
                                    <h3 class="notification-title">Notifications</h3>
                                    <button class="notification-clear">Clear All</button>
                                </div>
                                <div class="notification-list" id="notification-list">
                                    <div class="notification-item unread">
                                        <i class='bx bxs-user-plus notification-item-icon' style="color: var(--success);"></i>
                                        <div class="notification-item-content">
                                            <div class="notification-item-title">New Volunteer Application</div>
                                            <div class="notification-item-message">Maria Santos submitted a volunteer application</div>
                                            <div class="notification-item-time">5 minutes ago</div>
                                        </div>
                                    </div>
                                    <div class="notification-item unread">
                                        <i class='bx bxs-bell-ring notification-item-icon' style="color: var(--warning);"></i>
                                        <div class="notification-item-content">
                                            <div class="notification-item-title">Training Reminder</div>
                                            <div class="notification-item-message">Basic Firefighting training scheduled for tomorrow</div>
                                            <div class="notification-item-time">1 hour ago</div>
                                        </div>
                                    </div>
                                    <div class="notification-item">
                                        <i class='bx bxs-check-circle notification-item-icon' style="color: var(--success);"></i>
                                        <div class="notification-item-content">
                                            <div class="notification-item-title">Application Approved</div>
                                            <div class="notification-item-message">Carlos Mendoza's application was approved</div>
                                            <div class="notification-item-time">2 hours ago</div>
                                        </div>
                                    </div>
                                    <div class="notification-item">
                                        <i class='bx bxs-error notification-item-icon' style="color: var(--danger);"></i>
                                        <div class="notification-item-content">
                                            <div class="notification-item-title">System Update</div>
                                            <div class="notification-item-message">Scheduled maintenance this weekend</div>
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
                <?php if (isset($_GET['success'])): ?>
                    <?php if ($_GET['success'] == 1): ?>
                        <div class="alert alert-success">
                            <i class='bx bx-check-circle'></i>
                            <div>
                                <strong>Success!</strong> Training completion approved and certificate generated successfully.
                                <?php if (isset($_GET['registration_id'])): ?>
                                    <br><small>Registration ID: <?php echo htmlspecialchars($_GET['registration_id']); ?></small>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php elseif ($_GET['success'] == 2): ?>
                        <div class="alert alert-info">
                            <i class='bx bx-info-circle'></i>
                            <div>
                                <strong>Completed!</strong> Training completion has been rejected.
                            </div>
                        </div>
                    <?php endif; ?>
                <?php elseif (isset($_GET['error'])): ?>
                    <div class="alert alert-error">
                        <i class='bx bx-error'></i>
                        <div>
                            <strong>Error!</strong> <?php echo htmlspecialchars($_GET['error']); ?>
                        </div>
                    </div>
                <?php endif; ?>
                
                <div class="dashboard-header">
                    <div>
                        <h1 class="dashboard-title">Approve Training Completions</h1>
                        <p class="dashboard-subtitle">Review and approve training completions submitted by employees</p>
                    </div>
                    <div class="dashboard-actions">
                        <button class="primary-button" id="export-button">
                            <i class='bx bx-export'></i>
                            Export Reports
                        </button>
                        <button class="secondary-button" id="refresh-button">
                            <i class='bx bx-refresh'></i>
                            Refresh Data
                        </button>
                    </div>
                </div>
                
                <!-- Review Data Section -->
                <div class="review-data-container">
                    <!-- Stats Cards -->
                    <div class="stats-container">
                        <div class="stat-card <?php echo $status_filter === 'all' ? 'active' : ''; ?>" data-status="all">
                            <div class="stat-icon">
                                <i class='bx bxs-graduation'></i>
                            </div>
                            <div class="stat-value"><?php echo array_sum($status_counts); ?></div>
                            <div class="stat-label">Total Completions</div>
                        </div>
                        <div class="stat-card <?php echo $status_filter === 'completed' ? 'active' : ''; ?>" data-status="completed">
                            <div class="stat-icon">
                                <i class='bx bx-time-five'></i>
                            </div>
                            <div class="stat-value"><?php echo $status_counts['completed']; ?></div>
                            <div class="stat-label">Completed</div>
                        </div>
                        <div class="stat-card <?php echo $status_filter === 'verified' ? 'active' : ''; ?>" data-status="verified">
                            <div class="stat-icon">
                                <i class='bx bx-check-shield'></i>
                            </div>
                            <div class="stat-value"><?php echo $status_counts['verified']; ?></div>
                            <div class="stat-label">Awaiting Approval</div>
                        </div>
                        <div class="stat-card <?php echo $status_filter === 'certified' ? 'active' : ''; ?>" data-status="certified">
                            <div class="stat-icon">
                                <i class='bx bx-certification'></i>
                            </div>
                            <div class="stat-value"><?php echo $status_counts['certified']; ?></div>
                            <div class="stat-label">Certified</div>
                        </div>
                    </div>
                    
                    <!-- Enhanced Filters -->
                    <div class="filters-container">
                        <div class="filter-group">
                            <label class="filter-label">Status</label>
                            <select class="filter-select" id="status-filter">
                                <option value="all" <?php echo $status_filter === 'all' ? 'selected' : ''; ?>>All Statuses</option>
                                <option value="completed" <?php echo $status_filter === 'completed' ? 'selected' : ''; ?>>Completed</option>
                                <option value="verified" <?php echo $status_filter === 'verified' ? 'selected' : ''; ?>>Awaiting Approval</option>
                                <option value="certified" <?php echo $status_filter === 'certified' ? 'selected' : ''; ?>>Certified</option>
                            </select>
                        </div>
                        <div class="filter-group">
                            <label class="filter-label">Training</label>
                            <select class="filter-select" id="training-filter">
                                <option value="all">All Trainings</option>
                                <?php foreach ($all_trainings as $training): ?>
                                    <option value="<?php echo $training['id']; ?>" <?php echo $training_filter == $training['id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($training['title']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="filter-group">
                            <label class="filter-label">Search</label>
                            <input type="text" class="filter-input" id="search-filter" placeholder="Search by volunteer or training..." value="<?php echo htmlspecialchars($search_term); ?>">
                        </div>
                        <div class="filter-group" style="align-self: flex-end;">
                            <button class="action-button view-button" id="apply-filters">
                                <i class='bx bx-filter-alt'></i>
                                Apply Filters
                            </button>
                        </div>
                        <div class="filter-group" style="align-self: flex-end;">
                            <button class="action-button reject-button" id="reset-filters">
                                <i class='bx bx-reset'></i>
                                Reset
                            </button>
                        </div>
                    </div>
                    
                    <!-- Completions Grid -->
                    <div class="completions-grid">
                        <?php if (count($completions) > 0): ?>
                            <?php foreach ($completions as $completion): ?>
                                <div class="completion-card" data-id="<?php echo $completion['id']; ?>">
                                    <div class="completion-header">
                                        <div class="completion-avatar">
                                            <?php echo strtoupper(substr($completion['full_name'], 0, 1)); ?>
                                        </div>
                                        <div class="completion-info">
                                            <h3 class="completion-name"><?php echo htmlspecialchars($completion['full_name']); ?></h3>
                                            <p class="completion-email"><?php echo htmlspecialchars($completion['email']); ?></p>
                                        </div>
                                        <div class="completion-status status-<?php echo $completion['certificate_issued'] ? 'certified' : 'verified'; ?>">
                                            <?php echo $completion['certificate_issued'] ? 'Certified' : 'Verified'; ?>
                                        </div>
                                    </div>
                                    
                                    <div class="completion-details">
                                        <div class="detail-item">
                                            <div class="detail-label">Training</div>
                                            <div class="detail-value"><?php echo htmlspecialchars($completion['training_title']); ?></div>
                                        </div>
                                        <div class="detail-item">
                                            <div class="detail-label">Volunteer Status</div>
                                            <div class="detail-value"><?php echo htmlspecialchars($completion['volunteer_status']); ?></div>
                                        </div>
                                        <div class="detail-item">
                                            <div class="detail-label">Training Date</div>
                                            <div class="detail-value"><?php echo date('M d, Y', strtotime($completion['training_date'])); ?></div>
                                        </div>
                                        <div class="detail-item">
                                            <div class="detail-label">Duration</div>
                                            <div class="detail-value"><?php echo $completion['duration_hours']; ?> hours</div>
                                        </div>
                                    </div>
                                    
                                    <div class="completion-actions">
                                        <button class="action-button view-button" onclick="viewCompletion(<?php echo $completion['id']; ?>)">
                                            <i class='bx bx-show'></i>
                                            View Details
                                        </button>
                                        <?php if (!$completion['certificate_issued']): ?>
                                            <button class="action-button approve-button" onclick="approveCompletion(<?php echo $completion['id']; ?>)">
                                                <i class='bx bx-check'></i>
                                                Approve
                                            </button>
                                            <button class="action-button reject-button" onclick="rejectCompletion(<?php echo $completion['id']; ?>)">
                                                <i class='bx bx-x'></i>
                                                Reject
                                            </button>
                                        <?php else: ?>
                                            <button class="action-button view-button" style="width: 100%;" onclick="viewCompletion(<?php echo $completion['id']; ?>)">
                                                <i class='bx bx-certification'></i>
                                                View Certificate
                                            </button>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="no-completions">
                                <div class="no-completions-icon">
                                    <i class='bx bx-check-shield'></i>
                                </div>
                                <h3>No Training Completions Found</h3>
                                <p>No training completions match your current filters.</p>
                                <?php if ($status_filter === 'verified'): ?>
                                    <p style="margin-top: 10px;">All completions have been approved!</p>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Completion Details Modal -->
    <div class="modal-overlay" id="completion-modal">
        <div class="modal">
            <div class="modal-header">
                <h2 class="modal-title">Training Completion Details</h2>
                <button class="modal-close" id="modal-close">&times;</button>
            </div>
            <div class="modal-body" id="modal-body">
                <!-- Content will be loaded via JavaScript -->
            </div>
            <div class="modal-footer">
                <button class="modal-button modal-secondary" id="modal-close-btn">Close</button>
                <button class="modal-button modal-reject" id="modal-reject-btn">Reject</button>
                <button class="modal-button modal-approve" id="modal-approve-btn">Approve & Generate Certificate</button>
            </div>
        </div>
    </div>
    
    <script>
        // Global variables
        let currentCompletionId = null;
        
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
            
            // Show welcome notification
            showNotification('success', 'System Ready', 'Training completion approval system is now active');
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
                    document.getElementById('status-filter').value = status;
                    applyFilters();
                });
            });
            
            // Modal functionality
            document.getElementById('modal-close').addEventListener('click', closeModal);
            document.getElementById('modal-close-btn').addEventListener('click', closeModal);
            document.getElementById('modal-approve-btn').addEventListener('click', function() {
                if (currentCompletionId) {
                    approveCompletion(currentCompletionId);
                }
            });
            document.getElementById('modal-reject-btn').addEventListener('click', function() {
                if (currentCompletionId) {
                    rejectCompletion(currentCompletionId);
                }
            });
            
            // Export and refresh buttons
            document.getElementById('export-button').addEventListener('click', exportReports);
            document.getElementById('refresh-button').addEventListener('click', refreshData);
            
            // Keyboard shortcuts
            document.addEventListener('keydown', function(e) {
                // Search shortcut - forward slash
                if (e.key === '/' && !e.ctrlKey && !e.altKey && !e.metaKey) {
                    e.preventDefault();
                    document.getElementById('search-input').focus();
                }
                
                // Escape key to close modal
                if (e.key === 'Escape') {
                    closeModal();
                    userDropdown.classList.remove('show');
                    notificationDropdown.classList.remove('show');
                }
            });
        }
        
        function applyFilters() {
            const status = document.getElementById('status-filter').value;
            const training = document.getElementById('training-filter').value;
            const search = document.getElementById('search-filter').value;
            
            let url = 'approve_completions.php?';
            if (status !== 'all') {
                url += `status=${status}&`;
            }
            if (training !== 'all') {
                url += `training=${training}&`;
            }
            if (search) {
                url += `search=${encodeURIComponent(search)}`;
            }
            
            window.location.href = url;
        }
        
        function resetFilters() {
            document.getElementById('status-filter').value = 'all';
            document.getElementById('training-filter').value = 'all';
            document.getElementById('search-filter').value = '';
            applyFilters();
        }
        
        function viewCompletion(id) {
            currentCompletionId = id;
            
            // Show loading state
            document.getElementById('modal-body').innerHTML = `
                <div style="text-align: center; padding: 40px;">
                    <i class='bx bx-loader-circle bx-spin' style="font-size: 48px; color: var(--primary-color);"></i>
                    <p style="margin-top: 16px; color: var(--text-light);">Loading completion details...</p>
                </div>
            `;
            
            // Hide action buttons initially
            document.getElementById('modal-reject-btn').style.display = 'none';
            document.getElementById('modal-approve-btn').style.display = 'none';
            
            // Show modal
            document.getElementById('completion-modal').classList.add('active');
            
            // Fetch completion details via AJAX
            fetch(`approve_completions.php?ajax=true&get_training_details=true&id=${id}`)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        populateModal(data.data);
                    } else {
                        showNotification('error', 'Error', 'Failed to load completion details');
                        closeModal();
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    showNotification('error', 'Error', 'Failed to load completion details');
                    closeModal();
                });
        }
        
        function populateModal(data) {
            const modalBody = document.getElementById('modal-body');
            
            // Check if already certified
            const isCertified = data.certificate_issued == 1;
            
            // Build the full name properly
            const fullName = `${data.first_name} ${data.middle_name ? data.middle_name + ' ' : ''}${data.last_name}`;
            
            let html = `
                <div class="modal-section">
                    <h3 class="modal-section-title">Volunteer Information</h3>
                    <div class="modal-grid">
                        <div class="modal-detail">
                            <div class="modal-detail-label">Full Name</div>
                            <div class="modal-detail-value">${fullName}</div>
                        </div>
                        <div class="modal-detail">
                            <div class="modal-detail-label">Email</div>
                            <div class="modal-detail-value">${data.email}</div>
                        </div>
                        <div class="modal-detail">
                            <div class="modal-detail-label">Contact Number</div>
                            <div class="modal-detail-value">${data.contact_number || 'N/A'}</div>
                        </div>
                        <div class="modal-detail">
                            <div class="modal-detail-label">Date of Birth</div>
                            <div class="modal-detail-value">${data.date_of_birth || 'N/A'}</div>
                        </div>
                        <div class="modal-detail">
                            <div class="modal-detail-label">Address</div>
                            <div class="modal-detail-value">${data.address || 'N/A'}</div>
                        </div>
                        <div class="modal-detail">
                            <div class="modal-detail-label">Volunteer Status</div>
                            <div class="modal-detail-value">
                                ${data.volunteer_status}
                                ${data.volunteer_status === 'New Volunteer' ? '<br><small style="color: var(--warning);">Will be activated after approval</small>' : ''}
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="modal-section">
                    <h3 class="modal-section-title">Training Information</h3>
                    <div class="modal-grid">
                        <div class="modal-detail">
                            <div class="modal-detail-label">Training Title</div>
                            <div class="modal-detail-value">${data.training_title}</div>
                        </div>
                        <div class="modal-detail">
                            <div class="modal-detail-label">Training Dates</div>
                            <div class="modal-detail-value">
                                ${data.training_date} to ${data.training_end_date}
                            </div>
                        </div>
                        <div class="modal-detail">
                            <div class="modal-detail-label">Duration</div>
                            <div class="modal-detail-value">${data.duration_hours} hours</div>
                        </div>
                        <div class="modal-detail">
                            <div class="modal-detail-label">Instructor</div>
                            <div class="modal-detail-value">${data.instructor}</div>
                        </div>
                        <div class="modal-detail">
                            <div class="modal-detail-label">Location</div>
                            <div class="modal-detail-value">${data.location}</div>
                        </div>
                    </div>
                </div>
            `;
            
            // Add training description
            if (data.description) {
                html += `
                    <div class="modal-section">
                        <h3 class="modal-section-title">Training Description</h3>
                        <div class="modal-detail">
                            <div class="modal-detail-value">${data.description}</div>
                        </div>
                    </div>
                `;
            }
            
            // Add certificate info if already certified
            if (isCertified) {
                const issueDate = data.issue_date ? new Date(data.issue_date).toLocaleDateString('en-US', { year: 'numeric', month: 'long', day: 'numeric' }) : 'N/A';
                const expiryDate = data.expiry_date ? new Date(data.expiry_date).toLocaleDateString('en-US', { year: 'numeric', month: 'long', day: 'numeric' }) : 'N/A';
                const today = new Date();
                const expiry = new Date(data.expiry_date);
                const daysUntilExpiry = Math.ceil((expiry - today) / (1000 * 60 * 60 * 24));
                
                let certificateClass = 'certificate-info';
                let certificateIcon = 'bx bx-certification';
                let certificateText = '';
                
                if (daysUntilExpiry > 60) {
                    certificateClass += ' success';
                    certificateText = `<i class='bx bx-certification'></i> Certificate valid until ${expiryDate} (${daysUntilExpiry} days remaining)`;
                } else if (daysUntilExpiry > 0) {
                    certificateClass += ' warning';
                    certificateText = `<i class='bx bx-time-five'></i> Certificate expires in ${daysUntilExpiry} days (${expiryDate})`;
                } else {
                    certificateClass += ' danger';
                    certificateText = `<i class='bx bx-error-circle'></i> Certificate expired on ${expiryDate}`;
                }
                
                html += `
                    <div class="modal-section">
                        <h3 class="modal-section-title" style="color: var(--success);">
                            <i class='bx bx-certification'></i> Certificate Information
                        </h3>
                        <div class="modal-grid">
                            <div class="modal-detail">
                                <div class="modal-detail-label">Certificate Number</div>
                                <div class="modal-detail-value">${data.certificate_number || 'N/A'}</div>
                            </div>
                            <div class="modal-detail">
                                <div class="modal-detail-label">Issue Date</div>
                                <div class="modal-detail-value">${issueDate}</div>
                            </div>
                            <div class="modal-detail">
                                <div class="modal-detail-label">Expiry Date</div>
                                <div class="modal-detail-value">${expiryDate}</div>
                            </div>
                        </div>
                        <div class="${certificateClass}">
                            ${certificateText}
                        </div>
                    </div>
                `;
            }
            
            // Add completion proof if available
            if (data.completion_proof) {
                const proofPath = '../../uploads/training_proofs/' + data.completion_proof;
                html += `
                    <div class="modal-section">
                        <h3 class="modal-section-title">Completion Proof</h3>
                        <div class="proof-container">
                            <img src="${proofPath}" alt="Completion Proof" class="proof-image" onerror="this.style.display='none'; this.nextElementSibling.style.display='block';">
                            <div class="no-proof" style="display: none;">Proof image not available</div>
                            <p style="margin-top: 10px; font-size: 12px; color: var(--text-light);">
                                Verified by: ${data.verifier_first_name || 'N/A'} ${data.verifier_last_name || ''}
                            </p>
                        </div>
                    </div>
                `;
            }
            
            // Add completion notes if available - FIXED: Only show once
            if (data.completion_notes) {
                // Remove duplicate entries if they exist
                let notes = data.completion_notes;
                // If there are duplicate lines, only show unique ones
                const lines = notes.split('\n').filter(line => line.trim() !== '');
                const uniqueLines = [...new Set(lines)];
                
                html += `
                    <div class="modal-section">
                        <h3 class="modal-section-title">Employee Verification Notes</h3>
                        <div class="modal-detail">
                            <div class="modal-detail-value" style="white-space: pre-wrap; background: var(--gray-100); padding: 15px; border-radius: 8px;">
                                ${uniqueLines.join('\n')}
                            </div>
                        </div>
                    </div>
                `;
            }
            
            modalBody.innerHTML = html;
            
            // Show/hide action buttons based on certification status
            if (!isCertified) {
                document.getElementById('modal-reject-btn').style.display = 'inline-block';
                document.getElementById('modal-approve-btn').style.display = 'inline-block';
                document.getElementById('modal-approve-btn').innerHTML = '<i class="bx bx-check"></i> Approve & Generate Certificate (1 Year Validity)';
            } else {
                document.getElementById('modal-reject-btn').style.display = 'none';
                document.getElementById('modal-approve-btn').style.display = 'none';
                document.getElementById('modal-close-btn').textContent = 'Close';
            }
        }
        
        function closeModal() {
            document.getElementById('completion-modal').classList.remove('active');
            currentCompletionId = null;
        }
        
        function approveCompletion(id) {
            if (confirm('Are you sure you want to approve this training completion?\n\nThis will:\n1. Generate a certificate with 1-year validity\n2. Activate the volunteer if they are "New Volunteer"\n3. Update their training status to "certified"')) {
                showLoading();
                
                // Create form and submit
                const form = document.createElement('form');
                form.method = 'POST';
                form.action = 'approve_completions.php';
                
                const actionInput = document.createElement('input');
                actionInput.type = 'hidden';
                actionInput.name = 'action';
                actionInput.value = 'approve_completion';
                
                const idInput = document.createElement('input');
                idInput.type = 'hidden';
                idInput.name = 'registration_id';
                idInput.value = id;
                
                form.appendChild(actionInput);
                form.appendChild(idInput);
                document.body.appendChild(form);
                form.submit();
            }
        }
        
        function rejectCompletion(id) {
            const reason = prompt('Please enter the reason for rejection:');
            if (reason !== null) {
                if (reason.trim() === '') {
                    alert('Please provide a reason for rejection.');
                    return;
                }
                
                if (confirm('Are you sure you want to reject this training completion?')) {
                    showLoading();
                    
                    // Create form and submit
                    const form = document.createElement('form');
                    form.method = 'POST';
                    form.action = 'approve_completions.php';
                    
                    const actionInput = document.createElement('input');
                    actionInput.type = 'hidden';
                    actionInput.name = 'action';
                    actionInput.value = 'reject_completion';
                    
                    const idInput = document.createElement('input');
                    idInput.type = 'hidden';
                    idInput.name = 'registration_id';
                    idInput.value = id;
                    
                    form.appendChild(actionInput);
                    form.appendChild(idInput);
                    document.body.appendChild(form);
                    form.submit();
                }
            }
        }
        
        function showLoading() {
            document.getElementById('loading-overlay').classList.add('active');
        }
        
        function hideLoading() {
            document.getElementById('loading-overlay').classList.remove('active');
        }
        
        function exportReports() {
            showNotification('info', 'Export Started', 'Your report is being generated and will download shortly');
            // In a real implementation, you would trigger the export process
        }
        
        function refreshData() {
            showNotification('info', 'Refreshing Data', 'Fetching the latest training completions');
            location.reload();
        }
        
        function showNotification(type, title, message, playSound = false) {
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
            
            // Play sound if requested
            if (playSound) {
                playNotificationSound();
            }
            
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
        
        function playNotificationSound() {
            // Create a simple notification sound
            try {
                const audioContext = new (window.AudioContext || window.webkitAudioContext)();
                const oscillator = audioContext.createOscillator();
                const gainNode = audioContext.createGain();
                
                oscillator.connect(gainNode);
                gainNode.connect(audioContext.destination);
                
                oscillator.frequency.value = 800;
                oscillator.type = 'sine';
                
                gainNode.gain.setValueAtTime(0, audioContext.currentTime);
                gainNode.gain.linearRampToValueAtTime(0.1, audioContext.currentTime + 0.01);
                gainNode.gain.exponentialRampToValueAtTime(0.001, audioContext.currentTime + 0.5);
                
                oscillator.start(audioContext.currentTime);
                oscillator.stop(audioContext.currentTime + 0.5);
            } catch (e) {
                console.log('Audio context not supported');
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
        
        updateTime();
        setInterval(updateTime, 1000);
    </script>
</body>
</html>