<?php

error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);

// Session handling
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Set JSON header immediately
header('Content-Type: application/json');

// Check authentication
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

try {
    // Get volunteer ID
    $volunteer_id = $_POST['volunteer_id'] ?? $_GET['volunteer_id'] ?? null;

    if (!$volunteer_id) {
        echo json_encode(['success' => false, 'message' => 'Volunteer ID is required']);
        exit();
    }

    // Database connection
    $config_path = __DIR__ . '/../../../config/db_connection.php';
    if (!file_exists($config_path)) {
        throw new Exception('Database config not found');
    }
    require_once $config_path;

    // Get volunteer data
    $query = "SELECT * FROM volunteers WHERE id = ?";
    $stmt = $pdo->prepare($query);
    $stmt->execute([$volunteer_id]);
    $volunteer = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$volunteer) {
        echo json_encode(['success' => false, 'message' => 'Volunteer not found']);
        exit();
    }

    // Get REAL Dialogflow AI recommendation
    $recommendations = getRealDialogflowAI($volunteer, $pdo);

    echo json_encode([
        'success' => true,
        'recommendations' => $recommendations,
        'volunteer' => [
            'id' => $volunteer['id'],
            'name' => $volunteer['full_name'],
            'email' => $volunteer['email'],
            'physical_fitness' => $volunteer['physical_fitness']
        ],
        'ai_used' => 'Google Dialogflow AI',
        'timestamp' => date('Y-m-d H:i:s')
    ]);

} catch (Exception $e) {
    error_log('Dialogflow AI Error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'AI Service Error: ' . $e->getMessage()]);
}
exit();

/**
 * REAL Dialogflow AI Integration - NO FALLBACK
 */
function getRealDialogflowAI($volunteer, $pdo) {
    // Get active units
    $units = getAllActiveUnits($pdo);
    if (empty($units)) {
        throw new Exception('No active units available');
    }

    // Prepare data for AI
    $volunteerProfile = buildVolunteerProfile($volunteer);
    $unitsData = prepareUnitsData($units);

    // Call REAL Dialogflow API - NO FALLBACK
    $aiResponse = callDialogflowAPI($volunteerProfile, $unitsData);
    return processAIResponse($aiResponse, $units, $volunteer);
}

function callDialogflowAPI($volunteer, $units) {
    $projectId = 'volunteer-assignment-ai';
    
    $serviceAccount = [
        "type" => "service_account",
        "project_id" => "volunteer-assignment-ai",
        "private_key_id" => "f2a56711708cc0599f0998fb43e849b650eca103",
        "private_key" => "-----BEGIN PRIVATE KEY-----\nMIIEvQIBADANBgkqhkiG9w0BAQEFAASCBKcwggSjAgEAAoIBAQCOsVstUqcFIELA\nuhuM0VePP1+IjKR3ye+iUxdpd2pptjIvfSRywhyOvt5p8Oy0/bpST22QU1Ib6llO\nv1AgNv6b857sVWRMsphzlI89d55PSt7ETixykdtkD2148to5VKbGsbFcKSpZ4wWW\nHFfojT4A135XCqljxR1E1ybOVKXCA0lkFuHevqiLnp9T73+n04Dh6TjdV2Fr3kgV\n/nkq859w7Qu+gL7h1D9ltaxXP7ulf6BGUzIs0iLek32ww2oTf0c5O7+IwkEqe7gz\nDepOQYQyOK3eAdeAtwaP351QxwNXqI0oe1vb03FsSpqrivGNUGfDNriET6KjYBr9\nqaVW1KtZAgMBAAECggEAAtW3MAFSXCG18cpAvd1y1d+2cCoLIm4amqj38Bp1PMBn\n4tWjobwFgTh9hrFIopff2b7GOHXPTcqsF4ppdxpgmIyQfbV8lqF9sd4jsl7sADtG\njbPX4jbPmv9Ld3mrqDPsVEo3cdNHih2egMrzXCViM/YUBnqpvtetqy5zEOpRmLcc\naAN9gFuEI1LFuyBK4U2tvP/Xl5075ZBbEt44i6hHFHNAKBj1rQkIk6ZeLYkQIKYs\nWSXpzMSuqy1ecyG3N6qvocys3fYKuBzUX2xV+Mv+WrDnXQh3/RcrIlY35Df0igsZ\n72Jy1fwf4q5hqx6W+JGEyyver3DRO1XS5dpaW6N2GQKBgQDACafTMTk2kZoLXBgi\nIn/HHMJwl/0+AreeT1XgmPIImazeM+9qrfdg+oUan382fNbvMHNEaTe5MMsVIqDm\nkENhKafkMC4HpNTg4CgGCc+kj8/PxniAsTFfIENDaV34p1D9Udey2dwGptd2XZW2\nsxtvEwLmHn4Jf3YaIYAXNx+hKwKBgQC+OD3/XxDyy2+5vT5krbiVdWfxqwoDZr3v\niEWdUCxOvA1Uma9CgfpwLUPfNI8JvNoLRWfNxwwrkwj5K2YVIh9Ud1UzTpG7QuOy\ns+OmsvPPYH2y2QtNEPdBrwzF9ZjeQLtzCUq+jPwpVbd/PcjGnlaFuecrAzaHahjA\n00PcYWn7iwKBgEeS0bQLApHuDoXxWyVNymYBuA6S91XnWVxtoUpGdt7xt9ZRcQhH\nso24kWdsztMWEF2xpyR2OsiRAP/tmh6U4igSiHqp4l4C9zyhDwnBGlzxJLkB9eOx\nJv+XXLqBSP7mDW9803HbdQAdqux40NX5R15MraXq83rCwNfYaI8+glFlAoGBAKnY\n4lLh+eoxiINa3RlcnNKnULbTOE+tL69wCGjdK5LqCyUdTQaftJTxdgcZkRbqz+78\nfCGbt9w4n+yMucvo+fybyTHU1/9TTKlGQuGYLGdhCxvk/VhE6+J0gX1JPMRHHJkt\nFNZsYMQvy3cMHfhrbWpegnE/nzLuo0eZ3KAtQ0rdAoGAJzaWfwEZ27PymPU37wSU\nswJD+TaDJZB2lAfY3U48cXZxOTtECwSmtQR+RSXA2ldVyKvwtZnbmWLTbGfxiDtg\n+i37V7m9FzPVzRnzm4QHscEDdm6HTBg7m7sZkjM1sydZH6V4Dz6iOFlkEiTxyXVe\ni91CGpaJcYMcbUQIwZf8hVU=\n-----END PRIVATE KEY-----\n",
        "client_email" => "dialogflow-ai-service@volunteer-assignment-ai.iam.gserviceaccount.com",
        "client_id" => "113857395220241058527",
        "auth_uri" => "https://accounts.google.com/o/oauth2/auth",
        "token_uri" => "https://oauth2.googleapis.com/token",
        "auth_provider_x509_cert_url" => "https://www.googleapis.com/oauth2/v1/certs",
        "client_x509_cert_url" => "https://www.googleapis.com/robot/v1/metadata/x509/dialogflow-ai-service%40volunteer-assignment-ai.iam.gserviceaccount.com"
    ];

    // Test credentials first
    $accessToken = getAccessToken($serviceAccount);
    
    $query = buildAIQuery($volunteer, $units);
    $sessionId = uniqid('volunteer_');
    $url = "https://dialogflow.googleapis.com/v2/projects/{$projectId}/agent/sessions/{$sessionId}:detectIntent";
    
    $data = [
        'queryInput' => [
            'text' => [
                'text' => $query,
                'languageCode' => 'en-US'
            ]
        ]
    ];
    
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($data),
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $accessToken,
            'Content-Type: application/json; charset=utf-8',
        ],
        CURLOPT_TIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => true
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);
    
    // 🚨 DEBUG: Check what we're getting back
    error_log("Dialogflow Response - HTTP Code: {$httpCode}");
    error_log("Dialogflow Response - First 500 chars: " . substr($response, 0, 500));
    
    if ($httpCode !== 200) {
        // Check if response is HTML error page
        if (strpos($response, '<!DOCTYPE') !== false || strpos($response, '<html') !== false) {
            throw new Exception("Dialogflow returned HTML error page. Check service account permissions and project setup.");
        }
        throw new Exception("Dialogflow API error {$httpCode}: " . substr($response, 0, 200));
    }
    
    // 🚨 Check if response is HTML instead of JSON
    if (strpos(trim($response), '<') === 0) {
        error_log("HTML Response detected: " . substr($response, 0, 500));
        throw new Exception("Dialogflow returned HTML instead of JSON. Usually an authentication issue. Check service account setup.");
    }
    
    $result = json_decode($response, true);
    
    if (json_last_error() !== JSON_ERROR_NONE) {
        error_log("JSON decode error. Raw response: " . $response);
        throw new Exception("Invalid JSON response from Dialogflow: " . json_last_error_msg());
    }
    
    return $result;
}

/**
 * Get Google access token
 */
function getAccessToken($serviceAccount) {
    $tokenUrl = 'https://oauth2.googleapis.com/token';
    
    $header = [
        'alg' => 'RS256',
        'typ' => 'JWT'
    ];
    
    $now = time();
    $payload = [
        'iss' => $serviceAccount['client_email'],
        'scope' => 'https://www.googleapis.com/auth/dialogflow',
        'aud' => $serviceAccount['token_uri'],
        'exp' => $now + 3600,
        'iat' => $now
    ];
    
    $headerEncoded = base64UrlEncode(json_encode($header));
    $payloadEncoded = base64UrlEncode(json_encode($payload));
    
    $signature = '';
    $signSuccess = openssl_sign(
        $headerEncoded . '.' . $payloadEncoded,
        $signature,
        $serviceAccount['private_key'],
        'SHA256'
    );
    
    if (!$signSuccess) {
        throw new Exception('Failed to sign JWT for authentication');
    }
    
    $signatureEncoded = base64UrlEncode($signature);
    $jwt = $headerEncoded . '.' . $payloadEncoded . '.' . $signatureEncoded;
    
    $data = [
        'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
        'assertion' => $jwt
    ];
    
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $tokenUrl,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query($data),
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/x-www-form-urlencoded'
        ],
        CURLOPT_TIMEOUT => 10
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode !== 200) {
        throw new Exception("Token API error {$httpCode}. Check service account credentials.");
    }
    
    $tokenData = json_decode($response, true);
    
    if (!isset($tokenData['access_token'])) {
        throw new Exception('Failed to get access token. Service account may not have Dialogflow API access.');
    }
    
    return $tokenData['access_token'];
}

function base64UrlEncode($data) {
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

/**
 * Build AI query
 */
function buildAIQuery($volunteer, $units) {
    $skills = implode(', ', $volunteer['skills']);
    
    $query = "analyze volunteer skills for unit assignment ";
    $query .= "Volunteer has skills: {$skills}. ";
    $query .= "Physical fitness: {$volunteer['fitness']}. ";
    $query .= "Which emergency response units are most suitable?";
    
    return $query;
}

/**
 * Process AI response - NO FALLBACK
 */
function processAIResponse($aiResponse, $units, $volunteer) {
    $fulfillmentText = $aiResponse['queryResult']['fulfillmentText'] ?? '';
    $intent = $aiResponse['queryResult']['intent']['displayName'] ?? 'skill_analysis';
    $confidence = $aiResponse['queryResult']['intentDetectionConfidence'] ?? 0.8;
    
    error_log("Dialogflow Response - Intent: {$intent}, Confidence: {$confidence}");
    error_log("AI Text: {$fulfillmentText}");
    
    // NO FALLBACK - if empty response, throw exception
    if (empty($fulfillmentText)) {
        throw new Exception('Dialogflow AI returned empty response - check intent responses in Dialogflow console');
    }
    
    return extractRecommendationsFromAI($fulfillmentText, $units, $volunteer, $intent, $confidence);
}

/**
 * Extract recommendations from AI text - NO FALLBACK
 */
function extractRecommendationsFromAI($aiText, $units, $volunteer, $intent, $confidence) {
    $recommendations = [];
    $unitTypes = ['Fire', 'Rescue', 'EMS', 'Logistics', 'Command'];
    
    foreach ($unitTypes as $unitType) {
        if (stripos($aiText, $unitType) !== false) {
            $matchingUnits = array_filter($units, function($unit) use ($unitType) {
                return stripos($unit['unit_type'], $unitType) !== false;
            });
            
            foreach ($matchingUnits as $unit) {
                $available_spots = $unit['capacity'] - $unit['current_count'];
                if ($available_spots > 0) {
                    $score = calculateAIScore($aiText, $unitType);
                    $recommendations[] = [
                        'unit_id' => $unit['id'],
                        'unit_name' => $unit['unit_name'],
                        'unit_code' => $unit['unit_code'],
                        'unit_type' => $unit['unit_type'],
                        'location' => $unit['location'],
                        'capacity' => $unit['capacity'],
                        'current_count' => $unit['current_count'],
                        'available_spots' => $available_spots,
                        'score' => $score,
                        'matched_skills' => getMatchedSkills($volunteer, $unit),
                        'ai_confidence' => $score,
                        'ai_reasoning' => "Dialogflow AI analyzed skills and recommended {$unitType} unit",
                        'ai_model' => 'Google Dialogflow AI',
                        'dialogflow_intent' => $intent,
                        'dialogflow_confidence' => round($confidence * 100)
                    ];
                    break;
                }
            }
        }
    }
    
    // NO FALLBACK - if no recommendations found in AI response, throw exception
    if (empty($recommendations)) {
        throw new Exception('Dialogflow AI response did not contain any unit recommendations. Check AI responses in Dialogflow console.');
    }
    
    usort($recommendations, function($a, $b) {
        return $b['score'] - $a['score'];
    });
    
    return array_slice($recommendations, 0, 3);
}

function calculateAIScore($text, $unitType) {
    preg_match_all('/(\d{1,3})%/', $text, $matches);
    if (!empty($matches[1])) {
        return min(100, (int)$matches[1][0]);
    }
    
    $mentions = substr_count(strtolower($text), strtolower($unitType));
    return min(90, 70 + ($mentions * 10));
}

function buildVolunteerProfile($volunteer) {
    $skills = [];
    $skillMap = [
        'skills_basic_firefighting' => 'Firefighting',
        'skills_first_aid_cpr' => 'Medical',
        'skills_search_rescue' => 'Rescue',
        'skills_driving' => 'Driving',
        'skills_communication' => 'Communication',
        'skills_mechanical' => 'Mechanical',
        'skills_logistics' => 'Logistics'
    ];
    
    foreach ($skillMap as $dbField => $skillName) {
        if (isset($volunteer[$dbField]) && $volunteer[$dbField] == 1) {
            $skills[] = $skillName;
        }
    }
    
    return [
        'name' => $volunteer['full_name'],
        'skills' => $skills,
        'fitness' => $volunteer['physical_fitness'] ?? 'Good',
        'experience' => 'Intermediate'
    ];
}

function prepareUnitsData($units) {
    $unitsData = [];
    foreach ($units as $unit) {
        $unitsData[] = [
            'name' => $unit['unit_name'],
            'type' => $unit['unit_type'],
            'location' => $unit['location']
        ];
    }
    return $unitsData;
}

function getAllActiveUnits($pdo) {
    $query = "SELECT * FROM units WHERE status = 'Active'";
    $stmt = $pdo->prepare($query);
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function getMatchedSkills($volunteer, $unit) {
    $skills = [];
    $skillMap = [
        'skills_basic_firefighting' => 'Firefighting',
        'skills_first_aid_cpr' => 'Medical',
        'skills_search_rescue' => 'Rescue',
        'skills_driving' => 'Driving',
        'skills_communication' => 'Communication',
        'skills_mechanical' => 'Mechanical',
        'skills_logistics' => 'Logistics'
    ];
    
    foreach ($skillMap as $dbField => $name) {
        if (isset($volunteer[$dbField]) && $volunteer[$dbField] == 1) {
            $skills[] = $name;
        }
    }
    return $skills;
}