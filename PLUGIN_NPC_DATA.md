# NPC plugin data

When `core_npc_master.plugin_extended_data` is available, saves and single-key updates in `NsfwNpcData` copy persisted state into the `sharmat` namespace. Bulk stale-state cleanup and save/load cleanup refresh the copy too. Unchanged JSON is not rewritten. SHARMAT-only reset removes only this namespace.

The existing `nsfw_npc_data` table remains authoritative for runtime readers, normalized-name matching, and live-state preservation. The copy uses one atomic SQL namespace update, equivalent to the server API's JSONB write, so it can handle bulk cleanup without per-NPC queries. It does not create NPC history or modify core `extended_data`. Other namespaces are preserved. Consumers can read it through `NpcMaster::getPluginData($npcId, 'sharmat')`.

Only one-to-one normalized name matches are copied; ambiguous and unknown NPCs are skipped, with no profile creation. Existing rows are retained and copied on their next save or bulk cleanup. Older schemas continue using the existing table. Optional synchronization failures log a warning without replacing the source data. No new configuration, migration, or native-client change is required.

Ordinary NPC snapshots include the copy. History rollback does not rewind SHARMAT's live table; later state updates refresh the copy. This is storage interoperability, not a migration of runtime readers or changes to prompts, scene policy, or safety checks.

Server dependency: [HerikaServer #96](https://github.com/Dwemer-Dynamics/HerikaServer/pull/96).
