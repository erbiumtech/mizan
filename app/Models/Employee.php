<?php

namespace App\Models;

use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Employee extends Model
{
    use Auditable;

    protected $fillable = [
        'user_id', 'employee_id', 'phone', 'gender',
        'is_active', 'designation', 'department',
        'date_of_joining', 'nic', 'bank_id', 'bank_name', 'bank_account_no', 'iban_no',
        'address_line_1', 'address_line_2'
    ];

    protected $casts = [
        'date_of_joining' => 'date',
    ];

    public function bank()
    {
        return $this->belongsTo(Bank::class);
    }


    public function setting(): HasOne
    {
        return $this->hasOne(EmployeeSetting::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
