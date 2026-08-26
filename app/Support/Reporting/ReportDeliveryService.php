<?php

namespace App\Support\Reporting;

use App\Modules\Core\Filament\Pages\Reports;
use App\Modules\Core\Models\Company;
use App\Modules\Core\Models\ReportDefinition;
use App\Modules\Core\Models\ReportDelivery;
use App\Modules\Core\Models\ReportSchedule;
use App\Modules\Core\Models\User;
use App\Notifications\ScheduledReportDelivered;
use App\Notifications\ScheduledReportFailed;
use App\Support\Pdf\PdfDocument;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Notification;
use Throwable;

/**
 * Sending a report on a timetable — `docs/reports-expansion-plan.md` Phase 8, items 2, 3, 4, 6 and 7.
 *
 * **Item 2 is the whole of this class, and it is worth restating why.** An emailed report leaves the
 * application's authorization behind: nobody has to log in to read it, and nothing in the app records who
 * saw it. So:
 *
 *  - **the render runs as the schedule's owner**, whose access decides what the rows are — `renderAs()` sets
 *    the authenticated user before asking for the payload, because every gate in this application, from
 *    module licensing down to `EmployeeAccess`, reads `auth()->user()`. A console command has no user, so
 *    without this a scheduled report would either render as nobody or, worse, render as everybody;
 *  - **the recipient list is re-authorised at send time, not at schedule time** — a person whose role
 *    changed or who left the company is the ordinary case, not an edge one, and a schedule written a year
 *    ago would otherwise keep posting the payroll register to them;
 *  - **external recipients need their own permission** and are recorded on every delivery;
 *  - **a schedule whose owner loses access is suspended**, not silently rendered with fewer rows. A report
 *    that quietly shrinks is worse than one that stops: nobody notices the first.
 *
 * **Item 4's idempotency is a row written before the work, not after it.** `claim()` inserts the delivery and
 * lets the unique index refuse a second one, so the window between the mail leaving and the row landing —
 * which is where a duplicate send lives — does not exist. A failure keeps its row and its attempt count, so a
 * retry is the same period rather than a new one.
 *
 * **Item 6 is Phase 4's export, unchanged.** `ReportExport` produces the grid and `PdfDocument` renders the
 * same `reports.pane-export` template the download button uses, including the per-engine overrides. A second
 * renderer here would be a second answer to what the report says, and the whole reason Phase 8 waited for
 * Phase 4 was to avoid growing one.
 */
class ReportDeliveryService
{
    /**
     * How large an attachment may be before the email carries a link instead — item 7.
     *
     * "A size cap, with the attachment replaced by a link when it is exceeded: a 40 MB PDF does not fail in
     * this application, it fails at somebody's mail server, hours later, silently." Eight megabytes, because
     * that is under every common inbox limit once base64 has added its third — and the failure this prevents
     * is not ours to see, which is exactly why it has to be a number we choose rather than one we discover.
     */
    public const MAX_ATTACHMENT_BYTES = 8 * 1024 * 1024;

    /**
     * The schedules due at this instant.
     *
     * Cron is evaluated in PHP rather than in SQL, which is why this reads the active rows and filters them:
     * there is no SQL for "does this expression fire now", and the table is one row per schedule per company.
     * Item 5's shape — one scheduler entry that asks which rows are due — is the `CheckEnvironmentsHealth`
     * pattern and the reason a thousand schedules still need one entry.
     *
     * @return EloquentCollection<int, ReportSchedule>
     */
    public function due(?Carbon $at = null, int $windowMinutes = 15): EloquentCollection
    {
        $at ??= now();

        return ReportSchedule::query()
            ->active()
            ->get()
            ->filter(fn (ReportSchedule $schedule): bool => $schedule->isDueAt($at, $windowMinutes))
            ->values();
    }

    /**
     * Send one schedule's report for the period it is due for.
     *
     * Null when there is nothing to do: the owner is gone (and the schedule is now suspended), or this period
     * has already been delivered. Otherwise the delivery row, in whatever state it reached.
     *
     * **Throws on a rendering or mail failure, deliberately.** The row records the attempt first, and the
     * exception is what tells the queue to retry — `DeliverScheduledReport::$tries` bounds it and its
     * `failed()` hook is where the owner hears about it. Swallowing it here would make every failure silent
     * and permanent.
     */
    public function deliver(ReportSchedule $schedule, ?Carbon $at = null): ?ReportDelivery
    {
        $at ??= now();

        $owner = $schedule->owner();

        if ($owner === null) {
            $schedule->suspend('The owner no longer has an account in this company.');

            return null;
        }

        if (($refusal = $this->ownerRefusal($schedule, $owner)) !== null) {
            $schedule->suspend($refusal);

            return null;
        }

        $delivery = $this->claim($schedule, $schedule->periodKey($at));

        if ($delivery === null) {
            return null;
        }

        try {
            $payload = $this->renderAs($owner, $schedule, $at);

            if ($payload === null) {
                $schedule->suspend('The report could not be drawn for its owner. Check the module is enabled and the report still exists.');
                $delivery->markFailed('The report drew nothing for its owner.');

                return $delivery;
            }

            $delivery->forceFill(['rendered_at' => now()])->save();

            $recipients = $this->recipients($schedule, $owner);

            if ($recipients['allowed'] === []) {
                $delivery->markSkipped($recipients['recorded'], 'No recipient could be authorised at send time.');

                return $delivery;
            }

            $files = $this->files($schedule, $payload, $at);
            $link = $this->link($schedule, $at);

            foreach ($recipients['allowed'] as $recipient) {
                Notification::route('mail', $recipient['email'])->notify(
                    new ScheduledReportDelivered(
                        title: (string) ($payload['title'] ?? 'Report'),
                        subtitle: (string) ($payload['subtitle'] ?? ''),
                        period: RelativePeriod::label($schedule->period),
                        files: $files,
                        link: $link,
                    ),
                );
            }

            $delivery->markSent($recipients['recorded']);
            $schedule->forceFill(['last_run_at' => now()])->save();

            return $delivery;
        } catch (Throwable $exception) {
            $delivery->markFailed($exception->getMessage());

            throw $exception;
        }
    }

    /**
     * The delivery row for this period, or none if this period is already done.
     *
     * `firstOrCreate` plus the unique index, so two workers racing on one period produce one row — and the
     * loser of that race gets the winner's row rather than an exception, which is what makes a retry
     * continue the same attempt instead of starting a second.
     *
     * A row already `sent` or deliberately `skipped` returns null: both mean this period has been answered.
     * A `failed` or `pending` row is handed back, because that is a retry of the same send.
     */
    public function claim(ReportSchedule $schedule, string $periodKey): ?ReportDelivery
    {
        try {
            $delivery = ReportDelivery::query()->firstOrCreate(
                ['report_schedule_id' => $schedule->getKey(), 'period_key' => $periodKey],
                ['status' => ReportDelivery::STATUS_PENDING],
            );
        } catch (QueryException) {
            // The unique index refused a concurrent insert. Whoever won it owns this period.
            $delivery = ReportDelivery::query()
                ->where('report_schedule_id', $schedule->getKey())
                ->where('period_key', $periodKey)
                ->first();

            if ($delivery === null) {
                return null;
            }
        }

        return in_array($delivery->status, [ReportDelivery::STATUS_SENT, ReportDelivery::STATUS_SKIPPED], true)
            ? null
            : $delivery;
    }

    /**
     * The report, drawn as its owner would see it.
     *
     * **Two different dates, and the difference is not an inconsistency.** A coded report is "as at a date",
     * so the schedule's period sets that date to the span's *end* — "last month's aged receivables" is the
     * receivables at last month's end. A built report carries its own relative period, so the schedule's rule
     * *overrides* it and resolves against the run date — because the rule somebody set on the schedule is the
     * one they meant, and resolving a relative period against a date that is itself the end of a relative
     * period would answer the month before the one they asked for.
     *
     * @return array<string, mixed>|null
     */
    public function renderAs(User $owner, ReportSchedule $schedule, ?Carbon $at = null): ?array
    {
        $at ??= now();
        $previous = Auth::user();

        Auth::setUser($owner);

        try {
            if ($schedule->isBuiltReport()) {
                $definition = ReportDefinition::forKey($schedule->report_key);

                return $definition === null
                    ? null
                    : app(BuiltReport::class)->for($definition, $at->toDateString(), $schedule->period);
            }

            return app(ReportPaneRenderer::class)->for(
                (string) $schedule->report_key,
                $schedule->range($at)['to'],
                $schedule->comparison(),
                $schedule->asked(),
            );
        } finally {
            $previous === null ? Auth::forgetUser() : Auth::setUser($previous);
        }
    }

    /**
     * Who this may be sent to, decided now rather than when the schedule was written — item 2.
     *
     * Every address is answered with a reason, and every answer is recorded on the delivery: "left the
     * company", "no longer permitted to read reports", "external, allowed" is the audit trail somebody needs
     * six months later when a figure turns up in the wrong inbox.
     *
     * **An address matching no account here is external**, and the *owner* needs `ReportSendExternal` for it
     * — the owner, because they are the one whose access produced the rows and therefore the one choosing to
     * send them outside.
     *
     * @return array{allowed: array<int, array<string, mixed>>, recorded: array<int, array<string, mixed>>}
     */
    public function recipients(ReportSchedule $schedule, ?User $owner = null): array
    {
        $owner ??= $schedule->owner();
        $mayGoOutside = $owner !== null && $owner->can(ReportSchedule::SEND_EXTERNAL);

        $allowed = [];
        $recorded = [];

        foreach ($this->addresses($schedule) as $email) {
            /*
             * Across companies, and this is the line that stops a leak.
             *
             * `users` is a landlord table with a tenancy scope over it, so a scoped lookup answers null for
             * somebody who has left — and null here means *external*, which an owner holding
             * `ReportSendExternal` is allowed to send to. A person removed from the company would therefore
             * have been reclassified as an outsider and sent the report anyway. Reading the row and then
             * asking whether they are still a member is what refuses them.
             */
            $user = User::query()->acrossCompanies()->where('email', $email)->first();

            if ($user === null) {
                $recorded[] = [
                    'email' => $email,
                    'external' => true,
                    'sent' => $mayGoOutside,
                    'reason' => $mayGoOutside ? 'External recipient' : 'External recipient, and the owner may not send outside this company.',
                ];

                if ($mayGoOutside) {
                    $allowed[] = ['email' => $email, 'external' => true];
                }

                continue;
            }

            $reason = $this->memberRefusal($user);

            $recorded[] = [
                'email' => $email,
                'external' => false,
                'user_id' => $user->getKey(),
                'sent' => $reason === null,
                'reason' => $reason ?? 'Member of this company',
            ];

            if ($reason === null) {
                $allowed[] = ['email' => $email, 'external' => false];
            }
        }

        return ['allowed' => $allowed, 'recorded' => $recorded];
    }

    /**
     * The files to attach — item 6, which is Phase 4's export in a job.
     *
     * @return array<int, array{name: string, mime: string, data: string}>
     */
    public function files(ReportSchedule $schedule, array $payload, ?Carbon $at = null): array
    {
        $at ??= now();
        $export = app(ReportExport::class);
        $asOf = $schedule->range($at)['to'];
        $files = [];

        if (in_array($schedule->format, [ReportSchedule::FORMAT_CSV, ReportSchedule::FORMAT_BOTH], true)) {
            $files[] = [
                'name' => $export->filename($payload, $asOf, 'csv'),
                'mime' => 'text/csv',
                // The same BOM the download writes, and for the same reason: Excel on Windows reads a CSV
                // without one as the local codepage, and these reports are full of rupee signs.
                'data' => "\u{FEFF}".$export->csv($payload),
            ];
        }

        if (in_array($schedule->format, [ReportSchedule::FORMAT_PDF, ReportSchedule::FORMAT_BOTH], true)) {
            $document = (new PdfDocument('reports.pane-export', [
                'grid' => $export->grid($payload),
                'pdf' => true,
            ]))
                // Landscape for anything the pane calls wide, exactly as the download decides it.
                ->{($payload['wide'] ?? false) ? 'landscape' : 'portrait'}();

            $files[] = [
                'name' => $export->filename($payload, $asOf, 'pdf'),
                'mime' => 'application/pdf',
                'data' => $document->raw(),
            ];
        }

        return $files;
    }

    /**
     * The link to the live report — item 7.
     *
     * "**and a link to the live report in the body**, so a recipient who wants to drill in lands in the
     * application and is authorised there." Which is also item 1's claim made good: the schedule stores the
     * state the URL carries, so the link and the attachment are the same report.
     *
     * The tenant is passed explicitly because there is no Filament tenant in a queue worker — the company is
     * current for the *database*, and the panel's URL needs it as a parameter.
     */
    public function link(ReportSchedule $schedule, ?Carbon $at = null): ?string
    {
        $company = $this->company();

        if ($company === null) {
            return null;
        }

        $parameters = array_filter([
            'selected' => $schedule->report_key,
            'asOf' => $schedule->range($at ?? now())['to'],
            ...(array) ($schedule->state ?? []),
        ], fn (mixed $value): bool => $value !== null && $value !== '');

        try {
            return Reports::getUrl($parameters, tenant: $company);
        } catch (Throwable) {
            // A panel that cannot build a URL is not a reason to hold up an email that has its attachment.
            return null;
        }
    }

    /**
     * Tell the owner that this schedule has stopped working — item 8.
     *
     * "Retries are bounded and the owner is notified after repeated failure, because a scheduled report that
     * quietly stopped arriving is worse than one that was never set up: everybody assumes the silence means
     * nothing happened."
     */
    public function giveUp(ReportSchedule $schedule, string $error): void
    {
        $owner = $schedule->owner();

        if ($owner === null) {
            return;
        }

        $owner->notify(new ScheduledReportFailed(
            report: $this->titleFor($schedule),
            timetable: $schedule->timetable(),
            error: $error,
        ));
    }

    /** What this schedule's report is called, for an email that must not say `custom-7`. */
    public function titleFor(ReportSchedule $schedule): string
    {
        if ($schedule->isBuiltReport()) {
            return (string) (ReportDefinition::forKey($schedule->report_key)?->name ?? 'Report');
        }

        return (string) (Reports::catalogue()[$schedule->report_key]['label'] ?? $schedule->report_key);
    }

    /**
     * Why this owner may not have this report rendered for them, or null if they may.
     *
     * Membership first, then `ReportView`. Both are asked of the owner rather than of the schedule, because
     * "the owner lost access" is the ordinary way a schedule goes wrong and neither answer is knowable when
     * the schedule is written.
     */
    private function ownerRefusal(ReportSchedule $schedule, User $owner): ?string
    {
        $refusal = $this->memberRefusal($owner);

        return $refusal === null ? null : 'The owner '.$refusal;
    }

    /**
     * Why a user may not be sent a report of this company's, or null if they may.
     *
     * The same two questions for the owner and for a recipient, because they are the same two questions: is
     * this person still one of us, and may they still read a report.
     *
     * A clause rather than a sentence — "is no longer a member of this company." — so each caller can name
     * whose failing it is: "The owner is no longer a member" suspends a schedule, "The recipient is no longer
     * a member" is one line of a delivery's record, and the two must not read as the same event.
     */
    private function memberRefusal(User $user): ?string
    {
        $company = $this->company();

        if ($company === null) {
            return 'has no company in context.';
        }

        if ((int) $user->status !== 1) {
            return 'has a disabled account.';
        }

        if (! $user->companies()->whereKey($company->getKey())->exists()) {
            return 'is no longer a member of this company.';
        }

        return $user->can('ReportView') ? null : 'is no longer permitted to read reports.';
    }

    /**
     * Whose company this is.
     *
     * Filament's tenant first and spatie's second, which is the resolution `PayslipDeliveryService` uses and
     * for the same reason: this code runs in both places. On the web the panel's tenant is the company being
     * looked at; in a `TenantAware` command there is no panel and spatie's current tenant is the one whose
     * database is connected.
     */
    private function company(): ?Company
    {
        return Filament::getTenant() ?? Company::current();
    }

    /**
     * The addresses on a schedule, tidied.
     *
     * Lower-cased and de-duplicated, because a list typed by hand is where "Ali@Example.com" and
     * "ali@example.com" become two emails to one person.
     *
     * @return array<int, string>
     */
    private function addresses(ReportSchedule $schedule): array
    {
        $addresses = array_map(
            fn (mixed $email): string => mb_strtolower(trim((string) $email)),
            (array) ($schedule->recipients ?? []),
        );

        return array_values(array_unique(array_filter(
            $addresses,
            fn (string $email): bool => filter_var($email, FILTER_VALIDATE_EMAIL) !== false,
        )));
    }
}
