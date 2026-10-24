const {test,expect}=require('@playwright/test');
const {execFileSync}=require('child_process'),fs=require('fs'),path=require('path');
const {BASE,login}=require('./mlp-360-helpers');
const root=path.resolve(__dirname,'../..');
const fixture=(name,mode)=>execFileSync('docker',['exec','mlp375-php-1','php','tests/playwright/'+name+'-fixture.php',mode],{cwd:root,encoding:'utf8'});
const privateFile=name=>path.join(root,'docs/private/'+name+'-local.json');
const row=(page,id)=>page.locator('tr[data-episode-id="'+id+'"]');
test.use({serviceWorkers:'block',viewport:{width:1280,height:900},launchOptions:{args:['--no-proxy-server'],firefoxUserPrefs:{'network.proxy.type':0}}});
test.beforeEach(async({page})=>{
    if(!BASE||!['localhost','127.0.0.1'].includes(new URL(BASE).hostname))throw Error('Isolated localhost required');
    fixture('mlp-425-reasoning','setup');fixture('mlp-435-catalogue','setup');
    await page.route('https://**',route=>route.abort());
});
test.afterEach(()=>{
    if(fs.existsSync(privateFile('mlp435')))fixture('mlp-435-catalogue','cleanup');
    if(fs.existsSync(privateFile('mlp425')))fixture('mlp-425-reasoning','cleanup');
});
async function open(page,url){await page.goto(BASE+url,{waitUntil:'domcontentloaded'});if(await page.locator('#privacy-panel').isVisible())await page.locator('[data-privacy-necessary]').click();}
test('MLP-435 public catalogue retains old ratings and exposes all twelve Equestria Girls distributions',async({page},info)=>{
    const state=JSON.parse(fs.readFileSync(privateFile('mlp435'),'utf8'));
    await open(page,'/episodes.php');
    await expect(row(page,state.rows[0].ID)).toContainText('8.2');
    await page.locator('[data-catalogue-search]').fill('Equestria Girls');
    await expect(page.locator('.episode-catalogue tbody tr')).toHaveCount(12);
    for(const id of Object.values(state.ids)){
        const cells=row(page,id).locator('td');
        await expect(cells.nth(4)).not.toHaveText('—');await expect(cells.nth(5)).not.toHaveText('—');
    }
    const dance=row(page,state.ids.tt6813786);await dance.locator('details > summary').click();
    await expect(dance.locator('a[href="https://www.imdb.com/title/tt6813786/ratings/"]')).toBeVisible();
    await expect(dance.locator('.episode-catalogue-histogram li')).toHaveCount(10);
    await expect(dance.locator('.episode-catalogue-histogram')).toContainText('1: 61');
    await page.locator('[data-catalogue-season]').selectOption('special');await expect(page.locator('.episode-catalogue tbody tr')).toHaveCount(12);
    await page.locator('[data-catalogue-sort]').selectOption('sd');
    const values=await page.locator('.episode-catalogue tbody tr td:nth-child(6)').allTextContents();
    expect(values.map(Number)).toEqual(values.map(Number).sort((a,b)=>a-b));
    await page.setViewportSize({width:390,height:844});
    expect(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1)).toBe(true);
    const dir=path.join(root,'docs/tests/MLP-435');fs.mkdirSync(dir,{recursive:true});
    await page.screenshot({path:path.join(dir,info.project.name+'-public.png'),fullPage:true});
});
test('MLP-435 new films accept wishes and dashboard shows the approved slot counts',async({page})=>{
    const state=JSON.parse(fs.readFileSync(privateFile('mlp435'),'utf8'));
    const users=JSON.parse(fs.readFileSync(privateFile('mlp425'),'utf8')).users;
    await login(page.request,users.user);await open(page,'/episodes.php');
    await page.locator('[data-catalogue-search]').fill('Rainbow Rocks');
    const film=row(page,state.ids.tt3529198);
    await film.getByRole('button',{name:'Хочу посмотреть',exact:true}).click();
    await expect(film.getByRole('button',{name:'Отменить желание',exact:true})).toBeVisible();
    await expect(film.locator('td').nth(3)).toHaveText('1');
    await film.getByRole('button',{name:'Отменить желание',exact:true}).click();
    await expect(film.getByRole('button',{name:'Хочу посмотреть',exact:true})).toBeVisible();
    await page.context().clearCookies();await login(page.request,users.admin);await open(page,'/dashboard/#tab-episodes');
    await page.locator('[data-catalogue-search]').fill('Equestria Girls');
    await expect(page.locator('.episode-catalogue tbody tr')).toHaveCount(12);
    for(const [imdb,length] of [['tt3529198','3'],['tt6813786','1'],['tt7869818','2'],['tt11443714','2']])await expect(row(page,state.ids[imdb]).locator('td').last()).toHaveText(length);
});
