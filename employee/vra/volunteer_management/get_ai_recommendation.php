<?php
/**
 * GET AI RECOMMENDATION API
 * Calls AI to get unit recommendation for a volunteer
 * Based on their skills from the database (value = 1)
 */

session_start();
header('Content-Type: application/json');

require_once '../../../config/db_connection.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

try {
    // Get volunteer ID from request
    $volunteer_id = $_POST['volunteer_id'] ?? $_GET['volunteer_id'] ?? null;
    
    if (!$volunteer_id) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Volunteer ID is required']);
        exit();
    }
    
    // Fetch volunteer data from database
    $query = "SELECT * FROM volunteers WHERE id = ? AND status = 'approved'";
    $stmt = $pdo->prepare($query);
    $stmt->execute([$volunteer_id]);
    $volunteer = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$volunteer) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Volunteer not found or not approved']);
        exit();
    }
    
    // Calculate AI recommendation based on volunteer skills
    $recommendation = calculateAIRecommendation($volunteer, $pdo);
    
    echo json_encode([
        'success' => true,
        'recommendation' => $recommendation,
        'volunteer' => [
            'id' => $volunteer['id'],
            'name' => $volunteer['full_name'],
            'email' => $volunteer['email']
        ]
    ]);
    
} catch (Exception $e) {
    error_log('Error in get_ai_recommendation: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Server error: ' . $e->getMessage()]);
    exit();
}

/**
 * Calculate AI recommendation based on volunteer skills
 * Skills with value 1 are considered active
 */
function calculateAIRecommendation($volunteer, $pdo) {
    try {
        // Define skill-to-unit mapping with weights (higher = better match)
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
        
        // Calculate score for each unit
        foreach ($units as $unit) {
            $unitType = $unit['unit_type'];
            $score = 0;
            $matchedSkills = [];
            
            // Get skill weights for this unit type
            if (isset($skillMapping[$unitType])) {
                $skillWeights = $skillMapping[$unitType];
                
                // Calculate score based on volunteer's active skills
                foreach ($skillWeights as $skillField => $weight) {
                    // Check if volunteer has this skill (value = 1)
                    if (isset($volunteer[$skillField]) && $volunteer[$skillField] == 1) {
                        $score += $weight;
                        
                        // Format skill name for display
                        $skillName = ucwords(str_replace(['skills_', '_'], ['', ' '], $skillField));
                        $matchedSkills[] = $skillName;
                    }
                }
            }
            
            // Only add if has at least one skill match
            if ($score > 0) {
                $available_spots = $unit['capacity'] - $unit['current_count'];
                
                $recommendations[] = [
                    'unit_id' => $unit['id'],
                    'unit_name' => $unit['unit_name'],
                    'unit_code' => $unit['unit_code'],
                    'unit_type' => $unitType,
                    'location' => $unit['location'],
                    'capacity' => $unit['capacity'],
                    'current_count' => $unit['current_count'],
                    'available_spots' => $available_spots,
                    'score' => min(100, $score),
                    'matchedSkills' => $matchedSkills,
                    'description' => $unit['description']
                ];
            }
        }
        
        // Sort by score descending (best matches first)
        usort($recommendations, function($a, $b) {
            return $b['score'] - $a['score'];
        });
        
        // Return top 3 recommendations
        return array_slice($recommendations, 0, 3);
        
    } catch (Exception $e) {
        error_log('Error in calculateAIRecommendation: ' . $e->getMessage());
        throw $e;
    }
}

?>
