SHARMAT 3.1.1 - Feature Update: Devious Devices Integration

UPDATE BOTH THE SHARMAT SERVER AND GAME MOD.
The new device actions need the updated game scripts; a server-only update is not enough.

INSTALL / UPDATE
1. Exit Skyrim, then install this complete ZIP into your existing SHARMAT mod in MO2 or Vortex.
2. Ensure it wins conflicts for Scripts/AIAgentNSFW*.pex. Keep AIAgentNSFW.esp enabled.
3. Preserve your settings in SKSE/Plugins/StorageUtilData/SHARMAT_scene_framework.json.
4. Update the matching SHARMAT server extension. This ZIP includes its embedded installer package.
5. Restart Skyrim and load your save. No new save or quest reset is needed.

Requires CHIM, its normal dependencies, and the OStim or SexLab framework you use.
Frameworks, animations and Devious Devices are separate downloads.

OPTIONAL DEVIOUS DEVICES ACTIONS
Enable awareness and lock/unlock under SHARMAT Prompts > Devices & Wearables.
Device actions require DD 5.2 NG APIs. CHIM's Actions editor controls LockDevice
and UnlockDevice, including its existing Require Confirmation checkbox.
Allow performs the action; Cancel discards it. Both CHIM server and game plugin
must support confirmation commands. Device actions are disabled by default.

Put one device variant of the requested category in the wearer's inventory.
Unlocking needs the correct number of matching keys, held by the acting NPC or
the wearer. Locking respects the target relationship; helping remove a device
does not require romance. Existing speaking styles and role settings are retained.

Quest devices, special locks, conflicting equipment and active scenes are not
overridden. An unverified operation reports pending, not success. If that happens,
check the device state in DD before repeating an action. Binding automation and
Leash Framework are outside this integration. DD is optional; existing scene
framework routing, animations and VR touch handling remain in place.
