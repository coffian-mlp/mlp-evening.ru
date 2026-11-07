const { test, expect } = require('@playwright/test');
const { execFileSync } = require('child_process');
const path = require('path');
const root = path.resolve(__dirname, '../..');
const workspace = path.join(root, 'docs/tests/MLP-456/phase2/pw-workspace');
const override = path.join(root, 'docs/tests/MLP-456/phase2/pw.override.yml');
const base = process.env.MLP_BASE_URL;
function fixture(mode) {
  if (!base || !['localhost', '127.0.0.1'].includes(new URL(base).hostname)) throw new Error('Explicit localhost required');
  return JSON.parse(execFileSync('docker', ['compose', '-p', 'mlp456pw', '-f', path.join(workspace, 'docker-compose.yml'), '-f', override,
    'exec', '-T', '-e', 'MLP456_PW_FIXTURE=1', 'php', 'php', 'tests/playwright/mlp-456-memory-fixture.php', mode], { stdio: 'pipe' }).toString());
}
test.use({ serviceWorkers: 'block', viewport: { width: 1440, height: 1000 } });
test('MLP-456 настройки показывают и сохраняют действующие бюджеты памяти', async ({ page }) => {
  const state = fixture('setup');
  try {
    fixture('oversized-limits');
    await page.route('https://**', route => route.abort());
    const login = await (await page.request.post(base + '/api.php', { form: { action: 'login', username: state.admin_login, password: state.password } })).json();
    expect(login.success).toBe(true);
    await page.goto(base + '/dashboard/', { waitUntil: 'domcontentloaded' });
    await page.locator('.nav-tile[data-target="#tab-bot"]').click();
    for (const [key, limit] of [['block', 8000], ['user', 2000], ['meme', 2000]]) {
      const field = page.locator(`input[name="ai_memory_${key}_limit"]`);
      await expect(field).toHaveValue(String(limit));
      await expect(field).toHaveAttribute('max', String(limit));
    }
    const csrf = await page.locator('meta[name="csrf-token"]').getAttribute('content');
    const saved = await (await page.request.post(base + '/api.php', { form: { action: 'update_settings', csrf_token: csrf,
      ai_memory_block_limit: '99999', ai_memory_user_limit: '99999', ai_memory_meme_limit: '99999' } })).json();
    expect(saved.success).toBe(true);
    await page.reload({ waitUntil: 'domcontentloaded' });
    await page.locator('.nav-tile[data-target="#tab-bot"]').click();
    await expect(page.locator('input[name="ai_memory_block_limit"]')).toHaveValue('8000');
    await expect(page.locator('input[name="ai_memory_user_limit"]')).toHaveValue('2000');
    await expect(page.locator('input[name="ai_memory_meme_limit"]')).toHaveValue('2000');
  } finally { fixture('cleanup'); }
});
