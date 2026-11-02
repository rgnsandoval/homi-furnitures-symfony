FROM php:8.4-fpm

RUN apt-get update \
    && apt-get install -y --no-install-recommends git unzip libicu-dev libzip-dev \
    && docker-php-ext-install pdo_mysql intl zip opcache bcmath \
    && rm -rf /var/lib/apt/lists/*

RUN printf 'date.timezone=Asia/Manila\nmemory_limit=512M\nopcache.enable_cli=1\nopcache.memory_consumption=256\nopcache.max_accelerated_files=30000\nrealpath_cache_size=4096K\nrealpath_cache_ttl=600\n' > /usr/local/etc/php/conf.d/homi.ini

COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

WORKDIR /var/www/html
