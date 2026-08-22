<?php

namespace App\Modules\ConstructionQhse\Filament\Resources\Incidents\Pages;

use App\Filament\Concerns\RedirectsToIndex;
use App\Filament\Support\HelpAction;
use App\Modules\ConstructionQhse\Filament\Resources\Incidents\IncidentResource;
use App\Modules\ConstructionQhse\Services\IncidentService;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditIncident extends EditRecord
{
    use RedirectsToIndex;

    protected static string $resource = IncidentResource::class;

    protected function getHeaderActions(): array
    {
        return [HelpAction::make('construction-incidents', 'Incidents: Help')];
    }

    /** Through the service, which drops the status and the closure — both have their own act and their own rule. */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        return app(IncidentService::class)->update($record, $data);
    }
}
