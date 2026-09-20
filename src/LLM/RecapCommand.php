<?php

namespace LLM;

use Domain\BotCommandManager;
use Domain\ChatManager;

/**
 * /штош — личный итог вечера (MLP-325; прод-беклог №14–16, спор «дедуп vs персонализация»).
 *
 * Раньше — текстовая команда с общим промптом: два /штош подряд давали два одинаковых
 * «Штош. Стрим был что надо…». Теперь команда персональная и без дедупа: Лира получает
 * реплики вызвавшего за последние HOURS часов и рассказывает ему, как прошёл его вечер
 * в чате. Факт «Штош — традиция в конце стрима» живёт в памяти (мем), а не в промпте.
 * handler_type = recap; системный промпт команды (дашборд) задаёт только тон.
 */
class RecapCommand {
    public const HOURS        = 8;
    public const MAX_MESSAGES = 80;   // самые свежие; старше — отбрасываются
    public const MAX_CHARS    = 4000; // бюджет дайджеста в промпте
    public const LINE_CHARS   = 220;  // одна реплика в дайджесте

    private $llm;

    public function __construct(LLMManager $llm) {
        $this->llm = $llm;
    }

    /** Pure (MLP-345): «@ник …» в аргументе команды → ник цели, иначе null. */
    public static function parseTarget(string $payload): ?string {
        return preg_match('/^@([\p{L}\p{N}_.\-]{2,40})/u', trim($payload), $m) ? $m[1] : null;
    }

    public function handle(array $command, array $contextData): bool {
        $username = (string)($contextData['username'] ?? 'Гость');
        $userId   = (int)($contextData['user_id'] ?? 0);
        // MLP-345 (беклог №19): модератор подводит итог вечера за другого — «/штош @ник».
        $target = self::parseTarget(BotCommandManager::stripPrefix($command, (string)($contextData['message'] ?? ''), 'штош'));
        if ($target !== null) {
            if (empty($contextData['recap_for_others'])) {
                $this->llm->botSay("@{$username}, итог вечера за другого подводят только модераторы — а свой можно в любой момент: просто «/штош».");
                return true;
            }
            $user = (new \Domain\UserManager())->findByLoginOrNickname($target);
            if (!$user) {
                $this->llm->botSay("@{$username}, не нашла такого пони — «{$target}». Попробуй логин.");
                return true;
            }
            $targetId   = (int)$user['id'];
            $targetNick = ($user['nickname'] !== null && $user['nickname'] !== '') ? $user['nickname'] : $user['login'];
            $rows   = (new ChatManager())->getUserMessagesSince($targetId, self::HOURS);
            $digest = self::digest($rows, self::MAX_MESSAGES, self::MAX_CHARS);
            $this->llm->botSayLive(
                self::instruction($targetNick, $digest, (string)($command['system_prompt'] ?? ''), $username),
                "@{$targetNick}, штош! @{$username} попросил подвести итог твоего вечера — а вечер был хорош, раз ты здесь."
            );
            return true;
        }
        if ($userId <= 0) {
            // У гостя нет истории реплик по id — итог не собрать, но «штош» принимаем.
            $this->llm->botSayLive(
                "Гость @{$username} командой /штош просит подвести итог его вечера, но истории реплик гостей у тебя нет. "
                . "Ответь @{$username} одной-двумя фразами в своём стиле: личный итог вечера — только для зарегистрированных, а само «штош» засчитано.",
                "@{$username}, штош! Итог вечера веду только для зарегистрированных пони — но твоё «штош» засчитано."
            );
            return true;
        }
        $rows        = (new ChatManager())->getUserMessagesSince($userId, self::HOURS);
        $digest      = self::digest($rows, self::MAX_MESSAGES, self::MAX_CHARS);
        $instruction = self::instruction($username, $digest, (string)($command['system_prompt'] ?? ''));
        $this->llm->botSayLive(
            $instruction,
            "@{$username}, штош! Вечер был хорош, раз ты здесь — спасибо, что провёл его с нами."
        );
        return true;
    }

    /**
     * Pure: дайджест реплик пользователя для промпта — по строке «[ЧЧ:ММ] текст» (МСК),
     * без команд боту, картинки/опросы — плейсхолдерами, свежие в пределах бюджетов
     * (старые отбрасываются первыми). Пустая строка — реплик нет.
     * @param array $rows строки chat_messages: message (как хранится, htmlspecialchars), created_at (UTC)
     */
    public static function digest(array $rows, int $maxMessages = self::MAX_MESSAGES, int $maxChars = self::MAX_CHARS): string {
        $lines = [];
        foreach ($rows as $row) {
            $text = trim(html_entity_decode((string)($row['message'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            if ($text === '' || $text[0] === '/') continue; // команды боту — не реплики
            $text = preg_replace('/!\[[^\]]*\]\([^)]*\)/u', '(картинка)', $text);
            $text = preg_replace('/\[\[poll:\d+\]\]/u', '(опрос)', $text);
            $text = trim(preg_replace('/\s+/u', ' ', $text));
            if ($text === '') continue;
            if (mb_strlen($text) > self::LINE_CHARS) {
                $text = mb_substr($text, 0, self::LINE_CHARS - 1) . '…';
            }
            $ts = strtotime(((string)($row['created_at'] ?? '')) . ' UTC') ?: time();
            $lines[] = '[' . MskClock::format($ts, 'H:i') . '] ' . $text;
        }
        if (count($lines) > $maxMessages) {
            $lines = array_slice($lines, -$maxMessages);
        }
        // Бюджет символов: набираем от свежих к старым, лишнее старое отпадает.
        $kept = [];
        $total = 0;
        for ($i = count($lines) - 1; $i >= 0; $i--) {
            $len = mb_strlen($lines[$i]) + 1;
            if ($total + $len > $maxChars) break;
            $kept[] = $lines[$i];
            $total += $len;
        }
        return implode("\n", array_reverse($kept));
    }

    /** Pure: инструкция для botSayLive — данные + задача; промпт команды (дашборд) — тон. */
    public static function instruction(string $username, string $digest, string $commandPrompt, ?string $askedBy = null): string {
        $data = $digest !== '' ? $digest : '(реплик за это время нет — молчал(а), но был(а) в чате)';
        $s = $askedBy === null
            ? "Пользователь @{$username} командой /штош просит подвести итог своего вечера. "
            : "Модератор @{$askedBy} командой «/штош @{$username}» просит подвести итог вечера за @{$username} (сам @{$username} команду не вызывал — можно мягко это обыграть, но итог — для него). ";
        $s .= "Реплики @{$username} за последние " . self::HOURS . " часов (время МСК):\n{$data}\n\n";
        if (trim($commandPrompt) !== '') {
            $s .= trim($commandPrompt) . "\n";
        }
        $s .= "ТВОЯ ЗАДАЧА: расскажи @{$username}, как прошёл его вечер в чате — по его собственным репликам: о чём говорил, "
            . "чем запомнился, что было смешного или тёплого. Обращайся лично, 2–4 предложения, в своём стиле; не пересказывай "
            . "списком, не цитируй реплики подряд и не упоминай, что тебе передали данные.";
        return $s;
    }
}
