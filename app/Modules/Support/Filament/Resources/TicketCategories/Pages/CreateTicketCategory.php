<?php

namespace App\Modules\Support\Filament\Resources\TicketCategories\Pages;

use App\Filament\Concerns\RedirectsToIndex;
use App\Modules\Support\Filament\Resources\TicketCategories\TicketCategoryResource;
use Filament\Resources\Pages\CreateRecord;

class CreateTicketCategory extends CreateRecord
{
    use RedirectsToIndex;

    protected static string $resource = TicketCategoryResource::class;
}
