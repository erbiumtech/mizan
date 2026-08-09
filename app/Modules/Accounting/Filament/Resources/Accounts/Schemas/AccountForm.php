<?php

namespace App\Modules\Accounting\Filament\Resources\Accounts\Schemas;

use App\Modules\Accounting\Models\Account;
use App\Modules\Core\Models\Company;
use App\Support\TaxRegimes;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;

class AccountForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                // Nova: Text code — required, max:20, unique on create + update-with-id.
                TextInput::make('code')
                    ->required()
                    ->maxLength(20)
                    ->unique(table: Account::class, column: 'code', ignoreRecord: true),

                // Nova: Text name — required, max:255.
                TextInput::make('name')
                    ->required()
                    ->maxLength(255),

                // Nova: Select type — required.
                Select::make('type')
                    ->options([
                        'asset' => 'Asset',
                        'liability' => 'Liability',
                        'equity' => 'Equity',
                        'income' => 'Income',
                        'expense' => 'Expense',
                    ])
                    ->live()
                    ->required(),

                // Only on income accounts, and only where a tax estimate exists
                // to consume it — a business tenant has no use for the
                // individual schedules, so the field would be a question with no
                // purpose on every account it has.
                Select::make('tax_regime')
                    ->label('Taxed as')
                    ->options(TaxRegimes::ALL)
                    ->visible(fn ($get) => $get('type') === 'income'
                        && ((Filament::getTenant() ?? Company::current())?->isPersonal() ?? false))
                    ->helperText('Which Pakistani schedule income booked here falls under. Set it once and every entry against this account is classified on the Tax Estimate. Left blank, that income is listed as unclassified rather than guessed at.'),

                // Nova: BelongsTo parent → Account — nullable, searchable.
                //
                // Only accounts with no journal lines are offered: an account that
                // has been posted to stops accepting entries the moment it gains a
                // child (Account::canHaveChildren), and the resulting failure shows
                // up far away, in the next payroll or invoice posting.
                Select::make('parent_id')
                    ->label('Parent Account')
                    ->relationship(
                        'parent',
                        'name',
                        fn (Builder $query, ?Account $record) => $query
                            ->groupable()
                            ->when($record, fn (Builder $q) => $q->whereKeyNot($record->getKey()))
                    )
                    ->helperText('Only accounts without journal entries of their own can be parents.')
                    ->searchable()
                    ->preload()
                    ->nullable(),

                // Nova: Boolean is_active (Active).
                Toggle::make('is_active')
                    ->label('Active')
                    ->default(true),

                // Nova: Boolean allow_manual_entry — hideFromIndex (form only here).
                Toggle::make('allow_manual_entry')
                    ->label('Allow Manual Entry')
                    ->default(true),

                // Nova: Textarea description — nullable, hideFromIndex.
                Textarea::make('description')
                    ->nullable()
                    ->columnSpanFull(),

                // normal_balance and balance are Nova exceptOnForms (auto-derived) — omitted from the form.
            ]);
    }
}
