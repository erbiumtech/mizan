<?php

namespace App\Modules\ConstructionCosting\Filament\Resources\MaterialIssues\Pages;

use App\Filament\Support\HelpAction;
use App\Modules\ConstructionCosting\Filament\Resources\MaterialIssues\MaterialIssueResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListMaterialIssues extends ListRecords
{
    protected static string $resource = MaterialIssueResource::class;

    protected function getHeaderActions(): array
    {
        return [
            HelpAction::make('construction-material-issues', 'Material issues: Help'),
            CreateAction::make(),
        ];
    }
}
