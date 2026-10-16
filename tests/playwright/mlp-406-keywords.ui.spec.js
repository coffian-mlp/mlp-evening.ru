const {test,expect}=require('@playwright/test');
const {execFileSync}=require('child_process'),fs=require('fs'),path=require('path');
const root=path.resolve(__dirname,'../..'),state=path.join(root,'docs/private/mlp406-local.json'),base=process.env.MLP_BASE_URL;
const fixture=action=>execFileSync('docker',['exec','mlp375-php-1','php','tests/playwright/mlp-406-keywords-fixture.php',action],{cwd:root});
test.use({serviceWorkers:'block',launchOptions:{args:['--no-proxy-server'],firefoxUserPrefs:{'network.proxy.type':0}}});
test('personal keyword settings, validation and live sound delivery',async({page},info)=>{
 if(!base||!['localhost','127.0.0.1'].includes(new URL(base).hostname))throw Error('Localhost required');
 try{
  fixture('setup');const s=JSON.parse(fs.readFileSync(state,'utf8'));
  await page.addInitScript(()=>{
   window.__tones=0;
   window.AudioContext=class {constructor(){this.state='suspended';this.currentTime=0;this.destination={};}resume(){this.state='running';return Promise.resolve();}createOscillator(){return{frequency:{},connect(){},start(){window.__tones++;},stop(){}};}createGain(){return{gain:{setValueAtTime(){},exponentialRampToValueAtTime(){}},connect(){}};}};
   window.EventSource=class {constructor(){window.__sse=this;}addEventListener(){}close(){}};
  });
  await page.route('**/chat_stream.php*',r=>r.abort());
  expect((await(await page.request.post(base+'/api.php',{form:{action:'login',username:s.login,password:s.password}})).json()).success).toBe(true);
  await page.goto(base+'/chat_popup.php',{waitUntil:'domcontentloaded'});
  await page.evaluate(()=>{window.updateChatConnection({...window.chatConfig,driver:'sse'});window.openProfileModal();window.switchProfileTab('system');});
  await expect(page.locator('#profile-keywords-enabled')).not.toBeChecked();
  await page.locator('#profile-keywords-enabled').check();await page.locator('#profile-keywords').fill('Твайлайт, Ёж, ночной вечерок, Твайка');
  const saved=page.waitForResponse(r=>r.url().endsWith('/api.php')&&r.request().postData()?.includes('action=save_user_option'));
  await page.locator('#profile-keywords-save').click();expect((await(await saved).json()).success).toBe(true);
  await page.reload({waitUntil:'domcontentloaded'});
  await page.evaluate(()=>{window.updateChatConnection({...window.chatConfig,driver:'sse'});window.openProfileModal();window.switchProfileTab('system');});
  await expect(page.locator('#profile-keywords-enabled')).toBeChecked();await expect(page.locator('#profile-keywords')).toHaveValue('Твайлайт, Ёж, ночной вечерок, Твайка');
  await page.evaluate(()=>window.ChatKeywordAlerts.notify({id:Number(window.chatConfig.notification_after_id)+1,user_id:999999,message:'Твайлайт'}));expect(await page.evaluate(()=>window.__tones)).toBe(0);
  await page.locator('#profile-keywords-test').click();await expect.poll(()=>page.evaluate(()=>window.__tones)).toBe(2);
  const result=await page.evaluate(async owner=>{
   const wait=()=>new Promise(r=>setTimeout(r,40));let id=Number(window.chatConfig.notification_after_id)+100;
   const send=async(message,extra={})=>{window.__sse.onmessage({data:JSON.stringify({id:++id,user_id:999999,username:'Other',message,created_at:'2026-10-16 12:00:00',...extra})});await wait();return window.__tones;};
   const values=[];
   values.push(await send('Твайлайт',{user_id:owner}));values.push(await send('Твайлайт',{deleted:1}));values.push(await send('Твайлайтовый лунатик'));
   values.push(await send('<blockquote>Твайлайт</blockquote>Новая речь',{raw_message:'&gt; Твайлайт\nНовая речь'}));values.push(await send('<code>Твайлайт</code>',{raw_message:'`Твайлайт`'}));values.push(await send('А мы сейчас говорим о Твайке!'));
   window.__sse.onmessage({data:JSON.stringify({id,user_id:999999,username:'Other',message:'Твайлайт',created_at:'2026-10-16 12:00:00'})});await wait();values.push(window.__tones);
   values.push(await send('Твайлайт')); // cooldown
   const matches=window.ChatKeywordAlerts.matches;
   return {values,matching:[matches('ЕЖ!', ['Ёж']),matches('ночной  вечерок',['ночной вечерок']),matches('лунатик',['Луна']),matches('`Твайлайт`',['Твайлайт']),matches('https://x.test/Твайлайт',['Твайлайт'])]};
  },s.id);
  expect(result.values).toEqual([2,2,2,2,2,4,4,4]);expect(result.matching).toEqual([true,true,false,false,false]);
  await page.locator('#profile-keywords-enabled').uncheck();
  const off=page.waitForResponse(r=>r.url().endsWith('/api.php')&&r.request().postData()?.includes('action=save_user_option'));await page.locator('#profile-keywords-save').click();expect((await(await off).json()).success).toBe(true);
  await page.evaluate(()=>{localStorage.removeItem('mlp.keyword-alert.last.'+window.currentUserId);window.ChatKeywordAlerts.notify({id:Number(window.chatConfig.notification_after_id)+999,user_id:999999,message:'Твайлайт'});});expect(await page.evaluate(()=>window.__tones)).toBe(4);
  const invalid=await page.evaluate(async()=>await(await fetch('/api.php',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:new URLSearchParams({action:'save_user_option',csrf_token:window.csrfToken,key:'chat_keyword_alerts',value:JSON.stringify({enabled:true,words:[]})})})).json());expect(invalid.success).toBe(false);
  const dir=path.join(root,'docs/tests/MLP-406');fs.mkdirSync(dir,{recursive:true});
  await page.screenshot({path:path.join(dir,info.project.name+'-desktop.png')});await page.setViewportSize({width:390,height:844});await page.screenshot({path:path.join(dir,info.project.name+'-mobile.png')});
 }finally{if(fs.existsSync(state))fixture('cleanup');}
});
test('browser permits the actual Web Audio chime after explicit click',async({page})=>{
 if(!base||!['localhost','127.0.0.1'].includes(new URL(base).hostname))throw Error('Localhost required');
 try{
  fixture('setup');const s=JSON.parse(fs.readFileSync(state,'utf8'));
  await page.route('**/chat_stream.php*',r=>r.abort());
  expect((await(await page.request.post(base+'/api.php',{form:{action:'login',username:s.login,password:s.password}})).json()).success).toBe(true);
  await page.goto(base+'/chat_popup.php',{waitUntil:'domcontentloaded'});
  await page.evaluate(()=>{window.openProfileModal();window.switchProfileTab('system');});
  await page.locator('#profile-keywords-test').click();
  await expect(page.locator('#profile-keywords-status')).toHaveText('Так прозвучит уведомление.');
 }finally{if(fs.existsSync(state))fixture('cleanup');}
});
test('Russian noun endings match inside speech without matching derived words',async({page})=>{
 if(!base||!['localhost','127.0.0.1'].includes(new URL(base).hostname))throw Error('Localhost required');
 await page.route('**/chat_stream.php*',r=>r.abort());
 await page.goto(base+'/chat_popup.php',{waitUntil:'domcontentloaded'});
 const cases=[
  ['Мы сейчас обсуждаем Твайлайт Sparkle вместе',['Твайлайт'],true],
  ['А Твайку уже обсудили?',['Твайка'],true],['Мы говорили о Твайке вчера',['Твайка'],true],
  ['С Твайкой всё хорошо',['Твайка'],true],['С Твайкою всё хорошо',['Твайка'],true],
  ['У Твайки новая книга',['Твайка'],true],['Мы вспомнили о Баклажане',['Баклажан'],true],
  ['Сегодня говорим о Твайлайт',['Твайлайт'],true],['Нет Твайлайта',['Твайлайт'],true],
  ['Мы говорили об Искорке',['Искорка'],true],['Смотрели на Луну',['Луна'],true],
  ['Смотрели на лунатиков',['Луна'],false],['Твайлайтовый',['Твайлайт'],false],
  ['Твайкин',['Твайка'],false],['баклажановый',['Баклажан'],false],
  ['Супертвайка',['Твайка'],false],['Твайка123',['Твайка'],false],
  ['Твайка_бот',['Твайка'],false],['Разговор о night',['night'],true],
  ['Разговор о nightly',['night'],false],['Обсудим ночной вечерок завтра',['ночной вечерок'],true],
  ['Обсудим ночного вечерка',['ночной вечерок'],false],
  ['> Мы говорили о Твайке\nНовая реплика',['Твайка'],false],['`Твайку`',['Твайка'],false],
  ['https://example.test/Твайке',['Твайка'],false],['ЕЖ!', ['Ёж'],true],
  ['Мы говорили о ТВАЙКЕ',['твайка'],true],['Очень длинное слово',[''],false],
  ['Мы говорили о любви с любовью',['любовь'],true],['Смотрим ночью',['ночь'],true],
 ];
 const results=await page.evaluate(cases=>cases.map(([text,words])=>window.ChatKeywordAlerts.matches(text,words)),cases);
 expect(results).toEqual(cases.map(c=>c[2]));
});
