<?php

namespace App\Modules\Accounting\Models;

use App\Models\TenantModel as Model;
use App\Modules\Accounting\Support\BankFileAccount;
use App\Modules\Employees\Models\Employee;
use App\Modules\Payroll\Models\Payslip;
use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Builder;

class Payment extends Model
{
    use Auditable;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_EXPORTED = 'exported';

    public const STATUS_PAID = 'paid';

    public const RTGS_THRESHOLD = 1000000;

    /** TransactionType code that marks a payroll transfer (TransactionTypeSeeder). */
    public const SALARY_TRANSACTION_CODE = 'salary';

    protected $fillable = [
        'payable_type', 'payable_id', 'transaction_type_id', 'company_bank_account_id',
        'payslip_id', 'beneficiary_subscription_id', 'period',
        'amount', 'reference', 'details', 'value_date',
        'payment_type', 'status', 'journal_entry_id',
        'batch_reference', 'released_at',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'value_date' => 'date',
        'period' => 'date',
        'released_at' => 'datetime',
    ];

    /**
     * Not yet sent to the bank. What a batch is built from, and what keeps a
     * released payment out of the next one.
     */
    public function scopeUnreleased(Builder $query): Builder
    {
        return $query->whereIn('status', [self::STATUS_DRAFT, self::STATUS_APPROVED]);
    }

    public function scopeInBatch(Builder $query, string $reference): Builder
    {
        return $query->where('batch_reference', $reference);
    }

    public function isReleased(): bool
    {
        return $this->released_at !== null;
    }

    /**
     * May this payment go in a batch?
     *
     * A salary is only releasable once the employee has accepted the payslip it
     * pays: the acknowledgement is the point of the review step, and paying an
     * unacknowledged figure is the thing it exists to prevent. Payments with no
     * payslip behind them — a supplier, a petty cash top-up — have nobody to
     * acknowledge them and are releasable as soon as they exist.
     */
    public function isReleasable(): bool
    {
        if ($this->isReleased() || ! in_array($this->status, [self::STATUS_DRAFT, self::STATUS_APPROVED], true)) {
            return false;
        }

        $payslip = $this->payslip;

        return $payslip === null || $payslip->employee_review === Payslip::REVIEW_ACCEPTED;
    }

    /**
     * Why a held row is held, as a category rather than a sentence.
     *
     * The sentence below names the batch or quotes a rejection, which is right against one
     * row and useless for grouping — "Released in SAL-2026-07-B1" and "…-B2" are the same
     * fact twice. A summary line that groups by this can state what is actually true of each
     * group instead of asserting one reason for all of them, which is how a screen came to
     * say "held back until accepted" about payments that had already gone out.
     */
    public const BLOCK_RELEASED = 'released';

    public const BLOCK_STATUS = 'status';

    public const BLOCK_REJECTED = 'rejected';

    public const BLOCK_UNACCEPTED = 'unaccepted';

    /** @var array<string, string> */
    public const BLOCK_LABELS = [
        self::BLOCK_RELEASED => 'Already released in an earlier batch',
        self::BLOCK_STATUS => 'No longer releasable',
        self::BLOCK_REJECTED => 'Rejected by the employee',
        self::BLOCK_UNACCEPTED => 'Held back until accepted',
    ];

    public function releaseBlockedCategory(): ?string
    {
        if ($this->isReleasable()) {
            return null;
        }

        if ($this->isReleased()) {
            return self::BLOCK_RELEASED;
        }

        if (! in_array($this->status, [self::STATUS_DRAFT, self::STATUS_APPROVED], true)) {
            return self::BLOCK_STATUS;
        }

        return $this->payslip?->employee_review === Payslip::REVIEW_REJECTED
            ? self::BLOCK_REJECTED
            : self::BLOCK_UNACCEPTED;
    }

    public static function blockLabel(?string $category): string
    {
        return self::BLOCK_LABELS[$category] ?? 'Held back';
    }

    /**
     * Why it cannot go out, in words, for a row the user can see but not select.
     */
    public function releaseBlockedReason(): ?string
    {
        if ($this->isReleasable()) {
            return null;
        }

        if ($this->isReleased()) {
            return 'Released in '.($this->batch_reference ?: 'an earlier batch');
        }

        if (! in_array($this->status, [self::STATUS_DRAFT, self::STATUS_APPROVED], true)) {
            return 'Already '.$this->status;
        }

        return match ($this->payslip?->employee_review) {
            Payslip::REVIEW_REJECTED => 'Employee rejected the payslip'
                .($this->payslip->employee_rejection_reason ? ': '.$this->payslip->employee_rejection_reason : ''),
            default => 'Employee has not accepted the payslip yet',
        };
    }

    public function payable()
    {
        return $this->morphTo();
    }

    public function transactionType()
    {
        return $this->belongsTo(TransactionType::class);
    }

    public function companyBankAccount()
    {
        return $this->belongsTo(CompanyBankAccount::class);
    }

    public function subscription()
    {
        return $this->belongsTo(BeneficiarySubscription::class, 'beneficiary_subscription_id');
    }

    public function payslip()
    {
        return $this->belongsTo(Payslip::class);
    }

    public function journalEntry()
    {
        return $this->belongsTo(JournalEntry::class);
    }

    /**
     * The iPayments Payment Type for this transaction:
     * explicit override → RTGS above threshold → PAY for an employee salary →
     * BT when the beneficiary banks with the debiting bank → beneficiary
     * default → IBFT.
     */
    public function resolvedPaymentType(): string
    {
        if ($this->payment_type) {
            return $this->payment_type;
        }

        // Above the threshold the bank requires RTGS regardless of what the
        // payment is for, so this outranks the salary rule below: a high-value
        // salary settles as RTGS, not PAY.
        if ((float) $this->amount >= self::RTGS_THRESHOLD) {
            return 'RTGS';
        }

        // Otherwise a salary transfer is its own payment type, outranking the
        // intra-bank and beneficiary-default routing below.
        if ($this->isEmployeeSalary()) {
            return (string) (setting('ipayments')['salary_payment_type'] ?? 'PAY');
        }

        $payeeBankId = $this->payable instanceof Beneficiary
            ? $this->payable->bank_id
            : $this->payable?->bank_id;

        $debitBankId = $this->companyBankAccount?->bank_id;

        if ($payeeBankId && $debitBankId && $payeeBankId === $debitBankId) {
            return 'BT';
        }

        if ($this->payable instanceof Beneficiary && $this->payable->payment_type) {
            return $this->payable->payment_type;
        }

        return 'IBFT';
    }

    /**
     * Whether this payment is an employee salary transfer.
     *
     * Settling a payslip is decisive. Otherwise it must be a payment to an
     * employee under the salary transaction type — an employee can also be paid
     * an advance or a reimbursement, and those are ordinary transfers, not
     * payroll, so "the payee is an employee" alone is not enough.
     */
    public function isEmployeeSalary(): bool
    {
        if ($this->payslip_id) {
            return true;
        }

        if (! $this->payable instanceof Employee) {
            return false;
        }

        return $this->transactionType?->code === self::SALARY_TRANSACTION_CODE;
    }

    /**
     * Beneficiary-side columns for the bank file, from either an
     * Employee or a Beneficiary payable.
     */
    public function beneficiaryDetails(): array
    {
        $payable = $this->payable;

        if ($payable instanceof Employee) {
            return [
                'name' => $payable->user->name ?? $payable->employee_id,
                'account' => BankFileAccount::value(
                    $payable->iban_no,
                    $payable->bank_account_no,
                    $payable->bank,
                    $payable->bank_short_code,
                ),
                'bank_code' => $payable->bank?->bank_code ?? $payable->bank_code ?? '',
                'bank_name' => $payable->bank?->bank_name ?? $payable->bank_name ?? '',
                'bank_short_code' => $payable->bank?->bank_short_code ?? $payable->bank_short_code ?? '',
                'email' => $payable->user->email ?? '',
                'id_number' => $payable->nic ?? '',
                'id_type' => $payable->nic ? 'CNIC' : '',
                'phone' => $payable->phone ?? '',
                'address_1' => $payable->address_line_1 ?? '',
                'address_2' => $payable->address_line_2 ?? '',
            ];
        }

        return [
            'name' => $payable->name ?? '',
            'account' => BankFileAccount::value(
                $payable->iban ?? null,
                $payable->account_no ?? null,
                $payable->bank ?? null,
            ),
            'bank_code' => $payable->bank?->bank_code ?? '',
            'bank_name' => $payable->bank?->bank_name ?? '',
            'bank_short_code' => $payable->bank?->bank_short_code ?? '',
            'email' => $payable->email ?? '',
            'id_number' => $payable->id_number ?? '',
            'id_type' => $payable->id_number ? ($payable->id_type ?? 'CNIC') : '',
            'phone' => $payable->phone ?? '',
            'address_1' => $payable->address_line_1 ?? '',
            'address_2' => $payable->address_line_2 ?? '',
        ];
    }
}
