const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');

const BASE = process.env.MLP_BASE_URL;
let fixture;
test.use({ serviceWorkers: 'block' });
test.setTimeout(120000);
test.beforeAll(() => {
  if (!BASE || !['localhost', '127.0.0.1'].includes(new URL(BASE).hostname)) {
    throw new Error('MLP-464 requires an isolated local Docker application');
  }
  fixture = JSON.parse(fs.readFileSync(process.env.MLP_PIN_FIXTURE ||
    path.resolve(__dirname, '../../docs/private/mlp464-local.json'), 'utf8'));
});

async function login(request, role) {
  const user = fixture[role];
  expect((await (await request.post(BASE + '/api.php', {
    form: { action: 'login', username: user.login, password: user.password },
  })).json()).success).toBe(true);
  const html = await (await request.get(BASE + '/chat_popup.php')).text();
  return html.match(/name="csrf-token"\s+content="([^"]+)"/)[1];
}
async function post(request, action, csrf, fields = {}) {
  const result = await (await request.post(BASE + '/api.php', {
    form: { action, csrf_token: csrf, ...fields },
  })).json();
  expect(result.success, action).toBe(true);
  return result.data;
}
async function pin(request, csrf, index = 0) {
  return (await post(request, 'pin_message', csrf, { message_id: fixture.messages[index] })).pinned;
}

// Delivers publications to the actual Centrifugo subscriber in chat-core.js.
// Transport itself is controlled: no external services or production data.
async function prepare(page, storageFailure = '') {
  const errors = [];
  page.on('pageerror', error => errors.push(error.message));
  page.on('console', message => { if (message.type() === 'error') errors.push(message.text()); });
  await page.route('**/*', route => {
    const url = new URL(route.request().url());
    if (url.origin === new URL(BASE).origin || !/^https?:$/.test(url.protocol)) return route.continue();
    if (route.request().resourceType() === 'image') {
      return route.fulfill({ contentType: 'image/png', body: Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jRZkAAAAASUVORK5CYII=', 'base64') });
    }
    return route.fulfill({ status: 200, contentType: 'text/javascript', body: '' });
  });
  await page.addInitScript(failure => {
    window.__pinPublication = null;
    class ControlledCentrifuge {
      on() {}
      newSubscription(channel) {
        if (channel !== 'public:chat') throw new Error('Unexpected channel');
        return { on(event, callback) { if (event === 'publication') window.__pinPublication = callback; }, subscribe() {} };
      }
      connect() {}
      disconnect() {}
    }
    Object.defineProperty(window, 'Centrifuge', { get: () => ControlledCentrifuge, set() {} });
    let config;
    Object.defineProperty(window, 'chatConfig', {
      get: () => config,
      set(value) { config = { ...value, driver: 'centrifugo', centrifugo: { url: 'ws://localhost', token: 'synthetic' } }; },
    });
    if (failure === 'access') Object.defineProperty(window, 'localStorage', { get() { throw new Error('Storage unavailable'); } });
    if (failure === 'get') Storage.prototype.getItem = () => { throw new Error('Storage read unavailable'); };
    if (failure === 'set') Storage.prototype.setItem = () => { throw new Error('Quota exceeded'); };
    if (failure === 'remove') Storage.prototype.removeItem = () => { throw new Error('Storage write unavailable'); };
  }, storageFailure);
  return errors;
}
async function open(page, route) {
  expect((await page.goto(BASE + route, { waitUntil: 'domcontentloaded' })).status()).toBe(200);
  await expect.poll(() => page.evaluate(() => typeof window.__pinPublication)).toBe('function');
  await expect.poll(() => page.evaluate(() => window.privacyChoices.decided || !document.getElementById('privacy-panel').hidden)).toBe(true);
  if (await page.locator('#privacy-panel').isVisible()) {
    await page.locator('[data-privacy-necessary]').click();
    await expect(page.locator('#privacy-panel')).toBeHidden({ timeout: 15000 });
  }
}
async function publish(page, pinned) {
  await page.evaluate(pinned => window.__pinPublication({ data: { type: 'pin_update', pinned } }), pinned);
}
async function screenshot(page, info, route, name) {
  const dir = process.env.MLP_PIN_SCREENSHOTS || path.resolve(__dirname, '../../docs/tests/MLP-464');
  fs.mkdirSync(dir, { recursive: true });
  await page.locator('#chat').screenshot({ path: path.join(dir, `${info.project.name}-${route === '/' ? 'embedded' : 'popup'}-${name}.png`) });
}

for (const route of ['/', '/chat_popup.php']) {
  for (const role of ['guest', 'user']) {
    test(`MLP-464 ${route} ${role}: hide, persist, restore, keyboard and mobile`, async ({ page, request }, info) => {
      const csrf = await login(request, 'moderator');
      await pin(request, csrf);
      const errors = await prepare(page);
      if (role === 'user') await login(page.request, 'user');
      await open(page, route);
      const banner = page.locator('#chat-pinned-banner');
      const restore = page.getByRole('button', { name: 'Показать закреп', exact: true });
      const hide = page.getByRole('button', { name: 'Скрыть закреп (только у вас)', exact: true });
      await expect(banner).toBeVisible();
      await expect(hide).toHaveAttribute('title', 'Скрыть закреп (только у вас)');
      expect(await hide.evaluate(el => getComputedStyle(el).color === getComputedStyle(document.documentElement).color)).toBe(true);
      await expect(banner.locator('.pinned-unpin')).toHaveCount(0);
      // Existing assetVersion() must version both changed assets on the rendered page.
      expect(await page.locator('script[src*="/chat-core.js?v="]').count()).toBe(1);
      expect(await page.locator('link[href*="/chat-core.css?v="]').count()).toBe(1);
      if (role === 'guest') await screenshot(page, info, route, 'banner');
      await hide.focus();
      await hide.press('Enter');
      await expect(banner).toBeHidden();
      await expect(restore).toBeVisible();
      expect(await page.evaluate(id => localStorage.getItem('mlp.chat.hiddenPin.' + id), fixture.messages[0])).toBe('1');
      if (role === 'guest') await screenshot(page, info, route, 'hidden');
      await page.reload({ waitUntil: 'domcontentloaded' });
      await expect(restore).toBeVisible();
      await expect(banner).toBeHidden();
      await restore.focus();
      await restore.press('Space');
      await expect(banner).toBeVisible();
      await expect(restore).toBeHidden();
      expect(await page.evaluate(id => localStorage.getItem('mlp.chat.hiddenPin.' + id), fixture.messages[0])).toBeNull();
      await page.reload({ waitUntil: 'domcontentloaded' });
      await expect(banner).toBeVisible();
      await page.setViewportSize({ width: 375, height: 812 });
      await page.locator('#chat').scrollIntoViewIfNeeded();
      await hide.click();
      await expect(restore).toBeVisible();
      const geometry = await restore.evaluate(el => {
        const r = el.getBoundingClientRect();
        return { left: r.left, right: r.right, width: r.width, page: document.documentElement.scrollWidth, viewport: innerWidth };
      });
      expect(geometry.width).toBeLessThanOrEqual(44);
      expect(geometry.left).toBeGreaterThanOrEqual(0);
      expect(geometry.right).toBeLessThanOrEqual(375);
      expect(geometry.page).toBeLessThanOrEqual(geometry.viewport);
      if (role === 'guest') await screenshot(page, info, route, 'mobile-hidden');
      await restore.click();
      await expect(banner).toBeVisible();
      expect(errors).toEqual([]);
    });
  }

  test(`MLP-464 ${route}: moderator hide is local; publication, edit, replacement and unpin`, async ({ page, request, browser }) => {
    const csrf = await login(request, 'moderator');
    const first = await pin(request, csrf);
    const errors = await prepare(page);
    await login(page.request, 'moderator');
    await open(page, route);
    const banner = page.locator('#chat-pinned-banner');
    const hide = page.locator('.pinned-hide');
    const restore = page.locator('#chat-pinned-restore');
    await expect(banner.locator('.pinned-unpin')).toBeVisible();
    await expect(hide).toContainText('Скрыть');
    await expect(banner.locator('.pinned-unpin')).toHaveAccessibleName('Открепить');
    await hide.click();
    await expect(restore).toBeVisible();
    expect((await post(request, 'get_pinned', csrf)).pinned.id).toBe(first.id);
    const other = await browser.newContext({ serviceWorkers: 'block' });
    try {
      const secondPage = await other.newPage();
      const otherErrors = await prepare(secondPage);
      await open(secondPage, route);
      await expect(secondPage.locator('#chat-pinned-banner')).toBeVisible();
      await publish(page, { ...first, message: 'Edited synthetic pinned message' });
      await expect(banner).toBeHidden();
      await expect(restore).toBeVisible();
      await restore.click();
      await expect(banner).toContainText('Edited synthetic pinned message');
      await hide.click();
      const second = await pin(request, csrf, 1);
      await publish(page, second);
      await expect(banner).toBeVisible();
      await expect(restore).toBeHidden();
      await expect(banner).toContainText('Second synthetic pinned message');
      await hide.click();
      await page.reload({ waitUntil: 'domcontentloaded' });
      await expect(restore).toBeVisible();
      await restore.click();
      const unpinResponse = page.waitForResponse(response => response.url().endsWith('/api.php') &&
        new URLSearchParams(response.request().postData() || '').get('action') === 'unpin_message');
      await page.locator('.pinned-unpin').click();
      expect((await (await unpinResponse).json()).success).toBe(true);
      expect((await post(request, 'get_pinned', csrf)).pinned).toBeNull();
      await publish(page, null);
      await expect(banner).toBeHidden();
      await expect(restore).toBeHidden();
      // Unpin publication while the banner is hidden must remove the icon too.
      await pin(request, csrf);
      await publish(page, first);
      await expect(restore).toBeVisible();
      await post(request, 'unpin_message', csrf);
      await publish(page, null);
      await expect(banner).toBeHidden();
      await expect(restore).toBeHidden();
      expect(otherErrors).toEqual([]);
    } finally {
      await other.close();
    }
    expect(errors).toEqual([]);
  });

  test(`MLP-464 ${route}: a different pin on reload is visible`, async ({ page, request }) => {
    const csrf = await login(request, 'moderator');
    await pin(request, csrf);
    const errors = await prepare(page);
    await open(page, route);
    await page.locator('.pinned-hide').click();
    await pin(request, csrf, 1);
    await page.reload({ waitUntil: 'domcontentloaded' });
    await expect(page.locator('#chat-pinned-banner')).toBeVisible();
    await expect(page.locator('#chat-pinned-banner')).toContainText('Second synthetic pinned message');
    expect(errors).toEqual([]);
  });

  for (const failure of ['access', 'get', 'set', 'remove']) {
    test(`MLP-464 ${route}: storage ${failure} failure falls back to memory`, async ({ page, request }) => {
      const csrf = await login(request, 'moderator');
      const first = await pin(request, csrf);
      const errors = await prepare(page, failure);
      await open(page, route);
      const banner = page.locator('#chat-pinned-banner');
      const restore = page.locator('#chat-pinned-restore');
      await page.locator('.pinned-hide').click();
      await publish(page, { ...first, message: 'Changed while storage is unavailable' });
      await expect(banner).toBeHidden();
      await expect(restore).toBeVisible();
      await restore.click();
      await expect(banner).toBeVisible();
      await publish(page, first);
      await expect(banner).toBeVisible();
      await expect(restore).toBeHidden();
      if (failure !== 'remove') {
        await page.locator('.pinned-hide').click();
        await page.reload({ waitUntil: 'domcontentloaded' });
        await expect(banner).toBeVisible();
      }
      expect(errors).toEqual([]);
    });
  }
}
