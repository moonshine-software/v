import { HOSTED_URL } from '../environment.mjs';
import { expect, signIn, test } from '../support.mjs';

const MEMBER = { username: 'hosted-member@example.test', password: 'hosted-member-password' };
const HUB = `${HOSTED_URL}/t/acme/admin`;

async function signInAsMember(page) {
    await signIn(page, `${HOSTED_URL}/t/acme/admin`, MEMBER);
    await expect(page).toHaveURL(HUB);
}

test('hosted login, hub and profile preserve the tenant in every URL', async ({ page }) => {
    await signInAsMember(page);
    await expect(page.locator('main')).toContainText('Hosted OAuth');
    await page.goto(`${HUB}/page/profile-page`);
    await expect(page.locator('main input[name=name]')).toHaveValue('Tenant Member');
});

test('a tenant member cannot read or mutate platform users and roles', async ({ page }) => {
    await signInAsMember(page);
    const csrf = await page.locator('meta[name=csrf-token]').getAttribute('content');
    expect(csrf).toBeTruthy();
    for (const resource of ['moon-shine-user-resource', 'moon-shine-user-role-resource']) {
        const list = await page.request.get(`${HUB}/resource/${resource}/crud`, { headers: { Accept: 'application/json' } });
        expect(list.status()).toBe(403);
        const update = await page.request.put(`${HUB}/resource/${resource}/crud/1`, {
            headers: { 'X-CSRF-TOKEN': csrf, Accept: 'application/json' },
            data: {
                name: 'Changed by tenant', email: 'hosted-owner@example.test', moonshine_user_role_id: 1,
                password: 'changed-password', password_confirmation: 'changed-password', '_without-redirect': true,
            },
        });
        expect(update.status()).toBe(403);
        const index = resource.replace('-resource', '-index-page');
        const response = await page.goto(`${HUB}/resource/${resource}/${index}`);
        expect(response.status()).toBe(403);
    }
    await page.context().clearCookies();
    await signIn(page, `${HOSTED_URL}/t/acme/admin`, {
        username: 'hosted-owner@example.test', password: 'hosted-owner-password',
    });
    // Authentication succeeds with the original password; membership still denies the owner this tenant.
    await expect(page).toHaveURL(HUB);
    await expect(page.locator('body')).toContainText('403');
});

test('hosted OAuth completes PKCE login, restores the session and renders the configured layout', async ({ page }) => {
    await page.goto(`${HOSTED_URL}/t/acme/hosted-oauth-admin/login`);
    await page.getByRole('button', { name: 'Sign in with OAuth' }).click();
    await expect(page.getByRole('heading', { name: 'E2E Identity Provider' })).toBeVisible();
    await page.getByLabel('Username').fill('upstream');
    await page.getByLabel('Password').fill('upstream-password');
    await page.getByRole('button', { name: 'Authorize' }).click();
    await expect(page).toHaveURL(`${HOSTED_URL}/t/acme/hosted-oauth-admin`);
    await expect(page.locator('body')).toContainText('Hosted OAuth');
    const tasks = page.locator('aside').getByRole('link', { name: 'Tasks', exact: true });
    await tasks.click();
    await expect(page.locator('main')).toContainText('Hosted OAuth task');
    await page.reload();
    await expect(page.locator('main')).toContainText('Hosted OAuth task');
    await expect(page.locator('aside img[src="/vendor/moonshine/logo-small.svg"]')).toHaveCount(1);
    await expect(page.locator('aside img[src="/vendor/moonshine/logo.svg"]')).toHaveCount(1);
});

test('PAT login masks the token, preserves its case and accepts no refresh token', async ({ page }) => {
    const panel = `${HOSTED_URL}/t/acme/hosted-pat-admin`;
    await page.goto(`${panel}/login`);
    const token = page.getByLabel(/API token/);
    await expect(token).toHaveAttribute('type', 'password');
    await expect(page.getByLabel('Refresh token (optional)', { exact: true })).not.toHaveAttribute('required');
    await token.fill('Wrong-PAT-AbC');
    await page.getByRole('button', { name: 'Log in' }).click();
    await expect(page.locator('body')).toContainText('These credentials do not match');
    await expect(token).toHaveValue('');
    await token.fill('Case-Sensitive-PAT_AbC');
    await page.getByRole('button', { name: 'Log in' }).click();
    await expect(page).toHaveURL(panel);
    await page.locator('aside').getByRole('link', { name: 'Tasks', exact: true }).click();
    await expect(page.locator('main')).toContainText('Hosted PAT task');
});
