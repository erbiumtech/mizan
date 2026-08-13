<?php

namespace App\Modules\Campaigns\Policies;

use App\Modules\Campaigns\Models\Segment;
use App\Modules\Core\Models\User;

/**
 * Campaigns, segments and their sends share one group. Consent has its own rules below.
 *
 * SENDING is a separate permission from creating: this is the one module that can damage the
 * company's reputation, and drafting a campaign is not the same decision as putting it out.
 */
class SegmentPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('CampaignView');
    }

    public function view(User $user, Segment $record): bool
    {
        return $user->hasPermissionTo('CampaignView');
    }

    public function create(User $user): bool
    {
        return $user->hasPermissionTo('CampaignCreate');
    }

    public function update(User $user, Segment $record): bool
    {
        return $user->hasPermissionTo('CampaignUpdate');
    }

    public function delete(User $user, Segment $record): bool
    {
        return $user->hasPermissionTo('CampaignDelete');
    }
}
