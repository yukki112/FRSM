<?php
/**
 * API endpoint to get AI unit recommendations via Dialogflow
 * Called from the approve_applications page
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

// Get volunteer ID from POST request
$data = json_decode(file_get_contents('php://input'), true);
$volunteer_id = $data['volunteer_id'] ?? null;

if (!$volunteer_id) {
    http_response_code(400);
    echo json_encode(['error' => 'Volunteer ID required']);
    exit();
}

try {
    // Fetch volunteer data
    $query = "SELECT * FROM volunteers WHERE id = ?";
    $stmt = $pdo->prepare($query);
    $stmt->execute([$volunteer_id]);
    $volunteer = $stmt->fetch();
    
    if (!$volunteer) {
        http_response_code(404);
        echo json_encode(['error' => 'Volunteer not found']);
        exit();
    }
    
    // Call Dialogflow API directly
    $recommendations = callDialogflowAPI($volunteer, $volunteer_id);
    
    echo json_encode([
        'success' => true,
        'recommendations' => $recommendations,
        'volunteer_id' => $volunteer_id
    ]);
    
} catch (Exception $e) {
    error_log('Error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}

/**
 * Call Dialogflow API with local recommendation logic
 */
function callDialogflowAPI($volunteer, $volunteer_id) {
    global $pdo;
    
    // Skill-to-unit mapping (same as webhook)
    $skillMapping = [
        'Fire' => [
            'skills_basic_firefighting' => 40,
            'skills_physical_fitness' => 20,
            'skills_driving' => 10,
            'skills_communication' => 10,
            'skills_first_aid_cpr' => 20
        ],
        'Rescue' => [
            'skills_search_rescue' => 40,
            'skills_driving' => 15,
            'skills_communication' => 15,
            'skills_physical_fitness' => 20,
            'skills_first_aid_cpr' => 10
        ],
        'EMS' => [
            'skills_first_aid_cpr' => 50,
            'skills_communication' => 20,
            'skills_driving' => 15,
            'skills_physical_fitness' => 15
        ],
        'Logistics' => [
            'skills_logistics' => 40,
            'skills_mechanical' => 20,
            'skills_driving' => 15,
            'skills_communication' => 15,
            'skills_physical_fitness' => 10
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
    $units = $unitsStmt->fetchAll();
    
    $recommendations = [];
    
    foreach ($units as $unit) {
        $unitType = $unit['unit_type'];
        $score = 0;
        $matchedSkills = [];
        
        if (isset($skillMapping[$unitType])) {
            $skillWeights = $skillMapping[$unitType];
            
            foreach ($skillWeights as $skillField => $weight) {
                if ($volunteer[$skillField] == 1) {
                    $score += $weight;
                    $matchedSkills[] = ucwords(str_replace('skills_', '', $skillField));
                }
            }
        }
        
        // Bonus for physical fitness
        if ($volunteer['physical_fitness'] === 'Excellent') {
            $score += 10;
        } elseif ($volunteer['physical_fitness'] === 'Good') {
            $score += 5;
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
}
?>
