<?php

namespace Tests\Feature;

use App\Modules\Accounting\Filament\Resources\JournalEntries\Pages\CreateJournalEntry;
use App\Modules\Accounting\Filament\Resources\JournalEntries\Pages\EditJournalEntry;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Accounting\Services\JournalEntryService;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Tests\AccountingTestCase;
use Tests\Concerns\InteractsWithTenant;

/**
 * The Journal Entry form used to carry a raw, directly-editable Status field
 * alongside the workflow buttons. Anyone who could edit a Draft (i.e. anyone
 * with JournalEntryUpdate) could set Status straight to Posted through that
 * field — skipping submitForApproval()/approve()/post() entirely: no
 * segregation-of-duty check, and no account-balance update, since only
 * JournalEntryService::post() applies that. This is the regression guard.
 */
class JournalEntryStatusBypassTest extends AccountingTestCase
{
    use InteractsWithTenant;

    protected function setUp(): void
    {
        parent::setUp();

        // The form/resource tests below need an authorized-everything session;
        // the point under test is what the *form* offers, not who may reach it.
        Gate::before(fn () => true);
    }

    private function draftEntry(): JournalEntry
    {
        $entry = app(JournalEntryService::class)->create(
            ['entry_date' => '2026-08-01', 'memo' => 'Opening'],
            [
                ['account_id' => Account::where('code', '5100')->firstOrFail()->id, 'debit_amount' => 1000],
                ['account_id' => Account::where('code', '2300')->firstOrFail()->id, 'credit_amount' => 1000],
            ]
        );

        $this->assertSame(JournalEntry::STATUS_DRAFT, $entry->status);

        return $entry;
    }

    public function test_the_create_form_has_no_status_field(): void
    {
        $this->actingAs($this->makeUser('Accountant', 'create-form@test.local'));
        $this->setCurrentTenant();

        Livewire::test(CreateJournalEntry::class)
            ->assertSuccessful()
            ->assertFormFieldDoesNotExist('status');
    }

    public function test_the_edit_form_has_no_status_field(): void
    {
        $this->actingAs($this->makeUser('Accountant', 'edit-form@test.local'));
        $this->setCurrentTenant();
        $entry = $this->draftEntry();

        Livewire::test(EditJournalEntry::class, ['record' => $entry->getRouteKey()])
            ->assertSuccessful()
            ->assertFormFieldDoesNotExist('status');
    }

    public function test_a_new_entry_created_through_the_form_still_lands_as_draft(): void
    {
        $this->actingAs($this->makeUser('Accountant', 'create-lands-draft@test.local'));
        $this->setCurrentTenant();

        Livewire::test(CreateJournalEntry::class)
            ->fillForm([
                'entry_date' => '2026-08-01',
                'memo' => 'Opening float',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $entry = JournalEntry::where('memo', 'Opening float')->firstOrFail();
        $this->assertSame(JournalEntry::STATUS_DRAFT, $entry->status);
    }

    /**
     * The exact bypass this closes: without a Status component in the schema,
     * there is no `data.status` path for a request — tampered or not — to write
     * through. Filament's own CanBeDisabled trait warns that disabling a field
     * only stops *legitimate* submissions; a field that was never in the schema
     * has no client-side state to manipulate in the first place.
     */
    public function test_editing_an_entry_cannot_move_its_status_through_the_form(): void
    {
        $this->actingAs($this->makeUser('Accountant', 'edit-tamper@test.local'));
        $this->setCurrentTenant();
        $entry = $this->draftEntry();

        Livewire::test(EditJournalEntry::class, ['record' => $entry->getRouteKey()])
            ->fillForm(['memo' => 'Opening (corrected)', 'status' => 'posted'])
            ->call('save')
            ->assertHasNoFormErrors();

        $entry->refresh();
        $this->assertSame(JournalEntry::STATUS_DRAFT, $entry->status);
        $this->assertSame('Opening (corrected)', $entry->memo);
        $this->assertFalse($entry->is_posted);

        $debitAccount = Account::where('code', '5100')->firstOrFail();
        $this->assertSame('0.00', $debitAccount->balance, 'balance moves only through post(), which never ran');
    }

    public function test_posting_still_requires_going_through_the_workflow(): void
    {
        $this->actingAs($this->makeUser('Manager', 'workflow@test.local'));
        $this->setCurrentTenant();
        $entry = $this->draftEntry();

        $service = app(JournalEntryService::class);
        $service->submitForApproval($entry);

        // Approved by someone other than the creator — segregation of duties,
        // enforced regardless of how the entry reached Pending Approval.
        $approver = $this->makeUser('Manager', 'approver@test.local');
        $service->approve($entry->refresh(), $approver);
        $service->post($entry->refresh());

        $this->assertSame(JournalEntry::STATUS_POSTED, $entry->fresh()->status);
        $this->assertSame('1000.00', Account::where('code', '5100')->firstOrFail()->balance);
    }
}
