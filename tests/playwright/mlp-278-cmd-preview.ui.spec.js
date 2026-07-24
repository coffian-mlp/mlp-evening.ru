// MLP-278 — превью команд при вводе «/»: дропдаун со списком команд бота,
// фильтрация по мере набора, подстановка кликом. Проверяем на главной (embedded).
// Env: MLP_BASE_URL, MLP_LOGIN, MLP_PASS.

const { test, expect } = require('@playwright/test');

const BASE = process.env.MLP_BASE_URL;
const LOGIN = process.env.MLP_LOGIN;
const PASS = process.env.MLP_PASS;

test('превью команд: «/» открывает список, фильтр работает, клик подставляет (MLP-278)', async ({ page }) => {
  test.skip(!BASE, 'MLP_BASE_URL must be set');
  const res = await page.request.post(BASE + '/api.php', {
    form: { action: 'login', username: LOGIN, password: PASS },
  });
  expect((await res.json()).success).toBeTruthy();

  await page.goto(BASE + '/', { waitUntil: 'domcontentloaded' });

  // Данные команд отданы шаблоном
  const cmds = await page.evaluate(() => window.botCommands || []);
  expect(cmds.length, 'botCommands непуст').toBeGreaterThan(0);
  expect(cmds.some(c => c.prefix === '/todo'), '/todo в списке').toBeTruthy();

  // «/» открывает превью
  await page.fill('#chat-input', '/');
  await expect(page.locator('#cmd-preview')).toBeVisible();
  const total = await page.locator('.cmd-preview-item').count();
  expect(total).toBeGreaterThan(1);

  // Фильтрация
  await page.fill('#chat-input', '/то');
  await expect(page.locator('.cmd-preview-item')).toHaveCount(1);
  await expect(page.locator('.cmd-preview-prefix').first()).toHaveText('/todo');

  // Клик подставляет префикс с пробелом и закрывает превью
  await page.locator('.cmd-preview-item').first().click();
  await expect(page.locator('#chat-input')).toHaveValue('/todo ');
  await expect(page.locator('#cmd-preview')).toBeHidden();

  // Пробел после команды — превью не всплывает заново
  await page.fill('#chat-input', '/todo проверить превью');
  await expect(page.locator('#cmd-preview')).toBeHidden();

  // Escape закрывает
  await page.fill('#chat-input', '/');
  await expect(page.locator('#cmd-preview')).toBeVisible();
  await page.press('#chat-input', 'Escape');
  await expect(page.locator('#cmd-preview')).toBeHidden();
});
