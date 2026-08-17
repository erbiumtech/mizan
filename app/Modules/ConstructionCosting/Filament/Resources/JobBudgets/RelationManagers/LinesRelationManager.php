<?php

namespace App\Modules\ConstructionCosting\Filament\Resources\JobBudgets\RelationManagers;

use App\Modules\Construction\Models\CostCode;
use App\Modules\Construction\Models\WbsNode;
use App\Modules\ConstructionCosting\Models\JobBudget;
use App\Modules\ConstructionCosting\Models\JobBudgetLine;
use App\Modules\ConstructionCosting\Services\BudgetService;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\Summarizers\Sum;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * The priced lines of one budget version.
 *
 * **Quantity and rate are asked for, not just the amount.** The unit rate is the whole of cost control — "we
 * budgeted 90 m³ at 2,000 a metre" is what the next tender is priced from, and it cannot be recovered from money
 * alone. The amount is what the report totals; the rate is what anybody learns from.
 *
 * **Period is optional and its absence has a consequence the form states.** A line with a month is time-phased,
 * which is what makes planned value and therefore schedule variance computable. Left blank, §14 requires the cost
 * report to say "schedule performance unavailable" rather than showing a zero — so the helper text says so here,
 * where somebody can still decide to phase it.
 */
class LinesRelationManager extends RelationManager
{
    protected static string $relationship = 'lines';

    protected static ?string $title = 'Budget lines';

    /**
     * Editable only while the version is a draft.
     *
     * The rule is `BudgetService`'s, and this is the courtesy: an approved budget is what a variance is measured
     * against, so a screen offering to edit one is offering something the service will refuse.
     */
    private function isDraft(): bool
    {
        /** @var JobBudget $version */
        $version = $this->getOwnerRecord();

        return $version->status === JobBudget::STATUS_DRAFT;
    }

    public function form(Schema $schema): Schema
    {
        /** @var JobBudget $version */
        $version = $this->getOwnerRecord();

        return $schema
            ->columns(2)
            ->components([
                Select::make('cost_code_id')
                    ->label('Cost code')
                    // Only bookable codes: a heading with codes under it would be counted twice in every
                    // rolled-up total, which is the same rule the cost ledger keeps.
                    ->options(fn (): array => CostCode::query()->where('is_leaf', true)->where('is_active', true)
                        ->orderBy('code')->get()
                        ->mapWithKeys(fn (CostCode $code): array => [$code->getKey() => "{$code->code} — {$code->name}"])
                        ->all())
                    ->searchable()
                    ->required()
                    ->live()
                    // The unit travels with the code, so nobody prices tonnes against a cubic-metre rate.
                    ->afterStateUpdated(function ($state, callable $get, callable $set): void {
                        if (($get('unit_of_measure') ?? '') === '') {
                            $set('unit_of_measure', CostCode::query()->find($state)?->unit);
                        }
                    }),

                Select::make('wbs_node_id')
                    ->label('WBS element')
                    ->options(fn (): array => WbsNode::query()
                        ->where('job_id', $version->job_id)
                        ->orderBy('path')->get()
                        ->mapWithKeys(fn (WbsNode $node): array => [$node->getKey() => "{$node->code} — {$node->name}"])
                        ->all())
                    ->searchable()
                    ->placeholder('Whole job')
                    ->helperText('The control account earned value is measured at.'),

                TextInput::make('quantity')
                    ->numeric()
                    ->live(onBlur: true)
                    ->afterStateUpdated(fn ($state, callable $get, callable $set) => static::recomputeAmount($get, $set)),

                TextInput::make('unit_of_measure')
                    ->label('Unit')
                    ->maxLength(16),

                TextInput::make('unit_rate')
                    ->label('Rate')
                    ->numeric()
                    ->live(onBlur: true)
                    ->afterStateUpdated(fn ($state, callable $get, callable $set) => static::recomputeAmount($get, $set)),

                TextInput::make('amount')
                    ->numeric()
                    ->required()
                    ->helperText('Quantity times rate, and editable — a lump sum has no quantity.'),

                DatePicker::make('period_start')
                    ->label('Month')
                    ->helperText('Which month this budget belongs to. Left blank on every line, schedule variance reads "unavailable" rather than zero.'),

                TextInput::make('description')
                    ->maxLength(255)
                    ->columnSpanFull(),
            ]);
    }

    /**
     * Quantity times rate into the amount, without taking the amount away from anybody.
     *
     * A rate is the useful figure and a lump sum has no quantity, so this fills the amount in and leaves it
     * editable rather than deriving it — the same choice the invoice line form makes.
     */
    private static function recomputeAmount(callable $get, callable $set): void
    {
        $quantity = (float) ($get('quantity') ?? 0);
        $rate = (float) ($get('unit_rate') ?? 0);

        if ($quantity !== 0.0 && $rate !== 0.0) {
            $set('amount', round($quantity * $rate, 2));
        }
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('description')
            ->columns([
                TextColumn::make('costCode.code')
                    ->label('Code')
                    ->description(fn (JobBudgetLine $record): ?string => $record->costCode?->name)
                    ->searchable()
                    ->sortable(),

                TextColumn::make('wbsNode.code')
                    ->label('WBS')
                    ->placeholder('Whole job')
                    ->toggleable(),

                TextColumn::make('cost_type')
                    ->label('Type')
                    ->badge(),

                TextColumn::make('quantity')
                    ->alignEnd()
                    ->placeholder('—'),

                TextColumn::make('unit_of_measure')
                    ->label('Unit')
                    ->placeholder('—')
                    ->toggleable(),

                TextColumn::make('unit_rate')
                    ->label('Rate')
                    ->alignEnd()
                    ->placeholder('—'),

                TextColumn::make('amount')
                    ->money('PKR')
                    ->alignEnd()
                    ->sortable()
                    ->summarize(Sum::make()->money('PKR')),

                TextColumn::make('period_start')
                    ->label('Month')
                    ->formatStateUsing(fn ($state): string => $state->format('M Y'))
                    // The em dash is the honest answer and the tooltip says what it costs.
                    ->placeholder('—')
                    ->tooltip(fn (JobBudgetLine $record): ?string => $record->period_start === null
                        ? 'Not phased: this line cannot contribute to planned value.'
                        : null),
            ])
            ->defaultSort('id')
            ->headerActions([
                CreateAction::make()
                    ->visible(fn (): bool => $this->isDraft())
                    // Through the service, which snapshots the cost type off the code and refuses a heading.
                    ->using(fn (array $data): Model => app(BudgetService::class)
                        ->addLine($this->getOwnerRecord(), $data)),
            ])
            ->recordActions([
                EditAction::make()->visible(fn (): bool => $this->isDraft()),
                DeleteAction::make()->visible(fn (): bool => $this->isDraft()),
            ])
            ->emptyStateHeading($this->isDraft() ? 'No lines yet' : 'This version has no lines')
            ->emptyStateDescription($this->isDraft()
                ? 'A version with no lines cannot be approved: an empty budget approved as current reads as a job with no budget at all.'
                : 'Approved versions are not edited. A change to the budget is a new revision.');
    }
}
