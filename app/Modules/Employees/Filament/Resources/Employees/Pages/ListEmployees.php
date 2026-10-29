<?php

namespace App\Modules\Employees\Filament\Resources\Employees\Pages;

use App\Filament\Support\HelpAction;
use App\Modules\Employees\Filament\Resources\Employees\EmployeeResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListEmployees extends ListRecords
{
    protected static string $resource = EmployeeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // Grid or list — docs/saas-launch-plan.md §14.2.
            \Filament\Actions\Action::make('toggleLayout')
                ->label(fn (): string => session('employees.layout') === 'grid' ? 'List' : 'Grid')
                ->icon(fn (): string => session('employees.layout') === 'grid' ? 'heroicon-o-list-bullet' : 'heroicon-o-squares-2x2')
                ->color('gray')
                ->action(function (): void {
                    session(['employees.layout' => session('employees.layout') === 'grid' ? 'list' : 'grid']);
                    $this->resetTable();
                }),
            HelpAction::make('employees', 'Employees: Help'),
            CreateAction::make(),
        ];
    }
}
