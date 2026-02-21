<?php
// File: volunteers-api.php

header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Include database configuration
require_once 'config/db_connection.php'; // This will create $pdo variable

// Main API logic
try {
    // Get query parameters
    $barangay = isset($_GET['barangay']) ? trim($_GET['barangay']) : '';
    $skills = isset($_GET['skills']) ? trim($_GET['skills']) : '';
    $availability_date = isset($_GET['availability_date']) ? trim($_GET['availability_date']) : '';
    $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 20;
    $page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
    
    // Validate numeric parameters
    $limit = max(1, min(100, $limit));
    $page = max(1, $page);
    $offset = ($page - 1) * $limit;
    
    // Build the query
    $query = "
        SELECT 
            v.id as volunteer_id,
            CONCAT(v.first_name, ' ', COALESCE(v.middle_name, ''), ' ', v.last_name) as full_name,
            v.email,
            v.contact_number,
            v.address,
            v.status as volunteer_status,
            u.unit_name as assigned_unit,
            -- Skills
            v.skills_basic_firefighting,
            v.skills_first_aid_cpr,
            v.skills_search_rescue,
            v.skills_driving,
            v.driving_license_no,
            v.skills_communication,
            v.skills_mechanical,
            v.skills_logistics,
            -- Availability
            v.available_days,
            v.available_hours,
            v.emergency_response,
            -- Training
            v.training_completion_status,
            v.first_training_completed_at,
            v.active_since
        FROM volunteers v
        LEFT JOIN volunteer_assignments va ON v.id = va.volunteer_id AND va.status = 'Active'
        LEFT JOIN units u ON va.unit_id = u.id
        WHERE v.status = 'approved' 
          AND v.volunteer_status = 'Active'
    ";
    
    $params = [];
    $conditions = [];
    
    // Filter by barangay
    if (!empty($barangay)) {
        $conditions[] = "v.address LIKE ?";
        $params[] = "%$barangay%";
    }
    
    // Filter by skills
    if (!empty($skills)) {
        $skillArray = explode(',', $skills);
        $skillConditions = [];
        
        foreach ($skillArray as $skill) {
            $skill = trim($skill);
            switch ($skill) {
                case 'basic_firefighting':
                    $skillConditions[] = 'v.skills_basic_firefighting = 1';
                    break;
                case 'first_aid_cpr':
                    $skillConditions[] = 'v.skills_first_aid_cpr = 1';
                    break;
                case 'search_rescue':
                    $skillConditions[] = 'v.skills_search_rescue = 1';
                    break;
                case 'driving':
                    $skillConditions[] = 'v.skills_driving = 1';
                    break;
                case 'communication':
                    $skillConditions[] = 'v.skills_communication = 1';
                    break;
                case 'mechanical':
                    $skillConditions[] = 'v.skills_mechanical = 1';
                    break;
                case 'logistics':
                    $skillConditions[] = 'v.skills_logistics = 1';
                    break;
            }
        }
        
        if (!empty($skillConditions)) {
            $conditions[] = '(' . implode(' OR ', $skillConditions) . ')';
        }
    }
    
    // Check availability for specific date
    $hasDateFilter = false;
    if (!empty($availability_date) && strtotime($availability_date)) {
        $date = date('Y-m-d', strtotime($availability_date));
        $dayOfWeek = date('l', strtotime($date)); // e.g., "Monday"
        
        // Check if volunteer is available on that day
        $conditions[] = "v.available_days LIKE ?";
        $params[] = "%$dayOfWeek%";
        $hasDateFilter = true;
    }
    
    // Add conditions to query
    if (!empty($conditions)) {
        $query .= ' AND ' . implode(' AND ', $conditions);
    }
    
    // Create count query WITHOUT date subquery for simplicity
    $countQuery = "SELECT COUNT(*) as total FROM volunteers v WHERE v.status = 'approved' AND v.volunteer_status = 'Active'";
    if (!empty($conditions)) {
        $countQuery .= ' AND ' . implode(' AND ', $conditions);
    }
    
    $countParams = $params;
    
    $countStmt = $pdo->prepare($countQuery);
    $countStmt->execute($countParams);
    $totalResult = $countStmt->fetch();
    $total = $totalResult['total'];
    
    // Add date subquery if needed
    if ($hasDateFilter) {
        $query .= " AND NOT EXISTS (
            SELECT 1 FROM shifts s 
            WHERE s.volunteer_id = v.id 
            AND DATE(s.shift_date) = ?
            AND s.status IN ('scheduled', 'confirmed', 'in_progress')
        )";
        $params[] = $date;
    }
    
    // Add pagination and ordering - LIMIT/OFFSET should NOT be parameters
    $query .= " ORDER BY v.first_training_completed_at DESC, v.active_since DESC 
                LIMIT $limit OFFSET $offset";
    
    // Execute main query
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $volunteers = $stmt->fetchAll();
    
    // Transform the data
    $transformedVolunteers = [];
    foreach ($volunteers as $volunteer) {
        $availableDays = !empty($volunteer['available_days']) ? 
            explode(',', $volunteer['available_days']) : [];
        $availableHours = !empty($volunteer['available_hours']) ? 
            explode(',', $volunteer['available_hours']) : [];
        
        $transformedVolunteers[] = [
            'volunteer_id' => (int)$volunteer['volunteer_id'],
            'full_name' => $volunteer['full_name'],
            'email' => $volunteer['email'],
            'contact_number' => $volunteer['contact_number'],
            'address' => $volunteer['address'],
            'status' => $volunteer['volunteer_status'],
            'assigned_unit' => $volunteer['assigned_unit'] ?? 'Not assigned',
            'skills' => [
                'basic_firefighting' => (bool)$volunteer['skills_basic_firefighting'],
                'first_aid_cpr' => (bool)$volunteer['skills_first_aid_cpr'],
                'search_rescue' => (bool)$volunteer['skills_search_rescue'],
                'driving' => (bool)$volunteer['skills_driving'],
                'driving_license' => $volunteer['driving_license_no'],
                'communication' => (bool)$volunteer['skills_communication'],
                'mechanical' => (bool)$volunteer['skills_mechanical'],
                'logistics' => (bool)$volunteer['skills_logistics']
            ],
            'availability' => [
                'days' => $availableDays,
                'hours' => $availableHours,
                'emergency_response' => $volunteer['emergency_response']
            ],
            'training' => [
                'status' => $volunteer['training_completion_status'],
                'last_training_date' => $volunteer['first_training_completed_at'],
                'active_since' => $volunteer['active_since']
            ]
        ];
    }
    
    // Prepare response
    $response = [
        'success' => true,
        'timestamp' => date('Y-m-d H:i:s'),
        'data' => $transformedVolunteers,
        'pagination' => [
            'current_page' => $page,
            'per_page' => $limit,
            'total' => (int)$total,
            'total_pages' => ceil($total / $limit)
        ],
        'filters_applied' => [
            'barangay' => $barangay,
            'skills' => $skills,
            'availability_date' => $availability_date
        ]
    ];
    
    echo json_encode($response, JSON_PRETTY_PRINT);
    
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Database error occurred',
        'error' => $e->getMessage(),
        'query_debug' => isset($query) ? $query : 'No query',
        'params_debug' => isset($params) ? $params : 'No params'
    ]);
}