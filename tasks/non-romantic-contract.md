# Bounded non-romantic intimacy contract

## Intent and ownership

Given an explicitly authored directed non-romantic relationship and explicit SHARMAT settings, SHARMAT must preserve custom selections and designated non-romantic meaning during its own automatic affection/crush paths. Relationship state remains CHIM/MP data; SHARMAT owns action policy and its settings. No type aliasing to romantic, reverse-edge writes, implicit agreement, current-consent assertion or hidden settings repair. This task does not infer or establish arrangements from gossip.

SHARMAT baseline123c98d95ce7a54dada6ab34f91d2f4eabe3dffb. Prior proofs at f1b91a4 are reused only after relevant Git/blob or normalized source agreement is verified; Windows line-ending differences alone are not new behavior. Raw-hash disagreements must be explained rather than ignored. Current MP and complete action dispatch are separate read-only integration checks before claiming their support.

## Configuration contract — approved before settings edits

Use the existing aiagent_nsfw_reltypes configuration; retain built-in defaults and add an explicit non_romantic_types list defaulting to empty. This designation does not add eligibility, rewrite stored relationships, freeze intentional relationship changes, or disable automatic crush globally.

Pure existing-file seam: aiagentNsfwNormalizeRelTypeConfig($config, $readStatus = 'present') in open_mode_policy.php. It returns status, valid, enabled, eligible_types, non_romantic_types. DB/JSON boundaries distinguish present, missing, malformed and error; the pure helper performs no reads or writes.

- Absent row/legacy empty value: existing documented default types romantic/crush/ex, gate enabled, no non-romantic designation.
- A valid legacy object missing new fields retains compatible defaults; explicit eligible_types=[] is valid deny-all when gating is enabled.
- Malformed payload, malformed lists, invalid enabled flag or read failure: valid=false, enabled=true, empty lists. Never convert this into gate-off or allow-all. Reject invalid save payload without writing.
- Explicit validated enabled=false and explicit Open Mode retain their existing owned behavior; no new bypass.
- Lists contain canonicalized nonempty strings, preserve custom labels, and deduplicate. Reject nested or non-list values. JSON object/list distinction and strict enabled boolean handling belong at the validated boundary.
- Render all configured custom values alongside built-ins, escaped. Save eligible and non-romantic selections independently. An explicit custom-entry UI path must not automatically check either designation or eligibility. Existing custom-only configuration survives ordinary save/reload; deliberate deselection takes effect.
- Do not silently remove an existing new field when an older client omits it; preserve omitted non_romantic_types, while an explicit empty list clears it.

## Runtime contract — implemented and reviewed locally

All relevant type eligibility/refusal/crush consumers use that same normalized policy through one existing-runtime read seam. Built-in/default behavior stays unchanged except the explicitly requested correction of empty/error broadening. The per-type designation protects only SHARMAT automatic conversion; later legitimate native/manual relationship edits are not undone.

For action offers, assess the actual speaker and intended resolvable recipients using existing target, scope and scene facilities. Offering a start toward an eligible NPC must not offer or validate it toward an ineligible Player. Keep Player-only consent/control actions out of an NPC-only eligible offer. Retain existing special/OpenMode lanes and applicable protected/excluded/combat/framework/scene/refusal restrictions; do not create a new consent engine.

Revalidate the normalized action actor, target(s), actual action family and applicable current restrictions at the final supported point before native dispatch, after any earlier target rewriting. Reject malformed, substituted, unresolvable, ambiguous or out-of-scope targets without redirecting them to the Player. Keep text/structured action surfaces consistent enough to describe the same allowed offer; identify any captured-local or unsupported timing boundary rather than claiming visibility proves dispatch.

Add bounded relationship-context wording only where existing prompts would contradict an explicitly designated non-romantic current edge. Do not rewrite unrelated prompts or claim model compliance from a string fixture.

## Evidence and safety

Use production helpers/renderers/save handlers and real action callbacks with synthetic data and deterministic substitutes. Separate offered, accepted, dispatched and executed. A fixture must say which stage it reaches. No provider, live/real DB, installation, game/installed-mod access, gaming distro start/entry, CHIM/OStim product change, release/version update, fork/push/PR/publication. No teardown changes. Genuine compilation is required only if compiled scripts change; none is assumed needed.

## Falsifiers

The contract is not satisfied if save/reload loses the custom type, malformed config broadens access, SHARMAT automatic conversion overwrites the designated edge, NPC eligibility makes an ineligible Player eligible, a forged or late-substituted target passes, any existing applicable restriction is removed, or emitted context invents romance/mutual agreement. A provider/gameplay claim remains unverified until a separately authorized controlled session demonstrates it.

## Runtime adjudication before implementation

SHARMAT currently has a one-way outbound initiation policy. Its active native start path does not implement an independently verified recipient-acceptance gate; the later legacy Player-consent menu is unreachable for these start commands because the active branch returns first. Preserve that existing policy rather than inventing reciprocal consent from labels or constructing a new consent engine. Eligibility and permitted command emission do not prove recipient agreement or a successful scene.

A roster/enum candidate is not enough by itself. Eligible NPC-only initiation may expose only the relevant start family toward those exact recipients, never Player-only AcceptSex/control tools or an ineligible Player. Recheck all normalized targets and the acting speaker after prior target rewriting, with current applicable restrictions and offered action state. Do not fuzzy-retarget unknown names to Player.

Native command parsing has two concrete hazards: a target name containing the Player display name can resolve to Player, and a one-NPC StartThreesome target implicitly adds Player. Omit/reject ambiguous native names and independently require Player eligibility for that one-NPC form. Preserve the documented multi-recipient CSV StartThreesome protocol, validate every canonical recipient and its applicable route, and reject duplicates, unknown/self/out-of-scope names or overflow instead of truncating. Scalar start commands remain scalar. Use existing string-schema facilities and truthful per-action guidance rather than a contradictory singleton enum or a new combinatorial protocol. Compiled game scripts are unchanged.

## Read-boundary finding
Pinned CHIM fetchOne returns [] for both no rows and SQL failure. A plain optional-row SELECT cannot substantiate the promised distinction between missing config and read failure. Owners must use an existing-API guaranteed-row scalar query (or an equally small evidenced boundary) so a successful missing setting yields an explicit blank value row and an absent result remains an error. Do not add private connection access or a storage framework.


## Existing refusal wording preservation
The runtime reader additionally returns a fixed prompts map for the five existing refusal prompt keys. It includes only valid string values from a valid saved configuration; missing/invalid/error uses an empty map and the existing consumer defaults. The pure normalizer interface remains unchanged. This preserves user-authored wording in the same read and avoids adding a second unvalidated read or a generic configuration accessor.


## Preserve Player offers without a second lifecycle engine
NPC eligibility must not broaden a Player action that the existing builder withheld for arousal, scene-call affinity, decision, service, cooldown or other applicable gates. Capture the existing per-action Player start availability before adding NPC starts, and use that request-local evidence with the current owned type/special/safety checks. New NPC offers must respect an actual hard Player decision state; do not invent blanket level or tier cutoffs that remove existing autonomy or instruction/funcret behavior. Required negative control: an eligible NPC does not make a type-eligible but below-scene-floor Player edge eligible.
