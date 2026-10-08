const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');
const BASE = process.env.MLP_BASE_URL;
test.use({ serviceWorkers: 'block' });
test('rating spread explanation stays visible and sorting uses the existing standard deviation', async ({ page, browserName }) => {
    test.skip(!BASE, 'MLP_BASE_URL required');
    await page.goto(BASE + '/episodes.php');
    const help = page.locator('[data-catalogue-rating-help]');
    await expect(help).toBeVisible();
    await expect(help).toContainText('это не показатель спорности эпизода в фандоме');
    await expect(page.locator('.episode-catalogue-about')).not.toHaveAttribute('open', '');
    await expect(page.locator('[data-catalogue-column="sd"] button')).toHaveText('Разброс оценок σ');
    await page.locator('[data-catalogue-sort]').selectOption('sd');
    for (let direction = 0; direction < 2; direction++) {
        const values = await page.locator('.episode-catalogue tbody tr').evaluateAll(rows => rows.map(row => row.children[5].textContent.trim()).filter(text => text !== '—').map(Number));
        expect(values.length).toBeGreaterThan(1);
        expect(values.every((value, index) => !index || (direction ? value <= values[index - 1] : value >= values[index - 1]))).toBe(true);
        await page.locator('[data-catalogue-direction]').click();
    }
    for (const width of [1280, 360]) {
        await page.setViewportSize({ width, height: 900 });
        await expect(help).toBeVisible();
        expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
        if (process.env.MLP_SCREENSHOT_DIR) {
            fs.mkdirSync(process.env.MLP_SCREENSHOT_DIR, { recursive: true });
            await page.screenshot({ path: path.join(process.env.MLP_SCREENSHOT_DIR, `${browserName}-${width}.png`), animations: 'disabled' });
        }
    }
});
