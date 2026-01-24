<?php
/**
 * OStim Scene Log Viewer
 * Displays the ostim_scenes.log file in real-time
 * Can be embedded in control_panel or accessed directly
 */

$enginePath = __DIR__ . "/../../";
$logFile = $enginePath . "log/ostim_scenes.log";

// Handle AJAX requests for log content
if (isset($_GET['action']) && $_GET['action'] === 'getlog') {
    header('Content-Type: application/json');

    if (file_exists($logFile)) {
        $lines = file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        // Return last 100 lines, newest first
        $lines = array_reverse(array_slice($lines, -100));
        echo json_encode(['success' => true, 'lines' => $lines, 'count' => count($lines)]);
    } else {
        echo json_encode(['success' => true, 'lines' => [], 'count' => 0, 'message' => 'No scenes logged yet']);
    }
    exit;
}

// Handle clear log request
if (isset($_GET['action']) && $_GET['action'] === 'clear') {
    header('Content-Type: application/json');
    if (file_exists($logFile)) {
        file_put_contents($logFile, '');
    }
    echo json_encode(['success' => true, 'message' => 'Log cleared']);
    exit;
}

$embed = isset($_GET['embed']) && $_GET['embed'] == '1';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>OStim Scene Log</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Consolas', 'Monaco', monospace;
            background: <?php echo $embed ? 'transparent' : '#1a1a1a'; ?>;
            color: #e0e0e0;
            padding: 10px;
            height: 100vh;
            display: flex;
            flex-direction: column;
        }
        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 10px 15px;
            background: #2a2a2a;
            border-radius: 8px 8px 0 0;
            border-bottom: 1px solid #3a3a3a;
        }
        .header h2 {
            color: #ff9800;
            font-size: 16px;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .header-actions {
            display: flex;
            gap: 10px;
            align-items: center;
        }
        .btn {
            background: #3a3a3a;
            border: 1px solid #4a4a4a;
            color: #e0e0e0;
            padding: 6px 12px;
            border-radius: 4px;
            cursor: pointer;
            font-size: 12px;
            transition: all 0.2s;
        }
        .btn:hover { background: #4a4a4a; }
        .btn-danger { background: #c62828; border-color: #d32f2f; }
        .btn-danger:hover { background: #d32f2f; }
        .auto-refresh {
            display: flex;
            align-items: center;
            gap: 5px;
            font-size: 12px;
            color: #888;
        }
        .auto-refresh input { cursor: pointer; }
        .log-container {
            flex: 1;
            background: #1e1e1e;
            border: 1px solid #3a3a3a;
            border-top: none;
            border-radius: 0 0 8px 8px;
            overflow-y: auto;
            padding: 10px;
        }
        .log-entry {
            padding: 6px 10px;
            border-bottom: 1px solid #2a2a2a;
            font-size: 12px;
            line-height: 1.5;
        }
        .log-entry:hover { background: #252525; }
        .log-entry:last-child { border-bottom: none; }
        .log-timestamp {
            color: #666;
            margin-right: 10px;
        }
        .log-source {
            display: inline-block;
            padding: 2px 6px;
            border-radius: 3px;
            font-size: 10px;
            margin-right: 8px;
            font-weight: bold;
        }
        .log-source.JSONB { background: #2e7d32; color: #c8e6c9; }
        .log-source.JSON { background: #1565c0; color: #bbdefb; }
        .log-source.FALLBACK { background: #f57c00; color: #fff3e0; }
        .scene-id {
            color: #64b5f6;
            font-weight: bold;
            cursor: pointer;
        }
        .scene-id:hover { text-decoration: underline; }
        .scene-desc { color: #a5d6a7; }
        .empty-state {
            text-align: center;
            padding: 40px;
            color: #666;
        }
        .empty-state p { margin: 10px 0; }
        .status-bar {
            padding: 8px 15px;
            background: #252525;
            font-size: 11px;
            color: #888;
            border-top: 1px solid #3a3a3a;
        }
    </style>
</head>
<body>
    <div class="header">
        <h2>OStim Scene Log</h2>
        <div class="header-actions">
            <label class="auto-refresh">
                <input type="checkbox" id="autoRefresh" checked>
                Auto-refresh (5s)
            </label>
            <button class="btn" onclick="loadLog()">Refresh</button>
            <button class="btn btn-danger" onclick="clearLog()">Clear Log</button>
        </div>
    </div>
    <div class="log-container" id="logContainer">
        <div class="empty-state">
            <p>Loading...</p>
        </div>
    </div>
    <div class="status-bar" id="statusBar">
        Entries: 0 | Last update: --
    </div>

    <script>
        let refreshInterval = null;

        function loadLog() {
            fetch('?action=getlog')
                .then(r => r.json())
                .then(data => {
                    const container = document.getElementById('logContainer');
                    const statusBar = document.getElementById('statusBar');

                    if (data.lines && data.lines.length > 0) {
                        container.innerHTML = data.lines.map(line => {
                            // Parse: [2024-01-15 12:34:56] [SOURCE] ID: scene_id | DESC: description
                            const match = line.match(/\[([^\]]+)\] \[([^\]]+)\] ID: ([^|]+) \| DESC: (.+)/);
                            if (match) {
                                const [_, timestamp, source, sceneId, desc] = match;
                                return `<div class="log-entry">
                                    <span class="log-timestamp">${timestamp}</span>
                                    <span class="log-source ${source}">${source}</span>
                                    <span class="scene-id" onclick="copySceneId('${sceneId.trim()}')" title="Click to copy">${sceneId.trim()}</span>
                                    <span class="scene-desc">${desc}</span>
                                </div>`;
                            }
                            return `<div class="log-entry">${line}</div>`;
                        }).join('');
                    } else {
                        container.innerHTML = `<div class="empty-state">
                            <p>No scenes logged yet.</p>
                            <p style="font-size:11px;">Scene IDs will appear here when OStim scenes are triggered in-game.</p>
                        </div>`;
                    }

                    const now = new Date().toLocaleTimeString();
                    statusBar.textContent = `Entries: ${data.count} | Last update: ${now}`;
                })
                .catch(err => {
                    console.error('Error loading log:', err);
                });
        }

        function clearLog() {
            if (!confirm('Clear the OStim scene log?')) return;
            fetch('?action=clear')
                .then(r => r.json())
                .then(() => loadLog());
        }

        function copySceneId(sceneId) {
            navigator.clipboard.writeText(sceneId).then(() => {
                // Brief visual feedback
                const el = event.target;
                const original = el.textContent;
                el.textContent = 'Copied!';
                el.style.color = '#4caf50';
                setTimeout(() => {
                    el.textContent = original;
                    el.style.color = '';
                }, 1000);
            });
        }

        function toggleAutoRefresh() {
            const checkbox = document.getElementById('autoRefresh');
            if (checkbox.checked) {
                refreshInterval = setInterval(loadLog, 5000);
            } else {
                clearInterval(refreshInterval);
            }
        }

        document.getElementById('autoRefresh').addEventListener('change', toggleAutoRefresh);

        // Initial load
        loadLog();
        toggleAutoRefresh();
    </script>
</body>
</html>
