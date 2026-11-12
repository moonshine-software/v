import { API_PORTS, UPSTREAM } from '../environment.mjs';
import { expect, row, signIn, signInToHub, submitMainForm, test } from '../support.mjs';

const PANELS = [
    { panel: 'spa-admin', credentials: UPSTREAM.session, list: '/spa-admin/resource/spa-post-resource/post-index-page', heading: 'Session Posts' },
    { panel: 'oauth-admin', credentials: UPSTREAM.oauth, list: '/oauth-admin/resource/oauth-task-resource/task-index-page', heading: 'OAuth Tasks' },
];

for (const { panel, credentials, list, heading } of PANELS) {
    test.describe(panel, () => {
        test('visiting it asks for its own login and keeps the hub session', async ({ page }) => {
            await signInToHub(page);

            await page.goto(`/${panel}`);
            await expect(page).toHaveURL(new RegExp(`/${panel}/login$`));

            await page.goto('/admin');
            await expect(page).toHaveURL(/\/admin$/);
        });

        test('signing in opens its data and keeps the hub session', async ({ page }) => {
            await signInToHub(page);
            await signIn(page, panel, credentials);

            await page.goto(list);
            await expect(page.locator('main')).toContainText(heading);
            await expect(page.locator('main table:visible').first().locator('tbody tr').first()).toBeVisible();

            await page.goto('/admin');
            await expect(page).toHaveURL(/\/admin$/);
        });

        test('upstream credentials open this panel only', async ({ page }) => {
            await signIn(page, panel, credentials);
            await expect(page).toHaveURL(new RegExp(`/${panel}$`));

            await page.goto(list);
            await expect(page.locator('main')).toContainText(heading);

            await page.goto('/admin');
            await expect(page).toHaveURL(/\/admin\/login$/);

            await page.goto('/blog-admin');
            await expect(page).toHaveURL(/\/blog-admin\/login$/);
        });

        test('wrong upstream credentials are rejected', async ({ page }) => {
            await signIn(page, panel, { username: credentials.username, password: 'wrong-password' });

            await expect(page).toHaveURL(new RegExp(`/${panel}/login$`));

            await page.goto(`/${panel}`);
            await expect(page).toHaveURL(new RegExp(`/${panel}/login$`));
        });
    });
}

test('two panels stay signed in side by side', async ({ page }) => {
    await signIn(page, 'spa-admin', UPSTREAM.session);
    await signIn(page, 'oauth-admin', UPSTREAM.oauth);

    await page.goto('/spa-admin');
    await expect(page).toHaveURL(/\/spa-admin$/);
    await page.goto('/oauth-admin');
    await expect(page).toHaveURL(/\/oauth-admin$/);
});

test('each upstream login gets its own account, not the local administrator', async ({ page }) => {
    await signIn(page, 'spa-admin', UPSTREAM.session);
    await expect(page).toHaveURL(/\/spa-admin$/);

    await signInToHub(page);
    await page.goto('/admin/resource/moon-shine-user-resource/moon-shine-user-index-page');

    await expect(page.locator('main')).toContainText('spa-admin@api-admin.local');
});

test('session posts open existing forms and persist create, edit and delete across logins', async ({ page }) => {
    const resource = '/spa-admin/resource/spa-post-resource';
    await signIn(page, 'spa-admin', UPSTREAM.session);
    const existing = await page.goto(`${resource}/post-form-page/1`);
    expect(existing.status()).toBe(200);
    await expect(page.locator('main form [name=title]').first()).toHaveValue('Sanctum SPA login');

    await page.goto(`${resource}/post-form-page`);
    await page.locator('main form [name=title]').first().fill('E2E session post');
    await submitMainForm(page);
    await expect(page).toHaveURL(/post-form-page\/\d+$/);
    const id = page.url().split('/').pop();

    await page.locator('main form [name=title]').first().fill('E2E session post updated');
    await submitMainForm(page);
    await expect(page.locator('body')).toContainText('Saved');

    await page.context().clearCookies();
    await signIn(page, 'spa-admin', UPSTREAM.session);
    await page.goto(`${resource}/post-form-page/${id}`);
    await expect(page.locator('main form [name=title]').first()).toHaveValue('E2E session post updated');

    await page.goto(`${resource}/post-index-page`);
    await page.locator(`main table:visible a[href$="/crud/${id}"]`).click();
    await page.locator('form:visible button[type=submit]').last().click();
    await expect(row(page, 'E2E session post updated')).toHaveCount(0);
});

test('session post reads and writes retain authentication, CSRF and validation checks', async ({ request }) => {
    const api = `http://127.0.0.1:${API_PORTS.session}`;
    expect((await request.get(`${api}/posts/1`)).status()).toBe(401);
    expect((await request.post(`${api}/posts`, { data: { title: 'Unauthorized' } })).status()).toBe(401);

    await request.get(`${api}/sanctum/csrf-cookie`);
    const token = async () => (await request.storageState()).cookies.find(cookie => cookie.name === 'XSRF-TOKEN').value;
    expect((await request.post(`${api}/login`, {
        headers: { 'X-XSRF-TOKEN': await token() },
        data: { email: UPSTREAM.session.username, password: UPSTREAM.session.password },
    })).status()).toBe(200);

    expect((await request.put(`${api}/posts/1`, { data: { title: 'Missing CSRF' } })).status()).toBe(419);
    const headers = { 'X-XSRF-TOKEN': await token() };
    const invalid = await request.put(`${api}/posts/1`, { headers, data: { title: '', status: 'invalid' } });
    expect(invalid.status()).toBe(422);
    expect((await invalid.json()).errors).toHaveProperty('title');
    expect((await (await request.get(`${api}/posts/1`, { headers })).json()).data.title).toBe('Sanctum SPA login');
    expect((await request.get(`${api}/posts/999999`, { headers })).status()).toBe(404);
});
