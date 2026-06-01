FROM php:8.3-cli-alpine

RUN apk add --no-cache sqlite-dev \
    && docker-php-ext-install pdo_sqlite

WORKDIR /app

COPY src ./src
COPY public ./public

ENV DATA_PATH=/kuma-data \
    CACHE_PATH=/tmp/uptime-kuma-public-report-cache \
    CACHE_TTL=60 \
    APP_TIMEZONE=America/Sao_Paulo \
    DB_TIMEZONE=UTC \
    PUBLIC_TITLE="Relatorio de Incidentes"

USER www-data

EXPOSE 8080

CMD ["php", "-S", "0.0.0.0:8080", "-t", "public", "public/router.php"]
