const {test,expect}=require('@playwright/test');
const {execFileSync}=require('child_process'),fs=require('fs'),path=require('path');
const {BASE,localFixture,login,post}=require('./mlp-360-helpers');
const root=path.resolve(__dirname,'../..'),state=path.join(root,'docs/private/mlp360-local.json');
const fixture=action=>execFileSync('docker',['exec','mlp375-php-1','php','tests/playwright/mlp-360-fixture.php',action],{cwd:root,encoding:'utf8'});
test.use({serviceWorkers:'block',launchOptions:{args:['--no-proxy-server'],firefoxUserPrefs:{'network.proxy.type':0}}});
test.beforeEach(async({page})=>{
 if(!BASE||!['localhost','127.0.0.1'].includes(new URL(BASE).hostname))throw Error('Isolated localhost required');
 fixture('setup');await page.route('https://**',r=>r.abort());
 await page.addInitScript(()=>{window.EventSource=class{addEventListener(){}close(){}};});
});
test.afterEach(()=>{if(fs.existsSync(state))fixture('cleanup');});
async function open(page,route){
 const data=localFixture(),csrf=await login(page.request,data);await page.goto(BASE+route,{waitUntil:'domcontentloaded'});
 if(await page.locator('#privacy-panel').isVisible())await page.locator('[data-privacy-necessary]').click();
 const message=page.locator(`.chat-message[data-id="${data.messageId}"]`);await expect(message).toBeVisible();return{data,csrf,message,button:message.locator('.add-reaction-btn')};
}
test.describe('touch',()=>{
 test.use({hasTouch:true,viewport:{width:390,height:844}});
 for(const route of ['/','/chat_popup.php'])test('MLP-424 '+route+' first tap never votes; choice survives a pause, closes outside and sends exactly once',async({page},info)=>{
  const {data,csrf,message,button}=await open(page,route);let sent=0;
  page.on('request',r=>{if(r.postData()?.includes('action=toggle_reaction'))sent++;});
  await button.tap();await expect(message.locator('.reaction-picker')).toBeVisible();await expect(button).toHaveAttribute('aria-expanded','true');
  await page.waitForTimeout(1100);await button.dispatchEvent('mouseleave');await expect(message.locator('.reaction-picker')).toBeVisible();expect(sent).toBe(0);
  const history=await post(page.request,csrf,{action:'get_messages',limit:100});expect(history.data.messages.find(m=>Number(m.id)===data.messageId).my_reactions).toEqual([]);
  const dir=path.join(root,'docs/tests/MLP-424');fs.mkdirSync(dir,{recursive:true});await page.screenshot({path:path.join(dir,info.project.name+'-'+(route==='/'?'embedded':'popup')+'.png')});
  const response=page.waitForResponse(r=>r.request().postData()?.includes('action=toggle_reaction'));
  await message.getByRole('button',{name:'Какашка',exact:true}).tap();expect((await(await response).json()).success).toBe(true);
  await expect(message.locator('.reaction-item[data-reaction=poop]')).toHaveClass(/active/);await expect(message.locator('.reaction-item[data-reaction=like]')).toHaveCount(0);await expect(message.locator('.reaction-picker')).toHaveCount(0);expect(sent).toBe(1);
  await button.tap();await button.tap();await expect(message.locator('.reaction-picker')).toHaveCount(0);expect(sent).toBe(1);
  await button.tap();await page.locator('.chat-welcome').click();await expect(message.locator('.reaction-picker')).toHaveCount(0);await expect(button).toHaveAttribute('aria-expanded','false');expect(sent).toBe(1);
  await message.locator('.reaction-item[data-reaction=poop]').tap();await expect(message.locator('.reaction-item[data-reaction=poop]')).toHaveCount(0);expect(sent).toBe(2);
  await page.reload({waitUntil:'domcontentloaded'});await button.tap();await expect(message.locator('.reaction-picker')).toBeVisible();expect(sent).toBe(2);
 });
});
test.describe('mouse and hybrid input',()=>{
 test.use({hasTouch:false,viewport:{width:1280,height:850}});
 test('MLP-424 mouse retains hover and quick like; keyboard opens a choice without voting',async({page})=>{
  const {message,button}=await open(page,'/');let sent=0;page.on('request',r=>{if(r.postData()?.includes('action=toggle_reaction'))sent++;});
  await button.hover();await expect(message.locator('.reaction-picker')).toBeVisible();expect(sent).toBe(0);
  await page.mouse.move(0,0);await expect(message.locator('.reaction-picker')).toHaveCount(0);
  await button.click();await expect(message.locator('.reaction-item[data-reaction=like]')).toHaveClass(/active/);expect(sent).toBe(1);
  await page.mouse.move(0,0);await button.focus();await button.press('Enter');await expect(message.locator('.reaction-picker')).toBeVisible();expect(sent).toBe(1);
  await page.keyboard.press('Escape');await expect(message.locator('.reaction-picker')).toHaveCount(0);await expect(button).toBeFocused();
  await button.press('Space');await message.getByRole('button',{name:'Клоун',exact:true}).press('Enter');await expect(message.locator('.reaction-item[data-reaction=clown]')).toHaveClass(/active/);expect(sent).toBe(2);
 });
 test('MLP-424 a touch pointer on a mouse-capable device does not trigger the default like',async({page})=>{
  const {message,button}=await open(page,'/');let sent=0;page.on('request',r=>{if(r.postData()?.includes('action=toggle_reaction'))sent++;});
  await button.evaluate(el=>{el.dispatchEvent(new PointerEvent('pointerdown',{bubbles:true,pointerType:'touch'}));el.dispatchEvent(new MouseEvent('mouseover',{bubbles:true}));el.dispatchEvent(new MouseEvent('click',{bubbles:true,detail:1}));});
  await expect(message.locator('.reaction-picker')).toBeVisible();await button.dispatchEvent('mouseleave');await expect(message.locator('.reaction-picker')).toBeVisible();expect(sent).toBe(0);
  await page.keyboard.press('Escape');await button.hover();await expect(message.locator('.reaction-picker')).toBeVisible();
 });
});
