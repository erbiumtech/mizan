<?php

namespace App\Modules\Core\Filament\Resources\EmailTemplates\Pages;

use App\Modules\Core\Filament\Resources\EmailTemplates\EmailTemplateResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListEmailTemplates extends ListRecords
{
    protected static string $resource = EmailTemplateResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
