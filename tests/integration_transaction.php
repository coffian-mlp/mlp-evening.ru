<?php
require_once __DIR__ . '/integration_helpers.php';
if ((it_config()['db']['host'] ?? '') !== 'db') it_skip('Transaction test requires isolated db');
$db = it_require_db();
$db->query('CREATE TEMPORARY TABLE mlp_tx_test (id INT PRIMARY KEY) ENGINE=InnoDB');
try {
    Infra\Transaction::run($db, function () use ($db) {
        $db->query('INSERT INTO mlp_tx_test VALUES (1)');
        Infra\Transaction::run($db, fn() => $db->query('INSERT INTO mlp_tx_test VALUES (2)'));
    });
    check((int)$db->query('SELECT COUNT(*) n FROM mlp_tx_test')->fetch_assoc()['n'] === 2, 'nested commit joins outer transaction');
    try {
        Infra\Transaction::run($db, function () use ($db) {
            Infra\Transaction::run($db, fn() => $db->query('INSERT INTO mlp_tx_test VALUES (3)'));
            throw new RuntimeException('Injected outer failure');
        });
    } catch (RuntimeException $error) {}
    check((int)$db->query('SELECT COUNT(*) n FROM mlp_tx_test')->fetch_assoc()['n'] === 2, 'outer rollback removes successful nested effect');
    Infra\Transaction::run($db, function () use ($db) {
        $db->query('INSERT INTO mlp_tx_test VALUES (4)');
        try {
            Infra\Transaction::run($db, function () use ($db) {
                $db->query('INSERT INTO mlp_tx_test VALUES (5)');
                throw new RuntimeException('Injected nested failure');
            });
        } catch (RuntimeException $error) {}
    });
    check((int)$db->query('SELECT COUNT(*) n FROM mlp_tx_test')->fetch_assoc()['n'] === 3, 'caught nested rollback preserves outer work');
    $db->begin_transaction();
    $db->query('INSERT INTO mlp_tx_test VALUES (6)');
    Infra\Transaction::run($db, fn() => $db->query('INSERT INTO mlp_tx_test VALUES (7)'));
    $db->rollback();
    check((int)$db->query('SELECT COUNT(*) n FROM mlp_tx_test')->fetch_assoc()['n'] === 3, 'externally begun transaction is neither committed nor replaced');
} finally { $db->query('DROP TEMPORARY TABLE mlp_tx_test'); }
it_done();
