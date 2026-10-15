const {test,expect}=require('@playwright/test');
const {execFileSync}=require('child_process');
const fs=require('fs'),path=require('path');
const root=path.resolve(__dirname,'../..'),state=path.join(root,'docs/private/mlp399-local.json');
const base=process.env.MLP_BASE_URL;
function fixture(action){execFileSync('docker',['exec','mlp375-php-1','php','tests/playwright/mlp-399-attendance-fixture.php',action],{cwd:root,stdio:'pipe'});}
test.use({serviceWorkers:'block',viewport:{width:360,height:780},launchOptions:{args:['--no-proxy-server'],firefoxUserPrefs:{'network.proxy.type':0}}});
test('attendance: mark, public names, undo, reload and host close',async({page,browser},info)=>{
 test.setTimeout(60000);
 if(!base||!['localhost','127.0.0.1'].includes(new URL(base).hostname))throw Error('Local isolated server required');
 let hostContext;
 try {
  fixture('setup');const s=JSON.parse(fs.readFileSync(state,'utf8'));
  const login=async(request,name)=>expect((await(await request.post(base+'/api.php',{form:{action:'login',username:s[name+'Login'],password:s.password}})).json()).success).toBe(true);
  await login(page.request,'viewer');await page.goto(base+'/chat_popup.php',{waitUntil:'domcontentloaded'});
  const card=page.locator(`.poll-widget[data-poll-id="${s.poll}"]`),button=card.getByRole('button',{name:'Я тут!'});
  await expect(button).toBeVisible();await expect(card.locator('.poll-option')).toHaveCount(1);
  await button.click();await expect(button).toHaveAttribute('aria-pressed','true');await expect(card.locator('.poll-attendance-list')).toContainText('Attendance Viewer');await expect(card).toContainText('Вернулись: 1');
  await page.reload({waitUntil:'domcontentloaded'});await expect(button).toHaveAttribute('aria-pressed','true');
  await button.click();await expect(button).toHaveAttribute('aria-pressed','false');await expect(card).toContainText('Вернулись: 0');await expect(card.locator('.poll-attendance-list')).not.toContainText('Attendance Viewer');
  await expect(card.getByRole('button',{name:'Завершить перекличку'})).toHaveCount(0);
  await button.click();await expect(button).toHaveAttribute('aria-pressed','true');
  hostContext=await browser.newContext({serviceWorkers:'block'});await login(hostContext.request,'host');const host=await hostContext.newPage();await host.goto(base+'/chat_popup.php',{waitUntil:'domcontentloaded'});
  const hostCard=host.locator(`.poll-widget[data-poll-id="${s.poll}"]`);await expect(hostCard.locator('.poll-attendance-list')).toContainText('Attendance Viewer');
  host.on('dialog',d=>d.accept());await hostCard.getByRole('button',{name:'Завершить перекличку'}).click();await expect(hostCard.locator('.poll-attendance-button')).toBeDisabled();
  await hostContext.close();hostContext=null;
  await page.reload({waitUntil:'domcontentloaded'});await expect(button).toBeDisabled({timeout:15000});await expect(card).toContainText('Вернулись: 1');
  const dir=path.join(root,'docs/tests/MLP-399');fs.mkdirSync(dir,{recursive:true});await card.scrollIntoViewIfNeeded();await page.screenshot({path:path.join(dir,info.project.name+'.png')});
 } finally {if(hostContext)await hostContext.close();if(fs.existsSync(state))fixture('cleanup');}
});
