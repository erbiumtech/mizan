<?php

namespace App\Modules\Construction\Models;

use App\Models\TenantModel as Model;
use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One drawing on one transmittal.
 *
 * Points at the **revision**, not the document, and that is the whole design: a transmittal is a record of
 * which version went out, so pointing at the document alone would make it say something different after the
 * next issue — which is exactly the record somebody disputes later.
 */
class TransmittalItem extends Model
{
    use Auditable;

    protected $table = 'construction_transmittal_items';

    protected $fillable = ['transmittal_id', 'document_revision_id', 'copies', 'media'];

    protected $casts = ['copies' => 'integer'];

    protected $attributes = ['copies' => 1];

    public function transmittal(): BelongsTo
    {
        return $this->belongsTo(Transmittal::class, 'transmittal_id');
    }

    public function revision(): BelongsTo
    {
        return $this->belongsTo(DocumentRevision::class, 'document_revision_id');
    }
}
