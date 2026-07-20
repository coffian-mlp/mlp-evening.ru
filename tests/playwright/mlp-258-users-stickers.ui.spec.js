// MLP-258 — карточка пользователя (шире + все поля), пагинация стикеров в
// дашборде, превью стикеров с lazy в пикере чата и админ-списке.
//
// Env: MLP_BASE_URL, MLP_LOGIN, MLP_PASS, MLP_ADMIN=1 (админ-сценарии).

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

test('пикер чата: превью с lazy вместо полноразмеров (MLP-258)', async ({ page }) => {
  // Пикер доступен и гостю не нужен — логинимся обычным юзером/админом
  await login(page);
  await page.goto(BASE + '/', { waitUntil: 'domcontentloaded' });

  await page.click('#sticker-btn');
  await expect(page.locator('#sticker-picker')).toBeVisible();

  const imgs = page.locator('#sticker-picker .picker-sticker');
  await expect(imgs.first()).toBeAttached();
  const n = await imgs.count();
  expect(n, 'в активном паке есть стикеры').toBeGreaterThan(0);

  // Все ячейки — lazy
  const lazyCount = await page.$$eval('#sticker-picker .picker-sticker', (els) =>
    els.filter((e) => e.getAttribute('loading') === 'lazy').length);
  expect(lazyCount, 'все картинки пикера с loading=lazy').toBe(n);

  // После бэкфилла большинство ячеек — превью из thumbs/ (допускаем исключения:
  // мелкие файлы и анимированные gif остаются оригиналами)
  const thumbCount = await page.$$eval('#sticker-picker .picker-sticker', (els) =>
    els.filter((e) => (e.getAttribute('src') || '').includes('/thumbs/')).length);
  expect(thumbCount, 'есть ячейки на превью (бэкфилл сработал)').toBeGreaterThan(0);
});

test('админ: список стикеров постраничный, превью с lazy (MLP-258)', async ({ page }) => {
  test.skip(!IS_ADMIN, 'нужен MLP_ADMIN=1');
  await login(page);
  await page.goto(BASE + '/dashboard/#tab-stickers', { waitUntil: 'domcontentloaded' });

  const rows = page.locator('#stickers-table tbody tr');
  // Ждём именно данные (строку «Загрузка...» не считаем)
  await expect(page.locator('#stickers-table tbody img').first()).toBeAttached({ timeout: 15000 });
  const n = await rows.count();
  expect(n, 'на странице не больше 50 строк').toBeLessThanOrEqual(50);

  const lazyCount = await page.$$eval('#stickers-table tbody img', (els) =>
    els.filter((e) => e.getAttribute('loading') === 'lazy').length);
  expect(lazyCount, 'картинки списка с lazy').toBeGreaterThan(0);

  // Если стикеров больше страницы — пагинация листает
  const pagination = page.locator('#stickers-pagination');
  if (await pagination.locator('button').count() > 0) {
    const firstCode = await rows.first().locator('td').nth(1).innerText();
    await pagination.locator('button', { hasText: '→' }).click();
    await expect(rows.first()).toBeVisible({ timeout: 15000 });
    const newFirst = await rows.first().locator('td').nth(1).innerText();
    expect(newFirst, 'страница 2 — другие стикеры').not.toBe(firstCode);
  }
});

test('админ: карточка пользователя — широкая, все поля, статусы (MLP-258)', async ({ page }) => {
  test.skip(!IS_ADMIN, 'нужен MLP_ADMIN=1');
  await login(page);
  await page.goto(BASE + '/dashboard/#tab-users', { waitUntil: 'domcontentloaded' });

  // Открываем карточку тестового юзера (Claude) кликом по строке/кнопке редактирования
  const row = page.locator('#users-table tbody tr', { hasText: 'Claude' }).first();
  await expect(row).toBeVisible({ timeout: 15000 });
  await row.locator('button', { hasText: '✏️' }).first().click();

  const modal = page.locator('#user-modal .modal-content');
  await expect(modal).toBeVisible();

  // Ширина ≥ 700px (MLP-258: 720px)
  const width = await modal.evaluate((el) => el.getBoundingClientRect().width);
  expect(width, 'модалка стала широкой').toBeGreaterThanOrEqual(700);

  // Новые поля на месте
  await expect(page.locator('#user_email')).toBeVisible();
  await expect(page.locator('#user_font_scale')).toBeVisible();
  await expect(page.locator('#user-meta')).toBeVisible();
  await expect(page.locator('#user-meta-badges .badge')).toHaveCount(1); // Активен (Claude не забанен)
  await expect(page.locator('#user-socials-list')).toBeVisible();

  // Сохранение email работает (и откатываем)
  const marker = 'pw258-' + Date.now() + '@test.local';
  await page.fill('#user_email', marker);
  await page.click('#user-form button[type="submit"]');
  await expect(page.locator('#user-modal')).toBeHidden({ timeout: 10000 });

  // Переоткрываем — email сохранился
  await page.goto(BASE + '/dashboard/#tab-users', { waitUntil: 'domcontentloaded' });
  const row2 = page.locator('#users-table tbody tr', { hasText: 'Claude' }).first();
  await expect(row2).toBeVisible({ timeout: 15000 });
  await row2.locator('button', { hasText: '✏️' }).first().click();
  await expect(page.locator('#user_email')).toHaveValue(marker);

  // Откат: очищаем email
  await page.fill('#user_email', '');
  await page.click('#user-form button[type="submit"]');
  await expect(page.locator('#user-modal')).toBeHidden({ timeout: 10000 });
});
