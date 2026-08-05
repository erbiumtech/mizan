<?php

namespace App\Modules\Core\Filament\Resources\EmailTemplates\Pages;

use App\Filament\Concerns\RedirectsToIndex;
use App\Modules\Core\Filament\Resources\EmailTemplates\EmailTemplateResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditEmailTemplate extends EditRecord
{
    use RedirectsToIndex;

    protected static string $resource = EmailTemplateResource::class;

    protected function getHeaderActions(): array
    {
        return [DeleteAction::make()];
    }
}
