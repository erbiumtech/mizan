<?php

namespace Tests\Feature;

use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\Bank;
use App\Modules\Accounting\Models\Currency;
use App\Modules\Accounting\Models\TransactionType;
use App\Modules\Core\Models\Company;
use App\Modules\Core\Models\FiscalYear;
use App\Modules\PersonalFinance\Models\TaxSchedule;
use App\Multitenancy\CompanyProvisioner;
use Database\Seeders\PersonalBaselineSeeder;
use Database\Seeders\SalarySlabSeeder;
use Database\Seeders\TenantBaselineSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * A newly provisioned company must come out usable, not merely created.
 *
 * The failure this exists for is quiet: a seeder is added to the codebase, wired
 * into nothing, and every new company thereafter is missing data that no test
 * notices because the tests seed what they need themselves. It has happened
 * twice — three companies were provisioned with no tax schedules at all because
 * TaxScheduleSeeder was added to the baseline after them, and the first personal
 * account came out with no banks and no spending categories.
 *
 * So these assert the reference data itself rather than the seeder list: a list
 * check would pass on a seeder that runs and produces nothing.
 */
class TenantBaselineCompletenessTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<int, string> */
    protected array $provisionedFiles = [];

    protected function setUp(): void
    {
        parent::setUp();

        config(['multitenancy.tenant_database_connection_name' => 'tenant']);
    }

    protected function tearDown(): void
    {
        Company::forgetCurrent();

        foreach ($this->provisionedFiles as $path) {
            if ($path && File::exists($path)) {
                File::delete($path);
            }
        }

        parent::tearDown();
    }

    private function provision(string $type): Company
    {
        $company = app(CompanyProvisioner::class)->provision(
            name: 'Baseline '.$type,
            creator: \App\Modules\Core\Models\User::factory()->create(),
            type: $type,
        );

        $this->provisionedFiles[] = $company->database;
        $company->makeCurrent();

        return $company;
    }

    public function test_a_new_business_comes_out_ready_to_use(): void
    {
        $this->provision(Company::TYPE_BUSINESS);

        $this->assertGreaterThan(0, FiscalYear::count(), 'no fiscal year: nothing can be dated');
        $this->assertGreaterThan(0, Currency::count(), 'no currency: the books do not know what they are in');
        $this->assertGreaterThan(0, Account::count(), 'no chart of accounts: nothing can be posted');
        $this->assertGreaterThan(0, Bank::count(), 'no banks: no employee or beneficiary can be given one');
        $this->assertGreaterThan(0, TransactionType::count(), 'no categories: petty cash and payments cannot be booked');
        $this->assertGreaterThan(0, TaxSchedule::count(), 'no tax brackets');
        $this->assertGreaterThan(0, \App\Modules\Payroll\Models\SalarySlab::count(), 'no salary slabs: payroll cannot tax anybody');
    }

    public function test_a_new_personal_account_comes_out_ready_to_use(): void
    {
        $this->provision(Company::TYPE_PERSONAL);

        $this->assertGreaterThan(0, FiscalYear::count());
        $this->assertGreaterThan(0, Currency::count());
        $this->assertGreaterThan(0, Account::count());
        $this->assertGreaterThan(0, Bank::count(), 'a person has a bank account too');
        $this->assertGreaterThan(0, TransactionType::count(), 'no spending categories');
        $this->assertGreaterThan(0, TaxSchedule::count(), 'no tax brackets, so the estimate cannot run');
    }

    public function test_a_personal_accounts_categories_point_at_its_own_chart(): void
    {
        $this->provision(Company::TYPE_PERSONAL);

        // The trap this guards, stated as the specific wrong answers rather than
        // a name-matching heuristic: the business categories are keyed to the
        // business chart, where 5700 is Office Rent and 5600 is Food. In the
        // personal chart 5700 is Household & Maintenance and 5600 is Medical. Seed
        // the wrong set and rent posts to maintenance and groceries to medical,
        // silently, with nothing on the screen to show for it.
        $expected = [
            'rent' => '5200',
            'food' => '5100',
            'education' => '5300',
            'domestic-staff' => '5350',
            'medical' => '5600',
            'household' => '5700',
        ];

        $wrong = [];

        foreach ($expected as $code => $accountCode) {
            $type = TransactionType::with('account')->where('code', $code)->first();

            if ($type === null) {
                $wrong[] = "{$code}: no such category";

                continue;
            }

            if ($type->account?->code !== $accountCode) {
                $wrong[] = "{$code} -> {$type->account?->code} {$type->account?->name} (expected {$accountCode})";
            }
        }

        $this->assertSame([], $wrong, implode("\n", [
            'These personal spending categories post to the wrong account, which',
            'means they were seeded against the business chart:',
            '',
            ...$wrong,
        ]));

        // And none may dangle: a category with no account cannot be booked at all
        // (PettyCashService refuses it), so it is a dead option on a form.
        $this->assertSame(
            0,
            TransactionType::whereNull('account_id')->count(),
            'a spending category points at no account',
        );
    }

    public function test_a_personal_account_gets_no_payroll_slabs(): void
    {
        $this->provision(Company::TYPE_PERSONAL);

        // Absent on purpose, not forgotten: a personal account does not run
        // payroll, and the top-up command reports its own gaps per tenant type
        // so this does not read as missing data.
        $this->assertSame(0, \App\Modules\Payroll\Models\SalarySlab::count());
    }

    public function test_the_baseline_lists_hold_only_real_seeders(): void
    {
        // Guards the guard: a typo'd or deleted class in either list would
        // otherwise only surface when somebody next created a company.
        foreach ([TenantBaselineSeeder::seeders(), PersonalBaselineSeeder::seeders()] as $list) {
            $this->assertNotEmpty($list);

            foreach ($list as $seeder) {
                $this->assertTrue(class_exists($seeder), "{$seeder} does not exist");
            }
        }
    }

    public function test_only_the_salary_slab_seeder_is_destructive_to_rerun(): void
    {
        // tenants:seed-baseline promises it only adds. That promise rests on
        // every baseline seeder being firstOrCreate/updateOrCreate, with
        // SalarySlabSeeder the single known exception it filters out. A new
        // seeder that deletes rows would break the promise silently, so the
        // exception list is pinned here.
        $destructive = [];

        $all = array_unique(array_merge(TenantBaselineSeeder::seeders(), PersonalBaselineSeeder::seeders()));

        foreach ($all as $seeder) {
            $source = file_get_contents((new \ReflectionClass($seeder))->getFileName());

            if (preg_match('/->(delete|truncate|forceDelete)\(\)/', $source)) {
                $destructive[] = $seeder;
            }
        }

        $this->assertSame(
            [SalarySlabSeeder::class],
            $destructive,
            'A baseline seeder deletes rows. tenants:seed-baseline only filters SalarySlabSeeder, '
            .'so anything else here will destroy data it claims only to top up.',
        );
    }
}
