<?php

namespace App\Modules\Recruitment\Models;

use App\Models\TenantModel as Model;
use App\Modules\Core\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One interviewer's answer — docs/saas-launch-plan.md §14.6. One per person per interview. */
class InterviewFeedback extends Model
{
    protected $table = 'interview_feedback';

    public const RECOMMEND_HIRE = 'hire';

    public const RECOMMEND_MAYBE = 'maybe';

    public const RECOMMEND_NO = 'no';

    public const RECOMMENDATIONS = [self::RECOMMEND_HIRE => 'Hire', self::RECOMMEND_MAYBE => 'Worth another look', self::RECOMMEND_NO => 'Do not hire'];

    protected $fillable = ['interview_id', 'user_id', 'rating', 'recommendation', 'strengths', 'concerns', 'submitted_at'];

    protected $casts = ['rating' => 'integer', 'submitted_at' => 'datetime'];

    public function interview(): BelongsTo
    {
        return $this->belongsTo(Interview::class);
    }

    public function interviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function isSubmitted(): bool
    {
        return $this->submitted_at !== null;
    }
}
