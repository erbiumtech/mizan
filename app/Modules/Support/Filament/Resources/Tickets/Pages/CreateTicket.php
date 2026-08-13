<?php

namespace App\Modules\Support\Filament\Resources\Tickets\Pages;

use App\Filament\Concerns\RedirectsToIndex;
use App\Modules\Support\Filament\Resources\Tickets\TicketResource;
use Filament\Resources\Pages\CreateRecord;

class CreateTicket extends CreateRecord
{
    use RedirectsToIndex;

    protected static string $resource = TicketResource::class;
}
