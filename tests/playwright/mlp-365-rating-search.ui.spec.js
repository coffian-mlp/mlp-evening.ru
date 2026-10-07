const { test, expect } = require('@playwright/test');
const { execFileSync } = require('child_process');
const fs = require('fs');
const path = require('path');
const BASE = process.env.MLP_BASE_URL;
const repo = path.resolve(__dirname, '../..');
function cli(...args) {
  if (!BASE || !['localhost', '127.0.0.1'].includes(new URL(BASE).hostname)) throw new Error('Isolated localhost required');
  return execFileSync('docker', ['compose', '-p', 'mlp359', '-f', 'docker-compose.yml', '-f', 'docs/tests/MLP-359/compose.override.yml', 'exec', '-T', 'php', 'php', 'tests/playwright/mlp-361-interactions-fixture.php', ...args], { cwd: repo, stdio: 'pipe' }).toString();
}
function inspect(f) { return JSON.parse(cli('continuation-inspect', f.key)); }
function runWorker(f, mode = 'found') { return JSON.parse(cli('continuation-worker', f.key, mode)); }
const choice = (page, row) => page.locator(`.chat-message[data-id="${row.messageId}"] .command-interaction`);
async function refresh(page, row) {
  const widget = choice(page, row); await widget.scrollIntoViewIfNeeded();
  await widget.evaluate(node => window.CommandInteractions.refresh(node));
  return widget;
}
async function post(page, data) {
  const csrf = await page.locator('meta[name="csrf-token"]').getAttribute('content');
  return (await page.request.post(BASE + '/api.php', { form: { ...data, csrf_token: csrf } })).json();
}
async function send(page, message, quotes = []) {
  const result = await post(page, { action: 'send_message', message, ...(quotes.length ? { quoted_msg_ids: quotes.join(',') } : {}) });
  expect(result.success, JSON.stringify(result)).toBeTruthy(); return result;
}
async function sendQuotedUI(page, message, messageId) {
  const bubble = page.locator(`.chat-message[data-id="${messageId}"]`);
  await page.evaluate(() => window.getSelection()?.removeAllRanges());
  await bubble.locator('.quote-btn').click({ force: true });
  await expect(page.locator(`.quote-preview-remove[data-id="${messageId}"]`)).toBeVisible();
  const field = page.locator('#chat-input'); await field.fill(message);
  const response = page.waitForResponse(r => r.url().endsWith('/api.php') && r.request().postData()?.includes('action=send_message'));
  await page.locator('#chat-form').evaluate(form => form.requestSubmit());
  const sent = await response;
  const form = new URLSearchParams(sent.request().postData());
  expect((form.get('quoted_msg_ids') || '').split(',')).toContain(String(messageId));
  expect((await sent.json()).success).toBeTruthy();
}
async function actor(page, key, route = '/') {
  cli('continuation-user', key);
  const user = JSON.parse(fs.readFileSync(path.join(repo, 'docs/private/mlp361-interactions-local.json'), 'utf8')).cases[key];
  const login = await (await page.request.post(BASE + '/api.php', { form: { action: 'login', username: user.login, password: user.password } })).json();
  expect(login.success).toBeTruthy(); await page.goto(BASE + route, { waitUntil: 'domcontentloaded' });
  return { ...user, key };
}
async function proposal(page, f, query = 'самую первую серию') {
  await send(page, '!хочу ' + query); runWorker(f);
  const row = inspect(f).interactions.findLast(r => r.state === 'pending');
  expect(row).toBeTruthy(); await expect(choice(page, row)).toBeVisible({ timeout: 15000 });
  await refresh(page, row); return row;
}
test.beforeEach(() => { test.skip(!BASE, 'MLP_BASE_URL required'); });
test('MLP-365 рейтинг: initial best → обычная цитата worst с сохранением персонажа → once effect', async ({page,browserName}) => {
 const f=await actor(page,`${browserName}_rating_override`);const low=JSON.parse(cli('rating-targets',f.key));
 await send(page,'!хочу лучший эпизод где Лира');const first=runWorker(f,'rating-best');
 expect(first.calls.filter(c=>c.stage==='search')).toHaveLength(2);
 let state=inspect(f);const parent=state.interactions.findLast(r=>r.state==='pending');expect(parent).toBeTruthy();
 expect(parent.handlerContext.resolution_snapshot.intent.direction).toBe('best');expect(state.wishes).toBe(0);
 await expect(choice(page,parent)).toBeVisible({timeout:15000});await refresh(page,parent);
 const publicReply=await post(page,{action:'get_command_interaction',interaction_id:String(parent.id),message_id:String(parent.messageId)});
 expect(JSON.stringify(publicReply)).not.toContain('resolution_snapshot');expect(JSON.stringify(publicReply)).not.toContain('comparison');
 await choice(page,parent).getByRole('button',{name:'Не то, уточнить',exact:true}).click();
 await sendQuotedUI(page,'Скорее самый плохой, но Лиру сохрани',parent.messageId);
 expect(inspect(f).interactions.find(r=>r.id===parent.id).state).toBe('resolving');
 const second=runWorker(f,'rating-worst');expect(second.calls.filter(c=>c.stage==='search')).toHaveLength(2);
 state=inspect(f);const child=state.interactions.findLast(r=>r.state==='pending');expect(child.id).not.toBe(parent.id);
 expect(child.expiresAt).toBe(parent.expiresAt);expect(child.options.filter(o=>o.key.startsWith('episode_')).map(o=>o.key)).toEqual([`episode_${low.lowTargetId}`]);
 const persisted=state.interactions.find(r=>r.id===parent.id).resolverResult.resolution_snapshot;
 expect(persisted.intent.direction).toBe('worst');expect(persisted.intent.scope_constraints).toEqual(['Lyra appears']);expect(state.wishes).toBe(0);
 await expect(choice(page,child)).toBeVisible({timeout:15000});await refresh(page,child);
 await expect(page.locator(`.chat-message[data-id="${child.messageId}"]`)).toContainText('IMDb');
 await choice(page,child).locator(`[data-option-key="episode_${low.lowTargetId}"]`).click();
 await expect(choice(page,child)).toContainText('Выбор завершён');expect(inspect(f).wishes).toBe(1);expect(inspect(f).events).toBe(1);
 await post(page,{action:'act_command_interaction',interaction_id:String(child.id),option_key:`episode_${low.lowTargetId}`});expect(inspect(f).events).toBe(1);
 await page.reload({waitUntil:'domcontentloaded'});await refresh(page,child);await expect(choice(page,child)).toContainText('Выбор завершён');
 fs.mkdirSync(path.join(repo,'docs/tests/MLP-365-rating-search/screenshots'),{recursive:true});
 await page.screenshot({path:path.join(repo,`docs/tests/MLP-365-rating-search/screenshots/${browserName}-rating-confirmed.png`)});
});
test('MLP-365 неподтверждённый рейтинг → цитата вопроса → проверенный вариант',async({page,browserName})=>{
 const f=await actor(page,`${browserName}_rating_empty`);cli('rating-targets',f.key);
 await send(page,'!хочу самый засранный эпизод');runWorker(f,'rating-empty');
 let state=inspect(f);const waiting=state.interactions.findLast(r=>r.state==='clarifying');expect(waiting).toBeTruthy();
 expect(waiting.options.filter(o=>o.key.startsWith('episode_'))).toHaveLength(0);expect(state.wishes).toBe(0);
 await expect(choice(page,waiting)).toBeVisible({timeout:15000});await refresh(page,waiting);
 await expect(page.locator(`.chat-message[data-id="${waiting.messageId}"]`)).toContainText('IMDb');
 await sendQuotedUI(page,'Повтори поиск самых плохих по средней оценке',waiting.messageId);runWorker(f,'rating-worst');
 state=inspect(f);const child=state.interactions.findLast(r=>r.state==='pending');expect(child).toBeTruthy();expect(state.events).toBe(0);
 await expect(choice(page,child)).toBeVisible({timeout:15000});await refresh(page,child);
 expect(child.handlerContext.resolution_snapshot.intent.direction).toBe('worst');
 await choice(page,child).getByRole('button',{name:'Не то, уточнить',exact:true}).click();
 const prompt=runWorker(f,'rating-worst');expect(prompt.calls.filter(c=>c.stage==='search')).toHaveLength(0);
 expect(prompt.calls.filter(c=>c.stage==='live').some(c=>c.user.includes('Источник оценок: IMDb')&&c.user.includes('средняя оценка'))).toBeTruthy();
 expect(inspect(f).interactions.find(r=>r.id===child.id).state).toBe('clarifying');
 await refresh(page,child);
 await choice(page,child).getByRole('button',{name:'Передумал',exact:true}).click();expect(inspect(f).events).toBe(0);
});
test('MLP-365 явный неподдерживаемый источник не подменяется IMDb',async({page,browserName})=>{
 const f=await actor(page,`${browserName}_rating_source`);
 await send(page,'!хочу лучшую по Rotten Tomatoes');const calls=runWorker(f,'rating-source').calls;
 expect(calls.filter(c=>c.stage==='search')).toHaveLength(0);
 const state=inspect(f);const row=state.interactions.findLast(r=>r.state==='clarifying');
 expect(row.handlerContext.resolution_snapshot.reason).toBe('unsupported_source');expect(row.options.filter(o=>o.key.startsWith('episode_'))).toHaveLength(0);
 await expect(choice(page,row)).toBeVisible({timeout:15000});await refresh(page,row);
 await expect(page.locator(`.chat-message[data-id="${row.messageId}"]`)).toContainText('Rotten Tomatoes');
 await choice(page,row).getByRole('button',{name:'Передумал',exact:true}).click();expect(inspect(f).wishes).toBe(0);expect(inspect(f).events).toBe(0);
});
test('MLP-365 спорный: распределение голосов обязательно, средний рейтинг его не заменяет',async({page,browserName})=>{
 const f=await actor(page,`${browserName}_rating_polar`);
 await send(page,'!хочу спорную серию');runWorker(f,'rating-polar-missing');let state=inspect(f);
 const waiting=state.interactions.findLast(r=>r.state==='clarifying');expect(waiting).toBeTruthy();expect(state.wishes).toBe(0);
 expect(waiting.handlerContext.resolution_snapshot.reason).toBe('missing_distribution');
 await expect(choice(page,waiting)).toBeVisible({timeout:15000});await sendQuotedUI(page,'Спорную, с высокими и низкими оценками',waiting.messageId);
 runWorker(f,'rating-polar');state=inspect(f);const parent=state.interactions.find(r=>r.id===waiting.id);const child=state.interactions.findLast(r=>r.state==='pending');
 expect(parent.resolverResult.resolution_snapshot.candidates[0].rating.value).toBe(0.4);
 await expect(choice(page,child)).toBeVisible({timeout:15000});await refresh(page,child);
 await expect(page.locator(`.chat-message[data-id="${child.messageId}"]`)).toContainText('высокие и низкие');expect(state.events).toBe(0);
});
