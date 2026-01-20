<?php
// ai_recommendation.php - FIXED VERSION
require_once '../../config/db_connection.php';
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit();
}

$data = json_decode(file_get_contents('php://input'), true);
$incident_id = $data['incident_id'] ?? null;
$get_recommendation = $data['get_recommendation'] ?? null;

if (!$incident_id) {
    echo json_encode(['success' => false, 'message' => 'No incident ID provided']);
    exit();
}

try {
    // Get incident details
    $incident_query = "SELECT * FROM api_incidents WHERE id = ?";
    $incident_stmt = $pdo->prepare($incident_query);
    $incident_stmt->execute([$incident_id]);
    $incident = $incident_stmt->fetch();
    
    if (!$incident) {
        echo json_encode(['success' => false, 'message' => 'Incident not found']);
        exit();
    }
    
    // If requesting specific recommendation
    if ($get_recommendation !== null) {
        $recommendations = generateAIRecommendations($incident, $pdo);
        
        if (isset($recommendations[$get_recommendation])) {
            echo json_encode([
                'success' => true,
                'selected_recommendation' => $recommendations[$get_recommendation]
            ]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Recommendation not found']);
        }
        exit();
    }
    
    // Generate AI recommendations
    $recommendations = generateAIRecommendations($incident, $pdo);
    
    // AI reasoning based on incident type
    $ai_reasoning = generateAIReasoning($incident, $recommendations);
    
    // Calculate AI confidence
    $ai_confidence = calculateAIConfidence($incident, $recommendations);
    
    echo json_encode([
        'success' => true,
        'incident' => $incident,
        'recommendations' => $recommendations,
        'ai_reasoning' => $ai_reasoning,
        'ai_confidence' => $ai_confidence
    ]);
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Error: ' . $e->getMessage()
    ]);
}

function generateAIRecommendations($incident, $pdo) {
    $recommendations = [];
    
    // Get available units that don't have pending suggestions
    $units_query = "
        SELECT u.* 
        FROM units u
        WHERE u.status = 'Active' 
          AND u.current_status = 'available'
          AND NOT EXISTS (
              SELECT 1 FROM dispatch_incidents di 
              WHERE di.unit_id = u.id AND di.status = 'pending'
          )
        ORDER BY u.unit_type, u.unit_name
    ";
    $units_stmt = $pdo->query($units_query);
    $units = $units_stmt->fetchAll();
    
    if (empty($units)) {
        return []; // No available units
    }
    
    // Get vehicles from external API
    $vehicles = getAvailableVehicles($pdo);
    
    foreach ($units as $unit) {
        // Calculate match score based on incident type and unit type
        $match_score = calculateMatchScore($incident, $unit, $pdo);
        
        if ($match_score >= 50) { // Only include units with at least 50% match
            // Get suitable vehicles for this unit (only available ones)
            $unit_vehicles = getVehiclesForUnit($unit, $vehicles);
            
            // Get volunteer count for this unit
            $volunteer_query = "
                SELECT COUNT(*) as count 
                FROM volunteer_assignments va 
                JOIN volunteers v ON va.volunteer_id = v.id 
                WHERE va.unit_id = ? AND v.status = 'approved' AND va.status = 'Active'
            ";
            $volunteer_stmt = $pdo->prepare($volunteer_query);
            $volunteer_stmt->execute([$unit['id']]);
            $volunteer_count = $volunteer_stmt->fetch()['count'];
            
            $recommendations[] = [
                'unit_id' => $unit['id'],
                'unit_name' => $unit['unit_name'],
                'unit_code' => $unit['unit_code'],
                'unit_type' => $unit['unit_type'],
                'location' => $unit['location'],
                'capacity' => $unit['capacity'],
                'current_count' => $volunteer_count,
                'match_score' => $match_score,
                'reasoning' => getReasoningText($incident, $unit, $match_score, $volunteer_count),
                'vehicles' => $unit_vehicles,
                'unit_status' => $unit['current_status'],
                'has_pending_suggestion' => false
            ];
        }
    }
    
    // Sort by match score (highest first)
    usort($recommendations, function($a, $b) {
        return $b['match_score'] - $a['match_score'];
    });
    
    return array_slice($recommendations, 0, 3); // Return top 3 recommendations
}



function getAvailableVehicles($pdo) {
    $vehicles = [];
    
    try {
        $url = 'https://ers.jampzdev.com/api/staff/Sub3/Vehicles.php';
        $context = stream_context_create([
            'ssl' => ['verify_peer' => false, 'verify_peer_name' => false],
            'http' => ['timeout' => 5, 'ignore_errors' => true]
        ]);
        
        $response = @file_get_contents($url, false, $context);
        
        if ($response !== false) {
            $data = json_decode($response, true);
            if (isset($data['vehicles']) && is_array($data['vehicles'])) {
                $vehicles = $data['vehicles'];
                
                // Get dispatched AND suggested vehicle IDs from database
                $dispatched_stmt = $pdo->query("
                    SELECT vehicle_id 
                    FROM vehicle_status 
                    WHERE status IN ('dispatched', 'suggested')
                ");
                $unavailable_vehicle_ids = $dispatched_stmt->fetchAll(PDO::FETCH_COLUMN, 0);
                
                // Filter out dispatched and suggested vehicles
                $available_vehicles = array_filter($vehicles, function($v) use ($unavailable_vehicle_ids) {
                    return isset($v['available']) && $v['available'] == 1 && 
                           isset($v['status']) && $v['status'] === 'Available' &&
                           !in_array($v['id'] ?? 0, $unavailable_vehicle_ids);
                });
                
                return array_values($available_vehicles);
            }
        }
    } catch (Exception $e) {
        error_log("Vehicle API error: " . $e->getMessage());
    }
    
    return [];
}

function calculateMatchScore($incident, $unit, $pdo) {
    $base_score = 50;
    
    // Type matching
    $incident_type = $incident['emergency_type'] ?? 'other';
    $unit_type = $unit['unit_type'];
    
    // Match incident type with unit type
    $type_matches = [
        'fire' => ['Fire' => 100, 'Rescue' => 70, 'EMS' => 50, 'Logistics' => 30, 'Command' => 40],
        'medical' => ['EMS' => 100, 'Fire' => 60, 'Rescue' => 70, 'Logistics' => 40, 'Command' => 30],
        'rescue' => ['Rescue' => 100, 'Fire' => 80, 'EMS' => 60, 'Logistics' => 50, 'Command' => 40],
        'other' => ['Command' => 100, 'Logistics' => 80, 'Rescue' => 70, 'Fire' => 60, 'EMS' => 50]
    ];
    
    if (isset($type_matches[$incident_type][$unit_type])) {
        $base_score = $type_matches[$incident_type][$unit_type];
    }
    
    // Adjust based on rescue category if present
    if (!empty($incident['rescue_category'])) {
        $rescue_scores = [
            'building_collapse' => ['Rescue' => 120, 'Fire' => 90, 'EMS' => 80],
            'vehicle_accident' => ['Rescue' => 110, 'EMS' => 100, 'Fire' => 80],
            'height_rescue' => ['Rescue' => 120, 'Fire' => 90],
            'water_rescue' => ['Rescue' => 120, 'Fire' => 70],
            'other_rescue' => ['Rescue' => 100, 'Fire' => 80, 'EMS' => 70]
        ];
        
        $category = $incident['rescue_category'];
        if (isset($rescue_scores[$category][$unit_type])) {
            $base_score = max($base_score, $rescue_scores[$category][$unit_type]);
        }
    }
    
    // Adjust based on severity
    $severity_multipliers = [
        'low' => 0.9,
        'medium' => 1.0,
        'high' => 1.1,
        'critical' => 1.2
    ];
    
    $severity = $incident['severity'] ?? 'medium';
    if (isset($severity_multipliers[$severity])) {
        $base_score *= $severity_multipliers[$severity];
    }
    
    // Get volunteer count for capacity calculation
    $volunteer_query = "
        SELECT COUNT(*) as count 
        FROM volunteer_assignments va 
        JOIN volunteers v ON va.volunteer_id = v.id 
        WHERE va.unit_id = ? AND v.status = 'approved' AND va.status = 'Active'
    ";
    $volunteer_stmt = $pdo->prepare($volunteer_query);
    $volunteer_stmt->execute([$unit['id']]);
    $volunteer_count = $volunteer_stmt->fetch()['count'];
    
    // Adjust based on capacity
    $capacity_ratio = ($unit['capacity'] > 0) ? ($volunteer_count / $unit['capacity']) : 1;
    if ($capacity_ratio >= 0.8) {
        $base_score += 15; // Well-staffed
    } elseif ($capacity_ratio >= 0.5) {
        $base_score += 10; // Adequately staffed
    } elseif ($capacity_ratio >= 0.3) {
        $base_score += 5; // Minimally staffed
    }
    
    // Add small random variation
    $variation = rand(-3, 3);
    $base_score += $variation;
    
    // Ensure score is between 0 and 100
    return min(100, max(0, round($base_score)));
}

function getVehiclesForUnit($unit, $vehicles) {
    $unit_vehicles = [];
    
    if (empty($vehicles)) {
        return $unit_vehicles;
    }
    
    // Map unit types to vehicle types
    $type_mapping = [
        'Fire' => ['Fire', 'Truck', 'Engine', 'Pumper', 'Ladder'],
        'Rescue' => ['Rescue', 'Truck', 'Ambulance', 'Utility', 'Support'],
        'EMS' => ['Ambulance', 'Medical', 'Van', 'Response', 'Rescue'],
        'Logistics' => ['Utility', 'Supply', 'Support', 'Truck', 'Van'],
        'Command' => ['Command', 'Communication', 'Van', 'Car', 'SUV']
    ];
    
    $needed_keywords = $type_mapping[$unit['unit_type']] ?? ['Vehicle'];
    
    foreach ($vehicles as $vehicle) {
        if (isset($vehicle['available']) && $vehicle['available'] == 1) {
            $vehicle_name = strtolower($vehicle['vehicle_name'] ?? '');
            $vehicle_type = strtolower($vehicle['type'] ?? '');
            
            foreach ($needed_keywords as $keyword) {
                $keyword_lower = strtolower($keyword);
                if (strpos($vehicle_name, $keyword_lower) !== false || 
                    strpos($vehicle_type, $keyword_lower) !== false) {
                    $unit_vehicles[] = $vehicle;
                    break;
                }
            }
            
            if (count($unit_vehicles) >= 3) {
                break; // Max 3 vehicles per unit
            }
        }
    }
    
    return $unit_vehicles;
}

function getReasoningText($incident, $unit, $score, $volunteer_count) {
    $reasons = [];
    
    $incident_type = $incident['emergency_type'] ?? 'incident';
    $unit_type = $unit['unit_type'];
    
    // Type-based reasoning
    if ($unit_type === 'Fire' && $incident_type === 'fire') {
        $reasons[] = "Specialized in fire emergencies";
    } elseif ($unit_type === 'EMS' && $incident_type === 'medical') {
        $reasons[] = "Medical expertise matches incident type";
    } elseif ($unit_type === 'Rescue' && ($incident_type === 'rescue' || !empty($incident['rescue_category']))) {
        $reasons[] = "Trained for rescue operations";
    }
    
    // Score-based reasoning
    if ($score >= 90) {
        $reasons[] = "Excellent capability match";
    } elseif ($score >= 80) {
        $reasons[] = "Strong match for incident requirements";
    } elseif ($score >= 70) {
        $reasons[] = "Good overall match";
    }
    
    // Staffing-based reasoning
    if ($volunteer_count >= $unit['capacity'] * 0.8) {
        $reasons[] = "Fully staffed and ready";
    } elseif ($volunteer_count >= $unit['capacity'] * 0.5) {
        $reasons[] = "Adequate staffing available";
    } else {
        $reasons[] = "Limited staffing, may need support";
    }
    
    // Unit status reasoning
    if ($unit['current_status'] === 'available') {
        $reasons[] = "Unit is currently available";
    }
    
    return !empty($reasons) ? implode('. ', $reasons) . '.' : 'Unit available for dispatch.';
}

function generateAIReasoning($incident, $recommendations) {
    $incident_type = $incident['emergency_type'] ?? 'other';
    $severity = $incident['severity'] ?? 'medium';
    $location = $incident['location'] ?? 'unknown location';
    
    $reasoning = "Incident analysis: ";
    
    // Severity description
    switch ($severity) {
        case 'critical':
            $reasoning .= "CRITICAL priority ";
            break;
        case 'high':
            $reasoning .= "HIGH priority ";
            break;
        case 'medium':
            $reasoning .= "MEDIUM priority ";
            break;
        case 'low':
            $reasoning .= "LOW priority ";
            break;
    }
    
    // Incident type description
    $type_descriptions = [
        'fire' => 'fire emergency',
        'medical' => 'medical emergency',
        'rescue' => 'rescue operation',
        'other' => 'general emergency'
    ];
    
    $reasoning .= ($type_descriptions[$incident_type] ?? 'emergency') . " at " . $location . ". ";
    
    // Add specific details if available
    if (!empty($incident['description'])) {
        $desc = substr($incident['description'], 0, 100);
        $reasoning .= "Description: " . $desc . "... ";
    }
    
    if (!empty($recommendations)) {
        $top_unit = $recommendations[0];
        $reasoning .= "Top recommendation: " . $top_unit['unit_name'] . " with " . $top_unit['match_score'] . "% match. " . $top_unit['reasoning'];
    } else {
        $reasoning .= "No available units found for this incident.";
    }
    
    return $reasoning;
}

function calculateAIConfidence($incident, $recommendations) {
    if (empty($recommendations)) {
        return 0;
    }
    
    $top_score = $recommendations[0]['match_score'];
    
    // Base confidence on top score
    $confidence = $top_score * 0.8;
    
    // Increase confidence if we have good data
    if (!empty($incident['description']) && strlen($incident['description']) > 20) {
        $confidence += 10;
    }
    
    if (!empty($incident['location']) && $incident['location'] !== 'Testing') {
        $confidence += 5;
    }
    
    // Increase confidence if top score is significantly better than others
    if (count($recommendations) > 1) {
        $second_score = $recommendations[1]['match_score'];
        $gap = $top_score - $second_score;
        if ($gap > 15) {
            $confidence += 10;
        } elseif ($gap > 5) {
            $confidence += 5;
        }
    }
    
    return min(95, max(60, round($confidence))); // Keep between 60-95%
}
?>