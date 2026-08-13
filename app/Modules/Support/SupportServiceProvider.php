<?php

namespace App\Modules\Support;

use App\Modules\Support\Models\Ticket;
use App\Modules\Support\Models\TicketCategory;
use App\Modules\Support\Models\TicketReply;
use App\Modules\Support\Policies\TicketCategoryPolicy;
use App\Modules\Support\Policies\TicketPolicy;
use App\Modules\Support\Policies\TicketReplyPolicy;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class SupportServiceProvider extends ServiceProvider
{
    /** @var array<class-string, class-string> */
    private const POLICIES = [
        TicketCategory::class => TicketCategoryPolicy::class,
        Ticket::class => TicketPolicy::class,
        TicketReply::class => TicketReplyPolicy::class,
    ];

    public function boot(): void
    {
        foreach (self::POLICIES as $model => $policy) {
            Gate::policy($model, $policy);
        }
    }
}
