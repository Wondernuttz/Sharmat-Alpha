<?php

// Mirror persisted plugin state, including bulk cleanup, without creating NPC history.
function sharmatSyncNpcPluginData($npcName = null) {
    static $supported = null;
    if (!isset($GLOBALS['db'])) return;
    try {
        if ($supported === null) {
            $column = $GLOBALS['db']->fetchOne("SELECT 1 AS supported FROM information_schema.columns
                WHERE table_schema = 'public' AND table_name = 'core_npc_master'
                AND column_name = 'plugin_extended_data'");
            $supported = !empty($column['supported']);
        }
        if (!$supported) return;
        $name = $npcName === null ? null : strtolower(str_replace('_', ' ', trim($npcName)));
        // Keep the JSON copy in one SQL statement so bulk resets preserve other namespaces.
        // Multiple core or legacy rows with the same normalized name are deliberately skipped.
        $GLOBALS['db']->fetchOne("WITH candidates AS (
            SELECT npc.id, state.extended_data AS data,
                count(*) OVER (PARTITION BY lower(replace(npc.npc_name, '_', ' '))) AS matches
            FROM public.core_npc_master npc JOIN nsfw_npc_data state
                ON lower(replace(npc.npc_name, '_', ' ')) = lower(replace(state.npc_name, '_', ' '))
            WHERE ($1::text IS NULL OR lower(replace(npc.npc_name, '_', ' ')) = $1)
        ), updated AS (
            UPDATE public.core_npc_master npc
            SET plugin_extended_data = jsonb_set(npc.plugin_extended_data, '{sharmat}', candidate.data, true)
            FROM candidates candidate WHERE candidate.id = npc.id AND candidate.matches = 1
                AND jsonb_typeof(candidate.data) = 'object'
                AND npc.plugin_extended_data->'sharmat' IS DISTINCT FROM candidate.data
            RETURNING npc.id
        ) SELECT count(*) AS updated FROM updated", [$name]);
    } catch (Throwable $e) {
        error_log('[SHARMAT] Could not sync NPC plugin data; plugin state retained.');
    }
}
