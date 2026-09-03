<?php

namespace App\Modules\ConstructionCosting\Filament\Resources\PlantItems\Schemas;

use App\Modules\Accounting\Models\FixedAsset;
use App\Modules\Construction\Models\CostCode;
use App\Modules\ConstructionCosting\Models\Commitment;
use App\Modules\ConstructionCosting\Models\PlantItem;
use App\Modules\Invoicing\Models\Contact;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * A machine, how it is held, and what it charges.
 *
 * **The ownership field drives the rest of the form**, because it drives the accounting: an owned machine names a fixed
 * asset and charges the job internal hire; a hired one names a supplier and a hire order, and charges the job nothing
 * because its invoice already does.
 */
class PlantItemForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('The machine')
                    ->columns(2)
                    ->schema([
                        TextInput::make('code')
                            ->label('Plant number')
                            ->required()
                            ->maxLength(32)
                            ->helperText('What the site calls it: EXC-04, CRN-01. Unique.'),

                        TextInput::make('name')
                            ->required()
                            ->maxLength(255),

                        // Your own words, kept as one list rather than retyped per item:
                        // the plant register and its utilisation are read by category,
                        // and three spellings of "excavator" are three fleets. Edited
                        // under Settings -> Dropdown Options.
                        Select::make('category')
                            ->options(fn (?PlantItem $record): array => options('construction_costing.plant_category', $record?->category))
                            ->native(false)
                            ->helperText('Excavator, tower crane, dumper — your own words, added under Settings, Dropdown Options.'),

                        TextInput::make('registration')
                            ->maxLength(64)
                            ->helperText('Registration or serial, for the ones that have one.'),

                        Select::make('ownership')
                            ->label('How it is held')
                            ->options(PlantItem::OWNERSHIPS)
                            ->default(PlantItem::OWNERSHIP_OWNED)
                            ->selectablePlaceholder(false)
                            ->live()
                            // The one field on this form with an accounting consequence, so it says what it is.
                            ->helperText('Owned plant charges jobs internal hire. Hired plant does not — its supplier invoice is the cost, and its logs are the check against that invoice.'),

                        Select::make('meter_unit')
                            ->label('Meter reads in')
                            ->options([
                                PlantItem::METER_HOURS => 'Hours',
                                PlantItem::METER_KILOMETRES => 'Kilometres',
                            ])
                            ->default(PlantItem::METER_HOURS)
                            ->selectablePlaceholder(false),
                    ]),

                Section::make('Owned plant')
                    ->description('The asset behind the machine, so the depreciation, fuel and repairs its charges recover can be accumulated against it.')
                    ->visible(fn (callable $get): bool => $get('ownership') === PlantItem::OWNERSHIP_OWNED)
                    ->schema([
                        Select::make('fixed_asset_id')
                            ->label('Fixed asset')
                            ->options(fn (): array => FixedAsset::query()->orderBy('name')->pluck('name', 'id')->all())
                            ->searchable()
                            ->helperText('Optional, and worth filling in: without it the recovery account has nothing to be measured against.'),
                    ]),

                Section::make('Hired plant')
                    ->description('Who it comes from, and the order it comes on. The order is what makes the invoice check possible.')
                    ->visible(fn (callable $get): bool => $get('ownership') !== PlantItem::OWNERSHIP_OWNED)
                    ->columns(2)
                    ->schema([
                        Select::make('supplier_contact_id')
                            ->label('Hired from')
                            ->options(fn (): array => Contact::query()->orderBy('name')->pluck('name', 'id')->all())
                            ->searchable()
                            // Absent without Invoicing, which owns Contacts (§18.1). The column stays null and the
                            // machine is still a machine.
                            ->visible(fn (): bool => modules()->enabled('invoicing')),

                        Select::make('commitment_id')
                            ->label('Hire order')
                            ->options(fn (): array => Commitment::query()
                                ->where('type', Commitment::TYPE_PLANT_HIRE)
                                ->orderByDesc('id')->get()
                                ->mapWithKeys(fn (Commitment $order): array => [$order->getKey() => $order->displayName()])
                                ->all())
                            ->searchable()
                            // Without it there is nothing to compare the logs with, and an invoice for this machine
                            // can be paid with nothing saying whether the days add up (§7.3).
                            ->helperText('Plant-hire orders only. Without one, nothing checks the supplier\'s invoice against the days this machine was actually on site.'),
                    ]),

                Section::make('Rates')
                    ->description('What a unit costs. A blank rate means those units are not charged at all — which is a real choice, and the log will say so rather than quietly charging nothing.')
                    ->columns(3)
                    ->schema([
                        TextInput::make('working_rate')
                            ->label('Working')
                            ->numeric()
                            ->helperText('Per hour or per kilometre, as the meter reads.'),

                        TextInput::make('idle_rate')
                            ->label('Idle')
                            ->numeric()
                            ->helperText('On site, available, not working. Blank means not charged.'),

                        TextInput::make('standby_rate')
                            ->label('Standby')
                            ->numeric()
                            ->helperText('Usually a reduced rate. Blank means not charged.'),

                        Select::make('default_cost_code_id')
                            ->label('Usual cost code')
                            ->options(fn (): array => CostCode::query()
                                ->where('is_leaf', true)->where('is_active', true)
                                ->orderBy('code')->get()
                                ->mapWithKeys(fn (CostCode $code): array => [$code->getKey() => "{$code->code} — {$code->name}"])
                                ->all())
                            ->searchable()
                            ->columnSpanFull(),
                    ]),

                Section::make('Notes')
                    ->schema([
                        Toggle::make('is_active')
                            ->label('In the fleet')
                            ->default(true)
                            ->helperText('Switching a machine off takes it out of the pickers. There is no delete: a machine with cost against it is a row somebody will ask about.'),

                        Textarea::make('notes')->rows(2),
                    ]),
            ]);
    }
}
