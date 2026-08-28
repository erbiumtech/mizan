<?php

namespace App\Modules\Accounting\Models;

use App\Models\TenantModel as Model;
use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Builder;

/**
 * Tax withheld from one payment — Phase 4, and what the §165 statement is made of.
 *
 * A journal line would carry the amount and neither of the two figures the statement has to state: the
 * gross the rate was applied to, and the rate. Both are snapshotted here, along with whether the payee was
 * a filer on the day, so a supplier who starts filing next year does not retrospectively change what was
 * deducted from them last year.
 *
 * There is no service that edits one of these. A deduction is written when its payment is approved and
 * read afterwards; correcting it means reversing the payment, which is the same answer the rest of the
 * ledger gives.
 */
class WithholdingDeduction extends Model
{
    use Auditable;

    protected $fillable = [
        'payment_id', 'withholding_section_id', 'beneficiary_id',
        'taxable_amount', 'rate', 'amount', 'was_filer', 'deducted_on', 'journal_entry_id',
    ];

    protected $casts = [
        'taxable_amount' => 'decimal:2',
        'rate' => 'decimal:3',
        'amount' => 'decimal:2',
        'was_filer' => 'boolean',
        'deducted_on' => 'date',
    ];

    public function payment()
    {
        return $this->belongsTo(Payment::class);
    }

    public function section()
    {
        return $this->belongsTo(WithholdingSection::class, 'withholding_section_id');
    }

    public function beneficiary()
    {
        return $this->belongsTo(Beneficiary::class);
    }

    public function journalEntry()
    {
        return $this->belongsTo(JournalEntry::class);
    }

    public function scopeBetween(Builder $query, string $from, string $to): Builder
    {
        return $query->whereDate('deducted_on', '>=', $from)->whereDate('deducted_on', '<=', $to);
    }

    /**
     * Who the tax was withheld from, in the words the statement needs.
     *
     * The beneficiary where there is one, which is every deduction this application makes: a section is
     * assigned on the beneficiary record, so nothing else can be withheld from. The fallback exists because
     * a payment can be raised against an employee or a payslip, and a statement that says "Unknown payee"
     * is a bug somebody can find — a statement that omits the row is not.
     */
    public function payeeName(): string
    {
        if ($this->beneficiary) {
            return (string) $this->beneficiary->name;
        }

        $payable = $this->payment?->payable;

        return $payable ? (string) ($payable->name ?? class_basename($payable)) : 'Unknown payee';
    }

    /** Their NTN or CNIC, which the statement is filed against. */
    public function payeeIdentity(): ?string
    {
        return $this->beneficiary?->taxIdentity();
    }
}
