<?php

namespace App\Modules\Campaigns\Policies;

use App\Modules\Campaigns\Models\Consent;
use App\Modules\Core\Models\User;

/**
 * Campaigns, segments and their sends share one group. Consent has its own rules below.
 *
 * SENDING is a separate permission from creating: this is the one module that can damage the
 * company's reputation, and drafting a campaign is not the same decision as putting it out.
 */
class ConsentPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('CampaignView');
    }

    public function view(User $user, Consent $record): bool
    {
        return $user->hasPermissionTo('CampaignView');
    }

    public function create(User $user): bool
    {
        return $user->hasPermissionTo('CampaignCreate');
    }

    /**
     * **Consent rows are never edited or deleted, by anybody.**
     *
     * The whole value of this table is that it answers "who agreed to this, and when" — and an
     * editable consent record is a checkbox with extra steps. Withdrawing consent writes a NEW
     * row; correcting a mistake writes another. The history is the evidence, and evidence that
     * can be rewritten is not evidence.
     */
    public function update(User $user, Consent $record): bool
    {
        return false;
    }

    public function delete(User $user, Consent $record): bool
    {
        return false;
    }
}
