<?php

namespace App\Modules\Employees\Filament\Pages;

use App\Filament\Concerns\BelongsToModule;
use App\Filament\Support\HelpAction;
use App\Modules\Employees\Models\EmployeeJobHistory;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/** The dated transfers, over the job history — docs/saas-launch-plan.md §14.2. Recorded from the employee's page. */
class Transfers extends Page implements HasTable
{
    use BelongsToModule;
    use InteractsWithTable;

    protected string $view = 'filament.pages.simple-table';

    protected static string|UnitEnum|null $navigationGroup = 'Employee';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-arrows-right-left';

    protected static ?string $title = 'Transfers';

    protected static ?int $navigationSort = 8;

    public static function canAccess(): bool
    {
        return static::moduleIsAvailable() && (auth()->user()?->can('EmployeeView') ?? false);
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => EmployeeJobHistory::query()->with(['employee.user', 'manager.user', 'location'])->where('kind', 'transfer'))
            ->defaultSort('effective_from', 'desc')
            ->columns([
                TextColumn::make('effective_from')->date('d M Y')->label(__('Effective'))->sortable(),
                TextColumn::make('employee.display_label')->label(__('Employee')),
                TextColumn::make('designation')->placeholder('—'),
                TextColumn::make('department')->placeholder('—'),
                TextColumn::make('location.name')->label(__('Location'))->placeholder('—'),
                TextColumn::make('manager.display_label')->label(__('Manager'))->placeholder('—'),
                TextColumn::make('reason')->limit(50)->placeholder('—'),
            ]);
    }

    protected function getHeaderActions(): array
    {
        return [HelpAction::make('transfers-and-promotions', 'Transfers: Help')];
    }
}
