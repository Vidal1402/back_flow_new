# Imagem mínima para Railway: escuta sempre em 0.0.0.0:$PORT (sem Caddy/FrankenPHP).
# Evita 502 por desalinhamento SERVER_NAME vs PORT do Railpack.

# A imagem composer:2 não tem ext-mongodb; a extensão instala-se no stage seguinte.
FROM composer:2 AS vendor
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --optimize-autoloader --no-interaction --no-scripts \
    --ignore-platform-req=ext-mongodb

FROM php:8.4-cli-bookworm

RUN apt-get update && apt-get install -y --no-install-recommends \
    $PHPIZE_DEPS \
    libssl-dev \
    && pecl install mongodb \
    && docker-php-ext-enable mongodb \
    && apt-get purge -y --auto-remove $PHPIZE_DEPS \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /app

COPY --from=vendor /app/vendor ./vendor
COPY . .

ENV PORT=8080
EXPOSE 8080

# Railway define PORT; o servidor HTTP embutido do PHP usa esse valor.
CMD ["sh", "-c", "exec php -S 0.0.0.0:${PORT} -t public public/index.php"]
