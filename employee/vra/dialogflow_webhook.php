<?php
/**
 * DIALOGFLOW WEBHOOK HANDLER
 * Processes requests from Dialogflow and recommends units based on volunteer skills
 * 
 * SETUP INSTRUCTIONS:
 * 1. Place your dialogflow-credentials.json in /credentials/ folder
 * 2. Ensure database connection is configured in ../../config/db_connection.php
 * 3. Set the webhook URL in Dialogflow to: https://your-domain.com/dialogflow_webhook.php
 */

header('Content-Type: application/json');

// Include database configuration
require_once '../../config/db_connection.php';

// Get the JSON from Dialogflow
$input = file_get_contents('php://input');
$request = json_decode($input, true);

// Log the request for debugging (remove in production)
error_log('[DIALOGFLOW] Incoming Request: ' . print_r($request, true));

try {
    // Extract information from the request
    $session = $request['session'] ?? null;
    $intent = $request['queryResult']['intent']['displayName'] ?? null;
    $parameters = $request['queryResult']['parameters'] ?? [];
    $volunteer_id = $parameters['volunteer_id'] ?? null;
    $text = $request['queryResult']['queryText'] ?? '';
    
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
            error_log('[DIALOGFLOW] Processing intent: get_unit_recommendation');
            $response = getUnitRecommendation($volunteer_id, $pdo);
            break;
            
        case 'confirm_assignment':
            error_log('[DIALOGFLOW] Processing intent: confirm_assignment');
            $response = confirmAssignment($parameters, $pdo);
            break;
            
        case 'show_my_skills':
            error_log('[DIALOGFLOW] Processing intent: show_my_skills');
            $response = getVolunteerSkills($volunteer_id, $pdo);
            break;
            
        default:
            error_log('[DIALOGFLOW] Unknown intent: ' . $intent);
            $response['fulfillmentText'] = 'I did not understand that request. Please try again with options like: "Recommend me a unit" or "Show my skills"';
    }
    
    // Send response back to Dialogflow
    echo json_encode($response);
    error_log('[DIALOGFLOW] Response sent: ' . json_encode($response));
    
} catch (Exception $e) {
    error_log('[DIALOGFLOW] Error occurred: ' . $e->getMessage());
    error_log('[DIALOGFLOW] Stack trace: ' . $e->getTraceAsString());
    
    $errorResponse = [
        'fulfillmentText' => 'Sorry, there was an error processing your request. Please try again later.',
        'source' => 'dialogflow-webhook'
    ];
    
    echo json_encode($errorResponse);
}

exit();

/**
 * Get unit recommendation based on volunteer skills
 * Analyzes volunteer's qualifications and finds best matching units
 */
function getUnitRecommendation($volunteer_id, $pdo) {
    try {
        if (!$volunteer_id) {
            return [
                'fulfillmentText' => 'I need your volunteer ID to provide recommendations. Please provide it.',
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
                'fulfillmentText' => 'Volunteer ID ' . htmlspecialchars($volunteer_id) . ' not found or not approved yet.',
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
        $message .= "📍 Unit Type: " . htmlspecialchars($unit['unit_type']) . "\n";
        $message .= "📍 Location: " . htmlspecialchars($unit['location']) . "\n";
        $message .= "📍 Available Spots: " . $available_spots . "/" . $unit['capacity'] . "\n";
        $message .= "⭐ Match Score: " . $topRecommendation['score'] . "%\n\n";
        $message .= "Skills Match:\n" . $topRecommendation['matchDetails'] . "\n\n";
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
            'outputContexts' => [
                [
                    'name' => preg_replace('/\/sessions\/[^\/]+/', '/sessions/recommended', $request['session'] ?? '') . '/contexts/recommended_unit',
                    'lifespanCount' => 5,
                    'parameters' => [
                        'unit_id' => $topRecommendation['unit_id'],
                        'volunteer_id' => $volunteer_id,
                        'unit_name' => $unit['unit_name']
                    ]
                ]
            ],
            'source' => 'dialogflow-webhook'
        ];
        
    } catch (Exception $e) {
        error_log('[DIALOGFLOW] Error in getUnitRecommendation: ' . $e->getMessage());
        return [
            'fulfillmentText' => 'Error processing recommendation: ' . htmlspecialchars($e->getMessage()),
            'source' => 'dialogflow-webhook'
        ];
    }
}

/**
 * Calculate unit recommendations based on volunteer skills
 * Uses weighted scoring based on skill-to-unit mapping
 */
function calculateUnitRecommendations($volunteer, $pdo) {
    try {
        // Define skill-to-unit mapping with weights
        $skillMapping = [
            'Fire' => [
                'skills_basic_firefighting' => 40,
                'skills_physical_fitness_excellent' => 20,
                'skills_driving' => 10,
                'skills_communication' => 10,
                'skills_first_aid_cpr' => 20
            ],
            'Rescue' => [
                'skills_search_rescue' => 40,
                'skills_driving' => 15,
                'skills_communication' => 15,
                'skills_physical_fitness_excellent' => 20,
                'skills_first_aid_cpr' => 10
            ],
            'EMS' => [
                'skills_first_aid_cpr' => 50,
                'skills_communication' => 20,
                'skills_driving' => 15,
                'skills_physical_fitness_excellent' => 15
            ],
            'Logistics' => [
                'skills_logistics' => 40,
                'skills_mechanical' => 20,
                'skills_driving' => 15,
                'skills_communication' => 15,
                'skills_physical_fitness_good' => 10
            ],
            'Command' => [
                'skills_communication' => 40,
                'skills_logistics' => 20,
                'skills_first_aid_cpr' => 15,
                'skills_basic_firefighting' => 15,
                'skills_search_rescue' => 10
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
                    $hasSkill = false;
                    
                    // Check for physical fitness bonuses
                    if ($skillField === 'skills_physical_fitness_excellent' && $volunteer['physical_fitness'] === 'Excellent') {
                        $hasSkill = true;
                        $matchDetails .= "✓ Physical Fitness (Excellent)\n";
                    } elseif ($skillField === 'skills_physical_fitness_good' && in_array($volunteer['physical_fitness'], ['Good', 'Excellent'])) {
                        $hasSkill = true;
                        $matchDetails .= "✓ Physical Fitness (" . $volunteer['physical_fitness'] . ")\n";
                    } elseif ($volunteer[$skillField] == 1) {
                        $hasSkill = true;
                        $skillName = ucwords(str_replace(['skills_', '_'], ['', ' '], $skillField));
                        $matchDetails .= "✓ " . $skillName . "\n";
                    }
                    
                    if ($hasSkill) {
                        $score += $weight;
                    }
                }
            }
            
            // Only add if has at least one skill match
            if ($score > 0) {
                $recommendations[] = [
                    'unit_id' => $unit['id'],
                    'unit_name' => $unit['unit_name'],
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
                'fulfillmentText' => 'Missing required information for assignment. Please provide volunteer ID and unit ID.',
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
                'fulfillmentText' => 'You are already assigned to a unit. Please contact admin to reassign.',
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
        
        $message = "✅ Assignment confirmed! You have been successfully assigned to **" . htmlspecialchars($unit['unit_name']) . "** (" . htmlspecialchars($unit['unit_code']) . ").\n\n";
        $message .= "You will receive further details via email shortly.\n";
        $message .= "Welcome to the team!";
        
        return [
            'fulfillmentText' => $message,
            'source' => 'dialogflow-webhook'
        ];
        
    } catch (Exception $e) {
        error_log('[DIALOGFLOW] Error in confirmAssignment: ' . $e->getMessage());
        return [
            'fulfillmentText' => 'Error confirming assignment: ' . htmlspecialchars($e->getMessage()),
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
                'fulfillmentText' => 'Volunteer ID ' . htmlspecialchars($volunteer_id) . ' not found.',
                'source' => 'dialogflow-webhook'
            ];
        }
        
        $skills = [];
        
        if ($volunteer['skills_basic_firefighting']) $skills[] = '🔥 Basic Firefighting';
        if ($volunteer['skills_first_aid_cpr']) $skills[] = '🏥 First Aid/CPR';
        if ($volunteer['skills_search_rescue']) $skills[] = '🔍 Search & Rescue';
        if ($volunteer['skills_driving']) $skills[] = '🚗 Driving';
        if ($volunteer['skills_communication']) $skills[] = '📢 Communication';
        if ($volunteer['skills_mechanical']) $skills[] = '⚙️ Mechanical';
        if ($volunteer['skills_logistics']) $skills[] = '📦 Logistics';
        
        $skillsList = !empty($skills) ? implode("\n", $skills) : "No specialized skills recorded.";
        
        $message = "**Your Skills & Qualifications:**\n\n";
        $message .= $skillsList . "\n\n";
        $message .= "📚 Education: " . htmlspecialchars($volunteer['education']) . "\n";
        $message .= "💪 Physical Fitness: " . htmlspecialchars($volunteer['physical_fitness']) . "\n";
        $message .= "🌍 Languages: " . htmlspecialchars($volunteer['languages_spoken']);
        
        return [
            'fulfillmentText' => $message,
            'source' => 'dialogflow-webhook'
        ];
        
    } catch (Exception $e) {
        error_log('[DIALOGFLOW] Error in getVolunteerSkills: ' . $e->getMessage());
        return [
            'fulfillmentText' => 'Error retrieving skills: ' . htmlspecialchars($e->getMessage()),
            'source' => 'dialogflow-webhook'
        ];
    }
}

?>
