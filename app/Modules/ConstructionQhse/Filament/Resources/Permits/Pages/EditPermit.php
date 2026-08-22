<?php

namespace App\Modules\ConstructionQhse\Filament\Resources\Permits\Pages;

use App\Filament\Concerns\RedirectsToIndex;
use App\Filament\Support\HelpAction;
use App\Modules\ConstructionQhse\Filament\Resources\Permits\PermitResource;
use App\Modules\ConstructionQhse\Services\PermitService;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditPermit extends EditRecord
{
    use RedirectsToIndex;

    protected static string $resource = PermitResource::class;

    protected function getHeaderActions(): array
    {
        return [HelpAction::make('construction-permits', 'Permits to work: Help')];
    }

    /**
     * Through the service, which refuses to edit anything but a draft.
     *
     * An issued permit's window is what somebody signed for. Editing it would lose the record of what was authorised —
     * which is why extending is a new row.
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        return app(PermitService::class)->update($record, $data);
    }
}
