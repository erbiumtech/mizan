<?php

namespace App\Filament\Pages;

use App\Models\Account;
use App\Models\Company;
use App\Support\TenantSettings;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Placeholder;
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
        'processing_mode' => ['pattern' => '/^[A-Za-z]{2,4}$/', 'expects' => 'a processing mode, e.g. ON'],
        'invoice_format' => ['pattern' => '/^[0-9]{1,2}$/', 'expects' => 'a one or two digit format number'],
        'purpose_of_payment' => ['pattern' => '/^[0-9]{1,6}$/', 'expects' => 'a numeric purpose-of-payment code, e.g. 104'],
    ];

    public static function canAccess(): bool
    {
        return auth()->user()?->hasRole('Administrator') ?? false;
    }

    public function mount(): void
    {
        $this->form->fill([
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
                                    if (! is_array($value)) {
                                        return;
                                    }

                                    // Only scalar entries are account codes; a blank
                                    // one means "fall back to the default".
                                    $codes = collect($value)
                                        ->filter(fn ($code) => is_scalar($code) && trim((string) $code) !== '')
                                        ->map(fn ($code) => trim((string) $code));

                                    if ($codes->isEmpty()) {
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
                            ->editableKeys(false),
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

    public function save(): void
    {
        $state = $this->form->getState();

        $settings = app(TenantSettings::class);
        $settings->set('petty_cash.float_amount', (float) $state['petty_cash_float_amount']);
        $settings->set('accounting.auto_post_payroll', (bool) $state['accounting_auto_post_payroll']);
        $settings->set('accounting.payroll_accounts', $state['accounting_payroll_accounts']);
        $settings->set('ipayments', $state['ipayments']);
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
