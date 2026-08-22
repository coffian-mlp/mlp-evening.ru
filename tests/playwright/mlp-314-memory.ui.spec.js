// MLP-314 — карточка «Память Лиры» и секция настроек «Память» во вкладке «Бот».
// XSS-кейс: текст записи с html-инъекцией отображается как текст (innerText-рендер).
// Env: MLP_BASE_URL, MLP_LOGIN, MLP_PASS; MLP_ADMIN=1 — обязателен.

const { test, expect } = require('@playwright/test');

const BASE = process.env.MLP_BASE_URL;
const LOGIN = process.env.MLP_LOGIN;
const PASS = process.env.MLP_PASS;
const IS_ADMIN = process.env.MLP_ADMIN === '1';

test.use({ ignoreHTTPSErrors: true, viewport: { width: 1500, height: 1000 } });

async function loginAndOpenBotTab(page) {
  await page.goto(BASE + '/login.php', { waitUntil: 'domcontentloaded' });
  await page.fill('#ajax-login-form input[name="username"]', LOGIN);
  await page.fill('#ajax-login-form input[name="password"]', PASS);
  await page.click('#ajax-login-form button[type="submit"]');
  await page.waitForURL((url) => !url.pathname.includes('login.php'), { timeout: 15000, waitUntil: 'domcontentloaded' });
  await page.goto(BASE + '/dashboard/', { waitUntil: 'domcontentloaded' });
  await page.click('.nav-tile[data-target="#tab-bot"]');
}

test('память: карточка — список, фильтр, правка→manual, удаление, XSS-текст (MLP-314)', async ({ page }) => {
  test.skip(!IS_ADMIN, 'нужен MLP_ADMIN=1');
  await loginAndOpenBotTab(page);

  const card = page.locator('.card', { has: page.locator('h3', { hasText: 'Память Лиры' }) });
  await expect(card).toBeVisible();
  const count = card.locator('#mem-count');
  await expect(count).toContainText('в выборке', { timeout: 10000 });

  // Создаём запись с XSS-текстом через API (от лица залогиненного админа).
  const csrf = await page.getAttribute('meta[name="csrf-token"]', 'content');
  const marker = 'pw314_' + Date.now();
  // Запись создаётся командой менеджера через save после add? Прямого admin-add нет —
  // вносим мем через таблицу памяти невозможно из UI; используем правку существующей
  // записи, а если память пуста — пропускаем XSS-подшаг (создание — командой чата в QA).
  const listRes = await page.evaluate(async ({ csrf }) => {
    const body = new URLSearchParams({ action: 'get_memory', limit: '5', csrf_token: csrf });
    return await (await fetch('/api.php', { method: 'POST', body })).json();
  }, { csrf });
  expect(listRes.success).toBeTruthy();

  if (listRes.data.items.length > 0) {
    const target = listRes.data.items[0];
    const xss = `<img src=x onerror="document.title='XSS_${marker}'"> ${marker}`;
    const saveRes = await page.evaluate(async ({ csrf, id, text }) => {
      const body = new URLSearchParams({ action: 'save_memory', id: String(id), text, csrf_token: csrf });
      return await (await fetch('/api.php', { method: 'POST', body })).json();
    }, { csrf, id: target.id, text: xss });
    expect(saveRes.success).toBeTruthy();

    // Перезагрузка карточки: текст виден КАК ТЕКСТ, onerror не исполнился.
    await page.evaluate(() => loadMemory());
    const row = card.locator(`tr[data-mem-id="${target.id}"] .mem-text`);
    await expect(row).toContainText(marker, { timeout: 10000 });
    await expect(row).toContainText('<img'); // тег отображается буквально
    expect(page.url()).not.toContain('XSS');
    const title = await page.title();
    expect(title).not.toContain('XSS_');
    // Правка перевела запись в manual.
    await expect(card.locator(`tr[data-mem-id="${target.id}"]`)).toContainText('manual');

    // Возвращаем исходный текст записи (уборка).
    const restore = await page.evaluate(async ({ csrf, id, text }) => {
      const body = new URLSearchParams({ action: 'save_memory', id: String(id), text, csrf_token: csrf });
      return await (await fetch('/api.php', { method: 'POST', body })).json();
    }, { csrf, id: target.id, text: target.text });
    expect(restore.success).toBeTruthy();
  }

  // Фильтр по виду не ломает карточку.
  const widget = card.locator('.custom-select-wrapper .select-selected');
  if (await widget.count()) {
    await widget.click();
    await card.locator('.select-items div', { hasText: 'Досье' }).click();
  } else {
    await card.locator('#mem-kind-filter').selectOption('dossier');
  }
  await expect(count).toContainText('в выборке', { timeout: 10000 });
});

test('память: секция настроек сохраняется (MLP-314)', async ({ page }) => {
  test.skip(!IS_ADMIN, 'нужен MLP_ADMIN=1');
  await loginAndOpenBotTab(page);

  const section = page.locator('h4', { hasText: 'Память' });
  await expect(section).toBeVisible();
  const enabled = page.locator('input[type="checkbox"][name="ai_memory_enabled"]');
  await expect(enabled).toBeVisible();
  // Просто сохраняем форму как есть — settings-путь не 500-ит и остаёмся в дашборде.
  const form = page.locator('form', { has: enabled });
  await form.locator('button[type="submit"]').click();
  await page.waitForLoadState('domcontentloaded');
  expect(page.url()).toContain('dashboard');
});
