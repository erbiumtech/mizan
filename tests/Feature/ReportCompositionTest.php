<?php

namespace Tests\Feature;

use App\Modules\Accounting\Filament\Pages\ProfitAndLoss;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Accounting\Services\JournalEntryService;
use Illuminate\Support\Facades\File;
use Livewire\Livewire;
use Tests\AccountingTestCase;
use Tests\Concerns\InteractsWithTenant;

/**
 * The proportion bars on the Profit & Loss.
 *
 * A table of thirty account totals says how much each was and leaves the reader
 * to work out which ones mattered. These say it at a glance — and the one thing
 * that can silently break them is not the arithmetic but the CSS: Tailwind finds
 * classes by scanning source text, so an interpolated class name is never
 * compiled and the bar renders as an invisible div. That has happened here
 * before, so it is asserted rather than eyeballed.
 */
class ReportCompositionTest extends AccountingTestCase
{
    use InteractsWithTenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs($this->makeUser('Administrator', 'composition@test.local'));
        $this->setCurrentTenant();
    }

    private function postTo(string $code, float $amount, string $side = 'debit_amount'): void
    {
        $entries = app(JournalEntryService::class);
        $counter = $side === 'debit_amount' ? 'credit_amount' : 'debit_amount';

        $entry = $entries->create(
            ['entry_date' => '2026-07-10', 'entry_type' => 'general', 'memo' => 'Test'],
            [
                ['account_id' => Account::where('code', $code)->firstOrFail()->id, $side => $amount],
                ['account_id' => Account::where('code', '1100')->firstOrFail()->id, $counter => $amount],
            ],
        );

        $entry->update(['status' => JournalEntry::STATUS_APPROVED, 'approved_at' => now()]);
        $entries->post($entry);
    }

    public function test_it_shows_each_account_as_a_share_of_the_whole(): void
    {
        $this->postTo('5700', 75_000);   // 75% of spending
        $this->postTo('5750', 25_000);   // 25%

        Livewire::test(ProfitAndLoss::class)
            ->set('data.from', '2026-07-01')
            ->set('data.to', '2026-07-31')
            ->assertSuccessful()
            ->assertSee('Where the money went')
            ->assertSee('75.0%')
            ->assertSee('25.0%');
    }

    public function test_income_and_expenses_get_their_own_breakdown(): void
    {
        $this->postTo('5700', 40_000);
        $this->postTo('4100', 100_000, 'credit_amount');

        Livewire::test(ProfitAndLoss::class)
            ->set('data.from', '2026-07-01')
            ->set('data.to', '2026-07-31')
            ->assertSuccessful()
            ->assertSee('Where the money went')
            ->assertSee('Where the money came from');
    }

    public function test_a_period_with_nothing_in_it_shows_no_bars(): void
    {
        // An empty chart is worse than no chart: it reads as "everything is zero"
        // rather than "there is nothing here".
        Livewire::test(ProfitAndLoss::class)
            ->set('data.from', '2026-07-01')
            ->set('data.to', '2026-07-31')
            ->assertSuccessful()
            ->assertDontSee('Where the money went');
    }

    public function test_a_net_credit_on_an_expense_is_left_out_of_the_shares_and_said_so(): void
    {
        // A refund bigger than the spend gives an expense account a negative
        // period balance. A negative is not a proportion of a whole, so it is
        // excluded — but silently dropping a row from a report is its own bug.
        $this->postTo('5700', 50_000);
        $this->postTo('5750', 8_000, 'credit_amount');

        Livewire::test(ProfitAndLoss::class)
            ->set('data.from', '2026-07-01')
            ->set('data.to', '2026-07-31')
            ->assertSuccessful()
            ->assertSee('the other way for this');
    }

    public function test_the_bar_colours_are_actually_compiled(): void
    {
        // The regression that has happened here before: a class name that only
        // exists as an interpolation is never seen by Tailwind's scanner, so the
        // bar is a correctly sized div with no background. Every guard passes and
        // the page is visibly broken.
        $css = collect(File::glob(public_path('build/assets/*.css')))
            ->map(fn (string $path): string => File::get($path))
            ->implode("\n");

        $this->assertNotSame('', $css, 'No built CSS at all — run npm run build.');

        foreach (['bg-danger-500', 'bg-success-500', 'bg-primary-500', 'bg-gray-400'] as $class) {
            $this->assertStringContainsString(
                '.'.$class,
                $css,
                "[{$class}] is used by a report bar but is not in the built CSS. "
                .'Either it is written as an interpolation somewhere, or npm run build has not been run.',
            );
        }
    }

    public function test_no_report_view_interpolates_a_tailwind_class(): void
    {
        // The general form of the bug above, caught at the source rather than one
        // class at a time.
        $offenders = [];

        foreach (File::allFiles(resource_path('views')) as $file) {
            if (! str_ends_with($file->getFilename(), '.blade.php')) {
                continue;
            }

            if (preg_match_all(
                '/\b(?:bg|text|border|from|to|via)-\{\{/',
                File::get($file->getPathname()),
                $matches,
            )) {
                $offenders[] = str_replace(base_path().'/', '', $file->getPathname())
                    .' — '.implode(', ', array_unique($matches[0]));
            }
        }

        $this->assertSame([], $offenders, implode("\n", [
            'These views build a Tailwind class by interpolation. Tailwind scans for',
            'literal strings, so the class is never compiled and the element renders',
            'unstyled. Use a lookup map of whole class names instead.',
            '',
            ...$offenders,
        ]));
    }
}
