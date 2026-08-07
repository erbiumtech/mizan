<?php

namespace Tests\Feature;

use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Accounting\Models\ScheduledTransaction;
use App\Modules\Accounting\Services\ScheduledTransactionService;
use Carbon\CarbonImmutable;
use Tests\AccountingTestCase;
use Tests\Concerns\InteractsWithTenant;

/**
 * Standing journal entries.
 *
 * The claims worth pinning are the ones a reader would otherwise have to take on
 * trust: that nothing is ever raised twice, that a week of downtime does not
 * lose a week of rent, that a schedule set to the 31st still fires in February,
 * and that what comes out is a draft rather than something already in the books.
 */
class ScheduledTransactionTest extends AccountingTestCase
{
    use InteractsWithTenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs($this->makeUser('Administrator', 'scheduled@test.local'));
        $this->setCurrentTenant();
    }

    private function account(string $code): Account
    {
        return Account::where('code', $code)->firstOrFail();
    }

    private function schedule(array $attributes = [], float $amount = 50_000): ScheduledTransaction
    {
        $schedule = ScheduledTransaction::create($attributes + [
            'name' => 'Office rent',
            'interval_months' => 1,
            'day_of_month' => 1,
            'starts_on' => '2026-07-01',
        ]);

        $schedule->lines()->createMany([
            ['account_id' => $this->account('5700')->id, 'debit_amount' => $amount, 'sort' => 0],
            ['account_id' => $this->account('1100')->id, 'credit_amount' => $amount, 'sort' => 1],
        ]);

        return $schedule->fresh('lines');
    }

    private function service(): ScheduledTransactionService
    {
        return app(ScheduledTransactionService::class);
    }

    private function upTo(string $date): CarbonImmutable
    {
        return CarbonImmutable::parse($date)->startOfDay();
    }

    // ── when it falls due ───────────────────────────────────────────────────

    public function test_a_monthly_schedule_falls_due_every_month(): void
    {
        $dates = collect($this->schedule()->occurrencesUpTo($this->upTo('2026-10-15')))
            ->map->toDateString()
            ->all();

        $this->assertSame(['2026-07-01', '2026-08-01', '2026-09-01', '2026-10-01'], $dates);
    }

    public function test_a_quarterly_schedule_skips_the_months_between(): void
    {
        $dates = collect($this->schedule(['interval_months' => 3])->occurrencesUpTo($this->upTo('2027-06-30')))
            ->map->toDateString()
            ->all();

        $this->assertSame(['2026-07-01', '2026-10-01', '2027-01-01', '2027-04-01'], $dates);
    }

    public function test_a_short_month_uses_its_last_day_rather_than_skipping(): void
    {
        // The alternative — no occurrence at all in February — means the rent
        // quietly misses a month, which is worse than an entry dated two days
        // early.
        $dates = collect(
            $this->schedule(['day_of_month' => 31, 'starts_on' => '2027-01-01'])
                ->occurrencesUpTo($this->upTo('2027-03-31'))
        )->map->toDateString()->all();

        $this->assertSame(['2027-01-31', '2027-02-28', '2027-03-31'], $dates);
    }

    public function test_the_first_occurrence_never_predates_the_start(): void
    {
        // Agreed on the 20th, billed on the 1st: the first one is next month, not
        // three weeks before the agreement existed.
        $dates = collect(
            $this->schedule(['starts_on' => '2026-07-20', 'day_of_month' => 1])
                ->occurrencesUpTo($this->upTo('2026-09-30'))
        )->map->toDateString()->all();

        $this->assertSame(['2026-08-01', '2026-09-01'], $dates);
    }

    public function test_it_stops_after_the_end_date(): void
    {
        $dates = collect(
            $this->schedule(['ends_on' => '2026-09-15'])->occurrencesUpTo($this->upTo('2027-01-01'))
        )->map->toDateString()->all();

        $this->assertSame(['2026-07-01', '2026-08-01', '2026-09-01'], $dates);
    }

    // ── raising ─────────────────────────────────────────────────────────────

    public function test_it_raises_a_draft_and_not_a_posted_entry(): void
    {
        $schedule = $this->schedule();

        $this->service()->run($this->upTo('2026-07-01'));

        $entry = JournalEntry::forSource(ScheduledTransaction::class, $schedule->getKey())->firstOrFail();

        $this->assertSame(JournalEntry::STATUS_DRAFT, $entry->status, implode("\n", [
            'A schedule posted straight to the ledger.',
            'That is a way to put anything in the books with no approver, which is',
            'the one thing the approval workflow exists to prevent.',
        ]));
        $this->assertFalse((bool) $entry->is_posted);
        $this->assertSame('2026-07-01', $entry->entry_date->toDateString());
        $this->assertEqualsWithDelta(50_000, (float) $entry->lines->sum('debit_amount'), 0.01);
    }

    public function test_running_twice_raises_nothing_the_second_time(): void
    {
        $schedule = $this->schedule();

        $this->service()->run($this->upTo('2026-09-01'));
        $second = $this->service()->run($this->upTo('2026-09-01'));

        $this->assertCount(0, $second);
        $this->assertSame(3, JournalEntry::forSource(ScheduledTransaction::class, $schedule->getKey())->count());
    }

    public function test_a_week_of_downtime_does_not_lose_a_month(): void
    {
        // Due dates come from the schedule's own start, not from when the job
        // last happened to fire, so catching up is the ordinary path.
        $schedule = $this->schedule();

        $raised = $this->service()->run($this->upTo('2026-10-05'));

        $this->assertCount(4, $raised);
        $this->assertSame(
            ['2026-07-01', '2026-08-01', '2026-09-01', '2026-10-01'],
            JournalEntry::forSource(ScheduledTransaction::class, $schedule->getKey())
                ->orderBy('entry_date')
                ->pluck('entry_date')
                ->map(fn ($d) => CarbonImmutable::parse($d)->toDateString())
                ->all(),
        );
    }

    public function test_deleting_a_draft_lets_it_be_raised_again(): void
    {
        // Idempotency is asked of the ledger rather than kept as a cursor, and
        // this is the behaviour that proves which one it is.
        $schedule = $this->schedule();

        $this->service()->run($this->upTo('2026-07-01'));
        JournalEntry::forSource(ScheduledTransaction::class, $schedule->getKey())->firstOrFail()->delete();

        $this->assertCount(1, $this->service()->run($this->upTo('2026-07-01')));
    }

    public function test_catch_up_is_capped_and_the_rest_waits_for_the_next_run(): void
    {
        $schedule = $this->schedule(['starts_on' => '2020-01-01']);

        $first = $this->service()->run($this->upTo('2026-07-01'));
        $this->assertCount(ScheduledTransactionService::MAX_PER_RUN, $first);

        // Not lost — the next run picks up where this one stopped.
        $second = $this->service()->run($this->upTo('2026-07-01'));
        $this->assertCount(ScheduledTransactionService::MAX_PER_RUN, $second);
    }

    public function test_an_unbalanced_schedule_is_skipped_without_stopping_the_others(): void
    {
        $broken = $this->schedule(['name' => 'Broken']);
        $broken->lines()->first()->update(['debit_amount' => 1]);

        $good = $this->schedule(['name' => 'Good']);

        $raised = $this->service()->run($this->upTo('2026-07-01'));

        $this->assertCount(1, $raised);
        $this->assertSame($good->getKey(), $raised->first()->source_id);
        $this->assertSame(0, JournalEntry::forSource(ScheduledTransaction::class, $broken->getKey())->count());
    }

    public function test_a_paused_schedule_raises_nothing(): void
    {
        $this->schedule(['is_active' => false]);

        $this->assertCount(0, $this->service()->run($this->upTo('2026-12-01')));
    }

    public function test_the_entry_carries_the_schedules_memo(): void
    {
        $schedule = $this->schedule(['memo' => 'Rent for the Karachi office']);

        $this->service()->run($this->upTo('2026-07-01'));

        $this->assertSame(
            'Rent for the Karachi office',
            JournalEntry::forSource(ScheduledTransaction::class, $schedule->getKey())->firstOrFail()->memo,
        );
    }

    public function test_an_entry_with_no_memo_falls_back_to_the_schedule_name(): void
    {
        $schedule = $this->schedule();

        $this->service()->run($this->upTo('2026-07-01'));

        $this->assertSame(
            'Office rent',
            JournalEntry::forSource(ScheduledTransaction::class, $schedule->getKey())->firstOrFail()->memo,
        );
    }

    public function test_the_raised_entry_gets_a_fiscal_year(): void
    {
        // JournalEntryService derives it from the date. Worth asserting here
        // because an entry with no fiscal year vanishes from any report that
        // filters on one, with nothing to say why.
        $schedule = $this->schedule();

        $this->service()->run($this->upTo('2026-07-01'));

        $this->assertSame(
            $this->fiscalYear->getKey(),
            JournalEntry::forSource(ScheduledTransaction::class, $schedule->getKey())->firstOrFail()->fiscal_year_id,
        );
    }

    // ── the schedule itself ─────────────────────────────────────────────────

    /**
     * Asserted against the service above rather than by running the command,
     * which is wrapped in Spatie's TenantAware and iterates real per-tenant
     * database connections this single-database suite does not have. Same reason
     * MonthlyScheduleTest tests its services and asserts only registration here.
     */
    public function test_the_command_is_registered(): void
    {
        $this->assertArrayHasKey('accounting:raise-scheduled', \Illuminate\Support\Facades\Artisan::all());
    }

    public function test_it_runs_nightly_and_out_of_the_way_of_payroll(): void
    {
        $expression = collect(app(\Illuminate\Console\Scheduling\Schedule::class)->events())
            ->mapWithKeys(fn ($event): array => [$event->command => $event->expression])
            ->first(fn ($expression, $key): bool => str_contains($key, 'accounting:raise-scheduled'));

        // minute hour day-of-month month day-of-week. Daily, because a schedule
        // may name any day — and because a daily run makes catching up after an
        // outage the ordinary path rather than a repair job.
        $this->assertSame('20 2 * * *', $expression);
    }
}
