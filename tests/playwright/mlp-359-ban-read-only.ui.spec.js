const { test, expect } = require('@playwright/test');
const { execFileSync } = require('node:child_process');
const { randomBytes } = require('node:crypto');
const { writeFileSync } = require('node:fs');
const BASE = process.env.MLP_BASE_URL || 'http://localhost:8091';
function fixture(action, login, ...extra) {
  const output = execFileSync('docker', ['compose', '-p', 'mlp359', '-f', 'docker-compose.yml', '-f', 'docs/tests/MLP-359/compose.override.yml', 'exec', '-T', 'php', 'php', 'tests/playwright/fixtures/mlp-359-ban-read-only.php', action, login, ...extra.map(String)], { encoding: 'utf8' });
  return output.trim() ? JSON.parse(output) : {};
}

test('MLP-359: banned auth, public history/live, visible sanction, antiflood and expiry', async ({ page, browser }, info) => {
  test.skip(new URL(BASE).hostname !== 'localhost', 'Local fixture suite only');
  const login = 'it_mlp359_' + randomBytes(6).toString('hex');
  const data = fixture('setup', login);
  const oldRate = fixture('rate', login, 30).old;
  const guest = await browser.newContext();
  try {
    const guestPage = await guest.newPage();
    await guestPage.goto(BASE + '/', { waitUntil: 'domcontentloaded' });
    await expect(guestPage.locator('#chat-messages')).toContainText('Fixture history ' + login);
    const guestHistory = await guestPage.request.post(BASE + '/api.php', { form: { action: 'get_messages' } });
    expect((await guestHistory.json()).success).toBeTruthy();
    await page.goto(BASE + '/login.php', { waitUntil: 'domcontentloaded' });
    await page.fill('#ajax-login-form input[name="username"]', data.login);
    await page.fill('#ajax-login-form input[name="password"]', data.password);
    await page.click('#ajax-login-form button[type="submit"]');
    await page.waitForURL(url => !url.pathname.includes('login.php'));
    await expect(page.locator('#chat-input')).toBeVisible();
    await expect(page.locator('#chat-messages')).toContainText('Fixture history ' + login);
    fixture('new', login);
    await expect(guestPage.locator('#chat-messages')).toContainText('Fixture live ' + login, { timeout: 20000 });
    await expect(page.locator('#chat-messages')).toContainText('Fixture live ' + login, { timeout: 20000 });
    const csrf = await page.locator('meta[name="csrf-token"]').getAttribute('content');
    async function apiSend(message) {
      return (await page.request.post(BASE + '/api.php', { form: { action: 'send_message', message, csrf_token: csrf } })).json();
    }
    async function uiRefusal(text) {
      const before=fixture('count',login).count;
      await page.fill('#chat-input','Denied ' + login);
      await page.click('#chat-form button[type="submit"]');
      await expect(page.locator('.chat-notification.error').last()).toContainText(text);
      expect(fixture('count',login).count).toBe(before);
    }
    await uiRefusal('MLP359 browser reason');
    await expect(page.locator('.chat-notification.error').last()).toContainText('МСК');
    const beforeDirectBan=fixture('count',login).count;
    const directBan=await apiSend('Direct denied ' + login);
    expect(fixture('count',login).count).toBe(beforeDirectBan);
    expect(directBan.success).toBeFalsy(); expect(directBan.message).toContain('Ты в бане');
    await page.screenshot({ path: info.outputPath('ban-notice.png'), fullPage: true });
    fixture('mute',login);
    await uiRefusal('MLP359 mute reason');
    const beforeDirectMute=fixture('count',login).count;
    const directMute=await apiSend('Direct mute ' + login);
    expect(fixture('count',login).count).toBe(beforeDirectMute);
    expect(directMute.success).toBeFalsy(); expect(directMute.message).toContain('Ты в муте');
    fixture('unban',login);
    expect((await apiSend('Allowed after unban ' + login)).success).toBeTruthy();
    const antiflood=await apiSend('Recent unbanned ' + login);
    expect(antiflood.success).toBeFalsy(); expect(antiflood.message).toContain('Не так быстро');
    fixture('expired',login);
    expect((await apiSend('Allowed expired ban ' + login)).success).toBeTruthy();
    await page.screenshot({ path: info.outputPath('after-expiry.png'), fullPage: true });
    writeFileSync(info.outputPath('api-evidence.json'),JSON.stringify({ directBan, directMute, antiflood },null,2));
    await info.attach('API evidence', { body: JSON.stringify({ directBan, directMute, antiflood },null,2), contentType:'application/json' });
  } finally {
    await guest.close();
    fixture('rate',login,oldRate);
    fixture('cleanup',login);
  }
});
