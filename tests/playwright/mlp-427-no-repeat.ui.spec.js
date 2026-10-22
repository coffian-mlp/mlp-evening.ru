const {test,expect}=require('@playwright/test');
const {execFileSync}=require('child_process'),fs=require('fs'),path=require('path');
const {BASE,login}=require('./mlp-360-helpers');
const root=path.resolve(__dirname,'../..'),users=path.join(root,'docs/private/mlp425-local.json'),state=path.join(root,'docs/private/mlp427-local.json');
const fixture=(name,mode)=>execFileSync('docker',['exec','mlp375-php-1','php','tests/playwright/'+name+'-fixture.php',mode],{cwd:root,encoding:'utf8'});
test.use({serviceWorkers:'block',launchOptions:{args:['--no-proxy-server'],firefoxUserPrefs:{'network.proxy.type':0}}});
test.beforeEach(async({page})=>{
    if(!BASE||!['localhost','127.0.0.1'].includes(new URL(BASE).hostname))throw Error('Isolated localhost required');
    fixture('mlp-425-reasoning','setup');fixture('mlp-427-no-repeat','setup');
    await page.route('https://**',r=>r.abort());
    await login(page.request,JSON.parse(fs.readFileSync(users,'utf8')).users.admin);
    await page.goto(BASE+'/dashboard/#tab-episodes',{waitUntil:'domcontentloaded'});
    if(await page.locator('#privacy-panel').isVisible())await page.locator('[data-privacy-necessary]').click();
    page.on('dialog',d=>d.accept());
});
test.afterEach(()=>{
    if(fs.existsSync(state))fixture('mlp-427-no-repeat','cleanup');
    if(fs.existsSync(users))fixture('mlp-425-reasoning','cleanup');
});
test('MLP-427 saved list stays intact until regeneration, and rerolls exclude last watched pair',async({page},info)=>{
    const list=page.locator('#tab-episodes .playlist-list');
    await expect(list).toContainText('Recent pair one');await expect(list).toContainText('Recent pair two');
    for(let retry=0;retry<2;retry++) {
        await Promise.all([page.waitForEvent('domcontentloaded'),page.getByRole('button',{name:'Пересоздать плейлист'}).click()]);
        await expect(list).toContainText('Fresh option');await expect(list).not.toContainText('Recent pair');
        await expect(list.locator('li')).toHaveCount(1);
    }
    const dir=path.join(root,'docs/tests/MLP-427');fs.mkdirSync(dir,{recursive:true});await list.scrollIntoViewIfNeeded();await page.screenshot({path:path.join(dir,info.project.name+'-playlist.png')});
});
