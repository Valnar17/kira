<?php
require 'inc/config.php';
require 'inc/SupervisorClient.php';

$selectedServers = $_GET['servers'] ?? array_keys($SUPERVISOR_SERVERS);
if (!is_array($selectedServers)) {
    $selectedServers = explode(',', $selectedServers);
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Kira - Supervisor Dashboard</title>
    <link rel="stylesheet" href="inc/styles.css">
</head>
<body>

<!-- Dark Mode Toggle - Now floating bottom left -->
<button class="dark-mode-toggle" onclick="toggleDarkMode()">🌙</button>

<!-- Loading Overlay -->
<div id="loadingOverlay" class="loading-overlay">
    <div class="loading-spinner">
        <div class="spinner"></div>
        <div id="loadingText">Processing...</div>
    </div>
</div>

<!-- Notification -->
<div id="notification" class="notification"></div>

<!-- Log Modal Popup -->
<div id="logModal" class="log-modal">
    <div class="log-modal-content">
        <div class="log-modal-header">
            <h2 id="logModalTitle">Process Logs</h2>
            <div class="log-modal-controls">
                <button id="refreshLogsBtn" class="btn btn-refresh" onclick="refreshLogs()">↻ REFRESH</button>
                <button class="log-modal-close" onclick="closeLogModal()">&times;</button>
            </div>
        </div>
        <div class="log-modal-body">
            <div class="log-section">
                <h3>📤 STDOUT</h3>
                <pre id="stdoutContent" class="log-content"></pre>
            </div>
            <div class="log-section">
                <h3>📥 STDERR</h3>
                <pre id="stderrContent" class="log-content"></pre>
            </div>
        </div>
        <div class="log-modal-footer">
            <span id="logTimestamp">Last updated: --:--:--</span>
        </div>
    </div>
</div>

<div id="sidebar">
    <h3>▣ SERVER SELECTION</h3>
    <form method="get" id="serverForm">
        <?php foreach ($SUPERVISOR_SERVERS as $key => $conf): ?>
            <label>
                <input type="checkbox" name="servers[]" value="<?= htmlspecialchars($key) ?>"
                    <?= in_array($key, $selectedServers) ? 'checked' : '' ?>>
                <?= htmlspecialchars($conf['display_name'] ?? $key) ?>
            </label>
        <?php endforeach; ?>
        <button type="submit">↻ REFRESH</button>
    </form>

    <div class="refresh-controls">
        <h4>⟲ AUTO-REFRESH</h4>
        <div class="refresh-interval">
            <span>Interval:</span>
            <input type="number" id="refreshInterval" min="5" max="300" value="30">
            <span>sec</span>
        </div>
        <div class="refresh-status">
            <div>
                <span class="refresh-indicator" id="refreshIndicator"></span>
                <span id="refreshStatusText">DISABLED</span>
            </div>
            <span class="countdown" id="countdown"></span>
        </div>
        <button type="button" id="toggleRefresh">ENABLE</button>
    </div>
</div>

<div id="content">
    <div class="content-header">
        <h1>Kira - SUPERVISOR DASHBOARD</h1>
        <div class="last-updated" id="lastUpdated">
            Last updated: <?= date('H:i:s') ?>
        </div>
    </div>

    <div id="serverContainer">
        <?php foreach ($selectedServers as $serverName): ?>
            <?php
            if (!isset($SUPERVISOR_SERVERS[$serverName])) continue;
            $conf = $SUPERVISOR_SERVERS[$serverName];
            $displayName = $conf['display_name'] ?? $serverName;

            try {
                $client = new SupervisorClient($conf['url'], $conf['user'], $conf['pass']);
                $processes = $client->call('supervisor.getAllProcessInfo');
            } catch (Exception $e) {
                echo "<div class='server-header'>";
                echo "<h2>▣ " . htmlspecialchars($displayName) . "</h2>";
//                echo "<div class='server-subtitle'>(" . htmlspecialchars($serverName) . ")</div>";
                echo "</div>";
                echo "<div class='error-message'>✗ ERROR: " . htmlspecialchars($e->getMessage()) . "</div>";
                continue;
            }
            ?>

            <div class="server-section" data-server="<?= htmlspecialchars($serverName) ?>">
                <div class="server-header">
                    <div class="server-title">
                        <h2>▣ <?= htmlspecialchars($displayName) ?></h2>
                    </div>
                    <div class="server-actions">
                        <button type="button" class="btn btn-start" onclick="performBulkAction('<?= htmlspecialchars($serverName) ?>', 'start_all')">▶ START ALL</button>
                        <button type="button" class="btn btn-stop" onclick="performBulkAction('<?= htmlspecialchars($serverName) ?>', 'stop_all')">■ STOP ALL</button>
                        <button type="button" class="btn btn-restart" onclick="performBulkAction('<?= htmlspecialchars($serverName) ?>', 'restart_all')">↻ RESTART ALL</button>
                        <button type="button" class="btn btn-restart-running" onclick="performBulkAction('<?= htmlspecialchars($serverName) ?>', 'restart_running')">↻ RESTART RUNNING</button>
                    </div>
                </div>

                <table class="process-table">
                    <tr>
                        <th>NAME</th>
                        <th>GROUP</th>
                        <th>PID</th>
                        <th>UPTIME</th>
                        <th>STATUS</th>
                        <th>ACTIONS</th>
                        <th>LOGS</th>
                    </tr>
                    <?php foreach ($processes as $proc): ?>
                        <tr class="<?= strtolower($proc['statename']) ?>" data-process="<?= htmlspecialchars($proc['group'] . ':' . $proc['name']) ?>">
                            <td><strong><?= htmlspecialchars($proc['name']) ?></strong></td>
                            <td><?= htmlspecialchars($proc['group']) ?></td>
                            <td class="pid-cell"><?= htmlspecialchars($proc['pid']) ?></td>
                            <td class="uptime-cell">
                                <?php
                                // Zeige 0 für gestoppte/exited Prozesse
                                if (in_array(strtolower($proc['statename']), ['stopped', 'exited', 'fatal'])) {
                                    echo '0';
                                } else {
                                    echo htmlspecialchars($client->formatTimeDifference($proc['start'], $proc['now']));
                                }
                                ?>
                            </td>
                            <td class="status-cell">
                                <span class="status-badge status-<?= strtolower($proc['statename']) ?>">
                                    <?php
                                    switch (strtolower($proc['statename'])) {
                                        case 'running':
                                            echo '● ' . $proc['statename'];
                                            break;
                                        case 'stopped':
                                            echo '⏸ ' . $proc['statename'];
                                            break;
                                        case 'exited':
                                            echo '✗ ' . $proc['statename'];
                                            break;
                                        case 'fatal':
                                            echo '⚠ ' . $proc['statename'];
                                            break;
                                        default:
                                            echo '○ ' . $proc['statename'];
                                    }
                                    ?>
                                </span>
                            </td>
                            <td class="actions-cell">
                                <div class="process-actions">
                                    <?php if ($proc['statename'] === 'RUNNING'): ?>
                                        <button class="btn btn-stop" onclick="performSingleAction('<?= htmlspecialchars($serverName) ?>', 'stop', '<?= htmlspecialchars($proc['group'] . ':' . $proc['name']) ?>')">■ STOP</button>
                                    <?php else: ?>
                                        <button class="btn btn-start" onclick="performSingleAction('<?= htmlspecialchars($serverName) ?>', 'start', '<?= htmlspecialchars($proc['group'] . ':' . $proc['name']) ?>')">▶ START</button>
                                    <?php endif; ?>
                                    <button class="btn btn-restart" onclick="performSingleAction('<?= htmlspecialchars($serverName) ?>', 'restart', '<?= htmlspecialchars($proc['group'] . ':' . $proc['name']) ?>')">↻ RESTART</button>
                                </div>
                            </td>
                            <td>
                                <button class="btn" onclick="showLogs('<?= htmlspecialchars($serverName) ?>', '<?= htmlspecialchars($proc['group'] . ':' . $proc['name']) ?>')">≡ LOGS</button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </table>
            </div>
        <?php endforeach; ?>
    </div>
</div>

<script>
let refreshInterval = null;
let countdownInterval = null;
let remainingTime = 0;
let currentLogServer = '';
let currentLogProcess = '';

function toggleDarkMode() {
    const body = document.body;
    const toggleButton = document.querySelector('.dark-mode-toggle');

    body.classList.toggle('dark-mode');

    if (body.classList.contains('dark-mode')) {
        toggleButton.textContent = '☀';
        localStorage.setItem('darkMode', 'enabled');
    } else {
        toggleButton.textContent = '🌙';
        localStorage.setItem('darkMode', 'disabled');
    }
}

function showNotification(message, type = 'info') {
    const notification = document.getElementById('notification');
    notification.textContent = message;
    notification.className = `notification ${type} show`;

    setTimeout(() => {
        notification.classList.remove('show');
    }, 5000);
}

function showLoading(message = 'Processing...') {
    document.getElementById('loadingText').textContent = message;
    document.getElementById('loadingOverlay').style.display = 'flex';
}

function hideLoading() {
    document.getElementById('loadingOverlay').style.display = 'none';
}

function getSelectedServers() {
    const checkboxes = document.querySelectorAll('input[name="servers[]"]:checked');
    return Array.from(checkboxes).map(cb => cb.value);
}

function formatUptime(start, now) {
    if (!start || !now || start === 0 || now === 0) return '0';

    const diff = now - start;
    if (diff <= 0) return '0';

    const hours = Math.floor(diff / 3600);
    const minutes = Math.floor((diff % 3600) / 60);
    const seconds = diff % 60;

    if (hours > 0) {
        return `${hours}h ${minutes}m`;
    } else if (minutes > 0) {
        return `${minutes}m ${seconds}s`;
    } else {
        return `${seconds}s`;
    }
}

function getStatusIcon(statename) {
    switch (statename.toLowerCase()) {
        case 'running': return '● ';
        case 'stopped': return '⏸ ';
        case 'exited': return '✗ ';
        case 'fatal': return '⚠ ';
        default: return '○ ';
    }
}

function updateProcessRow(serverName, processData) {
    const processName = processData.group + ':' + processData.name;
    const row = document.querySelector(`[data-server="${serverName}"] tr[data-process="${processName}"]`);

    if (!row) {
        console.warn(`Row not found for process: ${processName} on server: ${serverName}`);
        return;
    }

    console.log(`Updating row for ${processName}:`, processData);

    // Update row class for status styling
    row.className = processData.statename.toLowerCase();
    row.setAttribute('data-process', processName);

    // Update PID
    const pidCell = row.querySelector('.pid-cell');
    if (pidCell) pidCell.textContent = processData.pid || '0';

    // Update uptime
    const uptimeCell = row.querySelector('.uptime-cell');
    if (uptimeCell) {
        const stoppedStates = ['stopped', 'exited', 'fatal'];
        if (stoppedStates.includes(processData.statename.toLowerCase())) {
            uptimeCell.textContent = '0';
        } else {
            const uptime = formatUptime(processData.start, processData.now);
            uptimeCell.textContent = uptime;
        }
    }

    // Update status badge
    const statusCell = row.querySelector('.status-cell .status-badge');
    if (statusCell) {
        statusCell.className = `status-badge status-${processData.statename.toLowerCase()}`;
        statusCell.textContent = getStatusIcon(processData.statename) + processData.statename;
    }

    // Update action buttons - Fixed escaping
    const actionsCell = row.querySelector('.actions-cell .process-actions');
    if (actionsCell) {
        let buttonsHtml = '';
        const escapedServer = serverName.replace(/'/g, "\\'");
        const escapedProcess = processName.replace(/'/g, "\\'");

        if (processData.statename === 'RUNNING') {
            buttonsHtml = `<button class="btn btn-stop" onclick="performSingleAction('${escapedServer}', 'stop', '${escapedProcess}')">■ STOP</button>`;
        } else {
            buttonsHtml = `<button class="btn btn-start" onclick="performSingleAction('${escapedServer}', 'start', '${escapedProcess}')">▶ START</button>`;
        }
        buttonsHtml += `<button class="btn btn-restart" onclick="performSingleAction('${escapedServer}', 'restart', '${escapedProcess}')">↻ RESTART</button>`;
        actionsCell.innerHTML = buttonsHtml;
    }
}

// Log Modal Functions
async function showLogs(server, processName) {
    currentLogServer = server;
    currentLogProcess = processName;

    const modal = document.getElementById('logModal');
    const title = document.getElementById('logModalTitle');

    title.textContent = `📋 Logs: ${processName} (${server})`;
    modal.style.display = 'flex';

    // Load logs
    await loadLogs();
}

async function loadLogs() {
    if (!currentLogServer || !currentLogProcess) return;

    showLoading('Loading logs...');

    try {
        const url = `inc/log.php?server=${encodeURIComponent(currentLogServer)}&name=${encodeURIComponent(currentLogProcess)}&ajax=1`;
        const response = await fetch(url);
        const data = await response.json();

        if (data.success) {
            document.getElementById('stdoutContent').textContent = data.stdout || 'No STDOUT output';
            document.getElementById('stderrContent').textContent = data.stderr || 'No STDERR output';
            document.getElementById('logTimestamp').textContent = `Last updated: ${data.timestamp}`;
        } else {
            document.getElementById('stdoutContent').textContent = `Error: ${data.error}`;
            document.getElementById('stderrContent').textContent = '';
        }
    } catch (error) {
        document.getElementById('stdoutContent').textContent = `Network error: ${error.message}`;
        document.getElementById('stderrContent').textContent = '';
    } finally {
        hideLoading();
    }
}

function refreshLogs() {
    loadLogs();
}

function closeLogModal() {
    const modal = document.getElementById('logModal');
    modal.style.display = 'none';
    currentLogServer = '';
    currentLogProcess = '';
}

// Close modal when clicking outside
window.onclick = function(event) {
    const modal = document.getElementById('logModal');
    if (event.target === modal) {
        closeLogModal();
    }
}

// ESC key to close modal
document.addEventListener('keydown', function(event) {
    if (event.key === 'Escape') {
        closeLogModal();
    }
});

async function refreshProcessData() {
    try {
        const selectedServers = getSelectedServers();
        const params = new URLSearchParams();
        selectedServers.forEach(server => params.append('servers[]', server));

        console.log('Refreshing process data for servers:', selectedServers);

        const response = await fetch(`inc/refresh_data.php?${params.toString()}`);

        if (!response.ok) {
            throw new Error(`HTTP ${response.status}: ${response.statusText}`);
        }

        const data = await response.json();
        console.log('Refresh data received:', data);

        if (data.success) {
            for (const [serverName, serverData] of Object.entries(data.servers)) {
                if (serverData.success && serverData.processes) {
                    console.log(`Updating ${serverData.processes.length} processes for server ${serverName}`);
                    serverData.processes.forEach(process => {
                        updateProcessRow(serverName, process);
                    });
                } else {
                    console.error(`Error for server ${serverName}:`, serverData.error);
                }
            }

            // Update timestamp
            document.getElementById('lastUpdated').textContent = `Last updated: ${data.timestamp}`;
        } else {
            console.error('Refresh failed:', data);
        }
    } catch (error) {
        console.error('Error refreshing data:', error);
        showNotification(`✗ Refresh error: ${error.message}`, 'error');
    }
}

async function performSingleAction(server, action, processName) {
    const actionNames = {
        'start': 'Starting',
        'stop': 'Stopping',
        'restart': 'Restarting'
    };

    showLoading(`${actionNames[action]} ${processName}...`);

    try {
        const response = await fetch('inc/action.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded'
            },
            body: `server=${encodeURIComponent(server)}&action=${encodeURIComponent(action)}&name=${encodeURIComponent(processName)}`
        });

        const responseText = await response.text();
        console.log('Action response text:', responseText);

        if (!responseText.trim()) {
            throw new Error('Empty response from server');
        }

        let result;
        try {
            result = JSON.parse(responseText);
        } catch (parseError) {
            console.error('JSON Parse Error:', parseError);
            console.error('Response text:', responseText);
            throw new Error('Invalid JSON response from server');
        }

        if (result.success) {
            showNotification(`✓ ${result.message}`, 'success');

            setTimeout(() => {
                refreshProcessData();
            }, 1000);
        } else {
            showNotification(`✗ Error: ${result.error}`, 'error');
        }
    } catch (error) {
        console.error('Action error:', error);
        showNotification(`✗ Network error: ${error.message}`, 'error');
    } finally {
        hideLoading();
    }
}


async function performBulkAction(server, action) {
    const actionNames = {
        'start_all': 'Starting all processes',
        'stop_all': 'Stopping all processes',
        'restart_all': 'Restarting all processes',
        'restart_stopped': 'Starting stopped processes'
    };

    showLoading(actionNames[action] + '...');

    try {
        const response = await fetch('inc/bulk_action.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify({
                server: server,
                action: action
            })
        });

        const result = await response.json();

        if (result.success) {
            let message = `✓ Action completed on ${server}`;
            if (result.results.length > 0) {
                message += ` (${result.results.length} processes)`;
            }
            if (result.errors.length > 0) {
                message += ` - ${result.errors.length} errors`;
            }
            showNotification(message, 'success');

            // Refresh nur die Daten ohne Page Reload
            setTimeout(() => {
                refreshProcessData();
            }, 2000);
        } else {
            showNotification(`✗ Error: ${result.error}`, 'error');
        }
    } catch (error) {
        showNotification(`✗ Network error: ${error.message}`, 'error');
    } finally {
        hideLoading();
    }
}

function updateLastUpdated() {
    const now = new Date();
    const timeString = now.toLocaleTimeString('de-DE');
    document.getElementById('lastUpdated').textContent = `Last updated: ${timeString}`;
}

function refreshPage() {
    // Aktuelle URL Parameter beibehalten
    const urlParams = new URLSearchParams(window.location.search);
    const newUrl = window.location.pathname + '?' + urlParams.toString();

    // Page refresh
    window.location.href = newUrl;
}

function startCountdown() {
    const interval = parseInt(document.getElementById('refreshInterval').value);
    remainingTime = interval;

    countdownInterval = setInterval(() => {
        remainingTime--;
        document.getElementById('countdown').textContent = `${remainingTime}s`;

        if (remainingTime <= 0) {
            refreshProcessData(); // Verwende AJAX statt Page Reload
            remainingTime = interval; // Reset countdown
        }
    }, 1000);
}

function stopCountdown() {
    if (countdownInterval) {
        clearInterval(countdownInterval);
        countdownInterval = null;
    }
    document.getElementById('countdown').textContent = '';
}

function toggleAutoRefresh() {
    const toggleButton = document.getElementById('toggleRefresh');
    const indicator = document.getElementById('refreshIndicator');
    const statusText = document.getElementById('refreshStatusText');
    const intervalInput = document.getElementById('refreshInterval');

    if (refreshInterval) {
        // Disable auto-refresh
        clearInterval(refreshInterval);
        refreshInterval = null;
        stopCountdown();

        toggleButton.textContent = 'ENABLE';
        indicator.classList.remove('active');
        statusText.textContent = 'DISABLED';
        intervalInput.disabled = false;

        localStorage.setItem('autoRefreshEnabled', 'false');
    } else {
        // Enable auto-refresh
        const interval = parseInt(intervalInput.value) * 1000;

        refreshInterval = setInterval(refreshProcessData, interval); // Verwende AJAX statt Page Reload
        startCountdown();

        toggleButton.textContent = 'DISABLE';
        indicator.classList.add('active');
        statusText.textContent = 'ACTIVE';
        intervalInput.disabled = true;

        localStorage.setItem('autoRefreshEnabled', 'true');
        localStorage.setItem('refreshIntervalValue', intervalInput.value);
    }
}

function initializeAutoRefresh() {
    const enabled = localStorage.getItem('autoRefreshEnabled') === 'true';
    const savedInterval = localStorage.getItem('refreshIntervalValue');

    if (savedInterval) {
        document.getElementById('refreshInterval').value = savedInterval;
    }

    if (enabled) {
        toggleAutoRefresh();
    }
}

// Event Listeners
document.addEventListener('DOMContentLoaded', function() {
    // Dark Mode initialisieren
    const darkMode = localStorage.getItem('darkMode');
    const toggleButton = document.querySelector('.dark-mode-toggle');

    if (darkMode === 'enabled') {
        document.body.classList.add('dark-mode');
        toggleButton.textContent = '☀';
    } else {
        toggleButton.textContent = '🌙';
    }

    // Auto-Refresh initialisieren
    initializeAutoRefresh();

    // Auto-Refresh Toggle Button
    document.getElementById('toggleRefresh').addEventListener('click', toggleAutoRefresh);

    // Interval Input Change
    document.getElementById('refreshInterval').addEventListener('change', function() {
        if (refreshInterval) {
            // Wenn auto-refresh aktiv ist, neu starten mit neuem Interval
            toggleAutoRefresh(); // Disable
            toggleAutoRefresh(); // Enable with new interval
        }
    });
});

// Page Visibility API - Pause when tab is not visible
document.addEventListener('visibilitychange', function() {
    if (document.hidden) {
        if (refreshInterval) {
            clearInterval(refreshInterval);
            stopCountdown();
        }
    } else {
        if (localStorage.getItem('autoRefreshEnabled') === 'true') {
            const interval = parseInt(document.getElementById('refreshInterval').value) * 1000;
            refreshInterval = setInterval(refreshProcessData, interval);
            startCountdown();
        }
    }
});

async function updateServerSelection() {
    const selectedServers = getSelectedServers();

    // Prüfe ob sich die Auswahl geändert hat
    if (selectedServers.length === 0) {
        document.getElementById('serverContainer').innerHTML = '<div class="no-servers">Keine Server ausgewählt</div>';
        return;
    }

    showLoading('Lade Server-Daten...');

    try {
        // Aktualisiere die URL ohne Page Reload
        const params = new URLSearchParams();
        selectedServers.forEach(server => params.append('servers[]', server));
        const newUrl = window.location.pathname + '?' + params.toString();
        window.history.replaceState({}, '', newUrl);

        // Lade neue Server-Daten
        const response = await fetch(`inc/refresh_data.php?${params.toString()}`);

        if (!response.ok) {
            throw new Error(`HTTP ${response.status}: ${response.statusText}`);
        }

        const data = await response.json();

        if (data.success) {
            // Komplett neuen Server-Container erstellen
            await rebuildServerContainer(data.servers);

            // Update timestamp
            document.getElementById('lastUpdated').textContent = `Last updated: ${data.timestamp}`;

            showNotification(`✓ Server-Auswahl aktualisiert (${selectedServers.length} Server)`, 'success');
        } else {
            throw new Error('Fehler beim Laden der Server-Daten');
        }
    } catch (error) {
        console.error('Error updating server selection:', error);
        showNotification(`✗ Fehler: ${error.message}`, 'error');
    } finally {
        hideLoading();
    }
}


// Neue Funktion zum Neuaufbau des Server-Containers
async function rebuildServerContainer(serversData) {
    const container = document.getElementById('serverContainer');
    container.innerHTML = '';

    for (const [serverName, serverData] of Object.entries(serversData)) {
        const serverSection = document.createElement('div');
        serverSection.className = 'server-section';
        serverSection.setAttribute('data-server', serverName);

        if (serverData.success && serverData.processes) {
            const displayName = serverData.server_info?.display_name || serverName;
            const showSubtitle = displayName !== serverName;

            // Server Header
            const serverHeader = document.createElement('div');
            serverHeader.className = 'server-header';
            serverHeader.innerHTML = `
                <div class="server-title">
                    <h2>▣ ${escapeHtml(displayName)}</h2>
                </div>
                <div class="server-actions">
                    <button type="button" class="btn btn-start" onclick="performBulkAction('${escapeHtml(serverName)}', 'start_all')">▶ START ALL</button>
                    <button type="button" class="btn btn-stop" onclick="performBulkAction('${escapeHtml(serverName)}', 'stop_all')">■ STOP ALL</button>
                    <button type="button" class="btn btn-restart" onclick="performBulkAction('${escapeHtml(serverName)}', 'restart_all')">↻ RESTART ALL</button>
                    <button type="button" class="btn btn-restart-running" onclick="performBulkAction('${escapeHtml(serverName)}', 'restart_running')">↻ RESTART RUNNING</button>
                </div>
            `;

            // Process Table
            const processTable = document.createElement('table');
            processTable.className = 'process-table';

            // Table Header
            processTable.innerHTML = `
                <tr>
                    <th>NAME</th>
                    <th>GROUP</th>
                    <th>PID</th>
                    <th>UPTIME</th>
                    <th>STATUS</th>
                    <th>ACTIONS</th>
                    <th>LOGS</th>
                </tr>
            `;

            // Process Rows
            serverData.processes.forEach(process => {
                const row = document.createElement('tr');
                row.className = process.statename.toLowerCase();
                row.setAttribute('data-process', process.group + ':' + process.name);

                const uptime = ['stopped', 'exited', 'fatal'].includes(process.statename.toLowerCase())
                    ? '0'
                    : formatUptime(process.start, process.now);

                const statusIcon = getStatusIcon(process.statename);
                const escapedServer = serverName.replace(/'/g, "\\'");
                const escapedProcess = (process.group + ':' + process.name).replace(/'/g, "\\'");

                let actionButtons = '';
                if (process.statename === 'RUNNING') {
                    actionButtons = `<button class="btn btn-stop" onclick="performSingleAction('${escapedServer}', 'stop', '${escapedProcess}')">■ STOP</button>`;
                } else {
                    actionButtons = `<button class="btn btn-start" onclick="performSingleAction('${escapedServer}', 'start', '${escapedProcess}')">▶ START</button>`;
                }
                actionButtons += `<button class="btn btn-restart" onclick="performSingleAction('${escapedServer}', 'restart', '${escapedProcess}')">↻ RESTART</button>`;

                row.innerHTML = `
                    <td><strong>${escapeHtml(process.name)}</strong></td>
                    <td>${escapeHtml(process.group)}</td>
                    <td class="pid-cell">${escapeHtml(process.pid || '0')}</td>
                    <td class="uptime-cell">${uptime}</td>
                    <td class="status-cell">
                        <span class="status-badge status-${process.statename.toLowerCase()}">
                            ${statusIcon}${process.statename}
                        </span>
                    </td>
                    <td class="actions-cell">
                        <div class="process-actions">
                            ${actionButtons}
                        </div>
                    </td>
                    <td>
                        <button class="btn" onclick="showLogs('${escapedServer}', '${escapedProcess}')">≡ LOGS</button>
                    </td>
                `;

                processTable.appendChild(row);
            });

            serverSection.appendChild(serverHeader);
            serverSection.appendChild(processTable);
        } else {
            // Error case
            const displayName = serverData.server_info?.display_name || serverName;
            const showSubtitle = displayName !== serverName;

            const serverHeader = document.createElement('div');
            serverHeader.className = 'server-header';
            serverHeader.innerHTML = `
                <div class="server-title">
                    <h2>▣ ${escapeHtml(displayName)}</h2>
                </div>
            `;

            const errorDiv = document.createElement('div');
            errorDiv.className = 'error-message';
            errorDiv.textContent = `✗ ERROR: ${serverData.error}`;

            serverSection.appendChild(serverHeader);
            serverSection.appendChild(errorDiv);
        }

        container.appendChild(serverSection);
    }
}

// Hilfsfunktion für HTML-Escaping
function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

// Aktualisierte Log-Modal-Funktion
async function showLogs(server, processName) {
    currentLogServer = server;
    currentLogProcess = processName;

    const modal = document.getElementById('logModal');
    const title = document.getElementById('logModalTitle');

    // Versuche display_name zu finden
    const serverSection = document.querySelector(`[data-server="${server}"]`);
    const serverTitle = serverSection?.querySelector('.server-title h2')?.textContent || server;
    const displayName = serverTitle.replace('▣ ', '');

    title.textContent = `📋 Logs: ${processName} (${displayName})`;
    modal.style.display = 'flex';

    // Load logs
    await loadLogs();
}

// Aktualisierte Notification-Funktion für Bulk-Actions
async function performBulkAction(server, action) {
    const actionNames = {
        'start_all': 'Starting all processes',
        'stop_all': 'Stopping all processes',
        'restart_all': 'Restarting all processes',
        'restart_running': 'Restarting running processes'
    };

    // Finde display_name für bessere Nachrichten
    const serverSection = document.querySelector(`[data-server="${server}"]`);
    const serverTitle = serverSection?.querySelector('.server-title h2')?.textContent || server;
    const displayName = serverTitle.replace('▣ ', '');

    showLoading(actionNames[action] + '...');

    try {
        const response = await fetch('inc/bulk_action.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify({
                server: server,
                action: action
            })
        });

        const result = await response.json();

        if (result.success) {
            let message = `✓ Action completed on ${displayName}`;
            if (result.results.length > 0) {
                message += ` (${result.results.length} processes)`;
            }
            if (result.errors.length > 0) {
                message += ` - ${result.errors.length} errors`;
            }
            showNotification(message, 'success');

            // Refresh nur die Daten ohne Page Reload
            setTimeout(() => {
                refreshProcessData();
            }, 2000);
        } else {
            showNotification(`✗ Error on ${displayName}: ${result.error}`, 'error');
        }
    } catch (error) {
        showNotification(`✗ Network error (${displayName}): ${error.message}`, 'error');
    } finally {
        hideLoading();
    }
}

// Aktualisierte Single-Action-Funktion
async function performSingleAction(server, action, processName) {
    const actionNames = {
        'start': 'Starting',
        'stop': 'Stopping',
        'restart': 'Restarting'
    };

    // Finde display_name für bessere Nachrichten
    const serverSection = document.querySelector(`[data-server="${server}"]`);
    const serverTitle = serverSection?.querySelector('.server-title h2')?.textContent || server;
    const displayName = serverTitle.replace('▣ ', '');

    showLoading(`${actionNames[action]} ${processName} on ${displayName}...`);

    try {
        const response = await fetch('inc/action.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded'
            },
            body: `server=${encodeURIComponent(server)}&action=${encodeURIComponent(action)}&name=${encodeURIComponent(processName)}`
        });

        const responseText = await response.text();

        if (!responseText.trim()) {
            throw new Error('Empty response from server');
        }

        let result;
        try {
            result = JSON.parse(responseText);
        } catch (parseError) {
            throw new Error('Invalid JSON response from server');
        }

        if (result.success) {
            showNotification(`✓ ${result.message} on ${displayName}`, 'success');

            setTimeout(() => {
                refreshProcessData();
            }, 1000);
        } else {
            showNotification(`✗ Error on ${displayName}: ${result.error}`, 'error');
        }
    } catch (error) {
        showNotification(`✗ Network error (${displayName}): ${error.message}`, 'error');
    } finally {
        hideLoading();
    }
}

// Event Listeners erweitern
document.addEventListener('DOMContentLoaded', function() {
    // Dark Mode initialisieren
    const darkMode = localStorage.getItem('darkMode');
    const toggleButton = document.querySelector('.dark-mode-toggle');

    if (darkMode === 'enabled') {
        document.body.classList.add('dark-mode');
        toggleButton.textContent = '☀';
    } else {
        toggleButton.textContent = '🌙';
    }

    // Auto-Refresh initialisieren
    initializeAutoRefresh();

    // Auto-Refresh Toggle Button
    document.getElementById('toggleRefresh').addEventListener('click', toggleAutoRefresh);

    // Interval Input Change
    document.getElementById('refreshInterval').addEventListener('change', function() {
        if (refreshInterval) {
            // Wenn auto-refresh aktiv ist, neu starten mit neuem Interval
            toggleAutoRefresh(); // Disable
            toggleAutoRefresh(); // Enable with new interval
        }
    });

    // Event Listener für Server-Checkboxen hinzufügen
    const serverCheckboxes = document.querySelectorAll('input[name="servers[]"]');
    serverCheckboxes.forEach(checkbox => {
        checkbox.addEventListener('change', function() {
            // Kleine Verzögerung, um mehrere schnelle Änderungen zu sammeln
            clearTimeout(window.serverSelectionTimeout);
            window.serverSelectionTimeout = setTimeout(() => {
                updateServerSelection();
            }, 300);
        });
    });

    // Prevent form submission für das Server-Form
    document.getElementById('serverForm').addEventListener('submit', function(e) {
        e.preventDefault();
        updateServerSelection();
    });
});

</script>

</body>
</html>