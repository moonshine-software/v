import { expect, POSTS, row, signInToHub, test, visibleTable } from '../support.mjs';

test('a filter applied while the table is loading is not lost', async ({ page }) => {
    await signInToHub(page);

    // The first load has to finish first: its response replaces the table root the filter request belongs to.
    await page.route(/\/component\/post-index-page\//, async (route) => {
        const delay = route.request().url().includes('filter') ? 3500 : 2000;
        await new Promise((resolve) => setTimeout(resolve, delay));
        await route.continue();
    });

    await page.goto(`${POSTS}/post-index-page`);
    await page.locator('main').getByRole('link', { name: /Filters/ }).or(page.locator('main').getByRole('button', { name: /Filters/ })).first().click();
    const filters = page.locator('form:visible').filter({ has: page.locator('[name="filter[status]"]') });
    await filters.locator('[name="filter[status]"]').selectOption('published');

    const filtered = page.waitForResponse((response) => response.url().includes('/component/post-index-page/') && response.url().includes('filter'));
    await filters.locator('button[type=submit]').first().click();
    await filtered;

    await expect(row(page, 'Post 1')).toBeVisible();
    await expect(visibleTable(page).locator(':scope > tbody > tr').filter({ hasText: 'Post 2' })).toHaveCount(0);
});
