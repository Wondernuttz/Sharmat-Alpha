        <!-- SHARMAT Logs Tab -->
        <div id="logs" class="tab-content">
            <h2 class="section-header" style="margin-bottom: 20px; display: flex; align-items: center; gap: 10px;"><img src="images/ChimNSFWsoulgem.png" style="width: 32px; height: 32px; vertical-align: middle; position: relative; top: -5px;"> Sharmat Debug Logs</h2>

            <div class="logs-container" style="display: flex; flex-direction: column; height: calc(100vh - 250px); min-height: 500px;">
                <!-- Sub-tabs for different log types -->
                <div class="logs-tabs" style="display: flex; gap: 5px; margin-bottom: 15px; flex-wrap: wrap;">
                    <button class="log-tab-btn" onclick="switchLogTab('ostim')" data-tab="ostim">OStim Scenes</button>
                    <button class="log-tab-btn active" onclick="switchLogTab('physics')" data-tab="physics">VR Physics</button>
                    <button class="log-tab-btn" onclick="switchLogTab('prompts')" data-tab="prompts">Prompts Sent</button>
                    <button class="log-tab-btn" onclick="switchLogTab('responses')" data-tab="responses">NPC Responses</button>
                    <button class="log-tab-btn" onclick="switchLogTab('status')" data-tab="status">Live Status</button>
                </div>

                <!-- Controls -->
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px; flex-wrap: wrap; gap: 10px;">
                    <label class="logs-checkbox-group">
                        <input type="checkbox" id="logsAutoRefresh" checked>
                        <span>Auto-refresh (5s)</span>
                    </label>
                    <div style="display: flex; gap: 10px;">
                        <button class="btn-secondary" onclick="refreshLogs()" style="padding: 6px 12px; font-size: 12px;">Refresh</button>
                        <button class="btn-clear-log" onclick="clearCurrentLog()">Clear Log</button>
                    </div>
                </div>

                <!-- Log content area -->
                <div class="log-content-wrapper" style="flex: 1; background: #1C1A24; border: 1px solid #3A3545; border-radius: 8px; overflow: hidden; display: flex; flex-direction: column;">
                    <!-- OStim Scenes Log -->
                    <div id="log-ostim" class="log-panel" style="flex: 1; overflow-y: auto; padding: 15px; display: none;">
                        <div class="log-loading" style="text-align: center; padding: 40px; color: #666;">Loading...</div>
                    </div>

                    <!-- VR Physics Log -->
                    <div id="log-physics" class="log-panel active" style="flex: 1; overflow-y: auto; padding: 15px;">
                        <div class="log-loading" style="text-align: center; padding: 40px; color: #666;">Loading...</div>
                    </div>

                    <!-- Prompts Sent Log -->
                    <div id="log-prompts" class="log-panel" style="flex: 1; overflow-y: auto; padding: 15px; display: none;">
                        <div class="log-loading" style="text-align: center; padding: 40px; color: #666;">Loading...</div>
                    </div>

                    <!-- NPC Responses Log -->
                    <div id="log-responses" class="log-panel" style="flex: 1; overflow-y: auto; padding: 15px; display: none;">
                        <div class="log-loading" style="text-align: center; padding: 40px; color: #666;">Loading...</div>
                    </div>

                    <!-- Live Status -->
                    <div id="log-status" class="log-panel" style="flex: 1; overflow-y: auto; padding: 15px; display: none;">
                        <div class="log-loading" style="text-align: center; padding: 40px; color: #666;">Loading...</div>
                    </div>
                </div>

                <!-- Status bar -->
                <div id="logsStatusBar" style="padding: 8px 15px; background: #252233; font-size: 11px; color: #888; border-top: 1px solid #3A3545; margin-top: 10px; border-radius: 0 0 8px 8px;">
                    Entries: 0 | Last update: --
                </div>
            </div>
        </div>
