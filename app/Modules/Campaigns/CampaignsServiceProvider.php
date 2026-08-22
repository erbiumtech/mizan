<?php

namespace App\Modules\Campaigns;

use App\Modules\Campaigns\Models\Campaign;
use App\Modules\Campaigns\Models\CampaignSend;
use App\Modules\Campaigns\Models\Consent;
use App\Modules\Campaigns\Models\Segment;
use App\Modules\Campaigns\Policies\CampaignPolicy;
use App\Modules\Campaigns\Policies\CampaignSendPolicy;
use App\Modules\Campaigns\Policies\ConsentPolicy;
use App\Modules\Campaigns\Policies\SegmentPolicy;
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
    }
}
