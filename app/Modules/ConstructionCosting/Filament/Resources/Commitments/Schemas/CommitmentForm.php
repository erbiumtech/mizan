<?php

namespace App\Modules\ConstructionCosting\Filament\Resources\Commitments\Schemas;

use App\Modules\ConstructionCosting\Models\Commitment;
use App\Modules\Invoicing\Models\Contact;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

/**
 * An order's header. **The job is deliberately not here** — it is on the lines.
 *
 * §5 spends a paragraph on why: one order of rebar split across three sites is completely normal, and forcing one
 * order per job means either the supplier gets three orders for one delivery, which he will not honour, or somebody
 * codes the whole load to one job. The first breaks the delivery note; the second is invisible.
 */
class CommitmentForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components([
                Select::make('type')
                    ->label('Kind')
                    ->options([
                        Commitment::TYPE_PURCHASE_ORDER => 'Purchase order',
                        Commitment::TYPE_SUBCONTRACT => 'Subcontract order',
                        Commitment::TYPE_PLANT_HIRE => 'Plant hire',
                        Commitment::TYPE_MANUAL => 'Manual commitment',
                    ])
                    ->default(Commitment::TYPE_PURCHASE_ORDER)
                    ->selectablePlaceholder(false)
                    ->required()
                    // The number series follows the kind, so changing it after numbering would leave a PO numbered
                    // as a subcontract.
                    ->disabled(fn (?Commitment $record): bool => $record !== null)
                    ->dehydrated(),

                TextInput::make('number')
                    ->label('Number')
                    ->maxLength(255)
                    ->helperText('Left blank, the next in this year\'s series. It is what comes back on the invoice.'),

                Select::make('contact_id')
                    ->label('Supplier')
                    ->options(fn (): array => Contact::query()->orderBy('name')->pluck('name', 'id')->all())
                    ->searchable()
                    // Absent without Invoicing, which owns Contacts (§18.1). A commitment to somebody with no
                    // contact record is still a commitment, and the column simply stays null.
                    ->visible(fn (): bool => modules()->enabled('invoicing'))
                    ->helperText('Who the order goes to.'),

                TextInput::make('supplier_reference')
                    ->label('Their reference')
                    ->maxLength(255)
                    ->helperText('The quote or order number the supplier will quote back.'),

                Textarea::make('description')
                    ->rows(2)
                    ->columnSpanFull()
                    ->helperText('What this order is for, in one line — it is the register\'s description.'),

                DatePicker::make('order_date')
                    ->label('Order date')
                    ->default(now()),

                DatePicker::make('required_by')
                    ->label('Required on site by'),

                TextInput::make('payment_terms_days')
                    ->label('Payment terms (days)')
                    ->numeric(),

                TextInput::make('retention_percent')
                    ->label('Retention %')
                    ->numeric()
                    ->visible(fn (callable $get): bool => $get('type') === Commitment::TYPE_SUBCONTRACT)
                    ->helperText('Held from each certificate against this subcontract.'),
            ]);
    }
}
