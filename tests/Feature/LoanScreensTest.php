<?php

namespace Tests\Feature;

use App\Modules\Accounting\Filament\Resources\Loans\LoanResource;
use App\Modules\Accounting\Filament\Resources\Loans\Pages\CreateLoan;
use App\Modules\Accounting\Filament\Resources\Loans\Pages\EditLoan;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\Loan;
use App\Modules\Accounting\Services\LoanService;
use Livewire\Livewire;
use Tests\AccountingTestCase;
use Tests\Concerns\InteractsWithTenant;

/**
 * The loan screens, and the two rules that only exist at this layer: a schedule
 * appears the moment a loan is saved, and the form closes as soon as the ledger
 * knows about the loan.
 */
class LoanScreensTest extends AccountingTestCase
{
    use InteractsWithTenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs($this->makeUser('Administrator', 'loanui@test.local'));
        $this->setCurrentTenant();
    }

    private function account(string $code): int
    {
        return Account::where('code', $code)->firstOrFail()->id;
    }

    /** @return array<string, mixed> */
    private function formData(array $overrides = []): array
    {
        return $overrides + [
            'name' => 'Vehicle finance',
            'principal' => 1_200_000,
            'annual_rate' => 12,
            'term_months' => 12,
            'starts_on' => '2026-07-05',
            'is_active' => true,
            'liability_account_id' => $this->account('2100'),
            'interest_account_id' => $this->account('5900'),
            'payment_account_id' => $this->account('1100'),
        ];
    }

    private function loan(array $overrides = []): Loan
    {
        $loan = Loan::create($this->formData($overrides));
        app(LoanService::class)->generateSchedule($loan);

        return $loan->fresh();
    }

    public function test_saving_a_loan_produces_its_schedule(): void
    {
        // An empty Schedule tab on a loan somebody has just set up reads as "this
        // feature does not work" rather than "press something".
        Livewire::test(CreateLoan::class)
            ->fillForm($this->formData())
            ->call('create')
            ->assertHasNoFormErrors();

        $loan = Loan::where('name', 'Vehicle finance')->firstOrFail();

        $this->assertSame(12, $loan->instalments()->count());

        // reorder(), not orderByDesc(): the relation already sorts ascending and
        // a second ORDER BY appended to it changes nothing — the same trap the
        // model's scheduledOutstanding() had.
        $this->assertEqualsWithDelta(
            0.0,
            (float) $loan->instalments()->reorder('number', 'desc')->first()->closing_balance,
            0.01,
        );
    }

    public function test_the_form_previews_the_monthly_figure_before_saving(): void
    {
        // The number somebody is deciding on. Being told it only after committing
        // to the loan is the wrong order.
        Livewire::test(CreateLoan::class)
            ->fillForm($this->formData())
            ->assertSee('106,618.55');
    }

    public function test_changing_the_terms_rebuilds_the_schedule(): void
    {
        $loan = $this->loan();

        Livewire::test(EditLoan::class, ['record' => $loan->getKey()])
            ->fillForm(['term_months' => 24])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(24, $loan->fresh()->instalments()->count());
    }

    public function test_the_form_closes_once_an_instalment_is_recorded(): void
    {
        // As a CEO, not an Administrator. Gate::before lets an Administrator past
        // every ability but create, so asserting this as one would prove the
        // bypass works rather than that the rule does.
        $loan = $this->loan();
        app(LoanService::class)->recordInstalment($loan->instalments()->first());

        $this->actingAs($this->makeUser('CEO', 'ceo@loan.test'));

        $this->assertFalse(
            LoanResource::canEdit($loan->fresh()),
            'Editing would rebuild a schedule half of which is already in the ledger.',
        );
        $this->assertFalse(LoanResource::canDelete($loan->fresh()));
    }

    public function test_an_untouched_loan_can_still_be_edited_and_deleted(): void
    {
        $loan = $this->loan();

        $this->actingAs($this->makeUser('CEO', 'ceo2@loan.test'));

        $this->assertTrue(LoanResource::canEdit($loan));
        $this->assertTrue(LoanResource::canDelete($loan));
    }

    public function test_an_accountant_sets_loans_up_but_does_not_record_them(): void
    {
        // Recording writes to the ledger, so it sits with the approval powers.
        $this->actingAs($this->makeUser('Accountant', 'acct@loan.test'));
        $loan = $this->loan();

        $this->assertTrue(LoanResource::canCreate());
        $this->assertTrue(LoanResource::canEdit($loan));
        $this->assertFalse(auth()->user()->can('record', $loan));
    }

    public function test_a_manager_can_record(): void
    {
        $this->actingAs($this->makeUser('Manager', 'mgr@loan.test'));

        $this->assertTrue(auth()->user()->can('record', $this->loan()));
    }

    public function test_an_employee_sees_no_loans(): void
    {
        $this->actingAs($this->makeUser('Employee', 'emp@loan.test'));

        $this->assertFalse(LoanResource::canViewAny());
    }
}
