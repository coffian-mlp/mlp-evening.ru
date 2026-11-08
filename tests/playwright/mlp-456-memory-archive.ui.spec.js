const { test, expect } = require('@playwright/test');
const { execFileSync } = require('child_process');
const path = require('path');
const base = process.env.MLP_BASE_URL;
const repo = path.resolve(__dirname, '../..');
const artifacts = path.join(repo, 'docs/tests/MLP-456/archive14');
const isLocal = !!base && ['127.0.0.1', 'localhost'].includes(new URL(base).hostname);
test.skip(!isLocal, 'MLP-456 archive fixtures require the disposable localhost Docker environment');
function fixture(mode) {
  if (!base || !['127.0.0.1', 'localhost'].includes(new URL(base).hostname)) throw new Error('Explicit localhost URL required');
  return JSON.parse(execFileSync('docker', ['compose', '--env-file', path.join(artifacts, 'test.env'), '-p', 'mlp456archive14', '-f', 'docker-compose.yml', '-f', path.join(artifacts, 'compose.yml'),
    'exec', '-T', '-e', 'MLP_ARCHIVE_PW=1', 'php', 'php', 'tests/playwright/mlp-456-archive-fixture.php', mode], { cwd: repo, stdio: 'pipe' }).toString());
}
async function action(page, name, click) {
  const response = page.waitForResponse(res => res.url().endsWith('/api.php') && new URLSearchParams(res.request().postData() || '').get('action') === name);
  await click();
  return (await response).json();
}
test.use({ serviceWorkers: 'block', viewport: { width: 1440, height: 1000 } });
test('MLP-456: архив помечен, изолирован от обычной памяти и удаляется через дашборд', async ({ page, playwright }, testInfo) => {
  const state = fixture('setup');
  try {
    await page.route('https://**', route => route.abort());
    const ordinary = await playwright.request.newContext();
    try {
      expect((await (await ordinary.post(base + '/api.php', { form: { action: 'login', username: state.user_login, password: state.password } })).json()).success).toBe(true);
      const html = await (await ordinary.get(base + '/')).text();
      const csrf = html.match(/name="csrf-token" content="([^"]+)"/)[1];
      for (const name of ['get_memory', 'delete_memory']) expect((await (await ordinary.post(base + '/api.php', { form: { action: name, id: String(state.archive_id), include_archived: '1', csrf_token: csrf } })).json()).success).toBe(false);
    } finally { await ordinary.dispose(); }
    expect((await (await page.request.post(base + '/api.php', { form: { action: 'login', username: state.admin_login, password: state.password } })).json()).success).toBe(true);
    await page.goto(base + '/dashboard/', { waitUntil: 'domcontentloaded' });
    await page.locator('.nav-tile[data-target="#tab-bot"]').click();
    const row = page.locator(`tr[data-mem-id="${state.archive_id}"]`);
    await expect(row).toBeVisible();
    await expect(row.locator('.mem-archive-label')).toHaveText('архив');
    await expect(row.locator('.mem-text')).toContainText('<img');
    await expect(row.locator('.mem-text img')).toHaveCount(0);
    expect(await page.title()).not.toContain('ARCHIVE_XSS');
    const csrf = await page.locator('meta[name="csrf-token"]').getAttribute('content');
    const legacy = await (await page.request.post(base + '/api.php', { form: { action: 'get_memory', csrf_token: csrf, limit: '200' } })).json();
    expect(legacy.data.items.some(it => Number(it.id) === state.archive_id)).toBe(false);
    expect(fixture('inspect').permanent_contains_archive).toBe(false);
    expect(fixture('inspect').derived.status).toBe('ready');
    page.once('dialog', dialog => dialog.accept(state.marker + ' edited archive <b>literal</b>'));
    expect((await action(page, 'save_memory', () => row.locator('button').first().click())).success).toBe(true);
    await expect(row).toContainText('manual');
    await expect(row.locator('.mem-archive-label')).toHaveText('архив');
    await expect(row.locator('.mem-text')).toContainText('<b>literal</b>');
    expect(Number(fixture('inspect').archive.is_archived)).toBe(1);
    expect(fixture('inspect').permanent_contains_archive).toBe(false);
    const card = page.locator('.card', { has: page.locator('h3', { hasText: 'Память Лиры' }) });
    await card.screenshot({ path: path.join(artifacts, `archive-${testInfo.project.name}.png`) });
    page.once('dialog', dialog => dialog.accept());
    expect((await action(page, 'delete_memory', () => row.locator('button').last().click())).success).toBe(true);
    await expect(row).toHaveCount(0);
    expect(fixture('inspect').archive).toBeNull();
    expect(fixture('inspect').derived).toBeNull();
    const active = page.locator(`tr[data-mem-id="${state.active_id}"]`);
    await expect(active).toBeVisible();
    page.once('dialog', dialog => dialog.accept());
    expect((await action(page, 'delete_memory', () => active.locator('button').last().click())).success).toBe(true);
    await expect(active).toHaveCount(0);
  } finally { fixture('cleanup'); }
});
