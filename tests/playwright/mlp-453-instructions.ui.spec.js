const {test,expect}=require('@playwright/test');
const {execFileSync}=require('child_process'),fs=require('fs'),path=require('path');
const {BASE,login,post}=require('./mlp-360-helpers');
const root=path.resolve(__dirname,'../..'),state=path.join(root,'docs/private/mlp453-local.json');
const fixture=mode=>execFileSync('docker',['exec','mlp375-php-1','php','tests/playwright/mlp-453-instructions-fixture.php',mode],{cwd:root,encoding:'utf8'});
test.use({serviceWorkers:'block',launchOptions:{args:['--no-proxy-server'],firefoxUserPrefs:{'network.proxy.type':0}}});
test.beforeEach(async({page})=>{
 if(!BASE||!['localhost','127.0.0.1'].includes(new URL(BASE).hostname))throw Error('Isolated localhost required');
 fixture('setup');await page.route('https://**',r=>r.abort());
});
test.afterEach(()=>{if(fs.existsSync(state))fixture('cleanup');});
test('MLP-453 additional instructions save independently, render safely and can be cleared',async({page},info)=>{
 const data=JSON.parse(fs.readFileSync(state,'utf8'));await login(page.request,data.users.admin);
 await page.goto(BASE+'/dashboard/#tab-bot',{waitUntil:'domcontentloaded'});
 if(await page.locator('#privacy-panel').isVisible())await page.locator('[data-privacy-necessary]').click();
 const extra=page.getByLabel('Дополнительные инструкции Лире',{exact:true});
 const base=page.locator('[name="ai_system_prompt"]');
 await expect(extra).toBeVisible();await expect(extra).toHaveValue('');await expect(base).toHaveValue('Base persona MLP453');
 expect(await extra.evaluate(el=>el.closest('.form-group').previousElementSibling.querySelector('textarea').name)).toBe('ai_system_prompt');
 const text='Тема: Ночь кошмаров.\n<img src=x onerror="window.mlp453Injected=true">';
 const save=async()=>{const response=page.waitForResponse(r=>r.url().endsWith('/api.php')&&r.request().postData()?.includes('update_settings'));await extra.locator('xpath=ancestor::form').locator('button[type="submit"]').click();expect((await(await response).json()).success).toBe(true);await page.reload({waitUntil:'domcontentloaded'});};
 await extra.fill(text);await save();await expect(extra).toHaveValue(text);await expect(base).toHaveValue('Base persona MLP453');
 expect(await page.evaluate(()=>window.mlp453Injected)).toBeUndefined();
 await page.setViewportSize({width:390,height:844});await extra.scrollIntoViewIfNeeded();await expect(extra).toBeVisible();
 expect(await extra.evaluate(el=>el.getBoundingClientRect().right<=innerWidth+1)).toBe(true);
 const dir=path.join(root,'docs/tests/MLP-453');fs.mkdirSync(dir,{recursive:true});await page.screenshot({path:path.join(dir,info.project.name+'-settings.png')});
 await extra.fill('');await save();await expect(extra).toHaveValue('');await expect(base).toHaveValue('Base persona MLP453');
});
test('MLP-453 ordinary user cannot change additional instructions',async({page})=>{
 const data=JSON.parse(fs.readFileSync(state,'utf8'));const csrf=await login(page.request,data.users.user);
 expect((await post(page.request,csrf,{action:'update_settings',ai_additional_instructions:'Unauthorized'})).success).toBe(false);
});
