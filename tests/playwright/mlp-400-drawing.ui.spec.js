const {test,expect}=require('@playwright/test');
const {execFileSync}=require('child_process'),fs=require('fs'),path=require('path');
const root=path.resolve(__dirname,'../..'),state=path.join(root,'docs/private/mlp400-local.json'),base=process.env.MLP_BASE_URL;
function fixture(action){return execFileSync('docker',['exec','mlp375-php-1','php','tests/playwright/mlp-400-drawing-fixture.php',action],{cwd:root,encoding:'utf8'});}
async function send(page,text,quote){
 if(quote)await page.locator(`.chat-message[data-id="${quote}"] .quote-btn`).click({force:true});
 await page.locator('#chat-input').fill(text);
 const response=page.waitForResponse(r=>r.url().endsWith('/api.php')&&r.request().postData()?.includes('action=send_message'));
 await page.locator('#chat-form').evaluate(form=>form.requestSubmit());const result=await(await response).json();expect(result.success,result.message).toBe(true);
 await expect(page.locator('#chat-input')).toHaveValue('');
}
async function act(page,button){const response=page.waitForResponse(r=>r.url().endsWith('/api.php')&&r.request().postData()?.includes('action=act_command_interaction'));await button.click();expect((await(await response).json()).success).toBe(true);}
test.use({serviceWorkers:'block',viewport:{width:390,height:844},launchOptions:{args:['--no-proxy-server'],firefoxUserPrefs:{'network.proxy.type':0}}});
test('drawing draft: quoted additions, explicit start, and edit with original image',async({page},info)=>{
 test.setTimeout(90000);
 if(!base||!['localhost','127.0.0.1'].includes(new URL(base).hostname))throw Error('Isolated localhost required');
 try{
  fixture('setup');const s=JSON.parse(fs.readFileSync(state,'utf8'));
  await page.route('**/chat_stream.php*',route=>route.abort());
  expect((await(await page.request.post(base+'/api.php',{form:{action:'login',username:s.ownerLogin,password:s.password}})).json()).success).toBe(true);
  await page.goto(base+'/chat_popup.php',{waitUntil:'domcontentloaded'});
  await send(page,'/нарисуй черновик замок');await expect.poll(()=>{fixture('tick');return JSON.parse(fixture('inspect')).interactions.length;}).toBeGreaterThan(0);await page.reload({waitUntil:'domcontentloaded'});
  let current=JSON.parse(fixture('inspect')).interactions.at(-1),widget=()=>page.locator(`.command-interaction[data-interaction-id="${current.id}"]`);
  await expect(widget().getByRole('button',{name:'Нарисовать',exact:true})).toBeEnabled();expect(JSON.parse(fixture('inspect')).drawings).toHaveLength(0);
  await send(page,'на облаке',current.message);fixture('tick');await page.reload({waitUntil:'domcontentloaded'});
  current=JSON.parse(fixture('inspect')).interactions.at(-1);expect(current.description).toBe('замок\nна облаке');expect(JSON.parse(fixture('inspect')).drawings).toHaveLength(0);
  await act(page,widget().getByRole('button',{name:'Нарисовать',exact:true}));fixture('tick');await page.reload({waitUntil:'domcontentloaded'});
  const drawing=JSON.parse(fixture('inspect')).drawings[0];await expect(page.locator(`.chat-message[data-id="${drawing}"] .chat-img`)).toBeVisible();
  await send(page,'/нарисуй сделай фон синим',drawing);fixture('tick');await page.reload({waitUntil:'domcontentloaded'});
  current=JSON.parse(fixture('inspect')).interactions.at(-1);expect(current.reference).toBe(JSON.parse(fixture('inspect')).drawingUrls[0]);expect(current.description).toBe('сделай фон синим');
  await widget().scrollIntoViewIfNeeded();await expect(widget().getByRole('button',{name:'Применить правки',exact:true})).toBeEnabled();
  await act(page,widget().getByRole('button',{name:'Применить правки',exact:true}));fixture('tick');await page.reload({waitUntil:'domcontentloaded'});
  expect(JSON.parse(fixture('inspect')).drawings).toHaveLength(2);
  const dir=path.join(root,'docs/tests/MLP-400');fs.mkdirSync(dir,{recursive:true});await page.screenshot({path:path.join(dir,info.project.name+'.png')});
 }catch(error){if(fs.existsSync(state))console.log(fixture('inspect'));throw error;}finally{if(fs.existsSync(state))fixture('cleanup');}
});
