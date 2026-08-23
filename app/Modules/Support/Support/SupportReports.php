<?php

namespace App\Modules\Support\Support;

use App\Modules\Support\Models\Ticket;
use App\Modules\Support\Services\TicketService;
use App\Support\Reporting\ReportShapes;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * The helpdesk's two reports — `docs/reports-expansion-plan.md` Phase 1.3.
 *
 * `TicketService::performance()` and `breaches()` were both implemented, both tested, and called from
 * nothing a user could reach. The plan's phrase for that is "report-grade computation exists and is
 * unreachable", and these two are the clearest case of it in the application: an SLA that is measured and
 * never shown is a commitment nobody can be held to.
 *
 * **A report and its exception list, not two reports.** *Performance* says what proportion of the month's
 * tickets met their commitment; *breaches* names the ones that did not, while there is still something to
 * do about them. The first is read at a month end and the second every morning, which is why they are
 * separate screens rather than one screen with a filter.
 *
 * **The clocks are reported, never enforced.** That is the module's own rule — `TicketCategory`'s SLA
 * columns are minutes, not deadlines, and nothing in this application refuses an action because a clock
 * ran out. Both reports say so on their face, because a percentage that looks like a penalty invites
 * somebody to close tickets to improve it.
 */
class SupportReports
{
    use ReportShapes;

    /**
     * Met against missed for the month, by category and then by person.
     *
     * **The month the date falls in**, because an SLA is read at a month end and quoted in a monthly
     * review. The fiscal year to date would average a bad week into forty others and hide it, which is
     * the opposite of what a commitment report is for.
     *
     * Two groupings in one table, in that order and deliberately. The commitment belongs to the
     * *category* — it is the only thing in the schema carrying an `sla_*_minutes` figure — so the category
     * rows are the report and the assignee rows are the diagnosis. Reversing them would invite a rate per
     * person to be read as a ranking, when somebody working the urgent queue is measured against a
     * tighter clock than somebody working the general one.
     */
    public function slaPerformance(string $asOf): array
    {
        $from = Carbon::parse($asOf)->startOfMonth()->toDateString();
        $to = Carbon::parse($asOf)->endOfMonth()->toDateString();

        $tickets = app(TicketService::class);
        $byCategory = $tickets->performance($from, $to);
        $byAssignee = $tickets->performanceByAssignee($from, $to);

        $rows = [];

        foreach (['By category' => $byCategory, 'By assignee' => $byAssignee] as $heading => $group) {
            foreach ($group as $row) {
                $rows[] = [
                    $heading.' · '.$row['label'],
                    number_format($row['tickets']),
                    $this->rate($row['met_response'], $row['tickets']),
                    $this->rate($row['met_resolution'], $row['tickets']),
                    number_format($row['reopened']),
                    // Null is not nought. An average of no ratings would report a team as hated when it
                    // was simply never asked.
                    $row['satisfaction'] === null ? '—' : number_format($row['satisfaction'], 1),
                ];
            }
        }

        // Off the category rows only. Summing both groupings would count every ticket twice, which is the
        // arithmetic trap in putting two groupings in one table.
        $total = array_sum(array_column($byCategory, 'tickets'));
        $metResponse = array_sum(array_column($byCategory, 'met_response'));
        $metResolution = array_sum(array_column($byCategory, 'met_resolution'));

        return $this->table(
            'SlaPerformance',
            'SLA Performance',
            $this->subtitle('tickets opened between '.$from.' and '.$to),
            ['Bucket', 'Tickets', 'Response met', 'Resolution met', 'Reopenings', 'Satisfaction'],
            'minmax(0, 1fr) 7rem 8rem 8rem 8rem 8rem',
            [1, 2, 3, 4, 5],
            $rows,
            [
                ['label' => 'TICKETS', 'value' => (float) $total, 'accent' => true],
                // The count, not the rate: a tile is a figure and a rate belongs in the note, where the
                // qualification about the clocks being reported rather than enforced can sit beside it.
                ['label' => 'MISSED RESOLUTION', 'value' => (float) ($total - $metResolution), 'accent' => false],
            ],
            $total === 0
                ? 'NO TICKETS WERE OPENED IN THIS MONTH'
                : mb_strtoupper(sprintf(
                    'response met %s · resolution met %s · reported, not enforced',
                    $this->rate($metResponse, $total),
                    $this->rate($metResolution, $total),
                )),
            [
                'Total — '.$total.' tickets',
                number_format($total),
                $this->rate($metResponse, $total),
                $this->rate($metResolution, $total),
                number_format(array_sum(array_column($byCategory, 'reopened'))),
                '',
            ],
            'No ticket was opened in this month.',
        );
    }

    /**
     * The open tickets that have missed a commitment, or are about to.
     *
     * **Open only, and that is the point.** `TicketService::breaches()` counts a ticket nobody has
     * answered yet whose time is already up, not only one answered late — a breach that has not finished
     * happening is the one still worth acting on. A closed ticket's breach is history and belongs in the
     * performance figures above, not in a list somebody works through.
     */
    public function slaBreaches(string $asOf): array
    {
        /** @var Collection<int, array{ticket: Ticket, note: string}> $breaches */
        $breaches = app(TicketService::class)->breaches();

        return $this->table(
            'SlaBreaches',
            'SLA Breaches',
            $this->subtitle('open tickets, as at '.$asOf),
            ['Ticket', 'Category', 'Assignee', 'What was missed', 'Age'],
            '9rem 10rem 10rem minmax(0, 1fr) 7rem',
            [4],
            $breaches->map(fn (array $row): array => [
                (string) $row['ticket']->number,
                (string) ($row['ticket']->category?->name ?? 'Uncategorised'),
                // Unassigned is stated rather than blanked: a breached ticket with nobody on it is the
                // worst row in this table and a blank cell reads as missing data.
                (string) ($row['ticket']->assignee?->display_label ?? 'Unassigned'),
                $row['note'],
                $this->age($row['ticket']),
            ])->all(),
            [
                ['label' => 'IN BREACH', 'value' => (float) $breaches->count(), 'accent' => true],
                ['label' => 'UNASSIGNED', 'value' => (float) $breaches
                    ->filter(fn (array $row): bool => $row['ticket']->assignee_employee_id === null)
                    ->count(), 'accent' => false],
            ],
            $breaches->isEmpty()
                ? 'EVERY OPEN TICKET IS INSIDE ITS COMMITMENT'
                : mb_strtoupper($breaches->count().' open tickets past a commitment · reported, not enforced'),
            null,
            'Every open ticket is inside its commitment.',
        );
    }

    /** A proportion, or a dash where there is nothing to take a proportion of. */
    private function rate(int $met, int $of): string
    {
        return $of === 0 ? '—' : number_format($met / $of * 100, 1).'%';
    }

    /** How long the ticket has been open, in the unit that reads. */
    private function age(Ticket $ticket): string
    {
        if ($ticket->opened_at === null) {
            return '—';
        }

        $hours = (int) $ticket->opened_at->diffInHours(now());

        // Days past two of them: "97h" is a number somebody has to divide, and the point of the column is
        // to be read at a glance.
        return $hours >= 48 ? round($hours / 24).'d' : $hours.'h';
    }
}
