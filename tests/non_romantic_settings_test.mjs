import assert from 'node:assert/strict';
import { copyFileSync, mkdirSync, mkdtempSync, readFileSync, realpathSync, rmSync, writeFileSync } from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import { spawnSync } from 'node:child_process';
import vm from 'node:vm';
import { fileURLToPath } from 'node:url';

const repo = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const php = process.env.PHP_BINARY || 'php';
const fixture = path.join(repo, 'tests/non_romantic_settings_fixture.php');
const tempBase = path.resolve(process.env.SHARMAT_TEST_TMP || os.tmpdir());
mkdirSync(tempBase, { recursive: true });
const runtimeRoot = mkdtempSync(path.join(tempBase, 'sharmat-nonromantic-settings-'));
let templatePath;

function cleanupRuntime() {
    const base = realpathSync(tempBase);
    const target = realpathSync(runtimeRoot);
    const relative = path.relative(base, target);
    assert.ok(relative && !relative.startsWith('..') && !path.isAbsolute(relative), 'Refusing to remove a temp path outside the owned settings test directory');
    rmSync(target, { recursive: true, force: true });
}

function phpRun(mode, input, args = []) {
    const result = spawnSync(php, ['-n', fixture, mode, ...args], {
        input: JSON.stringify(input), encoding: 'utf8', maxBuffer: 8 * 1024 * 1024,
    });
    if (result.error) throw result.error;
    if (result.status !== 0) throw new Error(`${mode} fixture failed (${result.status}): ${result.stderr || result.stdout}`);
    return result.stdout;
}

function htmlDecode(value) {
    return value.replaceAll('&quot;', '"').replaceAll('&#039;', "'")
        .replaceAll('&lt;', '<').replaceAll('&gt;', '>').replaceAll('&amp;', '&');
}

function checkboxValues(html, className) {
    const escaped = className.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
    return [...html.matchAll(new RegExp(`<input\\b[^>]*class="${escaped}"[^>]*>`, 'g'))].map(([tag]) => ({
        value: htmlDecode(tag.match(/\bvalue="([^"]*)"/)?.[1] ?? ''),
        checked: /\schecked(?:\s|>)/.test(tag),
    }));
}

function fieldValues(html) {
    const textarea = id => {
        const found = html.match(new RegExp(`<textarea\\b[^>]*\\bid="${id}"[^>]*>([\\s\\S]*?)<\\/textarea>`));
        assert.ok(found, `Rendered page omitted ${id}`);
        return { value: htmlDecode(found[1]) };
    };
    const enabledTag = html.match(/<input\b[^>]*\bid="reltypeEnabled"[^>]*>/)?.[0];
    assert.ok(enabledTag, 'Rendered page omitted reltypeEnabled');
    return {
        reltypeEnabled: { checked: /\schecked(?:\s|>)/.test(enabledTag) },
        reltypePromptFriendly: textarea('reltypePromptFriendly'),
        reltypePromptFond: textarea('reltypePromptFond'),
        reltypePromptDevoted: textarea('reltypePromptDevoted'),
        reltypePromptBonded: textarea('reltypePromptBonded'),
        reltypePromptMarried: textarea('reltypePromptMarried'),
        reltypeCustomInput: { value: '' },
    };
}

class FakeElement {
    constructor(tagName) {
        this.tagName = tagName;
        this.children = [];
        this.parentNode = null;
        this.className = '';
        this.type = '';
        this.value = '';
        this.checked = false;
        this.textContent = '';
    }
    appendChild(child) {
        child.parentNode = this;
        this.children.push(child);
        return child;
    }
    querySelectorAll(selector) {
        const match = selector.match(/^\.([\w-]+)(:checked)?$/);
        assert.ok(match, `Unexpected fake DOM selector ${selector}`);
        const results = [];
        const visit = node => {
            for (const child of node.children) {
                if (child.className.split(/\s+/).includes(match[1]) && (!match[2] || child.checked)) results.push(child);
                visit(child);
            }
        };
        visit(this);
        return results;
    }
}

function renderedJs(source) {
    const start = source.indexOf('        function addRelType() {');
    assert.notEqual(start, -1, 'Production custom relationship type UI function is missing');
    const end = source.indexOf('        // Null-safe DOM setter:', start);
    assert.notEqual(end, -1, 'Production relationship settings JS boundary changed');
    let code = source.slice(start, end).trimEnd();
    const endpoint = "<?php echo $_SERVER['PHP_SELF']; ?>";
    assert.equal(code.split(endpoint).length - 1, 1, 'Expected one real settings-save endpoint expression');
    return code.replace(endpoint, '/fixture-config.php');
}

function createUi(html, code) {
    const grid = new FakeElement('div');
    const boxesByValue = new Map();
    for (const [className, selector] of [
        ['reltype-checkbox', 'reltype-checkbox'],
        ['reltype-nonromantic-checkbox', 'reltype-nonromantic-checkbox'],
    ]) {
        for (const checkbox of checkboxValues(html, selector)) {
            let row = boxesByValue.get(checkbox.value);
            if (!row) {
                row = new FakeElement('div');
                boxesByValue.set(checkbox.value, row);
                grid.appendChild(row);
            }
            const element = new FakeElement('input');
            element.type = 'checkbox';
            element.className = className;
            element.value = checkbox.value;
            element.checked = checkbox.checked;
            row.appendChild(element);
        }
    }
    const elements = { ...fieldValues(html), reltypeGrid: grid };
    let request;
    class FakeFormData {
        constructor() { this.values = []; }
        append(key, value) { this.values.push([String(key), String(value)]); }
        entries() { return this.values[Symbol.iterator](); }
    }
    const sandbox = {
        FormData: FakeFormData,
        document: {
            getElementById(id) {
                assert.ok(elements[id], `Production JS requested missing element ${id}`);
                return elements[id];
            },
            createElement(tag) { return new FakeElement(tag); },
            querySelectorAll(selector) { return grid.querySelectorAll(selector); },
        },
        fetch(url, options) {
            request = { url, method: options.method, post: Object.fromEntries(options.body.entries()) };
            return Promise.resolve({ json: () => Promise.resolve({ success: true, message: 'captured' }) });
        },
        showAlert() {},
    };
    vm.runInNewContext(code, sandbox, { filename: 'config_manager.php:relationship type settings' });
    return {
        elements,
        grid,
        all(className) { return grid.querySelectorAll(`.${className}`); },
        addRelType: sandbox.addRelType,
        async save() {
            sandbox.saveRelTypes();
            await new Promise(resolve => setImmediate(resolve));
            assert.ok(request, 'Native saveRelTypes did not call fake fetch');
            return request;
        },
    };
}

function render(rawValue, readFailure = false) {
    return JSON.parse(phpRun('render', { raw_value: rawValue, read_failure: readFailure }, [templatePath])).html;
}

function runSave(currentConfig, post, readFailure = false) {
    const stdout = phpRun('save', { current_value: currentConfig === null ? null : JSON.stringify(currentConfig), post, read_failure: readFailure });
    const marker = '\n@@NON_ROMANTIC_SETTINGS_WRITES@@';
    const split = stdout.lastIndexOf(marker);
    assert.notEqual(split, -1, 'Real save handler did not emit the in-memory write capture');
    return { response: JSON.parse(stdout.slice(0, split)), writes: JSON.parse(stdout.slice(split + marker.length)) };
}

function postLists(post) {
    return {
        eligible: JSON.parse(post.eligible_types),
        nonRomantic: JSON.parse(post.non_romantic_types),
    };
}

try {
    templatePath = path.join(runtimeRoot, 'app/ui/config_section_reltypes.php');
    mkdirSync(path.dirname(templatePath), { recursive: true });
    mkdirSync(path.join(runtimeRoot, 'lib'), { recursive: true });
    copyFileSync(path.join(repo, 'config_section_reltypes.php'), templatePath);
    copyFileSync(path.join(repo, 'open_mode_policy.php'), path.join(runtimeRoot, 'app/ui/open_mode_policy.php'));
    writeFileSync(path.join(runtimeRoot, 'lib/relationship_manager.php'), `<?php
class RelationshipManager {
    public const TYPES = ['romantic', 'platonic', 'crush', 'ex'];
    public const TYPE_EMOJI = ['romantic' => '♥', 'platonic' => '•', 'crush' => '♡', 'ex' => '↩'];
}
`, 'utf8');

    const policySource = readFileSync(path.join(repo, 'open_mode_policy.php'), 'utf8');
    const managerSource = readFileSync(path.join(repo, 'config_manager.php'), 'utf8');
    const nativeJs = renderedJs(managerSource);
    const policyCases = JSON.parse(phpRun('normalize', {
        cases: {
            missing: { read_status: 'missing' },
            legacy: { config: { enabled: true } },
            custom: { config: { enabled: true, eligible_types: [' Romance ', 'CASUAL_Intimacy', 'casual_intimacy'], non_romantic_types: ['casual_intimacy'] } },
            emptyEligible: { config: { enabled: true, eligible_types: [], non_romantic_types: [] } },
            disabled: { config: { enabled: false, eligible_types: ['romantic'], non_romantic_types: [] } },
            topLevelList: { config: ['romantic'] },
            badEnabled: { config: { enabled: 'false', eligible_types: ['romantic'] } },
            badList: { config: { enabled: true, eligible_types: ['romantic', { nested: true }] } },
            readError: { read_status: 'error' },
        },
    }));
    assert.deepEqual(policyCases.missing, {
        status: 'missing', valid: true, enabled: true,
        eligible_types: ['romantic', 'crush', 'ex'], non_romantic_types: [],
    });
    assert.deepEqual(policyCases.legacy.eligible_types, ['romantic', 'crush', 'ex']);
    assert.deepEqual(policyCases.custom.eligible_types, ['romantic', 'casual_intimacy']);
    assert.deepEqual(policyCases.custom.non_romantic_types, ['casual_intimacy']);
    assert.deepEqual(policyCases.emptyEligible.eligible_types, []);
    assert.equal(policyCases.disabled.enabled, false);
    for (const name of ['topLevelList', 'badEnabled', 'badList', 'readError']) {
        assert.equal(policyCases[name].valid, false, `${name} must fail closed`);
        assert.equal(policyCases[name].enabled, true, `${name} must not disable the gate`);
        assert.deepEqual(policyCases[name].eligible_types, []);
        assert.deepEqual(policyCases[name].non_romantic_types, []);
    }

    const invalidConfigHtml = render(JSON.stringify(['romantic']));
    assert.ok(/id="reltypeEnabled"[^>]*checked/.test(invalidConfigHtml), 'Malformed config must leave the type gate enabled');
    assert.equal(checkboxValues(invalidConfigHtml, 'reltype-checkbox').some(item => item.checked), false, 'Malformed config must not grant eligibility');
    assert.equal(checkboxValues(invalidConfigHtml, 'reltype-nonromantic-checkbox').some(item => item.checked), false, 'Malformed config must not invent a non-romantic designation');
    const missingConfigHtml = render(null);
    assert.deepEqual(checkboxValues(missingConfigHtml, 'reltype-checkbox').filter(item => item.checked).map(item => item.value), ['romantic', 'crush', 'ex'], 'Empty DB row result must retain documented missing-config defaults');
    const missingUi = createUi(missingConfigHtml, nativeJs);
    const missingPost = (await missingUi.save()).post;
    const missingSave = runSave(null, missingPost);
    assert.equal(missingSave.response.success, true, 'A successful guaranteed-row read with no config must save defaults');
    assert.deepEqual(JSON.parse(missingSave.writes[0].row.value).eligible_types, ['romantic', 'crush', 'ex']);

    const failedReadHtml = render(null, true);
    assert.match(failedReadHtml, /settings could not be read/i, 'Failed fetchOne result must be visible in the settings UI');
    assert.match(failedReadHtml, /aria-disabled="true"/, 'Failed read must disable the settings save control');
    assert.equal(failedReadHtml.includes('onclick="event.stopPropagation(); saveRelTypes();"'), false, 'Failed read must not expose a save action that could overwrite defaults');
    const failedReadSave = runSave(null, missingPost, true);
    assert.equal(failedReadSave.response.success, false, 'fetchOne failure sentinel must be rejected');
    assert.equal(failedReadSave.writes.length, 0, 'fetchOne failure sentinel must not write config');

    const initial = {
        enabled: true,
        eligible_types: ['romantic', 'casual_intimacy'],
        non_romantic_types: ['casual_intimacy'],
        prompt_friendly: 'Friendly boundary.', prompt_fond: 'Fond boundary.',
        prompt_devoted: 'Devoted boundary.', prompt_bonded: 'Bonded boundary.',
        prompt_married_addon: 'Married to #SPOUSE#.',
    };
    const initialHtml = render(JSON.stringify(initial));
    const initialUi = createUi(initialHtml, nativeJs);
    assert.ok(checkboxValues(initialHtml, 'reltype-checkbox').some(item => item.value === 'casual_intimacy' && item.checked), 'Stored custom eligible type must render checked');
    assert.ok(checkboxValues(initialHtml, 'reltype-nonromantic-checkbox').some(item => item.value === 'casual_intimacy' && item.checked), 'Stored custom non-romantic designation must render checked');
    const initialPost = await initialUi.save();
    assert.deepEqual(postLists(initialPost.post), { eligible: ['romantic', 'casual_intimacy'], nonRomantic: ['casual_intimacy'] });
    const initialSave = runSave(initial, initialPost.post);
    assert.equal(initialSave.response.success, true);
    assert.equal(initialSave.writes.length, 1);
    const savedInitial = JSON.parse(initialSave.writes[0].row.value);
    assert.deepEqual(savedInitial.eligible_types, ['romantic', 'casual_intimacy']);
    assert.deepEqual(savedInitial.non_romantic_types, ['casual_intimacy']);
    const reloadedHtml = render(JSON.stringify(savedInitial));
    assert.ok(checkboxValues(reloadedHtml, 'reltype-checkbox').some(item => item.value === 'casual_intimacy' && item.checked), 'Custom eligible selection must survive save/reload');
    assert.ok(checkboxValues(reloadedHtml, 'reltype-nonromantic-checkbox').some(item => item.value === 'casual_intimacy' && item.checked), 'Custom non-romantic designation must survive save/reload');

    const addInitial = {
        enabled: true, eligible_types: ['romantic'], non_romantic_types: [],
    };
    const addUi = createUi(render(JSON.stringify(addInitial)), nativeJs);
    addUi.elements.reltypeCustomInput.value = 'casual_partner';
    addUi.addRelType();
    const addedEligible = addUi.all('reltype-checkbox').find(item => item.value === 'casual_partner');
    const addedNonRomantic = addUi.all('reltype-nonromantic-checkbox').find(item => item.value === 'casual_partner');
    assert.ok(addedEligible && addedNonRomantic, 'Custom entry workflow must add both independent choices');
    assert.equal(addedEligible.checked, false, 'A new custom type must not auto-enable eligibility');
    assert.equal(addedNonRomantic.checked, false, 'A new custom type must not auto-designate non-romantic');
    addedNonRomantic.checked = true;
    const addPost = await addUi.save();
    const lists = postLists(addPost.post);
    assert.equal(lists.eligible.includes('casual_partner'), false, 'Non-romantic designation alone must not add eligibility');
    assert.equal(lists.nonRomantic.includes('casual_partner'), true);
    const addSave = runSave(addInitial, addPost.post);
    assert.equal(addSave.response.success, true);
    const savedAdded = JSON.parse(addSave.writes[0].row.value);
    assert.equal(savedAdded.eligible_types.includes('casual_partner'), false);
    assert.equal(savedAdded.non_romantic_types.includes('casual_partner'), true);
    assert.ok(checkboxValues(render(JSON.stringify(savedAdded)), 'reltype-nonromantic-checkbox').some(item => item.value === 'casual_partner' && item.checked));

    const escapedHtml = render(JSON.stringify({ enabled: true, eligible_types: ['x<script>'], non_romantic_types: [] }));
    assert.ok(escapedHtml.includes('value="x&lt;script&gt;"'), 'Configured custom values must be escaped in checkbox attributes');
    assert.equal(escapedHtml.includes('<script>'), false, 'Configured custom values must not become markup');

    const omittedNewField = { ...initialPost.post };
    delete omittedNewField.non_romantic_types;
    const preserved = runSave(initial, omittedNewField);
    assert.equal(preserved.response.success, true, 'Older clients may omit the new list');
    assert.deepEqual(JSON.parse(preserved.writes[0].row.value).non_romantic_types, ['casual_intimacy']);
    const deliberateClear = runSave(initial, { ...initialPost.post, non_romantic_types: '[]' });
    assert.deepEqual(JSON.parse(deliberateClear.writes[0].row.value).non_romantic_types, [], 'Explicit empty list must clear the designation');
    const disabled = runSave(initial, { ...initialPost.post, enabled: 'false' });
    assert.equal(disabled.response.success, true);
    assert.equal(JSON.parse(disabled.writes[0].row.value).enabled, false, 'Explicit valid boolean string must preserve the existing gate-off setting');
    const badEnabled = runSave(initial, { ...initialPost.post, enabled: 'False' });
    assert.equal(badEnabled.response.success, false, 'Malformed gating flags must be rejected');
    assert.equal(badEnabled.writes.length, 0);
    const malformed = runSave(initial, { ...initialPost.post, eligible_types: '{"not":"a list"}' });
    assert.equal(malformed.response.success, false, 'Malformed save payload must be rejected');
    assert.equal(malformed.writes.length, 0, 'Malformed save payload must not write');

    assert.match(policySource, /function aiagentNsfwNormalizeRelTypeConfig\(/, 'The shared policy helper must be declared in the existing pure policy file');
    assert.ok(managerSource.includes("SELECT COALESCE((SELECT value::text FROM conf_opts WHERE id = 'aiagent_nsfw_reltypes'), '') AS value"), 'Renderer and save handler must use a guaranteed-row DB read');
    console.log('non_romantic_settings_test: normalized config, production UI/save roundtrip, missing-row defaults, read-failure rejection, explicit custom choices, and invalid-save rejection passed');
} finally {
    cleanupRuntime();
}
