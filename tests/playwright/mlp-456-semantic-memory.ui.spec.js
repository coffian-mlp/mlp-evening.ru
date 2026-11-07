const { test, expect } = require('@playwright/test');
const { execFileSync } = require('child_process');
const path = require('path');
const base = process.env.MLP_BASE_URL;
const repo = path.resolve(__dirname, '../..');
const workspace = path.join(repo, 'docs/tests/MLP-456/fix4/pw-workspace');
const override = path.join(repo, 'docs/tests/MLP-456/fix4/pw.override.yml');
let fixture;

function cli(mode) {
  if (!base || !['localhost', '127.0.0.1'].includes(new URL(base).hostname)) throw new Error('Explicit localhost URL required');
  const result = execFileSync('docker', ['compose', '-p', 'mlp456pw', '-f', path.join(workspace, 'docker-compose.yml'), '-f', override,
    'exec', '-T', '-e', 'MLP456_PW_FIXTURE=1', 'php', 'php', 'tests/playwright/mlp-456-memory-fixture.php', mode], { cwd: workspace, stdio: 'pipe' });
  return JSON.parse(result.toString());
}

async function response(page, action, perform) {
  const received = page.waitForResponse(res => res.url().endsWith('/api.php') && new URLSearchParams(res.request().postData() || '').get('action') === action).then(res => res.json());
  await perform();
  return received;
}

async function openChat(page) {
  await page.goto(base + '/chat_popup.php', { waitUntil: 'domcontentloaded' });
  const row = page.locator(`.chat-message[data-id="${fixture.message_id}"]`);
  await expect(row).toBeVisible();
  return row;
}

async function editMessage(page, row, text) {
  await row.hover();
  await row.locator('.edit-btn').click({ force: true });
  await page.locator('#chat-input').fill(text);
  return response(page, 'edit_message', () => page.locator('#chat-form').evaluate(form => form.requestSubmit()));
}

async function confirmAction(page, action, button) {
  return response(page, action, async () => {
    await button.click({ force: true });
    await expect(page.locator('#chat-confirmation-overlay')).toBeVisible();
    await page.locator('#chat-confirm-yes').click();
  });
}

function derived(kind) {
  return cli('inspect').derived.find(row => row.source_kind === kind);
}

test.use({ serviceWorkers: 'block', viewport: { width: 1440, height: 1000 } });
test.beforeEach(async ({ page }, testInfo) => {
  fixture = cli('setup');
  await page.route('https://**', route => route.abort());
  const loggedIn = await (await page.request.post(base + '/api.php', { form: { action: 'login', username: testInfo.title.includes('дашборде') ? fixture.admin_login : fixture.login, password: fixture.password } })).json();
  expect(loggedIn.success).toBe(true);
});
test.afterEach(() => { if (fixture) { cli('cleanup'); fixture = null; } });

test('MLP-456 правка, удаление и восстановление сообщения согласованы с индексом', async ({ page }) => {
  const row = await openChat(page);
  expect(derived('chat').status).toBe('ready');
  const text = fixture.marker + ' edited message';
  expect((await editMessage(page, row, text)).success).toBe(true);
  await expect(row).toContainText(text);
  expect(cli('inspect').message.message).toBe(text);
  expect(derived('chat')).toMatchObject({ status: 'pending', associations: null, vector_bytes: null });
  cli('prime');
  expect((await confirmAction(page, 'delete_message', row.locator('.delete-btn'))).success).toBe(true);
  await expect(row).toContainText('удалено');
  expect(Number(cli('inspect').message.is_deleted)).toBe(1);
  expect(derived('chat').status).toBe('pending');
  expect((await confirmAction(page, 'restore_message', row.locator('.restore-btn'))).success).toBe(true);
  await expect(row).toContainText(text);
  expect(Number(cli('inspect').message.is_deleted)).toBe(0);
  expect(derived('chat').status).toBe('pending');
});

test('MLP-456 правка записи памяти в дашборде инвалидирует производные данные', async ({ page }) => {
  await page.goto(base + '/dashboard/', { waitUntil: 'domcontentloaded' });
  await page.locator('.nav-tile[data-target="#tab-bot"]').click();
  await page.evaluate(() => loadMemory());
  const row = page.locator(`tr[data-mem-id="${fixture.memory_id}"]`);
  await expect(row).toContainText(fixture.memory_text);
  const text = fixture.marker + ' edited memory';
  page.once('dialog', dialog => dialog.accept(text));
  expect((await response(page, 'save_memory', () => row.locator('button').first().click())).success).toBe(true);
  await expect(row.locator('.mem-text')).toHaveText(text);
  expect(cli('inspect').memory.text).toBe(text);
  expect(derived('memory')).toMatchObject({ status: 'pending', associations: null, vector_bytes: null });
});

test('MLP-456 отзыв согласия скрывает источник и очищает производные данные', async ({ page }) => {
  await page.goto(base + '/profile.php', { waitUntil: 'domcontentloaded' });
  await expect(page.locator('[data-account-consent="withdraw"]')).toBeVisible();
  page.once('dialog', dialog => dialog.accept());
  await page.locator('[data-account-consent="withdraw"]').click();
  await expect(page.locator('#privacy')).toContainText('Учётная запись неактивна');
  await expect(page.locator('[data-account-consent="accept"]').first()).toBeVisible();
  const state = cli('inspect');
  expect(state.account.state).toBe('withdrawn');
  expect(state.derived).toEqual([]);
  await page.goto(base + '/chat_popup.php', { waitUntil: 'domcontentloaded' });
  await expect(page.locator(`.chat-message[data-id="${fixture.message_id}"]`)).not.toContainText(fixture.message_text);
});

test('MLP-456 отказ записи индекса откатывает источник и позволяет повторить действие', async ({ page }) => {
  const row = await openChat(page);
  const before = cli('inspect');
  cli('fail-derived');
  const text = fixture.marker + ' retry edit';
  expect((await editMessage(page, row, text)).success).toBe(false);
  await expect(page.locator('.chat-notification.error').last()).toBeVisible();
  await expect(row).toContainText(fixture.message_text);
  const failed = cli('inspect');
  expect(failed.message).toEqual(before.message);
  expect(failed.derived).toEqual(before.derived);
  cli('allow-derived');
  expect((await response(page, 'edit_message', () => page.locator('#chat-form').evaluate(form => form.requestSubmit()))).success).toBe(true);
  await expect(row).toContainText(text);
  expect(cli('inspect').message.message).toBe(text);
  expect(derived('chat').status).toBe('pending');
});
