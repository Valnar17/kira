<?php
require 'config.php';
require 'SupervisorClient.php';

$serverKey = $_GET['server'] ?? '';
$name = $_GET['name'] ?? '';
$ajax = $_GET['ajax'] ?? false;

if (!isset($SUPERVISOR_SERVERS[$serverKey])) {
    if ($ajax) {
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Ungültiger Server']);
        exit;
    }
    echo "Ungültiger Server";
    exit;
}

$conf = $SUPERVISOR_SERVERS[$serverKey];
$client = new SupervisorClient($conf['url'], $conf['user'], $conf['pass']);

if (!$name) {
    if ($ajax) {
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Prozessname fehlt']);
        exit;
    }
    echo "Prozessname fehlt";
    exit;
}

try {
    $stdout = $client->call('supervisor.readProcessStdoutLog', [$name, 0, 5000]);
    $stderr = $client->call('supervisor.readProcessStderrLog', [$name, 0, 5000]);
    
    if ($ajax) {
        header('Content-Type: application/json');
        echo json_encode([
            'success' => true,
            'process' => $name,
            'server' => $serverKey,
            'stdout' => $stdout,
            'stderr' => $stderr,
            'timestamp' => date('H:i:s')
        ]);
        exit;
    }
} catch (Exception $e) {
    if ($ajax) {
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Fehler: ' . $e->getMessage()]);
        exit;
    }
    echo "Fehler: " . $e->getMessage();
    exit;
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Logs für <?= htmlspecialchars($name) ?></title>
    <style>
        body { font-family: monospace; margin: 20px; }
        pre { background: #f5f5f5; padding: 15px; border-radius: 5px; white-space: pre-wrap; }
        h2 { color: #333; border-bottom: 2px solid #ddd; padding-bottom: 5px; }
    </style>
</head>
<body>

<h1>Logs für <?= htmlspecialchars($name) ?> (<?= htmlspecialchars($serverKey) ?>)</h1>

<h2>STDOUT</h2>
<pre><?= htmlspecialchars($stdout) ?></pre>

<h2>STDERR</h2>
<pre><?= htmlspecialchars($stderr) ?></pre>

</body>
</html>