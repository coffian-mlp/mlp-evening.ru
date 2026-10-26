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
async function clip(page,{live=false,segments=()=>5}={}){
    await page.route('**/live/evening/**',route=>{
        const file=new URL(route.request().url()).pathname.split('/').pop();
        if(!/^(?:(?:index|video_stream|audio_stream)\.m3u8|(?:video|audio)(?:_init\.mp4|\d+\.m4s))$/.test(file))return route.fulfill({status:404});
        const fixture=path.join(root,'tests/fixtures/self-hosted-hls',file);
        if(live&&file.endsWith('_stream.m3u8')){
            const body=fs.readFileSync(fixture,'utf8').replace(/^#EXT-X-(?:PLAYLIST-TYPE:.*|ENDLIST)\r?\n?/gm,'').replace(/#EXTINF:[^\n]+\n(?:audio|video)(\d+)\.m4s\n/g,(block,index)=>Number(index)<segments()?block:'');
            return route.fulfill({body,contentType:'application/vnd.apple.mpegurl'});
        }
        return route.fulfill({path:fixture,contentType:file.endsWith('.m3u8')?'application/vnd.apple.mpegurl':'video/mp4'});
    });
}
test('MLP-437 first-party player is available with necessary privacy and handles offline safely',async({page})=>{
    await page.route('**/live/evening/**',route=>route.fulfill({status:404}));await open(page);
    await expect(page.locator('[data-self-hosted-url]')).toBeVisible();await expect(page.locator('.self-hosted-state')).toContainText('Ожидаем эфир');
    await expect(page.locator('.video-content iframe')).toHaveCount(0);expect(await page.evaluate(()=>privacyChoices.external_media)).toBe(false);
    await expect(page.locator('[data-stream-play]')).toBeDisabled();await expect(page.getByRole('slider',{name:'Громкость',exact:true})).toBeVisible();
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
    await page.route('**/live/evening/**',route=>route.fulfill({status:404}));await open(page);await expect(page.locator('.self-hosted-state')).toContainText('Ожидаем эфир');
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
test('MLP-438 compact controls replace the native timeline and support keyboard, mute and saved volume',async({page},info)=>{
    await page.setViewportSize({width:1920,height:1080});await clip(page);await open(page);
    const player=page.locator('[data-self-hosted-url]'),video=player.locator('video'),play=player.locator('[data-stream-play]'),volume=player.getByRole('slider',{name:'Громкость',exact:true});
    await expect.poll(()=>video.evaluate(v=>v.currentTime)).toBeGreaterThan(.2);
    expect(await video.evaluate(v=>v.controls)).toBe(false);await expect(player.getByRole('slider')).toHaveCount(1);await expect(page.locator('.stream-fullscreen')).toHaveCount(1);
    await expect(play).toHaveAccessibleName('Пауза');await play.focus();await play.press('Space');await expect.poll(()=>video.evaluate(v=>v.paused)).toBe(true);await expect(play).toHaveAccessibleName('Смотреть прямой эфир');
    await volume.focus();await volume.press('Home');await expect.poll(()=>video.evaluate(v=>v.volume)).toBe(0);await expect(player.locator('[data-stream-mute]')).toHaveAccessibleName('Включить звук');
    await volume.press('ArrowRight');await expect.poll(()=>video.evaluate(v=>!v.muted&&v.volume===.01)).toBe(true);await expect(volume).toHaveAttribute('aria-valuetext','1%');
    await player.getByRole('button',{name:'Выключить звук',exact:true}).click();await expect.poll(()=>video.evaluate(v=>v.muted)).toBe(true);
    await player.getByRole('button',{name:'Включить звук',exact:true}).click();await expect.poll(()=>video.evaluate(v=>!v.muted&&v.volume===.01)).toBe(true);
    const dir=path.join(root,'docs/tests/MLP-438');fs.mkdirSync(dir,{recursive:true});await page.screenshot({path:path.join(dir,info.project.name+'-fullhd.png')});
    await play.press('Space');await expect.poll(()=>video.evaluate(v=>v.paused)).toBe(false);
    await page.reload({waitUntil:'domcontentloaded'});await expect.poll(()=>video.evaluate(v=>v.volume)).toBe(.01);
    await page.setViewportSize({width:320,height:720});await expect(play).toBeVisible();await expect(volume).toBeVisible();
    const bounds=await player.locator('.self-hosted-controls').boundingBox(),range=await volume.boundingBox();expect(range.x+range.width).toBeLessThanOrEqual(bounds.x+bounds.width+1);expect(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1)).toBe(true);
    await page.screenshot({path:path.join(dir,info.project.name+'-mobile.png')});
});
test('MLP-438 resuming a paused live stream returns to the current broadcast',async({page})=>{
    let segments=3;await clip(page,{live:true,segments:()=>segments});await open(page);const video=page.locator('[data-self-hosted-url] video'),play=page.locator('[data-stream-play]');
    await expect.poll(()=>video.evaluate(v=>v.currentTime)).toBeGreaterThan(.1);await play.click();await expect.poll(()=>video.evaluate(v=>v.paused)).toBe(true);
    segments=4;await expect.poll(()=>video.evaluate(v=>v.seekable.length?v.seekable.end(v.seekable.length-1)>7:v.duration===Infinity),{timeout:12000}).toBe(true);
    expect(await video.evaluate(v=>v.paused)).toBe(true);expect(await video.evaluate(v=>v.currentTime)).toBeLessThan(1);
    await video.evaluate(v=>v.addEventListener('playing',()=>{window.__resumedAt=v.currentTime;},{once:true}));
    await play.click();await expect.poll(()=>page.evaluate(()=>window.__resumedAt)).toBeGreaterThan(1.5);expect(await video.evaluate(v=>v.paused)).toBe(false);
});
test('MLP-438 play button recovers when autoplay is blocked',async({page})=>{
    await page.addInitScript(()=>{const play=HTMLMediaElement.prototype.play;let first=true;HTMLMediaElement.prototype.play=function(){if(first){first=false;return Promise.reject(new DOMException('Autoplay blocked','NotAllowedError'));}return play.call(this);};});
    await clip(page);await open(page);await expect(page.locator('.self-hosted-state')).toContainText('Нажми ▶');await expect(page.locator('[data-stream-play]')).toBeEnabled();
    await page.locator('[data-stream-play]').click();await expect.poll(()=>page.locator('video').evaluate(v=>v.currentTime)).toBeGreaterThan(.2);await expect(page.locator('.self-hosted-state')).toBeHidden();
});
async function observeWaiting(page){
    await page.addInitScript(()=>{
        window.__videoLoads=0;const load=HTMLMediaElement.prototype.load;HTMLMediaElement.prototype.load=function(){window.__videoLoads++;return load.call(this);};
        document.addEventListener('DOMContentLoaded',()=>{
            const box=document.querySelector('.self-hosted-state');if(!box)return;
            const snapshot=()=>({text:box.querySelector('[role=status]').textContent,hidden:box.hidden});window.__streamStates=[snapshot()];
            new MutationObserver(()=>window.__streamStates.push(snapshot())).observe(box,{childList:true,characterData:true,subtree:true,attributes:true,attributeFilter:['hidden']});
        });
    });
}
test('MLP-439 offline probes keep the waiting view unchanged without reloading video',async({page},info)=>{
    await observeWaiting(page);await page.clock.install();let checks=0;
    await page.route('**/live/evening/**',route=>{
        checks++;if(checks===3)return route.abort('failed');
        return route.fulfill({status:checks===1?404:checks===2?503:200,contentType:'text/html',body:'<html>No stream</html>'});
    });
    await open(page);await expect.poll(()=>checks).toBe(1);
    for(let check=2;check<=4;check++){await page.clock.fastForward(10100);await expect.poll(()=>checks).toBe(check);}
    await page.getByRole('button',{name:'Проверить сейчас',exact:true}).click();await expect.poll(()=>checks).toBe(5);
    expect(await page.evaluate(()=>window.__videoLoads)).toBe(0);expect(await page.locator('video').evaluate(v=>v.currentSrc)).toBe('');
    expect(await page.evaluate(()=>window.__streamStates)).toEqual([{text:'Ожидаем эфир. Видео включится автоматически.',hidden:false}]);
    const dir=path.join(root,'docs/tests/MLP-439');fs.mkdirSync(dir,{recursive:true});await page.screenshot({path:path.join(dir,info.project.name+'-waiting.png')});
});
test('MLP-439 background probe starts a new stream and returns to stable waiting when it ends',async({page})=>{
    await observeWaiting(page);await page.clock.install();await clip(page);let online=false,checks=0;
    await page.route('**/live/evening/**',route=>{if(online)return route.fallback();checks++;return route.fulfill({status:404});});
    await open(page);await expect.poll(()=>checks).toBe(1);expect(await page.evaluate(()=>window.__videoLoads)).toBe(0);
    online=true;await page.clock.fastForward(10100);const video=page.locator('video');
    await expect.poll(()=>video.evaluate(v=>v.currentTime),{timeout:15000}).toBeGreaterThan(.2);await expect(page.locator('.self-hosted-state')).toBeHidden();
    online=false;await video.evaluate(v=>{v.playbackRate=8;});await expect(page.locator('.self-hosted-state')).toBeVisible({timeout:15000});await expect(page.locator('.self-hosted-state')).toContainText('Ожидаем эфир');
    const loads=await page.evaluate(()=>window.__videoLoads);await page.evaluate(()=>{window.__streamStates=[];});
    await page.clock.fastForward(10100);await expect.poll(()=>checks).toBe(2);expect(await page.evaluate(()=>window.__videoLoads)).toBe(loads);expect(await page.evaluate(()=>window.__streamStates)).toEqual([]);
});
