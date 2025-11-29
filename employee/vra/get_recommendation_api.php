<?php
/**
 * API endpoint to get AI unit recommendations
 * Fixed version - unified with get_ai_recommendation.php
 * This uses the same logic as the main recommendation engine
 */

header('Content-Type: application/json');
require_once '../../config/db_connection.php';

// Require authentication
session_start();
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit();
}

try {
    // Get volunteer ID from POST request
    $data = json_decode(file_get_contents('php://input'), true);
    $volunteer_id = $data['volunteer_id'] ?? $_POST['volunteer_id'] ?? $_GET['volunteer_id'] ?? null;
    
    if (!$volunteer_id) {
        http_response_code(400);
        echo json_encode(['error' => 'Volunteer ID required']);
        exit();
    }
    
    // Fetch volunteer data
    $query = "SELECT * FROM volunteers WHERE id = ? AND status = 'approved'";
    $stmt = $pdo->prepare($query);
    $stmt->execute([$volunteer_id]);
    $volunteer = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$volunteer) {
        http_response_code(404);
        echo json_encode(['error' => 'Volunteer not found or not approved']);
        exit();
    }
    
    // Call unified recommendation logic
    $recommendations = callUnifiedRecommendationAPI($volunteer, $pdo);
    
    echo json_encode([
        'success' => true,
        'recommendations' => $recommendations,
        'volunteer_id' => $volunteer_id
    ]);
    
} catch (Exception $e) {
    // <CHANGE> Return JSON error instead of HTML
    error_log('Error in get_recommendation_api.php: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Server error: ' . $e->getMessage()]);
}

/**
 * Unified recommendation API - same logic as get_ai_recommendation.php
 * Ensures consistent results across all endpoints
 */
function callUnifiedRecommendationAPI($volunteer, $pdo) {
    try {
        // <CHANGE> Unified skill mapping - all BINARY fields
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
            $matchedSkills = [];
            
            if (isset($skillMapping[$unitType])) {
                $skillWeights = $skillMapping[$unitType];
                
                foreach ($skillWeights as $skillField => $weight) {
                    // <CHANGE> Check binary skill value
                    if (isset($volunteer[$skillField]) && (int)$volunteer[$skillField] === 1) {
                        $score += $weight;
                        $skillName = ucwords(str_replace(['skills_', '_'], ['', ' '], $skillField));
                        $matchedSkills[] = $skillName;
                    }
                }
            }
            
            // <CHANGE> Physical fitness bonus
            if (!empty($volunteer['physical_fitness'])) {
                if ($volunteer['physical_fitness'] === 'Excellent') {
                    $score += 15;
                    $matchedSkills[] = 'Physical Fitness (Excellent)';
                } elseif ($volunteer['physical_fitness'] === 'Good') {
                    $score += 8;
                    $matchedSkills[] = 'Physical Fitness (Good)';
                }
            }
            
            if ($score > 0) {
                $recommendations[] = [
                    'unit_id' => $unit['id'],
                    'unit_name' => $unit['unit_name'],
                    'unit_code' => $unit['unit_code'],
                    'unit_type' => $unitType,
                    'location' => $unit['location'],
                    'capacity' => $unit['capacity'],
                    'current_count' => $unit['current_count'],
                    'available_spots' => $unit['capacity'] - $unit['current_count'],
                    'score' => min(100, $score),
                    'matched_skills' => $matchedSkills
                ];
            }
        }
        
        // Sort by score descending
        usort($recommendations, function($a, $b) {
            return $b['score'] - $a['score'];
        });
        
        return array_slice($recommendations, 0, 3); // Return top 3
        
    } catch (Exception $e) {
        error_log('Error in callUnifiedRecommendationAPI: ' . $e->getMessage());
        throw $e;
    }
}
?>