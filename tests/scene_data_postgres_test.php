<?php
// Run as a local PostgreSQL role allowed to create disposable databases.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$root = dirname(__DIR__);
$admin = pg_connect('host=/var/run/postgresql dbname=postgres');
if (!$admin) throw new RuntimeException('Cannot connect to local PostgreSQL');
$database = 'sharmat_scene_test_' . bin2hex(random_bytes(6));
$quoted = pg_escape_identifier($admin, $database);
if (!pg_query($admin, "CREATE DATABASE {$quoted}")) throw new RuntimeException('Cannot create disposable database');
class SceneTestDb {
    public function __construct(public $connection) {}
    public function execQuery($query) { $r = pg_query($this->connection, $query); if (!$r) throw new RuntimeException(pg_last_error($this->connection)); return $r; }
    public function fetchOne($query) { return pg_fetch_assoc($this->execQuery($query)) ?: []; }
    public function fetchAll($query) { return pg_fetch_all($this->execQuery($query)) ?: []; }
    public function escape($value) { return pg_escape_string($this->connection, $value); }
    public function insert($table, $row) { return pg_insert($this->connection, $table, $row); }
}
$checks = 0;
function checkScene($ok, $label) { global $checks; ++$checks; if (!$ok) throw new RuntimeException($label); }
function sceneRejects($fn, $label) { $failed=false; try { $fn(); } catch (InvalidArgumentException $e) { $failed=true; } checkScene($failed,$label); }
function loadSceneFunction($source, $name) {
    if (!preg_match('/^function ' . preg_quote($name, '/') . '\(.*?^\}/ms', $source, $m)) throw new RuntimeException('Missing function ' . $name);
    eval($m[0]);
}
try {
    $connection = pg_connect('host=/var/run/postgresql dbname=' . $database, PGSQL_CONNECT_FORCE_NEW);
    checkScene(pg_dbname($connection) === $database, 'Test uses its newly created database');
    $db = $GLOBALS['db'] = new SceneTestDb($connection);
    $db->execQuery('CREATE TABLE conf_opts(id text PRIMARY KEY, value text)');
    $db->execQuery('CREATE TABLE ext_aiagentnsfw_scenes(stage text, description text, description_es text, description_en text, i_desc text)');
    $source = file_get_contents($root . '/nsfw_data.php');
    checkScene((bool)preg_match('/^class NsfwData \{.*?^\}/ms', $source, $m), 'Storage class extracted without application initialization');
    eval(str_replace('__DIR__', var_export($root, true), $m[0]));
    $lookup = file_get_contents($root . '/scene_lookup.php');
    foreach (['buildActorDescriptions','nsfwSceneIntensityTier','buildSceneIndex','getSceneData','getJsonbSceneDescription','getSceneDescription'] as $fn) loadSceneFunction($lookup,$fn);
    function getGlobalPrompt($key) { return ''; }
    function logOstimScene($id,$description,$source='JSONB') {}

    $handler = file_get_contents($root . '/nsfw_ostim_handler.php');
    checkScene((bool)preg_match('/\$sexStageName\s*=\s*[^;]+;/', $handler, $m), 'Production scene ID assignment located');
    foreach (['Prefix_A1_Middle','BDG_TB_Flr_TLB_BSF_A1'] as $raw) {
        $infoSexSceneParts = ['','',$raw]; eval($m[0]);
        checkScene($sexStageName === $raw, 'Player event preserves exact scene ID');
    }
    NsfwData::saveBlob(NsfwData::KEY_SCENES, [
        'Scene'=>['description'=>'base'], 'Scene_A1'=>['description'=>'exact {actor0}'],
        'Legacy'=>['description'=>'legacy {actor0}'], 'FamilyOne'=>['description'=>'one'], 'FamilyTwo'=>['description'=>'two'],
    ]);
    checkScene(NsfwData::getSceneDescription('Scene_A1') === 'exact {actor0}', 'Exact suffixed ID wins over legacy base');
    checkScene(NsfwData::getSceneDescription('Legacy_A1') === 'legacy {actor0}', 'Legacy suffix fallback preserves earlier customizations');
    checkScene(NsfwData::getScene('Legacy_A1') === null, 'Storage API keeps exact key semantics');
    $GLOBALS['OSTIM_INDEX_BUILT'] = false;
    checkScene(getSceneData('Scene_A1')['description'] === 'exact {actor0}', 'Metadata lookup agrees with description lookup');
    checkScene(getSceneData('Legacy_A1')['description'] === 'legacy {actor0}', 'Metadata lookup supports legacy suffix');
    checkScene(getSceneData('Family') === null && getSceneData('') === null, 'Ambiguous and empty IDs do not borrow an arbitrary scene');
    checkScene(str_contains(getSceneDescription('Scene_A1',['Actor A']), 'exact Actor A'), 'Full description path renders exact scene');

    checkScene(buildActorDescriptions('{Actor0} and {actor1}', ['Actor A','Actor B']) === 'Actor A and Actor B', 'Capitalized valid tokens normalize');
    checkScene(buildActorDescriptions('{actor} / {target}', ['Actor A','Actor B']) === 'Actor A / Actor B', 'Generic token compatibility');
    checkScene(buildActorDescriptions('{mood} {actor0}', ['Actor A']) === '{mood} Actor A', 'Unrelated braces preserved');
    foreach (['{actor1}','{actor01}','{actor-9}','{actor2]','{actor2] {actor0}','{actor9','{target}'] as $invalid) {
        checkScene(buildActorDescriptions($invalid,['Actor A']) === 'An intimate scene is happening', 'Invalid token gets neutral runtime fallback');
    }
    NsfwData::saveBlob(NsfwData::KEY_SCENES, ['Solo'=>['description'=>'original','actors'=>[[]],'tier'=>2]]);
    sceneRejects(fn()=>NsfwData::updateSceneDescription('Solo','{actor1}'), 'Save rejects out-of-range slot when actor count known');
    sceneRejects(fn()=>NsfwData::updateSceneDescription('Solo','{actor-9}'), 'Save rejects malformed token');
    checkScene(NsfwData::getScene('Solo')['description'] === 'original', 'Rejected save leaves description intact');
    checkScene(NsfwData::updateSceneDescription('Solo','{Actor0} moves'), 'Valid save succeeds');
    checkScene(NsfwData::getScene('Solo')['description'] === '{actor0} moves' && NsfwData::getScene('Solo')['tier']===2, 'Save normalizes tokens while preserving metadata');

    $parsed = NsfwData::parseSceneImport('{"New":"{Actor0} waits","Solo":{"description":"replacement"}}');
    $result = NsfwData::importScenes($parsed);
    checkScene($result === ['imported'=>1,'skipped'=>1], 'Default import adds missing and skips existing');
    checkScene(NsfwData::getScene('Solo')['description']==='{actor0} moves', 'Default import protects custom text');
    NsfwData::importScenes($parsed, true);
    checkScene(NsfwData::getScene('Solo')['description']==='replacement' && NsfwData::getScene('Solo')['tier']===2 && isset(NsfwData::getScene('Solo')['actors']), 'Explicit overwrite merges supplied fields without erasing metadata');
    checkScene(NsfwData::getScene('New')['description']==='{actor0} waits', 'Imported capitalization normalized');
    $tsv = "\xEF\xBB\xBFstage\tdescription\tdescription_es\nTsv\t\"line one\nline two \"\"quoted\"\"\"\t\\N\n";
    $parsed = NsfwData::parseSceneImport($tsv);
    checkScene($parsed['Tsv']['description']==="line one\nline two \"quoted\"" && $parsed['Tsv']['description_es']===null, 'TSV supports BOM, multiline quoted cells and legacy nulls');
    $parsed = NsfwData::parseSceneImport("stage\tdescription\tdescription_es\nTrailing\ttext\t\n");
    checkScene($parsed['Trailing']['description_es'] === '', 'TSV retains an empty final column');
    checkScene(isset(NsfwData::parseSceneImport('[{"stage":"ListScene","description":"test"}]')['ListScene']), 'JSON row arrays import');
    sceneRejects(fn()=>NsfwData::parseSceneImport("stage\tdescription\nBad\t{actor2]"), 'Malformed TSV placeholder rejected');
    sceneRejects(fn()=>NsfwData::parseSceneImport("stage\tdescription\nSame\tfirst\nSame\tsecond"), 'Duplicate TSV IDs rejected');
    $before = NsfwData::getAllScenes();
    sceneRejects(fn()=>NsfwData::importScenes(['Valid'=>['description'=>'valid'],'Bad'=>['description'=>'{actor-9}']],true), 'Bulk validation rejects invalid batch');
    checkScene(NsfwData::getAllScenes()===$before, 'Invalid batch does not partially save valid rows');

    $db->execQuery("INSERT INTO ext_aiagentnsfw_scenes(stage,description) VALUES ('Solo','old text'),('LegacyNew','legacy new')");
    $result = NsfwData::migrateFromTable();
    checkScene($result['success'] && $result['migrated']===1 && $result['skipped']===1, 'Migration adds missing legacy scenes');
    checkScene(NsfwData::getScene('Solo')['description']==='replacement' && NsfwData::getScene('New')!==null, 'Migration retains edited and custom-only entries');
    checkScene(NsfwData::migrateFromTable()['migrated']===0, 'Repeat migration is non-destructive');
    NsfwData::saveBlob(NsfwData::KEY_SCENES, ['Holding'=>['description'=>'test','actions'=>[['type'=>'VaginalSex']]]]);
    checkScene(nsfwSceneIntensityTier([],'Holding','Holding')===3, 'Explicit action metadata overrides tagless holding name');
    checkScene(nsfwSceneIntensityTier(['sex'],'Holding')===3, 'SexLab explicit tag still wins');
    checkScene(nsfwSceneIntensityTier(['kissing'],'Kiss')===2 && nsfwSceneIntensityTier([],'Holding')===1 && nsfwSceneIntensityTier(['idle'],'Intro')===0, 'Affection, romance and idle tiers preserved');
    NsfwData::saveBlob(NsfwData::KEY_SCENES, ['Holding'=>['tier'=>1,'actions'=>[['type'=>'vaginalsex']]]]);
    checkScene(nsfwSceneIntensityTier([],'Holding','Holding')===1, 'Authored tier override still wins');

    $legacy = json_decode(file_get_contents(__DIR__.'/fixtures/scene_catalog_3.1.9.10.json'),true);
    $stages = array_keys($legacy);
    NsfwData::saveBlob(NsfwData::KEY_SCENES, [
        $stages[0]=>['description'=>$legacy[$stages[0]],'tier'=>1,'description_es'=>'translation'],
        $stages[1]=>['description'=>'custom edit'], 'Unrelated'=>['description'=>'keep'],
    ]);
    checkScene(NsfwData::repairShippedSceneDescriptions()===1, 'Only exact defective shipped text repaired');
    checkScene(NsfwData::getScene($stages[0])['description']===NsfwData::getSceneDefault($stages[0]) && NsfwData::getScene($stages[0])['tier']===1, 'Repair preserves metadata');
    checkScene(NsfwData::getScene($stages[1])['description']==='custom edit' && NsfwData::getScene($stages[0])['description_es']==='translation', 'Repair preserves edited text and translations');
    checkScene(NsfwData::repairShippedSceneDescriptions()===0, 'Catalog repair is idempotent');
    NsfwData::resetAllScenesToDefault();
    checkScene(NsfwData::getScene('Unrelated')['description']==='keep', 'Explicit reset still preserves user-added scenes');
    $defaults=NsfwData::getAllSceneDefaults();
    checkScene(count($defaults)===5133, 'Catalog count unchanged');
    checkScene(!str_contains(json_encode($defaults),'printStackTrace') && !preg_match('/\{Actor[0-9]+\}/',json_encode($defaults)), 'Shipped catalog defects removed');

    $ui = file_get_contents($root.'/config_manager.php');
    $start=strpos($ui,'function handleSavePromptSettings()');
    $start=strpos($ui,'$settings = [',$start);
    $end=strpos($ui,'$json = json_encode($settings',$start);
    $_POST=['normal_kinks_template'=>'Normal sentinel','secret_kinks_template'=>'Secret sentinel'];
    eval(substr($ui,$start,$end-$start));
    checkScene($settings['normal_kinks_template']==='Normal sentinel' && $settings['secret_kinks_template']==='Secret sentinel', 'Prompt save includes both submitted templates');
    NsfwData::saveBlob(NsfwData::KEY_PROMPTS,['normal_kinks_template'=>'Saved normal','secret_kinks_template'=>'Saved secret']);
    $_POST=[]; eval(substr($ui,$start,$end-$start));
    checkScene($settings['normal_kinks_template']==='Saved normal' && $settings['secret_kinks_template']==='Saved secret', 'Older form submissions preserve stored templates');
    checkScene(str_contains($handler,"NsfwData::getDefaultKinkTemplates()['normal_kinks_template']") && str_contains($ui,"NsfwData::getDefaultKinkTemplates()['normal_kinks_template']"), 'Runtime and UI use shared defaults');
    echo "PASS: {$checks} scene data, import, migration, intensity and template checks.\n";
} finally {
    if (isset($connection) && $connection) pg_close($connection);
    if (!pg_query($admin,"DROP DATABASE {$quoted}")) throw new RuntimeException('Test database cleanup failed');
    pg_close($admin);
}
