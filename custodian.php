<?php
header('Content-Type: application/json');
require_once 'db_connection.php'; // Adjust this to your database connection file

class CustodianAPI {
    private $conn;
    
    public function __construct($conn) {
        $this->conn = $conn;
    }
    
    public function getBookInventorySummary() {
        try {
            $response = [
                'success' => true,
                'timestamp' => date('Y-m-d H:i:s'),
                'data' => []
            ];
            
            // 1. Total book inventory statistics
            $totalStats = $this->getTotalInventoryStats();
            $response['data']['total_inventory'] = $totalStats;
            
            // 2. Detailed status breakdown
            $statusBreakdown = $this->getStatusBreakdown();
            $response['data']['status_breakdown'] = $statusBreakdown;
            
            // 3. Damaged books details
            $damagedBooks = $this->getDamagedBooksDetails();
            $response['data']['damaged_books'] = $damagedBooks;
            
            // 4. Lost books details
            $lostBooks = $this->getLostBooksDetails();
            $response['data']['lost_books'] = $lostBooks;
            
            // 5. Books by condition
            $conditionBreakdown = $this->getConditionBreakdown();
            $response['data']['condition_breakdown'] = $conditionBreakdown;
            
            // 6. Recent transactions (last 30 days)
            $recentTransactions = $this->getRecentTransactions();
            $response['data']['recent_transactions'] = $recentTransactions;
            
            // 7. Category-wise inventory
            $categoryStats = $this->getCategoryWiseStats();
            $response['data']['category_stats'] = $categoryStats;
            
            // 8. Value assessment (based on purchase prices)
            $valueAssessment = $this->getValueAssessment();
            $response['data']['value_assessment'] = $valueAssessment;
            
            return json_encode($response, JSON_PRETTY_PRINT);
            
        } catch (Exception $e) {
            return json_encode([
                'success' => false,
                'error' => $e->getMessage(),
                'timestamp' => date('Y-m-d H:i:s')
            ], JSON_PRETTY_PRINT);
        }
    }
    
    private function getTotalInventoryStats() {
        $stats = [];
        
        // Total books (titles)
        $query = "SELECT COUNT(DISTINCT id) as total_titles FROM books WHERE is_active = 1";
        $result = $this->conn->query($query);
        $stats['total_book_titles'] = $result->fetch_assoc()['total_titles'];
        
        // Total physical copies
        $query = "SELECT COUNT(*) as total_copies FROM book_copies WHERE is_active = 1";
        $result = $this->conn->query($query);
        $stats['total_physical_copies'] = $result->fetch_assoc()['total_copies'];
        
        // Total copies value (estimated)
        $query = "SELECT 
                    COUNT(bc.id) as total_copies,
                    COALESCE(SUM(b.price), 0) as estimated_value
                  FROM book_copies bc
                  JOIN books b ON bc.book_id = b.id
                  WHERE bc.is_active = 1 AND b.is_active = 1";
        $result = $this->conn->query($query);
        $valueData = $result->fetch_assoc();
        $stats['estimated_total_value'] = number_format($valueData['estimated_value'], 2);
        
        return $stats;
    }
    
    private function getStatusBreakdown() {
        $breakdown = [];
        
        $query = "SELECT 
                    status,
                    COUNT(*) as count,
                    ROUND((COUNT(*) * 100.0 / (SELECT COUNT(*) FROM book_copies WHERE is_active = 1)), 2) as percentage
                  FROM book_copies 
                  WHERE is_active = 1
                  GROUP BY status
                  ORDER BY count DESC";
        
        $result = $this->conn->query($query);
        while ($row = $result->fetch_assoc()) {
            $breakdown[] = [
                'status' => $row['status'],
                'count' => (int)$row['count'],
                'percentage' => (float)$row['percentage']
            ];
        }
        
        return $breakdown;
    }
    
    private function getDamagedBooksDetails() {
        $damaged = [];
        
        // Summary
        $query = "SELECT 
                    COUNT(*) as total_damaged,
                    COUNT(DISTINCT book_id) as unique_titles_damaged,
                    COALESCE(SUM(b.price), 0) as estimated_value
                  FROM book_copies bc
                  JOIN books b ON bc.book_id = b.id
                  WHERE bc.status = 'damaged' 
                    AND bc.is_active = 1 
                    AND b.is_active = 1";
        
        $result = $this->conn->query($query);
        $summary = $result->fetch_assoc();
        
        $damaged['summary'] = [
            'total_damaged_copies' => (int)$summary['total_damaged'],
            'unique_titles_affected' => (int)$summary['unique_titles_damaged'],
            'estimated_value_damaged' => number_format($summary['estimated_value'], 2)
        ];
        
        // Detailed list
        $query = "SELECT 
                    bc.id as copy_id,
                    bc.copy_number,
                    bc.barcode,
                    b.id as book_id,
                    b.title,
                    b.author,
                    b.isbn,
                    bc.book_condition,
                    bc.current_section,
                    bc.current_shelf,
                    bc.current_row,
                    bc.current_slot,
                    b.price as book_price,
                    bc.acquisition_date
                  FROM book_copies bc
                  JOIN books b ON bc.book_id = b.id
                  WHERE bc.status = 'damaged' 
                    AND bc.is_active = 1 
                    AND b.is_active = 1
                  ORDER BY b.title, bc.copy_number";
        
        $result = $this->conn->query($query);
        $damaged['details'] = [];
        
        while ($row = $result->fetch_assoc()) {
            $damaged['details'][] = [
                'copy_id' => (int)$row['copy_id'],
                'copy_number' => $row['copy_number'],
                'barcode' => $row['barcode'],
                'book_id' => (int)$row['book_id'],
                'title' => $row['title'],
                'author' => $row['author'],
                'isbn' => $row['isbn'],
                'condition' => $row['book_condition'],
                'location' => $row['current_section'] . 
                             '-S' . $row['current_shelf'] . 
                             '-R' . $row['current_row'] . 
                             '-P' . $row['current_slot'],
                'price' => number_format($row['book_price'], 2),
                'acquisition_date' => $row['acquisition_date']
            ];
        }
        
        // Damage types from recent reports
        $query = "SELECT 
                    dt.name as damage_type,
                    COUNT(*) as occurrence_count,
                    dt.fee_amount
                  FROM lost_damaged_reports ldr
                  JOIN damage_types dt ON FIND_IN_SET(dt.id, REPLACE(REPLACE(ldr.damage_types, '[', ''), ']', '')) > 0
                  WHERE ldr.report_type = 'damaged'
                    AND ldr.status = 'resolved'
                  GROUP BY dt.id, dt.name, dt.fee_amount
                  ORDER BY occurrence_count DESC";
        
        $result = $this->conn->query($query);
        $damaged['damage_types_analysis'] = [];
        
        while ($row = $result->fetch_assoc()) {
            $damaged['damage_types_analysis'][] = [
                'damage_type' => $row['damage_type'],
                'occurrence_count' => (int)$row['occurrence_count'],
                'fee_amount' => number_format($row['fee_amount'], 2)
            ];
        }
        
        return $damaged;
    }
    
    private function getLostBooksDetails() {
        $lost = [];
        
        // Summary
        $query = "SELECT 
                    COUNT(*) as total_lost,
                    COUNT(DISTINCT book_id) as unique_titles_lost,
                    COALESCE(SUM(b.price), 0) as estimated_value,
                    COALESCE(SUM(bl.lost_fee), 0) as total_fees_charged
                  FROM book_copies bc
                  JOIN books b ON bc.book_id = b.id
                  LEFT JOIN borrow_logs bl ON bc.id = bl.book_copy_id 
                    AND bl.lost_status IN ('presumed_lost', 'confirmed_lost')
                  WHERE bc.status = 'lost' 
                    AND bc.is_active = 1 
                    AND b.is_active = 1";
        
        $result = $this->conn->query($query);
        $summary = $result->fetch_assoc();
        
        $lost['summary'] = [
            'total_lost_copies' => (int)$summary['total_lost'],
            'unique_titles_lost' => (int)$summary['unique_titles_lost'],
            'estimated_value_lost' => number_format($summary['estimated_value'], 2),
            'total_fees_recovered' => number_format($summary['total_fees_charged'], 2)
        ];
        
        // Detailed list
        $query = "SELECT 
                    bc.id as copy_id,
                    bc.copy_number,
                    bc.barcode,
                    b.id as book_id,
                    b.title,
                    b.author,
                    b.isbn,
                    bc.acquisition_date,
                    b.price as book_price,
                    MAX(ldr.report_date) as last_reported_date,
                    MAX(ldr.fee_charged) as last_fee_charged,
                    MAX(bl.lost_date) as lost_date
                  FROM book_copies bc
                  JOIN books b ON bc.book_id = b.id
                  LEFT JOIN lost_damaged_reports ldr ON bc.id = ldr.book_copy_id 
                    AND ldr.report_type = 'lost'
                  LEFT JOIN borrow_logs bl ON bc.id = bl.book_copy_id 
                    AND bl.lost_status IN ('presumed_lost', 'confirmed_lost')
                  WHERE bc.status = 'lost' 
                    AND bc.is_active = 1 
                    AND b.is_active = 1
                  GROUP BY bc.id, bc.copy_number, bc.barcode, b.id, b.title, 
                           b.author, b.isbn, bc.acquisition_date, b.price
                  ORDER BY b.title, bc.copy_number";
        
        $result = $this->conn->query($query);
        $lost['details'] = [];
        
        while ($row = $result->fetch_assoc()) {
            $lost['details'][] = [
                'copy_id' => (int)$row['copy_id'],
                'copy_number' => $row['copy_number'],
                'barcode' => $row['barcode'],
                'book_id' => (int)$row['book_id'],
                'title' => $row['title'],
                'author' => $row['author'],
                'isbn' => $row['isbn'],
                'acquisition_date' => $row['acquisition_date'],
                'book_price' => number_format($row['book_price'], 2),
                'last_reported_date' => $row['last_reported_date'],
                'last_fee_charged' => number_format($row['last_fee_charged'], 2),
                'lost_date' => $row['lost_date']
            ];
        }
        
        // Lost books by patron (if available)
        $query = "SELECT 
                    p.name as patron_name,
                    p.library_id,
                    COUNT(DISTINCT bc.id) as lost_books_count,
                    COALESCE(SUM(ldr.fee_charged), 0) as total_fees_charged
                  FROM book_copies bc
                  JOIN lost_damaged_reports ldr ON bc.id = ldr.book_copy_id
                  JOIN patrons p ON ldr.patron_id = p.id
                  WHERE bc.status = 'lost' 
                    AND ldr.report_type = 'lost'
                    AND ldr.status = 'resolved'
                  GROUP BY p.id, p.name, p.library_id
                  ORDER BY lost_books_count DESC";
        
        $result = $this->conn->query($query);
        $lost['by_patron'] = [];
        
        while ($row = $result->fetch_assoc()) {
            $lost['by_patron'][] = [
                'patron_name' => $row['patron_name'],
                'library_id' => $row['library_id'],
                'lost_books_count' => (int)$row['lost_books_count'],
                'total_fees_charged' => number_format($row['total_fees_charged'], 2)
            ];
        }
        
        return $lost;
    }
    
    private function getConditionBreakdown() {
        $conditions = [];
        
        $query = "SELECT 
                    book_condition,
                    COUNT(*) as count,
                    ROUND((COUNT(*) * 100.0 / (SELECT COUNT(*) FROM book_copies WHERE is_active = 1)), 2) as percentage
                  FROM book_copies 
                  WHERE is_active = 1
                  GROUP BY book_condition
                  ORDER BY 
                    CASE book_condition
                        WHEN 'new' THEN 1
                        WHEN 'good' THEN 2
                        WHEN 'fair' THEN 3
                        WHEN 'poor' THEN 4
                        WHEN 'damaged' THEN 5
                        WHEN 'lost' THEN 6
                        ELSE 7
                    END";
        
        $result = $this->conn->query($query);
        while ($row = $result->fetch_assoc()) {
            $conditions[] = [
                'condition' => $row['book_condition'],
                'count' => (int)$row['count'],
                'percentage' => (float)$row['percentage']
            ];
        }
        
        return $conditions;
    }
    
    private function getRecentTransactions() {
        $transactions = [];
        
        // Last 30 days transactions
        $query = "SELECT 
                    ct.transaction_type,
                    COUNT(*) as transaction_count,
                    DATE(ct.created_at) as transaction_date,
                    GROUP_CONCAT(DISTINCT bc.copy_number ORDER BY bc.copy_number SEPARATOR ', ') as affected_copies
                  FROM copy_transactions ct
                  JOIN book_copies bc ON ct.book_copy_id = bc.id
                  WHERE ct.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
                  GROUP BY ct.transaction_type, DATE(ct.created_at)
                  ORDER BY ct.created_at DESC
                  LIMIT 20";
        
        $result = $this->conn->query($query);
        while ($row = $result->fetch_assoc()) {
            $transactions[] = [
                'transaction_type' => $row['transaction_type'],
                'date' => $row['transaction_date'],
                'count' => (int)$row['transaction_count'],
                'affected_copies' => $row['affected_copies']
            ];
        }
        
        return $transactions;
    }
    
    private function getCategoryWiseStats() {
        $categories = [];
        
        $query = "SELECT 
                    c.name as category_name,
                    c.section_code,
                    COUNT(DISTINCT b.id) as book_titles,
                    COUNT(bc.id) as total_copies,
                    SUM(CASE WHEN bc.status = 'available' THEN 1 ELSE 0 END) as available_copies,
                    SUM(CASE WHEN bc.status = 'borrowed' THEN 1 ELSE 0 END) as borrowed_copies,
                    SUM(CASE WHEN bc.status = 'damaged' THEN 1 ELSE 0 END) as damaged_copies,
                    SUM(CASE WHEN bc.status = 'lost' THEN 1 ELSE 0 END) as lost_copies,
                    COALESCE(SUM(b.price), 0) as total_value
                  FROM categories c
                  LEFT JOIN books b ON c.id = b.category_id AND b.is_active = 1
                  LEFT JOIN book_copies bc ON b.id = bc.book_id AND bc.is_active = 1
                  WHERE c.is_active = 1
                  GROUP BY c.id, c.name, c.section_code
                  ORDER BY c.name";
        
        $result = $this->conn->query($query);
        while ($row = $result->fetch_assoc()) {
            $categories[] = [
                'category' => $row['category_name'],
                'section_code' => $row['section_code'],
                'book_titles' => (int)$row['book_titles'],
                'total_copies' => (int)$row['total_copies'],
                'available_copies' => (int)$row['available_copies'],
                'borrowed_copies' => (int)$row['borrowed_copies'],
                'damaged_copies' => (int)$row['damaged_copies'],
                'lost_copies' => (int)$row['lost_copies'],
                'total_value' => number_format($row['total_value'], 2),
                'availability_rate' => $row['total_copies'] > 0 ? 
                    round(($row['available_copies'] / $row['total_copies']) * 100, 2) : 0
            ];
        }
        
        return $categories;
    }
    
    private function getValueAssessment() {
        $assessment = [];
        
        // Total value by status
        $query = "SELECT 
                    bc.status,
                    COUNT(bc.id) as copy_count,
                    COALESCE(SUM(b.price), 0) as total_value,
                    ROUND(AVG(b.price), 2) as average_price
                  FROM book_copies bc
                  JOIN books b ON bc.book_id = b.id
                  WHERE bc.is_active = 1 AND b.is_active = 1
                  GROUP BY bc.status
                  ORDER BY total_value DESC";
        
        $result = $this->conn->query($query);
        $assessment['value_by_status'] = [];
        
        while ($row = $result->fetch_assoc()) {
            $assessment['value_by_status'][] = [
                'status' => $row['status'],
                'copy_count' => (int)$row['copy_count'],
                'total_value' => number_format($row['total_value'], 2),
                'average_price' => number_format($row['average_price'], 2)
            ];
        }
        
        // Top 10 most valuable books (by price)
        $query = "SELECT 
                    b.id,
                    b.title,
                    b.author,
                    b.isbn,
                    COUNT(bc.id) as copy_count,
                    b.price as unit_price,
                    (COUNT(bc.id) * b.price) as total_value
                  FROM books b
                  JOIN book_copies bc ON b.id = bc.book_id AND bc.is_active = 1
                  WHERE b.is_active = 1
                  GROUP BY b.id, b.title, b.author, b.isbn, b.price
                  ORDER BY b.price DESC
                  LIMIT 10";
        
        $result = $this->conn->query($query);
        $assessment['most_valuable_books'] = [];
        
        while ($row = $result->fetch_assoc()) {
            $assessment['most_valuable_books'][] = [
                'book_id' => (int)$row['id'],
                'title' => $row['title'],
                'author' => $row['author'],
                'isbn' => $row['isbn'],
                'copy_count' => (int)$row['copy_count'],
                'unit_price' => number_format($row['unit_price'], 2),
                'total_value' => number_format($row['total_value'], 2)
            ];
        }
        
        // Value of lost/damaged inventory
        $query = "SELECT 
                    'damaged' as type,
                    COUNT(bc.id) as copy_count,
                    COALESCE(SUM(b.price), 0) as total_value
                  FROM book_copies bc
                  JOIN books b ON bc.book_id = b.id
                  WHERE bc.status = 'damaged' AND bc.is_active = 1 AND b.is_active = 1
                  UNION ALL
                  SELECT 
                    'lost' as type,
                    COUNT(bc.id) as copy_count,
                    COALESCE(SUM(b.price), 0) as total_value
                  FROM book_copies bc
                  JOIN books b ON bc.book_id = b.id
                  WHERE bc.status = 'lost' AND bc.is_active = 1 AND b.is_active = 1";
        
        $result = $this->conn->query($query);
        $assessment['lost_damaged_value'] = [];
        
        while ($row = $result->fetch_assoc()) {
            $assessment['lost_damaged_value'][] = [
                'type' => $row['type'],
                'copy_count' => (int)$row['copy_count'],
                'total_value' => number_format($row['total_value'], 2)
            ];
        }
        
        return $assessment;
    }
}

// API Endpoint Handler
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    // Check if it's the custodian endpoint
    if (isset($_GET['endpoint']) && $_GET['endpoint'] === 'custodian-inventory') {
        
        // Create database connection (adjust these parameters)
        $servername = "localhost";
        $username = "root";
        $password = "";
        $dbname = "libraryfinal";
        
        $conn = new mysqli($servername, $username, $password, $dbname);
        
        if ($conn->connect_error) {
            die(json_encode([
                'success' => false,
                'error' => 'Database connection failed: ' . $conn->connect_error
            ]));
        }
        
        // Create API instance and get data
        $api = new CustodianAPI($conn);
        echo $api->getBookInventorySummary();
        
        $conn->close();
    } else {
        echo json_encode([
            'success' => false,
            'error' => 'Invalid endpoint. Use ?endpoint=custodian-inventory',
            'available_endpoints' => ['custodian-inventory']
        ]);
    }
} else {
    echo json_encode([
        'success' => false,
        'error' => 'Method not allowed. Use GET request.'
    ]);
}
?>