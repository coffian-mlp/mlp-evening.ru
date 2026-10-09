const {test,expect}=require('@playwright/test');
const fs=require('fs'),path=require('path');
const BASE=process.env.MLP_BASE_URL;
const ADMIN=process.env.MLP_ADMIN==='1';
const credentials=()=>JSON.parse(fs.readFileSync(path.resolve(__dirname,'../../docs/private/daybreaker-test-account.json'),'utf8'));
test.use({serviceWorkers:'block',viewport:{width:1280,height:900}});
async function login(page){const c=credentials();await page.goto(BASE+'/login.php');await page.fill('#ajax-login-form input[name="username"]',c.login||c.username);await page.fill('#ajax-login-form input[name="password"]',c.password);await page.click('#ajax-login-form button[type="submit"]');await page.waitForURL(u=>!u.pathname.includes('login.php'));}
test('MLP-376 non-admin cannot update announcement settings',async({page})=>{
 test.skip(ADMIN,'run with basic test-account role');await login(page);await page.goto(BASE+'/');const csrf=await page.locator('meta[name="csrf-token"]').getAttribute('content');
 const r=await page.request.post(BASE+'/api.php',{form:{action:'update_announcements',csrf_token:csrf,announcements_enabled:'0'}});expect((await r.json()).success).toBe(false);
});
test('MLP-376 admin saves settings, preserves masked secrets and sees validation errors',async({page,browserName})=>{
 test.skip(!ADMIN,'run with temporary administrator test-account role');await login(page);await page.goto(BASE+'/dashboard/#tab-bot');
 const form=page.locator('#announcement-settings-form');await expect(form).toBeVisible();await expect(form.locator('[name="announcements_enabled"][type="checkbox"]')).not.toBeChecked();
 const number=form.locator('[name="announcements_first_number"]');const old=await number.inputValue();
 const token=form.locator('[name="announcements_token"]'),proxy=form.locator('[name="announcements_proxy_url"]');await expect(token).toHaveValue('');await expect(proxy).toHaveValue('');await expect(token).toHaveAttribute('type','password');await expect(proxy).toHaveAttribute('type','password');
 const date=form.locator('[name="announcements_first_date"]');const oldDate=await date.inputValue();
 const submit=async()=>{const response=page.waitForResponse(r=>r.url().endsWith('/api.php')&&r.request().method()==='POST'&&r.request().postData().includes('update_announcements'));await form.locator('button[type="submit"]').click();return(await response).json();};
 try{
  await number.fill(String(Number(old)+1));expect((await submit()).success).toBe(true);await expect(number).toHaveValue(String(Number(old)+1));await expect(date).toHaveValue(oldDate);
  await page.reload();await expect(number).toHaveValue(String(Number(old)+1));await expect(token).toHaveValue('');await expect(token).toHaveAttribute('placeholder','Токен сохранён');await expect(proxy).toHaveValue('');
  await proxy.fill('invalid://secret@example.org');await number.fill(String(Number(old)+2));expect((await submit()).success).toBe(false);await expect(page.locator('.flash-message')).toContainText('Поддерживаются');await page.reload();await expect(number).toHaveValue(String(Number(old)+1));
  await page.setViewportSize({width:390,height:844});await form.scrollIntoViewIfNeeded();await expect(form.locator('button[type="submit"]')).toBeVisible();expect(await page.evaluate(()=>document.documentElement.scrollWidth-innerWidth)).toBeLessThanOrEqual(1);
  const dir=path.resolve(__dirname,'../../docs/tests/MLP-376/screenshots');fs.mkdirSync(dir,{recursive:true});await page.screenshot({path:path.join(dir,browserName+'-settings.png')});
 }finally{
  await page.reload();await number.fill(old);const result=await submit();expect(result.success,'original settings restored').toBe(true);
 }
});
