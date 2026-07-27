<?php

namespace App\Filament\Resources\Companies\Pages;

use App\Filament\Concerns\RedirectsToIndex;
use App\Filament\Resources\Companies\CompanyResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditCompany extends EditRecord
{
    use RedirectsToIndex;

    protected static string $resource = CompanyResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
