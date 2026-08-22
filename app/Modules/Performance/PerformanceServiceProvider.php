<?php

namespace App\Modules\Performance;

use App\Modules\Performance\Models\Goal;
use App\Modules\Performance\Models\OneToOne;
use App\Modules\Performance\Models\Review;
use App\Modules\Performance\Models\ReviewCycle;
use App\Modules\Performance\Policies\GoalPolicy;
use App\Modules\Performance\Policies\OneToOnePolicy;
use App\Modules\Performance\Policies\ReviewCyclePolicy;
use App\Modules\Performance\Policies\ReviewPolicy;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class PerformanceServiceProvider extends ServiceProvider
{
    /** @var array<class-string, class-string> */
    private const POLICIES = [
        ReviewCycle::class => ReviewCyclePolicy::class,
        Review::class => ReviewPolicy::class,
        Goal::class => GoalPolicy::class,
        OneToOne::class => OneToOnePolicy::class,
    ];

    public function boot(): void
    {
        foreach (self::POLICIES as $model => $policy) {
            Gate::policy($model, $policy);
        }
    }
}
