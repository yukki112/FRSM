<?php
require_once '../../config/db_connection.php';

$api_url = "https://ecs.jampzdev.com/api/emergencies/active";

echo "<h1>Testing API Connection</h1>";
echo "<p>API URL: $api_url</p>";

try {
    $context = stream_context_create([
        'ssl' => [
            'verify_peer' => false,
            'verify_peer_name' => false,
        ],
        'http' => [
            'timeout' => 10
        ]
    ]);
    
    $response = file_get_contents($api_url, false, $context);
    
    if ($response === false) {
        echo "<p style='color: red;'>Failed to fetch from API</p>";
        echo "<p>Error: " . error_get_last()['message'] . "</p>";
    } else {
        echo "<p style='color: green;'>Successfully fetched from API</p>";
        $data = json_decode($response, true);
        
        echo "<h2>API Response:</h2>";
        echo "<pre>" . print_r($data, true) . "</pre>";
        
        echo "<h2>Database Check:</h2>";
        // Check what's in your database
        $sql = "SELECT external_id, title, location, status FROM api_incidents ORDER BY external_id DESC LIMIT 10";
        $stmt = $pdo->query($sql);
        $db_incidents = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo "<h3>Latest 10 incidents in database:</h3>";
        echo "<pre>" . print_r($db_incidents, true) . "</pre>";
        
        echo "<h3>Checking for incident ID 18:</h3>";
        $check_sql = "SELECT * FROM api_incidents WHERE external_id = 18";
        $check_stmt = $pdo->query($check_sql);
        $incident_18 = $check_stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($incident_18) {
            echo "<p style='color: green;'>✓ Incident 18 found in database</p>";
            echo "<pre>" . print_r($incident_18, true) . "</pre>";
        } else {
            echo "<p style='color: red;'>✗ Incident 18 NOT found in database</p>";
            
            // Check if it exists in API response
            echo "<h3>Checking API data for ID 18:</h3>";
            $found_in_api = false;
            foreach ($data['data'] as $incident) {
                if ($incident['id'] == 18) {
                    $found_in_api = true;
                    echo "<p style='color: green;'>✓ Incident 18 found in API response</p>";
                    echo "<pre>" . print_r($incident, true) . "</pre>";
                    
                    // Try to insert it
                    echo "<h3>Attempting to insert incident 18:</h3>";
                    require_once 'fetch_from_api.php';
                    $test_result = fetchAndSyncIncidentsFromAPI($pdo);
                    echo "<pre>" . print_r($test_result, true) . "</pre>";
                    break;
                }
            }
            
            if (!$found_in_api) {
                echo "<p style='color: red;'>✗ Incident 18 NOT found in API response either</p>";
            }
        }
    }
    
} catch (Exception $e) {
    echo "<p style='color: red;'>Exception: " . $e->getMessage() . "</p>";
}
?>