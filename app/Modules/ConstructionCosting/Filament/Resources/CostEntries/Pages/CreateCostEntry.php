<?php

namespace App\Modules\ConstructionCosting\Filament\Resources\CostEntries\Pages;

use App\Filament\Concerns\RedirectsToIndex;
use App\Modules\Construction\Models\CostCode;
use App\Modules\Construction\Models\Job;
use App\Modules\ConstructionCosting\Filament\Resources\CostEntries\CostEntryResource;
use App\Modules\ConstructionCosting\Services\CostLedger;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateCostEntry extends CreateRecord
{
    use RedirectsToIndex;

    protected static string $resource = CostEntryResource::class;

    /**
     * Created through `CostLedger`, not by the form.
     *
     * The service is what derives the posting period from `incurred_on`, snapshots the cost type off the code and
     * refuses a heading — and §3 puts those there specifically so a screen cannot be the thing that decides
     * them. A `CreateRecord` that wrote the row directly would be a second, weaker path into the ledger.
     */
    protected function handleRecordCreation(array $data): Model
    {
        $job = Job::query()->findOrFail($data['job_id']);
        $code = CostCode::query()->findOrFail($data['cost_code_id']);

        unset($data['job_id'], $data['cost_code_id']);

        return app(CostLedger::class)->record($job, $code, $data);
    }
}
