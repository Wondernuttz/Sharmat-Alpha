# SHARMAT Alpha - Skyrim mod download

**[Download the complete SHARMAT 3.1.0 ZIP](https://github.com/Wondernuttz/Sharmat-Alpha/releases/download/v3.1.0/SHARMAT-3.1.0.zip)**

Install the ZIP in MO2 or Vortex over your existing SHARMAT mod. Keep
`AIAgentNSFW.esp` enabled. Update the server through SHARMAT's Info page as well.
The release ZIP includes the matching embedded server package for CHIM versions
that support it. Individual files in this source folder are not the installer.

For drunk animations, separately install [Open Animation Replacer](https://www.nexusmods.com/skyrimspecialedition/mods/92109)
and the standard **Drunk animations 1.6.1** main file from
[Drunk or drugged animations OAR](https://www.nexusmods.com/skyrimspecialedition/mods/62191).
Let SHARMAT win the animation pack's `config.json` conflict. Custom OAR overrides
need the SHARMAT condition merged into them. No animation assets are bundled.

Version 3.1.0 adds CHIM action confirmations and fixes scene IDs, actor
placeholders, imports, repeat migrations, intensity detection and prompt defaults.
NPC autonomy prompts now name only eligible partners who are present.

In CHIM's Actions editor, each SHARMAT game action has a **Require Confirmation**
checkbox on CHIM versions with action-confirmation support. Affection and scene
starts ask by default; existing explicit choices are preserved. Allow runs the
action and Cancel discards it. This uses CHIM's existing game-side confirmation
UI, so the CHIM server and game plugin must both support confirmation commands.

These fixes are server-side. The complete ZIP retains all six game scripts from
3.1.9.10 and includes the matching 3.1.0 embedded server package. Existing
3.1.9.10 game installations only need the server update for these fixes.
The Plugin Page entry point and complete-download fixes are retained.
Ensure SHARMAT wins conflicts for `Scripts/AIAgentNSFW*.pex` in your mod manager.

The included game scripts isolate optional framework dependencies so missing SexLab scripts
cannot disable OStim event registration, and the shared scripts do not require
OStim-specific types on SexLab-only setups. Both frameworks remain supported
together. The full ZIP contains these game scripts and the matching server package.

Restart Skyrim and load a save after installing. No new save, quest reset, or
animation-cache deletion is needed. Preserve custom scene-framework settings.

3.1.0 renumbers the 3.1.9.11 release and retains its fixes. Existing 3.1.9.11
installations already have these gameplay changes. Update Live or SHARMAT's
Info-page updater can install this version even when an older Plugin Manager
does not show an update badge for the lower number.
