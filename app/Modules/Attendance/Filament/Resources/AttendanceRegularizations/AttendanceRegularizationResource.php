<?php

namespace App\Modules\Attendance\Filament\Resources\AttendanceRegularizations;

use App\Filament\Concerns\BelongsToModule;
use App\Filament\Concerns\ScopesToAccessibleEmployees;
use App\Modules\Attendance\Filament\Resources\AttendanceDays\Schemas\AttendanceDayForm;
use App\Modules\Attendance\Filament\Resources\AttendanceRegularizations\Pages\CreateAttendanceRegularization;
use App\Modules\Attendance\Filament\Resources\AttendanceRegularizations\Pages\ListAttendanceRegularizations;
use App\Modules\Attendance\Models\AttendanceRegularization;
use App\Modules\Attendance\Services\RegularizationService;
use App\Support\EmployeeAccess;
use App\Support\EmployeeOptions;
use App\Support\LandlordUserColumn;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TimePicker;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use InvalidArgumentException;
use UnitEnum;

/**
 * "I was here that day."
 *
 * The way out of `not_marked`, and the only one — an admin quietly editing rows would
 * make the status a lie rather than a state. This is the EmployeeChangeRequest pattern
 * applied to a single day, which docs/hrms-plan.md §1 names as the pattern every HR
 * request should copy.
 */
class AttendanceRegularizationResource extends Resource
{
    use BelongsToModule;
    use ScopesToAccessibleEmployees;

    protected static ?string $model = AttendanceRegularization::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPencilSquare;

    protected static string|UnitEnum|null $navigationGroup = 'Employee';

    protected static ?string $modelLabel = 'Attendance correction';

    protected static ?int $navigationSort = 41;

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();

        if (! static::userIsPrivileged()) {
            $query->whereIn('employee_id', static::accessibleEmployeeIds()->all());
        }

        return $query;
    }

    public static function getNavigationBadge(): ?string
    {
        $pending = static::getEloquentQuery()->pending()->count();

        return $pending > 0 ? (string) $pending : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('employee_id')
                ->label('Employee')
                ->relationship('employee', 'employee_id', fn ($query) => app(EmployeeAccess::class)
                    ->scopeAccessibleEmployees($query->with('user'), auth()->user()))
                ->getOptionLabelFromRecordUsing(fn ($record) => $record->display_label)
                ->searchable()
                ->getSearchResultsUsing(fn (string $search): array => EmployeeOptions::search(
                    $search,
                    EmployeeOptions::accessibleScope(),
                ))
                ->preload()
                ->required()
                ->default(fn (): ?int => \App\Modules\Employees\Models\Employee::where('user_id', auth()->id())->value('id')),

            DatePicker::make('date')
                ->native(false)
                ->required()
                ->maxDate(now())
                ->helperText('A day already past. This is a correction, not a plan.'),

            Select::make('requested_status')
                ->label('What the day actually was')
                ->options(AttendanceDayForm::statusOptions())
                ->required(),

            TimePicker::make('requested_check_in_at')->seconds(false)->label('In'),
            TimePicker::make('requested_check_out_at')->seconds(false)->label('Out'),

            Textarea::make('reason')
                ->required()
                ->rows(2)
                ->columnSpanFull()
                ->helperText('Why the day was not recorded correctly. Kept on the request after approval, so the correction is auditable.'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('date')->date('d M Y')->sortable(),

                TextColumn::make('employee.display_label')
                    ->label('Employee')
                    ->searchable(query: fn ($query, string $search) => LandlordUserColumn::search($query, $search))
                    ->sortable(),

                TextColumn::make('requested_status')->label('Asked for')->badge()->color('gray'),

                TextColumn::make('reason')->wrap()->limit(60),

                TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        AttendanceRegularization::STATUS_PENDING => 'warning',
                        AttendanceRegularization::STATUS_APPROVED => 'success',
                        default => 'danger',
                    })
                    ->description(fn (AttendanceRegularization $record): ?string => $record->refusal_reason)
                    ->sortable(),
            ])
            ->defaultSort('date', 'desc')
            ->filters([
                SelectFilter::make('status')->options([
                    AttendanceRegularization::STATUS_PENDING => 'Awaiting a decision',
                    AttendanceRegularization::STATUS_APPROVED => 'Approved',
                    AttendanceRegularization::STATUS_REFUSED => 'Refused',
                ]),
            ])
            ->recordActions([
                Action::make('approve')
                    ->label('Approve')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalDescription('Approving writes the day as asked, marked as coming from the employee. The original request is kept.')
                    ->visible(fn (AttendanceRegularization $record): bool => auth()->user()?->can('decide', $record) ?? false)
                    ->action(function (AttendanceRegularization $record): void {
                        try {
                            app(RegularizationService::class)->approve($record, auth()->user());
                        } catch (InvalidArgumentException $e) {
                            Notification::make()->danger()->title($e->getMessage())->send();

                            return;
                        }

                        Notification::make()->success()->title('Approved, and the day is written.')->send();
                    }),

                Action::make('refuse')
                    ->label('Refuse')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->visible(fn (AttendanceRegularization $record): bool => auth()->user()?->can('decide', $record) ?? false)
                    ->schema([
                        Textarea::make('reason')->label('Reason')->required()->rows(2),
                    ])
                    ->action(function (AttendanceRegularization $record, array $data): void {
                        try {
                            app(RegularizationService::class)->refuse($record, auth()->user(), $data['reason']);
                        } catch (InvalidArgumentException $e) {
                            Notification::make()->danger()->title($e->getMessage())->send();

                            return;
                        }

                        Notification::make()->success()->title('Refused, with your reason recorded.')->send();
                    }),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAttendanceRegularizations::route('/'),
            'create' => CreateAttendanceRegularization::route('/create'),
        ];
    }
}
