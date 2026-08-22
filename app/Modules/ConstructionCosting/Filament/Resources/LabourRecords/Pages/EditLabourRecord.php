<?php

namespace App\Modules\ConstructionCosting\Filament\Resources\LabourRecords\Pages;

use App\Filament\Concerns\RedirectsToIndex;
use App\Filament\Support\HelpAction;
use App\Modules\ConstructionCosting\Filament\Resources\LabourRecords\LabourRecordResource;
use App\Modules\ConstructionCosting\Models\LabourRecord;
use App\Modules\ConstructionCosting\Services\LabourRecordService;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditLabourRecord extends EditRecord
{
    use RedirectsToIndex;

    protected static string $resource = LabourRecordResource::class;

    protected function getHeaderActions(): array
    {
        return [HelpAction::make('construction-site-sheets', 'Site sheets: Help')];
    }

    /**
     * Through the service, so the day-length guard applies to an edit as well as to a new sheet.
     *
     * Without this, eight hours typed as eighty would be refused on creation and accepted on the next save — and the
     * guard exists to catch exactly the eighty.
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var LabourRecord $record */
        return app(LabourRecordService::class)->update($record, $data);
    }
}
