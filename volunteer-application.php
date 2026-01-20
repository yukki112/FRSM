<?php
session_start();

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('X-XSS-Protection: 1; mode=block');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('Content-Security-Policy: default-src \'self\'; script-src \'self\' \'unsafe-inline\' https://cdnjs.cloudflare.com https://fonts.googleapis.com; style-src \'self\' \'unsafe-inline\' https://cdnjs.cloudflare.com https://fonts.googleapis.com; img-src \'self\' data: https:; connect-src \'self\'');

// Generate CSRF token for session
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Generate form submission token
if (empty($_SESSION['form_token'])) {
    $_SESSION['form_token'] = bin2hex(random_bytes(16));
}

require_once 'config/db_connection.php';

function checkRateLimit($identifier, $max_attempts = 5, $time_window = 3600) {
    $rate_file = sys_get_temp_dir() . '/' . md5($identifier) . '.txt';
    
    if (file_exists($rate_file)) {
        $data = json_decode(file_get_contents($rate_file), true);
        $now = time();
        
        if ($now - $data['first_attempt'] < $time_window) {
            if ($data['attempts'] >= $max_attempts) {
                return false;
            }
            $data['attempts']++;
        } else {
            $data = ['first_attempt' => $now, 'attempts' => 1];
        }
        file_put_contents($rate_file, json_encode($data));
    } else {
        file_put_contents($rate_file, json_encode(['first_attempt' => time(), 'attempts' => 1]));
    }
    
    return true;
}

function validateFormToken($token) {
    return isset($_SESSION['form_token']) && hash_equals($_SESSION['form_token'], $token);
}

function generateNewFormToken() {
    $_SESSION['form_token'] = bin2hex(random_bytes(16));
    return $_SESSION['form_token'];
}

// Check if volunteer registration is open
$status_query = "SELECT status FROM volunteer_registration_status ORDER BY updated_at DESC LIMIT 1";
$status_result = $pdo->query($status_query);
$registration_status = $status_result->fetch();

if (!$registration_status || $registration_status['status'] === 'closed') {
    header("Location: index.php#volunteer");
    exit();
}

// Handle form submission
$success_message = '';
$error_message = '';
$show_redirect = false;
$current_form_token = isset($_SESSION['form_token']) ? $_SESSION['form_token'] : ''; // Initialize current_form_token

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Initialize photo variables
    $id_front_photo = null;
    $id_back_photo = null;
    
    try {
        // Enhanced security checks
        if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
            throw new Exception("Security validation failed. Please refresh the page and try again.");
        }

        if (!isset($_POST['form_token']) || !validateFormToken($_POST['form_token'])) {
            throw new Exception("Form session expired. Please refresh the page and try again.");
        }

        $client_ip = $_SERVER['REMOTE_ADDR'];
        if (!checkRateLimit($client_ip . '_form', 3, 900)) { // 3 attempts per 15 minutes
            throw new Exception("Too many submission attempts. Please try again in 15 minutes.");
        }

        // Check for honeypot field
        if (!empty($_POST['website'])) {
            throw new Exception("Invalid form submission.");
        }

        $email = filter_var(trim($_POST['email'] ?? ''), FILTER_SANITIZE_EMAIL);
        
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new Exception("Please enter a valid email address.");
        }

        // Enhanced email domain validation
        $email_domain = strtolower(substr(strrchr($email, "@"), 1));
        $blocked_domains = [
            'tempmail.com', '10minutemail.com', 'guerrillamail.com', 'mailinator.com',
            'yopmail.com', 'throwawaymail.com', 'fakeinbox.com', 'temp-mail.org',
            'trashmail.com', 'disposablemail.com', 'getairmail.com', 'maildrop.cc'
        ];
        if (in_array($email_domain, $blocked_domains)) {
            throw new Exception("Please use a permanent email address from a trusted provider.");
        }

        // Rate limiting per email
        if (!checkRateLimit($email . '_email', 2, 3600)) {
            throw new Exception("This email has been used too many times. Please use a different email or try again later.");
        }

        $email_check_query = "SELECT id FROM volunteers WHERE email = ? LIMIT 1";
        $email_check_stmt = $pdo->prepare($email_check_query);
        $email_check_stmt->execute([$email]);
        
        if ($email_check_stmt->rowCount() > 0) {
            throw new Exception("This email address is already registered. Please use a different email or contact us if this is an error.");
        }

        // Personal Information - Enhanced sanitization
        // FIRST NAME
        $first_name = preg_replace('/[^a-zA-Z\s\'-]/', '', trim($_POST['first_name'] ?? ''));
        if (strlen($first_name) < 2 || strlen($first_name) > 50) {
            throw new Exception("Please enter a valid first name (2-50 characters).");
        }

        // MIDDLE NAME (optional)
        $middle_name = preg_replace('/[^a-zA-Z\s\'-]/', '', trim($_POST['middle_name'] ?? ''));

        // LAST NAME
        $last_name = preg_replace('/[^a-zA-Z\s\'-]/', '', trim($_POST['last_name'] ?? ''));
        if (strlen($last_name) < 2 || strlen($last_name) > 50) {
            throw new Exception("Please enter a valid last name (2-50 characters).");
        }

        $date_of_birth = trim($_POST['date_of_birth'] ?? '');
        $min_age_date = date('Y-m-d', strtotime('-18 years'));
        if ($date_of_birth > $min_age_date) {
            throw new Exception("You must be at least 18 years old to volunteer.");
        }

        $gender = in_array($_POST['gender'] ?? '', ['Male', 'Female', 'Other']) ? trim($_POST['gender']) : '';
        $civil_status = in_array($_POST['civil_status'] ?? '', ['Single', 'Married', 'Divorced', 'Widowed']) ? trim($_POST['civil_status']) : '';
        
        $address = htmlspecialchars(trim($_POST['address'] ?? ''), ENT_QUOTES, 'UTF-8');
        if (strlen($address) < 10) {
            throw new Exception("Please provide a complete address.");
        }

        $contact_number = preg_replace('/[^0-9+\-\s]/', '', trim($_POST['contact_number'] ?? ''));
        if (strlen($contact_number) < 10) {
            throw new Exception("Please provide a valid contact number.");
        }

        $social_media = htmlspecialchars(trim($_POST['social_media'] ?? ''), ENT_QUOTES, 'UTF-8');
        $valid_id_type = htmlspecialchars(trim($_POST['valid_id_type'] ?? ''), ENT_QUOTES, 'UTF-8');
        $valid_id_number = preg_replace('/[^a-zA-Z0-9\-]/', '', trim($_POST['valid_id_number'] ?? ''));
        
        // Upload directory with secure permissions
        $upload_dir = 'uploads/volunteer_id_photos/';
        if (!is_dir($upload_dir)) {
            mkdir($upload_dir, 0750, true);
            file_put_contents($upload_dir . '.htaccess', 'deny from all');
        }

        function secureFileUpload($file_input, $upload_dir, $prefix) {
            if (!isset($_FILES[$file_input]) || $_FILES[$file_input]['error'] === UPLOAD_ERR_NO_FILE) {
                throw new Exception(ucfirst(str_replace('_', ' ', $file_input)) . " is required.");
            }

            if ($_FILES[$file_input]['error'] !== UPLOAD_ERR_OK) {
                throw new Exception("Upload error for " . str_replace('_', ' ', $file_input) . ". Error code: " . $_FILES[$file_input]['error']);
            }

            // Validate MIME type and image content
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mime = finfo_file($finfo, $_FILES[$file_input]['tmp_name']);
            finfo_close($finfo);
            
            $allowed_mimes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
            if (!in_array($mime, $allowed_mimes)) {
                throw new Exception(ucfirst(str_replace('_', ' ', $file_input)) . " must be a valid image file (JPG, PNG, GIF, or WebP).");
            }

            // Validate actual image
            $file_info = getimagesize($_FILES[$file_input]['tmp_name']);
            if ($file_info === false) {
                throw new Exception(ucfirst(str_replace('_', ' ', $file_input)) . " must be a valid image file.");
            }

            // File size check (5MB max)
            if ($_FILES[$file_input]['size'] > 5242880) {
                throw new Exception(ucfirst(str_replace('_', ' ', $file_input)) . " must be less than 5MB.");
            }

            // Minimum dimensions check (prevent small/invalid images)
            if ($file_info[0] < 200 || $file_info[1] < 200) {
                throw new Exception(ucfirst(str_replace('_', ' ', $file_input)) . " dimensions are too small. Please upload a larger image.");
            }

            $file_ext = strtolower(pathinfo($_FILES[$file_input]['name'], PATHINFO_EXTENSION));
            $allowed_ext = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
            
            if (!in_array($file_ext, $allowed_ext)) {
                throw new Exception(ucfirst(str_replace('_', ' ', $file_input)) . " must be JPG, PNG, GIF, or WebP format.");
            }

            // Generate secure filename
            $filename = $prefix . '_' . time() . '_' . bin2hex(random_bytes(8)) . '.' . $file_ext;
            $filepath = $upload_dir . $filename;
            
            // Move uploaded file
            if (!move_uploaded_file($_FILES[$file_input]['tmp_name'], $filepath)) {
                throw new Exception("Failed to upload " . str_replace('_', ' ', $file_input) . ". Please try again.");
            }

            // Set secure permissions
            chmod($filepath, 0640);
            
            return 'uploads/volunteer_id_photos/' . $filename;
        }

        // Process ID photos with enhanced security
        $id_front_photo = secureFileUpload('id_front_photo', $upload_dir, 'id_front');
        $id_back_photo = secureFileUpload('id_back_photo', $upload_dir, 'id_back');
        
        // Emergency Contact - Enhanced sanitization
        $emergency_contact_name = preg_replace('/[^a-zA-Z\s\'-]/', '', trim($_POST['emergency_contact_name'] ?? ''));
        $emergency_contact_relationship = htmlspecialchars(trim($_POST['emergency_contact_relationship'] ?? ''), ENT_QUOTES, 'UTF-8');
        $emergency_contact_number = preg_replace('/[^0-9+\-\s]/', '', trim($_POST['emergency_contact_number'] ?? ''));
        $emergency_contact_address = htmlspecialchars(trim($_POST['emergency_contact_address'] ?? ''), ENT_QUOTES, 'UTF-8');
        
        // Volunteer Background - Enhanced sanitization
        $volunteered_before = in_array($_POST['volunteered_before'] ?? '', ['Yes', 'No']) ? trim($_POST['volunteered_before']) : '';
        $previous_volunteer_experience = htmlspecialchars(trim($_POST['previous_volunteer_experience'] ?? ''), ENT_QUOTES, 'UTF-8');
        $volunteer_motivation = htmlspecialchars(trim($_POST['volunteer_motivation'] ?? ''), ENT_QUOTES, 'UTF-8');
        if (strlen($volunteer_motivation) < 20) {
            throw new Exception("Please provide a more detailed motivation statement (minimum 20 characters).");
        }

        $currently_employed = in_array($_POST['currently_employed'] ?? '', ['Yes', 'No']) ? trim($_POST['currently_employed']) : '';
        $occupation = htmlspecialchars(trim($_POST['occupation'] ?? ''), ENT_QUOTES, 'UTF-8');
        $company = htmlspecialchars(trim($_POST['company'] ?? ''), ENT_QUOTES, 'UTF-8');
        
        // Skills and Qualifications - Enhanced sanitization
        $education = htmlspecialchars(trim($_POST['education'] ?? ''), ENT_QUOTES, 'UTF-8');
        $specialized_training = htmlspecialchars(trim($_POST['specialized_training'] ?? ''), ENT_QUOTES, 'UTF-8');
        $physical_fitness = in_array($_POST['physical_fitness'] ?? '', ['Excellent', 'Good', 'Fair']) ? trim($_POST['physical_fitness']) : '';
        $languages_spoken = htmlspecialchars(trim($_POST['languages_spoken'] ?? ''), ENT_QUOTES, 'UTF-8');
        
        // Skills checkboxes - Secure validation
        $skills_basic_firefighting = isset($_POST['skills_basic_firefighting']) ? 1 : 0;
        $skills_first_aid_cpr = isset($_POST['skills_first_aid_cpr']) ? 1 : 0;
        $skills_search_rescue = isset($_POST['skills_search_rescue']) ? 1 : 0;
        $skills_driving = isset($_POST['skills_driving']) ? 1 : 0;
        $driving_license_no = preg_replace('/[^a-zA-Z0-9\-]/', '', trim($_POST['driving_license_no'] ?? ''));
        $skills_communication = isset($_POST['skills_communication']) ? 1 : 0;
        $skills_mechanical = isset($_POST['skills_mechanical']) ? 1 : 0;
        $skills_logistics = isset($_POST['skills_logistics']) ? 1 : 0;
        
        // Availability - Secure validation
        $allowed_days = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];
        $available_days_array = array_filter($_POST['available_days'] ?? [], function($day) use ($allowed_days) {
            return in_array($day, $allowed_days);
        });
        $available_days = implode(',', $available_days_array);
        
        $allowed_hours = ['Morning', 'Afternoon', 'Night'];
        $available_hours_array = array_filter($_POST['available_hours'] ?? [], function($hour) use ($allowed_hours) {
            return in_array($hour, $allowed_hours);
        });
        $available_hours = implode(',', $available_hours_array);
        
        $emergency_response = in_array($_POST['emergency_response'] ?? '', ['Yes', 'No']) ? trim($_POST['emergency_response']) : '';
        
        // Area of Interest - Secure validation
        $area_interest_fire_suppression = isset($_POST['area_interest_fire_suppression']) ? 1 : 0;
        $area_interest_rescue_operations = isset($_POST['area_interest_rescue_operations']) ? 1 : 0;
        $area_interest_ems = isset($_POST['area_interest_ems']) ? 1 : 0;
        $area_interest_disaster_response = isset($_POST['area_interest_disaster_response']) ? 1 : 0;
        $area_interest_admin_logistics = isset($_POST['area_interest_admin_logistics']) ? 1 : 0;
        
        // Declaration - Secure validation
        $declaration_agreed = isset($_POST['declaration_agreed']) ? 1 : 0;
        $signature = htmlspecialchars(trim($_POST['signature'] ?? ''), ENT_QUOTES, 'UTF-8');
        $application_date = date('Y-m-d');
        
        // Create full name for signature validation (combine first, middle, last)
        $full_name_for_signature = trim($first_name . ' ' . ($middle_name ? $middle_name . ' ' : '') . $last_name);
        
        // Set default values for missing database fields
        $id_front_verified = 0;
        $id_back_verified = 0;
        
        // Comprehensive validation
        if (empty($first_name) || empty($last_name) || empty($email) || empty($contact_number)) {
            throw new Exception("Please fill in all required personal information fields.");
        }

        if (empty($date_of_birth)) {
            throw new Exception("Please enter your date of birth.");
        }

        if (empty($available_days) || empty($available_hours)) {
            throw new Exception("Please select at least one available day and time.");
        }
        
        if (!$declaration_agreed) {
            throw new Exception("You must agree to the declaration and consent terms.");
        }
        
        if (empty($signature)) {
            throw new Exception("Please provide your signature by typing your full name.");
        }

        if ($full_name_for_signature !== $signature) {
            throw new Exception("Signature must match your full name exactly.");
        }

        // UPDATED SQL QUERY with separate name fields (51 parameters now)
        $sql = "INSERT INTO volunteers (
            user_id, first_name, middle_name, last_name, date_of_birth, gender, civil_status, address, contact_number, email, social_media,
            valid_id_type, valid_id_number, id_front_photo, id_back_photo, id_front_verified, id_back_verified,
            emergency_contact_name, emergency_contact_relationship, emergency_contact_number, emergency_contact_address, 
            volunteered_before, previous_volunteer_experience, volunteer_motivation, currently_employed, 
            occupation, company, education, specialized_training, physical_fitness, languages_spoken, 
            skills_basic_firefighting, skills_first_aid_cpr, skills_search_rescue, skills_driving, 
            driving_license_no, skills_communication, skills_mechanical, skills_logistics, available_days, 
            available_hours, emergency_response, area_interest_fire_suppression, area_interest_rescue_operations, 
            area_interest_ems, area_interest_disaster_response, area_interest_admin_logistics, 
            declaration_agreed, signature, application_date, status
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
        
        $stmt = $pdo->prepare($sql);
        
        // UPDATED: Now 51 parameters (added first_name, middle_name, last_name)
        $result = $stmt->execute([
            NULL, // user_id (1)
            $first_name, // (2)
            $middle_name, // (3)
            $last_name, // (4)
            $date_of_birth, // (5)
            $gender, // (6)
            $civil_status, // (7)
            $address, // (8)
            $contact_number, // (9)
            $email, // (10)
            $social_media, // (11)
            $valid_id_type, // (12)
            $valid_id_number, // (13)
            $id_front_photo, // (14)
            $id_back_photo, // (15)
            $id_front_verified, // (16)
            $id_back_verified, // (17)
            $emergency_contact_name, // (18)
            $emergency_contact_relationship, // (19)
            $emergency_contact_number, // (20)
            $emergency_contact_address, // (21)
            $volunteered_before, // (22)
            $previous_volunteer_experience, // (23)
            $volunteer_motivation, // (24)
            $currently_employed, // (25)
            $occupation, // (26)
            $company, // (27)
            $education, // (28)
            $specialized_training, // (29)
            $physical_fitness, // (30)
            $languages_spoken, // (31)
            $skills_basic_firefighting, // (32)
            $skills_first_aid_cpr, // (33)
            $skills_search_rescue, // (34)
            $skills_driving, // (35)
            $driving_license_no, // (36)
            $skills_communication, // (37)
            $skills_mechanical, // (38)
            $skills_logistics, // (39)
            $available_days, // (40)
            $available_hours, // (41)
            $emergency_response, // (42)
            $area_interest_fire_suppression, // (43)
            $area_interest_rescue_operations, // (44)
            $area_interest_ems, // (45)
            $area_interest_disaster_response, // (46)
            $area_interest_admin_logistics, // (47)
            $declaration_agreed, // (48)
            $signature, // (49)
            $application_date, // (50)
            'pending' // (51)
        ]);
        
        if (!$result) {
            throw new Exception("Database insertion failed. Please try again.");
        }
        
        // Generate new form token after successful submission
        generateNewFormToken();
        
        $success_message = "Your volunteer application has been submitted successfully! We will review your application and contact you soon.";
        $show_redirect = true;
        
    } catch (Exception $e) {
        $error_message = "Error: " . $e->getMessage();
        // Clean up uploaded files if there was an error
        if (isset($id_front_photo) && $id_front_photo && file_exists($id_front_photo)) {
            unlink($id_front_photo);
        }
        if (isset($id_back_photo) && $id_back_photo && file_exists($id_back_photo)) {
            unlink($id_back_photo);
        }
    }
}

// Generate new form token for the form
$current_form_token = $_SESSION['form_token'];
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Volunteer Application - Barangay Commonwealth Fire & Rescue</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" integrity="sha512-1ycn6IcaQQ40/MKBW2a4L+S3Hh8y8zMnRLFvDteIm2i+rSJqLh7MZ5QlsN56KwswusTRz0ECYp5wo8o+MnWVrA==" crossorigin="anonymous" referrerpolicy="no-referrer">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        /* Enhanced color scheme matching index.php - modern dark theme with red accents */
        :root {
            --primary-red: #dc2626;
            --primary-dark: #991b1b;
            --primary-black: #1f2937;
            --accent-black: #111827;
            --light-gray: #f8fafc;
            --text-color: #1f2937;
            --text-light: #6b7280;
            --border-color: #e5e7eb;
            --shadow-sm: 0 4px 12px rgba(220, 38, 38, 0.1);
            --shadow-md: 0 8px 24px rgba(220, 38, 38, 0.15);
            --shadow-lg: 0 16px 40px rgba(0, 0, 0, 0.2);
            --gradient-primary: linear-gradient(135deg, var(--primary-red), #b91c1c);
            --gradient-dark: linear-gradient(135deg, var(--primary-black) 0%, var(--accent-black) 100%);
            --success-green: #10b981;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Poppins', 'Inter', sans-serif;
            color: var(--text-color);
            line-height: 1.6;
            min-height: 100vh;
            overflow-x: hidden;
            background: linear-gradient(135deg, #f9fafb 0%, #f3f4f6 50%, #f0f4f8 100%);
        }

        .container {
            max-width: 1000px;
            margin: 0 auto;
            padding: 20px;
        }

        /* Enhanced header with gradient background and improved styling */
        .header {
            text-align: center;
            margin-bottom: 50px;
            padding: 70px 50px;
            background: var(--gradient-dark);
            border-radius: 20px;
            box-shadow: var(--shadow-lg);
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255, 255, 255, 0.1);
            position: relative;
            overflow: hidden;
            color: white;
        }

        .header::before {
            content: '';
            position: absolute;
            top: -100%;
            right: -100%;
            width: 600px;
            height: 600px;
            background: radial-gradient(circle, rgba(220, 38, 38, 0.15), transparent 70%);
            border-radius: 50%;
            pointer-events: none;
            animation: float 20s infinite ease-in-out;
        }

        @keyframes float {
            0%, 100% { transform: translateY(0px); }
            50% { transform: translateY(30px); }
        }

        .logo {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 25px;
            margin-bottom: 30px;
            position: relative;
            z-index: 1;
            flex-wrap: wrap;
        }

        /* Updated logo icon to support image - can now display logo picture */
        .logo-icon {
            width: 110px;
            height: 110px;
            border-radius: 18px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 50px;
            background: var(--gradient-primary);
            box-shadow: 0 20px 50px rgba(220, 38, 38, 0.4);
            position: relative;
            border: 3px solid rgba(255, 255, 255, 0.2);
            flex-shrink: 0;
            overflow: hidden;
        }

        .logo-icon img {
            width: 100%;
            height: 100%;
            object-fit: contain;
            padding: 5px;
        }

        .logo-icon i {
            transition: all 0.3s ease;
        }

        .logo-icon:hover i {
            transform: scale(1.1) rotate(5deg);
        }

        .logo-text h1 {
            font-size: 2.5rem;
            font-weight: 800;
            color: #ffffff;
            margin-bottom: 8px;
            letter-spacing: -0.5px;
        }

        .logo-text p {
            font-size: 1.1rem;
            color: #ef4444;
            font-weight: 600;
            letter-spacing: 0.5px;
        }

        .header > .subtitle {
            color: #d1d5db;
            font-size: 1.05rem;
            margin-top: 30px;
            position: relative;
            z-index: 1;
            font-weight: 400;
        }

        /* Modernized form styling with better spacing and gradients */
        .application-form {
            background: #ffffff;
            border-radius: 20px;
            padding: 60px;
            box-shadow: var(--shadow-lg);
            margin-bottom: 50px;
            border: 1px solid var(--border-color);
            position: relative;
        }

        .form-section {
            margin-bottom: 60px;
            padding-bottom: 50px;
            border-bottom: 1px solid var(--border-color);
        }

        .form-section:last-of-type {
            border-bottom: none;
        }

        .section-header {
            display: flex;
            align-items: center;
            gap: 25px;
            margin-bottom: 45px;
        }

        /* Enhanced section icon with gradient background */
        .section-icon {
            width: 90px;
            height: 90px;
            border-radius: 16px;
            background: linear-gradient(135deg, #fef2f2, #fee2e2);
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--primary-red);
            font-size: 36px;
            box-shadow: var(--shadow-sm);
            flex-shrink: 0;
            transition: all 0.3s ease;
        }

        .section-header:hover .section-icon {
            transform: scale(1.1) rotate(5deg);
            box-shadow: 0 12px 30px rgba(220, 38, 38, 0.2);
        }

        .section-title {
            font-size: 2rem;
            font-weight: 800;
            color: var(--primary-black);
            letter-spacing: -0.3px;
        }

        .form-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 30px;
        }

        .form-group {
            display: flex;
            flex-direction: column;
        }

        .form-group.full-width {
            grid-column: 1 / -1;
        }

        label {
            display: block;
            margin-bottom: 12px;
            font-weight: 700;
            color: var(--text-color);
            font-size: 0.95rem;
        }

        .required::after {
            content: " *";
            color: var(--primary-red);
            font-weight: 800;
        }

        input, select, textarea {
            width: 100%;
            padding: 14px 18px;
            border: 2px solid var(--border-color);
            border-radius: 10px;
            font-family: 'Poppins', 'Inter', sans-serif;
            font-size: 0.95rem;
            transition: all 0.25s ease;
            background: #ffffff;
            color: var(--text-color);
        }

        input:focus, select:focus, textarea:focus {
            outline: none;
            border-color: var(--primary-red);
            box-shadow: 0 0 0 4px rgba(220, 38, 38, 0.1);
            background: #ffffff;
        }

        /* Honeypot field */
        .hp-field {
            display: none !important;
        }

        /* Enhanced reCAPTCHA styling to match new design */
        .recaptcha-section {
            background: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%);
            padding: 35px;
            border-radius: 16px;
            border: 2px solid var(--border-color);
            margin: 40px 0;
            text-align: center;
        }

        .recaptcha-title {
            font-size: 1.25rem;
            font-weight: 800;
            color: var(--text-color);
            margin-bottom: 25px;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 12px;
        }

        .recaptcha-title i {
            color: var(--primary-red);
            font-size: 28px;
        }

        .g-recaptcha {
            display: inline-block;
            margin: 0 auto;
        }

        .recaptcha-note {
            font-size: 0.85rem;
            color: var(--text-light);
            margin-top: 15px;
            line-height: 1.5;
        }

        /* Improved ID photo section with enhanced styling */
        .id-photo-section {
            background: linear-gradient(135deg, #fef2f2 0%, #fee2e2 100%);
            padding: 45px;
            border-radius: 16px;
            border: 2px solid var(--primary-red);
            margin-bottom: 40px;
        }

        .id-photo-title {
            font-size: 1.4rem;
            font-weight: 800;
            color: var(--text-color);
            margin-bottom: 30px;
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .id-photo-title i {
            color: var(--primary-red);
            font-size: 30px;
        }

        .photo-input-tabs {
            display: flex;
            gap: 12px;
            margin-bottom: 35px;
            flex-wrap: wrap;
        }

        .photo-tab-btn {
            background: white;
            border: 2px solid var(--border-color);
            padding: 13px 28px;
            border-radius: 10px;
            font-weight: 700;
            cursor: pointer;
            color: var(--text-light);
            transition: all 0.25s ease;
            flex: 1;
            min-width: 150px;
            max-width: 280px;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
        }

        .photo-tab-btn.active {
            background: var(--primary-red);
            color: white;
            border-color: var(--primary-red);
            box-shadow: 0 8px 20px rgba(220, 38, 38, 0.3);
        }

        .photo-tab-btn:hover {
            border-color: var(--primary-red);
            transform: translateY(-2px);
        }

        .photo-upload-methods {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
            margin-bottom: 30px;
        }

        .upload-method {
            padding: 25px;
            border: 2px solid var(--border-color);
            border-radius: 12px;
            cursor: pointer;
            text-align: center;
            transition: all 0.25s ease;
            background: white;
        }

        .upload-method:hover {
            border-color: var(--primary-red);
            box-shadow: var(--shadow-sm);
            background: #fef2f2;
        }

        .upload-method.active {
            background: #fef2f2;
            border-color: var(--primary-red);
            box-shadow: 0 8px 20px rgba(220, 38, 38, 0.15);
        }

        .upload-method-icon {
            font-size: 36px;
            color: var(--primary-red);
            margin-bottom: 12px;
        }

        .upload-method-title {
            font-weight: 800;
            color: var(--text-color);
            margin-bottom: 6px;
            font-size: 0.95rem;
        }

        .upload-method-desc {
            font-size: 0.85rem;
            color: var(--text-light);
        }

        .id-photos-grid {
            display: grid;
            grid-template-columns: 1fr;
            gap: 30px;
            margin-bottom: 25px;
        }

        .photo-upload-box {
            background: white;
            border: 2px dashed var(--border-color);
            border-radius: 14px;
            padding: 35px;
            text-align: center;
            cursor: pointer;
            transition: all 0.25s ease;
            position: relative;
            overflow: hidden;
        }

        .photo-upload-box:hover {
            border-color: var(--primary-red);
            background: #fef2f2;
            box-shadow: var(--shadow-sm);
        }

        .photo-upload-box input[type="file"] {
            display: none;
        }

        .upload-icon {
            font-size: 48px;
            color: var(--primary-red);
            margin-bottom: 15px;
        }

        .upload-text {
            font-weight: 800;
            color: var(--text-color);
            margin-bottom: 6px;
            font-size: 1rem;
        }

        .upload-hint {
            font-size: 0.85rem;
            color: var(--text-light);
        }

        .camera-container {
            display: none;
            background: white;
            border: 2px solid var(--border-color);
            border-radius: 14px;
            padding: 30px;
            margin-bottom: 25px;
        }

        .camera-container.active {
            display: block;
        }

        #cameraFeed, #frontCameraFeed, #backCameraFeed {
            width: 100%;
            height: auto;
            border-radius: 10px;
            background: #000;
            margin-bottom: 20px;
            transform: scaleX(-1);
        }

        .camera-controls {
            display: flex;
            gap: 12px;
            margin-bottom: 20px;
            justify-content: center;
            flex-wrap: wrap;
        }

        .camera-btn {
            padding: 13px 28px;
            border: none;
            border-radius: 10px;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.25s ease;
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 0.95rem;
        }

        .camera-btn-primary {
            background: var(--primary-red);
            color: white;
        }

        .camera-btn-primary:hover {
            background: #b91c1c;
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(220, 38, 38, 0.3);
        }

        .camera-btn-secondary {
            background: #e2e8f0;
            color: var(--text-color);
        }

        .camera-btn-secondary:hover {
            background: #cbd5e1;
            transform: translateY(-2px);
        }

        .permission-icon {
            font-size: 28px;
            flex-shrink: 0;
        }

        .camera-permission-request {
            background: #fef3c7;
            border: 2px solid var(--primary-red);
            border-radius: 12px;
            padding: 16px;
            margin-bottom: 20px;
            color: #92400e;
            display: flex;
            align-items: center;
            gap: 12px;
            font-weight: 600;
        }

        #frontCapturedPhoto, #backCapturedPhoto {
            width: 100%;
            height: auto;
            border-radius: 10px;
            margin-bottom: 20px;
            display: none;
            border: 2px solid var(--success-green);
        }

        .size-indicator {
            background: white;
            border: 2px solid var(--border-color);
            border-radius: 12px;
            padding: 20px;
            margin-top: 20px;
            display: flex;
            align-items: flex-start;
            gap: 20px;
        }

        .size-box {
            width: 100px;
            height: 140px;
            border: 2px dashed var(--primary-red);
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: rgba(220, 38, 38, 0.05);
            font-size: 0.75rem;
            color: var(--primary-red);
            font-weight: 700;
            text-align: center;
            padding: 8px;
            flex-shrink: 0;
        }

        .size-text {
            font-size: 0.85rem;
            color: var(--text-light);
            line-height: 1.6;
        }

        .size-text strong {
            color: var(--text-color);
            font-weight: 700;
        }

        .photo-preview {
            margin-top: 20px;
            display: none;
            position: relative;
        }

        .photo-preview img {
            width: 100%;
            height: auto;
            border-radius: 12px;
            border: 2px solid var(--success-green);
            max-height: 220px;
            object-fit: contain;
        }

        .preview-status {
            position: absolute;
            top: 12px;
            right: 12px;
            background: var(--success-green);
            color: white;
            padding: 8px 14px;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 6px;
            box-shadow: var(--shadow-sm);
        }

        .checkbox-group {
            display: flex;
            flex-wrap: wrap;
            gap: 15px;
            margin-top: 18px;
        }

        .checkbox-item {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 15px 18px;
            background: white;
            border: 2px solid var(--border-color);
            border-radius: 10px;
            cursor: pointer;
            transition: all 0.25s ease;
            flex: 1;
            min-width: 180px;
        }

        .checkbox-item:hover {
            border-color: var(--primary-red);
            box-shadow: 0 4px 14px rgba(220, 38, 38, 0.1);
            background: #fef2f2;
        }

        .checkbox-item input[type="checkbox"] {
            width: 24px;
            height: 24px;
            cursor: pointer;
            flex-shrink: 0;
            appearance: none;
            -webkit-appearance: none;
            -moz-appearance: none;
            border: 2px solid var(--border-color);
            border-radius: 6px;
            background: white;
            transition: all 0.25s ease;
            position: relative;
            accent-color: var(--primary-red);
        }

        .checkbox-item input[type="checkbox"]:hover {
            border-color: var(--primary-red);
            box-shadow: 0 0 8px rgba(220, 38, 38, 0.2);
        }

        .checkbox-item input[type="checkbox"]:checked {
            background: var(--primary-red);
            border-color: var(--primary-red);
            box-shadow: 0 0 12px rgba(220, 38, 38, 0.3);
        }

        .checkbox-item input[type="checkbox"]:checked::after {
            content: '✓';
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            color: white;
            font-size: 14px;
            font-weight: bold;
        }

        .checkbox-item label {
            margin-bottom: 0;
            font-weight: 600;
            cursor: pointer;
            user-select: none;
            color: var(--text-light);
        }

        .checkbox-item input[type="checkbox"]:checked + label {
            color: var(--primary-red);
            font-weight: 700;
        }

        .days-grid, .hours-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
            gap: 15px;
            margin-top: 18px;
        }

        .day-checkbox, .hour-checkbox {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 14px 18px;
            background: white;
            border: 2px solid var(--border-color);
            border-radius: 10px;
            cursor: pointer;
            transition: all 0.25s ease;
        }

        .day-checkbox:hover, .hour-checkbox:hover {
            border-color: var(--primary-red);
            box-shadow: 0 4px 14px rgba(220, 38, 38, 0.1);
            background: #fef2f2;
        }

        .day-checkbox input[type="checkbox"], .hour-checkbox input[type="checkbox"] {
            width: 20px;
            height: 20px;
            cursor: pointer;
            flex-shrink: 0;
            appearance: none;
            -webkit-appearance: none;
            -moz-appearance: none;
            border: 2px solid var(--border-color);
            border-radius: 6px;
            background: white;
            transition: all 0.25s ease;
        }

        .day-checkbox input[type="checkbox"]:checked, .hour-checkbox input[type="checkbox"]:checked {
            background: var(--primary-red);
            border-color: var(--primary-red);
        }

        .day-checkbox label, .hour-checkbox label {
            margin-bottom: 0;
            font-weight: 600;
            cursor: pointer;
            user-select: none;
        }

        .signature-input-wrapper {
            background: white;
            border: 2px solid var(--border-color);
            border-radius: 10px;
            padding: 14px 18px;
            transition: all 0.25s ease;
        }

        .signature-input-wrapper:focus-within {
            border-color: var(--primary-red);
            box-shadow: 0 0 0 4px rgba(220, 38, 38, 0.1);
        }

        .signature-input {
            border: none;
            outline: none;
            width: 100%;
            font-family: 'Poppins', sans-serif;
            font-size: 1rem;
            background: transparent;
        }

        .signature-hint {
            font-size: 0.85rem;
            color: var(--text-light);
            margin-top: 8px;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .declaration-box {
            background: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%);
            padding: 35px;
            border-radius: 16px;
            border-left: 5px solid var(--primary-red);
            margin: 40px 0;
            box-shadow: var(--shadow-sm);
        }

        .declaration-text {
            color: var(--text-light);
            line-height: 1.8;
            margin-bottom: 25px;
            font-size: 0.95rem;
        }

        .submit-section {
            text-align: center;
            margin-top: 60px;
        }

        /* Enhanced submit button with gradient and improved hover effect */
        .btn-submit {
            background: var(--gradient-primary);
            color: white;
            padding: 18px 60px;
            border: none;
            border-radius: 50px;
            font-size: 1.1rem;
            font-weight: 800;
            cursor: pointer;
            transition: all 0.25s ease;
            display: inline-flex;
            align-items: center;
            gap: 12px;
            box-shadow: 0 12px 35px rgba(220, 38, 38, 0.35);
            text-transform: uppercase;
            letter-spacing: 0.7px;
        }

        .btn-submit:hover {
            background: linear-gradient(135deg, #b91c1c, var(--primary-dark));
            transform: translateY(-4px);
            box-shadow: 0 18px 50px rgba(220, 38, 38, 0.45);
        }

        .btn-submit:disabled {
            background: #9ca3af;
            cursor: not-allowed;
            transform: none;
            box-shadow: none;
        }

        .alert {
            padding: 20px 28px;
            border-radius: 12px;
            margin-bottom: 35px;
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 14px;
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255, 255, 255, 0.3);
            box-shadow: var(--shadow-md);
            animation: slideDown 0.4s ease;
        }

        @keyframes slideDown {
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
            background: linear-gradient(135deg, #d1fae5 0%, #a7f3d0 100%);
            color: #065f46;
            border-color: #6ee7b7;
        }

        .alert-error {
            background: linear-gradient(135deg, #fee2e2 0%, #fecaca 100%);
            color: #7f1d1d;
            border-color: #fca5a5;
        }

        .redirect-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.85);
            z-index: 9999;
            align-items: center;
            justify-content: center;
            backdrop-filter: blur(4px);
            animation: fadeIn 0.3s ease;
        }

        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }

        @keyframes slideUp {
            from {
                transform: translateY(30px);
                opacity: 0;
            }
            to {
                transform: translateY(0);
                opacity: 1;
            }
        }

        .redirect-content {
            background: white;
            padding: 60px 50px;
            border-radius: 20px;
            max-width: 500px;
            text-align: center;
            box-shadow: var(--shadow-lg);
            animation: slideUp 0.5s ease;
        }

        .redirect-icon {
            font-size: 60px;
            color: var(--success-green);
            margin-bottom: 20px;
            animation: bounce 0.6s ease infinite;
        }

        @keyframes bounce {
            0%, 100% { transform: translateY(0); }
            50% { transform: translateY(-15px); }
        }

        .redirect-message {
            font-size: 1.2rem;
            font-weight: 800;
            color: var(--text-color);
            margin-bottom: 15px;
        }

        .redirect-text {
            color: var(--text-light);
            margin-bottom: 30px;
            line-height: 1.6;
        }

        .redirect-timer {
            font-size: 2rem;
            font-weight: 800;
            color: var(--primary-red);
            margin: 20px 0;
        }

        .redirect-link {
            background: var(--gradient-primary);
            color: white;
            padding: 14px 40px;
            border-radius: 50px;
            text-decoration: none;
            font-weight: 700;
            display: inline-block;
            transition: all 0.3s ease;
            box-shadow: 0 8px 20px rgba(220, 38, 38, 0.3);
        }

        .redirect-link:hover {
            transform: translateY(-3px);
            box-shadow: 0 12px 30px rgba(220, 38, 38, 0.4);
        }

        .back-home {
            text-align: center;
            margin: 40px 0;
        }

        .back-home a {
            display: inline-flex;
            align-items: center;
            gap: 10px;
            padding: 14px 28px;
            background: var(--primary-black);
            color: white;
            text-decoration: none;
            border-radius: 10px;
            font-weight: 600;
            transition: all 0.3s ease;
        }

        .back-home a:hover {
            background: var(--accent-black);
            transform: translateY(-2px);
            box-shadow: var(--shadow-sm);
        }

        @media (max-width: 1024px) {
            .application-form {
                padding: 40px 30px;
            }

            .header {
                padding: 50px 30px;
            }

            .logo {
                flex-direction: column;
                gap: 15px;
            }

            .logo-icon {
                width: 90px;
                height: 90px;
                font-size: 40px;
            }

            .logo-text h1 {
                font-size: 2rem;
            }

            .section-header {
                flex-direction: column;
                text-align: center;
                gap: 12px;
            }

            .days-grid, .hours-grid {
                grid-template-columns: repeat(auto-fit, minmax(120px, 1fr));
            }

            .redirect-content {
                margin: 20px;
                padding: 40px 25px;
            }

            .size-indicator {
                flex-direction: column;
                align-items: center;
            }

            .size-box {
                width: 100%;
                height: 100px;
            }

            .photo-tab-btn {
                max-width: none;
            }

            .form-grid {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 768px) {
            .container {
                padding: 15px;
            }
            
            .header {
                padding: 40px 20px;
                margin-bottom: 30px;
            }

            .logo-icon {
                width: 70px;
                height: 70px;
                font-size: 32px;
            }

            .logo-text h1 {
                font-size: 1.5rem;
            }

            .application-form {
                padding: 25px 15px;
                margin-bottom: 30px;
            }

            .section-title {
                font-size: 1.5rem;
            }

            .form-grid {
                grid-template-columns: 1fr;
                gap: 20px;
            }

            .photo-upload-methods {
                grid-template-columns: 1fr;
            }

            .days-grid, .hours-grid {
                grid-template-columns: 1fr;
            }

            .checkbox-item {
                min-width: auto;
            }

            .redirect-content {
                padding: 30px 20px;
            }
            
            .id-photo-section {
                padding: 25px;
            }
            
            .camera-controls {
                flex-direction: column;
            }
            
            .camera-btn {
                width: 100%;
                justify-content: center;
            }
        }

        @media (max-width: 480px) {
            .header {
                padding: 30px 15px;
            }
            
            .application-form {
                padding: 20px 10px;
            }
            
            .section-header {
                gap: 15px;
            }
            
            .section-icon {
                width: 70px;
                height: 70px;
                font-size: 28px;
            }
            
            .section-title {
                font-size: 1.3rem;
            }
            
            .id-photo-section {
                padding: 20px 15px;
            }
            
            .photo-input-tabs {
                flex-direction: column;
            }
            
            .photo-tab-btn {
                max-width: 100%;
            }
            
            .declaration-box {
                padding: 20px;
            }
            
            .btn-submit {
                padding: 15px 40px;
                font-size: 1rem;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <!-- Enhanced header with gradient background and logo image support -->
        <div class="header">
            <div class="logo">
                <div class="logo-icon">
                    <!-- Replace with: <img src="path/to/your/logo.png" alt="Logo"> -->
                    <i class="fas fa-fire-extinguisher"></i>
                </div>
                <div class="logo-text">
                    <h1>Barangay Commonwealth</h1>
                    <p>Fire & Rescue Services - Volunteer Application</p>
                </div>
            </div>
            <p class="subtitle">Join our dedicated team of emergency responders and make a meaningful impact in our community</p>
        </div>

        <!-- Success/Error Messages -->
        <?php if ($success_message): ?>
            <div class="alert alert-success">
                <i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($success_message); ?>
            </div>
        <?php endif; ?>

        <?php if ($error_message): ?>
            <div class="alert alert-error">
                <i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($error_message); ?>
            </div>
        <?php endif; ?>

        <?php if (!$success_message): ?>
        <!-- Application Form -->
        <form method="POST" class="application-form" id="volunteerForm" enctype="multipart/form-data" novalidate>
            <!-- CSRF Token for security -->
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
            
            <!-- Form session token -->
            <input type="hidden" name="form_token" value="<?php echo htmlspecialchars($current_form_token); ?>">
            
            <!-- Honeypot field for spam bots -->
            <input type="text" name="website" class="hp-field" autocomplete="off">

            <!-- Section 1: Personal Information -->
            <div class="form-section">
                <div class="section-header">
                    <div class="section-icon">
                        <i class="fas fa-user"></i>
                    </div>
                    <h2 class="section-title">Personal Information</h2>
                </div>
                
                <div class="form-grid">
                    <!-- UPDATED: Changed from single full_name to three separate fields -->
                    <div class="form-group">
                        <label for="first_name" class="required">First Name</label>
                        <input type="text" id="first_name" name="first_name" required maxlength="50">
                    </div>
                    
                    <div class="form-group">
                        <label for="middle_name">Middle Name</label>
                        <input type="text" id="middle_name" name="middle_name" maxlength="50">
                    </div>
                    
                    <div class="form-group">
                        <label for="last_name" class="required">Last Name</label>
                        <input type="text" id="last_name" name="last_name" required maxlength="50">
                    </div>
                    
                    <div class="form-group">
                        <label for="date_of_birth" class="required">Date of Birth</label>
                        <input type="date" id="date_of_birth" name="date_of_birth" required max="<?php echo date('Y-m-d', strtotime('-18 years')); ?>">
                    </div>
                    
                    <div class="form-group">
                        <label for="gender" class="required">Gender</label>
                        <select id="gender" name="gender" required>
                            <option value="">Select Gender</option>
                            <option value="Male">Male</option>
                            <option value="Female">Female</option>
                            <option value="Other">Other</option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label for="civil_status" class="required">Civil Status</label>
                        <select id="civil_status" name="civil_status" required>
                            <option value="">Select Civil Status</option>
                            <option value="Single">Single</option>
                            <option value="Married">Married</option>
                            <option value="Divorced">Divorced</option>
                            <option value="Widowed">Widowed</option>
                        </select>
                    </div>
                    
                    <div class="form-group full-width">
                        <label for="address" class="required">Complete Address</label>
                        <textarea id="address" name="address" rows="3" required maxlength="500"></textarea>
                    </div>
                    
                    <div class="form-group">
                        <label for="contact_number" class="required">Contact Number</label>
                        <input type="tel" id="contact_number" name="contact_number" required maxlength="20">
                    </div>
                    
                    <div class="form-group">
                        <label for="email" class="required">Email Address</label>
                        <input type="email" id="email" name="email" required maxlength="100">
                    </div>
                    
                    <div class="form-group">
                        <label for="social_media">Facebook / Social Media</label>
                        <input type="text" id="social_media" name="social_media" maxlength="100">
                    </div>
                    
                    <div class="form-group">
                        <label for="valid_id_type" class="required">Valid ID Type</label>
                        <select id="valid_id_type" name="valid_id_type" required>
                            <option value="">Select ID Type</option>
                            <option value="Driver's License">Driver's License</option>
                            <option value="Passport">Passport</option>
                            <option value="SSS ID">SSS ID</option>
                            <option value="GSIS ID">GSIS ID</option>
                            <option value="UMID">UMID</option>
                            <option value="Postal ID">Postal ID</option>
                            <option value="Voter's ID">Voter's ID</option>
                            <option value="PhilHealth ID">PhilHealth ID</option>
                            <option value="Barangay ID">Barangay ID</option>
                            <option value="Other">Other</option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label for="valid_id_number" class="required">ID Number</label>
                        <input type="text" id="valid_id_number" name="valid_id_number" required maxlength="50">
                    </div>
                </div>
            </div>

            <!-- Section 2: ID Photo Upload with Camera -->
            <div class="form-section">
                <div class="section-header">
                    <div class="section-icon">
                        <i class="fas fa-images"></i>
                    </div>
                    <h2 class="section-title">ID Photo Verification</h2>
                </div>

                <div class="id-photo-section">
                    <div class="id-photo-title">
                        <i class="fas fa-camera"></i>
                        <span>Upload Clear Photos of Your Valid ID (Front & Back)</span>
                    </div>

                    <!-- Photo input method selector tabs -->
                    <div class="photo-input-tabs" id="photoTabs">
                        <button type="button" class="photo-tab-btn active" data-tab="front">
                            <i class="fas fa-id-card"></i> ID Front Side
                        </button>
                        <button type="button" class="photo-tab-btn" data-tab="back">
                            <i class="fas fa-id-card"></i> ID Back Side
                        </button>
                    </div>

                    <div class="id-photos-grid">
                        <!-- ID Front Photo -->
                        <div id="frontPhotoContainer">
                            <!-- Upload method selector with camera and file upload options -->
                            <div class="photo-upload-methods" id="frontUploadMethods">
                                <div class="upload-method" onclick="switchFrontMethod(this, 'camera')">
                                    <div class="upload-method-icon">
                                        <i class="fas fa-camera"></i>
                                    </div>
                                    <div class="upload-method-title">Use Camera</div>
                                    <div class="upload-method-desc">Take photo directly</div>
                                </div>
                                <div class="upload-method active" onclick="switchFrontMethod(this, 'file')">
                                    <div class="upload-method-icon">
                                        <i class="fas fa-upload"></i>
                                    </div>
                                    <div class="upload-method-title">Upload File</div>
                                    <div class="upload-method-desc">Choose from device</div>
                                </div>
                            </div>

                            <!-- Camera UI for front -->
                            <div class="camera-container" id="frontCameraContainer">
                                <div class="camera-permission-request">
                                    <div class="permission-icon">
                                        <i class="fas fa-lock"></i>
                                    </div>
                                    <div>Camera permission required. Click "Start Camera" to proceed.</div>
                                </div>
                                <video id="frontCameraFeed" autoplay playsinline></video>
                                <canvas id="frontCameraCanvas" style="display: none;"></canvas>
                                <div class="camera-controls">
                                    <button type="button" class="camera-btn camera-btn-primary" onclick="startFrontCamera()">
                                        <i class="fas fa-video"></i> Start Camera
                                    </button>
                                    <button type="button" class="camera-btn camera-btn-primary" id="frontCaptureBtn" onclick="captureFrontPhoto()" style="display: none;">
                                        <i class="fas fa-camera"></i> Capture Photo
                                    </button>
                                    <button type="button" class="camera-btn camera-btn-secondary" onclick="stopFrontCamera()">
                                        <i class="fas fa-stop"></i> Stop Camera
                                    </button>
                                </div>
                                <img id="frontCapturedPhoto">
                                <div class="camera-controls" id="frontPhotoActions" style="display: none;">
                                    <button type="button" class="camera-btn camera-btn-primary" onclick="useFrontCapturedPhoto()">
                                        <i class="fas fa-check"></i> Use This Photo
                                    </button>
                                    <button type="button" class="camera-btn camera-btn-secondary" onclick="retakeFrontPhoto()">
                                        <i class="fas fa-redo"></i> Retake
                                    </button>
                                </div>
                            </div>

                            <!-- File upload for front -->
                            <div class="photo-upload-box" id="frontFileUpload" onclick="document.getElementById('id_front_input').click()">
                                <div class="upload-icon">
                                    <i class="fas fa-id-card"></i>
                                </div>
                                <div class="upload-text">ID Front Side</div>
                                <div class="upload-hint">Click to upload or drag image</div>
                            </div>
                            <input type="file" id="id_front_input" name="id_front_photo" accept="image/*" style="display: none;">
                            
                            <div class="size-indicator">
                                <div class="size-box">
                                    FITS HERE<br><br>4"×6"
                                </div>
                                <div class="size-text">
                                    <strong>Ensure your ID fits perfectly</strong> in the box on the left. The photo should clearly show your full ID card. <strong>Max 5MB</strong>
                                </div>
                            </div>

                            <div class="photo-preview" id="id_front_preview">
                                <img id="id_front_img" src="/placeholder.svg" alt="ID Front Preview">
                                <div class="preview-status">
                                    <i class="fas fa-check-circle"></i> Uploaded
                                </div>
                            </div>
                        </div>

                        <!-- ID Back Photo -->
                        <div id="backPhotoContainer" style="display: none;">
                            <!-- Upload method selector for back -->
                            <div class="photo-upload-methods" id="backUploadMethods">
                                <div class="upload-method" onclick="switchBackMethod(this, 'camera')">
                                    <div class="upload-method-icon">
                                        <i class="fas fa-camera"></i>
                                    </div>
                                    <div class="upload-method-title">Use Camera</div>
                                    <div class="upload-method-desc">Take photo directly</div>
                                </div>
                                <div class="upload-method active" onclick="switchBackMethod(this, 'file')">
                                    <div class="upload-method-icon">
                                        <i class="fas fa-upload"></i>
                                    </div>
                                    <div class="upload-method-title">Upload File</div>
                                    <div class="upload-method-desc">Choose from device</div>
                                </div>
                            </div>

                            <!-- Camera UI for back -->
                            <div class="camera-container" id="backCameraContainer">
                                <div class="camera-permission-request">
                                    <div class="permission-icon">
                                        <i class="fas fa-lock"></i>
                                    </div>
                                    <div>Camera permission required. Click "Start Camera" to proceed.</div>
                                </div>
                                <video id="backCameraFeed" autoplay playsinline></video>
                                <canvas id="backCameraCanvas" style="display: none;"></canvas>
                                <div class="camera-controls">
                                    <button type="button" class="camera-btn camera-btn-primary" onclick="startBackCamera()">
                                        <i class="fas fa-video"></i> Start Camera
                                    </button>
                                    <button type="button" class="camera-btn camera-btn-primary" id="backCaptureBtn" onclick="captureBackPhoto()" style="display: none;">
                                        <i class="fas fa-camera"></i> Capture Photo
                                    </button>
                                    <button type="button" class="camera-btn camera-btn-secondary" onclick="stopBackCamera()">
                                        <i class="fas fa-stop"></i> Stop Camera
                                    </button>
                                </div>
                                <img id="backCapturedPhoto">
                                <div class="camera-controls" id="backPhotoActions" style="display: none;">
                                    <button type="button" class="camera-btn camera-btn-primary" onclick="useBackCapturedPhoto()">
                                        <i class="fas fa-check"></i> Use This Photo
                                    </button>
                                    <button type="button" class="camera-btn camera-btn-secondary" onclick="retakeBackPhoto()">
                                        <i class="fas fa-redo"></i> Retake
                                    </button>
                                </div>
                            </div>

                            <!-- File upload for back -->
                            <div class="photo-upload-box" id="backFileUpload" onclick="document.getElementById('id_back_input').click()">
                                <div class="upload-icon">
                                    <i class="fas fa-id-card"></i>
                                </div>
                                <div class="upload-text">ID Back Side</div>
                                <div class="upload-hint">Click to upload or drag image</div>
                            </div>
                            <input type="file" id="id_back_input" name="id_back_photo" accept="image/*" style="display: none;">
                            
                            <div class="size-indicator">
                                <div class="size-box">
                                    FITS HERE<br><br>4"×6"
                                </div>
                                <div class="size-text">
                                    <strong>Ensure your ID fits perfectly</strong> in the box on the left. Show the back side of your ID clearly. <strong>Max 5MB</strong>
                                </div>
                            </div>

                            <div class="photo-preview" id="id_back_preview">
                                <img id="id_back_img" src="/placeholder.svg" alt="ID Back Preview">
                                <div class="preview-status">
                                    <i class="fas fa-check-circle"></i> Uploaded
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Section 3: Emergency Contact Information -->
            <div class="form-section">
                <div class="section-header">
                    <div class="section-icon">
                        <i class="fas fa-phone-alt"></i>
                    </div>
                    <h2 class="section-title">Emergency Contact Information</h2>
                </div>
                
                <div class="form-grid">
                    <div class="form-group">
                        <label for="emergency_contact_name" class="required">Full Name</label>
                        <input type="text" id="emergency_contact_name" name="emergency_contact_name" required maxlength="100">
                    </div>
                    
                    <div class="form-group">
                        <label for="emergency_contact_relationship" class="required">Relationship</label>
                        <input type="text" id="emergency_contact_relationship" name="emergency_contact_relationship" required maxlength="50">
                    </div>
                    
                    <div class="form-group">
                        <label for="emergency_contact_number" class="required">Contact Number</label>
                        <input type="tel" id="emergency_contact_number" name="emergency_contact_number" required maxlength="20">
                    </div>
                    
                    <div class="form-group full-width">
                        <label for="emergency_contact_address" class="required">Address</label>
                        <textarea id="emergency_contact_address" name="emergency_contact_address" rows="2" required maxlength="500"></textarea>
                    </div>
                </div>
            </div>

            <!-- Section 4: Volunteer Background -->
            <div class="form-section">
                <div class="section-header">
                    <div class="section-icon">
                        <i class="fas fa-history"></i>
                    </div>
                    <h2 class="section-title">Volunteer Background</h2>
                </div>
                
                <div class="form-grid">
                    <div class="form-group">
                        <label for="volunteered_before" class="required">Have you volunteered before?</label>
                        <select id="volunteered_before" name="volunteered_before" required>
                            <option value="">Select</option>
                            <option value="Yes">Yes</option>
                            <option value="No">No</option>
                        </select>
                    </div>
                    
                    <div class="form-group full-width" id="previous_experience_container" style="display: none;">
                        <label for="previous_volunteer_experience">If yes, where and what was your role?</label>
                        <textarea id="previous_volunteer_experience" name="previous_volunteer_experience" rows="3" maxlength="500"></textarea>
                    </div>
                    
                    <div class="form-group full-width">
                        <label for="volunteer_motivation" class="required">Why do you want to join the Fire and Rescue Volunteer Program?</label>
                        <textarea id="volunteer_motivation" name="volunteer_motivation" rows="3" required minlength="20" maxlength="1000"></textarea>
                    </div>
                    
                    <div class="form-group">
                        <label for="currently_employed" class="required">Are you currently employed?</label>
                        <select id="currently_employed" name="currently_employed" required>
                            <option value="">Select</option>
                            <option value="Yes">Yes</option>
                            <option value="No">No</option>
                        </select>
                    </div>
                    
                    <div class="form-group" id="occupation_container" style="display: none;">
                        <label for="occupation">Occupation</label>
                        <input type="text" id="occupation" name="occupation" maxlength="100">
                    </div>
                    
                    <div class="form-group" id="company_container" style="display: none;">
                        <label for="company">Company</label>
                        <input type="text" id="company" name="company" maxlength="100">
                    </div>
                </div>
            </div>

            <!-- Section 5: Skills and Qualifications -->
            <div class="form-section">
                <div class="section-header">
                    <div class="section-icon">
                        <i class="fas fa-graduation-cap"></i>
                    </div>
                    <h2 class="section-title">Skills and Qualifications</h2>
                </div>
                
                <div class="form-grid">
                    <div class="form-group">
                        <label for="education" class="required">Highest Educational Attainment</label>
                        <select id="education" name="education" required>
                            <option value="">Select</option>
                            <option value="Elementary">Elementary</option>
                            <option value="High School">High School</option>
                            <option value="Vocational">Vocational</option>
                            <option value="College Undergraduate">College Undergraduate</option>
                            <option value="College Graduate">College Graduate</option>
                            <option value="Postgraduate">Postgraduate</option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label for="physical_fitness" class="required">Physical Fitness Level</label>
                        <select id="physical_fitness" name="physical_fitness" required>
                            <option value="">Select</option>
                            <option value="Excellent">Excellent</option>
                            <option value="Good">Good</option>
                            <option value="Fair">Fair</option>
                        </select>
                    </div>
                    
                    <div class="form-group full-width">
                        <label for="specialized_training">Specialized Training / Certifications</label>
                        <textarea id="specialized_training" name="specialized_training" rows="3" placeholder="e.g., BLS, First Aid, Firefighting, Rescue Operations" maxlength="500"></textarea>
                    </div>
                    
                    <div class="form-group full-width">
                        <label for="languages_spoken" class="required">Languages Spoken</label>
                        <input type="text" id="languages_spoken" name="languages_spoken" required placeholder="e.g., English, Tagalog, Bisaya" maxlength="100">
                    </div>
                    
                    <div class="form-group full-width">
                        <label>Skills (check all that apply)</label>
                        <div class="checkbox-group">
                            <div class="checkbox-item">
                                <input type="checkbox" id="skills_basic_firefighting" name="skills_basic_firefighting" value="1">
                                <label for="skills_basic_firefighting">Basic Firefighting</label>
                            </div>
                            <div class="checkbox-item">
                                <input type="checkbox" id="skills_first_aid_cpr" name="skills_first_aid_cpr" value="1">
                                <label for="skills_first_aid_cpr">First Aid / CPR</label>
                            </div>
                            <div class="checkbox-item">
                                <input type="checkbox" id="skills_search_rescue" name="skills_search_rescue" value="1">
                                <label for="skills_search_rescue">Search and Rescue</label>
                            </div>
                            <div class="checkbox-item">
                                <input type="checkbox" id="skills_driving" name="skills_driving" value="1">
                                <label for="skills_driving">Driving</label>
                            </div>
                            <div class="checkbox-item">
                                <input type="checkbox" id="skills_communication" name="skills_communication" value="1">
                                <label for="skills_communication">Communication / Dispatch</label>
                            </div>
                            <div class="checkbox-item">
                                <input type="checkbox" id="skills_mechanical" name="skills_mechanical" value="1">
                                <label for="skills_mechanical">Mechanical / Technical</label>
                            </div>
                            <div class="checkbox-item">
                                <input type="checkbox" id="skills_logistics" name="skills_logistics" value="1">
                                <label for="skills_logistics">Logistics and Supply Handling</label>
                            </div>
                        </div>
                    </div>
                    
                    <div class="form-group full-width" id="driving_license_container" style="display: none;">
                        <label for="driving_license_no">Driving License Number</label>
                        <input type="text" id="driving_license_no" name="driving_license_no" maxlength="50">
                    </div>
                </div>
            </div>

            <!-- Section 6: Availability -->
            <div class="form-section">
                <div class="section-header">
                    <div class="section-icon">
                        <i class="fas fa-calendar-alt"></i>
                    </div>
                    <h2 class="section-title">Availability</h2>
                </div>
                
                <div class="form-grid">
                    <div class="form-group full-width">
                        <label class="required">Days Available</label>
                        <div class="days-grid">
                            <div class="day-checkbox">
                                <input type="checkbox" id="day_monday" name="available_days[]" value="Monday">
                                <label for="day_monday">Monday</label>
                            </div>
                            <div class="day-checkbox">
                                <input type="checkbox" id="day_tuesday" name="available_days[]" value="Tuesday">
                                <label for="day_tuesday">Tuesday</label>
                            </div>
                            <div class="day-checkbox">
                                <input type="checkbox" id="day_wednesday" name="available_days[]" value="Wednesday">
                                <label for="day_wednesday">Wednesday</label>
                            </div>
                            <div class="day-checkbox">
                                <input type="checkbox" id="day_thursday" name="available_days[]" value="Thursday">
                                <label for="day_thursday">Thursday</label>
                            </div>
                            <div class="day-checkbox">
                                <input type="checkbox" id="day_friday" name="available_days[]" value="Friday">
                                <label for="day_friday">Friday</label>
                            </div>
                            <div class="day-checkbox">
                                <input type="checkbox" id="day_saturday" name="available_days[]" value="Saturday">
                                <label for="day_saturday">Saturday</label>
                            </div>
                            <div class="day-checkbox">
                                <input type="checkbox" id="day_sunday" name="available_days[]" value="Sunday">
                                <label for="day_sunday">Sunday</label>
                            </div>
                        </div>
                    </div>
                    
                    <div class="form-group full-width">
                        <label class="required">Hours Available</label>
                        <div class="hours-grid">
                            <div class="hour-checkbox">
                                <input type="checkbox" id="hour_morning" name="available_hours[]" value="Morning">
                                <label for="hour_morning">Morning</label>
                            </div>
                            <div class="hour-checkbox">
                                <input type="checkbox" id="hour_afternoon" name="available_hours[]" value="Afternoon">
                                <label for="hour_afternoon">Afternoon</label>
                            </div>
                            <div class="hour-checkbox">
                                <input type="checkbox" id="hour_night" name="available_hours[]" value="Night">
                                <label for="hour_night">Night</label>
                            </div>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label for="emergency_response" class="required">Willing to respond during emergencies?</label>
                        <select id="emergency_response" name="emergency_response" required>
                            <option value="">Select</option>
                            <option value="Yes">Yes</option>
                            <option value="No">No</option>
                        </select>
                    </div>
                </div>
            </div>

            <!-- Section 7: Area of Interest -->
            <div class="form-section">
                <div class="section-header">
                    <div class="section-icon">
                        <i class="fas fa-heart"></i>
                    </div>
                    <h2 class="section-title">Area of Interest</h2>
                </div>
                
                <div class="form-group full-width">
                    <label>Select your areas of interest (check all that apply)</label>
                    <div class="checkbox-group">
                        <div class="checkbox-item">
                            <input type="checkbox" id="area_interest_fire_suppression" name="area_interest_fire_suppression" value="1">
                            <label for="area_interest_fire_suppression">Fire Suppression</label>
                        </div>
                        <div class="checkbox-item">
                            <input type="checkbox" id="area_interest_rescue_operations" name="area_interest_rescue_operations" value="1">
                            <label for="area_interest_rescue_operations">Rescue Operations</label>
                        </div>
                        <div class="checkbox-item">
                            <input type="checkbox" id="area_interest_ems" name="area_interest_ems" value="1">
                            <label for="area_interest_ems">Emergency Medical Services (EMS)</label>
                        </div>
                        <div class="checkbox-item">
                            <input type="checkbox" id="area_interest_disaster_response" name="area_interest_disaster_response" value="1">
                            <label for="area_interest_disaster_response">Disaster Response / Evacuation</label>
                        </div>
                        <div class="checkbox-item">
                            <input type="checkbox" id="area_interest_admin_logistics" name="area_interest_admin_logistics" value="1">
                            <label for="area_interest_admin_logistics">Admin / Logistics / Communications</label>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Section 8: Declaration and Consent -->
            <div class="form-section">
                <div class="section-header">
                    <div class="section-icon">
                        <i class="fas fa-file-signature"></i>
                    </div>
                    <h2 class="section-title">Declaration and Consent</h2>
                </div>
                
                <div class="declaration-box">
                    <div class="declaration-text">
                        <p><strong>I hereby declare that the information I have provided is true and correct.</strong> I understand that volunteering for Fire and Rescue involves physical and mental risks. I voluntarily assume these risks and agree to follow all safety rules and instructions.</p>
                        <p><strong>I authorize the Fire and Rescue Management</strong> to verify my background and use my information for emergency and operational purposes in compliance with the Data Privacy Act.</p>
                    </div>
                    
                    <div class="form-grid">
                        <div class="form-group full-width">
                            <label for="signature" class="required">Signature of Applicant (Type your full name)</label>
                            <div class="signature-input-wrapper">
                                <input type="text" id="signature" name="signature" class="signature-input" placeholder="Enter your full name as signature" required maxlength="100">
                            </div>
                            <div class="signature-hint">
                                <i class="fas fa-info-circle"></i>
                                Please type your full name exactly as it appears on your ID (First Middle Last)
                            </div>
                        </div>
                        
                        <div class="form-group">
                            <label for="application_date" class="required">Date</label>
                            <input type="date" id="application_date" name="application_date" value="<?php echo date('Y-m-d'); ?>" readonly>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <div class="checkbox-item" style="margin-top: 22px;">
                            <input type="checkbox" id="declaration_agreed" name="declaration_agreed" value="1" required>
                            <label for="declaration_agreed" class="required">I agree to the terms and conditions stated above</label>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Submit Section -->
            <div class="submit-section">
                <button type="submit" class="btn-submit" id="submitBtn">
                    <i class="fas fa-paper-plane"></i>
                    Submit Application
                </button>
            </div>
        </form>
        <?php endif; ?>

        <!-- Back to Home -->
        <div class="back-home">
            <a href="index.php">
                <i class="fas fa-arrow-left"></i>
                Back to Homepage
            </a>
        </div>
    </div>

    <!-- Redirect Overlay -->
    <div class="redirect-overlay" id="redirectOverlay">
        <div class="redirect-content">
            <div class="redirect-icon">
                <i class="fas fa-check"></i>
            </div>
            <h3 class="redirect-message">Application Submitted!</h3>
            <p class="redirect-text">Thank you for submitting your volunteer application. We will review it and contact you soon.</p>
            <div class="redirect-timer" id="redirectTimer">4</div>
            <p class="redirect-text">Redirecting to homepage in a few seconds...</p>
        </div>
    </div>

    <script>
        // Enhanced camera functionality with permission handling
        let frontCameraStream = null;
        let backCameraStream = null;

        async function startFrontCamera() {
            try {
                frontCameraStream = await navigator.mediaDevices.getUserMedia({ 
                    video: { facingMode: 'environment' } 
                });
                const video = document.getElementById('frontCameraFeed');
                video.srcObject = frontCameraStream;
                document.getElementById('frontCaptureBtn').style.display = 'block';
            } catch (err) {
                if (err.name === 'NotAllowedError') {
                    alert('Camera permission was denied. Please enable camera access in your browser settings.');
                } else if (err.name === 'NotFoundError') {
                    alert('No camera device found. Please check your device settings.');
                } else {
                    alert('Camera error: ' + err.message);
                }
            }
        }

        function stopFrontCamera() {
            if (frontCameraStream) {
                frontCameraStream.getTracks().forEach(track => track.stop());
                frontCameraStream = null;
                document.getElementById('frontCaptureBtn').style.display = 'none';
            }
        }

        function captureFrontPhoto() {
            const video = document.getElementById('frontCameraFeed');
            const canvas = document.getElementById('frontCameraCanvas');
            const ctx = canvas.getContext('2d');
            
            canvas.width = video.videoWidth;
            canvas.height = video.videoHeight;
            
            ctx.drawImage(video, 0, 0);
            
            const img = document.getElementById('frontCapturedPhoto');
            img.src = canvas.toDataURL('image/jpeg', 0.95);
            img.style.display = 'block';
            
            document.getElementById('frontPhotoActions').style.display = 'flex';
            document.getElementById('frontCaptureBtn').style.display = 'none';
        }

        function useFrontCapturedPhoto() {
            const canvas = document.getElementById('frontCameraCanvas');
            canvas.toBlob(blob => {
                const file = new File([blob], 'id_front_camera.jpg', { type: 'image/jpeg' });
                const dataTransfer = new DataTransfer();
                dataTransfer.items.add(file);
                document.getElementById('id_front_input').files = dataTransfer.files;
                
                handlePhotoUpload({ target: { files: dataTransfer.files } }, 'id_front_preview', 'id_front_img');
                switchFrontMethod(null, 'file');
                stopFrontCamera();
            }, 'image/jpeg', 0.95);
        }

        function retakeFrontPhoto() {
            document.getElementById('frontCapturedPhoto').style.display = 'none';
            document.getElementById('frontPhotoActions').style.display = 'none';
            document.getElementById('frontCaptureBtn').style.display = 'block';
        }

        async function startBackCamera() {
            try {
                backCameraStream = await navigator.mediaDevices.getUserMedia({ 
                    video: { facingMode: 'environment' } 
                });
                const video = document.getElementById('backCameraFeed');
                video.srcObject = backCameraStream;
                document.getElementById('backCaptureBtn').style.display = 'block';
            } catch (err) {
                if (err.name === 'NotAllowedError') {
                    alert('Camera permission was denied. Please enable camera access in your browser settings.');
                } else if (err.name === 'NotFoundError') {
                    alert('No camera device found. Please check your device settings.');
                } else {
                    alert('Camera error: ' + err.message);
                }
            }
        }

        function stopBackCamera() {
            if (backCameraStream) {
                backCameraStream.getTracks().forEach(track => track.stop());
                backCameraStream = null;
                document.getElementById('backCaptureBtn').style.display = 'none';
            }
        }

        function captureBackPhoto() {
            const video = document.getElementById('backCameraFeed');
            const canvas = document.getElementById('backCameraCanvas');
            const ctx = canvas.getContext('2d');
            
            canvas.width = video.videoWidth;
            canvas.height = video.videoHeight;
            
            ctx.drawImage(video, 0, 0);
            
            const img = document.getElementById('backCapturedPhoto');
            img.src = canvas.toDataURL('image/jpeg', 0.95);
            img.style.display = 'block';
            
            document.getElementById('backPhotoActions').style.display = 'flex';
            document.getElementById('backCaptureBtn').style.display = 'none';
        }

        function useBackCapturedPhoto() {
            const canvas = document.getElementById('backCameraCanvas');
            canvas.toBlob(blob => {
                const file = new File([blob], 'id_back_camera.jpg', { type: 'image/jpeg' });
                const dataTransfer = new DataTransfer();
                dataTransfer.items.add(file);
                document.getElementById('id_back_input').files = dataTransfer.files;
                
                handlePhotoUpload({ target: { files: dataTransfer.files } }, 'id_back_preview', 'id_back_img');
                switchBackMethod(null, 'file');
                stopBackCamera();
            }, 'image/jpeg', 0.95);
        }

        function retakeBackPhoto() {
            document.getElementById('backCapturedPhoto').style.display = 'none';
            document.getElementById('backPhotoActions').style.display = 'none';
            document.getElementById('backCaptureBtn').style.display = 'block';
        }

        // Photo tab navigation
        document.querySelectorAll('[data-tab]').forEach(btn => {
            btn.addEventListener('click', (e) => {
                e.preventDefault();
                const tab = btn.dataset.tab;
                document.querySelectorAll('[data-tab]').forEach(b => b.classList.remove('active'));
                btn.classList.add('active');
                
                if (tab === 'front') {
                    document.getElementById('frontPhotoContainer').style.display = 'block';
                    document.getElementById('backPhotoContainer').style.display = 'none';
                } else {
                    document.getElementById('frontPhotoContainer').style.display = 'none';
                    document.getElementById('backPhotoContainer').style.display = 'block';
                }
            });
        });

        function switchFrontMethod(element, method) {
            if (element) {
                document.querySelectorAll('#frontUploadMethods .upload-method').forEach(el => el.classList.remove('active'));
                element.classList.add('active');
            }
            
            if (method === 'camera') {
                document.getElementById('frontCameraContainer').classList.add('active');
                document.getElementById('frontFileUpload').style.display = 'none';
            } else {
                document.getElementById('frontCameraContainer').classList.remove('active');
                document.getElementById('frontFileUpload').style.display = 'block';
            }
        }

        function switchBackMethod(element, method) {
            if (element) {
                document.querySelectorAll('#backUploadMethods .upload-method').forEach(el => el.classList.remove('active'));
                element.classList.add('active');
            }
            
            if (method === 'camera') {
                document.getElementById('backCameraContainer').classList.add('active');
                document.getElementById('backFileUpload').style.display = 'none';
            } else {
                document.getElementById('backCameraContainer').classList.remove('active');
                document.getElementById('backFileUpload').style.display = 'block';
            }
        }

        document.getElementById('id_front_input').addEventListener('change', function(e) {
            handlePhotoUpload(e, 'id_front_preview', 'id_front_img');
        });

        document.getElementById('id_back_input').addEventListener('change', function(e) {
            handlePhotoUpload(e, 'id_back_preview', 'id_back_img');
        });

        function handlePhotoUpload(event, previewContainerId, imgElementId) {
            const file = event.target.files[0];
            if (!file) return;

            if (file.size > 5242880) {
                alert('File size must be less than 5MB');
                event.target.value = '';
                return;
            }

            if (!['image/jpeg', 'image/png', 'image/gif', 'image/webp'].includes(file.type)) {
                alert('Please upload a valid image file (JPG, PNG, GIF, or WebP)');
                event.target.value = '';
                return;
            }

            const reader = new FileReader();
            reader.onload = function(e) {
                const img = new Image();
                img.onload = function() {
                    if (img.width < 200 || img.height < 200) {
                        alert('Image dimensions are too small. Please upload a larger image.');
                        event.target.value = '';
                        return;
                    }
                    const previewContainer = document.getElementById(previewContainerId);
                    const imgElement = document.getElementById(imgElementId);
                    imgElement.src = e.target.result;
                    previewContainer.style.display = 'block';
                };
                img.src = e.target.result;
            };
            reader.readAsDataURL(file);
        }

        document.getElementById('volunteered_before').addEventListener('change', function() {
            document.getElementById('previous_experience_container').style.display = this.value === 'Yes' ? 'block' : 'none';
        });

        document.getElementById('currently_employed').addEventListener('change', function() {
            const show = this.value === 'Yes';
            document.getElementById('occupation_container').style.display = show ? 'block' : 'none';
            document.getElementById('company_container').style.display = show ? 'block' : 'none';
        });

        document.getElementById('skills_driving').addEventListener('change', function() {
            document.getElementById('driving_license_container').style.display = this.checked ? 'block' : 'none';
        });

        // Age validation
        document.getElementById('date_of_birth').addEventListener('change', function() {
            const dob = new Date(this.value);
            const today = new Date();
            const age = today.getFullYear() - dob.getFullYear();
            const monthDiff = today.getMonth() - dob.getMonth();
            
            if (monthDiff < 0 || (monthDiff === 0 && today.getDate() < dob.getDate())) {
                age--;
            }
            
            if (age < 18) {
                alert('You must be at least 18 years old to volunteer.');
                this.value = '';
            }
        });

        // Signature validation - UPDATED to combine first, middle, last name
        document.getElementById('signature').addEventListener('input', function() {
            const firstName = document.getElementById('first_name').value;
            const middleName = document.getElementById('middle_name').value;
            const lastName = document.getElementById('last_name').value;
            const signature = this.value;
            
            // Create full name by combining all parts
            let fullName = firstName.trim();
            if (middleName && middleName.trim() !== '') {
                fullName += ' ' + middleName.trim();
            }
            fullName += ' ' + lastName.trim();
            fullName = fullName.trim();
            
            if (fullName && signature && fullName !== signature) {
                this.style.borderColor = 'var(--primary-red)';
                this.style.backgroundColor = 'var(--primary-red-light)';
            } else {
                this.style.borderColor = '';
                this.style.backgroundColor = '';
            }
        });

        // Form submission handler - UPDATED
        document.getElementById('volunteerForm').addEventListener('submit', function(e) {
            const submitBtn = document.getElementById('submitBtn');
            submitBtn.disabled = true;
            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Processing...';

            // Basic validation
            const declaration = document.getElementById('declaration_agreed');
            if (!declaration.checked) {
                e.preventDefault();
                alert('Please agree to the declaration and consent terms.');
                submitBtn.disabled = false;
                submitBtn.innerHTML = '<i class="fas fa-paper-plane"></i> Submit Application';
                return false;
            }

            const daysChecked = document.querySelectorAll('input[name="available_days[]"]:checked').length;
            const hoursChecked = document.querySelectorAll('input[name="available_hours[]"]:checked').length;
            const signature = document.getElementById('signature').value;
            const firstName = document.getElementById('first_name').value;
            const lastName = document.getElementById('last_name').value;
            const middleName = document.getElementById('middle_name').value;
            
            // Create full name for validation
            let fullName = firstName.trim();
            if (middleName && middleName.trim() !== '') {
                fullName += ' ' + middleName.trim();
            }
            fullName += ' ' + lastName.trim();
            fullName = fullName.trim();
            
            if (daysChecked === 0) {
                e.preventDefault();
                alert('Please select at least one available day.');
                submitBtn.disabled = false;
                submitBtn.innerHTML = '<i class="fas fa-paper-plane"></i> Submit Application';
                return false;
            }
            
            if (hoursChecked === 0) {
                e.preventDefault();
                alert('Please select at least one available time period.');
                submitBtn.disabled = false;
                submitBtn.innerHTML = '<i class="fas fa-paper-plane"></i> Submit Application';
                return false;
            }

            if (!signature) {
                e.preventDefault();
                alert('Please provide your signature by typing your full name.');
                submitBtn.disabled = false;
                submitBtn.innerHTML = '<i class="fas fa-paper-plane"></i> Submit Application';
                return false;
            }

            if (signature !== fullName) {
                e.preventDefault();
                alert('Signature must match your full name exactly.');
                submitBtn.disabled = false;
                submitBtn.innerHTML = '<i class="fas fa-paper-plane"></i> Submit Application';
                return false;
            }

            const idFrontInput = document.getElementById('id_front_input');
            const idBackInput = document.getElementById('id_back_input');

            if (!idFrontInput.files.length) {
                e.preventDefault();
                alert('Please upload your ID front photo.');
                submitBtn.disabled = false;
                submitBtn.innerHTML = '<i class="fas fa-paper-plane"></i> Submit Application';
                return false;
            }

            if (!idBackInput.files.length) {
                e.preventDefault();
                alert('Please upload your ID back photo.');
                submitBtn.disabled = false;
                submitBtn.innerHTML = '<i class="fas fa-paper-plane"></i> Submit Application';
                return false;
            }

            return true;
        });

        <?php if ($show_redirect): ?>
            window.addEventListener('load', function() {
                const overlay = document.getElementById('redirectOverlay');
                const timer = document.getElementById('redirectTimer');
                overlay.style.display = 'flex';
                
                let count = 4;
                const interval = setInterval(() => {
                    count--;
                    timer.textContent = count;
                    
                    if (count <= 0) {
                        clearInterval(interval);
                        window.location.href = 'index.php';
                    }
                }, 1000);
            });
        <?php endif; ?>
    </script>
</body>
</html>