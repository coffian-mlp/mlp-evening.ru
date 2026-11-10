const { test, expect } = require('@playwright/test');
const { execFileSync } = require('child_process');
const path = require('path');
const repo = path.resolve(__dirname, '../..');
const task = path.join(repo, 'docs/tests/MLP-456/phase3-implementation');
const base = process.env.MLP_BASE_URL;
const local = base && ['localhost','127.0.0.1',...(process.env.MLP_FIXTURE_URL?['php']:[])].includes(new URL(base).hostname);
test.skip(!local, 'Disposable local fake-provider environment required');
async function fixture(mode) {
 if(process.env.MLP_FIXTURE_URL){const response=await fetch(process.env.MLP_FIXTURE_URL+'?mode='+mode);if(!response.ok)throw new Error('Synthetic fixture failed');return response.json();}
 return JSON.parse(execFileSync('docker',['compose','-p','mlp456phase3','-f',path.join(task,'workspace/docker-compose.yml'),'--env-file',path.join(task,'workspace/.env'),'-f',path.join(task,'compose.yml'),'exec','-T','-e','MLP_CONVERSATION_PW=1','php','php','tests/playwright/mlp-456-conversation-fixture.php',mode],{cwd:repo}).toString());
}
test.use({ serviceWorkers:'block' });
for (const scenario of ['greeting','mention','off']) test(`MLP-456 return memory: ${scenario}`, async ({ page }) => {
 const state=await fixture('setup');
 const blocked=[];await page.route('**/*',route=>{const url=new URL(route.request().url());const host=url.hostname;if(!['php','localhost','127.0.0.1'].includes(host)){blocked.push(host);return route.abort();}if(url.pathname==='/chat_stream.php')return route.fulfill({status:200,contentType:'text/event-stream',body:'retry: 60000\n\n'});return route.continue();});
 try {
  if(scenario==='off')await fixture('off');
  const login=await page.request.post(base+'/api.php',{form:{action:'login',username:state.login,password:state.password}});
  expect((await login.json()).success).toBe(true);
  await page.goto(base+'/chat_popup.php',{waitUntil:'domcontentloaded'});
  if(scenario==='mention'){
   await page.locator('#chat-input').fill('@Синтетик, привет!');
   const sent=page.waitForResponse(r=>r.url().endsWith('/api.php')&&new URLSearchParams(r.request().postData()||'').get('action')==='send_message');
   await page.locator('#chat-form').evaluate(form=>form.requestSubmit());
   expect((await (await sent).json()).success).toBe(true);
  }
  await fixture('work');
  const after=await fixture('inspect');
  expect(after.messages).toHaveLength(1);
  expect(after.messages[0].includes('чайник')).toBe(scenario!=='off');
  if(scenario!=='off')expect(after.arrivals.state).toBe('done');
  await page.reload({waitUntil:'domcontentloaded'});
  await expect(page.locator(`.chat-message`).filter({hasText:after.messages[0]}).first()).toBeVisible();
  await fixture('work');
  expect((await fixture('inspect')).messages).toHaveLength(1);
 } finally { await fixture('cleanup');expect(blocked).toEqual([]); }
});
