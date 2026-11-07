<?php
/** Split trusted migration SQL without treating quoted semicolons as delimiters. */
function historicalSeedStatements(string $sql): array {
    $statements = [];
    $statement = '';
    $quote = null;
    $length = strlen($sql);
    for ($index = 0; $index < $length; $index++) {
        $char = $sql[$index];
        if ($quote !== null) {
            $statement .= $char;
            if ($char === '\\' && $index + 1 < $length) {
                $statement .= $sql[++$index];
            } elseif ($char === $quote) {
                if ($index + 1 < $length && $sql[$index + 1] === $quote) {
                    $statement .= $sql[++$index];
                } else {
                    $quote = null;
                }
            }
        } elseif (substr($sql, $index, 2) === '--' || $char === '#') {
            $end = strpos($sql, "\n", $index);
            $index = $end === false ? $length : $end;
            $statement .= "\n";
        } elseif (substr($sql, $index, 2) === '/*') {
            $end = strpos($sql, '*/', $index + 2);
            if ($end === false) throw new RuntimeException('Unterminated migration comment');
            $index = $end + 1;
            $statement .= ' ';
        } elseif (in_array($char, ["'", '"', '`'], true)) {
            $quote = $char;
            $statement .= $char;
        } elseif ($char === ';') {
            if (trim($statement) !== '') $statements[] = trim($statement);
            $statement = '';
        } else {
            $statement .= $char;
        }
    }
    if ($quote !== null) throw new RuntimeException('Unterminated migration string');
    if (trim($statement) !== '') $statements[] = trim($statement);
    return $statements;
}

/** DDL and prepared DDL remain excluded. Every top-level DML target is inventoried. */
function historicalSeedDml(array $filenames): array {
    $dml = [];
    foreach ($filenames as $filename) {
        foreach (historicalSeedStatements(file_get_contents(dirname(__DIR__, 2) . '/migrations/' . $filename)) as $sql) {
            if (preg_match('/^(?:INSERT(?:\s+IGNORE)?\s+INTO|UPDATE|DELETE\s+FROM)\s+`?(\w+)/i', $sql, $match)) {
                $dml[] = ['file' => $filename, 'table' => $match[1], 'sql' => $sql];
            }
        }
    }
    return $dml;
}

function historicalSeedRows(mysqli $db, string $table): array {
    $rows = $db->query('SELECT * FROM `' . $table . '`')->fetch_all(MYSQLI_ASSOC);
    foreach ($rows as &$row) {
        if (in_array($table, ['bot_commands', 'menu_items'], true)) unset($row['id']);
        if ($table === 'users') unset($row['last_seen']); // One-time upgrade stamp, not a fresh default.
        if ($table === 'site_options') {
            unset($row['updated_at']);
            if ($row['key_name'] === 'playlist_rollout_watermark') $row['value'] = '<timestamp>';
        }
        ksort($row);
    }
    unset($row);
    $rows = array_map(static fn($row) => json_encode($row, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), $rows);
    sort($rows);
    return $rows;
}
