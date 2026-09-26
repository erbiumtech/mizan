<?php

namespace App\Modules\Employees\Filament\Resources\Employees\RelationManagers;

use App\Modules\Employees\Filament\Resources\Employees\EmployeeResource;
use App\Modules\Employees\Models\Employee;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Who reports to this employee — the optional Phase 4 tab of
 * docs/employee-hierarchy-access-plan.md.
 *
 * Read-only, like JobHistoryRelationManager and for the same kind of reason: the
 * reporting line is assigned on each report's *own* form, where the manager picker
 * excludes self and descendants and the save validates against cycles. A create or
 * edit here would be a second door into the same fact without those guards.
 */
class DirectReportsRelationManager extends RelationManager
{
    protected static string $relationship = 'directReports';

    protected static ?string $title = 'Direct reports';

    protected static string|\BackedEnum|null $icon = 'heroicon-o-user-group';

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('employee_id')
            ->columns([
                // "EMP-1 - John Doe"; the user behind the name rides along on the
                // employees query (Employee::$with), so this is not a per-row lookup.
                TextColumn::make('display_label')->label('Employee'),
                TextColumn::make('designation')->placeholder('—'),
                TextColumn::make('department')->placeholder('—')->toggleable(),
            ])
            // A hierarchy tab is for walking the tree, so a row opens the report's own page.
            ->recordUrl(fn (Employee $record): string => EmployeeResource::getUrl('view', ['record' => $record]))
            ->emptyStateHeading('No direct reports')
            ->emptyStateDescription('Anyone whose record names this employee as their manager appears here.');
    }
}
