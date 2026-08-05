<?php

namespace App\Modules\Invoicing\Filament\Resources\TaxRates\Schemas;

use App\Modules\Accounting\Models\Account;
use App\Modules\Invoicing\Models\TaxRate;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class TaxRateForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')
                ->required()
                ->maxLength(255)
                ->helperText('As it appears on the invoice, e.g. "GST 18%".'),

            TextInput::make('rate')
                ->label('Rate (%)')
                ->numeric()
                ->minValue(0)
                ->maxValue(100)
                ->required()
                ->suffix('%')
                ->helperText('18 means 18 per cent. Whether it is added on top or already in the price is decided per invoice.'),

            TextInput::make('code')
                ->label('Filing code')
                ->maxLength(255)
                ->helperText("The authority's own code for this tax, if it has one."),

            Select::make('account_id')
                ->label('Posts to')
                ->options(fn (): array => Account::whereIn('type', ['liability', 'asset'])
                    ->orderBy('code')
                    ->get()
                    ->mapWithKeys(fn (Account $a): array => [$a->id => $a->code.' '.$a->name])
                    ->all())
                ->searchable()
                ->helperText('Leave empty to use '.TaxRate::DEFAULT_ACCOUNT_CODE.'. Give each tax its own account if you file them separately.'),

            Toggle::make('is_active')
                ->label('Active')
                ->default(true)
                ->helperText('Switch off to stop offering it on new lines without touching invoices that used it.'),

            Toggle::make('is_default')
                ->label('Offer first on a new line')
                ->helperText('Only one rate can be the default; setting this stands the others down.'),
        ]);
    }
}
