
<?php

/**
 * Hydra Configuration with dotenv Support
 */

require_once __DIR__ . '/../vendor/autoload.php';

use Dotenv\Dotenv;

// Load environment variables
$dotenv = Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();

// Environment-based configuration
$env = $_ENV['HYDRA_ENV'] ?? 'production';

// Error reporting based on environment
if ($env === 'development') {
    error_reporting(E_ALL);
    ini_set('display_errors', 1);
} else {
    error_reporting(E_ERROR | E_WARNING | E_PARSE);
    ini_set('display_errors', 0);
}

// Application settings
define('HYDRA_VERSION', '2.0.0');
define('HYDRA_ENV', $env);
define('HYDRA_DEBUG', filter_var($_ENV['HYDRA_DEBUG'] ?? false, FILTER_VALIDATE_BOOLEAN));

// Performance settings
define('CACHE_TIMEOUT', (int)($_ENV['CACHE_TIMEOUT'] ?? 5));
define('REQUEST_TIMEOUT', (int)($_ENV['REQUEST_TIMEOUT'] ?? 10));
define('MAX_PROCESSES_PER_PAGE', (int)($_ENV['MAX_PROCESSES_PER_PAGE'] ?? 1000));

/**
 * Build supervisor servers configuration from environment variables
 */
function buildSupervisorServersFromEnv(): array
{
    $servers = [];
    $serverPrefixes = [];

    // Find all server prefixes from environment variables
    foreach ($_ENV as $key => $value) {
        if (preg_match('/^SERVER_([1-99_]+)_URL$/', $key, $matches)) {
            $serverPrefixes[] = $matches[1];
        }
    }

    // Build server configurations
    foreach ($serverPrefixes as $prefix) {
        $serverKey = str_replace('_', ' ', $prefix);
        $enabled = filter_var($_ENV["SERVER_{$prefix}_ENABLED"] ?? true, FILTER_VALIDATE_BOOLEAN);

        if ($enabled) {
            $servers[$serverKey] = [
                'url' => $_ENV["SERVER_{$prefix}_URL"],
                'user' => $_ENV["SERVER_{$prefix}_USER"],
                'pass' => $_ENV["SERVER_{$prefix}_PASS"],
                'display_name' => $_ENV["SERVER_{$prefix}_DISPLAY_NAME"] ?? $serverKey,
                'timeout' => (int)($_ENV["SERVER_{$prefix}_TIMEOUT"] ?? 10),
                'enabled' => true
            ];
        }
    }

    return $servers;
}

/**
 * Supervisor Server Configuration from Environment
 */
$SUPERVISOR_SERVERS = buildSupervisorServersFromEnv();

/**
 * Validate server configuration
 */
function validateServerConfig(array $servers): array
{
    $errors = [];

    foreach ($servers as $key => $config) {
        if (empty($config['url'])) {
            $errors[] = "Server '$key': URL is required";
        }

        if (!filter_var($config['url'], FILTER_VALIDATE_URL)) {
            $errors[] = "Server '$key': Invalid URL format";
        }

        if (empty($config['user']) || empty($config['pass'])) {
            $errors[] = "Server '$key': Username and password are required";
        }
    }

    return $errors;
}

// Validate configuration
$configErrors = validateServerConfig($SUPERVISOR_SERVERS);
if (!empty($configErrors) && HYDRA_DEBUG) {
    error_log('Hydra Configuration Errors: ' . implode(', ', $configErrors));
}

/**
 * Helper functions
 */
function getServerConfig(string $serverName): ?array
{
    global $SUPERVISOR_SERVERS;
    return $SUPERVISOR_SERVERS[$serverName] ?? null;
}

function getEnabledServers(): array
{
    global $SUPERVISOR_SERVERS;
    return array_keys($SUPERVISOR_SERVERS);
}

function isValidServer(string $serverName): bool
{
    global $SUPERVISOR_SERVERS;
    return isset($SUPERVISOR_SERVERS[$serverName]);
}

/**
 * Response helpers
 */
function jsonResponse(array $data, int $httpCode = 200): void
{
    http_response_code($httpCode);
    header('Content-Type: application/json');
    header('Cache-Control: no-cache, must-revalidate');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

function errorResponse(string $message, int $httpCode = 400): void
{
    jsonResponse(['error' => $message, 'timestamp' => date('c')], $httpCode);
}

/**
 * Input sanitization
 */
function sanitizeInput(mixed $input): mixed
{
    if (is_string($input)) {
        return trim(htmlspecialchars($input, ENT_QUOTES, 'UTF-8'));
    }

    if (is_array($input)) {
        return array_map('sanitizeInput', $input);
    }

    return $input;
}

/**
 * Logging function
 */
function logMessage(string $level, string $message, array $context = []): void
{
    if (!HYDRA_DEBUG && $level === 'debug') {
        return;
    }

    $logEntry = [
        'timestamp' => date('c'),
        'level' => $level,
        'message' => $message,
        'context' => $context,
        'request_id' => $_SERVER['REQUEST_ID'] ?? uniqid()
    ];

    error_log('[HYDRA] ' . json_encode($logEntry));
}

// Set request ID for tracking
if (!isset($_SERVER['REQUEST_ID'])) {
    $_SERVER['REQUEST_ID'] = uniqid('req_');
}

// Set timezone
date_default_timezone_set($_ENV['TIMEZONE'] ?? 'Europe/Berlin');
?>