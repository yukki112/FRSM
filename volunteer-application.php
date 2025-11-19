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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Initialize photo variables
    $id_front_photo = null;
    $id_back_photo = null;
    
    try {
        if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
            throw new Exception("Security validation failed. Please try again.");
        }

        $client_ip = $_SERVER['REMOTE_ADDR'];
        if (!checkRateLimit($client_ip)) {
            throw new Exception("Too many submission attempts. Please try again later.");
        }

        $email = filter_var(trim($_POST['email'] ?? ''), FILTER_SANITIZE_EMAIL);
        
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new Exception("Please enter a valid email address.");
        }

        $email_domain = strtolower(substr(strrchr($email, "@"), 1));
        $blocked_domains = ['tempmail.com', '10minutemail.com', 'guerrillamail.com', 'mailinator.com'];
        if (in_array($email_domain, $blocked_domains)) {
            throw new Exception("Please use a permanent email address.");
        }

        $email_check_query = "SELECT id FROM volunteers WHERE email = ? LIMIT 1";
        $email_check_stmt = $pdo->prepare($email_check_query);
        $email_check_stmt->execute([$email]);
        
        if ($email_check_stmt->rowCount() > 0) {
            throw new Exception("This email address is already registered. Please use a different email or contact us if this is an error.");
        }

        // Personal Information - Enhanced sanitization
        $full_name = preg_replace('/[^a-zA-Z\s\'-]/', '', trim($_POST['full_name'] ?? ''));
        $date_of_birth = trim($_POST['date_of_birth'] ?? '');
        $gender = in_array($_POST['gender'] ?? '', ['Male', 'Female', 'Other']) ? trim($_POST['gender']) : '';
        $civil_status = in_array($_POST['civil_status'] ?? '', ['Single', 'Married', 'Divorced', 'Widowed']) ? trim($_POST['civil_status']) : '';
        $address = htmlspecialchars(trim($_POST['address'] ?? ''), ENT_QUOTES, 'UTF-8');
        $contact_number = preg_replace('/[^0-9+\-\s]/', '', trim($_POST['contact_number'] ?? ''));
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
        
        // Set default values for missing database fields
        $id_front_verified = 0;
        $id_back_verified = 0;
        
        // Comprehensive validation
        if (empty($full_name) || empty($email) || empty($contact_number)) {
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

        // CORRECT SQL QUERY - Exactly 49 parameters
        $sql = "INSERT INTO volunteers (
            user_id, full_name, date_of_birth, gender, civil_status, address, contact_number, email, social_media,
            valid_id_type, valid_id_number, id_front_photo, id_back_photo, id_front_verified, id_back_verified,
            emergency_contact_name, emergency_contact_relationship, emergency_contact_number, emergency_contact_address, 
            volunteered_before, previous_volunteer_experience, volunteer_motivation, currently_employed, 
            occupation, company, education, specialized_training, physical_fitness, languages_spoken, 
            skills_basic_firefighting, skills_first_aid_cpr, skills_search_rescue, skills_driving, 
            driving_license_no, skills_communication, skills_mechanical, skills_logistics, available_days, 
            available_hours, emergency_response, area_interest_fire_suppression, area_interest_rescue_operations, 
            area_interest_ems, area_interest_disaster_response, area_interest_admin_logistics, 
            declaration_agreed, signature, application_date, status
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
        
        $stmt = $pdo->prepare($sql);
        
        // CORRECT: Exactly 49 parameters (matching the SQL statement) - INCLUDING date_of_birth
        $result = $stmt->execute([
            NULL, // user_id (1)
            $full_name, // (2)
            $date_of_birth, // (3) - THIS WAS MISSING - FIXED!
            $gender, // (4)
            $civil_status, // (5)
            $address, // (6)
            $contact_number, // (7)
            $email, // (8)
            $social_media, // (9)
            $valid_id_type, // (10)
            $valid_id_number, // (11)
            $id_front_photo, // (12)
            $id_back_photo, // (13)
            $id_front_verified, // (14)
            $id_back_verified, // (15)
            $emergency_contact_name, // (16)
            $emergency_contact_relationship, // (17)
            $emergency_contact_number, // (18)
            $emergency_contact_address, // (19)
            $volunteered_before, // (20)
            $previous_volunteer_experience, // (21)
            $volunteer_motivation, // (22)
            $currently_employed, // (23)
            $occupation, // (24)
            $company, // (25)
            $education, // (26)
            $specialized_training, // (27)
            $physical_fitness, // (28)
            $languages_spoken, // (29)
            $skills_basic_firefighting, // (30)
            $skills_first_aid_cpr, // (31)
            $skills_search_rescue, // (32)
            $skills_driving, // (33)
            $driving_license_no, // (34)
            $skills_communication, // (35)
            $skills_mechanical, // (36)
            $skills_logistics, // (37)
            $available_days, // (38)
            $available_hours, // (39)
            $emergency_response, // (40)
            $area_interest_fire_suppression, // (41)
            $area_interest_rescue_operations, // (42)
            $area_interest_ems, // (43)
            $area_interest_disaster_response, // (44)
            $area_interest_admin_logistics, // (45)
            $declaration_agreed, // (46)
            $signature, // (47)
            $application_date, // (48)
            'pending' // (49)
        ]);
        
        if (!$result) {
            throw new Exception("Database insertion failed. Please try again.");
        }
        
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
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Volunteer Application - Barangay Commonwealth Fire & Rescue</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" integrity="sha512-1ycn6IcaQQ40/MKBW2a4L+S3Hh8y8zMnRLFvDteIm2i+rSJqLh7MZ5QlsN56KwswusTRz0ECYp5wo8o+MnWVrA==" crossorigin="anonymous" referrerpolicy="no-referrer">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        /* Modern, Professional Design System */
        :root {
            --primary-red: #dc2626;
            --primary-red-dark: #7f1d1d;
            --primary-red-light: #fee2e2;
            --accent-blue: #2563eb;
            --accent-gold: #f59e0b;
            --text-dark: #0f172a;
            --text-gray: #475569;
            --text-light: #94a3b8;
            --bg-white: #ffffff;
            --bg-light: #f8fafc;
            --border-color: #e2e8f0;
            --success-green: #10b981;
            --shadow-sm: 0 2px 8px rgba(0, 0, 0, 0.06);
            --shadow-md: 0 8px 24px rgba(0, 0, 0, 0.1);
            --shadow-lg: 0 16px 40px rgba(0, 0, 0, 0.12);
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', 'Poppins', sans-serif;
            background: linear-gradient(135deg, #f0f4f8 0%, #f8fafc 50%, #fef2f2 100%);
            color: var(--text-dark);
            line-height: 1.6;
            min-height: 100vh;
            padding: 20px;
        }

        .container {
            max-width: 1000px;
            margin: 0 auto;
        }

        .header {
            text-align: center;
            margin-bottom: 50px;
            padding: 60px 45px;
            background: linear-gradient(135deg, var(--bg-white) 0%, rgba(255, 255, 255, 0.95) 100%);
            border-radius: 20px;
            box-shadow: var(--shadow-lg);
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255, 255, 255, 0.5);
            position: relative;
            overflow: hidden;
        }

        .header::before {
            content: '';
            position: absolute;
            top: -100%;
            right: -100%;
            width: 600px;
            height: 600px;
            background: radial-gradient(circle, rgba(220, 38, 38, 0.06) 0%, transparent 70%);
            border-radius: 50%;
            pointer-events: none;
        }

        .logo {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 20px;
            margin-bottom: 25px;
            position: relative;
            z-index: 1;
        }

        .logo-icon {
            width: 100px;
            height: 100px;
            border-radius: 18px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 50px;
            background: linear-gradient(135deg, var(--primary-red) 0%, #b91c1c 100%);
            box-shadow: 0 20px 40px rgba(220, 38, 38, 0.3), inset 0 1px 0 rgba(255, 255, 255, 0.2);
            position: relative;
            border: 3px solid rgba(255, 255, 255, 0.2);
            flex-shrink: 0;
        }

        .logo-text h1 {
            font-size: 2.2rem;
            font-weight: 800;
            background: linear-gradient(135deg, var(--primary-red), var(--primary-red-dark));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            margin-bottom: 8px;
            letter-spacing: -0.5px;
        }

        .logo-text p {
            font-size: 1rem;
            color: var(--text-light);
            font-weight: 500;
            letter-spacing: 0.3px;
        }

        .header > .subtitle {
            color: var(--text-gray);
            font-size: 1rem;
            margin-top: 25px;
            position: relative;
            z-index: 1;
            font-weight: 500;
        }

        .application-form {
            background: var(--bg-white);
            border-radius: 20px;
            padding: 55px;
            box-shadow: var(--shadow-lg);
            margin-bottom: 50px;
            border: 1px solid var(--border-color);
        }

        .form-section {
            margin-bottom: 55px;
            padding-bottom: 45px;
            border-bottom: 1px solid var(--border-color);
        }

        .form-section:last-of-type {
            border-bottom: none;
        }

        .section-header {
            display: flex;
            align-items: center;
            gap: 20px;
            margin-bottom: 40px;
        }

        .section-icon {
            width: 80px;
            height: 80px;
            border-radius: 16px;
            background: linear-gradient(135deg, var(--primary-red-light), #fecaca);
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--primary-red);
            font-size: 32px;
            box-shadow: var(--shadow-sm);
            flex-shrink: 0;
        }

        .section-title {
            font-size: 1.8rem;
            font-weight: 700;
            color: var(--text-dark);
            letter-spacing: -0.3px;
        }

        .form-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 28px;
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
            font-weight: 600;
            color: var(--text-dark);
            font-size: 0.95rem;
        }

        .required::after {
            content: " *";
            color: var(--primary-red);
            font-weight: 700;
        }

        input, select, textarea {
            width: 100%;
            padding: 13px 16px;
            border: 2px solid var(--border-color);
            border-radius: 10px;
            font-family: 'Inter', sans-serif;
            font-size: 0.95rem;
            transition: all 0.25s ease;
            background: var(--bg-white);
            color: var(--text-dark);
        }

        input:focus, select:focus, textarea:focus {
            outline: none;
            border-color: var(--primary-red);
            box-shadow: 0 0 0 4px rgba(220, 38, 38, 0.1);
            background: var(--bg-white);
        }

        /* Fixed ID Photo section alignment and styling */
        .id-photo-section {
            background: linear-gradient(135deg, var(--primary-red-light) 0%, #fef2f2 100%);
            padding: 40px;
            border-radius: 16px;
            border: 2px solid var(--primary-red);
            margin-bottom: 35px;
        }

        .id-photo-title {
            font-size: 1.3rem;
            font-weight: 700;
            color: var(--text-dark);
            margin-bottom: 25px;
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .id-photo-title i {
            color: var(--primary-red);
            font-size: 28px;
            min-width: 28px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
        }

        .photo-input-tabs {
            display: flex;
            gap: 12px;
            margin-bottom: 30px;
            flex-wrap: wrap;
        }

        .photo-tab-btn {
            background: white;
            border: 2px solid var(--border-color);
            padding: 12px 24px;
            border-radius: 10px;
            font-weight: 600;
            cursor: pointer;
            color: var(--text-gray);
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
            box-shadow: 0 8px 16px rgba(220, 38, 38, 0.25);
        }

        .photo-tab-btn:hover {
            border-color: var(--primary-red);
        }

        .photo-upload-methods {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 18px;
            margin-bottom: 30px;
        }

        .upload-method {
            padding: 20px;
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
            background: var(--primary-red-light);
        }

        .upload-method.active {
            background: var(--primary-red-light);
            border-color: var(--primary-red);
            box-shadow: 0 6px 16px rgba(220, 38, 38, 0.15);
        }

        .upload-method-icon {
            font-size: 32px;
            color: var(--primary-red);
            margin-bottom: 10px;
        }

        .upload-method-title {
            font-weight: 700;
            color: var(--text-dark);
            margin-bottom: 5px;
            font-size: 0.95rem;
        }

        .upload-method-desc {
            font-size: 0.85rem;
            color: var(--text-gray);
        }

        /* Fixed id-photos-grid layout for proper alignment */
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
            padding: 30px;
            text-align: center;
            cursor: pointer;
            transition: all 0.25s ease;
            position: relative;
            overflow: hidden;
        }

        .photo-upload-box:hover {
            border-color: var(--primary-red);
            background: var(--primary-red-light);
            box-shadow: var(--shadow-sm);
        }

        .photo-upload-box input[type="file"] {
            display: none;
        }

        .upload-icon {
            font-size: 45px;
            color: var(--primary-red);
            margin-bottom: 15px;
        }

        .upload-text {
            font-weight: 700;
            color: var(--text-dark);
            margin-bottom: 6px;
            font-size: 1rem;
        }

        .upload-hint {
            font-size: 0.85rem;
            color: var(--text-gray);
        }

        .camera-container {
            display: none;
            background: white;
            border: 2px solid var(--border-color);
            border-radius: 14px;
            padding: 25px;
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
            margin-bottom: 18px;
            transform: scaleX(-1);
        }

        .camera-controls {
            display: flex;
            gap: 12px;
            margin-bottom: 18px;
            justify-content: center;
            flex-wrap: wrap;
        }

        .camera-btn {
            padding: 12px 24px;
            border: none;
            border-radius: 10px;
            font-weight: 600;
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
            background: var(--primary-red-dark);
            transform: translateY(-2px);
            box-shadow: 0 8px 16px rgba(220, 38, 38, 0.25);
        }

        .camera-btn-secondary {
            background: #e2e8f0;
            color: var(--text-dark);
        }

        .camera-btn-secondary:hover {
            background: #cbd5e1;
        }

        .permission-icon {
            font-size: 28px;
            flex-shrink: 0;
        }

        .camera-permission-request {
            background: #fef3c7;
            border: 2px solid var(--accent-gold);
            border-radius: 12px;
            padding: 16px;
            margin-bottom: 18px;
            color: #92400e;
            display: flex;
            align-items: center;
            gap: 12px;
            font-weight: 500;
        }

        #frontCapturedPhoto, #backCapturedPhoto {
            width: 100%;
            height: auto;
            border-radius: 10px;
            margin-bottom: 18px;
            display: none;
            border: 2px solid var(--success-green);
        }

        .size-indicator {
            background: white;
            border: 2px solid var(--border-color);
            border-radius: 12px;
            padding: 18px;
            margin-top: 18px;
            display: flex;
            align-items: flex-start;
            gap: 18px;
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
            color: var(--text-gray);
            line-height: 1.6;
        }

        .size-text strong {
            color: var(--text-dark);
            font-weight: 600;
        }

        .photo-preview {
            margin-top: 18px;
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
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 6px;
            box-shadow: var(--shadow-sm);
        }

        .checkbox-group {
            display: flex;
            flex-wrap: wrap;
            gap: 14px;
            margin-top: 16px;
        }

        .checkbox-item {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 14px 18px;
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
            box-shadow: 0 4px 12px rgba(220, 38, 38, 0.1);
            background: var(--primary-red-light);
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
            font-weight: 500;
            cursor: pointer;
            user-select: none;
            color: var(--text-gray);
        }

        .checkbox-item input[type="checkbox"]:checked + label {
            color: var(--primary-red);
            font-weight: 600;
        }

        .days-grid, .hours-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
            gap: 14px;
            margin-top: 16px;
        }

        .day-checkbox, .hour-checkbox {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 13px 16px;
            background: white;
            border: 2px solid var(--border-color);
            border-radius: 10px;
            cursor: pointer;
            transition: all 0.25s ease;
        }

        .day-checkbox:hover, .hour-checkbox:hover {
            border-color: var(--primary-red);
            box-shadow: 0 4px 12px rgba(220, 38, 38, 0.1);
            background: var(--primary-red-light);
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
            font-weight: 500;
            cursor: pointer;
            user-select: none;
        }

        .signature-input-wrapper {
            background: white;
            border: 2px solid var(--border-color);
            border-radius: 10px;
            padding: 13px 16px;
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
            padding: 32px;
            border-radius: 16px;
            border-left: 5px solid var(--primary-red);
            margin: 35px 0;
            box-shadow: var(--shadow-sm);
        }

        .declaration-text {
            color: var(--text-gray);
            line-height: 1.8;
            margin-bottom: 25px;
            font-size: 0.95rem;
        }

        .submit-section {
            text-align: center;
            margin-top: 55px;
        }

        .btn-submit {
            background: linear-gradient(135deg, var(--primary-red), #b91c1c);
            color: white;
            padding: 16px 55px;
            border: none;
            border-radius: 50px;
            font-size: 1.1rem;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.25s ease;
            display: inline-flex;
            align-items: center;
            gap: 12px;
            box-shadow: 0 12px 32px rgba(220, 38, 38, 0.3);
            text-transform: uppercase;
            letter-spacing: 0.6px;
        }

        .btn-submit:hover {
            background: linear-gradient(135deg, #b91c1c, var(--primary-red-dark));
            transform: translateY(-3px);
            box-shadow: 0 18px 45px rgba(220, 38, 38, 0.4);
        }

        .alert {
            padding: 18px 24px;
            border-radius: 12px;
            margin-bottom: 30px;
            font-weight: 600;
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

        @keyframes bounce {
            0%, 100% { transform: scale(1); }
            50% { transform: scale(1.1); }
        }

        .redirect-content {
            background: white;
            border-radius: 20px;
            padding: 60px 50px;
            text-align: center;
            max-width: 500px;
            box-shadow: 0 30px 80px rgba(0, 0, 0, 0.3);
            animation: slideUp 0.4s ease;
        }

        .success-icon {
            width: 120px;
            height: 120px;
            margin: 0 auto 32px;
            background: linear-gradient(135deg, var(--success-green), #059669);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 60px;
            color: white;
            box-shadow: 0 15px 40px rgba(16, 185, 129, 0.35);
            animation: bounce 0.6s ease;
        }

        .redirect-content h3 {
            color: var(--text-dark);
            margin-bottom: 18px;
            font-size: 2.2rem;
            font-weight: 700;
        }

        .redirect-content p {
            color: var(--text-gray);
            margin-bottom: 25px;
            line-height: 1.8;
        }

        .redirect-timer {
            font-size: 3.5rem;
            font-weight: 700;
            color: var(--primary-red);
            margin: 32px 0;
            font-family: 'Courier New', monospace;
        }

        .redirect-text {
            color: var(--text-light);
            font-size: 0.95rem;
        }

        .back-home {
            text-align: center;
            margin-top: 40px;
            margin-bottom: 20px;
        }

        .back-home a {
            color: var(--primary-red);
            text-decoration: none;
            font-weight: 700;
            display: inline-flex;
            align-items: center;
            gap: 10px;
            transition: all 0.25s ease;
            font-size: 1rem;
        }

        .back-home a:hover {
            gap: 14px;
            transform: translateX(-3px);
        }

        @media (max-width: 768px) {
            .form-grid {
                grid-template-columns: 1fr;
            }

            .id-photos-grid {
                grid-template-columns: 1fr;
                gap: 25px;
            }

            .photo-upload-methods {
                grid-template-columns: 1fr;
            }

            .checkbox-item {
                min-width: unset;
            }

            .application-form {
                padding: 30px 20px;
            }

            .header {
                padding: 40px 25px;
            }

            .logo {
                flex-direction: column;
                gap: 15px;
            }

            .logo-icon {
                width: 80px;
                height: 80px;
                font-size: 40px;
            }

            .logo-text h1 {
                font-size: 1.8rem;
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
        }
    </style>
</head>
<body>
    <div class="container">
        <!-- Header -->
        <div class="header">
            <div class="logo">
                <div class="logo-icon">
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

            <!-- Section 1: Personal Information -->
            <div class="form-section">
                <div class="section-header">
                    <div class="section-icon">
                        <i class="fas fa-user"></i>
                    </div>
                    <h2 class="section-title">Personal Information</h2>
                </div>
                
                <div class="form-grid">
                    <div class="form-group full-width">
                        <label for="full_name" class="required">Full Name</label>
                        <input type="text" id="full_name" name="full_name" required>
                    </div>
                    
                    <div class="form-group">
                        <label for="date_of_birth" class="required">Date of Birth</label>
                        <input type="date" id="date_of_birth" name="date_of_birth" required>
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
                        <textarea id="address" name="address" rows="3" required></textarea>
                    </div>
                    
                    <div class="form-group">
                        <label for="contact_number" class="required">Contact Number</label>
                        <input type="tel" id="contact_number" name="contact_number" required>
                    </div>
                    
                    <div class="form-group">
                        <label for="email" class="required">Email Address</label>
                        <input type="email" id="email" name="email" required>
                    </div>
                    
                    <div class="form-group">
                        <label for="social_media">Facebook / Social Media</label>
                        <input type="text" id="social_media" name="social_media">
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
                        <input type="text" id="valid_id_number" name="valid_id_number" required>
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
                        <input type="text" id="emergency_contact_name" name="emergency_contact_name" required>
                    </div>
                    
                    <div class="form-group">
                        <label for="emergency_contact_relationship" class="required">Relationship</label>
                        <input type="text" id="emergency_contact_relationship" name="emergency_contact_relationship" required>
                    </div>
                    
                    <div class="form-group">
                        <label for="emergency_contact_number" class="required">Contact Number</label>
                        <input type="tel" id="emergency_contact_number" name="emergency_contact_number" required>
                    </div>
                    
                    <div class="form-group full-width">
                        <label for="emergency_contact_address" class="required">Address</label>
                        <textarea id="emergency_contact_address" name="emergency_contact_address" rows="2" required></textarea>
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
                        <textarea id="previous_volunteer_experience" name="previous_volunteer_experience" rows="3"></textarea>
                    </div>
                    
                    <div class="form-group full-width">
                        <label for="volunteer_motivation" class="required">Why do you want to join the Fire and Rescue Volunteer Program?</label>
                        <textarea id="volunteer_motivation" name="volunteer_motivation" rows="3" required></textarea>
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
                        <input type="text" id="occupation" name="occupation">
                    </div>
                    
                    <div class="form-group" id="company_container" style="display: none;">
                        <label for="company">Company</label>
                        <input type="text" id="company" name="company">
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
                        <textarea id="specialized_training" name="specialized_training" rows="3" placeholder="e.g., BLS, First Aid, Firefighting, Rescue Operations"></textarea>
                    </div>
                    
                    <div class="form-group full-width">
                        <label for="languages_spoken" class="required">Languages Spoken</label>
                        <input type="text" id="languages_spoken" name="languages_spoken" required placeholder="e.g., English, Tagalog, Bisaya">
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
                        <input type="text" id="driving_license_no" name="driving_license_no">
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
                                <input type="text" id="signature" name="signature" class="signature-input" placeholder="Enter your full name as signature" required>
                            </div>
                            <div class="signature-hint">
                                <i class="fas fa-info-circle"></i>
                                Please type your full name exactly as it appears on your ID
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
                <button type="submit" class="btn-submit">
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
            <div class="success-icon">
                <i class="fas fa-check"></i>
            </div>
            <h3>Application Submitted!</h3>
            <p>Thank you for submitting your volunteer application. We will review it and contact you soon.</p>
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

        document.getElementById('volunteerForm').addEventListener('submit', function(e) {
            const declaration = document.getElementById('declaration_agreed');
            if (!declaration.checked) {
                e.preventDefault();
                alert('Please agree to the declaration and consent terms.');
                return false;
            }

            const daysChecked = document.querySelectorAll('input[name="available_days[]"]:checked').length;
            const hoursChecked = document.querySelectorAll('input[name="available_hours[]"]:checked').length;
            const signature = document.getElementById('signature').value;
            
            if (daysChecked === 0) {
                e.preventDefault();
                alert('Please select at least one available day.');
                return false;
            }
            
            if (hoursChecked === 0) {
                e.preventDefault();
                alert('Please select at least one available time period.');
                return false;
            }

            if (!signature) {
                e.preventDefault();
                alert('Please provide your signature by typing your full name.');
                return false;
            }

            const idFrontInput = document.getElementById('id_front_input');
            const idBackInput = document.getElementById('id_back_input');

            if (!idFrontInput.files.length) {
                e.preventDefault();
                alert('Please upload your ID front photo.');
                return false;
            }

            if (!idBackInput.files.length) {
                e.preventDefault();
                alert('Please upload your ID back photo.');
                return false;
            }
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