const {test,expect}=require('@playwright/test');
const {execFileSync}=require('child_process'),fs=require('fs'),path=require('path');
const {BASE,login,post}=require('./mlp-360-helpers');
const root=path.resolve(__dirname,'../..'),state=path.join(root,'docs/private/mlp425-local.json');
const fixture=mode=>execFileSync('docker',['exec','mlp375-php-1','php','tests/playwright/mlp-425-reasoning-fixture.php',mode],{cwd:root,encoding:'utf8'});
test.use({serviceWorkers:'block',launchOptions:{args:['--no-proxy-server'],firefoxUserPrefs:{'network.proxy.type':0}}});
test.beforeEach(async({page})=>{
 if(!BASE||!['localhost','127.0.0.1'].includes(new URL(BASE).hostname))throw Error('Isolated localhost required');
 fixture('setup');await page.route('https://**',r=>r.abort());
});
test.afterEach(()=>{if(fs.existsSync(state))fixture('cleanup');});
test('MLP-425 administrator saves reasoning, reloads it, and invalid input preserves saved value',async({page},info)=>{
 const data=JSON.parse(fs.readFileSync(state,'utf8'));const csrf=await login(page.request,data.users.admin);
 await page.goto(BASE+'/dashboard/#tab-bot',{waitUntil:'domcontentloaded'});
 if(await page.locator('#privacy-panel').isVisible())await page.locator('[data-privacy-necessary]').click();
 const select=page.getByLabel('Уровень рассуждений RouterAI');
 const widget=select.locator('..');
 await expect(widget.locator('.select-selected')).toBeVisible();await expect(select).toHaveValue('auto');
 await widget.locator('.select-selected').click();await widget.locator('.select-items').getByText('Низкий — рекомендуется',{exact:true}).click();
 const form=select.locator('xpath=ancestor::form');
 expect(await form.locator(':invalid').evaluateAll(nodes=>nodes.map(el=>({name:el.name,error:el.validationMessage}))),'fixture form fields satisfy browser validation').toEqual([]);
 const saved=page.waitForResponse(r=>r.url().endsWith('/api.php') && r.request().method()==='POST' && r.request().postData()?.includes('update_settings'));
 await form.locator('button[type="submit"]').click();expect((await(await saved).json()).success).toBe(true);
 await page.reload({waitUntil:'domcontentloaded'});await expect(select).toHaveValue('low');
 const budget=await page.locator('[name="ai_max_tokens"]').inputValue();
 expect((await post(page.request,csrf,{action:'update_settings',ai_routerai_reasoning_effort:'invalid'})).success).toBe(true);
 await page.reload({waitUntil:'domcontentloaded'});await expect(select).toHaveValue('low');await expect(page.locator('[name="ai_max_tokens"]')).toHaveValue(budget);
 await page.setViewportSize({width:390,height:844});await widget.scrollIntoViewIfNeeded();await expect(widget.locator('.select-selected')).toBeVisible();
 expect(await widget.evaluate(el=>el.getBoundingClientRect().right<=innerWidth+1)).toBe(true);
 const dir=path.join(root,'docs/tests/MLP-425');fs.mkdirSync(dir,{recursive:true});await page.screenshot({path:path.join(dir,info.project.name+'-settings.png')});
});
test('MLP-425 ordinary user cannot change reasoning',async({page})=>{
 const data=JSON.parse(fs.readFileSync(state,'utf8'));const csrf=await login(page.request,data.users.user);
 const result=await post(page.request,csrf,{action:'update_settings',ai_routerai_reasoning_effort:'max'});
 expect(result.success).toBe(false);expect(result.message).toBe('Access Denied');
});
