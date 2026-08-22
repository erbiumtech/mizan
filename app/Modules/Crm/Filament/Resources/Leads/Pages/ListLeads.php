<?php

namespace App\Modules\Crm\Filament\Resources\Leads\Pages;

use App\Filament\Support\HelpAction;
use App\Modules\Crm\Filament\Resources\Leads\LeadResource;
use Filament\Resources\Pages\ListRecords;

class ListLeads extends ListRecords
{
    protected static string $resource = LeadResource::class;

    protected function getHeaderActions(): array
    {
        return [
            HelpAction::make('leads', 'Leads: Help'),
            \Filament\Actions\CreateAction::make(),
        ];
    }
}
