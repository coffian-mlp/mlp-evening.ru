const {test,expect}=require('@playwright/test');
const {execFileSync}=require('child_process');const fs=require('fs'),path=require('path');
const root=path.resolve(__dirname,'../..'),base=process.env.MLP_BASE_URL;
const state=path.join(root,'docs/private/mlp414-local.json'),shots=path.join(root,'docs/tests/MLP-414');
const fixture=action=>execFileSync('docker',['exec','mlp375-php-1','php','tests/playwright/mlp-414-privacy-fixture.php',action],{cwd:root});
test.use({serviceWorkers:'block',viewport:{width:1920,height:1080},launchOptions:{args:['--no-proxy-server'],firefoxUserPrefs:{'network.proxy.type':0}}});
test.beforeEach(async({page})=>{
    if(!base||!['127.0.0.1','localhost'].includes(new URL(base).hostname))throw Error('Isolated localhost required');
    await page.route('https://**',r=>r.abort());await page.route('**/chat_stream.php*',r=>r.abort());
    fs.mkdirSync(shots,{recursive:true});
});
async function post(page,data){return page.evaluate(async data=>(await fetch('/api.php',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:new URLSearchParams({csrf_token:window.csrfToken,...data})})).json(),data);}
async function dialog(page){await page.locator('.privacy-settings-link').click();await expect(page.locator('#privacy-dialog')).toBeVisible();}
async function login(page,user,role){expect((await(await page.request.post(base+'/api.php',{form:{action:'login',username:user[role+'Login'],password:user.password}})).json()).success).toBe(true);}
test('no optional requests before consent; panel avoids the player at Full HD and on phone',async({page},info)=>{
    const external=[];page.on('request',r=>{if(r.url().startsWith('https://'))external.push(r.url());});
    await page.goto(base+'/',{waitUntil:'load'});await expect(page.locator('#privacy-panel')).toBeVisible();
    for(const scheme of ['dark','light']){
        await page.emulateMedia({colorScheme:scheme});
        await expect(page.locator('#privacy-panel')).toHaveCSS('background-color',scheme==='dark'?'rgb(30, 32, 61)':'rgb(255, 250, 246)');
    }
    expect(external.filter(x=>/twitch|unpkg|telegram/.test(x))).toEqual([]);
    for(const width of [1920,1366,1024,900,769,768,390]){
        await page.setViewportSize({width,height:width===1920?1080:844});
        await page.waitForTimeout(350);
        const p=await page.locator('.video-content').boundingBox(),b=await page.locator('#privacy-panel').boundingBox();
        const intersection=Math.max(0,Math.min(p.x+p.width,b.x+b.width)-Math.max(p.x,b.x))*Math.max(0,Math.min(p.y+p.height,b.y+b.height)-Math.max(p.y,b.y));
        expect(intersection).toBe(0);expect(b.x).toBeGreaterThanOrEqual(0);expect(b.x+b.width).toBeLessThanOrEqual(width);
        if([1920,390].includes(width))await page.screenshot({path:path.join(shots,info.project.name+'-panel-'+width+'.png')});
    }
    await page.locator('[data-privacy-necessary]').click();await expect(page.locator('#privacy-panel')).toBeHidden();
    expect(await page.locator('.video-content iframe').count()).toBe(0);
    await page.reload({waitUntil:'load'});await expect(page.locator('#privacy-panel')).toBeHidden();
    const refused=await post(page,{action:'ux_measure',page_id:'1'.repeat(32),path:'/'});expect(refused.success).toBe(false);
    await page.goto(base+'/schedule.php',{waitUntil:'load'});await dialog(page);
    await expect(page.locator('#privacy-analytics')).not.toBeChecked();await expect(page.locator('#privacy-external')).not.toBeChecked();
});
test('player is independently allowed and removed after withdrawal; analytics starts and stops server-side',async({page},info)=>{
    let sdk=0;const reports=[];
    page.on('request',r=>{if(r.postData()?.includes('ux_measure'))reports.push(r.postData());});
    await page.route('https://player.twitch.tv/js/embed/v1.js',async r=>{sdk++;await r.fulfill({contentType:'application/javascript',body:`window.Twitch={Player:class {constructor(id){this.el=document.getElementById(id);this.el.appendChild(document.createElement('iframe'));this.volume=.5;}addEventListener(name,cb){if(name==='ready')setTimeout(cb,0);}setVolume(v){this.volume=v;}getVolume(){return this.volume;}destroy(){this.el.replaceChildren();}}};Twitch.Player.READY='ready';Twitch.Player.PLAY='play';Twitch.Player.PLAYING='playing';`});});
    try{
        fixture('setup');
        await page.goto(base+'/',{waitUntil:'load'});expect(sdk).toBe(0);
        await page.locator('[data-privacy-necessary]').click();await page.locator('[data-privacy-media]').click();
        await expect(page.locator('.video-content iframe')).toHaveCount(1);expect(sdk).toBe(1);expect(reports).toHaveLength(0);
        await dialog(page);await page.locator('#privacy-analytics').check();await page.locator('[data-privacy-save]').click();
        await expect.poll(()=>reports.length).toBeGreaterThan(0);
        await dialog(page);await page.locator('#privacy-analytics').uncheck();await page.locator('#privacy-external').uncheck();await page.locator('[data-privacy-save]').click();
        await expect(page.locator('.video-content iframe')).toHaveCount(0);await expect(page.locator('[data-privacy-media]')).toBeVisible();
        const count=reports.length;await page.setViewportSize({width:1440,height:900});await page.waitForTimeout(900);expect(reports).toHaveLength(count);
        expect((await post(page,{action:'ux_measure',page_id:'2'.repeat(32),path:'/'})).success).toBe(false);
        await page.screenshot({path:path.join(shots,info.project.name+'-player-denied.png')});
    }finally{if(fs.existsSync(state))fixture('cleanup');}
});
test('withdrawn authenticated user keeps profile only; explicit renewal restores actions',async({page},info)=>{
    try{
        fixture('setup');const user=JSON.parse(fs.readFileSync(state,'utf8'));await login(page,user,'reader');
        await page.goto(base+'/profile.php',{waitUntil:'load'});await expect(page.locator('[data-account-consent=withdraw]')).toBeVisible();
        expect((await post(page,{action:'privacy_file_review',path:'/upload/chat/other.txt',decision:'belongs'})).success).toBe(false);
        page.once('dialog',d=>d.accept());await page.locator('[data-account-consent=withdraw]').click();
        await expect(page.locator('#privacy')).toContainText('Учётная запись неактивна');
        for(const action of ['send_message','toggle_reaction','catalogue_wish','create_poll','update_settings','upload_file']){
            const result=await post(page,{action,message:'Must not send'});expect(result.success,action).toBe(false);expect(result.data.privacy_required).toBe(true);
        }
        expect((await post(page,{action:'update_profile',nickname:'Privacy reader updated'})).success).toBe(true);
        await page.goto(base+'/',{waitUntil:'load'});await expect(page).toHaveURL(base+'/profile.php');
        await page.screenshot({path:path.join(shots,info.project.name+'-inactive-profile.png')});
        await page.locator('#account-consent-check').check();await page.locator('[data-account-consent=accept]').click();
        await expect(page.locator('[data-account-consent=withdraw]')).toBeVisible();
        await page.goto(base+'/',{waitUntil:'load'});await expect(page).toHaveURL(base+'/');
    }finally{if(fs.existsSync(state))fixture('cleanup');}
});
test('expired account erasure invalidates the existing browser session and password login',async({page})=>{
    try{
        fixture('setup');const user=JSON.parse(fs.readFileSync(state,'utf8'));await login(page,user,'reader');
        await page.goto(base+'/profile.php',{waitUntil:'load'});
        page.once('dialog',d=>d.accept());await page.locator('[data-account-consent=withdraw]').click();
        await expect(page.locator('#privacy')).toContainText('Учётная запись неактивна');
        fixture('erase-reader');
        expect((await post(page,{action:'send_message',message:'Old session must not work'})).success).toBe(false);
        expect((await(await page.request.post(base+'/api.php',{form:{action:'login',username:user.readerLogin,password:user.password}})).json()).success).toBe(false);
        await page.goto(base+'/profile.php',{waitUntil:'load'});await expect(page).toHaveURL(/login.php/);
    }finally{if(fs.existsSync(state))fixture('cleanup');}
});
test('admin sees statistics and editable operator details; contact validation precedes writes',async({page},info)=>{
    try{
        fixture('setup');const user=JSON.parse(fs.readFileSync(state,'utf8'));await login(page,user,'admin');
        await page.goto(base+'/dashboard/#tab-statistics',{waitUntil:'load'});
        await expect(page.locator('.ux-statistics')).toBeVisible();await expect(page.locator('.ux-counters')).toContainText('посещений');
        await page.screenshot({path:path.join(shots,info.project.name+'-statistics.png')});
        await page.locator('[data-target="#tab-settings"]').click();
        const form=page.locator('#privacy-settings');await expect(form.locator('[name=privacy_operator]')).toHaveValue('Овчаров Константин Александрович');
        expect((await post(page,{action:'update_settings',privacy_operator:'Must not save',privacy_contact_email:'broken'})).success).toBe(false);
        await page.reload({waitUntil:'load'});await expect(form.locator('[name=privacy_operator]')).toHaveValue('Овчаров Константин Александрович');
    }finally{if(fs.existsSync(state))fixture('cleanup');}
});
test('explicit exit revokes browser choices, clears cookies and storage, and navigates without a referrer',async({page,context})=>{
    await page.route('https://yandex.ru/images/search?**',r=>r.fulfill({contentType:'text/html',body:'<p>Exit target mock</p>'}));
    await page.goto(base+'/privacy.php',{waitUntil:'load'});
    await page.locator('[data-privacy-all]').click();await expect(page.locator('#privacy-panel')).toBeHidden();
    await page.evaluate(()=>{localStorage.setItem('mlp.twitch.volume','0.2');sessionStorage.setItem('test','1');});
    await dialog(page);
    const request=page.waitForRequest(r=>r.url().startsWith('https://yandex.ru/images/search'));
    await page.locator('#privacy-dialog [data-privacy-exit]').click();
    const outgoing=await request;expect(outgoing.headers().referer).toBeUndefined();
    await expect(page).toHaveURL(/yandex.ru\/images\/search/);
    expect(new URL(page.url()).searchParams.get('text')).toBe('грустная Пинки Пай');
    await expect.poll(async()=>(await context.cookies(base)).filter(c=>['PHPSESSID','mlp_privacy','mlp_remember'].includes(c.name))).toEqual([]);
    await page.route(base+'/__mlp414-storage-check',r=>r.fulfill({contentType:'text/html',body:'<p>Storage probe</p>'}));
    await page.goto(base+'/__mlp414-storage-check');expect(await page.evaluate(()=>localStorage.length)).toBe(0);expect(await page.evaluate(()=>sessionStorage.length)).toBe(0);
});
