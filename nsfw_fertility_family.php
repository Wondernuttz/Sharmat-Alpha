<?php

/**
 * Fertility / Royal Family context (FMR NG dynasty ingestion).
 *
 * FMR NG mirrors its lineage store to Data\SKSE\Plugins\FMRNG_Lineage.json
 * (atomic write) on every lineage change. This module locates that file from
 * inside the distro (the Windows drives are visible under /mnt/*), parses it,
 * and builds the placeholder data the Fertility-tab family prompts consume in
 * context_pre. Pure flavor: no toolsets, no gates, nothing here touches the
 * consent ladder.
 *
 * JSON contract (FMR NG Lineage::ToJson, version 3):
 *   { version, royalMode, count, children:[ { name, childId, gender(0 son/1 daughter),
 *     raceIndex, race, birthDay(game days), mother:{id,name}, father:{id,name},
 *     trainingStatus(0 child/1 training/2 trained/3 adopted), trainClass(0-10/-1),
 *     trainingStart, actorIndex, adultId, legitimate(bool), successionIndex(1-based/-1),
 *     heir(bool), title("Crown Prince(ss)"/"Prince(ss)"/"") } ] }
 *
 * Player children are identified by FormID 20 (0x14) on mother or father.
 * Missing file / unreadable JSON = the feature is silently inert.
 */

if (!defined('NSFW_FMRNG_PLAYER_FORMID')) {
    define('NSFW_FMRNG_PLAYER_FORMID', 20); // 0x14
}

/**
 * Resolve the FMRNG_Lineage.json path. Order:
 *   1. NSFW_FERTILITY_LINEAGE_PATH setting (Windows C:\ form auto-converted to /mnt/c/).
 *   2. Auto-probe of common MO2-overwrite / Steam Data locations under /mnt/*.
 * The auto-probe result is cached in a tmp file and revalidated with is_file();
 * while unresolved it re-probes at most once per 300s.
 *
 * @return string|null Absolute readable path, or null when not found.
 */
function aiagentNsfwLineagePath()
{
    static $resolved = false; // per-request memo; false = not computed yet
    if ($resolved !== false) {
        return $resolved;
    }
    $resolved = null;

    // 1) Explicit setting wins. Accept both native and Windows path forms.
    $cfg = trim((string)_getNsfwSetting('NSFW_FERTILITY_LINEAGE_PATH', ''));
    if ($cfg !== '') {
        if (preg_match('/^([A-Za-z]):[\\\\\\/]/', $cfg, $m)) {
            $cfg = '/mnt/' . strtolower($m[1]) . '/' . str_replace('\\', '/', substr($cfg, 3));
        }
        if (is_file($cfg) && is_readable($cfg)) {
            $resolved = $cfg;
        }
        return $resolved; // explicit path set: never auto-probe behind the user's back
    }

    // 2) Cached auto-probe result.
    $cacheFile = sys_get_temp_dir() . '/nsfw_fmrng_lineage_path.json';
    $cache = is_file($cacheFile) ? @json_decode((string)@file_get_contents($cacheFile), true) : null;
    if (is_array($cache) && !empty($cache['path']) && is_file($cache['path'])) {
        $resolved = $cache['path'];
        return $resolved;
    }
    $lastProbe = is_array($cache) ? (int)($cache['ts'] ?? 0) : 0;
    if ((time() - $lastProbe) < 300) {
        return null; // probed recently and found nothing; do not glob every request
    }

    // 3) Probe. The game writes "Data\SKSE\Plugins\FMRNG_Lineage.json" relative to its
    // exe; under MO2's VFS new files land in overwrite, under Vortex/manual in real Data.
    $patterns = [
        '/mnt/*/SkyrimVRmods/overwrite/SKSE/Plugins/FMRNG_Lineage.json',
        '/mnt/*/*/overwrite/SKSE/Plugins/FMRNG_Lineage.json',
        '/mnt/*/Users/*/AppData/Local/ModOrganizer/*/overwrite/SKSE/Plugins/FMRNG_Lineage.json',
        '/mnt/*/Program Files (x86)/Steam/steamapps/common/*/Data/SKSE/Plugins/FMRNG_Lineage.json',
        '/mnt/*/Steam/steamapps/common/*/Data/SKSE/Plugins/FMRNG_Lineage.json',
        '/mnt/*/SteamLibrary/steamapps/common/*/Data/SKSE/Plugins/FMRNG_Lineage.json',
        '/mnt/*/Games/steamapps/common/*/Data/SKSE/Plugins/FMRNG_Lineage.json',
        '/mnt/*/steamapps/common/*/Data/SKSE/Plugins/FMRNG_Lineage.json',
    ];
    $best = null;
    $bestMtime = -1;
    foreach ($patterns as $pat) {
        $hits = @glob($pat, GLOB_NOSORT);
        if (!is_array($hits)) {
            continue;
        }
        foreach ($hits as $hit) {
            $mt = @filemtime($hit);
            if ($mt !== false && $mt > $bestMtime && is_readable($hit)) {
                $best = $hit;
                $bestMtime = $mt;
            }
        }
    }
    @file_put_contents($cacheFile, json_encode(['path' => ($best ?? ''), 'ts' => time()]));
    if ($best !== null) {
        error_log("[CHIM-NSFW FERTILITY] lineage file resolved: {$best}");
        $resolved = $best;
    }
    return $resolved;
}

/**
 * Parsed FMRNG_Lineage.json (per-request cache). Null when absent/unreadable.
 */
function aiagentNsfwLineageData()
{
    static $data = false;
    if ($data !== false) {
        return $data;
    }
    $data = null;
    $path = aiagentNsfwLineagePath();
    if ($path === null) {
        return null;
    }
    $size = @filesize($path);
    if ($size === false || $size <= 2 || $size > 2097152) {
        return null; // torn/empty/absurd file: treat as absent
    }
    $raw = @file_get_contents($path);
    if ($raw === false) {
        return null;
    }
    $parsed = json_decode($raw, true);
    if (!is_array($parsed) || !isset($parsed['children']) || !is_array($parsed['children'])) {
        error_log("[CHIM-NSFW FERTILITY] lineage file unparseable, ignoring: {$path}");
        return null;
    }
    $data = $parsed;
    return $data;
}

/**
 * Current game time in game DAYS derived from the request gamets
 * (codebase constant: gamets * 0.0000024 = game hours). 0 when unknown.
 */
function aiagentNsfwCurrentGameDays()
{
    $g = (float)($GLOBALS['gameRequest'][2] ?? 0);
    if ($g <= 0) {
        return 0.0;
    }
    return ($g * 0.0000024) / 24.0;
}

/**
 * FMR training class index 0-10 to name (FMR class order).
 */
function aiagentNsfwFmrClassName($idx)
{
    $names = ['Mage', 'Warrior', 'Thief', 'Assassin', 'Bard', 'Stormcloak',
              'Legionnaire', 'Dragon Slayer', 'Dawnguard', 'Necromancer', 'Vigilant'];
    return ($idx >= 0 && $idx < count($names)) ? $names[$idx] : '';
}

/**
 * Build the full family context for the prompt block:
 *   royalMode, childCount, bastardCount, inLineCount, heirName, heirTitle,
 *   familySummary, bastardNames, expectingCount, expectingSummary.
 * Returns null when there is nothing to say (no player children AND no known
 * pregnancies fathered by the player).
 */
function aiagentNsfwFamilyContext()
{
    static $ctx = false;
    if ($ctx !== false) {
        return $ctx;
    }
    $ctx = null;

    $playerName = trim((string)($GLOBALS['PLAYER_NAME'] ?? ''));

    // ---- children from the lineage export ----
    $royalMode = false;
    $lines = [];
    $bastards = [];
    $inLineCount = 0;
    $heirName = '';
    $heirTitle = '';
    $lineage = aiagentNsfwLineageData();
    if (is_array($lineage)) {
        $royalMode = !empty($lineage['royalMode']);
        $nowDays = aiagentNsfwCurrentGameDays();
        foreach ($lineage['children'] as $ch) {
            if (!is_array($ch)) {
                continue;
            }
            $mId = (int)($ch['mother']['id'] ?? 0);
            $fId = (int)($ch['father']['id'] ?? 0);
            if ($mId !== NSFW_FMRNG_PLAYER_FORMID && $fId !== NSFW_FMRNG_PLAYER_FORMID) {
                continue; // only the player's own children belong in this context
            }
            $name = trim((string)($ch['name'] ?? ''));
            if ($name === '') {
                continue;
            }
            $isDaughter = ((int)($ch['gender'] ?? 0)) === 1;
            $legit = !empty($ch['legitimate']);
            $title = trim((string)($ch['title'] ?? ''));
            $succ  = (int)($ch['successionIndex'] ?? -1);
            $race  = trim((string)($ch['race'] ?? ''));

            $bits = [];
            $bits[] = ($race !== '' && $race !== 'Unknown' ? $race . ' ' : '') . ($isDaughter ? 'daughter' : 'son');
            // Legitimacy / royal standing
            if ($legit && $succ > 0) {
                $inLineCount++;
            }
            if ($royalMode && $title !== '') {
                $bits[] = $title . ($succ > 0 ? ', ' . aiagentNsfwOrdinal($succ) . ' in line to the throne' : '');
            } elseif ($legit) {
                $bits[] = 'legitimate';
            }
            if (!$legit) {
                $bits[] = 'a bastard, born out of wedlock';
                $bastards[] = $name;
            }
            if ($royalMode && $succ > 0 && !empty($ch['heir'])) {
                $heirName = $name;
                $heirTitle = ($title !== '') ? $title : ($isDaughter ? 'Crown Princess' : 'Crown Prince');
            }
            // Age (game days since birth); omit when game time is unknown or rolled back
            $birthDay = (float)($ch['birthDay'] ?? 0);
            if ($nowDays > 0 && $birthDay > 0 && $nowDays >= $birthDay) {
                $ageDays = (int)floor($nowDays - $birthDay);
                $bits[] = 'born ' . $ageDays . ' day' . ($ageDays === 1 ? '' : 's') . ' ago';
            }
            // Whereabouts / stage of life
            $ts = (int)($ch['trainingStatus'] ?? 0);
            $cls = aiagentNsfwFmrClassName((int)($ch['trainClass'] ?? -1));
            if ($ts === 1) {
                $bits[] = 'away at training' . ($cls !== '' ? ' as a ' . $cls : '');
            } elseif ($ts === 2) {
                $bits[] = 'grown' . ($cls !== '' ? ' and trained as a ' . $cls : '');
            } elseif ($ts === 3) {
                $bits[] = 'adopted into the family home';
            }
            // The other parent, when known and not the player
            $otherParent = '';
            if ($mId === NSFW_FMRNG_PLAYER_FORMID) {
                $fn = trim((string)($ch['father']['name'] ?? ''));
                if ($fn !== '' && strcasecmp($fn, $playerName) !== 0) {
                    $otherParent = 'father ' . $fn;
                }
            } else {
                $mn = trim((string)($ch['mother']['name'] ?? ''));
                if ($mn !== '' && strcasecmp($mn, $playerName) !== 0) {
                    $otherParent = 'mother ' . $mn;
                }
            }
            if ($otherParent !== '') {
                $bits[] = $otherParent;
            }
            $lines[] = $name . ' (' . implode('; ', $bits) . ')';
        }
    }

    // ---- current pregnancies the server knows from the FMR_* events ----
    $expecting = [];
    if ($playerName !== '' && isset($GLOBALS['db'])) {
        try {
            $esc = str_replace("'", "''", strtolower($playerName));
            $rows = $GLOBALS['db']->fetchAll(
                "SELECT npc_name,
                        extended_data->>'fertility_progress' AS prog
                   FROM nsfw_npc_data
                  WHERE (extended_data->>'fertility_is_pregnant') IN ('true','1')
                    AND LOWER(extended_data->>'fertility_father') = '$esc'
                  ORDER BY npc_name ASC
                  LIMIT 12"
            );
            if (is_array($rows)) {
                foreach ($rows as $row) {
                    $mother = trim((string)($row['npc_name'] ?? ''));
                    if ($mother === '') {
                        continue;
                    }
                    $prog = (int)($row['prog'] ?? 0);
                    if     ($prog >= 95) { $stage = 'due any day now'; }
                    elseif ($prog >= 67) { $stage = 'heavily pregnant'; }
                    elseif ($prog >= 34) { $stage = 'visibly pregnant'; }
                    else                 { $stage = 'early in her pregnancy'; }
                    $expecting[] = $mother . ' (' . $stage . ')';
                }
            }
        } catch (Exception $e) {
            // no DB, no pregnancies section; children half still works
        }
    }

    if (count($lines) === 0 && count($expecting) === 0) {
        return null;
    }

    $expectingSummary = '';
    if (count($expecting) > 0) {
        $expectingSummary = implode(', ', $expecting)
            . (count($expecting) === 1 ? ' is' : ' are')
            . ' carrying ' . ($playerName !== '' ? $playerName . "'s" : "the player's") . ' child';
    }

    $ctx = [
        'royalMode'        => $royalMode,
        'childCount'       => count($lines),
        'bastardCount'     => count($bastards),
        'inLineCount'      => $inLineCount,
        'heirName'         => $heirName,
        'heirTitle'        => $heirTitle,
        'familySummary'    => implode('; ', $lines),
        'bastardNames'     => implode(', ', $bastards),
        'expectingCount'   => count($expecting),
        'expectingSummary' => $expectingSummary,
    ];
    return $ctx;
}

/**
 * Human wording for a baby-loss / hazard cause (FMR frozen cause vocabulary).
 */
function aiagentNsfwFertilityCauseWording($cause)
{
    $map = [
        'skooma'     => 'skooma',
        'alcohol'    => 'drink',
        'drugs'      => 'drugs',
        'withdrawal' => 'withdrawal sickness',
        'combat'     => 'violence',
        'drowned'    => 'drowning',
        'submerged'  => 'drowning',
        'cold'       => 'the freezing cold',
        'frozen'     => 'the freezing cold',
        'exposure'   => 'exposure to the cold',
        'hungry'     => 'hunger',
        'neglect'    => 'neglect',
        'nightshade' => 'nightshade poisoning',
        'deathbell'  => 'deathbell poisoning',
    ];
    $c = strtolower(trim((string)$cause));
    if ($c === '' || $c === 'unknown') {
        return 'an unknown cause';
    }
    return $map[$c] ?? $c;
}

/**
 * Witness context: recent baby tragedies (FMR_BabyTragedy killer attribution),
 * losses, and babies in danger (FMR_BabyDamage/Stress) among OTHER NPCs, so
 * companions can react with grief or judgment. Excludes the speaking NPC (her
 * own #FERTILITY block covers first-person grief) and the player. One line per
 * mother, most severe recent fact wins (tragedy > loss > active stress > hurt).
 * Returns null when there is nothing to tell.
 *
 * @param string $selfName The NPC currently speaking (excluded from the news).
 */
function aiagentNsfwTragedyContext($selfName)
{
    static $memo = [];
    $memoKey = strtolower((string)$selfName);
    if (array_key_exists($memoKey, $memo)) {
        return $memo[$memoKey];
    }
    $memo[$memoKey] = null;

    if (!isset($GLOBALS['db'])) {
        return null;
    }
    $win = max(60, (int)_getNsfwSetting('NSFW_FERTILITY_EVENT_WINDOW_SECONDS', 1800));
    $now = time();
    $cutGrief  = $now - ($win * 4);   // tragedy + loss linger like first-person grief
    $cutRecent = $now - $win;         // plain damage is short-lived news

    $tragedies = [];
    $losses = [];
    $dangers = [];
    $lastKiller = '';
    $lastVictim = '';
    $lastTragTs = 0;
    try {
        $rows = $GLOBALS['db']->fetchAll(
            "SELECT npc_name,
                    extended_data->>'fertility_tragedy_kind'                   AS tkind,
                    extended_data->>'fertility_tragedy_killer'                 AS tkiller,
                    COALESCE(extended_data->>'fertility_tragedy_ts','0')       AS tts,
                    COALESCE(extended_data->>'fertility_loss_ts','0')          AS lts,
                    extended_data->>'fertility_miscarriage'                    AS misc,
                    extended_data->>'fertility_baby_lost'                      AS lost,
                    extended_data->>'fertility_miscarriage_cause'              AS mcause,
                    extended_data->>'fertility_baby_death_cause'               AS dcause,
                    extended_data->>'fertility_stress_cause'                   AS scause,
                    COALESCE(extended_data->>'fertility_damage_ts','0')        AS dts,
                    COALESCE(extended_data->>'fertility_baby_health','100')    AS bhealth
               FROM nsfw_npc_data
              WHERE COALESCE(extended_data->>'fertility_tragedy_ts','0')::bigint > $cutGrief
                 OR COALESCE(extended_data->>'fertility_loss_ts','0')::bigint > $cutGrief
                 OR (extended_data->>'fertility_stress_cause') IS NOT NULL
                 OR COALESCE(extended_data->>'fertility_damage_ts','0')::bigint > $cutRecent
              LIMIT 24"
        );
    } catch (Exception $e) {
        return null; // no DB = no witness news; the feature is inert
    }
    if (!is_array($rows)) {
        return null;
    }

    $playerName = trim((string)($GLOBALS['PLAYER_NAME'] ?? ''));
    foreach ($rows as $row) {
        $mother = trim((string)($row['npc_name'] ?? ''));
        if ($mother === ''
            || strcasecmp($mother, (string)$selfName) === 0
            || ($playerName !== '' && strcasecmp($mother, $playerName) === 0)
            || (function_exists('nsfwIsNarratorName') && nsfwIsNarratorName($mother))) {
            continue;
        }
        // 1) Violent tragedy with killer attribution (FMR_BabyTragedy)
        $tts = (int)($row['tts'] ?? 0);
        $kind = trim((string)($row['tkind'] ?? ''));
        if ($tts > $cutGrief && $kind !== '') {
            $killer = trim((string)($row['tkiller'] ?? ''));
            if ($killer === '') { $killer = 'someone'; }
            $kCap = ($killer === 'someone') ? 'Someone' : $killer;
            if     ($kind === 'killed_pregnant')    { $line = "$kCap killed $mother, who was pregnant - her unborn child died with her"; }
            elseif ($kind === 'killed_carrying')    { $line = "$kCap killed $mother with her baby in her arms - the child is dead too"; }
            elseif ($kind === 'beaten_miscarriage') { $line = "$kCap beat $mother so badly that she lost the child she was carrying"; }
            elseif ($kind === 'beaten_babydeath')   { $line = "$kCap struck down $mother's baby in her very arms"; }
            else                                    { $line = "$kCap brought tragedy on $mother and her child"; }
            $tragedies[] = $line;
            if ($tts >= $lastTragTs) { $lastTragTs = $tts; $lastKiller = $killer; $lastVictim = $mother; }
            continue;
        }
        // 2) Non-violent loss (miscarriage / infant death) within the grief window
        $lts = (int)($row['lts'] ?? 0);
        if ($lts > $cutGrief) {
            $isMisc = in_array(strtolower((string)($row['misc'] ?? '')), ['1', 'true'], true);
            $isLost = in_array(strtolower((string)($row['lost'] ?? '')), ['1', 'true'], true);
            if ($isMisc || $isLost) {
                $cause = aiagentNsfwFertilityCauseWording($isMisc ? ($row['mcause'] ?? '') : ($row['dcause'] ?? ''));
                $what = $isMisc ? 'her unborn child' : 'her baby';
                $losses[] = "$mother lost $what to $cause";
                continue;
            }
        }
        // 3) Baby in danger right now (active stress) or hurt very recently (damage)
        $scause = trim((string)($row['scause'] ?? ''));
        if ($scause !== '') {
            $dangers[] = "$mother's child is in danger right now from " . aiagentNsfwFertilityCauseWording($scause);
        } elseif ((int)($row['dts'] ?? 0) > $cutRecent) {
            $bh = (int)($row['bhealth'] ?? 100);
            $dangers[] = "$mother's child was hurt moments ago" . ($bh < 100 ? " and is weakened" : "");
        }
    }

    if (count($tragedies) === 0 && count($losses) === 0 && count($dangers) === 0) {
        return null;
    }
    $memo[$memoKey] = [
        'tragedyCount'   => count($tragedies),
        'tragedySummary' => implode('; ', array_slice($tragedies, 0, 4)),
        'killerName'     => $lastKiller,
        'victimName'     => $lastVictim,
        'lossCount'      => count($losses),
        'lossSummary'    => implode('; ', array_slice($losses, 0, 4)),
        'dangerCount'    => count($dangers),
        'dangerSummary'  => implode('; ', array_slice($dangers, 0, 4)),
    ];
    return $memo[$memoKey];
}

/**
 * 1 -> "first", 2 -> "second", ... falls back to "Nth".
 */
function aiagentNsfwOrdinal($n)
{
    $words = [1 => 'first', 2 => 'second', 3 => 'third', 4 => 'fourth', 5 => 'fifth',
              6 => 'sixth', 7 => 'seventh', 8 => 'eighth', 9 => 'ninth', 10 => 'tenth'];
    return $words[$n] ?? ($n . 'th');
}
