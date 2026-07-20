FROM php:8.3-fpm

# Устанавливаем системные зависимости и расширения PHP
# Добавляем opcache для оптимизации производительности
# MLP-258: + GD (webp/jpeg/png/gif/freetype) — превью стикеров, паритет с продом
RUN apt-get update && apt-get install -y \
    git \
    unzip \
    libzip-dev \
    libpng-dev \
    libjpeg-dev \
    libwebp-dev \
    libfreetype6-dev \
    && docker-php-ext-configure gd --with-jpeg --with-webp --with-freetype \
    && docker-php-ext-install mysqli pdo pdo_mysql zip opcache gd

# Рекомендуемые настройки для Opcache в продакшене
RUN { \
        echo 'opcache.memory_consumption=128'; \
        echo 'opcache.interned_strings_buffer=8'; \
        echo 'opcache.max_accelerated_files=4000'; \
        echo 'opcache.revalidate_freq=2'; \
        echo 'opcache.fast_shutdown=1'; \
        echo 'opcache.enable=1'; \
    } > /usr/local/etc/php/conf.d/opcache-recommended.ini

# Копируем кастомный конфиг загрузок
COPY .docker/php/uploads.ini /usr/local/etc/php/conf.d/uploads.ini

# Устанавливаем Composer (официальный способ)
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html
