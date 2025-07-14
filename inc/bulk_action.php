<?php
require 'config.php';
require 'SupervisorClient.php';

// Headers
header('Content-Type: application/json');
header('Cache-Control: no-cache');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    errorResponse('Method not allowed', 405);
}

// Parse input
$input = json_decode(file_get_contents('php://input'), true);
if (json_last_error() !== JSON_ERROR_NONE) {
    errorResponse('Invalid JSON payload');
}

// Validate required fields
$serverName = sanitizeInput($input['server'] ?? '');
$action = sanitizeInput($input['action'] ?? '');

if (!isValidServer($serverName)) {
    errorResponse('Invalid server specified');
}

$allowedActions = ['start_all', 'stop_all', 'restart_all', 'restart_running'];
if (!in_array($action, $allowedActions)) {
    errorResponse('Invalid bulk action specified');
}

// Rate limiting for bulk operations
session_start();
$rateLimitKey = "bulk_rate_limit_{$serverName}";
$now = time();
$lastBulkAction = $_SESSION[$rateLimitKey] ?? 0;

if ($now - $lastBulkAction < 5) { // 5 second cooldown for bulk operations
    errorResponse('Bulk operation rate limit exceeded. Please wait 5 seconds.', 429);
}

$_SESSION[$rateLimitKey] = $now;

$config = getServerConfig($serverName);
$startTime = microtime(true);

try {
    $client = new SupervisorClient($config['url'], $config['user'], $config['pass']);
    $processes = $client->call('supervisor.getAllProcessInfo');

    // Filter processes based on action
    $targetProcesses = [];

    switch ($action) {
        case 'start_all':
            $targetProcesses = array_filter($processes, fn($proc) => $proc['statename'] !== 'RUNNING');
            $operation = 'start';
            break;

        case 'stop_all':
            $targetProcesses = array_filter($processes, fn($proc) => $proc['statename'] === 'RUNNING');
            $operation = 'stop';
            break;

        case 'restart_all':
            $targetProcesses = $processes;
            $operation = 'restart';
            break;

        case 'restart_running':
            $targetProcesses = array_filter($processes, fn($proc) => $proc['statename'] === 'RUNNING');
            $operation = 'restart';
            break;
    }

    if (empty($targetProcesses)) {
        jsonResponse([
            'success' => true,
            'message' => 'No processes match the criteria for this action',
            'results' => [],
            'errors' => [],
            'total_processed' => 0
        ]);
    }

    // Perform batch operation
    $batchResult = $client->batchOperation($operation, $targetProcesses);
    $executionTime = round((microtime(true) - $startTime) * 1000, 2);

    // Clear cache after bulk operations
    SupervisorClient::clearCache();

    logMessage('info', "Bulk action completed", [
        'server' => $serverName,
        'action' => $action,
        'processes_affected' => count($targetProcesses),
        'execution_time_ms' => $executionTime
    ]);

    jsonResponse([
        'success' => true,
        'results' => $batchResult['results'],
        'errors' => $batchResult['errors'],
        'total_processed' => count($batchResult['results']) + count($batchResult['errors']),
        'execution_time_ms' => $executionTime,
        'action' => $action,
        'server' => $serverName,
        'timestamp' => date('c')
    ]);

} catch (Exception $e) {
    logMessage('error', "Bulk action failed", [
        'server' => $serverName,
        'action' => $action,
        'error' => $e->getMessage()
    ]);

    errorResponse('Bulk operation failed: ' . $e->getMessage(), 500);
}
?>