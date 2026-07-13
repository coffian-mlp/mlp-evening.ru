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
