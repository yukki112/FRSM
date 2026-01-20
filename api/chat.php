<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    exit(0);
}

// NEW API KEY (with fresh quota)
$apiKey = 'AIzaSyAlP0ZxszLjkhDOos8xNXogNYpEvld9uDU';

// Get the incoming message
$input = json_decode(file_get_contents('php://input'), true);
$userMessage = isset($input['message']) ? trim($input['message']) : '';

if (empty($userMessage)) {
    echo json_encode(['success' => false, 'error' => 'Empty message']);
    exit;
}

// Add system prompt for fire safety context
$systemPrompt = "You are a Fire & Rescue Assistant for Barangay Commonwealth. You provide information about:
1. Fire safety tips and prevention
2. Emergency procedures (call 911 for real emergencies)
3. Volunteer programs
4. Fire extinguisher usage
5. Evacuation procedures
6. Training programs
7. Incident reporting

Always be helpful, concise, and safety-focused. For real emergencies, always tell users to CALL 911 FIRST.

User message: " . $userMessage;

// Gemini 2.0 Flash Lite endpoint
$url = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.0-flash-lite:generateContent?key=' . $apiKey;

// Prepare the request data
$requestData = [
    'contents' => [
        [
            'parts' => [
                ['text' => $systemPrompt]
            ]
        ]
    ],
    'generationConfig' => [
        'temperature' => 0.7,
        'topK' => 40,
        'topP' => 0.95,
        'maxOutputTokens' => 800,
    ],
    'safetySettings' => [
        [
            'category' => 'HARM_CATEGORY_HARASSMENT',
            'threshold' => 'BLOCK_ONLY_HIGH'
        ],
        [
            'category' => 'HARM_CATEGORY_HATE_SPEECH',
            'threshold' => 'BLOCK_ONLY_HIGH'
        ],
        [
            'category' => 'HARM_CATEGORY_SEXUALLY_EXPLICIT',
            'threshold' => 'BLOCK_ONLY_HIGH'
        ],
        [
            'category' => 'HARM_CATEGORY_DANGEROUS_CONTENT',
            'threshold' => 'BLOCK_ONLY_HIGH'
        ]
    ]
];

// Initialize cURL
$ch = curl_init();

curl_setopt_array($ch, [
    CURLOPT_URL => $url,
    CURLOPT_POST => true,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => [
        'Content-Type: application/json',
    ],
    CURLOPT_POSTFIELDS => json_encode($requestData),
    CURLOPT_TIMEOUT => 15,
    CURLOPT_SSL_VERIFYPEER => true,
    CURLOPT_FAILONERROR => true
]);

// Execute the request
$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$error = curl_error($ch);

curl_close($ch);

// Check for cURL errors
if ($error) {
    echo json_encode([
        'success' => false,
        'error' => 'Connection error: ' . $error
    ]);
    exit;
}

// Check HTTP status code
if ($httpCode !== 200) {
    // Try to get error details
    $errorDetails = json_decode($response, true);
    $errorMsg = 'API request failed with code: ' . $httpCode;
    
    if (isset($errorDetails['error']['message'])) {
        $errorMsg = $errorDetails['error']['message'];
    }
    
    // If quota exceeded on new key too, fall back to FAQ system
    if ($httpCode === 429) {
        echo json_encode([
            'success' => false,
            'error' => 'Daily limit reached. Using FAQ system instead.',
            'fallback' => true
        ]);
    } else {
        echo json_encode([
            'success' => false,
            'error' => $errorMsg,
            'http_code' => $httpCode
        ]);
    }
    exit;
}

// Decode the response
$responseData = json_decode($response, true);

// Extract the reply
if (isset($responseData['candidates'][0]['content']['parts'][0]['text'])) {
    $reply = $responseData['candidates'][0]['content']['parts'][0]['text'];
    
    // Add emergency reminder if not already present
    if (stripos($reply, '911') === false && stripos($reply, 'emergency') === false) {
        $reply .= "\n\n**Remember:** For real emergencies, always call 911 first!";
    }
    
    echo json_encode([
        'success' => true,
        'reply' => $reply
    ]);
    
} elseif (isset($responseData['promptFeedback']['blockReason'])) {
    // Handle blocked content
    echo json_encode([
        'success' => false,
        'error' => 'Message was filtered for safety. Please try a different question about fire safety.',
        'fallback' => true
    ]);
    
} else {
    // Unexpected response - fall back to FAQ
    echo json_encode([
        'success' => false,
        'error' => 'Unable to get response. Using FAQ system.',
        'fallback' => true
    ]);
}
?>