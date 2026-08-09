<?php

namespace Tests\Feature;

use App\Modules\Accounting\Filament\Resources\Accounts\Pages\CreateAccount;
use App\Modules\Accounting\Filament\Resources\Accounts\Pages\EditAccount;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Services\JournalEntryService;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Tests\AccountingTestCase;
use Tests\Concerns\InteractsWithTenant;

/**
 * An account that has been posted to must not be given sub-accounts.
 *
 * canAcceptEntries() refuses parents, so the moment a leaf gains a child it stops
 * accepting entries — silently, and only for writes that come later. A 5802
 * "Payroll/Salaries Tax" filed under 5100 Basic Salary Expense is what triggered
 * this: 5100 kept its 52 existing lines and its balance, and every payslip save
 * from then on died on the basic-wage line.
 */
class AccountParentGuardTest extends AccountingTestCase
{
    use InteractsWithTenant;

    private function postedAccount(): Account
    {
        $entry = app(JournalEntryService::class)->create(
            ['entry_date' => '2026-08-01', 'memo' => 'Opening'],
            [
                ['account_id' => Account::where('code', '5100')->firstOrFail()->id, 'debit_amount' => 1000],
                ['account_id' => Account::where('code', '2300')->firstOrFail()->id, 'credit_amount' => 1000],
            ]
        );

        $this->assertCount(2, $entry->lines);

        return Account::where('code', '5100')->firstOrFail();
    }

    public function test_an_account_with_lines_cannot_be_given_a_child(): void
    {
        $parent = $this->postedAccount();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('already has journal entries');

        Account::create([
            'code' => '5101',
            'name' => 'Payroll/Salaries Tax',
            'type' => 'expense',
            'parent_id' => $parent->id,
        ]);
    }

    public function test_an_existing_account_cannot_be_moved_under_a_posted_account(): void
    {
        $parent = $this->postedAccount();

        $child = Account::create([
            'code' => '5101',
            'name' => 'Payroll/Salaries Tax',
            'type' => 'expense',
            'parent_id' => Account::where('code', '5000')->firstOrFail()->id,
        ]);

        try {
            $child->update(['parent_id' => $parent->id]);
            $this->fail('expected the guard to refuse the move');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('5100', $e->getMessage());
        }

        $this->assertSame(
            Account::where('code', '5000')->firstOrFail()->id,
            $child->fresh()->parent_id,
            'the misfiled move left no trace'
        );
        $this->assertTrue($parent->fresh()->canAcceptEntries());
    }

    public function test_a_group_account_with_no_lines_still_accepts_children(): void
    {
        $expenses = Account::where('code', '5000')->firstOrFail();

        $child = Account::create([
            'code' => '5802',
            'name' => 'Payroll/Salaries Tax',
            'type' => 'expense',
            'parent_id' => $expenses->id,
        ]);

        $this->assertSame($expenses->id, $child->parent_id);
        $this->assertTrue($child->canAcceptEntries());
    }

    public function test_editing_a_posted_account_without_touching_its_parent_is_unaffected(): void
    {
        $parent = $this->postedAccount();

        $parent->update(['description' => 'Gross basic salary']);

        $this->assertSame('Gross basic salary', $parent->fresh()->description);
    }

    public function test_the_api_reports_a_field_error_rather_than_an_exception(): void
    {
        $parent = $this->postedAccount();

        Sanctum::actingAs($this->makeUser('Accountant', 'guard-api@test.local'));

        $this->postJson('/api/accounts', [
            'code' => '5101',
            'name' => 'Payroll/Salaries Tax',
            'type' => 'expense',
            'parent_id' => $parent->id,
        ])->assertStatus(422)->assertJsonValidationErrors('parent_id');

        $this->assertSame(0, $parent->fresh()->children()->count());
    }

    public function test_the_parent_options_exclude_accounts_that_have_lines(): void
    {
        $parent = $this->postedAccount();

        $groupable = Account::groupable()->pluck('code');

        $this->assertNotContains($parent->code, $groupable->all());
        $this->assertContains('5000', $groupable->all());
    }

    /**
     * The form is where the misfiling happened, so the narrowed parent list has to
     * survive both pages — the query closure is injected by Filament and a wrong
     * parameter name fails at render, not at save.
     */
    public function test_the_account_form_offers_no_posted_account_as_a_parent(): void
    {
        Gate::before(fn () => true);
        $parent = $this->postedAccount();
        $this->actingAs($this->makeUser('Accountant', 'guard-form@test.local'));
        $this->setCurrentTenant();

        $create = Livewire::test(CreateAccount::class)->assertSuccessful();
        $options = $create->instance()->getSchemaComponent('form.parent_id')->getSearchResults('Expense');

        $this->assertNotContains('Basic Salary Expense', $options, 'a posted account is not offered as a parent');
        $this->assertContains('Medical Allowance Expense', $options, 'an unposted account still is');

        Livewire::test(EditAccount::class, ['record' => $parent->getRouteKey()])
            ->assertSuccessful()
            ->assertFormFieldExists('parent_id');
    }

    public function test_the_guard_names_the_reason_entries_are_refused(): void
    {
        $parent = $this->postedAccount();

        Account::whereKey(
            Account::create([
                'code' => '5101',
                'name' => 'Payroll/Salaries Tax',
                'type' => 'expense',
                'parent_id' => Account::where('code', '5000')->firstOrFail()->id,
            ])->id
        )->update(['parent_id' => $parent->id]);

        $this->assertStringContainsString('sub-accounts', $parent->fresh()->entryRefusalReason());

        $inactive = Account::where('code', '5200')->firstOrFail();
        $inactive->update(['is_active' => false]);
        $this->assertSame('it is inactive', $inactive->fresh()->entryRefusalReason());

        $noManual = Account::where('code', '5300')->firstOrFail();
        $noManual->update(['allow_manual_entry' => false]);
        $this->assertSame('it does not allow manual entry', $noManual->fresh()->entryRefusalReason());

        $this->assertNull(Account::where('code', '5400')->firstOrFail()->entryRefusalReason());
    }
}
