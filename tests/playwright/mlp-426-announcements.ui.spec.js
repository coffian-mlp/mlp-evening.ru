const {test,expect}=require('@playwright/test');
const {execFileSync}=require('child_process'),fs=require('fs'),path=require('path');
const {BASE,login}=require('./mlp-360-helpers');
const root=path.resolve(__dirname,'../..');
const state=path.join(root,'docs/private/mlp425-local.json'),posts=path.join(root,'docs/private/mlp426-local.json');
const received=new WeakMap();
const fixture=(name,mode)=>execFileSync('docker',['exec','mlp375-php-1','php','tests/playwright/'+name+'-fixture.php',mode],{cwd:root,encoding:'utf8'});
test.use({serviceWorkers:'block',launchOptions:{args:['--no-proxy-server'],firefoxUserPrefs:{'network.proxy.type':0}}});
test.beforeEach(async({page})=>{
    if(!BASE||!['localhost','127.0.0.1'].includes(new URL(BASE).hostname))throw Error('Isolated localhost required');
    fixture('mlp-425-reasoning','setup');fixture('mlp-426-announcements','setup');
    await page.route('https://**',r=>r.abort());
    received.set(page,[]);
    await page.route('**/api.php',async route=>{
        const response=await route.fetch();
        if(route.request().postData()?.includes('announcement_post_action'))received.get(page).push(await response.json());
        await route.fulfill({response});
    });
    await login(page.request,JSON.parse(fs.readFileSync(state,'utf8')).users.admin);
    await page.goto(BASE+'/dashboard/#tab-announcements',{waitUntil:'domcontentloaded'});
    if(await page.locator('#privacy-panel').isVisible())await page.locator('[data-privacy-necessary]').click();
});
test.afterEach(()=>{
    if(fs.existsSync(posts))fixture('mlp-426-announcements','cleanup');
    if(fs.existsSync(state))fixture('mlp-425-reasoning','cleanup');
});
test('MLP-426 failed posts explain stage, episode and exhausted retries on desktop and mobile',async({page},info)=>{
    const main=page.locator('.announcement-post').filter({hasText:'Основной анонс'}).first();
    await expect(main.locator('.announcement-error')).toContainText('Road to Friendship');
    await expect(main.locator('.announcement-error')).toContainText('подтверждённые источники');
    await expect(main.locator('.announcement-error')).toContainText('Автоматические попытки закончились');
    await expect(page.locator('#announcement-posts')).not.toContainText('Следующая попытка');
    await expect(page.locator('#announcement-posts')).not.toContainText('synopsis_sources_missing');
    await expect(page.locator('.announcement-post').filter({hasText:'Напоминание'}).first()).toContainText('Достигнут дневной лимит');
    await page.setViewportSize({width:390,height:844});await main.locator('.announcement-error').scrollIntoViewIfNeeded();
    await expect(main.locator('.announcement-error')).toBeVisible();
    expect(await main.locator('.announcement-error').evaluate(el=>el.getBoundingClientRect().right<=innerWidth+1)).toBe(true);
    const dir=path.join(root,'docs/tests/MLP-426');fs.mkdirSync(dir,{recursive:true});await page.screenshot({path:path.join(dir,info.project.name+'-errors.png')});
});
test('MLP-426 regenerate both recovers failed post without stale error or approval',async({page})=>{
    let main=page.locator('.announcement-post').filter({hasText:'Основной анонс'}).first();
    const oldId=await main.getAttribute('data-revision');await main.locator('.announcement-regenerate > summary').click();
    await main.locator('button[value="both"]').click();await expect.poll(()=>received.get(page).length).toBe(1);
    expect(received.get(page)[0].success).toBe(true);
    await expect(page.locator('.announcement-post[data-revision="'+oldId+'"]')).toHaveCount(0);
    main=page.locator('.announcement-post').filter({hasText:'Основной анонс'}).first();
    await expect(main).toContainText('В очереди: текст');await expect(main.locator('.announcement-error')).toHaveCount(0);
    await expect(main.locator('button[value="approve"]')).toHaveCount(0);
});
