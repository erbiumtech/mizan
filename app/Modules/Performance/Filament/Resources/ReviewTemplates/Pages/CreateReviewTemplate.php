<?php

namespace App\Modules\Performance\Filament\Resources\ReviewTemplates\Pages;

use App\Filament\Concerns\RedirectsToIndex;
use App\Modules\Performance\Filament\Resources\ReviewTemplates\ReviewTemplateResource;
use Filament\Resources\Pages\CreateRecord;

class CreateReviewTemplate extends CreateRecord
{
    use RedirectsToIndex;

    protected static string $resource = ReviewTemplateResource::class;
}
