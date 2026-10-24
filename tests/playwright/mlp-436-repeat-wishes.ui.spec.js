const {test,expect}=require('@playwright/test');
const {execFileSync}=require('node:child_process');const fs=require('node:fs');const path=require('node:path');const {randomUUID}=require('node:crypto');
const {BASE,login}=require('./mlp-360-helpers');
const root=path.resolve(__dirname,'../..');
const cli=(name,...args)=>execFileSync('docker',['exec','mlp375-php-1','php','tests/playwright/'+name+'-fixture.php',...args],{cwd:root,encoding:'utf8'});
const privateFile=name=>path.join(root,'docs/private/'+name+'-local.json');
const state=()=>JSON.parse(cli('mlp-436-wishes','state'));
const fixture=()=>JSON.parse(fs.readFileSync(privateFile('mlp436'),'utf8'));
const row=(page,id)=>page.locator('tr[data-episode-id="'+id+'"]');
const btn=(page,id,kind)=>row(page,id).locator('[data-catalogue-kind="'+kind+'"]');
test.use({serviceWorkers:'block',viewport:{width:1440,height:1000},launchOptions:{args:['--no-proxy-server'],firefoxUserPrefs:{'network.proxy.type':0}}});
test.beforeEach(async({page},info)=>{
    if(!BASE||!['localhost','127.0.0.1'].includes(new URL(BASE).hostname))throw Error('Local isolated fixture required');
    cli('mlp-425-reasoning','setup');cli('mlp-436-wishes','setup',info.title.includes('boundary')?'boundary':'normal');
    await page.route('https://**',route=>route.abort());
    const auth=JSON.parse(fs.readFileSync(privateFile('mlp425'),'utf8'));await login(page.request,auth.users.user);
});
test.afterEach(()=>{
    if(fs.existsSync(privateFile('mlp436')))cli('mlp-436-wishes','cleanup');
    if(fs.existsSync(privateFile('mlp425')))cli('mlp-425-reasoning','cleanup');
});
async function open(page,url='/episodes.php'){
    const response=await page.goto(BASE+url,{waitUntil:'domcontentloaded'});
    if(await page.locator('#privacy-panel').isVisible())await page.locator('[data-privacy-necessary]').click();
    await page.locator('[data-catalogue-search]').fill('Weekly');return response;
}
async function settled(page){await expect(page.locator('[data-catalogue-feedback]')).not.toHaveText('Выполняется…');}
async function post(page,data){const csrf=await page.locator('meta[name="csrf-token"]').getAttribute('content');return(await page.request.post(BASE+'/api.php',{form:{csrf_token:csrf,...data}})).json();}
test('MLP-436 weekly repeat adds one vote, SSR and mobile retain cancellation',async({page},info)=>{
    const ids=fixture().ids,response=await open(page);
    const html=await response.text();const oldMarkup=html.match(new RegExp('<tr id="episode-'+ids.old+'"[\\s\\S]*?</tr>'))[0];
    expect(oldMarkup).toContain('Пожелать ещё раз');expect(oldMarkup).toContain('Твоих голосов: 2');
    await expect(btn(page,ids.old,'wish')).toHaveText('Пожелать ещё раз');await expect(btn(page,ids.old,'cancel')).toBeVisible();
    await expect(btn(page,ids.recent,'wish')).toHaveCount(0);await expect(btn(page,ids.recent,'cancel')).toBeVisible();
    await page.setViewportSize({width:390,height:844});await btn(page,ids.old,'wish').click();await settled(page);
    await expect(row(page,ids.old).locator('td').nth(3)).toHaveText('3');await expect(row(page,ids.old)).toContainText('Твоих голосов: 3');
    await expect(btn(page,ids.old,'wish')).toHaveCount(0);await expect(btn(page,ids.old,'cancel')).toBeEnabled();
    expect(state().quota.used).toBe(2);expect(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1)).toBe(true);
    const dir=path.join(root,'docs/tests/MLP-436');fs.mkdirSync(dir,{recursive:true});await page.screenshot({path:path.join(dir,info.project.name+'-mobile.png'),fullPage:true});
    await btn(page,ids.old,'cancel').click();await settled(page);await expect(row(page,ids.old).locator('td').nth(3)).toHaveText('0');expect(state().quota.used).toBe(2);
    const rejected=await post(page,{action:'catalogue_wish',episode_id:ids.old,operation_token:randomUUID()});expect(rejected.data.outcome.code).toBe('cooldown');
});
test('MLP-436 exhausted quota blocks repeat while cancellation remains available',async({page})=>{
    cli('mlp-436-wishes','seed','first');cli('mlp-436-wishes','seed','second');await open(page);const id=fixture().ids.old;
    await expect(btn(page,id,'wish')).toBeDisabled();await expect(btn(page,id,'cancel')).toBeEnabled();await btn(page,id,'cancel').click();await settled(page);
    await expect(btn(page,id,'wish')).toBeDisabled();expect(state().quota.used).toBe(3);
});
test('MLP-436 lost response retries the same repeat at exhausted quota without double counting',async({page})=>{
    cli('mlp-436-wishes','seed','first');await open(page);const id=fixture().ids.old;let first=true;const tokens=[];
    await page.route('**/api.php',async route=>{const p=new URLSearchParams(route.request().postData());if(p.get('action')!=='catalogue_wish')return route.continue();tokens.push(p.get('operation_token'));const response=await route.fetch();if(first){first=false;return route.abort();}return route.fulfill({response});});
    await btn(page,id,'wish').click();await settled(page);await expect(btn(page,id,'wish')).toHaveText('Повторить запрос');await expect(btn(page,id,'cancel')).toBeDisabled();
    expect(state().rows.find(r=>r.id===id).own_votes).toBe(3);
    await btn(page,id,'wish').click();await settled(page);expect(tokens[0]).toBe(tokens[1]);expect(state().rows.find(r=>r.id===id).own_votes).toBe(3);expect(state().quota.used).toBe(3);
    await expect(btn(page,id,'wish')).toHaveCount(0);await expect(btn(page,id,'cancel')).toBeEnabled();
});
test('MLP-436 boundary timer reveals weekly repeat without reload or automatic vote',async({page})=>{
    await page.clock.install();await open(page);const id=fixture().ids.old;
    await expect(btn(page,id,'wish')).toHaveCount(0);await page.clock.fastForward(15000);await expect(btn(page,id,'wish')).toHaveText('Пожелать ещё раз');
    expect(state().rows.find(r=>r.id===id).own_votes).toBe(2);
});
test('MLP-436 dashboard uses the same repeat control and vote counts',async({page})=>{
    const auth=JSON.parse(fs.readFileSync(privateFile('mlp425'),'utf8'));const ids=fixture().ids;await page.context().clearCookies();await login(page.request,auth.users.admin);await open(page,'/dashboard/#tab-episodes');
    await expect(row(page,ids.old).locator('td').nth(3)).toHaveText('2');
    await expect(btn(page,ids.old,'wish')).toHaveText('Хочу посмотреть');await btn(page,ids.old,'wish').click();await settled(page);await expect(row(page,ids.old).locator('td').nth(3)).toHaveText('3');
});
