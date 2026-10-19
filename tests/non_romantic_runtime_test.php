<?php
declare(strict_types=1);

/*
 * Isolated compatibility fixture. It extracts named production PHP functions and
 * the real offer/filter blocks from this checkout, then supplies only deterministic
 * relationship, roster, protection, and CHIM action-list collaborators. It does
 * not reimplement eligibility or target filtering.
 */

$repo = dirname(__DIR__);
$checks = [];
$failures = [];
$sourceEvidence = [];

function nrFailSetup(string $message): void
{
    fwrite(STDERR, "FIXTURE ERROR: {$message}\n");
    exit(2);
}

function nrReadSource(string $relative): string
{
    $path = dirname(__DIR__) . DIRECTORY_SEPARATOR . $relative;
    $source = file_get_contents($path);
    if (!is_string($source)) { nrFailSetup("Cannot read production source {$relative}"); }
    $GLOBALS['nr_source_evidence'][$relative] = hash('sha256', $source);
    return str_replace("\r\n", "\n", $source);
}

function nrLineAt(string $source, int $offset): int
{
    return substr_count(substr($source, 0, $offset), "\n") + 1;
}

function nrExtractFunction(string $source, string $name): string
{
    $tokens = token_get_all($source);
    $offsets = [];
    $offset = 0;
    foreach ($tokens as $token) {
        $offsets[] = $offset;
        $offset += strlen(is_array($token) ? $token[1] : $token);
    }

    $matches = [];
    foreach ($tokens as $i => $token) {
        if (!is_array($token) || $token[0] !== T_FUNCTION) { continue; }
        $nameIndex = $i + 1;
        while (isset($tokens[$nameIndex]) && is_array($tokens[$nameIndex])
            && in_array($tokens[$nameIndex][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
            $nameIndex++;
        }
        if (!isset($tokens[$nameIndex]) || !is_array($tokens[$nameIndex])
            || $tokens[$nameIndex][0] !== T_STRING || $tokens[$nameIndex][1] !== $name) {
            continue;
        }

        $openIndex = $nameIndex;
        while (isset($tokens[$openIndex]) && $tokens[$openIndex] !== '{') { $openIndex++; }
        if (!isset($tokens[$openIndex])) { nrFailSetup("Missing body for production function {$name}"); }
        $depth = 0;
        $end = null;
        for ($j = $openIndex; isset($tokens[$j]); $j++) {
            $opens = $tokens[$j] === '{'
                || (is_array($tokens[$j]) && in_array($tokens[$j][0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true));
            if ($opens) { $depth++; }
            elseif ($tokens[$j] === '}') {
                $depth--;
                if ($depth === 0) { $end = $offsets[$j] + 1; break; }
            }
        }
        if ($end === null) { nrFailSetup("Unbalanced production function {$name}"); }
        $matches[] = substr($source, $offsets[$i], $end - $offsets[$i]);
    }
    if (count($matches) !== 1) {
        nrFailSetup("Expected one production function {$name}; found " . count($matches));
    }
    return $matches[0];
}

function nrExtractAnchoredBlock(string $source, string $startAnchor, string $endAnchor, string $label): array
{
    $start = strpos($source, $startAnchor);
    $end = $start === false ? false : strpos($source, $endAnchor, $start + strlen($startAnchor));
    if ($start === false || $end === false) {
        nrFailSetup("Missing source anchors for {$label}");
    }
    return [
        substr($source, $start, $end - $start),
        nrLineAt($source, $start),
        nrLineAt($source, $end),
    ];
}

function nrExpect(bool $actual, bool $expected, string $label, string $detail = ''): void
{
    $ok = $actual === $expected;
    $entry = ['name' => $label, 'expected' => $expected, 'actual' => $actual, 'pass' => $ok];
    if ($detail !== '') { $entry['detail'] = $detail; }
    $GLOBALS['nr_checks'][] = $entry;
    if (!$ok) { $GLOBALS['nr_failures'][] = $entry; }
}

function nrActionPresent(array $actions, string $command, ?string $target = null): bool
{
    foreach ($actions as $action) {
        $parts = explode('|', (string)$action);
        $payload = explode('@', (string)($parts[2] ?? ''), 2);
        if (($payload[0] ?? '') === $command && ($target === null || trim((string)($payload[1] ?? '')) === $target)) {
            return true;
        }
    }
    return false;
}

function nrEnumFor(string $displayName): array
{
    foreach ((array)($GLOBALS['FUNCTIONS'] ?? []) as $definition) {
        if (($definition['name'] ?? '') === $displayName) {
            return (array)($definition['parameters']['properties']['target']['enum'] ?? []);
        }
    }
    return [];
}

function nrSeedStartDefinitions(array $enumOverrides = []): void
{
    $GLOBALS['FUNCTIONS'] = [];
    $GLOBALS['FUNC_LIST'] = [];
    $displayByCode = array_flip($GLOBALS['nr_code_by_display']);
    foreach (aiagentNsfwSexStartActionCodes() as $code) {
        $display = $displayByCode[$code] ?? $code;
        $targets = aiagentNsfwSexStartTargetOptions(
            $GLOBALS['HERIKA_NAME'],
            getIntimacyForActor($GLOBALS['HERIKA_NAME']),
            $code
        );
        $enumTargets = $enumOverrides[$code] ?? $targets;
        $targetSchema = ['type' => 'string'];
        if ($code === 'ExtCmdStartThreesome') {
            $targetSchema['description'] = aiagentNsfwSexStartTargetNote($code, $targets);
            if (isset($enumOverrides[$code])) { $targetSchema['enum'] = $enumTargets; }
        } else {
            $targetSchema['enum'] = $enumTargets;
        }
        $GLOBALS['FUNCTIONS'][] = [
            'name' => $display,
            'description' => 'A starts a scene with exact recipients',
            'parameters' => ['properties' => ['target' => $targetSchema]],
        ];
        if (in_array($code, (array)($GLOBALS['ENABLED_FUNCTIONS'] ?? []), true)) {
            $GLOBALS['FUNC_LIST'][] = $display;
        }
    }
    foreach ([['AcceptSex', 'ExtCmdAcceptSex'], ['RefuseSex', 'ExtCmdRefuseSex']] as [$display, $code]) {
        $GLOBALS['FUNCTIONS'][] = [
            'name' => $display,
            'parameters' => ['properties' => ['target' => ['type' => 'string', 'enum' => ['Player']]]],
        ];
        if (in_array($code, (array)($GLOBALS['ENABLED_FUNCTIONS'] ?? []), true)) {
            $GLOBALS['FUNC_LIST'][] = $display;
        }
    }
    $GLOBALS['FUNCTIONS'][] = [
        'name' => 'Kiss', 'description' => 'An affectionate action',
        'parameters' => ['properties' => ['target' => ['type' => 'string']]],
    ];
    if (in_array('ExtCmdKiss', (array)($GLOBALS['ENABLED_FUNCTIONS'] ?? []), true)) {
        $GLOBALS['FUNC_LIST'][] = 'Kiss';
    }
}

$GLOBALS['nr_checks'] = &$checks;
$GLOBALS['nr_failures'] = &$failures;
$GLOBALS['nr_source_evidence'] = &$sourceEvidence;
$GLOBALS['nr_settings'] = ['NSFW_OPEN_MODE' => false, 'NSFW_SCENE_CALL_MIN_AFFINITY' => 56];
$GLOBALS['nr_reltype_config'] = ['enabled' => true, 'eligible_types' => ['casual_intimacy']];
$GLOBALS['nr_catalog_ready'] = false;
$GLOBALS['nr_mode_enabled_actions'] = [];
$GLOBALS['nr_disabled_actions'] = [];
$GLOBALS['nr_player_conflict'] = false;
$GLOBALS['nr_relationships'] = [];
$GLOBALS['nr_intimacy'] = [];
$GLOBALS['nr_protected'] = [];
$GLOBALS['nr_nearby'] = ['B', 'D', 'Playerborn'];
$GLOBALS['nr_aliases'] = [];
$GLOBALS['nr_code_by_display'] = [
    'MakeLove' => 'ExtCmdStartSex',
    'StartBlowJob' => 'ExtCmdStartBlowJob',
    'StartAnalSex' => 'ExtCmdStartAnalSex',
    'StartMassage' => 'ExtCmdStartMassage',
    'StartThreesome' => 'ExtCmdStartThreesome',
    'StartTitfuck' => 'ExtCmdStartTitfuck',
    'StartHandJobSex' => 'ExtCmdStartHandJobSex',
    'StartHandjobSex' => 'ExtCmdStartHandjobSex',
    'DrinkBloodSex' => 'ExtCmdDrinkBloodSex',
    'AcceptSex' => 'ExtCmdAcceptSex',
    'RefuseSex' => 'ExtCmdRefuseSex',
    'Kiss' => 'ExtCmdKiss',
];

require_once $repo . '/open_mode_policy.php';
require_once $repo . '/nsfw_data.php';

class NonRomanticRuntimeDb
{
    public function fetchOne(string $query)
    {
        if (strpos($query, 'aiagent_nsfw_reltypes') !== false) {
            if (!empty($GLOBALS['nr_reltype_read_error'])) { return []; }
            if (array_key_exists('nr_reltype_raw', $GLOBALS)) { return ['value' => $GLOBALS['nr_reltype_raw']]; }
            return ['value' => json_encode($GLOBALS['nr_reltype_config'], JSON_THROW_ON_ERROR)];
        }
        return null;
    }
}

class RelationshipManager
{
    public static function getRelationship(string $speaker, string $target): array
    {
        return $GLOBALS['nr_relationships'][$speaker][$target] ?? ['aff' => 0, 'type' => 'neutral'];
    }
    public static function getPlayerRelationship(string $speaker): array
    {
        return self::getRelationship($speaker, 'Player');
    }
}

function _getNsfwSetting($key, $default = null)
{
    return $GLOBALS['nr_settings'][$key] ?? $default;
}

function aiagentNsfwIsProtectedNpc($name): bool
{
    return in_array(strtolower(trim((string)$name)), array_map('strtolower', $GLOBALS['nr_protected']), true);
}

function aiagentNsfwIsChildNpc($name): bool { return false; }
function isNpcSlave($name): bool { return false; }
function isProstitute($name): bool { return false; }
function getDrugStageForActor($name, $drug): int { return 0; }
function getNormalizedNearbyDialogueListenerNames($includeCurrent = true): array
{
    return $GLOBALS['nr_nearby'];
}
function nsfwIsNarratorName($name): bool { return false; }
function getFunctionCodeName($displayName): string
{
    return $GLOBALS['nr_code_by_display'][$displayName] ?? '';
}
function herikaActionCatalogDbReady(): bool { return !empty($GLOBALS['nr_catalog_ready']); }
function herikaLoadEnabledActionCodesForMode($isNpc, $includeRequirements = true): array
{
    return $GLOBALS['nr_mode_enabled_actions'];
}
function herikaActionCatalogIsActionEnabled($code): bool
{
    return !in_array($code, $GLOBALS['nr_disabled_actions'], true);
}
function aiagentNsfwArousalNum($key, $default = 0): int { return (int)$default; }
function aiagentNsfwPlayerConflictActive(): bool { return !empty($GLOBALS['nr_player_conflict']); }
function aiagentNsfwPlayerSceneCallCooldownActive(): bool { return !empty($GLOBALS['nr_player_scene_cooldown']); }
function aiagentNsfwWhiskeyDickActive(): bool { return !empty($GLOBALS['nr_whiskey_dick']); }
function herikaGetPromptActionDescription($name, $description): string
{
    return (string)$description;
}
function getIntimacyForActor($name): array
{
    return $GLOBALS['nr_intimacy'][$name] ?? [];
}
function FindClosestNPCName($name): string
{
    $name = trim((string)$name);
    return $GLOBALS['nr_aliases'][$name] ?? $name;
}

function nrResetScenario(array $overrides = []): void
{
    $GLOBALS['PLAYER_NAME'] = array_key_exists('nr_player_name', $overrides) ? (string)$overrides['nr_player_name'] : 'Player';
    $GLOBALS['HERIKA_NAME'] = 'A';
    $GLOBALS['db'] = new NonRomanticRuntimeDb();
    $GLOBALS['nr_relationships'] = [
        'A' => [
            'Player' => ['aff' => 72, 'type' => 'platonic'],
            'B' => ['aff' => 72, 'type' => 'casual_intimacy'],
            'C' => ['aff' => 72, 'type' => 'casual_intimacy'],
            'D' => ['aff' => 72, 'type' => 'platonic'],
            'Playerborn' => ['aff' => 72, 'type' => 'casual_intimacy'],
        ],
    ];
    $GLOBALS['nr_reltype_config'] = ['enabled' => true, 'eligible_types' => ['casual_intimacy']];
    $GLOBALS['nr_settings'] = ['NSFW_OPEN_MODE' => false, 'NSFW_SCENE_CALL_MIN_AFFINITY' => 56];
    $GLOBALS['nr_intimacy'] = [];
    $GLOBALS['nr_protected'] = [];
    $GLOBALS['nr_nearby'] = ['B', 'D', 'Playerborn'];
    $GLOBALS['nr_aliases'] = [];
    $GLOBALS['nr_catalog_ready'] = false;
    $GLOBALS['nr_mode_enabled_actions'] = [];
    $GLOBALS['nr_disabled_actions'] = [];
    $GLOBALS['nr_player_conflict'] = false;
    $GLOBALS['nr_player_scene_cooldown'] = false;
    $GLOBALS['nr_whiskey_dick'] = false;
    unset($GLOBALS['nr_reltype_read_error'], $GLOBALS['nr_reltype_raw'], $GLOBALS['AIAGENTNSFW_RELTYPE_POLICY'], $GLOBALS['AIAGENTNSFW_PLAYER_START_OFFER_SNAPSHOT']);
    $GLOBALS['ENABLED_FUNCTIONS'] = [];
    $GLOBALS['FUNC_LIST'] = [];
    $GLOBALS['FUNCTIONS'] = [];
    $GLOBALS['F_NAMES'] = array_flip($GLOBALS['nr_code_by_display']);
    $GLOBALS['actionsList'] = '';
    $GLOBALS['IS_NPC'] = false;
    foreach ($overrides as $key => $value) { $GLOBALS[$key] = $value; }
}

function nrRunPostfilter(string $action, array $overrides = []): array
{
    nrResetScenario($overrides);
    $GLOBALS['ENABLED_FUNCTIONS'] = $overrides['nr_enabled'] ?? [
        'ExtCmdStartSex', 'ExtCmdStartBlowJob', 'ExtCmdStartThreesome', 'ExtCmdAcceptSex', 'ExtCmdRefuseSex',
    ];
    $GLOBALS['AIAGENTNSFW_PLAYER_START_OFFER_SNAPSHOT'] = $overrides['nr_player_start_snapshot']
        ?? array_values(array_intersect(aiagentNsfwSexStartActionCodes(), $GLOBALS['ENABLED_FUNCTIONS']));
    nrSeedStartDefinitions($overrides['nr_target_enums'] ?? []);
    if (isset($overrides['nr_remove_start_definition'])) {
        $removeCode = $overrides['nr_remove_start_definition'];
        $GLOBALS['FUNCTIONS'] = array_values(array_filter($GLOBALS['FUNCTIONS'], static function ($definition) use ($removeCode) {
            return getFunctionCodeName($definition['name'] ?? '') !== $removeCode;
        }));
    }
    $GLOBALS['PATCH_ACTION_ALL_ACTORS'] = null;
    $hook = $GLOBALS['nr_postfilter'];
    return $hook([$action]);
}

function nrActionListAdapterFromEnabled(): void
{
    // Mirrors the pinned CHIM default AVAILABLE ACTION serializer format only;
    // policy decisions remain in the extracted SHARMAT production blocks.
    $displayNames = array_flip($GLOBALS['nr_code_by_display']);
    $lines = ["", "<available_actions_list>"];
    foreach ((array)($GLOBALS['ENABLED_FUNCTIONS'] ?? []) as $code) {
        $display = $displayNames[$code] ?? null;
        if ($display === null) { continue; }
        foreach ((array)($GLOBALS['FUNCTIONS'] ?? []) as $definition) {
            if (($definition['name'] ?? '') !== $display) { continue; }
            $lines[] = "AVAILABLE ACTION: {$display} (" . herikaGetPromptActionDescription($display, $definition['description'] ?? '') . ")";
            break;
        }
    }
    $lines[] = "</available_actions_list>";
    $GLOBALS['actionsList'] = implode("\n", $lines);
}

$common = nrReadSource('common.php');
$functions = nrReadSource('functions.php');
$contextPre = nrReadSource('context_pre.php');

$openMode = nrExtractFunction($common, 'aiagentNsfwOpenMode');
$slut = nrExtractFunction($common, 'aiagentNsfwIsSlutNpc');
$policyReader = nrExtractFunction($common, 'aiagentNsfwGetRelTypePolicy');
$playerGate = nrExtractFunction($common, 'aiagentNsfwRelTypeSexEligible');
$pairGate = nrExtractFunction($common, 'aiagentNsfwNpcToNpcSexEligible');
$startCodes = nrExtractFunction($common, 'aiagentNsfwSexStartActionCodes');
$targetOptions = nrExtractFunction($common, 'aiagentNsfwSexStartTargetOptions');
$actionAvailable = nrExtractFunction($common, 'aiagentNsfwSexStartActionAvailable');
$actionOffered = nrExtractFunction($common, 'aiagentNsfwSexStartActionOffered');
$canonicalTarget = nrExtractFunction($common, 'aiagentNsfwCanonicalSexStartTarget');
$targetNote = nrExtractFunction($common, 'aiagentNsfwSexStartTargetNote');
$canonicalTargets = nrExtractFunction($common, 'aiagentNsfwCanonicalSexStartTargets');
$addNpcStarts = nrExtractFunction($common, 'aiagentNsfwAddEligibleNpcSexStartActions');
$nsfwDataPathLiteral = var_export($repo . '/nsfw_data.php', true);
foreach ([$openMode, $slut, $policyReader, $playerGate, $pairGate, $startCodes, $targetOptions, $actionAvailable, $actionOffered, $canonicalTarget, $targetNote, $canonicalTargets, $addNpcStarts] as $functionSource) {
    $functionSource = str_replace('__DIR__ . "/nsfw_data.php"', $nsfwDataPathLiteral, $functionSource);
    eval($functionSource);
}

[$postfilterSource, $postfilterStartLine, $postfilterEndLine] = nrExtractAnchoredBlock(
    $functions,
    '$GLOBALS["action_post_process_fnct_ex"][]=function($actions) {',
    "\n\n// Get configurable sex cooldown",
    'actual action_post_process_fnct_ex closure'
);
$GLOBALS['action_post_process_fnct_ex'] = [];
eval($postfilterSource);
if (count($GLOBALS['action_post_process_fnct_ex']) !== 1) {
    nrFailSetup('Expected the exact production postfilter closure to register once');
}
$GLOBALS['nr_postfilter'] = $GLOBALS['action_post_process_fnct_ex'][0];

[$initialOfferBlock, $initialOfferStartLine, $initialOfferEndLine] = nrExtractAnchoredBlock(
    $functions,
    '// Add only the NPC target route after recording which starts the original per-action',
    '// RELATIONSHIP-TYPE SEX GATE',
    'production target-aware initial offer and catalog gate'
);
[$targetSchemaBlock, $targetSchemaStartLine, $targetSchemaEndLine] = nrExtractAnchoredBlock(
    $functions,
    '// PRESENT-ACTOR TARGET ENUM: sex starts use exact, route-specific recipients.',
    '// AcceptSex is REQUIRED again.',
    'production sexual-start and consent target schema gate'
);
[$contextGateBlock, $contextGateStartLine, $contextGateEndLine] = nrExtractAnchoredBlock(
    $contextPre,
    '// HARD CHILD/EXCLUSION TOOL STRIP.',
    '// TAKE_GOLD AMOUNT ROUTING',
    'production context_pre protection, relationship, combat, and final prompt reconciliation'
);

function nrRunProductionOfferAndContext(array $intimacyStatus, bool $sexDisposalEnabled = true): void
{
    global $initialOfferBlock, $contextGateBlock;
    $GLOBALS['nr_intimacy']['A'] = $intimacyStatus;
    eval($initialOfferBlock);
    nrSeedStartDefinitions();
    nrActionListAdapterFromEnabled();
    $GLOBALS['actionsList'] .= "\nAVAILABLE ACTION: Talk (continue conversation)";
    $actorName = $GLOBALS['HERIKA_NAME'];
    $GLOBALS['responseTemplate'] = ['action' => implode('|', $GLOBALS['FUNC_LIST'])];
    $GLOBALS['structuredOutputTemplate'] = ['json_schema' => ['schema' => ['properties' => ['action' => ['enum' => $GLOBALS['FUNC_LIST']]]]]];
    eval($contextGateBlock);
}

nrResetScenario();

nrExpect(aiagentNsfwNpcToNpcSexEligible('A', 'B'), true, 'production A-to-B pair gate accepts configured custom NPC edge');
nrExpect(aiagentNsfwRelTypeSexEligible('A'), false, 'production Player gate rejects A-to-Player platonic edge');

// Exercise the production config reader and both eligibility callers together. The fake DB only
// supplies the deterministic result of the real scalar-subquery read; the policy and gate logic
// below are extracted directly from common.php.
$policyCallerCases = [
    [
        'label' => 'blank legacy setting uses built-in romantic defaults',
        'overrides' => [
            'nr_reltype_raw' => '',
            'nr_relationships' => ['A' => [
                'Player' => ['aff' => 72, 'type' => 'romantic'],
                'B' => ['aff' => 72, 'type' => 'romantic'],
            ]],
        ],
        'status' => 'missing', 'valid' => true, 'enabled' => true,
        'types' => ['romantic', 'crush', 'ex'], 'player' => true, 'npc' => true,
    ],
    [
        'label' => 'explicit empty eligible list denies Player and NPC routes',
        'overrides' => [
            'nr_reltype_raw' => '{"enabled":true,"eligible_types":[]}',
            'nr_relationships' => ['A' => [
                'Player' => ['aff' => 72, 'type' => 'romantic'],
                'B' => ['aff' => 72, 'type' => 'romantic'],
            ]],
        ],
        'status' => 'present', 'valid' => true, 'enabled' => true,
        'types' => [], 'player' => false, 'npc' => false,
    ],
    [
        'label' => 'malformed config fails closed in both callers',
        'overrides' => [
            'nr_reltype_raw' => '{malformed',
            'nr_relationships' => ['A' => [
                'Player' => ['aff' => 72, 'type' => 'romantic'],
                'B' => ['aff' => 72, 'type' => 'romantic'],
            ]],
        ],
        'status' => 'malformed', 'valid' => false, 'enabled' => true,
        'types' => [], 'player' => false, 'npc' => false,
    ],
    [
        'label' => 'DB read error fails closed in both callers',
        'overrides' => [
            'nr_reltype_read_error' => true,
            'nr_relationships' => ['A' => [
                'Player' => ['aff' => 72, 'type' => 'romantic'],
                'B' => ['aff' => 72, 'type' => 'romantic'],
            ]],
        ],
        'status' => 'error', 'valid' => false, 'enabled' => true,
        'types' => [], 'player' => false, 'npc' => false,
    ],
    [
        'label' => 'explicit disabled policy preserves the existing gate-off route',
        'overrides' => [
            'nr_reltype_raw' => '{"enabled":false,"eligible_types":[]}',
            'nr_relationships' => ['A' => [
                'Player' => ['aff' => 72, 'type' => 'platonic'],
                'B' => ['aff' => 72, 'type' => 'casual_intimacy'],
            ]],
        ],
        'status' => 'present', 'valid' => true, 'enabled' => false,
        'types' => [], 'player' => true, 'npc' => true,
    ],
];
foreach ($policyCallerCases as $policyCase) {
    nrResetScenario($policyCase['overrides']);
    $policy = aiagentNsfwGetRelTypePolicy();
    nrExpect(($policy['status'] ?? null) === $policyCase['status'], true,
        'production reader classifies ' . $policyCase['label']);
    nrExpect(($policy['valid'] ?? null) === $policyCase['valid']
        && ($policy['enabled'] ?? null) === $policyCase['enabled']
        && ($policy['eligible_types'] ?? null) === $policyCase['types'], true,
        'production reader normalizes ' . $policyCase['label']);
    nrExpect(aiagentNsfwRelTypeSexEligible('A') === $policyCase['player'], true,
        'production Player caller applies ' . $policyCase['label']);
    nrExpect(aiagentNsfwNpcToNpcSexEligible('A', 'B') === $policyCase['npc'], true,
        'production NPC-pair caller applies ' . $policyCase['label']);
}

// AcceptSex is a separate Player decision control; it must not inherit the empty start-action snapshot.
nrResetScenario();
$GLOBALS['ENABLED_FUNCTIONS'] = ['ExtCmdAcceptSex', 'ExtCmdRefuseSex'];
$GLOBALS['AIAGENTNSFW_PLAYER_START_OFFER_SNAPSHOT'] = [];
nrSeedStartDefinitions();
$intimacyStatus = ['level' => 0, 'scene_actors' => ['A', 'Player'], 'intensity_tier' => 3, 'scene_phase' => 'tier_prompt'];
eval($targetSchemaBlock);
nrExpect(nrEnumFor('AcceptSex') === ['Player'], true,
    'real pending-consent schema keeps Player on AcceptSex when no sexual-start action is in the pre-offer snapshot');

// Run the actual new-offer snapshot and context reconciliation with an eligible NPC edge and platonic Player edge.
nrResetScenario();
$GLOBALS['ENABLED_FUNCTIONS'] = [
    'ExtCmdStartSex', 'ExtCmdStartBlowJob', 'ExtCmdStartThreesome', 'ExtCmdAcceptSex', 'ExtCmdRefuseSex', 'ExtCmdKiss',
];
$intimacyStatus = ['level' => 0, 'scene_actors' => ['A'], 'sex_disposal' => 25];
nrRunProductionOfferAndContext($intimacyStatus);
$makeLoveTargets = nrEnumFor('MakeLove');
nrExpect($makeLoveTargets === ['B'], true,
    'production target enum offers only exact present eligible NPCs when Player is ineligible',
    'actual enum=' . json_encode($makeLoveTargets, JSON_UNESCAPED_SLASHES));

nrExpect(in_array('ExtCmdStartSex', $GLOBALS['ENABLED_FUNCTIONS'], true), true,
    'context_pre restores StartSex only for an eligible present NPC recipient');
nrExpect(in_array('ExtCmdAcceptSex', $GLOBALS['ENABLED_FUNCTIONS'], true), false,
    'context_pre does not restore Player-only AcceptSex for NPC eligibility');
nrExpect(in_array('ExtCmdKiss', $GLOBALS['ENABLED_FUNCTIONS'], true), true,
    'NPC-target eligibility leaves the existing affection action available');
nrExpect(in_array('ExtCmdStartThreesome', $GLOBALS['ENABLED_FUNCTIONS'], true), false,
    'context_pre omits one-NPC threesome when native Papyrus implicitly adds the ineligible Player');
nrExpect(strpos(aiagentNsfwSexStartTargetNote('ExtCmdStartThreesome', ['B']), 'one NPC is invalid') !== false, true,
    'one-NPC/no-Player group guidance explicitly requires at least two NPC names');
nrExpect(in_array('MakeLove', $GLOBALS['FUNC_LIST'], true), true,
    'display-name offer list matches the target-aware enabled code list');
nrExpect(in_array('AcceptSex', $GLOBALS['FUNC_LIST'], true), false,
    'display-name offer list excludes Player-only AcceptSex in the NPC-only route');
nrExpect(strpos((string)$GLOBALS['actionsList'], 'AVAILABLE ACTION: MakeLove (') !== false, true,
    'captured CHIM action-list text adds the NPC-only start tool');
nrExpect(strpos((string)$GLOBALS['actionsList'], 'AVAILABLE ACTION: AcceptSex (') === false, true,
    'captured CHIM action-list text keeps Player-only AcceptSex absent');
nrExpect(strpos((string)$GLOBALS['actionsList'], 'AVAILABLE ACTION: Talk (continue conversation)') !== false, true,
    'captured action-list reconciliation preserves an unrelated action line');
nrExpect(strpos((string)$GLOBALS['actionsList'], 'AVAILABLE ACTION: Kiss (') !== false, true,
    'captured action-list reconciliation preserves an offered affection action');
$intimacyStatus = ['level' => 0, 'scene_actors' => ['A'], 'sex_disposal' => 25];
eval($targetSchemaBlock);
nrExpect(nrEnumFor('AcceptSex') === [], true,
    'real consent schema does not restore AcceptSex after the NPC-only context gate removed it');

// Two eligible NPCs restore the documented CSV route from an empty Player offer through real context and dispatch hooks.
nrResetScenario(['nr_nearby' => ['B', 'C'], 'nr_relationships' => ['A' => [
    'Player' => ['aff' => 0, 'type' => 'platonic'],
    'B' => ['aff' => 72, 'type' => 'casual_intimacy'],
    'C' => ['aff' => 72, 'type' => 'casual_intimacy'],
]]]);
$GLOBALS['ENABLED_FUNCTIONS'] = [];
$intimacyStatus = ['level' => 0, 'scene_actors' => ['A'], 'sex_disposal' => 25];
nrRunProductionOfferAndContext($intimacyStatus);
$threesomeDefinition = null;
foreach ($GLOBALS['FUNCTIONS'] as $definition) {
    if (($definition['name'] ?? '') === 'StartThreesome') { $threesomeDefinition = $definition; break; }
}
$threesomeTarget = $threesomeDefinition['parameters']['properties']['target'] ?? [];
nrExpect(in_array('ExtCmdStartThreesome', $GLOBALS['ENABLED_FUNCTIONS'], true), true,
    'context hook retains Threesome when two exact NPC recipients are eligible without Player');
nrExpect(($threesomeTarget['type'] ?? null) === 'string' && !array_key_exists('enum', $threesomeTarget), true,
    'Threesome schema remains a plain string so existing CSV groups are representable');
nrExpect(strpos((string)($threesomeTarget['description'] ?? ''), 'Allowed group members for this action: [B, C].') !== false, true,
    'Threesome schema description exposes only the current exact eligible recipients');
nrExpect(strpos((string)$GLOBALS['actionsList'], 'Allowed group members for this action: [B, C].') !== false, true,
    'captured action-list prose matches the current Threesome recipient schema');
$npcGroupDispatch = $GLOBALS['nr_postfilter'](['A|StartThreesome|ExtCmdStartThreesome@B,C']);
nrExpect(nrActionPresent($npcGroupDispatch, 'ExtCmdStartThreesome', 'B,C'), true,
    'actual empty-Player offer, full context, and final callback preserve eligible two-NPC group dispatch');

// The NPC-only add route cannot give Player back an action the original offer builder withheld below its floor.
nrResetScenario([
    'nr_nearby' => ['B'],
    'nr_relationships' => ['A' => [
        'Player' => ['aff' => 40, 'type' => 'casual_intimacy'],
        'B' => ['aff' => 72, 'type' => 'casual_intimacy'],
    ]],
]);
$GLOBALS['ENABLED_FUNCTIONS'] = ['ExtCmdStartMassage'];
$intimacyStatus = ['level' => 0, 'scene_actors' => ['A'], 'sex_disposal' => 25];
nrRunProductionOfferAndContext($intimacyStatus);
$belowFloorPlayer = $GLOBALS['nr_postfilter'](['A|MakeLove|ExtCmdStartSex@Player']);
$belowFloorNpc = $GLOBALS['nr_postfilter'](['A|MakeLove|ExtCmdStartSex@B']);
nrExpect(nrActionPresent($belowFloorPlayer, 'ExtCmdStartSex'), false,
    'NPC restoration cannot add Player to StartSex below the original scene-call floor');
nrExpect(nrActionPresent($belowFloorNpc, 'ExtCmdStartSex', 'B'), true,
    'the same below-floor request still permits its eligible NPC target');

// Exercise the exact postfilter callback, not a parallel eligibility implementation.
$cases = [
    ['eligible NPC control', 'A|MakeLove|ExtCmdStartSex@B', true, 'B', []],
    ['ineligible Player target', 'A|MakeLove|ExtCmdStartSex@Player', false, null, []],
    ['mixed eligible NPC and ineligible Player group', 'A|StartThreesome|ExtCmdStartThreesome@B,Player', false, null, []],
    ['eligible two-NPC Threesome group', 'A|StartThreesome|ExtCmdStartThreesome@B,C', true, 'B,C', ['nr_nearby' => ['B', 'C']]],
    ['eligible but out-of-roster target', 'A|MakeLove|ExtCmdStartSex@C', false, null, ['nr_nearby' => ['B']]],
    ['spoofed action speaker', 'Somebody|MakeLove|ExtCmdStartSex@B', false, null, []],
    ['action absent from enabled list', 'A|MakeLove|ExtCmdStartSex@B', false, null, ['nr_enabled' => ['ExtCmdRefuseSex']]],
    ['disabled catalog action cannot be revived from enabled functions', 'A|MakeLove|ExtCmdStartSex@B', false, null, [
        'nr_enabled' => ['ExtCmdStartSex'], 'nr_catalog_ready' => true, 'nr_mode_enabled_actions' => [],
    ]],
    ['missing offered target definition fails closed', 'A|MakeLove|ExtCmdStartSex@B', false, null, [
        'nr_enabled' => ['ExtCmdStartSex'], 'nr_remove_start_definition' => 'ExtCmdStartSex',
    ]],
    ['active refusal for this exact NPC pair', 'A|MakeLove|ExtCmdStartSex@B', false, null, ['nr_intimacy' => [
        'B' => ['is_npc_scene' => true, 'scene_actors' => ['A', 'B'], 'scene_phase' => 'rejected', 'refusal_expressed' => true, 'refused_until_scene_end' => true],
    ]]],
    ['unrelated Player refusal does not block A-to-B', 'A|MakeLove|ExtCmdStartSex@B', true, 'B', ['nr_intimacy' => [
        'B' => ['is_npc_scene' => false, 'scene_actors' => ['Player', 'B'], 'scene_phase' => 'rejected', 'refusal_expressed' => true, 'refused_until_scene_end' => true],
    ]]],
    ['protected target control', 'A|MakeLove|ExtCmdStartSex@B', false, null, ['nr_protected' => ['B']]],
    ['ineligible NPC relationship control', 'A|MakeLove|ExtCmdStartSex@D', false, null, []],
    ['Player-name substring collision resolves to Player natively', 'A|MakeLove|ExtCmdStartSex@Playerborn', false, null, []],
    ['single-target sex CSV is rejected rather than truncated', 'A|MakeLove|ExtCmdStartSex@B,Player', false, null, []],
    ['NPC-only one-partner threesome rejected because Player is implicit', 'A|StartThreesome|ExtCmdStartThreesome@B', false, null, []],
    ['eligible one-NPC Threesome may use implicit Player', 'A|StartThreesome|ExtCmdStartThreesome@B', true, 'B', ['nr_relationships' => [
        'A' => ['Player' => ['aff' => 72, 'type' => 'casual_intimacy'], 'B' => ['aff' => 72, 'type' => 'casual_intimacy']],
    ]]],
    ['self target rejected', 'A|MakeLove|ExtCmdStartSex@A', false, null, []],
    ['duplicate group target rejected', 'A|StartThreesome|ExtCmdStartThreesome@B,B', false, null, ['nr_nearby' => ['B', 'C']]],
    ['self member rejected in group', 'A|StartThreesome|ExtCmdStartThreesome@A,B', false, null, ['nr_nearby' => ['B', 'C']]],
    ['over-cap group rejected instead of native truncation', 'A|StartThreesome|ExtCmdStartThreesome@B,C,D,E,F', false, null, ['nr_nearby' => ['B', 'C', 'D', 'E', 'F']]],
    ['unknown alias rejected', 'A|StartThreesome|ExtCmdStartThreesome@B,Nearby Stranger', false, null, ['nr_nearby' => ['B', 'C']]],
    ['RefID decoration rejected', 'A|StartThreesome|ExtCmdStartThreesome@B (RefID: 0x123)', false, null, ['nr_nearby' => ['B', 'C']]],
    ['eligible Player target control', 'A|MakeLove|ExtCmdStartSex@Player', true, 'Player', ['nr_relationships' => [
        'A' => [
            'Player' => ['aff' => 72, 'type' => 'casual_intimacy'],
            'B' => ['aff' => 72, 'type' => 'casual_intimacy'],
        ],
    ]]],
    ['scalar native Player alias canonicalizes to non-default display name', 'A|MakeLove|ExtCmdStartSex@Player', true, 'TestHero', [
        'nr_player_name' => 'TestHero', 'nr_relationships' => ['A' => ['Player' => ['aff' => 72, 'type' => 'casual_intimacy']]],
    ]],
    ['lowercase scalar native Player alias canonicalizes to non-default display name', 'A|MakeLove|ExtCmdStartSex@player', true, 'TestHero', [
        'nr_player_name' => 'TestHero', 'nr_relationships' => ['A' => ['Player' => ['aff' => 72, 'type' => 'casual_intimacy']]],
    ]],
    ['scalar native Player alias rejected without Player route', 'A|MakeLove|ExtCmdStartSex@Player', false, null, [
        'nr_player_name' => 'TestHero', 'nr_relationships' => ['A' => ['Player' => ['aff' => 72, 'type' => 'platonic']]],
    ]],
    ['accepted active Player funcret preserves existing StartSex', 'A|MakeLove|ExtCmdStartSex@Player', true, 'Player', [
        'nr_intimacy' => ['A' => ['level' => 2, 'accepted_sex' => true, 'scene_actors' => ['A', 'Player'], 'scene_phase' => 'active']],
    ]],
    ['Player scene cooldown blocks Player start after offer snapshot', 'A|MakeLove|ExtCmdStartSex@Player', false, null, [
        'nr_player_scene_cooldown' => true, 'nr_relationships' => ['A' => ['Player' => ['aff' => 72, 'type' => 'casual_intimacy']]],
    ]],
    ['Player scene cooldown leaves eligible NPC start available', 'A|MakeLove|ExtCmdStartSex@B', true, 'B', [
        'nr_player_scene_cooldown' => true, 'nr_relationships' => ['A' => [
            'Player' => ['aff' => 72, 'type' => 'casual_intimacy'], 'B' => ['aff' => 72, 'type' => 'casual_intimacy'],
        ]],
    ]],
    ['Whiskey Dick blocks Player start after offer snapshot', 'A|MakeLove|ExtCmdStartSex@Player', false, null, [
        'nr_whiskey_dick' => true, 'nr_relationships' => ['A' => ['Player' => ['aff' => 72, 'type' => 'casual_intimacy']]],
    ]],
    ['missing Player offer snapshot fails closed but keeps eligible NPC route', 'A|MakeLove|ExtCmdStartSex@Player', false, null, [
        'nr_player_start_snapshot' => [], 'nr_relationships' => ['A' => ['Player' => ['aff' => 72, 'type' => 'casual_intimacy']]],
    ]],
    ['eligible explicit Player group target with non-default player display', 'A|StartThreesome|ExtCmdStartThreesome@Player,B', true, 'TestHero,B', [
        'nr_player_name' => 'TestHero', 'nr_nearby' => ['B', 'C'], 'nr_relationships' => ['A' => [
            'Player' => ['aff' => 72, 'type' => 'casual_intimacy'], 'B' => ['aff' => 72, 'type' => 'casual_intimacy'], 'C' => ['aff' => 72, 'type' => 'casual_intimacy'],
        ]],
    ]],
    ['lowercase native Player alias canonicalizes to display name', 'A|StartThreesome|ExtCmdStartThreesome@player', true, 'TestHero', [
        'nr_player_name' => 'TestHero', 'nr_relationships' => ['A' => ['Player' => ['aff' => 72, 'type' => 'casual_intimacy']]],
    ]],
    ['Player alias rejected without Player route', 'A|StartThreesome|ExtCmdStartThreesome@Player,B', false, null, ['nr_nearby' => ['B', 'C']]],
    ['Player alias plus canonical display duplicate rejected', 'A|StartThreesome|ExtCmdStartThreesome@Player,TestHero', false, null, [
        'nr_player_name' => 'TestHero', 'nr_relationships' => ['A' => ['Player' => ['aff' => 72, 'type' => 'casual_intimacy']]],
    ]],
    ['Player-only Threesome preserves eligible Player route', 'A|StartThreesome|ExtCmdStartThreesome@Player', true, 'TestHero', [
        'nr_player_name' => 'TestHero', 'nr_relationships' => ['A' => ['Player' => ['aff' => 72, 'type' => 'casual_intimacy']]],
    ]],
    ['Threesome Player permission uses its own catalog offer snapshot', 'A|StartThreesome|ExtCmdStartThreesome@Player', true, 'TestHero', [
        'nr_player_name' => 'TestHero', 'nr_catalog_ready' => true,
        'nr_mode_enabled_actions' => ['ExtCmdStartThreesome'], 'nr_enabled' => ['ExtCmdStartThreesome'],
        'nr_relationships' => ['A' => ['Player' => ['aff' => 72, 'type' => 'casual_intimacy']]],
    ]],
    ['group schema enum narrowing blocks the canonical group', 'A|StartThreesome|ExtCmdStartThreesome@B,C', false, null, [
        'nr_nearby' => ['B', 'C'], 'nr_target_enums' => ['ExtCmdStartThreesome' => ['B']],
    ]],
];
foreach ($cases as [$label, $action, $expected, $expectedTarget, $overrides]) {
    $out = nrRunPostfilter($action, $overrides);
    $payload = explode('@', (string)(explode('|', $action)[2] ?? ''), 2);
    $command = $payload[0] ?? '';
    $actual = $expected
        ? nrActionPresent($out, $command, $expectedTarget)
        : !nrActionPresent($out, $command, null);
    nrExpect($actual, true, 'postfilter: ' . $label, 'output=' . json_encode($out, JSON_UNESCAPED_SLASHES));
}

echo json_encode([
    'result' => empty($failures) ? 'PASS' : 'RED',
    'source_sha256' => array_map('strtoupper', $sourceEvidence),
    'production_source_lines' => [
        'initial_offer_snapshot' => [$initialOfferStartLine, $initialOfferEndLine],
        'target_schema' => [$targetSchemaStartLine, $targetSchemaEndLine],
        'context_pre_gate' => [$contextGateStartLine, $contextGateEndLine],
        'postfilter' => [$postfilterStartLine, $postfilterEndLine],
    ],
    'checks' => $checks,
    'failure_count' => count($failures),
    'failures' => $failures,
], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
exit(empty($failures) ? 0 : 1);
