# 🦄 Настройка Centrifugo для MLP Evening

Этот документ описывает процесс установки и настройки Centrifugo (v5+) на боевом сервере (Ubuntu/Debian) для обеспечения работы чата в реальном времени.

## 1. Установка Centrifugo

Скачиваем последнюю версию (v5.x.x) с GitHub releases.

```bash
# Пример для Linux amd64
wget https://github.com/centrifugal/centrifugo/releases/download/v5.4.5/centrifugo_5.4.5_linux_amd64.tar.gz
tar xzvf centrifugo_5.4.5_linux_amd64.tar.gz
sudo mv centrifugo /usr/local/bin/centrifugo
```

Проверяем установку:
```bash
centrifugo version
```

## 2. Конфигурация

Создаем директорию и генерируем конфиг:

```bash
mkdir -p /etc/centrifugo
centrifugo genconfig --config /etc/centrifugo/config.json
```

**Важно:** Отредактируйте `/etc/centrifugo/config.json`. Приведите его к следующему виду (замените секреты на свои!):

```json
{
  "token_hmac_secret_key": "ВАШ_СУПЕР_СЕКРЕТНЫЙ_КЛЮЧ_ДЛЯ_ТОКЕНОВ",
  "admin_password": "ВАШ_СЛОЖНЫЙ_ПАРОЛЬ_АДМИНА",
  "admin_secret": "ВАШ_СЕКРЕТ_ДЛЯ_АДМИН_API",
  "api_key": "ВАШ_API_KEY_ДЛЯ_БЭКЕНДА",
  "allowed_origins": [
    "https://v4.mlp-evening.ru",
    "http://localhost:8080"
  ],
  "namespaces": [
    {
      "name": "public",
      "history_size": 50,
      "history_ttl": "300s",
      "allow_history_for_subscriber": true,
      "allow_history_for_client": true,
      "allow_presence_for_subscriber": true,
      "allow_subscribe_for_client": true
    }
  ]
}
```

*Обратите внимание на `namespaces`: мы используем имя `public`, что соответствует каналам вида `public:chat`. Опция `allow_subscribe_for_client` обязательна, чтобы фронтенд мог подписываться.*

## 3. Systemd Service

Создаем файл службы `/etc/systemd/system/centrifugo.service`:

```ini
[Unit]
Description=Centrifugo Web Real-Time Messaging
After=network.target

[Service]
Type=simple
User=root
Group=root
LimitNOFILE=65536
ExecStart=/usr/local/bin/centrifugo --config /etc/centrifugo/config.json
Restart=on-failure
RestartSec=2

[Install]
WantedBy=multi-user.target
```

Запускаем:
```bash
sudo systemctl daemon-reload
sudo systemctl enable centrifugo
sudo systemctl start centrifugo
```

## 4. Настройка Nginx

Добавьте этот блок в конфиг вашего сайта (в блок `server`):

```nginx
    # 🌀 Centrifugo Proxy
    # Важно: без слеша в конце location
    location /connection {
        proxy_pass http://127.0.0.1:8000;
        proxy_http_version 1.1;
        proxy_set_header Upgrade $http_upgrade;
        proxy_set_header Connection "upgrade";
        proxy_set_header Host $host;
        
        # Таймауты для долгоживущих соединений
        proxy_read_timeout 600s;
        proxy_send_timeout 600s;
    }
```

Не забудьте перезагрузить Nginx:
```bash
sudo service nginx reload
# Или для BitrixEnv/Apache связки - убедитесь, что конфиг применился
```

## 5. Настройка Бэкенда (PHP)

В файле `config.php` на сервере укажите драйвер и ключи:

```php
'chat' => [
    'driver' => 'centrifugo', 
    'centrifugo_api_url' => 'http://127.0.0.1:8000/api', # Адрес Centrifugo локально
    'centrifugo_api_key' => 'ВАШ_API_KEY_ДЛЯ_БЭКЕНДА',   # Из config.json
    'centrifugo_secret'  => 'ВАШ_СУПЕР_СЕКРЕТНЫЙ_КЛЮЧ_ДЛЯ_ТОКЕНОВ', # token_hmac_secret_key
]
```

Готово! 🚀
