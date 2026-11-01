import { test as base, expect } from '@playwright/test';
import { ADMIN, API_PORTS } from './environment.mjs';

export { expect };

export const POSTS = '/blog-admin/resource/blog-post-resource';

// Every spec fails on uncaught browser errors and server failures, even when its own assertions pass.
export const test = base.extend({
    page: async ({ page }, use) => {
        const problems = [];
        page.on('pageerror', (error) => problems.push(`pageerror: ${error.message}`));
        page.on('response', (response) => {
            if (response.status() >= 500) {
                problems.push(`${response.status()} ${response.request().method()} ${response.url()}`);
            }
        });

        await use(page);

        expect(problems).toEqual([]);
    },
});

export async function signIn(page, panel, { username, password }) {
    await page.goto(`${panel.startsWith('http') ? panel : `/${panel}`}/login`);
    await page.locator('input[name=username]').fill(username);
    await page.locator('input[name=password]').fill(password);
    await page.getByRole('button', { name: 'Log in' }).click();
}

export async function signInToHub(page) {
    await signIn(page, 'admin', ADMIN);
    await expect(page).toHaveURL(/\/admin$/);
}

export function visibleTable(page) {
    return page.locator('main table:visible').first();
}

export function row(page, text) {
    return visibleTable(page).locator(':scope > tbody > tr').filter({ hasText: text }).first();
}

export async function submitMainForm(page) {
    await page.locator('main form').first().locator('button[type=submit]').first().click();
}

export function blogApi(path) {
    return `http://127.0.0.1:${API_PORTS.blog}/v1${path}`;
}

export async function createPost(request, fields) {
    const response = await request.post(blogApi('/posts'), {
        data: { body: 'E2E body', status: 'draft', category_id: 1, ...fields },
    });
    expect(response.ok()).toBe(true);

    return unwrap(await response.json());
}

export async function readPost(request, id) {
    return unwrap(await (await request.get(blogApi(`/posts/${id}`))).json());
}

function unwrap(payload) {
    return payload.data ?? payload;
}
