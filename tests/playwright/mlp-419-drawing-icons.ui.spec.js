const {test,expect}=require('@playwright/test');
const {execFileSync}=require('child_process'),fs=require('fs'),path=require('path');
const root=path.resolve(__dirname,'../..'),base=process.env.MLP_BASE_URL,state=path.join(root,'docs/private/mlp400-local.json');
const fixture=action=>execFileSync('docker',['exec','mlp375-php-1','php','tests/playwright/mlp-400-drawing-fixture.php',action],{cwd:root,encoding:'utf8'});
const inspect=()=>JSON.parse(fixture('inspect'));
test.use({serviceWorkers:'block',viewport:{width:1280,height:800},launchOptions:{args:['--no-proxy-server'],firefoxUserPrefs:{'network.proxy.type':0}}});
test.beforeEach(async({page})=>{
 if(!base||!['localhost','127.0.0.1'].includes(new URL(base).hostname))throw Error('Isolated localhost required');
 await page.route('https://**',r=>r.abort());await page.route('**/chat_stream.php*',r=>r.abort());
});
async function send(page,text,quote){
 await page.locator(`.chat-message[data-id="${quote}"] .quote-btn`).click({force:true});await page.locator('#chat-input').fill(text);
 const response=page.waitForResponse(r=>r.url().endsWith('/api.php')&&r.request().postData()?.includes('action=send_message'));
 await page.locator('#chat-form').evaluate(form=>form.requestSubmit());const result=await(await response).json();expect(result.success,result.message).toBe(true);
}
test('edit a finished drawing by quoted request, while praise remains ordinary conversation',async({page})=>{
 try{
  fixture('setup');fixture('auto');const s=JSON.parse(fs.readFileSync(state,'utf8'));
  expect((await(await page.request.post(base+'/api.php',{form:{action:'login',username:s.ownerLogin,password:s.password}})).json()).success).toBe(true);
  await page.goto(base+'/chat_popup.php',{waitUntil:'domcontentloaded'});
  if(await page.locator('#privacy-panel').isVisible())await page.locator('[data-privacy-necessary]').click();
  const original=inspect().drawings[0],originalUrl=inspect().drawingUrls[0];
  await send(page,'Спасибо, очень красиво!',original);fixture('tick');await page.reload({waitUntil:'domcontentloaded'});
  expect(inspect().interactions).toHaveLength(0);await expect(page.locator('.command-interaction')).toHaveCount(0);
  await send(page,'Сделай фон синим',original);
  await expect.poll(()=>{fixture('tick');return inspect().interactions.length;}).toBe(1);
  await page.reload({waitUntil:'domcontentloaded'});
  const interaction=inspect().interactions[0];expect(interaction.description).toBe('Сделай фон синим');expect(interaction.reference).toBe(originalUrl);
  const button=page.locator(`.command-interaction[data-interaction-id="${interaction.id}"]`).getByRole('button',{name:'Применить правки',exact:true});
  await expect(button).toBeEnabled();expect(inspect().drawings).toHaveLength(1);
  const response=page.waitForResponse(r=>r.url().endsWith('/api.php')&&r.request().postData()?.includes('action=act_command_interaction'));
  await button.click();expect((await(await response).json()).success).toBe(true);fixture('tick');await page.reload({waitUntil:'domcontentloaded'});
  expect(inspect().drawings).toHaveLength(2);await expect(page.locator(`.chat-message[data-id="${original}"] .chat-img`)).toBeVisible();
  expect(inspect().drawingUrls).toContain(originalUrl);expect(inspect().drawingUrls[0]).not.toBe(originalUrl);
 }finally{if(fs.existsSync(state))fixture('cleanup');}
});
test('favicon and ordinary PWA icons have real alpha; platform icons and manifest remain valid',async({page},info)=>{
 await page.goto(base+'/login.php',{waitUntil:'domcontentloaded'});
 const favicon=await page.locator('link[rel="icon"]').getAttribute('href');
 const manifestUrl=await page.locator('link[rel="manifest"]').getAttribute('href');
 expect(manifestUrl).toBe('/manifest.json?v=mlp414');
 const manifest=await(await page.request.get(base+manifestUrl)).json();
 expect(manifest.start_url).toBe('/');expect(manifest.scope).toBe('/');
 async function pixels(url){return page.evaluate(async url=>{
  const img=new Image();img.src=url;await img.decode();const canvas=document.createElement('canvas');canvas.width=img.naturalWidth;canvas.height=img.naturalHeight;
  const ctx=canvas.getContext('2d');ctx.drawImage(img,0,0);const data=ctx.getImageData(0,0,canvas.width,canvas.height).data;
  let visible=0;for(let i=3;i<data.length;i+=4)if(data[i]>128)visible++;
  return {width:canvas.width,height:canvas.height,corners:[data[3],data[canvas.width*4-1],data[data.length-canvas.width*4+3],data[data.length-1]],visible:visible/(canvas.width*canvas.height)};
 },url);}
 const fav=await pixels(favicon);expect(fav.corners).toEqual([0,0,0,0]);expect(fav.visible).toBeGreaterThan(.3);expect(fav.visible).toBeLessThan(.8);
 for(const icon of manifest.icons){
  expect(icon.src).toContain('?v=mlp419');const p=await pixels(icon.src);expect(`${p.width}x${p.height}`).toBe(icon.sizes);
  expect(p.corners).toEqual(icon.purpose==='maskable'?[255,255,255,255]:[0,0,0,0]);
 }
 const apple=await pixels(await page.locator('link[rel="apple-touch-icon"]').getAttribute('href'));expect(apple.corners).toEqual([255,255,255,255]);
 await page.setContent(`<style>body{margin:0;font:16px system-ui;background:#eee}.preview{width:720px;padding:24px;display:grid;grid-template-columns:1fr 1fr;gap:20px}.card{padding:25px;border-radius:16px;background:#fff}.card.dark{background:#202134;color:#eee}.row{height:100px;display:flex;gap:30px;align-items:center}small{display:block;color:#888}</style><div class="preview">${['light','dark'].map(theme=>`<div class="card ${theme}"><b>${theme==='light'?'Светлая вкладка':'Тёмная вкладка'}</b><div class="row">${[16,32,64].map(size=>`<span><img width="${size}" height="${size}" src="${base+favicon}"><small>${size} px</small></span>`).join('')}</div></div>`).join('')}</div>`);
 await page.locator('.preview img').evaluateAll(images=>Promise.all(images.map(img=>img.decode())));
 const dir=path.join(root,'docs/tests/MLP-419');fs.mkdirSync(dir,{recursive:true});await page.locator('.preview').screenshot({path:path.join(dir,info.project.name+'-icons.png')});
});
