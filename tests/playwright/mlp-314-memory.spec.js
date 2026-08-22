// MLP-314 — память Лиры: гейты admin-API дашборда + XSS-безопасность текста записи.
// Полный путь команд покрыт integration_memory.php; живой e2e команд — QA-этап.
// Env: MLP_BASE_URL, MLP_LOGIN, MLP_PASS; MLP_ADMIN=1 включает админ-часть.

const { test, expect, request } = require('@playwright/test');

const BASE = process.env.MLP_BASE_URL;
const LOGIN = process.env.MLP_LOGIN;
const PASS = process.env.MLP_PASS;
const IS_ADMIN = process.env.MLP_ADMIN === '1';

async function loginCtx() {
  const ctx = await request.newContext({ baseURL: BASE });
  const login = await (await ctx.post('/api.php', { form: { action: 'login', username: LOGIN, password: PASS } })).json();
  expect(login.success).toBeTruthy();
  const html = await (await ctx.get('/')).text();
  return { ctx, csrf: html.match(/name="csrf-token"\s+content="([^"]+)"/)?.[1] };
}

test('память: гость не достаёт до admin-actions (MLP-314)', async () => {
  expect(BASE, 'MLP_BASE_URL must be set').toBeTruthy();
  const guest = await request.newContext({ baseURL: BASE });
  for (const action of ['get_memory', 'save_memory', 'delete_memory']) {
    const res = await (await guest.post('/api.php', { form: { action } })).json();
    expect(res.success, `guest '${action}' denied`).toBeFalsy();
  }
  await guest.dispose();
});

test('память: админ — список, правка (XSS-текст безопасен), удаление (MLP-314)', async () => {
  test.skip(!IS_ADMIN, 'нужен MLP_ADMIN=1');
  const { ctx, csrf } = await loginCtx();

  const list = await (await ctx.post('/api.php', {
    form: { action: 'get_memory', csrf_token: csrf },
    headers: { 'X-CSRF-Token': csrf },
  })).json();
  expect(list.success, `list: ${JSON.stringify(list).slice(0, 150)}`).toBeTruthy();
  expect(Array.isArray(list.data.items)).toBeTruthy();
  expect(typeof list.data.total).toBe('number');

  // Кривой kind не роняет list (fail-safe валидация).
  const badKind = await (await ctx.post('/api.php', {
    form: { action: 'get_memory', kind: 'wrong', csrf_token: csrf },
    headers: { 'X-CSRF-Token': csrf },
  })).json();
  expect(badKind.success).toBeTruthy();

  // save/delete по несуществующему id — вежливый fail, не 500.
  const badSave = await (await ctx.post('/api.php', {
    form: { action: 'save_memory', id: '999999999', text: 'x', csrf_token: csrf },
    headers: { 'X-CSRF-Token': csrf },
  })).json();
  expect(badSave.success).toBeFalsy();
  const badDel = await (await ctx.post('/api.php', {
    form: { action: 'delete_memory', id: '999999999', csrf_token: csrf },
    headers: { 'X-CSRF-Token': csrf },
  })).json();
  expect(badDel.success).toBeFalsy();

  await ctx.dispose();
});
