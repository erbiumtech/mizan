<?php

namespace App\Modules\Accounting\Filament\Resources\Beneficiaries\Schemas;

use App\Filament\Support\CustomFieldsSchema;
use App\Modules\Accounting\Models\Beneficiary;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * A beneficiary read in the list's view sheet, in the form's own sections — docs/side-sheets.md. The
 * bank details are the ones the form already shows whoever may view a beneficiary.
 */
class BeneficiaryInfolist
{
    public static function configure(Schema $schema): Schema
    {
        $customFields = CustomFieldsSchema::infolistEntries(Beneficiary::class);

        return $schema->components([
            Section::make(__('Who is being paid'))
                ->columns(2)
                ->schema([
                    TextEntry::make('name')->label(__('Name')),
                    TextEntry::make('id_number')
                        ->label(__('ID Number'))
                        ->formatStateUsing(fn (string $state, Beneficiary $record): string => trim(($record->id_type ? $record->id_type.' ' : '').$state))
                        ->copyable()
                        ->placeholder('—'),
                    TextEntry::make('email')->label(__('Email'))->copyable()->placeholder('—'),
                    TextEntry::make('phone')->label(__('Phone'))->placeholder('—'),
                    TextEntry::make('address_line_1')->label(__('Address Line 1'))->placeholder('—'),
                    TextEntry::make('address_line_2')->label(__('Address Line 2'))->placeholder('—'),
                ]),

            Section::make(__('Where the money goes'))
                ->columns(2)
                ->schema([
                    TextEntry::make('bank.bank_name')->label(__('Bank'))->placeholder('—'),
                    TextEntry::make('account_no')->label(__('Account No'))->copyable()->placeholder('—'),
                    TextEntry::make('iban')->label(__('IBAN'))->copyable()->placeholder('—'),
                ]),

            Section::make(__('How it is paid'))
                ->columns(2)
                ->schema([
                    TextEntry::make('transactionType.name')->label(__('Usual Transaction Type'))->placeholder('—'),
                    TextEntry::make('payment_type')->label(__('Default Payment Type'))->badge()->placeholder('—'),
                ]),

            Section::make(__('Tax'))
                ->columns(2)
                ->schema([
                    IconEntry::make('is_contractor')->label(__('Contractor'))->boolean(),
                    TextEntry::make('engagement')
                        ->label(__('What they do'))
                        ->placeholder('—')
                        ->visible(fn (Beneficiary $record): bool => (bool) $record->is_contractor),
                    TextEntry::make('withholdingSection.section')
                        ->label(__('Withholding section'))
                        ->formatStateUsing(fn ($state, Beneficiary $record): string => $record->withholdingSection?->describe() ?? (string) $state)
                        ->placeholder(__('Paid gross')),
                    IconEntry::make('is_filer')
                        ->label(__('On the Active Taxpayers List'))
                        ->boolean()
                        ->visible(fn (Beneficiary $record): bool => filled($record->withholding_section_id)),
                ]),

            Section::make(__('Flags'))
                ->columns(2)
                ->schema([
                    IconEntry::make('is_active')->label(__('Active'))->boolean(),
                    IconEntry::make('is_petty_cash_custodian')->label(__('Petty cash custodian'))->boolean(),
                ]),

            Section::make(__('Custom fields'))
                ->columns(2)
                ->schema($customFields)
                ->visible($customFields !== []),
        ]);
    }
}
