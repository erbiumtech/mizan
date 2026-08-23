<?php

namespace App\Modules\Lifecycle\Support;

use App\Modules\Lifecycle\Models\EmployeeDocument;
use App\Modules\Lifecycle\Services\DocumentExpiryCheck;
use App\Support\Reporting\ReportShapes;
use Illuminate\Support\Collection;

/**
 * Documents about to lapse, as a list — `docs/reports-expansion-plan.md` Phase 1.5.
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
 */
class LifecycleReports
{
    use ReportShapes;

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
