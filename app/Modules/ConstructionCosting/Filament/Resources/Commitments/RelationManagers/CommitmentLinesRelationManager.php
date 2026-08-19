<?php

namespace App\Modules\ConstructionCosting\Filament\Resources\Commitments\RelationManagers;

use App\Modules\Construction\Models\CostCode;
use App\Modules\Construction\Models\Job;
use App\Modules\Construction\Models\WbsNode;
use App\Modules\ConstructionCosting\Models\Commitment;
use App\Modules\ConstructionCosting\Models\CommitmentLine;
use App\Modules\ConstructionCosting\Services\CommitmentService;
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
 * The lines of an order, where the job and the cost code live — `docs/construction-management-plan.md` §5.
 *
 * **One order, many jobs.** The job picker is on every line for the reason §5 gives at length: one order of rebar
 * split across three sites is completely normal, and the alternatives are three orders the supplier will not honour
 * or one job carrying the whole load.
 *
 * **Open is per line**, because relief is per line. A part delivery against line two says nothing about line five,
 * and an order whose openness could only be read in total would report everything as open until the last item
 * arrived.
 */
class CommitmentLinesRelationManager extends RelationManager
{
    protected static string $relationship = 'lines';

    protected static ?string $title = 'Order lines';

    private function isDraft(): bool
    {
        /** @var Commitment $commitment */
        $commitment = $this->getOwnerRecord();

        return $commitment->isDraft();
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components([
                Select::make('job_id')
                    ->label('Job')
                    ->options(fn (): array => Job::query()->live()->orderBy('code')->get()
                        ->mapWithKeys(fn (Job $job): array => [$job->getKey() => "{$job->code} — {$job->name}"])
                        ->all())
                    ->searchable()
                    ->required()
                    ->live()
                    // On the line, not the header. See the class docblock.
                    ->helperText('Each line can be a different job — one order, three sites.'),

                Select::make('cost_code_id')
                    ->label('Cost code')
                    // Only bookable codes: committing against a heading would double-count in every rolled-up
                    // total, exactly as booking cost to one would.
                    ->options(fn (): array => CostCode::query()->where('is_leaf', true)->where('is_active', true)
                        ->orderBy('code')->get()
                        ->mapWithKeys(fn (CostCode $code): array => [$code->getKey() => "{$code->code} — {$code->name}"])
                        ->all())
                    ->searchable()
                    ->required()
                    ->helperText('What the money is committed against. The cost type is taken from the code and kept.'),

                Select::make('wbs_node_id')
                    ->label('WBS element')
                    ->options(fn (callable $get): array => WbsNode::query()
                        ->where('job_id', $get('job_id'))
                        ->orderBy('path')->get()
                        ->mapWithKeys(fn (WbsNode $node): array => [$node->getKey() => "{$node->code} — {$node->name}"])
                        ->all())
                    ->searchable()
                    ->placeholder('Whole job'),

                Textarea::make('description')
                    ->required()
                    ->rows(2)
                    ->columnSpanFull()
                    ->helperText('What the supplier is being asked for, in their words — it prints on the order.'),

                TextInput::make('quantity')
                    ->numeric()
                    ->live(onBlur: true)
                    ->afterStateUpdated(fn ($state, callable $get, callable $set) => static::recomputeAmount($get, $set)),

                TextInput::make('unit_of_measure')
                    ->label('Unit')
                    ->maxLength(16),

                TextInput::make('rate')
                    ->numeric()
                    ->live(onBlur: true)
                    ->afterStateUpdated(fn ($state, callable $get, callable $set) => static::recomputeAmount($get, $set)),

                TextInput::make('amount')
                    ->numeric()
                    ->required()
                    ->helperText('Quantity × rate where both are given, and editable — a lump-sum order has neither.'),
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
                TextColumn::make('job.code')
                    ->label('Job')
                    ->description(fn (CommitmentLine $record): ?string => $record->job?->name)
                    ->sortable(),

                TextColumn::make('costCode.code')
                    ->label('Code')
                    ->description(fn (CommitmentLine $record): ?string => $record->costCode?->name),

                TextColumn::make('description')
                    ->wrap()
                    ->searchable(),

                TextColumn::make('cost_type')
                    ->label('Type')
                    ->badge()
                    ->toggleable(),

                TextColumn::make('quantity')
                    ->alignEnd()
                    ->placeholder('—'),

                TextColumn::make('rate')
                    ->alignEnd()
                    ->placeholder('—')
                    ->toggleable(),

                TextColumn::make('amount')
                    ->label('Ordered')
                    ->money('PKR')
                    ->alignEnd()
                    ->summarize(Sum::make()->money('PKR')),

                TextColumn::make('relieved')
                    ->label('Received / invoiced')
                    ->money('PKR')
                    ->alignEnd()
                    ->state(fn (CommitmentLine $record): float => $record->relievedTotal())
                    ->toggleable(),

                TextColumn::make('open')
                    ->label('Open')
                    ->money('PKR')
                    ->alignEnd()
                    ->weight('bold')
                    // Computed from the reliefs, so it cannot disagree with them.
                    ->state(fn (CommitmentLine $record): float => $record->openAmount()),
            ])
            ->defaultSort('id')
            ->headerActions([
                CreateAction::make()
                    ->visible(fn (): bool => $this->isDraft())
                    // Through the service, which refuses a heading code and an inactive one, and snapshots the
                    // cost type — the same rules the cost ledger keeps, for the same reasons.
                    ->using(fn (array $data): Model => app(CommitmentService::class)->addLine(
                        $this->getOwnerRecord(),
                        Job::query()->findOrFail($data['job_id']),
                        CostCode::query()->findOrFail($data['cost_code_id']),
                        $data,
                    )),
            ])
            ->recordActions([
                EditAction::make()->visible(fn (): bool => $this->isDraft()),
                DeleteAction::make()->visible(fn (): bool => $this->isDraft()),
            ])
            ->emptyStateHeading('Nothing ordered yet')
            ->emptyStateDescription($this->isDraft()
                ? 'Add a line per job and cost code. An order with no lines commits nothing and cannot be approved.'
                : 'This order is issued. A change to it is a variation to the order, not an edit — the supplier is working to the copy they were sent.');
    }
}
