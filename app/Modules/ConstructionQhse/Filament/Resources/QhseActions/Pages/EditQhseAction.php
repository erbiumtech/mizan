<?php

namespace App\Modules\ConstructionQhse\Filament\Resources\QhseActions\Pages;

use App\Filament\Concerns\RedirectsToIndex;
use App\Filament\Support\HelpAction;
use App\Modules\ConstructionQhse\Filament\Resources\QhseActions\QhseActionResource;
use App\Modules\ConstructionQhse\Services\ActionService;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditQhseAction extends EditRecord
{
    use RedirectsToIndex;

    protected static string $resource = QhseActionResource::class;

    protected function getHeaderActions(): array
    {
        return [HelpAction::make('construction-qhse-actions', 'QHSE actions: Help')];
    }

    /** Through the service, which drops the status, the completion and the verification — each has its own act. */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        return app(ActionService::class)->update($record, $data);
    }
}
