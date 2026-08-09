<?php

namespace App\Modules\Core\Models;

use App\Models\TenantModel as Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * A custom field value bound to a specific domain record (per tenant).
 */
class CustomFieldValue extends Model
{
    protected $fillable = ['custom_field_id', 'entity_type', 'entity_id', 'value'];

    protected $casts = [
        'value' => 'json',
    ];

    public function customField(): BelongsTo
    {
        return $this->belongsTo(CustomField::class);
    }

    public function entity(): MorphTo
    {
        return $this->morphTo();
    }
}
