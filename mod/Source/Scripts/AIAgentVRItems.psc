Scriptname AIAgentVRItems extends Quest
{
	==========================================================================
	    *** FUTURE: MOVE THIS SCRIPT TO CORE AIAGENT MOD ***
	==========================================================================

	VR ITEM AWARENESS FOR CHIM/HERIKA

	This script should be integrated into the main AIAgent/CHIM mod,
	NOT the NSFW extension. It provides general VR immersion that ALL
	users would benefit from, not just NSFW users.

	Currently housed in NSFW ext for development by borja (VR user).
	When ready for release, this should move to:
	  - AIAgent.esp (main mod)
	  - Or a dedicated AIAgentVR.esp for VR-specific features

	==========================================================================
	WHAT IT DOES:
	  - Detects when player picks up items in VR using HIGGS
	  - Detects when player drops/puts down items
	  - Sends events to backend so NPCs can "see" what player is holding
	  - Example: Pick up wine bottle -> NPC sees you holding wine

	DEPENDENCIES:
	  - HIGGS VR (required for VR grab detection)
	  - AIAgentFunctions (core CHIM Papyrus functions)

	BACKEND PROMPTS NEEDED:
	  - ext_vr_item_pickup  (when player picks up item)
	  - ext_vr_item_drop    (when player puts down item)

	XML TAG FORMAT:
	  <VR_ITEM>Player picked up Wine Bottle with their right hand</VR_ITEM>

	Author: CHIM Team (VR development by borja)
	==========================================================================
}
import Utility

; Configuration
bool Property enableVRItemAwareness = true Auto
float Property itemEventCooldown = 1.0 Auto  ; Cooldown between item events

; HIGGS integration
bool hasHIGGS = false

; Tracking
Actor playerRef
string leftHandItem = ""
string rightHandItem = ""
float lastItemEventTime = 0.0

; Debouncing
string lastReportedAction = ""
string lastReportedItem = ""
float lastReportedTime = 0.0

; ============================================
; INITIALIZATION
; ============================================
Event OnInit()
	Debug.Trace("[CHIM-VR] AIAgentVR OnInit - first install")
	playerRef = Game.GetPlayer()
	RegisterVREvents()
EndEvent

Function RegisterVREvents()
	hasHIGGS = false
	leftHandItem = ""
	rightHandItem = ""

	if !enableVRItemAwareness
		Debug.Trace("[CHIM-VR] VR Item Awareness disabled by config")
		return
	endif

	; Check if HIGGS is loaded
	if (Game.GetModByName("higgs_vr.esp") == 255)
		Debug.Trace("[CHIM-VR] HIGGS not installed - VR item awareness unavailable")
		return
	endif

	; Register for HIGGS events
	HiggsVR.RegisterForGrabEvent(self)
	HiggsVR.RegisterForDropEvent(self)
	hasHIGGS = true
	Debug.Trace("[CHIM-VR] HIGGS integration enabled for item awareness")
EndFunction

; ============================================
; HIGGS ITEM EVENTS
; ============================================

; Fires when player grabs ANY object (we filter out actors)
Event OnObjectGrabbed(ObjectReference refr, bool isLeft)
	if !hasHIGGS || !enableVRItemAwareness
		return
	endif

	; Skip if this is an actor (handled by AIAgentNSFW)
	if refr as Actor
		return
	endif

	; Get item info
	string itemName = refr.GetDisplayName()
	if itemName == ""
		Form baseForm = refr.GetBaseObject()
		if baseForm
			itemName = baseForm.GetName()
		endif
	endif

	if itemName == ""
		itemName = "something"
	endif

	; Update hand tracking
	string handName = "right hand"
	if isLeft
		handName = "left hand"
		leftHandItem = itemName
	else
		rightHandItem = itemName
	endif

	Debug.Trace("[CHIM-VR] Item grabbed: " + itemName + " with " + handName)

	; Debounce check
	float currentTime = Utility.GetCurrentRealTime()
	if currentTime - lastItemEventTime < itemEventCooldown
		return
	endif

	; Check if this is a duplicate event
	string action = "picked up"
	if action == lastReportedAction && itemName == lastReportedItem
		if currentTime - lastReportedTime < (itemEventCooldown * 2.0)
			return
		endif
	endif

	; Send RAW data to PHP - let PHP handle message formatting
	; Format: itemname^action^hand (using ^ to avoid CHIM pipe conflict)
	string handSide = "right"
	if isLeft
		handSide = "left"
	endif
	string rawData = itemName + "^pickup^" + handSide
	Debug.Trace("[CHIM-VR] Raw: " + rawData)
	AIAgentFunctions.logMessage(rawData, "ext_vr_item_raw")

	; Update debounce tracking
	lastItemEventTime = currentTime
	lastReportedAction = "pickup"
	lastReportedItem = itemName
	lastReportedTime = currentTime
EndEvent

; Fires when player drops ANY object
Event OnObjectDropped(ObjectReference refr, bool isLeft)
	if !hasHIGGS || !enableVRItemAwareness
		return
	endif

	; Skip if this is an actor (handled by AIAgentNSFW)
	if refr as Actor
		return
	endif

	; Get item info
	string itemName = refr.GetDisplayName()
	if itemName == ""
		Form baseForm = refr.GetBaseObject()
		if baseForm
			itemName = baseForm.GetName()
		endif
	endif

	if itemName == ""
		itemName = "something"
	endif

	; Update hand tracking
	string handName = "right hand"
	if isLeft
		handName = "left hand"
		leftHandItem = ""
	else
		rightHandItem = ""
	endif

	Debug.Trace("[CHIM-VR] Item dropped: " + itemName + " from " + handName)

	; Debounce check
	float currentTime = Utility.GetCurrentRealTime()
	if currentTime - lastItemEventTime < itemEventCooldown
		return
	endif

	; Check if this is a duplicate event
	string action = "dropped"
	if action == lastReportedAction && itemName == lastReportedItem
		if currentTime - lastReportedTime < (itemEventCooldown * 2.0)
			return
		endif
	endif

	; Send RAW data to PHP - let PHP handle message formatting
	; Format: itemname^action^hand (using ^ to avoid CHIM pipe conflict)
	string handSide = "right"
	if isLeft
		handSide = "left"
	endif
	string rawData = itemName + "^drop^" + handSide
	Debug.Trace("[CHIM-VR] Raw: " + rawData)
	AIAgentFunctions.logMessage(rawData, "ext_vr_item_raw")

	; Update debounce tracking
	lastItemEventTime = currentTime
	lastReportedAction = "drop"
	lastReportedItem = itemName
	lastReportedTime = currentTime
EndEvent

; ============================================
; UTILITY FUNCTIONS
; ============================================

; Get what's currently in player's hands (for context queries)
string Function GetLeftHandItem()
	return leftHandItem
EndFunction

string Function GetRightHandItem()
	return rightHandItem
EndFunction

; Check if player is holding anything
bool Function IsHoldingAnything()
	return leftHandItem != "" || rightHandItem != ""
EndFunction

; Get formatted string of what player is holding
string Function GetHandsDescription()
	if leftHandItem != "" && rightHandItem != ""
		return "holding " + leftHandItem + " in left hand and " + rightHandItem + " in right hand"
	elseif leftHandItem != ""
		return "holding " + leftHandItem + " in left hand"
	elseif rightHandItem != ""
		return "holding " + rightHandItem + " in right hand"
	else
		return "hands empty"
	endif
EndFunction
