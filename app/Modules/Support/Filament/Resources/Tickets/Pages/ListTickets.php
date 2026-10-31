<?php

namespace App\Modules\Support\Filament\Resources\Tickets\Pages;

use App\Filament\Concerns\OpensRecordsInSheets;
use App\Filament\Support\HelpAction;
use App\Modules\Support\Filament\Resources\Tickets\TicketResource;
use Filament\Resources\Pages\ListRecords;

class ListTickets extends ListRecords
{
    use OpensRecordsInSheets;

    protected static string $resource = TicketResource::class;

    protected function getHeaderActions(): array
    {
        return [
            HelpAction::make('tickets', 'Tickets: Help'),
            \Filament\Actions\CreateAction::make(),
        ];
    }
}
