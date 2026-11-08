<?php
require_once dirname(__DIR__).'/integration_helpers.php';it_require_db();
$id=(new Domain\BotMemoryManager())->add('meme',null,(string)$argv[1],'auto');
if($id===false)exit(2);echo$id;
