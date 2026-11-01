<?php
// Evaluate the production prompt blocks without bootstrapping the game/server.
$root = dirname(__DIR__);
function extractAutonomyFunction($name) {
    $source = file_get_contents(dirname(__DIR__) . '/common.php');
    if (!preg_match('/^function ' . $name . '\(.*?^\}/ms', $source, $match)) throw new RuntimeException('Missing function ' . $name);
    eval($match[0]);
}
function aiagentNsfwOpenMode() { return $GLOBALS['fixture']['open'] ?? false; }
function aiagentNsfwIsProtectedNpc($name) { return $name === 'Excluded Actor'; }
function isNpcSlave($name) { return $GLOBALS['fixture']['slave'] ?? false; }
function isProstitute($name) { return $GLOBALS['fixture']['prostitute'] ?? false; }
function aiagentNsfwIsSlutNpc($name) { return $GLOBALS['fixture']['promiscuous'] ?? false; }
function aiagentNsfwCanonicalRelationshipType($name) { return strtolower($name); }
function _getNsfwSetting($key, $default) { return $default; }
function getGlobalPrompt($key) { return $GLOBALS['fixture']['prompts'][$key] ?? ''; }
function getIntimacyForActor($name) { return $GLOBALS['fixture']['status'] ?? ['level'=>0,'sex_started'=>false,'intensity_tier'=>0]; }
function isSexDisposalEnabled() { return true; }
function getNpcAffinity($name) { return 100; }
function aiagentNsfwRelTypeSexEligible($name) { return true; }
class RelationshipManager {
    static function getRelationship($a, $b) { return $GLOBALS['fixture']['relationships'][$b] ?? ['aff'=>46, 'type'=>'platonic']; }
}
class AutonomyDb {
    function fetchOne($query) { return ['value'=>json_encode(['enabled'=>true,'eligible_types'=>['romantic']])]; }
}
extractAutonomyFunction('aiagentNsfwPlayerPresentForAutonomy');
extractAutonomyFunction('aiagentNsfwNpcToNpcSexEligible');
$source = file_get_contents($root . '/context_pre.php');
$start = strpos($source, '$__autonomyPlayerPresent =');
$end = strpos($source, '// REDRESS NUDGE', $start);
if ($start === false || $end === false) throw new RuntimeException('Prompt blocks not found');
$block = substr($source, $start, $end - $start);
$checks = 0;
function checkAutonomy($ok, $message) { global $checks; ++$checks; if (!$ok) throw new RuntimeException($message); }
function renderAutonomy($people, $fixture = [], $event = 'rechat') {
    global $block;
    $GLOBALS['fixture'] = $fixture;
    $GLOBALS['db'] = new AutonomyDb();
    $GLOBALS['HERIKA_PERS'] = '';
    $GLOBALS['PLAYER_NAME'] = 'Test Player';
    $GLOBALS['CACHE_PEOPLE'] = $people;
    $GLOBALS['gameRequest'] = [$event];
    $GLOBALS['AIAGENTNSFW_INITIATION_AUTONOMY'] = true;
    $GLOBALS['AIAGENTNSFW_AFFECTION_AUTONOMY'] = true;
    $actorName = 'Speaker';
    eval($block);
    return $GLOBALS['HERIKA_PERS'];
}
$romantic = ['relationships'=>['Partner'=>['aff'=>80,'type'=>'romantic']]];
$text = renderAutonomy('Speaker|Partner');
checkAutonomy(!str_contains($text, '<npc_scene_autonomy>'), 'Ineligible pair receives no NPC initiation cue');
checkAutonomy(!str_contains($text, '<intimacy_autonomy>') && !str_contains($text, '<affection_autonomy>') && !str_contains($text, '<arousal_receptiveness>'), 'Absent player receives no player-specific cues');
$text = renderAutonomy('Speaker|Partner', $romantic);
checkAutonomy(str_contains($text, '<npc_scene_autonomy>') && str_contains($text, 'Eligible partners for this turn: Partner.'), 'Eligible pair retains NPC autonomy');
checkAutonomy(!str_contains($text, 'Test Player'), 'Default NPC cue does not reference absent player');
$text = renderAutonomy('Speaker|Partner|Other|Test Player', $romantic);
checkAutonomy(str_contains($text, '<intimacy_autonomy>') && str_contains($text, '<arousal_receptiveness>'), 'Present player retains normal intimacy and arousal cues');
checkAutonomy(str_contains($text, 'Eligible partners for this turn: Partner.') && !str_contains($text, 'Other'), 'Only eligible partners appear in the suggestion');
$text = renderAutonomy('Speaker|tEsT pLaYeR');
checkAutonomy(str_contains($text, '<affection_autonomy>'), 'Player matching ignores case');
checkAutonomy(str_contains(renderAutonomy('Speaker', [], 'inputtext_s'), '<intimacy_autonomy>'), 'Private player input retains autonomy on legacy audience lists');
checkAutonomy(str_contains(renderAutonomy('Speaker|Partner', ['prostitute'=>true]), '<npc_scene_autonomy>'), 'Existing prostitute eligibility still works');
checkAutonomy(!str_contains(renderAutonomy('Speaker|Partner', $romantic + ['slave'=>true]), '<npc_scene_autonomy>'), 'Slave initiation remains player-only');
checkAutonomy(!str_contains(renderAutonomy('Speaker|Excluded Actor', ['open'=>true]), '<npc_scene_autonomy>'), 'Protected actor exclusion precedes open mode');
checkAutonomy(!str_contains(renderAutonomy('Speaker|Partner', $romantic + ['status'=>['level'=>2,'sex_started'=>true,'intensity_tier'=>3]]), '<npc_scene_autonomy>'), 'Active scenes do not get initiation cues');
$text = renderAutonomy('Speaker|Partner', $romantic + ['prompts'=>['npc_scene_autonomy_nudge'=>'CUSTOM_NUDGE']]);
checkAutonomy(str_contains($text, 'CUSTOM_NUDGE') && str_contains($text, 'Eligible partners for this turn: Partner.'), 'Custom wording is preserved with current eligible targets');
echo "PASS: {$checks} autonomy prompt checks.\n";
