const { test, expect } = require('@playwright/test');
const { execFileSync } = require('child_process');
const fs = require('fs');
const path = require('path');
const BASE = process.env.MLP_BASE_URL;
const repo = path.resolve(__dirname, '../..');
function cli(...args) {
  if (!BASE || !['localhost', '127.0.0.1'].includes(new URL(BASE).hostname)) throw new Error('Isolated localhost required');
  return execFileSync('docker', ['compose', '-p', 'mlp359', '-f', 'docker-compose.yml', '-f', 'docs/tests/MLP-359/compose.override.yml', 'exec', '-T', 'php', 'php', 'tests/playwright/mlp-366-ratings-fixture.php', ...args], { cwd: repo, stdio: 'pipe' }).toString();
}
const inspect = f => JSON.parse(cli('inspect', f.key));
const worker = (f, mode) => JSON.parse(cli('worker', f.key, mode));
const widget = (page, row) => page.locator(`.chat-message[data-id="${row.messageId}"] .command-interaction`);
async function refresh(page, row) {
  await expect(widget(page, row)).toBeVisible({ timeout: 15000 });
  await widget(page, row).scrollIntoViewIfNeeded();
  await widget(page, row).evaluate(node => window.CommandInteractions.refresh(node));
}
async function post(page, data) {
  const csrf = await page.locator('meta[name="csrf-token"]').getAttribute('content');
  return (await page.request.post(BASE + '/api.php', { form: { ...data, csrf_token: csrf } })).json();
}
async function send(page, text) {
  const result = await post(page, { action: 'send_message', message: text });
  expect(result.success, JSON.stringify(result)).toBeTruthy();
}
async function quoted(page, text, messageId) {
  // Wait for the actual refine response/renderer before preparing the quoted input.
  await expect(page.locator(`.chat-message[data-id="${messageId}"] .command-interaction`)).toContainText('Ответь Лире с цитатой и уточни описание');
  while (await page.locator('.quote-preview-remove').count()) await page.locator('.quote-preview-remove').first().click();
  await page.evaluate(() => window.getSelection()?.removeAllRanges());
  await page.locator(`.chat-message[data-id="${messageId}"] .quote-btn`).click();
  await expect(page.locator(`.quote-preview-remove[data-id="${messageId}"]`)).toBeVisible();
  await page.locator('#chat-input').fill(text);
  const pending = page.waitForResponse(r => r.url().endsWith('/api.php') && r.request().postData()?.includes('action=send_message'));
  await page.locator('#chat-form').evaluate(form => form.requestSubmit());
  const response = await pending;
  expect((new URLSearchParams(response.request().postData()).get('quoted_msg_ids') || '').split(',')).toContain(String(messageId));
  expect((await response.json()).success).toBeTruthy();
}
async function login(page, f) {
  const csrf = await page.locator('meta[name="csrf-token"]').count() ? await page.locator('meta[name="csrf-token"]').getAttribute('content') : '';
  const result = await (await page.request.post(BASE + '/api.php', { form: { action: 'login', username: f.login, password: f.password, csrf_token: csrf } })).json();
  expect(result.success, JSON.stringify(result)).toBeTruthy();
  await page.goto(BASE + '/', { waitUntil: 'domcontentloaded' });
}
async function actor(page, key) {
  cli('user', key);
  const data = JSON.parse(fs.readFileSync(path.join(repo, 'docs/private/mlp361-interactions-local.json'), 'utf8')).cases[key];
  const targets = JSON.parse(cli('ratings', key));
  const f = { ...data, ...targets, key };
  await login(page, f); return f;
}
function pending(f) {
  const row = inspect(f).interactions.findLast(r => r.state === 'pending');
  expect(row).toBeTruthy(); return row;
}
function candidateIds(row) { return row.options.filter(o => o.key.startsWith('episode_')).map(o => Number(o.key.slice(8))); }
function noRatingWeb(result) { expect(result.calls.filter(c => c.stage === 'search')).toHaveLength(0); }
async function screenshot(page, browserName, name) {
  const dir = path.join(repo, 'docs/tests/MLP-366-local-episode-ratings/screenshots');
  fs.mkdirSync(dir, { recursive: true });
  await page.screenshot({ path: path.join(dir, `${browserName}-${name}.png`), animations: 'disabled' });
}
test.beforeEach(() => test.skip(!BASE, 'MLP_BASE_URL required'));

test('MLP-366 local whole-pool best → quoted worst → exactly one confirmed wish', async ({ page, browserName }) => {
  const f = await actor(page, `${browserName}_local_mean`);
  await send(page, '!хочу эпизод с лучшим рейтингом');
  const initial = worker(f, 'best'); noRatingWeb(initial);
  const parent = pending(f); expect(candidateIds(parent)).toEqual([f.bestId]);
  expect(f.bestId).not.toBe(f.firstThreeIds[0]); expect(f.firstThreeIds).not.toContain(f.bestId);
  expect(inspect(f).events).toBe(0); expect(inspect(f).wishes).toBe(0);
  await refresh(page, parent);
  await expect(page.locator(`.chat-message[data-id="${parent.messageId}"]`)).toContainText('IMDb');
  const view = await post(page, { action: 'get_command_interaction', interaction_id: String(parent.id), message_id: String(parent.messageId) });
  expect(view.success).toBeTruthy(); expect(JSON.stringify(view)).not.toContain('IMDB_HISTOGRAM');
  expect(JSON.stringify(view)).not.toContain('resolution_snapshot'); expect(JSON.stringify(view)).not.toContain('provenance');
  await widget(page, parent).getByRole('button', { name: 'Не то, уточнить', exact: true }).click();
  await quoted(page, 'Скорее самый плохой по средней оценке IMDb', parent.messageId);
  expect(inspect(f).interactions.find(r => r.id === parent.id).state).toBe('resolving');
  noRatingWeb(worker(f, 'worst'));
  const child = pending(f); expect(child.id).not.toBe(parent.id); expect(child.expiresAt).toBe(parent.expiresAt);
  expect(candidateIds(child)).toEqual([f.worstId]); expect(inspect(f).events).toBe(0);
  await refresh(page, child); await screenshot(page, browserName, 'local-worst-before-confirm');
  await widget(page, child).locator(`[data-option-key="episode_${f.worstId}"]`).click();
  await expect(widget(page, child)).toContainText('Выбор завершён');
  expect(inspect(f).wishes).toBe(1); expect(inspect(f).events).toBe(1);
  await post(page, { action: 'act_command_interaction', interaction_id: String(child.id), option_key: `episode_${f.worstId}` });
  expect(inspect(f).events).toBe(1);
  await page.reload({ waitUntil: 'domcontentloaded' }); await refresh(page, child);
  await expect(widget(page, child)).toContainText('Выбор завершён');
});

test('MLP-366 population SD differs from explicit love/hate; repeated refine and cancel keep effects zero', async ({ page, browserName }) => {
  const f = await actor(page, `${browserName}_local_sd`);
  await send(page, '!хочу самую спорную серию'); noRatingWeb(worker(f, 'sd'));
  const parent = pending(f); expect(candidateIds(parent)).toEqual([f.sdId]);
  await refresh(page, parent); await screenshot(page, browserName, 'local-standard-deviation');
  await widget(page, parent).getByRole('button', { name: 'Не то, уточнить', exact: true }).click();
  const prompt = worker(f, 'sd'); noRatingWeb(prompt); expect(prompt.calls.filter(c => c.stage === 'normalize')).toHaveLength(0);
  await quoted(page, 'Именно массовые высокие и низкие оценки, любовь и ненависть', parent.messageId);
  noRatingWeb(worker(f, 'lovehate'));
  const child = pending(f); expect(child.id).not.toBe(parent.id);
  expect(candidateIds(child)).toEqual([f.lovehateId]); expect(f.lovehateId).not.toBe(f.sdId);
  await refresh(page, child); await screenshot(page, browserName, 'local-love-hate');
  await widget(page, child).getByRole('button', { name: 'Не то, уточнить', exact: true }).click();
  const secondPrompt = worker(f, 'lovehate'); noRatingWeb(secondPrompt);
  expect(secondPrompt.calls.filter(c => c.stage === 'normalize')).toHaveLength(0);
  const state = inspect(f); expect(state.interactions.find(r => r.id === child.id).state).toBe('clarifying');
  expect(state.interactions.find(r => r.id === child.id).handlerContext.resolution_snapshot.intent.metric).toBe('polarization');
  await refresh(page, child); await widget(page, child).getByRole('button', { name: 'Передумал', exact: true }).click();
  expect(inspect(f).events).toBe(0); expect(inspect(f).wishes).toBe(0);
});

test('MLP-366 stale observations yield clarification, never a fabricated or zero-valued winner', async ({ page, browserName }) => {
  const f = await actor(page, `${browserName}_local_stale`); cli('stale', f.key);
  await send(page, '!хочу лучший эпизод по IMDb'); noRatingWeb(worker(f, 'best'));
  const state = inspect(f); const row = state.interactions.findLast(r => r.state === 'clarifying');
  expect(row).toBeTruthy(); expect(candidateIds(row)).toEqual([]); expect(state.events).toBe(0);
  await refresh(page, row); await expect(page.locator(`.chat-message[data-id="${row.messageId}"]`)).toContainText('IMDb');
  await widget(page, row).getByRole('button', { name: 'Передумал', exact: true }).click();
  expect(inspect(f).wishes).toBe(0); expect(inspect(f).events).toBe(0);
});

test('MLP-366 ownership and source edit block choice while local rating facts remain private', async ({ page, browserName }) => {
  const f = await actor(page, `${browserName}_local_owner`);
  await send(page, '!хочу лучший эпизод'); noRatingWeb(worker(f, 'best')); const row = pending(f);
  await refresh(page, row);
  cli('user', `${browserName}_local_foreign`);
  const foreign = JSON.parse(fs.readFileSync(path.join(repo, 'docs/private/mlp361-interactions-local.json'), 'utf8')).cases[`${browserName}_local_foreign`];
  await login(page, foreign);
  const denied = await post(page, { action: 'act_command_interaction', interaction_id: String(row.id), option_key: `episode_${f.bestId}` });
  expect(denied.success).toBeFalsy(); expect(inspect(f).events).toBe(0);
  await login(page, f); cli('invalidate', f.key, 'edit');
  const edited = await post(page, { action: 'act_command_interaction', interaction_id: String(row.id), option_key: `episode_${f.bestId}` });
  expect(edited.success).toBeFalsy(); expect(inspect(f).wishes).toBe(0); expect(inspect(f).events).toBe(0);
});
