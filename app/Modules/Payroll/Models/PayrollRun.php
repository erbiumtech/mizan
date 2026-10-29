<?php

namespace App\Modules\Payroll\Models;

use App\Models\TenantModel as Model;
use App\Modules\Core\Models\FiscalYear;
use App\Modules\Core\Models\User;
use App\Support\PayrollMonth;
use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;

/**
 * One month of payroll, and whether it has been signed off.
 */
class PayrollRun extends Model
{
    use Auditable;

    public const STATUS_OPEN = 'open';

    /** Between open and locked — docs/saas-launch-plan.md §14.1. Somebody other than the clerk has read it. */
    public const STATUS_APPROVED = 'approved';

    public const STATUS_LOCKED = 'locked';

    public const LOCK_PERMISSION = 'PayrollRunLock';

    public const APPROVE_PERMISSION = 'PayrollRunApprove';

    protected $fillable = [
        'month', 'fiscal_year_id', 'status', 'notes',
        'locked_by', 'locked_at', 'reopened_by', 'reopened_at', 'reopen_reason',
        'approved_by', 'approved_at', 'sent_back_by', 'sent_back_at', 'sent_back_reason', 'inputs_as_of',
    ];

    protected $casts = [
        'locked_at' => 'datetime',
        'reopened_at' => 'datetime',
        'approved_at' => 'datetime',
        'sent_back_at' => 'datetime',
        'inputs_as_of' => 'datetime',
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

    public function isApproved(): bool
    {
        return $this->status === self::STATUS_APPROVED;
    }

    public function isOpen(): bool
    {
        return $this->status === self::STATUS_OPEN;
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /** Whether a run has to be approved before it can be signed off — a company switch, off by default. */
    public static function approvalRequired(): bool
    {
        return (bool) setting('payroll.require_approval', config('payroll.require_approval', false));
    }

    public function approve(User $by): self
    {
        if (! $this->isOpen()) {
            throw new InvalidArgumentException("{$this->periodLabel()} is {$this->status}, not open.");
        }

        if ($this->payslips()->count() === 0) {
            throw new InvalidArgumentException("{$this->periodLabel()} has no payslips in it, so there is nothing to approve.");
        }

        $this->update([
            'status' => self::STATUS_APPROVED,
            'approved_by' => $by->getKey(),
            'approved_at' => now(),
            'sent_back_by' => null,
            'sent_back_at' => null,
            'sent_back_reason' => null,
        ]);

        return $this;
    }

    /** Back to open, with the reason kept — what the clerk reads before fixing it. */
    public function sendBack(User $by, string $reason): self
    {
        if (! $this->isApproved()) {
            throw new InvalidArgumentException("{$this->periodLabel()} is not approved.");
        }

        if (trim($reason) === '') {
            throw new InvalidArgumentException('Sending a run back needs a reason.');
        }

        $this->update([
            'status' => self::STATUS_OPEN,
            'approved_by' => null,
            'approved_at' => null,
            'sent_back_by' => $by->getKey(),
            'sent_back_at' => now(),
            'sent_back_reason' => trim($reason),
        ]);

        return $this;
    }

    /** The run read its inputs now: raised, or recalculated. */
    public function markInputsRead(): self
    {
        $this->forceFill(['inputs_as_of' => now()])->save();

        return $this;
    }

    /**
     * Inputs newer than the run last read — docs/saas-launch-plan.md §14.1. Each
     * source by name with the time of its latest change, so the warning says what
     * moved. Tables are named, not classes: Payroll may not import Expenses or
     * Advances, and a company without them simply has no such table.
     *
     * @return array<string, \Illuminate\Support\Carbon>
     */
    public function staleInputs(): array
    {
        if ($this->inputs_as_of === null) {
            return [];
        }

        $this->loadMissing('fiscalYear');
        $first = PayrollMonth::firstDay($this->month, $this->fiscalYear);
        $last = $first->copy()->endOfMonth();
        $employees = $this->payslips()->pluck('employee_id');
        $since = $this->inputs_as_of;
        $stale = [];

        $sources = [
            'attendance' => ['attendance_days', 'date', 'employee_id'],
            'leave' => ['leave_requests', 'from_date', 'employee_id'],
            'expense claims' => ['expense_claims', null, 'employee_id'],
            'advances' => ['advances', null, 'employee_id'],
            'payslip inputs' => ['payslip_component_inputs', null, null],
        ];

        foreach ($sources as $label => [$table, $dateColumn, $employeeColumn]) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            $query = DB::table($table)->where('updated_at', '>', $since);

            if ($employeeColumn !== null) {
                $query->whereIn($employeeColumn, $employees);
            } else {
                $query->whereIn('payslip_id', $this->payslips()->select('id'));
            }

            if ($dateColumn !== null) {
                $query->whereDate($dateColumn, '>=', $first->toDateString())->whereDate($dateColumn, '<=', $last->toDateString());
            }

            $latest = $query->max('updated_at');

            if ($latest !== null) {
                $stale[$label] = Carbon::parse($latest);
            }
        }

        return $stale;
    }

    public function isStale(): bool
    {
        return $this->staleInputs() !== [];
    }

    /** The company's pay day for this month, when one is set — docs/saas-launch-plan.md §14.1. */
    public function payDay(): ?\Illuminate\Support\Carbon
    {
        $day = (int) setting('payroll.pay_day', config('payroll.pay_day', 0));

        if ($day < 1) {
            return null;
        }

        $this->loadMissing('fiscalYear');
        $first = PayrollMonth::firstDay($this->month, $this->fiscalYear);

        return Carbon::instance($first)->day(min($day, $first->daysInMonth));
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

        if (static::approvalRequired() && ! $this->isApproved()) {
            throw new InvalidArgumentException("{$this->periodLabel()} has not been approved. This company signs off approved runs only.");
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
            'approved_by' => null,
            'approved_at' => null,
            'reopened_by' => $by->getKey(),
            'reopened_at' => now(),
            'reopen_reason' => trim($reason),
        ]);

        return $this;
    }
}
