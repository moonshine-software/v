import { test } from 'node:test';
import assert from 'node:assert/strict';
import { mkdtempSync, readFileSync, writeFileSync, existsSync, readdirSync, rmSync, mkdirSync } from 'node:fs';
import { tmpdir } from 'node:os';
import path from 'node:path';
import { parseSelection, validatePort, supportedCompose, localOverride, composeArgs, serverConfig, clientPath, mergeJsonConfig, configureCodex, repositoryAccess, initializeInstallation, deploy, verifyMcp } from '../install.mjs';

function temporary(t) {
    const directory = mkdtempSync(path.join(tmpdir(), 'moonshine-installer-'));
    t.after(() => rmSync(directory, { recursive: true, force: true }));
    return directory;
}

test('multi-select accepts several clients, deduplicates, and rejects ambiguous choices', () => {
    assert.deepEqual(parseSelection('1, 2,3 1'), [0, 1, 2]);
    assert.deepEqual(parseSelection('ALL'), [0, 1, 2, 3, 4]);
    assert.deepEqual(parseSelection('none'), []);
    for (const invalid of ['', '0', '6', '1foo', '1,all']) assert.throws(() => parseSelection(invalid));
});

test('port validation prevents environment injection and privileged / invalid ports', () => {
    assert.equal(validatePort('8080'), 8080);
    for (const invalid of ['80', '65536', '8e3', '8080\nAPP_DEBUG=true', '-1']) assert.throws(() => validatePort(invalid));
});

test('Compose version check accepts Docker Desktop suffixes and rejects old or unknown versions', () => {
    for (const version of ['2.38.2', 'v2.38.2-desktop.1', '2.39.0', '5.0.0']) assert.equal(supportedCompose(version), true);
    for (const version of ['2.38.1', '2.30.0', '1.99.0', 'unknown']) assert.equal(supportedCompose(version), false);
});

test('MCP launch uses absolute paths, the selected Docker context, and a non-TTY foreground process', () => {
    const root = path.resolve('folder with spaces & characters');
    const config = serverConfig(root, 'desktop-linux', '/absolute/docker');
    assert.equal(config.command, '/absolute/docker');
    assert.deepEqual(config.args.slice(0, 3), ['--context', 'desktop-linux', 'compose']);
    assert.ok(config.args.includes(path.join(root, '.moonshine-install', 'runtime.env')));
    assert.deepEqual(config.args.slice(-6), ['exec', '-T', 'api-admin-fpm', 'php', 'artisan', 'api-admin:mcp-server']);
    assert.ok(!config.args.includes('-d'));
});

test('local HTTP override changes cookies for all PHP processes without changing network exposure or hosted mode', () => {
    const override = localOverride();
    assert.deepEqual(Object.keys(override.services), ['init', 'migrate', 'api-admin-fpm', 'queue', 'scheduler']);
    for (const service of Object.values(override.services)) {
        assert.deepEqual(service.environment, { APP_ENV: 'local', SESSION_SECURE_COOKIE: 'false' });
        assert.deepEqual(service.extra_hosts, ['host.docker.internal:host-gateway']);
        assert.equal(service.ports, undefined);
    }
});

test('JSON registration preserves other settings and servers, backs up, and is idempotent', async t => {
    const root = temporary(t);
    const file = path.join(root, 'settings.json');
    const original = '{"theme":"dark","mcpServers":{"existing":{"command":"keep"}}}\n';
    writeFileSync(file, original);
    const config = { command: 'docker', args: ['exec', '-T', 'app'] };
    await mergeJsonConfig(file, 'moonshine-test', config, () => assert.fail('No overwrite prompt expected'));
    const parsed = JSON.parse(readFileSync(file));
    assert.equal(parsed.theme, 'dark');
    assert.deepEqual(parsed.mcpServers.existing, { command: 'keep' });
    assert.deepEqual(parsed.mcpServers['moonshine-test'], config);
    const saved = readdirSync(root).find(name => name.includes('.moonshine-backup-'));
    assert.equal(readFileSync(path.join(root, saved), 'utf8'), original);
    assert.equal(await mergeJsonConfig(file, 'moonshine-test', config, () => false), 'already configured');
    assert.equal(readdirSync(root).length, 2);
});

test('malformed / non-object configs and declined collisions remain byte-for-byte intact', async t => {
    const root = temporary(t);
    const file = path.join(root, 'mcp.json');
    for (const original of ['{bad', 'null', '[]', '{"mcpServers":[]}', '// comment\n{}']) {
        writeFileSync(file, original);
        await assert.rejects(mergeJsonConfig(file, 'moonshine', {}, () => true));
        assert.equal(readFileSync(file, 'utf8'), original);
    }
    const original = '{"mcpServers":{"moonshine":{"command":"old"}}}';
    writeFileSync(file, original);
    assert.equal(await mergeJsonConfig(file, 'moonshine', { command: 'new' }, () => false), 'skipped');
    assert.equal(readFileSync(file, 'utf8'), original);
});

test('config modifications made while confirmation is open are not overwritten', async t => {
    const file = path.join(temporary(t), 'mcp.json');
    writeFileSync(file, '{"mcpServers":{"moonshine":{}}}');
    await assert.rejects(mergeJsonConfig(file, 'moonshine', { command: 'new' }, () => {
        writeFileSync(file, '{"changed":true}');
        return true;
    }), /changed during setup/);
    assert.equal(readFileSync(file, 'utf8'), '{"changed":true}');
});

test('Codex delegates TOML editing to the native CLI and backs up existing bytes', async t => {
    const file = path.join(temporary(t), 'config.toml');
    const original = '# keep comments\nmodel = "existing"\n';
    writeFileSync(file, original);
    const calls = [];
    await configureCodex(file, 'moonshine', { command: 'docker', args: ['path with spaces'] }, () => true, (command, args) => {
        calls.push({ command, args });
        return args[1] === 'list' ? '[]' : '';
    }, 'codex');
    assert.deepEqual(calls[1].args, ['mcp', 'add', 'moonshine', '--', 'docker', 'path with spaces']);
    assert.equal(readFileSync(file, 'utf8'), original);
    assert.equal(readdirSync(path.dirname(file)).length, 2);
});

test('client paths honor custom Codex / Claude locations without changing environment variables', () => {
    assert.equal(clientPath(0, '/home/example', { CLAUDE_CONFIG_DIR: '/custom/claude' }), path.join('/custom/claude', '.claude.json'));
    assert.equal(clientPath(1, '/home/example', { CODEX_HOME: '/custom/codex' }), path.join('/custom/codex', 'config.toml'));
    assert.equal(clientPath(2, '/home/example', {}), path.join('/home/example', '.cursor', 'mcp.json'));
});

test('resume preserves encryption keys, passwords and project identity; missing keys fail closed', t => {
    const root = path.join(temporary(t), 'installation');
    const tools = { git: 'git', docker: 'docker', context: 'desktop-linux' };
    const run = (command, args) => {
        if (args.includes('clone')) mkdirSync(root);
        return args.includes('rev-parse') ? 'abcdef123456' : '';
    };
    const state = initializeInstallation(root, 8080, tools, run);
    const envFile = path.join(root, '.moonshine-install', 'runtime.env');
    const env = readFileSync(envFile, 'utf8');
    assert.match(env, /APP_KEY=base64:[A-Za-z0-9+/]{43}=/);
    assert.match(env, /API_ADMIN_HOSTED_MODE=local/);
    assert.deepEqual(initializeInstallation(root, 9999, tools, () => assert.fail('Resume must not clone or rotate keys')), state);
    assert.equal(readFileSync(envFile, 'utf8'), env);
    assert.throws(() => initializeInstallation(root, 8080, { ...tools, context: 'different' }, run), /context changed/);
    rmSync(envFile);
    assert.throws(() => initializeInstallation(root, 8080, tools, run), /secrets are missing/);
});

test('existing unrelated directories are never repurposed', t => {
    const root = temporary(t);
    assert.throws(() => initializeInstallation(root, 8080, {}, () => assert.fail()), /already exists/);
    assert.deepEqual(readdirSync(root), []);
});

test('source download uses public HTTPS and reports access failures without switching to SSH', () => {
    assert.equal(repositoryAccess('git', (command, args, options) => {
        assert.ok(args.includes('ls-remote'));
        assert.equal(options.environment.GIT_TERMINAL_PROMPT, '0');
        return 'commit HEAD';
    }), 'https://github.com/moonshine-software/v.git');
    assert.throws(() => repositoryAccess('git', () => { throw new Error('HTTPS unavailable'); }), /HTTPS unavailable/);
});

test('deployment stops on failure and never removes volumes', () => {
    const calls = [];
    const root = path.resolve('installation');
    const state = { commit: 'abc123', context: 'default' };
    assert.throws(() => deploy(root, state, { git: 'git', docker: 'docker' }, (command, args) => {
        calls.push(args);
        if (command === 'git') return 'abc123';
        if (args.includes('build')) throw new Error('build failed');
        return '';
    }), /build failed/);
    assert.ok(!calls.some(args => args.includes('up') || args.includes('down')));
    assert.deepEqual(calls[1], [...composeArgs(root, 'default'), 'config', '--quiet']);
});

test('MCP verification completes initialize and tools/list against a real child process', async t => {
    const file = path.join(temporary(t), 'mcp.cjs');
    writeFileSync(file, `require('node:readline').createInterface({ input: process.stdin }).on('line', line => {
        const request = JSON.parse(line);
        if (request.id === 1) console.log(JSON.stringify({ jsonrpc: '2.0', id: 1, result: { protocolVersion: '2024-11-05' } }));
        if (request.id === 2) console.log(JSON.stringify({ jsonrpc: '2.0', id: 2, result: { tools: [{ name: 'register_service' }, { name: 'create_resource' }] } }));
    });`);
    await verifyMcp({ command: process.execPath, args: [file] }, 3000);
});

test('MCP verification rejects early exits, invalid output and missing tools', async t => {
    const root = temporary(t);
    for (const [index, code] of ['process.exit(1)', 'console.log("bad")', 'console.log(JSON.stringify({ id: 2, result: { tools: [] } }))'].entries()) {
        const file = path.join(root, `bad-${index}.cjs`);
        writeFileSync(file, code);
        await assert.rejects(verifyMcp({ command: process.execPath, args: [file] }, 3000));
    }
});
