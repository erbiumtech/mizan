<?php

namespace App\Health;

use App\Modules\Core\Models\Company;
use App\Support\LedgerControls;
use Spatie\Health\Checks\Check;
use Spatie\Health\Checks\Result;
use Throwable;

/**
 * Do the control accounts agree with the documents behind them? — `docs/erpnext-gap-plan.md` Phase 2.
 *
 * Ageing here iterates invoices and the trial balance reads the ledger, so a journal entry posted straight
 * at Receivables moves one and not the other — and nothing notices. ERPNext avoids this by putting a party
 * on the journal *line*, so its write-off is in Accounts Receivable automatically. Rebuilding ageing that
 * way was refused: it means a party on every line, which means every posting path again, for a report that
 * already works. What was actually broken is that the two figures could disagree *silently*, and this is
 * the fix for that — the disagreement is now loud.
 *
 * **Per company, because the ledger is.** Every other check in this directory asks a question about the
 * installation; this one asks the same question forty times, once per set of books. `TenantDatabaseCheck`
 * established that shape and the reason: in a database-per-company application, anything that says "the
 * ledger" is asking the wrong question.
 *
 * **Hourly, not every minute.** `health:check` runs every minute because the checks beside this one are a
 * PDO connect and a disk stat. This one sums a company's ledger, and a reconciliation drift discovered
 * fifty-nine minutes late is discovered in time. The gate is a run condition rather than a separate command
 * so the result still appears with every other check on one page.
 *
 * **Which controls exist is not this class's business.** `App\Support\LedgerControls` is a registry: the
 * module that owns a control account and the documents behind it registers the pair, and a company without
 * Invoicing has no receivables to reconcile and therefore nothing to check.
 */
class LedgerControlsCheck extends Check
{
    /**
     * What counts as agreement.
     *
     * Not zero: both sides round to two places and a company with a thousand invoices in three currencies
     * will differ by a rupee or two through rounding alone. A check that fires on that gets muted, and a
     * muted check is worse than none — while a real divergence is a whole invoice or a whole write-off,
     * which is orders of magnitude above this.
     */
    protected float $tolerance = 1.0;

    public function tolerance(float $tolerance): self
    {
        $this->tolerance = $tolerance;

        return $this;
    }

    public function run(): Result
    {
        $companies = Company::query()->orderBy('id')->get(['id', 'slug', 'database']);

        if ($companies->isEmpty()) {
            return Result::make()->ok('No companies yet.');
        }

        $checked = 0;
        $diverged = [];
        $unreadable = [];

        foreach ($companies as $company) {
            try {
                $rows = $this->controlsFor($company);
            } catch (Throwable $e) {
                // A company whose books cannot be read is `TenantDatabaseCheck`'s finding, not this one's.
                // Recorded rather than raised, so one broken database does not hide forty good comparisons.
                $unreadable[] = $company->slug;

                continue;
            }

            $checked++;

            foreach ($rows as $row) {
                if (abs($row['difference']) <= $this->tolerance) {
                    continue;
                }

                $diverged[] = sprintf(
                    '%s %s: ledger %s, documents %s (out by %s)',
                    $company->slug,
                    mb_strtolower($row['label']),
                    number_format($row['ledger'], 2),
                    number_format($row['documents'], 2),
                    number_format($row['difference'], 2),
                );
            }
        }

        $result = Result::make()
            ->meta([
                'companies_checked' => $checked,
                'controls' => LedgerControls::labels(),
                'diverged' => $diverged,
                'unreadable' => $unreadable,
            ])
            ->shortSummary(count($diverged).' out');

        if ($checked === 0) {
            return $result->ok('No company books could be read; see the tenant database check.');
        }

        if ($diverged === []) {
            return $result->ok("Control accounts agree with their documents in all {$checked} companies.");
        }

        // Failed rather than warned, and deliberately: a control account that disagrees with its subledger
        // is either a posting nobody meant to make or a report understating what the company is owed. Both
        // are things somebody has to look at, and neither improves by waiting.
        return $result->failed(implode('; ', $diverged));
    }

    /**
     * The comparison for one company, with its books current.
     *
     * `execute()` restores whatever was current afterwards, which matters because a health check may run
     * inside a request that already had a tenant — leaving the wrong company connected would be a data-leak
     * shaped bug caused by a monitoring tool, which is the same trap `TenantDatabaseCheck` documents.
     *
     * A single-database installation — and the test suite — has no tenant connection to switch, so the
     * comparison runs against whatever is current. That is the correct answer there rather than a skip: the
     * books are the books.
     *
     * @return array<int, array<string, mixed>>
     */
    private function controlsFor(Company $company): array
    {
        if (blank(config('multitenancy.tenant_database_connection_name'))) {
            return LedgerControls::compare();
        }

        return $company->execute(fn (): array => LedgerControls::compare());
    }
}
