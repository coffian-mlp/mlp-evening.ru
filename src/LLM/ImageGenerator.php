<?php

namespace LLM;

use Core\FileCache;
use Infra\ConfigManager;

/**
 * Генерация картинок для Лиры-художницы (MLP-274): RouterAI images API
 * (OpenAI-совместимый, ответ b64_json). Результат пережимается GD в JPEG
 * (наивному стилю не вредит, канал бережёт) и сохраняется в /upload/lyra/.
 *
 * Дневной лимит — счётчик в cache/imagegen/<дата>.json: успешные генерации
 * учитываются атомарно; сохранённый drawing receipt защищает восстановление от дубля.
 */
class ImageGenerator {

    const UPLOAD_DIR = '/upload/lyra/';
    const MAX_DIM = 1024;
    const JPEG_QUALITY = 88;
    const TIMEOUT = 110;
    const MAX_ATTEMPTS = 3;

    /** Причина последнего сбоя generate() — сырьё для живого извинения Лиры. */
    private static ?string $lastError = null;

    public static function lastError(): ?string {
        return self::$lastError;
    }

    /** Сегодняшний счётчик генераций (для лимита и статистики). */
    public static function todayCount(?FileCache $cache = null, ?string $day = null): int {
        $cache = $cache ?? new FileCache('imagegen');
        $day = $day ?? gmdate('Y-m-d');
        $row = $cache->get($day, 172800); // TTL 2 суток — вчерашний файл умирает сам
        return (int)($row['count'] ?? 0);
    }

    public static function bumpToday(?FileCache $cache = null, ?string $day = null, ?string $receipt = null): void {
        $cache = $cache ?? new FileCache('imagegen');
        $day = $day ?? gmdate('Y-m-d');
        $cache->updateAtomic($day, static function(array $row) use ($receipt): array {
            if ($receipt !== null && isset($row['receipts'][$receipt])) return $row;
            $row['count'] = (int)($row['count'] ?? 0) + 1;
            if ($receipt !== null) $row['receipts'][$receipt] = true;
            return $row;
        });
    }

    /**
     * Сгенерировать и сохранить картинку. Возвращает web-путь /upload/lyra/...jpg
     * или null (детали сбоя — в error_log). $model переопределяет настройку
     * (используется дегустацией моделей).
     */
    public static function generate(string $prompt, ?string $model = null, ?string $reference = null, array $options = []): ?string {
        self::$lastError = null;
        $c = ConfigManager::getInstance();
        $model = $model ?? (string)$c->getOption('ai_image_model', 'black-forest-labs/flux.2-klein-4b');
        $payload = ['model' => $model, 'prompt' => $prompt, 'n' => 1, 'size' => '1024x1024'];
        $proxy = null;

        // MLP-296: выбор провайдера — как у vision-помощника. Оба ходят в
        // OpenAI-совместимый /images/generations (ответ b64_json).
        $provider = (string)$c->getOption('ai_image_provider', 'routerai');
        if ($provider === 'openai') {
            $key = (string)$c->getOption('ai_openai_key', '');
            if ($key === '') {
                error_log('ImageGenerator: провайдер openai — нет ai_openai_key');
                self::$lastError = 'провайдер openai не настроен (нет ключа)';
                return null;
            }
            // База настроена на chat/completions — производим images-эндпоинт из неё.
            $base = (string)$c->getOption('ai_openai_base_url', 'https://api.openai.com/v1/chat/completions');
            $endpoint = preg_replace('#/chat/completions/?$#', '/images/generations', $base);
            if ($endpoint === $base) {
                $endpoint = rtrim($base, '/') . '/images/generations';
            }
            $payload['response_format'] = 'b64_json'; // OpenAI по умолчанию отдаёт url
            // socks5-прокси как у vision-помощника (vless не поддерживаем)
            $p = (string)$c->getOption('ai_proxy_url', '');
            $proxy = (strpos($p, 'socks5') === 0) ? str_replace('socks5://', 'socks5h://', $p) : null;
        } else {
            $key = (string)$c->getOption('ai_routerai_key', '');
            if ($key === '') {
                error_log('ImageGenerator: нет ai_routerai_key');
                self::$lastError = 'провайдер routerai не настроен (нет ключа)';
                return null;
            }
            $endpoint = 'https://routerai.ru/api/v1/images/generations';
        }

        $multipart = false;
        if ($reference !== null) {
            try { $dataUrl = self::referenceData($reference); }
            catch (\Throwable $error) { self::$lastError = 'исходный рисунок недоступен'; return null; }
            if ($provider === 'openai') {
                $endpoint = preg_replace('#/images/generations$#', '/images/edits', $endpoint);
                $payload['image'] = new \CURLStringFile(base64_decode(substr($dataUrl, strpos($dataUrl, ',') + 1)), basename($reference), substr($dataUrl, 5, strpos($dataUrl, ';') - 5));
                $multipart = true;
            } elseif (str_starts_with($model, 'google/')) {
                $endpoint = 'https://routerai.ru/api/v1/chat/completions';
                $payload = ['model'=>$model,'messages'=>[['role'=>'user','content'=>[
                    ['type'=>'text','text'=>$prompt],['type'=>'image_url','image_url'=>['url'=>$dataUrl]],
                ]]],'modalities'=>['image','text'],'image_config'=>['aspect_ratio'=>'1:1','image_size'=>'1K']];
            } else {
                $endpoint = 'https://routerai.ru/api/v1/images';
                $payload['input_references'] = [['type'=>'image_url','image_url'=>['url'=>$dataUrl]]];
            }
        }
        $logPayload = $payload;
        if ($reference !== null) $logPayload = ['model'=>$model,'prompt'=>$prompt,'reference'=>$reference];
        $headers = ['Authorization: Bearer ' . $key];
        if (!$multipart) $headers[] = 'Content-Type: application/json';
        $clock = $options['clock'] ?? static fn() => microtime(true);
        $pause = $options['pause'] ?? static fn(float $seconds) => usleep((int)round($seconds*1000000));
        $deadline = $options['deadline'] ?? ($clock() + self::TIMEOUT*self::MAX_ATTEMPTS + 1);
        $transport = $options['transport'] ?? [self::class, 'transport'];
        $generation = $options['generation_id'] ?? bin2hex(random_bytes(8));
        $log = static function(array $request, string $body, string $status, int $ms) use ($options, $provider, $model): void {
            if (isset($options['debug'])) ($options['debug'])($request, $body, $status, $ms);
            else LlmDebugLog::log('image', $provider, $model, $request, $body, $status, $ms);
        };
        for ($attempt=1; $attempt<=self::MAX_ATTEMPTS; $attempt++) {
            if (isset($options['guard']) && !($options['guard'])()) { self::$lastError='рисунок больше недоступен'; return null; }
            $timeout = min(self::TIMEOUT*1000, (int)floor(($deadline-$clock())*1000));
            if ($timeout < 1) { self::$lastError ??= 'время генерации истекло'; return null; }
            $exchange = $transport($endpoint,$payload,$headers,$proxy,$multipart,$timeout);
            $res = (string)($exchange['body'] ?? ''); $code=(int)($exchange['code'] ?? 0);
            $errno=(int)($exchange['errno'] ?? 0); $err=(string)($exchange['error'] ?? '');
            $ms=(int)($exchange['duration_ms'] ?? 0);
            UsageMeter::capture($provider,$model,$res,$code,$errno!==0 || $err!=='',$ms,'image');
            $request=$logPayload + ['attempt'=>$attempt,'max_attempts'=>self::MAX_ATTEMPTS,'generation_id'=>$generation,
                'scene_variant'=>$options['scene_variant'] ?? 'original','http_code'=>$code,'curl_errno'=>$errno];
            $embedded=self::extractError($res);
            $safety=$errno===0 && self::isSafetyMessage($embedded);
            if ($errno!==0 || $err!=='' || $code<200 || $code>=300 || $embedded!==null || $res==='') {
                self::$lastError=$embedded ?? self::failureReason($res,$err,$code);
                $log($request,$err!=='' ? $err : $res,'error',$ms);
                if (!$safety && self::retryable($errno,$code) && $attempt<self::MAX_ATTEMPTS) {
                    $delay=$attempt===1 ? 0.25 : 0.5;
                    if ($deadline-$clock()>$delay) { $pause($delay); continue; }
                }
                return null;
            }
            break;
        }
        $data = json_decode(trim((string)$res), true);
        $b64 = $data['data'][0]['b64_json'] ?? null;
        if (!$b64 && $reference !== null) {
            $url = $data['choices'][0]['message']['images'][0]['image_url']['url'] ?? '';
            if (preg_match('#^data:image/(?:png|jpeg|webp);base64,(.+)$#sD', $url, $match)) $b64 = $match[1];
        }
        if (!$b64) {
            error_log("ImageGenerator [$model]: нет b64_json в ответе");
            self::$lastError = 'провайдер прислал ответ без картинки';
            $log($request,$res,'error',$ms);
            return null;
        }
        $bytes = is_string($b64) ? base64_decode($b64, true) : false;
        if ($bytes === false || $bytes === '') {
            error_log("ImageGenerator [$model]: b64_json не декодировался");
            self::$lastError = 'картинка от провайдера не читается';
            $log($request,$res,'error',$ms);
            return null;
        }
        $saved = isset($options['save']) ? ($options['save'])($bytes) : self::saveJpeg($bytes);
        if ($saved === null) {
            self::$lastError = 'картинка получена, но не сохранилась на сервере';
        }
        if ($saved !== null) self::$lastError=null;
        $log($request,$res,$saved!==null ? 'ok' : 'error',$ms);
        return $saved;
    }

    /**
     * Pure: короткая причина сбоя для извинения Лиры — error.message из JSON
     * провайдера (как у OpenAI/RouterAI), иначе curl-ошибка / голый HTTP-код.
     */
    /** Pure (MLP-336): сообщение об ошибке из тела ответа (в т.ч. HTTP 200 с error-JSON после пробелов) или null. */
    public static function extractError(string $body): ?string {
        $body = trim($body);
        if ($body === '' || $body[0] !== '{') return null;
        $msg = json_decode($body, true)['error']['message'] ?? null;
        return is_string($msg) && $msg !== '' ? mb_substr($msg, 0, 300) : null;
    }

    /** Pure (MLP-336): отказ фильтра безопасности (сюжет, а не техника) — повод перерисовать мягче. */
    public static function isSafetyMessage(?string $msg): bool {
        return $msg !== null && (bool)preg_match('/safety|content (policy|blocked|filter)|blocked|violat|moderation/i', $msg);
    }

    public static function lastErrorIsSafety(): bool {
        return self::isSafetyMessage(self::$lastError);
    }

    public static function failureReason(string $body, string $curlErr, int $httpCode): string {
        $msg = (string)(json_decode($body, true)['error']['message'] ?? '');
        if ($msg !== '') {
            return mb_substr($msg, 0, 300);
        }
        if ($curlErr !== '') {
            return 'сеть подвела: ' . mb_substr($curlErr, 0, 200);
        }
        return "провайдер ответил ошибкой HTTP $httpCode";
    }

    /** GD-пережатие в JPEG (max 1024) и сохранение в /upload/lyra/. */
    public static function saveJpeg(string $bytes, ?string $nameHint = null): ?string {
        $src = @imagecreatefromstring($bytes);
        if (!$src) {
            error_log('ImageGenerator: битые байты картинки');
            return null;
        }
        $w = imagesx($src);
        $h = imagesy($src);
        $scale = min(1.0, self::MAX_DIM / max($w, $h));
        $nw = max(1, (int)round($w * $scale));
        $nh = max(1, (int)round($h * $scale));
        $dst = imagecreatetruecolor($nw, $nh);
        imagefilledrectangle($dst, 0, 0, $nw, $nh, imagecolorallocate($dst, 255, 255, 255));
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);

        $root = dirname(__DIR__, 2);
        $dir = $root . self::UPLOAD_DIR;
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            error_log('ImageGenerator: не создать ' . $dir);
            return null;
        }
        $name = ($nameHint ?: uniqid('lyra_')) . '_' . bin2hex(random_bytes(4)) . '.jpg';
        if (!imagejpeg($dst, $dir . $name, self::JPEG_QUALITY)) {
            // Классика: каталог создан root-ом по ssh, воркер (bitrix) писать не может.
            error_log('ImageGenerator: не записать ' . $dir . $name . ' (права? владелец каталога?)');
            return null;
        }
        // Sanity (MLP-277: разовый инцидент исчезнувшего файла) — битая ссылка
        // не должна попасть в чат ни при каких обстоятельствах.
        clearstatcache(true, $dir . $name);
        if (!is_file($dir . $name) || filesize($dir . $name) < 1000) {
            error_log('ImageGenerator: файл не прошёл sanity-проверку после записи: ' . $dir . $name);
            return null;
        }
        return self::UPLOAD_DIR . $name;
    }

    /** Local generated images only; never fetch caller-supplied URLs or arbitrary files. */
    public static function referenceData(string $webPath): string {
        if (!preg_match('#^/upload/lyra/[a-zA-Z0-9_-]+\.(?:jpg|jpeg|png|webp)$#D', $webPath)) throw new \RuntimeException('Invalid drawing reference');
        $root = realpath(dirname(__DIR__, 2) . self::UPLOAD_DIR);
        $path = realpath(dirname(__DIR__, 2) . $webPath);
        if (!$root || !$path || !str_starts_with($path, $root . DIRECTORY_SEPARATOR) || !is_file($path) || filesize($path) > 10 * 1024 * 1024) throw new \RuntimeException('Drawing reference unavailable');
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($path);
        if (!in_array($mime, ['image/jpeg','image/png','image/webp'], true)) throw new \RuntimeException('Invalid drawing reference type');
        return 'data:' . $mime . ';base64,' . base64_encode(file_get_contents($path));
    }

    public static function retryable(int $errno, int $httpCode): bool {
        if ($errno !== 0) return in_array($errno,[5,6,7,16,18,28,52,55,56,92],true);
        return $httpCode===429 || ($httpCode>=500 && $httpCode<=599);
    }

    private static function transport(string $endpoint, array $payload, array $headers, ?string $proxy, bool $multipart, int $timeout): array {
        $ch=curl_init($endpoint);
        curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_POST=>true,CURLOPT_HTTPHEADER=>$headers,
            CURLOPT_POSTFIELDS=>$multipart ? $payload : json_encode($payload),CURLOPT_TIMEOUT=>self::TIMEOUT,
            CURLOPT_TIMEOUT_MS=>$timeout,CURLOPT_CONNECTTIMEOUT_MS=>min(10000,$timeout)]);
        if ($proxy!==null) curl_setopt($ch,CURLOPT_PROXY,$proxy);
        $started=microtime(true);
        try {
            $body=curl_exec($ch);
            return ['body'=>is_string($body)?$body:'','code'=>(int)curl_getinfo($ch,CURLINFO_HTTP_CODE),
                'errno'=>curl_errno($ch),'error'=>curl_error($ch),'duration_ms'=>(int)round((microtime(true)-$started)*1000)];
        } finally { curl_close($ch); }
    }
}
