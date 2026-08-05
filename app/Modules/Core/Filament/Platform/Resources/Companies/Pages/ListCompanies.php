<?php

namespace App\Modules\Core\Filament\Platform\Resources\Companies\Pages;

use App\Filament\Support\HelpAction;
use App\Modules\Core\Filament\Platform\Resources\Companies\CompanyResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListCompanies extends ListRecords
{
    protected static string $resource = CompanyResource::class;

    protected function getHeaderActions(): array
    {
        return [
            HelpAction::make('companies', 'Companies: Help'),
            CreateAction::make(),
        ];
    }
}
