FROM php:8.5-cli

COPY --from=oven/bun:1.4.2 /usr/local/bin/bun /usr/local/bin/bun

RUN apt-get update && apt-get install -y \
    git \
    unzip \
    libzip-dev \
    libcurl4-openssl-dev \
    curl \
    && docker-php-ext-install zip curl sockets \
    && rm -rf /var/lib/apt/lists/*

RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

RUN git config --global --add safe.directory /app

RUN pecl install redis \
    && docker-php-ext-enable redis

RUN pecl install pcov \
    && docker-php-ext-enable pcov

RUN pecl install swoole \
    && docker-php-ext-enable swoole \
    && echo "swoole.use_shortname=0" >> /usr/local/etc/php/conf.d/docker-php-ext-swoole.ini