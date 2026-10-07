const {test, expect} = require('@playwright/test');
const fs = require('fs');
const path = require('path');
const BASE = process.env.MLP_BASE_URL;
const OUTPUT = process.env.MLP_SCREENSHOT_DIR;
const NOW = Date.parse('2026-10-07T12:00:00Z');
const events = [
    {id:1,title:'Поняшный вечерок — длинное название события для проверки переноса на телефоне',description:'Смотрим вместе.\nОбсуждаем после просмотра.',start_time:'2026-10-03 16:00:00',duration_minutes:240,is_recurring:1,recurrence_rule:'weekly',use_playlist:0,color:'#8349ac'},
    {id:2,title:'Поздний вечер, часть; 1',description:'Описание <img src=x onerror="window.scheduleXss=1">\nСледующая строка',start_time:'2026-10-09 21:30:00',duration_minutes:90,is_recurring:0,use_playlist:1,color:'#7cb4da'},
];
test.use({serviceWorkers:'block',timezoneId:'America/Los_Angeles'});
async function open(page, data = {events,playlist:[{titles:['Эпизод <b>как текст</b>']} ]}) {
    await page.addInitScript(time => Object.defineProperty(window,'serverTime',{get:()=>time/1000,set:()=>{},configurable:true}),NOW);
    await page.route('**/api.php', async route => {
        if(new URLSearchParams(route.request().postData()).get('action')==='get_public_events' || route.request().postData()?.includes('get_public_events')) return route.fulfill({json:{success:true,data}});
        return route.continue();
    });
    await page.goto(BASE+'/schedule.php',{waitUntil:'domcontentloaded'});
    await expect(page.locator('#schedule-events')).toHaveAttribute('aria-busy','false');
}
async function screenshot(page, name, browserName) {if(OUTPUT){fs.mkdirSync(OUTPUT,{recursive:true});await page.screenshot({path:path.join(OUTPUT,`${browserName}-${name}.png`),animations:'disabled'});}}

test('agenda is readable without horizontal overflow on desktop, tablet and narrow phones',async({page,browserName})=>{
    const errors=[];page.on('pageerror',e=>errors.push(e.message));await open(page);
    await expect(page.locator('#current-month-label')).toContainText('Октябрь 2026');
    await expect(page.locator('.schedule-event-card')).toHaveCount(6);
    for(const width of [1440,768,360,320]){
        await page.setViewportSize({width,height:900});
        await expect(page.locator('.schedule-event-title').first()).toBeVisible();
        expect(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth)).toBe(true);
        const b=await page.locator('.schedule-event-card').first().boundingBox();expect(b.x).toBeGreaterThanOrEqual(0);expect(b.x+b.width).toBeLessThanOrEqual(width);
        await screenshot(page,`agenda-${width}`,browserName);
    }
    expect(errors).toEqual([]);
});

test('month navigation, current month and empty month keep next event available',async({page})=>{
    await open(page);await page.getByRole('button',{name:'Предыдущий месяц'}).click();await expect(page.locator('#current-month-label')).toContainText('Сентябрь');await expect(page.locator('.schedule-empty')).toBeVisible();await expect(page.locator('#next-event')).toBeVisible();
    await page.getByRole('button',{name:'Следующий месяц'}).click();await expect(page.locator('.schedule-event-card')).toHaveCount(6);
    await page.getByRole('button',{name:'Следующий месяц'}).click();await expect(page.locator('#current-month-label')).toContainText('Ноябрь');await page.getByRole('button',{name:'Текущий месяц'}).click();await expect(page.locator('#current-month-label')).toContainText('Октябрь');
});

test('Moscow dates, accessible details and UTC ICS preserve midnight and literal text',async({page,browserName})=>{
    await open(page);await page.setViewportSize({width:360,height:780});
    const card=page.getByRole('button',{name:/00:30.*Поздний вечер/});
    await expect(card.locator('xpath=../..').locator('.schedule-date')).toContainText('10');
    await card.click();const dialog=page.getByRole('dialog');await expect(dialog).toBeVisible();await expect(page.locator('#modal-event-date')).toContainText('10 октября');await expect(page.locator('#modal-event-time')).toHaveText('00:30');
    await expect(dialog.locator('img,b')).toHaveCount(0);await expect(page.locator('#modal-playlist-content')).toContainText('Эпизод <b>как текст</b>');expect(await page.evaluate(()=>window.scheduleXss)).toBeUndefined();
    await expect(page.getByRole('button',{name:'Закрыть подробности'})).toBeFocused();await page.keyboard.press('Shift+Tab');await expect(page.getByRole('button',{name:'Добавить в календарь (.ics)'})).toBeFocused();await page.keyboard.press('Tab');await expect(page.getByRole('button',{name:'Закрыть подробности'})).toBeFocused();
    await screenshot(page,'details-360',browserName);
    const [download]=await Promise.all([page.waitForEvent('download'),page.getByRole('button',{name:'Добавить в календарь (.ics)'}).click()]);const content=fs.readFileSync(await download.path(),'utf8');const unfolded=content.replace(/\r\n /g,'');expect(unfolded).toContain('DTSTART:20261009T213000Z');expect(unfolded).toContain('DTEND:20261009T230000Z');expect(unfolded).toContain('SUMMARY:Поздний вечер\\, часть\\; 1');expect(unfolded).toContain('\\nСледующая строка');for(const line of content.split('\r\n'))expect(Buffer.byteLength(line)).toBeLessThanOrEqual(75);
    await page.keyboard.press('Escape');await expect(dialog).toBeHidden();await expect(card).toBeFocused();await card.click();await page.getByRole('button',{name:'Закрыть подробности'}).click();await expect(card).toBeFocused();
});

test('Asia timezone uses the same weekly Moscow occurrence',async({browser})=>{
    const context=await browser.newContext({timezoneId:'Asia/Tokyo',serviceWorkers:'block'});try{const page=await context.newPage();await open(page);const cards=page.locator('.schedule-event-card').filter({hasText:'Поняшный вечерок'});await expect(cards).toHaveCount(5);await expect(cards.first().locator('.schedule-event-time')).toContainText('19:00');await expect(page.locator('#next-event h2')).toHaveText('Поздний вечер, часть; 1');}finally{await context.close();}
});

test('load failure offers retry and empty schedule has a clear state',async({page})=>{
    let attempt=0;await page.route('**/api.php',route=>route.fulfill(attempt++?{json:{success:true,data:{events:[],playlist:[]}}}:{status:503,body:'Unavailable'}));
    await page.goto(BASE+'/schedule.php',{waitUntil:'domcontentloaded'});await expect(page.getByRole('button',{name:'Повторить'})).toBeVisible();await page.getByRole('button',{name:'Повторить'}).click();await expect(page.locator('.schedule-empty')).toBeVisible();await expect(page.locator('#next-event')).toBeHidden();
});

test('authenticated header structure fits narrow screens without changing account state',async({page,browserName})=>{
    await open(page);
    // The layout test uses the exact header branch structure; authentication is unchanged.
    await page.locator('.main-header .header-content').evaluate(header=>{
        const area=document.createElement('div');area.className='user-area';
        const username=document.createElement('span');username.className='username';username.textContent='Привет, Daybreaker! 👋';
        const form=document.createElement('form');form.style.margin='0';const button=document.createElement('button');button.type='submit';button.className='btn-logout';button.textContent='🚪 Выйти';form.append(button);area.append(username,form);header.append(area);
    });
    for(const width of [1440,360,320]){await page.setViewportSize({width,height:900});await expect(page.locator('.btn-logout')).toBeVisible();expect(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth)).toBe(true);await screenshot(page,`auth-header-${width}`,browserName);}
});
