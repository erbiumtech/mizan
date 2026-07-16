<?php

namespace App\Models;

use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Model;

class Beneficiary extends Model
{
    use Auditable;

    protected $fillable = [
        'name', 'bank_id', 'account_no', 'iban', 'id_type', 'id_number',
        'address_line_1', 'address_line_2', 'email', 'phone',
        'transaction_type_id', 'payment_type', 'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
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
