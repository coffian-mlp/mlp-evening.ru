// MLP-229 — events slice через тонкий роутер + CSRF L4.
// Проверяем: публичное чтение (get_public_events), enforce CSRF на save_event (L4),
// гейт роли (не-админ → Access Denied), happy-path CRUD для админа.
//
// Env: MLP_BASE_URL, MLP_LOGIN, MLP_PASS (тестовый юзер Claude).
//   MLP_ADMIN=1 — если роль Claude на проде выставлена в admin (включает CRUD-тест).

const { test, expect, request } = require('@playwright/test');

const BASE = process.env.MLP_BASE_URL;
const LOGIN = process.env.MLP_LOGIN;
const PASS = process.env.MLP_PASS;
const IS_ADMIN = process.env.MLP_ADMIN === '1';

async function getCsrf(ctx) {
  const html = await (await ctx.get('/')).text();
  return html.match(/name="csrf-token"\s+content="([^"]+)"/)?.[1];
}

// --- Публичное чтение: без авторизации, без CSRF ---
test('get_public_events: публичный endpoint отдаёт события и плейлист (MLP-229)', async () => {
  expect(BASE, 'MLP_BASE_URL must be set').toBeTruthy();
  const ctx = await request.newContext({ baseURL: BASE });

  const res = await (await ctx.post('/api.php', { form: { action: 'get_public_events' } })).json();
  expect(res.success, `get_public_events failed: ${JSON.stringify(res)}`).toBeTruthy();
  expect(Array.isArray(res.data.events), 'data.events must be array').toBeTruthy();
  expect('playlist' in res.data, 'data.playlist must be present').toBeTruthy();

  await ctx.dispose();
});

// --- L4: save_event без CSRF-токена отклоняется ---
test('save_event без CSRF → отказ (L4, MLP-229)', async () => {
  expect(BASE && LOGIN && PASS, 'env must be set').toBeTruthy();
  const ctx = await request.newContext({ baseURL: BASE });

  const login = await (await ctx.post('/api.php', { form: { action: 'login', username: LOGIN, password: PASS } })).json();
  expect(login.success, `login failed: ${JSON.stringify(login)}`).toBeTruthy();

  // Без csrf_token — раньше был bypass для админа, теперь должно отклоняться у всех.
  const res = await (await ctx.post('/api.php', {
    form: { action: 'save_event', title: 'CSRF test', start_time_utc: '2030-01-01 12:00:00' },
  })).json();
  expect(res.success, `save_event without CSRF must be rejected, got ${JSON.stringify(res)}`).toBeFalsy();
  expect(res.message || '', 'must be a security error').toMatch(/безопасност/i);

  await ctx.dispose();
});

// --- Гейт роли: не-админ с валидным CSRF всё равно получает Access Denied ---
test('save_event не-админом → Access Denied (MLP-229)', async () => {
  test.skip(IS_ADMIN, 'роль Claude=admin — негативный тест роли пропущен');
  expect(BASE && LOGIN && PASS, 'env must be set').toBeTruthy();
  const ctx = await request.newContext({ baseURL: BASE });

  const login = await (await ctx.post('/api.php', { form: { action: 'login', username: LOGIN, password: PASS } })).json();
  expect(login.success, 'login failed').toBeTruthy();
  const csrf = await getCsrf(ctx);
  expect(csrf, 'csrf token').toBeTruthy();

  const res = await (await ctx.post('/api.php', {
    form: { action: 'save_event', csrf_token: csrf, title: 'role test', start_time_utc: '2030-01-01 12:00:00' },
    headers: { 'X-CSRF-Token': csrf },
  })).json();
  expect(res.success, `non-admin save_event must be denied, got ${JSON.stringify(res)}`).toBeFalsy();

  await ctx.dispose();
});

// --- Happy-path CRUD (только когда Claude=admin) ---
test('admin: create → visible → delete событие (MLP-229)', async () => {
  test.skip(!IS_ADMIN, 'нужен MLP_ADMIN=1 (роль Claude=admin на проде)');
  expect(BASE && LOGIN && PASS, 'env must be set').toBeTruthy();
  const ctx = await request.newContext({ baseURL: BASE });

  const login = await (await ctx.post('/api.php', { form: { action: 'login', username: LOGIN, password: PASS } })).json();
  expect(login.success, 'login failed').toBeTruthy();
  const csrf = await getCsrf(ctx);
  expect(csrf, 'csrf token').toBeTruthy();

  const marker = 'PW-MLP229-' + Date.now();

  // create
  const created = await (await ctx.post('/api.php', {
    form: { action: 'save_event', csrf_token: csrf, title: marker, start_time_utc: '2030-01-01 12:00:00', duration_minutes: '90', color: '#6d2f8e' },
    headers: { 'X-CSRF-Token': csrf },
  })).json();
  expect(created.success, `create failed: ${JSON.stringify(created)}`).toBeTruthy();

  // visible через публичный endpoint
  const list = await (await ctx.post('/api.php', { form: { action: 'get_public_events' } })).json();
  const found = (list.data.events || []).find((e) => e.title === marker);
  expect(found, 'созданное событие должно быть в get_public_events').toBeTruthy();

  // delete (cleanup)
  const del = await (await ctx.post('/api.php', {
    form: { action: 'delete_event', csrf_token: csrf, id: String(found.id) },
    headers: { 'X-CSRF-Token': csrf },
  })).json();
  expect(del.success, `delete failed: ${JSON.stringify(del)}`).toBeTruthy();

  // подтверждаем удаление
  const list2 = await (await ctx.post('/api.php', { form: { action: 'get_public_events' } })).json();
  expect((list2.data.events || []).some((e) => e.title === marker), 'событие должно быть удалено').toBeFalsy();

  await ctx.dispose();
});
