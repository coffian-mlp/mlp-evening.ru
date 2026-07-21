// MLP-260 — полировка перед тегом v4.9.0: раскладка шапки (лого слева),
// живой кастомный селект пака после AJAX, «Расписание» ушло из меню чата,
// новые опции Лиры в форме ИИ.
//
// Env: MLP_BASE_URL, MLP_LOGIN, MLP_PASS; MLP_ADMIN=1 — админ-сценарии.

const { test, expect } = require('@playwright/test');

const BASE = process.env.MLP_BASE_URL;
const LOGIN = process.env.MLP_LOGIN;
const PASS = process.env.MLP_PASS;
const IS_ADMIN = process.env.MLP_ADMIN === '1';

test.use({ ignoreHTTPSErrors: true, viewport: { width: 1600, height: 900 } });

async function login(page) {
  await page.goto(BASE + '/login.php', { waitUntil: 'domcontentloaded' });
  await page.fill('#ajax-login-form input[name="username"]', LOGIN);
  await page.fill('#ajax-login-form input[name="password"]', PASS);
  await page.click('#ajax-login-form button[type="submit"]');
  await page.waitForURL((url) => !url.pathname.includes('login.php'), { timeout: 15000, waitUntil: 'domcontentloaded' });
}

test('шапка: логотип прижат влево, меню за ним (MLP-260)', async ({ page }) => {
  await page.goto(BASE + '/schedule.php', { waitUntil: 'domcontentloaded' });

  const logo = await page.locator('.main-header .logo-area').boundingBox();
  const nav = await page.locator('.main-header .site-nav').boundingBox();
  const viewport = page.viewportSize();

  expect(logo.x, 'логотип у левого края (не улетел вправо)').toBeLessThan(viewport.width * 0.15);
  expect(nav.x, 'меню правее логотипа').toBeGreaterThan(logo.x + logo.width - 1);
});

test('главная: сенобургер, лого, header-пункты в пустоте справа (MLP-260)', async ({ page }) => {
  await page.goto(BASE + '/', { waitUntil: 'domcontentloaded' });

  const burger = await page.locator('.video-container .site-burger-btn').boundingBox();
  const logo = await page.locator('.video-container .header img.logo').boundingBox();
  const viewport = page.viewportSize();

  expect(burger.x, 'бургер у левого края').toBeLessThan(viewport.width * 0.1);
  expect(logo.x, 'логотип сразу за бургером, не у правого края').toBeLessThan(viewport.width * 0.25);
  expect(logo.x, 'логотип правее бургера').toBeGreaterThan(burger.x);

  // MLP-260: header-набор рендерится и на главной — горизонталью правее лого
  const nav = page.locator('.video-container .site-nav-stream');
  await expect(nav.locator('.site-nav-link', { hasText: 'Расписание' })).toBeVisible();
  const navBox = await nav.boundingBox();
  expect(navBox.x, 'горизонтальные пункты — правее логотипа').toBeGreaterThan(logo.x + logo.width - 1);
});

test('меню чата: пункта «Расписание» больше нет (MLP-260)', async ({ page }) => {
  await login(page);
  await page.goto(BASE + '/', { waitUntil: 'domcontentloaded' });

  await page.click('#chat-menu-btn');
  const dropdown = page.locator('#chat-dropdown-menu');
  await expect(dropdown).toBeVisible();
  await expect(dropdown.locator('a', { hasText: 'Расписание' })).toHaveCount(0);
  await expect(dropdown.locator('a', { hasText: 'Поиск сообщений' })).toBeVisible(); // меню живое
});

test('админ: кастомный селект пака работает после AJAX-заполнения (MLP-260)', async ({ page }) => {
  test.skip(!IS_ADMIN, 'нужен MLP_ADMIN=1');
  await login(page);
  await page.goto(BASE + '/dashboard/#tab-stickers', { waitUntil: 'domcontentloaded' });

  // Ждём данные (loadPacks перестраивает кастомный UI)
  await expect(page.locator('#stickers-table tbody img').first()).toBeAttached({ timeout: 15000 });

  const wrapper = page.locator('#sticker-pack-select').locator('xpath=..');
  const label = wrapper.locator('.select-selected');
  await expect(label, 'placeholder «Загрузка...» ушёл').not.toHaveText(/Загрузка/);

  // Открываем кастомный дропдаун и выбираем первый реальный пак
  await label.click();
  const options = wrapper.locator('.select-items div');
  await expect(options.nth(1)).toBeVisible(); // 0 = «-- Выбрать пак --»
  const packName = await options.nth(1).innerText();
  await options.nth(1).click();

  await expect(label).toHaveText(packName);
  const value = await page.locator('#sticker-pack-select').inputValue();
  expect(value, 'нативный select получил id пака').not.toBe('');
});

test('админ: опции Лиры — тишина после приветствия и длина контекста (MLP-260)', async ({ page }) => {
  test.skip(!IS_ADMIN, 'нужен MLP_ADMIN=1');
  await login(page);
  await page.goto(BASE + '/dashboard/#tab-bot', { waitUntil: 'domcontentloaded' });

  await expect(page.locator('input[name="ai_greeting_cooldown"]')).toBeVisible();
  const ctx = page.locator('input[name="ai_context_messages"]');
  await expect(ctx).toBeVisible();
  await expect(ctx).toHaveAttribute('min', '4');
  await expect(ctx).toHaveAttribute('max', '100');
});
