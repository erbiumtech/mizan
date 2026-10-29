<?php

namespace App\Modules\Attendance\Filament\Resources\OvertimeRequests;

use App\Filament\Concerns\BelongsToModule;
use App\Filament\Concerns\ScopesToAccessibleEmployees;
use App\Modules\Attendance\Filament\Resources\OvertimeRequests\Pages\CreateOvertimeRequest;
use App\Modules\Attendance\Filament\Resources\OvertimeRequests\Pages\ListOvertimeRequests;
use App\Modules\Attendance\Models\OvertimeRequest;
use App\Modules\Attendance\Services\OvertimeRequests;
use App\Modules\Employees\Models\Employee;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
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

/** Overtime requests — docs/saas-launch-plan.md §14.4. */
class OvertimeRequestResource extends Resource
{
    use BelongsToModule;
    use ScopesToAccessibleEmployees;

    protected static ?string $model = OvertimeRequest::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClock;

    protected static string|UnitEnum|null $navigationGroup = 'Employee';

    protected static ?string $navigationLabel = 'Overtime Requests';

    protected static ?string $modelLabel = 'Overtime request';

    protected static ?int $navigationSort = 42;

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery()->with(['employee.user']);

        if (! static::userIsPrivileged()) {
            $query->whereIn('employee_id', static::accessibleEmployeeIds()->all());
        }

        return $query;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('employee_id')
                ->label('Employee')
                ->options(fn (): array => Employee::query()->where('is_active', true)->with('user')->orderBy('employee_id')->get()->mapWithKeys(fn (Employee $e): array => [$e->getKey() => $e->display_label])->all())
                ->searchable()
                ->required()
                ->default(fn (): ?int => Employee::where('user_id', auth()->id())->value('id')),
            DatePicker::make('worked_on')->native(false)->maxDate(now())->default(now())->required(),
            TextInput::make('minutes')->numeric()->minValue(15)->maxValue(720)->required()->helperText('Beyond the pattern\'s hours. 90 is an hour and a half.'),
            TextInput::make('reason')->required()->maxLength(190)->placeholder('Month-end closing; the Karachi shipment'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('worked_on')->date('d M Y')->sortable(),
                TextColumn::make('employee.display_label')->label('Employee'),
                TextColumn::make('minutes')->suffix(' min')->alignRight(),
                TextColumn::make('reason')->limit(40),
                TextColumn::make('status')->badge()->color(fn (string $s): string => match ($s) {
                    'approved' => 'success', 'refused' => 'danger', default => 'warning'
                })
                    ->description(fn (OvertimeRequest $r): string => $r->paymentNote()),
            ])
            ->filters([SelectFilter::make('status')->options(['pending' => 'Pending', 'approved' => 'Approved', 'refused' => 'Refused'])])
            ->defaultSort('id', 'desc')
            ->recordActions([
                Action::make('approve')->label('Approve')->icon('heroicon-o-check-circle')->color('success')->requiresConfirmation()
                    ->modalDescription(fn (OvertimeRequest $r): string => 'Adds '.$r->minutes.' minutes to the attendance day. '.(setting('payroll.pay_overtime', config('payroll.pay_overtime', false)) ? 'It is paid with that month\'s payslip.' : 'Overtime pay is switched off, so this is recorded and not paid — the request will say so.'))
                    ->visible(fn (OvertimeRequest $r): bool => auth()->user()?->can('decide', $r) ?? false)
                    ->action(function (OvertimeRequest $record): void {
                        try {
                            app(OvertimeRequests::class)->approve($record, auth()->user());
                        } catch (InvalidArgumentException $e) {
                            Notification::make()->danger()->title($e->getMessage())->send();

                            return;
                        }

                        Notification::make()->success()->title('Approved. '.$record->fresh()->paymentNote().'.')->send();
                    }),
                Action::make('refuse')->label('Refuse')->icon('heroicon-o-x-circle')->color('danger')
                    ->visible(fn (OvertimeRequest $r): bool => auth()->user()?->can('decide', $r) ?? false)
                    ->schema([Textarea::make('reason')->required()->rows(2)])
                    ->action(function (OvertimeRequest $record, array $data): void {
                        app(OvertimeRequests::class)->refuse($record, auth()->user(), $data['reason']);
                        Notification::make()->warning()->title('Refused.')->send();
                    }),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListOvertimeRequests::route('/'),
            'create' => CreateOvertimeRequest::route('/create'),
        ];
    }
}
