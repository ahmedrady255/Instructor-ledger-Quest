# Stage 1: Assets builder (Node.js 20 LTS)
FROM node:20-alpine AS assets
WORKDIR /app
COPY package*.json ./
RUN if [ -f package.json ]; then npm install; fi
COPY . .
RUN if [ -f package.json ] && [ -f vite.config.js ]; then npm run build; else mkdir -p public/build; fi

# Stage 2: Base PHP 8.3 FPM runtime
FROM php:8.3-fpm-bookworm AS base

ARG UID=1000
ARG GID=1000

ENV DEBIAN_FRONTEND=noninteractive
ENV COMPOSER_ALLOW_SUPERUSER=1

RUN apt-get update && apt-get install -y --no-install-recommends \
    curl \
    git \
    unzip \
    zip \
    libzip-dev \
    libicu-dev \
    libonig-dev \
    default-mysql-client \
    supervisor \
    procps \
    && rm -rf /var/lib/apt/lists/*

COPY --from=mlocati/php-extension-installer /usr/bin/install-php-extensions /usr/local/bin/

RUN install-php-extensions \
    pdo_mysql \
    bcmath \
    intl \
    mbstring \
    opcache \
    pcntl \
    zip \
    curl \
    redis

COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

COPY docker/php/php.ini /usr/local/etc/php/conf.d/99-app.ini
COPY docker/php/opcache.ini /usr/local/etc/php/conf.d/opcache.ini

COPY docker/supervisor/supervisord.conf /etc/supervisor/supervisord.conf
COPY docker/supervisor/horizon.conf /etc/supervisor/conf.d/horizon.conf

RUN usermod -u ${UID} www-data 2>/dev/null || true \
    && groupmod -g ${GID} www-data 2>/dev/null || true

WORKDIR /var/www/Instructor-ledger

COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
CMD ["php-fpm"]

# Stage 3: Production image
FROM base AS production

COPY . /var/www/Instructor-ledger
COPY --from=assets /app/public/build /var/www/Instructor-ledger/public/build

RUN if [ -f composer.json ]; then \
    composer install --no-dev --optimize-autoloader --no-interaction; \
    fi \
    && chown -R www-data:www-data /var/www/Instructor-ledger/storage /var/www/Instructor-ledger/bootstrap/cache 2>/dev/null || true

USER www-data
CMD ["php-fpm"]
