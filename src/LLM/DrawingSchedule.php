<?php

namespace LLM;

use Infra\ConfigManager;

/** Manual drawings take precedence; the existing worker lock serializes execution. */
final class DrawingSchedule {
    public const MANUAL_COOLDOWN = 1800;

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
