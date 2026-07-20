<?php
/**
 * Автозагрузчик классов (MLP-248/MLP-249, ADR-7). Без Composer (ADR-1).
 *
 * Чистый PSR-4 от src/: namespace = путь (Infra\Database → src/Infra/Database.php).
 * Временный classmap для глобальных классов (MLP-248) умер вместе с фазой 3:
 * все классы получили namespaces.
 *
 * Компоненты (Components\X\<Name>Component в class.php) в PSR-4 не вписываются —
 * исторически их грузил Application::includeComponent(). С MLP-255 у автолоадера
 * есть компонентная ветка (Components\X\* → src/Components/X/class.php): классы
 * компонентов доступны и вне рендера (пример — Api\DbAdminController).
 *
 * Подключение: require_once __DIR__ . '/autoload.php' в каждой точке входа
 * сразу после config. Ручной require_once классов запрещён (architecture.md, §13).
 */

spl_autoload_register(function ($class) {
    $file = __DIR__ . '/src/' . str_replace('\\', '/', $class) . '.php';
    if (is_file($file)) {
        require $file;
        return;
    }
    // Компонентная конвенция: Components\<Dir>\<Class> живёт в src/Components/<Dir>/class.php
    if (preg_match('#^Components\\\\([^\\\\]+)\\\\[^\\\\]+$#', $class, $m)) {
        $file = __DIR__ . '/src/Components/' . $m[1] . '/class.php';
        if (is_file($file)) {
            require $file;
        }
    }
});
