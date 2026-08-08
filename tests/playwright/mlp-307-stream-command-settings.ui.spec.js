// MLP-307 — настройки команд стрима в дашборде (вкладка «Бот», форма ИИ).
// Заменяет mlp-304 («дух машины»): поля machine_spirit_* упразднены, вместо них
// stream_command_*. Read-only: «Сохранить» не нажимается, боевые настройки не мутируются.
//
// Env: MLP_BASE_URL, MLP_LOGIN, MLP_PASS (тестовый юзер Claude), MLP_ADMIN=1.

const { test, expect } = require('@playwright/test');

const BASE = process.env.MLP_BASE_URL;
const LOGIN = process.env.MLP_LOGIN;
const PASS = process.env.MLP_PASS;
const IS_ADMIN = process.env.MLP_ADMIN === '1';

test.use({ ignoreHTTPSErrors: true, viewport: { width: 1600, height: 900 } });

test('админ: секция «Команды стрима» в настройках ИИ, поля загружены (MLP-307)', async ({ page }) => {
  test.skip(!IS_ADMIN, 'нужен MLP_ADMIN=1');
  expect(BASE, 'MLP_BASE_URL must be set').toBeTruthy();

  await page.goto(BASE + '/dashboard/', { waitUntil: 'domcontentloaded' });
  await page.fill('#ajax-login-form input[name="username"]', LOGIN);
  await page.fill('#ajax-login-form input[name="password"]', PASS);
  await page.click('#ajax-login-form button[type="submit"]');
  await page.waitForURL(/\/dashboard\//, { timeout: 15000, waitUntil: 'domcontentloaded' });

  await page.click('.nav-tile[data-target="#tab-bot"]');
  await expect(page.locator('#tab-bot')).toBeVisible();

  await expect(page.locator('#tab-bot h4', { hasText: 'Команды стрима' })).toBeVisible();

  await expect(page.locator('#tab-bot input[name="stream_command_enabled"][type="checkbox"]')).toBeAttached();
  await expect(page.locator('#tab-bot input[name="stream_command_owner_id"]')).toBeAttached();
  await expect(page.locator('#tab-bot input[name="stream_command_cooldown"]')).toBeAttached();

  await expect(page.locator('#tab-bot input[name="stream_command_owner_id"]')).toHaveValue(/^\d+$/);
  await expect(page.locator('#tab-bot input[name="stream_command_cooldown"]')).toHaveValue(/^\d+$/);

  // Поля упразднённого «духа машины» из формы удалены
  await expect(page.locator('#tab-bot [name^="machine_spirit_"]')).toHaveCount(0);
});
