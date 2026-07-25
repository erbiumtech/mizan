<?php

namespace App\Models;

use App\Models\TenantModel as Model;
use App\Traits\Auditable;

class PettyCashVoucher extends Model
{
    use Auditable;

    protected $fillable = [
        'voucher_no', 'date', 'details', 'amount',
        'transaction_type_id', 'receipt_path', 'journal_entry_id',
    ];

    protected $casts = [
        'date' => 'date',
        'amount' => 'decimal:2',
    ];

    protected static function booted()
    {
        static::creating(function (PettyCashVoucher $voucher) {
            if (empty($voucher->voucher_no)) {
                $year = now()->year;
                $last = static::where('voucher_no', 'like', "PCV-{$year}-%")->orderByDesc('id')->first();
                $next = $last ? ((int) substr($last->voucher_no, -4)) + 1 : 1;
                $voucher->voucher_no = sprintf('PCV-%d-%04d', $year, $next);
            }
        });
    }

    public function transactionType()
    {
        return $this->belongsTo(TransactionType::class);
    }

    public function journalEntry()
    {
        return $this->belongsTo(JournalEntry::class);
    }
}
