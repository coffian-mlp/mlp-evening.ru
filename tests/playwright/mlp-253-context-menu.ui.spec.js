// MLP-253 (v4.8.1): контекстное меню чата.
// Баг: на index.php меню (ПКМ / кнопка ⚡) не открывалось (Firefox как минимум) —
// absolute-меню с page-координатами внутри positioned/overflow-предков; там, где
// открывалось, высокое меню уезжало за нижнюю границу экрана.
// Фикс: меню в body, position:fixed, clamp во вьюпорт. Закреп — кнопкой 📌 на
// сообщении (вынесен из меню).
//
// Env: MLP_BASE_URL, MLP_LOGIN, MLP_PASS (тестовый юзер Claude).
const { test, expect } = require('@playwright/test');

const BASE = process.env.MLP_BASE_URL || 'https://mlp-evening.ru';
const LOGIN = process.env.MLP_LOGIN || 'Claude';
const PASS = process.env.MLP_PASS || '';

test.describe('MLP-253: контекстное меню чата', () => {
  test.skip(!PASS, 'MLP_PASS не задан');

  let context, page;

  test.beforeEach(async ({ browser }) => {
    context = await browser.newContext({ baseURL: BASE, viewport: { width: 1280, height: 800 } });
    // Логин через request — cookie-jar общий с браузерным контекстом.
    const res = await (await context.request.post('/api.php', {
      form: { action: 'login', username: LOGIN, password: PASS },
    })).json();
    expect(res.success, 'логин тестовым юзером').toBeTruthy();
    page = await context.newPage();
  });

  test.afterEach(async () => { await context.close(); });

  for (const path of ['/', '/chat_popup.php']) {
    test(`меню по ПКМ открывается во вьюпорте: ${path}`, async () => {
      await page.goto(path);
      const messages = page.locator('.chat-message');
      await expect(messages.last()).toBeVisible({ timeout: 15000 });

      // ПКМ по НИЖНЕМУ сообщению — раньше меню уезжало за нижнюю границу.
      await messages.last().click({ button: 'right' });

      const menu = page.locator('#chat-context-menu');
      await expect(menu, 'меню видимо').toBeVisible();

      const box = await menu.boundingBox();
      const vp = page.viewportSize();
      expect(box, 'у меню есть геометрия').toBeTruthy();
      expect(box.x, 'левый край в экране').toBeGreaterThanOrEqual(0);
      expect(box.y, 'верхний край в экране').toBeGreaterThanOrEqual(0);
      expect(box.x + box.width, 'правый край в экране').toBeLessThanOrEqual(vp.width + 1);
      expect(box.y + box.height, 'нижний край в экране (раньше уезжал)').toBeLessThanOrEqual(vp.height + 1);

      // Пункты закрепа из меню вынесены (MLP-253).
      await expect(menu.locator('[data-action="pin"], [data-action="unpin"]')).toHaveCount(0);

      // Клик мимо меню закрывает его.
      await page.mouse.click(10, 10);
      await expect(menu).toBeHidden();
    });
  }

  test('меню в body, position fixed (страховка от клипа предками)', async () => {
    await page.goto('/');
    await expect(page.locator('.chat-message').last()).toBeVisible({ timeout: 15000 });
    const info = await page.evaluate(() => {
      const m = document.getElementById('chat-context-menu');
      return { parent: m.parentElement.tagName, position: getComputedStyle(m).position };
    });
    expect(info.parent).toBe('BODY');
    expect(info.position).toBe('fixed');
  });
});
