<?php

namespace App\Modules\Core\Filament\Pages;

use App\Filament\Support\HelpAction;
use App\Modules\Core\Models\Company;
use App\Support\KeyValueState;
use App\Support\SettingsSections;
use App\Support\TenantSettings;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use UnitEnum;

/**
 * Per-company (per-tenant) settings editor. Loads the effective values via the
 * TenantSettings accessor (tenant override or config default) and persists
 * overrides into the current tenant's `settings` table.
 *
 * **Sections a module owns are contributed, not written here.** Two of them needed Accounting models — the
 * base currency reads `Currency` and asks `JournalEntryLine` whether anything is posted, and the payroll
 * account codes validate against `Account` — which made Core depend on Accounting for a settings form. They
 * now arrive through `App\Support\SettingsSections`; see docs/module-packaging-plan.md §9. What is left here
 * is the shell (access, `$data`, save dispatch, the notification, the status-page cache bust) and the
 * sections whose settings have no module class behind them.
 */
class CompanySettings extends Page
{
    protected string $view = 'filament.pages.company-settings';

    protected static string|UnitEnum|null $navigationGroup = 'Settings';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-cog-6-tooth';

    protected static ?string $title = 'Company Settings';

    public ?array $data = [];

    /**
     * Shape of each editable iPayments field: a pattern the bank will accept and
     * a description used in the failure message.
     *
     * The file is rejected by SCB as a whole if a header field is malformed, and
     * that only surfaces at upload time — long after the save — so it is worth
     * catching here. Blank is always allowed: the config default applies.
     *
     * @var array<string, array{pattern: string, expects: string}>
     */
    private const IPAYMENTS_RULES = [
        'debit_account' => ['pattern' => '/^[A-Za-z0-9\- ]{5,34}$/', 'expects' => 'an account number (5-34 letters, digits, spaces or dashes)'],
        'debit_bank_id' => ['pattern' => '/^[A-Za-z]{4}[A-Za-z]{2}[A-Za-z0-9]{2}([A-Za-z0-9]{3})?$/', 'expects' => 'a SWIFT/BIC code of 8 or 11 characters, e.g. SCBLPKKXXXX'],
        'debit_country' => ['pattern' => '/^[A-Za-z]{2}$/', 'expects' => 'a 2-letter ISO country code, e.g. PK'],
        'debit_city' => ['pattern' => '/^[A-Za-z]{3,20}$/', 'expects' => 'a city code or name, e.g. KHI'],
        'currency' => ['pattern' => '/^[A-Za-z]{3}$/', 'expects' => 'a 3-letter ISO currency code, e.g. PKR'],
        'payment_type' => ['pattern' => '/^[A-Za-z0-9]{2,10}$/', 'expects' => 'a payment type code, e.g. IBFT'],
        'salary_payment_type' => ['pattern' => '/^[A-Za-z0-9]{2,10}$/', 'expects' => 'a payment type code for salaries, e.g. PAY'],
        'processing_mode' => ['pattern' => '/^[A-Za-z]{2,4}$/', 'expects' => 'a processing mode, e.g. ON'],
        'invoice_format' => ['pattern' => '/^[0-9]{1,2}$/', 'expects' => 'a one or two digit format number'],
        'purpose_of_payment' => ['pattern' => '/^[0-9]{1,6}$/', 'expects' => 'a numeric purpose-of-payment code, e.g. 104'],
    ];

    public static function canAccess(): bool
    {
        return auth()->user()?->isAdministrator() ?? false;
    }

    protected function getHeaderActions(): array
    {
        return [
            HelpAction::make('company-settings', 'Company Settings: Help'),
        ];
    }

    public function mount(): void
    {
        // Own state first, then whatever the contributed sections need — which is not always a settings key:
        // the base currency is a row in the currencies table. See App\Support\Contracts\SettingsSection.
        $this->form->fill([
            'petty_cash_float_amount' => setting('petty_cash.float_amount'),
            'accounting_require_second_approver' => (bool) setting('accounting.require_second_approver'),
            'ipayments' => static::editableIpayments(),
            'projects_status_page_enabled' => (bool) setting('projects.status_page.enabled', false),
            'projects_status_page_token' => setting('projects.status_page.token'),
            'leave_year_basis' => setting('leave.year_basis'),
            'leave_carry_forward' => (bool) setting('leave.carry_forward'),
            'leave_prorate_first_year' => (bool) setting('leave.prorate_first_year'),
            'leave_require_second_approver' => (bool) setting('leave.require_second_approver'),
            'leave_min_notice_enforced' => (bool) setting('leave.min_notice_enforced'),
            'leave_sandwich_rule' => setting('leave.sandwich_rule'),
            'payroll_prorate_on_attendance' => (bool) setting('payroll.prorate_on_attendance'),
            'payroll_proration_divisor' => setting('payroll.proration_divisor'),
            'payroll_pay_overtime' => (bool) setting('payroll.pay_overtime'),
            'attendance_overtime_multiplier' => setting('attendance.overtime_multiplier'),
            'attendance_retention_months' => setting('attendance.retention_months'),
            ...SettingsSections::fill(),
        ]);
    }

    /**
     * The flat iPayments fields, without the nested `own_bank` matching rules.
     *
     * A KeyValue field can only edit scalars: rendering `own_bank` gave a row
     * whose value was an array, and touching it would have replaced the SCB
     * matching config with a string — silently sending IBANs to SCB accounts.
     * It stays in config, where TenantSettings merges it back in.
     *
     * @return array<string, scalar>
     */
    protected static function editableIpayments(): array
    {
        return array_filter((array) setting('ipayments'), 'is_scalar');
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components($this->sections());
    }

    /**
     * This page's own sections and the contributed ones, in one reading order.
     *
     * Keyed by sort rather than written in order, because the two Accounting sections are inserted at
     * positions 10 and 60 by `App\Support\SettingsSections` and the order has to be a stated decision on both
     * sides — currency first because it is what every other figure on the page means, the status page last
     * because it is the least consequential. Gaps are left so a module can land between two of these.
     *
     * @return array<int, mixed>
     */
    protected function sections(): array
    {
        $sections = [
            20 => [$this->pettyCashSection()],
            30 => [$this->approvalsSection()],
            40 => [$this->leaveSection()],
            50 => [$this->attendanceAndPaySection()],
            70 => [$this->ipaymentsSection()],
            80 => [$this->statusPageSection()],
        ];

        foreach (SettingsSections::sorted() as $contributed) {
            // Merged rather than assigned: a collision would otherwise drop one silently, and a module
            // choosing a sort cannot see what is already there.
            $sections[$contributed['sort']] = array_merge(
                $sections[$contributed['sort']] ?? [],
                $contributed['section']->components(),
            );
        }

        ksort($sections);

        return array_merge(...array_values($sections));
    }

    protected function pettyCashSection(): Section
    {
        return Section::make('Petty Cash')
            ->schema([
                TextInput::make('petty_cash_float_amount')
                    ->label('Float Amount')
                    ->numeric()
                    ->required()
                    ->helperText('The imprest the petty cash box is restored to each month.'),
            ]);
    }

    protected function approvalsSection(): Section
    {
        return Section::make('Approvals')
            ->description('Who has to sign an entry off before it reaches the ledger.')
            ->schema([
                Toggle::make('accounting_require_second_approver')
                    ->label('Require a second person to approve journal entries')
                    ->helperText('On, whoever writes an entry cannot be the one who approves it. '
                        .'Turn it OFF only if one person runs the books alone: with nobody else to '
                        .'approve, entries wait forever while the money they describe has already '
                        .'moved. Self-approvals are recorded as such in the audit trail, and '
                        .'scheduled entries and loan instalments post themselves rather than '
                        .'queueing for an approver who does not exist. It starts at whatever '
                        .'this installation was set up with (ACCOUNTING_REQUIRE_SECOND_APPROVER '
                        .'in .env); saving here is this company answering for itself, and that '
                        .'answer stands whatever the installation default later becomes.'),
            ]);
    }

    /**
     * Leave policy.
     *
     * This page belongs to Core, which serves every company — so without the visible() guard a company that
     * never bought Leave would be offered leave policy to set. docs/hrms-plan.md §4.7 and its test.
     *
     * Everything here obeys one rule: a setting decides what happens next, never what already happened. Each
     * field's helper text says when its change takes effect, at the point of saving, because the alternative
     * is somebody switching a basis in June and asking why last month's leave moved.
     */
    protected function leaveSection(): Section
    {
        return Section::make('Leave')
            ->description('Policy this company sets for itself. Day counts, notice periods and which types exist are reference data — edit those under Leave Types.')
            ->visible(fn (): bool => modules()->enabled('leave'))
            ->schema([
                Select::make('leave_year_basis')
                    ->label('When the leave year starts')
                    ->options([
                        'calendar' => 'Calendar year (1 January – 31 December)',
                        'fiscal' => 'Fiscal year (1 July – 30 June)',
                        'anniversary' => 'Each employee\'s joining anniversary',
                    ])
                    ->selectablePlaceholder(false)
                    ->native(false)
                    ->helperText('Changing this affects leave years opened from now on. Entitlements already open keep the window they were created with, so balances that are part-way through a year do not move.'),

                Toggle::make('leave_carry_forward')
                    ->label('Let unused days carry into the next leave year')
                    ->helperText('Off, unused days lapse at the year end and nothing is paid for them. On, each type carries up to its own "days that may carry forward" cap — a cap of 0 carries nothing even then. Switching this on does not give back days that have already lapsed.'),

                Toggle::make('leave_prorate_first_year')
                    ->label('Pro-rate a mid-year joiner\'s first year')
                    ->helperText('On, somebody joining part-way through gets a share of the year\'s days: the joining month counts if they started on or before the 15th. Off, they get the full year from day one. Either way, entitlements already granted are not restated.'),

                Toggle::make('leave_require_second_approver')
                    ->label('Require somebody else to approve leave')
                    ->helperText('On, nobody may approve their own leave — a manager\'s own request routes to their manager. Turn it OFF only if there is nobody above to approve: the person at the top of the reporting tree otherwise cannot have leave approved at all. Self-approvals are recorded as such in the audit trail. It starts at whatever this installation was set up with (LEAVE_REQUIRE_SECOND_APPROVER in .env); saving here is this company answering for itself, and that answer stands whatever the installation default later becomes.'),

                Toggle::make('leave_min_notice_enforced')
                    ->label('Enforce the notice each leave type asks for')
                    ->helperText('Off, short notice is a warning and the request is still recorded — which is usually right, since casual and sick leave are asked for late by their nature. On, a request with less than the type\'s notice is refused outright.'),

                Select::make('leave_sandwich_rule')
                    ->label('Weekends and holidays inside a leave')
                    ->options([
                        'off' => 'Not counted — Friday plus Monday uses 2 days',
                        'enclosed' => 'Counted when enclosed — Friday plus Monday uses 4 days',
                    ])
                    ->selectablePlaceholder(false)
                    ->native(false)
                    ->helperText('Applies to leave approved from now on. Requests already approved keep the days they were given — changing this does not restate leave somebody has already taken. Only days *between* two leave days are ever counted; a single Friday always costs one day.'),
            ]);
    }

    /**
     * The two switches in this application that can reduce or increase a payslip.
     *
     * Both ship OFF, and the wording here is deliberately blunt about what turning them on does — somebody
     * reading this page is deciding whether people get paid less next month.
     *
     * Visible only when there is something to feed them: without `attendance` there is no work pattern to say
     * how long a month or a day is, so pro-rating would have nothing to divide by and overtime no rate to
     * derive.
     */
    protected function attendanceAndPaySection(): Section
    {
        return Section::make('Attendance and pay')
            ->description('Whether the attendance record changes what people are paid. Both of these are off until you turn them on.')
            ->visible(fn (): bool => modules()->enabled('attendance') && modules()->enabled('payroll'))
            ->schema([
                Toggle::make('payroll_prorate_on_attendance')
                    ->label('Reduce pay for unpaid absence')
                    ->helperText('OFF by default, and this is the setting to think hardest about. On, a month with unpaid absence pays less: the basic wage and any pay component marked "pro-rates" scale by the days paid. Fixed allowances, bonuses, overtime and every deduction are left alone. A month where attendance has not been filled in pays in FULL — an unmarked day is not a day anybody missed. Turning this on applies from the next payroll month; months already calculated keep the figures they were paid on.'),

                Select::make('payroll_proration_divisor')
                    ->label('Divide the month by')
                    ->options([
                        'working_days' => 'The working days in the month, from the work pattern',
                        'calendar_days' => 'The calendar days in the month',
                        'fixed_26' => 'A fixed 26 days',
                        'fixed_30' => 'A fixed 30 days',
                    ])
                    ->selectablePlaceholder(false)
                    ->native(false)
                    ->visible(fn ($get): bool => (bool) $get('payroll_prorate_on_attendance'))
                    ->helperText('Recorded on each payslip as it is calculated, so changing this later never restates a month that has already been paid.'),

                Toggle::make('payroll_pay_overtime')
                    ->label('Pay for recorded overtime')
                    ->helperText('OFF by default. Until this is on, overtime is recorded and not paid — which is honest rather than lazy, because minutes have no defined value until a rate exists. On, the hourly rate is worked out from the basic wage and the work pattern\'s expected hours, multiplied by the figure below. The rate used is recorded on the payslip, so a later change to a package or a pattern does not restate a month already paid. Independent of pro-rating: overtime adds pay where pro-rating removes it.'),

                TextInput::make('attendance_overtime_multiplier')
                    ->label('Overtime multiplier')
                    ->numeric()
                    ->step(0.25)
                    ->minValue(1)
                    ->visible(fn ($get): bool => (bool) $get('payroll_pay_overtime'))
                    ->helperText('2.0 as shipped, because the Factories Act mandates double the ordinary rate. Provincial establishments differ — confirm what applies to you. Daily and weekly caps WARN and never reduce the amount: capping quietly would hide a compliance problem and underpay somebody at the same time.'),

                TextInput::make('attendance_retention_months')
                    ->label('Keep daily attendance for (months)')
                    ->numeric()
                    ->minValue(1)
                    ->helperText('Daily rows are pruned after this. It is the only table here that grows with usage rather than headcount, so it is cleaned up automatically — three years covers a payroll dispute, and longer has never been asked for.'),
            ]);
    }

    protected function ipaymentsSection(): Section
    {
        return Section::make('iPayments (Salary Bank File)')
            ->schema([
                KeyValue::make('ipayments')
                    ->label('iPayments Defaults')
                    ->keyLabel('Field')
                    ->valueLabel('Value')
                    ->addable(false)
                    ->deletable(false)
                    ->editableKeys(false)
                    ->helperText('Header fields for the salary bank file. A malformed value is only rejected when the file is uploaded to the bank, so it is checked here. Leave one blank to use the shipped default.')
                    ->rule(static function (): callable {
                        return static function (string $attribute, $value, callable $fail): void {
                            foreach (KeyValueState::map($value) as $field => $entry) {
                                // Nested config (the own_bank matching rules)
                                // is not editable here and never submitted.
                                if (! is_scalar($entry) || trim((string) $entry) === '') {
                                    continue;
                                }

                                $spec = self::IPAYMENTS_RULES[$field] ?? null;

                                if ($spec === null) {
                                    continue;
                                }

                                if (! preg_match($spec['pattern'], trim((string) $entry))) {
                                    $fail(sprintf(
                                        '"%s" must be %s — got "%s".',
                                        $field,
                                        $spec['expects'],
                                        $entry,
                                    ));
                                }
                            }
                        };
                    }),
            ]);
    }

    protected function statusPageSection(): Section
    {
        return Section::make('Public Status Page')
            ->description('Publishes the up/down state and uptime of environments marked "Show on public status page". Never publishes URLs, credentials or error details.')
            ->schema([
                Toggle::make('projects_status_page_enabled')
                    ->label('Enable public status page')
                    ->helperText('Off by default. The page is reachable only with the token below.'),

                TextInput::make('projects_status_page_token')
                    ->label('Access token')
                    ->helperText('Part of the URL. Changing it revokes every link already shared.')
                    ->suffixAction(
                        Action::make('generateStatusToken')
                            ->icon('heroicon-m-arrow-path')
                            ->label('Generate')
                            ->action(fn (Set $set) => $set('projects_status_page_token', Str::random(40)))
                    ),

                Placeholder::make('status_page_url')
                    ->label('Status page URL')
                    ->content(function (Get $get): string {
                        $token = $get('projects_status_page_token');
                        $company = Company::current();

                        if (! $token || ! $company) {
                            return 'Generate a token to get the URL.';
                        }

                        return route('status.show', ['company' => $company->slug, 'token' => $token]);
                    }),
            ]);
    }

    public function save(): void
    {
        $state = $this->form->getState();

        // Contributed sections write their own, first: the base currency is a row rather than a setting — it
        // is what the ledger's amounts mean — and the model refuses to change it once anything is posted
        // rather than trusting this screen to have disabled the field.
        SettingsSections::save($state);

        $settings = app(TenantSettings::class);
        $settings->set('petty_cash.float_amount', (float) $state['petty_cash_float_amount']);
        $settings->set('accounting.require_second_approver', (bool) $state['accounting_require_second_approver']);
        // Scalars only: the nested own_bank matching rules are not editable here,
        // and TenantSettings merges them back from config.
        $settings->set('ipayments', array_filter((array) $state['ipayments'], 'is_scalar'));
        $settings->set('projects.status_page.enabled', (bool) ($state['projects_status_page_enabled'] ?? false));
        $settings->set('projects.status_page.token', $state['projects_status_page_token'] ?: null);

        // Only when the section was actually rendered. Saving these for a company
        // without the module would write leave policy it can never see or change,
        // and the array keys are absent from $state when visible() hid the section.
        if (array_key_exists('leave_year_basis', $state)) {
            $this->saveLeaveSettings($settings, $state);
        }

        // Same guard, same reason: the keys are absent from $state when visible() hid
        // the section, and writing pay policy for a company that cannot see it would be
        // a figure nobody chose.
        if (array_key_exists('payroll_prorate_on_attendance', $state)) {
            $this->savePayPolicySettings($settings, $state);
        }

        // A stale cached payload would otherwise keep serving after the page is
        // switched off or its token rotated.
        if (Company::current()) {
            Cache::forget('status-page:'.Company::current()->getKey());
        }

        Notification::make()->title('Settings saved.')->success()->send();
    }

    /**
     * The two settings that move money, with every change recorded and named.
     *
     * "Who turned pro-rating on, and when" is where an incident about a short payslip
     * begins — §4.7 says exactly that, and it is the reason this is logged separately
     * from an ordinary settings save rather than folded into one entry.
     *
     * @param  array<string, mixed>  $state
     */
    private function savePayPolicySettings(TenantSettings $settings, array $state): void
    {
        $changes = [];

        $booleans = [
            'payroll.prorate_on_attendance' => 'payroll_prorate_on_attendance',
            'payroll.pay_overtime' => 'payroll_pay_overtime',
        ];

        foreach ($booleans as $key => $field) {
            $was = (bool) setting($key);
            $now = (bool) ($state[$field] ?? false);

            if ($was !== $now) {
                $changes[$key] = ['from' => $was, 'to' => $now];
            }

            $settings->set($key, $now);
        }

        $settings->set('payroll.proration_divisor', $state['payroll_proration_divisor'] ?? 'working_days');
        $settings->set('attendance.overtime_multiplier', (float) ($state['attendance_overtime_multiplier'] ?? 2.0));
        $settings->set('attendance.retention_months', (int) ($state['attendance_retention_months'] ?? 36));

        if ($changes !== []) {
            activity('CompanySettings')
                ->causedBy(auth()->user())
                ->event('pay_policy_changed')
                ->withProperties($changes)
                // Spelled out rather than keyed, because this is the entry somebody
                // reads months later while working out why a payslip was short.
                ->log('Pay policy changed: '.implode(', ', array_map(
                    fn (string $key, array $change): string => $key.' '.($change['to'] ? 'ON' : 'OFF'),
                    array_keys($changes),
                    $changes,
                )));
        }
    }

    /**
     * Leave policy, with every change recorded.
     *
     * "Who turned this on, and when" is where an incident about a short payslip or a
     * disputed balance begins — the modules page already takes this position for
     * licence changes, and these settings move the same kind of number. Only what
     * actually changed is logged, because an entry per save on every field would bury
     * the one change somebody is looking for.
     *
     * @param  array<string, mixed>  $state
     */
    private function saveLeaveSettings(TenantSettings $settings, array $state): void
    {
        $keys = [
            'leave.year_basis' => ['leave_year_basis', 'string'],
            'leave.carry_forward' => ['leave_carry_forward', 'bool'],
            'leave.prorate_first_year' => ['leave_prorate_first_year', 'bool'],
            'leave.require_second_approver' => ['leave_require_second_approver', 'bool'],
            'leave.min_notice_enforced' => ['leave_min_notice_enforced', 'bool'],
            'leave.sandwich_rule' => ['leave_sandwich_rule', 'string'],
        ];

        $changed = [];

        foreach ($keys as $key => [$field, $type]) {
            $was = $type === 'bool' ? (bool) setting($key) : (string) setting($key);
            $now = $type === 'bool' ? (bool) ($state[$field] ?? false) : (string) ($state[$field] ?? '');

            if ($was !== $now) {
                $changed[$key] = ['from' => $was, 'to' => $now];
            }

            $settings->set($key, $now);
        }

        if ($changed !== []) {
            activity('CompanySettings')
                ->causedBy(auth()->user())
                ->event('leave_policy_changed')
                ->withProperties($changed)
                ->log('Leave policy changed: '.implode(', ', array_keys($changed)));
        }
    }
}
