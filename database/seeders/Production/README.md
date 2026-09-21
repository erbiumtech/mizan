# Production seeders — deliberately untracked

The files in this directory load **real data**: this company's own roster, its clients, its suppliers and
the figures behind its monthly billing. They are ignored by git (`.gitignore`), and the ignore rule is the
point of this README — which *is* tracked, so the directory does not look empty by accident.

## Why

`docs/open-source-release-checklist.md` §1.1–1.3 found sixteen real people in the default seeders, with
their reporting lines and, for six of them, personal Gmail addresses; plus real salary structures and the
registered company name. The first fix moved all of it out of `db:seed` and into this directory, which
stopped a fresh install or a demo from carrying it. It did not stop the **repository** from carrying it,
and for other people's personal data that is the half that matters — a private repo is one access-grant
away from not being private, and git history outlives any decision to publish.

So the data lives on the machines that need it and nowhere else.

## What this means in practice

- **The files still work.** They are on disk here, on the developer machines that already had them and on
  the app server, where `git reset --hard` during a deploy leaves untracked files alone.
- **A fresh clone has none of them.** `db:seed` is unaffected — it never called these. A new machine that
  genuinely needs to re-seed real data copies the file from a machine that has it, or from a backup.
- **The data is already in the production database.** These were one-time bootstraps; the ledger, the
  payslips and the billing runs that followed are the record now.

## The shape, for anyone writing a replacement

Each is an ordinary `Illuminate\Database\Seeder` run by name and never from `DatabaseSeeder`:

    php artisan db:seed --class="Database\Seeders\Production\RealEmployeeSeeder"

They are tenant-scoped — a company must be current, so run them through `php artisan tenants:artisan` or
from a context that has made one current. Each uses `firstOrCreate`/`updateOrCreate` so a second run tops
up rather than duplicating. The dummy equivalents in `database/seeders` are the template: same structure,
invented names on the reserved `example.test` domain.

## Still in git history

Untracking removes these from the working tree of future clones, not from the history of this one. If this
repository is ever published, that history has to be rewritten (`git filter-repo`) or the repository
re-created from a fresh initial commit — see the release checklist. Until then the exposure is whoever can
already read the repo.
