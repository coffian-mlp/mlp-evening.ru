const {test,expect}=require('@playwright/test');
const fs=require('fs'),path=require('path');
const BASE=process.env.MLP_BASE_URL;
function fixture(){if(!['127.0.0.1','localhost'].includes(new URL(BASE).hostname))throw Error('isolated localhost required');return JSON.parse(fs.readFileSync(path.resolve(__dirname,'../../docs/private/mlp390-local.json'),'utf8'));}
test.use({serviceWorkers:'block',launchOptions:{args:['--no-proxy-server'],firefoxUserPrefs:{'network.proxy.type':0}}});
for(const width of [1280,390])test(`MLP-390 command selection, avatar and vision settings ${width}px`,async({page},info)=>{
 const responses=[];await page.route('**/api.php',async route=>{if(!route.request().postData()?.includes('update_settings')){await route.continue();return;}const response=await route.fetch();responses.push(await response.json());await route.fulfill({response});});
 const s=fixture();await page.setViewportSize({width,height:900});
 const login=await page.request.post(BASE+'/api.php',{form:{action:'login',username:s.login,password:s.password}});expect((await login.json()).success).toBe(true);
 await page.goto(BASE+'/',{waitUntil:'domcontentloaded'});
 const input=page.locator('#chat-input');await expect(input).toBeVisible();
 await page.evaluate(()=>{window.testSubmits=0;document.getElementById('chat-form').addEventListener('submit',e=>{e.preventDefault();e.stopImmediatePropagation();window.testSubmits++;},true);});
 await input.fill('/');await expect(page.locator('#cmd-preview')).toBeVisible();
 await input.press('ArrowDown');const selected=await page.locator('#cmd-preview .active').getAttribute('data-prefix');
 await input.press('Enter');await expect(input).toHaveValue(selected+' ');expect(await page.evaluate(()=>window.testSubmits)).toBe(0);
 await expect(page.locator('#cmd-preview')).toBeHidden();await input.press('Enter');expect(await page.evaluate(()=>window.testSubmits)).toBe(1);
 await input.fill('Text');await input.press('Shift+Enter');expect(await page.evaluate(()=>window.testSubmits)).toBe(1);
 await input.fill('/');await input.press('Tab');await expect(page.locator('#cmd-preview')).toBeHidden();expect(await page.evaluate(()=>window.testSubmits)).toBe(1);
 await page.goto(BASE+'/dashboard/#tab-users',{waitUntil:'domcontentloaded'});
 const row=page.locator('#users-table tbody tr').filter({hasText:s.login});const avatar=row.locator('img.lightbox-trigger');
 await expect(avatar).toBeVisible();await avatar.press('Enter');await expect(page.locator('#global-lightbox')).toBeVisible();
 await expect(page.locator('#global-lightbox-img')).toHaveAttribute('src','/assets/img/default-avatar.png');
 const size=await page.locator('#global-lightbox-img').evaluate(el=>el.getBoundingClientRect().width);expect(size).toBeGreaterThan(24);
 await page.keyboard.press('Escape');await expect(page.locator('#global-lightbox')).toBeHidden();
 await avatar.click();await expect(page.locator('#global-lightbox')).toBeVisible();await page.locator('#lightbox-close').click();await expect(page.locator('#global-lightbox')).toBeHidden();
 await page.locator('.nav-tile[data-target="#tab-bot"]').click();
 const main=page.locator('input[type="checkbox"][name="ai_main_is_vision"]'),fast=page.locator('input[type="checkbox"][name="ai_fast_is_vision"]');
 const oldMain=await main.isChecked(),oldFast=await fast.isChecked();
 const form=main.locator('xpath=ancestor::form');
 async function save(){const n=responses.length;await form.locator('button[type="submit"]').click();await expect.poll(()=>responses.length).toBeGreaterThan(n);expect(responses.at(-1).success).toBe(true);await page.reload({waitUntil:'domcontentloaded'});await page.locator('.nav-tile[data-target="#tab-bot"]').click();}
 await main.uncheck();await fast.check();await save();await expect(main).not.toBeChecked();await expect(fast).toBeChecked();
 const dir=path.resolve(__dirname,'../../docs/tests/MLP-390');fs.mkdirSync(dir,{recursive:true});await fast.scrollIntoViewIfNeeded();await page.screenshot({path:path.join(dir,info.project.name+'-'+width+'.png')});
 await main.setChecked(oldMain);await fast.setChecked(oldFast);await save();await page.unrouteAll({behavior:'wait'});
});
