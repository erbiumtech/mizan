<?php

namespace App\Modules\Performance\Filament\Resources\Reviews\Pages;

use App\Filament\Support\HelpAction;
use App\Modules\Performance\Filament\Resources\Reviews\ReviewResource;
use Filament\Resources\Pages\ListRecords;

class ListReviews extends ListRecords
{
    protected static string $resource = ReviewResource::class;

    protected function getHeaderActions(): array
    {
        return [
            HelpAction::make('reviews', 'Reviews: Help'),
            \Filament\Actions\CreateAction::make(),
        ];
    }
}
