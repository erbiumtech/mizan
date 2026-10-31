<?php

namespace App\Modules\Invoicing\Filament\Resources\Contacts\Schemas;

use App\Filament\Support\CustomFieldsSchema;
use App\Modules\Invoicing\Models\Contact;
use App\Support\CompanyCurrency;
use App\Support\EmployeeOptions;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * A contact read in the list's view sheet, in the form's own sections — docs/side-sheets.md. Their
 * bank details are the ones the form already shows whoever may view a contact.
 */
class ContactInfolist
{
    public static function configure(Schema $schema): Schema
    {
        $customFields = CustomFieldsSchema::infolistEntries(Contact::class);

        return $schema->components([
            Section::make(__('Who they are'))
                ->columns(2)
                ->schema([
                    TextEntry::make('name')->label(__('Name')),
                    TextEntry::make('kind')
                        ->label(__('Kind'))
                        ->badge()
                        ->color(fn (string $state): string => match ($state) {
                            'customer' => 'info',
                            'supplier' => 'warning',
                            'both' => 'success',
                            default => 'gray',
                        })
                        ->formatStateUsing(fn (string $state): string => __(ucfirst($state))),
                    TextEntry::make('category')->label(__('Category'))->placeholder('—'),
                    TextEntry::make('owner_employee_id')
                        ->label(__('Account manager'))
                        ->formatStateUsing(fn ($state): string => EmployeeOptions::labelFor($state) ?? (string) $state)
                        ->placeholder('—')
                        ->visible(fn (): bool => modules()->enabled('employees')),
                    IconEntry::make('is_active')->label(__('Active'))->boolean(),
                ]),

            Section::make(__('How to reach them'))
                ->columns(2)
                ->schema([
                    TextEntry::make('email')->label(__('Email'))->copyable()->placeholder('—'),
                    TextEntry::make('phone')->label(__('Phone'))->copyable()->placeholder('—'),
                    TextEntry::make('address_line_1')->label(__('Address Line 1'))->placeholder('—'),
                    TextEntry::make('address_line_2')->label(__('Address Line 2'))->placeholder('—'),
                ]),

            Section::make(__('Tax identity'))
                ->columns(2)
                ->schema([
                    TextEntry::make('ntn')->label(__('NTN'))->copyable()->placeholder('—'),
                    TextEntry::make('cnic')->label(__('CNIC'))->copyable()->placeholder('—'),
                ]),

            Section::make(__('Trading terms'))
                ->columns(2)
                ->schema([
                    TextEntry::make('default_discount_pct')
                        ->label(__('Standing discount %'))
                        ->formatStateUsing(fn ($state): string => rtrim(rtrim(number_format((float) $state, 2), '0'), '.').'%')
                        ->placeholder('—'),
                    TextEntry::make('payment_terms_days')
                        ->label(__('Payment terms'))
                        ->formatStateUsing(fn ($state): string => __(Contact::TERMS[$state] ?? (string) $state))
                        ->placeholder(__('None agreed')),
                    TextEntry::make('defaultTaxRate.name')
                        ->label(__('Default tax rate'))
                        ->formatStateUsing(fn ($state, Contact $record): string => $record->defaultTaxRate?->label() ?? (string) $state)
                        ->placeholder(__('The company default')),
                    TextEntry::make('credit_limit')
                        ->label(__('Credit limit'))
                        ->money(CompanyCurrency::code())
                        ->placeholder('—'),
                ]),

            Section::make(__('Their bank'))
                ->columns(2)
                ->schema([
                    TextEntry::make('bank.bank_name')->label(__('Bank'))->placeholder('—'),
                    TextEntry::make('iban')->label(__('IBAN'))->copyable()->placeholder('—'),
                    TextEntry::make('account_no')->label(__('Account number'))->copyable()->placeholder('—'),
                ]),

            Section::make(__('Custom fields'))
                ->columns(2)
                ->schema($customFields)
                ->visible($customFields !== []),
        ]);
    }
}
