FROM composer:2 AS vendor
WORKDIR /app
COPY composer.json composer.lock ./
COPY src/ src/
RUN composer install --no-dev --no-interaction --no-progress --ignore-platform-reqs --classmap-authoritative

FROM dunglas/frankenphp:1.13.2-php8.5.12
RUN install-php-extensions pdo_pgsql pcntl zip \
    && setcap -r /usr/local/bin/frankenphp \
    && useradd --create-home --uid 1000 app \
    && mkdir -p /app/storage \
    && chown -R app:app /app/storage /config/caddy /data/caddy
WORKDIR /app
COPY docker/app/Caddyfile /etc/frankenphp/Caddyfile
COPY docker/app/start.sh /usr/local/bin/start-app
COPY --from=vendor /app/vendor/ vendor/
COPY config.php ./
COPY bin/ bin/
COPY public/ public/
COPY src/ src/
USER app
ENV PORT=8080
EXPOSE 8080
HEALTHCHECK --interval=30s --timeout=5s CMD curl -fsS "http://localhost:${PORT}/" > /dev/null || exit 1
CMD ["start-app"]
