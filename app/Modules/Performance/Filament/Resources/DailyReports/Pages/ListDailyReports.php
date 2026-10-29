<?php

namespace App\Modules\Performance\Filament\Resources\DailyReports\Pages;

use App\Modules\Performance\Filament\Resources\DailyReports\DailyReportResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListDailyReports extends ListRecords
{
    protected static string $resource = DailyReportResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('File today\'s report')];
    }
}
