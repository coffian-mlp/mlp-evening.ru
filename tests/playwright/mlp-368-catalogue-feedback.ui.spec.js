const {test,expect}=require('@playwright/test');
const {execFileSync}=require('child_process');const fs=require('fs');const path=require('path');const {randomUUID}=require('crypto');
const BASE=process.env.MLP_BASE_URL, repo=path.resolve(__dirname,'../..');
const cli=(...args)=>{if(!BASE||!['localhost','127.0.0.1'].includes(new URL(BASE).hostname))throw Error('Local fixture required');return execFileSync('docker',['compose','-p','mlp359','-f','docker-compose.yml','-f','docs/tests/MLP-359/compose.override.yml','exec','-T','php','php','tests/playwright/mlp-367-catalogue-fixture.php',...args],{cwd:repo,stdio:'pipe'}).toString();};
const fixture=()=>JSON.parse(fs.readFileSync(path.join(repo,'docs/private/mlp367-catalogue-local.json'),'utf8'));
const row=(page,id)=>page.locator(`[data-episode-id="${id}"]`);
const button=(page,id)=>row(page,id).locator('[data-catalogue-action]');
async function post(page,data){const csrf=await page.locator('meta[name="csrf-token"]').count()?await page.locator('meta[name="csrf-token"]').getAttribute('content'):'';return(await page.request.post(BASE+'/api.php',{form:{csrf_token:csrf,...data}})).json();}
async function actor(page,key){cli('user',key);await page.goto(BASE+'/episodes.php');const f=fixture().cases[key];expect((await post(page,{action:'login',username:f.login,password:f.password})).success).toBe(true);await page.goto(BASE+'/episodes.php');return{...f,key};}
async function settled(page){await expect(page.locator('[data-catalogue-feedback]')).not.toHaveText('Выполняется…');}
async function shot(page,browser,name){const dir=path.join(repo,'docs/tests/MLP-368-catalogue-feedback/screenshots');fs.mkdirSync(dir,{recursive:true});await page.screenshot({path:path.join(dir,`${browser}-${name}.png`),animations:'disabled'});}
test.use({serviceWorkers:'block'});
test.beforeEach(()=>test.skip(!BASE,'MLP_BASE_URL required'));

test('MLP-368 exhausted SSR and reload disable new wishes but preserve cancellation',async({page,browserName})=>{
 const f=await actor(page,browserName+'_368_zero');cli('seed-wishes',f.key,'alpha,beta,gamma');await page.reload();const ids=fixture().ids;
 await expect(button(page,ids.delta)).toHaveText('Лимит на сегодня');await expect(button(page,ids.delta)).toBeDisabled();await expect(button(page,ids.delta)).toHaveCSS('cursor','not-allowed');await expect(button(page,ids.alpha)).toBeEnabled();
 await button(page,ids.alpha).click();await settled(page);await expect(button(page,ids.alpha)).toBeDisabled();await expect(button(page,ids.beta)).toHaveText('Отменить желание');await expect(button(page,ids.beta)).toBeEnabled();
 await expect(page.locator('[data-catalogue-quota]')).toContainText('Дневной лимит исчерпан');await expect(page.locator('.flash-message')).toContainText('Желание отменено');await page.reload();await expect(button(page,ids.delta)).toBeDisabled();
});
test('MLP-368 third committed wish lost response keeps UUID retry available at exhausted limit',async({page,browserName})=>{
 const f=await actor(page,browserName+'_368_retry');cli('seed-wishes',f.key,'alpha,beta');await page.reload();const ids=fixture().ids;let first=true;const tokens=[];
 await page.route('**/api.php',async route=>{const p=new URLSearchParams(route.request().postData());if(p.get('action')!=='catalogue_wish')return route.continue();tokens.push(p.get('operation_token'));if(first){first=false;await route.fetch();return route.abort();}return route.continue();});
 await button(page,ids.gamma).click();await settled(page);await expect(button(page,ids.gamma)).toHaveText('Повторить запрос');await expect(button(page,ids.gamma)).toBeEnabled();
 await button(page,ids.gamma).click();await settled(page);expect(tokens[1]).toBe(tokens[0]);await expect(button(page,ids.gamma)).toHaveText('Отменить желание');await expect(button(page,ids.delta)).toBeDisabled();expect(JSON.parse(cli('state',f.key)).accepted).toBe(3);
});
test('MLP-368 offscreen cooldown feedback remains visible plaintext at 360px without scroll jump',async({page,browserName})=>{
 await actor(page,browserName+'_368_flash');const id=fixture().ids.low;await button(page,id).click();await settled(page);await button(page,id).click();await settled(page);
 await page.setViewportSize({width:360,height:780});await button(page,id).evaluate(node=>node.scrollIntoView({block:'center',inline:'nearest'}));const beforeBox=await button(page,id).boundingBox();expect((await page.locator('[data-catalogue-feedback]').boundingBox()).y).toBeLessThan(0);await button(page,id).click();await settled(page);
 await expect(page.locator('.flash-message.alert-danger')).toContainText('(UTC)');const afterBox=await button(page,id).boundingBox();expect(beforeBox.y).toBeGreaterThanOrEqual(0);expect(beforeBox.y+beforeBox.height).toBeLessThanOrEqual(780);expect(afterBox.y).toBeGreaterThanOrEqual(0);expect(afterBox.y+afterBox.height).toBeLessThanOrEqual(780);expect(await page.evaluate(()=>scrollY)).toBeGreaterThan(0);expect((await page.locator('[data-catalogue-feedback]').boundingBox()).y).toBeLessThan(0);await shot(page,browserName,'cooldown-visible');
 const attack='<img src=x onerror="window.mlp368Xss=1"> & quotation " safe '+ 'длинная причина '.repeat(12);
 await page.route('**/api.php',async route=>{await route.fetch();return route.fulfill({json:{success:false,message:attack}});});
 await button(page,id).click();await settled(page);await expect(page.locator('.flash-message')).toHaveText(attack);await expect(page.locator('.flash-message img')).toHaveCount(0);expect(await page.evaluate(()=>window.mlp368Xss)).toBeUndefined();
 const box=await page.locator('.flash-message').boundingBox();expect(box.x).toBeGreaterThanOrEqual(0);expect(box.x+box.width).toBeLessThanOrEqual(360);await shot(page,browserName,'plaintext-long-error');
});
test('MLP-368 present malformed quota retains pending retry; absent legacy quota stays unknown',async({page,browserName})=>{
 const f=await actor(page,browserName+'_368_shape');const ids=fixture().ids;let corrupt=true;const tokens=[];
 await page.route('**/api.php',async route=>{const p=new URLSearchParams(route.request().postData());if(p.get('action')!=='catalogue_wish')return route.continue();tokens.push(p.get('operation_token'));const response=await route.fetch();const data=await response.json();if(corrupt){corrupt=false;data.data.quota.remaining=99;return route.fulfill({response,json:data});}return route.fulfill({response});});
 await button(page,ids.delta).click();await settled(page);await expect(button(page,ids.delta)).toHaveText('Повторить запрос');await expect(page.locator('.flash-message')).toContainText('Не удалось подтвердить дневной лимит');
 await button(page,ids.delta).click();await settled(page);expect(tokens[0]).toBe(tokens[1]);expect(JSON.parse(cli('state',f.key)).accepted).toBe(1);await expect(button(page,ids.delta)).toHaveText('Отменить желание');
 await page.unroute('**/api.php');await page.route('**/api.php',async route=>{const response=await route.fetch();const data=await response.json();delete data.data.quota;return route.fulfill({response,json:data});});
 await button(page,ids.stale).click();await settled(page);await expect(page.locator('[data-catalogue-quota]')).toContainText('неизвестен');await expect(button(page,ids.stale)).toHaveText('Отменить желание');
});
test('MLP-368 later same-day result cannot regain quota through older response',async({page,browserName})=>{
 await actor(page,browserName+'_368_order');const ids=fixture().ids;let release, captured;const ready=new Promise(resolve=>captured=resolve);const delayed=new Promise(resolve=>release=resolve);let first=true;
 await page.route('**/api.php',async route=>{if(new URLSearchParams(route.request().postData()).get('action')!=='catalogue_wish')return route.continue();if(first){first=false;const response=await route.fetch();captured();await delayed;return route.fulfill({response});}return route.continue();});
 await button(page,ids.alpha).click();await ready;await button(page,ids.beta).click();await expect(page.locator('[data-catalogue-quota]')).toContainText('Осталось сегодня: 1');release();await expect(button(page,ids.alpha)).toHaveText('Отменить желание');await expect(page.locator('[data-catalogue-quota]')).toContainText('Осталось сегодня: 1');
});
test('MLP-368 server-derived day expiry becomes unknown without invented allowance or mutation',async({page,browserName})=>{
 await page.clock.install();const f=await actor(page,browserName+'_368_expiry');cli('seed-wishes',f.key,'alpha,beta,gamma');await page.reload();const id=fixture().ids.delta;
 await expect(button(page,id)).toBeDisabled();const quota=JSON.parse(await page.locator('.episode-catalogue-data').textContent()).catalogue.viewer.quota;
 await page.clock.fastForward(Date.parse(quota.resets_at)-Date.parse(quota.observed_at)+1000);await expect(button(page,id)).toBeEnabled();await expect(page.locator('[data-catalogue-quota]')).toContainText('неизвестен');expect(JSON.parse(cli('state',f.key)).accepted).toBe(3);
});

// Render the actual PHP template against malformed boundary data; retain the real HTTP shell/assets and actor session.
test('MLP-368 malformed SSR quota types render unknown safely in actual template and browser',async({page,browserName})=>{
 const f=await actor(page,browserName+'_368_ssr');cli('seed-wishes',f.key,'alpha,beta,gamma');const id=fixture().ids.delta;
 for(const variant of ['array','null-byte','bad-day','bad-count','missing']){
  const rendered=cli('render-ssr',f.key,variant);expect(rendered).not.toContain('data-quota-disabled="true"');
  await page.route('**/episodes.php',async route=>{const response=await route.fetch();const body=(await response.text()).replace(/<section class="episode-catalogue"[\s\S]*?<\/section>/,rendered);return route.fulfill({response,body});});
  await page.goto(BASE+'/episodes.php');await expect(page.locator('[data-catalogue-quota]')).toContainText('неизвестен');await expect(button(page,id)).toBeEnabled();await expect(button(page,id)).toHaveText('Хочу посмотреть');await page.unroute('**/episodes.php');
 }
 expect(JSON.parse(cli('state',f.key)).accepted).toBe(3);
});
