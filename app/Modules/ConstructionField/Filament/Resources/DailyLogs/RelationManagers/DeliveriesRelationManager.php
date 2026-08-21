<?php

namespace App\Modules\ConstructionField\Filament\Resources\DailyLogs\RelationManagers;

use App\Modules\ConstructionField\Models\DailyLog;
use App\Modules\ConstructionField\Models\DailyLogDelivery;
use App\Modules\ConstructionField\Services\DailyLogService;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\TimePicker;
use Filament\Forms\Components\Toggle;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * What arrived on site — §16.1.
 *
 * **The docket, not the valuation.** There is no rate and no amount on this form, and that is the point: §5's goods
 * receipt is where a delivery becomes money, and a site record of what turned up is a different document from the
 * priced receipt accounts posts. Keeping them apart is what lets a contractor run a diary without a cost ledger.
 *
 * Two columns here are the ones somebody will come looking for. **Materials on site** is §16.1's flag, and the table
 * says plainly that it corroborates the stock figure rather than replacing it. **Priced receipt** is empty until
 * accounts have receipted the docket — and every empty one is material the job has and the cost ledger has not heard
 * about.
 */
class DeliveriesRelationManager extends RelationManager
{
    protected static string $relationship = 'deliveries';

    protected static ?string $title = 'Deliveries';

    private function log(): DailyLog
    {
        /** @var DailyLog $log */
        $log = $this->getOwnerRecord();

        return $log;
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components([
                TextInput::make('docket_number')
                    ->label('Docket')
                    ->maxLength(255)
                    ->helperText('Leave it blank if the load came with no paperwork — the table will say so rather than hide it.'),

                TimePicker::make('received_at')
                    ->label('Time in')
                    ->seconds(false),

                Textarea::make('description')
                    ->label('What arrived')
                    ->required()
                    ->rows(2)
                    ->columnSpanFull(),

                TextInput::make('quantity')->numeric(),
                TextInput::make('unit_of_measure')->label('Unit')->maxLength(16),

                Select::make('supplier_contact_id')
                    ->label('Supplier')
                    ->relationship('supplier', 'name')
                    ->searchable()
                    ->preload()
                    // Contacts belong to Invoicing, which this module does not require. Without it the field is absent
                    // and the name written on the docket carries the answer.
                    ->visible(fn (): bool => modules()->enabled('invoicing')),

                TextInput::make('supplier_label')
                    ->label('Supplier, in words')
                    ->maxLength(255)
                    ->helperText('Whatever is printed on the docket.'),

                TextInput::make('order_reference')
                    ->label('Order reference')
                    ->maxLength(255)
                    ->helperText('The order number as written on the docket. The priced order lives in cost control; this is what site can read.'),

                Select::make('contract_item_id')
                    ->label('Contract item')
                    ->options(fn (): array => $this->contractItems())
                    ->searchable()
                    ->visible(fn (): bool => modules()->enabled('construction_contracts')),

                Select::make('condition')
                    ->label('Condition')
                    ->options(DailyLogDelivery::CONDITIONS)
                    ->default(DailyLogDelivery::CONDITION_ACCEPTED)
                    ->required()
                    ->live(),

                TextInput::make('condition_notes')
                    ->label('Condition notes')
                    ->maxLength(255)
                    ->visible(fn (callable $get): bool => $get('condition') !== DailyLogDelivery::CONDITION_ACCEPTED)
                    ->helperText('Taken anyway because the pour was booked? Say so here — that is the fact that disappears otherwise.'),

                Toggle::make('is_materials_on_site')
                    ->label('Still standing on site, unconsumed')
                    ->columnSpanFull()
                    ->helperText('Evidence for the materials-on-site line. Where you keep a site store the claimed figure comes from stock, which goes down as material is used; this is the site record beside it.'),

                TextInput::make('notes')->maxLength(255)->columnSpanFull(),
            ]);
    }

    /**
     * The contract's priced lines, read out of the table rather than through `ContractItem`.
     *
     * `construction_contracts` is guarded here — this module requires only `construction` — so the picker asks the
     * query builder for two strings and never names a class from a module that may be absent. The same treatment §13's
     * `contract_id` gets.
     *
     * @return array<int, string>
     */
    private function contractItems(): array
    {
        if (! modules()->enabled('construction_contracts')) {
            return [];
        }

        $contracts = DB::table('construction_contracts')
            ->where('job_id', $this->log()->job_id)
            ->pluck('id');

        if ($contracts->isEmpty()) {
            return [];
        }

        return DB::table('construction_contract_items')
            ->whereIn('contract_id', $contracts)
            ->where('is_active', true)
            ->orderBy('sort')->orderBy('item_no')
            ->limit(500)
            ->get(['id', 'item_no', 'description'])
            ->mapWithKeys(fn ($row): array => [
                $row->id => $row->item_no.' — '.str($row->description)->limit(60),
            ])
            ->all();
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('description')
            ->columns([
                TextColumn::make('docket_number')
                    ->label('Docket')
                    // Said out loud rather than left blank: the docket is the first thing anybody asks for.
                    ->placeholder('no docket')
                    ->description(fn (DailyLogDelivery $record): ?string => $record->received_at),

                TextColumn::make('description')
                    ->label('What arrived')
                    ->wrap()
                    ->limit(60),

                TextColumn::make('quantity')
                    ->alignEnd()
                    ->placeholder('—')
                    ->description(fn (DailyLogDelivery $record): ?string => $record->unit_of_measure),

                TextColumn::make('supplier_label')
                    ->label('Supplier')
                    ->getStateUsing(fn (DailyLogDelivery $record): string => $record->supplierName()),

                TextColumn::make('condition')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => DailyLogDelivery::CONDITIONS[$state] ?? $state)
                    ->color(fn (string $state): string => match ($state) {
                        DailyLogDelivery::CONDITION_REJECTED => 'danger',
                        DailyLogDelivery::CONDITION_ACCEPTED_WITH_DAMAGE => 'warning',
                        default => 'success',
                    })
                    ->description(fn (DailyLogDelivery $record): ?string => $record->condition_notes),

                IconColumn::make('is_materials_on_site')
                    ->label('On site')
                    ->boolean(),

                TextColumn::make('goods_receipt_id')
                    ->label('Priced receipt')
                    ->getStateUsing(fn (DailyLogDelivery $record): ?string => $record->goods_receipt_id === null
                        ? null
                        : DB::table('construction_goods_receipts')->where('id', $record->goods_receipt_id)->value('number'))
                    // The empty state is the finding: a docket accounts have never seen is cost the job has incurred
                    // and the ledger has not recorded.
                    ->placeholder(fn (): string => modules()->enabled('construction_costing') ? 'not receipted' : '—')
                    ->color(fn (?string $state): string => $state === null ? 'warning' : 'gray'),
            ])
            ->filters([
                Filter::make('unreceipted')
                    ->label('Not yet receipted by accounts')
                    ->query(fn (Builder $query): Builder => $query->unreceipted())
                    ->visible(fn (): bool => modules()->enabled('construction_costing')),

                Filter::make('on_site')
                    ->label('Standing on site')
                    ->query(fn (Builder $query): Builder => $query->onSite()),
            ])
            ->headerActions([
                CreateAction::make()
                    ->visible(fn (): bool => auth()->user()?->can('update', $this->log()) ?? false)
                    ->using(fn (array $data): Model => app(DailyLogService::class)->addDelivery($this->log(), $data)),
            ])
            ->recordActions([
                /*
                 * Link the docket to the priced receipt accounts raised for it.
                 *
                 * This is the join that turns "material arrived" into "cost recorded", and it is done from here because
                 * the docket is what the two documents have in common — the picker offers the job's receipts with the
                 * one whose delivery note matches this docket already chosen.
                 *
                 * Absent without `construction_costing`, because there are no goods receipts to point at.
                 */
                Action::make('linkReceipt')
                    ->label('Link priced receipt')
                    ->icon('heroicon-o-link')
                    ->visible(fn (DailyLogDelivery $record): bool => modules()->enabled('construction_costing')
                        && ($record->goods_receipt_id === null)
                        && (auth()->user()?->can('update', $this->log()) ?? false))
                    ->schema(fn (DailyLogDelivery $record): array => [
                        Select::make('goods_receipt_id')
                            ->label('Goods receipt')
                            ->options($this->goodsReceipts())
                            ->default($this->suggestedReceipt($record))
                            ->searchable()
                            ->required()
                            ->helperText('Where one of these was raised against this docket, it is already selected.'),
                    ])
                    ->action(fn (DailyLogDelivery $record, array $data): bool => $record->update([
                        'goods_receipt_id' => $data['goods_receipt_id'],
                    ])),

                EditAction::make()->visible(fn (): bool => auth()->user()?->can('update', $this->log()) ?? false),
                DeleteAction::make()->visible(fn (): bool => auth()->user()?->can('update', $this->log()) ?? false),
            ])
            ->emptyStateHeading('Nothing delivered')
            ->emptyStateDescription('The docket recorded on the day is what makes a short delivery arguable three months later.');
    }

    /**
     * The job's goods receipts, read out of the table.
     *
     * `construction_goods_receipts` belongs to `construction_costing`, which this module does not require — so this is
     * the query builder again, and the header has no `job_id` of its own: the job is on the lines, which is why this
     * goes through a subquery rather than a `where`.
     *
     * @return array<int, string>
     */
    private function goodsReceipts(): array
    {
        return DB::table('construction_goods_receipts')
            ->whereIn('id', DB::table('construction_goods_receipt_lines')
                ->where('job_id', $this->log()->job_id)
                ->select('goods_receipt_id'))
            ->orderByDesc('received_on')
            ->limit(200)
            ->get(['id', 'number', 'received_on', 'delivery_note_reference'])
            ->mapWithKeys(fn ($row): array => [
                $row->id => $row->number.' — '.$row->received_on
                    .($row->delivery_note_reference ? " (docket {$row->delivery_note_reference})" : ''),
            ])
            ->all();
    }

    /** The receipt raised against this docket, where the two references agree. */
    private function suggestedReceipt(DailyLogDelivery $delivery): ?int
    {
        if (blank($delivery->docket_number)) {
            return null;
        }

        return DB::table('construction_goods_receipts')
            ->whereIn('id', DB::table('construction_goods_receipt_lines')
                ->where('job_id', $this->log()->job_id)
                ->select('goods_receipt_id'))
            ->where('delivery_note_reference', $delivery->docket_number)
            ->value('id');
    }
}
