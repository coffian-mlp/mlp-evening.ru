<?php

namespace LLM;

use Infra\ConfigManager;

/** Shared admission for worker and synchronous drawing paths. */
final class DrawingSchedule {
    public const MANUAL_COOLDOWN = 1800;

    public static function run(bool $auto, callable $draw): bool {
        $db = \Infra\Database::getInstance()->getConnection();
        if ((int)$db->query("SELECT GET_LOCK('bot_drawing', 0) AS acquired")->fetch_assoc()['acquired'] !== 1) {
            throw new DrawingBusy('Another drawing is running');
        }
        try {
            if ($auto && self::automaticBlocked()) return true;
            return $draw();
        } finally {
            $db->query("SELECT RELEASE_LOCK('bot_drawing')");
        }
    }

    public static function automaticBlocked(): bool {
        $lastManual = (int)(ConfigManager::getInstance()->getOptionDetails('bot_last_manual_draw')['value'] ?? 0);
        return time() - $lastManual < self::MANUAL_COOLDOWN || (new JobQueue())->hasManualDrawing();
    }

    public static function manualPublished(): void {
        $config = ConfigManager::getInstance();
        $now = (string)time();
        $config->setOption('bot_last_manual_draw', $now);
        $config->setOption('bot_last_autodraw', $now);
    }
}
