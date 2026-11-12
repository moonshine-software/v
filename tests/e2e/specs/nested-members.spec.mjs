import { expect, signInToHub, test } from '../support.mjs';
import { API_PORTS } from '../environment.mjs';

const api = `http://127.0.0.1:${API_PORTS.links}`;
const headers = { Authorization: 'Bearer link-pagination-token' };

for (const reply of ['message', 'empty', 'dirty']) {
    test(`nested member editing keeps loaded values and accepts a ${reply} success response`, async ({ page, request }) => {
        const reset = await request.put(`${api}/nested-state`, { headers, data: { reply } });
        expect(reset.ok()).toBe(true);
        await signInToHub(page);
        const opened = await page.goto('/links-admin/resource/links-nested-project-resource/nested-project-form-page/2');
        expect(opened?.status()).toBe(200);
        const [response] = await Promise.all([
            page.waitForResponse(response => response.url().includes('/rest-has-many/form') && response.request().method() === 'GET'),
            page.locator('main a[href*="rest-has-many/form"][href*="_key=17"]').click(),
        ]);
        expect(response.status()).toBe(200);
        const form = page.locator('form:visible').filter({ has: page.locator('[name=project_role]') });
        await expect(form.locator('[name=project_role]')).toHaveValue('member');
        await expect(form.locator('input[name^="model_ids["]').first()).toHaveValue('openai:gpt-4o-mini');
        await expect(form.locator('[name=assignee_id]')).toHaveValue('');

        const [saved] = await Promise.all([
            page.waitForResponse(response => response.url().includes('/rest-has-many/update')),
            form.locator('button[type=submit]').click(),
        ]);
        expect(saved.status()).toBe(200);
        const state = await (await request.get(`${api}/nested-state`, { headers })).json();
        expect(state.member.project_role).toBe('member');
        expect(state.member.assignee_id).toBeNull();
        expect(state.member.model_ids).toEqual(['openai:gpt-4o-mini']);
    });
}
