const {test,expect}=require('@playwright/test');
const {execFileSync}=require('child_process');
const fs=require('fs'),path=require('path');
const base=process.env.MLP_BASE_URL;
function fixture(action){execFileSync('docker',['compose','-p','mlp375','--env-file','.env','-f','docs/tests/MLP-375-docker.yml','exec','-T','php','php','tests/playwright/mlp-392-moderation-fixture.php',action],{cwd:path.resolve(__dirname,'../..'),stdio:'pipe'});}
test('MLP-392 moderation API rechecks authority after session role becomes stale',async({request})=>{
 if(!base||!['localhost','127.0.0.1'].includes(new URL(base).hostname))throw Error('isolated localhost required');
 fixture('setup');
 try{
  const s=JSON.parse(fs.readFileSync(path.resolve(__dirname,'../../docs/private/mlp392-local.json'),'utf8'));
  expect((await (await request.post(base+'/api.php',{form:{action:'login',username:s.login,password:s.password}})).json()).success).toBe(true);
  const html=await(await request.get(base+'/')).text();const csrf=html.match(/name="csrf-token"\s+content="([^"]+)"/)?.[1];expect(csrf).toBeTruthy();
  const apply=async(action)=>(await(await request.post(base+'/api.php',{form:{action,user_id:s.target,minutes:15,reason:'owned regression fixture',csrf_token:csrf},headers:{'X-CSRF-Token':csrf}})).json());
  fixture('downgrade');
  for(const action of ['ban_user','mute_user','unban_user','unmute_user']){const result=await apply(action);expect(result.success,action).toBe(false);expect(result.message).toContain('коллег');}
  fixture('revoke');
  for(const action of ['ban_user','mute_user','unban_user','unmute_user']){const result=await apply(action);expect(result.success,action).toBe(false);expect(result.message).toContain('прав');}
 }finally{fixture('cleanup');}
});
