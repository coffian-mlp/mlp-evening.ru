<?php
require __DIR__.'/integration_helpers.php';
if ((it_config()['db']['host']??'')!=='db') it_skip('isolated db required');
$db=it_require_db();$db->close();$db=Infra\Database::getInstance()->getConnection();
require dirname(__DIR__).'/migrate.php';
$path=dirname(__DIR__).'/migrations/2026_10_16_sticker_pack_authors.sql';
migrate_apply_file($db,$path);migrate_apply_file($db,$path);
$db->begin_transaction();
try {
 $packs=new Domain\StickerManager();$code='it_author_'.bin2hex(random_bytes(8));
 $id=$packs->createPack($code,'Legacy pack');
 $row=static function()use($db,$id){return $db->query('SELECT * FROM sticker_packs WHERE id='.(int)$id)->fetch_assoc();};
 check($row()['author_name']===null && $row()['author_url']===null,'legacy creation preserves optional attribution');
 $packs->updatePack($id,$code,'Attributed',null,' Artist <one> ',' https://example.org/artist ');
 check($row()['author_name']==='Artist <one>' && $row()['author_url']==='https://example.org/artist','metadata normalized and persisted as plain data');
 $packs->updatePack($id,$code,'Legacy edit');
 check($row()['author_name']==='Artist <one>','legacy edit does not erase attribution');
 $packs->updatePack($id,$code,'Clear',null,'','');
 check($row()['author_name']==='' && $row()['author_url']==='','explicit empty values clear attribution');
 foreach(['javascript:alert(1)','data:text/html,bad','//example.org','https://user:pass@example.org','ftp://example.org','https://example.org/ a',str_repeat('x',2049)] as $url){
  try{Domain\StickerPackAuthor::normalize('Artist',$url);throw new RuntimeException('Unsafe link accepted');}catch(Core\UserError $expected){}
 }
 foreach([str_repeat('я',121),"Author\nOther"] as $name){try{Domain\StickerPackAuthor::normalize($name,'');throw new RuntimeException('Invalid author accepted');}catch(Core\UserError $expected){}}
 check(Domain\StickerPackAuthor::normalize('', 'https://example.org/?a=1&b=2')===['','https://example.org/?a=1&b=2'],'link-only attribution supported');
 echo "ALL PASS\n";
} finally {$db->rollback();}
