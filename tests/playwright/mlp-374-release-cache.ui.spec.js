const { test, expect } = require('@playwright/test');
const BASE = process.env.MLP_BASE_URL;
test.use({launchOptions:{args:['--no-proxy-server'],firefoxUserPrefs:{'network.proxy.type':0}}});
test('v4.16.0-mlp434 service worker activates, retires the previous cache and serves cached assets offline', async ({ page, context }) => {
    test.skip(!BASE, 'MLP_BASE_URL required');
    await page.goto(BASE + '/manifest.json');
    await page.evaluate(async () => { await caches.open('mlp-evening-v4.16.0-mlp433'); });
    await page.goto(BASE + '/episodes.php');
    await page.evaluate(async () => { await navigator.serviceWorker.ready; });
    await expect.poll(() => page.evaluate(() => caches.keys())).toContain('mlp-evening-v4.16.0-mlp434');
    await expect.poll(() => page.evaluate(() => caches.keys())).not.toContain('mlp-evening-v4.16.0-mlp433');
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
        const reactions = await page.evaluate(async () => (await fetch('/src/Components/StreamReactions/templates/default/script.js')).text());
        expect(reactions).toContain('data-stream-reactions');
        const reactionStyles = await page.evaluate(async () => (await fetch('/src/Components/StreamReactions/templates/default/style.css')).text());
        expect(reactionStyles).toContain('.stream-reaction-panel');
        const themes = await page.evaluate(async () => {
            const results = [];
            for (const url of ['/assets/css/themes.css', '/assets/js/theme.js', '/assets/img/themes/luna-background.webp', '/assets/img/themes/celestia-background.webp', '/assets/img/themes/luna-logo.webp', '/assets/img/themes/celestia-logo.webp']) {
                const response = await fetch(url);
                results.push({url, status:response.status, length:(await response.arrayBuffer()).byteLength});
            }
            return results;
        });
        for (const asset of themes) { expect(asset.status, asset.url).toBe(200); expect(asset.length, asset.url).toBeGreaterThan(100); }
    } finally { await context.setOffline(false); }
});
