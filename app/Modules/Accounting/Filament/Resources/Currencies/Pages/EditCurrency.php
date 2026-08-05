<?php

namespace App\Modules\Accounting\Filament\Resources\Currencies\Pages;

use App\Filament\Concerns\RedirectsToIndex;
use App\Modules\Accounting\Filament\Resources\Currencies\CurrencyResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditCurrency extends EditRecord
{
    use RedirectsToIndex;

    protected static string $resource = CurrencyResource::class;

    protected function getHeaderActions(): array
    {
        return [DeleteAction::make()];
    }
}
