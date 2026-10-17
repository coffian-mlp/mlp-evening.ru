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
test('withdrawn authenticated user keeps public reading and profile; explicit renewal restores actions',async({page},info)=>{
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
        await page.goto(base+'/',{waitUntil:'load'});await expect(page).toHaveURL(base+'/');
        await expect(page.locator('.chat-messages')).toContainText('Public fixture message');
        expect((await post(page,{action:'get_public_events'})).success).toBe(true);
        expect((await post(page,{action:'get_messages'})).success).toBe(true);
        await page.screenshot({path:path.join(shots,info.project.name+'-inactive-profile.png')});
        await page.goto(base+'/profile.php',{waitUntil:'load'});
        await page.locator('#account-consent-check').check();await page.locator('.account-privacy [data-account-consent=accept]').click();
        await expect(page.locator('[data-account-consent=withdraw]')).toBeVisible();
        await page.goto(base+'/',{waitUntil:'load'});await expect(page).toHaveURL(base+'/');
    }finally{if(fs.existsSync(state))fixture('cleanup');}
});
test('existing account gets an explicit consent dialog and keeps chat, schedule and admin reads',async({page},info)=>{
    try{
        fixture('setup');fixture('pending');const user=JSON.parse(fs.readFileSync(state,'utf8'));await login(page,user,'admin');
        await page.goto(base+'/',{waitUntil:'load'});
        const consent=page.locator('#account-consent-dialog');await expect(consent).toBeVisible();
        await consent.getByRole('link',{name:'Полные условия и сведения об операторе'}).click();
        await expect(page).toHaveURL(base+'/privacy.php#account-consent');await expect(consent).toBeHidden();
        await expect(page.locator('#privacy-panel')).toHaveCSS('top','12px');
        await page.goto(base+'/',{waitUntil:'load'});await expect(consent).toBeVisible();
        await consent.getByRole('button',{name:'Пока только читать'}).click();
        await expect(page.locator('.chat-messages')).toContainText('Public fixture message');
        expect((await post(page,{action:'get_messages'})).success).toBe(true);
        await page.goto(base+'/schedule.php',{waitUntil:'load'});await expect(page).toHaveURL(base+'/schedule.php');
        expect((await post(page,{action:'get_public_events'})).success).toBe(true);
        await page.goto(base+'/dashboard/#tab-settings',{waitUntil:'load'});
        expect((await post(page,{action:'get_users'})).success).toBe(true);
        await page.evaluate(()=>window.jQuery.post('/api.php',{action:'update_settings',csrf_token:window.csrfToken,privacy_operator:'Must not save'},()=>{},'json'));
        await expect(consent).toBeVisible();await page.screenshot({path:path.join(shots,info.project.name+'-account-consent.png')});
        await page.locator('#account-consent-dialog-check').check();await consent.locator('[data-account-consent=accept]').click();
        await expect(consent).toBeHidden();await expect(page).toHaveURL(base+'/dashboard/#tab-settings');
        await expect(page.locator('#privacy-settings [name=privacy_operator]')).toHaveValue('Овчаров Константин Александрович');
    }finally{if(fs.existsSync(state))fixture('cleanup');}
});
test('account choices survive a fresh device and synchronize opt-out without another prompt',async({page,browser})=>{
    let other;
    try{
        fixture('setup');const user=JSON.parse(fs.readFileSync(state,'utf8'));await login(page,user,'reader');
        await page.goto(base+'/privacy.php',{waitUntil:'load'});await page.locator('[data-privacy-all]').click();await expect(page.locator('#privacy-panel')).toBeHidden();
        other=await browser.newContext({serviceWorkers:'block'});const second=await other.newPage();await second.route('https://**',r=>r.abort());await second.route('**/chat_stream.php*',r=>r.abort());
        await login(second,user,'reader');await second.goto(base+'/privacy.php',{waitUntil:'load'});
        await expect(second.locator('#privacy-panel')).toBeHidden();await dialog(second);
        await expect(second.locator('#privacy-analytics')).toBeChecked();await expect(second.locator('#privacy-external')).toBeChecked();
        await second.locator('#privacy-analytics').uncheck();await second.locator('#privacy-external').uncheck();
        const saved=second.waitForResponse(r=>r.url().endsWith('/api.php')&&r.request().postData()?.includes('action=privacy_choices'));
        await second.locator('[data-privacy-save]').click();expect((await(await saved).json()).success).toBe(true);
        expect((await post(page,{action:'ux_measure',page_id:'9'.repeat(32),path:'/'})).success).toBe(false);
        await page.reload({waitUntil:'load'});await expect(page.locator('#privacy-panel')).toBeHidden();await dialog(page);
        await expect(page.locator('#privacy-analytics')).not.toBeChecked();await expect(page.locator('#privacy-external')).not.toBeChecked();
    }finally{if(other)await other.close();if(fs.existsSync(state))fixture('cleanup');}
});
test('guest choice survives real registration and is available after login on a fresh device',async({page,browser})=>{
    let other;
    try{
        fixture('setup');const user=JSON.parse(fs.readFileSync(state,'utf8'));
        await page.goto(base+'/',{waitUntil:'load'});await page.locator('[data-privacy-necessary]').click();await expect(page.locator('#privacy-panel')).toBeHidden();
        let captcha=(await post(page,{action:'captcha_start'})).data;
        const names={'Твайлайт Спаркл':'magic','Эпплджек':'honesty','Рэйнбоу Дэш':'loyalty','Пинки Пай':'laughter','Рарити':'generosity','Флаттершай':'kindness'};
        const answer=Object.entries(names).find(([name])=>captcha.question.includes(name))[1];
        captcha=(await post(page,{action:'captcha_check',answer})).data.next_step;
        const elements={'q_a1.png':'magic','q_b2.png':'honesty','q_c3.png':'loyalty','q_d4.png':'laughter','q_e5.png':'generosity','q_f6.png':'kindness'};
        expect((await post(page,{action:'captcha_check',answer:elements[captcha.image_url.split('/').pop()]})).success).toBe(true);
        expect((await post(page,{action:'captcha_check',answer:'спайк'})).data.completed).toBe(true);
        const version=await page.locator('input[name=policy_version]').first().inputValue();
        expect((await post(page,{action:'register',login:user.registrationLogin,password:user.password,policy_version:version})).success).toBe(false);
        expect((await post(page,{action:'register',login:user.registrationLogin,password:user.password,account_consent:'1',policy_version:'outdated'})).success).toBe(false);
        expect((await post(page,{action:'register',login:user.registrationLogin,password:user.password,account_consent:'1',policy_version:version})).success).toBe(true);
        await page.reload({waitUntil:'load'});await expect(page.locator('#privacy-panel')).toBeHidden();await expect(page.locator('#account-consent-dialog')).toBeHidden();
        other=await browser.newContext({serviceWorkers:'block'});const second=await other.newPage();await second.route('https://**',r=>r.abort());await second.route('**/chat_stream.php*',r=>r.abort());
        await login(second,{readerLogin:user.registrationLogin,password:user.password},'reader');await second.goto(base+'/privacy.php',{waitUntil:'load'});
        await expect(second.locator('#privacy-panel')).toBeHidden();await dialog(second);await expect(second.locator('#privacy-analytics')).not.toBeChecked();await expect(second.locator('#privacy-external')).not.toBeChecked();
    }finally{if(other)await other.close();if(fs.existsSync(state))fixture('cleanup');}
});
test('details page keeps the guest panel at the top of the window',async({page},info)=>{
    await page.goto(base+'/privacy.php',{waitUntil:'load'});await expect(page.locator('#privacy-panel')).toBeVisible();
    await expect.poll(async()=>Math.round((await page.locator('#privacy-panel').boundingBox()).y)).toBe(12);
    await page.setViewportSize({width:390,height:844});await expect(page.locator('#privacy-panel')).toBeVisible();await page.waitForTimeout(100);
    expect((await page.locator('#privacy-panel').boundingBox()).y).toBe(12);await page.screenshot({path:path.join(shots,info.project.name+'-details-top.png')});
});
test('SSE remains readable before consent and after withdrawal',async({page})=>{
    try{
        fixture('setup');fixture('pending');const user=JSON.parse(fs.readFileSync(state,'utf8'));await login(page,user,'reader');
        await page.goto(base+'/profile.php',{waitUntil:'load'});await page.unroute('**/chat_stream.php*');
        const streamStatus=()=>page.evaluate(async()=>{const controller=new AbortController();const response=await fetch('/chat_stream.php',{signal:controller.signal});const status=response.status;controller.abort();return status;});
        expect(await streamStatus()).toBe(200);
        const version=await page.locator('.account-privacy [data-account-consent=accept]').getAttribute('data-version');
        expect((await post(page,{action:'privacy_account',decision:'withdraw',confirm:'withdraw',policy_version:version})).success).toBe(true);
        expect(await streamStatus()).toBe(200);
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
