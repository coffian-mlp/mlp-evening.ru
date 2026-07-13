// MLP-220 — убрать из вебрута опасные/отладочные скрипты (findings H2, L3).
// Удалены: install_bot.php (аноним пересоздавал бота), test_llm.php,
// yandex_test.php, test_parse.js. Под CLI-guard: cron_llm.php, bot_worker.php.
//
// Тест: через HTTP все эти пути НЕ доступны (404). CLI-запуск воркера
// проверяется отдельно на проде (см. docs/tests/MLP-220.tests.md).
//
// Env: MLP_BASE_URL.

const { test, expect, request } = require('@playwright/test');

const BASE = process.env.MLP_BASE_URL;

const PATHS = [
  '/install_bot.php',   // удалён
  '/test_llm.php',      // удалён
  '/yandex_test.php',   // удалён
  '/test_parse.js',     // удалён
  '/cron_llm.php',      // CLI-guard → 404 из веба
  '/bot_worker.php',    // CLI-guard → 404 из веба
];

test('dangerous/debug scripts are not web-accessible (MLP-220)', async () => {
  expect(BASE, 'MLP_BASE_URL must be set').toBeTruthy();
  const ctx = await request.newContext({ baseURL: BASE });

  for (const p of PATHS) {
    const res = await ctx.get(p);
    const status = res.status();
    // Не должно отдаваться успешно. Ожидаем 404 (удалённые файлы и CLI-guard),
    // допускаем 403 на случай серверной блокировки.
    expect(res.ok(), `${p}: expected NOT ok, got ${status}`).toBeFalsy();
    expect([403, 404], `${p}: expected 403/404, got ${status}`).toContain(status);
  }

  await ctx.dispose();
});
