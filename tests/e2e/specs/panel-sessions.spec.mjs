import { UPSTREAM } from '../environment.mjs';
import { expect, signIn, signInToHub, test } from '../support.mjs';

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
