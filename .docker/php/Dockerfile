FROM php:8.3-fpm

# Устанавливаем системные зависимости и расширения PHP
# Добавляем opcache для оптимизации производительности
RUN apt-get update && apt-get install -y \
    git \
    unzip \
    libzip-dev \
    && docker-php-ext-install mysqli pdo pdo_mysql zip opcache

# Рекомендуемые настройки для Opcache в продакшене
RUN { \
        echo 'opcache.memory_consumption=128'; \
        echo 'opcache.interned_strings_buffer=8'; \
        echo 'opcache.max_accelerated_files=4000'; \
        echo 'opcache.revalidate_freq=2'; \
        echo 'opcache.fast_shutdown=1'; \
        echo 'opcache.enable=1'; \
    } > /usr/local/etc/php/conf.d/opcache-recommended.ini

# Устанавливаем Composer (официальный способ)
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html
