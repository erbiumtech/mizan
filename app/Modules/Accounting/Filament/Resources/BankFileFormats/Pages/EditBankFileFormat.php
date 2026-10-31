<?php

namespace App\Modules\Accounting\Filament\Resources\BankFileFormats\Pages;

use App\Filament\Actions\ReplicateRecordAction;
use App\Filament\Concerns\RedirectsToIndex;
use App\Modules\Accounting\Filament\Resources\BankFileFormats\BankFileFormatResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditBankFileFormat extends EditRecord
{
    use RedirectsToIndex;

    protected static string $resource = BankFileFormatResource::class;

    protected function getHeaderActions(): array
    {
        return [
            BankFileFormatResource::previewAction()->record($this->getRecord()),
            ReplicateRecordAction::make(),
            DeleteAction::make(),
        ];
    }
}
