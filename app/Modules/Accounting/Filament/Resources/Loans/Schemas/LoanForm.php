<?php

namespace App\Modules\Accounting\Filament\Resources\Loans\Schemas;

use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Services\LoanService;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

class LoanForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('The loan')
                    ->schema([
                        TextInput::make('name')
                            ->required()
                            ->maxLength(255)
                            ->helperText('"Vehicle finance", "Office mortgage".'),

                        TextInput::make('lender')
                            ->maxLength(255)
                            ->nullable(),

                        TextInput::make('principal')
                            ->label('Amount borrowed')
                            ->numeric()
                            ->minValue(1)
                            ->required()
                            ->prefix('PKR')
                            ->live(onBlur: true),

                        TextInput::make('annual_rate')
                            ->label('Interest rate')
                            ->numeric()
                            ->minValue(0)
                            ->maxValue(200)
                            ->default(0)
                            ->required()
                            ->suffix('% a year')
                            ->live(onBlur: true)
                            ->helperText('Nominal annual rate, divided by twelve for each month. Zero for an interest-free arrangement.'),

                        TextInput::make('term_months')
                            ->label('Term')
                            ->numeric()
                            ->minValue(1)
                            ->maxValue(600)
                            ->required()
                            ->suffix('months')
                            ->live(onBlur: true),

                        DatePicker::make('starts_on')
                            ->label('First instalment due')
                            ->native(false)
                            ->default(now()->startOfMonth()->addMonth())
                            ->required()
                            ->live(onBlur: true)
                            ->helperText('A day too late for a short month uses that month\'s last day.'),

                        Toggle::make('is_active')
                            ->label('Active')
                            ->default(true),

                        Textarea::make('notes')->rows(2)->nullable()->columnSpanFull(),

                        // Shown before saving, because the monthly figure is the
                        // number somebody is deciding on — being told it only
                        // after committing to the loan is the wrong order.
                        Placeholder::make('preview')
                            ->label('What this works out at')
                            ->content(function (Get $get): string {
                                $principal = (float) $get('principal');
                                $months = (int) $get('term_months');
                                $rate = (float) $get('annual_rate');

                                if ($principal <= 0 || $months < 1) {
                                    return 'Fill in the amount and the term.';
                                }

                                try {
                                    $payment = app(LoanService::class)
                                        ->instalmentAmount($principal, $rate / 100 / 12, $months);
                                } catch (\Throwable $e) {
                                    return $e->getMessage();
                                }

                                $total = round($payment * $months, 2);

                                return sprintf(
                                    '%s a month for %d months. Total repaid about %s, of which roughly %s is interest.',
                                    number_format($payment, 2),
                                    $months,
                                    number_format($total, 2),
                                    number_format(max($total - $principal, 0), 2),
                                );
                            })
                            ->columnSpanFull(),
                    ])
                    ->columns(2),

                Section::make('Where it posts')
                    ->description('A repayment is a three-sided entry: it reduces what is owed, records the interest as a cost, and takes the money from somewhere.')
                    ->schema([
                        Select::make('liability_account_id')
                            ->label('Loan account')
                            ->options(fn (): array => static::accountsOfType(['liability']))
                            ->searchable()
                            ->required()
                            ->helperText('The liability carrying what is still owed.'),

                        Select::make('interest_account_id')
                            ->label('Interest account')
                            ->options(fn (): array => static::accountsOfType(['expense']))
                            ->searchable()
                            ->required()
                            ->helperText('Where the cost of borrowing is charged.'),

                        Select::make('payment_account_id')
                            ->label('Paid from')
                            ->options(fn (): array => static::accountsOfType(['asset']))
                            ->searchable()
                            ->required()
                            ->helperText('The cash or bank account the instalment leaves.'),
                    ])
                    ->columns(3),
            ]);
    }

    /**
     * @param  array<int, string>  $types
     * @return array<int, string>
     */
    private static function accountsOfType(array $types): array
    {
        return Account::query()
            ->whereIn('type', $types)
            ->postable()
            ->orderBy('code')
            ->get()
            ->mapWithKeys(fn (Account $a): array => [$a->id => "{$a->code} — {$a->name}"])
            ->all();
    }
}
