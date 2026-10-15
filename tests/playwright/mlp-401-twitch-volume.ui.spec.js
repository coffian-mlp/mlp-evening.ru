const {test,expect}=require('@playwright/test');
const {execFileSync}=require('child_process');
const path=require('path'),fs=require('fs');
const root=path.resolve(__dirname,'../..'),base=process.env.MLP_BASE_URL,key='mlp.twitch.volume';
const sdk=`
window.__players=[];
window.Twitch={Player:class {
 constructor(id,options){this.options=options;this.volume=0.5;this.calls=[];this.listeners={};this.acceptVolume=!window.__offlineStart;window.__players.push(this);
 const iframe=document.createElement('iframe');document.getElementById(id).appendChild(iframe);
 const doc=iframe.contentDocument;doc.open();doc.write('<label>Volume<input type="range" min="0" max="1" step="0.01" value="0.5"></label>');doc.close();
 this.range=doc.querySelector('input');this.range.addEventListener('input',()=>{this.volume=Number(this.range.value);});
 setTimeout(()=>{this.listeners.ready();window.__playerReady=true;},0);}
 addEventListener(name,callback){this.listeners[name]=callback;}
 setVolume(value){this.calls.push(value);if(!this.acceptVolume)return;const apply=()=>{this.volume=value;this.range.value=value;};if(window.__deferVolume)window.__applyVolume=apply;else apply();}
 getVolume(){return this.volume;}
}};Twitch.Player.READY='ready';Twitch.Player.PLAY='play';Twitch.Player.PLAYING='playing';`;
test.use({serviceWorkers:'block',launchOptions:{args:['--no-proxy-server'],firefoxUserPrefs:{'network.proxy.type':0}}});
async function fixture(page,source,{failSdk=false}={}){
 if(!base||!['localhost','127.0.0.1'].includes(new URL(base).hostname))throw Error('Isolated localhost required');
 const html=execFileSync('docker',['exec','mlp375-php-1','php','tests/playwright/mlp-401-player-fixture.php',source],{cwd:root,encoding:'utf8'});
 await page.route(base+'/__mlp401',route=>route.fulfill({contentType:'text/html',body:html}));
 await page.route('https://player.twitch.tv/js/embed/v1.js',route=>failSdk?route.abort():route.fulfill({contentType:'text/javascript',body:sdk}));
 await page.route('https://goodgame.ru/**',route=>route.fulfill({contentType:'text/html',body:'<p>Other provider</p>'}));
 await page.goto(base+'/__mlp401',{waitUntil:'load'});
}
test('Twitch component preserves volume and zero after reload',async({page},info)=>{
 await fixture(page,'https://player.twitch.tv/?channel=coffian&parent=mlp-evening.ru&autoplay=false&muted=true');
 await page.waitForFunction(()=>window.__playerReady);
 const slider=()=>page.frameLocator('.twitch-player iframe').getByRole('slider');
 await slider().press('Home');await expect.poll(()=>page.evaluate(k=>localStorage.getItem(k),key)).toBe('0');
 await page.reload({waitUntil:'load'});await page.waitForFunction(()=>window.__playerReady);
 expect(await page.evaluate(()=>window.__players[0].calls)).toEqual([0]);
 await slider().press('ArrowRight');await slider().press('ArrowRight');
 await expect.poll(()=>page.evaluate(k=>localStorage.getItem(k),key)).toBe('0.02');
 await page.reload({waitUntil:'load'});await page.waitForFunction(()=>window.__playerReady);
 expect(await page.evaluate(()=>window.__players[0].calls)).toEqual([0.02]);
 const options=await page.evaluate(()=>window.__players[0].options);expect(options.channel).toBe('coffian');expect(options.parent).toContain('127.0.0.1');expect(options.parent).toContain('mlp-evening.ru');expect(options.autoplay).toBe(false);expect(options.muted).toBe(true);
 const bounds=await page.locator('.twitch-player').boundingBox();expect(bounds.height).toBeGreaterThan(300);
 const dir=path.join(root,'docs/tests/MLP-401');fs.mkdirSync(dir,{recursive:true});await page.screenshot({path:path.join(dir,info.project.name+'.png')});
});
test('delayed SDK restore does not overwrite a saved volume',async({page})=>{
 await page.addInitScript(k=>{localStorage.setItem(k,'0.23');window.__deferVolume=true;},key);
 await fixture(page,'https://twitch.tv/coffian');await page.waitForFunction(()=>window.__playerReady);
 await page.waitForTimeout(1100);expect(await page.evaluate(k=>localStorage.getItem(k),key)).toBe('0.23');
 await page.evaluate(()=>window.__applyVolume());await expect.poll(()=>page.evaluate(()=>window.__players[0].getVolume())).toBe(0.23);
});
test('invalid storage and denied storage do not prevent playback',async({page})=>{
 const errors=[];page.on('pageerror',e=>errors.push(e.message));
 await page.addInitScript(k=>{localStorage.setItem(k,'broken');Storage.prototype.setItem=function(){throw new DOMException('denied','SecurityError');};},key);
 await fixture(page,'https://player.twitch.tv/?video=v1234&time=2m3s');await page.waitForFunction(()=>window.__playerReady);
 expect(await page.evaluate(()=>window.__players[0].calls)).toEqual([]);await page.waitForTimeout(600);expect(errors).toEqual([]);
});
test('offline player restores its preference when playback begins',async({page})=>{
 await page.addInitScript(k=>{localStorage.setItem(k,'0.23');window.__offlineStart=true;},key);
 await fixture(page,'https://player.twitch.tv/?channel=coffian');await page.waitForFunction(()=>window.__playerReady);
 await page.waitForTimeout(600);expect(await page.evaluate(k=>localStorage.getItem(k),key)).toBe('0.23');
 await page.evaluate(()=>{const player=window.__players[0];player.volume=1;player.listeners.play();});
 expect(await page.evaluate(k=>localStorage.getItem(k),key)).toBe('0.23');
 await page.evaluate(()=>{const player=window.__players[0];player.acceptVolume=true;player.listeners.playing();});
 expect(await page.evaluate(()=>window.__players[0].getVolume())).toBe(0.23);
 await page.frameLocator('.twitch-player iframe').getByRole('slider').press('Home');
 await expect.poll(()=>page.evaluate(k=>localStorage.getItem(k),key)).toBe('0');
});
test('other providers retain their iframe and do not load Twitch or change preferences',async({page})=>{
 let calls=0;page.on('request',request=>{if(request.url().startsWith('https://player.twitch.tv/'))calls++;});
 await page.addInitScript(k=>localStorage.setItem(k,'0.37'),key);
 await fixture(page,'https://goodgame.ru/player?161438#autoplay');
 await expect(page.locator('.video-content > iframe')).toHaveAttribute('src','https://goodgame.ru/player?161438#autoplay');expect(calls).toBe(0);expect(await page.evaluate(k=>localStorage.getItem(k),key)).toBe('0.37');
});
test('SDK failure falls back to the configured iframe',async({page})=>{
 await page.route('https://player.twitch.tv/?**',route=>route.fulfill({contentType:'text/html',body:'<p>Twitch iframe fallback</p>'}));
 const source='https://player.twitch.tv/?channel=coffian&parent=mlp-evening.ru';await fixture(page,source,{failSdk:true});
 await expect(page.locator('.video-content > iframe')).toHaveAttribute('src',source);
});
