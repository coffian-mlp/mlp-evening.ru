// MLP-265 — чат-срез api.php: ChatController через тонкий роутер.
// Живой флоу: send → edit → reaction → delete → restore → delete (одно
// сообщение — бережём rate-limit). Сообщение НЕ упоминает бота — Лира молчит.
// Чистка: тест сам удаляет своё сообщение (финальное состояние — deleted).
//
// Env: MLP_BASE_URL, MLP_LOGIN, MLP_PASS.

const { test, expect, request } = require('@playwright/test');

const BASE = process.env.MLP_BASE_URL;
const LOGIN = process.env.MLP_LOGIN;
const PASS = process.env.MLP_PASS;

async function getCsrf(ctx) {
  const html = await (await ctx.get('/')).text();
  return html.match(/name="csrf-token"\s+content="([^"]+)"/)?.[1];
}

async function loginCtx() {
  const ctx = await request.newContext({ baseURL: BASE });
  const login = await (await ctx.post('/api.php', { form: { action: 'login', username: LOGIN, password: PASS } })).json();
  expect(login.success, `login failed: ${JSON.stringify(login)}`).toBeTruthy();
  const csrf = await getCsrf(ctx);
  return { ctx, csrf };
}

function post(ctx, csrf, form) {
  return ctx.post('/api.php', { form: { ...form, csrf_token: csrf }, headers: { 'X-CSRF-Token': csrf } })
    .then(r => r.json());
}

test('гость: get_chat_input отдаёт форму, get_messages — историю; мутации закрыты (MLP-265)', async () => {
  expect(BASE, 'MLP_BASE_URL must be set').toBeTruthy();
  const ctx = await request.newContext({ baseURL: BASE });

  const input = await (await ctx.post('/api.php', { form: { action: 'get_chat_input' } })).json();
  expect(input.success, `get_chat_input: ${JSON.stringify(input).slice(0, 120)}`).toBeTruthy();
  expect(typeof input.data.html, 'html поля ввода на месте').toBe('string');
  expect(input.data.user_data, 'у гостя нет user_data').toEqual([]);

  const msgs = await (await ctx.post('/api.php', { form: { action: 'get_messages', limit: '5' } })).json();
  expect(msgs.success).toBeTruthy();
  expect(Array.isArray(msgs.data.messages)).toBeTruthy();

  for (const action of ['send_message', 'edit_message', 'delete_message', 'toggle_reaction', 'upload_file', 'search_messages']) {
    const res = await (await ctx.post('/api.php', { form: { action } })).json();
    expect(res.success, `guest '${action}' must be denied`).toBeFalsy();
  }

  await ctx.dispose();
});

test('юзер: send → edit → reaction → delete → restore → delete (MLP-265)', async () => {
  const { ctx, csrf } = await loginCtx();

  const marker = `pw265-проверка-среза-${Date.now()}`;
  const sent = await post(ctx, csrf, { action: 'send_message', message: marker });
  expect(sent.success, `send: ${JSON.stringify(sent)}`).toBeTruthy();

  // Находим своё сообщение (id нужен для дальнейших шагов).
  const msgs = await post(ctx, csrf, { action: 'get_messages', limit: '10' });
  const mine = msgs.data.messages.find(m => (m.message || '').includes(marker));
  expect(mine, 'своё сообщение видно в истории').toBeTruthy();
  const id = mine.id;

  const edited = await post(ctx, csrf, { action: 'edit_message', message_id: id, message: marker + ' (ред.)' });
  expect(edited.success, `edit: ${JSON.stringify(edited)}`).toBeTruthy();

  const react = await post(ctx, csrf, { action: 'toggle_reaction', message_id: id, reaction: 'like' });
  expect(react.success, `reaction: ${JSON.stringify(react)}`).toBeTruthy();

  const ctxRes = await post(ctx, csrf, { action: 'get_message_context', id });
  expect(ctxRes.success && Array.isArray(ctxRes.data.messages), 'контекст загружается').toBeTruthy();

  const search = await post(ctx, csrf, { action: 'search_messages', query: 'pw265-проверка' });
  expect(search.success, 'поиск работает').toBeTruthy();

  const del = await post(ctx, csrf, { action: 'delete_message', message_id: id });
  expect(del.success, `delete: ${JSON.stringify(del)}`).toBeTruthy();

  const rest = await post(ctx, csrf, { action: 'restore_message', message_id: id });
  expect(rest.success, `restore: ${JSON.stringify(rest)}`).toBeTruthy();

  // Финальная уборка: сообщение остаётся удалённым (правило чистки чата).
  const del2 = await post(ctx, csrf, { action: 'delete_message', message_id: id });
  expect(del2.success, 'финальное удаление (уборка)').toBeTruthy();

  await ctx.dispose();
});
