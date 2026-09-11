FROM php:8.3-cli-bookworm

ENV COMPOSER_ALLOW_SUPERUSER=1
ENV COMPOSER_NO_INTERACTION=1

RUN apt-get update && apt-get install -y --no-install-recommends git unzip curl ca-certificates libzip-dev libsqlite3-dev libicu-dev libonig-dev && docker-php-ext-install pdo_sqlite zip intl bcmath pcntl && rm -rf /var/lib/apt/lists/*
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY --from=node:22-bookworm /usr/local/bin/node /usr/local/bin/node
COPY --from=node:22-bookworm /usr/local/lib/node_modules /usr/local/lib/node_modules
COPY docker/npmw /usr/local/bin/npm
WORKDIR /var/www/html
COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh /usr/local/bin/npm
COPY . .
EXPOSE 8000
ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
