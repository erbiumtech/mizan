<?php

namespace Tests\Feature;

use App\Filament\Pages\AccountRegister;
use App\Models\Account;
use App\Services\RegisterEntryService;
use Livewire\Livewire;
use Tests\AccountingTestCase;
use Tests\Concerns\InteractsWithTenant;

/**
 * The register page's row actions, driven through Livewire against real roles.
 */
class RegisterPageActionsTest extends AccountingTestCase
{
    use InteractsWithTenant;

    private function actingAsRole(string $role, string $email)
    {
        $user = $this->makeUser($role, $email);
        $this->actingAs($user);
        $this->setCurrentTenant();

        return $user;
    }

    private function bookRow(float $amount = 1000)
    {
        return app(RegisterEntryService::class)->bookRow(
            Account::where('code', '1100')->firstOrFail(),
            Account::where('code', '5700')->firstOrFail(),
            ['date' => now()->toDateString(), 'description' => 'Rent', 'direction' => 'out', 'amount' => $amount],
        );
    }

    public function test_a_manager_can_edit_a_row_through_the_page(): void
    {
        $this->actingAsRole('Manager', 'mgr-reg@test.local');
        $entry = $this->bookRow(1000);

        Livewire::test(AccountRegister::class)
            ->callAction('editRow', arguments: ['entry' => $entry->id], data: [
                'date' => now()->toDateString(),
                'description' => 'Rent — corrected',
                'transfer_account_id' => Account::where('code', '5700')->value('id'),
                'credit' => 1250,
            ])
            ->assertHasNoActionErrors();

        $entry->refresh();
        $this->assertSame('Rent — corrected', $entry->memo);
        $this->assertSame(1250.0, (float) $entry->lines()->where('credit_amount', '>', 0)->value('credit_amount'));
    }

    public function test_an_administrator_can_delete_a_row_through_the_page(): void
    {
        // Deleting a ledger row is Administrator-only; everyone else reverses.
        $this->actingAsRole('Administrator', 'admin-del-reg@test.local');
        $entry = $this->bookRow(1000);

        Livewire::test(AccountRegister::class)
            ->callAction('deleteRow', arguments: ['entry' => $entry->id])
            ->assertHasNoActionErrors();

        $this->assertDatabaseMissing('journal_entries', ['id' => $entry->id]);
    }

    public function test_entering_both_debit_and_credit_is_refused(): void
    {
        $this->actingAsRole('Manager', 'mgr-both@test.local');
        $entry = $this->bookRow(1000);

        Livewire::test(AccountRegister::class)
            ->callAction('editRow', arguments: ['entry' => $entry->id], data: [
                'date' => now()->toDateString(),
                'description' => 'Both columns',
                'transfer_account_id' => Account::where('code', '5700')->value('id'),
                'debit' => 100,
                'credit' => 100,
            ]);

        // Rejected before the service is reached: the row is unchanged.
        $this->assertSame('Rent', $entry->refresh()->memo);
    }

    public function test_an_accountant_may_edit_but_not_delete_or_reverse(): void
    {
        $accountant = $this->actingAsRole('Accountant', 'acct-reg@test.local');

        $this->assertTrue($accountant->can('JournalEntryUpdate'));
        $this->assertFalse($accountant->can('JournalEntryDelete'));
        $this->assertFalse($accountant->can('JournalEntryReverse'));

        $component = Livewire::test(AccountRegister::class);
        $component->assertActionVisible('editRow');
        $component->assertActionHidden('deleteRow');
        $component->assertActionHidden('reverseRow');
    }

    public function test_a_manager_can_reverse_but_not_delete(): void
    {
        $manager = $this->actingAsRole('Manager', 'mgr-rev-reg@test.local');

        $this->assertTrue($manager->can('JournalEntryReverse'));
        $this->assertFalse($manager->can('JournalEntryDelete'));

        $component = Livewire::test(AccountRegister::class);
        $component->assertActionVisible('reverseRow');
        $component->assertActionHidden('deleteRow');
    }

    /**
     * Deleting a posted ledger row is Administrator-only, by decision: a CEO has
     * every other deletion right but corrects the books by reversing.
     */
    public function test_a_ceo_can_reverse_but_not_delete(): void
    {
        $ceo = $this->actingAsRole('CEO', 'ceo-reg@test.local');

        $this->assertTrue($ceo->can('AccountDelete'), 'CEO keeps its other deletion rights');
        $this->assertFalse($ceo->can('JournalEntryDelete'));

        $component = Livewire::test(AccountRegister::class);
        $component->assertActionVisible('reverseRow');
        $component->assertActionHidden('deleteRow');
    }

    public function test_reversing_a_row_books_a_mirrored_entry(): void
    {
        $this->actingAsRole('Manager', 'mgr-rev2@test.local');
        $entry = $this->bookRow(1000);
        $bank = Account::where('code', '1100')->firstOrFail();
        $balanceBefore = (float) $bank->refresh()->balance;

        Livewire::test(AccountRegister::class)
            ->callAction('reverseRow', arguments: ['entry' => $entry->id])
            ->assertHasNoActionErrors();

        // Original stays; the reversal restores the balance.
        $this->assertDatabaseHas('journal_entries', ['id' => $entry->id]);
        $this->assertSame(round($balanceBefore + 1000, 2), round((float) $bank->refresh()->balance, 2));
    }

    public function test_an_employee_sees_no_row_actions(): void
    {
        $this->actingAsRole('Employee', 'emp-reg@test.local');

        // The page itself requires JournalEntryView.
        $this->assertFalse(AccountRegister::canAccess());
    }
}
