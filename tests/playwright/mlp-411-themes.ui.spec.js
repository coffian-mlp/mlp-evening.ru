const {test, expect} = require('@playwright/test');
const {execFileSync} = require('child_process');
const fs = require('fs'), path = require('path');
const root = path.resolve(__dirname, '../..'), base = process.env.MLP_BASE_URL;
const state = path.join(root, 'docs/private/mlp411-local.json');
const fixture = action => execFileSync('docker', ['exec', 'mlp375-php-1', 'php', 'tests/playwright/mlp-411-themes-fixture.php', action], {cwd: root});
const shots = path.join(root, 'docs/tests/MLP-411');
test.use({serviceWorkers: 'block', colorScheme: 'dark', viewport: {width: 1672, height: 941}, launchOptions: {args: ['--no-proxy-server'], firefoxUserPrefs: {'network.proxy.type': 0}}});
test.beforeEach(async ({page}) => {
    if (!base || !['127.0.0.1', 'localhost'].includes(new URL(base).hostname)) throw Error('Isolated localhost required');
    await page.route('https://**', r => r.abort());
    await page.route('**/chat_stream.php*', r => r.abort());
    fs.mkdirSync(shots, {recursive: true});
});
async function login(page, user, role) {
    expect((await (await page.request.post(base + '/api.php', {form: {action: 'login', username: user[role + 'Login'], password: user.password}})).json()).success).toBe(true);
}
async function theme(page, name) {
    await expect(page.locator('html')).toHaveAttribute('data-theme', name);
    await expect.poll(() => page.evaluate(() => getComputedStyle(document.documentElement).backgroundImage)).toContain(name + '-background.webp');
    const logo = page.locator('.theme-logo-' + name);
    if (await logo.count()) {
        await expect(logo).toBeVisible();
        await expect.poll(() => logo.evaluate(el => el.complete && el.naturalWidth > 0)).toBe(true);
    }
}
async function choose(page, name) {
    await page.locator('[data-theme-toggle]').click();
    await page.locator('[data-theme-choice="'+name+'"]').click();
}
async function ready(page) {
    await page.evaluate(async () => {
        await document.fonts.ready;
        await Promise.all([...document.images].filter(img => img.classList.contains('logo')).map(img => img.decode().catch(() => {})));
        const bg = getComputedStyle(document.documentElement).backgroundImage.match(/url\(["']?(.*?)["']?\)/)?.[1];
        if (bg) await new Promise(resolve => {const image = new Image(); image.onload = image.onerror = resolve; image.src = bg;});
        await new Promise(resolve => setTimeout(resolve, 450));
    });
}
test('guest switches themes, persists before paint and shares with popup; mobile pages remain usable', async ({page, context}, info) => {
    await page.goto(base + '/', {waitUntil: 'domcontentloaded'});
    await theme(page, 'luna');
    await choose(page, 'celestia'); await theme(page, 'celestia');
    await page.reload({waitUntil: 'domcontentloaded'}); await theme(page, 'celestia');
    await expect(page.locator('meta[name="theme-color"]')).toHaveAttribute('content', '#f6eef8');
    const popup = await context.newPage();
    await popup.route('https://**', r => r.abort()); await popup.route('**/chat_stream.php*', r => r.abort());
    await popup.goto(base + '/chat_popup.php', {waitUntil: 'domcontentloaded'}); await theme(popup, 'celestia');
    await choose(popup, 'luna'); await theme(page, 'luna');
    await popup.close();
    for (const name of ['luna', 'celestia']) {
        if (await page.locator('html').getAttribute('data-theme') !== name) await choose(page, name);
        for (const width of [1672, 390]) {
            await page.setViewportSize({width, height: width === 390 ? 844 : 941});
            await theme(page, name);
            const bounds = await page.locator('.site-logo').evaluate(el => {const r = el.getBoundingClientRect();return {left:r.left, right:r.right, bottom:r.bottom};});
            expect(bounds.left).toBeGreaterThanOrEqual(0); expect(bounds.right).toBeLessThanOrEqual(width);
            await expect(page.locator('[data-theme-toggle]')).toBeInViewport();
            expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
            await ready(page);
            await page.screenshot({path: path.join(shots, `${info.project.name}-${name}-${width}.png`)});
        }
    }
    for (const url of ['/schedule.php', '/episodes.php', '/login.php']) {
        await page.goto(base + url, {waitUntil: 'domcontentloaded'}); await theme(page, 'celestia');
        await expect(page.locator('[data-theme-toggle]')).toBeVisible();
        expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
        await ready(page);
        await page.screenshot({path:path.join(shots, info.project.name + '-' + url.slice(1) + '.png')});
    }
});
test('theme still switches when browser storage is unavailable', async ({page}) => {
    await page.addInitScript(() => {Object.defineProperty(window, 'localStorage', {get(){throw new DOMException('Disabled', 'SecurityError');}});});
    await page.goto(base + '/', {waitUntil:'domcontentloaded'}); await theme(page, 'luna');
    await choose(page, 'celestia'); await theme(page, 'celestia');
});
test('system preference drives default theme and live changes until a manual override; automatic mode can be restored', async ({page}) => {
    await page.emulateMedia({colorScheme:'light'});
    await page.goto(base + '/', {waitUntil:'domcontentloaded'}); await theme(page,'celestia');
    await expect(page.locator('html')).toHaveAttribute('data-theme-preference','auto');
    await page.emulateMedia({colorScheme:'dark'}); await theme(page,'luna');
    await choose(page,'celestia');
    await page.emulateMedia({colorScheme:'light'}); await page.emulateMedia({colorScheme:'dark'});
    await theme(page,'celestia');
    await page.reload({waitUntil:'domcontentloaded'}); await theme(page,'celestia');
    await choose(page,'auto'); await theme(page,'luna');
    expect(await page.evaluate(()=>localStorage.getItem('mlp.theme'))).toBe(null);
    await page.emulateMedia({colorScheme:'light'}); await theme(page,'celestia');
    await page.reload({waitUntil:'domcontentloaded'}); await theme(page,'celestia');
    await page.locator('[data-theme-toggle]').focus(); await page.keyboard.press('Enter');
    await expect(page.locator('.theme-choices')).toBeVisible();
    await page.keyboard.press('Escape'); await expect(page.locator('.theme-choices')).toBeHidden();
    await expect(page.locator('[data-theme-toggle]')).toBeFocused();
});
test('admin changes all four assets, rejects unsafe references before writes and can restore defaults', async ({page}, info) => {
    try {
        fixture('setup'); const user = JSON.parse(fs.readFileSync(state, 'utf8')); await login(page, user, 'admin');
        await page.goto(base + '/dashboard/', {waitUntil:'domcontentloaded'});
        const form = page.locator('#appearance-settings form');
        const values = {theme_luna_background:'/assets/img/themes/luna-background.webp?custom=1',theme_luna_logo:'/assets/img/themes/luna-logo.webp?custom=1',theme_celestia_background:'/assets/img/themes/celestia-background.webp?custom=1',theme_celestia_logo:'/assets/img/themes/celestia-logo.webp?custom=1'};
        for (const [key,value] of Object.entries(values)) await form.locator('[name="'+key+'"]').fill(value);
        const saved = page.waitForResponse(r => r.url().endsWith('/api.php') && r.request().postData()?.includes('update_settings'));
        await form.locator('button[type=submit]').click(); expect((await (await saved).json()).success).toBe(true);
        await page.reload({waitUntil:'domcontentloaded'});
        for (const [key,value] of Object.entries(values)) await expect(form.locator('[name="'+key+'"]')).toHaveValue(value);
        const post = data => page.evaluate(async data => (await fetch('/api.php', {method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:new URLSearchParams({...data,action:'update_settings',csrf_token:window.csrfToken})})).json(), data);
        expect((await post({theme_luna_background:'/assets/img/default-avatar.png',theme_celestia_logo:'javascript:alert(1)'})).success).toBe(false);
        await page.reload({waitUntil:'domcontentloaded'}); await expect(form.locator('[name=theme_luna_background]')).toHaveValue(values.theme_luna_background);
        await choose(page, 'celestia');
        await ready(page);
        await page.screenshot({path:path.join(shots, info.project.name+'-admin.png')});
        await page.setViewportSize({width:390,height:844});
        await form.scrollIntoViewIfNeeded();
        for (const key of Object.keys(values)) {
            const rect = await form.locator('[name="'+key+'"]').evaluate(el => {const r=el.getBoundingClientRect();return {left:r.left,right:r.right};});
            expect(rect.left).toBeGreaterThanOrEqual(0); expect(rect.right).toBeLessThanOrEqual(390);
        }
        await ready(page); await page.screenshot({path:path.join(shots,info.project.name+'-admin-mobile.png')});
        await page.goto(base+'/',{waitUntil:'domcontentloaded'}); await expect(page.locator('.theme-logo-celestia')).toHaveAttribute('src',values.theme_celestia_logo);
        expect(await page.evaluate(()=>getComputedStyle(document.documentElement).backgroundImage)).toContain('custom=1');
        await page.context().clearCookies(); await login(page,user,'reader'); await page.goto(base+'/chat_popup.php',{waitUntil:'domcontentloaded'});
        expect((await post({theme_luna_logo:'/assets/img/default-avatar.png'})).success).toBe(false);
        await page.context().clearCookies(); await login(page,user,'admin'); await page.goto(base+'/dashboard/',{waitUntil:'domcontentloaded'});
        expect((await post(Object.fromEntries(Object.keys(values).map(k=>[k,''])))).success).toBe(true);
        await page.goto(base+'/',{waitUntil:'domcontentloaded'}); await expect(page.locator('.theme-logo-celestia')).toHaveAttribute('src','/assets/img/themes/celestia-logo.webp');
    } finally {if(fs.existsSync(state)) fixture('cleanup');}
});
test('authenticated chat, profile and quote cards use readable theme surfaces in both layouts', async ({page}, info) => {
    try {
        fixture('setup'); const user = JSON.parse(fs.readFileSync(state, 'utf8')); await login(page, user, 'reader');
        for (const url of ['/', '/chat_popup.php']) {
            await page.goto(base + url, {waitUntil:'domcontentloaded'});
            for (const name of ['luna', 'celestia']) {
                if (await page.locator('html').getAttribute('data-theme') !== name) await choose(page, name);
                await theme(page, name);
                const message = page.locator(`.chat-message[data-id="${user.message}"]`);
                await expect(message).toBeVisible();
                await expect(page.locator('.quote-content').last()).toHaveCSS('color', name === 'celestia' ? 'rgb(53, 35, 63)' : 'rgb(240, 237, 248)');
                const colors = await message.locator('.chat-text').evaluate(el => ({text:getComputedStyle(el).color, expected:getComputedStyle(document.documentElement).getPropertyValue('--text-main').trim()}));
                expect(colors.text).toBe(name === 'celestia' ? 'rgb(53, 35, 63)' : 'rgb(240, 237, 248)');
                const spoiler = message.locator('.md-spoiler');
                expect(await spoiler.evaluate(el=>getComputedStyle(el).color)).toBe(await spoiler.evaluate(el=>getComputedStyle(el).backgroundColor));
                await page.evaluate(() => {window.openProfileModal();window.switchProfileTab('visual');});
                await expect(page.locator('#profile-modal')).toBeVisible();
                await ready(page);
                await page.screenshot({path:path.join(shots, `${info.project.name}-${name}-${url === '/' ? 'embedded' : 'popup'}-profile.png`)});
                await page.locator('.close-modal-profile').click();
                await expect(page.locator('#profile-modal')).toBeHidden();
                await ready(page);
                await page.screenshot({path:path.join(shots, `${info.project.name}-${name}-${url === '/' ? 'embedded' : 'popup'}-message.png`)});
            }
        }
    } finally {if(fs.existsSync(state)) fixture('cleanup');}
});
