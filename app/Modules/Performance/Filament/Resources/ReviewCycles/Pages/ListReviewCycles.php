<?php

namespace App\Modules\Performance\Filament\Resources\ReviewCycles\Pages;

use App\Filament\Support\HelpAction;
use App\Modules\Performance\Filament\Resources\ReviewCycles\ReviewCycleResource;
use Filament\Resources\Pages\ListRecords;

class ListReviewCycles extends ListRecords
{
    protected static string $resource = ReviewCycleResource::class;

    protected function getHeaderActions(): array
    {
        return [
            HelpAction::make('review-cycles', 'Review Cycles: Help'),
            \Filament\Actions\CreateAction::make(),
        ];
    }
}
