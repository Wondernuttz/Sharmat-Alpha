import { spawn } from 'node:child_process';
import { mkdtemp, rm } from 'node:fs/promises';
import { createServer } from 'node:net';
import { tmpdir } from 'node:os';
import { join } from 'node:path';

const chromePath = process.argv[2];
const pageUrl = process.argv[3] || 'http://127.0.0.1:8081/HerikaServer/ext/aiagent_nsfw/config_manager.php';
if (!chromePath) throw new Error('Usage: node unsaved_section_ui_test.mjs <chrome-path> [page-url]');

const sleep = milliseconds => new Promise(resolve => setTimeout(resolve, milliseconds));
const portServer = createServer();
await new Promise((resolve, reject) => {
    portServer.once('error', reject);
    portServer.listen(0, '127.0.0.1', resolve);
});
const port = portServer.address().port;
await new Promise(resolve => portServer.close(resolve));

const profilePath = await mkdtemp(join(tmpdir(), 'sharmat-ui-test-'));
const chrome = spawn(chromePath, [
    '--headless=new',
    `--remote-debugging-port=${port}`,
    `--user-data-dir=${profilePath}`,
    '--disable-gpu',
    '--window-size=1280,900',
    '--no-first-run',
    '--no-default-browser-check',
    pageUrl,
], {
    stdio: 'ignore',
    windowsHide: true,
});

let socket;
try {
    let targets;
    for (let attempt = 0; attempt < 40; attempt++) {
        try {
            targets = await (await fetch(`http://127.0.0.1:${port}/json`)).json();
            if (targets.some(item => item.type === 'page')) break;
        } catch (_) {
            // Chrome is still starting.
        }
        await sleep(250);
    }

    const target = targets && targets.find(item => item.type === 'page');
    if (!target) throw new Error('No Chrome page target was available');

    socket = new WebSocket(target.webSocketDebuggerUrl);
    await new Promise((resolve, reject) => {
        socket.onopen = resolve;
        socket.onerror = reject;
    });

    let nextId = 1;
    const pending = new Map();
    socket.onmessage = event => {
        const message = JSON.parse(event.data);
        if (!message.id || !pending.has(message.id)) return;
        const request = pending.get(message.id);
        pending.delete(message.id);
        if (message.error) request.reject(new Error(message.error.message));
        else request.resolve(message.result);
    };

    const send = (method, params = {}) => new Promise((resolve, reject) => {
        const id = nextId++;
        pending.set(id, { resolve, reject });
        socket.send(JSON.stringify({ id, method, params }));
    });

    await send('Runtime.enable');
    await sleep(1200);

    const expression = `(async () => {
        const sleep = milliseconds => new Promise(resolve => setTimeout(resolve, milliseconds));
        document.querySelectorAll('.tab-content').forEach(tab => tab.classList.remove('active'));
        document.getElementById('prompts').classList.add('active');

        const defeatCard = document.getElementById('defeatFrameworkCard');
        const defeatDiagnosticTitle = defeatCard ? defeatCard.querySelector('.defeat-diagnostic-card h4') : null;
        const defeatDiagnosticCard = defeatCard ? defeatCard.querySelector('.defeat-diagnostic-card') : null;
        const defeatDiagnosticBody = defeatCard ? defeatCard.querySelector('.defeat-diagnostic-empty') : null;
        const slaverySection = document.getElementById('section3bContent')?.closest('.collapsible-section');
        const defeatLargeHeadings = defeatCard ? Array.from(defeatCard.querySelectorAll('#sectionDefeatContent > .card .section-header')) : [];
        const defeatToggle = document.getElementById('defeatAutoEnslave');
        const defeatToggleState = document.getElementById('defeatAutoEnslaveControlState');
        const defeatGoldLabel = defeatCard ? defeatCard.querySelector('.settings-checkbox-group .gold-glow-text') : null;
        const defeatTitleStyles = defeatDiagnosticTitle ? getComputedStyle(defeatDiagnosticTitle) : null;
        const defeatDiagnosticCardStyles = defeatDiagnosticCard ? getComputedStyle(defeatDiagnosticCard) : null;
        const defeatDiagnosticBodyStyles = defeatDiagnosticBody ? getComputedStyle(defeatDiagnosticBody) : null;
        const defeatLargeHeadingStyles = defeatLargeHeadings[0] ? getComputedStyle(defeatLargeHeadings[0]) : null;
        const defeatToggleStyles = defeatToggle ? getComputedStyle(defeatToggle) : null;
        const defeatGoldLabelStyles = defeatGoldLabel ? getComputedStyle(defeatGoldLabel) : null;
        let defeatToggleCanShowOff = false;
        let defeatToggleCanShowOn = false;
        let defeatToggleUncheckedBackground = null;
        let defeatToggleCheckedBackground = null;
        let defeatToggleBaseBorderColor = null;
        if (defeatToggle && defeatToggleState) {
            const originalInlineAnimation = defeatToggle.style.animation;
            defeatToggle.style.animation = 'none';
            defeatToggleBaseBorderColor = getComputedStyle(defeatToggle).borderColor;
            defeatToggle.style.animation = originalInlineAnimation;
            const originalChecked = defeatToggle.checked;
            defeatToggle.checked = false;
            updateDefeatAutoEnslaveControlState();
            defeatToggleCanShowOff = defeatToggleState.textContent === 'OFF';
            defeatToggleUncheckedBackground = getComputedStyle(defeatToggle).backgroundColor;
            defeatToggle.checked = true;
            updateDefeatAutoEnslaveControlState();
            defeatToggleCanShowOn = defeatToggleState.textContent === 'ON';
            defeatToggleCheckedBackground = getComputedStyle(defeatToggle).backgroundColor;
            defeatToggle.checked = originalChecked;
            updateDefeatAutoEnslaveControlState();
        }

        const control = document.getElementById('promptVrSpankFriendly');
        if (!control) return { error: 'VR Physics test control is missing' };
        control.value += ' ';
        control.dispatchEvent(new Event('input', { bubbles: true }));
        await sleep(100);

        const content = document.getElementById('sectionVrPhysicsContent');
        const header = content ? content.previousElementSibling : null;
        const badge = header ? header.querySelector(':scope > .section-unsaved-indicator') : null;
        const actionArea = header
            ? Array.from(header.children).find(child => child.querySelector && child.querySelector('.section-save-btn'))
            : null;
        const title = header ? header.querySelector(':scope > .section-header') : null;
        const badgeBox = badge ? badge.getBoundingClientRect() : null;
        const actionBox = actionArea ? actionArea.getBoundingClientRect() : null;
        const titleBox = title ? title.getBoundingClientRect() : null;
        const badgeStyles = badge ? getComputedStyle(badge) : null;
        const badgeText = badge ? badge.textContent : null;
        const exactHeaderMarked = Boolean(header && header.classList.contains('has-unsaved-changes'));
        const topLevelBadgeCount = document.querySelectorAll(
            '#prompts > .collapsible-section > .collapsible-header > .section-unsaved-indicator'
        ).length;
        const badgeBetweenTitleAndButtons = Boolean(
            badgeBox && actionBox && titleBox && badgeBox.left >= titleBox.right && badgeBox.right <= actionBox.left
        );
        const badgeColor = badgeStyles ? badgeStyles.color : null;
        const badgeBorderColor = badgeStyles ? badgeStyles.borderColor : null;
        const badgeFontFamily = badgeStyles ? badgeStyles.fontFamily : null;
        const badgeBorderStyle = badgeStyles ? badgeStyles.borderStyle : null;
        const badgeBackgroundImage = badgeStyles ? badgeStyles.backgroundImage : null;
        const badgeBackgroundColor = badgeStyles ? badgeStyles.backgroundColor : null;
        const badgeBorderRadius = badgeStyles ? badgeStyles.borderRadius : null;
        const badgeTextShadow = badgeStyles ? badgeStyles.textShadow : null;
        const badgeWordSpacing = badgeStyles ? badgeStyles.wordSpacing : null;
        const promptGeneration = nsfwUnsavedGeneration.prompts;
        markNsfwChangesSaved('prompts', promptGeneration);
        const promptClearedAfterSave = !header.querySelector('.section-unsaved-indicator');

        const defeatPromptControl = document.getElementById('promptDefeatAggressorScene');
        defeatPromptControl.value += ' ';
        defeatPromptControl.dispatchEvent(new Event('input', { bubbles: true }));
        const originalDefeatToggleChecked = defeatToggle.checked;
        defeatToggle.checked = !originalDefeatToggleChecked;
        defeatToggle.dispatchEvent(new Event('change', { bubbles: true }));
        const defeatCrossGroupBadgeCount = document.querySelectorAll('#prompts .section-unsaved-indicator').length;
        const defeatBadge = document.querySelector('#prompts .section-unsaved-indicator');
        const defeatBadgeGroups = defeatBadge ? (defeatBadge.dataset.nsfwUnsavedGroups || '').split(',').sort() : [];
        markNsfwChangesSaved('prompts', nsfwUnsavedGeneration.prompts);
        const defeatBadgeSurvivesFirstGroupSave = document.querySelectorAll('#prompts .section-unsaved-indicator').length === 1;
        defeatToggle.checked = originalDefeatToggleChecked;
        updateDefeatAutoEnslaveControlState();
        markNsfwChangesSaved('settings', nsfwUnsavedGeneration.settings);
        const defeatBadgeClearsAfterBothGroupsSave = document.querySelectorAll('#prompts .section-unsaved-indicator').length === 0;

        document.querySelectorAll('.tab-content').forEach(tab => tab.classList.remove('active'));
        document.getElementById('settings').classList.add('active');
        const settingsControl = document.getElementById('physicsSpankMinSpeed');
        settingsControl.value = String(Number(settingsControl.value) + 5);
        settingsControl.dispatchEvent(new Event('input', { bubbles: true }));
        await sleep(50);
        const settingsContent = document.getElementById('vrPhysicalContactContent');
        const settingsHeader = settingsContent ? settingsContent.previousElementSibling : null;
        const settingsExactHeaderMarked = Boolean(
            settingsHeader && settingsHeader.querySelector(':scope > .section-unsaved-indicator')
        );

        const staleSettingsGeneration = nsfwUnsavedGeneration.settings;
        settingsControl.value = String(Number(settingsControl.value) + 5);
        settingsControl.dispatchEvent(new Event('input', { bubbles: true }));
        markNsfwChangesSaved('settings', staleSettingsGeneration);
        const badgeSurvivedStaleSave = Boolean(settingsHeader.querySelector('.section-unsaved-indicator'));
        markNsfwChangesSaved('settings', nsfwUnsavedGeneration.settings);
        const settingsClearedAfterCurrentSave = !settingsHeader.querySelector('.section-unsaved-indicator');

        return {
            defeatCardExists: Boolean(defeatCard),
            defeatImmediatelyFollowsSlavery: Boolean(slaverySection && slaverySection.nextElementSibling === defeatCard),
            topLevelDefeatTabExists: Boolean(document.getElementById('defeat')),
            defeatLargeHeadingCount: defeatLargeHeadings.length,
            defeatLargeHeadingFont: defeatLargeHeadingStyles ? defeatLargeHeadingStyles.fontFamily : null,
            defeatLargeHeadingSize: defeatLargeHeadingStyles ? defeatLargeHeadingStyles.fontSize : null,
            defeatLargeHeadingAnimation: defeatLargeHeadingStyles ? defeatLargeHeadingStyles.animationName : null,
            defeatTitleFontFamily: defeatTitleStyles ? defeatTitleStyles.fontFamily : null,
            defeatTitleTextShadow: defeatTitleStyles ? defeatTitleStyles.textShadow : null,
            defeatTitleAnimation: defeatTitleStyles ? defeatTitleStyles.animationName : null,
            defeatDiagnosticBackground: defeatDiagnosticCardStyles ? defeatDiagnosticCardStyles.backgroundColor : null,
            defeatDiagnosticBorderColor: defeatDiagnosticCardStyles ? defeatDiagnosticCardStyles.borderColor : null,
            defeatDataColor: defeatDiagnosticBodyStyles ? defeatDiagnosticBodyStyles.color : null,
            defeatToggleAnimation: defeatToggleStyles ? defeatToggleStyles.animationName : null,
            defeatToggleWidth: defeatToggleStyles ? defeatToggleStyles.width : null,
            defeatToggleHeight: defeatToggleStyles ? defeatToggleStyles.height : null,
            defeatToggleBorderColor: defeatToggleStyles ? defeatToggleStyles.borderColor : null,
            defeatToggleBorderRadius: defeatToggleStyles ? defeatToggleStyles.borderRadius : null,
            defeatGoldLabelAnimation: defeatGoldLabelStyles ? defeatGoldLabelStyles.animationName : null,
            defeatGoldLabelFont: defeatGoldLabelStyles ? defeatGoldLabelStyles.fontFamily : null,
            defeatToggleCanShowOff,
            defeatToggleCanShowOn,
            defeatToggleUncheckedBackground,
            defeatToggleCheckedBackground,
            defeatToggleBaseBorderColor,
            defeatCrossGroupBadgeCount,
            defeatBadgeGroups,
            defeatBadgeSurvivesFirstGroupSave,
            defeatBadgeClearsAfterBothGroupsSave,
            badgeText,
            exactHeaderMarked,
            topLevelBadgeCount,
            badgeBetweenTitleAndButtons,
            badgeColor,
            badgeBorderColor,
            badgeFontFamily,
            badgeBorderStyle,
            badgeBackgroundImage,
            badgeBackgroundColor,
            badgeBorderRadius,
            badgeTextShadow,
            badgeWordSpacing,
            floatingIndicatorExists: Boolean(document.getElementById('unsavedChangesIndicator')),
            promptClearedAfterSave,
            settingsExactHeaderMarked,
            badgeSurvivedStaleSave,
            settingsClearedAfterCurrentSave,
        };
    })()`;

    const evaluation = await send('Runtime.evaluate', {
        expression,
        awaitPromise: true,
        returnByValue: true,
    });
    const result = evaluation.result.value;
    const failures = [];
    const check = (condition, message) => {
        if (!condition) failures.push(message);
    };

    check(!result.error, result.error || 'page evaluation failed');
    check(result.defeatCardExists, 'Defeat Frameworks is not present as a Prompts card');
    check(result.defeatImmediatelyFollowsSlavery, 'Defeat Frameworks is not directly under Slavery');
    check(!result.topLevelDefeatTabExists, 'Defeat Frameworks is still a top-level tab');
    check(result.defeatLargeHeadingCount === 3, 'Player Defeat, Enemy Defeated, and Defeat Diagnostics are not all large headings');
    check(result.defeatLargeHeadingFont && result.defeatLargeHeadingFont.includes('MagicCards'), 'large defeat headings do not use MagicCards');
    check(result.defeatLargeHeadingSize === '24px', 'large defeat headings are not 24px');
    check(result.defeatLargeHeadingAnimation === 'subPulse', 'large defeat headings do not use the native section-header pulse');
    check(result.defeatTitleFontFamily && result.defeatTitleFontFamily.includes('MagicCards'), 'defeat diagnostic title does not use MagicCards');
    check(result.defeatTitleTextShadow && result.defeatTitleTextShadow !== 'none', 'defeat diagnostic title is missing its glow');
    check(result.defeatTitleAnimation === 'neonPulse', 'diagnostic titles do not use the native gold text pulse');
    check(result.defeatDiagnosticBackground === 'rgb(37, 34, 51)', 'defeat diagnostics do not use the native info-box background');
    check(result.defeatDiagnosticBorderColor === 'rgb(58, 53, 69)', 'defeat diagnostics do not use the native info-box border');
    check(result.defeatDataColor === 'rgb(184, 168, 200)', 'defeat diagnostic body text is not the native lavender color');
    check(result.defeatToggleAnimation === 'goldNeonPulse', 'auto-enslave checkbox is missing the native glow animation');
    check(result.defeatToggleWidth === '20px' && result.defeatToggleHeight === '20px', 'auto-enslave checkbox does not use the native dimensions');
    check(result.defeatToggleBaseBorderColor === 'rgb(253, 245, 208)', 'auto-enslave checkbox does not use the native gold border');
    check(result.defeatToggleBorderRadius === '4px', 'auto-enslave checkbox does not use the native corner radius');
    check(result.defeatGoldLabelAnimation === 'neonPulse', 'auto-enslave label is missing the native gold text pulse');
    check(result.defeatGoldLabelFont && result.defeatGoldLabelFont.includes('MagicCards'), 'auto-enslave label does not use the native gold text font');
    check(result.defeatToggleCanShowOff, 'auto-enslave switch cannot visibly show OFF');
    check(result.defeatToggleCanShowOn, 'auto-enslave switch cannot visibly show ON');
    check(result.defeatToggleUncheckedBackground === 'rgb(30, 26, 46)', 'auto-enslave OFF state does not use the native unchecked background');
    check(result.defeatToggleCheckedBackground === 'rgb(253, 245, 208)', 'auto-enslave ON state does not use the native checked background');
    check(result.defeatCrossGroupBadgeCount === 1, 'editing both defeat groups duplicated the unsaved warning');
    check(JSON.stringify(result.defeatBadgeGroups) === JSON.stringify(['prompts', 'settings']), 'single defeat warning did not track both save groups');
    check(result.defeatBadgeSurvivesFirstGroupSave, 'defeat warning disappeared before both save groups were saved');
    check(result.defeatBadgeClearsAfterBothGroupsSave, 'defeat warning did not clear after both save groups were saved');
    check(result.badgeText === 'You Have Unsaved Changes', 'badge text is wrong or missing');
    check(result.exactHeaderMarked, 'edited section header did not receive the dirty state');
    check(result.topLevelBadgeCount === 1, 'an edit marked more than the exact changed section');
    check(result.badgeBetweenTitleAndButtons, 'badge is not between the section title and Save/Open controls');
    check(result.badgeColor === 'rgb(253, 245, 208)', 'warning color does not exactly match the active top tab');
    check(result.badgeFontFamily && result.badgeFontFamily.includes('MagicCards'), 'warning does not use the elegant SHARMAT display font');
    check(result.badgeWordSpacing === '8px', 'warning words do not have the required visual spacing');
    check(result.badgeBorderStyle === 'none', 'warning is still pillboxed with a border');
    check(result.badgeBackgroundImage === 'none', 'warning is still pillboxed with a background image');
    check(result.badgeBackgroundColor === 'rgba(0, 0, 0, 0)', 'warning is still pillboxed with a background color');
    check(result.badgeBorderRadius === '0px', 'warning still has a rounded pill shape');
    check(result.badgeTextShadow && result.badgeTextShadow !== 'none', 'warning is missing the gold text glow');
    check(!result.floatingIndicatorExists, 'old floating unsaved indicator still exists');
    check(result.promptClearedAfterSave, 'successful prompt save did not clear its section badge');
    check(result.settingsExactHeaderMarked, 'Settings edit did not mark its exact owning section');
    check(result.badgeSurvivedStaleSave, 'an older save incorrectly cleared a newer unsaved edit');
    check(result.settingsClearedAfterCurrentSave, 'current successful Settings save did not clear its section badge');

    if (failures.length) {
        throw new Error(`Unsaved section UI regression failures:\n - ${failures.join('\n - ')}\nResult: ${JSON.stringify(result)}`);
    }

    console.log(`Unsaved section UI browser test passed: ${JSON.stringify(result)}`);
} finally {
    if (socket && socket.readyState < WebSocket.CLOSING) socket.close();
    chrome.kill();
    await Promise.race([
        new Promise(resolve => chrome.once('exit', resolve)),
        sleep(2000),
    ]);
    await rm(profilePath, { recursive: true, force: true });
}
