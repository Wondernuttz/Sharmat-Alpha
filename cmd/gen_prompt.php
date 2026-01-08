<?php
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'POST') {
    $jsonDataInput = $_POST;
    
    try {
        // Common Includes
        $enginePath = realpath(__DIR__ . DIRECTORY_SEPARATOR . ".." . DIRECTORY_SEPARATOR . ".." . DIRECTORY_SEPARATOR . ".." . DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        require_once $enginePath . "conf" . DIRECTORY_SEPARATOR . "conf.php";
        require_once $enginePath . "lib" . DIRECTORY_SEPARATOR . "model_dynmodel.php";
        require_once $enginePath . "lib" . DIRECTORY_SEPARATOR . "{$GLOBALS["DBDRIVER"]}.class.php";
        require_once $enginePath . "lib" . DIRECTORY_SEPARATOR . "chat_helper_functions.php";
        require_once $enginePath . "lib" . DIRECTORY_SEPARATOR . "data_functions.php";
        require_once $enginePath . "lib" . DIRECTORY_SEPARATOR . "logger.php";
        require_once $enginePath . "lib" . DIRECTORY_SEPARATOR . "utils_game_timestamp.php";
        require_once $enginePath . "lib" . DIRECTORY_SEPARATOR . "rolemaster_helpers.php";
        require_once $enginePath . "lib" . DIRECTORY_SEPARATOR . "dynamic_update_util.php";
        $GLOBALS["ENGINE_PATH"] = $enginePath;
        
        error_log("GEN PROMPT] $enginePath " . print_r($jsonDataInput, true));
        
        // Global DB object
        $db = new sql();

        require_once $enginePath . "lib/core/npc_master.class.php";
        require_once $enginePath . "lib/core/api_badge.class.php";
        require_once $enginePath . "lib/core/core_profiles.class.php";
        require_once $enginePath . "lib/core/llm_connector.class.php";
        require_once $enginePath . "lib/core/tts_connector.class.php";

        require_once $enginePath . "lib" . DIRECTORY_SEPARATOR . "lazy_xml.php";

        // Global DB object
        $db            = new sql();
        $GLOBALS["db"] = $db;

        // Validate input parameters
        if (empty($jsonDataInput["npc_id"])) {
            throw new Exception("NPC ID is required");
        }
        if (empty($jsonDataInput["connector_id"])) {
            throw new Exception("Connector ID is required");
        }
        if (empty($jsonDataInput["field_type"])) {
            throw new Exception("Field type is required");
        }
        if (empty($jsonDataInput["profanity_level"])) {
            throw new Exception("Profanity level is required");
        }

        // Validate profanity level value
        $validProfanityLevels = [0,1,2,3];
        if (!in_array($jsonDataInput["profanity_level"], $validProfanityLevels)) {
            throw new Exception("Invalid profanity level");
        }

        $npcMaster = new NpcMaster();

        $connector            = new LLMConnector();
        $currentConnectorData = $connector->getById($jsonDataInput["connector_id"]);
        
        if (!$currentConnectorData) {
            throw new Exception("Connector not found");
        }
        
        $currentNpcData = $npcMaster->getById($jsonDataInput["npc_id"]);
        
        if (!$currentNpcData) {
            throw new Exception("NPC not found");
        }

        $profile            = new CoreProfile();
        $currentProfileData = $profile->getById($currentNpcData["profile_id"]);
        $connector->setOldGlobals($currentConnectorData);
        $npcMaster->setOldGlobalsFromCurrentNpcData($currentNpcData);

        $extended = $npcMaster->getExtendedData($currentNpcData);
        $metadata = $npcMaster->getMetadata($currentNpcData);
        $metadataProfile= $profile->getMetadata($currentProfileData);

        $meta=array_merge($metadata,$metadataProfile);

        $settingsRow = $GLOBALS["db"]->fetchOne("SELECT value FROM conf_opts WHERE id = 'aiagent_nsfw_settings'");
        $parsedSettings = json_decode($settingsRow['value'], true);


        if (isset($meta["CORE_LANG"]) && $meta["CORE_LANG"] == "es") {
           $LANG = "es";
        } else {
           $LANG = "en";
        }
        $dynamicBiography = buildDynamicBiography($GLOBALS);

        $prompt[$LANG] = [];
        $prompt[$LANG][] = ['role' => 'user', 'system' => "You're NSFW writer. You like to use this kind of words: {$parsedSettings["GENERIC_GLOSSARY"]}"];
        $prompt[$LANG][] = ['role' => 'user', 'content' => "<character_sheet>\n{$GLOBALS["HERIKA_NAME"]}:\n$dynamicBiography\n</character_sheet>"];
        
        
        // Get profanity level description
        $profanityDescriptions["en"] = [
            "0" => "The language should be mild and suggestive, avoiding explicit terms.",
            "1" => "Use moderately explicit language, balanced between suggestive and direct.",
            "2" => "Use explicit and direct sexual language without restraint.",
            "3" => "Use the most explicit, intense, and graphic sexual language possible."
        ];
        
        $profanityDescriptions["es"] = [
            "0" => "Usa un lenguaje sugestivo",
            "1" => "Usa un lenguaje moderamente sucio",
            "2" => "Usa un lenguaje sucio, explicito, con palabrotas.",
            "3" => "Usa un lenguaje sucio, explicito, con palabrotas, totalmente degenerado."
        ];

         // Get profanity level description
        $profanityDescriptionsPrompt["en"] = [
            "0" => "",
            "1" => "Generate an average sexual behaviour. (e.g., BDSM, role-playing, voyeurism, foot fetish, exhibitionism)",
            "2" => "Generate a hard sexual behaviour. Something that most people should find disgusting",
            "3" => "Generate a depravate sexual behaviour. Something that most people should find disgusting and very very naughty, even if contradicts character personality. Sex change poeple dude!."
        ];
        
        $profanityDescriptionsPrompt["es"] = [
            "0" => "",
            "1" => "Genera un comportamiento sexual adecuado al personaje",
            "2" => "Genera un comportamiento sexual duro, algo que la mayoria de la gente encontraria vergonzoso. ",
            "3" => "Genera un comportamiento sexual duro, algo que la mayoria de la gente encontraria vergonzoso y asqueroso, incluso si va en contrar de la personalidad del personaje. El sexo cambia a legente tio!."
        ];

        $profanityDesc = $profanityDescriptions[$LANG][$jsonDataInput["profanity_level"]];
        $profanityDescriptionsPrompt = $profanityDescriptionsPrompt[$LANG][$jsonDataInput["profanity_level"]];
  
        // Different prompts based on field type
        if ($jsonDataInput["field_type"] === "sex_prompt") {
            $prompt["en"][] = ['role' => 'user', 'content' => "Create a detailed and explicit sex behavior prompt for the character {$GLOBALS["HERIKA_NAME"]}.
    $profanityDescriptionsPrompt
     This prompt should comprehensively describe the character's sexual behavior, including a variety of fetishes, kinks, and preferences. Specifically, include the following elements:
Sexual Orientation and Identity: Detail the character's sexual orientation (e.g., heterosexual, bisexual, pansexual), gender preferences, and any fluid or evolving aspects of their identity.
Fetishes and Kinks: List and describe at least 1 specific fetishes or kinks (e.g., BDSM, role-playing, voyeurism, foot fetish, exhibitionism), explaining how they manifest in the character's behavior. Ensure these are varied and aligned with PEGI-18 content.
Overall Tone: Keep the description explicit and immersive for NSFW storytelling."];
            $prompt["en"][]=['role' => 'user', 'content' =>'Output format: plain text prompt, starting directly with Sexual Orientation'];

            $prompt["es"][] = ['role' => 'user', 'content' => "Crea un prompt detallado y explícito de comportamiento sexual para el personaje {$GLOBALS["HERIKA_NAME"]}.
    $profanityDescriptionsPrompt
Este prompt debe describir de manera exhaustiva el comportamiento sexual del personaje, incluyendo una variedad de fetiches, kinks y preferencias. Específicamente, incluye los siguientes elementos:
Orientación e Identidad Sexual: Detalla la orientación sexual del personaje (por ejemplo, heterosexual, bisexual, pansexual), preferencias de género y cualquier aspecto fluido o en evolución de su identidad.
Fetiches y manias: Lista y describe al menos 1 fetiche o kink específico (por ejemplo, BDSM, role-playing, voyeurism, foot fetish, exhibitionism), explicando cómo se manifiestan en el comportamiento del personaje. Asegúrate de que sean variados y alineados con el contenido PEGI-18.
Tono General: Mantén la descripción explícita e inmersiva para la narración NSFW,"];
        $prompt["es"][]=['role' => 'user', 'content' =>'Formato de salida: prompt en texto plano, empezando directamenete por Orientación e Identidad Sexual'];


        } elseif ($jsonDataInput["field_type"] === "sex_speech_style") {
            $prompt["en"][] = ['role' => 'user', 'content' => "Create a detailed description of the character {$GLOBALS["HERIKA_NAME"]}'s sexual speech style and dirty talk patterns.
Priority: $profanityDesc
Include:
Speech Patterns: Describe how the character speaks during intimate moments - their vocabulary, tone, and cadence.
Dirty Talk Style: Detail the specific phrases, language intensity, and emotional tone they use. Are they dominant, submissive, playful, or intense?
Emotional Expression: How does their sexual speech reflect their emotional state and personality?
Examples: Some speech samples"];
            $prompt["en"][]=['role' => 'user', 'content' =>'Output format: plain text prompt, starting directly with Speech Patterns'];


            $prompt["es"][] = ['role' => 'user', 'content' => "Crea una descripción detallada del estilo de habla sexual y los patrones de dirty talk del personaje {$GLOBALS["HERIKA_NAME"]}.
Priority: $profanityDesc
Incluye:
Patrones de Habla: Describe cómo el personaje habla durante momentos íntimos - su vocabulario, tono y cadencia.
Estilo de Dirty Talk: Detalla las frases específicas, la intensidad del lenguaje y el tono emocional que usan. ¿Son dominantes, sumisos, juguetones o intensos?
Ejemplos: Algunas muestras de habla literales."];
        $prompt["es"][]=['role' => 'user', 'content' =>'Formato de salida: prompt en texto plano, empezando directamenete por Patrones de Habla'];
        }

        

        $contextData = $prompt[$LANG];

        $connectionHandler = $connector->getConnector($currentConnectorData);
        $buffer            = $connectionHandler->fast_request($contextData, ["MAX_TOKENS" => 2048, "temperature" => 0.7], "aiagent_nsfw_prompt_create");

        error_log("GEN_PROMPT_RESPONSE: " . print_r($buffer, true));
        
        // Return success response
        echo json_encode([
            "success" => true,
            "prompt" => strip_tags($buffer),
            "npc_id" => $jsonDataInput["npc_id"],
            "connector_id" => $jsonDataInput["connector_id"],
            "profanity_level" => $jsonDataInput["profanity_level"],
            "field_type" => $jsonDataInput["field_type"]
        ]);
    } catch (Exception $e) {
        error_log("GEN_PROMPT_ERROR: " . $e->getMessage());
        echo json_encode([
            "success" => false,
            "error" => $e->getMessage()
        ]);
    }
    exit;
} else {
    // Handle non-POST requests
    echo json_encode([
        "success" => false,
        "error" => "Invalid request method. POST required."
    ]);
    exit;
}
?>
