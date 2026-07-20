<?php
namespace Components\DbAdmin;

use Core\Component;
use Infra\Database;
use Domain\Auth;

class DbAdminComponent extends Component {

    /** Допустимые операторы фильтра (AR3-5: единый список для view и CSV). */
    private const VALID_OPERATORS = ['=', '!=', '>', '<', '>=', '<=', 'LIKE', 'BETWEEN'];


    public function executeComponent() {
        if (!Auth::isAdmin()) {
            echo "Access Denied";
            return;
        }

        $db = Database::getInstance()->getConnection();
        
        $action = $_GET['db_action'] ?? 'list';
        $table = $_GET['table'] ?? '';

        // get_row / update_row / export — через api.php (MLP-255: Api\DbAdminController);
        // здесь остался только рендер списка/просмотра (GET-навигация по вкладке).

        // --- VIEW LOGIC ---
        $this->result['action'] = $action;
        $this->result['tables'] = [];
        $this->result['current_table'] = $table;
        $this->result['columns'] = [];
        $this->result['rows'] = [];
        $this->result['error'] = null;
        $this->result['pagination'] = [];
        // MLP-257: мультифильтр (AND) + серверная сортировка.
        // sort/dir строго строками — массив в параметре не должен ронять рендер (ревью).
        $sortCol = $_GET['sort'] ?? '';
        $sortDir = $_GET['dir'] ?? '';
        $this->result['filters'] = self::parseFilters($_GET);
        $this->result['sort'] = [
            'col' => is_string($sortCol) ? $sortCol : '',
            'dir' => (is_string($sortDir) && strtolower($sortDir) === 'desc') ? 'desc' : 'asc',
        ];

        // 1. Get Table List
        try {
            $stmt = $db->query("SHOW TABLES");
            while ($row = $stmt->fetch_array()) {
                $this->result['tables'][] = $row[0];
            }
        } catch (\Exception $e) {
            error_log('DbAdmin tables: ' . $e->getMessage());
            $this->result['error'] = "Ошибка получения списка таблиц.";
        }

        // 2. View Specific Table
        if ($action === 'view' && $table) {
            if (!in_array($table, $this->result['tables'])) {
                $this->result['error'] = "Таблица '$table' не найдена.";
            } else {
                $this->fetchTableData($db, $table);
            }
        }

        $this->result['pk'] = $this->getPrimaryKey($db, $table);

        $this->includeTemplate();
    }

    /**
     * H3: динамический whitelist из схемы. Имена таблиц/колонок нельзя
     * параметризовать в SQL — их подставляют в backticks, поэтому единственная
     * защита от identifier-injection: сверять с реальной схемой перед подстановкой.
     */
    private function validTables($db): array {
        $tables = [];
        $res = $db->query("SHOW TABLES");
        while ($res && $row = $res->fetch_array()) {
            $tables[] = $row[0];
        }
        return $tables;
    }

    private function isValidTable($db, $table): bool {
        return self::isKnown($table, $this->validTables($db));
    }

    /** Pure: имя есть в whitelist (строгое сравнение — отсекает wildcard/injection). */
    public static function isKnown($name, array $whitelist): bool {
        return is_string($name) && $name !== '' && in_array($name, $whitelist, true);
    }

    /** Pure: оставить только поля, чьи ключи — реальные колонки таблицы (H3). */
    public static function keepKnownColumns(array $data, array $validCols): array {
        return array_intersect_key($data, array_flip($validCols));
    }

    /** Список колонок таблицы. $table ДОЛЖЕН быть предварительно провалидирован isValidTable(). */
    private function validColumns($db, $table): array {
        $cols = [];
        $res = $db->query("SHOW COLUMNS FROM `$table`");
        while ($res && $c = $res->fetch_assoc()) {
            $cols[] = $c['Field'];
        }
        return $cols;
    }

    /**
     * MLP-257: условия фильтра из запроса. Новый формат — параллельные массивы
     * filter_col[]/filter_op[]/filter_val[]; legacy-одиночный
     * filter_column/filter_operator/filter_value принимается для старых закладок.
     * Пустые значения и рассинхрон длин отбрасываются. Pure — юнит-тестируется.
     *
     * @return array<int, array{col: string, op: string, val: string}>
     */
    public static function parseFilters(array $params): array {
        $cols = $params['filter_col'] ?? [];
        $ops  = $params['filter_op']  ?? [];
        $vals = $params['filter_val'] ?? [];

        // Legacy-одиночный фильтр (до MLP-257)
        if (!$cols && !empty($params['filter_column'])) {
            $cols = [$params['filter_column']];
            $ops  = [$params['filter_operator'] ?? '='];
            $vals = [$params['filter_value'] ?? ''];
        }

        if (!is_array($cols) || !is_array($ops) || !is_array($vals)) return [];

        $conditions = [];
        foreach ($cols as $i => $col) {
            $val = $vals[$i] ?? null;
            if (!is_string($col) || $col === '' || !is_string($val) || $val === '') continue;
            $op = $ops[$i] ?? '=';
            $conditions[] = ['col' => $col, 'op' => is_string($op) ? $op : '=', 'val' => $val];
        }
        return $conditions;
    }

    /**
     * MLP-257: WHERE по набору условий (AND). Семантика прежнего одиночного
     * фильтра сохранена: неизвестная колонка → условие игнорируется, оператор
     * вне whitelist → '=', LIKE оборачивается %...%, BETWEEN ждёт «min,max»
     * (иначе фоллбек '='). Идентификаторы — только точный whitelist (H3),
     * значения — только плейсхолдеры. Pure — юнит-тестируется без БД.
     *
     * @return array{0: string, 1: string, 2: array} [WHERE-строка, types, values]
     */
    public static function buildWhere(array $conditions, array $validCols): array {
        $parts = [];
        $types = "";
        $values = [];

        foreach ($conditions as $c) {
            $col = $c['col'] ?? '';
            if (!self::isKnown($col, $validCols)) continue; // H3

            $op = $c['op'] ?? '=';
            if (!in_array($op, self::VALID_OPERATORS, true)) $op = '=';
            $val = $c['val'] ?? '';

            if ($op === 'LIKE') {
                $parts[] = "`$col` LIKE ?";
                $types .= "s";
                $values[] = "%" . $val . "%";
            } elseif ($op === 'BETWEEN') {
                $range = explode(',', $val, 2);
                if (count($range) === 2) {
                    $parts[] = "`$col` BETWEEN ? AND ?";
                    $types .= "ss";
                    $values[] = trim($range[0]);
                    $values[] = trim($range[1]);
                } else {
                    $parts[] = "`$col` = ?"; // фоллбек при кривом формате
                    $types .= "s";
                    $values[] = $val;
                }
            } else {
                $parts[] = "`$col` $op ?";
                $types .= "s";
                $values[] = $val;
            }
        }

        $where = $parts ? 'WHERE ' . implode(' AND ', $parts) : '';
        return [$where, $types, $values];
    }

    /**
     * MLP-257: ORDER BY по whitelist-колонке; направление строго ASC|DESC
     * (дефолт ASC). Неизвестная колонка → пустая строка (без сортировки).
     * Pure — юнит-тестируется.
     */
    public static function buildOrderBy(?string $col, ?string $dir, array $validCols): string {
        if (!self::isKnown($col, $validCols)) return '';
        $dir = strtoupper((string)$dir) === 'DESC' ? 'DESC' : 'ASC';
        return "ORDER BY `$col` $dir";
    }

    /** MLP-255: public + $id параметром — зовётся из Api\DbAdminController (POST). */
    public function ajaxGetRow($db, $table, $id = null) {
        if (!$this->isValidTable($db, $table)) {
            echo json_encode(['success' => false, 'message' => 'Invalid table']);
            return;
        }
        $pk = $this->getPrimaryKey($db, $table);
        if (!$pk) {
            echo json_encode(['success' => false, 'message' => 'No primary key found']);
            return;
        }

        if (!$id) {
            echo json_encode(['success' => false, 'message' => 'No ID provided']);
            return;
        }

        $sql = "SELECT * FROM `$table` WHERE `$pk` = ?";
        $stmt = $db->prepare($sql);
        $stmt->bind_param("s", $id);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();

        if ($row) {
            // Get column types to render correct inputs
            $colTypes = [];
            $res = $db->query("SHOW COLUMNS FROM `$table`");
            while ($c = $res->fetch_assoc()) {
                $colTypes[$c['Field']] = $c['Type'];
            }
            
            echo json_encode(['success' => true, 'data' => $row, 'pk' => $pk, 'types' => $colTypes]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Row not found']);
        }
    }

    /** MLP-255: public — зовётся из Api\DbAdminController (POST на api.php). */
    public function ajaxUpdateRow($db, $table) {
        if (!$this->isValidTable($db, $table)) {
            echo json_encode(['success' => false, 'message' => 'Invalid table']);
            return;
        }
        $pk = $this->getPrimaryKey($db, $table);
        if (!$pk) {
            echo json_encode(['success' => false, 'message' => 'No primary key found']);
            return;
        }

        $id = $_POST['__pk_value'] ?? null;
        if (!$id) {
            echo json_encode(['success' => false, 'message' => 'No ID provided']);
            return;
        }

        // Filter out internal fields
        // MLP-255: + action/csrf_token (транспорт через api.php). Побочное ограничение:
        // колонку таблицы с именем 'action' через модалку не отредактировать.
        $data = [];
        foreach ($_POST as $key => $val) {
            if (!in_array($key, ['__pk_value', 'db_action', 'table', 'action', 'csrf_token'], true)) {
                // If value is empty string, check if we should set it to NULL?
                // For now, let's treat empty string as empty string, unless standard practice.
                // However, for nullable fields, empty string might mean NULL.
                // Let's stick to string for now.
                $data[$key] = $val;
            }
        }

        // H3: обновляем только колонки, реально существующие в таблице —
        // имена колонок идут ключами $_POST и подставляются в backticks.
        $data = self::keepKnownColumns($data, $this->validColumns($db, $table));

        if (empty($data)) {
            echo json_encode(['success' => false, 'message' => 'No data to update']);
            return;
        }

        // Build Update Query
        $setParts = [];
        $types = "";
        $values = [];

        foreach ($data as $col => $val) {
            $setParts[] = "`$col` = ?";
            $types .= "s";
            // Convert empty string to NULL for update
            $values[] = ($val === '') ? null : $val;
        }

        $sql = "UPDATE `$table` SET " . implode(', ', $setParts) . " WHERE `$pk` = ?";
        $types .= "s";
        $values[] = $id;

        try {
            $stmt = $db->prepare($sql);
            if (!$stmt) {
                 throw new \Exception("Prepare failed: " . $db->error);
            }
            
            $stmt->bind_param($types, ...$values);
            
            if ($stmt->execute()) {
                echo json_encode(['success' => true]);
            } else {
                throw new \Exception("Execute failed: " . $stmt->error);
            }
        } catch (\Exception $e) {
            error_log('DbAdmin updateRow: ' . $e->getMessage());
            echo json_encode(['success' => false, 'message' => 'Ошибка сохранения строки.']);
        }
    }

    private function getPrimaryKey($db, $table) {
        if (empty($table)) return null;
        $res = $db->query("SHOW KEYS FROM `$table` WHERE Key_name = 'PRIMARY'");
        if ($res && $row = $res->fetch_assoc()) {
            return $row['Column_name'];
        }
        // Fallback: try 'id'
        $res = $db->query("SHOW COLUMNS FROM `$table` LIKE 'id'");
        if ($res && $res->num_rows > 0) return 'id';
        return null;
    }

    private function fetchTableData($db, $table) {
        // Pagination
        $page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
        if ($page < 1) $page = 1;
        $limit = 50;
        $offset = ($page - 1) * $limit;

        // Get columns first (needed for filter dropdown even if empty result)
        $colRes = $db->query("SHOW COLUMNS FROM `$table`");
        while ($col = $colRes->fetch_assoc()) {
            $this->result['columns'][] = $col['Field'];
        }

        // MLP-257: мультифильтр + сортировка — единые построители (те же, что у CSV)
        list($where, $types, $values) = self::buildWhere($this->result['filters'], $this->result['columns']);
        $orderBy = self::buildOrderBy($this->result['sort']['col'], $this->result['sort']['dir'], $this->result['columns']);

        // MLP-257: несовместимая пара оператор/значение больше не роняет страницу
        try {
            // Count total with filter
            $countSql = "SELECT COUNT(*) as cnt FROM `$table` $where";
            $stmt = $db->prepare($countSql);
            if ($where) {
                $stmt->bind_param($types, ...$values);
            }
            $stmt->execute();
            $totalRows = $stmt->get_result()->fetch_assoc()['cnt'];

            // Fetch rows with filter + sort
            $sql = "SELECT * FROM `$table` $where $orderBy LIMIT $limit OFFSET $offset";
            $stmt = $db->prepare($sql);
            if ($where) {
                $stmt->bind_param($types, ...$values);
            }
            $stmt->execute();
            $result = $stmt->get_result();

            if ($result) {
                while ($row = $result->fetch_assoc()) {
                    $this->result['rows'][] = $row;
                }
            }

            $this->result['pagination'] = [
                'current' => $page,
                'total_pages' => ceil($totalRows / $limit),
                'total_rows' => $totalRows
            ];
        } catch (\Throwable $e) {
            error_log('DbAdmin fetchTableData: ' . $e->getMessage());
            $this->result['error'] = 'Запрос не выполнился — проверь фильтры (оператор/значение).';
            $this->result['rows'] = [];
            $this->result['pagination'] = ['current' => 1, 'total_pages' => 0, 'total_rows' => 0];
        }
    }

    public function exportCsv($table, $params = []) {
        $db = Database::getInstance()->getConnection();
        
        // Security check
        $stmt = $db->query("SHOW TABLES");
        $validTables = [];
        while ($row = $stmt->fetch_array()) {
            $validTables[] = $row[0];
        }
        if (!in_array($table, $validTables, true)) die("Invalid table");

        // Headers
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=' . $table . '_' . date('Y-m-d_H-i') . '.csv');
        $output = fopen('php://output', 'w');
        fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF)); // BOM

        // MLP-257: те же построители, что у просмотра — экспорт видит ровно то,
        // что на экране (мультифильтр + сортировка), и только через prepared
        // statements (ручное экранирование значений ликвидировано).
        // Компромисс: get_result() буферизует результат — для admin-масштаба
        // приемлемо, зато без интерполяции значений в SQL.
        $validCols = $this->validColumns($db, $table);
        list($where, $types, $values) = self::buildWhere(self::parseFilters($params), $validCols);
        // sort/dir строго строками (массив не должен ронять экспорт — ревью)
        $sortCol = $params['sort'] ?? null;
        $sortDir = $params['dir'] ?? null;
        $orderBy = self::buildOrderBy(is_string($sortCol) ? $sortCol : null, is_string($sortDir) ? $sortDir : null, $validCols);

        try {
            $stmt = $db->prepare("SELECT * FROM `$table` $where $orderBy");
            if ($where) {
                $stmt->bind_param($types, ...$values);
            }
            $stmt->execute();
            $result = $stmt->get_result();

            if ($result) {
                $fields = $result->fetch_fields();
                $headers = [];
                foreach ($fields as $field) $headers[] = $field->name;
                fputcsv($output, $headers);

                while ($row = $result->fetch_row()) {
                    fputcsv($output, $row);
                }
            }
        } catch (\Throwable $e) {
            error_log('DbAdmin exportCsv: ' . $e->getMessage());
            fputcsv($output, ['Ошибка экспорта — проверь фильтры.']);
        }

        fclose($output);
    }
}
