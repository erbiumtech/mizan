<?php

namespace App\Modules\Employees\Filament\Resources\Employees\Schemas;

use App\Filament\Support\CustomFieldsSchema;
use App\Modules\Employees\Models\Employee;
use App\Models\User;
use App\Support\EmployeeAccess;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
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

                // The company address is also the login, so it lives on the
                // linked user; the personal one is a plain employee column.
                TextInput::make('user_email')
                    ->label('Company Email')
                    ->helperText('Used to sign in.')
                    ->email()
                    ->required()
                    ->dehydrated()
                    ->afterStateHydrated(fn (TextInput $component, ?Employee $record) => $component->state($record?->user?->email))
                    ->rule(fn (?Employee $record) => Rule::unique(User::class, 'email')->ignore($record?->user_id)),

                TextInput::make('personal_email')
                    ->label('Personal Email')
                    ->email()
                    ->maxLength(255),

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
                    ->disabled($adminOnly)
                    ->dehydrated(fn (): bool => ! $adminOnly()),

                Select::make('department')
                    ->options([
                        'IT' => 'IT',
                        'Office Staff' => 'Office Staff',
                    ])
                    ->disabled($adminOnly)
                    ->dehydrated(fn (): bool => ! $adminOnly()),

                // Reporting line. Only Admins/Managers/CEO may assign it. The
                // picker excludes the employee themselves and their whole
                // downline to prevent cycles; a matching validation rule
                // enforces the same on save.
                Select::make('manager_id')
                    ->label('Manager')
                    ->helperText('The employee this person reports to.')
                    ->options(function (?Employee $record) {
                        $exclude = $record
                            ? app(EmployeeAccess::class)->subtreeEmployeeIds($record->id)->all()
                            : [];

                        return Employee::query()
                            ->when($exclude, fn ($q) => $q->whereNotIn('id', $exclude))
                            ->with('user')
                            ->get()
                            ->mapWithKeys(fn (Employee $employee) => [$employee->id => $employee->display_label]);
                    })
                    ->searchable()
                    ->preload()
                    ->nullable()
                    ->rule(fn (?Employee $record) => function (string $attribute, $value, $fail) use ($record) {
                        if ($record && $value
                            && in_array((int) $value, app(EmployeeAccess::class)->subtreeEmployeeIds($record->id)->all(), true)) {
                            $fail('A manager cannot be the employee themselves or one of their own reports.');
                        }
                    })
                    ->visible(fn (): bool => auth()->user()?->hasAnyRole(['Administrator', 'Manager', 'CEO']) ?? false),

                DatePicker::make('date_of_birth')
                    ->label('Date of Birth')
                    ->native(false)
                    ->displayFormat('d-m-Y')
                    // A birth date in the future or beyond a working lifetime is
                    // a typo, not a person.
                    ->maxDate(now()->subYears(14))
                    ->minDate(now()->subYears(100)),

                DatePicker::make('date_of_joining')
                    ->label('Date of Joining'),

                TextInput::make('nic')
                    ->label('NIC')
                    ->required(),

                FileUpload::make('nic_front')
                    ->label('NIC (Front)')
                    ->image()
                    ->disk('public')
                    ->directory('nic')
                    ->visibility('public')
                    ->openable()
                    ->downloadable()
                    ->required()
                    ->imageEditor()
                    ->maxSize(4096),

                FileUpload::make('nic_back')
                    ->label('NIC (Back)')
                    ->image()
                    ->disk('public')
                    ->directory('nic')
                    ->visibility('public')
                    ->openable()
                    ->downloadable()
                    ->required()
                    ->imageEditor()
                    ->maxSize(4096),

                Select::make('bank_id')
                    ->label('Bank')
                    ->relationship('bank', 'bank_name')
                    ->searchable()
                    ->preload()
                    ->required()
                    ->helperText('Bank directory for salary bank files'),

                TextInput::make('bank_account_no')
                    ->label('Bank A/C No')
                    ->required(),

                TextInput::make('iban_no')
                    ->label('IBAN No')
                    ->required(),

                TextInput::make('phone')
                    ->tel()
                    ->required()
                    ->unique(table: Employee::class, column: 'phone', ignoreRecord: true)
                    ->validationMessages(['unique' => 'This phone number is already used by another employee.']),

                TextInput::make('secondary_phone')
                    ->label('Secondary Phone')
                    ->tel()
                    ->required(),

                TextInput::make('address_line_1'),

                TextInput::make('address_line_2'),

                Select::make('gender')
                    ->label('Gender')
                    ->options([
                        'Male' => 'Male',
                        'Female' => 'Female',
                        'Other' => 'Other',
                    ]),

                ...CustomFieldsSchema::form(Employee::class),
            ]);
    }
}
