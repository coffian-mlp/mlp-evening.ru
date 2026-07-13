// MLP-223 — «Запомнить меня» (persistent-token в БД).
// Проверяем: (1) вход с галкой выдаёт долгоживущую remember-cookie;
// (2) в свежем контексте, где есть ТОЛЬКО remember-cookie (без сессии),
//     происходит авто-вход при заходе на страницу.
//
// Env: MLP_BASE_URL, MLP_LOGIN, MLP_PASS (тестовый юзер Claude).

const { test, expect, request } = require('@playwright/test');

const BASE = process.env.MLP_BASE_URL;
const LOGIN = process.env.MLP_LOGIN;
const PASS = process.env.MLP_PASS;
const IS_HTTPS = (BASE || '').startsWith('https://');
const COOKIE = 'mlp_remember';

test('remember-me issues token and auto-logs-in a fresh session (MLP-223)', async () => {
  expect(BASE && LOGIN && PASS, 'env must be set').toBeTruthy();

  // 1. Вход с галкой remember=1.
  const ctx1 = await request.newContext({ baseURL: BASE });
  const login = await (await ctx1.post('/api.php', {
    form: { action: 'login', username: LOGIN, password: PASS, remember: '1' },
  })).json();
  expect(login.success, `login failed: ${JSON.stringify(login)}`).toBeTruthy();

  const state1 = await ctx1.storageState();
  const remember = state1.cookies.find((c) => c.name === COOKIE);
  expect(remember, 'remember cookie must be issued').toBeTruthy();
  expect(remember.httpOnly, 'remember cookie HttpOnly').toBeTruthy();
  if (IS_HTTPS) expect(remember.secure, 'remember cookie Secure on https').toBeTruthy();
  // Долгоживущая: > 25 дней вперёд.
  const daysAhead = (remember.expires - Date.now() / 1000) / 86400;
  expect(daysAhead, `expires must be long-lived, got ${daysAhead}d`).toBeGreaterThan(25);
  await ctx1.dispose();

  // 2. Свежий контекст ТОЛЬКО с remember-cookie (без PHPSESSID).
  const ctx2 = await request.newContext({
    baseURL: BASE,
    storageState: { cookies: [remember], origins: [] },
  });

  // Заход на страницу → init.php должен авто-залогинить по remember-cookie.
  const html = await (await ctx2.get('/')).text();
  const csrf = html.match(/name="csrf-token"\s+content="([^"]+)"/)?.[1];
  expect(csrf, 'csrf token on / after auto-login').toBeTruthy();

  // get_chat_input отдаёт user_data только для авторизованного (Auth::check()).
  const r = await (await ctx2.post('/api.php', {
    form: { action: 'get_chat_input', csrf_token: csrf },
    headers: { 'X-CSRF-Token': csrf },
  })).json();
  expect(r.success, 'get_chat_input ok').toBeTruthy();
  expect(r.data && r.data.user_data && r.data.user_data.user_id,
    `auto-login must establish user session, got ${JSON.stringify(r.data && r.data.user_data)}`).toBeTruthy();

  await ctx2.dispose();
});
