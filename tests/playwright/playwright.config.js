// Конфиг Playwright: API-тесты (request-контекст) + браузерные UI-тесты (*.ui.spec.js).
// API-тесты браузеров не требуют; для UI: npx playwright install chromium firefox.
// UI-баги вида MLP-253 (контекстное меню) ловятся только в реальных движках,
// причём поведение различается (Gecko vs Blink) — поэтому два браузерных проекта.
module.exports = {
  testDir: __dirname,
  timeout: 30000,
  reporter: [['list']],
  // По одному воркеру: тесты ходят на живой прод и зависят от роли тестового юзера.
  workers: 1,
  projects: [
    { name: 'api', testIgnore: /\.ui\.spec\.js$/ },
    { name: 'chromium-ui', testMatch: /\.ui\.spec\.js$/, use: { browserName: 'chromium' } },
    { name: 'firefox-ui', testMatch: /\.ui\.spec\.js$/, use: { browserName: 'firefox' } },
  ],
};
