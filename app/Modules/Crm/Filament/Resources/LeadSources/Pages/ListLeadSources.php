<?php

namespace App\Modules\Crm\Filament\Resources\LeadSources\Pages;

use App\Filament\Support\HelpAction;
use App\Modules\Crm\Filament\Resources\LeadSources\LeadSourceResource;
use Filament\Resources\Pages\ListRecords;

class ListLeadSources extends ListRecords
{
    protected static string $resource = LeadSourceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            HelpAction::make('lead-sources', 'Lead Sources: Help'),
            \Filament\Actions\CreateAction::make(),
        ];
    }
}
