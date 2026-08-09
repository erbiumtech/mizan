<?php

namespace App\Modules\Billing\Filament\Resources\BillingRuns\Pages;

use App\Filament\Concerns\RedirectsToIndex;
use App\Modules\Billing\Filament\Resources\BillingRuns\BillingRunResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditBillingRun extends EditRecord
{
    use RedirectsToIndex;

    protected static string $resource = BillingRunResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
