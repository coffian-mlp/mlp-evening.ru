// MLP-270 — беклог /todo: гейты API дашборда (полный путь команды покрыт
// интеграционным тестом integration_feedback.php и ручной прод-проверкой QA).
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

test('беклог: гость и обычный юзер не достают до админ-списка (MLP-270)', async () => {
  expect(BASE, 'MLP_BASE_URL must be set').toBeTruthy();
  const guest = await request.newContext({ baseURL: BASE });
  for (const action of ['get_feedback', 'set_feedback_status']) {
    const res = await (await guest.post('/api.php', { form: { action } })).json();
    expect(res.success, `guest '${action}' denied`).toBeFalsy();
  }
  await guest.dispose();
});

test('беклог: админ видит список и меняет статус (MLP-270)', async () => {
  test.skip(!IS_ADMIN, 'нужен MLP_ADMIN=1');
  const { ctx, csrf } = await loginCtx();

  const list = await (await ctx.post('/api.php', {
    form: { action: 'get_feedback', status: 'new', csrf_token: csrf },
    headers: { 'X-CSRF-Token': csrf },
  })).json();
  expect(list.success, `list: ${JSON.stringify(list).slice(0, 150)}`).toBeTruthy();
  expect(Array.isArray(list.data.items)).toBeTruthy();
  expect(typeof list.data.new_count).toBe('number');

  const bad = await (await ctx.post('/api.php', {
    form: { action: 'set_feedback_status', id: '0', status: 'new', csrf_token: csrf },
    headers: { 'X-CSRF-Token': csrf },
  })).json();
  expect(bad.success, 'кривой id отвергается').toBeFalsy();

  await ctx.dispose();
});
