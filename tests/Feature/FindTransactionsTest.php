<?php

namespace Tests\Feature;

use App\Modules\Accounting\Filament\Pages\FindTransactions;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Accounting\Models\JournalEntryLine;
use App\Modules\Accounting\Services\JournalEntryService;
use Livewire\Livewire;
use Tests\AccountingTestCase;
use Tests\Concerns\InteractsWithTenant;

/**
 * Searching the ledger.
 *
 * The filter semantics are the whole feature, and two of them are the kind that
 * look right and are not: an "at most" written as an OR matches every line in
 * the book, because the unused side of every line is zero; and a status filter
 * left open puts drafts into a column total that reads as the books.
 */
class FindTransactionsTest extends AccountingTestCase
{
    use InteractsWithTenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs($this->makeUser('Administrator', 'finder@test.local'));
        $this->setCurrentTenant();
    }

    private function account(string $code): Account
    {
        return Account::where('code', $code)->firstOrFail();
    }

    private function entry(string $date, string $code, float $amount, bool $post = true, ?string $memo = null): JournalEntry
    {
        $entries = app(JournalEntryService::class);

        $entry = $entries->create(
            ['entry_date' => $date, 'entry_type' => 'general', 'memo' => $memo ?? 'Test'],
            [
                ['account_id' => $this->account($code)->id, 'debit_amount' => $amount],
                ['account_id' => $this->account('1100')->id, 'credit_amount' => $amount],
            ],
        );

        if ($post) {
            $entry->update(['status' => JournalEntry::STATUS_APPROVED, 'approved_at' => now()]);
            $entries->post($entry);
        }

        return $entry->fresh();
    }

    /** The lines the table would show for the given filter state. */
    private function found(array $filters = []): \Illuminate\Support\Collection
    {
        $component = Livewire::test(FindTransactions::class);

        foreach ($filters as $key => $value) {
            $component->set("tableFilters.{$key}", $value);
        }

        return $component->instance()->getFilteredTableQuery()->get();
    }

    public function test_it_finds_postings_and_not_entries(): void
    {
        // One entry, two sides: rent and cash. Both are postings and both are
        // findable, which is the grain the search is meant to work at.
        $this->entry('2026-07-10', '5700', 50_000);

        $this->assertCount(2, $this->found());
    }

    public function test_it_filters_to_the_chosen_accounts(): void
    {
        $this->entry('2026-07-10', '5700', 50_000);
        $this->entry('2026-07-11', '5750', 9_000);

        $found = $this->found(['account_id' => ['values' => [$this->account('5700')->id]]]);

        $this->assertCount(1, $found);
        $this->assertSame($this->account('5700')->id, $found->first()->account_id);
    }

    public function test_at_least_matches_either_side(): void
    {
        $this->entry('2026-07-10', '5700', 80_000);
        $this->entry('2026-07-11', '5750', 9_000);

        // Both sides of the 80,000 entry qualify; neither side of the 9,000 does.
        $this->assertCount(2, $this->found(['amount' => ['min' => 50_000]]));
    }

    public function test_at_most_means_neither_side_exceeds_it(): void
    {
        // The trap. Written as an OR — "debit <= max OR credit <= max" — this
        // matches every line in the ledger, because the unused side of every
        // line is zero and zero is under any ceiling.
        $this->entry('2026-07-10', '5700', 80_000);
        $this->entry('2026-07-11', '5750', 9_000);

        $found = $this->found(['amount' => ['max' => 10_000]]);

        $this->assertCount(2, $found, 'An "at most" filter matched the whole ledger.');
        $this->assertEqualsWithDelta(
            9_000,
            (float) max($found->max('debit_amount'), $found->max('credit_amount')),
            0.01,
        );
    }

    public function test_a_range_narrows_from_both_ends(): void
    {
        $this->entry('2026-07-10', '5700', 80_000);
        $this->entry('2026-07-11', '5750', 9_000);
        $this->entry('2026-07-12', '5850', 30_000);

        $found = $this->found(['amount' => ['min' => 10_000, 'max' => 50_000]]);

        $this->assertCount(2, $found);
        $this->assertEqualsWithDelta(30_000, (float) $found->max('debit_amount'), 0.01);
    }

    public function test_it_filters_by_date(): void
    {
        $this->entry('2026-07-10', '5700', 10_000);
        $this->entry('2026-08-10', '5700', 20_000);

        $this->assertCount(2, $this->found(['dates' => ['from' => '2026-08-01', 'to' => '2026-08-31']]));
    }

    public function test_it_shows_posted_entries_only_until_told_otherwise(): void
    {
        // Left open, a draft's amount lands in the column total under a heading
        // that reads as the books.
        $this->entry('2026-07-10', '5700', 10_000);
        $this->entry('2026-07-11', '5750', 99_000, post: false);

        $found = $this->found();

        $this->assertCount(2, $found, 'The default view is posted entries only.');
        $this->assertEqualsWithDelta(10_000, (float) $found->max('debit_amount'), 0.01);
    }

    public function test_clearing_the_status_filter_brings_drafts_back(): void
    {
        $this->entry('2026-07-10', '5700', 10_000);
        $this->entry('2026-07-11', '5750', 99_000, post: false);

        $this->assertCount(4, $this->found(['status' => ['values' => []]]));
    }

    public function test_it_can_show_one_side_only(): void
    {
        $this->entry('2026-07-10', '5700', 50_000);

        $debits = $this->found(['side' => ['value' => 'debit']]);

        $this->assertCount(1, $debits);
        $this->assertEqualsWithDelta(50_000, (float) $debits->first()->debit_amount, 0.01);
    }

    public function test_filters_combine(): void
    {
        $this->entry('2026-07-10', '5700', 80_000);
        $this->entry('2026-08-10', '5700', 80_000);
        $this->entry('2026-08-11', '5700', 5_000);

        $found = $this->found([
            'account_id' => ['values' => [$this->account('5700')->id]],
            'dates' => ['from' => '2026-08-01', 'to' => '2026-08-31'],
            'amount' => ['min' => 50_000],
            'side' => ['value' => 'debit'],
        ]);

        $this->assertCount(1, $found);
        $this->assertEqualsWithDelta(80_000, (float) $found->first()->debit_amount, 0.01);
    }

    public function test_a_line_with_no_narration_falls_back_to_the_entry_memo(): void
    {
        // A column of blanks helps nobody; the memo is what a human actually
        // wrote about the transaction.
        $this->entry('2026-07-10', '5700', 50_000, memo: 'July rent, Karachi office');

        Livewire::test(FindTransactions::class)
            ->assertSuccessful()
            ->assertSee('July rent, Karachi office');
    }

    public function test_the_page_renders_and_lists_the_entry_number(): void
    {
        $entry = $this->entry('2026-07-10', '5700', 50_000);

        Livewire::test(FindTransactions::class)
            ->assertSuccessful()
            ->assertSee($entry->entry_number);
    }

    public function test_an_employee_cannot_reach_it(): void
    {
        $this->actingAs($this->makeUser('Employee', 'nofinder@test.local'));

        $this->assertFalse(FindTransactions::canAccess());
    }

    public function test_a_line_cannot_outlive_its_entry(): void
    {
        // Why the query carries no whereHas guard against orphans: the database
        // will not have them. Asserted rather than assumed, because the guard was
        // removed on the strength of it.
        $this->entry('2026-07-10', '5700', 50_000);

        $this->expectException(\Illuminate\Database\QueryException::class);

        JournalEntryLine::create([
            'journal_entry_id' => 99_999,
            'account_id' => $this->account('5700')->id,
            'debit_amount' => 1_000,
        ]);
    }

    public function test_deleting_an_entry_takes_its_lines_out_of_the_results(): void
    {
        $entry = $this->entry('2026-07-10', '5700', 50_000);
        $this->entry('2026-07-11', '5750', 9_000);

        $entry->delete();

        $this->assertCount(2, $this->found());
    }
}
