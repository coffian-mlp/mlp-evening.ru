<?php

namespace Infra;

use mysqli;
use mysqli_sql_exception;
use RuntimeException;
use Throwable;

/** Nested work joins one connection; only the outermost call commits. */
final class Transaction
{
    private static array $depth = [];

    public static function run(mysqli $db, callable $work, int $attempt = 0): mixed
    {
        $key = spl_object_id($db);
        $depth = self::$depth[$key] ?? 0;
        $savepoint = 'mlp_tx_' . $depth;
        $ownsTransaction = $depth === 0 && !self::hasTransaction($db, $key);
        if ($ownsTransaction) {
            $db->begin_transaction();
        } else {
            $db->query('SAVEPOINT ' . $savepoint);
        }
        self::$depth[$key] = $depth + 1;
        try {
            $result = $work();
            if ($ownsTransaction) {
                $db->commit();
            } else {
                $db->query('RELEASE SAVEPOINT ' . $savepoint);
            }
            return $result;
        } catch (Throwable $error) {
            if ($ownsTransaction) {
                $db->rollback();
            } elseif (!($error instanceof mysqli_sql_exception && $error->getCode() === 1213)) {
                $db->query('ROLLBACK TO SAVEPOINT ' . $savepoint);
                $db->query('RELEASE SAVEPOINT ' . $savepoint);
            }
            if ($ownsTransaction && $error instanceof mysqli_sql_exception && in_array($error->getCode(), [1205, 1213], true) && $attempt < 2) {
                unset(self::$depth[$key]);
                usleep(10000 * ($attempt + 1));
                return self::run($db, $work, $attempt + 1);
            }
            throw $error;
        } finally {
            if ($depth === 0) {
                unset(self::$depth[$key]);
            } else {
                self::$depth[$key] = $depth;
            }
        }
    }

    public static function depth(mysqli $db): int
    {
        return self::$depth[spl_object_id($db)] ?? 0;
    }

    /** MySQL lacks MariaDB's @@in_transaction; probe without committing the caller's work. */
    private static function hasTransaction(mysqli $db, int $key): bool
    {
        $probe = 'mlp_probe_' . $key;
        if (!$db->query('SAVEPOINT ' . $probe)) throw new RuntimeException('Cannot probe transaction');
        try {
            $released = $db->query('RELEASE SAVEPOINT ' . $probe);
            if ($released) return true;
            if ($db->errno === 1305) return false;
            throw new RuntimeException('Cannot probe transaction');
        } catch (mysqli_sql_exception $error) {
            if ($error->getCode() === 1305) return false;
            throw $error;
        }
    }
}
