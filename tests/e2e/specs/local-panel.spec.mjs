import { expect, row, signInToHub, submitMainForm, test } from '../support.mjs';

const TENANTS = '/localapp-admin/resource/tenant-resource';

test.beforeEach(async ({ page }) => {
    await signInToHub(page);
});

test('the sidebar lists the panel resources and system resources only', async ({ page }) => {
    await page.goto('/localapp-admin');

    const menu = page.locator('aside');
    await expect(menu.getByRole('link', { name: 'Tenant' })).toBeVisible();
    // System resources sit in a collapsed group.
    await expect(menu.locator('a', { hasText: 'Admins' })).toHaveCount(1);
    await expect(menu.getByRole('link', { name: 'Posts' })).toHaveCount(0);
});

test('trusted local administrators can still open the native user resource', async ({ page }) => {
    await page.goto('/admin/resource/moon-shine-user-resource/moon-shine-user-index-page');
    await expect(page.locator('main')).toContainText('E2E Admin');
    const response = await page.request.get('/admin/resource/moon-shine-user-resource/crud', {
        headers: { Accept: 'application/json' },
    });
    expect(response.status()).toBe(200);
});

test('the service switcher is available and offers other services', async ({ page }) => {
    await page.goto('/localapp-admin');

    const switcher = page.locator('.api-admin-panel-switcher');
    await expect(switcher.locator('.api-admin-panel-switcher__title')).toHaveText('Local App');
    await expect(switcher.locator('.api-admin-panel-switcher__item-title', { hasText: 'Blog Admin' })).toHaveCount(1);
});

test('records are stored in the local database', async ({ page }) => {
    await page.goto('/localapp-admin');
    await page.locator('aside').getByRole('link', { name: 'Tenant' }).click();
    await expect(page).toHaveURL(new RegExp(`${TENANTS}/tenant-index-page`));

    await page.goto(`${TENANTS}/tenant-form-page`);
    await page.locator('main form [name=slug]').fill('e2e-tenant');
    await page.locator('main form [name=name]').fill('Tenant & Co');
    await submitMainForm(page);
    await expect(page).toHaveURL(/tenant-form-page\/\d+$/);
    await expect(page.locator('main form [name=name]')).toHaveValue('Tenant & Co');

    await page.goto(`${TENANTS}/tenant-index-page`);
    await expect(row(page, 'e2e-tenant')).toContainText('Tenant & Co');
});

test('validation rules from the spec are enforced', async ({ page }) => {
    await page.goto(`${TENANTS}/tenant-form-page`);
    await page.locator('main form [name=name]').fill('Without slug');
    await page.locator('main form').first().evaluate((form) => form.setAttribute('novalidate', 'novalidate'));
    await submitMainForm(page);

    await expect(page).not.toHaveURL(/tenant-form-page\/\d+$/);
    await expect(page.locator('body')).toContainText(/slug/i);
});
