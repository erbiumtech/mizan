<?php

namespace App\Modules\Expenses\Filament\Resources\ExpenseClaims\Schemas;

use App\Modules\Accounting\Models\TransactionType;
use App\Support\EmployeeAccess;
use App\Support\EmployeeOptions;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class ExpenseClaimForm
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
                // Defaults to the claimant's own record, because most claims are
                // somebody claiming for themselves.
                ->default(fn (): ?int => \App\Modules\Employees\Models\Employee::where('user_id', auth()->id())->value('id')),

            DatePicker::make('claimed_on')
                ->label('Spent on')
                ->native(false)
                ->default(now())
                ->maxDate(now())
                ->required()
                ->helperText('When the money was spent, not when it is being claimed.'),

            TextInput::make('description')
                ->required()
                ->maxLength(255)
                ->helperText('What it was for, in the words you would use to explain it.'),

            TextInput::make('amount')
                ->numeric()
                ->minValue(0.01)
                ->required(),

            Select::make('transaction_type_id')
                ->label('Category')
                ->options(fn (): array => TransactionType::where('is_active', true)
                    ->orderBy('name')
                    ->pluck('name', 'id')
                    ->all())
                ->searchable()
                ->helperText('The same categories company payments use, so a claim and a company purchase for the same thing land together.'),

            FileUpload::make('receipt_path')
                ->label('Receipt')
                ->disk('public')
                ->directory('expense-claims')
                ->acceptedFileTypes(['image/jpeg', 'image/png', 'application/pdf'])
                ->maxSize(8192)
                ->helperText('Stored per company and served only to somebody signed in — see TenantStorage.'),

            Textarea::make('notes')
                ->rows(2)
                ->columnSpanFull(),
        ]);
    }
}
