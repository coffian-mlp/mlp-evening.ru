<?php

namespace LLM;

use Infra\ConfigManager;
use Infra\Database;

/**
 * Debug-журнал обращений к нейронкам: каждый запрос/ответ (чат, служебные
 * вызовы, vision, генерация картинок) пишется в таблицу llm_debug_log —
 * для отладки «что модель реально получила и что ответила». И любопытства.
 *
 * TTL 7 суток: чистка ленивая, при каждой вставке (индекс по created_at,
 * LIMIT против длинного лока). Выключатель — опция ai_debug_log (деф. вкл).
 * Журнал никогда не роняет бота: все сбои глотаются в error_log.
 */
class LlmDebugLog {

    const TTL_DAYS = 7;
    const MAX_FIELD = 200000; // потолок символов на request/response

    public static function enabled(): bool {
        try {
            return (bool)(int)ConfigManager::getInstance()->getOption('ai_debug_log', 1);
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Записать обмен. $kind: chat | utility | vision | image.
     * $request — строка или массив (уйдёт в JSON); картинки-base64 вырезаются.
     */
    public static function log(string $kind, string $provider, string $model, $request, ?string $response, string $status, int $durationMs): void {
        if (!self::enabled()) {
            return;
        }
        try {
            $found=false;$request=self::redactRecall($request,$found);
            $recall=$found||(is_array($request)&&!empty($request['memory_recall']['sources']));
            $req = self::squash($request);
            $res = $response === null ? null : ($recall ? '[Recall reply text omitted from debug log]' : self::squash($response));
            $db = Database::getInstance()->getConnection();
            $stmt = $db->prepare(
                "INSERT INTO llm_debug_log (kind, provider, model, status, duration_ms, request, response)
                 VALUES (?, ?, ?, ?, ?, ?, ?)"
            );
            $stmt->bind_param('ssssiss', $kind, $provider, $model, $status, $durationMs, $req, $res);
            $stmt->execute();
            $db->query("DELETE FROM llm_debug_log WHERE created_at < NOW() - INTERVAL " . self::TTL_DAYS . " DAY LIMIT 500");
        } catch (\Throwable $e) {
            error_log('LlmDebugLog: ' . get_class($e) . ': ' . $e->getMessage());
        }
    }

    /** Privacy boundary for every caller, including retries and direct utility logging. */
    public static function redactRecall(mixed $data, ?bool &$found = null): mixed {
        if(is_array($data)){foreach($data as $key=>$value)$data[$key]=self::redactRecall($value,$found);return $data;}
        if(!is_string($data))return $data;
        if($data==='[Recall source text omitted from debug log]'){$found=true;return $data;}
        // Inspect serialized values before looking for literal Unicode markers.
        // Ordinary JSON must retain its original whitespace and escaping byte for byte.
        $json=json_decode($data,true);
        if(json_last_error()===JSON_ERROR_NONE&&(is_array($json)||is_string($json))){
            $jsonFound=false;$redacted=self::redactRecall($json,$jsonFound);
            if($jsonFound){$found=true;return json_encode($redacted,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);}
            return $data;
        }
        $decoded=html_entity_decode($data,ENT_QUOTES|ENT_HTML5,'UTF-8');
        $positions=[];foreach([MemoryRecallBlock::MARKER,MemoryRecallBlock::FOCUS]as$marker){$position=mb_stripos($decoded,$marker);if($position!==false)$positions[]=$position;}
        if(!$positions)return $data;
        $found=true;return trim(mb_substr($decoded,0,min($positions))).'[Recall source text omitted from debug log]';
    }

    /**
     * Pure: не-строку — в JSON; base64-картинки (data-URI и b64_json ответа
     * images API) заменяются маркером с размером; потолок длины MAX_FIELD.
     */
    public static function squash($data): string {
        $s = is_string($data)
            ? $data
            : (string)json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $s = (string)preg_replace_callback(
            '/data:image\/[a-z+.\-]+;base64,[A-Za-z0-9+\/=\\\\]{50,}/i',
            fn($m) => '[b64-картинка, ' . strlen($m[0]) . ' симв.]',
            $s
        );
        $s = (string)preg_replace_callback(
            '/"b64_json"\s*:\s*"[^"]{50,}"/',
            fn($m) => '"b64_json":"[картинка, ' . strlen($m[0]) . ' симв.]"',
            $s
        );
        if (mb_strlen($s) > self::MAX_FIELD) {
            $s = mb_substr($s, 0, self::MAX_FIELD) . '…[обрезано]';
        }
        return $s;
    }

    /** Короткое имя провайдера для колонки provider (LLM\RouterAIProvider → RouterAI). */
    public static function providerName(object $provider): string {
        $short = basename(str_replace('\\', '/', get_class($provider)));
        return preg_replace('/Provider$/', '', $short) ?: $short;
    }

    /** Модель провайдера, если он умеет её отдавать (OpenAI-совместимые умеют). */
    public static function providerModel(object $provider): string {
        return method_exists($provider, 'getModel') ? (string)$provider->getModel() : '';
    }

    public static function eraseAliases(array $aliases): void {
        $db=Database::getInstance()->getConnection();
        foreach(array_unique(array_filter($aliases)) as $name){
            $pattern='(@|\\] +)'.preg_quote($name,'~').'([^[:alnum:]_]|$)';
            $stmt=$db->prepare('UPDATE llm_debug_log SET request="[Удалено по запросу пользователя]",response="[Удалено по запросу пользователя]" WHERE REGEXP_LIKE(request,?) OR REGEXP_LIKE(response,?)');
            $stmt->bind_param('ss',$pattern,$pattern);$stmt->execute();
        }
    }


}
