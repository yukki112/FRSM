<?php
require_once '../config/db_connection.php';

header('Content-Type: application/json');

try {
    $stmt = $pdo->query("
        SELECT 
            id,
            COALESCE(name, 'Anonymous') as name,
            rating,
            message,
            is_anonymous,
            created_at
        FROM feedbacks 
        WHERE is_approved = 1 
        ORDER BY created_at DESC 
        LIMIT 10
    ");
    
    $feedbacks = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // If no feedbacks in database, use fallback testimonials
    if (empty($feedbacks)) {
        $feedbacks = [
            [
                'id' => 1,
                'name' => 'Maria Johnson',
                'rating' => 5,
                'message' => 'The quick response from Barangay Commonwealth Fire & Rescue saved our home during the recent fire incident. Their professionalism and dedication are truly commendable.',
                'is_anonymous' => 0,
                'created_at' => date('Y-m-d H:i:s')
            ],
            [
                'id' => 2,
                'name' => 'Carlos Reyes',
                'rating' => 5,
                'message' => 'Volunteering with the fire and rescue team has been one of the most rewarding experiences of my life. The training is excellent and the team feels like family.',
                'is_anonymous' => 0,
                'created_at' => date('Y-m-d H:i:s')
            ],
            [
                'id' => 3,
                'name' => 'Anna Santos',
                'rating' => 4,
                'message' => 'The fire safety seminar organized by the team was incredibly informative. I now feel much more prepared to handle emergency situations at home and work.',
                'is_anonymous' => 0,
                'created_at' => date('Y-m-d H:i:s')
            ]
        ];
    }
    
    echo json_encode(['success' => true, 'feedbacks' => $feedbacks]);
    
} catch (PDOException $e) {
    error_log("Get feedbacks error: " . $e->getMessage());
    echo json_encode(['success' => false, 'feedbacks' => []]);
}