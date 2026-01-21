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
if ($role !== 'ADMIN' && $role !== 'ADMIN') {
    header("Location: ../admin_dashboard.php");
    exit();
}

// Get all active volunteers
function getActiveVolunteers($pdo) {
    $sql = "SELECT 
                v.id,
                v.first_name,
                v.last_name,
                v.email,
                v.contact_number,
                v.available_days,
                v.available_hours,
                v.volunteer_status,
                v.user_id as volunteer_user_id,
                u.unit_name,
                u.unit_code,
                -- Get volunteer's skills
                v.skills_basic_firefighting,
                v.skills_first_aid_cpr,
                v.skills_search_rescue,
                v.skills_driving,
                v.skills_communication,
                v.skills_mechanical,
                v.skills_logistics
            FROM volunteers v
            LEFT JOIN volunteer_assignments va ON v.id = va.volunteer_id AND va.status = 'Active'
            LEFT JOIN units u ON va.unit_id = u.id
            WHERE v.status = 'approved' 
            AND v.volunteer_status IN ('Active', 'New Volunteer')
            ORDER BY v.last_name, v.first_name";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Get all units
function getUnits($pdo) {
    $sql = "SELECT id, unit_name, unit_code, unit_type, location FROM units WHERE status = 'Active' ORDER BY unit_name";
    $stmt = $pdo->query($sql);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Get duty assignment templates based on unit type
function getDutyTemplates($pdo, $unit_type = null) {
    $sql = "SELECT * FROM duty_templates WHERE 1=1";
    $params = [];
    
    if ($unit_type) {
        $sql .= " AND (applicable_units LIKE ? OR applicable_units IS NULL OR applicable_units = '')";
        $params[] = "%$unit_type%";
    }
    
    $sql .= " ORDER BY duty_type, priority";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Get existing shifts for a date range (to avoid conflicts)
function getExistingShifts($pdo, $start_date, $end_date) {
    $sql = "SELECT 
                s.id,
                s.shift_date,
                s.start_time,
                s.end_time,
                s.shift_type,
                s.status,
                s.volunteer_id,
                s.user_id,
                s.shift_for,
                s.duty_assignment_id,
                v.first_name as volunteer_first_name,
                v.last_name as volunteer_last_name,
                u.unit_name,
                u.unit_code,
                da.duty_type,
                da.duty_description
            FROM shifts s
            LEFT JOIN volunteers v ON s.volunteer_id = v.id
            LEFT JOIN units u ON s.unit_id = u.id
            LEFT JOIN duty_assignments da ON s.duty_assignment_id = da.id
            WHERE s.shift_date BETWEEN ? AND ?
            AND s.status != 'cancelled'
            ORDER BY s.shift_date, s.start_time";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$start_date, $end_date]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Create a duty assignment
function createDutyAssignment($pdo, $shift_id, $duty_data) {
    $sql = "INSERT INTO duty_assignments (
                shift_id,
                duty_type,
                duty_description,
                priority,
                required_equipment,
                required_training,
                notes
            ) VALUES (?, ?, ?, ?, ?, ?, ?)";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        $shift_id,
        $duty_data['duty_type'],
        $duty_data['duty_description'],
        $duty_data['priority'] ?? 'primary',
        $duty_data['required_equipment'] ?? null,
        $duty_data['required_training'] ?? null,
        $duty_data['notes'] ?? null
    ]);
    
    return $pdo->lastInsertId();
}

// Process form submission
$success_message = '';
$error_message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $pdo->beginTransaction();
        
        // CRITICAL FIX: Make sure shift_type is always set
        $shift_type = isset($_POST['shift_type']) ? $_POST['shift_type'] : 'custom';
        $unit_id = $_POST['unit_id'];
        $location = $_POST['location'] ?? 'Main Station';
        $notes = $_POST['notes'] ?? '';
        $created_by = $user_id;
        
        // Handle duty assignment data
        $duty_assignment_data = null;
        if (isset($_POST['duty_type']) && !empty($_POST['duty_type'])) {
            $duty_assignment_data = [
                'duty_type' => $_POST['duty_type'],
                'duty_description' => $_POST['duty_description'] ?? '',
                'priority' => $_POST['duty_priority'] ?? 'primary',
                'required_equipment' => $_POST['required_equipment'] ?? null,
                'required_training' => $_POST['required_training'] ?? null,
                'notes' => $_POST['duty_notes'] ?? null
            ];
        }
        
        // Handle single shift creation
        if (isset($_POST['create_single_shift'])) {
            $shift_date = $_POST['shift_date'];
            $start_time = $_POST['start_time'];
            $end_time = $_POST['end_time'];
            $volunteer_id = $_POST['volunteer_id'] ?? null;
            
            // FIX: Make volunteer_id required
            if (empty($volunteer_id)) {
                throw new Exception("Volunteer selection is required. Please select a volunteer.");
            }
            
            $sql = "INSERT INTO shifts (
                user_id, volunteer_id, shift_for, unit_id,
                shift_date, shift_type, start_time, end_time,
                status, confirmation_status, location, notes, created_by, created_at, updated_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'scheduled', 'pending', ?, ?, ?, NOW(), NOW())";
            
            // For volunteer shift, try to get their user_id if they have one
            $volunteer_query = "SELECT user_id FROM volunteers WHERE id = ?";
            $volunteer_stmt = $pdo->prepare($volunteer_query);
            $volunteer_stmt->execute([$volunteer_id]);
            $volunteer_data = $volunteer_stmt->fetch();
            
            $user_id_value = $volunteer_data['user_id'] ?? null;
            $shift_for = 'volunteer';
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                $user_id_value,
                $volunteer_id,
                $shift_for,
                $unit_id,
                $shift_date,
                $shift_type,
                $start_time,
                $end_time,
                $location,
                $notes,
                $created_by
            ]);
            
            $shift_id = $pdo->lastInsertId();
            
            // Create duty assignment if provided
            if ($duty_assignment_data) {
                $duty_assignment_id = createDutyAssignment($pdo, $shift_id, $duty_assignment_data);
                
                // Update shift with duty assignment ID
                $update_sql = "UPDATE shifts SET duty_assignment_id = ? WHERE id = ?";
                $update_stmt = $pdo->prepare($update_sql);
                $update_stmt->execute([$duty_assignment_id, $shift_id]);
            }
            
            // Send notification to volunteer
            $notification_query = "INSERT INTO notifications (user_id, type, title, message, is_read, created_at)
                                  VALUES (?, 'new_shift', 'New Shift Assigned', 'You have been assigned a new shift on " . date('M d, Y', strtotime($shift_date)) . " from " . date('g:i A', strtotime($start_time)) . " to " . date('g:i A', strtotime($end_time)) . ". Please confirm your availability.', 0, NOW())";
            $notification_stmt = $pdo->prepare($notification_query);
            $notification_stmt->execute([$user_id_value]);
            
            $success_message = "Shift created successfully!";
        }
        
        // Handle recurring shifts
        elseif (isset($_POST['create_recurring_shifts'])) {
            $start_date = $_POST['start_date'];
            $end_date = $_POST['end_date'];
            $recurrence_days = $_POST['recurrence_days'] ?? [];
            $shift_time = $_POST['shift_time'];
            $duration_hours = $_POST['duration_hours'];
            $volunteer_ids = $_POST['volunteer_ids'] ?? [];
            
            // FIX: Make volunteer_ids required
            if (empty($volunteer_ids)) {
                throw new Exception("At least one volunteer must be selected for recurring shifts.");
            }
            
            // Parse shift time
            list($start_hour, $start_minute) = explode(':', $shift_time);
            $start_time = "$start_hour:$start_minute:00";
            
            // Calculate end time based on duration
            $end_time_obj = new DateTime($start_time);
            $end_time_obj->modify("+$duration_hours hours");
            $end_time = $end_time_obj->format('H:i:s');
            
            // Create shifts for each day in the range
            $start = new DateTime($start_date);
            $end = new DateTime($end_date);
            $interval = new DateInterval('P1D');
            $period = new DatePeriod($start, $interval, $end->modify('+1 day'));
            
            $shifts_created = 0;
            $days_of_week = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
            
            foreach ($period as $date) {
                $day_name = $days_of_week[$date->format('w')];
                
                // Check if this day should have a shift
                if (in_array($day_name, $recurrence_days)) {
                    $shift_date = $date->format('Y-m-d');
                    
                    // Assign volunteers in rotation
                    $volunteer_index = ($shifts_created % count($volunteer_ids));
                    $volunteer_id = $volunteer_ids[$volunteer_index];
                    $shift_for = 'volunteer';
                    
                    // Get volunteer's user_id if exists
                    $volunteer_query = "SELECT user_id FROM volunteers WHERE id = ?";
                    $volunteer_stmt = $pdo->prepare($volunteer_query);
                    $volunteer_stmt->execute([$volunteer_id]);
                    $volunteer_data = $volunteer_stmt->fetch();
                    
                    $user_id_value = $volunteer_data['user_id'] ?? null;
                    
                    $sql = "INSERT INTO shifts (
                        user_id, volunteer_id, shift_for, unit_id,
                        shift_date, shift_type, start_time, end_time,
                        status, confirmation_status, location, notes, created_by, created_at, updated_at
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'scheduled', 'pending', ?, ?, ?, NOW(), NOW())";
                    
                    $stmt = $pdo->prepare($sql);
                    $stmt->execute([
                        $user_id_value,
                        $volunteer_id,
                        $shift_for,
                        $unit_id,
                        $shift_date,
                        $shift_type,
                        $start_time,
                        $end_time,
                        $location,
                        $notes,
                        $created_by
                    ]);
                    
                    $shift_id = $pdo->lastInsertId();
                    
                    // Create duty assignment if provided
                    if ($duty_assignment_data) {
                        $duty_assignment_id = createDutyAssignment($pdo, $shift_id, $duty_assignment_data);
                        
                        // Update shift with duty assignment ID
                        $update_sql = "UPDATE shifts SET duty_assignment_id = ? WHERE id = ?";
                        $update_stmt = $pdo->prepare($update_sql);
                        $update_stmt->execute([$duty_assignment_id, $shift_id]);
                    }
                    
                    // Send notification if volunteer has an account
                    if ($user_id_value) {
                        $notification_query = "INSERT INTO notifications (user_id, type, title, message, is_read, created_at)
                                              VALUES (?, 'new_shift', 'New Shift Assigned', 'You have been assigned a new recurring shift on " . date('M d, Y', strtotime($shift_date)) . " from " . date('g:i A', strtotime($start_time)) . " to " . date('g:i A', strtotime($end_time)) . ". Please confirm your availability.', 0, NOW())";
                        $notification_stmt = $pdo->prepare($notification_query);
                        $notification_stmt->execute([$user_id_value]);
                    }
                    
                    $shifts_created++;
                }
            }
            
            $success_message = "Created $shifts_created recurring shifts successfully!";
        }
        
        // Handle bulk shift creation for multiple volunteers on same day
        elseif (isset($_POST['create_bulk_shifts'])) {
            $shift_date = $_POST['bulk_shift_date'];
            $start_time = $_POST['bulk_start_time'];
            $end_time = $_POST['bulk_end_time'];
            $volunteer_ids = $_POST['bulk_volunteer_ids'] ?? [];
            
            // FIX: Make volunteer_ids required
            if (empty($volunteer_ids)) {
                throw new Exception("At least one volunteer must be selected for bulk shifts.");
            }
            
            $shifts_created = 0;
            
            foreach ($volunteer_ids as $volunteer_id) {
                // Get volunteer's user_id if exists
                $volunteer_query = "SELECT user_id FROM volunteers WHERE id = ?";
                $volunteer_stmt = $pdo->prepare($volunteer_query);
                $volunteer_stmt->execute([$volunteer_id]);
                $volunteer_data = $volunteer_stmt->fetch();
                
                $user_id_value = $volunteer_data['user_id'] ?? null;
                
                $sql = "INSERT INTO shifts (
                    user_id, volunteer_id, shift_for, unit_id,
                    shift_date, shift_type, start_time, end_time,
                    status, confirmation_status, location, notes, created_by, created_at, updated_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'scheduled', 'pending', ?, ?, ?, NOW(), NOW())";
                
                $stmt = $pdo->prepare($sql);
                $stmt->execute([
                    $user_id_value,
                    $volunteer_id,
                    'volunteer',
                    $unit_id,
                    $shift_date,
                    $shift_type,
                    $start_time,
                    $end_time,
                    $location,
                    $notes,
                    $created_by
                ]);
                
                $shift_id = $pdo->lastInsertId();
                
                // Create duty assignment if provided
                if ($duty_assignment_data) {
                    $duty_assignment_id = createDutyAssignment($pdo, $shift_id, $duty_assignment_data);
                    
                    // Update shift with duty assignment ID
                    $update_sql = "UPDATE shifts SET duty_assignment_id = ? WHERE id = ?";
                    $update_stmt = $pdo->prepare($update_sql);
                    $update_stmt->execute([$duty_assignment_id, $shift_id]);
                }
                
                // Send notification if volunteer has an account
                if ($user_id_value) {
                    $notification_query = "INSERT INTO notifications (user_id, type, title, message, is_read, created_at)
                                          VALUES (?, 'new_shift', 'New Shift Assigned', 'You have been assigned a new shift on " . date('M d, Y', strtotime($shift_date)) . " from " . date('g:i A', strtotime($start_time)) . " to " . date('g:i A', strtotime($end_time)) . ". Please confirm your availability.', 0, NOW())";
                    $notification_stmt = $pdo->prepare($notification_query);
                    $notification_stmt->execute([$user_id_value]);
                }
                
                $shifts_created++;
            }
            
            $success_message = "Created $shifts_created shifts for multiple volunteers successfully!";
        }
        
        $pdo->commit();
        
    } catch (Exception $e) {
        $pdo->rollBack();
        $error_message = "Error creating shifts: " . $e->getMessage();
        // For debugging
        error_log("Shift creation error: " . $e->getMessage());
        error_log("POST data: " . print_r($_POST, true));
    }
}

// Get data for display
$volunteers = getActiveVolunteers($pdo);
$units = getUnits($pdo);

// Get existing shifts for the next 30 days
$today = date('Y-m-d');
$next_month = date('Y-m-d', strtotime('+30 days'));
$existing_shifts = getExistingShifts($pdo, $today, $next_month);

// Group existing shifts by date for calendar view
$shifts_by_date = [];
foreach ($existing_shifts as $shift) {
    $date = $shift['shift_date'];
    if (!isset($shifts_by_date[$date])) {
        $shifts_by_date[$date] = [];
    }
    $shifts_by_date[$date][] = $shift;
}

// Shift type options
$shift_types = [
    'morning' => 'Morning Shift (6AM-2PM)',
    'afternoon' => 'Afternoon Shift (2PM-10PM)',
    'evening' => 'Evening Shift (6PM-2AM)',
    'night' => 'Night Shift (10PM-6AM)',
    'full_day' => 'Full Day (8AM-5PM)',
    'custom' => 'Custom Hours'
];

// Duty type options
$duty_types = [
    'fire_suppression' => 'Fire Suppression',
    'rescue_operations' => 'Rescue Operations',
    'emergency_medical' => 'Emergency Medical',
    'hazardous_materials' => 'Hazardous Materials',
    'technical_rescue' => 'Technical Rescue',
    'water_rescue' => 'Water Rescue',
    'command_post' => 'Command Post',
    'logistics_support' => 'Logistics Support',
    'equipment_management' => 'Equipment Management',
    'communications' => 'Communications',
    'first_aid_station' => 'First Aid Station',
    'crowd_control' => 'Crowd Control',
    'investigation' => 'Investigation',
    'salvage_overhaul' => 'Salvage & Overhaul',
    'rehabilitation' => 'Rehabilitation'
];

// Priority options
$priority_options = [
    'primary' => 'Primary Duty',
    'secondary' => 'Secondary Duty',
    'support' => 'Support Role'
];

// Days of week for recurrence
$days_of_week = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];

// Default shift times by type
$default_times = [
    'morning' => ['06:00', '14:00'],
    'afternoon' => ['14:00', '22:00'],
    'evening' => ['18:00', '02:00'],
    'night' => ['22:00', '06:00'],
    'full_day' => ['08:00', '17:00']
];

// Default duty descriptions based on duty type
$default_duty_descriptions = [
    'fire_suppression' => 'Primary firefighting duties including hose line operations, water supply, ventilation, and search & rescue in fire conditions.',
    'rescue_operations' => 'Search and rescue operations including victim location, extrication, and technical rescue scenarios.',
    'emergency_medical' => 'Provide emergency medical care including patient assessment, basic life support, and stabilization until EMS arrival.',
    'hazardous_materials' => 'Identify, contain, and mitigate hazardous materials incidents following proper protocols and safety procedures.',
    'technical_rescue' => 'Specialized rescue operations including high-angle, confined space, trench, and structural collapse rescue.',
    'water_rescue' => 'Water rescue operations including shore-based rescue, boat operations, and swift water rescue techniques.',
    'command_post' => 'Assist with incident command system operations including communications, resource tracking, and documentation.',
    'logistics_support' => 'Manage and distribute equipment, supplies, and resources to support ongoing operations.',
    'equipment_management' => 'Maintain, inventory, and deploy specialized equipment and tools for emergency operations.',
    'communications' => 'Operate radio communications, maintain communication logs, and ensure proper information flow.',
    'first_aid_station' => 'Operate rehabilitation station providing medical monitoring, hydration, and rest for personnel.',
    'crowd_control' => 'Maintain scene safety by controlling access, managing bystanders, and ensuring perimeter security.',
    'investigation' => 'Assist with post-incident investigation including evidence preservation and documentation.',
    'salvage_overhaul' => 'Perform salvage operations to protect property and overhaul to ensure complete extinguishment.',
    'rehabilitation' => 'Monitor personnel for signs of exhaustion, provide hydration and nutrition, and ensure crew readiness.'
];

// Default required training based on duty type
$default_required_training = [
    'fire_suppression' => 'Basic Firefighter Training, SCBA Certification, Hose & Ladder Operations',
    'rescue_operations' => 'Technical Rescue Training, Rope Rescue Certification, Confined Space Awareness',
    'emergency_medical' => 'First Aid/CPR Certification, Emergency Medical Responder, Bloodborne Pathogens',
    'hazardous_materials' => 'HazMat Awareness/Operations, Decontamination Procedures',
    'technical_rescue' => 'Advanced Technical Rescue Certification, Rope Systems, Patient Packaging',
    'water_rescue' => 'Water Rescue Certification, Swift Water Training, Boat Operations',
    'command_post' => 'ICS Training, Resource Management, Communications Protocols',
    'logistics_support' => 'Inventory Management, Supply Chain Operations',
    'equipment_management' => 'Equipment Maintenance, Tool Operations, Inventory Control',
    'communications' => 'Radio Communications, Incident Reporting, Documentation',
    'first_aid_station' => 'First Aid/CPR, Vital Signs Monitoring, Medical Documentation',
    'crowd_control' => 'Crowd Management, Scene Safety, Traffic Control',
    'investigation' => 'Fire Investigation Basics, Evidence Preservation, Documentation',
    'salvage_overhaul' => 'Salvage Operations, Overhaul Techniques, Property Conservation',
    'rehabilitation' => 'Rehab Operations, Medical Monitoring, Crew Resource Management'
];

$stmt = null;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Create Shift Schedule - Fire & Rescue Management</title>
    <link href='https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css' rel='stylesheet'>
    <link rel="icon" type="image/png" sizes="32x32" href="../../img/frsm-logo.png">
    <link rel="stylesheet" href="../../css/dashboard.css">
    <style>

          /* ... (keep all your existing CSS styles) ... */
        /* Add these new styles for duty assignments */
        
        .duty-assignment-section {
            margin-top: 30px;
            padding: 20px;
            background: rgba(59, 130, 246, 0.05);
            border-radius: 12px;
            border: 1px solid rgba(59, 130, 246, 0.2);
        }
        
        .duty-type-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
            gap: 10px;
            margin-top: 10px;
        }
        
        .duty-type-btn {
            padding: 12px;
            border: 2px solid var(--border-color);
            border-radius: 8px;
            background: var(--card-bg);
            cursor: pointer;
            transition: all 0.3s ease;
            text-align: center;
        }
        
        .duty-type-btn:hover {
            border-color: var(--info);
            background: rgba(59, 130, 246, 0.1);
        }
        
        .duty-type-btn.selected {
            border-color: var(--info);
            background: rgba(59, 130, 246, 0.2);
            font-weight: 600;
        }
        
        .duty-type-icon {
            font-size: 24px;
            margin-bottom: 8px;
            display: block;
            color: var(--info);
        }
        
        .equipment-checklist {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
            gap: 10px;
            margin-top: 10px;
        }
        
        .equipment-item {
            padding: 10px;
            border: 1px solid var(--border-color);
            border-radius: 6px;
            background: var(--card-bg);
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .skill-match-indicator {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 2px 8px;
            border-radius: 12px;
            font-size: 11px;
            margin-left: 8px;
        }
        
        .skill-match-good {
            background: rgba(16, 185, 129, 0.2);
            color: var(--success);
        }
        
        .skill-match-partial {
            background: rgba(245, 158, 11, 0.2);
            color: var(--warning);
        }
        
        .skill-match-none {
            background: rgba(220, 38, 38, 0.2);
            color: var(--danger);
        }
        
        .duty-preview {
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: 8px;
            padding: 15px;
            margin-top: 15px;
        }
        
        .duty-preview h4 {
            margin-top: 0;
            color: var(--primary-color);
        }
        
        .required-skills {
            display: flex;
            flex-wrap: wrap;
            gap: 5px;
            margin-top: 10px;
        }
        
        .skill-tag {
            padding: 4px 8px;
            background: rgba(59, 130, 246, 0.1);
            border-radius: 12px;
            font-size: 11px;
            color: var(--info);
        }
        
        /* Add to existing styles */
        .volunteer-skills {
            display: flex;
            flex-wrap: wrap;
            gap: 4px;
            margin-top: 5px;
        }
        
        .volunteer-skill {
            padding: 2px 6px;
            background: rgba(16, 185, 129, 0.1);
            border-radius: 10px;
            font-size: 10px;
            color: var(--success);
        }
        /* The CSS remains exactly the same as your original code */
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

        .tabs-container {
            display: flex;
            gap: 8px;
            margin-bottom: 30px;
            border-bottom: 2px solid var(--border-color);
        }

        .tab-button {
            padding: 12px 24px;
            background: none;
            border: none;
            border-bottom: 3px solid transparent;
            color: var(--text-light);
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 15px;
        }

        .tab-button.active {
            color: var(--primary-color);
            border-bottom-color: var(--primary-color);
        }

        .tab-button:hover:not(.active) {
            color: var(--text-color);
        }

        .tab-content {
            display: none;
        }

        .tab-content.active {
            display: block;
            animation: fadeIn 0.3s ease;
        }

        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .form-container {
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: 16px;
            padding: 30px;
            margin-bottom: 30px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
        }

        .form-section {
            margin-bottom: 30px;
        }

        .form-section:last-child {
            margin-bottom: 0;
        }

        .form-section-title {
            font-size: 18px;
            font-weight: 600;
            color: var(--text-color);
            margin-bottom: 20px;
            padding-bottom: 12px;
            border-bottom: 2px solid var(--border-color);
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .form-section-title i {
            color: var(--primary-color);
        }

        .form-row {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-bottom: 20px;
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

        .form-label.required::after {
            content: ' *';
            color: var(--danger);
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

        .form-textarea {
            min-height: 100px;
            resize: vertical;
        }

        .checkbox-group {
            display: flex;
            flex-wrap: wrap;
            gap: 15px;
            margin-top: 10px;
        }

        .checkbox-item {
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .checkbox-item input[type="checkbox"] {
            width: 18px;
            height: 18px;
            cursor: pointer;
        }

        .checkbox-item label {
            cursor: pointer;
            user-select: none;
        }

        .volunteer-select-container {
            max-height: 300px;
            overflow-y: auto;
            border: 1px solid var(--border-color);
            border-radius: 10px;
            padding: 15px;
            background: var(--card-bg);
        }

        .volunteer-item {
            padding: 12px;
            border: 1px solid var(--border-color);
            border-radius: 8px;
            margin-bottom: 10px;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .volunteer-item:hover {
            border-color: var(--primary-color);
            background: rgba(220, 38, 38, 0.05);
        }

        .volunteer-item.selected {
            border-color: var(--primary-color);
            background: rgba(220, 38, 38, 0.1);
        }

        .volunteer-info {
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .volunteer-name {
            font-weight: 600;
            color: var(--text-color);
        }

        .volunteer-details {
            font-size: 12px;
            color: var(--text-light);
        }

        .availability-badge {
            display: inline-block;
            padding: 4px 8px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 600;
            margin-right: 5px;
            margin-bottom: 5px;
            background: var(--gray-200);
            color: var(--gray-700);
        }

        .availability-badge.available {
            background: rgba(16, 185, 129, 0.1);
            color: var(--success);
        }

        .time-inputs {
            display: flex;
            gap: 15px;
            align-items: center;
        }

        .time-input-group {
            flex: 1;
        }

        .duration-input {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .duration-input input {
            width: 80px;
            text-align: center;
        }

        .duration-label {
            font-size: 14px;
            color: var(--text-light);
        }

        .form-actions {
            display: flex;
            gap: 15px;
            justify-content: flex-end;
            margin-top: 30px;
            padding-top: 20px;
            border-top: 1px solid var(--border-color);
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

        .btn-success {
            background: linear-gradient(135deg, var(--success), #0da271);
            color: white;
        }

        .btn-success:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(16, 185, 129, 0.3);
        }

        .calendar-container {
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: 16px;
            padding: 30px;
            margin-top: 30px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
        }

        .calendar-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }

        .calendar-title {
            font-size: 20px;
            font-weight: 700;
            color: var(--text-color);
        }

        .calendar-nav {
            display: flex;
            gap: 10px;
        }

        .calendar-nav-btn {
            padding: 8px 12px;
            border-radius: 8px;
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            color: var(--text-color);
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .calendar-nav-btn:hover {
            background: var(--gray-100);
            border-color: var(--primary-color);
        }

        .calendar-grid {
            display: grid;
            grid-template-columns: repeat(7, 1fr);
            gap: 1px;
            background: var(--border-color);
            border: 1px solid var(--border-color);
            border-radius: 8px;
            overflow: hidden;
        }

        .calendar-day-header {
            background: rgba(220, 38, 38, 0.1);
            padding: 12px;
            text-align: center;
            font-weight: 600;
            color: var(--text-color);
            border-bottom: 1px solid var(--border-color);
        }

        .calendar-day {
            background: var(--card-bg);
            min-height: 120px;
            padding: 10px;
            border: 1px solid var(--border-color);
            transition: all 0.3s ease;
        }

        .calendar-day:hover {
            background: var(--gray-100);
        }

        .calendar-day.today {
            background: rgba(220, 38, 38, 0.05);
            border: 2px solid var(--primary-color);
        }

        .calendar-day.other-month {
            background: var(--gray-100);
            color: var(--text-light);
        }

        .dark-mode .calendar-day.other-month {
            background: var(--gray-800);
        }

        .day-number {
            font-weight: 600;
            margin-bottom: 8px;
            color: var(--text-color);
        }

        .shift-item {
            background: rgba(59, 130, 246, 0.1);
            border-left: 3px solid var(--info);
            padding: 6px 8px;
            margin-bottom: 5px;
            border-radius: 4px;
            font-size: 11px;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .shift-item:hover {
            background: rgba(59, 130, 246, 0.2);
        }

        .shift-item.volunteer {
            background: rgba(16, 185, 129, 0.1);
            border-left-color: var(--success);
        }

        .shift-item.volunteer:hover {
            background: rgba(16, 185, 129, 0.2);
        }

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

        .shifts-list {
            max-height: 400px;
            overflow-y: auto;
        }

        .shift-detail-item {
            padding: 15px;
            border: 1px solid var(--border-color);
            border-radius: 8px;
            margin-bottom: 10px;
            background: var(--card-bg);
        }

        .shift-detail-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 10px;
        }

        .shift-time {
            font-weight: 600;
            color: var(--primary-color);
        }

        .shift-assigned {
            font-size: 13px;
            color: var(--text-light);
        }

        .shift-unit {
            font-size: 12px;
            color: var(--text-light);
            margin-top: 5px;
        }

        .no-shifts {
            text-align: center;
            padding: 40px;
            color: var(--text-light);
        }

        .no-shifts i {
            font-size: 48px;
            margin-bottom: 16px;
            opacity: 0.5;
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
            
            .form-row {
                grid-template-columns: 1fr;
                gap: 15px;
            }
            
            .time-inputs {
                flex-direction: column;
                gap: 15px;
            }
            
            .time-input-group {
                width: 100%;
            }
            
            .calendar-grid {
                grid-template-columns: repeat(1, 1fr);
            }
            
            .calendar-day {
                min-height: auto;
            }
            
            .tabs-container {
                flex-direction: column;
            }
            
            .form-actions {
                flex-direction: column;
            }
            
            .btn {
                width: 100%;
                justify-content: center;
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
            
            .form-container {
                padding: 20px;
            }
            
            .calendar-container {
                padding: 20px;
            }
        }

        .help-text {
            font-size: 12px;
            color: var(--text-light);
            margin-top: 4px;
            margin-bottom: 0;
        }

        .badge {
            display: inline-block;
            padding: 4px 8px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 600;
            margin-left: 8px;
        }

        .badge-success {
            background: rgba(16, 185, 129, 0.1);
            color: var(--success);
        }

        .badge-warning {
            background: rgba(245, 158, 11, 0.1);
            color: var(--warning);
        }

        .badge-info {
            background: rgba(59, 130, 246, 0.1);
            color: var(--info);
        }
        
        .account-badge {
            font-size: 10px;
            padding: 2px 6px;
            margin-left: 5px;
        }
        
        .account-badge.has-account {
            background: rgba(16, 185, 129, 0.2);
            color: var(--success);
        }
        
        .account-badge.no-account {
            background: rgba(245, 158, 11, 0.2);
            color: var(--warning);
        }
        
        .error-field {
            border-color: var(--danger) !important;
            box-shadow: 0 0 0 3px rgba(220, 38, 38, 0.1) !important;
        }
        
        .error-message {
            color: var(--danger);
            font-size: 12px;
            margin-top: 4px;
            display: none;
        }
   </style>
</head>
<body>
    <!-- Calendar Modal -->
    <div class="modal-overlay" id="calendar-modal">
        <div class="modal">
            <div class="modal-header">
                <h2 class="modal-title" id="modal-date-title">Shifts for </h2>
                <button class="modal-close" id="calendar-modal-close">&times;</button>
            </div>
            <div class="modal-body">
                <div id="shifts-list-content">
                    <!-- Shifts will be loaded here -->
                </div>
            </div>
            <div class="modal-actions">
                <button type="button" class="btn btn-secondary" id="close-calendar-modal">Close</button>
            </div>
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
        <!-- Sidebar (same as your existing sidebar) -->
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
                    <div class="menu-item active" onclick="toggleSubmenu('schedule-management')">
                        <div class="icon-box icon-bg-purple">
                            <i class='bx bxs-calendar icon-purple'></i>
                        </div>
                        <span class="font-medium">Schedule Management</span>
                        <svg class="dropdown-arrow menu-icon rotated" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                        </svg>
                    </div>
                    <div id="schedule-management" class="submenu active">
                       <a href="view_shifts.php" class="submenu-item">View Shifts</a>
                        <a href="create_schedule.php" class="submenu-item active">Create Schedule</a>
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
                        <svg class="dropdown-arrow menu-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                        </svg>
                    </div>
                    <div id="training-management" class="submenu">
                        <a href="#" class="submenu-item">View Records</a>
                        <a href="#" class="submenu-item">Approve Completions</a>
                        <a href="#" class="submenu-item">Assign Training</a>
                        <a href="#" class="submenu-item">Track Expiry</a>
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
                            <input type="text" placeholder="Search volunteers..." class="search-input" id="search-input">
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
                        <h1 class="dashboard-title">Create Shift Schedule</h1>
                        <p class="dashboard-subtitle">Create and manage shift schedules with duty assignments for volunteers and employees. Total Active Volunteers: <?php echo count($volunteers); ?></p>
                    </div>
                </div>
                
                <!-- Content Container -->
                <div class="content-container">
                    <!-- Tabs Navigation -->
                    <div class="tabs-container">
                        <button class="tab-button active" data-tab="single-shift">
                            <i class='bx bx-plus-circle'></i>
                            Single Shift
                        </button>
                        <button class="tab-button" data-tab="recurring-shifts">
                            <i class='bx bx-calendar-plus'></i>
                            Recurring Shifts
                        </button>
                        <button class="tab-button" data-tab="bulk-shifts">
                            <i class='bx bx-user-plus'></i>
                            Bulk Assignments
                        </button>
                        <button class="tab-button" data-tab="view-calendar">
                            <i class='bx bx-calendar'></i>
                            View Calendar
                        </button>
                    </div>
                    
                    <!-- Single Shift Tab -->
                    <div class="tab-content active" id="single-shift">
                        <form method="POST" class="form-container" id="single-shift-form">
                            <div class="form-section">
                                <h3 class="form-section-title">
                                    <i class='bx bx-time'></i>
                                    Shift Details
                                </h3>
                                
                                <div class="form-row">
                                    <div class="form-group">
                                        <label class="form-label required" for="shift_type">Shift Type</label>
                                        <select class="form-select" id="shift_type" name="shift_type" required>
                                            <option value="">Select shift type</option>
                                            <?php foreach ($shift_types as $value => $label): ?>
                                                <option value="<?php echo $value; ?>" <?php echo isset($_POST['shift_type']) && $_POST['shift_type'] == $value ? 'selected' : ''; ?>>
                                                    <?php echo $label; ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                        <div class="error-message" id="shift_type_error">Please select a shift type</div>
                                    </div>
                                    
                                    <div class="form-group">
                                        <label class="form-label required" for="unit_id">Assigned Unit</label>
                                        <select class="form-select" id="unit_id" name="unit_id" required>
                                            <option value="">Select unit</option>
                                            <?php foreach ($units as $unit): ?>
                                                <option value="<?php echo $unit['id']; ?>" data-unit-type="<?php echo htmlspecialchars($unit['unit_type']); ?>" <?php echo isset($_POST['unit_id']) && $_POST['unit_id'] == $unit['id'] ? 'selected' : ''; ?>>
                                                    <?php echo htmlspecialchars($unit['unit_name']); ?> (<?php echo htmlspecialchars($unit['unit_code']); ?>)
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                        <div class="error-message" id="unit_id_error">Please select a unit</div>
                                    </div>
                                </div>
                                
                                <div class="form-row">
                                    <div class="form-group">
                                        <label class="form-label required" for="shift_date">Shift Date</label>
                                        <input type="date" class="form-input" id="shift_date" name="shift_date" 
                                               value="<?php echo isset($_POST['shift_date']) ? $_POST['shift_date'] : date('Y-m-d'); ?>" 
                                               min="<?php echo date('Y-m-d'); ?>" required>
                                        <div class="error-message" id="shift_date_error">Please select a valid date</div>
                                    </div>
                                    
                                    <div class="form-group">
                                        <label class="form-label required" for="volunteer_id">Assign to Volunteer</label>
                                        <select class="form-select" id="volunteer_id" name="volunteer_id" required onchange="checkVolunteerSkills(this)">
                                            <option value="">Select volunteer (required)</option>
                                            <?php foreach ($volunteers as $volunteer): 
                                                $skills = [];
                                                if ($volunteer['skills_basic_firefighting']) $skills[] = 'Firefighting';
                                                if ($volunteer['skills_first_aid_cpr']) $skills[] = 'First Aid/CPR';
                                                if ($volunteer['skills_search_rescue']) $skills[] = 'Search & Rescue';
                                                if ($volunteer['skills_driving']) $skills[] = 'Driving';
                                                if ($volunteer['skills_communication']) $skills[] = 'Communication';
                                                if ($volunteer['skills_mechanical']) $skills[] = 'Mechanical';
                                                if ($volunteer['skills_logistics']) $skills[] = 'Logistics';
                                            ?>
                                                <option value="<?php echo $volunteer['id']; ?>" 
                                                        data-skills="<?php echo htmlspecialchars(json_encode($skills)); ?>"
                                                        <?php echo isset($_POST['volunteer_id']) && $_POST['volunteer_id'] == $volunteer['id'] ? 'selected' : ''; ?>>
                                                    <?php echo htmlspecialchars($volunteer['first_name'] . ' ' . $volunteer['last_name']); ?>
                                                    <?php if ($volunteer['unit_name']): ?>
                                                        (<?php echo htmlspecialchars($volunteer['unit_name']); ?>)
                                                    <?php endif; ?>
                                                    <?php if ($volunteer['volunteer_user_id']): ?>
                                                        <span class="account-badge has-account">Has Account</span>
                                                    <?php else: ?>
                                                        <span class="account-badge no-account">No Account</span>
                                                    <?php endif; ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                        <div class="error-message" id="volunteer_id_error">Please select a volunteer</div>
                                        <div id="volunteer-skills-display" class="volunteer-skills" style="display: none;"></div>
                                        <p class="help-text">Volunteers with accounts can log attendance online and will see shifts in their availability confirmation page.</p>
                                    </div>
                                </div>
                                
                                <div class="form-row">
                                    <div class="form-group">
                                        <label class="form-label required" for="start_time">Start Time</label>
                                        <input type="time" class="form-input" id="start_time" name="start_time" 
                                               value="<?php echo isset($_POST['start_time']) ? $_POST['start_time'] : '08:00'; ?>" required>
                                        <div class="error-message" id="start_time_error">Please select a start time</div>
                                    </div>
                                    
                                    <div class="form-group">
                                        <label class="form-label required" for="end_time">End Time</label>
                                        <input type="time" class="form-input" id="end_time" name="end_time" 
                                               value="<?php echo isset($_POST['end_time']) ? $_POST['end_time'] : '17:00'; ?>" required>
                                        <div class="error-message" id="end_time_error">Please select an end time</div>
                                    </div>
                                </div>
                                
                                <div class="form-row">
                                    <div class="form-group">
                                        <label class="form-label" for="location">Location</label>
                                        <input type="text" class="form-input" id="location" name="location" 
                                               placeholder="e.g., Main Station, Firehouse #1" 
                                               value="<?php echo isset($_POST['location']) ? $_POST['location'] : 'Main Station'; ?>">
                                    </div>
                                    
                                    <div class="form-group">
                                        <label class="form-label" for="notes">General Notes</label>
                                        <textarea class="form-textarea" id="notes" name="notes" 
                                                  placeholder="Any special instructions or notes..."><?php echo isset($_POST['notes']) ? $_POST['notes'] : ''; ?></textarea>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Duty Assignment Section -->
                            <div class="duty-assignment-section">
                                <h3 class="form-section-title">
                                    <i class='bx bx-task'></i>
                                    Duty Assignment
                                </h3>
                                
                                <div class="form-row">
                                    <div class="form-group">
                                        <label class="form-label" for="duty_type">Duty Type</label>
                                        <select class="form-select" id="duty_type" name="duty_type" onchange="updateDutyDescription(this)">
                                            <option value="">Select duty type (optional)</option>
                                            <?php foreach ($duty_types as $value => $label): ?>
                                                <option value="<?php echo $value; ?>" <?php echo isset($_POST['duty_type']) && $_POST['duty_type'] == $value ? 'selected' : ''; ?>>
                                                    <?php echo $label; ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                        <p class="help-text">Select the primary duty for this shift</p>
                                    </div>
                                    
                                    <div class="form-group">
                                        <label class="form-label" for="duty_priority">Duty Priority</label>
                                        <select class="form-select" id="duty_priority" name="duty_priority">
                                            <?php foreach ($priority_options as $value => $label): ?>
                                                <option value="<?php echo $value; ?>" <?php echo isset($_POST['duty_priority']) && $_POST['duty_priority'] == $value ? 'selected' : ''; ?>>
                                                    <?php echo $label; ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>
                                
                                <div class="form-group">
                                    <label class="form-label" for="duty_description">Duty Description</label>
                                    <textarea class="form-textarea" id="duty_description" name="duty_description" 
                                              placeholder="Describe the specific duties and responsibilities..." 
                                              rows="4"><?php echo isset($_POST['duty_description']) ? $_POST['duty_description'] : ''; ?></textarea>
                                    <p class="help-text">Be specific about what the volunteer will be doing during this shift</p>
                                </div>
                                
                                <div class="form-row">
                                    <div class="form-group">
                                        <label class="form-label" for="required_equipment">Required Equipment</label>
                                        <textarea class="form-textarea" id="required_equipment" name="required_equipment" 
                                                  placeholder="List any required equipment (e.g., SCBA, radio, turnout gear, tools)..."
                                                  rows="3"><?php echo isset($_POST['required_equipment']) ? $_POST['required_equipment'] : ''; ?></textarea>
                                    </div>
                                    
                                    <div class="form-group">
                                        <label class="form-label" for="required_training">Required Training</label>
                                        <textarea class="form-textarea" id="required_training" name="required_training" 
                                                  placeholder="List any required training or certifications..."
                                                  rows="3"><?php echo isset($_POST['required_training']) ? $_POST['required_training'] : ''; ?></textarea>
                                    </div>
                                </div>
                                
                                <div class="form-group">
                                    <label class="form-label" for="duty_notes">Duty-Specific Notes</label>
                                    <textarea class="form-textarea" id="duty_notes" name="duty_notes" 
                                              placeholder="Any additional notes specific to this duty assignment..."
                                              rows="2"><?php echo isset($_POST['duty_notes']) ? $_POST['duty_notes'] : ''; ?></textarea>
                                </div>
                                
                                <!-- Duty Type Quick Select -->
                                <div class="form-group">
                                    <label class="form-label">Quick Select Duty Types</label>
                                    <div class="duty-type-grid" id="duty-type-grid">
                                        <?php 
                                        $duty_icons = [
                                            'fire_suppression' => 'bx bx-fire',
                                            'rescue_operations' => 'bx bx-first-aid',
                                            'emergency_medical' => 'bx bx-plus-medical',
                                            'hazardous_materials' => 'bx bx-radiation',
                                            'technical_rescue' => 'bx bx-building',
                                            'water_rescue' => 'bx bx-water',
                                            'command_post' => 'bx bx-command',
                                            'logistics_support' => 'bx bx-package',
                                            'equipment_management' => 'bx bx-wrench',
                                            'communications' => 'bx bx-radio',
                                            'first_aid_station' => 'bx bx-heart',
                                            'crowd_control' => 'bx bx-group',
                                            'investigation' => 'bx bx-search-alt',
                                            'salvage_overhaul' => 'bx bx-hammer',
                                            'rehabilitation' => 'bx bx-restaurant'
                                        ];
                                        
                                        foreach ($duty_types as $value => $label): 
                                            $icon = $duty_icons[$value] ?? 'bx bx-task';
                                        ?>
                                            <div class="duty-type-btn" data-duty-type="<?php echo $value; ?>">
                                                <i class="<?php echo $icon; ?> duty-type-icon"></i>
                                                <?php echo $label; ?>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="form-actions">
                                <button type="reset" class="btn btn-secondary">
                                    <i class='bx bx-reset'></i>
                                    Reset Form
                                </button>
                                <button type="submit" name="create_single_shift" class="btn btn-primary" onclick="return validateSingleShiftForm()">
                                    <i class='bx bx-save'></i>
                                    Create Shift with Duty Assignment
                                </button>
                            </div>
                        </form>
                    </div>
                    
                    <!-- Recurring Shifts Tab -->
                    <div class="tab-content" id="recurring-shifts">
                        <form method="POST" class="form-container" id="recurring-shifts-form">
                            <div class="form-section">
                                <h3 class="form-section-title">
                                    <i class='bx bx-calendar-event'></i>
                                    Recurrence Pattern
                                </h3>
                                
                                <div class="form-row">
                                    <div class="form-group">
                                        <label class="form-label required" for="start_date">Start Date</label>
                                        <input type="date" class="form-input" id="start_date" name="start_date" 
                                               value="<?php echo isset($_POST['start_date']) ? $_POST['start_date'] : date('Y-m-d'); ?>" 
                                               min="<?php echo date('Y-m-d'); ?>" required>
                                        <div class="error-message" id="start_date_error">Please select a start date</div>
                                    </div>
                                    
                                    <div class="form-group">
                                        <label class="form-label required" for="end_date">End Date</label>
                                        <input type="date" class="form-input" id="end_date" name="end_date" 
                                               value="<?php echo isset($_POST['end_date']) ? $_POST['end_date'] : date('Y-m-d', strtotime('+30 days')); ?>" 
                                               min="<?php echo date('Y-m-d'); ?>" required>
                                        <div class="error-message" id="end_date_error">Please select an end date</div>
                                        <p class="help-text">Shifts will be created up to and including this date</p>
                                    </div>
                                </div>
                                
                                <div class="form-group">
                                    <label class="form-label required">Days of Week</label>
                                    <div class="checkbox-group">
                                        <?php 
                                        $selected_days = isset($_POST['recurrence_days']) ? $_POST['recurrence_days'] : ['Monday', 'Wednesday', 'Friday'];
                                        foreach ($days_of_week as $day): ?>
                                            <div class="checkbox-item">
                                                <input type="checkbox" id="day_<?php echo strtolower($day); ?>" 
                                                       name="recurrence_days[]" value="<?php echo $day; ?>"
                                                       <?php echo in_array($day, $selected_days) ? 'checked' : ''; ?>>
                                                <label for="day_<?php echo strtolower($day); ?>"><?php echo $day; ?></label>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                    <div class="error-message" id="recurrence_days_error">Please select at least one day</div>
                                </div>
                            </div>
                            
                            <div class="form-section">
                                <h3 class="form-section-title">
                                    <i class='bx bx-time-five'></i>
                                    Shift Details
                                </h3>
                                
                                <div class="form-row">
                                    <div class="form-group">
                                        <label class="form-label required" for="shift_time_recurring">Shift Time</label>
                                        <input type="time" class="form-input" id="shift_time_recurring" 
                                               name="shift_time" 
                                               value="<?php echo isset($_POST['shift_time']) ? $_POST['shift_time'] : '08:00'; ?>" 
                                               required>
                                        <div class="error-message" id="shift_time_error">Please select a shift time</div>
                                    </div>
                                    
                                    <div class="form-group">
                                        <label class="form-label required" for="duration_hours">Duration (hours)</label>
                                        <div class="duration-input">
                                            <input type="number" class="form-input" id="duration_hours" 
                                                   name="duration_hours" min="1" max="24" 
                                                   value="<?php echo isset($_POST['duration_hours']) ? $_POST['duration_hours'] : '8'; ?>" 
                                                   required>
                                            <span class="duration-label">hours</span>
                                        </div>
                                        <div class="error-message" id="duration_hours_error">Please enter a valid duration (1-24 hours)</div>
                                    </div>
                                </div>
                                
                                <div class="form-row">
                                    <div class="form-group">
                                        <label class="form-label required" for="unit_id_recurring">Assigned Unit</label>
                                        <select class="form-select" id="unit_id_recurring" name="unit_id" required>
                                            <option value="">Select unit</option>
                                            <?php foreach ($units as $unit): ?>
                                                <option value="<?php echo $unit['id']; ?>" data-unit-type="<?php echo htmlspecialchars($unit['unit_type']); ?>" <?php echo isset($_POST['unit_id']) && $_POST['unit_id'] == $unit['id'] ? 'selected' : ''; ?>>
                                                    <?php echo htmlspecialchars($unit['unit_name']); ?> (<?php echo htmlspecialchars($unit['unit_code']); ?>)
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                        <div class="error-message" id="unit_id_recurring_error">Please select a unit</div>
                                    </div>
                                    
                                    <div class="form-group">
                                        <label class="form-label" for="location_recurring">Location</label>
                                        <input type="text" class="form-input" id="location_recurring" name="location" 
                                               placeholder="e.g., Main Station, Firehouse #1" 
                                               value="<?php echo isset($_POST['location']) ? $_POST['location'] : 'Main Station'; ?>">
                                    </div>
                                </div>
                                
                                <div class="form-group">
                                    <label class="form-label" for="notes_recurring">General Notes</label>
                                    <textarea class="form-textarea" id="notes_recurring" name="notes" 
                                              placeholder="Any special instructions or notes for all recurring shifts..."><?php echo isset($_POST['notes']) ? $_POST['notes'] : ''; ?></textarea>
                                </div>
                            </div>
                            
                            <!-- Duty Assignment for Recurring Shifts -->
                            <div class="duty-assignment-section">
                                <h3 class="form-section-title">
                                    <i class='bx bx-task'></i>
                                    Duty Assignment for All Recurring Shifts
                                </h3>
                                
                                <div class="form-row">
                                    <div class="form-group">
                                        <label class="form-label" for="duty_type_recurring">Duty Type</label>
                                        <select class="form-select" id="duty_type_recurring" name="duty_type" onchange="updateDutyDescription(this, 'recurring')">
                                            <option value="">Select duty type (optional)</option>
                                            <?php foreach ($duty_types as $value => $label): ?>
                                                <option value="<?php echo $value; ?>" <?php echo isset($_POST['duty_type']) && $_POST['duty_type'] == $value ? 'selected' : ''; ?>>
                                                    <?php echo $label; ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    
                                    <div class="form-group">
                                        <label class="form-label" for="duty_priority_recurring">Duty Priority</label>
                                        <select class="form-select" id="duty_priority_recurring" name="duty_priority">
                                            <?php foreach ($priority_options as $value => $label): ?>
                                                <option value="<?php echo $value; ?>" <?php echo isset($_POST['duty_priority']) && $_POST['duty_priority'] == $value ? 'selected' : ''; ?>>
                                                    <?php echo $label; ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>
                                
                                <div class="form-group">
                                    <label class="form-label" for="duty_description_recurring">Duty Description</label>
                                    <textarea class="form-textarea" id="duty_description_recurring" name="duty_description" 
                                              placeholder="Describe the specific duties and responsibilities..." 
                                              rows="4"><?php echo isset($_POST['duty_description']) ? $_POST['duty_description'] : ''; ?></textarea>
                                </div>
                            </div>
                            
                            <div class="form-section">
                                <h3 class="form-section-title">
                                    <i class='bx bx-user-check'></i>
                                    Assign Volunteers
                                </h3>
                                
                                <div class="form-group">
                                    <label class="form-label required">Select Volunteers</label>
                                    <p class="help-text">Selected volunteers will be assigned shifts in rotation. At least one volunteer must be selected.</p>
                                    
                                    <div class="volunteer-select-container" id="volunteer-select-container">
                                        <?php 
                                        $selected_volunteers = isset($_POST['volunteer_ids']) ? $_POST['volunteer_ids'] : [];
                                        foreach ($volunteers as $volunteer): 
                                            $is_selected = in_array($volunteer['id'], $selected_volunteers);
                                            $skills = [];
                                            if ($volunteer['skills_basic_firefighting']) $skills[] = 'Firefighting';
                                            if ($volunteer['skills_first_aid_cpr']) $skills[] = 'First Aid/CPR';
                                            if ($volunteer['skills_search_rescue']) $skills[] = 'Search & Rescue';
                                            if ($volunteer['skills_driving']) $skills[] = 'Driving';
                                            if ($volunteer['skills_communication']) $skills[] = 'Communication';
                                            if ($volunteer['skills_mechanical']) $skills[] = 'Mechanical';
                                            if ($volunteer['skills_logistics']) $skills[] = 'Logistics';
                                        ?>
                                            <div class="volunteer-item <?php echo $is_selected ? 'selected' : ''; ?>" 
                                                 data-volunteer-id="<?php echo $volunteer['id']; ?>">
                                                <div class="volunteer-info">
                                                    <div>
                                                        <div class="volunteer-name">
                                                            <?php echo htmlspecialchars($volunteer['first_name'] . ' ' . $volunteer['last_name']); ?>
                                                            <span class="badge badge-success"><?php echo $volunteer['volunteer_status']; ?></span>
                                                            <?php if ($volunteer['volunteer_user_id']): ?>
                                                                <span class="account-badge has-account">Has Account</span>
                                                            <?php else: ?>
                                                                <span class="account-badge no-account">No Account</span>
                                                            <?php endif; ?>
                                                        </div>
                                                        <div class="volunteer-details">
                                                            <?php if ($volunteer['unit_name']): ?>
                                                                <i class='bx bx-building-house'></i> <?php echo htmlspecialchars($volunteer['unit_name']); ?> •
                                                            <?php endif; ?>
                                                            <i class='bx bx-phone'></i> <?php echo htmlspecialchars($volunteer['contact_number']); ?>
                                                        </div>
                                                        <div class="volunteer-skills">
                                                            <?php foreach ($skills as $skill): ?>
                                                                <span class="volunteer-skill"><?php echo $skill; ?></span>
                                                            <?php endforeach; ?>
                                                        </div>
                                                        <div style="margin-top: 5px;">
                                                            <?php 
                                                            $available_days = explode(',', $volunteer['available_days']);
                                                            $available_hours = explode(',', $volunteer['available_hours']);
                                                            foreach ($available_days as $day): 
                                                                if (trim($day)): ?>
                                                                    <span class="availability-badge available"><?php echo trim($day); ?></span>
                                                                <?php endif;
                                                            endforeach; 
                                                            foreach ($available_hours as $hour): 
                                                                if (trim($hour)): ?>
                                                                    <span class="availability-badge"><?php echo trim($hour); ?></span>
                                                                <?php endif;
                                                            endforeach; ?>
                                                        </div>
                                                    </div>
                                                    <input type="checkbox" name="volunteer_ids[]" 
                                                           value="<?php echo $volunteer['id']; ?>" 
                                                           style="display: none;" 
                                                           <?php echo $is_selected ? 'checked' : ''; ?>>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                    <div class="error-message" id="volunteer_ids_error">Please select at least one volunteer</div>
                                    <p class="help-text">Click on volunteers to select/deselect them</p>
                                </div>
                            </div>
                            
                            <div class="form-actions">
                                <button type="reset" class="btn btn-secondary">
                                    <i class='bx bx-reset'></i>
                                    Reset Form
                                </button>
                                <button type="submit" name="create_recurring_shifts" class="btn btn-success" onclick="return validateRecurringShiftsForm()">
                                    <i class='bx bx-calendar-plus'></i>
                                    Create Recurring Shifts
                                </button>
                            </div>
                        </form>
                    </div>
                    
                    <!-- Bulk Shifts Tab -->
                    <div class="tab-content" id="bulk-shifts">
                        <form method="POST" class="form-container" id="bulk-shifts-form">
                            <div class="form-section">
                                <h3 class="form-section-title">
                                    <i class='bx bx-group'></i>
                                    Assign Multiple Volunteers
                                </h3>
                                
                                <div class="form-row">
                                    <div class="form-group">
                                        <label class="form-label required" for="bulk_shift_date">Shift Date</label>
                                        <input type="date" class="form-input" id="bulk_shift_date" name="bulk_shift_date" 
                                               value="<?php echo isset($_POST['bulk_shift_date']) ? $_POST['bulk_shift_date'] : date('Y-m-d'); ?>" 
                                               min="<?php echo date('Y-m-d'); ?>" required>
                                        <div class="error-message" id="bulk_shift_date_error">Please select a shift date</div>
                                    </div>
                                </div>
                                
                                <div class="form-row">
                                    <div class="form-group">
                                        <label class="form-label required" for="bulk_start_time">Start Time</label>
                                        <input type="time" class="form-input" id="bulk_start_time" name="bulk_start_time" 
                                               value="<?php echo isset($_POST['bulk_start_time']) ? $_POST['bulk_start_time'] : '08:00'; ?>" required>
                                        <div class="error-message" id="bulk_start_time_error">Please select a start time</div>
                                    </div>
                                    
                                    <div class="form-group">
                                        <label class="form-label required" for="bulk_end_time">End Time</label>
                                        <input type="time" class="form-input" id="bulk_end_time" name="bulk_end_time" 
                                               value="<?php echo isset($_POST['bulk_end_time']) ? $_POST['bulk_end_time'] : '17:00'; ?>" required>
                                        <div class="error-message" id="bulk_end_time_error">Please select an end time</div>
                                    </div>
                                </div>
                                
                                <div class="form-row">
                                    <div class="form-group">
                                        <label class="form-label required" for="unit_id_bulk">Assigned Unit</label>
                                        <select class="form-select" id="unit_id_bulk" name="unit_id" required>
                                            <option value="">Select unit</option>
                                            <?php foreach ($units as $unit): ?>
                                                <option value="<?php echo $unit['id']; ?>" data-unit-type="<?php echo htmlspecialchars($unit['unit_type']); ?>" <?php echo isset($_POST['unit_id']) && $_POST['unit_id'] == $unit['id'] ? 'selected' : ''; ?>>
                                                    <?php echo htmlspecialchars($unit['unit_name']); ?> (<?php echo htmlspecialchars($unit['unit_code']); ?>)
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                        <div class="error-message" id="unit_id_bulk_error">Please select a unit</div>
                                    </div>
                                    
                                    <div class="form-group">
                                        <label class="form-label" for="location_bulk">Location</label>
                                        <input type="text" class="form-input" id="location_bulk" name="location" 
                                               placeholder="e.g., Main Station, Firehouse #1" 
                                               value="<?php echo isset($_POST['location']) ? $_POST['location'] : 'Main Station'; ?>">
                                    </div>
                                </div>
                                
                                <div class="form-group">
                                    <label class="form-label" for="notes_bulk">General Notes</label>
                                    <textarea class="form-textarea" id="notes_bulk" name="notes" 
                                              placeholder="Any special instructions or notes..."><?php echo isset($_POST['notes']) ? $_POST['notes'] : ''; ?></textarea>
                                </div>
                            </div>
                            
                            <!-- Duty Assignment for Bulk Shifts -->
                            <div class="duty-assignment-section">
                                <h3 class="form-section-title">
                                    <i class='bx bx-task'></i>
                                    Duty Assignment for All Bulk Shifts
                                </h3>
                                
                                <div class="form-row">
                                    <div class="form-group">
                                        <label class="form-label" for="duty_type_bulk">Duty Type</label>
                                        <select class="form-select" id="duty_type_bulk" name="duty_type" onchange="updateDutyDescription(this, 'bulk')">
                                            <option value="">Select duty type (optional)</option>
                                            <?php foreach ($duty_types as $value => $label): ?>
                                                <option value="<?php echo $value; ?>" <?php echo isset($_POST['duty_type']) && $_POST['duty_type'] == $value ? 'selected' : ''; ?>>
                                                    <?php echo $label; ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    
                                    <div class="form-group">
                                        <label class="form-label" for="duty_priority_bulk">Duty Priority</label>
                                        <select class="form-select" id="duty_priority_bulk" name="duty_priority">
                                            <?php foreach ($priority_options as $value => $label): ?>
                                                <option value="<?php echo $value; ?>" <?php echo isset($_POST['duty_priority']) && $_POST['duty_priority'] == $value ? 'selected' : ''; ?>>
                                                    <?php echo $label; ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>
                                
                                <div class="form-group">
                                    <label class="form-label" for="duty_description_bulk">Duty Description</label>
                                    <textarea class="form-textarea" id="duty_description_bulk" name="duty_description" 
                                              placeholder="Describe the specific duties and responsibilities..." 
                                              rows="4"><?php echo isset($_POST['duty_description']) ? $_POST['duty_description'] : ''; ?></textarea>
                                </div>
                            </div>
                            
                            <div class="form-section">
                                <h3 class="form-section-title">
                                    <i class='bx bx-user-plus'></i>
                                    Select Volunteers
                                </h3>
                                
                                <div class="form-group">
                                    <label class="form-label required">Select Volunteers</label>
                                    <p class="help-text">Selected volunteers will be assigned the same shift on the selected date. At least one volunteer must be selected.</p>
                                    
                                    <div class="volunteer-select-container" id="bulk-volunteer-select-container">
                                        <?php 
                                        $selected_bulk_volunteers = isset($_POST['bulk_volunteer_ids']) ? $_POST['bulk_volunteer_ids'] : [];
                                        foreach ($volunteers as $volunteer): 
                                            $is_selected = in_array($volunteer['id'], $selected_bulk_volunteers);
                                            $skills = [];
                                            if ($volunteer['skills_basic_firefighting']) $skills[] = 'Firefighting';
                                            if ($volunteer['skills_first_aid_cpr']) $skills[] = 'First Aid/CPR';
                                            if ($volunteer['skills_search_rescue']) $skills[] = 'Search & Rescue';
                                            if ($volunteer['skills_driving']) $skills[] = 'Driving';
                                            if ($volunteer['skills_communication']) $skills[] = 'Communication';
                                            if ($volunteer['skills_mechanical']) $skills[] = 'Mechanical';
                                            if ($volunteer['skills_logistics']) $skills[] = 'Logistics';
                                        ?>
                                            <div class="volunteer-item <?php echo $is_selected ? 'selected' : ''; ?>" 
                                                 data-volunteer-id="<?php echo $volunteer['id']; ?>">
                                                <div class="volunteer-info">
                                                    <div>
                                                        <div class="volunteer-name">
                                                            <?php echo htmlspecialchars($volunteer['first_name'] . ' ' . $volunteer['last_name']); ?>
                                                            <span class="badge badge-success"><?php echo $volunteer['volunteer_status']; ?></span>
                                                            <?php if ($volunteer['volunteer_user_id']): ?>
                                                                <span class="account-badge has-account">Has Account</span>
                                                            <?php else: ?>
                                                                <span class="account-badge no-account">No Account</span>
                                                            <?php endif; ?>
                                                        </div>
                                                        <div class="volunteer-details">
                                                            <?php if ($volunteer['unit_name']): ?>
                                                                <i class='bx bx-building-house'></i> <?php echo htmlspecialchars($volunteer['unit_name']); ?> •
                                                            <?php endif; ?>
                                                            <i class='bx bx-phone'></i> <?php echo htmlspecialchars($volunteer['contact_number']); ?>
                                                        </div>
                                                        <div class="volunteer-skills">
                                                            <?php foreach ($skills as $skill): ?>
                                                                <span class="volunteer-skill"><?php echo $skill; ?></span>
                                                            <?php endforeach; ?>
                                                        </div>
                                                    </div>
                                                    <input type="checkbox" name="bulk_volunteer_ids[]" 
                                                           value="<?php echo $volunteer['id']; ?>" 
                                                           style="display: none;"
                                                           <?php echo $is_selected ? 'checked' : ''; ?>>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                    <div class="error-message" id="bulk_volunteer_ids_error">Please select at least one volunteer</div>
                                    <p class="help-text">Click on volunteers to select/deselect them</p>
                                </div>
                            </div>
                            
                            <div class="form-actions">
                                <button type="reset" class="btn btn-secondary">
                                    <i class='bx bx-reset'></i>
                                    Reset Form
                                </button>
                                <button type="submit" name="create_bulk_shifts" class="btn btn-success" onclick="return validateBulkShiftsForm()">
                                    <i class='bx bx-user-plus'></i>
                                    Create Bulk Shifts
                                </button>
                            </div>
                        </form>
                    </div>
                    
                    <!-- View Calendar Tab -->
                    <div class="tab-content" id="view-calendar">
                        <div class="calendar-container">
                            <div class="calendar-header">
                                <h3 class="calendar-title" id="calendar-month-year"><?php echo date('F Y'); ?></h3>
                                <div class="calendar-nav">
                                    <button class="calendar-nav-btn" id="prev-month">
                                        <i class='bx bx-chevron-left'></i> Prev
                                    </button>
                                    <button class="calendar-nav-btn" id="today-btn">Today</button>
                                    <button class="calendar-nav-btn" id="next-month">
                                        Next <i class='bx bx-chevron-right'></i>
                                    </button>
                                </div>
                            </div>
                            
                            <div class="calendar-grid" id="calendar-grid">
                                <!-- Calendar will be generated by JavaScript -->
                            </div>
                        </div>
                        
                        <div class="form-container">
                            <h3 class="form-section-title">
                                <i class='bx bx-stats'></i>
                                Shift Statistics
                            </h3>
                            
                            <div class="form-row">
                                <div class="form-group">
                                    <div style="text-align: center; padding: 20px; background: rgba(59, 130, 246, 0.1); border-radius: 10px;">
                                        <div style="font-size: 32px; font-weight: 800; color: var(--info);">
                                            <?php echo count($existing_shifts); ?>
                                        </div>
                                        <div style="color: var(--text-light);">Total Shifts (Next 30 Days)</div>
                                    </div>
                                </div>
                                
                                <div class="form-group">
                                    <div style="text-align: center; padding: 20px; background: rgba(16, 185, 129, 0.1); border-radius: 10px;">
                                        <div style="font-size: 32px; font-weight: 800; color: var(--success);">
                                            <?php 
                                            $volunteer_shifts = array_filter($existing_shifts, function($shift) {
                                                return $shift['shift_for'] === 'volunteer';
                                            });
                                            echo count($volunteer_shifts);
                                            ?>
                                        </div>
                                        <div style="color: var(--text-light);">Volunteer Shifts</div>
                                    </div>
                                </div>
                                
                                <div class="form-group">
                                    <div style="text-align: center; padding: 20px; background: rgba(245, 158, 11, 0.1); border-radius: 10px;">
                                        <div style="font-size: 32px; font-weight: 800; color: var(--warning);">
                                            <?php 
                                            $shifts_with_duty = array_filter($existing_shifts, function($shift) {
                                                return !empty($shift['duty_type']);
                                            });
                                            echo count($shifts_with_duty);
                                            ?>
                                        </div>
                                        <div style="color: var(--text-light);">Shifts with Duty Assignment</div>
                                    </div>
                                </div>
                                
                                <div class="form-group">
                                    <div style="text-align: center; padding: 20px; background: rgba(220, 38, 38, 0.1); border-radius: 10px;">
                                        <div style="font-size: 32px; font-weight: 800; color: var(--danger);">
                                            <?php 
                                            $today_shifts = array_filter($existing_shifts, function($shift) {
                                                return $shift['shift_date'] === date('Y-m-d');
                                            });
                                            echo count($today_shifts);
                                            ?>
                                        </div>
                                        <div style="color: var(--text-light);">Today's Shifts</div>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Duty Type Distribution -->
                            <div class="form-section">
                                <h3 class="form-section-title">
                                    <i class='bx bx-pie-chart-alt'></i>
                                    Duty Type Distribution
                                </h3>
                                
                                <div class="form-row">
                                    <?php 
                                    $duty_counts = [];
                                    foreach ($existing_shifts as $shift) {
                                        if (!empty($shift['duty_type'])) {
                                            $duty_counts[$shift['duty_type']] = ($duty_counts[$shift['duty_type']] ?? 0) + 1;
                                        }
                                    }
                                    
                                    // Sort by count descending
                                    arsort($duty_counts);
                                    $top_duties = array_slice($duty_counts, 0, 4, true);
                                    
                                    $duty_colors = [
                                        'fire_suppression' => '#dc2626',
                                        'rescue_operations' => '#10b981',
                                        'emergency_medical' => '#3b82f6',
                                        'hazardous_materials' => '#f59e0b',
                                        'technical_rescue' => '#8b5cf6',
                                        'water_rescue' => '#06b6d4',
                                        'command_post' => '#6366f1',
                                        'logistics_support' => '#84cc16',
                                        'equipment_management' => '#f97316',
                                        'communications' => '#ec4899'
                                    ];
                                    
                                    foreach ($top_duties as $duty => $count):
                                        $color = $duty_colors[$duty] ?? '#6b7280';
                                        $label = $duty_types[$duty] ?? ucfirst(str_replace('_', ' ', $duty));
                                    ?>
                                        <div class="form-group">
                                            <div style="text-align: center; padding: 20px; border-radius: 10px; background: <?php echo $color; ?>20;">
                                                <div style="font-size: 32px; font-weight: 800; color: <?php echo $color; ?>;">
                                                    <?php echo $count; ?>
                                                </div>
                                                <div style="color: var(--text-light); font-size: 12px;"><?php echo $label; ?></div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <script>
        // Duty assignment data from PHP
        const defaultDutyDescriptions = <?php echo json_encode($default_duty_descriptions); ?>;
        const defaultRequiredTraining = <?php echo json_encode($default_required_training); ?>;
        const dutyTypes = <?php echo json_encode($duty_types); ?>;
        
        document.addEventListener('DOMContentLoaded', function() {
            // Initialize event listeners
            initEventListeners();
            
            // Update time display
            updateTime();
            setInterval(updateTime, 1000);
            
            // Initialize tabs
            initTabs();
            
            // Initialize volunteer selection
            initVolunteerSelection();
            
            // Initialize calendar
            initCalendar();
            
            // Set up shift type time auto-fill
            setupShiftTypeAutoFill();
            
            // Initialize duty type selection
            initDutyTypeSelection();
            
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
            
            // Calendar modal
            const calendarModal = document.getElementById('calendar-modal');
            const calendarModalClose = document.getElementById('calendar-modal-close');
            const closeCalendarModal = document.getElementById('close-calendar-modal');
            
            calendarModalClose.addEventListener('click', () => calendarModal.classList.remove('active'));
            closeCalendarModal.addEventListener('click', () => calendarModal.classList.remove('active'));
            
            calendarModal.addEventListener('click', function(e) {
                if (e.target === this) {
                    calendarModal.classList.remove('active');
                }
            });
        }
        
        function initTabs() {
            const tabButtons = document.querySelectorAll('.tab-button');
            const tabContents = document.querySelectorAll('.tab-content');
            
            tabButtons.forEach(button => {
                button.addEventListener('click', () => {
                    // Remove active class from all buttons and contents
                    tabButtons.forEach(btn => btn.classList.remove('active'));
                    tabContents.forEach(content => content.classList.remove('active'));
                    
                    // Add active class to clicked button
                    button.classList.add('active');
                    
                    // Show corresponding content
                    const tabId = button.getAttribute('data-tab');
                    document.getElementById(tabId).classList.add('active');
                    
                    // If switching to calendar tab, refresh calendar
                    if (tabId === 'view-calendar') {
                        refreshCalendar();
                    }
                });
            });
        }
        
        function initVolunteerSelection() {
            // For recurring shifts tab
            const volunteerItems = document.querySelectorAll('#volunteer-select-container .volunteer-item');
            volunteerItems.forEach(item => {
                item.addEventListener('click', function() {
                    this.classList.toggle('selected');
                    const checkbox = this.querySelector('input[type="checkbox"]');
                    checkbox.checked = !checkbox.checked;
                });
            });
            
            // For bulk shifts tab
            const bulkVolunteerItems = document.querySelectorAll('#bulk-volunteer-select-container .volunteer-item');
            bulkVolunteerItems.forEach(item => {
                item.addEventListener('click', function() {
                    this.classList.toggle('selected');
                    const checkbox = this.querySelector('input[type="checkbox"]');
                    checkbox.checked = !checkbox.checked;
                });
            });
        }
        
        function initDutyTypeSelection() {
            // Duty type quick select buttons
            const dutyTypeButtons = document.querySelectorAll('.duty-type-btn');
            dutyTypeButtons.forEach(button => {
                button.addEventListener('click', function() {
                    const dutyType = this.getAttribute('data-duty-type');
                    
                    // Remove selected class from all buttons
                    dutyTypeButtons.forEach(btn => btn.classList.remove('selected'));
                    
                    // Add selected class to clicked button
                    this.classList.add('selected');
                    
                    // Update duty type select and description based on active tab
                    const activeTab = document.querySelector('.tab-content.active').id;
                    let dutyTypeSelect, dutyDescriptionField, dutyTrainingField;
                    
                    switch(activeTab) {
                        case 'single-shift':
                            dutyTypeSelect = document.getElementById('duty_type');
                            dutyDescriptionField = document.getElementById('duty_description');
                            dutyTrainingField = document.getElementById('required_training');
                            break;
                        case 'recurring-shifts':
                            dutyTypeSelect = document.getElementById('duty_type_recurring');
                            dutyDescriptionField = document.getElementById('duty_description_recurring');
                            break;
                        case 'bulk-shifts':
                            dutyTypeSelect = document.getElementById('duty_type_bulk');
                            dutyDescriptionField = document.getElementById('duty_description_bulk');
                            break;
                    }
                    
                    if (dutyTypeSelect) {
                        dutyTypeSelect.value = dutyType;
                        if (dutyDescriptionField) {
                            dutyDescriptionField.value = defaultDutyDescriptions[dutyType] || '';
                        }
                        if (dutyTrainingField) {
                            dutyTrainingField.value = defaultRequiredTraining[dutyType] || '';
                        }
                    }
                });
            });
        }
        
        function setupShiftTypeAutoFill() {
            const shiftTypeSelect = document.getElementById('shift_type');
            const startTimeInput = document.getElementById('start_time');
            const endTimeInput = document.getElementById('end_time');
            
            const defaultTimes = {
                'morning': ['06:00', '14:00'],
                'afternoon': ['14:00', '22:00'],
                'evening': ['18:00', '02:00'],
                'night': ['22:00', '06:00'],
                'full_day': ['08:00', '17:00']
            };
            
            shiftTypeSelect.addEventListener('change', function() {
                const selectedType = this.value;
                if (defaultTimes[selectedType]) {
                    startTimeInput.value = defaultTimes[selectedType][0];
                    endTimeInput.value = defaultTimes[selectedType][1];
                }
            });
        }
        
        function updateDutyDescription(selectElement, formType = 'single') {
            const dutyType = selectElement.value;
            let dutyDescriptionField, dutyTrainingField;
            
            switch(formType) {
                case 'single':
                    dutyDescriptionField = document.getElementById('duty_description');
                    dutyTrainingField = document.getElementById('required_training');
                    break;
                case 'recurring':
                    dutyDescriptionField = document.getElementById('duty_description_recurring');
                    break;
                case 'bulk':
                    dutyDescriptionField = document.getElementById('duty_description_bulk');
                    break;
            }
            
            if (dutyDescriptionField && defaultDutyDescriptions[dutyType]) {
                dutyDescriptionField.value = defaultDutyDescriptions[dutyType];
            }
            
            if (dutyTrainingField && defaultRequiredTraining[dutyType]) {
                dutyTrainingField.value = defaultRequiredTraining[dutyType];
            }
            
            // Update quick select buttons
            const dutyTypeButtons = document.querySelectorAll('.duty-type-btn');
            dutyTypeButtons.forEach(button => {
                if (button.getAttribute('data-duty-type') === dutyType) {
                    button.classList.add('selected');
                } else {
                    button.classList.remove('selected');
                }
            });
        }
        
        function checkVolunteerSkills(selectElement) {
            const volunteerSkillsDisplay = document.getElementById('volunteer-skills-display');
            const selectedOption = selectElement.options[selectElement.selectedIndex];
            
            if (selectedOption.value) {
                const skills = JSON.parse(selectedOption.getAttribute('data-skills') || '[]');
                
                if (skills.length > 0) {
                    volunteerSkillsDisplay.innerHTML = '<strong>Skills:</strong> ' + skills.join(', ');
                    volunteerSkillsDisplay.style.display = 'block';
                } else {
                    volunteerSkillsDisplay.innerHTML = '<em>No specific skills recorded</em>';
                    volunteerSkillsDisplay.style.display = 'block';
                }
            } else {
                volunteerSkillsDisplay.style.display = 'none';
            }
        }
        
        // Form validation functions
        function validateSingleShiftForm() {
            let isValid = true;
            
            // Reset errors
            document.querySelectorAll('#single-shift-form .error-message').forEach(el => el.style.display = 'none');
            document.querySelectorAll('#single-shift-form .form-input, #single-shift-form .form-select').forEach(el => el.classList.remove('error-field'));
            
            // Check shift type
            const shiftType = document.getElementById('shift_type');
            if (!shiftType.value) {
                document.getElementById('shift_type_error').style.display = 'block';
                shiftType.classList.add('error-field');
                isValid = false;
            }
            
            // Check unit
            const unitId = document.getElementById('unit_id');
            if (!unitId.value) {
                document.getElementById('unit_id_error').style.display = 'block';
                unitId.classList.add('error-field');
                isValid = false;
            }
            
            // Check shift date
            const shiftDate = document.getElementById('shift_date');
            if (!shiftDate.value) {
                document.getElementById('shift_date_error').style.display = 'block';
                shiftDate.classList.add('error-field');
                isValid = false;
            }
            
            // Check volunteer
            const volunteerId = document.getElementById('volunteer_id');
            if (!volunteerId.value) {
                document.getElementById('volunteer_id_error').style.display = 'block';
                volunteerId.classList.add('error-field');
                isValid = false;
            }
            
            // Check start time
            const startTime = document.getElementById('start_time');
            if (!startTime.value) {
                document.getElementById('start_time_error').style.display = 'block';
                startTime.classList.add('error-field');
                isValid = false;
            }
            
            // Check end time
            const endTime = document.getElementById('end_time');
            if (!endTime.value) {
                document.getElementById('end_time_error').style.display = 'block';
                endTime.classList.add('error-field');
                isValid = false;
            }
            
            return isValid;
        }
        
        function validateRecurringShiftsForm() {
            let isValid = true;
            
            // Reset errors
            document.querySelectorAll('#recurring-shifts-form .error-message').forEach(el => el.style.display = 'none');
            document.querySelectorAll('#recurring-shifts-form .form-input, #recurring-shifts-form .form-select').forEach(el => el.classList.remove('error-field'));
            
            // Check shift type (from parent form)
            const shiftType = document.getElementById('shift_type');
            if (!shiftType.value) {
                document.getElementById('shift_type_error').style.display = 'block';
                shiftType.classList.add('error-field');
                isValid = false;
            }
            
            // Check unit
            const unitId = document.getElementById('unit_id_recurring');
            if (!unitId.value) {
                document.getElementById('unit_id_recurring_error').style.display = 'block';
                unitId.classList.add('error-field');
                isValid = false;
            }
            
            // Check start date
            const startDate = document.getElementById('start_date');
            if (!startDate.value) {
                document.getElementById('start_date_error').style.display = 'block';
                startDate.classList.add('error-field');
                isValid = false;
            }
            
            // Check end date
            const endDate = document.getElementById('end_date');
            if (!endDate.value) {
                document.getElementById('end_date_error').style.display = 'block';
                endDate.classList.add('error-field');
                isValid = false;
            }
            
            // Check recurrence days
            const recurrenceDays = document.querySelectorAll('input[name="recurrence_days[]"]:checked');
            if (recurrenceDays.length === 0) {
                document.getElementById('recurrence_days_error').style.display = 'block';
                isValid = false;
            }
            
            // Check shift time
            const shiftTime = document.getElementById('shift_time_recurring');
            if (!shiftTime.value) {
                document.getElementById('shift_time_error').style.display = 'block';
                shiftTime.classList.add('error-field');
                isValid = false;
            }
            
            // Check duration
            const duration = document.getElementById('duration_hours');
            if (!duration.value || duration.value < 1 || duration.value > 24) {
                document.getElementById('duration_hours_error').style.display = 'block';
                duration.classList.add('error-field');
                isValid = false;
            }
            
            // Check volunteers
            const volunteers = document.querySelectorAll('input[name="volunteer_ids[]"]:checked');
            if (volunteers.length === 0) {
                document.getElementById('volunteer_ids_error').style.display = 'block';
                isValid = false;
            }
            
            return isValid;
        }
        
        function validateBulkShiftsForm() {
            let isValid = true;
            
            // Reset errors
            document.querySelectorAll('#bulk-shifts-form .error-message').forEach(el => el.style.display = 'none');
            document.querySelectorAll('#bulk-shifts-form .form-input, #bulk-shifts-form .form-select').forEach(el => el.classList.remove('error-field'));
            
            // Check shift type (from parent form)
            const shiftType = document.getElementById('shift_type');
            if (!shiftType.value) {
                document.getElementById('shift_type_error').style.display = 'block';
                shiftType.classList.add('error-field');
                isValid = false;
            }
            
            // Check unit
            const unitId = document.getElementById('unit_id_bulk');
            if (!unitId.value) {
                document.getElementById('unit_id_bulk_error').style.display = 'block';
                unitId.classList.add('error-field');
                isValid = false;
            }
            
            // Check shift date
            const shiftDate = document.getElementById('bulk_shift_date');
            if (!shiftDate.value) {
                document.getElementById('bulk_shift_date_error').style.display = 'block';
                shiftDate.classList.add('error-field');
                isValid = false;
            }
            
            // Check start time
            const startTime = document.getElementById('bulk_start_time');
            if (!startTime.value) {
                document.getElementById('bulk_start_time_error').style.display = 'block';
                startTime.classList.add('error-field');
                isValid = false;
            }
            
            // Check end time
            const endTime = document.getElementById('bulk_end_time');
            if (!endTime.value) {
                document.getElementById('bulk_end_time_error').style.display = 'block';
                endTime.classList.add('error-field');
                isValid = false;
            }
            
            // Check volunteers
            const volunteers = document.querySelectorAll('input[name="bulk_volunteer_ids[]"]:checked');
            if (volunteers.length === 0) {
                document.getElementById('bulk_volunteer_ids_error').style.display = 'block';
                isValid = false;
            }
            
            return isValid;
        }
        
        // Calendar functionality
        let currentDate = new Date();
        
        function initCalendar() {
            // Calendar navigation
            document.getElementById('prev-month').addEventListener('click', () => {
                currentDate.setMonth(currentDate.getMonth() - 1);
                refreshCalendar();
            });
            
            document.getElementById('next-month').addEventListener('click', () => {
                currentDate.setMonth(currentDate.getMonth() + 1);
                refreshCalendar();
            });
            
            document.getElementById('today-btn').addEventListener('click', () => {
                currentDate = new Date();
                refreshCalendar();
            });
            
            // Initial calendar render
            refreshCalendar();
        }
        
        function refreshCalendar() {
            const year = currentDate.getFullYear();
            const month = currentDate.getMonth();
            
            // Update month/year display
            document.getElementById('calendar-month-year').textContent = 
                currentDate.toLocaleDateString('en-US', { month: 'long', year: 'numeric' });
            
            // Get first day of month
            const firstDay = new Date(year, month, 1);
            const lastDay = new Date(year, month + 1, 0);
            const daysInMonth = lastDay.getDate();
            
            // Get day of week for first day (0 = Sunday, 1 = Monday, etc.)
            const firstDayIndex = firstDay.getDay();
            
            // Clear calendar
            const calendarGrid = document.getElementById('calendar-grid');
            calendarGrid.innerHTML = '';
            
            // Add day headers
            const dayHeaders = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
            dayHeaders.forEach(day => {
                const dayHeader = document.createElement('div');
                dayHeader.className = 'calendar-day-header';
                dayHeader.textContent = day;
                calendarGrid.appendChild(dayHeader);
            });
            
            // Add empty cells for days before first day of month
            for (let i = 0; i < firstDayIndex; i++) {
                const emptyDay = document.createElement('div');
                emptyDay.className = 'calendar-day other-month';
                calendarGrid.appendChild(emptyDay);
            }
            
            // Add days of month
            const today = new Date();
            today.setHours(0, 0, 0, 0);
            
            for (let day = 1; day <= daysInMonth; day++) {
                const date = new Date(year, month, day);
                const dateStr = date.toISOString().split('T')[0];
                
                const dayElement = document.createElement('div');
                dayElement.className = 'calendar-day';
                
                // Check if today
                if (date.getTime() === today.getTime()) {
                    dayElement.classList.add('today');
                }
                
                // Day number
                const dayNumber = document.createElement('div');
                dayNumber.className = 'day-number';
                dayNumber.textContent = day;
                dayElement.appendChild(dayNumber);
                
                // Add shifts for this day
                const shiftsForDay = <?php echo json_encode($shifts_by_date); ?>[dateStr] || [];
                
                // Limit to 3 shifts for display
                const displayShifts = shiftsForDay.slice(0, 3);
                
                displayShifts.forEach(shift => {
                    const shiftElement = document.createElement('div');
                    shiftElement.className = `shift-item ${shift.shift_for}`;
                    
                    // Format time
                    const startTime = shift.start_time ? shift.start_time.substring(0, 5) : '';
                    const assignedTo = shift.volunteer_first_name ? 
                        `${shift.volunteer_first_name.charAt(0)}. ${shift.volunteer_last_name}` : 'Unassigned';
                    
                    // Add duty type to tooltip if available
                    let tooltip = `${shift.shift_type} - ${shift.unit_name || 'No Unit'}`;
                    if (shift.duty_type) {
                        const dutyLabel = dutyTypes[shift.duty_type] || shift.duty_type;
                        tooltip += ` - ${dutyLabel}`;
                    }
                    
                    shiftElement.title = tooltip;
                    shiftElement.textContent = `${startTime} - ${assignedTo}`;
                    shiftElement.addEventListener('click', () => showShiftsForDate(dateStr));
                    
                    dayElement.appendChild(shiftElement);
                });
                
                // Show "+ more" if there are more than 3 shifts
                if (shiftsForDay.length > 3) {
                    const moreElement = document.createElement('div');
                    moreElement.className = 'shift-item';
                    moreElement.textContent = `+${shiftsForDay.length - 3} more`;
                    moreElement.addEventListener('click', () => showShiftsForDate(dateStr));
                    dayElement.appendChild(moreElement);
                }
                
                // Add click event to view all shifts for this day
                dayElement.addEventListener('click', (e) => {
                    if (!e.target.classList.contains('shift-item')) {
                        showShiftsForDate(dateStr);
                    }
                });
                
                calendarGrid.appendChild(dayElement);
            }
        }
        
        function showShiftsForDate(dateStr) {
            const modal = document.getElementById('calendar-modal');
            const title = document.getElementById('modal-date-title');
            const content = document.getElementById('shifts-list-content');
            
            const date = new Date(dateStr);
            const formattedDate = date.toLocaleDateString('en-US', { 
                weekday: 'long', 
                year: 'numeric', 
                month: 'long', 
                day: 'numeric' 
            });
            
            title.textContent = `Shifts for ${formattedDate}`;
            
            // Get shifts for this date
            const shiftsForDay = <?php echo json_encode($shifts_by_date); ?>[dateStr] || [];
            
            if (shiftsForDay.length === 0) {
                content.innerHTML = `
                    <div class="no-shifts">
                        <i class='bx bx-calendar-x'></i>
                        <h3>No Shifts Scheduled</h3>
                        <p>There are no shifts scheduled for this date.</p>
                    </div>
                `;
            } else {
                let shiftsHtml = '<div class="shifts-list">';
                
                shiftsForDay.forEach(shift => {
                    const startTime = shift.start_time ? shift.start_time.substring(0, 5) : '';
                    const endTime = shift.end_time ? shift.end_time.substring(0, 5) : '';
                    const assignedTo = shift.volunteer_first_name ? 
                        `${shift.volunteer_first_name} ${shift.volunteer_last_name}` : 
                        (shift.user_id ? 'Employee' : 'Unassigned');
                    
                    const dutyLabel = shift.duty_type ? (dutyTypes[shift.duty_type] || shift.duty_type) : 'No specific duty';
                    
                    shiftsHtml += `
                        <div class="shift-detail-item">
                            <div class="shift-detail-header">
                                <div class="shift-time">${startTime} - ${endTime}</div>
                                <span class="badge ${shift.status === 'confirmed' ? 'badge-success' : shift.status === 'pending' ? 'badge-warning' : 'badge-info'}">
                                    ${shift.status}
                                </span>
                            </div>
                            <div class="shift-assigned">
                                <strong>Assigned to:</strong> ${assignedTo}
                            </div>
                            <div class="shift-assigned" style="margin-top: 5px;">
                                <strong>Duty:</strong> ${dutyLabel}
                            </div>
                            ${shift.duty_description ? `
                                <div class="shift-unit" style="margin-top: 5px; font-style: italic;">
                                    <i class='bx bx-task'></i> ${shift.duty_description.substring(0, 100)}${shift.duty_description.length > 100 ? '...' : ''}
                                </div>
                            ` : ''}
                            ${shift.unit_name ? `
                                <div class="shift-unit">
                                    <i class='bx bx-building-house'></i> ${shift.unit_name} (${shift.unit_code})
                                </div>
                            ` : ''}
                        </div>
                    `;
                });
                
                shiftsHtml += '</div>';
                content.innerHTML = shiftsHtml;
            }
            
            modal.classList.add('active');
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