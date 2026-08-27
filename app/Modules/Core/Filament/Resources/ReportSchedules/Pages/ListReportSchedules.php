<?php

namespace App\Modules\Core\Filament\Resources\ReportSchedules\Pages;

use App\Filament\Support\HelpAction;
use App\Modules\Core\Filament\Resources\ReportSchedules\ReportScheduleResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListReportSchedules extends ListRecords
{
    protected static string $resource = ReportScheduleResource::class;

    protected function getHeaderActions(): array
    {
        return [
            HelpAction::make('report-schedules', 'Scheduled reports: Help'),
            CreateAction::make(),
        ];
    }
}
