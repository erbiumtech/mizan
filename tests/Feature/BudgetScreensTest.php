<?php

namespace Tests\Feature;

use App\Modules\Accounting\Filament\Pages\BudgetVsActual;
use App\Modules\Accounting\Filament\Resources\Budgets\Pages\CreateBudget;
use App\Modules\Accounting\Filament\Resources\Budgets\Pages\EditBudget;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\Budget;
use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Accounting\Services\BudgetService;
use App\Modules\Accounting\Services\JournalEntryService;
use Livewire\Livewire;
use Tests\AccountingTestCase;
use Tests\Concerns\InteractsWithTenant;

/**
 * The screens, not the arithmetic — BudgetTest has the arithmetic.
 *
 * What is proved here is the join between them, which is the part the service
 * tests cannot see: the form's yearly figure is a shape no column holds, so it
 * is unset before the save and written afterwards. Get that wrong and the budget
 * saves with no plan at all, silently, while every service test still passes.
 */
class BudgetScreensTest extends AccountingTestCase
{
    use InteractsWithTenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs($this->makeUser('Administrator', 'budget-ui@test.local'));
        $this->setCurrentTenant();
    }

    private function account(string $code): Account
    {
        return Account::where('code', $code)->firstOrFail();
    }

    public function test_creating_a_budget_writes_the_monthly_plan(): void
    {
        Livewire::test(CreateBudget::class)
            ->fillForm([
                'name' => '2026-2027',
                'fiscal_year_id' => $this->fiscalYear->getKey(),
                'is_active' => true,
                'plan' => [
                    ['account_id' => $this->account('5700')->id, 'amount' => 120_000],
                    ['account_id' => $this->account('4100')->id, 'amount' => 1_200_000],
                ],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $budget = Budget::where('name', '2026-2027')->firstOrFail();

        $this->assertSame(24, $budget->lines()->count(), 'Two accounts over twelve months.');
        $this->assertSame(120_000.0, $budget->annualFor($this->account('5700')->id));
        $this->assertSame(1_200_000.0, $budget->annualFor($this->account('4100')->id));
    }

    public function test_the_edit_form_shows_the_year_rather_than_the_months(): void
    {
        $budget = Budget::create(['fiscal_year_id' => $this->fiscalYear->getKey(), 'name' => 'Plan']);
        app(BudgetService::class)->setAnnual($budget, $this->account('5700')->id, 120_000);

        Livewire::test(EditBudget::class, ['record' => $budget->getKey()])
            ->assertFormSet(fn (array $state): bool => collect($state['plan'])->contains(
                fn (array $row): bool => (int) $row['account_id'] === $this->account('5700')->id
                    && abs((float) $row['amount'] - 120_000) < 0.005,
            ));
    }

    public function test_editing_only_the_name_does_not_touch_the_plan(): void
    {
        // The whole reason setAnnual() is a no-op on an unchanged total. Through
        // the real form, because that is where the regression would land.
        $budget = Budget::create(['fiscal_year_id' => $this->fiscalYear->getKey(), 'name' => 'Plan']);
        $rent = $this->account('5700')->id;

        app(BudgetService::class)->setAnnual($budget, $rent, 120_000);
        $budget->lines()->where('period_start', '2026-12-01')->update(['amount' => 30_000]);
        $budget->lines()->where('period_start', '2026-07-01')->update(['amount' => 0]);

        Livewire::test(EditBudget::class, ['record' => $budget->getKey()])
            ->fillForm(['name' => 'Plan renamed'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertEqualsWithDelta(
            30_000,
            (float) $budget->lines()->where('period_start', '2026-12-01')->firstOrFail()->amount,
            0.001,
            'Renaming a budget re-spread its months and destroyed the adjustments.',
        );
    }

    public function test_the_report_renders_with_a_budget_and_actuals(): void
    {
        $budget = Budget::create(['fiscal_year_id' => $this->fiscalYear->getKey(), 'name' => 'Plan']);
        app(BudgetService::class)->setAnnual($budget, $this->account('5700')->id, 120_000);

        $entries = app(JournalEntryService::class);
        $entry = $entries->create(
            ['entry_date' => '2026-07-10', 'entry_type' => 'general', 'memo' => 'Rent'],
            [
                ['account_id' => $this->account('5700')->id, 'debit_amount' => 8_000],
                ['account_id' => $this->account('1100')->id, 'credit_amount' => 8_000],
            ],
        );
        $entry->update(['status' => JournalEntry::STATUS_APPROVED, 'approved_at' => now()]);
        $entries->post($entry);

        Livewire::test(BudgetVsActual::class)
            ->assertSuccessful()
            ->assertSee('Rent Expense')
            ->assertSee('8,000.00');
    }

    public function test_the_report_says_so_when_there_is_no_budget_at_all(): void
    {
        // Rendering an empty table would read as "nothing was spent".
        Livewire::test(BudgetVsActual::class)
            ->assertSuccessful()
            ->assertSee('No budget selected');
    }

    public function test_the_report_defaults_to_the_open_year(): void
    {
        $old = \App\Modules\Core\Models\FiscalYear::where('name', '2025-2026')->firstOrFail();

        Budget::create(['fiscal_year_id' => $old->getKey(), 'name' => 'Last year']);
        $current = Budget::create(['fiscal_year_id' => $this->fiscalYear->getKey(), 'name' => 'This year']);

        Livewire::test(BudgetVsActual::class)
            ->assertFormSet(['budget_id' => $current->getKey()]);
    }

    public function test_an_inactive_budget_is_not_offered(): void
    {
        Budget::create([
            'fiscal_year_id' => $this->fiscalYear->getKey(),
            'name' => 'Superseded',
            'is_active' => false,
        ]);

        Livewire::test(BudgetVsActual::class)
            ->assertSuccessful()
            ->assertSee('No budget selected');
    }

    public function test_two_budgets_cannot_share_a_name_within_one_year(): void
    {
        Budget::create(['fiscal_year_id' => $this->fiscalYear->getKey(), 'name' => '2026-2027']);

        Livewire::test(CreateBudget::class)
            ->fillForm([
                'name' => '2026-2027',
                'fiscal_year_id' => $this->fiscalYear->getKey(),
            ])
            ->call('create')
            ->assertHasFormErrors(['name']);
    }
}
