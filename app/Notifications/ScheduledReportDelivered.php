<?php

namespace App\Notifications;

use App\Modules\Core\Models\Company;
use App\Support\Reporting\ReportDeliveryService;
use App\Support\TemplatedMail;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * A scheduled report, by email — `docs/reports-expansion-plan.md` Phase 8, item 7.
 *
 * > **The email**, through `EmailTemplate` where the company has one, with the file attached exactly as
 * > `PayslipIssued` does it — **and a link to the live report in the body**, so a recipient who wants to drill
 * > in lands in the application and is authorised there. A size cap, with the attachment replaced by a link
 * > when it is exceeded.
 *
 * **The rendered files are carried, not the schedule's id, which is the opposite of `PayslipIssued`.** That
 * one deliberately carries an id and renders at send time, because a payslip is a document about a row that
 * may have changed. This is the other case: the render is the *point at which* the report was true, item 4
 * has already recorded that one delivery happened for this period, and re-rendering per recipient would run
 * a heavy report once per person and risk five recipients receiving five different numbers.
 *
 * Which is why this is **not** `ShouldQueue`: the job that produced the files is already queued, and queueing
 * the notification too would put a few megabytes of base64 into the queue payload — paid for again on every
 * retry — to move work that is already off the request.
 *
 * **The link is the same report.** Item 1 stores the filter state the URL carries, so the link in the body
 * opens exactly what is attached, authorised as whoever clicks it rather than as the schedule's owner.
 */
class ScheduledReportDelivered extends Notification
{
    use Queueable;

    /**
     * @param  array<int, array{name: string, mime: string, data: string}>  $files
     */
    public function __construct(
        public string $title,
        public string $subtitle,
        public string $period,
        public array $files,
        public ?string $link = null,
    ) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        // Mail only. A recipient may be an address with no account here — item 2's external case — so there
        // is nothing to write a database notification against.
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $company = Company::current()?->name;
        $tooLarge = $this->tooLarge();

        $lines = [
            $this->title.' for '.mb_strtolower($this->period).' is attached.',
            $this->subtitle,
        ];

        if ($tooLarge) {
            // Replaced rather than trimmed: half a report is not a smaller report. The link is the whole one.
            $lines = [
                $this->title.' for '.mb_strtolower($this->period).' is ready.',
                'It is too large to send as an attachment, so it is not attached — open it in the application instead.',
            ];
        }

        $mail = TemplatedMail::apply(
            new MailMessage,
            'report_delivered',
            [
                'report' => $this->title,
                'period' => $this->period,
                'company' => $company,
            ],
            subject: $this->title.' — '.$this->period,
            greeting: 'Hello,',
            lines: array_values(array_filter($lines)),
        );

        if ($this->link !== null) {
            $mail->action('Open '.$this->title, $this->link);
        }

        if (! $tooLarge) {
            foreach ($this->files as $file) {
                $mail->attachData($file['data'], $file['name'], ['mime' => $file['mime']]);
            }
        }

        return $mail;
    }

    /** Whether what would be attached is more than a mail server will take — see the cap's own note. */
    public function tooLarge(): bool
    {
        $bytes = array_sum(array_map(fn (array $file): int => strlen($file['data']), $this->files));

        return $bytes > ReportDeliveryService::MAX_ATTACHMENT_BYTES;
    }
}
