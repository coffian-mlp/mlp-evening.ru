const { test, expect } = require('@playwright/test');
const BASE = process.env.MLP_BASE_URL;
test.use({launchOptions:{args:['--no-proxy-server'],firefoxUserPrefs:{'network.proxy.type':0}}});
test('v4.15.0 service worker activates, retires the previous cache and serves cached assets offline', async ({ page, context }) => {
    test.skip(!BASE, 'MLP_BASE_URL required');
    await page.goto(BASE + '/manifest.json');
    await page.evaluate(async () => { await caches.open('mlp-evening-v4.14.0'); });
    await page.goto(BASE + '/episodes.php');
    await page.evaluate(async () => { await navigator.serviceWorker.ready; });
    await expect.poll(() => page.evaluate(() => caches.keys())).toContain('mlp-evening-v4.15.0');
    await expect.poll(() => page.evaluate(() => caches.keys())).not.toContain('mlp-evening-v4.14.0');
    await page.reload();
    await expect.poll(() => page.evaluate(() => Boolean(navigator.serviceWorker.controller))).toBe(true);
    await context.setOffline(true);
    try {
        const asset = await page.evaluate(async () => {
            const response = await fetch('/assets/css/main.css');
            return { status: response.status, length: (await response.text()).length };
        });
        expect(asset.status).toBe(200);
        expect(asset.length).toBeGreaterThan(100);
        const keywords = await page.evaluate(async () => (await fetch('/src/Components/Chat/assets/keyword-alerts.js')).text());
        expect(keywords).toContain('function wordForms(word)');
    } finally { await context.setOffline(false); }
});
