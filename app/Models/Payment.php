<?php

namespace App\Models;

use App\Models\TenantModel as Model;
use App\Traits\Auditable;

class Payment extends Model
{
    use Auditable;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_EXPORTED = 'exported';

    public const STATUS_PAID = 'paid';

    public const RTGS_THRESHOLD = 1000000;

    protected $fillable = [
        'payable_type', 'payable_id', 'transaction_type_id', 'company_bank_account_id',
        'payslip_id', 'amount', 'reference', 'details', 'value_date',
        'payment_type', 'status', 'journal_entry_id',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'value_date' => 'date',
    ];

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
     * explicit override → RTGS above threshold → BT when the beneficiary
     * banks with the debiting bank → beneficiary default → IBFT.
     */
    public function resolvedPaymentType(): string
    {
        if ($this->payment_type) {
            return $this->payment_type;
        }

        if ((float) $this->amount >= self::RTGS_THRESHOLD) {
            return 'RTGS';
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
     * Beneficiary-side columns for the bank file, from either an
     * Employee or a Beneficiary payable.
     */
    public function beneficiaryDetails(): array
    {
        $payable = $this->payable;

        if ($payable instanceof Employee) {
            return [
                'name' => $payable->user->name ?? $payable->employee_id,
                'account' => $payable->iban_no ?: $payable->bank_account_no,
                'bank_code' => $payable->bank?->bank_code ?? $payable->bank_code ?? '',
                'bank_name' => $payable->bank?->bank_name ?? $payable->bank_name ?? '',
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
            'account' => $payable->iban ?: $payable->account_no,
            'bank_code' => $payable->bank?->bank_code ?? '',
            'bank_name' => $payable->bank?->bank_name ?? '',
            'email' => $payable->email ?? '',
            'id_number' => $payable->id_number ?? '',
            'id_type' => $payable->id_number ? ($payable->id_type ?? 'CNIC') : '',
            'phone' => $payable->phone ?? '',
            'address_1' => $payable->address_line_1 ?? '',
            'address_2' => $payable->address_line_2 ?? '',
        ];
    }
}
