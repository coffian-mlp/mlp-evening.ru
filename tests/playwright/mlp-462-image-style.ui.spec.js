const { test, expect } = require('@playwright/test');

test.use({ serviceWorkers: 'block' });
test.setTimeout(90000);

test('MLP-462 AI settings show the approved image style placeholder without console errors', async ({ page }, info) => {
  const base = process.env.MLP_BASE_URL;
  if (!base || !['localhost', '127.0.0.1'].includes(new URL(base).hostname)) {
    throw new Error('MLP-462 requires an isolated local Docker application');
  }
  const errors = [];
  page.on('pageerror', error => errors.push(error.message));
  page.on('console', message => { if (message.type() === 'error') errors.push(message.text()); });
  await page.route('**/*', async route => {
    const url = new URL(route.request().url());
    if (url.origin === new URL(base).origin || !/^https?:$/.test(url.protocol)) return route.continue();
    if (route.request().resourceType() === 'image') {
      return route.fulfill({ contentType: 'image/png', body: Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jRZkAAAAASUVORK5CYII=', 'base64') });
    }
    return route.abort();
  });
  const login = await page.request.post(base + '/api.php', {
    form: { action: 'login', username: process.env.MLP_TEST_LOGIN || 'admin', password: process.env.MLP_TEST_PASSWORD || 'password' },
  });
  expect((await login.json()).success, 'synthetic administrator login').toBe(true);
  const response = await page.goto(base + '/dashboard/#tab-bot', { waitUntil: 'domcontentloaded' });
  expect(response.status()).toBe(200);
  const field = page.locator('textarea[name="ai_image_style_prompt"]');
  await field.scrollIntoViewIfNeeded();
  await expect(field).toBeVisible();
  await expect(field).toHaveValue('');
  await expect(field).toHaveAttribute('placeholder', '{technique}. Hand-made amateur artwork… Subject:');
  await page.screenshot({ path: info.outputPath('image-style-settings.png') });
  expect(errors, 'browser console and uncaught errors').toEqual([]);
});
