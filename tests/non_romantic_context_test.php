<?php
declare(strict_types=1);

/*
 * Focused prompt-context fixture. It evaluates the actual NsfwRelationship
 * class after removing its unavailable external includes, then supplies
 * deterministic relationship, NSFW-data, prompt, and policy collaborators.
 * It does not bootstrap CHIM or prove model compliance.
 */

$repo = dirname(__DIR__);
$sourcePath = $repo . '/nsfw_relationship.php';
$source = file_get_contents($sourcePath);
if (!is_string($source)) {
    fwrite(STDERR, "FIXTURE ERROR: cannot read nsfw_relationship.php\n");
    exit(2);
}
$sourceHash = hash('sha256', $source);
$productionSource = $source;
$chimInclude = 'require_once __DIR__ . "/../../lib/relationship_manager.php";';
if (substr_count($source, $chimInclude) !== 1) {
    fwrite(STDERR, "FIXTURE ERROR: expected exactly one CHIM RelationshipManager include\n");
    exit(2);
}
$source = str_replace($chimInclude, '', $source);
$nsfwDataInclude = 'require_once __DIR__ . "/nsfw_data.php";';
$source = str_replace($nsfwDataInclude, '', $source);
$source = str_replace('require_once __DIR__ . "/../../lib/core/npc_master.class.php";', '', $source);
$source = str_replace('require_once __DIR__ . "/common.php";', '', $source);
$source = preg_replace('/\A<\?php\s*/', '', $source, 1, $openTagCount);
if ($openTagCount !== 1) {
    fwrite(STDERR, "FIXTURE ERROR: cannot isolate production PHP class\n");
    exit(2);
}

require_once $repo . '/open_mode_policy.php';

class NonRomanticContextTestDb
{
    public function fetchOne(string $query)
    {
        if (strpos($query, 'aiagent_nsfw_prompts') !== false) {
            return ['value' => json_encode([
                'tier_bonded' => 'You are in love with #PLAYER_NAME#; your bond is romantic and exclusive.',
            ], JSON_THROW_ON_ERROR)];
        }
        if (strpos($query, 'aiagent_nsfw_reltypes') !== false) {
            return ['value' => json_encode([
                'enabled' => true,
                'eligible_types' => [],
                'prompt_bonded' => 'Raw database refusal for #NPC_NAME# and #PLAYER_NAME#.',
            ], JSON_THROW_ON_ERROR)];
        }
        return [];
    }
}

class RelationshipManager
{
    public static $relationship = ['aff' => 96, 'tier' => 'bonded', 'type' => 'casual_intimacy'];
    public static $relationships = [];

    public static function getPlayerRelationship($npcName): array
    {
        return self::$relationship;
    }

    public static function getRelationship($npcName, $partnerName): array
    {
        return self::$relationships[$npcName][$partnerName] ?? self::$relationship;
    }

    public static function getTierLabel($affinity): string
    {
        return ((int)$affinity >= 91) ? 'Bonded' : 'Friendly';
    }
}

class NsfwNpcData
{
    public static function get($npcName): array
    {
        return [];
    }
}

function aiagentNsfwGetRelTypePolicy(): array
{
    return $GLOBALS['non_romantic_context_policy'];
}

$GLOBALS['db'] = new NonRomanticContextTestDb();
$GLOBALS['PLAYER_NAME'] = 'Player';
$GLOBALS['non_romantic_context_policy'] = [
    'status' => 'present',
    'valid' => true,
    'enabled' => false,
    'eligible_types' => [],
    'non_romantic_types' => ['casual_intimacy'],
    'prompts' => ['prompt_bonded' => 'Shared reader refusal for #NPC_NAME# and #PLAYER_NAME#.'],
];

eval($source);

$checks = [];
$failures = [];
$expect = static function (bool $condition, string $label, string $detail = '') use (&$checks, &$failures): void {
    $checks[] = ['label' => $label, 'pass' => $condition, 'detail' => $detail];
    if (!$condition) { $failures[] = $label; }
};

NsfwRelationship::clearCache();
$markedPrompt = NsfwRelationship::getTierPrompt('Mira');
$boundary = 'Treat it as controlling over affinity- or tier-based language for this pair.';
$boundaryPosition = strpos($markedPrompt, $boundary);
$romancePosition = strpos($markedPrompt, 'your bond is romantic and exclusive');
$expect(
    $boundaryPosition !== false
        && $romancePosition !== false
        && $boundaryPosition > $romancePosition
        && strpos($markedPrompt, 'directed relationship from Mira toward Player') !== false
        && strpos($markedPrompt, 'Apply this designation only to this pair, not other scene participants.') !== false
        && strpos($markedPrompt, 'Do not infer romance, exclusivity, reciprocal feelings, or agreement') !== false,
    'explicit non-romantic directed type adds a later boundary even with the eligibility gate disabled',
    'prompt_sha256=' . hash('sha256', $markedPrompt)
);

RelationshipManager::$relationship['type'] = 'platonic';
NsfwRelationship::clearCache();
$unmarkedPrompt = NsfwRelationship::getTierPrompt('Mira');
$expect(
    strpos($unmarkedPrompt, $boundary) === false,
    'an undesignated type receives no non-romantic annotation'
);

RelationshipManager::$relationship['type'] = 'casual_intimacy';
$GLOBALS['non_romantic_context_policy']['valid'] = false;
NsfwRelationship::clearCache();
$invalidPolicyPrompt = NsfwRelationship::getTierPrompt('Mira');
$expect(
    strpos($invalidPolicyPrompt, $boundary) === false,
    'invalid policy cannot invent a non-romantic designation'
);

$GLOBALS['non_romantic_context_policy']['valid'] = true;
$GLOBALS['non_romantic_context_policy']['enabled'] = false;
NsfwRelationship::clearCache();
$npcPairPrompt = NsfwRelationship::getTierPromptByAffinity(96, false, 'Rook', 'Mira', 'npc');
$expect(
    strpos($npcPairPrompt, $boundary) !== false
        && strpos($npcPairPrompt, 'directed relationship from Mira toward Rook') !== false,
    'NPC-to-NPC tier prompt uses the current directed pair designation even when the gate is disabled'
);

RelationshipManager::$relationships = [
    'Mira' => [
        'B' => ['aff' => 80, 'tier' => 'fond', 'type' => 'casual_intimacy'],
        'C' => ['aff' => 96, 'tier' => 'bonded', 'type' => 'romantic'],
    ],
];
NsfwRelationship::clearCache();
$mixedGroup = NsfwRelationship::buildMultiActorContext('Mira', ['Mira', 'B', 'C'], false, 'npc');
$expect(
    strpos($mixedGroup['tierPrompt'], 'directed relationship from Mira toward B') !== false
        && strpos($mixedGroup['tierPrompt'], 'Apply this designation only to this pair, not other scene participants.') !== false
        && strpos($mixedGroup['tierPrompt'], 'from Mira toward C') === false
        && strpos($mixedGroup['context'], 'Partner 2 (C): bonded') !== false,
    'mixed group prompt scopes B non-romantic designation away from romantic C',
    $mixedGroup['tierPrompt']
);
RelationshipManager::$relationships = [];

$GLOBALS['non_romantic_context_policy'] = [
    'status' => 'present',
    'valid' => true,
    'enabled' => true,
    'eligible_types' => [],
    'non_romantic_types' => ['casual_intimacy'],
    'prompts' => ['prompt_bonded' => 'Shared reader refusal for #NPC_NAME# and #PLAYER_NAME#.'],
];
$refusal = NsfwRelationship::getRelTypeGateRefusal('Mira', 'Player');
$expect(
    $refusal === 'Shared reader refusal for Mira and Player.',
    'refusal consumer preserves saved wording from the shared policy reader',
    is_string($refusal) ? $refusal : 'null'
);

$GLOBALS['non_romantic_context_policy']['enabled'] = false;
$gateOffRefusal = NsfwRelationship::getRelTypeGateRefusal('Mira', 'Player');
$expect(
    $gateOffRefusal === null,
    'explicitly disabled relationship-type gate still bypasses refusal'
);

$relSourceStart = strpos($productionSource, 'public static function getRelationshipOverhead(');
$relSourceEnd = strpos($productionSource, 'private static function replaceRoleOverheadPlaceholders(', $relSourceStart);
$refusalSourceStart = strpos($productionSource, 'public static function getRelTypeGateRefusal(');
$refusalSourceEnd = strpos($productionSource, 'public static function buildMultiActorContext(', $refusalSourceStart);
$currentRelSource = $productionSource;
$overheadSource = ($relSourceStart !== false && $relSourceEnd !== false)
    ? substr($currentRelSource, $relSourceStart, $relSourceEnd - $relSourceStart) : '';
$refusalSource = ($refusalSourceStart !== false && $refusalSourceEnd !== false)
    ? substr($currentRelSource, $refusalSourceStart, $refusalSourceEnd - $refusalSourceStart) : '';
$expect(
    strpos($overheadSource, 'aiagentNsfwGetRelTypePolicy()') !== false
        && strpos($overheadSource, "fetchOne(\"SELECT value FROM conf_opts WHERE id = 'aiagent_nsfw_reltypes'\")") === false,
    'relationship overhead consumes the shared policy without parsing raw config'
);
$expect(
    strpos($refusalSource, 'aiagentNsfwGetRelTypePolicy()') !== false
        && strpos($refusalSource, "fetchOne(\"SELECT value FROM conf_opts WHERE id = 'aiagent_nsfw_reltypes'\")") === false,
    'relationship refusal consumes the shared policy without parsing raw config'
);

$inviteSource = file_get_contents($repo . '/nsfw_ostim_handler.php');
$expect(
    is_string($inviteSource)
        && strpos($inviteSource, 'with romantic intent') === false
        && strpos($inviteSource, 'only an invitation') !== false
        && strpos($inviteSource, 'does not establish acceptance') !== false,
    'NPC invite narrative describes an invitation without asserting romantic intent or acceptance'
);

echo "production_source_sha256={$sourceHash}\n";
echo "checks=" . count($checks) . " failures=" . count($failures) . "\n";
foreach ($checks as $check) {
    echo ($check['pass'] ? 'PASS' : 'FAIL') . ' ' . $check['label'];
    if ($check['detail'] !== '') { echo ' ' . $check['detail']; }
    echo "\n";
}
if ($failures !== []) {
    exit(1);
}
