Scriptname AIAgentNSFWSceneEngine Hidden
{NPC-initiated scene START / JOIN / group for OStim AND SexLab. All Global - no CK attachment needed; call as
 AIAgentNSFWSceneEngine.StartOrJoinScene(...). This is the EXECUTION helper only; AIAgentNSFW.CommandManager stays
 the entry point and delegates here (nothing orphaned). Scene selection is STEERED by the requested act (sceneAct)
 so the model reaches the full library by position/act instead of a random pick; it falls back to random/default
 when no tagged scene matches, so a scene always starts. Created 2026-06-29.}

; ============================================================
; ENGINE DETECTION
; ============================================================
bool Function HasOStim() global
    return (Game.GetFormFromFile(0x000801, "Ostim.esp") as Quest) != None
EndFunction

SexLabFramework Function GetSexLab() global
    return Game.GetFormFromFile(0xD62, "SexLab.esm") as SexLabFramework
EndFunction

; ============================================================
; ACT -> ENGINE VOCAB. sceneAct is a normalized keyword from CommandManager:
; "vaginal" "anal" "oral" "handjob" "boobjob" or "" (empty = any/random). Each engine translates it into its own
; tag/action vocabulary so the same call steers either OStim or SexLab. Unknown/empty act -> "" (any scene).
; ============================================================
string Function OStimActionCSVForAct(string sceneAct) global
    if sceneAct == "vaginal"
        return "vaginalsex,vaginal,VaginalSex,Vaginal"
    elseif sceneAct == "anal"
        return "analsex,anal,AnalSex,Anal"
    elseif sceneAct == "oral"
        return "blowjob,cunnilingus,lickingpenis"
    elseif sceneAct == "handjob"
        return "handjob"
    elseif sceneAct == "boobjob"
        return "boobjob"
    elseif sceneAct == "masturbation"
        return "malemasturbation,femalemasturbation"
    endif
    return ""
EndFunction

; Some acts (e.g. massage) are not OStim "action types" but scene TAGS - resolve those here.
string Function OStimSceneTagForAct(string sceneAct) global
    if sceneAct == "massage"
        return "massage,foreplay,sensual"
    elseif sceneAct == "bloodfeed"
        ; vampire blood-feeding: matches OStim vampire-bite scenes (OARE_Devour*, OStim2P*VampireBite*).
        ; MinAI maps the "vampire" tag to "vampirebite" (minai_SexOstim), so these tags resolve the bite scenes.
        return "vampirebite,vampire,bite,neckkissing,feeding,devour"
    elseif sceneAct == "kiss"
        return "kissing,frenchkissing"
    elseif sceneAct == "hug"
        return "cuddling,hugging,embrace"
    elseif sceneAct == "holdhands"
        return "holdinghands,handholding"
    endif
    return ""
EndFunction

; Chaste OARE staples by exact scene id - the tag pass can miss these (OARE_StandingHandHolding is
; tagged only "oare"), and the affection acts must NEVER fall through to a random (possibly sex) scene.
string Function OStimExactSceneForAct(string sceneAct) global
    if sceneAct == "kiss"
        return "OARE_StandingKiss"
    elseif sceneAct == "hug"
        return "OARE_StandingHug"
    elseif sceneAct == "holdhands"
        return "OARE_StandingHandHolding"
    endif
    return ""
EndFunction

string Function SexLabTagsForAct(string sceneAct) global
    if sceneAct == "vaginal"
        return "Vaginal"
    elseif sceneAct == "anal"
        return "Anal"
    elseif sceneAct == "oral"
        return "Oral,Blowjob"
    elseif sceneAct == "handjob"
        return "Handjob"
    elseif sceneAct == "boobjob"
        return "Boobjob"
    elseif sceneAct == "masturbation"
        return "Masturbation,Solo"
    elseif sceneAct == "massage"
        return "Massage,Foreplay"
    elseif sceneAct == "bloodfeed"
        return "Bite,Vampire,VampireFeed,Neck,Nibble,Feeding"
    endif
    return ""
EndFunction

; ============================================================
; PUBLIC ENTRY - start a scene between speaker+target, or (if one is already in a live scene and bAllowJoin)
; add the other into it (group / join via stop+restart with the combined actor list). sceneAct steers which
; position/animation is chosen. Returns true if handled.
; ============================================================
bool Function StartOrJoinScene(Actor akSpeaker, Actor akTarget, bool bAllowJoin = true, string sceneAct = "") global
    if akSpeaker == None || akTarget == None || akSpeaker == akTarget
        Debug.Trace("[CHIM-NSFW SceneEngine] StartOrJoinScene aborted: missing/identical actors")
        return false
    endif
    if HasOStim()
        return StartOrJoinOStim(akSpeaker, akTarget, bAllowJoin, sceneAct)
    endif
    if GetSexLab() != None
        return StartOrJoinSexLab(akSpeaker, akTarget, bAllowJoin, sceneAct)
    endif
    Debug.Trace("[CHIM-NSFW SceneEngine] No OStim or SexLab detected")
    return false
EndFunction

; ============================================================
; PUBLIC ENTRY - SOLO scene (e.g. self-masturbation) for a single actor. Returns true if handled.
; ============================================================
bool Function StartSoloScene(Actor akActor, string sceneAct = "") global
    if akActor == None
        Debug.Trace("[CHIM-NSFW SceneEngine] StartSoloScene aborted: missing actor")
        return false
    endif
    if HasOStim()
        if OActor.GetSceneID(akActor) >= 0
            return true ; already in a scene
        endif
        Actor[] solo = new Actor[1]
        solo[0] = akActor
        Debug.Trace("[CHIM-NSFW SceneEngine] OStim: starting solo scene " + akActor.GetDisplayName() + " act=" + sceneAct)
        return StartOStimScene(solo, sceneAct) >= 0
    endif
    SexLabFramework slf = GetSexLab()
    if slf != None
        if slf.FindActorController(akActor) >= 0
            return true ; already in a scene
        endif
        Actor[] solo = new Actor[1]
        solo[0] = akActor
        Debug.Trace("[CHIM-NSFW SceneEngine] SexLab: starting solo scene " + akActor.GetDisplayName() + " act=" + sceneAct)
        return StartSexLabScene(slf, solo, sceneAct)
    endif
    Debug.Trace("[CHIM-NSFW SceneEngine] No OStim or SexLab detected (solo)")
    return false
EndFunction

; ============================================================
; OSTIM
; ============================================================
bool Function StartOrJoinOStim(Actor akSpeaker, Actor akTarget, bool bAllowJoin, string sceneAct = "") global
    int speakerThread = OActor.GetSceneID(akSpeaker)
    int targetThread = OActor.GetSceneID(akTarget)
    int activeThread = speakerThread
    if activeThread < 0
        activeThread = targetThread
    endif

    if activeThread < 0
        ; neither is in a scene -> brand new scene with both, steered by sceneAct
        Actor[] pair = new Actor[2]
        pair[0] = akSpeaker
        pair[1] = akTarget
        Debug.Trace("[CHIM-NSFW SceneEngine] OStim: starting new scene " + akSpeaker.GetDisplayName() + " + " + akTarget.GetDisplayName() + " act=" + sceneAct)
        return StartOStimScene(pair, sceneAct) >= 0
    endif

    ; Both already in the SAME scene -> SHIFT the current scene to the requested act (e.g. she's asked for a blowjob
    ; while you're mid-hug/kiss). Without this, a Start* act on two actors already together was a no-op and the scene
    ; never changed - the "asked for a BJ but stayed hugging" bug.
    if speakerThread >= 0 && targetThread >= 0 && speakerThread == targetThread
        if sceneAct != ""
            return ShiftOStimSceneToAct(speakerThread, sceneAct)
        endif
        return true
    endif

    if !bAllowJoin
        Debug.Trace("[CHIM-NSFW SceneEngine] OStim: scene live but joining disabled")
        return false
    endif

    ; one of them is already in a live scene -> the OTHER one joins it (group)
    Actor joiner = akSpeaker
    if speakerThread >= 0
        joiner = akTarget
    endif
    Debug.Trace("[CHIM-NSFW SceneEngine] OStim: joining thread " + activeThread + " with " + joiner.GetDisplayName())
    return AddActorToOStimThread(activeThread, joiner)
EndFunction

int Function StartOStimScene(Actor[] actors, string sceneAct = "") global
    string scene = ""
    string actionCSV = OStimActionCSVForAct(sceneAct)
    if actionCSV != ""
        scene = OLibrary.GetRandomSceneWithAnyActionCSV(actors, actionCSV)
    endif
    if scene == ""
        string tagCSV = OStimSceneTagForAct(sceneAct)
        if tagCSV != ""
            scene = OLibrary.GetRandomSceneWithAnySceneTagCSV(actors, tagCSV)
        endif
    endif
    if scene == ""
        scene = OStimExactSceneForAct(sceneAct) ; affection acts pin their OARE staple, never a random scene
    endif
    if scene == ""
        scene = OLibrary.GetRandomScene(actors) ; fallback: any valid scene for this actor set
    endif
    int builderID = OThreadBuilder.Create(actors)
    if scene != ""
        OThreadBuilder.SetStartingAnimation(builderID, scene)
    endif
    return OThreadBuilder.Start(builderID)
EndFunction

bool Function AddActorToOStimThread(int threadID, Actor joiner) global
    Actor[] current = OThread.GetActors(threadID)
    if current.Length < 1 || current.Length >= 5
        return false
    endif
    if current.Find(joiner) >= 0
        return true ; already in this scene - nothing to do (e.g. StartSex when already partnered)
    endif
    Actor[] combined = PapyrusUtil.PushActor(current, joiner)
    OThread.Stop(threadID)
    int guard = 0
    while OThread.IsRunning(threadID) && guard < 50
        Utility.Wait(0.2)
        guard += 1
    endwhile
    return StartOStimScene(combined, "") >= 0
EndFunction

; Shift the CURRENT OStim scene (same actors) to a scene matching the requested act, via a smooth WarpTo. Used when
; both actors are already together and the model calls a Start* act to escalate/redirect (e.g. blowjob mid-hug).
bool Function ShiftOStimSceneToAct(int threadID, string sceneAct) global
    Actor[] actors = OThread.GetActors(threadID)
    if actors.Length < 1
        return false
    endif
    string scene = ""
    string actionCSV = OStimActionCSVForAct(sceneAct)
    if actionCSV != ""
        scene = OLibrary.GetRandomSceneWithAnyActionCSV(actors, actionCSV)
    endif
    if scene == ""
        string tagCSV = OStimSceneTagForAct(sceneAct)
        if tagCSV != ""
            scene = OLibrary.GetRandomSceneWithAnySceneTagCSV(actors, tagCSV)
        endif
    endif
    if scene == "" && actors.Length == 2
        scene = OStimExactSceneForAct(sceneAct) ; e.g. shift a hug to hand-holding (tagged only "oare")
    endif
    if scene == ""
        Debug.Trace("[CHIM-NSFW SceneEngine] OStim shift: no scene matched act=" + sceneAct + " - leaving current scene")
        return false
    endif
    Debug.Trace("[CHIM-NSFW SceneEngine] OStim shift: thread " + threadID + " -> " + scene + " (act=" + sceneAct + ")")
    OThread.WarpTo(threadID, scene, true)
    return true
EndFunction

; ============================================================
; SEXLAB
; ============================================================
bool Function StartOrJoinSexLab(Actor akSpeaker, Actor akTarget, bool bAllowJoin, string sceneAct = "") global
    SexLabFramework slf = GetSexLab()
    if slf == None
        return false
    endif
    int speakerThread = slf.FindActorController(akSpeaker)
    int targetThread = slf.FindActorController(akTarget)
    int activeThread = speakerThread
    if activeThread < 0
        activeThread = targetThread
    endif

    if activeThread < 0
        Actor[] pair = new Actor[2]
        pair[0] = akSpeaker
        pair[1] = akTarget
        Debug.Trace("[CHIM-NSFW SceneEngine] SexLab: starting new scene " + akSpeaker.GetDisplayName() + " + " + akTarget.GetDisplayName() + " act=" + sceneAct)
        return StartSexLabScene(slf, pair, sceneAct)
    endif

    ; Both already in the SAME SexLab scene -> SHIFT to the requested act (parity with the OStim
    ; ShiftOStimSceneToAct path). SexLab has no in-thread WarpTo, so ShiftSexLabScene does a quick stop+restart
    ; with animations matching the new act. Same actors, new act (e.g. she asks for a blowjob mid-scene).
    if speakerThread >= 0 && targetThread >= 0 && speakerThread == targetThread
        if sceneAct != ""
            return ShiftSexLabScene(slf, speakerThread, sceneAct)
        endif
        return true
    endif

    if !bAllowJoin
        return false
    endif

    Actor joiner = akSpeaker
    if speakerThread >= 0
        joiner = akTarget
    endif
    Debug.Trace("[CHIM-NSFW SceneEngine] SexLab: joining controller " + activeThread + " with " + joiner.GetDisplayName())
    return AddActorToSexLabThread(slf, activeThread, joiner)
EndFunction

bool Function StartSexLabScene(SexLabFramework slf, Actor[] actors, string sceneAct = "") global
    int males = CountMales(actors)
    int females = actors.Length - males
    sslBaseAnimation[] anims = slf.GetAnimationsByDefault(males, females) ; default baseline (always valid)
    string tags = SexLabTagsForAct(sceneAct)
    if tags != ""
        sslBaseAnimation[] tagged = slf.GetAnimationsByTags(actors.Length, tags, "", false)
        if tagged.Length < 1 && sceneAct == "bloodfeed"
            ; No vampire-tagged SexLab animations installed (vanilla registry has none) - prefer an embrace-style
            ; foreplay/leadin scene over a random default so the feed still reads as a neck bite (fix 2026-07-01)
            tagged = slf.GetAnimationsByTags(actors.Length, "Foreplay,LeadIn", "", false)
        endif
        if tagged.Length >= 1
            anims = tagged ; steer to the requested act when matches exist
        endif
    endif
    return slf.StartSex(actors, anims) >= 0
EndFunction

bool Function AddActorToSexLabThread(SexLabFramework slf, int threadID, Actor joiner) global
    sslThreadController ctrl = slf.GetController(threadID)
    if ctrl == None
        return false
    endif
    Actor[] current = ctrl.Positions
    if current.Length < 1 || current.Length >= 5
        return false
    endif
    if current.Find(joiner) >= 0
        return true ; already in this scene - nothing to do
    endif
    Actor[] combined = PapyrusUtil.PushActor(current, joiner)
    ctrl.EndAnimation(true)
    Utility.Wait(1.0)
    return StartSexLabScene(slf, combined, "")
EndFunction

; Shift the CURRENT SexLab scene (same actors) to the requested act. SexLab has NO in-thread position swap (no
; OThread.WarpTo equivalent), so this does a quick EndAnimation + StartSex restart with animations matching the new
; act. If no animation matches, it leaves the current scene running (no pointless teardown) - mirroring the OStim
; ShiftOStimSceneToAct "leave current scene on miss" behaviour.
bool Function ShiftSexLabScene(SexLabFramework slf, int threadID, string sceneAct) global
    sslThreadController ctrl = slf.GetController(threadID)
    if ctrl == None
        return false
    endif
    Actor[] actors = ctrl.Positions
    if actors.Length < 1
        return false
    endif
    string tags = SexLabTagsForAct(sceneAct)
    if tags == ""
        return false ; unknown/empty act - nothing to steer to, leave the scene as-is
    endif
    sslBaseAnimation[] tagged = slf.GetAnimationsByTags(actors.Length, tags, "", false)
    if tagged.Length < 1 && sceneAct == "bloodfeed"
        ; same embrace-style fallback as StartSexLabScene (fix 2026-07-01)
        tagged = slf.GetAnimationsByTags(actors.Length, "Foreplay,LeadIn", "", false)
    endif
    if tagged.Length < 1
        Debug.Trace("[CHIM-NSFW SceneEngine] SexLab shift: no animation matched act=" + sceneAct + " - leaving current scene")
        return false
    endif
    Debug.Trace("[CHIM-NSFW SceneEngine] SexLab shift: thread " + threadID + " -> act=" + sceneAct + " (" + tagged.Length + " anims)")
    ctrl.EndAnimation(true)
    Utility.Wait(1.0)
    return slf.StartSex(actors, tagged) >= 0
EndFunction

int Function CountMales(Actor[] actors) global
    int males = 0
    int i = 0
    while i < actors.Length
        if actors[i] != None && actors[i].GetActorBase() != None && actors[i].GetActorBase().GetSex() == 0
            males += 1
        endif
        i += 1
    endwhile
    return males
EndFunction
