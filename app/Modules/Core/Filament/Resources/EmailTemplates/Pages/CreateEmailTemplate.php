<?php

namespace App\Modules\Core\Filament\Resources\EmailTemplates\Pages;

use App\Filament\Concerns\RedirectsToIndex;
use App\Modules\Core\Filament\Resources\EmailTemplates\EmailTemplateResource;
use Filament\Resources\Pages\CreateRecord;

class CreateEmailTemplate extends CreateRecord
{
    use RedirectsToIndex;

    protected static string $resource = EmailTemplateResource::class;
}
