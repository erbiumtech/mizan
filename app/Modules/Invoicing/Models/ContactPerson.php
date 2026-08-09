<?php

namespace App\Modules\Invoicing\Models;

use App\Models\TenantModel as Model;
use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One named person at a client or supplier.
 */
class ContactPerson extends Model
{
    use Auditable;

    // Laravel would pluralise this to contact_people; the table is contact_persons.
    protected $table = 'contact_persons';

    protected $fillable = ['contact_id', 'name', 'title', 'email', 'phone', 'is_primary', 'notes'];

    protected $casts = ['is_primary' => 'boolean'];

    protected static function booted(): void
    {
        // One primary per contact, asserted rather than assumed — the same shape the
        // fiscal year and the default tax rate use, and for the same reason: a model
        // stood down since being loaded is not dirty when set again.
        static::saved(function (self $person): void {
            if (! $person->is_primary) {
                return;
            }

            static::whereKey($person->getKey())->update(['is_primary' => true]);

            static::where('contact_id', $person->contact_id)
                ->whereKeyNot($person->getKey())
                ->where('is_primary', true)
                ->update(['is_primary' => false]);
        });
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    public function label(): string
    {
        return $this->title ? "{$this->name} ({$this->title})" : $this->name;
    }
}
