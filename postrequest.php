<?php

require_once __DIR__ . "/common.php";

if (!function_exists('aiagentNsfwProcessSapConsumeTimers')) {
    function aiagentNsfwProcessSapConsumeTimers() {
        if (empty($GLOBALS["db"]) || !_getNsfwSetting("DRUGS_ENABLED", true) || !_getNsfwSetting("DRUG_ANIMATIONS", true)) {
            return;
        }

        $sapRegex = $GLOBALS["db"]->escape(function_exists('aiagentNsfwSapRegex') ? aiagentNsfwSapRegex() : 'sleeping[ -]?tree[ -]?sap|tree sap');
        $doseActionRegex = $GLOBALS["db"]->escape(function_exists('aiagentNsfwDrugDoseActionRegex') ? aiagentNsfwDrugDoseActionRegex() : '^Consume$');
        $lookback = time() - 900;
        $rows = $GLOBALS["db"]->fetchAll(
            "SELECT rowid, actorname, localts, gamets, fullcall
             FROM public.actions_issued
             WHERE localts >= {$lookback}
               AND action ~* '{$doseActionRegex}'
               AND fullcall ~* '{$sapRegex}'
             ORDER BY rowid ASC
             LIMIT 50"
        );
        if (!is_array($rows) || count($rows) === 0) {
            return;
        }

        if (!class_exists('Npcmaster')) { require_once __DIR__ . "/../../lib/core/npc_master.class.php"; }
        $npcMaster = new Npcmaster();
        $fallDelay = (int)_getNsfwSetting("SAP_FALL_AFTER_DRINK_SECONDS", (int)_getNsfwSetting("DRUNK_FALL_AFTER_DRINK_SECONDS", 8));
        if ($fallDelay < 0) { $fallDelay = 0; }
        $rouseDelay = (int)_getNsfwSetting("SAP_AUTO_ROUSE_SECONDS", 1080);
        if ($rouseDelay < 60) { $rouseDelay = 60; }

        foreach ($rows as $row) {
            $actorName = trim((string)($row["actorname"] ?? ""));
            $rowid = (int)($row["rowid"] ?? 0);
            $localts = (int)($row["localts"] ?? time());
            if ($actorName === '' || $rowid <= 0) {
                continue;
            }

            $data = NsfwNpcData::get($actorName);
            $processed = (int)($data["aiagent_nsfw_sap_last_processed_action"] ?? 0);
            if ($processed >= $rowid) {
                continue;
            }

            $refid = $npcMaster->getByName($actorName)["refid"] ?? null;
            if (empty($refid)) {
                continue;
            }

            $dropAt = max(time(), $localts + $fallDelay);
            $rouseAt = $dropAt + $rouseDelay;
            if (aiagentNsfwQueueSapDropAndRouse($actorName, $refid, $dropAt, $rouseAt)) {
                $data["aiagent_nsfw_sap_last_processed_action"] = $rowid;
                $data["aiagent_nsfw_drug_down"] = 1;
                $data["aiagent_nsfw_sap_paralysis_applied"] = 1;
                $data["aiagent_nsfw_sap_rouse_due_localts"] = $rouseAt;
                NsfwNpcData::save($actorName, $data);
                error_log("[AIAGENTNSFW] Postrequest queued sap drop/rouse for {$actorName} action {$rowid}");
            }
        }
    }
}

if (!function_exists('aiagentNsfwProcessSkoomaConsumeCheer')) {
    // Fire the skooma post-consume CHEER right after the consume (scheduled ~3s later, like the toast's near-immediate
    // PlayIdle), instead of relying ONLY on context.php's 8s-delayed conditional which fires when the NPC has already
    // returned to default/sandbox so the in-game override gets dropped. Deduped per dose via the SAME tracker
    // context.php uses (aiagent_nsfw_skooma_ritual_dose_lt) so the two paths never double-fire.
    function aiagentNsfwProcessSkoomaConsumeCheer() {
        if (empty($GLOBALS["db"]) || !_getNsfwSetting("DRUGS_ENABLED", true) || !_getNsfwSetting("DRUG_ANIMATIONS", true)) {
            return;
        }
        $cheerIdle = trim((string)_getNsfwSetting("SKOOMA_POST_CONSUME_IDLE", "IdleCivilWarCheer"));
        if ($cheerIdle === '' || !function_exists('aiagentNsfwSendIdle')) {
            return;
        }
        $skoomaRegex = $GLOBALS["db"]->escape(function_exists('aiagentNsfwSkoomaRegex') ? aiagentNsfwSkoomaRegex() : 'skooma|skuma|scuma|schuma');
        $doseActionRegex = $GLOBALS["db"]->escape(function_exists('aiagentNsfwDrugDoseActionRegex') ? aiagentNsfwDrugDoseActionRegex() : '^Consume$');
        $delay = (int)_getNsfwSetting("SKOOMA_CONSUME_CHEER_DELAY_SECONDS", 3);
        if ($delay < 0) { $delay = 0; }
        $lookback = time() - 60; // only FRESH consumes (snappy override; avoids replaying stale doses)
        $rows = $GLOBALS["db"]->fetchAll(
            "SELECT rowid, actorname, localts FROM public.actions_issued
             WHERE localts >= {$lookback} AND action ~* '{$doseActionRegex}' AND fullcall ~* '{$skoomaRegex}'
             ORDER BY rowid ASC LIMIT 20"
        );
        if (!is_array($rows) || count($rows) === 0) {
            return;
        }
        if (!class_exists('Npcmaster')) { require_once __DIR__ . "/../../lib/core/npc_master.class.php"; }
        $npcMaster = new Npcmaster();
        foreach ($rows as $row) {
            $actorName = trim((string)($row["actorname"] ?? ""));
            $localts = (int)($row["localts"] ?? time());
            if ($actorName === '') { continue; }
            $data = NsfwNpcData::get($actorName);
            if (!empty($data["aiagent_nsfw_drug_down"]) || !empty($data["aiagent_nsfw_unconscious"])) { continue; }
            // dedup per dose (shared with context.php so they don't double-fire)
            if ((int)($data["aiagent_nsfw_skooma_ritual_dose_lt"] ?? 0) >= $localts) { continue; }
            $refid = $npcMaster->getByName($actorName)["refid"] ?? null;
            if (empty($refid)) { continue; }
            if (aiagentNsfwSendIdle($actorName, $refid, $cheerIdle, $localts + $delay)) {
                $data["aiagent_nsfw_skooma_ritual_dose_lt"] = $localts;
                $data["aiagent_nsfw_skooma_dance_ts"] = time();
                NsfwNpcData::save($actorName, $data);
                error_log("[AIAGENTNSFW] Postrequest skooma consume CHEER queued for {$actorName} (idle {$cheerIdle}, +{$delay}s)");
            }
        }
    }
}

if (!function_exists('aiagentNsfwRetryOverdueSapRouses')) {
    function aiagentNsfwRetryOverdueSapRouses() {
        if (empty($GLOBALS["db"]) || !_getNsfwSetting("DRUGS_ENABLED", true) || !_getNsfwSetting("DRUG_ANIMATIONS", true)) {
            return;
        }

        NsfwNpcData::ensureTable();

        $now = time();
        $retryAfter = $now - 60;
        $rows = $GLOBALS["db"]->fetchAll(
            "SELECT npc_name, extended_data
             FROM nsfw_npc_data
             WHERE (
                    extended_data ? 'aiagent_nsfw_sap_paralysis_applied'
                    OR extended_data ? 'aiagent_nsfw_drug_down'
                   )
               AND (extended_data->>'aiagent_nsfw_sap_rouse_due_localts') ~ '^[0-9]+$'
               AND (extended_data->>'aiagent_nsfw_sap_rouse_due_localts')::bigint <= {$now}
               AND (
                    NOT (extended_data ? 'aiagent_nsfw_sap_last_rouse_retry_localts')
                    OR (
                        (extended_data->>'aiagent_nsfw_sap_last_rouse_retry_localts') ~ '^[0-9]+$'
                        AND (extended_data->>'aiagent_nsfw_sap_last_rouse_retry_localts')::bigint <= {$retryAfter}
                    )
                   )
             ORDER BY updated_at ASC
             LIMIT 25"
        );
        if (!is_array($rows) || count($rows) === 0) {
            return;
        }

        if (!class_exists('Npcmaster')) { require_once __DIR__ . "/../../lib/core/npc_master.class.php"; }
        $npcMaster = new Npcmaster();

        foreach ($rows as $row) {
            $actorName = trim((string)($row["npc_name"] ?? ""));
            if ($actorName === '') {
                continue;
            }

            $data = json_decode($row["extended_data"] ?? "{}", true);
            if (!is_array($data)) {
                $data = NsfwNpcData::get($actorName);
            }

            $refid = $npcMaster->getByName($actorName)["refid"] ?? null;
            if (empty($refid)) {
                continue;
            }

            if (aiagentNsfwQueueSapRouse($actorName, $refid, $now)) {
                $data["aiagent_nsfw_sap_last_rouse_retry_localts"] = $now;
                $data["aiagent_nsfw_sap_rouse_retry_count"] = ((int)($data["aiagent_nsfw_sap_rouse_retry_count"] ?? 0)) + 1;
                NsfwNpcData::save($actorName, $data);
                error_log("[AIAGENTNSFW] Retried overdue sap rouse for {$actorName}");
            }
        }
    }
}

if (!function_exists('aiagentNsfwRetryDrunkRouses')) {
    function aiagentNsfwRetryDrunkRouses() {
        if (empty($GLOBALS["db"]) || !_getNsfwSetting("DRUNK_ANIMATIONS", true)) {
            return;
        }

        NsfwNpcData::ensureTable();

        $now = time();
        $retryAfter = $now - 60;
        $rows = $GLOBALS["db"]->fetchAll(
            "SELECT npc_name, extended_data
             FROM nsfw_npc_data
             WHERE extended_data ? 'aiagent_nsfw_unconscious'
               AND (
                    NOT (extended_data ? 'aiagent_nsfw_drunk_rouse_last_retry_localts')
                    OR (
                        (extended_data->>'aiagent_nsfw_drunk_rouse_last_retry_localts') ~ '^[0-9]+$'
                        AND (extended_data->>'aiagent_nsfw_drunk_rouse_last_retry_localts')::bigint <= {$retryAfter}
                    )
                   )
             ORDER BY updated_at ASC
             LIMIT 25"
        );
        if (!is_array($rows) || count($rows) === 0) {
            return;
        }

        if (!class_exists('Npcmaster')) { require_once __DIR__ . "/../../lib/core/npc_master.class.php"; }
        $npcMaster = new Npcmaster();

        foreach ($rows as $row) {
            $actorName = trim((string)($row["npc_name"] ?? ""));
            if ($actorName === '') {
                continue;
            }

            if (getDrunkStageForActor($actorName) >= 10) {
                continue;
            }

            $data = json_decode($row["extended_data"] ?? "{}", true);
            if (!is_array($data)) {
                $data = NsfwNpcData::get($actorName);
            }

            $refid = $npcMaster->getByName($actorName)["refid"] ?? null;
            if (empty($refid)) {
                continue;
            }

            if (aiagentNsfwQueueDrunkRouse($actorName, $refid, $now)) {
                $data["aiagent_nsfw_drunk_rouse_last_retry_localts"] = $now;
                $data["aiagent_nsfw_drunk_rouse_retry_count"] = ((int)($data["aiagent_nsfw_drunk_rouse_retry_count"] ?? 0)) + 1;
                NsfwNpcData::save($actorName, $data);
                error_log("[AIAGENTNSFW] Retried drunk rouse for {$actorName}");
            }
        }
    }
}

if (!function_exists('aiagentNsfwReconcileRuntimeAfterRollbackGlobal')) {
    function aiagentNsfwReconcileRuntimeAfterRollbackGlobal() {
        if (empty($GLOBALS["db"]) || !function_exists('aiagentNsfwReconcileActorRuntimeAfterRollback')) {
            return;
        }

        $now = (float)($GLOBALS["gameRequest"][2] ?? 0);
        if ($now <= 0) {
            return;
        }

        NsfwNpcData::ensureTable();
        $rows = $GLOBALS["db"]->fetchAll(
            "SELECT npc_name
             FROM nsfw_npc_data
             WHERE extended_data ? 'skooma_state'
                OR extended_data ? 'skooma_state_gamets'
                OR extended_data ? 'skooma_last_dose'
                OR extended_data ? 'aiagent_nsfw_prompt_skooma_level'
                OR extended_data ? 'aiagent_nsfw_skooma_av'
                OR extended_data ? 'aiagent_nsfw_skooma_speed'
                OR extended_data ? 'aiagent_nsfw_skooma_dance_ts'
                OR extended_data ? 'aiagent_nsfw_skooma_crazed_ts'
                OR extended_data ? 'aiagent_nsfw_prompt_sap_level'
                OR extended_data ? 'aiagent_nsfw_drug_down'
                OR extended_data ? 'aiagent_nsfw_sap_paralysis_applied'
                OR extended_data ? 'aiagent_nsfw_prompt_drunk_stage'
                OR extended_data ? 'aiagent_nsfw_last_time_drunk'
                OR extended_data ? 'aiagent_nsfw_drunk_av'
                OR (
                    extended_data ? 'aiagent_nsfw_intimacy_data'
                    AND (extended_data->'aiagent_nsfw_intimacy_data'->>'gamets') ~ '^[0-9]+(\\.[0-9]+)?$'
                    AND (extended_data->'aiagent_nsfw_intimacy_data'->>'gamets')::float > {$now}
                )
             ORDER BY updated_at ASC
             LIMIT 100"
        );
        if (!is_array($rows) || count($rows) === 0) {
            return;
        }

        foreach ($rows as $row) {
            $actorName = trim((string)($row["npc_name"] ?? ""));
            if ($actorName !== '') {
                aiagentNsfwReconcileActorRuntimeAfterRollback($actorName, $now);
            }
        }
    }
}

try {
    aiagentNsfwReconcileRuntimeAfterRollbackGlobal();
    aiagentNsfwProcessSapConsumeTimers();
    aiagentNsfwProcessSkoomaConsumeCheer();
    aiagentNsfwRetryOverdueSapRouses();
    aiagentNsfwRetryDrunkRouses();
} catch (Throwable $e) {
    error_log("[AIAGENTNSFW] Sap timer postrequest failed: " . $e->getMessage());
}

// OSLA BRIDGE: after every turn, mirror this actor's (possibly changed) arousal into OSL Aroused.
try {
    if (function_exists('aiagentNsfwQueueOslaArousalSyncForTurn')) {
        aiagentNsfwQueueOslaArousalSyncForTurn();
    }
} catch (Throwable $e) {
    error_log("[AIAGENTNSFW] OSLA sync postrequest failed: " . $e->getMessage());
}

?>
