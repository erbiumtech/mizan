<?php

namespace App\Modules\Performance\Filament\Resources\DailyReports\Pages;

use App\Modules\Employees\Models\Employee;
use App\Modules\Performance\Filament\Resources\DailyReports\DailyReportResource;
use App\Modules\Performance\Services\DailyReports;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * Filing goes through the service, so the day's daily-report task completes and
 * the verdict job is queued; a re-filed date replaces the earlier report.
 */
class CreateDailyReport extends CreateRecord
{
    protected static string $resource = DailyReportResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        $employee = Employee::query()->where('user_id', auth()->id())->firstOrFail();

        try {
            return app(DailyReports::class)->submit($employee, (string) $data['report_date'], (string) $data['body'], auth()->user());
        } catch (InvalidArgumentException $e) {
            Notification::make()->danger()->title($e->getMessage())->send();
            $this->halt();
        }
    }

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('index');
    }
}
