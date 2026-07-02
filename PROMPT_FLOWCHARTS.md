# SHARMAT — Prompt Flow Charts

One chart per path. Every box that injects text names the prompt key(s) involved (all editable in the UI;
reset buttons restore shipped defaults). Built 2026-07-02 against the live system.

---

## 1. Player → NPC: normal (non-scene) turn

What stacks into her context on an ordinary chat turn.

```mermaid
flowchart TD
    A[Normal turn for NPC] --> B[Character block: bio + personality]
    B --> C[Relationship context<br/><i>tier reference + Player line<br/>aff, tier, type, notes</i>]
    C --> D{Affinity tier?}
    D -->|any| E[Tier overhead prompt<br/><b>tier_&lt;tier&gt;</b> e.g. tier_neutral, tier_fond<br/>CRITICAL FOR ROLEPLAY framing]
    E --> F{Married?<br/>spouse_names set}
    F -->|to player| G[<b>tier_marriage_*</b> addendum]
    F -->|to another| H[Affair awareness addendum<br/>+ Devoted floor applies to consent]
    F -->|single| I[no addendum]
    G --> J{Fond+ AND eligible rel type?}
    H --> J
    I --> J
    J -->|yes| K[<b>intimacy_autonomy_nudge</b><br/>you may initiate + sex toolset granted]
    J -->|no| L[No sex initiators offered<br/>rel-type gate strips them]
    K --> M[Overlays if active:<br/>drunk stage prompt, skooma level,<br/>DD device awareness, fertility state]
    L --> M
```

---

## 2. Player → NPC: the consent ladder (scene attempt / tier-3 moment)

Evaluated the moment intimacy escalates to tier 3 (explicit). This is THE decision tree.

```mermaid
flowchart TD
    A[Tier-3 moment reached] --> B{Slave?}
    B -->|yes| S[SLAVE PATH — chart 6<br/>own prompts, no consent ask]
    B -->|no| C{Prostitute?}
    C -->|yes| P[PROSTITUTE PATH — chart 7<br/>business framing, payment]
    C -->|no| D{Rel type checked<br/>in the UI list?}
    D -->|no| E[POLITE HARD REFUSE<br/><b>prompt_friendly/fond/devoted/bonded</b><br/>per current tier, from Rel Types UI<br/>AcceptSex stripped, RefuseSex offered]
    D -->|yes| F{Married to<br/>someone else?}
    F -->|yes, aff < 76 Devoted| E
    F -->|yes, aff ≥ 76| G
    F -->|no| H{Affinity ≥ 31<br/>Friendly?}
    H -->|no| E
    H -->|yes| G{Affinity ≥ 56<br/>Fond?}
    G -->|no: Friendly..Fond| I[CONSENT ASK<br/><b>consent_decision_prompt</b><br/>choose: AcceptSex or RefuseSex<br/>style cues stripped until she decides]
    G -->|yes: Fond+| J[AUTONOMY<br/>no ask needed — her initiation or<br/>acceptance flows naturally<br/>model-driven consent allowed]
    I -->|AcceptSex| K[ENGAGED — chart 3]
    I -->|RefuseSex| R[REFUSAL — chart 4]
    E -->|RefuseSex| R
    J --> K
```

---

## 3. In-scene turn (accepted / engaged)

```mermaid
flowchart TD
    A[Scene event: animation change,<br/>chatnf_sl, ext_nsfw_sexcene] --> B[Scene context<br/>current_scene + scene description<br/>from the scenes DB table]
    B --> C[Sex personality<br/><b>sex_prompt</b> per-NPC, AI-generated]
    C --> D[Speak style cue<br/><b>content</b> of her sex_speech_style<br/>via deferred SCENE_CUE_OVERRIDE]
    D --> E[Profanity level<br/><b>profanity_1..5</b>]
    E --> F{Kinks unlocked?<br/>aff ≥ unlock tiers,<br/>group = lowest partner}
    F -->|normal ≥56| G[<b>normal_kinks_template</b><br/>ask for ONE at random]
    F -->|secret ≥76| H[<b>secret_kinks_template</b>]
    F -->|no| I[skip]
    G --> J{Gagged? DD}
    H --> J
    I --> J
    J -->|yes| K[Muffle: cue swapped to<br/>non-explicit variant + device_gag note]
    J -->|no| L[explicit cue stands]
    K --> M{Orgasm event?}
    L --> M
    M -->|consented per eligibility| N[Pleasure climax cue<br/><b>climax_prompt</b> of speak style]
    M -->|NOT consented| O[BOUNDARY cue<br/>non-consent framing<br/>+ witness broadcast if aff ≤ 55]
    N --> Q[Scene end → pillow talk<br/><b>pillow_talk_prompt</b>]
    O --> Q2[Scene end → no pillow talk<br/>consequence engine remembers]
```

---

## 4. Refusal path

```mermaid
flowchart TD
    A[Decline directive active<br/>or she decides no] --> B{Model calls<br/>RefuseSex tool?}
    B -->|no — narrates only| C[Refusal in dialogue only<br/>scene continues on BOUNDARY path<br/>she never reads as consenting<br/><i>model requirement: tool-calling</i>]
    B -->|yes| D[scene_phase = rejected<br/>refusal STICKY until scene end]
    D --> E{Exit-locked?<br/>drunk ≥ 7 or sleeping tree sap}
    E -->|yes| F[REFUSES BUT CANNOT LEAVE<br/>scene continues, boundary framing<br/>consequence engine]
    E -->|no| G[Hard stop queued<br/>ExtCmdStopScene → OStim + SexLab end]
    D --> H[Witness broadcast<br/>forcing themselves line to<br/>bystanders who can perceive]
```

---

## 5. NPC → NPC scenes

```mermaid
flowchart TD
    A[Background scene starts<br/>ext_nsfw_npc_scene] --> B[Thread registered<br/>per-pair thread key]
    B --> C{NPC affinity<br/>gating enabled?}
    C -->|disabled| D[Both consent automatically<br/>kinks unlocked]
    C -->|enabled| E[Affinity between the PAIR checked<br/>low affinity → scene stopped]
    D --> F[Rotating call-and-response<br/>global speech cooldown paces lines]
    E --> F
    F --> G[#PRIMARY_PARTNER# resolves to<br/>the OTHER NPC — never the player]
    G --> H[Same speak style / kinks / climax<br/>prompts as player scenes,<br/>partner-substituted]
```

---

## 6. Slave path (own system — consent ladder does not apply)

```mermaid
flowchart TD
    A[is_slave NPC] --> B[<b>slavery_fiction_frame</b><br/>global frame, Prompts tab]
    B --> C[Relationship overhead by tier<br/><b>relationship_overhead_slave_*</b><br/>devoted ↔ resentful voice]
    C --> D[Slave speak style<br/><b>slave_speak_styles</b> per-NPC]
    D --> E[Ambient idles by tier<br/>drink tray / sweeping HOLD<br/>pray / bow auto-release]
    E --> F[Scene: no consent ask ever<br/>slave climax prompts<br/>positive / neutral / negative]
    F --> G[Poison arm: hateful/hostile tiers<br/>may poison master's food<br/>consumption-verified]
```

---

## 7. Prostitute path (own system)

```mermaid
flowchart TD
    A[is_prostitute NPC] --> B[Business framing<br/><b>tier_prost_&lt;tier&gt;</b> prompts<br/>price by affinity tier]
    B --> C{Payment?}
    C -->|pending| D[Service withheld<br/>payment_pending framing]
    C -->|paid or free-service| E[Scene proceeds<br/>business speak style<br/>personal kinks SUPPRESSED]
    E --> F[CollectPayment tool<br/>gold actually transfers]
```

---

## 8. Overlays that layer onto ANY path

```mermaid
flowchart TD
    A[Any turn] --> B{Drunk stage 1-10?<br/>drinks in 12h game window}
    B -->|3+| C[<b>drunk_stage_N</b> prompt<br/>+ TTS slur + mood + OAR sway 5+<br/>stumble/ragdoll 6+ , exit-lock 7+]
    A --> D{Skooma L1-L3?}
    D -->|yes| E[Skooma state prompts<br/>dance/crazed idles<br/>addiction treadmill L2→L3]
    A --> F{Devious Devices worn?}
    F -->|yes| G[<b>dd_awareness</b> device list<br/>gag → muffle swap<br/>belt → access framing]
    A --> H{Fertility Mode?}
    H -->|yes| I[fertility_state block<br/>pregnancy stages, father]
    A --> J{Whiskey dick window?}
    J -->|yes| K[Sex initiators withheld<br/>impotence framing for duration]
```

---

### Reading order for newcomers
Chart 1 is every ordinary conversation. Chart 2 is the single most important one — it decides
whether intimacy can even happen. Charts 3/4 are inside and out of scenes. 6/7 replace chart 2
entirely for flagged NPCs. Chart 8 rides on top of everything.
