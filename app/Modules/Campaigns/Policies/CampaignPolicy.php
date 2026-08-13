<?php

namespace App\Modules\Campaigns\Policies;

use App\Modules\Campaigns\Models\Campaign;
use App\Modules\Core\Models\User;

/**
 * Campaigns, segments and their sends share one group. Consent has its own rules below.
 *
 * SENDING is a separate permission from creating: this is the one module that can damage the
 * company's reputation, and drafting a campaign is not the same decision as putting it out.
 */
class CampaignPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('CampaignView');
    }

    public function view(User $user, Campaign $record): bool
    {
        return $user->hasPermissionTo('CampaignView');
    }

    public function create(User $user): bool
    {
        return $user->hasPermissionTo('CampaignCreate');
    }

    public function update(User $user, Campaign $record): bool
    {
        return $user->hasPermissionTo('CampaignUpdate');
    }

    public function delete(User $user, Campaign $record): bool
    {
        return $user->hasPermissionTo('CampaignDelete') && $record->isSendable();
    }

    /**
     * Sending is its own permission.
     *
     * Drafting a campaign is a writing task; sending it is an irreversible act that reaches
     * people outside the company — and on WhatsApp, one that can cost the company its number.
     * The two are not the same decision.
     */
    public function send(User $user, Campaign $record): bool
    {
        return $user->hasPermissionTo('CampaignSend') && $record->isSendable();
    }
}
