const {test,expect}=require('@playwright/test');const {execFileSync}=require('node:child_process');const fs=require('node:fs');const path=require('node:path');
const {BASE,login,post}=require('./mlp-360-helpers');const root=path.resolve(__dirname,'../..');
const cli=(name,...args)=>execFileSync('docker',['exec','mlp375-php-1','php','tests/playwright/'+name+'-fixture.php',...args],{cwd:root,encoding:'utf8'});
const privateFile=name=>path.join(root,'docs/private/'+name+'-local.json');
const auth=()=>JSON.parse(fs.readFileSync(privateFile('mlp425'),'utf8')).users;
test.use({serviceWorkers:'block',viewport:{width:1366,height:900},launchOptions:{args:['--no-proxy-server'],firefoxUserPrefs:{'network.proxy.type':0}}});
test.beforeEach(async({page})=>{
    if(!BASE||!['localhost','127.0.0.1'].includes(new URL(BASE).hostname))throw Error('Isolated localhost required');
    cli('mlp-425-reasoning','setup');cli('mlp-437-stream','setup');await page.route('https://**',route=>route.abort());
});
test.afterEach(()=>{if(fs.existsSync(privateFile('mlp437')))cli('mlp-437-stream','cleanup');if(fs.existsSync(privateFile('mlp425')))cli('mlp-425-reasoning','cleanup');});
async function open(page,url='/'){const response=await page.goto(BASE+url,{waitUntil:'domcontentloaded'});if(await page.locator('#privacy-panel').isVisible())await page.locator('[data-privacy-necessary]').click();return response;}
async function clip(page){await page.route('**/live/evening/**',route=>{const file=new URL(route.request().url()).pathname.split('/').pop();if(!/^(?:(?:index|video_stream|audio_stream)\.m3u8|(?:video|audio)(?:_init\.mp4|\d+\.m4s))$/.test(file))return route.fulfill({status:404});return route.fulfill({path:path.join(root,'tests/fixtures/self-hosted-hls',file),contentType:file.endsWith('.m3u8')?'application/vnd.apple.mpegurl':'video/mp4'});});}
test('MLP-437 first-party player is available with necessary privacy and handles offline safely',async({page})=>{
    await page.route('**/live/evening/**',route=>route.fulfill({status:404}));await open(page);
    await expect(page.locator('[data-self-hosted-url]')).toBeVisible();await expect(page.locator('.self-hosted-state')).toContainText('Эфир пока недоступен');
    await expect(page.locator('.video-content iframe')).toHaveCount(0);expect(await page.evaluate(()=>privacyChoices.external_media)).toBe(false);
    const denied=await post(page.request,'',{action:'self_hosted_stream_credentials'});expect(denied.success).toBe(false);
});
test('MLP-437 actual HLS decodes video, stores volume, and fits mobile layout',async({page},info)=>{
    let audioFragments=0;page.on('response',response=>{if(/\/audio\d+\.m4s$/.test(response.url())&&response.status()===200)audioFragments++;});
    await clip(page);await open(page);const video=page.locator('[data-self-hosted-url] video');
    await expect.poll(()=>video.evaluate(v=>v.currentTime),{timeout:15000}).toBeGreaterThan(.2);await expect(page.locator('.self-hosted-state')).toBeHidden();
    expect(audioFragments).toBeGreaterThan(0);
    await video.evaluate(v=>{v.volume=.37;});expect(await page.evaluate(()=>localStorage.getItem('mlp.self-hosted.volume'))).toBe('0.37');
    await page.reload({waitUntil:'domcontentloaded'});await expect.poll(()=>video.evaluate(v=>v.volume)).toBe(.37);
    await page.setViewportSize({width:390,height:844});expect(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1)).toBe(true);
    await page.screenshot({path:path.join(root,'docs/tests/MLP-437/'+info.project.name+'-player.png'),fullPage:true});
});
test('MLP-437 manual reconnect starts an offline stream without reloading the page',async({page})=>{
    await page.route('**/live/evening/**',route=>route.fulfill({status:404}));await open(page);await expect(page.locator('.self-hosted-state')).toContainText('Эфир пока недоступен');
    await page.unroute('**/live/evening/**');await clip(page);await page.locator('[data-stream-retry]').click();
    await expect.poll(()=>page.locator('video').evaluate(v=>v.readyState),{timeout:15000}).toBeGreaterThanOrEqual(2);
});
test('MLP-437 dashboard saves sources without clearing settings and reveals credentials only on demand',async({page})=>{
    const csrf=await login(page.request,auth().admin);await open(page,'/dashboard/#tab-settings');const form=page.locator('#stream-settings form');
    const markup=await(await page.request.get(BASE+'/dashboard/')).text();expect(markup).not.toContain('pass='+ 'a'.repeat(64));
    await page.locator('#stream-settings summary').click();await page.locator('[data-self-stream-credentials]').click();await expect(page.locator('[data-self-stream-key]')).toHaveValue('evening?user=broadcaster&pass='+'a'.repeat(64));
    await expect(page.locator('[data-self-stream-key]')).toHaveAttribute('type','password');await page.locator('[data-self-stream-reveal]').click();await expect(page.locator('[data-self-stream-key]')).toHaveAttribute('type','text');
    await form.locator('[name=stream_source]').selectOption('external');await form.getByRole('button',{name:'Сохранить',exact:true}).click();await expect(page.locator('.flash-message')).toContainText('Настройки плеера сохранены');
    await expect(form.locator('[name=stream_source]')).toHaveValue('external');await page.reload({waitUntil:'domcontentloaded'});await expect(form.locator('[name=stream_source]')).toHaveValue('external');
    await page.locator('[data-self-stream-status]').click();await expect(page.locator('[data-self-stream-message]')).toContainText('Медиасервер пока недоступен');
    const bad=await post(page.request,csrf,{action:'save_stream_settings',stream_source:'self_hosted',stream_url:'https://player.twitch.tv/?channel=fixture',self_hosted_hls_url:'https://evil.test/a.m3u8'});expect(bad.success).toBe(false);
});
test('MLP-437 preview is admin-only and does not change the public external source',async({page})=>{
    cli('mlp-437-stream','external');const csrf=await login(page.request,auth().user);await open(page,'/stream-preview.php');await expect(page.locator('[data-self-hosted-url]')).toHaveCount(0);
    expect((await post(page.request,csrf,{action:'self_hosted_stream_credentials'})).success).toBe(false);
    await page.context().clearCookies();await login(page.request,auth().admin);await clip(page);await open(page,'/stream-preview.php');await expect(page.locator('[data-self-hosted-url]')).toBeVisible();
    await open(page,'/?self_hosted_preview=1');await expect(page.locator('[data-twitch-options]')).toBeVisible();await expect(page.locator('[data-self-hosted-url]')).toHaveCount(0);
});
