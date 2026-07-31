// MLP-304 — настройки «духа машины» в дашборде (вкладка «Бот», форма ИИ).
// Проверяется наличие и корректная загрузка полей machine_spirit_* (read-only:
// «Сохранить» не нажимается, боевые настройки не мутируются).
//
// Env: MLP_BASE_URL, MLP_LOGIN, MLP_PASS (тестовый юзер Claude), MLP_ADMIN=1.

const { test, expect } = require('@playwright/test');

const BASE = process.env.MLP_BASE_URL;
const LOGIN = process.env.MLP_LOGIN;
const PASS = process.env.MLP_PASS;
const IS_ADMIN = process.env.MLP_ADMIN === '1';

test.use({ ignoreHTTPSErrors: true, viewport: { width: 1600, height: 900 } });

test('админ: секция «Дух машины» в настройках ИИ, поля загружены (MLP-304)', async ({ page }) => {
  test.skip(!IS_ADMIN, 'нужен MLP_ADMIN=1');
  expect(BASE, 'MLP_BASE_URL must be set').toBeTruthy();

  await page.goto(BASE + '/dashboard/', { waitUntil: 'domcontentloaded' });
  await page.fill('#ajax-login-form input[name="username"]', LOGIN);
  await page.fill('#ajax-login-form input[name="password"]', PASS);
  await page.click('#ajax-login-form button[type="submit"]');
  await page.waitForURL(/\/dashboard\//, { timeout: 15000, waitUntil: 'domcontentloaded' });

  await page.click('.nav-tile[data-target="#tab-bot"]');
  await expect(page.locator('#tab-bot')).toBeVisible();

  // Заголовок секции
  await expect(page.locator('#tab-bot h4', { hasText: 'Дух машины' })).toBeVisible();

  // Поля присутствуют (чекбокс + скрытый парный input дают count=2 у enabled)
  await expect(page.locator('#tab-bot input[name="machine_spirit_enabled"][type="checkbox"]')).toBeAttached();
  await expect(page.locator('#tab-bot textarea[name="machine_spirit_prompt"]')).toBeAttached();
  await expect(page.locator('#tab-bot input[name="machine_spirit_user_login"]')).toBeAttached();
  await expect(page.locator('#tab-bot input[name="machine_spirit_owner_id"]')).toBeAttached();
  await expect(page.locator('#tab-bot input[name="machine_spirit_cooldown"]')).toBeAttached();

  // Значения загружены из site_options (на проде фича включена, логин Claude)
  await expect(page.locator('#tab-bot input[name="machine_spirit_user_login"]')).toHaveValue(/\S+/);
  await expect(page.locator('#tab-bot input[name="machine_spirit_owner_id"]')).toHaveValue(/^\d+$/);

  // Встроенный промпт подсказкой (DEFAULT_PROMPT доступен шаблону)
  const placeholder = await page.locator('#tab-bot textarea[name="machine_spirit_prompt"]').getAttribute('placeholder');
  expect(placeholder).toContain('Adeptus Mechanicus');

  // Правило дрейфа (MLP-285): у каждого поля секции есть ключ в whitelist —
  // проверяется юнит-тестом test_settings_keys.php; здесь только UI-наличие.
});
