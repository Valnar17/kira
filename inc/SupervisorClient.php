
<?php

/**
 * Optimized Supervisor Client with Connection Pooling and Caching
 */
class SupervisorClient
{
    private string $url;
    private ?string $auth;
    private static array $connectionPool = [];
    private static array $cache = [];
    private int $cacheTimeout = 5; // 5 seconds cache

    public function __construct(string $url, string $username = '', string $password = '')
    {
        $this->url = rtrim($url, '/');
        $this->auth = $username && $password ? "$username:$password" : null;
    }

    /**
     * Make XML-RPC call with caching and connection pooling
     */
    public function call(string $method, array $params = []): mixed
    {
        // Create cache key for read operations
        $cacheKey = md5($this->url . $method . serialize($params));
        $isReadOperation = in_array($method, [
            'supervisor.getAllProcessInfo',
            'supervisor.getProcessInfo',
            'supervisor.readProcessStdoutLog',
            'supervisor.readProcessStderrLog',
            'supervisor.getVersion'
        ]);

        // Check cache for read operations
        if ($isReadOperation && isset(self::$cache[$cacheKey])) {
            $cacheData = self::$cache[$cacheKey];
            if (time() - $cacheData['timestamp'] < $this->cacheTimeout) {
                return $cacheData['data'];
            }
            unset(self::$cache[$cacheKey]);
        }

        $request = xmlrpc_encode_request($method, $params, ['encoding' => 'utf-8']);
        $ch = $this->getCurlHandle();

        curl_setopt($ch, CURLOPT_POSTFIELDS, $request);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if (curl_errno($ch)) {
            $error = curl_error($ch);
            curl_close($ch);
            throw new Exception("Connection Error: $error");
        }

        if ($httpCode !== 200) {
            curl_close($ch);
            throw new Exception("HTTP Error: $httpCode - Response: " . substr($response, 0, 200));
        }

        curl_close($ch);

        // Debug: Log response for troubleshooting
        if (empty($response)) {
            throw new Exception("Empty response from server");
        }

        // Validate that response is valid XML before decoding
        if (!$this->isValidXml($response)) {
            throw new Exception("Invalid XML response from server: " . substr($response, 0, 200));
        }

        $result = xmlrpc_decode($response);

        // Check if xmlrpc_decode failed
        if ($result === null) {
            throw new Exception("Failed to decode XML-RPC response: " . substr($response, 0, 200));
        }

        // For XML-RPC fault detection, we need to check if it's an array first
        if (is_array($result) && xmlrpc_is_fault($result)) {
            throw new Exception("XML-RPC Fault: " . ($result['faultString'] ?? 'Unknown fault'));
        }

        // Cache read operations
        if ($isReadOperation) {
            self::$cache[$cacheKey] = [
                'data' => $result,
                'timestamp' => time()
            ];
        }

        return $result;
    }

    /**
     * Validate if string is valid XML
     */
    private function isValidXml(string $xml): bool
    {
        // Basic XML validation
        if (empty($xml) || !str_contains($xml, '<?xml')) {
            return false;
        }

        // Use libxml to validate
        $oldSetting = libxml_use_internal_errors(true);
        $doc = simplexml_load_string($xml);
        $errors = libxml_get_errors();
        libxml_clear_errors();
        libxml_use_internal_errors($oldSetting);

        return $doc !== false && empty($errors);
    }

    /**
     * Optimized cURL handle creation with connection reuse
     */
    private function getCurlHandle()
    {
        $connectionKey = md5($this->url . ($this->auth ?? ''));

        // Don't reuse connections as it might cause issues with XML-RPC
        // Create fresh connection each time for better reliability
        $ch = curl_init($this->url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ['Content-Type: text/xml; charset=utf-8'],
            CURLOPT_TIMEOUT => 15,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_USERAGENT => 'Hydra SupervisorClient/2.0',
            CURLOPT_POST => true,
        ]);

        if ($this->auth) {
            curl_setopt($ch, CURLOPT_USERPWD, $this->auth);
            curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
        }

        return $ch;
    }

    /**
     * Optimized time difference formatting
     */
    public function formatTimeDifference(int $startTimestamp, int $endTimestamp): string
    {
        // Handle invalid timestamps
        if ($startTimestamp <= 0 || $endTimestamp <= $startTimestamp) {
            return '0';
        }

        $diff = $endTimestamp - $startTimestamp;

        // Handle unrealistic differences (supervisor bug workaround)
        if ($diff > 1000000000) { // ~31 years
            return '0';
        }

        $days = intval($diff / 86400);
        $hours = intval(($diff % 86400) / 3600);
        $minutes = intval(($diff % 3600) / 60);
        $seconds = $diff % 60;

        if ($days > 0) {
            return sprintf("%dd %02d:%02d:%02d", $days, $hours, $minutes, $seconds);
        }

        return sprintf("%02d:%02d:%02d", $hours, $minutes, $seconds);
    }

    /**
     * Batch process operations for better performance
     */
    public function batchOperation(string $operation, array $processes): array
    {
        $results = [];
        $errors = [];

        foreach ($processes as $process) {
            $processName = is_array($process)
                ? $process['group'] . ':' . $process['name']
                : $process;

            try {
                switch ($operation) {
                    case 'start':
                        $this->call('supervisor.startProcess', [$processName, false]);
                        $results[] = "Started: $processName";
                        break;
                    case 'stop':
                        $this->call('supervisor.stopProcess', [$processName, false]);
                        $results[] = "Stopped: $processName";
                        break;
                    case 'restart':
                        try {
                            $this->call('supervisor.stopProcess', [$processName, false]);
                        } catch (Exception $e) {
                            // Continue if already stopped
                        }
                        usleep(100000); // 0.1 second delay
                        $this->call('supervisor.startProcess', [$processName, false]);
                        $results[] = "Restarted: $processName";
                        break;
                }
            } catch (Exception $e) {
                $errors[] = "Failed $operation on $processName: " . $e->getMessage();
            }
        }

        return ['results' => $results, 'errors' => $errors];
    }

    /**
     * Clear cache manually
     */
    public static function clearCache(): void
    {
        self::$cache = [];
    }

    /**
     * Cleanup resources
     */
    public static function cleanup(): void
    {
        foreach (self::$connectionPool as $ch) {
            curl_close($ch);
        }
        self::$connectionPool = [];
        self::$cache = [];
    }
}

// Cleanup on script end
register_shutdown_function([SupervisorClient::class, 'cleanup']);