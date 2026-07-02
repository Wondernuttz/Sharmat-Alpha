Scriptname AIAgentNSFWPlayerAlias extends ReferenceAlias
{
	Player alias script to detect game load events.
	OnPlayerLoadGame only fires on ReferenceAlias scripts filled with the player,
	NOT on Quest scripts directly.

	This script forwards the event to the main quest scripts.
}

; Reference to main NSFW quest script
AIAgentNSFW Property nsfwQuest Auto

bool poisonArmed = false
string poisonSourceName = ""
float poisonExpiresAt = 0.0
int poisonSuccessChance = 65
float poisonDurationSeconds = 120.0
float poisonRemainingSeconds = 0.0
float poisonTickSeconds = 5.0
int poisonMagnitude = 3
bool poisonNotifyPlayer = true
string poisonConsumeTypes = "food,drink,potion"

Function RegisterSharmatAliasEvents()
	UnRegisterForModEvent("SHARMAT_ArmSlavePoison")
	RegisterForModEvent("SHARMAT_ArmSlavePoison", "OnSharmatArmSlavePoison")
EndFunction

Event OnPlayerLoadGame()
	Debug.Trace("[CHIM-NSFW] PlayerAlias: OnPlayerLoadGame fired")
	Debug.Notification("SHARMAT loaded")
	RegisterSharmatAliasEvents()

	; Forward to main NSFW script
	if nsfwQuest
		Debug.Trace("[CHIM-NSFW] PlayerAlias: Calling nsfwQuest.DoRegister()")
		nsfwQuest.DoRegister()
	endif
EndEvent

Event OnInit()
	Debug.Trace("[CHIM-NSFW] PlayerAlias: OnInit - first install")
	RegisterSharmatAliasEvents()
EndEvent

Event OnSharmatArmSlavePoison(string eventName, string payload, float numArg, Form sender)
	String[] parts = StringUtil.Split(payload, "@")
	poisonSourceName = ""
	float expireHours = 24.0
	poisonSuccessChance = 65
	poisonDurationSeconds = 120.0
	poisonMagnitude = 3
	poisonNotifyPlayer = true
	poisonConsumeTypes = "food,drink,potion"

	if parts.Length > 0
		poisonSourceName = parts[0]
	endif
	if parts.Length > 1
		expireHours = parts[1] as float
	endif
	if parts.Length > 2
		poisonSuccessChance = parts[2] as int
	endif
	if parts.Length > 3
		poisonDurationSeconds = parts[3] as float
	endif
	if parts.Length > 4
		poisonMagnitude = parts[4] as int
	endif
	if parts.Length > 5
		poisonNotifyPlayer = (parts[5] as int) != 0
	endif
	if parts.Length > 6
		poisonConsumeTypes = parts[6]
	endif

	if expireHours <= 0.0
		expireHours = 24.0
	endif
	if poisonSuccessChance < 0
		poisonSuccessChance = 0
	elseif poisonSuccessChance > 100
		poisonSuccessChance = 100
	endif
	if poisonDurationSeconds < 5.0
		poisonDurationSeconds = 5.0
	endif
	if poisonMagnitude < 1
		poisonMagnitude = 1
	endif

	poisonArmed = true
	poisonExpiresAt = Utility.GetCurrentGameTime() + (expireHours / 24.0)
	Debug.Trace("[CHIM-NSFW] PlayerAlias: slave poison armed by " + poisonSourceName + " expires in " + expireHours + " game hours")
EndEvent

bool Function TypeListContains(string needle)
	string haystack = "," + poisonConsumeTypes + ","
	return StringUtil.Find(haystack, "," + needle + ",") != -1
EndFunction

bool Function IsEligiblePoisonConsumable(Form akBaseObject)
	if akBaseObject == None
		return false
	endif

	Potion potionItem = akBaseObject as Potion
	if potionItem != None
		if TypeListContains("potion")
			return true
		endif
		if TypeListContains("food") && potionItem.IsFood()
			return true
		endif
		; In Skyrim ALL drinkable alcohol (wine/mead/ale) is a Potion with IsFood()==false. The "drink"
		; keyword in poisonConsumeTypes had no branch here, so poisoning a slave's drink never fired.
		if TypeListContains("drink") && !potionItem.IsFood()
			return true
		endif
	endif

	Ingredient ingredientItem = akBaseObject as Ingredient
	if ingredientItem != None && TypeListContains("ingredient")
		return true
	endif

	return false
EndFunction

Event OnItemRemoved(Form akBaseItem, int aiItemCount, ObjectReference akItemReference, ObjectReference akDestContainer)
	; CONSUMED = the item left the inventory with NO destination (drops spawn a reference, stores/sales have a
	; container, VR HIGGS pulls spawn a reference). The only reliable "actually drank/ate it" signal in VR.
	if akBaseItem == None || akItemReference != None || akDestContainer != None
		return
	endif

	; WHISKEY DICK: report actual drinks; the server owns the alcohol regex + enable/male/chance gates.
	if (akBaseItem as Potion) != None
		AIAgentFunctions.logMessage("PLAYER_DRINK^" + akBaseItem.GetName(), "ext_nsfw_player_drink")
		Debug.Trace("[CHIM-NSFW] PlayerAlias: player CONSUMED '" + akBaseItem.GetName() + "' -> reported (ext_nsfw_player_drink)")
	endif

	; SLAVE POISON (moved from OnObjectEquipped, fix 2026-07-01): the equip event fires on VR item handling
	; WITHOUT consumption - an armed poison could fire from merely grabbing food. Consumption-verified now.
	if !poisonArmed
		return
	endif

	if Utility.GetCurrentGameTime() > poisonExpiresAt
		Debug.Trace("[CHIM-NSFW] PlayerAlias: armed slave poison expired before consumption")
		poisonArmed = false
		return
	endif

	if !IsEligiblePoisonConsumable(akBaseItem)
		return
	endif

	poisonArmed = false
	int roll = Utility.RandomInt(1, 100)
	if roll > poisonSuccessChance
		Debug.Trace("[CHIM-NSFW] PlayerAlias: slave poison failed roll " + roll + "/" + poisonSuccessChance)
		if poisonSourceName != ""
			AIAgentFunctions.logMessageForActor("command@ExtCmdPoisonMasterFood@" + akBaseItem.GetName() + "@poison_failed", "funcret", poisonSourceName)
		endif
		return
	endif

	poisonRemainingSeconds = poisonDurationSeconds
	if poisonNotifyPlayer
		Debug.Notification("You have been poisoned.")
	endif
	Debug.Trace("[CHIM-NSFW] PlayerAlias: slave poison fired from " + poisonSourceName + " using " + akBaseItem.GetName())
	if poisonSourceName != ""
		AIAgentFunctions.logMessageForActor("The Narrator: " + poisonSourceName + " secretly poisoned " + Game.GetPlayer().GetDisplayName() + ". They must lie like their life depends on it and act worried, helpful, or innocent unless exposed.", "ext_nsfw_slave_poison", poisonSourceName)
	endif
	RegisterForSingleUpdate(poisonTickSeconds)
EndEvent

; OnObjectEquipped REMOVED (fix 2026-07-01): both the drink report and the slave-poison trigger now live in
; OnItemRemoved with consumption verification - the equip event fires on VR item handling (HIGGS grabs,
; inventory moves) without consumption, causing phantom drinks and grab-triggered poison.

Event OnUpdate()
	if poisonRemainingSeconds <= 0.0
		return
	endif

	Game.GetPlayer().DamageActorValue("Health", poisonMagnitude)
	poisonRemainingSeconds -= poisonTickSeconds
	if poisonRemainingSeconds > 0.0
		RegisterForSingleUpdate(poisonTickSeconds)
	else
		Debug.Trace("[CHIM-NSFW] PlayerAlias: slave poison effect ended")
	endif
EndEvent
