const {test,expect}=require('@playwright/test');
const fs=require('fs'),path=require('path');
const BASE=process.env.MLP_BASE_URL;
function fixture(){if(!['localhost','127.0.0.1'].includes(new URL(BASE).hostname))throw Error('isolated localhost required');return JSON.parse(fs.readFileSync(path.resolve(__dirname,'../../docs/private/mlp391-local.json'),'utf8'));}
test.use({serviceWorkers:'block',launchOptions:{args:['--no-proxy-server'],firefoxUserPrefs:{'network.proxy.type':0}}});
for(const width of [1280,390])test(`MLP-391 worker batch renders ten reactions and clean reply at ${width}px`,async({page},info)=>{
 const s=fixture();await page.setViewportSize({width,height:900});
 const login=await page.request.post(BASE+'/api.php',{form:{action:'login',username:s.login,password:s.password}});expect((await login.json()).success).toBe(true);
 await page.goto(BASE+'/chat_popup.php',{waitUntil:'domcontentloaded'});
 for(const id of s.messages.slice(0,10)){
  const message=page.locator(`.chat-message[data-id="${id}"]`);
  await expect(message).toHaveCount(1);
  const reaction=message.locator('.reaction-item[data-reaction="poop"]');await expect(reaction).toHaveCount(1);await expect(reaction).toContainText('💩');await expect(reaction.locator('.reaction-count')).toHaveText('1');
 }
 const reply=page.locator(`.chat-message[data-id="${s.replyId}"]`);await expect(reply).toContainText('готово!');await expect(reply).not.toContainText('[№34213]');await expect(reply).not.toContainText('РЕАКЦИЯ:');
 await page.reload({waitUntil:'domcontentloaded'});
 for(const id of s.messages.slice(0,10))await expect(page.locator(`.chat-message[data-id="${id}"] .reaction-item[data-reaction="poop"] .reaction-count`)).toHaveText('1');
 const dir=path.resolve(__dirname,'../../docs/tests/MLP-391');fs.mkdirSync(dir,{recursive:true});await reply.scrollIntoViewIfNeeded();await page.screenshot({path:path.join(dir,info.project.name+'-'+width+'.png')});
});
