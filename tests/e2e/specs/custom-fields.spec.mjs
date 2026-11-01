import { expect, signInToHub, test, visibleTable } from '../support.mjs';

// Provisioning creates and updates E2eBrandName, then creates and deletes E2eRemoved.
// The application starts from the config those tools leave behind.
test('a panel renders a custom field after other field tools changed the config', async ({ page }) => {
    await signInToHub(page);
    await page.goto('/shop-admin/resource/shop-brand-resource/brand-index-page');

    await expect(visibleTable(page)).toContainText('updated:Acme Corp');
    await expect(visibleTable(page)).not.toContainText('created:');
});
