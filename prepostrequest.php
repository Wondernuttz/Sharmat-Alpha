<?php 

// Postrequest tasks.
// Generate climax sentence if not generated.

if ($gameRequest[0]=="chatnf_sl"||$gameRequest[0]=="chatnf_sl_climax") {    

   
    // Check if already generated
    $actor=$GLOBALS["HERIKA_NAME"];
    $intimacyStatus=getIntimacyForActor($actor);
    if (!isset($intimacyStatus["orgasm_generated"]) || $intimacyStatus["orgasm_generated"]==false) {
        generateClimaxSpeech();
        
    } else {
        error_log("Orgams sound already generated");

    }

   

} else {
    error_log(print_r($gameRequest,true));

    return;
}


?>