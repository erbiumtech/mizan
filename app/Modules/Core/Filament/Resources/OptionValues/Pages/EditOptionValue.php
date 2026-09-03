<?php

namespace App\Modules\Core\Filament\Resources\OptionValues\Pages;

use App\Filament\Concerns\RedirectsToIndex;
use App\Modules\Core\Filament\Resources\OptionValues\OptionValueResource;
use Filament\Resources\Pages\EditRecord;

class EditOptionValue extends EditRecord
{
    use RedirectsToIndex;

    protected static string $resource = OptionValueResource::class;

    protected function getHeaderActions(): array
    {
        // Deleting is offered because a company that has just been provisioned should be
        // able to clear the shipped list and write its own. An entry already chosen on a
        // record is better switched off — the record keeps showing what it was set to.
        return [\Filament\Actions\DeleteAction::make()];
    }
}
