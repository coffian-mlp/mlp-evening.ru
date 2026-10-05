<?php
require_once dirname(__DIR__) . '/integration_helpers.php';
if ((it_config()['db']['host'] ?? '') !== 'db' || PHP_SAPI !== 'cli') exit(1);
it_require_db();
$table = $argv[1] ?? '';
if (!preg_match('/^it_ci_[a-f0-9]{12}$/D', $table)) exit(1);
$db = Infra\Database::getInstance()->getConnection();
$handler = ['visibility' => 'public', 'permission' => static fn() => true,
    'execute' => function ($actor, $payload, $key) use ($db, $table) {
        usleep(200000);
        $db->query("UPDATE $table SET effects=effects+1 WHERE id=1");
        return ['status' => 'accepted', 'code' => 'fixture', 'facts' => ['choice' => $payload['choice']]];
    }];
$manager = new Domain\CommandInteractionManager(['fixture' => $handler]);
try {
    echo json_encode($manager->consume((int)$argv[2], $argv[3], (int)$argv[4]), JSON_THROW_ON_ERROR);
} catch (Throwable $error) { fwrite(STDERR, get_class($error) . ': ' . $error->getMessage()); exit(1); }
