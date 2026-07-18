            <!-- FERTILITY & PREGNANCY: lives on the Prompts tab (was briefly its own tab 2026-07-07,
                 folded back in 2026-07-10 per user directive). Element IDs are load-bearing - the
                 save/load JS in config_manager.php addresses them directly. -->
            <div class="collapsible-section" style="margin-bottom: 15px;">
                <div class="collapsible-header" onclick="togglePromptSection('sectionFertility')" style="background: linear-gradient(135deg, #2A2540 0%, #1C1A24 100%); padding: 15px 20px; border-radius: 8px; cursor: pointer; display: flex; justify-content: space-between; align-items: center; border: 1px solid #3A3545; transition: all 0.3s ease;">
                    <h3 class="section-header" style="margin: 0; display: flex; align-items: center; gap: 10px;">
                        <img src="images/ChimNSFWsoulgem.png" class="chim-icon" style="width: 24px; height: 24px;">
                        Fertility &amp; Pregnancy
                    </h3>
                    <div style="display: flex; gap: 8px;">
                        <span class="section-save-btn" onclick="event.stopPropagation(); saveSettings(); savePromptSettings();">Save</span>
                        <span id="sectionFertilityToggle" class="section-toggle-btn">Open</span>
                    </div>
                </div>
                <div id="sectionFertilityContent" class="collapsible-content" style="display: none; padding: 20px; background: #1C1A24; border: 1px solid #3A3545; border-top: none; border-radius: 0 0 8px 8px;">
            <p style="color: #B8A8C8; line-height: 1.6; margin-bottom: 14px;">
                SHARMAT listens for the Fertility Mode event contract and colors each NPC's dialogue with her
                current state: cycle phase, trimester, recovery, worn-baby hazards, conception, labor, and loss.
                Works with the current <strong style="color:#FDF5D0;">Fertility Mode Reloaded</strong> and is built for the upcoming
                <strong style="color:#FDF5D0;">Fertility Mode Reloaded NG</strong> (same events, richer data - NG is the recommended pairing).
                If no fertility mod is installed, nothing here ever fires. Requires the current SHARMAT game mod (Download Game Mod on the Info tab).
            </p>

            <div class="settings-checkbox-group" style="margin-bottom: 18px;">
                <label for="nsfwFertilityEnabled">
                    <input type="checkbox" id="nsfwFertilityEnabled" name="NSFW_FERTILITY_ENABLED" checked>
                    <span>Fertility Reactions</span>
                </label>
                <div style="margin-top: 6px;">
                    <label style="color: #9988BB; font-size: 12px;">Event reaction window (seconds)</label>
                    <input type="number" id="nsfwFertilityEventWindow" name="NSFW_FERTILITY_EVENT_WINDOW_SECONDS" min="60" max="21600" step="60" value="1800" style="width: 90px; padding: 6px; background: #252233; border: 1px solid #3A3545; color: #B8A8D0; border-radius: 5px;">
                    <p class="legend">How long conception, labor, relief, and hazard reactions stay in an NPC's voice after the event. Grief from a loss lingers 4x this window. Default: 1800 (30 min).</p>
                </div>
                <p class="legend" style="margin-top: 6px;">Toggles and prompts here save with the normal Save buttons. Placeholders: #NPC_NAME#, #PLAYER_NAME#, #FATHER_NAME#, #CAUSE#.</p>
            </div>

            <h3 class="info-subtitle">Pregnancy Stages</h3>
            <p style="color: #9988BB; font-size: 11px; margin-bottom: 10px;">Injected every turn while the NPC is in that stage. Leave a field blank to fall back to the built-in default.</p>
            <div class="form-group">
                <label>First Trimester</label>
                <textarea id="promptFertilityTri1" class="auto-resize" style="min-height: 48px; width: 100%; resize: none; overflow: hidden;"></textarea>
            </div>
            <div class="form-group">
                <label>Second Trimester</label>
                <textarea id="promptFertilityTri2" class="auto-resize" style="min-height: 48px; width: 100%; resize: none; overflow: hidden;"></textarea>
            </div>
            <div class="form-group">
                <label>Third Trimester</label>
                <textarea id="promptFertilityTri3" class="auto-resize" style="min-height: 48px; width: 100%; resize: none; overflow: hidden;"></textarea>
            </div>
            <div class="form-group">
                <label>Full Term</label>
                <textarea id="promptFertilityFullterm" class="auto-resize" style="min-height: 48px; width: 100%; resize: none; overflow: hidden;"></textarea>
            </div>
            <div class="form-group">
                <label>Postpartum Recovery</label>
                <textarea id="promptFertilityRecovery" class="auto-resize" style="min-height: 48px; width: 100%; resize: none; overflow: hidden;"></textarea>
            </div>

            <h3 class="info-subtitle">Cycle Phases</h3>
            <p style="color: #9988BB; font-size: 11px; margin-bottom: 10px;">The follicular phase deliberately has no prompt - it is the neutral baseline.</p>
            <div class="form-group">
                <label>Menstruation</label>
                <textarea id="promptFertilityCycleMenses" class="auto-resize" style="min-height: 48px; width: 100%; resize: none; overflow: hidden;"></textarea>
            </div>
            <div class="form-group">
                <label>Ovulation (fertile peak)</label>
                <textarea id="promptFertilityCycleOvulation" class="auto-resize" style="min-height: 48px; width: 100%; resize: none; overflow: hidden;"></textarea>
            </div>
            <div class="form-group">
                <label>Luteal / PMS</label>
                <textarea id="promptFertilityCyclePms" class="auto-resize" style="min-height: 48px; width: 100%; resize: none; overflow: hidden;"></textarea>
            </div>

            <h3 class="info-subtitle">Events</h3>
            <p style="color: #9988BB; font-size: 11px; margin-bottom: 10px;">Fired by conception, labor, worn-baby hazards (cold, water, exposure, substances), and loss. Hazard stress stays active until the hazard ends.</p>
            <div class="form-group">
                <label>Conception</label>
                <textarea id="promptFertilityConception" class="auto-resize" style="min-height: 48px; width: 100%; resize: none; overflow: hidden;"></textarea>
            </div>
            <div class="form-group">
                <label>Labor / Birth</label>
                <textarea id="promptFertilityLabor" class="auto-resize" style="min-height: 48px; width: 100%; resize: none; overflow: hidden;"></textarea>
            </div>
            <div class="form-group">
                <label>Baby in Danger (hazard)</label>
                <textarea id="promptFertilityStress" class="auto-resize" style="min-height: 48px; width: 100%; resize: none; overflow: hidden;"></textarea>
            </div>
            <div class="form-group">
                <label>Baby in Danger (substances - skooma, alcohol, drugs)</label>
                <textarea id="promptFertilityStressSubstance" class="auto-resize" style="min-height: 48px; width: 100%; resize: none; overflow: hidden;"></textarea>
            </div>
            <div class="form-group">
                <label>Danger Passed (relief)</label>
                <textarea id="promptFertilityRelief" class="auto-resize" style="min-height: 48px; width: 100%; resize: none; overflow: hidden;"></textarea>
            </div>
            <div class="form-group">
                <label>Baby Lost</label>
                <textarea id="promptFertilityLossBaby" class="auto-resize" style="min-height: 48px; width: 100%; resize: none; overflow: hidden;"></textarea>
            </div>
            <div class="form-group">
                <label>Miscarriage</label>
                <textarea id="promptFertilityMiscarriage" class="auto-resize" style="min-height: 48px; width: 100%; resize: none; overflow: hidden;"></textarea>
            </div>

            <h3 class="info-subtitle">Royal Family / Dynasty (FMR NG)</h3>
            <p style="color: #9988BB; font-size: 11px; margin-bottom: 10px;">
                FMR NG exports the player's dynasty to <strong style="color:#B8A8D0;">FMRNG_Lineage.json</strong> on every change:
                children (names, ages, legitimate vs bastard) and, with NG's Royal Dynasty Mode on, crown prince/princess titles and the
                line of succession. NPCs weave this into conversation, plus any pregnancies they already know of through the fertility events.
                Pure flavor: no new actions, no consent changes. Silently off when the file does not exist.
                Extra placeholders: #FAMILY_SUMMARY#, #CHILD_COUNT#, #HEIR_NAME#, #HEIR_TITLE#, #BASTARD_NAMES#, #BASTARD_COUNT#, #EXPECTING_SUMMARY#, #EXPECTING_COUNT#.
            </p>
            <div class="settings-checkbox-group" style="margin-bottom: 14px;">
                <label for="nsfwFertilityFamilyEnabled">
                    <input type="checkbox" id="nsfwFertilityFamilyEnabled" name="NSFW_FERTILITY_FAMILY_ENABLED" checked>
                    <span>Royal Family / Dynasty Context</span>
                </label>
                <div style="margin-top: 6px;">
                    <label style="color: #9988BB; font-size: 12px;">Lineage file path (blank = auto-detect)</label>
                    <input type="text" id="nsfwFertilityLineagePath" name="NSFW_FERTILITY_LINEAGE_PATH" placeholder="auto-detect (MO2 overwrite / game Data folder)" style="width: 100%; max-width: 520px; padding: 6px; background: #252233; border: 1px solid #3A3545; color: #B8A8D0; border-radius: 5px;">
                    <p class="legend">Full path to FMRNG_Lineage.json. Windows form is fine (C:\...\overwrite\SKSE\Plugins\FMRNG_Lineage.json). Leave blank to let the server find it in the usual MO2 overwrite and Steam Data locations.</p>
                </div>
            </div>
            <div class="form-group">
                <label>Family Overview (fires whenever the player has children)</label>
                <textarea id="promptFertilityFamilyOverview" class="auto-resize" style="min-height: 48px; width: 100%; resize: none; overflow: hidden;"></textarea>
            </div>
            <div class="form-group">
                <label>Royal Court (only while NG's Royal Dynasty Mode is on)</label>
                <textarea id="promptFertilityFamilyRoyal" class="auto-resize" style="min-height: 48px; width: 100%; resize: none; overflow: hidden;"></textarea>
            </div>
            <div class="form-group">
                <label>Bastard Children (fires when any child was born out of wedlock)</label>
                <textarea id="promptFertilityFamilyBastard" class="auto-resize" style="min-height: 48px; width: 100%; resize: none; overflow: hidden;"></textarea>
            </div>
            <div class="form-group">
                <label>Expecting Mothers (fires when known pregnancies carry the player's child)</label>
                <textarea id="promptFertilityFamilyExpecting" class="auto-resize" style="min-height: 48px; width: 100%; resize: none; overflow: hidden;"></textarea>
            </div>
            <div class="form-group">
                <label>Grown Child Returned (fires when the speaking NPC is a child of the player who came back from training as an adult; the first conversation also silently clears their stored child flags and rebuilds their profile)</label>
                <textarea id="promptFertilityFamilyReturn" class="auto-resize" style="min-height: 48px; width: 100%; resize: none; overflow: hidden;"></textarea>
            </div>

            <h3 class="info-subtitle">Baby Tragedy / Witness Reactions (FMR NG)</h3>
            <p style="color: #9988BB; font-size: 11px; margin-bottom: 10px;">
                FMR NG reports harm to OTHER mothers' babies with names attached: who killed a pregnant woman
                (<strong style="color:#B8A8D0;">FMR_BabyTragedy</strong>), who lost a child to skooma or the cold, and whose baby is in danger
                right now. Companions react with grief, judgment, or alarm. Pure flavor: no new actions, no consent changes;
                an NPC's OWN grief stays in the Events prompts above. Extra placeholders: #TRAGEDY_SUMMARY#, #TRAGEDY_COUNT#,
                #KILLER_NAME#, #VICTIM_NAME#, #LOSS_SUMMARY#, #DANGER_SUMMARY#.
            </p>
            <div class="settings-checkbox-group" style="margin-bottom: 14px;">
                <label for="nsfwFertilityTragedyEnabled">
                    <input type="checkbox" id="nsfwFertilityTragedyEnabled" name="NSFW_FERTILITY_TRAGEDY_ENABLED" checked>
                    <span>Baby Tragedy / Witness Reactions</span>
                </label>
                <p class="legend" style="margin-top: 6px;">Losses and killings stay in NPCs' voices 4x the event reaction window; a hazard stays until it ends. The mother herself is excluded - these prompts are for everyone else.</p>
            </div>
            <div class="form-group">
                <label>Witnessed Tragedy (a named killer harmed a pregnant or baby-carrying woman)</label>
                <textarea id="promptFertilityWitnessTragedy" class="auto-resize" style="min-height: 48px; width: 100%; resize: none; overflow: hidden;"></textarea>
            </div>
            <div class="form-group">
                <label>Witnessed Loss (another woman lost her child - skooma, cold, drowning...)</label>
                <textarea id="promptFertilityWitnessLoss" class="auto-resize" style="min-height: 48px; width: 100%; resize: none; overflow: hidden;"></textarea>
            </div>
            <div class="form-group">
                <label>Witnessed Danger (another woman's baby is in danger or was just hurt)</label>
                <textarea id="promptFertilityWitnessDanger" class="auto-resize" style="min-height: 48px; width: 100%; resize: none; overflow: hidden;"></textarea>
            </div>
                </div>
            </div>
