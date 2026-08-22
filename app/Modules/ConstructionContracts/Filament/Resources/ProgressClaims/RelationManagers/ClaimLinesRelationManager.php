<?php

namespace App\Modules\ConstructionContracts\Filament\Resources\ProgressClaims\RelationManagers;

use App\Modules\ConstructionContracts\Models\ProgressClaim;
use App\Modules\ConstructionContracts\Models\ProgressClaimLine;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\Summarizers\Sum;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * What is being claimed, line by line — `docs/construction-management-plan.md` §10.2.
 *
 * **Every figure here is cumulative**, which the labels say out loud: "to date", not "this period". A monthly
 * figure typed into a cumulative field is the commonest way a claim comes out wrong, and it looks entirely
 * plausible until somebody compares it with last month.
 *
 * The **measured by** column records which input the person actually typed, and it is not decoration: a percent
 * against a line whose quantity a variation later grew is measured on a stale denominator, so the value is
 * authoritative and the input is what lets a later reader reproduce the intent.
 *
 * Lines are not added or removed here. They are the schedule, seeded when the claim is opened — a claim that
 * omitted lines would not add up to the contract, and one that invented them would claim against nothing.
 */
class ClaimLinesRelationManager extends RelationManager
{
    protected static string $relationship = 'lines';

    protected static ?string $title = 'Measured lines';

    private function isEditable(): bool
    {
        /** @var ProgressClaim $claim */
        $claim = $this->getOwnerRecord();

        return $claim->isEditable();
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components([
                Select::make('measurement_input')
                    ->label('Measured by')
                    ->options([
                        ProgressClaimLine::INPUT_PERCENT => 'Percent complete',
                        ProgressClaimLine::INPUT_QUANTITY => 'Quantity done',
                        ProgressClaimLine::INPUT_VALUE => 'Value',
                        ProgressClaimLine::INPUT_MILESTONE => 'Milestone — nothing or everything',
                    ])
                    ->default(ProgressClaimLine::INPUT_PERCENT)
                    ->selectablePlaceholder(false)
                    ->live()
                    ->helperText('Whichever you actually typed. All three become a value; this records which one it was.'),

                TextInput::make('cumulative_percent')
                    ->label('Percent complete to date')
                    ->numeric()
                    ->visible(fn (callable $get): bool => in_array($get('measurement_input'), [
                        ProgressClaimLine::INPUT_PERCENT,
                        ProgressClaimLine::INPUT_MILESTONE,
                    ], true))
                    ->helperText('Cumulative, not this period.'),

                TextInput::make('cumulative_quantity')
                    ->label('Quantity to date')
                    ->numeric()
                    ->visible(fn (callable $get): bool => $get('measurement_input') === ProgressClaimLine::INPUT_QUANTITY)
                    ->helperText('Cumulative, not this period.'),

                TextInput::make('cumulative_work_value')
                    ->label('Work value to date')
                    ->numeric()
                    ->required(fn (callable $get): bool => $get('measurement_input') === ProgressClaimLine::INPUT_VALUE)
                    ->helperText('Computed from the input above when the claim is submitted, unless you are claiming by value.'),

                TextInput::make('cumulative_materials_value')
                    ->label('Materials on site to date')
                    ->numeric()
                    ->helperText('Delivered and not yet built in. Retained at its own rate where the contract says so.'),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('id')
            ->columns([
                TextColumn::make('contractItem.item_no')
                    ->label('Item')
                    ->sortable(),

                TextColumn::make('contractItem.description')
                    ->label('Description')
                    ->wrap()
                    ->searchable(),

                TextColumn::make('contractItem.scheduled_value')
                    ->label('Scheduled')
                    ->money('PKR')
                    ->alignEnd(),

                TextColumn::make('measurement_input')
                    ->label('Measured by')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => ucfirst($state))
                    ->toggleable(),

                TextColumn::make('cumulative_percent')
                    ->label('%')
                    ->alignEnd()
                    ->placeholder('—')
                    ->toggleable(),

                TextColumn::make('cumulative_work_value')
                    ->label('Work to date')
                    ->money('PKR')
                    ->alignEnd()
                    ->summarize(Sum::make()->money('PKR')),

                TextColumn::make('cumulative_materials_value')
                    ->label('Materials')
                    ->money('PKR')
                    ->alignEnd()
                    ->summarize(Sum::make()->money('PKR')),
            ])
            ->defaultSort('id')
            ->recordActions([
                EditAction::make()->visible(fn (): bool => $this->isEditable()),
            ])
            ->emptyStateHeading('No lines')
            ->emptyStateDescription('Lines are seeded from the contract schedule when a claim is opened.');
    }
}
