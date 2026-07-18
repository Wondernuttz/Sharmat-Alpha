        <!-- NPC Settings Tab -->
        <div id="speakstyles" class="tab-content">
            <div class="alert success" id="speakStylesSuccessAlert"></div>
            <div class="alert error" id="speakStylesErrorAlert"></div>

            <!-- NPC NSFW Settings Card -->
            <div class="npc-settings-card" style="background: #252233; border: 1px solid #3A3545; border-radius: 10px; padding: 12px; margin: 8px 0;">
                <h3 class="section-header" style="margin: 0 0 3px 0; display: flex; align-items: center; gap: 10px;">
                    <img src="images/ChimNSFWsoulgem.png" style="width: 32px; height: 32px; vertical-align: middle; position: relative; top: -5px;"> NPC NSFW Settings
                </h3>
                <p class="legend" style="margin-bottom: 10px; color: #B8A8D0;">Configure NPC behavior during intimate scenes - speech styles, sex worker services, kinks, and AI generation.</p>

                <div class="row" style="display: flex; gap: 15px; margin-bottom: 10px;">
                    <div class="form-group" style="flex: 1;">
                        <label for="npcSelectInput">Select NPC</label>
                        <div style="position: relative;">
                            <input type="text" id="npcSelectInput" name="npc_search_field_unique" placeholder="Type NPC name..." autocomplete="off" data-lpignore="true" data-1p-ignore="true" data-form-type="other" data-bwignore="true" aria-autocomplete="none" style="width: 100%; padding: 10px; background: #252233; border: 1px solid #3A3545; color: #B8A8D0; border-radius: 5px;">
                            <div id="npcAutocompleteList" class="autocomplete-list" style="display: none;"></div>
                        </div>
                    </div>
                    <div class="form-group" style="flex: 1;">
                        <label for="aiConnectorSelect">AI Connector (for generation)</label>
                        <div style="display: flex; gap: 8px; align-items: center;">
                            <select id="aiConnectorSelect" style="flex: 1; padding: 10px; background: #252233; border: 1px solid #3A3545; color: #B8A8C8; border-radius: 5px;">
                                <option value="">-- Select connector --</option>
                                <?php
                                $connectors = $GLOBALS["db"]->fetchAll("SELECT id, label FROM core_llm_connector ORDER BY label");
                                foreach ($connectors as $conn) {
                                    $id = htmlspecialchars($conn['id']);
                                    $label = htmlspecialchars($conn['label']);
                                    echo "<option value=\"{$id}\">{$label}</option>\n";
                                }
                                ?>
                            </select>
                            <button type="button" class="btn-secondary" onclick="openAiPromptModal()" style="padding: 8px 10px; white-space: nowrap; font-family: 'MagicCards', 'Segoe UI', sans-serif; letter-spacing: 1px; word-spacing: 4px; font-size: 12px;">Edit Prompt</button>
                        </div>
                        <p style="font-size: 11px; color: #B8A8D0; margin: 5px 0 8px 0;"><strong>Grok highly recommended</strong> - it doesn't hold back on adult content.</p>
                        <div style="display: flex; gap: 10px;">
                            <button type="button" id="autoGenerateToggle" class="btn-secondary npc-action-btn" onclick="toggleAutoGenerate()">Generate as NPCs are Met</button>
                            <button class="btn-primary npc-action-btn" onclick="batchGenerateNsfwProfiles()">Batch Generate NPCs</button>
                        </div>
                    </div>
                </div>

                <!-- Batch Progress Bar -->
                <div id="batchGenerateProgress" class="batch-progress-card">
                    <div class="batch-progress-header">
                        <span id="batchProgressText" class="batch-progress-title">Processing...</span>
                        <span id="batchProgressCount" class="batch-progress-count">0 / 0</span>
                    </div>
                    <div class="batch-progress-track">
                        <div id="batchProgressBar" class="batch-progress-fill"></div>
                    </div>
                    <div id="batchExistingSkipped" class="batch-existing-skipped"></div>
                    <div id="batchSkippedList" class="batch-skipped-list"></div>
                </div>

                <!-- NPC Specific Settings Panel -->
                <div id="npcSettingsPanel" style="background: #252233; border: 1px solid #3A3545; border-radius: 8px; padding: 20px; margin-top: 15px;">
                    <div style="display: flex; align-items: flex-start; margin-bottom: 15px;">
                        <!-- Race portrait on left edge -->
                        <div id="racePortraitContainer" class="race-portrait-container visible" style="display: flex; flex-direction: column; align-items: center; gap: 5px;">
                            <img id="racePortraitImg" class="race-portrait" src="images/ChimNSFWsoulgem.png" alt="Race Portrait">
                            <span id="raceLabel" class="race-label-small" style="display: none;"></span>
                        </div>
                        <!-- NPC name, button, and badges -->
                        <div style="margin-left: 15px; display: flex; flex-direction: column; gap: 8px;">
                            <h4 id="npcSettingsTitle" class="info-subtitle npc-title-large" style="margin: 0;">Select an NPC above</h4>
                            <button class="btn-primary npc-action-btn" onclick="generateSexPrompt()">Generate with AI</button>
                            <div style="display: flex; gap: 8px; align-items: center;">
                                <span id="aiGeneratedBadge" class="source-badge ai-badge" style="display: none;">AI Generated</span>
                                <span id="manualOverrideBadge" class="source-badge manual-badge" style="display: none;">User Input</span>
                            </div>
                        </div>
                        <!-- Speak Style and Profanity on the right -->
                        <div style="margin-left: 50px; display: flex; flex-direction: column; gap: 5px;">
                            <div class="form-group" style="margin-bottom: 0;">
                                <label style="margin-bottom: 2px;">Speak Style</label>
                                <select id="speakStyleSelect" style="padding: 8px 10px; background: #252233; border: 1px solid #3A3545; color: #B8A8C8; border-radius: 5px; min-width: 280px;" onchange="onSpeakStyleChange()">
                                    <option value="">-- Select speak style --</option>
                                    <?php
                                    // Load speak styles from JSONB (single source of truth)
                                    $styleRow = $GLOBALS["db"]->fetchOne("SELECT value FROM conf_opts WHERE id = 'aiagent_nsfw_speak_styles'");
                                    if ($styleRow && !empty($styleRow['value'])) {
                                        $allStyles = json_decode($styleRow['value'], true) ?: [];
                                        ksort($allStyles); // Sort by name
                                        foreach ($allStyles as $styleName => $styleData) {
                                            $name = htmlspecialchars($styleName);
                                            $desc = htmlspecialchars($styleData['description'] ?? '');
                                            $emoji = $styleData['emoji'] ?? '📝';
                                            $displayName = ucfirst($name);
                                            echo "<option value=\"{$name}\">{$emoji} {$displayName} - {$desc}</option>\n";
                                        }
                                    }
                                    ?>
                                </select>
                            </div>
                            <div class="form-group" style="margin-bottom: 0;">
                                <label for="profanityLevel" style="margin-bottom: 2px;">Profanity Level</label>
                                <select id="profanityLevel" style="width: 100%; padding: 8px 10px; background: #252233; border: 1px solid #3A3545; color: #B8A8C8; border-radius: 5px;">
                                    <option value="">-- Not set --</option>
                                    <option value="1">1 - Soft (tasteful, romantic)</option>
                                    <option value="2">2 - Moderate (some explicit terms)</option>
                                    <option value="3">3 - Hard (crude, vulgar)</option>
                                    <option value="4">4 - Extreme (extreme vulgarity)</option>
                                </select>
                            </div>
                        </div>
                    </div>

                    <!-- Relationship Status Row -->
                    <div style="display: flex; gap: 15px; margin-bottom: 15px; flex-wrap: wrap;">
                        <div class="form-group" style="flex: 1; min-width: 150px; margin-bottom: 0;">
                            <label style="margin-bottom: 2px;">Spousal Status</label>
                            <select id="spousalStatus" style="width: 100%; padding: 8px 10px; background: #252233; border: 1px solid #3A3545; color: #B8A8C8; border-radius: 5px;" onchange="onSpousalStatusChange()">
                                <option value="">-- Not set --</option>
                                <option value="single">Single</option>
                                <option value="married">Married</option>
                                <option value="widowed">Widowed</option>
                            </select>
                        </div>
                        <div class="form-group" style="flex: 2; min-width: 250px; margin-bottom: 0; position: relative;">
                            <label style="margin-bottom: 2px;">Spouse Name(s) <span style="color: #9988BB; font-size: 10px;">(comma-separated for multiple)</span></label>
                            <input type="text" id="spouseNamesInput" placeholder="Type to search or enter names..." autocomplete="off" style="width: 100%; padding: 8px 10px; background: #252233; border: 1px solid #3A3545; color: #B8A8D0; border-radius: 5px;">
                            <div id="spouseAutocompleteList" class="autocomplete-list" style="display: none;"></div>
                        </div>
                        <div class="form-group" style="flex: 1; min-width: 150px; margin-bottom: 0;">
                            <label style="margin-bottom: 2px;">Sexual Orientation</label>
                            <select id="sexualOrientation" style="width: 100%; padding: 8px 10px; background: #252233; border: 1px solid #3A3545; color: #B8A8C8; border-radius: 5px;">
                                <option value="">-- Not set --</option>
                                <option value="heterosexual">Heterosexual</option>
                                <option value="homosexual">Homosexual</option>
                                <option value="bisexual">Bisexual</option>
                                <option value="asexual">Asexual</option>
                            </select>
                        </div>
                        <div class="form-group" style="flex: 1; min-width: 150px; margin-bottom: 0;">
                            <label style="margin-bottom: 2px;">Relationship Preference</label>
                            <select id="relationshipPreference" style="width: 100%; padding: 8px 10px; background: #252233; border: 1px solid #3A3545; color: #B8A8C8; border-radius: 5px;">
                                <option value="">-- Not set --</option>
                                <option value="monogamous">Monogamous</option>
                                <option value="polyamorous">Polyamorous</option>
                                <option value="uncommitted">Uncommitted</option>
                                <option value="not_interested">Not Interested</option>
                            </select>
                        </div>
                    </div>

                    <div class="form-group" style="margin-bottom: 10px;">
                        <label for="sexPrompt">Sex Prompt (NPC-specific personality during scenes)</label>
                        <textarea id="sexPrompt" placeholder="Describe how this NPC behaves during intimate scenes..." style="width: 100%; min-height: 100px; background: #252233; border: 1px solid #3A3545; color: #B8A8D0; border-radius: 5px; padding: 10px;"></textarea>
                    </div>

                    <!-- Normal Kinks Section -->
                    <div class="form-group" style="margin-bottom: 15px;">
                        <div style="display: flex; align-items: center; gap: 15px; margin-bottom: 5px;">
                            <label for="kinksInput" style="margin: 0;">Normal Kinks</label>
                            <div style="display: flex; align-items: center; gap: 8px;">
                                <span style="color: #9988BB; font-size: 11px;">Unlocked at:</span>
                                <select id="normalKinksUnlockTier" style="padding: 4px 8px; background: #252233; border: 1px solid #3A3545; color: #B8A8C8; border-radius: 4px; font-size: 11px;">
                                    <option value="-100">Hostile (-100 to -91)</option>
                                    <option value="-90">Hateful (-90 to -76)</option>
                                    <option value="-75">Resentful (-75 to -56)</option>
                                    <option value="-55">Cold (-55 to -31)</option>
                                    <option value="-30">Wary (-30 to -6)</option>
                                    <option value="-5">Neutral (-5 to +5)</option>
                                    <option value="6">Acquaintance (+6 to +30)</option>
                                    <option value="31">Friendly (+31 to +55)</option>
                                    <option value="56" selected>Fond (+56 to +75)</option>
                                    <option value="76">Devoted (+76 to +90)</option>
                                    <option value="91">Bonded (+91 to +100)</option>
                                </select>
                            </div>
                        </div>
                        <p style="font-size: 11px; color: #9988BB; margin: 5px 0;">Things they'll ask for during intimate moments - preferences, positions, turn-ons.</p>
                        <input type="text" id="kinksInput" placeholder="outdoors, rough sex, biting/marking, doggy style, hair pulling" style="width: 100%; padding: 10px; background: #252233; border: 1px solid #3A3545; color: #B8A8D0; border-radius: 5px;">
                    </div>

                    <div class="form-group" style="margin-bottom: 20px;">
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px;">
                            <div>
                                <label style="margin: 0; display: block;">Quick add normal kinks:</label>
                                <div style="font-size: 11px; color: #9988BB; font-style: italic;">Click tag to toggle • Click <span style="color: #FDF5D0;">×</span> to remove</div>
                            </div>
                            <button type="button" class="btn-new-kink" onclick="addNewKinkTag('normal')">New</button>
                        </div>
                        <div id="kinkTagsWrapper" style="position: relative; padding: 5px; margin: -5px;">
                            <div id="kinkTagsContainer" class="kink-tags" style="display: flex; flex-wrap: wrap; gap: 8px; align-items: center; max-height: 90px; overflow: visible; transition: max-height 0.3s ease;">
                                <!-- Tags will be populated by JavaScript -->
                            </div>
                            <button type="button" id="kinkTagsToggle" class="kink-expand-btn" onclick="toggleKinkContainer('normal')" style="display: none; margin-top: 5px; font-size: 11px; color: #9988BB; background: none; border: none; cursor: pointer;">Show more ▼</button>
                        </div>
                    </div>

                    <!-- Secret Kinks Section -->
                    <div class="form-group" style="margin-bottom: 15px; margin-top: 25px; padding-top: 20px; border-top: 1px solid #3A3545;">
                        <div style="display: flex; align-items: center; gap: 15px; margin-bottom: 5px;">
                            <label for="secretKinksInput" style="margin: 0;">Secret Kinks</label>
                            <div style="display: flex; align-items: center; gap: 8px;">
                                <span style="color: #9988BB; font-size: 11px;">Unlocked at:</span>
                                <select id="secretKinksUnlockTier" style="padding: 4px 8px; background: #252233; border: 1px solid #3A3545; color: #B8A8C8; border-radius: 4px; font-size: 11px;">
                                    <option value="-100">Hostile (-100 to -91)</option>
                                    <option value="-90">Hateful (-90 to -76)</option>
                                    <option value="-75">Resentful (-75 to -56)</option>
                                    <option value="-55">Cold (-55 to -31)</option>
                                    <option value="-30">Wary (-30 to -6)</option>
                                    <option value="-5">Neutral (-5 to +5)</option>
                                    <option value="6">Acquaintance (+6 to +30)</option>
                                    <option value="31">Friendly (+31 to +55)</option>
                                    <option value="56">Fond (+56 to +75)</option>
                                    <option value="76" selected>Devoted (+76 to +90)</option>
                                    <option value="91">Bonded (+91 to +100)</option>
                                </select>
                            </div>
                        </div>
                        <p style="font-size: 11px; color: #9988BB; margin: 5px 0;">Deepest, darkest desires they only reveal to someone they truly trust.</p>
                        <input type="text" id="secretKinksInput" placeholder="breeding, degradation, public humiliation, extreme bondage" style="width: 100%; padding: 10px; background: #252233; border: 1px solid #3A3545; color: #B8A8D0; border-radius: 5px;">
                    </div>

                    <div class="form-group" style="margin-bottom: 20px;">
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px;">
                            <div>
                                <label style="margin: 0; display: block;">Quick add secret kinks:</label>
                                <div style="font-size: 11px; color: #9988BB; font-style: italic;">Click tag to toggle • Click <span style="color: #FDF5D0;">×</span> to remove</div>
                            </div>
                            <button type="button" class="btn-new-kink" onclick="addNewKinkTag('secret')">New</button>
                        </div>
                        <div id="secretKinkTagsWrapper" style="position: relative; padding: 5px; margin: -5px;">
                            <div id="secretKinkTagsContainer" class="kink-tags" style="display: flex; flex-wrap: wrap; gap: 8px; align-items: center; max-height: 90px; overflow: visible; transition: max-height 0.3s ease;">
                                <!-- Tags will be populated by JavaScript -->
                            </div>
                            <button type="button" id="secretKinkTagsToggle" class="kink-expand-btn" onclick="toggleKinkContainer('secret')" style="display: none; margin-top: 5px; font-size: 11px; color: #9988BB; background: none; border: none; cursor: pointer;">Show more ▼</button>
                        </div>
                    </div>

                    <!-- Prostitute Checkbox and Pricing Panel -->
                    <div class="prostitute-section" style="background: #2A2233; border: 2px solid #4A3545; border-radius: 8px; padding: 10px 15px; margin-bottom: 8px;">
                        <label style="display: flex; align-items: center; gap: 10px; cursor: pointer; margin: 0;">
                            <input type="checkbox" id="isProstitute" style="width: 18px; height: 18px;" onchange="if(this.checked){var s=document.getElementById('isSlave'); if(s){s.checked=false; toggleSlaveOptions();} var sl=document.getElementById('isSlut'); if(sl){sl.checked=false;}} toggleProstitutePricing()">
                            <span class="gold-glow-text">This NPC offers adult entertainment services</span>
                        </label>

                        <!-- Expandable Pricing Panel -->
                        <div id="prostitutePricingPanel" style="display: none; margin-top: 20px;">
                            <!-- Prostitute Type, Motivation & Payment Type -->
                            <div class="row" style="display: flex; gap: 20px; margin-bottom: 20px; flex-wrap: wrap;">
                                <div class="form-group" style="flex: 1; min-width: 180px;">
                                    <label>Type</label>
                                    <select id="prostituteType" style="width: 100%; padding: 10px; background: #252233; border: 1px solid #3A3545; color: #B8A8D0; border-radius: 5px;">
                                        <option value="streetwalker">Streetwalker (works the streets)</option>
                                        <option value="tavern_worker">Tavern Worker (entertains patrons)</option>
                                        <option value="courtesan">Courtesan (refined, luxury)</option>
                                        <option value="escort">Escort (professional, discreet)</option>
                                        <option value="temple_prostitute">Temple Prostitute (sacred rites)</option>
                                        <option value="camp_follower">Camp Follower (travels with armies)</option>
                                    </select>
                                </div>
                                <div class="form-group" style="flex: 1; min-width: 180px;">
                                    <label>Motivation</label>
                                    <select id="prostituteMotivation" style="width: 100%; padding: 10px; background: #252233; border: 1px solid #3A3545; color: #B8A8D0; border-radius: 5px;">
                                        <option value="professional">Professional (business-like, experienced)</option>
                                        <option value="survival">Survival (desperate, needs the money)</option>
                                        <option value="pleasure">Pleasure (enjoys the work)</option>
                                        <option value="forced">Forced (unwilling, controlled)</option>
                                        <option value="sacred">Sacred (temple prostitute, religious)</option>
                                    </select>
                                </div>
                                <div class="form-group" style="flex: 1; min-width: 180px;">
                                    <label>Payment Type</label>
                                    <select id="paymentType" style="width: 100%; padding: 10px; background: #252233; border: 1px solid #3A3545; color: #B8A8D0; border-radius: 5px;">
                                        <option value="gold">Gold/Septims</option>
                                        <option value="favors">Favors/Services</option>
                                        <option value="goods">Goods/Items</option>
                                        <option value="mixed">Mixed (flexible)</option>
                                    </select>
                                </div>
                            </div>

                            <!-- Scene Prompts for this NPC -->
                            <div style="background: #252233; border: 1px solid #3A3545; border-radius: 8px; padding: 15px; margin-bottom: 20px;">
                                <p style="color: #9988BB; font-size: 11px; margin-bottom: 15px;">
                                    <strong style="color: #B8A8D0;">Scene Prompts</strong> - Triggered by: Prostitution mod event OR this checkbox being enabled.
                                </p>
                                <div class="form-group" style="margin-bottom: 12px;">
                                    <label style="color: #B8A8D0; font-size: 12px;">Prostitute Personality Override</label>
                                    <textarea id="npcProstitutionPersonality" class="auto-resize" style="min-height: 48px; width: 100%; resize: none; overflow: hidden; background: #1C1A24; border: 1px solid #3A3545; color: #B8A8D0; padding: 8px; border-radius: 5px; font-size: 14px;" placeholder="How this NPC approaches their work..."></textarea>
                                </div>
                                <div class="form-group" style="margin-bottom: 12px;">
                                    <label style="color: #B8A8D0; font-size: 12px;">During Service Prompt</label>
                                    <textarea id="npcProstitutionDuring" class="auto-resize" style="min-height: 48px; width: 100%; resize: none; overflow: hidden; background: #1C1A24; border: 1px solid #3A3545; color: #B8A8D0; padding: 8px; border-radius: 5px; font-size: 14px;" placeholder="Behavior during the service..."></textarea>
                                </div>
                                <div class="form-group" style="margin-bottom: 12px;">
                                    <label style="color: #B8A8D0; font-size: 12px;">Orgasm / Climax Prompt</label>
                                    <textarea id="npcProstitutionOrgasm" class="auto-resize" style="min-height: 48px; width: 100%; resize: none; overflow: hidden; background: #1C1A24; border: 1px solid #3A3545; color: #B8A8D0; padding: 8px; border-radius: 5px; font-size: 14px;" placeholder="How she reacts when SHE climaxes during the service (overrides her profile climax). #NPC_NAME# / #PLAYER_NAME#..."></textarea>
                                </div>
                                <div class="form-group" style="margin-bottom: 0;">
                                    <label style="color: #B8A8D0; font-size: 12px;">Post-Service / Pillow Talk</label>
                                    <textarea id="npcProstitutionAfter" class="auto-resize" style="min-height: 48px; width: 100%; resize: none; overflow: hidden; background: #1C1A24; border: 1px solid #3A3545; color: #B8A8D0; padding: 8px; border-radius: 5px; font-size: 14px;" placeholder="After the service is complete..."></textarea>
                                </div>
                            </div>

                            <!-- Session Price (single flat rate for the whole scene) -->
                            <div class="form-group" style="margin-top: 12px;">
                                <label>Session Price (gold)</label>
                                <input type="number" id="sessionPrice" value="100" min="0" style="width: 100%; padding: 10px; background: #252233; border: 1px solid #3A3545; color: #B8A8D0; border-radius: 5px;">
                                <p style="font-size: 11px; color: #9988BB; margin: 6px 0 0 0;">One flat price for the whole scene, agreed up front and fixed start to finish.</p>
                            </div>
                        </div>
                    </div>

                    <!-- Slave Checkbox Section -->
                    <div class="slave-section" style="background: #2A2233; border: 2px solid #4A3545; border-radius: 8px; padding: 10px 15px; margin-bottom: 15px;">
                        <label style="display: flex; align-items: center; gap: 10px; cursor: pointer; margin: 0;">
                            <input type="checkbox" id="isSlave" onchange="if(this.checked){var p=document.getElementById('isProstitute'); if(p){p.checked=false; toggleProstitutePricing();} var sl=document.getElementById('isSlut'); if(sl){sl.checked=false;}} toggleSlaveOptions()">
                            <span class="gold-glow-text">This NPC is enslaved</span>
                        </label>
                        <p style="color: #886666; font-size: 10px; margin: 5px 0 0 30px; font-style: italic;">
                            ⚠️ Note: Loading an older save will reset this setting. Re-check this box after loading if needed.
                        </p>

                        <!-- Slavery fiction frame is now a GLOBAL toggle on the Prompts tab (next to the fiction frame prompt) -->

                        <!-- Expandable Slave Options Panel -->
                        <div id="slaveOptionsPanel" style="display: none; margin-top: 20px;">
                            <p style="color: #9988BB; font-size: 11px; margin-bottom: 15px;">
                                <strong style="color: #B8A8D0;">Slave Speak Styles</strong> - Customize how this slave expresses themselves during intimate scenes.
                                Leave blank to use global defaults. Affinity-based personality is automatically applied from global settings.
                            </p>

                            <!-- Slave Speak Style -->
                            <div style="background: #252235; padding: 15px; border-radius: 8px; margin-bottom: 15px; border: 1px solid #3A3545;">
                                <h4 style="color: #B8A8C8; margin-bottom: 10px; display: flex; align-items: center; gap: 8px;">
                                    <img src="images/ChimNSFWsoulgem.png" style="width: 20px; height: 20px;"> Slave Speak Style
                                </h4>
                                <p style="color: #7766AA; font-size: 10px; margin-bottom: 10px;">How this slave speaks and addresses their owner during intimate scenes</p>
                                <div class="form-group">
                                    <textarea id="npcSlaveSpeakStyle" class="auto-resize" style="min-height: 60px; width: 100%; resize: none; overflow: hidden; background: #1C1A24; border: 1px solid #3A3545; color: #B8A8D0; padding: 8px; border-radius: 5px; font-size: 14px;" placeholder="Leave blank for global default..."></textarea>
                                </div>
                            </div>

                            <!-- Slave Scene Cues -->
                            <div style="background: #252235; padding: 15px; border-radius: 8px; margin-bottom: 15px; border: 1px solid #3A3545;">
                                <h4 style="color: #B8A8C8; margin-bottom: 10px; display: flex; align-items: center; gap: 8px;">
                                    <img src="images/ChimNSFWsoulgem.png" style="width: 20px; height: 20px;"> Scene Cues
                                </h4>
                                <p style="color: #7766AA; font-size: 10px; margin-bottom: 10px;">How this slave behaves and speaks during the scene</p>
                                <div class="form-group">
                                    <textarea id="npcSlaveSceneCues" class="auto-resize" style="min-height: 60px; width: 100%; resize: none; overflow: hidden; background: #1C1A24; border: 1px solid #3A3545; color: #B8A8D0; padding: 8px; border-radius: 5px; font-size: 14px;" placeholder="Leave blank for global default..."></textarea>
                                </div>
                            </div>

                            <!-- Slave Climax (3 tiers chosen by the slave's affinity toward their owner) -->
                            <div style="background: #252235; padding: 15px; border-radius: 8px; margin-bottom: 15px; border: 1px solid #3A3545;">
                                <h4 style="color: #B8A8C8; margin-bottom: 10px; display: flex; align-items: center; gap: 8px;">
                                    <img src="images/ChimNSFWsoulgem.png" style="width: 20px; height: 20px;"> Slave Climax
                                </h4>
                                <p style="color: #7766AA; font-size: 10px; margin-bottom: 10px;">How this slave reacts at climax. Three tiers picked by their affinity toward their owner. Leave a tier blank for the global default.</p>
                                <div class="form-group">
                                    <label style="color: #B8A8D0; font-size: 11px;">Eager (high affinity / devoted)</label>
                                    <textarea id="npcSlaveClimaxEager" class="auto-resize" style="min-height: 44px; width: 100%; resize: none; overflow: hidden; background: #1C1A24; border: 1px solid #3A3545; color: #B8A8D0; padding: 8px; border-radius: 5px; font-size: 14px;" placeholder="Really into it - cries out for their master..."></textarea>
                                </div>
                                <div class="form-group">
                                    <label style="color: #B8A8D0; font-size: 11px;">Neutral</label>
                                    <textarea id="npcSlaveClimaxNeutral" class="auto-resize" style="min-height: 44px; width: 100%; resize: none; overflow: hidden; background: #1C1A24; border: 1px solid #3A3545; color: #B8A8D0; padding: 8px; border-radius: 5px; font-size: 14px;" placeholder="Goes along with it..."></textarea>
                                </div>
                                <div class="form-group" style="margin-bottom: 0;">
                                    <label style="color: #B8A8D0; font-size: 11px;">Reluctant (low affinity / resentful)</label>
                                    <textarea id="npcSlaveClimaxReluctant" class="auto-resize" style="min-height: 44px; width: 100%; resize: none; overflow: hidden; background: #1C1A24; border: 1px solid #3A3545; color: #B8A8D0; padding: 8px; border-radius: 5px; font-size: 14px;" placeholder="Dutiful, detached - 'are you finished, Master?'"></textarea>
                                </div>
                            </div>

                            <!-- Owner Climax Reaction -->
                            <div style="background: #252235; padding: 15px; border-radius: 8px; margin-bottom: 15px; border: 1px solid #3A3545;">
                                <h4 style="color: #B8A8C8; margin-bottom: 10px; display: flex; align-items: center; gap: 8px;">
                                    <img src="images/ChimNSFWsoulgem.png" style="width: 20px; height: 20px;"> Owner Climax Reaction
                                </h4>
                                <p style="color: #7766AA; font-size: 10px; margin-bottom: 10px;">How this slave reacts when their owner reaches climax</p>
                                <div class="form-group">
                                    <textarea id="npcSlaveOwnerClimax" class="auto-resize" style="min-height: 48px; width: 100%; resize: none; overflow: hidden; background: #1C1A24; border: 1px solid #3A3545; color: #B8A8D0; padding: 8px; border-radius: 5px; font-size: 14px;" placeholder="Leave blank for global default..."></textarea>
                                </div>
                            </div>

                            <!-- Aftermath -->
                            <div style="background: #252235; padding: 15px; border-radius: 8px; border: 1px solid #3A3545;">
                                <h4 style="color: #B8A8C8; margin-bottom: 10px; display: flex; align-items: center; gap: 8px;">
                                    <img src="images/ChimNSFWsoulgem.png" style="width: 20px; height: 20px;"> Aftermath
                                </h4>
                                <p style="color: #7766AA; font-size: 10px; margin-bottom: 10px;">How this slave reflects and responds after the scene ends</p>
                                <div class="form-group">
                                    <textarea id="npcSlaveAftermath" class="auto-resize" style="min-height: 48px; width: 100%; resize: none; overflow: hidden; background: #1C1A24; border: 1px solid #3A3545; color: #B8A8D0; padding: 8px; border-radius: 5px; font-size: 14px;" placeholder="Leave blank for global default..."></textarea>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Promiscuous (Slut) Checkbox Section: third role mark beside prostitute and slave.
                         No sub-options: behavior comes from the Promiscuous Relationship Overhead prompt set. -->
                    <div class="slut-section" style="background: #2A2233; border: 2px solid #4A3545; border-radius: 8px; padding: 10px 15px; margin-bottom: 15px;">
                        <label style="display: flex; align-items: center; gap: 10px; cursor: pointer; margin: 0;">
                            <input type="checkbox" id="isSlut" onchange="if(this.checked){var p=document.getElementById('isProstitute'); if(p){p.checked=false; toggleProstitutePricing();} var s=document.getElementById('isSlave'); if(s){s.checked=false; toggleSlaveOptions();}}">
                            <span class="gold-glow-text">This NPC is promiscuous (slut)</span>
                        </label>
                        <p style="color: #886666; font-size: 10px; margin: 5px 0 0 30px; font-style: italic;">
                            Sexually available from Acquainted (+6) affinity and up, no relationship-type requirement, no affair floor. Below Acquainted they still refuse. Uses the Promiscuous Relationship Overhead prompts (Prompts tab). Free of charge, unlike a prostitute.
                        </p>
                    </div>

                    <div class="button-group" style="display: flex; gap: 10px;">
                        <button class="btn-primary npc-action-btn" onclick="saveNpcSettings()">Save NPC Settings</button>
                        <button class="btn-danger npc-action-btn" onclick="deleteNpcSettings()">Delete Settings</button>
                        <button class="btn-secondary npc-action-btn" onclick="closeNpcSettings()">Clear Form</button>
                    </div>
                </div>
            </div>

            <!-- Manage Global Speak Styles Section -->
            <div class="global-styles-card" style="background: #252233; border: 1px solid #3A3545; border-radius: 10px; padding: 12px; margin-top: 15px;">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 3px;">
                    <div>
                        <h3 class="section-header" style="margin: 0; display: flex; align-items: center; gap: 10px;">
                            <img src="images/ChimNSFWsoulgem.png" style="width: 32px; height: 32px; vertical-align: middle; position: relative; top: -5px;"> Manage Global Speak Styles
                        </h3>
                        <p class="legend" style="margin: 0; padding: 0; color: #B8A8D0;">Edit global style definitions. Changes here affect ALL NPCs using that style. Use <code>#PRIMARY_PARTNER#</code> for whoever the speaker is addressing in the active scene.</p>
                    </div>
                    <button class="create-style-btn" onclick="openCreateStyleModal()" style="padding: 10px 20px; border-radius: 5px; cursor: pointer;">Create New Style</button>
                </div>
                <div class="table-wrapper" style="background: #1C1A24; border-radius: 8px; overflow: hidden; margin-top: 8px;">
                    <table id="globalStylesTable" style="width: 100%; border-collapse: collapse;">
                        <thead>
                            <tr style="background: #252233;">
                                <th style="padding: 12px; border-bottom: 2px solid #FDF5D0;
            text-align: center;
             width: 50px;">Icon</th>
                                <th style="padding: 12px; border-bottom: 2px solid #FDF5D0;
            text-align: center;
             width: 120px;">Style</th>
                                <th style="padding: 12px; text-align: center;">Description</th>
                                <th style="padding: 12px; text-align: center; width: 80px;">Type</th>
                                <th style="padding: 12px; text-align: center; width: 80px;">Actions</th>
                            </tr>
                        </thead>
                        <tbody id="globalStylesTableBody">
                            <!-- Populated by JavaScript -->
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Configured NPCs Section -->
            <div class="configured-npcs-card" style="background: #252233; border: 1px solid #3A3545; border-radius: 10px; padding: 12px; margin-top: 15px;">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px;">
                    <div>
                        <h3 class="section-header" style="margin: 0; display: flex; align-items: center; gap: 10px;">
                            <img src="images/ChimNSFWsoulgem.png" style="width: 32px; height: 32px; vertical-align: middle; position: relative; top: -5px;"> NPCs with Assigned NSFW Settings
                        </h3>
                        <p class="legend" style="margin: 0; padding: 0; color: #B8A8D0;">Click Edit to modify or use search to find a specific NPC.<br>Click an NPC name to prioritize them at the top of the list.</p>
                    </div>
                    <div style="position: relative; align-self: center;">
                        <input type="text" id="configuredNpcsSearch" placeholder="Search NPCs..."
                               style="width: 250px; padding: 8px 12px; background: #1C1A24; border: 1px solid #3A3545; color: #B8A8D0; border-radius: 5px; font-size: 14px;"
                               oninput="showConfiguredNpcsAutocomplete(this.value)"
                               onfocus="showConfiguredNpcsAutocomplete(this.value)"
                               autocomplete="off">
                        <div id="configuredNpcsAutocomplete" class="autocomplete-dropdown" style="display: none; position: absolute; top: 100%; left: 0; right: 0; max-height: 200px; overflow-y: auto; background: #1C1A24; border: 1px solid #3A3545; border-top: none; border-radius: 0 0 5px 5px; z-index: 1000;"></div>
                    </div>
                </div>
                <div class="table-wrapper" style="background: #1C1A24; border-radius: 8px; overflow: hidden;">
                    <table id="configuredNpcsTable" style="width: 100%; border-collapse: collapse;">
                        <thead>
                            <tr style="background: #252233;">
                                <th style="padding: 12px; text-align: left;">NPC Name</th>
                                <th style="padding: 12px; text-align: left;">Speak Style</th>
                                <th style="padding: 12px; text-align: left;">Style Description</th>
                                <th style="padding: 12px; text-align: center;">Kinks</th>
                                <th style="padding: 12px; text-align: center;">Profanity</th>
                                <th style="padding: 12px; text-align: center;">Source</th>
                                <th style="padding: 12px; text-align: center;">Actions</th>
                            </tr>
                        </thead>
                        <tbody id="configuredNpcsTableBody">
                            <!-- Populated by JavaScript -->
                        </tbody>
                    </table>
                </div>
                <!-- NPC Table Pagination -->
                <div id="npcPaginationContainer" style="display: none; margin-top: 15px;">
                    <div class="pagination" id="npcPaginationControls"></div>
                    <div class="pagination-info" id="npcPaginationInfo"></div>
                </div>
            </div>
        </div>
