<?php

namespace App\Modules\Employees\Filament\Resources\Employees\Schemas;

use App\Filament\Support\CustomFieldsSchema;
use App\Modules\Core\Models\User;
use App\Modules\Core\Models\WorkLocation;
use App\Modules\Employees\Models\Employee;
use App\Support\EmployeeAccess;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Fieldset;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\Rule;

class EmployeeForm
{
    public static function configure(Schema $schema): Schema
    {
        // Nova $adminOnly closure: non-admins may edit only contact/bank
        // details; employment fields stay read-only for them.
        $adminOnly = fn (): bool => ! (auth()->user()?->isAdministrator() ?? false);

        return $schema
            ->components([
                Section::make('Who they are')
                    ->description('The record, the login behind it, and the number the attendance device knows them by.')
                    ->columns(2)
                    ->schema([
                        TextInput::make('employee_id')
                            ->label(__('Employee ID'))
                            ->required()
                            ->disabled($adminOnly)
                            ->dehydrated(fn (): bool => ! $adminOnly()),

                        // The biometric device's user id — docs/saas-launch-plan.md §8.7. Shown only
                        // when a device can push, because without one it is a number nobody has.

                        TextInput::make('device_pin')
                            ->label(__('Device PIN'))
                            ->maxLength(32)
                            ->unique(ignoreRecord: true)
                            ->helperText('The user number enrolled on the attendance device. Punches with this PIN become this employee\'s days.')
                            ->visible(fn (): bool => modules()->enabled('devices'))
                            ->disabled($adminOnly)
                            ->dehydrated(fn (): bool => ! $adminOnly()),

                        // BelongsTo user (Employee Name) — readonly in Nova; picker
                        // restricted to users holding the Employee role.
                        //
                        // The row's own user is always in the list, whether or not they
                        // still qualify. Users are shared across companies, so a query
                        // that only offers current members would make an employee left
                        // behind by a membership change show a blank name and fail
                        // validation on save — on a field nobody can even edit.

                        Select::make('user_id')
                            ->label(__('Employee Name'))
                            ->relationship(
                                'user',
                                'name',
                                // The membership scope is lifted at the root so it can come
                                // back as one branch of an OR: the picker offers this
                                // company's people, an existing row keeps its own user.
                                fn (Builder $query, ?Employee $record): Builder => $query
                                    ->acrossCompanies()
                                    ->where(fn (Builder $eligible) => $eligible
                                        ->where(fn (Builder $member) => $member
                                            ->inCurrentCompany()
                                            ->exceptPlatformAdmins()
                                            ->whereHas('roles', fn (Builder $roles) => $roles->where('name', 'Employee')))
                                        ->when($record?->user_id, fn (Builder $own, $userId) => $own
                                            ->orWhere($own->getModel()->getQualifiedKeyName(), $userId))),
                            )
                            ->searchable()
                            ->preload()
                            // Not required: nobody can fill a disabled field, and Filament
                            // validates it anyway — which made Create impossible and every
                            // employee without a login uneditable.
                            ->disabled()
                            ->dehydrated(false),

                        // Transient user_name / user_email — resolve from the linked
                        // user and write back to those model attributes (the model's
                        // saving hook routes them to the user or a change request).

                        TextInput::make('user_name')
                            ->label(__('Name'))
                            ->required()
                            ->maxLength(255)
                            ->dehydrated()
                            // Staff with no login keep their name on the row (see Employee::fullName()).
                            ->afterStateHydrated(fn (TextInput $component, ?Employee $record) => $component->state($record?->user?->name ?? $record?->name)),

                        // The company address is also the login, so it lives on the
                        // linked user; the personal one is a plain employee column.

                        TextInput::make('user_email')
                            ->label(__('Company Email'))
                            ->helperText('Used to sign in.')
                            ->email()
                            ->required()
                            // Only where there is a login to sign in with: this form creates none,
                            // and an address typed for somebody without one would be dropped.
                            ->visible(fn (?Employee $record): bool => $record?->user_id !== null)
                            ->dehydrated()
                            ->afterStateHydrated(fn (TextInput $component, ?Employee $record) => $component->state($record?->user?->email))
                            ->rule(fn (?Employee $record) => Rule::unique(User::class, 'email')->ignore($record?->user_id)),
                    ]),

                Section::make('The job')
                    ->description('What they do and who they report to. Each of these is a job fact rather than a detail: changing one is a decision somebody with authority makes, which is why they are read-only for everybody else.')
                    ->columns(2)
                    ->schema([
                        Select::make('status')
                            ->label(__('Status'))
                            ->options(Employee::STATUSES)
                            ->default(Employee::STATUS_ACTIVE)
                            ->disabled($adminOnly)
                            ->dehydrated(fn (): bool => ! $adminOnly()),

                        // The three job facts below are lists this company writes for itself,
                        // under Settings -> Dropdown Options. They were literal arrays here,
                        // which made hiring a draughtsman a deploy. The record's own value is
                        // passed so an entry since switched off still shows on the employee who
                        // holds it instead of rendering blank and saving that blank back.

                        Select::make('designation')
                            ->options(fn (?Employee $record): array => options('employees.designation', $record?->designation))
                            ->disabled($adminOnly)
                            ->dehydrated(fn (): bool => ! $adminOnly()),

                        Select::make('department')
                            ->options(fn (?Employee $record): array => options('employees.department', $record?->department))
                            ->disabled($adminOnly)
                            ->dehydrated(fn (): bool => ! $adminOnly()),

                        // Where they work — plan §7.9. A job fact like the two above, so the
                        // same gate; the site's holidays and its geofence follow from it.

                        Select::make('location_id')
                            ->label(__('Location'))
                            ->options(fn (): array => WorkLocation::options())
                            ->placeholder('Not recorded')
                            ->nullable()
                            ->disabled($adminOnly)
                            ->dehydrated(fn (): bool => ! $adminOnly()),

                        // A job fact like the two above, so the same admin-only gate: an
                        // employee promoting themselves off probation would be a payroll
                        // and a gratuity problem, not just a data one. Changing it writes
                        // a dated history row, which is what makes "were they permanent
                        // in March" answerable — see App\Modules\Employees\Services\JobHistory.
                        //
                        // Options from the company's own list, not spelled again here: two
                        // screens each writing their own is how Company::TYPE_LABELS came to
                        // exist. This was Employee::EMPLOYMENT_TYPES, whose four values are now
                        // what the list is seeded with.

                        Select::make('employment_type')
                            ->label(__('Employment Type'))
                            ->options(fn (?Employee $record): array => options('employees.employment_type', $record?->employment_type))
                            ->placeholder('Not recorded')
                            ->disabled($adminOnly)
                            ->dehydrated(fn (): bool => ! $adminOnly()),

                        // Reporting line. Only Admins/Managers/CEO may assign it. The
                        // picker excludes the employee themselves and their whole
                        // downline to prevent cycles; a matching validation rule
                        // enforces the same on save.

                        Select::make('manager_id')
                            ->label(__('Manager'))
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
                            ->visible(function (): bool {
                                $user = auth()->user();

                                // A super admin holds no role in most companies; see
                                // User::isAdministrator() for why that has to be said here.
                                return (bool) $user?->isSuperAdmin()
                                    || (bool) $user?->hasAnyRole(['Administrator', 'Manager', 'CEO']);
                            }),
                    ]),

                Section::make('Service dates')
                    ->description('When the employment started, when it was confirmed, and when it ended. Every letter the company issues about this person is written against these.')
                    ->columns(2)
                    ->schema([
                        DatePicker::make('date_of_joining')
                            ->label(__('Date of Joining')),

                        // Which bundle of leave types applies — docs/saas-launch-plan.md §14.4. Options come from
                        // Leave's table by name, so this form names no Leave class.

                        Select::make('leave_group_id')
                            ->label(__('Leave group'))
                            // TenantDb, not DB: the table is per company, and the default connection is the
                            // landlord database in production even though they are the same under test.
                            ->options(fn (): array => \App\Support\TenantDb::hasTable('leave_groups') ? \App\Support\TenantDb::table('leave_groups')->orderBy('name')->pluck('name', 'id')->all() : [])
                            ->placeholder('Default group')
                            ->nullable()
                            ->visible(fn (): bool => modules()->enabled('leave'))
                            ->disabled($adminOnly)
                            ->dehydrated(fn (): bool => ! $adminOnly()),

                        DatePicker::make('probation_ends_on')
                            ->label(__('Probation ends'))
                            ->afterOrEqual('date_of_joining')
                            ->disabled($adminOnly)
                            ->dehydrated(fn (): bool => ! $adminOnly())
                            ->helperText('Listed under Probations until confirmed.'),

                        DatePicker::make('confirmed_on')
                            ->label(__('Confirmed on'))
                            ->disabled($adminOnly)
                            ->dehydrated(fn (): bool => ! $adminOnly()),

                        /*
                     * When the employment ended.
                     *
                     * The column has been on `employees` since §3 of the plan — payroll, the headcount
                     * report and the experience letter all read it — and there has never been a field to
                     * write it with: it could only be set by a final settlement, which is a licensed
                     * module, or by hand in the database. That is the gap this fills.
                     *
                     * **It does not deactivate anybody.** `is_active` above is what every query filters
                     * on and it stays the flag; this records *when* it happened, not *whether* — see the
                     * migration that added the column. Setting one without the other is a real state (a
                     * notice period served out), so neither drives the other.
                     *
                     * Admin-only, like the job facts above: a date here rewrites the tense of every
                     * letter the company issues about this person.
                     */

                        DatePicker::make('left_on')
                            ->label(__('Termination / Last Working Day'))
                            ->helperText('The last day of employment. Leave blank while they are still employed. '
                                .'Once set, the experience letter and the income certificate are written in the past '
                                .'tense and bounded by this date — the Status above is a separate switch.')
                            // A leaving date before the joining date is a typo, and it would print a negative
                            // length of service on a letter somebody verifies.
                            ->afterOrEqual('date_of_joining')
                            ->disabled($adminOnly)
                            ->dehydrated(fn (): bool => ! $adminOnly()),
                    ]),

                Section::make('Personal details')
                    ->description('The person rather than the post — what a certificate, a tax form or an ID card is made from.')
                    ->columns(2)
                    ->schema([
                        DatePicker::make('date_of_birth')
                            ->label(__('Date of Birth'))
                            ->native(false)
                            ->displayFormat('d-m-Y')
                            // A birth date in the future or beyond a working lifetime is
                            // a typo, not a person.
                            ->maxDate(now()->subYears(14))
                            ->minDate(now()->subYears(100)),

                        TextInput::make('nic')
                            ->label(__('NIC'))
                            ->required(),

                        // The record's depth — docs/saas-launch-plan.md §14.2. Contact details, like the phone
                        // and address below, are the person's own to change; the approval queue takes them.

                        TextInput::make('father_name')->label("Father's name")->maxLength(120),

                        TextInput::make('ntn')->label(__('NTN'))->maxLength(20),

                        TextInput::make('nationality')->maxLength(60)->default('Pakistani'),

                        Select::make('marital_status')->options(Employee::MARITAL_STATUSES)->nullable(),

                        Select::make('gender')
                            ->label(__('Gender'))
                            ->options(fn (?Employee $record): array => options('employees.gender', $record?->gender)),

                        FileUpload::make('photo_path')
                            ->label(__('Photo'))
                            ->image()
                            ->disk('public')
                            ->directory('employee-photos')
                            ->maxSize(2048)
                            ->imageResizeTargetWidth('600'),
                    ]),

                Section::make('How to reach them')
                    ->description('Theirs to keep current: an employee editing their own record sends these through the change queue rather than writing them straight in.')
                    ->columns(2)
                    ->schema([
                        TextInput::make('personal_email')
                            ->label(__('Personal Email'))
                            ->email()
                            ->maxLength(255),

                        // Six statuses, one flag — docs/saas-launch-plan.md §14.2. The model derives `is_active`.

                        TextInput::make('phone')
                            ->tel()
                            ->required()
                            ->unique(table: Employee::class, column: 'phone', ignoreRecord: true)
                            ->validationMessages(['unique' => 'This phone number is already used by another employee.']),

                        TextInput::make('secondary_phone')
                            ->label(__('Secondary Phone'))
                            ->tel()
                            ->required(),

                        TextInput::make('address_line_1'),

                        TextInput::make('address_line_2'),

                        Fieldset::make('Emergency contact')
                            ->columns(3)
                            ->schema([
                                TextInput::make('emergency_contact_name')->label(__('Name'))->maxLength(120),
                                TextInput::make('emergency_contact_relation')->label(__('Relation'))->maxLength(40)->placeholder('Spouse, brother, …'),
                                TextInput::make('emergency_contact_phone')->label(__('Phone'))->maxLength(40),
                            ])
                            ->columnSpanFull(),

                        Fieldset::make('Current address')
                            ->columns(2)
                            ->schema([
                                TextInput::make('current_address.line_1')->label(__('Line 1'))->maxLength(190),
                                TextInput::make('current_address.line_2')->label(__('Line 2'))->maxLength(190),
                                TextInput::make('current_address.city')->label(__('City'))->maxLength(80),
                                TextInput::make('current_address.province')->label(__('Province'))->maxLength(80),
                                TextInput::make('current_address.postal_code')->label(__('Postal code'))->maxLength(20),
                            ])
                            ->columnSpanFull(),

                        Fieldset::make('Permanent address')
                            ->columns(2)
                            ->schema([
                                TextInput::make('permanent_address.line_1')->label(__('Line 1'))->maxLength(190),
                                TextInput::make('permanent_address.line_2')->label(__('Line 2'))->maxLength(190),
                                TextInput::make('permanent_address.city')->label(__('City'))->maxLength(80),
                                TextInput::make('permanent_address.province')->label(__('Province'))->maxLength(80),
                                TextInput::make('permanent_address.postal_code')->label(__('Postal code'))->maxLength(20),
                            ])
                            ->columnSpanFull(),
                    ]),

                Section::make('Identity documents')
                    ->description('Both sides of the NIC. Required, because the certificates and the bank file are checked against them.')
                    ->columns(2)
                    ->schema([
                        FileUpload::make('nic_front')
                            ->label(__('NIC (Front)'))
                            ->image()
                            ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp'])
                            ->disk('public')
                            ->directory('nic')
                            ->visibility('public')
                            ->openable()
                            ->downloadable()
                            ->required()
                            ->imageEditor()
                            ->maxSize(4096),

                        FileUpload::make('nic_back')
                            ->label(__('NIC (Back)'))
                            ->image()
                            ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp'])
                            ->disk('public')
                            ->directory('nic')
                            ->visibility('public')
                            ->openable()
                            ->downloadable()
                            ->required()
                            ->imageEditor()
                            ->maxSize(4096),

                        /*
                     * The directory lists the banks we transfer *out* to, so our own bank is
                     * deliberately not in it — which used to make an employee who banks with us
                     * unsaveable, because this field was required and had nothing to pick. It is
                     * optional now, and the short code below carries the answer instead.
                     */
                    ]),

                Section::make('How they are paid')
                    ->description('Where salary is sent. Which identifier a transfer goes on depends on the bank — see the note under each field.')
                    ->columns(2)
                    ->schema([
                        Select::make('bank_id')
                            ->label(__('Bank'))
                            ->relationship('bank', 'bank_name')
                            ->searchable()
                            ->preload()
                            ->live()
                            ->helperText('The bank we transfer out to. Leave blank if they bank with us — '
                                .'our own bank is not in this directory — and fill in the short code instead.'),

                        TextInput::make('bank_short_code')
                            ->label(__('Bank short code'))
                            ->maxLength(20)
                            ->visible(fn (Get $get): bool => blank($get('bank_id')))
                            ->required(fn (Get $get): bool => blank($get('bank_id')))
                            ->helperText('Only when no bank is chosen above — e.g. SCB for Standard Chartered. '
                                .'It is what the bank file carries in the beneficiary bank column.'),

                        TextInput::make('bank_code')
                            ->label(__('Bank code'))
                            ->maxLength(20)
                            ->visible(fn (Get $get): bool => blank($get('bank_id')))
                            ->helperText('Optional. Only used when no bank is chosen.'),

                        /*
                     * One of these two has to be there, and *which* one depends on the bank: a
                     * transfer inside our own bank goes on the account number, everything else on
                     * the IBAN. Requiring both would block a perfectly payable employee whose
                     * other identifier nobody has, so the rule is "at least one", and the bank
                     * file preview refuses the row if it is the wrong one.
                     */

                        TextInput::make('bank_account_no')
                            ->label(__('Bank A/C No'))
                            ->live(onBlur: true)
                            ->required(fn (Get $get): bool => blank($get('iban_no')))
                            ->helperText('What a transfer inside our own bank is sent on.'),

                        TextInput::make('iban_no')
                            ->label(__('IBAN No'))
                            ->live(onBlur: true)
                            ->required(fn (Get $get): bool => blank($get('bank_account_no')))
                            ->helperText('What a transfer to another bank is sent on.'),
                    ]),

                ...CustomFieldsSchema::form(Employee::class),
            ]);
    }
}
