<?php
session_start();
require_once '../../config/db_connection.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: ../../login.php");
    exit();
}

$user_id = $_SESSION['user_id'];

// Get user information
$query = "SELECT first_name, middle_name, last_name, role, email, avatar FROM users WHERE id = ?";
$stmt = $pdo->prepare($query);
$stmt->execute([$user_id]);
$user = $stmt->fetch();

if (!$user) {
    header("Location: ../../login.php");
    exit();
}

$first_name = htmlspecialchars($user['first_name']);
$middle_name = htmlspecialchars($user['middle_name']);
$last_name = htmlspecialchars($user['last_name']);
$role = htmlspecialchars($user['role']);
$email = htmlspecialchars($user['email']);
$avatar = htmlspecialchars($user['avatar']);

$full_name = $first_name;
if (!empty($middle_name)) {
    $full_name .= " " . $middle_name;
}
$full_name .= " " . $last_name;

// Check if user is a volunteer (USER role)
if ($role !== 'USER') {
    header("Location: ../dashboard.php");
    exit();
}

// Get volunteer ID from volunteers table
$volunteer_query = "SELECT id, first_name, last_name, contact_number, volunteer_status, training_completion_status, first_training_completed_at, active_since FROM volunteers WHERE user_id = ?";
$volunteer_stmt = $pdo->prepare($volunteer_query);
$volunteer_stmt->execute([$user_id]);
$volunteer = $volunteer_stmt->fetch();

if (!$volunteer) {
    // User is not registered as a volunteer
    header("Location: ../dashboard.php");
    exit();
}

$volunteer_id = $volunteer['id'];
$volunteer_name = htmlspecialchars($volunteer['first_name'] . ' ' . $volunteer['last_name']);
$volunteer_contact = htmlspecialchars($volunteer['contact_number']);
$volunteer_status = htmlspecialchars($volunteer['volunteer_status']);
$training_status = htmlspecialchars($volunteer['training_completion_status']);
$first_training_completed = $volunteer['first_training_completed_at'];
$active_since = $volunteer['active_since'];

// Check volunteer status
$show_warning = false;
$warning_message = '';

if ($volunteer_status === 'New Volunteer') {
    $show_warning = true;
    $warning_message = "You need to complete your first training and get it approved to become an Active Volunteer.";
} elseif ($volunteer_status === 'Inactive') {
    $show_warning = true;
    $warning_message = "Your volunteer account is inactive. Please contact the administrator.";
}

// Handle actions
$success_message = '';
$error_message = '';

// Function to update training status based on dates
function updateTrainingStatus($pdo) {
    $today = date('Y-m-d');
    
    try {
        // Start transaction
        $pdo->beginTransaction();
        
        // Update trainings that have started but not ended yet to 'ongoing'
        $ongoing_query = "UPDATE trainings 
                         SET status = 'ongoing'
                         WHERE status = 'scheduled' 
                         AND training_date <= ? 
                         AND (training_end_date IS NULL OR training_end_date >= ?)";
        
        $ongoing_stmt = $pdo->prepare($ongoing_query);
        $ongoing_stmt->execute([$today, $today]);
        
        // Update trainings that have ended to 'completed'
        $completed_query = "UPDATE trainings 
                           SET status = 'completed'
                           WHERE status IN ('scheduled', 'ongoing') 
                           AND training_end_date IS NOT NULL 
                           AND training_end_date < ?";
        
        $completed_stmt = $pdo->prepare($completed_query);
        $completed_stmt->execute([$today]);
        
        // Also update trainings without end date that started more than 1 day ago
        $completed_no_end_query = "UPDATE trainings 
                                  SET status = 'completed'
                                  WHERE status = 'ongoing' 
                                  AND training_end_date IS NULL 
                                  AND DATE(training_date) < ?";
        
        $completed_no_end_stmt = $pdo->prepare($completed_no_end_query);
        $completed_no_end_stmt->execute([$today]);
        
        $pdo->commit();
        
        // Also update related training registrations
        updateTrainingRegistrations($pdo);
        
    } catch (Exception $e) {
        $pdo->rollBack();
        error_log("Error updating training status: " . $e->getMessage());
    }
}

// Function to update training registrations based on training status
function updateTrainingRegistrations($pdo) {
    try {
        // Update registrations for completed trainings where completion_status is still 'not_started'
        $update_reg_query = "UPDATE training_registrations tr
                            JOIN trainings t ON tr.training_id = t.id
                            SET tr.completion_status = 'completed'
                            WHERE t.status = 'completed' 
                            AND tr.completion_status = 'not_started' 
                            AND tr.status != 'cancelled'";
        
        $pdo->exec($update_reg_query);
        
    } catch (Exception $e) {
        error_log("Error updating training registrations: " . $e->getMessage());
    }
}

// Update training status before processing any actions
updateTrainingStatus($pdo);

// Handle registration
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['register_training'])) {
    $training_id = $_POST['training_id'];
    
    // Check if training exists and is still available
    $training_check = $pdo->prepare("SELECT * FROM trainings WHERE id = ? AND status IN ('scheduled', 'ongoing')");
    $training_check->execute([$training_id]);
    $training = $training_check->fetch();
    
    if (!$training) {
        $error_message = "Training not found or not available for registration.";
    } else {
        // Check if already registered
        $check_registration = $pdo->prepare("SELECT * FROM training_registrations WHERE training_id = ? AND volunteer_id = ? AND status != 'cancelled'");
        $check_registration->execute([$training_id, $volunteer_id]);
        
        if ($check_registration->fetch()) {
            $error_message = "You are already registered for this training.";
        } else {
            // Check if training is full
            if ($training['max_participants'] > 0) {
                $count_registered = $pdo->prepare("SELECT COUNT(*) as count FROM training_registrations WHERE training_id = ? AND status != 'cancelled'");
                $count_registered->execute([$training_id]);
                $registered_count = $count_registered->fetch()['count'];
                
                if ($registered_count >= $training['max_participants']) {
                    $error_message = "This training is already full. Please try another training.";
                }
            }
            
            if (!$error_message) {
                try {
                    // Start transaction
                    $pdo->beginTransaction();
                    
                    // Register for training
                    $register_query = "INSERT INTO training_registrations 
                                      (training_id, volunteer_id, user_id, status, registration_date) 
                                      VALUES (?, ?, ?, 'registered', NOW())";
                    
                    $register_stmt = $pdo->prepare($register_query);
                    $register_stmt->execute([$training_id, $volunteer_id, $user_id]);
                    
                    // Update current participants count
                    $update_query = "UPDATE trainings 
                                    SET current_participants = current_participants + 1
                                    WHERE id = ?";
                    
                    $update_stmt = $pdo->prepare($update_query);
                    $update_stmt->execute([$training_id]);
                    
                    // Send notification to employee/admin
                    $notif_query = "INSERT INTO notifications (user_id, type, title, message, created_at)
                                   SELECT id, 'training_registration', 'New Training Registration',
                                          'Volunteer " . addslashes($volunteer_name) . " has registered for training: " . addslashes($training['title']) . "',
                                          NOW()
                                   FROM users WHERE role IN ('EMPLOYEE', 'ADMIN')";
                    $pdo->exec($notif_query);
                    
                    $pdo->commit();
                    $success_message = "Successfully registered for training!";
                    
                } catch (Exception $e) {
                    $pdo->rollBack();
                    $error_message = "Error registering for training: " . $e->getMessage();
                }
            }
        }
    }
}

// Handle cancellation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['cancel_registration'])) {
    $registration_id = $_POST['registration_id'];
    
    // Check if registration belongs to this volunteer
    $check_query = "SELECT tr.*, t.training_date, t.status as training_status, t.training_end_date
                    FROM training_registrations tr
                    INNER JOIN trainings t ON tr.training_id = t.id
                    WHERE tr.id = ? AND tr.volunteer_id = ?";
    
    $check_stmt = $pdo->prepare($check_query);
    $check_stmt->execute([$registration_id, $volunteer_id]);
    $registration = $check_stmt->fetch();
    
    if (!$registration) {
        $error_message = "Registration not found.";
    } else {
        // Check if training is today or ongoing - cannot cancel
        $today = date('Y-m-d');
        $training_date = date('Y-m-d', strtotime($registration['training_date']));
        $training_end_date = $registration['training_end_date'] ? date('Y-m-d', strtotime($registration['training_end_date'])) : null;
        
        // Check if training has started (today is training date or later) OR training status is ongoing/completed
        $has_started = $today >= $training_date || $registration['training_status'] === 'ongoing' || $registration['training_status'] === 'completed';
        
        if ($has_started) {
            $error_message = "Cannot cancel registration for training that has already started or is ongoing.";
        } else {
            try {
                // Start transaction
                $pdo->beginTransaction();
                
                // Cancel registration
                $cancel_query = "UPDATE training_registrations 
                                SET status = 'cancelled',
                                    completion_status = 'not_started'
                                WHERE id = ? AND volunteer_id = ?";
                
                $cancel_stmt = $pdo->prepare($cancel_query);
                $cancel_stmt->execute([$registration_id, $volunteer_id]);
                
                // Update current participants count
                $update_query = "UPDATE trainings 
                                SET current_participants = GREATEST(0, current_participants - 1)
                                WHERE id = ?";
                
                $update_stmt = $pdo->prepare($update_query);
                $update_stmt->execute([$registration['training_id']]);
                
                $pdo->commit();
                $success_message = "Training registration cancelled successfully.";
                
            } catch (Exception $e) {
                $pdo->rollBack();
                $error_message = "Error cancelling registration: " . $e->getMessage();
            }
        }
    }
}

// Handle marking as completed (for verification)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['mark_completed'])) {
    $registration_id = $_POST['registration_id'];
    
    // Check if registration belongs to this volunteer
    $check_query = "SELECT tr.*, t.training_date, t.training_end_date, t.status as training_status 
                    FROM training_registrations tr
                    INNER JOIN trainings t ON tr.training_id = t.id
                    WHERE tr.id = ? AND tr.volunteer_id = ?";
    
    $check_stmt = $pdo->prepare($check_query);
    $check_stmt->execute([$registration_id, $volunteer_id]);
    $registration = $check_stmt->fetch();
    
    if (!$registration) {
        $error_message = "Registration not found.";
    } else {
        // Check if training is completed (end date passed or status is completed)
        $today = date('Y-m-d');
        $training_end_date = $registration['training_end_date'] ? date('Y-m-d', strtotime($registration['training_end_date'])) : $registration['training_date'];
        
        // Check if training has ended
        $has_ended = $today > $training_end_date || $registration['training_status'] === 'completed';
        
        if (!$has_ended) {
            $error_message = "Cannot mark as completed. Training is not finished yet.";
        } elseif ($registration['completion_status'] === 'completed') {
            $error_message = "Training already marked as completed.";
        } else {
            try {
                // Start transaction
                $pdo->beginTransaction();
                
                // Mark as completed (needs employee verification)
                $complete_query = "UPDATE training_registrations 
                                  SET completion_status = 'completed',
                                      completion_date = NOW()
                                  WHERE id = ? AND volunteer_id = ?";
                
                $complete_stmt = $pdo->prepare($complete_query);
                $complete_stmt->execute([$registration_id, $volunteer_id]);
                
                // Get training title for notification
                $training_title_query = $pdo->prepare("SELECT title FROM trainings WHERE id = ?");
                $training_title_query->execute([$registration['training_id']]);
                $training_title = $training_title_query->fetch()['title'];
                
                // Send notification to employee
                $notif_query = "INSERT INTO notifications (user_id, type, title, message, created_at)
                               SELECT id, 'training_completion', 'Training Completion',
                                      'Volunteer " . addslashes($volunteer_name) . " has completed training: " . addslashes($training_title) . ". Please verify and submit to admin.',
                                      NOW()
                               FROM users WHERE role = 'EMPLOYEE' LIMIT 1";
                $pdo->exec($notif_query);
                
                $pdo->commit();
                $success_message = "Training marked as completed! An employee will verify your completion.";
                
            } catch (Exception $e) {
                $pdo->rollBack();
                $error_message = "Error marking training as completed: " . $e->getMessage();
            }
        }
    }
}

// Get filters
$category_filter = $_GET['category'] ?? 'all';
$status_filter = $_GET['status'] ?? 'all';
$search_term = $_GET['search'] ?? '';
$view = $_GET['view'] ?? 'available'; // 'available' or 'registered'

// Function to get available trainings with filters
function getAvailableTrainings($pdo, $volunteer_id, $category = 'all', $status = 'all', $search = '', $view = 'available') {
    $today = date('Y-m-d');
    
    if ($view === 'registered') {
        // Get trainings the volunteer is registered for
        $sql = "SELECT t.*, 
                tr.id as registration_id,
                tr.status as registration_status,
                tr.completion_status,
                tr.completion_date,
                tr.certificate_issued,
                tr.employee_submitted,
                tr.admin_approved,
                (SELECT COUNT(*) FROM training_registrations tr2 WHERE tr2.training_id = t.id AND tr2.status != 'cancelled') as registered_count
                FROM trainings t
                INNER JOIN training_registrations tr ON t.id = tr.training_id
                WHERE tr.volunteer_id = ? 
                AND tr.status != 'cancelled'";
        
        $params = [$volunteer_id];
    } else {
        // Get available trainings (not registered)
        $sql = "SELECT t.*,
                (SELECT COUNT(*) FROM training_registrations tr WHERE tr.training_id = t.id AND tr.status != 'cancelled') as registered_count,
                CASE 
                    WHEN EXISTS (SELECT 1 FROM training_registrations tr2 WHERE tr2.training_id = t.id AND tr2.volunteer_id = ? AND tr2.status != 'cancelled') THEN 1
                    ELSE 0
                END as is_registered
                FROM trainings t
                WHERE t.status IN ('scheduled', 'ongoing')
                AND NOT EXISTS (
                    SELECT 1 FROM training_registrations tr 
                    WHERE tr.training_id = t.id 
                    AND tr.volunteer_id = ? 
                    AND tr.status != 'cancelled'
                )";
        
        $params = [$volunteer_id, $volunteer_id];
    }
    
    // Apply filters
    if ($view === 'available') {
        if ($status !== 'all') {
            $sql .= " AND t.status = ?";
            $params[] = $status;
        }
    } else {
        if ($status !== 'all') {
            if ($status === 'completed') {
                $sql .= " AND tr.completion_status = 'completed'";
            } elseif ($status === 'in_progress') {
                $sql .= " AND tr.completion_status = 'in_progress'";
            } elseif ($status === 'not_started') {
                $sql .= " AND tr.completion_status = 'not_started'";
            }
        }
    }
    
    if ($search) {
        $sql .= " AND (t.title LIKE ? OR t.description LIKE ? OR t.instructor LIKE ? OR t.location LIKE ?)";
        $search_param = "%$search%";
        $params[] = $search_param;
        $params[] = $search_param;
        $params[] = $search_param;
        $params[] = $search_param;
    }
    
    // Order by date
    if ($view === 'registered') {
        $sql .= " ORDER BY 
                 CASE t.status 
                     WHEN 'ongoing' THEN 1
                     WHEN 'scheduled' THEN 2
                     WHEN 'completed' THEN 3
                     ELSE 4
                 END,
                 t.training_date ASC";
    } else {
        $sql .= " ORDER BY 
                 CASE t.status 
                     WHEN 'ongoing' THEN 1
                     WHEN 'scheduled' THEN 2
                     ELSE 3
                 END,
                 t.training_date ASC";
    }
    
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Error fetching trainings: " . $e->getMessage());
        return [];
    }
}

// Function to get training participants
function getTrainingParticipants($pdo, $training_id) {
    $sql = "SELECT tr.*, 
            v.first_name, v.last_name, v.contact_number, v.email,
            v.volunteer_status,
            CASE 
                WHEN tr.completion_status = 'completed' AND tr.admin_approved = 1 THEN 'certified'
                WHEN tr.completion_status = 'completed' AND tr.employee_submitted = 1 THEN 'pending_approval'
                WHEN tr.completion_status = 'completed' THEN 'needs_verification'
                WHEN tr.completion_status = 'in_progress' THEN 'in_progress'
                ELSE 'registered'
            END as participant_status
            FROM training_registrations tr
            INNER JOIN volunteers v ON tr.volunteer_id = v.id
            WHERE tr.training_id = ? 
            AND tr.status != 'cancelled'
            ORDER BY tr.registration_date DESC";
    
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$training_id]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Error fetching training participants: " . $e->getMessage());
        return [];
    }
}

// Get data based on view
if ($view === 'registered') {
    $trainings = getAvailableTrainings($pdo, $volunteer_id, $category_filter, $status_filter, $search_term, 'registered');
    $page_title = "My Registered Trainings";
    $page_subtitle = "View and manage your training registrations";
} else {
    $trainings = getAvailableTrainings($pdo, $volunteer_id, $category_filter, $status_filter, $search_term, 'available');
    $page_title = "Available Training Modules";
    $page_subtitle = "Register for upcoming training sessions";
}

// Get statistics
$total_available = count(getAvailableTrainings($pdo, $volunteer_id, 'all', 'all', '', 'available'));
$total_registered = count(getAvailableTrainings($pdo, $volunteer_id, 'all', 'all', '', 'registered'));
$completed_trainings = 0;
$in_progress_trainings = 0;

// Get volunteer's completed and certified trainings
$certified_trainings_query = "SELECT COUNT(*) as count FROM training_registrations 
                              WHERE volunteer_id = ? 
                              AND completion_status = 'completed' 
                              AND admin_approved = 1";
$certified_stmt = $pdo->prepare($certified_trainings_query);
$certified_stmt->execute([$volunteer_id]);
$certified_count = $certified_stmt->fetch()['count'];

// Check if volunteer can become active
$can_become_active = false;
if ($volunteer_status === 'New Volunteer' && $certified_count > 0) {
    $can_become_active = true;
}

if ($view === 'registered') {
    foreach ($trainings as $training) {
        if ($training['completion_status'] === 'completed') {
            $completed_trainings++;
        } elseif ($training['completion_status'] === 'in_progress') {
            $in_progress_trainings++;
        }
    }
}

// Close statements
$stmt = null;
$volunteer_stmt = null;
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Register for Training - Fire & Rescue Services Management</title>
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
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-bottom: 30px;
        }

        .stat-card {
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: 16px;
            padding: 20px;
            text-align: center;
            transition: all 0.3s ease;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
        }

        .stat-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 8px 16px rgba(0, 0, 0, 0.1);
        }

        .stat-icon {
            width: 50px;
            height: 50px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 12px;
            font-size: 20px;
        }

        .stat-icon.available {
            background: rgba(59, 130, 246, 0.1);
            color: var(--info);
        }

        .stat-icon.registered {
            background: rgba(245, 158, 11, 0.1);
            color: var(--warning);
        }

        .stat-icon.completed {
            background: rgba(16, 185, 129, 0.1);
            color: var(--success);
        }

        .stat-icon.in-progress {
            background: rgba(139, 92, 246, 0.1);
            color: var(--purple);
        }

        .stat-icon.certified {
            background: rgba(139, 92, 246, 0.1);
            color: var(--purple);
        }

        .stat-value {
            font-size: 28px;
            font-weight: 800;
            margin-bottom: 5px;
        }

        .stat-label {
            color: var(--text-light);
            font-size: 13px;
        }

        .view-tabs {
            display: flex;
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: 12px;
            padding: 4px;
            margin-bottom: 30px;
            width: fit-content;
        }

        .view-tab {
            padding: 12px 24px;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 8px;
            color: var(--text-color);
        }

        .view-tab:hover {
            background: var(--gray-100);
        }

        .view-tab.active {
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            color: white;
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
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-bottom: 15px;
        }

        .filter-group {
            display: flex;
            flex-direction: column;
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

        .btn-danger {
            background: linear-gradient(135deg, var(--danger), #b91c1c);
            color: white;
        }

        .btn-danger:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(220, 38, 38, 0.3);
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

        .training-info {
            display: flex;
            flex-direction: column;
            gap: 8px;
        }

        .training-title {
            font-weight: 700;
            color: var(--text-color);
            font-size: 16px;
        }

        .training-description {
            font-size: 13px;
            color: var(--text-light);
            line-height: 1.5;
        }

        .training-details {
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
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

        .training-date {
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

        .training-participants {
            text-align: center;
        }

        .participants-count {
            font-size: 18px;
            font-weight: 700;
            color: var(--text-color);
        }

        .participants-label {
            font-size: 12px;
            color: var(--text-light);
        }

        .participants-progress {
            width: 80px;
            height: 6px;
            background: var(--border-color);
            border-radius: 3px;
            margin: 8px auto 0;
            overflow: hidden;
        }

        .participants-fill {
            height: 100%;
            background: linear-gradient(90deg, var(--primary-color), var(--secondary-color));
            border-radius: 3px;
            transition: width 0.3s ease;
        }

        .status-badge {
            display: inline-block;
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            text-align: center;
            min-width: 100px;
        }

        .status-scheduled {
            background: rgba(59, 130, 246, 0.1);
            color: var(--info);
            border: 1px solid rgba(59, 130, 246, 0.2);
        }

        .status-ongoing {
            background: rgba(245, 158, 11, 0.1);
            color: var(--warning);
            border: 1px solid rgba(245, 158, 11, 0.2);
        }

        .status-completed {
            background: rgba(16, 185, 129, 0.1);
            color: var(--success);
            border: 1px solid rgba(16, 185, 129, 0.2);
        }

        .status-cancelled {
            background: rgba(220, 38, 38, 0.1);
            color: var(--danger);
            border: 1px solid rgba(220, 38, 38, 0.2);
        }

        .completion-badge {
            display: inline-block;
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            text-align: center;
            min-width: 120px;
        }

        .completion-not_started {
            background: rgba(156, 163, 175, 0.1);
            color: var(--gray-500);
            border: 1px solid rgba(156, 163, 175, 0.2);
        }

        .completion-in_progress {
            background: rgba(59, 130, 246, 0.1);
            color: var(--info);
            border: 1px solid rgba(59, 130, 246, 0.2);
        }

        .completion-completed {
            background: rgba(16, 185, 129, 0.1);
            color: var(--success);
            border: 1px solid rgba(16, 185, 129, 0.2);
        }

        .completion-failed {
            background: rgba(220, 38, 38, 0.1);
            color: var(--danger);
            border: 1px solid rgba(220, 38, 38, 0.2);
        }

        .registration-status {
            font-size: 11px;
            color: var(--text-light);
            margin-top: 4px;
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
            border-radius: 20px;
            width: 90%;
            max-width: 800px;
            max-height: 80vh;
            transform: scale(0.9);
            transition: all 0.3s ease;
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.15);
            display: flex;
            flex-direction: column;
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
            flex-shrink: 0;
        }
        
        .modal-title {
            font-size: 20px;
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
            overflow-y: auto;
            flex-grow: 1;
        }
        
        .modal-actions {
            padding: 24px;
            border-top: 1px solid var(--border-color);
            display: flex;
            gap: 12px;
            justify-content: flex-end;
            flex-shrink: 0;
        }

        .participants-list {
            display: grid;
            gap: 12px;
        }

        .participant-item {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 12px;
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: 10px;
        }

        .participant-info {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .participant-avatar {
            width: 36px;
            height: 36px;
            border-radius: 50%;
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            font-size: 14px;
            flex-shrink: 0;
        }

        .participant-details {
            display: flex;
            flex-direction: column;
        }

        .participant-name {
            font-weight: 600;
            color: var(--text-color);
            font-size: 13px;
        }

        .participant-status {
            display: flex;
            flex-direction: column;
            align-items: flex-end;
            gap: 4px;
        }

        .participant-status-badge {
            display: inline-block;
            padding: 4px 8px;
            border-radius: 12px;
            font-size: 10px;
            font-weight: 600;
        }

        .status-certified {
            background: rgba(16, 185, 129, 0.1);
            color: var(--success);
        }

        .status-pending_approval {
            background: rgba(245, 158, 11, 0.1);
            color: var(--warning);
        }

        .status-needs_verification {
            background: rgba(59, 130, 246, 0.1);
            color: var(--info);
        }

        .status-in_progress {
            background: rgba(139, 92, 246, 0.1);
            color: var(--purple);
        }

        .status-registered {
            background: rgba(156, 163, 175, 0.1);
            color: var(--gray-500);
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

        .form-group {
            margin-bottom: 20px;
        }

        .form-label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: var(--text-color);
            font-size: 14px;
        }

        .form-input, .form-select, .form-textarea {
            width: 100%;
            padding: 12px 16px;
            border-radius: 10px;
            border: 1px solid var(--border-color);
            background: var(--card-bg);
            color: var(--text-color);
            font-size: 14px;
            transition: all 0.3s ease;
        }

        .form-input:focus, .form-select:focus, .form-textarea:focus {
            outline: none;
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(220, 38, 38, 0.1);
        }

        .info-box {
            background: rgba(59, 130, 246, 0.05);
            border: 1px solid rgba(59, 130, 246, 0.2);
            border-radius: 10px;
            padding: 15px;
            margin-bottom: 20px;
        }

        .info-box.warning {
            background: rgba(245, 158, 11, 0.05);
            border: 1px solid rgba(245, 158, 11, 0.2);
        }

        .info-box.success {
            background: rgba(16, 185, 129, 0.05);
            border: 1px solid rgba(16, 185, 129, 0.2);
        }

        .volunteer-status-banner {
            background: linear-gradient(135deg, #f59e0b, #d97706);
            color: white;
            padding: 15px 20px;
            border-radius: 10px;
            margin-bottom: 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .volunteer-status-banner.success {
            background: linear-gradient(135deg, var(--success), #0da271);
        }
        
        .volunteer-status-banner.warning {
            background: linear-gradient(135deg, var(--warning), #d97706);
        }
        
        .volunteer-status-content {
            display: flex;
            align-items: center;
            gap: 15px;
        }
        
        .volunteer-status-icon {
            font-size: 24px;
        }

        /* Date status indicators */
        .date-status {
            display: flex;
            align-items: center;
            gap: 5px;
            margin-top: 4px;
            font-size: 11px;
        }
        
        .date-status.upcoming {
            color: var(--info);
        }
        
        .date-status.ongoing {
            color: var(--warning);
            font-weight: 600;
        }
        
        .date-status.completed {
            color: var(--success);
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
                grid-template-columns: 1fr;
            }
            
            .table {
                display: block;
                overflow-x: auto;
            }
            
            .action-buttons {
                flex-direction: column;
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
            
            .training-info {
                flex-direction: column;
                align-items: flex-start;
                gap: 8px;
            }
            
            .training-details {
                flex-direction: column;
                gap: 8px;
            }
            
            .participant-item {
                flex-direction: column;
                gap: 12px;
                align-items: flex-start;
            }
            
            .participant-status {
                align-items: flex-start;
            }
        }

        @media (max-width: 576px) {
            .modal {
                width: 95%;
                max-width: 95%;
            }
            
            .btn {
                justify-content: center;
            }
            
            .view-tabs {
                flex-direction: column;
                width: 100%;
            }
        }

        /* User profile dropdown styles */
        .user-profile-dropdown {
            position: absolute;
            top: 100%;
            right: 0;
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: 8px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
            min-width: 200px;
            z-index: 1000;
            display: none;
            margin-top: 10px;
        }
        
        .user-profile-dropdown.show {
            display: block;
        }
        
        .dropdown-item {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 12px 16px;
            color: var(--text-color);
            text-decoration: none;
            transition: all 0.3s ease;
        }
        
        .dropdown-item:hover {
            background: var(--gray-100);
        }
        
        .dropdown-item i {
            font-size: 18px;
        }
        
        .dropdown-divider {
            height: 1px;
            background: var(--border-color);
            margin: 4px 0;
        }
    </style>
</head>
<body>
    <!-- Participants Modal -->
    <div class="modal-overlay" id="participants-modal">
        <div class="modal">
            <div class="modal-header">
                <h2 class="modal-title" id="modal-title">Training Participants</h2>
                <button class="modal-close" id="modal-close">&times;</button>
            </div>
            <div class="modal-body">
                <div id="modal-participants-content">
                    <!-- Participants list will be loaded here -->
                </div>
            </div>
            <div class="modal-actions">
                <button type="button" class="btn btn-secondary" id="close-participants-modal">Close</button>
            </div>
        </div>
    </div>
    
    <!-- Registration Confirmation Modal -->
    <div class="modal-overlay" id="register-modal">
        <div class="modal">
            <div class="modal-header">
                <h2 class="modal-title">Register for Training</h2>
                <button class="modal-close" id="register-modal-close">&times;</button>
            </div>
            <form method="POST" id="register-form">
                <div class="modal-body">
                    <input type="hidden" name="training_id" id="register-training-id">
                    
                    <div class="form-group">
                        <div class="form-label" id="register-training-info">Loading training information...</div>
                    </div>
                    
                    <div class="form-group">
                        <div class="info-box">
                            <strong>Registration Terms:</strong>
                            <ul style="margin: 8px 0 0 20px; font-size: 13px; color: var(--text-light);">
                                <li>You can cancel registration anytime before the training starts</li>
                                <li>Once training starts, cancellation is not allowed</li>
                                <li>Attendance will be recorded by the instructor</li>
                                <li>Completion must be verified by an employee</li>
                                <li>Certificate will be issued after admin approval</li>
                            </ul>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <div style="background: rgba(220, 38, 38, 0.05); padding: 15px; border-radius: 10px; border-left: 4px solid var(--primary-color);">
                            <strong>Confirmation Required</strong>
                            <p style="margin-top: 5px; font-size: 13px; color: var(--text-light);" id="confirmation-text">
                                Are you sure you want to register for this training?
                            </p>
                        </div>
                    </div>
                </div>
                <div class="modal-actions">
                    <button type="button" class="btn btn-secondary" id="close-register-modal">Cancel</button>
                    <button type="submit" name="register_training" class="btn btn-success">
                        <i class='bx bx-check'></i>
                        Confirm Registration
                    </button>
                </div>
            </form>
        </div>
    </div>
    
    <!-- Cancel Confirmation Modal -->
    <div class="modal-overlay" id="cancel-modal">
        <div class="modal">
            <div class="modal-header">
                <h2 class="modal-title">Cancel Registration</h2>
                <button class="modal-close" id="cancel-modal-close">&times;</button>
            </div>
            <form method="POST" id="cancel-form">
                <div class="modal-body">
                    <input type="hidden" name="registration_id" id="cancel-registration-id">
                    
                    <div class="form-group">
                        <div class="form-label" id="cancel-training-info">Loading registration information...</div>
                    </div>
                    
                    <div class="form-group">
                        <div class="info-box warning">
                            <strong>Important:</strong>
                            <ul style="margin: 8px 0 0 20px; font-size: 13px; color: var(--text-light);">
                                <li>You can only cancel before the training starts</li>
                                <li>If training is today or ongoing, cancellation is not allowed</li>
                                <li>Your spot will be made available for other volunteers</li>
                                <li>Multiple cancellations may affect future registrations</li>
                            </ul>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <div style="background: rgba(220, 38, 38, 0.05); padding: 15px; border-radius: 10px; border-left: 4px solid var(--primary-color);">
                            <strong>Confirmation Required</strong>
                            <p style="margin-top: 5px; font-size: 13px; color: var(--text-light);" id="cancel-confirmation-text">
                                Are you sure you want to cancel your registration?
                            </p>
                        </div>
                    </div>
                </div>
                <div class="modal-actions">
                    <button type="button" class="btn btn-secondary" id="close-cancel-modal">Keep Registration</button>
                    <button type="submit" name="cancel_registration" class="btn btn-danger">
                        <i class='bx bx-x'></i>
                        Cancel Registration
                    </button>
                </div>
            </form>
        </div>
    </div>
    
    <!-- Completion Confirmation Modal -->
    <div class="modal-overlay" id="complete-modal">
        <div class="modal">
            <div class="modal-header">
                <h2 class="modal-title">Mark Training as Completed</h2>
                <button class="modal-close" id="complete-modal-close">&times;</button>
            </div>
            <form method="POST" id="complete-form">
                <div class="modal-body">
                    <input type="hidden" name="registration_id" id="complete-registration-id">
                    
                    <div class="form-group">
                        <div class="form-label" id="complete-training-info">Loading training information...</div>
                    </div>
                    
                    <div class="form-group">
                        <div class="info-box">
                            <strong>Completion Process:</strong>
                            <ul style="margin: 8px 0 0 20px; font-size: 13px; color: var(--text-light);">
                                <li>Training must be finished (end date passed)</li>
                                <li>You must have attended the training sessions</li>
                                <li>Employee will verify your completion</li>
                                <li>After verification, employee submits to admin</li>
                                <li>Admin approves and issues certificate</li>
                                <li>You will be notified when certificate is ready</li>
                            </ul>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <div style="background: rgba(16, 185, 129, 0.05); padding: 15px; border-radius: 10px; border-left: 4px solid var(--success);">
                            <strong>Confirmation Required</strong>
                            <p style="margin-top: 5px; font-size: 13px; color: var(--text-light);" id="complete-confirmation-text">
                                Are you sure you have completed this training?
                            </p>
                        </div>
                    </div>
                </div>
                <div class="modal-actions">
                    <button type="button" class="btn btn-secondary" id="close-complete-modal">Not Yet</button>
                    <button type="submit" name="mark_completed" class="btn btn-success">
                        <i class='bx bx-check'></i>
                        Mark as Completed
                    </button>
                </div>
            </form>
        </div>
    </div>
    
    <!-- Notification -->
    <div class="notification <?php echo $success_message ? 'notification-success show' : ($error_message ? 'notification-error show' : ''); ?>" id="notification">
        <i class='notification-icon bx <?php echo $success_message ? 'bx-check-circle' : ($error_message ? 'bx-error' : ''); ?>'></i>
        <div class="notification-content">
            <div class="notification-title"><?php echo $success_message ? 'Success' : ($error_message ? 'Error' : ''); ?></div>
            <div class="notification-message"><?php echo $success_message ?: $error_message; ?></div>
        </div>
        <button class="notification-close" id="notification-close">&times;</button>
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
                    <a href="../dashboard.php" class="menu-item" id="dashboard-menu">
                        <div class="icon-box icon-bg-red">
                            <i class='bx bxs-dashboard icon-red'></i>
                        </div>
                        <span class="font-medium">Dashboard</span>
                    </a>
                    
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
                        <a href="#" class="submenu-item">Active Incidents</a>
                        <a href="#" class="submenu-item">Incident Reports</a>
                        <a href="#" class="submenu-item">Response History</a>
                    </div>
                    
                    <div class="menu-item" onclick="toggleSubmenu('volunteer')">
                        <div class="icon-box icon-bg-blue">
                            <i class='bx bxs-user-detail icon-blue'></i>
                        </div>
                        <span class="font-medium">Volunteer Roster</span>
                        <svg class="dropdown-arrow menu-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                        </svg>
                    </div>
                    <div id="volunteer" class="submenu">
                        <a href="#" class="submenu-item">Volunteer List</a>
                        <a href="#" class="submenu-item">Roles & Skills</a>
                        <a href="#" class="submenu-item">Availability</a>
                    </div>
                    
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
                        <a href="#" class="submenu-item">Equipment List</a>
                        <a href="#" class="submenu-item">Stock Levels</a>
                        <a href="#" class="submenu-item">Maintenance Logs</a>
                    </div>
                    
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
                      <a href="../sds/view_shifts.php" class="submenu-item">Shift Calendar</a>
                        <a href="../sds/confirm_availability.php" class="submenu-item">Confirm Availability</a>
                        <a href="../sds/duty_assignments.php" class="submenu-item">Duty Assignments</a>
                        <a href="../sds/attendance_logs.php" class="submenu-item">Attendance Logs</a>
                    </div>
                    
                    <div class="menu-item" onclick="toggleSubmenu('training')">
                        <div class="icon-box icon-bg-teal">
                            <i class='bx bxs-graduation icon-teal'></i>
                        </div>
                        <span class="font-medium">Training & Certification</span>
                        <svg class="dropdown-arrow menu-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                        </svg>
                    </div>
                    <div id="training" class="submenu active">
                         <a href="register_training.php" class="submenu-item active">Register for Training</a>
            <a href="training_records.php" class="submenu-item">Training Records</a>
            <a href="certification_status.php" class="submenu-item">Certification Status</a>
          
                    </div>
                    
                    <div class="menu-item" onclick="toggleSubmenu('inspection')">
                        <div class="icon-box icon-bg-yellow">
                            <i class='bx bxs-check-shield icon-yellow'></i>
                        </div>
                        <span class="font-medium">Establishment Inspections</span>
                        <svg class="dropdown-arrow menu-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                        </svg>
                    </div>
                    <div id="inspection" class="submenu">
                        <a href="#" class="submenu-item">Inspection Scheduler</a>
                        <a href="#" class="submenu-item">Inspection Results</a>
                        <a href="#" class="submenu-item">Violation Notices</a>
                    </div>
                    
                    <div class="menu-item" onclick="toggleSubmenu('postincident')">
                        <div class="icon-box icon-bg-pink">
                            <i class='bx bxs-file-doc icon-pink'></i>
                        </div>
                        <span class="font-medium">Post-Incident Analytics</span>
                        <svg class="dropdown-arrow menu-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                        </svg>
                    </div>
                    <div id="postincident" class="submenu">
                        <a href="#" class="submenu-item">Analytics Dashboard</a>
                        <a href="#" class="submenu-item">Incident Trends</a>
                        <a href="#" class="submenu-item">Lessons Learned</a>
                    </div>
                </div>
                
                <p class="menu-title" style="margin-top: 32px;">GENERAL</p>
                
                <div class="menu-items">
                    <a href="../settings.php" class="menu-item">
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
                            <input type="text" placeholder="Search trainings..." class="search-input" id="search-input">
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
                                <img src="../../profile/uploads/avatars/<?php echo $avatar; ?>" alt="User" class="user-avatar">
                            <?php else: ?>
                                <div class="user-avatar" style="background: linear-gradient(135deg, var(--primary-color), var(--secondary-color)); color: white; display: flex; align-items: center; justify-content: center; font-weight: bold; border-radius: 50%; width: 40px; height: 40px;">
                                    <?php echo strtoupper(substr($first_name, 0, 1) . substr($last_name, 0, 1)); ?>
                                </div>
                            <?php endif; ?>
                            <div class="user-info">
                                <p class="user-name"><?php echo $full_name; ?></p>
                                <p class="user-email"><?php echo $email; ?></p>
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
                <div class="dashboard-header">
                    <div>
                        <h1 class="dashboard-title"><?php echo $page_title; ?></h1>
                        <p class="dashboard-subtitle"><?php echo $page_subtitle; ?></p>
                    </div>
                </div>
                
                <!-- Content Container -->
                <div class="content-container">
                    <!-- Volunteer Status Banner -->
                    <?php if ($show_warning): ?>
                        <div class="volunteer-status-banner warning">
                            <div class="volunteer-status-content">
                                <i class='volunteer-status-icon bx bx-info-circle'></i>
                                <div>
                                    <h3 style="margin: 0; font-size: 16px;">Volunteer Status: <?php echo $volunteer_status; ?></h3>
                                    <p style="margin: 5px 0 0 0; font-size: 13px; opacity: 0.9;"><?php echo $warning_message; ?></p>
                                </div>
                            </div>
                        </div>
                    <?php elseif ($volunteer_status === 'Active'): ?>
                        <div class="volunteer-status-banner success">
                            <div class="volunteer-status-content">
                                <i class='volunteer-status-icon bx bx-check-circle'></i>
                                <div>
                                    <h3 style="margin: 0; font-size: 16px;">Volunteer Status: <?php echo $volunteer_status; ?></h3>
                                    <p style="margin: 5px 0 0 0; font-size: 13px; opacity: 0.9;">
                                        Active since: <?php echo date('M j, Y', strtotime($active_since)); ?>
                                    </p>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>
                    
                    <!-- Statistics -->
                    <div class="stats-container">
                        <div class="stat-card">
                            <div class="stat-icon available">
                                <i class='bx bx-book-open'></i>
                            </div>
                            <div class="stat-value"><?php echo $total_available; ?></div>
                            <div class="stat-label">Available Trainings</div>
                        </div>
                        
                        <div class="stat-card">
                            <div class="stat-icon registered">
                                <i class='bx bx-calendar-check'></i>
                            </div>
                            <div class="stat-value"><?php echo $total_registered; ?></div>
                            <div class="stat-label">Registered Trainings</div>
                        </div>
                        
                        <?php if ($view === 'registered'): ?>
                        <div class="stat-card">
                            <div class="stat-icon completed">
                                <i class='bx bx-check-circle'></i>
                            </div>
                            <div class="stat-value"><?php echo $completed_trainings; ?></div>
                            <div class="stat-label">Completed</div>
                        </div>
                        
                        <div class="stat-card">
                            <div class="stat-icon in-progress">
                                <i class='bx bx-time-five'></i>
                            </div>
                            <div class="stat-value"><?php echo $in_progress_trainings; ?></div>
                            <div class="stat-label">In Progress</div>
                        </div>
                        <?php endif; ?>
                        
                        <div class="stat-card">
                            <div class="stat-icon certified">
                                <i class='bx bx-certificate'></i>
                            </div>
                            <div class="stat-value"><?php echo $certified_count; ?></div>
                            <div class="stat-label">Certified Trainings</div>
                        </div>
                    </div>
                    
                    <!-- View Tabs -->
                    <div class="view-tabs">
                        <div class="view-tab <?php echo $view === 'available' ? 'active' : ''; ?>" onclick="window.location.href='?view=available'">
                            <i class='bx bx-search'></i>
                            <span>Available Trainings</span>
                        </div>
                        <div class="view-tab <?php echo $view === 'registered' ? 'active' : ''; ?>" onclick="window.location.href='?view=registered'">
                            <i class='bx bx-list-check'></i>
                            <span>My Registrations</span>
                        </div>
                    </div>
                    
                    <!-- Filters -->
                    <div class="filters-container">
                        <h3 class="filters-title">
                            <i class='bx bx-filter-alt'></i>
                            Filter Trainings
                        </h3>
                        
                        <form method="GET" id="filters-form">
                            <input type="hidden" name="view" value="<?php echo $view; ?>">
                            
                            <div class="filters-grid">
                                <?php if ($view === 'available'): ?>
                                <div class="filter-group">
                                    <label class="filter-label" for="status">Training Status</label>
                                    <select class="filter-select" id="status" name="status">
                                        <option value="all" <?php echo $status_filter === 'all' ? 'selected' : ''; ?>>All Status</option>
                                        <option value="scheduled" <?php echo $status_filter === 'scheduled' ? 'selected' : ''; ?>>Scheduled (Upcoming)</option>
                                        <option value="ongoing" <?php echo $status_filter === 'ongoing' ? 'selected' : ''; ?>>Ongoing (Active)</option>
                                    </select>
                                </div>
                                <?php else: ?>
                                <div class="filter-group">
                                    <label class="filter-label" for="status">Completion Status</label>
                                    <select class="filter-select" id="status" name="status">
                                        <option value="all" <?php echo $status_filter === 'all' ? 'selected' : ''; ?>>All Status</option>
                                        <option value="not_started" <?php echo $status_filter === 'not_started' ? 'selected' : ''; ?>>Not Started</option>
                                        <option value="in_progress" <?php echo $status_filter === 'in_progress' ? 'selected' : ''; ?>>In Progress</option>
                                        <option value="completed" <?php echo $status_filter === 'completed' ? 'selected' : ''; ?>>Completed</option>
                                    </select>
                                </div>
                                <?php endif; ?>
                                
                                <div class="filter-group">
                                    <label class="filter-label" for="search">Search</label>
                                    <input type="text" class="filter-input" id="search" name="search" 
                                           value="<?php echo htmlspecialchars($search_term); ?>" 
                                           placeholder="Search by title, instructor, location...">
                                </div>
                                
                                <div class="filter-group">
                                    <label class="filter-label">&nbsp;</label>
                                    <div style="display: flex; gap: 10px;">
                                        <button type="submit" class="btn btn-primary">
                                            <i class='bx bx-search'></i>
                                            Apply Filters
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
                    
                    <!-- Trainings Table -->
                    <div class="table-container">
                        <div class="table-header">
                            <h3 class="table-title">
                                <?php if ($view === 'available'): ?>
                                    <i class='bx bx-book-open'></i>
                                    Available Training Modules
                                <?php else: ?>
                                    <i class='bx bx-list-check'></i>
                                    My Training Registrations
                                <?php endif; ?>
                                <span style="font-size: 14px; font-weight: normal; color: var(--text-light); margin-left: 10px;">
                                    <?php echo count($trainings); ?> found
                                </span>
                            </h3>
                        </div>
                        
                        <?php if (count($trainings) > 0): ?>
                            <table class="table">
                                <thead>
                                    <tr>
                                        <th>Training Details</th>
                                        <th>Date & Duration</th>
                                        <th>Participants</th>
                                        <th>Status</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($trainings as $training): 
                                        $start_date = date('M j, Y', strtotime($training['training_date']));
                                        $end_date = $training['training_end_date'] ? date('M j, Y', strtotime($training['training_end_date'])) : null;
                                        $duration = $training['duration_hours'] ? number_format($training['duration_hours'], 1) . ' hours' : 'N/A';
                                        
                                        $participants_percentage = $training['max_participants'] > 0 ? 
                                            min(100, ($training['registered_count'] / $training['max_participants']) * 100) : 0;
                                        
                                        // Determine current date status
                                        $today = date('Y-m-d');
                                        $training_date = date('Y-m-d', strtotime($training['training_date']));
                                        $training_end_date = $training['training_end_date'] ? date('Y-m-d', strtotime($training['training_end_date'])) : $training_date;
                                        
                                        // Check if training has started
                                        $has_started = $today >= $training_date;
                                        // Check if training has ended
                                        $has_ended = $today > $training_end_date;
                                        
                                        // Determine date status for display
                                        $date_status = '';
                                        $date_status_class = '';
                                        if ($has_ended) {
                                            $date_status = 'Completed';
                                            $date_status_class = 'completed';
                                        } elseif ($has_started) {
                                            $date_status = 'Ongoing';
                                            $date_status_class = 'ongoing';
                                        } else {
                                            $date_status = 'Upcoming';
                                            $date_status_class = 'upcoming';
                                        }
                                        
                                        // Determine actions based on view
                                        $can_cancel = !$has_started && $training['status'] !== 'ongoing' && $training['status'] !== 'completed';
                                        
                                        // Check if training is completed (for marking as completed)
                                        $can_mark_completed = false;
                                        if ($view === 'registered' && isset($training['completion_status'])) {
                                            $can_mark_completed = $has_ended && 
                                                                 $training['completion_status'] !== 'completed' &&
                                                                 $training['completion_status'] !== 'failed';
                                        }
                                    ?>
                                    <tr>
                                        <td>
                                            <div class="training-info">
                                                <div class="training-title">
                                                    <?php echo htmlspecialchars($training['title']); ?>
                                                </div>
                                                <div class="training-description">
                                                    <?php echo htmlspecialchars(substr($training['description'], 0, 100)); ?>...
                                                </div>
                                                <div class="training-details">
                                                    <?php if ($training['instructor']): ?>
                                                    <div class="detail-item">
                                                        <i class='bx bx-user'></i>
                                                        <span><?php echo htmlspecialchars($training['instructor']); ?></span>
                                                    </div>
                                                    <?php endif; ?>
                                                    
                                                    <?php if ($training['location']): ?>
                                                    <div class="detail-item">
                                                        <i class='bx bx-map'></i>
                                                        <span><?php echo htmlspecialchars($training['location']); ?></span>
                                                    </div>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        </td>
                                        <td>
                                            <div class="training-date">
                                                <div class="date-label">Start Date</div>
                                                <div class="date-value"><?php echo $start_date; ?></div>
                                                
                                                <?php if ($end_date && $end_date !== $start_date): ?>
                                                <div class="date-label" style="margin-top: 8px;">End Date</div>
                                                <div class="date-value"><?php echo $end_date; ?></div>
                                                <?php endif; ?>
                                                
                                                <div class="date-label" style="margin-top: 8px;">Duration</div>
                                                <div class="date-value"><?php echo $duration; ?></div>
                                                
                                                <div class="date-status <?php echo $date_status_class; ?>">
                                                    <i class='bx bx-<?php echo $has_ended ? 'check-circle' : ($has_started ? 'time-five' : 'calendar'); ?>'></i>
                                                    <?php echo $date_status; ?>
                                                </div>
                                            </div>
                                        </td>
                                        <td>
                                            <div class="training-participants">
                                                <div class="participants-count">
                                                    <?php echo $training['registered_count']; ?>
                                                </div>
                                                <div class="participants-label">
                                                    Registered
                                                </div>
                                                
                                                <?php if ($training['max_participants'] > 0): ?>
                                                <div class="participants-progress">
                                                    <div class="participants-fill" style="width: <?php echo $participants_percentage; ?>%;"></div>
                                                </div>
                                                <div class="participants-label">
                                                    <?php echo $training['registered_count']; ?> / <?php echo $training['max_participants']; ?>
                                                </div>
                                                <?php endif; ?>
                                                
                                                <button type="button" class="btn btn-info btn-sm view-participants-btn" 
                                                        style="margin-top: 8px; padding: 4px 8px; font-size: 11px;"
                                                        data-training-id="<?php echo $training['id']; ?>"
                                                        data-training-title="<?php echo htmlspecialchars($training['title']); ?>">
                                                    <i class='bx bx-group'></i>
                                                    View Participants
                                                </button>
                                            </div>
                                        </td>
                                        <td>
                                            <?php if ($view === 'available'): ?>
                                                <div class="status-badge status-<?php echo $training['status']; ?>">
                                                    <?php echo ucfirst($training['status']); ?>
                                                </div>
                                            <?php else: ?>
                                                <div class="completion-badge completion-<?php echo $training['completion_status']; ?>">
                                                    <?php echo ucfirst(str_replace('_', ' ', $training['completion_status'])); ?>
                                                </div>
                                                
                                                <?php if ($training['employee_submitted']): ?>
                                                    <div class="registration-status" style="color: var(--warning);">
                                                        <i class='bx bx-time'></i> Submitted to Admin
                                                    </div>
                                                <?php elseif ($training['admin_approved']): ?>
                                                    <div class="registration-status" style="color: var(--success);">
                                                        <i class='bx bx-check-circle'></i> Approved
                                                    </div>
                                                <?php elseif ($training['certificate_issued']): ?>
                                                    <div class="registration-status" style="color: var(--success);">
                                                        <i class='bx bx-certificate'></i> Certificate Issued
                                                    </div>
                                                <?php endif; ?>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div class="action-buttons">
                                                <?php if ($view === 'available'): ?>
                                                    <?php if (isset($training['is_registered']) && $training['is_registered']): ?>
                                                        <span style="color: var(--success); font-size: 12px;">
                                                            <i class='bx bx-check-circle'></i> Registered
                                                        </span>
                                                    <?php else: ?>
                                                        <?php if ($training['max_participants'] > 0 && $training['registered_count'] >= $training['max_participants']): ?>
                                                            <span style="color: var(--danger); font-size: 12px;">
                                                                <i class='bx bx-x-circle'></i> Full
                                                            </span>
                                                        <?php elseif ($training['status'] === 'completed'): ?>
                                                            <span style="color: var(--gray-500); font-size: 12px;">
                                                                <i class='bx bx-calendar-x'></i> Ended
                                                            </span>
                                                        <?php else: ?>
                                                            <button type="button" class="btn btn-success btn-sm register-btn"
                                                                    data-training-id="<?php echo $training['id']; ?>"
                                                                    data-training-title="<?php echo htmlspecialchars($training['title']); ?>"
                                                                    data-training-date="<?php echo $start_date; ?>"
                                                                    data-participants="<?php echo $training['registered_count']; ?>/<?php echo $training['max_participants']; ?>">
                                                                <i class='bx bx-user-plus'></i>
                                                                Register
                                                            </button>
                                                        <?php endif; ?>
                                                    <?php endif; ?>
                                                <?php else: ?>
                                                    <?php if ($can_cancel): ?>
                                                        <button type="button" class="btn btn-danger btn-sm cancel-btn"
                                                                data-registration-id="<?php echo $training['registration_id']; ?>"
                                                                data-training-title="<?php echo htmlspecialchars($training['title']); ?>"
                                                                data-training-date="<?php echo $start_date; ?>">
                                                            <i class='bx bx-x'></i>
                                                            Cancel
                                                        </button>
                                                    <?php endif; ?>
                                                    
                                                    <?php if ($can_mark_completed): ?>
                                                        <button type="button" class="btn btn-success btn-sm complete-btn"
                                                                data-registration-id="<?php echo $training['registration_id']; ?>"
                                                                data-training-title="<?php echo htmlspecialchars($training['title']); ?>"
                                                                data-training-date="<?php echo $start_date; ?>">
                                                            <i class='bx bx-check'></i>
                                                            Mark Completed
                                                        </button>
                                                    <?php endif; ?>
                                                    
                                                    <?php if ($training['certificate_issued'] && $training['admin_approved']): ?>
                                                        <button type="button" class="btn btn-info btn-sm" onclick="viewCertificate(<?php echo $training['registration_id']; ?>)">
                                                            <i class='bx bx-certificate'></i>
                                                            View Certificate
                                                        </button>
                                                    <?php endif; ?>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php else: ?>
                            <div class="empty-state">
                                <?php if ($view === 'available'): ?>
                                    <i class='bx bx-book'></i>
                                    <h3>No Training Modules Available</h3>
                                    <p>There are no training modules available for registration at the moment. Please check back later or contact the administrator for upcoming training schedules.</p>
                                <?php else: ?>
                                    <i class='bx bx-calendar-x'></i>
                                    <h3>No Training Registrations</h3>
                                    <p>You haven't registered for any training modules yet. Browse available trainings to register and enhance your skills.</p>
                                    <a href="?view=available" class="btn btn-primary" style="margin-top: 20px;">
                                        <i class='bx bx-search'></i>
                                        Browse Available Trainings
                                    </a>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                    
                    <!-- Information Section -->
                    <div class="section-container" style="border: 1px solid var(--border-color); border-radius: 16px; padding: 24px;">
                        <h3 style="font-size: 18px; font-weight: 700; color: var(--text-color); margin-bottom: 20px; display: flex; align-items: center; gap: 10px;">
                            <i class='bx bx-help-circle'></i>
                            Training & Volunteer Activation Guide
                        </h3>
                        
                        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 20px; margin-bottom: 20px;">
                            <div style="background: rgba(59, 130, 246, 0.1); padding: 20px; border-radius: 10px; border: 1px solid rgba(59, 130, 246, 0.2);">
                                <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 10px;">
                                    <div style="background: var(--info); color: white; width: 30px; height: 30px; border-radius: 50%; display: flex; align-items: center; justify-content: center;">
                                        <i class='bx bx-user-plus'></i>
                                    </div>
                                    <h4 style="margin: 0; color: var(--text-color);">Step 1: Registration</h4>
                                </div>
                                <p style="margin: 0; color: var(--text-color); font-size: 13px;">
                                    Register for available training modules. You can cancel anytime before the training starts.
                                </p>
                            </div>
                            
                            <div style="background: rgba(245, 158, 11, 0.1); padding: 20px; border-radius: 10px; border: 1px solid rgba(245, 158, 11, 0.2);">
                                <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 10px;">
                                    <div style="background: var(--warning); color: white; width: 30px; height: 30px; border-radius: 50%; display: flex; align-items: center; justify-content: center;">
                                        <i class='bx bx-calendar'></i>
                                    </div>
                                    <h4 style="margin: 0; color: var(--text-color);">Step 2: Attendance</h4>
                                </div>
                                <p style="margin: 0; color: var(--text-color); font-size: 13px;">
                                    Attend the training on the scheduled date. Cancellation is not allowed once training starts.
                                </p>
                            </div>
                            
                            <div style="background: rgba(16, 185, 129, 0.1); padding: 20px; border-radius: 10px; border: 1px solid rgba(16, 185, 129, 0.2);">
                                <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 10px;">
                                    <div style="background: var(--success); color: white; width: 30px; height: 30px; border-radius: 50%; display: flex; align-items: center; justify-content: center;">
                                        <i class='bx bx-check'></i>
                                    </div>
                                    <h4 style="margin: 0; color: var(--text-color);">Step 3: Completion</h4>
                                </div>
                                <p style="margin: 0; color: var(--text-color); font-size: 13px;">
                                    After training ends, mark as completed. Employee will verify and submit to admin for certificate.
                                </p>
                            </div>
                            
                            <div style="background: rgba(139, 92, 246, 0.1); padding: 20px; border-radius: 10px; border: 1px solid rgba(139, 92, 246, 0.2);">
                                <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 10px;">
                                    <div style="background: var(--purple); color: white; width: 30px; height: 30px; border-radius: 50%; display: flex; align-items: center; justify-content: center;">
                                        <i class='bx bx-certificate'></i>
                                    </div>
                                    <h4 style="margin: 0; color: var(--text-color);">Step 4: Activation</h4>
                                </div>
                                <p style="margin: 0; color: var(--text-color); font-size: 13px;">
                                    After admin approves your first training, you'll become an Active Volunteer and can take shifts.
                                </p>
                            </div>
                        </div>
                        
                        <div style="margin-top: 20px; padding: 15px; background: rgba(220, 38, 38, 0.05); border-radius: 8px; border-left: 3px solid var(--primary-color);">
                            <h4 style="margin: 0 0 10px 0; color: var(--primary-color);">Automatic Status Updates:</h4>
                            <ul style="margin: 0; padding-left: 20px; color: var(--text-color); font-size: 13px;">
                                <li><strong>Scheduled:</strong> Training hasn't started yet (future date)</li>
                                <li><strong>Ongoing:</strong> Training start date has arrived (current date or later)</li>
                                <li><strong>Completed:</strong> Training end date has passed (past date)</li>
                                <li><strong>Registration:</strong> Only allowed for "Scheduled" and "Ongoing" trainings</li>
                                <li><strong>Cancellation:</strong> Only allowed before training start date</li>
                                <li><strong>Mark Completed:</strong> Only allowed after training end date has passed</li>
                                <li><strong>Status Updates:</strong> Automatically updated daily based on dates</li>
                                <li><strong>Auto-Completion:</strong> Training registrations are automatically marked as "completed" when training ends</li>
                            </ul>
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
            
            // Setup modals
            setupParticipantsModal();
            setupRegisterModal();
            setupCancelModal();
            setupCompleteModal();
            
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
        }
        
        function setupParticipantsModal() {
            const participantsModal = document.getElementById('participants-modal');
            const modalClose = document.getElementById('modal-close');
            const closeModal = document.getElementById('close-participants-modal');
            const viewButtons = document.querySelectorAll('.view-participants-btn');
            
            modalClose.addEventListener('click', () => participantsModal.classList.remove('active'));
            closeModal.addEventListener('click', () => participantsModal.classList.remove('active'));
            
            participantsModal.addEventListener('click', function(e) {
                if (e.target === this) {
                    participantsModal.classList.remove('active');
                }
            });
            
            viewButtons.forEach(button => {
                button.addEventListener('click', function() {
                    const trainingId = this.getAttribute('data-training-id');
                    const trainingTitle = this.getAttribute('data-training-title');
                    
                    document.getElementById('modal-title').textContent = 'Participants - ' + trainingTitle;
                    
                    // Load participants via AJAX
                    fetchParticipants(trainingId);
                    
                    participantsModal.classList.add('active');
                });
            });
        }
        
        function setupRegisterModal() {
            const registerModal = document.getElementById('register-modal');
            const modalClose = document.getElementById('register-modal-close');
            const closeModal = document.getElementById('close-register-modal');
            const registerButtons = document.querySelectorAll('.register-btn');
            
            modalClose.addEventListener('click', () => registerModal.classList.remove('active'));
            closeModal.addEventListener('click', () => registerModal.classList.remove('active'));
            
            registerModal.addEventListener('click', function(e) {
                if (e.target === this) {
                    registerModal.classList.remove('active');
                }
            });
            
            registerButtons.forEach(button => {
                button.addEventListener('click', function() {
                    const trainingId = this.getAttribute('data-training-id');
                    const trainingTitle = this.getAttribute('data-training-title');
                    const trainingDate = this.getAttribute('data-training-date');
                    const participants = this.getAttribute('data-participants');
                    
                    document.getElementById('register-training-id').value = trainingId;
                    document.getElementById('register-training-info').innerHTML = `
                        <strong>Training:</strong> ${trainingTitle}<br>
                        <strong>Date:</strong> ${trainingDate}<br>
                        <strong>Participants:</strong> ${participants}
                    `;
                    
                    document.getElementById('confirmation-text').textContent = 
                        `Are you sure you want to register for "${trainingTitle}" on ${trainingDate}?`;
                    
                    registerModal.classList.add('active');
                });
            });
        }
        
        function setupCancelModal() {
            const cancelModal = document.getElementById('cancel-modal');
            const modalClose = document.getElementById('cancel-modal-close');
            const closeModal = document.getElementById('close-cancel-modal');
            const cancelButtons = document.querySelectorAll('.cancel-btn');
            
            modalClose.addEventListener('click', () => cancelModal.classList.remove('active'));
            closeModal.addEventListener('click', () => cancelModal.classList.remove('active'));
            
            cancelModal.addEventListener('click', function(e) {
                if (e.target === this) {
                    cancelModal.classList.remove('active');
                }
            });
            
            cancelButtons.forEach(button => {
                button.addEventListener('click', function() {
                    const registrationId = this.getAttribute('data-registration-id');
                    const trainingTitle = this.getAttribute('data-training-title');
                    const trainingDate = this.getAttribute('data-training-date');
                    
                    document.getElementById('cancel-registration-id').value = registrationId;
                    document.getElementById('cancel-training-info').innerHTML = `
                        <strong>Training:</strong> ${trainingTitle}<br>
                        <strong>Date:</strong> ${trainingDate}
                    `;
                    
                    document.getElementById('cancel-confirmation-text').textContent = 
                        `Are you sure you want to cancel your registration for "${trainingTitle}" on ${trainingDate}?`;
                    
                    cancelModal.classList.add('active');
                });
            });
        }
        
        function setupCompleteModal() {
            const completeModal = document.getElementById('complete-modal');
            const modalClose = document.getElementById('complete-modal-close');
            const closeModal = document.getElementById('close-complete-modal');
            const completeButtons = document.querySelectorAll('.complete-btn');
            
            modalClose.addEventListener('click', () => completeModal.classList.remove('active'));
            closeModal.addEventListener('click', () => completeModal.classList.remove('active'));
            
            completeModal.addEventListener('click', function(e) {
                if (e.target === this) {
                    completeModal.classList.remove('active');
                }
            });
            
            completeButtons.forEach(button => {
                button.addEventListener('click', function() {
                    const registrationId = this.getAttribute('data-registration-id');
                    const trainingTitle = this.getAttribute('data-training-title');
                    const trainingDate = this.getAttribute('data-training-date');
                    
                    document.getElementById('complete-registration-id').value = registrationId;
                    document.getElementById('complete-training-info').innerHTML = `
                        <strong>Training:</strong> ${trainingTitle}<br>
                        <strong>Date:</strong> ${trainingDate}
                    `;
                    
                    document.getElementById('complete-confirmation-text').textContent = 
                        `Are you sure you have completed "${trainingTitle}"? This will notify an employee for verification.`;
                    
                    completeModal.classList.add('active');
                });
            });
        }
        
        function fetchParticipants(trainingId) {
            const contentDiv = document.getElementById('modal-participants-content');
            contentDiv.innerHTML = '<div style="text-align: center; padding: 40px;"><i class="bx bx-loader-alt bx-spin" style="font-size: 40px; color: var(--text-light);"></i><p>Loading participants...</p></div>';
            
            fetch('get_training_participants.php?training_id=' + trainingId)
                .then(response => response.text())
                .then(html => {
                    contentDiv.innerHTML = html;
                })
                .catch(error => {
                    contentDiv.innerHTML = '<div style="text-align: center; padding: 40px; color: var(--danger);"><i class="bx bx-error"></i><p>Error loading participants. Please try again.</p></div>';
                    console.error('Error:', error);
                });
        }
        
        function clearFilters() {
            const view = '<?php echo $view; ?>';
            window.location.href = '?view=' + view;
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
        
        function viewCertificate(registrationId) {
            // You'll need to implement this function based on how certificates are stored
            alert('Certificate viewing functionality will be implemented based on how certificates are stored in the system.');
            // Example: window.open('view_certificate.php?id=' + registrationId, '_blank');
        }
    </script>
</body>
</html>