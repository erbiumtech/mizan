<?php

namespace App\Modules\Accounting\Models;

use App\Models\TenantModel as Model;
use App\Traits\Auditable;

class TransactionType extends Model
{
    use Auditable;

    protected $fillable = ['name', 'code', 'account_id', 'description', 'is_active'];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function account()
    {
        return $this->belongsTo(Account::class);
    }

    public function payments()
    {
        return $this->hasMany(Payment::class);
    }

    public function companyBankAccounts()
    {
        return $this->hasMany(CompanyBankAccount::class);
    }

    public function journalEntries()
    {
        return $this->hasMany(JournalEntry::class);
    }

    public function defaultCompanyBankAccount(): ?CompanyBankAccount
    {
        return $this->companyBankAccounts()
            ->where('is_active', true)
            ->orderByDesc('is_default')
            ->first();
    }

    public static function byCode(string $code): ?self
    {
        return static::where('code', $code)->first();
    }
}
