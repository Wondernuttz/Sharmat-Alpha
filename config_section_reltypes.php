<?php
// ============================================================================
// RELATIONSHIP TYPE GATES  (a collapsible section INSIDE the Prompts tab)
// ----------------------------------------------------------------------------
// Self-contained: reads/writes ONLY conf_opts 'aiagent_nsfw_reltypes'. Never
// touches the prompts/settings JSONB blobs. Types are pulled LIVE from CHIM
// (RelationshipManager) so new types appear here automatically.
// ============================================================================
require_once __DIR__ . "/../../lib/relationship_manager.php";
$_rtRow = (isset($GLOBALS["db"]) && $GLOBALS["db"]) ? $GLOBALS["db"]->fetchOne("SELECT value FROM conf_opts WHERE id = 'aiagent_nsfw_reltypes'") : null;
$_rtCfg = ($_rtRow && !empty($_rtRow['value'])) ? json_decode($_rtRow['value'], true) : [];
if (!is_array($_rtCfg)) { $_rtCfg = []; }
$_rtEnabled  = array_key_exists('enabled', $_rtCfg) ? (bool)$_rtCfg['enabled'] : true;  // master toggle (default on)
$_rtEligible = $_rtCfg['eligible_types'] ?? ['romantic', 'crush', 'ex'];                 // default sex-eligible: the romantic ones
// Treat an EMPTY saved string as "use the default". A blank save previously wiped these to '' -> empty textareas ->
// the gate silently no-op'd. The ?? operator alone does NOT catch '' (empty string is not null), so sanitize blanks.
$_rtVal = function ($v, $d) { return (is_string($v) && trim($v) !== '') ? $v : $d; };
$_rtFriendly = $_rtVal($_rtCfg['prompt_friendly'] ?? null, "You like #PLAYER_NAME# and feel comfortable with them, but your relationship is not romantic or sexual. Kindly but clearly decline the advance and keep the boundary intact. Politely refuse.");
$_rtFond     = $_rtVal($_rtCfg['prompt_fond'] ?? null, "You have genuine affection for #PLAYER_NAME# and care about them, but your relationship with them isn't a romantic or sexual one - you're simply not involved that way. Warmly but firmly decline the advance without hurting the bond. Politely refuse.");
$_rtDevoted  = $_rtVal($_rtCfg['prompt_devoted'] ?? null, "You care deeply for #PLAYER_NAME#, but what the two of you share isn't romantic - the bond runs deep, just not that way. Gently and kindly turn down the advance while honoring how much they mean to you. Politely refuse.");
$_rtBonded   = $_rtVal($_rtCfg['prompt_bonded'] ?? null, "#PLAYER_NAME# means the world to you, but your bond isn't a romantic or sexual one and you won't cross that line. Tenderly, lovingly decline - the closeness stays, the line stays. Politely refuse.");
$_rtMarried  = $_rtVal($_rtCfg['prompt_married_addon'] ?? null, "You are also married to #SPOUSE#.");  // additive - appended to the refusal when she is also married
$_rtTypes = RelationshipManager::TYPES;
$_rtEmoji = RelationshipManager::TYPE_EMOJI;
?>
            <!-- ============================================ -->
            <!-- RELATIONSHIP TYPE GATES -->
            <!-- ============================================ -->
            <style>
                .reltype-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(250px, 1fr)); gap: 12px; margin-top: 12px; }
                .reltype-box {
                    display: flex; align-items: center; gap: 12px;
                    background: #221E30; border: 1px solid #3A3545; border-radius: 8px;
                    padding: 13px 16px; cursor: pointer; transition: all 0.3s ease;
                }
                .reltype-box:hover { border-color: rgba(253,245,208,0.5); box-shadow: 0 0 8px rgba(139,92,246,0.25); }
                .reltype-box .rt-emoji { font-size: 24px; flex-shrink: 0; line-height: 1; }
                .reltype-box .rt-name { flex: 1; font-size: 18px; word-spacing: normal; margin: 0; }
                .reltype-box input[type="checkbox"] {
                    appearance: none; -webkit-appearance: none;
                    width: 22px; height: 22px; border: 2px solid #FDF5D0; border-radius: 4px;
                    background: #1E1A2E; cursor: pointer; position: relative; flex-shrink: 0;
                    animation: goldNeonPulse 3s ease-in-out infinite alternate;
                }
                .reltype-box input[type="checkbox"]:checked { background: #FDF5D0; border-color: #FDF5D0; }
                .reltype-box input[type="checkbox"]:checked::after {
                    content: '\2713'; position: absolute; top: 50%; left: 50%; transform: translate(-50%,-50%);
                    color: #3D2A5C; font-size: 15px; font-weight: bold;
                }
                .reltype-box input[type="checkbox"]:hover { border-color: #fff; box-shadow: 0 0 8px rgba(253,245,208,0.4); }
            </style>

            <div class="collapsible-section" style="margin-bottom: 15px;">
                <div class="collapsible-header" onclick="togglePromptSection('reltypeGates')" style="background: linear-gradient(135deg, #2A2540 0%, #1C1A24 100%); padding: 15px 20px; border-radius: 8px; cursor: pointer; display: flex; justify-content: space-between; align-items: center; border: 1px solid #3A3545; transition: all 0.3s ease;">
                    <h3 class="section-header" style="margin: 0; display: flex; align-items: center; gap: 10px;">
                        <img src="images/ChimNSFWsoulgem.png" class="chim-icon" style="width: 24px; height: 24px;"> Relationship Type Gates
                    </h3>
                    <div style="display: flex; gap: 8px;">
                        <span class="section-save-btn" onclick="event.stopPropagation(); saveRelTypes();">Save</span>
                        <span id="reltypeGatesToggle" class="section-toggle-btn">Open</span>
                    </div>
                </div>
                <div id="reltypeGatesContent" class="collapsible-content" style="display: none; padding: 20px; background: #1C1A24; border: 1px solid #3A3545; border-top: none; border-radius: 0 0 8px 8px;">
                    <div class="alert success" id="reltypesSuccessAlert"></div>
                    <div class="alert error" id="reltypesErrorAlert"></div>

                    <p style="color: #9988BB; font-size: 12px; margin-bottom: 18px;">
                        Choose which relationship types are <strong style="color:#B8A8C8;">sex-eligible</strong>. Player-initiated sex with an NPC whose type is <em>not</em> checked gets a polite refusal from Friendly through Bonded instead of the normal scene. Acquaintance and lower still follow the normal affinity tier prompts. Slaves and prostitutes are exempt. Player-only (never NPC&harr;NPC). Types are pulled live from CHIM, so new ones appear here automatically. Use <code style="background: #252233; padding: 2px 6px; border-radius: 3px;">#PLAYER_NAME#</code> in the refusal prompts.
                    </p>

                    <!-- Master toggle: OFF = bypass the whole gate -->
                    <div class="settings-checkbox-group">
                        <label>
                            <input type="checkbox" id="reltypeEnabled" <?php echo $_rtEnabled ? 'checked' : ''; ?>>
                            <span>Enable Relationship-Type Gating</span>
                        </label>
                        <p class="legend" style="color: #9988BB; font-size: 11px; margin: 8px 0 0 30px;">When OFF, this whole section is bypassed &mdash; every NPC falls back to the normal affinity tier prompts (no type restriction).</p>
                    </div>

                    <!-- The tiered polite-refusal prompts -->
                    <div class="form-group" style="margin-top: 18px;">
                        <label>Friendly (+31 to +55) &mdash; Polite Refusal</label>
                        <textarea id="reltypePromptFriendly" class="auto-resize" style="min-height: 48px; width: 100%; resize: none; overflow: hidden;"><?php echo htmlspecialchars($_rtFriendly); ?></textarea>
                    </div>
                    <div class="form-group">
                        <label>Fond (+56 to +75) &mdash; Polite Refusal</label>
                        <textarea id="reltypePromptFond" class="auto-resize" style="min-height: 48px; width: 100%; resize: none; overflow: hidden;"><?php echo htmlspecialchars($_rtFond); ?></textarea>
                    </div>
                    <div class="form-group">
                        <label>Devoted (+76 to +90) &mdash; Polite Refusal</label>
                        <textarea id="reltypePromptDevoted" class="auto-resize" style="min-height: 48px; width: 100%; resize: none; overflow: hidden;"><?php echo htmlspecialchars($_rtDevoted); ?></textarea>
                    </div>
                    <div class="form-group">
                        <label>Bonded (+91 to +100) &mdash; Polite Refusal</label>
                        <textarea id="reltypePromptBonded" class="auto-resize" style="min-height: 48px; width: 100%; resize: none; overflow: hidden;"><?php echo htmlspecialchars($_rtBonded); ?></textarea>
                    </div>
                    <div class="form-group">
                        <label>Married non-types &mdash; additive</label>
                        <p style="color: #9988BB; font-size: 11px; margin: 0 0 6px;">Appended onto the refusal above when the NPC is <em>also</em> married. Use <code style="background: #252233; padding: 2px 6px; border-radius: 3px;">#SPOUSE#</code> for the spouse's name.</p>
                        <textarea id="reltypePromptMarried" class="auto-resize" style="min-height: 40px; width: 100%; resize: none; overflow: hidden;"><?php echo htmlspecialchars($_rtMarried); ?></textarea>
                    </div>

                    <!-- Sex-eligible relationship types: little boxes, breathing-purple name + gold checkbox -->
                    <label style="display:block; color:#B8A8C8; font-weight:bold; margin: 22px 0 4px;">Sex-Eligible Relationship Types</label>
                    <p style="color: #9988BB; font-size: 11px; margin: 0 0 6px;">Checked = this type can lead to sex (goes through the normal rel gate). Unchecked = polite refusal.</p>
                    <div class="reltype-grid">
                        <?php foreach ($_rtTypes as $_t):
                            $_emoji = $_rtEmoji[$_t] ?? '&bull;';
                            $_checked = in_array($_t, $_rtEligible, true) ? 'checked' : '';
                        ?>
                        <label class="reltype-box">
                            <span class="rt-emoji"><?php echo $_emoji; ?></span>
                            <span class="rt-name section-header"><?php echo htmlspecialchars(ucfirst($_t)); ?></span>
                            <input type="checkbox" class="reltype-checkbox" value="<?php echo htmlspecialchars($_t); ?>" <?php echo $_checked; ?>>
                        </label>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
