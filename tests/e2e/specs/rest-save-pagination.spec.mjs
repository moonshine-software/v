import { expect, signInToHub, submitMainForm, test, visibleTable } from '../support.mjs';
import { API_PORTS } from '../environment.mjs';

const endpoint = `http://127.0.0.1:${API_PORTS.links}/save/7`;
const headers = { Authorization: 'Bearer link-pagination-token' };
const formUrl = '/links-admin/resource/links-save-resource/save-form-page/7';

test.beforeEach(async ({ page }) => {
    await signInToHub(page);
});

test('a 204 update keeps the form identity and still saves its relations', async ({ page, request }) => {
    await request.put(endpoint, { headers, data: { title: 'Original' } });
    await page.goto(formUrl);
    await page.locator('main form [name=title]').fill('No content update');
    await page.locator('main form [name="tags[]"]').evaluate(select => {
        for (const option of select.options) option.selected = ['1', '3'].includes(option.value);
    });
    await submitMainForm(page);

    await expect.poll(async () => (await (await request.get(endpoint, { headers })).json()).data.tag_ids.sort()).toEqual([1, 3]);
    await expect(page.locator('main form [name=title]')).toHaveValue('No content update');
    await page.goto(formUrl);
    await expect.poll(async () => page.locator('main form [name="tags[]"]').evaluate(select =>
        Array.from(select.selectedOptions, option => option.value).sort())).toEqual(['1', '3']);
});

test('a failed relation write shows a partial-save error without exposing the API response', async ({ page, request }) => {
    await request.put(endpoint, { headers, data: { title: 'Original' } });
    await page.goto(formUrl);
    await page.locator('main form [name=title]').fill('Fail relation');
    const [response] = await Promise.all([
        page.waitForResponse(response => new URL(response.url()).pathname.endsWith('/crud/7')),
        submitMainForm(page),
    ]);

    expect(response.status()).toBe(422);
    await expect(page.getByText('The record was saved, but this relation could not be saved.', { exact: false }).first()).toBeVisible();
    await expect(page.locator('body')).not.toContainText('private-relation-secret');
    expect((await (await request.get(endpoint, { headers })).json()).data.title).toBe('Fail relation');
});

test('cursor navigation keeps filters, search and sort across successive pages', async ({ page }) => {
    await page.goto('/cursor-admin/resource/cursor-item-resource/item-index-page?sort=-id&search=Link&filter%5Bstatus%5D=published');
    const next = page.locator('main .pagination-list a[title*="Next"]');
    await expect(visibleTable(page)).toContainText('Link item 9');

    for (const title of ['Link item 5', 'Link item 1']) {
        await next.click();
        await expect(visibleTable(page)).toContainText(title);
        const url = new URL(page.url());
        expect(url.searchParams.get('search')).toBe('Link');
        expect(url.searchParams.get('sort')).toBe('-id');
        expect(url.searchParams.get('filter[status]')).toBe('published');
        expect(url.searchParams.get('after')).toBeTruthy();
    }
    await expect(next).toHaveCount(0);
    await expect(visibleTable(page)).not.toContainText('Link item 2');
});
