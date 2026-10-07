SHARMAT 3.1.9.4 - Female/Female Scene Update

INSTALL / UPDATE
1. Fully exit Skyrim.
2. Install the complete release ZIP into your existing SHARMAT mod in MO2 or Vortex.
3. Keep AIAgentNSFW.esp enabled. Preserve any custom values in
   SKSE/Plugins/StorageUtilData/SHARMAT_scene_framework.json.
4. Restart Skyrim and load a save.

Requires CHIM and its normal dependencies, plus the enabled OStim or SexLab
framework and suitable installed animations. Those frameworks and animation
packs are separate downloads.

This update selects an available female/female sexual scene before the generic
fallback for a female player and female NPC. It covers OStim starts and act
changes and SexLab starts. Requested matching scenes retain priority.
Affection/service requests and furniture constraints remain separate.

The Sharmat web updater updates the SERVER component. After updating, use
Download Game Mod on the Info page to obtain the matching game files.
The web updater alone does not replace the Papyrus scripts in your game.

Release ZIPs also include a matching server component under CHIM/server-plugins.
CHIM versions supporting embedded server packages install it on save load.

SCENE FRAMEWORK SETTINGS
SKSE/Plugins/StorageUtilData/SHARMAT_scene_framework.json defaults to:
  { "ostim": 1, "sexlab": 1 }
Set a value to 0 to disable that framework's game-side hooks, then reload a save.
These settings are independent of the web Detect OStim / Detect SexLab options.
