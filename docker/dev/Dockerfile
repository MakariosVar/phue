FROM php:8.5-cli

COPY --from=oven/bun:1.4.2 /usr/local/bin/bun /usr/local/bin/bun

# build-essential is not optional here: every pecl install below compiles from
# source, and without a compiler `pecl install swoole` fails deep inside the
# build with a message about `make` rather than about the missing package.
RUN apt-get update && apt-get install -y \
    git \
    unzip \
    build-essential \
    libzip-dev \
    libcurl4-openssl-dev \
    curl \
    && docker-php-ext-install zip curl sockets pdo_mysql \
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

# Verify the extension the main server needs is actually loaded, so a build that
# lost it fails here with one clear line instead of surfacing later as a fatal
# from `phue serve`. `php -m` lists loaded modules; the `-r` is the cheap way to
# ask for exactly one.
RUN php -r 'exit(extension_loaded("swoole") ? 0 : fwrite(STDERR, "swoole extension failed to load\n") || 1);'

# Dependencies are installed in the image, not by the person who just cloned a
# project.
#
# The manifest files are copied on their own first and installed before the
# source: Docker caches each layer, so editing application code rebuilds
# nothing while editing composer.json re-runs only the dependency step. Copying
# the whole tree first — the obvious way to write this — invalidates the layer
# on every source change and makes a rebuild take minutes for no reason.
#
# Only composer.json is copied, deliberately. A scaffolded application resolves
# its own dependency set, which is not the framework's, so shipping the
# framework's composer.lock would pin a project to versions it was never tested
# against. A glob like composer.lock* is not an alternative: it resolves to
# nothing in a fresh project and Docker fails the build on the unmatched source.
WORKDIR /app

COPY composer.json ./
RUN composer install --no-interaction --no-scripts --no-autoloader

COPY ssr/package.json ./ssr/
RUN cd ssr && bun install

COPY . .
RUN composer dump-autoload --optimize --no-interaction
