// MLP-257 — панель БД: серверная сортировка, мультифильтр (AND), пагинация
// по отфильтрованному, экспорт CSV с теми же условиями через prepared statements.
//
// Env: MLP_BASE_URL, MLP_LOGIN, MLP_PASS, MLP_ADMIN=1 (обязательно — панель БД).

const { test, expect } = require('@playwright/test');

const BASE = process.env.MLP_BASE_URL;
const LOGIN = process.env.MLP_LOGIN;
const PASS = process.env.MLP_PASS;
const IS_ADMIN = process.env.MLP_ADMIN === '1';

test.use({ ignoreHTTPSErrors: true, viewport: { width: 1600, height: 900 } });

test.beforeEach(() => {
  test.skip(!IS_ADMIN, 'нужен MLP_ADMIN=1 (панель БД только для админа)');
});

async function login(page) {
  await page.goto(BASE + '/login.php', { waitUntil: 'domcontentloaded' });
  await page.fill('#ajax-login-form input[name="username"]', LOGIN);
  await page.fill('#ajax-login-form input[name="password"]', PASS);
  await page.click('#ajax-login-form button[type="submit"]');
  await page.waitForURL(/\/dashboard\//, { timeout: 15000, waitUntil: 'domcontentloaded' });
}

function dbUrl(query) {
  return BASE + '/dashboard/index.php?db_action=view&table=chat_messages&' + query + '#tab-database';
}

/** id из первой строки данных (2-я ячейка: после кнопки 🔧 идут колонки, id — первая). */
async function firstRowId(page) {
  const txt = await page.locator('.db-admin-container tbody tr').first().locator('td').nth(1).innerText();
  return parseInt(txt, 10);
}

test('серверная сортировка: desc поднимает максимальный id со «дна» (MLP-257)', async ({ page }) => {
  await login(page);

  await page.goto(dbUrl('sort=id&dir=asc'), { waitUntil: 'domcontentloaded' });
  const minId = await firstRowId(page);
  await expect(page.locator('.db-admin-container th', { hasText: 'id ▲' })).toBeVisible();

  await page.goto(dbUrl('sort=id&dir=desc'), { waitUntil: 'domcontentloaded' });
  const maxId = await firstRowId(page);
  await expect(page.locator('.db-admin-container th', { hasText: 'id ▼' })).toBeVisible();

  // Сортировка по ВСЕМУ набору: max сильно больше min (в таблице тысячи строк,
  // клиентская сортировка первых 50 такой разницы дать не может).
  expect(maxId, `desc(${maxId}) должен быть заметно больше asc(${minId})`).toBeGreaterThan(minId + 50);

  // Повторный клик по заголовку переключает направление (ссылка ведёт на asc)
  const href = await page.locator('.db-admin-container th a', { hasText: 'id' }).first().getAttribute('href');
  expect(href).toContain('dir=asc');
});

test('мультифильтр: два условия сужают выборку по AND (MLP-257)', async ({ page }) => {
  await login(page);
  await page.goto(dbUrl('filter_col[0]=id&filter_op[0]=%3E%3D&filter_val[0]=10&filter_col[1]=id&filter_op[1]=%3C%3D&filter_val[1]=12&sort=id&dir=asc'),
    { waitUntil: 'domcontentloaded' });

  const rows = page.locator('.db-admin-container tbody tr');
  const n = await rows.count();
  expect(n, 'диапазон 10..12 — не больше 3 строк').toBeLessThanOrEqual(3);
  for (let i = 0; i < n; i++) {
    const id = parseInt(await rows.nth(i).locator('td').nth(1).innerText(), 10);
    expect(id, `строка ${i}: id в диапазоне`).toBeGreaterThanOrEqual(10);
    expect(id).toBeLessThanOrEqual(12);
  }

  // В форме отрендерены обе строки условий (+ пустая для добавления)
  await expect(page.locator('.db-filter-row')).toHaveCount(3);
});

test('пагинация сохраняет фильтры и сортировку (MLP-257)', async ({ page }) => {
  await login(page);
  await page.goto(dbUrl('filter_col[0]=id&filter_op[0]=%3E&filter_val[0]=0&sort=id&dir=asc'), { waitUntil: 'domcontentloaded' });
  const p1last = parseInt(await page.locator('.db-admin-container tbody tr').last().locator('td').nth(1).innerText(), 10);

  const next = page.locator('.db-admin-container .pagination a', { hasText: '→' });
  await expect(next, 'должна быть вторая страница').toBeVisible();
  const href = await next.getAttribute('href');
  expect(href, 'ссылка страницы переносит фильтр').toContain('filter_col');
  expect(href, 'ссылка страницы переносит сортировку').toContain('sort=id');

  await next.click();
  await page.waitForLoadState('domcontentloaded');
  const p2first = await firstRowId(page);
  expect(p2first, 'страница 2 продолжает отсортированный набор').toBeGreaterThan(p1last);
});

test('экспорт CSV уважает фильтры и сортировку, отдаёт text/csv (MLP-257)', async ({ page, context }) => {
  await login(page);
  const html = await (await context.request.get(BASE + '/')).text();
  const csrf = html.match(/name="csrf-token"\s+content="([^"]+)"/)?.[1];
  expect(csrf, 'csrf token').toBeTruthy();

  const res = await context.request.post(BASE + '/api.php', {
    form: {
      action: 'db_export', csrf_token: csrf, table: 'chat_messages',
      'filter_col[0]': 'id', 'filter_op[0]': '>=', 'filter_val[0]': '10',
      'filter_col[1]': 'id', 'filter_op[1]': '<=', 'filter_val[1]': '12',
      sort: 'id', dir: 'desc',
    },
    headers: { 'X-CSRF-Token': csrf },
  });
  expect(res.headers()['content-type'] || '').toContain('text/csv');

  // message — TEXT с возможными переносами внутри кавычек: парсим только строки,
  // начинающиеся с числового id (continuation-строки многострочных полей отпадают).
  const lines = (await res.text()).trim().split('\n').slice(1);
  const ids = lines.filter((l) => /^\d+,/.test(l)).map((l) => parseInt(l, 10));
  expect(ids.length, 'есть отфильтрованные строки').toBeGreaterThan(0);
  expect(new Set(ids).size, 'в диапазоне 10..12 максимум 3 уникальных id').toBeLessThanOrEqual(3);
  for (const id of ids) {
    expect(id).toBeGreaterThanOrEqual(10);
    expect(id).toBeLessThanOrEqual(12);
  }
  const sorted = [...ids].sort((a, b) => b - a);
  expect(ids, 'порядок — по сортировке desc').toEqual(sorted);
});

test('устойчивость: legacy-фильтр работает, неизвестная колонка игнорируется (MLP-257)', async ({ page }) => {
  await login(page);

  // Legacy-одиночный формат (закладки до MLP-257)
  await page.goto(dbUrl('filter_column=id&filter_operator=%3C%3D&filter_value=5&sort=id&dir=asc'), { waitUntil: 'domcontentloaded' });
  const rows = page.locator('.db-admin-container tbody tr');
  const n = await rows.count();
  expect(n).toBeLessThanOrEqual(5);
  expect(n).toBeGreaterThan(0);

  // Неизвестная колонка → условие игнорируется, страница жива
  await page.goto(dbUrl('filter_col[0]=no_such_col&filter_op[0]=%3D&filter_val[0]=x'), { waitUntil: 'domcontentloaded' });
  await expect(page.locator('.db-admin-container table')).toBeVisible();
  expect(await page.locator('.db-admin-container tbody tr').count()).toBeGreaterThan(1);
});
