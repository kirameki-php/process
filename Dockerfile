FROM php:8-cli

COPY --from=composer /usr/bin/composer /usr/local/bin/composer

RUN set -e \
    && apt-get update && apt-get install -y \
        bash \
        git \
    && docker-php-ext-install -j$(nproc) pcntl \
    && mkdir -p /app

WORKDIR /app
