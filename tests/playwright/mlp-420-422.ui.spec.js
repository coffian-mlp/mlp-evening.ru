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
 await page.clock.install();
 await login(page,'reader');await page.goto(base+'/');await necessary(page);await page.locator('[data-privacy-media]').click();await expect(page.locator('.video-content iframe')).toBeVisible();
 const stage=page.locator('.video-stage');await stage.getByRole('button',{name:'Эмоции к эфиру',exact:true}).click();
 const fullscreen=stage.getByRole('button',{name:'На весь экран с эмоциями'});if(await fullscreen.isVisible()){await fullscreen.click();await expect.poll(()=>page.evaluate(()=>!!document.fullscreenElement?.classList.contains('video-stage'))).toBe(true);await page.evaluate(()=>document.exitFullscreen());}
 await page.clock.pauseAt(await page.evaluate(()=>Date.now()+1000));
 const sent=page.waitForResponse(r=>r.url().endsWith('/api.php')&&r.request().postData()?.includes('action=send_stream_reaction'));await stage.getByRole('button',{name:'Огонь',exact:true}).click();const out=await(await sent).json();expect(out.success).toBe(true);await expect(stage.locator('.stream-emotion')).toHaveCount(1);
 expect((await post(page,'send_stream_reaction',{reaction:'heart'})).success).toBe(false);await page.evaluate(data=>window.fixtureStreamEvent(data),out.data.event);await expect(stage.locator('.stream-emotion')).toHaveCount(1);
 const duration=await stage.locator('.stream-emotion').evaluate(el=>parseFloat(getComputedStyle(el).animationDuration));expect(duration).toBeGreaterThanOrEqual(5);expect(duration).toBeLessThanOrEqual(7);
 const opacity=await stage.locator('.stream-emotion').evaluate(el=>{el.getAnimations().forEach(animation=>{animation.pause();animation.currentTime=1500;});return Number(getComputedStyle(el).opacity);});expect(opacity).toBeGreaterThan(0.95);
 const reactionDir=path.join(root,'docs/tests/MLP-429');fs.mkdirSync(reactionDir,{recursive:true});await stage.screenshot({path:path.join(reactionDir,info.project.name+'-reaction.png')});
 await page.clock.runFor(2100);await expect(stage.locator('.stream-emotion')).toHaveCount(1);await expect(stage.getByRole('button',{name:'Огонь',exact:true})).toBeDisabled();
 await page.clock.runFor(900);await expect(stage.locator('.stream-emotion')).toHaveCount(1);
 await page.clock.runFor(500);await expect(stage.getByRole('button',{name:'Огонь',exact:true})).toBeEnabled();await expect(stage.locator('.stream-reaction-hint')).toHaveText('Одна эмоция раз в 3 секунды');
 await expect(stage.locator('.stream-emotion')).toHaveCount(1);await page.clock.runFor(3700);await expect(stage.locator('.stream-emotion')).toHaveCount(0);
 await page.emulateMedia({reducedMotion:'reduce'});await page.evaluate(()=>window.fixtureStreamEvent({type:'stream_reaction',event_id:'sr-90001',reaction:'heart',expires_at:Date.now()+7000}));await expect(stage.locator('.stream-emotion')).toHaveClass(/reduced-motion/);
 const reducedDuration=await stage.locator('.stream-emotion').evaluate(el=>parseFloat(getComputedStyle(el).animationDuration));expect(reducedDuration).toBeGreaterThanOrEqual(5);expect(reducedDuration).toBeLessThanOrEqual(7);
 await stage.locator('.stream-emotions-enabled').uncheck();await expect(stage.locator('.stream-emotion')).toHaveCount(0);await page.evaluate(()=>window.fixtureStreamEvent({type:'stream_reaction',event_id:'sr-90002',reaction:'heart',expires_at:Date.now()+7000}));await expect(stage.locator('.stream-emotion')).toHaveCount(0);
 await page.clock.resume();await page.reload();await stage.getByRole('button',{name:'Эмоции к эфиру',exact:true}).click();await expect(stage.locator('.stream-emotions-enabled')).not.toBeChecked();await stage.locator('.stream-emotions-enabled').check();
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

async function openEmotionPlayer(page){
 await page.goto(base+'/',{waitUntil:'domcontentloaded'});await necessary(page);await page.locator('[data-privacy-media]').click();await expect(page.locator('.video-content iframe')).toBeVisible();
}
async function windEvent(page,id,random=.5){
 await page.evaluate(({id,random})=>{const original=Math.random;Math.random=()=>random;try{window.fixtureStreamEvent({type:'stream_reaction',event_id:'sr-'+id,reaction:'heart',expires_at:Date.now()+8000});}finally{Math.random=original;}},{id,random});
}
async function particleFrame(particle,progress){
 return particle.evaluate((el,p)=>{const animation=el.getAnimations()[0];animation.pause();animation.currentTime=animation.effect.getTiming().duration*p;const b=el.getBoundingClientRect(),parent=el.parentElement.getBoundingClientRect();return{x:b.x-parent.x,y:b.y-parent.y,right:b.right-parent.x,bottom:b.bottom-parent.y,width:parent.width,height:parent.height,opacity:Number(getComputedStyle(el).opacity),duration:animation.effect.getTiming().duration};},progress);
}
test('MLP-442 emotions start in the left third, drift right and bounce down while fading',async({page},info)=>{
 await page.clock.install();await openEmotionPlayer(page);await page.clock.pauseAt(await page.evaluate(()=>Date.now()+100));
 const dir=path.join(root,'docs/tests/MLP-442');fs.mkdirSync(dir,{recursive:true});
 for(const [index,width]of[1280,390,320].entries()){
  await page.setViewportSize({width,height:850});await windEvent(page,442000+index);const particle=page.locator('.stream-emotion').last();
  const start=await particleFrame(particle,0),rising=await particleFrame(particle,.35),top=await particleFrame(particle,.68),bounce=await particleFrame(particle,.82),end=await particleFrame(particle,.98);
  expect(start.x).toBeGreaterThanOrEqual(0);expect(start.right).toBeLessThanOrEqual(start.width/3);expect(start.duration).toBe(6000);
  expect(rising.x).toBeGreaterThan(start.x);expect(rising.y).toBeLessThan(start.y);expect(top.x).toBeGreaterThan(rising.x);expect(top.y).toBeLessThan(rising.y);expect(top.y).toBeGreaterThanOrEqual(0);expect(top.y).toBeLessThan(36);
  expect(bounce.y).toBeGreaterThan(top.y+2);expect(end.y).toBeGreaterThan(bounce.y);expect(end.x).toBeGreaterThan(start.width*2/3);expect(end.right).toBeLessThanOrEqual(end.width);expect(end.opacity).toBeLessThan(.2);
  for(const frame of[start,rising,top,bounce,end]){expect(frame.y).toBeGreaterThanOrEqual(0);expect(frame.bottom).toBeLessThanOrEqual(frame.height);}
  await particleFrame(particle,.68);await page.locator('.video-stage').screenshot({path:path.join(dir,info.project.name+'-top-'+width+'.png')});await page.evaluate(()=>document.querySelector('.stream-emotions').replaceChildren());
 }
});
test('MLP-442 each emotion has its own five-to-seven-second lifetime and the crowd cap remains twelve',async({page})=>{
 await page.clock.install();await openEmotionPlayer(page);await page.clock.pauseAt(await page.evaluate(()=>Date.now()+100));await windEvent(page,442100,0);await windEvent(page,442101,1);
 expect(await page.locator('.stream-emotion').evaluateAll(nodes=>nodes.map(el=>parseFloat(getComputedStyle(el).animationDuration)))).toEqual([5,7]);
 await page.clock.runFor(4990);await expect(page.locator('.stream-emotion')).toHaveCount(2);await page.clock.runFor(250);await expect(page.locator('.stream-emotion')).toHaveCount(1);expect(await page.locator('.stream-emotion').evaluate(el=>getComputedStyle(el).animationDuration)).toBe('7s');
 await page.clock.runFor(2000);await expect(page.locator('.stream-emotion')).toHaveCount(0);
 for(let i=0;i<20;i++)await windEvent(page,442200+i);await expect(page.locator('.stream-emotion')).toHaveCount(12);
 await page.getByRole('button',{name:'Эмоции к эфиру',exact:true}).click();await page.locator('.stream-emotions-enabled').uncheck();await expect(page.locator('.stream-emotion')).toHaveCount(0);
});
test('MLP-442 reduced motion only fades and active flights adapt to resizing and fullscreen',async({page})=>{
 await page.clock.install();await page.emulateMedia({reducedMotion:'reduce'});await openEmotionPlayer(page);await page.clock.pauseAt(await page.evaluate(()=>Date.now()+100));await windEvent(page,442300);
 const still=page.locator('.stream-emotion');await expect(still).toHaveClass(/reduced-motion/);const first=await particleFrame(still,.2),last=await particleFrame(still,.9);expect(first.duration).toBe(6000);expect(last.x).toBeCloseTo(first.x,1);expect(last.y).toBeCloseTo(first.y,1);expect(last.opacity).toBeLessThan(first.opacity);
 await page.evaluate(()=>document.querySelector('.stream-emotions').replaceChildren());await page.emulateMedia({reducedMotion:'no-preference'});await page.setViewportSize({width:320,height:850});await windEvent(page,442301);
 const particle=page.locator('.stream-emotion');const small=await particleFrame(particle,.68);await page.setViewportSize({width:1280,height:850});await page.clock.runFor(100);await expect.poll(async()=>Number((await particleFrame(particle,.68)).height)).toBeGreaterThan(small.height);
 const large=await particleFrame(particle,.68);expect(large.y).toBeGreaterThanOrEqual(0);expect(large.right).toBeLessThanOrEqual(large.width);
 const fullscreen=page.locator('.stream-fullscreen');if(await fullscreen.isVisible()){await fullscreen.click();await page.clock.runFor(100);await expect.poll(()=>page.evaluate(()=>!!document.fullscreenElement)).toBe(true);const frame=await particleFrame(particle,.68);expect(frame.y).toBeGreaterThanOrEqual(0);expect(frame.right).toBeLessThanOrEqual(frame.width);await fullscreen.click();}
});
test('MLP-443 initial flight follows a pronounced arc instead of a straight diagonal',async({page})=>{
 await page.clock.install();await openEmotionPlayer(page);await page.clock.pauseAt(await page.evaluate(()=>Date.now()+100));
 for(const [index,width]of[1280,320].entries()){
  await page.setViewportSize({width,height:850});await windEvent(page,443000+index);const particle=page.locator('.stream-emotion').last();
  const start=await particleFrame(particle,0),early=await particleFrame(particle,.17),middle=await particleFrame(particle,.34),late=await particleFrame(particle,.51),top=await particleFrame(particle,.68);
  const horizontal=(middle.x-start.x)/(top.x-start.x),vertical=(start.y-middle.y)/(start.y-top.y);
  expect(vertical-horizontal).toBeGreaterThan(.25);
  const initialSlope=(start.y-early.y)/(early.x-start.x),finalSlope=(late.y-top.y)/(top.x-late.x);expect(initialSlope).toBeGreaterThan(finalSlope*3);
  let previous=start;for(let i=1;i<=32;i++){const frame=await particleFrame(particle,.68*i/32);expect(frame.x).toBeGreaterThan(previous.x);expect(frame.y).toBeLessThan(previous.y);expect(frame.x).toBeGreaterThanOrEqual(0);expect(frame.y).toBeGreaterThanOrEqual(0);expect(frame.right).toBeLessThanOrEqual(frame.width);previous=frame;}
  await page.evaluate(()=>document.querySelector('.stream-emotions').replaceChildren());
 }
});
