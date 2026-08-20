<?php

namespace App\Modules\ConstructionField\Filament\Resources\DailyLogs\Pages;

use App\Filament\Concerns\RedirectsToIndex;
use App\Filament\Support\HelpAction;
use App\Modules\ConstructionField\Filament\Resources\DailyLogs\DailyLogResource;
use App\Modules\ConstructionField\Models\DailyLog;
use App\Modules\ConstructionField\Services\DailyLogService;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditDailyLog extends EditRecord
{
    use RedirectsToIndex;

    protected static string $resource = DailyLogResource::class;

    protected function getHeaderActions(): array
    {
        return [HelpAction::make('construction-site-diary', 'Site diary: Help')];
    }

    /** Through the service, so an approved day refuses the save rather than accepting it silently. */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var DailyLog $record */
        return app(DailyLogService::class)->update($record, $data);
    }
}
