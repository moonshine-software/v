import { copyFileSync, existsSync, readFileSync, rmSync } from 'node:fs';
import { join } from 'node:path';
import { STATE_FILE } from './environment.mjs';

export default async function globalTeardown() {
    if (! existsSync(STATE_FILE)) {
        return;
    }

    const { workDirectory, pids } = JSON.parse(readFileSync(STATE_FILE, 'utf8'));

    for (const pid of pids) {
        try {
            // Negative pid: artisan serve runs PHP workers as child processes.
            process.kill(-pid, 'SIGTERM');
        } catch {
            // Already stopped.
        }
    }

    const log = join(workDirectory, 'storage/logs/laravel.log');
    if (existsSync(log)) {
        copyFileSync(log, new URL('./.artifacts/server.log', import.meta.url));
    }
    rmSync(workDirectory, { recursive: true, force: true });
    rmSync(STATE_FILE, { force: true });
}
