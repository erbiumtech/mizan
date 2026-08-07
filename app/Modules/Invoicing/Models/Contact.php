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
        'ntn', 'cnic', 'bank_id', 'is_active', 'payment_terms_days',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'payment_terms_days' => 'integer',
    ];

    /**
     * The terms worth offering as a choice. Anything else can be typed.
     *
     * Null is deliberately absent from the list and offered as its own option on
     * the form: "none agreed" and "due on receipt" are different facts, and a
     * dropdown that silently treats one as the other would put every contact
     * nobody has thought about into the overdue bucket on day one.
     *
     * @var array<int, string>
     */
    public const TERMS = [
        0 => 'Due on receipt',
        7 => 'Net 7 days',
        15 => 'Net 15 days',
        30 => 'Net 30 days',
        45 => 'Net 45 days',
        60 => 'Net 60 days',
        90 => 'Net 90 days',
    ];

    public function paymentTermsLabel(): string
    {
        if ($this->payment_terms_days === null) {
            return 'None agreed';
        }

        return self::TERMS[$this->payment_terms_days] ?? "Net {$this->payment_terms_days} days";
    }

    /**
     * When an invoice dated $invoiceDate falls due under this contact's terms,
     * or null when none are agreed.
     */
    public function dueDateFor(\Illuminate\Support\Carbon|string $invoiceDate): ?\Illuminate\Support\Carbon
    {
        if ($this->payment_terms_days === null) {
            return null;
        }

        return \Illuminate\Support\Carbon::parse($invoiceDate)
            ->startOfDay()
            ->addDays($this->payment_terms_days);
    }

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
