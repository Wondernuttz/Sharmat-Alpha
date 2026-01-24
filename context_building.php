<?php 

// Current historic context data here. $GLOBALS["CONTEXT_BUILDING_DATA"]
// This is called when build NPC context, we can filter here our custom eventypes

foreach ($GLOBALS["CONTEXT_BUILDING_DATA"] as $n => $line) {
    if ($line["role"] == "ext_nsfw_scene") {
        $GLOBALS["CONTEXT_BUILDING_DATA"][$n]["role"] = "user";

    } else  if ($line["role"] == "ext_nsfw_sexcene") {
        $GLOBALS["CONTEXT_BUILDING_DATA"][$n]["role"] = "user";

    } else  if ($line["role"] == "ext_nsfw_action") {
        $GLOBALS["CONTEXT_BUILDING_DATA"][$n]["role"] = "user";

    } else  if ($line["role"] == "ext_nsfw_physics") {
        // VR physics touch/grab events (HIGGS grab, CBPC touch)
        $GLOBALS["CONTEXT_BUILDING_DATA"][$n]["role"] = "user";

    } else  if ($line["role"] == "ext_nsfw_physics_blocked") {
        // Blocked touch attempts (chastity devices, armor)
        $GLOBALS["CONTEXT_BUILDING_DATA"][$n]["role"] = "user";

    } else  if ($line["role"] == "ext_vr_item_pickup" || $line["role"] == "ext_vr_item_drop") {
        // VR item events (HIGGS pickup/drop)
        $GLOBALS["CONTEXT_BUILDING_DATA"][$n]["role"] = "user";

    } else  if ($line["role"] == "ext_nsfw_npc_scene") {
        // NPC-to-NPC scenes (OStim NPCs)
        $GLOBALS["CONTEXT_BUILDING_DATA"][$n]["role"] = "user";

    } else  if ($line["role"] == "ext_nsfw_npc_invite") {
        // NPC-to-NPC invite phase (dom approaching sub)
        $GLOBALS["CONTEXT_BUILDING_DATA"][$n]["role"] = "user";

    } else  if ($line["role"] == "ext_nsfw_npc_orgasm") {
        // NPC orgasm in NPC-to-NPC scene
        $GLOBALS["CONTEXT_BUILDING_DATA"][$n]["role"] = "user";

    } else  if ($line["role"] == "chatnf_npc_sl") {
        // NPC-to-NPC scene speech
        $GLOBALS["CONTEXT_BUILDING_DATA"][$n]["role"] = "user";
    }
}
?>