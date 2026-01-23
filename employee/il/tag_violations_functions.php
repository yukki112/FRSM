<?php
require_once '../../config/db_connection.php';

// Function to get violations by status
function getViolationsByStatus($pdo, $status = null, $search = null, $date_from = null, $date_to = null, $severity = null) {
    $sql = "SELECT iv.*, ir.report_number, ir.inspection_date, 
                   ie.establishment_name, ie.address, ie.barangay,
                   ir.inspected_by, u.first_name, u.last_name
            FROM inspection_violations iv
            JOIN inspection_reports ir ON iv.inspection_id = ir.id
            JOIN inspection_establishments ie ON ir.establishment_id = ie.id
            LEFT JOIN users u ON ir.inspected_by = u.id
            WHERE 1=1";
    
    $params = [];
    
    if ($status && $status !== 'all') {
        $sql .= " AND iv.status = ?";
        $params[] = $status;
    }
    
    if ($severity && $severity !== 'all') {
        $sql .= " AND iv.severity = ?";
        $params[] = $severity;
    }
    
    if ($search) {
        $sql .= " AND (ie.establishment_name LIKE ? OR ir.report_number LIKE ? 
                OR iv.violation_code LIKE ? OR iv.violation_description LIKE ?)";
        $search_param = "%$search%";
        $params[] = $search_param;
        $params[] = $search_param;
        $params[] = $search_param;
        $params[] = $search_param;
    }
    
    if ($date_from) {
        $sql .= " AND DATE(iv.created_at) >= ?";
        $params[] = $date_from;
    }
    
    if ($date_to) {
        $sql .= " AND DATE(iv.created_at) <= ?";
        $params[] = $date_to;
    }
    
    $sql .= " ORDER BY 
                CASE iv.severity 
                    WHEN 'critical' THEN 1
                    WHEN 'major' THEN 2
                    WHEN 'minor' THEN 3
                    ELSE 4
                END,
                CASE iv.status
                    WHEN 'pending' THEN 1
                    WHEN 'overdue' THEN 2
                    WHEN 'rectified' THEN 3
                    ELSE 4
                END,
                iv.compliance_deadline ASC,
                iv.created_at DESC";
    
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Error fetching violations: " . $e->getMessage());
        return [];
    }
}

// Function to get violation details
function getViolationDetails($pdo, $violation_id) {
    $sql = "SELECT iv.*, ir.*, ie.*,
                   u.first_name as inspector_first, u.last_name as inspector_last,
                   ur.first_name as rectified_by_first, ur.last_name as rectified_by_last
            FROM inspection_violations iv
            JOIN inspection_reports ir ON iv.inspection_id = ir.id
            JOIN inspection_establishments ie ON ir.establishment_id = ie.id
            LEFT JOIN users u ON ir.inspected_by = u.id
            LEFT JOIN users ur ON iv.rectified_by = ur.id
            WHERE iv.id = ?";
    
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$violation_id]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Error fetching violation details: " . $e->getMessage());
        return null;
    }
}

// Function to update violation status
function updateViolationStatus($pdo, $violation_id, $status, $user_id, $notes = null, $evidence_file = null) {
    try {
        $sql = "UPDATE inspection_violations 
                SET status = ?,
                    rectified_at = CASE 
                        WHEN ? = 'rectified' THEN NOW() 
                        ELSE rectified_at 
                    END,
                    rectified_by = CASE 
                        WHEN ? = 'rectified' THEN ? 
                        ELSE rectified_by 
                    END,
                    rectified_evidence = ?,
                    admin_notes = CONCAT_WS('\n', admin_notes, ?),
                    updated_at = NOW()
                WHERE id = ?";
        
        $stmt = $pdo->prepare($sql);
        $rectified_by = ($status === 'rectified') ? $user_id : null;
        $stmt->execute([
            $status, 
            $status, 
            $status, 
            $rectified_by,
            $evidence_file,
            $notes,
            $violation_id
        ]);
        
        return $stmt->rowCount() > 0;
    } catch (PDOException $e) {
        error_log("Error updating violation status: " . $e->getMessage());
        return false;
    }
}

// Function to get violation statistics
function getViolationStatistics($pdo, $user_id = null) {
    $stats = [
        'total' => 0,
        'pending' => 0,
        'rectified' => 0,
        'overdue' => 0,
        'critical' => 0,
        'major' => 0,
        'minor' => 0,
        'total_fines' => 0,
        'collected_fines' => 0
    ];
    
    try {
        // Get count statistics
        $sql = "SELECT 
                    COUNT(*) as total,
                    SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending,
                    SUM(CASE WHEN status = 'rectified' THEN 1 ELSE 0 END) as rectified,
                    SUM(CASE WHEN status = 'overdue' THEN 1 ELSE 0 END) as overdue,
                    SUM(CASE WHEN severity = 'critical' THEN 1 ELSE 0 END) as critical,
                    SUM(CASE WHEN severity = 'major' THEN 1 ELSE 0 END) as major,
                    SUM(CASE WHEN severity = 'minor' THEN 1 ELSE 0 END) as minor,
                    SUM(COALESCE(fine_amount, 0)) as total_fines,
                    SUM(CASE WHEN status = 'rectified' THEN COALESCE(fine_amount, 0) ELSE 0 END) as collected_fines
                FROM inspection_violations
                WHERE 1=1";
        
        if ($user_id) {
            $sql .= " AND inspection_id IN (
                        SELECT id FROM inspection_reports WHERE inspected_by = ?
                    )";
            $params = [$user_id];
        } else {
            $params = [];
        }
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($result) {
            $stats = array_merge($stats, $result);
        }
        
        return $stats;
    } catch (PDOException $e) {
        error_log("Error getting violation statistics: " . $e->getMessage());
        return $stats;
    }
}

// Function to handle file upload
function uploadEvidenceFile($file) {
    if ($file['error'] !== UPLOAD_ERR_OK) {
        return null;
    }
    
    // Validate file type
    $allowed_types = ['image/jpeg', 'image/png', 'image/gif', 'application/pdf'];
    $file_type = mime_content_type($file['tmp_name']);
    
    if (!in_array($file_type, $allowed_types)) {
        return null;
    }
    
    // Validate file size (max 5MB)
    if ($file['size'] > 5 * 1024 * 1024) {
        return null;
    }
    
    // Generate unique filename
    $extension = pathinfo($file['name'], PATHINFO_EXTENSION);
    $filename = 'evidence_' . uniqid() . '_' . time() . '.' . $extension;
    $upload_path = '../../uploads/violation_evidence/';
    
    // Create directory if it doesn't exist
    if (!is_dir($upload_path)) {
        mkdir($upload_path, 0755, true);
    }
    
    $destination = $upload_path . $filename;
    
    if (move_uploaded_file($file['tmp_name'], $destination)) {
        return 'violation_evidence/' . $filename;
    }
    
    return null;
}
?>