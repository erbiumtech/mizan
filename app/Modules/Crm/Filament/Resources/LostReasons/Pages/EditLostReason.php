<?php

namespace App\Modules\Crm\Filament\Resources\LostReasons\Pages;

use App\Filament\Actions\ReplicateRecordAction;
use App\Filament\Concerns\RedirectsToIndex;
use App\Modules\Crm\Filament\Resources\LostReasons\LostReasonResource;
use App\Modules\Crm\Models\LostReason;
use Filament\Resources\Pages\EditRecord;

class EditLostReason extends EditRecord
{
    use RedirectsToIndex;

    protected static string $resource = LostReasonResource::class;

    protected function getHeaderActions(): array
    {
        // Not once a deal was lost for it: the deal's reason would be quietly cleared, and win/loss by
        // reason would lose that loss without saying so. Switch it off instead.
        return [ReplicateRecordAction::make(), \Filament\Actions\DeleteAction::make()->visible(fn (LostReason $record): bool => ! $record->opportunities()->exists())];
    }
}
