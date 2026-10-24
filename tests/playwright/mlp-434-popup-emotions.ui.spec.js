const {test,expect}=require('@playwright/test');
const {execFileSync}=require('child_process'),fs=require('fs'),path=require('path');
const {BASE,login,post}=require('./mlp-360-helpers');
const root=path.resolve(__dirname,'../..'),stateFile=path.join(root,'docs/private/mlp420-local.json');
const fixture=mode=>execFileSync('docker',['exec','mlp375-php-1','php','tests/playwright/mlp-420-422-fixture.php',mode],{cwd:root,encoding:'utf8'});
const state=()=>JSON.parse(fs.readFileSync(stateFile,'utf8'));
const picker=page=>page.locator('[data-stream-reactions="chat"]');
const requests=new WeakMap();
test.use({serviceWorkers:'block',viewport:{width:520,height:850},launchOptions:{args:['--no-proxy-server'],firefoxUserPrefs:{'network.proxy.type':0}}});
async function external(route){
    if(route.request().url().startsWith('https://video.invalid/'))return route.fulfill({contentType:'text/html',body:'<!doctype html><body>Video fixture</body>'});
    return route.abort();
}
async function necessary(page){if(await page.locator('#privacy-panel').isVisible())await page.locator('[data-privacy-necessary]').click();}
async function openPopup(page,authenticated=true){
    if(authenticated){const s=state();await login(page.request,{login:s.readerLogin,password:s.password});}
    await page.goto(BASE+'/chat_popup.php',{waitUntil:'domcontentloaded'});await necessary(page);
}
test.beforeEach(async({page})=>{
    if(!BASE||!['localhost','127.0.0.1'].includes(new URL(BASE).hostname))throw Error('Isolated localhost required');
    fixture('setup');requests.set(page,[]);
    await page.route('https://**',external);
    await page.addInitScript(()=>{window.EventSource=class{addEventListener(){}close(){}};});
    page.on('request',request=>{if(request.postData()?.includes('action=send_stream_reaction'))requests.get(page).push(request);});
});
test.afterEach(()=>{if(fs.existsSync(stateFile))fixture('cleanup');});
test('MLP-434 popup sends an emotion without a player or external-media consent; real SSE delivers it to viewers',async({page,browser})=>{
    await openPopup(page);
    expect(await page.evaluate(()=>window.privacyChoices.external_media)).toBe(false);
    await expect(page.locator('iframe,.video-stage')).toHaveCount(0);
    await expect(page.locator('[data-stream-reactions]')).toHaveCount(1);
    await picker(page).getByRole('button',{name:'Эмоции к эфиру',exact:true}).click();
    await expect(picker(page).locator('.stream-emotions-choice,.stream-fullscreen')).toHaveCount(0);
    const context=await browser.newContext({serviceWorkers:'block',viewport:{width:1280,height:850}});
    try {
        await context.route('https://**',external);
        const viewer=await context.newPage();
        await viewer.addInitScript(()=>{
            const NativeEventSource=window.EventSource;
            window.EventSource=class extends NativeEventSource{constructor(url){super(url);this.addEventListener('open',()=>window.viewerStreamOpen=true);}};
        });
        await viewer.goto(BASE+'/',{waitUntil:'domcontentloaded'});await necessary(viewer);
        await viewer.locator('[data-privacy-media]').click();
        await expect(viewer.locator('.video-content iframe')).toBeVisible();
        await expect.poll(()=>viewer.evaluate(()=>window.viewerStreamOpen===true)).toBe(true);
        const before=await viewer.locator('.chat-message').count();
        const received=page.waitForResponse(r=>r.url().endsWith('/api.php')&&r.request().postData()?.includes('action=send_stream_reaction'));
        await picker(page).getByRole('button',{name:'Огонь',exact:true}).click();
        const response=await(await received).json();
        expect(response.success).toBe(true);expect(response.data.cooldown).toBe(3);
        await expect(picker(page).getByRole('button',{name:'Огонь',exact:true})).toBeDisabled();
        const csrf=await page.evaluate(()=>window.csrfToken);
        expect((await post(page.request,csrf,{action:'send_stream_reaction',reaction:'heart'})).success).toBe(false);
        await expect(viewer.locator('.stream-emotion')).toHaveText('🔥');
        expect(await viewer.locator('.chat-message').count()).toBe(before);
        await expect(page.locator('iframe')).toHaveCount(0);
        expect(requests.get(page)).toHaveLength(1);
        await expect(picker(page).getByRole('button',{name:'Огонь',exact:true})).toBeEnabled({timeout:5000});
    } finally {await context.close();}
});
test('MLP-434 popup picker fits narrow windows in both themes and closes with Escape or outside click',async({page},info)=>{
    await openPopup(page);
    const toggle=picker(page).getByRole('button',{name:'Эмоции к эфиру',exact:true});
    const panel=picker(page).locator('.stream-reaction-panel');
    const dir=path.join(root,'docs/tests/MLP-434');fs.mkdirSync(dir,{recursive:true});
    for(const colorScheme of ['light','dark']){
        await page.emulateMedia({colorScheme});
        for(const width of [320,390]){
            await page.setViewportSize({width,height:844});
            await toggle.focus();await page.keyboard.press('Enter');
            await expect(panel).toBeVisible();
            const bounds=await panel.boundingBox();expect(bounds.x).toBeGreaterThanOrEqual(0);expect(bounds.x+bounds.width).toBeLessThanOrEqual(width+1);
            await expect(picker(page).locator('[data-stream-reaction]')).toHaveCount(8);
            expect(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth)).toBe(true);
            await page.screenshot({path:path.join(dir,info.project.name+'-'+colorScheme+'-'+width+'.png')});
            await page.keyboard.press('Escape');await expect(panel).toBeHidden();await expect(toggle).toBeFocused();
        }
    }
    await toggle.click();await page.locator('#chat-input').click();await expect(panel).toBeHidden();
    await page.locator('#chat-input').fill('Текст остаётся в поле');await toggle.click();await page.keyboard.press('Escape');
    await expect(page.locator('#chat-input')).toHaveValue('Текст остаётся в поле');
});
test('MLP-434 popup prompts guests to sign in and shows server rejection for a banned account',async({page})=>{
    await openPopup(page,false);
    await picker(page).getByRole('button',{name:'Эмоции к эфиру',exact:true}).click();
    await picker(page).getByRole('button',{name:'Огонь',exact:true}).click();
    await expect(page.locator('.flash-message')).toContainText('Войди, чтобы отправлять эмоции');
    expect(requests.get(page)).toHaveLength(0);
    await openPopup(page);fixture('ban');
    await picker(page).getByRole('button',{name:'Эмоции к эфиру',exact:true}).click();
    const received=page.waitForResponse(r=>r.url().endsWith('/api.php')&&r.request().postData()?.includes('action=send_stream_reaction'));
    await picker(page).getByRole('button',{name:'Огонь',exact:true}).click();
    const response=await(await received).json();expect(response.success).toBe(false);
    await expect(page.locator('.flash-message')).toContainText(response.message);
    await expect(picker(page).getByRole('button',{name:'Огонь',exact:true})).toBeEnabled();
});
