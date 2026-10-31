<?php

namespace App\Modules\Expenses\Models;

use App\Models\TenantModel as Model;
use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Accounting\Models\Payment;
use App\Modules\Accounting\Models\TransactionType;
use App\Modules\Core\Models\User;
use App\Modules\Employees\Models\Employee;
use App\Modules\Expenses\Notifications\ExpenseClaimDecided;
use App\Modules\Expenses\Notifications\ExpenseClaimSubmitted;
use App\Modules\Expenses\Services\ExpenseClaimApprovalChain;
use App\Modules\Expenses\Services\ExpenseClaimBankPayment;
use App\Modules\Expenses\Services\ExpenseClaimPosting;
use App\Modules\Payroll\Models\Payslip;
use App\Support\Contracts\PayableDocument;
use App\Support\Replication\Replicable;
use App\Support\Replication\ReplicatesRecords;
use App\Support\TenantTransaction;
use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Notification;
use InvalidArgumentException;

/**
 * An expense an employee paid for and is owed back.
 *
 * The workflow is EmployeeChangeRequest's, deliberately: submit, notify whoever may
 * approve, approve or refuse *with a reason*. That pattern is built and tested on
 * another subject, and a second way of doing the same thing would be a second thing
 * to get wrong.
 */
class ExpenseClaim extends Model implements PayableDocument, Replicable
{
    use Auditable, ReplicatesRecords;

    // ─── PayableDocument — paid by bank transfer, docs/bexio-gap-plan.md §3.6 ───

    /** The bank file pays the employee, on the bank details of their record. */
    public function payee(): ?EloquentModel
    {
        // Loaded explicitly: a list of payments reaches this through the morph, where the lazy-load guard
        // would otherwise trip on the second row (`user` is what display_label and the file read).
        return $this->loadMissing('employee.user')->employee;
    }

    public function paymentLabel(): string
    {
        return "Expense claim #{$this->getKey()}";
    }

    /** Approving the payment clears the liability approval booked; see ExpenseClaimBankPayment::settle(). */
    public function settleByPayment(EloquentModel $payment): ?EloquentModel
    {
        return app(ExpenseClaimBankPayment::class)->settle($this, $payment);
    }

    /**
     * The state that means "waiting for someone to approve it": its list puts these rows first
     * (App\Filament\Support\PendingFirst). [column, values], or [column, null] for "while empty".
     */
    public const PENDING_APPROVAL = ['status', [self::STATUS_PENDING]];

    /** Being written — docs/saas-launch-plan.md §14.3. Nobody is told until it is submitted. */
    public const STATUS_DRAFT = 'draft';

    public const STATUS_PENDING = 'pending';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REFUSED = 'refused';

    /** Approved and paid — with a payslip, or on its own. */
    public const STATUS_SETTLED = 'settled';

    /** Who may decide a claim. */
    public const APPROVE_PERMISSION = 'ExpenseClaimApprove';

    protected $fillable = [
        'employee_id', 'transaction_type_id', 'claimed_on', 'description', 'amount',
        'notes', 'receipt_path', 'status', 'submitted_by', 'submitted_at', 'decided_by', 'decided_at',
        'refusal_reason', 'payslip_id', 'payment_id', 'journal_entry_id',
    ];

    protected $casts = [
        'claimed_on' => 'date',
        'amount' => 'decimal:2',
        'decided_at' => 'datetime',
        'submitted_at' => 'datetime',
    ];

    protected $attributes = [
        'status' => self::STATUS_PENDING,
    ];

    protected static function booted(): void
    {
        // The accrual goes with the claim — docs/bexio-gap-plan.md Phase 3, item 13. Reversed rather than
        // deleted, so the ledger says the liability existed and was taken back.
        static::deleting(function (self $claim): void {
            app(ExpenseClaimPosting::class)->reverse($claim);
        });

        static::created(function (self $claim): void {
            // A draft tells nobody; submit() does when it is ready — docs/saas-launch-plan.md §14.3.
            if ($claim->isPending()) {
                $claim->notifyApprovers();
            }
        });
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function transactionType(): BelongsTo
    {
        return $this->belongsTo(TransactionType::class);
    }

    public function submitter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    public function payslip(): BelongsTo
    {
        return $this->belongsTo(Payslip::class);
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_PENDING);
    }

    /** Approved and not yet paid: what the company owes this employee back, however it will be paid. */
    public function scopeAwaitingSettlement(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_APPROVED);
    }

    /**
     * Owed, and payroll's to pay: a claim with a bank payment raised for it is spoken for, even while
     * that payment is still a draft — a payslip reimbursing it too would pay the person twice.
     */
    public function scopeReimbursableByPayroll(Builder $query): Builder
    {
        return $query->awaitingSettlement()->whereNull('payment_id');
    }

    public function isApproved(): bool
    {
        return $this->status === self::STATUS_APPROVED;
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function isSettled(): bool
    {
        return $this->status === self::STATUS_SETTLED;
    }

    /**
     * Approve, and book what is now owed — docs/bexio-gap-plan.md Phase 3, item 13.
     *
     * One transaction over the status and the accrual: a claim approved whose posting then failed would
     * be a liability the report shows and the ledger does not, which is the hole this closes.
     */
    public function lines(): HasMany
    {
        return $this->hasMany(ExpenseClaimLine::class)->orderBy('sort')->orderBy('id');
    }

    public function approvals(): HasMany
    {
        return $this->hasMany(ExpenseClaimApproval::class)->orderBy('step');
    }

    public function isDraft(): bool
    {
        return $this->status === self::STATUS_DRAFT;
    }

    /** The lines are the claim: their sum is its amount, and their first date its date. */
    public function recalculateFromLines(): void
    {
        $lines = $this->lines()->get();

        if ($lines->isEmpty()) {
            return;
        }

        $this->forceFill([
            'amount' => round((float) $lines->sum('amount'), 2),
            'claimed_on' => $lines->min('spent_on'),
        ])->saveQuietly();
    }

    /**
     * The lines whose category needs a receipt and which have none — what stops a submit.
     *
     * "Needs a receipt" is the category's own switch (Expense Categories), checked when the claim is put in
     * front of an approver rather than while it is a draft somebody is still assembling.
     *
     * @return array<int, string>
     */
    public function linesMissingReceipts(): array
    {
        return $this->lines()->with('category')->get()
            ->filter(fn (ExpenseClaimLine $line): bool => (bool) $line->category?->requires_receipt && blank($line->receipt_path))
            ->map(fn (ExpenseClaimLine $line): string => trim($line->description.' ('.$line->category->name.')'))
            ->values()
            ->all();
    }

    /** From draft to pending: now the approvers are told. */
    public function submit(): self
    {
        if (! $this->isDraft()) {
            throw new InvalidArgumentException("This claim is already {$this->status}.");
        }

        if (round((float) $this->amount, 2) <= 0) {
            throw new InvalidArgumentException('A claim needs at least one line with an amount.');
        }

        if (($missing = $this->linesMissingReceipts()) !== []) {
            throw new InvalidArgumentException('A receipt is needed for: '.implode('; ', $missing).'. Attach it to the line, then submit.');
        }

        $this->update(['status' => self::STATUS_PENDING, 'submitted_at' => now()]);
        $this->notifyApprovers();

        return $this;
    }

    public function notifyApprovers(): void
    {
        // Never to the person who submitted it, even if they hold the permission:
        // the point of an approver is that it is somebody else.
        $approvers = User::holdingPermission(self::APPROVE_PERMISSION)
            ->where('id', '!=', $this->submitted_by)
            ->where('status', 1)
            ->get();

        Notification::send($approvers, new ExpenseClaimSubmitted($this));
    }

    /** "waiting for HR" part-way through a chain; null otherwise. */
    public function awaitingLabel(): ?string
    {
        return app(ExpenseClaimApprovalChain::class)->awaitingLabel($this);
    }

    public function approve(User $approver): self
    {
        $this->assertPending('approved');
        $this->assertNotOwnClaim($approver);

        // The chain — docs/saas-launch-plan.md §14.3. A step that is not the last records itself
        // and stops; only the last books the accrual and tells the claimant.
        $chain = app(ExpenseClaimApprovalChain::class);
        $step = $chain->nextStep($this);
        $role = $chain->roleForStep($step);

        if ($role !== null && ! $approver->hasRole($role)) {
            throw new InvalidArgumentException("This claim is waiting for somebody holding the {$role} role to approve it.");
        }

        if (! $chain->isFinal($step)) {
            $this->approvals()->create(['step' => $step, 'role' => $role, 'approved_by' => $approver->getKey(), 'approved_at' => now()]);

            return $this->refresh();
        }

        if ($step > 1) {
            $this->approvals()->create(['step' => $step, 'role' => $role, 'approved_by' => $approver->getKey(), 'approved_at' => now()]);
        }

        TenantTransaction::run(function () use ($approver): void {
            $this->update([
                'status' => self::STATUS_APPROVED,
                'decided_by' => $approver->getKey(),
                'decided_at' => now(),
                'refusal_reason' => null,
            ]);

            app(ExpenseClaimPosting::class)->accrue($this);
        });

        $this->notifySubmitter();

        return $this;
    }

    /** The accrual approval booked, where Accounting is licensed — ExpenseClaimPosting. */
    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }

    /**
     * A refusal carries its reason. Being told no without being told why is the
     * complaint the whole approval step exists to answer.
     */
    public function refuse(User $approver, string $reason): self
    {
        $this->assertPending('refused');
        $this->assertNotOwnClaim($approver);

        if (trim($reason) === '') {
            throw new InvalidArgumentException('A refusal needs a reason.');
        }

        $this->update([
            'status' => self::STATUS_REFUSED,
            'decided_by' => $approver->getKey(),
            'decided_at' => now(),
            'refusal_reason' => trim($reason),
        ]);

        $this->notifySubmitter();

        return $this;
    }

    private function assertPending(string $verb): void
    {
        if (! $this->isPending()) {
            throw new InvalidArgumentException(
                "This claim is already {$this->status} and cannot be {$verb}."
            );
        }
    }

    /**
     * Enforced on the model rather than in the policy: Administrators and super
     * admins pass every policy check, so a rule that has to hold for everyone cannot
     * live in one — and "somebody else approves it" is the whole point.
     */
    private function assertNotOwnClaim(User $approver): void
    {
        if ($this->submitted_by === $approver->getKey()) {
            throw new InvalidArgumentException('A claim cannot be decided by the person who submitted it.');
        }
    }

    private function notifySubmitter(): void
    {
        $submitter = User::query()->acrossCompanies()->find($this->submitted_by);

        if ($submitter && (int) $submitter->status === 1) {
            Notification::send($submitter, new ExpenseClaimDecided($this));
        }
    }

    // ─── Replicate — docs/replicate-records.md ───

    /**
     * A repeat of a claim, such as the same monthly travel: same employee, type, description and lines.
     * The copy is a draft dated today, so it tells no approver and posts nothing until it is submitted.
     * It has no submission, decision, approval trail, payslip, payment or accrual, and no receipts:
     * a receipt is evidence for the original spending, not for this one.
     */
    public function replicationResets(): array
    {
        return [
            'status' => self::STATUS_DRAFT,
            'claimed_on' => fn (): string => now()->toDateString(),
            'receipt_path' => null,
            'submitted_by' => fn (): ?int => auth()->id(),
            'submitted_at' => null,
            'decided_by' => null,
            'decided_at' => null,
            'refusal_reason' => null,
            'payslip_id' => null,
            'payment_id' => null,
            'journal_entry_id' => null,
        ];
    }

    public function replicationRelations(): array
    {
        return ['lines' => ['receipt_path']];
    }
}
