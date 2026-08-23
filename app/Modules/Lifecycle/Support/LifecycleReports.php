<?php

namespace App\Modules\Lifecycle\Support;

use App\Modules\Employees\Models\Employee;
use App\Modules\Leave\Models\LeaveType;
use App\Modules\Lifecycle\Models\EmployeeDocument;
use App\Modules\Lifecycle\Services\DocumentExpiryCheck;
use App\Modules\Lifecycle\Services\FinalSettlementBuilder;
use App\Support\Reporting\ReportShapes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Lifecycle's two reports: what is about to lapse, and what unused leave would cost.
 *
 * **Documents Expiring** — `docs/reports-expansion-plan.md` Phase 1.5.
 *
 * `DocumentExpiryCheck` had exactly one caller: the daily command that mails a warning. So the state of
 * every visa, licence and contract in the company was *knowable* and never *viewable* — the plan's words
 * for it are "compliance-critical and currently only ever emailed", and a compliance fact that exists only
 * in somebody's inbox is a compliance fact nobody can audit.
 *
 * **Built on `expiring()` rather than `due()`, and that is the load-bearing decision in this file.**
 * `due()` is the notification query: it suppresses a document once its threshold has been warned at, so
 * that a daily job does not mail the same person the same warning for thirty days. A report built on it
 * would have shown *fewer* documents the more reliably the reminders went out — emptiest on the company
 * that had been most diligent, and silent about why. `expiring()` is the listing: everything inside the
 * window, warned about or not.
 *
 * **Leave Liability** — Phase 2.2, and it lives here rather than in Leave for one reason: the figure is
 * whatever a final settlement would pay out, and `FinalSettlementBuilder` is Lifecycle's. Putting the report
 * beside the calculation is what keeps the accrual and the settlement from being two numbers. It also needs
 * no new module edge — `lifecycle -> leave` already exists for exactly this calculation.
 */
class LifecycleReports
{
    use ReportShapes;

    /**
     * What unused encashable leave would cost if everybody left today — Phase 2.2.
     *
     * **This report ties to nothing, and that is the finding rather than a shortcoming.** Phase 2's opening
     * rule is that "every one of them carries a record row that ties to a ledger balance", and Phase 2.2's
     * own text says why this one cannot: the accrual "belongs in the accounts and is currently in nobody's
     * figures". There is no leave-liability account in `config/accounting.php`'s payroll mapping and nothing
     * posts one, so there is no balance to tie to. The report says so on its face. Inventing a comparison
     * against an account that does not hold this would have been worse than having none.
     *
     * **The figure is `FinalSettlementBuilder::leaveEncashment()`, not a formula of this report's own**, and
     * that is the decision worth defending. A leave liability is *what the company would have to pay*, so
     * the only defensible definition is the one the settlement actually uses — same encashable types, same
     * positive-balance-only rule, same `statutory.encashment_divisor`. A second formula here would drift
     * from the first, and the drift would show up as a settlement that did not match the accrual it had been
     * provided for. Phase 2's own risk list names that failure: "a plausible number that is wrong".
     *
     * **The cost is a handful of queries per employee, accepted deliberately.** `LeaveBalance::for()` answers
     * per employee per type and reaches entitlements, leave days and adjustments; over a headcount that is
     * a few hundred queries for a report one person opens at a month end. The alternative was a batched
     * re-implementation — a second formula, which is the thing this method exists to avoid. If it ever bites,
     * the fix belongs in `LeaveBalance` as a bulk method that the single-employee one also calls, so there is
     * still one implementation.
     */
    public function leaveLiability(string $asOf): array
    {
        // Illuminate's Carbon, not Carbon's own. `leaveEncashment()` hints the Laravel subclass and an
        // instance of the parent is not an instance of the child — the same mistake Phase 1.7 recorded and
        // this file made again a day later, because the shorter import is the one muscle memory reaches for.
        $date = Carbon::parse($asOf);
        $builder = app(FinalSettlementBuilder::class);

        // Guarded, like `leaveEncashment()` itself is: Lifecycle requires `employees` and not `leave`, so a
        // company can own this report and not the module behind it. Without leave there is no encashable
        // type and therefore no liability — which is a real answer and not an empty screen.
        $encashable = modules()->enabled('leave')
            ? LeaveType::query()
                ->where('is_encashable', true)
                ->where('is_active', true)
                ->orderBy('sort')
                ->pluck('label')
            : collect();

        $rows = [];
        $days = 0.0;
        $liability = 0.0;
        $unpriced = 0;

        foreach ($this->employeesInService($date) as $employee) {
            $encashment = $builder->leaveEncashment($employee, $date);

            // Nothing owed is not a row. A liability report listing everybody with nought against most of
            // them buries the handful that matter, and the headcount is on the note either way.
            if ($encashment['days'] <= 0 && $encashment['amount'] <= 0) {
                continue;
            }

            /*
             * Days owed but no amount means no wage was recorded, and the honest cell is a dash in both
             * money columns rather than a nought in either.
             *
             * `leaveEncashment()` returns 0.0 for somebody with no salary package — correctly, since it has
             * no rate to multiply by — and printing that as `0` would say those days are worth nothing.
             * They are worth an amount nobody has recorded the wage to compute, which is a data problem
             * somebody should fix and not a liability of nil. The count goes on the note so the total is
             * never read as complete when it is not.
             */
            $priced = $encashment['days'] > 0 && $encashment['amount'] > 0;

            if (! $priced) {
                $unpriced++;
            }

            $rows[] = [
                (string) $employee->display_label,
                number_format($encashment['days'], 1),
                // Derived rather than asked for separately: amount over days is the rate that was actually
                // used, so a reader checking one row's arithmetic gets the same answer the settlement would.
                $priced ? number_format($encashment['amount'] / $encashment['days'], 0) : '—',
                $priced ? number_format($encashment['amount'], 0) : '—',
            ];

            $days += $encashment['days'];
            $liability += $encashment['amount'];
        }

        return $this->table(
            'LeaveLiability',
            'Leave Liability',
            $this->subtitle('unused encashable leave as at '.$asOf),
            ['Employee', 'Days', 'Daily rate', 'Liability'],
            'minmax(0, 1fr) 8rem 10rem 12rem',
            [1, 2, 3],
            $rows,
            [
                ['label' => 'LIABILITY', 'value' => round($liability, 2), 'accent' => true],
                ['label' => 'DAYS', 'value' => round($days, 1), 'accent' => false],
            ],
            $this->liabilityNote($rows, $encashable, $unpriced),
            $rows === [] ? null : [
                'Total — '.count($rows).' employees',
                number_format($days, 1),
                '',
                number_format($liability, 0),
            ],
            $encashable->isEmpty()
                ? 'No leave type is marked encashable, so unused leave lapses and costs nothing.'
                : 'Nobody has unused encashable leave as at this date.',
        );
    }

    /**
     * What the liability figure means, and the one thing a reader must be told about it.
     *
     * That it is in no account. A provision nobody has posted is exactly as real as one that has been, and
     * the difference is only that the balance sheet does not know — which is the fact this report exists to
     * deliver, so it goes on the face rather than in the help.
     *
     * @param  array<int, array<int, string>>  $rows
     * @param  Collection<int, string>  $encashable
     * @param  int  $unpriced  employees with days owed and no recorded wage to value them at
     */
    private function liabilityNote(array $rows, Collection $encashable, int $unpriced = 0): string
    {
        if ($encashable->isEmpty()) {
            // A different answer from "nobody has any". Nothing is encashable, so there is no liability to
            // compute at all, and a nought here would read as a company that happens to be up to date.
            return 'NO LEAVE TYPE IS ENCASHABLE, SO UNUSED LEAVE LAPSES';
        }

        if ($rows === []) {
            return mb_strtoupper('nobody has unused '.$encashable->implode(', ').' · not posted to any account');
        }

        return mb_strtoupper(implode(' · ', array_filter([
            count($rows).' employees',
            $encashable->implode(', '),
            // Said out loud, because the total is short by however much these are worth and nothing else
            // on the screen would tell a reader that.
            $unpriced > 0
                ? $unpriced.($unpriced === 1 ? ' has' : ' have').' no recorded wage, so the total is incomplete'
                : null,
            'not posted to any account',
        ])));
    }

    /**
     * Who this is a liability for.
     *
     * Still in service at the date: somebody who has left has either been settled — in which case the money
     * is a payable and not a provision — or has not, in which case it is a debt rather than an accrual. Both
     * are different reports.
     *
     * @return Collection<int, Employee>
     */
    private function employeesInService(Carbon $date): Collection
    {
        return Employee::query()
            ->where('is_active', true)
            ->where(fn ($query) => $query
                ->whereNull('left_on')
                ->orWhereDate('left_on', '>', $date->toDateString()))
            ->with('user')
            ->orderBy('employee_id')
            ->get();
    }

    /**
     * Every document expiring inside the reminder window, soonest first.
     *
     * **A snapshot, so it takes no period.** "What lapses soon" is a question about now, and a from-and-to
     * would invite somebody to ask it of last March, where the answer would be a list of things that have
     * since been renewed.
     *
     * The window is the widest configured reminder threshold, so this report covers exactly the population
     * the daily mail watches. A company that warns at 90 days gets a 90-day report without touching this
     * file.
     */
    public function documentsExpiring(string $asOf): array
    {
        /** @var Collection<int, array{document: EmployeeDocument, days: int, bucket: string}> $rows */
        $rows = app(DocumentExpiryCheck::class)->expiring($asOf);

        $expired = $rows->filter(fn (array $row): bool => $row['days'] < 0);

        return $this->table(
            'DocumentsExpiring',
            'Documents Expiring',
            $this->subtitle('as at '.$asOf),
            ['Employee', 'Document', 'Number', 'Expires', 'Days', 'Status'],
            'minmax(0, 1fr) 9rem 11rem 8rem 6rem 10rem',
            [4],
            $rows->map(fn (array $row): array => [
                (string) ($row['document']->employee?->display_label ?? 'Employee #'.$row['document']->employee_id),
                // The kind is a lowercase enum value in the column; nobody wants to read "cnic".
                (string) str($row['document']->kind)->replace('_', ' ')->title(),
                // A document with no number recorded is stated as such rather than left blank, because a
                // blank cell in a compliance list reads as a rendering fault.
                (string) ($row['document']->number ?: 'Not recorded'),
                (string) $row['document']->expires_on?->toDateString(),
                // Negative days are an overdue count, not a countdown, so they are shown as one.
                $row['days'] < 0 ? abs($row['days']).' ago' : number_format($row['days']),
                $row['bucket'],
            ])->all(),
            [
                ['label' => 'EXPIRING', 'value' => (float) $rows->count(), 'accent' => true],
                // Its own tile because it is a different problem: everything else on this list is a
                // deadline, and these are already past.
                ['label' => 'ALREADY EXPIRED', 'value' => (float) $expired->count(), 'accent' => false],
            ],
            $rows->isEmpty()
                ? 'NOTHING LAPSES INSIDE THE REMINDER WINDOW'
                : mb_strtoupper($expired->isEmpty()
                    ? $rows->count().' documents lapse soon · none expired yet'
                    : $expired->count().' of '.$rows->count().' have already expired'),
            null,
            'Nothing lapses inside the reminder window.',
        );
    }
}
