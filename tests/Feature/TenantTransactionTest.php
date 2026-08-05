<?php

namespace Tests\Feature;

use App\Modules\Accounting\Models\Account;
use App\Support\TenantTransaction;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\AccountingTestCase;

/**
 * The helper that decides which connection a transaction opens on.
 *
 * Worth its own test because the suite cannot catch what it fixes: there is no
 * tenant connection here (multitenancy.tenant_database_connection_name is null
 * and TenantModel falls back to the default), so a transaction on the wrong
 * connection behaves identically in tests and silently commits in production.
 * What is assertable is the choice itself.
 */
class TenantTransactionTest extends AccountingTestCase
{
    public function test_it_targets_the_tenant_connection_when_one_is_configured(): void
    {
        config(['multitenancy.tenant_database_connection_name' => 'tenant']);

        $this->assertSame('tenant', TenantTransaction::connectionName());
        $this->assertNotSame(
            config('database.default'),
            TenantTransaction::connectionName(),
            'the whole point: tenant data does not live on the default connection'
        );
    }

    public function test_it_falls_back_to_the_default_connection_without_tenancy(): void
    {
        config(['multitenancy.tenant_database_connection_name' => null]);

        $this->assertSame(config('database.default'), TenantTransaction::connectionName());
    }

    public function test_it_is_the_connection_tenant_models_actually_use(): void
    {
        // The property being maintained, stated directly rather than by name: a
        // transaction must open where the writes land.
        $this->assertSame(
            (new Account)->getConnection()->getName(),
            TenantTransaction::connectionName(),
        );
    }

    public function test_it_returns_the_callback_value_and_commits(): void
    {
        $account = TenantTransaction::run(fn () => Account::create([
            'code' => '5810',
            'name' => 'Committed Expense',
            'type' => 'expense',
        ]));

        $this->assertInstanceOf(Account::class, $account);
        $this->assertTrue(Account::where('code', '5810')->exists());
    }

    public function test_a_throwing_callback_rolls_the_writes_back(): void
    {
        $before = Account::count();

        try {
            TenantTransaction::run(function () {
                Account::create([
                    'code' => '5811',
                    'name' => 'Rolled Back Expense',
                    'type' => 'expense',
                ]);

                throw new RuntimeException('abandon');
            });
            $this->fail('expected the exception to propagate');
        } catch (RuntimeException $e) {
            $this->assertSame('abandon', $e->getMessage());
        }

        $this->assertFalse(Account::where('code', '5811')->exists());
        $this->assertSame($before, Account::count());
    }

    public function test_it_nests_without_committing_the_outer_work_early(): void
    {
        $before = Account::count();
        // Not zero: RefreshDatabase holds the suite's own transaction open.
        $depthBefore = DB::transactionLevel();

        try {
            TenantTransaction::run(function () {
                Account::create(['code' => '5812', 'name' => 'Outer', 'type' => 'expense']);

                TenantTransaction::run(fn () => Account::create([
                    'code' => '5813', 'name' => 'Inner', 'type' => 'expense',
                ]));

                throw new RuntimeException('abandon both');
            });
            $this->fail('expected the exception to propagate');
        } catch (RuntimeException) {
            // expected
        }

        $this->assertSame($before, Account::count(), 'the inner write did not survive the outer rollback');
        $this->assertSame($depthBefore, DB::transactionLevel(), 'the savepoints unwound cleanly');
    }
}
