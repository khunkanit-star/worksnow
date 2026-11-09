FROM dunglas/frankenphp:1-php8.4-bookworm

WORKDIR /app
RUN install-php-extensions pdo_mysql pdo_sqlite \
    && cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"

COPY Caddyfile /etc/caddy/Caddyfile
COPY index.php config.example.php ./
COPY app ./app
COPY public ./public
COPY assets ./public/assets

ENV PORT=80 \
    WORKSNOW_REQUIRE_EXTERNAL_DB=1 \
    WORKSNOW_SECURE_COOKIES=1 \
    WORKSNOW_SESSION_DRIVER=database

EXPOSE 80
CMD ["frankenphp", "run", "--config", "/etc/caddy/Caddyfile"]
