<?php
require dirname(__DIR__,2).'/autoload.php';
if(!is_file('/var/www/html/.mlp461-owned'))throw new RuntimeException('Disposable daemon lab only');
$db=Infra\Database::getInstance()->getConnection();
file_put_contents('/var/www/html/probe-ready','ready');
while(true){try{$db->query('SELECT 1');}catch(Throwable $e){echo get_class($e).' code='.(int)$e->getCode()."\n";exit(1);}sleep(1);}
