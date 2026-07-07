        <div id="fertility" class="tab-content">
            <h2 style="color: #FDF5D0;">Fertility</h2>
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
        </div>
