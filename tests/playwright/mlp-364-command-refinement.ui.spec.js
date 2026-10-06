const { test, expect } = require('@playwright/test');
const { execFileSync } = require('child_process');
const fs = require('fs');
const path = require('path');
const BASE = process.env.MLP_BASE_URL;
const repo = path.resolve(__dirname, '../..');
function cli(...args) {
  if (!BASE || !['localhost', '127.0.0.1'].includes(new URL(BASE).hostname)) throw new Error('Isolated localhost required');
  return execFileSync('docker', ['compose', '-p', 'mlp359', '-f', 'docker-compose.yml', '-f', 'docs/tests/MLP-359/compose.override.yml', 'exec', '-T', 'php', 'php', 'tests/playwright/mlp-361-interactions-fixture.php', ...args], { cwd: repo, stdio: 'pipe' }).toString();
}
function inspect(f) { return JSON.parse(cli('continuation-inspect', f.key)); }
function runWorker(f, mode = 'found') { return JSON.parse(cli('continuation-worker', f.key, mode)); }
const choice = (page, row) => page.locator(`.chat-message[data-id="${row.messageId}"] .command-interaction`);
async function refresh(page, row) {
  const widget = choice(page, row); await widget.scrollIntoViewIfNeeded();
  await widget.evaluate(node => window.CommandInteractions.refresh(node));
  return widget;
}
async function post(page, data) {
  const csrf = await page.locator('meta[name="csrf-token"]').getAttribute('content');
  return (await page.request.post(BASE + '/api.php', { form: { ...data, csrf_token: csrf } })).json();
}
async function send(page, message, quotes = []) {
  const result = await post(page, { action: 'send_message', message, ...(quotes.length ? { quoted_msg_ids: quotes.join(',') } : {}) });
  expect(result.success).toBeTruthy(); return result;
}
async function sendQuotedUI(page, message, messageId) {
  const bubble = page.locator(`.chat-message[data-id="${messageId}"]`);
  await bubble.locator('.quote-btn').click({ force: true });
  const field = page.locator('#chat-input'); await field.fill(message);
  const response = page.waitForResponse(r => r.url().endsWith('/api.php') && r.request().postData()?.includes('action=send_message'));
  await page.locator('#chat-form').evaluate(form => form.requestSubmit());
  expect((await (await response).json()).success).toBeTruthy();
}
async function actor(page, key, route = '/') {
  cli('continuation-user', key);
  const user = JSON.parse(fs.readFileSync(path.join(repo, 'docs/private/mlp361-interactions-local.json'), 'utf8')).cases[key];
  const login = await (await page.request.post(BASE + '/api.php', { form: { action: 'login', username: user.login, password: user.password } })).json();
  expect(login.success).toBeTruthy(); await page.goto(BASE + route, { waitUntil: 'domcontentloaded' });
  return { ...user, key };
}
async function proposal(page, f, query = 'самую первую серию') {
  await send(page, '!хочу ' + query); runWorker(f);
  const row = inspect(f).interactions.findLast(r => r.state === 'pending');
  expect(row).toBeTruthy(); await expect(choice(page, row)).toBeVisible({ timeout: 15000 });
  await refresh(page, row); return row;
}
test.beforeEach(() => { test.skip(!BASE, 'MLP_BASE_URL required'); });
test.afterEach(async ({ page, browserName }, info) => {
  if (info.status !== info.expectedStatus) await info.attach('interaction-dom', { body: await page.locator('.command-interaction').evaluateAll(nodes => JSON.stringify(nodes.map(n => ({ id: n.dataset.interactionId, state: n._commandState, text: n.textContent })))), contentType: 'application/json' });
  if (info.status === info.expectedStatus && info.title.includes('неверный эпизод')) {
    fs.mkdirSync(path.join(repo, 'docs/tests/MLP-364/screenshots'), { recursive: true });
    await page.screenshot({ path: path.join(repo, `docs/tests/MLP-364/screenshots/${browserName}-${info.title.includes('popup') ? 'popup' : 'embedded'}-${info.title.includes('360px') ? 'mobile' : 'desktop'}.png`) });
  }
});
for (const route of ['/', '/chat_popup.php']) for (const width of [1280, 360]) {
  test(`MLP-364 неверный эпизод ${route} ${width}px: уточнение до просьбы Лиры и принятие`, async ({ page, browserName }) => {
    await page.setViewportSize({ width, height: 800 });
    const f = await actor(page, `${browserName}_${route === '/' ? 'embed' : 'popup'}_${width}`, route);
    const parent = await proposal(page, f); const rootExpiry = parent.expiresAt;
    expect(inspect(f).wishes).toBe(0);
    const screenshotBase = path.join(repo, `docs/tests/MLP-364/screenshots/${browserName}-${route === '/' ? 'embedded' : 'popup'}-${width === 360 ? 'mobile' : 'desktop'}`);
    fs.mkdirSync(path.dirname(screenshotBase), { recursive: true });
    await page.screenshot({ path: screenshotBase + '-pending.png' });
    await choice(page, parent).getByRole('button', { name: 'Не то, уточнить', exact: true }).click();
    await expect(choice(page, parent).locator('button[data-option-key^="episode_"]')).toHaveCount(0);
    await expect(choice(page, parent).getByRole('button', { name: 'Передумал', exact: true })).toBeEnabled();
    await page.screenshot({ path: screenshotBase + '-clarifying.png' });
    // The new LLM question has not run: the original actual proposal is immediately quoteable.
    await sendQuotedUI(page, String(f.targetId), parent.messageId);
    expect(inspect(f).interactions.find(r => r.id === parent.id).state).toBe('resolving');
    runWorker(f); const state = inspect(f); const child = state.interactions.findLast(r => r.state === 'pending');
    expect(child.id).not.toBe(parent.id); expect(child.expiresAt).toBe(rootExpiry); expect(state.wishes).toBe(0); expect(state.events).toBe(0);
    expect(child.options.filter(o => o.key.startsWith('episode_')).map(o => o.key)).toEqual([`episode_${f.targetId}`]);
    await expect(choice(page, child)).toBeVisible({ timeout: 15000 }); await refresh(page, child);
    await refresh(page, parent); await expect(choice(page, parent)).toContainText('Выбор заменён');
    await choice(page, child).locator(`[data-option-key="episode_${f.targetId}"]`).click();
    await expect(choice(page, child)).toContainText('Выбор завершён'); expect(inspect(f).wishes).toBe(1); expect(inspect(f).events).toBe(1);
    const replay = await post(page, { action: 'act_command_interaction', interaction_id: String(child.id), option_key: 'cancel' });
    expect(replay.success).toBeTruthy(); expect(inspect(f).wishes).toBe(1); expect(inspect(f).events).toBe(1);
    await page.reload({ waitUntil: 'domcontentloaded' }); await refresh(page, child); await expect(choice(page, child)).toContainText('Выбор завершён');
  });
}
test('MLP-364 текстовый отказ, пустой поиск, цитата вопроса и повтор', async ({ page, browserName }) => {
  const f = await actor(page, `${browserName}_empty`); const parent = await proposal(page, f);
  await send(page, 'нет, не эта — там была Рэрити', [parent.messageId]); runWorker(f, 'noresults');
  let state = inspect(f); const waiting = state.interactions.find(r => r.id === parent.id);
  expect(waiting.state).toBe('clarifying'); expect(state.wishes).toBe(0); expect(state.events).toBe(0);
  expect(waiting.questionBindings.length).toBeGreaterThan(0);
  const questionId = waiting.questionBindings.at(-1).id;
  await expect(page.locator(`.chat-message[data-id="${questionId}"]`)).toBeVisible({ timeout: 15000 });
  await expect(page.locator(`.chat-message[data-id="${questionId}"] .command-interaction`)).toHaveCount(0);
  await send(page, 'повтори поиск', [questionId]); runWorker(f);
  state = inspect(f); const child = state.interactions.findLast(r => r.state === 'pending');
  expect(child.id).not.toBe(parent.id); expect(state.wishes).toBe(0);
  const search = state.trace.filter(c => c.stage === 'search'); expect(search.length).toBe(2);
  const verify = state.trace.filter(c => c.stage === 'verify').at(-1);
  expect(JSON.parse(verify.user).original_query).toContain('самую первую серию'); expect(JSON.parse(verify.user).original_query).toContain('Рэрити');
  runWorker(f); expect(inspect(f).trace.filter(c => c.stage === 'search').length).toBe(search.length);
});
test('MLP-364 отмена поиска в resolving не меняет квоту и не создаёт поздний выбор', async ({ page, browserName }) => {
  const f = await actor(page, `${browserName}_resolve_cancel`); const parent = await proposal(page, f);
  await send(page, 'нет, не эта — там была Рэрити', [parent.messageId]);
  const widget = await refresh(page, parent); await expect(widget).toContainText('Ищем');
  await expect(widget.getByRole('button')).toHaveCount(1); await widget.getByRole('button', { name: 'Передумал', exact: true }).click();
  runWorker(f); const state = inspect(f); expect(state.interactions.some(r => r.state === 'pending')).toBeFalsy(); expect(state.wishes).toBe(0); expect(state.events).toBe(0);
  await expect(widget).toContainText('Выбор отменён');
});
test('MLP-364 отмена во время внешнего поиска отвергает поздний подтверждённый результат', async ({ page, browserName }) => {
  const f = await actor(page, `${browserName}_late_cancel`); const parent = await proposal(page, f);
  await send(page, 'нет, не эта — там была Рэрити', [parent.messageId]);
  runWorker(f, 'cancel-during-search'); const state = inspect(f);
  expect(state.trace.some(c => c.stage === 'search')).toBeTruthy();
  expect(state.interactions.find(r => r.id === parent.id).state).toBe('cancelled');
  expect(state.interactions.some(r => r.state === 'pending')).toBeFalsy(); expect(state.wishes).toBe(0); expect(state.events).toBe(0);
  await refresh(page, parent); await expect(choice(page, parent)).toContainText('Выбор отменён');
});
test('MLP-364 чужая цитата и посторонний разговор не меняют свой поиск; адресная отмена однозначна', async ({ page, browserName }) => {
  const f = await actor(page, `${browserName}_address`); const parent = await proposal(page, f);
  await send(page, 'передумал'); await send(page, 'не передумал', [parent.messageId]);
  expect(inspect(f).interactions.find(r => r.id === parent.id).state).toBe('pending');
  cli('case', `${browserName}_foreign364`);
  const foreign = JSON.parse(fs.readFileSync(path.join(repo, 'docs/private/mlp361-interactions-local.json'), 'utf8')).cases[`${browserName}_foreign364`];
  await send(page, 'передумал', [foreign.messageId]); expect(inspect(f).interactions.find(r => r.id === parent.id).state).toBe('pending');
  await send(page, 'Лира, передумал', [parent.messageId, foreign.messageId]); expect(inspect(f).interactions.find(r => r.id === parent.id).state).toBe('pending');
  await send(page, 'Лира, нет, не эта — там была Рэрити'); expect(inspect(f).interactions.find(r => r.id === parent.id).state).toBe('resolving');
  await send(page, 'Лира, передумал!'); expect(inspect(f).interactions.find(r => r.id === parent.id).state).toBe('cancelled');
  expect(inspect(f).wishes).toBe(0); expect(inspect(f).events).toBe(0);
});
test('MLP-364 ошибка первоначального поиска оставляет уточнение и повтор без голоса', async ({ page, browserName }) => {
  const f = await actor(page, `${browserName}_initial_error`);
  await send(page, '!хочу серию с Рэрити и дымом'); runWorker(f, 'error');
  const parent = inspect(f).interactions.findLast(r => r.state === 'clarifying'); expect(parent).toBeTruthy();
  await expect(choice(page, parent)).toBeVisible({ timeout: 15000 }); await refresh(page, parent);
  await expect(choice(page, parent).locator('button[data-option-key^="episode_"]')).toHaveCount(0);
  await expect(choice(page, parent).getByRole('button', { name: 'Передумал', exact: true })).toBeEnabled();
  expect(inspect(f).wishes).toBe(0); expect(inspect(f).events).toBe(0);
  await send(page, 'повтори поиск', [parent.messageId]); runWorker(f);
  const child = inspect(f).interactions.findLast(r => r.state === 'pending'); expect(child).toBeTruthy(); expect(child.id).not.toBe(parent.id);
  expect(inspect(f).wishes).toBe(0); expect(inspect(f).events).toBe(0);
});
test('MLP-364 неоднозначная цитата доставляет notice через worker без служебного маркера в DOM', async ({ page, browserName }) => {
  const f = await actor(page, `${browserName}_notice`); const parent = await proposal(page, f);
  cli('case', `${browserName}_notice_foreign`);
  const foreign = JSON.parse(fs.readFileSync(path.join(repo, 'docs/private/mlp361-interactions-local.json'), 'utf8')).cases[`${browserName}_notice_foreign`];
  await send(page, 'Лира, передумал', [parent.messageId, foreign.messageId]);
  expect(inspect(f).notices).toEqual([]); runWorker(f);
  const state = inspect(f); expect(state.notices).toHaveLength(1);
  const notice = state.notices[0];
  expect(notice.raw).toContain(`[[command-delivery:notice_${notice.sourceId}]]`);
  expect(notice.rendered).not.toContain('command-delivery:');
  const bubble = page.locator(`.chat-message[data-id="${notice.id}"]`);
  await expect(bubble).toBeVisible({ timeout: 15000 });
  await expect(bubble).toContainText('цитат'); await expect(bubble).not.toContainText('command-delivery:');
  await expect(bubble.locator('.command-interaction')).toHaveCount(0);
  expect(state.interactions.find(r => r.id === parent.id).state).toBe('pending');
  expect(state.wishes).toBe(0); expect(state.events).toBe(0);
  runWorker(f); expect(inspect(f).notices.map(n => n.id)).toEqual([notice.id]);
});
for (const editBeforeDelivery of [false, true]) {
  test(`MLP-364 durable отмена после потери enqueue ${editBeforeDelivery ? 'блокирует ответ после edit' : 'восстанавливает единственное подтверждение'}`, async ({ page, browserName }) => {
    const f = await actor(page, `${browserName}_terminal_${editBeforeDelivery ? 'edit' : 'recover'}`);
    const parent = await proposal(page, f); const before = inspect(f);
    const cancelled = await post(page, { action: 'act_command_interaction', interaction_id: String(parent.id), option_key: 'cancel' });
    expect(cancelled.success).toBeTruthy();
    const committed = inspect(f).interactions.find(r => r.id === parent.id);
    expect(committed.state).toBe('cancelled'); expect(committed.resultMessageId).toBe(0);
    expect(committed.work).toBeTruthy();
    expect(JSON.parse(cli('continuation-discard-jobs', f.key)).removed).toBeGreaterThanOrEqual(1);
    if (editBeforeDelivery) cli('continuation-invalidate', f.key, 'edit');
    runWorker(f); let state = inspect(f); const delivered = state.interactions.find(r => r.id === parent.id);
    expect(state.wishes).toBe(before.wishes); expect(state.events).toBe(before.events);
    if (editBeforeDelivery) {
      expect(delivered.resultMessageId).toBe(0); expect(delivered.state).toBe('cancelled');
    } else {
      expect(delivered.resultMessageId).toBeGreaterThan(0);
      const reply = page.locator(`.chat-message[data-id="${delivered.resultMessageId}"]`);
      await expect(reply).toBeVisible({ timeout: 15000 }); await expect(reply).toContainText('отмен');
      await expect(reply).not.toContainText('command-delivery:');
    }
    runWorker(f); state = inspect(f);
    expect(state.interactions.find(r => r.id === parent.id).resultMessageId).toBe(delivered.resultMessageId);
    expect(state.wishes).toBe(before.wishes); expect(state.events).toBe(before.events);
  });
}
test('MLP-364 новая команда заменяет старый незавершённый поиск', async ({ page, browserName }) => {
  const f = await actor(page, `${browserName}_supersede`); const parent = await proposal(page, f);
  await send(page, 'нет, не эта — там была Рэрити', [parent.messageId]);
  await send(page, '!хочу ' + f.targetId); runWorker(f);
  const state = inspect(f); expect(state.interactions.find(r => r.id === parent.id).state).toBe('superseded'); expect(state.wishes).toBe(1); expect(state.events).toBe(1);
  expect(state.interactions.some(r => r.state === 'pending')).toBeFalsy();
  const stale = await post(page, { action: 'act_command_interaction', interaction_id: String(parent.id), option_key: 'episode_1' });
  expect(stale.success).toBeFalsy(); expect(inspect(f).events).toBe(1);
});
test('MLP-364 активное уточнение сохраняет polling 5 секунд и максимум два чтения', async ({ page, browserName }) => {
  const f = await actor(page, `${browserName}_polling`); const parent = await proposal(page, f);
  await choice(page, parent).getByRole('button', { name: 'Не то, уточнить', exact: true }).click();
  await expect(choice(page, parent)).toContainText('Ответь Лире');
  const at = []; let concurrent = 0; let maximum = 0; const pending = new Set();
  const started = request => {
    const data = request.postData(); if (!data?.includes('action=get_command_interaction')) return;
    pending.add(request); maximum = Math.max(maximum, ++concurrent);
    if (new URLSearchParams(data).get('interaction_id') === String(parent.id)) at.push(Date.now());
  };
  const finished = request => { if (pending.delete(request)) --concurrent; };
  page.on('request', started); page.on('requestfinished', finished); page.on('requestfailed', finished);
  await page.waitForTimeout(11000);
  page.off('request', started); page.off('requestfinished', finished); page.off('requestfailed', finished);
  expect(at.length).toBeGreaterThanOrEqual(2); expect(at.length).toBeLessThanOrEqual(3); expect(maximum).toBeLessThanOrEqual(2);
  for (let i = 1; i < at.length; ++i) expect(at[i] - at[i - 1]).toBeGreaterThanOrEqual(4500);
  await choice(page, parent).getByRole('button', { name: 'Передумал', exact: true }).click();
});
for (const invalidation of ['expire', 'edit', 'ban']) {
  test(`MLP-364 ${invalidation} запрещает уточнение и не расходует квоту`, async ({ page, browserName }) => {
    const f = await actor(page, `${browserName}_${invalidation}`); const parent = await proposal(page, f);
    cli('continuation-invalidate', f.key, invalidation);
    const reply = await post(page, { action: 'act_command_interaction', interaction_id: String(parent.id), option_key: 'refine' });
    expect(reply.success).toBeFalsy(); expect(inspect(f).wishes).toBe(0); expect(inspect(f).events).toBe(0);
    await refresh(page, parent); await expect(choice(page, parent).getByRole('button')).toHaveCount(0);
    if (invalidation === 'expire') await expect(choice(page, parent)).toContainText('Срок выбора истёк');
    if (invalidation === 'edit') await expect(choice(page, parent)).toContainText('Выбор недоступен');
    if (invalidation === 'ban') {
      await expect(choice(page, parent)).toContainText('Выбор недоступен');
      const view = await post(page, { action: 'get_command_interaction', interaction_id: String(parent.id), message_id: String(parent.messageId) });
      expect(view.data.state).toBe('unavailable'); expect(view.data.can_act).toBeFalsy(); expect(view.data.options).toEqual([]);
    }
  });
}
