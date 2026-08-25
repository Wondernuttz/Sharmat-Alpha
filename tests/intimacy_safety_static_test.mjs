import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const here = path.dirname(fileURLToPath(import.meta.url));
const root = path.dirname(here);
const read = (name) => fs.readFileSync(path.join(root, name), 'utf8');

const catalog = read('catalog_seed.php');
const upsert = catalog.slice(catalog.indexOf('ON CONFLICT (code_name)'));
assert.ok(upsert.includes('metadata        = EXCLUDED.metadata'));
assert.ok(!upsert.includes('is_activated    = EXCLUDED.is_activated'), 'catalog refresh must preserve Action Editor activation state');

const sceneEngine = read('mod/Source/Scripts/AIAgentNSFWSceneEngine.psc');
assert.ok(sceneEngine.includes('bool Function OStimActIsChasteAffection'));
assert.ok(sceneEngine.includes('sceneName = OStimExactSceneForAct(sceneAct)'));
assert.ok(sceneEngine.includes('elseif sceneName == ""\n        sceneName = OLibrary.GetRandomScene(actors)'), 'random fallback remains available for non-affection acts');
assert.ok(sceneEngine.includes('if sceneName == "" && chasteAffection'), 'chaste affection must fail before random fallback');
assert.ok(sceneEngine.includes('RosterContainsProtectedMinor(actors)'), 'Papyrus scene start must reject protected actors');

const common = read('common.php');
assert.ok(common.includes('function aiagentNsfwIsExcludedNpc'));
assert.ok(common.includes('function aiagentNsfwIsProtectedNpc'));
assert.ok(common.includes("_getNsfwSetting('NSFW_EXCLUDED_NPCS', '')"));
assert.ok(!common.includes('aiagentNsfwNpcToNpcAffectionEligible'), 'affection must remain free/OStim-driven');
assert.ok(!common.includes('RelationshipManager::getRelationship($npcB, $npcA)'), 'do not add reciprocal NPC relationship policy');

const functions = read('functions.php');
assert.ok(functions.includes('HARD INTIMACY SAFETY FILTER'));
assert.ok(functions.includes('aiagentNsfwIsProtectedNpc($__safeTarget)'));
assert.ok(functions.includes('aiagentNsfwIsProtectedNpc($__safeSpeaker)'));

const settingsUi = read('config_section_settings.php');
assert.ok(settingsUi.includes('id="nsfwExcludedNpcs"'));
const configManager = read('config_manager.php');
assert.ok(configManager.includes("fdSet('NSFW_EXCLUDED_NPCS', 'nsfwExcludedNpcs', 'value')"));
assert.ok(configManager.includes("'reason' => 'user_excluded'"));

const prompts = read('prompts.php');
assert.ok(prompts.includes('aiagentNsfwStructuredResponseTokenBudget()'));
assert.ok(!prompts.includes('TOKEN_LIMIT_SEX_SCENE'));
assert.ok(!prompts.includes('TOKEN_LIMIT_CLIMAX'));
assert.ok(!prompts.includes('TOKEN_LIMIT_PHYSICS'));
assert.ok(!prompts.includes('"player_request" => ["The Narrator: "]'));

assert.ok(common.includes('function aiagentNsfwStructuredResponseTokenBudget()'));
assert.ok(common.includes('return 512;'));

assert.ok(!settingsUi.includes('Response Token Limits'));
assert.ok(settingsUi.includes('Action Voice &amp; Random Moans'));
assert.ok(settingsUi.includes('Enable TTS Modifier - Level 2 (Action)'));
assert.ok(settingsUi.includes('Exact-Match Repeat Cooldown'));
assert.ok(settingsUi.includes('NSFW_GAZE_EXACT_MATCH_COOLDOWN_SECONDS'));

const physics = read('nsfw_physics.php');
assert.ok(physics.includes("_getNsfwSetting('NSFW_GAZE_EXACT_MATCH_COOLDOWN_SECONDS', 120)"));
assert.ok(physics.includes("aiagentNsfwRuntimeStateGet('gaze_exact_cd', $exactKey)"));
assert.ok(physics.includes("aiagentNsfwRuntimeStateSet('gaze_exact_cd', $exactKey"));
assert.ok(physics.includes("$exactKey = $actorName . '|' . $region"));

console.log('intimacy_safety_static_test: OK');
