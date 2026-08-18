<?php

// Pure helpers for framework-provided scene roles. Actor names are matched without
// case sensitivity because CHIM display names and framework names do not always use
// identical casing.
function aiagentNsfwActorRoleTags($actorRoles, $actorName)
{
    if (!is_array($actorRoles)) {
        return [];
    }

    foreach ($actorRoles as $name => $tags) {
        if (strcasecmp(trim((string)$name), trim((string)$actorName)) === 0) {
            return array_values(array_unique(array_map(
                static function ($tag) { return strtolower(trim((string)$tag)); },
                is_array($tags) ? $tags : []
            )));
        }
    }

    return [];
}

function aiagentNsfwActorHasSceneRole($actorRoles, $actorName, $role)
{
    return in_array(strtolower(trim((string)$role)), aiagentNsfwActorRoleTags($actorRoles, $actorName), true);
}

function aiagentNsfwFrameworkPlayerIsVictim($actorRoles, $playerName)
{
    return aiagentNsfwActorHasSceneRole($actorRoles, $playerName, 'forced')
        && aiagentNsfwActorHasSceneRole($actorRoles, $playerName, 'victim');
}

function aiagentNsfwFrameworkActorIsAggressor($actorRoles, $actorName, $playerName)
{
    return aiagentNsfwFrameworkPlayerIsVictim($actorRoles, $playerName)
        && aiagentNsfwActorHasSceneRole($actorRoles, $actorName, 'forced')
        && aiagentNsfwActorHasSceneRole($actorRoles, $actorName, 'aggressor');
}
