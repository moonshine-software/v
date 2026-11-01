import { ADMIN } from '../environment.mjs';
import { expect, signIn, signInToHub, test } from '../support.mjs';

test('the root URL leads guests to the hub login', async ({ page }) => {
    await page.goto('/');

    await expect(page).toHaveURL(/\/admin\/login$/);
});

test('a wrong password does not open the hub', async ({ page }) => {
    await signIn(page, 'admin', { username: ADMIN.username, password: 'wrong-password' });

    await expect(page).toHaveURL(/\/admin\/login$/);
    await expect(page.locator('body')).toContainText('These credentials do not match');
});

test('the hub lists every service with its real status', async ({ page }) => {
    await signInToHub(page);

    const main = page.locator('main');
    for (const title of ['Blog Admin', 'Shop Admin', 'OAuth Admin', 'Session Admin']) {
        await expect(main.getByRole('link', { name: new RegExp(`${title} Online`) })).toBeVisible();
    }
    await expect(main.getByRole('link', { name: /Local App/ })).toBeVisible();
});

test('the panel switcher names the hub and the current service', async ({ page }) => {
    await signInToHub(page);
    const title = page.locator('.api-admin-panel-switcher__title');
    await expect(title).toHaveText('Services');

    await page.goto('/blog-admin');
    await expect(title).toHaveText('Blog Admin');
});

test('the panel switcher moves between services', async ({ page }) => {
    await signInToHub(page);
    await page.goto('/blog-admin');

    await page.locator('.api-admin-panel-switcher button, .api-admin-panel-switcher [role=button]').first().click();
    await page.locator('.api-admin-panel-switcher').getByRole('link', { name: 'Shop Admin' }).click();

    await expect(page).toHaveURL(/\/shop-admin/);
    await expect(page.locator('aside')).toContainText('Products');
});
