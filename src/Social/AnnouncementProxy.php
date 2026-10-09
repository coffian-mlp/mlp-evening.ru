<?php
namespace Social;

/** Dedicated, short-lived Telegram tunnel; never shares the LLM proxy process. */
final class AnnouncementProxy
{
    private $process = null;
    private ?string $directory = null;
    private ?string $resolved = null;

    public function __construct(private string $url) { self::validate($url); }

    public static function validate(string $url): void
    {
        if ($url === '') return;
        if (strlen($url) > 4096 || preg_match('/[\x00-\x20\x7f]/', $url)) throw new \InvalidArgumentException('Некорректная ссылка прокси.');
        $parts = parse_url($url);
        if (!$parts || empty($parts['host']) || !in_array($parts['scheme'] ?? '', ['http','https','socks5','socks5h','vless'], true)) throw new \InvalidArgumentException('Поддерживаются HTTP, HTTPS, SOCKS5 и VLESS-прокси.');
        if (($parts['scheme'] ?? '') === 'vless') self::config($url, 10809);
    }

    public static function config(string $url, int $port): array
    {
        $u = parse_url($url); parse_str($u['query'] ?? '', $q);
        foreach ($q as $value) if (!is_string($value)) throw new \InvalidArgumentException('Некорректные параметры VLESS.');
        $id = rawurldecode($u['user'] ?? '');
        if (!preg_match('/\A[0-9a-f]{8}(?:-[0-9a-f]{4}){3}-[0-9a-f]{12}\z/iD', $id) || empty($u['host'])) throw new \InvalidArgumentException('В VLESS-ссылке нужен корректный UUID и адрес сервера.');
        $network = $q['type'] ?? 'tcp'; $security = $q['security'] ?? 'none';
        if (!in_array($network, ['tcp','raw','ws','grpc'], true) || !in_array($security, ['none','tls','reality'], true)) throw new \InvalidArgumentException('Этот транспорт VLESS не поддерживается.');
        $user = ['id'=>$id, 'encryption'=>'none'];
        if (!empty($q['flow'])) {
            if ($q['flow'] !== 'xtls-rprx-vision') throw new \InvalidArgumentException('Этот режим VLESS flow не поддерживается.');
            $user['flow'] = $q['flow'];
        }
        $stream = ['network'=>$network, 'security'=>$security];
        if ($security === 'reality') {
            if (empty($q['pbk'])) throw new \InvalidArgumentException('В REALITY-ссылке отсутствует публичный ключ.');
            $stream['realitySettings'] = ['serverName'=>$q['sni'] ?? $u['host'], 'fingerprint'=>$q['fp'] ?? 'chrome', 'publicKey'=>$q['pbk'], 'shortId'=>$q['sid'] ?? '', 'spiderX'=>$q['spx'] ?? '/'];
        } elseif ($security === 'tls') {
            $stream['tlsSettings'] = ['serverName'=>$q['sni'] ?? $u['host'], 'fingerprint'=>$q['fp'] ?? 'chrome'];
        }
        if ($network === 'ws') $stream['wsSettings'] = ['path'=>$q['path'] ?? '/', 'headers'=>['Host'=>$q['host'] ?? $q['sni'] ?? $u['host']]];
        if ($network === 'grpc') $stream['grpcSettings'] = ['serviceName'=>$q['serviceName'] ?? ''];
        return ['log'=>['loglevel'=>'none'], 'inbounds'=>[['listen'=>'127.0.0.1','port'=>$port,'protocol'=>'socks','settings'=>['auth'=>'noauth','udp'=>false]]],
            'outbounds'=>[['protocol'=>'vless','settings'=>['vnext'=>[['address'=>$u['host'],'port'=>$u['port'] ?? 443,'users'=>[$user]]]],'streamSettings'=>$stream]]];
    }

    public function resolve(): string
    {
        if ($this->resolved !== null) return $this->resolved;
        if (!str_starts_with($this->url, 'vless://')) return $this->resolved = preg_replace('~^socks5://~', 'socks5h://', $this->url);
        $binary = dirname(__DIR__, 2).'/src/LLM/bin/xray';
        if (!is_executable($binary)) throw new TelegramBotException('Telegram VLESS runtime unavailable.');
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
        if (!$socket) throw new TelegramBotException('Telegram proxy port unavailable.');
        $port = (int)substr(strrchr(stream_socket_get_name($socket, false), ':'), 1); fclose($socket);
        $this->directory = sys_get_temp_dir().'/mlp-announcements-'.bin2hex(random_bytes(12));
        if (!mkdir($this->directory, 0700)) throw new TelegramBotException('Telegram proxy runtime unavailable.');
        $file = $this->directory.'/config.json';
        if (file_put_contents($file, json_encode(self::config($this->url, $port), JSON_THROW_ON_ERROR)) === false) throw new TelegramBotException('Telegram proxy configuration unavailable.');
        chmod($file, 0600);
        $this->process = proc_open([$binary, 'run', '-config', $file], [0=>['file','/dev/null','r'],1=>['file','/dev/null','w'],2=>['file','/dev/null','w']], $pipes);
        if (!is_resource($this->process)) throw new TelegramBotException('Telegram proxy could not start.');
        for ($i=0; $i<30; $i++) {
            if (!proc_get_status($this->process)['running']) break;
            $connection = @fsockopen('127.0.0.1', $port, $errno, $error, 0.1);
            if ($connection) { fclose($connection); return $this->resolved = 'socks5h://127.0.0.1:'.$port; }
            usleep(100000);
        }
        throw new TelegramBotException('Telegram proxy did not become ready.');
    }

    public function __destruct()
    {
        if (is_resource($this->process)) { proc_terminate($this->process); usleep(100000); if (proc_get_status($this->process)['running']) proc_terminate($this->process, 9); proc_close($this->process); }
        if ($this->directory !== null) { @unlink($this->directory.'/config.json'); @rmdir($this->directory); }
    }
}
