<?php
// auto_sync_trainings.php
require_once 'config/db_connection.php';
require_once 'includes/sync_trainings.php';

// Run sync
$result = syncTrainingData($pdo);

// Log the result
$log_file = 'logs/training_sync.log';
$log_entry = date('Y-m-d H:i:s') . ' - ' . 
             ($result['success'] ? 'SUCCESS' : 'FAILED') . ' - ' . 
             json_encode($result) . PHP_EOL;

// Ensure logs directory exists
if (!file_exists('logs')) {
    mkdir('logs', 0755, true);
}

file_put_contents($log_file, $log_entry, FILE_APPEND);

if ($result['success']) {
    echo "Sync completed successfully. Created: {$result['created']}, Updated: {$result['updated']}, Total: {$result['total']}";
} else {
    echo "Sync failed: {$result['error']}";
    http_response_code(500);
}
?>