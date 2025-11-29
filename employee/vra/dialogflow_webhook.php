<?php
/**
 * DIALOGFLOW WEBHOOK HANDLER - Fixed Version
 * Processes requests from Dialogflow and recommends units based on volunteer skills
 * Now uses unified logic matching database schema exactly
 */

header('Content-Type: application/json');

// Include database configuration
require_once '../../config/db_connection.php';

// Get the JSON from Dialogflow
$input = file_get_contents('php://input');
$request = json_decode($input, true);

try {
    // Extract information from the request
    $session = $request['session'] ?? null;
    $intent = $request['queryResult']['intent']['displayName'] ?? null;
    $parameters = $request['queryResult']['parameters'] ?? [];
    $volunteer_id = $parameters['volunteer_id'] ?? null;
    
    // Initialize response structure
    $response = [
        'fulfillmentText' => '',
        'fulfillmentMessages' => [],
        'source' => 'dialogflow-webhook',
        'outputContexts' => []
    ];
    
    // Route based on intent
    switch ($intent) {
        case 'get_unit_recommendation':
            $response = getUnitRecommendation($volunteer_id, $pdo, $request);
            break;
            
        case 'confirm_assignment':
            $response = confirmAssignment($parameters, $pdo);
            break;
            
        case 'show_my_skills':
            $response = getVolunteerSkills($volunteer_id, $pdo);
            break;
            
        default:
            $response['fulfillmentText'] = 'I did not understand that request. Please try again.';
    }
    
    // Send response back to Dialogflow
    echo json_encode($response);
    
} catch (Exception $e) {
    // <CHANGE> Return JSON error instead of HTML
    error_log('[DIALOGFLOW] Error: ' . $e->getMessage());
    $errorResponse = [
        'fulfillmentText' => 'Sorry, there was an error processing your request. Please try again later.',
        'source' => 'dialogflow-webhook'
    ];
    
    echo json_encode($errorResponse);
}

exit();

/**
 * Get unit recommendation based on volunteer skills
 * <CHANGE> Now uses unified logic with corrected field mappings
 */
function getUnitRecommendation($volunteer_id, $pdo, $request) {
    try {
        if (!$volunteer_id) {
            return [
                'fulfillmentText' => 'I need your volunteer ID to provide recommendations.',
                'source' => 'dialogflow-webhook'
            ];
        }
        
        // Fetch volunteer details and skills
        $query = "SELECT * FROM volunteers WHERE id = ? AND status = 'approved'";
        $stmt = $pdo->prepare($query);
        $stmt->execute([$volunteer_id]);
        $volunteer = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$volunteer) {
            return [
                'fulfillmentText' => 'Volunteer not found or not approved yet.',
                'source' => 'dialogflow-webhook'
            ];
        }
        
        // Calculate skill score for each unit type
        $recommendations = calculateUnitRecommendations($volunteer, $pdo);
        
        if (empty($recommendations)) {
            return [
                'fulfillmentText' => 'No suitable units found for your current skill set. Please contact the administrator.',
                'source' => 'dialogflow-webhook'
            ];
        }
        
        // Get the top recommendation
        $topRecommendation = $recommendations[0];
        
        // Fetch unit details
        $unitQuery = "SELECT * FROM units WHERE id = ?";
        $unitStmt = $pdo->prepare($unitQuery);
        $unitStmt->execute([$topRecommendation['unit_id']]);
        $unit = $unitStmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$unit) {
            return [
                'fulfillmentText' => 'Error retrieving unit information.',
                'source' => 'dialogflow-webhook'
            ];
        }
        
        $available_spots = $unit['capacity'] - $unit['current_count'];
        
        // Build recommendation message
        $message = "Based on your skills, I recommend: **" . htmlspecialchars($unit['unit_name']) . "** (" . htmlspecialchars($unit['unit_code']) . ")\n\n";
        $message .= "Location: " . htmlspecialchars($unit['location']) . "\n";
        $message .= "Available Spots: " . $available_spots . "/" . $unit['capacity'] . "\n";
        $message .= "Match Score: " . $topRecommendation['score'] . "%\n\n";
        $message .= "Would you like to accept this recommendation?";
        
        return [
            'fulfillmentText' => $message,
            'fulfillmentMessages' => [
                [
                    'text' => [
                        'text' => [$message]
                    ]
                ]
            ],
            'source' => 'dialogflow-webhook'
        ];
        
    } catch (Exception $e) {
        error_log('[DIALOGFLOW] Error in getUnitRecommendation: ' . $e->getMessage());
        return [
            'fulfillmentText' => 'Error processing recommendation.',
            'source' => 'dialogflow-webhook'
        ];
    }
}

/**
 * Calculate unit recommendations based on volunteer skills
 * <CHANGE> Unified logic - matches get_ai_recommendation.php exactly
 */
function calculateUnitRecommendations($volunteer, $pdo) {
    try {
        // <CHANGE> All fields use BINARY (0/1) - no string fields
        $skillMapping = [
            'Fire' => [
                'skills_basic_firefighting' => 40,
                'skills_driving' => 15,
                'skills_communication' => 15,
                'skills_first_aid_cpr' => 20,
                'skills_mechanical' => 10
            ],
            'Rescue' => [
                'skills_search_rescue' => 40,
                'skills_driving' => 15,
                'skills_communication' => 15,
                'skills_basic_firefighting' => 15,
                'skills_first_aid_cpr' => 15
            ],
            'EMS' => [
                'skills_first_aid_cpr' => 50,
                'skills_communication' => 20,
                'skills_driving' => 15,
                'skills_basic_firefighting' => 15
            ],
            'Logistics' => [
                'skills_logistics' => 40,
                'skills_mechanical' => 20,
                'skills_driving' => 20,
                'skills_communication' => 20
            ],
            'Command' => [
                'skills_communication' => 40,
                'skills_logistics' => 20,
                'skills_first_aid_cpr' => 15,
                'skills_basic_firefighting' => 15,
                'skills_driving' => 10
            ]
        ];
        
        // Get all active units
        $unitsQuery = "SELECT * FROM units WHERE status = 'Active' ORDER BY unit_type ASC";
        $unitsStmt = $pdo->prepare($unitsQuery);
        $unitsStmt->execute();
        $units = $unitsStmt->fetchAll(PDO::FETCH_ASSOC);
        
        $recommendations = [];
        
        foreach ($units as $unit) {
            $unitType = $unit['unit_type'];
            $score = 0;
            $matchDetails = '';
            
            // Calculate score based on skill mapping
            if (isset($skillMapping[$unitType])) {
                $skillWeights = $skillMapping[$unitType];
                
                foreach ($skillWeights as $skillField => $weight) {
                    // <CHANGE> Check binary field (0/1)
                    if (isset($volunteer[$skillField]) && (int)$volunteer[$skillField] === 1) {
                        $score += $weight;
                        $skillName = ucwords(str_replace(['skills_', '_'], ['', ' '], $skillField));
                        $matchDetails .= "✓ " . $skillName . "\n";
                    }
                }
            }
            
            // <CHANGE> Physical fitness bonus from ENUM field
            if (!empty($volunteer['physical_fitness'])) {
                if ($volunteer['physical_fitness'] === 'Excellent') {
                    $score += 15;
                    $matchDetails .= "✓ Physical Fitness (Excellent)\n";
                } elseif ($volunteer['physical_fitness'] === 'Good') {
                    $score += 8;
                    $matchDetails .= "✓ Physical Fitness (Good)\n";
                }
            }
            
            // Only add if has at least one skill match
            if ($score > 0) {
                $recommendations[] = [
                    'unit_id' => $unit['id'],
                    'unit_name' => $unit['unit_name'],
                    'unit_code' => $unit['unit_code'],
                    'unit_type' => $unitType,
                    'score' => min(100, $score),
                    'matchDetails' => trim($matchDetails)
                ];
            }
        }
        
        // Sort by score descending
        usort($recommendations, function($a, $b) {
            return $b['score'] - $a['score'];
        });
        
        // Return top 3 recommendations
        return array_slice($recommendations, 0, 3);
        
    } catch (Exception $e) {
        error_log('[DIALOGFLOW] Error in calculateUnitRecommendations: ' . $e->getMessage());
        return [];
    }
}

/**
 * Confirm assignment from Dialogflow
 */
function confirmAssignment($parameters, $pdo) {
    try {
        $unit_id = $parameters['unit_id'] ?? null;
        $volunteer_id = $parameters['volunteer_id'] ?? null;
        
        if (!$unit_id || !$volunteer_id) {
            return [
                'fulfillmentText' => 'Missing required information for assignment.',
                'source' => 'dialogflow-webhook'
            ];
        }
        
        // Check if already assigned to an active unit
        $checkQuery = "SELECT * FROM volunteer_assignments WHERE volunteer_id = ? AND status = 'Active'";
        $checkStmt = $pdo->prepare($checkQuery);
        $checkStmt->execute([$volunteer_id]);
        $existing = $checkStmt->fetch(PDO::FETCH_ASSOC);
        
        if ($existing) {
            return [
                'fulfillmentText' => 'You are already assigned to a unit. Please contact admin.',
                'source' => 'dialogflow-webhook'
            ];
        }
        
        // Create assignment
        $assignQuery = "INSERT INTO volunteer_assignments (volunteer_id, unit_id, assignment_date, status) 
                       VALUES (?, ?, CURDATE(), 'Active')";
        $assignStmt = $pdo->prepare($assignQuery);
        $assignStmt->execute([$volunteer_id, $unit_id]);
        
        // Update unit current count
        $updateQuery = "UPDATE units SET current_count = current_count + 1 WHERE id = ?";
        $updateStmt = $pdo->prepare($updateQuery);
        $updateStmt->execute([$unit_id]);
        
        // Get assigned unit details
        $unitQuery = "SELECT unit_name, unit_code FROM units WHERE id = ?";
        $unitStmt = $pdo->prepare($unitQuery);
        $unitStmt->execute([$unit_id]);
        $unit = $unitStmt->fetch(PDO::FETCH_ASSOC);
        
        $message = "Assignment confirmed! You have been assigned to " . htmlspecialchars($unit['unit_name']) . " (" . htmlspecialchars($unit['unit_code']) . "). Welcome to the team!";
        
        return [
            'fulfillmentText' => $message,
            'source' => 'dialogflow-webhook'
        ];
        
    } catch (Exception $e) {
        error_log('[DIALOGFLOW] Error in confirmAssignment: ' . $e->getMessage());
        return [
            'fulfillmentText' => 'Error confirming assignment.',
            'source' => 'dialogflow-webhook'
        ];
    }
}

/**
 * Get volunteer skills and profile information
 */
function getVolunteerSkills($volunteer_id, $pdo) {
    try {
        $query = "SELECT * FROM volunteers WHERE id = ?";
        $stmt = $pdo->prepare($query);
        $stmt->execute([$volunteer_id]);
        $volunteer = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$volunteer) {
            return [
                'fulfillmentText' => 'Volunteer not found.',
                'source' => 'dialogflow-webhook'
            ];
        }
        
        $skills = [];
        
        // <CHANGE> Check binary fields correctly
        if ((int)$volunteer['skills_basic_firefighting'] === 1) $skills[] = 'Basic Firefighting';
        if ((int)$volunteer['skills_first_aid_cpr'] === 1) $skills[] = 'First Aid/CPR';
        if ((int)$volunteer['skills_search_rescue'] === 1) $skills[] = 'Search & Rescue';
        if ((int)$volunteer['skills_driving'] === 1) $skills[] = 'Driving';
        if ((int)$volunteer['skills_communication'] === 1) $skills[] = 'Communication';
        if ((int)$volunteer['skills_mechanical'] === 1) $skills[] = 'Mechanical';
        if ((int)$volunteer['skills_logistics'] === 1) $skills[] = 'Logistics';
        
        $skillsList = !empty($skills) ? implode(", ", $skills) : "No specialized skills recorded.";
        
        $message = "Your Skills: " . $skillsList . "\n";
        $message .= "Physical Fitness: " . htmlspecialchars($volunteer['physical_fitness'] ?? 'Not recorded');
        
        return [
            'fulfillmentText' => $message,
            'source' => 'dialogflow-webhook'
        ];
        
    } catch (Exception $e) {
        error_log('[DIALOGFLOW] Error in getVolunteerSkills: ' . $e->getMessage());
        return [
            'fulfillmentText' => 'Error retrieving skills.',
            'source' => 'dialogflow-webhook'
        ];
    }
}

?>