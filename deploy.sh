#!/usr/bin/env bash

set -Eeuo pipefail

APP_ROOT="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
cd "$APP_ROOT"

echo "==> Installing PHP dependencies"
composer install \
    --no-dev \
    --prefer-dist \
    --no-interaction \
    --no-progress \
    --optimize-autoloader

echo "==> Installing JavaScript dependencies"
npm ci --ignore-scripts

echo "==> Building customer portal assets"
npm run build

echo "==> Linking public storage"
php artisan storage:link --force

echo "==> Applying database migrations"
php artisan migrate --force --no-interaction

echo "==> Refreshing Laravel caches"
php artisan optimize:clear --no-interaction
php artisan config:cache --no-interaction
php artisan route:cache --no-interaction
php artisan view:cache --no-interaction

echo "==> Deployment complete"
