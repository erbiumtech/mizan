<?php

namespace App\Modules\Employees\Filament\Resources\Employees\Pages;

use App\Filament\Concerns\OpensRecordsInSheets;
use App\Filament\Support\HelpAction;
use App\Modules\Core\Filament\Pages\CsvImport;
use App\Modules\Employees\Filament\Resources\Employees\EmployeeResource;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;

class ListEmployees extends ListRecords
{
    use OpensRecordsInSheets;

    protected static string $resource = EmployeeResource::class;

    /**
     * The Material list design's header — docs/material-3-theme.md, "Lists": an outlined Import, a tonal
     * secondary action and the filled New employee with its plus. Filament's colours do the mapping: gray is
     * tonal, `outlined()` is outlined, primary is filled.
     */
    protected function getHeaderActions(): array
    {
        return [
            // The CSV import page, where Employees is one of the importers. Administrators only, as the page is.
            Action::make('import')
                ->label(__('Import'))
                ->icon(Heroicon::OutlinedArrowUpTray)
                ->outlined()
                ->url(fn (): string => CsvImport::getUrl())
                ->visible(fn (): bool => CsvImport::canAccess()),

            // Grid or list — docs/saas-launch-plan.md §14.2.
            Action::make('toggleLayout')
                ->label(fn (): string => session('employees.layout') === 'grid' ? __('List') : __('Grid'))
                ->icon(fn (): string => session('employees.layout') === 'grid' ? 'heroicon-o-list-bullet' : 'heroicon-o-squares-2x2')
                ->color('gray')
                ->action(function (): void {
                    session(['employees.layout' => session('employees.layout') === 'grid' ? 'list' : 'grid']);
                    $this->resetTable();
                }),
            HelpAction::make('employees', 'Employees: Help'),
            CreateAction::make()
                ->icon(Heroicon::Plus),
        ];
    }
}
