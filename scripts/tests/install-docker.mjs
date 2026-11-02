// Opt-in integration test. Creates and removes only its own temporary stack.
import assert from 'node:assert/strict';
import { existsSync, mkdtempSync, mkdirSync, copyFileSync, readFileSync, writeFileSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import path from 'node:path';
import { randomBytes } from 'node:crypto';
import { fileURLToPath } from 'node:url';
import { execute, checkPrerequisites, initializeInstallation, deploy, composeArgs, serverConfig, hasAdministrator, verifyMcp } from '../install.mjs';

const source = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const directory = mkdtempSync(path.join(tmpdir(), 'moonshine-install-docker-'));
const root = path.join(directory, 'checkout with spaces');
const tools = checkPrerequisites();
const port = Number(process.env.INSTALLER_TEST_PORT || 18089);
let state;
try {
    state = initializeInstallation(root, port, tools, (command, args, options) => {
        if (args.includes('ls-remote')) return execute(command, ['ls-remote', '--exit-code', source, 'HEAD'], options);
        if (args.includes('clone')) {
            execute(command, ['clone', '--no-hardlinks', '--', source, root]);
            const files = execute(command, ['-C', source, 'ls-files', '-z', '--cached', '--others', '--exclude-standard'], { capture: true }).split('\0').filter(Boolean);
            const publishable = new Set(files);
            const cloned = execute(command, ['-C', root, 'ls-files', '-z'], { capture: true }).split('\0').filter(Boolean);
            for (const file of cloned) {
                if (!publishable.has(file)) rmSync(path.join(root, file), { force: true });
            }
            for (const file of files) {
                const target = path.join(root, file);
                if (existsSync(path.join(source, file))) {
                    mkdirSync(path.dirname(target), { recursive: true });
                    copyFileSync(path.join(source, file), target);
                } else {
                    rmSync(target, { force: true });
                }
            }
            // A disposable snapshot gives dirty-tree tests their own image tags.
            execute(command, ['-C', root, 'add', '--all']);
            const snapshot = execute(command, ['-C', root, 'ls-files', '-z'], { capture: true }).split('\0').filter(Boolean);
            assert.deepEqual(snapshot.sort(), files.filter(file => existsSync(path.join(source, file))).sort(), 'The snapshot must match publishable files, including staged removals');
            execute(command, ['-C', root, '-c', 'user.name=Installer Test', '-c', 'user.email=installer@example.test', '-c', 'commit.gpgsign=false', 'commit', '--no-verify', '--allow-empty', '-m', 'test: isolated installer snapshot'], { capture: true });
            return '';
        }
        return execute(command, args, options);
    });
    const localFiles = ['.idea/workspace.xml', '.vscode/settings.json', '.ai-workspace.json', '.phpactor.json', '.DS_Store', 'public/vendor/moonshine/.DS_Store'];
    for (const file of localFiles) {
        mkdirSync(path.dirname(path.join(root, file)), { recursive: true });
        writeFileSync(path.join(root, file), 'local-only installation fixture');
    }
    const originalEnvironment = readFileSync(path.join(root, '.moonshine-install', 'runtime.env'), 'utf8');
    deploy(root, state, tools);
    const compose = composeArgs(root, state.context);
    assert.equal(hasAdministrator(root, state.context, tools.docker), false);
    const password = randomBytes(24).toString('base64url');
    execute(tools.docker, [...compose, 'exec', '-T', 'api-admin-fpm', 'php', 'artisan', 'moonshine:user', '--username=installer@example.test', '--name=Installer Test', `--password=${password}`, '--no-interaction'], { capture: true });
    assert.equal(hasAdministrator(root, state.context, tools.docker), true);
    for (const file of ['.moonshine-install', ...localFiles]) {
        execute(tools.docker, [...compose, 'exec', '-T', 'api-admin-fpm', 'test', '!', '-e', `/var/www/html/${file}`]);
    }
    await verifyMcp(serverConfig(root, state.context, tools.docker));
    // Recovery restarts the web container; do not reuse its old HTTP connections.
    const login = await fetch(`http://127.0.0.1:${port}/admin/login`, { headers: { Connection: 'close' } });
    assert.equal(login.status, 200);
    const html = await login.text();
    const token = html.match(/name="_token"[^>]*value="([^"]+)"/)?.[1];
    assert.ok(token, 'Login form must contain a CSRF token');
    const cookie = login.headers.getSetCookie().map(value => value.split(';')[0]).join('; ');
    assert.ok(!login.headers.getSetCookie().some(value => /;\s*secure(?:;|$)/i.test(value)), 'Local HTTP cookies must not require HTTPS');
    const action = html.match(/<form[^>]*action="([^"]+)"/)?.[1];
    assert.ok(action, 'Login form must have an action');
    assert.equal(new URL(action, login.url).protocol, 'http:', 'Local web image must generate HTTP form URLs');
    const authenticated = await fetch(new URL(action.replaceAll('&amp;', '&'), login.url), {
        method: 'POST', redirect: 'manual', headers: { Cookie: cookie, Connection: 'close' },
        body: new URLSearchParams({ _token: token, username: 'installer@example.test', password }),
    });
    assert.equal(authenticated.status, 302);
    assert.ok(!authenticated.headers.get('location')?.includes('/login'), 'Administrator must be authenticated');
    assert.deepEqual(initializeInstallation(root, 9999, tools), state);
    assert.equal(readFileSync(path.join(root, '.moonshine-install', 'runtime.env'), 'utf8'), originalEnvironment);
    const backup = path.join(directory, 'backup with spaces');
    const recoveryEnvironment = {
        ...process.env,
        DOCKER_CONTEXT: state.context,
        PRODUCTION_ENV_FILE: path.join(root, '.moonshine-install', 'runtime.env'),
        PRODUCTION_COMPOSE_OVERRIDE: path.join(root, '.moonshine-install', 'compose.local.json'),
    };
    execute(tools.docker, [...compose, 'exec', '-T', 'api-admin-fpm', 'php', 'docker/smoke-state.php', 'write']);
    execute('bash', [path.join(root, 'docker', 'backup.sh'), backup], { environment: recoveryEnvironment });
    assert.equal((await fetch(`http://127.0.0.1:${port}/admin/login`, { headers: { Connection: 'close' } })).status, 200, 'Backup must restart the local installation');
    execute(tools.docker, [...compose, 'exec', '-T', 'api-admin-fpm', 'php', 'docker/smoke-state.php', 'damage']);
    assert.throws(() => execute(tools.docker, [...compose, 'exec', '-T', 'api-admin-fpm', 'php', 'docker/smoke-state.php', 'check'], { capture: true }));
    execute('bash', [path.join(root, 'docker', 'restore.sh'), backup], { environment: recoveryEnvironment });
    execute(tools.docker, [...compose, 'exec', '-T', 'api-admin-fpm', 'php', 'docker/smoke-state.php', 'check']);
    const restoredLogin = await fetch(`http://127.0.0.1:${port}/admin/login`, { headers: { Connection: 'close' } });
    assert.equal(restoredLogin.status, 200);
    assert.ok(!restoredLogin.headers.getSetCookie().some(value => /;\s*secure(?:;|$)/i.test(value)), 'Restore must preserve local HTTP cookies');
    const restoredAction = (await restoredLogin.text()).match(/<form[^>]*action="([^"]+)"/)?.[1];
    assert.ok(restoredAction, 'Restored login form must have an action');
    assert.equal(new URL(restoredAction, restoredLogin.url).protocol, 'http:');
    execute(tools.docker, [...compose, 'stop']);
    execute(tools.docker, [...compose, 'up', '-d', '--wait', '--wait-timeout', '240']);
    assert.equal(hasAdministrator(root, state.context, tools.docker), true);
    await verifyMcp(serverConfig(root, state.context, tools.docker));
    console.log('PASS: fresh installation, secret exclusion, administrator login, MCP discovery, backup/restore, resume and restart persistence.');
} catch (error) {
    if (state) execute(tools.docker, [...composeArgs(root, state.context), 'logs', '--tail', '60']);
    throw error;
} finally {
    if (state) execute(tools.docker, [...composeArgs(root, state.context), 'down', '--volumes', '--remove-orphans']);
    rmSync(directory, { recursive: true, force: true });
}
