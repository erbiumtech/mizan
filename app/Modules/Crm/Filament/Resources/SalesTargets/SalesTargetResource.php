<?php

namespace App\Modules\Crm\Filament\Resources\SalesTargets;

use App\Filament\Concerns\BelongsToModule;
use App\Modules\Crm\Filament\Resources\SalesTargets\Pages\CreateSalesTarget;
use App\Modules\Crm\Filament\Resources\SalesTargets\Pages\EditSalesTarget;
use App\Modules\Crm\Filament\Resources\SalesTargets\Pages\ListSalesTargets;
use App\Modules\Crm\Models\SalesTarget;
use App\Modules\Crm\Services\PipelineReports;
use App\Modules\Employees\Models\Employee;
use BackedEnum;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

/**
 * Phase 7: what somebody is expected to bring in.
 *
 * Attainment is computed here. **Paying it is not.** §3 refuses to compute a commission into
 * payroll: a commission is a pay-component amount somebody enters after approval, because the
 * first disputed deal would otherwise become a payroll incident — the same reason performance
 * ratings are kept away from pay.
 */
class SalesTargetResource extends Resource
{
    use BelongsToModule;

    protected static ?string $model = SalesTarget::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedFlag;

    protected static string|UnitEnum|null $navigationGroup = 'Sales';

    protected static ?string $modelLabel = 'Sales target';

    protected static ?int $navigationSort = 31;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('employee_id')
                ->label('Whose')
                ->options(fn (): array => Employee::query()
                    ->where('is_active', true)
                    ->get()
                    ->mapWithKeys(fn (Employee $e): array => [$e->id => $e->display_label])
                    ->all())
                ->searchable()
                ->required(),

            Select::make('kind')
                ->label('Measured on')
                ->options([
                    SalesTarget::KIND_WON_VALUE => 'Value of deals won',
                    SalesTarget::KIND_NEW_LEADS => 'New leads',
                    SalesTarget::KIND_ACTIVITIES => 'Calls and meetings logged',
                ])
                ->default(SalesTarget::KIND_WON_VALUE)
                ->required()
                ->helperText('Calls and meetings must be read as effort, not performance — somebody with forty calls and no wins may be working a harder patch.'),

            DatePicker::make('period_start')->native(false)->default(now()->startOfQuarter())->required(),
            DatePicker::make('period_end')->native(false)->default(now()->endOfQuarter())->required()->afterOrEqual('period_start'),

            TextInput::make('target_amount')->label('Target')->numeric()->minValue(0)->required(),

            TextInput::make('currency_code')->label('Currency')->maxLength(3)->placeholder('PKR'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('employee.display_label')->label('Whose')->searchable()->sortable(),

                TextColumn::make('kind')
                    ->label('Measured on')
                    ->badge()
                    ->color('gray')
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        SalesTarget::KIND_NEW_LEADS => 'new leads',
                        SalesTarget::KIND_ACTIVITIES => 'calls & meetings',
                        default => 'won value',
                    }),

                TextColumn::make('period_start')
                    ->label('Period')
                    ->date('d M Y')
                    ->description(fn (SalesTarget $record): string => 'to '.$record->period_end->format('d M Y')),

                TextColumn::make('target_amount')->label('Target')->money('PKR')->alignEnd(),

                // Computed on read, from won deals at the rate each recorded. Never stored:
                // a stored attainment drifts from the deals behind it.
                TextColumn::make('attainment')
                    ->label('Achieved')
                    ->alignEnd()
                    ->state(function (SalesTarget $record): string {
                        $rows = app(PipelineReports::class)->attainment($record->period_start->toDateString());

                        foreach ($rows as $row) {
                            if ($row['target']->is($record)) {
                                return number_format($row['achieved'], 0)
                                    .($row['attainment_pct'] !== null ? " ({$row['attainment_pct']}%)" : '');
                            }
                        }

                        return '—';
                    })
                    ->description('paid by a human, never automatically'),
            ])
            ->defaultSort('period_start', 'desc')
            ->recordActions([\Filament\Actions\EditAction::make()]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSalesTargets::route('/'),
            'create' => CreateSalesTarget::route('/create'),
            'edit' => EditSalesTarget::route('/{record}/edit'),
        ];
    }
}
