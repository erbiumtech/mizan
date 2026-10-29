<?php

namespace App\Modules\Performance\Filament\Resources\DailyReports\Pages;

use App\Filament\Support\HelpAction;
use App\Modules\Performance\Filament\Resources\DailyReports\DailyReportResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListDailyReports extends ListRecords
{
    protected static string $resource = DailyReportResource::class;

    protected function getHeaderActions(): array
    {
        return [
            HelpAction::make('daily-reports', 'Daily Reports: Help'),
            CreateAction::make()->label('File today\'s report'),
        ];
    }
}
