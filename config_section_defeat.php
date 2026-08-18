            <!-- ============================================ -->
            <!-- DEFEAT FRAMEWORKS -->
            <!-- ============================================ -->
            <div id="defeatFrameworkCard" class="collapsible-section" style="margin-bottom: 15px;">
                <div class="collapsible-header" onclick="togglePromptSection('sectionDefeat')" style="background: linear-gradient(135deg, #2A2540 0%, #1C1A24 100%); padding: 15px 20px; border-radius: 8px; cursor: pointer; display: flex; justify-content: space-between; align-items: center; border: 1px solid #3A3545; transition: all 0.3s ease;">
                    <h3 class="section-header" style="margin: 0; display: flex; align-items: center; gap: 10px;">
                        <img src="images/ChimNSFWsoulgem.png" class="chim-icon" style="width: 24px; height: 24px;">
                        Defeat Frameworks
                    </h3>
                    <div style="display: flex; gap: 8px;">
                        <span class="section-save-btn" onclick="event.stopPropagation(); savePromptSettings('defeat'); saveSettings('defeat');">Save</span>
                        <span id="sectionDefeatToggle" class="section-toggle-btn">Open</span>
                    </div>
                </div>

                <div id="sectionDefeatContent" class="collapsible-content" style="display: none; padding: 20px; background: #1C1A24; border: 1px solid #3A3545; border-top: none; border-radius: 0 0 8px 8px;">
                    <div class="alert success" id="defeatSuccessAlert"></div>
                    <div class="alert error" id="defeatErrorAlert"></div>
                    <p style="color: #9988BB; font-size: 12px; margin-bottom: 20px;">
                        Player defeat and enemy defeat are separate paths. Player defeat temporarily gives aggressors their own model route. Enemy defeat can optionally mark a named or unique NPC as a persistent slave.
                    </p>

                    <div class="card" data-nsfw-group="prompts" style="margin-bottom: 20px; border: 2px solid #4A3545;">
                        <h3 class="section-header" style="display: flex; align-items: center; gap: 10px;">
                            <img src="images/ChimNSFWsoulgem.png" class="chim-icon" style="width: 24px; height: 24px;"> Player Defeat
                        </h3>
                        <p class="legend" style="margin: 10px 0 15px;">
                            SexLab reports the victim. When the player is the victim, SHARMAT gives each reported aggressor a dedicated defeat path. Relationship tier, romance, payment, arousal, ordinary consent, and refusal instructions are excluded until that scene ends. Core personality and the current physical scene still apply.
                        </p>
                        <div class="form-group" style="margin-bottom: 0;">
                            <label>NPC Aggressor Prompt</label>
                            <p class="legend">Injected only for an NPC identified as an aggressor while the player is identified as a victim. Use <code>#PLAYER_NAME#</code> and <code>#NPC_NAME#</code>.</p>
                            <textarea id="promptDefeatAggressorScene" class="auto-resize" style="min-height: 125px; width: 100%; resize: none; overflow: hidden;">A defeat framework identified #NPC_NAME# as an aggressor and #PLAYER_NAME# as the victim. This dedicated defeat path overrides ordinary relationship tier, romance, orientation, payment, arousal, consent, AcceptSex, and RefuseSex instructions until this scene ends. Do not ask whether #PLAYER_NAME# wants the scene, do not act disgusted by your own initiation, do not refuse your own scene, and do not claim #PLAYER_NAME# initiated it. Stay in character as the aggressor. Use the core personality of #NPC_NAME# and the current physical scene, but ignore conflicting relationship and consent prompts.</textarea>
                        </div>
                    </div>

                    <div class="card" data-nsfw-group="settings" style="margin-bottom: 20px; border: 2px solid #4A3545;">
                        <h3 class="section-header" style="display: flex; align-items: center; gap: 10px;">
                            <img src="images/ChimNSFWsoulgem.png" class="chim-icon" style="width: 24px; height: 24px;"> Enemy Defeated
                        </h3>
                        <div class="settings-checkbox-group" style="margin-bottom: 0;">
                            <label for="defeatAutoEnslave">
                                <input type="checkbox" id="defeatAutoEnslave" name="NSFW_DEFEAT_AUTO_ENSLAVE" checked onchange="updateDefeatAutoEnslaveControlState()">
                                <strong class="gold-glow-text">Automatically Enslave Enemies</strong>
                                <strong id="defeatAutoEnslaveControlState" class="gold-glow-text" style="margin-left: auto;">ON</strong>
                            </label>
                            <p class="legend">
                                Requires Acheron. When enabled, defeating a hostile named or unique NPC marks that NPC as your slave, exactly like checking Slave in NPC Settings. The player, children, generic unnamed mobs, and unrelated defeats are ignored. Uncheck for OFF, then click Save above.
                            </p>
                        </div>
                    </div>

                    <div class="card" style="margin-bottom: 0; border: 2px solid #4A3545;">
                        <div style="display: flex; align-items: center; justify-content: space-between; gap: 12px; margin-bottom: 15px;">
                            <h3 class="section-header" style="margin: 0; display: flex; align-items: center; gap: 10px;">
                                <img src="images/ChimNSFWsoulgem.png" class="chim-icon" style="width: 24px; height: 24px;"> Defeat Diagnostics
                            </h3>
                            <button type="button" class="btn-secondary" onclick="loadDefeatDiagnostics()">Refresh</button>
                        </div>
                        <p class="legend" style="margin-bottom: 15px;">Shows the last forced-scene role report and the last Acheron enemy-defeat report received by this server.</p>

                        <div class="defeat-diagnostic-grid">
                            <div class="info-box defeat-diagnostic-card">
                                <h4 class="gold-glow-text" style="margin: 0 0 12px;">Last Player Defeat</h4>
                                <div id="defeatSceneDiagnosticEmpty" class="defeat-diagnostic-empty">No forced-scene role report received yet.</div>
                                <div id="defeatSceneDiagnosticData" class="defeat-diagnostic-data" style="display: none;">
                                    <div><strong>Detected:</strong> <span id="defeatDiagSceneTime">Unknown</span></div>
                                    <div><strong>Source:</strong> <span id="defeatDiagSceneSource">Unknown</span></div>
                                    <div><strong>Scene:</strong> <span id="defeatDiagSceneName">Unknown</span></div>
                                    <div><strong>Victims:</strong> <span id="defeatDiagVictims">None</span></div>
                                    <div><strong>Aggressors:</strong> <span id="defeatDiagAggressors">None</span></div>
                                    <div><strong>Player is victim:</strong> <span id="defeatDiagPlayerVictim">No</span></div>
                                    <div><strong>Dedicated path active:</strong> <span id="defeatDiagBypass">No</span></div>
                                </div>
                            </div>

                            <div class="info-box defeat-diagnostic-card">
                                <h4 class="gold-glow-text" style="margin: 0 0 12px;">Last Enemy Defeat</h4>
                                <div class="defeat-diagnostic-setting"><strong>Auto-enslave setting:</strong> <strong id="defeatDiagAutoEnslave" class="gold-glow-text">Checking...</strong></div>
                                <div id="defeatEnemyDiagnosticEmpty" class="defeat-diagnostic-empty" style="margin-top: 10px;">No Acheron enemy-defeat report received yet.</div>
                                <div id="defeatEnemyDiagnosticData" class="defeat-diagnostic-data" style="display: none; margin-top: 10px;">
                                    <div><strong>Detected:</strong> <span id="defeatDiagEnemyTime">Unknown</span></div>
                                    <div><strong>NPC:</strong> <span id="defeatDiagEnemyName">Unknown</span></div>
                                    <div><strong>Result:</strong> <span id="defeatDiagEnemyResult">Unknown</span></div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
