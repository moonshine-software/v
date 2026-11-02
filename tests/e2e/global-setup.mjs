import { execFileSync, spawn } from 'node:child_process';
import { cpSync, mkdirSync, mkdtempSync, realpathSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { createServer } from 'node:net';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { ADMIN, API_DIRECTORIES, API_PORTS, BASE_URL, HOSTED_URL, STATE_FILE } from './environment.mjs';

const repository = realpathSync(fileURLToPath(new URL('../..', import.meta.url)));
const composer = process.env.COMPOSER_BINARY ?? 'composer';
export let activeWorkDirectory;

function run(command, args, cwd, env = {}) {
    execFileSync(command, args, { cwd, stdio: ['ignore', 'pipe', 'inherit'], env: { ...process.env, ...env } });
}

// Only files git would publish: local services of the developer stay out of the run.
function copyPublishableTree(target) {
    const files = execFileSync('git', ['ls-files', '-z', '--cached', '--others', '--exclude-standard'], {
        cwd: repository,
        maxBuffer: 64 * 1024 * 1024,
    }).toString().split('\0').filter(Boolean);

    for (const file of files) {
        const destination = join(target, file);
        mkdirSync(dirname(destination), { recursive: true });
        try {
            cpSync(join(repository, file), destination);
        } catch (error) {
            // A tracked file deleted in the working tree is simply absent from the run.
            if (error.code !== 'ENOENT') {
                throw error;
            }
        }
    }

    cpSync(join(repository, 'vendor'), join(target, 'vendor'), { recursive: true });
}

function writeEnvironment(target) {
    writeFileSync(join(target, '.env'), [
        'APP_NAME=MoonShineE2E',
        'APP_ENV=local',
        'APP_KEY=',
        'APP_DEBUG=true',
        `APP_URL=${BASE_URL}`,
        'DB_CONNECTION=sqlite',
        `DB_DATABASE=${join(target, 'database/e2e.sqlite')}`,
        'SESSION_DRIVER=file',
        'CACHE_STORE=file',
        'QUEUE_CONNECTION=sync',
        'LOG_LEVEL=error',
        '',
    ].join('\n'));
    writeFileSync(join(target, 'database/e2e.sqlite'), '');
}

export async function assertPortsAvailable(urls) {
    for (const url of urls) {
        const { hostname, port } = new URL(url);
        await new Promise((resolve, reject) => {
            const server = createServer();
            server.once('error', error => reject(new Error(`E2E address ${hostname}:${port} is unavailable. Stop the conflicting server before running tests.`, { cause: error })));
            server.listen({ host: hostname, port: Number(port), exclusive: true }, () => server.close(resolve));
        });
    }
}

export function startProcess(command, args, cwd, env = {}) {
    const child = spawn(command, args, { cwd, detached: true, stdio: 'ignore', env: { ...process.env, ...env } });
    child.unref();

    return child;
}

export async function waitFor(url, child) {
    const deadline = Date.now() + 60_000;
    let spawnError;
    const onError = error => { spawnError = error; };
    child.once('error', onError);
    const assertRunning = () => {
        if (spawnError) throw new Error(`Could not start E2E server for ${url}: ${spawnError.message}`, { cause: spawnError });
        if (child.exitCode !== null || child.signalCode !== null) {
            throw new Error(`E2E server for ${url} exited before readiness (${child.signalCode ?? child.exitCode}).`);
        }
    };
    try {
        while (Date.now() < deadline) {
            assertRunning();
            let response;
            try {
                response = await fetch(url, { redirect: 'manual', signal: AbortSignal.timeout(1000) });
            } catch {
                // Not listening yet.
            }
            assertRunning();
            if (response && response.status < 500) return;
            await new Promise((resolve) => setTimeout(resolve, 250));
        }
        throw new Error(`Timed out waiting for ${url}`);
    } finally {
        child.removeListener('error', onError);
    }
}

export default async function globalSetup() {
    await assertPortsAvailable([BASE_URL, HOSTED_URL, ...Object.values(API_PORTS).map(port => `http://127.0.0.1:${port}`)]);
    const workDirectory = realpathSync(mkdtempSync(join(tmpdir(), 'moonshine-v-e2e-')));
    activeWorkDirectory = workDirectory;
    const pids = [];
    mkdirSync(dirname(fileURLToPath(STATE_FILE)), { recursive: true });
    // Written first, so teardown cleans up even when provisioning fails.
    const saveState = () => writeFileSync(STATE_FILE, JSON.stringify({ workDirectory, pids }));
    saveState();

    copyPublishableTree(workDirectory);
    writeEnvironment(workDirectory);
    run(composer, ['dump-autoload', '-o', '-q', '--no-scripts'], workDirectory);
    run('php', ['artisan', 'key:generate', '--force', '--no-ansi'], workDirectory);
    run('php', ['artisan', 'migrate', '--force', '--no-ansi'], workDirectory);

    for (const [name, port] of Object.entries(API_PORTS)) {
        const child = startProcess('php', ['-S', `127.0.0.1:${port}`, 'server.php'], join(workDirectory, API_DIRECTORIES[name]), {
            E2E_OAUTH_REDIRECT_URI: `${HOSTED_URL}/t/acme/hosted-oauth-admin/oauth/callback`,
        });
        if (child.pid !== undefined) pids.push(child.pid);
        saveState();
        await waitFor(`http://127.0.0.1:${port}/health`, child);
    }

    run('php', ['scripts/e2e-provision.php'], workDirectory, {
        E2E_API_PORTS: JSON.stringify(API_PORTS),
        E2E_ADMIN_USER: ADMIN.username,
        E2E_ADMIN_PASSWORD: ADMIN.password,
    });

    writeFileSync(join(workDirectory, 'database/hosted-e2e.sqlite'), '');
    const hostedEnvironment = {
        APP_URL: HOSTED_URL,
        API_ADMIN_HOSTED_MODE: 'hosted',
        API_ADMIN_ALLOW_PRIVATE_UPSTREAM: 'true', // Only the isolated fixture APIs use loopback.
        API_ADMIN_GENERATED_SERVICES_PATH: join(workDirectory, 'storage/api-admin/hosted/services.php'),
        API_ADMIN_GENERATED_PANELS_PATH: join(workDirectory, 'storage/api-admin/hosted/panels.php'),
        DB_DATABASE: join(workDirectory, 'database/hosted-e2e.sqlite'),
        SESSION_COOKIE: 'moonshine_hosted_e2e',
        LOG_LEVEL: 'debug',
        E2E_OAUTH_PORT: String(API_PORTS.code),
        PHP_CLI_SERVER_WORKERS: '4',
    };
    run('php', ['artisan', 'migrate', '--force', '--no-ansi'], workDirectory, hostedEnvironment);
    run('php', ['scripts/e2e-provision-hosted.php'], workDirectory, hostedEnvironment);
    run(composer, ['dump-autoload', '-o', '-q', '--no-scripts'], workDirectory);

    const { hostname, port } = new URL(BASE_URL);
    const appServer = startProcess('php', ['artisan', 'serve', `--host=${hostname}`, `--port=${port}`, '--no-reload'], workDirectory, {
        PHP_CLI_SERVER_WORKERS: '4',
    });
    if (appServer.pid !== undefined) pids.push(appServer.pid);
    saveState();
    await waitFor(`${BASE_URL}/admin/login`, appServer);

    const hostedAddress = new URL(HOSTED_URL);
    const hostedServer = startProcess('php', ['artisan', 'serve', `--host=${hostedAddress.hostname}`, `--port=${hostedAddress.port}`, '--no-reload'], workDirectory, hostedEnvironment);
    if (hostedServer.pid !== undefined) pids.push(hostedServer.pid);
    saveState();
    await waitFor(`${HOSTED_URL}/t/acme/admin/login`, hostedServer);
}
