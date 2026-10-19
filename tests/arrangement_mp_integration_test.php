<?php
declare(strict_types=1);

$mpRootArg = $argv[1] ?? '';
$mpRoot = $mpRootArg === '' ? false : realpath($mpRootArg);
if ($mpRoot === false || !is_file($mpRoot . '/server/store.php')
    || !is_file($mpRoot . '/server/intimacy.php')
    || !is_file($mpRoot . '/server/intimacy_request.php')) {
    fwrite(STDERR, "Pass the explicit CHIM-MindPoisoning-Intimacy checkout path as argv[1].\n");
    exit(2);
}

require_once $mpRoot . '/server/controls.php';
require_once $mpRoot . '/server/intimacy_request.php';

function chimGetGeneralSettingBool(string $id, bool $default = false): bool
{
    return $id === 'mind_poisoning_intimacy_enabled' ? true : $default;
}

function chimInteractionBegin(): void
{
    $GLOBALS['chim_interaction_generation'] ??= 7;
}

function chimInteractionState(): array
{
    return ['enabled' => true, 'generation' => 7];
}

function extractSpeakerNameFromChatEvent(string $sourceData): string
{
    $colon = strpos($sourceData, ':');
    return $colon === false ? '' : trim(substr($sourceData, 0, $colon));
}

function extractTalkTargetMetadata(string $sourceData): array
{
    if (preg_match('/\(Talking to ([^()]*)\)\s*$/iu', $sourceData, $matches) !== 1) {
        return ['hasExplicitTarget' => false, 'isBroadcast' => true, 'targets' => []];
    }
    return ['hasExplicitTarget' => true, 'isBroadcast' => false, 'targets' => [trim($matches[1])]];
}

if (!class_exists('RelationshipManager')) {
    final class RelationshipManager
    {
        public static function resolveNpcByName(string $name): ?array
        {
            $npc = $GLOBALS['ar_npcs'][$name] ?? null;
            return is_array($npc) ? $npc : null;
        }
    }
}

function arrangementIntegrationAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
    $GLOBALS['arrangement_integration_checks']++;
}

final class ArrangementIntegrationStore implements \ChimMindPoisoning\StoreDb
{
    public array $npcs = [];
    public array $events = [];
    public int $lockedOwner = 0;
    public int $writeCount = 0;
    private bool $transaction = false;
    private ?array $before = null;

    public function activePlaythrough(): ?array { return ['id' => '1', 'player_name' => 'Dragonborn']; }
    public function acknowledgedEvent(string $utteranceId): ?array
    {
        foreach ($this->events as $event) {
            if (($event['utterance_id'] ?? null) === $utteranceId) { return $event; }
        }
        return null;
    }
    public function playerInputEvent(array $source): ?array { return null; }
    public function eventById(int $eventId, string $utteranceId): ?array
    {
        $event = $this->events[$eventId] ?? null;
        return is_array($event) && ($event['utterance_id'] ?? null) === $utteranceId ? $event : null;
    }
    public function npcIdentities(): array
    {
        return array_values(array_map(static fn(array $npc): array => [
            'id' => $npc['id'], 'npc_name' => $npc['npc_name'],
        ], $this->npcs));
    }
    public function npcById(int $npcId, bool $forUpdate = false): ?array { return $this->npcs[$npcId] ?? null; }
    public function reflectionHistory(string $actorName, int $beforeEventId): array { return []; }
    public function beginForListener(int $listenerId): bool
    {
        if ($this->transaction || !isset($this->npcs[$listenerId])) { return false; }
        $this->lockedOwner = $listenerId;
        $this->before = unserialize(serialize($this->npcs));
        $this->transaction = true;
        return true;
    }
    public function writeNpc(int $npcId, array $relationshipEdges, object $mindPoisoningData, float $gamets, array $relationshipKeysToRemove = []): bool
    {
        if (!$this->transaction || !isset($this->npcs[$npcId])) { return false; }
        if ($relationshipEdges !== [] || $relationshipKeysToRemove !== []) { return false; }
        $plugins = clone $this->npcs[$npcId]['plugin_extended_data'];
        $plugins->mind_poisoning = $mindPoisoningData;
        $this->npcs[$npcId]['plugin_extended_data'] = $plugins;
        $this->npcs[$npcId]['gamets_last_updated'] = $gamets;
        $this->writeCount++;
        return true;
    }
    public function backupAndVerify(int $npcId, array $expected): bool
    {
        $row = $this->npcs[$npcId] ?? null;
        return is_array($row)
            && \ChimMindPoisoning\sameJsonValue($row['extended_data'] ?? null, $expected['extended_data'] ?? null)
            && \ChimMindPoisoning\sameJsonValue($row['plugin_extended_data'] ?? null, $expected['plugin_extended_data'] ?? null)
            && (float)($row['gamets_last_updated'] ?? -1) === (float)($expected['gamets_last_updated'] ?? -2);
    }
    public function commit(): bool
    {
        if (!$this->transaction) { return false; }
        $this->transaction = false;
        $this->before = null;
        return true;
    }
    public function rollback(): void
    {
        if ($this->transaction && $this->before !== null) { $this->npcs = $this->before; }
        $this->transaction = false;
        $this->before = null;
    }
    public function release(): void { $this->transaction = false; }
}

function arrangementIntegrationExtractFunction(string $source, string $name): string
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
        if (!isset($tokens[$openIndex])) { break; }
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
    }
    throw new RuntimeException("Could not extract production function {$name}.");
}

$GLOBALS['arrangement_integration_checks'] = 0;
$enginePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'sharmat-arrangement-mp-' . getmypid();
if (!is_dir($enginePath) && !mkdir($enginePath, 0700) && !is_dir($enginePath)) {
    throw new RuntimeException('Could not create the task-owned engine control fixture.');
}
file_put_contents($enginePath . DIRECTORY_SEPARATOR . 'main.php', "<?php\n");
$GLOBALS['ENGINE_PATH'] = $enginePath . DIRECTORY_SEPARATOR;
$GLOBALS['chim_interaction_generation'] = 7;
$GLOBALS['NEVER_CLEAR_RELATIONSHIP_DATA'] = false;

try {
    $speakerEdge = (object)['aff' => -42, 'type' => 'enemy', 'notes' => 'preserve this edge'];
    $store = new ArrangementIntegrationStore();
    $store->npcs = [
        101 => [
            'id' => 101,
            'npc_name' => 'A',
            'extended_data' => (object)['relationships' => (object)['B' => $speakerEdge]],
            'plugin_extended_data' => new stdClass(),
            'gamets_last_updated' => 5.0,
        ],
        202 => [
            'id' => 202,
            'npc_name' => 'B',
            'extended_data' => (object)['relationships' => new stdClass()],
            'plugin_extended_data' => new stdClass(),
            'gamets_last_updated' => 5.0,
        ],
    ];
    $text = 'We have a casual arrangement.';
    $utteranceId = 'utt_sharmat_arrangement_0001';
    $sourceData = 'A: ' . $text . ' (Talking to B)';
    $store->events[301] = [
        'event_id' => 301,
        'utterance_id' => $utteranceId,
        'gamets' => 25.0,
        'source_data' => $sourceData,
        'delivery_state' => 'spoken',
        'speaker_kind' => 'npc',
    ];
    $event = [
        'event_id' => 301,
        'utterance_id' => $utteranceId,
        'playthrough_id' => '1',
        'gamets' => 25.0,
        'source_data' => $sourceData,
        'speaker_kind' => 'npc',
        'speaker_id' => 101,
        'opinion_owner_id' => 101,
        'speaker_name' => 'A',
        'listener_id' => 202,
        'listener_name' => 'B',
        'listener_role' => 'addressed',
        'addressed_listener_id' => 202,
        'addressed_listener_name' => 'B',
        'target_subject' => 'npc:202',
        'player_name' => 'Dragonborn',
        'text' => $text,
        'speech_hash' => hash('sha256', $text),
        'interaction_generation' => 7,
        'source_kind' => 'intimacy',
    ];
    $event['ack_identity'] = \ChimMindPoisoning\mindPoisoningIntimacyAckIdentity($event);
    $owner = $store->npcById(101);
    $initialEdgeBytes = serialize($store->npcs[101]['extended_data']);
    $response = json_encode(['intimacy' => ['status' => 'casual', 'evidence' => $text]], JSON_THROW_ON_ERROR);
    $producerStatus = \ChimMindPoisoning\classifyDirectIntimacyEvent(
        $event,
        $owner,
        $store,
        static fn(array $_event, string $_stage): bool => true,
        static fn(array $_messages): string => $response
    );
    arrangementIntegrationAssert($producerStatus === 'committed', 'Real MP classifier and speaker-owned writer did not commit.');
    arrangementIntegrationAssert($store->lockedOwner === 101, 'MP producer must lock the NPC self-report owner.');
    arrangementIntegrationAssert($store->writeCount === 1, 'MP producer must perform one store write.');
    arrangementIntegrationAssert(serialize($store->npcs[101]['extended_data']) === $initialEdgeBytes,
        'MP arrangement-only producer must leave affinity/type/notes byte-identical.');

    $projection = \ChimMindPoisoning\mindPoisoningReadIntimacyArrangement(101, 'npc:202', $store);
    arrangementIntegrationAssert(($projection['state'] ?? null) === 'active' && ($projection['kind'] ?? null) === 'casual',
        'Real MP v1 API did not read the just-produced exact-pair arrangement.');
    arrangementIntegrationAssert($store->writeCount === 1,
        'Real MP public reader must not mutate the producer store.');

    $commonPath = dirname(__DIR__) . '/common.php';
    $commonSource = file_get_contents($commonPath);
    if (!is_string($commonSource)) { throw new RuntimeException('Could not read SHARMAT production common.php.'); }
    $GLOBALS['arrangement_mp_store'] = $store;
    $GLOBALS['PLAYER_NAME'] = 'Dragonborn';
    $GLOBALS['ar_npcs'] = [
        'A' => ['id' => 101, 'npc_name' => 'A'],
        'B' => ['id' => 202, 'npc_name' => 'B'],
    ];
    $GLOBALS['AIAGENTNSFW_RELTYPE_POLICY'] = [
        'valid' => true,
        'enabled' => true,
        'eligible_types' => ['romantic', 'crush', 'ex'],
        'non_romantic_types' => [],
        'use_intimacy_arrangements' => true,
    ];
    $functions = [];
    foreach ([
        'aiagentNsfwGetRelTypePolicy',
        'aiagentNsfwReadIntimacyArrangement',
        'aiagentNsfwHasActiveIntimacyArrangement',
        'aiagentNsfwIntimacyArrangementAllowsSexPair',
    ] as $name) {
        $function = arrangementIntegrationExtractFunction($commonSource, $name);
        if ($name === 'aiagentNsfwReadIntimacyArrangement') {
            $needle = '$projection = $reader($ownerId, $targetSubject);';
            $replacement = '$projection = $reader($ownerId, $targetSubject, $GLOBALS["arrangement_mp_store"]);';
            $count = 0;
            $function = str_replace($needle, $replacement, $function, $count);
            if ($count !== 1) { throw new RuntimeException('Expected one optional-store injection seam in the extracted SHARMAT reader.'); }
        }
        $functions[] = $function;
    }
    eval(implode("\n", $functions));
    $samePairAllows = aiagentNsfwIntimacyArrangementAllowsSexPair('A', 'B');
    arrangementIntegrationAssert($samePairAllows === true,
        'Actual SHARMAT pair-policy helper did not accept the real MP exact-pair projection.');
    arrangementIntegrationAssert(aiagentNsfwIntimacyArrangementAllowsSexPair('B', 'A') === false,
        'Actual SHARMAT pair-policy helper inferred a reverse arrangement.');
    arrangementIntegrationAssert($store->writeCount === 1,
        'SHARMAT consumer reads must not write to the MP producer store.');

    $hashes = [];
    foreach ([
        $mpRoot . '/server/intimacy.php',
        $mpRoot . '/server/intimacy_request.php',
        $mpRoot . '/server/store.php',
        $commonPath,
    ] as $path) {
        $hashes[basename(dirname($path)) . '/' . basename($path)] = hash_file('sha256', $path);
    }
    echo json_encode([
        'checks' => $GLOBALS['arrangement_integration_checks'],
        'failures' => 0,
        'producer_status' => $producerStatus,
        'api_projection' => $projection,
        'consumer' => 'extracted SHARMAT reader and exact-pair policy; one test-only substitution adds the API optional StoreDb argument',
        'source_sha256' => $hashes,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
} finally {
    $mainPath = $enginePath . DIRECTORY_SEPARATOR . 'main.php';
    if (is_file($mainPath)) { unlink($mainPath); }
    if (is_dir($enginePath)) { rmdir($enginePath); }
}
