<?php
/**
 * Дрейф-гард карты настроек (MLP-285, AR7-6): каждое поле форм AdminSettings
 * (general + ai) обязано иметь правило в SettingsController::KEYS — иначе
 * админ жмёт «Сохранить», а ключ молча не пишется (тихая потеря настройки).
 *
 * БД не нужна. Запуск: php tests/test_settings_keys.php
 */

require_once __DIR__ . '/../autoload.php';

$fail = 0;
function ok($cond, $label) {
    global $fail;
    echo ($cond ? "  [OK] " : "  [FAIL] ") . $label . "\n";
    if (!$cond) $fail++;
}

$ref = new ReflectionClassConstant(Api\SettingsController::class, 'KEYS');
$keys = $ref->getValue();

echo "== Правила корректны по форме ==\n";
$badRules = [];
foreach ($keys as $key => $rule) {
    $valid = in_array($rule, ['int', 'uint', 'string', 'url'], true)
        || (is_array($rule) && ($rule[0] ?? '') === 'enum' && is_array($rule[1] ?? null) && $rule[1] !== []);
    if (!$valid) $badRules[] = $key;
}
ok($badRules === [], 'все правила известных типов' . ($badRules ? ' (плохие: ' . implode(', ', $badRules) . ')' : ''));

echo "\n== Формы дашборда покрыты картой ==\n";
$templates = [
    __DIR__ . '/../src/Components/AdminSettings/templates/general/template.php',
    __DIR__ . '/../src/Components/AdminSettings/templates/ai/template.php',
];
$ignored = ['action', 'csrf_token']; // служебные поля формы
$missing = [];
$formFields = [];
foreach ($templates as $tpl) {
    $src = file_get_contents($tpl);
    preg_match_all('/name="([a-z_0-9]+)"/', $src, $m);
    foreach (array_unique($m[1]) as $name) {
        if (in_array($name, $ignored, true)) continue;
        $formFields[] = $name;
        if (!isset($keys[$name])) $missing[] = $name;
    }
}
ok(count($formFields) > 40, 'поля форм найдены (' . count($formFields) . ' шт.)');
ok($missing === [], 'каждое поле формы есть в KEYS' . ($missing ? ' (нет: ' . implode(', ', $missing) . ')' : ''));

echo "\n" . ($fail === 0 ? "ALL PASS" : "FAILED: $fail") . "\n";
exit($fail === 0 ? 0 : 1);
