<?php

namespace App\Modules\ConstructionCosting\Notifications;

use App\Modules\ConstructionCosting\Models\Reconciliation;
use App\Support\Contracts\CategorisedNotification;
use App\Support\TemplatedMail;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * The two ledgers disagree — `docs/construction-management-plan.md` §4.3.
 *
 * **The cause is in the notification, not just the difference.** §4.2's argument for the drill-down applies with more
 * force to a message somebody reads on a phone: "the difference is 412,900" is a fact they can do nothing with, and
 * "412,900, all of it burden charged with no absorption account" is a fact they can fix before lunch.
 *
 * Database and mail both, like every other notification in this suite. A month-end difference is exactly the thing
 * somebody needs to see when they are not at their desk.
 */
class ReconciliationUnbalanced extends Notification implements CategorisedNotification
{
    use Queueable;

    public function __construct(public Reconciliation $reconciliation) {}

    /** @return array<int, string> */
    public function notificationCategory(): string
    {
        return 'monitoring';
    }

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $month = $this->reconciliation->period_start->format('F Y');

        $mail = (new MailMessage)
            ->subject("Job cost does not reconcile for {$month}")
            ->greeting("{$month} does not reconcile.")
            ->line('General ledger cost '.number_format((float) $this->reconciliation->gl_cost, 2)
                .', less '.number_format((float) $this->reconciliation->unallocated_gl_cost, 2)
                .' carrying no job, plus '.number_format((float) $this->reconciliation->pending_job_cost, 2)
                .' of job cost still awaiting the general ledger, gives an expected '
                .number_format((float) $this->reconciliation->expected_job_cost, 2).'.')
            ->line('Job cost recorded: '.number_format((float) $this->reconciliation->job_cost, 2)
                .'. **Difference '.number_format((float) $this->reconciliation->difference, 2).'.**');

        foreach ($this->causes() as $sentence) {
            $mail->line($sentence);
        }

        $mail
            // Nothing has been adjusted, and saying so matters: §4.3's fourth mechanism is that a forced close never
            // fudges the ledger, and somebody reading this should not assume it has already been dealt with.
            ->line('Nothing has been adjusted. Both ledgers stay as they are until the cause is fixed or somebody '
                .'accepts the difference with a stated reason.')
            ->line('Cost periods → Reconciliation shows the full breakdown.');

        return TemplatedMail::wrap($mail, 'reconciliation_unbalanced', [
            'period' => $month,
            'difference' => number_format((float) $this->reconciliation->difference, 2),
            'job_cost' => number_format((float) $this->reconciliation->job_cost, 2),
            'gl_cost' => number_format((float) $this->reconciliation->gl_cost, 2),
            'company' => \App\Modules\Core\Models\Company::current()?->name,
        ]);
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'reconciliation_id' => $this->reconciliation->getKey(),
            'period_start' => $this->reconciliation->period_start->toDateString(),
            'difference' => (float) $this->reconciliation->difference,
            'title' => 'Job cost does not reconcile for '.$this->reconciliation->period_start->format('F Y'),
            'body' => 'Difference '.number_format((float) $this->reconciliation->difference, 2)
                .'. '.(($this->causes()[0] ?? 'No named cause accounts for it.')),
        ];
    }

    /**
     * The causes that carry a figure, largest first, as sentences.
     *
     * @return array<int, string>
     */
    private function causes(): array
    {
        return collect($this->reconciliation->causeRows())
            ->filter(fn (array $row): bool => (int) ($row['count'] ?? 0) > 0)
            ->sortByDesc(fn (array $row): float => abs((float) ($row['amount'] ?? 0)))
            ->map(fn (array $row): string => $row['label'].': '.$row['count'].' item(s)'
                .(abs((float) ($row['amount'] ?? 0)) >= 0.01
                    ? ' worth '.number_format((float) $row['amount'], 2)
                    : '')
                .'. '.$row['explanation'])
            ->values()
            ->all();
    }
}
