import { expect, signInToHub, test, visibleTable } from '../support.mjs';
import { API_PORTS, BASE_URL } from '../environment.mjs';

for (const mode of ['simple', 'numbered']) {
    test(`Link header ${mode} navigation stays authenticated and preserves filters, search and sort`, async ({ page }) => {
        await signInToHub(page);
        const upstreamRequests = [];
        page.on('request', request => {
            if (new URL(request.url()).port === String(API_PORTS.links)) upstreamRequests.push(request.url());
        });
        const indexUrl = `/links-admin/resource/links-${mode}-resource/${mode}-index-page`;
        await page.goto(`${indexUrl}?sort=-id&search=Link&filter%5Bstatus%5D=published`);
        const next = page.locator('main .pagination-list a[title*="Next"]');
        const previous = page.locator('main .pagination-list a[title*="Previous"]');

        await expect(visibleTable(page)).toContainText('Link item 9');
        await expect(visibleTable(page)).toContainText('Link item 7');
        await expect(previous).toHaveCount(0);
        await expect(next).toHaveAttribute('href', new RegExp(`^${BASE_URL}`));

        for (const [button, number, title] of [[next, 2, 'Link item 5'], [next, 3, 'Link item 1'], [previous, 2, 'Link item 5']]) {
            await button.click();
            await expect(page).toHaveURL(new RegExp(`${mode}-index-page.*[?&]page=${number}`));
            await expect(visibleTable(page)).toContainText(title);
            const url = new URL(page.url());
            expect(url.origin).toBe(BASE_URL);
            expect(url.searchParams.get('filter[status]')).toBe('published');
            expect(url.searchParams.get('search')).toBe('Link');
            expect(url.searchParams.get('sort')).toBe('-id');
            expect(url.searchParams.has('api_key')).toBe(false);
            if (number === 3) await expect(next).toHaveCount(0);
        }
        await expect(visibleTable(page)).not.toContainText('Link item 6');
        expect(upstreamRequests).toEqual([]);
    });
}
