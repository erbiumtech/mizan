<?php

namespace App\Modules\Work\Filament\Resources\WorkItems\Pages;

use App\Modules\Projects\Models\Project;
use App\Modules\Work\Filament\Resources\WorkItems\WorkItemResource;
use App\Modules\Work\Services\WorkItems;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateWorkItem extends CreateRecord
{
    protected static string $resource = WorkItemResource::class;

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }

    /**
     * Through the service, because the key and the number are not the form's to invent.
     *
     * `WorkItems::create()` takes the sequence under a row lock on the project — see §3.1 and the
     * service's own comment for why `WRK-12` appearing twice is the one failure here that cannot be
     * undone later.
     */
    protected function handleRecordCreation(array $data): Model
    {
        $project = Project::query()->findOrFail($data['project_id']);

        return app(WorkItems::class)->create($project, $data, auth()->user());
    }
}
