<?php

namespace App\Modules\Invoicing\Models;

use App\Models\Concerns\HasCustomFields;
use App\Models\TenantModel as Model;
use App\Modules\Accounting\Models\Bank;
use App\Traits\Auditable;

class Contact extends Model
{
    use Auditable, HasCustomFields;

    public const KIND_CUSTOMER = 'customer';

    public const KIND_SUPPLIER = 'supplier';

    public const KIND_BOTH = 'both';

    protected $fillable = [
        'name', 'kind', 'email', 'phone', 'address_line_1', 'address_line_2',
        'ntn', 'cnic', 'bank_id', 'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function people()
    {
        return $this->hasMany(ContactPerson::class);
    }

    /**
     * Where correspondence goes.
     *
     * The primary named person if there is one, otherwise the contact's own address —
     * so adding people to a client changes who is written to, and adding none changes
     * nothing.
     */
    public function correspondenceEmail(): ?string
    {
        $primary = $this->people()->where('is_primary', true)->value('email');

        return $primary ?: $this->email;
    }

    public function invoices()
    {
        return $this->hasMany(Invoice::class);
    }

    public function bank()
    {
        return $this->belongsTo(Bank::class);
    }

    public function isCustomer(): bool
    {
        return in_array($this->kind, [self::KIND_CUSTOMER, self::KIND_BOTH]);
    }

    public function isSupplier(): bool
    {
        return in_array($this->kind, [self::KIND_SUPPLIER, self::KIND_BOTH]);
    }
}
