-- MLP-367: preserve any existing same-URL custom navigation item.
INSERT INTO menu_items (title,url,sort_order,visibility,show_in_header,show_in_burger)
SELECT 'Эпизоды','/episodes.php',25,'all',1,1
WHERE NOT EXISTS (SELECT 1 FROM menu_items WHERE url='/episodes.php');
