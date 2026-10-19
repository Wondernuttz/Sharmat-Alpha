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
const runtimeRoot = mkdtempSync(path.join(tempBase, 'sharmat-arrangement-settings-'));
let templatePath;
let checks = 0;

function check(condition, message) {
    assert.ok(condition, message);
    checks++;
}

function phpRun(mode, input, args = []) {
    const result = spawnSync(php, ['-n', fixture, mode, ...args], {
        input: JSON.stringify(input), encoding: 'utf8', maxBuffer: 8 * 1024 * 1024,
    });
    if (result.error) throw result.error;
    assert.equal(result.status, 0, `${mode} fixture failed (${result.status}): ${result.stderr || result.stdout}`);
    return result.stdout;
}

function render(rawValue) {
    return JSON.parse(phpRun('render', { raw_value: rawValue }, [templatePath])).html;
}

function arrangementCheckbox(html) {
    const tag = html.match(/<input\b[^>]*\bid="reltypeUseIntimacyArrangements"[^>]*>/)?.[0];
    assert.ok(tag, 'Production settings page omitted the arrangement-policy checkbox');
    return /\schecked(?:\s|>)/.test(tag);
}

function runProductionUiSave(checked) {
    const manager = readFileSync(path.join(repo, 'config_manager.php'), 'utf8');
    const start = manager.indexOf('        function addRelType() {');
    const end = manager.indexOf('        // Null-safe DOM setter:', start);
    assert.notEqual(start, -1, 'Production relationship settings UI start is missing');
    assert.notEqual(end, -1, 'Production relationship settings UI boundary is missing');
    const endpoint = "<?php echo $_SERVER['PHP_SELF']; ?>";
    let code = manager.slice(start, end).trimEnd();
    assert.equal(code.split(endpoint).length - 1, 1, 'Expected one production save endpoint expression');
    code = code.replace(endpoint, '/fixture-config.php');

    const elements = {
        reltypeEnabled: { checked: true },
        reltypeUseIntimacyArrangements: { checked },
        reltypePromptFriendly: { value: '' },
        reltypePromptFond: { value: '' },
        reltypePromptDevoted: { value: '' },
        reltypePromptBonded: { value: '' },
        reltypePromptMarried: { value: '' },
    };
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
                assert.ok(elements[id], `Production save UI requested unexpected DOM id ${id}`);
                return elements[id];
            },
            querySelectorAll() { return []; },
        },
        fetch(url, options) {
            request = { url, post: Object.fromEntries(options.body.entries()) };
            return Promise.resolve({ json: () => Promise.resolve({ success: true }) });
        },
        showAlert() {},
    };
    vm.runInNewContext(code, sandbox, { filename: 'config_manager.php:relationship settings save' });
    sandbox.saveRelTypes();
    return new Promise(resolve => setImmediate(() => {
        assert.ok(request, 'Production saveRelTypes did not submit');
        resolve(request);
    }));
}

function runProductionSave(currentConfig, post) {
    const input = {
        current_value: currentConfig === null ? null : JSON.stringify(currentConfig),
        post,
    };
    const stdout = phpRun('save', input);
    const marker = '\n@@NON_ROMANTIC_SETTINGS_WRITES@@';
    const split = stdout.lastIndexOf(marker);
    assert.notEqual(split, -1, 'Production save handler did not emit the write capture');
    return { response: JSON.parse(stdout.slice(0, split)), writes: JSON.parse(stdout.slice(split + marker.length)) };
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
    public const TYPE_EMOJI = [];
}
`, 'utf8');

    const missingHtml = render(null);
    check(arrangementCheckbox(missingHtml) === false, 'Missing/legacy configuration renders the new option off by default');
    check(/Off by default/i.test(missingHtml), 'Settings explanation identifies the opt-in as off by default');

    const saved = {
        enabled: true,
        eligible_types: ['romantic'],
        non_romantic_types: [],
        use_intimacy_arrangements: true,
    };
    check(arrangementCheckbox(render(JSON.stringify(saved))) === true, 'Saved opt-in renders checked');

    const trueRequest = await runProductionUiSave(true);
    check(trueRequest.post.use_intimacy_arrangements === 'true', 'Production UI posts the checked opt-in');
    const savedTrue = runProductionSave(saved, trueRequest.post);
    check(savedTrue.response.success === true && savedTrue.writes.length === 1, 'Production save accepts an explicit opt-in');
    check(JSON.parse(savedTrue.writes[0].row.value).use_intimacy_arrangements === true, 'Explicit opt-in round-trips as a strict boolean');

    const falseRequest = await runProductionUiSave(false);
    check(falseRequest.post.use_intimacy_arrangements === 'false', 'Production UI posts an unchecked opt-out');
    const savedFalse = runProductionSave(saved, falseRequest.post);
    check(savedFalse.response.success === true && JSON.parse(savedFalse.writes[0].row.value).use_intimacy_arrangements === false,
        'Explicit opt-out round-trips as a strict boolean');

    const omitted = { ...trueRequest.post };
    delete omitted.use_intimacy_arrangements;
    const preserved = runProductionSave(saved, omitted);
    check(preserved.response.success === true && JSON.parse(preserved.writes[0].row.value).use_intimacy_arrangements === true,
        'An older client omitting the field preserves a saved opt-in');
    const legacyOmitted = runProductionSave({ enabled: true, eligible_types: ['romantic'] }, omitted);
    check(legacyOmitted.response.success === true && JSON.parse(legacyOmitted.writes[0].row.value).use_intimacy_arrangements === false,
        'A legacy config without the field keeps the new option off');

    const malformed = runProductionSave(saved, { ...trueRequest.post, use_intimacy_arrangements: 'True' });
    check(malformed.response.success === false && malformed.writes.length === 0,
        'Malformed toggle values are rejected without writing');

    const policy = JSON.parse(phpRun('normalize', { cases: { missing: { read_status: 'missing' } } }));
    check(policy.missing.use_intimacy_arrangements === false, 'Shared policy normalizer defaults old/missing config to off');

    const sourceHashes = {};
    for (const file of ['config_manager.php', 'config_section_reltypes.php', 'open_mode_policy.php']) {
        const { createHash } = await import('node:crypto');
        sourceHashes[file] = createHash('sha256').update(readFileSync(path.join(repo, file))).digest('hex');
    }
    console.log(JSON.stringify({ checks, failures: 0, source_sha256: sourceHashes }, null, 2));
} finally {
    const base = realpathSync(tempBase);
    const target = realpathSync(runtimeRoot);
    const relative = path.relative(base, target);
    assert.ok(relative && !relative.startsWith('..') && !path.isAbsolute(relative), 'Refusing to remove temp data outside SHARMAT_TEST_TMP');
    rmSync(target, { recursive: true, force: true });
}
