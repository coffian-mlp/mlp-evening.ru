// MLP-239 (v4.7.0) — опросы: API-уровень (create/vote/close/get) через тонкий роутер.
// Виджет-рендеринг (poll.js) требует браузера и проверяется вручную/отдельно.
//
// Env: MLP_BASE_URL, MLP_LOGIN, MLP_PASS (Claude).
//   MLP_ADMIN=1 — роль Claude=admin (включает happy-path CRUD, оставляет тест-опрос → чистится пострелизным purge).

const { test, expect, request } = require('@playwright/test');

const BASE = process.env.MLP_BASE_URL;
const LOGIN = process.env.MLP_LOGIN;
const PASS = process.env.MLP_PASS;
const IS_ADMIN = process.env.MLP_ADMIN === '1';

async function login() {
  const ctx = await request.newContext({ baseURL: BASE });
  const r = await (await ctx.post('/api.php', { form: { action: 'login', username: LOGIN, password: PASS } })).json();
  expect(r.success, `login failed: ${JSON.stringify(r)}`).toBeTruthy();
  const html = await (await ctx.get('/')).text();
  const csrf = html.match(/name="csrf-token"\s+content="([^"]+)"/)?.[1];
  return { ctx, csrf };
}

test.beforeEach(() => { expect(BASE && LOGIN && PASS, 'env must be set').toBeTruthy(); });

test('get_poll: несуществующий опрос → не найден (public)', async () => {
  const ctx = await request.newContext({ baseURL: BASE });
  const res = await (await ctx.post('/api.php', { form: { action: 'get_poll', poll_id: '999999999' } })).json();
  expect(res.success).toBeFalsy();
  await ctx.dispose();
});

test('create_poll без CSRF → отказ (L4-политика)', async () => {
  const { ctx } = await login();
  const res = await (await ctx.post('/api.php', {
    form: { action: 'create_poll', question: 'x', 'options[]': 'A' },
  })).json();
  expect(res.success, `must be rejected without CSRF: ${JSON.stringify(res)}`).toBeFalsy();
  await ctx.dispose();
});

test('create_poll не-админом при polls_create_role=moderator → недостаточно прав', async () => {
  test.skip(IS_ADMIN, 'роль Claude=admin — негативный тест прав пропущен');
  const { ctx, csrf } = await login();
  const res = await (await ctx.post('/api.php', {
    form: { action: 'create_poll', csrf_token: csrf, question: 'Тест прав', 'options[]': 'A', 'options[]': 'B' },
    headers: { 'X-CSRF-Token': csrf },
  })).json();
  // При дефолте polls_create_role=moderator обычный user не может создавать.
  expect(res.success, `non-privileged create must be denied: ${JSON.stringify(res)}`).toBeFalsy();
  await ctx.dispose();
});

test('admin: create → get → vote → close (MLP_ADMIN=1)', async () => {
  test.skip(!IS_ADMIN, 'нужен MLP_ADMIN=1');
  const { ctx, csrf } = await login();
  const marker = 'PW-POLL-' + Date.now();

  const created = await (await ctx.post('/api.php', {
    form: {
      action: 'create_poll', csrf_token: csrf, question: marker,
      'options[]': 'Вариант А', 'options[]': 'Вариант Б',
      is_multi: '0', is_anonymous: '0',
    },
    headers: { 'X-CSRF-Token': csrf },
  })).json();
  expect(created.success, `create failed: ${JSON.stringify(created)}`).toBeTruthy();
  const poll = created.data.poll;
  expect(poll.question).toBe(marker);
  expect(poll.options.length).toBe(2);

  const got = await (await ctx.post('/api.php', { form: { action: 'get_poll', poll_id: String(poll.id) } })).json();
  expect(got.success).toBeTruthy();

  const voted = await (await ctx.post('/api.php', {
    form: { action: 'vote_poll', csrf_token: csrf, poll_id: String(poll.id), 'option_ids[]': String(poll.options[0].id) },
    headers: { 'X-CSRF-Token': csrf },
  })).json();
  expect(voted.success).toBeTruthy();
  expect(voted.data.results.total_voters).toBe(1);
  expect(voted.data.results.options[0].votes).toBe(1);

  const closed = await (await ctx.post('/api.php', {
    form: { action: 'close_poll', csrf_token: csrf, poll_id: String(poll.id) },
    headers: { 'X-CSRF-Token': csrf },
  })).json();
  expect(closed.success).toBeTruthy();

  await ctx.dispose();
});
