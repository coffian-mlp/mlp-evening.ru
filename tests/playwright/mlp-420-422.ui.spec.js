const {test,expect}=require('@playwright/test');
const {execFileSync}=require('child_process'),fs=require('fs'),path=require('path');
const root=path.resolve(__dirname,'../..'),base=process.env.MLP_BASE_URL,stateFile=path.join(root,'docs/private/mlp420-local.json');
const fixture=mode=>execFileSync('docker',['exec','mlp375-php-1','php','tests/playwright/mlp-420-422-fixture.php',mode],{cwd:root,encoding:'utf8'});
const state=()=>JSON.parse(fs.readFileSync(stateFile,'utf8'));
test.use({serviceWorkers:'block',viewport:{width:1280,height:850},launchOptions:{args:['--no-proxy-server'],firefoxUserPrefs:{'network.proxy.type':0}}});
test.beforeEach(async({page})=>{
 if(!base||!['localhost','127.0.0.1'].includes(new URL(base).hostname))throw Error('Isolated localhost required');
 fixture('setup');
 await page.route('https://**',r=>r.request().url().startsWith('https://video.invalid/')?r.fulfill({contentType:'text/html',body:'<!doctype html><body style="background:#151525;color:#fff">Video fixture</body>'}):r.abort());
 await page.addInitScript(()=>{
  window.fixtureStreams=[];
  window.EventSource=class{constructor(url){this.url=url;this.listeners={};window.fixtureStreams.push(this);}addEventListener(name,callback){this.listeners[name]=callback;}close(){} };
  window.fixtureStreamEvent=data=>window.fixtureStreams.forEach(stream=>stream.listeners.stream_reaction?.({data:JSON.stringify(data)}));
 });
});
test.afterEach(()=>{if(fs.existsSync(stateFile))fixture('cleanup');});
async function necessary(page){if(await page.locator('#privacy-panel').isVisible())await page.locator('[data-privacy-necessary]').click();}
async function login(page,role){const s=state();const response=await page.request.post(base+'/api.php',{form:{action:'login',username:s[role+'Login'],password:s.password}});expect((await response.json()).success).toBe(true);}
async function post(page,action,fields={}){return (await(await page.request.post(base+'/api.php',{form:{action,csrf_token:await page.evaluate(()=>window.csrfToken),...fields}})).json());}
test('archive shows frozen program and corrected viewing, escapes titles and adapts to both themes',async({page},info)=>{
 const s=state();await page.goto(base+'/archive.php');await necessary(page);
 let card=page.locator('.archive-card').filter({has:page.getByRole('heading',{name:s.title,exact:true})});
 await expect(card).toBeVisible();await expect(card.locator('[data-watched=yes]')).toHaveCount(2);await expect(card.locator('[data-watched=no]')).toHaveCount(1);
 await expect(card).toContainText('<img src=x');expect(await page.evaluate(()=>window.archiveXss)).toBeUndefined();await expect(card.locator('img')).toHaveCount(0);
 fixture('correct');await page.reload();card=page.locator('.archive-card').filter({has:page.getByRole('heading',{name:s.title,exact:true})});await expect(card).toContainText('Список исправлен организатором');await expect(card.locator('[data-watched=yes]')).toHaveCount(1);
 const dir=path.join(root,'docs/tests/MLP-420-422');fs.mkdirSync(dir,{recursive:true});
 for(const colorScheme of ['light','dark']){await page.emulateMedia({colorScheme});await page.setViewportSize({width:390,height:844});expect(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1)).toBe(true);await page.screenshot({path:path.join(dir,info.project.name+'-archive-'+colorScheme+'.png'),fullPage:true});}
});
test('usage is admin-only, preserves unknown costs and applies edited rates only to later requests',async({page},info)=>{
 await login(page,'reader');await page.goto(base+'/');await necessary(page);expect((await post(page,'get_llm_usage')).success).toBe(false);expect((await post(page,'save_llm_usage_rates',{rates:'[]'})).success).toBe(false);
 await page.context().clearCookies();await login(page,'admin');await page.goto(base+'/dashboard/index.php');await necessary(page);
 await page.evaluate(()=>{location.hash='tab-bot';});const root=page.locator('#llm-usage');await expect(root).toBeVisible();await expect(root.locator('#usage-status')).toContainText('За последние');
 const s=state();const row=root.locator('#usage-rows tr').filter({hasText:s.model});await expect(row).toContainText('Нет тарифа или данных');
 await root.locator('summary').click();const rateRow=root.locator('#usage-rates tr').filter({has:page.locator('input[data-field=model]')});
 const index=await root.locator('#usage-rates input[data-field=model]').evaluateAll((nodes,model)=>nodes.findIndex(node=>node.value===model),s.model);expect(index).toBeGreaterThanOrEqual(0);
 const rate=rateRow.nth(index);await rate.locator('[data-field=input]').fill('1');await rate.locator('[data-field=output]').fill('2');
 const saved=page.waitForResponse(r=>r.url().endsWith('/api.php')&&r.request().postData()?.includes('action=save_llm_usage_rates'));await root.getByRole('button',{name:'Сохранить тарифы'}).click();expect((await(await saved).json()).success).toBe(true);
 fixture('meter');await root.locator('#usage-refresh').click();await expect(root.locator('#usage-rows tr').filter({hasText:s.model}).filter({hasText:'USD'})).toContainText('2 USD');await expect(root.locator('#usage-rows tr').filter({hasText:s.model}).filter({hasText:'Нет тарифа'})).toHaveCount(1);
 await page.setViewportSize({width:390,height:844});expect(await root.evaluate(el=>el.getBoundingClientRect().width<=innerWidth+1)).toBe(true);
 const dir=path.resolve(__dirname,'../../docs/tests/MLP-420-422');fs.mkdirSync(dir,{recursive:true});await root.screenshot({path:path.join(dir,info.project.name+'-usage-mobile.png')});
});
test('broadcast emotions send once, consume SSE events, suppress replay, expire and respect personal and reduced-motion settings',async({page},info)=>{
 await login(page,'reader');await page.goto(base+'/');await necessary(page);await page.locator('[data-privacy-media]').click();await expect(page.locator('.video-content iframe')).toBeVisible();
 const stage=page.locator('.video-stage');await stage.getByRole('button',{name:'Эмоции к эфиру',exact:true}).click();
 const fullscreen=stage.getByRole('button',{name:'На весь экран с эмоциями'});if(await fullscreen.isVisible()){await fullscreen.click();await expect.poll(()=>page.evaluate(()=>!!document.fullscreenElement?.classList.contains('video-stage'))).toBe(true);await page.evaluate(()=>document.exitFullscreen());}
 const sent=page.waitForResponse(r=>r.url().endsWith('/api.php')&&r.request().postData()?.includes('action=send_stream_reaction'));await stage.getByRole('button',{name:'Огонь',exact:true}).click();const out=await(await sent).json();expect(out.success).toBe(true);await expect(stage.locator('.stream-emotion')).toHaveCount(1);
 expect((await post(page,'send_stream_reaction',{reaction:'heart'})).success).toBe(false);await page.evaluate(data=>window.fixtureStreamEvent(data),out.data.event);await expect(stage.locator('.stream-emotion')).toHaveCount(1);await expect(stage.locator('.stream-emotion')).toHaveCount(0,{timeout:4000});
 await page.emulateMedia({reducedMotion:'reduce'});await page.evaluate(()=>window.fixtureStreamEvent({type:'stream_reaction',event_id:'sr-90001',reaction:'heart',expires_at:Date.now()+7000}));await expect(stage.locator('.stream-emotion')).toHaveClass(/reduced-motion/);
 await stage.locator('.stream-emotions-enabled').uncheck();await expect(stage.locator('.stream-emotion')).toHaveCount(0);await page.evaluate(()=>window.fixtureStreamEvent({type:'stream_reaction',event_id:'sr-90002',reaction:'heart',expires_at:Date.now()+7000}));await expect(stage.locator('.stream-emotion')).toHaveCount(0);
 await page.reload();await stage.getByRole('button',{name:'Эмоции к эфиру',exact:true}).click();await expect(stage.locator('.stream-emotions-enabled')).not.toBeChecked();await stage.locator('.stream-emotions-enabled').check();
 await page.evaluate(()=>window.fixtureStreamEvent({type:'stream_reaction',event_id:'sr-90003',reaction:'heart',expires_at:Date.now()-1000}));await expect(stage.locator('.stream-emotion')).toHaveCount(0);
 fixture('ban');expect((await post(page,'send_stream_reaction',{reaction:'heart'})).success).toBe(false);
 await page.setViewportSize({width:390,height:844});expect(await stage.locator('.stream-reaction-panel').evaluate(el=>el.getBoundingClientRect().right<=innerWidth)).toBe(true);
 const dir=path.join(root,'docs/tests/MLP-420-422');fs.mkdirSync(dir,{recursive:true});await page.screenshot({path:path.join(dir,info.project.name+'-player-mobile.png'),fullPage:true});
});
test('Centrifugo publications reach the same player overlay and never become chat messages',async({page})=>{
 await page.addInitScript(()=>{
  window.Centrifuge=class{on(){}connect(){}disconnect(){}newSubscription(){return{on(name,fn){if(name==='publication')window.fixturePublication=fn;},subscribe(){}};}};
  document.addEventListener('DOMContentLoaded',()=>{window.chatConfig={driver:'centrifugo',centrifugo:{url:'ws://fixture',token:'fixture'}};});
 });
 await login(page,'reader');await page.goto(base+'/');await necessary(page);await page.locator('[data-privacy-media]').click();
 await expect(page.locator('.video-content iframe')).toBeVisible();
 await expect.poll(()=>page.evaluate(()=>typeof window.fixturePublication)).toBe('function');
 const before=await page.locator('.chat-message').count();
 await page.evaluate(()=>window.fixturePublication({data:{type:'stream_reaction',event_id:'sr-90004',reaction:'clown',expires_at:Date.now()+7000}}));
 await expect(page.locator('.stream-emotion')).toHaveText('🤡');expect(await page.locator('.chat-message').count()).toBe(before);
});
test('actual SSE delivers a newly accepted reaction without chat history replay',async({page})=>{
 await login(page,'reader');await page.goto(base+'/');await necessary(page);
 const controller=new AbortController();const timer=setTimeout(()=>controller.abort(),12000);let reader;
 try{
  const response=await fetch(base+'/chat_stream.php',{signal:controller.signal});reader=response.body.getReader();await reader.read();
  const out=await post(page,'send_stream_reaction',{reaction:'heart'});expect(out.success).toBe(true);
  let text='';while(!text.includes('event: stream_reaction')){const part=await reader.read();if(part.done)break;text+=new TextDecoder().decode(part.value);}
  expect(text).toContain('event: stream_reaction');expect(text).toContain(out.data.event.event_id);
 }finally{clearTimeout(timer);controller.abort();try{await reader?.cancel();}catch(error){}}
});
