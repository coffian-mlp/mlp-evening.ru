<?php
require __DIR__.'/integration_helpers.php';
if((it_config()['db']['host']??'')!=='db')it_skip('requires isolated db');
it_require_db()->close();$db=Infra\Database::getInstance()->getConnection();
$sql=file_get_contents(__DIR__.'/../migrations/2026_10_07_episode_catalogue_menu.sql');
$db->begin_transaction();
try{
 $db->query("DELETE FROM menu_items WHERE url='/episodes.php'");
 $db->query($sql);$row=$db->query("SELECT * FROM menu_items WHERE url='/episodes.php'")->fetch_assoc();
 check($row['visibility']==='all'&&(int)$row['show_in_header']===1&&(int)$row['show_in_burger']===1,'public navigation all placements');
 $db->query($sql);check((int)$db->query("SELECT COUNT(*) n FROM menu_items WHERE url='/episodes.php'")->fetch_assoc()['n']===1,'rerun no duplicate');
 $db->query("UPDATE menu_items SET title='Custom',sort_order=999,is_active=0,visibility='admins',show_in_header=0,show_in_burger=0 WHERE url='/episodes.php'");
 $before=$db->query("SELECT * FROM menu_items WHERE url='/episodes.php'")->fetch_assoc();$db->query($sql);
 check($db->query("SELECT * FROM menu_items WHERE url='/episodes.php'")->fetch_assoc()===$before,'same URL customization preserved exactly');
}finally{$db->rollback();}
it_done();
