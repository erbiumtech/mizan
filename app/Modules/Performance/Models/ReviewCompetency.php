<?php

namespace App\Modules\Performance\Models;

use App\Models\TenantModel as Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One thing a review asks about, and what it is worth — docs/saas-launch-plan.md §14.6. */
class ReviewCompetency extends Model
{
    protected $fillable = ['review_template_id', 'name', 'description', 'weight', 'sort'];

    protected $casts = ['weight' => 'decimal:2', 'sort' => 'integer'];

    public function template(): BelongsTo
    {
        return $this->belongsTo(ReviewTemplate::class, 'review_template_id');
    }
}
