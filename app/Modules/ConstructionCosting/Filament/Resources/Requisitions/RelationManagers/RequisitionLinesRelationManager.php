<?php

namespace App\Modules\ConstructionCosting\Filament\Resources\Requisitions\RelationManagers;

use App\Modules\Construction\Models\CostCode;
use App\Modules\ConstructionCosting\Models\Requisition;
use App\Modules\ConstructionCosting\Models\RequisitionLine;
use App\Modules\ConstructionCosting\Services\RequisitionService;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * What is being asked for, line by line — `docs/construction-management-plan.md` §5.
 *
 * **Ordered and outstanding are computed from the order lines that name each request line.** Many orders to one
 * request, because forty tonnes ordered as twenty now and twenty in March is ordinary — and a cancelled order leaves
 * its line outstanding again, which is the state site is actually in.
 *
 * The **cost code is optional here**. Site asks for materials; the buyer decides which code carries them, and
 * `RequisitionService::order()` refuses to raise an order line without one. Demanding it on the request would teach
 * people to pick whichever code lets the form save.
 */
class RequisitionLinesRelationManager extends RelationManager
{
    protected static string $relationship = 'lines';

    protected static ?string $title = 'What is needed';

    private function isEditable(): bool
    {
        /** @var Requisition $requisition */
        $requisition = $this->getOwnerRecord();

        return $requisition->isEditable();
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components([
                Textarea::make('description')
                    ->required()
                    ->rows(2)
                    ->columnSpanFull()
                    ->helperText('What is needed, in the words site would use on a paper docket.'),

                TextInput::make('quantity')
                    ->numeric()
                    ->required()
                    ->minValue(0.0001),

                TextInput::make('unit_of_measure')
                    ->label('Unit')
                    ->maxLength(16),

                Select::make('cost_code_id')
                    ->label('Cost code')
                    ->options(fn (): array => CostCode::query()->where('is_leaf', true)->where('is_active', true)
                        ->orderBy('code')->get()
                        ->mapWithKeys(fn (CostCode $code): array => [$code->getKey() => "{$code->code} — {$code->name}"])
                        ->all())
                    ->searchable()
                    // Optional on purpose — see the class docblock.
                    ->helperText('Optional. The buyer sets it when the order is raised if you leave it blank.'),

                TextInput::make('estimated_rate')
                    ->label('Estimated rate')
                    ->numeric()
                    ->helperText('A guess is useful for the approval and is never a price — the committed figure comes from the supplier.'),

                TextInput::make('notes')
                    ->maxLength(255),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('description')
            ->columns([
                TextColumn::make('description')
                    ->wrap()
                    ->searchable(),

                TextColumn::make('quantity')
                    ->label('Asked for')
                    ->alignEnd()
                    ->description(fn (RequisitionLine $record): ?string => $record->unit_of_measure),

                TextColumn::make('ordered')
                    ->label('Ordered')
                    ->alignEnd()
                    // Computed from the order lines, ignoring cancelled orders.
                    ->state(fn (RequisitionLine $record): string => rtrim(rtrim(number_format($record->orderedQuantity(), 4, '.', ''), '0'), '.') ?: '0'),

                TextColumn::make('outstanding')
                    ->label('Still to order')
                    ->alignEnd()
                    ->weight('bold')
                    ->state(fn (RequisitionLine $record): string => rtrim(rtrim(number_format($record->outstandingQuantity(), 4, '.', ''), '0'), '.') ?: '0')
                    ->color(fn (RequisitionLine $record): string => $record->outstandingQuantity() > 0.0 ? 'warning' : 'gray'),

                TextColumn::make('costCode.code')
                    ->label('Code')
                    // An em dash rather than a blank: the buyer has to supply one, and this is where they see that.
                    ->placeholder('Buyer to set')
                    ->toggleable(),

                TextColumn::make('estimated_amount')
                    ->label('Estimate')
                    ->money('PKR')
                    ->alignEnd()
                    ->placeholder('—')
                    ->toggleable(),
            ])
            ->defaultSort('id')
            ->headerActions([
                CreateAction::make()
                    ->visible(fn (): bool => $this->isEditable())
                    ->using(fn (array $data): Model => app(RequisitionService::class)
                        ->addLine($this->getOwnerRecord(), $data)),
            ])
            ->recordActions([
                EditAction::make()->visible(fn (): bool => $this->isEditable()),
                DeleteAction::make()->visible(fn (): bool => $this->isEditable()),
            ])
            ->emptyStateHeading('Nothing asked for yet')
            ->emptyStateDescription($this->isEditable()
                ? 'Add what is needed. A request with no lines cannot be submitted.'
                : 'This request has been approved. Raise another for anything further — the approval was for what it said at the time.');
    }
}
