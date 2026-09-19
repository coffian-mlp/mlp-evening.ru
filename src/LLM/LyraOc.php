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
    public const MAX_LEN = 170; // генератор выдаёт ~150 симв.; при 120 терялась кьютимарка (поле 19.09)
    public const MIN_LEN = 8;
    /** Сколько новых обликов максимум за один рисунок (иначе /нарисуйчат думает полминуты). */
    public const PER_DRAWING = 5; // решение владельца 19.09: было 2

    public const PROMPT = "Ты — служебный генератор пони-обликов (ОС) для участников чата. Не персонаж, без комментариев.\n"
        . "По нику, цвету ника и фактам о человеке придумай ОДИН облик пони в мире My Little Pony и ответь РОВНО ОДНОЙ строкой:\n"
        . "внешность: <вид пони>, <цвет шёрстки>, <грива>, <одна яркая деталь или аксессуар>, кьютимарка — <символ по интересам>\n"
        . "Правила: по-русски, до 150 символов, без markdown и без имени. Вид — земнопони/пегас/единорог по характеру. "
        . "Пол НЕ указывай (пиши «пони», не «кобылка»/«жеребец»), если из фактов он не следует явно. "
        . "Если дан цвет ника — используй его для гривы или яркого акцента (шарф, очки, кьютимарка), НЕ для шёрстки: цвет шёрстки выбери сам по характеру. Ничего о реальной внешности, возрасте, весе, здоровье; никаких насмешек — облик должен нравиться человеку. "
        . "Если фактов мало — придумай нейтральный симпатичный облик. Никакого другого текста.";

    /** Промпт vision-помощника для /яос с картинкой (MLP-337). */
    public const VISION_PROMPT = "Ты — служебный описатель персонажей для художника. На картинке — персонаж (обычно пони в стиле My Little Pony). "
        . "Опиши ЕГО ВНЕШНОСТЬ одной строкой по-русски, до 150 символов, строго в формате:\n"
        . "внешность: <вид пони или существа>, <цвет шёрстки/кожи>, <грива/волосы: цвет и форма>, <глаза>, <одна яркая деталь или аксессуар>, кьютимарка — <что на бедре, если видно>\n"
        . "Только то, что реально видно; без имён, без оценок, без markdown, без другого текста. Если на картинке не персонаж, а что-то иное — ответь одним словом: НЕТ.";

    /** Промпт слияния «картинка + пожелание владельца» (MLP-337). */
    public const MERGE_PROMPT = "Ты — служебный редактор описаний пони-обликов. Даны описание персонажа с картинки (только видимое) и пожелание владельца текстом. "
        . "Составь ОДНУ строку по-русски, до 150 символов, строго в формате «внешность: <вид пони>, <шёрстка>, <грива>, <глаза>, <одежда/аксессуары>, кьютимарка — <…>». "
        . "Приоритет у пожелания владельца: вид пони, пол, одежда и аксессуары, кьютимарка (если владелец говорит «скрыта» — так и пиши), характерные детали. Цвета и формы бери из исходного описания, если владелец не сказал иначе; если исходное описание — уже готовый облик, СОХРАНИ все его детали и добавь новые. "
        . "Убери всё, что не про внешность (характер, навыки, привычки). Без markdown, без имени, без другого текста.";

    private $llm;
    private $memory;

    public function __construct(LLMManager $llm) {
        $this->llm = $llm;
        $this->memory = new BotMemoryManager();
    }

    /** Pure (MLP-337): URL картинки из текста сообщения (markdown-вложение чата или прямая ссылка на изображение). */
    public static function extractImageUrl(string $message): ?string {
        if (preg_match('/!\[[^\]]*\]\(([^)\s]+)\)/u', $message, $m)) return $m[1];
        if (preg_match('~(https?://\S+\.(?:png|jpe?g|gif|webp)(?:\?\S*)?)~iu', $message, $m)) return $m[1];
        if (preg_match('~(/upload/\S+\.(?:png|jpe?g|gif|webp))~iu', $message, $m)) return $m[1];
        return null;
    }

    /** Pure (MLP-337): текст команды без картинок и ссылок — остаток можно считать пожеланием. */
    public static function stripImages(string $text): string {
        $t = preg_replace('/!\[[^\]]*\]\([^)]*\)/u', ' ', $text);
        $t = preg_replace('~https?://\S+|/upload/\S+~iu', ' ', $t);
        return trim(preg_replace('/\s+/u', ' ', $t));
    }

    /** Pure (MLP-339): «/яос дополни шарф» → «шарф»; иначе null. Синонимы: добавь, дополнить, добавить, +. */
    public static function parseAugment(string $payload): ?string {
        if (preg_match('/^(?:дополни(?:ть)?|добав(?:ь|ить)|\+)(?!\p{L})\s*[:\-—,]?\s*(.*)$/isu', trim($payload), $m)) {
            return trim($m[1]);
        }
        return null;
    }

    /** Pure: текущий облик из строк досье (без префикса) или null. */
    public static function currentLook(array $dossierRows): ?string {
        foreach ($dossierRows as $row) {
            $t = (string)($row['text'] ?? '');
            if (self::isAppearance($t)) return trim(preg_replace('/^внешность\s*[:\-—]\s*/iu', '', $t));
        }
        return null;
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
        $s .= 'Цвет ника (для гривы или акцента, не шёрстки): ' . ($colorName !== '' ? $colorName : 'не задан') . "\n";
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
     * использует новые облики сразу. Возвращает [user_id => ['nick','text']]; $announce=false —
     * объявления отложены (художница объявит после картинки, MLP-335: решение владельца).
     */
    public function ensureFor(array $onlineUsers, array &$dossiers, bool $announce = true): array {
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
            $created[$id] = ['nick' => $nick, 'text' => $text];
            if ($announce) $this->announce($nick, $text);
        }
        return $created;
    }

    /** Живое объявление облика с правом вето (решение владельца: не фикс-фразой). $drawn — рисунок с ним уже выше. */
    public function announce(string $nick, string $text, bool $drawn = false): void {
        $look = trim(mb_substr($text, mb_strlen(self::PREFIX)));
        $where = $drawn ? ' Рисунок с ним уже опубликован выше — можешь сослаться на него («на рисунке ты…»).' : '';
        $this->llm->botSayLive(
            "Ты только что придумала, как выглядит @{$nick} в виде пони, и записала себе в память: «{$look}».{$where} "
            . "Скажи это @{$nick} одной-двумя фразами в своём стиле — тепло и с интересом, обязательно перескажи облик и добавь, "
            . "что если не нравится — можно переписать командой «/яос своё описание». Не задавай других вопросов.",
            "@{$nick}, я тут представила тебя пони: {$look}. Если хочешь другой облик — напиши «/яос своё описание».",
            [], $nick
        );
    }

    /** Слияние описания с картинки и пожелания владельца → облик без префикса; null — сбой. */
    public function mergeLook(string $look, string $wish, string $baseLabel = 'С картинки'): ?string {
        $task = [['role' => 'user', 'content' => "{$baseLabel}: {$look}\nПожелание владельца: " . mb_substr($wish, 0, 400) . "\n\nСоставь облик."]];
        $merged = self::parse($this->llm->generateUtility($task, self::MERGE_PROMPT, 25));
        return $merged === null ? null : trim(mb_substr($merged, mb_strlen(self::PREFIX)));
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

        // MLP-339: «/яос дополни шарф и очки» — текущий облик + дополнение (картинка в дополнении тоже допустима).
        $augment = self::parseAugment(self::stripImages($payload));
        $imageUrl = self::extractImageUrl($payload);
        if ($augment !== null) {
            $current = self::currentLook($this->memory->getByUser($userId));
            if ($current === null) {
                $this->llm->botSay("@{$username}, дополнять пока нечего — облика у тебя нет. Задай его: «/яос описание» или «/яос» с картинкой.");
                return true;
            }
            $addition = $augment;
            if ($imageUrl !== null) {
                $seen = self::parse(VisionDescriber::describeWith($imageUrl, self::VISION_PROMPT));
                if ($seen !== null) $addition = trim(mb_substr($seen, mb_strlen(self::PREFIX)) . ($augment !== '' ? '; ' . $augment : ''));
            }
            if (mb_strlen($addition) < 3) {
                $this->llm->botSay("@{$username}, а что добавить? Например: «/яос дополни красный шарф и очки».");
                return true;
            }
            $merged = $this->mergeLook($current, $addition, 'Текущий облик');
            if ($merged === null) {
                $this->llm->botSay("@{$username}, не сообразила, как это вплести — попробуй сформулировать иначе или перепиши облик целиком.");
                return true;
            }
            if (!$this->memory->setAppearance($userId, self::PREFIX . ' ' . BotMemoryManager::normalizeText($merged), 'manual', $userId)) {
                $this->llm->botSay("@{$username}, копыто дрогнуло — не записалось. Попробуй ещё раз!");
                return true;
            }
            $this->llm->botSayLive(
                "Пользователь @{$username} командой /яос дополнил свой пони-облик: было «{$current}», добавил «{$augment}», теперь облик: «{$merged}». Запись уже обновлена. "
                . "Подтверди @{$username} одной-двумя фразами в своём стиле, упомяни, что именно добавилось. Не задавай вопросов.",
                "@{$username}, дополнила: теперь ты — {$merged}.",
                [], "@{$username}"
            );
            return true;
        }

        // MLP-337: /яос с картинкой — vision-помощник описывает персонажа, описание и становится обликом.
        if ($imageUrl !== null) {
            $described = self::parse(VisionDescriber::describeWith($imageUrl, self::VISION_PROMPT));
            $wish = self::stripImages($payload);
            if ($described === null) {
                $this->llm->botSay("@{$username}, не разглядела на картинке персонажа — попробуй другую или опиши словами: «/яос серая кобылка в очках».");
                return true;
            }
            $look = trim(mb_substr($described, mb_strlen(self::PREFIX)));
            if ($wish !== '') {
                // Пожелание владельца главнее картинки (вид, пол, одежда, кьютимарка) — сливаем служебным вызовом;
                // раньше длинное пожелание просто отбрасывалось (Darbel, 22:17 19.09).
                $merged = $this->mergeLook($look, $wish);
                if ($merged !== null) $look = $merged;
            }
            $text = self::PREFIX . ' ' . BotMemoryManager::normalizeText($look);
            if (!$this->memory->setAppearance($userId, $text, 'manual', $userId)) {
                $this->llm->botSay("@{$username}, копыто дрогнуло — не записалось. Попробуй ещё раз!");
                return true;
            }
            $this->llm->botSayLive(
                "Пользователь @{$username} командой /яос прислал картинку со своей ОС. Ты рассмотрела её и записала облик: «{$look}». "
                . "Подтверди @{$username} одной-двумя фразами в своём стиле: перескажи, что увидела, и что теперь будешь рисовать так; если что-то не так — пусть поправит словами через /яос. Не задавай других вопросов.",
                "@{$username}, рассмотрела: {$look}. Запомнила — так и буду рисовать; если что не так, поправь «/яос описание».",
                [], "@{$username}"
            );
            return true;
        }

        if ($payload === '') {
            $current = null;
            foreach ($this->memory->getByUser($userId) as $row) {
                if (self::isAppearance((string)$row['text'])) { $current = trim(mb_substr((string)$row['text'], mb_strlen(self::PREFIX))); break; }
            }
            $this->llm->botSay($current !== null
                ? "@{$username}, сейчас я представляю тебя так: {$current}. Хочешь иначе — «/яос новое описание», добавить деталь — «/яос дополни …»."
                : "@{$username}, облика у тебя пока нет. Напиши «/яос серая кобылка в очках с гривой цвета чая» или пришли «/яос» с картинкой своей ОС — запомню. Потом можно уточнять: «/яос дополни красный шарф».");
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
