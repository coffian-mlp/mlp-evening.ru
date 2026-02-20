<?php
namespace Components\DbAdmin;

use Core\Component;
use Database;
use Auth;

class DbAdminComponent extends Component {
    
    public function executeComponent() {
        if (!Auth::isAdmin()) {
            echo "Access Denied";
            return;
        }

        $db = Database::getInstance()->getConnection();
        
        $action = $_GET['db_action'] ?? 'list';
        $table = $_GET['table'] ?? '';
        
        // --- API ACTION: GET ROW FOR EDIT ---
        if ($action === 'get_row' && $table) {
            $this->ajaxGetRow($db, $table);
            exit;
        }

        // --- API ACTION: UPDATE ROW ---
        if ($action === 'update_row' && $table) {
            $this->ajaxUpdateRow($db, $table);
            exit;
        }

        // --- VIEW LOGIC ---
        $this->result['action'] = $action;
        $this->result['tables'] = [];
        $this->result['current_table'] = $table;
        $this->result['columns'] = [];
        $this->result['rows'] = [];
        $this->result['error'] = null;
        $this->result['pagination'] = [];
        $this->result['filter'] = [
            'column' => $_GET['filter_column'] ?? '',
            'operator' => $_GET['filter_operator'] ?? '=',
            'value' => $_GET['filter_value'] ?? ''
        ];

        // 1. Get Table List
        try {
            $stmt = $db->query("SHOW TABLES");
            while ($row = $stmt->fetch_array()) {
                $this->result['tables'][] = $row[0];
            }
        } catch (\Exception $e) {
            $this->result['error'] = "Ошибка получения списка таблиц: " . $e->getMessage();
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

    private function buildWhereClause($db, $params) {
        $where = "";
        $types = "";
        $values = [];

        if (!empty($params['filter_column']) && isset($params['filter_value']) && $params['filter_value'] !== '') {
            $col = $db->real_escape_string($params['filter_column']);
            $op = $params['filter_operator'];
            $val = $params['filter_value'];

            // Validate operator whitelist
            $validOps = ['=', '!=', '>', '<', '>=', '<=', 'LIKE', 'BETWEEN'];
            if (!in_array($op, $validOps)) $op = '=';

            if ($op === 'LIKE') {
                $where = "WHERE `$col` LIKE ?";
                $types .= "s";
                $values[] = "%" . $val . "%";
            } elseif ($op === 'BETWEEN') {
                // Expect value like "min,max"
                $parts = explode(',', $val, 2);
                if (count($parts) === 2) {
                    $where = "WHERE `$col` BETWEEN ? AND ?";
                    $types .= "ss";
                    $values[] = trim($parts[0]);
                    $values[] = trim($parts[1]);
                } else {
                    // Fallback if format invalid
                    $where = "WHERE `$col` = ?";
                    $types .= "s";
                    $values[] = $val;
                }
            } else {
                $where = "WHERE `$col` $op ?";
                $types .= "s";
                $values[] = $val;
            }
        }
        return [$where, $types, $values];
    }

    private function ajaxGetRow($db, $table) {
        $pk = $this->getPrimaryKey($db, $table);
        if (!$pk) {
            echo json_encode(['success' => false, 'message' => 'No primary key found']);
            return;
        }

        $id = $_GET['id'] ?? null;
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

    private function ajaxUpdateRow($db, $table) {
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
        $data = [];
        foreach ($_POST as $key => $val) {
            if ($key !== '__pk_value' && $key !== 'db_action' && $key !== 'table') {
                // If value is empty string, check if we should set it to NULL?
                // For now, let's treat empty string as empty string, unless standard practice.
                // However, for nullable fields, empty string might mean NULL. 
                // Let's stick to string for now.
                $data[$key] = $val;
            }
        }

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
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
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
        $limit = 50;
        $offset = ($page - 1) * $limit;

        // Build Filter
        list($where, $types, $values) = $this->buildWhereClause($db, $_GET);

        // Get columns first (needed for filter dropdown even if empty result)
        $colRes = $db->query("SHOW COLUMNS FROM `$table`");
        while ($col = $colRes->fetch_assoc()) {
            $this->result['columns'][] = $col['Field'];
        }

        // Count total with filter
        $countSql = "SELECT COUNT(*) as cnt FROM `$table` $where";
        $stmt = $db->prepare($countSql);
        if ($where) {
            $stmt->bind_param($types, ...$values);
        }
        $stmt->execute();
        $totalRows = $stmt->get_result()->fetch_assoc()['cnt'];
        
        // Fetch rows with filter
        $sql = "SELECT * FROM `$table` $where LIMIT $limit OFFSET $offset";
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
    }

    public function exportCsv($table, $params = []) {
        $db = Database::getInstance()->getConnection();
        
        // Security check
        $stmt = $db->query("SHOW TABLES");
        $validTables = [];
        while ($row = $stmt->fetch_array()) {
            $validTables[] = $row[0];
        }
        if (!in_array($table, $validTables)) die("Invalid table");

        // Headers
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=' . $table . '_' . date('Y-m-d_H-i') . '.csv');
        $output = fopen('php://output', 'w');
        fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF)); // BOM

        // Build Filter
        list($where, $types, $values) = $this->buildWhereClause($db, $params);

        // Stream Query
        // Note: Prepared statements with unbuffered results in MySQLi are tricky.
        // We will use standard query here but carefully escape values since bind_param is hard with unbuffered.
        // ACTUALLY, let's use prepared statement + get_result + fetch_row loop. 
        // For huge exports, real_query + use_result is better, but requires manual escaping.
        // Let's stick to safe manual escaping for export to keep it simple and safe enough for admins.
        
        $sql = "SELECT * FROM `$table` $where";
        
        // Re-construct SQL with escaped values because bind_param doesn't support unbuffered easily across all drivers
        if ($where) {
            $col = $db->real_escape_string($params['filter_column']);
            $op = $params['filter_operator'];
            $val = $db->real_escape_string($params['filter_value']);
            $validOps = ['=', '!=', '>', '<', '>=', '<=', 'LIKE', 'BETWEEN'];
            if (!in_array($op, $validOps)) $op = '=';
            
            if ($op === 'LIKE') {
                 $sql = "SELECT * FROM `$table` WHERE `$col` LIKE '%$val%'";
            } elseif ($op === 'BETWEEN') {
                 $parts = explode(',', $params['filter_value'], 2);
                 if (count($parts) === 2) {
                     $val1 = $db->real_escape_string(trim($parts[0]));
                     $val2 = $db->real_escape_string(trim($parts[1]));
                     $sql = "SELECT * FROM `$table` WHERE `$col` BETWEEN '$val1' AND '$val2'";
                 } else {
                     $val = $db->real_escape_string($params['filter_value']);
                     $sql = "SELECT * FROM `$table` WHERE `$col` = '$val'";
                 }
            } else {
                 $sql = "SELECT * FROM `$table` WHERE `$col` $op '$val'";
            }
        }

        $result = $db->query($sql, MYSQLI_USE_RESULT); // Unbuffered mode
        
        if ($result) {
            $fields = $result->fetch_fields();
            $headers = [];
            foreach ($fields as $field) $headers[] = $field->name;
            fputcsv($output, $headers);
            
            while ($row = $result->fetch_row()) {
                fputcsv($output, $row);
            }
        }
        
        fclose($output);
    }
}
