        <div id="info" class="tab-content">
            <style>
                @keyframes sharmatGlow { from { text-shadow: 0 0 8px rgba(253,245,208,0.5); } to { text-shadow: 0 0 22px rgba(253,245,208,0.95), 0 0 32px rgba(255,200,90,0.45); } }
                .sharmat-title { font-family: 'MagicCards', 'Times New Roman', serif; color: #FDF5D0; font-weight: bold; font-size: 38px; letter-spacing: 2px; margin: 0 0 12px; text-shadow: 0 0 10px rgba(253,245,208,0.6); animation: sharmatGlow 2.8s ease-in-out infinite alternate; }
            </style>
            <h1 class="sharmat-title"><img src="images/ChimNSFWsoulgem.png" style="width: 38px; height: 38px; vertical-align: middle; position: relative; top: -4px;"> SHARMAT</h1>
            <?php
                // Version display (2026-07-18): manifest.json is the server-version source of truth;
                // mod/version.txt ships INSIDE the game-mod download so installed copies carry their own.
                $sharmatManifestInfo = @json_decode((string)@file_get_contents(__DIR__ . '/manifest.json'), true);
                $sharmatServerVersion = is_array($sharmatManifestInfo) ? (string)($sharmatManifestInfo['version'] ?? '?') : '?';
                $sharmatBundledModVersion = trim((string)@file_get_contents(__DIR__ . '/mod/version.txt'));
                if ($sharmatBundledModVersion === '') { $sharmatBundledModVersion = '?'; }
            ?>
            <p style="margin: -4px 0 6px; color: #FDF5D0; font-size: 15px; letter-spacing: 1px;">
                Server Extension <strong>v<?php echo htmlspecialchars($sharmatServerVersion); ?></strong>
                &nbsp;&middot;&nbsp; Game Mod (bundled) <strong>v<?php echo htmlspecialchars($sharmatBundledModVersion); ?></strong>
            </p>
            <p style="margin: 0 0 18px; color: #9988BB; font-size: 11px;">
                The bundled version is what the Download Game Mod button delivers. Your INSTALLED mod folder has its own version.txt inside - if it reads lower than the bundled version (or is missing), re-download the game mod and load a save.
            </p>
            <p style="line-height: 1.6; color: #B8A8C8; margin-bottom: 22px;">
                SHARMAT is a CHIM NSFW framework designed to bring meaningful, realistic relationship building and actions into the AI platform. It is driven by multilevel prompt gating and timing, built off the CHIM relationship system, and is designed to help even less capable models deliver a more realistic NSFW experience. Actions in SHARMAT must to some degree be <strong style="color: #FDF5D0;">EARNED</strong> by the player: relationships must be maintained, and NPCs are made aware of edge cases they would otherwise miss using the standard prompt-overhead methods of the past.
            </p>

            <h3 class="info-subtitle">Updates</h3>
            <div class="info-box">
                <p style="color: #B8A8C8; margin: 0 0 10px;">Pulls the latest SHARMAT server extension from GitHub. Your settings, prompts, and NPC profiles are kept (they live in the database), local conf files are preserved, and a file backup is taken first. The game never loads files from the server, so whenever a fix says it needs new GAME files, update first and then grab them with the Download Game Mod button.</p>
                <button type="button" class="btn-primary" onclick="sharmatCheckUpdate()">Check for Updates</button>
                <button type="button" class="btn-primary" id="sharmatRunUpdateBtn" style="display:none; background: #2A2435; color: #FDF5D0; border: 1px solid #FDF5D0; box-shadow: 0 0 10px rgba(255,200,90,0.45); text-shadow: 0 0 6px rgba(253,245,208,0.6);" onclick="sharmatRunUpdate()">Update Now</button>
                <a class="btn-primary" href="?action=sharmatDownloadMod" style="display:inline-block; text-decoration:none;">Download Game Mod (zip)</a>
                <p style="color: #9988BB; font-size: 11px; margin: 8px 0 0;">The zip installs directly as a mod in MO2/Vortex: add it (or extract over your existing SHARMAT mod, overwriting), then load a save. Only needed when a fix mentions new game/mod files - server-only fixes need just the update.</p>
                <div id="sharmatUpdateStatus" style="margin-top: 10px; color: #B8A8C8;"></div>
            </div>
            <script>
            function sharmatCheckUpdate() {
                const st = document.getElementById('sharmatUpdateStatus');
                st.textContent = 'Checking GitHub...';
                fetch('?action=sharmatCheckUpdate').then(r => r.json()).then(d => {
                    if (!d.success) { st.textContent = 'Check failed: ' + d.error; return; }
                    if (d.update_available) {
                        st.textContent = 'Update available.';
                        document.getElementById('sharmatRunUpdateBtn').style.display = 'inline-block';
                    } else {
                        st.textContent = 'Up to date.';
                    }
                }).catch(e => { st.textContent = 'Check failed: ' + e.message; });
            }
            function sharmatRunUpdate() {
                if (!confirm('Update SHARMAT server files from GitHub now? Settings and prompts are kept; a file backup is taken first.')) return;
                const st = document.getElementById('sharmatUpdateStatus');
                st.textContent = 'Downloading and applying update...';
                fetch('?action=sharmatRunUpdate', { method: 'POST' }).then(r => r.json()).then(d => {
                    if (!d.success) { st.textContent = 'Update failed: ' + d.error; return; }
                    let msg;
                    if (d.failed_count > 0) { msg = 'Update incomplete: ' + d.failed_count + ' files could not be written. ' + (d.hint || ''); }
                    else { msg = 'Update complete - reload this page.'; }
                    st.textContent = msg;
                    document.getElementById('sharmatRunUpdateBtn').style.display = 'none';
                }).catch(e => { st.textContent = 'Update failed: ' + e.message; });
            }
            </script>

            <h3 class="info-subtitle">Overview</h3>
            <p style="line-height: 1.6; color: #B8A8C8; margin-bottom: 15px;">
                This extension makes NPCs aware of intimate scenes and lets them speak and act in character during them. It works with <strong style="color: #FDF5D0;">both OStim and SexLab</strong>: the server reacts to scene events from either framework, for player scenes, player group scenes (orgies), and NPC-to-NPC scenes. NPC behaviour is shaped by the <strong style="color: #FDF5D0;">relationship model</strong> (affinity tiers), an optional <strong style="color: #FDF5D0;">arousal gate</strong>, and the per-NPC profile (sex prompt, speech style, kinks). So it is recommended that you at least use the relationship model for the most authentic experience.
            </p>

            <h3 class="info-subtitle">How It Works</h3>
            <div class="info-box">
                <ul style="margin: 6px 0; padding-left: 20px; line-height: 1.7;">
                    <li><strong>Scene flow:</strong> when a scene starts, each NPC gets a scene-start prompt; per-stage and per-orgasm events drive their dialogue. Each actor is told what is happening to <em>them</em> specifically: in an orgy every NPC reacts to their own moment, and the player's orgasm is routed to the partner(s) in that scene.</li>
                    <li><strong>Relationship tiers:</strong> with the Relationship Model on, the prompt injected is chosen by the NPC's affinity toward their partner (Hostile up to Bonded). Marriage and affair scenes get their own tier prompts.</li>
                    <li><strong>Arousal gate (optional):</strong> sex actions unlock progressively as the NPC's <strong>sex_disposal</strong> rises. Prostitutes skip this gate (they gate on relationship + payment instead).</li>
                    <li><strong>Prostitution:</strong> driven by the per-NPC checkbox. One flat session price, adjusted by an affinity discount/premium. At the top affinity tier she can choose to stop charging (QuitProstitution).</li>
                    <li><strong>Intoxication:</strong> real alcohol consumption (the inventory-backed Consume action) is tallied over a game-time window into 10 drunk stages, each with its own prompt and progressively slurred speech. Toast/Drink are social flavor by default; a settings toggle can make them count too.</li>
                </ul>
            </div>

            <h3 class="info-subtitle">Placeholder Tags</h3>
            <p style="color: #B8A8C8; margin-bottom: 10px;">Use these in any prompt on the <strong>Prompts</strong> tab or in a per-NPC sex prompt. They are replaced at runtime, from the speaking NPC's point of view.</p>
            <div class="info-code-box">
                <div><strong style="color: #FDF5D0;">#NPC_NAME#</strong>: the speaking NPC (the one this line is for). Use it to refer to the NPC by name.</div>
                <div style="margin-top: 8px;"><strong style="color: #FDF5D0;">#PLAYER_NAME#</strong>: the human player only. Do not use this as a generic scene-partner token in speak styles.</div>
                <div style="margin-top: 8px;"><strong style="color: #FDF5D0;">#PRIMARY_PARTNER#</strong>: the active partner for speech styles and NPC scene prompts. In a player scene this resolves to <strong>#PLAYER_NAME#</strong>; in an NPC-to-NPC scene it resolves to the other NPC.</div>
                <div style="margin-top: 8px;"><strong style="color: #FDF5D0;">#SCENE_PARTICIPANTS#</strong>: comma-separated scene roster for the current thread.</div>
                <div style="margin-top: 8px;"><strong style="color: #FDF5D0;">#ORGASMER_NAME#</strong>: the actor who triggered the current orgasm/climax event.</div>
                <div style="margin-top: 8px;"><strong style="color: #FDF5D0;">#SPEAKER_NAME#</strong>: alias of #NPC_NAME# (the speaking NPC).</div>
                <div style="margin-top: 8px;"><strong style="color: #FDF5D0;">#OTHER_PARTICIPANTS#</strong>: the scene roster excluding the speaking NPC.</div>
                <div style="margin-top: 8px;"><strong style="color: #FDF5D0;">#NPC_PARTNER#</strong> / <strong style="color: #FDF5D0;">#NPC_SCENE_PARTICIPANTS#</strong>: NPC-to-NPC scenes: the other NPC in the pair / the full NPC-scene roster.</div>
                <div style="margin-top: 8px;"><strong style="color: #FDF5D0;">#NPC_SPOUSE#</strong>: the speaking NPC's own spouse by name (empty if unmarried).</div>
                <div style="margin-top: 8px;"><strong style="color: #FDF5D0;">#CURRENT_SCENE#</strong> / <strong style="color: #FDF5D0;">#SCENE_DESCRIPTION#</strong>: the current animation/scene name and its plain-language description.</div>
                <div style="margin-top: 8px;"><strong style="color: #FDF5D0;">#SCENE_ID#</strong> / <strong style="color: #FDF5D0;">#THREAD_ID#</strong>: internal scene and thread identifiers (advanced; mostly for debugging prompts).</div>
                <div style="margin-top: 8px;"><strong style="color: #FDF5D0;">#NPC_INVITE_INITIATOR#</strong> / <strong style="color: #FDF5D0;">#NPC_INVITE_TARGET#</strong> / <strong style="color: #FDF5D0;">#NPC_INVITE_ACTION#</strong> / <strong style="color: #FDF5D0;">#NPC_INVITE_ROLE#</strong>: NPC-scene invitations: who is inviting, who is being pulled in, the requested act, and the invitee's role.</div>
                <div style="margin-top: 8px;"><strong style="color: #FDF5D0;">#SPOUSE#</strong>: the PLAYER'S or NPC's spouse. In <em>marriage</em> prompts this is the partner (they are married); in <em>affair</em> prompts it is the absent spouse being cheated on.</div>
                <div style="margin-top: 8px;"><strong style="color: #FDF5D0;">#TIER#</strong>: the relationship tier label (Hostile, Wary, Neutral, Friendly, Fond, Devoted, Bonded).</div>
                <div style="margin-top: 8px;"><strong style="color: #FDF5D0;">#AFFINITY#</strong>: the NPC's numeric affinity toward the partner (-100 to +100).</div>
                <div style="margin-top: 8px;"><strong style="color: #FDF5D0;">#REL_TYPE#</strong>: the relationship-type label (e.g. lover, friend, acquaintance).</div>
                <div style="margin-top: 8px;"><strong style="color: #FDF5D0;">#AROUSAL#</strong>: the NPC's current arousal (sex_disposal) value.</div>
                <div style="margin-top: 8px;"><strong style="color: #FDF5D0;">#KINKS#</strong>: Injects the LIST of KINKS you have marked into a prompt.</div>
                <div style="margin-top: 8px;"><strong style="color: #FDF5D0;">#SECRET_KINKS#</strong>: Injects the LIST of HIGHER AFFINITY SECRET KINKS you have marked into a prompt.</div>
                <div style="margin-top: 8px;"><strong style="color: #FDF5D0;">#FATHER#</strong> / <strong style="color: #FDF5D0;">#MOTHER#</strong>: Fertility Mode only: the baby's father / the pregnant NPC.</div>
            </div>

            <h3 class="info-subtitle">NPC Extended Data</h3>
            <p style="color: #B8A8C8; margin-bottom: 10px;">Per-NPC NSFW state is stored in the <strong>nsfw_npc_data</strong> table (not core_npc_master). The live scene state lives under:</p>
            <div class="info-code-box">
                <div><strong>aiagent_nsfw_intimacy_data:</strong> {</div>
                <div style="margin-left: 15px;">
                    <div><strong>level:</strong> 0-2 (0: not in scene, 1: idle scene, 2: active scene)</div>
                    <div><strong>scene_phase:</strong> affection | tier_prompt | accepted | engaged | rejected</div>
                    <div><strong>intensity_tier:</strong> 1 innocent / 2 romantic / 3 sexual</div>
                    <div><strong>sex_disposal:</strong> 0-100 arousal (gates actions when the arousal gate is on)</div>
                    <div><strong>is_naked:</strong> 0|1 &nbsp;&middot;&nbsp; <strong>orgasm_generated:</strong> precached climax speech</div>
                </div>
                <div>}</div>
                <div style="margin-top: 10px;">Profile fields (set per-NPC in <strong>NPC Settings</strong>): sex_prompt, sex_speech_style, nsfw_kinks, nsfw_secret_kinks, is_prostitute, is_slave, spousal_status, sexual_orientation, relationship_preference.</div>
            </div>

            <h3 class="info-subtitle">Quick Links</h3>
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px; margin-top: 15px;">
                <button class="btn-secondary quick-link-btn" onclick="switchTab('scenes')" style="cursor: pointer;"><img src="images/ChimNSFWsoulgem.png" class="chim-icon"> Scenes Manager</button>
                <button class="btn-secondary quick-link-btn" onclick="switchTab('speakstyles')" style="cursor: pointer;"><img src="images/ChimNSFWsoulgem.png" class="chim-icon"> NPC Settings</button>
                <button class="btn-secondary quick-link-btn" onclick="switchTab('prompts')" style="cursor: pointer;"><img src="images/ChimNSFWsoulgem.png" class="chim-icon"> Prompts</button>
                <button class="btn-secondary quick-link-btn" onclick="switchTab('settings')" style="cursor: pointer;"><img src="images/ChimNSFWsoulgem.png" class="chim-icon"> Settings</button>
            </div>
        </div>
