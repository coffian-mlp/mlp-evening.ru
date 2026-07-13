// Минимальный конфиг Playwright для API-тестов проекта (request-контекст, без браузеров).
// Браузеры не нужны — тесты используют только `request`, поэтому `npx playwright install`
// запускать не требуется.
module.exports = {
  testDir: __dirname,
  timeout: 30000,
  reporter: [['list']],
  // По одному воркеру: тесты ходят на живой прод и зависят от роли тестового юзера.
  workers: 1,
};
