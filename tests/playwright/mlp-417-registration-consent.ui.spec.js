const {test,expect}=require('@playwright/test');
const {execFileSync}=require('child_process');const fs=require('fs'),path=require('path');
const root=path.resolve(__dirname,'../..'),base=process.env.MLP_BASE_URL,state=path.join(root,'docs/private/mlp414-local.json');
const fixture=action=>execFileSync('docker',['exec','mlp375-php-1','php','tests/playwright/mlp-414-privacy-fixture.php',action],{cwd:root});
test.use({serviceWorkers:'block',viewport:{width:1366,height:900},launchOptions:{args:['--no-proxy-server'],firefoxUserPrefs:{'network.proxy.type':0}}});
test.beforeEach(async({page})=>{
    if(!base||!['localhost','127.0.0.1'].includes(new URL(base).hostname))throw Error('Isolated localhost required');
    await page.route('https://**',r=>r.abort());await page.route('**/chat_stream.php*',r=>r.abort());fixture('setup');
    await page.goto(base+'/login.php',{waitUntil:'load'});await page.locator('[data-privacy-necessary]').click();await expect(page.locator('#privacy-panel')).toBeHidden();
});
test.afterEach(()=>{if(fs.existsSync(state))fixture('cleanup');});
test('new Telegram profile is created only after a separate checked registration consent',async({page},info)=>{
    const user=JSON.parse(fs.readFileSync(state,'utf8'));
    const invalid=await page.request.post(base+'/api.php',{form:{action:'social_login',provider:'telegram',account_consent:'1',csrf_token:'invalid'}});
    expect((await invalid.json()).success).toBe(false);expect(JSON.parse(fixture('social-state'))).toEqual({exists:false,active:false});
    await page.evaluate(data=>window.onTelegramAuth(data),user.telegramData);
    const dialog=page.locator('#social-registration-dialog');await expect(dialog).toBeVisible();
    await expect(dialog).toContainText('Telegram registration fixture');expect(JSON.parse(fixture('social-state'))).toEqual({exists:false,active:false});
    await dialog.getByRole('button',{name:'Отмена',exact:true}).click();await expect(dialog).toBeHidden();
    expect(JSON.parse(fixture('social-state')).exists).toBe(false);
    await page.evaluate(data=>window.onTelegramAuth(data),user.telegramData);await expect(dialog).toBeVisible();
    await dialog.getByRole('button',{name:'Подтвердить и создать аккаунт'}).click();
    expect(JSON.parse(fixture('social-state')).exists).toBe(false);
    await page.setViewportSize({width:390,height:844});await page.locator('#social-registration-consent').check();
    await page.screenshot({path:path.join(root,'docs/tests/MLP-417',info.project.name+'-registration-mobile.png')});
    await dialog.getByRole('button',{name:'Подтвердить и создать аккаунт'}).click();
    await expect(page).toHaveURL(base+'/');expect(JSON.parse(fixture('social-state'))).toEqual({exists:true,active:true});
    await expect(page.locator('#privacy-panel')).toBeHidden();await expect(page.locator('#account-consent-dialog')).toBeHidden();
    expect(await page.evaluate(()=>window.privacyChoices)).toEqual({decided:true,analytics:false,external_media:false});
});
test('existing linked Telegram profile logs in without a registration consent dialog',async({page})=>{
    fixture('link-reader');const user=JSON.parse(fs.readFileSync(state,'utf8'));
    await page.evaluate(data=>window.onTelegramAuth(data),user.telegramData);await expect(page).toHaveURL(base+'/');
    await expect.poll(()=>page.evaluate(()=>window.currentUserId)).toBe(user.ids.reader);
    await expect(page.locator('#social-registration-dialog')).toHaveCount(0);await expect(page.locator('#account-consent-dialog')).toBeHidden();
});
