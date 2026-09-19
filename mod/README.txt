SHARMAT GAME MOD (Papyrus scripts + plugin)
===========================================

This folder is the SHARMAT Skyrim mod itself:

  AIAgentNSFW.esp
  Scripts\          (compiled .pex files)
  Seq\
  Source\Scripts\   (source .psc, for reference)

HOW TO INSTALL / UPDATE
-----------------------
1. Download this whole "mod" folder from GitHub
   (Code -> Download ZIP, or grab just this folder).
2. Copy its CONTENTS into your SHARMAT mod in MO2 / Vortex
   (the mod that contains AIAgentNSFW.esp), overwriting when asked.
3. Load a save. Script changes take effect after a save load.
   You do NOT need a new game.

WHEN DO I NEED THIS?
--------------------
Whenever a fix or feature says "needs a mod re-download", "new pex",
or "Papyrus update". The Update button on the Sharmat web page only
updates the SERVER side - it deliberately never touches these game
files, so mod updates are always a manual download of this folder.

If a fix says "Update button is enough", you do not need anything
from here.

SCENE FRAMEWORK TOGGLE
----------------------
SHARMAT can listen to OStim, SexLab, or both. The web Settings page has
"Detect OStim Scenes" / "Detect SexLab Scenes" (both on by default).

Papyrus cannot read those server checkboxes. In-game hook registration
uses Data/SKSE/Plugins/StorageUtilData/SHARMAT_scene_framework.json:

  { "ostim": 1, "sexlab": 1 }

Set a value to 0 to skip that framework's hooks (reload a save after).
Missing file = both on. The JSON is independent of the web checkboxes.
