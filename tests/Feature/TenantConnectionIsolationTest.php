<?php

namespace Tests\Feature;

use App\Modules\Construction\Models\CostCode;
use App\Modules\Core\Models\Company;
use App\Modules\Core\Models\User;
use App\Multitenancy\CompanyProvisioner;
use App\Support\TenantDb;
use App\Support\TenantTransaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

/**
 * The distinction the rest of the suite cannot express, proved against a real second database.
 *
 * `TENANT_DATABASE_CONNECTION` is `""` in phpunit.xml, so `tenant_database_connection_name` is null,
 * `TenantModel` falls back to the default connection, and the tenant and landlord databases are the same
 * one. That is deliberate and makes the suite fast — and it is also why two production bugs survived
 * hundreds of passing tests:
 *
 *  - 43 hand-written `DB::table()` queries read tenant tables on the landlord connection. In production
 *    those tables are not there, so `PeriodCloseService::open()` threw "Base table or view not found"
 *    behind 126 passing accrual and period-close tests.
 *  - 14 `DB::transaction()` calls opened on the landlord connection while their bodies wrote tenant rows,
 *    so nothing inside them was transactional. That one fails **silently**: `TenantTransaction`'s docblock
 *    records a payslip reversal that stayed posted, and `GnuCashImportService` records a rolled-back
 *    "preview" that imported permanently.
 *
 * `TenantConnectionGuardTest` catches both shapes by reading the source, which is cheap and holds the line
 * for the two known forms. This is the other half: it stands up an actual tenant database — following
 * `TenantBaselineCompletenessTest`, which already provisions one — and asserts the behaviour rather than
 * the spelling. It would catch a shape nobody thought to grep for.
 */
class TenantConnectionIsolationTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<string> */
    private array $provisioned = [];

    protected function setUp(): void
    {
        parent::setUp();

        // The switch the rest of the suite leaves off.
        config(['multitenancy.tenant_database_connection_name' => 'tenant']);
    }

    protected function tearDown(): void
    {
        Company::forgetCurrent();

        foreach ($this->provisioned as $path) {
            if ($path && File::exists($path)) {
                File::delete($path);
            }
        }

        parent::tearDown();
    }

    private function company(): Company
    {
        $company = app(CompanyProvisioner::class)->provision(
            name: 'Isolation '.uniqid(),
            creator: User::factory()->create(),
        );

        $this->provisioned[] = $company->database;
        $company->makeCurrent();

        return $company;
    }

    /**
     * THE structural fact: the two connections are different databases.
     *
     * Note what this deliberately does **not** assert. In production the landlord has no tenant tables at
     * all — verified directly: `mpr` holds 0 `construction_*` tables against the tenant's 82 — which is why
     * the query bug threw "Base table or view not found". Under test it cannot: `RefreshDatabase` migrates
     * every migration into the in-memory database, so the landlord carries the tenant schema too.
     *
     * So the loud form of the bug is not reproducible here, and everything below tests the **silent** form
     * instead — a query that finds the table and the wrong rows. That is the more dangerous half anyway.
     */
    public function test_the_tenant_and_default_connections_are_different_databases(): void
    {
        $company = $this->company();

        $this->assertTrue(Schema::connection('tenant')->hasTable('construction_cost_codes'));

        $this->assertNotSame(
            DB::connection('tenant')->getDatabaseName(),
            DB::connection(config('database.default'))->getDatabaseName(),
            'the tenant must be a genuinely separate database, or nothing below means anything'
        );

        $this->assertSame($company->database, DB::connection('tenant')->getDatabaseName());
    }

    /** A tenant model writes to the tenant database, which is the premise. */
    public function test_a_tenant_model_writes_to_the_tenant_connection(): void
    {
        $this->company();

        CostCode::create(['code' => 'ISO-1', 'name' => 'Isolation probe']);

        $this->assertSame('tenant', (new CostCode)->getConnectionName());
        $this->assertSame(1, DB::connection('tenant')->table('construction_cost_codes')->where('code', 'ISO-1')->count());
    }

    /**
     * `TenantDb::table()` finds the row; `DB::table()` does not.
     *
     * This is the silent failure in miniature. The landlord query does not error — it finds a table of that
     * name and reports zero rows, which is a cost report that comes back empty and looks fine. In production
     * the same query errors instead, because the landlord has no such table; both are the one bug.
     */
    public function test_only_the_tenant_helper_reaches_the_row(): void
    {
        $this->company();

        CostCode::create(['code' => 'ISO-2', 'name' => 'Isolation probe']);

        $this->assertSame(
            1,
            TenantDb::table('construction_cost_codes')->where('code', 'ISO-2')->count(),
            'the helper must read the connection the row was written to'
        );

        $this->assertSame(
            0,
            DB::connection(config('database.default'))->table('construction_cost_codes')->where('code', 'ISO-2')->count(),
            'a landlord query must not find a tenant row — this zero is what a bare DB::table() returns, '
            .'silently, in place of the data somebody asked for'
        );
    }

    /**
     * The silent half, demonstrated rather than argued.
     *
     * `TenantTransaction::run()` rolls the tenant write back. A bare `DB::transaction()` around the same
     * write does not — it opens and rolls back a transaction on the landlord connection that never
     * contained it, so the row survives a thrown exception. This is the shape that left a payslip
     * reversal posted with nothing to replace it.
     */
    public function test_only_the_tenant_transaction_actually_rolls_a_tenant_write_back(): void
    {
        $this->company();

        try {
            TenantTransaction::run(function (): void {
                CostCode::create(['code' => 'ISO-ROLLED', 'name' => 'Should not survive']);

                throw new RuntimeException('fail after writing');
            });
        } catch (RuntimeException) {
            // expected
        }

        $this->assertSame(
            0,
            TenantDb::table('construction_cost_codes')->where('code', 'ISO-ROLLED')->count(),
            'TenantTransaction must roll a tenant write back'
        );

        try {
            DB::transaction(function (): void {
                CostCode::create(['code' => 'ISO-SURVIVED', 'name' => 'Survives, wrongly']);

                throw new RuntimeException('fail after writing');
            });
        } catch (RuntimeException) {
            // expected
        }

        $this->assertSame(
            1,
            TenantDb::table('construction_cost_codes')->where('code', 'ISO-SURVIVED')->count(),
            'a landlord transaction cannot roll back a tenant write — if this ever reads 0, the two '
            .'connections have stopped being separate and this whole test file has stopped meaning anything'
        );
    }

    /** Two companies do not see each other's rows, which is what "database per tenant" has to mean. */
    public function test_two_companies_do_not_share_rows(): void
    {
        $first = $this->company();
        CostCode::create(['code' => 'ISO-A', 'name' => 'First company']);

        $second = $this->company();

        $this->assertNotSame($first->database, $second->database);
        $this->assertSame(0, CostCode::where('code', 'ISO-A')->count(), "the second company must not see the first's codes");

        CostCode::create(['code' => 'ISO-B', 'name' => 'Second company']);

        $first->makeCurrent();

        $this->assertSame(1, CostCode::where('code', 'ISO-A')->count());
        $this->assertSame(0, CostCode::where('code', 'ISO-B')->count());
    }
}
