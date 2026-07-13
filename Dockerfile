FROM php:8.4-cli-alpine

RUN apk add --no-cache \
    bash git curl unzip linux-headers nodejs npm

RUN docker-php-ext-install pcntl pdo_mysql sockets

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

COPY composer.json composer.lock* ./
RUN composer install \
    --no-dev \
    --no-scripts \
    --no-autoloader \
    --no-interaction \
    --prefer-dist

COPY package.json package-lock.json* ./
RUN if [ -f package-lock.json ]; then \
      npm ci --no-audit --no-fund; \
    else \
      npm install --no-audit --no-fund; \
    fi

COPY . .

RUN cp .env.example .env \
    && mkdir -p storage/framework/cache storage/framework/sessions storage/framework/views storage/logs bootstrap/cache \
    && composer dump-autoload --optimize --classmap-authoritative \
    && php artisan package:discover --ansi \
    && php artisan key:generate --force --no-interaction \
    && npm run build \
    && chmod -R 775 storage bootstrap/cache \
    && chmod +x docker/ensure-env.sh \
    && rm -f .env

EXPOSE 8000 8080

CMD ["sh", "-lc", "\
  /app/docker/ensure-env.sh || exit 1; \
  php artisan config:clear --no-interaction || true && \
  php artisan route:clear --no-interaction || true && \
  php artisan view:clear --no-interaction || true && \
  echo \"Ready -> http://localhost:${APP_SERVER_PORT:-8000}\" && \
  exec php artisan serve --host=${APP_SERVER_HOST:-0.0.0.0} --port=${APP_SERVER_PORT:-8000} \
"]
