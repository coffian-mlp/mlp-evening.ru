const {test,expect}=require('@playwright/test');
const {execFileSync}=require('child_process');
const fs=require('fs'),path=require('path');
const base=process.env.MLP_BASE_URL;
function fixture(action){execFileSync('docker',['compose','-p','mlp375','--env-file','.env','-f','docs/tests/MLP-375-docker.yml','exec','-T','php','php','tests/playwright/mlp-398-inline-fixture.php',action],{cwd:path.resolve(__dirname,'../..'),stdio:'pipe'});}
test.use({serviceWorkers:'block',launchOptions:{args:['--no-proxy-server'],firefoxUserPrefs:{'network.proxy.type':0}}});
test('MLP-398 inline timeout persists a visible retry notice without a worker',async({page},info)=>{
 if(!base||!['localhost','127.0.0.1'].includes(new URL(base).hostname))throw Error('isolated localhost required');
 try{
  fixture('setup');
  const s=JSON.parse(fs.readFileSync(path.resolve(__dirname,'../../docs/private/mlp398-local.json'),'utf8'));
  expect((await(await page.request.post(base+'/api.php',{form:{action:'login',username:s.login,password:s.password}})).json()).success).toBe(true);
  await page.goto(base+'/chat_popup.php',{waitUntil:'domcontentloaded'});
  const notice=page.locator(`.chat-message[data-id="${s.reply}"]`);
  await expect(notice).toContainText('Повтори команду');await expect(notice).toContainText('этот ещё не начинала');
  const dir=path.resolve(__dirname,'../../docs/tests/MLP-398');fs.mkdirSync(dir,{recursive:true});await notice.scrollIntoViewIfNeeded();await page.screenshot({path:path.join(dir,info.project.name+'.png')});
  await page.reload({waitUntil:'domcontentloaded'});await expect(page.locator(`.chat-message[data-id="${s.reply}"]`)).toContainText('Повтори команду');
 }finally{if(fs.existsSync(path.resolve(__dirname,'../../docs/private/mlp398-local.json')))fixture('cleanup');}
});
