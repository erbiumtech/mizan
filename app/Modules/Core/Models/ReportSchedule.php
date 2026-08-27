<?php

namespace App\Modules\Core\Models;

use App\Models\TenantModel as Model;
use App\Support\Reporting\RelativePeriod;
use Cron\CronExpression;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A report, on a timetable — `docs/reports-expansion-plan.md` Phase 8, items 1, 2 and 3.
 *
 * > The reports and the dashboard both require somebody to come and look. This sends the report to the people
 * > who would otherwise ask for it: the aged receivables every Monday, the payroll register the day after a
 * > run, the SLA summary on the first of the month.
 *
 * **The owner is not bookkeeping, it is the security model.** An emailed report leaves the application's
 * authorization behind — nobody has to log in to read it — so item 2 makes the render run *as* somebody:
 * "the render runs as the schedule's owner, whose access decides what the rows are". Everything else about
 * this table follows from that one sentence, including `suspended_at`: a schedule whose owner loses access is
 * stopped rather than quietly rendered with fewer rows, because a report that silently shrinks is worse than
 * one that stops arriving.
 *
 * **The period is a rule, never two dates.** `RelativePeriod` exists for exactly this — Phase 6.2 stored it
 * relative *because* this phase would send it, and "the aged receivables every Monday" resolves its own dates
 * each Monday or it is a report that mails last quarter for ever.
 *
 * **The resolved period is also the idempotency key**, which is item 3's sharp end: "getting it wrong is not a
 * cosmetic error but a double send". `periodKey()` is what `report_deliveries.period_key` holds, and its unique
 * index is what makes a retried render one email rather than two.
 */
class ReportSchedule extends Model
{
    public const FORMAT_PDF = 'pdf';

    public const FORMAT_CSV = 'csv';

    public const FORMAT_BOTH = 'both';

    /** @var array<string, string> */
    public const FORMATS = [
        self::FORMAT_PDF => 'PDF',
        self::FORMAT_CSV => 'CSV',
        self::FORMAT_BOTH => 'PDF and CSV',
    ];

    /** The permission to send to somebody who has no account here — item 2, and the one gate the
     * CRUD permissions do not cover. See the module manifest's `Report` group. */
    public const SEND_EXTERNAL = 'ReportSendExternal';

    /**
     * The timetables the form offers, and the cron each is.
     *
     * Presets rather than a cron field alone, because "every Monday" is what people ask for and
     * `0 7 * * 1` is what they then get wrong. The expression is still stored — a company that wants
     * something else may write one, and the scheduler reads only this column.
     *
     * @var array<string, string>
     */
    public const TIMETABLES = [
        '0 7 * * *' => 'Every day at 07:00',
        '0 7 * * 1' => 'Every Monday at 07:00',
        '0 7 1 * *' => 'The 1st of the month at 07:00',
        '0 7 1 1,4,7,10 *' => 'The 1st of each quarter at 07:00',
    ];

    protected $fillable = [
        'user_id', 'report_key', 'state', 'period', 'format',
        'cron', 'timezone', 'recipients', 'is_active',
    ];

    protected $casts = [
        'state' => 'array',
        'recipients' => 'array',
        'is_active' => 'boolean',
        'suspended_at' => 'datetime',
        'last_run_at' => 'datetime',
    ];

    public function deliveries(): HasMany
    {
        return $this->hasMany(ReportDelivery::class)->latest('id');
    }

    /**
     * The owner, whose access decides what the rows are.
     *
     * **Across companies, deliberately.** `users` is a landlord table with a tenancy scope over it, so a
     * plain `find()` answers null for somebody who has left this company — and "no account at all" and "no
     * longer a member" are two different suspensions with two different fixes. Reading the row and *then*
     * asking whether they are still a member is what lets the schedule say which.
     */
    public function owner(): ?User
    {
        return $this->user_id === null
            ? null
            : User::query()->acrossCompanies()->whereKey($this->user_id)->first();
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true)->whereNull('suspended_at');
    }

    public function isSuspended(): bool
    {
        return $this->suspended_at !== null;
    }

    /**
     * Stop this schedule, and say why — item 2.
     *
     * Recorded rather than logged, because the person who has to fix it reads the list rather than the log:
     * "the owner no longer has permission to read this report" is a sentence somebody can act on, and
     * `is_active` staying true is what lets them resume it once they have.
     */
    public function suspend(string $reason): void
    {
        $this->forceFill([
            'suspended_at' => now(),
            'suspended_reason' => $reason,
        ])->save();
    }

    public function resume(): void
    {
        $this->forceFill(['suspended_at' => null, 'suspended_reason' => null])->save();
    }

    /**
     * Whether this schedule is due at this instant.
     *
     * **In the schedule's own timezone**, which is the difference between a report that arrives on the 1st
     * and one that arrives on the 31st for half the year. `CronExpression` takes the timezone, so this asks
     * it rather than converting by hand.
     *
     * The window matters: `reports:deliver` runs every fifteen minutes (item 5), so "is due now" has to mean
     * "was due since the last run" or a 07:00 report would only ever be sent by a 07:00 run. Cron's own
     * `getPreviousRunDate` answers that — the last time this expression fired at or before now — and the
     * comparison is against the window the caller names.
     */
    public function isDueAt(Carbon $at, int $windowMinutes = 15): bool
    {
        if (! $this->is_active || $this->isSuspended() || blank($this->cron)) {
            return false;
        }

        if (! CronExpression::isValidExpression((string) $this->cron)) {
            return false;
        }

        $cron = new CronExpression((string) $this->cron);
        $timezone = $this->timezone ?: 'UTC';
        $now = $at->copy()->setTimezone($timezone);

        // `allowCurrentDate: true`, so a run landing exactly on the minute counts as due rather than as
        // fifteen minutes late.
        $previous = Carbon::instance($cron->getPreviousRunDate($now, 0, true, $timezone));

        return $previous->diffInMinutes($now, absolute: true) < $windowMinutes;
    }

    /** The span this run covers. @return array{from: string, to: string} */
    public function range(?Carbon $at = null): array
    {
        return RelativePeriod::range($this->period, ($at ?? now())->toDateString());
    }

    /**
     * The idempotency key for this run — item 3 and item 4 meeting.
     *
     * The *resolved* span rather than the rule, which is what makes the guarantee hold in both directions: a
     * daily month-to-date report has one key a day and sends daily, while "monthly on the 1st, last month"
     * has one key a month however many times the queue retries it.
     */
    public function periodKey(?Carbon $at = null): string
    {
        $range = $this->range($at);

        return $this->period.':'.$range['from'].'..'.$range['to'];
    }

    /** Whether this names a report somebody assembled, rather than a coded one. */
    public function isBuiltReport(): bool
    {
        return ReportDefinition::idFromKey($this->report_key) !== null;
    }

    /**
     * The filter state, in the shape the pane's own renderer takes.
     *
     * The saved state is the URL's, so the keys are the URL's — and `find` is the property the search box
     * binds to while `search` is what the renderer is passed. Translated here rather than at both call sites.
     *
     * @return array<string, mixed>
     */
    public function asked(): array
    {
        $state = (array) ($this->state ?? []);

        return [
            'account' => $state['account'] ?? null,
            'budget' => $state['budget'] ?? null,
            'search' => $state['find'] ?? $state['search'] ?? '',
            'month' => $state['month'] ?? null,
        ];
    }

    /** The comparison basis a coded report is drawn with, defaulting as the pane does. */
    public function comparison(): string
    {
        $state = (array) ($this->state ?? []);

        return is_string($state['compare'] ?? null) ? $state['compare'] : 'previous_year';
    }

    /** How this timetable reads, for the list and the log. */
    public function timetable(): string
    {
        return (self::TIMETABLES[$this->cron] ?? $this->cron).' · '.$this->timezone;
    }
}
