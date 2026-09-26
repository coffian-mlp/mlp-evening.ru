<?php

namespace LLM;

use Domain\BotCommandManager;

/**
 * Дедуп одинаковых команд бота (MLP-326, прод-беклог №14/№16). Pure.
 *
 * Та же команда с теми же аргументами уже в очереди или отвечена в пределах окна
 * (ai_command_dedup_window, сек; 0 = выкл) → вместо повторной генерации (рисунок,
 * расписание, текст) — короткая живая реплика «уже делаю / смотри выше».
 * Персональные команды (todo, память, напоминалки, /штош-итог) не дедупятся:
 * их ответы разные по сути. Без склейки: первый вызов отвечается как обычно.
 */
final class CommandDedup {
    /** handler_type, для которых повтор — дубль. */
    public const TYPES = ['text', 'schedule', 'poll', 'image', 'image_chat', 'ban', 'mute']; // MLP-350: повтор жалобы не зовёт модераторов дважды
    /** Аргументы и алиасы не различают вызовы: «/расписание пожалуйста» = «/schedule». */
    public const IGNORE_ARGS = ['schedule', 'image_chat'];
    /** Задержка реплики-уведомления, сек (короче lifelike: человек ждёт ответа на команду). */
    public const NOTICE_DELAY = 3;

    /** Ключ дубля или null, если команда не дедупится. */
    public static function key(array $command, string $message): ?string {
        $type = (string)($command['handler_type'] ?? '');
        if (!in_array($type, self::TYPES, true)) {
            return null;
        }
        if (in_array($type, self::IGNORE_ARGS, true)) {
            return "cmd:{$type}|";
        }
        $id = (string)($command['id'] ?? ($command['command_prefix'] ?? ''));
        if ($id === '') {
            return null;
        }
        $args = BotCommandManager::stripPrefix($command, $message, '');
        $args = mb_strtolower(trim((string)preg_replace('/\s+/u', ' ', $args)));
        return "cmd:{$id}|{$args}";
    }

    /**
     * Первая из недавних задач с тем же ключом (pending/processing/done), не являющаяся
     * сама уведомлением о дубле и не авто-задачей воркера (MLP-344). $jobs — JobQueue::recentDynamicCommands(): status, age, data.
     * @return array{username:string,status:string,age:int}|null
     */
    public static function findOriginal(array $jobs, string $key): ?array {
        foreach ($jobs as $job) {
            $data = $job['data'] ?? [];
            if (!empty($data['dedup_of'])) continue;
            if (!empty($data['auto'])) continue; // MLP-344: авторисунок — не команда человека; его молчаливый сбой не должен глушить /нарисуйчат
            if (!in_array((string)($job['status'] ?? ''), ['pending', 'processing', 'done'], true)) continue;
            if (self::key($data['command'] ?? [], (string)($data['message'] ?? '')) !== $key) continue;
            return [
                'username' => (string)($data['username'] ?? ''),
                'status'   => (string)$job['status'],
                'age'      => max(0, (int)($job['age'] ?? 0)),
            ];
        }
        return null;
    }

    /** Инструкция для botSayLive + запасная фраза: [instruction, fallback]. */
    public static function notice(array $command, array $original, string $username): array {
        $prefix = (string)($command['command_prefix'] ?? 'эту команду');
        $type   = (string)($command['handler_type'] ?? 'text');
        $orig   = (string)($original['username'] ?? '');
        $who    = $orig === '' ? 'я сама (анонс по расписанию)' : ($orig === $username ? "@{$username} сам(а)" : "@{$orig}");
        $age    = max(0, (int)($original['age'] ?? 0));
        $done   = (($original['status'] ?? '') === 'done');
        if (in_array($type, ['image', 'image_chat'], true)) {
            $what = $done ? 'рисунок уже готов и висит выше' : 'рисунок уже рисуется';
        } elseif ($type === 'schedule') {
            $what = $done ? 'расписание уже рассказано выше' : 'расписание уже готовится';
        } else {
            $what = $done ? 'ответ уже дан выше' : 'ответ уже готовится';
        }
        $instruction = "Пользователь @{$username} повторил команду {$prefix}: {$age} секунд назад её уже вызывали ({$who}), и {$what}. "
            . "Повторно ничего не делай. Ответь @{$username} ОДНОЙ короткой фразой в своём стиле: отправь глянуть выше или чуть подождать. Без вопросов.";
        $fallback = $done
            ? "@{$username}, {$prefix} только что было — смотри чуть выше 👆"
            : "@{$username}, {$prefix} уже в работе — секундочку, сейчас будет!";
        return [$instruction, $fallback];
    }
}
