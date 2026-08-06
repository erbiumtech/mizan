<?php

namespace Tests\Feature;

use App\Modules\Core\Models\Company;
use App\Modules\Core\Models\FiscalYear;
use App\Modules\Core\Models\User;
use App\Modules\PersonalFinance\Filament\Pages\IncomeAndExpenditure;
use App\Modules\PersonalFinance\Filament\Pages\PersonalBalanceSheet;
use App\Modules\PersonalFinance\Filament\Pages\TaxEstimate;
use App\Modules\PersonalFinance\Filament\Resources\PersonalAccounts\Pages\ListPersonalAccounts;
use App\Modules\PersonalFinance\Filament\Resources\PersonalEntries\Pages\ListPersonalEntries;
use App\Modules\PersonalFinance\Models\PersonalAccount;
use App\Modules\PersonalFinance\Services\PersonalEntryService;
use App\Modules\PersonalFinance\Services\StarterChart;
use Database\Seeders\FiscalYearSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\TaxScheduleSeeder;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\InteractsWithTenant;
use Tests\TestCase;

/**
 * The screens: that they render for an ordinary employee, that the everyday
 * actions work end to end, and that the numbers shown are the ones recorded.
 */
class PersonalFinancePagesTest extends TestCase
{
    use InteractsWithTenant;
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $company = Company::factory()->create();
        $this->seed([PermissionSeeder::class, FiscalYearSeeder::class]);
        app(PermissionRegistrar::class)->setPermissionsTeamId($company->getKey());
        (new RoleSeeder)->run();

        // An Employee on purpose: this module is for everybody, so the lowest
        // seeded role has to be able to use all of it.
        $this->user = User::factory()->create(['status' => 1]);
        $company->users()->attach($this->user->getKey());
        $this->user->assignRole('Employee');

        $this->actingAs($this->user);
        $this->setCurrentTenant($company);

        $this->seed(TaxScheduleSeeder::class);
    }

    public function test_an_employee_can_reach_every_screen(): void
    {
        foreach ([PersonalBalanceSheet::class, IncomeAndExpenditure::class, TaxEstimate::class] as $page) {
            $this->assertTrue($page::canAccess(), $page.' was denied to an Employee.');
        }
    }

    public function test_every_screen_renders(): void
    {
        Livewire::test(ListPersonalAccounts::class)->assertSuccessful();
        Livewire::test(ListPersonalEntries::class)->assertSuccessful();
        Livewire::test(PersonalBalanceSheet::class)->assertSuccessful();
        Livewire::test(IncomeAndExpenditure::class)->assertSuccessful();
        Livewire::test(TaxEstimate::class)->assertSuccessful();
    }

    public function test_the_starter_chart_action_is_offered_only_while_there_is_nothing(): void
    {
        Livewire::test(ListPersonalAccounts::class)
            ->assertActionVisible(TestAction::make('starterChart'));

        app(StarterChart::class)->createFor();

        // Once somebody has a chart, a button that adds fifteen accounts is a
        // nuisance rather than a help.
        Livewire::test(ListPersonalAccounts::class)
            ->assertActionHidden(TestAction::make('starterChart'));
    }

    public function test_the_starter_chart_includes_education_and_is_safe_to_repeat(): void
    {
        $created = app(StarterChart::class)->createFor();
        $this->assertGreaterThan(0, $created);

        $this->assertTrue(
            PersonalAccount::where('name', 'Education')->exists(),
            'Education was asked for by name and is missing from the starter chart.',
        );

        // Salary arrives already tagged, so the tax estimate works for the
        // commonest case without anybody configuring anything.
        $this->assertSame(
            'salaried',
            PersonalAccount::where('code', '4000')->first()?->tax_regime,
        );

        $this->assertSame(0, app(StarterChart::class)->createFor(), 'Running it twice duplicated accounts.');
    }

    public function test_recording_an_expense_through_the_screen_updates_the_balance_sheet(): void
    {
        app(StarterChart::class)->createFor();

        $cash = PersonalAccount::where('code', '1000')->firstOrFail();
        $cash->update(['opening_balance' => 100000]);
        $education = PersonalAccount::where('code', '5300')->firstOrFail();

        Livewire::test(ListPersonalEntries::class)
            ->callAction(TestAction::make('recordExpense'), [
                'date' => now()->toDateString(),
                'amount' => 25000,
                'category_id' => $education->id,
                'from_id' => $cash->id,
                'description' => 'University fees',
            ]);

        $report = Livewire::test(PersonalBalanceSheet::class)->instance()->getReport();

        $this->assertSame(75000.0, $report['total_assets']);
        $this->assertSame(75000.0, $report['net_worth']);
    }

    public function test_the_income_and_expenditure_screen_answers_what_education_cost(): void
    {
        app(StarterChart::class)->createFor();

        $bank = PersonalAccount::where('code', '1100')->firstOrFail();
        $education = PersonalAccount::where('code', '5300')->firstOrFail();
        $food = PersonalAccount::where('code', '5100')->firstOrFail();

        $entries = app(PersonalEntryService::class);
        $entries->recordExpense($education, $bank, 40000, ['date' => '2025-09-10', 'description' => 'Fees']);
        $entries->recordExpense($education, $bank, 15000, ['date' => '2025-11-02', 'description' => 'Books']);
        $entries->recordExpense($food, $bank, 9000, ['date' => '2025-11-03', 'description' => 'Groceries']);

        $year = FiscalYear::where('name', '2025-2026')->firstOrFail();

        $page = Livewire::test(IncomeAndExpenditure::class);
        $page->set('data.fiscal_year_id', $year->id);

        $report = $page->instance()->getReport();

        $educationRow = collect($report['expenses'])->firstWhere('name', 'Education');

        $this->assertSame(55000.0, $educationRow['amount'], 'Education spending for the year is wrong.');
        $this->assertSame(64000.0, $report['total_expenses']);
    }

    public function test_the_tax_estimate_reports_a_missing_schedule_instead_of_zero(): void
    {
        // 2026-2027 has no schedule seeded. Answering "you owe nothing" would be
        // the payroll bug all over again.
        $other = FiscalYear::where('name', '2026-2027')->firstOrFail();

        app(StarterChart::class)->createFor();
        $bank = PersonalAccount::where('code', '1100')->firstOrFail();
        $salary = PersonalAccount::where('code', '4000')->firstOrFail();

        app(PersonalEntryService::class)->recordIncome($bank, $salary, 3000000, [
            'date' => $other->start_date->copy()->addMonth()->toDateString(),
            'description' => 'Salary',
        ]);

        $page = Livewire::test(TaxEstimate::class);
        $page->set('data.fiscal_year_id', $other->id);

        $result = $page->instance()->getEstimate();

        $this->assertNull($result['estimate']);
        $this->assertNotNull($result['error']);
        $this->assertStringContainsString('No Salaried tax bracket covers', $result['error']);
    }
}
