const {test,expect}=require('@playwright/test');
const fs=require('fs'),path=require('path');
const BASE=process.env.MLP_BASE_URL;
test.use({serviceWorkers:'block',launchOptions:{args:['--no-proxy-server'],firefoxUserPrefs:{'network.proxy.type':0}}});
test('MLP-387 administrator saves generation prompts and validates text limits',async({page,browserName})=>{
 const c=JSON.parse(fs.readFileSync(path.resolve(__dirname,'../../docs/private/daybreaker-test-account.json'),'utf8'));
 await page.goto(BASE+'/login.php');await page.fill('#ajax-login-form input[name="username"]',c.login||c.username);await page.fill('#ajax-login-form input[name="password"]',c.password);await page.click('#ajax-login-form button[type="submit"]');await page.waitForURL(u=>!u.pathname.includes('login.php'));
 await page.goto(BASE+'/dashboard/#tab-bot');const form=page.locator('#announcement-settings-form');await expect(form).toBeVisible();
 await form.locator('#announcement-generation-settings summary').click();
 const prompt=form.locator('#announcements_prompt_main'),length=form.locator('#announcements_main_chars');const old=await prompt.inputValue(),oldLength=await length.inputValue();
 const submit=async()=>{const r=page.waitForResponse(r=>r.url().endsWith('/api.php')&&r.request().method()==='POST'&&r.request().postData().includes('update_announcements'));await form.locator('button[type="submit"]').click();return(await r).json();};
 try {
  await prompt.fill(old+' Проверочный стиль MLP-387.');await length.fill('720');expect((await submit()).success).toBe(true);
  await page.reload();await form.locator('#announcement-generation-settings summary').click();await expect(prompt).toHaveValue(old+' Проверочный стиль MLP-387.');await expect(length).toHaveValue('720');
  await expect(form.locator('#announcements_token')).toHaveValue('');await expect(form.locator('#announcements_proxy_url')).toHaveValue('');
  const csrf=await page.locator('meta[name="csrf-token"]').getAttribute('content');const bad=await page.request.post(BASE+'/api.php',{form:{action:'update_announcements',csrf_token:csrf,announcements_main_chars:'901'}});expect((await bad.json()).success).toBe(false);
  await page.setViewportSize({width:390,height:844});await prompt.scrollIntoViewIfNeeded();await expect(prompt).toBeVisible();expect(await form.evaluate(el=>el.getBoundingClientRect().right-innerWidth)).toBeLessThanOrEqual(1);
  fs.mkdirSync(path.resolve(__dirname,'../../docs/tests/MLP-387'),{recursive:true});await page.screenshot({path:path.resolve(__dirname,'../../docs/tests/MLP-387/'+browserName+'.png')});
 } finally {await prompt.fill(old);await length.fill(oldLength);expect((await submit()).success).toBe(true);}
});
