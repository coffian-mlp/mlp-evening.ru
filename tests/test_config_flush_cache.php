<?php
use Infra\ConfigManager;
/**
 * Юнит-тест ConfigManager::flushCache() — AR2-1 (MLP-227).
 *
 * Проверяет, что request-scoped кеш опций можно принудительно сбросить, и
 * следующий getOption() перечитает свежие данные (важно для daemon-воркера,
 * где синглтон ConfigManager живёт весь процесс).
 *
 * БД не нужна: ConfigManager создаётся без конструктора (reflection), $db
 * подменяется фейком, который отдаёт управляемый набор строк site_options.
 *
 * Запуск: php tests/test_config_flush_cache.php
 */

// ConfigManager тянет Database.php, а тот на верхнем уровне требует config.php.
// На чистом клоне без конфига тест нечего запускать — мягко пропускаем.
if (!file_exists(__DIR__ . '/../.env') && !file_exists(__DIR__ . '/../config.php')) {
    echo "SKIP: нет .env/config.php (нужен для загрузки Database.php)\n";
    exit(0);
}

require_once __DIR__ . '/../autoload.php';

$fail = 0;
function eq($got, $want, $label) {
    global $fail;
    if ($got === $want) { echo "  [OK] $label\n"; }
    else { echo "  [FAIL] $label\n        want: " . var_export($want, true) . "\n        got:  " . var_export($got, true) . "\n"; $fail++; }
}

/** Фейковый результат mysqli: отдаёт строки по одной через fetch_assoc(). */
class FakeResult {
    private $rows;
    private $i = 0;
    public function __construct(array $rows) { $this->rows = $rows; }
    public function fetch_assoc() {
        return $this->i < count($this->rows) ? $this->rows[$this->i++] : null;
    }
}

/** Фейковый mysqli: отдаёт текущий снимок site_options на каждый query(). */
class FakeDb {
    public $dataset = [];   // key_name => value
    public $queryCount = 0;
    public function query($sql) {
        $this->queryCount++;
        $rows = [];
        foreach ($this->dataset as $k => $v) {
            $rows[] = ['key_name' => $k, 'value' => $v];
        }
        return new FakeResult($rows);
    }
}

// --- Сборка ConfigManager с подменённым $db (без конструктора/БД) ---
$ref = new ReflectionClass(ConfigManager::class);
$cm  = $ref->newInstanceWithoutConstructor();

$dbProp = $ref->getProperty('db'); // PHP 8.1+: private доступно из reflection без setAccessible
$fakeDb = new FakeDb();
$fakeDb->dataset = ['ai_prompt' => 'старый промпт'];
$dbProp->setValue($cm, $fakeDb);

echo "== Первое чтение грузит и кеширует ==\n";
eq($cm->getOption('ai_prompt'), 'старый промпт', 'getOption читает исходное значение');
eq($fakeDb->queryCount, 1, 'первый getOption сделал 1 SELECT');
$cm->getOption('ai_prompt');
eq($fakeDb->queryCount, 1, 'повторный getOption берёт из кеша (без SELECT)');

echo "\n== Правка в БД не видна без сброса кеша (это и есть баг daemon) ==\n";
$fakeDb->dataset['ai_prompt'] = 'новый промпт';
eq($cm->getOption('ai_prompt'), 'старый промпт', 'кеш держит старое значение');

echo "\n== flushCache() заставляет перечитать ==\n";
$cm->flushCache();
eq($cm->getOption('ai_prompt'), 'новый промпт', 'после flushCache видно свежее значение');
eq($fakeDb->queryCount, 2, 'flushCache вызвал новый SELECT');

echo "\n== default для отсутствующего ключа ==\n";
eq($cm->getOption('missing_key', 42), 42, 'default возвращается для отсутствующего ключа');

echo "\n" . ($fail === 0 ? "ALL PASS\n" : "FAILURES: $fail\n");
exit($fail === 0 ? 0 : 1);
