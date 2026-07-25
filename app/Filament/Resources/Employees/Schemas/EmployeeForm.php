<?php

namespace App\Filament\Resources\Employees\Schemas;

use App\Models\Employee;
use App\Models\User;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;
use Illuminate\Validation\Rule;

class EmployeeForm
{
    public static function configure(Schema $schema): Schema
    {
        // Nova $adminOnly closure: non-admins may edit only contact/bank
        // details; employment fields stay read-only for them.
        $adminOnly = fn (): bool => ! (auth()->user()?->hasRole('Administrator') ?? false);

        return $schema
            ->components([
                TextInput::make('employee_id')
                    ->label('Employee ID')
                    ->required()
                    ->disabled($adminOnly)
                    ->dehydrated(fn (): bool => ! $adminOnly()),

                // BelongsTo user (Employee Name) — readonly in Nova; picker
                // restricted to users holding the Employee role.
                Select::make('user_id')
                    ->label('Employee Name')
                    ->relationship('user', 'name', fn ($query) => $query->whereHas('roles', fn ($q) => $q->where('name', 'Employee')))
                    ->searchable()
                    ->preload()
                    ->required()
                    ->disabled()
                    ->dehydrated(false),

                // Transient user_name / user_email — resolve from the linked
                // user and write back to those model attributes (the model's
                // saving hook routes them to the user or a change request).
                TextInput::make('user_name')
                    ->label('Name')
                    ->required()
                    ->maxLength(255)
                    ->dehydrated()
                    ->afterStateHydrated(fn (TextInput $component, ?Employee $record) => $component->state($record?->user?->name)),

                TextInput::make('user_email')
                    ->label('Email')
                    ->email()
                    ->required()
                    ->dehydrated()
                    ->afterStateHydrated(fn (TextInput $component, ?Employee $record) => $component->state($record?->user?->email))
                    ->rule(fn (?Employee $record) => Rule::unique(User::class, 'email')->ignore($record?->user_id)),

                Select::make('is_active')
                    ->label('Status')
                    ->options([1 => 'Active', 0 => 'Inactive'])
                    ->disabled($adminOnly)
                    ->dehydrated(fn (): bool => ! $adminOnly()),

                Select::make('designation')
                    ->options([
                        'Senior Full Stack Developer' => 'Senior Full Stack Developer',
                        'Full Stack Developer' => 'Full Stack Developer',
                        'Frontend Developer' => 'Frontend Developer',
                        'Backend Developer' => 'Backend Developer',
                        'Secretary' => 'Secretary',
                        'Cook' => 'Cook',
                        'Office Boy' => 'Office Boy',
                    ])
                    ->required()
                    ->disabled($adminOnly)
                    ->dehydrated(fn (): bool => ! $adminOnly()),

                Select::make('department')
                    ->options([
                        'IT' => 'IT',
                        'Office Staff' => 'Office Staff',
                    ])
                    ->required()
                    ->disabled($adminOnly)
                    ->dehydrated(fn (): bool => ! $adminOnly()),

                DatePicker::make('date_of_joining')
                    ->label('Date of Joining'),

                TextInput::make('nic')
                    ->label('NIC'),

                Select::make('bank_id')
                    ->label('Bank')
                    ->relationship('bank', 'bank_name')
                    ->searchable()
                    ->preload()
                    ->nullable()
                    ->helperText('Bank directory for salary bank files'),

                TextInput::make('bank_account_no')
                    ->label('Bank A/C No'),

                TextInput::make('iban_no')
                    ->label('IBAN No'),

                TextInput::make('phone'),

                TextInput::make('address_line_1'),

                TextInput::make('address_line_2'),

                Select::make('gender')
                    ->label('Gender')
                    ->options([
                        'Male' => 'Male',
                        'Female' => 'Female',
                        'Other' => 'Other',
                    ]),

                ...\App\Filament\Support\CustomFieldsSchema::form(\App\Models\Employee::class),
            ]);
    }
}
