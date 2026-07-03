Scriptname AIAgentNSFW extends Quest
import Utility

; ============================================================================
; AIAgentNSFW - VR Integration for CHIM/Herika
; ============================================================================
;
; This script contains BOTH:
;   1. VR ITEM TRACKING (CHIM CORE READY) - Lines marked with [CHIM-CORE]
;   2. NSFW TOUCH/GROPE DETECTION (NSFW ONLY) - Lines marked with [NSFW-ONLY]
;
; FOR RANGAROO/TYLER - MIGRATION GUIDE:
; ============================================================================
; To extract VR Item Tracking for core CHIM:
;
; PROPERTIES TO COPY [CHIM-CORE]:
;
; FUNCTIONS TO COPY [CHIM-CORE]:
;   - OnHIGGSObjectGrabbed() - item pickup detection
;   - OnHIGGSObjectDropped() - item drop detection
;   - SendVRItemEvent() - sends to PHP server
;
; LEAVE IN NSFW [NSFW-ONLY]:
;   - All CBPC collision handling (body part touch)
;   - All body node arrays (BreastNodes, ButtNodes, etc)
;   - OnCBPCCollision(), SendCorrelatedGrab(), etc
;   - Touch threshold tracking
;
; The PHP side is already cleanly separated:
;   - vr_items.php = CHIM CORE READY (no NSFW content)
;   - nsfw_physics.php = NSFW ONLY (body part groping)
; ============================================================================

int map
int descriptionsMap
int versionCheck = 1

int Property mdi auto
int Property mdo auto

Quest Property AIAgentPapyrusFunctionsQ  Auto

Bool Property isSceneRunningInvolvingPlayer  Auto

Faction Property noFacialExpressionsFaction Auto

; ============================================================================
; [NSFW-ONLY] CBPC Physics Touch Detection - Body part collision
; ============================================================================
bool Property enableCBPC = true Auto
float Property cbpcTouchThreshold = 0.5 Auto  ; How long touch must last to register
float Property cbpcCooldown = 2.0 Auto        ; Cooldown between physics messages

; ============================================================================
; [NSFW-ONLY] HIGGS Grab Detection - Groping NPCs
; ============================================================================
bool Property enableHIGGS = true Auto
bool hasHIGGS = false
Actor currentlyGrabbedActor = None
string currentlyGrabbedNode = ""
string currentlyGrabbedBodyPart = ""
float grabStartTime = 0.0
bool isGrabbing = false

; [NSFW-ONLY] HIGGS + CBPC Correlation (for determining WHERE on body the grab occurred)
; When HIGGS returns OTHER/unknown body part, we wait for CBPC to tell us the exact location
float Property grabCorrelationWindow = 1.25 Auto  ; Window for CBPC to identify exact body part after HIGGS
Actor pendingGrabActor = None
string pendingGrabHand = ""
float pendingGrabTime = 0.0
bool pendingGrabBlocked = false
string pendingGrabBlockedBy = ""
string pendingGrabBodyPart = ""  ; Fallback body part from HIGGS if CBPC doesn't fire
bool hasPendingGrab = false

; ============================================================================
; [CHIM-CORE] VR Item Tracking - What player is holding in each hand
; ============================================================================
; This section can be moved to core CHIM for general VR immersion
; No NSFW content - just tracks items like swords, potions, food, etc.

; VR Detection - auto-detected based on HIGGS presence, or can be set manually
; true = VR mode (use UIWheelMenu), false = flatscreen (use SkyMessage)
bool isVRMode = false
bool usePrismaConsent = false ; flip true + wire ShowPrismaConsent once CHIM ships Prisma confirmations
int npcSceneTalkCounter = 0
int consentBarkThreadId = -1 ; consent bark: last OStim thread we fired a fast accept/refuse decision for (one bark per scene)
int npcSceneActorStash = 0 ; JContainers JMap: "thr_<id>" -> JArray of participant display names (teardown fallback)
string lastResolvedOStimOrgasmActionType = ""
string OSTIM_ORGASM_ACTION_TYPES = "vaginalsex,VaginalSex,vaginal,Vaginal,vaginalpullout,analsex,AnalSex,anal,Anal,analpullout,blowjob,lickingpenis,lickingtesticles,rubbingpenisagainstface,cunnilingus,handjob,boobjob,footjob,thighjob,grindingthigh,buttjob,malemasturbation,femalemasturbation"
; Penetrative/primary acts ONLY - the orgasm partner is whoever the orgasmer is penetrating or penetrated by, not a
; secondary act (handjob/footjob) merely listed first. Searched FIRST so 3-ways credit the real partner (no FMR dep).
string OSTIM_PENETRATIVE_ACTION_TYPES = "vaginalsex,VaginalSex,vaginal,Vaginal,vaginalpullout,analsex,AnalSex,anal,Anal,analpullout,blowjob,deepthroat,cunnilingus"

Actor playerRef
int touchedLocations = 0
float lastPhysicsSpeechTime = 0.0
bool hitThreshold = false
float hitValue = 0.0
string locationHit = ""
bool collisionMutex = false

; Debouncing - track last reported touch to avoid duplicates
Actor lastReportedActor = None
string lastReportedBodyPart = ""
float lastReportedTime = 0.0

; Track which actors have custom colliders registered (for on-demand registration)
int actorsWithColliders = 0

string[] BreastNodes
string[] ButtNodes
string[] BellyNodes
string[] PenisNodes
string[] VaginalNodes
string[] AnalNodes
string[] ArmNodes
string[] LegNodes
string[] HandNodes
string[] FootNodes
string[] HeadNodes
string[] BackNodes
string[] ShoulderNodes

string BREASTS_KEY = "Breasts"
string BUTT_KEY = "Butt"
string VAGINAL_KEY = "Pussy"
string ANAL_KEY = "Anal"
string BELLY_KEY = "Belly"
string PENIS_KEY = "Penis"
string ARM_KEY = "Arm"
string LEG_KEY = "Leg"
string HAND_KEY = "Hand"
string FOOT_KEY = "Foot"
string HEAD_KEY = "Head"
string BACK_KEY = "Back"
string SHOULDER_KEY = "Shoulder"
string OTHER_KEY = "Body"
string ACTOR_KEY = "ActorName"
string ACTORREF_KEY = "ActorRef"
string GENITAL_COLLISION_KEY = "GenitalCollision"

; Devious Devices integration (soft dependency - no master required)
; Grab the zadLibs quest by its one FormID, read keyword props off it.
zadLibs libs
bool hasDeviousDevices = false

; ============================================
; DEVIOUS DEVICES INTEGRATION
; ============================================
; Initialize the DD library at runtime (soft - no ESP master needed)
Function InitDeviousDevices()
	hasDeviousDevices = false
	libs = None

	if (Game.GetModByName("Devious Devices - Integration.esm") == 255)
		Debug.Trace("[CHIM-NSFW] Devious Devices not installed")
		return
	endif

	libs = Game.GetFormFromFile(0x00F624, "Devious Devices - Integration.esm") as zadLibs
	if (libs)
		hasDeviousDevices = true
		Debug.Trace("[CHIM-NSFW] Devious Devices integration enabled")
	else
		Debug.Trace("[CHIM-NSFW] Devious Devices zadLibs not found")
	endif
EndFunction

; Build a CSV of the locked device types the actor is wearing (server maps these to prose)
string Function GetWornDevicesCSV(Actor akActor)
	if (!hasDeviousDevices || !akActor || !libs)
		return ""
	endif
	string csv = ""
	if (akActor.WornHasKeyword(libs.zad_DeviousBelt))
		csv += "belt,"
	endif
	if (akActor.WornHasKeyword(libs.zad_DeviousBra))
		csv += "bra,"
	endif
	if (akActor.WornHasKeyword(libs.zad_DeviousGag) || akActor.WornHasKeyword(libs.zad_DeviousGagPanel) || akActor.WornHasKeyword(libs.zad_DeviousGagLarge))
		csv += "gag,"
	endif
	if (akActor.WornHasKeyword(libs.zad_DeviousCollar))
		csv += "collar,"
	endif
	if (akActor.WornHasKeyword(libs.zad_DeviousArmbinder))
		csv += "armbinder,"
	endif
	if (akActor.WornHasKeyword(libs.zad_DeviousYoke))
		csv += "yoke,"
	endif
	if (akActor.WornHasKeyword(libs.zad_DeviousElbowTie))
		csv += "elbowtie,"
	endif
	if (akActor.WornHasKeyword(libs.zad_DeviousStraitJacket))
		csv += "straitjacket,"
	endif
	if (akActor.WornHasKeyword(libs.zad_DeviousBlindfold))
		csv += "blindfold,"
	endif
	if (akActor.WornHasKeyword(libs.zad_DeviousHobbleSkirt))
		csv += "hobbleskirt,"
	endif
	if (akActor.WornHasKeyword(libs.zad_DeviousArmCuffs))
		csv += "armcuffs,"
	endif
	if (akActor.WornHasKeyword(libs.zad_DeviousLegCuffs))
		csv += "legcuffs,"
	endif
	if (akActor.WornHasKeyword(libs.zad_DeviousAnkleShackles))
		csv += "ankleshackles,"
	endif
	if (akActor.WornHasKeyword(libs.zad_DeviousPlugVaginal))
		csv += "plugvaginal,"
	endif
	if (akActor.WornHasKeyword(libs.zad_DeviousPlugAnal))
		csv += "pluganal,"
	endif
	if (akActor.WornHasKeyword(libs.zad_DeviousClamps))
		csv += "clamps,"
	endif
	if (akActor.WornHasKeyword(libs.zad_DeviousCorset))
		csv += "corset,"
	endif
	if (akActor.WornHasKeyword(libs.zad_DeviousHood))
		csv += "hood,"
	endif
	if (akActor.WornHasKeyword(libs.zad_DeviousHarness))
		csv += "harness,"
	endif
	if (akActor.WornHasKeyword(libs.zad_DeviousGloves))
		csv += "gloves,"
	endif
	if (akActor.WornHasKeyword(libs.zad_DeviousSuit) || akActor.WornHasKeyword(libs.zad_DeviousPetSuit))
		csv += "suit,"
	endif
	if (akActor.WornHasKeyword(libs.zad_DeviousPiercingsNipple))
		csv += "piercingsnipple,"
	endif
	if (akActor.WornHasKeyword(libs.zad_DeviousPiercingsVaginal))
		csv += "piercingsvaginal,"
	endif
	return csv
EndFunction

; Push the actor's current device list to the server (fires on equip/remove)
Function SendDeviceState(Actor akActor)
	if (!hasDeviousDevices || !akActor)
		return
	endif
	AIAgentFunctions.logMessage(akActor.GetDisplayName() + "^" + GetWornDevicesCSV(akActor), "ext_nsfw_devices")
EndFunction

Event OnDDDeviceChanged(Form inventoryDevice, Form deviceKeyword, Form akActor)
	SendDeviceState(akActor as Actor)
EndEvent

; Check if actor is wearing chastity belt
bool Function IsWearingChastityBelt(Actor akActor)
	if (!hasDeviousDevices || !akActor || !libs)
		return false
	endif
	return akActor.WornHasKeyword(libs.zad_DeviousBelt)
EndFunction

; Check if actor is wearing chastity bra
bool Function IsWearingChastityBra(Actor akActor)
	if (!hasDeviousDevices || !akActor || !libs)
		return false
	endif
	return akActor.WornHasKeyword(libs.zad_DeviousBra)
EndFunction

; ============================================
; CONSENT MENU (UIExtensions list - works in VR and flatscreen)
; ============================================
; Shows a Yes/No list popup; the question is shown as a notification
; Returns true if user accepts, false if declined
bool Function ShowVRConsentMenu(string npcName, string actionDescription)
	Debug.Notification(npcName + " " + actionDescription)
	UIListMenu listMenu = UIExtensions.GetMenu("UIListMenu") as UIListMenu
	if !listMenu
		return false
	endif
	listMenu.AddEntryItem("Yes")
	listMenu.AddEntryItem("No")
	listMenu.OpenMenu()
	int ret = listMenu.GetResultInt()
	; 0 = Yes, 1 = No, -1 = cancelled
	if ret == 0
		return true
	else
		return false
	endif
EndFunction

; Prisma consent seam: returns 1=yes 0=no -1=unavailable (Rangroo's prisma-ui-confirmations, unreleased)
int Function ShowPrismaConsent(string npcName, string actionDescription)
	if !usePrismaConsent
		return -1
	endif
	; TODO(prisma): call CHIM's Prisma confirmation API here and map its result to 1/0
	return -1
EndFunction

; Consent gate: Prisma confirmation when available, otherwise auto-accept (no prompt without Prisma)
string Function GetPlayerConsent(string npcName, string actionDescription)
	int prismaResult = ShowPrismaConsent(npcName, actionDescription)
	if prismaResult == 1
		return "Yes, please!"
	elseif prismaResult == 0
		return "No, thanks"
	endif

	; No Prisma available -> auto-accept (ShowVRConsentMenu kept below to re-enable a fallback prompt if wanted)
	return "Yes, please!"
EndFunction

; ============================================
; HIGGS VR GRAB INTEGRATION
; ============================================
; Initialize HIGGS soft dependency and register for grab events
; Also detects VR mode (HIGGS only exists for Skyrim VR)
Function InitHIGGS()
	hasHIGGS = false
	isGrabbing = false
	currentlyGrabbedActor = None
	currentlyGrabbedNode = ""

	if !enableHIGGS
		Debug.Trace("[CHIM-NSFW] HIGGS integration disabled by config")
		return
	endif

	; Check if HIGGS is loaded - if yes, we're in VR
	if (Game.GetModByName("higgs_vr.esp") == 255)
		Debug.Trace("[CHIM-NSFW] HIGGS not installed - flatscreen mode")
		isVRMode = false
		return
	endif

	; HIGGS found = VR mode confirmed
	isVRMode = true
	Debug.Trace("[CHIM-NSFW] VR mode detected (HIGGS present)")

	; Register for HIGGS grab/drop events
	HiggsVR.RegisterForGrabEvent(self)
	HiggsVR.RegisterForDropEvent(self)
	hasHIGGS = true
	Debug.Trace("[CHIM-NSFW] HIGGS integration enabled")
EndFunction

; Get item name held in the OTHER hand (not the one grabbing)
; Uses HIGGS global function directly - no script property needed
string Function GetHeldItemInOtherHand(bool grabbingWithLeft)
	if !hasHIGGS
		return ""
	endif

	; Get what's in the OTHER hand
	ObjectReference otherHandObj = HiggsVR.GetGrabbedObject(!grabbingWithLeft)
	if !otherHandObj
		return ""
	endif

	; Skip if it's an actor (we're grabbing an NPC with that hand)
	if otherHandObj as Actor
		return ""
	endif

	; Get item name
	string itemName = otherHandObj.GetDisplayName()
	if itemName == ""
		Form baseForm = otherHandObj.GetBaseObject()
		if baseForm
			itemName = baseForm.GetName()
		endif
	endif

	return itemName
EndFunction


; ============================================================================
; [NSFW-ONLY] HIGGS ACTOR GRAB/DROP EVENTS - GROPING DETECTION
; ============================================================================
; These events handle grabbing NPCs (body parts). NSFW content.
; The item pickup path (HandleItemGrabbed) branches off early and is [CHIM-CORE].
; All logic handled by PHP backend (nsfw_physics.php)
; ============================================================================

; [NSFW-ONLY] HIGGS grab event - body part groping detection
; If HIGGS knows the exact body part, send immediately
; If HIGGS returns OTHER/Body (generic), wait for CBPC to tell us the exact location
Event OnObjectGrabbed(ObjectReference refr, bool isLeft)
	if !hasHIGGS || !enableHIGGS
		return
	endif

	Actor target = refr as Actor
	if !target
		; Not an actor - VR item pickup is owned by AIAgentVRItems.psc (the CHIM-core-bound VR-items script).
		; Don't emit ext_vr_item_raw here too, or every VR grab double-fires. Grope (actor) path continues below.
		return
	endif

	if target.IsChild()
		return
	endif

	; Get raw data
	string nodeName = HiggsVR.GetGrabbedNodeName(isLeft)
	Debug.Trace("[CHIM-NSFW] HIGGS RAW nodeName: '" + nodeName + "' (length=" + StringUtil.GetLength(nodeName) + ")")
	string bodyPart = MapNodeToBodyPart(nodeName)
	string actorName = target.GetDisplayName()
	Debug.Trace("[CHIM-NSFW] HIGGS grabbed node: '" + nodeName + "' -> mapped to: '" + bodyPart + "' on " + actorName)
	string handSide = "right"
	if isLeft
		handSide = "left"
	endif

	; Check chastity blocking (raw data only)
	bool isBlocked = false
	string blockedBy = ""
	if hasDeviousDevices
		if (bodyPart == VAGINAL_KEY || bodyPart == ANAL_KEY) && IsWearingChastityBelt(target)
			isBlocked = true
			blockedBy = "chastity belt"
		elseif bodyPart == BREASTS_KEY && IsWearingChastityBra(target)
			isBlocked = true
			blockedBy = "chastity bra"
		endif
	endif

	; Track for drop event
	currentlyGrabbedActor = target
	currentlyGrabbedNode = nodeName
	currentlyGrabbedBodyPart = bodyPart
	grabStartTime = Utility.GetCurrentRealTime()
	isGrabbing = true

	; Get what's in the OTHER hand (the one not grabbing the NPC)
	string heldItem = GetHeldItemInOtherHand(isLeft)

	; Ambiguous body parts that might actually be sexual areas
	; Shoulder (near breasts), Back (near butt), Belly (near vaginal)
	; For these, wait for CBPC to tell us the precise location
	bool needsCBPCConfirmation = (bodyPart == SHOULDER_KEY || bodyPart == BACK_KEY || bodyPart == BELLY_KEY)

	; If HIGGS knows the exact body part (and it's not ambiguous), send immediately
	if bodyPart != OTHER_KEY && !needsCBPCConfirmation
		; Format: actor^bodypart^action^blocked^blockedby^hand^helditem (using ^ to avoid CHIM pipe conflict)
		string rawData = actorName + "^" + bodyPart + "^grab^" + isBlocked + "^" + blockedBy + "^" + handSide + "^" + heldItem
		Debug.Trace("[CHIM-NSFW] HIGGS Raw (known location): " + rawData)
		AIAgentFunctions.logMessage(rawData, "ext_nsfw_physics_raw")
		lastPhysicsSpeechTime = Utility.GetCurrentRealTime()
		hasPendingGrab = false
	else
		; HIGGS returned generic or ambiguous node - wait for CBPC to tell us exact location
		; Ambiguous nodes (Shoulder/Back/Belly) might actually be sexual areas (Breasts/Butt/Pussy)
		; Store pending grab info for CBPC correlation
		pendingGrabActor = target
		pendingGrabHand = handSide
		pendingGrabTime = Utility.GetCurrentRealTime()
		pendingGrabBlocked = isBlocked
		pendingGrabBlockedBy = blockedBy
		pendingGrabBodyPart = bodyPart  ; Store HIGGS body part as fallback
		hasPendingGrab = true

		if needsCBPCConfirmation
			Debug.Trace("[CHIM-NSFW] HIGGS grab on ambiguous area (" + bodyPart + ") - waiting for CBPC to confirm exact location")
		else
			Debug.Trace("[CHIM-NSFW] HIGGS pending grab on " + actorName + " - waiting for CBPC correlation")
		endif

		; Register a timeout - if CBPC doesn't fire within window, send with HIGGS bodyPart
		RegisterForSingleUpdate(grabCorrelationWindow + 0.05)
	endif
EndEvent

; Helper to clear pending grab state
Function ClearPendingGrab()
	hasPendingGrab = false
	pendingGrabActor = None
	pendingGrabHand = ""
	pendingGrabTime = 0.0
	pendingGrabBlocked = false
	pendingGrabBlockedBy = ""
	pendingGrabBodyPart = ""
EndFunction

; Send a correlated grab event (called when CBPC identifies the body part after HIGGS grab)
Function SendCorrelatedGrab(string bodyPart, Actor akActor)
	if !hasPendingGrab || !pendingGrabActor || pendingGrabActor != akActor
		return
	endif

	string actorName = akActor.GetDisplayName()

	; Re-check chastity blocking now that we know the body part
	bool isBlocked = pendingGrabBlocked
	string blockedBy = pendingGrabBlockedBy
	if hasDeviousDevices && !isBlocked
		if (bodyPart == VAGINAL_KEY || bodyPart == ANAL_KEY) && IsWearingChastityBelt(akActor)
			isBlocked = true
			blockedBy = "chastity belt"
		elseif bodyPart == BREASTS_KEY && IsWearingChastityBra(akActor)
			isBlocked = true
			blockedBy = "chastity bra"
		endif
	endif

	; Get held item from the OTHER hand
	bool grabbingWithLeft = (pendingGrabHand == "left")
	string heldItem = GetHeldItemInOtherHand(grabbingWithLeft)

	; Format: actor^bodypart^action^blocked^blockedby^hand^helditem (using ^ to avoid CHIM pipe conflict)
	string rawData = actorName + "^" + bodyPart + "^grab^" + isBlocked + "^" + blockedBy + "^" + pendingGrabHand + "^" + heldItem
	Debug.Trace("[CHIM-NSFW] HIGGS+CBPC Correlated: " + rawData)
	AIAgentFunctions.logMessage(rawData, "ext_nsfw_physics_raw")
	lastPhysicsSpeechTime = Utility.GetCurrentRealTime()
	currentlyGrabbedBodyPart = bodyPart

	ClearPendingGrab()
EndFunction

; Check and handle pending grab timeout
Function CheckPendingGrabTimeout()
	if !hasPendingGrab || !pendingGrabActor
		return
	endif

	float elapsed = Utility.GetCurrentRealTime() - pendingGrabTime
	if elapsed >= grabCorrelationWindow
		; CBPC didn't fire in time - use HIGGS body part (or OTHER if none)
		string actorName = pendingGrabActor.GetDisplayName()

		; Get held item from the OTHER hand
		bool grabbingWithLeft = (pendingGrabHand == "left")
		string heldItem = GetHeldItemInOtherHand(grabbingWithLeft)

		; Use stored body part from HIGGS, or OTHER_KEY if empty
		string fallbackBodyPart = pendingGrabBodyPart
		if fallbackBodyPart == ""
			fallbackBodyPart = OTHER_KEY
		endif

		string rawData = actorName + "^" + fallbackBodyPart + "^grab^" + pendingGrabBlocked + "^" + pendingGrabBlockedBy + "^" + pendingGrabHand + "^" + heldItem
		Debug.Trace("[CHIM-NSFW] HIGGS Raw (timeout, fallback=" + fallbackBodyPart + "): " + rawData)
		AIAgentFunctions.logMessage(rawData, "ext_nsfw_physics_raw")
		lastPhysicsSpeechTime = Utility.GetCurrentRealTime()

		ClearPendingGrab()
	endif
EndFunction

; HIGGS drop event - sends raw data to PHP
Event OnObjectDropped(ObjectReference refr, bool isLeft)
	if !hasHIGGS || !enableHIGGS
		return
	endif

	Actor target = refr as Actor
	if !target
		; Not an actor - VR item drop is owned by AIAgentVRItems.psc (the CHIM-core-bound VR-items script).
		; Don't emit ext_vr_item_raw here too, or every VR drop double-fires. Grope (actor) path continues below.
		return
	endif

	if target != currentlyGrabbedActor
		return
	endif

	float holdDuration = Utility.GetCurrentRealTime() - grabStartTime
	string bodyPart = MapNodeToBodyPart(currentlyGrabbedNode)
	if currentlyGrabbedBodyPart != ""
		bodyPart = currentlyGrabbedBodyPart
	endif
	string actorName = target.GetDisplayName()
	string handSide = "right"
	if isLeft
		handSide = "left"
	endif

	; Send RAW data to PHP - let PHP handle all logic
	; Format: actor^bodypart^action^duration^hand (using ^ to avoid CHIM pipe conflict)
	string rawData = actorName + "^" + bodyPart + "^release^" + holdDuration + "^" + handSide
	Debug.Trace("[CHIM-NSFW] HIGGS Raw: " + rawData)

	AIAgentFunctions.logMessage(rawData, "ext_nsfw_physics_raw")

	isGrabbing = false
	currentlyGrabbedActor = None
	currentlyGrabbedNode = ""
	currentlyGrabbedBodyPart = ""
	grabStartTime = 0.0
EndEvent

; Map a skeleton node name to a body part key
; Tries exact match first, then falls back to pattern matching
string Function MapNodeToBodyPart(string nodeName)
	; Handle empty node name
	if nodeName == "" || nodeName == "None"
		Debug.Trace("[CHIM-NSFW] MapNodeToBodyPart: empty/None node - returning OTHER_KEY")
		return OTHER_KEY
	endif

	; Try exact match in node arrays first
	if HeadNodes.Find(nodeName) >= 0
		return HEAD_KEY
	elseif BreastNodes.Find(nodeName) >= 0
		return BREASTS_KEY
	elseif ButtNodes.Find(nodeName) >= 0
		return BUTT_KEY
	elseif BellyNodes.Find(nodeName) >= 0
		return BELLY_KEY
	elseif ShoulderNodes.Find(nodeName) >= 0
		return SHOULDER_KEY
	elseif BackNodes.Find(nodeName) >= 0
		return BACK_KEY
	elseif PenisNodes.Find(nodeName) >= 0
		return PENIS_KEY
	elseif AnalNodes.Find(nodeName) >= 0
		return ANAL_KEY
	elseif VaginalNodes.Find(nodeName) >= 0
		return VAGINAL_KEY
	elseif ArmNodes.Find(nodeName) >= 0
		return ARM_KEY
	elseif HandNodes.Find(nodeName) >= 0
		return HAND_KEY
	elseif LegNodes.Find(nodeName) >= 0
		return LEG_KEY
	elseif FootNodes.Find(nodeName) >= 0
		return FOOT_KEY
	endif

	; Exact match failed - try pattern matching as fallback
	; This catches variations in node naming between different skeleton mods
	string result = MapNodeByPattern(nodeName)
	if result != ""
		Debug.Trace("[CHIM-NSFW] MapNodeToBodyPart: pattern matched '" + nodeName + "' -> " + result)
		return result
	endif

	; Log unrecognized node for debugging
	Debug.Trace("[CHIM-NSFW] UNRECOGNIZED NODE: '" + nodeName + "' - returning OTHER_KEY")
	return OTHER_KEY
EndFunction

; Fallback pattern matching for node names that don't exactly match our arrays
; Uses StringUtil.Find() to check for substrings
string Function MapNodeByPattern(string nodeName)
	; Convert to lowercase for case-insensitive matching
	string lowerNode = StringUtil.Substring(nodeName, 0)  ; Get full string

	; Head patterns
	if StringUtil.Find(nodeName, "Head") >= 0 || StringUtil.Find(nodeName, "head") >= 0
		return HEAD_KEY
	elseif StringUtil.Find(nodeName, "Neck") >= 0 || StringUtil.Find(nodeName, "neck") >= 0
		return HEAD_KEY
	endif

	; Breast patterns
	if StringUtil.Find(nodeName, "Breast") >= 0 || StringUtil.Find(nodeName, "breast") >= 0
		return BREASTS_KEY
	elseif StringUtil.Find(nodeName, "Boob") >= 0 || StringUtil.Find(nodeName, "boob") >= 0
		return BREASTS_KEY
	endif

	; Butt patterns
	if StringUtil.Find(nodeName, "Butt") >= 0 || StringUtil.Find(nodeName, "butt") >= 0
		return BUTT_KEY
	endif

	; Genital patterns (check before pelvis/spine)
	if StringUtil.Find(nodeName, "Genital") >= 0 || StringUtil.Find(nodeName, "genital") >= 0
		return PENIS_KEY
	elseif StringUtil.Find(nodeName, "Penis") >= 0 || StringUtil.Find(nodeName, "penis") >= 0
		return PENIS_KEY
	elseif StringUtil.Find(nodeName, "Scrotum") >= 0 || StringUtil.Find(nodeName, "scrotum") >= 0
		return PENIS_KEY
	elseif StringUtil.Find(nodeName, "Pussy") >= 0 || StringUtil.Find(nodeName, "pussy") >= 0
		return VAGINAL_KEY
	elseif StringUtil.Find(nodeName, "Clitor") >= 0 || StringUtil.Find(nodeName, "clitor") >= 0
		return VAGINAL_KEY
	elseif StringUtil.Find(nodeName, "Vagina") >= 0 || StringUtil.Find(nodeName, "vagina") >= 0
		return VAGINAL_KEY
	elseif StringUtil.Find(nodeName, "Anus") >= 0 || StringUtil.Find(nodeName, "anus") >= 0
		return ANAL_KEY
	elseif StringUtil.Find(nodeName, "Anal") >= 0 || StringUtil.Find(nodeName, "anal") >= 0
		return ANAL_KEY
	endif

	; Pelvis - context dependent (could be vaginal or butt)
	if StringUtil.Find(nodeName, "Pelvis") >= 0 || StringUtil.Find(nodeName, "pelvis") >= 0
		return VAGINAL_KEY  ; Default to vaginal for pelvis
	endif

	; Shoulder patterns (check before generic spine)
	if StringUtil.Find(nodeName, "Clavicle") >= 0 || StringUtil.Find(nodeName, "clavicle") >= 0
		return SHOULDER_KEY
	elseif StringUtil.Find(nodeName, "Spn2") >= 0 || StringUtil.Find(nodeName, "Spine2") >= 0
		return SHOULDER_KEY
	endif

	; Back/Belly patterns (spine disambiguation)
	if StringUtil.Find(nodeName, "Spn0") >= 0 || StringUtil.Find(nodeName, "Spine0") >= 0
		return BACK_KEY
	elseif StringUtil.Find(nodeName, "Spn1") >= 0 || StringUtil.Find(nodeName, "Spine1") >= 0
		return BELLY_KEY
	elseif StringUtil.Find(nodeName, "Belly") >= 0 || StringUtil.Find(nodeName, "belly") >= 0
		return BELLY_KEY
	endif

	; Arm patterns
	if StringUtil.Find(nodeName, "UpperArm") >= 0 || StringUtil.Find(nodeName, "Forearm") >= 0
		return ARM_KEY
	elseif StringUtil.Find(nodeName, "Uar") >= 0 || StringUtil.Find(nodeName, "Lar") >= 0
		return ARM_KEY
	elseif StringUtil.Find(nodeName, "ArmTwist") >= 0
		return ARM_KEY
	endif

	; Hand patterns
	if StringUtil.Find(nodeName, "Hand") >= 0 || StringUtil.Find(nodeName, "hand") >= 0
		return HAND_KEY
	elseif StringUtil.Find(nodeName, "Finger") >= 0 || StringUtil.Find(nodeName, "finger") >= 0
		return HAND_KEY
	elseif StringUtil.Find(nodeName, "Hnd") >= 0
		return HAND_KEY
	endif

	; Leg patterns
	if StringUtil.Find(nodeName, "Thigh") >= 0 || StringUtil.Find(nodeName, "thigh") >= 0
		return LEG_KEY
	elseif StringUtil.Find(nodeName, "Calf") >= 0 || StringUtil.Find(nodeName, "calf") >= 0
		return LEG_KEY
	elseif StringUtil.Find(nodeName, "Thg") >= 0 || StringUtil.Find(nodeName, "Clf") >= 0
		return LEG_KEY
	endif

	; Foot patterns
	if StringUtil.Find(nodeName, "Foot") >= 0 || StringUtil.Find(nodeName, "foot") >= 0
		return FOOT_KEY
	elseif StringUtil.Find(nodeName, "Toe") >= 0 || StringUtil.Find(nodeName, "toe") >= 0
		return FOOT_KEY
	endif

	; Generic spine without specific number - default to belly (mid-torso)
	if StringUtil.Find(nodeName, "Spine") >= 0 || StringUtil.Find(nodeName, "spine") >= 0
		return BELLY_KEY
	endif

	; Torso/chest patterns - default to belly
	if StringUtil.Find(nodeName, "Torso") >= 0 || StringUtil.Find(nodeName, "torso") >= 0
		return BELLY_KEY
	elseif StringUtil.Find(nodeName, "Chest") >= 0 || StringUtil.Find(nodeName, "chest") >= 0
		return BELLY_KEY
	endif

	; No pattern matched
	return ""
EndFunction

; ============================================
; AROUSAL INTEGRATION (SLO Aroused NG)
; ============================================
; Safely get arousal level for an actor
; Returns -1 if SLO Aroused is not installed
int Function GetActorArousalLevel(Actor akActor)
	if (!akActor)
		return -1
	endif

	; Check if SexLabAroused.esm is loaded
	if (Game.GetModByName("SexLabAroused.esm") == 255)
		return -1
	endif

	; Get OAroused quest and call GetArousal
	Form oArousedForm = Game.GetFormFromFile(0x0A5BA8, "SexLabAroused.esm")
	if (!oArousedForm)
		return -1
	endif

	; Use faction rank as fallback (slaArousal faction stores arousal 0-100)
	Faction arousalFaction = Game.GetFormFromFile(0x027877, "SexLabAroused.esm") as Faction
	if (arousalFaction && akActor.IsInFaction(arousalFaction))
		return akActor.GetFactionRank(arousalFaction)
	endif

	return -1
EndFunction

; Get arousal description for prompts
string Function GetArousalDescription(int arousalLevel)
	if (arousalLevel < 0)
		return ""
	elseif (arousalLevel < 20)
		return "not aroused"
	elseif (arousalLevel < 40)
		return "slightly aroused"
	elseif (arousalLevel < 60)
		return "aroused"
	elseif (arousalLevel < 80)
		return "very aroused"
	else
		return "extremely horny"
	endif
EndFunction

function DoRegister()

	Debug.Notification("[CHIM-NSFW] Registering events")
	Debug.Trace("[CHIM-NSFW]: DoRegister called")
	
	UnregisterForAllModEvents()
	Utility.wait(1);	
	UnRegisterForModEvent("CHIM_CommandReceived")
	UnRegisterForModEvent("CHIM_SpeechStopped")
	UnRegisterForModEvent("CHIM_SpeechStarted")
	
	UnRegisterForModEvent("ostim_event")
	UnRegisterForModEvent("ostim_thread_start")
	UnRegisterForModEvent("ostim_actor_orgasm")
	;RegisterForModEvent("ocum_play_cum_shoot_effect", "OCumPlayCumShoot")
	UnRegisterForModEvent("ostim_scenechanged")
	UnRegisterForModEvent("ostim_end")
	UnRegisterForModEvent("ostim_thread_start")
	UnRegisterForModEvent("ostim_thread_scenechanged")
	UnRegisterForModEvent("ostim_thread_end")

	
	RegisterForModEvent("CHIM_CommandReceived", "CommandManager")
	RegisterForModEvent("CHIM_SpeechStopped", "HelperSpeechStop")
	RegisterForModEvent("CHIM_SpeechStarted", "HelperSpeechStart")
	
	RegisterForModEvent("ostim_event", "OstimEvent")
	RegisterForModEvent("ostim_actor_orgasm", "OStimOrgasm")
	;RegisterForModEvent("ocum_play_cum_shoot_effect", "OCumPlayCumShoot")
	RegisterForModEvent("ostim_scenechanged", "OStimSceneChanged")
	RegisterForModEvent("ostim_end", "OStimEnd")
	RegisterForModEvent("ostim_thread_start", "OStimThreadStart")
	RegisterForModEvent("ostim_thread_scenechanged", "OStimThreadSceneChanged")
	RegisterForModEvent("ostim_thread_end", "OStimThreadEnd")

	; ============================================
	; SUBTHREAD EVENTS (NPC-to-NPC scenes from OStim NPCs mod)
	; These fire for NPC-only scenes that don't involve player
	; ============================================
	UnRegisterForModEvent("ostim_subthread_start")
	UnRegisterForModEvent("ostim_subthread_end")
	UnRegisterForModEvent("ostim_subthread_orgasm")
	RegisterForModEvent("ostim_subthread_start", "OStimSubthreadStart")
	RegisterForModEvent("ostim_subthread_end", "OStimSubthreadEnd")
	RegisterForModEvent("ostim_subthread_orgasm", "OStimSubthreadOrgasm")

	; NPC-to-NPC invite phase - when dom actor approaches sub actor
	UnRegisterForModEvent("ostim_npc_invite")
	RegisterForModEvent("ostim_npc_invite", "OStimNpcInvite")

	; ============================================
	; SEXLAB EVENTS - global Hook* ModEvents fire for EVERY SexLab scene (incl NPC-to-NPC).
	; No SetHook needed. Handlers below mirror the OStim paths.
	; ============================================
	UnRegisterForModEvent("HookAnimationStart")
	UnRegisterForModEvent("HookStageStart")
	UnRegisterForModEvent("HookAnimationEnd")
	UnRegisterForModEvent("SexLabOrgasm")
	RegisterForModEvent("HookAnimationStart", "OnSexLabAnimStart")
	RegisterForModEvent("HookStageStart", "OnSexLabStageStart")
	RegisterForModEvent("HookAnimationEnd", "OnSexLabAnimEnd")
	RegisterForModEvent("SexLabOrgasm", "OnSexLabActorOrgasm")

	; ============================================
	; DEVIOUS DEVICES EVENTS (soft - only matter if DD is installed)
	; ============================================
	UnRegisterForModEvent("DDI_DeviceEquipped")
	UnRegisterForModEvent("DDI_DeviceRemoved")
	RegisterForModEvent("DDI_DeviceEquipped", "OnDDDeviceChanged")
	RegisterForModEvent("DDI_DeviceRemoved", "OnDDDeviceChanged")

	; DD BASELINE SYNC (fix 2026-07-02, SexLab audit #3): the equip/remove events only cover changes made
	; AFTER this registration - devices already worn at load (or after a server DB reset) stayed invisible
	; until the next change. Push the player's current device state now; NPCs refresh on their next change.
	if (hasDeviousDevices)
		SendDeviceState(Game.GetPlayer())
	endif

	UnRegisterForModEvent("FertilityModeImpregnate")
	RegisterForModEvent("FertilityModeImpregnate", "FertilityImpregnated")
	
	UnRegisterForModEvent("FMPlusLabor")
	RegisterForModEvent("FMPlusLabor", "FertilityLabor")

	UnRegisterForModEvent("FertilityModeAbort")
	RegisterForModEvent("FertilityModeAbort", "FertilityAbort")
	
	UnRegisterForModEvent("FertilityModeLabor")
	RegisterForModEvent("FertilityModeLabor", "FertilityModeLabor")
	
	UnRegisterForModEvent("FertilityModeConception")
	RegisterForModEvent("FertilityModeConception", "FertilityModeConception")
	
	UnRegisterForModEvent("FMPlusDoMorph")
	RegisterForModEvent("FMPlusDoMorph", "FertilityModeUpdate")
	
	UnRegisterForModEvent("FMPlusConception")
	RegisterForModEvent("FMPlusConception", "FMPlusConception")
	
	UnRegisterForModEvent("FMDefinedChildSpawned")
	RegisterForModEvent("FMDefinedChildSpawned", "FMDefinedChildSpawned")

	; Fertility Mode Reloaded (FMR) events - your custom mod
	UnRegisterForModEvent("FMR_ActorStatus")
	RegisterForModEvent("FMR_ActorStatus", "OnFMRActorStatus")

	UnRegisterForModEvent("FMR_BabyDamage")
	RegisterForModEvent("FMR_BabyDamage", "OnFMRBabyDamage")

	UnRegisterForModEvent("FMR_BabyDeath")
	RegisterForModEvent("FMR_BabyDeath", "OnFMRBabyDeath")

	UnRegisterForModEvent("FMR_BabyMiscarriage")
	RegisterForModEvent("FMR_BabyMiscarriage", "OnFMRBabyMiscarriage")

	UnRegisterForModEvent("FMR_BabyStatus")
	RegisterForModEvent("FMR_BabyStatus", "OnFMRBabyStatus")

	UnRegisterForModEvent("FMR_MotherDeath")
	RegisterForModEvent("FMR_MotherDeath", "OnFMRMotherDeath")

	; CBPC Physics Touch Detection
	playerRef = Game.GetPlayer()
	InitDeviousDevices()  ; Initialize DD soft dependency
	InitHIGGS()           ; Initialize HIGGS soft dependency
	if grabCorrelationWindow < 1.25
		grabCorrelationWindow = 1.25
	endif

	; Initialize collider tracking map
	if (actorsWithColliders == 0)
		actorsWithColliders = JMap.Object()
		JValue.Retain(actorsWithColliders)
	endif

	if enableCBPC
		Debug.Trace("[CHIM-NSFW] Enabling CBPC Physics Touch Detection")
		lastPhysicsSpeechTime = 0.0
		if (touchedLocations == 0)
			touchedLocations = JMap.Object()
			JValue.Retain(touchedLocations)
		EndIf
		ClearTouchedLocations()
		InitNodeDefinitions()

		UnRegisterForModEvent("CBPCPlayerCollisionWithFemaleEvent")
		UnRegisterForModEvent("CBPCPlayerCollisionWithMaleEvent")
		UnRegisterForModEvent("CBPCPlayerGenitalCollisionWithFemaleEvent")
		UnRegisterForModEvent("CBPCPlayerGenitalCollisionWithMaleEvent")

		RegisterForModEvent("CBPCPlayerCollisionWithFemaleEvent", "OnCBPCCollision")
		RegisterForModEvent("CBPCPlayerCollisionWithMaleEvent", "OnCBPCCollision")
		RegisterForModEvent("CBPCPlayerGenitalCollisionWithFemaleEvent", "OnCBPCCollision")
		RegisterForModEvent("CBPCPlayerGenitalCollisionWithMaleEvent", "OnCBPCCollision")

		RegisterForSingleUpdate(cbpcCooldown)
	Else
		Debug.Trace("[CHIM-NSFW] CBPC Physics disabled")
		UnRegisterForModEvent("CBPCPlayerCollisionWithFemaleEvent")
		UnRegisterForModEvent("CBPCPlayerCollisionWithMaleEvent")
		UnRegisterForModEvent("CBPCPlayerGenitalCollisionWithFemaleEvent")
		UnRegisterForModEvent("CBPCPlayerGenitalCollisionWithMaleEvent")
	EndIf

EndFunction

; Initialize body node definitions for CBPC collision detection
Function InitNodeDefinitions()
	; Head nodes - head and neck
	HeadNodes = new String[5]
	HeadNodes[0] = "NPC Head [Head]"
	HeadNodes[1] = "NPC Head"
	HeadNodes[2] = "Head"
	HeadNodes[3] = "NPC Head MagicNode [Hmag]"
	HeadNodes[4] = "NPC Neck [Neck]"

	; Shoulder nodes - upper back/shoulder area (clavicles and upper spine)
	ShoulderNodes = new String[5]
	ShoulderNodes[0] = "NPC L Clavicle [LClv]"
	ShoulderNodes[1] = "NPC R Clavicle [RClv]"
	ShoulderNodes[2] = "NPC Spine2 [Spn2]"
	ShoulderNodes[3] = "CME L Clavicle [LClv]"
	ShoulderNodes[4] = "CME R Clavicle [RClv]"

	; Back nodes - lower back (Spine0 only - distinct from butt and belly)
	BackNodes = new String[2]
	BackNodes[0] = "NPC Spine [Spn0]"
	BackNodes[1] = "CME Spine [Spn0]"

	; Breast nodes
	BreastNodes = new String[10]
	BreastNodes[0] = "L Breast01"
	BreastNodes[1] = "L Breast02"
	BreastNodes[2] = "L Breast03"
	BreastNodes[3] = "R Breast01"
	BreastNodes[4] = "R Breast02"
	BreastNodes[5] = "R Breast03"
	BreastNodes[6] = "NPC L Breast"
	BreastNodes[7] = "NPC R Breast"
	BreastNodes[8] = "NPC L Breast01"
	BreastNodes[9] = "NPC R Breast01"

	; Butt nodes - expanded to include CBPC collision spheres
	; RearThigh nodes are where CBPC registers touches near the butt
	ButtNodes = new String[6]
	ButtNodes[0] = "NPC L Butt"
	ButtNodes[1] = "NPC R Butt"
	ButtNodes[2] = "L Butt"
	ButtNodes[3] = "R Butt"
	ButtNodes[4] = "NPC L RearThigh"
	ButtNodes[5] = "NPC R RearThigh"

	; Belly nodes - front torso/stomach area
	; NPC Spine1 [Spn1] = CBPC default belly collision node (from CBPCCollisionConfig.txt)
	; Distinct from Shoulder (Spine2), Back (Spine0), and Butt
	BellyNodes = new String[4]
	BellyNodes[0] = "HDT Belly"
	BellyNodes[1] = "NPC Spine1 [Spn1]"
	BellyNodes[2] = "NPC Belly"
	BellyNodes[3] = "Belly"

	; Penis nodes - expanded with bracket format from CBPC config
	; CBPC uses "NPC Genitals06 [Gen06]" etc
	PenisNodes = new String[14]
	PenisNodes[0] = "NPC Genitals01"
	PenisNodes[1] = "NPC Genitals01 [Gen01]"
	PenisNodes[2] = "NPC Genitals02"
	PenisNodes[3] = "NPC Genitals03"
	PenisNodes[4] = "NPC Genitals04"
	PenisNodes[5] = "NPC Genitals05"
	PenisNodes[6] = "NPC Genitals06"
	PenisNodes[7] = "NPC Genitals06 [Gen06]"
	PenisNodes[8] = "SOSScrotum"
	PenisNodes[9] = "NPC GenitalsScrotum"
	PenisNodes[10] = "GenitalsScrotumLag"
	PenisNodes[11] = "Genitals01"
	PenisNodes[12] = "Genitals02"
	PenisNodes[13] = "NPC GenitalsBase"

	; Vaginal nodes - expanded with bracket format from CBPC config
	; "NPC Pelvis [Pelv]" is the actual CBPC collision node name
	VaginalNodes = new String[9]
	VaginalNodes[0] = "NPC Pelvis"
	VaginalNodes[1] = "NPC Pelvis [Pelv]"
	VaginalNodes[2] = "NPC L Pussy02"
	VaginalNodes[3] = "NPC R Pussy02"
	VaginalNodes[4] = "Clitoral1"
	VaginalNodes[5] = "VaginaB1"
	VaginalNodes[6] = "NPC Pussy01"
	VaginalNodes[7] = "NPC Pussy02"
	VaginalNodes[8] = "Vagina"

	; Anal nodes - expanded
	AnalNodes = new String[3]
	AnalNodes[0] = "Anal"
	AnalNodes[1] = "NPC Anus"
	AnalNodes[2] = "Anus"

	; Arm nodes - standard Skyrim skeleton bones
	ArmNodes = new String[8]
	ArmNodes[0] = "NPC L UpperArm [LUar]"
	ArmNodes[1] = "NPC R UpperArm [RUar]"
	ArmNodes[2] = "NPC L Forearm [LLar]"
	ArmNodes[3] = "NPC R Forearm [RLar]"
	ArmNodes[4] = "NPC L UpperarmTwist1 [LUt1]"
	ArmNodes[5] = "NPC R UpperarmTwist1 [RUt1]"
	ArmNodes[6] = "NPC L ForearmTwist1 [LLt1]"
	ArmNodes[7] = "NPC R ForearmTwist1 [RLt1]"

	; Hand nodes - standard Skyrim skeleton bones
	HandNodes = new String[4]
	HandNodes[0] = "NPC L Hand [LHnd]"
	HandNodes[1] = "NPC R Hand [RHnd]"
	HandNodes[2] = "NPC L Finger00 [LF00]"
	HandNodes[3] = "NPC R Finger00 [RF00]"

	; Leg nodes - standard Skyrim skeleton bones
	LegNodes = new String[8]
	LegNodes[0] = "NPC L Thigh [LThg]"
	LegNodes[1] = "NPC R Thigh [RThg]"
	LegNodes[2] = "NPC L Calf [LClf]"
	LegNodes[3] = "NPC R Calf [RClf]"
	LegNodes[4] = "NPC L ThighTwist [LTht]"
	LegNodes[5] = "NPC R ThighTwist [RTht]"
	LegNodes[6] = "NPC L CalfTwist [LClt]"
	LegNodes[7] = "NPC R CalfTwist [RClt]"

	; Foot nodes - standard Skyrim skeleton bones
	FootNodes = new String[4]
	FootNodes[0] = "NPC L Foot [Lft ]"
	FootNodes[1] = "NPC R Foot [Rft ]"
	FootNodes[2] = "NPC L Toe0 [LToe]"
	FootNodes[3] = "NPC R Toe0 [RToe]"
EndFunction

; ============================================
; CBPC DYNAMIC COLLIDER REGISTRATION
; ============================================
; Attach collision spheres to body nodes at runtime using CBPC API
; This allows detecting touches on arms/legs/hands/feet that aren't in default CBPC config
Function RegisterCustomColliders(Actor akActor)
	if !akActor
		return
	endif

	; Create position array for collider (x, y, z)
	float[] pos = new float[3]
	pos[0] = 0.0
	pos[1] = 0.0
	pos[2] = 0.0

	; Arm colliders - larger radius for upper arm/forearm
	CBPCPluginScript.AttachColliderSphere(akActor, "NPC L UpperArm [LUar]", pos, 4.0, 1.0, 100, true)
	CBPCPluginScript.AttachColliderSphere(akActor, "NPC R UpperArm [RUar]", pos, 4.0, 1.0, 101, true)
	CBPCPluginScript.AttachColliderSphere(akActor, "NPC L Forearm [LLar]", pos, 3.5, 1.0, 102, true)
	CBPCPluginScript.AttachColliderSphere(akActor, "NPC R Forearm [RLar]", pos, 3.5, 1.0, 103, true)

	; Hand colliders - smaller radius
	CBPCPluginScript.AttachColliderSphere(akActor, "NPC L Hand [LHnd]", pos, 2.5, 1.0, 104, true)
	CBPCPluginScript.AttachColliderSphere(akActor, "NPC R Hand [RHnd]", pos, 2.5, 1.0, 105, true)

	; Leg colliders - larger radius for thigh/calf
	CBPCPluginScript.AttachColliderSphere(akActor, "NPC L Thigh [LThg]", pos, 5.0, 1.0, 106, true)
	CBPCPluginScript.AttachColliderSphere(akActor, "NPC R Thigh [RThg]", pos, 5.0, 1.0, 107, true)
	CBPCPluginScript.AttachColliderSphere(akActor, "NPC L Calf [LClf]", pos, 4.0, 1.0, 108, true)
	CBPCPluginScript.AttachColliderSphere(akActor, "NPC R Calf [RClf]", pos, 4.0, 1.0, 109, true)

	; Foot colliders - smaller radius
	CBPCPluginScript.AttachColliderSphere(akActor, "NPC L Foot [Lft ]", pos, 3.0, 1.0, 110, true)
	CBPCPluginScript.AttachColliderSphere(akActor, "NPC R Foot [Rft ]", pos, 3.0, 1.0, 111, true)

	; Head colliders - medium radius for head and neck
	CBPCPluginScript.AttachColliderSphere(akActor, "NPC Head [Head]", pos, 5.0, 1.0, 112, true)
	CBPCPluginScript.AttachColliderSphere(akActor, "NPC Neck [Neck]", pos, 3.0, 1.0, 113, true)

	; Shoulder colliders - upper back/shoulder area (clavicles + Spine2)
	CBPCPluginScript.AttachColliderSphere(akActor, "NPC L Clavicle [LClv]", pos, 4.0, 1.0, 114, true)
	CBPCPluginScript.AttachColliderSphere(akActor, "NPC R Clavicle [RClv]", pos, 4.0, 1.0, 115, true)
	CBPCPluginScript.AttachColliderSphere(akActor, "NPC Spine2 [Spn2]", pos, 5.0, 1.0, 116, true)

	; Back collider - lower back only (Spine0)
	CBPCPluginScript.AttachColliderSphere(akActor, "NPC Spine [Spn0]", pos, 4.5, 1.0, 117, true)

	Debug.Trace("[CHIM-NSFW] Registered custom CBPC colliders for " + akActor.GetDisplayName())
EndFunction

; Remove custom colliders from an actor
Function UnregisterCustomColliders(Actor akActor)
	if !akActor
		return
	endif

	; Type 0 = sphere, Type 1 = capsule
	; Detach all custom colliders by index (100-117: arms, hands, legs, feet, head, shoulder, back)
	int i = 100
	while i <= 117
		CBPCPluginScript.DetachCollider(akActor, "", 0, i, true)
		i += 1
	endwhile

	Debug.Trace("[CHIM-NSFW] Unregistered custom CBPC colliders for " + akActor.GetDisplayName())
EndFunction

; Register custom colliders on all nearby NPCs for arm/leg/hand/foot touch detection
; Call this periodically or when entering new areas
; Check if actor has custom colliders registered
bool Function HasCustomColliders(Actor akActor)
	if !akActor || actorsWithColliders == 0
		return false
	endif
	string actorKey = akActor.GetFormID() as string
	return JMap.HasKey(actorsWithColliders, actorKey)
EndFunction

; Mark actor as having custom colliders
Function MarkActorWithColliders(Actor akActor)
	if !akActor || actorsWithColliders == 0
		return
	endif
	string actorKey = akActor.GetFormID() as string
	JMap.SetInt(actorsWithColliders, actorKey, 1)
EndFunction

; Register colliders on-demand when first collision detected with an actor
; This is more performant than scanning all nearby NPCs
Function RegisterCollidersOnDemand(Actor akActor)
	if !akActor || !enableCBPC
		return
	endif

	; Skip if already registered
	if HasCustomColliders(akActor)
		return
	endif

	; Register custom colliders for arm/leg/hand/foot detection
	RegisterCustomColliders(akActor)
	MarkActorWithColliders(akActor)
EndFunction

; Clear touch tracking data
Function ClearTouchedLocations()
	JMap.SetStr(touchedLocations, ACTOR_KEY, "")
	JMap.SetForm(touchedLocations, ACTORREF_KEY, None)
	JMap.SetFlt(touchedLocations, BREASTS_KEY, 0.0)
	JMap.SetFlt(touchedLocations, VAGINAL_KEY, 0.0)
	JMap.SetFlt(touchedLocations, ANAL_KEY, 0.0)
	JMap.SetFlt(touchedLocations, BELLY_KEY, 0.0)
	JMap.SetFlt(touchedLocations, PENIS_KEY, 0.0)
	JMap.SetFlt(touchedLocations, BUTT_KEY, 0.0)
	JMap.SetFlt(touchedLocations, ARM_KEY, 0.0)
	JMap.SetFlt(touchedLocations, LEG_KEY, 0.0)
	JMap.SetFlt(touchedLocations, HAND_KEY, 0.0)
	JMap.SetFlt(touchedLocations, FOOT_KEY, 0.0)
	JMap.SetFlt(touchedLocations, HEAD_KEY, 0.0)
	JMap.SetFlt(touchedLocations, SHOULDER_KEY, 0.0)
	JMap.SetFlt(touchedLocations, BACK_KEY, 0.0)
	JMap.SetFlt(touchedLocations, OTHER_KEY, 0.0)
	JMap.setInt(touchedLocations, GENITAL_COLLISION_KEY, 0)
	hitThreshold = False
	locationHit = ""
	hitValue = 0.0
	collisionMutex = False
EndFunction

; Track cumulative touch duration for a body part
Function TrackTouch(string nodeType, float collisionDuration, Actor akActor)
	if hitThreshold
		return
	EndIf

	string actorName = akActor.GetDisplayName()
	Float currentValue = JMap.GetFlt(touchedLocations, nodeType)
	Float newValue = currentValue + collisionDuration

	Debug.Trace("[CHIM-NSFW] CBPC Touch: " + nodeType + " on " + actorName + " = " + newValue)
	JMap.SetFlt(touchedLocations, nodeType, newValue)

	if newValue > cbpcTouchThreshold
		hitThreshold = True
		hitValue = newValue
		locationHit = nodeType
		Debug.Trace("[CHIM-NSFW] CBPC Threshold hit: " + locationHit + " = " + hitValue)
	EndIf

	if actorName == ""
		JMap.SetStr(touchedLocations, ACTOR_KEY, "someone")
	Else
		JMap.SetStr(touchedLocations, ACTOR_KEY, actorName)
		JMap.SetForm(touchedLocations, ACTORREF_KEY, akActor)
	EndIf
EndFunction

; Handle CBPC collision events
Function OnCBPCCollision(string eventName, string nodeName, float collisionDuration, Form actorForm)
	if collisionMutex
		return
	EndIf
	collisionMutex = True

	if !enableCBPC
		collisionMutex = False
		return
	EndIf

	if hitThreshold
		collisionMutex = False
		return
	EndIf

	Actor akActor = actorForm as Actor
	if !akActor
		collisionMutex = False
		return
	EndIf

	; Skip children
	if akActor.IsChild()
		Debug.Trace("[CHIM-NSFW] CBPC: Skipping child actor")
		collisionMutex = False
		return
	EndIf

	; Register custom colliders on-demand for arm/leg/hand/foot detection
	; This is more performant than scanning all nearby NPCs
	RegisterCollidersOnDemand(akActor)

	string actorName = akActor.GetDisplayName()
	Debug.Trace("[CHIM-NSFW] CBPC Collision: " + eventName + " | " + nodeName + " | " + collisionDuration + " | " + actorName)

	if actorName == ""
		collisionMutex = False
		return
	EndIf

	; Map node to body part
	string bodyPart = OTHER_KEY
	if BreastNodes.Find(nodeName) >= 0
		bodyPart = BREASTS_KEY
	elseif ButtNodes.Find(nodeName) >= 0
		bodyPart = BUTT_KEY
	elseif BellyNodes.Find(nodeName) >= 0
		bodyPart = BELLY_KEY
	elseif PenisNodes.Find(nodeName) >= 0
		bodyPart = PENIS_KEY
	elseif AnalNodes.Find(nodeName) >= 0
		bodyPart = ANAL_KEY
	elseif VaginalNodes.Find(nodeName) >= 0
		bodyPart = VAGINAL_KEY
	elseif HeadNodes.Find(nodeName) >= 0
		bodyPart = HEAD_KEY
	elseif ShoulderNodes.Find(nodeName) >= 0
		bodyPart = SHOULDER_KEY
	elseif BackNodes.Find(nodeName) >= 0
		bodyPart = BACK_KEY
	elseif ArmNodes.Find(nodeName) >= 0
		bodyPart = ARM_KEY
	elseif HandNodes.Find(nodeName) >= 0
		bodyPart = HAND_KEY
	elseif LegNodes.Find(nodeName) >= 0
		bodyPart = LEG_KEY
	elseif FootNodes.Find(nodeName) >= 0
		bodyPart = FOOT_KEY
	EndIf

	; ============================================
	; HIGGS + CBPC CORRELATION
	; If there's a pending HIGGS grab on this actor within the correlation window,
	; this CBPC event tells us WHERE the grab actually landed
	; ============================================
	; DEBUG: Log correlation check state
	string pendingActorName = ""
	if pendingGrabActor
		pendingActorName = pendingGrabActor.GetDisplayName()
	endif
	Debug.Trace("[CHIM-NSFW] CBPC Correlation check: hasPendingGrab=" + hasPendingGrab + " pendingActor=" + pendingActorName + " thisActor=" + actorName)

	if hasPendingGrab && pendingGrabActor == akActor
		float elapsed = Utility.GetCurrentRealTime() - pendingGrabTime
		Debug.Trace("[CHIM-NSFW] CBPC Correlation timing: elapsed=" + elapsed + " window=" + grabCorrelationWindow)
		if elapsed <= grabCorrelationWindow
			; CBPC fired within window - this is where the grab landed!
			Debug.Trace("[CHIM-NSFW] CBPC correlated with HIGGS grab: " + bodyPart + " on " + actorName)
			SendCorrelatedGrab(bodyPart, akActor)
			collisionMutex = False
			return  ; Don't also track as touch - it's a grab
		else
			Debug.Trace("[CHIM-NSFW] CBPC correlation TIMEOUT: elapsed " + elapsed + " > window " + grabCorrelationWindow)
		endif
	elseif hasPendingGrab
		Debug.Trace("[CHIM-NSFW] CBPC correlation ACTOR MISMATCH: pending=" + pendingActorName + " this=" + actorName)
	endif

	; Normal touch tracking (no pending grab, or different actor)
	TrackTouch(bodyPart, collisionDuration, akActor)

	; Track if this was a genital collision
	if eventName == "CBPCPlayerGenitalCollisionWithFemaleEvent" || eventName == "CBPCPlayerGenitalCollisionWithMaleEvent"
		JMap.setInt(touchedLocations, GENITAL_COLLISION_KEY, 1)
	EndIf

	collisionMutex = False
EndFunction

; Periodic update to process accumulated touch data
Event OnUpdate()
	; First, check if we have a pending HIGGS grab that timed out
	CheckPendingGrabTimeout()

	if !enableCBPC
		RegisterForSingleUpdate(cbpcCooldown)
		return
	EndIf

	; Skip CBPC touch processing if HIGGS is currently grabbing
	; (HIGGS events already sent the grab message, avoid duplicate)
	if isGrabbing
		ClearTouchedLocations()
		RegisterForSingleUpdate(cbpcCooldown)
		return
	EndIf

	if !hitThreshold
		ClearTouchedLocations()
		RegisterForSingleUpdate(cbpcCooldown)
		return
	EndIf

	string actorName = JMap.GetStr(touchedLocations, ACTOR_KEY)
	Actor akActor = JMap.GetForm(touchedLocations, ACTORREF_KEY) as Actor

	if actorName == ""
		ClearTouchedLocations()
		RegisterForSingleUpdate(cbpcCooldown)
		return
	EndIf

	string playerName = playerRef.GetDisplayName()
	float currentTime = Utility.GetCurrentRealTime()

	; Debounce: Skip if this is the same actor and body part we just reported recently
	; This prevents spamming the same touch message
	float debounceWindow = cbpcCooldown * 2.0  ; 4 seconds by default
	if akActor == lastReportedActor && locationHit == lastReportedBodyPart
		if currentTime - lastReportedTime < debounceWindow
			ClearTouchedLocations()
			RegisterForSingleUpdate(cbpcCooldown)
			return
		EndIf
	EndIf

	; Check cooldown
	if currentTime - lastPhysicsSpeechTime < cbpcCooldown
		ClearTouchedLocations()
		RegisterForSingleUpdate(cbpcCooldown)
		return
	EndIf

	; ============================================
	; CBPC TOUCH - MINIMAL PSC
	; All logic handled by PHP backend (nsfw_physics.php)
	; PSC only sends raw event data
	; ============================================

	; Get raw data
	bool wasPenetration = (JMap.GetInt(touchedLocations, GENITAL_COLLISION_KEY) == 1)
	bool isBlocked = false
	string blockedBy = ""

	; Check for chastity blocking (raw data only)
	if akActor && hasDeviousDevices
		if (locationHit == VAGINAL_KEY || locationHit == ANAL_KEY) && IsWearingChastityBelt(akActor)
			isBlocked = true
			blockedBy = "chastity belt"
		elseif locationHit == BREASTS_KEY && IsWearingChastityBra(akActor)
			isBlocked = true
			blockedBy = "chastity bra"
		endif
	EndIf

	; Get player sex for penetration context
	int playerSex = playerRef.GetActorBase().GetSex()

	; Send RAW data to PHP - let PHP handle all logic
	; Format: actor^bodypart^action^blocked^blockedby^penetration^playersex (using ^ to avoid CHIM pipe conflict)
	string rawData = actorName + "^" + locationHit + "^touch^" + isBlocked + "^" + blockedBy + "^" + wasPenetration + "^" + playerSex
	Debug.Trace("[CHIM-NSFW] CBPC Raw: " + rawData)

	AIAgentFunctions.logMessage(rawData, "ext_nsfw_physics_raw")
	lastPhysicsSpeechTime = currentTime

	; Update debounce tracking
	lastReportedActor = akActor
	lastReportedBodyPart = locationHit
	lastReportedTime = currentTime

	ClearTouchedLocations()
	RegisterForSingleUpdate(cbpcCooldown)
EndEvent

Event OnInit()
		Debug.Notification("[CHIM-NSFW] First installed")
		Debug.Trace("[CHIM-NSFW]: OnInit called for the first time")
		map = JMap.object()
		versionCheck = 2

		DoRegister()
		RegisterForSleep()
EndEvent

Event OnSleepStart(float afSleepStartTime, float afDesiredSleepEndTime)
	DoRegister()
EndEvent

Event HelperSpeechStart(Form npc)
	Debug.Trace("[CHIM NSFW] HelperSpeechStart")

	; Register for HIGGS events if not already done
	if !hasHIGGS && Game.GetModByName("higgs_vr.esp") != 255
		HiggsVR.RegisterForGrabEvent(self)
		HiggsVR.RegisterForDropEvent(self)
		hasHIGGS = true
		Debug.Trace("[CHIM-NSFW] Registered for HIGGS events")
	endif

	StorageUtil.SetIntValue(npc, "IS_SPEAKING", 1)
	int running=OThread.GetThreadCount();
	if (running==0)
		return
	endif
	Actor akActor=npc as Actor
	if (akActor)
		OActor.Mute(akActor)
		OActor.ClearExpression(akActor)
		OActor.StallClimax(akActor)
		OActor.SetExcitementMultiplier(akActor,0)
		akActor.SetFactionRank(noFacialExpressionsFaction,1)

		Debug.Trace("[CHIM NSFW] "+akActor.GetDisplayName()+" is muted for moan (is speaking)")
	else
		Debug.Trace("[CHIM NSFW] no actor")
	EndIf

EndEvent

Event HelperSpeechStop(Form npc)

	Debug.Trace("[CHIM NSFW] HelperSpeechStop")
	StorageUtil.SetIntValue(npc, "IS_SPEAKING", 0)
		
	int running=OThread.GetThreadCount();
	if (running==0)
		return
	endif
	
	Actor akActor=npc as Actor
	if (akActor)
	
		OActor.SetExcitementMultiplier(akActor,1)
		OActor.UnMute(akActor)
		OActor.PermitClimax(akActor)
		akActor.RemoveFromFaction(noFacialExpressionsFaction)
		float excitement=OActor.GetExcitement(akActor)
		Debug.Trace("[CHIM NSFW] "+akActor.GetDisplayName()+" is unmuted for moan, excitement:"+excitement)
		if (excitement>=100)
			;OActor.Climax(akActor,true)
			OActor.SetExcitement(akActor, 99)
		endif
	EndIf
EndEvent

string Function FormatNsfwCommandNotification(String npcname, String command, String parameter)
	if command == "ExtCmdPoisonMasterFood"
		return ""
	elseif command == "ExtCmdWhiskeyDick"
		return ""
	elseif command == "ExtCmdDrunkPhysics"
		return ""
	elseif command == "ExtCmdRefuseSex"
		return npcname + " refused intimacy."
	elseif command == "ExtCmdStopScene"
		return npcname + " ended the scene."
	elseif command == "ExtCmdStopNpcScene"
		return npcname + " ended the NPC scene."
	elseif command == "ExtCmdCollectPayment"
		return npcname + " collected payment."
	elseif command == "ExtCmdQuitProstitution"
		return npcname + " quit prostitution."
	elseif command == "ExtCmdWorshipMaster"
		return npcname + " worships their master."
	elseif command == "ExtCmdAskForFreedom"
		return npcname + " asks their master for freedom."
	elseif command == "ExtCmdAcceptFreedom"
		return npcname + " is freed by their master."
	elseif command == "ExtCmdSexCommand"
		if parameter != ""
			return npcname + " changed the scene: " + parameter
		endif
		return npcname + " changed the scene."
	elseif command == "ExtCmdHug"
		return npcname + " hugged " + parameter + "."
	elseif command == "ExtCmdHoldHands"
		return npcname + " held hands with " + parameter + "."
	elseif command == "ExtCmdKiss"
		return npcname + " kissed " + parameter + "."
	elseif command == "ExtCmdRemoveClothes"
		return npcname + " removed clothing."
	elseif command == "ExtCmdPutOnClothes"
		return npcname + " got dressed."
	elseif command == "ExtCmdStartMassage"
		return npcname + " started a massage with " + parameter + "."
	elseif command == "ExtCmdStartSex" || command == "ExtCmdStartThreesome" || command == "ExtCmdStartBlowJob" || command == "ExtCmdStartTitfuck" || command == "ExtCmdStartAnalSex" || command == "ExtCmdStartHandjobSex" || command == "ExtCmdStartHandJobSex"
		return npcname + " started an intimate scene with " + parameter + "."
	endif

	return ""
EndFunction


Event CommandManager(String npcname,String  command, String parameter)

	string readableCommand = FormatNsfwCommandNotification(npcname, command, parameter)
	if readableCommand != ""
		Debug.Notification(readableCommand)
	endif
	Debug.Trace("[CHIM NSFW] External command "+command+ " received for "+npcname+" parameter="+parameter)
	Actor npc=AIAgentFunctions.getAgentByName(npcname);

	; VAMPIRE DETECTION (report once per NPC): the SERVER cannot detect vampires because Skyrim collapses the race to
	; its base name ("Nord"), so the stored race never contains "vampire". Report it here the way Fertility Mode
	; Reloaded does - the RACE FORM carries a "Vampire" keyword even when its display name is just "Nord". Cached in
	; StorageUtil so the silent ext_nsfw_vampire fast event fires only once per actor per session. Gates DrinkBloodSex.
	if npc != None && StorageUtil.GetIntValue(npc, "sharmat_vampchecked", 0) == 0
		StorageUtil.SetIntValue(npc, "sharmat_vampchecked", 1)
		string vflag = "0"
		Race npcRace = npc.GetRace()
		if npcRace != None && npcRace.HasKeywordString("Vampire")
			vflag = "1"
		endif
		AIAgentFunctions.logMessage("VAMPIRE^" + npcname + "^" + vflag, "ext_nsfw_vampire")
		Debug.Trace("[CHIM-NSFW] Vampire check for " + npcname + " -> " + vflag)
	endif

	if (command=="ExtCmdWhiskeyDick")
		; PLAYER-ONLY EFFECT (fix 2026-07-02): the named NPC is just the delivery carrier (the DLL only
		; dispatches commands addressed to a registered agent) - everything here targets the PLAYER.
		Debug.Notification("Sharmat- You have Whiskey Dick!")
		Debug.SendAnimationEvent(Game.GetPlayer(), "SOSFlaccid") ; limp the schlong via SOS (base-game anim event; no-op if SOS/schlong absent)
		if parameter == "stopscene"
			; In-scene trigger: the carrier IS the player's scene partner - end the player's scene.
			Debug.Trace("[CHIM-NSFW] WhiskeyDick: notifying player, limping (SOSFlaccid), stopping player scene")
			command = "ExtCmdStopScene"
		else
			; On-drink trigger (no scene): do NOT fall through to StopScene - the carrier may be in her
			; own unrelated scene and must not be touched.
			Debug.Trace("[CHIM-NSFW] WhiskeyDick: notifying player, limping (SOSFlaccid) - no scene stop")
			return
		endif
	endif

	if (command=="ExtCmdPoisonMasterFood")
		SendModEvent("SHARMAT_ArmSlavePoison", npcname + "@" + parameter, 0.0)
		AIAgentFunctions.logMessageForActor("command@ExtCmdPoisonMasterFood@armed@poison trap armed", "funcret", npcname)
		return
	endif

	if (command=="ExtCmdCollectPayment")
		Form gold = Game.GetForm(0x0000000F)
		Actor playerRefLocal = Game.GetPlayer()
		String[] paymentParts = StringUtil.Split(parameter, "@")
		int amount = 0
		string serviceDesc = "services"
		if paymentParts.Length > 0
			amount = paymentParts[0] as int
		else
			amount = parameter as int
		endif
		if paymentParts.Length > 1
			serviceDesc = paymentParts[1]
		endif

		if amount <= 0
			Debug.Trace("[CHIM-NSFW] TakeGold failed: invalid amount '" + parameter + "'")
			AIAgentFunctions.logMessageForActor("command@ExtCmdCollectPayment@" + amount + "@payment_failed: invalid gold amount", "funcret", npcname)
			return
		endif

		int playerGold = playerRefLocal.GetItemCount(gold)
		if playerGold < amount
			Debug.Trace("[CHIM-NSFW] TakeGold failed: player has " + playerGold + " gold, needs " + amount)
			AIAgentFunctions.logMessageForActor("command@ExtCmdCollectPayment@" + amount + "@payment_failed: player has " + playerGold + " gold, needs " + amount, "funcret", npcname)
			return
		endif

		playerRefLocal.RemoveItem(gold, amount, true)
		if npc != None
			npc.AddItem(gold, amount, true)
		endif

		int playerGoldAfter = playerRefLocal.GetItemCount(gold)
		Debug.Trace("[CHIM-NSFW] TakeGold success: transferred " + amount + " gold to " + npcname + " for " + serviceDesc + ". Player gold now " + playerGoldAfter)
		AIAgentFunctions.logMessageForActor("command@ExtCmdCollectPayment@" + amount + "@payment_success: took " + amount + " gold for " + serviceDesc + "; player_gold_before=" + playerGold + "; player_gold_after=" + playerGoldAfter, "funcret", npcname)
		return
	endif

	if (command=="ExtCmdQuitProstitution")
		AIAgentFunctions.logMessageForActor("command@ExtCmdQuitProstitution@" + parameter + "@" + npcname + " stops working as a prostitute", "funcret", npcname)
		return
	endif

	if (command=="ExtCmdGiveFreeService")
		AIAgentFunctions.logMessageForActor("command@ExtCmdGiveFreeService@" + parameter + "@" + npcname + " gives this service for free", "funcret", npcname)
		return
	endif

	if (command=="ExtCmdWorshipMaster")
		AIAgentFunctions.logMessageForActor("command@ExtCmdWorshipMaster@" + parameter + "@" + npcname + " worships their master", "funcret", npcname)
		return
	endif

	if (command=="ExtCmdAskForFreedom")
		AIAgentFunctions.logMessageForActor("command@ExtCmdAskForFreedom@" + parameter + "@" + npcname + " asks for freedom", "funcret", npcname)
		return
	endif

	if (command=="ExtCmdAcceptFreedom")
		AIAgentFunctions.logMessageForActor("command@ExtCmdAcceptFreedom@" + parameter + "@" + npcname + " is freed by their master", "funcret", npcname)
		return
	endif

	if (command=="ExtCmdStartSex" || command=="ExtCmdStartThreesome" || command=="ExtCmdStartBlowJob" || command=="ExtCmdStartTitfuck" || command=="ExtCmdStartAnalSex" || command=="ExtCmdStartHandJobSex" || command=="ExtCmdStartHandjobSex" || command=="ExtCmdStartMassage" || command=="ExtCmdDrinkBloodSex")
		; REAL EXECUTION (2026-06-29): the old path only printed "started an intimate scene" and never animated.
		; Resolve the target and actually START (or JOIN, for a threesome) an OStim/SexLab scene via the engine.
		Actor sceneTarget = AIAgentFunctions.getAgentByName(parameter)
		if sceneTarget == None
			sceneTarget = Game.GetPlayer()
		endif
		; Steer scene selection by the act the model actually called, so StartBlowJob picks a blowjob scene,
		; StartAnalSex an anal scene, etc. (the engine falls back to a random scene if no tagged match exists).
		string sceneAct = ""
		if command=="ExtCmdStartSex"
			sceneAct = "vaginal"
		elseif command=="ExtCmdStartBlowJob"
			sceneAct = "oral"
		elseif command=="ExtCmdStartAnalSex"
			sceneAct = "anal"
		elseif command=="ExtCmdStartHandJobSex" || command=="ExtCmdStartHandjobSex"
			sceneAct = "handjob"
		elseif command=="ExtCmdStartTitfuck"
			sceneAct = "boobjob"
		elseif command=="ExtCmdStartMassage"
			sceneAct = "massage"
		elseif command=="ExtCmdDrinkBloodSex"
			sceneAct = "bloodfeed"
		endif
		bool sceneHandled = AIAgentNSFWSceneEngine.StartOrJoinScene(npc, sceneTarget, true, sceneAct)
		if sceneHandled
			AIAgentFunctions.logMessageForActor("command@" + command + "@" + parameter + "@" + npcname + " started/joined an intimate scene with " + parameter, "funcret", npcname)
		else
			AIAgentFunctions.logMessageForActor("command@" + command + "@" + parameter + "@error: could not start or join a scene with " + parameter, "funcret", npcname)
		endif
		return
	endif

	if (command=="ExtCmdDrunkPhysics")
		if npc == None
			Debug.Trace("[CHIM-NSFW] DrunkPhysics skipped: actor not found for " + npcname)
			return
		endif

		String[] drunkPhysParts = StringUtil.Split(parameter, "@")
		string reaction = "stumble"
		int stage = 0
		float force = 6.0
		bool requireMovement = true
		int movingFallChance = 60
		int standingFallChance = 20
		int stage9StumbleChance = 50
		if drunkPhysParts.Length > 0
			reaction = drunkPhysParts[0]
		endif
		if drunkPhysParts.Length > 1
			stage = drunkPhysParts[1] as int
		endif
		if drunkPhysParts.Length > 2
			force = drunkPhysParts[2] as float
		endif
		if drunkPhysParts.Length > 3
			requireMovement = (drunkPhysParts[3] as int) != 0
		endif
		if drunkPhysParts.Length > 4
			movingFallChance = drunkPhysParts[4] as int
		endif
		if drunkPhysParts.Length > 5
			standingFallChance = drunkPhysParts[5] as int
		endif
		if drunkPhysParts.Length > 6
			stage9StumbleChance = drunkPhysParts[6] as int
		endif

		float animSpeed = npc.GetAnimationVariableFloat("Speed")
		if animSpeed < 0.0
			animSpeed = 0.0 - animSpeed
		endif
		bool actorMoving = animSpeed > 0.10 || npc.IsRunning() || npc.IsSprinting()

		if requireMovement && !actorMoving && reaction != "stage9_roll"
			Debug.Trace("[CHIM-NSFW] DrunkPhysics skipped for " + npcname + ": stationary at stage " + stage + " (reaction " + reaction + ")")
			return
		endif

		if reaction == "stage9_roll"
			int roll = Utility.RandomInt(1, 100)
			int movingStumbleLimit = movingFallChance + stage9StumbleChance
			int standingStumbleLimit = standingFallChance + stage9StumbleChance
			if actorMoving
				if roll <= movingFallChance
					npc.PushActorAway(npc, 2.0)
					Debug.Trace("[CHIM-NSFW] DrunkPhysics stage9 moving fall fired for " + npcname + " speed " + animSpeed + " roll " + roll + "/" + movingFallChance)
				elseif roll <= movingStumbleLimit
					float angle9 = Utility.RandomFloat(0.0, 6.28318)
					npc.ApplyHavokImpulse(Math.Sin(angle9), Math.Cos(angle9), 0.0, force)
					Debug.Trace("[CHIM-NSFW] DrunkPhysics stage9 moving stumble fired for " + npcname + " speed " + animSpeed + " roll " + roll)
				else
					Debug.Trace("[CHIM-NSFW] DrunkPhysics stage9 moving no-op for " + npcname + " speed " + animSpeed + " roll " + roll)
				endif
			else
				if roll <= standingFallChance
					npc.PushActorAway(npc, 0.8)
					Debug.Trace("[CHIM-NSFW] DrunkPhysics stage9 standing fall fired for " + npcname + " roll " + roll + "/" + standingFallChance)
				elseif roll <= standingStumbleLimit
					float angle9s = Utility.RandomFloat(0.0, 6.28318)
					npc.ApplyHavokImpulse(Math.Sin(angle9s), Math.Cos(angle9s), 0.0, force)
					Debug.Trace("[CHIM-NSFW] DrunkPhysics stage9 standing stumble fired for " + npcname + " roll " + roll)
				else
					Debug.Trace("[CHIM-NSFW] DrunkPhysics stage9 stationary no-op for " + npcname + " roll " + roll)
				endif
			endif
		elseif reaction == "fall"
			npc.PushActorAway(npc, 2.0)
			Debug.Trace("[CHIM-NSFW] DrunkPhysics fall fired for " + npcname + " at stage " + stage + " speed " + animSpeed)
		else
			float angle = Utility.RandomFloat(0.0, 6.28318)
			npc.ApplyHavokImpulse(Math.Sin(angle), Math.Cos(angle), 0.0, force)
			Debug.Trace("[CHIM-NSFW] DrunkPhysics stumble fired for " + npcname + " at stage " + stage + " speed " + animSpeed + " force " + force)
		endif
		return
	endif
	
	if (command=="ExtCmdRemoveClothes")
	
		Int modIndex = Game.GetModByName("_GSPoses.esp")
		;FastRemoveClothes(npc)
		if modIndex != 255
			AIAgentFunctions.logMessage(npcname+" starts to remove clothing slowly","ext_nsfw_action")
			GSPoseRemoveClothes(npc,npc)
			FastRemoveClothes(npc)
		else
			FastRemoveClothes(npc)
		endIf
		
		
		;npc.UnequipAll()
		AIAgentFunctions.logMessageForActor("The Narrator:" + npcname+" is now naked.","chatnf_sl_naked",npcname)
		AIAgentFunctions.logMessageForActor("command@ExtCmdRemoveClothes@@"+npcname+" removes clothes and armor","funcret",npcname)
		
	endif
	
	if (command=="ExtCmdPutOnClothes")
		
		
		
		npc.EquipItem(GetBestArmorForSlot(npc, 0x00000004), false, true) ; Body
		npc.EquipItem(GetBestArmorForSlot(npc, 0x00000008), false, true) ; Hands
		npc.EquipItem(GetBestArmorForSlot(npc, 0x00000010), false, true) ; Forearms
		npc.EquipItem(GetBestArmorForSlot(npc, 0x00000020), false, true) ; Feet
		npc.EquipItem(GetBestArmorForSlot(npc, 0x00000040), false, true) ; Calves
		npc.EquipItem(GetBestArmorForSlot(npc, 0x00000080), false, true) ; Shield
	
		AIAgentFunctions.logMessageForActor("command@ExtCmdPutOnClothes@@"+npcname+" puts on clothes and armor","funcret",npcname)

	
	endIf	
	if (command=="ExtCmdKiss")

		Actor kissedActor=None
		bool IsPlayerInvolved=false

		Package doNothing = Game.GetForm(0x654e2) as Package ; Package Travelto
		ImageSpaceModifier FadeToBlack = Game.GetForm(0x000f756d) as ImageSpaceModifier
		ImageSpaceModifier FadeFromBlack = Game.GetForm(0x000f756f) as ImageSpaceModifier


		If (StringUtil.find(parameter,Game.GetPlayer().GetDisplayName()) !=-1)
			; PLayer involved
			string result = GetPlayerConsent(npc.GetDisplayName(), "wants to kiss you. Allow?")
			if result == "No, thanks"
				AIAgentFunctions.logMessageForActor("command@ExtCmdKiss@"+parameter+"@error. Player refused kiss","funcret",npcname)
				return;
			else
				kissedActor=Game.GetPlayer()
				IsPlayerInvolved=true;
			endif
		else
			kissedActor = AIAgentFunctions.getAgentByName(parameter)
		endif

		AIAgentAIMind.ResetPackages(npc)
		UnequipItemBySlot(npc, 0x00000080); Feet.

		if kissedActor==None
			AIAgentFunctions.requestMessageForActor("command@ExtCmdKiss@"+parameter+"@error. target nout found;"+parameter+"","funcret",npcname)
			return
		endif


		if (kissedActor.IsOnMount())
			AIAgentFunctions.logMessageForActor("command@ExtCmdKiss@"+parameter+"@error. "+kissedActor+" is on mount","funcret",npcname)
			return
		endif

		if (npc.IsOnMount())
			AIAgentFunctions.logMessageForActor("command@ExtCmdKiss@"+parameter+"@error. "+npcname+" is on mount","funcret",npcname)
			return
		endif

		int kissedActorStatus= StorageUtil.GetIntValue(kissedActor, "chim_kiss_status", 0)
		int npcStatus= StorageUtil.GetIntValue(npc, "chim_kiss_status", 0)

		if (kissedActorStatus != 0 || npcStatus != 0)
			AIAgentFunctions.logMessageForActor("command@ExtCmdKiss@"+parameter+"@error. "+npcname+" is already kissing someone","funcret",npcname)
			return
		endif

		Debug.trace("[CHIM-NSFW] "+npcname+" want to kiss "+kissedActor.GetDisplayName());

		if (npc.GetSitState()==3 || npc.GetSitState()==2)
			Debug.SendAnimationEvent(npc, "IdleForceDefaultState")
		endif

		if (kissedActor.GetSitState()==3 || kissedActor.GetSitState()==2)
			Debug.SendAnimationEvent(kissedActor, "IdleForceDefaultState")
		endif

		; ============================================
		; VR MODE: Use OStim for proper actor alignment
		; ============================================
		if isVRMode && IsPlayerInvolved
			Debug.Trace("[CHIM-NSFW] VR Kiss: Using OStim for proper alignment")

			ActorUtil.AddPackageOverride(npc, doNothing, 100, 0)
			npc.EvaluatePackage()

			; Build actor array for OStim
			Actor[] kissActors = new Actor[2]
			kissActors[0] = npc
			kissActors[1] = kissedActor

			; Sort actors for OStim compatibility
			Actor[] sortedActors = OActorUtil.Sort(kissActors, OActorUtil.toArray())

			if OActor.VerifyActors(sortedActors)
				; Use OStim kiss scene - try multiple scene IDs
				string kissScene = OLibrary.GetRandomSceneWithAnySceneTagCSV(sortedActors, "kissing,frenchkissing")
				if kissScene == ""
					kissScene = "OARE_StandingKiss"
				endif

				; Start the scene - OStim will align actors properly for VR
				int builderID = OThreadBuilder.create(sortedActors)
				OThreadBuilder.SetStartingAnimation(builderID, kissScene)
				int kissThreadID = OThreadBuilder.Start(builderID)

				Debug.Trace("[CHIM-NSFW] VR Kiss: Started OStim thread " + kissThreadID + " with scene: " + kissScene)

				StorageUtil.SetIntValue(kissedActor, "chim_kiss_status", 1)
				StorageUtil.SetIntValue(npc, "chim_kiss_status", 1)

				AIAgentFunctions.logMessage(npcname+" kisses "+parameter,"ext_nsfw_action")
				AIAgentFunctions.setAnimationBusy(1, npcname)

				; Wait for the kiss duration then stop the scene
				Wait(10)

				; Stop the OStim scene
				if kissThreadID >= 0 && OThread.IsRunning(kissThreadID)
					OThread.Stop(kissThreadID)
				endif

				AIAgentFunctions.setAnimationBusy(0, npcname)
				StorageUtil.SetIntValue(kissedActor, "chim_kiss_status", 0)
				StorageUtil.SetIntValue(npc, "chim_kiss_status", 0)
			else
				Debug.Trace("[CHIM-NSFW] VR Kiss: Actor verification failed")
				AIAgentFunctions.logMessageForActor("command@ExtCmdKiss@"+parameter+"@error. Could not start kiss animation","funcret",npcname)
			endif

			ActorUtil.RemovePackageOverride(npc, doNothing)
			npc.EvaluatePackage()
			npc.EquipItem(GetBestArmorForSlot(npc, 0x00000080), false, true) ; Feet

			AIAgentFunctions.logMessageForActor("command@ExtCmdKiss@"+parameter+"@"+npcname+" gave a kiss to "+parameter+"","funcret",npcname)

		else
			; ============================================
			; DESKTOP MODE: Original behavior
			; ============================================
			if IsPlayerInvolved
				Game.DisablePlayerControls();
			endif

			ActorUtil.AddPackageOverride(npc, doNothing, 100, 0)
			npc.EvaluatePackage()

			Wait(0.1)

			fadeToBlack.Apply() ; fade in 1 second

			StorageUtil.SetIntValue(kissedActor, "chim_kiss_status", 1)
			StorageUtil.SetIntValue(npc, "chim_kiss_status", 1)

			Wait(2)

			if (IsPlayerInvolved)
				Game.FadeOutGame(False,True,50, 1)
			endif

			npc.SetDontMove()

			if (IsPlayerInvolved)
				Utility.SetIniBool("bDisablePlayerCollision:Havok", True)
			endif

			float angle = kissedActor.GetAngleZ()
			float forwardX = 0.5 * Math.Sin(angle)
			float forwardY = 0.5 * Math.Cos(angle)
			float rightX = 2.0 * Math.Cos(angle)
			float rightY = -2.0 * Math.Sin(angle)

			npc.SetAnimationVariableBool("bHumanoidFootIKDisable", True) ; disable inverse kinematics
			Debug.trace(kissedActor.getDisplayName()+ ":" +kissedActor.GetHeight()+" "+kissedActor.GetScale()+", "+npc.getDisplayName()+ ":" +npc.GetHeight()+" "+npc.GetScale())
			float heightA = kissedActor.GetHeight() * kissedActor.GetScale()
			float heightB = npc.GetHeight() * npc.GetScale()
			float zOffset = heightA - heightB - 3.5

			npc.MoveTo(kissedActor, forwardX + rightX, forwardY + rightY, zOffset)

			if (IsPlayerInvolved)
				Game.ForceThirdPerson();
			else
				kissedActor.SetAnimationVariableInt("IsNPC", 0) ; disable head tracking
			endif

			npc.SetAnimationVariableInt("IsNPC", 0) ; disable head tracking
			npc.SetAlpha(1.0, true) ; true desactiva fading automático

			AIAgentFunctions.logMessage(npcname+" kisses "+parameter,"ext_nsfw_action")

			debug.sendanimationevent(kissedActor, "standingkiss2")
			debug.sendanimationevent(npc, "standingkiss1")

			if (IsPlayerInvolved)
				Game.FadeOutGame(False,True,0.1, 0.1)
				FadeToBlack.PopTo(FadeFromBlack)
			endif

			MfgConsoleFunc.SetPhoneme(npc, 1, 90)
			MfgConsoleFunc.SetModifier(npc, 0, 90)
			MfgConsoleFunc.SetModifier(npc, 1, 90)

			AIAgentFunctions.setLocked(1,npcname)

			int totalTime = 20
			float stepTime = 0.5
			int steps = (totalTime / stepTime) as int
			float time = 0.0

			while (time < totalTime)
				float rawValue = 10.0 + 80.0 * Math.Sin((time / totalTime) * 6.28 * 32)
				int phonemeValue = rawValue as int
				if (phonemeValue<0)
					phonemeValue = 0
				endif
				MfgConsoleFunc.SetPhoneme(npc, 1, phonemeValue)
				MfgConsoleFunc.SetPhoneme(kissedActor, 1, phonemeValue)

				Wait(stepTime*2)
				time += stepTime*2
			endWhile

			; Reset to relaxed mouth
			MfgConsoleFunc.SetPhoneme(npc, 1, 10)
			MfgConsoleFunc.SetPhoneme(kissedActor, 1, 10)

			AIAgentFunctions.setLocked(0,npcname)
			AIAgentFunctions.logMessageForActor("command@ExtCmdKiss@"+parameter+"@"+npcname+" gave a kiss to "+parameter+"","funcret",npcname)

			debug.sendanimationevent(npc, "idleforcedefaultstate")
			debug.sendanimationevent(kissedActor, "idleforcedefaultstate")

			npc.SetAnimationVariableInt("IsNPC", 1) ; enable head tracking
			npc.SetAnimationVariableBool("bHumanoidFootIKDisable", False) ; enable inverse kinematics

			npc.EquipItem(GetBestArmorForSlot(npc, 0x00000080), false, true) ; Feet

			MfgConsoleFunc.ResetPhonemeModifier(npc);reset
			ActorUtil.RemovePackageOverride(npc, doNothing)
			npc.EvaluatePackage()
			npc.SetDontMove(false)

			if IsPlayerInvolved
				Utility.SetIniBool("bDisablePlayerCollision:Havok",false)
				Game.EnablePlayerControls();
			else
				kissedActor.SetAnimationVariableInt("IsNPC", 1) ; enable head tracking again
			endif

			StorageUtil.SetIntValue(kissedActor, "chim_kiss_status", 0)
			StorageUtil.SetIntValue(npc, "chim_kiss_status", 0)
		endif

	endIf	
	
	if (command=="ExtCmdVampireBiteFeed")

		AIAgentAIMind.ResetPackages(npc)
		Wait(0.1)

		; ============================================
		; VR MODE: Use OStim vampire feeding scene
		; ============================================
		if isVRMode
			Debug.Trace("[CHIM-NSFW] VR Vampire Feed: Using OStim for proper alignment")

			Actor receiver = Game.GetPlayer()

			; Build actor array for OStim
			Actor[] feedActors = new Actor[2]
			feedActors[0] = npc
			feedActors[1] = receiver

			; Sort actors for OStim compatibility
			Actor[] sortedActors = OActorUtil.Sort(feedActors, OActorUtil.toArray())

			if OActor.VerifyActors(sortedActors)
				; Use OARE vampire bite scene for proper VR alignment
				; Choose scene based on player's sex (player is the victim being bitten)
				string feedScene = "OARE_DevourVampireBiteFemaleStanding"
				if receiver.GetActorBase().GetSex() == 0  ; 0 = Male, 1 = Female
					feedScene = "OARE_DevourVampireBiteMaleStanding"
				endif

				; Start the scene - OStim will align actors properly for VR
				int builderID = OThreadBuilder.create(sortedActors)
				OThreadBuilder.SetStartingAnimation(builderID, feedScene)
				int feedThreadID = OThreadBuilder.Start(builderID)

				Debug.Trace("[CHIM-NSFW] VR Vampire Feed: Started OStim thread " + feedThreadID + " with scene: " + feedScene)

				; Wait for the feed duration then stop
				AIAgentFunctions.setAnimationBusy(1, npcname)
				Wait(5)

				; Stop the OStim scene
				if feedThreadID >= 0 && OThread.IsRunning(feedThreadID)
					OThread.Stop(feedThreadID)
				endif

				AIAgentFunctions.setAnimationBusy(0, npcname)
			else
				Debug.Trace("[CHIM-NSFW] VR Vampire Feed: Actor verification failed")
			endif

		else
			; ============================================
			; DESKTOP MODE: Original behavior
			; ============================================
			npc.PathToReference(Game.GetPlayer(), 0.5);Move it next to it

			Idle feedIdle=Game.GetForm(0x0200E6A8) as Idle
			if (npc.PlayIdleWithTarget(feedIdle,Game.GetPlayer()))
					Wait(5)

			endif


			if (Game.GetPlayer().GetName()==parameter)
				;npc.StartVampireFeed(Game.GetPlayer())
			else
				;npc.StartVampireFeed(Game.GetPlayer())
			endif
		endif

		AIAgentFunctions.logMessageForActor("command@ExtCmdVampireBiteFeed@"+parameter+"@"+npcname+" bites an feeds from "+parameter+", arousal raises","funcret",npcname)


	endIf	
	
	if (command=="ExtCmdConsumeSoul")

		Actor victim=None
		bool IsPlayerInvolved=false
		
		If (StringUtil.find(parameter,Game.GetPlayer().GetDisplayName()) !=-1)
			; PLayer involved
			string result = GetPlayerConsent(npc.GetDisplayName(), "wants to take a taste of you. Allow?")
			if result == "No, thanks"
				AIAgentFunctions.logMessageForActor("command@ExtCmdConsumeSoul@"+parameter+"@error. Player refused ritual","funcret",npcname)
				return;
			else
				victim=Game.GetPlayer()
				IsPlayerInvolved=true;
				Game.DisablePlayerControls();
			endif	
		else
			victim = AIAgentFunctions.getAgentByName(parameter)
		endif 

		if (victim && !IsPlayerInvolved)

			Package doNothing = Game.GetForm(0x654e2) as Package ; Package doNothing
			ActorUtil.AddPackageOverride(npc, doNothing, 100, 0)
			npc.EvaluatePackage()


			AIAgentFunctions.setAnimationBusy(1,npcname)
			npc.SetLookAt(victim,true)
			
			;npc.MoveTo(victim, 1);Move it next to it
			MiscObject sacrifficeCoin=Game.GetForm(0x0000000f)	as MiscObject; Necklace
			ObjectReference sacrifficeCoinRef=victim.PlaceAtMe(sacrifficeCoin,1);
			
			float heading = victim.GetAngleZ()
			;float headingRad = heading * 0.0174533 
			float dist = 120.0 ; or whatever distance you want
			float xOffset = dist * Math.Sin(heading)
			float yOffset = dist * Math.Cos(heading)
			
			sacrifficeCoinRef.MoveTo(victim, xOffset, yOffset, 0.0, true);
			
			npc.PathToReference(sacrifficeCoinRef, 1);Move it next to it
			
			; Move the NPC in front of the victim's facing
			
			;npc.MoveTo(sacrifficeCoinRef,0,0,0,true)
			;npc.SetAngle(0.0, 0.0, victim.GetAngleZ()*-1)
			Wait(1)
			Debug.SendAnimationEvent(npc, "IdleRitualSkull1")
			npc.SetDontMove(true)
			;victim.SplineTranslateToRef(npc, 1.0, 10.0, 10)
			Wait(5)
			Debug.SendAnimationEvent(npc, "IdleRitualSkull2")
			AIAgentFunctions.setLocked(1,npcname)
			
			Spell absorbHealthSpell = Game.GetForm(0x0008d5c3) as Spell ; Replace with the actual FormID and plugin name
			if absorbHealthSpell
				absorbHealthSpell.Cast(npc, victim)
			else
				Debug.Trace("[CHIM-NSFW] Absorb Health spell not found!")
			endif
			
			
			

			Wait(5)
			npc.InterruptCast();
			victim.KillSilent(npc)
			
			
			npc.SetDontMove(false)
			AIAgentFunctions.setLocked(0,npcname)
			AIAgentFunctions.setAnimationBusy(0,npcname)
			npc.activate(sacrifficeCoinRef)
			npc.ClearLookAt()
			
			ActorUtil.RemovePackageOverride(npc, doNothing)
			npc.EvaluatePackage()
			
			AIAgentFunctions.logMessageForActor("command@ExtCmdConsumeSoul@"+parameter+"@"+npcname+" consumed +"+parameter+" soul","funcret",npcname)

		endif
	endIf	
	
	if (command=="ExtCmdHug")


		string result = GetPlayerConsent(npc.GetDisplayName(), "wants to hug you. Allow?")
		if result == "No, thanks"
			AIAgentFunctions.logMessageForActor("command@"+command+"@"+parameter+"@error. Player refused to hug","funcret",npcname)
			return;
		endif



		Actor receiver=Game.GetPlayer();
		if (npc.GetSitState()==3 || npc.GetSitState()==2) ; Dont use feature if player is not sitting, or is on a mount
			Debug.SendAnimationEvent(npc, "IdleForceDefaultState")
		endif
		;npc.StartVampireFeed(Game.GetPlayer())

		if (npc.GetDistance(receiver)>512)
			AIAgentFunctions.logMessageForActor("command@"+command+"@"+parameter+"@error. Player is too far away to hug","funcret",npcname)
			return;
		endif

		; ============================================
		; VR MODE: Use OStim for proper actor alignment
		; ============================================
		; In VR, PathToReference doesn't work well because the player's
		; body position and head position can differ. OStim handles
		; VR actor alignment properly through its scene system.
		; ============================================
		if isVRMode
			Debug.Trace("[CHIM-NSFW] VR Hug: Using OStim for proper alignment")

			AIAgentAIMind.ResetPackages(npc)
			Package doNothing = Game.GetForm(0x654e2) as Package
			ActorUtil.AddPackageOverride(npc, doNothing, 100, 0)
			npc.EvaluatePackage()

			; Build actor array for OStim
			Actor[] hugActors = new Actor[2]
			hugActors[0] = npc
			hugActors[1] = receiver

			; Sort actors for OStim compatibility
			Actor[] sortedActors = OActorUtil.Sort(hugActors, OActorUtil.toArray())

			if OActor.VerifyActors(sortedActors)
				; Use OARE standing hug scene for proper VR alignment
				string hugScene = "OARE_StandingHug"

				; Start the scene - OStim will align actors properly for VR
				int builderID = OThreadBuilder.create(sortedActors)
				OThreadBuilder.SetStartingAnimation(builderID, hugScene)
				int hugThreadID = OThreadBuilder.Start(builderID)

				Debug.Trace("[CHIM-NSFW] VR Hug: Started OStim thread " + hugThreadID + " with scene: " + hugScene)

				; Wait for the hug duration then stop the scene
				AIAgentFunctions.setAnimationBusy(1, npcname)
				Wait(5)

				; Stop the OStim scene
				if hugThreadID >= 0 && OThread.IsRunning(hugThreadID)
					OThread.Stop(hugThreadID)
				endif

				AIAgentFunctions.setAnimationBusy(0, npcname)
			else
				Debug.Trace("[CHIM-NSFW] VR Hug: Actor verification failed")
				AIAgentFunctions.logMessageForActor("command@"+command+"@"+parameter+"@error. Could not start hug animation","funcret",npcname)
			endif

			ActorUtil.RemovePackageOverride(npc, doNothing)
			npc.EvaluatePackage()

			AIAgentFunctions.logMessageForActor("command@ExtCmdHug@"+parameter+"@"+npcname+" gives a hug to "+parameter+"","funcret",npcname)

		else
			; ============================================
			; DESKTOP MODE: Original behavior
			; ============================================
			AIAgentAIMind.ResetPackages(npc)
			Package doNothing = Game.GetForm(0x654e2) as Package ; Package doNothing
			ActorUtil.AddPackageOverride(npc, doNothing, 100, 0)
			npc.EvaluatePackage()
			Wait(0.1)


			MiscObject hugCoin=Game.GetForm(0x0000000f)	as MiscObject; Necklace
			ObjectReference hugCoinRef=receiver.PlaceAtMe(hugCoin,1);

			float heading = receiver.GetAngleZ()
			;float headingRad = heading * 0.0174533
			float dist = 64.0 ; or whatever distance you want
			float xOffset = dist * Math.Sin(heading)
			float yOffset = dist * Math.Cos(heading)

			hugCoinRef.MoveTo(receiver, xOffset, yOffset, 0.0, true);



			AIAgentFunctions.setAnimationBusy(1,npcname)
			npc.SetLookAt(receiver)
			npc.PathToReference(hugCoinRef, 1);Move it next to it


			Utility.SetIniBool("bDisablePlayerCollision:Havok", True)
			npc.SetAnimationVariableBool("bHumanoidFootIKDisable", True)


			;if (npc.GetDistance(receiver)<256)
			;	npc.MoveTo(receiver, 1);Move it next to it
			;else
			;	npc.MoveTo(receiver, 1);Move it next to it
			;endif



			Idle hugIdle=Game.GetForm(0x0f4699) as Idle
			;int CameraState=Game.GetCameraState();
			Game.ForceThirdPerson();
			npc.PlayIdleWithTarget(hugIdle,Game.GetPlayer())
			npc.SetDontMove(true)
			Wait(5)
			npc.SetDontMove(false)
			AIAgentFunctions.setLocked(0,npcname)
			AIAgentFunctions.setAnimationBusy(0,npcname)
			npc.activate(hugCoinRef)
			npc.ClearLookAt()
			Utility.SetIniBool("bDisablePlayerCollision:Havok", False)
			npc.SetAnimationVariableBool("bHumanoidFootIKDisable", False)

			ActorUtil.RemovePackageOverride(npc, doNothing)
			npc.EvaluatePackage()
			;if (CameraState==0)
			;	Game.ForceFirstPerson();
			;endif;

			AIAgentFunctions.logMessageForActor("command@ExtCmdHug@"+parameter+"@"+npcname+" gives a hug to "+parameter+"","funcret",npcname)
		endif

	endIf	
	
	if (command=="ExtCmdHoldHands")


		string result = GetPlayerConsent(npc.GetDisplayName(), "wants to hold your hand. Allow?")
		if result == "No, thanks"
			AIAgentFunctions.logMessageForActor("command@"+command+"@"+parameter+"@error. Player refused to hold hands","funcret",npcname)
			return;
		endif

		Actor receiver=Game.GetPlayer();
		if (npc.GetSitState()==3 || npc.GetSitState()==2)
			Debug.SendAnimationEvent(npc, "IdleForceDefaultState")
		endif

		if (npc.GetDistance(receiver)>512)
			AIAgentFunctions.logMessageForActor("command@"+command+"@"+parameter+"@error. Player is too far away to hold hands","funcret",npcname)
			return
		endif

		Debug.Trace("[CHIM-NSFW] HoldHands: Using OStim for hand-holding alignment")

		AIAgentAIMind.ResetPackages(npc)
		Package doNothing = Game.GetForm(0x654e2) as Package
		ActorUtil.AddPackageOverride(npc, doNothing, 100, 0)
		npc.EvaluatePackage()

		Actor[] handActors = new Actor[2]
		handActors[0] = npc
		handActors[1] = receiver

		Actor[] sortedActors = OActorUtil.Sort(handActors, OActorUtil.toArray())

		if OActor.VerifyActors(sortedActors)
			string handScene = "OARE_StandingHandHolding"

			int builderID = OThreadBuilder.create(sortedActors)
			OThreadBuilder.SetStartingAnimation(builderID, handScene)
			int handThreadID = OThreadBuilder.Start(builderID)

			Debug.Trace("[CHIM-NSFW] HoldHands: Started OStim thread " + handThreadID + " with scene: " + handScene)

			AIAgentFunctions.setAnimationBusy(1, npcname)
			Wait(5)

			if handThreadID >= 0 && OThread.IsRunning(handThreadID)
				OThread.Stop(handThreadID)
			endif

			AIAgentFunctions.setAnimationBusy(0, npcname)
		else
			Debug.Trace("[CHIM-NSFW] HoldHands: Actor verification failed")
			AIAgentFunctions.logMessageForActor("command@"+command+"@"+parameter+"@error. Could not start hand-holding animation","funcret",npcname)
		endif

		ActorUtil.RemovePackageOverride(npc, doNothing)
		npc.EvaluatePackage()

		AIAgentFunctions.logMessageForActor("command@ExtCmdHoldHands@"+parameter+"@"+npcname+" holds hands with "+parameter+"","funcret",npcname)

	endIf	
	
	if (command=="ExtCmdStartSex" || command=="ExtCmdStartThreesome" || command=="ExtCmdStartBlowJob" || command=="ExtCmdStartMassage" || command=="ExtCmdStartTitfuck" || command=="ExtCmdStartAnalSex" || command=="ExtCmdStartHandjobSex" || command=="ExtCmdStartHandJobSex")
		
		
		noFacialExpressionsFaction = Game.GetFormFromFile(0xD92, "OStim.esp") as Faction ; Package Travelto
	
		If (StringUtil.find(parameter,Game.GetPlayer().GetDisplayName()) !=-1)
			; PLayer involved
			string result = GetPlayerConsent(npc.GetDisplayName(), "wants to have sex with you. Allow?")
			if result == "No, thanks"
				AIAgentFunctions.logMessageForActor("command@"+command+"@"+parameter+"@error. Player refused to start sex","funcret",npcname)
				return;
			endif
		endif 
			
		Actor[] actorsInvolved=PrepareScene(npc,parameter);
		
		
		
		;AIAgentFunctions.logMessage("ENJOY THE SEX SCENE!. NO QUEST, NO DUTIES NOW. ONLY PLEASURE","force_current_task")
		AIAgentFunctions.logMessageForActor("command@"+command+"@"+parameter+"@Intimate scene starts","funcret",npcname)

		; ENGINE SELECT: OStim if installed, otherwise SexLab. Both use actorsInvolved, which PrepareScene has
		; already populated with the PLAYER as the default partner. The OStim path continues below unchanged;
		; the SexLab path starts the scene here and returns. (Verified against SexLabUtil.QuickStart signature.)
		if (Game.GetModByName("OStim.esp") == 255)
			string slTag = ""
			if (command=="ExtCmdStartSex")
				slTag = "Vaginal"
			elseif (command=="ExtCmdStartBlowJob")
				slTag = "Blowjob"
			elseif (command=="ExtCmdStartAnalSex")
				slTag = "Anal"
			elseif (command=="ExtCmdStartTitfuck")
				slTag = "Boobjob"
			elseif (command=="ExtCmdStartHandjobSex" || command=="ExtCmdStartHandJobSex")
				slTag = "Handjob"
			endif
			Actor slA2 = none
			Actor slA3 = none
			Actor slA4 = none
			Actor slA5 = none
			if (actorsInvolved.Length > 1)
				slA2 = actorsInvolved[1]
			endif
			if (actorsInvolved.Length > 2)
				slA3 = actorsInvolved[2]
			endif
			if (actorsInvolved.Length > 3)
				slA4 = actorsInvolved[3]
			endif
			if (actorsInvolved.Length > 4)
				slA5 = actorsInvolved[4]
			endif
			sslThreadController slc = SexLabUtil.QuickStart(actorsInvolved[0], slA2, slA3, slA4, slA5, none, "", slTag)
			if (!slc && slTag != "")
				; Tag found no matching animation in the player's pack - retry untagged so a scene still starts.
				slc = SexLabUtil.QuickStart(actorsInvolved[0], slA2, slA3, slA4, slA5, none, "", "")
			endif
			Debug.Trace("[CHIM-NSFW] Launched SexLab scene (tag="+slTag+", actors="+actorsInvolved.Length+")")
			return
		endif

		mdi=AIAgentFunctions.get_conf_i("_max_distance_inside");
		mdo=AIAgentFunctions.get_conf_i("_max_distance_outside");
		
		Debug.Trace("[CHIM-NSFW] Enabling intimacy bubble: saving settings: "+mdi+","+mdo);
		
		AIAgentFunctions.setConf("_max_distance_inside",256,256,256);
		AIAgentFunctions.setConf("_max_distance_outside",256,256,256);
		
		
		Actor[] finalActorsInvolved= OActorUtil.Sort(actorsInvolved,OActorUtil.toArray());
		Debug.Trace("[CHIM-NSFW] Actors sorted");
		
		if (OActor.VerifyActors(actorsInvolved))
			Debug.Trace("[CHIM-NSFW] Actors verified");
			
			String initialSceneText="";
			if (command=="ExtCmdStartSex" )
				initialSceneText="vaginalsex";
			elseif (command=="ExtCmdStartThreesome" )
				initialSceneText="idle";
			elseif	(command=="ExtCmdStartBlowJob")
				;should wait here to stop speaking, if speaking
				int limit = 15
				int n = 0
				while (n < limit)
					Utility.Wait(2)
					if StorageUtil.GetIntValue(npc, "IS_SPEAKING", 0) == 0
						n = limit
					else
						Debug.Trace("[CHIM-NSFW] Actor "+npc+" speaking, delaying oral");
					endif

					n = n+1
				EndWhile

				initialSceneText="blowjob";
				
			elseif (command=="ExtCmdStartMassage")
				
				initialSceneText="cuddling";
				
			elseif (command=="ExtCmdStartTitfuck")
				int limit = 15
				int n = 0
				while (n < limit)
					Utility.Wait(2)
					if StorageUtil.GetIntValue(npc, "IS_SPEAKING", 0) == 0
						n = limit
					else
						Debug.Trace("[CHIM-NSFW] Actor "+npc+" speaking, delaying oral");
					endif

					n = n+1
				EndWhile
				initialSceneText="boobjob";
				
			elseif (command=="ExtCmdStartAnalSex")
				int limit = 15
				int n = 0
				while (n < limit)
					Utility.Wait(2)
					if StorageUtil.GetIntValue(npc, "IS_SPEAKING", 0) == 0
						n = limit
					else
						Debug.Trace("[CHIM-NSFW] Actor "+npc+" speaking, delaying anal");
					endif

					n = n+1
				EndWhile
				initialSceneText="analsex";
				
			
			elseif (command=="ExtCmdStartHandjobSex" || command=="ExtCmdStartHandJobSex")
				int limit = 15
				int n = 0
				while (n < limit)
					Utility.Wait(2)
					if StorageUtil.GetIntValue(npc, "IS_SPEAKING", 0) == 0
						n = limit
					else
						Debug.Trace("[CHIM-NSFW] Actor "+npc+" speaking, delaying handjob");
					endif

					n = n+1
				EndWhile
				initialSceneText="handjob";
				
			
			EndIf
			
			
			
			
			;String initialScene=OLibrary.GetRandomSceneWithSceneTag(finalActorsInvolved,initialSceneText)
			String initialScene=OLibrary.GetRandomSceneWithAllActionsCSV(finalActorsInvolved,initialSceneText)

			int builderID = OThreadBuilder.create(finalActorsInvolved)
			OThreadBuilder.SetStartingAnimation(builderID, initialScene)
			int newThreadID = OThreadBuilder.Start(builderID)
			
			StorageUtil.SetIntValue(npc, "ostimThreadId", newThreadID)
			Debug.Trace("[CHIM-NSFW] Launched Scene thrId:"+newThreadID);

			
			;OThread.QuickStart(finalActorsInvolved)
		else
			Debug.Trace("[CHIM-NSFW] Could not verify actors:");
		endif
		
		
			
	endIf	
	
	
	if (command=="ExtCmdStartMassage~")
		;parameter

		If (StringUtil.find(parameter,Game.GetPlayer().GetDisplayName()) !=-1)
			; PLayer involved
			string result = GetPlayerConsent(npc.GetDisplayName(), "wants to give you a massage. Allow?")
			if result == "No, thanks"
				AIAgentFunctions.logMessageForActor("command@ExtCmdStartMassage@"+parameter+"@error. Player refused to start sex","funcret",npcname)
				return;
			endif
		endif 
		
		Actor[] actorsInvolved=PrepareScene(npc,parameter);
		
		AIAgentFunctions.logMessageForActor("command@ExtCmdStartMassage@"+parameter,"funcret",npcname)
		
		mdi=AIAgentFunctions.get_conf_i("_max_distance_inside");
		mdo=AIAgentFunctions.get_conf_i("_max_distance_outside");
		
		Debug.Trace("Enabling intimacy bubble: saving settings: "+mdi+","+mdo);
		
		AIAgentFunctions.setConf("_max_distance_inside",256,256,256);
		AIAgentFunctions.setConf("_max_distance_outside",256,256,256);
		
		;SexLabFramework _slf = SexLabUtil.GetAPI() 
		;_slf.QuickStart(npc, actorsInvolved[0],  actorsInvolved[1], actorsInvolved[2], actorsInvolved[3],actorsInvolved[4],"", "foreplay");
			
	endIf	
	
	if (command=="ExtCmdStartTitfuck~")

		If (StringUtil.find(parameter,Game.GetPlayer().GetDisplayName()) !=-1)
			; PLayer involved
			string result = GetPlayerConsent(npc.GetDisplayName(), "wants to give you a titjob. Allow?")
			if result == "No, thanks"
				AIAgentFunctions.logMessageForActor("command@ExtCmdStartTitfuck@"+parameter+"@error. Player refused to start sex","funcret",npcname)
				return;
			endif
		endif 
		
		Actor[] actorsInvolved=PrepareScene(npc,parameter);
		
		AIAgentFunctions.logMessage("ENJOY THE SEX SCENE!. NO QUEST, NO DUTIES NOW. ONLY PLEASURE","force_current_task")
		AIAgentFunctions.logMessageForActor("command@ExtCmdStartTitfuck@"+parameter,"funcret",npcname)
		
		mdi=AIAgentFunctions.get_conf_i("_max_distance_inside");
		mdo=AIAgentFunctions.get_conf_i("_max_distance_outside");
		
		Debug.Trace("[CHIM-NSFW] Enabling intimacy bubble: saving settings: "+mdi+","+mdo);
		
		AIAgentFunctions.setConf("_max_distance_inside",256,256,256);
		AIAgentFunctions.setConf("_max_distance_outside",256,256,256);
		;sslThreadController function QuickStart(actor a1, actor a2 = none, actor a3 = none, actor a4 = none, actor a5 = none, actor victim = none, string hook = "", string animationTags = "") global
		;SexLabFramework _slf = SexLabUtil.GetAPI() 
		;_slf.QuickStart(npc, actorsInvolved[0],  actorsInvolved[1], actorsInvolved[2], actorsInvolved[3],actorsInvolved[4],"", "boobjob");
	
			
	endIf	
	
	if (command=="ExtCmdStartSelfMasturbation")
		
		; Actor firing the event
		
		Actor[] actorsInvolved=PrepareScene(npc,"");
		AIAgentNSFWSceneEngine.StartSoloScene(npc, "masturbation") ; actually animate the solo scene (2026-06-29)
		AIAgentFunctions.logMessage("ENJOY THE SEX SCENE!. NO QUEST, NO DUTIES NOW. ONLY PLEASURE","force_current_task")
		AIAgentFunctions.logMessageForActor("command@ExtCmdStartSelfMasturbation@"+parameter,"funcret",npcname)
		
		mdi=AIAgentFunctions.get_conf_i("_max_distance_inside");
		mdo=AIAgentFunctions.get_conf_i("_max_distance_outside");
		
		Debug.Trace("[CHIM-NSFW] Enabling intimacy bubble: saving settings: "+mdi+","+mdo);
		
		AIAgentFunctions.setConf("_max_distance_inside",256,256,256);
		AIAgentFunctions.setConf("_max_distance_outside",256,256,256);
		
		OThread.QuickStart(actorsInvolved)
	
		
		Debug.Trace("[CHIM-NSFW] Launched Scene QuickStart mode");	
		
	endIf	
	
	
	if (command=="ExtCmdQuickenPace" || command=="ExtCmdSlowPace")
		if (OActor.IsInOStim(npc))
			int paceThr = OActor.GetSceneId(npc)
			if paceThr >= 0 && OThread.IsRunning(paceThr)
				int curSpeed = OThread.GetSpeed(paceThr)
				int newSpeed = curSpeed + 1
				if command=="ExtCmdSlowPace"
					newSpeed = curSpeed - 1
				endif
				if newSpeed < 0
					newSpeed = 0
				endif
				OThread.SetSpeed(paceThr, newSpeed) ; OStim clamps to the scene's max
				Debug.Trace("[CHIM-NSFW] "+command+": "+npc.GetDisplayName()+" pace "+curSpeed+" -> "+newSpeed+" (thread "+paceThr+")")
			endif
		else
			; SEXLAB (fix 2026-07-02, SexLab audit #2): SexLab has no pace API - report it back so the
			; model learns instead of "changing pace" into the void.
			AIAgentFunctions.logMessageForActor("command@" + command + "@unsupported@pace control is not available in this scene", "funcret", npcname)
			Debug.Trace("[CHIM-NSFW] "+command+": no OStim thread for "+npc.GetDisplayName()+" - pace unsupported (SexLab), funcret sent")
		endif
		return
	endif

	if (command=="ExtCmdSexCommand")
		;
		if (OActor.IsInOStim(npc))

			int thrId=StorageUtil.GetIntValue(npc, "ostimThreadId", 0)
			int thrId2=OActor.GetSceneId(npc)
			
			String sceneId=OThread.GetScene(thrId2)
			
			Debug.Trace("[CHIM-NSFW] "+npc.GetDisplayName()+"@ExtCmdSexCommand@"+parameter+" thrId: "+thrId+" thrId2: "+thrId2);
			Actor[] actorsInvolved = GetOStimThreadParticipantsSafe(thrId2) ; LIVE thread - the SceneEngine does not set ostimThreadId storage, so thrId can be stale/0
			if actorsInvolved == None || CountValidActors(actorsInvolved) < 1
				Debug.Trace("[CHIM-NSFW] ExtCmdSexCommand: No actors for thread " + thrId2)
				return
			endif
			
			int i=0
			while i < actorsInvolved.Length
				Actor participant = actorsInvolved[i]
				
				Debug.Trace("[CHIM-NSFW] Participant: " + participant.GetDisplayName())
				
				if (participant == Game.GetPlayer())
					Debug.Trace("[CHIM-NSFW] Participant is player " + participant.GetDisplayName())
				else
					Debug.Trace("[CHIM-NSFW] Participant is NPC: " + participant.GetDisplayName())
				endif

				i += 1
			endwhile
		
			;OMetadata.FindActionForMate(string Id, int Position, string Type) Global Native
			;String newScene=OLibrary.GetRandomSceneWithMultiActorTagForAnyCSV(actorsInvolved,"")
			
			;String sequence=OSequence.GetRandomSequence(actorsInvolved)
			;OThread.PlaySequence(thrId, sequence, true)
			Debug.Trace("[CHIM-NSFW] GetScenesInRange: "+OCSV.ToCSVList(OLibrary.GetScenesInRange(sceneId,actorsInvolved,5)))
			Debug.Trace("[CHIM-NSFW] GetSceneTags: "+OCSV.ToCSVList(OMetadata.GetSceneTags(sceneId)))
			
			Debug.Trace("[CHIM-NSFW] GetActorTags 0: "+OCSV.ToCSVList(OMetadata.GetActorTags(sceneId,0)))
			Debug.Trace("[CHIM-NSFW] GetActorTags 1: "+OCSV.ToCSVList(OMetadata.GetActorTags(sceneId,1)))
			
			;Debug.Trace("[CHIM-NSFW] FindAllActionsCSV : "+(OMetadata.FindAllActionsCSV(sceneId,"blowjob").Length))
			;Debug.Trace("[CHIM-NSFW] FindAnyActionForActorCSV 1: "+(OMetadata.FindAnyActionForActorCSV(sceneId,1,"blowjob")))
			
			String[] tags=OMetadata.GetSceneTags(SceneID);
			String sceneTags=OCSV.ToCSVList(tags)
			
			
			string sanitizedTag="";
			
			if (StringUtil.Find(parameter,"blowjob")>=0)
				sanitizedTag="blowjob,deepthroat,lickingpenis"
				Utility.Wait(2); Give time to end speech
				AIAgentFunctions.setLocked(1,npcname)
				bool isMuted=npc.GetFactionRank(noFacialExpressionsFaction)>0
				if (isMuted);Actor is talking, lets wait to end speech
					int counter=1;
					while isMuted && counter < 15
						Utility.Wait(2); Give time to end speech
						counter = counter + 1 
						Debug.Trace("[CHIM-NSFW] want to use mouth but is still talking: "+npc.GetDisplayName())
						isMuted=npc.GetFactionRank(noFacialExpressionsFaction)>0
					endWhile
				endif
				
			elseif (StringUtil.Find(parameter,"boobjob")>=0)
				sanitizedTag="boobjob"
			elseif (StringUtil.Find(parameter,"analsex")>=0)
				sanitizedTag="analsex"
			elseif (StringUtil.Find(parameter,"cunnilingus")>=0)
				sanitizedTag="cunnilingus"
			elseif (StringUtil.Find(parameter,"frenchkissing")>=0)
				sanitizedTag="frenchkissing"
				Utility.Wait(2); Give time to end speech
				AIAgentFunctions.setLocked(1,npcname)
				bool isMuted=npc.GetFactionRank(noFacialExpressionsFaction)>0
				if (isMuted);Actor is talking, lets wait to end speech
					int counter=1;
					while isMuted && counter < 15
						Utility.Wait(2); Give time to end speech
						counter = counter + 1 
						Debug.Trace("[CHIM-NSFW] want to use mouth but is still talking: "+npc.GetDisplayName())
						isMuted=npc.GetFactionRank(noFacialExpressionsFaction)>0
					endWhile
				endif
					
			elseif (StringUtil.Find(parameter,"handjob")>=0)
				sanitizedTag="handjob"
			elseif (StringUtil.Find(parameter,"vaginalfingering")>=0)
				sanitizedTag="vaginalfingering"
			elseif (StringUtil.Find(parameter,"vaginalsex")>=0)
				sanitizedTag="vaginalsex"
				
			endif
			

			String actionScene2=OLibrary.GetRandomSceneWithAnyActionCSV(actorsInvolved,sanitizedTag)
			;String actionScene=OLibrary.GetRandomSceneWithAnyActionCSV(actorsInvolved,"blowjob")
			
			string furnitureType=OThread.GetFurnitureType(thrId2)
			if (furnitureType)
				actionScene2=OLibrary.GetRandomFurnitureSceneWithAnyActionCSV(actorsInvolved,furnitureType,sanitizedTag)
				if !actionScene2 
					actionScene2=OLibrary.GetRandomFurnitureScene(actorsInvolved,furnitureType)
				endif
			endif
			
			;String newScene=OLibrary.GetRandomSceneWithMultiActorTagForAnyCSV(actorsInvolved,"reversecowgirl")
			;String newScene2=OLibrary.GetRandomSceneWithMultiActorTagForAnyCSV(actorsInvolved,saniºtizedParameter)
			
			
			;String newScene=OLibrary.GetRandomScene(actorsInvolved)
			OThread.WarpTo(thrId2,actionScene2,true)

			Debug.Trace("[CHIM-NSFW] <"+actionScene2+"> <"+sanitizedTag+">, Actors:"+actorsInvolved.length);
		else
			; SEXLAB (fix 2026-07-02, SexLab audit #1): ExtCmdSexCommand was OStim-only, yet the server offers
			; SexAction mid-scene on BOTH engines - the model "changed position" and nothing happened. SexLab has
			; no in-thread warp, so ShiftSexLabScene restarts the same actors on animations matching the act;
			; on an unknown/unmatched act it leaves the current scene running.
			SexLabFramework slfShift = SexLabUtil.GetAPI()
			if (slfShift)
				int slShiftTid = slfShift.FindActorController(npc)
				if (slShiftTid < 0)
					slShiftTid = slfShift.FindActorController(Game.GetPlayer())
				endif
				if (slShiftTid >= 0)
					bool slShifted = AIAgentNSFWSceneEngine.ShiftSexLabScene(slfShift, slShiftTid, parameter)
					Debug.Trace("[CHIM-NSFW] ExtCmdSexCommand (SexLab): act=" + parameter + " thread=" + slShiftTid + " shifted=" + slShifted)
				else
					Debug.Trace("[CHIM-NSFW] ExtCmdSexCommand: " + npc.GetDisplayName() + " is in neither an OStim nor a SexLab scene")
				endif
			endif
		endif
	endif

	; Stop NPC-to-NPC OStim scene by thread ID
	; Called by PHP when affinity is too low between NPCs
	if (command == "ExtCmdStopNpcScene")
		; parameter contains the thread ID to stop
		int threadToStop = parameter as int
		if threadToStop >= 0 && OThread.IsRunning(threadToStop)
			Debug.Trace("[CHIM-NSFW] Stopping NPC scene thread: " + threadToStop + " due to low affinity")
			OThread.Stop(threadToStop)
			AIAgentFunctions.logMessage("NPC scene ended due to relationship conflict", "ext_nsfw_action")
		else
			Debug.Trace("[CHIM-NSFW] Could not stop thread " + threadToStop + " - not running or invalid")
		endif
	endif

	; ============================================
	; ExtCmdStopScene - Stop player OStim/SexLab scene
	; ============================================
	; ExtCmdRefuseSex is SPEECH/STATE ONLY now - decoupled from the mechanical stop. PHP owns the routing and
	; queues a SEPARATE ExtCmdStopScene only when the NPC is NOT drunk/sap exit-locked. So RefuseSex must NOT
	; stop the scene here; it only logs the refusal. The drunk/sap "can refuse, cannot exit" rule lives in PHP.
	if (command == "ExtCmdRefuseSex")
		Debug.Trace("[CHIM-NSFW] ExtCmdRefuseSex: refusal recorded, no scene stop (PHP routes the mechanical stop)")
		AIAgentFunctions.logMessage("NPC refused sexual advances", "ext_nsfw_action")
	endif

	if (command == "ExtCmdStopScene")
		Debug.Trace("[CHIM-NSFW] " + command + ": Stopping player scene")
		bool stoppedAny = false
		Actor playerActor = Game.GetPlayer()

		; OStim thread IDs are not guaranteed to be dense 0..GetThreadCount()-1 values.
		; Ask OStim which thread the player/NPC is actually in before falling back to a scan.
		int playerThreadId = OActor.GetSceneId(playerActor)
		if playerThreadId >= 0 && OThread.IsRunning(playerThreadId)
			Debug.Trace("[CHIM-NSFW] Stopping player scene thread from OActor.GetSceneId(player): " + playerThreadId)
			OThread.Stop(playerThreadId)
			stoppedAny = true
		endif

		if npc && OActor.IsInOStim(npc)
			int npcThreadId = OActor.GetSceneId(npc)
			if npcThreadId >= 0 && npcThreadId != playerThreadId && OThread.IsRunning(npcThreadId)
				Actor[] npcThreadActors = GetOStimThreadParticipantsSafe(npcThreadId)
				int na = 0
				bool npcThreadHasPlayer = false
				while npcThreadActors != None && na < npcThreadActors.Length
					if npcThreadActors[na] != None && npcThreadActors[na] == playerActor
						npcThreadHasPlayer = true
					endif
					na += 1
				endwhile
				if npcThreadHasPlayer
					Debug.Trace("[CHIM-NSFW] Stopping player scene thread from OActor.GetSceneId(npc): " + npcThreadId)
					OThread.Stop(npcThreadId)
					stoppedAny = true
				endif
			endif
		endif

		; Fallback for older OStim behavior where GetSceneId is unavailable/stale.
		int threadCount = OThread.GetThreadCount()
		int t = 0
		while t < threadCount
			if OThread.IsRunning(t)
				Actor[] actors = GetOStimThreadParticipantsSafe(t)
				if actors == None || CountValidActors(actors) < 1
					Debug.Trace("[CHIM-NSFW] ExtCmdStopScene: No actors for running thread " + t)
				else
				int a = 0
				bool playerInThread = false
				while a < actors.Length
					if actors[a] != None && actors[a] == playerActor
						playerInThread = true
					endif
					a += 1
				endwhile
				if playerInThread
					Debug.Trace("[CHIM-NSFW] Stopping player scene thread: " + t)
					OThread.Stop(t)
					stoppedAny = true
				endif
				endif
			endif
			t += 1
		endwhile
		if !stoppedAny
			Debug.Trace("[CHIM-NSFW] " + command + ": no running player OStim thread found to stop")
		endif
		; Also end any SexLab player scene so refuse/stop exits both engines
		SexLabFramework SexLabStop = SexLabUtil.GetAPI()
		if (SexLabStop)
			int slTid = SexLabStop.FindActorController(Game.GetPlayer())
			if (slTid >= 0)
				sslThreadController slController = SexLabStop.GetController(slTid)
				if (slController)
					Debug.Trace("[CHIM-NSFW] Stopping player SexLab scene thread: " + slTid)
					slController.EndAnimation()
				endif
			endif
		endif
		AIAgentFunctions.logMessage("Scene ended by NPC", "ext_nsfw_action")
	endif

EndEvent

Actor[] Function PrepareScene(Actor npc,String parameter)

	bool addplayer=false;
	
	
	String[] actorNames = StringUtil.Split(parameter,",")

	Int i = 0
	Int totalArraySize=0;
	While i < actorNames.Length 
		
		String currentNpcName = actorNames[i]
		; Get actor reference by name
		Actor foundNpc = AIAgentFunctions.getAgentByName(currentNpcName)
		
		If (StringUtil.find(currentNpcName,Game.GetPlayer().GetDisplayName()) !=-1)
			Debug.Trace("[CHIM-NSFW] [1] Adding (delayed) player <" + currentNpcName+">")	
			;actorsInvolved=PapyrusUtil.PushActor(actorsInvolved,Game.GetPlayer())
			totalArraySize=totalArraySize+1;
			addplayer=true
		endif
		if (foundNpc)
			Debug.Trace("[CHIM-NSFW] [1] Adding <" + currentNpcName+">")	
			totalArraySize=totalArraySize+1;
			;actorsInvolved=PapyrusUtil.PushActor(actorsInvolved,foundNpc)
			AIAgentFunctions.setAnimationBusy(1,currentNpcName)
		EndIf
		i = i+1
	EndWhile

	; If the parameter named no partner (e.g. it was an act like "vaginalsex", or empty), the scene is meant
	; with the PLAYER she's talking to - add the player as the partner so OStim builds a couple scene instead
	; of a solo / masturbation one.
	bool defaultPlayerPartner = false
	if (totalArraySize == 0 && !addplayer)
		defaultPlayerPartner = true
		totalArraySize = totalArraySize + 1
	endif

	Actor[] actorsInvolved= PapyrusUtil.ActorArray(totalArraySize+1, None)
	int j=1
	actorsInvolved[0] = npc; Add caller NPC
	Debug.Trace("[CHIM-NSFW] [2] Adding action caller <" + npc.GetDisplayName()+">")	
	AIAgentFunctions.setAnimationBusy(1,npc.GetDisplayName())

	i = 0
	
	While i < actorNames.Length 
		
		String currentNpcName = actorNames[i]
		
		Actor foundNpc = AIAgentFunctions.getAgentByName(currentNpcName)
		
		If (StringUtil.find(currentNpcName,Game.GetPlayer().GetDisplayName()) !=-1)
			Debug.Trace("[CHIM-NSFW] [2] Adding (delayed) player <" + currentNpcName+">")	
			actorsInvolved[j]=Game.GetPlayer();
			j = j+1
		endif
		if (foundNpc)
			Debug.Trace("[CHIM-NSFW] [2] Adding <" + currentNpcName+">")	
			actorsInvolved[j]=foundNpc
			j = j+1
		EndIf
		
		i = i+1

	EndWhile

	if (defaultPlayerPartner)
		actorsInvolved[j] = Game.GetPlayer()
		j = j+1
		Debug.Trace("[CHIM-NSFW] [2] No partner named in parameter - defaulting scene partner to the player")
	endif

	return actorsInvolved;
	
endFunction

; OSTIM related
Event OstimEvent(Int ThreadId,String type, Form eActor,Form eTarget,Form ePerformer)

	Debug.Trace("[CHIM-NSFW] OSTIM event: "+ThreadId+","+type+","+eActor.GetName())
	Debug.Trace("[CHIM-NSFW] OSTIM event: "+OThread.GetScene(ThreadId))

EndEvent

Event OStimStart(string eventName, string strArg, float numArg, Form sender)

	Debug.Trace("[CHIM-NSFW] OStimStart: "+eventName+","+StrArg+","+numArg+","+sender.GetName())

	int threadID = numArg as int

	; Get actors in this thread
	Actor[] Actors = GetOStimThreadParticipantsSafe(threadID)
	string SceneID = OThread.GetScene(threadID)

	if Actors == None || CountValidActors(Actors) < 1
		Debug.Trace("[CHIM-NSFW] OStimStart: No valid actors for ThreadID=" + threadID + ", SceneID=" + SceneID)
		return
	endif

	Debug.Trace("[CHIM-NSFW] OStimStart: ThreadID=" + threadID + ", SceneID=" + SceneID + ", Actors count=" + CountValidActors(Actors))

	; Check if player is in scene and find first NPC
	bool playerInScene = false
	Actor firstNpcInScene = None
	int i = 0
	while i < Actors.Length
		if Actors[i] != None
			if Actors[i] == Game.GetPlayer()
				playerInScene = true
				Debug.Trace("[CHIM-NSFW] OStimStart: Player is in scene")
			else
				if firstNpcInScene == None
					firstNpcInScene = Actors[i]
					Debug.Trace("[CHIM-NSFW] OStimStart: First NPC is " + Actors[i].GetDisplayName())
				endif
			endif
		endif
		i += 1
	endwhile

	; ============================================
	; PLAYER SCENE START - Trigger tier prompt as chat event
	; This fires ONCE when scene starts - uses requestMessageForActor to get LLM response
	; ============================================
	if playerInScene && firstNpcInScene != None
		string npcName = firstNpcInScene.GetDisplayName()
		Debug.Trace("[CHIM-NSFW] SCENE START: Requesting tier prompt response for " + npcName)

		; Build actor list for PHP
		string actorList = ""
		int j = 0
		while j < Actors.Length
			if Actors[j] != None
				actorList = actorList + "/" + Actors[j].GetDisplayName()
			endif
			j += 1
		endwhile

		; Get scene tags for context
		String[] tags = OMetadata.GetSceneTags(SceneID)
		String sceneTags = OCSV.ToCSVList(tags)

		; Format: SceneID/tags/SceneID/actors (matches ext_nsfw_sexcene format)
		string sceneData = SceneID + "/" + sceneTags + "/" + SceneID + actorList

		; USE requestMessageForActor TO TRIGGER LLM RESPONSE!!!
		AIAgentFunctions.requestMessageForActor(sceneData, "ext_nsfw_sexcene", npcName)
		Debug.Trace("[CHIM-NSFW] SCENE START: Sent ext_nsfw_sexcene via requestMessageForActor for " + npcName)
	else
		Debug.Trace("[CHIM-NSFW] OStimStart: No player in scene or no NPC found, skipping tier prompt")
	endif

EndEvent

int Function FindActorIndexInList(Actor[] actors, Actor target)
	if actors == None || target == None
		return -1
	endif

	int i = 0
	while i < actors.Length
		if actors[i] == target
			return i
		endif
		i += 1
	endwhile

	return -1
EndFunction

int Function CountValidActors(Actor[] participants)
	if participants == None
		return 0
	endif

	int count = 0
	int i = 0
	while i < participants.Length
		if participants[i] != None
			count += 1
		endif
		i += 1
	endwhile

	return count
EndFunction

Actor[] Function GetOStimThreadParticipantsSafe(int threadID)
	Actor[] participants = new Actor[6]
	int count = 0
	int index = 0
	while index < 6
		Actor participant = OThread.GetActor(threadID, index)
		if participant != None
			participants[count] = participant
			count += 1
		endif
		index += 1
	endwhile

	if count < 1
		return None
	endif

	return participants
EndFunction

; ============================================
; NPC SCENE ROSTER + TEARDOWN STASH (up to 5 NPC actors)
; ============================================
; Live actor resolution is unreliable at the moments we need it (thread-start JSON is empty,
; thread-end JSON often fails to parse / the thread is already torn down). So we:
;   1. carry the FULL roster on the wire as actors=<json> (server registers everyone), and
;   2. stash the roster per-thread so the end event can always fire chatnf_sl_end -> clean teardown.

int Function GetNpcSceneStashMap()
	if npcSceneActorStash == 0
		npcSceneActorStash = JMap.Object()
		JValue.Retain(npcSceneActorStash)
	endif
	return npcSceneActorStash
EndFunction

string Function BuildNpcActorsJson(Actor[] participants)
	string j = "["
	int i = 0
	int added = 0
	while i < participants.Length && added < 5
		if participants[i] != None && participants[i] != Game.GetPlayer()
			if added > 0
				j += ","
			endif
			j += "\"" + participants[i].GetDisplayName() + "\""
			added += 1
		endif
		i += 1
	endwhile
	j += "]"
	return j
EndFunction

Function StashNpcSceneActors(int threadID, Actor[] participants)
	if participants == None || threadID < 0
		return
	endif
	int names = JArray.Object()
	int i = 0
	int added = 0
	while i < participants.Length && added < 5
		if participants[i] != None && participants[i] != Game.GetPlayer()
			JArray.AddStr(names, participants[i].GetDisplayName())
			added += 1
		endif
		i += 1
	endwhile
	if added > 0
		JMap.SetObj(GetNpcSceneStashMap(), "thr_" + threadID, names)
	endif
EndFunction

string Function BuildScoringFromStash(int threadID)
	if threadID < 0
		return ""
	endif
	int names = JMap.GetObj(GetNpcSceneStashMap(), "thr_" + threadID)
	if names == 0 || JArray.Count(names) < 1
		return ""
	endif
	string scoring = ""
	int i = 0
	while i < JArray.Count(names)
		scoring = scoring + "/" + JArray.GetStr(names, i) + "@100"
		i += 1
	endwhile
	return scoring
EndFunction

Function ClearStashedNpcSceneActors(int threadID)
	JMap.RemoveKey(GetNpcSceneStashMap(), "thr_" + threadID)
EndFunction

string Function ResolveOStimOrgasmPartnerName(Actor orgasmer, int threadID, string sceneID)
	lastResolvedOStimOrgasmActionType = ""

	if orgasmer == None
		return ""
	endif

	Actor[] actors = None
	if threadID >= 0
		actors = GetOStimThreadParticipantsSafe(threadID)
	endif

	int orgasmerIndex = FindActorIndexInList(actors, orgasmer)
	if sceneID != "" && orgasmerIndex >= 0 && actors != None
		; PENETRATIVE-PRIORITY (no FMR dependency): the orgasm partner is whoever the orgasmer is actually penetrating
		; or being penetrated by - NOT whatever secondary act (handjob/footjob) happens to be listed first. In a 3-way
		; this is what credited the scene-starter instead of the real partner. Search the
		; penetrative acts FIRST and return that target; only fall through to the broader list below if none. (2026-06-29)
		int penActionIndex = OMetadata.FindAnyActionForActorCSV(sceneID, orgasmerIndex, OSTIM_PENETRATIVE_ACTION_TYPES)
		if penActionIndex != -1
			lastResolvedOStimOrgasmActionType = OMetadata.GetActionType(sceneID, penActionIndex)
			int penTargetIndex = OMetadata.GetActionTarget(sceneID, penActionIndex)
			if penTargetIndex >= 0 && penTargetIndex < actors.Length
				Actor penTarget = actors[penTargetIndex]
				if penTarget != None && penTarget != orgasmer
					Debug.Trace("[CHIM-NSFW] OStim orgasm PENETRATIVE actor->target: " + orgasmer.GetDisplayName() + " -> " + penTarget.GetDisplayName() + " action=" + lastResolvedOStimOrgasmActionType)
					return penTarget.GetDisplayName()
				endif
			endif
		endif
		penActionIndex = OMetadata.FindAnyActionForTargetCSV(sceneID, orgasmerIndex, OSTIM_PENETRATIVE_ACTION_TYPES)
		if penActionIndex != -1
			lastResolvedOStimOrgasmActionType = OMetadata.GetActionType(sceneID, penActionIndex)
			int penActorIndex = OMetadata.GetActionActor(sceneID, penActionIndex)
			if penActorIndex >= 0 && penActorIndex < actors.Length
				Actor penActor = actors[penActorIndex]
				if penActor != None && penActor != orgasmer
					Debug.Trace("[CHIM-NSFW] OStim orgasm PENETRATIVE target<-actor: " + orgasmer.GetDisplayName() + " <- " + penActor.GetDisplayName() + " action=" + lastResolvedOStimOrgasmActionType)
					return penActor.GetDisplayName()
				endif
			endif
		endif
		int actionIndex = OMetadata.FindAnyActionForActorCSV(sceneID, orgasmerIndex, OSTIM_ORGASM_ACTION_TYPES)
		if actionIndex != -1
			lastResolvedOStimOrgasmActionType = OMetadata.GetActionType(sceneID, actionIndex)
			int targetIndex = OMetadata.GetActionTarget(sceneID, actionIndex)
			if targetIndex >= 0 && targetIndex < actors.Length
				Actor targetActor = actors[targetIndex]
				if targetActor != None && targetActor != orgasmer
					Debug.Trace("[CHIM-NSFW] OStim orgasm metadata actor->target: " + orgasmer.GetDisplayName() + " -> " + targetActor.GetDisplayName() + " action=" + lastResolvedOStimOrgasmActionType)
					return targetActor.GetDisplayName()
				endif
			endif
		endif

		actionIndex = OMetadata.FindAnyActionForTargetCSV(sceneID, orgasmerIndex, OSTIM_ORGASM_ACTION_TYPES)
		if actionIndex != -1
			lastResolvedOStimOrgasmActionType = OMetadata.GetActionType(sceneID, actionIndex)
			int actorIndex = OMetadata.GetActionActor(sceneID, actionIndex)
			if actorIndex >= 0 && actorIndex < actors.Length
				Actor actorPartner = actors[actorIndex]
				if actorPartner != None && actorPartner != orgasmer
					Debug.Trace("[CHIM-NSFW] OStim orgasm metadata target<-actor: " + orgasmer.GetDisplayName() + " <- " + actorPartner.GetDisplayName() + " action=" + lastResolvedOStimOrgasmActionType)
					return actorPartner.GetDisplayName()
				endif
			endif
		endif
	endif

	OSexIntegrationMain ostim = Game.GetFormFromFile(0x000801, "Ostim.esp") as OSexIntegrationMain
	if ostim
		Actor sexPartner = ostim.GetSexPartner(orgasmer)
		if sexPartner != None && sexPartner != orgasmer
			lastResolvedOStimOrgasmActionType = "ostim_partner_fallback"
			Debug.Trace("[CHIM-NSFW] OStim orgasm partner fallback: " + orgasmer.GetDisplayName() + " -> " + sexPartner.GetDisplayName())
			return sexPartner.GetDisplayName()
		endif
	endif

	if actors != None
		int i = 0
		while i < actors.Length
			if actors[i] != None && actors[i] != orgasmer
				lastResolvedOStimOrgasmActionType = "actor_list_fallback"
				Debug.Trace("[CHIM-NSFW] OStim orgasm actor-list fallback: " + orgasmer.GetDisplayName() + " -> " + actors[i].GetDisplayName())
				return actors[i].GetDisplayName()
			endif
			i += 1
		endwhile
	endif

	return ""
EndFunction

; General per-actor scene-partner resolver for the SPEAKING NPC. Engine-authoritative so 3+ scenes attribute
; correctly: the server used to GUESS first-other, so in a threesome two NPCs would "talk like they're fucking
; each other" while both were actually paired with the third. OStim: metadata action->target (reuses
; ResolveOStimOrgasmPartnerName, whose action-type CSV covers all partnered acts, not just orgasm). SexLab: the
; other position (exact for 2 actors; best-effort first-other for 3+, since SexLab has no per-action targeting).
string Function ResolveSceneSpeakerPartner(Actor speaker, int threadID, string routeName)
	if speaker == None
		return ""
	endif

	if routeName == "SexLabNpcSceneStart" || routeName == "SexLabNpcStage"
		SexLabFramework SexLab = SexLabUtil.GetAPI()
		if SexLab
			int tid = threadID
			if tid < 0
				tid = SexLab.FindActorController(speaker)
			endif
			if tid >= 0
				sslThreadController controller = SexLab.GetController(tid)
				if controller
					Actor[] pos = controller.Positions
					if pos
						int i = 0
						while i < pos.Length
							if pos[i] != None && pos[i] != speaker && pos[i] != Game.GetPlayer()
								return pos[i].GetDisplayName()
							endif
							i += 1
						endwhile
					endif
				endif
			endif
		endif
		return ""
	endif

	; OStim route - resolve the speaker's CURRENT action partner from scene metadata.
	string sceneID = ""
	if threadID >= 0
		sceneID = OThread.GetScene(threadID)
	endif
	return ResolveOStimOrgasmPartnerName(speaker, threadID, sceneID)
EndFunction

Event OstimOrgasm(string eventName, string strArg, float numArg, Form sender)

	int threadID = numArg as int
	Actor orgasmer = sender as Actor

	if orgasmer == None
		Debug.Trace("[CHIM-NSFW] OstimOrgasm: No valid orgasmer actor")
		return
	endif

	Debug.Trace("[CHIM-NSFW] OstimOrgasm: "+eventName+","+StrArg+","+numArg+","+orgasmer.GetDisplayName())
	if (sender.GetName()=="OSexIntegrationMainQuest")
		OSexIntegrationMain osex = sender as OSexIntegrationMain
	endif

	; Get scene context for PHP to build contextual orgasm message
	string sceneID = ""
	int orgasmerIndex = 0
	string partnerName = ""

	bool sceneHasPlayer = false
	if (threadID >= 0)
		sceneID = OThread.GetScene(threadID)
		Actor[] actors = GetOStimThreadParticipantsSafe(threadID)

		; Find orgasmer's position and a partner; also detect whether the PLAYER is in this thread so an NPC-to-NPC
		; climax is not misrouted through the player consent/refusal handler (the is_npc_scene misclassification bug).
		int i = 0
		if actors != None
			while (i < actors.Length)
				if (actors[i] != None && actors[i] == orgasmer)
					orgasmerIndex = i
				endif
				if (actors[i] != None && actors[i] == playerRef)
					sceneHasPlayer = true
				endif
				i += 1
			endWhile
		endif
	endif

	partnerName = ResolveOStimOrgasmPartnerName(orgasmer, threadID, sceneID)

	; Build data string: OrgasmerName/SceneID/OrgasmerIndex/PartnerName/ActionType
	string orgasmData = orgasmer.GetDisplayName()
	if (sceneID != "")
		orgasmData = orgasmData + "/" + sceneID + "/" + orgasmerIndex + "/" + partnerName
		if lastResolvedOStimOrgasmActionType != ""
			orgasmData = orgasmData + "/" + lastResolvedOStimOrgasmActionType
		endif
	endif

	Debug.Trace("[CHIM-NSFW] OstimOrgasm context: " + orgasmData)

	; Check if orgasmer is player
	bool isPlayerOrgasm = (orgasmer == playerRef)

	if (!isPlayerOrgasm)
		; NPC orgasmed. Player IN the thread -> ext_nsfw_orgasm (player-scene handler). Player NOT in the thread
		; (NPC-to-NPC) -> ext_nsfw_npc_orgasm, so the NPC climax is NOT run through the player consent/refusal
		; suppression (which was leaving is_npc_scene=false and latching scene_phase='rejected'). Mirrors the SexLab
		; handler + OStimSubthreadOrgasm payload (actor^partner^scene^action).
		if (sceneHasPlayer)
			Debug.Trace("[CHIM-NSFW] NPC orgasm (player scene) - sending to: " + orgasmer.GetDisplayName())
			AIAgentFunctions.requestMessageForActor(orgasmData, "ext_nsfw_orgasm", orgasmer.GetDisplayName())
		else
			Debug.Trace("[CHIM-NSFW] NPC-to-NPC orgasm - sending ext_nsfw_npc_orgasm to: " + orgasmer.GetDisplayName())
			AIAgentFunctions.requestMessageForActor(orgasmer.GetDisplayName() + "^" + partnerName + "^" + sceneID + "^" + lastResolvedOStimOrgasmActionType, "ext_nsfw_npc_orgasm", orgasmer.GetDisplayName())
		endif
	else
		; Player orgasmed - partnerName is the metadata target/fallback; mark PLAYER_ORGASM and route to them
		if (partnerName != "")
			string playerOrgasmData = "PLAYER_ORGASM/" + sceneID + "/" + orgasmerIndex + "/" + partnerName
			if lastResolvedOStimOrgasmActionType != ""
				playerOrgasmData = playerOrgasmData + "/" + lastResolvedOStimOrgasmActionType
			endif
			Debug.Trace("[CHIM-NSFW] Player orgasm - routing to: " + partnerName + " action=" + lastResolvedOStimOrgasmActionType)
			AIAgentFunctions.requestMessageForActor(playerOrgasmData, "ext_nsfw_orgasm", partnerName)
		endif
	endif
EndEvent

Event OStimSceneChanged(string EventName, string SceneID, float NumArg, Form Sender)
	Debug.Trace("[CHIM-NSFW] OStimSceneChanged: "+EventName+","+SceneID+","+numArg+","+sender.GetName())
	
	string sexPos=SceneID
	
	String[] tags=OMetadata.GetSceneTags(SceneID);
	String sceneTags=OCSV.ToCSVList(tags)
	
	
	
	;String d1=OCSV.ToCSVList(OMetadata.GetActorTags(SceneID,0))
	;String d2=OCSV.ToCSVList(OMetadata.GetActorTags(SceneID,1))
	
	Debug.Trace("[CHIM-NSFW] OStimSceneChanged: Scene Tags: "+sceneTags)

	Int threadId=numArg as Int
	string furnitureType=OThread.GetFurnitureType(threadId)

	Debug.Trace("[CHIM-NSFW] Furniture used: "+furnitureType+", ThreadID: "+threadId)

	Actor participantTalk = None;
	bool playerInScene = false

	Actor[] participants = GetOStimThreadParticipantsSafe(threadId)
	String actorList = "";
	if (participants != none)
		int i = 0
		while i < participants.Length
			Actor participant = participants[i]
			if participant != None
				; Do something with actor
				String actorTags=OCSV.ToCSVList(OMetadata.GetActorTags(SceneID,i))
				; Send actor name WITH their role tags so PHP knows who is doing what
				actorList=actorList+"/"+participant.GetDisplayName()+"^"+actorTags
				Debug.Trace("[CHIM-NSFW] Participant: " + participant.GetDisplayName()+" tags:"+actorTags)
				
				if (participant == Game.GetPlayer())
					playerInScene = true
					Debug.Trace("[CHIM-NSFW] Participant is player " + participant.GetDisplayName())
				else
					bool isMouthOpen=OActor.HasExpressionOverride(participant)
					if (isMouthOpen)
						Debug.Trace("[CHIM-NSFW] Participant is 'mouth busy' :" + participant.GetDisplayName())
						AIAgentFunctions.setLocked(1,participant.GetDisplayName())
					else
						Debug.Trace("[CHIM-NSFW] Participant is NOT 'mouth busy' " + participant.GetDisplayName())
						AIAgentFunctions.setLocked(0,participant.GetDisplayName())
						if (OMetadata.HasAnySceneTagCSV(SceneID,"sex,reversecowgirl,cowgirl,missionary,cunnilingus,doggystyle,prone")) ; Define this policy
							participantTalk=participant
						endif
					endif
					AIAgentFunctions.setAnimationBusy(1,participant.GetDisplayName())
				endif
			endif

			i += 1
		endwhile
	endif
	
	; Always send scene data to Narrator for state tracking (updates current_scene_desc for all actors, no LLM call)
	AIAgentFunctions.logMessage(sexPos+"/"+sceneTags+"/"+SceneID+actorList, "info_sexscene")

	; CONSENT BARK (fast accept/refuse decision). participantTalk is only set when the scene is at an explicit
	; sex position (tier 3), so this is the moment the player scene becomes explicit. Fire a fast ext_nsfw_consent_bark
	; ONCE per thread so the server resolves the NPC's accept/refuse decision INSTANTLY (it is a fast command and
	; bypasses the MAIN semaphore) instead of waiting on the scene queue. Player scenes only (playerInScene). The
	; server treats it exactly like the scene's sexcene turn, so the model decides with full context and no player input.
	if (participantTalk && playerInScene && threadId != consentBarkThreadId)
		consentBarkThreadId = threadId
		AIAgentFunctions.requestMessageForActor(sexPos+"/"+sceneTags+"/"+SceneID+actorList, "ext_nsfw_consent_bark", participantTalk.GetDisplayName())
		Debug.Trace("[CHIM-NSFW] CONSENT BARK fired for thread " + threadId + " -> " + participantTalk.GetDisplayName())
	endif

	; Also route scene event to NPC for dialogue response (only when mouth is free)
	if (participantTalk)
		AIAgentFunctions.requestMessageForActor(sexPos+"/"+sceneTags+"/"+SceneID+actorList, "ext_nsfw_sexcene", participantTalk.GetDisplayName())
	endif

	; Send chatnf_sl to ALL participants — each has their own 30s cooldown
	; Removed mouth-lock restriction so NPCs comment during active sex positions
	if participants != None
		int k = 0
		while (k < participants.Length)
			Actor talkActor = participants[k]
			if (talkActor != None && talkActor != Game.GetPlayer())
				float daysPassed = Utility.GetCurrentGameTime()
				float lastTalkedTime = StorageUtil.GetFloatValue(talkActor, "chim_ostim_talk_cooldown", 0)
				if ((daysPassed - lastTalkedTime) > 0.00694)	;30 irl seconds
					float excitement = OActor.GetExcitement(talkActor)
					Debug.Trace("[CHIM-NSFW] " + talkActor.GetDisplayName() + " auto-talk, excitement:" + excitement)
					if (excitement <= 80)
						; Legacy chatnf_sl auto-talk disabled. ext_nsfw_sexcene owns model prompting.
						; AIAgentFunctions.requestMessageForActor("", "chatnf_sl", talkActor.GetDisplayName())
						StorageUtil.SetFloatValue(talkActor, "chim_ostim_talk_cooldown", daysPassed)
					else
						; Legacy chatnf_sl auto-talk disabled. ext_nsfw_sexcene owns model prompting.
						; AIAgentFunctions.requestMessageForActor("", "chatnf_sl_moan", talkActor.GetDisplayName())
					endif
				else
					Debug.Trace("[CHIM-NSFW] Auto talk in cooldown for " + talkActor.GetDisplayName())
					; Legacy chatnf_sl auto-talk disabled. ext_nsfw_sexcene owns model prompting.
					; AIAgentFunctions.requestMessageForActor("", "chatnf_sl_moan", talkActor.GetDisplayName())
				endif
			endif
			k += 1
		endWhile
	else
		Debug.Trace("[CHIM-NSFW] OStimSceneChanged: No participants for auto-talk")
	endif
	
EndEvent

Event OStimEnd(string EventName, string Json, float NumArg, Form Sender)
	; the following code only works with API version 7.3.1 or higher
	Debug.Trace("[CHIM-NSFW] OStimEnd: "+EventName+","+Json+","+numArg+","+sender.GetName())
	int threadID = NumArg as int
	Actor[] Actors = None
	string SceneID = ""
	if Json != ""
		Actors = OJSON.GetActors(Json)
		SceneID = OJSON.GetScene(Json)
	endif
	if Actors == None || CountValidActors(Actors) < 1
		Actors = GetOStimThreadParticipantsSafe(threadID)
	endif
	if SceneID == ""
		SceneID = OThread.GetScene(threadID)
	endif
	if Actors == None || CountValidActors(Actors) < 1
		Debug.Trace("[CHIM-NSFW] OStimEnd: No valid actors for ThreadID=" + threadID + ", SceneID=" + SceneID)
		return
	endif

	bool playerInScene=false
	int i=0;
	string scoring=""
	while i < Actors.Length
			Actor participant = Actors[i]
			if participant == None
				i += 1
			else
			
			Debug.Trace("[CHIM-NSFW] OStimEnd. Participant: " + participant.GetDisplayName())
			
			if (participant == Game.GetPlayer())
				Debug.Trace("[CHIM-NSFW] OStimEnd. Participant is player " + participant.GetDisplayName())
				playerInScene = true
			else
				AIAgentFunctions.setLocked(0,participant.GetDisplayName())
				AIAgentFunctions.setAnimationBusy(0,participant.GetDisplayName())
			endif

			scoring = scoring + "/" + participant.GetDisplayName() + "@100"

			i += 1
			endif
	endwhile
	
	; OStimThreadEnd bails for the player thread (thread 0 - OJSON.GetActors fails / thread already torn down),
	; so the player scene would NEVER tear down: scene_phase/sex_started/current_scene_desc linger and the NPC
	; keeps narrating sex while just standing. Send the end HERE, where we have valid resolved actors + scoring.
	if (playerInScene)
		AIAgentFunctions.requestMessage(scoring,"chatnf_sl_end")
		Debug.Trace("[CHIM-NSFW] OStimEnd: sent chatnf_sl_end for player scene teardown -> " + scoring)
	endif

	; Change this to restore from CHIM MCM directly
	if (playerInScene)
		Debug.Trace("[CHIM-NSFW] Restoring settings because intimacy bubble");
		
		AIAgentFunctions.setConf("_max_distance_inside",mdi,mdi,mdi);
		AIAgentFunctions.setConf("_max_distance_outside",mdo,mdo,mdo);
	endif;
	
	
	AIAgentFunctions.logMessage("","force_current_task")
		
EndEvent

Actor Function PickNpcSceneSpeaker(Actor[] participants)
	if participants == None || participants.Length < 1
		return None
	endif

	int usable = 0
	int i = 0
	while i < participants.Length
		if participants[i] != None && participants[i] != Game.GetPlayer()
			usable += 1
		endif
		i += 1
	endwhile
	if usable < 1
		return None
	endif

	int target = npcSceneTalkCounter
	while target >= usable
		target -= usable
	endwhile
	npcSceneTalkCounter += 1

	i = 0
	int seen = 0
	while i < participants.Length
		if participants[i] != None && participants[i] != Game.GetPlayer()
			if seen == target
				return participants[i]
			endif
			seen += 1
		endif
		i += 1
	endwhile

	return None
EndFunction

Function RequestNpcSceneTurn(Actor[] participants, string npcSceneData, string routeName, int threadID = -1)
	; Carry the FULL roster (up to 5 NPCs) so the server registers EVERY participant, not just the first two.
	; The server (processNpcScene) parses the trailing actors=<json> field and falls back to npc1/npc2 only if absent.
	string fullData = npcSceneData + "^actors=" + BuildNpcActorsJson(participants)
	; Stash the roster per-thread so the scene-end handler can always tear down cleanly.
	StashNpcSceneActors(threadID, participants)
	Actor speaker = PickNpcSceneSpeaker(participants)
	if speaker != None
		; Engine-authoritative partner for THIS speaker so threesomes attribute correctly (server consumes partner=).
		string speakerPartner = ResolveSceneSpeakerPartner(speaker, threadID, routeName)
		if speakerPartner != ""
			fullData = fullData + "^partner=" + speakerPartner
		endif
		AIAgentFunctions.requestMessageForActor(fullData, "ext_nsfw_npc_scene", speaker.GetDisplayName())
		Debug.Trace("[CHIM-NSFW] " + routeName + ": Requested ext_nsfw_npc_scene response for " + speaker.GetDisplayName() + " (partner=" + speakerPartner + ")")
	else
		AIAgentFunctions.logMessage(fullData, "ext_nsfw_npc_scene")
		Debug.Trace("[CHIM-NSFW] " + routeName + ": Logged ext_nsfw_npc_scene fallback; no NPC speaker")
	endif
EndFunction

Event OStimThreadStart(string EventName, string Json, float ThreadID, Form Sender)
	; NOTE: Player scene start is handled by OStimStart event instead
	; This event handles NPC-to-NPC scenes only

	int threadIDInt = ThreadID as int
	Actor[] Actors = None
	string SceneID = ""
	if Json != ""
		Actors = OJSON.GetActors(Json)
		SceneID = OJSON.GetScene(Json)
	endif
	if Actors == None || CountValidActors(Actors) < 1
		Actors = GetOStimThreadParticipantsSafe(threadIDInt)
	endif
	if SceneID == ""
		SceneID = OThread.GetScene(threadIDInt)
	endif
	Debug.Trace("[CHIM-NSFW] OStimThreadStart: "+EventName+","+Json+","+ThreadID+","+sender.GetName())
	if Actors == None || CountValidActors(Actors) < 1
		Debug.Trace("[CHIM-NSFW] OStimThreadStart: No actors from JSON or OThread fallback")
		return
	endif

	; Check if player is in scene
	bool playerInScene = false
	int i = 0
	while i < Actors.Length
		if Actors[i] != None && Actors[i] == Game.GetPlayer()
			playerInScene = true
		endif
		i += 1
	endwhile

	; NPC-to-NPC scene detected - send to PHP for affinity check and potential stop
	if !playerInScene && CountValidActors(Actors) >= 2
		Actor npc1 = Actors[0]
		Actor npc2 = Actors[1]
		string npc1Name = npc1.GetDisplayName()
		string npc2Name = npc2.GetDisplayName()

		Debug.Trace("[CHIM-NSFW] NPC-to-NPC scene detected: " + npc1Name + " + " + npc2Name)

		; Get Skyrim's relationship rank between NPCs
		; -4 = Archnemesis, -3 = Enemy, -2 = Foe, -1 = Rival
		; 0 = Acquaintance, 1 = Friend, 2 = Confidant, 3 = Ally, 4 = Lover
		int npc1ToNpc2Rank = npc1.GetRelationshipRank(npc2)
		int npc2ToNpc1Rank = npc2.GetRelationshipRank(npc1)

		Debug.Trace("[CHIM-NSFW] Relationship ranks: " + npc1Name + "->" + npc2Name + ": " + npc1ToNpc2Rank + ", " + npc2Name + "->" + npc1Name + ": " + npc2ToNpc1Rank)

		; Send to PHP with relationship ranks - PHP decides whether to stop
		; Format: npc1^npc2^threadID^sceneID^npc1ToNpc2Rank^npc2ToNpc1Rank (using ^ to avoid CHIM pipe conflict)
		string rawData = npc1Name + "^" + npc2Name + "^" + threadIDInt + "^" + SceneID + "^" + npc1ToNpc2Rank + "^" + npc2ToNpc1Rank
		RequestNpcSceneTurn(Actors, rawData, "OStimThreadStart", threadIDInt)
	endif
EndEvent

Event OStimThreadSceneChanged(string EventName, string SceneID, float ThreadID, Form Sender)
	int npcSceneThreadInt = ThreadID as int
	Actor[] participants = GetOStimThreadParticipantsSafe(npcSceneThreadInt)
	string resolvedSceneID = SceneID
	if resolvedSceneID == ""
		resolvedSceneID = OThread.GetScene(npcSceneThreadInt)
	endif

	Debug.Trace("[CHIM-NSFW] OStimThreadSceneChanged: " + EventName + ", SceneID: " + resolvedSceneID + ", ThreadID: " + npcSceneThreadInt)

	if participants == None || CountValidActors(participants) < 2
		Debug.Trace("[CHIM-NSFW] OStimThreadSceneChanged: No valid participants")
		return
	endif

	bool playerInScene = false
	int i = 0
	while i < participants.Length
		if participants[i] != None
			if participants[i] == Game.GetPlayer()
				playerInScene = true
			else
				AIAgentFunctions.setAnimationBusy(1, participants[i].GetDisplayName())
				if OActor.HasExpressionOverride(participants[i])
					AIAgentFunctions.setLocked(1, participants[i].GetDisplayName())
				else
					AIAgentFunctions.setLocked(0, participants[i].GetDisplayName())
				endif
			endif
		endif
		i += 1
	endwhile

	; NPC-only OStim NPC expansion scenes may arrive here without a usable thread-start JSON payload.
	; Emit the canonical NPC-scene event from the live thread actor list so CHIM can see it.
	if !playerInScene
		int npc1ToNpc2Rank = participants[0].GetRelationshipRank(participants[1])
		int npc2ToNpc1Rank = participants[1].GetRelationshipRank(participants[0])
		string npcSceneData = participants[0].GetDisplayName() + "^" + participants[1].GetDisplayName() + "^" + npcSceneThreadInt + "^" + resolvedSceneID + "^" + npc1ToNpc2Rank + "^" + npc2ToNpc1Rank
		RequestNpcSceneTurn(participants, npcSceneData, "OStimThreadSceneChanged", npcSceneThreadInt)
	endif
EndEvent

Event OStimActorOrgasm(string EventName, string SceneID, float ThreadID, Form Sender)
	Actor OrgasmedActor = Sender as Actor
	
	Debug.Trace("[CHIM-NSFW] OStimActorOrgasm: "+EventName+","+SceneID+","+ThreadID+","+sender.GetName())


EndEvent

Event OStimThreadEnd(string EventName, string Json, float ThreadID, Form Sender)
	; the following code only works with API version 7.3.1 or higher
	int threadEndIDInt = ThreadID as int
	Actor[] Actors = None
	string SceneID = ""
	if Json != ""
		Actors = OJSON.GetActors(Json)
		SceneID = OJSON.GetScene(Json)
	endif
	if Actors == None || CountValidActors(Actors) < 1
		Actors = GetOStimThreadParticipantsSafe(threadEndIDInt)
	endif
	if SceneID == ""
		SceneID = OThread.GetScene(threadEndIDInt)
	endif
	Debug.Trace("[CHIM-NSFW] OStimThreadEnd: "+EventName+","+Json+","+ThreadID+","+sender.GetName())
	if Actors == None || CountValidActors(Actors) < 1
		; Live resolution failed (empty end JSON / thread already torn down). Fall back to the stashed roster so
		; the scene still tears down cleanly - otherwise NPC scene state lingers and bleeds into later dialogue.
		string stashScoring = BuildScoringFromStash(threadEndIDInt)
		if stashScoring != ""
			AIAgentFunctions.requestMessage(stashScoring, "chatnf_sl_end")
			ClearStashedNpcSceneActors(threadEndIDInt)
			Debug.Trace("[CHIM-NSFW] OStimThreadEnd: used stashed roster for teardown -> " + stashScoring)
			return
		endif
		Debug.Trace("[CHIM-NSFW] OStimThreadEnd: No actors from JSON or OThread fallback")
		return
	endif
	int i = 0 
	string scoring=""

	while i < Actors.Length
			Actor participant = Actors[i]
			if participant == None
				i += 1
			else
			
			Debug.Trace("[CHIM-NSFW] OStimThreadEnd. Participant: " + participant.GetDisplayName())
			
			if (participant == Game.GetPlayer())
				Debug.Trace("[CHIM-NSFW] OStimThreadEnd. Participant is player " + participant.GetDisplayName())
			else
				AIAgentFunctions.setLocked(0,participant.GetDisplayName())
				AIAgentFunctions.setAnimationBusy(0,participant.GetDisplayName())
			endif

			scoring = scoring + "/" + participant.GetDisplayName() + "@100"

			i += 1
			endif
	endwhile

	AIAgentFunctions.requestMessage(scoring,"chatnf_sl_end")
	ClearStashedNpcSceneActors(threadEndIDInt)

EndEvent


; ============================================
; SUBTHREAD EVENTS - NPC-to-NPC Scenes (OStim NPCs mod)
; These use the SAME pipeline as player scenes so NPCs get:
; - Tier prompts based on their relationship with each other
; - Sex prompts and speak styles from their NSFW profiles
; - Marriage/affair detection (if NPC is married but partner != spouse)
; - Orgasm handling and pillow talk
; ============================================

Event OStimSubthreadStart(string EventName, string SceneID, float SubthreadID, Form Sender)
	; Subthread started - NPC-to-NPC scene beginning
	; SceneID contains the scene identifier, SubthreadID is the subthread index
	Debug.Trace("[CHIM-NSFW] OStimSubthreadStart: " + EventName + ", SceneID: " + SceneID + ", SubthreadID: " + SubthreadID)

	int threadId = SubthreadID as int

	; Get actors in this subthread
	Actor[] participants = GetOStimThreadParticipantsSafe(threadId)
	if participants == None || CountValidActors(participants) < 2
		Debug.Trace("[CHIM-NSFW] OStimSubthreadStart: No valid participants found")
		return
	endif

	; Get scene metadata
	String[] tags = OMetadata.GetSceneTags(SceneID)
	String sceneTags = OCSV.ToCSVList(tags)
	string sexPos = SceneID

	Debug.Trace("[CHIM-NSFW] OStimSubthreadStart: Scene Tags: " + sceneTags)

	; Build actor list in same format as player scenes
	String actorList = ""
	int i = 0
	while i < participants.Length
		Actor participant = participants[i]
		if participant == None
			i += 1
		else
			actorList = actorList + "/" + participant.GetDisplayName()

			; Set animation busy for all NPC participants
			AIAgentFunctions.setAnimationBusy(1, participant.GetDisplayName())

			; Check mouth state for locking
			bool isMouthOpen = OActor.HasExpressionOverride(participant)
			if isMouthOpen
				AIAgentFunctions.setLocked(1, participant.GetDisplayName())
				Debug.Trace("[CHIM-NSFW] NPC " + participant.GetDisplayName() + " mouth busy")
			else
				AIAgentFunctions.setLocked(0, participant.GetDisplayName())
			endif

			Debug.Trace("[CHIM-NSFW] OStimSubthreadStart participant: " + participant.GetDisplayName())
			i += 1
		endif
	endwhile

	; NPC-only subthreads must stay on the NPC-to-NPC route.
	; Player-style ext_nsfw_sexcene is reserved for player + NPC scenes.
	int npc1ToNpc2Rank = participants[0].GetRelationshipRank(participants[1])
	int npc2ToNpc1Rank = participants[1].GetRelationshipRank(participants[0])
	string npcSceneData = participants[0].GetDisplayName() + "^" + participants[1].GetDisplayName() + "^" + threadId + "^" + SceneID + "^" + npc1ToNpc2Rank + "^" + npc2ToNpc1Rank
	RequestNpcSceneTurn(participants, npcSceneData, "OStimSubthreadStart", threadId)

	Debug.Trace("[CHIM-NSFW] OStimSubthreadStart: Routed ext_nsfw_npc_scene for NPC scene: " + actorList)
EndEvent


Event OStimSubthreadEnd(string EventName, string SceneID, float SubthreadID, Form Sender)
	; Subthread ended - NPC-to-NPC scene finished
	Debug.Trace("[CHIM-NSFW] OStimSubthreadEnd: " + EventName + ", SceneID: " + SceneID + ", SubthreadID: " + SubthreadID)

	int threadId = SubthreadID as int

	; Get actors that were in this subthread
	Actor[] participants = GetOStimThreadParticipantsSafe(threadId)
	if participants == None
		; Fall back to the stashed roster so the scene still tears down cleanly.
		string stashScoring = BuildScoringFromStash(threadId)
		if stashScoring != ""
			AIAgentFunctions.requestMessage(stashScoring, "chatnf_sl_end")
			ClearStashedNpcSceneActors(threadId)
			Debug.Trace("[CHIM-NSFW] OStimSubthreadEnd: used stashed roster for teardown -> " + stashScoring)
			return
		endif
		Debug.Trace("[CHIM-NSFW] OStimSubthreadEnd: No participants found")
		return
	endif

	; Build scoring string and clear states
	String scoring = ""
	int i = 0
	while i < participants.Length
		Actor participant = participants[i]
		if participant == None
			i += 1
		else

			; Clear animation and lock states
			AIAgentFunctions.setLocked(0, participant.GetDisplayName())
			AIAgentFunctions.setAnimationBusy(0, participant.GetDisplayName())

			; Add to scoring (same format as player scenes)
			scoring = scoring + "/" + participant.GetDisplayName() + "@100"

			Debug.Trace("[CHIM-NSFW] OStimSubthreadEnd participant: " + participant.GetDisplayName())
			i += 1
		endif
	endwhile

	; Send scene end event - PHP handles pillow talk for both NPCs
	AIAgentFunctions.requestMessage(scoring, "chatnf_sl_end")
	ClearStashedNpcSceneActors(threadId)

	Debug.Trace("[CHIM-NSFW] OStimSubthreadEnd: Sent chatnf_sl_end for NPC scene")
EndEvent


Event OStimSubthreadOrgasm(string EventName, string SceneID, float SubthreadID, Form Sender)
	; NPC orgasm in subthread scene
	; Sender is the Actor who orgasmed
	Actor orgasmedActor = Sender as Actor

	if orgasmedActor == None
		Debug.Trace("[CHIM-NSFW] OStimSubthreadOrgasm: No valid actor")
		return
	endif

	string actorName = orgasmedActor.GetDisplayName()
	Debug.Trace("[CHIM-NSFW] OStimSubthreadOrgasm: " + actorName + " reached climax in scene " + SceneID)

	; Get the other participants to know who they climaxed with
	int threadId = SubthreadID as int
	string partnerName = ResolveOStimOrgasmPartnerName(orgasmedActor, threadId, SceneID)

	; Send climax event - same format as player scenes
	; Format: actorName^partnerName^sceneID^actionType (using ^ to avoid CHIM pipe conflict)
	string climaxData = actorName + "^" + partnerName + "^" + SceneID + "^" + lastResolvedOStimOrgasmActionType
	AIAgentFunctions.requestMessageForActor(climaxData, "ext_nsfw_npc_orgasm", actorName)

	; Server handles speech triggering via chatnf_sl_climax when appropriate
	Debug.Trace("[CHIM-NSFW] OStimSubthreadOrgasm: Sent climax event for " + actorName + " with partner " + partnerName + " action=" + lastResolvedOStimOrgasmActionType)
EndEvent


Event OStimNpcInvite(string EventName, string InviteData, float ThreadID, Form Sender)
	; NPC-to-NPC invite phase - when dom actor is approaching sub actor
	; This fires BEFORE the scene starts, perfect time for tier prompts
	; InviteData format: DomActor^SubActor^ThirdActor (third is optional)
	Debug.Trace("[CHIM-NSFW] OStimNpcInvite: " + InviteData + " (ThreadID: " + ThreadID + ")")

	; Parse the invite data to get dom actor name for requestMessage
	string[] parts = StringUtil.Split(InviteData, "^")
	string domActorName = ""
	if parts.Length > 0
		domActorName = parts[0]
	endif

	; Use requestMessageForActor to trigger LLM response for NPC-to-NPC invite
	; This makes NPC scene start work like orgasm events - fires as chat event
	if domActorName != ""
		AIAgentFunctions.requestMessageForActor(InviteData, "ext_nsfw_npc_invite", domActorName)
		Debug.Trace("[CHIM-NSFW] OStimNpcInvite: Sent ext_nsfw_npc_invite via requestMessageForActor for " + domActorName)
	else
		; Fallback to logMessage if parsing failed
		AIAgentFunctions.logMessage(InviteData, "ext_nsfw_npc_invite")
		Debug.Trace("[CHIM-NSFW] OStimNpcInvite: Sent ext_nsfw_npc_invite via logMessage (fallback)")
	endif
EndEvent


Event FertilityImpregnated(Form Sender)
	;Sender is impregnated
	Actor akActor = Sender as Actor
	Debug.Trace("[CHIM-NSFW FERTILITY] "+akActor.GetDisplayName()+"@pregnant")
	AIAgentFunctions.logMessageForActor(akActor.GetDisplayName()+"@pregnant","fertility_notification",akActor.GetDisplayName())
EndEvent

Event FertilityAbort(Form Sender)
	;Sender is impregnatedºº
	Actor akActor = Sender as Actor
	Debug.Trace("[CHIM-NSFW FERTILITY] "+akActor.GetDisplayName()+"@aborted")
	AIAgentFunctions.logMessageForActor(akActor.GetDisplayName()+"@aborted","fertility_notification",akActor.GetDisplayName())
EndEvent

Event FertilityBirth(Form Sender)
	Actor akActor = Sender as Actor
	Debug.Trace("[CHIM-NSFW FERTILITY] "+akActor.GetDisplayName()+"@birth")
	AIAgentFunctions.logMessageForActor(akActor.GetDisplayName()+"@birth","fertility_notification",akActor.GetDisplayName())
EndEvent

Event FertilityUpdate(Actor akActor , string modName, string morphName, float scale,string modfile)
	
	Debug.Trace("[CHIM-NSFW FERTILITY] "+akActor.GetDisplayName()+"@"+scale+"@"+morphName+"@"+modName)
	AIAgentFunctions.logMessageForActor(akActor.GetDisplayName()+"@"+scale,"fertility_notification",akActor.GetDisplayName())
EndEvent

Event FertilityLabor(string eventname,Form Sender , int index)
	Actor akActor = Sender as Actor
	if (akActor)
		Debug.Trace("[CHIM-NSFW FERTILITY] FertilityLabor: "+akActor.GetDisplayName()+"@"+eventname+"@"+index)
		AIAgentFunctions.requestMessageForActor("The Narrator: "+akActor.GetDisplayName()+" is in labor, giving birth!","infoaction",akActor.GetDisplayName())
		AIAgentFunctions.requestMessageForActor(akActor.GetDisplayName()+" should express emotions and pain , as is in labor, giving birth!","instruction",akActor.GetDisplayName())
		Utility.wait(10)
		Debug.SendAnimationEvent(akActor, "IdleWounded_02")
	endif

EndEvent

Event FertilityModeConception(string eventname,Form mother , string motherName, string fatherName, int index)
	
	Debug.Trace("[CHIM-NSFW FERTILITY] Event:"+eventname+" , "+motherName+" pregnant of "+fatherName+", index:"+index+", mother fid:"+mother.GetFormId())
	;AIAgentFunctions.logMessageForActor(motherName+"@pregnant","fertility_notification",motherName)
EndEvent

event FertilityModeUpdate(string a = " ", string b = " ", float ScaleStart = 0.0, form sender)
	Debug.Trace("[CHIM-NSFW FERTILITY] FertilityModeUpdate:"+sender.GetName()+", "+a+" ,"+b+" , ScaleStart:"+ScaleStart)
endEvent

event FMPlusConception(string eventname,Form mother , string motherName, string fatherName, int index)
	Debug.Trace("[CHIM-NSFW FERTILITY] Event:"+eventname+" , "+motherName+" is giving birth child of "+fatherName+", index:"+index+", mother fid:"+mother.GetFormId())
	
endEvent

Event FertilityModeLabor(string eventname,Form Sender ,int index)
	Actor akActor = Sender as Actor
	Debug.Trace("[CHIM-NSFW FERTILITY] FertilityModeLabor: "+akActor.GetDisplayName()+"@"+eventname+"@"+index)
	AIAgentFunctions.logMessageForActor(akActor.GetDisplayName()+"@birth","fertility_notification",akActor.GetDisplayName())
	AIAgentFunctions.logMessageForActor("The Narrator: "+akActor.GetDisplayName()+" had a baby!","infoaction",akActor.GetDisplayName())
	AIAgentFunctions.requestMessageForActor(akActor.GetDisplayName()+" should express emotions, a new baby is born!","instruction",akActor.GetDisplayName())
	Debug.SendAnimationEvent(akActor, "idleforcedefaultstate")
EndEvent

Event FMDefinedChildSpawned(Form child)
	Actor akActor = child as Actor
	Debug.Trace("[CHIM-NSFW FERTILITY] Child spawned: "+akActor.GetDisplayName())
EndEvent

; ============================================
; FERTILITY MODE RELOADED (FMR) EVENTS
; ============================================

; Main status event - pregnancy progress, recovery, cycle
; Rank 1-100 = pregnancy %, 101-115 = recovery, 116-119 = cycle, 0 = cleared
Event OnFMRActorStatus(Form akActor, int factionRank, string fatherName, int fatherRaceId)
	Actor mother = akActor as Actor
	if !mother
		return
	endif

	string motherName = mother.GetDisplayName()
	Debug.Trace("[CHIM-NSFW FMR] ActorStatus: " + motherName + " rank=" + factionRank + " father=" + fatherName)

	; Determine status type and send to backend
	if factionRank >= 1 && factionRank <= 100
		; Pregnancy progress (1-100%)
		AIAgentFunctions.logMessageForActor(motherName + "@pregnant@" + factionRank + "@" + fatherName, "fertility_notification", motherName)
	elseif factionRank >= 101 && factionRank <= 115
		; Recovery phase (post-birth)
		int recoveryDay = factionRank - 100
		AIAgentFunctions.logMessageForActor(motherName + "@recovery@" + recoveryDay, "fertility_notification", motherName)
	elseif factionRank == 0
		; Cleared/aborted
		AIAgentFunctions.logMessageForActor(motherName + "@cleared", "fertility_notification", motherName)
	endif
EndEvent

; Baby took damage during pregnancy
Event OnFMRBabyDamage(Form mother, int damage, int remainingHealth)
	Actor akMother = mother as Actor
	if !akMother
		return
	endif

	string motherName = akMother.GetDisplayName()
	Debug.Trace("[CHIM-NSFW FMR] BabyDamage: " + motherName + " damage=" + damage + " health=" + remainingHealth)

	; Notify backend - mother should react to baby being hurt
	AIAgentFunctions.logMessageForActor(motherName + "@baby_damage@" + damage + "@" + remainingHealth, "fertility_notification", motherName)

	; Request emotional response
	if remainingHealth < 30
		AIAgentFunctions.requestMessageForActor(motherName + " feels her unborn child is in danger and should express fear/pain!", "instruction", motherName)
	endif
EndEvent

; Baby died during pregnancy
Event OnFMRBabyDeath(Form mother, string cause)
	Actor akMother = mother as Actor
	if !akMother
		return
	endif

	string motherName = akMother.GetDisplayName()
	Debug.Trace("[CHIM-NSFW FMR] BabyDeath: " + motherName + " cause=" + cause)

	; Notify backend
	AIAgentFunctions.logMessageForActor(motherName + "@baby_death@" + cause, "fertility_notification", motherName)

	; Request grief response
	AIAgentFunctions.requestMessageForActor(motherName + " has lost her unborn child and should express grief!", "instruction", motherName)
EndEvent

; Miscarriage event
Event OnFMRBabyMiscarriage(Form akActor, string cause)
	Actor mother = akActor as Actor
	if !mother
		return
	endif

	string motherName = mother.GetDisplayName()
	Debug.Trace("[CHIM-NSFW FMR] Miscarriage: " + motherName + " cause=" + cause)

	; Notify backend
	AIAgentFunctions.logMessageForActor(motherName + "@miscarriage@" + cause, "fertility_notification", motherName)

	; Request emotional response
	AIAgentFunctions.requestMessageForActor(motherName + " has had a miscarriage and should express grief/pain!", "instruction", motherName)
EndEvent

; Baby status update (age, health, days remaining)
Event OnFMRBabyStatus(Form mother, int babyAgeDays, int babyHealth, int daysRemaining)
	Actor akMother = mother as Actor
	if !akMother
		return
	endif

	string motherName = akMother.GetDisplayName()
	Debug.Trace("[CHIM-NSFW FMR] BabyStatus: " + motherName + " age=" + babyAgeDays + " health=" + babyHealth + " daysLeft=" + daysRemaining)

	; Only notify on significant milestones
	if babyAgeDays == 1 || babyAgeDays == 7 || daysRemaining <= 3
		AIAgentFunctions.logMessageForActor(motherName + "@baby_status@" + babyAgeDays + "@" + babyHealth + "@" + daysRemaining, "fertility_notification", motherName)
	endif
EndEvent

; Mother died while pregnant or with baby
Event OnFMRMotherDeath(Form deadActor, int wasPregnant, int hadBaby)
	Actor mother = deadActor as Actor
	if !mother
		return
	endif

	string motherName = mother.GetDisplayName()
	Debug.Trace("[CHIM-NSFW FMR] MotherDeath: " + motherName + " wasPregnant=" + wasPregnant + " hadBaby=" + hadBaby)

	; Notify backend for narrative purposes
	if wasPregnant
		AIAgentFunctions.logMessageForActor(motherName + "@mother_death@pregnant", "fertility_notification", motherName)
	elseif hadBaby
		AIAgentFunctions.logMessageForActor(motherName + "@mother_death@with_baby", "fertility_notification", motherName)
	endif
EndEvent

; UTILITIES

 Armor Function GetBestArmorForSlot(Actor akActor, Int aiSlotMask)
    Armor bestArmor = None
    Float bestRating = 0.0
    
    Int itemCount = akActor.GetNumItems()
    
    Int i = 0
    While (i < itemCount)
        Form itemForm = akActor.GetNthForm(i)
        
        If (itemForm.GetType() == 26) ; ARMO - Armor
            Armor armorItem = itemForm as Armor
            
            ; Check if armor fits the slot mask
            If (armorItem.GetSlotMask() == aiSlotMask)
                Float armorRating = armorItem.GetArmorRating()
                Debug.Trace("[CHIM NSFW] Checking "+itemForm.GetName()+ " "+itemForm.GetType());
                ; Compare with current best
                If (armorRating >= bestRating)
                    bestRating = armorRating
                    bestArmor = armorItem
                EndIf
            EndIf
        EndIf
        
        i += 1
    EndWhile
    
    Return bestArmor
EndFunction

Weapon Function GetBestWeapon(Actor akActor)
    Weapon bestWeapon = None
    Float bestDamage = 0.0
    
    Int itemCount = akActor.GetNumItems()
    
    Int i = 0
    While (i < itemCount)
        Form itemForm = akActor.GetNthForm(i)
        
        If (itemForm.GetType() == 41) ; WEAP - Weapon
            Weapon weaponItem = itemForm as Weapon
            Float weaponDamage = weaponItem.GetBaseDamage()
            
            ; Compare with current best
            If (weaponDamage > bestDamage)
                bestDamage = weaponDamage
                bestWeapon = weaponItem
            EndIf
        EndIf
        
        i += 1
    EndWhile
    
    Return bestWeapon
EndFunction

Function GSPoseRemoveClothes(Actor aktarget, Actor akcaster)


	aktarget.SetAnimationVariableInt("IsNPC", 1) ; disable head tracking
	aktarget.SetAnimationVariableBool("bHumanoidFootIKDisable", True) ; disable inverse kinematics
	debug.sendanimationevent(aktarget, "stripteasehands")
	utility.wait(2.5)
	akTarget.unequipitemslot(33) ;hands
	akTarget.unequipitemslot(34) ;forearms
	
	debug.sendanimationevent(aktarget, "stripteaseshoes")
	utility.wait(3)
	akTarget.unequipitemslot(37) ;feet
	akTarget.unequipitemslot(38) ;calves
	
	debug.sendanimationevent(aktarget, "stripteasebuttom")
	utility.wait(3)
	akTarget.unequipitemslot(52) ;pelvis secondary or undergarment
	akTarget.unequipitemslot(49) ;pelvis primary or outergarment
	
	debug.sendanimationevent(aktarget, "stripteasetop")
	utility.wait(3)
	akTarget.unequipitemslot(32) ;body (full)
	akTarget.unequipitemslot(46) ;chest primary or outergarment
	akTarget.unequipitemslot(56) ;chest secondary or undergarment
	
	debug.sendanimationevent(aktarget, "IdleDialogueExpresiveStart")
	utility.wait(3)
	aktarget.SetAnimationVariableInt("IsNPC", 1) ; enable head tracking
	aktarget.SetAnimationVariableBool("bHumanoidFootIKDisable", False) ; enable inverse kinematics
	
	
endfunction

Function StartStripTease(Actor aktarget, Actor akcaster)
	
	AIAgentFunctions.setAnimationBusy(1,aktarget.GetDisplayName())	
	aktarget.SetAnimationVariableInt("IsNPC", 1) ; disable head tracking
	aktarget.SetAnimationVariableBool("bHumanoidFootIKDisable", True) ; disable inverse kinematics
	debug.sendanimationevent(aktarget, "gs44")
	utility.wait(3)
	debug.sendanimationevent(aktarget, "gs1")
	utility.wait(3)
	debug.sendanimationevent(aktarget, "gs25")
	utility.wait(3)
	debug.sendanimationevent(aktarget, "stripteasehands")
	utility.wait(2.5)
	akTarget.unequipitemslot(33) ;hands
	akTarget.unequipitemslot(34) ;forearms
	debug.sendanimationevent(aktarget, "gs2")
	utility.wait(3)

	debug.sendanimationevent(aktarget, "gs103")
	utility.wait(3)
	debug.sendanimationevent(aktarget, "gs52")
	utility.wait(3)
	debug.sendanimationevent(aktarget, "stripteaseshoes")
	utility.wait(3)
	akTarget.unequipitemslot(37) ;feet
	akTarget.unequipitemslot(38) ;calves
	debug.sendanimationevent(aktarget, "gs3")
	utility.wait(3)
	debug.sendanimationevent(aktarget, "gs30")
	utility.wait(3)
	debug.sendanimationevent(aktarget, "gs54")
	utility.wait(3)
	debug.sendanimationevent(aktarget, "stripteasebuttom")
	utility.wait(3)
	akTarget.unequipitemslot(52) ;pelvis secondary or undergarment
	akTarget.unequipitemslot(49) ;pelvis primary or outergarment
	debug.sendanimationevent(aktarget, "stripteasetop")
	utility.wait(3)
	akTarget.unequipitemslot(32) ;body (full)
	akTarget.unequipitemslot(46) ;chest primary or outergarment
	akTarget.unequipitemslot(56) ;chest secondary or undergarment
	debug.sendanimationevent(aktarget, "gs16")
	utility.wait(3)
	debug.sendanimationevent(aktarget, "gs35")
	utility.wait(3)
	debug.sendanimationevent(aktarget, "gs97")
	utility.wait(3)
	aktarget.unequipall()
	debug.sendanimationevent(aktarget, "gs17")
	utility.wait(3)
	debug.sendanimationevent(aktarget, "gs39")
	utility.wait(3)
	debug.sendanimationevent(aktarget, "gs37")
	utility.wait(2.5)
	debug.sendanimationevent(aktarget, "idleforcedefaultstate")

	aktarget.SetAnimationVariableInt("IsNPC", 1) ; enable head tracking
	aktarget.SetAnimationVariableBool("bHumanoidFootIKDisable", False) ; enable inverse kinematics
	AIAgentFunctions.setAnimationBusy(0,aktarget.GetDisplayName())	

EndFunction

function FastRemoveClothes(Actor npc)
	Form[] equippedItems=PO3_SKSEFunctions.AddAllEquippedItemsToArray(npc);
	Int iElement = equippedItems.Length
	Int iIndex = 0
	
		
	while iIndex < equippedItems.Length
		Form currentItem = equippedItems[iIndex]
		Armor armorItem = currentItem as Armor

		if armorItem != None
			; npc.UnequipItem(armorItem, false, false)
			int slotMask = armorItem.GetSlotMask()

			; These are standard head-related slots
			bool isHeadItem = (Math.LogicalAnd(slotMask, 0x00000001) != 0 || Math.LogicalAnd(slotMask, 0x00000002) != 0 || Math.LogicalAnd(slotMask, 0x00000800) != 0 || Math.LogicalAnd(slotMask, 0x00001000) != 0)

			if (Math.LogicalAnd(slotMask, 0x00000004) != 0)	; Explicity if body. Robes also occup head slot
				isHeadItem=false;
			endif
			
			if !isHeadItem
				npc.UnequipItem(armorItem, false, false)
			endif
		endif
		
		Weapon WeaponItem = currentItem as Weapon

		if WeaponItem != None
			npc.UnequipItem(WeaponItem, false, false)
		endif
		
		;Utility.Wait(1)
		iIndex += 1
	endwhile
endfunction

function UnequipItemBySlot(Actor npc, int slot)
	Form[] equippedItems=PO3_SKSEFunctions.AddAllEquippedItemsToArray(npc);
	Int iElement = equippedItems.Length
	Int iIndex = 0
	
		
	while iIndex < equippedItems.Length
		Form currentItem = equippedItems[iIndex]
		Armor armorItem = currentItem as Armor

		if armorItem != None
			; npc.UnequipItem(armorItem, false, false)
			Debug.Trace("[CHIM NSFW] Checking "+armorItem.getName());
			int slotMask = armorItem.GetSlotMask()

			
			bool matchesSlot = Math.LogicalAnd(slotMask, slot) != 0 

			
			if matchesSlot
				npc.UnequipItem(armorItem, false, false)
			endif
		endif
		
		iIndex += 1
	endwhile
endfunction

Function Dance(Actor aktarget, Actor akcaster)
		
	aktarget.SetAnimationVariableInt("IsNPC", 1) ; disable head tracking
	aktarget.SetAnimationVariableBool("bHumanoidFootIKDisable", True) ; disable inverse kinematics
	debug.sendanimationevent(aktarget, "gs44")
	utility.wait(3)
	debug.sendanimationevent(aktarget, "gs1")
	utility.wait(3)
	debug.sendanimationevent(aktarget, "gs25")
	utility.wait(3)
	debug.sendanimationevent(aktarget, "gs2")
	utility.wait(3)

	debug.sendanimationevent(aktarget, "gs103")
	utility.wait(3)
	debug.sendanimationevent(aktarget, "gs52")
	utility.wait(3)

	debug.sendanimationevent(aktarget, "gs3")
	utility.wait(3)
	debug.sendanimationevent(aktarget, "gs30")
	utility.wait(3)
	debug.sendanimationevent(aktarget, "gs54")
	utility.wait(3)
	debug.sendanimationevent(aktarget, "gs16")
	utility.wait(3)
	debug.sendanimationevent(aktarget, "gs35")
	utility.wait(3)
	debug.sendanimationevent(aktarget, "gs97")
	utility.wait(3)
	debug.sendanimationevent(aktarget, "gs17")
	utility.wait(3)
	debug.sendanimationevent(aktarget, "gs39")
	utility.wait(3)
	debug.sendanimationevent(aktarget, "gs37")
	utility.wait(2.5)
	debug.sendanimationevent(aktarget, "idleforcedefaultstate")

	aktarget.SetAnimationVariableInt("IsNPC", 1) ; enable head tracking
	aktarget.SetAnimationVariableBool("bHumanoidFootIKDisable", False) ; enable inverse kinematics

EndFunction



; ============================================================================
; SEXLAB INTEGRATION (active) - mirrors the OStim server paths.
; SexLab fires global Hook<Event> ModEvents (int tid, bool HasPlayer) for EVERY
; scene including NPC-to-NPC; per-actor orgasm is "SexLabOrgasm" (Form akActor,...).
; Registered in DoRegister(). Player scenes get speech-style dialogue (chatnf_sl);
; NPC-to-NPC stays scene-aware only (NO speech styles, by design).
; ============================================================================

Event OnSexLabAnimStart(int tid, bool HasPlayer)
	SexLabFramework SexLab = SexLabUtil.GetAPI()
	if (!SexLab)
		return
	endif
	sslThreadController controller = SexLab.GetController(tid)
	if (!controller)
		return
	endif
	Actor[] actors = controller.Positions
	if (actors.Length < 1)
		return
	endif

	string sceneID = controller.Animation.Name
	string sceneTags = PapyrusUtil.StringJoin(controller.Animation.GetTags(), ",") ; GetTags() is string[]; the bare cast produced "[A, B, C]" which broke server CSV tag parsing (fix 2026-07-01)
	string actorList = ""
	Actor firstNpc = None
	int i = 0
	while (i < actors.Length)
		actorList = actorList + "/" + actors[i].GetDisplayName()
		if (actors[i].GetFormID() != 0x14)
			if (firstNpc == None)
				firstNpc = actors[i]
			endif
			AIAgentFunctions.setAnimationBusy(1, actors[i].GetDisplayName())
		endif
		i += 1
	endWhile
	if (firstNpc == None)
		return
	endif

	; NPC-to-NPC SexLab scene: emit the rich relationship-tier event first (mirrors OStimThreadStart) so the server
	; sets is_npc_scene/npc_scene_partner and uses the NPC-to-NPC relationship/tier prompts (parity with OStim).
	if (!HasPlayer && actors.Length >= 2)
		int npc1ToNpc2Rank = actors[0].GetRelationshipRank(actors[1])
		int npc2ToNpc1Rank = actors[1].GetRelationshipRank(actors[0])
		string npcSceneData = actors[0].GetDisplayName() + "^" + actors[1].GetDisplayName() + "^" + tid + "^" + sceneID + "^" + npc1ToNpc2Rank + "^" + npc2ToNpc1Rank
		RequestNpcSceneTurn(actors, npcSceneData, "SexLabNpcSceneStart", tid)
		Debug.Trace("[CHIM-NSFW] SexLab NPC-only scene start tid=" + tid + " requested ext_nsfw_npc_scene")
		return
	endif

	; SOLO NPC scene (masturbation): keep it OFF the player-scene route (fix 2026-07-01, OStim parity - a solo
	; scene fires no player-scene event; the server's canonicalization needs >=2 actors anyway).
	if (!HasPlayer)
		return
	endif

	; Matches the ext_nsfw_sexcene wire OStimStart uses: SceneID/tags/SceneID/actors
	; NO consent bark here (user-confirmed 2026-07-01): the bark path is vestigial/unreliable - live logs show every
	; bark ever fired was routed to The Narrator and blocked by policy. The tier-3 consent decision is driven by the
	; AcceptSex/RefuseSex toolset strip on the normal scene turn, not by a fast bark.
	string sceneData = sceneID + "/" + sceneTags + "/" + sceneID + actorList
	AIAgentFunctions.requestMessageForActor(sceneData, "ext_nsfw_sexcene", firstNpc.GetDisplayName())
	Debug.Trace("[CHIM-NSFW] SexLab scene start tid=" + tid + " player=" + HasPlayer)
EndEvent

Event OnSexLabStageStart(int tid, bool HasPlayer)
	SexLabFramework SexLab = SexLabUtil.GetAPI()
	if (!SexLab)
		return
	endif
	sslThreadController controller = SexLab.GetController(tid)
	if (!controller)
		return
	endif
	Actor[] actors = controller.Positions
	if (actors.Length < 1)
		return
	endif

	; Scene awareness for ALL scenes (incl NPC-to-NPC) - no speech styles, just context
	string sceneID = controller.Animation.Name
	string sceneTags = PapyrusUtil.StringJoin(controller.Animation.GetTags(), ",") ; GetTags() is string[]; the bare cast produced "[A, B, C]" which broke server CSV tag parsing (fix 2026-07-01)
	string stageDesc = controller.Animation.FetchStage(controller.Stage)[0]
	string actorList = ""
	; FORWARD order (fix 2026-07-01): the reverse loop stored raw_scene_actor_slots backwards vs Positions,
	; so the server's slot->name lookup could name the wrong actor on stage updates.
	int i = 0
	while (i < actors.Length)
		actorList = actorList + "/" + actors[i].GetDisplayName()
		if (actors[i].GetFormID() != 0x14)
			if (SexLab.isMouthOpen(actors[i]))
				AIAgentFunctions.setLocked(1, actors[i].GetDisplayName())
			else
				AIAgentFunctions.setLocked(0, actors[i].GetDisplayName())
			endif
		endif
		i += 1
	endWhile
	; NPC-only stages stay on the NPC-to-NPC route and request one participant response.
	if (!HasPlayer && actors.Length >= 2)
		int npc1ToNpc2Rank = actors[0].GetRelationshipRank(actors[1])
		int npc2ToNpc1Rank = actors[1].GetRelationshipRank(actors[0])
		string stageSceneID = sceneID + "_stage_" + controller.Stage
		string npcSceneData = actors[0].GetDisplayName() + "^" + actors[1].GetDisplayName() + "^" + tid + "^" + stageSceneID + "^" + npc1ToNpc2Rank + "^" + npc2ToNpc1Rank
		RequestNpcSceneTurn(actors, npcSceneData, "SexLabNpcStage", tid)
		return
	endif

	; SOLO NPC stage: off the player-scene route (fix 2026-07-01, mirrors the scene-start guard)
	if (!HasPlayer)
		return
	endif

	AIAgentFunctions.logMessage(sceneID + "/" + sceneTags + "/" + stageDesc + actorList, "info_sexscene")

	; Spoken per-stage dialogue (speech styles) ONLY for player scenes
	Actor[] sorted = SexLab.SortActors(actors, false)
	i = sorted.Length
	bool talked = false
	Actor participantTalk = None
	while (i > 0)
		i -= 1
		if (sorted[i].GetFormID() != 0x14)
			if (!SexLab.isMouthOpen(sorted[i]))
				participantTalk = sorted[i]
				talked = true
				i = 0
			endif
		endif
	endWhile
	if (participantTalk != None)
		string sceneData = sceneID + "/" + sceneTags + "/" + stageDesc + actorList
		AIAgentFunctions.requestMessageForActor(sceneData, "ext_nsfw_sexcene", participantTalk.GetDisplayName())
		Debug.Trace("[CHIM-NSFW] SexLab stage routed ext_nsfw_sexcene to " + participantTalk.GetDisplayName())
	endif
	if (!talked)
		; Legacy chatnf_sl narrator fallback disabled. ext_nsfw_sexcene owns model prompting.
		; AIAgentFunctions.requestMessageForActor("The Narrator: everyone is busy. The Narrator comments on the scene.", "chatnf_sl_nr", "The Narrator")
	endif
EndEvent

Event OnSexLabActorOrgasm(Form akActor, int FullEnjoyment, int Orgasms)
	Actor orgasmer = akActor as Actor
	if (!orgasmer)
		return
	endif
	SexLabFramework SexLab = SexLabUtil.GetAPI()
	if (!SexLab)
		return
	endif
	int tid = SexLab.FindActorController(orgasmer)
	if (tid < 0)
		return
	endif
	sslThreadController controller = SexLab.GetController(tid)
	if (!controller)
		return
	endif
	Actor[] actors = controller.Positions
	if (actors.Length < 1)
		return
	endif

	string sceneID = controller.Animation.Name
	; Action-type field for the server (fix 2026-07-01, OStim parity): SexLab has no per-action metadata, but the
	; animation TAGS csv substring-matches the same server act table (blowjob/anal/handjob/...), reviving the
	; orgasm-location phrase + act-specific cues in SexLab scenes.
	string slOrgasmTags = PapyrusUtil.StringJoin(controller.Animation.GetTags(), ",")
	bool sceneHasPlayer = false
	int orgasmerIndex = 0
	string partnerName = ""
	int k = 0
	while (k < actors.Length)
		if (actors[k].GetFormID() == 0x14)
			sceneHasPlayer = true
		endif
		if (actors[k] == orgasmer)
			orgasmerIndex = k
		elseif (partnerName == "")
			partnerName = actors[k].GetDisplayName()
		endif
		k += 1
	endWhile

	if (orgasmer.GetFormID() == 0x14)
		; Player came - everyone in THIS scene hears it (fan out to every non-player actor)
		string playerData = "PLAYER_ORGASM/" + sceneID + "/" + orgasmerIndex + "/" + partnerName + "/" + slOrgasmTags ; [3]=partner [4]=action (fix 2026-07-01: was the player's own name at [3] and no action field)
		int m = 0
		while (m < actors.Length)
			if (actors[m].GetFormID() != 0x14)
				AIAgentFunctions.requestMessageForActor(playerData, "ext_nsfw_orgasm", actors[m].GetDisplayName())
			endif
			m += 1
		endWhile
	else
		; NPC came - tell THAT NPC. Player scene -> ext_nsfw_orgasm; NPC-to-NPC -> ext_nsfw_npc_orgasm
		string orgasmData = orgasmer.GetDisplayName() + "/" + sceneID + "/" + orgasmerIndex
		if (partnerName != "")
			orgasmData = orgasmData + "/" + partnerName + "/" + slOrgasmTags ; [4]=action (fix 2026-07-01, OStim parity)
		endif
		if (sceneHasPlayer)
			AIAgentFunctions.requestMessageForActor(orgasmData, "ext_nsfw_orgasm", orgasmer.GetDisplayName())
		else
			AIAgentFunctions.requestMessageForActor(orgasmer.GetDisplayName() + "^" + partnerName + "^" + sceneID + "^" + slOrgasmTags, "ext_nsfw_npc_orgasm", orgasmer.GetDisplayName())
		endif
	endif
EndEvent

Event OnSexLabAnimEnd(int tid, bool HasPlayer)
	SexLabFramework SexLab = SexLabUtil.GetAPI()
	if (!SexLab)
		return
	endif
	sslThreadController controller = SexLab.GetController(tid)
	string score = ""
	if (controller)
		Actor[] actors = controller.Positions
		int i = actors.Length
		while (i > 0)
			i -= 1
			score = score + "/" + actors[i].GetDisplayName() + "@" + SexLab.GetEnjoyment(tid, actors[i])
			if (actors[i].GetFormID() != 0x14)
				AIAgentFunctions.setAnimationBusy(0, actors[i].GetDisplayName())
				AIAgentFunctions.setLocked(0, actors[i].GetDisplayName())
			endif
		endWhile
	endif

	; Insurance: if SexLab's controller was already gone, fall back to the stashed roster so teardown still fires.
	if score == ""
		score = BuildScoringFromStash(tid)
	endif

	AIAgentFunctions.logMessage("# END OF SEX SCENE", "infoaction")
	AIAgentFunctions.requestMessage(score, "chatnf_sl_end")
	ClearStashedNpcSceneActors(tid)
	AIAgentFunctions.logMessage("", "force_current_task")
EndEvent


; SEXLAB RELATED (legacy reference - superseded by the active block above)

;/
function StartIntimateSceneWithPlayer(Actor npc, int level=0,string tags)
	
	SexLabFramework _slf = SexLabUtil.GetAPI() 
	
	_slf.QuickStart(npc,Game.GetPlayer(),none, none,  none, none, "", tags);
	
endFunction

 Event OnAnimationStart(int tid, bool HasPlayer)

	SexLabFramework SexLab = SexLabUtil.GetAPI() 
	
	Actor[] actorList = SexLab.GetController(tid).Positions
	Actor[] sortedActorList = SexLab.SortActors(actorList,true)
	int i = sortedActorList.Length
	bool playerInScene=false
	while(i > 0)
            i -= 1
            if (sortedActorList[i].GetFormID()==0x14) 
				playerInScene=true;
			else
				AIAgentFunctions.setAnimationBusy(1,sortedActorList[i].GetDisplayName())
			endif
    endwhile
	
	if (playerInScene)
		
	endif;
	
	Debug.Notification("[CHIM-NSFW] Started intimate scene")
	Debug.Trace("[CHIM-NSFW] Started intimate scene")
	
EndEvent


Event OnStageStart(int tid, bool HasPlayer)

		SexLabFramework SexLab = SexLabUtil.GetAPI() 
		sslThreadController controller = SexLab.GetController(tid)
		; Why OnAnimationStart isnt registering?
		if (controller.Stage==1) 
		endif
		

		Actor[] actorList = SexLab.GetController(tid).Positions
		Bool playerInScene=false;
		Actor[] targetactorList = actorList
		Int howmuch
		
		If (actorList.length < 1)
			return
		EndIf
		
		String pleasure=""

		Actor[] sortedActorList = SexLab.SortActors(actorList,false)
		
		
		int i = sortedActorList.Length
		;while(i > 0)
        ;    i -= 1
        ;    pleasure=pleasure+sortedActorList[i].GetDisplayName()+" pleasure score "+SexLab.GetEnjoyment(tid,sortedActorList[i])+","
        ;endwhile
		
		String sceneTags=""+controller.Animation.GetTags()+"/"
		if (controller.Animation.GetTags()=="")
			sceneTags="/";
		EndIf
		
		String sexPos="" +controller.Animation.Name+"/";
		;String pleasureFull=pleasure
		
		String description1=controller.Animation.FetchStage(controller.Stage)[0]+"/"
		String description2="";
		i = actorList.Length
		while(i > 0)
            i -= 1
			description2=description2+actorList[i].GetDisplayName()+"/"
			if (actorList[i]==Game.GetPlayer())
				playerInScene=true;
			else
				if (SexLab.isMouthOpen(actorList[i]))
					Debug.Trace("[CHIM NSFW] "+actorList[i].GetDisplayName()+" has mouth open");
					AIAgentFunctions.setLocked(1,actorList[i].GetDisplayName())
				else
					AIAgentFunctions.setLocked(0,actorList[i].GetDisplayName())
				endif
			endif

            ;pleasure=pleasure+sortedActorList[i].GetDisplayName()+" pleasure score "+SexLab.GetEnjoyment(tid,sortedActorList[i])+","
        endwhile
		
		
		Actor firstPartipant=actorList[0]; Get Female (assuming player is male, and he is having part in this sex scene)
		
		; Send event, AI can be aware SEX is happening here
		AIAgentFunctions.logMessage(sexPos+sceneTags+description1+description2,"info_sexscene")
		;.GetDisplayName()+ " and "+actorList[1].GetDisplayName()+ " are having a intimate moment."+description+description2+"("+pleasureFull+")","infoaction")
		
		Utility.wait(1);
		
		i = sortedActorList.Length
		bool participantTalk=false
		while(i > 0)
            i -= 1
			Actor participant=sortedActorList[i];
			if participant.GetDisplayName()!=Game.GetPlayer().GetDisplayName()
				if (!SexLab.isMouthOpen(participant))
					; Legacy chatnf_sl auto-talk disabled. ext_nsfw_sexcene owns model prompting.
					; AIAgentFunctions.requestMessageForActor("","chatnf_sl",participant.GetDisplayName())
					participantTalk=true;
					i=0
				else
					Debug.Trace("[CHIM NSFW] "+actorList[i].GetDisplayName()+" has mouth open");
				endif
				
			endif
        endwhile
		
		if (!participantTalk)
			; Legacy chatnf_sl narrator fallback disabled. ext_nsfw_sexcene owns model prompting.
			; AIAgentFunctions.requestMessageForActor("The Narrator: seems everyone is **busy**. Narrator is hot too and comments about the scene","chatnf_sl_nr","The Narrator")
		endIf
		
EndEvent

Event PostSexScene(int tid, bool HasPlayer)
	
		SexLabFramework SexLab = SexLabUtil.GetAPI() 

		sslThreadController controller = SexLab.GetController(tid)

		Actor[] actorList = SexLab.HookActors(tid)
		Actor[] targetactorList = actorList
		Int howmuch
		

		If (actorList.length < 1)
			return
		EndIf
		
		String pleasure=""

		int i = actorList.Length
		while(i > 0)
            i -= 1
            pleasure=pleasure+actorList[i].GetDisplayName()+" is reaching orgasm,"
        endwhile
		String pleasureFull="Pleasure:"+pleasure
		; Send event, AI can be aware SEX is happening here
		AIAgentFunctions.logMessage(pleasureFull,"infoaction")
		Utility.wait(1);
		
		Actor[] sortedActorList = SexLab.SortActors(actorList,true)
		Actor firstPartipant=actorList[0]; Get Female (assuming player is male, and he is having part in this sex scene)
					
		i = sortedActorList.Length
		while(i > 0)
            i -= 1
			Actor participant=sortedActorList[i];
			if participant.GetDisplayName()!=Game.GetPlayer().GetDisplayName()
				if (!SexLab.isMouthOpen(participant))
					; Legacy chatnf_sl climax chatter disabled. ext_nsfw_orgasm owns orgasm prompting.
					; AIAgentFunctions.requestMessageForActor("The Narrator: "+participant.GetDisplayName()+" had an orgasm!","chatnf_sl_climax",participant.GetDisplayName())
				endif
			endif
        endwhile
		
EndEvent

Event EndSexScene(int tid, bool HasPlayer)
	
		SexLabFramework SexLab = SexLabUtil.GetAPI() 

		JValue.release(descriptionsMap)
		Debug.Notification("[CHIM-NSFW] Ended intimate scene")
		sslThreadController controller = SexLab.GetController(tid)

		Actor[] actorList = SexLab.HookActors(tid)
		Actor[] targetactorList = actorList
		Int howmuch
		Actor[] sortedActorList = SexLab.SortActors(actorList,true)

		int i = sortedActorList.Length
		string score=""
		while(i > 0)
            i -= 1
            score=score+"/"+sortedActorList[i].GetDisplayName()+"@"+SexLab.GetEnjoyment(tid,sortedActorList[i])
			
        endwhile
		
		; Send event, AI can be aware SEX is happening here
			
		AIAgentFunctions.logMessage("# END OF SEX SCENE","infoaction")
		
		; POst comment
		Utility.wait(1);
		AIAgentFunctions.requestMessage(score,"chatnf_sl_end")

		bool playerInScene=false
		i = actorList.Length
		while(i > 0)
				i -= 1
				if (sortedActorList[i].GetFormID()==0x14) 
					playerInScene=true;
				else
					AIAgentFunctions.setAnimationBusy(1,sortedActorList[i].GetDisplayName())
				endif
		endwhile
		
		; Change this to restore from CHIM MCM directly
		if (playerInScene)
			Debug.Trace("Restoring settings because intimacy bubble");
			
			AIAgentFunctions.setConf("_max_distance_inside",mdi,mdi,mdi);
			AIAgentFunctions.setConf("_max_distance_outside",mdo,mdo,mdo);
		endif;
		
		i = sortedActorList.Length
		while(i > 0)
            i -= 1
			Actor participant=actorList[i];
			if participant.GetDisplayName()!=Game.GetPlayer().GetDisplayName()
				AIAgentFunctions.setAnimationBusy(1,participant.GetDisplayName())
				AIAgentFunctions.setLocked(0,participant.GetDisplayName())
			endif
			
        endwhile
		
		AIAgentFunctions.logMessage("","force_current_task")
	
EndEvent


/;
