<?php

class XrayManager {
    private $xrayPath;
    private $configPath;
    private $socksPort = 10808;

    public function __construct() {
        // Мы будем хранить бинарник Xray в папке src/LLM/bin
        $this->xrayPath = __DIR__ . '/bin/xray';
        $this->configPath = __DIR__ . '/bin/config.json';
    }

    /**
     * Проверяет, запущен ли Xray и отвечает ли его SOCKS5 порт.
     * Если не отвечает - пытается запустить.
     */
    public function ensureRunning($vlessLink) {
        if (empty($vlessLink)) return false;

        // Если порт уже слушается, считаем, что Xray работает
        if ($this->isPortOpen('127.0.0.1', $this->socksPort)) {
            return true;
        }

        // Если порт закрыт - надо запускать. Но сначала проверим, есть ли сам бинарник Xray
        if (!file_exists($this->xrayPath)) {
            error_log("Xray binary not found at {$this->xrayPath}");
            return false;
        }

        // Генерируем конфиг из ссылки vless://
        $this->generateConfig($vlessLink);

        // Запускаем Xray в фоне
        // Для Linux: nohup ./xray -c config.json > /dev/null 2>&1 &
        $cmd = "nohup " . escapeshellarg($this->xrayPath) . " -c " . escapeshellarg($this->configPath) . " > " . escapeshellarg(__DIR__ . '/bin/xray.log') . " 2>&1 &";
        shell_exec($cmd);

        // Ждем максимум 2 секунды, пока он поднимется
        $attempts = 0;
        while ($attempts < 20) {
            if ($this->isPortOpen('127.0.0.1', $this->socksPort)) {
                return true;
            }
            usleep(100000); // 100ms
            $attempts++;
        }

        return false; // Не смог запуститься
    }

    /**
     * Превращает vless:// ссылку в минимальный config.json для Xray (режим socks5 in -> vless out)
     */
    private function generateConfig($link) {
        if (strpos($link, 'vless://') !== 0) {
            // Пока поддерживаем только VLESS (самый популярный сейчас)
            // Но можно расширить парсер
            return false;
        }

        // Пример парсинга vless://uuid@host:port?encryption=none&security=reality&...#Name
        $parsedUrl = parse_url($link);
        $uuid = $parsedUrl['user'] ?? '';
        $host = $parsedUrl['host'] ?? '';
        $port = $parsedUrl['port'] ?? 443;
        
        parse_str($parsedUrl['query'] ?? '', $query);
        
        $security = $query['security'] ?? 'none';
        $sni = $query['sni'] ?? $host;
        $pbk = $query['pbk'] ?? '';
        $sid = $query['sid'] ?? '';
        $fp = $query['fp'] ?? 'chrome';
        $type = $query['type'] ?? 'tcp';

        // Базовый скелет конфига
        $config = [
            "log" => ["loglevel" => "warning"],
            "inbounds" => [
                [
                    "listen" => "127.0.0.1",
                    "port" => $this->socksPort,
                    "protocol" => "socks",
                    "settings" => ["udp" => true]
                ]
            ],
            "outbounds" => [
                [
                    "protocol" => "vless",
                    "settings" => [
                        "vnext" => [
                            [
                                "address" => $host,
                                "port" => (int)$port,
                                "users" => [
                                    [
                                        "id" => $uuid,
                                        "encryption" => "none"
                                    ]
                                ]
                            ]
                        ]
                    ],
                    "streamSettings" => [
                        "network" => $type,
                        "security" => $security,
                    ]
                ]
            ]
        ];

        // Настройка REALITY/TLS
        if ($security === 'reality') {
            $config['outbounds'][0]['streamSettings']['realitySettings'] = [
                "publicKey" => $pbk,
                "shortId" => $sid,
                "serverName" => $sni,
                "fingerprint" => $fp,
                "show" => false
            ];
        } elseif ($security === 'tls') {
            $config['outbounds'][0]['streamSettings']['tlsSettings'] = [
                "serverName" => $sni,
                "fingerprint" => $fp
            ];
        }

        // Настройка сети (WS / GRPC и т.д.)
        if ($type === 'ws') {
            $config['outbounds'][0]['streamSettings']['wsSettings'] = [
                "path" => $query['path'] ?? '/',
                "headers" => ["Host" => $sni]
            ];
        }

        // Сохраняем конфиг
        if (!is_dir(dirname($this->configPath))) {
            mkdir(dirname($this->configPath), 0755, true);
        }
        file_put_contents($this->configPath, json_encode($config, JSON_PRETTY_PRINT));
        return true;
    }

    private function isPortOpen($host, $port, $timeout = 1) {
        $connection = @fsockopen($host, $port, $errno, $errstr, $timeout);
        if (is_resource($connection)) {
            fclose($connection);
            return true;
        }
        return false;
    }
}
