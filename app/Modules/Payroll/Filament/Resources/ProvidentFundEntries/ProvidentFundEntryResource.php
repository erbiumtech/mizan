<?php

namespace App\Modules\Payroll\Filament\Resources\ProvidentFundEntries;

use App\Filament\Concerns\BelongsToModule;
use App\Modules\Employees\Models\Employee;
use App\Modules\Payroll\Filament\Resources\ProvidentFundEntries\Pages\CreateProvidentFundEntry;
use App\Modules\Payroll\Filament\Resources\ProvidentFundEntries\Pages\ListProvidentFundEntries;
use App\Modules\Payroll\Models\ProvidentFundEntry;
use App\Modules\Payroll\Services\ProvidentFund;
use BackedEnum;
use Filament\Forms\Components\DatePicker;
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

/** The provident-fund ledger — docs/saas-launch-plan.md §14.1. */
class ProvidentFundEntryResource extends Resource
{
    use BelongsToModule;

    protected static ?string $model = ProvidentFundEntry::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingLibrary;

    protected static string|UnitEnum|null $navigationGroup = 'Employee';

    protected static ?string $navigationLabel = 'Provident Fund';

    protected static ?string $modelLabel = 'Provident fund entry';

    protected static ?int $navigationSort = 23;

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['employee.user', 'payslip']);
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('employee_id')
                ->label('Employee')
                ->options(fn (): array => Employee::query()->with('user')->orderBy('employee_id')->get()->mapWithKeys(fn (Employee $e): array => [$e->getKey() => $e->display_label])->all())
                ->searchable()
                ->required(),
            Select::make('kind')
                ->options(array_diff_key(ProvidentFundEntry::KINDS, [ProvidentFundEntry::KIND_CONTRIBUTION => true]))
                ->required()
                ->helperText('Monthly contributions arrive on their own when a payroll run is signed off.'),
            DatePicker::make('entry_date')->native(false)->default(now())->required(),
            TextInput::make('employee_amount')->label("Employee's share")->numeric()->default(0)->required(),
            TextInput::make('employer_amount')->label("Employer's share")->numeric()->default(0)->required(),
            TextInput::make('reference')->maxLength(190)->placeholder('Opening balance from the old register; profit for FY 2025-26'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('entry_date')->date('d M Y')->sortable(),
                TextColumn::make('employee.display_label')->label('Employee'),
                TextColumn::make('kind')->badge()->formatStateUsing(fn (string $state): string => ProvidentFundEntry::KINDS[$state] ?? $state),
                TextColumn::make('employee_amount')->label('Employee')->numeric(2)->alignRight(),
                TextColumn::make('employer_amount')->label('Employer')->numeric(2)->alignRight(),
                TextColumn::make('net')->label('Movement')->state(fn (ProvidentFundEntry $r): string => number_format($r->net(), 2))->alignRight(),
                TextColumn::make('reference')->limit(40),
                TextColumn::make('balance')
                    ->label('Balance now')
                    ->state(fn (ProvidentFundEntry $r): string => number_format(app(ProvidentFund::class)->balanceFor((int) $r->employee_id), 2))
                    ->alignRight()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('employee_id')->label('Employee')->relationship('employee', 'employee_id'),
                SelectFilter::make('kind')->options(ProvidentFundEntry::KINDS),
            ])
            ->defaultSort('id', 'desc')
            ->recordActions([]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListProvidentFundEntries::route('/'),
            'create' => CreateProvidentFundEntry::route('/create'),
        ];
    }
}
