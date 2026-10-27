const {test,expect}=require('@playwright/test');const {execFileSync}=require('node:child_process');const fs=require('node:fs');const path=require('node:path');
const {BASE,login,post}=require('./mlp-360-helpers');const root=path.resolve(__dirname,'../..');const file=path.join(root,'docs/private/mlp444-local.json');
const cli=(...args)=>execFileSync('docker',['exec','mlp375-php-1','php','tests/playwright/mlp-444-monitor-fixture.php',...args],{cwd:root,encoding:'utf8'});
const fixture=()=>JSON.parse(fs.readFileSync(file,'utf8'));const roles=name=>JSON.parse(cli('roles_get',name));
test.use({serviceWorkers:'block',viewport:{width:1366,height:950},launchOptions:{args:['--no-proxy-server'],firefoxUserPrefs:{'network.proxy.type':0}}});
test.beforeEach(async({page})=>{
    if(!['localhost','127.0.0.1'].includes(new URL(BASE).hostname))throw Error('Isolated localhost required');
    cli('setup');cli('roles_set','admin','user','admin');cli('roles_set','viewer','user');
    await page.route('https://**',route=>route.abort());await page.route('**/chat_stream.php*',route=>route.abort());await page.route('**/live/**',route=>route.fulfill({status:404}));
});
test.afterEach(()=>{if(fs.existsSync(file))cli('cleanup');});
async function open(page,url){await page.goto(BASE+url,{waitUntil:'domcontentloaded'});if(await page.locator('#privacy-panel').isVisible())await page.locator('[data-privacy-necessary]').click();}
test('MLP-445 only explicit streamer sees notification settings, independently of administrator role',async({page},info)=>{
    const s=fixture();
    for(const [name,visible] of [['admin',false],['viewer',false],['host',true]]){
        await page.goto('about:blank');await page.context().clearCookies();const csrf=await login(page.request,s.users[name]);await open(page,'/profile.php');
        await expect(page.locator('#lyra-notifications')).toHaveCount(visible?1:0);
        const status=await post(page.request,csrf,{action:'lyra_notifications_status'});expect(status.success).toBe(visible);
    }
    await page.setViewportSize({width:390,height:844});await page.locator('#lyra-notifications').scrollIntoViewIfNeeded();
    await page.screenshot({path:path.join(root,'docs/tests/MLP-445/'+info.project.name+'-streamer-profile.png'),fullPage:true});
});
test('MLP-445 administrator selects multiple roles and mandatory user cannot be removed',async({page},info)=>{
    const s=fixture();await login(page.request,s.users.admin);await open(page,'/dashboard/#tab-users');
    const edit=async()=>{await page.evaluate(id=>window.openUserCard(id),s.users.host.id);await expect(page.locator('#user_roles')).toBeVisible();};
    await edit();const box=page.locator('#user_roles');await expect(box.locator('input[value=user][type=checkbox]')).toBeChecked();await expect(box.locator('input[value=user][type=checkbox]')).toBeDisabled();
    await box.locator('input[value=moderator]').check();await box.locator('input[value=admin]').check();
    await page.screenshot({path:path.join(root,'docs/tests/MLP-445/'+info.project.name+'-roles.png'),fullPage:true});
    await page.locator('#user-form button[type=submit]').click();await expect(page.locator('#user-modal')).toBeHidden();
    expect(roles('host')).toEqual(['user','moderator','admin','streamer']);
    await edit();for(const role of ['moderator','admin','streamer'])await box.locator('input[value='+role+']').uncheck();
    await page.locator('#user-form button[type=submit]').click();await expect(page.locator('#user-modal')).toBeHidden();expect(roles('host')).toEqual(['user']);
});
test('MLP-445 role changes affect existing session without another login and preserve chat access',async({page})=>{
    const s=fixture();await login(page.request,s.users.viewer);await open(page,'/profile.php');await expect(page.locator('#lyra-notifications')).toHaveCount(0);
    cli('roles_set','viewer','streamer');await page.reload({waitUntil:'domcontentloaded'});await expect(page.locator('#lyra-notifications')).toBeVisible();
    cli('roles_set','viewer');await page.reload({waitUntil:'domcontentloaded'});await expect(page.locator('#lyra-notifications')).toHaveCount(0);
    await open(page,'/');await expect(page.locator('.chat-input-area')).toBeVisible();expect(roles('viewer')).toEqual(['user']);
});
test('MLP-445 profile or forged administrative request cannot grant own roles',async({page})=>{
    const s=fixture();const csrf=await login(page.request,s.users.viewer);
    await post(page.request,csrf,{action:'update_profile',role:'admin','roles[]':'admin',roles_json:'["admin"]',_roles_json:'["admin"]'});
    expect(roles('viewer')).toEqual(['user']);
    expect((await post(page.request,csrf,{action:'save_user',user_id:String(s.users.viewer.id),login:s.users.viewer.login,'roles[]':'admin'})).success).toBe(false);
    expect((await post(page.request,csrf,{action:'lyra_notifications_connect'})).success).toBe(false);
    expect((await post(page.request,csrf,{action:'get_users'})).success).toBe(false);
});
test('MLP-445 event candidates require streamer membership and streamer gains no dashboard administration',async({page})=>{
    const s=fixture();let csrf=await login(page.request,s.users.admin);await open(page,'/dashboard/#tab-events');
    const select=page.locator('#event_streamer_user_id');await expect(select.locator('option[value="'+s.users.host.id+'"]').first()).toHaveCount(1);
    await expect(select.locator('option[value="'+s.users.admin.id+'"]').first()).toHaveCount(0);
    expect((await post(page.request,csrf,{action:'save_stream_monitor_settings',default_streamer:String(s.users.admin.id)})).success).toBe(false);
    await page.goto('about:blank');await page.context().clearCookies();csrf=await login(page.request,s.users.host);
    for(const action of ['get_users','self_hosted_stream_credentials','save_stream_monitor_settings'])expect((await post(page.request,csrf,{action})).success).toBe(false);
});
