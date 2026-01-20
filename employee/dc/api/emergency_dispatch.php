<?php
// api/emergency_dispatch.php - COMPLETE Emergency Dispatch System with AI Suggestions Only
require_once '../../../config/db_connection.php';
header('Content-Type: application/json');
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, PUT, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, X-API-Key");

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

/**
 * Helper Functions - MUST BE DEFINED BEFORE ANY CODE THAT CALLS THEM
 */
function calculateReadinessScore($unit, $volunteers, $vehicles) {
    $score = 0;
    if ($unit['current_status'] === 'available') $score += 30;
    elseif ($unit['current_status'] === 'standby') $score += 20;
    else $score += 5;
    
    $volunteer_count = count($volunteers);
    if ($volunteer_count >= 10) $score += 30;
    elseif ($volunteer_count >= 5) $score += 20;
    elseif ($volunteer_count >= 2) $score += 10;
    
    $vehicle_count = count($vehicles);
    if ($vehicle_count >= 3) $score += 20;
    elseif ($vehicle_count >= 2) $score += 15;
    elseif ($vehicle_count >= 1) $score += 10;
    
    // Additional score for unit type match
    if (isset($unit['unit_type'])) {
        if ($unit['unit_type'] === 'Fire' || $unit['unit_type'] === 'Rescue') $score += 10;
        elseif ($unit['unit_type'] === 'EMS') $score += 8;
    }
    
    return min($score, 100);
}

function calculateSuggestionScore($unit, $incident) {
    $score = 0;
    $emergency_type = strtolower($incident['emergency_type'] ?? '');
    $unit_type = strtolower($unit['unit_type'] ?? '');
    
    // Match emergency type with unit type
    if ($emergency_type === 'fire' && $unit_type === 'fire') {
        $score += 40;
    } elseif ($emergency_type === 'medical' && $unit_type === 'ems') {
        $score += 40;
    } elseif ($emergency_type === 'other' && ($unit_type === 'rescue' || $unit_type === 'fire')) {
        $score += 35;
    } else {
        $score += 20; // Base score for any match
    }
    
    // Add volunteer count score
    $volunteer_count = $unit['volunteer_count'] ?? 0;
    $score += min($volunteer_count * 3, 25);
    
    // Add vehicle count score
    $vehicle_count = $unit['available_vehicle_count'] ?? 0;
    $score += min($vehicle_count * 5, 15);
    
    // Add severity multiplier
    $severity = strtolower($incident['severity'] ?? 'medium');
    $severity_multipliers = [
        'critical' => 1.3,
        'high' => 1.2,
        'medium' => 1.0,
        'low' => 0.8
    ];
    $multiplier = $severity_multipliers[$severity] ?? 1.0;
    $score *= $multiplier;
    
    // Random factor for demo (simulating proximity, traffic, etc.)
    $score += rand(-10, 10);
    
    return min(round($score), 100);
}

function getRecommendationLevel($score) {
    if ($score >= 85) return 'High';
    if ($score >= 70) return 'Medium-High';
    if ($score >= 55) return 'Medium';
    if ($score >= 40) return 'Low-Medium';
    return 'Low';
}

function getUnitSuggestions($pdo, $incident) {
    $available_units_query = "
        SELECT u.*, 
               COUNT(DISTINCT va.volunteer_id) as volunteer_count,
               COUNT(DISTINCT vs.vehicle_id) as vehicle_count
        FROM units u
        LEFT JOIN volunteer_assignments va ON u.id = va.unit_id AND va.status = 'Active'
        LEFT JOIN vehicle_status vs ON u.id = vs.unit_id AND vs.status = 'available'
        WHERE u.status = 'active'
          AND u.current_status = 'available'
          AND u.id NOT IN (
              SELECT DISTINCT unit_id 
              FROM dispatch_incidents 
              WHERE status = 'pending'
          )
        GROUP BY u.id
    ";
    
    $available_units_stmt = $pdo->query($available_units_query);
    $available_units = $available_units_stmt->fetchAll();
    
    $suggested_units = [];
    foreach ($available_units as $unit) {
        $score = calculateSuggestionScore($unit, $incident);
        
        // Get sample volunteers for display
        $volunteers_query = "
            SELECT v.full_name, v.contact_number, v.email,
                   v.skills_basic_firefighting, v.skills_first_aid_cpr,
                   v.skills_search_rescue, v.skills_driving
            FROM volunteer_assignments va
            JOIN volunteers v ON va.volunteer_id = v.id
            WHERE va.unit_id = ? AND v.status = 'approved' AND va.status = 'Active'
            LIMIT 3
        ";
        $volunteers_stmt = $pdo->prepare($volunteers_query);
        $volunteers_stmt->execute([$unit['id']]);
        $sample_volunteers = $volunteers_stmt->fetchAll();
        
        // Get available vehicles for this unit (excluding suggested ones)
        $vehicles_query = "
            SELECT vs.*
            FROM vehicle_status vs
            WHERE vs.unit_id = ? AND vs.status = 'available'
            LIMIT 3
        ";
        $vehicles_stmt = $pdo->prepare($vehicles_query);
        $vehicles_stmt->execute([$unit['id']]);
        $sample_vehicles = $vehicles_stmt->fetchAll();
        
        $suggested_units[] = [
            'unit_id' => $unit['id'],
            'unit_name' => $unit['unit_name'],
            'unit_code' => $unit['unit_code'],
            'unit_type' => $unit['unit_type'],
            'unit_location' => $unit['location'],
            'suggestion_score' => $score,
            'recommendation_level' => getRecommendationLevel($score),
            'volunteer_count' => $unit['volunteer_count'] ?? 0,
            'vehicle_count' => $unit['vehicle_count'] ?? 0,
            'sample_volunteers' => $sample_volunteers,
            'sample_vehicles' => $sample_vehicles,
            'readiness_score' => calculateReadinessScore($unit, $sample_volunteers, $sample_vehicles)
        ];
    }
    
    usort($suggested_units, function($a, $b) {
        return $b['suggestion_score'] <=> $a['suggestion_score'];
    });
    
    return array_slice($suggested_units, 0, 5);
}

function getAllAvailableUnits($pdo, $incident) {
    $units_query = "
        SELECT u.*, 
               COUNT(DISTINCT va.volunteer_id) as volunteer_count,
               COUNT(DISTINCT vs.vehicle_id) as available_vehicle_count
        FROM units u
        LEFT JOIN volunteer_assignments va ON u.id = va.unit_id AND va.status = 'Active'
        LEFT JOIN vehicle_status vs ON u.id = vs.unit_id AND vs.status = 'available'
        WHERE u.status = 'active'
          AND u.current_status = 'available'
          AND u.id NOT IN (
              SELECT DISTINCT unit_id 
              FROM dispatch_incidents 
              WHERE status = 'pending'
          )
        GROUP BY u.id
        ORDER BY u.unit_type, u.unit_name
    ";
    
    $units_stmt = $pdo->query($units_query);
    $units = $units_stmt->fetchAll();
    
    foreach ($units as &$unit) {
        // Get all volunteers in this unit
        $volunteers_query = "
            SELECT v.id, v.full_name, v.contact_number, v.email,
                   v.skills_basic_firefighting, v.skills_first_aid_cpr,
                   v.skills_search_rescue, v.skills_driving, v.skills_communication,
                   v.available_days, v.available_hours,
                   v.volunteer_status
            FROM volunteer_assignments va
            JOIN volunteers v ON va.volunteer_id = v.id
            WHERE va.unit_id = ? 
              AND v.status = 'approved' 
              AND va.status = 'Active'
            ORDER BY v.full_name
        ";
        
        $volunteers_stmt = $pdo->prepare($volunteers_query);
        $volunteers_stmt->execute([$unit['id']]);
        $unit['all_volunteers'] = $volunteers_stmt->fetchAll();
        
        // Get all available vehicles for this unit (excluding suggested ones)
        $vehicles_query = "
            SELECT vs.*
            FROM vehicle_status vs
            WHERE vs.unit_id = ? AND vs.status = 'available'
        ";
        
        $vehicles_stmt = $pdo->prepare($vehicles_query);
        $vehicles_stmt->execute([$unit['id']]);
        $unit['all_vehicles'] = $vehicles_stmt->fetchAll();
        
        // Calculate match score for this incident
        $unit['match_score'] = calculateSuggestionScore($unit, $incident);
        $unit['recommendation_level'] = getRecommendationLevel($unit['match_score']);
        $unit['readiness_score'] = calculateReadinessScore($unit, $unit['all_volunteers'], $unit['all_vehicles']);
        
        // Unit is available (not suggested or dispatched)
        $unit['is_available'] = true;
        $unit['has_pending_suggestion'] = false;
    }
    
    return $units;
}

/**
 * NOTIFICATION FUNCTIONS
 * REMOVED: SMS notifications
 * KEPT: Dashboard notifications + Email notifications
 */
function sendEmailNotification($pdo, $email, $subject, $body) {
    // Implement email sending logic
    // Example: PHPMailer, SendGrid, etc.
    // This is a placeholder - you'll need to implement actual email sending
    
    try {
        // Log the email attempt
        $email_log_query = "INSERT INTO email_logs (recipient, subject, body, status, sent_at) 
                           VALUES (?, ?, ?, 'sent', NOW())";
        $email_stmt = $pdo->prepare($email_log_query);
        $email_stmt->execute([$email, $subject, $body]);
        
        // For demo purposes, we'll just log it
        error_log("Email to {$email}: {$subject}");
        
        return true;
    } catch (Exception $e) {
        error_log("Failed to send email: " . $e->getMessage());
        return false;
    }
}

function notifyUnitVolunteers($pdo, $unit_id, $unit_name, $incident_title, $incident_location) {
    try {
        // Get all active volunteers in this unit
        $volunteers_query = "
            SELECT v.id, v.full_name, v.email, v.contact_number, u.id as user_id
            FROM volunteer_assignments va
            JOIN volunteers v ON va.volunteer_id = v.id
            LEFT JOIN users u ON v.email = u.email
            WHERE va.unit_id = ? 
              AND v.status = 'approved' 
              AND va.status = 'Active'
        ";
        
        $volunteers_stmt = $pdo->prepare($volunteers_query);
        $volunteers_stmt->execute([$unit_id]);
        $unit_volunteers = $volunteers_stmt->fetchAll();
        
        $notification_count = 0;
        $email_count = 0;
        
        foreach ($unit_volunteers as $volunteer) {
            $user_id = $volunteer['user_id'] ?? 0;
            $volunteer_name = $volunteer['full_name'] ?? 'Volunteer';
            $email = $volunteer['email'] ?? '';
            
            // Create notification message for dashboard
            $notification_title = "Unit Dispatch Notification";
            $notification_message = "Your unit {$unit_name} has been dispatched to incident: {$incident_title} at {$incident_location}. Please report to your unit immediately.";
            
            // 1. Store in notifications table (DASHBOARD NOTIFICATION - if user exists)
            if ($user_id > 0) {
                $notification_query = "
                    INSERT INTO notifications (user_id, type, title, message, is_read, created_at)
                    VALUES (?, 'dispatch', ?, ?, 0, NOW())
                ";
                
                $notification_stmt = $pdo->prepare($notification_query);
                $notification_stmt->execute([
                    $user_id, 
                    $notification_title, 
                    $notification_message
                ]);
                $notification_count++;
            }
            
            // 2. Send Email notification
            if (!empty($email)) {
                $email_subject = "Emergency Dispatch Notification - {$unit_name}";
                $email_body = "Dear {$volunteer_name},\n\n";
                $email_body .= "Your unit {$unit_name} has been dispatched to an emergency incident:\n\n";
                $email_body .= "Incident: {$incident_title}\n";
                $email_body .= "Location: {$incident_location}\n";
                $email_body .= "Status: DISPATCHED\n\n";
                $email_body .= "Please report to your unit immediately for deployment.\n\n";
                $email_body .= "You can also check your volunteer dashboard for updates.\n\n";
                $email_body .= "This is an automated dispatch notification.\n";
                
                sendEmailNotification($pdo, $email, $email_subject, $email_body);
                $email_count++;
            }
        }
        
        return [
            'success' => true,
            'dashboard_notifications_sent' => $notification_count,
            'emails_sent' => $email_count,
            'total_volunteers' => count($unit_volunteers)
        ];
        
    } catch (Exception $e) {
        error_log("Failed to notify volunteers: " . $e->getMessage());
        return [
            'success' => false,
            'error' => $e->getMessage()
        ];
    }
}

// Main Request Handling
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $action = $_GET['action'] ?? 'dashboard';
    $incident_id = $_GET['incident_id'] ?? null;
    $dispatch_id = $_GET['dispatch_id'] ?? null;
    $suggestion_id = $_GET['suggestion_id'] ?? null;
    
    switch ($action) {
        
        // 1. DASHBOARD - See all units with AI suggestions
        case 'dashboard':
        case 'units':
            $emergency_type = $_GET['emergency_type'] ?? null;
            $severity = $_GET['severity'] ?? null;
            $location = $_GET['location'] ?? null;
            
            // Get all units (including suggested/dispatched ones for admin view)
            $query = "
                SELECT u.*, 
                       COUNT(DISTINCT va.volunteer_id) as volunteer_count,
                       COUNT(DISTINCT vs.vehicle_id) as vehicle_count,
                       d.status as current_dispatch_status,
                       ai.title as current_incident_title
                FROM units u
                LEFT JOIN volunteer_assignments va ON u.id = va.unit_id AND va.status = 'Active'
                LEFT JOIN vehicle_status vs ON u.id = vs.unit_id AND vs.status = 'available'
                LEFT JOIN dispatch_incidents d ON u.current_dispatch_id = d.id 
                    AND d.status IN ('dispatched', 'en_route', 'arrived')
                LEFT JOIN api_incidents ai ON d.incident_id = ai.id
                WHERE u.status = 'active'
                GROUP BY u.id
                ORDER BY u.unit_type, u.unit_name
            ";
            
            $stmt = $pdo->query($query);
            $units = $stmt->fetchAll();
            
            // Get volunteers for each unit
            foreach ($units as &$unit) {
                // Get volunteers with their skills
                $volunteers_query = "
                    SELECT v.id, v.full_name, v.contact_number, v.email,
                           v.skills_basic_firefighting, v.skills_first_aid_cpr,
                           v.skills_search_rescue, v.skills_driving, v.skills_communication,
                           v.available_days, v.available_hours,
                           v.volunteer_status
                    FROM volunteer_assignments va
                    JOIN volunteers v ON va.volunteer_id = v.id
                    WHERE va.unit_id = ? 
                      AND v.status = 'approved' 
                      AND va.status = 'Active'
                    ORDER BY v.full_name
                ";
                
                $volunteers_stmt = $pdo->prepare($volunteers_query);
                $volunteers_stmt->execute([$unit['id']]);
                $unit['volunteers'] = $volunteers_stmt->fetchAll();
                
                // Get available vehicles (NOT including suggested ones)
                $vehicles_query = "
                    SELECT vs.*
                    FROM vehicle_status vs
                    WHERE vs.unit_id = ? 
                      AND vs.status = 'available'
                ";
                
                $vehicles_stmt = $pdo->prepare($vehicles_query);
                $vehicles_stmt->execute([$unit['id']]);
                $unit['available_vehicles'] = $vehicles_stmt->fetchAll();
                
                // Get suggested vehicles for this unit
                $suggested_vehicles_query = "
                    SELECT vs.*, di.incident_id, ai.title as incident_title
                    FROM vehicle_status vs
                    LEFT JOIN dispatch_incidents di ON vs.suggestion_id = di.id
                    LEFT JOIN api_incidents ai ON di.incident_id = ai.id
                    WHERE vs.unit_id = ? 
                      AND vs.status = 'suggested'
                      AND di.status = 'pending'
                ";
                
                $suggested_vehicles_stmt = $pdo->prepare($suggested_vehicles_query);
                $suggested_vehicles_stmt->execute([$unit['id']]);
                $unit['suggested_vehicles'] = $suggested_vehicles_stmt->fetchAll();
                
                $unit['readiness_score'] = calculateReadinessScore($unit, $unit['volunteers'], $unit['available_vehicles']);
            }
            
            $response = [
                'success' => true,
                'action' => 'dashboard',
                'units' => $units,
                'total_units' => count($units),
                'timestamp' => date('Y-m-d H:i:s')
            ];
            
            // If incident data is provided, add AI suggestions (only for available units)
            if ($incident_id) {
                $incident_query = "SELECT * FROM api_incidents WHERE id = ?";
                $incident_stmt = $pdo->prepare($incident_query);
                $incident_stmt->execute([$incident_id]);
                $incident = $incident_stmt->fetch();
                
                if ($incident) {
                    $suggestions = getUnitSuggestions($pdo, $incident);
                    $response['suggestions'] = $suggestions;
                    $response['incident'] = $incident;
                    
                    // Get all available units for manual selection (excludes suggested ones)
                    $response['all_available_units'] = getAllAvailableUnits($pdo, $incident);
                }
            }
            
            echo json_encode($response);
            break;
            
        // 2. PENDING SUGGESTIONS - Approval panel
        case 'pending_suggestions':
        case 'approval_panel':
            $status = $_GET['status'] ?? 'pending';
            
            if ($suggestion_id) {
                // Get specific suggestion
                $query = "
                    SELECT di.*, 
                           ai.id as incident_id, ai.title, ai.location, ai.severity, ai.emergency_type,
                           ai.description, ai.caller_name, ai.caller_phone, ai.dispatch_status,
                           u.unit_name, u.unit_code, u.unit_type, u.location as unit_location,
                           u.current_status as unit_status
                    FROM dispatch_incidents di
                    JOIN api_incidents ai ON di.incident_id = ai.id
                    JOIN units u ON di.unit_id = u.id
                    WHERE di.id = ?
                ";
                $stmt = $pdo->prepare($query);
                $stmt->execute([$suggestion_id]);
                $suggestion = $stmt->fetch();
                
                if ($suggestion) {
                    $vehicles = json_decode($suggestion['vehicles_json'], true) ?? [];
                    
                    // Get suggested vehicle details from vehicle_status table
                    $vehicle_details = [];
                    if (!empty($vehicles)) {
                        $vehicle_ids = array_column($vehicles, 'id');
                        $placeholders = implode(',', array_fill(0, count($vehicle_ids), '?'));
                        $vehicles_query = "
                            SELECT vs.* 
                            FROM vehicle_status vs
                            WHERE vs.vehicle_id IN ($placeholders)
                              AND vs.suggestion_id = ?
                        ";
                        $vehicles_stmt = $pdo->prepare($vehicles_query);
                        $params = array_merge($vehicle_ids, [$suggestion_id]);
                        $vehicles_stmt->execute($params);
                        $vehicle_details = $vehicles_stmt->fetchAll();
                    }
                    
                    $volunteers_query = "
                        SELECT v.full_name, v.contact_number, v.email,
                               v.skills_basic_firefighting, v.skills_first_aid_cpr,
                               v.skills_search_rescue, v.skills_driving,
                               v.volunteer_status
                        FROM volunteer_assignments va
                        JOIN volunteers v ON va.volunteer_id = v.id
                        WHERE va.unit_id = ? AND v.status = 'approved' AND va.status = 'Active'
                    ";
                    $volunteers_stmt = $pdo->prepare($volunteers_query);
                    $volunteers_stmt->execute([$suggestion['unit_id']]);
                    $volunteers = $volunteers_stmt->fetchAll();
                    
                    echo json_encode([
                        'success' => true,
                        'action' => 'suggestion_details',
                        'suggestion' => $suggestion,
                        'suggested_vehicles' => $vehicles, // The vehicles that were suggested
                        'vehicle_status_details' => $vehicle_details, // Actual vehicle status from DB
                        'volunteers' => $volunteers,
                        'volunteer_count' => count($volunteers)
                    ]);
                } else {
                    echo json_encode(['success' => false, 'message' => 'Suggestion not found']);
                }
            } else {
                // Get all pending suggestions
                $query = "
                    SELECT di.id, di.status as suggestion_status, di.dispatched_at as suggested_at,
                           ai.id as incident_id, ai.title, ai.location, ai.severity, ai.emergency_type,
                           ai.dispatch_status, ai.created_at as incident_reported,
                           u.unit_name, u.unit_code, u.unit_type,
                           (SELECT COUNT(*) FROM vehicle_status vs WHERE vs.suggestion_id = di.id) as suggested_vehicle_count,
                           (SELECT COUNT(*) FROM volunteer_assignments va 
                            JOIN volunteers v ON va.volunteer_id = v.id 
                            WHERE va.unit_id = u.id AND v.status = 'approved') as volunteer_count
                    FROM dispatch_incidents di
                    JOIN api_incidents ai ON di.incident_id = ai.id
                    JOIN units u ON di.unit_id = u.id
                    WHERE di.status = ? 
                      AND ai.dispatch_status = 'processing'
                    ORDER BY ai.severity DESC, di.dispatched_at DESC
                    LIMIT 50
                ";
                $stmt = $pdo->prepare($query);
                $stmt->execute([$status]);
                $suggestions = $stmt->fetchAll();
                
                echo json_encode([
                    'success' => true,
                    'action' => 'pending_suggestions',
                    'suggestions' => $suggestions,
                    'count' => count($suggestions),
                    'status' => $status,
                    'timestamp' => date('Y-m-d H:i:s')
                ]);
            }
            break;
            
        // 3. ACTIVE DISPATCHES - Live tracker
        case 'active_dispatches':
        case 'tracker':
            if ($dispatch_id) {
                // Get specific active dispatch
                $query = "
                    SELECT di.*, 
                           ai.title, ai.location, ai.severity, ai.emergency_type,
                           ai.description, ai.caller_name, ai.caller_phone,
                           u.unit_name, u.unit_code, u.unit_type, u.location as unit_location,
                           u.current_status as unit_status
                    FROM dispatch_incidents di
                    JOIN api_incidents ai ON di.incident_id = ai.id
                    JOIN units u ON di.unit_id = u.id
                    WHERE di.id = ? 
                      AND di.status IN ('dispatched', 'en_route', 'arrived', 'completed')
                ";
                $stmt = $pdo->prepare($query);
                $stmt->execute([$dispatch_id]);
                $dispatch = $stmt->fetch();
                
                if ($dispatch) {
                    $vehicles = json_decode($dispatch['vehicles_json'], true) ?? [];
                    
                    // Get actual vehicle details
                    $vehicle_ids = array_column($vehicles, 'id');
                    $vehicle_details = [];
                    if (!empty($vehicle_ids)) {
                        $placeholders = implode(',', array_fill(0, count($vehicle_ids), '?'));
                        $vehicles_query = "
                            SELECT vs.* 
                            FROM vehicle_status vs
                            WHERE vs.vehicle_id IN ($placeholders)
                        ";
                        $vehicles_stmt = $pdo->prepare($vehicles_query);
                        $vehicles_stmt->execute($vehicle_ids);
                        $vehicle_details = $vehicles_stmt->fetchAll();
                    }
                    
                    $volunteers_query = "
                        SELECT v.full_name, v.contact_number, v.email,
                               v.skills_basic_firefighting, v.skills_first_aid_cpr,
                               v.skills_search_rescue, v.skills_driving,
                               v.volunteer_status
                        FROM volunteer_assignments va
                        JOIN volunteers v ON va.volunteer_id = v.id
                        WHERE va.unit_id = ? AND v.status = 'approved' AND va.status = 'Active'
                    ";
                    $volunteers_stmt = $pdo->prepare($volunteers_query);
                    $volunteers_stmt->execute([$dispatch['unit_id']]);
                    $volunteers = $volunteers_stmt->fetchAll();
                    
                    echo json_encode([
                        'success' => true,
                        'action' => 'dispatch_details',
                        'dispatch' => $dispatch,
                        'vehicles' => $vehicles,
                        'vehicle_details' => $vehicle_details,
                        'volunteers' => $volunteers,
                        'dispatch_time' => $dispatch['dispatched_at']
                    ]);
                } else {
                    echo json_encode(['success' => false, 'message' => 'Dispatch not found']);
                }
            } else {
                // Get all active dispatches
                $query = "
                    SELECT di.id, di.status, di.dispatched_at, di.status_updated_at,
                           ai.title, ai.location, ai.severity, ai.emergency_type,
                           ai.dispatch_status as incident_status,
                           u.unit_name, u.unit_code, u.unit_type,
                           (SELECT COUNT(*) FROM vehicle_status vs WHERE vs.dispatch_id = di.id) as vehicle_count
                    FROM dispatch_incidents di
                    JOIN api_incidents ai ON di.incident_id = ai.id
                    JOIN units u ON di.unit_id = u.id
                    WHERE di.status IN ('dispatched', 'en_route', 'arrived', 'completed')
                      AND di.dispatched_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
                    ORDER BY 
                        CASE di.status 
                            WHEN 'dispatched' THEN 1
                            WHEN 'en_route' THEN 2
                            WHEN 'arrived' THEN 3
                            WHEN 'completed' THEN 4
                            ELSE 5
                        END,
                        di.dispatched_at DESC
                    LIMIT 50
                ";
                $stmt = $pdo->query($query);
                $dispatches = $stmt->fetchAll();
                
                echo json_encode([
                    'success' => true,
                    'action' => 'active_dispatches',
                    'dispatches' => $dispatches,
                    'count' => count($dispatches),
                    'timestamp' => date('Y-m-d H:i:s')
                ]);
            }
            break;
            
        // 4. GET ALL UNITS FOR MANUAL SELECTION
        case 'all_units':
            $incident_id = $_GET['incident_id'] ?? null;
            
            if ($incident_id) {
                $incident_query = "SELECT * FROM api_incidents WHERE id = ?";
                $incident_stmt = $pdo->prepare($incident_query);
                $incident_stmt->execute([$incident_id]);
                $incident = $incident_stmt->fetch();
                
                if ($incident) {
                    $all_units = getAllAvailableUnits($pdo, $incident);
                    
                    echo json_encode([
                        'success' => true,
                        'action' => 'all_units',
                        'units' => $all_units,
                        'incident' => $incident,
                        'count' => count($all_units)
                    ]);
                } else {
                    echo json_encode(['success' => false, 'message' => 'Incident not found']);
                }
            } else {
                echo json_encode(['success' => false, 'message' => 'Missing incident_id']);
            }
            break;
            
        // 5. GET SUGGESTED VEHICLES FOR A UNIT
        case 'suggested_vehicles':
            $unit_id = $_GET['unit_id'] ?? null;
            
            if (!$unit_id) {
                echo json_encode(['success' => false, 'message' => 'Missing unit_id']);
                break;
            }
            
            // Get suggested vehicles for this unit
            $query = "
                SELECT vs.*, 
                       di.id as suggestion_id,
                       di.status as suggestion_status,
                       di.dispatched_at as suggested_at,
                       ai.id as incident_id,
                       ai.title as incident_title,
                       ai.severity as incident_severity
                FROM vehicle_status vs
                LEFT JOIN dispatch_incidents di ON vs.suggestion_id = di.id
                LEFT JOIN api_incidents ai ON di.incident_id = ai.id
                WHERE vs.unit_id = ? 
                  AND vs.status = 'suggested'
                  AND di.status = 'pending'
                ORDER BY di.dispatched_at DESC
            ";
            
            $stmt = $pdo->prepare($query);
            $stmt->execute([$unit_id]);
            $suggested_vehicles = $stmt->fetchAll();
            
            echo json_encode([
                'success' => true,
                'action' => 'suggested_vehicles',
                'unit_id' => $unit_id,
                'suggested_vehicles' => $suggested_vehicles,
                'count' => count($suggested_vehicles),
                'timestamp' => date('Y-m-d H:i:s')
            ]);
            break;
            
        // 6. GET ALL SUGGESTED VEHICLES IN THE SYSTEM
        case 'all_suggested_vehicles':
            $query = "
                SELECT vs.*, 
                       u.id as unit_id,
                       u.unit_name,
                       u.unit_code,
                       u.unit_type,
                       di.id as suggestion_id,
                       di.status as suggestion_status,
                       di.dispatched_at as suggested_at,
                       ai.id as incident_id,
                       ai.title as incident_title,
                       ai.severity as incident_severity,
                       ai.emergency_type
                FROM vehicle_status vs
                LEFT JOIN units u ON vs.unit_id = u.id
                LEFT JOIN dispatch_incidents di ON vs.suggestion_id = di.id
                LEFT JOIN api_incidents ai ON di.incident_id = ai.id
                WHERE vs.status = 'suggested'
                  AND di.status = 'pending'
                ORDER BY u.unit_name, di.dispatched_at DESC
            ";
            
            $stmt = $pdo->query($query);
            $all_suggested_vehicles = $stmt->fetchAll();
            
            // Group by unit for better organization
            $grouped_vehicles = [];
            foreach ($all_suggested_vehicles as $vehicle) {
                $unit_id = $vehicle['unit_id'];
                if (!isset($grouped_vehicles[$unit_id])) {
                    $grouped_vehicles[$unit_id] = [
                        'unit_id' => $unit_id,
                        'unit_name' => $vehicle['unit_name'],
                        'unit_code' => $vehicle['unit_code'],
                        'unit_type' => $vehicle['unit_type'],
                        'vehicles' => []
                    ];
                }
                $grouped_vehicles[$unit_id]['vehicles'][] = $vehicle;
            }
            
            echo json_encode([
                'success' => true,
                'action' => 'all_suggested_vehicles',
                'total_suggested_vehicles' => count($all_suggested_vehicles),
                'grouped_vehicles' => array_values($grouped_vehicles),
                'flat_list' => $all_suggested_vehicles,
                'timestamp' => date('Y-m-d H:i:s')
            ]);
            break;
            
        default:
            echo json_encode(['success' => false, 'message' => 'Invalid action']);
    }
    
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true);
    $action = $data['action'] ?? null;
    
    switch ($action) {
        
        // A. CREATE SUGGESTION
        case 'create_suggestion':
            $incident_id = $data['incident_id'] ?? null;
            $unit_id = $data['unit_id'] ?? null;
            $suggested_by = $data['suggested_by'] ?? null;
            $vehicles = $data['vehicles'] ?? [];
            $notes = $data['notes'] ?? '';
            
            if (!$incident_id || !$unit_id) {
                echo json_encode(['success' => false, 'message' => 'Missing incident_id or unit_id']);
                exit();
            }
            
            try {
                $pdo->beginTransaction();
                
                // Check unit availability - must be available and not already suggested
                $unit_check = "
                    SELECT u.current_status, 
                           (SELECT COUNT(*) FROM dispatch_incidents di 
                            WHERE di.unit_id = u.id AND di.status = 'pending') as pending_suggestions
                    FROM units u
                    WHERE u.id = ? AND u.status = 'active'
                ";
                $unit_stmt = $pdo->prepare($unit_check);
                $unit_stmt->execute([$unit_id]);
                $unit_data = $unit_stmt->fetch();
                
                if (!$unit_data) {
                    throw new Exception('Unit not found');
                }
                
                if ($unit_data['current_status'] !== 'available' || $unit_data['pending_suggestions'] > 0) {
                    throw new Exception('Unit is not available (already suggested or dispatched)');
                }
                
                // Check incident status
                $incident_check = "SELECT dispatch_status FROM api_incidents WHERE id = ?";
                $incident_stmt = $pdo->prepare($incident_check);
                $incident_stmt->execute([$incident_id]);
                $incident_status = $incident_stmt->fetchColumn();
                
                if (!$incident_status || $incident_status !== 'for_dispatch') {
                    throw new Exception('Incident is not ready for dispatch');
                }
                
                // Check each vehicle is REALLY available (not suggested or dispatched)
                foreach ($vehicles as $vehicle) {
                    $vehicle_check = "
                        SELECT vehicle_id, status 
                        FROM vehicle_status 
                        WHERE vehicle_id = ? 
                        AND status IN ('available')
                    ";
                    $vehicle_stmt = $pdo->prepare($vehicle_check);
                    $vehicle_stmt->execute([$vehicle['id']]);
                    $vehicle_data = $vehicle_stmt->fetch();
                    
                    // If vehicle doesn't exist in database, create it as available first
                    if (!$vehicle_data) {
                        // Create vehicle record
                        $create_vehicle = "
                            INSERT INTO vehicle_status 
                            (vehicle_id, vehicle_name, vehicle_type, unit_id, status, last_updated)
                            VALUES (?, ?, ?, ?, 'available', NOW())
                        ";
                        $create_stmt = $pdo->prepare($create_vehicle);
                        $create_stmt->execute([
                            $vehicle['id'],
                            $vehicle['vehicle_name'] ?? 'Unknown',
                            $vehicle['type'] ?? 'Unknown',
                            $unit_id
                        ]);
                    } 
                    // If vehicle exists but NOT available
                    elseif ($vehicle_data['status'] !== 'available') {
                        throw new Exception("Vehicle {$vehicle['id']} is not available (status: {$vehicle_data['status']})");
                    }
                }
                
                // Create suggestion
                $insert_query = "
                    INSERT INTO dispatch_incidents 
                    (incident_id, unit_id, status, dispatched_at, dispatched_by, vehicles_json, er_notes)
                    VALUES (?, ?, 'pending', NOW(), ?, ?, ?)
                ";
                
                $vehicles_json = json_encode($vehicles);
                $insert_stmt = $pdo->prepare($insert_query);
                $insert_stmt->execute([$incident_id, $unit_id, $suggested_by, $vehicles_json, $notes]);
                
                $suggestion_id = $pdo->lastInsertId();
                
                // Update incident
                $update_incident = "
                    UPDATE api_incidents 
                    SET dispatch_status = 'processing',
                        dispatch_id = ?
                    WHERE id = ?
                ";
                $update_stmt = $pdo->prepare($update_incident);
                $update_stmt->execute([$suggestion_id, $incident_id]);
                
                // Update unit status to 'suggested' (NOT 'dispatched')
                $update_unit = "
                    UPDATE units 
                    SET current_status = 'suggested',
                        current_dispatch_id = ?
                    WHERE id = ?
                ";
                $unit_update_stmt = $pdo->prepare($update_unit);
                $unit_update_stmt->execute([$suggestion_id, $unit_id]);
                
                // Mark vehicles as 'suggested' - they are now reserved
                foreach ($vehicles as $vehicle) {
                    $update_vehicle = "
                        UPDATE vehicle_status 
                        SET status = 'suggested',
                            suggestion_id = ?,
                            last_updated = NOW()
                        WHERE vehicle_id = ?
                    ";
                    $vehicle_stmt = $pdo->prepare($update_vehicle);
                    $vehicle_stmt->execute([$suggestion_id, $vehicle['id']]);
                }
                
                $pdo->commit();
                
                echo json_encode([
                    'success' => true,
                    'message' => 'Suggestion created - unit and vehicles are now reserved',
                    'suggestion_id' => $suggestion_id,
                    'created_at' => date('Y-m-d H:i:s'),
                    'vehicle_count' => count($vehicles)
                ]);
                
            } catch (Exception $e) {
                $pdo->rollBack();
                echo json_encode([
                    'success' => false,
                    'message' => 'Failed to create suggestion: ' . $e->getMessage()
                ]);
            }
            break;
            
        // B. APPROVE/REJECT SUGGESTION
        case 'approve_suggestion':
        case 'reject_suggestion':
            $suggestion_id = $data['suggestion_id'] ?? null;
            $er_notes = $data['notes'] ?? null;
            $er_user_id = $data['er_user_id'] ?? null;
            
            if (!$suggestion_id) {
                echo json_encode(['success' => false, 'message' => 'Missing suggestion_id']);
                exit();
            }
            
            $is_approve = ($action === 'approve_suggestion');
            
            try {
                $pdo->beginTransaction();
                
                // Get suggestion details
                $suggestion_query = "
                    SELECT di.*, ai.id as incident_id, ai.title, ai.location, u.id as unit_id, u.unit_name
                    FROM dispatch_incidents di
                    JOIN api_incidents ai ON di.incident_id = ai.id
                    JOIN units u ON di.unit_id = u.id
                    WHERE di.id = ?
                ";
                $suggestion_stmt = $pdo->prepare($suggestion_query);
                $suggestion_stmt->execute([$suggestion_id]);
                $suggestion = $suggestion_stmt->fetch();
                
                if (!$suggestion) {
                    throw new Exception('Suggestion not found');
                }
                
                if ($is_approve) {
                    // APPROVE: Make it an actual dispatch
                    $update_query = "
                        UPDATE dispatch_incidents 
                        SET status = 'dispatched',
                            er_notes = COALESCE(?, er_notes),
                            status_updated_at = NOW()
                        WHERE id = ?
                    ";
                    $update_stmt = $pdo->prepare($update_query);
                    $update_stmt->execute([$er_notes, $suggestion_id]);
                    
                    // Update incident
                    $update_incident = "
                        UPDATE api_incidents 
                        SET dispatch_status = 'processing',
                            status = 'processing',
                            responded_at = NOW(),
                            responded_by = ?
                        WHERE id = ?
                    ";
                    $update_incident_stmt = $pdo->prepare($update_incident);
                    $update_incident_stmt->execute([$er_user_id, $suggestion['incident_id']]);
                    
                    // Update unit to 'dispatched'
                    $update_unit = "
                        UPDATE units 
                        SET current_status = 'dispatched',
                            current_dispatch_id = ?,
                            last_status_change = NOW()
                        WHERE id = ?
                    ";
                    $update_unit_stmt = $pdo->prepare($update_unit);
                    $update_unit_stmt->execute([$suggestion_id, $suggestion['unit_id']]);
                    
                    // Mark suggested vehicles as 'dispatched'
                    $vehicle_query = "
                        UPDATE vehicle_status 
                        SET status = 'dispatched',
                            dispatch_id = ?,
                            last_updated = NOW()
                        WHERE suggestion_id = ?
                    ";
                    $vehicle_stmt = $pdo->prepare($vehicle_query);
                    $vehicle_stmt->execute([$suggestion_id, $suggestion_id]);
                    
                    // NOTIFY ALL VOLUNTEERS IN THE UNIT (Dashboard + Email only)
                    $notification_result = notifyUnitVolunteers(
                        $pdo,
                        $suggestion['unit_id'],
                        $suggestion['unit_name'],
                        $suggestion['title'],
                        $suggestion['location']
                    );
                    
                    $message = 'Suggestion approved and dispatched';
                    $new_status = 'dispatched';
                    $dashboard_notifications = $notification_result['dashboard_notifications_sent'] ?? 0;
                    $emails_sent = $notification_result['emails_sent'] ?? 0;
                    
                } else {
                    // REJECT: Cancel the suggestion
                    $update_query = "
                        UPDATE dispatch_incidents 
                        SET status = 'cancelled',
                            er_notes = COALESCE(?, er_notes),
                            status_updated_at = NOW()
                        WHERE id = ?
                    ";
                    $update_stmt = $pdo->prepare($update_query);
                    $update_stmt->execute([$er_notes, $suggestion_id]);
                    
                    // Reset incident to 'for_dispatch'
                    $update_incident = "
                        UPDATE api_incidents 
                        SET dispatch_status = 'for_dispatch',
                            dispatch_id = NULL,
                            status = 'pending'
                        WHERE id = ?
                    ";
                    $update_incident_stmt = $pdo->prepare($update_incident);
                    $update_incident_stmt->execute([$suggestion['incident_id']]);
                    
                    // Reset unit to 'available'
                    $update_unit = "
                        UPDATE units 
                        SET current_status = 'available',
                            current_dispatch_id = NULL,
                            last_status_change = NOW()
                        WHERE id = ?
                    ";
                    $update_unit_stmt = $pdo->prepare($update_unit);
                    $update_unit_stmt->execute([$suggestion['unit_id']]);
                    
                    // Reset suggested vehicles back to 'available'
                    $reset_vehicles = "
                        UPDATE vehicle_status 
                        SET status = 'available',
                            dispatch_id = NULL,
                            suggestion_id = NULL,
                            last_updated = NOW()
                        WHERE suggestion_id = ?
                    ";
                    $reset_vehicles_stmt = $pdo->prepare($reset_vehicles);
                    $reset_vehicles_stmt->execute([$suggestion_id]);
                    
                    $message = 'Suggestion rejected - unit and vehicles are available again';
                    $new_status = 'cancelled';
                    $dashboard_notifications = 0;
                    $emails_sent = 0;
                }
                
                $pdo->commit();
                
                echo json_encode([
                    'success' => true,
                    'message' => $message,
                    'suggestion_id' => $suggestion_id,
                    'new_status' => $new_status,
                    'dashboard_notifications_sent' => $dashboard_notifications,
                    'emails_sent' => $emails_sent,
                    'updated_at' => date('Y-m-d H:i:s')
                ]);
                
            } catch (Exception $e) {
                $pdo->rollBack();
                echo json_encode([
                    'success' => false,
                    'message' => 'Failed: ' . $e->getMessage()
                ]);
            }
            break;
            
        // C. UPDATE DISPATCH STATUS
        case 'update_dispatch':
            $dispatch_id = $data['dispatch_id'] ?? null;
            $status = $data['status'] ?? null;
            $notes = $data['notes'] ?? null;
            
            if (!$dispatch_id || !$status) {
                echo json_encode(['success' => false, 'message' => 'Missing dispatch_id or status']);
                exit();
            }
            
            $valid_statuses = ['en_route', 'arrived', 'completed'];
            if (!in_array($status, $valid_statuses)) {
                echo json_encode(['success' => false, 'message' => 'Invalid status']);
                exit();
            }
            
            try {
                // Verify it's an active dispatch
                $check_query = "SELECT status FROM dispatch_incidents WHERE id = ?";
                $check_stmt = $pdo->prepare($check_query);
                $check_stmt->execute([$dispatch_id]);
                $current_status = $check_stmt->fetchColumn();
                
                if (!$current_status || $current_status === 'pending') {
                    throw new Exception('This is not an active dispatch');
                }
                
                // Update dispatch status
                $update_query = "
                    UPDATE dispatch_incidents 
                    SET status = ?, 
                        status_updated_at = NOW(),
                        er_notes = CONCAT_WS('\n', COALESCE(er_notes, ''), ?)
                    WHERE id = ?
                ";
                $update_stmt = $pdo->prepare($update_query);
                $update_stmt->execute([$status, date('H:i') . ' - ' . $notes, $dispatch_id]);
                
                // If completed, close incident and free resources
                if ($status === 'completed') {
                    $incident_query = "SELECT incident_id, unit_id FROM dispatch_incidents WHERE id = ?";
                    $incident_stmt = $pdo->prepare($incident_query);
                    $incident_stmt->execute([$dispatch_id]);
                    $dispatch_data = $incident_stmt->fetch();
                    
                    if ($dispatch_data) {
                        // Close incident
                        $update_incident = "
                            UPDATE api_incidents 
                            SET dispatch_status = 'closed',
                                status = 'closed',
                                resolved_at = NOW()
                            WHERE id = ?
                        ";
                        $update_incident_stmt = $pdo->prepare($update_incident);
                        $update_incident_stmt->execute([$dispatch_data['incident_id']]);
                        
                        // Free unit - set back to 'available'
                        $update_unit = "
                            UPDATE units 
                            SET current_status = 'available',
                                current_dispatch_id = NULL,
                                last_status_change = NOW()
                            WHERE id = ?
                        ";
                        $update_unit_stmt = $pdo->prepare($update_unit);
                        $update_unit_stmt->execute([$dispatch_data['unit_id']]);
                        
                        // Free vehicles - set back to 'available'
                        $reset_vehicles = "
                            UPDATE vehicle_status 
                            SET status = 'available',
                                dispatch_id = NULL,
                                suggestion_id = NULL,
                                last_updated = NOW()
                            WHERE dispatch_id = ?
                        ";
                        $reset_vehicles_stmt = $pdo->prepare($reset_vehicles);
                        $reset_vehicles_stmt->execute([$dispatch_id]);
                    }
                }
                
                echo json_encode([
                    'success' => true,
                    'message' => 'Dispatch status updated',
                    'dispatch_id' => $dispatch_id,
                    'new_status' => $status,
                    'updated_at' => date('Y-m-d H:i:s')
                ]);
                
            } catch (Exception $e) {
                echo json_encode([
                    'success' => false,
                    'message' => 'Update failed: ' . $e->getMessage()
                ]);
            }
            break;
            
        default:
            echo json_encode(['success' => false, 'message' => 'Invalid action']);
    }
}
?>