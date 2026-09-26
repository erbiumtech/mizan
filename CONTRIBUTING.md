# Contributing

Issues and pull requests are welcome. This document is the short version; the
[README](README.md) has the full setup and the security model.

## Setting up

Follow [Getting started](README.md#getting-started) in the README. You need:

- **PHP 8.4+** (`composer.json` requires `^8.4`), Composer
- MySQL 8
- Node 22+ (PDF rendering via Chromium)

```bash
composer install
npm install && npm run build
cp .env.example .env && php artisan key:generate
php artisan migrate --seed
```

## Running tests

```bash
php artisan test
```

The suite runs against a single in-memory SQLite database. **Run one suite at a
time** — concurrent `php artisan test` processes interfere with each other and
produce hundreds of spurious failures.

New behaviour needs a test. Accounting and tax changes need a test that would
fail without them.

## Code style

Pint, before every PR:

```bash
./vendor/bin/pint
```

CI runs `pint --test` and will fail on style drift.

## Pull requests

- Branch from `master`; keep PRs small and focused.
- Explain *why* in the description. Domain rules here often look arbitrary
  until you know the regulation behind them.
- Migrations must be tenant-path aware: tenant tables belong in
  `database/migrations/tenant/`, landlord tables in `database/migrations/`.
- Tests pass, Pint clean — the PR template has the checklist.

## Security issues

Do not open a public issue. See [SECURITY.md](SECURITY.md).
