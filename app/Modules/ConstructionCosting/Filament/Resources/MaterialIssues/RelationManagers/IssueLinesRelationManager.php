<?php

namespace App\Modules\ConstructionCosting\Filament\Resources\MaterialIssues\RelationManagers;

use App\Modules\Construction\Models\CostCode;
use App\Modules\Construction\Models\Job;
use App\Modules\Construction\Models\WbsNode;
use App\Modules\ConstructionCosting\Models\MaterialIssue;
use App\Modules\ConstructionCosting\Models\MaterialIssueLine;
use App\Modules\ConstructionCosting\Services\MaterialIssueService;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Services\InventoryValuationService;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\Summarizers\Sum;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * What came off the docket.
 *
 * **`Wasted` is a column of its own, not a note.** §6 puts wastage and its reason on the line, and the reason it earns a
 * column is that wastage is the figure nobody totals: it goes out of the store as its own `waste` movement so it can be
 * queried by type, and it stays on the job because the company paid for it.
 *
 * The **Return** action is how material comes back, against the line it went out on — §6's `returned_quantity`. A
 * separate return document would be a second numbering series for the reversal of a docket somebody is still holding.
 */
class IssueLinesRelationManager extends RelationManager
{
    protected static string $relationship = 'lines';

    protected static ?string $title = 'Material';

    private function issue(): MaterialIssue
    {
        /** @var MaterialIssue $issue */
        $issue = $this->getOwnerRecord();

        return $issue;
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components([
                Select::make('product_id')
                    ->label('Product')
                    ->options(fn (): array => Product::query()->orderBy('sku')->get()
                        ->mapWithKeys(fn (Product $product): array => [
                            $product->getKey() => "{$product->sku} — {$product->name}",
                        ])
                        ->all())
                    ->searchable()
                    ->required()
                    ->live()
                    ->helperText(fn (callable $get): string => static::onHand($get, $this->issue())),

                Select::make('job_id')
                    ->label('To job')
                    ->options(fn (): array => Job::query()->orderBy('code')->get()
                        ->mapWithKeys(fn (Job $job): array => [$job->getKey() => "{$job->code} — {$job->name}"])
                        ->all())
                    ->searchable()
                    ->required()
                    ->live()
                    // The job is on the line because one docket out of a development's store serves several of its jobs.
                    ->helperText('One docket can serve several jobs off the same store.'),

                Select::make('cost_code_id')
                    ->label('Used on')
                    ->options(fn (): array => CostCode::query()
                        ->where('is_leaf', true)->where('is_active', true)
                        ->orderBy('code')->get()
                        ->mapWithKeys(fn (CostCode $code): array => [$code->getKey() => "{$code->code} — {$code->name}"])
                        ->all())
                    ->searchable()
                    ->required()
                    ->helperText('Where the cost moves to. It has to be the same cost type as the code the material was received against.'),

                Select::make('wbs_node_id')
                    ->label('WBS element')
                    ->options(fn (callable $get): array => $get('job_id') === null ? [] : WbsNode::query()
                        ->where('job_id', $get('job_id'))
                        ->orderBy('path')->get()
                        ->mapWithKeys(fn (WbsNode $node): array => [$node->getKey() => "{$node->code} — {$node->name}"])
                        ->all())
                    ->searchable()
                    ->placeholder('Whole job'),

                TextInput::make('quantity')
                    ->numeric()
                    ->required()
                    ->helperText('Everything that left the store, wastage included.'),

                TextInput::make('wastage_quantity')
                    ->label('Of which wasted')
                    ->numeric()
                    ->default(0)
                    ->helperText('Part of the quantity, not on top of it. It leaves as a separate waste movement so it can be totalled.'),

                TextInput::make('wastage_reason')
                    ->label('Wasted because')
                    ->maxLength(255)
                    ->columnSpanFull(),

                TextInput::make('description')
                    ->maxLength(255)
                    ->columnSpanFull(),
            ]);
    }

    /** What the store actually holds, said on the form rather than discovered at posting. */
    private static function onHand(callable $get, MaterialIssue $issue): string
    {
        $productId = $get('product_id');

        if ($productId === null) {
            return 'Pick a product to see what the store holds.';
        }

        $product = Product::query()->find($productId);

        if ($product === null) {
            return '';
        }

        $onHand = app(InventoryValuationService::class)->onHand($product, $issue->stock_location_id);

        return $onHand <= 0.0
            ? 'This store holds none of that product. Receiving a delivery into the store is what puts it there.'
            : 'On hand at '.($issue->store?->code ?? 'this store').': '.$onHand.'.';
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('description')
            ->columns([
                TextColumn::make('product.sku')
                    ->label('Product')
                    ->description(fn (MaterialIssueLine $record): ?string => $record->product?->name)
                    ->searchable(),

                TextColumn::make('job.code')
                    ->label('Job')
                    ->toggleable(),

                TextColumn::make('costCode.code')
                    ->label('Used on')
                    ->toggleable(),

                TextColumn::make('quantity')
                    ->alignEnd(),

                TextColumn::make('wastage_quantity')
                    ->label('Wasted')
                    ->alignEnd()
                    ->description(fn (MaterialIssueLine $record): ?string => $record->wastage_reason)
                    // Zero rather than a dash: wastage of nothing is a fact worth stating on a material docket.
                    ->summarize(Sum::make()->label('Wasted')),

                TextColumn::make('returned_quantity')
                    ->label('Back')
                    ->alignEnd()
                    ->placeholder('—')
                    ->toggleable(),

                TextColumn::make('unit_cost')
                    ->label('At')
                    ->money('PKR')
                    ->alignEnd()
                    ->placeholder('at posting'),

                TextColumn::make('amount')
                    ->label('Value')
                    ->money('PKR')
                    ->alignEnd()
                    ->placeholder('—')
                    ->summarize(Sum::make()->money('PKR')->label('Moved')),
            ])
            ->headerActions([
                CreateAction::make()
                    ->visible(fn (): bool => $this->issue()->isDraft()
                        && (auth()->user()?->can('update', $this->issue()) ?? false))
                    ->using(fn (array $data): Model => app(MaterialIssueService::class)->addLine(
                        $this->issue(),
                        Job::query()->findOrFail($data['job_id']),
                        CostCode::query()->findOrFail($data['cost_code_id']),
                        Product::query()->findOrFail($data['product_id']),
                        $data,
                    )),
            ])
            ->recordActions([
                EditAction::make()
                    ->visible(fn (): bool => $this->issue()->isDraft()
                        && (auth()->user()?->can('update', $this->issue()) ?? false)),

                Action::make('return')
                    ->label('Return')
                    ->icon('heroicon-o-arrow-uturn-left')
                    ->color('gray')
                    ->modalHeading('Material back into the store')
                    ->modalDescription('It goes back at the cost it left at — revaluing it would make a return a way of changing the value of stock without buying anything. The reclassified cost is unwound in the same proportion.')
                    ->schema([
                        TextInput::make('quantity')
                            ->numeric()
                            ->required()
                            ->helperText(fn (MaterialIssueLine $record): string => 'Still out on this line: '
                                .$record->outstandingQuantity().'.'),

                        TextInput::make('reason')
                            ->maxLength(255)
                            ->helperText('Over-ordered, wrong size, job finished — worth a word.'),
                    ])
                    ->visible(fn (MaterialIssueLine $record): bool => $this->issue()->isPosted()
                        && $record->outstandingQuantity() > 0.0
                        && (auth()->user()?->can('post', $this->issue()) ?? false))
                    ->action(function (MaterialIssueLine $record, array $data): void {
                        try {
                            app(MaterialIssueService::class)->recordReturn(
                                $record,
                                (float) $data['quantity'],
                                $data['reason'] ?? null,
                            );
                        } catch (InvalidArgumentException $e) {
                            Notification::make()->danger()->title($e->getMessage())->send();

                            return;
                        }

                        Notification::make()->success()->title('Back on the store.')->send();
                    }),

                DeleteAction::make()
                    ->visible(fn (): bool => $this->issue()->isDraft()
                        && (auth()->user()?->can('update', $this->issue()) ?? false)),
            ])
            ->emptyStateHeading('Nothing on this docket yet')
            ->emptyStateDescription('Add what left the store. The value is worked out at posting, from the FIFO lots the store actually holds.');
    }
}
