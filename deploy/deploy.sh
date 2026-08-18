#!/usr/bin/env bash
#
# Deploy the application, and leave it in the state it is fast in.
#
# Without the caches below every request re-reads and re-parses every config
# file, every route file and every Blade template that has not been compiled
# yet. Measured on a developer machine, that is ~190ms of framework bootstrap
# before any of this application's own code runs — on every request, whether
# somebody is loading the dashboard or clicking a button.
#
# Run from the project root on the app server:
#
#     deploy/deploy.sh
#
# See docs/page-load-performance-plan.md.

set -euo pipefail

cd "$(dirname "$0")/.."

echo "==> Maintenance mode"
# `down` before the code changes and `up` after, so nobody is served a request
# that starts on the old code and finishes on the new. The secret lets whoever
# is deploying keep browsing to check the result.
php artisan down --render="errors::503" || true
trap 'php artisan up' EXIT

echo "==> PHP dependencies"
composer install --no-dev --optimize-autoloader --no-interaction --prefer-dist

echo "==> Front-end assets"
npm ci
npm run build

echo "==> Database"
# Landlord only. Each company's own database is migrated by
# `php artisan tenants:artisan migrate --tenant=...` — see App\Support\TenantMigrations —
# which is a separate decision from deploying code, because it runs per company
# and can take a while.
php artisan migrate --force

echo "==> Caches"
# Clear first: `optimize` writes over the config and route caches, but a view
# compiled from a template that has since changed is not overwritten, it is
# simply stale.
php artisan optimize:clear
php artisan filament:optimize-clear

# config, routes, events, views.
php artisan optimize
# Blade icon manifest: without it every heroicon in the sidebar is a filesystem
# lookup, and the sidebar draws a hundred of them.
php artisan icons:cache
# Filament's own component and icon caches.
php artisan filament:optimize

echo "==> Restart workers"
# Queue workers hold the old code in memory until they are told otherwise.
php artisan queue:restart

echo
echo "Done. If OPcache is running with opcache.validate_timestamps=0, restart"
echo "PHP-FPM now — otherwise it will keep serving the release before this one:"
echo
echo "    sudo systemctl reload php8.3-fpm"
echo
