// MLP-255 — админский пласт api.php через тонкий роутер (контроллеры src/Api/).
// Проверяем: публичность чтения стикеров (гость), гейты ролей на admin/moderator
// actions, CRUD команд бота через новый транспорт, db_get_row/db_export для
// панели БД, CSRF-гейт на db_update_row. Без деструктивных операций на проде.
//
// Env: MLP_BASE_URL, MLP_LOGIN, MLP_PASS (тестовый юзер Claude).
//   MLP_ADMIN=1 — если роль Claude на проде выставлена в admin (включает admin-тесты).

const { test, expect, request } = require('@playwright/test');

const BASE = process.env.MLP_BASE_URL;
const LOGIN = process.env.MLP_LOGIN;
const PASS = process.env.MLP_PASS;
const IS_ADMIN = process.env.MLP_ADMIN === '1';

async function getCsrf(ctx) {
  const html = await (await ctx.get('/')).text();
  return html.match(/name="csrf-token"\s+content="([^"]+)"/)?.[1];
}

async function loginCtx() {
  const ctx = await request.newContext({ baseURL: BASE });
  const login = await (await ctx.post('/api.php', { form: { action: 'login', username: LOGIN, password: PASS } })).json();
  expect(login.success, `login failed: ${JSON.stringify(login)}`).toBeTruthy();
  const csrf = await getCsrf(ctx);
  expect(csrf, 'csrf token').toBeTruthy();
  return { ctx, csrf };
}

// --- Гость: чтение стикеров осталось публичным (пикер чата) ---
test('get_stickers/get_packs: публичны для гостя (MLP-255)', async () => {
  expect(BASE, 'MLP_BASE_URL must be set').toBeTruthy();
  const ctx = await request.newContext({ baseURL: BASE });

  const stickers = await (await ctx.post('/api.php', { form: { action: 'get_stickers' } })).json();
  expect(stickers.success, `get_stickers failed: ${JSON.stringify(stickers).slice(0, 200)}`).toBeTruthy();
  expect(Array.isArray(stickers.data.stickers), 'data.stickers must be array').toBeTruthy();

  const packs = await (await ctx.post('/api.php', { form: { action: 'get_packs' } })).json();
  expect(packs.success, 'get_packs failed').toBeTruthy();
  expect(Array.isArray(packs.data.packs), 'data.packs must be array').toBeTruthy();

  await ctx.dispose();
});

// --- Гость: admin- и moderator-actions закрыты ---
test('гейты ролей: guest не проходит в admin/moderator actions (MLP-255)', async () => {
  expect(BASE, 'MLP_BASE_URL must be set').toBeTruthy();
  const ctx = await request.newContext({ baseURL: BASE });

  for (const action of ['update_settings', 'get_users', 'save_bot_command', 'db_get_row', 'purge_messages']) {
    const res = await (await ctx.post('/api.php', { form: { action } })).json();
    expect(res.success, `guest '${action}' must be denied, got ${JSON.stringify(res).slice(0, 200)}`).toBeFalsy();
  }

  await ctx.dispose();
});

// --- Admin: update_settings через роутер (без ключей — ничего не меняет) ---
test('admin: update_settings отвечает через новый транспорт (MLP-255)', async () => {
  test.skip(!IS_ADMIN, 'нужен MLP_ADMIN=1 (роль Claude=admin на проде)');
  const { ctx, csrf } = await loginCtx();

  // Ни одного ключа настроек не передаём — контроллер отвечает success, ничего не записав.
  const res = await (await ctx.post('/api.php', {
    form: { action: 'update_settings', csrf_token: csrf },
    headers: { 'X-CSRF-Token': csrf },
  })).json();
  expect(res.success, `update_settings failed: ${JSON.stringify(res)}`).toBeTruthy();

  await ctx.dispose();
});

// --- Admin: CRUD команды бота через api.php (создать → увидеть → удалить) ---
test('admin: save/delete_bot_command через api.php (MLP-255)', async () => {
  test.skip(!IS_ADMIN, 'нужен MLP_ADMIN=1');
  const { ctx, csrf } = await loginCtx();

  const marker = '/pw255-' + Date.now();

  const created = await (await ctx.post('/api.php', {
    form: {
      action: 'save_bot_command', csrf_token: csrf, id: '0',
      command_prefix: marker, description: 'Playwright MLP-255', handler_type: 'text',
      system_prompt: 'test', is_active: '1',
    },
    headers: { 'X-CSRF-Token': csrf },
  })).json();
  expect(created.success, `save_bot_command failed: ${JSON.stringify(created)}`).toBeTruthy();

  // Команда видна в дашборде (список рендерится сервером)
  const html = await (await ctx.get('/dashboard/index.php')).text();
  expect(html.includes(marker), 'созданная команда должна быть в дашборде').toBeTruthy();

  // Достаём id из data-cmd JSON нужной строки и удаляем (cleanup)
  const row = html.split('\n').find((l) => l.includes('data-cmd') && l.includes(marker));
  const id = row && JSON.parse(row.match(/data-cmd="([^"]+)"/)[1].replace(/&quot;/g, '"')).id;
  expect(id, 'id созданной команды').toBeTruthy();

  const del = await (await ctx.post('/api.php', {
    form: { action: 'delete_bot_command', csrf_token: csrf, id: String(id) },
    headers: { 'X-CSRF-Token': csrf },
  })).json();
  expect(del.success, `delete_bot_command failed: ${JSON.stringify(del)}`).toBeTruthy();

  const html2 = await (await ctx.get('/dashboard/index.php')).text();
  expect(html2.includes(marker), 'команда должна быть удалена').toBeFalsy();

  await ctx.dispose();
});

// --- Admin: панель БД — чтение строки и экспорт CSV через api.php ---
test('admin: db_get_row и db_export через api.php (MLP-255)', async () => {
  test.skip(!IS_ADMIN, 'нужен MLP_ADMIN=1');
  const { ctx, csrf } = await loginCtx();

  const row = await (await ctx.post('/api.php', {
    form: { action: 'db_get_row', csrf_token: csrf, table: 'users', id: '1' },
    headers: { 'X-CSRF-Token': csrf },
  })).json();
  expect(row.success, `db_get_row failed: ${JSON.stringify(row).slice(0, 200)}`).toBeTruthy();
  expect(row.pk, 'pk в ответе (контракт модалки)').toBeTruthy();
  expect(row.types, 'types в ответе (контракт модалки)').toBeTruthy();

  const exportRes = await ctx.post('/api.php', {
    form: { action: 'db_export', csrf_token: csrf, table: 'sticker_packs' },
    headers: { 'X-CSRF-Token': csrf },
  });
  expect(exportRes.headers()['content-type'] || '', 'экспорт должен отдавать CSV').toContain('text/csv');
  expect((exportRes.headers()['content-disposition'] || '')).toContain('attachment');

  await ctx.dispose();
});

// --- Admin: db_update_row без CSRF отклоняется (гейт api.php) ---
test('db_update_row без CSRF → отказ (MLP-255)', async () => {
  test.skip(!IS_ADMIN, 'нужен MLP_ADMIN=1');
  const { ctx } = await loginCtx();

  const res = await (await ctx.post('/api.php', {
    form: { action: 'db_update_row', table: 'users', __pk_value: '1', nickname: 'hacked' },
  })).json();
  expect(res.success, `db_update_row without CSRF must be rejected: ${JSON.stringify(res)}`).toBeFalsy();
  expect(res.message || '', 'must be a security error').toMatch(/безопасност/i);

  await ctx.dispose();
});
