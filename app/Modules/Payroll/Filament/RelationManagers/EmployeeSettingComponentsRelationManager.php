<?php

namespace App\Modules\Payroll\Filament\RelationManagers;

use App\Modules\Payroll\Models\PayComponent;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * What this package pays of each added component.
 *
 * Only the added ones are offered. Basic wage and the four shipped allowances are
 * fields on the package itself and reading them from two places would let the two
 * disagree — the arithmetic still takes them from their columns.
 *
 * On the setting rather than the employee so it inherits the date ranges payroll
 * already versions packages by: a raise in March is a new setting, and its component
 * amounts go with it.
 *
 * **Lived in Employees, and referenced `PayComponent` by its full name to hide the edge.** Its own docblock
 * said so: *"referenced by its full name rather than imported, deliberately"* — which is the manoeuvre
 * `docs/module-packaging-plan.md` names as the cycle being avoided in the lint rather than in the code, and
 * which phase 0's scan sees straight through. Payroll requires Employees, so the honest fix is the phase 7
 * one: Employees offers a slot, this fills it from `PayrollServiceProvider`, and Employees never learns that
 * Payroll exists. `PayComponent` is imported properly here because this module owns it.
 *
 * The screen is simply absent without Payroll rather than an empty list, which is a better answer than the
 * old one for the same reason: there are no pay components to attach.
 */
class EmployeeSettingComponentsRelationManager extends RelationManager
{
    protected static string $relationship = 'components';

    protected static ?string $title = 'Added allowances and deductions';

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('pay_component_id')
                ->label('Component')
                ->options(fn (): array => PayComponent::active()
                    ->dataDriven()
                    ->orderBy('sort')
                    ->get()
                    ->mapWithKeys(fn ($c): array => [
                        $c->id => $c->label.' — '.($c->isEarning() ? 'earning' : 'deduction'),
                    ])
                    ->all())
                ->required()
                ->searchable()
                ->distinct()
                ->helperText('Defined under Settings → Pay components. The built-in parts of pay are fields on the package above.'),

            TextInput::make('amount')
                ->numeric()
                ->minValue(0)
                ->required(),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('id')
            ->columns([
                TextColumn::make('component.label')->label('Component')->sortable(),

                TextColumn::make('component.kind')
                    ->label('Kind')
                    ->badge()
                    ->color(fn (string $state): string => $state === PayComponent::KIND_EARNING ? 'success' : 'danger'),

                TextColumn::make('amount')->money('PKR')->alignEnd(),

                TextColumn::make('component.is_taxable')
                    ->label('Taxable')
                    ->state(fn ($record): string => $record->component?->isEarning()
                        ? ($record->component->is_taxable ? 'yes' : 'no')
                        : '—'),
            ])
            ->defaultSort('id')
            ->headerActions([CreateAction::make()])
            ->recordActions([EditAction::make(), DeleteAction::make()]);
    }
}
