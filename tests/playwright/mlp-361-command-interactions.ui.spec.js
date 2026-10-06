const { test, expect } = require('@playwright/test');
const { execFileSync } = require('child_process');
const fs = require('fs');
const path = require('path');
const BASE = process.env.MLP_BASE_URL;
const repo = path.resolve(__dirname, '../..');
function cli(...args) {
  if (!BASE || !['localhost','127.0.0.1'].includes(new URL(BASE).hostname)) throw new Error('Isolated localhost required');
  return execFileSync('docker', ['compose','-p','mlp359','-f','docker-compose.yml','-f','docs/tests/MLP-359/compose.override.yml','exec','-T','php','php','tests/playwright/mlp-361-interactions-fixture.php',...args], { cwd: repo, stdio:'pipe' }).toString();
}
function fixture(key) {
  cli('case', key);
  return JSON.parse(fs.readFileSync(path.join(repo,'docs/private/mlp361-interactions-local.json'),'utf8')).cases[key];
}
async function login(page, user) {
  const result = await (await page.request.post(BASE+'/api.php',{form:{action:'login',username:user.login,password:user.password}})).json();
  expect(result.success).toBeTruthy();
}
async function post(page, data, csrf) {
  return (await page.request.post(BASE+'/api.php', { timeout:10000, form: { ...data, ...(csrf ? {csrf_token:csrf}: {}) } })).json();
}
test.afterEach(async ({page},info) => {
  if (info.status === info.expectedStatus) return;
  const state = await page.locator('.command-interaction').evaluateAll(nodes => nodes.map(e=>({id:e.dataset.interactionId,text:e.textContent,mounted:e._commandMounted,visible:e._commandVisible,loading:e._commandLoading,state:e._commandState,rect:e.getBoundingClientRect().toJSON()}))).catch(()=>[]);
  await info.attach('interaction-state',{body:JSON.stringify(state),contentType:'application/json'});
});
function widget(page, f) { return page.locator(`.chat-message[data-id="${f.messageId}"] .command-interaction`); }
async function reveal(locator) { await expect(async () => { await locator.scrollIntoViewIfNeeded(); }).toPass({timeout:10000}); }
test('MLP-361 bang command through actual chat HTTP queues with AI disabled', async ({page,browserName}) => {
  test.skip(!BASE,'MLP_BASE_URL required');
  const key = `${browserName}_command_http`;
  const f = fixture(key);
  cli('age-messages',key);
  await login(page,f);
  await page.goto(BASE+'/',{waitUntil:'domcontentloaded'});
  const csrf = await page.locator('meta[name="csrf-token"]').getAttribute('content');
  const result = await post(page,{action:'send_message',message:'!хочу 1'},csrf);
  expect(result.success).toBeTruthy();
  await expect.poll(() => JSON.parse(cli('queued-command',key)).count).toBe(1);
});
for (const route of ['/', '/chat_popup.php']) for (const width of [1280,360]) {
  test(`MLP-361 buttons ${route} ${width}px history, safe labels, cancel and reload`, async ({page,browserName}) => {
    test.skip(!BASE, 'MLP_BASE_URL required');
    const key = `${browserName}_${route==='/'?'embedded':'popup'}_${width}`;
    const f = fixture(key);
    await login(page,f); await page.setViewportSize({width,height:800});
    await page.goto(BASE+route,{waitUntil:'domcontentloaded'});
    const choice = widget(page,f);
    await reveal(choice);
    await expect(choice.getByRole('button',{name:'Отмена'})).toBeEnabled();
    await expect(choice.locator('img')).toHaveCount(0);
    await expect(choice.getByRole('button').first()).toContainText('<img src=x');
    await expect(page.locator(`.chat-message[data-id="${f.fakeId}"] .command-interaction button`)).toHaveCount(0);
    await expect(page.locator(`.chat-message[data-id="${f.quoteId}"] .command-interaction`)).toHaveCount(0);
    const box = await choice.boundingBox(); expect(box.width).toBeLessThanOrEqual(width);
    await choice.getByRole('button',{name:'Отмена'}).click();
    await expect(choice).toContainText('Выбор отменён');
    await expect(choice.getByRole('button')).toHaveCount(0);
    await page.reload({waitUntil:'domcontentloaded'});
    await reveal(widget(page,f));
    await expect(widget(page,f)).toContainText('Выбор отменён');
  });
}
test('MLP-361 real SSE delivers a new bound choice without reload', async ({page,browserName}) => {
  test.skip(!BASE,'MLP_BASE_URL required');
  await page.goto(BASE+'/',{waitUntil:'domcontentloaded'});
  await page.waitForTimeout(1000);
  const f = fixture(`${browserName}_realtime`);
  await expect(widget(page,f)).toBeVisible({timeout:15000});
  await reveal(widget(page,f));
  await expect(widget(page,f).getByRole('button',{name:'Отмена'})).toBeDisabled();
});
test('MLP-361 API rejects guest, foreign, CSRF, tampered choice and late sanction', async ({page,browserName}) => {
  test.skip(!BASE,'MLP_BASE_URL required');
  const key = `${browserName}_security`;
  const f = fixture(key);
  const mutation = {action:'act_command_interaction',interaction_id:String(f.interactionId),option_key:'one'};
  expect((await post(page,mutation)).success).toBeFalsy();
  const foreign = fixture(`${browserName}_foreign`); await login(page,foreign);
  await page.goto(BASE+'/',{waitUntil:'domcontentloaded'});
  let csrf = await page.locator('meta[name="csrf-token"]').getAttribute('content');
  expect((await post(page,mutation,csrf)).success).toBeFalsy();
  await page.goto('about:blank'); await page.context().clearCookies(); await login(page,f);
  await page.goto(BASE+'/',{waitUntil:'domcontentloaded'});
  csrf = await page.locator('meta[name="csrf-token"]').getAttribute('content');
  expect((await post(page,mutation)).success).toBeFalsy();
  expect((await post(page,{...mutation,option_key:'invented'},csrf)).success).toBeFalsy();
  cli('ban',key);
  expect((await post(page,mutation,csrf)).success).toBeFalsy();
});
test('MLP-361 real HTTP retry of two candidate clicks preserves one wish', async ({page,browserName}) => {
  test.skip(!BASE,'MLP_BASE_URL required');
  const f = fixture(`${browserName}_retry`); await login(page,f);
  await page.goto(BASE+'/',{waitUntil:'domcontentloaded'});
  await reveal(widget(page,f));
  await expect(widget(page,f).getByRole('button').first()).toBeEnabled();
  const csrf = await page.locator('meta[name="csrf-token"]').getAttribute('content');
  const first = await post(page,{action:'act_command_interaction',interaction_id:String(f.interactionId),option_key:'one'},csrf);
  const second = await post(page,{action:'act_command_interaction',interaction_id:String(f.interactionId),option_key:'two'},csrf);
  expect(first.success).toBeTruthy(); expect(second.success).toBeTruthy();
  expect(second.data.outcome).toEqual(first.data.outcome);
  expect(first.data.outcome.status).toBe('accepted');
  await expect(widget(page,f)).toContainText('Выбор завершён',{timeout:10000});
});
test('MLP-361 expired and edited source choices never execute', async ({page,browserName}) => {
  test.skip(!BASE,'MLP_BASE_URL required');
  const key = `${browserName}_expiry`; const f = fixture(key); await login(page,f);
  cli('expire',key);
  await page.goto(BASE+'/',{waitUntil:'domcontentloaded'});
  await reveal(widget(page,f));
  await expect(widget(page,f)).toContainText('Срок выбора истёк');
  const editKey = `${browserName}_edited`; const edited = fixture(editKey);
  cli('edit',editKey); await page.goto('about:blank'); await page.context().clearCookies(); await login(page,edited);
  await page.goto(BASE+'/',{waitUntil:'domcontentloaded'});
  await reveal(widget(page,edited));
  await expect(widget(page,edited)).toContainText('Выбор недоступен');
});
test('MLP-361 administrator corrects and restores an old completion', async ({page,browserName}) => {
  test.skip(!BASE,'MLP_BASE_URL required');
  const key = `${browserName}_correction`;
  cli('correction',key);
  const f = JSON.parse(fs.readFileSync(path.join(repo,'docs/private/mlp361-interactions-local.json'),'utf8')).cases[key];
  await login(page,f);
  const url = BASE+`/dashboard/?snapshot_id=${f.snapshotId}&completion_key=${encodeURIComponent('manual:'+f.snapshotId)}`;
  await page.goto(url,{waitUntil:'domcontentloaded'});
  await page.locator('.nav-tile[data-target="#tab-episodes"]').click();
  const form = page.locator('form[data-playlist-correction]');
  await expect(form).toBeVisible();
  expect(JSON.parse(cli('inspect',key))).toEqual([1,1]);
  await form.locator('input[name="story_ids[]"]').first().uncheck();
  await Promise.all([
    page.waitForNavigation({waitUntil:'domcontentloaded'}),
    form.getByRole('button',{name:'Сохранить корректировку'}).click(),
  ]);
  expect(JSON.parse(cli('inspect',key))).toEqual([0,1]);
  await expect(form).toBeVisible();
  await expect(form.locator('input[name="story_ids[]"]').first()).not.toBeChecked();
  await form.locator('input[name="story_ids[]"]').first().check();
  await Promise.all([
    page.waitForNavigation({waitUntil:'domcontentloaded'}),
    form.getByRole('button',{name:'Сохранить корректировку'}).click(),
  ]);
  expect(JSON.parse(cli('inspect',key))).toEqual([1,1]);
  await expect(form.locator('input[name="story_ids[]"]').first()).toBeChecked();
});

test('MLP-362 first episode actual command proposes clean buttons before vote', async ({page,browserName}) => {
  test.skip(!BASE,'MLP_BASE_URL required');
  const key=`${browserName}_semantic`;
  cli('semantic',key);
  const f=JSON.parse(fs.readFileSync(path.join(repo,'docs/private/mlp361-interactions-local.json'),'utf8')).cases[key];
  await login(page,f);await page.goto(BASE+'/',{waitUntil:'domcontentloaded'});
  const choice=widget(page,f);await reveal(choice);
  await expect(choice.getByRole('button').first()).toContainText(/Season 1 Episode 0?1 /);
  const message=page.locator(`.chat-message[data-id="${f.messageId}"]`);
  await expect(message).toContainText('желание пока не записано');
  await expect(message).not.toContainText('Детерминированный');
  await expect(message).not.toContainText('confirmation_required');
  fs.mkdirSync(path.join(repo,'docs/tests/MLP-362/screenshots'),{recursive:true});
  await page.screenshot({path:path.join(repo,`docs/tests/MLP-362/screenshots/${browserName}-semantic.png`)});
  await choice.getByRole('button').first().click();
  await expect(choice).toContainText('Выбор завершён');
  await page.reload({waitUntil:'domcontentloaded'});await reveal(widget(page,f));
  await expect(widget(page,f)).toContainText('Выбор завершён');
});

test('MLP-363 actual live proposal preserves natural wording and one confirmed wish', async ({page,browserName}) => {
  test.skip(!BASE,'MLP_BASE_URL required');
  const key=`${browserName}_live363`;
  cli('live',key);
  const f=JSON.parse(fs.readFileSync(path.join(repo,'docs/private/mlp361-interactions-local.json'),'utf8')).cases[key];
  expect(f.liveCalls).toBe(1);
  expect(f.liveUser).toContain('Выбор делает пользователь');
  expect(f.liveUser).not.toContain('Выбери эпизод кнопкой');
  expect(JSON.parse(cli('wish-count',key)).count).toBe(0);
  await login(page,f); await page.goto(BASE+'/',{waitUntil:'domcontentloaded'});
  const choice=widget(page,f);await reveal(choice);
  const message=page.locator(`.chat-message[data-id="${f.messageId}"]`);
  await expect(message).toContainText('Жми на кнопочку под ответом — этот выбор за тобой!');
  await expect(message).not.toContainText('желание пока не записано');
  await expect(choice.getByRole('button').first()).toBeVisible();
  fs.mkdirSync(path.join(repo,'docs/tests/MLP-363/screenshots'),{recursive:true});
  await page.screenshot({path:path.join(repo,`docs/tests/MLP-363/screenshots/${browserName}-live.png`)});
  await choice.getByRole('button').first().click();
  await expect(choice).toContainText('Выбор завершён');
  expect(JSON.parse(cli('wish-count',key)).count).toBe(1);
  const csrf=await page.locator('meta[name="csrf-token"]').getAttribute('content');
  const repeat=await post(page,{action:'act_command_interaction',interaction_id:String(f.interactionId),option_key:'cancel'},csrf);
  expect(repeat.success).toBeTruthy(); expect(repeat.data.outcome.status).toBe('accepted');
  expect(JSON.parse(cli('wish-count',key)).count).toBe(1);
  await page.reload({waitUntil:'domcontentloaded'}); await reveal(widget(page,f));
  await expect(widget(page,f)).toContainText('Выбор завершён');
  expect(JSON.parse(cli('wish-count',key)).count).toBe(1);
});
