<?php

require_once __DIR__ . "/../../lib/chat_helper_functions.php";
require_once __DIR__ . "/scene_lookup.php";  // OStim JSON parsing + dual lookup
require_once __DIR__ . "/nsfw_relationship.php";  // Tier-based relationship prompts
require_once __DIR__ . "/nsfw_npc_scene.php";  // NPC-to-NPC scene handling
require_once __DIR__ . "/nsfw_physics.php";  // VR touch/physics handling
require_once __DIR__ . "/nsfw_ostim_handler.php";  // OStim scene handling

/**
 * Process OStim/SexLab scene events
 * Wrapper function for backwards compatibility.
 * @see NsfwOstimHandler::processEvent() for implementation
 */
function processInfoSexScene()
{
    NsfwOstimHandler::processEvent();
}

/**
 * Process VR physics events (HIGGS grab, CBPC touch)
 * Wrapper function for backwards compatibility.
 * @see NsfwPhysics::processEvent() for implementation
 */
function processInfoPhysics()
{
    NsfwPhysics::processEvent();
}

/**
 * Process VR Item Events (HIGGS pickup/drop)
 * Wrapper function for backwards compatibility.
 * @see VRItems::processEvent() for implementation
 */
function processInfoVRItems()
{
    require_once(__DIR__ . '/vr_items.php');
    VRItems::processEvent();
}

function processInfoFertility()
{
    global $gameRequest;

    if ($gameRequest[0] == "fertility_notification") {
        $actor = $GLOBALS["HERIKA_NAME"];

        $npcManager = new NpcMaster();
        $npcData    = $npcManager->getByName($actor);

        if (! $npcData) {
            $npcData = $npcManager->getByName(ucFirst(strtolower($actor)));
        }
        $extended = json_decode($npcData["extended_data"], true);
        if (!$extended) $extended = [];

        $subCmd = explode("@", $gameRequest[3]);
        $eventType = $subCmd[1] ?? '';

        error_log("[CHIM-NSFW FERTILITY] Processing: " . $gameRequest[3]);

        switch ($eventType) {
            case 'pregnant':
                // Format: name@pregnant@progress@fatherName (from FMR_ActorStatus)
                $extended["fertility_is_pregnant"] = true;
                $extended["fertility_progress"] = intval($subCmd[2] ?? 0);
                $extended["fertility_father"] = $subCmd[3] ?? '';
                break;

            case 'recovery':
                // Format: name@recovery@day (from FMR_ActorStatus rank 101-115)
                $extended["fertility_is_pregnant"] = false;
                $extended["fertility_recovery_day"] = intval($subCmd[2] ?? 0);
                break;

            case 'cleared':
                // Format: name@cleared (from FMR_ActorStatus rank 0)
                $extended["fertility_is_pregnant"] = false;
                $extended["fertility_progress"] = 0;
                unset($extended["fertility_father"]);
                unset($extended["fertility_recovery_day"]);
                break;

            case 'aborted':
                $extended["fertility_is_pregnant"] = false;
                break;

            case 'birth':
                $extended["fertility_is_pregnant"] = false;
                $extended["fertility_recent_birth"] = $gameRequest[2];
                break;

            case 'baby_damage':
                // Format: name@baby_damage@damage@remainingHealth
                $extended["fertility_baby_health"] = intval($subCmd[3] ?? 100);
                $extended["fertility_baby_damaged"] = true;
                break;

            case 'baby_death':
                // Format: name@baby_death@cause
                $extended["fertility_is_pregnant"] = false;
                $extended["fertility_baby_lost"] = true;
                $extended["fertility_baby_death_cause"] = $subCmd[2] ?? 'unknown';
                break;

            case 'miscarriage':
                // Format: name@miscarriage@cause
                $extended["fertility_is_pregnant"] = false;
                $extended["fertility_miscarriage"] = true;
                $extended["fertility_miscarriage_cause"] = $subCmd[2] ?? 'unknown';
                break;

            case 'baby_status':
                // Format: name@baby_status@ageDays@health@daysRemaining
                $extended["fertility_baby_age_days"] = intval($subCmd[2] ?? 0);
                $extended["fertility_baby_health"] = intval($subCmd[3] ?? 100);
                $extended["fertility_days_remaining"] = intval($subCmd[4] ?? 0);
                break;

            case 'mother_death':
                // Format: name@mother_death@status (pregnant or with_baby)
                $extended["fertility_mother_died"] = true;
                $extended["fertility_death_status"] = $subCmd[2] ?? '';
                break;
        }

        $npcData["extended_data"] = json_encode($extended);
        $npcData["gamets_last_updated"] = $gameRequest[2];
        $npcManager->updateByArray($npcData);

        $gameRequest[0] = "info";
        logEvent($gameRequest);
        terminate();
    }
}

function getIntimacyForActor($actorName)
{

    $npcManager = new NpcMaster();
    $npcData    = $npcManager->getByName($actorName);
    if (! $npcData) {
        $npcData = $npcManager->getByName(ucFirst(strtolower($actorName)));
    }
    if (isset($npcData["extended_data"])) {
        $extended = json_decode($npcData["extended_data"], true);
    } else {
        $extended = [];
    }

    if (isset($extended["aiagent_nsfw_intimacy_data"]) && isNonEmptyArray($extended["aiagent_nsfw_intimacy_data"])) {
        $intimacyStatus = $extended["aiagent_nsfw_intimacy_data"];

    } else {
        $intimacyStatus = ["level" => 0, "sex_disposal" => 0];
    }

    return $intimacyStatus;
}

/*
 Make AI aware this NPC has given birth a child recently
*/

function setBirthPrompt($actorName)
{
    $GLOBALS["HERIKA_PERSONALITY"].="\nImportant: {$actorName} had a child recently, (out of context, check 'Baby' item on inventory/equipment, this means $actorName is carrying the baby";
}

/**
 * Inject sex speech style for actor
 * Wrapper function for backwards compatibility.
 * @see NsfwOstimHandler::setSexSpeechStyle() for implementation
 */
function setSexSpeechStyle($actorName)
{
    NsfwOstimHandler::setSexSpeechStyle($actorName);
}

/**
 * Inject sex prompt (personality during scenes)
 * Wrapper function for backwards compatibility.
 * @see NsfwOstimHandler::setSexPrompt() for implementation
 */
function setSexPrompt($actorName)
{
    NsfwOstimHandler::setSexPrompt($actorName);
}

function updateIntimacyForActor($actorName, $idata)
{

    if ($actorName == $GLOBALS["PLAYER_NAME"]) {
        return;
    }

    error_log("[AIAGENTNSFW] Updating intimacy for $actorName. " . json_encode($idata));

    $currentIntimacy = getIntimacyForActor($actorName);
    $npcManager      = new NpcMaster();
    $npcData         = $npcManager->getByName($actorName);

    if (! $npcData) {
        $npcData = $npcManager->getByName(ucFirst(strtolower($actorName)));
    }

    $extended = json_decode($npcData["extended_data"], true);

    // Always add/update the gamets timestamp to track when this intimacy data was created
    // This allows us to detect and clear "future" data when loading older saves
    $idata['gamets'] = $GLOBALS["gameRequest"][2] ?? time();

    if (isset($extended["aiagent_nsfw_intimacy_data"]) && isNonEmptyArray($extended["aiagent_nsfw_intimacy_data"])) {
        $extended["aiagent_nsfw_intimacy_data"] = array_merge($extended["aiagent_nsfw_intimacy_data"], $idata);
    } else {
        $extended["aiagent_nsfw_intimacy_data"] = $idata;
    }

    $npcData["extended_data"] = json_encode($extended);
    $npcManager->updateByArray($npcData);

}

function saveAllDisposals()
{
    error_log("[AIAGENT NSFW] saveAllDisposals is deprecated");
    return;
    audit_log(__FILE__ . " [AIAGENT NSFW]  " . __LINE__);
    $data       = $GLOBALS["db"]->fetchAll("select * from conf_opts where id like '%_intimacy'");
    $datatoSave = [];
    foreach ($data as $rowactor) {
        $datatoSave[] = $rowactor;
    }

    $GLOBALS["db"]->upsertRowOnConflict(
        "conf_opts",
        [
            "id"    => "aiagent_nsfw_intimacy",
            "value" => json_encode($datatoSave),
        ],
        'id'
    );
    audit_log(__FILE__ . " [AIAGENT NSFW]  " . __LINE__);

}

function loadAllDisposals()
{
    error_log("[AIAGENT NSFW] loadAllDisposals is deprecated");
    return;

    audit_log(__FILE__ . " [AIAGENT NSFW]  " . __LINE__);

    $GLOBALS["db"]->execQuery("delete  from conf_opts where id like '%_intimacy'");

    $savedData     = $GLOBALS["db"]->fetchOne("select value from conf_opts where id like 'aiagent_nsfw_intimacy'");
    $savedDataFull = [];

    if ($savedData) {
        $savedDataFull = json_decode($savedData["value"], true);

    }

    if (is_array($savedDataFull)) {
        foreach ($savedDataFull as $actorIntimacyData) {
            $GLOBALS["db"]->upsertRowOnConflict(
                "conf_opts",
                [
                    "id"    => $actorIntimacyData["id"],
                    "value" => $actorIntimacyData["value"],
                ],
                'id'
            );
        }
    }

    audit_log(__FILE__ . " [AIAGENT NSFW]  " . __LINE__);

}

/*
Calculates intimacy disposal based on moods issued when talking.
*/

function getSexDisposalFromMood($actorName, $currentGamets)
{

    $playerNameE = $GLOBALS["db"]->escape($GLOBALS["PLAYER_NAME"]);
    $actorNameE  = $GLOBALS["db"]->escape($actorName);

    $sdQuery = "
    WITH mood_scores AS (
    SELECT
        speaker,
        listener,
        mood,
        CASE
            WHEN mood = 'playful' THEN 1
            WHEN mood = 'seductive' THEN 1
            WHEN mood = 'sexy' THEN 1
            WHEN mood = 'aroused' THEN 1
            WHEN mood = 'sensual' THEN 1
            WHEN mood = 'flirty' THEN 1
            WHEN mood = 'lovely' THEN 1
            WHEN mood = 'loving' THEN 1
            WHEN mood = 'drunk' THEN 1
            WHEN mood = 'tipsy' THEN 1
            WHEN mood = 'irritated' THEN -2
            WHEN mood = 'grumpy' THEN -1
            ELSE 0
        END AS sex_disposal_speech,gamets
    FROM public.moods_issued
    WHERE mood IS NOT NULL
    and speaker like '$actorNameE'
    and (listener like '$playerNameE' or 1=1)
    and ($currentGamets-gamets)<(7/ 0.0000024)
    order by gamets DESC
    limit 100
)
SELECT
    speaker,
    listener,
    SUM(sex_disposal_speech) AS total_sentiment,
    COUNT(*) AS interactions,
    ROUND(AVG(sex_disposal_speech), 2) AS avg_sentiment,
    MIN(gamets) AS gamets_from,
    MAX(gamets) AS gamets_to
FROM mood_scores
GROUP BY speaker, listener
ORDER BY total_sentiment DESC";

    $statData = $GLOBALS["db"]->fetchOne($sdQuery);
    error_log("[AIGANET NSFW] Mood speech analisys: " . json_encode($statData));
    if (isNonEmptyArray($statData)) {
        return $statData["avg_sentiment"];

    }

    return 0;

}

function getLastIssuedMood($actorName, $currentGamets, $timeFrameLimit = 5)
{

    $playerNameE = $GLOBALS["db"]->escape($GLOBALS["PLAYER_NAME"]);
    $actorNameE  = $GLOBALS["db"]->escape($actorName);

    $sdQuery = "
    select *
    FROM public.moods_issued
    WHERE mood IS NOT NULL
    and speaker like '$actorNameE'
    and ($currentGamets-gamets)<(1/ 0.0000024*$timeFrameLimit)
    order by gamets DESC
    limit 1";
    $statData = $GLOBALS["db"]->fetchOne($sdQuery);
    error_log("Last mood: " . json_encode($statData) . "<$sdQuery>");
    if (isNonEmptyArray($statData)) {
        return $statData["mood"];

    }

    return "";

}

function findRowByFirstColumn($filePath, $searchValue)
{
    // Use JSONB storage via NsfwData class
    $lang = $GLOBALS["CORE_LANG"] ?? 'en';
    $description = NsfwData::getSceneDescription($searchValue, $lang);

    if (!empty($description)) {
        error_log("Found JSONB description for $searchValue!");
        return strtolower($description);
    }

    // Auto-insert for future editing
    NsfwData::saveScene($searchValue, '', null, null, null);

    return null; // No match found
}

function findRowByFirstColumnOld($filePath, $searchValue)
{
    if (($fh = fopen($filePath, 'r')) === false) {
        return null;
    }

    $header = fgetcsv($fh, 0, ",", '"', '\\'); // Read and skip header
    while (($row = fgetcsv($fh, 0, ",", '"', '\\')) !== false) {

        if (trim(mb_strtolower($row[0])) === trim(mb_strtolower($searchValue))) {
            error_log("Found description for $searchValue!");
            fclose($fh);
            return $row[1];
        }
    }

    fclose($fh);
    return null; // No match found
}

/**
 * GASP audio processing for orgasm sounds
 * Wrapper function for backwards compatibility.
 * @see NsfwOstimHandler::gasper() for implementation
 */
function gasper($original_speech, $moan, $sourceaudio, $sourcevoiceaudio)
{
    return NsfwOstimHandler::gasper($original_speech, $moan, $sourceaudio, $sourcevoiceaudio);
}

//  Guess if player is naked
function playerIsNaked()
{

    audit_log(__FILE__ . " [AIAGENT NSFW]  " . __LINE__);

    $val = $GLOBALS["db"]->fetchOne("select value from conf_opts where id='player_naked'");
    if ($val && isset($val["value"]) && $val["value"] == 1) {
        return true;
    }

    return false;
}

/**
 * Generate climax speech using LLM
 * Wrapper function for backwards compatibility.
 * @see NsfwOstimHandler::generateClimaxSpeech() for implementation
 */
function generateClimaxSpeech()
{
    NsfwOstimHandler::generateClimaxSpeech();
}

function isProstitute($actorName) {
    $npcManager = new NpcMaster();
    $npcData = $npcManager->getByName($actorName);
    if (!$npcData) {
        $npcData = $npcManager->getByName(ucFirst(strtolower($actorName)));
    }
    if (!$npcData) return false;
    $extended = json_decode($npcData["extended_data"] ?? '{}', true) ?: [];
    return !empty($extended['is_prostitute']) ||
           !empty($extended['profession_prostitute']) ||
           !empty($extended['adult_entertainment_services_autodetected']);
}

function setNpcProstituteStatus($actorName, $isProstitute) {
    $npcManager = new NpcMaster();
    $npcData = $npcManager->getByName($actorName);
    if (!$npcData) {
        $npcData = $npcManager->getByName(ucFirst(strtolower($actorName)));
    }
    if (!$npcData) return false;

    $extended = json_decode($npcData["extended_data"] ?? '{}', true) ?: [];

    if ($isProstitute) {
        $extended['is_prostitute'] = true;
    } else {
        unset($extended['is_prostitute']);
        unset($extended['profession_prostitute']);
        unset($extended['adult_entertainment_services_autodetected']);
    }

    $npcManager->update($npcData['name'], ['extended_data' => json_encode($extended)]);
    return true;
}

function getNpcAffinity($npcName, $targetName = null) {
    require_once(__DIR__ . '/nsfw_relationship.php');
    if ($targetName === null || strtolower($targetName) === 'player' || $targetName === $GLOBALS['PLAYER_NAME']) {
        $relationship = RelationshipManager::getPlayerRelationship($npcName);
    } else {
        $relationship = RelationshipManager::getRelationship($npcName, $targetName);
    }
    return $relationship['aff'] ?? 0;
}

function getGlobalPrompt($promptKey) {
    static $promptsCache = null;
    if ($promptsCache === null) {
        try {
            $settingsRow = $GLOBALS["db"]->fetchOne("SELECT value FROM conf_opts WHERE id = 'aiagent_nsfw_prompts'");
            if ($settingsRow && !empty($settingsRow['value'])) {
                $promptsCache = json_decode($settingsRow['value'], true) ?: [];
            } else {
                $promptsCache = [];
            }
        } catch (Exception $e) {
            $promptsCache = [];
        }
    }
    return $promptsCache[$promptKey] ?? '';
}

function isNpcSlave($actorName) {
    $npcManager = new NpcMaster();
    $npcData = $npcManager->getByName($actorName);
    if (!$npcData) {
        $npcData = $npcManager->getByName(ucFirst(strtolower($actorName)));
    }
    if (!$npcData) return false;
    $extended = json_decode($npcData["extended_data"] ?? '{}', true) ?: [];
    return !empty($extended['is_slave']);
}

function setNpcSlaveStatus($actorName, $isSlave) {
    $npcManager = new NpcMaster();
    $npcData = $npcManager->getByName($actorName);
    if (!$npcData) {
        $npcData = $npcManager->getByName(ucFirst(strtolower($actorName)));
    }
    if (!$npcData) return false;

    $extended = json_decode($npcData["extended_data"] ?? '{}', true) ?: [];

    if ($isSlave) {
        $extended['is_slave'] = true;
    } else {
        unset($extended['is_slave']);
    }

    $npcManager->update($npcData['name'], ['extended_data' => json_encode($extended)]);
    return true;
}

function freeNpcSlave($actorName) {
    return setNpcSlaveStatus($actorName, false);
}

/**
 * Build negotiation context for prostitute after AcceptTransaction
 * Uses the full prostitute configuration from is_prostitute checkbox:
 * - prostitute_type: streetwalker, courtesan, escort, tavern_worker, temple_prostitute, camp_follower
 * - prostitute_pricing: individual_acts, time_bookings, style_addons, group_premiums
 * - payment_type: gold, favors, goods, mixed
 * - motivation: professional, desperate, etc.
 * - personality_prompt, during_prompt, after_prompt
 *
 * @param string $npcName The prostitute NPC
 * @param string $clientName The client
 * @param int $affinity Affinity score with client
 * @return string Context to inject into personality
 */
function buildProstituteNegotiationContext($npcName, $clientName, $affinity) {
    $context = "";

    // Get NPC's extended data for all prostitute configuration
    $npcManager = new NpcMaster();
    $npcData = $npcManager->getByName($npcName);
    $extended = [];
    if ($npcData) {
        $extended = json_decode($npcData["extended_data"] ?? '{}', true) ?: [];
    }

    // Get global prompts for pricing modifiers
    $globalPrompts = getGlobalNsfwPrompts();

    // Get tier name from affinity
    $tierName = getAffinityTierName($affinity);

    // Get pricing modifier for this tier
    $pricingModKey = 'pricing_mod_' . strtolower($tierName);
    $pricingMod = floatval($globalPrompts[$pricingModKey] ?? 0);

    // === PROSTITUTE TYPE ===
    // Types: streetwalker, courtesan, escort, tavern_worker, temple_prostitute, camp_follower
    // Check top-level first, then inside pricing (for backwards compatibility)
    $pricing = $extended['prostitute_pricing'] ?? null;
    $prostituteType = $extended['prostitute_type']
        ?? ($pricing['prostitute_type'] ?? null)
        ?? 'streetwalker';
    $typeDescriptions = [
        'streetwalker' => 'You work the streets. Quick transactions, straightforward pricing.',
        'courtesan' => 'You are a refined courtesan. You offer companionship and intimacy to those who can afford luxury.',
        'escort' => 'You are a professional escort. Discretion and quality service are your hallmarks.',
        'tavern_worker' => 'You work at a tavern. You entertain patrons who catch your eye or pay well enough.',
        'temple_prostitute' => 'You serve at a temple. Your services are considered sacred rites.',
        'camp_follower' => 'You follow armies and camps. You provide comfort to soldiers and travelers.'
    ];
    $typeDesc = $typeDescriptions[$prostituteType] ?? $typeDescriptions['streetwalker'];

    // === PAYMENT & MOTIVATION ===
    // Payment type preference: gold, favors, goods, mixed
    $paymentType = 'gold';
    $motivation = 'professional';
    if ($pricing && is_array($pricing)) {
        $paymentType = $pricing['payment_type'] ?? 'gold';
        $motivation = $pricing['motivation'] ?? 'professional';
    }

    // Payment type descriptions
    $paymentDescriptions = [
        'gold' => 'You only accept gold payment.',
        'favors' => 'You accept favors and services in exchange. Build trust first.',
        'goods' => 'You accept valuable goods and items as payment.',
        'mixed' => 'You accept gold, goods, or favors - whatever works.'
    ];
    $paymentDesc = $paymentDescriptions[$paymentType] ?? $paymentDescriptions['gold'];

    // Motivation affects behavior
    $motivationDescriptions = [
        'professional' => 'This is your profession. Business-like and efficient.',
        'desperate' => 'You need the money badly. More willing to negotiate down.',
        'luxurious' => 'You cater to wealthy clients. Premium prices, premium service.',
        'survival' => 'You do this to survive. Pragmatic about it.',
        'enjoyment' => 'You genuinely enjoy your work. Enthusiastic service.'
    ];
    $motivationDesc = $motivationDescriptions[$motivation] ?? '';

    // === BUILD PRICE LIST ===
    // Make act names human-readable
    $actNameMap = [
        'foreplay_kissing' => 'Kissing',
        'foreplay_cuddling' => 'Cuddling',
        'foreplay_groping' => 'Groping/touching',
        'foreplay_stripping' => 'Stripping',
        'manual_handjob' => 'Handjob',
        'manual_fingering' => 'Fingering',
        'manual_mutual' => 'Mutual touching',
        'oral_giving' => 'Oral (giving)',
        'oral_receiving' => 'Oral (receiving)',
        'oral_mutual' => 'Mutual oral',
        'full_vaginal' => 'Vaginal sex',
        'full_anal' => 'Anal sex',
        'full_both' => 'Full service (both)',
        'solo_watch' => 'Watching solo',
        'solo_masturbate' => 'Masturbation show',
        'finish_body' => 'Finish on body',
        'finish_face' => 'Finish on face',
        'finish_inside' => 'Finish inside'
    ];

    $priceList = "";
    if ($pricing && is_array($pricing)) {
        // Individual acts - these are PER-ACT prices, not cumulative
        if (!empty($pricing['individual_acts'])) {
            $priceList .= "\n#Your Prices (per act, not cumulative):";
            foreach ($pricing['individual_acts'] as $act => $price) {
                $adjustedPrice = $pricingMod != 0 ? round($price * (1 + $pricingMod / 100)) : $price;
                if ($adjustedPrice < 1) $adjustedPrice = 1; // Minimum 1 gold
                $actLabel = $actNameMap[$act] ?? str_replace('_', ' ', $act);
                $priceList .= "\n- {$actLabel}: {$adjustedPrice} gold";
            }
        }
        // Time-based bookings
        if (!empty($pricing['time_bookings'])) {
            $priceList .= "\n#Time Packages (flat rate, all-inclusive):";
            $timeNameMap = [
                'time_1hr' => '1 hour',
                'time_12hr' => 'Half day (12 hours)',
                'time_24hr' => 'Full day (24 hours)',
                'time_72hr' => 'Weekend (3 days)',
                'time_gfe' => 'Girlfriend experience'
            ];
            foreach ($pricing['time_bookings'] as $duration => $price) {
                $adjustedPrice = $pricingMod != 0 ? round($price * (1 + $pricingMod / 100)) : $price;
                if ($adjustedPrice < 1) $adjustedPrice = 1;
                $timeLabel = $timeNameMap[$duration] ?? str_replace('_', ' ', $duration);
                $priceList .= "\n- {$timeLabel}: {$adjustedPrice} gold";
            }
        }
        // Style add-ons
        if (!empty($pricing['style_addons'])) {
            $priceList .= "\n#Special Requests (add to base price):";
            $addonNameMap = [
                'addon_domination' => 'Domination',
                'addon_submission' => 'Submission',
                'addon_watch' => 'Let them watch'
            ];
            foreach ($pricing['style_addons'] as $addon => $price) {
                $adjustedPrice = $pricingMod != 0 ? round($price * (1 + $pricingMod / 100)) : $price;
                if ($adjustedPrice < 1) $adjustedPrice = 1;
                $addonLabel = $addonNameMap[$addon] ?? str_replace('_', ' ', $addon);
                $priceList .= "\n- {$addonLabel}: +{$adjustedPrice} gold";
            }
        }
        // Group premiums
        if (!empty($pricing['group_premiums'])) {
            $priceList .= "\n#Group Rates (add to base price):";
            $groupNameMap = [
                'group_threesome' => 'Threesome',
                'group_foursome' => 'Foursome',
                'group_orgy' => 'Orgy (5+)'
            ];
            foreach ($pricing['group_premiums'] as $groupType => $price) {
                $adjustedPrice = $pricingMod != 0 ? round($price * (1 + $pricingMod / 100)) : $price;
                if ($adjustedPrice < 1) $adjustedPrice = 1;
                $groupLabel = $groupNameMap[$groupType] ?? str_replace(['group_', '_'], ['', ' '], $groupType);
                $priceList .= "\n- {$groupLabel}: +{$adjustedPrice} gold";
            }
        }
    }

    // === PRICING ADJUSTMENT TEXT ===
    $pricingText = "";
    if ($pricingMod < 0) {
        $discount = abs($pricingMod);
        $pricingText = "Give {$clientName} a {$discount}% discount (you like them).";
    } else if ($pricingMod > 0) {
        $pricingText = "Charge {$clientName} a {$pricingMod}% premium (you don't trust them yet).";
    } else {
        $pricingText = "Standard rates for {$clientName}.";
    }

    // === NPC'S CUSTOM PERSONALITY PROMPT ===
    $personalityPrompt = '';
    if ($pricing && !empty($pricing['personality_prompt'])) {
        $personalityPrompt = $pricing['personality_prompt'];
        // Replace placeholders
        $personalityPrompt = str_replace('#NPC_NAME#', $npcName, $personalityPrompt);
        $personalityPrompt = str_replace('#PARTNER#', $clientName, $personalityPrompt);
        $personalityPrompt = str_replace('#PLAYER_NAME#', $clientName, $personalityPrompt);
    }

    // === BUILD FULL CONTEXT ===
    $context = "\n#NEGOTIATION PHASE";
    $context .= "\nYou accepted {$clientName} as a potential client.";
    $context .= "\n#Type: " . ucfirst($prostituteType) . " - " . $typeDesc;

    if (!empty($motivationDesc)) {
        $context .= "\n#Motivation: " . $motivationDesc;
    }

    $context .= "\n#Payment: " . $paymentDesc;
    $context .= "\n#Pricing: " . $pricingText;

    if (!empty($personalityPrompt)) {
        $context .= "\n#Your Style: " . $personalityPrompt;
    }

    if (!empty($priceList)) {
        $context .= $priceList;
    }

    $context .= "\n\n#Instructions: Discuss what {$clientName} wants. Quote your prices based on the list above. ";
    $context .= "Once you agree on services and price, use RequestPayment(amount, service) to request payment. ";
    $context .= "Example: RequestPayment(50, \"oral\") or RequestPayment(100, \"full service\"). ";
    $context .= "When {$clientName} agrees to pay, the gold will be taken automatically. ";
    $context .= "Wait for payment confirmation before proceeding with intimate acts.";

    error_log("[AIAGENTNSFW] Built negotiation context for {$npcName} (type: {$prostituteType}, payment: {$paymentType}, tier: {$tierName})");

    return $context;
}

/**
 * Get global NSFW prompts from database
 */
function getGlobalNsfwPrompts() {
    static $cache = null;
    if ($cache !== null) return $cache;

    try {
        $row = $GLOBALS["db"]->fetchOne("SELECT value FROM conf_opts WHERE id = 'aiagent_nsfw_prompts'");
        if ($row && !empty($row['value'])) {
            $cache = json_decode($row['value'], true) ?: [];
        } else {
            $cache = [];
        }
    } catch (Exception $e) {
        $cache = [];
    }
    return $cache;
}

/**
 * Get affinity tier name from affinity score
 */
function getAffinityTierName($affinity) {
    if ($affinity >= 91) return 'bonded';
    if ($affinity >= 76) return 'devoted';
    if ($affinity >= 56) return 'fond';
    if ($affinity >= 31) return 'friendly';
    if ($affinity >= 6) return 'acquaintance';
    if ($affinity >= -5) return 'neutral';
    if ($affinity >= -30) return 'wary';
    if ($affinity >= -55) return 'cold';
    if ($affinity >= -75) return 'resentful';
    if ($affinity >= -90) return 'hateful';
    return 'hostile';
}
