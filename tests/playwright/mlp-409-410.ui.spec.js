const {test,expect}=require('@playwright/test');
const {execFileSync}=require('child_process'),fs=require('fs'),path=require('path');
const root=path.resolve(__dirname,'../..'),state=path.join(root,'docs/private/mlp409-local.json'),base=process.env.MLP_BASE_URL;
const fixture=action=>execFileSync('docker',['exec','mlp375-php-1','php','tests/playwright/mlp-409-410-fixture.php',action],{cwd:root});
test.use({serviceWorkers:'block',viewport:{width:1440,height:1000},launchOptions:{args:['--no-proxy-server'],firefoxUserPrefs:{'network.proxy.type':0}}});
async function login(page,s,role){expect((await(await page.request.post(base+'/api.php',{form:{action:'login',username:s[role+'Login'],password:s.password}})).json()).success).toBe(true);}
function local(){if(!base||!['localhost','127.0.0.1'].includes(new URL(base).hostname))throw Error('Isolated localhost required');}
test('pack attribution admin editing, safe public link and authorization',async({page},info)=>{
 local();try{
  fixture('setup');const s=JSON.parse(fs.readFileSync(state,'utf8'));
  await page.route('**/chat_stream.php*',r=>r.abort());await page.route('https://**',r=>r.abort());await login(page,s,'admin');
  await page.goto(base+'/dashboard/#tab-stickers',{waitUntil:'domcontentloaded'});
  const create=page.locator('#create-pack-form');
  await create.locator('[name=code]').fill('it409_new_'+s.suffix);await create.locator('[name=name]').fill('Created attribution');
  await create.locator('[name=author_name]').fill('Initial artist');await create.locator('[name=author_url]').fill('https://example.org/initial');
  const made=page.waitForResponse(r=>r.url().endsWith('/api.php')&&r.request().postData()?.includes('create_pack'));await create.locator('button[type=submit]').click();expect((await(await made).json()).success).toBe(true);
  const item=page.locator('.pack-item').filter({hasText:'Created attribution'});await expect(item).toContainText('Initial artist');
  await item.locator('.edit-pack-btn').click();await expect(page.locator('#edit_pack_author_name')).toHaveValue('Initial artist');
  await page.locator('#edit_pack_author_name').fill('Updated <artist>');await page.locator('#edit_pack_author_url').fill('https://example.org/updated?a=1&b=2');
  const edited=page.waitForResponse(r=>r.url().endsWith('/api.php')&&r.request().postData()?.includes('update_pack'));await page.locator('#edit-pack-form button[type=submit]').click();expect((await(await edited).json()).success).toBe(true);
  await expect(item).toContainText('Updated <artist>');await page.reload({waitUntil:'domcontentloaded'});await expect(item).toContainText('Updated <artist>');
  const invalid=await page.evaluate(async id=>await(await fetch('/api.php',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:new URLSearchParams({action:'update_pack',id,code:'unused',name:'Bad link',author_url:'javascript:alert(1)',csrf_token:window.csrfToken})})).json(),s.pack);expect(invalid.success).toBe(false);
  await page.context().clearCookies();await login(page,s,'reader');await page.goto(base+'/chat_popup.php',{waitUntil:'domcontentloaded'});
  const denied=await page.evaluate(async id=>await(await fetch('/api.php',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:new URLSearchParams({action:'update_pack',id,code:'unused',name:'Denied',author_name:'Denied',csrf_token:window.csrfToken})})).json(),s.pack);expect(denied.success).toBe(false);
  await page.locator('#sticker-btn').click();await page.locator(`.sticker-tab-btn[data-pack-id="${s.pack}"]`).click();
  await expect(page.locator('#sticker-grid .picker-sticker')).toBeVisible();
  await expect.poll(()=>page.locator('#sticker-grid .picker-sticker').evaluate(el=>el.complete&&el.naturalWidth>0)).toBe(true);
  const credit=page.locator('#sticker-pack-credit');await expect(credit).toHaveText('Автор: Author <safe>');await expect(credit.locator('a')).toHaveAttribute('href','https://example.org/artist');await expect(credit.locator('a')).toHaveAttribute('rel','noopener noreferrer');expect(await credit.locator('safe').count()).toBe(0);
  await page.locator(`.sticker-tab-btn[data-pack-id="${s.plain}"]`).click();await expect(credit).toBeHidden();
  await page.locator(`.sticker-tab-btn[data-pack-id="${s.pack}"]`).click();
  const dir=path.join(root,'docs/tests/MLP-409-410');fs.mkdirSync(dir,{recursive:true});await expect(page.locator('#sticker-picker')).toHaveCSS('opacity','1');await page.screenshot({path:path.join(dir,info.project.name+'-picker.png')});
  await page.goto(base+'/',{waitUntil:'domcontentloaded'});await page.setViewportSize({width:390,height:844});await page.locator('#sticker-btn').click();await page.locator(`.sticker-tab-btn[data-pack-id="${s.pack}"]`).click();await expect(page.locator('#sticker-pack-credit')).toHaveText('Автор: Author <safe>');await expect(page.locator('#sticker-pack-credit')).toBeVisible();await expect(page.locator('#sticker-picker')).toHaveCSS('opacity','1');await page.screenshot({path:path.join(dir,info.project.name+'-picker-mobile.png')});
 }finally{if(fs.existsSync(state))fixture('cleanup');}
});
test('profile font scale applies to message, quote, author, time and input in both chat layouts',async({page},info)=>{
 local();try{
  fixture('setup');const s=JSON.parse(fs.readFileSync(state,'utf8'));await page.route('**/chat_stream.php*',r=>r.abort());await page.route('https://**',r=>r.abort());await login(page,s,'reader');
  for(const url of ['/chat_popup.php','/']){
   await page.goto(base+url,{waitUntil:'domcontentloaded'});const message=page.locator(`.chat-message[data-id="${s.message}"]`);await expect(message).toBeVisible();
   await page.evaluate(()=>{window.openProfileModal();window.switchProfileTab('visual');});
   const slider=page.locator('#ajax-profile-form input[name=font_scale]');
   const set=async n=>{await slider.evaluate((el,value)=>{el.value=String(value);el.dispatchEvent(new Event('input',{bubbles:true}));},n);await expect.poll(()=>page.locator('#chat-input').evaluate(el=>parseFloat(getComputedStyle(el).fontSize))).toBeCloseTo(16*n/100,2);};
   const sizes=async()=>page.evaluate(id=>{const msg=document.querySelector(`.chat-message[data-id="${id}"]`);return ['.chat-text','.username','.timestamp','.md-quote','.md-code'].map(selector=>parseFloat(getComputedStyle(msg.querySelector(selector)).fontSize)).concat(parseFloat(getComputedStyle(document.querySelector('#chat-input')).fontSize));},s.message);
   await set(100);const standard=await sizes();await set(150);const large=await sizes();await set(50);const small=await sizes();
   for(let i=0;i<standard.length;i++){expect(large[i]/standard[i],JSON.stringify({url,i,standard,large,small})).toBeCloseTo(1.5,2);expect(small[i]/standard[i]).toBeCloseTo(.5,2);}
   await set(150);let saves=0;const countSave=request=>{if(request.url().endsWith('/api.php')&&request.postData()?.includes('update_profile'))saves++;};page.on('request',countSave);
   await Promise.all([page.waitForNavigation({waitUntil:'domcontentloaded'}),page.locator('#ajax-profile-form button[type=submit]').click()]);
   page.off('request',countSave);expect(saves).toBe(1);
   await expect.poll(()=>page.evaluate(()=>parseFloat(getComputedStyle(document.documentElement).fontSize))).toBe(24);
   const dir=path.join(root,'docs/tests/MLP-409-410');fs.mkdirSync(dir,{recursive:true});await page.setViewportSize({width:390,height:844});
   if(url==='/'){await expect(page.locator('#chat-mobile-input')).toHaveCSS('font-size','24px');}
   await message.scrollIntoViewIfNeeded();await page.screenshot({path:path.join(dir,info.project.name+(url==='/'?'-embedded-mobile.png':'-popup-mobile.png'))});await page.setViewportSize({width:1440,height:1000});
  }
 }finally{if(fs.existsSync(state))fixture('cleanup');}
});
