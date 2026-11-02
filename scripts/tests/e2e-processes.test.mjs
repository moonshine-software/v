import { test } from 'node:test';
import assert from 'node:assert/strict';
import { once } from 'node:events';
import { createServer } from 'node:http';
import { mkdtempSync, writeFileSync, existsSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { pathToFileURL } from 'node:url';
import { assertPortsAvailable, startProcess, waitFor } from '../../tests/e2e/global-setup.mjs';
import globalTeardown from '../../tests/e2e/global-teardown.mjs';
import { STATE_FILE } from '../../tests/e2e/environment.mjs';

async function server(t, status = 200) {
    const instance = createServer((request, response) => {
        response.writeHead(status, { Connection: 'close' });
        response.end('existing server');
    });
    instance.listen(0, '127.0.0.1');
    await once(instance, 'listening');
    t.after(() => new Promise(resolve => instance.close(resolve)));
    return { instance, url: `http://127.0.0.1:${instance.address().port}` };
}

function child(t, args, command = process.execPath) {
    const instance = startProcess(command, args, process.cwd());
    t.after(() => {
        if (instance.exitCode === null && instance.pid !== undefined) instance.kill();
    });
    return instance;
}

test('E2E port preflight rejects an existing healthy server without stopping it', async t => {
    const { instance, url } = await server(t);
    await assert.rejects(assertPortsAvailable([url]), /unavailable.*conflicting server/);
    assert.equal(instance.listening, true);
    assert.equal((await fetch(url)).status, 200);
});

test('E2E port preflight releases its probe socket', async t => {
    const { instance, url } = await server(t);
    await new Promise(resolve => instance.close(resolve));
    await assertPortsAvailable([url]);
    await assertPortsAvailable([url]);
});

test('E2E teardown leaves previous run state untouched when this run never started', async () => {
    const directory = mkdtempSync(join(tmpdir(), 'moonshine-e2e-state-'));
    const state = join(directory, 'state.json');
    const original = STATE_FILE.href;
    try {
        STATE_FILE.href = pathToFileURL(state).href;
        writeFileSync(state, JSON.stringify({ workDirectory: directory, pids: [] }));
        await globalTeardown();
        assert.equal(existsSync(state), true);
        assert.equal(existsSync(directory), true);
    } finally {
        STATE_FILE.href = original;
        rmSync(directory, { recursive: true, force: true });
    }
});

test('E2E readiness rejects an exited child even when another server returns 200', { timeout: 5000 }, async t => {
    const { url } = await server(t);
    const instance = child(t, ['-e', 'process.exit(23)']);
    await once(instance, 'exit');
    await assert.rejects(waitFor(url, instance), /exited before readiness \(23\)/);
});

test('E2E readiness reports command spawn failures', { timeout: 5000 }, async t => {
    const instance = child(t, [], `/missing-e2e-command-${process.pid}`);
    await assert.rejects(waitFor('http://127.0.0.1:1', instance), /Could not start E2E server.*ENOENT/);
});

test('E2E readiness notices a child exit while its endpoint is unavailable', { timeout: 5000 }, async t => {
    const { url } = await server(t, 503);
    const instance = child(t, ['-e', 'setTimeout(() => process.exit(17), 50)']);
    await assert.rejects(waitFor(url, instance), /exited before readiness \(17\)/);
});

test('E2E readiness accepts the live child server', { timeout: 5000 }, async t => {
    const { instance, url } = await server(t);
    await new Promise(resolve => instance.close(resolve));
    const port = new URL(url).port;
    const processInstance = child(t, ['-e', `require('node:http').createServer((request, response) => response.end('ready')).listen(${port}, '127.0.0.1')`]);
    await waitFor(url, processInstance);
    assert.equal(processInstance.exitCode, null);
});
