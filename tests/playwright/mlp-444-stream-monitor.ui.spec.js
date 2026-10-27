const {test,expect}=require('@playwright/test');
const {execFileSync}=require('node:child_process');const fs=require('node:fs');const path=require('node:path');
const {BASE,login,post}=require('./mlp-360-helpers');const root=path.resolve(__dirname,'../..');
const cli=(...args)=>execFileSync('docker',['exec','mlp375-php-1','php','tests/playwright/mlp-444-monitor-fixture.php',...args],{cwd:root,encoding:'utf8'});
const file=path.join(root,'docs/private/mlp444-local.json');const fixture=()=>JSON.parse(fs.readFileSync(file,'utf8'));
test.use({serviceWorkers:'block',viewport:{width:1366,height:950},launchOptions:{args:['--no-proxy-server'],firefoxUserPrefs:{'network.proxy.type':0}}});
test.beforeEach(async({page})=>{if(!['localhost','127.0.0.1'].includes(new URL(BASE).hostname))throw Error('Local fixture required');cli('setup');await page.route('https://**',route=>route.abort());await page.route('**/chat_stream.php*',route=>route.abort());await page.route('**/live/**',route=>route.fulfill({status:404}));});
test.afterEach(()=>{if(fs.existsSync(file))cli('cleanup');});
async function open(page,url){await page.goto(BASE+url,{waitUntil:'domcontentloaded'});if(await page.locator('#privacy-panel').isVisible())await page.locator('[data-privacy-necessary]').click();}
test('MLP-444 profile connects matching Telegram and disconnects without removing social login',async({page},info)=>{
    const s=fixture();const csrf=await login(page.request,s.users.host);await open(page,'/profile.php');
    const status=page.locator('#lyra-notifications-status');await expect(status).toHaveText('Уведомления Лиры не подключены.');
    await page.locator('#lyra-notifications-connect').click();const link=page.locator('#lyra-notifications-link');await expect(link).toBeVisible();
    const url=await link.getAttribute('href');expect(url).toMatch(/^https:\/\/t.me\/test_lyra_bot\?start=notify_[a-f0-9]{48}$/);
    cli('accept',url.match(/notify_([a-f0-9]{48})$/)[1]);await page.evaluate(()=>dispatchEvent(new Event('focus')));
    await expect(status).toHaveText('Уведомления Лиры подключены.');await expect(link).toBeHidden();
    await page.setViewportSize({width:390,height:844});await page.locator('#lyra-notifications').scrollIntoViewIfNeeded();
    await page.screenshot({path:path.join(root,'docs/tests/MLP-444/'+info.project.name+'-profile.png'),fullPage:true});
    expect(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1)).toBe(true);
    await page.locator('#lyra-notifications-disconnect').click();await expect(status).toHaveText('Уведомления Лиры не подключены.');
    expect((await post(page.request,csrf,{action:'get_user_socials'})).data.socials.some(x=>x.provider==='telegram')).toBe(true);
});
test('MLP-444 unlinked profile explains prerequisite and connection API enforces CSRF',async({page})=>{
    const s=fixture();await login(page.request,s.users.viewer);await open(page,'/profile.php');
    await expect(page.locator('#lyra-notifications-status')).toContainText('Сначала привяжи Telegram');await expect(page.locator('#lyra-notifications-connect')).toBeHidden();
    const denied=await page.request.post(BASE+'/api.php',{form:{action:'lyra_notifications_connect'}});expect((await denied.json()).success).toBe(false);
});
test('MLP-444 only streamer and administrator may answer system-origin chat controls',async({page})=>{
    const s=fixture();let csrf=await login(page.request,s.users.viewer);await open(page,'/');
    const widget=page.locator('[data-interaction-id="'+s.interaction+'"]');await expect(widget.getByRole('button',{name:'Вижу, исправляю'})).toBeDisabled();
    expect((await post(page.request,csrf,{action:'act_command_interaction',interaction_id:String(s.interaction),option_key:'repair'})).success).toBe(false);
    await page.context().clearCookies();await login(page.request,s.users.host);await open(page,'/');await expect(widget.getByRole('button',{name:'Вижу, исправляю'})).toBeEnabled();
    await widget.getByRole('button',{name:'Вижу, исправляю'}).click();await expect(widget).toContainText('Выбор завершён');
    await page.reload({waitUntil:'domcontentloaded'});await expect(widget).toContainText('Выбор завершён');
});
test('MLP-444 dashboard saves per-event streamer and keeps internal fields out of public schedule',async({page},info)=>{
    const s=fixture();const csrf=await login(page.request,s.users.admin);await open(page,'/dashboard/#tab-events');
    await expect(page.locator('#stream-monitor-settings')).toBeVisible();
    const row=page.locator('#events-table tr[data-id="'+s.event+'"]');await row.getByRole('button',{name:'✎',exact:true}).click();
    await expect(page.locator('#event_monitor_stream')).toBeChecked();await expect(page.locator('#event_streamer_user_id')).toHaveValue(String(s.users.host.id));
    await page.locator('#event_streamer_user_id').selectOption(String(s.users.admin.id));await page.locator('#event-form button[type=submit]').click();
    await expect(page.locator('#event-modal')).toBeHidden();await row.getByRole('button',{name:'✎',exact:true}).click();
    await expect(page.locator('#event_streamer_user_id')).toHaveValue(String(s.users.admin.id));
    await page.screenshot({path:path.join(root,'docs/tests/MLP-444/'+info.project.name+'-event.png'),fullPage:true});
    await page.setViewportSize({width:390,height:844});
    expect(await page.locator('#event-modal .modal-content').evaluate(el=>{const r=el.getBoundingClientRect();return r.height<=innerHeight-30&&r.width<=innerWidth;})).toBe(true);
    expect(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1)).toBe(true);
    await page.locator('#event_streamer_user_id').scrollIntoViewIfNeeded();
    await page.screenshot({path:path.join(root,'docs/tests/MLP-444/'+info.project.name+'-event-mobile.png'),fullPage:true});
    const publicEvents=(await post(page.request,csrf,{action:'get_public_events'})).data.events;const item=publicEvents.find(x=>Number(x.id)===s.event);
    expect(item.streamer_user_id).toBeUndefined();expect(item.monitor_stream).toBeUndefined();
});
