<?php

namespace App\Health;

use App\Modules\Core\Models\Company;
use Illuminate\Support\Facades\DB;
use Spatie\Health\Checks\Check;
use Spatie\Health\Checks\Result;
use Throwable;

/**
 * Can every company's database actually be reached?
 *
 * The package's own `DatabaseCheck` calls `DB::connection($name)->getPdo()` on one connection.
 * In a database-per-tenant application that connection is the landlord — so the check goes green
 * while any number of companies are unreachable, and the first person to notice is a customer
 * who cannot open their books.
 *
 * Pointing `DatabaseCheck` at the `tenant` connection does not fix it either, for the same reason
 * it did not fix the backup: `tenant` is one connection name whose `database` is swapped per
 * company, so it would test whichever company happened to be current — one of forty, chosen by
 * whatever ran last, and reported as though it spoke for all of them.
 *
 * This connects to each company's database in turn and names the ones that refused. It is the
 * health-check twin of {@see \App\Backup\TenantBackup}, and it exists for the same reason: in
 * this architecture, anything that says "the database" is asking the wrong question.
 *
 * **Why a plain connect and nothing more.** No table counts, no migration-state check, no query
 * against a known row. Those are slower, they differ per company as modules are licensed, and a
 * health check that is expensive gets run less often — which is the opposite of what it is for.
 * "Can I open a connection to this database" is the question whose false answer breaks
 * everything downstream.
 */
class TenantDatabaseCheck extends Check
{
    /**
     * Below this many unreachable companies it is a warning; at or above it, a failure.
     *
     * One unreachable company out of forty is an incident for that company and a warning here;
     * every company unreachable is the database server being down. Both need to be visible, and
     * conflating them means whoever is woken up cannot tell which they are dealing with from the
     * notification alone.
     */
    protected int $failAtCount = 2;

    public function failAtCount(int $count): self
    {
        $this->failAtCount = $count;

        return $this;
    }

    public function run(): Result
    {
        $template = config('multitenancy.tenant_database_connection_name');

        if (blank($template) || blank(config("database.connections.{$template}"))) {
            // Not a failure. A single-database installation — and the test suite — has no tenant
            // connection, and reporting that as broken would train somebody to ignore this check.
            return Result::make()->ok('No tenant connection configured; nothing to check.');
        }

        $companies = Company::query()->orderBy('id')->get(['id', 'slug', 'database']);

        if ($companies->isEmpty()) {
            return Result::make()->ok('No companies yet.');
        }

        $unreachable = [];

        foreach ($companies as $company) {
            if (blank($company->database)) {
                $unreachable[$company->slug] = 'no database recorded';

                continue;
            }

            try {
                $this->connect($template, $company->database);
            } catch (Throwable $e) {
                // The message rather than just the slug: "unknown database" and "access denied"
                // and "connection refused" need three different people, and a list of slugs
                // makes somebody go and find that out one company at a time.
                $unreachable[$company->slug] = $e->getMessage();
            }
        }

        $checked = $companies->count();
        $failed = count($unreachable);

        $result = Result::make()->meta([
            'companies_checked' => $checked,
            'unreachable' => $unreachable,
        ])->shortSummary("{$failed}/{$checked} unreachable");

        if ($failed === 0) {
            return $result->ok("All {$checked} company databases reachable.");
        }

        $detail = collect($unreachable)
            ->map(fn (string $error, string $slug): string => "{$slug} ({$error})")
            ->implode('; ');

        $message = "{$failed} of {$checked} company databases could not be reached: {$detail}";

        return $failed >= $this->failAtCount
            ? $result->failed($message)
            : $result->warning($message);
    }

    /**
     * Open a connection to one company's database and let go of it.
     *
     * Its own throwaway connection name, purged each time. Reusing the live `tenant` connection
     * would repoint the connection the request is currently using — so a health check running in
     * a tenant context could leave the application talking to a different company's database,
     * which is a data-leak shaped bug caused by a monitoring tool.
     */
    private function connect(string $template, string $database): void
    {
        $name = 'health_tenant_check';

        config([
            "database.connections.{$name}" => array_merge(
                config("database.connections.{$template}"),
                ['database' => $database],
            ),
        ]);

        DB::purge($name);

        try {
            DB::connection($name)->getPdo();
        } finally {
            // Always, including after a failure: a dead PDO handle left in the manager would be
            // handed to the next company's check and report its error again.
            DB::purge($name);
        }
    }
}
