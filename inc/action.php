
<?php
require 'config.php';
require 'SupervisorClient.php';

// CORS Headers
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Requested-With');

// Handle preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

$isAjax = $_SERVER['REQUEST_METHOD'] === 'POST';
$allowedActions = ['start', 'stop', 'restart'];

// Parse input based on request method
if ($isAjax) {
    $contentType = $_SERVER['CONTENT_TYPE'] ?? '';

    if (strpos($contentType, 'application/json') !== false) {
        $input = json_decode(file_get_contents('php://input'), true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            errorResponse('Invalid JSON payload');
        }
    } else {
        $input = $_POST;
    }

    $serverKey = $input['server'] ?? '';
    $action = $input['action'] ?? '';
    $name = $input['name'] ?? '';
} else {
    // GET request (legacy support)
    $serverKey = $_GET['server'] ?? '';
    $action = $_GET['action'] ?? '';
    $name = $_GET['name'] ?? '';
}

// Input validation
$serverKey = sanitizeInput($serverKey);
$action = sanitizeInput($action);
$name = sanitizeInput($name);

if (!isValidServer($serverKey)) {
    errorResponse('Invalid server specified');
}

if (!in_array($action, $allowedActions)) {
    errorResponse('Invalid action specified');
}

if (empty($name) || !preg_match('/^[a-zA-Z0-9_:-]+$/', $name)) {
    errorResponse('Invalid process name specified');
}

// Rate limiting (simple implementation)
session_start();
$rateLimitKey = "rate_limit_{$serverKey}_{$action}";
$now = time();
$lastAction = $_SESSION[$rateLimitKey] ?? 0;

if ($now - $lastAction < 1) { // 1 second cooldown
    errorResponse('Rate limit exceeded. Please wait before retrying.', 429);
}

$_SESSION[$rateLimitKey] = $now;

// Execute action
$config = getServerConfig($serverKey);
$startTime = microtime(true);

try {
    $client = new SupervisorClient($config['url'], $config['user'], $config['pass']);

    switch ($action) {
        case 'start':
            $success = $client->call('supervisor.startProcess', [$name, true]);
            $message = "Started process: $name";
            break;

        case 'stop':
            $success = $client->call('supervisor.stopProcess', [$name, true]);
            $message = "Stopped process: $name";
            break;

        case 'restart':
            // Stop first (ignore errors if already stopped)
            try {
                $client->call('supervisor.stopProcess', [$name, true]);
                usleep(100000); // 0.1 second delay
            } catch (Exception $e) {
                // Continue if process was already stopped
            }

            $success = $client->call('supervisor.startProcess', [$name, true]);
            $message = "Restarted process: $name";
            break;
    }

    $executionTime = round((microtime(true) - $startTime) * 1000, 2);

    // Clear cache after write operations
    SupervisorClient::clearCache();

    logMessage('info', "Action completed successfully", [
        'server' => $serverKey,
        'action' => $action,
        'process' => $name,
        'execution_time_ms' => $executionTime
    ]);

    if ($isAjax) {
        jsonResponse([
            'success' => true,
            'message' => $message,
            'process' => $name,
            'action' => $action,
            'server' => $serverKey,
            'execution_time_ms' => $executionTime,
            'timestamp' => date('c')
        ]);
    } else {
        // Legacy redirect
        header("Location: index.php?" . http_build_query(['servers' => [$serverKey]]));
        exit;
    }

} catch (Exception $e) {
    $errorMsg = $e->getMessage();

    logMessage('error', "Action failed", [
        'server' => $serverKey,
        'action' => $action,
        'process' => $name,
        'error' => $errorMsg
    ]);

    if ($isAjax) {
        errorResponse("Action failed: $errorMsg", 500);
    } else {
        echo "❌ Error: " . htmlspecialchars($errorMsg);
    }
}
?>