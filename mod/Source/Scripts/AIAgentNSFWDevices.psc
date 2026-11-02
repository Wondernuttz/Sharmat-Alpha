Scriptname AIAgentNSFWDevices Hidden
; Optional DD types live here so the shared quest loads without Devious Devices.
zadLibs Function Library() Global
    if Game.GetModByName("Devious Devices - Integration.esm") == 255
        return None
    endif
    return Game.GetFormFromFile(0x00F624, "Devious Devices - Integration.esm") as zadLibs
EndFunction

bool Function ActionsAvailable() Global
    zadLibs dd = Library()
    return dd && dd.GetVersion() >= 14
EndFunction

string[] Function Categories() Global
    return StringUtil.Split("belt,bra,gag,collar,armbinder,yoke,elbowtie,straitjacket,blindfold,hobbleskirt,armcuffs,legcuffs,ankleshackles,plugvaginal,pluganal,clamps,corset,hood,harness,gloves,suit,piercingsnipple,piercingsvaginal", ",")
EndFunction

Keyword Function DeviceKeyword(string category) Global
    zadLibs dd = Library()
    if !dd
        return None
    endif
    if category == "belt"
        return dd.zad_DeviousBelt
    endif
    if category == "bra"
        return dd.zad_DeviousBra
    endif
    if category == "gag"
        return dd.zad_DeviousGag
    endif
    if category == "collar"
        return dd.zad_DeviousCollar
    endif
    if category == "armbinder"
        return dd.zad_DeviousArmbinder
    endif
    if category == "yoke"
        return dd.zad_DeviousYoke
    endif
    if category == "elbowtie"
        return dd.zad_DeviousElbowTie
    endif
    if category == "straitjacket"
        return dd.zad_DeviousStraitJacket
    endif
    if category == "blindfold"
        return dd.zad_DeviousBlindfold
    endif
    if category == "hobbleskirt"
        return dd.zad_DeviousHobbleSkirt
    endif
    if category == "armcuffs"
        return dd.zad_DeviousArmCuffs
    endif
    if category == "legcuffs"
        return dd.zad_DeviousLegCuffs
    endif
    if category == "ankleshackles"
        return dd.zad_DeviousAnkleShackles
    endif
    if category == "plugvaginal"
        return dd.zad_DeviousPlugVaginal
    endif
    if category == "pluganal"
        return dd.zad_DeviousPlugAnal
    endif
    if category == "clamps"
        return dd.zad_DeviousClamps
    endif
    if category == "corset"
        return dd.zad_DeviousCorset
    endif
    if category == "hood"
        return dd.zad_DeviousHood
    endif
    if category == "harness"
        return dd.zad_DeviousHarness
    endif
    if category == "gloves"
        return dd.zad_DeviousGloves
    endif
    if category == "suit"
        return dd.zad_DeviousSuit
    endif
    if category == "piercingsnipple"
        return dd.zad_DeviousPiercingsNipple
    endif
    if category == "piercingsvaginal"
        return dd.zad_DeviousPiercingsVaginal
    endif
    return None
EndFunction

string Function WornCSV(Actor akActor) Global
    zadLibs libs = Library()
	if (!akActor || !libs)
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

bool Function WearsCategory(Actor wearer, string category) Global
    Keyword kw = DeviceKeyword(category)
    return wearer && kw && wearer.WornHasKeyword(kw)
EndFunction

bool Function ProtectedDevice(Armor inventoryDevice, Armor renderedDevice) Global
    zadLibs dd = Library()
    if !dd || !inventoryDevice || !renderedDevice
        return true
    endif
    return inventoryDevice.HasKeyword(dd.zad_QuestItem) || inventoryDevice.HasKeyword(dd.zad_BlockGeneric) || renderedDevice.HasKeyword(dd.zad_QuestItem) || renderedDevice.HasKeyword(dd.zad_BlockGeneric)
EndFunction

Armor Function InventoryDevice(Actor wearer, string category) Global
    zadLibs dd = Library()
    Keyword kw = DeviceKeyword(category)
    if !dd || !wearer || !kw
        return None
    endif
    Armor candidate = None
    int i = wearer.GetNumItems()
    while i > 0
        i -= 1
        Armor item = wearer.GetNthForm(i) as Armor
        if item && item.HasKeyword(dd.zad_InventoryDevice) && dd.GetDeviceKeyword(item) == kw
            if !ProtectedDevice(item, dd.GetRenderedDevice(item))
                if candidate && candidate != item
                    return None
                endif
                candidate = item
            endif
        endif
    endwhile
    return candidate
EndFunction

; Standard generic devices only. Timed, breakable and scripted special locks stay in DD's UI.
bool Function SimpleLock(Armor item) Global
    return zadNativeFunctions.GetPropertyFloat(item, "LockShieldTimerMin") <= 0.0 && zadNativeFunctions.GetPropertyFloat(item, "LockShieldTimerMax") <= 0.0 && zadNativeFunctions.GetPropertyFloat(item, "KeyBreakChance") <= 0.0 && !zadNativeFunctions.GetPropertyBool(item, "TimedUnlock")
EndFunction

bool Function KeywordConditions(Actor wearer, Armor item, bool locking) Global
    Form[] conflicts
    Form[] required
    if locking
        conflicts = zadNativeFunctions.GetPropertyFormArray(item, "EquipConflictingDevices")
        required = zadNativeFunctions.GetPropertyFormArray(item, "EquipRequiredDevices")
    else
        conflicts = zadNativeFunctions.GetPropertyFormArray(item, "UnEquipConflictingDevices")
    endif
    int i = 0
    if conflicts
        i = conflicts.Length
    endif
    while i > 0
        i -= 1
        Keyword kw = conflicts[i] as Keyword
        if kw && wearer.WornHasKeyword(kw)
            return false
        endif
    endwhile
    i = 0
    if required
        i = required.Length
    endif
    while i > 0
        i -= 1
        Keyword kw = required[i] as Keyword
        if kw && !wearer.WornHasKeyword(kw)
            return false
        endif
    endwhile
    return true
EndFunction

bool Function SlotConflict(Actor wearer, Armor rendered) Global
    zadLibs dd = Library()
    int slot = 0
    while slot < 32
        int mask = Math.LeftShift(1, slot)
        if Math.LogicalAnd(rendered.GetSlotMask(), mask) != 0
            Armor occupied = wearer.GetWornForm(mask) as Armor
            if occupied && (occupied.HasKeyword(dd.zad_Lockable) || occupied.HasKeyword(dd.zad_DeviousPlug))
                return true
            endif
        endif
        slot += 1
    endwhile
    return false
EndFunction

bool Function AccessibleDevice(Actor wearer, Armor rendered) Global
    zadLibs dd = Library()
    if wearer.WornHasKeyword(dd.zad_DeviousBelt)
        if rendered.HasKeyword(dd.zad_DeviousPlugVaginal) || (rendered.HasKeyword(dd.zad_DeviousPlugAnal) && !wearer.WornHasKeyword(dd.zad_PermitAnal))
            return false
        endif
    endif
    if wearer.WornHasKeyword(dd.zad_DeviousBra) && rendered.HasKeyword(dd.zad_DeviousPiercingsNipple) && !rendered.HasKeyword(dd.zad_BraNoBlockPiercings)
        return false
    endif
    return true
EndFunction

bool Function InScene(Actor actorRef) Global
    if AIAgentNSFWSceneEngine.HasOStim() && OActor.IsInOStim(actorRef)
        return true
    endif
    if AIAgentNSFWSceneEngine.HasSexLab() && SexLabUtil.IsActorActive(actorRef)
        return true
    endif
    return false
EndFunction

string Function Choices(Actor wearer, bool locking) Global
    if !ActionsAvailable() || !wearer
        return ""
    endif
    zadLibs dd = Library()
    string[] types = Categories()
    Keyword[] keywords = new Keyword[23]
    Armor[] candidates = new Armor[23]
    bool[] ambiguous = new bool[23]
    int k = 0
    while k < types.Length
        keywords[k] = DeviceKeyword(types[k])
        k += 1
    endwhile
    ; Scan inventory once per snapshot, not once for each of the 23 categories.
    if locking
        int n = wearer.GetNumItems()
        while n > 0
            n -= 1
            Armor inventoryItem = wearer.GetNthForm(n) as Armor
            if inventoryItem && inventoryItem.HasKeyword(dd.zad_InventoryDevice)
                Keyword itemKeyword = dd.GetDeviceKeyword(inventoryItem)
                k = 0
                while k < types.Length
                    if keywords[k] == itemKeyword && !ProtectedDevice(inventoryItem, dd.GetRenderedDevice(inventoryItem))
                        if candidates[k] && candidates[k] != inventoryItem
                            ambiguous[k] = true
                        endif
                        candidates[k] = inventoryItem
                    endif
                    k += 1
                endwhile
            endif
        endwhile
    endif
    string result = ""
    int i = 0
    while i < types.Length
        Keyword kw = keywords[i]
        Armor item
        Armor rendered = dd.GetWornRenderedDeviceByKeyword(wearer, kw)
        if locking && !rendered && !wearer.WornHasKeyword(kw)
            item = candidates[i]
            if item && !ambiguous[i] && AccessibleDevice(wearer, dd.GetRenderedDevice(item)) && KeywordConditions(wearer, item, true) && !SlotConflict(wearer, dd.GetRenderedDevice(item))
                result += types[i] + ","
            endif
        elseif !locking && rendered
            item = dd.GetWornDevice(wearer, kw)
            if item && !ProtectedDevice(item, rendered) && AccessibleDevice(wearer, rendered) && SimpleLock(item) && KeywordConditions(wearer, item, false)
                result += types[i] + ","
            endif
        endif
        i += 1
    endwhile
    return result
EndFunction

string Function Execute(Actor operator, Actor wearer, string category, bool locking) Global
    if !ActionsAvailable()
        return "error: device actions require Devious Devices 5.2 NG APIs"
    endif
    if !operator || !wearer || operator == wearer
        return "error: target is unavailable"
    endif
    if AIAgentNSFWSceneEngine.IsProtectedMinorActor(operator) || AIAgentNSFWSceneEngine.IsProtectedMinorActor(wearer)
        return "error: protected actor"
    endif
    if !operator.Is3DLoaded() || !wearer.Is3DLoaded() || operator.GetDistance(wearer) > 512.0 || operator.IsDead() || wearer.IsDead() || operator.IsInCombat() || wearer.IsInCombat()
        return "error: actors must be nearby, alive and out of combat"
    endif
    if InScene(operator) || InScene(wearer)
        return "error: finish the active scene before changing devices"
    endif
    zadLibs dd = Library()
    if operator.WornHasKeyword(dd.zad_DeviousHeavyBondage) || operator.WornHasKeyword(dd.zad_DeviousBondageMittens)
        return "error: your restraints prevent handling the device"
    endif
    Keyword kw = DeviceKeyword(category)
    if !kw
        return "error: unknown device category"
    endif
    Armor item
    Armor rendered
    Actor keyHolder
    Key deviceKey
    int keyCount = 1
    bool consumeKey = false
    if locking
        if wearer.WornHasKeyword(kw) || dd.GetWornRenderedDeviceByKeyword(wearer, kw)
            return "error: a device of this category is already worn"
        endif
        item = InventoryDevice(wearer, category)
        if !item || wearer.GetItemCount(item) <= 0
            return "error: put exactly one device variant of this category in the wearer inventory"
        endif
        rendered = dd.GetRenderedDevice(item)
        if ProtectedDevice(item, rendered) || !AccessibleDevice(wearer, rendered) || SlotConflict(wearer, rendered) || !KeywordConditions(wearer, item, true)
            return "error: protected or conflicting device"
        endif
    else
        item = dd.GetWornDevice(wearer, kw)
        rendered = dd.GetWornRenderedDeviceByKeyword(wearer, kw)
        if ProtectedDevice(item, rendered) || !AccessibleDevice(wearer, rendered) || !SimpleLock(item) || !KeywordConditions(wearer, item, false)
            return "error: special, protected or conflicting device; use its mod controls"
        endif
        if wearer == Game.GetPlayer() && dd.IsLockJammed(wearer, kw) != 0
            return "error: lock is jammed or its state is unknown"
        endif
        deviceKey = dd.GetDeviceKey(item)
        keyCount = zadNativeFunctions.GetPropertyInt(item, "NumberOfKeysNeeded", 1)
        if !deviceKey || keyCount < 1
            return "error: this device has no supported key"
        endif
        if operator.GetItemCount(deviceKey) >= keyCount
            keyHolder = operator
        elseif wearer.GetItemCount(deviceKey) >= keyCount
            keyHolder = wearer
        else
            return "error: neither actor holds enough matching keys"
        endif
        consumeKey = zadNativeFunctions.GetPropertyBool(item, "DestroyKey") || (dd.Config.GlobalDestroyKey && deviceKey.HasKeyword(dd.zad_NonUniqueKey))
    endif
    bool accepted
    if locking
        accepted = dd.LockDevice(wearer, item, false)
    else
        accepted = dd.UnlockDevice(wearer, item, rendered, kw, false, true)
    endif
    if !accepted
        return "error: Devious Devices rejected the action"
    endif
    int tries = 0
    while tries < 60
        bool worn = wearer.IsEquipped(rendered)
        if (locking && worn) || (!locking && !worn && !wearer.IsEquipped(item) && !wearer.WornHasKeyword(kw))
            if !locking && consumeKey
                keyHolder.RemoveItem(deviceKey, keyCount, true)
            endif
            if locking
                return "success: device equipped and verified"
            endif
            return "success: device removed and verified"
        endif
        Utility.Wait(0.25)
        tries += 1
    endwhile
    return "pending: equipment change was not verified; do not retry until the device state updates"
EndFunction
