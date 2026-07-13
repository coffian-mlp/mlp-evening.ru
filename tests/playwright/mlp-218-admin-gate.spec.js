// MLP-218 — admin-gate на update_settings (findings C1/C2).
// API-уровневый тест (Playwright request-контекст, без браузера): не-админ
// не видит форму настроек в UI, поэтому уязвимость достижима только прямым
// POST в api.php — проверяем именно это.
//
// Креды НЕ хранятся в файле — берутся из env (тестовый юзер Claude на проде):
//   MLP_BASE_URL   базовый URL (напр. https://mlp-evening.ru)
//   MLP_LOGIN      логин тестового пользователя
//   MLP_PASS       пароль тестового пользователя
//   MLP_EXPECT     'denied'  — ожидаем Access Denied (роль не-админ)  → AC-1
//                  'allowed' — ожидаем успех (роль admin)            → AC-2
//
// Запуск: см. tests/playwright/README.md

const { test, expect, request } = require('@playwright/test');

const BASE = process.env.MLP_BASE_URL;
const LOGIN = process.env.MLP_LOGIN;
const PASS = process.env.MLP_PASS;
const EXPECT = process.env.MLP_EXPECT || 'denied';

test(`update_settings gate → expect=${EXPECT}`, async () => {
  expect(BASE && LOGIN && PASS, 'MLP_BASE_URL/MLP_LOGIN/MLP_PASS must be set').toBeTruthy();

  const ctx = await request.newContext({ baseURL: BASE });

  // 1. Логин (до авторизации CSRF не требуется). Сессионная кука сохранится в ctx.
  const loginRes = await ctx.post('/api.php', {
    form: { action: 'login', username: LOGIN, password: PASS },
  });
  const login = await loginRes.json();
  expect(login.success, `login failed: ${JSON.stringify(login)}`).toBeTruthy();

  // 2. Достаём CSRF-токен из <meta name="csrf-token"> (header.php).
  const html = await (await ctx.get('/')).text();
  const m = html.match(/name="csrf-token"\s+content="([^"]+)"/);
  expect(m, 'csrf-token meta not found on /').toBeTruthy();
  const csrf = m[1];

  // 3. POST update_settings БЕЗ полей настроек — безопасный no-op:
  //    если гейт пройден, все isset()-блоки пропущены, ничего не пишется,
  //    case доходит до sendResponse(true, "Настройки обновлены!").
  const res = await ctx.post('/api.php', {
    form: { action: 'update_settings', csrf_token: csrf },
    headers: { 'X-CSRF-Token': csrf },
  });
  const json = await res.json();

  if (EXPECT === 'denied') {
    // AC-1: не-админ заблокирован, настройки не тронуты.
    expect(json.success, `expected denial, got: ${JSON.stringify(json)}`).toBeFalsy();
    expect(json.message || '').toContain('Access Denied');
  } else {
    // AC-2: админ проходит гейт (регресса нет).
    expect(json.message || '').not.toContain('Access Denied');
    expect(json.success, `expected admin success, got: ${JSON.stringify(json)}`).toBeTruthy();
  }

  await ctx.dispose();
});
