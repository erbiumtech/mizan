<?php

namespace App\Modules\Core\Policies;

use App\Modules\Core\Models\User;
use App\Modules\Core\Models\WebhookDelivery;

/**
 * Read-only, and the same permission as the endpoints.
 *
 * The log holds the bodies that were sent, which are this company's records in
 * somebody else's shape. Whoever may decide where they go may see what went.
 */
class WebhookDeliveryPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('WebhookManage');
    }

    public function view(User $user, WebhookDelivery $delivery): bool
    {
        return $user->can('WebhookManage');
    }

    /** Written by the application as it delivers, and never edited after. */
    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, WebhookDelivery $delivery): bool
    {
        // Sending again rewrites the row, so the action checks `WebhookManage` itself.
        return false;
    }

    public function delete(User $user, WebhookDelivery $delivery): bool
    {
        return false;
    }
}
