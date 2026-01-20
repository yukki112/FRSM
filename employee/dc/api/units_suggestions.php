<?php
// api/units_suggestions.php - For getting all units with suggestions and volunteer info
require_once '../../../config/db_connection.php';
header('Content-Type: application/json');
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, X-API-Key");

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// API key authentication
$api_key = $_SERVER['HTTP_X_API_KEY'] ?? $_GET['api_key'] ?? '';
$valid_api_key = 'YUKKIAPIKEY';

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $incident_id = $_GET['incident_id'] ?? null;
    $emergency_type = $_GET['emergency_type'] ?? null;
    $severity = $_GET['severity'] ?? null;
    $location = $_GET['location'] ?? null;
    $get_suggestions = $_GET['get_suggestions'] ?? 'true';
    
    // Get all units
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
        $volunteers_query = "
            SELECT v.id, v.full_name, v.contact_number, v.email,
                   v.skills_basic_firefighting, v.skills_first_aid_cpr,
                   v.skills_search_rescue, v.skills_driving, v.skills_communication,
                   v.available_days, v.available_hours, v.availability_status,
                   va.role_in_unit, va.assigned_since
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
        
        // Get available vehicles for this unit
        $vehicles_query = "
            SELECT vs.*, vd.vehicle_type, vd.vehicle_name, vd.capacity
            FROM vehicle_status vs
            JOIN vehicle_details vd ON vs.vehicle_id = vd.id
            WHERE vs.unit_id = ? 
              AND vs.status = 'available'
              AND vd.status = 'active'
        ";
        
        $vehicles_stmt = $pdo->prepare($vehicles_query);
        $vehicles_stmt->execute([$unit['id']]);
        $unit['available_vehicles'] = $vehicles_stmt->fetchAll();
        
        // Calculate unit readiness score
        $unit['readiness_score'] = calculateReadinessScore($unit, $unit['volunteers'], $unit['available_vehicles']);
    }
    
    // If incident data is provided, suggest best units
    $suggestions = [];
    if ($incident_id && $get_suggestions === 'true') {
        // Get incident details if incident_id is provided
        if ($incident_id) {
            $incident_query = "
                SELECT ai.*, 
                       al.latitude, al.longitude
                FROM api_incidents ai
                LEFT JOIN api_locations al ON ai.location_id = al.id
                WHERE ai.id = ?
            ";
            $incident_stmt = $pdo->prepare($incident_query);
            $incident_stmt->execute([$incident_id]);
            $incident = $incident_stmt->fetch();
            
            if ($incident) {
                $emergency_type = $incident['emergency_type'];
                $severity = $incident['severity'];
                $location = $incident['location'];
                
                // Get all available units (not currently dispatched)
                $available_units_query = "
                    SELECT u.*, 
                           COUNT(DISTINCT va.volunteer_id) as volunteer_count,
                           COUNT(DISTINCT vs.vehicle_id) as vehicle_count,
                           al.latitude as unit_lat, al.longitude as unit_lng
                    FROM units u
                    LEFT JOIN volunteer_assignments va ON u.id = va.unit_id AND va.status = 'Active'
                    LEFT JOIN vehicle_status vs ON u.id = vs.unit_id AND vs.status = 'available'
                    LEFT JOIN api_locations al ON u.location_id = al.id
                    WHERE u.status = 'active'
                      AND u.current_status = 'available'
                    GROUP BY u.id
                ";
                
                $available_units_stmt = $pdo->query($available_units_query);
                $available_units = $available_units_stmt->fetchAll();
                
                // Calculate suggestion scores for each unit
                $suggested_units = [];
                foreach ($available_units as $unit) {
                    $score = calculateUnitSuggestionScore($unit, $incident);
                    
                    // Get volunteers for this unit (for display in suggestion)
                    $volunteers_for_suggestion_query = "
                        SELECT v.full_name, v.contact_number,
                               v.skills_basic_firefighting, v.skills_first_aid_cpr,
                               v.skills_search_rescue, v.skills_driving
                        FROM volunteer_assignments va
                        JOIN volunteers v ON va.volunteer_id = v.id
                        WHERE va.unit_id = ? AND v.status = 'approved' AND va.status = 'Active'
                        LIMIT 5
                    ";
                    
                    $volunteers_for_suggestion_stmt = $pdo->prepare($volunteers_for_suggestion_query);
                    $volunteers_for_suggestion_stmt->execute([$unit['id']]);
                    $unit_volunteers = $volunteers_for_suggestion_stmt->fetchAll();
                    
                    // Get available vehicles
                    $vehicles_for_suggestion_query = "
                        SELECT vd.vehicle_type, vd.vehicle_name, vd.capacity
                        FROM vehicle_status vs
                        JOIN vehicle_details vd ON vs.vehicle_id = vd.id
                        WHERE vs.unit_id = ? AND vs.status = 'available'
                    ";
                    
                    $vehicles_for_suggestion_stmt = $pdo->prepare($vehicles_for_suggestion_query);
                    $vehicles_for_suggestion_stmt->execute([$unit['id']]);
                    $unit_vehicles = $vehicles_for_suggestion_stmt->fetchAll();
                    
                    $suggested_units[] = [
                        'unit_id' => $unit['id'],
                        'unit_name' => $unit['unit_name'],
                        'unit_code' => $unit['unit_code'],
                        'unit_type' => $unit['unit_type'],
                        'location' => $unit['location'],
                        'volunteer_count' => $unit['volunteer_count'],
                        'vehicle_count' => $unit['vehicle_count'],
                        'readiness_score' => $unit['readiness_score'] ?? 0,
                        'suggestion_score' => $score['total_score'],
                        'score_breakdown' => $score['breakdown'],
                        'matching_skills' => $score['matching_skills'],
                        'volunteers_sample' => $unit_volunteers,
                        'available_vehicles' => $unit_vehicles,
                        'estimated_arrival_time' => $score['estimated_arrival'],
                        'recommendation_level' => getRecommendationLevel($score['total_score'])
                    ];
                }
                
                // Sort by suggestion score (highest first)
                usort($suggested_units, function($a, $b) {
                    return $b['suggestion_score'] <=> $a['suggestion_score'];
                });
                
                $suggestions = [
                    'incident_id' => $incident_id,
                    'emergency_type' => $emergency_type,
                    'severity' => $severity,
                    'location' => $location,
                    'suggested_units' => array_slice($suggested_units, 0, 5), // Top 5 suggestions
                    'total_available_units' => count($available_units)
                ];
            }
        }
    } else if ($emergency_type && $severity && $location && $get_suggestions === 'true') {
        // If incident data is provided directly (without incident_id)
        $incident_data = [
            'emergency_type' => $emergency_type,
            'severity' => $severity,
            'location' => $location
        ];
        
        // Get available units
        $available_units_query = "
            SELECT u.*, 
                   COUNT(DISTINCT va.volunteer_id) as volunteer_count,
                   COUNT(DISTINCT vs.vehicle_id) as vehicle_count
            FROM units u
            LEFT JOIN volunteer_assignments va ON u.id = va.unit_id AND va.status = 'Active'
            LEFT JOIN vehicle_status vs ON u.id = vs.unit_id AND vs.status = 'available'
            WHERE u.status = 'active'
              AND u.current_status = 'available'
            GROUP BY u.id
        ";
        
        $available_units_stmt = $pdo->query($available_units_query);
        $available_units = $available_units_stmt->fetchAll();
        
        // Calculate suggestion scores
        $suggested_units = [];
        foreach ($available_units as $unit) {
            $score = calculateUnitSuggestionScore($unit, $incident_data);
            
            $suggested_units[] = [
                'unit_id' => $unit['id'],
                'unit_name' => $unit['unit_name'],
                'unit_code' => $unit['unit_code'],
                'unit_type' => $unit['unit_type'],
                'suggestion_score' => $score['total_score'],
                'score_breakdown' => $score['breakdown'],
                'recommendation_level' => getRecommendationLevel($score['total_score'])
            ];
        }
        
        // Sort by suggestion score
        usort($suggested_units, function($a, $b) {
            return $b['suggestion_score'] <=> $a['suggestion_score'];
        });
        
        $suggestions = [
            'emergency_type' => $emergency_type,
            'severity' => $severity,
            'location' => $location,
            'suggested_units' => array_slice($suggested_units, 0, 5),
            'total_available_units' => count($available_units)
        ];
    }
    
    // Response structure
    $response = [
        'success' => true,
        'units' => $units,
        'total_units' => count($units),
        'timestamp' => date('Y-m-d H:i:s')
    ];
    
    if (!empty($suggestions)) {
        $response['suggestions'] = $suggestions;
    }
    
    echo json_encode($response);
    
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // POST: Create a suggestion for dispatching a unit
    $data = json_decode(file_get_contents('php://input'), true);
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
        
        // Check if unit is available
        $unit_check = "
            SELECT current_status FROM units WHERE id = ? AND status = 'active'
        ";
        $unit_stmt = $pdo->prepare($unit_check);
        $unit_stmt->execute([$unit_id]);
        $unit_status = $unit_stmt->fetchColumn();
        
        if ($unit_status !== 'available') {
            throw new Exception('Unit is not available for dispatch');
        }
        
        // Check if incident exists and is in correct status
        $incident_check = "
            SELECT dispatch_status FROM api_incidents WHERE id = ?
        ";
        $incident_stmt = $pdo->prepare($incident_check);
        $incident_stmt->execute([$incident_id]);
        $incident_status = $incident_stmt->fetchColumn();
        
        if (!$incident_status) {
            throw new Exception('Incident not found');
        }
        
        if ($incident_status !== 'for_dispatch') {
            throw new Exception('Incident is not in dispatchable status');
        }
        
        // Create dispatch suggestion
        $insert_query = "
            INSERT INTO dispatch_incidents 
            (incident_id, unit_id, status, dispatched_at, dispatched_by, vehicles_json, er_notes)
            VALUES (?, ?, 'pending', NOW(), ?, ?, ?)
        ";
        
        $vehicles_json = json_encode($vehicles);
        
        $insert_stmt = $pdo->prepare($insert_query);
        $insert_stmt->execute([$incident_id, $unit_id, $suggested_by, $vehicles_json, $notes]);
        
        $dispatch_id = $pdo->lastInsertId();
        
        // Update incident status
        $update_incident = "
            UPDATE api_incidents 
            SET dispatch_status = 'processing',
                dispatch_id = ?
            WHERE id = ?
        ";
        $update_stmt = $pdo->prepare($update_incident);
        $update_stmt->execute([$dispatch_id, $incident_id]);
        
        // Update unit status
        $update_unit = "
            UPDATE units 
            SET current_status = 'pending_dispatch',
                current_dispatch_id = ?
            WHERE id = ?
        ";
        $unit_update_stmt = $pdo->prepare($update_unit);
        $unit_update_stmt->execute([$dispatch_id, $unit_id]);
        
        // Mark vehicles as "reserved" for this suggestion
        foreach ($vehicles as $vehicle) {
            $vehicle_query = "
                INSERT INTO vehicle_status 
                (vehicle_id, unit_id, dispatch_id, status, last_updated)
                VALUES (?, ?, ?, 'reserved', NOW())
                ON DUPLICATE KEY UPDATE 
                dispatch_id = VALUES(dispatch_id),
                status = VALUES(status),
                last_updated = VALUES(last_updated)
            ";
            $vehicle_stmt = $pdo->prepare($vehicle_query);
            $vehicle_stmt->execute([$vehicle['id'], $unit_id, $dispatch_id]);
        }
        
        $pdo->commit();
        
        // Get created suggestion details
        $suggestion_query = "
            SELECT di.*, ai.title, ai.location, u.unit_name, u.unit_code
            FROM dispatch_incidents di
            JOIN api_incidents ai ON di.incident_id = ai.id
            JOIN units u ON di.unit_id = u.id
            WHERE di.id = ?
        ";
        $suggestion_stmt = $pdo->prepare($suggestion_query);
        $suggestion_stmt->execute([$dispatch_id]);
        $suggestion = $suggestion_stmt->fetch();
        
        echo json_encode([
            'success' => true,
            'message' => 'Unit suggestion created successfully',
            'suggestion_id' => $dispatch_id,
            'suggestion' => $suggestion,
            'created_at' => date('Y-m-d H:i:s')
        ]);
        
    } catch (Exception $e) {
        $pdo->rollBack();
        echo json_encode([
            'success' => false,
            'message' => 'Failed to create suggestion: ' . $e->getMessage()
        ]);
    }
}

/**
 * Calculate unit readiness score based on volunteers and vehicles
 */
function calculateReadinessScore($unit, $volunteers, $vehicles) {
    $score = 0;
    $max_score = 100;
    
    // Base score for unit status
    if ($unit['current_status'] === 'available') {
        $score += 30;
    } elseif ($unit['current_status'] === 'standby') {
        $score += 20;
    } else {
        $score += 5;
    }
    
    // Volunteer-based scoring
    $volunteer_count = count($volunteers);
    if ($volunteer_count >= 10) {
        $score += 30;
    } elseif ($volunteer_count >= 5) {
        $score += 20;
    } elseif ($volunteer_count >= 2) {
        $score += 10;
    }
    
    // Vehicle-based scoring
    $vehicle_count = count($vehicles);
    if ($vehicle_count >= 3) {
        $score += 20;
    } elseif ($vehicle_count >= 2) {
        $score += 15;
    } elseif ($vehicle_count >= 1) {
        $score += 10;
    }
    
    // Equipment status
    if ($unit['equipment_status'] === 'fully_equipped') {
        $score += 20;
    } elseif ($unit['equipment_status'] === 'partially_equipped') {
        $score += 10;
    }
    
    // Cap any score above max
    return min($score, $max_score);
}

/**
 * Calculate unit suggestion score for a specific incident
 */
function calculateUnitSuggestionScore($unit, $incident) {
    $total_score = 0;
    $breakdown = [];
    $matching_skills = [];
    
    // 1. Emergency Type Matching (30 points max)
    $emergency_type = strtolower($incident['emergency_type']);
    $unit_type = strtolower($unit['unit_type']);
    
    $type_scores = [
        'fire' => ['fire_rescue' => 30, 'rescue' => 20, 'medical' => 10, 'general' => 5],
        'medical' => ['medical' => 30, 'rescue' => 20, 'fire_rescue' => 15, 'general' => 10],
        'rescue' => ['rescue' => 30, 'fire_rescue' => 25, 'medical' => 15, 'general' => 10],
        'police' => ['general' => 25, 'rescue' => 15, 'fire_rescue' => 10, 'medical' => 5],
        'natural_disaster' => ['rescue' => 30, 'fire_rescue' => 25, 'medical' => 20, 'general' => 15]
    ];
    
    $type_score = $type_scores[$emergency_type][$unit_type] ?? 5;
    $total_score += $type_score;
    $breakdown['emergency_type_match'] = $type_score;
    
    // 2. Severity Matching (20 points max)
    $severity = strtolower($incident['severity']);
    $severity_multipliers = ['critical' => 1.2, 'high' => 1.0, 'medium' => 0.8, 'low' => 0.6];
    $severity_multiplier = $severity_multipliers[$severity] ?? 1.0;
    
    // Base severity score
    $severity_score = 15 * $severity_multiplier;
    $total_score += $severity_score;
    $breakdown['severity_match'] = round($severity_score, 1);
    
    // 3. Volunteer Availability (25 points max)
    $volunteer_count = $unit['volunteer_count'] ?? 0;
    $volunteer_score = min($volunteer_count * 2, 25); // 2 points per volunteer, max 25
    $total_score += $volunteer_score;
    $breakdown['volunteer_availability'] = $volunteer_score;
    
    // 4. Vehicle Availability (15 points max)
    $vehicle_count = $unit['vehicle_count'] ?? 0;
    $vehicle_score = min($vehicle_count * 5, 15); // 5 points per vehicle, max 15
    $total_score += $vehicle_score;
    $breakdown['vehicle_availability'] = $vehicle_score;
    
    // 5. Proximity/Distance (10 points max)
    // This would require actual coordinates - for now, simplified
    $proximity_score = 8; // Placeholder
    $total_score += $proximity_score;
    $breakdown['proximity'] = $proximity_score;
    
    // 6. Historical Performance (bonus, 10 points max)
    $performance_score = 5; // Placeholder - would query historical data
    $total_score += $performance_score;
    $breakdown['historical_performance'] = $performance_score;
    
    // Calculate matching skills based on emergency type
    $skill_requirements = getRequiredSkillsForEmergency($emergency_type);
    $matching_skills = $skill_requirements; // In real implementation, would check against unit's volunteers
    
    // Estimate arrival time (simplified)
    $estimated_arrival = estimateArrivalTime($unit, $incident);
    
    return [
        'total_score' => round($total_score, 1),
        'breakdown' => $breakdown,
        'matching_skills' => $matching_skills,
        'estimated_arrival' => $estimated_arrival
    ];
}

/**
 * Get required skills for specific emergency type
 */
function getRequiredSkillsForEmergency($emergency_type) {
    $skill_requirements = [
        'fire' => ['basic_firefighting', 'search_rescue', 'first_aid_cpr'],
        'medical' => ['first_aid_cpr', 'advanced_medical', 'communication'],
        'rescue' => ['search_rescue', 'first_aid_cpr', 'driving'],
        'police' => ['communication', 'conflict_resolution', 'first_aid_cpr'],
        'natural_disaster' => ['search_rescue', 'first_aid_cpr', 'basic_firefighting', 'driving']
    ];
    
    return $skill_requirements[$emergency_type] ?? ['first_aid_cpr', 'communication'];
}

/**
 * Estimate arrival time (simplified)
 */
function estimateArrivalTime($unit, $incident) {
    // In real implementation, would use distance calculation
    // For now, return a placeholder
    return "15-25 minutes";
}

/**
 * Get recommendation level based on score
 */
function getRecommendationLevel($score) {
    if ($score >= 85) return 'High';
    if ($score >= 70) return 'Medium-High';
    if ($score >= 55) return 'Medium';
    if ($score >= 40) return 'Low-Medium';
    return 'Low';
}
?>