const {test,expect}=require('@playwright/test');
const {execFileSync}=require('child_process'),fs=require('fs'),path=require('path');
const root=path.resolve(__dirname,'../..'),state=path.join(root,'docs/private/mlp404-local.json'),base=process.env.MLP_BASE_URL;
const fixture=action=>execFileSync('docker',['exec','mlp375-php-1','php','tests/playwright/mlp-404-delivery-fixture.php',action],{cwd:root,encoding:'utf8'});
test.use({serviceWorkers:'block',launchOptions:{args:['--no-proxy-server'],firefoxUserPrefs:{'network.proxy.type':0}}});
test('inline command survives pause and appears exactly once after recovery',async({page},info)=>{
 if(!base||!['localhost','127.0.0.1'].includes(new URL(base).hostname))throw Error('Isolated localhost required');
 try{
  fixture('setup');const s=JSON.parse(fs.readFileSync(state,'utf8'));
  await page.route('**/chat_stream.php*',r=>r.abort());
  expect((await(await page.request.post(base+'/api.php',{form:{action:'login',username:s.ownerLogin,password:s.password}})).json()).success).toBe(true);
  await page.goto(base+'/chat_popup.php',{waitUntil:'domcontentloaded'});
  await page.locator('#chat-input').fill('/проверкадоставки');
  const sent=page.waitForResponse(r=>r.url().endsWith('/api.php')&&r.request().postData()?.includes('action=send_message'));
  await page.locator('#chat-form').evaluate(form=>form.requestSubmit());expect((await(await sent).json()).success).toBe(true);
  await expect.poll(()=>JSON.parse(fixture('inspect')).jobs[0]?.status).toBe('pending');expect(JSON.parse(fixture('inspect')).replies).toBe(0);
  fixture('recover');await page.reload({waitUntil:'domcontentloaded'});
  await expect(page.locator('.chat-message').filter({hasText:'Ответ команды сохранён и доставлен.'})).toHaveCount(1);
  const before=JSON.parse(fixture('inspect'));expect(before.jobs[0].status).toBe('done');expect(before.jobs[0].receipt).toBeGreaterThan(0);
  fixture('recover');await page.reload({waitUntil:'domcontentloaded'});
  expect(JSON.parse(fixture('inspect'))).toEqual(before);await expect(page.locator('.chat-message').filter({hasText:'Ответ команды сохранён и доставлен.'})).toHaveCount(1);
  const dir=path.join(root,'docs/tests/MLP-404');fs.mkdirSync(dir,{recursive:true});await page.screenshot({path:path.join(dir,info.project.name+'.png')});
 }finally{if(fs.existsSync(state))fixture('cleanup');}
});
