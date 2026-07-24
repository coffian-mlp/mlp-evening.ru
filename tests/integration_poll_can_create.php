<?php
use Domain\PollManager;
use Infra\ConfigManager;
/**
 * Интеграционный тест PollManager::canCreate() (MLP-281, AR7-1): единая точка
 * правила polls_create_role. Матрица «настройка × роль сессии».
 *
 * Сессию эмулируем через $_SESSION (CLI): canCreate → Auth::check/isAdmin/isModerator
 * читают её же. Опцию пишем через ConfigManager (владелец site_options) и
 * восстанавливаем исходное значение в finally.
 *
 * Запуск: docker compose exec php php tests/integration_poll_can_create.php
 */
require_once __DIR__ . '/integration_helpers.php';

it_require_db();
$cfg = ConfigManager::getInstance();
$orig = $cfg->getOption('polls_create_role', null);

function as_user(?string $role): void {
    if ($role === null) {
        unset($_SESSION['user_id'], $_SESSION['role']);
    } else {
        $_SESSION['user_id'] = 999999;
        $_SESSION['role'] = $role;
    }
}

try {
    // [настройка, роль сессии (null = гость), ожидание]
    $matrix = [
        ['all',       null,        false],
        ['all',       'user',      true],
        ['all',       'moderator', true],
        ['all',       'admin',     true],
        ['admin',     'user',      false],
        ['admin',     'moderator', false],
        ['admin',     'admin',     true],
        ['moderator', null,        false],
        ['moderator', 'user',      false],
        ['moderator', 'moderator', true],
        ['moderator', 'admin',     true],
    ];
    foreach ($matrix as [$setting, $role, $expect]) {
        $cfg->setOption('polls_create_role', $setting);
        as_user($role);
        $label = sprintf("polls_create_role=%s, роль=%s → %s", $setting, $role ?? 'гость', $expect ? 'да' : 'нет');
        check(PollManager::canCreate() === $expect, $label);
    }

    // Дефолт (опция отсутствует) = moderator.
    $cfg->setOption('polls_create_role', '');
    as_user('user');
    // пустая строка — getOption вернёт '', не дефолт; проверяем ветку else (=> isModerator)
    check(PollManager::canCreate() === false, "пустая настройка ведёт себя как 'moderator' для user");
    as_user('moderator');
    check(PollManager::canCreate() === true, "пустая настройка ведёт себя как 'moderator' для модера");
} finally {
    as_user(null);
    if ($orig !== null) {
        $cfg->setOption('polls_create_role', $orig);
    }
}

it_done();
