        <!-- Scenes Tab -->
        <div id="scenes" class="tab-content active">
            <div class="alert success" id="sceneSuccessAlert"></div>
            <div class="alert error" id="sceneErrorAlert"></div>

            <h2 class="section-header" style="margin-bottom: 20px; display: flex; align-items: center; gap: 10px;"><img src="images/ChimNSFWsoulgem.png" style="width: 32px; height: 32px; vertical-align: middle; position: relative; top: -5px;"> Create New Scene</h2>

            <div class="row">
                <div class="form-group">
                    <label for="sceneStage">Stage (ID) *</label>
                    <input type="text" id="sceneStage" placeholder="e.g., scene_01" required>
                </div>
                <div class="form-group">
                    <label for="sceneDesc">Description</label>
                    <input type="text" id="sceneDesc" placeholder="Default description">
                </div>
            </div>

            <div class="button-group">
                <button class="btn-primary npc-action-btn" onclick="createScene()">Create Scene</button>
                <button class="btn-secondary npc-action-btn" onclick="clearSceneForm()">Clear Form</button>
                <button class="btn-warning npc-action-btn" onclick="generateSceneDescriptions()" title='Will Use AI'>Generate Descriptions</button>
            </div>
            <p class="legend">Generate descriptions - what is this for? You can edit the internal description field and add a natural speaking description, 
                just refer to actors as "actor zero", "actor one". Then use the button to generate a well-formatted description. 
                You can use a browser extension like <a target="_blank" href="https://chromewebstore.google.com/detail/voice-in-speech-to-text-d/pjnefijmagpdjfhhkpljicbbpicelgko" style="color: #FDF5D0;">Voice In - Speech to Text</a>
                to fill the internal description field more quickly using voice.
            </p>

            <h2 class="section-header" style="margin: 30px 0 20px; display: flex; align-items: center; gap: 10px;"><img src="images/ChimNSFWsoulgem.png" style="width: 32px; height: 32px; vertical-align: middle; position: relative; top: -5px;"> Existing Scenes</h2>
            <!-- Scene Filters -->
            <div class="row" style="margin-bottom: 20px; gap: 15px; align-items: flex-end;">
                <div class="form-group" style="margin-bottom: 0;">
                    <label for="sceneSearchInput">Search Scenes</label>
                    <input type="text" id="sceneSearchInput" placeholder="Type to search by stage name..." oninput="filterScenes()">
                </div>
                <div class="form-group" style="flex: 0 0 150px; margin-bottom: 0;">
                    <label for="sceneTypeFilter">Scene Type</label>
                    <select id="sceneTypeFilter" onchange="filterScenes()">
                        <option value="">All Types</option>
                        <option value="OStim">OStim</option>
                    </select>
                </div>
                <div class="form-group" style="flex: 0 0 150px; margin-bottom: 0;">
                    <label for="sceneAnimatorFilter">Animator</label>
                    <select id="sceneAnimatorFilter" onchange="filterScenes()">
                        <option value="">All Animators</option>
                        <option value="Billyy">Billyy (1723)</option>
                        <option value="Anubs">Anubs (762)</option>
                        <option value="Leito">Leito (275)</option>
                        <option value="Nibbles">Nibbles (226)</option>
                        <option value="OStim">OStim (98)</option>
                        <option value="MFP">MFP (17)</option>
                        <option value="MJ">MJ (9)</option>
                        <option value="Other">Other</option>
                    </select>
                </div>
                <div style="flex: 0 0 auto; display: flex; gap: 10px;">
                    <button class="btn-secondary" onclick="clearSceneFilters()" style="height: 38px;">Clear Filters</button>
                    <button class="btn-secondary" onclick="resetAllSceneDefaults()" style="height: 38px;">Master Reset Defaults</button>
                </div>
            </div>
            <div id="sceneFilterInfo" style="margin-bottom: 10px; color: #B8A8D0; font-size: 13px;"></div>
            <div class="loading active" id="scenesLoading">Loading scenes...</div>
            <div class="table-wrapper">
                <table id="scenesTable" style="display: none;">
                    <thead>
                        <tr>
                            <th>Stage</th>
                            <th>Description</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody id="scenesTableBody">
                    </tbody>
                </table>
            </div>
            <div id="paginationContainer" style="display: none;">
                <div class="pagination" id="paginationControls"></div>
                <div class="pagination-info" id="paginationInfo"></div>
            </div>
        </div>
