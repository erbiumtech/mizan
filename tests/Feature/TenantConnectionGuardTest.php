<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * No hand-written query may reach a tenant table on the default connection.
 *
 * `DB::table()` builds against the default connection — the landlord database in production — while every
 * `TenantModel` reads and writes `tenant`. A query written that way looks for a tenant table in the landlord
 * and does not find it: `AccrualService` threw *"Table 'mpr.construction_goods_receipt_lines' doesn't exist"*
 * the first time a cost period was opened outside the test suite, after 126 accrual and period-close tests had
 * passed against it.
 *
 * **This test exists because the suite structurally cannot catch that.** Under test
 * `multitenancy.tenant_database_connection_name` is null, `TenantModel` falls back to the default, and the two
 * connections are the same one — so every such query hits the right rows by accident. No amount of feature
 * testing will find these; only reading the source will.
 *
 * A static check rather than a second live connection, which would be the stronger test and a much larger
 * change: the tenant connection is configured per request by `SwitchTenantDatabaseTask`, and standing a real
 * second database up in the suite means reworking how every test gets its schema. This costs nothing and holds
 * the line today. `use App\Support\TenantDb;` then `TenantDb::table(...)` is the fix at a call site.
 */
class TenantConnectionGuardTest extends TestCase
{
    /** Landlord tables, which `DB::table()` is the correct way to reach. */
    private const LANDLORD_TABLES = [
        'users', 'companies', 'company_modules', 'permissions', 'roles',
        'model_has_permissions', 'model_has_roles', 'role_has_permissions',
        'migrations', 'jobs', 'job_batches', 'failed_jobs', 'cache', 'cache_locks',
        'sessions', 'password_reset_tokens', 'activity_log',
    ];

    /**
     * Every table the tenant migrations create.
     *
     * Read from the migrations rather than listed here, so a table added next month is covered without anybody
     * remembering to add it.
     *
     * @return list<string>
     */
    private function tenantTables(): array
    {
        $tables = [];

        foreach (glob(base_path('database/migrations/tenant/*.php')) as $file) {
            preg_match_all("/Schema::create\(\s*'([a-z0-9_]+)'/", (string) file_get_contents($file), $matches);
            $tables = array_merge($tables, $matches[1]);
        }

        return array_values(array_diff(array_unique($tables), self::LANDLORD_TABLES));
    }

    /** @return list<string> */
    private function sourceFiles(): array
    {
        $files = [];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(app_path(), \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        sort($files);

        return $files;
    }

    public function test_no_tenant_table_is_queried_on_the_default_connection(): void
    {
        $tenantTables = $this->tenantTables();

        $this->assertGreaterThan(100, count($tenantTables), 'the tenant schema should have been discovered');

        $violations = [];

        foreach ($this->sourceFiles() as $path) {
            $source = (string) file_get_contents($path);

            // `DB::table('x')` and `DB::table('x as alias')`, but not `DB::connection(...)->table('x')`.
            preg_match_all("/(?<!connection\(\)->)\bDB::table\(\s*'([a-z0-9_]+)(?:\s+as\s+[a-z0-9_]+)?'/i", $source, $matches, PREG_OFFSET_CAPTURE);

            foreach ($matches[1] as $index => [$table, $offset]) {
                if (! in_array($table, $tenantTables, true)) {
                    continue;
                }

                $line = substr_count(substr($source, 0, (int) $matches[0][$index][1]), "\n") + 1;

                $violations[] = str_replace(base_path().'/', '', $path).":{$line}  DB::table('{$table}')";
            }
        }

        $this->assertSame([], $violations, count($violations).<<<'TEXT'
             hand-written quer(ies) reach a tenant table on the default connection.

            In production that connection is the landlord database, which does not have these tables, so each
            of these throws "Base table or view not found" the moment it runs. The suite cannot catch it: with
            one database under test the tenant and default connections are the same.

            Fix: use App\Support\TenantDb; then TenantDb::table('...') in place of DB::table('...').

            TEXT);
    }

    /**
     * The same bug in the shape that fails *silently*, which is the worse one.
     *
     * `DB::transaction()` opens one on the default connection, so a block of tenant writes wrapped in it is
     * not in a transaction at all — each write commits on the spot and a failure part-way leaves the earlier
     * ones standing. Nothing errors; the data is simply half-written.
     *
     * `TenantTransaction` exists because of it and records the incident: "a payslip save reversed its existing
     * journal entry, then failed validating the replacement, and the reversal stayed posted with nothing left
     * to replace it." `GnuCashImportService` records a second one, where a rolled-back "preview" committed a
     * permanent import. It had reached fourteen more call sites across Attendance, Recruitment, CRM,
     * Lifecycle, Quotations and Leave before this test existed.
     *
     * Scoped to `app/Modules`, which is where tenant services live. A landlord-only write — the company
     * registry, permissions — is correctly a `DB::transaction()`, so this is not a blanket ban; if one is
     * genuinely needed under a module, `DB::connection('...')->transaction()` says so explicitly and passes.
     */
    public function test_no_module_service_opens_a_transaction_on_the_default_connection(): void
    {
        $violations = [];

        foreach ($this->sourceFiles() as $path) {
            if (! str_contains($path, '/Modules/')) {
                continue;
            }

            foreach (explode("\n", (string) file_get_contents($path)) as $number => $line) {
                // Skip docblocks and comments, which discuss this bug at length on purpose.
                if (preg_match('/^\s*(\*|\/\/|#)/', $line)) {
                    continue;
                }

                if (str_contains($line, 'DB::transaction(')) {
                    $violations[] = str_replace(base_path().'/', '', $path).':'.($number + 1);
                }
            }
        }

        $this->assertSame([], $violations, count($violations).<<<'TEXT'
             transaction(s) under app/Modules open on the default connection.

            Tenant writes inside them are not transactional: each commits immediately, and a failure part-way
            leaves the earlier ones standing with nothing to roll them back. It fails silently — no error, just
            half-written data.

            Fix: use App\Support\TenantTransaction; then TenantTransaction::run(fn () => ...).

            TEXT);
    }
}
