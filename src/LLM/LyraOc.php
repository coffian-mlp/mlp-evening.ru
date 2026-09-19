<?php

namespace LLM;

use Domain\BotCommandManager;
use Domain\BotMemoryManager;
use Infra\ConfigManager;

/**
 * ОС (пони-облик) участников чата (MLP-335).
 *
 * Художнице нужна устойчивая внешность каждого, а у большинства цвет ника дефолтный и приметы
 * поведенческие. Лира придумывает облик сама — служебным LLM-вызовом (без личности, строгий
 * формат), лениво: когда режиссёру сцены нужна внешность участника онлайн, а её нет. Хранится
 * фактом досье «внешность: …» с source=oc (автосжатие памяти его не трогает), человек может
 * переписать свой облик командой /яос (source=manual). Объявление — живой репликой с правом вето.
 * Правила генерации: без пола, если он не ясен из досье; ничего про реальную внешность/возраст/вес;
 * облик должен льстить; кьютимарка — из интересов; шёрстка — из цвета ника, если он свой.
 */
class LyraOc {
    public const PREFIX = 'внешность:';
    public const MAX_LEN = 120;
    public const MIN_LEN = 8;
    /** Сколько новых обликов максимум за один рисунок (иначе /нарисуйчат думает полминуты). */
    public const PER_DRAWING = 2;

    public const PROMPT = "Ты — служебный генератор пони-обликов (ОС) для участников чата. Не персонаж, без комментариев.\n"
        . "По нику, цвету ника и фактам о человеке придумай ОДИН облик пони в мире My Little Pony и ответь РОВНО ОДНОЙ строкой:\n"
        . "внешность: <вид пони>, <цвет шёрстки>, <грива>, <одна яркая деталь или аксессуар>, кьютимарка — <символ по интересам>\n"
        . "Правила: по-русски, до 120 символов, без markdown и без имени. Вид — земнопони/пегас/единорог по характеру. "
        . "Пол НЕ указывай (пиши «пони», не «кобылка»/«жеребец»), если из фактов он не следует явно. "
        . "Если дан цвет ника — шёрстка этого цвета. Ничего о реальной внешности, возрасте, весе, здоровье; никаких насмешек — облик должен нравиться человеку. "
        . "Если фактов мало — придумай нейтральный симпатичный облик. Никакого другого текста.";

    private $llm;
    private $memory;

    public function __construct(LLMManager $llm) {
        $this->llm = $llm;
        $this->memory = new BotMemoryManager();
    }

    /** Pure: есть ли у досье запись внешности. */
    public static function hasAppearance(array $dossierRows): bool {
        foreach ($dossierRows as $row) {
            if (self::isAppearance((string)($row['text'] ?? ''))) return true;
        }
        return false;
    }

    public static function isAppearance(string $text): bool {
        return (bool)preg_match('/^внешность\s*[:\-—]/iu', trim($text));
    }

    /** Pure: текст задания генератору. */
    public static function taskText(string $nick, string $colorName, array $facts): string {
        $s = "Ник: {$nick}\n";
        $s .= 'Цвет ника: ' . ($colorName !== '' ? $colorName : 'не задан') . "\n";
        $clean = [];
        foreach (array_slice($facts, 0, 4) as $f) {
            $f = LyraArtist::shortFact((string)$f, 100);
            if ($f !== '' && !self::isAppearance($f)) $clean[] = $f;
        }
        $s .= 'Факты: ' . ($clean ? implode('; ', $clean) : 'нет') . "\n\nПридумай облик.";
        return $s;
    }

    /**
     * Pure: разбор ответа генератора → «внешность: …» (нормализованная строка) или null.
     * Берётся первая строка с префиксом; markdown и кавычки снимаются; длина клампится.
     */
    public static function parse(?string $raw): ?string {
        if ($raw === null) return null;
        foreach (preg_split('/\R/u', $raw) as $line) {
            $line = trim(preg_replace('/[*`_>#]+/u', '', $line), " \t\"«»'");
            if (!preg_match('/^внешность\s*[:\-—]\s*(.+)$/iu', $line, $m)) continue;
            $v = trim(preg_replace('/\s+/u', ' ', $m[1]), " .;\"«»'");
            if (mb_strlen($v) < self::MIN_LEN) return null;
            if (mb_strlen($v) > self::MAX_LEN) {
                $cut = mb_substr($v, 0, self::MAX_LEN);
                $pos = mb_strrpos($cut, ',') ?: mb_strrpos($cut, ' ') ?: self::MAX_LEN;
                $v = rtrim(mb_substr($cut, 0, $pos), ' ,;');
            }
            return self::PREFIX . ' ' . $v;
        }
        return null;
    }

    /**
     * Придумать облики онлайн-участникам без внешности (не больше PER_DRAWING за вызов),
     * записать в память и объявить в чате. $dossiers дополняется на месте — художница
     * использует новые облики сразу. Возвращает [user_id => текст].
     */
    public function ensureFor(array $onlineUsers, array &$dossiers): array {
        $c = ConfigManager::getInstance();
        if (!(int)$c->getOption('ai_memory_enabled', 1) || !(int)$c->getOption('ai_oc_auto', 1)) {
            return [];
        }
        $botId = $this->llm->getBotUserId();
        $created = [];
        foreach ($onlineUsers as $u) {
            if (count($created) >= self::PER_DRAWING) break;
            $id = (int)($u['id'] ?? 0);
            if ($id <= 0 || $id === $botId) continue;
            $rows = $dossiers[$id] ?? [];
            if (self::hasAppearance($rows)) continue;
            $nick = BotMemoryManager::normalizeText((string)($u['nickname'] ?? ''));
            if ($nick === '') continue;
            $task = [['role' => 'user', 'content' => self::taskText($nick, LyraArtist::colorName((string)($u['chat_color'] ?? '')), array_column($rows, 'text'))]];
            $text = self::parse($this->llm->generateUtility($task, self::PROMPT, 25));
            if ($text === null) {
                error_log("LyraOc: генератор не дал облик для #$id ($nick)");
                continue;
            }
            $saved = $this->memory->setAppearance($id, $text, 'oc');
            if (!$saved) continue;
            $dossiers[$id] = array_merge($rows, [['text' => $text, 'source' => 'oc']]);
            $created[$id] = $text;
            $this->announce($nick, $text);
        }
        return $created;
    }

    /** Живое объявление облика с правом вето (решение владельца: не фикс-фразой). */
    private function announce(string $nick, string $text): void {
        $look = trim(mb_substr($text, mb_strlen(self::PREFIX)));
        $this->llm->botSayLive(
            "Ты только что придумала, как выглядит @{$nick} в виде пони, и записала себе в память: «{$look}». "
            . "Скажи это @{$nick} одной-двумя фразами в своём стиле — тепло и с интересом, обязательно перескажи облик и добавь, "
            . "что если не нравится — можно переписать командой «/яос своё описание». Не задавай других вопросов.",
            "@{$nick}, я тут представила тебя пони: {$look}. Если хочешь другой облик — напиши «/яос своё описание».",
            [], $nick
        );
    }

    /** /яос <описание> — человек задаёт или переписывает СВОЙ облик (без прав; гости — нет). */
    public function handleSetOwn(array $command, array $contextData): bool {
        $username = (string)($contextData['username'] ?? 'Гость');
        $userId = (int)($contextData['user_id'] ?? 0);
        if ($userId <= 0) {
            $this->llm->botSay("@{$username}, облик запоминаю только зарегистрированным — сначала войди на сайт.");
            return true;
        }
        $payload = BotCommandManager::stripPrefix($command, (string)($contextData['message'] ?? ''), 'яос');
        $payload = trim(preg_replace('/^внешность\s*[:\-—]\s*/iu', '', $payload));
        if ($payload === '') {
            $current = null;
            foreach ($this->memory->getByUser($userId) as $row) {
                if (self::isAppearance((string)$row['text'])) { $current = trim(mb_substr((string)$row['text'], mb_strlen(self::PREFIX))); break; }
            }
            $this->llm->botSay($current !== null
                ? "@{$username}, сейчас я представляю тебя так: {$current}. Хочешь иначе — «/яос новое описание»."
                : "@{$username}, облика у тебя пока нет. Напиши «/яос серая кобылка в очках с гривой цвета чая» — запомню.");
            return true;
        }
        $len = mb_strlen($payload);
        if ($len < self::MIN_LEN || $len > 160) {
            $this->llm->botSay("@{$username}, описание нужно от " . self::MIN_LEN . " до 160 символов — как пони выглядит: вид, цвет шёрстки, грива, деталь, кьютимарка.");
            return true;
        }
        $text = self::PREFIX . ' ' . BotMemoryManager::normalizeText($payload);
        if (!$this->memory->setAppearance($userId, $text, 'manual', $userId)) {
            $this->llm->botSay("@{$username}, копыто дрогнуло — не записалось. Попробуй ещё раз!");
            return true;
        }
        $this->llm->botSayLive(
            "Пользователь @{$username} командой /яос задал свой пони-облик: «{$payload}». Он уже записан вместо прежнего. "
            . "Подтверди @{$username} одной-двумя фразами в своём стиле, можно отреагировать на облик. Не задавай вопросов.",
            "@{$username}, записала: теперь ты для меня — {$payload}. На следующем рисунке проверим!",
            [], "@{$username}"
        );
        return true;
    }
}
