<?php

namespace App\Modules\Campaigns\Jobs;

use App\Modules\Campaigns\Models\Campaign;
use App\Modules\Campaigns\Services\CampaignSender;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * One batch of a campaign, then the next one after the company's pause — docs/crms-plan.md §6.
 *
 * Tenant-aware by the queue default, so it runs against the company that sent it. One try: a batch
 * that throws has already paused its campaign (CampaignSender::deliverBatch), and a blind retry would
 * be the thing that sends somebody the same message twice. Somebody presses Send to resume.
 */
class DeliverCampaign implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 1;

    public function __construct(public int $campaignId) {}

    public function handle(CampaignSender $sender): void
    {
        $campaign = Campaign::query()->find($this->campaignId);

        if ($campaign === null) {
            return;
        }

        $remaining = $sender->deliverBatch($campaign);

        if ($remaining > 0) {
            static::dispatch($this->campaignId)->delay(now()->addSeconds(CampaignSender::batchInterval()));
        }
    }
}
