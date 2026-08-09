<?php

namespace Tests\Feature;

use App\Modules\Accounting\Filament\Widgets\CashFlowChart;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Accounting\Services\JournalEntryService;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use ReflectionClass;
use Tests\AccountingTestCase;
use Tests\Concerns\InteractsWithTenant;

/**
 * The in / out / net line under the daily cash flow chart.
 *
 * Fourteen pairs of points tell you the shape of the period and not whether cash
 * went up or down, which is what the panel is actually looked at for. The totals
 * are the same two aggregates the chart already runs, so what is worth testing is
 * that they cover the same window the chart plots and that the sign is right.
 */
class DashboardCashFlowTotalsTest extends AccountingTestCase
{
    use InteractsWithTenant;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-08-20');

        $this->actingAs($this->makeUser('Administrator', 'cashflow-widget@test.local'));
        $this->setCurrentTenant();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function postEntry(string $date, array $lines): JournalEntry
    {
        $entries = app(JournalEntryService::class);

        $entry = $entries->create(
            ['entry_date' => $date, 'entry_type' => 'general', 'memo' => 'Test'],
            collect($lines)->map(fn (array $line): array => [
                'account_id' => Account::where('code', $line[0])->firstOrFail()->id,
                $line[1] => $line[2],
            ])->all(),
        );

        $entry->update(['status' => JournalEntry::STATUS_APPROVED, 'approved_at' => now()]);

        return $entries->post($entry);
    }

    /** Money into the bank, and money back out of it. */
    private function cashIn(string $date, float $amount): void
    {
        $this->postEntry($date, [['1100', 'debit_amount', $amount], ['4100', 'credit_amount', $amount]]);
    }

    private function cashOut(string $date, float $amount): void
    {
        $this->postEntry($date, [['5700', 'debit_amount', $amount], ['1100', 'credit_amount', $amount]]);
    }

    private function description(string $filter = '14'): string
    {
        $widget = new CashFlowChart;
        $widget->filter = $filter;

        return (string) $widget->getDescription();
    }

    public function test_it_totals_the_window_it_plots(): void
    {
        $this->cashIn('2026-08-18', 90000);
        $this->cashOut('2026-08-19', 32500);

        $description = $this->description();

        $this->assertStringContainsString('In PKR 90,000.00', $description);
        $this->assertStringContainsString('Out PKR 32,500.00', $description);
        $this->assertStringContainsString('Net PKR 57,500.00', $description);
        $this->assertStringContainsString('over 14 days', $description);
    }

    public function test_a_negative_net_is_shown_as_negative(): void
    {
        $this->cashIn('2026-08-15', 10000);
        $this->cashOut('2026-08-16', 45000);

        // Not "Net PKR -35,000.00" by string luck: the sign is deliberate, and a
        // month where more went out than came in must not read as a surplus.
        $this->assertStringContainsString('Net −PKR 35,000.00', $this->description());
    }

    public function test_movement_outside_the_window_is_excluded(): void
    {
        $this->cashIn('2026-08-19', 5000);   // inside 14 days
        $this->cashIn('2026-07-01', 800000); // long before

        $this->assertStringContainsString('In PKR 5,000.00', $this->description('14'));

        // ...and reappears once the filter reaches back far enough.
        $this->assertStringContainsString('In PKR 805,000.00', $this->description('60'));
    }

    public function test_an_empty_period_reads_as_zero_rather_than_blank(): void
    {
        $description = $this->description();

        $this->assertStringContainsString('In PKR 0.00', $description);
        $this->assertStringContainsString('Net PKR 0.00', $description);
    }

    public function test_the_totals_agree_with_the_plotted_series(): void
    {
        $this->cashIn('2026-08-10', 12000);
        $this->cashIn('2026-08-11', 8000);
        $this->cashOut('2026-08-12', 3000);

        $widget = new CashFlowChart;
        $widget->filter = '14';

        // getData() is protected, as Filament defines it — reached the same way
        // PayrollAccountCheckTest reaches accountId().
        $method = (new ReflectionClass($widget))->getMethod('getData');
        $method->setAccessible(true);

        $data = $method->invoke($widget);
        $plottedIn = array_sum($data['datasets'][0]['data']);
        $plottedOut = array_sum($data['datasets'][1]['data']);

        $this->assertSame(20000.0, round($plottedIn, 2));
        $this->assertStringContainsString('In PKR '.number_format($plottedIn, 2), (string) $widget->getDescription());
        $this->assertStringContainsString('Out PKR '.number_format($plottedOut, 2), (string) $widget->getDescription());
    }

    public function test_the_widget_still_renders(): void
    {
        $this->cashIn('2026-08-19', 1500);

        Livewire::test(CashFlowChart::class)
            ->assertSuccessful()
            ->assertSee('Net PKR 1,500.00');
    }
}
