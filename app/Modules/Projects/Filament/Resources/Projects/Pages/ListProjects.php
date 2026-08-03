<?php

namespace App\Modules\Projects\Filament\Resources\Projects\Pages;

use App\Filament\Concerns\HasSavedViews;
use App\Modules\Projects\Filament\Resources\Projects\ProjectResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListProjects extends ListRecords
{
    use HasSavedViews;

    protected static string $resource = ProjectResource::class;

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
            ['key' => 'active_first', 'name' => 'Active first', 'icon' => 'heroicon-o-play', 'state' => ['filters' => ['status' => ['values' => ['active']]]]],
            ['key' => 'unhealthy', 'name' => 'Unhealthy first', 'icon' => 'heroicon-o-exclamation-triangle', 'state' => ['filters' => ['unhealthy' => ['isActive' => true]]]],
            ['key' => 'name_asc', 'name' => 'Name A → Z', 'icon' => 'heroicon-o-bars-arrow-down', 'state' => ['sort' => 'name:asc']],
            ['key' => 'newest', 'name' => 'Newest first', 'icon' => 'heroicon-o-clock', 'state' => ['sort' => 'created_at:desc']],
        ];
    }
}
