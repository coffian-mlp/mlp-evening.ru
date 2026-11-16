const {test,expect}=require('@playwright/test');
const {execFileSync}=require('node:child_process');
const fs=require('node:fs');
const path=require('node:path');
const base=process.env.MLP_BASE_URL;
const repo=path.resolve(__dirname,'../..');
const output=path.join(repo,'docs/tests/MLP-471');
const local=!!base&&['127.0.0.1','localhost'].includes(new URL(base).hostname);
test.skip(!local,'MLP-471 requires explicit localhost and its disposable Docker database');
function fixture(mode){
    if(!local)throw Error('Local URL required');
    return JSON.parse(execFileSync('docker',['exec','mlp471-php-1','php','tests/playwright/mlp-471-recurrence-fixture.php',mode],{cwd:repo,encoding:'utf8'}));
}
async function login(request,user){
    const response=await request.post(base+'/api.php',{form:{action:'login',username:user.login,password:user.password}});
    expect((await response.json()).success).toBe(true);
}
async function open(page,url){
    await page.goto(base+url,{waitUntil:'domcontentloaded'});
    await page.waitForFunction(()=>window.privacyChoices!==undefined);
    if(!await page.evaluate(()=>window.privacyChoices.decided)){
        const saved=page.waitForResponse(r=>r.url()===base+'/api.php'&&r.request().postData()?.includes('privacy_choices'));
        await page.locator('[data-privacy-necessary]').click();
        expect((await (await saved).json()).success).toBe(true);
        await expect(page.locator('#privacy-panel')).toBeHidden();
    }
}
async function save(page){
    // The successful form reloads immediately; wait for that navigation instead of
    // reading a response body which Chromium may evict during location.reload().
    await Promise.all([
        page.waitForNavigation({waitUntil:'domcontentloaded'}),
        page.locator('#event-form button[type=submit]').click(),
    ]);
    await expect(page.locator('#event-modal')).toBeHidden();
}

test.use({serviceWorkers:'block',timezoneId:'America/Los_Angeles',viewport:{width:1440,height:1000}});
test('MLP-471: срок и пропуски сохраняются, календарь исключает даты, форма доступна на телефоне',async({page,playwright},info)=>{
    test.setTimeout(240000);
    let s;
    const errors=[];
    page.on('pageerror',e=>errors.push(e.message));
    page.on('console',message=>{if(message.type()==='error')errors.push(message.text());});
    await page.route('**/*',route=>{
        const url=new URL(route.request().url());
        if(url.origin!==new URL(base).origin)return route.fulfill({status:200,contentType:'text/plain',body:''});
        if(url.pathname==='/chat_stream.php')return route.fulfill({status:200,contentType:'text/event-stream',body:': local fixture\n\n'});
        return route.continue();
    });
    const start='2030-01-11T01:30'; // UTC is the previous day; dates must stay MSK in a US browser.
    await page.addInitScript(()=>Object.defineProperty(window,'serverTime',{get:()=>Date.parse('2030-01-10T12:00:00Z')/1000,set:()=>{},configurable:true}));
    try{
        s=fixture('setup');
        await login(page.request,s.users.admin);
        await open(page,'/dashboard/#tab-events');
        await page.getByRole('button',{name:/Добавить событие/}).click();
        await page.locator('#event_title').fill(s.marker);
        await page.locator('#event_start_time').fill(start);
        await page.locator('#event_is_recurring').check();
        await expect(page.locator('#event_recurrence_rule')).toHaveValue('weekly');
        await page.locator('#event_recurrence_until').fill('2030-01-25');
        await page.locator('#event_skip_date').fill('2030-01-12');
        await page.locator('#event_add_skip').click();
        await expect(page.locator('#event_recurrence_error')).toContainText('Выбери дату вхождения');
        for(const date of ['2030-01-11','2030-01-18']){
            await page.locator('#event_skip_date').fill(date);
            await page.locator('#event_add_skip').click();
        }
        await expect(page.locator('#event_skip_dates li')).toHaveCount(2);
        await page.screenshot({path:path.join(output,info.project.name+'-form-desktop.png')});
        await save(page);
        const event=fixture('inspect')[0];
        expect(event.start_time).toBe('2030-01-10 22:30:00');
        expect(event.recurrence_until).toBe('2030-01-25');
        expect(JSON.parse(event.recurrence_skip_dates)).toEqual(['2030-01-11','2030-01-18']);
        const row=page.locator('#events-table tr[data-id="'+event.id+'"]');
        await row.getByRole('button',{name:'✎',exact:true}).click();
        await expect(page.locator('#event_recurrence_until')).toHaveValue('2030-01-25');
        await expect(page.locator('#event_skip_dates li')).toHaveCount(2);
        await page.getByRole('button',{name:'Удалить пропуск 2030-01-18',exact:true}).click();
        await expect(page.locator('#event_skip_dates li')).toHaveCount(1);
        await save(page);
        await row.getByRole('button',{name:'✎',exact:true}).click();
        await expect(page.locator('#event_skip_dates li')).toHaveCount(1);
        await page.locator('#event_skip_date').fill('2030-01-18');await page.locator('#event_add_skip').click();
        await page.setViewportSize({width:360,height:780});
        await page.locator('#event_recurrence_until').scrollIntoViewIfNeeded();
        await page.screenshot({path:path.join(output,info.project.name+'-form-mobile.png')});
        expect(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth)).toBe(true);
        expect(await page.locator('#event-modal .modal-content').evaluate(el=>el.getBoundingClientRect().width<=innerWidth)).toBe(true);
        await save(page);
        const csrf=await page.locator('meta[name="csrf-token"]').getAttribute('content');
        const invalid=await page.request.post(base+'/api.php',{form:{action:'save_event',csrf_token:csrf,id:String(event.id),title:s.marker,start_time_utc:event.start_time,is_recurring:'1',recurrence_rule:'weekly',recurrence_until:'2030-01-25',recurrence_skip_dates:'["2030-01-12"]'}});
        const invalidData=await invalid.json();expect(invalidData.success).toBe(false);expect(invalidData.message).toContain('не является датой вхождения');
        const untilInvalid=await page.request.post(base+'/api.php',{form:{action:'save_event',csrf_token:csrf,id:String(event.id),title:s.marker,start_time_utc:event.start_time,is_recurring:'1',recurrence_rule:'weekly',recurrence_until:'2030-01-10',recurrence_skip_dates:'[]'}});
        expect((await untilInvalid.json()).message).toContain('раньше даты начала');
        const noCsrf=await page.request.post(base+'/api.php',{form:{action:'save_event',id:String(event.id),title:s.marker,start_time_utc:event.start_time}});
        expect((await noCsrf.json()).success).toBe(false);
        const ordinary=await playwright.request.newContext();
        try{
            await login(ordinary,s.users.user);
            const html=await (await ordinary.get(base+'/')).text();const token=html.match(/name="csrf-token" content="([^"]+)"/)[1];
            const denied=await ordinary.post(base+'/api.php',{form:{action:'save_event',csrf_token:token,id:String(event.id),title:s.marker,start_time_utc:event.start_time}});
            expect((await denied.json()).success).toBe(false);
        }finally{await ordinary.dispose();}
        expect(fixture('inspect')[0].recurrence_until).toBe('2030-01-25');
        await page.context().clearCookies();
        await open(page,'/schedule.php');
        await expect(page.locator('#schedule-events')).toHaveAttribute('aria-busy','false');
        const cards=page.locator('.schedule-event-card').filter({hasText:s.marker});
        await expect(cards).toHaveCount(1);
        await expect(cards.first().locator('xpath=../..').locator('.schedule-date strong')).toHaveText('25');
        await expect(page.locator('#next-event')).toContainText(s.marker);
        await page.screenshot({path:path.join(output,info.project.name+'-calendar-mobile.png')});
        expect(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth)).toBe(true);
        await page.getByRole('button',{name:'Следующий месяц'}).click();
        await expect(cards).toHaveCount(0);
        expect(errors).toEqual([]);
        fs.writeFileSync(path.join(output,info.project.name+'-console.json'),JSON.stringify(errors));
    }finally{
        fs.writeFileSync(path.join(output,info.project.name+'-console.json'),JSON.stringify(errors));
        fixture('cleanup');
    }
});
