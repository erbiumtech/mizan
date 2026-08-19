<?php

namespace App\Modules\ConstructionCosting\Filament\Resources\GoodsReceipts\RelationManagers;

use App\Modules\Construction\Models\CostCode;
use App\Modules\Construction\Models\Job;
use App\Modules\ConstructionCosting\Models\CommitmentLine;
use App\Modules\ConstructionCosting\Models\GoodsReceipt;
use App\Modules\ConstructionCosting\Models\GoodsReceiptLine;
use App\Modules\ConstructionCosting\Services\GoodsReceiptService;
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
 * What arrived — `docs/construction-management-plan.md` §5.
 *
 * **Received against an order line seeds everything from it**, including the rate. That matters: the accrual has to be
 * at order rate for a three-way match to mean anything, and a rate typed a second time is a rate that will differ from
 * the first.
 *
 * **Over-delivery is allowed.** A supplier sending a full pack rather than the ordered part of one happens, and the
 * honest treatment is to record what arrived and let the order read as over-relieved — refusing would leave material on
 * site and uncosted.
 *
 * **The store destination is not offered.** §6's site-store path needs a stock location, which this application does
 * not have yet; the field exists in the schema so the later phase has somewhere to write, and the service refuses a
 * store line rather than costing it as though it had been stocked.
 */
class ReceiptLinesRelationManager extends RelationManager
{
    protected static string $relationship = 'lines';

    protected static ?string $title = 'What arrived';

    private function isDraft(): bool
    {
        /** @var GoodsReceipt $receipt */
        $receipt = $this->getOwnerRecord();

        return $receipt->isDraft();
    }

    public function form(Schema $schema): Schema
    {
        /** @var GoodsReceipt $receipt */
        $receipt = $this->getOwnerRecord();

        return $schema
            ->columns(2)
            ->components([
                Select::make('commitment_line_id')
                    ->label('Order line')
                    ->options(fn (): array => CommitmentLine::query()
                        ->where('commitment_id', $receipt->commitment_id)
                        ->with('costCode')
                        ->get()
                        ->mapWithKeys(fn (CommitmentLine $line): array => [
                            $line->getKey() => $line->displayName()
                                .' — '.rtrim(rtrim(number_format($line->openAmount(), 2, '.', ''), '0'), '.').' open',
                        ])
                        ->all())
                    ->searchable()
                    ->live()
                    // Absent on an unordered delivery, where the job and the code have to be picked by hand.
                    ->visible(fn (): bool => $receipt->commitment_id !== null)
                    ->afterStateUpdated(function ($state, callable $set): void {
                        $line = CommitmentLine::query()->with('costCode')->find($state);

                        if (! $line) {
                            return;
                        }

                        // Seeded from the order, including the rate — see the class docblock on why not retyped.
                        $set('job_id', $line->job_id);
                        $set('cost_code_id', $line->cost_code_id);
                        $set('description', $line->description);
                        $set('unit_of_measure', $line->unit_of_measure);
                        $set('unit_rate', $line->rate);
                    })
                    ->helperText('Picking one fills in the job, the code and the rate from the order.'),

                Select::make('job_id')
                    ->label('Job')
                    ->options(fn (): array => Job::query()->live()->orderBy('code')->get()
                        ->mapWithKeys(fn (Job $job): array => [$job->getKey() => "{$job->code} — {$job->name}"])
                        ->all())
                    ->searchable()
                    ->required(),

                Select::make('cost_code_id')
                    ->label('Cost code')
                    ->options(fn (): array => CostCode::query()->where('is_leaf', true)->where('is_active', true)
                        ->orderBy('code')->get()
                        ->mapWithKeys(fn (CostCode $code): array => [$code->getKey() => "{$code->code} — {$code->name}"])
                        ->all())
                    ->searchable()
                    ->required(),

                Textarea::make('description')
                    ->required()
                    ->rows(2)
                    ->columnSpanFull(),

                TextInput::make('quantity')
                    ->numeric()
                    ->required()
                    ->minValue(0.0001)
                    ->helperText('What actually arrived. More than was ordered is allowed — the order will read as over-relieved.'),

                TextInput::make('unit_of_measure')
                    ->label('Unit')
                    ->maxLength(16),

                TextInput::make('unit_rate')
                    ->label('Rate')
                    ->numeric()
                    ->helperText('The order rate. The accrual is raised at this, and the invoice may disagree.'),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('description')
            ->columns([
                TextColumn::make('job.code')
                    ->label('Job')
                    ->sortable(),

                TextColumn::make('costCode.code')
                    ->label('Code')
                    ->description(fn (GoodsReceiptLine $record): ?string => $record->costCode?->name),

                TextColumn::make('description')
                    ->wrap()
                    ->searchable(),

                TextColumn::make('quantity')
                    ->label('Received')
                    ->alignEnd()
                    ->description(fn (GoodsReceiptLine $record): ?string => $record->unit_of_measure),

                TextColumn::make('unit_rate')
                    ->label('Rate')
                    ->alignEnd()
                    ->placeholder('—')
                    ->toggleable(),

                TextColumn::make('amount')
                    ->label('Value')
                    ->money('PKR')
                    ->alignEnd()
                    ->summarize(Sum::make()->money('PKR')),

                TextColumn::make('commitmentLine.commitment.number')
                    ->label('Order')
                    ->placeholder('Unordered')
                    ->toggleable(),

                TextColumn::make('cost_entry_id')
                    ->label('Accrued')
                    // The accrual this line raised, which is what makes posting idempotent and traceable.
                    ->formatStateUsing(fn ($state): string => $state ? 'Yes' : 'No')
                    ->toggleable(),
            ])
            ->defaultSort('id')
            ->headerActions([
                CreateAction::make()
                    ->visible(fn (): bool => $this->isDraft())
                    ->using(function (array $data): Model {
                        $service = app(GoodsReceiptService::class);
                        $receipt = $this->getOwnerRecord();

                        // Against an order line where one was picked, so the rate and the link come from the order
                        // rather than from whoever is typing.
                        if (($data['commitment_line_id'] ?? null)) {
                            return $service->addLineFor(
                                $receipt,
                                CommitmentLine::query()->findOrFail($data['commitment_line_id']),
                                (float) $data['quantity'],
                                collect($data)->only(['description', 'unit_rate', 'unit_of_measure'])->filter()->all(),
                            );
                        }

                        return $service->addLine(
                            $receipt,
                            Job::query()->findOrFail($data['job_id']),
                            CostCode::query()->findOrFail($data['cost_code_id']),
                            $data,
                        );
                    }),
            ])
            ->recordActions([
                EditAction::make()->visible(fn (): bool => $this->isDraft()),
                DeleteAction::make()->visible(fn (): bool => $this->isDraft()),
            ])
            ->emptyStateHeading('Nothing recorded yet')
            ->emptyStateDescription($this->isDraft()
                ? 'Add what arrived. Posting the receipt relieves the order and puts the cost on the job.'
                : 'This receipt is posted. Record a second delivery rather than editing this one — the accruals and the relief are already out there.');
    }
}
