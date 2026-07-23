<?php
/**
 * Бэкфилл превью стикеров (MLP-258) — разовый CLI-скрипт.
 *
 * Обходит chat_stickers WHERE thumb_url IS NULL, генерит превью через
 * Infra\Thumbnailer, пишет thumb_url. Идемпотентен (повторный запуск — только
 * необработанные). Стикеры, которым превью не положено (анимированный GIF,
 * маленький файл, битый файл), остаются с NULL — фронт показывает оригинал.
 *
 * Запуск: php scripts/backfill_sticker_thumbs.php
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only\n");
}

require_once __DIR__ . '/../autoload.php';

use Domain\StickerManager;
use Infra\Database;
use Infra\Thumbnailer;

$db = Database::getInstance()->getConnection();
$sm = new StickerManager(); // MLP-266 (AR6-6): запись thumb_url — через владельца

$res = $db->query("SELECT id, image_url FROM chat_stickers WHERE thumb_url IS NULL");
$total = $res->num_rows;
$done = 0;
$skipped = 0;

echo "Стикеров без превью: $total\n";

while ($row = $res->fetch_assoc()) {
    $thumb = Thumbnailer::createFor($row['image_url']);
    if ($thumb !== null) {
        // MLP-266 (AR6-6): запись — только через владельца таблицы.
        $sm->setThumb((int)$row['id'], $thumb);
        $done++;
    } else {
        $skipped++; // аним. GIF / маленький / битый / нет GD — оригинал и так ок
    }
}

echo "Готово: превью создано — $done, пропущено (оригинал) — $skipped.\n";
