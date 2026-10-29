<?php

namespace App\Modules\Performance\Models;

use App\Models\TenantModel as Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** What the two of them said about one competency — docs/saas-launch-plan.md §14.6. */
class ReviewRating extends Model
{
    protected $fillable = ['review_id', 'review_competency_id', 'self_rating', 'manager_rating', 'self_note', 'manager_note'];

    protected $casts = ['self_rating' => 'integer', 'manager_rating' => 'integer'];

    public function review(): BelongsTo
    {
        return $this->belongsTo(Review::class);
    }

    public function competency(): BelongsTo
    {
        return $this->belongsTo(ReviewCompetency::class, 'review_competency_id');
    }
}
