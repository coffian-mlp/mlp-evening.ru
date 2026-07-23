// MLP-272 — смоук аплоада чата (давний хвост арх-ревью v4.8): живая загрузка
// PNG через api.php, отказ на запрещённый формат человеческим текстом, гейт гостя.
// Файл-превью остаётся в /upload/chat/ (мелкий, помечен pw272 в имени исходника).
//
// Env: MLP_BASE_URL, MLP_LOGIN, MLP_PASS.

const { test, expect, request } = require('@playwright/test');

const BASE = process.env.MLP_BASE_URL;
const LOGIN = process.env.MLP_LOGIN;
const PASS = process.env.MLP_PASS;

// 1×1 красный PNG
const PNG = Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR4nGP4z8DwHwAFAAH/q842iQAAAABJRU5ErkJggg==', 'base64');

async function loginCtx() {
  const ctx = await request.newContext({ baseURL: BASE });
  const login = await (await ctx.post('/api.php', { form: { action: 'login', username: LOGIN, password: PASS } })).json();
  expect(login.success).toBeTruthy();
  const html = await (await ctx.get('/')).text();
  return { ctx, csrf: html.match(/name="csrf-token"\s+content="([^"]+)"/)?.[1] };
}

test('аплоад: PNG загружается и доступен по URL (MLP-272)', async () => {
  expect(BASE, 'MLP_BASE_URL must be set').toBeTruthy();
  const { ctx, csrf } = await loginCtx();

  const res = await (await ctx.post('/api.php', {
    multipart: {
      action: 'upload_file',
      csrf_token: csrf,
      file: { name: 'pw272-pixel.png', mimeType: 'image/png', buffer: PNG },
    },
    headers: { 'X-CSRF-Token': csrf },
  })).json();

  expect(res.success, `upload: ${JSON.stringify(res)}`).toBeTruthy();
  expect(res.data.is_image, 'распознан как картинка').toBeTruthy();
  expect(res.data.url).toMatch(/^\/upload\/chat\/.+\.png$/);

  const fetched = await ctx.get(res.data.url);
  expect(fetched.status(), 'файл отдаётся по URL').toBe(200);

  await ctx.dispose();
});

test('аплоад: запрещённый формат отбивается человеческим текстом (MLP-272 + MLP-261)', async () => {
  const { ctx, csrf } = await loginCtx();

  const res = await (await ctx.post('/api.php', {
    multipart: {
      action: 'upload_file',
      csrf_token: csrf,
      file: { name: 'evil.exe', mimeType: 'application/octet-stream', buffer: Buffer.from('MZ\x90\x00static') },
    },
    headers: { 'X-CSRF-Token': csrf },
  })).json();

  expect(res.success).toBeFalsy();
  expect(res.message, 'текст про формат, не системщина').toContain('формат');
  expect(res.message).not.toContain('mysqli');

  await ctx.dispose();
});

test('аплоад: гость не грузит (MLP-272)', async () => {
  const guest = await request.newContext({ baseURL: BASE });
  const res = await (await guest.post('/api.php', { form: { action: 'upload_file' } })).json();
  expect(res.success).toBeFalsy();
  await guest.dispose();
});
