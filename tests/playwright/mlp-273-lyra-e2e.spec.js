// MLP-273 — e2e живой Лиры (давний долг арх-ревью v4.8). ЖЖЁТ ТОКЕНЫ и ждёт
// воркер (до ~2.5 мин на сценарий) — гоняется ТОЛЬКО с MLP_LYRA=1, против прода.
// Чистка: свои сообщения и ответы бота спек убирает сам (мягкое удаление),
// тестовый опрос закрывает.
//
// Env: MLP_BASE_URL, MLP_LOGIN, MLP_PASS, MLP_LYRA=1.

const { test, expect, request } = require('@playwright/test');

const BASE = process.env.MLP_BASE_URL;
const LOGIN = process.env.MLP_LOGIN;
const PASS = process.env.MLP_PASS;
const LYRA = process.env.MLP_LYRA === '1';

test.describe.configure({ timeout: 200000 });

async function loginCtx() {
  const ctx = await request.newContext({ baseURL: BASE });
  const login = await (await ctx.post('/api.php', { form: { action: 'login', username: LOGIN, password: PASS } })).json();
  expect(login.success).toBeTruthy();
  const html = await (await ctx.get('/')).text();
  return { ctx, csrf: html.match(/name="csrf-token"\s+content="([^"]+)"/)?.[1] };
}

function post(ctx, csrf, form) {
  return ctx.post('/api.php', { form: { ...form, csrf_token: csrf }, headers: { 'X-CSRF-Token': csrf } }).then(r => r.json());
}

async function getMessages(ctx, csrf, limit = 20) {
  const res = await post(ctx, csrf, { action: 'get_messages', limit: String(limit) });
  return res.data.messages || [];
}

async function cleanup(ctx, csrf, ids) {
  for (const id of ids) {
    await post(ctx, csrf, { action: 'delete_message', message_id: id });
  }
}

test('Лира отвечает на упоминание: живо, по-русски, без системщины (MLP-273)', async () => {
  test.skip(!LYRA, 'нужен MLP_LYRA=1 (живой LLM, токены)');
  const { ctx, csrf } = await loginCtx();
  const toClean = [];

  const marker = `pw273-${Date.now()}`;
  const sent = await post(ctx, csrf, { action: 'send_message', message: `@TotallyNotAPony привет! Скажи, какой твой любимый инструмент? (${marker})` });
  expect(sent.success).toBeTruthy();

  let reply = null;
  await expect.poll(async () => {
    const msgs = await getMessages(ctx, csrf, 20);
    const mine = msgs.find(m => (m.message || '').includes(marker));
    if (mine && !toClean.includes(mine.id)) toClean.push(mine.id);
    reply = msgs.find(m => m.user_id == 12 && mine && m.id > mine.id);
    return !!reply;
  }, { timeout: 170000, intervals: [5000] }).toBeTruthy();

  toClean.push(reply.id);
  const text = reply.raw_message || reply.message || '';
  expect(text.length, 'ответ непустой').toBeGreaterThan(5);
  expect(/[а-яё]/i.test(text), 'ответ содержит кириллицу').toBeTruthy();
  for (const bad of ['system', 'СИСТЕМНОЕ СООБЩЕНИЕ', '[РЕАКЦИЯ', 'assistant:', '<think>']) {
    expect(text.toLowerCase(), `без системщины (${bad})`).not.toContain(bad.toLowerCase());
  }

  await cleanup(ctx, csrf, toClean);
  await ctx.dispose();
});

test('Лира создаёт опрос с таймером: «/опрос … закрой через 5 минут» → closes_at (MLP-273 + MLP-271)', async () => {
  test.skip(!LYRA, 'нужен MLP_LYRA=1');
  // ПРЕДПОСЫЛКА: роль тест-юзера должна проходить polls_create_role (дефолт
  // moderator) — иначе AR4-1-гейт сбрасывает /опрос в упоминание и Лира молчит
  // (проверено живьём: это фича, не баг).
  const { ctx, csrf } = await loginCtx();
  const toClean = [];

  const marker = `pw273p-${Date.now()}`;
  const sent = await post(ctx, csrf, {
    action: 'send_message',
    message: `/опрос Какой сорт яблок лучше? (${marker}) Варианты: грэнни, фуджи, антоновка, закрой через 5 минут`,
  });
  expect(sent.success).toBeTruthy();

  let pollMsg = null;
  await expect.poll(async () => {
    const msgs = await getMessages(ctx, csrf, 20);
    const mine = msgs.find(m => (m.message || '').includes(marker));
    if (mine && !toClean.includes(mine.id)) toClean.push(mine.id);
    pollMsg = msgs.find(m => m.user_id == 12 && /\[\[poll:(\d+)\]\]/.test(m.raw_message || m.message || ''));
    return !!pollMsg;
  }, { timeout: 170000, intervals: [5000] }).toBeTruthy();

  toClean.push(pollMsg.id);
  const pollId = (pollMsg.raw_message || pollMsg.message).match(/\[\[poll:(\d+)\]\]/)[1];

  const poll = await post(ctx, csrf, { action: 'get_poll', poll_id: pollId });
  expect(poll.success, `get_poll: ${JSON.stringify(poll).slice(0, 150)}`).toBeTruthy();
  const p = poll.data.poll || poll.data;
  expect(p.closes_at, 'closes_at установлен ботом из «закрой через 5 минут»').toBeTruthy();

  const closed = await post(ctx, csrf, { action: 'close_poll', poll_id: pollId });
  expect(closed.success, 'тестовый опрос закрыт').toBeTruthy();

  await cleanup(ctx, csrf, toClean);
  await ctx.dispose();
});
