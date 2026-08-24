<?php

namespace App\Modules\ConstructionCosting\Filament\Resources\ControlAccounts\Schemas;

use App\Modules\Accounting\Models\Account;
use App\Modules\ConstructionCosting\Models\ControlAccount;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class ControlAccountForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components([
                Select::make('account_id')
                    ->label('General ledger account')
                    ->options(fn (): array => Account::query()->where('is_active', true)->orderBy('code')->get()
                        ->mapWithKeys(fn (Account $a): array => [$a->getKey() => "{$a->code} — {$a->name}"])
                        ->all())
                    ->searchable()
                    ->required(),

                Select::make('kind')
                    ->label('Kind')
                    ->options(ControlAccount::KINDS)
                    ->required()
                    ->live()
                    ->helperText('What this account is for the reconciliation report. Every "Job cost" account makes up the GL cost total the difference is computed from.'),

                /*
                 * **The column that turns an account in scope into an account the service posts to.**
                 *
                 * Optional, and the placeholder says what leaving it blank means: report scope only. §4.1's refusal
                 * depends on this being explicit — a service that guessed would post half a company's burden to one
                 * account and half to another depending on insertion order, and no report would say so.
                 */
                Select::make('purpose')
                    ->label('Posting rule')
                    ->options(ControlAccount::PURPOSES)
                    ->placeholder('None — in scope of the report only')
                    ->helperText('Which of construction\'s summary postings credits this account. At most one account per rule, enforced by the database.')
                    ->unique(ignoreRecord: true)
                    ->columnSpanFull(),

                Select::make('cost_type')
                    ->label('Cost type')
                    ->options([
                        'labour' => 'Labour',
                        'material' => 'Material',
                        'plant' => 'Plant',
                        'subcontract' => 'Subcontract',
                        'other' => 'Other',
                    ])
                    ->placeholder('Any — one cost account for everything')
                    // Only meaningful on a cost account: §4.1 groups the summary journal by (GL account × cost type),
                    // so this is what turns one line a month into four a book-keeper can read.
                    ->visible(fn ($get): bool => $get('kind') === ControlAccount::KIND_COST)
                    ->helperText('Leave blank if this company keeps one job-cost account for every kind of cost.'),

                TextInput::make('label')
                    ->label('Name on the report')
                    ->maxLength(255)
                    ->placeholder('Defaults to the account code and name')
                    ->columnSpanFull(),

                Toggle::make('is_active')
                    ->label('In use')
                    ->default(true)
                    ->helperText('Switched off, it leaves the report and the posting rules without being deleted — which is what you want when a chart of accounts is being restructured.'),

                Textarea::make('notes')
                    ->rows(2)
                    ->columnSpanFull(),
            ]);
    }
}
