<?php

namespace App\Backup;

use App\Modules\Core\Models\Company;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Back up each company's own database, one archive each.
 *
 * `spatie/laravel-backup` dumps a list of *connection names*. This application is
 * database-per-tenant: there is one `tenant` connection whose `database` is swapped at runtime
 * by `SwitchTenantDatabaseTask`, and one database per company behind it. So the package cannot
 * see the tenants on its own —
 *
 *  - its default (`['mysql']`) backs up the landlord and none of the books;
 *  - adding `tenant` to that list backs up whichever company happened to be current, chosen
 *    unpredictably by whatever ran last.
 *
 * Neither of those fails loudly. Both produce an archive of the expected size on the expected
 * schedule, and you find out what is missing when you try to restore.
 *
 * **One archive per company, not one archive containing every company.** A combined archive
 * grows without bound and makes restoring one company mean extracting it out of everyone
 * else's data — during an incident, under time pressure, with the other companies live. Per
 * company, a restore is: take that file, load that database.
 *
 * **A tenant archive is only half a restore.** The `companies` row that names the database, and
 * the `users` and role assignments that reach it, are in the landlord — so a tenant archive
 * without a landlord archive from a compatible moment restores a database nothing can open.
 * `backup:tenants` says so when it finishes rather than leaving it to be discovered.
 */
class TenantBackup
{
    /**
     * A throwaway connection used only to point a dumper at one company's database.
     *
     * Reused and repointed per company rather than registering one connection per tenant: the
     * dump is sequential anyway, and N permanent connections in `database.connections` is N
     * more things that look real to anything enumerating them.
     */
    public const CONNECTION = 'backup_tenant';

    /**
     * Point {@see self::CONNECTION} at one company's database and return its name.
     *
     * Copies the `tenant` connection wholesale so credentials, charset, collation and options
     * follow whatever multitenancy is already using — anything else would drift the day
     * somebody adds an SSL option to the tenant connection and not to this one.
     */
    public function connectionFor(Company $company): string
    {
        $template = config('multitenancy.tenant_database_connection_name');

        if (blank($template) || blank(config("database.connections.{$template}"))) {
            throw new RuntimeException(
                "The tenant database connection '{$template}' is not configured, so tenant databases "
                .'cannot be backed up. Check multitenancy.tenant_database_connection_name.'
            );
        }

        if (blank($company->database)) {
            throw new RuntimeException(
                "Company {$company->slug} has no database recorded against it, so there is nothing to "
                .'back up. A company provisioned without one is a bug worth chasing rather than skipping.'
            );
        }

        config([
            'database.connections.'.self::CONNECTION => array_merge(
                config("database.connections.{$template}"),
                ['database' => $company->database],
            ),
        ]);

        // Without this the connection keeps the previous company's PDO handle — and would
        // cheerfully dump the previous company's database into this company's archive, which is
        // both a wrong backup and a data leak between tenants.
        DB::purge(self::CONNECTION);

        return self::CONNECTION;
    }

    /**
     * Where one company's archives go: its own top-level backup name, and a matching prefix.
     *
     * **A separate name, not a subfolder inside the landlord's**, and that is the whole point of
     * this method existing. `backup:monitor` lists its destination with `allFiles()`, which
     * recurses — so tenant archives nested under the landlord's name would satisfy the
     * landlord's "newest backup is less than a day old" check. `backup:run` could then fail
     * every night for a month and the monitor would stay green on fresh tenant archives, which
     * is a worse position than having no monitor at all.
     *
     * A sibling directory is outside `allFiles({landlord name})`, so the landlord's health check
     * once again reflects the landlord's backup and nothing else.
     *
     * @return array{name: string, prefix: string}
     */
    public function destinationFor(Company $company): array
    {
        return [
            'name' => config('backup.backup.name').'-tenant-'.$company->slug,
            'prefix' => "tenant-{$company->slug}-",
        ];
    }

    /**
     * Back up one company's database.
     *
     * `--only-db`: the uploads are not per-tenant — they sit on shared disks under
     * `storage/app` and are covered once by `backup:run`. Including them here would copy every
     * company's files into every company's archive.
     *
     * @return int the exit code `backup:run` gave
     */
    public function run(Company $company): int
    {
        $connection = $this->connectionFor($company);
        $destination = $this->destinationFor($company);

        // Swapped for the duration of this one dump and restored in a `finally`, so a company
        // that throws mid-dump cannot leave the landlord configuration pointing at a tenant —
        // which would make the next `backup:run` quietly archive one company's books under the
        // landlord's name.
        $original = [
            'backup.backup.name' => config('backup.backup.name'),
            'backup.backup.source.databases' => config('backup.backup.source.databases'),
            'backup.backup.destination.filename_prefix' => config('backup.backup.destination.filename_prefix'),
        ];

        config([
            'backup.backup.name' => $destination['name'],
            'backup.backup.source.databases' => [$connection],
            'backup.backup.destination.filename_prefix' => $destination['prefix'],
        ]);

        try {
            // `--config=backup` is not decoration, and leaving it off is a silent, dangerous
            // bug that this went through once already.
            //
            // The package binds its Config object with `$this->app->scoped(...)`, so it is
            // built from config/backup.php the first time anything resolves it and cached for
            // the rest of the process. `BackupCommand` takes it by constructor injection — so
            // the three overrides above are invisible to it, and `backup:run` cheerfully dumps
            // the *landlord* again, under the landlord's name and prefix, once per company.
            // Every archive is plausible, correctly timestamped, and the wrong database.
            //
            // Passing `--config` makes the command re-read `config('backup')` at execution
            // time, which is the package's own escape hatch for exactly this. Asserted in
            // BackupConfigurationTest.
            return Artisan::call('backup:run', [
                '--only-db' => true,
                '--config' => 'backup',
            ]);
        } finally {
            config($original);
        }
    }

    /**
     * Every company, in id order.
     *
     * **Unfiltered, and each clause left out is deliberate.** Not by status: a suspended
     * company's books are exactly as legally required to exist as a busy one's, and "we stopped
     * backing them up when they went quiet" is not a sentence anybody wants to say. Not by
     * licensing: a licence is a commercial fact, not a statement about whose data matters.
     *
     * And not by `whereNotNull('database')` either, which was here first and was wrong twice
     * over. `companies.database` is NOT NULL, so it filtered nothing — but had it ever matched,
     * it would have *silently dropped* the one company whose backup most needs looking at. A
     * company with no database name is a bug to surface; the command reports it as a per-company
     * failure and carries on with the rest.
     */
    public function companies(): \Illuminate\Support\Collection
    {
        return Company::query()->orderBy('id')->get();
    }
}
