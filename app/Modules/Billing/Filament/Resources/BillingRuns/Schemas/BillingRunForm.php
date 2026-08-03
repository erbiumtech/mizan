<?php

namespace App\Modules\Billing\Filament\Resources\BillingRuns\Schemas;

use App\Modules\Invoicing\Models\Contact;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class BillingRunForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('contact_id')
                    ->label('Client')
                    ->relationship('contact', 'name', fn ($query) => $query
                        ->whereIn('kind', [Contact::KIND_CUSTOMER, Contact::KIND_BOTH])
                        ->where('is_active', true))
                    ->searchable()
                    ->preload()
                    ->required(),

                Select::make('month')
                    ->label('Payroll month')
                    ->options(array_combine(
                        $months = ['January', 'February', 'March', 'April', 'May', 'June',
                            'July', 'August', 'September', 'October', 'November', 'December'],
                        $months,
                    ))
                    ->default(now()->format('F'))
                    ->required(),

                Select::make('fiscal_year_id')
                    ->label('Fiscal Year')
                    ->relationship('fiscalYear', 'name')
                    ->default(fn () => \App\Modules\Core\Models\FiscalYear::where('is_active', true)->value('id'))
                    ->searchable()
                    ->preload()
                    ->required(),

                DatePicker::make('invoice_date')
                    ->native(false)
                    ->default(now())
                    ->required(),

                DatePicker::make('due_date')
                    ->native(false)
                    ->after('invoice_date'),

                TextInput::make('currency')
                    ->label('Client currency')
                    ->default('EUR')
                    ->maxLength(3)
                    ->required()
                    ->helperText('The invoice is raised in the company currency; this is what the client is quoted in.'),

                TextInput::make('exchange_rate')
                    ->label('Rate')
                    ->numeric()
                    ->minValue(0)
                    ->helperText('Company currency per 1 of the client currency, as agreed for the month.'),

                Textarea::make('notes')
                    ->rows(2)
                    ->columnSpanFull(),
            ]);
    }
}
