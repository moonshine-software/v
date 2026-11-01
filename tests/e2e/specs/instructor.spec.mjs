import { API_PORTS } from '../environment.mjs';
import { expect, signInToHub, test } from '../support.mjs';

test('a new service goes from probe to a working panel in the browser', async ({ page }) => {
    await signInToHub(page);
    await page.locator('main').getByRole('link', { name: 'Add service' }).click();
    await expect(page.locator('main')).toContainText('API discovery');

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
