<?php

namespace Tests\Feature;

use App\Modules\Accounting\Filament\Pages\AccountRegister;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\JournalEntry;
use Illuminate\Support\Facades\File;
use Livewire\Livewire;
use Tests\AccountingTestCase;
use Tests\Concerns\InteractsWithTenant;

/**
 * The blank row at the foot of the register — GnuCash's entry line.
 *
 * The dialog still exists and is unchanged. What is new is that the last row of
 * the table is typeable, which is the difference between recording one
 * transaction and recording twenty.
 *
 * The rule all three ways in share — an amount goes in exactly one of Debit or
 * Credit — now lives in one place, so most of what is asserted here is that the
 * row reaches the same service the dialog does and refuses the same things.
 */
class RegisterInlineRowTest extends AccountingTestCase
{
    use InteractsWithTenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs($this->makeUser('Administrator', 'inline@test.local'));
        $this->setCurrentTenant();
    }

    private function register(): Account
    {
        return Account::where('code', '1100')->firstOrFail();
    }

    private function expense(): Account
    {
        return Account::where('code', '5700')->firstOrFail();
    }

    /** @return \Livewire\Features\SupportTesting\Testable */
    private function page()
    {
        return Livewire::test(AccountRegister::class);
    }

    /** @param array<string, mixed> $row */
    private function typeRow(array $row = [])
    {
        return $this->page()->set('newRowData', $row + [
            'date' => '2026-07-10',
            'num' => 'CHQ-1',
            'description' => 'Office rent',
            'transfer_account_id' => $this->expense()->id,
            'debit' => null,
            'credit' => 50_000,
        ]);
    }

    // ── booking ─────────────────────────────────────────────────────────────

    public function test_typing_a_row_and_saving_books_a_posted_entry(): void
    {
        $this->typeRow()->call('saveNewRow')->assertHasNoErrors();

        $entry = JournalEntry::latest('id')->with('lines')->firstOrFail();

        $this->assertSame('Office rent', $entry->memo);
        $this->assertSame('CHQ-1', $entry->reference);
        $this->assertSame('2026-07-10', $entry->entry_date->toDateString());
        $this->assertTrue((bool) $entry->is_posted, 'The register posts immediately — that is what it is for.');

        $own = $entry->lines->firstWhere('account_id', $this->register()->id);
        $other = $entry->lines->firstWhere('account_id', $this->expense()->id);

        $this->assertEqualsWithDelta(50_000, (float) $own->credit_amount, 0.01, 'Credit is money out of the register account.');
        $this->assertEqualsWithDelta(50_000, (float) $other->debit_amount, 0.01);
    }

    public function test_a_debit_is_money_in(): void
    {
        $this->typeRow(['debit' => 25_000, 'credit' => null])->call('saveNewRow')->assertHasNoErrors();

        $entry = JournalEntry::latest('id')->with('lines')->firstOrFail();

        $this->assertEqualsWithDelta(
            25_000,
            (float) $entry->lines->firstWhere('account_id', $this->register()->id)->debit_amount,
            0.01,
        );
    }

    public function test_the_row_clears_itself_ready_for_the_next_one(): void
    {
        // A register is used in runs of twenty, not one at a time.
        $component = $this->typeRow()->call('saveNewRow');

        $component
            ->assertSet('newRowData.description', null)
            ->assertSet('newRowData.credit', null)
            ->assertSet('newRowData.num', null)
            ->assertDispatched('register-row-saved');

        // The date portion, because Filament's picker keeps a time of day in
        // state. What matters is which day it resets to.
        $this->assertSame(
            now()->toDateString(),
            \Illuminate\Support\Carbon::parse($component->get('newRowData.date'))->toDateString(),
        );
    }

    public function test_the_date_resets_to_today_rather_than_inheriting(): void
    {
        // A date that quietly carries over from the last entry is how an
        // afternoon of transactions ends up on one wrong day.
        $component = $this->typeRow(['date' => '2026-07-01'])->call('saveNewRow');

        $this->assertSame(
            now()->toDateString(),
            \Illuminate\Support\Carbon::parse($component->get('newRowData.date'))->toDateString(),
        );
    }

    public function test_the_saved_row_is_marked_so_a_back_dated_one_can_be_found(): void
    {
        // It sorts into the middle of the ledger rather than appearing where it
        // was typed, so without this a correct save looks like nothing happened.
        $component = $this->typeRow(['date' => '2026-07-02'])->call('saveNewRow');

        $component->assertSet('justAdded', JournalEntry::latest('id')->first()->getKey());
    }

    // ── what it refuses ─────────────────────────────────────────────────────

    public function test_an_amount_in_both_columns_is_refused(): void
    {
        $this->typeRow(['debit' => 100, 'credit' => 100])
            ->call('saveNewRow')
            ->assertHasErrors('newRowData.debit');

        $this->assertSame(0, JournalEntry::count());
    }

    public function test_an_amount_in_neither_column_is_refused(): void
    {
        $this->typeRow(['debit' => null, 'credit' => null])
            ->call('saveNewRow')
            ->assertHasErrors('newRowData.debit');

        $this->assertSame(0, JournalEntry::count());
    }

    public function test_a_description_is_required(): void
    {
        $this->typeRow(['description' => null])
            ->call('saveNewRow')
            ->assertHasErrors('newRowData.description');

        $this->assertSame(0, JournalEntry::count());
    }

    public function test_a_transfer_account_is_required(): void
    {
        $this->typeRow(['transfer_account_id' => null])
            ->call('saveNewRow')
            ->assertHasErrors('newRowData.transfer_account_id');
    }

    public function test_an_account_outside_the_offered_list_is_refused(): void
    {
        // Not merely "required": the list is scoped to what this register may post
        // against, and a stale id from a switched account would otherwise reach
        // the service and fail there with a worse message.
        $this->typeRow(['transfer_account_id' => $this->register()->id])
            ->call('saveNewRow')
            ->assertHasErrors('newRowData.transfer_account_id');

        $this->assertSame(0, JournalEntry::count());
    }

    public function test_errors_keep_what_was_typed_on_screen(): void
    {
        // The reason errors are on the fields rather than in a toast: the row is
        // still there, so the screen can point at the box that is wrong.
        $this->typeRow(['debit' => 100, 'credit' => 100])
            ->call('saveNewRow')
            ->assertSet('newRowData.description', 'Office rent')
            ->assertSet('newRowData.debit', 100);
    }

    // ── access ──────────────────────────────────────────────────────────────

    public function test_an_accountant_can_type_in_the_row(): void
    {
        $this->actingAs($this->makeUser('Accountant', 'acct@inline.test'));

        $this->assertTrue($this->page()->instance()->canAddInline());
    }

    public function test_an_employee_cannot_reach_the_register_at_all(): void
    {
        $this->actingAs($this->makeUser('Employee', 'emp@inline.test'));

        $this->assertFalse(AccountRegister::canAccess());
    }

    /**
     * Somebody who may read the ledger but not post to it.
     *
     * No seeded role is in this position — every role that can open the register
     * can also write to it — so the role is built here. It is the case the two
     * separate permissions exist for, and without it the gate on saveNewRow()
     * would never be exercised by anything.
     */
    private function actAsReader(string $email): void
    {
        $role = \Spatie\Permission\Models\Role::firstOrCreate([
            'name' => 'Ledger Reader',
            'company_id' => \Filament\Facades\Filament::getTenant()?->getKey(),
        ]);
        $role->syncPermissions(['JournalEntryView']);

        $this->actingAs($this->makeUser('Ledger Reader', $email));
    }

    public function test_a_reader_opens_the_register_but_gets_no_row(): void
    {
        $this->actAsReader('reader@inline.test');

        $this->assertTrue(AccountRegister::canAccess());
        $this->assertFalse($this->page()->instance()->canAddInline());
        $this->page()->assertDontSee('Enter books it and clears the line');
    }

    public function test_a_reader_cannot_post_through_it_anyway(): void
    {
        // The gate is on the method, not only on the markup — a hidden row is a
        // hidden button, not a closed door.
        $this->actAsReader('reader2@inline.test');

        $this->typeRow()->call('saveNewRow')->assertForbidden();

        $this->assertSame(0, JournalEntry::count());
    }

    // ── the dialog is untouched ─────────────────────────────────────────────

    public function test_the_add_transaction_dialog_still_works(): void
    {
        $this->page()
            ->callAction('addTransaction', [
                'date' => '2026-07-11',
                'num' => 'CHQ-9',
                'description' => 'Via the dialog',
                'transfer_account_id' => $this->expense()->id,
                'debit' => null,
                'credit' => 1_500,
            ])
            ->assertHasNoActionErrors();

        $this->assertSame('Via the dialog', JournalEntry::latest('id')->first()->memo);
    }

    public function test_the_dialog_and_the_row_refuse_the_same_thing(): void
    {
        // They now share sideAndAmount(), which is the point: the one rule a
        // register has cannot come to mean different things on two screens.
        foreach ([[100, 100], [null, null], [0, 0]] as [$debit, $credit]) {
            $this->expectNotToPerformAssertions();

            try {
                AccountRegister::sideAndAmount($debit, $credit);
                $this->fail('sideAndAmount accepted '.json_encode([$debit, $credit]));
            } catch (\InvalidArgumentException) {
                // expected
            }
        }
    }

    public function test_the_shared_rule_reads_the_side_correctly(): void
    {
        $this->assertSame(
            ['direction' => 'in', 'amount' => 500.0],
            AccountRegister::sideAndAmount(500, null),
        );

        $this->assertSame(
            ['direction' => 'out', 'amount' => 750.0],
            AccountRegister::sideAndAmount(null, 750),
        );
    }

    // ── the reason this is a Filament field ─────────────────────────────────

    public function test_the_transfer_account_is_searchable(): void
    {
        // The bug that moved this out of the table. A native <select> only
        // type-jumps on the start of a label, and every label here begins with
        // the account type — "Expense:5700 Rent Expense" — so typing "rent"
        // matched nothing and 43 accounts had to be scrolled.
        $field = collect($this->page()->instance()->newRowForm->getFlatComponents())
            ->first(fn ($component): bool => $component instanceof \Filament\Forms\Components\Select
                && $component->getName() === 'transfer_account_id');

        $this->assertNotNull($field, 'The transfer field is not in the strip.');
        $this->assertTrue($field->isSearchable(), 'Turning search off here makes the account unfindable by name.');
    }

    public function test_an_account_can_be_found_by_its_name_not_only_its_type(): void
    {
        // The labels are what search runs against, so the name has to be in
        // them. It is the second half of the fix and would survive a rename of
        // the field without this.
        $labels = $this->page()->instance()->transferOptions()->pluck('label');

        $this->assertTrue(
            $labels->contains(fn (string $label): bool => str_contains(strtolower($label), 'rent')),
            'No option mentions "rent", so no search for it can succeed.',
        );
    }

    // ── the styling that has broken before ──────────────────────────────────

    public function test_the_row_tints_are_actually_compiled(): void
    {
        // dark:even:bg-white/[0.02] was the first choice for the stripe and
        // Tailwind emits nothing at all for it, so the stripe would simply not
        // exist in dark mode. Same shape as the report bars, and invisible to
        // every other test.
        $css = collect(File::glob(public_path('build/assets/*.css')))
            ->map(fn (string $path): string => File::get($path))
            ->implode("\n");

        foreach ([
            'bg-warning-50',            // the entry row
            'dark:bg-warning-500/10',
            'bg-primary-50',            // the row just added
            'dark:bg-primary-500/10',
            'even:bg-gray-50',          // striping
            'dark:even:bg-white/5',
        ] as $class) {
            $this->assertStringContainsString(
                str_replace([':', '/'], ['\:', '\/'], $class),
                $css,
                "[{$class}] styles the register but is not in the built CSS.",
            );
        }
    }
}
