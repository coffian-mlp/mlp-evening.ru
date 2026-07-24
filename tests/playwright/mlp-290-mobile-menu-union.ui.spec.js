// MLP-290 — мобильный бургер = header ∪ burger: пункт «только шапка» обязан
// появляться в бургере на мобиле (прецедент: админ-пункт пропадал в PWA),
// а на десктопе главной — не дублироваться (класс menu-only-mobile).
//
// Env: MLP_BASE_URL, MLP_LOGIN, MLP_PASS; MLP_ADMIN=1 — обязателен (CRUD пункта).

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

test('header-only пункт: в мобильном бургере есть, на десктопе главной скрыт (MLP-290)', async ({ page, context, browser }) => {
  test.skip(!IS_ADMIN, 'нужен MLP_ADMIN=1');
  await login(page);

  const marker = 'PW290-' + Date.now();
  const html = await (await context.request.get(BASE + '/')).text();
  const csrf = html.match(/name="csrf-token"\s+content="([^"]+)"/)?.[1];
  const api = async (form) => (await context.request.post(BASE + '/api.php', {
    form: { csrf_token: csrf, ...form }, headers: { 'X-CSRF-Token': csrf },
  })).json();

  // Пункт «только шапка» (прецедент бага), видимость all — чтобы проверять без сессии в новых контекстах.
  const created = await api({ action: 'save_menu_item', title: marker, url: '/schedule.php', show_in_header: '1', show_in_burger: '0', is_active: '1' });
  expect(created.success, `save failed: ${JSON.stringify(created)}`).toBeTruthy();

  try {
    // 1. Десктоп, шапка /schedule: пункт в горизонтали (как настроено).
    await page.goto(BASE + '/schedule.php', { waitUntil: 'domcontentloaded' });
    await expect(page.locator('.site-nav .site-nav-link', { hasText: marker })).toBeVisible();

    // 2. МОБИЛА, шапка: горизонталь спрятана — пункт ОБЯЗАН быть в бургере (сам фикс).
    const mob = await browser.newContext({ ignoreHTTPSErrors: true, viewport: { width: 390, height: 800 } });
    const mpage = await mob.newPage();
    await mpage.goto(BASE + '/schedule.php', { waitUntil: 'domcontentloaded' });
    await expect(mpage.locator('.site-nav')).toBeHidden();
    await mpage.click('.site-header-burger .site-burger-btn');
    await expect(mpage.locator('.site-header-burger .site-menu-item', { hasText: marker })).toBeVisible();

    // 3. МОБИЛА, главная: в сенобургере пункт тоже есть.
    await mpage.goto(BASE + '/', { waitUntil: 'domcontentloaded' });
    await mpage.click('.video-container .site-burger-btn');
    await expect(mpage.locator('.video-container .site-menu-item', { hasText: marker })).toBeVisible();
    await mob.close();

    // 4. Десктоп, главная: в сенобургере НЕ дублируется (горизонталь его уже показывает) —
    //    в DOM присутствует, но скрыт классом menu-only-mobile.
    await page.goto(BASE + '/', { waitUntil: 'domcontentloaded' });
    await page.click('.video-container .site-burger-btn');
    const deskItem = page.locator('.video-container .site-burger-panel .site-menu-item', { hasText: marker });
    await expect(deskItem).toHaveCount(1);
    await expect(deskItem).toBeHidden();
    await expect(page.locator('.site-nav-stream .site-nav-link', { hasText: marker })).toBeVisible();
  } finally {
    const items = (await api({ action: 'get_menu_items' })).data.items;
    const it = items.find((i) => i.title === marker);
    if (it) expect((await api({ action: 'delete_menu_item', id: String(it.id) })).success).toBeTruthy();
  }
});
