<?php

namespace App\Modules\ConstructionContracts\Filament\Resources\Variations\RelationManagers;

use App\Modules\Construction\Models\CostCode;
use App\Modules\ConstructionContracts\Models\ContractItem;
use App\Modules\ConstructionContracts\Models\Variation;
use App\Modules\ConstructionContracts\Models\VariationItem;
use App\Modules\ConstructionContracts\Services\VariationService;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\Summarizers\Sum;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * What a variation does to the schedule, line by line.
 *
 * The four actions and what each becomes when the variation is written into the schedule:
 *
 * | Action | What it writes |
 * |---|---|
 * | Add | a new schedule line, printed appended rather than folded into the original bill |
 * | Omit | a **negative** line — never a reduction of the line it omits |
 * | Remeasure | the quantity on the named line, keeping the old one here |
 * | Rate change | the rate on the named line, keeping the old one here |
 *
 * The omission rule is the one worth reading twice: reducing the original would make every certificate already
 * issued print a "completed to date" figure exceeding the "scheduled value" it was measured against, which is
 * impossible on the face of the form.
 */
class VariationItemsRelationManager extends RelationManager
{
    protected static string $relationship = 'items';

    protected static ?string $title = 'Lines';

    private function isOpen(): bool
    {
        /** @var Variation $variation */
        $variation = $this->getOwnerRecord();

        return ! in_array($variation->status, [Variation::STATUS_APPROVED, Variation::STATUS_INCORPORATED], true);
    }

    public function form(Schema $schema): Schema
    {
        /** @var Variation $variation */
        $variation = $this->getOwnerRecord();

        return $schema
            ->columns(2)
            ->components([
                Select::make('action')
                    ->label('Action')
                    ->options([
                        VariationItem::ACTION_ADD => 'Add — a new line',
                        VariationItem::ACTION_OMIT => 'Omit — remove work, as a negative line',
                        VariationItem::ACTION_REMEASURE => 'Remeasure — change the quantity',
                        VariationItem::ACTION_RATE_CHANGE => 'Rate change — change the rate',
                    ])
                    ->default(VariationItem::ACTION_ADD)
                    ->selectablePlaceholder(false)
                    ->live()
                    ->required(),

                Select::make('contract_item_id')
                    ->label('Schedule line')
                    ->options(fn (): array => ContractItem::query()
                        ->where('contract_id', $variation->contract_id)
                        ->where('is_active', true)
                        ->orderBy('sort')->get()
                        ->mapWithKeys(fn (ContractItem $item): array => [$item->getKey() => $item->displayName()])
                        ->all())
                    ->searchable()
                    // Required for everything but an add, which has no existing line to act on.
                    ->required(fn (callable $get): bool => $get('action') !== VariationItem::ACTION_ADD)
                    ->visible(fn (callable $get): bool => $get('action') !== VariationItem::ACTION_ADD)
                    ->helperText('The line this acts on.'),

                TextInput::make('item_no')
                    ->label('New item number')
                    ->required(fn (callable $get): bool => $get('action') === VariationItem::ACTION_ADD)
                    ->visible(fn (callable $get): bool => $get('action') === VariationItem::ACTION_ADD)
                    ->helperText('What it will print as on the schedule.'),

                Textarea::make('description')
                    ->rows(2)
                    ->columnSpanFull(),

                Select::make('cost_code_id')
                    ->label('Cost code')
                    ->options(fn (): array => CostCode::query()->where('is_leaf', true)->where('is_active', true)
                        ->orderBy('code')->get()
                        ->mapWithKeys(fn (CostCode $code): array => [$code->getKey() => "{$code->code} — {$code->name}"])
                        ->all())
                    ->searchable()
                    ->visible(fn (callable $get): bool => $get('action') === VariationItem::ACTION_ADD)
                    ->helperText('The join to job cost, same as on the original schedule.'),

                TextInput::make('unit')->maxLength(16),

                TextInput::make('quantity')
                    ->numeric()
                    ->live(onBlur: true)
                    ->afterStateUpdated(fn ($state, callable $get, callable $set) => static::recomputeAmount($get, $set)),

                TextInput::make('rate')
                    ->numeric()
                    ->live(onBlur: true)
                    ->afterStateUpdated(fn ($state, callable $get, callable $set) => static::recomputeAmount($get, $set)),

                TextInput::make('amount')
                    ->numeric()
                    ->required()
                    // An omission is written as the figure to come off; incorporation makes the sign negative
                    // whichever way it was typed, so a positive omission cannot quietly add money.
                    ->helperText('Quantity × rate where both are given. On an omission, the amount to come off.'),
            ]);
    }

    private static function recomputeAmount(callable $get, callable $set): void
    {
        $quantity = (float) ($get('quantity') ?? 0);
        $rate = (float) ($get('rate') ?? 0);

        if ($quantity !== 0.0 && $rate !== 0.0) {
            $set('amount', round($quantity * $rate, 2));
        }
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('description')
            ->columns([
                TextColumn::make('action')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        VariationItem::ACTION_ADD => 'success',
                        VariationItem::ACTION_OMIT => 'danger',
                        default => 'warning',
                    })
                    ->formatStateUsing(fn (string $state): string => str_replace('_', ' ', ucfirst($state))),

                TextColumn::make('contractItem.item_no')
                    ->label('Schedule line')
                    ->placeholder('New line')
                    ->description(fn (VariationItem $record): ?string => $record->item_no),

                TextColumn::make('description')
                    ->wrap(),

                TextColumn::make('quantity')
                    ->alignEnd()
                    ->placeholder('—')
                    // The audit trail for the one permitted in-place edit, on the row that made it.
                    ->description(fn (VariationItem $record): ?string => $record->previous_quantity === null
                        ? null
                        : 'was '.$record->previous_quantity),

                TextColumn::make('rate')
                    ->alignEnd()
                    ->placeholder('—')
                    ->description(fn (VariationItem $record): ?string => $record->previous_rate === null
                        ? null
                        : 'was '.$record->previous_rate),

                TextColumn::make('amount')
                    ->money('PKR')
                    ->alignEnd()
                    ->summarize(Sum::make()->money('PKR')),

                TextColumn::make('resultingItem.item_no')
                    ->label('Wrote')
                    ->placeholder('—')
                    ->tooltip('The schedule line this became when the variation was written in.')
                    ->toggleable(),
            ])
            ->defaultSort('id')
            ->headerActions([
                CreateAction::make()
                    ->visible(fn (): bool => $this->isOpen())
                    // Through the service, which refuses a line with no target and an add with no number.
                    ->using(fn (array $data): Model => app(VariationService::class)
                        ->addItem($this->getOwnerRecord(), $data)),
            ])
            ->recordActions([
                EditAction::make()->visible(fn (): bool => $this->isOpen()),
                DeleteAction::make()->visible(fn (): bool => $this->isOpen()),
            ])
            ->emptyStateHeading('No lines yet')
            ->emptyStateDescription($this->isOpen()
                ? 'Add, omit, remeasure or change a rate. The lines are what gets written into the schedule when this is approved.'
                : 'These lines are what the parties agreed. A further change is another variation.');
    }
}
