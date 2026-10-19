<?php
declare(strict_types=1);

/*
 * Focused automatic-crush fixture. It extracts the production policy reader,
 * crush helper, and registered Kiss/AcceptSex closures, then provides only
 * deterministic in-memory collaborators. It does not bootstrap CHIM or prove
 * database persistence or gameplay behavior.
 */

function crushFailSetup(string $message): never
{
    fwrite(STDERR, "FIXTURE ERROR: {$message}\n");
    exit(2);
}

function crushReadSource(string $relative): string
{
    $path = dirname(__DIR__) . DIRECTORY_SEPARATOR . $relative;
    $source = file_get_contents($path);
    if (!is_string($source)) { crushFailSetup("Cannot read production source {$relative}"); }
    return str_replace("\r\n", "\n", $source);
}

function crushExtractCallable(string $source, string $label, ?string $name = null): string
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
        if ($name !== null) {
            $nameIndex = $i + 1;
            while (isset($tokens[$nameIndex]) && is_array($tokens[$nameIndex])
                && in_array($tokens[$nameIndex][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                $nameIndex++;
            }
            if (!isset($tokens[$nameIndex]) || !is_array($tokens[$nameIndex])
                || $tokens[$nameIndex][0] !== T_STRING || $tokens[$nameIndex][1] !== $name) {
                continue;
            }
        }

        $openIndex = $i + 1;
        while (isset($tokens[$openIndex]) && $tokens[$openIndex] !== '{') { $openIndex++; }
        if (!isset($tokens[$openIndex])) { crushFailSetup("Missing body for {$label}"); }

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
        if ($end === null) { crushFailSetup("Unbalanced body for {$label}"); }
        $matches[] = substr($source, $offsets[$i], $end - $offsets[$i]);
    }

    if (count($matches) !== 1) {
        crushFailSetup("Expected one callable for {$label}; found " . count($matches));
    }
    return $matches[0];
}

function crushExtractRegisteredClosure(string $source, string $code): string
{
    $anchor = '$GLOBALS["FUNCSERV"]["' . $code . '"]=function()';
    if (substr_count($source, $anchor) !== 1) {
        crushFailSetup("Expected one registered closure assignment for {$code}");
    }
    $anchorOffset = strpos($source, $anchor);
    $functionOffset = strpos($source, 'function()', $anchorOffset);
    if ($anchorOffset === false || $functionOffset === false) {
        crushFailSetup("Cannot locate registered closure for {$code}");
    }

    $tokens = token_get_all($source);
    $offset = 0;
    $functionIndex = null;
    $offsets = [];
    foreach ($tokens as $i => $token) {
        $offsets[$i] = $offset;
        if ($functionIndex === null && is_array($token) && $token[0] === T_FUNCTION && $offset >= $functionOffset) {
            $functionIndex = $i;
        }
        $offset += strlen(is_array($token) ? $token[1] : $token);
    }
    if ($functionIndex === null) { crushFailSetup("No function token found for {$code}"); }

    $tokensSource = $source;
    $body = crushExtractCallableFromTokenIndex($tokens, $offsets, $functionIndex, $tokensSource, $code);
    return $body;
}

function crushExtractCallableFromTokenIndex(array $tokens, array $offsets, int $functionIndex, string $source, string $label): string
{
    $openIndex = $functionIndex + 1;
    while (isset($tokens[$openIndex]) && $tokens[$openIndex] !== '{') { $openIndex++; }
    if (!isset($tokens[$openIndex])) { crushFailSetup("Missing closure body for {$label}"); }

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
    if ($end === null) { crushFailSetup("Unbalanced closure body for {$label}"); }
    return substr($source, $offsets[$functionIndex], $end - $offsets[$functionIndex]);
}

final class CrushTestDatabase
{
    public string $config = '';
    public array $queries = [];

    public function fetchOne(string $query): array
    {
        $this->queries[] = $query;
        if ($query !== "SELECT COALESCE((SELECT value::text FROM conf_opts WHERE id = 'aiagent_nsfw_reltypes'), '') AS value") {
            crushFailSetup('Production policy reader issued an unexpected query');
        }
        return ['value' => $this->config];
    }
}

class RelationshipManager
{
    public static array $actors = [];
    public static array $setCalls = [];

    public static function resolveNpcByName(string $actorName): ?array
    {
        if (!isset(self::$actors[$actorName])) { return null; }
        return ['extended_data' => json_encode(self::$actors[$actorName], JSON_THROW_ON_ERROR)];
    }

    public static function normalizeRelationshipMap($relationships): array
    {
        return is_array($relationships) ? $relationships : [];
    }

    public static function setRelationship(string $actorName, string $targetName, int $affinity, string $type): void
    {
        self::$setCalls[] = [$actorName, $targetName, $affinity, $type];
        self::$actors[$actorName]['relationships'][$targetName] = ['aff' => $affinity, 'type' => $type];
    }
}

class NsfwNpcData
{
    public static function get(string $actorName): array { return []; }
}

function _getNsfwSetting($key, $default = null) { return $default; }
function nsfwIsNarratorName($name): bool { return strcasecmp((string)$name, 'The Narrator') === 0; }
function aiagentNsfwOrientationAllowsPlayer($actorName): bool { return true; }
function aiagentNsfwStampAffection($actorName): void {}
function getIntimacyForActor($actorName): array { return []; }
function isSexDisposalEnabled(): bool { return false; }
function aiagentNsfwArousalNum($key, $default = 0): int { return (int)$default; }
function updateIntimacyForActor($actorName, $status): void { $GLOBALS['crush_test_intimacy'][$actorName] = $status; }
function getNpcAffinity($npcName, $targetName): int { return 72; }
function isProstitute($npcName): bool { return false; }

$repo = dirname(__DIR__);
$commonSource = crushReadSource('common.php');
$functionsSource = crushReadSource('functions.php');
$policySource = crushReadSource('open_mode_policy.php');
$instantCrush = crushExtractCallable($commonSource, 'aiagentNsfwInstantCrush', 'aiagentNsfwInstantCrush');
$policyReader = crushExtractCallable($commonSource, 'aiagentNsfwGetRelTypePolicy', 'aiagentNsfwGetRelTypePolicy');
$kissClosure = crushExtractRegisteredClosure($functionsSource, 'ExtCmdKiss');
$acceptSexClosure = crushExtractRegisteredClosure($functionsSource, 'ExtCmdAcceptSex');
$instantCrushHash = hash('sha256', $instantCrush);
$policyReaderHash = hash('sha256', $policyReader);
$kissClosureHash = hash('sha256', $kissClosure);
$acceptSexClosureHash = hash('sha256', $acceptSexClosure);

$nsfwInclude = 'require_once __DIR__ . "/nsfw_data.php";';
if (substr_count($instantCrush, $nsfwInclude) !== 1) {
    crushFailSetup('Expected one NsfwNpcData include in the extracted production crush helper');
}
$instantCrush = str_replace($nsfwInclude, '/* NsfwNpcData is a deterministic in-memory fixture. */', $instantCrush);

require_once $repo . DIRECTORY_SEPARATOR . 'open_mode_policy.php';
eval($policyReader);
eval($instantCrush);
$GLOBALS['FUNCSERV'] = [];
eval('$GLOBALS["FUNCSERV"]["ExtCmdKiss"]=' . $kissClosure . ';');
eval('$GLOBALS["FUNCSERV"]["ExtCmdAcceptSex"]=' . $acceptSexClosure . ';');

$GLOBALS['PLAYER_NAME'] = 'Player';
$GLOBALS['HERIKA_NAME'] = 'Mira';
$GLOBALS['returnFunction'] = [];
$GLOBALS['db'] = new CrushTestDatabase();
$GLOBALS['crush_test_intimacy'] = [];

$checks = [];
$failures = [];
$expect = static function (bool $condition, string $label, string $detail = '') use (&$checks, &$failures): void {
    $checks[] = ['label' => $label, 'pass' => $condition, 'detail' => $detail];
    if (!$condition) { $failures[] = $label; }
};

$setScenario = static function (string $type, array $config): void {
    RelationshipManager::$actors = [
        'Mira' => [
            'relationships_locked' => false,
            'relationships' => ['Player' => ['aff' => 72, 'type' => $type]],
        ],
    ];
    RelationshipManager::$setCalls = [];
    $GLOBALS['db']->config = json_encode($config, JSON_THROW_ON_ERROR);
    $GLOBALS['db']->queries = [];
    unset($GLOBALS['AIAGENTNSFW_RELTYPE_POLICY']);
    $GLOBALS['gameRequest'] = ['', 0, 0, 'Kiss@target@Player'];
};

$getPlayerType = static function (): string {
    return (string)(RelationshipManager::$actors['Mira']['relationships']['Player']['type'] ?? '');
};

$markedType = 'casual_intimacy';
$nonRomantic = ['non_romantic_types' => [$markedType]];

// Affection's actual registered Kiss closure: the mark protects the current
// Player edge even with the relationship gate disabled and an empty eligible list.
$setScenario($markedType, ['enabled' => false, 'eligible_types' => []] + $nonRomantic);
$policy = aiagentNsfwGetRelTypePolicy();
$expect(
    ($policy['valid'] ?? false) === true
        && ($policy['enabled'] ?? null) === false
        && ($policy['eligible_types'] ?? null) === []
        && ($policy['non_romantic_types'] ?? null) === [$markedType],
    'production reader returns a valid designation with the gate off and an empty eligible list'
);
$GLOBALS['FUNCSERV']['ExtCmdKiss']();
$expect(
    $getPlayerType() === $markedType && RelationshipManager::$setCalls === []
        && $GLOBALS['db']->queries === ["SELECT COALESCE((SELECT value::text FROM conf_opts WHERE id = 'aiagent_nsfw_reltypes'), '') AS value"],
    'actual Kiss action preserves a designated Player type when the gate is off and the type is ineligible'
);

// AcceptSex must follow the same policy through its real personal-intimacy branch.
$setScenario($markedType, ['enabled' => true, 'eligible_types' => ['romantic']] + $nonRomantic);
$policy = aiagentNsfwGetRelTypePolicy();
$expect(
    ($policy['valid'] ?? false) === true
        && ($policy['enabled'] ?? null) === true
        && ($policy['eligible_types'] ?? null) === ['romantic']
        && ($policy['non_romantic_types'] ?? null) === [$markedType],
    'production reader keeps designation separate from the enabled gate and eligible list'
);
$GLOBALS['gameRequest'][3] = 'AcceptSex@Player';
$GLOBALS['FUNCSERV']['ExtCmdAcceptSex']();
$expect(
    $getPlayerType() === $markedType && RelationshipManager::$setCalls === []
        && ($GLOBALS['crush_test_intimacy']['Mira']['accepted_sex'] ?? false) === true,
    'actual AcceptSex action preserves a designated Player type independently of eligibility'
);

// Unmarked ordinary relationships retain the existing affinity-triggered behavior.
$setScenario('platonic', ['enabled' => false, 'eligible_types' => []] + $nonRomantic);
$GLOBALS['FUNCSERV']['ExtCmdKiss']();
$expect(
    $getPlayerType() === 'crush'
        && RelationshipManager::$setCalls === [['Mira', 'Player', 72, 'crush']],
    'valid unmarked ordinary type still converts to crush through the actual Kiss action'
);

// After an explicit relationship edit, the automatic conversion must leave that
// romantic state intact even while the prior custom designation remains configured.
$setScenario($markedType, ['enabled' => false, 'eligible_types' => []] + $nonRomantic);
$GLOBALS['FUNCSERV']['ExtCmdKiss']();
RelationshipManager::$actors['Mira']['relationships']['Player']['type'] = 'romantic';
RelationshipManager::$setCalls = [];
$GLOBALS['FUNCSERV']['ExtCmdKiss']();
$expect(
    $getPlayerType() === 'romantic' && RelationshipManager::$setCalls === [],
    'a later explicit romantic relationship edit is not overwritten by automatic crush conversion'
);

printf("production_common_sha256=%s\n", hash('sha256', $commonSource));
printf("production_functions_sha256=%s\n", hash('sha256', $functionsSource));
printf("production_policy_sha256=%s\n", hash('sha256', $policySource));
printf("instant_crush_callable_sha256=%s\n", $instantCrushHash);
printf("policy_reader_callable_sha256=%s\n", $policyReaderHash);
printf("kiss_closure_sha256=%s\n", $kissClosureHash);
printf("acceptsex_closure_sha256=%s\n", $acceptSexClosureHash);
foreach ($checks as $check) {
    printf("%s %s%s\n", $check['pass'] ? 'PASS' : 'FAIL', $check['label'],
        $check['detail'] === '' ? '' : ' ' . $check['detail']);
}
printf("checks=%d failures=%d\n", count($checks), count($failures));
exit($failures === [] ? 0 : 1);
