// MLP-219 — admin-gate на state-changing экшены плейлиста/истории (finding H1).
// Гейтим: regenerate_playlist, vote, mark_watched, clear_votes,
// reset_times_watched, clear_watching_log. Все они вызываются только из
// админ-компонентов (AdminPlaylist/AdminSettings/AdminHistory); публичного
// вызова нет — проверено картой вызовов (docs/research/MLP-219.research.md).
//
// Тест проверяет БЕЗОПАСНОЕ направление AC-1: не-админ получает Access Denied.
// Гейт срабатывает ДО деструктивной операции (TRUNCATE/сброс), поэтому запуск
// от не-админа ничего не портит. Admin-направление для деструктивных экшенов
// НЕ исполняется на проде (сохранность данных) — оно тождественно уже
// доказанному в MLP-218 гейту (см. docs/qa/MLP-219.qa.md).
//
// Env: MLP_BASE_URL, MLP_LOGIN, MLP_PASS (не-админ, тестовый юзер Claude).

const { test, expect, request } = require('@playwright/test');

const BASE = process.env.MLP_BASE_URL;
const LOGIN = process.env.MLP_LOGIN;
const PASS = process.env.MLP_PASS;

const ACTIONS = [
  { action: 'regenerate_playlist' },
  { action: 'vote', extra: { episode_id: '1' } },
  { action: 'mark_watched', extra: { ids: '1' } },
  { action: 'clear_votes' },
  { action: 'reset_times_watched' },
  { action: 'clear_watching_log' },
];

test('H1 state-changing actions deny non-admin (MLP-219)', async () => {
  expect(BASE && LOGIN && PASS, 'MLP_BASE_URL/MLP_LOGIN/MLP_PASS must be set').toBeTruthy();

  const ctx = await request.newContext({ baseURL: BASE });

  const loginRes = await ctx.post('/api.php', {
    form: { action: 'login', username: LOGIN, password: PASS },
  });
  expect((await loginRes.json()).success, 'login failed').toBeTruthy();

  const html = await (await ctx.get('/')).text();
  const csrf = html.match(/name="csrf-token"\s+content="([^"]+)"/)?.[1];
  expect(csrf, 'csrf-token meta not found').toBeTruthy();

  for (const { action, extra } of ACTIONS) {
    const res = await ctx.post('/api.php', {
      form: { action, csrf_token: csrf, ...(extra || {}) },
      headers: { 'X-CSRF-Token': csrf },
    });
    const json = await res.json();
    expect(json.success, `${action}: expected denial, got ${JSON.stringify(json)}`).toBeFalsy();
    expect(json.message || '', `${action}: expected "Access Denied"`).toContain('Access Denied');
  }

  await ctx.dispose();
});
