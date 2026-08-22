<?php

namespace Tests\Feature;

use App\Modules\Payroll\Filament\Resources\PayrollRuns\PayrollRunResource;
use App\Modules\Payroll\Models\PayrollRun;
use Illuminate\Support\Facades\DB;
use Tests\AccountingTestCase;
use Tests\Concerns\InteractsWithTenant;

/**
 * The payroll-runs list, rendered with rows in it.
 *
 * **That last clause is the whole point of this file.** `FilamentResourcesSmokeTest` already renders every
 * resource index, and it passed while this screen was returning a 500 in the browser — because it creates
 * a user and nothing else, so every table it renders is empty and no per-row closure ever runs. A column
 * that reaches for a relation is invisible to a test with no rows.
 *
 * What shipped past it: the Period column calls `PayrollRun::periodLabel()`, which reads the fiscal year;
 * with lazy loading disabled outside production that is a LazyLoadingViolationException on every visit, and
 * before the guard it was one query per row. The Accepted column then called `totals()` — five aggregates —
 * *twice* per row, which is ten queries a row and a hundred for a page of ten.
 *
 * So this asserts both, and asserts them the only way that works: with rows on the page.
 */
class PayrollRunsListTest extends AccountingTestCase
{
    use InteractsWithTenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs($this->makeUser('Administrator', 'runs@test.local'));
        $this->setCurrentTenant();
    }

    /** @return array<int, string> the statements one request ran */
    private function renderList(): array
    {
        $queries = [];

        DB::listen(function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });

        $this->get(PayrollRunResource::getUrl('index'))->assertOk();

        return $queries;
    }

    public function test_the_list_renders_with_runs_in_it(): void
    {
        foreach (['July', 'August', 'September'] as $month) {
            PayrollRun::forMonth($month, $this->fiscalYear);
        }

        $response = $this->get(PayrollRunResource::getUrl('index'))->assertOk();

        // The Period column is the one that reads the fiscal year. Its label is built from the month and
        // that year, so seeing it means the relation resolved rather than throwing.
        $response->assertSee('July');
        $response->assertSee('August');
    }

    /**
     * The page's cost does not grow with the number of runs on it.
     *
     * Both defects above were per-row, so this is the assertion that would have caught either: the eager
     * load stops the fiscal year being fetched again for each row, and the memo stops the five aggregates
     * being run twice.
     */
    public function test_the_list_does_not_pay_per_row(): void
    {
        PayrollRun::forMonth('July', $this->fiscalYear);

        $this->renderList();          // warm whatever caches the shell has
        $few = $this->renderList();

        foreach (['August', 'September', 'October', 'November', 'December'] as $month) {
            PayrollRun::forMonth($month, $this->fiscalYear);
        }

        $many = $this->renderList();

        // Five more rows. The aggregates behind the Accepted column are per run and cannot be avoided
        // without changing what the column says, so the budget is what those cost once each — not twice,
        // and not plus a fiscal-year lookup per row.
        $growth = count($many) - count($few);

        $this->assertLessThanOrEqual(
            25,
            $growth,
            "five more rows cost {$growth} more queries:\n\n".implode("\n", array_diff($many, $few)),
        );

        // And the fiscal year is fetched for the page rather than for each row.
        $yearLookups = count(array_filter(
            $many,
            fn (string $sql): bool => str_contains($sql, 'from `fiscal_years`') || str_contains($sql, 'from "fiscal_years"'),
        ));

        $this->assertLessThanOrEqual(2, $yearLookups, 'the fiscal year is being looked up per row');
    }

    /**
     * The five aggregates are read once per run, however often the page asks.
     *
     * Asserted on the model rather than through the page, because that is where the memo lives and the
     * page is only one of its callers — the lock confirmation asks for the same figures.
     */
    public function test_the_totals_are_read_once_per_run(): void
    {
        $run = PayrollRun::forMonth('August', $this->fiscalYear);

        $first = [];
        DB::listen(function ($query) use (&$first): void {
            $first[] = $query->sql;
        });

        $run->totals();
        $afterFirst = count($first);

        $run->totals();
        $run->totals();

        $this->assertSame(
            $afterFirst,
            count($first),
            'asking a second time ran more queries, so the totals are not being remembered',
        );
    }
}
