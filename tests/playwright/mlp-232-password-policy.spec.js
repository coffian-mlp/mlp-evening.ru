// MLP-232 (L5) — политика пароля min8/max72.
// Проверяем негативы через update_profile (без капчи, пароль не меняется при отказе):
// короткий (<8) и слишком длинный (>72 байт) пароли отклоняются.
//
// Env: MLP_BASE_URL, MLP_LOGIN, MLP_PASS (Claude, любая роль — нужен только вход).

const { test, expect, request } = require('@playwright/test');

const BASE = process.env.MLP_BASE_URL;
const LOGIN = process.env.MLP_LOGIN;
const PASS = process.env.MLP_PASS;

async function loggedCtx() {
  const ctx = await request.newContext({ baseURL: BASE });
  const login = await (await ctx.post('/api.php', { form: { action: 'login', username: LOGIN, password: PASS } })).json();
  expect(login.success, `login failed: ${JSON.stringify(login)}`).toBeTruthy();
  const html = await (await ctx.get('/')).text();
  const csrf = html.match(/name="csrf-token"\s+content="([^"]+)"/)?.[1];
  expect(csrf, 'csrf token').toBeTruthy();
  return { ctx, csrf };
}

test.beforeEach(() => {
  expect(BASE && LOGIN && PASS, 'env must be set').toBeTruthy();
});

test('update_profile: короткий пароль (7) отклоняется (L5)', async () => {
  const { ctx, csrf } = await loggedCtx();
  const res = await (await ctx.post('/api.php', {
    form: { action: 'update_profile', csrf_token: csrf, password: '1234567' },
    headers: { 'X-CSRF-Token': csrf },
  })).json();
  expect(res.success, `short password must be rejected: ${JSON.stringify(res)}`).toBeFalsy();
  expect(res.message || '', 'must mention too short').toMatch(/корот/i);
  await ctx.dispose();
});

test('update_profile: пароль >72 байт отклоняется (L5)', async () => {
  const { ctx, csrf } = await loggedCtx();
  const res = await (await ctx.post('/api.php', {
    form: { action: 'update_profile', csrf_token: csrf, password: 'a'.repeat(73) },
    headers: { 'X-CSRF-Token': csrf },
  })).json();
  expect(res.success, `over-72 password must be rejected: ${JSON.stringify(res)}`).toBeFalsy();
  expect(res.message || '', 'must mention too long').toMatch(/длин/i);
  await ctx.dispose();
});
