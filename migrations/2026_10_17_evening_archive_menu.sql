INSERT INTO menu_items(title,url,sort_order,visibility,show_in_header,show_in_burger)
SELECT '📚 Архив','/archive.php',35,'all',1,1
WHERE NOT EXISTS (SELECT 1 FROM menu_items WHERE url='/archive.php');
