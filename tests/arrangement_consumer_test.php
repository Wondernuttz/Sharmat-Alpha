<?php
declare(strict_types=1);

namespace ChimMindPoisoning {
    if (!defined(__NAMESPACE__ . '\\MIND_POISONING_INTIMACY_API_VERSION')) {
        define(__NAMESPACE__ . '\\MIND_POISONING_INTIMACY_API_VERSION', 1);
    }

    function mindPoisoningReadIntimacyArrangement(int $ownerNpcId, string $targetSubject, ?\StoreDb $store = null): array
    {
        $key = $ownerNpcId . ':' . $targetSubject;
        $GLOBALS['ar_arrangement_api_calls'][] = $key;
        return $GLOBALS['ar_arrangement_api_rows'][$key] ?? [
            'state' => 'none', 'kind' => null, 'owner_id' => $ownerNpcId,
            'target' => $targetSubject, 'playthrough_id' => 'test-scope',
        ];
    }
}

namespace {

function arFailSetup(string $message): void
{
    fwrite(STDERR, "FIXTURE ERROR: {$message}\n");
    exit(2);
}

function arRead(string $relative): string
{
    $path = dirname(__DIR__) . DIRECTORY_SEPARATOR . $relative;
    $source = file_get_contents($path);
    if (!is_string($source)) { arFailSetup("Cannot read production source {$relative}"); }
    $GLOBALS['ar_source_hashes'][$relative] = hash('sha256', $source);
    return str_replace("\r\n", "\n", $source);
}

function arExtractFunction(string $source, string $name): ?string
{
    $tokens = token_get_all($source);
    $offsets = [];
    $offset = 0;
    foreach ($tokens as $token) {
        $offsets[] = $offset;
        $offset += strlen(is_array($token) ? $token[1] : $token);
    }

    foreach ($tokens as $i => $token) {
        if (!is_array($token) || $token[0] !== T_FUNCTION) { continue; }
        $nameIndex = $i + 1;
        while (isset($tokens[$nameIndex]) && is_array($tokens[$nameIndex])
            && in_array($tokens[$nameIndex][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) { $nameIndex++; }
        if (!isset($tokens[$nameIndex]) || !is_array($tokens[$nameIndex])
            || $tokens[$nameIndex][0] !== T_STRING || $tokens[$nameIndex][1] !== $name) { continue; }

        $openIndex = $nameIndex;
        while (isset($tokens[$openIndex]) && $tokens[$openIndex] !== '{') { $openIndex++; }
        if (!isset($tokens[$openIndex])) { arFailSetup("Missing function body for {$name}"); }
        $depth = 0;
        for ($j = $openIndex; isset($tokens[$j]); $j++) {
            $opens = $tokens[$j] === '{'
                || (is_array($tokens[$j]) && in_array($tokens[$j][0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true));
            if ($opens) { $depth++; }
            elseif ($tokens[$j] === '}') {
                $depth--;
                if ($depth === 0) { return substr($source, $offsets[$i], $offsets[$j] + 1 - $offsets[$i]); }
            }
        }
        arFailSetup("Unbalanced function body for {$name}");
    }
    return null;
}

function arExtractBlock(string $source, string $start, string $end, string $label): string
{
    $offset = strpos($source, $start);
    $limit = $offset === false ? false : strpos($source, $end, $offset + strlen($start));
    if ($offset === false || $limit === false) { arFailSetup("Missing production block anchors for {$label}"); }
    return substr($source, $offset, $limit - $offset);
}

function arLoadFunctions(string $source, array $names): void
{
    $code = '';
    foreach ($names as $name) {
        $function = arExtractFunction($source, $name);
        if ($function !== null) {
            // Keep production behavior while letting the fixture provide NsfwNpcData directly.
            $function = str_replace('require_once __DIR__ . "/nsfw_data.php";', '', $function);
            $code .= "\n" . $function . "\n";
        }
    }
    if ($code === '') { arFailSetup('No production functions extracted'); }
    eval($code);
}

function arExpect(bool $actual, bool $expected, string $name, string $detail = ''): void
{
    $pass = $actual === $expected;
    $entry = ['name' => $name, 'expected' => $expected, 'actual' => $actual, 'pass' => $pass];
    if ($detail !== '') { $entry['detail'] = $detail; }
    $GLOBALS['ar_checks'][] = $entry;
    if (!$pass) { $GLOBALS['ar_failures'][] = $entry; }
}

class ArrangementNpcData
{
    public static function get(string $name): array
    {
        return $GLOBALS['ar_profiles'][$name] ?? [];
    }
}

class NsfwNpcData
{
    public static function get(string $name): array
    {
        if (!empty($GLOBALS['ar_profile_read_error'])) { throw new RuntimeException('synthetic profile read failure'); }
        return $GLOBALS['ar_profiles'][$name] ?? [];
    }
}

class RelationshipManager
{
    public static function getRelationship(string $speaker, string $target): array
    {
        return $GLOBALS['ar_relationships'][$speaker][$target] ?? ['aff' => 0, 'type' => 'neutral'];
    }

    public static function getPlayerRelationship(string $speaker): array
    {
        return self::getRelationship($speaker, 'Player');
    }

    public static function getTierLabel($affinity): string
    {
        $affinity = (int)$affinity;
        if ($affinity >= 91) { return 'Bonded'; }
        if ($affinity >= 76) { return 'Devoted'; }
        if ($affinity >= 56) { return 'Fond'; }
        return $affinity >= 31 ? 'Friendly' : 'Stranger';
    }

    public static function resolveNpcByName(string $name): ?array
    {
        return $GLOBALS['ar_npcs'][$name] ?? null;
    }

    public static function normalizeRelationshipMap($relationships): array
    {
        return is_array($relationships) ? $relationships : [];
    }

    public static function setRelationship(string $speaker, string $target, int $affinity, string $type): void
    {
        $GLOBALS['ar_crush_writes'][] = [$speaker, $target, $affinity, $type];
        $GLOBALS['ar_relationships'][$speaker][$target] = ['aff' => $affinity, 'type' => $type];
    }
}

class ArrangementPolicyTestDb
{
    private $row;

    public function __construct($row) { $this->row = $row; }

    public function fetchOne(string $query)
    {
        $expected = "SELECT COALESCE((SELECT value::text FROM conf_opts WHERE id = 'aiagent_nsfw_reltypes'), '') AS value";
        if (trim($query) !== $expected) { throw new RuntimeException('Unexpected production policy query'); }
        return $this->row;
    }
}

function _getNsfwSetting($key, $default = null) { return $GLOBALS['ar_settings'][$key] ?? $default; }
function aiagentNsfwIsProtectedNpc($name): bool { return in_array(strtolower((string)$name), $GLOBALS['ar_protected'], true); }
function aiagentNsfwIsChildNpc($name): bool { return false; }
function aiagentNsfwIsNarratorName($name): bool { return strcasecmp((string)$name, 'The Narrator') === 0; }
function aiagentNsfwPlayerConflictActive(): bool { return false; }
function aiagentNsfwPlayerSceneCallCooldownActive(): bool { return false; }
function aiagentNsfwWhiskeyDickActive(): bool { return false; }
function aiagentNsfwProstitutePaymentWaived($name): bool { return false; }
function aiagentNsfwArousalNum($key, $default): float { return (float)$default; }
function getNpcAffinity($name): int { return (int)(RelationshipManager::getPlayerRelationship($name)['aff'] ?? 0); }
function getIntimacyForActor($name): array { return $GLOBALS['ar_intimacy'][$name] ?? []; }
function updateIntimacyForActor($name, $status): void { $GLOBALS['ar_intimacy'][$name] = $status; }
function npcNameToCodename($name): string { return (string)$name; }
function isAffinityGatingEnabled(): bool { return false; }
function isSexDisposalEnabled(): bool { return false; }
function getNormalizedNearbyDialogueListenerNames($includeCurrent = true): array { return $GLOBALS['ar_nearby']; }
function getDrugStageForActor($name, $drug): int { return 0; }
function isNpcSlave($name): bool { return false; }
function isProstitute($name): bool { return false; }
function nsfwIsNarratorName($name): bool { return aiagentNsfwIsNarratorName($name); }
function herikaActionCatalogDbReady(): bool { return false; }
function herikaActionCatalogIsActionEnabled($code): bool { return true; }
function getFunctionCodeName($displayName): string { return $GLOBALS['ar_display_codes'][$displayName] ?? ''; }

$GLOBALS['ar_checks'] = [];
$GLOBALS['ar_failures'] = [];
$GLOBALS['ar_source_hashes'] = [];
$common = arRead('common.php');
$policy = arRead('open_mode_policy.php');
arLoadFunctions($policy, ['aiagentNsfwCanonicalRelationshipType', 'aiagentNsfwNormalizeRelTypeConfig']);
arLoadFunctions($common, [
    'aiagentNsfwOpenMode', 'aiagentNsfwIsSlutNpc', 'aiagentNsfwGetRelTypePolicy',
    'aiagentNsfwReadIntimacyArrangement', 'aiagentNsfwHasActiveIntimacyArrangement',
    'aiagentNsfwBeginIntimacyArrangementOfferReadCache', 'aiagentNsfwClearIntimacyArrangementOfferReadCache',
    'aiagentNsfwIntimacyArrangementAllowsSexPair',
    'aiagentNsfwAcceptedPlayerArrangementEligible',
    'aiagentNsfwRelTypeSexEligible', 'aiagentNsfwNpcToNpcSexEligible',
    'aiagentNsfwSexStartActionCodes', 'aiagentNsfwSexStartTargetOptions',
    'aiagentNsfwSexStartActionAvailable', 'aiagentNsfwSexStartTargetNote',
    'aiagentNsfwSexStartActionOffered', 'aiagentNsfwCanonicalSexStartTarget',
    'aiagentNsfwCanonicalSexStartTargets', 'aiagentNsfwAddEligibleNpcSexStartActions',
    'aiagentNsfwInstantCrush', 'aiagentNsfwOrientationAllowsPlayer',
]);
$relationshipSource = arRead('nsfw_relationship.php');
$relTypeRefusalMethod = arExtractFunction($relationshipSource, 'getRelTypeGateRefusal');
if ($relTypeRefusalMethod === null) { arFailSetup('Could not extract the arrangement-aware refusal method'); }
$relTypeRefusalMethod = str_replace('require_once __DIR__ . "/nsfw_data.php";', '', $relTypeRefusalMethod, $removeNsfwDataInclude);
if ($removeNsfwDataInclude !== 1) { arFailSetup('Could not remove the refusal fixture external data include'); }
eval('class ArrangementNsfwRelationshipProbe {
    private static function replacePlayerRoutePlaceholders($prompt, $partnerName, $npcName) {
        return str_replace(["#NPC_NAME#", "#PLAYER_NAME#"], [$npcName, $partnerName], (string)$prompt);
    }
    ' . $relTypeRefusalMethod . '
}');

$context = arRead('context_pre.php');
$contextBlock = arExtractBlock($context, '// Reconcile the final start-action surfaces', '// TAKE_GOLD AMOUNT ROUTING', 'final context/schema reconciliation');
$contextBlock = substr($contextBlock, 0, strrpos($contextBlock, '// ============================================================'));
$arrangementCueBlock = arExtractBlock($context, '// Arrangement-only offers need a cue when the ordinary Fond+ autonomy nudge does not apply.', '// COURTSHIP NUDGE:', 'arrangement-only initiation cue');
$npcSceneAutonomyBlock = arExtractBlock($context, '// Only use arrangement wording for exact NPCs present in the final current start-target map.', '// AROUSAL RECEPTIVENESS', 'NPC scene autonomy and arrangement cue');

$functions = arRead('functions.php');
$playerOfferBlock = arExtractBlock(
    $functions,
    'if ($sexDisposalEnabled && !$isProstitute) {',
    '// Affection needs the per-NPC floor (Fond 56; Acquaintance 6 for promiscuous marks)',
    'initial player sexual-start offer builder'
);
$playerArrangementLaneBlock = arExtractBlock(
    $functions,
    '// An active directed arrangement replaces only this exact Player pair',
    '// Only offer this action if sex disposal is >20 for this actor',
    'exact Player arrangement-lane check'
);
$playerSnapshotBlock = arExtractBlock(
    $functions,
    '$targetedSexStartCodes = aiagentNsfwSexStartActionCodes();',
    '// Add only the NPC target route after recording which starts the original per-action',
    'player sexual-start offer snapshot'
);
$mainDecisionBlock = arExtractBlock(
    $functions,
    '$isAutonomousFondPlus = empty($intimacyStatus["refused_until_scene_end"])',
    '        if ($playerNeedsDecision) {',
    'main pending-decision computation'
);
$mainDecisionStripBlock = arExtractBlock(
    $functions,
    'if ($playerNeedsDecision) {',
    '} else if ((int)($intimacyStatus["intensity_tier"] ?? 0) >= 3 && empty($intimacyStatus["accepted_sex"]) && $isAutonomousFondPlus) {',
    'main pending-decision tool strip'
) . '}';
$fallbackArrangementBlock = arExtractBlock(
    $functions,
    '$fallbackPendingDecision = (($fallbackIntimacy[\'scene_phase\'] ?? \'\') === \'tier_prompt\')',
    'error_log("[AIAGENTNSFW] All functions available");',
    'fallback pending-decision and current arrangement filter'
);
$actionCallback = arExtractBlock(
    $functions,
    '$GLOBALS["action_post_process_fnct_ex"][]=function($actions) {',
    '// Get configurable sex cooldown',
    'post-model action callback'
);
$markNpcInitiatedConsent = arExtractFunction($functions, 'aiagentNsfwMarkNpcInitiatedSexConsent');
if ($markNpcInitiatedConsent === null) { arFailSetup('Could not extract the production NPC-initiated consent marker'); }
$startSexFunserv = arExtractBlock(
    $functions,
    '$GLOBALS["FUNCSERV"]["ExtCmdStartSex"]=function() {',
    '};',
    'ExtCmdStartSex FUNCSERV'
);
eval($markNpcInitiatedConsent);
eval($startSexFunserv . '};');
$prerequest = arRead('prerequest.php');
$orgConsentBlock = arExtractBlock(
    $prerequest,
    '$orgConsentConfirmed =',
    '    $orgConsentBlocked =',
    'prerequest orgasm consent assignment'
);
$ostimHandler = arRead('nsfw_ostim_handler.php');
$climaxConsentBlock = arExtractBlock(
    $ostimHandler,
    '$sceneConsent =',
    '// SELF-LATCH BREAKER',
    'handleClimax accepted-action consent assignment'
);
$secondSceneConsentOffset = strpos($ostimHandler, '$sceneConsent =', strpos($ostimHandler, '$sceneConsent =') + 1);
if ($secondSceneConsentOffset === false) { arFailSetup('Could not find the second production scene-consent assignment'); }
$orgasmConsentBlock = arExtractBlock(
    substr($ostimHandler, $secondSceneConsentOffset),
    '$sceneConsent =',
    '// SELF-LATCH BREAKER',
    'handleOrgasm accepted-action consent assignment'
);
$partnerMatchesBlock = arExtractBlock(
    $ostimHandler,
    '$priorPartnerMatches =',
    '                $recentAcceptedSex =',
    'accepted-scene carryover partner check'
);
$recentAcceptedBlock = arExtractBlock(
    $ostimHandler,
    '$recentAcceptedSex =',
    '                // NEW SCENE',
    'accepted-scene carryover freshness check'
);

$GLOBALS['ar_settings'] = [
    'NSFW_OPEN_MODE' => false,
    'NSFW_SCENE_CALL_MIN_AFFINITY' => 56,
    'NSFW_AFFAIR_MIN_AFFINITY' => 56,
    'INSTANT_CRUSH_ON_AFFECTION' => true,
];
$GLOBALS['PLAYER_NAME'] = 'Player';
$GLOBALS['HERIKA_NAME'] = 'A';
$GLOBALS['ar_protected'] = [];
$GLOBALS['ar_profiles'] = [];
$GLOBALS['ar_relationships'] = [
    'A' => [
        'Player' => ['aff' => 0, 'type' => 'platonic'],
        'B' => ['aff' => 0, 'type' => 'platonic'],
    ],
];
$GLOBALS['ar_npcs'] = [
    'A' => ['id' => 101, 'npc_name' => 'A', 'extended_data' => json_encode([
        'relationships' => ['Player' => ['aff' => 60, 'type' => 'platonic']],
    ])],
    'B' => ['id' => 202, 'npc_name' => 'B', 'extended_data' => '{}'],
];
$GLOBALS['ar_nearby'] = ['B'];
$GLOBALS['ar_intimacy'] = ['A' => ['level' => 0, 'scene_actors' => []]];
$GLOBALS['ar_crush_writes'] = [];
$GLOBALS['ar_arrangements_enabled'] = true;
$GLOBALS['ar_arrangement_api_rows'] = [
    '101:npc:202' => ['state' => 'active', 'kind' => 'casual', 'owner_id' => 101,
        'target' => 'npc:202', 'playthrough_id' => 'test-scope'],
];
$GLOBALS['AIAGENTNSFW_RELTYPE_POLICY'] = [
    'status' => 'present', 'valid' => true, 'enabled' => true,
    'eligible_types' => ['romantic', 'crush', 'ex'], 'non_romantic_types' => [],
    'use_intimacy_arrangements' => true,
];

// Offer and final dispatch controls use the actual production context block and callback.
$GLOBALS['AIAGENTNSFW_PLAYER_START_OFFER_SNAPSHOT'] = [];
$GLOBALS['ENABLED_FUNCTIONS'] = [];
$GLOBALS['FUNC_LIST'] = [];
$GLOBALS['FUNCTIONS'] = [];
$GLOBALS['F_NAMES'] = ['ExtCmdStartSex' => 'MakeLove'];
$GLOBALS['ar_display_codes'] = ['MakeLove' => 'ExtCmdStartSex'];
$GLOBALS['actionsList'] = "\nAVAILABLE ACTION: MakeLove (Start an explicit scene with the named recipient)\n";
$GLOBALS['FUNCTIONS'][] = [
    'name' => 'MakeLove',
    'description' => 'Start an explicit scene with the named recipient',
    'parameters' => ['properties' => ['target' => ['type' => 'string', 'enum' => []]]],
];
$GLOBALS['FUNC_LIST'][] = 'MakeLove';
function herikaLoadEnabledActionCodesForMode($isNpc, $includeRequirements = true): array { return []; }
function aiagentNsfwIsVampireNpc($name): bool { return false; }
function getGlobalPrompt($key): string { return (string)($GLOBALS['ar_global_prompts'][$key] ?? ''); }

// Active A->B should add the existing NPC route without restoring the Player route.
$apiProjection = aiagentNsfwReadIntimacyArrangement('A', 'B');
arExpect(($apiProjection['state'] ?? '') === 'active', true, 'current MP v1 exact-pair projection is accepted', json_encode($apiProjection));
$added = aiagentNsfwAddEligibleNpcSexStartActions('A', $GLOBALS['ar_intimacy']['A'], true, 25);
arExpect(in_array('ExtCmdStartSex', $added, true), true, 'active exact A-to-B arrangement adds an arousal-qualified NPC start');
arExpect(in_array('ExtCmdStartSex', $GLOBALS['ENABLED_FUNCTIONS'], true), true, 'added start remains enabled');
$actorName = 'A';
eval($contextBlock);
$schemaEnum = $GLOBALS['FUNCTIONS'][0]['parameters']['properties']['target']['enum'] ?? [];
arExpect($schemaEnum === ['B'], true, 'context schema lists only the arrangement-authorized current NPC target');
arExpect(strpos($GLOBALS['actionsList'], 'Allowed recipient for this action: B only.') !== false, true,
    'captured action prose lists the same exact NPC recipient');
arExpect(strpos($GLOBALS['actionsList'], 'Player') === false, true, 'captured action prose does not imply Player eligibility');
$GLOBALS['HERIKA_PERS'] = '';
$GLOBALS['CACHE_PEOPLE'] = 'Player|B';
$GLOBALS['AIAGENTNSFW_INITIATION_AUTONOMY'] = false;
$GLOBALS['ar_global_prompts'] = [];
eval($npcSceneAutonomyBlock);
arExpect(strpos($GLOBALS['HERIKA_PERS'], 'You have reported a current casual intimacy arrangement with B') !== false, true,
    'active casual NPC arrangement adds a target-specific cue even without a close-bond autonomy flag');
arExpect(strpos($GLOBALS['HERIKA_PERS'], 'do not establish anyone else\'s agreement, present willingness, or consent') !== false, true,
    'NPC arrangement context describes only the speaker perspective and does not claim recipient consent');
preg_match('/<directed_npc_intimacy_arrangement>\s*(.*?)\s*<\/directed_npc_intimacy_arrangement>/s', $GLOBALS['HERIKA_PERS'], $npcArrangementCueMatch);
arExpect(isset($npcArrangementCueMatch[1]) && strpos($npcArrangementCueMatch[1], 'B') !== false
    && strpos($npcArrangementCueMatch[1], 'Player') === false, true,
    'NPC arrangement cue names only the offered NPC target and excludes Player');
$GLOBALS['HERIKA_PERS'] = '';
$GLOBALS['ar_global_prompts']['npc_scene_autonomy_nudge'] = 'Keep this operator wording verbatim: #NPC_NAME# may choose.';
$GLOBALS['AIAGENTNSFW_INITIATION_AUTONOMY'] = true;
$GLOBALS['ar_arrangement_api_rows']['101:npc:202']['state'] = 'none';
$GLOBALS['ar_relationships']['A']['B'] = ['aff' => 80, 'type' => 'romantic'];
eval($contextBlock);
eval($npcSceneAutonomyBlock);
arExpect(strpos($GLOBALS['HERIKA_PERS'], 'Keep this operator wording verbatim: A may choose.') !== false, true,
    'baseline NPC autonomy preserves configured wording verbatim without an active arrangement');
arExpect(strpos($GLOBALS['HERIKA_PERS'], '<directed_npc_intimacy_arrangement>') === false, true,
    'inactive or absent arrangements do not add the target-specific context cue');
$GLOBALS['ar_arrangement_api_rows']['101:npc:202']['state'] = 'active';
$GLOBALS['ar_relationships']['A']['B'] = ['aff' => 0, 'type' => 'platonic'];
$GLOBALS['action_post_process_fnct_ex'] = [];
eval($actionCallback);
$filtered = ($GLOBALS['action_post_process_fnct_ex'][0])(['A|MakeLove|ExtCmdStartSex@B']);
arExpect($filtered === ['A|MakeLove|ExtCmdStartSex@B'], true, 'final action callback accepts the fresh exact A-to-B arrangement target');
$GLOBALS['ar_arrangement_api_rows']['101:npc:202']['state'] = 'ended';
$filtered = ($GLOBALS['action_post_process_fnct_ex'][0])(['A|MakeLove|ExtCmdStartSex@B']);
arExpect($filtered === [], true, 'final action callback re-reads and rejects an arrangement ended after prompt construction');
$GLOBALS['ar_arrangement_api_rows']['101:npc:202']['state'] = 'active';

// The Player target must be present in the original action offer and per-action snapshot.
// A separate A->B record cannot grant it; only an exact A->Player record can open that lane.
$GLOBALS['ar_arrangement_api_rows']['101:npc:202']['state'] = 'none';
$GLOBALS['ar_arrangement_api_rows']['101:player'] = [
    'state' => 'active', 'kind' => 'casual', 'owner_id' => 101,
    'target' => 'player', 'playthrough_id' => 'test-scope',
];
$GLOBALS['ENABLED_FUNCTIONS'] = [];
$GLOBALS['AIAGENTNSFW_PLAYER_START_OFFER_SNAPSHOT'] = [];
$GLOBALS['ar_relationships']['A']['Player'] = ['aff' => 0, 'type' => 'platonic'];
$intimacyStatus = ['level' => 0, 'sex_disposal' => 25, 'scene_actors' => []];
$frameworkForcedAggressor = false;
$isSlave = false;
$npcSceneGateDisabled = false;
$skoomaBargain = false;
$affinity = 0;
$sceneCallMinAffinity = 56;
$affectionOrientationOk = true;
$npcName = 'A';
$currentArousal = 25;
$isNaked = 0;
$isNpcScene = false;
$relGateNpc = 'A';
$sexDisposalEnabled = true;
$isProstitute = false;
eval($playerArrangementLaneBlock);
eval($playerOfferBlock);
arExpect(in_array('ExtCmdStartSex', $GLOBALS['ENABLED_FUNCTIONS'], true), true,
    'active exact A-to-Player arrangement qualifies the existing low-affinity start builder');
eval($playerSnapshotBlock);
arExpect(in_array('ExtCmdStartSex', $GLOBALS['AIAGENTNSFW_PLAYER_START_OFFER_SNAPSHOT'], true), true,
    'Player start snapshot records only the action the existing builder offered');
arExpect(!empty($GLOBALS['AIAGENTNSFW_ARRANGEMENT_INITIATION']), true,
    'actual low-affinity Player start offer marks the arrangement-specific initiation cue');
$GLOBALS['HERIKA_PERS'] = '';
$GLOBALS['AIAGENTNSFW_INITIATION_AUTONOMY'] = false;
$actorName = 'A';
eval($arrangementCueBlock);
arExpect(strpos($GLOBALS['HERIKA_PERS'], 'current directed intimacy arrangement records your own stance') !== false, true,
    'arrangement-only cue tells the NPC this is their own current stance');
arExpect(strpos($GLOBALS['HERIKA_PERS'], 'does not establish Player\'s willingness or current consent') !== false, true,
    'arrangement-only cue does not claim recipient willingness or consent');
$GLOBALS['FUNCTIONS'][0]['parameters']['properties']['target']['enum'] = [];
$GLOBALS['ENABLED_FUNCTIONS'] = ['ExtCmdStartSex'];
eval($contextBlock);
arExpect($GLOBALS['FUNCTIONS'][0]['parameters']['properties']['target']['enum'] === ['Player'], true,
    'Player schema is available only from the exact Player arrangement plus its offer snapshot');
$filtered = ($GLOBALS['action_post_process_fnct_ex'][0])(['A|MakeLove|ExtCmdStartSex@Player']);
arExpect($filtered === ['A|MakeLove|ExtCmdStartSex@Player'], true,
    'final action callback accepts the exact offered Player route');
$GLOBALS['ar_arrangement_api_rows']['101:player']['state'] = 'unavailable';
$filtered = ($GLOBALS['action_post_process_fnct_ex'][0])(['A|MakeLove|ExtCmdStartSex@Player']);
arExpect($filtered === [], true, 'final Player route fails closed when current MP state is unavailable');
$GLOBALS['ar_arrangement_api_rows']['101:player']['state'] = 'active';

// Exact Player arrangements cannot bypass the existing orientation policy. NPC-only B stays available.
foreach ([
    'asexual' => ['sexual_orientation' => 'asexual', 'sex' => 0],
    'mismatch' => ['sexual_orientation' => 'heterosexual', 'sex' => 0],
] as $orientationCase => $profile) {
    $GLOBALS['ar_profiles']['A'] = $profile;
    $GLOBALS['PLAYER_SEX'] = 0;
    $GLOBALS['ENABLED_FUNCTIONS'] = [];
    $GLOBALS['AIAGENTNSFW_PLAYER_START_OFFER_SNAPSHOT'] = [];
    $GLOBALS['ar_arrangement_api_rows']['101:player']['state'] = 'active';
    $GLOBALS['ar_arrangement_api_rows']['101:npc:202']['state'] = 'active';
    $GLOBALS['ar_relationships']['A']['Player'] = ['aff' => 0, 'type' => 'platonic'];
    $intimacyStatus = ['level' => 0, 'sex_disposal' => 25, 'scene_actors' => []];
    $frameworkForcedAggressor = false;
    $isSlave = false;
    $npcSceneGateDisabled = false;
    $skoomaBargain = false;
    $affinity = 0;
    $sceneCallMinAffinity = 56;
    $affectionOrientationOk = aiagentNsfwOrientationAllowsPlayer('A');
    $npcName = 'A';
    $currentArousal = 25;
    $isNaked = 0;
    $isNpcScene = false;
    $relGateNpc = 'A';
    $sexDisposalEnabled = true;
    $isProstitute = false;
    eval($playerArrangementLaneBlock);
    eval($playerOfferBlock);
    arExpect($playerArrangementStartLane === false, true,
        "{$orientationCase} Player arrangement fails the actual orientation eligibility helper");
    arExpect(!in_array('ExtCmdStartSex', $GLOBALS['ENABLED_FUNCTIONS'], true), true,
        "{$orientationCase} low-affinity Player start is not added by the actual offer builder");

    $npcAdded = aiagentNsfwAddEligibleNpcSexStartActions('A', $intimacyStatus, true, 25);
    arExpect(in_array('ExtCmdStartSex', $npcAdded, true), true,
        "{$orientationCase} Player mismatch does not disable the separate eligible A-to-B route");
    $GLOBALS['ENABLED_FUNCTIONS'] = $npcAdded;
    $GLOBALS['AIAGENTNSFW_PLAYER_START_OFFER_SNAPSHOT'] = [];
    $GLOBALS['FUNCTIONS'][0]['parameters']['properties']['target']['enum'] = ['Player', 'B'];
    $GLOBALS['FUNC_LIST'] = ['MakeLove'];
    $GLOBALS['HERIKA_PERS'] = '';
    $actorName = 'A';
    eval($contextBlock);
    arExpect(($GLOBALS['FUNCTIONS'][0]['parameters']['properties']['target']['enum'] ?? []) === ['B'], true,
        "{$orientationCase} current context target schema excludes Player but retains B");

    // Simulate a stale/broader model schema; final production callback must still re-check orientation.
    $GLOBALS['FUNCTIONS'][0]['parameters']['properties']['target']['enum'] = ['Player', 'B'];
    $filteredPlayer = ($GLOBALS['action_post_process_fnct_ex'][0])(['A|MakeLove|ExtCmdStartSex@Player']);
    $filteredNpc = ($GLOBALS['action_post_process_fnct_ex'][0])(['A|MakeLove|ExtCmdStartSex@B']);
    arExpect($filteredPlayer === [], true,
        "{$orientationCase} final action validator rejects Player despite a broader stale schema");
    arExpect($filteredNpc === ['A|MakeLove|ExtCmdStartSex@B'], true,
        "{$orientationCase} final action validator retains exact eligible NPC B");
    if ($orientationCase === 'mismatch' && $filteredNpc === ['A|MakeLove|ExtCmdStartSex@B']) {
        $GLOBALS['ar_intimacy'] = ['A' => ['level' => 0, 'sex_disposal' => 25]];
        $GLOBALS['gameRequest'] = ['', '', '', 'A@MakeLove@B'];
        $GLOBALS['HERIKA_NAME'] = 'A';
        $GLOBALS['FUNCSERV']['ExtCmdStartSex']();
        arExpect(empty($GLOBALS['ar_intimacy']['A']['accepted_sex']), true,
            'the actual filtered A-to-B StartSex FUNCSERV does not claim Player acceptance');
    }
}
$GLOBALS['ar_profiles']['A'] = [];
$GLOBALS['PLAYER_SEX'] = 0;

// A self-report alone is not acceptance, even during a low-affinity scene.
$GLOBALS['ar_arrangement_api_rows']['101:player']['state'] = 'active';
$GLOBALS['ar_arrangement_api_rows']['101:npc:202']['state'] = 'none';
$GLOBALS['AIAGENTNSFW_RELTYPE_POLICY'] = [
    'status' => 'present', 'valid' => true, 'enabled' => true,
    'eligible_types' => [], 'non_romantic_types' => [], 'use_intimacy_arrangements' => true,
];
$GLOBALS['ar_relationships']['A']['Player'] = ['aff' => 0, 'type' => 'platonic'];
$actorName = 'A';
$actor = 'A';
$playerName = 'Player';
$orgScenePhase = 'accepted';
$orgServiceJustCompleted = false;
$orgIntimacy = [
    'level' => 2, 'scene_phase' => 'accepted', 'accepted_sex' => false,
    'sex_started' => false, 'had_sex_in_scene' => false,
];
eval($orgConsentBlock);
arExpect($orgConsentConfirmed === false, true,
    'active Player arrangement without accepted_sex is not prerequest orgasm consent');
$intimacyStatus = $orgIntimacy;
$serviceJustCompleted = false;
eval($climaxConsentBlock);
arExpect($sceneConsent === false, true,
    'active Player arrangement without accepted_sex is not handleClimax consent');
eval($orgasmConsentBlock);
arExpect($sceneConsent === false, true,
    'active Player arrangement without accepted_sex is not handleOrgasm consent');

// The exact filtered StartSex action, not the arrangement record, creates the existing accepted state.
$GLOBALS['ar_profiles']['A'] = ['sexual_orientation' => 'bisexual', 'sex' => 0];
$GLOBALS['ENABLED_FUNCTIONS'] = [];
$GLOBALS['AIAGENTNSFW_PLAYER_START_OFFER_SNAPSHOT'] = [];
$GLOBALS['ar_arrangement_api_rows']['101:player']['state'] = 'active';
$GLOBALS['ar_arrangement_api_rows']['101:npc:202']['state'] = 'none';
$GLOBALS['ar_relationships']['A']['Player'] = ['aff' => 0, 'type' => 'platonic'];
$intimacyStatus = ['level' => 0, 'sex_disposal' => 25, 'scene_actors' => []];
$frameworkForcedAggressor = false;
$isSlave = false;
$npcSceneGateDisabled = false;
$skoomaBargain = false;
$affinity = 0;
$sceneCallMinAffinity = 56;
$affectionOrientationOk = aiagentNsfwOrientationAllowsPlayer('A');
$npcName = 'A';
$currentArousal = 25;
$isNaked = 0;
$isNpcScene = false;
$relGateNpc = 'A';
$sexDisposalEnabled = true;
$isProstitute = false;
$playerNeedsDecision = false;
eval($playerArrangementLaneBlock);
eval($playerOfferBlock);
eval($playerSnapshotBlock);
$GLOBALS['FUNC_LIST'] = ['MakeLove'];
$GLOBALS['FUNCTIONS'] = [[
    'name' => 'MakeLove',
    'parameters' => ['properties' => ['target' => ['type' => 'string', 'enum' => []]]],
]];
$actorName = 'A';
eval($contextBlock);
$filteredPlayerStart = ($GLOBALS['action_post_process_fnct_ex'][0])(['A|MakeLove|ExtCmdStartSex@Player']);
arExpect($filteredPlayerStart === ['A|MakeLove|ExtCmdStartSex@Player'], true,
    'active exact arrangement plus Player offer snapshot reaches the real postfilter output');
arExpect(empty($GLOBALS['ar_intimacy']['A']['accepted_sex']), true,
    'offering and filtering the arrangement start do not themselves write accepted_sex');
$GLOBALS['gameRequest'] = ['', '', '', 'A@MakeLove@Player'];
$GLOBALS['HERIKA_NAME'] = 'A';
$GLOBALS['ar_intimacy']['A'] = ['level' => 0, 'sex_disposal' => 25];
$GLOBALS['FUNCSERV']['ExtCmdStartSex']();
arExpect(!empty($GLOBALS['ar_intimacy']['A']['accepted_sex'])
    && ($GLOBALS['ar_intimacy']['A']['last_accept_sex_partner'] ?? '') === 'Player', true,
    'only the actual filtered StartSex FUNCSERV records NPC-initiated acceptance');
$orgIntimacy = $GLOBALS['ar_intimacy']['A'];
$orgIntimacy['scene_actors'] = ['A', 'Player'];
$orgIntimacy['is_npc_scene'] = false;
arExpect(aiagentNsfwAcceptedPlayerArrangementEligible('A', $orgIntimacy), true,
    'accepted Player arrangement requires and recognizes the current exact Player scene pair');
eval($orgConsentBlock);
arExpect($orgConsentConfirmed === true, true,
    'the actual current StartSex acceptance remains valid under the exact active arrangement pair');
$intimacyStatus = $orgIntimacy;
$actor = 'A';
$serviceJustCompleted = false;
eval($climaxConsentBlock);
arExpect($sceneConsent === true, true,
    'actual accepted Player arrangement remains valid in the current Player climax path');
eval($orgasmConsentBlock);
arExpect($sceneConsent === true, true,
    'actual accepted Player arrangement remains valid in the current Player orgasm path');
$GLOBALS['ar_profiles']['A'] = ['spousal_status' => 'married', 'spouse_names' => 'Other'];
$GLOBALS['ar_relationships']['A']['Player'] = ['aff' => 0, 'type' => 'platonic'];
arExpect(aiagentNsfwAcceptedPlayerArrangementEligible('A', $orgIntimacy), false,
    'accepted arrangement continuation retains the existing affair floor for a married NPC');
$GLOBALS['ar_profiles']['A'] = ['sexual_orientation' => 'bisexual', 'sex' => 0];

// An A-to-Player self-report cannot validate an NPC-only scene or an accepted action aimed at B.
$npcOnlyAccepted = [
    'accepted_sex' => true, 'last_accept_sex_partner' => 'B', 'sex_partner' => 'B',
    'scene_actors' => ['A', 'B'], 'is_npc_scene' => true, 'scene_phase' => 'engaged',
    'sex_started' => false, 'had_sex_in_scene' => false, 'npc_affinity_gate_disabled' => false,
];
arExpect(aiagentNsfwAcceptedPlayerArrangementEligible('A', $npcOnlyAccepted), false,
    'A-to-Player arrangement cannot validate a recorded A-to-B NPC-scene acceptance');
$intimacyStatus = $npcOnlyAccepted;
$serviceJustCompleted = false;
eval($climaxConsentBlock);
arExpect($sceneConsent === false, true,
    'NPC-only accepted state does not consume the unrelated Player arrangement lane');
$npcOnlyAccepted['npc_affinity_gate_disabled'] = true;
$intimacyStatus = $npcOnlyAccepted;
eval($climaxConsentBlock);
arExpect($sceneConsent === true, true,
    'the observed NPC-only scene keeps its existing native scene-consent lane');
$orgIntimacy = $npcOnlyAccepted;
eval($orgConsentBlock);
arExpect($orgConsentConfirmed === true, true,
    'prerequest retains the observed NPC-only scene lane without arrangement authorization');

$wrongPlayerPair = [
    'accepted_sex' => true, 'last_accept_sex_partner' => 'B', 'sex_partner' => 'B',
    'scene_actors' => ['A', 'Player'], 'is_npc_scene' => false, 'scene_phase' => 'accepted',
    'sex_started' => false, 'had_sex_in_scene' => false,
];
arExpect(aiagentNsfwAcceptedPlayerArrangementEligible('A', $wrongPlayerPair), false,
    'a Player roster cannot relabel a different accepted partner as the Player');
$orgIntimacy = $wrongPlayerPair;
eval($orgConsentBlock);
arExpect($orgConsentConfirmed === false, true,
    'wrong-partner accepted state cannot use the unrelated Player arrangement');
$wrongPlayerPair['last_accept_sex_partner'] = 'Player';
$wrongPlayerPair['scene_actors'] = ['A', 'B'];
arExpect(aiagentNsfwAcceptedPlayerArrangementEligible('A', $wrongPlayerPair), false,
    'a recorded Player acceptance without Player in the current roster cannot use arrangement eligibility');
$orgIntimacy = $wrongPlayerPair;
eval($orgConsentBlock);
arExpect($orgConsentConfirmed === false, true,
    'missing current Player roster fails closed in prerequest consent');

// The accepted-action carryover still requires the original freshness and partner guards.
$intimacyStatus = $orgIntimacy;
$priorAcceptedSex = true;
$priorAcceptPartner = 'Player';
$priorAcceptTime = time() - 60;
$sceneTier = 3;
$consentCarryoverSeconds = 1800;
$orderedActorList = ['A', 'Player'];
$playerName = 'Player';
eval($partnerMatchesBlock);
eval($recentAcceptedBlock);
arExpect($recentAcceptedSex === true, true,
    'current accepted action carries only with fresh tier and matching Player partner');
$priorAcceptTime = time() - 1801;
eval($recentAcceptedBlock);
arExpect($recentAcceptedSex === false, true,
    'active arrangement cannot extend an expired accepted-action carryover');
$priorAcceptTime = time() - 60;
$priorAcceptPartner = 'B';
eval($partnerMatchesBlock);
eval($recentAcceptedBlock);
arExpect($recentAcceptedSex === false, true,
    'active arrangement cannot carry an accepted action to a different partner');
$priorAcceptedSex = false;
$priorAcceptPartner = 'Player';
eval($partnerMatchesBlock);
eval($recentAcceptedBlock);
arExpect($recentAcceptedSex === false, true,
    'active arrangement without a prior accepted action cannot use carryover');

// A valid ordinary romantic acceptance keeps working when the new arrangement option is disabled.
$GLOBALS['AIAGENTNSFW_RELTYPE_POLICY']['eligible_types'] = ['romantic'];
$GLOBALS['AIAGENTNSFW_RELTYPE_POLICY']['use_intimacy_arrangements'] = false;
$GLOBALS['ar_relationships']['A']['Player'] = ['aff' => 56, 'type' => 'romantic'];
$priorAcceptedSex = true;
$priorAcceptPartner = 'Player';
$priorAcceptTime = time() - 60;
eval($partnerMatchesBlock);
eval($recentAcceptedBlock);
arExpect($recentAcceptedSex === true, true,
    'disabled arrangement option preserves the existing ordinary acceptance carryover');

// Main pending-decision state remains authoritative even when an arrangement changes type eligibility.
$GLOBALS['ENABLED_FUNCTIONS'] = [];
$GLOBALS['AIAGENTNSFW_PLAYER_START_OFFER_SNAPSHOT'] = [];
$GLOBALS['AIAGENTNSFW_ARRANGEMENT_INITIATION'] = false;
$intimacyStatus = ['level' => 0, 'sex_disposal' => 25, 'scene_phase' => 'tier_prompt', 'intensity_tier' => 3,
    'accepted_sex' => false, 'scene_actors' => []];
$affinity = 56;
$sceneCallMinAffinity = 56;
$isNpcScene = false;
$openModeAdultPlayerRoute = false;
$frameworkForcedAggressor = false;
$isSlave = false;
$isProstitute = false;
$npcSceneGateDisabled = false;
$skoomaBargain = false;
$GLOBALS['ar_relationships']['A']['Player'] = ['aff' => 56, 'type' => 'platonic'];
eval($playerArrangementLaneBlock);
eval($playerOfferBlock);
eval($mainDecisionBlock);
arExpect($isAutonomousFondPlus === false && $playerNeedsDecision === true, true,
    'arrangement-only type eligibility does not bypass the existing pending Player decision');
$GLOBALS['ENABLED_FUNCTIONS'] = ['ExtCmdStartSex', 'ExtCmdStartBlowJob'];
eval($mainDecisionStripBlock);
arExpect($GLOBALS['ENABLED_FUNCTIONS'] === ['ExtCmdAcceptSex', 'ExtCmdRefuseSex'], true,
    'main pending-decision strip keeps only the existing consent controls');
$GLOBALS['AIAGENTNSFW_ARRANGEMENT_INITIATION'] = false;
eval($playerSnapshotBlock);
arExpect(empty($GLOBALS['AIAGENTNSFW_ARRANGEMENT_INITIATION']), true,
    'pending decision cannot receive the arrangement initiation cue');

// The actual fallback consent filter keeps the arrangement lane only when no decision or refusal is active.
$GLOBALS['AIAGENTNSFW_RELTYPE_POLICY']['use_intimacy_arrangements'] = true;
$fallbackNpcName = 'A';
$fallbackIntimacy = [];
$fallbackIsSlaveActor = false;
$fallbackIsProstitute = false;
$fallbackSkoomaBargain = false;
$fallbackSceneCallMin = 56;
$fallbackAffinity = 0;
$fallbackIsUnpaidProstitute = false;
aiagentNsfwClearIntimacyArrangementOfferReadCache();
$GLOBALS['ar_arrangement_api_rows']['101:player']['state'] = 'active';
$GLOBALS['ENABLED_FUNCTIONS'] = ['ExtCmdStartSex'];
eval($fallbackArrangementBlock);
arExpect($fallbackArrangementLane === true && $fallbackConsented === true
    && in_array('ExtCmdStartSex', $GLOBALS['ENABLED_FUNCTIONS'], true), true,
    'funcret fallback retains an exact active Player arrangement lane');
$fallbackIntimacy = ['scene_phase' => 'tier_prompt', 'intensity_tier' => 3, 'accepted_sex' => false];
$GLOBALS['ENABLED_FUNCTIONS'] = ['ExtCmdStartSex'];
eval($fallbackArrangementBlock);
arExpect($fallbackPendingDecision === true && $fallbackArrangementLane === false && $fallbackConsented === false
    && !in_array('ExtCmdStartSex', $GLOBALS['ENABLED_FUNCTIONS'], true), true,
    'funcret fallback preserves its pending-decision restriction');
$fallbackIntimacy = ['refused_until_scene_end' => true];
$GLOBALS['ENABLED_FUNCTIONS'] = ['ExtCmdStartSex'];
eval($fallbackArrangementBlock);
arExpect($fallbackArrangementLane === false && $fallbackConsented === false
    && !in_array('ExtCmdStartSex', $GLOBALS['ENABLED_FUNCTIONS'], true), true,
    'funcret fallback preserves current refusal despite an active arrangement');

// The main offer also rejects arrangement-only starts during a current refusal or rejected phase.
$GLOBALS['ENABLED_FUNCTIONS'] = [];
$intimacyStatus = ['level' => 0, 'sex_disposal' => 25, 'scene_phase' => 'rejected', 'refusal_expressed' => true];
$affinity = 0;
eval($playerArrangementLaneBlock);
eval($playerOfferBlock);
arExpect(!in_array('ExtCmdStartSex', $GLOBALS['ENABLED_FUNCTIONS'], true), true,
    'main rejected/refused route cannot add an arrangement start');

$GLOBALS['ar_arrangement_api_rows']['101:player']['state'] = 'none';
$GLOBALS['ar_arrangement_api_rows']['101:npc:202']['state'] = 'active';
$intimacyStatus = ['level' => 0, 'sex_disposal' => 25, 'scene_actors' => []];
$GLOBALS['ar_relationships']['A']['Player'] = ['aff' => 0, 'type' => 'platonic'];
$affinity = 0;
$currentArousal = 25;
$sceneCallMinAffinity = 56;

// The old PR7 behavior remains the fallback when no current exact-pair MP record exists.
$GLOBALS['AIAGENTNSFW_RELTYPE_POLICY']['use_intimacy_arrangements'] = false;
arExpect(aiagentNsfwNpcToNpcSexEligible('A', 'B'), false, 'default-off setting preserves ordinary affinity/type gate');
$defaults = aiagentNsfwNormalizeRelTypeConfig((object)['eligible_types' => ['romantic']]);
arExpect(array_key_exists('use_intimacy_arrangements', $defaults), true, 'legacy config normalizes the new option');
arExpect(($defaults['use_intimacy_arrangements'] ?? null) === false, true, 'legacy config defaults arrangement use off');

// With opt-in enabled, only the current directed A->B arrangement can replace the ordinary pair gate.
$GLOBALS['AIAGENTNSFW_RELTYPE_POLICY']['use_intimacy_arrangements'] = true;
arExpect(aiagentNsfwNpcToNpcSexEligible('A', 'B'), true, 'active exact A-to-B arrangement can coexist with negative affinity');
arExpect(aiagentNsfwNpcToNpcSexEligible('A', 'Player'), false, 'NPC arrangement does not enable a Player route');
$GLOBALS['AIAGENTNSFW_RELTYPE_POLICY']['eligible_types'] = [];
$GLOBALS['ar_arrangement_api_rows']['101:player']['state'] = 'active';
arExpect(aiagentNsfwRelTypeSexEligible('A'), false,
    'active self-reported Player arrangement does not satisfy ordinary-only consent eligibility');
arExpect(aiagentNsfwRelTypeSexEligible('A', true), true,
    'active exact Player arrangement is accepted only by the explicit outbound eligibility call');
$GLOBALS['AIAGENTNSFW_RELTYPE_POLICY']['eligible_types'] = ['romantic', 'crush', 'ex'];
$GLOBALS['ar_arrangement_api_rows']['101:player']['state'] = 'none';
$GLOBALS['ar_arrangement_api_rows']['101:npc:202']['state'] = 'ended';
arExpect(aiagentNsfwNpcToNpcSexEligible('A', 'B'), false, 'ended arrangement falls back to ordinary pair gate');
$GLOBALS['ar_arrangement_api_rows']['101:npc:202']['state'] = 'active';
$GLOBALS['ar_arrangement_api_rows']['101:npc:202']['owner_id'] = 999;
arExpect(aiagentNsfwNpcToNpcSexEligible('A', 'B'), false, 'identity-mismatched projection cannot authorize');
$GLOBALS['ar_arrangement_api_rows']['101:npc:202']['owner_id'] = 101;

// The legacy disabled type gate does not lose its scene-call floor when the new option is off.
$GLOBALS['ar_relationships']['A']['B'] = ['aff' => 0, 'type' => 'platonic'];
$GLOBALS['AIAGENTNSFW_RELTYPE_POLICY']['enabled'] = false;
$GLOBALS['AIAGENTNSFW_RELTYPE_POLICY']['use_intimacy_arrangements'] = false;
arExpect(aiagentNsfwNpcToNpcSexEligible('A', 'B'), false,
    'disabled type gate with arrangement option off preserves the ordinary NPC affinity floor');
$GLOBALS['AIAGENTNSFW_RELTYPE_POLICY']['use_intimacy_arrangements'] = true;
arExpect(aiagentNsfwNpcToNpcSexEligible('A', 'B'), true,
    'explicit exact-pair arrangement is available while the legacy type grid is disabled');
$GLOBALS['AIAGENTNSFW_RELTYPE_POLICY']['enabled'] = true;
$GLOBALS['AIAGENTNSFW_RELTYPE_POLICY']['use_intimacy_arrangements'] = true;
$GLOBALS['ar_relationships']['A']['B'] = ['aff' => 0, 'type' => 'platonic'];

// The Player arrangement cannot skip the distinct affair floor or turn profile-read errors into a bypass.
$GLOBALS['ar_arrangement_api_rows']['101:player'] = [
    'state' => 'active', 'kind' => 'casual', 'owner_id' => 101,
    'target' => 'player', 'playthrough_id' => 'test-scope',
];
$GLOBALS['ar_relationships']['A']['Player'] = ['aff' => 0, 'type' => 'platonic'];
$GLOBALS['ar_profile_read_error'] = true;
arExpect(aiagentNsfwRelTypeSexEligible('A', true), false,
    'arrangement-only Player route fails closed when spouse/affair profile data cannot be read');
$GLOBALS['ar_profile_read_error'] = false;
$GLOBALS['ar_profiles']['A'] = ['spousal_status' => 'married', 'spouse_names' => 'Other'];
$GLOBALS['ar_relationships']['A']['Player'] = ['aff' => 40, 'type' => 'platonic'];
arExpect(aiagentNsfwRelTypeSexEligible('A', true), false,
    'active arrangement preserves the affair floor for a married NPC');
$GLOBALS['ar_profiles']['A'] = [];
$GLOBALS['ar_relationships']['A']['Player'] = ['aff' => 0, 'type' => 'platonic'];

// Casual arrangement preservation is independent of the sexual-eligibility opt-in.
$GLOBALS['AIAGENTNSFW_RELTYPE_POLICY']['use_intimacy_arrangements'] = false;
$GLOBALS['ar_arrangement_api_rows']['101:player'] = [
    'state' => 'active', 'kind' => 'casual', 'owner_id' => 101,
    'target' => 'player', 'playthrough_id' => 'test-scope',
];
aiagentNsfwInstantCrush('A', 'affection', 'A|ExtCmdKiss@Player');
arExpect($GLOBALS['ar_crush_writes'] === [], true, 'casual arrangement prevents instant-crush type conversion while eligibility option is off');

// The actual relationship refusal method honors only the exact opted-in Player pair.
$GLOBALS['PLAYER_NAME'] = 'Player';
$GLOBALS['ar_relationships']['A']['Player'] = ['aff' => 40, 'type' => 'platonic'];
$GLOBALS['ar_profiles']['A'] = [];
$GLOBALS['AIAGENTNSFW_RELTYPE_POLICY'] = [
    'status' => 'present', 'valid' => true, 'enabled' => true,
    'eligible_types' => ['romantic', 'crush', 'ex'], 'non_romantic_types' => [],
    'use_intimacy_arrangements' => true,
    'prompts' => ['prompt_friendly' => 'Saved current relationship-type refusal.'],
];
$GLOBALS['ar_arrangement_api_rows'] = [
    '101:player' => ['state' => 'active', 'kind' => 'casual', 'owner_id' => 101,
        'target' => 'player', 'playthrough_id' => 'test-scope'],
];
$relationshipProbe = new ArrangementNsfwRelationshipProbe();
$playerArrangementRefusal = $relationshipProbe->getRelTypeGateRefusal('A', 'Player');
arExpect($playerArrangementRefusal === null, true,
    'active exact Player arrangement removes only the ordinary relationship-type refusal');
$GLOBALS['ar_arrangement_api_rows'] = [
    '101:npc:202' => ['state' => 'active', 'kind' => 'casual', 'owner_id' => 101,
        'target' => 'npc:202', 'playthrough_id' => 'test-scope'],
];
$unrelatedArrangementRefusal = $relationshipProbe->getRelTypeGateRefusal('A', 'Player');
arExpect(is_string($unrelatedArrangementRefusal) && $unrelatedArrangementRefusal !== '', true,
    'an unrelated A-to-NPC arrangement preserves the Player relationship-type refusal');

// A single prompt offer pass should read each exact owner-target projection once,
// then final dispatch must clear that prompt snapshot and see a newer ended state.
$offerCacheFunctionsPresent = function_exists('aiagentNsfwBeginIntimacyArrangementOfferReadCache')
    && function_exists('aiagentNsfwClearIntimacyArrangementOfferReadCache');
arExpect($offerCacheFunctionsPresent, true, 'offer-only MP projection cache has explicit begin/clear boundaries');
if ($offerCacheFunctionsPresent) {
    $GLOBALS['PLAYER_NAME'] = 'Player';
    $GLOBALS['HERIKA_NAME'] = 'A';
    $GLOBALS['ar_npcs'] = [
        'A' => ['id' => 101, 'npc_name' => 'A'],
        'B' => ['id' => 202, 'npc_name' => 'B'],
        'C' => ['id' => 303, 'npc_name' => 'C'],
        'D' => ['id' => 404, 'npc_name' => 'D'],
        'E' => ['id' => 505, 'npc_name' => 'E'],
        'F' => ['id' => 606, 'npc_name' => 'F'],
    ];
    $GLOBALS['ar_nearby'] = ['B', 'C', 'D', 'E', 'F'];
    $GLOBALS['ar_relationships']['A'] = ['Player' => ['aff' => 0, 'type' => 'neutral']];
    foreach (['B', 'C', 'D', 'E', 'F'] as $targetName) {
        $GLOBALS['ar_relationships']['A'][$targetName] = ['aff' => 0, 'type' => 'neutral'];
    }
    $GLOBALS['ar_intimacy']['A'] = ['level' => 0, 'scene_actors' => []];
    $intimacyStatus = $GLOBALS['ar_intimacy']['A'];
    $GLOBALS['AIAGENTNSFW_RELTYPE_POLICY'] = [
        'valid' => true, 'enabled' => true,
        'eligible_types' => ['romantic', 'crush', 'ex'], 'non_romantic_types' => [],
        'use_intimacy_arrangements' => true,
    ];
    $GLOBALS['ar_arrangement_api_rows'] = [
        '101:npc:202' => ['state' => 'active', 'kind' => 'casual', 'owner_id' => 101,
            'target' => 'npc:202', 'playthrough_id' => 'test-scope'],
    ];
    $GLOBALS['AIAGENTNSFW_PLAYER_START_OFFER_SNAPSHOT'] = [];
    $actionCodes = aiagentNsfwSexStartActionCodes();
    $GLOBALS['ar_arrangement_api_calls'] = [];
    foreach ($actionCodes as $actionCode) {
        aiagentNsfwSexStartTargetOptions('A', $intimacyStatus, $actionCode);
    }
    $uncachedReads = count($GLOBALS['ar_arrangement_api_calls']);
    arExpect(count($actionCodes) === 9 && $uncachedReads === 54, true,
        'five-target nine-action offer fixture measures 54 uncached exact-pair MP reads',
        json_encode(['actions' => count($actionCodes), 'reads' => $uncachedReads]));

    aiagentNsfwBeginIntimacyArrangementOfferReadCache();
    $GLOBALS['ar_arrangement_api_calls'] = [];
    foreach ($actionCodes as $actionCode) {
        aiagentNsfwSexStartTargetOptions('A', $intimacyStatus, $actionCode);
    }
    $cachedReads = count($GLOBALS['ar_arrangement_api_calls']);
    arExpect($cachedReads === 6, true,
        'offer-only cache bounds the same five-target nine-action pass to six exact-pair MP reads',
        json_encode(['actions' => count($actionCodes), 'reads' => $cachedReads]));

    $GLOBALS['ar_arrangement_api_rows']['101:npc:202']['state'] = 'ended';
    $readsBeforeDispatch = count($GLOBALS['ar_arrangement_api_calls']);
    $GLOBALS['ENABLED_FUNCTIONS'] = ['ExtCmdStartSex'];
    $GLOBALS['FUNC_LIST'] = ['MakeLove'];
    $GLOBALS['F_NAMES'] = ['ExtCmdStartSex' => 'MakeLove'];
    $GLOBALS['ar_display_codes'] = ['MakeLove' => 'ExtCmdStartSex'];
    $GLOBALS['FUNCTIONS'] = [[
        'name' => 'MakeLove',
        'parameters' => ['properties' => ['target' => ['type' => 'string', 'enum' => ['B']]]],
    ]];
    $GLOBALS['action_post_process_fnct_ex'] = [];
    eval($actionCallback);
    $filtered = ($GLOBALS['action_post_process_fnct_ex'][0])(['A|MakeLove|ExtCmdStartSex@B']);
    arExpect($filtered === [], true,
        'final dispatch rejects an arrangement ended after offer construction');
    arExpect(count($GLOBALS['ar_arrangement_api_calls']) > $readsBeforeDispatch, true,
        'final dispatch clears the cached prompt projection and performs a fresh MP read',
        json_encode(['reads_before_dispatch' => $readsBeforeDispatch, 'reads_after_dispatch' => count($GLOBALS['ar_arrangement_api_calls'])]));
    aiagentNsfwClearIntimacyArrangementOfferReadCache();
}

// Exercise the actual runtime configuration reader and its caller behavior.
$readPolicy = static function ($row) {
    unset($GLOBALS['AIAGENTNSFW_RELTYPE_POLICY']);
    $GLOBALS['db'] = new ArrangementPolicyTestDb($row);
    return aiagentNsfwGetRelTypePolicy();
};
$GLOBALS['PLAYER_NAME'] = 'Player';
$GLOBALS['ar_relationships']['A'] = [
    'Player' => ['aff' => 80, 'type' => 'romantic'],
    'B' => ['aff' => 80, 'type' => 'romantic'],
];
$GLOBALS['ar_arrangement_api_rows'] = [
    '101:player' => ['state' => 'active', 'kind' => 'casual', 'owner_id' => 101,
        'target' => 'player', 'playthrough_id' => 'test-scope'],
    '101:npc:202' => ['state' => 'active', 'kind' => 'casual', 'owner_id' => 101,
        'target' => 'npc:202', 'playthrough_id' => 'test-scope'],
];
$GLOBALS['ar_arrangement_api_calls'] = [];
$missingRuntimePolicy = $readPolicy(['value' => '']);
arExpect($missingRuntimePolicy['valid'] === true
    && $missingRuntimePolicy['eligible_types'] === ['romantic', 'crush', 'ex']
    && $missingRuntimePolicy['use_intimacy_arrangements'] === false, true,
    'actual runtime reader supplies legacy built-ins and keeps the arrangement option off when missing');
arExpect(aiagentNsfwRelTypeSexEligible('A') && aiagentNsfwNpcToNpcSexEligible('A', 'B'), true,
    'missing arrangement config preserves the built-in Player and NPC type routes');
arExpect($GLOBALS['ar_arrangement_api_calls'] === [], true,
    'default-off eligibility performs zero MP arrangement reads');

$emptyRuntimePolicy = $readPolicy(['value' => json_encode([
    'enabled' => true, 'eligible_types' => [], 'non_romantic_types' => [],
    'use_intimacy_arrangements' => false,
], JSON_THROW_ON_ERROR)]);
$GLOBALS['ar_relationships']['A']['Player'] = ['aff' => 80, 'type' => 'romantic'];
$GLOBALS['ar_relationships']['A']['B'] = ['aff' => 80, 'type' => 'romantic'];
arExpect($emptyRuntimePolicy['valid'] === true && $emptyRuntimePolicy['eligible_types'] === [], true,
    'actual runtime reader preserves an explicit empty eligible list');
arExpect(!aiagentNsfwRelTypeSexEligible('A') && !aiagentNsfwNpcToNpcSexEligible('A', 'B'), true,
    'explicit empty eligible list denies both Player and NPC routes when arrangement use is off');

$malformedRuntimePolicy = $readPolicy(['value' => '{"enabled":true,"eligible_types":[],"use_intimacy_arrangements":"true"}']);
arExpect($malformedRuntimePolicy['valid'] === false
    && !aiagentNsfwRelTypeSexEligible('A') && !aiagentNsfwNpcToNpcSexEligible('A', 'B'), true,
    'malformed runtime arrangement flag fails closed in both eligibility routes');
$errorRuntimePolicy = $readPolicy([]);
arExpect($errorRuntimePolicy['valid'] === false
    && !aiagentNsfwRelTypeSexEligible('A') && !aiagentNsfwNpcToNpcSexEligible('A', 'B'), true,
    'runtime policy read failure fails closed in both eligibility routes');

$disabledRuntimePolicy = $readPolicy(['value' => json_encode([
    'enabled' => false, 'eligible_types' => [], 'non_romantic_types' => [],
    'use_intimacy_arrangements' => false,
], JSON_THROW_ON_ERROR)]);
arExpect($disabledRuntimePolicy['valid'] === true && $disabledRuntimePolicy['enabled'] === false
    && aiagentNsfwRelTypeSexEligible('A', true) && aiagentNsfwNpcToNpcSexEligible('A', 'B'), true,
    'explicitly disabled legacy type gate keeps its existing bypass with arrangement use off');

$arrangementRuntimePolicy = $readPolicy(['value' => json_encode([
    'enabled' => true, 'eligible_types' => [], 'non_romantic_types' => [],
    'use_intimacy_arrangements' => true,
], JSON_THROW_ON_ERROR)]);
$GLOBALS['ar_relationships']['A']['Player'] = ['aff' => 0, 'type' => 'platonic'];
$GLOBALS['ar_relationships']['A']['B'] = ['aff' => -42, 'type' => 'enemy'];
arExpect($arrangementRuntimePolicy['valid'] === true && $arrangementRuntimePolicy['use_intimacy_arrangements'] === true
    && aiagentNsfwRelTypeSexEligible('A', true) && aiagentNsfwNpcToNpcSexEligible('A', 'B'), true,
    'actual opt-in runtime reader authorizes only the two current active directed arrangement pairs');

$output = [
    'checks' => $GLOBALS['ar_checks'],
    'failures' => $GLOBALS['ar_failures'],
    'source_sha256' => $GLOBALS['ar_source_hashes'],
    'api_stub' => 'ChimMindPoisoning v1 response stub; final cross-fixture uses the producer API with a synthetic store',
];
echo json_encode($output, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
exit(count($GLOBALS['ar_failures']) > 0 ? 1 : 0);
}
