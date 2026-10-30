<?php

namespace Tests\Feature;

use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Support\ConstructionAccounts;
use App\Modules\ConstructionContracts\Filament\Settings\ConstructionAccountsSettingsSection;
use App\Modules\Core\Models\CompanyModule;
use App\Support\TenantSettings;
use Database\Seeders\ConstructionAccountsSeeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\AccountingTestCase;
use Tests\Concerns\InteractsWithTenant;

/**
 * The construction account map is chosen from the chart — no account number is read at runtime.
 *
 * The bug: `config/accounting.php` shipped goods received not invoiced as 2600, which the standard chart gives to
 * Customer Advances, and `ConstructionAccountsSeeder` then skipped creating the real account because the code was
 * taken. The map is now `construction.accounts` (line => account id), written by the seeder and by a tenant
 * migration for companies seeded before.
 */
class ConstructionAccountMapTest extends AccountingTestCase
{
    use InteractsWithTenant;

    private const MIGRATION = '2026_10_30_220000_construction_account_map_from_the_chart';

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs($this->makeUser('Administrator', 'accountmap@test.local'));
        $this->setCurrentTenant();

        foreach (['construction', 'construction_contracts', 'construction_costing', 'accounting'] as $module) {
            CompanyModule::updateOrCreate(
                ['company_id' => $this->tenant->getKey(), 'module' => $module],
                ['licensed' => true, 'enabled' => true],
            );
        }
        modules()->flush();
    }

    private function settings(): TenantSettings
    {
        return app(TenantSettings::class);
    }

    public function test_the_seeder_gives_goods_received_not_invoiced_its_own_account_not_customer_advances(): void
    {
        $this->assertSame('Customer Advances', Account::where('code', '2600')->value('name'), 'the standard chart owns 2600');

        $this->seed(ConstructionAccountsSeeder::class);

        $grni = Account::findOrFail(ConstructionAccounts::id('goods_received_not_invoiced'));

        $this->assertSame('Goods Received Not Invoiced', $grni->name);
        $this->assertNotSame('2600', $grni->code);
        $this->assertSame('Customer Advances', Account::where('code', '2600')->value('name'), 'never renamed or borrowed');
        $this->assertCount(count(ConstructionAccounts::KEYS), ConstructionAccounts::map(), 'every line is chosen');
    }

    public function test_a_taken_code_moves_the_new_account_to_the_next_free_one(): void
    {
        Account::create(['code' => '2605', 'name' => 'Something else', 'type' => 'liability']);

        $this->seed(ConstructionAccountsSeeder::class);

        $this->assertSame('2606', ConstructionAccounts::code('goods_received_not_invoiced'));
        $this->assertSame('Something else', Account::where('code', '2605')->value('name'));
    }

    public function test_reseeding_keeps_the_company_choice_and_adds_nothing(): void
    {
        $this->seed(ConstructionAccountsSeeder::class);

        $elsewhere = Account::create(['code' => '2699', 'name' => 'Our own GRNI', 'type' => 'liability']);
        $map = ConstructionAccounts::map();
        $map['goods_received_not_invoiced'] = $elsewhere->getKey();
        $this->settings()->set(ConstructionAccounts::SETTING, $map);
        $accounts = Account::count();

        $this->seed(ConstructionAccountsSeeder::class);

        $this->assertSame($elsewhere->getKey(), ConstructionAccounts::id('goods_received_not_invoiced'));
        $this->assertSame($accounts, Account::count());
    }

    public function test_an_unchosen_line_refuses_rather_than_falling_back_to_a_shipped_code(): void
    {
        // The chart has a 4600 — but nothing chose it, and nothing is read from config any more.
        Account::create(['code' => '4600', 'name' => 'Contract Revenue', 'type' => 'income']);
        $this->assertNull(config('accounting.construction_accounts'));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('No account is chosen for construction line \'contract_revenue\'');

        ConstructionAccounts::id('contract_revenue');
    }

    public function test_the_settings_section_shows_and_saves_the_chosen_accounts(): void
    {
        $this->seed(ConstructionAccountsSeeder::class);
        $section = app(ConstructionAccountsSettingsSection::class);
        $other = Account::where('code', '2610')->value('id');

        $state = $section->fill();
        $this->assertSame(ConstructionAccounts::id('goods_received_not_invoiced'), $state['construction_account_goods_received_not_invoiced']);

        $state['construction_account_goods_received_not_invoiced'] = $other;
        $state['construction_account_contract_revenue'] = null;
        $section->save($state);

        $this->assertSame($other, ConstructionAccounts::id('goods_received_not_invoiced'));
        $this->assertArrayNotHasKey('contract_revenue', ConstructionAccounts::map(), 'a cleared line is unchosen');
    }

    public function test_the_section_leaves_the_map_alone_when_it_was_not_on_the_page(): void
    {
        $this->seed(ConstructionAccountsSeeder::class);
        $map = ConstructionAccounts::map();

        app(ConstructionAccountsSettingsSection::class)->save(['petty_cash_float_amount' => 0]);

        $this->assertSame($map, ConstructionAccounts::map());
    }

    // ---------------------------------------------------------------- the backfill

    /** The chart as the old seeder left it: every construction account except GRNI, whose 2600 was taken. */
    private function oldSeededChart(): void
    {
        foreach ([
            ['4600', 'Contract Revenue', 'income'], ['1610', 'Contract Assets', 'asset'], ['1620', 'Retention Receivable', 'asset'],
            ['2610', 'Accrued Subcontract Costs', 'liability'], ['5620', 'Job Cost — Labour', 'expense'],
        ] as [$code, $name, $type]) {
            Account::create(['code' => $code, 'name' => $name, 'type' => $type]);
        }

        DB::table('settings')->where('key', ConstructionAccounts::SETTING)->delete();
        $this->settings()->flush();
    }

    private function migrate(): void
    {
        (require database_path('migrations/tenant/'.self::MIGRATION.'.php'))->up();
        $this->settings()->flush();
    }

    public function test_the_backfill_maps_the_seeded_accounts_and_gives_grni_a_real_account(): void
    {
        $this->oldSeededChart();

        $this->migrate();

        $this->assertSame('4600', ConstructionAccounts::code('contract_revenue'));
        $this->assertSame('1620', ConstructionAccounts::code('retention_receivable'));
        $grni = Account::findOrFail(ConstructionAccounts::id('goods_received_not_invoiced'));
        $this->assertSame('Goods Received Not Invoiced', $grni->name);
        $this->assertSame('2605', $grni->code);
        $this->assertSame('Customer Advances', Account::where('code', '2600')->value('name'));
    }

    public function test_the_backfill_honours_the_codes_a_company_had_saved(): void
    {
        $this->oldSeededChart();
        $ours = Account::create(['code' => '1625', 'name' => 'Retention held by clients', 'type' => 'asset']);
        $this->settings()->set('accounting.construction_accounts', ['retention_receivable' => '1625', 'contract_revenue' => '']);

        $this->migrate();

        $this->assertSame($ours->getKey(), ConstructionAccounts::id('retention_receivable'));
        $this->assertSame('4600', ConstructionAccounts::code('contract_revenue'), 'a blank line kept the shipped code');
        $this->assertFalse(DB::table('settings')->where('key', 'accounting.construction_accounts')->exists(), 'the old codes are gone');
    }

    public function test_the_backfill_leaves_a_company_without_construction_alone(): void
    {
        DB::table('settings')->where('key', ConstructionAccounts::SETTING)->delete();
        $accounts = Account::count();

        $this->migrate();

        $this->assertFalse(DB::table('settings')->where('key', ConstructionAccounts::SETTING)->exists());
        $this->assertSame($accounts, Account::count());
    }
}
