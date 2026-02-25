<?php
session_start();
require_once '../../config/db_connection.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: ../../login/login.php");
    exit();
}

$user_id = $_SESSION['user_id'];
$query = "SELECT role FROM users WHERE id = ?";
$stmt = $pdo->prepare($query);
$stmt->execute([$user_id]);
$user = $stmt->fetch();

if (!$user || $user['role'] !== 'ADMIN') {
    header("Location: ../admin_dashboard.php");
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $volunteer_id = $_POST['volunteer_id'] ?? '';
    $experience_years = $_POST['experience_years'] ?? '';
    $proof_type = $_POST['proof_type'] ?? 'other';
    $description = $_POST['description'] ?? '';
    
    $errors = [];
    
    // Validate inputs
    if (empty($volunteer_id)) {
        $errors[] = "Please select a volunteer";
    }
    
    if (empty($experience_years) || $experience_years < 10 || $experience_years > 50) {
        $errors[] = "Experience years must be between 10 and 50";
    }
    
    // Check if file was uploaded
    if (!isset($_FILES['proof_file']) || $_FILES['proof_file']['error'] !== UPLOAD_ERR_OK) {
        $errors[] = "Please upload a proof document";
    } else {
        $file = $_FILES['proof_file'];
        $max_size = 5 * 1024 * 1024; // 5MB
        $allowed_types = ['application/pdf', 'image/jpeg', 'image/jpg', 'image/png'];
        $allowed_extensions = ['pdf', 'jpg', 'jpeg', 'png'];
        
        // Check file size
        if ($file['size'] > $max_size) {
            $errors[] = "File size must be less than 5MB";
        }
        
        // Check file type
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime_type = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);
        
        if (!in_array($mime_type, $allowed_types)) {
            $errors[] = "Only PDF and image files (JPG, PNG) are allowed";
        }
        
        // Check file extension
        $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($extension, $allowed_extensions)) {
            $errors[] = "Invalid file extension";
        }
    }
    
    if (empty($errors)) {
        try {
            $pdo->beginTransaction();
            
            // Create upload directory if it doesn't exist
            $upload_dir = '../../uploads/experience_proofs/';
            if (!file_exists($upload_dir)) {
                mkdir($upload_dir, 0777, true);
            }
            
            // Generate unique filename
            $filename = 'exp_proof_' . $volunteer_id . '_' . time() . '_' . uniqid() . '.' . $extension;
            $filepath = $upload_dir . $filename;
            
            // Move uploaded file
            if (move_uploaded_file($file['tmp_name'], $filepath)) {
                // Insert into experienced_volunteer_requests
                $insert_query = "INSERT INTO experienced_volunteer_requests 
                                (volunteer_id, experience_years, proof_path, status, created_at) 
                                VALUES (?, ?, ?, 'pending', NOW())";
                $insert_stmt = $pdo->prepare($insert_query);
                $insert_stmt->execute([$volunteer_id, $experience_years, $filename]);
                
                $request_id = $pdo->lastInsertId();
                
                // Insert into experience_proofs
                $proof_query = "INSERT INTO experience_proofs 
                               (request_id, proof_type, file_path, description, uploaded_at) 
                               VALUES (?, ?, ?, ?, NOW())";
                $proof_stmt = $pdo->prepare($proof_query);
                $proof_stmt->execute([$request_id, $proof_type, $filename, $description]);
                
                $pdo->commit();
                
                header("Location: approve_completions.php?tab=experienced&success=upload&message=Experience proof uploaded successfully");
                exit();
            } else {
                throw new Exception("Failed to upload file");
            }
        } catch (Exception $e) {
            $pdo->rollBack();
            $errors[] = "Database error: " . $e->getMessage();
        }
    }
    
    // If there are errors, redirect back with error messages
    $error_string = implode(", ", $errors);
    header("Location: approve_completions.php?tab=experienced&error=" . urlencode($error_string));
    exit();
} else {
    header("Location: approve_completions.php?tab=experienced");
    exit();
}
?>