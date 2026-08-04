<?php

namespace App\Modules\Payroll\Filament\Resources\PayComponents\Pages;

use App\Filament\Concerns\RedirectsToIndex;
use App\Modules\Payroll\Filament\Resources\PayComponents\PayComponentResource;
use Filament\Resources\Pages\EditRecord;

class EditPayComponent extends EditRecord
{
    use RedirectsToIndex;

    protected static string $resource = PayComponentResource::class;

    protected function getHeaderActions(): array
    {
        return [\Filament\Actions\DeleteAction::make()];
    }
}
