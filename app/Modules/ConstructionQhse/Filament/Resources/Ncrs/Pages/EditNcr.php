<?php

namespace App\Modules\ConstructionQhse\Filament\Resources\Ncrs\Pages;

use App\Filament\Concerns\RedirectsToIndex;
use App\Filament\Support\HelpAction;
use App\Modules\ConstructionQhse\Filament\Resources\Ncrs\NcrResource;
use App\Modules\ConstructionQhse\Services\NcrService;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditNcr extends EditRecord
{
    use RedirectsToIndex;

    protected static string $resource = NcrResource::class;

    protected function getHeaderActions(): array
    {
        return [HelpAction::make('construction-ncrs', 'Non-conformance: Help')];
    }

    /**
     * Through the service, which drops the fields that have their own act.
     *
     * The status, the disposition, the verification and the certificate the deduction landed on are each written by
     * something with a rule attached — a form posting its whole state must not settle any of them.
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        return app(NcrService::class)->update($record, $data);
    }
}
