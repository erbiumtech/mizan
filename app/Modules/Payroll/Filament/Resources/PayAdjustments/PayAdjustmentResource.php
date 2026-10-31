<?php

namespace App\Modules\Payroll\Filament\Resources\PayAdjustments;

use App\Filament\Concerns\BelongsToModule;
use App\Modules\Core\Models\FiscalYear;
use App\Modules\Employees\Models\Employee;
use App\Modules\Payroll\Filament\Resources\PayAdjustments\Pages\CreatePayAdjustment;
use App\Modules\Payroll\Filament\Resources\PayAdjustments\Pages\EditPayAdjustment;
use App\Modules\Payroll\Filament\Resources\PayAdjustments\Pages\ListPayAdjustments;
use App\Modules\Payroll\Models\PayAdjustment;
use App\Modules\Payroll\Models\PayComponent;
use App\Modules\Payroll\Services\PayslipService;
use App\Support\PayrollMonth;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/** Bonus and adjustment records — docs/saas-launch-plan.md §14.1. */
class PayAdjustmentResource extends Resource
{
    use BelongsToModule;

    protected static ?string $model = PayAdjustment::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedAdjustmentsHorizontal;

    protected static string|UnitEnum|null $navigationGroup = 'Employee';

    protected static ?string $navigationLabel = 'Pay Adjustments';

    protected static ?string $modelLabel = 'Pay adjustment';

    protected static ?int $navigationSort = 22;

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['employee.user', 'component', 'fiscalYear', 'payslip.payrollRun']);
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('employee_id')
                ->label(__('Employee'))
                ->options(fn (): array => Employee::query()->where('is_active', true)->with('user')->orderBy('employee_id')->get()
                    ->mapWithKeys(fn (Employee $e): array => [$e->getKey() => $e->display_label])->all())
                ->searchable()
                ->required(),
            Select::make('fiscal_year_id')
                ->label(__('Fiscal year'))
                ->options(fn (): array => FiscalYear::query()->orderByDesc('start_date')->pluck('name', 'id')->all())
                ->default(fn (): ?int => FiscalYear::current()?->getKey())
                ->required(),
            Select::make('month')
                ->options(self::months())
                ->default(fn (): string => now()->monthName)
                ->required(),
            Select::make('pay_component_id')
                ->label(__('Line'))
                ->options(fn (): array => PayComponent::query()->whereIn('code', PayslipService::OVERRIDE_KEYS)->orderBy('sort')->pluck('label', 'id')->all())
                ->required()
                ->helperText('Added to what the month would otherwise pay on this line. A negative amount takes it off.'),
            TextInput::make('amount')->numeric()->required(),
            TextInput::make('reason')->required()->maxLength(190)->placeholder('Eid bonus; recovered the advance paid in cash'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('employee.display_label')->label(__('Employee'))->searchable(['employees.employee_id']),
                TextColumn::make('month')->description(fn (PayAdjustment $r): ?string => $r->fiscalYear?->name),
                TextColumn::make('component.label')->label(__('Line')),
                TextColumn::make('amount')->numeric(2)->alignRight(),
                TextColumn::make('reason')->limit(40),
                TextColumn::make('payslip.id')->label(__('Payslip'))->prefix('#')->placeholder('not yet raised')
                    ->description(fn (PayAdjustment $r): ?string => $r->payslip?->payrollRun?->isLocked() ? 'signed off' : null),
            ])
            ->filters([
                SelectFilter::make('month')->options(self::months()),
            ])
            ->defaultSort('id', 'desc')
            ->recordActions([EditAction::make(), DeleteAction::make()]);
    }

    /** @return array<string, string> */
    private static function months(): array
    {
        $names = array_keys(PayrollMonth::fiscalOrder(FiscalYear::current()));

        return array_combine($names, $names);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPayAdjustments::route('/'),
            'create' => CreatePayAdjustment::route('/create'),
            'edit' => EditPayAdjustment::route('/{record}/edit'),
        ];
    }
}
