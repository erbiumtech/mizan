<?php

namespace App\Modules\Leave\Filament\Resources\LeaveEncashments;

use App\Filament\Concerns\BelongsToModule;
use App\Filament\Concerns\ScopesToAccessibleEmployees;
use App\Modules\Core\Models\FiscalYear;
use App\Modules\Employees\Models\Employee;
use App\Modules\Leave\Filament\Resources\LeaveEncashments\Pages\CreateLeaveEncashment;
use App\Modules\Leave\Filament\Resources\LeaveEncashments\Pages\ListLeaveEncashments;
use App\Modules\Leave\Models\LeaveEncashment;
use App\Modules\Leave\Models\LeaveType;
use App\Modules\Leave\Services\LeaveBalance;
use App\Modules\Leave\Services\LeaveEncashments;
use App\Support\EmployeeAccess;
use App\Support\PayrollMonth;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use InvalidArgumentException;
use UnitEnum;

/** Encashment outside a separation — docs/saas-launch-plan.md §14.4. */
class LeaveEncashmentResource extends Resource
{
    use BelongsToModule;
    use ScopesToAccessibleEmployees;

    protected static ?string $model = LeaveEncashment::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static string|UnitEnum|null $navigationGroup = 'Employee';

    protected static ?string $navigationLabel = 'Leave Encashments';

    protected static ?string $modelLabel = 'Leave encashment';

    protected static ?int $navigationSort = 34;

    /** Own and downline unless privileged, like every employee-keyed list — what a colleague is paid out is not everyone's to read. */
    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery()->with(['employee.user', 'leaveType', 'fiscalYear']);

        if (! static::userIsPrivileged()) {
            $query->whereIn('employee_id', static::accessibleEmployeeIds()->all());
        }

        return $query;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('employee_id')
                ->label(__('Employee'))
                // Own record and downline, unless privileged: a picker of every colleague let anyone file in another's name.
                ->options(fn (): array => app(EmployeeAccess::class)->scopeAccessibleEmployees(Employee::query()->where('is_active', true), auth()->user())->with('user')->orderBy('employee_id')->get()->mapWithKeys(fn (Employee $e): array => [$e->getKey() => $e->display_label])->all())
                ->searchable()
                ->required()
                ->live()
                ->default(fn (): ?int => app(EmployeeAccess::class)->ownEmployeeId(auth()->user())),
            Select::make('leave_type_id')
                ->label(__('Leave type'))
                ->options(fn (): array => LeaveType::query()->active()->where('is_encashable', true)->orderBy('sort')->pluck('label', 'id')->all())
                ->required()
                ->live()
                ->helperText(function (Get $get): ?string {
                    $employee = $get('employee_id') ? Employee::query()->find($get('employee_id')) : null;
                    $type = $get('leave_type_id') ? LeaveType::query()->find($get('leave_type_id')) : null;

                    if (! $employee || ! $type) {
                        return null;
                    }

                    $left = app(LeaveBalance::class)->remaining($employee, $type);

                    return $left === null ? 'No leave year is open for this type.' : number_format($left, 1).' day(s) left.';
                }),
            TextInput::make('days')->numeric()->minValue(0.5)->step(0.5)->required()
                // Refused at filing, not first at approval: an approver should not be handed a request the
                // balance never covered.
                ->rule(fn (Get $get) => function (string $attribute, mixed $value, \Closure $fail) use ($get): void {
                    $employee = $get('employee_id') ? Employee::query()->find($get('employee_id')) : null;
                    $type = $get('leave_type_id') ? LeaveType::query()->find($get('leave_type_id')) : null;

                    if (! $employee || ! $type || ! is_numeric($value)) {
                        return;
                    }

                    $available = app(LeaveEncashments::class)->available($employee, $type);

                    if ($available === null) {
                        $fail("No leave year is open for {$type->label}, so there is nothing to encash.");
                    } elseif ((float) $value > $available) {
                        $fail(sprintf('Only %s day(s) of %s can be encashed — the balance, less what is already asked for.', number_format($available, 1), $type->label));
                    }
                }),
            TextInput::make('amount')->numeric()->minValue(0)->default(0)->helperText('What is paid: days times the daily rate the company uses. Reaches the payslip named below as a bonus line.'),
            Select::make('fiscal_year_id')->label(__('Fiscal year'))->options(fn (): array => FiscalYear::query()->orderByDesc('start_date')->pluck('name', 'id')->all())->default(fn (): ?int => FiscalYear::current()?->getKey()),
            Select::make('month')->label(__('Paid with the payslip for'))->options(fn (): array => array_combine($m = array_keys(PayrollMonth::fiscalOrder(FiscalYear::current())), $m))->default(fn (): string => now()->monthName),
            Textarea::make('reason')->rows(2)->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('employee.display_label')->label(__('Employee')),
                TextColumn::make('leaveType.label')->label(__('Type')),
                TextColumn::make('days')->numeric(1),
                TextColumn::make('amount')->numeric(2)->alignRight(),
                TextColumn::make('month')->description(fn (LeaveEncashment $r): ?string => $r->fiscalYear?->name),
                TextColumn::make('status')->badge()->color(fn (string $state): string => match ($state) {
                    'approved' => 'success', 'refused' => 'danger', default => 'warning'
                })
                    ->description(fn (LeaveEncashment $r): ?string => $r->refusal_reason),
            ])
            ->filters([SelectFilter::make('status')->options(['pending' => 'Pending', 'approved' => 'Approved', 'refused' => 'Refused'])])
            ->defaultSort('id', 'desc')
            ->recordActions([
                Action::make('approve')->label(__('Approve'))->icon('heroicon-o-check-circle')->color('success')->requiresConfirmation()
                    ->modalDescription('Debits the balance now and sends the amount to the payslip named.')
                    ->visible(fn (LeaveEncashment $r): bool => $r->isPending() && (auth()->user()?->can('LeaveRequestApprove') ?? false))
                    ->action(function (LeaveEncashment $record): void {
                        try {
                            app(LeaveEncashments::class)->approve($record, auth()->user());
                        } catch (InvalidArgumentException $e) {
                            Notification::make()->danger()->title($e->getMessage())->send();

                            return;
                        }

                        Notification::make()->success()->title('Approved.')->send();
                    }),
                Action::make('refuse')->label(__('Refuse'))->icon('heroicon-o-x-circle')->color('danger')
                    ->visible(fn (LeaveEncashment $r): bool => $r->isPending() && (auth()->user()?->can('LeaveRequestApprove') ?? false))
                    ->schema([Textarea::make('reason')->required()->rows(2)])
                    ->action(function (LeaveEncashment $record, array $data): void {
                        app(LeaveEncashments::class)->refuse($record, auth()->user(), $data['reason']);
                        Notification::make()->warning()->title('Refused.')->send();
                    }),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListLeaveEncashments::route('/'),
            'create' => CreateLeaveEncashment::route('/create'),
        ];
    }
}
