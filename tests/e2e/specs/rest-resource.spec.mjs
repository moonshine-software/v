import { blogApi, createPost, expect, POSTS, readPost, row, signInToHub, submitMainForm, test, visibleTable } from '../support.mjs';

test.beforeEach(async ({ page }) => {
    await signInToHub(page);
});

test('the list shows upstream records and sorts on the server', async ({ page }) => {
    await page.goto(`${POSTS}/post-index-page`);

    await expect(row(page, 'Post 1')).toContainText('Body for post 1');

    await visibleTable(page).locator('thead a:visible').first().click();

    await expect(page).toHaveURL(/sort=title/);
    await expect(row(page, 'Post 1')).toBeVisible();
});

test('filters reach the upstream API', async ({ page, request }) => {
    for (let index = 0; index < 6; index++) {
        await createPost(request, { title: `E2E page ${index}` });
    }

    await page.goto(`${POSTS}/post-index-page`);

    await page.locator('main').getByRole('link', { name: /Filters/ }).or(page.locator('main').getByRole('button', { name: /Filters/ })).first().click();
    const filters = page.locator('form:visible').filter({ has: page.locator('[name="filter[status]"]') });
    await filters.locator('[name="filter[status]"]').selectOption('published');
    await filters.locator('button[type=submit]').first().click();

    await expect(page).toHaveURL(/filter%5Bstatus%5D=published/);
    await expect(row(page, 'Post 1')).toBeVisible();
    await expect(visibleTable(page).locator(':scope > tbody > tr').filter({ hasText: 'Post 2' })).toHaveCount(0);
});

test('form values reach the upstream exactly as entered and stay escaped on screen', async ({ page, request }) => {
    const title = 'E2E Tom & Jerry <b>"bold"</b>';
    const body = 'Body & <i>markup</i>';

    await page.goto(`${POSTS}/post-form-page`);
    await page.locator('main form [name=title]').first().fill(title);
    await page.locator('main form [name=body]').first().fill(body);
    await submitMainForm(page);

    await expect(page).toHaveURL(/post-form-page\/\d+$/);
    const id = page.url().split('/').pop();
    expect(await readPost(request, id)).toMatchObject({ title, body });

    // Saving again must not encode the value a second time.
    await submitMainForm(page);
    await expect(page.locator('body')).toContainText('Saved');
    expect(await readPost(request, id)).toMatchObject({ title, body });
    await expect(page.locator('main form [name=title]').first()).toHaveValue(title);

    await page.goto(`${POSTS}/post-detail-page/${id}`);
    await expect(page.locator('main')).toContainText(title);
    await expect(page.locator('main b')).toHaveCount(0);
});

test('an edit updates the upstream record', async ({ page, request }) => {
    const post = await createPost(request, { title: 'E2E before edit' });

    await page.goto(`${POSTS}/post-form-page/${post.id}`);
    await page.locator('main form [name=title]').first().fill('E2E after edit');
    await page.locator('main form [name=status]').first().selectOption('archived');
    await submitMainForm(page);

    await expect.poll(async () => (await readPost(request, post.id)).title).toBe('E2E after edit');
    expect((await readPost(request, post.id)).status).toBe('archived');
});

test('a record is deleted after confirmation', async ({ page, request }) => {
    const post = await createPost(request, { title: 'E2E delete one' });

    await page.goto(`${POSTS}/post-index-page`);
    await page.locator(`main table:visible a[href$="/crud/${post.id}"]`).click();
    await page.locator('form:visible button[type=submit]').last().click();

    await expect.poll(async () => (await request.get(blogApi(`/posts/${post.id}`))).status()).toBe(404);
});

test('several records are deleted together', async ({ page, request }) => {
    const first = await createPost(request, { title: 'E2E delete many A' });
    const second = await createPost(request, { title: 'E2E delete many B' });

    await page.goto(`${POSTS}/post-index-page`);
    await row(page, 'E2E delete many A').locator('input[type=checkbox]').check();
    await row(page, 'E2E delete many B').locator('input[type=checkbox]').check();
    await visibleTable(page).locator('tfoot a:visible, tfoot button:visible').first().click();
    await page.locator('form:visible button[type=submit]').last().click();

    await expect.poll(async () => (await request.get(blogApi(`/posts/${first.id}`))).status()).toBe(404);
    expect((await request.get(blogApi(`/posts/${second.id}`))).status()).toBe(404);
});

test('unsafe record IDs reject the whole delete request and leave real records intact', async ({ page, request }) => {
    const post = await createPost(request, { title: 'E2E keep after unsafe delete' });
    await page.goto(`${POSTS}/post-index-page`);
    const csrf = await page.locator('meta[name=csrf-token]').getAttribute('content');
    expect(csrf).toBeTruthy();

    const result = await page.request.delete(`${POSTS}/crud`, {
        headers: { 'X-CSRF-TOKEN': csrf, 'X-Requested-With': 'XMLHttpRequest', Accept: 'application/json' },
        data: { ids: [post.id, '../users/7'] },
    });

    expect(result.status()).toBe(422);
    expect((await readPost(request, post.id)).title).toBe('E2E keep after unsafe delete');
    await page.reload();
    await expect(row(page, 'E2E keep after unsafe delete')).toBeVisible();
});

test('a relation-only resource has no standalone page', async ({ page }) => {
    const response = await page.goto('/blog-admin/resource/comment-resource/comment-index-page');

    expect(response.status()).toBe(404);
});

test('other services keep their own response format', async ({ page }) => {
    await page.goto('/shop-admin/resource/shop-product-resource/product-index-page');

    await expect(visibleTable(page)).toContainText('SKU-1-001');
});
