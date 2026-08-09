<?php

namespace App\Modules\Core\Filament\Resources\Comments\Pages;

use App\Filament\Support\HelpAction;
use App\Modules\Core\Filament\Resources\Comments\CommentResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListComments extends ListRecords
{
    protected static string $resource = CommentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            HelpAction::make('comments', 'Comments: Help'),
            CreateAction::make(),
        ];
    }
}
