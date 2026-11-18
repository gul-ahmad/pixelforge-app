#!/usr/bin/env bash
# Builds or updates the app on the office server. Run as the app user, never as root:
#
#   sudo -u your-app-user -H bash /var/www/your-app/pixelforge/deploy/deploy.sh
#
# Safe to run again for every update.
set -euo pipefail

APP_DIR=/var/www/your-app/pixelforge
PHP=/usr/bin/php8.4                 # the server's default "php" is 8.2; this app needs 8.4
COMPOSER=/usr/local/bin/composer

cd "$APP_DIR"

if [ ! -f .env ]; then
    echo "Missing .env: copy deploy/env.production.example to .env and fill it in first." >&2
    exit 1
fi

# Vite's Wayfinder plugin runs plain "php artisan …" during the build. Point "php" at 8.4
# for this script only (nothing system-wide changes).
mkdir -p .deploy-bin
ln -sf "$PHP" .deploy-bin/php
export PATH="$APP_DIR/.deploy-bin:$PATH"

echo "==> Pulling latest code"
git pull --ff-only

echo "==> PHP dependencies"
"$PHP" "$COMPOSER" install --no-dev --optimize-autoloader --no-interaction

echo "==> Clear the previous deploy's config and route caches"
# The build's Wayfinder step reads the route list, and a route cache from the last deploy
# would hide new routes (the build then fails on missing @/routes/... files). Only these two
# caches: "optimize:clear" would also empty the app cache (queue restart signal, rate limits,
# job uniqueness locks). Both are rebuilt in the "Caches" step below.
"$PHP" artisan config:clear
"$PHP" artisan route:clear

echo "==> Frontend build"
npm ci --no-audit --no-fund
npm run build

echo "==> Database"
[ -f database/database.sqlite ] || touch database/database.sqlite
if ! grep -q '^APP_KEY=base64:' .env; then
    "$PHP" artisan key:generate --force
fi
"$PHP" artisan migrate --force

echo "==> Caches"
"$PHP" artisan config:cache
"$PHP" artisan route:cache
"$PHP" artisan view:cache

echo "==> Restart queue workers (cron starts a fresh one with the new code within a minute)"
"$PHP" artisan queue:restart

echo "==> Done"
