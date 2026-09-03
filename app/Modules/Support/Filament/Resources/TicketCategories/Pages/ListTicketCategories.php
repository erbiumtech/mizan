<?php

namespace App\Modules\Support\Filament\Resources\TicketCategories\Pages;

use App\Filament\Support\HelpAction;
use App\Modules\Support\Filament\Resources\TicketCategories\TicketCategoryResource;
use Filament\Resources\Pages\ListRecords;

class ListTicketCategories extends ListRecords
{
    protected static string $resource = TicketCategoryResource::class;

    protected function getHeaderActions(): array
    {
        return [
            HelpAction::make('ticket-categories', 'Ticket Categories: Help'),
            \Filament\Actions\CreateAction::make(),
        ];
    }
}
