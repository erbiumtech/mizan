<?php

namespace App\Modules\Performance\Filament\Resources\ReviewTemplates\Pages;

use App\Filament\Support\HelpAction;
use App\Modules\Performance\Filament\Resources\ReviewTemplates\ReviewTemplateResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListReviewTemplates extends ListRecords
{
    protected static string $resource = ReviewTemplateResource::class;

    protected function getHeaderActions(): array
    {
        return [
            HelpAction::make('review-templates', 'Review Templates: Help'),
            CreateAction::make(),
        ];
    }
}
