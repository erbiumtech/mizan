<?php

namespace App\Modules\Core\Models;

use App\Models\TenantModel as Model;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One send, or one attempt at one — `docs/reports-expansion-plan.md` Phase 8, items 4 and 8.
 *
 * > **One delivery per period, whatever the queue does.** `report_deliveries` with
 * > `unique(schedule_id, period_key)`, plus status, rendered_at, sent_at, recipient list and error. This is the
 * > `payslips.sent_at` / `SubscriptionBillingService::alreadyBilled()` pattern, and it is not optional: a
 * > queued render that exceeds its timeout is retried by design, and without this the retry emails the report
 * > a second time.
 *
 * **The row is written before the work, not after it.** That is the whole mechanism: `claim()` inserts a
 * pending row and lets the unique index refuse a second one, so two workers racing on the same period produce
 * one delivery and one email. A row written after a successful send would leave the window that matters —
 * between the mail leaving and the row landing — completely unguarded.
 *
 * **A failed attempt keeps its row**, which is why `attempts` is here and why `status` distinguishes failed
 * from pending. Item 8 wants a log people can read: "what went out, to whom, when, and what failed" — and a
 * failure that deleted its own row would make a schedule that has never worked look like one that has never
 * run.
 */
class ReportDelivery extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_SENT = 'sent';

    public const STATUS_FAILED = 'failed';

    /** Rendered and deliberately not sent: nobody was left to send it to. See `ReportDeliveryService`. */
    public const STATUS_SKIPPED = 'skipped';

    /** @var array<string, string> */
    public const STATUSES = [
        self::STATUS_PENDING => 'Pending',
        self::STATUS_SENT => 'Sent',
        self::STATUS_FAILED => 'Failed',
        self::STATUS_SKIPPED => 'Not sent',
    ];

    /**
     * How many times one period is attempted before the owner is told — item 8's "retries are bounded".
     *
     * Three, and the reason it is a constant rather than only the job's `$tries` is that the *count* is what
     * decides when to notify: a queue configured differently must not turn "the owner hears after three
     * failures" into "the owner hears never".
     */
    public const MAX_ATTEMPTS = 3;

    protected $fillable = ['report_schedule_id', 'period_key', 'status', 'attempts', 'recipients', 'error'];

    protected $casts = [
        'recipients' => 'array',
        'rendered_at' => 'datetime',
        'sent_at' => 'datetime',
    ];

    public function schedule(): BelongsTo
    {
        return $this->belongsTo(ReportSchedule::class, 'report_schedule_id');
    }

    public function scopeRecent(Builder $query): Builder
    {
        return $query->latest('id');
    }

    /** @param  array<int, array<string, mixed>>  $recipients */
    public function markSent(array $recipients): void
    {
        $this->forceFill([
            'status' => self::STATUS_SENT,
            'recipients' => $recipients,
            'rendered_at' => $this->rendered_at ?? now(),
            'sent_at' => now(),
            'error' => null,
        ])->save();
    }

    /**
     * Nothing was sent, and that was the right answer.
     *
     * Every recipient refused at send time — they left, or lost the permission — which is a state worth
     * recording rather than a failure: the render worked, the report was fine, and there was nobody to send
     * it to. Marking it failed would put a red row in the log for something nobody can fix by retrying.
     *
     * @param  array<int, array<string, mixed>>  $recipients
     */
    public function markSkipped(array $recipients, string $reason): void
    {
        $this->forceFill([
            'status' => self::STATUS_SKIPPED,
            'recipients' => $recipients,
            'rendered_at' => $this->rendered_at ?? now(),
            'error' => $reason,
        ])->save();
    }

    public function markFailed(string $error): void
    {
        $this->forceFill([
            'status' => self::STATUS_FAILED,
            'attempts' => (int) $this->attempts + 1,
            'error' => mb_substr($error, 0, 2000),
        ])->save();
    }

    /** Whether this period has been given up on, which is when the owner hears about it. */
    public function isExhausted(): bool
    {
        return $this->status === self::STATUS_FAILED && (int) $this->attempts >= self::MAX_ATTEMPTS;
    }
}
