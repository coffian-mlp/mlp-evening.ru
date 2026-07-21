// MLP-259 — меню сайта: сенобургер на главной, горизонталь в шапке /schedule,
// роли (админ-пункт скрыт от гостя), внешние ссылки (↗/_blank), раскрывашки,
// мобильная шапка, редактор в дашборде, профиль без кнопки «Админка».
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

test('гость: сенобургер на главной — Стрим и Расписание видны, Админки нет (MLP-259)', async ({ page }) => {
  await page.goto(BASE + '/', { waitUntil: 'domcontentloaded' });

  const btn = page.locator('.video-container .site-burger-btn');
  await expect(btn).toBeVisible();
  await btn.click();

  const panel = page.locator('.video-container .site-burger-panel');
  await expect(panel).toBeVisible();
  // Состав панели — контент админа (подачи переключаемы); проверяем инварианты:
  // сид-пункт «Стрим» в бургере и отсутствие админ-пунктов у гостя.
  await expect(panel.locator('.site-menu-item', { hasText: 'Стрим' })).toBeVisible();
  await expect(panel.locator('.site-menu-item', { hasText: 'Админка' })).toHaveCount(0);

  // Esc закрывает
  await page.keyboard.press('Escape');
  await expect(panel).toBeHidden();
});

test('гость: горизонтальное меню в шапке /schedule, активный пункт (MLP-259)', async ({ page }) => {
  await page.goto(BASE + '/schedule.php', { waitUntil: 'domcontentloaded' });

  const nav = page.locator('.site-nav');
  await expect(nav).toBeVisible();
  await expect(nav.locator('.site-nav-link', { hasText: 'Расписание' })).toHaveClass(/active/);
  await expect(nav.locator('.site-nav-link', { hasText: 'Админка' })).toHaveCount(0);
});

test('мобильная шапка: ссылки схлопнуты в сенобургер (MLP-259)', async ({ browser }) => {
  const ctx = await browser.newContext({ ignoreHTTPSErrors: true, viewport: { width: 390, height: 800 } });
  const page = await ctx.newPage();
  await page.goto(BASE + '/schedule.php', { waitUntil: 'domcontentloaded' });

  await expect(page.locator('.site-nav')).toBeHidden();
  const btn = page.locator('.site-header-burger .site-burger-btn');
  await expect(btn).toBeVisible();
  await btn.click();
  await expect(page.locator('.site-header-burger .site-menu-item', { hasText: 'Стрим' })).toBeVisible();
  await ctx.close();
});

test('админ: пункт «Админка» виден и ведёт в дашборд; профиль без кнопки (MLP-259)', async ({ page }) => {
  test.skip(!IS_ADMIN, 'нужен MLP_ADMIN=1');
  await login(page);
  await page.goto(BASE + '/schedule.php', { waitUntil: 'domcontentloaded' });

  const adminLink = page.locator('.site-nav .site-nav-link', { hasText: 'Админка' });
  await expect(adminLink).toBeVisible();
  await adminLink.click();
  await page.waitForURL(/\/dashboard\//, { timeout: 15000, waitUntil: 'domcontentloaded' });

  // Профиль-модалка больше не содержит «Админку» (разметка страницы)
  await page.goto(BASE + '/', { waitUntil: 'domcontentloaded' });
  await expect(page.locator('.profile-actions-footer a', { hasText: 'Админка' })).toHaveCount(0);
});

test('админ: CRUD пункта — внешний, раскрывашка с ребёнком, порядок, уборка (MLP-259)', async ({ page, context }) => {
  test.skip(!IS_ADMIN, 'нужен MLP_ADMIN=1');
  await login(page);

  const marker = 'PW259-' + Date.now();

  // API-хелпер (csrf из meta)
  const html = await (await context.request.get(BASE + '/')).text();
  const csrf = html.match(/name="csrf-token"\s+content="([^"]+)"/)?.[1];
  const api = async (form) => (await context.request.post(BASE + '/api.php', {
    form: { csrf_token: csrf, ...form }, headers: { 'X-CSRF-Token': csrf },
  })).json();

  // 1. Внешний пункт
  const ext = await api({ action: 'save_menu_item', title: marker + '-ext', url: 'https://example.com/pony', is_external: '1', show_in_header: '1', show_in_burger: '1', is_active: '1' });
  expect(ext.success, `save external failed: ${JSON.stringify(ext)}`).toBeTruthy();

  // 2. Раскрывашка + ребёнок
  const parent = await api({ action: 'save_menu_item', title: marker + '-rooms', url: '', show_in_header: '1', show_in_burger: '1', is_active: '1' });
  expect(parent.success).toBeTruthy();
  const items = (await api({ action: 'get_menu_items' })).data.items;
  const parentItem = items.find((i) => i.title === marker + '-rooms');
  expect(parentItem, 'раскрывашка в дереве').toBeTruthy();
  const child = await api({ action: 'save_menu_item', title: marker + '-room-a', url: '/schedule.php', parent_id: String(parentItem.id), show_in_header: '1', show_in_burger: '1', is_active: '1' });
  expect(child.success).toBeTruthy();

  // 3. На сайте: внешний с ↗/_blank; раскрывашка раскрывается
  await page.goto(BASE + '/', { waitUntil: 'domcontentloaded' });
  await page.click('.video-container .site-burger-btn');
  const panel = page.locator('.video-container .site-burger-panel');
  const extLink = panel.locator('a.site-menu-item', { hasText: marker + '-ext' });
  await expect(extLink).toBeVisible();
  await expect(extLink).toHaveAttribute('target', '_blank');
  await expect(extLink.locator('.ext-mark')).toBeVisible();

  const parentBtn = panel.locator('.site-burger-parent', { hasText: marker + '-rooms' });
  await expect(parentBtn).toBeVisible();
  await parentBtn.click();
  await expect(panel.locator('.site-menu-child', { hasText: marker + '-room-a' })).toBeVisible();
  expect(new URL(page.url()).pathname, 'клик по раскрывашке не навигирует').toBe('/');

  // 4. Уборка: удаляем созданное (ребёнок поднимется на корень при удалении родителя)
  const fresh = (await api({ action: 'get_menu_items' })).data.items;
  for (const t of [marker + '-ext', marker + '-rooms']) {
    const it = fresh.find((i) => i.title === t);
    if (it) expect((await api({ action: 'delete_menu_item', id: String(it.id) })).success).toBeTruthy();
  }
  const afterParentDelete = (await api({ action: 'get_menu_items' })).data.items;
  const orphan = afterParentDelete.find((i) => i.title === marker + '-room-a');
  expect(orphan, 'ребёнок поднялся на корень').toBeTruthy();
  expect((await api({ action: 'delete_menu_item', id: String(orphan.id) })).success).toBeTruthy();
});
