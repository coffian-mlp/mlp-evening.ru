const fs = require('fs');
const path = require('path');
const { expect } = require('@playwright/test');

const BASE = process.env.MLP_BASE_URL;
function localFixture() {
  const url = new URL(BASE || 'http://invalid');
  if (!['localhost', '127.0.0.1'].includes(url.hostname)) {
    throw new Error('MLP-360 tests require isolated localhost HTTP and CLI fixture');
  }
  return JSON.parse(fs.readFileSync(path.resolve(__dirname, '../../docs/private/mlp360-local.json'), 'utf8'));
}

async function login(ctx, fixture) {
  const response = await ctx.post(BASE + '/api.php', {
    form: { action: 'login', username: fixture.login, password: fixture.password },
  });
  expect((await response.json()).success, 'fixture login').toBeTruthy();
  const html = await (await ctx.get(BASE + '/')).text();
  const csrf = html.match(/name="csrf-token"\s+content="([^"]+)"/)?.[1];
  expect(csrf, 'CSRF token present').toBeTruthy();
  return csrf;
}
async function post(ctx, csrf, form) {
  return (await ctx.post(BASE + '/api.php', {
    form: { ...form, csrf_token: csrf }, headers: { 'X-CSRF-Token': csrf },
  })).json();
}
const NEW = { skull: '💀', clown: '🤡', hundred: '💯', poop: '💩' };
const OLD = { like:'👍', heart:'❤️', laugh:'😂', wow:'😮', fire:'🔥', party:'🎉',
  cool:'😎', think:'🤔', neutral:'😐', cry:'😢', eyes:'👀', dislike:'👎' };
module.exports = { BASE, localFixture, login, post, NEW, OLD };
