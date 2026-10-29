<?php

namespace App\Modules\Core\Policies;

use App\Modules\Core\Models\User;
use App\Modules\Core\Models\WebhookEndpoint;

/**
 * An endpoint is a standing instruction to send this company's data somewhere —
 * docs/saas-launch-plan.md §14.9. That is an administrator's decision, and the same
 * one whichever way it is phrased, so one permission covers all of it.
 */
class WebhookEndpointPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('WebhookManage');
    }

    public function view(User $user, WebhookEndpoint $endpoint): bool
    {
        return $user->can('WebhookManage');
    }

    public function create(User $user): bool
    {
        return $user->can('WebhookManage');
    }

    public function update(User $user, WebhookEndpoint $endpoint): bool
    {
        return $user->can('WebhookManage');
    }

    public function delete(User $user, WebhookEndpoint $endpoint): bool
    {
        return $user->can('WebhookManage');
    }
}
