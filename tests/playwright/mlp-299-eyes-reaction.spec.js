// MLP-299 — реакция «глазки» 👀: новый код 'eyes' принимается API
// (whitelist ChatManager), появляется в пикере (REACTION_ICONS) и рендерится
// на сообщении. Чистка: реакция снимается повторным toggle, сообщение удаляется.
//
// Env: MLP_BASE_URL, MLP_LOGIN, MLP_PASS.

const { test, expect, request } = require('@playwright/test');

const BASE = process.env.MLP_BASE_URL;
const LOGIN = process.env.MLP_LOGIN;
const PASS = process.env.MLP_PASS;

async function loginCtx() {
  const ctx = await request.newContext({ baseURL: BASE });
  const login = await (await ctx.post('/api.php', { form: { action: 'login', username: LOGIN, password: PASS } })).json();
  expect(login.success, `login failed: ${JSON.stringify(login)}`).toBeTruthy();
  const html = await (await ctx.get('/')).text();
  const csrf = html.match(/name="csrf-token"\s+content="([^"]+)"/)?.[1];
  return { ctx, csrf };
}

function post(ctx, csrf, form) {
  return ctx.post('/api.php', { form: { ...form, csrf_token: csrf }, headers: { 'X-CSRF-Token': csrf } })
    .then(r => r.json());
}

test('API: toggle_reaction принимает eyes, мусор — нет (MLP-299)', async () => {
  test.skip(!BASE, 'MLP_BASE_URL must be set');
  const { ctx, csrf } = await loginCtx();

  const marker = `pw299-глазки-${Date.now()}`;
  const sent = await post(ctx, csrf, { action: 'send_message', message: marker });
  expect(sent.success, `send: ${JSON.stringify(sent)}`).toBeTruthy();

  const msgs = await post(ctx, csrf, { action: 'get_messages', limit: '10' });
  const mine = msgs.data.messages.find(m => (m.message || '').includes(marker));
  expect(mine, 'своё сообщение видно в истории').toBeTruthy();

  const eyes = await post(ctx, csrf, { action: 'toggle_reaction', message_id: mine.id, reaction: 'eyes' });
  expect(eyes.success, `eyes принята: ${JSON.stringify(eyes)}`).toBeTruthy();
  expect(JSON.stringify(eyes.data.reactions), 'eyes в сводке реакций').toContain('eyes');

  const bogus = await post(ctx, csrf, { action: 'toggle_reaction', message_id: mine.id, reaction: 'banana' });
  expect(bogus.success, 'мусорная реакция отклонена').toBeFalsy();

  // Уборка: снять реакцию, удалить сообщение.
  const off = await post(ctx, csrf, { action: 'toggle_reaction', message_id: mine.id, reaction: 'eyes' });
  expect(off.success, 'повторный toggle снимает реакцию').toBeTruthy();
  const del = await post(ctx, csrf, { action: 'delete_message', message_id: mine.id });
  expect(del.success, 'уборка тестового сообщения').toBeTruthy();

  await ctx.dispose();
});

test('UI: пикер реакций показывает 👀 (MLP-299)', async ({ page }) => {
  test.skip(!BASE, 'MLP_BASE_URL must be set');
  const res = await page.request.post(BASE + '/api.php', {
    form: { action: 'login', username: LOGIN, password: PASS },
  });
  expect((await res.json()).success, 'login').toBeTruthy();

  await page.goto(BASE + '/', { waitUntil: 'domcontentloaded' });
  await expect(page.locator('.chat-message').first()).toBeVisible({ timeout: 10000 });

  // Пикер строится из REACTION_ICONS по hover на кнопке «добавить реакцию»
  // (кнопка проявляется при наведении на сообщение — opacity, не display).
  await page.locator('.chat-message').first().hover();
  const addBtn = page.locator('.chat-message .add-reaction-btn').first();
  await addBtn.hover();
  const picker = page.locator('.reaction-picker');
  await expect(picker).toBeVisible({ timeout: 5000 });
  await expect(picker.locator('.reaction-picker-item[title="eyes"]')).toHaveText('👀');
});
