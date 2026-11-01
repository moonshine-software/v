import { blogApi, createPost, expect, POSTS, signInToHub, test } from '../support.mjs';

const HOSTILE = '<img src=x onerror="window.__xss=true">';

test.beforeEach(async ({ page }) => {
    await signInToHub(page);
});

test('the detail page resolves every relation', async ({ page }) => {
    await page.goto(`${POSTS}/post-detail-page/1`);

    const main = page.locator('main');
    await expect(main).toContainText('Science');
    await expect(main).toContainText('John Doe');
    await expect(main).toContainText('PHP, Laravel');
});

test('a has-many record is created from the parent form', async ({ page, request }) => {
    const post = await createPost(request, { title: 'E2E has many' });

    await page.goto(`${POSTS}/post-form-page/${post.id}`);
    await page.locator('main').getByRole('button', { name: 'Add' }).or(page.locator('main').getByRole('link', { name: 'Add' })).first().click();
    const form = page.locator('form:visible').filter({ has: page.locator('[name=author_name]') });
    await form.locator('[name=author_name]').fill('E2E & Author');
    await form.locator('[name=body]').fill('E2E comment');
    await form.locator('button[type=submit]').first().click();

    await expect.poll(async () => {
        const comments = await (await request.get(blogApi(`/posts/${post.id}/comments`))).json();

        return (comments.data ?? comments).map((comment) => comment.author_name);
    }).toEqual(['E2E & Author']);
    await expect(page.locator('main')).toContainText('E2E & Author');
});

test('a has-one record is saved from the parent form', async ({ page, request }) => {
    const post = await createPost(request, { title: 'E2E has one' });

    await page.goto(`${POSTS}/post-form-page/${post.id}`);
    const form = page.locator('main form:visible').filter({ has: page.locator('[name=email]') });
    await form.locator('[name=name]').fill('E2E Author');
    await form.locator('[name=email]').fill('author@example.test');
    await form.locator('[name=bio]').fill('E2E bio');
    await form.locator('button[type=submit]').first().click();

    await expect.poll(async () => {
        const author = await (await request.get(blogApi(`/posts/${post.id}/author`))).json();

        return (author.data ?? author).name;
    }).toBe('E2E Author');
});

test('belongs-to-many selections are synced to the upstream', async ({ page, request }) => {
    const post = await createPost(request, { title: 'E2E tags' });

    await page.goto(`${POSTS}/post-form-page/${post.id}`);
    await page.locator('main form [name="tags[]"]').first().evaluate((select) => {
        for (const option of select.options) {
            option.selected = ['1', '3'].includes(option.value);
        }
    });
    await page.locator('main form').first().locator('button[type=submit]').first().click();

    await expect.poll(async () => {
        const tags = await (await request.get(blogApi(`/posts/${post.id}/tags`))).json();

        return (tags.data ?? tags).map((tag) => tag.id).sort();
    }).toEqual([1, 3]);
});

test('hostile upstream data is shown as text and never runs', async ({ page, request }) => {
    const post = await createPost(request, { title: HOSTILE, body: HOSTILE });
    const comment = await request.post(blogApi(`/posts/${post.id}/comments`), { data: { author_name: HOSTILE, body: HOSTILE } });
    expect(comment.ok()).toBe(true);
    await request.post(blogApi(`/posts/${post.id}/author`), { data: { name: HOSTILE, email: 'x@example.test', bio: HOSTILE } });

    for (const url of [`${POSTS}/post-index-page`, `${POSTS}/post-detail-page/${post.id}`, `${POSTS}/post-form-page/${post.id}`]) {
        await page.goto(url);
        await page.waitForLoadState('networkidle');

        expect(await page.evaluate(() => window.__xss ?? false)).toBe(false);
        await expect(page.locator('main img[src="x"]')).toHaveCount(0);
    }
});
