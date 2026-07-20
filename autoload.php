<?php
/**
 * Автозагрузчик классов (MLP-248, ADR-7). Без Composer (ADR-1).
 *
 * Правила:
 *  1. Класс с namespace — PSR-4 от src/: Core\Application → src/Core/Application.php.
 *  2. Глобальный класс — по карте src/classmap.php (временно, до фазы 3
 *     перекладки: после добавления namespaces карта опустеет и умрёт).
 *
 * Компоненты (Components\X\XComponent в class.php) в PSR-4 не вписываются —
 * их по-прежнему грузит Application::includeComponent() по конвенции;
 * PSR-4-ветка для них просто не найдёт файл и молча уступит.
 *
 * Подключение: require_once __DIR__ . '/autoload.php' в каждой точке входа
 * (init.php, api.php, chat_stream.php, CLI-скрипты) сразу после config.
 * Ручной require_once классов в новом коде запрещён (architecture.md, §13).
 */

spl_autoload_register(function ($class) {
    $base = __DIR__ . '/src/';

    if (strpos($class, '\\') !== false) {
        $file = $base . str_replace('\\', '/', $class) . '.php';
        if (is_file($file)) {
            require $file;
        }
        return;
    }

    static $map = null;
    if ($map === null) {
        $map = require $base . 'classmap.php';
    }
    if (isset($map[$class])) {
        require $base . $map[$class];
    }
});
