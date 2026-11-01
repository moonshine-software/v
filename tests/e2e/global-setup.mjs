import { execFileSync, spawn } from 'node:child_process';
import { cpSync, mkdirSync, mkdtempSync, realpathSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { ADMIN, API_DIRECTORIES, API_PORTS, BASE_URL, HOSTED_URL, STATE_FILE } from './environment.mjs';

const repository = realpathSync(fileURLToPath(new URL('../..', import.meta.url)));
const composer = process.env.COMPOSER_BINARY ?? 'composer';

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

function startProcess(command, args, cwd, env = {}) {
    const child = spawn(command, args, { cwd, detached: true, stdio: 'ignore', env: { ...process.env, ...env } });
    child.unref();

    return child.pid;
}

async function waitFor(url) {
    const deadline = Date.now() + 60_000;
    while (Date.now() < deadline) {
        try {
            if ((await fetch(url, { redirect: 'manual' })).status < 500) {
                return;
            }
        } catch {
            // Not listening yet.
        }
        await new Promise((resolve) => setTimeout(resolve, 250));
    }
    throw new Error(`Timed out waiting for ${url}`);
}

export default async function globalSetup() {
    const workDirectory = realpathSync(mkdtempSync(join(tmpdir(), 'moonshine-v-e2e-')));
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
        pids.push(startProcess('php', ['-S', `127.0.0.1:${port}`, 'server.php'], join(workDirectory, API_DIRECTORIES[name]), {
            E2E_OAUTH_REDIRECT_URI: `${HOSTED_URL}/t/acme/hosted-oauth-admin/oauth/callback`,
        }));
        saveState();
        await waitFor(`http://127.0.0.1:${port}/health`);
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
    pids.push(startProcess('php', ['artisan', 'serve', `--host=${hostname}`, `--port=${port}`, '--no-reload'], workDirectory, {
        PHP_CLI_SERVER_WORKERS: '4',
    }));
    saveState();
    await waitFor(`${BASE_URL}/admin/login`);

    const hostedAddress = new URL(HOSTED_URL);
    pids.push(startProcess('php', ['artisan', 'serve', `--host=${hostedAddress.hostname}`, `--port=${hostedAddress.port}`, '--no-reload'], workDirectory, hostedEnvironment));
    saveState();
    await waitFor(`${HOSTED_URL}/t/acme/admin/login`);
}
