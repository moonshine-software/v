import { execFileSync } from 'node:child_process';
import { readFileSync } from 'node:fs';
import { STATE_FILE } from '../environment.mjs';
import { expect, signInToHub, test, visibleTable } from '../support.mjs';

test('a rotating OAuth service keeps working after repeated application cache clears', async ({ page }) => {
    await signInToHub(page);
    const list = '/rotation-admin/resource/rotation-task-resource/task-index-page';
    await page.goto(list);
    await expect(visibleTable(page)).toContainText('Verify refresh-token rotation');

    const { workDirectory } = JSON.parse(readFileSync(STATE_FILE, 'utf8'));
    for (let attempt = 0; attempt < 2; attempt++) {
        execFileSync('php', ['artisan', 'cache:clear', '--no-ansi'], {
            cwd: workDirectory,
            env: { ...process.env, XDEBUG_MODE: 'off' },
        });
        await page.reload();
        await expect(page).toHaveURL(new RegExp(`${list}$`));
        await expect(visibleTable(page)).toContainText('Verify refresh-token rotation');
    }
});
