#!/usr/bin/env node
import { spawn, spawnSync } from 'node:child_process';
import { randomBytes } from 'node:crypto';
import { existsSync, readFileSync, writeFileSync, mkdirSync, renameSync, copyFileSync, chmodSync, statSync, realpathSync } from 'node:fs';
import { homedir } from 'node:os';
import path from 'node:path';
import { createInterface } from 'node:readline/promises';
import { pathToFileURL } from 'node:url';
import { createServer } from 'node:net';

const REPOSITORY = 'https://github.com/moonshine-software/v.git';
const STATE_DIR = '.moonshine-install';
const CLIENTS = ['Claude Code', 'Codex', 'Cursor', 'Gemini CLI', 'Windsurf'];

export function execute(command, args, { capture = false, cwd, input, environment, timeout } = {}) {
    // npm-installed CLIs are .cmd files on Windows. PowerShell literal arguments
    // avoid cmd.exe interpreting characters in installation paths or JSON.
    if (process.platform === 'win32' && /\.(cmd|bat)$/i.test(command)) {
        const quote = value => `'${value.replaceAll("'", "''")}'`;
        const script = `& ${[command, ...args].map(quote).join(' ')}; exit $LASTEXITCODE`;
        args = ['-NoProfile', '-NonInteractive', '-EncodedCommand', Buffer.from(script, 'utf16le').toString('base64')];
        command = 'powershell.exe';
    }
    const result = spawnSync(command, args, {
        cwd, encoding: 'utf8', input, env: environment, timeout,
        stdio: input !== undefined ? ['pipe', 'pipe', 'pipe'] : capture ? 'pipe' : 'inherit',
    });
    if (result.error || result.status !== 0) {
        throw new Error(`${path.basename(command)} failed${result.status === null ? '' : ` (${result.status})`}. ${result.error?.message ?? result.stderr?.trim() ?? ''}`);
    }
    return result.stdout?.trim() ?? '';
}

export function findExecutable(name, env = process.env, platform = process.platform) {
    const extensions = platform === 'win32' ? ['.exe', '.cmd', '.bat', ''] : [''];
    for (const directory of (env.PATH ?? '').split(platform === 'win32' ? ';' : ':')) {
        if (!directory) continue;
        for (const extension of extensions) {
            const candidate = path.resolve(directory.replace(/^"|"$/g, ''), name + extension);
            if (existsSync(candidate) && statSync(candidate).isFile()) return candidate;
        }
    }
    throw new Error(`${name} is not on PATH. Install it, open a new terminal, and run the installer again.`);
}

export function parseSelection(value) {
    if (value.trim().toLowerCase() === 'none') return [];
    if (value.trim().toLowerCase() === 'all') return CLIENTS.map((_, index) => index);
    const parts = value.trim().split(/[ ,]+/);
    if (parts.some(part => !/^[1-5]$/.test(part))) throw new Error('Choose numbers 1–5, separated by commas, or all / none.');
    return [...new Set(parts.map(part => Number(part) - 1))];
}

export function validatePort(value) {
    if (!/^\d+$/.test(String(value)) || Number(value) < 1024 || Number(value) > 65535) {
        throw new Error('Use a port between 1024 and 65535.');
    }
    return Number(value);
}

export function supportedCompose(version) {
    const match = version.match(/^v?(\d+)\.(\d+)\.(\d+)(?:[-+].*)?$/);
    if (!match) return false;
    const [major, minor, patch] = match.slice(1).map(Number);
    return major > 2 || (major === 2 && (minor > 38 || (minor === 38 && patch >= 2)));
}

export function localOverride() {
    return { services: Object.fromEntries(['init', 'migrate', 'api-admin-fpm', 'queue', 'scheduler'].map(name => [name, {
        environment: { APP_ENV: 'local', SESSION_SECURE_COOKIE: 'false' },
        extra_hosts: ['host.docker.internal:host-gateway'],
    }])) };
}

export function composeArgs(root, context) {
    return ['--context', context, 'compose', '--env-file', path.join(root, STATE_DIR, 'runtime.env'),
        '-f', path.join(root, 'docker', 'compose.production.yml'),
        '-f', path.join(root, STATE_DIR, 'compose.local.json')];
}

export function serverConfig(root, context, docker) {
    return { command: docker, args: [...composeArgs(root, context), 'exec', '-T', 'api-admin-fpm', 'php', 'artisan', 'api-admin:mcp-server'] };
}

export function clientPath(index, home = homedir(), env = process.env) {
    const paths = [
        path.join(env.CLAUDE_CONFIG_DIR || home, '.claude.json'),
        path.join(env.CODEX_HOME || path.join(home, '.codex'), 'config.toml'),
        path.join(home, '.cursor', 'mcp.json'),
        path.join(home, '.gemini', 'settings.json'),
        path.join(home, '.codeium', 'windsurf', 'mcp_config.json'),
    ];
    return paths[index];
}

export function writePrivate(file, content) {
    mkdirSync(path.dirname(file), { recursive: true, mode: 0o700 });
    const temporary = `${file}.${randomBytes(6).toString('hex')}.tmp`;
    writeFileSync(temporary, content, { mode: 0o600, flag: 'wx' });
    renameSync(temporary, file);
    chmodSync(file, 0o600);
}

export function backup(file) {
    if (!existsSync(file)) return;
    const target = `${file}.moonshine-backup-${Date.now()}-${randomBytes(3).toString('hex')}`;
    copyFileSync(file, target);
    chmodSync(target, 0o600);
    return target;
}

function jsonObject(value) {
    return value !== null && typeof value === 'object' && !Array.isArray(value);
}

export async function mergeJsonConfig(file, name, config, confirm) {
    const original = existsSync(file) ? readFileSync(file, 'utf8') : null;
    let document;
    try {
        document = original === null ? {} : JSON.parse(original.replace(/^\uFEFF/, ''));
    } catch {
        throw new Error(`Cannot parse ${file}. Existing configuration was left untouched. Use the exported MCP snippet to add the server manually.`);
    }
    if (!jsonObject(document) || (document.mcpServers !== undefined && !jsonObject(document.mcpServers))) {
        throw new Error(`Invalid mcpServers configuration in ${file}; file left untouched.`);
    }
    const previous = document.mcpServers?.[name];
    if (JSON.stringify(previous) === JSON.stringify(config)) return 'already configured';
    if (previous !== undefined && !await confirm(`Replace MCP server "${name}" in ${file}?`)) return 'skipped';
    document.mcpServers = { ...document.mcpServers, [name]: config };
    if ((existsSync(file) ? readFileSync(file, 'utf8') : null) !== original) {
        throw new Error(`${file} changed during setup. Close the client and retry.`);
    }
    const saved = backup(file);
    writePrivate(file, `${JSON.stringify(document, null, 2)}\n`);
    return saved ? `configured; backup: ${saved}` : 'configured';
}

export async function configureCodex(file, name, config, confirm, run = execute, command = findExecutable('codex')) {
    // Let Codex parse and update its TOML, preserving unrelated configuration.
    const servers = JSON.parse(run(command, ['mcp', 'list', '--json'], { capture: true }));
    if (!Array.isArray(servers)) throw new Error('Unexpected response from codex mcp list --json.');
    const existing = servers.find(server => server.name === name);
    if (existing && !await confirm(`Replace Codex MCP server "${name}"?`)) return 'skipped';
    const saved = backup(file);
    run(command, ['mcp', 'add', name, '--', config.command, ...config.args], { capture: true });
    return saved ? `configured; backup: ${saved}` : 'configured';
}

async function askValid(prompt, label, fallback, validate) {
    while (true) {
        const value = (await prompt.question(`${label}${fallback !== undefined ? ` [${fallback}]` : ''}: `)).trim() || fallback;
        try { return validate(value); } catch (error) { console.error(error.message); }
    }
}

async function assertPortAvailable(port) {
    await new Promise((resolve, reject) => {
        const server = createServer();
        server.once('error', () => reject(new Error(`Port ${port} is already in use. Choose another port.`)));
        server.listen(port, '127.0.0.1', () => server.close(resolve));
    });
}

export function checkPrerequisites(run = execute) {
    const [major, minor] = process.versions.node.split('.').map(Number);
    if (major < 22 || (major === 22 && minor < 12)) throw new Error('Node.js 22.12+ is required (Node.js 24 recommended).');
    const git = findExecutable('git');
    const docker = findExecutable('docker');
    run(git, ['--version'], { capture: true });
    const context = run(docker, ['context', 'show'], { capture: true });
    const inspect = JSON.parse(run(docker, ['context', 'inspect', context], { capture: true }));
    if (!/^(unix|npipe):\/\//.test(inspect[0]?.Endpoints?.docker?.Host ?? '')) {
        throw new Error('Choose a local Docker context. This installer binds the panel to this computer’s loopback interface.');
    }
    if (process.env.DOCKER_HOST || process.env.DOCKER_TLS_VERIFY) {
        throw new Error('Unset DOCKER_HOST / DOCKER_TLS_VERIFY and select a local Docker context before installation.');
    }
    const serverVersion = run(docker, ['--context', context, 'version', '--format', '{{.Server.Version}}'], { capture: true });
    if (Number(serverVersion.split('.')[0]) < 26) throw new Error('Docker Engine 26+ is required.');
    const version = run(docker, ['--context', context, 'compose', 'version', '--short'], { capture: true });
    if (!supportedCompose(version)) {
        throw new Error('Docker Compose 2.38.2+ is required. Update Docker Desktop / the Compose plugin.');
    }
    return { git, docker, context };
}

export function repositoryAccess(git, run = execute) {
    run(git, ['-c', 'credential.interactive=false', 'ls-remote', '--exit-code', REPOSITORY, 'HEAD'], {
        capture: true, timeout: 30000, environment: { ...process.env, GIT_TERMINAL_PROMPT: '0' },
    });
    return REPOSITORY;
}

export function initializeInstallation(root, port, tools, run = execute) {
    const stateFile = path.join(root, STATE_DIR, 'state.json');
    if (existsSync(stateFile)) {
        const state = JSON.parse(readFileSync(stateFile, 'utf8'));
        if (state.version !== 1 || state.root !== realpathSync(root) || state.context !== tools.context) {
            throw new Error('The installation path or Docker context changed. Use the original location and context to resume.');
        }
        if (!existsSync(path.join(root, STATE_DIR, 'runtime.env'))) throw new Error('Installation secrets are missing. Restore runtime.env from backup; do not generate a new APP_KEY.');
        return state;
    }
    if (existsSync(root)) throw new Error('The installation directory already exists. Choose a new directory, or an installation created by this wizard.');
    const repository = repositoryAccess(tools.git, run);
    run(tools.git, ['-c', 'core.autocrlf=false', 'clone', '--depth', '1', '--', repository, root]);
    const commit = run(tools.git, ['-C', root, 'rev-parse', 'HEAD'], { capture: true });
    const id = randomBytes(5).toString('hex');
    const state = { version: 1, root: realpathSync(root), context: tools.context, port, commit, project: `moonshine-${id}`, adminCreated: false };
    const env = {
        COMPOSE_PROJECT_NAME: state.project,
        APP_IMAGE: `moonshine-v-app:${commit}`, WEB_IMAGE: `moonshine-v-web-local:${commit}`,
        APP_KEY: `base64:${randomBytes(32).toString('base64')}`,
        APP_URL: `http://127.0.0.1:${port}`, APP_PORT: port,
        DB_PASSWORD: randomBytes(32).toString('hex'), REDIS_PASSWORD: randomBytes(32).toString('hex'),
        API_ADMIN_HOSTED_MODE: 'local', API_ADMIN_HOSTED_MCP_ENABLED: 'false',
    };
    writePrivate(path.join(root, STATE_DIR, 'runtime.env'), Object.entries(env).map(([key, value]) => `${key}=${value}`).join('\n') + '\n');
    writePrivate(path.join(root, STATE_DIR, 'compose.local.json'), JSON.stringify(localOverride(), null, 2) + '\n');
    writePrivate(stateFile, JSON.stringify(state, null, 2) + '\n');
    return state;
}

export function deploy(root, state, tools, run = execute) {
    const commit = run(tools.git, ['-C', root, 'rev-parse', 'HEAD'], { capture: true });
    if (commit !== state.commit) throw new Error('The checkout changed since installation. Use the production upgrade guide; this wizard only resumes the original checkout.');
    const compose = composeArgs(root, state.context);
    run(tools.docker, [...compose, 'config', '--quiet']);
    for (const [target, name] of [['production', 'app'], ['web-local', 'web-local']]) {
        run(tools.docker, ['--context', state.context, 'build', '--target', target, '-f', path.join(root, 'docker', 'Dockerfile'), '-t', `moonshine-v-${name}:${state.commit}`, root]);
    }
    run(tools.docker, [...compose, 'up', '-d', '--wait', '--wait-timeout', '240']);
    run(tools.docker, [...compose, 'exec', '-T', 'api-admin-fpm', 'php', 'docker/health.php']);
}

export function hasAdministrator(root, context, docker, run = execute) {
    const script = "require 'vendor/autoload.php'; $app = require 'bootstrap/app.php'; $app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap(); echo MoonShine\\Laravel\\MoonShineAuth::getModel()::query()->exists() ? 'yes' : 'no';";
    return run(docker, [...composeArgs(root, context), 'exec', '-T', 'api-admin-fpm', 'php', '-r', script], { capture: true }) === 'yes';
}

export function verifyMcp(config, timeout = 30000) {
    return new Promise((resolve, reject) => {
        const child = spawn(config.command, config.args, { stdio: ['pipe', 'pipe', 'pipe'] });
        let buffer = '';
        let settled = false;
        const finish = error => {
            if (settled) return;
            settled = true;
            clearTimeout(timer);
            child.stdin.end();
            child.kill();
            if (error) reject(error); else resolve();
        };
        const timer = setTimeout(() => finish(new Error('MCP handshake timed out. Check the application logs.')), timeout);
        child.on('error', error => finish(error));
        child.stdin.on('error', error => finish(error));
        child.on('exit', () => finish(new Error('MCP exited before returning its tool list.')));
        child.stderr.resume();
        child.stdout.on('data', chunk => {
            buffer += chunk.toString();
            if (buffer.length > 4 * 1024 * 1024) return finish(new Error('MCP response exceeded the verification limit.'));
            let newline;
            while ((newline = buffer.indexOf('\n')) !== -1) {
                const line = buffer.slice(0, newline);
                buffer = buffer.slice(newline + 1);
                let message;
                try { message = JSON.parse(line); } catch { return finish(new Error('MCP emitted invalid JSON on stdout.')); }
                if (message.error) return finish(new Error(`MCP request failed: ${message.error.message}`));
                if (message.id === 1 && message.result?.protocolVersion) {
                    child.stdin.write(JSON.stringify({ jsonrpc: '2.0', method: 'notifications/initialized' }) + '\n');
                    child.stdin.write(JSON.stringify({ jsonrpc: '2.0', id: 2, method: 'tools/list', params: {} }) + '\n');
                }
                if (message.id === 2) {
                    const names = message.result?.tools?.map(tool => tool.name) ?? [];
                    return finish(names.includes('register_service') && names.includes('create_resource') ? undefined : new Error('MCP tool list is missing the generation tools.'));
                }
            }
        });
        child.stdin.write(JSON.stringify({ jsonrpc: '2.0', id: 1, method: 'initialize', params: {
            protocolVersion: '2024-11-05', capabilities: {}, clientInfo: { name: 'moonshine-installer', version: '1.0.0' },
        } }) + '\n');
    });
}

export async function main() {
    if (process.argv.includes('--help')) {
        console.log('moonshine-v-install\nInteractive local Docker installation and MCP setup for Claude Code, Codex, Cursor, Gemini CLI and Windsurf.\nRequires Node.js 22.12+, Git, Docker Engine 26+ and Compose 2.38.2+.\nRun again with the same directory to resume or configure additional clients.\nFrom an installed checkout: node scripts/install.mjs --status | --start | --stop | --logs');
        return;
    }
    const lifecycle = { '--status': ['ps'], '--start': ['up', '-d', '--wait', '--wait-timeout', '240'], '--stop': ['stop'], '--logs': ['logs', '--tail', '100', '-f'] };
    const action = process.argv[2];
    if (Object.hasOwn(lifecycle, action)) {
        const root = process.cwd();
        const state = JSON.parse(readFileSync(path.join(root, STATE_DIR, 'state.json'), 'utf8'));
        if (state.root !== realpathSync(root)) throw new Error('Run lifecycle commands from the original installation directory.');
        execute(findExecutable('docker'), [...composeArgs(root, state.context), ...lifecycle[action]]);
        return;
    }
    if (action) throw new Error(`Unknown option: ${action}. Use --help.`);
    if (!process.stdin.isTTY) throw new Error('Run this installer in an interactive terminal (do not pipe its standard input).');
    console.log('\nMoonShine API Admin — local installation\n');
    const tools = checkPrerequisites();
    const prompt = createInterface({ input: process.stdin, output: process.stdout });
    const confirm = async label => /^(y|yes)$/i.test((await prompt.question(`${label} [y/N]: `)).trim());
    let root;
    try {
        root = await askValid(prompt, 'Installation directory', path.join(homedir(), 'moonshine-v'), value => {
            const resolved = path.resolve(value.replace(/^~(?=$|[\\/])/, homedir()));
            if (existsSync(resolved) && !existsSync(path.join(resolved, STATE_DIR, 'state.json'))) throw new Error('Choose a new directory; existing files will not be overwritten.');
            return resolved;
        });
        const existing = existsSync(path.join(root, STATE_DIR, 'state.json'));
        const port = existing ? undefined : await askValid(prompt, 'Local HTTP port', '8080', validatePort);
        console.log('\nMCP clients (select several, e.g. 1,2,3):');
        CLIENTS.forEach((client, index) => console.log(`  ${index + 1}. ${client}`));
        const selected = await askValid(prompt, 'Clients (numbers / all / none)', 'none', parseSelection);
        if (selected.includes(1)) findExecutable('codex');
        console.log(`\nInstall in: ${root}\nDocker context: ${tools.context}\nClients: ${selected.map(index => CLIENTS[index]).join(', ') || 'none'}\nThe application uses local HTTP; public deployments require the production guide.\nClose selected clients before updating their settings.`);
        if (!await confirm('Continue with installation?')) return;
        if (!existing) await assertPortAvailable(port);
        const state = initializeInstallation(root, port, tools);
        console.log(`\nBuilding application and starting ${state.project}. The first build can take several minutes.\n`);
        // readline must release stdin while Artisan reads the administrator password.
        prompt.pause();
        process.stdin.setRawMode?.(false);
        deploy(root, state, tools);
        if (!hasAdministrator(root, state.context, tools.docker)) {
            console.log('\nCreate the panel administrator (password input is hidden):\n');
            execute(tools.docker, [...composeArgs(root, state.context), 'exec', 'api-admin-fpm', 'php', 'artisan', 'moonshine:user']);
            if (!hasAdministrator(root, state.context, tools.docker)) throw new Error('No administrator was created. Run setup again and complete all administrator fields.');
        }
        state.adminCreated = true;
        writePrivate(path.join(root, STATE_DIR, 'state.json'), JSON.stringify(state, null, 2) + '\n');
        process.stdin.setRawMode?.(true);
        prompt.resume();
        const config = serverConfig(root, state.context, tools.docker);
        await verifyMcp(config);
        console.log('MCP handshake and generation tool discovery passed.');
        const name = state.project;
        const snippet = path.join(root, STATE_DIR, 'mcp.json');
        writePrivate(snippet, JSON.stringify({ mcpServers: { [name]: config } }, null, 2) + '\n');
        const failures = [];
        for (const index of selected) {
            try {
                const file = clientPath(index);
                const result = index === 1
                    ? await configureCodex(file, name, config, confirm)
                    : await mergeJsonConfig(file, name, index === 0 ? { type: 'stdio', ...config } : config, confirm);
                console.log(`${CLIENTS[index]}: ${result}`);
            } catch (error) {
                failures.push(CLIENTS[index]);
                console.error(`${CLIENTS[index]}: ${error.message}`);
            }
        }
        console.log(`\nPanel: http://127.0.0.1:${state.port}/admin\nMCP server: ${name}\nManual MCP configuration: ${snippet}\nRestart selected clients and approve the MCP connection there.\nKeep ${path.join(root, STATE_DIR)}: it contains the encryption key and database credentials.\nLifecycle and backups: ${path.join(root, 'docs', 'installation.md')}\n`);
        if (failures.length) throw new Error(`Application is running, but MCP setup failed for: ${failures.join(', ')}. Fix the reported issue and rerun with the same directory.`);
    } catch (error) {
        if (root) console.error(`Installation directory: ${root}. Existing application data and credentials have been preserved.`);
        throw error;
    } finally {
        prompt.close();
    }
}

if (process.argv[1] && existsSync(process.argv[1]) && import.meta.url === pathToFileURL(realpathSync(process.argv[1])).href) {
    main().catch(error => { console.error(`\nSetup failed: ${error.message}`); process.exitCode = 1; });
}
