<?php

namespace App\Modules\ConstructionContracts\Filament\Resources\Contracts\RelationManagers;

use App\Modules\Construction\Models\CostCode;
use App\Modules\Construction\Models\WbsNode;
use App\Modules\ConstructionContracts\Models\Contract;
use App\Modules\ConstructionContracts\Models\ContractItem;
use App\Modules\ConstructionContracts\Services\ContractService;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\Summarizers\Sum;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * The item schedule — a Bill of Quantities or a Schedule of Values, depending on the contract's family.
 *
 * **The same rows either way** (§8.2). AIA fills the number, the description and the scheduled value; FIDIC
 * fills unit, quantity and rate and lets the value follow. The form asks for all of it and requires only what
 * both need, which is what makes one screen serve both standards rather than two screens claiming to.
 *
 * The **cost code** on a line is the join to job cost, and it is the field worth filling in even though it is
 * optional: without it the certified value and the cost that earned it cannot be compared, which is the
 * question the commercial team asks every month.
 */
class ItemsRelationManager extends RelationManager
{
    protected static string $relationship = 'items';

    protected static ?string $title = 'Item schedule';

    /** Titled in the contract's own vocabulary, which is the whole of what the standard drives (§8.3). */
    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        /** @var Contract $ownerRecord */
        return $ownerRecord->vocabulary()->itemSchedule();
    }

    private function isDraft(): bool
    {
        /** @var Contract $contract */
        $contract = $this->getOwnerRecord();

        return $contract->isDraft();
    }

    public function form(Schema $schema): Schema
    {
        /** @var Contract $contract */
        $contract = $this->getOwnerRecord();

        return $schema
            ->columns(2)
            ->components([
                TextInput::make('item_no')
                    ->label('Item')
                    ->required()
                    ->maxLength(255)
                    ->helperText('The printed line number: 2.1.4, or 03 30 00.'),

                Select::make('item_type')
                    ->label('Type')
                    ->options([
                        ContractItem::TYPE_MEASURED => 'Measured',
                        ContractItem::TYPE_LUMP_SUM => 'Lump sum',
                        ContractItem::TYPE_PROVISIONAL_SUM => 'Provisional sum',
                        ContractItem::TYPE_PRIME_COST_SUM => 'Prime cost sum',
                        ContractItem::TYPE_DAYWORKS => 'Dayworks',
                        ContractItem::TYPE_CONTINGENCY => 'Contingency',
                        ContractItem::TYPE_MILESTONE => 'Milestone',
                        ContractItem::TYPE_ADVANCE => 'Advance',
                        ContractItem::TYPE_ADJUSTMENT => 'Adjustment',
                    ])
                    ->default(ContractItem::TYPE_MEASURED)
                    ->selectablePlaceholder(false),

                Textarea::make('description')
                    ->required()
                    ->rows(2)
                    ->columnSpanFull(),

                Select::make('parent_id')
                    ->label('Under section')
                    ->options(fn (): array => ContractItem::query()
                        ->where('contract_id', $contract->getKey())
                        ->orderBy('sort')->get()
                        ->mapWithKeys(fn (ContractItem $item): array => [$item->getKey() => $item->displayName()])
                        ->all())
                    ->searchable()
                    ->placeholder('Top level')
                    ->helperText('BoQ sections and continuation-sheet subtotal groups.'),

                Select::make('cost_code_id')
                    ->label('Cost code')
                    ->options(fn (): array => CostCode::query()->where('is_leaf', true)->where('is_active', true)
                        ->orderBy('code')->get()
                        ->mapWithKeys(fn (CostCode $code): array => [$code->getKey() => "{$code->code} — {$code->name}"])
                        ->all())
                    ->searchable()
                    ->helperText('The join to job cost. Without it, certified value and the cost that earned it cannot be compared.'),

                Select::make('wbs_node_id')
                    ->label('WBS element')
                    ->options(fn (): array => WbsNode::query()
                        ->where('job_id', $contract->job_id)
                        ->orderBy('path')->get()
                        ->mapWithKeys(fn (WbsNode $node): array => [$node->getKey() => "{$node->code} — {$node->name}"])
                        ->all())
                    ->searchable()
                    ->placeholder('Whole job'),

                TextInput::make('unit')
                    ->maxLength(16)
                    ->helperText('Left blank on a lump-sum or Schedule of Values line.'),

                TextInput::make('quantity')
                    ->numeric()
                    ->live(onBlur: true)
                    ->afterStateUpdated(fn ($state, callable $get, callable $set) => static::recomputeValue($get, $set)),

                TextInput::make('rate')
                    ->numeric()
                    ->live(onBlur: true)
                    ->afterStateUpdated(fn ($state, callable $get, callable $set) => static::recomputeValue($get, $set)),

                TextInput::make('scheduled_value')
                    ->label('Scheduled value')
                    ->numeric()
                    ->required()
                    // Editable, because a lump sum has no quantity. Frozen at execution, not here — the model
                    // stops recomputing it once the contract is no longer a draft.
                    ->helperText('Quantity × rate where both are given, and editable. Frozen when the contract is executed.'),

                Toggle::make('retention_applies')
                    ->label('Retention applies')
                    ->default(true),

                Toggle::make('materials_allowed')
                    ->label('Materials on site claimable')
                    ->helperText('Whether materials delivered but not built in may be claimed against this line.'),

                TextInput::make('sort')
                    ->numeric()
                    ->default(0)
                    ->helperText('Print order within the section.'),
            ]);
    }

    /** Quantity × rate into the value, leaving it editable — the same choice the budget line form makes. */
    private static function recomputeValue(callable $get, callable $set): void
    {
        $quantity = (float) ($get('quantity') ?? 0);
        $rate = (float) ($get('rate') ?? 0);

        if ($quantity !== 0.0 && $rate !== 0.0) {
            $set('scheduled_value', round($quantity * $rate, 2));
        }
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('item_no')
            ->columns([
                TextColumn::make('item_no')
                    ->label('Item')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('description')
                    ->wrap()
                    ->searchable()
                    ->description(fn (ContractItem $record): ?string => $record->isVariationLine()
                        // §9: an approved variation appends its line rather than editing the original, which is
                        // exactly how a change order prints on a continuation sheet.
                        ? 'From a variation'
                        : null),

                TextColumn::make('item_type')
                    ->label('Type')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => str_replace('_', ' ', ucfirst($state)))
                    ->toggleable(),

                TextColumn::make('costCode.code')
                    ->label('Code')
                    ->placeholder('—')
                    ->toggleable(),

                TextColumn::make('quantity')
                    ->alignEnd()
                    ->placeholder('—'),

                TextColumn::make('unit')
                    ->placeholder('—')
                    ->toggleable(),

                TextColumn::make('rate')
                    ->alignEnd()
                    ->placeholder('—'),

                TextColumn::make('scheduled_value')
                    ->label('Scheduled value')
                    ->money('PKR')
                    ->alignEnd()
                    // An omission is negative and reads as one, which is the point of writing it that way.
                    ->color(fn (ContractItem $record): string => $record->isOmission() ? 'danger' : 'gray')
                    ->sortable()
                    ->summarize(Sum::make()->money('PKR')),
            ])
            ->defaultSort('sort')
            ->headerActions([
                CreateAction::make()
                    ->visible(fn (): bool => $this->isDraft())
                    // Through the service, which refuses an executed schedule and says what to do instead.
                    ->using(fn (array $data): Model => app(ContractService::class)
                        ->addItem($this->getOwnerRecord(), $data)),
            ])
            ->recordActions([
                EditAction::make()->visible(fn (ContractItem $record): bool => auth()->user()?->can('update', $record) ?? false),
                DeleteAction::make()->visible(fn (ContractItem $record): bool => auth()->user()?->can('delete', $record) ?? false),
            ])
            ->emptyStateHeading($this->isDraft() ? 'Nothing priced yet' : 'This schedule is empty')
            ->emptyStateDescription($this->isDraft()
                ? 'A contract with no lines cannot be executed: a certificate would have nothing to measure against.'
                : 'An executed schedule is changed by a variation, which appends its own line.');
    }
}
