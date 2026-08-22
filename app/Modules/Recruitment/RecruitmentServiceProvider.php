<?php

namespace App\Modules\Recruitment;

use App\Modules\Recruitment\Models\Applicant;
use App\Modules\Recruitment\Models\Application;
use App\Modules\Recruitment\Models\Interview;
use App\Modules\Recruitment\Models\Offer;
use App\Modules\Recruitment\Models\Vacancy;
use App\Modules\Recruitment\Policies\ApplicantPolicy;
use App\Modules\Recruitment\Policies\ApplicationPolicy;
use App\Modules\Recruitment\Policies\InterviewPolicy;
use App\Modules\Recruitment\Policies\OfferPolicy;
use App\Modules\Recruitment\Policies\VacancyPolicy;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class RecruitmentServiceProvider extends ServiceProvider
{
    /** @var array<class-string, class-string> */
    private const POLICIES = [
        Vacancy::class => VacancyPolicy::class,
        Applicant::class => ApplicantPolicy::class,
        Application::class => ApplicationPolicy::class,
        Interview::class => InterviewPolicy::class,
        Offer::class => OfferPolicy::class,
    ];

    public function boot(): void
    {
        foreach (self::POLICIES as $model => $policy) {
            Gate::policy($model, $policy);
        }

        $this->loadRoutesFrom(__DIR__.'/routes/console.php');
    }
}
