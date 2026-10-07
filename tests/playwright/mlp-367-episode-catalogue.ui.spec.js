const { test, expect } = require('@playwright/test');
const { execFileSync } = require('child_process');
const fs = require('fs');
const path = require('path');
const { randomUUID } = require('crypto');
const BASE = process.env.MLP_BASE_URL;
const repo = path.resolve(__dirname, '../..');
function cli(...args) {
  if (!BASE || !['localhost','127.0.0.1'].includes(new URL(BASE).hostname)) throw new Error('Local isolated fixture required');
  return execFileSync('docker',['compose','-p','mlp359','-f','docker-compose.yml','-f','docs/tests/MLP-359/compose.override.yml','exec','-T','php','php','tests/playwright/mlp-367-catalogue-fixture.php',...args],{cwd:repo,stdio:'pipe'}).toString();
}
const fixture = () => JSON.parse(fs.readFileSync(path.join(repo,'docs/private/mlp367-catalogue-local.json'),'utf8'));
const row = (page,id) => page.locator(`.episode-catalogue tbody tr[data-episode-id="${id}"]`);
const state = key => JSON.parse(cli('state',key || ''));
async function post(page,data) {
  const csrf = await page.locator('meta[name="csrf-token"]').count() ? await page.locator('meta[name="csrf-token"]').getAttribute('content') : ''; 
  return (await page.request.post(BASE+'/api.php',{form:{csrf_token:csrf,...data}})).json();
}
async function actor(page,key,role='user') {
  cli('user',key,role); const f=fixture().cases[key];
  const result=await post(page,{action:'login',username:f.login,password:f.password}); expect(result.success).toBeTruthy();
  await page.goto(BASE+'/episodes.php'); return {...f,key};
}
async function shot(page,browser,name) {
  const dir=path.join(repo,'docs/tests/MLP-367-episode-catalogue/screenshots');fs.mkdirSync(dir,{recursive:true});
  await page.screenshot({path:path.join(dir,`${browser}-${name}.png`),animations:'disabled'});
}
const clickAction = async (page,id,name) => {
  await row(page,id).getByRole('button',{name,exact:true}).click();
  await expect(page.locator('[data-catalogue-feedback]')).not.toHaveText('Выполняется…');
};
test.beforeEach(async ({page}) => { test.skip(!BASE,'MLP_BASE_URL required'); await page.goto(BASE+'/episodes.php'); await expect(page.locator('.episode-catalogue')).toBeVisible(); });

test('MLP-367 guests read public catalogue without private fields or effects',async ({page,browserName})=>{
  const before=state();const data=JSON.parse(await page.locator('.episode-catalogue-data').textContent());
  expect(data.catalogue.viewer.authenticated).toBe(false);expect(data.catalogue.rows.length).toBeGreaterThan(8);
  expect(data.catalogue.rows.every(r=>!r.admin)).toBe(true);expect(JSON.stringify(data)).not.toMatch(/provenance|owner_id|resolution_snapshot/);
  await expect(row(page,fixture().ids.alpha).getByRole('link',{name:'Войти, чтобы пожелать'})).toHaveAttribute('href','/login.php?redirect=%2Fepisodes.php');
  expect((await post(page,{action:'catalogue_wish',episode_id:fixture().ids.alpha,operation_token:randomUUID()})).success).toBe(false);
  await page.locator('[data-catalogue-search]').fill('Catalogue');await shot(page,browserName,'guest-desktop');
  await page.setViewportSize({width:360,height:780});await shot(page,browserName,'guest-mobile');
  expect(await page.evaluate(()=>document.documentElement.scrollWidth<=window.innerWidth)).toBe(true);
  expect(state()).toEqual(before);
});
test('MLP-367 combined filters and numeric/null-last stable sorts',async ({page})=>{
  const ids=fixture().ids;await page.locator('[data-catalogue-season]').selectOption('97');
  await page.locator('[data-catalogue-search]').fill('С97Е1');await expect(page.locator('.episode-catalogue tbody tr')).toHaveCount(1);await expect(row(page,ids.alpha)).toBeVisible();
  await page.locator('[data-catalogue-search]').fill('Catalogue');await page.locator('[data-catalogue-sort]').selectOption('views');
  let order=await page.locator('.episode-catalogue tbody tr').evaluateAll(ns=>ns.map(n=>Number(n.dataset.episodeId)));
  expect(order.indexOf(ids.alpha)).toBeLessThan(order.indexOf(ids.beta));expect(order.indexOf(ids.alpha)).toBeLessThan(order.indexOf(ids.gamma));
  await page.locator('[data-catalogue-direction]').click();order=await page.locator('.episode-catalogue tbody tr').evaluateAll(ns=>ns.map(n=>Number(n.dataset.episodeId)));expect(order[0]).toBe(ids.beta);
  await page.locator('[data-catalogue-season]').selectOption('');await page.locator('[data-catalogue-sort]').selectOption('score');
  for(let i=0;i<2;i++){ const last=page.locator('.episode-catalogue tbody tr').last();await expect(last).toHaveAttribute('data-episode-id',String(ids.unknown));await page.locator('[data-catalogue-direction]').click(); }
  await page.locator('[data-catalogue-sort]').selectOption('code');await expect(page.locator('.episode-catalogue tbody tr').last().locator('td').first()).toHaveText('Спецвыпуск');
  await page.locator('[data-catalogue-direction]').click();await expect(page.locator('.episode-catalogue tbody tr').last().locator('td').first()).toHaveText('Спецвыпуск');
  await page.locator('[data-catalogue-search]').fill('no matching episode');await expect(page.locator('[data-catalogue-empty]')).toBeVisible();
});
test('MLP-367 details preserve weighted score, histogram, related part and safe DOM',async ({page,browserName})=>{
  const ids=fixture().ids;await page.locator('[data-catalogue-search]').fill('Catalogue');await row(page,ids.alpha).locator('summary').click();
  await expect(row(page,ids.alpha).locator('td').nth(4)).toHaveText('8.2');await expect(row(page,ids.alpha).locator('td').nth(5)).toHaveText('4.5');
  await expect(row(page,ids.alpha).locator('meter')).toHaveCount(10);await expect(row(page,ids.alpha)).toContainText('Количество оценок: 100');
  await expect(row(page,ids.alpha).getByRole('link',{name:'Страница IMDb'})).toHaveAttribute('href',/^https:\/\/www\.imdb\.com\/title\/tt\d+\/ratings\/$/);
  await expect(row(page,ids.stale)).toContainText('устарело');await expect(row(page,ids.low)).toContainText('мало оценок');await expect(row(page,ids.unknown)).toContainText('данные отсутствуют');
  await expect(row(page,ids.unsafe).locator('img')).toHaveCount(0);expect(await page.evaluate(()=>window.mlp367Xss)).toBeUndefined();
  await shot(page,browserName,'histogram');await row(page,ids.alpha).locator('[data-catalogue-related]').click();await expect(row(page,ids.beta)).toBeVisible();
});
test('MLP-367 authenticated wish/cancel refresh preserves filters and other wishes',async ({page,browserName})=>{
  const f=await actor(page,browserName+'_wish');const ids=fixture().ids;await page.locator('[data-catalogue-season]').selectOption('97');await page.locator('[data-catalogue-search]').fill('Alpha');
  await expect(row(page,ids.alpha).locator('td').nth(3)).toHaveText('3');await clickAction(page,ids.alpha,'Хочу посмотреть');
  await expect(row(page,ids.alpha).getByRole('button',{name:'Отменить желание'})).toBeVisible();await expect(page.locator('[data-catalogue-search]')).toHaveValue('Alpha');
  await expect(row(page,ids.alpha).locator('td').nth(3)).toHaveText('4');expect(state(f.key).accepted).toBe(1);
  await page.reload();await clickAction(page,ids.alpha,'Отменить желание');await expect(row(page,ids.alpha).locator('td').nth(3)).toHaveText('3');expect(state(f.key).active).toHaveLength(0);
  await expect(row(page,ids.alpha).getByRole('button')).toBeFocused();expect(state('other').active.some(r=>Number(r.episode_id)===ids.alpha)).toBe(true);
});
test.describe('transport loss with observable browser routing',()=>{
  test.use({serviceWorkers:'block'});
  test('MLP-367 lost response retries same UUID and old replay uses current inactive row',async ({page,browserName})=>{
  const f=await actor(page,browserName+'_retry');const id=fixture().ids.beta;let first=true;const tokens=[];
  await page.route('**/api.php',async route=>{const p=new URLSearchParams(route.request().postData());if(p.get('action')!=='catalogue_wish')return route.continue();tokens.push(p.get('operation_token'));if(first){first=false;await route.fetch();return route.abort();}return route.continue();});
  await clickAction(page,id,'Хочу посмотреть');await expect(row(page,id).getByRole('button',{name:'Повторить запрос'})).toBeVisible();
  await clickAction(page,id,'Повторить запрос');expect(tokens[1]).toBe(tokens[0]);expect(state(f.key).accepted).toBe(1);
  await clickAction(page,id,'Отменить желание');const replay=await post(page,{action:'catalogue_wish',episode_id:id,operation_token:tokens[0]});expect(replay.data.outcome.code).toBe('accepted');expect(replay.data.row.own_active).toBe(false);
  await page.unroute('**/api.php');await page.route('**/api.php',route=>{const p=new URLSearchParams(route.request().postData());if(p.get('action')==='catalogue_wish')p.set('operation_token',tokens[0]);return route.continue({postData:p.toString()});});
  await clickAction(page,id,'Хочу посмотреть');await expect(page.locator('[data-catalogue-feedback]')).toContainText('сейчас желание не активно');await expect(row(page,id).getByRole('button',{name:'Хочу посмотреть'})).toBeVisible();expect(state(f.key).accepted).toBe(1);
});
});
test('MLP-367 shared domain quota and cooldown survive cancellation',async ({page,browserName})=>{
  const f=await actor(page,browserName+'_quota');cli('seed-wishes',f.key,'alpha,beta');await page.reload();const ids=fixture().ids;
  await clickAction(page,ids.gamma,'Хочу посмотреть');expect(state(f.key).accepted).toBe(3);await clickAction(page,ids.delta,'Хочу посмотреть');await expect(page.locator('[data-catalogue-feedback]')).toContainText('Дневной лимит исчерпан');
  await clickAction(page,ids.alpha,'Отменить желание');await clickAction(page,ids.delta,'Хочу посмотреть');await expect(page.locator('[data-catalogue-feedback]')).toContainText('Дневной лимит исчерпан');
  await clickAction(page,ids.alpha,'Хочу посмотреть');await expect(page.locator('[data-catalogue-feedback]')).toContainText('(UTC)');expect(state(f.key).accepted).toBe(3);expect(state(f.key).active).toHaveLength(2);
});
test('MLP-367 HTTP CSRF, server actor, current grants and missing target fail honestly',async ({page,browserName})=>{
  const f=await actor(page,browserName+'_guards');const id=fixture().ids.low;const token=randomUUID();const before=state(f.key);
  expect((await post(page,{action:'catalogue_wish',episode_id:id,operation_token:token,csrf_token:'wrong'})).success).toBe(false);
  expect((await post(page,{action:'catalogue_wish',episode_id:id,operation_token:'invalid'})).success).toBe(false);expect(state(f.key)).toEqual(before);
  const accepted=await post(page,{action:'catalogue_wish',episode_id:id,operation_token:token,user_id:fixture().cases.other.userId});expect(accepted.data.row.own_active).toBe(true);expect(state(f.key).accepted).toBe(1);expect(state('other').active.some(r=>Number(r.episode_id)===id)).toBe(false);
  cli('ban',f.key);expect((await post(page,{action:'catalogue_wish',episode_id:id,operation_token:token})).success).toBe(false);cli('clear-grant',f.key);cli('mute',f.key);expect((await post(page,{action:'catalogue_cancel_wish',episode_id:id,operation_token:randomUUID()})).success).toBe(false);cli('clear-grant',f.key);
  const missing=await post(page,{action:'catalogue_wish',episode_id:999999,operation_token:randomUUID()});expect(missing.data.outcome.code).toBe('missing');expect(missing.data.row).toBeNull();
  cli('delete-user',f.key);expect((await post(page,{action:'catalogue_wish',episode_id:id,operation_token:token})).success).toBe(false);
});
test('MLP-367 admin shared table retains technical columns and scoped sorting',async ({page,browserName})=>{
  await actor(page,browserName+'_admin','admin');const publicData=JSON.parse(await page.locator('.episode-catalogue-data').textContent());expect(publicData.admin).toBe(false);
  await page.goto(BASE+'/dashboard/#tab-episodes');await expect(page.locator('.episode-catalogue')).toBeVisible();
  const data=JSON.parse(await page.locator('.episode-catalogue-data').textContent());expect(data.admin).toBe(true);expect(data.catalogue.rows.every(r=>r.admin)).toBe(true);
  await page.locator('[data-catalogue-search]').fill('Catalogue');await page.locator('[data-catalogue-sort-key="views"]').click();
  await expect(row(page,fixture().ids.alpha)).toContainText('Catalogue Alpha');await expect(page.locator('.episode-catalogue th')).toContainText(['Серия','Название','Просмотры','Желания','IMDb','Разброс σ','Моё желание','ID','TWOPART_ID','LENGTH']);
  await shot(page,browserName,'admin-desktop');await page.setViewportSize({width:360,height:780});await shot(page,browserName,'admin-mobile');
});
