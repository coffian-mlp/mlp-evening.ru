const {test,expect}=require('@playwright/test');
const {execFileSync}=require('node:child_process');
const fs=require('node:fs'),path=require('node:path');
const base=process.env.MLP_BASE_URL;
const local=!!base&&['127.0.0.1','localhost'].includes(new URL(base).hostname);
const output=path.resolve(__dirname,'../../docs/tests/MLP-472');
test.skip(!local,'Explicit localhost and owned disposable Docker database required');
function fixture(mode){return JSON.parse(execFileSync('docker',['exec','mlp472-php-1','php','tests/playwright/mlp-472-playlist-editor-fixture.php',mode],{encoding:'utf8'}));}
async function login(request,user){const r=await request.post(base+'/api.php',{form:{action:'login',username:user.login,password:user.password}});expect((await r.json()).success).toBe(true);}
async function privacy(page){
    await page.waitForFunction(()=>window.privacyChoices!==undefined);
    if(!await page.evaluate(()=>window.privacyChoices.decided)){
        const saved=page.waitForResponse(r=>r.url()===base+'/api.php'&&r.request().postData()?.includes('privacy_choices'));
        await page.locator('[data-privacy-necessary]').click();expect((await (await saved).json()).success).toBe(true);
        await expect(page.locator('#privacy-panel')).toBeHidden();
    }
}
test.use({serviceWorkers:'block',viewport:{width:1440,height:1000}});
test('MLP-472: админ заменяет, добавляет, переставляет и удаляет; изменения сохраняются и защищены',async({page,browser,playwright},info)=>{
    test.setTimeout(240000);
    const errors=[];let s;
    page.on('pageerror',e=>errors.push(e.message));page.on('console',m=>{if(m.type()==='error')errors.push(m.text());});
    const route=async route=>{const u=new URL(route.request().url());if(u.origin!==new URL(base).origin)return route.fulfill({status:200,body:''});if(u.pathname==='/chat_stream.php')return route.fulfill({contentType:'text/event-stream',body:': fixture\n\n'});return route.continue();};
    await page.route('**/*',route);
    try{
        s=fixture('setup');await login(page.request,s.users.admin);
        await page.goto(base+'/dashboard/#tab-episodes',{waitUntil:'domcontentloaded'});await privacy(page);
        const root=page.locator('#playlist-editor'),items=root.locator('[data-editor-list]>li');
        await expect(items).toHaveCount(2);await expect(root.locator('[data-editor-announcement]')).toBeVisible();
        const csrf=await page.locator('meta[name="csrf-token"]').getAttribute('content');
        const original=fixture('inspect');
        async function saved(action){const r=page.waitForResponse(r=>r.url()===base+'/api.php'&&r.request().postData()?.includes('action=edit_upcoming_playlist'));await action();expect((await (await r).json()).success).toBe(true);await expect(root.locator('[data-editor-status]')).toHaveText('Плейлист сохранён.');}
        async function choose(name){await root.locator('#playlist-story-query').fill(s.marker+' '+name);await root.getByRole('button',{name:'Найти',exact:true}).click();await expect(root.locator('[data-editor-results] button')).toHaveCount(1);await saved(()=>root.locator('[data-editor-results] button').click());}
        await items.nth(0).getByRole('button',{name:'Заменить',exact:true}).click();await choose('Gamma');
        await expect(items.nth(0)).toContainText('Gamma');
        await root.locator('[data-editor-add]').click();await choose('Movie');await expect(items).toHaveCount(3);await expect(root.locator('[data-editor-duration]')).toContainText('длиннее эфира');
        await saved(()=>items.nth(2).getByRole('button',{name:'Вверх',exact:true}).click());await expect(items.nth(1)).toContainText('Movie');
        await saved(()=>items.nth(2).getByRole('button',{name:'Удалить',exact:true}).click());await expect(items).toHaveCount(2);
        await root.locator('[data-editor-add]').click();await choose('Part B');await expect(items.nth(2)).toContainText('Part A');await expect(items.nth(2)).toContainText('Part B');
        await page.screenshot({path:path.join(output,info.project.name+'-editor-desktop.png')});
        await page.reload({waitUntil:'domcontentloaded'});await expect(items).toHaveCount(3);await expect(items.nth(0)).toContainText('Gamma');await expect(items.nth(1)).toContainText('Movie');await expect(items.nth(2)).toContainText('Part B');
        const state=fixture('inspect');
        const api=async(form,request=page.request)=>(await request.post(base+'/api.php',{form}));
        const stale=await api({action:'edit_upcoming_playlist',csrf_token:csrf,run_id:s.run,snapshot_id:String(s.snapshot),version:original.version,operation:'remove',index:'0'});expect((await stale.json()).message).toContain('другом окне');
        const noCsrf=await api({action:'edit_upcoming_playlist',run_id:s.run,snapshot_id:String(s.snapshot),version:state.version,operation:'remove',index:'0'});expect((await noCsrf.json()).success).toBe(false);
        const malformed=await api({action:'edit_upcoming_playlist',csrf_token:csrf,run_id:s.run,snapshot_id:String(s.snapshot),version:state.version,operation:'remove',index:'bad'});expect((await malformed.json()).success).toBe(false);
        const search=await api({action:'search_playlist_stories',csrf_token:csrf,query:"' OR 1=1 --"});expect((await search.json()).data.stories).toEqual([]);
        const publicData=await (await api({action:'get_public_events'})).json();expect(publicData.data.playlists_by_run[s.run]).toEqual(state.stories);
        const ordinary=await playwright.request.newContext();
        try{
            await login(ordinary,s.users.user);const html=await (await ordinary.get(base+'/')).text();const token=html.match(/name="csrf-token" content="([^"]+)"/)[1];
            for(const action of ['edit_upcoming_playlist','search_playlist_stories']){const denied=await api({action,csrf_token:token,query:s.marker,run_id:s.run,snapshot_id:String(s.snapshot),version:state.version,operation:'remove',index:'0'},ordinary);expect(denied.status()).toBe(403);expect((await denied.json()).success).toBe(false);}
            const dashboard=await ordinary.get(base+'/dashboard/');expect(dashboard.status()).toBe(403);expect(await dashboard.text()).not.toContain('id="playlist-editor"');
        }finally{await ordinary.dispose();}
        const guest=await playwright.request.newContext();try{const denied=await api({action:'edit_upcoming_playlist'},guest);expect((await denied.json()).success).toBe(false);}finally{await guest.dispose();}
        await page.setViewportSize({width:360,height:780});await root.scrollIntoViewIfNeeded();await root.locator('[data-editor-add]').click();await root.locator('#playlist-story-query').fill(s.marker+' Alpha');await root.getByRole('button',{name:'Найти',exact:true}).click();await expect(root.locator('[data-editor-results] button')).toBeVisible();
        await page.screenshot({path:path.join(output,info.project.name+'-editor-mobile.png')});expect(await root.evaluate(el=>el.getBoundingClientRect().width<=innerWidth)).toBe(true);expect(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth)).toBe(true);
        await page.evaluate(()=>document.documentElement.dataset.theme='luna');
        await root.screenshot({path:path.join(output,info.project.name+'-editor-dark-mobile.png')});
        expect(await root.evaluate(el=>el.getBoundingClientRect().width<=innerWidth)).toBe(true);
        await page.evaluate(()=>document.documentElement.dataset.theme='celestia');
        await page.goto(base+'/schedule.php',{waitUntil:'domcontentloaded'});await privacy(page);await expect(page.locator('#schedule-events')).toHaveAttribute('aria-busy','false');await page.locator('.schedule-event-card').filter({hasText:s.marker}).click();await expect(page.locator('#modal-playlist-content')).toContainText('Gamma');await expect(page.locator('#modal-playlist-content')).toContainText('Movie');await expect(page.locator('#modal-playlist-content')).not.toContainText('Beta');await page.screenshot({path:path.join(output,info.project.name+'-calendar-mobile.png')});
        expect(errors).toEqual([]);
    }finally{fs.writeFileSync(path.join(output,info.project.name+'-console.json'),JSON.stringify(errors));if(s)fixture('cleanup');}
});
