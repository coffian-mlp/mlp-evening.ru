const {test,expect}=require('@playwright/test');
const fs=require('node:fs'),path=require('node:path'),http=require('node:http');
const root=path.resolve(__dirname,'../..');let server,base;
test.use({serviceWorkers:'block',viewport:{width:1100,height:800},launchOptions:{args:['--no-proxy-server'],firefoxUserPrefs:{'network.proxy.type':0}}});
test.beforeAll(async()=>{
 server=http.createServer((req,res)=>{
  const url=new URL(req.url,'http://localhost');
  if(url.pathname==='/')return res.end('<!doctype html><meta charset="utf-8"><body>');
  const name=url.pathname.slice(1);
  if(!/^(?:favicon\.png|assets\/img\/(?:icon-(?:192|512|maskable-512)\.png|apple-touch-icon\.png|player\/lyra-buffering-(?:still-)?v2\.webp))$/.test(name)){res.writeHead(404);return res.end();}
  res.setHeader('Content-Type',name.endsWith('.png')?'image/png':'image/webp');res.end(fs.readFileSync(path.join(root,name)));
 });await new Promise(resolve=>server.listen(0,'127.0.0.1',resolve));base='http://127.0.0.1:'+server.address().port;
});
test.afterAll(async()=>{await new Promise(resolve=>server.close(resolve));});
test('MLP-450 transparent animated WebP decodes, moves and has a seamless eight-second loop container',async({page},info)=>{
 const bytes=fs.readFileSync(path.join(root,'assets/img/player/lyra-buffering-v2.webp')),durations=[];let loops,bg;
 expect(bytes.subarray(0,4).toString()).toBe('RIFF');expect(bytes.subarray(8,12).toString()).toBe('WEBP');
 for(let offset=12;offset+8<=bytes.length;){const tag=bytes.toString('ascii',offset,offset+4),length=bytes.readUInt32LE(offset+4),data=offset+8;
  if(tag==='ANIM'){bg=bytes.readUInt32LE(data);loops=bytes.readUInt16LE(data+4);}
  if(tag==='ANMF')durations.push(bytes.readUIntLE(data+12,3));offset=data+length+(length%2);
 }
 expect(bg).toBe(0);expect(loops).toBe(0);expect(durations).toHaveLength(200);expect(durations.reduce((a,b)=>a+b,0)).toBe(8000);
 await page.goto(base);await page.setContent(`<style>body{margin:0}.stage{display:flex}.card{background:#050508}.card.light{background:#f1edf8}img{display:block;width:420px;height:280px}</style><div class="stage"><div class="card"><img id="animation" src="${base}/assets/img/player/lyra-buffering-v2.webp"></div><div class="card light"><img src="${base}/assets/img/player/lyra-buffering-still-v2.webp"></div></div>`);
 await page.locator('img').evaluateAll(images=>Promise.all(images.map(im=>im.decode())));
 const first=await page.locator('#animation').evaluate(im=>{const c=document.createElement('canvas');c.width=im.naturalWidth;c.height=im.naturalHeight;const ctx=c.getContext('2d');ctx.drawImage(im,0,0);const p=ctx.getImageData(0,0,c.width,c.height).data;return{w:c.width,h:c.height,corners:[p[3],p[c.width*4-1],p[p.length-c.width*4+3],p[p.length-1]]};});
 expect([first.w,first.h]).toEqual([420,280]);expect(first.corners).toEqual([0,0,0,0]);
 // Canvas uses the image's default frame; compare actual browser rendering to verify motion.
 const rendered=await page.locator('#animation').screenshot({animations:'allow'});
 await expect.poll(async()=>rendered.equals(await page.locator('#animation').screenshot({animations:'allow'})),{timeout:3000}).toBe(false);
 const dir=path.join(root,'docs/tests/MLP-450');fs.mkdirSync(dir,{recursive:true});await page.locator('.stage').screenshot({path:path.join(dir,info.project.name+'-artwork.png')});
});
test('MLP-450 icon family is transparent where needed and maskable artwork fits its safe circle',async({page},info)=>{
 await page.goto(base);
 const stats=await page.evaluate(async()=>{
  const files=[['/favicon.png',64,false],['/assets/img/icon-192.png',192,false],['/assets/img/icon-512.png',512,false],['/assets/img/icon-maskable-512.png',512,true],['/assets/img/apple-touch-icon.png',180,true]],out=[];
  for(const [file,size,opaque] of files){const im=new Image();im.src=file;await im.decode();const c=document.createElement('canvas');c.width=im.naturalWidth;c.height=im.naturalHeight;const ctx=c.getContext('2d');ctx.drawImage(im,0,0);const data=ctx.getImageData(0,0,c.width,c.height).data;let outside=0,visible=0;
   for(let y=0;y<c.height;y++)for(let x=0;x<c.width;x++){const i=(y*c.width+x)*4;if(data[i+3]>128)visible++;if(file.includes('maskable')&&Math.hypot(x-c.width/2,y-c.height/2)>c.width*.4+1&&(data[i]!==25||data[i+1]!==19||data[i+2]!==51))outside++;}
   out.push({file,size,opaque,w:c.width,h:c.height,corners:[data[3],data[c.width*4-1],data[data.length-c.width*4+3],data[data.length-1]],outside,visible});
  }return out;
 });
 for(const s of stats){expect([s.w,s.h]).toEqual([s.size,s.size]);expect(s.corners).toEqual(s.opaque?[255,255,255,255]:[0,0,0,0]);expect(s.outside).toBe(0);expect(s.visible).toBeGreaterThan(s.size*s.size*.3);}
 await page.setContent(`<style>body{margin:0;font:16px system-ui}.row{display:flex;align-items:center;gap:24px;padding:24px;background:#f1edf8}.row.dark{background:#191333;color:white}</style>${['light','dark'].map(theme=>`<div class="row ${theme}">${[16,32,64].map(size=>`<img src="${base}/favicon.png" width="${size}" height="${size}">`).join('')}<img src="${base}/assets/img/icon-maskable-512.png" width="128" height="128"><img src="${base}/assets/img/apple-touch-icon.png" width="90" height="90"></div>`).join('')}`);
 await page.locator('img').evaluateAll(images=>Promise.all(images.map(im=>im.decode())));const dir=path.join(root,'docs/tests/MLP-450');fs.mkdirSync(dir,{recursive:true});await page.screenshot({path:path.join(dir,info.project.name+'-icons.png')});
});
