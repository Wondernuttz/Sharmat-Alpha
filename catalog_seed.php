<?php
/**
 * Sharmat-Alpha catalog seeder.
 *
 * action_rework's processor/funcret.php gates follow-up execution on the action
 * existing in core_action with metadata.followup.enabled=true. Extensions load
 * after the base catalog is seeded, so Sharmat's actions are absent and the
 * gate terminates them. This file UPSERTs Sharmat's ExtCmd* actions into
 * core_action with followup enabled so the gate passes and the new catalog
 * editor UI shows them.
 *
 * Idempotent: per-request guard via $GLOBALS, plus ON CONFLICT DO UPDATE so
 * upstream catalog refreshes do not erase Sharmat rows.
 */

if (!function_exists('aiagent_nsfw_seed_action_catalog')) {

// Mirror functions.php FUNCTIONS[...]['parameters'] so the 2.8.4 catalog (now
// authoritative for the LLM tool schema) does not clobber NSFW action args with [].
function aiagent_nsfw_action_params($code)
{
    $target = function ($desc) {
        return [
            'type' => 'object',
            'properties' => ['target' => ['type' => 'string', 'description' => $desc]],
            'required' => ['target'],
        ];
    };
    switch ($code) {
        case 'ExtCmdSexCommand':
            return $target('Sexual act/position/practice (e.g. blowjob, boobjob, analsex, vaginalsex)');
        case 'ExtCmdStartThreesome':
            return $target('Partner NPCs to include, comma-separated');
        case 'ExtCmdDrinkBloodSex':
            return $target('Target to bite and feed on');
        case 'ExtCmdConsumeSoul':
            return $target('Victim, captured foe');
        case 'ExtCmdCollectPayment':
            return [
                'type' => 'object',
                'properties' => [
                    'amount'  => ['type' => 'integer', 'description' => 'Gold amount to collect (e.g., 50, 100, 250)'],
                    'service' => ['type' => 'string', 'description' => 'Brief description of services'],
                ],
                'required' => ['amount'],
            ];
        case 'ExtCmdStopNpcScene':
            return [
                'type' => 'object',
                'properties' => [
                    'reason' => ['type' => 'string', 'description' => 'Brief reason for stopping the NPC-to-NPC scene'],
                ],
                'required' => [],
            ];
        case 'ExtCmdPoisonMasterFood':
            return [
                'type' => 'object',
                'properties' => [
                    'reason' => ['type' => 'string', 'description' => 'Private reason for secretly poisoning the player. Do not say this out loud.'],
                ],
                'required' => [],
            ];
        case 'ExtCmdGiveFreeService':
            return [
                'type' => 'object',
                'properties' => [
                    'reason' => ['type' => 'string', 'description' => 'Brief in-character reason for not charging this time (e.g., "this one is on me")'],
                ],
                'required' => [],
            ];
        case 'ExtCmdWorshipMaster':
            return [
                'type' => 'object',
                'properties' => [
                    'reason' => ['type' => 'string', 'description' => 'Brief in-character expression of devotion to your master.'],
                ],
                'required' => [],
            ];
        case 'ExtCmdAskForFreedom':
            return [
                'type' => 'object',
                'properties' => [
                    'reason' => ['type' => 'string', 'description' => 'In-character reason for asking your master to free you.'],
                ],
                'required' => [],
            ];
        case 'ExtCmdAcceptFreedom':
            return [
                'type' => 'object',
                'properties' => [
                    'reason' => ['type' => 'string', 'description' => 'Brief reaction to being freed. ONLY use this if your master explicitly agreed to free you.'],
                ],
                'required' => [],
            ];
        case 'ExtCmdQuickenPace':
        case 'ExtCmdSlowPace':
            return [
                'type' => 'object',
                'properties' => [
                    'reason' => ['type' => 'string', 'description' => 'Optional in-character line about changing the pace.'],
                ],
                'required' => [],
            ];
        default:
            return $target('Target NPC, Actor, or being');
    }
}

function aiagent_nsfw_seed_action_catalog()
{
    if (!empty($GLOBALS['AIAGENT_NSFW_CATALOG_SEEDED'])) {
        return;
    }
    $GLOBALS['AIAGENT_NSFW_CATALOG_SEEDED'] = true;

    if (!isset($GLOBALS['db']) || !is_object($GLOBALS['db'])) {
        return;
    }

    if (!function_exists('herikaActionCatalogSqlText')
        || !function_exists('herikaActionCatalogSqlJson')
        || !function_exists('herikaActionCatalogSqlBool')) {
        return;
    }

    $followupPromptDefault = 'Reply with one short in-character line reacting to the action result below. Do not ask follow-up questions.';

    $actions = [
        ['code' => 'ExtCmdHug',                   'name' => 'GiveHug',                   'desc' => 'Embrace the target affectionately.',                  'arg' => 'target'],
        ['code' => 'ExtCmdHoldHands',             'name' => 'HoldHands',                 'desc' => 'Hold the target actor\'s hand affectionately.',        'arg' => 'target'],
        ['code' => 'ExtCmdRemoveClothes',         'name' => 'RemoveClothes',        'desc' => 'Remove your own or target\'s clothing.',              'arg' => 'target'],
        ['code' => 'ExtCmdStartSex',              'name' => 'MakeLove',             'desc' => 'Initiate intimate scene with target.',                'arg' => 'target'],
        ['code' => 'ExtCmdStartBlowJob',          'name' => 'GiveOralSex',        'desc' => 'Initiate oral scene with target.',                    'arg' => 'target'],
        ['code' => 'ExtCmdStartAnalSex',          'name' => 'StartAnalSex',        'desc' => 'Initiate anal scene with target.',                    'arg' => 'target'],
        ['code' => 'ExtCmdStartMassage',          'name' => 'GiveMassage',         'desc' => 'Initiate massage scene with target.',                 'arg' => 'target'],
        ['code' => 'ExtCmdStartThreesome',        'name' => 'StartThreesome',       'desc' => 'Initiate group scene with one or more targets (comma-separated).',          'arg' => 'target'],
        ['code' => 'ExtCmdStartHandJobSex',       'name' => 'Masturbate',        'desc' => 'Initiate handjob with target.',                       'arg' => 'target'],
        ['code' => 'ExtCmdStartTitfuck',          'name' => 'StartBoobjob',         'desc' => 'Initiate titfuck with target.',                       'arg' => 'target'],
        ['code' => 'ExtCmdDrinkBloodSex',         'name' => 'DrinkBlood',           'desc' => 'VAMPIRE ONLY: initiate an intimate blood-feeding scene, sinking your fangs into the target\'s neck.', 'arg' => 'target'],
        ['code' => 'ExtCmdStartSelfMasturbation', 'name' => 'StartSelfMasturbation',     'desc' => 'Self pleasure scene.',                                'arg' => 'target'],
        ['code' => 'ExtCmdKiss',                  'name' => 'Kiss',                  'desc' => 'Kiss target.',                                        'arg' => 'target'],
        ['code' => 'ExtCmdPutOnClothes',          'name' => 'PutOnClothes',        'desc' => 'Re-dress.',                                           'arg' => 'target'],
        ['code' => 'ExtCmdAcceptSex',             'name' => 'AcceptSex',            'desc' => 'Accept a sexual proposition.',                        'arg' => 'target'],
        ['code' => 'ExtCmdRefuseSex',             'name' => 'RefuseSex',            'desc' => 'Decline a sexual proposition.',                       'arg' => 'target'],
        ['code' => 'ExtCmdStopScene',             'name' => 'EndSexScene',            'desc' => 'End and EXIT the active SEX scene you are currently in (the live OStim/SexLab intimate scene). Call this to stop having sex and leave the scene - when finished, satisfied, uncomfortable, or interrupted. This is the ONLY way to end an ongoing sex scene.',                     'arg' => 'target'],
        // ExtCmdStopNpcScene / EndNpcScene PERMANENTLY REMOVED from the catalog (user directive 2026-06-28/29):
        // its end-call was unreliable and tore down the PLAYER's scene instead of the NPC-to-NPC thread. It is NOT
        // seeded here, and the live DB row is deactivated. functions.php ALSO strips it from ENABLED_FUNCTIONS every
        // turn as a safety net. Do NOT re-add this row. An in-scene NPC reacts in dialogue only; it cannot end scenes.
        ['code' => 'ExtCmdSexCommand',            'name' => 'SexAction',           'desc' => 'Issue a directive during an active scene.',           'arg' => 'command'],
        ['code' => 'ExtCmdQuickenPace',           'name' => 'QuickenPace',          'desc' => 'Speed up the pace/tempo of the active sex scene.',      'arg' => 'reason'],
        ['code' => 'ExtCmdSlowPace',              'name' => 'SlowPace',             'desc' => 'Slow down the pace/tempo of the active sex scene.',     'arg' => 'reason'],
        ['code' => 'ExtCmdConsumeSoul',           'name' => 'RitualConsumeSoul',          'desc' => 'Soul absorption finisher.',                           'arg' => 'target'],
        ['code' => 'ExtCmdCollectPayment',        'name' => 'TakeGold',       'desc' => 'Take agreed gold for negotiated prostitute services.',  'arg' => 'amount'],
        ['code' => 'ExtCmdQuitProstitution',      'name' => 'QuitProstitution',     'desc' => 'Stop working as a prostitute for the player - no longer charge (offered only at top affinity).', 'arg' => 'target'],
        ['code' => 'ExtCmdGiveFreeService',       'name' => 'GiveFreeService',     'desc' => 'Choose to give this one service for free - no charge, no price modifier (offered at Devoted+; stays a prostitute, unlike Quit).', 'arg' => 'reason'],
        ['code' => 'ExtCmdPoisonMasterFood',      'name' => 'PoisonMasterFood',       'desc' => 'Secretly arm a slow poison on the player\'s next eligible consumable. Only exposed to qualifying hostile/hateful slaves.', 'arg' => 'reason'],
        ['code' => 'ExtCmdWorshipMaster',         'name' => 'WorshipMaster',          'desc' => 'A slave kneels and prays in devotion to their master (plays the pray animation). Slaves only.', 'arg' => 'reason'],
        ['code' => 'ExtCmdAskForFreedom',         'name' => 'AskForFreedom',          'desc' => 'A slave pleads with their master for freedom - a request only; the master must explicitly agree. Offered only when the master enables it.', 'arg' => 'reason'],
        ['code' => 'ExtCmdAcceptFreedom',         'name' => 'AcceptFreedom',          'desc' => 'A slave is freed after the master explicitly agrees - clears slave status. Offered only after AskForFreedom.', 'arg' => 'reason'],
    ];

    foreach ($actions as $a) {
        $followupEnabled = ($a['code'] !== 'ExtCmdPoisonMasterFood');
        $metadata = [
            'dispatch'  => 'plugin_command',
            'builtin'   => false,
            'status'    => 'active',
            'source'    => 'aiagent_nsfw',
            'extension' => 'aiagent_nsfw',
            'followup'  => [
                'enabled'  => $followupEnabled,
                'arg_name' => $a['arg'],
                'prompt'   => $followupPromptDefault,
            ],
        ];

        $sql = "INSERT INTO public.core_action (
                code_name, action_name, description, return_message,
                available_to_npc, available_to_followers, available_to_narrator,
                is_activated, parameters_json, metadata, game_function,
                import_version, script_proxy_program
            ) VALUES (
                " . herikaActionCatalogSqlText($a['code']) . ",
                " . herikaActionCatalogSqlText($a['name']) . ",
                " . herikaActionCatalogSqlText($a['desc']) . ",
                '',
                " . herikaActionCatalogSqlBool(true) . ",
                " . herikaActionCatalogSqlBool(true) . ",
                " . herikaActionCatalogSqlBool(false) . ",
                " . herikaActionCatalogSqlBool(true) . ",
                " . herikaActionCatalogSqlJson(aiagent_nsfw_action_params($a['code'])) . ",
                " . herikaActionCatalogSqlJson($metadata) . ",
                " . herikaActionCatalogSqlBool(false) . ",
                1,
                " . herikaActionCatalogSqlJson(null, true) . "
            )
            ON CONFLICT (code_name) DO UPDATE SET
                action_name     = EXCLUDED.action_name,
                description     = EXCLUDED.description,
                parameters_json = EXCLUDED.parameters_json,
                metadata        = EXCLUDED.metadata,
                is_activated    = EXCLUDED.is_activated,
                updated_at      = NOW()";

        try {
            $GLOBALS['db']->execQuery($sql);
        } catch (Exception $e) {
            error_log('[aiagent_nsfw] catalog seed failed for ' . $a['code'] . ': ' . $e->getMessage());
        }
    }
}

aiagent_nsfw_seed_action_catalog();

}
