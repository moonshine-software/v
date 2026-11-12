import { expect, signInToHub, submitMainForm, test, visibleTable } from '../support.mjs';
import { API_PORTS, BASE_URL } from '../environment.mjs';

const api = `http://127.0.0.1:${API_PORTS.links}/mixed`;
const headers = { Authorization: 'Bearer link-pagination-token' };
const resourceUrl = name => `/mixed-admin/resource/mixed-${name}-resource`;
const indexUrl = name => `${resourceUrl(name)}/${name}-index-page`;

test.beforeEach(async ({ page, request }) => {
    expect((await request.put(`${api}/state`, { headers })).ok()).toBe(true);
    await signInToHub(page);
});

for (const [name, endpoint] of [['task', 'tasks'], ['transcription', 'transcriptions'], ['notification', 'notifications']]) {
    test(`${endpoint} uses its own cursor path and keeps search, filters and sort`, async ({ page }) => {
        const opened = await page.goto(`${indexUrl(name)}?search=Mapping&filter%5Bstatus%5D=published&sort=-id`);
        expect(opened?.status()).toBe(200);
        await expect(visibleTable(page)).toContainText(`Mapping ${endpoint} 7`);
        await expect(visibleTable(page)).toContainText(`Mapping ${endpoint} 5`);
        const next = page.locator('main .pagination-list a[title*="Next"]');
        await next.click();

        await expect(visibleTable(page)).toContainText(`Mapping ${endpoint} 3`);
        await expect(visibleTable(page)).not.toContainText(`Mapping ${endpoint} 6`);
        await expect(next).toHaveCount(0);
        const url = new URL(page.url());
        expect(url.origin).toBe(BASE_URL);
        expect(url.searchParams.get('cursor')).toBeTruthy();
        expect(url.searchParams.get('search')).toBe('Mapping');
        expect(url.searchParams.get('filter[status]')).toBe('published');
        expect(url.searchParams.get('sort')).toBe('-id');
    });
}

test('a root-array resource works in the same panel as the other response formats', async ({ page }) => {
    await page.goto(`${indexUrl('permission')}?search=View&filter%5Bstatus%5D=published`);
    await expect(visibleTable(page)).toContainText('View projects');
    await expect(visibleTable(page)).not.toContainText('Edit projects');
    await expect(page.locator('main .pagination-list')).toHaveCount(0);
    for (const title of ['Mixed Tasks', 'Mixed Transcriptions', 'Mixed Notifications', 'Mixed Permissions', 'Mixed Settings']) {
        await expect(page.locator('aside').getByRole('link', { name: title, exact: true })).toBeVisible();
    }
    const panels = await page.locator('.api-admin-panel-switcher a').evaluateAll(links => links
        .map(link => new URL(link.href).pathname)
        .filter(path => path.startsWith('/mixed')));
    expect(panels).toEqual(['/mixed-admin']);
});

test('settings search and browser updates use the string primary key', async ({ page, request }) => {
    await page.goto(`${indexUrl('setting')}?search=Site`);
    await expect(visibleTable(page)).toContainText('Site title');
    await expect(visibleTable(page)).not.toContainText('Timezone');
    await expect(page.locator('main .pagination-list')).toHaveCount(0);

    const formUrl = `${resourceUrl('setting')}/setting-form-page/site_title`;
    expect((await page.goto(formUrl))?.status()).toBe(200);
    await expect(page.locator('main form [name=name]')).toHaveValue('site_title');
    await expect(page.locator('main form [name=value]')).toHaveValue('MoonShine V fixture');
    await page.locator('main form [name=value]').fill('MoonShine V mixed mapping');
    await submitMainForm(page);

    await expect.poll(async () => (await (await request.get(`${api}/state`, { headers })).json())
        .settings.find(setting => setting.name === 'site_title').value).toBe('MoonShine V mixed mapping');
    await page.goto(formUrl);
    await expect(page.locator('main form [name=value]')).toHaveValue('MoonShine V mixed mapping');
});

test('relation dropdowns inherit root-array mapping and a string primary key from their target resources', async ({ page, request }) => {
    const formUrl = `${resourceUrl('task')}/task-form-page/1`;
    expect((await page.goto(formUrl))?.status()).toBe(200);
    const setting = page.locator('main form [name=setting_name]');
    const permission = page.locator('main form [name=permission_id]');
    await expect(setting).toHaveValue('site_title');
    await expect(setting.locator('..').locator('.ts-control')).toContainText('Site title');
    await expect(permission).toHaveValue('2');
    await expect(permission.locator('..').locator('.ts-control')).toContainText('Edit projects');

    await setting.locator('..').locator('.ts-control input').fill('Timezone');
    await page.locator('.ts-dropdown:visible [data-selectable]').filter({ hasText: 'Timezone' }).click();
    await expect(setting).toHaveValue('timezone');
    await permission.locator('..').locator('.ts-control input').fill('View projects');
    await page.locator('.ts-dropdown:visible [data-selectable]').filter({ hasText: 'View projects' }).click();
    await expect(permission).toHaveValue('1');
    await submitMainForm(page);

    await expect.poll(async () => {
        const { task } = await (await request.get(`${api}/state`, { headers })).json();

        return { setting: task.setting_name, permission: String(task.permission_id) };
    }).toEqual({ setting: 'timezone', permission: '1' });
    await page.goto(formUrl);
    await expect(page.locator('main form [name=setting_name]')).toHaveValue('timezone');
    await expect(page.locator('main form [name=permission_id]')).toHaveValue('1');
});

test('has-many tables preserve explicit global endpoints and inherit each target response mapping', async ({ page, request }) => {
    const permissions = await (await request.get(`${api}/permissions`, { headers })).json();
    const transcriptions = await (await request.get(`${api}/transcriptions`, { headers })).json();
    expect(permissions).toHaveLength(2);
    expect(transcriptions.data).toHaveLength(2);
    expect(transcriptions.meta.next_cursor).toBeTruthy();

    expect((await page.goto(`${resourceUrl('task')}/task-form-page/1`))?.status()).toBe(200);
    const permissionTable = page.locator('main table[data-name="rest-has-many-permission_rows"]');
    const transcriptionTable = page.locator('main table[data-name="rest-has-many-transcription_rows"]');
    for (const permission of permissions) {
        await expect(permissionTable).toContainText(permission.label);
    }
    for (const transcription of transcriptions.data) {
        await expect(transcriptionTable).toContainText(transcription.title);
    }
    await expect(permissionTable).not.toContainText('Records not found');
    await expect(transcriptionTable).not.toContainText('Records not found');
});
