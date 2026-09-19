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
    /** Ориентир длины для промптов; в коде НЕ обрезается (решение владельца 19.09). */
    public const MAX_LEN = 170;
    public const MIN_LEN = 8;
    /** Сколько новых обликов максимум за один рисунок (иначе /нарисуйчат думает полминуты). */
    public const PER_DRAWING = 5; // решение владельца 19.09: было 2

    public const PROMPT = "Ты — служебный генератор пони-обликов (ОС) для участников чата. Не персонаж, без комментариев.\n"
        . "По нику, цвету ника и фактам о человеке придумай ОДИН облик пони в мире My Little Pony и ответь РОВНО ДВУМЯ строками:\n"
        . "внешность: <вид пони>, <цвет шёрстки>, <грива>, <одна яркая деталь или аксессуар>, кьютимарка — <символ по интересам>\n"
        . "персонаж: <роль или занятие в Понивилле, характер, 1–2 привычки — по интересам из фактов, дружелюбно, до 200 символов>\n"
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
        . "Характер, навыки и привычки не записывай, но роль или принадлежность, которую можно ПОКАЗАТЬ (агент принцессы Луны → эмблема полумесяца на плаще; моряк → бескозырка), преврати в одну видимую деталь. "
        . "Без markdown, без имени, без другого текста.";

    /** Префикс записи лора/характера персонажа (MLP-340). */
    public const PERSONA_PREFIX = 'ОС:';
    public const PERSONA_MAX = 220;

    /** Промпт лора для уже существующего облика (MLP-341, тихий бэкфилл). */
    public const PERSONA_PROMPT = "Ты — служебный генератор лора пони-персонажей. Не персонаж, без комментариев. "
        . "По нику, облику пони и фактам о человеке придумай лор персонажа и ответь РОВНО ОДНОЙ строкой:\n"
        . "персонаж: <роль или занятие в Понивилле, характер, 1–2 привычки — по интересам из фактов, дружелюбно, до 200 символов>\n"
        . "Только по-русски, без латиницы и иностранных слов. Облик не пересказывай и не меняй. Ничего о реальной внешности, возрасте, здоровье человека; никаких насмешек. Никакого другого текста.";

    /** Промпт разборщика: текст владельца → внешность + персонаж (MLP-340). */
    public const SPLIT_PROMPT = "Ты — служебный разборщик описаний пони-персонажа. Дан текст владельца о его персонаже. Раздели его на ДВЕ строки по-русски, без markdown:\n"
        . "внешность: <только видимое — вид пони, пол если назван, шёрстка, грива, глаза, одежда, аксессуары, кьютимарка; роль или принадлежность, которую можно показать, — одной видимой деталью>\n"
        . "персонаж: <роль, лор, характер, привычки, стиль поведения ПЕРСОНАЖА — до 200 символов, своими словами владельца>\n"
        . "Если для какой-то строки данных нет — не пиши её вовсе. Ничего о реальном человеке. Никакого другого текста.";

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

    /** Pure (MLP-340): разбор ответа разборщика → ['look' => ?string, 'persona' => ?string] (оба без префиксов). */
    public static function parseSplit(?string $raw): array {
        $out = ['look' => null, 'persona' => null];
        if ($raw === null) return $out;
        foreach (preg_split('/\R/u', $raw) as $line) {
            $line = trim(preg_replace('/[*`_>#]+/u', '', $line), " \t\"«»'");
            if ($out['look'] === null && preg_match('/^внешность\s*[:\-—]\s*(.+)$/iu', $line, $m)) {
                $v = trim(preg_replace('/\s+/u', ' ', $m[1]), " .;\"«»'");
                if (mb_strlen($v) >= self::MIN_LEN) $out['look'] = $v;
            } elseif ($out['persona'] === null && preg_match('/^(?:персонаж|ос|характер)\s*[:\-—]\s*(.+)$/iu', $line, $m)) {
                $v = trim(preg_replace('/\s+/u', ' ', $m[1]), " .;\"«»'");
                if (mb_strlen($v) >= 3) $out['persona'] = $v;
            }
        }
        return $out;
    }

    /** Pure (MLP-341): лор годен, если это русский текст без латинских вкраплений и мусора (glm-5.3: «сидрoonном», «sweet tooth»). */
    public static function personaIsClean(?string $persona): bool {
        if ($persona === null || mb_strlen($persona) < 10) return false;
        if (preg_match('/[a-z]{2,}/iu', $persona)) return false;          // латиница внутри русского лора
        if (preg_match('/[а-яё][a-z]|[a-z][а-яё]/iu', $persona)) return false; // смешанные слова
        return true;
    }

    /** Pure: текущий лор персонажа из строк досье (без префикса) или null. */
    public static function currentPersona(array $dossierRows): ?string {
        foreach ($dossierRows as $row) {
            $t = (string)($row['text'] ?? '');
            if (preg_match('/^ос\s*:\s*(.+)$/iu', trim($t), $m)) return trim($m[1]);
        }
        return null;
    }

    /** Pure (MLP-340): объединить лор — старый + новый, без дублей, в пределах PERSONA_MAX. */
    public static function joinPersona(?string $current, string $addition): string {
        $addition = trim($addition, " .;");
        if ($current === null || $current === '') return $addition;
        if (mb_stripos($current, $addition) !== false) return $current;
        return rtrim($current, " .;") . '; ' . $addition; // без обрезки (решение владельца)
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
            return self::PREFIX . ' ' . $v; // без обрезки: длину просит промпт
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
            $raw = $this->llm->generateUtility($task, self::PROMPT, 25);
            $text = self::parse($raw);
            if ($text === null) {
                error_log("LyraOc: генератор не дал облик для #$id ($nick)");
                continue;
            }
            $saved = $this->memory->setAppearance($id, $text, 'oc');
            if (!$saved) continue;
            // MLP-341: лор персонажа — второй строкой того же ответа; тихо, source=oc
            $persona = self::parseSplit($raw)['persona'];
            if (self::personaIsClean($persona) && self::currentPersona($rows) === null) {
                $this->memory->setPersona($id, self::PERSONA_PREFIX . ' ' . BotMemoryManager::normalizeText($persona), 'oc');
            }
            $dossiers[$id] = array_merge($rows, [['text' => $text, 'source' => 'oc']]);
            $created[$id] = ['nick' => $nick, 'text' => $text];
            if ($announce) $this->announce($nick, $text);
        }
        return $created;
    }

    /**
     * Тихий бэкфилл лора (MLP-341): пользователям с обликом, но без «ОС: …» придумать персонажа по облику и
     * фактам; источник oc, без объявлений. Возвращает [user_id => лор].
     */
    public function backfillPersona(array $userIds): array {
        $out = [];
        $nicks = (new \Domain\UserManager())->getUsersByIds(array_map('intval', $userIds));
        foreach ($userIds as $id) {
            $id = (int)$id;
            $rows = $this->memory->getByUser($id);
            $look = self::currentLook($rows);
            if ($look === null || self::currentPersona($rows) !== null) continue;
            $facts = [];
            foreach ($rows as $r) { $t = (string)$r['text']; if (!self::isAppearance($t)) $facts[] = LyraArtist::shortFact($t, 120); }
            $nick = (string)($nicks[$id]['nick'] ?? ('#' . $id));
            $task = [['role' => 'user', 'content' => "Ник: {$nick}\nОблик: {$look}\nФакты: " . ($facts ? implode('; ', array_slice(array_filter($facts), 0, 5)) : 'нет') . "\n\nПридумай лор."]];
            $persona = null;
            for ($attempt = 0; $attempt < 2 && !self::personaIsClean($persona); $attempt++) { // мусор с латиницей — вторая попытка
                $persona = self::parseSplit($this->llm->generateUtility($task, self::PERSONA_PROMPT, 25))['persona'];
            }
            if (!self::personaIsClean($persona)) { error_log("LyraOc backfillPersona: лор для #$id не прошёл проверку: " . (string)$persona); continue; }
            if ($this->memory->setPersona($id, self::PERSONA_PREFIX . ' ' . BotMemoryManager::normalizeText($persona), 'oc')) $out[$id] = $persona;
        }
        return $out;
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

    /** Разбор текста владельца на внешность и персонажа служебным вызовом (MLP-340). */
    public function splitWish(string $wish): array {
        $wish = trim($wish);
        if ($wish === '') return ['look' => null, 'persona' => null];
        $task = [['role' => 'user', 'content' => "Текст владельца: " . mb_substr($wish, 0, 600) . "\n\nРаздели."]];
        return self::parseSplit($this->llm->generateUtility($task, self::SPLIT_PROMPT, 25));
    }

    /** Записать лор персонажа (объединяя с прежним); возвращает итоговый текст или null, если писать нечего. */
    private function storePersona(int $userId, ?string $addition): ?string {
        if ($addition === null || trim($addition) === '') return null;
        $joined = self::joinPersona(self::currentPersona($this->memory->getByUser($userId)), $addition);
        $this->memory->setPersona($userId, self::PERSONA_PREFIX . ' ' . BotMemoryManager::normalizeText($joined), 'manual', $userId);
        return $joined;
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
            // MLP-340: лор/характер персонажа — отдельной записью «ОС: …» в досье (Лира обыгрывает в разговоре).
            $split = $augment !== '' ? $this->splitWish($augment) : ['look' => null, 'persona' => null];
            $persona = $this->storePersona($userId, $split['persona']);
            $merged = $this->mergeLook($current, $addition, 'Текущий облик');
            if ($merged !== null && mb_strtolower(trim($merged, ' .')) === mb_strtolower(trim($current, ' .'))) {
                // Внешность не изменилась — честно сказать (прецедент Darbel 22:39); лор при этом мог записаться.
                $this->llm->botSay($persona !== null
                    ? "@{$username}, внешность не поменялась (в облике это уже есть или это не про внешность), а вот про персонажа записала: «{$persona}». Хочешь показать что-то на рисунке — скажи чем, например «/яос дополни эмблема полумесяца на плаще»."
                    : "@{$username}, посмотрела — в облике это уже есть или это не про внешность, запись не изменилась: {$current}. Если хочешь показать это на рисунке — скажи чем (например, «/яос дополни эмблема полумесяца на плаще»).");
                return true;
            }
            if ($merged === null) {
                $this->llm->botSay("@{$username}, не сообразила, как это вплести — попробуй сформулировать иначе или перепиши облик целиком.");
                return true;
            }
            if (!$this->memory->setAppearance($userId, self::PREFIX . ' ' . BotMemoryManager::normalizeText($merged), 'manual', $userId)) {
                $this->llm->botSay("@{$username}, копыто дрогнуло — не записалось. Попробуй ещё раз!");
                return true;
            }
            $this->llm->botSayLive(
                "Пользователь @{$username} командой /яос дополнил свой пони-облик. Было: «{$current}». Стало (это и есть запись, других деталей в ней нет): «{$merged}»." . ($persona !== null ? " Отдельно записан лор персонажа: «{$persona}»." : '') . " "
                . "Подтверди @{$username} одной-двумя фразами в своём стиле и назови, что именно появилось в записи — только то, что реально есть в «стало»; не приписывай записи того, чего в ней нет. Не задавай вопросов.",
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
            $personaText = null;
            if ($wish !== '') {
                // Пожелание владельца главнее картинки (вид, пол, одежда, кьютимарка) — сливаем служебным вызовом;
                // раньше длинное пожелание просто отбрасывалось (Darbel, 22:17 19.09).
                $merged = $this->mergeLook($look, $wish);
                if ($merged !== null) $look = $merged;
                $personaText = $this->storePersona($userId, $this->splitWish($wish)['persona']); // MLP-340
            }
            $text = self::PREFIX . ' ' . BotMemoryManager::normalizeText($look);
            if (!$this->memory->setAppearance($userId, $text, 'manual', $userId)) {
                $this->llm->botSay("@{$username}, копыто дрогнуло — не записалось. Попробуй ещё раз!");
                return true;
            }
            $this->llm->botSayLive(
                "Пользователь @{$username} командой /яос прислал картинку со своей ОС. Ты рассмотрела её и записала облик: «{$look}»." . ($personaText !== null ? " Отдельно записан лор персонажа: «{$personaText}»." : '') . " "
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
            $persona = self::currentPersona($this->memory->getByUser($userId));
            $this->llm->botSay($current !== null
                ? "@{$username}, сейчас я представляю тебя так: {$current}." . ($persona !== null ? " Про персонажа помню: {$persona}." : '') . " Хочешь иначе — «/яос новое описание», добавить деталь — «/яос дополни …»."
                : "@{$username}, облика у тебя пока нет. Напиши «/яос серая кобылка в очках с гривой цвета чая» или пришли «/яос» с картинкой своей ОС — запомню. Потом можно уточнять: «/яос дополни красный шарф».");
            return true;
        }
        $len = mb_strlen($payload);
        if ($len < self::MIN_LEN) {
            $this->llm->botSay("@{$username}, слишком коротко — опиши, как пони выглядит: вид, цвет шёрстки, грива, деталь, кьютимарка.");
            return true;
        }
        // MLP-340: текст владельца — это часто и внешность, и лор персонажа; раскладываем на две записи.
        $split = $this->splitWish($payload);
        $lookText = $split['look'] ?? ($split['persona'] === null ? $payload : null); // разборщик молчит → весь текст как облик
        $personaText = $this->storePersona($userId, $split['persona']);
        if ($lookText !== null && !$this->memory->setAppearance($userId, self::PREFIX . ' ' . BotMemoryManager::normalizeText($lookText), 'manual', $userId)) {
            $this->llm->botSay("@{$username}, копыто дрогнуло — не записалось. Попробуй ещё раз!");
            return true;
        }
        if ($lookText === null && $personaText === null) {
            $this->llm->botSay("@{$username}, не разобрала, что тут про внешность, а что про характер — опиши, как пони выглядит: вид, шёрстка, грива, деталь, кьютимарка.");
            return true;
        }
        $summary = ($lookText !== null ? "облик: «{$lookText}»" : 'облик без изменений') . ($personaText !== null ? "; лор персонажа: «{$personaText}»" : '');
        $this->llm->botSayLive(
            "Пользователь @{$username} командой /яос описал своего пони-персонажа. Записано ({$summary}) — это и есть записи, ничего сверх них нет. "
            . "Подтверди @{$username} одной-двумя фразами в своём стиле, назови, что записала. Не задавай вопросов.",
            "@{$username}, записала — {$summary}. На следующем рисунке проверим!",
            [], "@{$username}"
        );
        return true;
    }
}
