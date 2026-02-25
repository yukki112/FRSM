<?php
session_start();
require_once '../../config/db_connection.php';

// Check if FPDF exists before requiring it
$fpdf_path = '../../vendor/setasign/fpdf/fpdf.php';
if (file_exists($fpdf_path)) {
    require_once($fpdf_path);
} else {
    // Alternative paths to try
    $alt_paths = [
        '../../vendor/fpdf/fpdf.php',
        '../../fpdf/fpdf.php',
        '../../libs/fpdf/fpdf.php',
        '../../includes/fpdf/fpdf.php'
    ];
    
    $fpdf_found = false;
    foreach ($alt_paths as $path) {
        if (file_exists($path)) {
            require_once($path);
            $fpdf_found = true;
            break;
        }
    }
    
    // If still not found, define a fallback class
    if (!$fpdf_found && !class_exists('FPDF')) {
        // Create a minimal FPDF class to prevent fatal error
        class FPDF {
            function __construct($orientation = 'P', $unit = 'mm', $size = 'A4') {}
            function AddPage($orientation = '', $size = '') {}
            function SetMargins($left, $top, $right = -1) {}
            function SetDrawColor($r, $g = -1, $b = -1) {}
            function SetLineWidth($width) {}
            function Rect($x, $y, $w, $h, $style = '') {}
            function Image($file, $x = null, $y = null, $w = 0, $h = 0, $type = '', $link = '') {}
            function SetFont($family, $style = '', $size = 0) {}
            function SetTextColor($r, $g = -1, $b = -1) {}
            function SetY($y) {}
            function Cell($w, $h = 0, $txt = '', $border = 0, $ln = 0, $align = '', $fill = false, $link = '') {}
            function MultiCell($w, $h, $txt, $border = 0, $align = 'J', $fill = false) {}
            function Line($x1, $y1, $x2, $y2) {}
            function SetXY($x, $y) {}
            function Output($dest = '', $name = '', $isUTF8 = false) { return ''; }
        }
    }
}

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
            case 'approve_experienced_volunteer':
                approveExperiencedVolunteer($_POST['volunteer_id'], $_POST['experience_years'], $_POST['proof_path']);
                break;
            case 'reject_experienced_volunteer':
                rejectExperiencedVolunteer($_POST['volunteer_id']);
                break;
        }
    }
}

// Handle AJAX requests
if (isset($_GET['ajax']) && $_GET['ajax'] === 'true') {
    header('Content-Type: application/json');
    
    if (isset($_GET['get_training_details'])) {
        echo json_encode(getTrainingDetails($_GET['id']));
        exit();
    } elseif (isset($_GET['get_experienced_volunteer_details'])) {
        echo json_encode(getExperiencedVolunteerDetails($_GET['id']));
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

// Check if experienced_volunteer_requests table exists
$table_check = $pdo->query("SHOW TABLES LIKE 'experienced_volunteer_requests'");
$experienced_requests = [];

if ($table_check->rowCount() == 0) {
    // Create the table if it doesn't exist
    try {
        $create_table_sql = "
            CREATE TABLE IF NOT EXISTS `experienced_volunteer_requests` (
                `id` int(11) NOT NULL AUTO_INCREMENT,
                `volunteer_id` int(11) NOT NULL,
                `experience_years` int(11) NOT NULL,
                `proof_path` varchar(255) DEFAULT NULL,
                `status` enum('pending','approved','rejected') DEFAULT 'pending',
                `review_notes` text DEFAULT NULL,
                `reviewed_by` int(11) DEFAULT NULL,
                `reviewed_at` datetime DEFAULT NULL,
                `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
                `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
                PRIMARY KEY (`id`),
                KEY `idx_volunteer_id` (`volunteer_id`),
                KEY `idx_status` (`status`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
        ";
        $pdo->exec($create_table_sql);
        
        $create_proof_table = "
            CREATE TABLE IF NOT EXISTS `experience_proofs` (
                `id` int(11) NOT NULL AUTO_INCREMENT,
                `request_id` int(11) NOT NULL,
                `proof_type` enum('certificate','employment_record','recommendation','other') NOT NULL,
                `file_path` varchar(255) NOT NULL,
                `description` text DEFAULT NULL,
                `uploaded_at` timestamp NOT NULL DEFAULT current_timestamp(),
                PRIMARY KEY (`id`),
                KEY `idx_request_id` (`request_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
        ";
        $pdo->exec($create_proof_table);
        
        // Add foreign key constraints separately
        try {
            $pdo->exec("ALTER TABLE `experienced_volunteer_requests` ADD CONSTRAINT `fk_experienced_volunteer` FOREIGN KEY (`volunteer_id`) REFERENCES `volunteers` (`id`) ON DELETE CASCADE");
        } catch (Exception $e) {
            // Constraint might already exist
        }
        
        try {
            $pdo->exec("ALTER TABLE `experienced_volunteer_requests` ADD CONSTRAINT `fk_experienced_reviewed_by` FOREIGN KEY (`reviewed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL");
        } catch (Exception $e) {
            // Constraint might already exist
        }
        
        try {
            $pdo->exec("ALTER TABLE `experience_proofs` ADD CONSTRAINT `fk_proof_request` FOREIGN KEY (`request_id`) REFERENCES `experienced_volunteer_requests` (`id`) ON DELETE CASCADE");
        } catch (Exception $e) {
            // Constraint might already exist
        }
    } catch (Exception $e) {
        // Table creation failed, but we'll continue with empty array
        error_log("Failed to create experienced volunteer tables: " . $e->getMessage());
    }
} else {
    // Fetch experienced volunteers awaiting approval
    $experienced_query = "
        SELECT 
            v.*,
            CONCAT(v.first_name, ' ', COALESCE(v.middle_name, ''), ' ', v.last_name) as full_name,
            erv.experience_years,
            erv.proof_path,
            erv.created_at as request_date,
            erv.status as request_status,
            erv.review_notes
        FROM volunteers v
        JOIN experienced_volunteer_requests erv ON v.id = erv.volunteer_id
        WHERE erv.status = 'pending'
        ORDER BY erv.created_at DESC
    ";
    
    try {
        $experienced_stmt = $pdo->prepare($experienced_query);
        $experienced_stmt->execute();
        $experienced_requests = $experienced_stmt->fetchAll();
    } catch (Exception $e) {
        $experienced_requests = [];
        error_log("Failed to fetch experienced requests: " . $e->getMessage());
    }
}

// Function to approve training completion
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
        
        // Insert certificate record
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

// Function to approve experienced volunteer
function approveExperiencedVolunteer($volunteer_id, $experience_years, $proof_path) {
    global $pdo, $user_id;
    
    try {
        $pdo->beginTransaction();
        
        // Update volunteer status to Active and mark as experienced
        $volunteer_query = "UPDATE volunteers 
                           SET volunteer_status = 'Active',
                               training_completion_status = 'certified',
                               active_since = CURDATE(),
                               notes = CONCAT(COALESCE(notes, ''), '\nApproved as experienced volunteer with ', ?, ' years of experience on ', CURDATE())
                           WHERE id = ?";
        $volunteer_stmt = $pdo->prepare($volunteer_query);
        $volunteer_stmt->execute([$experience_years, $volunteer_id]);
        
        // Update the request status
        $request_query = "UPDATE experienced_volunteer_requests 
                         SET status = 'approved',
                             reviewed_by = ?,
                             reviewed_at = NOW()
                         WHERE volunteer_id = ? AND status = 'pending'";
        $request_stmt = $pdo->prepare($request_query);
        $request_stmt->execute([$user_id, $volunteer_id]);
        
        // Create a notification for the volunteer
        $notification_query = "INSERT INTO notifications (user_id, type, title, message, created_at)
                              SELECT user_id, 'experience_approved', 'Experienced Volunteer Approved', 
                              CONCAT('Your application as an experienced volunteer with ', ?, ' years of experience has been approved. You are now an active volunteer.'),
                              NOW()
                              FROM volunteers WHERE id = ? AND user_id IS NOT NULL";
        $notification_stmt = $pdo->prepare($notification_query);
        $notification_stmt->execute([$experience_years, $volunteer_id]);
        
        $pdo->commit();
        
        header("Location: approve_completions.php?success=3&volunteer_id=" . $volunteer_id);
        exit();
        
    } catch (Exception $e) {
        $pdo->rollBack();
        header("Location: approve_completions.php?error=" . urlencode($e->getMessage()));
        exit();
    }
}

// Function to reject experienced volunteer
function rejectExperiencedVolunteer($volunteer_id) {
    global $pdo, $user_id;
    
    try {
        $pdo->beginTransaction();
        
        // Update the request status
        $request_query = "UPDATE experienced_volunteer_requests 
                         SET status = 'rejected',
                             reviewed_by = ?,
                             reviewed_at = NOW()
                         WHERE volunteer_id = ? AND status = 'pending'";
        $request_stmt = $pdo->prepare($request_query);
        $request_stmt->execute([$user_id, $volunteer_id]);
        
        // Create a notification for the volunteer
        $notification_query = "INSERT INTO notifications (user_id, type, title, message, created_at)
                              SELECT user_id, 'experience_rejected', 'Experienced Volunteer Application Update', 
                              'Your application as an experienced volunteer has been reviewed. Please complete the required training to become an active volunteer.',
                              NOW()
                              FROM volunteers WHERE id = ? AND user_id IS NOT NULL";
        $notification_stmt = $pdo->prepare($notification_query);
        $notification_stmt->execute([$volunteer_id]);
        
        $pdo->commit();
        
        header("Location: approve_completions.php?success=4&volunteer_id=" . $volunteer_id);
        exit();
        
    } catch (Exception $e) {
        $pdo->rollBack();
        header("Location: approve_completions.php?error=" . urlencode($e->getMessage()));
        exit();
    }
}

// Function to get experienced volunteer details
function getExperiencedVolunteerDetails($volunteer_id) {
    global $pdo;
    
    try {
        $query = "SELECT 
                    v.*,
                    CONCAT(v.first_name, ' ', COALESCE(v.middle_name, ''), ' ', v.last_name) as full_name,
                    erv.id as request_id,
                    erv.experience_years,
                    erv.proof_path,
                    erv.created_at as request_date,
                    erv.status as request_status,
                    erv.review_notes,
                    (SELECT GROUP_CONCAT(CONCAT(proof_type, ':', file_path) SEPARATOR '|') 
                     FROM experience_proofs WHERE request_id = erv.id) as proofs
                  FROM volunteers v
                  JOIN experienced_volunteer_requests erv ON v.id = erv.volunteer_id
                  WHERE v.id = ?";
        
        $stmt = $pdo->prepare($query);
        $stmt->execute([$volunteer_id]);
        $data = $stmt->fetch();
        
        if ($data) {
            // Parse proofs
            $proofs = [];
            if (!empty($data['proofs'])) {
                $proof_parts = explode('|', $data['proofs']);
                foreach ($proof_parts as $part) {
                    if (strpos($part, ':') !== false) {
                        list($type, $path) = explode(':', $part, 2);
                        $proofs[] = ['type' => $type, 'path' => $path];
                    }
                }
            }
            $data['proofs_array'] = $proofs;
            
            return [
                'success' => true,
                'data' => $data
            ];
        } else {
            return [
                'success' => false,
                'message' => 'Volunteer not found'
            ];
        }
    } catch (Exception $e) {
        return [
            'success' => false,
            'message' => 'Error: ' . $e->getMessage()
        ];
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

// Function to generate PDF certificate
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
    
    // Check if FPDF class exists
    if (!class_exists('FPDF')) {
        // Return a placeholder path since PDF generation failed
        error_log("FPDF class not found - certificate generation failed");
        return 'certificates/generation_failed.txt';
    }
    
    try {
        // Create PDF in landscape A4
        $pdf = new FPDF('L', 'mm', 'A4');
        $pdf->AddPage();
        
        // Set margins
        $pdf->SetMargins(25, 20, 25);
        
        // Get page width (297mm for A4 landscape)
        $pageWidth = 297;
        
        // Add decorative border
        $pdf->SetDrawColor(220, 38, 38);
        $pdf->SetLineWidth(1.5);
        $pdf->Rect(15, 15, 267, 180);
        
        // Add logo
        $logo_path = '../../img/frsm-logo.png';
        if (file_exists($logo_path)) {
            $pdf->Image($logo_path, 29, 22, 26);
        }
        
        // Calculate center position
        $centerX = $pageWidth / 2;
        
        // Add certificate header
        $pdf->SetFont('Arial', 'B', 16);
        $pdf->SetTextColor(0, 0, 0);
        $pdf->SetY(25);
        $pdf->Cell(0, 10, 'FIRE & RESCUE SERVICES MANAGEMENT', 0, 1, 'C');
        
        $pdf->SetFont('Arial', 'B', 12);
        $pdf->SetTextColor(220, 38, 38);
        $pdf->SetY(32);
        $pdf->Cell(0, 10, 'OFFICE OF TRAINING AND CERTIFICATION', 0, 1, 'C');
        
        // Add certificate title
        $pdf->SetFont('Arial', 'B', 28);
        $pdf->SetTextColor(0, 0, 0);
        $pdf->SetY(45);
        $pdf->Cell(0, 10, 'CERTIFICATE OF COMPLETION', 0, 1, 'C');
        
        // Add decorative lines
        $pdf->SetLineWidth(0.75);
        $pdf->SetDrawColor(220, 38, 38);
        $lineWidth = 120;
        $lineStartX = $centerX - ($lineWidth / 2);
        $pdf->Line($lineStartX, 58, $lineStartX + $lineWidth, 58);
        $pdf->Line($lineStartX + 5, 60, $lineStartX + $lineWidth - 5, 60);
        
        // Add "This is to certify that" text
        $pdf->SetFont('Arial', 'I', 12);
        $pdf->SetTextColor(100, 100, 100);
        $pdf->SetY(68);
        $pdf->Cell(0, 10, 'This is to certify that', 0, 1, 'C');
        
        // Add volunteer name
        $pdf->SetFont('Arial', 'B', 25);
        $pdf->SetTextColor(220, 38, 38);
        $pdf->SetY(78);
        
        $name = strtoupper($data['volunteer_name']);
        if (strlen($name) > 40) {
            $name = substr($name, 0, 37) . '...';
        }
        $pdf->Cell(0, 10, $name, 0, 1, 'C');
        
        // Add training details
        $pdf->SetFont('Arial', '', 10);
        $pdf->SetTextColor(0, 0, 0);
        $pdf->SetY(92);
        $pdf->Cell(0, 10, 'has successfully completed the training program', 0, 1, 'C');
        
        // Training title
        $training_title = '"' . $data['training_title'] . '"';
        if (strlen($training_title) > 50) {
            $training_title = '"' . substr($data['training_title'], 0, 47) . '..."';
        }
        
        $pdf->SetFont('Arial', 'B', 18);
        $pdf->SetTextColor(220, 38, 38);
        $pdf->SetY(102);
        $pdf->Cell(0, 10, $training_title, 0, 1, 'C');
        
        // Training date
        $pdf->SetFont('Arial', '', 10);
        $pdf->SetTextColor(0, 0, 0);
        $pdf->SetY(112);
        $pdf->Cell(0, 10, 'held on ' . date('F d, Y', strtotime($data['training_date'])), 0, 1, 'C');
        
        // Duration and instructor
        $pdf->SetY(120);
        $pdf->Cell(0, 10, 'Duration: ' . $data['duration_hours'] . ' hours | Instructor: ' . $data['instructor'], 0, 1, 'C');
        
        // Add certificate number and dates
        $pdf->SetFont('Arial', '', 9);
        $pdf->SetTextColor(80, 80, 80);
        
        $pdf->SetY(128);
        $pdf->Cell(0, 10, 'Certificate No: ' . $certificate_number, 0, 1, 'C');
        
        $pdf->SetY(135);
        $pdf->Cell(0, 10, 'Issued: ' . $issue_date . ' | Valid Until: ' . $expiry_date, 0, 1, 'C');
        
        // Add validity period note
        $pdf->SetFont('Arial', 'I', 8);
        $pdf->SetTextColor(150, 150, 150);
        $pdf->SetY(143);
        $pdf->Cell(0, 10, '(Certificate is valid for 1 year from date of issue)', 0, 1, 'C');
        
        // Add signatures section
        $pdf->SetFont('Arial', '', 10);
        $signatureY = 152;
        $leftSignatureX = $centerX - 70;
        
        $pdf->SetXY($leftSignatureX, $signatureY);
        $pdf->Cell(60, 10, '________________________', 0, 0, 'C');
        
        $adminDisplayName = strtoupper($admin_name);
        if (strlen($adminDisplayName) > 22) {
            $adminDisplayName = substr($adminDisplayName, 0, 19) . '...';
        }
        
        $pdf->SetXY($leftSignatureX, $signatureY + 5);
        $pdf->Cell(60, 10, $adminDisplayName, 0, 0, 'C');
        $pdf->SetXY($leftSignatureX, $signatureY + 10);
        $pdf->Cell(60, 10, 'Administrator', 0, 0, 'C');
        
        // Right signature (Instructor)
        if (!empty($data['instructor'])) {
            $rightSignatureX = $centerX + 10;
            
            $pdf->SetXY($rightSignatureX, $signatureY);
            $pdf->Cell(60, 10, '________________________', 0, 0, 'C');
            
            $instructorName = strtoupper($data['instructor']);
            if (strlen($instructorName) > 22) {
                $instructorName = substr($instructorName, 0, 19) . '...';
            }
            
            $pdf->SetXY($rightSignatureX, $signatureY + 5);
            $pdf->Cell(60, 10, $instructorName, 0, 0, 'C');
            $pdf->SetXY($rightSignatureX, $signatureY + 10);
            $pdf->Cell(60, 10, 'Instructor', 0, 0, 'C');
        }
        
        // Footer note
        $pdf->SetFont('Arial', 'I', 7);
        $pdf->SetTextColor(120, 120, 120);
        $pdf->SetY(172);
        $pdf->Cell(0, 10, 'This certificate is issued by Fire & Rescue Services Management System', 0, 1, 'C');
        
        // Save certificate
        $certificates_dir = '../../uploads/certificates/';
        if (!file_exists($certificates_dir)) {
            mkdir($certificates_dir, 0777, true);
        }
        
        $filename = 'certificate_' . $registration_id . '_' . time() . '.pdf';
        $filepath = $certificates_dir . $filename;
        $pdf->Output('F', $filepath);
        
        return 'uploads/certificates/' . $filename;
        
    } catch (Exception $e) {
        error_log("Certificate generation failed: " . $e->getMessage());
        return 'certificates/generation_failed.txt';
    }
}

// Function to get training details
function getTrainingDetails($registration_id) {
    global $pdo;
    
    try {
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
    } catch (Exception $e) {
        return [
            'success' => false,
            'message' => 'Error: ' . $e->getMessage()
        ];
    }
}

// Determine active tab
$active_tab = isset($_GET['tab']) ? $_GET['tab'] : 'training';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Approve Completions & Experienced Volunteers - Fire & Rescue Services</title>
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
        
        .tab-navigation {
            display: flex;
            gap: 2px;
            margin-bottom: 30px;
            background: var(--gray-100);
            padding: 4px;
            border-radius: 12px;
            width: fit-content;
        }
        
        .dark-mode .tab-navigation {
            background: var(--gray-800);
        }
        
        .tab-item {
            padding: 12px 24px;
            border-radius: 10px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 8px;
            color: var(--text-light);
        }
        
        .tab-item i {
            font-size: 18px;
        }
        
        .tab-item.active {
            background: var(--card-bg);
            color: var(--primary-color);
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
        }
        
        .dark-mode .tab-item.active {
            background: var(--gray-700);
            color: white;
        }
        
        .tab-item:hover:not(.active) {
            background: rgba(220, 38, 38, 0.1);
            color: var(--primary-color);
        }
        
        .tab-content {
            display: none;
        }
        
        .tab-content.active {
            display: block;
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
        
        .stat-card[data-status="pending"]::before {
            background: var(--warning);
        }
        
        .stat-card[data-status="approved"]::before {
            background: var(--success);
        }
        
        .stat-card[data-status="rejected"]::before {
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
        
        .stat-card[data-status="pending"] .stat-icon {
            background: rgba(245, 158, 11, 0.1);
            color: var(--warning);
        }
        
        .stat-card[data-status="approved"] .stat-icon {
            background: rgba(16, 185, 129, 0.1);
            color: var(--success);
        }
        
        .stat-card[data-status="rejected"] .stat-icon {
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
        
        .status-pending {
            background: rgba(245, 158, 11, 0.1);
            color: var(--warning);
        }
        
        .status-approved {
            background: rgba(16, 185, 129, 0.1);
            color: var(--success);
        }
        
        .status-rejected {
            background: rgba(220, 38, 38, 0.1);
            color: var(--danger);
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
        
        .experience-badge {
            padding: 4px 8px;
            border-radius: 6px;
            font-size: 12px;
            font-weight: 600;
            background: rgba(139, 92, 246, 0.1);
            color: #8b5cf6;
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }
        
        .experience-badge i {
            font-size: 14px;
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
            
            .tab-navigation {
                flex-direction: column;
                width: 100%;
            }
        }
        
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
        
        .upload-form {
            background: var(--gray-100);
            padding: 20px;
            border-radius: 12px;
            margin-top: 20px;
        }
        
        .dark-mode .upload-form {
            background: var(--gray-800);
        }
        
        .form-group {
            margin-bottom: 16px;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 6px;
            font-weight: 500;
            color: var(--text-color);
        }
        
        .form-control {
            width: 100%;
            padding: 10px 12px;
            border: 1px solid var(--border-color);
            border-radius: 8px;
            background: var(--card-bg);
            color: var(--text-color);
            font-size: 14px;
        }
        
        .form-control:focus {
            outline: none;
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(220, 38, 38, 0.1);
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
        <div class="animation-text" id="animation-text">Loading dashboard...</div>
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
                        <a href="../vm/review_data.php" class="submenu-item">Review Data</a>
                        <a href="../vm/approve-applications.php" class="submenu-item">Assign Volunteers</a>
                        <a href="../vm/view-availability.php" class="submenu-item">View Availability</a>
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
                        <a href="approve_completions.php" class="submenu-item <?php echo $active_tab == 'training' ? 'active' : ''; ?>">Approve Completions</a>
                        <a href="experienced_volunteers.php" class="submenu-item <?php echo $active_tab == 'experienced' ? 'active' : ''; ?>">Experienced Volunteers</a>
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
                        <a href="../ile/approve_reports.php" class="submenu-item">Approve Reports</a>
                        <a href="../ile/review_violations.php" class="submenu-item">Review Violations</a>
                        <a href="../ile/issue_certificates.php" class="submenu-item">Issue Certificates</a>
                        <a href="../ile/track_follow_up.php" class="submenu-item">Track Follow-Up</a>
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
                            <input type="text" placeholder="Search..." class="search-input" id="search-input" value="<?php echo htmlspecialchars($search_term); ?>">
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
                    <?php elseif ($_GET['success'] == 3): ?>
                        <div class="alert alert-success">
                            <i class='bx bx-check-circle'></i>
                            <div>
                                <strong>Success!</strong> Experienced volunteer has been approved and activated.
                                <?php if (isset($_GET['volunteer_id'])): ?>
                                    <br><small>Volunteer ID: <?php echo htmlspecialchars($_GET['volunteer_id']); ?></small>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php elseif ($_GET['success'] == 4): ?>
                        <div class="alert alert-info">
                            <i class='bx bx-info-circle'></i>
                            <div>
                                <strong>Completed!</strong> Experienced volunteer application has been rejected.
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
                        <h1 class="dashboard-title">Approve Completions & Experienced Volunteers</h1>
                        <p class="dashboard-subtitle">Review and approve training completions and experienced volunteer applications</p>
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
                
                <!-- Tab Navigation -->
                <div class="review-data-container">
                    <div class="tab-navigation">
                        <div class="tab-item <?php echo $active_tab == 'training' ? 'active' : ''; ?>" onclick="switchTab('training')">
                            <i class='bx bx-certification'></i>
                            Training Completions
                        </div>
                        <div class="tab-item <?php echo $active_tab == 'experienced' ? 'active' : ''; ?>" onclick="switchTab('experienced')">
                            <i class='bx bx-user-check'></i>
                            Experienced Volunteers (10-20+ Years)
                        </div>
                    </div>
                    
                    <!-- Training Completions Tab -->
                    <div class="tab-content <?php echo $active_tab == 'training' ? 'active' : ''; ?>" id="tab-training">
                        <!-- Stats Cards -->
                        <div class="stats-container">
                            <div class="stat-card <?php echo $status_filter === 'all' ? 'active' : ''; ?>" data-status="all" onclick="filterByStatus('all')">
                                <div class="stat-icon">
                                    <i class='bx bxs-graduation'></i>
                                </div>
                                <div class="stat-value"><?php echo array_sum($status_counts); ?></div>
                                <div class="stat-label">Total Completions</div>
                            </div>
                            <div class="stat-card <?php echo $status_filter === 'completed' ? 'active' : ''; ?>" data-status="completed" onclick="filterByStatus('completed')">
                                <div class="stat-icon">
                                    <i class='bx bx-time-five'></i>
                                </div>
                                <div class="stat-value"><?php echo $status_counts['completed']; ?></div>
                                <div class="stat-label">Completed</div>
                            </div>
                            <div class="stat-card <?php echo $status_filter === 'verified' ? 'active' : ''; ?>" data-status="verified" onclick="filterByStatus('verified')">
                                <div class="stat-icon">
                                    <i class='bx bx-check-shield'></i>
                                </div>
                                <div class="stat-value"><?php echo $status_counts['verified']; ?></div>
                                <div class="stat-label">Awaiting Approval</div>
                            </div>
                            <div class="stat-card <?php echo $status_filter === 'certified' ? 'active' : ''; ?>" data-status="certified" onclick="filterByStatus('certified')">
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
                    
                    <!-- Experienced Volunteers Tab -->
                    <div class="tab-content <?php echo $active_tab == 'experienced' ? 'active' : ''; ?>" id="tab-experienced">
                        <!-- Stats Cards for Experienced Volunteers -->
                        <?php
                        // Get counts for experienced volunteers
                        $exp_counts = [
                            'pending' => 0,
                            'approved' => 0,
                            'rejected' => 0
                        ];
                        
                        foreach ($experienced_requests as $request) {
                            $exp_counts[$request['request_status']]++;
                        }
                        ?>
                        
                        <div class="stats-container">
                            <div class="stat-card" data-status="all">
                                <div class="stat-icon">
                                    <i class='bx bx-user-check'></i>
                                </div>
                                <div class="stat-value"><?php echo array_sum($exp_counts); ?></div>
                                <div class="stat-label">Total Requests</div>
                            </div>
                            <div class="stat-card" data-status="pending">
                                <div class="stat-icon">
                                    <i class='bx bx-time'></i>
                                </div>
                                <div class="stat-value"><?php echo $exp_counts['pending']; ?></div>
                                <div class="stat-label">Pending</div>
                            </div>
                            <div class="stat-card" data-status="approved">
                                <div class="stat-icon">
                                    <i class='bx bx-check-circle'></i>
                                </div>
                                <div class="stat-value"><?php echo $exp_counts['approved']; ?></div>
                                <div class="stat-label">Approved</div>
                            </div>
                            <div class="stat-card" data-status="rejected">
                                <div class="stat-icon">
                                    <i class='bx bx-x-circle'></i>
                                </div>
                                <div class="stat-value"><?php echo $exp_counts['rejected']; ?></div>
                                <div class="stat-label">Rejected</div>
                            </div>
                        </div>
                        
                        <!-- Information Box -->
                        <div style="background: linear-gradient(135deg, #6366f1 0%, #8b5cf6 100%); padding: 20px; border-radius: 16px; margin-bottom: 24px; color: white;">
                            <div style="display: flex; align-items: center; gap: 16px;">
                                <i class='bx bx-info-circle' style="font-size: 32px;"></i>
                                <div>
                                    <h3 style="font-size: 18px; margin-bottom: 8px;">Experienced Volunteer Program</h3>
                                    <p style="opacity: 0.9;">Volunteers with 10-20+ years of relevant experience can be approved without completing training. They must provide valid proof of experience (certificates, employment records, recommendations).</p>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Experienced Volunteers Grid -->
                        <div class="completions-grid">
                            <?php if (count($experienced_requests) > 0): ?>
                                <?php foreach ($experienced_requests as $request): ?>
                                    <div class="completion-card" data-id="<?php echo $request['id']; ?>">
                                        <div class="completion-header">
                                            <div class="completion-avatar">
                                                <?php echo strtoupper(substr($request['full_name'], 0, 1)); ?>
                                            </div>
                                            <div class="completion-info">
                                                <h3 class="completion-name"><?php echo htmlspecialchars($request['full_name']); ?></h3>
                                                <p class="completion-email"><?php echo htmlspecialchars($request['email']); ?></p>
                                            </div>
                                            <div class="completion-status status-<?php echo $request['request_status']; ?>">
                                                <?php echo ucfirst($request['request_status']); ?>
                                            </div>
                                        </div>
                                        
                                        <div class="completion-details">
                                            <div class="detail-item">
                                                <div class="detail-label">Experience</div>
                                                <div class="detail-value">
                                                    <span class="experience-badge">
                                                        <i class='bx bx-calendar-star'></i>
                                                        <?php echo htmlspecialchars($request['experience_years']); ?> years
                                                    </span>
                                                </div>
                                            </div>
                                            <div class="detail-item">
                                                <div class="detail-label">Request Date</div>
                                                <div class="detail-value"><?php echo date('M d, Y', strtotime($request['request_date'])); ?></div>
                                            </div>
                                            <div class="detail-item">
                                                <div class="detail-label">Volunteer Status</div>
                                                <div class="detail-value"><?php echo htmlspecialchars($request['volunteer_status']); ?></div>
                                            </div>
                                            <div class="detail-item">
                                                <div class="detail-label">Application Date</div>
                                                <div class="detail-value"><?php echo date('M d, Y', strtotime($request['application_date'])); ?></div>
                                            </div>
                                        </div>
                                        
                                        <div class="completion-actions">
                                            <button class="action-button view-button" onclick="viewExperiencedVolunteer(<?php echo $request['id']; ?>)">
                                                <i class='bx bx-show'></i>
                                                View Details
                                            </button>
                                            <?php if ($request['request_status'] == 'pending'): ?>
                                                <button class="action-button approve-button" onclick="approveExperiencedVolunteer(<?php echo $request['id']; ?>, <?php echo $request['experience_years']; ?>, '<?php echo addslashes($request['proof_path']); ?>')">
                                                    <i class='bx bx-check'></i>
                                                    Approve
                                                </button>
                                                <button class="action-button reject-button" onclick="rejectExperiencedVolunteer(<?php echo $request['id']; ?>)">
                                                    <i class='bx bx-x'></i>
                                                    Reject
                                                </button>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <div class="no-completions">
                                    <div class="no-completions-icon">
                                        <i class='bx bx-user-check'></i>
                                    </div>
                                    <h3>No Experienced Volunteer Requests</h3>
                                    <p>There are no experienced volunteer applications pending review.</p>
                                    <p style="margin-top: 10px; color: var(--text-light);">Check back later or encourage volunteers to apply through the volunteer registration form.</p>
                                </div>
                            <?php endif; ?>
                        </div>
                        
                        <!-- Upload Form for Admins (Optional) -->
                        <div class="upload-form">
                            <h3 style="margin-bottom: 16px; display: flex; align-items: center; gap: 8px;">
                                <i class='bx bx-upload' style="color: var(--primary-color);"></i>
                                Upload Experience Proof for Existing Volunteer
                            </h3>
                            <p style="margin-bottom: 16px; color: var(--text-light);">Use this form to manually upload experience proof for a volunteer who has 10-20+ years of experience.</p>
                            <form method="POST" enctype="multipart/form-data" action="upload_experience_proof.php">
                                <div class="form-group">
                                    <label for="volunteer_id">Select Volunteer</label>
                                    <select class="form-control" id="volunteer_id" name="volunteer_id" required>
                                        <option value="">-- Select Volunteer --</option>
                                        <?php
                                        try {
                                            $volunteer_query = "SELECT id, first_name, last_name, email FROM volunteers WHERE volunteer_status = 'New Volunteer' OR volunteer_status = 'Inactive' ORDER BY first_name";
                                            $volunteer_stmt = $pdo->prepare($volunteer_query);
                                            $volunteer_stmt->execute();
                                            $volunteers = $volunteer_stmt->fetchAll();
                                            foreach ($volunteers as $volunteer):
                                        ?>
                                        <option value="<?php echo $volunteer['id']; ?>">
                                            <?php echo htmlspecialchars($volunteer['first_name'] . ' ' . $volunteer['last_name'] . ' (' . $volunteer['email'] . ')'); ?>
                                        </option>
                                        <?php 
                                            endforeach;
                                        } catch (Exception $e) {
                                            // No volunteers found or query failed
                                        }
                                        ?>
                                    </select>
                                </div>
                                <div class="form-group">
                                    <label for="experience_years">Years of Experience</label>
                                    <input type="number" class="form-control" id="experience_years" name="experience_years" min="10" max="50" required>
                                </div>
                                <div class="form-group">
                                    <label for="proof_file">Proof Document (Certificate, Employment Record, etc.)</label>
                                    <input type="file" class="form-control" id="proof_file" name="proof_file" accept=".pdf,.jpg,.jpeg,.png" required>
                                    <small style="color: var(--text-light);">Accepted formats: PDF, JPG, PNG (Max 5MB)</small>
                                </div>
                                <button type="submit" class="primary-button" style="width: 100%;">
                                    <i class='bx bx-upload'></i>
                                    Upload and Submit for Approval
                                </button>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Completion Details Modal -->
    <div class="modal-overlay" id="completion-modal">
        <div class="modal">
            <div class="modal-header">
                <h2 class="modal-title" id="modal-title">Training Completion Details</h2>
                <button class="modal-close" id="modal-close">&times;</button>
            </div>
            <div class="modal-body" id="modal-body">
                <!-- Content will be loaded via JavaScript -->
            </div>
            <div class="modal-footer">
                <button class="modal-button modal-secondary" id="modal-close-btn">Close</button>
                <button class="modal-button modal-reject" id="modal-reject-btn" style="display: none;">Reject</button>
                <button class="modal-button modal-approve" id="modal-approve-btn" style="display: none;">Approve & Generate Certificate</button>
            </div>
        </div>
    </div>
    
    <script>
        // Global variables
        let currentCompletionId = null;
        let currentExperiencedId = null;
        let currentTab = '<?php echo $active_tab; ?>';
        
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
            showNotification('success', 'System Ready', 'Approval system is now active');
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
                if (document.getElementById('notification-dropdown')) {
                    document.getElementById('notification-dropdown').classList.remove('show');
                }
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
            const notificationClear = document.querySelector('.notification-clear');
            if (notificationClear) {
                notificationClear.addEventListener('click', function(e) {
                    e.stopPropagation();
                    const notificationList = document.getElementById('notification-list');
                    if (notificationList) {
                        notificationList.innerHTML = `
                            <div class="notification-empty">
                                <i class='bx bxs-bell-off'></i>
                                <p>No notifications</p>
                            </div>
                        `;
                    }
                    document.getElementById('notification-count').textContent = '0';
                });
            }
            
            // Close dropdowns when clicking outside
            document.addEventListener('click', function() {
                userDropdown.classList.remove('show');
                if (notificationDropdown) {
                    notificationDropdown.classList.remove('show');
                }
            });
            
            // Filter functionality
            const applyFiltersBtn = document.getElementById('apply-filters');
            if (applyFiltersBtn) {
                applyFiltersBtn.addEventListener('click', applyFilters);
            }
            
            const resetFiltersBtn = document.getElementById('reset-filters');
            if (resetFiltersBtn) {
                resetFiltersBtn.addEventListener('click', resetFilters);
            }
            
            const searchFilter = document.getElementById('search-filter');
            if (searchFilter) {
                searchFilter.addEventListener('keypress', function(e) {
                    if (e.key === 'Enter') {
                        applyFilters();
                    }
                });
            }
            
            // Search input in header
            const searchInput = document.getElementById('search-input');
            if (searchInput) {
                searchInput.addEventListener('keypress', function(e) {
                    if (e.key === 'Enter') {
                        const searchFilter = document.getElementById('search-filter');
                        if (searchFilter) {
                            searchFilter.value = this.value;
                        }
                        applyFilters();
                    }
                });
            }
            
            // Modal functionality
            const modalClose = document.getElementById('modal-close');
            if (modalClose) {
                modalClose.addEventListener('click', closeModal);
            }
            
            const modalCloseBtn = document.getElementById('modal-close-btn');
            if (modalCloseBtn) {
                modalCloseBtn.addEventListener('click', closeModal);
            }
            
            const modalApproveBtn = document.getElementById('modal-approve-btn');
            if (modalApproveBtn) {
                modalApproveBtn.addEventListener('click', function() {
                    if (currentTab === 'training' && currentCompletionId) {
                        approveCompletion(currentCompletionId);
                    } else if (currentTab === 'experienced' && currentExperiencedId) {
                        approveExperiencedVolunteer(currentExperiencedId);
                    }
                });
            }
            
            const modalRejectBtn = document.getElementById('modal-reject-btn');
            if (modalRejectBtn) {
                modalRejectBtn.addEventListener('click', function() {
                    if (currentTab === 'training' && currentCompletionId) {
                        rejectCompletion(currentCompletionId);
                    } else if (currentTab === 'experienced' && currentExperiencedId) {
                        rejectExperiencedVolunteer(currentExperiencedId);
                    }
                });
            }
            
            // Export and refresh buttons
            const exportBtn = document.getElementById('export-button');
            if (exportBtn) {
                exportBtn.addEventListener('click', exportReports);
            }
            
            const refreshBtn = document.getElementById('refresh-button');
            if (refreshBtn) {
                refreshBtn.addEventListener('click', refreshData);
            }
            
            // Keyboard shortcuts
            document.addEventListener('keydown', function(e) {
                // Search shortcut - forward slash
                if (e.key === '/' && !e.ctrlKey && !e.altKey && !e.metaKey) {
                    e.preventDefault();
                    const searchInput = document.getElementById('search-input');
                    if (searchInput) {
                        searchInput.focus();
                    }
                }
                
                // Escape key to close modal
                if (e.key === 'Escape') {
                    closeModal();
                    userDropdown.classList.remove('show');
                    if (notificationDropdown) {
                        notificationDropdown.classList.remove('show');
                    }
                }
            });
        }
        
        function switchTab(tab) {
            currentTab = tab;
            window.location.href = 'approve_completions.php?tab=' + tab;
        }
        
        function filterByStatus(status) {
            if (currentTab === 'training') {
                const statusFilter = document.getElementById('status-filter');
                if (statusFilter) {
                    statusFilter.value = status;
                }
                applyFilters();
            }
        }
        
        function applyFilters() {
            const statusFilter = document.getElementById('status-filter');
            const trainingFilter = document.getElementById('training-filter');
            const searchFilter = document.getElementById('search-filter');
            
            const status = statusFilter ? statusFilter.value : 'all';
            const training = trainingFilter ? trainingFilter.value : 'all';
            const search = searchFilter ? searchFilter.value : '';
            
            let url = 'approve_completions.php?tab=training&';
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
            const statusFilter = document.getElementById('status-filter');
            const trainingFilter = document.getElementById('training-filter');
            const searchFilter = document.getElementById('search-filter');
            
            if (statusFilter) statusFilter.value = 'all';
            if (trainingFilter) trainingFilter.value = 'all';
            if (searchFilter) searchFilter.value = '';
            
            applyFilters();
        }
        
        function viewCompletion(id) {
            currentTab = 'training';
            currentCompletionId = id;
            
            // Show loading state
            const modalTitle = document.getElementById('modal-title');
            if (modalTitle) modalTitle.textContent = 'Training Completion Details';
            
            const modalBody = document.getElementById('modal-body');
            if (modalBody) {
                modalBody.innerHTML = `
                    <div style="text-align: center; padding: 40px;">
                        <i class='bx bx-loader-circle bx-spin' style="font-size: 48px; color: var(--primary-color);"></i>
                        <p style="margin-top: 16px; color: var(--text-light);">Loading completion details...</p>
                    </div>
                `;
            }
            
            // Hide action buttons initially
            const rejectBtn = document.getElementById('modal-reject-btn');
            const approveBtn = document.getElementById('modal-approve-btn');
            if (rejectBtn) rejectBtn.style.display = 'none';
            if (approveBtn) approveBtn.style.display = 'none';
            
            // Show modal
            const modal = document.getElementById('completion-modal');
            if (modal) modal.classList.add('active');
            
            // Fetch completion details via AJAX
            fetch(`approve_completions.php?ajax=true&get_training_details=true&id=${id}`)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        populateTrainingModal(data.data);
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
        
        function viewExperiencedVolunteer(id) {
            currentTab = 'experienced';
            currentExperiencedId = id;
            
            // Show loading state
            const modalTitle = document.getElementById('modal-title');
            if (modalTitle) modalTitle.textContent = 'Experienced Volunteer Details';
            
            const modalBody = document.getElementById('modal-body');
            if (modalBody) {
                modalBody.innerHTML = `
                    <div style="text-align: center; padding: 40px;">
                        <i class='bx bx-loader-circle bx-spin' style="font-size: 48px; color: var(--primary-color);"></i>
                        <p style="margin-top: 16px; color: var(--text-light);">Loading volunteer details...</p>
                    </div>
                `;
            }
            
            // Hide action buttons initially
            const rejectBtn = document.getElementById('modal-reject-btn');
            const approveBtn = document.getElementById('modal-approve-btn');
            if (rejectBtn) rejectBtn.style.display = 'none';
            if (approveBtn) approveBtn.style.display = 'none';
            
            // Show modal
            const modal = document.getElementById('completion-modal');
            if (modal) modal.classList.add('active');
            
            // Fetch volunteer details via AJAX
            fetch(`approve_completions.php?ajax=true&get_experienced_volunteer_details=true&id=${id}`)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        populateExperiencedModal(data.data);
                    } else {
                        showNotification('error', 'Error', 'Failed to load volunteer details');
                        closeModal();
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    showNotification('error', 'Error', 'Failed to load volunteer details');
                    closeModal();
                });
        }
        
        function populateTrainingModal(data) {
            const modalBody = document.getElementById('modal-body');
            if (!modalBody) return;
            
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
                            <div class="modal-detail-value">${data.email || 'N/A'}</div>
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
                                ${data.volunteer_status || 'N/A'}
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
                            <div class="modal-detail-value">${data.training_title || 'N/A'}</div>
                        </div>
                        <div class="modal-detail">
                            <div class="modal-detail-label">Training Dates</div>
                            <div class="modal-detail-value">
                                ${data.training_date || 'N/A'} to ${data.training_end_date || 'N/A'}
                            </div>
                        </div>
                        <div class="modal-detail">
                            <div class="modal-detail-label">Duration</div>
                            <div class="modal-detail-value">${data.duration_hours || 'N/A'} hours</div>
                        </div>
                        <div class="modal-detail">
                            <div class="modal-detail-label">Instructor</div>
                            <div class="modal-detail-value">${data.instructor || 'N/A'}</div>
                        </div>
                        <div class="modal-detail">
                            <div class="modal-detail-label">Location</div>
                            <div class="modal-detail-value">${data.location || 'N/A'}</div>
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
                
                let certificateClass = 'certificate-info';
                let certificateIcon = 'bx bx-certification';
                let certificateText = `Certificate valid until ${expiryDate}`;
                
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
            
            // Add completion notes if available
            if (data.completion_notes) {
                // Remove duplicate entries if they exist
                let notes = data.completion_notes;
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
            const rejectBtn = document.getElementById('modal-reject-btn');
            const approveBtn = document.getElementById('modal-approve-btn');
            const closeBtn = document.getElementById('modal-close-btn');
            
            if (!isCertified) {
                if (rejectBtn) rejectBtn.style.display = 'inline-block';
                if (approveBtn) {
                    approveBtn.style.display = 'inline-block';
                    approveBtn.innerHTML = '<i class="bx bx-check"></i> Approve & Generate Certificate (1 Year Validity)';
                }
            } else {
                if (rejectBtn) rejectBtn.style.display = 'none';
                if (approveBtn) approveBtn.style.display = 'none';
                if (closeBtn) closeBtn.textContent = 'Close';
            }
        }
        
        function populateExperiencedModal(data) {
            const modalBody = document.getElementById('modal-body');
            if (!modalBody) return;
            
            // Build the full name properly
            const fullName = `${data.first_name} ${data.middle_name ? data.middle_name + ' ' : ''}${data.last_name}`;
            
            // Format date of birth
            const dob = data.date_of_birth ? new Date(data.date_of_birth).toLocaleDateString('en-US', { year: 'numeric', month: 'long', day: 'numeric' }) : 'N/A';
            
            // Calculate age
            let age = 'N/A';
            if (data.date_of_birth) {
                const birthDate = new Date(data.date_of_birth);
                const today = new Date();
                age = today.getFullYear() - birthDate.getFullYear();
                const m = today.getMonth() - birthDate.getMonth();
                if (m < 0 || (m === 0 && today.getDate() < birthDate.getDate())) {
                    age--;
                }
            }
            
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
                            <div class="modal-detail-value">${data.email || 'N/A'}</div>
                        </div>
                        <div class="modal-detail">
                            <div class="modal-detail-label">Contact Number</div>
                            <div class="modal-detail-value">${data.contact_number || 'N/A'}</div>
                        </div>
                        <div class="modal-detail">
                            <div class="modal-detail-label">Date of Birth</div>
                            <div class="modal-detail-value">${dob} (${age} years old)</div>
                        </div>
                        <div class="modal-detail">
                            <div class="modal-detail-label">Gender</div>
                            <div class="modal-detail-value">${data.gender || 'N/A'}</div>
                        </div>
                        <div class="modal-detail">
                            <div class="modal-detail-label">Civil Status</div>
                            <div class="modal-detail-value">${data.civil_status || 'N/A'}</div>
                        </div>
                        <div class="modal-detail">
                            <div class="modal-detail-label">Address</div>
                            <div class="modal-detail-value">${data.address || 'N/A'}</div>
                        </div>
                        <div class="modal-detail">
                            <div class="modal-detail-label">Education</div>
                            <div class="modal-detail-value">${data.education || 'N/A'}</div>
                        </div>
                        <div class="modal-detail">
                            <div class="modal-detail-label">Occupation</div>
                            <div class="modal-detail-value">${data.occupation || 'N/A'} ${data.company ? 'at ' + data.company : ''}</div>
                        </div>
                    </div>
                </div>
                
                <div class="modal-section">
                    <h3 class="modal-section-title">Experience Information</h3>
                    <div class="modal-grid">
                        <div class="modal-detail">
                            <div class="modal-detail-label">Years of Experience</div>
                            <div class="modal-detail-value">
                                <span class="experience-badge">
                                    <i class='bx bx-calendar-star'></i>
                                    ${data.experience_years} years
                                </span>
                            </div>
                        </div>
                        <div class="modal-detail">
                            <div class="modal-detail-label">Request Date</div>
                            <div class="modal-detail-value">${new Date(data.request_date).toLocaleDateString('en-US', { year: 'numeric', month: 'long', day: 'numeric' })}</div>
                        </div>
                        <div class="modal-detail">
                            <div class="modal-detail-label">Volunteer Status</div>
                            <div class="modal-detail-value">${data.volunteer_status || 'N/A'}</div>
                        </div>
                        <div class="modal-detail">
                            <div class="modal-detail-label">Application Date</div>
                            <div class="modal-detail-value">${new Date(data.application_date).toLocaleDateString('en-US', { year: 'numeric', month: 'long', day: 'numeric' })}</div>
                        </div>
                    </div>
                </div>
            `;
            
            // Add skills if available
            if (data.skills_basic_firefighting || data.skills_first_aid_cpr || data.skills_search_rescue || data.skills_driving || data.skills_communication || data.skills_mechanical || data.skills_logistics) {
                html += `
                    <div class="modal-section">
                        <h3 class="modal-section-title">Skills & Qualifications</h3>
                        <div style="display: flex; flex-wrap: wrap; gap: 8px;">
                `;
                
                if (data.skills_basic_firefighting) html += `<span style="background: rgba(220, 38, 38, 0.1); color: var(--danger); padding: 4px 12px; border-radius: 20px; font-size: 12px;"><i class='bx bx-fire'></i> Basic Firefighting</span>`;
                if (data.skills_first_aid_cpr) html += `<span style="background: rgba(59, 130, 246, 0.1); color: var(--info); padding: 4px 12px; border-radius: 20px; font-size: 12px;"><i class='bx bx-plus-medical'></i> First Aid/CPR</span>`;
                if (data.skills_search_rescue) html += `<span style="background: rgba(139, 92, 246, 0.1); color: #8b5cf6; padding: 4px 12px; border-radius: 20px; font-size: 12px;"><i class='bx bx-search'></i> Search & Rescue</span>`;
                if (data.skills_driving) html += `<span style="background: rgba(16, 185, 129, 0.1); color: var(--success); padding: 4px 12px; border-radius: 20px; font-size: 12px;"><i class='bx bx-car'></i> Driving (License: ${data.driving_license_no || 'N/A'})</span>`;
                if (data.skills_communication) html += `<span style="background: rgba(245, 158, 11, 0.1); color: var(--warning); padding: 4px 12px; border-radius: 20px; font-size: 12px;"><i class='bx bx-radio'></i> Communication</span>`;
                if (data.skills_mechanical) html += `<span style="background: rgba(99, 102, 241, 0.1); color: #6366f1; padding: 4px 12px; border-radius: 20px; font-size: 12px;"><i class='bx bx-wrench'></i> Mechanical</span>`;
                if (data.skills_logistics) html += `<span style="background: rgba(6, 182, 212, 0.1); color: #06b6d4; padding: 4px 12px; border-radius: 20px; font-size: 12px;"><i class='bx bx-package'></i> Logistics</span>`;
                
                html += `
                        </div>
                    </div>
                `;
            }
            
            // Add proof documents
            if (data.proofs_array && data.proofs_array.length > 0) {
                html += `
                    <div class="modal-section">
                        <h3 class="modal-section-title">Proof Documents</h3>
                `;
                
                data.proofs_array.forEach((proof, index) => {
                    const fileExt = proof.path.split('.').pop().toLowerCase();
                    const isImage = ['jpg', 'jpeg', 'png', 'gif'].includes(fileExt);
                    const proofPath = '../../uploads/experience_proofs/' + proof.path;
                    
                    html += `
                        <div style="margin-bottom: 20px;">
                            <p style="margin-bottom: 8px;"><strong>Document ${index + 1}:</strong> ${proof.type.replace('_', ' ').toUpperCase()}</p>
                    `;
                    
                    if (isImage) {
                        html += `<img src="${proofPath}" alt="Proof Document" class="proof-image" onerror="this.style.display='none'; this.nextElementSibling.style.display='block';">
                                 <div class="no-proof" style="display: none;">Proof image not available</div>`;
                    } else {
                        html += `
                            <a href="${proofPath}" target="_blank" style="display: inline-block; padding: 10px 20px; background: rgba(59, 130, 246, 0.1); color: var(--info); border-radius: 8px; text-decoration: none;">
                                <i class='bx bx-file'></i> View PDF Document
                            </a>
                        `;
                    }
                    
                    html += `</div>`;
                });
                
                html += `</div>`;
            } else if (data.proof_path) {
                const proofPath = '../../uploads/experience_proofs/' + data.proof_path;
                const fileExt = data.proof_path.split('.').pop().toLowerCase();
                const isImage = ['jpg', 'jpeg', 'png', 'gif'].includes(fileExt);
                
                html += `
                    <div class="modal-section">
                        <h3 class="modal-section-title">Proof Document</h3>
                `;
                
                if (isImage) {
                    html += `<img src="${proofPath}" alt="Proof Document" class="proof-image" onerror="this.style.display='none'; this.nextElementSibling.style.display='block';">
                             <div class="no-proof" style="display: none;">Proof image not available</div>`;
                } else {
                    html += `
                        <a href="${proofPath}" target="_blank" style="display: inline-block; padding: 10px 20px; background: rgba(59, 130, 246, 0.1); color: var(--info); border-radius: 8px; text-decoration: none;">
                            <i class='bx bx-file'></i> View PDF Document
                        </a>
                    `;
                }
                
                html += `</div>`;
            }
            
            // Add review notes if available
            if (data.review_notes) {
                html += `
                    <div class="modal-section">
                        <h3 class="modal-section-title">Review Notes</h3>
                        <div class="modal-detail">
                            <div class="modal-detail-value" style="background: var(--gray-100); padding: 15px; border-radius: 8px;">
                                ${data.review_notes}
                            </div>
                        </div>
                    </div>
                `;
            }
            
            modalBody.innerHTML = html;
            
            // Show action buttons for pending requests
            const rejectBtn = document.getElementById('modal-reject-btn');
            const approveBtn = document.getElementById('modal-approve-btn');
            
            if (data.request_status === 'pending') {
                if (rejectBtn) rejectBtn.style.display = 'inline-block';
                if (approveBtn) {
                    approveBtn.style.display = 'inline-block';
                    approveBtn.innerHTML = '<i class="bx bx-check"></i> Approve as Experienced Volunteer';
                }
            } else {
                if (rejectBtn) rejectBtn.style.display = 'none';
                if (approveBtn) approveBtn.style.display = 'none';
            }
        }
        
        function closeModal() {
            const modal = document.getElementById('completion-modal');
            if (modal) modal.classList.remove('active');
            currentCompletionId = null;
            currentExperiencedId = null;
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
        
        function approveExperiencedVolunteer(id, experienceYears, proofPath) {
            if (confirm(`Are you sure you want to approve this volunteer as an experienced volunteer with ${experienceYears} years of experience?\n\nThis will:\n1. Activate the volunteer immediately\n2. Mark them as "certified" without requiring training\n3. Update their status to "Active"`)) {
                showLoading();
                
                // Create form and submit
                const form = document.createElement('form');
                form.method = 'POST';
                form.action = 'approve_completions.php';
                
                const actionInput = document.createElement('input');
                actionInput.type = 'hidden';
                actionInput.name = 'action';
                actionInput.value = 'approve_experienced_volunteer';
                
                const idInput = document.createElement('input');
                idInput.type = 'hidden';
                idInput.name = 'volunteer_id';
                idInput.value = id;
                
                const yearsInput = document.createElement('input');
                yearsInput.type = 'hidden';
                yearsInput.name = 'experience_years';
                yearsInput.value = experienceYears;
                
                const proofInput = document.createElement('input');
                proofInput.type = 'hidden';
                proofInput.name = 'proof_path';
                proofInput.value = proofPath;
                
                form.appendChild(actionInput);
                form.appendChild(idInput);
                form.appendChild(yearsInput);
                form.appendChild(proofInput);
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
        
        function rejectExperiencedVolunteer(id) {
            const reason = prompt('Please enter the reason for rejection:');
            if (reason !== null) {
                if (reason.trim() === '') {
                    alert('Please provide a reason for rejection.');
                    return;
                }
                
                if (confirm('Are you sure you want to reject this experienced volunteer application?')) {
                    showLoading();
                    
                    // Create form and submit
                    const form = document.createElement('form');
                    form.method = 'POST';
                    form.action = 'approve_completions.php';
                    
                    const actionInput = document.createElement('input');
                    actionInput.type = 'hidden';
                    actionInput.name = 'action';
                    actionInput.value = 'reject_experienced_volunteer';
                    
                    const idInput = document.createElement('input');
                    idInput.type = 'hidden';
                    idInput.name = 'volunteer_id';
                    idInput.value = id;
                    
                    form.appendChild(actionInput);
                    form.appendChild(idInput);
                    document.body.appendChild(form);
                    form.submit();
                }
            }
        }
        
        function showLoading() {
            const loadingOverlay = document.getElementById('loading-overlay');
            if (loadingOverlay) {
                loadingOverlay.classList.add('active');
            }
        }
        
        function hideLoading() {
            const loadingOverlay = document.getElementById('loading-overlay');
            if (loadingOverlay) {
                loadingOverlay.classList.remove('active');
            }
        }
        
        function exportReports() {
            showNotification('info', 'Export Started', 'Your report is being generated and will download shortly');
            // In a real implementation, you would trigger the export process
        }
        
        function refreshData() {
            showNotification('info', 'Refreshing Data', 'Fetching the latest data');
            location.reload();
        }
        
        function showNotification(type, title, message, playSound = false) {
            const container = document.getElementById('notification-container');
            if (!container) return;
            
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
            const closeBtn = notification.querySelector('.notification-close');
            if (closeBtn) {
                closeBtn.addEventListener('click', function() {
                    notification.classList.remove('show');
                    setTimeout(() => {
                        if (notification.parentNode) {
                            container.removeChild(notification);
                        }
                    }, 300);
                });
            }
            
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
            if (!submenu) return;
            
            const arrow = submenu.previousElementSibling.querySelector('.dropdown-arrow');
            
            submenu.classList.toggle('active');
            if (arrow) {
                arrow.classList.toggle('rotated');
            }
        }
        
        function updateTime() {
            const now = new Date();
            const utc = now.getTime() + (now.getTimezoneOffset() * 60000);
            const gmt8 = new Date(utc + (8 * 3600000));
            
            const hours = gmt8.getHours().toString().padStart(2, '0');
            const minutes = gmt8.getMinutes().toString().padStart(2, '0');
            const seconds = gmt8.getSeconds().toString().padStart(2, '0');
            
            const timeString = `${hours}:${minutes}:${seconds} UTC+8`;
            const timeElement = document.getElementById('current-time');
            if (timeElement) {
                timeElement.textContent = timeString;
            }
        }
        
        updateTime();
        setInterval(updateTime, 1000);
    </script>
</body>
</html>