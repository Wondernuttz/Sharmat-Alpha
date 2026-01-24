Scriptname AIAgentNSFWPlayerAlias extends ReferenceAlias
{
	Player alias script to detect game load events.
	OnPlayerLoadGame only fires on ReferenceAlias scripts filled with the player,
	NOT on Quest scripts directly.

	This script forwards the event to the main quest scripts.
}

; Reference to main NSFW quest script
AIAgentNSFW Property nsfwQuest Auto

; Reference to VR Items quest script (if separate)
AIAgentVRItems Property vrItemsQuest Auto

Event OnPlayerLoadGame()
	Debug.Trace("[CHIM-NSFW] PlayerAlias: OnPlayerLoadGame fired")
	Debug.Notification("[CHIM-NSFW] Game loaded")

	; Forward to main NSFW script
	if nsfwQuest
		Debug.Trace("[CHIM-NSFW] PlayerAlias: Calling nsfwQuest.DoRegister()")
		nsfwQuest.DoRegister()
	endif

	; Forward to VR Items script
	if vrItemsQuest
		Debug.Trace("[CHIM-NSFW] PlayerAlias: Calling vrItemsQuest.RegisterVREvents()")
		vrItemsQuest.RegisterVREvents()
	endif
EndEvent

Event OnInit()
	Debug.Trace("[CHIM-NSFW] PlayerAlias: OnInit - first install")
EndEvent
