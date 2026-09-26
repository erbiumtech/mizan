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
# Landlord only. Each company's own database is migrated by `php artisan tenants:migrate`
# — see App\Support\TenantMigrations — which is a separate decision from deploying
# code, because it runs per company and can take a while. It is printed again at the
# end of this script, because a release whose tenant schema is behind fails at the
# first screen that reads a new table rather than here.
php artisan migrate --force

echo "==> Permissions and roles"
# **Not optional, and idempotent.** A module declares its permissions in its own
# `module.php`, and a policy that checks one the database has not got does not deny —
# `hasPermissionTo()` *throws*, so the panel 500s rather than hiding a button.
# PermissionSeeder's own docblock names that failure. It is exactly what a release
# that adds a permission and forgets this step ships.
#
# Both are safe to re-run: the first writes the declared set (with a guard against
# discovering nothing, which would otherwise wipe the table), and the second syncs
# every company's five roles — Administrator gains a new module's permissions the
# moment they exist, which is the other half of why this belongs in every deploy
# rather than in a runbook somebody follows when they remember.
php artisan db:seed --class=Database\\Seeders\\PermissionSeeder --force
php artisan db:seed --class=Database\\Seeders\\RoleSeeder --force

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
# Horizon too: it finishes the jobs in flight and exits, and systemd (deploy/horizon/) starts
# it again on this release. Harmless when Horizon is not running.
php artisan horizon:terminate || true

echo "==> Reload PHP-FPM"
# OPcache runs with opcache.validate_timestamps=0 (deploy/php/opcache.ini), so an
# FPM that is not reloaded keeps serving the release before this one — forever.
# This used to be a printed reminder at the end of the log, which is not a
# mitigation. `sudo -n` so an unattended deploy falls through to the instruction
# instead of hanging on a password prompt.
if sudo -n systemctl reload php8.3-fpm 2>/dev/null; then
    echo "    php8.3-fpm reloaded; OPcache now serves this release."
else
    echo "    sudo needs a password here, so FPM was NOT reloaded. Until somebody"
    echo "    runs the following, PHP is still executing the previous release:"
    echo
    echo "        sudo systemctl reload php8.3-fpm"
fi

echo
echo "Done. Two things this script deliberately does not do:"
echo
echo "  1. Tenant migrations. They run per company and can take a while, so they are"
echo "     a decision rather than a step — but a release whose tenant schema is behind"
echo "     fails at the first screen that reads a new table:"
echo
echo "         php artisan tenants:migrate"
echo
echo "  2. Top up each company's reference data. Migrations create the tables; the rows"
echo "     that ship with the application arrive through the baseline seeders, which only"
echo "     ever add — the withholding sections a supplier deduction reads, and the two"
echo "     deferral accounts, both landed this way:"
echo
echo "         php artisan tenants:seed-baseline"
echo
