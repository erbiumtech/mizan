<?php

namespace App\Modules\ConstructionContracts\Filament\Resources\BackCharges\Pages;

use App\Filament\Concerns\RedirectsToIndex;
use App\Filament\Support\HelpAction;
use App\Modules\ConstructionContracts\Filament\Resources\BackCharges\BackChargeResource;
use App\Modules\ConstructionContracts\Models\BackCharge;
use App\Modules\ConstructionContracts\Services\BackChargeService;
use Filament\Resources\Pages\EditRecord;

/**
 * Saving re-derives the total, because the cost or the markup may have changed.
 *
 * `recompute()` is a no-op on anything but a draft, which is where the rule belongs — the policy already refuses to open
 * this page for a notified charge, and a second guard here would be a second place to get it wrong.
 */
class EditBackCharge extends EditRecord
{
    use RedirectsToIndex;

    protected static string $resource = BackChargeResource::class;

    protected function getHeaderActions(): array
    {
        return [HelpAction::make('construction-back-charges', 'Back-charges: Help')];
    }

    protected function afterSave(): void
    {
        /** @var BackCharge $record */
        $record = $this->record;

        app(BackChargeService::class)->recompute($record);
    }
}
