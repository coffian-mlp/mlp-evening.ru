// MLP-222 — security hardening batch. Тестируемые части: M1 (сессия) и M3 (SSRF).
// L1/L2 проверяются code-review + смоуком (трудно/нежелательно триггерить на проде).
//
// Env: MLP_BASE_URL, MLP_LOGIN, MLP_PASS (тестовый юзер Claude, роль user).

const { test, expect, request } = require('@playwright/test');

const BASE = process.env.MLP_BASE_URL;
const LOGIN = process.env.MLP_LOGIN;
const PASS = process.env.MLP_PASS;
const IS_HTTPS = (BASE || '').startsWith('https://');

function sessionCookie(state) {
  return state.cookies.find((c) => c.name === 'PHPSESSID');
}

async function getCsrf(ctx) {
  const html = await (await ctx.get('/')).text();
  return html.match(/name="csrf-token"\s+content="([^"]+)"/)?.[1];
}

// --- M1: session hardening ---
test('M1: session cookie hardened + id regenerated on login (MLP-222)', async () => {
  expect(BASE && LOGIN && PASS, 'env must be set').toBeTruthy();
  const ctx = await request.newContext({ baseURL: BASE });

  // Анонимная сессия до логина.
  await ctx.get('/');
  const before = sessionCookie(await ctx.storageState());
  expect(before, 'PHPSESSID must exist before login').toBeTruthy();

  // Логин.
  const login = await (await ctx.post('/api.php', { form: { action: 'login', username: LOGIN, password: PASS } })).json();
  expect(login.success, `login failed: ${JSON.stringify(login)}`).toBeTruthy();

  const after = sessionCookie(await ctx.storageState());
  expect(after, 'PHPSESSID must exist after login').toBeTruthy();

  // Session fixation: id должен смениться после логина.
  expect(after.value, 'session id must change after login').not.toBe(before.value);
  // Флаги cookie.
  expect(after.httpOnly, 'cookie must be HttpOnly').toBeTruthy();
  expect(after.sameSite, 'cookie must be SameSite=Lax').toBe('Lax');
  if (IS_HTTPS) expect(after.secure, 'cookie must be Secure on https').toBeTruthy();

  await ctx.dispose();
});

// --- M3: SSRF в uploadFromUrl (через update_profile avatar_url) ---
test('M3: uploadFromUrl rejects internal addresses (MLP-222)', async () => {
  expect(BASE && LOGIN && PASS, 'env must be set').toBeTruthy();
  const ctx = await request.newContext({ baseURL: BASE });

  const login = await (await ctx.post('/api.php', { form: { action: 'login', username: LOGIN, password: PASS } })).json();
  expect(login.success, 'login failed').toBeTruthy();
  const csrf = await getCsrf(ctx);
  expect(csrf, 'csrf token').toBeTruthy();

  // Внутренние/reserved адреса — должны отклоняться (загрузка не происходит).
  for (const badUrl of ['http://127.0.0.1/x.png', 'http://169.254.169.254/latest/meta-data/']) {
    const res = await (await ctx.post('/api.php', {
      form: { action: 'update_profile', csrf_token: csrf, avatar_url: badUrl },
      headers: { 'X-CSRF-Token': csrf },
    })).json();
    expect(res.success, `SSRF to ${badUrl} must be rejected, got ${JSON.stringify(res)}`).toBeFalsy();
  }

  await ctx.dispose();
});
