// MLP-264 — срез api.php: auth/profile/socials через тонкий роутер.
// Проверяем, что перенесённые actions отвечают как раньше: logout, whitelist
// пользовательских настроек, соц-привязки, отбивка кривого avatar_url,
// register с занятым логином (человеческий текст из UserError).
//
// Env: MLP_BASE_URL, MLP_LOGIN, MLP_PASS (тестовый юзер Claude).

const { test, expect, request } = require('@playwright/test');

const BASE = process.env.MLP_BASE_URL;
const LOGIN = process.env.MLP_LOGIN;
const PASS = process.env.MLP_PASS;

async function getCsrf(ctx) {
  const html = await (await ctx.get('/')).text();
  return html.match(/name="csrf-token"\s+content="([^"]+)"/)?.[1];
}

async function loginCtx() {
  const ctx = await request.newContext({ baseURL: BASE });
  const login = await (await ctx.post('/api.php', { form: { action: 'login', username: LOGIN, password: PASS } })).json();
  expect(login.success, `login failed: ${JSON.stringify(login)}`).toBeTruthy();
  const csrf = await getCsrf(ctx);
  expect(csrf, 'csrf token').toBeTruthy();
  return { ctx, csrf };
}

test('профиль: save_user_option — whitelist ключей держит мусор (MLP-264)', async () => {
  expect(BASE, 'MLP_BASE_URL must be set').toBeTruthy();
  const { ctx, csrf } = await loginCtx();

  const bad = await (await ctx.post('/api.php', {
    form: { action: 'save_user_option', key: 'evil_key', value: '1', csrf_token: csrf },
    headers: { 'X-CSRF-Token': csrf },
  })).json();
  expect(bad.success, 'мусорный ключ отвергается').toBeFalsy();

  const good = await (await ctx.post('/api.php', {
    form: { action: 'save_user_option', key: 'chat_title_enabled', value: '1', csrf_token: csrf },
    headers: { 'X-CSRF-Token': csrf },
  })).json();
  expect(good.success, `валидный ключ сохраняется: ${JSON.stringify(good)}`).toBeTruthy();

  await ctx.dispose();
});

test('профиль: get_user_socials отвечает массивом; unlink без provider — отказ (MLP-264)', async () => {
  const { ctx, csrf } = await loginCtx();

  const socials = await (await ctx.post('/api.php', {
    form: { action: 'get_user_socials', csrf_token: csrf },
    headers: { 'X-CSRF-Token': csrf },
  })).json();
  expect(socials.success).toBeTruthy();
  expect(Array.isArray(socials.data.socials), 'data.socials — массив').toBeTruthy();

  const unlink = await (await ctx.post('/api.php', {
    form: { action: 'unlink_social', csrf_token: csrf },
    headers: { 'X-CSRF-Token': csrf },
  })).json();
  expect(unlink.success, 'без provider — отказ').toBeFalsy();

  await ctx.dispose();
});

test('профиль: невалидный avatar_url отбивается, а не сохраняется тихо (MLP-264, фикс)', async () => {
  const { ctx, csrf } = await loginCtx();

  const res = await (await ctx.post('/api.php', {
    form: { action: 'update_profile', avatar_url: 'javascript:alert(1)', csrf_token: csrf },
    headers: { 'X-CSRF-Token': csrf },
  })).json();
  expect(res.success, 'кривая ссылка не проходит').toBeFalsy();
  expect(res.message).toContain('Некорректная ссылка');

  await ctx.dispose();
});

test('auth: register с занятым логином — человеческий текст; гость не достаёт до user-actions (MLP-264)', async () => {
  const ctx = await request.newContext({ baseURL: BASE });

  // Гость: user-action через роутер → отказ (requireApiLogin).
  for (const action of ['update_profile', 'save_user_option', 'get_user_socials', 'bind_social', 'logout']) {
    const res = await (await ctx.post('/api.php', { form: { action } })).json();
    expect(res.success, `guest '${action}' must be denied`).toBeFalsy();
  }

  // register: капча не пройдена → понятный отказ (не 500 и не системный текст).
  const reg = await (await ctx.post('/api.php', {
    form: { action: 'register', login: LOGIN, password: 'x'.repeat(12) },
  })).json();
  expect(reg.success).toBeFalsy();
  expect(reg.message, 'ответ человеческий (капча или занятый логин)').toMatch(/Гармонии|существует/);

  await ctx.dispose();
});

test('auth: logout завершает сессию (MLP-264)', async () => {
  const { ctx, csrf } = await loginCtx();

  const out = await (await ctx.post('/api.php', {
    form: { action: 'logout', csrf_token: csrf },
    headers: { 'X-CSRF-Token': csrf },
  })).json();
  expect(out.success, `logout: ${JSON.stringify(out)}`).toBeTruthy();

  // После выхода user-action недоступен.
  const after = await (await ctx.post('/api.php', { form: { action: 'get_user_socials' } })).json();
  expect(after.success, 'после logout сессии нет').toBeFalsy();

  await ctx.dispose();
});
