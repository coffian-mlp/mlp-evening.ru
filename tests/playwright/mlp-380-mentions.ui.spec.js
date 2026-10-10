const {test,expect}=require('@playwright/test');
const fs=require('fs'),path=require('path');
const BASE=process.env.MLP_BASE_URL;
test.use({serviceWorkers:'block'});
test.setTimeout(60000);
for (const mode of ['popup','embedded']) test(`MLP-380 full identity highlighting and insertion (${mode})`,async({page})=>{
  if (!BASE || !['localhost','127.0.0.1'].includes(new URL(BASE).hostname)) throw new Error('Local owned fixture required');
  const f=JSON.parse(fs.readFileSync(path.resolve(__dirname,'../../docs/private/mlp380-mentions-local.json'),'utf8'));
  const u=f.users[0];
  const r=await page.request.post(BASE+'/api.php',{form:{action:'login',username:u.login,password:u.password}});expect((await r.json()).success).toBe(true);
  await page.goto(BASE+(mode==='popup'?'/chat_popup.php':'/'),{waitUntil:'domcontentloaded'});
  if (mode==='embedded') {const toggle=page.locator('#chat-toggle');if(await toggle.count()) await toggle.click();}
  await page.waitForFunction(()=>typeof window.reloadChatMessages==='function');
  await page.evaluate(()=>window.reloadChatMessages());
  const row=id=>page.locator(`.chat-message[data-id="${id}"]`);
  const mine=row(f.messages[0]);await expect(mine).toHaveClass(/message-mentioned/);
  await expect(mine.locator('.md-mention')).toHaveText('@Твайлайт Спаркл');
  await mine.locator('.md-mention').click({force:true});await expect(page.locator('#chat-input')).toHaveValue(/@Твайлайт Спаркл/);
  const other=row(f.messages[1]);await expect(other).not.toHaveClass(/message-mentioned/);
  await expect(other.locator('.md-mention')).toHaveText('@Hord-Tyan');
  await page.locator('#chat-input').fill('');await other.locator('.md-mention').click({force:true});await expect(page.locator('#chat-input')).toHaveValue(/@Hord-Tyan/);
  await expect(row(f.messages[2]).locator('.md-mention')).toHaveCount(0);
  await page.setViewportSize({width:390,height:844});await mine.locator('.md-mention').click({force:true});await expect(page.locator('#chat-input')).toHaveValue(/@Твайлайт Спаркл/);
});
