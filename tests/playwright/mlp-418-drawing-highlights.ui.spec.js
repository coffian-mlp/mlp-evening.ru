const {test,expect}=require('@playwright/test');
const {execFileSync}=require('child_process'),fs=require('fs'),path=require('path');
const root=path.resolve(__dirname,'../..'),base=process.env.MLP_BASE_URL,state=path.join(root,'docs/private/mlp400-local.json');
const fixture=action=>execFileSync('docker',['exec','mlp375-php-1','php','tests/playwright/mlp-400-drawing-fixture.php',action],{cwd:root,encoding:'utf8'});
const inspect=()=>JSON.parse(fixture('inspect'));
test.use({serviceWorkers:'block',viewport:{width:1920,height:1080},launchOptions:{args:['--no-proxy-server'],firefoxUserPrefs:{'network.proxy.type':0}}});
test.beforeEach(async({page})=>{
 if(!base||!['localhost','127.0.0.1'].includes(new URL(base).hostname))throw Error('Isolated localhost required');
 await page.route('https://**',r=>r.abort());await page.route('**/chat_stream.php*',r=>r.abort());
 fixture('setup');const s=JSON.parse(fs.readFileSync(state,'utf8'));
 expect((await(await page.request.post(base+'/api.php',{form:{action:'login',username:s.ownerLogin,password:s.password}})).json()).success).toBe(true);
 await page.goto(base+'/chat_popup.php',{waitUntil:'domcontentloaded'});
 if(await page.locator('#privacy-panel').isVisible())await page.locator('[data-privacy-necessary]').click();
});
test.afterEach(()=>{if(fs.existsSync(state))fixture('cleanup');});
async function send(page,text,quote){
 if(quote){const button=page.locator(`.chat-message[data-id="${quote}"] .quote-btn`);await button.click({force:true});}
 await page.locator('#chat-input').fill(text);
 const response=page.waitForResponse(r=>r.url().endsWith('/api.php')&&r.request().postData()?.includes('action=send_message'));
 await page.locator('#chat-form').evaluate(form=>form.requestSubmit());const result=await(await response).json();expect(result.success,result.message).toBe(true);
}
test('ordinary quoted conversation never opens a phantom drawing draft',async({page})=>{
 const s=JSON.parse(fs.readFileSync(state,'utf8'));
 await send(page,'А ты приняла согласие на обработку данных?',s.greeting);fixture('tick');await page.reload({waitUntil:'domcontentloaded'});
 expect(inspect().interactions).toHaveLength(0);
 expect(inspect().jobs.filter(x=>x.handler==='command_interaction_reply')).toHaveLength(0);
 await expect(page.locator('.command-interaction')).toHaveCount(0);
});
test('chat drawing collects wishes for a minute, starts itself, and automatic drawing has no buttons',async({page})=>{
 test.setTimeout(60000);
 await send(page,'/нарисуйчат');await expect.poll(()=>{fixture('tick');return inspect().messages.some(x=>x.text.includes('work_drawchat_'));}).toBe(true);await page.reload({waitUntil:'domcontentloaded'});
 const announcement=inspect().messages.find(x=>x.text.includes('work_drawchat_'));
 expect(announcement).toBeTruthy();await expect(page.locator(`.chat-message[data-id="${announcement.id}"]`)).toContainText('минуту');
 await expect(page.locator('.command-interaction')).toHaveCount(0);expect(inspect().drawings).toHaveLength(0);
 await send(page,'Добавь воздушный шар',announcement.id);fixture('tick');
 expect(inspect().interactions).toHaveLength(0);expect(inspect().drawings).toHaveLength(0);
 fixture('advance');fixture('tick');await page.reload({waitUntil:'domcontentloaded'});
 expect(inspect().drawings).toHaveLength(1);await expect(page.locator('.command-interaction')).toHaveCount(0);
 await expect(page.locator(`.chat-message[data-id="${inspect().drawings[0]}"] .chat-img`)).toBeVisible();
 fixture('auto');await page.reload({waitUntil:'domcontentloaded'});
 expect(inspect().drawings).toHaveLength(2);await expect(page.locator('.command-interaction')).toHaveCount(0);
});
test('mention, reply and quote-jump highlights stay subtle in both themes',async({page},info)=>{
 const s=JSON.parse(fs.readFileSync(state,'utf8'));
 const mentioned=page.locator(`.chat-message[data-id="${s.mentioned}"]`),quoted=page.locator(`.chat-message[data-id="${s.quoted}"]`);
 await expect(mentioned).toHaveClass(/message-mentioned/);await expect(quoted).toHaveClass(/message-quoted/);
 for(const name of ['celestia','luna']){
  const switcher=page.locator('[data-theme-toggle]:visible');
  if(await switcher.count())await switcher.click();else await page.locator('#chat-menu-btn').click();
  await page.locator(`[data-theme-choice="${name}"]:visible`).click();
  await expect(page.locator('html')).toHaveAttribute('data-theme',name);
  await page.mouse.move(0,0);
  await expect(mentioned).toHaveCSS('background-color',name==='celestia'?'rgba(121, 80, 152, 0.08)':'rgba(209, 181, 241, 0.12)');
  await expect(mentioned).not.toHaveCSS('border-left-color','rgb(255, 235, 59)');
  await quoted.hover();await expect(quoted).toHaveCSS('background-color',name==='celestia'?'rgba(126, 108, 154, 0.1)':'rgba(149, 163, 216, 0.15)');
  await mentioned.hover();await expect(mentioned).toHaveCSS('background-color',name==='celestia'?'rgba(121, 80, 152, 0.13)':'rgba(209, 181, 241, 0.18)');
  await quoted.locator('.quote-link-btn').click();
  await expect(page.locator('.highlight-message')).toHaveCount(1);
  await expect(page.locator('.highlight-message')).toHaveCount(0,{timeout:5000});
  await page.mouse.move(0,0);
  const dir=path.join(root,'docs/tests/MLP-418');fs.mkdirSync(dir,{recursive:true});
  await page.screenshot({path:path.join(dir,`${info.project.name}-${name}-fullhd.png`)});
 }
});
