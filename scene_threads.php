<?php

function aiagentNsfwSceneThreadLedgerPath()
{
    return sys_get_temp_dir() . "/aiagent_nsfw_scene_threads.json";
}

function aiagentNsfwSceneThreadNormalizeActors($actors)
{
    if (!is_array($actors)) {
        return [];
    }
    $out = [];
    foreach ($actors as $actor) {
        $actor = trim((string)$actor);
        if ($actor === '' || (function_exists('nsfwIsNarratorName') && nsfwIsNarratorName($actor))) {
            continue;
        }
        $out[] = $actor;
    }
    return array_values(array_unique($out));
}

function aiagentNsfwSceneThreadKey($scope, $idOrActors)
{
    $scope = preg_replace('/[^a-z0-9_-]/i', '_', strtolower((string)$scope));
    if (is_array($idOrActors)) {
        $actors = aiagentNsfwSceneThreadNormalizeActors($idOrActors);
        $fingerprint = array_map('strtolower', $actors);
        sort($fingerprint, SORT_STRING);
        $id = md5(implode('|', $fingerprint));
    } else {
        $id = preg_replace('/[^a-z0-9_-]/i', '_', strtolower((string)$idOrActors));
        if ($id === '') {
            $id = 'unknown';
        }
    }
    return $scope . "_" . $id;
}

function aiagentNsfwSceneThreadLoadLedger()
{
    $path = aiagentNsfwSceneThreadLedgerPath();
    $data = json_decode((string)@file_get_contents($path), true);
    if (!is_array($data)) {
        $data = [];
    }
    $now = time();
    foreach ($data as $key => $row) {
        $last = (int)($row['updated_at'] ?? $row['started_at'] ?? 0);
        if ($last > 0 && ($now - $last) > 7200) {
            unset($data[$key]);
        }
    }
    return $data;
}

function aiagentNsfwSceneThreadSaveLedger($ledger)
{
    @file_put_contents(aiagentNsfwSceneThreadLedgerPath(), json_encode($ledger, JSON_UNESCAPED_SLASHES), LOCK_EX);
}

function aiagentNsfwSceneThreadUpsert($key, $scope, $actors, $sceneId = '', $description = '', $event = '')
{
    $key = preg_replace('/[^a-z0-9_-]/i', '_', (string)$key);
    if ($key === '') {
        return '';
    }
    $ledger = aiagentNsfwSceneThreadLoadLedger();
    $now = time();
    $ledger[$key] = [
        'key' => $key,
        'scope' => (string)$scope,
        'actors' => aiagentNsfwSceneThreadNormalizeActors($actors),
        'scene_id' => (string)$sceneId,
        'description' => (string)$description,
        'event' => (string)$event,
        'gamets' => (float)($GLOBALS["gameRequest"][2] ?? 0),
        'started_at' => (int)($ledger[$key]['started_at'] ?? $now),
        'updated_at' => $now,
        'active' => true,
    ];
    aiagentNsfwSceneThreadSaveLedger($ledger);
    return $key;
}

function aiagentNsfwSceneThreadEndByKey($key)
{
    $key = preg_replace('/[^a-z0-9_-]/i', '_', (string)$key);
    if ($key === '') {
        return 0;
    }
    $ledger = aiagentNsfwSceneThreadLoadLedger();
    if (!isset($ledger[$key])) {
        return 0;
    }
    unset($ledger[$key]);
    aiagentNsfwSceneThreadSaveLedger($ledger);
    return 1;
}

function aiagentNsfwSceneThreadEndByActors($actors)
{
    $actors = array_map('strtolower', aiagentNsfwSceneThreadNormalizeActors($actors));
    if (empty($actors)) {
        return 0;
    }
    $ledger = aiagentNsfwSceneThreadLoadLedger();
    $removed = 0;
    foreach ($ledger as $key => $row) {
        $rowActors = array_map('strtolower', aiagentNsfwSceneThreadNormalizeActors($row['actors'] ?? []));
        if (!empty(array_intersect($actors, $rowActors))) {
            unset($ledger[$key]);
            $removed++;
        }
    }
    if ($removed > 0) {
        aiagentNsfwSceneThreadSaveLedger($ledger);
    }
    return $removed;
}

function aiagentNsfwSceneThreadIsActive($key)
{
    $key = preg_replace('/[^a-z0-9_-]/i', '_', (string)$key);
    if ($key === '') {
        return false;
    }
    $ledger = aiagentNsfwSceneThreadLoadLedger();
    if (empty($ledger[$key]['active'])) {
        return false;
    }
    $row = $ledger[$key];
    $scope = (string)($row['scope'] ?? '');
    $last = (int)($row['updated_at'] ?? 0);
    if ($last <= 0) {
        return false;
    }
    // Authoritative kill switch for PLAYER scenes: the instant the player's scene
    // ends (the player-scene ended-marker is stamped at/after this thread's last update
    // and not superseded by a newer player-scene-active heartbeat), the scene is over
    // NOW - strip the sex context on the next turn instead of waiting out the wall clock.
    //
    // CRITICAL: read the PLAYER-SPECIFIC ended/active markers, NOT the global ones.
    // nsfw_scene_ended.txt is stamped by EVERY scene end, including NPC-to-NPC scenes
    // (Rorlund/Erdi/etc. having OStim scenes nearby). The global marker is shared, so a
    // concurrent NPC-to-NPC scene ENDING would bump nsfw_scene_ended.txt past this live
    // player thread's last update and falsely kill it -> the Scene Fence then nulls the
    // player's scene_phase mid-scene -> consent gate fails -> "she never consented" at
    // orgasm. Only a scene the PLAYER was actually in stamps nsfw_player_scene_ended.txt.
    if (stripos($scope, 'player_scene') !== false) {
        $endedTs  = (int)(@file_get_contents(sys_get_temp_dir() . "/nsfw_player_scene_ended.txt") ?: 0);
        $activeTs = (int)(@file_get_contents(sys_get_temp_dir() . "/nsfw_player_scene_active.txt") ?: 0);
        if ($endedTs > 0 && $endedTs >= $last && $endedTs >= $activeTs) {
            return false;
        }
    }
    $staleSeconds = (stripos($scope, 'npc_scene') !== false) ? 180 : 900;
    return (time() - $last) <= $staleSeconds;
}

// Ledger-as-authority lookup BY ACTOR. Returns the active scene-thread row this actor is
// currently part of (with its description/actors/scene_id), or null if the ledger says the
// actor is not in any live scene. This is THE single source of truth for "is actor X in a
// scene right now" — every scene-context injection and lifecycle gate should consult this
// rather than the per-NPC intimacy fields, which are only a write-through cache.
function aiagentNsfwActorLiveSceneRow($actorName)
{
    $actorName = strtolower(trim((string)$actorName));
    if ($actorName === '') {
        return null;
    }
    $ledger = aiagentNsfwSceneThreadLoadLedger();
    foreach ($ledger as $key => $row) {
        $rowActors = array_map('strtolower', aiagentNsfwSceneThreadNormalizeActors($row['actors'] ?? []));
        if (in_array($actorName, $rowActors, true) && aiagentNsfwSceneThreadIsActive($key)) {
            return $row;
        }
    }
    return null;
}

function aiagentNsfwActorInLiveScene($actorName)
{
    return aiagentNsfwActorLiveSceneRow($actorName) !== null;
}

function aiagentNsfwSceneThreadTag($text, $key)
{
    $key = preg_replace('/[^a-z0-9_-]/i', '_', (string)$key);
    $text = (string)$text;
    if ($key === '' || strpos($text, '#SHARMAT_SCENE_THREAD:') !== false) {
        return $text;
    }
    return rtrim($text) . "\n#SHARMAT_SCENE_THREAD:" . $key;
}

// Retroactively tag the scene actors' SPOKEN chat lines (type='chat') with a thread key, so the context
// cleaner removes them from what the model sees once that key is inactive. Used at scene end to "snap" the
// explicit scene dialogue out of future context without deleting the eventlog record (the lines were already
// spoken in-game; the tag is added after, so it never reaches subtitles/TTS, and the cleaner strips the tag
// from any row it keeps). $sinceTs (epoch, matches eventlog.localts) bounds it to this scene's window.
function aiagentNsfwTagSceneChatForActors($actors, $key, $sinceTs = 0)
{
    if (empty($GLOBALS["db"]) || !is_array($actors)) {
        return 0;
    }
    $key = preg_replace('/[^a-z0-9_-]/i', '_', (string)$key);
    if ($key === '') {
        return 0;
    }
    $sinceTs = (int)$sinceTs;
    $tagged = 0;
    foreach (aiagentNsfwSceneThreadNormalizeActors($actors) as $actor) {
        $esc = $GLOBALS["db"]->escape($actor);
        $where = "type = 'chat' AND people LIKE '%|" . $esc . "|%' AND data NOT LIKE '%#SHARMAT_SCENE_THREAD:%'";
        if ($sinceTs > 0) {
            $where .= " AND localts >= " . $sinceTs;
        }
        $GLOBALS["db"]->query("UPDATE eventlog SET data = data || E'\\n#SHARMAT_SCENE_THREAD:" . $key . "' WHERE " . $where);
        $tagged++;
    }
    return $tagged;
}

function aiagentNsfwSceneThreadStripTags($text)
{
    return preg_replace('/\s*#SHARMAT_SCENE_THREAD:[A-Za-z0-9_-]+/', '', (string)$text);
}

function aiagentNsfwSceneThreadContextKey($text)
{
    if (preg_match('/#SHARMAT_SCENE_THREAD:([A-Za-z0-9_-]+)/', (string)$text, $m)) {
        return $m[1];
    }
    return '';
}

function aiagentNsfwSceneThreadCleanContextArray(&$rows, $contentField = 'content')
{
    if (!is_array($rows)) {
        return 0;
    }
    $taggedByThread = [];
    foreach ($rows as $idx => $row) {
        $content = is_array($row) ? (string)($row[$contentField] ?? '') : '';
        $key = aiagentNsfwSceneThreadContextKey($content);
        if ($key === '') {
            continue;
        }
        if (!aiagentNsfwSceneThreadIsActive($key)) {
            unset($rows[$idx]);
            continue;
        }
        $taggedByThread[$key][] = $idx;
    }
    foreach ($taggedByThread as $key => $idxs) {
        array_pop($idxs);
        foreach ($idxs as $idx) {
            unset($rows[$idx]);
        }
    }
    foreach ($rows as $idx => $row) {
        if (is_array($row) && isset($row[$contentField])) {
            $rows[$idx][$contentField] = aiagentNsfwSceneThreadStripTags($row[$contentField]);
        }
    }
    $rows = array_values($rows);
    return count($taggedByThread);
}
