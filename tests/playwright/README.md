# Playwright-тесты (API-уровень)

Проверяют реальные HTTP-endpoint'ы `api.php` на живом стенде (у проекта нет
локальной среды — тестируем на проде, см. PROJECT_PROFILE.md). Используют
Playwright `request`-контекст, **браузеры не нужны** (`playwright install` не требуется).

Креды не хранятся в репозитории — передаются через переменные окружения.

## MLP-218 — admin-gate на update_settings (C1/C2)

Тест `mlp-218-admin-gate.spec.js` проверяет, что `action=update_settings`:
- отклоняется для не-админа (`Access Denied`) — AC-1;
- проходит для админа — AC-2.

Роль тестового пользователя (`Claude`) переключается между прогонами на проде
(разрешено PROJECT_PROFILE.md). Между сменой роли нужен свежий логин — тест
логинится сам при каждом запуске.

```bash
# AC-1: роль пользователя = user  → ожидаем отказ
MLP_BASE_URL="https://mlp-evening.ru" MLP_LOGIN="Claude" MLP_PASS="<pass>" MLP_EXPECT=denied \
  npx playwright test -c tests/playwright/playwright.config.js

# (на проде: UPDATE users SET role='admin' WHERE login='Claude'; )

# AC-2: роль пользователя = admin → ожидаем успех
MLP_BASE_URL="https://mlp-evening.ru" MLP_LOGIN="Claude" MLP_PASS="<pass>" MLP_EXPECT=allowed \
  npx playwright test -c tests/playwright/playwright.config.js

# (на проде вернуть: UPDATE users SET role='user' WHERE login='Claude'; )
```

## MLP-363 — живые реплики команд пожеланий

`mlp-361-command-interactions.ui.spec.js` содержит отдельный сценарий MLP-363 для Chromium и Firefox. Docker-only режим `live` fixture вызывает настоящий dispatcher, PlaylistCommand, LLMManager и provider; подменяется только внешний curl transport через `mlp-363-live-transport.php`. HTTP API, DOM и обработчик кнопок остаются настоящими. Проверяется доставка свободной реплики, отсутствие голоса до выбора, один голос после выбора, идемпотентность повтора и состояние после перезагрузки. Настройки live/provider временно меняются только в изолированной БД и возвращаются до браузерного запроса.

```bash
docker compose -p mlp359 -f docker-compose.yml -f docs/tests/MLP-359/compose.override.yml exec -T php php tests/playwright/mlp-361-interactions-fixture.php setup
MLP_BASE_URL=http://127.0.0.1:8091 npx playwright test -c tests/playwright/playwright.config.js mlp-361-command-interactions.ui.spec.js
docker compose -p mlp359 -f docker-compose.yml -f docs/tests/MLP-359/compose.override.yml exec -T php php tests/playwright/mlp-361-interactions-fixture.php cleanup
```

Сервер localhost должен работать отдельно. Полный PHP suite выполняется до setup или после cleanup: браузерный fixture временно меняет общие настройки. Cleanup обязателен также после FAIL. Credentials сохраняются только в ignored файле с правами 0600. Production API и provider данным fixture не вызываются.

Для диагностики живых реплик различай транспортный timeout и отклонение фактической проверки: оба случая штатно приводят к fallback. Live-режим не гарантирует генерацию при каждом вызове. Наличие работающих кнопок и отсутствие голосования до выбора проверяются независимо от формулировки; сценарий с выключенным AI не подтверждает live path.
