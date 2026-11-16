const {test,expect}=require('@playwright/test');
const fs=require('fs'),path=require('path');
const base=process.env.MLP_BASE_URL;
test.use({serviceWorkers:'block',viewport:{width:420,height:900},launchOptions:{args:['--no-proxy-server'],firefoxUserPrefs:{'network.proxy.type':0}}});
async function fixture(page,action){return test.step('fixture '+action,async()=>{const r=await page.request.post(base+'/_fixture?action='+action,{timeout:action==='tick'?120000:30000});expect(r.ok()).toBe(true);return r.json();});}
async function start(page){
  if(!base||!['localhost','127.0.0.1'].includes(new URL(base).hostname))throw Error('Owned localhost fixture required');
  page.on('pageerror',error=>console.log('Synthetic page error:',error.message));
  const state=await fixture(page,'setup');
  await page.route('**/*',route=>{
    const url=route.request().url();
    return url.startsWith(base)&&!url.includes('/chat_stream.php')?route.continue():route.abort();
  });
  expect((await(await page.request.post(base+'/api.php',{form:{action:'login',username:state.login,password:state.password}})).json()).success).toBe(true);
  await page.goto(base+'/chat_popup.php',{waitUntil:'domcontentloaded'});
  await expect(page.locator('#chat-input')).toBeEditable();
}
async function send(page,text,quote){
  if(quote)await page.locator(`.chat-message[data-id="${quote}"] .quote-btn`).click({force:true});
  await page.locator('#chat-input').fill(text);
  const response=page.waitForResponse(r=>r.url().endsWith('/api.php')&&r.request().postData()?.includes('action=send_message'));
  await page.locator('#chat-form').evaluate(form=>form.requestSubmit());
  const result=await(await response).json();expect(result.success,result.message).toBe(true);
  await expect(page.locator('#chat-input')).toHaveValue('');
}
async function show(page){await page.reload({waitUntil:'domcontentloaded'});return fixture(page,'inspect');}
async function imageVisible(page,id){await expect(page.locator(`.chat-message[data-id="${id}"] .chat-img`)).toBeVisible();}
async function act(page,button){const response=page.waitForResponse(r=>r.url().endsWith('/api.php')&&r.request().postData()?.includes('action=act_command_interaction'));await button.click();const result=await(await response).json();expect(result.success,result.message).toBe(true);}
async function screenshot(page,info,label){const dir=path.resolve(__dirname,'../../docs/tests/MLP-468');fs.mkdirSync(dir,{recursive:true});await page.screenshot({path:path.join(dir,info.project.name+'-'+label+'.png')});}
test('MLP-468: Лира отвечает во время рисунка и один раз уведомляет второй запрос',async({page},info)=>{
  test.setTimeout(180000);let drawing;
  try{
    await start(page);await fixture(page,'block');await send(page,'/нарисуй замок');
    drawing=fixture(page,'tick');await expect.poll(async()=>(await fixture(page,'entered')).entered,{timeout:20000}).toBe(true);
    await send(page,'/нарисуй воздушный шар');await send(page,'@Лаборатория, ответь пока рисуешь');await fixture(page,'tick');
    let s=await show(page);
    expect(s.pictures).toHaveLength(0);
    const reply=s.messages.find(m=>m.text.includes('отвечаю, пока рисую'));expect(reply).toBeTruthy();
    await expect(page.locator(`.chat-message[data-id="${reply.id}"]`)).toContainText('отвечаю, пока рисую');
    const notices=s.messages.filter(m=>m.text.includes('занята другим рисунком'));expect(notices).toHaveLength(1);
    await fixture(page,'advance');await fixture(page,'tick');s=await show(page);
    expect(s.messages.filter(m=>m.text.includes('занята другим рисунком'))).toHaveLength(1);
    await screenshot(page,info,'reply-during-image');
    await fixture(page,'release');await drawing;drawing=null;
    await fixture(page,'advance');
    await fixture(page,'tick');await fixture(page,'tick');s=await show(page);
    expect(s.pictures).toHaveLength(2);expect(s.pictures[0]).toBeGreaterThan(reply.id);
    for(const id of s.pictures)await imageVisible(page,id);
    await fixture(page,'tick');expect((await fixture(page,'inspect')).pictures).toHaveLength(2);
    await expect(page.locator('body')).not.toContainText('drawing_v1');await screenshot(page,info,'delivered');
  }finally{await page.close();await fixture(page,'release').catch(()=>{});if(drawing)await drawing.catch(()=>{});await fixture(page,'cleanup');}
});
test('MLP-468: черновик, правка рисунка и минутный сбор пожеланий используют настоящий worker',async({page},info)=>{
  test.setTimeout(180000);
  try{
    await start(page);await send(page,'/нарисуй черновик замок');await fixture(page,'tick');let s=await show(page);
    let current=s.interactions.at(-1);expect(current).toBeTruthy();expect(s.pictures).toHaveLength(0);
    let button=page.locator(`.command-interaction[data-interaction-id="${current.id}"]`).getByRole('button',{name:'Нарисовать',exact:true});
    await expect(button).toBeEnabled();await act(page,button);await fixture(page,'tick');await fixture(page,'tick');s=await show(page);
    expect(s.pictures).toHaveLength(1);await imageVisible(page,s.pictures[0]);
    await send(page,'/нарисуй сделай фон синим',s.pictures[0]);await fixture(page,'tick');s=await show(page);current=s.interactions.at(-1);
    button=page.locator(`.command-interaction[data-interaction-id="${current.id}"]`).getByRole('button',{name:'Применить правки',exact:true});
    await expect(button).toBeEnabled();await act(page,button);await fixture(page,'tick');await fixture(page,'tick');s=await show(page);expect(s.pictures).toHaveLength(2);
    await send(page,'/нарисуйчат');await fixture(page,'tick');s=await show(page);expect(s.pictures).toHaveLength(2);
    const collection=s.messages.findLast(m=>m.text.includes('минуту'));expect(collection).toBeTruthy();await expect(page.locator(`.chat-message[data-id="${collection.id}"]`)).toContainText('минуту');
    await send(page,'Добавь воздушный шар',collection.id);await fixture(page,'advance');await fixture(page,'tick');await fixture(page,'tick');s=await show(page);
    expect(s.pictures).toHaveLength(3);await imageVisible(page,s.pictures[2]);await screenshot(page,info,'draft-edit-collection');
  }finally{await page.close();await fixture(page,'cleanup');}
});
