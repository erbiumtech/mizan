<?php

namespace App\Modules\Payroll\Models;

use App\Models\TenantModel as Model;
use App\Modules\Core\Models\FiscalYear;
use App\Modules\Core\Models\User;
use App\Modules\Payroll\Support\PayrollMonth;
use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use InvalidArgumentException;

/**
 * One month of payroll, and whether it has been signed off.
 */
class PayrollRun extends Model
{
    use Auditable;

    public const STATUS_OPEN = 'open';

    public const STATUS_LOCKED = 'locked';

    public const LOCK_PERMISSION = 'PayrollRunLock';

    protected $fillable = [
        'month', 'fiscal_year_id', 'status', 'notes',
        'locked_by', 'locked_at', 'reopened_by', 'reopened_at', 'reopen_reason',
    ];

    protected $casts = [
        'locked_at' => 'datetime',
        'reopened_at' => 'datetime',
    ];

    protected $attributes = ['status' => self::STATUS_OPEN];

    public function fiscalYear(): BelongsTo
    {
        return $this->belongsTo(FiscalYear::class);
    }

    public function payslips(): HasMany
    {
        return $this->hasMany(Payslip::class, 'payroll_run_id');
    }

    public function locker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'locked_by');
    }

    public function scopeLocked(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_LOCKED);
    }

    public function isLocked(): bool
    {
        return $this->status === self::STATUS_LOCKED;
    }

    /**
     * The run for a payroll month, made if it does not exist.
     *
     * Every payslip belongs to one, so this is called wherever payslips are created
     * rather than left to whoever remembers.
     */
    public static function forMonth(string $month, FiscalYear $fiscalYear): self
    {
        return static::firstOrCreate(
            ['month' => $month, 'fiscal_year_id' => $fiscalYear->getKey()],
            ['status' => self::STATUS_OPEN],
        );
    }

    public function periodLabel(): string
    {
        // Loaded explicitly. This is called from a table column, from a modal's description, from lock()
        // and unlock(), and from Payslip's refusal message — none of which can assume the caller eager
        // loaded the year, and reading it lazily is a violation the moment the guard is on.
        //
        // Over a *list* this is still a query per row, so PayrollRunsTable eager-loads it; this is the
        // safety net for the single-record callers.
        $this->loadMissing('fiscalYear');

        return PayrollMonth::firstDay($this->month, $this->fiscalYear)->format('F Y');
    }

    /**
     * Cached for the life of the model, because reading it costs five queries.
     *
     * @var array<string, float|int>|null
     */
    private ?array $totalsMemo = null;

    /**
     * What the month came to, from the payslips in it.
     *
     * Five aggregates — a count, three sums and another count — so the figure is remembered per model.
     * The payroll-runs table asked for it *twice* per row to render "3 of 8 accepted", which was ten
     * queries a row and a hundred for a page of ten; nothing on that screen changes a payslip while it
     * renders, so answering the second ask from the first is the same answer for a tenth of the cost.
     *
     * The trade is that a payslip changed after this was first read is not reflected until the model is
     * reloaded. Nothing that mutates payslips reads totals() in the same request — lock() and unlock()
     * count payslips directly — and a stale figure inside one render is not a risk worth five queries.
     *
     * @return array<string, float|int>
     */
    public function totals(): array
    {
        if ($this->totalsMemo !== null) {
            return $this->totalsMemo;
        }

        $payslips = $this->payslips();

        return $this->totalsMemo = [
            'payslips' => $payslips->count(),
            'gross' => round((float) $payslips->sum('total_earnings'), 2),
            'deductions' => round((float) $payslips->sum('total_deductions'), 2),
            'net' => round((float) $payslips->sum('net_salary'), 2),
            'accepted' => $payslips->where('employee_review', Payslip::REVIEW_ACCEPTED)->count(),
        ];
    }

    /**
     * Sign the month off. Nothing in it can be changed afterwards without reopening
     * it, and reopening leaves a reason behind.
     */
    public function lock(User $by): self
    {
        if ($this->isLocked()) {
            throw new InvalidArgumentException("{$this->periodLabel()} is already locked.");
        }

        if ($this->payslips()->count() === 0) {
            throw new InvalidArgumentException(
                "{$this->periodLabel()} has no payslips in it, so there is nothing to agree."
            );
        }

        $this->update([
            'status' => self::STATUS_LOCKED,
            'locked_by' => $by->getKey(),
            'locked_at' => now(),
        ]);

        return $this;
    }

    /**
     * Open a signed-off month again, with a reason.
     *
     * The reason is required because this is the thing an auditor asks about: a month
     * that was agreed, then changed, and nothing saying why.
     */
    public function reopen(User $by, string $reason): self
    {
        if (! $this->isLocked()) {
            throw new InvalidArgumentException("{$this->periodLabel()} is not locked.");
        }

        if (trim($reason) === '') {
            throw new InvalidArgumentException('Reopening a signed-off month needs a reason.');
        }

        $this->update([
            'status' => self::STATUS_OPEN,
            'reopened_by' => $by->getKey(),
            'reopened_at' => now(),
            'reopen_reason' => trim($reason),
        ]);

        return $this;
    }
}
