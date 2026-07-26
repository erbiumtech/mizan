<?php

namespace App\Filament\Resources\Products\Pages;

use App\Filament\Concerns\HasSavedViews;
use App\Filament\Resources\Products\ProductResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListProducts extends ListRecords
{
    use HasSavedViews;

    protected static string $resource = ProductResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
            $this->saveViewAction(),
        ];
    }

    /**
     * Developer-defined preset views (state-only; safe columns/sort).
     */
    public function presetViews(): array
    {
        return [
            ['key' => 'name_asc', 'name' => 'Name A → Z', 'icon' => 'heroicon-o-bars-arrow-down', 'state' => ['sort' => 'name:asc']],
            ['key' => 'newest', 'name' => 'Newest first', 'icon' => 'heroicon-o-clock', 'state' => ['sort' => 'created_at:desc']],
        ];
    }
}
