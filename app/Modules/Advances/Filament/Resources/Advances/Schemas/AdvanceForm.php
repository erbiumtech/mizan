<?php

namespace App\Modules\Advances\Filament\Resources\Advances\Schemas;

use App\Modules\Advances\Models\Advance;
use App\Modules\Advances\Models\AdvanceType;
use App\Support\EmployeeAccess;
use App\Support\EmployeeOptions;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Support\Carbon;

class AdvanceForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('employee_id')
                ->label('Employee')
                ->relationship('employee', 'employee_id', fn ($query) => app(EmployeeAccess::class)
                    ->scopeAccessibleEmployees($query->with('user'), auth()->user()))
                ->getOptionLabelFromRecordUsing(fn ($record) => $record->display_label)
                ->searchable()
                ->getSearchResultsUsing(fn (string $search): array => EmployeeOptions::search(
                    $search,
                    EmployeeOptions::accessibleScope(),
                ))
                ->preload()
                ->required()
                ->disabledOn('edit')
                ->helperText('Fixed once set: the recoveries already taken belong to this person.'),

            // The type and its limits — docs/saas-launch-plan.md §14.3. Interest and the monthly figure follow.
            Select::make('advance_type_id')
                ->label('Type')
                ->options(fn (): array => AdvanceType::query()->active()->orderBy('name')->pluck('name', 'id')->all())
                ->live()
                ->afterStateUpdated(fn (Get $get, Set $set) => self::recompute($get, $set))
                ->helperText(fn (Get $get): ?string => ($t = AdvanceType::find($get('advance_type_id'))) ? implode(' · ', array_filter([
                    $t->max_amount !== null ? 'up to '.number_format((float) $t->max_amount) : null,
                    $t->max_instalments !== null ? 'at most '.$t->max_instalments.' instalments' : null,
                    (float) $t->interest_rate_pct > 0 ? $t->interest_rate_pct.'% interest' : 'interest-free',
                ])) : null),
            TextInput::make('total_amount')
                ->label('Advance amount')
                ->numeric()
                ->minValue(0.01)
                ->required()
                ->live(onBlur: true)
                ->afterStateUpdated(fn (Get $get, Set $set) => self::recompute($get, $set)),
            TextInput::make('instalments')
                ->label('Instalments')
                ->numeric()
                ->minValue(1)
                ->maxValue(120)
                ->live(onBlur: true)
                ->afterStateUpdated(fn (Get $get, Set $set) => self::recompute($get, $set))
                ->helperText('How many months. Sets the monthly deduction from the amount and any interest.'),
            TextInput::make('interest_amount')
                ->label('Interest')
                ->numeric()
                ->minValue(0)
                ->default(0)
                ->helperText('Worked out from the type; added to what is recovered.'),

            TextInput::make('monthly_instalment')
                ->label('Monthly deduction')
                ->numeric()
                ->minValue(0.01)
                ->required()
                ->live(onBlur: true)
                ->helperText('Taken from each payslip until the advance clears. The last one is trimmed to '
                    .'whatever is left, so it can never over-recover.'),

            DatePicker::make('started_on')
                ->label('Given on')
                ->native(false)
                ->default(now())
                ->required()
                ->live(onBlur: true),

            DatePicker::make('recovery_starts_on')
                ->label('Start deducting from')
                ->native(false)
                ->live(onBlur: true)
                ->helperText('The first payroll month to deduct from. Leave blank to follow Company Settings → '
                    .'Advances (by default, the month it is given). Only the month matters — a date mid-month still '
                    .'means the whole of it.'),

            Select::make('skipped_months')
                ->label('Skip these months')
                ->multiple()
                ->native(false)
                ->options(fn (Get $get): array => self::scheduleMonths($get))
                ->helperText('Nothing is deducted in a month you skip and nothing is written off, so the '
                    .'advance simply runs a month longer.'),

            Select::make('status')
                ->options([
                    Advance::STATUS_REQUESTED => 'Requested — waiting for approval',
                    Advance::STATUS_ACTIVE => 'Active — deducting',
                    Advance::STATUS_PAUSED => 'Paused — not deducting for now',
                    Advance::STATUS_SETTLED => 'Settled',
                    Advance::STATUS_CANCELLED => 'Cancelled — stop deducting',
                    Advance::STATUS_REFUSED => 'Refused',
                ])
                ->disabled(fn (): bool => ! (auth()->user()?->can('AdvanceUpdate') ?? false))
                ->dehydrated(fn (): bool => auth()->user()?->can('AdvanceUpdate') ?? false)
                ->default(Advance::STATUS_ACTIVE)
                ->native(false)
                ->required()
                ->helperText('Cancelled stops payroll taking any more without writing off what is owed.'),

            TextInput::make('reference')
                ->label('Reference')
                ->maxLength(255)
                ->helperText('Cheque number, agreement reference — whatever ties this to the money handed over.'),

            Textarea::make('notes')->rows(2)->columnSpanFull(),

            // Shown rather than stored: both are derived from the recovery ledger,
            // so a field holding them would be a second version of the truth.
            Placeholder::make('recovered')
                ->label('Recovered so far')
                ->content(fn (?Advance $record): string => $record
                    ? number_format($record->recoveredAmount(), 2).' over '.$record->recoveries()->count().' deduction(s)'
                    : '—')
                ->visibleOn('edit'),

            Placeholder::make('remaining')
                ->label('Still outstanding')
                ->content(fn (?Advance $record): string => $record ? number_format($record->remainingAmount(), 2) : '—')
                ->visibleOn('edit'),
        ])->columns(2);
    }

    /**
     * The months this advance is scheduled to be recovered across, as `Y-m => March 2027`.
     *
     * Offered rather than a free date so a skip cannot be entered for a month payroll
     * will never ask about. The list runs one month past the last instalment, plus one
     * for each month already skipped — every skip pushes the end of the schedule out by
     * one, and without that the option after a skip would disappear as soon as it was
     * chosen.
     *
     * @return array<string, string>
     */
    protected static function scheduleMonths(Get $get): array
    {
        $start = Carbon::parse($get('recovery_starts_on') ?: ($get('started_on') ?: now()))->startOfMonth();

        $total = (float) $get('total_amount');
        $instalment = (float) $get('monthly_instalment');

        // Twelve while the amounts are still blank, which is what a fresh create form
        // looks like until somebody types into it.
        $months = $total > 0 && $instalment > 0 ? (int) ceil($total / $instalment) : 12;
        $months = min($months + count((array) $get('skipped_months')) + 1, 60);

        return collect(range(0, $months - 1))
            ->mapWithKeys(function (int $offset) use ($start): array {
                $month = $start->copy()->addMonths($offset);

                return [$month->format('Y-m') => $month->format('F Y')];
            })
            ->all();
    }

    /** Interest from the type, the monthly figure from the instalments — docs/saas-launch-plan.md §14.3. */
    protected static function recompute(Get $get, Set $set): void
    {
        $type = AdvanceType::find($get('advance_type_id'));
        $amount = (float) $get('total_amount');
        $interest = $type ? $type->interestOn($amount) : (float) $get('interest_amount');
        $set('interest_amount', $interest);

        $instalments = (int) $get('instalments');

        if ($instalments > 0 && $amount > 0) {
            $set('monthly_instalment', round(($amount + $interest) / $instalments, 2));
        }
    }
}
