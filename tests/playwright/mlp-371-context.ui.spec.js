const { test, expect } = require('@playwright/test');
const { execFileSync } = require('child_process');
const fs = require('fs');
const path = require('path');
const BASE = process.env.MLP_BASE_URL;
const repo = path.resolve(__dirname, '../..');
function cli(mode) {
  if (!BASE || !['localhost','127.0.0.1'].includes(new URL(BASE).hostname)) throw new Error('Isolated localhost required');
  return execFileSync('docker',['compose','-p','mlp359','-f','docker-compose.yml','-f','docs/tests/MLP-359/compose.override.yml','exec','-T','php','php','tests/playwright/mlp-371-context-fixture.php',mode],{cwd:repo,stdio:'pipe'}).toString();
}
let f;
test.beforeAll(()=> { cli('setup'); f=JSON.parse(fs.readFileSync(path.join(repo,'docs/private/mlp371-browser-local.json'),'utf8')); });
test.afterAll(()=> cli('cleanup'));
async function login(page, route) {
  expect((await (await page.request.post(BASE+'/api.php',{form:{action:'login',username:f.login,password:f.password}})).json()).success).toBeTruthy();
  await page.goto(BASE+route,{waitUntil:'domcontentloaded'});
}
test('admin edits a 4000-character cell through the existing memory UI',async({page})=>{
  await login(page,'/dashboard/');
  await page.locator('.nav-tile[data-target="#tab-bot"]').click();
  await page.evaluate(()=>loadMemory());
  const row=page.locator(`tr[data-mem-id="${f.memoryId}"]`);
  await expect(row).toContainText(f.marker);
  const prefix='Длинная заметка '; const text=prefix+ 'я'.repeat(4000-[...prefix].length); // exactly4000 Unicode characters
  expect([...text].length).toBe(4000);
  page.once('dialog',dialog=>dialog.accept(text));
  const saved=page.waitForResponse(r=>r.url().endsWith('/api.php')&&r.request().postData()?.includes('action=save_memory'));
  await row.locator('button').first().click();
  expect((await (await saved).json()).success).toBeTruthy();
  await expect(row.locator('.mem-text')).toHaveText(text);
  const inspected=JSON.parse(cli('inspect')).memory.find(it=>Number(it.id)===f.memoryId);
  expect(inspected.text).toBe(text);
});
test('todo repeat returns the original number and a live duplicate acknowledgement',async({page})=>{
  await login(page,'/');
  async function send(text) {
    await page.locator('#chat-input').fill(text);
    const sent=page.waitForResponse(r=>r.url().endsWith('/api.php')&&r.request().postData()?.includes('action=send_message'));
    await page.locator('#chat-form').evaluate(form=>form.requestSubmit());
    expect((await (await sent).json()).success).toBeTruthy();
    return JSON.parse(cli('worker'));
  }
  const prefix=f.command.command_prefix.startsWith('/')||f.command.command_prefix.startsWith('!')?f.command.command_prefix:'/'+f.command.command_prefix;
  expect((await send(prefix+' '+f.marker+' исправить поиск')).usedLive).toBeTruthy();
  const first=JSON.parse(cli('inspect')).feedback;
  expect(first).toHaveLength(1);
  expect((await send(prefix+' '+f.marker.toUpperCase()+'   исправить   поиск')).duplicate).toBeTruthy();
  const final=JSON.parse(cli('inspect')).feedback;
  expect(final).toEqual(first);
  await page.reload({waitUntil:'domcontentloaded'});
  await expect(page.locator('.chat-message').filter({hasText:`Такая задача уже есть №${first[0].id}`})).toBeVisible();
});
