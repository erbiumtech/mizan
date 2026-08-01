<?php

namespace App\Modules\Advances\Filament\Resources\Advances\Schemas;

use App\Modules\Advances\Models\Advance;
use App\Support\EmployeeAccess;
use App\Support\EmployeeOptions;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

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

            TextInput::make('total_amount')
                ->label('Advance amount')
                ->numeric()
                ->minValue(0.01)
                ->required(),

            TextInput::make('monthly_instalment')
                ->label('Monthly deduction')
                ->numeric()
                ->minValue(0.01)
                ->required()
                ->helperText('Taken from each payslip until the advance clears. The last one is trimmed to '
                    .'whatever is left, so it can never over-recover.'),

            DatePicker::make('started_on')
                ->label('Given on')
                ->native(false)
                ->default(now())
                ->required(),

            Select::make('status')
                ->options([
                    Advance::STATUS_ACTIVE => 'Active — deducting',
                    Advance::STATUS_SETTLED => 'Settled',
                    Advance::STATUS_CANCELLED => 'Cancelled — stop deducting',
                ])
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
}
