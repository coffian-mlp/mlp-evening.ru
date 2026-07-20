// MLP-256 — каркас дашборда (7 вкладок, полная ширина, компактное меню) + /login.
// Браузерные сценарии: гейт неавторизованного, вход админа со страницы /login.php,
// обход всех вкладок (с ожиданием lazy-данных), legacy-хеши, полная ширина,
// вход не-админа → главная.
//
// Env: MLP_BASE_URL, MLP_LOGIN, MLP_PASS (тестовый юзер Claude).
//   MLP_ADMIN=1 — роль Claude=admin (админ-сценарии); без него — сценарий не-админа.

const { test, expect } = require('@playwright/test');

const BASE = process.env.MLP_BASE_URL;
const LOGIN = process.env.MLP_LOGIN;
const PASS = process.env.MLP_PASS;
const IS_ADMIN = process.env.MLP_ADMIN === '1';

test.use({ ignoreHTTPSErrors: true, viewport: { width: 1600, height: 900 } });

async function uiLogin(page) {
  await page.goto(BASE + '/login.php', { waitUntil: 'domcontentloaded' });
  await page.fill('#ajax-login-form input[name="username"]', LOGIN);
  await page.fill('#ajax-login-form input[name="password"]', PASS);
  await page.click('#ajax-login-form button[type="submit"]');
  await page.waitForURL((url) => !url.pathname.includes('login.php'), { timeout: 15000, waitUntil: 'domcontentloaded' });
}

test('гость: /dashboard/ редиректит на /login.php с redirect (MLP-256)', async ({ page }) => {
  expect(BASE, 'MLP_BASE_URL must be set').toBeTruthy();
  await page.goto(BASE + '/dashboard/', { waitUntil: 'domcontentloaded' });
  await expect(page).toHaveURL(/login\.php\?redirect=/);
  await expect(page.locator('#ajax-login-form')).toBeVisible();
  // Регистрации на странице нет
  await expect(page.locator('#register-form-wrapper')).toHaveCount(0);
});

test('админ: вход с /login.php → дашборд, «Настройки» активны, 7 вкладок (MLP-256)', async ({ page }) => {
  test.skip(!IS_ADMIN, 'нужен MLP_ADMIN=1');
  await page.goto(BASE + '/dashboard/', { waitUntil: 'domcontentloaded' }); // гость → login.php?redirect=/dashboard/
  await page.fill('#ajax-login-form input[name="username"]', LOGIN);
  await page.fill('#ajax-login-form input[name="password"]', PASS);
  await page.click('#ajax-login-form button[type="submit"]');

  await page.waitForURL(/\/dashboard\//, { timeout: 15000, waitUntil: 'domcontentloaded' });
  await expect(page.locator('.nav-tile')).toHaveCount(7);
  await expect(page.locator('.nav-tile.active .label')).toHaveText('Настройки');
  await expect(page.locator('#tab-settings')).toBeVisible();
});

test('админ: обход 7 вкладок, lazy-данные подгружаются (MLP-256)', async ({ page }) => {
  test.skip(!IS_ADMIN, 'нужен MLP_ADMIN=1');
  await uiLogin(page);
  await page.goto(BASE + '/dashboard/', { waitUntil: 'domcontentloaded' });

  // Вкладка «Пользователи»: ждём get_users при клике
  const usersResp = page.waitForResponse((r) =>
    r.url().includes('/api.php') && (r.request().postData() || '').includes('action=get_users'));
  await page.click('.nav-tile[data-target="#tab-users"]');
  await usersResp;
  await expect(page.locator('#tab-users')).toBeVisible();

  // Вкладка «Стикеры»: ждём get_stickers
  const stickersResp = page.waitForResponse((r) =>
    r.url().includes('/api.php') && (r.request().postData() || '').includes('action=get_stickers'));
  await page.click('.nav-tile[data-target="#tab-stickers"]');
  await stickersResp;
  await expect(page.locator('#tab-stickers')).toBeVisible();

  // Остальные вкладки: панель становится видимой, хеш пишется в URL
  for (const tab of ['#tab-bot', '#tab-episodes', '#tab-events', '#tab-database', '#tab-settings']) {
    await page.click(`.nav-tile[data-target="${tab}"]`);
    await expect(page.locator(tab)).toBeVisible();
    expect(page.url()).toContain(tab);
  }

  // Ничего не потеряно: ключевые блоки на новых местах
  await page.click('.nav-tile[data-target="#tab-bot"]');
  await expect(page.locator('#tab-bot #bot-command-form')).toBeAttached();
  await page.click('.nav-tile[data-target="#tab-episodes"]');
  await expect(page.locator('#tab-episodes #watch-history-table')).toBeAttached();
  await expect(page.locator('#tab-episodes .card.danger-zone')).toBeAttached();
});

test('legacy-хеши открывают новые вкладки (MLP-256)', async ({ page }) => {
  test.skip(!IS_ADMIN, 'нужен MLP_ADMIN=1');
  await uiLogin(page);

  const cases = [
    ['#tab-controls', '#tab-settings'],
    ['#tab-bot-commands', '#tab-bot'],
    ['#tab-history', '#tab-episodes'],
    ['#tab-moderation', '#tab-users'],
  ];
  for (const [legacy, target] of cases) {
    await page.goto(BASE + '/dashboard/' + legacy, { waitUntil: 'domcontentloaded' });
    await expect(page.locator(target), `${legacy} → ${target}`).toBeVisible();
  }
});

test('полная ширина: контейнер ≥ 90% окна на 1600px (MLP-256)', async ({ page }) => {
  test.skip(!IS_ADMIN, 'нужен MLP_ADMIN=1');
  await uiLogin(page);
  await page.goto(BASE + '/dashboard/', { waitUntil: 'domcontentloaded' });

  const width = await page.evaluate(() =>
    document.querySelector('.dashboard-layout .container').getBoundingClientRect().width);
  expect(width, 'контент должен занимать почти всю ширину').toBeGreaterThan(1600 * 0.9);

  // Нет горизонтального скролла страницы
  const overflow = await page.evaluate(() =>
    document.documentElement.scrollWidth - document.documentElement.clientWidth);
  expect(overflow, 'страница не должна скроллиться горизонтально').toBeLessThanOrEqual(0);

  // Меню в одну строку: все 7 табов на одной высоте
  const tops = await page.$$eval('.nav-tile', (els) => els.map((e) => e.getBoundingClientRect().top));
  expect(new Set(tops.map((t) => Math.round(t))).size, 'все табы в одной строке').toBe(1);
});

test('не-админ: после входа с /login.php уводит на главную (MLP-256)', async ({ page }) => {
  test.skip(IS_ADMIN, 'сценарий для роли user (запусти без MLP_ADMIN)');
  await page.goto(BASE + '/login.php', { waitUntil: 'domcontentloaded' });
  await page.fill('#ajax-login-form input[name="username"]', LOGIN);
  await page.fill('#ajax-login-form input[name="password"]', PASS);
  await page.click('#ajax-login-form button[type="submit"]');
  await page.waitForURL((url) => !url.pathname.includes('login.php'), { timeout: 15000, waitUntil: 'domcontentloaded' });
  expect(new URL(page.url()).pathname, 'не-админ должен оказаться на главной').toBe('/');
});
