<?php
declare(strict_types=1);

$repo = dirname(__DIR__);
$input = json_decode((string)file_get_contents('php://stdin'));
if (!is_object($input)) {
    fwrite(STDERR, "Expected a JSON object on stdin.\n");
    exit(2);
}

class NonRomanticSettingsTestDb
{
    private $value;
    private bool $readFailure;
    public array $writes = [];

    public function __construct($value, bool $readFailure = false)
    {
        $this->value = $value;
        $this->readFailure = $readFailure;
    }
    public function fetchOne(string $query)
    {
        $expected = "SELECT COALESCE((SELECT value::text FROM conf_opts WHERE id = 'aiagent_nsfw_reltypes'), '') AS value";
        if (trim($query) !== $expected) {
            throw new RuntimeException('Expected the production guaranteed-row relationship-type read query.');
        }
        if ($this->readFailure) { return []; }
        return ['value' => is_string($this->value) ? $this->value : ''];
    }
    public function upsertRowOnConflict(string $table, array $row, string $key): void
    {
        $this->writes[] = ['table' => $table, 'row' => $row, 'conflict' => $key];
    }
}

function nonRomanticSettingsFail(string $message): void
{
    fwrite(STDERR, $message . "\n");
    exit(2);
}

function nonRomanticSettingsExtractFunction(string $source, string $name): string
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
            && in_array($tokens[$nameIndex][0], [T_WHITESPACE, T_AMPERSAND_NOT_FOLLOWED_BY_VAR_OR_VARARG, T_AMPERSAND_FOLLOWED_BY_VAR_OR_VARARG], true)) {
            $nameIndex++;
        }
        if (!isset($tokens[$nameIndex]) || !is_array($tokens[$nameIndex])
            || $tokens[$nameIndex][0] !== T_STRING || $tokens[$nameIndex][1] !== $name) {
            continue;
        }

        $openIndex = $nameIndex;
        while (isset($tokens[$openIndex]) && $tokens[$openIndex] !== '{') { $openIndex++; }
        if (!isset($tokens[$openIndex])) { nonRomanticSettingsFail("Function body missing for {$name}"); }
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
        if ($end === null) { nonRomanticSettingsFail("Unbalanced function body for {$name}"); }
        $matches[] = substr($source, $offsets[$i], $end - $offsets[$i]);
    }
    if (count($matches) !== 1) {
        nonRomanticSettingsFail("Expected one production function {$name}; found " . count($matches));
    }
    return $matches[0];
}

$mode = $argv[1] ?? '';
if ($mode === 'normalize') {
    require_once $repo . '/open_mode_policy.php';
    $results = [];
    foreach (($input->cases ?? []) as $name => $case) {
        $config = property_exists($case, 'config') ? $case->config : null;
        $readStatus = is_string($case->read_status ?? null) ? $case->read_status : 'present';
        $results[$name] = aiagentNsfwNormalizeRelTypeConfig($config, $readStatus);
    }
    echo json_encode($results, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

if ($mode === 'render') {
    $value = property_exists($input, 'raw_value') ? $input->raw_value : null;
    $GLOBALS['db'] = new NonRomanticSettingsTestDb($value, !empty($input->read_failure));
    $template = $argv[2] ?? '';
    if ($template === '' || !is_file($template)) { nonRomanticSettingsFail('Missing isolated production template path.'); }
    ob_start();
    include $template;
    $html = (string)ob_get_clean();
    echo json_encode(['html' => $html], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

if ($mode === 'save') {
    require_once $repo . '/open_mode_policy.php';
    $source = file_get_contents($repo . '/config_manager.php');
    if (!is_string($source)) { nonRomanticSettingsFail('Could not read production config_manager.php.'); }
    eval(nonRomanticSettingsExtractFunction($source, 'handleSaveRelTypes'));

    $currentValue = property_exists($input, 'current_value') ? $input->current_value : null;
    $GLOBALS['db'] = new NonRomanticSettingsTestDb($currentValue, !empty($input->read_failure));
    $db = $GLOBALS['db'];
    $_POST = (array)($input->post ?? new stdClass());
    register_shutdown_function(static function () use ($db): void {
        echo "\n@@NON_ROMANTIC_SETTINGS_WRITES@@" . json_encode($db->writes, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    });
    handleSaveRelTypes();
}

nonRomanticSettingsFail('Expected normalize, render, or save mode.');
