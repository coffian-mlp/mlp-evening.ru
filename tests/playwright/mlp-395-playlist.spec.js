const {test,expect}=require('@playwright/test');
const {execFileSync}=require('child_process');
const fs=require('fs'),path=require('path');
const base=process.env.MLP_BASE_URL;
function fixture(action){execFileSync('docker',['compose','-p','mlp375','--env-file','.env','-f','docs/tests/MLP-375-docker.yml','exec','-T','php','php','tests/playwright/mlp-395-playlist-fixture.php',action],{cwd:path.resolve(__dirname,'../..'),stdio:'pipe'});}
test('MLP-395 correction endpoint preserves wish fulfilled by later showing',async({request})=>{
 if(!base||!['localhost','127.0.0.1'].includes(new URL(base).hostname))throw Error('isolated localhost required');
 fixture('setup');
 try{
  const s=JSON.parse(fs.readFileSync(path.resolve(__dirname,'../../docs/private/mlp395-local.json'),'utf8'));
  expect((await(await request.post(base+'/api.php',{form:{action:'login',username:s.login,password:s.password}})).json()).success).toBe(true);
  const csrf=(await(await request.get(base+'/')).text()).match(/name="csrf-token"\s+content="([^"]+)"/)?.[1];expect(csrf).toBeTruthy();
  const result=await(await request.post(base+'/api.php',{form:{action:'correct_playlist_completion',snapshot_id:s.snapshot,completion_key:s.A,operation_key:'fixture395:correct:'+s.user,csrf_token:csrf},headers:{'X-CSRF-Token':csrf}})).json();
  expect(result.success).toBe(true);fixture('check');
 }finally{fixture('cleanup');}
});
