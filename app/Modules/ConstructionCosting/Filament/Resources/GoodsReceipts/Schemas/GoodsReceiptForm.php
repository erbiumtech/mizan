<?php

namespace App\Modules\ConstructionCosting\Filament\Resources\GoodsReceipts\Schemas;

use App\Modules\Construction\Models\Location;
use App\Modules\ConstructionCosting\Models\Commitment;
use App\Modules\ConstructionCosting\Models\GoodsReceipt;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

/**
 * A delivery's header, in the order the storeman has the paperwork in front of them.
 *
 * **The order is optional**, and that is not laxity: a delivery that arrives against no order is a real event, and
 * refusing to record it would leave the material on site and uncosted, with the only remedy being to invent an order
 * after the fact.
 *
 * **The delivery note reference is the field that matters later.** It is what a three-way match is argued from when
 * the invoice disagrees with what arrived.
 */
class GoodsReceiptForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components([
                Select::make('commitment_id')
                    ->label('Against order')
                    ->options(fn (): array => Commitment::query()
                        ->committing()
                        ->get()
                        ->mapWithKeys(fn (Commitment $c): array => [$c->getKey() => $c->displayName()])
                        ->all())
                    ->searchable()
                    ->placeholder('No order — delivered unordered')
                    ->live()
                    // Only issued orders: an order the supplier has not been sent cannot have been delivered against.
                    ->helperText('Issued orders only. Leave blank for a delivery nobody ordered — it still gets costed.')
                    ->disabled(fn (?GoodsReceipt $record): bool => $record !== null && ! $record->isDraft()),

                DatePicker::make('received_on')
                    ->label('Received on')
                    ->required()
                    ->default(now())
                    ->helperText('The day it arrived. If that month is closed the cost lands in the open one and keeps this date.'),

                TextInput::make('delivery_note_reference')
                    ->label('Delivery note')
                    ->maxLength(255)
                    ->helperText("The supplier's own number. It is what the invoice will be matched against."),

                Select::make('location_id')
                    ->label('Delivered to')
                    ->options(fn (): array => Location::query()->orderBy('path')->get()
                        ->mapWithKeys(fn (Location $l): array => [$l->getKey() => "{$l->code} — {$l->name}"])
                        ->all())
                    ->searchable()
                    ->placeholder('Site'),

                Textarea::make('notes')
                    ->rows(2)
                    ->columnSpanFull()
                    ->helperText('Short delivery, damage, anything the buyer or the surveyor will need to know.'),
            ]);
    }
}
