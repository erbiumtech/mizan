<?php

namespace Tests\Feature;

use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Accounting\Models\Loan;
use App\Modules\Accounting\Services\LoanService;
use Carbon\CarbonImmutable;
use Tests\AccountingTestCase;
use Tests\Concerns\InteractsWithTenant;

/**
 * Amortisation.
 *
 * The thing a hand-kept spreadsheet gets wrong: the instalment is level but the
 * split inside it is not, because interest is charged on what is still owed. A
 * flat split puts the wrong figure in interest expense every month and leaves
 * the liability nowhere near zero at the end.
 *
 * So the properties asserted here are the ones that make the table trustworthy —
 * it closes to exactly zero, the interest falls every month, and every row's
 * arithmetic ties.
 */
class LoanTest extends AccountingTestCase
{
    use InteractsWithTenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs($this->makeUser('Administrator', 'loan@test.local'));
        $this->setCurrentTenant();
    }

    private function service(): LoanService
    {
        return app(LoanService::class);
    }

    private function account(string $code): Account
    {
        return Account::where('code', $code)->firstOrFail();
    }

    private function loan(array $attributes = []): Loan
    {
        $loan = Loan::create($attributes + [
            'name' => 'Vehicle finance',
            'liability_account_id' => $this->account('2100')->id,
            'interest_account_id' => $this->account('5900')->id,
            'payment_account_id' => $this->account('1100')->id,
            'principal' => 1_200_000,
            'annual_rate' => 12,
            'term_months' => 12,
            'starts_on' => '2026-07-05',
        ]);

        $this->service()->generateSchedule($loan);

        return $loan->fresh('instalments');
    }

    // ── the arithmetic ──────────────────────────────────────────────────────

    public function test_the_level_instalment_matches_the_annuity_formula(): void
    {
        // 1,200,000 at 1% a month over 12 months. Cross-checked against the
        // standard PMT: 1200000 * 0.01 / (1 - 1.01^-12) = 106,618.55.
        $this->assertEqualsWithDelta(
            106_618.55,
            $this->service()->instalmentAmount(1_200_000, 0.01, 12),
            0.01,
        );
    }

    public function test_an_interest_free_loan_is_split_evenly(): void
    {
        // The annuity formula divides by zero at a zero rate. A staff loan or
        // money from family is a real arrangement, not an error.
        $this->assertEqualsWithDelta(
            10_000,
            $this->service()->instalmentAmount(120_000, 0.0, 12),
            0.01,
        );
    }

    public function test_the_schedule_closes_to_exactly_zero(): void
    {
        // Rounding each month to the paisa leaves a level schedule a few rupees
        // out after a full term. A loan that finishes owing 3.71 is a loan
        // somebody has to write a journal entry to close.
        foreach ([[1_200_000, 12, 12], [5_000_000, 14.5, 60], [250_000, 0, 18], [9_999_999, 23.75, 240]] as [$p, $rate, $n]) {
            $rows = $this->service()->schedule($p, $rate / 100 / 12, $n, CarbonImmutable::parse('2026-07-01'));

            $this->assertEqualsWithDelta(
                0.0,
                (float) end($rows)['closing_balance'],
                0.005,
                "A loan of {$p} at {$rate}% over {$n} months did not close to zero.",
            );
        }
    }

    public function test_every_row_ties(): void
    {
        $rows = $this->service()->schedule(5_000_000, 0.145 / 12, 60, CarbonImmutable::parse('2026-07-01'));

        foreach ($rows as $row) {
            $this->assertEqualsWithDelta(
                $row['payment'],
                $row['principal'] + $row['interest'],
                0.005,
                "Instalment {$row['number']}: the payment is not its two parts.",
            );

            $this->assertEqualsWithDelta(
                $row['closing_balance'],
                $row['opening_balance'] - $row['principal'],
                0.005,
                "Instalment {$row['number']}: the balance did not fall by the principal.",
            );
        }
    }

    public function test_the_interest_share_falls_and_the_principal_share_rises(): void
    {
        $rows = $this->service()->schedule(1_200_000, 0.01, 12, CarbonImmutable::parse('2026-07-01'));

        for ($i = 1; $i < count($rows); $i++) {
            $this->assertLessThan($rows[$i - 1]['interest'], $rows[$i]['interest']);
            $this->assertGreaterThan($rows[$i - 1]['principal'], $rows[$i]['principal']);
        }
    }

    public function test_the_first_instalment_is_almost_all_interest_on_a_long_loan(): void
    {
        // The fact the table exists to show. 20 years at 20%: the first payment
        // barely touches the principal.
        $rows = $this->service()->schedule(10_000_000, 0.20 / 12, 240, CarbonImmutable::parse('2026-07-01'));

        $this->assertGreaterThan(0.9, $rows[0]['interest'] / $rows[0]['payment']);
        $this->assertLessThan(0.1, $rows[239]['interest'] / $rows[239]['payment']);
    }

    public function test_a_rate_that_could_never_amortise_is_refused(): void
    {
        // Only reachable at nonsense rates — the annuity payment is derived from
        // the same rate, so for any sane one it exceeds the interest by
        // construction. At 100% a MONTH the level payment rounds to exactly the
        // interest and the balance would never fall, which is the case worth
        // refusing rather than rendering as a schedule that goes nowhere.
        $this->expectExceptionMessageMatches('/never fall/');

        $this->service()->schedule(1_000_000, 100.0, 12, CarbonImmutable::parse('2026-07-01'));
    }

    public function test_a_short_month_does_not_skip_an_instalment(): void
    {
        $rows = $this->service()->schedule(300_000, 0.0, 3, CarbonImmutable::parse('2027-01-31'));

        $this->assertSame(
            ['2027-01-31', '2027-02-28', '2027-03-31'],
            array_column($rows, 'due_on'),
        );
    }

    // ── recording ───────────────────────────────────────────────────────────

    public function test_recording_an_instalment_raises_a_three_sided_draft(): void
    {
        $loan = $this->loan();
        $first = $loan->instalments->first();

        $entry = $this->service()->recordInstalment($first);

        $this->assertSame(JournalEntry::STATUS_DRAFT, $entry->status);
        $this->assertCount(3, $entry->lines);

        $byAccount = $entry->lines->keyBy('account_id');

        $this->assertEqualsWithDelta(
            (float) $first->principal,
            (float) $byAccount[$loan->liability_account_id]->debit_amount,
            0.01,
            'The liability must fall by the principal portion only.',
        );
        $this->assertEqualsWithDelta(
            (float) $first->interest,
            (float) $byAccount[$loan->interest_account_id]->debit_amount,
            0.01,
        );
        $this->assertEqualsWithDelta(
            (float) $first->payment,
            (float) $byAccount[$loan->payment_account_id]->credit_amount,
            0.01,
            'The bank loses the whole instalment, not just the principal.',
        );
    }

    public function test_an_interest_free_instalment_has_no_interest_line(): void
    {
        // A zero line balances perfectly well and puts a row of nothing on every
        // entry for the life of the loan.
        $loan = $this->loan(['annual_rate' => 0, 'principal' => 120_000]);

        $entry = $this->service()->recordInstalment($loan->instalments->first());

        $this->assertCount(2, $entry->lines);
    }

    public function test_an_instalment_cannot_be_recorded_twice(): void
    {
        $loan = $this->loan();
        $first = $loan->instalments->first();

        $this->service()->recordInstalment($first);

        $this->expectExceptionMessageMatches('/already been recorded/');
        $this->service()->recordInstalment($first->fresh());
    }

    public function test_the_schedule_cannot_be_rebuilt_once_anything_is_recorded(): void
    {
        // Half the table is already in the ledger. Regenerating would leave
        // entries matching no row and a liability that no longer reaches zero.
        $loan = $this->loan();
        $this->service()->recordInstalment($loan->instalments->first());

        $this->expectExceptionMessageMatches('/cannot be rebuilt/');
        $this->service()->generateSchedule($loan->fresh());
    }

    public function test_the_outstanding_balance_follows_what_has_been_recorded(): void
    {
        $loan = $this->loan();

        $this->assertEqualsWithDelta(1_200_000, $loan->scheduledOutstanding(), 0.01);

        $this->service()->recordInstalment($loan->instalments->first());

        $this->assertEqualsWithDelta(
            (float) $loan->instalments->first()->closing_balance,
            $loan->fresh()->scheduledOutstanding(),
            0.01,
        );
    }

    public function test_the_next_due_instalment_is_the_first_unrecorded_one(): void
    {
        $loan = $this->loan();

        $this->assertSame(1, $loan->nextDue()->number);

        $this->service()->recordInstalment($loan->instalments->first());

        $this->assertSame(2, $loan->fresh()->nextDue()->number);
    }

    public function test_a_finished_loan_has_nothing_next_due(): void
    {
        $loan = $this->loan(['term_months' => 2, 'principal' => 20_000, 'annual_rate' => 0]);

        foreach ($loan->instalments as $instalment) {
            $this->service()->recordInstalment($instalment);
        }

        $this->assertNull($loan->fresh()->nextDue());
        $this->assertEqualsWithDelta(0.0, $loan->fresh()->scheduledOutstanding(), 0.01);
    }

    public function test_the_total_interest_is_the_cost_of_borrowing(): void
    {
        $loan = $this->loan();

        // Twelve payments of ~106,619 against 1,200,000 borrowed.
        $this->assertEqualsWithDelta(
            round((float) $loan->instalments->sum('payment') - 1_200_000, 2),
            $loan->totalInterest(),
            0.05,
        );
    }
}
