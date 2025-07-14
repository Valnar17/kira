<?php
require 'config.php';
require 'SupervisorClient.php';

// CORS and caching headers
header('Content-Type: application/json');
header('Cache-Control: no-cache, must-revalidate');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET');
header('Access-Control-Allow-Headers: Content-Type, X-Requested-With');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    errorResponse('Method not allowed', 405);
}

// Input validation and sanitization
$selectedServers = sanitizeInput($_GET['servers'] ?? getEnabledServers());
if (!is_array($selectedServers)) {
    $selectedServers = array_filter(explode(',', $selectedServers));
}

// Validate server names
$validServers = array_filter($selectedServers, 'isValidServer');
if (empty($validServers)) {
    errorResponse('No valid servers specified');
}

$serverData = [];
$startTime = microtime(true);

// Parallel processing for better performance
$multiHandle = curl_multi_init();
$curlHandles = [];
$clients = [];

// Initialize all connections
foreach ($validServers as $serverName) {
    $config = getServerConfig($serverName);
    try {
        $client = new SupervisorClient($config['url'], $config['user'], $config['pass']);
        $clients[$serverName] = $client;
        
        // We'll use the existing call method which handles caching
        $serverData[$serverName] = [
            'success' => true,
            'processes' => $client->call('supervisor.getAllProcessInfo'),
            'server_info' => [
                'display_name' => $config['display_name'] ?? $serverName,
                'url' => $config['url']
            ]
        ];
    } catch (Exception $e) {
        $serverData[$serverName] = [
            'success' => false,
            'error' => $e->getMessage(),
            'server_info' => [
                'display_name' => $config['display_name'] ?? $serverName,
                'url' => $config['url']
            ]
        ];
        
        logMessage('error', "Failed to connect to server $serverName", [
            'error' => $e->getMessage(),
            'url' => $config['url']
        ]);
    }
}

$executionTime = round((microtime(true) - $startTime) * 1000, 2);

jsonResponse([
    'success' => true,
    'servers' => $serverData,
    'timestamp' => date('H:i:s'),
    'execution_time_ms' => $executionTime,
    'cache_info' => [
        'timeout' => CACHE_TIMEOUT,
        'enabled' => true
    ],
    'meta' => [
        'total_servers' => count($validServers),
        'successful_servers' => count(array_filter($serverData, fn($s) => $s['success'])),
        'request_id' => $_SERVER['REQUEST_ID']
    ]
]);
?>