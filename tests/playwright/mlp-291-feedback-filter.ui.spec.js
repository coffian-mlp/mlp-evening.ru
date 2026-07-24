// MLP-291 — фильтр беклога (/todo) в дашборде: нативный селект скрыт кастомным
// виджетом (прецедент: inline display:inline-block перебивал display:none обёртки,
// и рядом с виджетом торчал второй, нативный селект). Фильтрация через виджет работает.
//
// Env: MLP_BASE_URL, MLP_LOGIN, MLP_PASS; MLP_ADMIN=1 — обязателен (вкладка дашборда).

const { test, expect } = require('@playwright/test');

const BASE = process.env.MLP_BASE_URL;
const LOGIN = process.env.MLP_LOGIN;
const PASS = process.env.MLP_PASS;
const IS_ADMIN = process.env.MLP_ADMIN === '1';

test.use({ ignoreHTTPSErrors: true, viewport: { width: 1500, height: 1000 } });

test('беклог: один видимый фильтр, нативный селект скрыт, фильтрация работает (MLP-291)', async ({ page }) => {
  test.skip(!IS_ADMIN, 'нужен MLP_ADMIN=1');

  await page.goto(BASE + '/login.php', { waitUntil: 'domcontentloaded' });
  await page.fill('#ajax-login-form input[name="username"]', LOGIN);
  await page.fill('#ajax-login-form input[name="password"]', PASS);
  await page.click('#ajax-login-form button[type="submit"]');
  await page.waitForURL((url) => !url.pathname.includes('login.php'), { timeout: 15000, waitUntil: 'domcontentloaded' });

  await page.goto(BASE + '/dashboard/', { waitUntil: 'domcontentloaded' });
  await page.click('.nav-tile[data-target="#tab-bot"]');

  const card = page.locator('.card', { has: page.locator('h3', { hasText: 'Беклог от пользователей' }) });
  await expect(card).toBeVisible();

  // Нативный селект в DOM один и он СКРЫТ (id уникален, обёртка его прячет).
  const native = card.locator('#fb-status-filter');
  await expect(native).toHaveCount(1);
  await expect(native).toBeHidden();
  // Виден ровно один кастомный виджет фильтра.
  const widget = card.locator('.custom-select-wrapper .select-selected');
  await expect(widget).toHaveCount(1);
  await expect(widget).toBeVisible();

  // Фильтрация через виджет дёргает загрузку: «Все» → счётчик обновился.
  const count = card.locator('#fb-count');
  await expect(count).toContainText('в выборке', { timeout: 10000 });
  await widget.click();
  await card.locator('.select-items div', { hasText: 'Все' }).click();
  await expect(widget).toHaveText('Все');
  await expect(count).toContainText('в выборке', { timeout: 10000 });
});
