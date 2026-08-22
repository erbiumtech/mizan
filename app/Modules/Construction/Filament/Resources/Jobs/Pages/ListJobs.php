<?php

namespace App\Modules\Construction\Filament\Resources\Jobs\Pages;

use App\Filament\Support\HelpAction;
use App\Modules\Construction\Filament\Resources\Jobs\JobResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListJobs extends ListRecords
{
    protected static string $resource = JobResource::class;

    protected function getHeaderActions(): array
    {
        return [
            HelpAction::make('construction-jobs', 'Jobs: Help'),
            CreateAction::make(),
        ];
    }
}
