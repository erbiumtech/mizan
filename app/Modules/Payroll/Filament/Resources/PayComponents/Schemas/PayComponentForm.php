<?php

namespace App\Modules\Payroll\Filament\Resources\PayComponents\Schemas;

use App\Modules\Accounting\Models\Account;
use App\Modules\Payroll\Models\PayComponent;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

class PayComponentForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('label')
                ->required()
                ->maxLength(255)
                ->helperText('As it appears on the payslip and the client statement.'),

            TextInput::make('code')
                ->required()
                ->maxLength(64)
                ->alphaDash()
                ->unique(ignoreRecord: true)
                // Reports and imports refer to it, so it is fixed once anything has
                // been paid under it.
                ->disabled(fn (?PayComponent $record): bool => (bool) $record?->payslipAmounts()->exists())
                ->helperText('A stable identifier, e.g. fuel_card. Cannot change once it has been paid.'),

            Select::make('kind')
                ->options([
                    PayComponent::KIND_EARNING => 'Earning — added to pay',
                    PayComponent::KIND_DEDUCTION => 'Deduction — taken off pay',
                ])
                ->default(PayComponent::KIND_EARNING)
                ->selectablePlaceholder(false)
                ->live()
                ->disabled(fn (?PayComponent $record): bool => (bool) $record?->is_column_backed)
                ->required(),

            Toggle::make('is_taxable')
                ->label('Taxable')
                ->default(true)
                ->visible(fn (Get $get): bool => $get('kind') === PayComponent::KIND_EARNING)
                ->helperText('Off for money that is not income — a reimbursement of the employee\'s own spending.'),

            Select::make('account_id')
                ->label('Posts to')
                ->options(fn (): array => Account::where('allow_manual_entry', true)
                    ->orderBy('code')
                    ->get()
                    ->mapWithKeys(fn (Account $a): array => [$a->id => $a->code.' '.$a->name])
                    ->all())
                ->searchable()
                ->helperText('Where it lands in the ledger. Required unless a payroll account key is given below — a component with nowhere to post makes a payslip impossible to post.'),

            TextInput::make('account_key')
                ->label('Payroll account key')
                ->maxLength(64)
                ->helperText('Optional: reuse an existing payroll mapping, e.g. bonus_overtime, instead of naming an account.'),

            TextInput::make('sort')
                ->numeric()
                ->default(100)
                ->helperText('Order on the payslip. The shipped components run 10–140.'),

            Toggle::make('is_active')
                ->label('Active')
                ->default(true)
                ->helperText('Switch off to stop paying it, without touching payslips that already did.'),

            TextInput::make('description')->maxLength(255)->columnSpanFull(),
        ]);
    }
}
