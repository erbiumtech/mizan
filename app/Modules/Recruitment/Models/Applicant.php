<?php

namespace App\Modules\Recruitment\Models;

use App\Models\TenantModel as Model;
use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Prunable;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

/**
 * Somebody who applied.
 *
 * **The most sensitive data this application holds, and it is held about people the
 * company never hired.** So: Prunable with a retention setting, the CV file deleted with
 * the row, and the help text saying plainly that the company is the data controller.
 *
 * Separate from `applications` because one person applies twice, and a system that cannot
 * see that has no memory.
 */
class Applicant extends Model
{
    use Auditable;
    use Prunable;

    protected $fillable = [
        'name', 'email', 'phone', 'cnic', 'source', 'resume_path', 'linkedin',
        'current_employer', 'notice_period_days', 'expected_salary', 'notes',
    ];

    protected $casts = [
        'notice_period_days' => 'integer',
        'expected_salary' => 'decimal:2',
    ];

    /**
     * Applicants whose last application was rejected longer ago than the retention
     * window.
     *
     * Deliberately keyed on the REJECTION rather than on the row's age: somebody still
     * in a process, or hired, is not a candidate for pruning however long ago they
     * applied.
     */
    public function prunable(): Builder
    {
        $months = (int) setting('recruitment.retention_months', 24);
        $cutoff = now()->subMonths($months);

        return static::query()
            ->whereDoesntHave('applications', fn (Builder $query) => $query
                ->where('stage', '!=', Application::STAGE_REJECTED)
                ->orWhereNull('rejected_at')
                ->orWhere('rejected_at', '>', $cutoff));
    }

    /**
     * Delete the CV with the row.
     *
     * The reason this hook exists rather than relying on the cascade: a pruned row that
     * leaves its file behind means the company still holds a stranger's CV while
     * believing it does not, which is worse than not pruning at all.
     */
    protected function pruning(): void
    {
        if ($this->resume_path) {
            Storage::disk('public')->delete($this->resume_path);
        }
    }

    public function applications(): HasMany
    {
        return $this->hasMany(Application::class);
    }

    /** Whether this person has been here before — the memory the split table buys. */
    public function hasAppliedBefore(): bool
    {
        return $this->applications()->count() > 1;
    }
}
