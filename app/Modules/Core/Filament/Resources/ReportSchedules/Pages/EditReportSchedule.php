<?php

namespace App\Modules\Core\Filament\Resources\ReportSchedules\Pages;

use App\Filament\Concerns\RedirectsToIndex;
use App\Modules\Core\Filament\Resources\ReportSchedules\ReportScheduleResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditReportSchedule extends EditRecord
{
    use RedirectsToIndex;

    protected static string $resource = ReportScheduleResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
