<?php
/**
 * Автозагрузчик классов (MLP-248/MLP-249, ADR-7). Без Composer (ADR-1).
 *
 * Чистый PSR-4 от src/: namespace = путь (Infra\Database → src/Infra/Database.php).
 * Временный classmap для глобальных классов (MLP-248) умер вместе с фазой 3:
 * все классы получили namespaces.
 *
 * Компоненты (Components\X\XComponent в class.php) в PSR-4 не вписываются —
 * их грузит Application::includeComponent() по конвенции; PSR-4-ветка для них
 * молча промахивается (файла <X>Component.php нет), класс уже определён.
 *
 * Подключение: require_once __DIR__ . '/autoload.php' в каждой точке входа
 * сразу после config. Ручной require_once классов запрещён (architecture.md, §13).
 */

spl_autoload_register(function ($class) {
    $file = __DIR__ . '/src/' . str_replace('\\', '/', $class) . '.php';
    if (is_file($file)) {
        require $file;
    }
});
