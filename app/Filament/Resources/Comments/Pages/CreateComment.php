<?php

namespace App\Filament\Resources\Comments\Pages;

use App\Filament\Concerns\RedirectsToIndex;
use App\Filament\Resources\Comments\CommentResource;
use Filament\Resources\Pages\CreateRecord;

class CreateComment extends CreateRecord
{
    use RedirectsToIndex;

    protected static string $resource = CommentResource::class;
}
