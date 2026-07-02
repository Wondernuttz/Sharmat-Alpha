#!/usr/bin/php
<?php
/**
 * NSFW Profile Worker — CADENCE TICK (user directive 2026-07-01)
 * ============================================================
 * Run by cron every minute to keep the background profile worker draining the "Add NPC" queue on a SERVER-SIDE
 * cadence, INDEPENDENT of the game. Previously the worker was only ensured from context.php (a game-request turn)
 * and idle-exits after 30s, so with no game running the queued NPCs never got built ("gold toggle does nothing
 * unless I'm playing"). This tick decouples it: cron re-ensures the detached daemon every minute so it wakes,
 * drains whatever is queued one-at-a-time, and idle-exits when done — like the relationship model runs at cadence.
 *
 * Respects the gold toggle: _nsfwEnsureProfileWorkerRunning() no-ops when AUTO_GENERATE_NSFW_PROFILES is off.
 * Lightweight: PID/pgrep guard prevents duplicate daemons; the actual LLM work runs OUTSIDE the MAIN semaphore
 * in the daemon, so it never blocks NPC dialogue / the game.
 */

// CLI only — never serve this over HTTP.
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("cli only\n"); }

$path = dirname(__FILE__) . '/../../';
require_once($path . 'conf/conf.php');
require_once($path . 'lib/postgresql.class.php');

$GLOBALS['ENGINE_PATH'] = realpath($path) . '/';
$GLOBALS['db'] = new sql();

require_once(__DIR__ . '/nsfw_profile_queue.php');

if (function_exists('_nsfwEnsureProfileWorkerRunning')) {
    // force=true: this is a dedicated tick process, so bypass the once-per-request static guard.
    _nsfwEnsureProfileWorkerRunning(true);
}
