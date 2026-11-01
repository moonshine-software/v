import { test, expect } from '@playwright/test';
import { fileURLToPath } from 'node:url';

test('hosted realtime subscribes privately while local realtime keeps its public channel', async ({ page }) => {
    await page.addScriptTag({
        type: 'module',
        path: fileURLToPath(new URL('../../../resources/js/api-admin/realtime.js', import.meta.url)),
    });

    const calls = await page.evaluate(() => {
        const calls = [];
        const subscription = (type, channel) => {
            calls.push([type, channel]);
            return { listen: () => {} };
        };
        window.Echo = {
            private: (channel) => subscription('private', channel),
            channel: (channel) => subscription('public', channel),
        };
        const config = { enabled: true, service: 'blog', resource: 'posts', page: 'index' };
        window.ApiAdminRealtimeRefresh.register({ ...config, private: true, channels: ['api-admin.tenant.acme.blog.posts'] });
        window.ApiAdminRealtimeRefresh.register({ ...config, channels: ['api-admin.blog.posts'] });
        return calls;
    });

    expect(calls).toEqual([
        ['private', 'api-admin.tenant.acme.blog.posts'],
        ['public', 'api-admin.blog.posts'],
    ]);
});
