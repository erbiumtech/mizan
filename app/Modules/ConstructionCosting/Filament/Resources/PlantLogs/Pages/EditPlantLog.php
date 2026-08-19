<?php

namespace App\Modules\ConstructionCosting\Filament\Resources\PlantLogs\Pages;

use App\Filament\Concerns\RedirectsToIndex;
use App\Filament\Support\HelpAction;
use App\Modules\ConstructionCosting\Filament\Resources\PlantLogs\PlantLogResource;
use App\Modules\ConstructionCosting\Models\PlantLog;
use App\Modules\ConstructionCosting\Services\PlantService;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditPlantLog extends EditRecord
{
    use RedirectsToIndex;

    protected static string $resource = PlantLogResource::class;

    protected function getHeaderActions(): array
    {
        return [HelpAction::make('construction-plant-logs', 'Plant logs: Help')];
    }

    /** Through the service, so the unit and meter guards apply to an edit as well as to a new log. */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var PlantLog $record */
        return app(PlantService::class)->update($record, $data);
    }
}
