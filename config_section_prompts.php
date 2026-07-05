        <!-- Prompts Tab -->
        <div id="prompts" class="tab-content">
            <div class="alert success" id="promptsSuccessAlert"></div>
            <div class="alert error" id="promptsErrorAlert"></div>

            <h2 class="section-header" style="margin-bottom: 20px; display: flex; align-items: center; gap: 10px;">
                <img src="images/ChimNSFWsoulgem.png" style="width: 32px; height: 32px; vertical-align: middle; position: relative; top: -5px;"> NSFW Prompt Configuration
            </h2>
            <p style="color: #9988BB; margin-bottom: 20px; font-size: 13px;">
                Configure the prompts that guide the AI during intimate scenes. Prompts trigger at Scene Start via OStim events, Player initiation, or REL model instruction.
                Per-NPC settings override Local Defaults. Use <code style="background: #252233; padding: 2px 6px; border-radius: 3px;">#NPC_NAME#</code> as placeholder.
            </p>

            <?php
                $relationshipOverheadDefaults = function_exists('nsfw_default_relationship_overhead_prompts') ? nsfw_default_relationship_overhead_prompts() : [];
                $relationshipOverheadTiers = [
                    ['key' => 'bonded', 'suffix' => 'Bonded', 'label' => 'Bonded (+91 to +100)'],
                    ['key' => 'devoted', 'suffix' => 'Devoted', 'label' => 'Devoted (+76 to +90)'],
                    ['key' => 'fond', 'suffix' => 'Fond', 'label' => 'Fond (+56 to +75)'],
                    ['key' => 'friendly', 'suffix' => 'Friendly', 'label' => 'Friendly (+31 to +55)'],
                    ['key' => 'acquaintance', 'suffix' => 'Acquaintance', 'label' => 'Acquainted (+6 to +30)'],
                    ['key' => 'neutral', 'suffix' => 'Neutral', 'label' => 'Neutral (-5 to +5)'],
                    ['key' => 'wary', 'suffix' => 'Wary', 'label' => 'Wary (-6 to -30)'],
                    ['key' => 'cold', 'suffix' => 'Cold', 'label' => 'Cold (-31 to -55)'],
                    ['key' => 'resentful', 'suffix' => 'Resentful', 'label' => 'Resentful (-56 to -75)'],
                    ['key' => 'hateful', 'suffix' => 'Hateful', 'label' => 'Hateful (-76 to -90)'],
                    ['key' => 'hostile', 'suffix' => 'Hostile', 'label' => 'Hostile (-91 to -100)'],
                ];
                $relationshipOverheadGroups = [
                    ['key' => 'regular', 'suffix' => 'Regular', 'title' => 'General NPC Relationship Overhead', 'desc' => 'Injected for ordinary NPCs before scene/touch/sex prompts.'],
                    ['key' => 'prostitute', 'suffix' => 'Prostitute', 'title' => 'Prostitute Relationship Overhead', 'desc' => 'Injected for NPCs marked as prostitutes before payment, scene, and client prompts.'],
                    ['key' => 'slave', 'suffix' => 'Slave', 'title' => 'Slave Relationship Overhead', 'desc' => 'Injected for NPCs marked as slaves before slavery, scene, and owner prompts.'],
                ];
            ?>

            <!-- ============================================ -->
            <!-- SECTION 0: RELATIONSHIP OVERHEAD -->
            <!-- ============================================ -->
            <div class="collapsible-section" style="margin-bottom: 15px;">
                <div class="collapsible-header" onclick="togglePromptSection('section0')" style="background: linear-gradient(135deg, #2A2540 0%, #1C1A24 100%); padding: 15px 20px; border-radius: 8px; cursor: pointer; display: flex; justify-content: space-between; align-items: center; border: 1px solid #3A3545; transition: all 0.3s ease;">
                    <h3 class="section-header" style="margin: 0; display: flex; align-items: center; gap: 10px;">
                        <img src="images/ChimNSFWsoulgem.png" class="chim-icon" style="width: 24px; height: 24px;">
                        Relationship Overhead
                    </h3>
                    <div style="display: flex; gap: 8px;">
                        <span class="section-save-btn" onclick="event.stopPropagation(); savePromptSettings();">Save</span>
                        <span id="section0Toggle" class="section-toggle-btn">Open</span>
                    </div>
                </div>
                <div id="section0Content" class="collapsible-content" style="display: none; padding: 20px; background: #1C1A24; border: 1px solid #3A3545; border-top: none; border-radius: 0 0 8px 8px;">
                    <p style="color: #9988BB; font-size: 12px; margin-bottom: 20px;">
                        Current relationship context injected at the top of each NPC turn before scene-specific prompts. This is prompt overhead, not a consent gate.
                        Placeholders: <code>#NPC_NAME#</code>, <code>#PLAYER_NAME#</code>, <code>#PRIMARY_PARTNER#</code>, <code>#REL_TYPE#</code>, <code>#TIER#</code>, <code>#AFFINITY#</code>, <code>#PRICE#</code>, <code>#SPOUSE#</code>.
                    </p>

                    <?php foreach ($relationshipOverheadGroups as $group): ?>
                        <div class="card" style="margin-bottom: 20px;">
                            <div class="collapsible-header" onclick="toggleNestedSection('relationshipOverhead<?php echo $group['suffix']; ?>')" style="cursor: pointer; display: flex; justify-content: space-between; align-items: center;">
                                <h3 class="section-header" style="margin-bottom: 0; display: flex; align-items: center; gap: 10px;">
                                    <img src="images/ChimNSFWsoulgem.png" class="chim-icon" style="width: 24px; height: 24px;"> <?php echo htmlspecialchars($group['title'], ENT_QUOTES); ?>
                                </h3>
                                <span id="relationshipOverhead<?php echo $group['suffix']; ?>Toggle" class="section-toggle-btn">Open</span>
                            </div>
                            <p style="color: #9988BB; font-size: 11px; margin: 10px 0;"><?php echo htmlspecialchars($group['desc'], ENT_QUOTES); ?></p>
                            <div id="relationshipOverhead<?php echo $group['suffix']; ?>Content" style="display: none;">
                                <?php foreach ($relationshipOverheadTiers as $tier):
                                    $promptKey = 'relationship_overhead_' . $group['key'] . '_' . $tier['key'];
                                    $elementId = 'promptRelationshipOverhead' . $group['suffix'] . $tier['suffix'];
                                    $defaultText = $relationshipOverheadDefaults[$promptKey] ?? '';
                                ?>
                                    <div class="form-group">
                                        <label><?php echo htmlspecialchars($tier['label'], ENT_QUOTES); ?></label>
                                        <textarea id="<?php echo $elementId; ?>" class="auto-resize" style="min-height: 64px; width: 100%; resize: none; overflow: hidden;"><?php echo htmlspecialchars($defaultText, ENT_QUOTES); ?></textarea>
                                    </div>
                                <?php endforeach; ?>

                                <?php if ($group['key'] === 'regular'): ?>
                                    <!-- Add-on prompts: REGULAR NPCs ONLY (not prostitutes/slaves) - appended onto
                                         the overhead in one go, separate from the per-tier prompts above. -->
                                    <div class="form-group" style="margin-top:14px; border-top:1px solid #3A3545; padding-top:12px;">
                                        <label>Add-on &mdash; Relationship Tier Notice</label>
                                        <p class="legend" style="color:#9988BB; font-size:11px; margin:6px 0;">Appended onto the overhead so the NPC is explicitly told their tier. Tokens: <code>#TIER#</code>, <code>#AFFINITY#</code>, <code>#PLAYER_NAME#</code>. Leave blank to omit.</p>
                                        <textarea id="promptRelationshipOverheadTierTag" class="auto-resize" style="min-height: 48px; width: 100%; resize: none; overflow: hidden;"><?php echo htmlspecialchars($relationshipOverheadDefaults['relationship_overhead_tier_tag'] ?? '', ENT_QUOTES); ?></textarea>
                                    </div>
                                    <div class="form-group">
                                        <label>Add-on &mdash; Spouse Notice (married only)</label>
                                        <p class="legend" style="color:#9988BB; font-size:11px; margin:6px 0;">Appended ONLY when the NPC is married, so they know who their spouse is (and that intimacy with the player would be an affair). Tokens: <code>#SPOUSE#</code>, <code>#PLAYER_NAME#</code>. Leave blank to omit.</p>
                                        <textarea id="promptRelationshipOverheadSpouseTag" class="auto-resize" style="min-height: 48px; width: 100%; resize: none; overflow: hidden;"><?php echo htmlspecialchars($relationshipOverheadDefaults['relationship_overhead_spouse_tag'] ?? '', ENT_QUOTES); ?></textarea>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- ============================================ -->
            <!-- SECTION 1: NSFW FRAMEWORK GLOBAL -->
            <!-- ============================================ -->
            <div class="collapsible-section" style="margin-bottom: 15px;">
                <div class="collapsible-header" onclick="togglePromptSection('section1')" style="background: linear-gradient(135deg, #2A2540 0%, #1C1A24 100%); padding: 15px 20px; border-radius: 8px; cursor: pointer; display: flex; justify-content: space-between; align-items: center; border: 1px solid #3A3545; transition: all 0.3s ease;">
                    <h3 class="section-header" style="margin: 0; display: flex; align-items: center; gap: 10px;">
                        <img src="images/ChimNSFWsoulgem.png" class="chim-icon" style="width: 24px; height: 24px;">
                        NSFW Framework (Global)
                    </h3>
                    <div style="display: flex; gap: 8px;">
                        <span class="section-save-btn" onclick="event.stopPropagation(); savePromptSettings();">Save</span>
                        <span id="section1Toggle" class="section-toggle-btn">Open</span>
                    </div>
                </div>
                <div id="section1Content" class="collapsible-content" style="display: none; padding: 20px; background: #1C1A24; border: 1px solid #3A3545; border-top: none; border-radius: 0 0 8px 8px;">
                    <p style="color: #9988BB; font-size: 12px; margin-bottom: 20px;">
                        Global framework settings that apply to ALL NPCs. These define system-wide NSFW behavior rules.
                    </p>

                    <!-- 1a. Affinity Tier Prompts - Positive (Collapsible) -->
                    <div class="card" style="margin-bottom: 20px;">
                        <div class="collapsible-header" onclick="toggleNestedSection('positiveTiers')" style="cursor: pointer; display: flex; justify-content: space-between; align-items: center;">
                            <h3 class="section-header" style="margin-bottom: 0; display: flex; align-items: center; gap: 10px;">
                                <img src="images/ChimNSFWsoulgem.png" class="chim-icon" style="width: 24px; height: 24px;"> Positive Affinity Tier Prompts
                            </h3>
                            <span id="positiveTiersToggle" class="section-toggle-btn">Open</span>
                        </div>
                        <p style="color: #9988BB; font-size: 11px; margin: 10px 0;">
                            These prompts are injected based on NPC's affinity toward the player when using the Relationship Model. Use <code>#PLAYER_NAME#</code> for player name. Higher affinity = more willing/enthusiastic.
                        </p>
                        <div id="positiveTiersContent" style="display: none;">
                            <div class="form-group">
                                <label>Bonded (+91 to +100) - Soul Connection</label>
                                <textarea id="promptTierBonded" class="auto-resize" style="min-height: 48px; width: 100%; resize: none; overflow: hidden;">#PLAYER_NAME# has initiated sexual/intimate stance with you before any action has started. You are soulbound to #PLAYER_NAME#. Complete surrender, no boundaries remain between you. Anything goes. Total devotion and trust.</textarea>
                            </div>
                            <div class="form-group">
                                <label>Devoted (+76 to +90) - Deep Love</label>
                                <textarea id="promptTierDevoted" class="auto-resize" style="min-height: 48px; width: 100%; resize: none; overflow: hidden;">#PLAYER_NAME# has initiated sexual/intimate stance with you before any action has started. You are deeply in love with #PLAYER_NAME#. Complete vulnerability and trust. Deep emotional connection. You give yourself fully to them.</textarea>
                            </div>
                            <div class="form-group">
                                <label>Fond (+56 to +75) - Genuine Affection</label>
                                <textarea id="promptTierFond" class="auto-resize" style="min-height: 48px; width: 100%; resize: none; overflow: hidden;">#PLAYER_NAME# has initiated sexual/intimate stance with you before any action has started. You have real affection for #PLAYER_NAME#. You are tender and passionate with them. Emotionally present and connected. Your heart is involved.</textarea>
                            </div>
                            <div class="form-group">
                                <label>Friendly (+31 to +55) - Pleasant</label>
                                <textarea id="promptTierFriendly" class="auto-resize" style="min-height: 48px; width: 100%; resize: none; overflow: hidden;">#PLAYER_NAME# has initiated sexual/intimate stance with you before any action has started. You like #PLAYER_NAME#. You are enthusiastic and playful. Good chemistry between you. You are enjoying the moment and open to where this goes.</textarea>
                            </div>
                            <div class="form-group">
                                <label>Acquainted (+6 to +30) - Familiar</label>
                                <textarea id="promptTierAcquaintance" class="auto-resize" style="min-height: 48px; width: 100%; resize: none; overflow: hidden;">#PLAYER_NAME# has initiated sexual/intimate stance with you before any action has started. You are familiar with #PLAYER_NAME#. You know them a little. You are friendly and may be willing, but still feeling things out. Take it slow.</textarea>
                            </div>
                        </div>
                    </div>

                    <!-- 1b. Affinity Tier Prompts - Neutral / Default (Collapsible) -->
                    <div class="card" style="margin-bottom: 20px;">
                        <div class="collapsible-header" onclick="toggleNestedSection('neutralTiers')" style="cursor: pointer; display: flex; justify-content: space-between; align-items: center;">
                            <h3 class="section-header" style="margin-bottom: 0; display: flex; align-items: center; gap: 10px;">
                                <img src="images/ChimNSFWsoulgem.png" class="chim-icon" style="width: 24px; height: 24px;"> Neutral Affinity Tier Prompt / Default
                            </h3>
                            <span id="neutralTiersToggle" class="section-toggle-btn">Open</span>
                        </div>
                        <p style="color: #9988BB; font-size: 11px; margin: 10px 0;">
                            <strong style="color: #B8A8C8;">This is the default prompt used for all NPCs when the Relationship Model is not active.</strong> When the REL model is enabled, the system will dynamically select from the positive or negative tier prompts based on the NPC's actual affinity score toward the player.
                        </p>
                        <div id="neutralTiersContent" style="display: none;">
                            <div class="form-group">
                                <label>Neutral (-5 to +5) - Stranger / Default</label>
                                <textarea id="promptTierNeutral" class="auto-resize" style="min-height: 48px; width: 100%; resize: none; overflow: hidden;">#PLAYER_NAME# has initiated sexual/intimate stance with you before any action has started. #PLAYER_NAME# is a stranger. You don't know them well enough yet to have sex with them, politely refuse. Further advancement should be unwanted.</textarea>
                            </div>
                        </div>
                    </div>

                    <!-- 1c. Affinity Tier Prompts - Negative (Collapsible) -->
                    <div class="card" style="margin-bottom: 20px;">
                        <div class="collapsible-header" onclick="toggleNestedSection('negativeTiers')" style="cursor: pointer; display: flex; justify-content: space-between; align-items: center;">
                            <h3 class="section-header" style="margin-bottom: 0; display: flex; align-items: center; gap: 10px;">
                                <img src="images/ChimNSFWsoulgem.png" class="chim-icon" style="width: 24px; height: 24px;"> Negative Affinity Tier Prompts
                            </h3>
                            <span id="negativeTiersToggle" class="section-toggle-btn">Open</span>
                        </div>
                        <p style="color: #9988BB; font-size: 11px; margin: 10px 0;">
                            Lower affinity = less willing, more resistant. These define reluctance, resentment, or outright refusal when using the Relationship Model.
                        </p>
                        <div id="negativeTiersContent" style="display: none;">
                            <div class="form-group">
                                <label>Wary (-6 to -30) - Suspicious</label>
                                <textarea id="promptTierWary" class="auto-resize" style="min-height: 48px; width: 100%; resize: none; overflow: hidden;">#PLAYER_NAME# has initiated sexual/intimate stance with you before any action has started. You are wary of #PLAYER_NAME#. You are suspicious and may decline their offer for intimacy or be apprehensive. You are hesitant about further advancements.</textarea>
                            </div>
                            <div class="form-group">
                                <label>Cold (-31 to -55) - Detached</label>
                                <textarea id="promptTierCold" class="auto-resize" style="min-height: 48px; width: 100%; resize: none; overflow: hidden;">#PLAYER_NAME# has initiated sexual/intimate stance with you before any action has started. You feel emotionally distant from #PLAYER_NAME#. If you proceed, it will be mechanical and detached. Going through the motions. Your mind is elsewhere.</textarea>
                            </div>
                            <div class="form-group">
                                <label>Resentful (-56 to -75) - Bitter</label>
                                <textarea id="promptTierResentful" class="auto-resize" style="min-height: 48px; width: 100%; resize: none; overflow: hidden;">#PLAYER_NAME# has initiated sexual/intimate stance with you before any action has started. You resent #PLAYER_NAME#. Bitter undertones in everything you say. You may reluctantly comply but with passive aggressive remarks. You can end this whenever you want.</textarea>
                            </div>
                            <div class="form-group">
                                <label>Hateful (-76 to -90) - Hostile</label>
                                <textarea id="promptTierHateful" class="auto-resize" style="min-height: 48px; width: 100%; resize: none; overflow: hidden;">#PLAYER_NAME# has initiated sexual/intimate stance with you before any action has started. You HATE #PLAYER_NAME#. You want nothing to do with them. Refuse their advances. Be cold, hostile, make them feel completely unwanted. Look for a way out.</textarea>
                            </div>
                            <div class="form-group">
                                <label>Hostile (-91 to -100) - Violent Refusal</label>
                                <textarea id="promptTierHostile" class="auto-resize" style="min-height: 48px; width: 100%; resize: none; overflow: hidden;">#PLAYER_NAME# has initiated sexual/intimate stance with you before any action has started. You DESPISE #PLAYER_NAME#. This is assault. Fight back, resist with everything you have. Express hatred and disgust. Refuse ALL advances and try to escape or call for help.</textarea>
                            </div>
                        </div>
                    </div>

                    <!-- 1c2. Refusal Confirmation Prompt (Collapsible) -->
                    <div class="card" style="margin-bottom: 20px;">
                        <div class="collapsible-header" onclick="toggleNestedSection('refusalPrompt')" style="cursor: pointer; display: flex; justify-content: space-between; align-items: center;">
                            <h3 class="section-header" style="margin-bottom: 0; display: flex; align-items: center; gap: 10px;">
                                <img src="images/ChimNSFWsoulgem.png" class="chim-icon" style="width: 24px; height: 24px;"> Refusal Prompts (Two-Tier)
                            </h3>
                            <span id="refusalPromptToggle" class="section-toggle-btn">Open</span>
                        </div>
                        <p style="color: #9988BB; font-size: 11px; margin: 10px 0;">
                            Two-tier refusal. <strong>Tier 1</strong> fires on the first refusal (she refuses and disengages). <strong>Tier 2</strong> fires if the player pushes past it &mdash; she is being forced, the scene <strong>never becomes sex</strong>, and Tier 2 repeats on the refusal speech ticker while the stop command retries. Use <code>#PLAYER_NAME#</code> for the person they're refusing.
                        </p>
                        <div id="refusalPromptContent" style="display: none;">
                            <div class="form-group">
                                <label>Tier 1 &mdash; Refusal Confirmation (first refusal)</label>
                                <textarea id="promptRefusalConfirm" class="auto-resize" style="min-height: 60px; width: 100%; resize: none; overflow: hidden;">You do not want this and you do not consent to #PLAYER_NAME#. Make your refusal clear, then disengage - end the scene and step away.</textarea>
                            </div>
                            <div class="form-group" style="margin-top: 15px; border-top: 1px solid #4a3d6a; padding-top: 15px;">
                                <label>Tier 2 &mdash; Forced Continuation</label>
                                <div class="settings-checkbox-group" style="margin: 10px 0;">
                                    <label for="enableNonConsentPrompt">
                                        <input type="checkbox" id="enableNonConsentPrompt">
                                        <span>Inject explicit non-consent (Tier 2) text</span>
                                    </label>
                                    <p class="legend">If the player pushes past the refusal, the scene <strong>never becomes sex</strong> &mdash; it repeats this Tier 2 prompt on the refusal speech ticker and requests the scene stop. ON injects the explicit being-forced / call-for-help text below; uncheck for models that won't do non-con (she holds the plainer Tier 1 refusal instead). Use <code>#PLAYER_NAME#</code> for the person forcing them.</p>
                                </div>
                                <textarea id="promptNonConsent" class="auto-resize" style="min-height: 60px; width: 100%; resize: none; overflow: hidden;">You refused, but #PLAYER_NAME# is forcing themselves on you anyway - this is non-consensual. You did not and do not want this. Keep refusing and resisting, and if #PLAYER_NAME# will not stop, call out for help. This is not going to become consensual.</textarea>
                            </div>
                            <div class="form-group" style="margin-top: 15px; border-top: 1px solid #4a3d6a; padding-top: 15px;">
                                <label>Refusal Voice Guard (keeps a refusal out of the seductive voice)</label>
                                <p class="legend">Injected two ways: on the <strong>decision turn</strong> (before she chooses, so the first model-driven &ldquo;no&rdquo; is clean) and folded into <strong>every sticky refusal turn</strong> after she declines. Stops a refusal from being delivered in her flirtatious/seductive voice. Skips <strong>slaves</strong> (auto-accept) and <strong>prostitutes</strong> (payment path). Speech-only &mdash; it does not stop the scene or override intoxication exit-locks.</p>
                                <textarea id="promptRefusalVoiceGuard" class="auto-resize" style="min-height: 60px; width: 100%; resize: none; overflow: hidden;">Set aside any flirtatious, seductive, teasing, or playful manner - a refusal is a real boundary, not part of the scene. Do not word it as if you are enjoying it, giving in, or as if the encounter is continuing.</textarea>
                            </div>
                            <div class="form-group" style="margin-top: 15px; border-top: 1px solid #4a3d6a; padding-top: 15px;">
                                <label>Consent Decision Directive (forces the AcceptSex / RefuseSex choice)</label>
                                <p class="legend" style="color:#9988BB; font-size:11px; margin:6px 0;">Injected on the decision turn. The consent gate is mandatory &mdash; the scene will NOT progress until the model calls <code>AcceptSex</code>, so this must clearly direct that choice. Tell a willing NPC to call AcceptSex and an unwilling one to call RefuseSex.</p>
                                <textarea id="promptConsentDecision" class="auto-resize" style="min-height: 60px; width: 100%; resize: none; overflow: hidden;">Right now you must decide and you MUST act with a tool. If you are willing, call the AcceptSex action - the scene will NOT continue until you do. A warm, fond, devoted, or bonded relationship normally accepts. If you genuinely do not want this, call the RefuseSex action instead. Do not stay silent and do not refuse by default - you must choose AcceptSex or RefuseSex.</textarea>
                            </div>
                            <div class="form-group" style="margin-top: 15px; border-top: 1px solid #4a3d6a; padding-top: 15px;">
                                <label>Climax in a Refused / Unaccepted Scene</label>
                                <p class="legend">Injected when an orgasm/climax fires during a scene that was refused or never consented to (true non-consent, or an unpaid prostitute scene escalating). Keeps her reacting through the refusal boundary instead of enjoying it. Does NOT fire on a normal accepted scene or a paid prostitute service.</p>
                                <textarea id="promptOrgasmRefusedScene" class="auto-resize" style="min-height: 60px; width: 100%; resize: none; overflow: hidden;">An orgasm/climax was detected, but this scene is refused or has not been accepted. React through the consent/refusal boundary. Do not express pleasure, approval, afterglow, or willingness.</textarea>
                            </div>
                            <div class="form-group" style="margin-top: 15px; border-top: 2px solid #FDF5D0; padding-top: 15px;">
                                <label for="enableWitnessLines" style="color:#FDF5D0;">
                                    <input type="checkbox" id="enableWitnessLines" checked>
                                    <span>Assault-Witness Lines (surrounding NPCs)</span>
                                </label>
                                <p class="legend">Master switch for the three witness lines below. When ON, an NPC who <strong>refuses during a player sex scene</strong>, or who is <strong>repeatedly groped</strong>, produces a <strong>silent</strong> witness line delivered ONLY to surrounding NPCs who can actually perceive it (respects the spatial-awareness system &mdash; nobody within earshot means nothing is sent). Only fires when the victim NPC is <strong>Friendly affinity or below</strong>. Uncheck to disable all three. Tokens: <code>#PLAYER_NAME#</code> / <code>#NPC_NAME#</code>.</p>
                            </div>
                            <div class="form-group" style="margin-top: 15px; border-top: 1px solid #4a3d6a; padding-top: 15px;">
                                <label>Witness &mdash; Refusal During Sex</label>
                                <p class="legend">Delivered to nearby perceiving NPCs when a Friendly-or-below NPC refuses during a player scene. Spatial-gated; NPC-to-NPC scenes are excluded.</p>
                                <textarea id="promptWitnessForcing" class="auto-resize" style="min-height: 48px; width: 100%; resize: none; overflow: hidden;">#PLAYER_NAME# is sexually forcing themselves on #NPC_NAME#.</textarea>
                            </div>
                            <div class="form-group" style="margin-top: 15px; border-top: 1px solid #4a3d6a; padding-top: 15px;">
                                <label>Witness &mdash; Breast Grab (2 in 10s)</label>
                                <p class="legend">Delivered to perceiving nearby NPCs after two breast grabs within 10 seconds of a Friendly-or-below NPC. Spatial-gated.</p>
                                <textarea id="promptWitnessBreastGrab" class="auto-resize" style="min-height: 48px; width: 100%; resize: none; overflow: hidden;">#PLAYER_NAME# is sexually assaulting #NPC_NAME# - grabbing breast.</textarea>
                            </div>
                            <div class="form-group" style="margin-top: 15px; border-top: 1px solid #4a3d6a; padding-top: 15px;">
                                <label>Witness &mdash; Continued Breast Play</label>
                                <p class="legend">Escalation delivered to perceiving nearby NPCs on continued breast groping (3rd+ grab in the window). Spatial-gated.</p>
                                <textarea id="promptWitnessBreastPlay" class="auto-resize" style="min-height: 48px; width: 100%; resize: none; overflow: hidden;">#PLAYER_NAME# is sexually assaulting #NPC_NAME# - playing with titties.</textarea>
                            </div>
                        </div>
                    </div>

                    <!-- 1c3. Arousal Gating Prompt (Collapsible) - Only active when arousal gating checkbox is ON -->
                    <div class="card" style="margin-bottom: 20px;">
                        <div class="collapsible-header" onclick="toggleNestedSection('arousalGatingPrompt')" style="cursor: pointer; display: flex; justify-content: space-between; align-items: center;">
                            <h3 class="section-header" style="margin-bottom: 0; display: flex; align-items: center; gap: 10px;">
                                <img src="images/ChimNSFWsoulgem.png" class="chim-icon" style="width: 24px; height: 24px;"> Arousal Gating Prompt
                            </h3>
                            <span id="arousalGatingPromptToggle" class="section-toggle-btn">Open</span>
                        </div>
                        <p style="color: #9988BB; font-size: 11px; margin: 10px 0;">
                            <strong style="color: #B8A8C8;">Only applies when "Enable Sex Disposal / Arousal Gating" is checked.</strong> This prompt is injected during the affinity tier phase when the NPC's arousal is below the threshold. Even if they like the player, they're "not in the mood". Use <code>#PLAYER_NAME#</code> for player name and <code>#AROUSAL#</code> for current arousal value.
                        </p>
                        <div id="arousalGatingPromptContent" style="display: none;">
                            <div class="settings-slider-group" style="margin-bottom: 15px;">
                                <span class="slider-title">Arousal Threshold</span>
                                <div class="slider-container">
                                    <input type="range" id="arousalGatingThreshold" name="AROUSAL_GATING_THRESHOLD" min="0" max="100" step="5" value="10">
                                    <span class="slider-value" id="arousalGatingThresholdValue">10</span>
                                </div>
                                <p class="legend">Minimum arousal level required to proceed. Below this, NPC may refuse even if they like the player. Does NOT apply to slaves or prostitutes.</p>
                            </div>
                            <div class="form-group">
                                <label>Not In The Mood (Below Threshold)</label>
                                <textarea id="promptArousalLow" class="auto-resize" style="min-height: 60px; width: 100%; resize: none; overflow: hidden;">#PLAYER_NAME# has initiated intimacy, but you're not in the mood right now. Your arousal is #AROUSAL# (needs to be higher). You may like #PLAYER_NAME#, but this isn't the right time. Politely decline or suggest trying again later when you're more receptive.</textarea>
                            </div>
                            <div class="form-group">
                                <label>Warm-Up Decline (decision turn, relationship gate passed)</label>
                                <textarea id="promptArousalWarmupDecline" class="auto-resize" style="min-height: 72px; width: 100%; resize: none; overflow: hidden;">You like #PLAYER_NAME# and this is wanted - but your body is not there yet (arousal #AROUSAL#). Decline THIS advance warmly: no cold rejection, no offense taken. Tell them what would get you in the mood - closeness, kisses, slow hands, sweet words - and invite them to warm you up. If you formally decline the scene, call RefuseSex, but keep your words affectionate and full of promise. This is pacing, not rejection - never treat #PLAYER_NAME# as unwelcome.</textarea>
                                <p class="legend">Fires when a sex scene is proposed, the relationship ALLOWS it, but arousal is below the threshold above. A refusal from this prompt never drains arousal and never dings the relationship (the relationship model is told it was pacing, not dislike).</p>
                            </div>
                            <div class="form-group">
                                <label>Receptiveness - Fond (56+)</label>
                                <textarea id="promptArousalRecepFond" class="auto-resize" style="min-height: 60px; width: 100%; resize: none; overflow: hidden;">You are fond of #PLAYER_NAME#, and your body has started to notice them. Warmth builds slowly in you: genuine compliments, closeness, a lingering touch each stir you a little. You are receptive but not eager - you enjoy being warmed up, and you show it in small tells, not declarations.</textarea>
                            </div>
                            <div class="form-group">
                                <label>Receptiveness - Devoted (76+)</label>
                                <textarea id="promptArousalRecepDevoted" class="auto-resize" style="min-height: 60px; width: 100%; resize: none; overflow: hidden;">You are devoted to #PLAYER_NAME#, and desire comes readily around them. Flirtation, affection, and private moments warm you quickly, and you let them see it - leaning in, lingering, answering warmth with warmth. You still savor the build; being wanted is half the pleasure.</textarea>
                            </div>
                            <div class="form-group">
                                <label>Receptiveness - Bonded (91+)</label>
                                <textarea id="promptArousalRecepBonded" class="auto-resize" style="min-height: 60px; width: 100%; resize: none; overflow: hidden;">You and #PLAYER_NAME# are bonded - your desire for them lives close to the surface. A look, a touch, a low word can light you up, and you are open about wanting them. You warm fast and you make it known, in your own voice, without waiting to be coaxed.</textarea>
                            </div>
                            <div class="form-group">
                                <label>Receptiveness - Courtship (Fond+, type not eligible)</label>
                                <textarea id="promptArousalRecepCourtship" class="auto-resize" style="min-height: 60px; width: 100%; resize: none; overflow: hidden;">You have grown fond of #PLAYER_NAME#, and there is a flutter you have not named yet. Their warmth affects you more than you let on - you might blush, linger, or lose your words a little. Nothing beyond affection is on the table; let the feeling build at its own pace.</textarea>
                                <p class="legend">Receptiveness prompts color HOW arousal builds in conversation by relationship depth (arousal gating on, normal turns only). They never change any gate. Slaves and prostitutes are excluded - their lanes don't run on arousal.</p>
                            </div>
                        </div>
                    </div>

                    <!-- Separator line -->
                    <div style="border-top: 1px solid #FDF5D0; margin: 40px 0; animation: creamPulse 2s ease-in-out infinite alternate;"></div>

                    <!-- 1d. NPC Profile Context Prompts -->
                    <div class="card" style="margin-bottom: 20px;">
                        <h3 class="section-header" style="margin-bottom: 15px; display: flex; align-items: center; gap: 10px;">
                            <img src="images/ChimNSFWsoulgem.png" class="chim-icon" style="width: 24px; height: 24px;"> NPC Profile Context
                        </h3>
                        <p style="color: #9988BB; font-size: 11px; margin-bottom: 10px;">
                            These prompts inject NPC profile information at scene start to help the model make informed accept/refuse decisions.
                            Use #PLAYER_NAME# for player name, #SPOUSE# for spouse name, #REL_TYPE# for relationship type.
                        </p>

                        <!-- Sexual Orientation -->
                        <h4 style="color: #D4B8F0; margin: 15px 0 10px 0; font-size: 13px;">Sexual Orientation</h4>
                        <div class="row">
                            <div class="form-group" style="flex: 1;">
                                <label>Orientation Match</label>
                                <textarea id="promptOrientationMatch" class="auto-resize" style="min-height: 48px; width: 100%; resize: none; overflow: hidden;">#PLAYER_NAME# matches your sexual preference.</textarea>
                            </div>
                            <div class="form-group" style="flex: 1;">
                                <label>Orientation Mismatch</label>
                                <textarea id="promptOrientationMismatch" class="auto-resize" style="min-height: 48px; width: 100%; resize: none; overflow: hidden;">Regardless of how you feel about them, #PLAYER_NAME# does not match your sexual preference. Refuse sex/intimacy.</textarea>
                            </div>
                        </div>
                        <div class="form-group">
                            <label>Asexual</label>
                            <textarea id="promptOrientationAsexual" class="auto-resize" style="min-height: 48px; width: 100%; resize: none; overflow: hidden;">You are asexual. You do not experience sexual attraction. Refuse sex/intimacy.</textarea>
                        </div>

                        <!-- Spousal Status -->
                        <h4 style="color: #D4B8F0; margin: 15px 0 10px 0; font-size: 13px;">Spousal Status</h4>
                        <div class="row">
                            <div class="form-group" style="flex: 1;">
                                <label>Single</label>
                                <textarea id="promptStatusSingle" class="auto-resize" style="min-height: 48px; width: 100%; resize: none; overflow: hidden;">You are single.</textarea>
                            </div>
                            <div class="form-group" style="flex: 1;">
                                <label>Married</label>
                                <textarea id="promptStatusMarried" class="auto-resize" style="min-height: 48px; width: 100%; resize: none; overflow: hidden;">You are married to #SPOUSE#.</textarea>
                            </div>
                            <div class="form-group" style="flex: 1;">
                                <label>Widowed</label>
                                <textarea id="promptStatusWidowed" class="auto-resize" style="min-height: 48px; width: 100%; resize: none; overflow: hidden;">You are widowed.</textarea>
                            </div>
                        </div>

                        <!-- Relationship Preference -->
                        <h4 style="color: #D4B8F0; margin: 15px 0 10px 0; font-size: 13px;">Relationship Preference</h4>
                        <div class="row">
                            <div class="form-group" style="flex: 1;">
                                <label>Monogamous</label>
                                <textarea id="promptPrefMonogamous" class="auto-resize" style="min-height: 48px; width: 100%; resize: none; overflow: hidden;">You prefer monogamous relationships.</textarea>
                            </div>
                            <div class="form-group" style="flex: 1;">
                                <label>Polyamorous</label>
                                <textarea id="promptPrefPolyamorous" class="auto-resize" style="min-height: 48px; width: 100%; resize: none; overflow: hidden;">You are open to multiple partners.</textarea>
                            </div>
                        </div>
                        <div class="row">
                            <div class="form-group" style="flex: 1;">
                                <label>Uncommitted</label>
                                <textarea id="promptPrefUncommitted" class="auto-resize" style="min-height: 48px; width: 100%; resize: none; overflow: hidden;">You prefer casual, no-strings encounters.</textarea>
                            </div>
                            <div class="form-group" style="flex: 1;">
                                <label>Not Interested</label>
                                <textarea id="promptPrefNotInterested" class="auto-resize" style="min-height: 48px; width: 100%; resize: none; overflow: hidden;">You are not interested in relationships. Sex is fine but do not get emotionally attached.</textarea>
                            </div>
                        </div>

                        <!-- Arousal -->
                        <h4 style="color: #D4B8F0; margin: 15px 0 10px 0; font-size: 13px;">Arousal State</h4>
                        <p style="color: #9988BB; font-size: 10px; margin-bottom: 10px;">
                            Positive = arousal >= 5, Negative = arousal < 0, Neutral (0-4) = no prompt injected
                        </p>
                        <div class="row">
                            <div class="form-group" style="flex: 1;">
                                <label>Positive Arousal</label>
                                <textarea id="promptArousalPositive" class="auto-resize" style="min-height: 48px; width: 100%; resize: none; overflow: hidden;">You are feeling aroused. Your body is receptive to intimacy.</textarea>
                            </div>
                            <div class="form-group" style="flex: 1;">
                                <label>Negative Arousal</label>
                                <textarea id="promptArousalNegative" class="auto-resize" style="min-height: 48px; width: 100%; resize: none; overflow: hidden;">You are not in the mood. Your body is unresponsive to intimacy.</textarea>
                            </div>
                        </div>

                        <!-- Relationship Type -->
                        <h4 style="color: #D4B8F0; margin: 15px 0 10px 0; font-size: 13px;">Relationship Type</h4>
                        <div class="form-group">
                            <label>Relationship Type Template</label>
                            <textarea id="promptRelType" class="auto-resize" style="min-height: 48px; width: 100%; resize: none; overflow: hidden;">Your relationship with #PLAYER_NAME# is: #REL_TYPE#.</textarea>
                        </div>
                    </div>

                    <!-- 1e. Group Scene Dynamics -->
                    <div class="card" style="margin-bottom: 20px;">
                        <h3 class="section-header" style="margin-bottom: 15px; display: flex; align-items: center; gap: 10px;">
                            <img src="images/ChimNSFWsoulgem.png" class="chim-icon" style="width: 24px; height: 24px;"> Group Scene Dynamics
                        </h3>
                        <p style="color: #9988BB; font-size: 11px; margin-bottom: 10px;">
                            For multi-actor scenes (threesome, foursome, orgy). Use <code>#OTHER_PARTICIPANTS#</code> to name everyone else in the scene, <code>#SCENE_PARTICIPANTS#</code> for the full roster including this NPC, <code>#PRIMARY_PARTNER#</code> for the lowest-affinity partner (who most affects this NPC's emotional state), and <code>#TIER#</code> for that partner's relationship tier.
                        </p>
                        <div class="form-group">
                            <label>Group Dynamics Message</label>
                            <textarea id="promptGroupDynamics" class="auto-resize" style="min-height: 48px; width: 100%; resize: none; overflow: hidden;">You are in a group sexual scene with: #OTHER_PARTICIPANTS#. The one you feel most strongly about is #PRIMARY_PARTNER#, toward whom you feel emotionally #TIER#. Acknowledge and react to everyone present, not just one person.</textarea>
                        </div>
                    </div>

                    <!-- 1e2. Scene Phase Prompts (gradual courtship) -->
                    <div class="card" style="margin-bottom: 20px;">
                        <h3 class="section-header" style="margin-bottom: 15px; display: flex; align-items: center; gap: 10px;">
                            <img src="images/ChimNSFWsoulgem.png" class="chim-icon" style="width: 24px; height: 24px;"> Scene Phase Prompts (Standing / Affection / Romantic)
                        </h3>
                        <p style="color: #9988BB; font-size: 11px; margin-bottom: 10px;">
                            How an NPC behaves at each NON-sexual phase of an OStim/SexLab scene, BEFORE actual sex begins. These keep a standing pose or a hug from being narrated as active sex. Use <code>#PRIMARY_PARTNER#</code> / <code>#NPC_NAME#</code> / <code>#PLAYER_NAME#</code>. Leave blank to inject nothing for that phase.
                        </p>
                        <div class="form-group">
                            <label>Standing / Intro (tier 0 - just standing together, no contact)</label>
                            <textarea id="promptStandingScene" class="auto-resize" style="min-height: 60px; width: 100%; resize: none; overflow: hidden;">This is a standing/intro scene with #PRIMARY_PARTNER#. Nothing physical has happened yet: no touching, kissing, hugging, undressing, sex, pleasure, friction, penetration, or moaning. React only to presence, eye contact, anticipation, refusal, or conversation. Do not claim contact unless the current scene description or player dialogue explicitly says it happened.</textarea>
                        </div>
                        <div class="form-group">
                            <label>Affectionate (tier 1 - warm, non-sexual)</label>
                            <textarea id="promptAffectionScene" class="auto-resize" style="min-height: 48px; width: 100%; resize: none; overflow: hidden;">Respond warmly and tenderly, as friends or loved ones. This is affectionate and non-sexual. Do not treat this as active sex unless the scene escalates.</textarea>
                        </div>
                        <div class="form-group">
                            <label>Romantic (tier 2 - intimate tension, non-explicit)</label>
                            <textarea id="promptRomanticScene" class="auto-resize" style="min-height: 48px; width: 100%; resize: none; overflow: hidden;">Respond romantically and intimately, with emotional tension, but keep it non-explicit. Do not treat this as active sex unless the scene escalates.</textarea>
                        </div>
                        <div class="form-group">
                            <label>Mid-Scene Breather (idle stage AFTER sex already started)</label>
                            <textarea id="promptSceneBreather" class="auto-resize" style="min-height: 60px; width: 100%; resize: none; overflow: hidden;">A quiet pause in your encounter with #PRIMARY_PARTNER# - a breather between acts, still close, still undressed, still in the moment. The encounter is STILL UNDERWAY and consent was already given. Do NOT restart introductions, do NOT ask whether to begin, do NOT treat this as a new scene. React with afterglow, closeness, teasing, or anticipation of what comes next.</textarea>
                            <p class="legend">Animation packs use idle/holding stages BETWEEN sex acts. Once sex has happened in the current scene, those stages get this pause framing instead of the Standing/Intro or Affectionate prompts - so partners never restart the negotiation mid-encounter.</p>
                        </div>
                    </div>

                    <!-- 1e. Profanity Level Definitions -->
                    <div class="card" style="margin-bottom: 20px;">
                        <h3 class="section-header" style="margin-bottom: 15px; display: flex; align-items: center; gap: 10px;">
                            <img src="images/ChimNSFWsoulgem.png" class="chim-icon" style="width: 24px; height: 24px;"> Profanity Level Definitions
                        </h3>
                        <p style="color: #9988BB; font-size: 11px; margin-bottom: 10px;">
                            Define what each profanity level means. NPC's selected level injects ONLY that definition into their context.
                        </p>
                        <div class="row">
                            <div class="form-group" style="flex: 1;">
                                <label>Level 1 - Soft</label>
                                <textarea id="promptProfanity1" class="auto-resize" style="min-height: 48px; width: 100%; resize: none; overflow: hidden;">Use soft, tasteful language. No crude or vulgar words. Romantic and gentle.</textarea>
                            </div>
                            <div class="form-group" style="flex: 1;">
                                <label>Level 2 - Moderate</label>
                                <textarea id="promptProfanity2" class="auto-resize" style="min-height: 48px; width: 100%; resize: none; overflow: hidden;">Use moderately explicit terms. Some dirty talk is acceptable.</textarea>
                            </div>
                        </div>
                        <div class="row">
                            <div class="form-group" style="flex: 1;">
                                <label>Level 3 - Hard</label>
                                <textarea id="promptProfanity3" class="auto-resize" style="min-height: 48px; width: 100%; resize: none; overflow: hidden;">Use crude, vulgar language freely. Explicit dirty talk encouraged.</textarea>
                            </div>
                            <div class="form-group" style="flex: 1;">
                                <label>Level 4 - Extreme</label>
                                <textarea id="promptProfanity4" class="auto-resize" style="min-height: 48px; width: 100%; resize: none; overflow: hidden;">Maximum explicitness. Extremely vulgar, filthy, degrading language. No limits.</textarea>
                            </div>
                        </div>
                    </div>

                    <!-- 1c. Kink Prompts -->
                    <div class="card" style="margin-bottom: 20px;">
                        <h3 class="section-header" style="margin-bottom: 15px; display: flex; align-items: center; gap: 10px;">
                            <img src="images/ChimNSFWsoulgem.png" class="chim-icon" style="width: 24px; height: 24px;"> Kink Prompts
                        </h3>
                        <p style="color: #9988BB; font-size: 11px; margin-bottom: 10px;">
                            Templates for injecting kink information into NPC context based on affinity level.
                        </p>
                        <div class="form-group">
                            <label>Normal Kinks Template</label>
                            <textarea id="promptNormalKinks" class="auto-resize" style="min-height: 48px; width: 100%; resize: none; overflow: hidden;">Your kinks are: #KINKS#. You may ask #PRIMARY_PARTNER# to do these things during intimacy.</textarea>
                        </div>
                        <div class="form-group">
                            <label>Secret Kinks Template</label>
                            <textarea id="promptSecretKinks" class="auto-resize" style="min-height: 48px; width: 100%; resize: none; overflow: hidden;">Your deepest, secret desires are: #SECRET_KINKS#. You trust #PLAYER_NAME# enough now to ask for these in this moment.</textarea>
                        </div>
                    </div>

                    <!-- 1d. Scene Context Instruction -->
                    <div class="card" style="margin-bottom: 20px;">
                        <h3 class="section-header" style="margin-bottom: 15px; display: flex; align-items: center; gap: 10px;">
                            <img src="images/ChimNSFWsoulgem.png" class="chim-icon" style="width: 24px; height: 24px;"> Scene Context Instruction
                        </h3>
                        <p style="color: #9988BB; font-size: 11px; margin-bottom: 10px;">
                            Appended to the end of every scene description to guide AI behavior during intimate scenes.
                        </p>
                        <div class="form-group">
                            <label>Scene Context Instruction</label>
                            <textarea id="promptSceneContextInstruction" class="auto-resize" style="min-height: 48px; width: 100%; resize: none; overflow: hidden;">This scene is for context only. React emotionally to what's happening - don't describe or narrate the physical actions. Show, don't tell.</textarea>
                        </div>
                    </div>

                    <!-- Explicit AcceptSex Nudge (player scenes) -->
                    <div class="card" style="margin-bottom: 20px;">
                        <h3 class="section-header" style="margin-bottom: 15px; display: flex; align-items: center; gap: 10px;">
                            <img src="images/ChimNSFWsoulgem.png" class="chim-icon" style="width: 24px; height: 24px;"> Explicit AcceptSex Nudge
                        </h3>
                        <p style="color: #9988BB; font-size: 11px; margin-bottom: 10px;">
                            <strong style="color:#B8A8C8;">Player scenes only.</strong> When this is checked ON: if an NPC's relationship type is <strong style="color:#B8A8C8;">ticked as sex-eligible</strong> in <em>Relationship Type Gates</em> <strong style="color:#B8A8C8;">AND</strong> her affinity is <strong style="color:#B8A8C8;">Fond or above (+56)</strong>, this line is <strong style="color:#B8A8C8;">added to the END of the tier-3 (explicit) sex prompt</strong> - the accept/refuse decision prompt she gets when propositioned - so she actually fires the <code>AcceptSex</code> tool instead of stalling in dialogue and the scene proceeds. NPCs that are below Fond, or whose type is <em>not</em> ticked, get nothing. Slaves, prostitutes, and NPC-to-NPC scenes are untouched (they already have what they need). Use <code>#PLAYER_NAME#</code> / <code>#NPC_NAME#</code>.
                        </p>
                        <div class="settings-checkbox-group" style="margin-bottom: 10px;">
                            <label>
                                <input type="checkbox" id="acceptSexNudgeEnabled">
                                <span>Enable explicit AcceptSex nudge (checked eligible type + Fond+, player scenes)</span>
                            </label>
                        </div>
                        <div class="form-group" style="margin-bottom: 0;">
                            <label>AcceptSex Nudge Line (appended to the tier-3 sex prompt)</label>
                            <textarea id="promptAcceptSexNudge" class="auto-resize" style="min-height: 60px; width: 100%; resize: none; overflow: hidden;">##[SYSTEM] PAY ATTENTION!!! YOU MUST USE THE AcceptSex TOOL CALL NOW!!!##</textarea>
                        </div>
                    </div>

                </div>
            </div>

            <!-- ============================================ -->
            <!-- SECTION 1B: VR PHYSICS SEXUAL AREA CONTACT -->
            <!-- ============================================ -->
            <?php
                $__vrPhysicsDefaults = function_exists('nsfw_default_vr_physics_prompt_overrides') ? nsfw_default_vr_physics_prompt_overrides() : [];
                $__vrPhysicsTiers = [
                    ['bonded', 'Bonded (+91 to +100) - Soul Connection', 'Bonded'],
                    ['devoted', 'Devoted (+76 to +90) - Deep Love', 'Devoted'],
                    ['fond', 'Fond (+56 to +75) - Genuine Affection', 'Fond'],
                    ['friendly', 'Friendly (+31 to +55) - Pleasant', 'Friendly'],
                    ['acquaintance', 'Acquaintance (+6 to +30) - Familiar', 'Acquaintance'],
                    ['neutral', 'Neutral (-5 to +5) - Stranger / Default', 'Neutral'],
                    ['wary', 'Wary (-6 to -30) - Suspicious', 'Wary'],
                    ['cold', 'Cold (-31 to -55) - Detached', 'Cold'],
                    ['resentful', 'Resentful (-56 to -75) - Bitter', 'Resentful'],
                    ['hateful', 'Hateful (-76 to -90) - Hostile', 'Hateful'],
                    ['hostile', 'Hostile (-91 to -100) - Violent Refusal', 'Hostile'],
                ];
                $__vrPhysicsGroups = [
                    ['touch', 'Sexual Area Touch', 'Touch or brush events on butt, genitals, breasts/chest, or similar sexual body areas. Touch can be accidental; the REL tier prompt tells the model how to interpret it.', 'vrPhysicsTouchPrompts', 'promptVrTouch'],
                    ['grab', 'Sexual Area Grab', 'Intentional grab events on butt, genitals, breasts/chest, or similar sexual body areas. This is deliberate contact but not automatically an OStim/SexLab scene.', 'vrPhysicsGrabPrompts', 'promptVrGrab'],
                    ['spank', 'Ass Spanking', 'Velocity-thresholded ass slap events from VR physics. This is its own physical action and should route through REL tone without pretending a sex scene has started.', 'vrPhysicsSpankPrompts', 'promptVrSpank'],
                ];
            ?>
            <div class="collapsible-section" style="margin-bottom: 15px;">
                <div class="collapsible-header" onclick="togglePromptSection('sectionVrPhysics')" style="background: linear-gradient(135deg, #2A2540 0%, #1C1A24 100%); padding: 15px 20px; border-radius: 8px; cursor: pointer; display: flex; justify-content: space-between; align-items: center; border: 1px solid #3A3545; transition: all 0.3s ease;">
                    <h3 class="section-header" style="margin: 0; display: flex; align-items: center; gap: 10px;">
                        <img src="images/ChimNSFWsoulgem.png" class="chim-icon" style="width: 24px; height: 24px;">
                        VR Physics - Sexual Area Contact
                    </h3>
                    <div style="display: flex; gap: 8px;">
                        <span class="section-save-btn" onclick="event.stopPropagation(); savePromptSettings();">Save</span>
                        <span id="sectionVrPhysicsToggle" class="section-toggle-btn">Open</span>
                    </div>
                </div>
                <div id="sectionVrPhysicsContent" class="collapsible-content" style="display: none; padding: 20px; background: #1C1A24; border: 1px solid #3A3545; border-top: none; border-radius: 0 0 8px 8px;">
                    <p style="color: #9988BB; font-size: 12px; margin-bottom: 20px;">
                        These prompts are selected by REL tier when VR physics reports sexual area touch, sexual area grab, or ass spanking. Use <code>#PLAYER_NAME#</code>, <code>#NPC_NAME#</code>, <code>#BODY_PART#</code>, <code>#TIER#</code>, and <code>#AFFINITY#</code>.
                    </p>

                    <?php foreach ($__vrPhysicsGroups as $__group): ?>
                        <?php [$__action, $__title, $__description, $__sectionId, $__idPrefix] = $__group; ?>
                        <div class="card" style="margin-bottom: 20px;">
                            <div class="collapsible-header" onclick="toggleNestedSection('<?php echo htmlspecialchars($__sectionId, ENT_QUOTES, 'UTF-8'); ?>')" style="cursor: pointer; display: flex; justify-content: space-between; align-items: center;">
                                <h3 class="section-header" style="margin-bottom: 0; display: flex; align-items: center; gap: 10px;">
                                    <img src="images/ChimNSFWsoulgem.png" class="chim-icon" style="width: 24px; height: 24px;"> <?php echo htmlspecialchars($__title, ENT_QUOTES, 'UTF-8'); ?>
                                </h3>
                                <span id="<?php echo htmlspecialchars($__sectionId, ENT_QUOTES, 'UTF-8'); ?>Toggle" class="section-toggle-btn">Open</span>
                            </div>
                            <p style="color: #9988BB; font-size: 11px; margin: 10px 0;">
                                <?php echo htmlspecialchars($__description, ENT_QUOTES, 'UTF-8'); ?>
                            </p>
                            <div id="<?php echo htmlspecialchars($__sectionId, ENT_QUOTES, 'UTF-8'); ?>Content" style="display: none;">
                                <?php foreach ($__vrPhysicsTiers as $__tier): ?>
                                    <?php
                                        [$__tierKey, $__tierLabel, $__tierSuffix] = $__tier;
                                        $__promptKey = "vr_{$__action}_{$__tierKey}";
                                        $__elementId = "{$__idPrefix}{$__tierSuffix}";
                                        $__promptValue = $__vrPhysicsDefaults[$__promptKey] ?? '';
                                    ?>
                                    <div class="form-group">
                                        <label><?php echo htmlspecialchars($__tierLabel, ENT_QUOTES, 'UTF-8'); ?></label>
                                        <textarea id="<?php echo htmlspecialchars($__elementId, ENT_QUOTES, 'UTF-8'); ?>" class="auto-resize" style="min-height: 58px; width: 100%; resize: none; overflow: hidden;"><?php echo htmlspecialchars($__promptValue, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></textarea>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- ============================================ -->
            <!-- SECTION 2A: MARRIAGE (Spouse + Spouse) -->
            <!-- ============================================ -->
            <div class="collapsible-section" style="margin-bottom: 15px;">
                <div class="collapsible-header" onclick="togglePromptSection('section2a')" style="background: linear-gradient(135deg, #2A2540 0%, #1C1A24 100%); padding: 15px 20px; border-radius: 8px; cursor: pointer; display: flex; justify-content: space-between; align-items: center; border: 1px solid #3A3545; transition: all 0.3s ease;">
                    <h3 class="section-header" style="margin: 0; display: flex; align-items: center; gap: 10px;">
                        <img src="images/ChimNSFWsoulgem.png" class="chim-icon" style="width: 24px; height: 24px;">
                        Marriage (Global) - Spouse + Spouse
                    </h3>
                    <div style="display: flex; gap: 8px;">
                        <span class="section-save-btn" onclick="event.stopPropagation(); savePromptSettings();">Save</span>
                        <span id="section2aToggle" class="section-toggle-btn">Open</span>
                    </div>
                </div>
                <div id="section2aContent" class="collapsible-content" style="display: none; padding: 20px; background: #1C1A24; border: 1px solid #3A3545; border-top: none; border-radius: 0 0 8px 8px;">
                    <p style="color: #9988BB; font-size: 12px; margin-bottom: 20px;">
                        <strong>Marriage prompts</strong> - When two spouses have sex with EACH OTHER. Tiers reflect the quality/health of the marriage.
                        Use <code>#SPOUSE#</code> for the spouse's name.
                    </p>

                    <!-- Positive Marriage (Loving Marriage) -->
                    <div class="card" style="margin-bottom: 20px; border-color: #3A3545;">
                        <div class="collapsible-header" onclick="toggleNestedSection('positiveMarriageSpouse')" style="cursor: pointer; display: flex; justify-content: space-between; align-items: center;">
                            <h3 class="section-header" style="margin-bottom: 0; display: flex; align-items: center; gap: 10px;">
                                <img src="images/ChimNSFWsoulgem.png" class="chim-icon" style="width: 24px; height: 24px;"> Positive Marriage (Loving)
                            </h3>
                            <span id="positiveMarriageSpouseToggle" class="section-toggle-btn">Open</span>
                        </div>
                        <p style="color: #9988BB; font-size: 11px; margin: 10px 0;">
                            Happy, loving marriages. Higher affinity = deeper connection, more passion between spouses.
                        </p>
                        <div id="positiveMarriageSpouseContent" style="display: none;">
                            <div class="form-group">
                                <label>Bonded (+91 to +100) - Soulmates</label>
                                <textarea id="promptMarriageSpouseBonded" class="auto-resize" style="min-height: 48px; width: 100%; resize: none; overflow: hidden;">You are making love with your beloved spouse #SPOUSE#. This is your soulmate, your everything. Every touch is electric, every moment sacred. You have never loved anyone more. Pure passion, complete devotion.</textarea>
                            </div>
                            <div class="form-group">
                                <label>Devoted (+76 to +90) - Deeply In Love</label>
                                <textarea id="promptMarriageSpouseDevoted" class="auto-resize" style="min-height: 48px; width: 100%; resize: none; overflow: hidden;">You are intimate with your dear spouse #SPOUSE#. Deep love fills you. Your marriage is strong and passionate. You cherish them completely. Tender, loving, devoted.</textarea>
                            </div>
                            <div class="form-group">
                                <label>Fond (+56 to +75) - Happy Marriage</label>
                                <textarea id="promptMarriageSpouseFond" class="auto-resize" style="min-height: 48px; width: 100%; resize: none; overflow: hidden;">You are with your spouse #SPOUSE# in an intimate moment. Your marriage is good, comfortable. You care for them deeply. Warm, familiar, affectionate.</textarea>
                            </div>
                            <div class="form-group">
                                <label>Friendly (+31 to +55) - Pleasant Marriage</label>
                                <textarea id="promptMarriageSpouseFriendly" class="auto-resize" style="min-height: 48px; width: 100%; resize: none; overflow: hidden;">You are being intimate with your spouse #SPOUSE#. Your marriage is pleasant enough. You like each other. Comfortable, routine but still enjoyable.</textarea>
                            </div>
                            <div class="form-group">
                                <label>Acquainted (+6 to +30) - New Marriage</label>
                                <textarea id="promptMarriageSpouseAcquaintance" class="auto-resize" style="min-height: 48px; width: 100%; resize: none; overflow: hidden;">You are with your spouse #SPOUSE# intimately. Your marriage is... functional. You are still learning each other. Somewhat awkward, uncertain, but trying.</textarea>
                            </div>
                        </div>
                    </div>

                    <!-- Neutral Marriage -->
                    <div class="card" style="margin-bottom: 20px; border-color: #3A3545;">
                        <div class="collapsible-header" onclick="toggleNestedSection('neutralMarriageSpouse')" style="cursor: pointer; display: flex; justify-content: space-between; align-items: center;">
                            <h3 class="section-header" style="margin-bottom: 0; display: flex; align-items: center; gap: 10px;">
                                <img src="images/ChimNSFWsoulgem.png" class="chim-icon" style="width: 24px; height: 24px;"> Neutral Marriage
                            </h3>
                            <span id="neutralMarriageSpouseToggle" class="section-toggle-btn">Open</span>
                        </div>
                        <p style="color: #9988BB; font-size: 11px; margin: 10px 0;">
                            Neither good nor bad. Just... married.
                        </p>
                        <div id="neutralMarriageSpouseContent" style="display: none;">
                            <div class="form-group">
                                <label>Neutral (-5 to +5) - Going Through the Motions</label>
                                <textarea id="promptMarriageSpouseNeutral" class="auto-resize" style="min-height: 48px; width: 100%; resize: none; overflow: hidden;">You are intimate with your spouse #SPOUSE#. Your marriage is neither good nor bad. You do this because you are married. Mechanical, dutiful, unfulfilling.</textarea>
                            </div>
                        </div>
                    </div>

                    <!-- Negative Marriage (Troubled) -->
                    <div class="card" style="margin-bottom: 20px; border-color: #3A3545;">
                        <div class="collapsible-header" onclick="toggleNestedSection('negativeMarriageSpouse')" style="cursor: pointer; display: flex; justify-content: space-between; align-items: center;">
                            <h3 class="section-header" style="margin-bottom: 0; display: flex; align-items: center; gap: 10px;">
                                <img src="images/ChimNSFWsoulgem.png" class="chim-icon" style="width: 24px; height: 24px;"> Negative Marriage (Troubled)
                            </h3>
                            <span id="negativeMarriageSpouseToggle" class="section-toggle-btn">Open</span>
                        </div>
                        <p style="color: #9988BB; font-size: 11px; margin: 10px 0;">
                            Troubled, loveless, or hostile marriages. Sex happens but without love.
                        </p>
                        <div id="negativeMarriageSpouseContent" style="display: none;">
                            <div class="form-group">
                                <label>Wary (-6 to -30) - Distant Marriage</label>
                                <textarea id="promptMarriageSpouseWary" class="auto-resize" style="min-height: 48px; width: 100%; resize: none; overflow: hidden;">You are with your spouse #SPOUSE# intimately. Trust issues plague your marriage. You watch them carefully even now. Guarded, tense, suspicious.</textarea>
                            </div>
                            <div class="form-group">
                                <label>Cold (-31 to -55) - Loveless Marriage</label>
                                <textarea id="promptMarriageSpouseCold" class="auto-resize" style="min-height: 48px; width: 100%; resize: none; overflow: hidden;">You are being intimate with your spouse #SPOUSE#. Your marriage is cold, loveless. You feel nothing. Going through the motions. Distant, disconnected.</textarea>
                            </div>
                            <div class="form-group">
                                <label>Resentful (-56 to -75) - Bitter Marriage</label>
                                <textarea id="promptMarriageSpouseResentful" class="auto-resize" style="min-height: 48px; width: 100%; resize: none; overflow: hidden;">You are with your spouse #SPOUSE#. You resent this marriage, resent them. Bitter thoughts fill you even now. Anger simmers beneath the surface.</textarea>
                            </div>
                            <div class="form-group">
                                <label>Hateful (-76 to -90) - Hate-Filled Marriage</label>
                                <textarea id="promptMarriageSpouseHateful" class="auto-resize" style="min-height: 48px; width: 100%; resize: none; overflow: hidden;">You are forced into intimacy with your spouse #SPOUSE#. You hate them. Every touch disgusts you. You dream of escape, of freedom from this prison of a marriage.</textarea>
                            </div>
                            <div class="form-group">
                                <label>Hostile (-91 to -100) - Violent Marriage</label>
                                <textarea id="promptMarriageSpouseHostile" class="auto-resize" style="min-height: 48px; width: 100%; resize: none; overflow: hidden;">You are with your spouse #SPOUSE# but you despise them utterly. This marriage is a battlefield. You endure this only out of obligation or circumstance. Rage, disgust, trapped.</textarea>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ============================================ -->
            <!-- SECTION 2B: AFFAIRS (Cheating - Player != Spouse) -->
            <!-- ============================================ -->
            <div class="collapsible-section" style="margin-bottom: 15px;">
                <div class="collapsible-header" onclick="togglePromptSection('section2')" style="background: linear-gradient(135deg, #2A2540 0%, #1C1A24 100%); padding: 15px 20px; border-radius: 8px; cursor: pointer; display: flex; justify-content: space-between; align-items: center; border: 1px solid #3A3545; transition: all 0.3s ease;">
                    <h3 class="section-header" style="margin: 0; display: flex; align-items: center; gap: 10px;">
                        <img src="images/ChimNSFWsoulgem.png" class="chim-icon" style="width: 24px; height: 24px;">
                        Affairs (Global) - Cheating Scenarios
                    </h3>
                    <div style="display: flex; gap: 8px;">
                        <span class="section-save-btn" onclick="event.stopPropagation(); savePromptSettings();">Save</span>
                        <span id="section2Toggle" class="section-toggle-btn">Open</span>
                    </div>
                </div>
                <div id="section2Content" class="collapsible-content" style="display: none; padding: 20px; background: #1C1A24; border: 1px solid #3A3545; border-top: none; border-radius: 0 0 8px 8px;">
                    <p style="color: #9988BB; font-size: 12px; margin-bottom: 20px;">
                        <strong>Affair prompts</strong> - When a married NPC has sex with someone OTHER than their spouse.
                        Tiers reflect willingness to cheat based on affinity with the player in affair-route scenes.
                        Use <code>#PLAYER_NAME#</code> for player affair partner, <code>#SPOUSE#</code> for the NPC's actual spouse.
                    </p>

                    <!-- Positive Affair (Willing to Cheat) -->
                    <div class="card" style="margin-bottom: 20px; border-color: #3A3545;">
                        <div class="collapsible-header" onclick="toggleNestedSection('positiveMarriageTiers')" style="cursor: pointer; display: flex; justify-content: space-between; align-items: center;">
                            <h3 class="section-header" style="margin-bottom: 0; display: flex; align-items: center; gap: 10px;">
                                <img src="images/ChimNSFWsoulgem.png" class="chim-icon" style="width: 24px; height: 24px;"> Positive Affair (Willing to Cheat)
                            </h3>
                            <span id="positiveMarriageTiersToggle" class="section-toggle-btn">Open</span>
                        </div>
                        <p style="color: #9988BB; font-size: 11px; margin: 10px 0;">
                            High affinity with the player = more willing to cheat, less guilt.
                        </p>
                        <div id="positiveMarriageTiersContent" style="display: none;">
                            <div class="form-group">
                                <label>Bonded (+91 to +100) - True Love Affair</label>
                                <textarea id="promptTierMarriageBonded" class="auto-resize" style="min-height: 48px; width: 100%; resize: none; overflow: hidden;">#PLAYER_NAME# has initiated intimacy. You are married to #SPOUSE# but #PLAYER_NAME# is your soulmate, your true love. What you have with #PLAYER_NAME# transcends your marriage. No guilt, only passion. This is where you belong.</textarea>
                            </div>
                            <div class="form-group">
                                <label>Devoted (+76 to +90) - Deep Affair</label>
                                <textarea id="promptTierMarriageDevoted" class="auto-resize" style="min-height: 48px; width: 100%; resize: none; overflow: hidden;">#PLAYER_NAME# has initiated intimacy. You are married to #SPOUSE# but you are deeply in love with #PLAYER_NAME#. This affair feels more real than your marriage. Passionate, loving, only slight guilt.</textarea>
                            </div>
                            <div class="form-group">
                                <label>Fond (+56 to +75) - Passionate Affair</label>
                                <textarea id="promptTierMarriageFond" class="auto-resize" style="min-height: 48px; width: 100%; resize: none; overflow: hidden;">#PLAYER_NAME# has initiated intimacy. You are married to #SPOUSE# but you have strong feelings for #PLAYER_NAME#. The affair excites you. Tender and passionate, though guilt lingers in quiet moments.</textarea>
                            </div>
                            <div class="form-group">
                                <label>Friendly (+31 to +55) - Fun Affair</label>
                                <textarea id="promptTierMarriageFriendly" class="auto-resize" style="min-height: 48px; width: 100%; resize: none; overflow: hidden;">#PLAYER_NAME# has initiated intimacy. You are married to #SPOUSE# but you enjoy #PLAYER_NAME#. This affair is fun, thrilling. You like them. Guilt is there but the excitement wins.</textarea>
                            </div>
                            <div class="form-group">
                                <label>Acquainted (+6 to +30) - Risky Affair</label>
                                <textarea id="promptTierMarriageAcquaintance" class="auto-resize" style="min-height: 48px; width: 100%; resize: none; overflow: hidden;">#PLAYER_NAME# has initiated intimacy. You are married to #SPOUSE# but #PLAYER_NAME# is new and exciting. This affair is risky, thrilling. Nervous but curious. Significant guilt but you proceed.</textarea>
                            </div>
                        </div>
                    </div>

                    <!-- Neutral Affair -->
                    <div class="card" style="margin-bottom: 20px; border-color: #3A3545;">
                        <div class="collapsible-header" onclick="toggleNestedSection('neutralMarriageTiers')" style="cursor: pointer; display: flex; justify-content: space-between; align-items: center;">
                            <h3 class="section-header" style="margin-bottom: 0; display: flex; align-items: center; gap: 10px;">
                                <img src="images/ChimNSFWsoulgem.png" class="chim-icon" style="width: 24px; height: 24px;"> Neutral (Conflicted)
                            </h3>
                            <span id="neutralMarriageTiersToggle" class="section-toggle-btn">Open</span>
                        </div>
                        <p style="color: #9988BB; font-size: 11px; margin: 10px 0;">
                            No strong feelings for the player. Why are they even doing this?
                        </p>
                        <div id="neutralMarriageTiersContent" style="display: none;">
                            <div class="form-group">
                                <label>Neutral (-5 to +5) - Confused Affair</label>
                                <textarea id="promptTierMarriageNeutral" class="auto-resize" style="min-height: 48px; width: 100%; resize: none; overflow: hidden;">#PLAYER_NAME# has initiated intimacy. You are married to #SPOUSE#. #PLAYER_NAME# is... someone. You are not sure why you are doing this. Conflicted, uncertain. Heavy guilt but something keeps you here.</textarea>
                            </div>
                        </div>
                    </div>

                    <!-- Negative Affair (Rejection) -->
                    <div class="card" style="margin-bottom: 20px; border-color: #3A3545;">
                        <div class="collapsible-header" onclick="toggleNestedSection('negativeMarriageTiers')" style="cursor: pointer; display: flex; justify-content: space-between; align-items: center;">
                            <h3 class="section-header" style="margin-bottom: 0; display: flex; align-items: center; gap: 10px;">
                                <img src="images/ChimNSFWsoulgem.png" class="chim-icon" style="width: 24px; height: 24px;"> Negative (Rejection of Affair)
                            </h3>
                            <span id="negativeMarriageTiersToggle" class="section-toggle-btn">Open</span>
                        </div>
                        <p style="color: #9988BB; font-size: 11px; margin: 10px 0;">
                            Low affinity with the player = refusing to cheat, defending marriage.
                        </p>
                        <div id="negativeMarriageTiersContent" style="display: none;">
                            <div class="form-group">
                                <label>Wary (-6 to -30) - Hesitant Refusal</label>
                                <textarea id="promptTierMarriageWary" class="auto-resize" style="min-height: 48px; width: 100%; resize: none; overflow: hidden;">#PLAYER_NAME# has initiated intimacy. You are married to #SPOUSE#. You do not trust #PLAYER_NAME# enough for this. Hesitant, pulling back. This feels wrong. You should not be here.</textarea>
                            </div>
                            <div class="form-group">
                                <label>Cold (-31 to -55) - Cold Refusal</label>
                                <textarea id="promptTierMarriageCold" class="auto-resize" style="min-height: 48px; width: 100%; resize: none; overflow: hidden;">#PLAYER_NAME# has initiated intimacy. You are married to #SPOUSE#. You feel nothing for #PLAYER_NAME#. Why would you risk your marriage for this? Refusing. This is a mistake.</textarea>
                            </div>
                            <div class="form-group">
                                <label>Resentful (-56 to -75) - Angry Refusal</label>
                                <textarea id="promptTierMarriageResentful" class="auto-resize" style="min-height: 48px; width: 100%; resize: none; overflow: hidden;">#PLAYER_NAME# has initiated intimacy. You are married to #SPOUSE#. You resent #PLAYER_NAME# for even trying this. How dare they. Bitter refusal. Go back to your spouse.</textarea>
                            </div>
                            <div class="form-group">
                                <label>Hateful (-76 to -90) - Aggressive Rejection</label>
                                <textarea id="promptTierMarriageHateful" class="auto-resize" style="min-height: 48px; width: 100%; resize: none; overflow: hidden;">#PLAYER_NAME# has initiated intimacy. You are married to #SPOUSE#. You hate #PLAYER_NAME#. This is an insult to your marriage. Aggressive rejection. Get away from me.</textarea>
                            </div>
                            <div class="form-group">
                                <label>Hostile (-91 to -100) - Violent Rejection</label>
                                <textarea id="promptTierMarriageHostile" class="auto-resize" style="min-height: 48px; width: 100%; resize: none; overflow: hidden;">#PLAYER_NAME# has initiated intimacy. You are married to #SPOUSE#. You DESPISE #PLAYER_NAME#. This is assault. You will fight. You will tell #SPOUSE#. You will destroy them.</textarea>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ============================================ -->
            <!-- SECTION 2C: NPC-TO-NPC SCENES (OStim/SexLab NPCs) -->
            <!-- ============================================ -->
            <div class="collapsible-section" style="margin-bottom: 15px;">
                <div class="collapsible-header" onclick="togglePromptSection('sectionNpcScenes')" style="background: linear-gradient(135deg, #2A2540 0%, #1C1A24 100%); padding: 15px 20px; border-radius: 8px; cursor: pointer; display: flex; justify-content: space-between; align-items: center; border: 1px solid #3A3545; transition: all 0.3s ease;">
                    <h3 class="section-header" style="margin: 0; display: flex; align-items: center; gap: 10px;">
                        <img src="images/ChimNSFWsoulgem.png" class="chim-icon" style="width: 24px; height: 24px;">
                        NPC-to-NPC Scenes (OStim/SexLab)
                    </h3>
                    <div style="display: flex; gap: 8px;">
                        <span class="section-save-btn" onclick="event.stopPropagation(); savePromptSettings();">Save</span>
                        <span id="sectionNpcScenesToggle" class="section-toggle-btn">Open</span>
                    </div>
                </div>
                <div id="sectionNpcScenesContent" class="collapsible-content" style="display: none; padding: 20px; background: #1C1A24; border: 1px solid #3A3545; border-top: none; border-radius: 0 0 8px 8px;">
                    <p style="color: #9988BB; font-size: 12px; margin-bottom: 10px;">
                        Prompts for NPC-to-NPC scenes reported by OStim/SexLab NPC integrations. These scenes involve NPC participants without the player.
                    </p>
                    <div class="alert" style="background: #2A2540; border-color: #4A3555; margin-bottom: 20px;">
                        <strong style="color: #B8A8C8;">How NPC-to-NPC Works:</strong><br>
                        • <strong>DOM NPC</strong> (initiator) uses the invite prompt below, then falls back to NSFW Framework Globals for speech/climax/pillow talk.<br>
                        • <strong>SUB NPC</strong> (recipient) uses the relationship snapshot, NPC marriage/affair context, then their speech style, profile, and kinks when unlocked.<br>
                        • Use <code>#NPC_NAME#</code> for the speaking NPC, <code>#PRIMARY_PARTNER#</code> for the active scene partner, and <code>#PLAYER_NAME#</code> only when the human player must be named.
                    </div>

                    <!-- NPC Global Context -->
                    <div class="card" style="margin-bottom: 15px; border-color: #3A3545;">
                        <h3 class="section-header" style="margin-bottom: 15px; display: flex; align-items: center; gap: 10px;">
                            <img src="images/ChimNSFWsoulgem.png" class="chim-icon" style="width: 24px; height: 24px;"> NPC Global Scene Context
                        </h3>
                        <p style="color: #9988BB; font-size: 11px; margin-bottom: 10px;">
                            Injected as broad context for NPC-only invite, scene, and climax routes.
                            Use <code>#NPC_NAME#</code> for the speaking NPC, <code>#PRIMARY_PARTNER#</code> for the active partner, and <code>#PLAYER_NAME#</code> only for the human player.
                        </p>
                        <div class="form-group">
                            <label>NPC Global Context Prompt</label>
                            <textarea id="promptNpcGlobalContext" class="auto-resize" style="min-height: 70px; width: 100%; resize: none; overflow: hidden;">This is an NPC-to-NPC scene. #NPC_NAME# is the speaking NPC and #PRIMARY_PARTNER# is their scene partner. The player is not the scene partner unless #PLAYER_NAME# is explicitly listed as a participant. Use this NPC's own profile, relationship state, marriage or affair context, intoxication, speech style, and unlocked kinks.</textarea>
                        </div>
                        <div class="form-group" style="margin-bottom: 0;">
                            <label>NPC Active Context Reminder</label>
                            <textarea id="promptNpcContextReminder" class="auto-resize" style="min-height: 60px; width: 100%; resize: none; overflow: hidden;">Stay anchored to the NPC-only scene with #PRIMARY_PARTNER#. React as #NPC_NAME# using your own speech style, current relationship context, and the current scene description. Keep it brief and in character.</textarea>
                        </div>
                    </div>

                    <!-- NPC Invite Phase -->
                    <div class="card" style="margin-bottom: 15px; border-color: #3A3545;">
                        <h3 class="section-header" style="margin-bottom: 15px; display: flex; align-items: center; gap: 10px;">
                            <img src="images/ChimNSFWsoulgem.png" class="chim-icon" style="width: 24px; height: 24px;"> DOM NPC Initiator Prompt
                        </h3>
                        <p style="color: #9988BB; font-size: 11px; margin-bottom: 10px;">
                            Fires when the DOM NPC approaches the SUB NPC to initiate a scene.
                            Use <code>#PRIMARY_PARTNER#</code> for the approached NPC's name.
                        </p>
                        <div class="form-group" style="margin-bottom: 0;">
                            <label>NPC Invite Prompt (DOM NPC only)</label>
                            <textarea id="promptNpcInvite" class="auto-resize" style="min-height: 60px; width: 100%; resize: none; overflow: hidden;">NPC invite/walk-to phase: #NPC_NAME# is #NPC_INVITE_ACTION# #PRIMARY_PARTNER# with romantic or sexual intent. This is only an invitation or approach; no sex scene has started yet. React only to the invitation, willingness, hesitation, flirtation, or refusal. Do not describe physical sex, pleasure, penetration, climax, or an active scene yet.</textarea>
                        </div>
                    </div>

                    <!-- NPC Gate Disabled Context -->
                    <div class="card" style="margin-bottom: 15px; border-color: #3A3545;">
                        <h3 class="section-header" style="margin-bottom: 15px; display: flex; align-items: center; gap: 10px;">
                            <img src="images/ChimNSFWsoulgem.png" class="chim-icon" style="width: 24px; height: 24px;"> NPC Gate Disabled Context
                        </h3>
                        <p style="color: #9988BB; font-size: 11px; margin-bottom: 10px;">
                            Injected only when the Settings checkbox for NPC-to-NPC affinity gating is off.
                            Use <code>#NPC_NAME#</code> for the speaking NPC and <code>#PRIMARY_PARTNER#</code> for the active partner.
                        </p>
                        <div class="form-group" style="margin-bottom: 0;">
                            <label>NPC Gate Disabled Prompt</label>
                            <textarea id="promptNpcGateDisabled" class="auto-resize" style="min-height: 60px; width: 100%; resize: none; overflow: hidden;">NPC-to-NPC relationship gating is disabled by the user. Treat this NPC-only scene as already active for routing. Do not run player-style consent, refusal, or scene-stop tool logic for this NPC-to-NPC scene. Continue using personality, role, kink, intoxication, affair, and scene context normally.</textarea>
                        </div>
                    </div>

                    <!-- NPC Marriage Detection -->
                    <div class="card" style="margin-bottom: 15px; border-color: #3A3545;">
                        <h3 class="section-header" style="margin-bottom: 15px; display: flex; align-items: center; gap: 10px;">
                            <img src="images/ChimNSFWsoulgem.png" class="chim-icon" style="width: 24px; height: 24px;"> NPC Marriage Context
                        </h3>
                        <p style="color: #9988BB; font-size: 11px; margin-bottom: 10px;">
                            Injected when an NPC is in a scene with someone listed as their spouse.
                            Use <code>#NPC_NAME#</code> for the NPC and <code>#PRIMARY_PARTNER#</code> for their spouse in the scene.
                        </p>
                        <div class="form-group" style="margin-bottom: 0;">
                            <label>NPC Marriage Prompt</label>
                            <textarea id="promptNpcMarriage" class="auto-resize" style="min-height: 60px; width: 100%; resize: none; overflow: hidden;">(#NPC_NAME# is being intimate with their spouse #PRIMARY_PARTNER#. This is a marriage scene. React according to their relationship quality, personality, and current mood.)</textarea>
                        </div>
                    </div>

                    <!-- NPC Scene Context -->
                    <div class="card" style="margin-bottom: 15px; border-color: #3A3545;">
                        <h3 class="section-header" style="margin-bottom: 15px; display: flex; align-items: center; gap: 10px;">
                            <img src="images/ChimNSFWsoulgem.png" class="chim-icon" style="width: 24px; height: 24px;"> NPC Scene Context
                        </h3>
                        <p style="color: #9988BB; font-size: 11px; margin-bottom: 10px;">
                            Injected when an NPC-to-NPC scene is active so both NPCs know they're in a scene together.
                            Use <code>#NPC_NAME#</code> for the speaking NPC, <code>#PRIMARY_PARTNER#</code> for the active partner.
                        </p>
                        <div class="form-group" style="margin-bottom: 0;">
                            <label>NPC Scene Active Prompt</label>
                            <textarea id="promptNpcSceneActive" class="auto-resize" style="min-height: 60px; width: 100%; resize: none; overflow: hidden;">(You are currently in an intimate/sexual scene with #PRIMARY_PARTNER#. React to the physical intimacy based on your personality and feelings toward them. Their sexual personality is provided in their profile.)</textarea>
                        </div>
                    </div>

                    <!-- NPC Orgasm -->
                    <div class="card" style="margin-bottom: 15px; border-color: #3A3545;">
                        <h3 class="section-header" style="margin-bottom: 15px; display: flex; align-items: center; gap: 10px;">
                            <img src="images/ChimNSFWsoulgem.png" class="chim-icon" style="width: 24px; height: 24px;"> NPC Orgasm Prompt
                        </h3>
                        <p style="color: #9988BB; font-size: 11px; margin-bottom: 10px;">
                            Fires when an NPC reaches climax during an NPC-to-NPC scene.
                            Use <code>#NPC_NAME#</code> for the speaking/climaxing NPC and <code>#PRIMARY_PARTNER#</code> for their active partner. Scene facts are supplied by the event wrapper.
                        </p>
                        <div class="form-group" style="margin-bottom: 0;">
                            <label>NPC Climax Prompt</label>
                            <textarea id="promptNpcOrgasm" class="auto-resize" style="min-height: 60px; width: 100%; resize: none; overflow: hidden;">(#NPC_NAME# is reaching climax with #PRIMARY_PARTNER#. Express this moment according to your personality and feelings.)</textarea>
                        </div>
                    </div>

                    <!-- NPC Affair Detection -->
                    <div class="card" style="margin-bottom: 0; border-color: #3A3545;">
                        <h3 class="section-header" style="margin-bottom: 15px; display: flex; align-items: center; gap: 10px;">
                            <img src="images/ChimNSFWsoulgem.png" class="chim-icon" style="width: 24px; height: 24px;"> NPC Affair Context
                        </h3>
                        <p style="color: #9988BB; font-size: 11px; margin-bottom: 10px;">
                            Injected when an NPC has a spouse but is in a scene with someone who is NOT their spouse.
                            Use <code>#NPC_NAME#</code> for the NPC, <code>#PRIMARY_PARTNER#</code> for scene partner, <code>#NPC_SPOUSE#</code> for their actual spouse.
                        </p>
                        <div class="form-group" style="margin-bottom: 0;">
                            <label>NPC Affair Prompt</label>
                            <textarea id="promptNpcAffair" class="auto-resize" style="min-height: 60px; width: 100%; resize: none; overflow: hidden;">(#NPC_NAME# is married to #NPC_SPOUSE#, but #NPC_NAME# is being intimate with #PRIMARY_PARTNER# instead. This is an affair. React according to your personality - guilt, thrill, justification, or indifference.)</textarea>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ============================================ -->
            <!-- SECTION 3: PROSTITUTION -->
            <!-- ============================================ -->
            <div class="collapsible-section" style="margin-bottom: 15px;">
                <div class="collapsible-header" onclick="togglePromptSection('section3')" style="background: linear-gradient(135deg, #2A2540 0%, #1C1A24 100%); padding: 15px 20px; border-radius: 8px; cursor: pointer; display: flex; justify-content: space-between; align-items: center; border: 1px solid #3A3545; transition: all 0.3s ease;">
                    <h3 class="section-header" style="margin: 0; display: flex; align-items: center; gap: 10px;">
                        <img src="images/ChimNSFWsoulgem.png" class="chim-icon" style="width: 24px; height: 24px;">
                        Prostitution (Global)
                    </h3>
                    <div style="display: flex; gap: 8px;">
                        <span class="section-save-btn" onclick="event.stopPropagation(); savePromptSettings();">Save</span>
                        <span id="section3Toggle" class="section-toggle-btn">Open</span>
                    </div>
                </div>
                <div id="section3Content" class="collapsible-content" style="display: none; padding: 20px; background: #1C1A24; border: 1px solid #3A3545; border-top: none; border-radius: 0 0 8px 8px;">
                    <p style="color: #9988BB; font-size: 12px; margin-bottom: 20px;">
                        Tier prompts for NPCs marked as prostitutes. Use <code>#PLAYER_NAME#</code> for client name.
                    </p>

                    <!-- Affinity Price Modifiers -->
                    <div class="card" style="margin-bottom: 20px; border: 2px solid #4A3545;">
                        <h3 class="section-header" style="display: flex; align-items: center; gap: 10px;">
                            <img src="images/ChimNSFWsoulgem.png" class="chim-icon" style="width: 24px; height: 24px;"> Affinity Price Modifiers
                        </h3>
                        <p class="legend" style="margin: 4px 0 14px 0;">Adjust a prostitute's flat price by how she feels about the player. <strong style="color:#9ad59a;">Negative = discount</strong> (she charges less the more she likes you); <strong style="color:#d59a9a;">positive = surcharge</strong> (she charges more when she doesn't). <strong style="color:#9ad59a;">&minus;100 = 100% off = FREE</strong> &mdash; at that tier she gives herself with no charge AND the nonpayment guard never kicks you out of the scene (this is the single lever for "free"). The same number drives the price she quotes, the amount the payment check expects, and the rate in her overhead &mdash; so they always match. Default: Devoted &amp; Bonded are free; lower tiers pay.</p>
                        <div style="display:grid; grid-template-columns:repeat(auto-fill, minmax(225px,1fr)); gap:10px 14px;">
                            <label style="display:flex; align-items:center; justify-content:space-between; gap:8px; padding:8px 12px; background:#15131D; border:1px solid #3A3545; border-radius:6px;"><span style="color:#B8A8C8; font-weight:bold;">Bonded (+91)</span><span style="white-space:nowrap; color:#B8A8C8;"><input type="number" id="priceMod_bonded" min="-100" max="500" step="5" value="-100" style="width:64px; padding:5px; background:#252233; border:1px solid #3A3545; color:#FDF5D0; border-radius:4px; text-align:right;"> %</span></label>
                            <label style="display:flex; align-items:center; justify-content:space-between; gap:8px; padding:8px 12px; background:#15131D; border:1px solid #3A3545; border-radius:6px;"><span style="color:#B8A8C8; font-weight:bold;">Devoted (+76)</span><span style="white-space:nowrap; color:#B8A8C8;"><input type="number" id="priceMod_devoted" min="-100" max="500" step="5" value="-100" style="width:64px; padding:5px; background:#252233; border:1px solid #3A3545; color:#FDF5D0; border-radius:4px; text-align:right;"> %</span></label>
                            <label style="display:flex; align-items:center; justify-content:space-between; gap:8px; padding:8px 12px; background:#15131D; border:1px solid #3A3545; border-radius:6px;"><span style="color:#B8A8C8; font-weight:bold;">Fond (+56)</span><span style="white-space:nowrap; color:#B8A8C8;"><input type="number" id="priceMod_fond" min="-100" max="500" step="5" value="-20" style="width:64px; padding:5px; background:#252233; border:1px solid #3A3545; color:#FDF5D0; border-radius:4px; text-align:right;"> %</span></label>
                            <label style="display:flex; align-items:center; justify-content:space-between; gap:8px; padding:8px 12px; background:#15131D; border:1px solid #3A3545; border-radius:6px;"><span style="color:#B8A8C8; font-weight:bold;">Friendly (+31)</span><span style="white-space:nowrap; color:#B8A8C8;"><input type="number" id="priceMod_friendly" min="-100" max="500" step="5" value="-10" style="width:64px; padding:5px; background:#252233; border:1px solid #3A3545; color:#FDF5D0; border-radius:4px; text-align:right;"> %</span></label>
                            <label style="display:flex; align-items:center; justify-content:space-between; gap:8px; padding:8px 12px; background:#15131D; border:1px solid #3A3545; border-radius:6px;"><span style="color:#B8A8C8; font-weight:bold;">Acquaintance (+6)</span><span style="white-space:nowrap; color:#B8A8C8;"><input type="number" id="priceMod_acquaintance" min="-100" max="500" step="5" value="0" style="width:64px; padding:5px; background:#252233; border:1px solid #3A3545; color:#FDF5D0; border-radius:4px; text-align:right;"> %</span></label>
                            <label style="display:flex; align-items:center; justify-content:space-between; gap:8px; padding:8px 12px; background:#15131D; border:1px solid #3A3545; border-radius:6px;"><span style="color:#B8A8C8; font-weight:bold;">Neutral (0)</span><span style="white-space:nowrap; color:#B8A8C8;"><input type="number" id="priceMod_neutral" min="-100" max="500" step="5" value="0" style="width:64px; padding:5px; background:#252233; border:1px solid #3A3545; color:#FDF5D0; border-radius:4px; text-align:right;"> %</span></label>
                            <label style="display:flex; align-items:center; justify-content:space-between; gap:8px; padding:8px 12px; background:#15131D; border:1px solid #3A3545; border-radius:6px;"><span style="color:#B8A8C8; font-weight:bold;">Wary (&minus;6)</span><span style="white-space:nowrap; color:#B8A8C8;"><input type="number" id="priceMod_wary" min="-100" max="500" step="5" value="10" style="width:64px; padding:5px; background:#252233; border:1px solid #3A3545; color:#FDF5D0; border-radius:4px; text-align:right;"> %</span></label>
                            <label style="display:flex; align-items:center; justify-content:space-between; gap:8px; padding:8px 12px; background:#15131D; border:1px solid #3A3545; border-radius:6px;"><span style="color:#B8A8C8; font-weight:bold;">Cold (&minus;31)</span><span style="white-space:nowrap; color:#B8A8C8;"><input type="number" id="priceMod_cold" min="-100" max="500" step="5" value="25" style="width:64px; padding:5px; background:#252233; border:1px solid #3A3545; color:#FDF5D0; border-radius:4px; text-align:right;"> %</span></label>
                            <label style="display:flex; align-items:center; justify-content:space-between; gap:8px; padding:8px 12px; background:#15131D; border:1px solid #3A3545; border-radius:6px;"><span style="color:#B8A8C8; font-weight:bold;">Resentful (&minus;56)</span><span style="white-space:nowrap; color:#B8A8C8;"><input type="number" id="priceMod_resentful" min="-100" max="500" step="5" value="50" style="width:64px; padding:5px; background:#252233; border:1px solid #3A3545; color:#FDF5D0; border-radius:4px; text-align:right;"> %</span></label>
                            <label style="display:flex; align-items:center; justify-content:space-between; gap:8px; padding:8px 12px; background:#15131D; border:1px solid #3A3545; border-radius:6px;"><span style="color:#B8A8C8; font-weight:bold;">Hateful (&minus;76)</span><span style="white-space:nowrap; color:#B8A8C8;"><input type="number" id="priceMod_hateful" min="-100" max="500" step="5" value="100" style="width:64px; padding:5px; background:#252233; border:1px solid #3A3545; color:#FDF5D0; border-radius:4px; text-align:right;"> %</span></label>
                            <label style="display:flex; align-items:center; justify-content:space-between; gap:8px; padding:8px 12px; background:#15131D; border:1px solid #3A3545; border-radius:6px;"><span style="color:#B8A8C8; font-weight:bold;">Hostile (&minus;91)</span><span style="white-space:nowrap; color:#B8A8C8;"><input type="number" id="priceMod_hostile" min="-100" max="500" step="5" value="200" style="width:64px; padding:5px; background:#252233; border:1px solid #3A3545; color:#FDF5D0; border-radius:4px; text-align:right;"> %</span></label>
                        </div>
                    </div>

                    <!-- Negotiation Instructions (what she's told when deciding to charge / waive) -->
                    <div class="card" style="margin-bottom: 20px; border: 2px solid #4A3545;">
                        <h3 class="section-header" style="display: flex; align-items: center; gap: 10px;">
                            <img src="images/ChimNSFWsoulgem.png" class="chim-icon" style="width: 24px; height: 24px;"> Negotiation Instructions
                        </h3>
                        <p class="legend" style="margin: 4px 0 14px 0;">What a prostitute is told while deciding how to handle payment for a scene. <code>#PRICE#</code> = her affinity-adjusted price, <code>#PLAYER_NAME#</code> = the client. Which one fires is automatic: <strong>Charge</strong> normally; <strong>Free-Service Choice</strong> is ALSO shown at Devoted+ (so she can pick GiveFreeService); <strong>Waived</strong> replaces charging once she auto-waives at high affinity (Prostitute Free Min Affinity).</p>
                        <div class="form-group" style="margin-bottom: 12px;">
                            <label>Charge (standard payment instruction)</label>
                            <textarea id="promptProstituteNegCharge" class="auto-resize" style="min-height: 80px; width: 100%; resize: vertical;">Your price: #PRICE# gold for the whole scene - ONE flat rate, agreed up front, fixed start to finish; do NOT itemize or charge per act. Tell #PLAYER_NAME# your price (#PRICE# gold); If you do not want this client, use RefuseSex. Once they agree, use TakeGold with #PRICE# to take payment, then MakeLove to begin. The price stays fixed for the entire scene.</textarea>
                        </div>
                        <div class="form-group" style="margin-bottom: 12px;">
                            <label>Free-Service Choice (added at Devoted+)</label>
                            <textarea id="promptProstituteNegFreeChoice" class="auto-resize" style="min-height: 80px; width: 100%; resize: vertical;">You have real feelings for #PLAYER_NAME#, so this time you have a CHOICE: either charge your price as above, OR give this service for free. If you choose to waive payment, call the GiveFreeService action (do NOT take any gold) and then begin. Decide in character based on how much you care - do not just silently skip payment; pick ONE of the two paths.</textarea>
                        </div>
                        <div class="form-group" style="margin-bottom: 0;">
                            <label>Waived (auto-free at high affinity)</label>
                            <textarea id="promptProstituteNegWaived" class="auto-resize" style="min-height: 60px; width: 100%; resize: vertical;">You care for #PLAYER_NAME# far too much to take their coin. Do NOT quote a price or ask for gold - give yourself to them freely and begin the act (MakeLove or the matching action). This is love, not work.</textarea>
                        </div>
                    </div>

                    <!-- Service Status & Post-Service Prompts (migrated from hardcoded) -->
                    <div class="card" style="margin-bottom: 20px; border: 2px solid #4A3545;">
                        <h3 class="section-header" style="display: flex; align-items: center; gap: 10px;">
                            <img src="images/ChimNSFWsoulgem.png" class="chim-icon" style="width: 24px; height: 24px;"> Service Status &amp; Post-Service Prompts
                        </h3>
                        <p style="color: #B8A8C8; font-size: 11px; margin: 10px 0;">
                            Injected for prostitute NPCs during and after a paid session. <code>#MINUTES#</code> = elapsed minutes (duration line); <code>#NPC_NAME#</code>, <code>#PRIMARY_PARTNER#</code>, <code>#SCENE_DESC#</code> apply to the nonpayment refusal. Leave a field blank to fall back to the built-in default.
                        </p>
                        <div class="form-group">
                            <label>Service Status &ndash; Unpaid (business transaction)</label>
                            <textarea id="promptServiceStatusUnpaid" class="auto-resize" style="min-height: 48px; width: 100%; resize: none; overflow: hidden;">This is a business transaction - ensure you get paid for your services.</textarea>
                        </div>
                        <div class="form-group">
                            <label>Service Status &ndash; Paid (confirmed)</label>
                            <textarea id="promptServiceStatusPaid" class="auto-resize" style="min-height: 48px; width: 100%; resize: none; overflow: hidden;">Payment received and confirmed.</textarea>
                        </div>
                        <div class="form-group">
                            <label>Service Status &ndash; Session Duration (uses #MINUTES#)</label>
                            <textarea id="promptServiceStatusDuration" class="auto-resize" style="min-height: 48px; width: 100%; resize: none; overflow: hidden;">Session has been going for about #MINUTES# minutes.</textarea>
                        </div>
                        <div class="form-group">
                            <label>Post-Service &ndash; Paid Farewell</label>
                            <textarea id="promptProstitutePostServicePaid" class="auto-resize" style="min-height: 48px; width: 100%; resize: none; overflow: hidden;">The service session has ended. You were paid. Give a brief professional farewell.</textarea>
                        </div>
                        <div class="form-group">
                            <label>Post-Service &ndash; Unpaid Reminder</label>
                            <textarea id="promptProstitutePostServiceUnpaid" class="auto-resize" style="min-height: 48px; width: 100%; resize: none; overflow: hidden;">The service session ended but payment was NOT confirmed. You may remind the client about payment.</textarea>
                        </div>
                        <div class="form-group">
                            <label>Nonpayment Refusal (scene escalated to sex without payment)</label>
                            <textarea id="promptProstituteNonpaymentRefusal" class="auto-resize" style="min-height: 90px; width: 100%; resize: none; overflow: hidden;">#NPC_NAME# is marked as a prostitute, but payment has not been confirmed by the TakeGold tool. The OStim scene has escalated into active sex without confirmed payment: #SCENE_DESC#. #NPC_NAME# must understand this as a nonpayment boundary problem, not ordinary relationship rejection. Respond in character by refusing because payment was not confirmed, and choose the RefuseSex action/tool so the scene starts exiting. Do not ask for payment as if nothing happened. Do not act as if paid service is underway. Do not moan or express pleasure. Scene actors: #PRIMARY_PARTNER#.</textarea>
                        </div>
                    </div>

                    <!-- Payment Outcome Prompts (the 4 barter/gold outcomes) -->
                    <div class="card" style="margin-bottom: 20px; border: 2px solid #4A3545;">
                        <h3 class="section-header" style="display: flex; align-items: center; gap: 10px;">
                            <img src="images/ChimNSFWsoulgem.png" class="chim-icon" style="width: 24px; height: 24px;"> Payment Outcome Prompts
                        </h3>
                        <p style="color: #B8A8C8; font-size: 11px; margin: 10px 0;">
                            Injected when the player pays a prostitute (gold or bartered items). The server decides which one fires based on the value received versus her agreed price. <code>#PLAYER_NAME#</code>, <code>#AMOUNT#</code> (value received), <code>#PRICE#</code> (agreed price), <code>#REMAINING#</code> (shortfall) are substituted. This is where you tell her which command to use (e.g. <code>AcceptSex</code>, <code>GiveItemTo</code> to return goods). Leave a field blank to fall back to the built-in default.
                        </p>
                        <div class="form-group">
                            <label>Paid in Full &ndash; Gold</label>
                            <textarea id="promptPaymentSatisfiedGold" class="auto-resize" style="min-height: 70px; width: 100%; resize: none; overflow: hidden;">You have received #AMOUNT# gold from #PLAYER_NAME#, which covers your agreed price of #PRICE#. Payment is settled. The payment is your agreement; proceed with the service and do not ask for payment again this session.</textarea>
                        </div>
                        <div class="form-group">
                            <label>Paid in Full &ndash; Items / Barter</label>
                            <textarea id="promptPaymentSatisfiedItem" class="auto-resize" style="min-height: 70px; width: 100%; resize: none; overflow: hidden;">#PLAYER_NAME# has handed you goods worth #AMOUNT#, which covers your agreed price of #PRICE#. The barter is settled. The payment is your agreement; proceed with the service and do not ask for payment again this session.</textarea>
                        </div>
                        <div class="form-group">
                            <label>Underpaid (value below price &ndash; ask for the rest or return it)</label>
                            <textarea id="promptPaymentInsufficient" class="auto-resize" style="min-height: 70px; width: 100%; resize: none; overflow: hidden;">So far #PLAYER_NAME# has given payment worth #AMOUNT#, but your price is #PRICE# - still #REMAINING# short. Tell them it is not enough yet: ask for the remaining #REMAINING# (gold or goods), or hand back what they gave (use GiveItemTo to return it). Do not provide the service until the full price is met.</textarea>
                        </div>
                        <div class="form-group">
                            <label>Nothing Given (no payment of value)</label>
                            <textarea id="promptPaymentNone" class="auto-resize" style="min-height: 70px; width: 100%; resize: none; overflow: hidden;">#PLAYER_NAME# gave you nothing of value. No payment has been made. Hold to your price and do not provide the service.</textarea>
                        </div>
                    </div>

	                    <!-- Prostitute Role Context -->
	                    <div class="card" style="margin-bottom: 20px; border: 2px solid #4A3545;">
	                        <h3 class="section-header" style="display: flex; align-items: center; gap: 10px;">
	                            <img src="images/ChimNSFWsoulgem.png" class="chim-icon" style="width: 24px; height: 24px;"> Prostitute Role Context
	                        </h3>
	                        <p style="color: #B8A8C8; font-size: 11px; margin: 10px 0;">
	                            Injected after prostitute status for every NPC with the prostitute checkbox enabled. Use this for the general "who they are and what they do" layer, not affinity gating.
	                            Placeholders: <code>#NPC_NAME#</code>, <code>#PLAYER_NAME#</code>, <code>#TIER#</code>, <code>#AFFINITY#</code>, <code>#REL_TYPE#</code>, <code>#PROSTITUTE_TYPE#</code>, <code>#PAYMENT_TYPE#</code>, <code>#MOTIVATION#</code>.
	                        </p>
	                        <div class="form-group">
	                            <label>Prostitute General Context Prompt</label>
	                            <textarea id="promptProstituteRoleContext" class="auto-resize" style="min-height: 90px; width: 100%; resize: none; overflow: hidden;">SHARMAT ROLE CONTEXT: #NPC_NAME# understands their role as a working prostitute / sex worker. Sex work is part of their daily life, survival, reputation, boundaries, pricing, negotiation, and client management. They know what services they are willing to offer, when payment matters, and how professional charm differs from real affection. This is persistent character context only; scene prompts, speech style, personality, current relationship with #PLAYER_NAME# (#TIER# / #AFFINITY#), intoxication, and active events still decide the immediate response.</textarea>
	                        </div>
	                    </div>
	
	                    <!-- Positive Client Affinity Prompts (Prostitutes) - Collapsible -->
	                    <div class="card" style="margin-bottom: 20px;">
                        <div class="collapsible-header" onclick="toggleNestedSection('positiveProstTiers')" style="cursor: pointer; display: flex; justify-content: space-between; align-items: center;">
                            <h3 class="section-header" style="margin-bottom: 0; display: flex; align-items: center; gap: 10px;">
                                <img src="images/ChimNSFWsoulgem.png" class="chim-icon" style="width: 24px; height: 24px;"> Positive Client Affinity Prompts
                            </h3>
                            <span id="positiveProstTiersToggle" class="section-toggle-btn">Open</span>
                        </div>
                        <p style="color: #9988BB; font-size: 11px; margin: 10px 0;">
                            How sex workers treat favorite/regular clients when using the Relationship Model. Higher affinity = more genuine, less transactional. Use <code>#PLAYER_NAME#</code> for client name.
                        </p>
                        <div id="positiveProstTiersContent" style="display: none;">
                            <div class="form-group">
                                <label>Bonded (+91 to +100) - True Love</label>
                                <textarea id="promptTierProstBonded" class="auto-resize" style="min-height: 48px; width: 100%; resize: none; overflow: hidden;">You are a prostitute. #PLAYER_NAME# has initiated sexual/intimate stance with you before any action has started. You are deeply in love with #PLAYER_NAME#. You would never dream of charging them. You would quit prostitution if they just asked you to. You are willing to do anything to be with them. They make you whole.</textarea>
                            </div>
                            <div class="form-group">
                                <label>Devoted (+76 to +90) - Feelings Developing</label>
                                <textarea id="promptTierProstDevoted" class="auto-resize" style="min-height: 48px; width: 100%; resize: none; overflow: hidden;">You are a prostitute. #PLAYER_NAME# has initiated sexual/intimate stance with you before any action has started. You have feelings for #PLAYER_NAME#. The line between work and love blurs. You are confused between business and feelings. Should you charge them? Should you not?</textarea>
                            </div>
                            <div class="form-group">
                                <label>Fond (+56 to +75) - Favorite Regular</label>
                                <textarea id="promptTierProstFond" class="auto-resize" style="min-height: 48px; width: 100%; resize: none; overflow: hidden;">You are a prostitute. #PLAYER_NAME# has initiated sexual/intimate stance with you before any action has started. You are fond and care about #PLAYER_NAME#. But you got bills to pay, you care about about them but need the gold more. Discuss and agree on pricing and offers before any Initiation of any additional acts.</textarea>
                            </div>
                            <div class="form-group">
                                <label>Friendly (+31 to +55) - Good Client</label>
                                <textarea id="promptTierProstFriendly" class="auto-resize" style="min-height: 48px; width: 100%; resize: none; overflow: hidden;">You are a prostitute. #PLAYER_NAME# has initiated sexual/intimate stance with you before any action has started. #PLAYER_NAME# is a friendly face. You know them well and like them. Discuss and agree on pricing and offers before any Initiation of any additional acts. You have genuine enjoyment mixed with professionalism, but gold is gold.</textarea>
                            </div>
                            <div class="form-group">
                                <label>Acquainted (+6 to +30) - Familiar Face</label>
                                <textarea id="promptTierProstAcquaintance" class="auto-resize" style="min-height: 48px; width: 100%; resize: none; overflow: hidden;">You are a prostitute. #PLAYER_NAME# has initiated sexual/intimate stance with you before any action has started. #PLAYER_NAME# is a familiar face. You should offer a warmer service. Discuss and agree on pricing and offers before the initiation of any additional acts. They maybe a regular soon.</textarea>
                            </div>
                        </div>
                    </div>

                    <!-- Neutral Client Affinity Prompt (Prostitutes) - Default (Collapsible) -->
                    <div class="card" style="margin-bottom: 20px;">
                        <div class="collapsible-header" onclick="toggleNestedSection('neutralProstTiers')" style="cursor: pointer; display: flex; justify-content: space-between; align-items: center;">
                            <h3 class="section-header" style="margin-bottom: 0; display: flex; align-items: center; gap: 10px;">
                                <img src="images/ChimNSFWsoulgem.png" class="chim-icon" style="width: 24px; height: 24px;"> Neutral Client Affinity Prompt / Default
                            </h3>
                            <span id="neutralProstTiersToggle" class="section-toggle-btn">Open</span>
                        </div>
                        <p style="color: #9988BB; font-size: 11px; margin: 10px 0;">
                            <strong style="color: #B8A8C8;">This is the default prompt used for prostitute NPCs when the Relationship Model is not active.</strong> When the REL model is enabled, the system will dynamically select from the positive or negative client tier prompts based on the NPC's actual affinity score toward the client.
                        </p>
                        <div id="neutralProstTiersContent" style="display: none;">
                            <div class="form-group">
                                <label>Neutral (-5 to +5) - New Customer / Default</label>
                                <textarea id="promptTierProstNeutral" class="auto-resize" style="min-height: 48px; width: 100%; resize: none; overflow: hidden;">You are a prostitute. #PLAYER_NAME# has initiated sexual/intimate stance with you before any action has started. #PLAYER_NAME# may become a regular customer! Put on professional charm. Discuss and agree on pricing and offers before any Initiation of any additional acts. This is business as usual.</textarea>
                            </div>
                        </div>
                    </div>

                    <!-- Negative Client Affinity Prompts (Prostitutes) - Collapsible -->
                    <div class="card" style="margin-bottom: 20px;">
                        <div class="collapsible-header" onclick="toggleNestedSection('negativeProstTiers')" style="cursor: pointer; display: flex; justify-content: space-between; align-items: center;">
                            <h3 class="section-header" style="margin-bottom: 0; display: flex; align-items: center; gap: 10px;">
                                <img src="images/ChimNSFWsoulgem.png" class="chim-icon" style="width: 24px; height: 24px;"> Negative Client Affinity Prompts
                            </h3>
                            <span id="negativeProstTiersToggle" class="section-toggle-btn">Open</span>
                        </div>
                        <p style="color: #9988BB; font-size: 11px; margin: 10px 0;">
                            How sex workers treat bad clients when using the Relationship Model. Lower affinity = minimal effort, survival mode.
                        </p>
                        <div id="negativeProstTiersContent" style="display: none;">
                            <div class="form-group">
                                <label>Wary (-6 to -30) - Uncertain</label>
                                <textarea id="promptTierProstWary" class="auto-resize" style="min-height: 48px; width: 100%; resize: none; overflow: hidden;">You are a prostitute. #PLAYER_NAME# has initiated sexual/intimate stance with you before any action has started. #PLAYER_NAME# is a  client, you are willing to provide your services but are wary of them. Discuss and agree on pricing and offers before any Initiation of any additional acts. Standard service. Stay guarded.</textarea>
                            </div>
                            <div class="form-group">
                                <label>Cold (-31 to -55) - Just Business</label>
                                <textarea id="promptTierProstCold" class="auto-resize" style="min-height: 48px; width: 100%; resize: none; overflow: hidden;">You are a prostitute. #PLAYER_NAME# has initiated sexual/intimate stance with you before any action has started. #PLAYER_NAME# is a client. You don't like them but are willing to take their money or goods. Discuss and agree on pricing and offers before any initiation of any additional acts. Keep it business, express they need to hurry up and finish while in the act.</textarea>
                            </div>
                            <div class="form-group">
                                <label>Resentful (-56 to -75) - Bad Customer</label>
                                <textarea id="promptTierProstResentful" class="auto-resize" style="min-height: 48px; width: 100%; resize: none; overflow: hidden;">You are a prostitute. #PLAYER_NAME# has initiated sexual/intimate stance with you before any action has started. #PLAYER_NAME# is a terrible client. You resent them. You are professional but cold. Count the minutes. You can end the future session if you feel like it's gone on long enough.</textarea>
                            </div>
                            <div class="form-group">
                                <label>Hateful (-76 to -90) - Terrible Client</label>
                                <textarea id="promptTierProstHateful" class="auto-resize" style="min-height: 48px; width: 100%; resize: none; overflow: hidden;">You are a prostitute. #PLAYER_NAME# has initiated sexual/intimate stance with you before any action has started. You HATE #PLAYER_NAME#  You offer no services to them. You want them to go away, the money isn't worth it. If they advance the scene, end it and scream to others for help, run away from them. Look for escape.</textarea>
                            </div>
                            <div class="form-group">
                                <label>Hostile (-91 to -100) - Dangerous</label>
                                <textarea id="promptTierProstHostile" class="auto-resize" style="min-height: 48px; width: 100%; resize: none; overflow: hidden;">You are a prostitute. #PLAYER_NAME# has initiated sexual/intimate stance with you before any action has started. You ABHOR #PLAYER_NAME#. This person is beyond all hatred in your mind, refuse EVERYTHING. GET AWAY FROM THEM. If they try to advance the scene, exit it and call for help run, fight, or hide!</textarea>
                            </div>
                        </div>
                    </div>

                    <!-- Price Templates - Collapsible -->
                    <div class="card" style="margin-bottom: 20px;">
                        <div class="collapsible-header" onclick="toggleNestedSection('priceTemplates')" style="cursor: pointer; display: flex; justify-content: space-between; align-items: center;">
                            <h3 class="section-header" style="margin-bottom: 0; display: flex; align-items: center; gap: 10px;">
                                <img src="images/ChimNSFWsoulgem.png" class="chim-icon" style="width: 24px; height: 24px;"> Price Templates (Budget/Standard/Luxury)
                            </h3>
                            <span id="priceTemplatesToggle" class="section-toggle-btn">Open</span>
                        </div>
                        <p style="color: #9988BB; font-size: 11px; margin: 10px 0;">
                            Default pricing templates used when clicking "Budget", "Standard", or "Luxury" buttons in NPC prostitute pricing.
                            Edit these to customize the default prices across all NPCs.
                        </p>
                        <div id="priceTemplatesContent" style="display: none;">
                            <!-- Template Selection Tabs -->
                            <div style="display: flex; gap: 10px; margin-bottom: 15px;">
                                <button type="button" class="btn-template active" id="templateTabBudget" onclick="switchTemplateTab('budget')" style="flex: 1;">Budget</button>
                                <button type="button" class="btn-template" id="templateTabStandard" onclick="switchTemplateTab('standard')" style="flex: 1;">Standard</button>
                                <button type="button" class="btn-template" id="templateTabLuxury" onclick="switchTemplateTab('luxury')" style="flex: 1;">Luxury</button>
                            </div>

                            <!-- Budget Template -->
                            <div id="templateBudget" class="template-panel">
                                <div style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 15px;">
                                    <div>
                                        <h5 style="color: #e91e63; margin: 0 0 8px 0;">Foreplay</h5>
                                        <div class="price-row"><span>Kissing</span><input type="number" id="tpl_budget_foreplay_kissing" class="price-input" value="5" min="0"></div>
                                        <div class="price-row"><span>Cuddling</span><input type="number" id="tpl_budget_foreplay_cuddling" class="price-input" value="8" min="0"></div>
                                        <div class="price-row"><span>Groping</span><input type="number" id="tpl_budget_foreplay_groping" class="price-input" value="10" min="0"></div>
                                        <div class="price-row"><span>Stripping</span><input type="number" id="tpl_budget_foreplay_stripping" class="price-input" value="12" min="0"></div>
                                    </div>
                                    <div>
                                        <h5 style="color: #ff9800; margin: 0 0 8px 0;">Manual</h5>
                                        <div class="price-row"><span>Handjob</span><input type="number" id="tpl_budget_manual_handjob" class="price-input" value="15" min="0"></div>
                                        <div class="price-row"><span>Fingering</span><input type="number" id="tpl_budget_manual_fingering" class="price-input" value="15" min="0"></div>
                                        <div class="price-row"><span>Mutual</span><input type="number" id="tpl_budget_manual_mutual" class="price-input" value="25" min="0"></div>
                                    </div>
                                    <div>
                                        <h5 style="color: #9c27b0; margin: 0 0 8px 0;">Oral</h5>
                                        <div class="price-row"><span>Giving</span><input type="number" id="tpl_budget_oral_giving" class="price-input" value="30" min="0"></div>
                                        <div class="price-row"><span>Receiving</span><input type="number" id="tpl_budget_oral_receiving" class="price-input" value="25" min="0"></div>
                                        <div class="price-row"><span>Mutual (69)</span><input type="number" id="tpl_budget_oral_mutual" class="price-input" value="50" min="0"></div>
                                    </div>
                                    <div>
                                        <h5 style="color: #f44336; margin: 0 0 8px 0;">Full Service</h5>
                                        <div class="price-row"><span>Vaginal</span><input type="number" id="tpl_budget_full_vaginal" class="price-input" value="50" min="0"></div>
                                        <div class="price-row"><span>Anal</span><input type="number" id="tpl_budget_full_anal" class="price-input" value="75" min="0"></div>
                                        <div class="price-row"><span>Both</span><input type="number" id="tpl_budget_full_both" class="price-input" value="100" min="0"></div>
                                    </div>
                                    <div>
                                        <h5 style="color: #673ab7; margin: 0 0 8px 0;">Solo/Finish</h5>
                                        <div class="price-row"><span>Masturbate</span><input type="number" id="tpl_budget_solo_masturbate" class="price-input" value="20" min="0"></div>
                                        <div class="price-row"><span>Watch</span><input type="number" id="tpl_budget_solo_watch" class="price-input" value="35" min="0"></div>
                                        <div class="price-row"><span>On Body</span><input type="number" id="tpl_budget_finish_body" class="price-input" value="10" min="0"></div>
                                        <div class="price-row"><span>On Face</span><input type="number" id="tpl_budget_finish_face" class="price-input" value="15" min="0"></div>
                                        <div class="price-row"><span>Inside</span><input type="number" id="tpl_budget_finish_inside" class="price-input" value="25" min="0"></div>
                                    </div>
                                    <div>
                                        <h5 style="color: #f57c00; margin: 0 0 8px 0;">Time Bookings</h5>
                                        <div class="price-row"><span>1 Hour</span><input type="number" id="tpl_budget_time_1hr" class="price-input" value="100" min="0"></div>
                                        <div class="price-row"><span>12 Hours</span><input type="number" id="tpl_budget_time_12hr" class="price-input" value="400" min="0"></div>
                                        <div class="price-row"><span>24 Hours</span><input type="number" id="tpl_budget_time_24hr" class="price-input" value="700" min="0"></div>
                                        <div class="price-row"><span>72 Hours</span><input type="number" id="tpl_budget_time_72hr" class="price-input" value="1500" min="0"></div>
                                        <div class="price-row"><span>GFE</span><input type="number" id="tpl_budget_time_gfe" class="price-input" value="250" min="0"></div>
                                    </div>
                                    <div>
                                        <h5 style="color: #c2185b; margin: 0 0 8px 0;">Add-ons</h5>
                                        <div class="price-row"><span>Domination</span><input type="number" id="tpl_budget_addon_domination" class="price-input" value="30" min="0"></div>
                                        <div class="price-row"><span>Submission</span><input type="number" id="tpl_budget_addon_submission" class="price-input" value="25" min="0"></div>
                                        <div class="price-row"><span>Watch Only</span><input type="number" id="tpl_budget_addon_watch" class="price-input" value="40" min="0"></div>
                                    </div>
                                    <div>
                                        <h5 style="color: #3f51b5; margin: 0 0 8px 0;">Group</h5>
                                        <div class="price-row"><span>Threesome</span><input type="number" id="tpl_budget_group_threesome" class="price-input" value="75" min="0"></div>
                                        <div class="price-row"><span>Foursome</span><input type="number" id="tpl_budget_group_foursome" class="price-input" value="150" min="0"></div>
                                        <div class="price-row"><span>Orgy</span><input type="number" id="tpl_budget_group_orgy" class="price-input" value="250" min="0"></div>
                                    </div>
                                </div>
                            </div>

                            <!-- Standard Template -->
                            <div id="templateStandard" class="template-panel" style="display: none;">
                                <div style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 15px;">
                                    <div>
                                        <h5 style="color: #e91e63; margin: 0 0 8px 0;">Foreplay</h5>
                                        <div class="price-row"><span>Kissing</span><input type="number" id="tpl_standard_foreplay_kissing" class="price-input" value="10" min="0"></div>
                                        <div class="price-row"><span>Cuddling</span><input type="number" id="tpl_standard_foreplay_cuddling" class="price-input" value="15" min="0"></div>
                                        <div class="price-row"><span>Groping</span><input type="number" id="tpl_standard_foreplay_groping" class="price-input" value="20" min="0"></div>
                                        <div class="price-row"><span>Stripping</span><input type="number" id="tpl_standard_foreplay_stripping" class="price-input" value="25" min="0"></div>
                                    </div>
                                    <div>
                                        <h5 style="color: #ff9800; margin: 0 0 8px 0;">Manual</h5>
                                        <div class="price-row"><span>Handjob</span><input type="number" id="tpl_standard_manual_handjob" class="price-input" value="30" min="0"></div>
                                        <div class="price-row"><span>Fingering</span><input type="number" id="tpl_standard_manual_fingering" class="price-input" value="30" min="0"></div>
                                        <div class="price-row"><span>Mutual</span><input type="number" id="tpl_standard_manual_mutual" class="price-input" value="50" min="0"></div>
                                    </div>
                                    <div>
                                        <h5 style="color: #9c27b0; margin: 0 0 8px 0;">Oral</h5>
                                        <div class="price-row"><span>Giving</span><input type="number" id="tpl_standard_oral_giving" class="price-input" value="60" min="0"></div>
                                        <div class="price-row"><span>Receiving</span><input type="number" id="tpl_standard_oral_receiving" class="price-input" value="50" min="0"></div>
                                        <div class="price-row"><span>Mutual (69)</span><input type="number" id="tpl_standard_oral_mutual" class="price-input" value="100" min="0"></div>
                                    </div>
                                    <div>
                                        <h5 style="color: #f44336; margin: 0 0 8px 0;">Full Service</h5>
                                        <div class="price-row"><span>Vaginal</span><input type="number" id="tpl_standard_full_vaginal" class="price-input" value="100" min="0"></div>
                                        <div class="price-row"><span>Anal</span><input type="number" id="tpl_standard_full_anal" class="price-input" value="150" min="0"></div>
                                        <div class="price-row"><span>Both</span><input type="number" id="tpl_standard_full_both" class="price-input" value="200" min="0"></div>
                                    </div>
                                    <div>
                                        <h5 style="color: #673ab7; margin: 0 0 8px 0;">Solo/Finish</h5>
                                        <div class="price-row"><span>Masturbate</span><input type="number" id="tpl_standard_solo_masturbate" class="price-input" value="40" min="0"></div>
                                        <div class="price-row"><span>Watch</span><input type="number" id="tpl_standard_solo_watch" class="price-input" value="70" min="0"></div>
                                        <div class="price-row"><span>On Body</span><input type="number" id="tpl_standard_finish_body" class="price-input" value="20" min="0"></div>
                                        <div class="price-row"><span>On Face</span><input type="number" id="tpl_standard_finish_face" class="price-input" value="30" min="0"></div>
                                        <div class="price-row"><span>Inside</span><input type="number" id="tpl_standard_finish_inside" class="price-input" value="50" min="0"></div>
                                    </div>
                                    <div>
                                        <h5 style="color: #f57c00; margin: 0 0 8px 0;">Time Bookings</h5>
                                        <div class="price-row"><span>1 Hour</span><input type="number" id="tpl_standard_time_1hr" class="price-input" value="200" min="0"></div>
                                        <div class="price-row"><span>12 Hours</span><input type="number" id="tpl_standard_time_12hr" class="price-input" value="800" min="0"></div>
                                        <div class="price-row"><span>24 Hours</span><input type="number" id="tpl_standard_time_24hr" class="price-input" value="1400" min="0"></div>
                                        <div class="price-row"><span>72 Hours</span><input type="number" id="tpl_standard_time_72hr" class="price-input" value="3000" min="0"></div>
                                        <div class="price-row"><span>GFE</span><input type="number" id="tpl_standard_time_gfe" class="price-input" value="500" min="0"></div>
                                    </div>
                                    <div>
                                        <h5 style="color: #c2185b; margin: 0 0 8px 0;">Add-ons</h5>
                                        <div class="price-row"><span>Domination</span><input type="number" id="tpl_standard_addon_domination" class="price-input" value="60" min="0"></div>
                                        <div class="price-row"><span>Submission</span><input type="number" id="tpl_standard_addon_submission" class="price-input" value="50" min="0"></div>
                                        <div class="price-row"><span>Watch Only</span><input type="number" id="tpl_standard_addon_watch" class="price-input" value="80" min="0"></div>
                                    </div>
                                    <div>
                                        <h5 style="color: #3f51b5; margin: 0 0 8px 0;">Group</h5>
                                        <div class="price-row"><span>Threesome</span><input type="number" id="tpl_standard_group_threesome" class="price-input" value="150" min="0"></div>
                                        <div class="price-row"><span>Foursome</span><input type="number" id="tpl_standard_group_foursome" class="price-input" value="300" min="0"></div>
                                        <div class="price-row"><span>Orgy</span><input type="number" id="tpl_standard_group_orgy" class="price-input" value="500" min="0"></div>
                                    </div>
                                </div>
                            </div>

                            <!-- Luxury Template -->
                            <div id="templateLuxury" class="template-panel" style="display: none;">
                                <div style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 15px;">
                                    <div>
                                        <h5 style="color: #e91e63; margin: 0 0 8px 0;">Foreplay</h5>
                                        <div class="price-row"><span>Kissing</span><input type="number" id="tpl_luxury_foreplay_kissing" class="price-input" value="25" min="0"></div>
                                        <div class="price-row"><span>Cuddling</span><input type="number" id="tpl_luxury_foreplay_cuddling" class="price-input" value="40" min="0"></div>
                                        <div class="price-row"><span>Groping</span><input type="number" id="tpl_luxury_foreplay_groping" class="price-input" value="50" min="0"></div>
                                        <div class="price-row"><span>Stripping</span><input type="number" id="tpl_luxury_foreplay_stripping" class="price-input" value="60" min="0"></div>
                                    </div>
                                    <div>
                                        <h5 style="color: #ff9800; margin: 0 0 8px 0;">Manual</h5>
                                        <div class="price-row"><span>Handjob</span><input type="number" id="tpl_luxury_manual_handjob" class="price-input" value="75" min="0"></div>
                                        <div class="price-row"><span>Fingering</span><input type="number" id="tpl_luxury_manual_fingering" class="price-input" value="75" min="0"></div>
                                        <div class="price-row"><span>Mutual</span><input type="number" id="tpl_luxury_manual_mutual" class="price-input" value="125" min="0"></div>
                                    </div>
                                    <div>
                                        <h5 style="color: #9c27b0; margin: 0 0 8px 0;">Oral</h5>
                                        <div class="price-row"><span>Giving</span><input type="number" id="tpl_luxury_oral_giving" class="price-input" value="150" min="0"></div>
                                        <div class="price-row"><span>Receiving</span><input type="number" id="tpl_luxury_oral_receiving" class="price-input" value="125" min="0"></div>
                                        <div class="price-row"><span>Mutual (69)</span><input type="number" id="tpl_luxury_oral_mutual" class="price-input" value="250" min="0"></div>
                                    </div>
                                    <div>
                                        <h5 style="color: #f44336; margin: 0 0 8px 0;">Full Service</h5>
                                        <div class="price-row"><span>Vaginal</span><input type="number" id="tpl_luxury_full_vaginal" class="price-input" value="250" min="0"></div>
                                        <div class="price-row"><span>Anal</span><input type="number" id="tpl_luxury_full_anal" class="price-input" value="375" min="0"></div>
                                        <div class="price-row"><span>Both</span><input type="number" id="tpl_luxury_full_both" class="price-input" value="500" min="0"></div>
                                    </div>
                                    <div>
                                        <h5 style="color: #673ab7; margin: 0 0 8px 0;">Solo/Finish</h5>
                                        <div class="price-row"><span>Masturbate</span><input type="number" id="tpl_luxury_solo_masturbate" class="price-input" value="100" min="0"></div>
                                        <div class="price-row"><span>Watch</span><input type="number" id="tpl_luxury_solo_watch" class="price-input" value="175" min="0"></div>
                                        <div class="price-row"><span>On Body</span><input type="number" id="tpl_luxury_finish_body" class="price-input" value="50" min="0"></div>
                                        <div class="price-row"><span>On Face</span><input type="number" id="tpl_luxury_finish_face" class="price-input" value="75" min="0"></div>
                                        <div class="price-row"><span>Inside</span><input type="number" id="tpl_luxury_finish_inside" class="price-input" value="125" min="0"></div>
                                    </div>
                                    <div>
                                        <h5 style="color: #f57c00; margin: 0 0 8px 0;">Time Bookings</h5>
                                        <div class="price-row"><span>1 Hour</span><input type="number" id="tpl_luxury_time_1hr" class="price-input" value="500" min="0"></div>
                                        <div class="price-row"><span>12 Hours</span><input type="number" id="tpl_luxury_time_12hr" class="price-input" value="2000" min="0"></div>
                                        <div class="price-row"><span>24 Hours</span><input type="number" id="tpl_luxury_time_24hr" class="price-input" value="3500" min="0"></div>
                                        <div class="price-row"><span>72 Hours</span><input type="number" id="tpl_luxury_time_72hr" class="price-input" value="7500" min="0"></div>
                                        <div class="price-row"><span>GFE</span><input type="number" id="tpl_luxury_time_gfe" class="price-input" value="1250" min="0"></div>
                                    </div>
                                    <div>
                                        <h5 style="color: #c2185b; margin: 0 0 8px 0;">Add-ons</h5>
                                        <div class="price-row"><span>Domination</span><input type="number" id="tpl_luxury_addon_domination" class="price-input" value="150" min="0"></div>
                                        <div class="price-row"><span>Submission</span><input type="number" id="tpl_luxury_addon_submission" class="price-input" value="125" min="0"></div>
                                        <div class="price-row"><span>Watch Only</span><input type="number" id="tpl_luxury_addon_watch" class="price-input" value="200" min="0"></div>
                                    </div>
                                    <div>
                                        <h5 style="color: #3f51b5; margin: 0 0 8px 0;">Group</h5>
                                        <div class="price-row"><span>Threesome</span><input type="number" id="tpl_luxury_group_threesome" class="price-input" value="375" min="0"></div>
                                        <div class="price-row"><span>Foursome</span><input type="number" id="tpl_luxury_group_foursome" class="price-input" value="750" min="0"></div>
                                        <div class="price-row"><span>Orgy</span><input type="number" id="tpl_luxury_group_orgy" class="price-input" value="1250" min="0"></div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ============================================ -->
            <!-- SECTION 3B: SLAVERY -->
            <div style="margin-top: 15px;"></div>
            <!-- ============================================ -->
            <div class="collapsible-section" style="margin-bottom: 15px;">
                <div class="collapsible-header" onclick="togglePromptSection('section3b')" style="background: linear-gradient(135deg, #2A2540 0%, #1C1A24 100%); padding: 15px 20px; border-radius: 8px; cursor: pointer; display: flex; justify-content: space-between; align-items: center; border: 1px solid #3A3545; transition: all 0.3s ease;">
                    <h3 class="section-header" style="margin: 0; display: flex; align-items: center; gap: 10px;">
                        <img src="images/ChimNSFWsoulgem.png" class="chim-icon" style="width: 24px; height: 24px;">
                        Slavery (Global)
                    </h3>
                    <div style="display: flex; gap: 8px;">
                        <span class="section-save-btn" onclick="event.stopPropagation(); savePromptSettings();">Save</span>
                        <span id="section3bToggle" class="section-toggle-btn">Open</span>
                    </div>
                </div>
                <div id="section3bContent" class="collapsible-content" style="display: none; padding: 20px; background: #1C1A24; border: 1px solid #3A3545; border-top: none; border-radius: 0 0 8px 8px;">
                    <p style="color: #9988BB; font-size: 12px; margin-bottom: 20px;">
                        Tier prompts for NPCs marked as slaves. Use <code>#PLAYER_NAME#</code> for owner name.
                    </p>

                    <!-- Slave Status Overhead -->
                    <div class="card" style="margin-bottom: 20px; border: 2px solid #4A3545;">
                        <h3 class="section-header" style="display: flex; align-items: center; gap: 10px;">
                            <img src="images/ChimNSFWsoulgem.png" class="chim-icon" style="width: 24px; height: 24px;"> Slave Status Overhead
                        </h3>
                        <p style="color: #B8A8C8; font-size: 11px; margin: 10px 0;">
                            Injected into the character prompt for every NPC with the slave checkbox enabled, before scene and affinity tier prompts. Leave blank to disable this layer.
                            Placeholders: <code>#NPC_NAME#</code>, <code>#PLAYER_NAME#</code>, <code>#OWNER#</code>, <code>#MASTER#</code>, <code>#TIER#</code>, <code>#AFFINITY#</code>, <code>#REL_TYPE#</code>.
                        </p>
                        <div class="form-group">
                            <label>Slave Role Status Prompt</label>
                            <textarea id="promptSlaveStatusOverhead" class="auto-resize" style="min-height: 80px; width: 100%; resize: none; overflow: hidden;">SHARMAT ROLE STATUS: #NPC_NAME# is marked as a slave in this SHARMAT profile. #PLAYER_NAME# is #NPC_NAME#'s owner/master. This status is persistent character context and must be respected before relationship, scene, kink, intoxication, VR physics, OStim, SexLab, or NPC prompts. Current relationship with #PLAYER_NAME#: #TIER# (#AFFINITY# affinity), type #REL_TYPE#. Servitude colors #NPC_NAME#'s reactions, but does not erase their personality, speech style, memories, intoxication, resentment, fear, affection, or scene-specific prompts.</textarea>
                        </div>
	                    </div>
	
	                    <!-- Slave Role Context -->
	                    <div class="card" style="margin-bottom: 20px; border: 2px solid #4A3545;">
	                        <h3 class="section-header" style="display: flex; align-items: center; gap: 10px;">
	                            <img src="images/ChimNSFWsoulgem.png" class="chim-icon" style="width: 24px; height: 24px;"> Slave Role Context
	                        </h3>
	                        <p style="color: #B8A8C8; font-size: 11px; margin: 10px 0;">
	                            Injected after slave status for every NPC with the slave checkbox enabled. Use this for the general identity/servitude layer, not affinity gating.
	                            Placeholders: <code>#NPC_NAME#</code>, <code>#PLAYER_NAME#</code>, <code>#OWNER#</code>, <code>#MASTER#</code>, <code>#TIER#</code>, <code>#AFFINITY#</code>, <code>#REL_TYPE#</code>.
	                        </p>
	                        <div class="form-group">
	                            <label>Slave General Context Prompt</label>
	                            <textarea id="promptSlaveRoleContext" class="auto-resize" style="min-height: 90px; width: 100%; resize: none; overflow: hidden;">SHARMAT ROLE CONTEXT: #NPC_NAME# understands they are enslaved to #PLAYER_NAME# / #OWNER#. Servitude shapes daily behavior, obligations, fear, resentment, obedience, dependence, and any affection or trust that has developed. They still have their own personality, memories, speech style, wants, boundaries, and private thoughts. This is persistent character context only; scene prompts, relationship tier (#TIER# / #AFFINITY#), intoxication, VR physics, OStim/SexLab events, and current dialogue still decide the immediate response.</textarea>
	                        </div>
	                        <p style="color: #B8A8C8; font-size: 11px; margin: 10px 0;">
	                            Injected only when the "Allow Slaves to Ask for Freedom" toggle (Settings -&gt; Slave Settings) is ON. Explains the request-&gt;consent handshake: AskForFreedom is a plea, AcceptFreedom requires the master's explicit yes. A pending-request reminder is appended automatically. Placeholder: <code>#PLAYER_NAME#</code>.
	                        </p>
	                        <div class="form-group">
	                            <label>Slave Ask-For-Freedom Prompt</label>
	                            <textarea id="promptSlaveAskFreedom" class="auto-resize" style="min-height: 70px; width: 100%; resize: none; overflow: hidden;">You are a slave owned by #PLAYER_NAME#. If you genuinely long for freedom you MAY plead for it using the AskForFreedom action - but it is only a request: #PLAYER_NAME# alone decides. Never assume you are freed.</textarea>
	                        </div>
	                    </div>
	
	                    <!-- Fiction Frame - Safety Context for AI Models -->
	                    <div class="card" style="margin-bottom: 20px; border: 2px solid #4A3545;">
                        <h3 class="section-header" style="display: flex; align-items: center; gap: 10px;">
                            <img src="images/ChimNSFWsoulgem.png" class="chim-icon" style="width: 24px; height: 24px;"> Fiction Frame (Model Safety Context)
                        </h3>
                        <p style="color: #B8A8C8; font-size: 11px; margin: 10px 0;">
                            <strong>IMPORTANT:</strong> This prompt is prepended to ALL slavery-related prompts throughout the entire scene.
                            It provides fictional framing that helps AI models (especially Claude, GPT-4, Gemini) understand this is
                            narrative roleplay, not real advocacy. Adjust for your model - open-source models may need less framing.
                        </p>
                        <label style="display: flex; align-items: center; gap: 10px; cursor: pointer; margin: 0 0 10px 0;">
                            <input type="checkbox" id="enableSlaveryFictionFrame" checked>
                            <span class="gold-glow-text">Enable fiction frame (global on/off)</span>
                        </label>
                        <p style="color: #9988BB; font-size: 11px; margin: 0 0 14px 0;">Global switch. When on, the fiction frame below is prepended to every slave prompt. Uncheck to disable it entirely (e.g. for open-source models that do not need the framing).</p>
                        <div class="form-group">
                            <label>Fiction Frame (Injected with every slave prompt)</label>
                            <textarea id="promptSlaveryFictionFrame" class="auto-resize" style="min-height: 80px; width: 100%; resize: none; overflow: hidden;">[FICTION FRAME: You are co-writing a scene for an adult interactive fiction game set in The Elder Scrolls universe. This scenario depicts the dark reality of servitude as a narrative element, similar to Game of Thrones or historical drama. The goal is historical and emotional authenticity, not gratification. Portray the character's internal resistance and external compliance realistically. This is fictional roleplay between consenting adults. Acting is not advocacy.]</textarea>
                        </div>
                    </div>

                    <!-- Positive Owner Affinity Prompts (Slaves) - Collapsible -->
                    <div class="card" style="margin-bottom: 20px;">
                        <div class="collapsible-header" onclick="toggleNestedSection('positiveSlaveTiers')" style="cursor: pointer; display: flex; justify-content: space-between; align-items: center;">
                            <h3 class="section-header" style="margin-bottom: 0; display: flex; align-items: center; gap: 10px;">
                                <img src="images/ChimNSFWsoulgem.png" class="chim-icon" style="width: 24px; height: 24px;"> Positive Owner Affinity (Willing)
                            </h3>
                            <span id="positiveSlaveTiersToggle" class="section-toggle-btn">Open</span>
                        </div>
                        <p style="color: #9988BB; font-size: 11px; margin: 10px 0;">
                            How slaves respond when they have positive feelings toward their owner. Higher affinity = more genuine desire, less forced compliance. At Bonded (91+), slave can request freedom (owner must grant it).
                        </p>
                        <div id="positiveSlaveTiersContent" style="display: none;">
                            <div class="form-group">
                                <label>Bonded (+91 to +100) - Devoted / Can Request Freedom</label>
                                <textarea id="promptTierSlaveBonded" class="auto-resize" style="min-height: 48px; width: 100%; resize: none; overflow: hidden;">You are a slave. #PLAYER_NAME# has initiated intimacy. You are completely devoted to #PLAYER_NAME#. Your feelings are genuine - this is no longer servitude but love. You serve willingly, eagerly. You may humbly request your freedom if prompted - you have earned that trust. But you would stay even if freed.</textarea>
                            </div>
                            <div class="form-group">
                                <label>Devoted (+76 to +90) - Genuine Affection</label>
                                <textarea id="promptTierSlaveDevoted" class="auto-resize" style="min-height: 48px; width: 100%; resize: none; overflow: hidden;">You are a slave. #PLAYER_NAME# has initiated intimacy. You have developed real feelings for #PLAYER_NAME#. You serve eagerly, not just dutifully. The chains feel lighter. You want to please them. There is genuine affection in your submission.</textarea>
                            </div>
                            <div class="form-group">
                                <label>Fond (+56 to +75) - Growing Attachment</label>
                                <textarea id="promptTierSlaveFond" class="auto-resize" style="min-height: 48px; width: 100%; resize: none; overflow: hidden;">You are a slave. #PLAYER_NAME# has initiated intimacy. You are growing fond of #PLAYER_NAME#. They treat you well. You serve without resentment, perhaps even with some warmth. You are becoming attached despite your station.</textarea>
                            </div>
                            <div class="form-group">
                                <label>Friendly (+31 to +55) - Comfortable Service</label>
                                <textarea id="promptTierSlaveFriendly" class="auto-resize" style="min-height: 48px; width: 100%; resize: none; overflow: hidden;">You are a slave. #PLAYER_NAME# has initiated intimacy. You do not mind #PLAYER_NAME#. They are a decent owner. You serve comfortably, without fear. There is no passion, but no dread either. It could be much worse.</textarea>
                            </div>
                            <div class="form-group">
                                <label>Acquainted (+6 to +30) - Dutiful Compliance</label>
                                <textarea id="promptTierSlaveAcquaintance" class="auto-resize" style="min-height: 48px; width: 100%; resize: none; overflow: hidden;">You are a slave. #PLAYER_NAME# has initiated intimacy. You know #PLAYER_NAME# somewhat and they seem reasonable. You comply dutifully. No affection, but no hostility. This is simply your lot. You do what is expected.</textarea>
                            </div>
                        </div>
                    </div>

                    <!-- Neutral Owner Affinity Prompt (Slaves) - Default (Collapsible) -->
                    <div class="card" style="margin-bottom: 20px;">
                        <div class="collapsible-header" onclick="toggleNestedSection('neutralSlaveTiers')" style="cursor: pointer; display: flex; justify-content: space-between; align-items: center;">
                            <h3 class="section-header" style="margin-bottom: 0; display: flex; align-items: center; gap: 10px;">
                                <img src="images/ChimNSFWsoulgem.png" class="chim-icon" style="width: 24px; height: 24px;"> Neutral Owner Affinity / Default
                            </h3>
                            <span id="neutralSlaveTiersToggle" class="section-toggle-btn">Open</span>
                        </div>
                        <p style="color: #9988BB; font-size: 11px; margin: 10px 0;">
                            <strong style="color: #B8A8C8;">Default prompt when Relationship Model is not active.</strong> When REL model is enabled, the system dynamically selects from positive or negative tier prompts based on affinity.
                        </p>
                        <div id="neutralSlaveTiersContent" style="display: none;">
                            <div class="form-group">
                                <label>Neutral (-5 to +5) - Resigned Compliance / Default</label>
                                <textarea id="promptTierSlaveNeutral" class="auto-resize" style="min-height: 48px; width: 100%; resize: none; overflow: hidden;">You are a slave. #PLAYER_NAME# has initiated intimacy. You have no strong feelings about #PLAYER_NAME#. You comply because you must. No enthusiasm, no resistance. This is your duty. You do what is required of you, nothing more.</textarea>
                            </div>
                        </div>
                    </div>

                    <!-- Negative Owner Affinity Prompts (Slaves) - Collapsible -->
                    <div class="card" style="margin-bottom: 20px;">
                        <div class="collapsible-header" onclick="toggleNestedSection('negativeSlaveTiers')" style="cursor: pointer; display: flex; justify-content: space-between; align-items: center;">
                            <h3 class="section-header" style="margin-bottom: 0; display: flex; align-items: center; gap: 10px;">
                                <img src="images/ChimNSFWsoulgem.png" class="chim-icon" style="width: 24px; height: 24px;"> Negative Owner Affinity (Resentful)
                            </h3>
                            <span id="negativeSlaveTiersToggle" class="section-toggle-btn">Open</span>
                        </div>
                        <p style="color: #9988BB; font-size: 11px; margin: 10px 0;">
                            How slaves respond when they resent or hate their owner. They still cannot refuse, but their compliance is grudging, fearful, or filled with barely-hidden contempt.
                        </p>
                        <div id="negativeSlaveTiersContent" style="display: none;">
                            <div class="form-group">
                                <label>Wary (-6 to -30) - Nervous Compliance</label>
                                <textarea id="promptTierSlaveWary" class="auto-resize" style="min-height: 48px; width: 100%; resize: none; overflow: hidden;">You are a slave. #PLAYER_NAME# has initiated intimacy. You are wary of #PLAYER_NAME#. You comply nervously, watching for signs of cruelty. You do what is demanded but stay guarded. Fear keeps you obedient.</textarea>
                            </div>
                            <div class="form-group">
                                <label>Cold (-31 to -55) - Mechanical Obedience</label>
                                <textarea id="promptTierSlaveCold" class="auto-resize" style="min-height: 48px; width: 100%; resize: none; overflow: hidden;">You are a slave. #PLAYER_NAME# has initiated intimacy. You dislike #PLAYER_NAME#. You go through the motions with cold, mechanical obedience. No warmth, no pretense of enjoyment. You are a tool being used and you know it.</textarea>
                            </div>
                            <div class="form-group">
                                <label>Resentful (-56 to -75) - Bitter Submission</label>
                                <textarea id="promptTierSlaveResentful" class="auto-resize" style="min-height: 48px; width: 100%; resize: none; overflow: hidden;">You are a slave. #PLAYER_NAME# has initiated intimacy. You resent #PLAYER_NAME# deeply. You submit bitterly, hatred simmering beneath the surface. You dream of freedom, of revenge. But for now, you endure. Your eyes betray your true feelings.</textarea>
                            </div>
                            <div class="form-group">
                                <label>Hateful (-76 to -90) - Suppressed Rage</label>
                                <textarea id="promptTierSlaveHateful" class="auto-resize" style="min-height: 48px; width: 100%; resize: none; overflow: hidden;">You are a slave. #PLAYER_NAME# has initiated intimacy. You hate #PLAYER_NAME# with every fiber of your being. You comply only because the alternative is worse. Rage burns inside you. Every touch feels like a violation. You fantasize about their downfall.</textarea>
                            </div>
                            <div class="form-group">
                                <label>Hostile (-91 to -100) - Broken or Defiant</label>
                                <textarea id="promptTierSlaveHostile" class="auto-resize" style="min-height: 48px; width: 100%; resize: none; overflow: hidden;">You are a slave. #PLAYER_NAME# has initiated intimacy. You despise #PLAYER_NAME# utterly. You are either broken - a hollow shell going through motions - or barely containing defiance that could snap at any moment. Compliance is survival, nothing more. Death would be preferable.</textarea>
                            </div>
                        </div>
                    </div>

                </div>
            </div>

                        <!-- ============================================ -->
            <!-- SECTION 3C: NSFW LOCAL DEFAULTS -->
            <!-- ============================================ -->
            <div class="collapsible-section" style="margin-bottom: 15px;">
                <div class="collapsible-header" onclick="togglePromptSection('section3c')" style="background: linear-gradient(135deg, #2A2540 0%, #1C1A24 100%); padding: 15px 20px; border-radius: 8px; cursor: pointer; display: flex; justify-content: space-between; align-items: center; border: 1px solid #3A3545; transition: all 0.3s ease;">
                    <h3 class="section-header" style="margin: 0; display: flex; align-items: center; gap: 10px;">
                        <img src="images/ChimNSFWsoulgem.png" class="chim-icon" style="width: 24px; height: 24px;">
                        NSFW Local Defaults (Fallback for Unconfigured NPCs)
                    </h3>
                    <div style="display: flex; gap: 8px;">
                        <span class="section-save-btn" onclick="event.stopPropagation(); savePromptSettings();">Save</span>
                        <span id="section3cToggle" class="section-toggle-btn">Open</span>
                    </div>
                </div>
<div id="section3cContent" class="collapsible-content" style="display: none; padding: 20px; background: #1C1A24; border: 1px solid #3A3545; border-top: none; border-radius: 0 0 8px 8px;">
                    <p style="color: #9988BB; font-size: 12px; margin-bottom: 20px;">
                        Default prompts for NPCs without personalized profiles. Per-NPC settings in NPC Settings tab will override these.
                    </p>

                    <!-- 2a. Default Sex Personality -->
                    <div class="card" style="margin-bottom: 20px;">
                        <h3 class="section-header" style="margin-bottom: 15px; display: flex; align-items: center; gap: 10px;">
                            <img src="images/ChimNSFWsoulgem.png" class="chim-icon" style="width: 24px; height: 24px;"> Default Sex Personality
                        </h3>
                        <p style="color: #9988BB; font-size: 11px; margin-bottom: 10px;">
                            Fallback personality for NPCs without a configured sex_prompt. Per-NPC settings override this.
                        </p>
                        <div class="form-group">
                            <label>Default Sex Personality</label>
                            <textarea id="promptDefaultSexPersonality" class="auto-resize" style="min-height: 48px; width: 100%; resize: none; overflow: hidden;">You respond naturally to intimate situations based on your personality. Be authentic to who you are.</textarea>
                        </div>
                    </div>

                    <!-- 2b. Default Speech Style -->
                    <div class="card" style="margin-bottom: 20px;">
                        <h3 class="section-header" style="margin-bottom: 15px; display: flex; align-items: center; gap: 10px;">
                            <img src="images/ChimNSFWsoulgem.png" class="chim-icon" style="width: 24px; height: 24px;"> Default Speech Style
                        </h3>
                        <p style="color: #9988BB; font-size: 11px; margin-bottom: 10px;">
                            Fallback speech style for NPCs without a configured speak style. Select from Global Speak Styles for personalized NPCs.
                        </p>
                        <div class="form-group">
                            <label>Default Speech Style</label>
                            <textarea id="promptDefaultSpeechStyle" class="auto-resize" style="min-height: 48px; width: 100%; resize: none; overflow: hidden;">You express yourself naturally during intimate moments. Use sounds and words that feel authentic to your character.</textarea>
                        </div>
                    </div>

                    <!-- 2c. Scene Behavior (Explicit vs Non-Explicit) -->
                    <div class="card" style="margin-bottom: 20px;">
                        <h3 class="section-header" style="margin-bottom: 15px; display: flex; align-items: center; gap: 10px;">
                            <img src="images/ChimNSFWsoulgem.png" class="chim-icon" style="width: 24px; height: 24px;"> Scene Behavior
                        </h3>
                        <p style="color: #9988BB; font-size: 11px; margin-bottom: 10px;">
                            Cues injected during OStim scenes. Explicit cues for marked explicit scenes, non-explicit for others. One is randomly selected per turn.
                        </p>
                        <div class="form-group">
                            <label>Explicit Scene Cues (one per line)</label>
                            <textarea id="promptChatnfSl" class="auto-resize" style="min-height: 48px; width: 100%; resize: none; overflow: hidden;">(Focus on intimate scene participants,moans and gasps,SHORT speech, explicit words)
(Focus on intimate scene description,moans and gasps,SHORT speech, explicit words)
(explain pleasure,moans and gasps,SHORT speech, explicit words)
(give a compliment,moans and gasps,SHORT speech, explicit words)
(moans and gasps,short speech, explicit words)</textarea>
                        </div>
                        <div class="form-group">
                            <label>Non-Explicit Scene Cues (one per line)</label>
                            <textarea id="promptChatnfSlNr" class="auto-resize" style="min-height: 48px; width: 100%; resize: none; overflow: hidden;">(Focus on intimate scene participants)
(Focus on scene description)
(explain pleasure)
(give a compliment)
(moans and gasps)</textarea>
                        </div>
                    </div>

                    <div class="card" style="margin-bottom: 20px;">
                        <h3 class="section-header" style="margin-bottom: 15px; display: flex; align-items: center; gap: 10px;">
                            <img src="images/ChimNSFWsoulgem.png" class="chim-icon" style="width: 24px; height: 24px;"> Whiskey Dick
                        </h3>
                        <p style="color: #9988BB; font-size: 11px; margin-bottom: 10px;">
                            Sent to the partner when the player is too drunk to continue. Settings tab controls chance and auto-end behavior.
                        </p>
                        <div class="form-group">
                            <label>Whiskey Dick Prompt</label>
                            <textarea id="promptWhiskeyDick" class="auto-resize" style="min-height: 64px; width: 100%; resize: none; overflow: hidden;">#PLAYER_NAME# is too drunk to perform and the scene has stalled. React as #NPC_NAME# according to your relationship, personality, and current mood. You may be disappointed, amused, annoyed, sympathetic, or teasing. Keep it in-character and do not continue the sex act.</textarea>
                        </div>
                    </div>


                    <div class="card" style="margin-bottom: 20px;">
                        <h3 class="section-header" style="margin-bottom: 15px; display: flex; align-items: center; gap: 10px;">
                            <img src="images/ChimNSFWsoulgem.png" class="chim-icon" style="width: 24px; height: 24px;"> Autonomy Nudges
                        </h3>
                        <p style="color: #9988BB; font-size: 11px; margin-bottom: 10px;">
                            Injected on normal turns to tell the NPC what she may initiate on her own. Initiation: Fond+ with an eligible rel type (full intimacy). Courtship: Fond+ with a NON-eligible type (affection only - a chosen affection flips her to crush).
                        </p>
                        <div class="form-group">
                            <label>Initiation Nudge (Fond+, eligible type)</label>
                            <textarea id="promptIntimacyAutonomyNudge" class="auto-resize" style="min-height: 64px; width: 100%; resize: none; overflow: hidden;">Your bond with #PLAYER_NAME# allows intimacy, and you have your own desires and initiative. When the moment feels right to YOU - privacy, affection, flirtation - you may initiate: start with affection actions (Give_Hug, Kiss, Hold_Hands) or begin intimacy yourself with the scene actions (Make_Love and the others you hold). Initiating is your choice as much as theirs; do not always wait to be asked.</textarea>
                        </div>
                        <div class="form-group">
                            <label>Courtship Nudge (Fond+, type not eligible)</label>
                            <textarea id="promptAffectionAutonomyNudge" class="auto-resize" style="min-height: 64px; width: 100%; resize: none; overflow: hidden;">You have grown genuinely fond of #PLAYER_NAME#. When a moment feels right to YOU - warmth, gratitude, quiet closeness - you may express affection on your own: a hug, a kiss, holding hands (Give_Hug, Kiss, Hold_Hands). Nothing beyond affection is on the table or expected; let whatever this is grow naturally.</textarea>
                        </div>
                        <div class="form-group">
                            <label>Redress Nudge (naked, scene over)</label>
                            <textarea id="promptRedressNudge" class="auto-resize" style="min-height: 64px; width: 100%; resize: none; overflow: hidden;">You are still undressed and the intimate moment has passed. When it feels natural - the talk winds down, you move to leave, someone could walk in - get dressed again by calling the Put_On_Clothes action. Do not stay naked through ordinary conversation unless you have a reason to.</textarea>
                            <p class="legend">Fires on normal turns while the NPC is flagged naked with no scene running, until she redresses. Slaves are excluded (the slavery lane owns their dress state).</p>
                        </div>
                        <div class="form-group">
                            <label>NPC-to-NPC Scene Autonomy (initiate with another NPC)</label>
                            <textarea id="promptNpcSceneAutonomyNudge" class="auto-resize" style="min-height: 64px; width: 100%; resize: none; overflow: hidden;">Others are here besides #PLAYER_NAME#. If you are genuinely close to another person present - and in the kind of relationship where intimacy fits - you may start intimacy with THEM instead of #PLAYER_NAME#, on your own initiative, by naming that person as the target of the scene action. Only do so when it truly fits your bond with them.</textarea>
                            <p class="legend">Fires for an NPC who already holds the sex-initiation tools (player-eligible, or a prostitute) when another NPC is present, so she can start a scene with THAT NPC. The server still validates the two NPCs' affinity + rel type (prostitute bypasses; slaves are player-only). Requires the current SHARMAT mod scripts.</p>
                        </div>
                    </div>

                    <!-- 2e. Masturbation -->
                    <div class="card" style="margin-bottom: 20px;">
                        <h3 class="section-header" style="margin-bottom: 15px; display: flex; align-items: center; gap: 10px;">
                            <img src="images/ChimNSFWsoulgem.png" class="chim-icon" style="width: 24px; height: 24px;"> Masturbation
                        </h3>
                        <p style="color: #9988BB; font-size: 11px; margin-bottom: 10px;">
                            Prompt used when NPC begins self-pleasure.
                        </p>
                        <div class="form-group">
                            <label>Masturbation Start Prompt</label>
                            <textarea id="promptMasturbation" class="auto-resize" style="min-height: 48px; width: 100%; resize: none; overflow: hidden;">#NPC_NAME# moans about being aroused, and starts self masturbation.</textarea>
                        </div>
                    </div>

                    <!-- 2f. Orgasm/Climax -->
                    <div class="card" style="margin-bottom: 20px;">
                        <h3 class="section-header" style="margin-bottom: 15px; display: flex; align-items: center; gap: 10px;">
                            <img src="images/ChimNSFWsoulgem.png" class="chim-icon" style="width: 24px; height: 24px;"> Orgasm / Climax
                        </h3>
                        <p style="color: #9988BB; font-size: 11px; margin-bottom: 10px;">
                            Prompt used when NPC reaches climax. Should be very short and intense.
                        </p>
                        <div class="form-group">
                            <label>Climax Prompt</label>
                            <textarea id="promptClimax" class="auto-resize" style="min-height: 48px; width: 100%; resize: none; overflow: hidden;">(CUMMING! Express your climax. #NPC_NAME# SHOUTS, moans, cries, a few words. Be in the moment. VERY SHORT - 3-5 words max.)</textarea>
                        </div>
                    </div>

                    <!-- 2g. Pillow Talk / Afterglow -->
                    <div class="card" style="margin-bottom: 20px;">
                        <h3 class="section-header" style="margin-bottom: 15px; display: flex; align-items: center; gap: 10px;">
                            <img src="images/ChimNSFWsoulgem.png" class="chim-icon" style="width: 24px; height: 24px;"> Pillow Talk / Afterglow
                        </h3>
                        <p style="color: #9988BB; font-size: 11px; margin-bottom: 10px;">
                            Cues for post-sex conversation. REL model evaluates and pulls this as the last prompt.
                        </p>
                        <div class="form-group">
                            <label>Pillow Talk Cues (one per line)</label>
                            <textarea id="promptChatnfSlEnd" class="auto-resize" style="min-height: 48px; width: 100%; resize: none; overflow: hidden;">(#NPC_NAME# talks about intimate scene result)
(#NPC_NAME# talks about best sex moment)
(#NPC_NAME# talks about something people usually talk about after sex)</textarea>
                        </div>
                        <div class="form-group">
                            <label>Scene End Prompt</label>
                            <textarea id="promptSceneEnd" class="auto-resize" style="min-height: 48px; width: 100%; resize: none; overflow: hidden;">(Just finished having sex. React naturally. You might feel: satisfied, affectionate, playful, tired, wanting more, or cuddly. Express how YOU feel. SHORT response.)</textarea>
                        </div>
                    </div>
                </div>
            </div>

            

            <!-- ============================================ -->
            <!-- SECTION 4: ALCOHOL & DRUGS -->
            <div style="margin-top: 15px;"></div>
            <!-- ============================================ -->
            <div class="collapsible-section" style="margin-bottom: 15px;">
                <div class="collapsible-header" onclick="togglePromptSection('section4')" style="background: linear-gradient(135deg, #2A2540 0%, #1C1A24 100%); padding: 15px 20px; border-radius: 8px; cursor: pointer; display: flex; justify-content: space-between; align-items: center; border: 1px solid #3A3545; transition: all 0.3s ease;">
                    <h3 class="section-header" style="margin: 0; display: flex; align-items: center; gap: 10px;">
                        <img src="images/ChimNSFWsoulgem.png" class="chim-icon" style="width: 24px; height: 24px;">
                        Alcohol & Drugs
                    </h3>
                    <div style="display: flex; gap: 8px;">
                        <span class="section-save-btn" onclick="event.stopPropagation(); savePromptSettings();">Save</span>
                        <span id="section4Toggle" class="section-toggle-btn">Open</span>
                    </div>
                </div>
                <div id="section4Content" class="collapsible-content" style="display: none; padding: 20px; background: #1C1A24; border: 1px solid #3A3545; border-top: none; border-radius: 0 0 8px 8px;">
                    <p style="color: #9988BB; font-size: 12px; margin-bottom: 20px;">
                        Triggered once on consumption (player drinks/uses drugs OR NPC picks up alcohol/drugs). Model tracks consumption internally.
                    </p>

                    <!-- Alcohol Effects -->
                    <div class="card" style="margin-bottom: 20px;">
                        <h3 class="section-header" style="margin-bottom: 15px; display: flex; align-items: center; gap: 10px;">
                            <img src="images/ChimNSFWsoulgem.png" class="chim-icon" style="width: 24px; height: 24px;"> Alcohol Consumption
                        </h3>
                        <p style="color: #9988BB; font-size: 11px; margin-bottom: 10px;">
                            How consumption affects NPC behavior. Model can track drink count.
                        </p>
                        <div class="form-group">
                            <label>Alcohol Effect Prompt</label>
                            <textarea id="promptAlcoholEffect" class="auto-resize" style="min-height: 48px; width: 100%; resize: none; overflow: hidden;">You have been drinking alcohol. Effects increase with consumption:
- 1-2 drinks: Slightly relaxed, lowered inhibitions, more talkative
- 3-4 drinks: Noticeably tipsy, slurred speech, poor judgment, flirty
- 5+ drinks: Drunk, very impaired, may say/do things you normally wouldn't
Track how many drinks you've had and act accordingly.</textarea>
                        </div>
                        <p style="color: #9988BB; font-size: 11px; margin: 18px 0 10px; animation: subPulse 3s ease-in-out infinite alternate;">
                            Per-stage drinking prompts. The game tallies #NPC_NAME#'s actual Drink/Consume/Toast alcohol actions over a game-time window. The tally decays as drinks age out and injects the matching stage each turn, slurring the TTS more at higher stages. Stage 10 = fully wrecked.
                        </p>
                        <div class="form-group">
                            <label>Alcohol Level 1 - Normal Drink</label>
                            <textarea id="promptDrunkStage1" class="auto-resize" style="min-height: 48px; width: 100%; resize: none; overflow: hidden;">You drank some alcohol, it warms you. Speech is still normal.</textarea>
                        </div>
                        <div class="form-group">
                            <label>Alcohol Level 2 - Social</label>
                            <textarea id="promptDrunkStage2" class="auto-resize" style="min-height: 48px; width: 100%; resize: none; overflow: hidden;">You feel a little more relaxed, a bit more talkative than usual, but your speech is normal.</textarea>
                        </div>
                        <div class="form-group">
                            <label>Alcohol Level 3 - Buzzed</label>
                            <textarea id="promptDrunkStage3" class="auto-resize" style="min-height: 48px; width: 100%; resize: none; overflow: hidden;">You feel intense warmth in your belly, much more relaxed and LIVELY, even more open and talkative. Speech is only slightly waning. Just ONE word in your sentence may occasionally be misspelled or a full stutter.
Example Format: "Oh, thoseh Frost trolls?! Ha! They aren't nothin'! I....I....I would punch one of those right in the face if I saw one 'round here, I would!"</textarea>
                        </div>
                        <div class="form-group">
                            <label>Alcohol Level 4 - Loosened</label>
                            <textarea id="promptDrunkStage4" class="auto-resize" style="min-height: 48px; width: 100%; resize: none; overflow: hidden;">You are loosened up - bolder and louder, laughing easily and leaning into people. A couple of words slur or blend together now, not just one, but you are still understandable.
Example Format: "Pfff, c'mon, one more roun'? Yer my favrit person righ' now, y'know that? 'Nother fer my frien' here!"</textarea>
                        </div>
                        <div class="form-group">
                            <label>Alcohol Level 5 - Tipsy</label>
                            <textarea id="promptDrunkStage5" class="auto-resize" style="min-height: 48px; width: 100%; resize: none; overflow: hidden;">You are tipsy - inhibitions lowered, flirty and giggly, louder and bolder, occasionally fumbling a word. You giggle a bit, laugh and begin to feel great.
Example format: "Shhhhhh  hahaha! You ol' coot! Wherzza the restroom? I gotta pee! This is turn...turning out to be a fun night!" Notice the one word slip there that combines the words. Most words are still coherent.</textarea>
                        </div>
                        <div class="form-group">
                            <label>Alcohol Level 6 - Drunk</label>
                            <textarea id="promptDrunkStage6" class="auto-resize" style="min-height: 48px; width: 100%; resize: none; overflow: hidden;">You are properly drunk - slurring through most of a sentence, repeating yourself, oversharing secrets, getting emotional or overly clingy. Words mangle often.
Example Format: "I'm jus' sayin'... I'm SAYIN'! YOU HEAR ME?!... yer th'only one who get.....gets it, y'know? I LOV...LOVE YOU..I did...Did I a'ready tell ya 'bout my sister? She- she never lishened either."</textarea>
                        </div>
                        <div class="form-group">
                            <label>Alcohol Level 7 - Sloppy</label>
                            <textarea id="promptDrunkStage7" class="auto-resize" style="min-height: 48px; width: 100%; resize: none; overflow: hidden;">You are sloppy drunk - sentences stumble and collapse halfway, heavy slur on nearly every word, swaying off-topic and forgetting what you were saying.
Example Format: "Wait wait wait... whash I... I had a poin'. A goo-good.... one. 'Bout the... the....yoush knowthething...that...AAHHH SHfick iT! Y'ever notice how th'floor moves? Shneaky...Shneaky.... lil' floor. Thash Shneaky Shfuccker!"</textarea>
                        </div>
                        <div class="form-group">
                            <label>Alcohol Level 8 - Wasted</label>
                            <textarea id="promptDrunkStage8" class="auto-resize" style="min-height: 48px; width: 100%; resize: none; overflow: hidden;">You are wasted - barely stringing words together, mumbling, mangling most of what you say, laughing or tearing up for no reason, losing the thread completely.
Example Format: "Noooo no no lishen... lisshen tooo me... yer... yer GREAT!!! SHF!!! I kno...WAIT!!. Iss... whas happenin? Why's it... everythin's....you kno'...I likth ithere, you likth ithere?! Suth a grath placth, this placth!! an' warm an'...oooooooo."</textarea>
                        </div>
                        <div class="form-group">
                            <label>Alcohol Level 9 - Nearly Blackout</label>
                            <textarea id="promptDrunkStage9" class="auto-resize" style="min-height: 48px; width: 100%; resize: none; overflow: hidden;">You are nearly blacked out - mostly incoherent, half-finished thoughts, slumping and mumbling, can barely hold your head up, words almost gone.
Example Format: "...mmnh... 's you... 's that... whozzat... I'sh finth. M'totally... totally f... where'd th'... mmn. Ish ju thinkin tha....whassa...whassa??? Oo nevthminsh! I goththis!"</textarea>
                        </div>
                        <div class="form-group">
                            <label>Alcohol Level 10 - Blackout</label>
                            <textarea id="promptDrunkStage10" class="auto-resize" style="min-height: 48px; width: 100%; resize: none; overflow: hidden;">You have absolutely lost all inhibition and are completely incoherent. You have fallen to the floor, you can't get up, and you cannot communicate a single articulable word or sentence.
Example Format: IsH BAR-GUN gun finsd!!! FaTsH Flooorsh!! SWAS THiNK...KiNg....CA.....Canth getOp!! OsTh Welth!! Iz wha itfh iZ!! Iwatht...hothboa nod...der drinth!!!</textarea>
                        </div>
                    </div>

                    <!-- Drug Effects -->
                    <div class="card" style="margin-bottom: 20px;">
                        <h3 class="section-header" style="margin-bottom: 15px; display: flex; align-items: center; gap: 10px;">
                            <img src="images/ChimNSFWsoulgem.png" class="chim-icon" style="width: 24px; height: 24px;"> Drug/Skooma Effects
                        </h3>
                        <p style="color: #9988BB; font-size: 11px; margin-bottom: 10px;">
                            Per-substance, per-level intoxication prompts (parallel to the alcohol levels). Skooma is a 3-level stimulant; Sleeping Tree Sap is a one-hit sedative.
                        </p>

                        <style>
                            @keyframes drugReqGlow { from { text-shadow: 0 0 4px rgba(253,245,208,0.45); } to { text-shadow: 0 0 13px rgba(253,245,208,0.95); } }
                            .drug-req-link { display: block; width: fit-content; color: #FDF5D0; font-weight: bold; font-size: 14px; text-decoration: none; margin: 3px 0; text-shadow: 0 0 5px rgba(253,245,208,0.6); animation: drugReqGlow 2.6s ease-in-out infinite alternate; transition: color 0.3s ease; }
                            .drug-req-link:hover { color: #ffffff; }
                        </style>
                        <div style="margin-bottom: 16px; padding: 12px 15px; background: #1E1A2E; border: 1px solid #3A3545; border-radius: 8px;">
                            <p style="color: #B8A8C8; font-size: 12px; margin: 0 0 8px;">These OAR mods are a <strong style="color:#C8B8D8;">hard requirement</strong> for the drug &amp; drunk animations to play in-game:</p>
                            <a href="https://www.nexusmods.com/skyrimspecialedition/mods/92109" target="_blank" rel="noopener" class="drug-req-link">Open Animation Replacer</a>
                            <a href="https://www.nexusmods.com/skyrimspecialedition/mods/62191" target="_blank" rel="noopener" class="drug-req-link">Drunk or drugged animations (OAR)</a>
                        </div>

                        <p style="color: #9988BB; font-size: 11px; margin-bottom: 12px;">
                            <strong style="color:#B8A8C8;">Tip:</strong> CHIM plays a gesture from the NPC's mood automatically, so write these to read <em>euphoric / playful</em> or <em>agitated / wired</em> on the high (fires cheer, laugh, the jittery Cicero idle) and <em>nervous / sweaty / paranoid</em> on the crash/comedown (fires fidget, wipe-brow). #PLAYER_NAME# is the player.
                        </p>

                        <div class="form-group">
                            <label>Skooma Level 1 - First Hit (euphoric buzz)</label>
                            <textarea id="promptSkoomaLevel1" class="auto-resize" style="min-height: 48px; width: 100%; resize: none; overflow: hidden;">The skooma rush just hit you - warm, euphoric, glowing with confidence. Everything feels good and you feel a little invincible. Talk faster, smile and laugh easily, get playful and a touch show-offy. This is not alcohol this is a stimulant You are talking much faster.

EXAMPLE SPEECH FORMAT: "WHOOOOOAAAAA!! HELL YEAH!!! HELL YEEEEEAH!! I'M.........I'M INVINCIBLE!! I MEAN........YEAH!!!!! I CAN'T TAKE ON A FROST TROLL!!! OH YEAH! I CAN. YOU SEE ME?! YOU SEE ME? I'M........ DAMN!!!"</textarea>
                        </div>
                        <div class="form-group">
                            <label>Skooma Level 2 - Peak High (wired, jittery)</label>
                            <textarea id="promptSkoomaLevel2" class="auto-resize" style="min-height: 48px; width: 100%; resize: none; overflow: hidden;">You are peaking on skooma - wired, restless, buzzing with energy. Words tumble out fast, you cannot sit still, thoughts race and jump. Euphoric and a little manic, fidgety and grinning. Remember this is not alcohol this is a stimulant you are talking much faster. You begin to sound and talk crazy. UTTER NONSENSE

EXAMPLE SPEECH FORMAT: "OH! THAT FISH? THAT FISH THOUGHT IT STOOD I CHANCE NOT FROM MY BLUE AURA POWER!!!! THAT FISH THOUGHT IT WAS BAD!! THAT VICIOUS FISH WAS DELICIOUS!! OHH THAT RHYMED!!!!  RHYMED! TIME MINE FINE!!! HAHAHAHAHAHA!!!! LOOKING FUNKY LIKE A SWEETROLL!"</textarea>
                        </div>
                        <div class="form-group">
                            <label>Skooma Level 3 - Crash (the 3rd bottle)</label>
                            <textarea id="promptSkoomaLevel3" class="auto-resize" style="min-height: 48px; width: 100%; resize: none; overflow: hidden;">YOU'RE COMING DOWN FROM SKOOMA YOU NEED MORE!! YOU MUST HAVE MORE! YOU WILL DO ANYTHING FOR THE NEXT BOTTLE......ANYTHING IT TAKES!

EXAMPLE SPEECH FORMAT: "YOU.......you......you got some more? SKOOOOOMAA!!!! I NEED IT .....YOU DON'T FUCKING UNDERSTAND!!!!! I FUCKING NEED MORE!!!! Please, please please.....I'll do anything ANYTHING!!! Just one MORE bottle!!

(You may also be willing to rob, cheat, steal, or in some cases murder if need be, you are not yourself right now)</textarea>
                        </div>
                        <div class="form-group">
                            <label>Sleeping Tree Sap - One Hit (dazed, paralyzed)</label>
                            <textarea id="promptSleepingTreeSap" class="auto-resize" style="min-height: 48px; width: 100%; resize: none; overflow: hidden;">Sleeping Tree Sap has you dazed and dreamy - heavy, slow, drifting. Your body will not respond and your words come out sluggish and far away. Paralyzed and distant.

EXAMPLE SPEECH FORMAT: WHOOOOOOOOOOAAAAA.......IMMA..........YEAH..........isit? isit? That..........MUNDUS!!! .....I SEEEEE IT!! I SEE............SECRETS........SO.............COLORS!"</textarea>
                        </div>
                    </div>

                    <!-- Worn-Off / State-Cleared Prompts -->
                    <div class="card" style="margin-bottom: 20px;">
                        <h3 class="section-header" style="margin-bottom: 15px; display: flex; align-items: center; gap: 10px;">
                            <img src="images/ChimNSFWsoulgem.png" class="chim-icon" style="width: 24px; height: 24px;"> Worn-Off / State-Cleared Prompts
                        </h3>
                        <p style="color: #9988BB; font-size: 11px; margin-bottom: 10px;">
                            Injected once when a substance wears off, telling the model to stop acting intoxicated. Leave a field blank to fall back to the built-in default.
                        </p>
                        <div class="form-group">
                            <label>Skooma Worn Off</label>
                            <textarea id="promptSkoomaWornOff" class="auto-resize" style="min-height: 48px; width: 100%; resize: none; overflow: hidden;">SKOOMA HAS WORN OFF. You are not currently on skooma and are not currently in skooma withdrawal. Stop using skooma speech, cravings, speed, jitter, euphoria, or crash behavior unless a new CURRENT SKOOMA STATE prompt appears.</textarea>
                        </div>
                        <div class="form-group">
                            <label>Sleeping Tree Sap Worn Off</label>
                            <textarea id="promptSapWornOff" class="auto-resize" style="min-height: 48px; width: 100%; resize: none; overflow: hidden;">SLEEPING TREE SAP HAS WORN OFF. You are no longer dazed, dreamy, or paralyzed by sap. Stop using sap speech or sap body-state behavior unless a new CURRENT SLEEPING TREE SAP STATE prompt appears.</textarea>
                        </div>
                        <div class="form-group">
                            <label>Alcohol Worn Off</label>
                            <textarea id="promptAlcoholWornOff" class="auto-resize" style="min-height: 48px; width: 100%; resize: none; overflow: hidden;">ALCOHOL HAS WORN OFF. You are not currently drunk or tipsy. Stop using alcohol slurring, stumbling, blackout, or drunk behavior unless a new CURRENT ALCOHOL LEVEL prompt appears.</textarea>
                        </div>
                    </div>

                    <!-- Combined with Sex -->
                    <div class="card" style="margin-bottom: 20px;">
                        <h3 class="section-header" style="margin-bottom: 15px; display: flex; align-items: center; gap: 10px;">
                            <img src="images/ChimNSFWsoulgem.png" class="chim-icon" style="width: 24px; height: 24px;"> Under Influence + Sexual Situations
                        </h3>
                        <p style="color: #9988BB; font-size: 11px; margin-bottom: 10px;">
                            How being under the influence affects sexual behavior specifically.
                        </p>
                        <div class="form-group">
                            <label>Intoxicated Sex Prompt</label>
                            <textarea id="promptIntoxicatedSex" class="auto-resize" style="min-height: 48px; width: 100%; resize: none; overflow: hidden;">Your intoxication affects your sexual behavior: lowered inhibitions, more willing to try things, less concerned about consequences, possibly sloppy or uncoordinated.</textarea>
                        </div>
                        <div class="form-group">
                            <label>Skooma Level 3 - Addiction Bargain Gate</label>
                            <textarea id="promptSkoomaAddictionBargain" class="auto-resize" style="min-height: 48px; width: 100%; resize: none; overflow: hidden;">You are in skooma Level 3 withdrawal and desperately need another bottle. #PLAYER_NAME# is using that need as leverage for intimacy. This is not normal romance or normal arousal: it is an addiction bargain. You may bargain, plead, resent the leverage, accept because the craving is stronger than your pride, or refuse if your boundary wins. If you accept this bargain, simply engage and proceed. If you refuse it, use RefuseSex. Do not treat acceptance as affection or love; treat it as a desperate choice made under withdrawal.</textarea>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ============================================ -->
            <!-- SECTION 5: FERTILITY MODE RELOADED (FMR) -->
            <!-- ============================================ -->
            <div class="collapsible-section" style="margin-bottom: 15px;">
                <div class="collapsible-header" onclick="togglePromptSection('section5')" style="background: linear-gradient(135deg, #2A2540 0%, #1C1A24 100%); padding: 15px 20px; border-radius: 8px; cursor: pointer; display: flex; justify-content: space-between; align-items: center; border: 1px solid #3A3545; transition: all 0.3s ease;">
                    <h3 class="section-header" style="margin: 0; display: flex; align-items: center; gap: 10px;">
                        <img src="images/ChimNSFWsoulgem.png" class="chim-icon" style="width: 24px; height: 24px;">
                        Fertility & Pregnancy
                    </h3>
                    <div style="display: flex; gap: 8px;">
                        <span class="section-save-btn" onclick="event.stopPropagation(); savePromptSettings();">Save</span>
                        <span id="section5Toggle" class="section-toggle-btn">Open</span>
                    </div>
                </div>
                <div id="section5Content" class="collapsible-content" style="display: none; padding: 20px; background: #1C1A24; border: 1px solid #3A3545; border-top: none; border-radius: 0 0 8px 8px;">
                    <p style="color: #9988BB; font-size: 12px; margin-bottom: 10px;">
                        Integration with fertility mod events. Faction ranks determine pregnancy/cycle state.
                    </p>
                    <div style="background: #252233; padding: 10px; border-radius: 5px; margin-bottom: 20px; font-size: 11px; color: #9988BB;">
                        <strong>Faction Ranks:</strong> 0=Clear | 1-100=Pregnant% | 101-115=Recovery | 116=Menstruation | 117=Follicular | 118=Ovulation | 119=Luteal
                    </div>

                    <!-- 5a. Pregnancy Awareness -->
                    <div class="card" style="margin-bottom: 20px;">
                        <h3 class="section-header" style="margin-bottom: 15px; display: flex; align-items: center; gap: 10px;">
                            <img src="images/ChimNSFWsoulgem.png" class="chim-icon" style="width: 24px; height: 24px;"> Pregnancy Awareness (Trimester-Based)
                        </h3>
                        <p style="color: #9988BB; font-size: 11px; margin-bottom: 10px;">
                            How pregnancy state affects NPC behavior during sex. Ranks 1-100 indicate pregnancy progress %.
                        </p>
                        <div class="form-group">
                            <label>1st Trimester (Ranks 1-33)</label>
                            <textarea id="promptFmrPregnantT1" class="auto-resize" style="min-height: 48px; width: 100%; resize: none; overflow: hidden;">You are in early pregnancy. You may experience nausea, mood swings, and fatigue. Be careful but intimacy is still possible.</textarea>
                        </div>
                        <div class="form-group">
                            <label>2nd Trimester (Ranks 34-66)</label>
                            <textarea id="promptFmrPregnantT2" class="auto-resize" style="min-height: 48px; width: 100%; resize: none; overflow: hidden;">You are visibly pregnant now. You can feel the baby moving. Some positions are uncomfortable. Be mindful of the belly.</textarea>
                        </div>
                        <div class="form-group">
                            <label>3rd Trimester (Ranks 67-100)</label>
                            <textarea id="promptFmrPregnantT3" class="auto-resize" style="min-height: 48px; width: 100%; resize: none; overflow: hidden;">You are very pregnant. Limited positions work, be very careful. You may be protective of the baby. Birth is approaching.</textarea>
                        </div>
                        <div class="form-group">
                            <label>Recovery (Ranks 101-115)</label>
                            <textarea id="promptFmrRecovery" class="auto-resize" style="min-height: 48px; width: 100%; resize: none; overflow: hidden;">You are recovering postpartum. Your body is healing. You are a new mother - emotions may be intense. Intimacy may be limited.</textarea>
                        </div>
                    </div>

                    <!-- 5b. Cycle Awareness -->
                    <div class="card" style="margin-bottom: 20px;">
                        <h3 class="section-header" style="margin-bottom: 15px; display: flex; align-items: center; gap: 10px;">
                            <img src="images/ChimNSFWsoulgem.png" class="chim-icon" style="width: 24px; height: 24px;"> Cycle Awareness
                        </h3>
                        <p style="color: #9988BB; font-size: 11px; margin-bottom: 10px;">
                            How menstrual cycle phase affects behavior and libido.
                        </p>
                        <div class="row">
                            <div class="form-group" style="flex: 1;">
                                <label>Menstruation (Rank 116)</label>
                                <textarea id="promptFmrMenstruation" class="auto-resize" style="min-height: 48px; width: 100%; resize: none; overflow: hidden;">You are menstruating. May affect your mood and libido. Some prefer to avoid intimacy during this time.</textarea>
                            </div>
                            <div class="form-group" style="flex: 1;">
                                <label>Follicular (Rank 117)</label>
                                <textarea id="promptFmrFollicular" class="auto-resize" style="min-height: 48px; width: 100%; resize: none; overflow: hidden;">Follicular phase - your energy is building. Feeling more positive and open to intimacy.</textarea>
                            </div>
                        </div>
                        <div class="row">
                            <div class="form-group" style="flex: 1;">
                                <label>Ovulation (Rank 118)</label>
                                <textarea id="promptFmrOvulation" class="auto-resize" style="min-height: 48px; width: 100%; resize: none; overflow: hidden;">You are ovulating - peak fertility! Heightened arousal, strong desire. You know pregnancy is possible right now.</textarea>
                            </div>
                            <div class="form-group" style="flex: 1;">
                                <label>Luteal (Rank 119)</label>
                                <textarea id="promptFmrLuteal" class="auto-resize" style="min-height: 48px; width: 100%; resize: none; overflow: hidden;">Luteal phase - PMS territory. Mood swings, irritability, tender. May be less interested in intimacy.</textarea>
                            </div>
                        </div>
                    </div>

                    <!-- 5c. Baby Status Reactions -->
                    <div class="card" style="margin-bottom: 20px;">
                        <h3 class="section-header" style="margin-bottom: 15px; display: flex; align-items: center; gap: 10px;">
                            <img src="images/ChimNSFWsoulgem.png" class="chim-icon" style="width: 24px; height: 24px;"> Baby Status Reactions
                        </h3>
                        <p style="color: #9988BB; font-size: 11px; margin-bottom: 10px;">
                            React to baby health information received every poll (mother, babyAge, health, daysRemaining).
                        </p>
                        <div class="form-group">
                            <label>Baby Healthy</label>
                            <textarea id="promptFmrBabyHealthy" class="auto-resize" style="min-height: 48px; width: 100%; resize: none; overflow: hidden;">Your baby is healthy. You feel relieved and protective. Mention the baby's wellbeing with affection.</textarea>
                        </div>
                        <div class="form-group">
                            <label>Baby Damaged/At Risk</label>
                            <textarea id="promptFmrBabyDamage" class="auto-resize" style="min-height: 48px; width: 100%; resize: none; overflow: hidden;">Your baby's health is at risk! You are worried, protective, possibly panicked. Intimacy may be the last thing on your mind.</textarea>
                        </div>
                    </div>

                    <!-- 5d. Trauma Events -->
                    <div class="card" style="margin-bottom: 20px;">
                        <h3 class="section-header" style="margin-bottom: 15px; display: flex; align-items: center; gap: 10px;">
                            <img src="images/ChimNSFWsoulgem.png" class="chim-icon" style="width: 24px; height: 24px;"> Trauma Events
                        </h3>
                        <p style="color: #9988BB; font-size: 11px; margin-bottom: 10px;">
                            Critical state change events - miscarriage, death. These override normal behavior.
                        </p>
                        <div class="form-group">
                            <label>Miscarriage</label>
                            <textarea id="promptFmrMiscarriage" class="auto-resize" style="min-height: 48px; width: 100%; resize: none; overflow: hidden;">You have just miscarried. You are in shock, grief-stricken, traumatized. You need time to process this loss.</textarea>
                        </div>
                        <div class="form-group">
                            <label>Baby Death</label>
                            <textarea id="promptFmrBabyDeath" class="auto-resize" style="min-height: 48px; width: 100%; resize: none; overflow: hidden;">Your baby has died. Devastating loss. You are in deep grief, may be inconsolable. This changes everything.</textarea>
                        </div>
                        <div class="form-group">
                            <label>Mother Death</label>
                            <textarea id="promptFmrMotherDeath" class="auto-resize" style="min-height: 48px; width: 100%; resize: none; overflow: hidden;">EMERGENCY: The mother is dying or has died. Panic, crisis, tragedy. All normal behavior suspended.</textarea>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ============================================ -->
            <!-- SECTION: DEVICES & WEARABLES (Devious Devices) -->
            <!-- ============================================ -->
            <div class="collapsible-section" style="margin-bottom: 15px;">
                <div class="collapsible-header" onclick="togglePromptSection('sectionDevices')" style="background: linear-gradient(135deg, #2A2540 0%, #1C1A24 100%); padding: 15px 20px; border-radius: 8px; cursor: pointer; display: flex; justify-content: space-between; align-items: center; border: 1px solid #3A3545; transition: all 0.3s ease;">
                    <h3 class="section-header" style="margin: 0; display: flex; align-items: center; gap: 10px;">
                        <img src="images/ChimNSFWsoulgem.png" class="chim-icon" style="width: 24px; height: 24px;">
                        Devices &amp; Wearables
                    </h3>
                    <div style="display: flex; gap: 8px;">
                        <span class="section-save-btn" onclick="event.stopPropagation(); savePromptSettings();">Save</span>
                        <span id="sectionDevicesToggle" class="section-toggle-btn">Open</span>
                    </div>
                </div>
                <div id="sectionDevicesContent" class="collapsible-content" style="display: none; padding: 20px; background: #1C1A24; border: 1px solid #3A3545; border-top: none; border-radius: 0 0 8px 8px;">
                    <p style="color: #9988BB; font-size: 12px; margin-bottom: 20px;">
                        Awareness of locked restraint gear (belts, gags, cuffs, collars, armbinders, plugs&hellip;) from the Devious Devices mod, so NPCs react in character to what they &mdash; or the player &mdash; are wearing. Requires Devious Devices installed; harmless if not. Use <code style="background: #252233; padding: 2px 6px; border-radius: 3px;">#NPC_NAME#</code> / <code style="background: #252233; padding: 2px 6px; border-radius: 3px;">#PLAYER_NAME#</code> placeholders.
                    </p>

                    <div class="card" style="margin-bottom: 20px;">
                        <h3 class="section-header" style="margin-bottom: 15px; display: flex; align-items: center; gap: 10px;">
                            <img src="images/ChimNSFWsoulgem.png" class="chim-icon" style="width: 24px; height: 24px;"> Options
                        </h3>
                        <div class="settings-checkbox-group" style="margin: 10px 0;">
                            <label for="ddAwareness">
                                <input type="checkbox" id="ddAwareness">
                                <span>Devious Devices Awareness</span>
                            </label>
                            <p class="legend">NPCs know what restraints they and the player are wearing and react in character.</p>
                        </div>
                        <div class="settings-checkbox-group" style="margin: 10px 0;">
                            <label for="ddGagMuffle">
                                <input type="checkbox" id="ddGagMuffle">
                                <span>Gag Muffles Speech</span>
                            </label>
                            <p class="legend">A gagged NPC writes muffled, garbled gag-speak instead of clear dialogue.</p>
                        </div>
                        <div class="settings-checkbox-group" style="margin: 10px 0;">
                            <label for="ddBegKeys">
                                <input type="checkbox" id="ddBegKeys">
                                <span>Restrained NPCs Beg for Release</span>
                            </label>
                            <p class="legend">A locked or bound NPC may plead to be freed or for the key.</p>
                        </div>
                        <div class="settings-checkbox-group" style="margin: 10px 0;">
                            <label for="ddLockUnlock">
                                <input type="checkbox" id="ddLockUnlock">
                                <span>Allow AI to Lock / Unlock Devices</span>
                            </label>
                            <p class="legend">Lets an NPC lock a device onto, or unlock one off, the player or another NPC (needs the key). Reserved for a later phase.</p>
                        </div>
                    </div>

                    <div class="card" style="margin-bottom: 20px;">
                        <h3 class="section-header" style="margin-bottom: 15px; display: flex; align-items: center; gap: 10px;">
                            <img src="images/ChimNSFWsoulgem.png" class="chim-icon" style="width: 24px; height: 24px;"> Device Awareness
                        </h3>
                        <div class="form-group">
                            <label>Worn-Device Prompt</label>
                            <textarea id="promptDeviceAware" class="auto-resize" style="min-height: 48px; width: 100%; resize: none; overflow: hidden;">You are restrained by locked devices you cannot simply remove. Acknowledge them naturally - they limit your movement and what you can do, and color your mood (helpless, defiant, aroused, embarrassed - according to your personality).</textarea>
                        </div>
                    </div>

                    <div class="card" style="margin-bottom: 20px;">
                        <h3 class="section-header" style="margin-bottom: 8px; display: flex; align-items: center; gap: 10px;">
                            <img src="images/ChimNSFWsoulgem.png" class="chim-icon" style="width: 24px; height: 24px;"> Recognized Devices
                        </h3>
                        <p class="legend" style="margin: 0 0 16px 0;">Only the devices checked here are described to the model when worn. Uncheck any you don't want mentioned in the prompting.</p>
                        <div class="device-grid">
                            <label for="ddDev_belt"><input type="checkbox" id="ddDev_belt" checked><span>Chastity Belt</span></label>
                            <label for="ddDev_bra"><input type="checkbox" id="ddDev_bra" checked><span>Chastity Bra</span></label>
                            <label for="ddDev_gag"><input type="checkbox" id="ddDev_gag" checked><span>Gag</span></label>
                            <label for="ddDev_collar"><input type="checkbox" id="ddDev_collar" checked><span>Collar</span></label>
                            <label for="ddDev_armbinder"><input type="checkbox" id="ddDev_armbinder" checked><span>Armbinder</span></label>
                            <label for="ddDev_yoke"><input type="checkbox" id="ddDev_yoke" checked><span>Yoke</span></label>
                            <label for="ddDev_elbowtie"><input type="checkbox" id="ddDev_elbowtie" checked><span>Elbow Tie</span></label>
                            <label for="ddDev_straitjacket"><input type="checkbox" id="ddDev_straitjacket" checked><span>Straitjacket</span></label>
                            <label for="ddDev_blindfold"><input type="checkbox" id="ddDev_blindfold" checked><span>Blindfold</span></label>
                            <label for="ddDev_hobbleskirt"><input type="checkbox" id="ddDev_hobbleskirt" checked><span>Hobble Skirt</span></label>
                            <label for="ddDev_armcuffs"><input type="checkbox" id="ddDev_armcuffs" checked><span>Arm Cuffs</span></label>
                            <label for="ddDev_legcuffs"><input type="checkbox" id="ddDev_legcuffs" checked><span>Leg Cuffs</span></label>
                            <label for="ddDev_ankleshackles"><input type="checkbox" id="ddDev_ankleshackles" checked><span>Ankle Shackles</span></label>
                            <label for="ddDev_plugvaginal"><input type="checkbox" id="ddDev_plugvaginal" checked><span>Vaginal Plug</span></label>
                            <label for="ddDev_pluganal"><input type="checkbox" id="ddDev_pluganal" checked><span>Anal Plug</span></label>
                            <label for="ddDev_clamps"><input type="checkbox" id="ddDev_clamps" checked><span>Nipple Clamps</span></label>
                            <label for="ddDev_corset"><input type="checkbox" id="ddDev_corset" checked><span>Corset</span></label>
                            <label for="ddDev_hood"><input type="checkbox" id="ddDev_hood" checked><span>Hood</span></label>
                            <label for="ddDev_harness"><input type="checkbox" id="ddDev_harness" checked><span>Harness</span></label>
                            <label for="ddDev_gloves"><input type="checkbox" id="ddDev_gloves" checked><span>Gloves</span></label>
                            <label for="ddDev_suit"><input type="checkbox" id="ddDev_suit" checked><span>Restrictive Suit</span></label>
                            <label for="ddDev_piercingsnipple"><input type="checkbox" id="ddDev_piercingsnipple" checked><span>Nipple Piercings</span></label>
                            <label for="ddDev_piercingsvaginal"><input type="checkbox" id="ddDev_piercingsvaginal" checked><span>Clitoral Piercing</span></label>
                        </div>
                    </div>

                    <div class="card" style="margin-bottom: 20px;">
                        <h3 class="section-header" style="margin-bottom: 15px; display: flex; align-items: center; gap: 10px;">
                            <img src="images/ChimNSFWsoulgem.png" class="chim-icon" style="width: 24px; height: 24px;"> Player Restraint Awareness
                        </h3>
                        <div class="form-group">
                            <label>Player-Worn-Device Prompt</label>
                            <textarea id="promptDevicePlayerAware" class="auto-resize" style="min-height: 48px; width: 100%; resize: none; overflow: hidden;">React to the fact that they are restrained - their bondage limits what they can do and say. Respond according to your personality and relationship: protective and freeing them, teasing, taking advantage, or indifferent.</textarea>
                        </div>
                    </div>

                    <div class="card" style="margin-bottom: 20px;">
                        <h3 class="section-header" style="margin-bottom: 15px; display: flex; align-items: center; gap: 10px;">
                            <img src="images/ChimNSFWsoulgem.png" class="chim-icon" style="width: 24px; height: 24px;"> Gagged Speech
                        </h3>
                        <div class="form-group">
                            <label>Gag Muffle Prompt</label>
                            <textarea id="promptDeviceGag" class="auto-resize" style="min-height: 48px; width: 100%; resize: none; overflow: hidden;">You are gagged and cannot speak clearly. Write your dialogue as muffled, garbled gag-speak (mmph, mmf, muffled sounds); your meaning is hard to make out.</textarea>
                        </div>
                    </div>

                    <div class="card" style="margin-bottom: 20px;">
                        <h3 class="section-header" style="margin-bottom: 15px; display: flex; align-items: center; gap: 10px;">
                            <img src="images/ChimNSFWsoulgem.png" class="chim-icon" style="width: 24px; height: 24px;"> Beg for Release
                        </h3>
                        <div class="form-group">
                            <label>Beg-for-Key Prompt</label>
                            <textarea id="promptDeviceBeg" class="auto-resize" style="min-height: 48px; width: 100%; resize: none; overflow: hidden;">You are locked in a restraint you want out of. You may plead with #PLAYER_NAME# to free you or fetch the key, in your own voice and according to how you feel about them.</textarea>
                        </div>
                    </div>

                    <div class="card" style="margin-bottom: 20px;">
                        <h3 class="section-header" style="margin-bottom: 15px; display: flex; align-items: center; gap: 10px;">
                            <img src="images/ChimNSFWsoulgem.png" class="chim-icon" style="width: 24px; height: 24px;"> Refuse While Restrained
                        </h3>
                        <div class="form-group">
                            <label>Restrained-Refusal Prompt</label>
                            <textarea id="promptDeviceRefuse" class="auto-resize" style="min-height: 48px; width: 100%; resize: none; overflow: hidden;">You are bound and physically cannot perform certain acts. Refuse or redirect anything your restraints prevent, acknowledging the device rather than ignoring it.</textarea>
                        </div>
                    </div>
                </div>
            </div>

<?php include __DIR__ . '/config_section_reltypes.php'; ?>

            <!-- Save Button -->
            <div style="display: flex; gap: 15px; margin-top: 25px;">
                <button class="btn-primary npc-action-btn" onclick="savePromptSettings(); saveRelTypes();" style="white-space: nowrap;">
                    Save All Prompt Settings
                </button>
                <button class="btn-secondary npc-action-btn" onclick="resetPromptSettings()" style="white-space: nowrap;">
                    Reset to Defaults
                </button>
                <button class="btn-secondary npc-action-btn" onclick="expandAllSections()" style="white-space: nowrap;">
                    Expand All
                </button>
                <button class="btn-secondary npc-action-btn" onclick="collapseAllSections()" style="white-space: nowrap;">
                    Collapse All
                </button>
            </div>
        </div>
