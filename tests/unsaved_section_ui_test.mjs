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
        const promptGeneration = nsfwUnsavedGeneration.prompts;
        markNsfwChangesSaved('prompts', promptGeneration);
        const promptClearedAfterSave = !header.querySelector('.section-unsaved-indicator');

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
            badgeText,
            exactHeaderMarked,
            topLevelBadgeCount,
            badgeBetweenTitleAndButtons,
            badgeColor,
            badgeBorderColor,
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
    check(result.badgeText === 'You Have Unsaved Changes', 'badge text is wrong or missing');
    check(result.exactHeaderMarked, 'edited section header did not receive the dirty state');
    check(result.topLevelBadgeCount === 1, 'an edit marked more than the exact changed section');
    check(result.badgeBetweenTitleAndButtons, 'badge is not between the section title and Save/Open controls');
    check(result.badgeColor === 'rgb(253, 245, 208)', 'badge does not use the SHARMAT cream-gold text color');
    check(result.badgeBorderColor === 'rgb(244, 201, 93)', 'badge does not use the SHARMAT gold border');
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
