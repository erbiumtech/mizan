<?php

namespace App\Support\Reporting;

use App\Modules\Core\Models\ReportDelivery;
use Illuminate\Support\Carbon;

/**
 * A report of the reports — `docs/reports-expansion-plan.md` Phase 8, item 8.
 *
 * > **A delivery log people can read** — a report of the reports: what went out, to whom, when, and what
 * > failed.
 *
 * **A report rather than a resource, and that is the cheap answer as well as the right one.** Everything this
 * needs already exists: `ReportShapes::table()` for the payload, the hub for the door, Phase 4's export for
 * the copy somebody sends on, and the `ReportView` gate every other report is behind. A Filament resource
 * would have added a policy, a form nobody should fill in, and a second place to explain what a delivery is.
 *
 * **Ninety days.** A log people read is read for "did Monday's go out" rather than for last year, and an
 * unbounded one grows a row per schedule per period for ever. The span is stated in the note so nobody reads
 * an absence as a failure.
 */
class ReportDeliveryLog
{
    use ReportShapes;

    /** How far back the log looks. */
    public const DAYS = 90;

    /** And how many rows it will draw, for the same reason Phase 6.5 has a ceiling: this is read on a screen. */
    public const MAX_ROWS = 500;

    /**
     * The log, newest first.
     *
     * @return array<string, mixed>
     */
    public function for(string $asOf): array
    {
        $to = Carbon::parse($asOf);
        $from = $to->copy()->subDays(self::DAYS)->startOfDay();

        $deliveries = ReportDelivery::query()
            ->with('schedule')
            ->where('created_at', '>=', $from)
            ->where('created_at', '<', $to->copy()->addDay()->startOfDay())
            ->recent()
            ->limit(self::MAX_ROWS)
            ->get();

        $reports = app(ReportDeliveryService::class);

        $rows = $deliveries->map(function (ReportDelivery $delivery) use ($reports): array {
            $schedule = $delivery->schedule;

            return [
                $schedule === null ? '—' : $reports->titleFor($schedule),
                (string) $delivery->period_key,
                ReportDelivery::STATUSES[$delivery->status] ?? (string) $delivery->status,
                $this->recipientSummary($delivery),
                $delivery->sent_at?->format('j M Y H:i') ?? $delivery->created_at?->format('j M Y H:i') ?? '—',
                // What failed, or why nothing was sent. The one column somebody opens this report for.
                (string) ($delivery->error ?? ''),
            ];
        })->all();

        $sent = $deliveries->where('status', ReportDelivery::STATUS_SENT)->count();
        $failed = $deliveries->where('status', ReportDelivery::STATUS_FAILED)->count();

        return [
            ...$this->table(
                'ReportDeliveries',
                'Report Deliveries',
                $this->subtitle($from->format('j M Y').' to '.$to->format('j M Y')),
                ['Report', 'Period', 'Status', 'To', 'When', 'What happened'],
                'minmax(0, 1fr) 14rem 7rem 6rem 10rem minmax(0, 1.2fr)',
                [3],
                $rows,
                [
                    ['label' => 'SENT', 'value' => (float) $sent, 'accent' => true],
                    ['label' => 'FAILED', 'value' => (float) $failed, 'accent' => false],
                ],
                mb_strtoupper($failed > 0
                    ? $failed.' of '.$deliveries->count().' deliveries failed in the last '.self::DAYS.' days'
                    : 'last '.self::DAYS.' days · '.$deliveries->count().' deliveries'),
                $rows === [] ? null : ['Total — '.count($rows).' deliveries', '', '', '', '', ''],
                'Nothing has been sent yet. A schedule needs cron and a queue worker — see the help.',
                wide: true,
            ),
            // A log with a failure in it is a log that does not add up, and the pane draws that note in
            // warning colour — which is most of the reason to show this report to anybody.
            'balanced' => $failed === 0,
        ];
    }

    /**
     * Who it went to, as a count with the refusals visible.
     *
     * "3 of 5" is the shape that matters: item 2 re-authorises recipients at send time, so a delivery going
     * to fewer people than the schedule names is *correct* behaviour that somebody still needs to see.
     */
    private function recipientSummary(ReportDelivery $delivery): string
    {
        $recipients = (array) ($delivery->recipients ?? []);

        if ($recipients === []) {
            return '—';
        }

        $sent = count(array_filter($recipients, fn (mixed $recipient): bool => (bool) (((array) $recipient)['sent'] ?? false)));

        return $sent.' of '.count($recipients);
    }
}
