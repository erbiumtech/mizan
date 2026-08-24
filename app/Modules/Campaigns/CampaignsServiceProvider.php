<?php

namespace App\Modules\Campaigns;

use App\Modules\Campaigns\Filament\Pages\CampaignPerformance;
use App\Modules\Campaigns\Filament\Pages\ConsentRegister;
use App\Modules\Campaigns\Models\Campaign;
use App\Modules\Campaigns\Models\CampaignSend;
use App\Modules\Campaigns\Models\Consent;
use App\Modules\Campaigns\Models\Segment;
use App\Modules\Campaigns\Policies\CampaignPolicy;
use App\Modules\Campaigns\Policies\CampaignSendPolicy;
use App\Modules\Campaigns\Policies\ConsentPolicy;
use App\Modules\Campaigns\Policies\SegmentPolicy;
use App\Modules\Campaigns\Support\CampaignReports;
use App\Modules\Campaigns\Support\ConsentReports;
use App\Support\Reporting\ReportCatalogue;
use App\Support\Reporting\ReportRenderers;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class CampaignsServiceProvider extends ServiceProvider
{
    /** @var array<class-string, class-string> */
    private const POLICIES = [
        Segment::class => SegmentPolicy::class,
        Campaign::class => CampaignPolicy::class,
        CampaignSend::class => CampaignSendPolicy::class,
        Consent::class => ConsentPolicy::class,
    ];

    public function boot(): void
    {
        foreach (self::POLICIES as $model => $policy) {
            Gate::policy($model, $policy);
        }

        $this->registerReports();
    }

    /**
     * The consent register and campaign performance — `docs/reports-expansion-plan.md` Phases 3.10 and 3.11.
     *
     * Filed under *Sales & pipeline* rather than with the operational reports: the person who needs to know
     * whether a permission can be defended is whoever is about to run the campaign.
     *
     * Registered unconditionally. The page gates itself on `moduleIsAvailable()` and `Reports::sections()`
     * filters through `canAccess()`, so a company without the campaigns module sees no entry rather than a
     * report that fails when opened.
     */
    private function registerReports(): void
    {
        ReportCatalogue::register(
            'Sales & pipeline',
            ConsentRegister::class,
            'Who may be contacted on which channel, with the evidence behind each permission.',
        );

        ReportCatalogue::register(
            'Sales & pipeline',
            CampaignPerformance::class,
            'What each campaign reached, what it skipped for want of consent, and why sends failed.',
        );

        ReportRenderers::register(
            'ConsentRegister',
            fn (string $asOf): array => app(ConsentReports::class)->consentRegister($asOf),
        );
        ReportRenderers::register(
            'CampaignPerformance',
            fn (string $asOf): array => app(CampaignReports::class)->campaignPerformance($asOf),
        );
    }
}
