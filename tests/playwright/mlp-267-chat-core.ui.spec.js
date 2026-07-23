// MLP-267 (AR6-3) — единое ядро чата: оба шаблона (embedded на главной,
// popup в /chat_popup.php) работают на общих chat-core.js/chat-core.css.
// Проверяем в реальном браузере: рендер, ghost-инпут в ОБОИХ (в popup он
// впервые — эталон переехал из embedded), отправка/удаление на главной,
// десктопный ввод в попапе (мобильный FAB погашен).
//
// Env: MLP_BASE_URL, MLP_LOGIN, MLP_PASS. Уборка: сообщение удаляется в тесте.

const { test, expect } = require('@playwright/test');

const BASE = process.env.MLP_BASE_URL;
const LOGIN = process.env.MLP_LOGIN;
const PASS = process.env.MLP_PASS;

async function loginInContext(page) {
  const res = await page.request.post(BASE + '/api.php', {
    form: { action: 'login', username: LOGIN, password: PASS },
  });
  const json = await res.json();
  expect(json.success, `login: ${JSON.stringify(json)}`).toBeTruthy();
}

test('embedded: чат на главной живёт на ядре — рендер, отправка, удаление (MLP-267)', async ({ page }) => {
  test.skip(!BASE, 'MLP_BASE_URL must be set');
  await loginInContext(page);
  await page.goto(BASE + '/', { waitUntil: 'domcontentloaded' });

  // Ядро подключено, дублей старых шаблонных скриптов нет.
  const scripts = await page.$$eval('script[src]', els => els.map(e => e.getAttribute('src')));
  expect(scripts.some(s => s.includes('/Chat/assets/chat-core.js')), 'chat-core.js подключён').toBeTruthy();
  expect(scripts.some(s => s.includes('templates/embedded/script.js')), 'старый шаблонный JS не подключён').toBeFalsy();

  await expect(page.locator('#chat.chat-container')).toBeVisible();
  await expect(page.locator('.chat-input-wrapper #chat-input')).toBeVisible();

  // Ghost-кнопка: круглая, абсолютная (стиль из ядра).
  const btnPos = await page.locator('#chat-form button[type="submit"]').evaluate(el => getComputedStyle(el).position);
  expect(btnPos, 'ghost-кнопка позиционируется absolute').toBe('absolute');

  // Живая отправка через UI.
  const marker = `pw267-ядро-${Date.now()}`;
  await page.fill('#chat-input', marker);
  await page.locator('#chat-form button[type="submit"]').click();
  await expect(page.locator('.chat-messages').getByText(marker)).toBeVisible({ timeout: 10000 });

  // Уборка: удаляем своё сообщение через API (id из DOM).
  const msgEl = page.locator('.chat-message', { hasText: marker }).last();
  const msgId = await msgEl.getAttribute('data-id');
  if (msgId) {
    const html = await (await page.request.get(BASE + '/')).text();
    const csrf = html.match(/name="csrf-token"\s+content="([^"]+)"/)?.[1];
    const del = await (await page.request.post(BASE + '/api.php', {
      form: { action: 'delete_message', message_id: msgId, csrf_token: csrf },
      headers: { 'X-CSRF-Token': csrf },
    })).json();
    expect(del.success, 'уборка тестового сообщения').toBeTruthy();
  }
});

test('popup: /chat_popup.php на ядре — десктопный ввод, ghost-инпут, история (MLP-267)', async ({ page }) => {
  test.skip(!BASE, 'MLP_BASE_URL must be set');
  await loginInContext(page);
  await page.goto(BASE + '/chat_popup.php', { waitUntil: 'domcontentloaded' });

  const scripts = await page.$$eval('script[src]', els => els.map(e => e.getAttribute('src')));
  expect(scripts.some(s => s.includes('/Chat/assets/chat-core.js')), 'ядро в попапе').toBeTruthy();
  expect(scripts.some(s => s.includes('templates/popup/script.js')), 'старый popup JS не подключён').toBeFalsy();

  await expect(page.locator('#chat.chat-container')).toBeVisible();

  // Новое: ghost-инпут добрался и до попапа (wrapper в разметке).
  await expect(page.locator('.chat-input-wrapper #chat-input')).toBeVisible();

  // Принудительный десктоп: поле ввода видно, мобильный FAB скрыт (overrides живы).
  await expect(page.locator('.chat-input-area')).toBeVisible();
  const fab = page.locator('.chat-mobile-fab');
  if (await fab.count()) await expect(fab).toBeHidden();

  // История сообщений загрузилась (D1-фикс: раньше в SSE-режиме попап открывался пустым).
  await expect(page.locator('.chat-message').first()).toBeVisible({ timeout: 10000 });
});
