import { API_PORTS } from '../environment.mjs';
import { expect, signInToHub, test } from '../support.mjs';

test('a new service goes from probe to a working panel in the browser', async ({ page }) => {
    await signInToHub(page);
    await page.locator('main').getByRole('link', { name: 'Add service' }).click();
    await expect(page.locator('main')).toContainText('API discovery');
    await expect(page.getByRole('heading', { level: 1 })).toHaveText('Add service');

    await page.locator('#base_url').fill(`http://127.0.0.1:${API_PORTS.blog}/v1`);
    await page.locator('#service_name').fill('wizard');
    await page.locator('#resource_name').fill('post');
    await page.locator('#endpoint_paths').fill('/posts');
    await page.getByRole('button', { name: 'Probe API' }).click();

    await page.getByRole('button', { name: 'Generate service and resource' }).click();
    await page.getByRole('link', { name: 'Open generated panel' }).click();

    await expect(page).toHaveURL(/\/wizard-admin/);
    await page.locator('aside').getByRole('link', { name: 'Posts' }).click();
    await expect(page.locator('main table:visible').first()).toContainText('Post 1');

    await page.goto('/admin');
    await expect(page.locator('main')).toContainText('wizard');
});

test('header authentication survives review and generates a working panel', async ({ page }) => {
    await signInToHub(page);
    await page.locator('main').getByRole('link', { name: 'Add service' }).click();
    await page.locator('#base_url').fill(`http://127.0.0.1:${API_PORTS.links}`);
    await page.locator('#service_name').fill('wizard-header');
    await page.locator('#resource_name').fill('post');
    await page.locator('#endpoint_paths').fill('/simple');
    await page.locator('#auth_type').selectOption('header');
    await page.locator('#auth_header').fill('Authorization');
    await page.locator('#api_key').fill('Bearer link-pagination-token');
    await page.getByRole('button', { name: 'Probe API' }).click();

    await expect(page.locator('#auth_header')).toHaveValue('Authorization');
    await expect(page.locator('.api-admin-instructor__code').first()).not.toContainText('link-pagination-token');
    await page.locator('#confirm_api_key').fill('Bearer link-pagination-token');
    await page.getByRole('button', { name: 'Generate service and resource' }).click();
    await page.getByRole('link', { name: 'Open generated panel' }).click();
    await expect(page).toHaveURL(/\/wizard_header-admin/);
    await page.locator('aside').getByRole('link', { name: 'Posts' }).click();
    await expect(page.locator('main table:visible').first()).toContainText('Link item 1');
});
