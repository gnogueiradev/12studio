# Multi-stage adaptado do projeto qrcode: base -> dependencies -> build ->
# production. O deploy (Jenkinsfile) constroi os assets para o public/
# bind-mounted ANTES de trocar o container — o container antigo continua a
# servir durante o build (zero-downtime por ordenacao).

FROM webdevops/php-nginx:8.4 AS base
ENV WEB_DOCUMENT_ROOT=/app/public
# Node para o build do Vite/SSR (nao usado em runtime alem do SSR bundle).
RUN curl -fsSL https://deb.nodesource.com/setup_22.x | bash - \
    && apt-get install -y --no-install-recommends nodejs \
    && rm -rf /var/lib/apt/lists/*

FROM base AS dependencies
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction
COPY package.json package-lock.json ./
RUN npm ci

FROM dependencies AS build
WORKDIR /app
COPY . .
# Envs fixados para o artisan funcionar em build (sem .env real): sqlite em
# memoria, tudo sync/array — nada disto sobrevive para runtime.
ENV APP_ENV=production \
    DB_CONNECTION=sqlite \
    DB_DATABASE=:memory: \
    SESSION_DRIVER=array \
    CACHE_STORE=array \
    QUEUE_CONNECTION=sync \
    MAIL_MAILER=array
RUN composer dump-autoload --optimize \
    && php artisan package:discover --ansi \
    && echo "APP_KEY=" > .env \
    && php artisan key:generate --force \
    && php artisan wayfinder:generate --with-form \
    && npm run build:ssr \
    && npm prune --omit=dev \
    && rm -f .env

FROM base AS production
WORKDIR /app
COPY --from=build /app /app
# Supervisor: worker das filas, scheduler e SSR ao lado do nginx+fpm.
COPY docker/queue-worker.conf docker/scheduler.conf docker/inertia-ssr.conf /opt/docker/etc/supervisor.d/
COPY docker/opcache.ini /opt/docker/etc/php/php.ini
COPY docker/nginx-vhost.conf /opt/docker/etc/nginx/vhost.common.d/12studio.conf
COPY docker/entrypoint-permissions.sh /opt/docker/provision/entrypoint.d/20-permissions.sh
RUN chmod +x /opt/docker/provision/entrypoint.d/20-permissions.sh \
    && chown -R application:application /app

# O healthcheck do compose/Jenkins bate no /up do Laravel.
HEALTHCHECK --interval=30s --timeout=5s --start-period=30s --retries=3 \
    CMD curl -fsS http://localhost/up || exit 1
