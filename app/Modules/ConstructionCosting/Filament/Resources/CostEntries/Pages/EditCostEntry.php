<?php

namespace App\Modules\ConstructionCosting\Filament\Resources\CostEntries\Pages;

use App\Filament\Concerns\RedirectsToIndex;
use App\Modules\ConstructionCosting\Filament\Resources\CostEntries\CostEntryResource;
use App\Modules\ConstructionCosting\Models\CostEntry;
use App\Modules\ConstructionCosting\Services\CostLedger;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditCostEntry extends EditRecord
{
    use RedirectsToIndex;

    protected static string $resource = CostEntryResource::class;

    /**
     * Amended through `CostLedger`, which refuses once the entry has hardened.
     *
     * The resource hides the edit action for a hardened row, but that is a courtesy rather than the rule: §3.3's
     * line is enforced in the service so a direct URL, an import or a future API meets the same refusal.
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var CostEntry $record */
        return app(CostLedger::class)->amend($record, $data);
    }
}
