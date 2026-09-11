#!/bin/sh
set -e

cd /var/www/html

if [ ! -f .env ]; then
  cp .env.example .env
fi

if [ ! -d vendor ]; then
  composer install --no-interaction --prefer-dist
fi

if ! grep -q "^APP_KEY=base64:" .env; then
  php artisan key:generate --force --ansi
fi

mkdir -p database
touch database/database.sqlite

php artisan migrate --force
php artisan db:seed --force

if [ ! -d node_modules ]; then
  npm ci
fi

npm run build

exec php artisan serve --host=0.0.0.0 --port=8000
