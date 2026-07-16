// MLP-230 (H3) — DbAdmin: whitelist таблиц/колонок из схемы.
// Проверяем, что get_row/update_row отклоняют неизвестную таблицу («Invalid table»)
// и пропускают реальную. Endpoint — /dashboard/index.php?db_action=... (нужен admin).
//
// Env: MLP_BASE_URL, MLP_LOGIN, MLP_PASS.
//   MLP_ADMIN=1 — роль Claude на проде выставлена в admin (иначе тесты пропускаются).

const { test, expect, request } = require('@playwright/test');

const BASE = process.env.MLP_BASE_URL;
const LOGIN = process.env.MLP_LOGIN;
const PASS = process.env.MLP_PASS;
const IS_ADMIN = process.env.MLP_ADMIN === '1';

async function adminCtx() {
  const ctx = await request.newContext({ baseURL: BASE });
  const login = await (await ctx.post('/api.php', { form: { action: 'login', username: LOGIN, password: PASS } })).json();
  expect(login.success, `login failed: ${JSON.stringify(login)}`).toBeTruthy();
  return ctx;
}

test.beforeEach(() => {
  test.skip(!IS_ADMIN, 'нужен MLP_ADMIN=1 (роль Claude=admin на проде)');
  expect(BASE && LOGIN && PASS, 'env must be set').toBeTruthy();
});

test('get_row: неизвестная таблица → Invalid table (H3)', async () => {
  const ctx = await adminCtx();
  const res = await (await ctx.get('/dashboard/index.php?db_action=get_row&table=zzz_bogus_table&id=1')).json();
  expect(res.success, `bogus table must be rejected: ${JSON.stringify(res)}`).toBeFalsy();
  expect(res.message || '', 'message must mention invalid table').toMatch(/invalid table/i);
  await ctx.dispose();
});

test('get_row: реальная таблица (users) → success (H3)', async () => {
  const ctx = await adminCtx();
  // id=19 — тестовый пользователь Claude на проде; для локального стенда поправь при надобности.
  const res = await (await ctx.get('/dashboard/index.php?db_action=get_row&table=users&id=19')).json();
  expect(res.success, `real table must work: ${JSON.stringify(res)}`).toBeTruthy();
  await ctx.dispose();
});

test('update_row: неизвестная таблица → Invalid table (H3)', async () => {
  const ctx = await adminCtx();
  const res = await (await ctx.post('/dashboard/index.php', {
    form: { db_action: 'update_row', table: 'zzz_bogus_table', __pk_value: '1' },
  })).json();
  expect(res.success, `bogus update must be rejected: ${JSON.stringify(res)}`).toBeFalsy();
  expect(res.message || '', 'message must mention invalid table').toMatch(/invalid table/i);
  await ctx.dispose();
});
