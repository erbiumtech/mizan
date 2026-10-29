<?php

namespace App\Modules\Employees\Filament\Pages;

use App\Filament\Concerns\BelongsToModule;
use App\Filament\Support\HelpAction;
use App\Modules\Employees\Models\Employee;
use App\Modules\Employees\Services\EmployeeMoves;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/** Who is on probation, overdue first — docs/saas-launch-plan.md §14.2. */
class Probations extends Page implements HasTable
{
    use BelongsToModule;
    use InteractsWithTable;

    protected string $view = 'filament.pages.simple-table';

    protected static string|UnitEnum|null $navigationGroup = 'Employee';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-clock';

    protected static ?string $title = 'Probations';

    protected static ?int $navigationSort = 7;

    public static function canAccess(): bool
    {
        return static::moduleIsAvailable() && (auth()->user()?->can('EmployeeUpdate') ?? false);
    }

    public static function getNavigationBadge(): ?string
    {
        $overdue = Employee::query()->where('is_active', true)->whereNotNull('probation_ends_on')->whereNull('confirmed_on')->whereDate('probation_ends_on', '<', now()->toDateString())->count();

        return $overdue > 0 ? (string) $overdue : null;
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => Employee::query()->with('user')->where('is_active', true)->whereNotNull('probation_ends_on')->whereNull('confirmed_on'))
            ->defaultSort('probation_ends_on')
            ->columns([
                TextColumn::make('display_label')->label('Employee')->searchable(['employee_id']),
                TextColumn::make('designation')->placeholder('—'),
                TextColumn::make('department')->placeholder('—'),
                TextColumn::make('date_of_joining')->date('d M Y')->label('Joined'),
                TextColumn::make('probation_ends_on')->date('d M Y')->label('Probation ends')->sortable()
                    ->color(fn (Employee $r): string => $r->probationOverdue() ? 'danger' : 'gray')
                    ->description(fn (Employee $r): ?string => $r->probationOverdue() ? 'overdue '.$r->probation_ends_on->diffForHumans() : null),
            ])
            ->recordActions([
                Action::make('confirm')
                    ->label('Confirm')
                    ->icon('heroicon-o-check-badge')
                    ->color('success')
                    ->schema([DatePicker::make('on')->label('Confirmed on')->native(false)->default(now())->required()])
                    ->action(function (Employee $record, array $data): void {
                        app(EmployeeMoves::class)->confirm($record, $data['on']);
                        Notification::make()->success()->title($record->display_label.' confirmed.')->send();
                    }),
            ]);
    }

    protected function getHeaderActions(): array
    {
        return [HelpAction::make('probations', 'Probations: Help')];
    }
}
