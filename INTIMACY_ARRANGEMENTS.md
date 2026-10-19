# Optional Mind Poisoning intimacy arrangements

This contribution adds a separate, default-off consumer for Mind Poisoning's version-1 read API. It extends the custom-type preservation and target-aware action work in PR #7; it is separate from OStim teardown cleanup.

## Why separate state?

Affinity describes liking and the main relationship type describes an emotional relationship. Neither needs to be replaced when an NPC reports ongoing casual intimacy. A negative-affinity or non-romantic emotional edge can coexist with such a report. No fake romantic type, automatic affection bonus or reciprocal edge is required.

MP 0.1.26 recognizes current first-person reports from exact addressed NPC lines and registered reflections when its own recognition setting is enabled. Its `casual`, `romantic` and explicit `none` records belong to one NPC. They do not establish a shared agreement, exclusivity or current willingness. MP's model interpretation remains fallible.

## Setup

This is source for upstream review, not a replacement SHARMAT release or an installed-game test.

1. Use an MP build exposing exactly `ChimMindPoisoning\MIND_POISONING_INTIMACY_API_VERSION = 1` and `mindPoisoningReadIntimacyArrangement()`. MP recognition has its own independent default-off setting; see its tagged arrangement guide.
2. In SHARMAT's existing relationship-type settings, explicitly enable **Use current directed intimacy arrangements**. The saved key is `use_intimacy_arrangements`; old settings default to false and an older settings client omitting it preserves the saved value.
3. A valid active exact-pair report can satisfy ordinary type/liking eligibility. Applicable protection, orientation, role, affair, arousal, current refusal, target, scene and native restrictions still apply. Missing, older, invalid, pruned-source, ended or unavailable API results add no lane. Ended returns to ordinary policy; it is not a permanent ban.

MP owns recognition, directed state, source validation, storage and the read API. SHARMAT owns action availability and execution policy. PCV keeps scene/receipt ownership. No hidden sibling-path dependency, settings write, shared relationship engine or provider call is introduced in this consumer.

## Important boundaries

- Player and NPC targets remain separate. An eligible NPC cannot restore independently ineligible Player tools or Player-only AcceptSex. Final action filtering re-reads current state; an old prompt/schema is insufficient after an ending.
- Player orientation is rechecked for the new regular arrangement route. The inspected NPC-pair gate has no separate target-orientation predicate; this contribution preserves that existing policy rather than inventing one.
- Existing scene-consent predicates remain ordinary-only by default. The new report alone does not set acceptance. A narrow fallback for an already accepted Player action requires the recorded Player partner, Player in the current scene roster, a non-NPC-only scene and current applicable eligibility. Existing acceptance carryover time/tier checks remain. NPC-only observed scenes retain SHARMAT's existing scene lane.
- Casual reports add non-romantic context and suppress automatic crush conversion for the exact Player pair even with the action-eligibility option off. They do not undo later intentional changes or alter affinity.
- Bounded offer-pass memoization avoids repeating the same MP read for every action. It is cleared before final dispatch. Context for exact current NPC targets can coexist with dislike; saved custom prompt text remains the operator's text.
- The existing StartSex funcret callback can update bookkeeping on a failed native result. This pre-existing behavior is not repaired here, and an accepted server command is not proof of native scene success or recipient consent.

## Reproduce the focused checks

Use a standalone PHP 8.3.35 CLI and Node. Set temporary directories to task-owned scratch space and do not boot an installed CHIM server:

```powershell
$env:PHP_BINARY = '<standalone PHP>/php.exe'
$env:SHARMAT_TEST_TMP = '<task-owned existing temporary directory>'
& $env:PHP_BINARY -n tests/arrangement_consumer_test.php
node tests/arrangement_settings_test.mjs
& $env:PHP_BINARY -n -d 'extension=<PHP ext>/php_mbstring.dll' tests/arrangement_mp_integration_test.php '<explicit MP 0.1.26 source checkout>'
```

Fixtures extract production functions/callbacks and replace DB, roster, native command and model collaborators. The cross-plugin fixture calls the real MP classifier, persistence and read API with a synthetic StoreDb. They are not a separately reimplemented policy or live server. Exact final outputs and source hashes are recorded with the contribution task receipt.

No live database, provider credentials/quota, WSL distro, installed mods, game data, native OStim lifecycle or gameplay is exercised by these checks. The existing custom-type/Open Mode regressions remain separate evidence.

## Smallest later gameplay check

With a separately authorized isolated setup or database backup, establish a clearly current casual report, verify affinity/type remain independent, and observe an eligible exact partner offer and real native participants. Separately observe the recipient's decision. Exercise an ending between offer and dispatch, an explicit refusal, an ineligible or protected target and an unaffected ordinary baseline. Test actual Player acceptance/carryover and an NPC-only scene separately. Native success, dialogue consistency and model recognition require their own evidence; a label and a captured command are insufficient.