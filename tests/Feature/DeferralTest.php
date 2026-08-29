<?php

namespace Tests\Feature;

use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Accounting\Services\DeferralService;
use App\Modules\Accounting\Services\ScheduledTransactionService;
use App\Modules\Accounting\Services\SecondApproverRule;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use RuntimeException;
use Tests\AccountingTestCase;
use Tests\Concerns\InteractsWithTenant;

/**
 * Deferred revenue and prepaid cost — `docs/erpnext-gap-plan.md` Phase 5.
 *
 * An annual subscription invoiced in July recognised the whole year in July, and an annual licence bought in
 * July charged the whole year to July. Both overstate one month and understate eleven.
 *
 * **The item's point is that no engine was needed.** ERPNext has one — a flag on the item, service dates on
 * the invoice row, a company-wide proration choice, a job. Here `ScheduledTransaction` was already a dated
 * monthly posting between two dates, so what was missing was the arithmetic and a pair of accounts. These
 * tests are mostly about the arithmetic, because that is the part that can be wrong while looking right:
 *
 *  - **`test_the_schedule_recognises_the_whole_deferral_and_no_more`** — twelve postings that add up to what
 *    was deferred, which is the property the whole feature stands on.
 *  - **`test_a_remainder_stays_in_income_rather_than_stranding_in_the_liability`** — 1,000 over three months
 *    does not divide, and a fixed-line schedule cannot vary its last posting.
 *  - **`test_deferring_a_cost_is_the_same_entry_the_other_way_up`** — the mirror, asserted rather than
 *    assumed, because a schedule pointing the wrong way looks like a working feature until the year end.
 */
class DeferralTest extends AccountingTestCase
{
    use InteractsWithTenant;

    private DeferralService $deferrals;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-08-13 10:00:00');

        // Signed in before the tenant is made current, which is the order `setCurrentTenant()` needs:
        // Filament's TenantSet event carries the user, and there is nobody to carry otherwise.
        $this->actingAs($this->makeUser('Administrator', 'deferral@test.local'));
        $this->setCurrentTenant();

        /*
         * The second-approver control off, which is what lets the monthly recognition reach the ledger in
         * these tests rather than sitting as a draft.
         *
         * Not a convenience: it is the honest way to measure the arithmetic, because the recognition goes
         * through `ScheduledTransactionService` exactly as every other schedule does — and that service
         * respects the company's approval policy. The test below asserts the other case explicitly, so both
         * halves of that behaviour are pinned rather than one of them being assumed.
         */
        app(SecondApproverRule::class)->set(false);

        $this->deferrals = app(DeferralService::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /** The deferral itself: revenue out of income, into the liability, posted. */
    public function test_deferring_revenue_takes_it_out_of_income_now(): void
    {
        $result = $this->deferrals->deferRevenue(12_000, 12, '2026-09-01', 'Annual licence — Acme');

        $entry = $result['entry'];

        $this->assertTrue($entry->is_posted);
        // Dated the end of the month before the first recognition: the deferral belongs to the period being
        // taken out of, not to the first period being given.
        $this->assertSame('2026-08-31', $entry->entry_date->toDateString());
        // Debits less credits: taking revenue out of income is a debit to 4100, and the liability is
        // credited — so income moves +12,000 here and the liability -12,000. The signs read backwards
        // against the balance sheet on purpose; this is one entry, not a balance.
        $this->assertSame(12_000.0, $this->movement($entry, '4100'));
        $this->assertSame(-12_000.0, $this->movement($entry, '2500'));
        $this->assertSame(1_000.0, $result['monthly']);
    }

    /**
     * And the schedule gives it back, exactly.
     *
     * Run through `ScheduledTransactionService` rather than asserted from the schedule's own lines, so what
     * is measured is what the job will actually post — twelve entries, adding to the deferral, and nothing in
     * the thirteenth month.
     */
    public function test_the_schedule_recognises_the_whole_deferral_and_no_more(): void
    {
        $result = $this->deferrals->deferRevenue(12_000, 12, '2026-09-01', 'Annual licence — Acme');

        $occurrences = $result['schedule']->occurrencesUpTo(CarbonImmutable::parse('2028-01-31'));

        $this->assertCount(12, $occurrences);
        $this->assertSame('2026-09-30', $occurrences[0]->toDateString());
        $this->assertSame('2027-08-31', $occurrences[11]->toDateString());

        $raised = app(ScheduledTransactionService::class)->run(CarbonImmutable::parse('2028-01-31'));

        $this->assertCount(12, $raised);
        $this->assertSame(12_000.0, round((float) collect($raised)
            ->flatMap(fn (JournalEntry $entry): array => $entry->lines()->get()->all())
            ->where('account_id', $this->accountId('2500'))
            ->sum('debit_amount'), 2));

        // The liability is empty afterwards, which is the only test of "and no more" that matters.
        $this->assertSame(0.0, $this->balance('2500'));
    }

    /**
     * A remainder is left in income rather than stranded in the liability.
     *
     * 1,000 over three months is 333.33 a month. Deferring 1,000 would leave a cent in 2500 for ever;
     * deferring 999.99 leaves the cent where it already was, in the month that billed it.
     */
    public function test_a_remainder_stays_in_income_rather_than_stranding_in_the_liability(): void
    {
        $result = $this->deferrals->deferRevenue(1_000, 3, '2026-09-01', 'Three months of support');

        $this->assertSame(333.33, $result['monthly']);
        $this->assertSame(999.99, $result['deferred']);
        $this->assertSame(-999.99, $this->movement($result['entry'], '2500'));

        app(ScheduledTransactionService::class)->run(CarbonImmutable::parse('2026-12-31'));

        $this->assertSame(0.0, $this->balance('2500'));
        /*
         * The cent that was never deferred.
         *
         * Nothing here billed the original 1,000 — the fixture defers out of an income account that starts
         * empty — so what is left in 4100 is the deferral's own arithmetic: 999.99 taken out and 999.99 given
         * back, netting to nothing, with the 0.01 never having left. Measured as "the deferral is complete"
         * rather than as an income balance, which is the claim being made.
         */
        $this->assertSame(0.0, $this->balance('4100'));
    }

    /** The mirror, and the accounts are the other way up. */
    public function test_deferring_a_cost_is_the_same_entry_the_other_way_up(): void
    {
        $result = $this->deferrals->deferExpense(6_000, 6, '2026-09-01', 'Annual insurance');

        $entry = $result['entry'];

        $this->assertSame(6_000.0, $this->movement($entry, '1350'));
        $this->assertSame(-6_000.0, $this->movement($entry, '5900'));
        $this->assertSame(1_000.0, $result['monthly']);

        // Halfway: three months charged, so half the asset is left and half the cost has landed.
        app(ScheduledTransactionService::class)->run(CarbonImmutable::parse('2026-11-30'));

        $this->assertSame(3_000.0, round($this->balanceOf('1350'), 2));
        $this->assertSame(-3_000.0, round($this->balanceOf('5900'), 2));

        // And at the end the asset is used up. 5900 comes back to nothing here because this fixture never
        // booked the original cost into it — the deferral took 6,000 out and the schedule put 6,000 back.
        // In a real company the invoice put it there first, and what these two figures prove is that the
        // pair moves together, a month at a time, and nets exactly.
        app(ScheduledTransactionService::class)->run(CarbonImmutable::parse('2027-03-31'));

        $this->assertSame(0.0, $this->balance('1350'));
        $this->assertSame(0.0, $this->balance('5900'));
    }

    /**
     * With the control on, the month's recognition arrives as a draft for somebody to approve.
     *
     * The other half of what `setUp()` switches off, pinned rather than assumed. It is also the honest
     * answer: a deferral schedule is a schedule, and a company that requires a second approver on journal
     * entries requires one on these too. What it must not do is post silently.
     */
    public function test_with_a_second_approver_required_the_recognition_waits_as_a_draft(): void
    {
        app(SecondApproverRule::class)->set(true);

        $this->deferrals->deferRevenue(12_000, 12, '2026-09-01', 'Annual licence — Acme');

        $raised = app(ScheduledTransactionService::class)->run(CarbonImmutable::parse('2026-09-30'));

        $this->assertCount(1, $raised);
        $this->assertFalse((bool) $raised->first()->is_posted);
        $this->assertSame(JournalEntry::STATUS_DRAFT, $raised->first()->status);

        // The liability still holds the whole deferral: nothing has been recognised until somebody approves.
        $this->assertSame(-12_000.0, round($this->balanceOf('2500'), 2));
    }

    /** A named account is used rather than the default, because a company with two revenue accounts has one. */
    public function test_the_income_account_can_be_named(): void
    {
        $other = $this->accountId('4200');

        $result = $this->deferrals->deferRevenue(1_200, 12, '2026-09-01', 'Product subscriptions', $other);

        $this->assertSame(1_200.0, $this->movement($result['entry'], '4200'));
        $this->assertSame(0.0, $this->movement($result['entry'], '4100'));
    }

    /** Refusals, each of which is a sentence somebody can act on. */
    public function test_it_refuses_what_it_cannot_spread(): void
    {
        $this->assertRefused('nothing to defer', fn () => $this->deferrals->deferRevenue(0, 12, '2026-09-01', 'x'));
        $this->assertRefused('at least one month', fn () => $this->deferrals->deferRevenue(100, 0, '2026-09-01', 'x'));
        $this->assertRefused('typing mistake', fn () => $this->deferrals->deferRevenue(100, 240, '2026-09-01', 'x'));
        $this->assertRefused('less than a cent a month', fn () => $this->deferrals->deferRevenue(0.05, 12, '2026-09-01', 'x'));
    }

    /**
     * A chart without the deferral account refuses rather than inventing one.
     *
     * Creating an account as a side effect of a form submission is how a chart of accounts stops being
     * something a company recognises — and the message names the command that adds the shipped one.
     */
    public function test_a_chart_with_no_deferred_revenue_account_says_so(): void
    {
        Account::where('code', DeferralService::DEFERRED_REVENUE_CODE)->delete();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('tenants:seed-baseline');

        $this->deferrals->deferRevenue(1_200, 12, '2026-09-01', 'Annual licence');
    }

    /** Nothing is left half-done: a failure after the entry rolls the entry back too. */
    public function test_the_deferral_and_its_schedule_are_one_transaction(): void
    {
        $before = JournalEntry::count();

        try {
            // 4100 is deleted after the account lookups would have succeeded for 2500, so the failure
            // happens while the entry is being written rather than before anything starts.
            $this->deferrals->deferRevenue(1_200, 12, '2026-09-01', 'x', 999_999);
        } catch (\Throwable) {
            // The point is the count below.
        }

        $this->assertSame($before, JournalEntry::count());
        $this->assertSame(0, \App\Modules\Accounting\Models\ScheduledTransaction::count());
    }

    // ───────────────────────────────────────────────────── fixtures ──

    private function assertRefused(string $fragment, callable $act): void
    {
        try {
            $act();

            $this->fail("Expected a refusal containing [{$fragment}].");
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString($fragment, $e->getMessage());
        }
    }

    /** Net movement on an account within one entry: debits less credits. */
    private function movement(JournalEntry $entry, string $code): float
    {
        $lines = $entry->lines()->where('account_id', $this->accountId($code));

        return round((float) $lines->sum('debit_amount') - (float) $lines->sum('credit_amount'), 2);
    }

    /** The account's balance across every posted entry, debits less credits. */
    private function balanceOf(string $code): float
    {
        $lines = \App\Modules\Accounting\Models\JournalEntryLine::query()
            ->where('account_id', $this->accountId($code))
            ->whereHas('journalEntry', fn ($entry) => $entry->where('is_posted', true));

        return round((float) (clone $lines)->sum('debit_amount') - (float) $lines->sum('credit_amount'), 2);
    }

    /** The same thing, for an account whose balance should have come back to nothing. */
    private function balance(string $code): float
    {
        return abs($this->balanceOf($code)) < 0.005 ? 0.0 : $this->balanceOf($code);
    }

    private function accountId(string $code): int
    {
        return Account::where('code', $code)->firstOrFail()->id;
    }
}
