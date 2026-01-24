<?php
/**
 * NSFW Debug Panel
 * Human-readable view of prompts sent, responses received, and OStim scenes
 */

$enginePath = __DIR__ . DIRECTORY_SEPARATOR . ".." . DIRECTORY_SEPARATOR . ".." . DIRECTORY_SEPARATOR;
require_once($enginePath . "conf" . DIRECTORY_SEPARATOR . "conf.php");
require_once($enginePath . "lib" . DIRECTORY_SEPARATOR . "{$GLOBALS["DBDRIVER"]}.class.php");

$db = new sql();
$GLOBALS["db"] = $db;

$embed = isset($_GET['embed']) && $_GET['embed'] == '1';
$nsfwLogFile = $enginePath . "log/nsfw_debug.log";

// Handle AJAX requests
if (isset($_GET['action'])) {
    header('Content-Type: application/json');

    switch ($_GET['action']) {
        case 'getSceneLog':
            $logFile = $enginePath . "log/ostim_scenes.log";
            $entries = [];
            if (file_exists($logFile)) {
                $lines = file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
                $lines = array_reverse(array_slice($lines, -50));
                foreach ($lines as $line) {
                    // Parse: [2024-01-15 12:34:56] [SOURCE] ID: scene_id | DESC: description
                    if (preg_match('/\[([^\]]+)\] \[([^\]]+)\] ID: ([^|]+) \| DESC: (.+)/', $line, $m)) {
                        $entries[] = [
                            'time' => $m[1],
                            'source' => $m[2],
                            'scene_id' => trim($m[3]),
                            'description' => $m[4]
                        ];
                    }
                }
            }
            echo json_encode(['success' => true, 'entries' => $entries]);
            break;

        case 'getPromptLog':
            $entries = [];
            // Read Apache error log and filter for AIAGENT-NSFW lines only
            $apacheLog = '/var/log/apache2/error.log';
            if (file_exists($apacheLog) && is_readable($apacheLog)) {
                // Get last ~20MB for more history (sex scenes may be hours apart)
                $lines = [];
                $fp = fopen($apacheLog, 'r');
                if ($fp) {
                    $fileSize = filesize($apacheLog);
                    $seekPos = max(0, $fileSize - 20000000); // 20MB window
                    if ($seekPos > 0) {
                        fseek($fp, $seekPos, SEEK_SET);
                        fgets($fp); // Skip partial line
                    }
                    while (!feof($fp)) {
                        $line = fgets($fp);
                        if ($line !== false) {
                            $lines[] = $line;
                        }
                    }
                    fclose($fp);
                }

                // Filter for NSFW plugin lines and reverse (newest first)
                $lines = array_reverse($lines);
                $count = 0;

                // Only show these meaningful patterns (whitelist approach)
                $showPatterns = [
                    'speak style',
                    'Injected',
                    'Parsed orgasm',
                    'Overrid',
                    'tier relationship',
                    'pillow talk',
                    'Built negotiation',
                    'climax prompt',
                    'reacting to partner',
                    'chatnf_sl'
                ];

                foreach ($lines as $line) {
                    if ($count >= 100) break;

                    // Match all tag formats: [AIAGENTNSFW], [AIAGENT_NSFW], [AIAGENT-NSFW], [AIAGENTNSFW ], [AIAGENTNSFW-DEBUG]
                    if (preg_match('/\[AIAGENT[_-]?NSFW/', $line)) {
                        // Skip DEBUG preprocessor noise
                        if (stripos($line, 'PREPROCESSING:') !== false) continue;
                        if (stripos($line, 'All functions available') !== false) continue;
                        if (stripos($line, 'Updating intimacy') !== false) continue;
                        if (stripos($line, 'updateIntimacyForActor') !== false) continue;

                        // Parse: [Mon Jan 12 07:28:34.365808 2026] [php:notice] ... [AIAGENTNSFW] message
                        // More permissive: capture everything after the tag
                        if (preg_match('/\[AIAGENT[_-]?NSFW[^\]]*\]\s*(.+)$/i', $line, $m)) {
                            // Extract timestamp from beginning
                            preg_match('/^\[([^\]]+)\]/', $line, $ts);
                            $timestamp = $ts[1] ?? '';
                            $message = trim($m[1]);

                            // Only show meaningful entries (whitelist)
                            $show = false;
                            foreach ($showPatterns as $pattern) {
                                if (stripos($message, $pattern) !== false) {
                                    $show = true;
                                    break;
                                }
                            }
                            if (!$show) continue;

                            // Categorize by message content
                            $type = 'INFO';
                            if (stripos($message, 'orgasm') !== false || stripos($message, 'climax') !== false) {
                                $type = 'ORGASM';
                            } elseif (stripos($message, 'Injected') !== false) {
                                $type = 'INJECT';
                            } elseif (stripos($message, 'Overrid') !== false || stripos($message, 'chatnf_sl') !== false) {
                                $type = 'CUE';
                            } elseif (stripos($message, 'tier') !== false) {
                                $type = 'TIER';
                            } elseif (stripos($message, 'pillow') !== false) {
                                $type = 'PILLOW';
                            } elseif (stripos($message, 'speak style') !== false) {
                                $type = 'STYLE';
                            }

                            // Extract NPC name if present
                            $npc = '';
                            if (preg_match('/for\s+([A-Z][a-z]+(?:\s+[A-Z][a-z]+)?)/i', $message, $npcMatch)) {
                                $npc = $npcMatch[1];
                            }

                            // Parse time from Apache timestamp
                            $time = '';
                            if (preg_match('/(\d{2}:\d{2}:\d{2})/', $timestamp, $timeMatch)) {
                                $time = $timeMatch[1];
                            }

                            $entries[] = [
                                'time' => $time,
                                'type' => $type,
                                'npc' => $npc,
                                'message' => $message
                            ];
                            $count++;
                        }
                    }
                }
            }
            echo json_encode(['success' => true, 'entries' => $entries]);
            break;

        case 'getResponses':
            $entries = [];
            try {
                // Get recent chat responses from eventlog
                $sql = "SELECT data, to_timestamp(localts) as ts
                        FROM eventlog
                        WHERE type = 'chat'
                        ORDER BY rowid DESC
                        LIMIT 50";
                $results = $GLOBALS['db']->fetchAll($sql);
                foreach ($results ?: [] as $r) {
                    // Parse "NPC: message (talking to X)" format
                    if (preg_match('/^([^:]+):\s*(.+)$/s', $r['data'], $m)) {
                        $entries[] = [
                            'time' => date('H:i:s', strtotime($r['ts'])),
                            'speaker' => trim($m[1]),
                            'message' => trim($m[2])
                        ];
                    }
                }
            } catch (Exception $e) {
                error_log("[NSFW_DEBUG] getResponses error: " . $e->getMessage());
            }
            echo json_encode(['success' => true, 'entries' => $entries]);
            break;

        case 'getStatus':
            $statuses = [];
            $statusFiles = glob(sys_get_temp_dir() . "/nsfw_intimacy_*.json");
            foreach ($statusFiles as $file) {
                $content = @file_get_contents($file);
                if ($content && $data = json_decode($content, true)) {
                    // Extract NPC name from filename
                    $npcName = str_replace(['nsfw_intimacy_', '.json'], '', basename($file));
                    $npcName = str_replace('_', ' ', $npcName);

                    $statuses[] = [
                        'npc' => ucwords($npcName),
                        'phase' => $data['scene_phase'] ?? 'none',
                        'level' => $data['intimacy_level'] ?? 0,
                        'actors' => implode(', ', $data['scene_actors'] ?? []),
                        'updated' => date('H:i:s', filemtime($file))
                    ];
                }
            }
            echo json_encode(['success' => true, 'statuses' => $statuses]);
            break;

        case 'clearSceneLog':
            $logFile = $enginePath . "log/ostim_scenes.log";
            @file_put_contents($logFile, '');
            echo json_encode(['success' => true]);
            break;

        case 'clearPromptLog':
            @file_put_contents($nsfwLogFile, '');
            echo json_encode(['success' => true]);
            break;
    }
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>NSFW Debug</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Segoe UI', Tahoma, sans-serif;
            background: <?php echo $embed ? 'transparent' : '#1a1a1a'; ?>;
            color: #e0e0e0;
            height: 100vh;
            display: flex;
            flex-direction: column;
        }

        .tabs {
            display: flex;
            background: #252525;
            border-bottom: 2px solid #ff9800;
        }
        .tab {
            padding: 12px 24px;
            cursor: pointer;
            background: transparent;
            border: none;
            color: #888;
            font-size: 14px;
            font-weight: 500;
            transition: all 0.2s;
        }
        .tab:hover { color: #ccc; background: #2a2a2a; }
        .tab.active { color: #ff9800; background: #2a2a2a; }
        .tab .count {
            background: #444;
            padding: 2px 8px;
            border-radius: 10px;
            font-size: 11px;
            margin-left: 8px;
        }
        .tab.active .count { background: #ff9800; color: #000; }

        .panel { display: none; flex: 1; flex-direction: column; overflow: hidden; }
        .panel.active { display: flex; }

        .toolbar {
            display: flex;
            justify-content: space-between;
            padding: 10px 15px;
            background: #222;
            border-bottom: 1px solid #333;
        }
        .toolbar-info { color: #666; font-size: 12px; }
        .toolbar-actions { display: flex; gap: 10px; align-items: center; }
        .btn {
            background: #3a3a3a;
            border: 1px solid #4a4a4a;
            color: #ccc;
            padding: 6px 14px;
            border-radius: 4px;
            cursor: pointer;
            font-size: 12px;
        }
        .btn:hover { background: #4a4a4a; }
        .btn-clear { background: #8b0000; }
        .btn-clear:hover { background: #a00; }
        .auto-refresh { color: #666; font-size: 12px; display: flex; align-items: center; gap: 5px; }

        .log-area {
            flex: 1;
            overflow-y: auto;
            padding: 10px;
            background: #1a1a1a;
        }

        /* Scene entries */
        .scene-entry {
            padding: 10px 15px;
            border-bottom: 1px solid #2a2a2a;
            display: grid;
            grid-template-columns: 80px 70px 200px 1fr;
            gap: 15px;
            align-items: center;
        }
        .scene-entry:hover { background: #222; }
        .scene-time { color: #666; font-size: 11px; }
        .scene-source {
            padding: 3px 8px;
            border-radius: 4px;
            font-size: 11px;
            font-weight: bold;
            text-align: center;
        }
        .scene-source.JSONB { background: #1b5e20; color: #a5d6a7; }
        .scene-source.JSON { background: #0d47a1; color: #90caf9; }
        .scene-source.FALLBACK { background: #e65100; color: #ffe0b2; }
        .scene-id {
            color: #64b5f6;
            font-family: monospace;
            font-size: 12px;
            cursor: pointer;
        }
        .scene-id:hover { text-decoration: underline; color: #90caf9; }
        .scene-desc { color: #81c784; font-size: 13px; }

        /* Prompt entries */
        .prompt-entry {
            padding: 10px 15px;
            border-bottom: 1px solid #2a2a2a;
            display: grid;
            grid-template-columns: 70px 80px 120px 1fr;
            gap: 15px;
            align-items: start;
        }
        .prompt-entry:hover { background: #222; }
        .prompt-time { color: #666; font-size: 11px; }
        .prompt-type {
            padding: 3px 8px;
            border-radius: 4px;
            font-size: 11px;
            font-weight: bold;
            text-align: center;
        }
        .prompt-type.INJECT { background: #4a148c; color: #ce93d8; }
        .prompt-type.TIER { background: #1565c0; color: #90caf9; }
        .prompt-type.SCENE { background: #2e7d32; color: #a5d6a7; }
        .prompt-type.PHASE { background: #f57c00; color: #ffe0b2; }
        .prompt-type.INFO { background: #37474f; color: #b0bec5; }
        .prompt-npc { color: #ffb74d; font-weight: 500; }
        .prompt-msg { color: #b0bec5; font-size: 13px; line-height: 1.5; }

        /* Response entries */
        .response-entry {
            padding: 12px 15px;
            border-bottom: 1px solid #2a2a2a;
        }
        .response-entry:hover { background: #222; }
        .response-header {
            display: flex;
            justify-content: space-between;
            margin-bottom: 6px;
        }
        .response-speaker { color: #ffb74d; font-weight: bold; font-size: 14px; }
        .response-time { color: #666; font-size: 11px; }
        .response-msg {
            color: #a5d6a7;
            font-size: 14px;
            font-style: italic;
            line-height: 1.5;
            padding-left: 10px;
            border-left: 3px solid #388e3c;
        }

        /* Status cards */
        .status-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
            gap: 15px;
            padding: 15px;
        }
        .status-card {
            background: #252525;
            border: 1px solid #3a3a3a;
            border-radius: 8px;
            padding: 15px;
        }
        .status-card h4 { color: #ffb74d; margin-bottom: 12px; font-size: 15px; }
        .status-row {
            display: flex;
            justify-content: space-between;
            padding: 6px 0;
            font-size: 13px;
            border-bottom: 1px solid #2a2a2a;
        }
        .status-row:last-child { border-bottom: none; }
        .status-label { color: #888; }
        .status-value { color: #81c784; }
        .status-value.engaged { color: #4caf50; }
        .status-value.tier_prompt { color: #ff9800; }
        .status-value.none { color: #666; }

        .empty { text-align: center; padding: 60px 20px; color: #555; }
        .empty p { margin: 10px 0; }

        .status-bar {
            padding: 6px 15px;
            background: #1a1a1a;
            font-size: 11px;
            color: #555;
            border-top: 1px solid #333;
            display: flex;
            justify-content: space-between;
        }
    </style>
</head>
<body>
    <div class="tabs">
        <button class="tab active" data-panel="scenes">OStim Scenes <span class="count" id="sceneCount">0</span></button>
        <button class="tab" data-panel="prompts">Prompts Sent <span class="count" id="promptCount">0</span></button>
        <button class="tab" data-panel="responses">NPC Responses <span class="count" id="responseCount">0</span></button>
        <button class="tab" data-panel="status">Live Status</button>
    </div>

    <!-- Scenes -->
    <div id="scenes" class="panel active">
        <div class="toolbar">
            <span class="toolbar-info">Click scene ID to edit description</span>
            <div class="toolbar-actions">
                <label class="auto-refresh"><input type="checkbox" id="arScenes" checked> Auto</label>
                <button class="btn" onclick="loadScenes()">Refresh</button>
                <button class="btn btn-clear" onclick="clearScenes()">Clear</button>
            </div>
        </div>
        <div class="log-area" id="sceneArea"></div>
    </div>

    <!-- Prompts -->
    <div id="prompts" class="panel">
        <div class="toolbar">
            <span class="toolbar-info">Prompts injected into NPC context</span>
            <div class="toolbar-actions">
                <label class="auto-refresh"><input type="checkbox" id="arPrompts" checked> Auto</label>
                <button class="btn" onclick="loadPrompts()">Refresh</button>
                <button class="btn btn-clear" onclick="clearPrompts()">Clear</button>
            </div>
        </div>
        <div class="log-area" id="promptArea"></div>
    </div>

    <!-- Responses -->
    <div id="responses" class="panel">
        <div class="toolbar">
            <span class="toolbar-info">What NPCs actually said (last 2 hours)</span>
            <div class="toolbar-actions">
                <label class="auto-refresh"><input type="checkbox" id="arResponses" checked> Auto</label>
                <button class="btn" onclick="loadResponses()">Refresh</button>
            </div>
        </div>
        <div class="log-area" id="responseArea"></div>
    </div>

    <!-- Status -->
    <div id="status" class="panel">
        <div class="toolbar">
            <span class="toolbar-info">Current intimacy state for tracked NPCs</span>
            <div class="toolbar-actions">
                <label class="auto-refresh"><input type="checkbox" id="arStatus" checked> Auto</label>
                <button class="btn" onclick="loadStatus()">Refresh</button>
            </div>
        </div>
        <div class="log-area" id="statusArea"></div>
    </div>

    <div class="status-bar">
        <span id="lastUpdate">--</span>
        <span>NSFW Debug Panel</span>
    </div>

    <!-- Scene Edit Modal -->
    <div id="sceneEditModal" style="display: none; position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.7); z-index: 1000;">
        <div style="position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); background: #1C1A24; padding: 25px; border-radius: 10px; width: 90%; max-width: 550px; max-height: 90vh; overflow-y: auto; box-shadow: 0 10px 40px rgba(0,0,0,0.5); border: 1px solid #ff9800;">
            <h3 style="margin: 0 0 15px 0; color: #ff9800; font-size: 16px;">Edit Scene Description</h3>

            <div style="margin-bottom: 15px;">
                <label style="display: block; color: #888; font-size: 11px; margin-bottom: 4px;">Scene ID</label>
                <input type="text" id="modalSceneId" disabled style="width: 100%; padding: 8px; background: #252233; border: 1px solid #3a3a3a; color: #64b5f6; font-family: monospace; font-size: 12px; border-radius: 4px; box-sizing: border-box;">
            </div>

            <div style="margin-bottom: 15px;">
                <label style="display: block; color: #888; font-size: 11px; margin-bottom: 4px;">Description (English)</label>
                <textarea id="modalSceneDesc" style="width: 100%; padding: 8px; background: #252233; border: 1px solid #3a3a3a; color: #e0e0e0; font-size: 13px; border-radius: 4px; min-height: 80px; resize: vertical; box-sizing: border-box;"></textarea>
            </div>

            <div style="margin-bottom: 15px;">
                <label style="display: block; color: #888; font-size: 11px; margin-bottom: 4px;">Description (Spanish)</label>
                <textarea id="modalSceneDescEs" style="width: 100%; padding: 8px; background: #252233; border: 1px solid #3a3a3a; color: #e0e0e0; font-size: 13px; border-radius: 4px; min-height: 60px; resize: vertical; box-sizing: border-box;"></textarea>
            </div>

            <div style="margin-bottom: 20px;">
                <label style="display: block; color: #888; font-size: 11px; margin-bottom: 4px;">Internal Description (Backup)</label>
                <textarea id="modalSceneIDesc" style="width: 100%; padding: 8px; background: #252233; border: 1px solid #3a3a3a; color: #888; font-size: 12px; border-radius: 4px; min-height: 50px; resize: vertical; box-sizing: border-box;"></textarea>
            </div>

            <div style="display: flex; gap: 10px; justify-content: flex-end;">
                <button onclick="saveSceneEdit()" style="padding: 8px 20px; background: #ff9800; border: none; color: #000; font-weight: bold; border-radius: 4px; cursor: pointer;">Save</button>
                <button onclick="closeSceneModal()" style="padding: 8px 20px; background: #3a3a3a; border: 1px solid #4a4a4a; color: #ccc; border-radius: 4px; cursor: pointer;">Cancel</button>
            </div>
        </div>
    </div>

    <script>
        // Tab switching
        document.querySelectorAll('.tab').forEach(t => {
            t.onclick = () => {
                document.querySelectorAll('.tab').forEach(x => x.classList.remove('active'));
                document.querySelectorAll('.panel').forEach(x => x.classList.remove('active'));
                t.classList.add('active');
                document.getElementById(t.dataset.panel).classList.add('active');
            };
        });

        function esc(s) { return s ? s.replace(/</g,'&lt;').replace(/>/g,'&gt;') : ''; }
        function updateTime() { document.getElementById('lastUpdate').textContent = 'Updated: ' + new Date().toLocaleTimeString(); }

        // Open scene editor modal - fetch existing data from config_manager
        function openSceneEditor(sceneId) {
            document.getElementById('modalSceneId').value = sceneId;
            document.getElementById('modalSceneDesc').value = '';
            document.getElementById('modalSceneDescEs').value = '';
            document.getElementById('modalSceneIDesc').value = '';

            // Fetch existing scene data
            fetch('config_manager.php?action=getScene&stage=' + encodeURIComponent(sceneId))
                .then(r => r.json())
                .then(d => {
                    if (d.success && d.scene) {
                        document.getElementById('modalSceneDesc').value = d.scene.description || '';
                        document.getElementById('modalSceneDescEs').value = d.scene.description_es || '';
                        document.getElementById('modalSceneIDesc').value = d.scene.i_desc || '';
                    }
                    document.getElementById('sceneEditModal').style.display = 'block';
                })
                .catch(() => {
                    // If fetch fails, still show modal with empty fields for new scene
                    document.getElementById('sceneEditModal').style.display = 'block';
                });
        }

        function closeSceneModal() {
            document.getElementById('sceneEditModal').style.display = 'none';
        }

        function saveSceneEdit() {
            const sceneId = document.getElementById('modalSceneId').value;
            const description = document.getElementById('modalSceneDesc').value.trim();
            const descriptionEs = document.getElementById('modalSceneDescEs').value.trim();
            const iDesc = document.getElementById('modalSceneIDesc').value.trim();

            const formData = new FormData();
            formData.append('stage', sceneId);
            formData.append('description', description);
            formData.append('description_es', descriptionEs);
            formData.append('description_en', '');
            formData.append('i_desc', iDesc);

            fetch('config_manager.php?action=update', {
                method: 'POST',
                body: formData
            })
            .then(r => r.json())
            .then(d => {
                if (d.success) {
                    closeSceneModal();
                    // Refresh scene log to show updated description
                    loadScenes();
                    // Also notify parent window to refresh scenes manager if embedded
                    if (window.parent && window.parent.loadScenes) {
                        window.parent.loadScenes();
                    }
                } else {
                    alert('Error saving: ' + (d.error || 'Unknown error'));
                }
            })
            .catch(err => {
                alert('Network error: ' + err.message);
            });
        }

        // Close modal on background click
        document.getElementById('sceneEditModal').onclick = function(e) {
            if (e.target === this) closeSceneModal();
        };

        // Scenes
        function loadScenes() {
            fetch('?action=getSceneLog').then(r=>r.json()).then(d => {
                const area = document.getElementById('sceneArea');
                document.getElementById('sceneCount').textContent = d.entries.length;
                if (d.entries.length) {
                    area.innerHTML = d.entries.map(e => `
                        <div class="scene-entry">
                            <span class="scene-time">${esc(e.time)}</span>
                            <span class="scene-source ${e.source}">${e.source}</span>
                            <span class="scene-id" onclick="openSceneEditor('${esc(e.scene_id)}')" title="Click to edit description">${esc(e.scene_id)}</span>
                            <span class="scene-desc">${esc(e.description)}</span>
                        </div>
                    `).join('');
                } else {
                    area.innerHTML = '<div class="empty"><p>No scenes logged yet</p><p style="font-size:12px">Scene IDs appear here when OStim triggers</p></div>';
                }
                updateTime();
            });
        }
        function clearScenes() { if(confirm('Clear scene log?')) fetch('?action=clearSceneLog').then(loadScenes); }

        // Prompts
        function loadPrompts() {
            fetch('?action=getPromptLog').then(r=>r.json()).then(d => {
                const area = document.getElementById('promptArea');
                document.getElementById('promptCount').textContent = d.entries.length;
                if (d.entries.length) {
                    area.innerHTML = d.entries.map(e => `
                        <div class="prompt-entry">
                            <span class="prompt-time">${esc(e.time)}</span>
                            <span class="prompt-type ${e.type}">${e.type}</span>
                            <span class="prompt-npc">${esc(e.npc)}</span>
                            <span class="prompt-msg">${esc(e.message)}</span>
                        </div>
                    `).join('');
                } else {
                    area.innerHTML = '<div class="empty"><p>No prompts logged yet</p><p style="font-size:12px">Enable NSFW debug logging to see prompts here</p></div>';
                }
                updateTime();
            });
        }
        function clearPrompts() { if(confirm('Clear prompt log?')) fetch('?action=clearPromptLog').then(loadPrompts); }

        // Responses
        function loadResponses() {
            fetch('?action=getResponses').then(r=>r.json()).then(d => {
                const area = document.getElementById('responseArea');
                document.getElementById('responseCount').textContent = d.entries.length;
                if (d.entries.length) {
                    area.innerHTML = d.entries.map(e => `
                        <div class="response-entry">
                            <div class="response-header">
                                <span class="response-speaker">${esc(e.speaker)}</span>
                                <span class="response-time">${e.time}</span>
                            </div>
                            <div class="response-msg">"${esc(e.message)}"</div>
                        </div>
                    `).join('');
                } else {
                    area.innerHTML = '<div class="empty"><p>No recent responses</p><p style="font-size:12px">NPC dialogue appears here during scenes</p></div>';
                }
                updateTime();
            });
        }

        // Status
        function loadStatus() {
            fetch('?action=getStatus').then(r=>r.json()).then(d => {
                const area = document.getElementById('statusArea');
                if (d.statuses.length) {
                    area.innerHTML = '<div class="status-grid">' + d.statuses.map(s => `
                        <div class="status-card">
                            <h4>${esc(s.npc)}</h4>
                            <div class="status-row">
                                <span class="status-label">Phase</span>
                                <span class="status-value ${s.phase}">${s.phase}</span>
                            </div>
                            <div class="status-row">
                                <span class="status-label">Intimacy Level</span>
                                <span class="status-value">${s.level}</span>
                            </div>
                            <div class="status-row">
                                <span class="status-label">Scene Actors</span>
                                <span class="status-value">${esc(s.actors) || 'None'}</span>
                            </div>
                            <div class="status-row">
                                <span class="status-label">Last Update</span>
                                <span class="status-value">${s.updated}</span>
                            </div>
                        </div>
                    `).join('') + '</div>';
                } else {
                    area.innerHTML = '<div class="empty"><p>No active sessions</p><p style="font-size:12px">Status appears when NPCs enter intimate scenes</p></div>';
                }
                updateTime();
            });
        }

        // Auto-refresh
        let intervals = {};
        function setupAuto(id, fn) {
            const cb = document.getElementById(id);
            const run = () => { if(cb.checked) intervals[id] = setInterval(fn, 5000); else clearInterval(intervals[id]); };
            cb.onchange = run;
            run();
        }

        // Init
        loadScenes(); loadPrompts(); loadResponses(); loadStatus();
        setupAuto('arScenes', loadScenes);
        setupAuto('arPrompts', loadPrompts);
        setupAuto('arResponses', loadResponses);
        setupAuto('arStatus', loadStatus);
    </script>
</body>
</html>
