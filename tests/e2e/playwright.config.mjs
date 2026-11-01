import { defineConfig, devices } from '@playwright/test';
import { BASE_URL } from './environment.mjs';

export default defineConfig({
    testDir: './specs',
    outputDir: './.artifacts/results',
    globalSetup: './global-setup.mjs',
    globalTeardown: './global-teardown.mjs',
    // Specs share one upstream state, so they run one after another.
    fullyParallel: false,
    workers: 1,
    forbidOnly: Boolean(process.env.CI),
    timeout: 60_000,
    expect: { timeout: 10_000 },
    reporter: process.env.CI ? [['list'], ['github']] : 'list',
    use: {
        baseURL: BASE_URL,
        trace: 'retain-on-failure',
        screenshot: 'only-on-failure',
    },
    // Uses the Chrome already installed on the machine; no browser download needed.
    projects: [{ name: 'chrome', use: { ...devices['Desktop Chrome'], channel: 'chrome' } }],
});
