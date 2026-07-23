// MLP-261 (AR6-1) — разделение пользовательских и системных текстов ошибок.
// Проверяем, что валидационные тексты (Core\UserError) ДОХОДЯТ до пользователя
// как есть (не заменяются на «Что-то пошло не так»), а ответ не содержит
// системных деталей. Системный путь (маскирование) покрыт интеграционным
// тестом tests/integration_user_error.php — из браузера его не спровоцировать.
//
// Env: MLP_BASE_URL, MLP_LOGIN, MLP_PASS (тестовый юзер Claude).
//   MLP_ADMIN=1 — если роль Claude на проде admin (включает тест импорта ZIP).

const { test, expect, request } = require('@playwright/test');

const BASE = process.env.MLP_BASE_URL;
const LOGIN = process.env.MLP_LOGIN;
const PASS = process.env.MLP_PASS;
const IS_ADMIN = process.env.MLP_ADMIN === '1';

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

// --- Пользовательский текст UserError доходит до UI (update_profile, аватар) ---
test('update_profile: валидационный текст аплоада виден пользователю (MLP-261)', async () => {
  expect(BASE, 'MLP_BASE_URL must be set').toBeTruthy();
  const { ctx, csrf } = await loginCtx();

  // SSRF-гейт UploadManager — детерминированный UserError без внешней сети.
  const res = await (await ctx.post('/api.php', {
    form: { action: 'update_profile', avatar_url: 'http://127.0.0.1/x.png', csrf_token: csrf },
    headers: { 'X-CSRF-Token': csrf },
  })).json();

  expect(res.success, 'must fail').toBeFalsy();
  expect(res.message, 'префикс контекста сохранён').toContain('Аватар:');
  expect(res.message, 'пользовательский текст не замаскирован').toContain('внутренний адрес');
  expect(res.message, 'нет общего текста вместо валидационного').not.toContain('Что-то пошло не так');

  await ctx.dispose();
});

// --- Импорт стикеров: не-ZIP → «Только ZIP архивы!» (admin) ---
test('import_zip_stickers: не-ZIP файл отбивается пользовательским текстом (MLP-261)', async () => {
  test.skip(!IS_ADMIN, 'нужен MLP_ADMIN=1 (роль Claude=admin)');
  const { ctx, csrf } = await loginCtx();

  const res = await (await ctx.post('/api.php', {
    multipart: {
      action: 'import_zip_stickers',
      pack_id: '1',
      csrf_token: csrf,
      zip_file: { name: 'not-a-zip.txt', mimeType: 'text/plain', buffer: Buffer.from('пони') },
    },
    headers: { 'X-CSRF-Token': csrf },
  })).json();

  expect(res.success, 'must fail').toBeFalsy();
  expect(res.message, 'текст валидации ZIP виден').toContain('Только ZIP архивы');

  await ctx.dispose();
});

// --- Ответ об ошибке не содержит системных деталей ---
test('тексты ошибок не содержат следов SQL/путей (MLP-261)', async () => {
  expect(BASE, 'MLP_BASE_URL must be set').toBeTruthy();
  const { ctx, csrf } = await loginCtx();

  // Занятый логин при обновлении профиля — UserError, но проверяем и общую гигиену ответа.
  const res = await (await ctx.post('/api.php', {
    form: { action: 'update_profile', avatar_url: 'http://169.254.169.254/latest/meta-data', csrf_token: csrf },
    headers: { 'X-CSRF-Token': csrf },
  })).json();

  expect(res.success).toBeFalsy();
  for (const marker of ['mysqli', 'SQLSTATE', '/var/www', 'Stack trace', '.php:']) {
    expect(res.message, `ответ не содержит «${marker}»`).not.toContain(marker);
  }

  await ctx.dispose();
});
