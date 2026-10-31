<?php

namespace App\Modules\Accounting\Models;

use App\Models\Concerns\HasCustomFields;
use App\Models\TenantModel as Model;
use App\Modules\Core\Models\Bank;
use App\Support\Replication\Replicable;
use App\Support\Replication\ReplicatesRecords;
use App\Traits\Auditable;

class Beneficiary extends Model implements Replicable
{
    use Auditable, HasCustomFields, ReplicatesRecords;

    protected $fillable = [
        'name', 'bank_id', 'account_no', 'iban', 'id_type', 'id_number',
        'address_line_1', 'address_line_2', 'email', 'phone',
        'transaction_type_id', 'payment_type', 'is_petty_cash_custodian', 'is_active',
        'is_contractor', 'engagement', 'engaged_on', 'engagement_ended_on',
        'withholding_section_id', 'is_filer',
    ];

    protected $casts = [
        'is_contractor' => 'boolean',
        'is_filer' => 'boolean',
        'engaged_on' => 'date',
        'engagement_ended_on' => 'date',
        'is_active' => 'boolean',
        'is_petty_cash_custodian' => 'boolean',
    ];

    /**
     * At most one petty cash custodian.
     *
     * PettyCashService::replenish() pays the *first* active custodian it finds, so
     * with two of them which beneficiary receives the money depends on row order.
     * Enforced on write rather than by validation so it holds for the seeders and
     * for any future call site, not only the form.
     */
    protected static function booted(): void
    {
        static::saved(function (self $beneficiary): void {
            if (! $beneficiary->is_petty_cash_custodian) {
                return;
            }

            static::query()
                ->whereKeyNot($beneficiary->getKey())
                ->where('is_petty_cash_custodian', true)
                ->update(['is_petty_cash_custodian' => false]);
        });
    }

    public function scopePettyCashCustodian($query)
    {
        return $query->where('is_petty_cash_custodian', true)->where('is_active', true);
    }

    public function bank()
    {
        return $this->belongsTo(Bank::class);
    }

    public function transactionType()
    {
        return $this->belongsTo(TransactionType::class);
    }

    public function subscriptions()
    {
        return $this->hasMany(BeneficiarySubscription::class);
    }

    /**
     * Which section of the Ordinance applies to what this supplier is paid for — Phase 4.
     *
     * Null for almost everybody, and that is the design: nothing is withheld from a beneficiary with no
     * section, so assigning one is the single act that turns the deduction on for that supplier.
     */
    public function withholdingSection()
    {
        return $this->belongsTo(WithholdingSection::class, 'withholding_section_id');
    }

    public function withholdingDeductions()
    {
        return $this->hasMany(WithholdingDeduction::class);
    }

    public function scopeContractors($query)
    {
        return $query->where('is_contractor', true);
    }

    /**
     * Their tax identity, for the year-end summary. NTN where they have one, since a
     * business files under that; the CNIC otherwise.
     */
    public function taxIdentity(): ?string
    {
        return $this->id_number ?: null;
    }

    public function payments()
    {
        return $this->morphMany(Payment::class, 'payable');
    }

    // ─── Replicate — docs/replicate-records.md ───

    /**
     * A payee "like this one": same bank, payment type, transaction type and withholding treatment, never
     * the same person. The account number, IBAN, ID number, contact details and address are left for
     * whoever fills the copy in, so nobody is paid into someone else's account by accident.
     */
    public function replicationResets(): array
    {
        return [
            'account_no' => null,
            'iban' => null,
            'id_number' => null,
            'email' => null,
            'phone' => null,
            'address_line_1' => null,
            'address_line_2' => null,
            'is_petty_cash_custodian' => false,
            'engaged_on' => null,
            'engagement_ended_on' => null,
        ];
    }
}
