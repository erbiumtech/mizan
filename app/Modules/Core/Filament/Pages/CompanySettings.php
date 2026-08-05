<?php

namespace App\Modules\Core\Filament\Pages;

use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\Currency;
use App\Modules\Accounting\Models\JournalEntryLine;
use App\Modules\Core\Models\Company;
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

    /**
     * A KeyValue field is validated in its raw editing shape — a list of
     * ['key' => ..., 'value' => ...] rows — not as the associative map it casts
     * to afterwards. Both shapes are accepted here so the rules below hold
     * whichever one they are handed.
     *
     * @return array<string, mixed>
     */
    protected static function keyValueMap(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $map = [];

        foreach ($value as $key => $entry) {
            if (is_array($entry) && array_key_exists('key', $entry)) {
                if ($entry['key'] !== null && $entry['key'] !== '') {
                    $map[$entry['key']] = $entry['value'] ?? null;
                }

                continue;
            }

            $map[$key] = $entry;
        }

        return $map;
    }

    public function mount(): void
    {
        $this->form->fill([
            'base_currency' => Currency::baseCode(),
            'petty_cash_float_amount' => setting('petty_cash.float_amount'),
            'accounting_auto_post_payroll' => (bool) setting('accounting.auto_post_payroll'),
            'accounting_payroll_accounts' => setting('accounting.payroll_accounts'),
            'ipayments' => static::editableIpayments(),
            'projects_status_page_enabled' => (bool) setting('projects.status_page.enabled', false),
            'projects_status_page_token' => setting('projects.status_page.token'),
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
            ->components([
                // The company's own currency, not a per-document or per-report choice:
                // it is what every amount in this company's ledger means.
                Section::make('Currency')
                    ->description('What this company keeps its books in. Everything posted is recorded in it.')
                    ->schema([
                        Select::make('base_currency')
                            ->label('Base currency')
                            ->options(fn (): array => static::currencyOptions())
                            ->selectablePlaceholder(false)
                            ->native(false)
                            // Fixed once anything is posted: every stored amount means
                            // this currency, so changing it would reinterpret the ledger
                            // rather than restate it.
                            ->disabled(fn (): bool => static::ledgerHasEntries())
                            ->helperText(fn (): string => static::ledgerHasEntries()
                                ? 'Fixed: entries have been posted in this currency, and changing it would reinterpret them rather than restate them.'
                                : 'Can still be changed because nothing has been posted yet.'),
                    ]),

                Section::make('Petty Cash')
                    ->schema([
                        TextInput::make('petty_cash_float_amount')
                            ->label('Float Amount')
                            ->numeric()
                            ->required()
                            ->helperText('The imprest the petty cash box is restored to each month.'),
                    ]),

                Section::make('Payroll')
                    ->schema([
                        Toggle::make('accounting_auto_post_payroll')
                            ->label('Auto-post payroll journal entries')
                            ->helperText('When on, payroll entries are approved and posted on creation; otherwise they await Manager/CEO approval.'),

                        KeyValue::make('accounting_payroll_accounts')
                            ->label('Payroll Account Codes')
                            ->keyLabel('Line')
                            ->valueLabel('Account code')
                            ->addable(false)
                            ->deletable(false)
                            ->editableKeys(false)
                            ->helperText('Each line must name a code that exists in the chart of accounts. Leave one blank to fall back to the shipped default.')
                            // Saving a code that does not exist breaks payroll
                            // posting at the point of use, long after the save —
                            // and these keys are not addable, so the mistake
                            // cannot be undone from this page. Catch it here.
                            ->rule(static function (): callable {
                                return static function (string $attribute, $value, callable $fail): void {
                                    // Only scalar entries are account codes; a blank
                                    // one means "fall back to the default".
                                    $codes = collect(self::keyValueMap($value))
                                        ->filter(fn ($code) => is_scalar($code) && trim((string) $code) !== '')
                                        ->map(fn ($code) => trim((string) $code));

                                    if ($codes->isEmpty()) {
                                        return;
                                    }

                                    // A company whose chart has not been seeded yet
                                    // would fail on every shipped default, locking
                                    // the admin out of the whole settings page over
                                    // values they never typed.
                                    if (Account::query()->doesntExist()) {
                                        return;
                                    }

                                    $existing = Account::whereIn('code', $codes->values()->all())
                                        ->pluck('code')
                                        ->all();

                                    foreach ($codes as $line => $code) {
                                        if (! in_array($code, $existing, true)) {
                                            $fail("Account code {$code} for \"{$line}\" does not exist in the chart of accounts.");
                                        }
                                    }
                                };
                            }),
                    ]),

                Section::make('iPayments (Salary Bank File)')
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
                                    foreach (self::keyValueMap($value) as $field => $entry) {
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
                    ]),

                Section::make('Public Status Page')
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
                    ]),
            ]);
    }

    /** Has anything been posted in the current base currency? */
    /**
     * The currencies that may be chosen, and always the one in use.
     *
     * A company whose currency list has not been seeded, or whose base currency has
     * since been switched off, still has a base currency — the page shows it, so it has
     * to be a valid answer. Leaving it out makes the form reject the value it was itself
     * given.
     *
     * @return array<string, string>
     */
    public static function currencyOptions(): array
    {
        $currency = Currency::class;

        $options = $currency::active()
            ->orderBy('code')
            ->get()
            ->mapWithKeys(fn ($row): array => [$row->code => $row->code.' — '.$row->name])
            ->all();

        $base = $currency::baseCode();
        $name = $currency::where('code', $base)->value('name');

        return $options + [$base => $name ? "{$base} — {$name}" : $base];
    }

    public static function ledgerHasEntries(): bool
    {
        return JournalEntryLine::exists();
    }

    private function saveBaseCurrency(?string $code): void
    {
        if (! $code || $code === Currency::baseCode()) {
            return;
        }

        try {
            Currency::where('code', $code)->firstOrFail()->update(['is_base' => true]);
        } catch (\InvalidArgumentException $e) {
            Notification::make()->danger()->title($e->getMessage())->send();
        }
    }

    public function save(): void
    {
        $state = $this->form->getState();

        // The currency is a row, not a setting: it is what the ledger's amounts mean,
        // and the model refuses to change it once anything is posted rather than
        // trusting this screen to have disabled the field.
        $this->saveBaseCurrency($state['base_currency'] ?? null);

        $settings = app(TenantSettings::class);
        $settings->set('petty_cash.float_amount', (float) $state['petty_cash_float_amount']);
        $settings->set('accounting.auto_post_payroll', (bool) $state['accounting_auto_post_payroll']);
        $settings->set('accounting.payroll_accounts', $state['accounting_payroll_accounts']);
        // Scalars only: the nested own_bank matching rules are not editable here,
        // and TenantSettings merges them back from config.
        $settings->set('ipayments', array_filter((array) $state['ipayments'], 'is_scalar'));
        $settings->set('projects.status_page.enabled', (bool) ($state['projects_status_page_enabled'] ?? false));
        $settings->set('projects.status_page.token', $state['projects_status_page_token'] ?: null);

        // A stale cached payload would otherwise keep serving after the page is
        // switched off or its token rotated.
        if (Company::current()) {
            Cache::forget('status-page:'.Company::current()->getKey());
        }

        Notification::make()->title('Settings saved.')->success()->send();
    }
}
