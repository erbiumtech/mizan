<?php

namespace App\Modules\Support\Filament\Resources\Tickets\Pages;

use App\Filament\Concerns\RedirectsToIndex;
use App\Modules\Support\Filament\Resources\Tickets\TicketResource;
use Filament\Resources\Pages\EditRecord;

class EditTicket extends EditRecord
{
    use RedirectsToIndex;

    protected static string $resource = TicketResource::class;

    protected function getHeaderActions(): array
    {
        return [\Filament\Actions\DeleteAction::make()];
    }
}
