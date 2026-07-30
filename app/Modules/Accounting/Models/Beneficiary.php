<?php

namespace App\Modules\Accounting\Models;

use App\Models\Concerns\HasCustomFields;
use App\Models\TenantModel as Model;
use App\Traits\Auditable;

class Beneficiary extends Model
{
    use Auditable, HasCustomFields;

    protected $fillable = [
        'name', 'bank_id', 'account_no', 'iban', 'id_type', 'id_number',
        'address_line_1', 'address_line_2', 'email', 'phone',
        'transaction_type_id', 'payment_type', 'is_petty_cash_custodian', 'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'is_petty_cash_custodian' => 'boolean',
    ];

    public function bank()
    {
        return $this->belongsTo(Bank::class);
    }

    public function transactionType()
    {
        return $this->belongsTo(TransactionType::class);
    }

    public function payments()
    {
        return $this->morphMany(Payment::class, 'payable');
    }
}
