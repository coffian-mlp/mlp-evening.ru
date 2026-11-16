// Loopback-only HTTP driver into the owned internal Docker network; no external target is accepted.
const http=require('http'),{spawn}=require('child_process'),fs=require('fs'),path=require('path');
const port=8469,origin=`http://127.0.0.1:${port}`;
const root=path.resolve(__dirname,'../../docs/tests/MLP-468/workspace');
if(!fs.existsSync(path.join(root,'.mlp468-owned')))throw Error('Owned synthetic workspace required');
const mime={js:'application/javascript',css:'text/css',png:'image/png',jpg:'image/jpeg',jpeg:'image/jpeg',svg:'image/svg+xml',webp:'image/webp',woff:'font/woff',woff2:'font/woff2',ico:'image/x-icon'};
const server=http.createServer((req,res)=>{
  if(!['GET','POST','HEAD'].includes(req.method)||!req.url.startsWith('/')||req.url.startsWith('//')||
    ![`127.0.0.1:${port}`,`localhost:${port}`].includes(req.headers.host)||
    (req.headers.origin&&!['http://localhost:'+port,origin].includes(req.headers.origin))){res.writeHead(403);return res.end();}
  // Serve unchanged public bytes from the same owned copy; spawning Docker per font/JS is unnecessarily slow.
  const pathname=req.url.split('?')[0],extension=pathname.split('.').pop();
  if(['GET','HEAD'].includes(req.method)&&mime[extension]&&/^\/(?:assets\/|src\/Components\/|upload\/lyra\/)[A-Za-z0-9_./-]+$/.test(pathname)&&!pathname.includes('..')){
    const file=path.join(root,pathname);
    if(fs.existsSync(file)&&fs.realpathSync(file).startsWith(root+path.sep)&&fs.statSync(file).isFile()){
      res.writeHead(200,{'Content-Type':mime[extension]});return res.end(req.method==='HEAD'?undefined:fs.readFileSync(file));
    }
  }
  const chunks=[];let size=0;
  req.on('data',chunk=>{size+=chunk.length;if(size>16777216)req.destroy();else chunks.push(chunk);});
  req.on('end',()=>{
    if(process.env.MLP_DRAWING_TRACE==='1')process.stdout.write(`${req.method} ${req.url}\n`);
    const body=Buffer.concat(chunks),headers=[];
    for(const [key,value]of Object.entries(req.headers))if(!['connection','transfer-encoding','content-length','accept-encoding'].includes(key))headers.push(`${key}: ${value}`);
    headers.push('Connection: close','Accept-Encoding: identity',`Content-Length: ${body.length}`);
    const child=spawn('docker',['exec','-i','mlp468-php-1','php','tests/helpers/drawing_http_tunnel.php'],{stdio:['pipe','pipe','pipe']});
    const output=[];let length=0;
    child.stdout.on('data',chunk=>{length+=chunk.length;if(length>33554432)child.kill();else output.push(chunk);});
    child.stderr.resume();
    child.on('error',()=>{if(!res.headersSent){res.writeHead(502);res.end('Synthetic tunnel unavailable');}});
    child.on('close',code=>{
      if(res.writableEnded)return;
      const raw=Buffer.concat(output),split=raw.indexOf('\r\n\r\n');
      if(code!==0||split<0){res.writeHead(502);return res.end('Synthetic tunnel failed');}
      const lines=raw.subarray(0,split).toString('latin1').split('\r\n'),status=Number(lines.shift().split(' ')[1]);
      const responseHeaders={};
      for(const line of lines){const colon=line.indexOf(':');if(colon<0)continue;const key=line.slice(0,colon).toLowerCase(),value=line.slice(colon+1).trim();
        if(['connection','transfer-encoding','content-length'].includes(key))continue;
        if(key==='set-cookie')(responseHeaders[key]??=[]).push(value);else responseHeaders[key]=value;}
      res.writeHead(status,responseHeaders);res.end(raw.subarray(split+4));
    });
    child.stdin.on('error',()=>{});
    child.stdin.end(Buffer.concat([Buffer.from(`${req.method} ${req.url} HTTP/1.1\r\n${headers.join('\r\n')}\r\n\r\n`),body]));
  });
});
server.listen(port,'127.0.0.1',()=>process.stdout.write(`Synthetic drawing proxy ${origin}\n`));
for(const signal of ['SIGINT','SIGTERM'])process.on(signal,()=>server.close(()=>process.exit(0)));
